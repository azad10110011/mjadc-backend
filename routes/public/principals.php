<?php

$router->get('/api/principals', function () {
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(message, '') as message, photo_path 
         FROM principals ORDER BY sort_order"
    );
    Response::success($members);
});
