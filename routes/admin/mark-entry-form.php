<?php
// Routes for Admin Mark Entry Form
// GET  /api/admin/mark-entry-form/subjects?year=&class=&exam_name=
// POST /api/admin/mark-entry-form

if (!function_exists('markEntryNormSubject')) {
    function markEntryNormSubject($name) {
        $n = trim(strtolower((string)$name));
        $n = preg_replace('/\s+/', ' ', $n);
        if ($n === 'information and communication technology' || $n === 'information & communication technology') {
            return 'ict';
        }
        return $n;
    }
}

if (!function_exists('markEntrySameSubject')) {
    function markEntrySameSubject($a, $b) {
        return markEntryNormSubject($a) === markEntryNormSubject($b);
    }
}

if (!function_exists('markEntryGetConfiguredItems')) {
    function markEntryGetConfiguredItems($examId) {
        $rows = Database::fetchAll(
            "SELECT esc.*, 
                    sp.id AS paper_id, sp.name AS paper_name, sp.code AS paper_code, 
                    s.id AS subject_id, s.name AS subject_name, s.code AS subject_code
             FROM exam_subject_config esc
             LEFT JOIN subject_papers sp ON esc.selected_paper_id = sp.id
             LEFT JOIN subjects s ON esc.subject_id = s.id
             WHERE esc.exam_id = ?
             ORDER BY s.id, sp.id",
            [$examId]
        );

        $items = [];
        foreach ($rows as $row) {
            if ($row['paper_selection'] === 'single' && !empty($row['selected_paper_id'])) {
                $code = $row['paper_code'] ?: $row['subject_code'];
                $label = ($code ? $code . ' ' : '') . $row['paper_name'];
                $items[] = [
                    'key' => 'paper-' . $row['selected_paper_id'],
                    'subject_id' => (int)$row['subject_id'],
                    'subject_name' => $row['subject_name'],
                    'paper_id' => (int)$row['selected_paper_id'],
                    'paper_name' => $row['paper_name'],
                    'code' => $code,
                    'label' => $label,
                    'parts_target' => $row['paper_name'],
                ];
            } else {
                // All papers of the subject, or single paper with no specific paper_id
                $allPapers = Database::fetchAll(
                    "SELECT id, name, code FROM subject_papers WHERE parent_id = ? ORDER BY id",
                    [$row['subject_id']]
                );

                if (!empty($allPapers)) {
                    foreach ($allPapers as $p) {
                        $code = $p['code'] ?: $row['subject_code'];
                        $label = ($code ? $code . ' ' : '') . $p['name'];
                        $items[] = [
                            'key' => 'paper-' . $p['id'],
                            'subject_id' => (int)$row['subject_id'],
                            'subject_name' => $row['subject_name'],
                            'paper_id' => (int)$p['id'],
                            'paper_name' => $p['name'],
                            'code' => $code,
                            'label' => $label,
                            'parts_target' => $p['name'],
                        ];
                    }
                } else {
                    // Subject has no separate papers (e.g., ICT)
                    $code = $row['subject_code'];
                    $label = ($code ? $code . ' ' : '') . $row['subject_name'];
                    $items[] = [
                        'key' => 'subject-' . $row['subject_id'],
                        'subject_id' => (int)$row['subject_id'],
                        'subject_name' => $row['subject_name'],
                        'paper_id' => null,
                        'paper_name' => null,
                        'code' => $code,
                        'label' => $label,
                        'parts_target' => $row['subject_name'],
                    ];
                }
            }
        }
        return $items;
    }
}

// GET /api/admin/mark-entry-form/subjects?year=&class=&exam_name=
$router->get('/api/admin/mark-entry-form/subjects', function () {
    Auth::requireRole('admin');
    
    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    
    if (!$year || !$class || !$examName) {
        Response::success(['subjects' => []]);
    }
    
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    
    if (!$exam) {
        Response::success(['subjects' => []]);
    }

    $items = markEntryGetConfiguredItems($exam['id']);

    Response::success(['subjects' => $items]);
});

