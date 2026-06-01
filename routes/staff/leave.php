<?php

// GET /api/staff/leave/summary
$router->get('/api/staff/leave/summary', function () {
    $user = Auth::requireRole('staff');

    $currentYear = date('Y');
    $allocations = Database::fetchAll(
        "SELECT * FROM leave_allocations WHERE role_type = 'staff' AND user_id IS NULL
         UNION
         SELECT * FROM leave_allocations WHERE role_type = 'staff' AND user_id = ?
         ORDER BY user_id DESC, period = 'lifetime' DESC",
        [$user['id']]
    );

    if (empty($allocations)) {
        $allocations = Database::fetchAll(
            "SELECT * FROM leave_allocations WHERE role_type = 'staff' AND user_id IS NULL"
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

    // Check maternity 2-time limit
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

    // Determine allocation for balance check
    $alloc = Database::fetch(
        "SELECT total_days, period FROM leave_allocations
         WHERE leave_type = ?
           AND ((role_type = 'staff' AND user_id IS NULL)
            OR (role_type = 'staff' AND user_id = ?))
         ORDER BY user_id DESC, period = 'lifetime' DESC
         LIMIT 1",
        [$data['leave_type'], $user['id']]
    );

    if ($alloc) {
        $period = $alloc['period'];
        $year = $period === 'lifetime' ? 0 : (int)date('Y');

        $existing = Database::fetch(
            "SELECT days_taken FROM leave_taken
             WHERE user_id = ? AND year = ? AND leave_type = ? AND period = ?",
            [$user['id'], $year, $data['leave_type'], $period]
        );

        $from = new DateTime($data['from_date']);
        $to = new DateTime($data['to_date']);
        $daysRequested = $from->diff($to)->days + 1;

        $existingTaken = $existing ? (int)$existing['days_taken'] : 0;
        $totalAllowed = (int)$alloc['total_days'];

        if ($totalAllowed > 0 && ($existingTaken + $daysRequested > $totalAllowed)) {
            Response::error('Insufficient leave balance');
        }
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

// PUT /api/staff/leave/{id}
$router->put('/api/staff/leave/{id}', function (array $params) {
    $user = Auth::requireRole('staff');

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
