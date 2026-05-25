<?php

// GET /api/staff/leave/summary
$router->get('/api/staff/leave/summary', function () {
    $user = Auth::requireRole('staff');

    $currentYear = date('Y');
    $allocations = Database::fetchAll(
        "SELECT * FROM leave_allocations WHERE role_type = 'staff'"
    );

    $summary = [];
    foreach ($allocations as $alloc) {
        $taken = Database::fetch(
            "SELECT days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$user['id'], $currentYear, $alloc['leave_type']]
        );

        $daysTaken = $taken ? (int)$taken['days_taken'] : 0;

        $summary[] = [
            'type' => $alloc['leave_type'],
            'allocated' => (int)$alloc['total_days'],
            'taken' => $daysTaken,
            'remaining' => max(0, (int)$alloc['total_days'] - $daysTaken),
        ];
    }

    Response::success($summary);
});

// POST /api/staff/leave/apply
$router->post('/api/staff/leave/apply', function () {
    $user = Auth::requireRole('staff');

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
        Response::forbidden('Maternity leave is only available for female staff');
    }

    $applicationId = Database::insert('leave_applications', [
        'applicant_id' => $user['id'],
        'applicant_role' => 'staff',
        'leave_type' => $data['leave_type'],
        'from_date' => $data['from_date'],
        'to_date' => $data['to_date'],
        'reason' => $data['reason'],
        'status' => 'pending',
    ]);

    Response::created(['id' => $applicationId], 'Leave application submitted');
});

// GET /api/staff/leave/applications
$router->get('/api/staff/leave/applications', function () {
    $user = Auth::requireRole('staff');

    $applications = Database::fetchAll(
        "SELECT id, leave_type, from_date, to_date, reason, status, created_at 
         FROM leave_applications 
         WHERE applicant_id = ?
         ORDER BY created_at DESC",
        [$user['id']]
    );

    Response::success($applications);
});

// PUT /api/staff/leave/{id}
$router->put('/api/staff/leave/{id}', function (array $params) {
    $user = Auth::requireRole('staff');

    $application = Database::fetch(
        "SELECT * FROM leave_applications WHERE id = ? AND applicant_id = ? AND status = 'pending'",
        [$params['id'], $user['id']]
    );

    if (!$application) {
        Response::forbidden('You can only edit your own pending applications');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['leave_type', 'from_date', 'to_date', 'reason'];
    $updateData = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }

    if (empty($updateData)) {
        Response::validationError(['No valid fields to update']);
    }

    Database::update('leave_applications', $updateData, 'id = ?', ['id' => $params['id']]);

    Response::success(null, 'Leave application updated');
});
