<?php

// GET /api/routines
$router->get('/api/routines', function () {
    $routines = Database::fetchAll(
        "SELECT id, class, section, pdf_path, uploaded_at 
         FROM routines ORDER BY class, section"
    );
    Response::success($routines);
});

// GET /api/routines/{class}
$router->get('/api/routines/{class}', function (array $params) {
    $routines = Database::fetchAll(
        "SELECT id, class, section, pdf_path, uploaded_at 
         FROM routines WHERE class = ?",
        [$params['class']]
    );
    Response::success($routines);
});
