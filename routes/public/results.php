<?php

// GET /api/results/search?year=&class=&exam_name=&student_id=
// Public result search (Session 115): anyone can see a student's result SUMMARY only —
// Passed + GPA, or Failed + the failed subject names. Never the subject-by-subject transcript.
// GPA / fail rules come from transcriptBuild() so they match the official transcript
// (optional-subject bonus, compulsory F/Absent = whole result F).
$router->get('/api/results/search', function () {
    $year = trim((string)($_GET['year'] ?? ''));
    $class = trim((string)($_GET['class'] ?? ''));
    $examName = trim((string)($_GET['exam_name'] ?? ''));
    $studentId = trim((string)($_GET['student_id'] ?? ''));

    if ($year === '' || $class === '' || $examName === '' || $studentId === '') {
        Response::validationError(['Missing required filter parameters']);
    }

    $exam = Database::fetch(
        "SELECT id, year, class, exam_name FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $stu = Database::fetch("SELECT id, user_id, class, student_id FROM students WHERE student_id = ?", [$studentId]);
    if (!$exam || !$stu) {
        Response::success(null, 'No result found');
    }

    // Session 113: all-or-nothing — only once EVERY subject row of this student is published.
    $rows = Database::fetch(
        "SELECT COUNT(*) AS total_rows, SUM(status = 'published') AS published_rows
         FROM exam_results WHERE exam_id = ? AND student_id = ?",
        [$exam['id'], $stu['id']]
    );
    if (!$rows || (int)$rows['total_rows'] === 0) {
        Response::success(null, 'No result found');
    }
    if ((int)$rows['published_rows'] < (int)$rows['total_rows']) {
        Response::success(null, 'This result has not been published yet.');
    }

    // Session 100: results held for unpaid fees are not shown
    $dues = duesStatusFor([$stu], (int)$exam['year'], (string)$exam['exam_name']);
    if (duesHeld($dues[(int)$stu['id']] ?? null)) {
        Response::error('This result is on hold because of unpaid fees. Please contact the college office.', 403);
    }

    $list = transcriptBuild($exam, (string)$stu['student_id'], '', true);
    $t = $list[0] ?? null;
    if (!$t || empty($t['subjects'])) {
        Response::success(null, 'No result found');
    }

    $passed = strtoupper((string)$t['overall_grade']) !== 'F';
    Response::success([
        'student_id' => $t['student_id'],
        'name' => $t['name'],
        'status' => $passed ? 'Passed' : 'Failed',
        'final_gpa' => $passed ? (float)$t['overall_gpa'] : null,
        'grade' => $passed ? $t['overall_grade'] : 'F',
        'fail_subjects' => $passed ? [] : resultFailSubjects($t),
    ]);
});
