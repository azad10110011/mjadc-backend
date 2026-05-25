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
        "SELECT s.id, s.student_id, s.name, s.father_name, s.mother_name,
                s.date_of_birth, s.registration_no, s.class, s.student_group,
                s.academic_session, s.student_type, s.optional_subject,
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
        "SELECT er.*, s.student_id as s_roll, s.name as s_name, s.father_name,
                s.mother_name, s.date_of_birth, s.registration_no,
                s.student_group, s.academic_session, s.student_type,
                s.optional_subject,
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

    $publishedAt = null;
    foreach ($results as $r) {
        if (!$publishedAt && !empty($r['published_at'])) {
            $publishedAt = $r['published_at'];
        }
    }

    foreach ($results as $r) {
        $sid = (int)$r['student_id'];
        if (!isset($transcripts[$sid])) {
            $stu = $studentMap[$sid] ?? [];
            $transcripts[$sid] = [
                'student_id' => $r['s_roll'],
                'name' => $r['s_name'],
                'father_name' => $r['father_name'] ?? '',
                'mother_name' => $r['mother_name'] ?? '',
                'date_of_birth' => $r['date_of_birth'] ?? '',
                'registration_no' => $r['registration_no'] ?? '',
                'class' => $r['class'],
                'group' => $r['student_group'],
                'exam_name' => $r['exam_name'],
                'year' => $r['year'],
                'academic_session' => $r['academic_session'] ?? '',
                'student_type' => $r['student_type'] ?? '',
                'optional_subject' => $r['optional_subject'] ?? '',
                'published_at' => $publishedAt,
                'subjects' => [],
            ];
        }

        $transcripts[$sid]['subjects'][] = [
            'subject' => $r['subject'],
            'total' => (float)$r['total'],
            'grade' => $r['grade'],
            'gpa' => (float)$r['gpa'],
            'status' => $r['status'],
        ];
    }

    // Calculate GPA with optional subject logic
    foreach ($transcripts as &$t) {
        $optionalSubj = $t['optional_subject'];
        $mainSubjects = [];
        $optionalSubjectData = null;

        foreach ($t['subjects'] as $sub) {
            if ($optionalSubj && strcasecmp($sub['subject'], $optionalSubj) === 0) {
                $optionalSubjectData = $sub;
            } else {
                $mainSubjects[] = $sub;
            }
        }

        // If optional subject not found in results, treat all as main
        if (!$optionalSubjectData) {
            $mainSubjects = $t['subjects'];
        }

        $totalMainPoints = 0;
        $mainCount = 0;
        $hasFail = false;

        foreach ($mainSubjects as $sub) {
            if ($sub['grade'] !== 'Absent' && $sub['grade'] !== 'F') {
                $totalMainPoints += $sub['gpa'];
                $mainCount++;
            } else {
                $hasFail = true;
            }
        }

        $t['total_subjects'] = count($mainSubjects);

        // GPA without optional (capped at 5.00)
        if ($hasFail) {
            $t['gpa_without_optional'] = 0;
            $t['overall_grade'] = 'F';
        } elseif ($mainCount > 0) {
            $t['gpa_without_optional'] = min(round($totalMainPoints / $mainCount, 2), 5.00);
        } else {
            $t['gpa_without_optional'] = 0;
        }

        // Optional subject GP Above 2
        $gpAbove2 = 0;
        if ($optionalSubjectData && $optionalSubjectData['grade'] !== 'Absent' && $optionalSubjectData['grade'] !== 'F') {
            $gpAbove2 = max(0, $optionalSubjectData['gpa'] - 2.00);
        }
        $t['optional_gp_above_2'] = round($gpAbove2, 2);

        // Final GPA with optional (capped at 5.00)
        $totalAllPoints = $totalMainPoints + $gpAbove2;
        if ($hasFail) {
            $t['overall_gpa'] = 0;
            $t['overall_grade'] = 'F';
        } elseif ($mainCount > 0) {
            $gpa = min(round($totalAllPoints / $mainCount, 2), 5.00);
            $t['overall_gpa'] = $gpa;
            if ($gpa >= 5) $t['overall_grade'] = 'A+';
            elseif ($gpa >= 4) $t['overall_grade'] = 'A';
            elseif ($gpa >= 3.5) $t['overall_grade'] = 'A-';
            elseif ($gpa >= 3) $t['overall_grade'] = 'B';
            elseif ($gpa >= 2) $t['overall_grade'] = 'C';
            elseif ($gpa >= 1) $t['overall_grade'] = 'D';
            else $t['overall_grade'] = 'F';
        } else {
            $t['overall_gpa'] = 0;
            $t['overall_grade'] = 'N/A';
        }

        unset($t);
    }

    Response::success(array_values($transcripts));
});
