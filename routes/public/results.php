<?php

// GET /api/results/search?year=&class=&exam_name=&student_id=
$router->get('/api/results/search', function () {
    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $studentId = $_GET['student_id'] ?? '';

    if (!$year || !$class || !$examName || !$studentId) {
        Response::validationError(['Missing required filter parameters']);
    }

    $result = Database::fetch(
        "SELECT s.student_id, s.name, 
                AVG(er.gpa) as gpa
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE e.year = ? AND e.class = ? AND e.exam_name = ? 
               AND s.student_id = ? AND er.status = 'published'
         GROUP BY s.student_id, s.name",
        [$year, $class, $examName, $studentId]
    );

    if (!$result) {
        Response::success(null, 'No result found');
    }

    Response::success([
        'student_id' => $result['student_id'],
        'name' => $result['name'],
        'gpa' => number_format((float)$result['gpa'], 2),
    ]);
});
