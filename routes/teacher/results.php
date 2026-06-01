<?php

// GET /api/teacher/profile – returns teacher info including group
$router->get('/api/teacher/profile', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);
    $teacher = Database::fetch(
        "SELECT t.*, u.email, u.date_of_birth 
         FROM teachers t 
         JOIN users u ON t.user_id = u.id 
         WHERE t.user_id = ?",
        [$user['id']]
    );
    if (!$teacher) {
        Response::success(array_merge($user, ['group' => null, 'result_subjects' => []]));
        return;
    }
    $teacher['roles'] = $user['roles'];
    Response::success($teacher);
});

// GET /api/teacher/subjects – returns subject names filtered by teacher's group
$router->get('/api/teacher/subjects', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $teacher = Database::fetch("SELECT `group` FROM teachers WHERE user_id = ?", [$user['id']]);
    $group = $teacher ? $teacher['group'] : null;

    if ($group && $group !== 'Common') {
        $names = array_unique(array_merge(
            array_column(Database::fetchAll(
                "SELECT DISTINCT name FROM subjects WHERE (`group` = ? OR `group` = 'Common') AND type IN ('public','both')",
                [$group]
            ), 'name'),
            array_column(Database::fetchAll(
                "SELECT DISTINCT sp.name FROM subject_papers sp JOIN subjects s ON sp.parent_id = s.id WHERE (s.`group` = ? OR s.`group` = 'Common') AND s.type IN ('result','both')",
                [$group]
            ), 'name')
        ));
    } else {
        // Common group or no group set: show all
        $names = array_unique(array_merge(
            array_column(Database::fetchAll("SELECT DISTINCT name FROM subjects WHERE type IN ('public','both')"), 'name'),
            array_column(Database::fetchAll("SELECT DISTINCT sp.name FROM subject_papers sp JOIN subjects s ON sp.parent_id = s.id WHERE s.type IN ('result','both')"), 'name')
        ));
    }
    sort($names);
    Response::success(array_values($names));
});

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

    // Check if result is locked
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

    if (in_array($result['status'], ['approved', 'published'])) {
        Response::forbidden('Cannot modify. Result has been approved or published.');
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
    ], 'id = ?', ['id' => $data['result_id']]);

    Response::success(null, 'Result updated');
});

// GET /api/teacher/results?year=&class=&exam_name=&subject=
$router->get('/api/teacher/results', function () {
    $user = Auth::requireRole('teacher');

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    $results = Database::fetchAll(
        "SELECT er.id, s.student_id, s.name, er.mcq, er.cq, er.practical, 
                er.total, er.grade, er.gpa, er.status
         FROM exam_results er
         JOIN students s ON er.student_id = s.id
         JOIN exams e ON er.exam_id = e.id
         WHERE e.year = ? AND e.class = ? AND e.exam_name = ? AND er.subject = ?
         ORDER BY s.student_id",
        [$year, $class, $examName, $subject]
    );

    Response::success($results);
});

function calculateGradeFromTotal(float $total): array
{
    $grades = [
        ['min' => 80, 'grade' => 'A+', 'points' => 5.00],
        ['min' => 70, 'grade' => 'A', 'points' => 4.00],
        ['min' => 60, 'grade' => 'A-', 'points' => 3.50],
        ['min' => 50, 'grade' => 'B', 'points' => 3.00],
        ['min' => 40, 'grade' => 'C', 'points' => 2.00],
        ['min' => 33, 'grade' => 'D', 'points' => 1.00],
        ['min' => 0, 'grade' => 'F', 'points' => 0.00],
    ];

    foreach ($grades as $g) {
        if ($total >= $g['min']) {
            return $g;
        }
    }
    return ['grade' => 'F', 'points' => 0.00];
}
