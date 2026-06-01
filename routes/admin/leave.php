<?php

// GET /api/admin/leave-management
$router->get('/api/admin/leave-management', function () {
    Auth::requireRole('admin');

    $applications = Database::fetchAll(
        "SELECT la.*, u.name as applicant_name
         FROM leave_applications la
         JOIN users u ON la.applicant_id = u.id
         ORDER BY la.created_at DESC"
    );

    $result = [];
    foreach ($applications as $app) {
        $alloc = Database::fetch(
            "SELECT total_days, period FROM leave_allocations
             WHERE leave_type = ?
               AND ((role_type = ? AND user_id IS NULL)
                OR (role_type = ? AND user_id = ?))
             ORDER BY user_id DESC, period = 'lifetime' DESC
             LIMIT 1",
            [$app['leave_type'], $app['applicant_role'], $app['applicant_role'], $app['applicant_id']]
        );

        $year = $alloc && $alloc['period'] === 'lifetime' ? 0 : date('Y');
        $period = $alloc ? $alloc['period'] : 'yearly';

        $taken = Database::fetch(
            "SELECT SUM(days_taken) as total FROM leave_taken
             WHERE user_id = ? AND leave_type = ? AND period = ? AND year = ?",
            [$app['applicant_id'], $app['leave_type'], $period, $year]
        );

        $allocated = $alloc ? (int)$alloc['total_days'] : 0;
        $takenDays = $taken ? (int)$taken['total'] : 0;

        $result[] = [
            'id' => $app['id'],
            'applicant_id' => $app['applicant_id'],
            'applicant_role' => $app['applicant_role'],
            'applicant_name' => $app['applicant_name'],
            'leave_type' => $app['leave_type'],
            'from_date' => $app['from_date'],
            'to_date' => $app['to_date'],
            'reason' => $app['reason'],
            'status' => $app['status'],
            'created_at' => $app['created_at'],
            'allocated' => $allocated,
            'taken' => $takenDays,
            'remaining' => max(0, $allocated - $takenDays),
        ];
    }

    Response::success($result);
});

// POST /api/admin/leave-management/action
$router->post('/api/admin/leave-management/action', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    validate($data)->required('application_id', 'Application ID')
        ->required('action', 'Action')
        ->inArray('action', ['approved', 'rejected'])
        ->validate();

    $application = Database::fetch(
        "SELECT * FROM leave_applications WHERE id = ?",
        [$data['application_id']]
    );

    if (!$application) {
        Response::notFound('Application not found');
    }

    Database::update('leave_applications', [
        'status' => $data['action'],
        'reviewed_by' => Auth::getUser()['id'],
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', ['id' => $data['application_id']]);

    if ($data['action'] === 'approved') {
        approveLeave($application);
    }

    Response::success(null, 'Leave ' . $data['action']);
});

// PUT /api/admin/leave-management/{id}
$router->put('/api/admin/leave-management/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['leave_type']) || !isset($data['from_date']) || !isset($data['to_date']) || !isset($data['status'])) {
        Response::validationError(['leave_type, from_date, to_date, status are required']);
    }

    $oldApp = Database::fetch("SELECT * FROM leave_applications WHERE id = ?", [$params['id']]);
    if (!$oldApp) {
        Response::notFound('Application not found');
    }

    Database::update('leave_applications', [
        'leave_type' => $data['leave_type'],
        'from_date' => $data['from_date'],
        'to_date' => $data['to_date'],
        'reason' => $data['reason'] ?? $oldApp['reason'],
        'status' => $data['status'],
    ], 'id = ?', ['id' => $params['id']]);

    // If status changed to approved and wasn't approved before, track the leave
    if ($data['status'] === 'approved' && $oldApp['status'] !== 'approved') {
        approveLeave($oldApp);
    }

    // If status changed away from approved, reverse the leave taken
    if ($data['status'] !== 'approved' && $oldApp['status'] === 'approved') {
        reverseLeave($oldApp);
    }

    Response::success(null, 'Leave application updated');
});

