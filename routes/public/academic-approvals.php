<?php

$router->get('/api/academic-approvals', function () {
    $items = Database::fetchAll(
        "SELECT id, heading, COALESCE(image_path, '') as image_path, image_width, image_height 
         FROM academic_approvals ORDER BY sort_order"
    );
    Response::success($items);
});
