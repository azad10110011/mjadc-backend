<?php
// Transcript QR verification.
//  - Every printed transcript carries a QR -> /verify/transcript/?id=<student_id>&exam=<exam_id>&t=<token>
//  - <token> is an HMAC of student_id|exam_id, so QR links cannot be guessed by walking student IDs.
//  - Admin decides per student per exam whether online verification is ON
//    (row in transcript_verifications). OFF -> scanner sees "contact Exam office".
//
// Requires routes/admin/transcript.php (transcriptBuild) to be loaded first.

if (!defined('TRANSCRIPT_VERIFY_OFF_MSG')) define('TRANSCRIPT_VERIFY_OFF_MSG', 'Please contact Exam office to online verify your Transcript.');

// Tables are created on demand so a missed SQL step on live can't break anything
// (same SQL is kept in database/migration_025_transcript_verify.sql).
if (!function_exists('transcriptVerifyEnsureSchema')) {
function transcriptVerifyEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    Database::query(
        "CREATE TABLE IF NOT EXISTS transcript_verifications (
            id INT AUTO_INCREMENT PRIMARY KEY,
            student_id INT NOT NULL,
            exam_id INT NOT NULL,
            enabled_by INT NULL,
            enabled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_student_exam (student_id, exam_id),
            KEY idx_exam (exam_id)
        )"
    );
    // Kept out of site_settings on purpose: /api/settings/{key} is public.
    Database::query(
        "CREATE TABLE IF NOT EXISTS app_secrets (
            name VARCHAR(64) PRIMARY KEY,
            value VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $done = true;
}}

if (!function_exists('transcriptVerifySecret')) {
function transcriptVerifySecret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;
    transcriptVerifyEnsureSchema();
    // INSERT IGNORE + re-read: two first requests at once still agree on one secret.
    Database::query(
        "INSERT IGNORE INTO app_secrets (name, value) VALUES ('transcript_verify', ?)",
        [bin2hex(random_bytes(32))]
    );
    $row = Database::fetch("SELECT value FROM app_secrets WHERE name = 'transcript_verify'");
    $secret = $row['value'];
    return $secret;
}}

if (!function_exists('transcriptVerifyToken')) {
function transcriptVerifyToken(string $studentCode, int $examId): string
{
    return substr(hash_hmac('sha256', $studentCode . '|' . $examId, transcriptVerifySecret()), 0, 20);
}}

// student_id code (e.g. "271001") => enabled_at, for one exam
if (!function_exists('transcriptVerifyEnabledSet')) {
function transcriptVerifyEnabledSet(int $examId): array
{
    transcriptVerifyEnsureSchema();
    $rows = Database::fetchAll(
        "SELECT s.student_id AS code, tv.enabled_at
         FROM transcript_verifications tv
         JOIN students s ON s.id = tv.student_id
         WHERE tv.exam_id = ?",
        [$examId]
    );
    $set = [];
    foreach ($rows as $r) $set[$r['code']] = $r['enabled_at'];
    return $set;
}}

// Students eligible for an exam: currently in the exam's class, or holding results for it
// (covers last year's 11th exam after the class moved up to 12th).
if (!function_exists('transcriptVerifyStudents')) {
function transcriptVerifyStudents(array $exam, string $group = ''): array
{
    $sql = "SELECT s.id, s.student_id, s.name, s.class, s.student_group, s.class_roll
            FROM students s
            WHERE (s.class = ? OR s.id IN (SELECT DISTINCT er.student_id FROM exam_results er WHERE er.exam_id = ?))";
    $params = [$exam['class'], $exam['id']];
    if ($group !== '') {
        $sql .= " AND s.student_group = ?";
        $params[] = $group;
    }
    $sql .= " ORDER BY CASE s.student_group WHEN 'Science' THEN 1 WHEN 'Business Studies' THEN 2 WHEN 'Humanities' THEN 3 ELSE 4 END,
              CAST(s.class_roll AS UNSIGNED), s.class_roll, s.student_id";
    return Database::fetchAll($sql, $params);
}}

