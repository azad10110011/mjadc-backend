<?php

function teacherResultNormSubject(string $name): string
{
    $n = trim($name);
    $n = preg_replace('/^(compulsory|selective|optional)\s*:\s*/i', '', $n);
    $n = preg_replace('/\s*\([^)]*\)\s*$/', '', $n);
    $n = preg_replace('/\s*-(?:1st|2nd|1|2|first|second)\s*(?:paper)?\b/i', '', $n);
    $n = preg_replace('/\s+(?:1st|2nd|first|second)\s*(?:paper)?\b/i', '', $n);
    $n = preg_replace('/\s+paper\b/i', '', $n);
    $n = preg_replace('/\s+/', ' ', $n);
    return mb_strtolower(trim($n));
}

function teacherResultCanonicalize(string $name): string
{
    $norm = teacherResultNormSubject($name);
    $aliases = [
        'bangla' => 'bangla',
        'bengali' => 'bangla',
        'english' => 'english',
        'ict' => 'ict',
        'information and communication technology' => 'ict',
        'information & communication technology' => 'ict',
        'physics' => 'physics',
        'chemistry' => 'chemistry',
        'biology' => 'biology',
        'higher math' => 'higher mathematics',
        'higher mathematics' => 'higher mathematics',
    ];
    return $aliases[$norm] ?? $norm;
}

function teacherResultSameSubject(?string $a, ?string $b): bool
{
    if (!$a || !$b) {
        return false;
    }
    return teacherResultCanonicalize($a) === teacherResultCanonicalize($b);
}

function teacherResultSelectionKey(int $subjectId, $paperId): string
{
    return $paperId ? ('paper-' . (int)$paperId) : ('subject-' . $subjectId);
}

function teacherResultPartsCandidates(string $subjectName, ?string $paperName): array
{
    $candidates = [];
    if ($paperName) {
        $candidates[] = $paperName;
        $candidates[] = $subjectName . '-' . trim(str_ireplace('paper', '', $paperName));
    }
    $candidates[] = $subjectName;
    if (teacherResultSameSubject($subjectName, 'ICT')) {
        $candidates[] = 'ICT';
        $candidates[] = 'Information and Communication Technology';
    }

    return array_values(array_unique(array_filter($candidates, function ($c) {
        return $c !== null && trim((string)$c) !== '';
    })));
}

/**
 * Returns the subjects/papers an admin configured for the exam
 * (Exam Management -> Exam Paper Selection -> exam_subject_config).
 *
 * Config values are keyed by exam_id. A year/class/exam_name triple is resolved to the
 * exam row first; if that row does not exist yet (nothing uploaded for the exam), the
 * config is resolved by matching year/class/exam_name instead. Never returns every
 * subject in the college - an unconfigured exam returns an empty list.
 */
