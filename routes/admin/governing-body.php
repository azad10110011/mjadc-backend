<?php

$router->get('/api/admin/governing-body', function () {
    Auth::requireRole('admin');
    $members = Database::fetchAll("SELECT id, name, designation, mobile, photo_path, sort_order FROM governing_body ORDER BY sort_order");
    Response::success($members);
});

$router->post('/api/admin/governing-body', function () {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            Response::validationError(['Request body is required']);
        }
        $validator = validate($data);
        $validator->required('name', 'Name')
            ->required('designation', 'Designation');
        if (!$validator->passes()) {
            Response::validationError($validator->errors());
        }
        $maxOrder = Database::fetch("SELECT MAX(sort_order) as max_order FROM governing_body");
        $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

        $id = Database::insert('governing_body', [
            'name' => $data['name'],
            'designation' => $data['designation'],
            'mobile' => $data['mobile'] ?? null,
            'photo_path' => $data['photo_path'] ?? null,
            'sort_order' => $sortOrder,
        ]);
        Response::created(['id' => $id], 'Member added');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->put('/api/admin/governing-body/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        foreach (['name', 'designation', 'mobile', 'photo_path'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (!empty($updateData)) {
            Database::update('governing_body', $updateData, 'id = ?', ['id' => $params['id']]);
        }
        Response::success(null, 'Member updated');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->delete('/api/admin/governing-body/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $deleted = Database::fetch("SELECT sort_order FROM governing_body WHERE id = ?", [$params['id']]);
        if (!$deleted) Response::notFound('Member not found');
        Database::delete('governing_body', 'id = ?', [$params['id']]);
        Database::query("UPDATE governing_body SET sort_order = sort_order - 1 WHERE sort_order > ?", [$deleted['sort_order']]);
        Response::success(null, 'Member deleted');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->post('/api/admin/governing-body/{id}/move-up', function (array $params) {
    try {
        Auth::requireRole('admin');
        $current = Database::fetch("SELECT id, sort_order FROM governing_body WHERE id = ?", [$params['id']]);
        if (!$current) Response::notFound('Member not found');
        $above = Database::fetch("SELECT id, sort_order FROM governing_body WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1", [$current['sort_order']]);
        if (!$above) Response::success(null, 'Already at top');
        Database::update('governing_body', ['sort_order' => $above['sort_order']], 'id = ?', [$current['id']]);
        Database::update('governing_body', ['sort_order' => $current['sort_order']], 'id = ?', [$above['id']]);
        Response::success(null, 'Moved up');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->post('/api/admin/governing-body/{id}/move-down', function (array $params) {
    try {
        Auth::requireRole('admin');
        $current = Database::fetch("SELECT id, sort_order FROM governing_body WHERE id = ?", [$params['id']]);
        if (!$current) Response::notFound('Member not found');
        $below = Database::fetch("SELECT id, sort_order FROM governing_body WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1", [$current['sort_order']]);
        if (!$below) Response::success(null, 'Already at bottom');
        Database::update('governing_body', ['sort_order' => $below['sort_order']], 'id = ?', [$current['id']]);
        Database::update('governing_body', ['sort_order' => $current['sort_order']], 'id = ?', [$below['id']]);
        Response::success(null, 'Moved down');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});
