<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/co-curricular/{club}
$router->get('/api/admin/co-curricular/{club}', function (array $params) {
    Auth::requireRole('admin');
    $validClubs = ['bncc', 'rover-scout', 'science-club', 'debating-club'];
    $clubMap = ['bncc' => 'BNCC', 'rover-scout' => 'Rover Scout', 'science-club' => 'Science Club', 'debating-club' => 'Debating Club'];
    $club = $clubMap[$params['club']] ?? null;
    if (!$club) Response::error('Invalid club', 400);

    $members = Database::fetchAll(
        "SELECT id, club, name, designation, mobile, photo_path, sort_order 
         FROM co_curricular WHERE club = ? ORDER BY sort_order",
        [$club]
    );
    Response::success($members);
}, [$adminMw]);

// POST /api/admin/co-curricular/{club}
$router->post('/api/admin/co-curricular/{club}', function (array $params) {
    Auth::requireRole('admin');
    $clubMap = ['bncc' => 'BNCC', 'rover-scout' => 'Rover Scout', 'science-club' => 'Science Club', 'debating-club' => 'Debating Club'];
    $club = $clubMap[$params['club']] ?? null;
    if (!$club) Response::error('Invalid club', 400);

    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['name']) || empty($data['designation'])) {
        Response::validationError(['name and designation are required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM co_curricular WHERE club = ?", [$club]);
    $id = Database::insert('co_curricular', [
        'club' => $club,
        'name' => $data['name'],
        'designation' => $data['designation'],
        'mobile' => $data['mobile'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Member added');
}, [$adminMw]);

// PUT /api/admin/co-curricular/{club}/{id}
$router->put('/api/admin/co-curricular/{club}/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['name', 'designation', 'mobile', 'photo_path'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('co_curricular', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Member updated');
}, [$adminMw]);

// DELETE /api/admin/co-curricular/{club}/{id}
$router->delete('/api/admin/co-curricular/{club}/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('co_curricular', 'id = ?', [$params['id']]);
    Response::success(null, 'Member deleted');
}, [$adminMw]);

// POST /api/admin/co-curricular/{club}/{id}/move-up
$router->post('/api/admin/co-curricular/{club}/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $clubMap = ['bncc' => 'BNCC', 'rover-scout' => 'Rover Scout', 'science-club' => 'Science Club', 'debating-club' => 'Debating Club'];
    $club = $clubMap[$params['club']] ?? null;
    if (!$club) Response::error('Invalid club', 400);

    $current = Database::fetch("SELECT id, sort_order FROM co_curricular WHERE id = ? AND club = ?", [$params['id'], $club]);
    if (!$current) Response::notFound('Member not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM co_curricular WHERE club = ? AND sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$club, $current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('co_curricular', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('co_curricular', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/co-curricular/{club}/{id}/move-down
$router->post('/api/admin/co-curricular/{club}/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $clubMap = ['bncc' => 'BNCC', 'rover-scout' => 'Rover Scout', 'science-club' => 'Science Club', 'debating-club' => 'Debating Club'];
    $club = $clubMap[$params['club']] ?? null;
    if (!$club) Response::error('Invalid club', 400);

    $current = Database::fetch("SELECT id, sort_order FROM co_curricular WHERE id = ? AND club = ?", [$params['id'], $club]);
    if (!$current) Response::notFound('Member not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM co_curricular WHERE club = ? AND sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$club, $current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('co_curricular', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('co_curricular', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);
