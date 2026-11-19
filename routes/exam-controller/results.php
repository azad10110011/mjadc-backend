<?php

// GET /api/exam-controller/subjects – returns exam paper names only (no public subject names)
$router->get('/api/exam-controller/subjects', function () {
    Auth::requireRole('exam_controller');

    $names = array_column(Database::fetchAll(
        "SELECT DISTINCT sp.name FROM subject_papers sp ORDER BY sp.name"
    ), 'name');
    sort($names);
    Response::success(array_values($names));
});

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

    // Get part configs to know which fields to read
    $partConfigs = Database::fetchAll(
        "SELECT part_name FROM subject_parts WHERE subject = ? ORDER BY sort_order",
        [$data['subject']]
    );
    if (empty($partConfigs)) {
        // Default parts if none configured
        $partConfigs = [
            ['part_name' => 'mcq'],
            ['part_name' => 'cq'],
            ['part_name' => 'practical'],
        ];
    }

    $markRule = duesSettings()['mark_entry']; // Session 100: admin fee rule for mark entry
    foreach ($marks as $mark) {
        $student = Database::fetch(
            "SELECT id, user_id, class FROM students WHERE student_id = ? AND class = ?",
            [$mark['student_id'], $data['class']]
        );
        if (!$student) continue;
        if ($markRule !== 'all') {
            $st = duesStatusFor([$student], (int)$data['year'], (string)$data['exam_name']);
            if (!duesMatches($st[(int)$student['id']] ?? null, $markRule)) continue;
        }

        $partsData = $mark['parts_data'] ?? [];
        $lowerParts = [];
        foreach ($partsData as $k => $v) $lowerParts[strtolower($k)] = $v;
        $mcq = (float) ($lowerParts['mcq'] ?? $mark['mcq'] ?? 0);
        $cq = (float) ($lowerParts['cq'] ?? $mark['cq'] ?? 0);
        $practical = (float) ($lowerParts['practical'] ?? $mark['practical'] ?? 0);
        $total = $mcq + $cq + $practical;
        $grade = calculateGradeFromTotal($total);

        // Upsert
        $existing = Database::fetch(
            "SELECT id, status FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $data['subject']]
        );

        if ($existing) {
            // Session 113: don't overwrite rows past the EC's stage; keep the current status.
            if (!resultCanEditMarks($user, (string)$existing['status'])) continue;
            Database::update('exam_results', [
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'total' => $total,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
            ], 'id = ?', ['id' => $existing['id']]);
        } else {
            Database::insert('exam_results', [
                'student_id' => $student['id'],
                'exam_id' => $examId,
                'subject' => $data['subject'],
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'total' => $total,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'status' => 'submitted', // EC-entered marks go straight into the EC review queue
                'uploaded_by' => $user['id'],
            ]);
        }
        $inserted++;
    }

    Response::created(['inserted' => $inserted], "{$inserted} results processed");
});

// GET /api/exam-controller/results/detail?exam_name=&subject=&class=
$router->get('/api/exam-controller/results/detail', function () {
    Auth::requireRole('exam_controller');

    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';
    $class = $_GET['class'] ?? '';

    if (!$examName || !$subject || !$class) {
        Response::validationError(['exam_name, subject, and class are required']);
    }

    $results = Database::fetchAll(
        "SELECT er.id, s.student_id, s.name, er.mcq, er.cq, er.practical,
                er.total, er.grade, er.gpa, er.status
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE e.exam_name = ? AND er.subject = ? AND e.class = ?
         ORDER BY s.student_id",
        [$examName, $subject, $class]
    );

    Response::success($results);
});

