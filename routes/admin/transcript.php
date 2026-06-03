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

    // Build student query
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

    // Get all results for this exam
    $results = Database::fetchAll(
        "SELECT er.*, e.exam_name, e.year
         FROM exam_results er
         JOIN exams e ON er.exam_id = e.id
         WHERE er.exam_id = ?
         ORDER BY er.student_id, er.subject",
        [$examId]
    );

    // Group results by student_id
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

        foreach ($studentResults as $r) {
            $gpa = (float) ($r['gpa'] ?? 0);
            $total = (float) ($r['total'] ?? ($r['mcq'] + $r['cq'] + $r['practical']));
            $grade = $r['grade'] ?? 'F';

            $subjects[] = [
                'subject' => $r['subject'],
                'total' => $total,
                'grade' => $grade,
                'gpa' => $gpa,
                'status' => $r['status'],
            ];

            if ($hasOptional && $r['subject'] === $student['optional_subject']) {
                $optionalGp = max($gpa - 2, 0);
            } else {
                $totalPoints += $gpa;
                $subjectCount++;
            }
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

function calculateGradeFromGpa(float $gpa): string
{
    if ($gpa >= 5.00) return 'A+';
    if ($gpa >= 4.00) return 'A';
    if ($gpa >= 3.50) return 'A-';
    if ($gpa >= 3.00) return 'B';
    if ($gpa >= 2.00) return 'C';
    if ($gpa >= 1.00) return 'D';
    return 'F';
}
