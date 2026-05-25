<?php

// GET /api/subjects — public, no auth required
$router->get('/api/subjects', function () {
    $subjects = Database::fetchAll("SELECT name FROM subjects ORDER BY name");
    $names = array_map(fn($s) => $s['name'], $subjects);
    Response::success($names);
});