// GET /api/exam-controller/results/pending
$router->get('/api/exam-controller/results/pending', function () {
    Auth::requireRole('exam_controller');

    $results = Database::fetchAll(
        "SELECT e.exam_name, er.subject, e.class, COUNT(*) as student_count,
                er.status, MIN(er.id) as result_group_id
         FROM exam_results er
         JOIN exams e ON er.exam_id = e.id
         WHERE er.status = 'submitted'
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
    // Session 113: only a submitted row can be approved (never re-approve or downgrade a published one).
    if ($result['status'] !== 'submitted') {
        Response::error('Only submitted results can be approved (this one is ' . $result['status'] . ').', 409);
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
        ['exam_name' => $examName, 'subject' => $subject, 'class' => $class, 'status' => 'submitted'] // Session 113: approve submitted rows
    );

    Response::success(['updated' => $updated], "{$updated} results approved");
});

// POST /api/exam-controller/results/back-to-teacher  {exam_name, subject, class}   (Session 77)
// The Approve Result page has always called this, but it was missing (404 locally and on live).
// Year-safe: only the NEWEST exam with that name + class is touched (never older years), and only
// rows that already left draft (submitted/approved) go back to draft so the teacher can edit them.
$router->post('/api/exam-controller/results/back-to-teacher', function () {
    Auth::requireRole('exam_controller');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];

    $examName = trim((string)($data['exam_name'] ?? ''));
    $subject = trim((string)($data['subject'] ?? ''));
    $class = trim((string)($data['class'] ?? ''));
    if ($examName === '' || $subject === '' || $class === '') {
        Response::validationError(['Missing filter parameters']);
    }

    $exam = Database::fetch(
        "SELECT e.id FROM exams e
         WHERE e.exam_name = ? AND e.class = ?
           AND EXISTS (SELECT 1 FROM exam_results er WHERE er.exam_id = e.id AND er.subject = ?)
         ORDER BY e.year DESC, e.id DESC LIMIT 1",
        [$examName, $class, $subject]
    );
    if (!$exam) {
        Response::notFound('No results found for this exam / subject / class');
    }

    $stmt = Database::query(
        "UPDATE exam_results SET status = 'draft', approved_by = NULL
         WHERE exam_id = ? AND subject = ? AND status IN ('submitted', 'approved')",
        [$exam['id'], $subject]
    );
    $updated = $stmt->rowCount();

    Response::success(['updated' => $updated],
        $updated > 0 ? "{$updated} results returned to teacher" : 'These results are already with the teacher (draft)');
});

// PUT /api/exam-controller/results/{id}
$router->put('/api/exam-controller/results/{id}', function (array $params) {
    $user = Auth::requireRole('exam_controller');

    $data = json_decode(file_get_contents('php://input'), true);
    $result = Database::fetch("SELECT * FROM exam_results WHERE id = ?", [$params['id']]);
    if (!$result) {
        Response::notFound('Result not found');
    }

    // Session 113: stage lock (EC may edit draft/submitted; approved/published are admin-only).
    if (!resultCanEditMarks($user, (string)$result['status'])) {
        Response::forbidden(resultLockReason((string)$result['status']));
    }

    // Recompute with the shared part-wise rule so EC edits match teacher/admin results.
    $partsData = is_array($data['parts_data'] ?? null) ? $data['parts_data'] : [];
    $absentIn = is_array($data['absent_in'] ?? null) ? $data['absent_in'] : (is_array(json_decode((string)$result['absent_in'], true)) ? json_decode((string)$result['absent_in'], true) : []);
    if (empty($partsData)) {
        // fall back to the row's own part data, overlaying any plain mcq/cq/practical sent
        $partsData = is_array(json_decode((string)$result['parts_data'], true)) ? json_decode((string)$result['parts_data'], true) : [];
        foreach (['mcq', 'cq', 'practical'] as $k) if (isset($data[$k])) $partsData[$k] = $data[$k];
        if (empty($partsData)) $partsData = ['mcq' => $result['mcq'], 'cq' => $result['cq'], 'practical' => $result['practical']];
    }
    [$cq, $mcq, $practical, $total, $grade, $gpa, $partsJson, $absentJson] = resultComputeRow((string)$result['subject'], $partsData, $absentIn);

    Database::query(
        "UPDATE exam_results SET cq = ?, mcq = ?, practical = ?, total = ?, grade = ?, gpa = ?, parts_data = ?, absent_in = ? WHERE id = ?",
        [$cq, $mcq, $practical, $total, $grade, $gpa, $partsJson, $absentJson, $params['id']]
    );

    Response::success(['grade' => $grade, 'gpa' => $gpa, 'total' => $total], 'Result updated');
});
