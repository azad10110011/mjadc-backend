<?php
// GET /api/public/exam-fee-verify?student_id=X&year=Y&exam=Z
// Public (no auth) endpoint hit when someone scans the QR code on an admit card.
// Returns only minimal info: student name/class + whether the exam fee is paid.

$router->get('/api/public/exam-fee-verify', function () {
    $studentId = trim($_GET['student_id'] ?? '');
    $year = (int)($_GET['year'] ?? 0);
    $exam = trim($_GET['exam'] ?? '');
    if (!$studentId || !$year || !$exam) {
        Response::error('student_id, year and exam parameters are required', 400);
    }

    $student = Database::fetch(
        "SELECT s.id, s.student_id, s.name, s.class, s.student_group, s.photo_path
         FROM students s WHERE s.student_id = ? LIMIT 1",
        [$studentId]
    );
    if (!$student) {
        Response::error('Student not found', 404);
    }

    $fee = Database::fetch(
        "SELECT amount, payment_method, collected_at FROM exam_fees
         WHERE student_user_id = ? AND year = ? AND exam_name = ? LIMIT 1",
        [$student['id'], $year, $exam]
    );

    Response::success([
        'student_id' => $student['student_id'],
        'name' => $student['name'],
        'class' => $student['class'],
        'group' => $student['student_group'],
        'exam_name' => $exam,
        'year' => $year,
        'fee_paid' => (bool)$fee,
        'amount' => $fee ? (float)$fee['amount'] : null,
        'payment_method' => $fee['payment_method'] ?? null,
        'collected_at' => $fee['collected_at'] ?? null,
    ]);
});