function teacherResultGetConfiguredItems(int $year, string $class, string $examName): array
{
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );

    $configs = [];
    if ($exam) {
        $configs = Database::fetchAll(
            "SELECT esc.id as config_id, esc.subject_id, esc.paper_selection, esc.selected_paper_id,
                    s.name as subject_name, s.code as subject_code,
                    p.name as paper_name, p.code as paper_code
             FROM exam_subject_config esc
             JOIN subjects s ON esc.subject_id = s.id
             LEFT JOIN subject_papers p ON esc.selected_paper_id = p.id
             WHERE esc.exam_id = ?
             ORDER BY s.id ASC, p.id ASC, esc.id ASC",
            [$exam['id']]
        );
    }

    if (empty($configs)) {
        $configs = Database::fetchAll(
            "SELECT esc.id as config_id, esc.subject_id, esc.paper_selection, esc.selected_paper_id,
                    s.name as subject_name, s.code as subject_code,
                    p.name as paper_name, p.code as paper_code
             FROM exam_subject_config esc
             JOIN exams e ON e.id = esc.exam_id
             JOIN subjects s ON esc.subject_id = s.id
             LEFT JOIN subject_papers p ON esc.selected_paper_id = p.id
             WHERE e.year = ? AND e.class = ? AND e.exam_name = ?
             ORDER BY s.id ASC, p.id ASC, esc.id ASC",
            [$year, $class, $examName]
        );
    }

    $items = [];
    foreach ($configs as $c) {
        $subjectId = (int)$c['subject_id'];
        $subjectName = (string)$c['subject_name'];
        $hasSelectedPaper = !empty($c['selected_paper_id']) && !empty($c['paper_name']);

        if ($hasSelectedPaper) {
            $items[] = [
                'key' => teacherResultSelectionKey($subjectId, $c['selected_paper_id']),
                'label' => trim(($c['paper_code'] ? $c['paper_code'] . ' ' : '') . $c['paper_name']),
                'subject_id' => $subjectId,
                'subject_name' => $subjectName,
                'paper_id' => (int)$c['selected_paper_id'],
                'paper_name' => $c['paper_name'],
                'code' => $c['paper_code'] ?: $c['subject_code'],
            ];
            continue;
        }

        if ($c['paper_selection'] === 'all') {
            // Every paper of the subject counts for this exam
            $papers = Database::fetchAll(
                "SELECT id, name, code FROM subject_papers WHERE parent_id = ? ORDER BY id ASC",
                [$subjectId]
            );
            foreach ($papers as $p) {
                $items[] = [
                    'key' => teacherResultSelectionKey($subjectId, $p['id']),
                    'label' => trim(($p['code'] ? $p['code'] . ' ' : '') . $p['name']),
                    'subject_id' => $subjectId,
                    'subject_name' => $subjectName,
                    'paper_id' => (int)$p['id'],
                    'paper_name' => $p['name'],
                    'code' => $p['code'] ?: $c['subject_code'],
                ];
            }
            if (empty($papers)) {
                $items[] = [
                    'key' => teacherResultSelectionKey($subjectId, null),
                    'label' => trim(($c['subject_code'] ? $c['subject_code'] . ' ' : '') . $subjectName),
                    'subject_id' => $subjectId,
                    'subject_name' => $subjectName,
                    'paper_id' => null,
                    'paper_name' => null,
                    'code' => $c['subject_code'],
                ];
            }
            continue;
        }

        // Single selection without a paper row: whole subject (e.g. ICT)
        $items[] = [
            'key' => teacherResultSelectionKey($subjectId, null),
            'label' => trim(($c['subject_code'] ? $c['subject_code'] . ' ' : '') . $subjectName),
            'subject_id' => $subjectId,
            'subject_name' => $subjectName,
            'paper_id' => null,
            'paper_name' => null,
            'code' => $c['subject_code'],
        ];
    }

    return $items;
}

/**
 * Students of the class (or one group) ordered Science -> Business Studies -> Humanities,
 * then class roll, then student id.
 */
function teacherResultFetchClassStudents(string $class, string $group = ''): array
{
    $groupCase = "CASE
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%science%' THEN 1
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%business%' THEN 2
        WHEN LOWER(TRIM(COALESCE(student_group, ''))) LIKE '%humanities%' THEN 3
        ELSE 4
    END";

    $sql = "SELECT id, user_id, student_id, name, class_roll, class, section, student_group,
                   compulsory_subjects, selective_subjects, optional_subject
            FROM students
            WHERE class = ?";
    $params = [$class];
    if ($group !== '') {
        $sql .= " AND student_group = ?";
        $params[] = $group;
    }
    $sql .= " ORDER BY {$groupCase} ASC, CAST(class_roll AS UNSIGNED) ASC, class_roll ASC, student_id ASC";

    return Database::fetchAll($sql, $params);
}

/**
 * Keeps only students who actually take the subject/paper.
 *
 * - Compulsory subjects (Bangla, English, ICT, ...) are taken by every student of the class.
 * - Selective / optional subjects only by students who listed them (paper name or parent subject).
 * - Students without any subject selection are only included when an existing result row
 *   already exists for that subject (never load a whole class into an unrelated subject).
 */
function teacherResultTakesSubject(array $st, string $subjectName, ?string $paperName, bool $hasExistingResult = false): bool
{
    $compulsoryList = ['Bangla', 'English', 'ICT', 'Information and Communication Technology'];
    foreach ($compulsoryList as $comp) {
        if (teacherResultSameSubject($subjectName, $comp)) {
            return true;
        }
    }

    $names = [$subjectName];
    if ($paperName) {
        $names[] = $paperName;
    }

    $lists = [];
    if (!empty($st['compulsory_subjects'])) {
        $decoded = json_decode($st['compulsory_subjects'], true);
        if (is_array($decoded)) {
            $lists[] = $decoded;
        }
    }
    if (!empty($st['selective_subjects'])) {
        $decoded = json_decode($st['selective_subjects'], true);
        if (is_array($decoded)) {
            $lists[] = $decoded;
        }
    }
    foreach ($lists as $list) {
        foreach ($list as $enrolled) {
            foreach ($names as $name) {
                if (teacherResultSameSubject($name, $enrolled)) {
                    return true;
                }
            }
        }
    }

    if (!empty($st['optional_subject'])) {
        foreach ($names as $name) {
            if (teacherResultSameSubject($name, $st['optional_subject'])) {
                return true;
            }
        }
    }

    $hasSelection = !empty($st['compulsory_subjects']) || !empty($st['selective_subjects']) || !empty($st['optional_subject']);

    return !$hasSelection && $hasExistingResult;
}

