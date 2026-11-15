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

// GET /api/admin/results/exam-subjects?year=&class=&exam_name=
// Only the subjects/papers the admin configured in Exam Paper Selection for this exam
$router->get('/api/admin/results/exam-subjects', function () {
    Auth::requireRole('admin');

    $year = (int)($_GET['year'] ?? 0);
    $class = trim($_GET['class'] ?? '');
    $examName = trim($_GET['exam_name'] ?? '');

    if (!$year || !$class || !$examName) {
        Response::validationError(['year, class, exam_name are required']);
    }

    $items = teacherResultGetConfiguredItems($year, $class, $examName);
    Response::success(['subjects' => $items]);
});

// DELETE /api/admin/results/{id}
$router->delete('/api/admin/results/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('exam_results', 'id = ?', [$params['id']]);
    Response::success(null, 'Result deleted');
});

// GET /api/admin/results/upload-data?year=&class=&exam_name=&subject=&subject_id=&paper_name=
$router->get('/api/admin/results/upload-data', function () {
    Auth::requireRole('admin');

    $year = (int)($_GET['year'] ?? 0);
    $class = trim($_GET['class'] ?? '');
    $examName = trim($_GET['exam_name'] ?? '');
    $subject = trim($_GET['subject'] ?? '');
    $subjectId = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int)$_GET['subject_id'] : 0;
    $paperName = trim($_GET['paper_name'] ?? '');
    $group = trim($_GET['group'] ?? '');

    if (!$year || !$class || !$examName || (!$subject && !$subjectId)) {
        Response::validationError(['year, class, exam_name, subject are required']);
    }

    // Resolve the configured subject/paper for this exam
    $configuredItems = teacherResultGetConfiguredItems($year, $class, $examName);
    $matchedItem = null;

    if ($subjectId > 0) {
        foreach ($configuredItems as $item) {
            if ($item['subject_id'] !== $subjectId) {
                continue;
            }
            if ($paperName === '') {
                $matchedItem = $item;
                break;
            }
            if ($item['paper_name'] && strcasecmp($item['paper_name'], $paperName) === 0) {
                $matchedItem = $item;
                break;
            }
        }
    }
    if (!$matchedItem && $subject !== '') {
        foreach ($configuredItems as $item) {
            if (teacherResultSameSubject($item['subject_name'], $subject)) {
                $matchedItem = $item;
                break;
            }
        }
    }

    if (!$configuredItems || !$matchedItem) {
        Response::error('Selected subject is not configured for this exam', 400);
    }

    $subjectToMatch = $matchedItem['subject_name'];
    $paperToMatch = $matchedItem['paper_name'];
    $subjectIdToMatch = (int)$matchedItem['subject_id'];

    // Stored result key: configured subject + paper
    $subjectKey = $subjectToMatch;
    if ($paperToMatch) {
        $subjectKey = $subjectToMatch . ' - ' . $paperToMatch;
    }

    // Part configurations (paper first, then subject, then ICT fallback)
    $partConfigs = [];
    foreach (teacherResultPartsCandidates($subjectToMatch, $paperToMatch) as $candidate) {
        if (teacherResultSameSubject($candidate, 'ICT')) {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark, sort_order FROM subject_parts
                 WHERE subject IN ('ICT', 'Information and Communication Technology')
                 ORDER BY sort_order ASC, id ASC"
            );
        } else {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark, sort_order FROM subject_parts WHERE subject = ? ORDER BY sort_order ASC, id ASC",
                [$candidate]
            );
        }
        if (!empty($rows)) {
            $partConfigs = $rows;
            break;
        }
    }
    // subject_parts may hold both 'ICT' and 'Information and Communication Technology' rows
    $seenParts = [];
    $partConfigs = array_values(array_filter(array_map(function ($p) use (&$seenParts) {
        $dedupeKey = strtolower((string)$p['part_name']);
        if (isset($seenParts[$dedupeKey])) {
            return null;
        }
        $seenParts[$dedupeKey] = true;
        return $p;
    }, $partConfigs)));

    if (empty($partConfigs)) {
        $partConfigs = [
            ['part_name' => 'mcq', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 1],
            ['part_name' => 'cq', 'full_mark' => 50, 'pass_mark' => 17, 'sort_order' => 2],
            ['part_name' => 'practical', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 3],
        ];
    }

    // Find exam_id
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $examId = $exam ? $exam['id'] : null;

    // Existing marks keyed by student row id
    $existingMarks = [];
    if ($examId) {
        $subjectKeys = [$subjectToMatch];
        if ($subjectKey !== $subjectToMatch) {
            $subjectKeys[] = $subjectKey;
        }
        $placeholders = implode(', ', array_fill(0, count($subjectKeys), '?'));
        $marksData = Database::fetchAll(
            "SELECT student_id, cq, mcq, practical, total, grade, gpa, parts_data, absent_in, status, id as result_id
             FROM exam_results
             WHERE exam_id = ? AND subject IN ({$placeholders})",
            array_merge([$examId], $subjectKeys)
        );
        foreach ($marksData as $m) {
            $existingMarks[$m['student_id']] = $m;
        }
    }

    // Only students who take the subject, ordered Science -> Business Studies -> Humanities -> roll
    $students = [];
    foreach (teacherResultFetchClassStudents($class, $group) as $st) {
        $existing = $existingMarks[$st['id']] ?? null;
        if (!teacherResultTakesSubject($st, $subjectToMatch, $paperToMatch, $existing !== null)) {
            continue;
        }

        $partsData = [];
        $absentIn = [];
        if ($existing) {
            if (!empty($existing['parts_data'])) {
                $decoded = json_decode($existing['parts_data'], true);
                if (is_array($decoded)) {
                    $partsData = $decoded;
                }
            }
            if (!empty($existing['absent_in'])) {
                $decoded = json_decode($existing['absent_in'], true);
                if (is_array($decoded)) {
                    $absentIn = $decoded;
                }
            }
            // Legacy rows without parts_data: map the fixed columns onto the configured parts
            if (empty($partsData)) {
                foreach ($partConfigs as $p) {
                    $col = strtolower($p['part_name']);
                    $partsData[$p['part_name']] = (float)($existing[$col] ?? 0);
                }
            }
        }

        $students[] = [
            'id' => $st['id'],
            'student_id' => $st['student_id'],
            'name' => $st['name'],
            'class_roll' => $st['class_roll'],
            'section' => $st['section'],
            'student_group' => $st['student_group'],
            'result_id' => $existing ? $existing['result_id'] : null,
            'status' => $existing ? $existing['status'] : null,
            'parts_data' => $partsData,
            'absent_in' => $absentIn,
            'mcq' => $existing ? $existing['mcq'] : 0,
            'cq' => $existing ? $existing['cq'] : 0,
            'practical' => $existing ? $existing['practical'] : 0,
            'total' => $existing ? $existing['total'] : 0,
            'grade' => $existing ? $existing['grade'] : '',
            'gpa' => $existing ? $existing['gpa'] : 0,
        ];
    }

    Response::success([
        'part_configs' => $partConfigs,
        'students' => $students,
        'subject_key' => $subjectKey,
        'subject_name' => $subjectToMatch,
        'paper_name' => $paperToMatch,
        'subject_id' => $subjectIdToMatch,
    ]);
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

    $year = (int)$data['year'];
    $class = (string)$data['class'];
    $examName = (string)$data['exam_name'];
    $subject = (string)$data['subject'];
    $subjectId = isset($data['subject_id']) ? (int)$data['subject_id'] : 0;
    $paperName = trim((string)($data['paper_name'] ?? ''));

    // Validate the subject against the admin's Exam Paper Selection for this exam
    $matchedItem = null;
    foreach (teacherResultGetConfiguredItems($year, $class, $examName) as $item) {
        if ($subjectId > 0 && $item['subject_id'] === $subjectId) {
            $matchedItem = $item;
            break;
        }
        if (teacherResultSameSubject($item['subject_name'], $subject) && ($paperName === '' || ($item['paper_name'] && strcasecmp($item['paper_name'], $paperName) === 0))) {
            $matchedItem = $item;
            break;
        }
    }
    if ($matchedItem) {
        $subject = $matchedItem['subject_name'];
        $paperName = $matchedItem['paper_name'] ?: '';
    }

    $subjectKey = $subject;
    if ($paperName !== '') {
        $subjectKey = $subject . ' - ' . $paperName;
    }

    // Get or create exam
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );

    if (!$exam) {
        $examId = Database::insert('exams', [
            'year' => $year,
            'class' => $class,
            'exam_name' => $examName,
        ]);
    } else {
        $examId = $exam['id'];
    }

    $marks = $data['marks'];
    $processed = 0;

    foreach ($marks as $mark) {
        $student = Database::fetch(
            "SELECT id FROM students WHERE student_id = ? AND class = ?",
            [$mark['student_id'], $class]
        );
        if (!$student) continue;

        $partsData = $mark['parts_data'] ?? [];
        $lowerParts = [];
        foreach ($partsData as $k => $v) $lowerParts[strtolower($k)] = $v;
        $mcq = (float) ($lowerParts['mcq'] ?? $mark['mcq'] ?? 0);
        $cq = (float) ($lowerParts['cq'] ?? $mark['cq'] ?? 0);
        $practical = (float) ($lowerParts['practical'] ?? $mark['practical'] ?? 0);
        $total = 0;
        foreach ($lowerParts as $v) $total += (float)$v;
        if (empty($lowerParts)) $total = $mcq + $cq + $practical;
        $grade = calculateGradeFromTotal($total);

        // Upsert
        $existing = Database::fetch(
            "SELECT id FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?",
            [$student['id'], $examId, $subjectKey]
        );

        if ($existing) {
            Database::query(
                "UPDATE exam_results SET mcq = ?, cq = ?, practical = ?, total = ?, grade = ?, gpa = ?, parts_data = ?, absent_in = ? WHERE id = ?",
                [$mcq, $cq, $practical, $total, $grade['grade'], $grade['points'],
                 !empty($partsData) ? json_encode($partsData) : null,
                 !empty($mark['absent_in']) ? json_encode($mark['absent_in']) : null,
                 $existing['id']]
            );
        } else {
            Database::query(
                "INSERT INTO exam_results (student_id, exam_id, subject, mcq, cq, practical, total, grade, gpa, parts_data, absent_in, status, uploaded_by)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                [$student['id'], $examId, $subjectKey, $mcq, $cq, $practical, $total, $grade['grade'], $grade['points'],
                 !empty($partsData) ? json_encode($partsData) : null,
                 !empty($mark['absent_in']) ? json_encode($mark['absent_in']) : null,
                 'draft', $user['id']]
            );
        }
        $processed++;
    }

    Response::created(['processed' => $processed, 'subject_key' => $subjectKey], "{$processed} results saved");
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
