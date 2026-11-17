<?php

// POST /api/admin/transcript – generate student transcripts (admin & exam_controller)
$router->post('/api/admin/transcript', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);

    $data = json_decode(file_get_contents('php://input'), true);
    $year = $data['year'] ?? '';
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $studentIdFilter = trim((string)($data['student_id'] ?? ''));
    $groupFilter = trim((string)($data['group'] ?? ''));

    if (!$year || !$class || !$examName) {
        Response::validationError(['year, class, and exam_name are required']);
    }

    $exam = Database::fetch(
        "SELECT id, year, class, exam_name FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }

    // Build the whole class once: merit position is counted across the full exam (all groups),
    // then keep only the requested student / group (Session 75).
    $all = transcriptBuild($exam);
    $merit = transcriptMeritPositions($all);
    $transcripts = [];
    foreach ($all as $t) {
        if ($studentIdFilter !== '' && (string)$t['student_id'] !== (string)$studentIdFilter) continue;
        if ($groupFilter !== '' && (string)$t['group'] !== (string)$groupFilter) continue;
        $m = $merit[(string)$t['student_id']] ?? null;
        $t['position'] = $m['position'] ?? null;
        $t['total_marks'] = $m['total_marks'] ?? transcriptTotalMarks($t);
        $t['position_out_of'] = count($merit);
        $transcripts[] = $t;
    }

    // QR verification: signed token per student+exam, and whether admin enabled online verify.
    // Wrapped so a missing table/secret never breaks transcript generation itself.
    try {
        $enabled = transcriptVerifyEnabledSet((int)$exam['id']);
        foreach ($transcripts as &$t) {
            $t['exam_id'] = (int)$exam['id'];
            $t['verify_token'] = transcriptVerifyToken($t['student_id'], (int)$exam['id']);
            $t['verify_enabled'] = isset($enabled[$t['student_id']]);
        }
        unset($t);
    } catch (Throwable $e) {
        error_log('transcript verify meta failed: ' . $e->getMessage());
    }

    Response::success($transcripts);
});

/**
 * Build transcripts for one exam. $exam = row with id, year, class, exam_name.
 * $studentIdFilter / $groupFilter are optional. $ignoreClass lets the public QR
 * verifier find a student who has since moved class (e.g. 11th -> 12th).
 */
