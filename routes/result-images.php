<?php

// Result images (Session 123): a teacher photographs result sheets in the mobile app (camera or gallery)
// and saves them against an exam + subject.
//  - Teachers: upload, see and delete THEIR OWN pictures (only for subjects assigned to them).
//  - Exam Controller + admin: see everyone's; admin can delete any.
// Files are private (uploads/private/result-images) and sent only through GET /api/result-images/file/{id}.
// Fixed paths are registered before /{id}.

if (!function_exists('resultImagesEnsureSchema')) {
    function resultImagesEnsureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        Database::query(
            "CREATE TABLE IF NOT EXISTS result_images (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                year INT NOT NULL,
                class VARCHAR(20) NOT NULL,
                exam_name VARCHAR(100) NOT NULL,
                subject VARCHAR(150) NOT NULL,
                note VARCHAR(255) NULL,
                file_path VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_exam (year, class, exam_name),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('auditTrackTable')) auditTrackTable('result_images'); // Update History covers it at once (Session 121)
    }

    /** Can this user see everyone's result images? */
    function resultImagesSeeAll(array $u): bool
    {
        return (bool)array_intersect(['admin', 'exam_controller'], (array)($u['roles'] ?? []));
    }

    function resultImagesOut(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'user_id' => (int)$r['user_id'], 'teacher' => $r['teacher'] ?? null,
            'year' => (int)$r['year'], 'class' => $r['class'], 'exam_name' => $r['exam_name'], 'subject' => $r['subject'],
            'note' => $r['note'], 'created_at' => $r['created_at'],
        ];
    }
}

// GET /api/result-images/file/{id}
$router->get('/api/result-images/file/{id}', function (array $params) {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);
    resultImagesEnsureSchema();
    $r = Database::fetch("SELECT user_id, file_path FROM result_images WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('Picture not found');
    if (!resultImagesSeeAll($user) && (int)$r['user_id'] !== (int)$user['id']) Response::forbidden('Not your picture');
    privateServe($r['file_path']);
});

// GET /api/result-images?year=&class=&exam_name=&subject=&user_id=
$router->get('/api/result-images', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);
    resultImagesEnsureSchema();
    $where = [];
    $p = [];
    foreach (['year' => 'ri.year', 'class' => 'ri.class', 'exam_name' => 'ri.exam_name', 'subject' => 'ri.subject'] as $k => $col) {
        if (($_GET[$k] ?? '') !== '') { $where[] = "$col = ?"; $p[] = (string)$_GET[$k]; }
    }
    if (!resultImagesSeeAll($user)) {
        $where[] = 'ri.user_id = ?';
        $p[] = (int)$user['id'];
    } elseif (($_GET['user_id'] ?? '') !== '') {
        $where[] = 'ri.user_id = ?';
        $p[] = (int)$_GET['user_id'];
    }
    $sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $rows = Database::fetchAll(
        "SELECT ri.*, u.name AS teacher FROM result_images ri LEFT JOIN users u ON u.id = ri.user_id $sql
         ORDER BY ri.created_at DESC, ri.id DESC LIMIT 500",
        $p
    );
    Response::success(array_map('resultImagesOut', $rows));
});

// POST /api/result-images (multipart: year, class, exam_name, subject, note?, image)
$router->post('/api/result-images', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);
    resultImagesEnsureSchema();
    $year = (int)($_POST['year'] ?? 0);
    $class = trim((string)($_POST['class'] ?? ''));
    $exam = trim((string)($_POST['exam_name'] ?? ''));
    $subject = trim((string)($_POST['subject'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if ($year < 2000 || $year > 2100 || $class === '' || $exam === '' || $subject === '') {
        Response::validationError(['Choose year, class, exam and subject']);
    }
    // a teacher may only upload for subjects assigned to them
    $assigned = function_exists('teacherResultAssignedSubjects') ? teacherResultAssignedSubjects($user) : null;
    if ($assigned !== null && !resultImagesSeeAll($user)) {
        $ok = false;
        foreach ($assigned as $a) if (teacherResultSameSubject($a, $subject) || teacherResultSameSubject($a, explode(' - ', $subject)[0])) { $ok = true; break; }
        if (!$ok) Response::forbidden('This subject is not assigned to you.');
    }
    $path = privateSaveImage($_FILES['image'] ?? null, 'result-images', 'u' . (int)$user['id']);
    $id = Database::insert('result_images', [
        'user_id' => (int)$user['id'], 'year' => $year, 'class' => mb_substr($class, 0, 20), 'exam_name' => mb_substr($exam, 0, 100),
        'subject' => mb_substr($subject, 0, 150), 'note' => $note !== '' ? mb_substr($note, 0, 255) : null, 'file_path' => $path,
    ]);
    Response::created(['id' => $id], 'Result picture saved');
});

// DELETE /api/result-images/{id} — the uploader or an admin
$router->delete('/api/result-images/{id}', function (array $params) {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller', 'admin']);
    resultImagesEnsureSchema();
    $r = Database::fetch("SELECT user_id, file_path FROM result_images WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('Picture not found');
    $isAdmin = in_array('admin', (array)$user['roles'], true);
    if (!$isAdmin && (int)$r['user_id'] !== (int)$user['id']) Response::forbidden('You can delete only your own pictures.');
    Database::query("DELETE FROM result_images WHERE id = ?", [(int)$params['id']]);
    privateDelete($r['file_path']);
    Response::success(null, 'Picture deleted');
});
