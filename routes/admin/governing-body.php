<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/governing-body
$router->get('/api/admin/governing-body', function () {
    Auth::requireRole('admin');
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(position, '') as position, mobile, photo_path, sort_order 
         FROM governing_body ORDER BY sort_order"
    );
    Response::success($members);
}, [$adminMw]);

// POST /api/admin/governing-body
$router->post('/api/admin/governing-body', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['name']) || empty($data['designation'])) {
        Response::validationError(['name and designation are required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM governing_body");
    $id = Database::insert('governing_body', [
        'name' => $data['name'],
        'designation' => $data['designation'],
        'position' => $data['position'] ?? null,
        'mobile' => $data['mobile'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Member added');
}, [$adminMw]);

// PUT /api/admin/governing-body/{id}
$router->put('/api/admin/governing-body/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['name', 'designation', 'position', 'mobile', 'photo_path'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('governing_body', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Member updated');
}, [$adminMw]);

// DELETE /api/admin/governing-body/{id}
$router->delete('/api/admin/governing-body/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('governing_body', 'id = ?', [$params['id']]);
    Response::success(null, 'Member deleted');
}, [$adminMw]);

// POST /api/admin/governing-body/{id}/move-up
$router->post('/api/admin/governing-body/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM governing_body WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM governing_body WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('governing_body', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('governing_body', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/governing-body/{id}/move-down
$router->post('/api/admin/governing-body/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM governing_body WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM governing_body WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('governing_body', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('governing_body', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/governing-body/reorder
$router->post('/api/admin/governing-body/reorder', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) Response::validationError(['ids' => 'ids array required']);
    foreach ($ids as $i => $id) {
        Database::update('governing_body', ['sort_order' => $i + 1], 'id = ?', ['id' => (int)$id]);
    }
    Response::success(null, 'Reordered');
}, [$adminMw]);
