<?php

// GET /api/pages/{key}
$router->get('/api/pages/{key}', function (array $params) {
    $page = Database::fetch(
        "SELECT page_key, title, content, updated_at 
         FROM page_content WHERE page_key = ?",
        [$params['key']]
    );
    if (!$page) {
        Response::notFound('Page not found');
    }
    Response::success($page);
});
