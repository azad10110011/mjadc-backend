<?php

// GET /api/student/attendance?subject=&from=&to=   (Session 79)
// The logged-in student's attendance report: overall + per subject + day-by-day records.
// Every save stores one row per listed student, so "classes held" for a student = their rows.

$router->get('/api/student/attendance', function () {
    $user = Auth::requireRole('student');
    $student = Database::fetch("SELECT id, student_id, name, class, student_group FROM students WHERE user_id = ?", [$user['id']]);
    if (!$student) {
        Response::notFound('Student profile not found');
    }

    $where = 'ta.student_id = ?';
    $params = [$student['id']];
    $subject = trim((string)($_GET['subject'] ?? ''));
    $from = trim((string)($_GET['from'] ?? ''));
    $to = trim((string)($_GET['to'] ?? ''));
    foreach (['from' => $from, 'to' => $to] as $k => $v) {
        if ($v !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            Response::validationError(["$k must be YYYY-MM-DD"]);
        }
    }
    if ($from !== '') { $where .= ' AND ta.`date` >= ?'; $params[] = $from; }
    if ($to !== '') { $where .= ' AND ta.`date` <= ?'; $params[] = $to; }

    // per subject (always across the date range, ignoring the subject filter, so the table stays complete)
    $subjects = Database::fetchAll(
        "SELECT ta.subject, COUNT(*) AS total, SUM(ta.status = 'present') AS present,
                GROUP_CONCAT(DISTINCT t.name ORDER BY t.name SEPARATOR ', ') AS teachers,
                MIN(ta.`date`) AS first_date, MAX(ta.`date`) AS last_date
         FROM teacher_attendance ta
         LEFT JOIN teachers t ON t.id = ta.teacher_id
         WHERE $where
         GROUP BY ta.subject
         ORDER BY ta.subject",
        $params
    );
    $total = 0; $present = 0;
    foreach ($subjects as &$s) {
        $s['total'] = (int)$s['total'];
        $s['present'] = (int)$s['present'];
        $s['absent'] = $s['total'] - $s['present'];
        $s['percentage'] = $s['total'] > 0 ? round($s['present'] * 100 / $s['total'], 1) : 0;
        $total += $s['total'];
        $present += $s['present'];
    }
    unset($s);

    $recWhere = $where;
    $recParams = $params;
    if ($subject !== '') { $recWhere .= ' AND ta.subject = ?'; $recParams[] = $subject; }
    $records = Database::fetchAll(
        "SELECT ta.`date`, ta.subject, ta.status, t.name AS teacher
         FROM teacher_attendance ta
         LEFT JOIN teachers t ON t.id = ta.teacher_id
         WHERE $recWhere
         ORDER BY ta.`date` DESC, ta.subject
         LIMIT 2000",
        $recParams
    );

    Response::success([
        'student' => $student,
        'summary' => [
            'total' => $total,
            'present' => $present,
            'absent' => $total - $present,
            'percentage' => $total > 0 ? round($present * 100 / $total, 1) : 0,
        ],
        'subjects' => $subjects,
        'records' => $records,
    ]);
});
