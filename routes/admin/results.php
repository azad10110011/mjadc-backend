<?php

// GET /api/admin/results?exam_name=&class=&year=
$router->get('/api/admin/results', function () {
    Auth::requireRole('admin');

    $where = [];
    $params = [];
    foreach (['exam_name', 'class', 'year', 'subject'] as $f) {
        if (!empty($_GET[$f])) {
            $col = $f === 'exam_name' || $f === 'class' || $f === 'year' ? "e.{$f}" : "er.{$f}";
            $where[] = "{$col} = ?";
            $params[] = $_GET[$f];
        }
    }
    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $results = Database::fetchAll(
        "SELECT er.*, s.student_id, s.name as student_name, e.exam_name, e.class, e.year
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         {$whereClause}
         ORDER BY e.year DESC, e.exam_name, s.student_id",
        $params
    );
    Response::success($results);
});

// DELETE /api/admin/results/{id}
$router->delete('/api/admin/results/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('exam_results', 'id = ?', [$params['id']]);
    Response::success(null, 'Result deleted');
});

// GET /api/admin/results/upload-data?year=&class=&exam_name=&subject=
$router->get('/api/admin/results/upload-data', function () {
    Auth::requireRole('admin');

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$year || !$class || !$examName || !$subject) {
        Response::validationError(['year, class, exam_name, subject are required']);
    }

    // Get or create exam
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $examId = $exam ? $exam['id'] : null;

    // Get all students in the class with their existing marks for this exam+subject
    $students = Database::fetchAll(
        "SELECT s.id, s.student_id, s.name,
                er.mcq, er.cq, er.practical, er.total, er.grade, er.gpa, er.status,
                er.id as result_id
         FROM students s
         LEFT JOIN exam_results er ON er.student_id = s.id
             AND er.exam_id = ? AND er.subject = ?
         WHERE s.class = ?
         ORDER BY s.student_id",
        [$examId, $subject, $class]
    );

    Response::success($students);
});

// POST /api/admin/results/upload
$router->post('/api/admin/results/upload', function () {
    $user = Auth::requireRole('admin');

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
    $processed = 0;

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
        $processed++;
    }

    Response::created(['processed' => $processed], "{$processed} results saved");
});

// DELETE /api/admin/results/bulk?exam_name=&class=&year=&subject=
$router->delete('/api/admin/results/bulk', function () {
    Auth::requireRole('admin');

    $examName = $_GET['exam_name'] ?? '';
    $class = $_GET['class'] ?? '';
    $year = $_GET['year'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$examName || !$class || !$year || !$subject) {
        Response::validationError(['exam_name, class, year, subject are required']);
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }

    $deleted = Database::delete(
        'exam_results',
        'exam_id = ? AND subject = ?',
        [$exam['id'], $subject]
    );

    Response::success(['deleted' => $deleted], "{$deleted} results deleted");
});