/**
 * Subjects (public names) the logged-in teacher was assigned for result management
 * (Admin -> Users -> "Assign Result Management Subjects (Exam Marks)" -> teacher_subjects.type='result').
 * Returns null when the viewer is not a plain teacher (exam_controller sees every subject).
 */
function teacherResultAssignedSubjects(array $user): ?array
{
    // Only viewers WITHOUT the teacher role (e.g. a pure exam_controller) are unrestricted;
    // anyone who teaches is limited to the subjects assigned to them.
    if (!in_array('teacher', $user['roles'] ?? [], true)) {
        return null;
    }
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$user['id']]);
    if (!$teacher) {
        return [];
    }
    $rows = Database::fetchAll(
        "SELECT subject FROM teacher_subjects WHERE teacher_id = ? AND type = 'result' ORDER BY subject",
        [$teacher['id']]
    );
    return array_column($rows, 'subject');
}

// GET /api/teacher/results/exam-subjects - Returns only subjects configured for the selected exam
$router->get('/api/teacher/results/exam-subjects', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $year = (int)($_GET['year'] ?? 0);
    $class = trim($_GET['class'] ?? '');
    $examName = trim($_GET['exam_name'] ?? '');

    if (!$year || !$class || !$examName) {
        Response::error('year, class, and exam_name are required', 400);
    }

    $items = teacherResultGetConfiguredItems($year, $class, $examName);

    // Plain teachers only see exam-configured subjects they were assigned for result management
    $assigned = teacherResultAssignedSubjects($user);
    if ($assigned !== null) {
        $items = array_values(array_filter($items, function ($item) use ($assigned) {
            foreach ($assigned as $a) {
                if (teacherResultSameSubject($item['subject_name'], $a) || teacherResultSameSubject((string)($item['paper_name'] ?? ''), $a)) {
                    return true;
                }
            }
            return false;
        }));
    }

    Response::success(['subjects' => $items]);
});

// GET /api/teacher/results/subjects - Kept for backwards compatibility
$router->get('/api/teacher/results/subjects', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);
    $subjects = Database::fetchAll("SELECT DISTINCT subject FROM teacher_subjects WHERE teacher_id = ? ORDER BY subject", [$user['id']]);
    Response::success(array_column($subjects, 'subject'));
});

