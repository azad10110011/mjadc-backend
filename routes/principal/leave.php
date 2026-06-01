<?php

// GET /api/principal/leave/summary
$router->get('/api/principal/leave/summary', function () {
    $user = Auth::requireRole('principal');

    $currentYear = date('Y');
    $allocations = Database::fetchAll(
        "SELECT * FROM leave_allocations WHERE role_type = 'principal' AND user_id IS NULL
         UNION
         SELECT * FROM leave_allocations WHERE role_type = 'principal' AND user_id = ?
         ORDER BY user_id DESC, period = 'lifetime' DESC",
        [$user['id']]
    );

    if (empty($allocations)) {
        $allocations = Database::fetchAll(
            "SELECT * FROM leave_allocations WHERE role_type = 'principal' AND user_id IS NULL"
        );
    }

    $summary = [];
    foreach ($allocations as $alloc) {
        $year = $alloc['period'] === 'lifetime' ? 0 : $currentYear;

        $taken = Database::fetch(
            "SELECT days_taken FROM leave_taken
             WHERE user_id = ? AND year = ? AND leave_type = ? AND period = ?",
            [$user['id'], $year, $alloc['leave_type'], $alloc['period']]
        );

        $daysTaken = $taken ? (int)$taken['days_taken'] : 0;

        $summary[] = [
            'type' => $alloc['leave_type'],
            'allocated' => (int)$alloc['total_days'],
            'taken' => $daysTaken,
            'remaining' => max(0, (int)$alloc['total_days'] - $daysTaken),
            'period' => $alloc['period'],
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

    // Check maternity limit: max 2 approved applications lifetime
    if ($data['action'] === 'approved' && $application['leave_type'] === 'maternity') {
        $maternityCount = Database::fetch(
            "SELECT COUNT(*) as cnt FROM leave_applications
             WHERE applicant_id = ? AND leave_type = 'maternity' AND status = 'approved' AND id != ?",
            [$application['applicant_id'], $application['id']]
        );
        if ($maternityCount && (int)$maternityCount['cnt'] >= 2) {
            Response::error('This person has already used maternity leave twice. No more maternity leave allowed.');
        }
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
        approveLeaveByPrincipal($application);
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

    // Check maternity 2-time limit for principal's own application
    if ($data['leave_type'] === 'maternity') {
        $maternityCount = Database::fetch(
            "SELECT COUNT(*) as cnt FROM leave_applications
             WHERE applicant_id = ? AND leave_type = 'maternity' AND status = 'approved'",
            [$user['id']]
        );
        if ($maternityCount && (int)$maternityCount['cnt'] >= 2) {
            Response::error('You have already used maternity leave twice. No more maternity leave allowed.');
        }
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

// GET /api/principal/leave/applications
$router->get('/api/principal/leave/applications', function () {
    $user = Auth::requireRole('principal');

    $applications = Database::fetchAll(
        "SELECT id, leave_type, from_date, to_date, reason, status, created_at
         FROM leave_applications
         WHERE applicant_id = ?
         ORDER BY created_at DESC",
        [$user['id']]
    );

    Response::success($applications);
});

// PUT /api/principal/leave/{id}
$router->put('/api/principal/leave/{id}', function (array $params) {
    $user = Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);

    $app = Database::fetch(
        "SELECT * FROM leave_applications WHERE id = ? AND applicant_id = ?",
        [$params['id'], $user['id']]
    );

    if (!$app) {
        Response::notFound('Application not found');
    }

    Database::update('leave_applications', [
        'leave_type' => $data['leave_type'] ?? $app['leave_type'],
        'from_date' => $data['from_date'] ?? $app['from_date'],
        'to_date' => $data['to_date'] ?? $app['to_date'],
        'reason' => $data['reason'] ?? $app['reason'],
    ], 'id = ?', ['id' => $params['id']]);

    Response::success(null, 'Leave application updated');
});

// Shared helper for principal: approve leave and track taken days
function approveLeaveByPrincipal(array $application): void {

    $from = new DateTime($application['from_date']);
    $to = new DateTime($application['to_date']);
    $days = $from->diff($to)->days + 1;

    // Determine allocation period
    $alloc = Database::fetch(
        "SELECT total_days, period FROM leave_allocations
         WHERE leave_type = ?
           AND ((role_type = ? AND user_id IS NULL)
            OR (role_type = ? AND user_id = ?))
         ORDER BY user_id DESC, period = 'lifetime' DESC
         LIMIT 1",
        [$application['leave_type'], $application['applicant_role'], $application['applicant_role'], $application['applicant_id']]
    );

    $period = $alloc ? $alloc['period'] : 'yearly';
    $year = $period === 'lifetime' ? 0 : (int)date('Y');

    $existing = Database::fetch(
        "SELECT id, days_taken FROM leave_taken
         WHERE user_id = ? AND year = ? AND leave_type = ? AND period = ?",
        [$application['applicant_id'], $year, $application['leave_type'], $period]
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
            'year' => $year,
            'leave_type' => $application['leave_type'],
            'period' => $period,
            'days_taken' => $days,
        ]);
    }
}
