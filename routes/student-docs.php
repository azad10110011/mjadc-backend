<?php

// Student images (Session 123): money receipts, admission forms … stored under a student.
//  - View:   admin + Administration users (permission "/p_F7c2j/student-images", on by default; admin can turn
//            it off per user in User Permissions).
//  - Upload: admin + Administration users (permission "/p_F7c2j/student-images#upload", on by default) — the
//            upload screen is shown in the mobile app (camera or gallery).
//  - Edit / delete: admin only.
// Files are private (uploads/private/student-docs) and only sent through GET /api/student-docs/file/{id}.
// Fixed paths are registered before /{id} (the router takes the first match).

if (!function_exists('studentDocsEnsureSchema')) {
    function studentDocsEnsureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        Database::query(
            "CREATE TABLE IF NOT EXISTS student_documents (
                id INT AUTO_INCREMENT PRIMARY KEY,
                student_id INT NOT NULL,
                doc_type VARCHAR(60) NOT NULL,
                note VARCHAR(255) NULL,
                file_path VARCHAR(255) NOT NULL,
                uploaded_by INT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                KEY idx_student (student_id),
                CONSTRAINT fk_student_documents_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('auditTrackTable')) auditTrackTable('student_documents'); // Update History covers it at once (Session 121)
    }

    function studentDocsIsAdmin(array $u): bool
    {
        return in_array('admin', (array)($u['roles'] ?? []), true);
    }

    function studentDocsOut(array $r): array
    {
        return [
            'id' => (int)$r['id'], 'student_id' => (int)$r['student_id'], 'doc_type' => $r['doc_type'], 'note' => $r['note'],
            'uploaded_by' => $r['uploaded_by'] !== null ? (int)$r['uploaded_by'] : null, 'uploader' => $r['uploader'] ?? null,
            'created_at' => $r['created_at'],
        ];
    }

    define('STUDENT_DOC_TYPES_DEFAULT', ['Money Receipt', 'Admission Form', 'Testimonial', 'Mark Sheet', 'Birth Certificate', 'Other']);
}

// GET /api/student-docs/students?q= — find a student (ID, name, mobile)
$router->get('/api/student-docs/students', function () {
    Auth::requireAnyRole(['admin', 'administration']);
    studentDocsEnsureSchema();
    $q = trim((string)($_GET['q'] ?? ''));
    $params = [];
    $where = "s.class <> 'Old'";
    if ($q !== '') {
        $where = '(s.student_id LIKE ? OR s.name LIKE ? OR s.mobile LIKE ?)';
        $like = '%' . $q . '%';
        $params = [$like, $like, $like];
    }
    $rows = Database::fetchAll(
        "SELECT s.id, s.student_id, s.name, s.class, s.student_group, s.photo_path,
                (SELECT COUNT(*) FROM student_documents d WHERE d.student_id = s.id) AS docs
         FROM students s WHERE $where ORDER BY s.sort_order, s.student_id LIMIT 40",
        $params
    );
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['docs'] = (int)$r['docs']; }
    unset($r);
    Response::success($rows);
});

// GET /api/student-docs/types — suggestions for the type list
$router->get('/api/student-docs/types', function () {
    Auth::requireAnyRole(['admin', 'administration']);
    studentDocsEnsureSchema();
    $used = array_column(Database::fetchAll("SELECT DISTINCT doc_type FROM student_documents ORDER BY doc_type"), 'doc_type');
    Response::success(array_values(array_unique(array_merge(STUDENT_DOC_TYPES_DEFAULT, $used))));
});

// GET /api/student-docs/file/{id} — the picture itself (private)
$router->get('/api/student-docs/file/{id}', function (array $params) {
    Auth::requireAnyRole(['admin', 'administration']);
    studentDocsEnsureSchema();
    $r = Database::fetch("SELECT file_path FROM student_documents WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('Picture not found');
    privateServe($r['file_path']);
});

// GET /api/student-docs?student_id= — one student's pictures
$router->get('/api/student-docs', function () {
    Auth::requireAnyRole(['admin', 'administration']);
    studentDocsEnsureSchema();
    $sid = (int)($_GET['student_id'] ?? 0);
    if (!$sid) Response::validationError(['student_id' => 'Choose a student']);
    $student = Database::fetch("SELECT id, student_id, name, class, student_group, photo_path FROM students WHERE id = ?", [$sid]);
    if (!$student) Response::notFound('Student not found');
    $rows = Database::fetchAll(
        "SELECT d.*, u.name AS uploader FROM student_documents d LEFT JOIN users u ON u.id = d.uploaded_by
         WHERE d.student_id = ? ORDER BY d.created_at DESC, d.id DESC",
        [$sid]
    );
    Response::success(['student' => $student, 'documents' => array_map('studentDocsOut', $rows)]);
});

// POST /api/student-docs (multipart: student_id, doc_type, note?, image) — upload
$router->post('/api/student-docs', function () {
    $user = Auth::requireAnyRole(['admin', 'administration']);
    studentDocsEnsureSchema();
    $sid = (int)($_POST['student_id'] ?? 0);
    $type = trim((string)($_POST['doc_type'] ?? ''));
    $note = trim((string)($_POST['note'] ?? ''));
    if (!$sid || !Database::fetch("SELECT 1 FROM students WHERE id = ?", [$sid])) Response::validationError(['student_id' => 'Choose a student']);
    if ($type === '' || mb_strlen($type) > 60) Response::validationError(['doc_type' => 'Choose what this picture is (e.g. Money Receipt)']);
    $path = privateSaveImage($_FILES['image'] ?? null, 'student-docs', 's' . $sid);
    $id = Database::insert('student_documents', [
        'student_id' => $sid, 'doc_type' => $type, 'note' => $note !== '' ? mb_substr($note, 0, 255) : null,
        'file_path' => $path, 'uploaded_by' => (int)$user['id'],
    ]);
    Response::created(['id' => $id], 'Picture saved under the student');
});

// PUT /api/student-docs/{id} { doc_type?, note? } — admin only
$router->put('/api/student-docs/{id}', function (array $params) {
    $user = Auth::requireAnyRole(['admin', 'administration']);
    if (!studentDocsIsAdmin($user)) Response::forbidden('Only an admin can edit saved pictures.');
    studentDocsEnsureSchema();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $set = [];
    if (isset($data['doc_type'])) {
        $t = trim((string)$data['doc_type']);
        if ($t === '' || mb_strlen($t) > 60) Response::validationError(['doc_type' => 'Type is required']);
        $set['doc_type'] = $t;
    }
    if (array_key_exists('note', $data)) $set['note'] = trim((string)$data['note']) !== '' ? mb_substr(trim((string)$data['note']), 0, 255) : null;
    if (!$set) Response::validationError(['Nothing to change']);
    if (!Database::update('student_documents', $set, 'id = ?', ['id' => (int)$params['id']]) && !Database::fetch("SELECT 1 FROM student_documents WHERE id = ?", [(int)$params['id']])) {
        Response::notFound('Picture not found');
    }
    Response::success(null, 'Saved');
});

// DELETE /api/student-docs/{id} — admin only
$router->delete('/api/student-docs/{id}', function (array $params) {
    $user = Auth::requireAnyRole(['admin', 'administration']);
    if (!studentDocsIsAdmin($user)) Response::forbidden('Only an admin can delete saved pictures.');
    studentDocsEnsureSchema();
    $r = Database::fetch("SELECT file_path FROM student_documents WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('Picture not found');
    Database::query("DELETE FROM student_documents WHERE id = ?", [(int)$params['id']]);
    privateDelete($r['file_path']);
    Response::success(null, 'Picture deleted');
});