// POST /api/admin/leave-management
$router->post('/api/admin/leave-management', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('applicant_id', 'Applicant ID')
        ->required('applicant_role', 'Applicant Role')
        ->required('leave_type', 'Leave Type')
        ->required('from_date', 'From Date')
        ->required('to_date', 'To Date')
        ->required('reason', 'Reason');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $status = $data['status'] ?? 'pending';

    $applicationId = Database::insert('leave_applications', [
        'applicant_id' => (int)$data['applicant_id'],
        'applicant_role' => $data['applicant_role'],
        'leave_type' => $data['leave_type'],
        'from_date' => $data['from_date'],
        'to_date' => $data['to_date'],
        'reason' => $data['reason'],
        'status' => $status,
    ]);

    if ($status === 'approved') {
        $app = Database::fetch("SELECT * FROM leave_applications WHERE id = ?", [$applicationId]);
        if ($app) approveLeave($app);
    }

    Response::created(['id' => $applicationId], 'Leave application created');
});

// DELETE /api/admin/leave-management/{id}
$router->delete('/api/admin/leave-management/{id}', function (array $params) {
    Auth::requireRole('admin');

    $app = Database::fetch("SELECT * FROM leave_applications WHERE id = ?", [$params['id']]);
    if (!$app) {
        Response::notFound('Application not found');
    }

    if ($app['status'] === 'approved') {
        reverseLeave($app);
    }

    Database::delete('leave_applications', 'id = ?', [$params['id']]);
    Response::success(null, 'Leave application deleted');
});

// GET /api/admin/leave-allocations
$router->get('/api/admin/leave-allocations', function () {
    Auth::requireRole('admin');
    $allocations = Database::fetchAll(
        "SELECT la.*, u.name as user_name
         FROM leave_allocations la
         LEFT JOIN users u ON la.user_id = u.id
         ORDER BY la.role_type, la.leave_type, la.user_id IS NOT NULL, la.user_id"
    );
    Response::success($allocations);
});

// POST /api/admin/leave-allocations
$router->post('/api/admin/leave-allocations', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('role_type', 'Role Type')
        ->inArray('role_type', ['teacher', 'staff', 'principal'])
        ->required('leave_type', 'Leave Type')
        ->inArray('leave_type', ['casual', 'medical', 'maternity', 'without_pay'])
        ->required('total_days', 'Total Days')
        ->numeric('total_days');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $user_id = isset($data['user_id']) && $data['user_id'] ? (int)$data['user_id'] : null;
    $period = $data['period'] ?? 'yearly';

    // For maternity leave with per-user allocation, force lifetime
    if ($data['leave_type'] === 'maternity' && $user_id !== null) {
        $period = 'lifetime';
    }

    Database::insert('leave_allocations', [
        'role_type' => $data['role_type'],
        'user_id' => $user_id,
        'leave_type' => $data['leave_type'],
        'total_days' => (int)$data['total_days'],
        'period' => $period,
    ]);

    Response::created(null, 'Leave allocation created');
});

// PUT /api/admin/leave-allocations/{id}
$router->put('/api/admin/leave-allocations/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['total_days'])) {
        Response::validationError(['total_days is required']);
    }

    $fields = ['total_days' => (int)$data['total_days']];
    if (isset($data['period'])) {
        $fields['period'] = $data['period'];
    }

    Database::update('leave_allocations', $fields, 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'Leave allocation updated');
});

// DELETE /api/admin/leave-allocations/{id}
$router->delete('/api/admin/leave-allocations/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('leave_allocations', 'id = ?', [$params['id']]);
    Response::success(null, 'Leave allocation deleted');
});

// GET /api/admin/leave-taken
$router->get('/api/admin/leave-taken', function () {
    Auth::requireRole('admin');

    $taken = Database::fetchAll(
        "SELECT lt.*, u.name as user_name
         FROM leave_taken lt
         JOIN users u ON lt.user_id = u.id
         ORDER BY lt.user_id, lt.leave_type, lt.year DESC"
    );

    Response::success($taken);
});