if (!function_exists('transcriptBuild')) {
function transcriptBuild(array $exam, string $studentIdFilter = '', string $groupFilter = '', bool $ignoreClass = false): array
{
    $examId = $exam['id'];
    $year = $exam['year'];
    $class = $exam['class'];
    $examName = $exam['exam_name'];

    // ---------- Build subject / paper lookup maps ----------
    $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY `group`, name");
    $subjectMap = [];          // subject id => subject row
    $paperToSubject = [];      // paper name => subject id
    $paperToSubjectName = [];  // paper name => subject name
    $subjectPapersMap = [];    // subject id => papers []

    $paperCode = [];           // paper name => code   (Session 69)
    $subjectCode = [];         // subject name => code
    foreach ($subjects as $s) {
        $subjectMap[$s['id']] = $s;
        $subjectCode[$s['name']] = $s['code'] ?? '';
        $papers = Database::fetchAll("SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name", [$s['id']]);
        $subjectPapersMap[$s['id']] = $papers;
        foreach ($papers as $p) {
            $paperToSubject[$p['name']] = $s['id'];
            $paperToSubjectName[$p['name']] = $s['name'];
            $paperCode[$p['name']] = $p['code'] ?? '';
        }
    }
    // "Bangla - Bangla-2" -> ['name' => 'Bangla-2', 'code' => '102']
    $resolveDisplay = function (string $raw) use ($paperCode, $subjectCode): array {
        $name = $raw;
        if (!isset($paperCode[$raw]) && strpos($raw, ' - ') !== false) {
            $name = trim(explode(' - ', $raw, 2)[1]);
        }
        $code = ($paperCode[$name] ?? '') ?: ($subjectCode[$name] ?? '') ?: ($subjectCode[$raw] ?? '');
        return ['name' => $name, 'code' => (string)$code];
    };

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
    $studentWhere = $ignoreClass ? '1 = 1' : 's.class = ?';
    $studentParams = $ignoreClass ? [] : [$class];
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
        return [];
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
        $optParts = [];
        $optDisplay = '';
        $optRow = null;

        // ----- Group non-optional results by subject (for averaging) -----
        $resultsBySubject = [];   // subject_id => [result rows]
        $singleRowResults = [];   // [{ display_name, result }] for separate display

        foreach ($studentResults as $r) {
            $paperName = $r['subject'];
            // Results may be stored as "Subject - Paper" (e.g. "Higher Mathematics - Higher Mathematics-2").
            // Resolve the real paper / subject name so optional + config lookups match (Session 69).
            $lookupPaper = $paperName;
            $lookupSubject = $paperName;
            if (!isset($paperToSubject[$paperName]) && strpos($paperName, ' - ') !== false) {
                [$lookupSubject, $lookupPaper] = array_map('trim', explode(' - ', $paperName, 2));
            }
            $subjectId = $paperToSubject[$lookupPaper] ?? null;
            $config = $configMap[$subjectId] ?? null;

            // ----- Optional subject -----
            $isOptionalPaper = $hasOptional && (
                (!empty($optionalPaperNames) && (in_array($paperName, $optionalPaperNames) || in_array($lookupPaper, $optionalPaperNames)))
                || $paperName === $student['optional_subject']
                || $lookupSubject === $student['optional_subject']
            );

            if ($isOptionalPaper) {
                $total = (float)($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
                $gpa = (float)($r['gpa'] ?? 0);
                $optionalTotalSum += $total;
                $optParts[] = transcriptParts($r);
                $optDisplay = $paperName;
                $optRow = $r;
                $optionalGpaSum += $gpa;
                $optionalPaperCount++;
                continue;
            }

            // ----- Exam subject config logic -----
            if ($config && $config['paper_selection'] === 'single') {
                // Show only the selected paper; display name = paper name
                $selectedPaperName = $selectedPaperNameBySubject[$subjectId] ?? $lookupPaper;
                if ($lookupPaper === $selectedPaperName || $paperName === $selectedPaperName) {
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
            $parts = transcriptParts($r);
            $abs = transcriptAbsentState([$parts]);
            if ($abs['any']) { $grade = 'F'; $gpa = 0.0; } // absent in any part = fail (same rule as result upload)

            $subjects[] = [
                'subject' => $resolveDisplay($row['display_name'])['name'],
                'code' => $resolveDisplay($row['display_name'])['code'],
                'parts' => $parts,
                'total' => $total,
                'grade' => $grade,
                'gpa' => $gpa,
                'absent' => $abs['all'],
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
            $partList = [];

            foreach ($papers as $r) {
                $total = (float)($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
                $gpa = (float)($r['gpa'] ?? 0);
                $partList[] = transcriptParts($r);
                $totalSum += $total;
                $gpaSum += $gpa;
            }

            $avgTotalPerPaper = $paperCount > 0 ? $totalSum / $paperCount : 0;
            $avgGradeInfo = calculateGradeFromTotal($avgTotalPerPaper);
            $abs = transcriptAbsentState($partList);
            if ($abs['any']) { $avgGradeInfo = ['grade' => 'F', 'points' => 0.0]; }

            $subjects[] = [
                'subject' => $subjectName,
                'code' => (string)($subjectMap[$subjectId]['code'] ?? ''),
                'parts' => transcriptPartsCombine($partList, $paperCount),
                'total' => $avgTotalPerPaper,
                'grade' => $avgGradeInfo['grade'],
                'gpa' => $avgGradeInfo['points'],
                'absent' => $abs['all'],
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
            // Single optional paper: use its stored grade, exactly like the main subjects
            // (keeps part-wise pass-mark failures as F). Several papers: averaged as before.
            if ($optionalPaperCount === 1 && $optRow && ($optRow['grade'] ?? '') !== '') {
                $optGradeInfo = ['grade' => $optRow['grade'], 'points' => (float)($optRow['gpa'] ?? 0)];
            }
            $optAbs = transcriptAbsentState($optParts);
            if ($optAbs['any']) { $optGradeInfo = ['grade' => 'F', 'points' => 0.0]; }
            $optGpa = $optGradeInfo['points'];

            $subjects[] = [
                // One paper -> show it like the other rows ("Subject - Paper"); several -> subject name
                'subject' => $optionalPaperCount === 1 && $optDisplay !== ''
                    ? $resolveDisplay($optDisplay)['name']
                    : ($optionalPublicName ?: $student['optional_subject']),
                'code' => $optionalPaperCount === 1 && $optDisplay !== ''
                    ? $resolveDisplay($optDisplay)['code']
                    : (string)($subjectCode[$optionalPublicName ?: $student['optional_subject']] ?? ''),
                'parts' => transcriptPartsCombine($optParts),
                'total' => $optionalTotalSum,
                'grade' => $optGradeInfo['grade'],
                'gpa' => $optGpa,
                'absent' => $optAbs['all'],
                'status' => 'draft',
                'is_optional' => true,
                'paper_count' => $optionalPaperCount,
            ];

            $optionalGp = max($optGpa - 2, 0);
        }

        // Session 93: compulsory subjects first — Bangla, English, ICT — then the rest in their
        // usual order; the optional subject stays last. Stable (papers keep 1st → 2nd order).
        $subjects = transcriptOrderSubjects($subjects);

        $gpaWithoutOptional = $subjectCount > 0 ? round($totalPoints / $subjectCount, 2) : 0;
        // GPA can never exceed 5.00, even with the optional-subject bonus (Session 69)
        $overallGpa = $subjectCount > 0 ? min(5.00, round(($totalPoints + $optionalGp) / $subjectCount, 2)) : 0;
        $overallGrade = calculateGradeFromGpa($overallGpa);

        // Board rule (Session 70): F / Absent in any compulsory (non-optional) subject
        // means the whole result is F with GPA 0.00. An F in the optional subject does not.
        foreach ($subjects as $s) {
            if (empty($s['is_optional']) && in_array(strtoupper((string)$s['grade']), ['F', 'ABSENT'], true)) {
                $overallGpa = 0.00;
                $gpaWithoutOptional = 0.00;
                $overallGrade = 'F';
                break;
            }
        }

        $custom = json_decode((string)($student['custom_fields'] ?? ''), true);
        $custom = is_array($custom) ? array_filter($custom, fn($v) => is_scalar($v) && trim((string)$v) !== '') : [];
        $transcripts[] = [
            'student_id' => $student['student_id'],
            'name' => $student['name'],
            'photo_path' => $student['photo_path'] ?? null,
            'father_name' => $student['father_name'] ?? '',
            'mother_name' => $student['mother_name'] ?? '',
            'date_of_birth' => $student['date_of_birth'] ?? '',
            'registration_no' => '',
            'class' => $student['class'],
            'group' => $student['student_group'],
            'class_roll' => $student['class_roll'] ?? '',
            'exam_name' => $examName,
            'year' => $year,
            // Session 84: "Academic Session" comes from the custom field "Session" (e.g. 2025-26). The
            // students.academic_session column holds the HSC batch year (e.g. 2027), shown separately.
            'academic_session' => transcriptCustomValue($custom, ['Session', 'Academic Session']),
            'hsc_batch' => $student['academic_session'] ?? '',
            'custom' => (object)$custom,
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

    return $transcripts;
}}


if (!function_exists('transcriptParts')) {
/**
 * Part marks of one result row (Session 69).
 * Each value: number, 'A' (absent in that part) or null (subject has no such part).
 * parts_data (e.g. {"CQ":32}) tells which parts exist; old rows without it fall back to the columns.
 */
/** 0 = Bangla, 1 = English, 2 = ICT, 3 = any other subject, 4 = optional subject. */
if (!function_exists('transcriptSubjectRank')) {
function transcriptSubjectRank(array $s): int
{
    if (!empty($s['is_optional'])) return 4;
    $name = strtolower(trim((string)($s['subject'] ?? '')));
    $code = trim((string)($s['code'] ?? ''));
    if (preg_match('/^(bangla|bengali|বাংলা)/u', $name) || in_array($code, ['101', '102'], true)) return 0;
    if (preg_match('/^english/', $name) || in_array($code, ['107', '108'], true)) return 1;
    if (preg_match('/^(information\s*(and|&)\s*communication\s*technology|ict\b)/', $name) || $code === '275') return 2;
    return 3;
}}

if (!function_exists('transcriptOrderSubjects')) {
function transcriptOrderSubjects(array $subjects): array
{
    $keyed = [];
    foreach (array_values($subjects) as $i => $s) $keyed[] = [transcriptSubjectRank($s), $i, $s];
    usort($keyed, fn($a, $b) => $a[0] <=> $b[0] ?: $a[1] <=> $b[1]);
    return array_column($keyed, 2);
}}

function transcriptParts(array $r): array
{
    $out = ['cq' => null, 'mcq' => null, 'practical' => null];
    $parts = json_decode((string)($r['parts_data'] ?? ''), true);
    $absent = json_decode((string)($r['absent_in'] ?? ''), true);
    $absent = is_array($absent) ? array_map('strtolower', $absent) : [];

    if (is_array($parts) && $parts) {
        foreach ($parts as $name => $val) {
            $key = strtolower(trim((string)$name));
            if (!array_key_exists($key, $out)) continue;
            $out[$key] = in_array($key, $absent, true) ? 'A' : (float)$val;
        }
        return $out;
    }
    foreach ($out as $key => $_) {
        $v = (float)($r[$key] ?? 0);
        $out[$key] = $v > 0 ? $v : null;
    }
    return $out;
}}

if (!function_exists('transcriptCustomValue')) {
/** First non-empty custom-field value among these labels (case-insensitive). */
function transcriptCustomValue(array $custom, array $labels): string
{
    foreach ($labels as $l) {
        foreach ($custom as $k => $v) {
            if (strcasecmp(trim((string)$k), $l) === 0 && trim((string)$v) !== '') return trim((string)$v);
        }
    }
    return '';
}}

if (!function_exists('transcriptTotalMarks')) {
/** Sum of subject totals (absent subjects count 0). */
function transcriptTotalMarks(array $t): float
{
    $sum = 0.0;
    foreach ($t['subjects'] ?? [] as $s) {
        $sum += !empty($s['absent']) ? 0 : (float)$s['total'];
    }
    return round($sum, 2);
}}

if (!function_exists('transcriptMeritPositions')) {
/**
 * Merit order for one exam (Session 75; shared by transcript + result summary).
 * Only passed students get a position: GPA desc, then total marks desc;
 * equal GPA + equal total share a position (1, 1, 3 ...).
 * Failed students follow (no position) by total marks desc, then fewest failed subjects.
 * Students with no subjects are ignored.
 * Returns student_id => ['position' => int|null, 'merit_index' => int, 'total_marks' => float]
 */
function transcriptMeritPositions(array $transcripts): array
{
    $list = [];
    foreach ($transcripts as $t) {
        if (empty($t['subjects'])) continue;
        $failed = 0;
        foreach ($t['subjects'] as $s) {
            if (empty($s['is_optional']) && strtoupper((string)$s['grade']) === 'F') $failed++;
        }
        $list[] = [
            'id' => (string)$t['student_id'],
            'gpa' => (float)$t['overall_gpa'],
            'total' => transcriptTotalMarks($t),
            'passed' => strtoupper((string)$t['overall_grade']) !== 'F',
            'failed' => $failed,
        ];
    }
    usort($list, function ($a, $b) {
        if ($a['passed'] !== $b['passed']) return $a['passed'] ? -1 : 1;
        if ($a['passed'] && $a['gpa'] != $b['gpa']) return $a['gpa'] < $b['gpa'] ? 1 : -1;
        if ($a['total'] != $b['total']) return $a['total'] < $b['total'] ? 1 : -1;
        if (!$a['passed'] && $a['failed'] !== $b['failed']) return $a['failed'] - $b['failed'];
        return strcmp($a['id'], $b['id']);
    });
    $out = [];
    $pos = 0;
    $prev = null;
    foreach ($list as $i => $x) {
        $position = null;
        if ($x['passed']) {
            $key = $x['gpa'] . '|' . $x['total'];
            if ($key !== $prev) { $pos = $i + 1; $prev = $key; }
            $position = $pos;
        }
        $out[$x['id']] = ['position' => $position, 'merit_index' => $i, 'total_marks' => $x['total']];
    }
    return $out;
}}

if (!function_exists('transcriptAbsentState')) {
/** any = absent in at least one part; all = absent in every existing part (whole subject absent). */
function transcriptAbsentState(array $list): array
{
    $present = 0; $absent = 0;
    foreach ($list as $p) {
        foreach ($p as $v) {
            if ($v === null) continue;
            if ($v === 'A') $absent++; else $present++;
        }
    }
    return ['any' => $absent > 0, 'all' => $absent > 0 && $present === 0];
}}

if (!function_exists('transcriptPartsCombine')) {
/** Sum (or average when $divide > 1) the part marks of several papers. */
function transcriptPartsCombine(array $list, int $divide = 1): array
{
    $out = ['cq' => null, 'mcq' => null, 'practical' => null];
    foreach ($out as $key => $_) {
        $sum = null;
        foreach ($list as $p) {
            if ($p[$key] === null) continue;
            if ($p[$key] === 'A') { $sum = $sum ?? 0; continue; }
            $sum = ($sum ?? 0) + $p[$key];
        }
        $out[$key] = $sum === null ? null : ($divide > 1 ? $sum / $divide : $sum);
    }
    return $out;
}}

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

