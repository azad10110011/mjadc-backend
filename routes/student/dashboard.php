<?php

// GET /api/student/dashboard
$router->get('/api/student/dashboard', function () {
    $user = Auth::requireRole('student');

    $student = Database::fetch("SELECT * FROM students WHERE user_id = ?", [$user['id']]);
    if (!$student) {
        Response::notFound('Student profile not found');
    }

    // Last exam result
    $lastResult = Database::fetch(
        "SELECT e.exam_name, e.class, e.year, AVG(er.gpa) as gpa
         FROM exam_results er
         JOIN exams e ON er.exam_id = e.id
         WHERE er.student_id = ? AND er.status = 'published'
         GROUP BY e.exam_name, e.class, e.year
         ORDER BY e.year DESC, er.created_at DESC
         LIMIT 1",
        [$student['id']]
    );

    // Fee summary
    $feeSummary = Database::fetch(
        "SELECT COALESCE(SUM(amount_due), 0) as total_due, 
                COALESCE(SUM(amount_paid), 0) as total_paid,
                COALESCE(SUM(waiver_amount), 0) as total_waiver
         FROM tuition_fees WHERE student_id = ?",
        [$student['id']]
    );

    // Session 101: features the admin turned off for this student are not sent at all;
    // a result on hold for unpaid fees (Session 100) is not shown here either.
    $showResults = Permissions::has($user, '/p_G9n4s/results');
    $showFees = Permissions::has($user, '/p_G9n4s/pay-fees');
    if ($lastResult && function_exists('duesHeld')) {
        $dues = duesStatusFor([$student], (int)$lastResult['year'], (string)$lastResult['exam_name']);
        if (duesHeld($dues[(int)$student['id']] ?? null)) $lastResult = null;
    }

    Response::success([
        'student' => $student,
        'last_result' => $showResults ? $lastResult : null,
        'total_due' => $showFees ? ($feeSummary['total_due'] ?? 0) : null,
        'total_paid' => $showFees ? ($feeSummary['total_paid'] ?? 0) : null,
        'outstanding' => $showFees ? max(($feeSummary['total_due'] ?? 0) - ($feeSummary['total_paid'] ?? 0) - ($feeSummary['total_waiver'] ?? 0), 0) : null,
        'features' => ['results' => $showResults, 'fees' => $showFees],
    ]);
});
