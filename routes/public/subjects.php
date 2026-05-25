<?php

// GET /api/subjects — public, returns subjects for public/teacher directory use
$router->get('/api/subjects', function () {
    $subjects = Database::fetchAll(
        "SELECT name FROM subjects WHERE type IN ('public','both') ORDER BY name"
    );
    $names = array_map(fn($s) => $s['name'], $subjects);
    Response::success($names);
});

// GET /api/result-subjects — returns subjects for result management
$router->get('/api/result-subjects', function () {
    Auth::requireAnyRole(['teacher', 'exam_controller', 'admin', 'administration']);
    $names = getResultSubjects();
    Response::success($names);
});