// POST /api/admin/leave-taken
$router->post('/api/admin/leave-taken', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('user_id', 'User ID')
        ->required('leave_type', 'Leave Type')
        ->required('days_taken', 'Days Taken');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');
    $period = $data['period'] ?? 'yearly';

    $existing = Database::fetch(
        "SELECT id FROM leave_taken WHERE user_id = ? AND year = ? AND leave_type = ? AND period = ?",
        [$data['user_id'], $year, $data['leave_type'], $period]
    );

    if ($existing) {
        Database::update('leave_taken', [
            'days_taken' => (int)$data['days_taken'],
        ], 'id = ?', ['id' => $existing['id']]);
    } else {
        Database::insert('leave_taken', [
            'user_id' => (int)$data['user_id'],
            'year' => $year,
            'leave_type' => $data['leave_type'],
            'period' => $period,
            'days_taken' => (int)$data['days_taken'],
        ]);
    }

    Response::success(null, 'Leave taken record saved');
});

// PUT /api/admin/leave-taken/{id}
$router->put('/api/admin/leave-taken/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['days_taken'])) {
        Response::validationError(['days_taken is required']);
    }

    Database::update('leave_taken', ['days_taken' => (int)$data['days_taken']], 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'Leave taken record updated');
});

// DELETE /api/admin/leave-taken/{id}
$router->delete('/api/admin/leave-taken/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('leave_taken', 'id = ?', [$params['id']]);
    Response::success(null, 'Leave taken record deleted');
});

// POST /api/admin/leave-taken/reset-year
$router->post('/api/admin/leave-taken/reset-year', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $year = isset($data['year']) ? (int)$data['year'] : (int)date('Y');

    // Set days_taken to 0 for all users for this year
    Database::query(
        "UPDATE leave_taken SET days_taken = 0 WHERE year = ? AND period = 'yearly'",
        [$year]
    );

    Response::success(null, "All taken records reset for year $year");
});

// GET /api/admin/users-by-role
$router->get('/api/admin/users-by-role', function () {
    Auth::requireRole('admin');

    $role = $_GET['role'] ?? '';
    if (!in_array($role, ['teacher', 'staff', 'principal'])) {
        Response::validationError(['Invalid role']);
    }

    $users = Database::fetchAll(
        "SELECT u.id, u.name, u.email, u.gender
         FROM users u
         JOIN user_roles ur ON u.id = ur.user_id
         WHERE ur.role = ?
         ORDER BY u.name",
        [$role]
    );

    Response::success($users);
});

// Shared helper: approve leave and track taken days
function approveLeave(array $application): void {
    $from = new DateTime($application['from_date']);
    $to = new DateTime($application['to_date']);
    $days = $from->diff($to)->days + 1;

    // Determine allocation period (yearly or lifetime)
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

    // Check maternity limit: max 2 approved applications lifetime
    if ($application['leave_type'] === 'maternity') {
        $maternityCount = Database::fetch(
            "SELECT COUNT(*) as cnt FROM leave_applications
             WHERE applicant_id = ? AND leave_type = 'maternity' AND status = 'approved' AND id != ?",
            [$application['applicant_id'], $application['id']]
        );
        if ($maternityCount && (int)$maternityCount['cnt'] >= 2) {
            Response::error('This person has already used maternity leave twice. No more maternity leave allowed.');
        }
    }

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

// Shared helper: reverse leave taken days
function reverseLeave(array $application): void {
    $from = new DateTime($application['from_date']);
    $to = new DateTime($application['to_date']);
    $days = $from->diff($to)->days + 1;

    $alloc = Database::fetch(
        "SELECT period FROM leave_allocations
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
        $newDays = max(0, $existing['days_taken'] - $days);
        if ($newDays > 0) {
            Database::update('leave_taken', ['days_taken' => $newDays], 'id = ?', ['id' => $existing['id']]);
        } else {
            Database::delete('leave_taken', 'id = ?', [$existing['id']]);
        }
    }
}