// GET /api/teacher/results/load-students - Loads enrolled students for the selected subject
$router->get('/api/teacher/results/load-students', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $year = (int)($_GET['year'] ?? 0);
    $class = trim($_GET['class'] ?? '');
    $examName = trim($_GET['exam_name'] ?? '');
    $subject = trim($_GET['subject'] ?? '');
    $subjectId = isset($_GET['subject_id']) && $_GET['subject_id'] !== '' ? (int)$_GET['subject_id'] : 0;
    $paperName = trim($_GET['paper_name'] ?? '');
    $group = trim($_GET['group'] ?? '');

    if (!$year || !$class || !$examName || (!$subject && !$subjectId)) {
        Response::error('Year, class, exam_name, and subject are required', 400);
    }

    $configuredItems = teacherResultGetConfiguredItems($year, $class, $examName);

    // Teachers may only load students for subjects assigned to them for result management
    $assigned = teacherResultAssignedSubjects($user);
    if ($assigned !== null) {
        $configuredItems = array_values(array_filter($configuredItems, function ($item) use ($assigned) {
            foreach ($assigned as $a) {
                if (teacherResultSameSubject($item['subject_name'], $a) || teacherResultSameSubject((string)($item['paper_name'] ?? ''), $a)) {
                    return true;
                }
            }
            return false;
        }));
    }

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

    // Restricted teachers may never fall back to a subject outside their assignment list
    if (!$matchedItem && $assigned !== null) {
        Response::error('This subject is not assigned to you for result management', 403);
    }

    $subjectToMatch = $matchedItem ? $matchedItem['subject_name'] : $subject;
    $subjectIdToMatch = $matchedItem ? (int)$matchedItem['subject_id'] : $subjectId;
    $paperToMatch = $matchedItem ? $matchedItem['paper_name'] : ($paperName !== '' ? $paperName : null);

    // Results are stored under the configured subject + paper key so each paper keeps its own marks
    $subjectKey = $subjectToMatch;
    if ($paperToMatch) {
        $subjectKey = $subjectToMatch . ' - ' . $paperToMatch;
    }

    // Part configuration: paper first, then subject, then ICT fallback
    $partConfigs = [];
    foreach (teacherResultPartsCandidates($subjectToMatch, $paperToMatch) as $candidate) {
        if (teacherResultSameSubject($candidate, 'ICT')) {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark
                 FROM subject_parts
                 WHERE subject IN ('ICT', 'Information and Communication Technology')
                 ORDER BY sort_order ASC, id ASC"
            );
        } else {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark
                 FROM subject_parts
                 WHERE subject = ?
                 ORDER BY sort_order ASC, id ASC",
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
        $name = (string)$p['part_name'];
        $dedupeKey = strtolower($name);
        if (isset($seenParts[$dedupeKey])) {
            return null;
        }
        $seenParts[$dedupeKey] = true;
        return [
            'part_name' => $name,
            'full_mark' => (float)$p['full_mark'],
            'pass_mark' => (float)$p['pass_mark'],
        ];
    }, $partConfigs)));

    // Find exam_id
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    $examId = $exam ? $exam['id'] : null;

    // Existing marks for this subject/paper
    $existingMarks = [];
    if ($examId) {
        $subjectKeys = [(string)$subjectToMatch];
        if ($subjectKey !== $subjectToMatch) {
            $subjectKeys[] = $subjectKey;
        }
        $placeholders = implode(', ', array_fill(0, count($subjectKeys), '?'));
        // NOTE: exam_results has no is_absent column; absence is stored in absent_in (JSON).
        $marksData = Database::fetchAll(
            "SELECT student_id, cq, mcq, practical, total, grade, gpa, parts_data, absent_in
             FROM exam_results
             WHERE exam_id = ? AND subject IN ({$placeholders})",
            array_merge([$examId], $subjectKeys)
        );
        foreach ($marksData as $m) {
            $existingMarks[$m['student_id']] = $m;
        }
    }

    // Class students ordered Science -> Business -> Humanities -> roll
    $allStudents = teacherResultFetchClassStudents($class, $group);
    // Session 100: admin rule — which students subject teachers / exam controller may enter marks for
    $markRule = duesSettings()['mark_entry'];
    [$allStudents, , $hiddenDue] = duesFilterRows($allStudents, $year, $examName, $markRule);

    $result = [];
    foreach ($allStudents as $s) {
        $existing = $existingMarks[$s['id']] ?? null;

        if (!teacherResultTakesSubject($s, $subjectToMatch, $paperToMatch, $existing !== null)) {
            continue;
        }

        $partsData = [];
        $absentIn = [];

        if ($existing && !empty($existing['parts_data'])) {
            $decoded = json_decode($existing['parts_data'], true);
            if (is_array($decoded)) {
                $partsData = $decoded;
            }
        }
        if ($existing && !empty($existing['absent_in'])) {
            $decoded = json_decode($existing['absent_in'], true);
            if (is_array($decoded)) {
                $absentIn = $decoded;
            }
        }
        $isAbsent = $existing ? (count($absentIn) > 0 && count($absentIn) >= count($partConfigs)) : false;

        $result[] = [
            'id' => $s['id'],
            'student_id' => $s['student_id'],
            'name' => $s['name'],
            'class_roll' => $s['class_roll'],
            'section' => $s['section'],
            'student_group' => $s['student_group'],
            'subject_id' => $subjectIdToMatch,
            'paper_name' => $paperToMatch,
            'subject_key' => $subjectKey,
            'cq' => $existing ? $existing['cq'] : 0,
            'mcq' => $existing ? $existing['mcq'] : 0,
            'practical' => $existing ? $existing['practical'] : 0,
            'total' => $existing ? $existing['total'] : 0,
            'grade' => $existing ? $existing['grade'] : '',
            'gpa' => $existing ? $existing['gpa'] : 0,
            'is_absent' => (bool)$isAbsent,
            'parts_data' => $partsData,
            'absent_in' => $absentIn,
        ];
    }

    Response::success([
        'students' => $result,
        'mark_entry_rule' => $markRule,
        'mark_entry_rule_label' => DUES_FILTERS[$markRule] ?? $markRule,
        'hidden_due' => $hiddenDue,
        'part_configs' => $partConfigs,
        'subject_key' => $subjectKey,
        'subject_name' => $subjectToMatch,
        'paper_name' => $paperToMatch,
        'subject_id' => $subjectIdToMatch,
    ]);
});

