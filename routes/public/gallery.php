<?php

// GET /api/gallery
$router->get('/api/gallery', function () {
    $images = Database::fetchAll(
        "SELECT id, caption, event_name, photo_path, uploaded_at 
         FROM gallery ORDER BY uploaded_at DESC"
    );
    Response::success($images);
});
