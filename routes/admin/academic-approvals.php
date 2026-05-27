<?php

$router->get('/api/admin/academic-approvals', function () {
    Auth::requireRole('admin');
    $items = Database::fetchAll("SELECT id, heading, image_path, image_width, image_height, sort_order FROM academic_approvals ORDER BY sort_order");
    Response::success($items);
});

$router->post('/api/admin/academic-approvals', function () {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            Response::validationError(['Request body is required']);
        }
        $validator = validate($data);
        $validator->required('heading', 'Heading');
        if (!$validator->passes()) Response::validationError($validator->errors());

        $maxOrder = Database::fetch("SELECT MAX(sort_order) as max_order FROM academic_approvals");
        $sortOrder = ($maxOrder['max_order'] ?? 0) + 1;

        $id = Database::insert('academic_approvals', [
            'heading' => $data['heading'],
            'image_path' => $data['image_path'] ?? null,
            'image_width' => $data['image_width'] ?? null,
            'image_height' => $data['image_height'] ?? null,
            'sort_order' => $sortOrder,
        ]);
        Response::created(['id' => $id], 'Approval added');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->put('/api/admin/academic-approvals/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        foreach (['heading', 'image_path', 'image_width', 'image_height'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (!empty($updateData)) Database::update('academic_approvals', $updateData, 'id = ?', ['id' => $params['id']]);
        Response::success(null, 'Approval updated');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->delete('/api/admin/academic-approvals/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $deleted = Database::fetch("SELECT sort_order FROM academic_approvals WHERE id = ?", [$params['id']]);
        if (!$deleted) Response::notFound('Not found');
        Database::delete('academic_approvals', 'id = ?', [$params['id']]);
        Database::query("UPDATE academic_approvals SET sort_order = sort_order - 1 WHERE sort_order > ?", [$deleted['sort_order']]);
        Response::success(null, 'Approval deleted');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->post('/api/admin/academic-approvals/{id}/move-up', function (array $params) {
    try {
        Auth::requireRole('admin');
        $current = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE id = ?", [$params['id']]);
        if (!$current) Response::notFound('Not found');
        $above = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1", [$current['sort_order']]);
        if (!$above) Response::success(null, 'Already at top');
        Database::update('academic_approvals', ['sort_order' => $above['sort_order']], 'id = ?', [$current['id']]);
        Database::update('academic_approvals', ['sort_order' => $current['sort_order']], 'id = ?', [$above['id']]);
        Response::success(null, 'Moved up');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

$router->post('/api/admin/academic-approvals/{id}/move-down', function (array $params) {
    try {
        Auth::requireRole('admin');
        $current = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE id = ?", [$params['id']]);
        if (!$current) Response::notFound('Not found');
        $below = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1", [$current['sort_order']]);
        if (!$below) Response::success(null, 'Already at bottom');
        Database::update('academic_approvals', ['sort_order' => $below['sort_order']], 'id = ?', [$current['id']]);
        Database::update('academic_approvals', ['sort_order' => $current['sort_order']], 'id = ?', [$below['id']]);
        Response::success(null, 'Moved down');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});
