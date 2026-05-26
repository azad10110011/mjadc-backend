<?php

// GET /api/admin/pages
$router->get('/api/admin/pages', function () {
    Auth::requireRole('admin');
    $pages = Database::fetchAll("SELECT * FROM page_content ORDER BY page_key");
    Response::success($pages);
});

// POST /api/admin/pages
$router->post('/api/admin/pages', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['page_key']) || !isset($data['content'])) {
        Response::validationError(['page_key and content are required']);
    }

    $existing = Database::fetch("SELECT id FROM page_content WHERE page_key = ?", [$data['page_key']]);
    if ($existing) {
        Response::error('Page key already exists', 409);
    }

    Database::insert('page_content', [
        'page_key' => $data['page_key'],
        'title' => $data['title'] ?? null,
        'content' => $data['content'],
        'updated_by' => $user['id'],
    ]);

    Response::created(null, 'Page created');
});

// PUT /api/admin/pages/{key}
$router->put('/api/admin/pages/{key}', function (array $params) {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['content'])) {
        Response::validationError(['content is required']);
    }

    $existing = Database::fetch("SELECT id FROM page_content WHERE page_key = ?", [$params['key']]);

    if ($existing) {
        Database::update('page_content', [
            'content' => $data['content'],
            'title' => $data['title'] ?? null,
            'updated_by' => $user['id'],
        ], 'page_key = ?', ['page_key' => $params['key']]);
        Response::success(null, 'Page updated');
    } else {
        Database::insert('page_content', [
            'page_key' => $params['key'],
            'title' => $data['title'] ?? null,
            'content' => $data['content'],
            'updated_by' => $user['id'],
        ]);
        Response::created(null, 'Page created');
    }
});

// DELETE /api/admin/pages/{key}
$router->delete('/api/admin/pages/{key}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('page_content', 'page_key = ?', ['page_key' => $params['key']]);
    Response::success(null, 'Page deleted');
});
