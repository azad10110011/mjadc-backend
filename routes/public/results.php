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

    // Session 100: results held for unpaid fees are not shown
    $stu = Database::fetch("SELECT id, user_id, class FROM students WHERE student_id = ?", [$studentId]);
    if ($stu && preg_match('/^\d{4}$/', (string)$year)) {
        $dues = duesStatusFor([$stu], (int)$year, (string)$examName);
        if (duesHeld($dues[(int)$stu['id']] ?? null)) {
            Response::error('This result is on hold because of unpaid fees. Please contact the college office.', 403);
        }
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
