<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/career-club
$router->get('/api/admin/career-club', function () {
    Auth::requireRole('admin');
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(position, '') as position, COALESCE(mobile, '') as mobile, photo_path, sort_order
         FROM career_club ORDER BY sort_order"
    );
    Response::success($members);
}, [$adminMw]);

// POST /api/admin/career-club
$router->post('/api/admin/career-club', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['name']) || empty($data['designation'])) {
        Response::validationError(['name and designation are required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM career_club");
    $id = Database::insert('career_club', [
        'name' => $data['name'],
        'designation' => $data['designation'],
        'position' => $data['position'] ?? null,
        'mobile' => $data['mobile'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Member added');
}, [$adminMw]);

// PUT /api/admin/career-club/{id}
$router->put('/api/admin/career-club/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['name', 'designation', 'position', 'mobile', 'photo_path'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('career_club', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Member updated');
}, [$adminMw]);

// DELETE /api/admin/career-club/{id}
$router->delete('/api/admin/career-club/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('career_club', 'id = ?', [$params['id']]);
    Response::success(null, 'Member deleted');
}, [$adminMw]);

// POST /api/admin/career-club/{id}/move-up
$router->post('/api/admin/career-club/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM career_club WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM career_club WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('career_club', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('career_club', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/career-club/{id}/move-down
$router->post('/api/admin/career-club/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM career_club WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Member not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM career_club WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('career_club', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('career_club', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);
