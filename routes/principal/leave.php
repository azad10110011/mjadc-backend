<?php

// GET /api/principal/leave/summary
$router->get('/api/principal/leave/summary', function () {
    $user = Auth::requireRole('principal');

    $currentYear = date('Y');
    $allocations = Database::fetchAll(
        "SELECT * FROM leave_allocations WHERE role_type = 'principal'"
    );

    $summary = [];
    foreach ($allocations as $alloc) {
        $taken = Database::fetch(
            "SELECT days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$user['id'], $currentYear, $alloc['leave_type']]
        );

        $summary[] = [
            'type' => $alloc['leave_type'],
            'allocated' => (int)$alloc['total_days'],
            'taken' => $taken ? (int)$taken['days_taken'] : 0,
            'remaining' => max(0, (int)$alloc['total_days'] - ($taken ? (int)$taken['days_taken'] : 0)),
        ];
    }

    Response::success($summary);
});

// GET /api/principal/leave/pending
$router->get('/api/principal/leave/pending', function () {
    Auth::requireRole('principal');

    $applications = Database::fetchAll(
        "SELECT la.*, u.name as applicant_name
         FROM leave_applications la
         JOIN users u ON la.applicant_id = u.id
         WHERE la.status = 'pending' AND la.applicant_role IN ('teacher', 'staff')
         ORDER BY la.created_at DESC"
    );

    Response::success($applications);
});

// POST /api/principal/leave/action
$router->post('/api/principal/leave/action', function () {
    $user = Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('application_id', 'Application ID')
        ->required('action', 'Action')
        ->inArray('action', ['approved', 'rejected']);

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $application = Database::fetch(
        "SELECT * FROM leave_applications WHERE id = ?",
        [$data['application_id']]
    );

    if (!$application) {
        Response::notFound('Application not found');
    }

    Database::update(
        'leave_applications',
        [
            'status' => $data['action'],
            'reviewed_by' => $user['id'],
            'reviewed_at' => date('Y-m-d H:i:s'),
        ],
        'id = ?',
        ['id' => $data['application_id']]
    );

    if ($data['action'] === 'approved') {
        $from = new DateTime($application['from_date']);
        $to = new DateTime($application['to_date']);
        $days = $from->diff($to)->days + 1;

        $existing = Database::fetch(
            "SELECT id, days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$application['applicant_id'], date('Y'), $application['leave_type']]
        );

        if ($existing) {
            Database::update(
                'leave_taken',
                ['days_taken' => $existing['days_taken'] + $days],
                'id = ?',
                ['id' => $existing['id']]
            );
        } else {
            Database::insert('leave_taken', [
                'user_id' => $application['applicant_id'],
                'year' => date('Y'),
                'leave_type' => $application['leave_type'],
                'days_taken' => $days,
            ]);
        }
    }

    Response::success(null, 'Leave ' . $data['action']);
});

// POST /api/principal/leave/apply
$router->post('/api/principal/leave/apply', function () {
    $user = Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('leave_type', 'Leave Type')
        ->required('from_date', 'From Date')
        ->required('to_date', 'To Date')
        ->required('reason', 'Reason');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    if ($data['leave_type'] === 'maternity' && $user['gender'] !== 'female') {
        Response::forbidden('Maternity leave is only available for female principals');
    }

    $applicationId = Database::insert('leave_applications', [
        'applicant_id' => $user['id'],
        'applicant_role' => 'principal',
        'leave_type' => $data['leave_type'],
        'from_date' => $data['from_date'],
        'to_date' => $data['to_date'],
        'reason' => $data['reason'],
        'status' => 'pending',
    ]);

    Response::created(['id' => $applicationId], 'Leave application submitted');
});
