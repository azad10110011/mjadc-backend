<?php

// Teacher attendance + admin attendance view (Session 77).
// Restored from the June live snapshot (mjadc.ac.bd/mjadc-api) — the Teacher → Attendance and
// Admin → Attendance pages call these, but the file was missing from this backend (404 on live too).
// Fixes vs snapshot:
//   * compulsory subjects listed by their real names ('Information and Communication Technology',
//     not 'ICT') — before, an ICT teacher got an empty student list;
//   * helpers renamed + function_exists-guarded; GET /api/teacher/subjects added (also missing).

if (!function_exists('attendanceTeacherId')) {
function attendanceTeacherId($user) {
    $teacherId = $_GET['teacher_id'] ?? null;
    if ($teacherId && in_array('admin', $user['roles'])) {
        return (int) $teacherId;
    }
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$user['id']]);
    return $teacher ? (int) $teacher['id'] : null;
}}

if (!function_exists('attendancePostTeacherId')) {
function attendancePostTeacherId($user, $data) {
    $teacherId = $data['teacher_id'] ?? null;
    if ($teacherId && in_array('admin', $user['roles'])) {
        return (int) $teacherId;
    }
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$user['id']]);
    return $teacher ? (int) $teacher['id'] : null;
}}

// GET /api/teacher/subjects – result subjects assigned to the logged-in teacher
$router->get('/api/teacher/subjects', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$user['id']]);
    if (!$teacher) {
        Response::success([]);
    }
    $subjects = array_column(Database::fetchAll(
        "SELECT subject FROM teacher_subjects WHERE teacher_id = ? AND type = 'result' ORDER BY subject",
        [$teacher['id']]
    ), 'subject');
    Response::success(array_values($subjects));
});

// GET /api/admin/attendance/teachers – list teachers with their subjects (admin only)
$router->get('/api/admin/attendance/teachers', function () {
    Auth::requireRole('admin');

    $teachers = Database::fetchAll(
        "SELECT id, name FROM teachers ORDER BY name"
    );

    foreach ($teachers as &$t) {
        $subs = Database::fetchAll(
            "SELECT subject FROM teacher_subjects WHERE teacher_id = ? AND type = 'result' ORDER BY subject",
            [$t['id']]
        );
        $t['subjects'] = array_values(array_column($subs, 'subject'));
    }

    Response::success($teachers);
});

// GET /api/teacher/attendance/students – load students for a subject and class
$router->get('/api/teacher/attendance/students', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);

    $class = $_GET['class'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$class || !$subject) {
        Response::validationError(['class and subject are required']);
    }

    $subjInfo = Database::fetch(
        "SELECT s.id, s.name, s.`group` FROM subjects s
         JOIN subject_papers sp ON sp.parent_id = s.id WHERE sp.name = ?",
        [$subject]
    );
    if (!$subjInfo) {
        $subjInfo = Database::fetch(
            "SELECT id, name, `group` FROM subjects WHERE name = ?",
            [$subject]
        );
    }

    $compulsorySubjects = ['Bangla', 'English', 'Information and Communications Technology', 'ICT', 'Information and Communication Technology'];

    if ($subjInfo && !in_array($subjInfo['name'], $compulsorySubjects)) {
        $subjName = $subjInfo['name'];
        $students = Database::fetchAll(
            "SELECT id, student_id, name FROM students
             WHERE class = ?
               AND (JSON_CONTAINS(selective_subjects, ?) OR optional_subject = ?)
             ORDER BY student_id",
            [$class, json_encode($subjName), $subjName]
        );
    } else {
        $students = Database::fetchAll(
            "SELECT id, student_id, name FROM students WHERE class = ? ORDER BY student_id",
            [$class]
        );
    }

    Response::success($students);
});

// GET /api/teacher/attendance/records – get existing attendance for a subject, class, and date
$router->get('/api/teacher/attendance/records', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);

    $teacherId = attendanceTeacherId($user);
    if (!$teacherId) {
        Response::success([]);
        return;
    }

    $subject = $_GET['subject'] ?? '';
    $class = $_GET['class'] ?? '';
    $date = $_GET['date'] ?? '';

    if (!$subject || !$class || !$date) {
        Response::validationError(['subject, class, and date are required']);
    }

    $records = Database::fetchAll(
        "SELECT student_id, status FROM teacher_attendance
         WHERE teacher_id = ? AND subject = ? AND class = ? AND date = ?",
        [$teacherId, $subject, $class, $date]
    );

    $result = [];
    foreach ($records as $r) {
        $result[$r['student_id']] = $r['status'];
    }

    Response::success($result);
});

