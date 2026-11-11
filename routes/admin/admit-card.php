<?php
// Shared helpers for exam form generation (admit cards + blank mark entry sheets)

if (!function_exists('examFormStudents')) {
    function examFormStudents(array $data): array
    {
        $class = $data['class'] ?? '';
        $studentIdFilter = $data['student_id'] ?? '';
        $groupFilter = $data['group'] ?? '';
        if (!$class) {
            Response::validationError(['class is required']);
        }
        $where = 's.class = ?';
        $params = [$class];
        if ($studentIdFilter) {
            $where .= ' AND s.student_id = ?';
            $params[] = $studentIdFilter;
        }
        if ($groupFilter) {
            $where .= ' AND s.student_group = ?';
            $params[] = $groupFilter;
        }
        return Database::fetchAll(
            "SELECT s.id AS sid, s.user_id, s.student_id, s.name, s.name_bn, s.father_name, s.mother_name,
                    s.date_of_birth, s.registration_no, s.class, s.section,
                    s.student_group, s.student_type, s.academic_session, s.class_roll,
                    s.photo_path, s.optional_subject, s.selective_subjects
             FROM students s
             WHERE $where
             ORDER BY s.class_roll + 0, s.student_id",
            $params
        );
    }
}

if (!function_exists('examFormSubjects')) {
    function examFormSubjects(string $groupFilter): array
    {
        if ($groupFilter) {
            $subjects = Database::fetchAll(
                "SELECT * FROM subjects WHERE `group` = ? OR `group` = 'General' ORDER BY `group`, name",
                [$groupFilter]
            );
        } else {
            $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY `group`, name");
        }
        $out = [];
        foreach ($subjects as $s) {
            $papers = Database::fetchAll(
                "SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name",
                [$s['id']]
            );
            if (count($papers) > 0) {
                foreach ($papers as $p) {
                    $out[] = [
                        'name' => $p['name'],
                        'subject_name' => $s['name'],
                        'parts' => Database::fetchAll(
                            "SELECT part_name, full_mark, pass_mark FROM subject_parts
                             WHERE subject = ? ORDER BY sort_order, id",
                            [$p['name']]
                        ),
                    ];
                }
            } else {
                $out[] = [
                    'name' => $s['name'],
                    'subject_name' => $s['name'],
                    'parts' => Database::fetchAll(
                        "SELECT part_name, full_mark, pass_mark FROM subject_parts
                         WHERE subject = ? ORDER BY sort_order, id",
                        [$s['name']]
                    ),
                ];
            }
        }
        return $out;
    }
}

if (!function_exists('examFormSubjectStudents')) {
    // Students who take a given subject:
    //  - subject belongs to student's group, OR subject is General, OR
    //  - subject name is listed in the student's selective_subjects JSON
    function examFormSubjectStudents(array $students, string $subjectName, string $subjectGroup): array
    {
        $out = [];
        foreach ($students as $s) {
            $taken = false;
            $sel = json_decode($s['selective_subjects'] ?? '[]', true);
            if (is_array($sel) && in_array($subjectName, $sel, true)) {
                $taken = true;
            }
            if (!$taken && ($subjectGroup === 'General' || $subjectGroup === null || $subjectGroup === '')) {
                $taken = true;
            }
            if (!$taken && $subjectGroup !== '' && $s['student_group'] === $subjectGroup) {
                $taken = true;
            }
            if ($taken) {
                $out[] = $s;
            }
        }
        usort($out, function ($a, $b) {
            return ((int)($a['class_roll'] ?: 0)) <=> ((int)($b['class_roll'] ?: 0));
        });
        return $out;
    }
}

// POST /api/admin/admit-card - students for admit card generation
$router->post('/api/admin/admit-card', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $data = json_decode(file_get_contents('php://input'), true);
    $students = examFormStudents($data);
    Response::success($students);
});

// POST /api/admin/mark-entry-form - per-subject student lists with part config
$router->post('/api/admin/mark-entry-form', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $data = json_decode(file_get_contents('php://input'), true);
    $class = $data['class'] ?? '';
    if (!$class) {
        Response::validationError(['class is required']);
    }
    $students = examFormStudents($data);
    if ($data['group'] ?? '') {
        $subjects = Database::fetchAll(
            "SELECT * FROM subjects WHERE `group` = ? OR `group` = 'General' ORDER BY `group`, name",
            [$data['group']]
        );
    } else {
        $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY `group`, name");
    }

    $out = [];
    foreach ($subjects as $s) {
        $subjectStudents = examFormSubjectStudents($students, $s['name'], $s['group'] ?? '');
        if (count($subjectStudents) === 0) {
            continue; // no students take this subject -> no sheet
        }
        $papers = Database::fetchAll(
            "SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name",
            [$s['id']]
        );
        if (count($papers) > 0) {
            foreach ($papers as $p) {
                $parts = Database::fetchAll(
                    "SELECT part_name, full_mark, pass_mark FROM subject_parts
                     WHERE subject = ? ORDER BY sort_order, id",
                    [$p['name']]
                );
                $out[] = [
                    'name' => $p['name'],
                    'subject_name' => $s['name'],
                    'parts' => $parts,
                    'students' => array_map(function ($st) {
                        return [
                            'student_id' => $st['student_id'],
                            'name' => $st['name'],
                            'class_roll' => $st['class_roll'],
                            'section' => $st['section'],
                            'student_group' => $st['student_group'],
                        ];
                    }, $subjectStudents),
                ];
            }
        } else {
            $parts = Database::fetchAll(
                "SELECT part_name, full_mark, pass_mark FROM subject_parts
                 WHERE subject = ? ORDER BY sort_order, id",
                [$s['name']]
            );
            $out[] = [
                'name' => $s['name'],
                'subject_name' => $s['name'],
                'parts' => $parts,
                'students' => array_map(function ($st) {
                    return [
                        'student_id' => $st['student_id'],
                        'name' => $st['name'],
                        'class_roll' => $st['class_roll'],
                        'section' => $st['section'],
                        'student_group' => $st['student_group'],
                    ];
                }, $subjectStudents),
            ];
        }
    }

    Response::success(['subjects' => $out]);
});
