<?php

// POST /api/admin/transcript – generate student transcripts (admin & exam_controller)
$router->post('/api/admin/transcript', function () {
    $user = Auth::requireAnyRole(['admin', 'exam_controller']);

    $data = json_decode(file_get_contents('php://input'), true);
    $year = $data['year'] ?? '';
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $studentIdFilter = $data['student_id'] ?? '';
    $groupFilter = $data['group'] ?? '';

    if (!$year || !$class || !$examName) {
        Response::validationError(['year, class, and exam_name are required']);
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }
    $examId = $exam['id'];

    // ---------- Build subject / paper lookup maps ----------
    $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY `group`, name");
    $subjectMap = [];          // subject id => subject row
    $paperToSubject = [];      // paper name => subject id
    $paperToSubjectName = [];  // paper name => subject name
    $subjectPapersMap = [];    // subject id => papers []

    foreach ($subjects as $s) {
        $subjectMap[$s['id']] = $s;
        $papers = Database::fetchAll("SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name", [$s['id']]);
        $subjectPapersMap[$s['id']] = $papers;
        foreach ($papers as $p) {
            $paperToSubject[$p['name']] = $s['id'];
            $paperToSubjectName[$p['name']] = $s['name'];
        }
    }

    // ---------- Exam subject config (paper selection + calculation mode) ----------
    $configs = Database::fetchAll(
        "SELECT * FROM exam_subject_config WHERE exam_id = ?",
        [$examId]
    );
    $configMap = [];
    $selectedPaperNameBySubject = [];
    foreach ($configs as $c) {
        $configMap[$c['subject_id']] = $c;
        if ($c['paper_selection'] === 'single' && $c['selected_paper_id']) {
            $sp = Database::fetch("SELECT name FROM subject_papers WHERE id = ?", [$c['selected_paper_id']]);
            if ($sp) {
                $selectedPaperNameBySubject[$c['subject_id']] = $sp['name'];
            }
        }
    }

    // ---------- Build student query ----------
    $studentWhere = 's.class = ?';
    $studentParams = [$class];
    if ($studentIdFilter) {
        $studentWhere .= ' AND s.student_id = ?';
        $studentParams[] = $studentIdFilter;
    }
    if ($groupFilter) {
        $studentWhere .= ' AND s.student_group = ?';
        $studentParams[] = $groupFilter;
    }

    $students = Database::fetchAll(
        "SELECT s.*, u.date_of_birth
         FROM students s
         LEFT JOIN users u ON s.user_id = u.id
         WHERE $studentWhere
         ORDER BY s.student_id",
        $studentParams
    );

    if (empty($students)) {
        Response::success([]);
    }

    // ---------- Get all results for this exam ----------
    $results = Database::fetchAll(
        "SELECT er.*, e.exam_name, e.year
         FROM exam_results er
         JOIN exams e ON er.exam_id = e.id
         WHERE er.exam_id = ?
         ORDER BY er.student_id, er.subject",
        [$examId]
    );

    $resultsByStudent = [];
    foreach ($results as $r) {
        $resultsByStudent[$r['student_id']][] = $r;
    }

    $transcripts = [];
    foreach ($students as $student) {
        $studentResults = $resultsByStudent[$student['id']] ?? [];

        $subjects = [];
        $totalPoints = 0;
        $subjectCount = 0;
        $optionalGp = 0;
        $hasOptional = !empty($student['optional_subject']);

        // ----- Optional subject paper lookup (existing behaviour) -----
        $optionalPaperNames = [];
        $optionalPublicName = '';
        if ($hasOptional) {
            $optSubj = Database::fetch(
                "SELECT id, name FROM subjects WHERE name = ?",
                [$student['optional_subject']]
            );
            if ($optSubj) {
                $optionalPublicName = $optSubj['name'];
                $optionalPaperNames = array_column(Database::fetchAll(
                    "SELECT name FROM subject_papers WHERE parent_id = ?",
                    [$optSubj['id']]
                ), 'name');
            }
        }

        $optionalTotalSum = 0;
        $optionalGpaSum = 0;
        $optionalPaperCount = 0;

        // ----- Group non-optional results by subject (for averaging) -----
        $resultsBySubject = [];   // subject_id => [result rows]
        $singleRowResults = [];   // [{ display_name, result }] for separate display

        foreach ($studentResults as $r) {
            $paperName = $r['subject'];
            $subjectId = $paperToSubject[$paperName] ?? null;
            $config = $configMap[$subjectId] ?? null;

            // ----- Optional subject (existing behaviour) -----
            $isOptionalPaper = $hasOptional && (
                (!empty($optionalPaperNames) && in_array($paperName, $optionalPaperNames))
                || $paperName === $student['optional_subject']
            );

            if ($isOptionalPaper) {
                $total = (float)($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
                $gpa = (float)($r['gpa'] ?? 0);
                $optionalTotalSum += $total;
                $optionalGpaSum += $gpa;
                $optionalPaperCount++;
                continue;
            }

            // ----- Exam subject config logic -----
            if ($config && $config['paper_selection'] === 'single') {
                // Show only the selected paper; display name = paper name
                $selectedPaperName = $selectedPaperNameBySubject[$subjectId] ?? $paperName;
                if ($paperName === $selectedPaperName) {
                    $singleRowResults[] = [
                        'display_name' => $paperName,
                        'result' => $r,
                    ];
                }
            } elseif ($config && $config['paper_selection'] === 'all' && $config['calculation_mode'] === 'average') {
                // Group for average calculation
                $resultsBySubject[$subjectId][] = $r;
            } else {
                // Default / separate: show each paper individually with paper name
                $singleRowResults[] = [
                    'display_name' => $paperName,
                    'result' => $r,
                ];
            }
        }

        // ----- Emit single-row results (separate papers) -----
        foreach ($singleRowResults as $row) {
            $r = $row['result'];
            $gpa = (float)($r['gpa'] ?? 0);
            $total = (float)($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
            $grade = $r['grade'] ?? 'F';

            $subjects[] = [
                'subject' => $row['display_name'],
                'total' => $total,
                'grade' => $grade,
                'gpa' => $gpa,
                'status' => $r['status'],
            ];
            $totalPoints += $gpa;
            $subjectCount++;
        }

        // ----- Emit average-mode subjects (combined papers) -----
        foreach ($resultsBySubject as $subjectId => $papers) {
            $subjectName = $subjectMap[$subjectId]['name'] ?? 'Unknown';
            $paperCount = count($papers);
            $totalSum = 0;
            $gpaSum = 0;

            foreach ($papers as $r) {
                $total = (float)($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
                $gpa = (float)($r['gpa'] ?? 0);
                $totalSum += $total;
                $gpaSum += $gpa;
            }

            $avgTotalPerPaper = $paperCount > 0 ? $totalSum / $paperCount : 0;
            $avgGradeInfo = calculateGradeFromTotal($avgTotalPerPaper);

            $subjects[] = [
                'subject' => $subjectName,
                'total' => $avgTotalPerPaper,
                'grade' => $avgGradeInfo['grade'],
                'gpa' => $avgGradeInfo['points'],
                'status' => 'draft',
                'is_average' => true,
                'paper_count' => $paperCount,
            ];

            $totalPoints += $avgGradeInfo['points'];
            $subjectCount++;
        }

        // ----- Combined optional subject entry (existing behaviour) -----
        if ($optionalPaperCount > 0) {
            $avgTotalPerPaper = $optionalTotalSum / $optionalPaperCount;
            $optGradeInfo = calculateGradeFromTotal($avgTotalPerPaper);
            $optGpa = $optGradeInfo['points'];

            $subjects[] = [
                'subject' => $optionalPublicName ?: $student['optional_subject'],
                'total' => $optionalTotalSum,
                'grade' => $optGradeInfo['grade'],
                'gpa' => $optGpa,
                'status' => 'draft',
                'is_optional' => true,
                'paper_count' => $optionalPaperCount,
            ];

            $optionalGp = max($optGpa - 2, 0);
        }

        $gpaWithoutOptional = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;
        $overallGpa = $subjectCount > 0 ? round(($totalPoints + $optionalGp) / $subjectCount, 2) : 0;
        $overallGrade = calculateGradeFromGpa($overallGpa);

        $transcripts[] = [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'father_name' => $student['father_name'] ?? '',
            'mother_name' => $student['mother_name'] ?? '',
            'date_of_birth' => $student['date_of_birth'] ?? '',
            'registration_no' => '',
            'class' => $student['class'],
            'group' => $student['student_group'],
            'exam_name' => $examName,
            'year' => $year,
            'academic_session' => $student['academic_session'] ?? '',
            'student_type' => $student['student_type'] ?? '',
            'optional_subject' => $student['optional_subject'] ?? '',
            'published_at' => null,
            'subjects' => $subjects,
            'overall_gpa' => $overallGpa,
            'overall_grade' => $overallGrade,
            'gpa_without_optional' => $gpaWithoutOptional,
            'optional_gp_above_2' => round($optionalGp, 2),
            'total_subjects' => count($subjects),
        ];
    }

    Response::success($transcripts);
});

if (!function_exists('calculateGradeFromTotal')) {
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
}}


if (!function_exists('calculateGradeFromGpa')) {
function calculateGradeFromGpa(float $gpa): string
{
    if ($gpa >= 5.00) return 'A+';
    if ($gpa >= 4.00) return 'A';
    if ($gpa >= 3.50) return 'A-';
    if ($gpa >= 3.00) return 'B';
    if ($gpa >= 2.00) return 'C';
    if ($gpa >= 1.00) return 'D';
    return 'F';
}}

