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

    Response::success($applications);
});

// POST /api/admin/leave-management/action
$router->post('/api/admin/leave-management/action', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    validate($data)->required('application_id', 'Application ID')
        ->required('action', 'Action')
        ->inArray('action', ['approved', 'rejected'])
        ->validate();

    Database::update('leave_applications', [
        'status' => $data['action'],
        'reviewed_by' => Auth::getUser()['id'],
        'reviewed_at' => date('Y-m-d H:i:s'),
    ], 'id = ?', ['id' => $data['application_id']]);

    Response::success(null, 'Leave ' . $data['action']);
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
