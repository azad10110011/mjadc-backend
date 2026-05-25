<?php

// POST /api/admin/transcript — Generate student transcript
$router->post('/api/admin/transcript', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);

    $data = json_decode(file_get_contents('php://input'), true);
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $year = $data['year'] ?? '';
    $studentId = $data['student_id'] ?? '';
    $group = $data['group'] ?? '';

    if (!$class || !$examName || !$year) {
        Response::validationError(['class, exam_name, year are required']);
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }

    // Build student query
    $where = "s.class = ?";
    $params = [$class];

    if ($studentId) {
        $where .= " AND s.student_id = ?";
        $params[] = $studentId;
    }
    if ($group) {
        $where .= " AND s.student_group = ?";
        $params[] = $group;
    }

    $students = Database::fetchAll(
        "SELECT s.id, s.student_id, s.name, s.class, s.student_group,
                s.compulsory_subjects, s.selective_subjects
         FROM students s
         WHERE $where
         ORDER BY s.student_id",
        $params
    );

    if (empty($students)) {
        Response::notFound('No students found');
    }

    $studentIds = array_map(fn($s) => $s['id'], $students);
    $placeholders = implode(',', array_fill(0, count($studentIds), '?'));

    $results = Database::fetchAll(
        "SELECT er.*, s.student_id as s_roll, s.name as s_name, s.student_group,
                e.exam_name, e.year, e.class
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE er.student_id IN ($placeholders) AND er.exam_id = ?
         ORDER BY s.student_id, er.subject",
        array_merge($studentIds, [$exam['id']])
    );

    // Group results by student
    $transcripts = [];
    $studentMap = [];
    foreach ($students as $s) {
        $studentMap[$s['id']] = $s;
    }

    foreach ($results as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($transcripts[$sid])) {
            $stu = $studentMap[$sid] ?? [];
            $transcripts[$sid] = [
                'student_id' => $r['s_roll'],
                'name' => $r['s_name'],
                'class' => $r['class'],
                'group' => $r['student_group'],
                'exam_name' => $r['exam_name'],
                'year' => $r['year'],
                'subjects' => [],
            ];
        }

        $partsData = $r['parts_data'] ? json_decode($r['parts_data'], true) : [];
        $absentIn = $r['absent_in'] ? json_decode($r['absent_in'], true) : [];

        $transcripts[$sid]['subjects'][] = [
            'subject' => $r['subject'],
            'parts_data' => $partsData,
            'absent_in' => $absentIn,
            'total' => (float)$r['total'],
            'grade' => $r['grade'],
            'gpa' => (float)$r['gpa'],
            'status' => $r['status'],
        ];
    }

    // Calculate overall GPA for each student
    foreach ($transcripts as &$t) {
        $totalPoints = 0;
        $subjectCount = 0;
        $hasFail = false;
        foreach ($t['subjects'] as $sub) {
            if ($sub['grade'] !== 'Absent' && $sub['grade'] !== 'F') {
                $totalPoints += $sub['gpa'];
                $subjectCount++;
            } else {
                $hasFail = true;
            }
        }
        $t['total_subjects'] = count($t['subjects']);
        if ($hasFail) {
            $t['overall_grade'] = 'F';
            $t['overall_gpa'] = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;
        } elseif ($subjectCount > 0) {
            $gpa = round($totalPoints / $subjectCount, 2);
            $t['overall_gpa'] = $gpa;
            // Map GPA to letter grade
            if ($gpa >= 5) $t['overall_grade'] = 'A+';
            elseif ($gpa >= 4) $t['overall_grade'] = 'A';
            elseif ($gpa >= 3.5) $t['overall_grade'] = 'A-';
            elseif ($gpa >= 3) $t['overall_grade'] = 'B';
            elseif ($gpa >= 2) $t['overall_grade'] = 'C';
            elseif ($gpa >= 1) $t['overall_grade'] = 'D';
            else $t['overall_grade'] = 'F';
        } else {
            $t['overall_grade'] = 'N/A';
            $t['overall_gpa'] = 0;
        }
        unset($t);
    }

    Response::success(array_values($transcripts));
});
