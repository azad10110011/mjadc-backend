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

    Response::success([
        'student' => $student,
        'last_result' => $lastResult,
        'total_due' => $feeSummary['total_due'] ?? 0,
        'total_paid' => $feeSummary['total_paid'] ?? 0,
        'outstanding' => max(($feeSummary['total_due'] ?? 0) - ($feeSummary['total_paid'] ?? 0) - ($feeSummary['total_waiver'] ?? 0), 0),
    ]);
});
