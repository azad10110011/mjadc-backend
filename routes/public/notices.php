<?php

// GET /api/notices
$router->get('/api/notices', function () {
    $notices = Database::fetchAll(
        "SELECT id, title, body, status, pdf_path, published_at, created_at 
         FROM notices 
         WHERE status = 'published' 
         ORDER BY published_at DESC"
    );
    Response::success($notices);
});

// GET /api/notices/{id}
$router->get('/api/notices/{id}', function (array $params) {
    $notice = Database::fetch(
        "SELECT id, title, body, pdf_path, published_at, created_at 
         FROM notices WHERE id = ? AND status = 'published'",
        [$params['id']]
    );
    if (!$notice) {
        Response::notFound('Notice not found');
    }
    Response::success($notice);
});
