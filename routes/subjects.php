<?php

// GET /api/subjects?group=Science – returns flat list of subject names
// If group is provided, only subjects with that group (or 'General') are returned
$router->get('/api/subjects', function () {
    $group = $_GET['group'] ?? '';

    if ($group) {
        $subjectNames = array_unique(array_merge(
            array_column(Database::fetchAll(
                "SELECT DISTINCT name FROM subjects WHERE (type IN ('public','both')) AND (`group` = ? OR `group` = 'General')",
                [$group]
            ), 'name'),
            array_column(Database::fetchAll(
                "SELECT DISTINCT sp.name FROM subject_papers sp JOIN subjects s ON sp.parent_id = s.id WHERE s.type IN ('result','both') AND (s.`group` = ? OR s.`group` = 'General')",
                [$group]
            ), 'name')
        ));
    } else {
        $subjectNames = array_unique(array_merge(
            array_column(Database::fetchAll("SELECT DISTINCT name FROM subjects WHERE type IN ('public','both')"), 'name'),
            array_column(Database::fetchAll("SELECT DISTINCT sp.name FROM subject_papers sp JOIN subjects s ON sp.parent_id = s.id WHERE s.type IN ('result','both')"), 'name')
        ));
    }

    sort($subjectNames);
    Response::success(array_values($subjectNames));
});
