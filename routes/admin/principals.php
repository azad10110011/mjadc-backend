<?php

$adminMw = function () { Auth::requireRole('admin'); };

$router->get('/api/admin/principals', function () {
    Auth::requireRole('admin');
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(message, '') as message, photo_path, sort_order 
         FROM principals ORDER BY sort_order"
    );
    Response::success($members);
}, [$adminMw]);

$router->post('/api/admin/principals', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['name']) || empty($data['designation'])) {
        Response::validationError(['name and designation are required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM principals");
    $id = Database::insert('principals', [
        'name' => $data['name'],
        'designation' => $data['designation'],
        'message' => $data['message'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Member added');
}, [$adminMw]);

$router->put('/api/admin/principals/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['name', 'designation', 'message', 'photo_path'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('principals', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Member updated');
}, [$adminMw]);

$router->delete('/api/admin/principals/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('principals', 'id = ?', [$params['id']]);
    Response::success(null, 'Member deleted');
}, [$adminMw]);

$router->post('/api/admin/principals/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM principals WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM principals WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('principals', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('principals', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

$router->post('/api/admin/principals/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM principals WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM principals WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('principals', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('principals', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);
