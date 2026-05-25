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

    $results = Database::fetchAll(
        "SELECT s.student_id, s.name, er.subject,
                er.mcq, er.cq, er.practical, er.parts_data,
                er.total, er.grade, er.gpa, er.absent_in
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE e.year = ? AND e.class = ? AND e.exam_name = ?
               AND s.student_id = ? AND er.status = 'published'
         ORDER BY er.subject",
        [$year, $class, $examName, $studentId]
    );

    if (empty($results)) {
        Response::success(null, 'No result found');
    }

    $totalGpa = 0;
    $subjectCount = 0;

    foreach ($results as &$r) {
        $r['parts_data'] = $r['parts_data'] ? json_decode($r['parts_data'], true) : null;
        $r['absent_in'] = $r['absent_in'] ? json_decode($r['absent_in'], true) : [];

        if ($r['gpa'] !== null && $r['grade'] !== 'Absent') {
            $totalGpa += (float)$r['gpa'];
            $subjectCount++;
        }
    }

    $student = $results[0];

    Response::success([
        'student_id' => $student['student_id'],
        'name' => $student['name'],
        'subjects' => $results,
        'gpa' => $subjectCount > 0 ? number_format($totalGpa / $subjectCount, 2) : '0.00',
    ]);
});
