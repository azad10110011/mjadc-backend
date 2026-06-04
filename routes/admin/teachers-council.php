<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/teachers-council
$router->get('/api/admin/teachers-council', function () {
    Auth::requireRole('admin');
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(position, '') as position, photo_path, sort_order
         FROM teachers_council ORDER BY sort_order"
    );
    Response::success($members);
}, [$adminMw]);

// POST /api/admin/teachers-council
$router->post('/api/admin/teachers-council', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['name']) || empty($data['designation'])) {
        Response::validationError(['name and designation are required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM teachers_council");
    $id = Database::insert('teachers_council', [
        'name' => $data['name'],
        'designation' => $data['designation'],
        'position' => $data['position'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Member added');
}, [$adminMw]);

// PUT /api/admin/teachers-council/{id}
$router->put('/api/admin/teachers-council/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['name', 'designation', 'position', 'photo_path'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('teachers_council', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Member updated');
}, [$adminMw]);

// DELETE /api/admin/teachers-council/{id}
$router->delete('/api/admin/teachers-council/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('teachers_council', 'id = ?', [$params['id']]);
    Response::success(null, 'Member deleted');
}, [$adminMw]);

// POST /api/admin/teachers-council/{id}/move-up
$router->post('/api/admin/teachers-council/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM teachers_council WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM teachers_council WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('teachers_council', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('teachers_council', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/teachers-council/{id}/move-down
$router->post('/api/admin/teachers-council/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM teachers_council WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM teachers_council WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('teachers_council', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('teachers_council', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);
