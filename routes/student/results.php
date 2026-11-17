<?php

// GET /api/student/results?exam_name=&year=   (Session 77)
// The logged-in student's own result for one exam. Restores an endpoint the "My Results" page has
// always called but that was missing from this backend (404 locally and on live).
// Uses transcriptBuild()/transcriptMeritPositions() (routes/admin/transcript.php) so GPA, optional
// subject, absent and fail rules are identical to the official transcript.
// Only shown when ALL of the student's rows for that exam are published.

$router->get('/api/student/results', function () {
    $user = Auth::requireRole('student');

    $student = Database::fetch("SELECT id, user_id, student_id, class FROM students WHERE user_id = ?", [$user['id']]);
    if (!$student) {
        Response::notFound('Student profile not found');
    }

    $examName = trim((string)($_GET['exam_name'] ?? ''));
    $year = trim((string)($_GET['year'] ?? ''));
    if ($examName === '') {
        Response::validationError(['exam_name is required']);
    }

    // Exams of that name this student actually has results in (newest first); optional year filter
    $exams = Database::fetchAll(
        "SELECT e.id, e.year, e.class, e.exam_name,
                COUNT(er.id) AS total_rows, SUM(er.status = 'published') AS published_rows
         FROM exams e
         JOIN exam_results er ON er.exam_id = e.id AND er.student_id = ?
         WHERE e.exam_name = ? AND (? = '' OR e.year = ?)
         GROUP BY e.id
         ORDER BY e.year DESC, e.id DESC",
        [$student['id'], $examName, $year, $year]
    );
    if (!$exams) {
        Response::notFound("No result found for {$examName}" . ($year ? " {$year}" : '') . '.');
    }
    $exam = $exams[0];
    if ((int)$exam['published_rows'] === 0 || (int)$exam['published_rows'] < (int)$exam['total_rows']) {
        Response::notFound("The {$examName} {$exam['year']} result has not been published yet.");
    }

    // Session 100: result + marksheet held while the admin's due rule says so
    $dues = duesStatusFor([$student], (int)$exam['year'], (string)$exam['exam_name']);
    if (duesHeld($dues[(int)$student['id']] ?? null)) {
        Response::error(duesHoldMessage($dues[(int)$student['id']]), 403);
    }

    // Whole class once (for merit position), then this student
    $all = transcriptBuild($exam);
    $merit = transcriptMeritPositions($all);
    $mine = null;
    foreach ($all as $t) {
        if ((string)$t['student_id'] === (string)$student['student_id']) { $mine = $t; break; }
    }
    if (!$mine) {
        // Student has since changed class: build just this student, ignoring class
        $one = transcriptBuild($exam, (string)$student['student_id'], '', true);
        $mine = $one[0] ?? null;
    }
    if (!$mine || empty($mine['subjects'])) {
        Response::notFound('No result found.');
    }

    $failSubjects = [];
    foreach ($mine['subjects'] as $s) {
        if (empty($s['is_optional']) && strtoupper((string)$s['grade']) === 'F') $failSubjects[] = $s['subject'];
    }
    $passed = strtoupper((string)$mine['overall_grade']) !== 'F';

    Response::success([
        'student_id' => $mine['student_id'],
        'name' => $mine['name'],
        'class' => $mine['class'],
        'group' => $mine['group'],
        'exam_name' => $exam['exam_name'],
        'year' => $exam['year'],
        'status' => $passed ? 'Passed' : 'Failed',
        'final_gpa' => (float)$mine['overall_gpa'],
        'grade' => $mine['overall_grade'],
        'gpa_without_optional' => (float)$mine['gpa_without_optional'],
        'optional_gp_above_2' => (float)$mine['optional_gp_above_2'],
        'total_marks' => transcriptTotalMarks($mine),
        'position' => $merit[(string)$mine['student_id']]['position'] ?? null,
        'fail_subjects' => $failSubjects,
        'subjects' => $mine['subjects'],
    ]);
});
