<?php

require_once __DIR__ . '/../../utils/helpers.php';

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
         ORDER BY e.year, e.class, e.exam_name, s.student_id",
        $params
    );

    foreach ($results as &$r) {
        $r['parts_data'] = $r['parts_data'] ? json_decode($r['parts_data'], true) : null;
        $r['absent_in'] = $r['absent_in'] ? json_decode($r['absent_in'], true) : [];
    }

    Response::success($results);
});

// DELETE /api/admin/results/{id}
$router->delete('/api/admin/results/{id}', function (array $params) {
    $user = Auth::requireRole('admin');

    $result = Database::fetch("SELECT * FROM exam_results WHERE id = ?", [$params['id']]);
    if (!$result) {
        Response::notFound('Result not found');
    }

    logResultChange(
        (int)$params['id'],
        'deleted',
        json_encode($result),
        null,
        $user['id']
    );

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
    $processed = 0;

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
            $total = array_sum(array_values($partsData));
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
                json_encode([
                    'mcq' => $mcq, 'cq' => $cq, 'practical' => $practical,
                    'parts_data' => $partsData, 'total' => $total,
                    'grade' => $grade['grade'], 'gpa' => $grade['points'],
                    'absent_in' => $absentIn,
                ]),
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
                json_encode([
                    'student_id' => $student['id'],
                    'subject' => $data['subject'],
                    'parts_data' => $partsData, 'total' => $total,
                    'grade' => $grade['grade'], 'gpa' => $grade['points'],
                ]),
                $user['id']
            );
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
