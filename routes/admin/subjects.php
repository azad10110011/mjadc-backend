<?php

// GET /api/admin/subjects
$router->get('/api/admin/subjects', function () {
    Auth::requireRole('admin');
    $subjects = Database::fetchAll("SELECT id, name, created_at FROM subjects ORDER BY name");
    Response::success($subjects);
});

// POST /api/admin/subjects
$router->post('/api/admin/subjects', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        Response::error('Subject name is required', 400);
    }

    $existing = Database::fetch("SELECT id FROM subjects WHERE name = ?", [$name]);
    if ($existing) {
        Response::error('Subject already exists', 409);
    }

    $id = Database::insert('subjects', ['name' => $name]);
    Response::created(['id' => $id, 'name' => $name], 'Subject created');
});

// DELETE /api/admin/subjects/{id}
$router->delete('/api/admin/subjects/{id}', function (array $params) {
    Auth::requireRole('admin');

    $id = (int)$params['id'];
    Database::delete('subjects', 'id = ?', [$id]);
    Response::success(null, 'Subject deleted');
});
