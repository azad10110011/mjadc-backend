<?php

// Admin -> Exam Management -> Result Summary (Session 73)
// POST /api/admin/result-summary  {year, class, exam_name, group?}
// Uses transcriptBuild() (routes/admin/transcript.php) so GPA / fail / absent / optional
// rules are exactly the same as the transcript.
//
// Merit position: transcriptMeritPositions() — counted across the whole exam (all groups),
// so it matches the transcript. Only passed students get a position (GPA desc, then total marks desc;
// equal GPA + equal total share a position). Failed students have no position and come after.

$router->post('/api/admin/result-summary', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $year = trim((string)($data['year'] ?? ''));
    $class = trim((string)($data['class'] ?? ''));
    $examName = trim((string)($data['exam_name'] ?? ''));
    $group = trim((string)($data['group'] ?? ''));

    if ($year === '' || $class === '' || $examName === '') {
        Response::validationError(['year, class, and exam_name are required']);
    }

    $exam = Database::fetch(
        "SELECT id, year, class, exam_name FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }

    // Position is counted across the whole exam (all groups), same as the transcript (Session 75)
    $all = transcriptBuild($exam);
    $merit = transcriptMeritPositions($all);

    $rows = [];
    $noResult = 0;
    foreach ($all as $t) {
        if ($group !== '' && (string)$t['group'] !== $group) continue;
        if (empty($t['subjects'])) { $noResult++; continue; }

        $totalMarks = 0.0;
        $failedSubjects = [];
        $subjects = [];
        foreach ($t['subjects'] as $s) {
            $absent = !empty($s['absent']);
            $totalMarks += $absent ? 0 : (float)$s['total'];
            $isOpt = !empty($s['is_optional']);
            if (!$isOpt && strtoupper((string)$s['grade']) === 'F') {
                $failedSubjects[] = $s['subject'];
            }
            $subjects[] = [
                'subject' => $s['subject'],
                'code' => $s['code'] ?? '',
                'total' => round((float)$s['total'], 2),
                'grade' => $s['grade'],
                'gpa' => (float)$s['gpa'],
                'absent' => $absent,
                'is_optional' => $isOpt,
            ];
        }

        $rows[] = [
            'student_id' => $t['student_id'],
            'name' => $t['name'],
            'group' => $t['group'],
            'class_roll' => $t['class_roll'] ?? '',
            'subjects' => $subjects,
            'total_marks' => round($totalMarks, 2),
            'gpa' => (float)$t['overall_gpa'],
            'gpa_without_optional' => (float)$t['gpa_without_optional'],
            'grade' => $t['overall_grade'],
            'passed' => strtoupper((string)$t['overall_grade']) !== 'F',
            'failed_subjects' => $failedSubjects,
            'position' => $merit[(string)$t['student_id']]['position'] ?? null,
            'merit_index' => $merit[(string)$t['student_id']]['merit_index'] ?? PHP_INT_MAX,
        ];
    }

    // Merit order (positions come from transcriptMeritPositions above)
    usort($rows, fn ($a, $b) => $a['merit_index'] <=> $b['merit_index']);

    // ----- Statistics -----
    $gradeDist = ['A+' => 0, 'A' => 0, 'A-' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
    $byGroup = [];
    $passed = 0;
    foreach ($rows as $r) {
        $g = strtoupper($r['grade']) === 'A+' ? 'A+' : $r['grade'];
        if (isset($gradeDist[$g])) $gradeDist[$g]++;
        if ($r['passed']) $passed++;
        $gk = $r['group'] ?: 'N/A';
        $byGroup[$gk] = $byGroup[$gk] ?? ['group' => $gk, 'appeared' => 0, 'passed' => 0, 'failed' => 0, 'gpa5' => 0];
        $byGroup[$gk]['appeared']++;
        $byGroup[$gk][$r['passed'] ? 'passed' : 'failed']++;
        if ($r['gpa'] >= 5.0) $byGroup[$gk]['gpa5']++;
    }
    $appeared = count($rows);

    Response::success([
        'exam' => ['id' => (int)$exam['id'], 'year' => $exam['year'], 'class' => $exam['class'], 'exam_name' => $exam['exam_name']],
        'group' => $group,
        'stats' => [
            'registered' => $appeared + $noResult,
            'appeared' => $appeared,
            'no_result' => $noResult,
            'passed' => $passed,
            'failed' => $appeared - $passed,
            'pass_rate' => $appeared > 0 ? round($passed * 100 / $appeared, 2) : 0,
            'gpa5' => $gradeDist['A+'],
            'grade_distribution' => $gradeDist,
            'by_group' => array_values($byGroup),
        ],
        'rows' => $rows,
    ]);
});
