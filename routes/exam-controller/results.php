<?php

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

    // Get or create exam
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

    $marks = $data['marks'];
    $inserted = 0;

    foreach ($marks as $mark) {
        $student = Database::fetch(
            "SELECT id FROM students WHERE student_id = ? AND class = ?",
            [$mark['student_id'], $data['class']]
        );
        if (!$student) continue;

        $total = ($mark['mcq'] ?? 0) + ($mark['cq'] ?? 0) + ($mark['practical'] ?? 0);
        $grade = calculateGradeFromTotal($total);

        // Upsert
        $existing = Database::fetch(
            "SELECT id FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $data['subject']]
        );

        if ($existing) {
            Database::update('exam_results', [
                'mcq' => $mark['mcq'] ?? 0,
                'cq' => $mark['cq'] ?? 0,
                'practical' => $mark['practical'] ?? 0,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'status' => 'draft',
            ], 'id = ?', ['id' => $existing['id']]);
        } else {
            Database::insert('exam_results', [
                'student_id' => $student['id'],
                'exam_id' => $examId,
                'subject' => $data['subject'],
                'mcq' => $mark['mcq'] ?? 0,
                'cq' => $mark['cq'] ?? 0,
                'practical' => $mark['practical'] ?? 0,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'status' => 'draft',
                'uploaded_by' => $user['id'],
            ]);
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

    Database::update('exam_results', ['status' => 'approved', 'approved_by' => $user['id']], 'id = ?', ['id' => $params['id']]);

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

    $mcq = $data['mcq'] ?? $result['mcq'];
    $cq = $data['cq'] ?? $result['cq'];
    $practical = $data['practical'] ?? $result['practical'];
    $total = $mcq + $cq + $practical;
    $grade = calculateGradeFromTotal($total);

    Database::update('exam_results', [
        'mcq' => $mcq,
        'cq' => $cq,
        'practical' => $practical,
        'grade' => $grade['grade'],
        'gpa' => $grade['points'],
    ], 'id = ?', ['id' => $params['id']]);

    Response::success(null, 'Result updated');
});