// POST /api/teacher/results/upload - Upload and save results for exam-configured subject
$router->post('/api/teacher/results/upload', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $data = json_decode(file_get_contents('php://input'), true);
    $year = $data['year'] ?? '';
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $subject = $data['subject'] ?? '';
    $subjectId = isset($data['subject_id']) ? (int)$data['subject_id'] : 0;
    $paperName = trim((string)($data['paper_name'] ?? ''));
    $status = ($data['status'] ?? '') === 'draft' ? 'draft' : null;
    $marks = $data['marks'] ?? [];

    if (!$year || !$class || !$examName || (!$subject && !$subjectId) || empty($marks)) {
        Response::error('All fields and marks are required', 400);
    }

    // Verify subject is configured for this exam if subject_id is supplied
    $configuredItems = teacherResultGetConfiguredItems((int)$year, $class, $examName);

    // Teachers may only save marks for subjects assigned to them for result management
    $assigned = teacherResultAssignedSubjects($user);
    if ($assigned !== null) {
        $configuredItems = array_values(array_filter($configuredItems, function ($item) use ($assigned) {
            foreach ($assigned as $a) {
                if (teacherResultSameSubject($item['subject_name'], $a) || teacherResultSameSubject((string)($item['paper_name'] ?? ''), $a)) {
                    return true;
                }
            }
            return false;
        }));
    }

    if (!empty($configuredItems)) {
        $valid = false;
        foreach ($configuredItems as $item) {
            if ($subjectId > 0 && $item['subject_id'] === $subjectId) {
                $valid = true;
                $subject = $item['subject_name'];
                $paperName = $item['paper_name'];
                break;
            } elseif ($subject && teacherResultSameSubject($item['subject_name'], $subject)) {
                $valid = true;
                $subject = $item['subject_name'];
                $paperName = $item['paper_name'];
                break;
            }
        }
        if (!$valid) {
            Response::error($assigned === null ? 'Selected subject is not configured for this exam' : 'This subject is not assigned to you for result management', 403);
        }
    } elseif ($assigned !== null) {
        Response::error('No subjects are assigned to you for result management', 403);
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );

    if (!$exam) {
        $examId = Database::insert(
            "INSERT INTO exams (year, class, exam_name) VALUES (?, ?, ?)",
            [$year, $class, $examName]
        );
    } else {
        $examId = $exam['id'];
    }

    // Results key: configured subject + paper (same key used by load-students)
    $subjectKey = $subject;
    if ($paperName !== '') {
        $subjectKey = $subject . ' - ' . $paperName;
    }

    $partConfigs = [];
    foreach (teacherResultPartsCandidates($subject, $paperName !== '' ? $paperName : null) as $candidate) {
        if (teacherResultSameSubject($candidate, 'ICT')) {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark FROM subject_parts
                 WHERE subject IN ('ICT', 'Information and Communication Technology')
                 ORDER BY sort_order ASC, id ASC"
            );
        } else {
            $rows = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark FROM subject_parts WHERE subject = ? ORDER BY sort_order ASC, id ASC",
                [$candidate]
            );
        }
        if (!empty($rows)) {
            $partConfigs = $rows;
            break;
        }
    }

    // Session 100: marks can only be entered for students allowed by the admin's fee rule
    $markRule = duesSettings()['mark_entry'];
    $allowedIds = null;
    if ($markRule !== 'all') {
        $codes = array_values(array_unique(array_filter(array_map(fn($m) => (string)($m['student_id'] ?? ''), $marks))));
        $rows = $codes ? Database::fetchAll("SELECT id, user_id, class FROM students WHERE student_id IN (" . implode(',', array_fill(0, count($codes), '?')) . ")", $codes) : [];
        $st = duesStatusFor($rows, (int)$year, (string)$examName);
        $allowedIds = [];
        foreach ($rows as $r) if (duesMatches($st[(int)$r['id']] ?? null, $markRule)) $allowedIds[(int)$r['id']] = true;
    }
    $skippedDue = 0;

    $saved = 0;
    foreach ($marks as $m) {
        $studentId = $m['student_id'] ?? '';
        if (!$studentId) {
            continue;
        }

        $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$studentId]);
        if (!$student) {
            continue;
        }
        if ($allowedIds !== null && !isset($allowedIds[(int)$student['id']])) {
            $skippedDue++;
            continue;
        }

        $partsData = $m['parts_data'] ?? [];
        $absentIn = $m['absent_in'] ?? [];
        $cq = (float)($m['cq'] ?? ($partsData['cq'] ?? 0));
        $mcq = (float)($m['mcq'] ?? ($partsData['mcq'] ?? 0));
        $practical = (float)($m['practical'] ?? ($partsData['practical'] ?? 0));

        if (!empty($partConfigs)) {
            $total = 0;
            $failed = false;
            $allAbsent = count($partConfigs) > 0;

            foreach ($partConfigs as $p) {
                $pn = $p['part_name'];
                $isAbs = in_array($pn, $absentIn, true);
                if (!$isAbs) {
                    $allAbsent = false;
                }
                $val = (float)($partsData[$pn] ?? 0);
                $total += $val;
                if ($isAbs || $val < (float)$p['pass_mark']) {
                    $failed = true;
                }
            }

            if ($allAbsent) {
                $grade = 'F';
                $gpa = 0.00;
                $isAbsent = 1;
            } elseif ($failed) {
                $grade = 'F';
                $gpa = 0.00;
                $isAbsent = 0;
            } else {
                $gInfo = calculateGradeFromTotal($total);
                $grade = $gInfo['grade'];
                $gpa = $gInfo['points'];
                $isAbsent = 0;
            }
        } else {
            $total = (float)($m['total'] ?? ($cq + $mcq + $practical));
            $isAbsent = !empty($m['is_absent']) ? 1 : 0;
            if ($isAbsent) {
                $grade = 'F';
                $gpa = 0.00;
            } else {
                $gInfo = calculateGradeFromTotal($total);
                $grade = $gInfo['grade'];
                $gpa = $gInfo['points'];
            }
        }

        $existing = Database::fetch(
            "SELECT id FROM exam_results WHERE exam_id = ? AND student_id = ? AND subject = ?",
            [$examId, $student['id'], $subjectKey]
        );

        $partsJson = !empty($partsData) ? json_encode($partsData) : null;
        $absentJson = !empty($absentIn) ? json_encode($absentIn) : null;

        if ($existing) {
            Database::query(
                "UPDATE exam_results SET cq = ?, mcq = ?, practical = ?, total = ?, grade = ?, gpa = ?, parts_data = ?, absent_in = ? WHERE id = ?",
                [$cq, $mcq, $practical, $total, $grade, $gpa, $partsJson, $absentJson, $existing['id']]
            );
        } else {
            $columns = 'exam_id, student_id, subject, cq, mcq, practical, total, grade, gpa, parts_data, absent_in, uploaded_by';
            $placeholders = '?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?';
            $params = [$examId, $student['id'], $subjectKey, $cq, $mcq, $practical, $total, $grade, $gpa, $partsJson, $absentJson, $user['id']];
            if ($status === 'draft') {
                $columns .= ', status';
                $placeholders .= ', ?';
                $params[] = 'draft';
            }
            Database::query(
                "INSERT INTO exam_results ({$columns}) VALUES ({$placeholders})",
                $params
            );
        }
        $saved++;
    }

    Response::success(['message' => 'Results uploaded successfully' . ($skippedDue ? " ($skippedDue student(s) skipped: fee rule)" : ''), 'saved_count' => $saved, 'skipped_due' => $skippedDue, 'subject_key' => $subjectKey]);
});

// GET /api/teacher/results/download
$router->get('/api/teacher/results/download', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';
    $subject = $_GET['subject'] ?? '';

    if (!$year || !$class || !$examName || !$subject) {
        Response::error('All fields are required', 400);
    }

    $results = Database::fetchAll(
        "SELECT s.student_id, s.name, s.class_roll, s.section,
                er.cq, er.mcq, er.practical, er.total, er.grade, er.gpa, er.is_absent
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
