<?php

$adminMw = function () { Auth::requireRole('admin'); };

$router->get('/api/admin/academic-approvals', function () {
    Auth::requireRole('admin');
    $items = Database::fetchAll(
        "SELECT id, heading, COALESCE(image_path, '') as image_path, image_width, image_height, sort_order 
         FROM academic_approvals ORDER BY sort_order"
    );
    Response::success($items);
}, [$adminMw]);

$router->post('/api/admin/academic-approvals', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['heading'])) {
        Response::validationError(['heading is required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM academic_approvals");
    $id = Database::insert('academic_approvals', [
        'heading' => $data['heading'],
        'image_path' => $data['image_path'] ?? null,
        'image_width' => $data['image_width'] ?? null,
        'image_height' => $data['image_height'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Approval added');
}, [$adminMw]);

$router->put('/api/admin/academic-approvals/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['heading', 'image_path', 'image_width', 'image_height'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('academic_approvals', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Approval updated');
}, [$adminMw]);

$router->delete('/api/admin/academic-approvals/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('academic_approvals', 'id = ?', [$params['id']]);
    Response::success(null, 'Approval deleted');
}, [$adminMw]);

$router->post('/api/admin/academic-approvals/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Approval not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM academic_approvals WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('academic_approvals', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('academic_approvals', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

$router->post('/api/admin/academic-approvals/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM academic_approvals WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Approval not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM academic_approvals WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('academic_approvals', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('academic_approvals', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);