if (!function_exists('transcriptVerifyFindExam')) {
function transcriptVerifyFindExam(string $year, string $class, string $examName): array
{
    if ($year === '' || $class === '' || $examName === '') {
        Response::validationError(['year, class, and exam_name are required']);
    }
    $exam = Database::fetch(
        "SELECT id, year, class, exam_name FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) Response::notFound('Exam not found');
    return $exam;
}}

// GET /api/admin/transcript-verify?year=&class=&exam_name=&group=
$router->get('/api/admin/transcript-verify', function () {
    Auth::requireRole('admin');
    $exam = transcriptVerifyFindExam(
        trim($_GET['year'] ?? ''),
        trim($_GET['class'] ?? ''),
        trim($_GET['exam_name'] ?? '')
    );
    $enabled = transcriptVerifyEnabledSet((int)$exam['id']);
    $out = [];
    foreach (transcriptVerifyStudents($exam, trim($_GET['group'] ?? '')) as $s) {
        $out[] = [
            'id' => (int)$s['id'],
            'student_id' => $s['student_id'],
            'name' => $s['name'],
            'class' => $s['class'],
            'group' => $s['student_group'],
            'class_roll' => $s['class_roll'],
            'enabled' => isset($enabled[$s['student_id']]),
            'enabled_at' => $enabled[$s['student_id']] ?? null,
        ];
    }
    Response::success(['exam_id' => (int)$exam['id'], 'students' => $out]);
});

// POST /api/admin/transcript-verify
// { year, class, exam_name, enabled: bool, all?: bool, group?: string, student_ids?: int[] (students.id) }
$router->post('/api/admin/transcript-verify', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $exam = transcriptVerifyFindExam(
        trim((string)($data['year'] ?? '')),
        trim((string)($data['class'] ?? '')),
        trim((string)($data['exam_name'] ?? ''))
    );
    $examId = (int)$exam['id'];
    $enable = !empty($data['enabled']);

    // Only ever touch students eligible for this exam, whatever ids the client sends.
    $eligible = array_map('intval', array_column(
        transcriptVerifyStudents($exam, !empty($data['all']) ? trim((string)($data['group'] ?? '')) : ''),
        'id'
    ));
    if (!empty($data['all'])) {
        $ids = $eligible;
    } else {
        $requested = array_map('intval', (array)($data['student_ids'] ?? []));
        $ids = array_values(array_intersect($eligible, $requested));
    }
    if (empty($ids)) {
        Response::error('No students selected', 422);
    }

    transcriptVerifyEnsureSchema();
    $changed = 0;
    foreach (array_chunk($ids, 200) as $chunk) {
        if ($enable) {
            $ph = implode(',', array_fill(0, count($chunk), '(?, ?, ?)'));
            $params = [];
            foreach ($chunk as $sid) array_push($params, $sid, $examId, $user['id'] ?? null);
            $changed += Database::query(
                "INSERT IGNORE INTO transcript_verifications (student_id, exam_id, enabled_by) VALUES $ph",
                $params
            )->rowCount();
        } else {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $changed += Database::query(
                "DELETE FROM transcript_verifications WHERE exam_id = ? AND student_id IN ($ph)",
                array_merge([$examId], $chunk)
            )->rowCount();
        }
    }
    Response::success(['changed' => $changed, 'requested' => count($ids)],
        $enable ? 'Online verification enabled' : 'Online verification disabled');
});

// GET /api/public/transcript-verify?id=<student_id>&exam=<exam_id>&t=<token>   (no auth — QR scan)
$router->get('/api/public/transcript-verify', function () {
    $code = trim($_GET['id'] ?? '');
    $examId = (int)($_GET['exam'] ?? 0);
    $token = trim($_GET['t'] ?? '');
    if ($code === '' || !$examId || $token === '') {
        Response::error('Invalid verification link', 400);
    }
    if (!hash_equals(transcriptVerifyToken($code, $examId), $token)) {
        Response::error('Invalid verification link. ' . TRANSCRIPT_VERIFY_OFF_MSG, 404);
    }
    $exam = Database::fetch("SELECT id, year, class, exam_name FROM exams WHERE id = ?", [$examId]);
    if (!$exam) Response::error('Invalid verification link', 404);

    $enabled = transcriptVerifyEnabledSet($examId);
    if (!isset($enabled[$code])) {
        // Deliberately reveal nothing about the student or the result.
        Response::success(['verified' => false, 'message' => TRANSCRIPT_VERIFY_OFF_MSG]);
    }

    // Session 100: a result held for unpaid fees is not verified online either
    $stu = Database::fetch("SELECT id, user_id, class FROM students WHERE student_id = ?", [$code]);
    if ($stu) {
        $dues = duesStatusFor([$stu], (int)$exam['year'], (string)$exam['exam_name']);
        if (duesHeld($dues[(int)$stu['id']] ?? null)) {
            Response::success(['verified' => false, 'message' => 'This result is on hold. ' . TRANSCRIPT_VERIFY_OFF_MSG]);
        }
    }

    // The QR link is permanent; it always shows the CURRENT result, so a mark corrected after
    // publishing is reflected on the next scan (rebuilt live from exam_results each time).
    $list = transcriptBuild($exam, $code, '', true);
    if (empty($list)) Response::error('Transcript not found. ' . TRANSCRIPT_VERIFY_OFF_MSG, 404);

    $t = $list[0];
    unset($t['date_of_birth'], $t['registration_no'], $t['optional_subject'], $t['custom']); // custom fields may hold personal details
    $t['verified'] = true;
    $t['verified_since'] = $enabled[$code];
    Response::success($t);
});
