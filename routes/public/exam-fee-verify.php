<?php
// GET /api/public/exam-fee-verify?student_id=X&year=Y&exam=Z
// Public (no auth) endpoint hit when someone scans the QR code on an admit card.
// Returns name/class/group, parents' names, fee status: paid flag, due amount and will-pay-within date.
$router->get('/api/public/exam-fee-verify', function () {
    $studentId = trim($_GET['student_id'] ?? '');
    $year = (int)($_GET['year'] ?? 0);
    $exam = trim($_GET['exam'] ?? '');
    if (!$studentId || !$year || !$exam) {
        Response::error('student_id, year and exam parameters are required', 400);
    }
    $student = Database::fetch(
        "SELECT s.id, s.user_id, s.student_id, s.name, s.father_name, s.mother_name, s.class, s.student_group, s.photo_path
         FROM students s WHERE s.student_id = ? LIMIT 1",
        [$studentId]
    );
    if (!$student) {
        Response::error('Student not found', 404);
    }
    // exam_fees.student_user_id stores the USERS id (not students.id); fees may be
    // keyed by plain 'Pre-Test' or class-suffixed 'Pre-Test (12th)' — try both.
    $examVariants = [$exam];
    $suffix = ' (' . $student['class'] . ')';
    if (substr($exam, -strlen($suffix)) === $suffix) {
        $examVariants[] = substr($exam, 0, -strlen($suffix));
    } else {
        $examVariants[] = $exam . $suffix;
    }
    // total configured fee for this exam (key may be plain or suffixed)
    $total = 0.0;
    $settingKeys = [];
    foreach ($examVariants as $v) {
        $settingKeys[] = 'exam_fee_amount_' . strtolower(str_replace(' ', '_', $v));
    }
    foreach ($settingKeys as $sk) {
        $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1", [$sk]);
        if ($row && (float)$row['setting_value'] > 0) { $total = (float)$row['setting_value']; break; }
    }
    // sum payments + waivers across the variants
    $paid = 0.0;
    $waived = 0.0;
    $lastPaid = null;
    $willPayWithin = null;
    foreach ($examVariants as $variant) {
        $rows = Database::fetchAll(
            "SELECT amount, collected_at FROM exam_fees
             WHERE student_user_id = ? AND year = ? AND exam_name = ?",
            [$student['user_id'], $year, $variant]
        );
        foreach ($rows as $r) {
            $paid += (float)$r['amount'];
            $lastPaid = $r['collected_at'];
        }
        $wRows = Database::fetchAll(
            "SELECT amount FROM exam_fee_waivers
             WHERE student_user_id = ? AND year = ? AND exam_name = ?",
            [$student['user_id'], $year, $variant]
        );
        foreach ($wRows as $r) $waived += (float)$r['amount'];
        $feeRow = Database::fetch(
            "SELECT will_pay_within FROM exam_fees
             WHERE student_user_id = ? AND year = ? AND exam_name = ? AND will_pay_within IS NOT NULL
             ORDER BY id DESC LIMIT 1",
            [$student['user_id'], $year, $variant]
        );
        if ($feeRow) $willPayWithin = $feeRow['will_pay_within'];
    }
    $due = $total > 0 ? max(0, $total - $paid - $waived) : 0.0;
    $fullyPaid = $total > 0 ? ($paid + $waived >= $total) : ($paid > 0);
    Response::success([
        'student_id' => $student['student_id'],
        'name' => $student['name'],
        'father_name' => $student['father_name'],
        'mother_name' => $student['mother_name'],
        'class' => $student['class'],
        'group' => $student['student_group'],
        'exam_name' => $exam,
        'year' => $year,
        'fee_paid' => $fullyPaid,
        'due' => $due,
        'will_pay_within' => $willPayWithin,
        'collected_at' => $lastPaid,
    ]);
});
