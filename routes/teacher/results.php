<?php

// GET /api/teacher/results/load-students – returns students with part configs for a subject
$router->get('/api/teacher/results/load-students', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$year || !$class || !$examName || !$subject) {
        Response::validationError(['year, class, exam_name, and subject are required']);
    }

    // Get subject part configs
    $partConfigs = Database::fetchAll(
        "SELECT part_name, full_mark, pass_mark, sort_order FROM subject_parts WHERE subject = ? ORDER BY sort_order",
        [$subject]
    );

    if (empty($partConfigs)) {
        // Fallback: use default mcq/cq/practical parts if none configured
        $partConfigs = [
            ['part_name' => 'mcq', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 1],
            ['part_name' => 'cq', 'full_mark' => 50, 'pass_mark' => 17, 'sort_order' => 2],
            ['part_name' => 'practical', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 3],
        ];
    }

    // Get or create exam
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $examId = $exam ? $exam['id'] : null;

    // Get all students in this class
    $students = Database::fetchAll(
        "SELECT id, student_id, name FROM students WHERE class = ? ORDER BY student_id",
        [$class]
    );

    $result = [];
    foreach ($students as $student) {
        $partsData = [];
        $absentIn = [];
        $resultId = null;
        $status = null;

        if ($examId) {
            $existing = Database::fetch(
                "SELECT * FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
                [$student['id'], $examId, $subject]
            );
            if ($existing) {
                $resultId = $existing['id'];
                $status = $existing['status'];
                foreach ($partConfigs as $p) {
                    $col = strtolower($p['part_name']);
                    $val = $existing[$col] ?? 0;
                    $partsData[$p['part_name']] = (float) $val;
                    if ((float) $val === 0.0 && $existing['grade'] === 'Absent') {
                        $absentIn[] = $p['part_name'];
                    }
                }
            }
        }

        $result[] = [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'result_id' => $resultId,
            'status' => $status,
            'parts_data' => $partsData,
            'absent_in' => $absentIn,
        ];
    }

    Response::success([
        'part_configs' => $partConfigs,
        'students' => $result,
    ]);
});

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

// GET /api/teacher/subjects – returns teacher's assigned result subjects only
$router->get('/api/teacher/subjects', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$user['id']]);
    if (!$teacher) {
        Response::success([]);
        return;
    }

    $subjects = array_column(Database::fetchAll(
        "SELECT subject FROM teacher_subjects WHERE teacher_id = ? AND type = 'result' ORDER BY subject",
        [$teacher['id']]
    ), 'subject');

    Response::success(array_values($subjects));
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
            "SELECT id FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $data['subject']]
        );

        if ($existing) {
            Database::update('exam_results', [
                'mcq' => $mcq,
                'cq' => $cq,
                'practical' => $practical,
                'total' => $total,
                'grade' => $grade['grade'],
                'gpa' => $grade['points'],
                'status' => 'draft',
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
                'status' => 'draft',
                'uploaded_by' => $user['id'],
            ]);
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

    $partsData = $data['parts_data'] ?? [];
    $lowerParts = [];
    foreach ($partsData as $k => $v) $lowerParts[strtolower($k)] = $v;
    $mcq = (float) ($lowerParts['mcq'] ?? $data['mcq'] ?? $result['mcq']);
    $cq = (float) ($lowerParts['cq'] ?? $data['cq'] ?? $result['cq']);
    $practical = (float) ($lowerParts['practical'] ?? $data['practical'] ?? $result['practical']);
    $total = $mcq + $cq + $practical;
    $grade = calculateGradeFromTotal($total);

    Database::update('exam_results', [
        'mcq' => $mcq,
        'cq' => $cq,
        'practical' => $practical,
        'total' => $total,
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