// POST /api/teacher/attendance/save – save or update attendance for a date
$router->post('/api/teacher/attendance/save', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);

    $data = json_decode(file_get_contents('php://input'), true);
    $teacherId = attendancePostTeacherId($user, $data);
    if (!$teacherId) {
        Response::error('Teacher not found', 404);
        return;
    }

    $subject = $data['subject'] ?? '';
    $class = $data['class'] ?? '';
    $date = $data['date'] ?? '';
    $records = $data['records'] ?? [];

    if (!$subject || !$class || !$date || !is_array($records)) {
        Response::validationError(['subject, class, date, and records are required']);
    }
    // Any date may be taken (past days included) — but it must be a real calendar date (Session 79)
    $d = DateTime::createFromFormat('!Y-m-d', (string)$date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        Response::validationError(['date must be a valid date (YYYY-MM-DD)']);
    }

    $saved = 0;

    foreach ($records as $r) {
        $studentId = (int) ($r['student_id'] ?? 0);
        $status = ($r['status'] ?? '') === 'present' ? 'present' : 'absent';

        if ($studentId <= 0) continue;

        Database::query(
            "INSERT INTO teacher_attendance (teacher_id, student_id, subject, class, `date`, `status`)
             VALUES (?, ?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE `status` = VALUES(`status`), updated_at = NOW()",
            [$teacherId, $studentId, $subject, $class, $date, $status]
        );

        $saved++;
    }

    Response::success(['saved' => $saved]);
});

// GET /api/teacher/attendance/stats – get attendance stats per student for a subject
$router->get('/api/teacher/attendance/stats', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);

    $teacherId = attendanceTeacherId($user);
    if (!$teacherId) {
        Response::success([]);
        return;
    }

    $subject = $_GET['subject'] ?? '';
    $class = $_GET['class'] ?? '';

    if (!$subject || !$class) {
        Response::validationError(['subject and class are required']);
    }

    $totalClasses = Database::fetch(
        "SELECT COUNT(DISTINCT date) as total FROM teacher_attendance
         WHERE teacher_id = ? AND subject = ? AND class = ?",
        [$teacherId, $subject, $class]
    );
    $totalDays = (int) ($totalClasses['total'] ?? 0);

    $attendance = Database::fetchAll(
        "SELECT ta.student_id, s.student_id as sid, s.name,
                SUM(CASE WHEN ta.status = 'present' THEN 1 ELSE 0 END) as attended
         FROM teacher_attendance ta
         JOIN students s ON s.id = ta.student_id
         WHERE ta.teacher_id = ? AND ta.subject = ? AND ta.class = ?
         GROUP BY ta.student_id
         ORDER BY s.student_id",
        [$teacherId, $subject, $class]
    );

    $result = [];
    foreach ($attendance as $a) {
        $attended = (int) $a['attended'];
        $percentage = $totalDays > 0 ? round(($attended / $totalDays) * 100, 1) : 0;
        $result[] = [
            'student_id' => $a['sid'],
            'name' => $a['name'],
            'total_classes' => $totalDays,
            'attended' => $attended,
            'percentage' => $percentage,
        ];
    }

    Response::success($result);
});

// GET /api/teacher/attendance/dates?subject=&class=[&teacher_id=]   (Session 79)
// Dates this teacher already took attendance for (newest first) with present/absent counts,
// so a past day can be reopened and corrected.
$router->get('/api/teacher/attendance/dates', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);
    $teacherId = attendanceTeacherId($user);
    if (!$teacherId) {
        Response::success([]);
    }
    $subject = $_GET['subject'] ?? '';
    $class = $_GET['class'] ?? '';
    if (!$subject || !$class) {
        Response::validationError(['subject and class are required']);
    }
    Response::success(Database::fetchAll(
        "SELECT `date`, SUM(status = 'present') AS present, SUM(status = 'absent') AS absent
         FROM teacher_attendance
         WHERE teacher_id = ? AND subject = ? AND class = ?
         GROUP BY `date`
         ORDER BY `date` DESC
         LIMIT 400",
        [$teacherId, $subject, $class]
    ));
});