// POST /api/admin/mark-entry-form
$router->post('/api/admin/mark-entry-form', function () {
    Auth::requireRole('admin');
    
    $data = json_decode(file_get_contents('php://input'), true);
    $year = $data['year'] ?? '';
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $group = $data['group'] ?? '';
    $selectedKeys = $data['selected_keys'] ?? []; // Optional filter by specific keys
    
    if (!$year || !$class || !$examName) {
        Response::validationError(['error' => 'Year, Class, and Exam Name are required']);
    }
    
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    
    if (!$exam) {
        Response::error('Exam not found. Please configure the exam first.', 404);
    }
    
    $configuredItems = markEntryGetConfiguredItems($exam['id']);
    
    if (empty($configuredItems)) {
        Response::success(['subjects' => []]);
    }

    // Require explicit configured keys. Never interpret missing/empty selections as all subjects.
    if (!is_array($selectedKeys) || empty($selectedKeys)) {
        Response::success(['subjects' => []]);
    }
    $selectedKeys = array_values(array_unique(array_filter($selectedKeys, 'is_string')));
    $configuredItems = array_values(array_filter($configuredItems, function ($item) use ($selectedKeys) {
        return in_array($item['key'], $selectedKeys, true);
    }));
    if (empty($configuredItems)) {
        Response::success(['subjects' => []]);
    }

    // Fetch all students for this class (and group if specified)
    // Order: Group Priority (Science -> Business Studies -> Humanities -> others), then class_roll, student_id
    $groupCase = "CASE 
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%science%' THEN 1 
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%business%' THEN 2 
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%humanities%' THEN 3 
        ELSE 4 
    END";

    $studentQuery = "SELECT student_id, name, class_roll, class, section, student_group, 
                            compulsory_subjects, selective_subjects, optional_subject
                     FROM students 
                     WHERE class = ?";
    $params = [$class];
    if ($group) {
        $studentQuery .= " AND student_group = ?";
        $params[] = $group;
    }
    $studentQuery .= " ORDER BY {$groupCase} ASC, CAST(class_roll AS UNSIGNED) ASC, class_roll ASC, student_id ASC";
    
    $allStudents = Database::fetchAll($studentQuery, $params);

    $compulsoryList = ['Bangla', 'English', 'ICT', 'Information and Communication Technology'];

    $resultSheets = [];

    foreach ($configuredItems as $item) {
        $subjectName = $item['subject_name'];
        $enrollmentName = $item['paper_name'] ?: $item['subject_name'];
        $filteredStudents = [];

        foreach ($allStudents as $st) {
            $takes = false;

            // Required papers of compulsory subjects include every student in the selected class/group.
            foreach ($compulsoryList as $comp) {
                if (markEntrySameSubject($subjectName, $comp)) {
                    $takes = true;
                    break;
                }
            }

            // Also check student's compulsory_subjects JSON column if set
            if (!$takes && !empty($st['compulsory_subjects'])) {
                $compArray = json_decode($st['compulsory_subjects'], true);
                if (is_array($compArray)) {
                    foreach ($compArray as $c) {
                        if (markEntrySameSubject($enrollmentName, $c) || markEntrySameSubject($subjectName, $c)) {
                            $takes = true;
                            break;
                        }
                    }
                }
            }

            // 2. Selective subjects
            if (!$takes && !empty($st['selective_subjects'])) {
                $selArray = json_decode($st['selective_subjects'], true);
                if (is_array($selArray)) {
                    foreach ($selArray as $s) {
                        if (markEntrySameSubject($enrollmentName, $s) || markEntrySameSubject($subjectName, $s)) {
                            $takes = true;
                            break;
                        }
                    }
                }
            }

            // 3. Optional subject
            if (!$takes && !empty($st['optional_subject'])) {
                if (markEntrySameSubject($enrollmentName, $st['optional_subject']) || markEntrySameSubject($subjectName, $st['optional_subject'])) {
                    $takes = true;
                }
            }

            if ($takes) {
                $filteredStudents[] = [
                    'student_id' => $st['student_id'],
                    'name' => $st['name'],
                    'class_roll' => $st['class_roll'],
                    'class' => $st['class'],
                    'section' => $st['section'],
                    'student_group' => $st['student_group'],
                ];
            }
        }

        // Fetch subject parts
        // Look up by parts_target first (e.g. "Bangla-2", "Physics-2", etc.)
        $parts = Database::fetchAll(
            "SELECT part_name, full_mark, pass_mark 
             FROM subject_parts 
             WHERE subject = ? 
             ORDER BY sort_order ASC, id ASC",
            [$item['parts_target']]
        );

        // Fallback: try subject_name (e.g., if parts were registered under "Bangla" instead of "Bangla-2")
        if (empty($parts) && $item['subject_name'] !== $item['parts_target']) {
            $parts = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark 
                 FROM subject_parts 
                 WHERE subject = ? 
                 ORDER BY sort_order ASC, id ASC",
                [$item['subject_name']]
            );
        }

        // ICT fallback check
        if (empty($parts) && markEntrySameSubject($item['subject_name'], 'ICT')) {
            $parts = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark 
                 FROM subject_parts 
                 WHERE subject IN ('ICT', 'Information and Communication Technology')
                 ORDER BY sort_order ASC, id ASC"
            );
        }

        // Format parts to clean strings/numbers (strip trailing zeros if decimals)
        $cleanParts = array_map(function ($p) {
            return [
                'part_name' => $p['part_name'],
                'full_mark' => (string)(0 + $p['full_mark']),
                'pass_mark' => (string)(0 + $p['pass_mark']),
            ];
        }, $parts);

        // Only include if students are enrolled in this subject (or if user selected it)
        // If no students enrolled in this subject, it won't clutter the printout
        if (!empty($filteredStudents)) {
            $resultSheets[] = [
                'key' => $item['key'],
                'label' => $item['label'],
                'code' => $item['code'],
                'name' => $item['paper_name'] ?: $item['subject_name'],
                'subject_name' => $item['subject_name'],
                'parts' => $cleanParts,
                'students' => $filteredStudents,
            ];
        }
    }

    Response::success(['subjects' => $resultSheets]);
});
