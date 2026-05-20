<?php

$router->get('/api/admin-panel/results', function () {
    Auth::requireRole('administration');

    $where = [];
    $params = [];
    foreach (['exam_name', 'class', 'year', 'subject'] as $f) {
        if (!empty($_GET[$f])) {
            $col = $f === 'exam_name' || $f === 'class' || $f === 'year' ? "e.{$f}" : "er.{$f}";
            $where[] = "{$col} = ?";
            $params[] = $_GET[$f];
        }
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $results = Database::fetchAll(
        "SELECT er.*, s.student_id, s.name as student_name, e.exam_name, e.class, e.year
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         {$whereClause}
         ORDER BY e.year DESC, e.exam_name, s.student_id",
        $params
    );
    Response::success($results);
});
