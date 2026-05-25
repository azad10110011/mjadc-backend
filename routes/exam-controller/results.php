<?php

require_once __DIR__ . '/../../utils/helpers.php';

// POST /api/exam-controller/results/upload
$router->post('/api/exam-controller/results/upload', function () {
    $user = Auth::requireRole('exam_controller');

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
            $total = $mcq + $cq + $practical;
            $grade = calculateGradeFromTotal($total);
            $partsJson = null;
        }

        $absentJson = !empty($absentIn) ? json_encode($absentIn) : null;

        $existing = Database::fetch(
            "SELECT id FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $data['subject']]
        );

        if ($existing) {
            $oldResult = Database::fetch("SELECT * FROM exam_results WHERE id = ?", [$existing['id']]);

            Database::update('exam_results', [
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'parts_data' => $partsJson,
                'total' => $total,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'absent_in' => $absentJson,
                'status' => 'draft',
                'uploaded_by' => $user['id'],
            ], 'id = ?', ['id' => $existing['id']]);

            logResultChange(
                (int)$existing['id'],
                'updated',
                json_encode($oldResult),
                json_encode(['parts_data' => $partsData, 'total' => $total, 'grade' => $grade['grade']]),
                $user['id']
            );
        } else {
            $newId = Database::insert('exam_results', [
                'student_id' => $student['id'],
                'exam_id' => $examId,
                'subject' => $data['subject'],
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'parts_data' => $partsJson,
                'total' => $total,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'absent_in' => $absentJson,
                'status' => 'draft',
                'uploaded_by' => $user['id'],
            ]);

            logResultChange(
                (int)$newId,
                'created',
                null,
                json_encode(['student_id' => $student['id'], 'subject' => $data['subject'], 'parts_data' => $partsData]),
                $user['id']
            );
        }
        $inserted++;
    }

    Response::created(['inserted' => $inserted], "{$inserted} results processed");
});

// GET /api/exam-controller/results/pending
$router->get('/api/exam-controller/results/pending', function () {
    Auth::requireRole('exam_controller');

    $results = Database::fetchAll(
        "SELECT e.exam_name, er.subject, e.class, COUNT(*) as student_count,
                er.status, MIN(er.id) as result_group_id
         FROM exam_results er
         JOIN exams e ON er.exam_id = e.id
         WHERE er.status = 'draft'
         GROUP BY e.exam_name, er.subject, e.class, er.status
         ORDER BY e.exam_name, er.subject"
    );

    Response::success($results);
});

// POST /api/exam-controller/results/approve/{id}
$router->post('/api/exam-controller/results/approve/{id}', function (array $params) {
    $user = Auth::requireRole('exam_controller');

    $result = Database::fetch("SELECT * FROM exam_results WHERE id = ?", [$params['id']]);
    if (!$result) {
        Response::notFound('Result not found');
    }

    $oldStatus = $result['status'];
    Database::update('exam_results', ['status' => 'approved', 'approved_by' => $user['id']], 'id = ?', ['id' => $params['id']]);

    logResultChange(
        (int)$params['id'],
        'approved',
        json_encode(['status' => $oldStatus]),
        json_encode(['status' => 'approved', 'approved_by' => $user['id']]),
        $user['id']
    );

    Response::success(null, 'Result approved');
});

// POST /api/exam-controller/results/approve-batch
$router->post('/api/exam-controller/results/approve-batch', function () {
    $user = Auth::requireRole('exam_controller');
    $data = json_decode(file_get_contents('php://input'), true);

    $examName = $data['exam_name'] ?? '';
    $subject = $data['subject'] ?? '';
    $class = $data['class'] ?? '';

    if (!$examName || !$subject || !$class) {
        Response::validationError(['Missing filter parameters']);
    }

    $results = Database::fetchAll(
        "SELECT er.id FROM exam_results er JOIN exams e ON er.exam_id = e.id
         WHERE e.exam_name = ? AND er.subject = ? AND e.class = ? AND er.status = ?",
        [$examName, $subject, $class, 'draft']
    );

    foreach ($results as $r) {
        logResultChange(
            (int)$r['id'],
            'approved',
            json_encode(['status' => 'draft']),
            json_encode(['status' => 'approved', 'approved_by' => $user['id']]),
            $user['id']
        );
    }

    $updated = Database::update(
        'exam_results er JOIN exams e ON er.exam_id = e.id',
        ['er.status' => 'approved', 'er.approved_by' => $user['id']],
        'e.exam_name = ? AND er.subject = ? AND e.class = ? AND er.status = ?',
        ['exam_name' => $examName, 'subject' => $subject, 'class' => $class, 'status' => 'draft']
    );

    Response::success(['updated' => $updated], "{$updated} results approved");
});

// PUT /api/exam-controller/results/{id}
$router->put('/api/exam-controller/results/{id}', function (array $params) {
    $user = Auth::requireRole('exam_controller');

    $data = json_decode(file_get_contents('php://input'), true);
    $result = Database::fetch("SELECT * FROM exam_results WHERE id = ?", [$params['id']]);
    if (!$result) {
        Response::notFound('Result not found');
    }

    if ($result['status'] === 'published') {
        Response::forbidden('Cannot edit published results');
    }

    $partConfigs = getSubjectParts($result['subject']);
    $absentIn = $data['absent_in'] ?? [];
    $partsData = $data['parts_data'] ?? [];

    if (!empty($partsData)) {
        $grade = calculateGradeFromParts($partsData, $partConfigs, $absentIn);
        $partsJson = json_encode($partsData);
        $total = array_sum($partsData);
    } else {
        $partsJson = null;
        $mcq = $data['mcq'] ?? $result['mcq'];
        $cq = $data['cq'] ?? $result['cq'];
        $practical = $data['practical'] ?? $result['practical'];
        $total = $mcq + $cq + $practical;
        $grade = calculateGradeFromTotal($total);
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
    ], 'id = ?', ['id' => $params['id']]);

    logResultChange(
        (int)$params['id'],
        'updated',
        json_encode($result),
        json_encode(['parts_data' => $partsData, 'total' => $total, 'grade' => $grade['grade']]),
        $user['id']
    );

    Response::success(null, 'Result updated');
});
