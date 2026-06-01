<?php

// GET /api/subjects – returns flat list of all subject names
$router->get('/api/subjects', function () {
    $subjects = array_unique(array_merge(
        array_column(Database::fetchAll("SELECT DISTINCT name FROM subjects WHERE type IN ('public','both')"), 'name'),
        array_column(Database::fetchAll("SELECT DISTINCT sp.name FROM subject_papers sp JOIN subjects s ON sp.parent_id = s.id WHERE s.type IN ('result','both')"), 'name')
    ));
    sort($subjects);
    Response::success(array_values($subjects));
});
