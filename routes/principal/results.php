<?php

// GET /api/principal/results/publish   (rewritten Session 88)
// One card per student group (from the admin catalog). Each shows the newest exam that has APPROVED
// results for that group's students. Before: a hard-coded 3-department list and a join on teacher
// subjects that never matched stored result subjects ("Bangla - Bangla-2"), so it was always empty.
$router->get('/api/principal/results/publish', function () {
    Auth::requireRole('principal');
    $data = [];
    foreach (catalogGroupNames() as $group) {
        $exam = Database::fetch(
            "SELECT e.id, e.exam_name, e.class, e.year FROM exam_results er
             JOIN exams e ON e.id = er.exam_id JOIN students s ON s.id = er.student_id
             WHERE er.status = 'approved' AND s.student_group = ?
             GROUP BY e.id ORDER BY e.year DESC, e.id DESC LIMIT 1",
            [$group]
        );
        $results = $exam ? Database::fetchAll(
            "SELECT s.student_id, s.name, ROUND(SUM(er.total), 2) AS total_mark, ROUND(AVG(er.gpa), 2) AS gpa,
                    'approved' AS status, COUNT(*) AS subjects
             FROM exam_results er JOIN students s ON s.id = er.student_id
             WHERE er.exam_id = ? AND er.status = 'approved' AND s.student_group = ?
             GROUP BY s.id, s.student_id, s.name ORDER BY s.student_id",
            [$exam['id'], $group]
        ) : [];
        $data[] = [
            'department' => $group,
            'exam_name' => $exam['exam_name'] ?? '',
            'class' => $exam['class'] ?? '',
            'year' => $exam['year'] ?? '',
            'results' => $results,
        ];
    }
    Response::success($data);
});

if (!function_exists('principalPublishExam')) {
/** Newest exam with this name (+ class) that has results in the given status for the group. */
function principalPublishExam(string $examName, string $class, string $group, string $status): ?array
{
    return Database::fetch(
        "SELECT e.id, e.year FROM exams e
         WHERE e.exam_name = ? AND (? = '' OR e.class = ?)
           AND EXISTS (SELECT 1 FROM exam_results er JOIN students s ON s.id = er.student_id
                       WHERE er.exam_id = e.id AND er.status = ? AND (? = '' OR s.student_group = ?))
         ORDER BY e.year DESC, e.id DESC LIMIT 1",
        [$examName, $class, $class, $status, $group, $group]
    );
}}

// POST /api/principal/results/publish  { exam_name, class?, group? }
// Publishes the approved results of THAT group in the newest matching exam only (before: every
// approved result with that exam name, all classes / groups / years).
$router->post('/api/principal/results/publish', function () {
    $user = Auth::requireRole('principal');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $examName = trim((string)($data['exam_name'] ?? ''));
    $class = trim((string)($data['class'] ?? ''));
    $group = trim((string)($data['group'] ?? $data['department'] ?? ''));
    if ($examName === '') {
        Response::validationError(['exam_name is required']);
    }
    $exam = principalPublishExam($examName, $class, $group, 'approved');
    if (!$exam) {
        Response::success(['updated' => 0], 'No approved results to publish');
    }
    $stmt = Database::query(
        "UPDATE exam_results er JOIN students s ON s.id = er.student_id
         SET er.status = 'published', er.published_at = NOW(), er.published_by = ?
         WHERE er.exam_id = ? AND er.status = 'approved' AND (? = '' OR s.student_group = ?)",
        [$user['id'], $exam['id'], $group, $group]
    );
    Response::success(['updated' => $stmt->rowCount()], $stmt->rowCount() . ' results published');
});

// POST /api/principal/results/back-to-exam-controller  { exam_name, subject? , class?, group? }
// Year-safe (newest matching exam). Without a subject, the whole group's approved results go back.
$router->post('/api/principal/results/back-to-exam-controller', function () {
    Auth::requireRole('principal');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $examName = trim((string)($data['exam_name'] ?? ''));
    $subject = trim((string)($data['subject'] ?? ''));
    $class = trim((string)($data['class'] ?? ''));
    $group = trim((string)($data['group'] ?? ''));
    if ($examName === '') {
        Response::validationError(['exam_name is required']);
    }
    $exam = principalPublishExam($examName, $class, $group, 'approved');
    if (!$exam) {
        Response::success(['updated' => 0], 'Nothing to send back');
    }
    $stmt = Database::query(
        "UPDATE exam_results er JOIN students s ON s.id = er.student_id
         SET er.status = 'draft'
         WHERE er.exam_id = ? AND er.status = 'approved' AND (? = '' OR er.subject = ?) AND (? = '' OR s.student_group = ?)",
        [$exam['id'], $subject, $subject, $group, $group]
    );
    Response::success(['updated' => $stmt->rowCount()], $stmt->rowCount() . ' results returned to Exam Controller');
});
