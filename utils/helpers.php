<?php

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
        if ($total >= $g['min']) return $g;
    }
    return ['grade' => 'F', 'points' => 0.00];
}

function getDefaultSubjectParts(): array
{
    return [
        ['part_name' => 'mcq', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 1],
        ['part_name' => 'cq', 'full_mark' => 50, 'pass_mark' => 17, 'sort_order' => 2],
        ['part_name' => 'practical', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 3],
    ];
}

function getSubjectParts(string $subject): array
{
    $parts = Database::fetchAll(
        "SELECT part_name, full_mark, pass_mark, sort_order
         FROM subject_parts
         WHERE subject = ?
         ORDER BY sort_order",
        [$subject]
    );
    if (!empty($parts)) {
        return $parts;
    }
    return getDefaultSubjectParts();
}

function calculateGradeFromParts(array $parts, array $partConfigs, array $absentIn = []): array
{
    $hasAbsent = !empty($absentIn);
    $hasFail = false;
    $total = 0;

    foreach ($partConfigs as $config) {
        $partName = $config['part_name'];
        $mark = (float)($parts[$partName] ?? 0);

        if (in_array($partName, $absentIn)) {
            $hasAbsent = true;
        } elseif ($mark < (float)$config['pass_mark']) {
            $hasFail = true;
        }

        $total += $mark;
    }

    if ($hasAbsent) {
        return ['grade' => 'Absent', 'points' => 0.00];
    }

    if ($hasFail) {
        return ['grade' => 'F', 'points' => 0.00];
    }

    return calculateGradeFromTotal($total);
}

function calculateGradeFromMarks(float $mcq, float $cq, float $practical, array $absentIn = []): array
{
    $total = $mcq + $cq + $practical;
    $hasAbsent = !empty($absentIn);

    $mcqFail = !in_array('mcq', $absentIn) && $mcq < 8;
    $cqFail = !in_array('cq', $absentIn) && $cq < 17;
    $practicalFail = !in_array('practical', $absentIn) && $practical < 8;
    $belowThreshold = $mcqFail || $cqFail || $practicalFail;

    if ($hasAbsent) {
        return ['grade' => 'Absent', 'points' => 0.00];
    }

    if ($belowThreshold) {
        return ['grade' => 'F', 'points' => 0.00];
    }

    return calculateGradeFromTotal($total);
}

function logResultChange(int $examResultId, string $action, ?string $oldData, ?string $newData, int $userId): void
{
    Database::insert('result_changelog', [
        'exam_result_id' => $examResultId,
        'action' => $action,
        'old_data' => $oldData,
        'new_data' => $newData,
        'user_id' => $userId,
    ]);
}

function getTeacherSubjects(int $userId, string $type = 'public'): array
{
    if ($type === 'result') {
        $rows = Database::fetchAll(
            "SELECT ts.subject FROM teacher_subjects ts
             JOIN teachers t ON t.id = ts.teacher_id
             WHERE t.user_id = ? AND (ts.type = 'result' OR ts.type = 'public')",
            [$userId]
        );
    } else {
        $rows = Database::fetchAll(
            "SELECT ts.subject FROM teacher_subjects ts
             JOIN teachers t ON t.id = ts.teacher_id
             WHERE t.user_id = ? AND ts.type = ?",
            [$userId, $type]
        );
    }
    return array_map(fn($r) => $r['subject'], $rows);
}

function setTeacherSubjects(int $userId, array $subjects, string $type = 'public'): void
{
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$userId]);
    if (!$teacher) return;
    $teacherId = (int)$teacher['id'];

    Database::query("DELETE FROM teacher_subjects WHERE teacher_id = ? AND type = ?", [$teacherId, $type]);
    foreach ($subjects as $subject) {
        $subject = trim($subject);
        if ($subject !== '') {
            Database::insert('teacher_subjects', [
                'teacher_id' => $teacherId,
                'subject' => $subject,
                'type' => $type,
            ]);
        }
    }
}

function getTeacherResultSubjects(int $userId): array
{
    return getTeacherSubjects($userId, 'result');
}

function setTeacherResultSubjects(int $userId, array $subjects): void
{
    setTeacherSubjects($userId, $subjects, 'result');
}

function getResultSubjects(): array
{
    $rows = Database::fetchAll(
        "SELECT id, name, type FROM subjects WHERE type IN ('result','both') ORDER BY name"
    );
    return array_map(fn($r) => $r['name'], $rows);
}

function getPublicSubjects(): array
{
    $rows = Database::fetchAll(
        "SELECT id, name, type FROM subjects WHERE type IN ('public','both') ORDER BY name"
    );
    return array_map(fn($r) => $r['name'], $rows);
}

function ensureTeacherRecord(int $userId, array $data = []): int
{
    $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$userId]);
    if ($teacher) return (int)$teacher['id'];

    return Database::insert('teachers', [
        'user_id' => $userId,
        'name' => $data['name'] ?? '',
        'gender' => $data['gender'] ?? 'male',
        'designation' => $data['designation'] ?? 'Lecturer',
        'subject' => ($data['subjects'] ?? [])[0] ?? null,
        'joining_date' => $data['joining_date'] ?? date('Y-m-d'),
        'mobile' => $data['mobile'] ?? '',
        'email' => $data['email'] ?? '',
    ]);
}

function canUserAccessSubject(array $user, string $subject): bool
{
    if (in_array('admin', $user['roles'])) return true;
    if (in_array('exam_controller', $user['roles'])) return true;

    $userId = (int)$user['id'];
    $allSubjects = array_merge(
        getTeacherSubjects($userId, 'public'),
        getTeacherSubjects($userId, 'result')
    );
    return in_array($subject, $allSubjects);
}
