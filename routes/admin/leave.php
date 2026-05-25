<?php

// GET /api/admin/leave-management
$router->get('/api/admin/leave-management', function () {
    Auth::requireRole('admin');

    $applications = Database::fetchAll(
        "SELECT la.*, u.name as applicant_name,
                COALESCE(la_alloc.total_days, 0) as allocated,
                COALESCE(lt_total, 0) as taken
         FROM leave_applications la
         JOIN users u ON la.applicant_id = u.id
         LEFT JOIN leave_allocations la_alloc
              ON la_alloc.role_type = la.applicant_role
              AND la_alloc.leave_type = la.leave_type
         LEFT JOIN (
              SELECT user_id, leave_type, year, SUM(days_taken) as lt_total
              FROM leave_taken
              GROUP BY user_id, leave_type, year
         ) lt ON lt.user_id = la.applicant_id
              AND lt.leave_type = la.leave_type
              AND lt.year = YEAR(CURDATE())
         ORDER BY la.created_at DESC"
    );

    $applications = array_map(function ($app) {
        $app['allocated'] = (int)($app['allocated'] ?? 0);
        $app['taken'] = (int)($app['taken'] ?? 0);
        $app['remaining'] = max(0, $app['allocated'] - $app['taken']);
        return $app;
    }, $applications);

    Response::success($applications);
});

// POST /api/admin/leave-management/action
$router->post('/api/admin/leave-management/action', function () {
    $user = Auth::requireRole('admin');

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
        'reviewed_by' => $user['id'],
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', ['id' => $data['application_id']]);

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

// PUT /api/admin/leave-management/{id}
$router->put('/api/admin/leave-management/{id}', function (array $params) {
    $user = Auth::requireRole('admin');

    $application = Database::fetch(
        "SELECT * FROM leave_applications WHERE id = ?",
        [$params['id']]
    );

    if (!$application) {
        Response::notFound('Application not found');
    }

    $data = json_decode(file_get_contents('php://input'), true);
    $allowedFields = ['leave_type', 'from_date', 'to_date', 'reason', 'status'];
    $updateData = [];

    foreach ($allowedFields as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }

    if (empty($updateData)) {
        Response::validationError(['No valid fields to update']);
    }

    if (isset($data['status'])) {
        $updateData['reviewed_by'] = $user['id'];
        $updateData['reviewed_at'] = date('Y-m-d H:i:s');
    }

    Database::update('leave_applications', $updateData, 'id = ?', ['id' => $params['id']]);

    // Update leave_taken when status or dates change
    $oldStatus = $application['status'];
    $newStatus = $data['status'] ?? $oldStatus;
    $oldFrom = $application['from_date'];
    $oldTo = $application['to_date'];
    $newFrom = $data['from_date'] ?? $oldFrom;
    $newTo = $data['to_date'] ?? $oldTo;
    $leaveType = $data['leave_type'] ?? $application['leave_type'];

    $calcDays = function ($from, $to) {
        $f = new DateTime($from);
        $t = new DateTime($to);
        return $f->diff($t)->days + 1;
    };

    if ($oldStatus === 'approved' && $newStatus !== 'approved') {
        // Remove from leave_taken
        $days = $calcDays($oldFrom, $oldTo);
        $existing = Database::fetch(
            "SELECT id, days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$application['applicant_id'], date('Y'), $application['leave_type']]
        );
        if ($existing) {
            $newTaken = max(0, $existing['days_taken'] - $days);
            Database::update('leave_taken', ['days_taken' => $newTaken], 'id = ?', ['id' => $existing['id']]);
        }
    } elseif ($newStatus === 'approved' && $oldStatus !== 'approved') {
        // Add to leave_taken
        $days = $calcDays($newFrom, $newTo);
        $existing = Database::fetch(
            "SELECT id, days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$application['applicant_id'], date('Y'), $leaveType]
        );
        if ($existing) {
            Database::update('leave_taken', ['days_taken' => $existing['days_taken'] + $days], 'id = ?', ['id' => $existing['id']]);
        } else {
            Database::insert('leave_taken', [
                'user_id' => $application['applicant_id'],
                'year' => date('Y'),
                'leave_type' => $leaveType,
                'days_taken' => $days,
            ]);
        }
    } elseif ($oldStatus === 'approved' && $newStatus === 'approved'
              && ($newFrom !== $oldFrom || $newTo !== $oldTo || $leaveType !== $application['leave_type'])) {
        $oldDays = $calcDays($oldFrom, $oldTo);
        $newDays = $calcDays($newFrom, $newTo);

        $existing = Database::fetch(
            "SELECT id, days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$application['applicant_id'], date('Y'), $application['leave_type']]
        );
        if ($existing) {
            Database::update('leave_taken',
                ['days_taken' => max(0, $existing['days_taken'] - $oldDays)],
                'id = ?', ['id' => $existing['id']]);
        }

        $existing = Database::fetch(
            "SELECT id, days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$application['applicant_id'], date('Y'), $leaveType]
        );
        if ($existing) {
            Database::update('leave_taken',
                ['days_taken' => $existing['days_taken'] + $newDays],
                'id = ?', ['id' => $existing['id']]);
        } else {
            Database::insert('leave_taken', [
                'user_id' => $application['applicant_id'],
                'year' => date('Y'),
                'leave_type' => $leaveType,
                'days_taken' => $newDays,
            ]);
        }
    }

    Response::success(null, 'Leave application updated');
});

// GET /api/admin/leave-allocations
$router->get('/api/admin/leave-allocations', function () {
    Auth::requireRole('admin');
    $allocations = Database::fetchAll("SELECT * FROM leave_allocations");
    Response::success($allocations);
});

// PUT /api/admin/leave-allocations/{id}
$router->put('/api/admin/leave-allocations/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['total_days'])) {
        Response::validationError(['total_days is required']);
    }

    Database::update('leave_allocations', ['total_days' => $data['total_days']], 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'Leave allocation updated');
});
