<?php

require_once __DIR__ . '/../../utils/helpers.php';

// POST /api/teacher/results/upload
$router->post('/api/teacher/results/upload', function () {
    $user = Auth::requireRole('teacher');

    $data = json_decode(file_get_contents('php://input'), true);

    $validator = validate($data);
    $validator->required('year', 'Year')
        ->required('class', 'Class')
        ->required('exam_name', 'Exam Name')
        ->required('subject', 'Subject')
        ->required('marks', 'Marks');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    if (!canUserAccessSubject($user, $data['subject'])) {
        Response::forbidden('You can only upload marks for your own subject');
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$data['year'], $data['class'], $data['exam_name']]
    );

    if (!$exam) {
        $examId = Database::insert('exams', [
            'year' => $data['year'],
            'class' => $data['class'],
            'exam_name' => $data['exam_name'],
        ]);
    } else {
        $examId = $exam['id'];
    }

    $partConfigs = getSubjectParts($data['subject']);
    $marks = $data['marks'];
    $inserted = 0;

    foreach ($marks as $mark) {
        $student = Database::fetch(
            "SELECT id FROM students WHERE student_id = ? AND class = ?",
            [$mark['student_id'], $data['class']]
        );
        if (!$student) continue;

        $absentIn = $mark['absent_in'] ?? [];
        $partsData = $mark['parts_data'] ?? [];
        $mcq = in_array('mcq', $absentIn) ? 0 : (isset($partsData['mcq']) ? (float)$partsData['mcq'] : ($mark['mcq'] ?? 0));
        $cq = in_array('cq', $absentIn) ? 0 : (isset($partsData['cq']) ? (float)$partsData['cq'] : ($mark['cq'] ?? 0));
        $practical = in_array('practical', $absentIn) ? 0 : (isset($partsData['practical']) ? (float)$partsData['practical'] : ($mark['practical'] ?? 0));

        if (!empty($partsData)) {
            foreach ($partsData as $partName => $partVal) {
                if (in_array($partName, $absentIn)) {
                    $partsData[$partName] = 0;
                } else {
                    $partsData[$partName] = max(0, min((float)$partVal, 999));
                }
            }
            // Clamp each part to its full_mark
            foreach ($partConfigs as $config) {
                $pn = $config['part_name'];
                if (!in_array($pn, $absentIn) && isset($partsData[$pn])) {
                    $partsData[$pn] = min((float)$partsData[$pn], (float)$config['full_mark']);
                }
            }
            $grade = calculateGradeFromParts($partsData, $partConfigs, $absentIn);
            $partsJson = json_encode($partsData);
            $total = array_sum($partsData);
        } else {
            $grade = calculateGradeFromMarks($mcq, $cq, $practical, $absentIn);
            $partsJson = null;
            $total = $mcq + $cq + $practical;
        }

        $absentJson = !empty($absentIn) ? json_encode($absentIn) : null;

        $existing = Database::fetch(
            "SELECT id, mcq, cq, practical, parts_data, grade, gpa, absent_in 
             FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $data['subject']]
        );

        if ($existing) {
            Database::query(
                "UPDATE exam_results SET mcq = ?, cq = ?, practical = ?, parts_data = ?, 
                 total = ?, grade = ?, gpa = ?, absent_in = ?, status = 'draft', uploaded_by = ?
                 WHERE id = ?",
                [$mcq, $cq, $practical, $partsJson, $total,
                 $grade['grade'], $grade['points'], $absentJson, $user['id'], $existing['id']]
            );

            logResultChange(
                (int)$existing['id'],
                'updated',
                json_encode($existing),
                json_encode(['parts_data' => $partsData, 'total' => $total, 'grade' => $grade['grade']]),
                $user['id']
            );
        } else {
            Database::query(
                "INSERT INTO exam_results (student_id, exam_id, subject, mcq, cq, practical, parts_data, 
                 total, grade, gpa, absent_in, status, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft', ?)",
                [$student['id'], $examId, $data['subject'],
                 $mcq, $cq, $practical, $partsJson, $total,
                 $grade['grade'], $grade['points'], $absentJson, $user['id']]
            );
        }
        $inserted++;
    }

    Response::created(['inserted' => $inserted], "{$inserted} results uploaded");
});

// PUT /api/teacher/results/update
$router->put('/api/teacher/results/update', function () {
    $user = Auth::requireRole('teacher');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('result_id', 'Result ID');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $result = Database::fetch(
        "SELECT er.*, e.class, e.exam_name 
         FROM exam_results er 
         JOIN exams e ON er.exam_id = e.id 
         WHERE er.id = ?",
        [$data['result_id']]
    );

    if (!$result) {
        Response::notFound('Result not found');
    }

    if (!canUserAccessSubject($user, $result['subject'])) {
        Response::forbidden('You can only update marks for your own subject');
    }

    if (in_array($result['status'], ['approved', 'published'])) {
        Response::forbidden('Cannot modify. Result has been approved or published.');
    }

    $partConfigs = getSubjectParts($result['subject']);
    $absentIn = $data['absent_in'] ?? [];
    $partsData = $data['parts_data'] ?? [];

    if (!empty($partsData)) {
        $partsData = array_merge(
            json_decode($result['parts_data'] ?? '{}', true) ?: [],
            $partsData
        );
        foreach ($partsData as $partName => $partVal) {
            if (in_array($partName, $absentIn)) {
                $partsData[$partName] = 0;
            }
        }
        // Clamp each part to its full_mark
        foreach ($partConfigs as $config) {
            $pn = $config['part_name'];
            if (!in_array($pn, $absentIn) && isset($partsData[$pn])) {
                $partsData[$pn] = min((float)$partsData[$pn], (float)$config['full_mark']);
            }
        }
        $grade = calculateGradeFromParts($partsData, $partConfigs, $absentIn);
        $partsJson = json_encode($partsData);
        $total = array_sum($partsData);
    } else {
        $partsData = [];
        $partsJson = null;
        $mcq = in_array('mcq', $absentIn) ? 0 : ($data['mcq'] ?? $result['mcq']);
        $cq = in_array('cq', $absentIn) ? 0 : ($data['cq'] ?? $result['cq']);
        $practical = in_array('practical', $absentIn) ? 0 : ($data['practical'] ?? $result['practical']);
        $grade = calculateGradeFromMarks($mcq, $cq, $practical, $absentIn);
        $total = $mcq + $cq + $practical;
    }

    $absentJson = !empty($absentIn) ? json_encode($absentIn) : null;

    Database::update('exam_results', [
        'mcq' => $mcq ?? $result['mcq'],
        'cq' => $cq ?? $result['cq'],
        'practical' => $practical ?? $result['practical'],
        'parts_data' => $partsJson,
        'total' => $total,
        'grade' => $grade['grade'],
        'gpa' => $grade['points'],
        'absent_in' => $absentJson,
    ], 'id = ?', ['id' => $data['result_id']]);

    logResultChange(
        (int)$data['result_id'],
        'updated',
        json_encode($result),
        json_encode(['parts_data' => $partsData, 'total' => $total, 'grade' => $grade['grade']]),
        $user['id']
    );

    Response::success(null, 'Result updated');
});

// GET /api/teacher/results/load-students?year=&class=&exam_name=&subject=
$router->get('/api/teacher/results/load-students', function () {
    $user = Auth::requireRole('teacher');

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$year || !$class || !$examName || !$subject) {
        Response::validationError(['year, class, exam_name, subject are required']);
    }

    if (!canUserAccessSubject($user, $subject)) {
        Response::forbidden('You can only access your own subject');
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $examId = $exam ? $exam['id'] : null;

    $students = Database::fetchAll(
        "SELECT s.id, s.student_id, s.name,
                er.mcq, er.cq, er.practical, er.parts_data, er.total, er.grade, er.gpa, er.status, er.absent_in,
                er.id as result_id
         FROM students s
         LEFT JOIN exam_results er ON er.student_id = s.id
             AND er.exam_id = ? AND er.subject = ?
         WHERE s.class = ?
         ORDER BY s.student_id",
        $examId ? [$examId, $subject, $class] : [0, $subject, $class]
    );

    $partConfigs = getSubjectParts($subject);

    foreach ($students as &$s) {
        $s['absent_in'] = $s['absent_in'] ? json_decode($s['absent_in'], true) : [];
        $s['parts_data'] = $s['parts_data'] ? json_decode($s['parts_data'], true) : null;
        $s['part_configs'] = $partConfigs;
    }

    Response::success(['students' => $students, 'part_configs' => $partConfigs]);
});

// GET /api/teacher/results?year=&class=&exam_name=&subject=
$router->get('/api/teacher/results', function () {
    $user = Auth::requireRole('teacher');

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$year || !$class || !$examName || !$subject) {
        Response::validationError(['year, class, exam_name, subject are required']);
    }

    if (!canUserAccessSubject($user, $subject)) {
        Response::forbidden('You can only access your own subject');
    }

    $results = Database::fetchAll(
        "SELECT er.id, s.student_id, s.name, er.mcq, er.cq, er.practical, er.parts_data,
                er.total, er.grade, er.gpa, er.status, er.absent_in
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE e.year = ? AND e.class = ? AND e.exam_name = ? AND er.subject = ?
         ORDER BY s.student_id",
        [$year, $class, $examName, $subject]
    );

    foreach ($results as &$r) {
        $r['absent_in'] = $r['absent_in'] ? json_decode($r['absent_in'], true) : [];
        $r['parts_data'] = $r['parts_data'] ? json_decode($r['parts_data'], true) : null;
    }

    Response::success($results);
});
