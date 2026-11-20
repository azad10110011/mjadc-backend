<?php

$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Serve upload files directly (bypass for when .htaccess rewrite fails on some hosts)
$basePath = dirname($_SERVER['SCRIPT_NAME']); // e.g., /mjadc-api
$relativePath = $requestUri;
if ($basePath !== '/' && strpos($requestUri, $basePath) === 0) {
    $relativePath = substr($requestUri, strlen($basePath));
}
$relativePath = '/' . trim($relativePath, '/');
$relativePath = ltrim($relativePath, '/');

if (strpos($relativePath, 'uploads/') === 0 && strpos($relativePath, 'uploads/private/') !== 0) { // private files only via the API (Session 123)
    $filePath = __DIR__ . '/' . $relativePath;
    if (file_exists($filePath) && !is_dir($filePath)) {
        $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
        $mimeTypes = [
            'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg',
            'png' => 'image/png', 'gif' => 'image/gif',
            'webp' => 'image/webp', 'pdf' => 'application/pdf',
        ];
        $mime = $mimeTypes[$ext] ?? 'application/octet-stream';
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($filePath));
        header('Cache-Control: public, max-age=31536000');
        readfile($filePath);
        exit;
    }
}

// Serve static files directly (for PHP built-in server)
$uri = $requestUri;
$staticFile = __DIR__ . $uri;
if ($uri !== '/' && file_exists($staticFile) && !is_dir($staticFile)) {
    return false;
}

// Load environment
require_once __DIR__ . '/vendor/autoload.php';

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

// Load core
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/utils/Audit.php'; // Update History triggers + request context (Session 121) — before any DB use
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/utils/Router.php';
require_once __DIR__ . '/utils/Validator.php';
require_once __DIR__ . '/utils/JWTHandler.php';
require_once __DIR__ . '/utils/Dues.php'; // fee due status, mark-entry / result-hold rules (Session 100)
require_once __DIR__ . '/utils/Security.php'; // login throttling, forced password change, temp passwords (Session 112)
require_once __DIR__ . '/utils/ResultWorkflow.php'; // result draft→submitted→approved→published rules (Session 113)
require_once __DIR__ . '/utils/PrivateFiles.php'; // private image storage served only through the API (Session 123)
require_once __DIR__ . '/utils/SubjectNames.php'; // official subject names: ICT = Information and Communications Technology (Session 124)
require_once __DIR__ . '/middleware/Cors.php';
require_once __DIR__ . '/middleware/Auth.php';

// Session 125: any unexpected server error → a short error id (full reason in error_log). Admins also get
// the reason in the message, so a failure on live can be diagnosed without server access.
function mjadcServerError(string $what): void
{
    $id = substr(bin2hex(random_bytes(4)), 0, 8);
    error_log("[mjadc $id] " . ($_SERVER['REQUEST_METHOD'] ?? '') . ' ' . ($_SERVER['REQUEST_URI'] ?? '') . ' :: ' . $what);
    $msg = "Something went wrong on the server (error $id).";
    try {
        $u = class_exists('Auth') ? Auth::getUser() : null;
        if ($u && in_array('admin', (array)($u['roles'] ?? []), true)) $msg .= ' Details: ' . mb_substr($what, 0, 400);
    } catch (Throwable $x) {}
    while (ob_get_level()) ob_end_clean();
    if (!headers_sent()) { http_response_code(500); header('Content-Type: application/json; charset=utf-8'); }
    echo json_encode(['status' => 500, 'message' => $msg, 'error_id' => $id], JSON_UNESCAPED_UNICODE);
    exit;
}
set_exception_handler(function (Throwable $e) {
    mjadcServerError(get_class($e) . ': ' . $e->getMessage() . ' @ ' . basename($e->getFile()) . ':' . $e->getLine());
});
register_shutdown_function(function () {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        mjadcServerError('PHP fatal: ' . $e['message'] . ' @ ' . basename($e['file']) . ':' . $e['line']);
    }
});

// Handle CORS
Cors::handle();

// Create router
$router = new Router();

// ============= AUTH ROUTES =============
require __DIR__ . '/routes/auth.php';

// ============= PUBLIC ROUTES =============
require __DIR__ . '/routes/subjects.php';
require __DIR__ . '/routes/subjects-with-codes.php'; // GET /api/subjects-with-codes (codes for entry forms)
require __DIR__ . '/routes/public/notices.php';
require __DIR__ . '/routes/public/results.php';
require __DIR__ . '/routes/public/routines.php';
require __DIR__ . '/routes/public/syllabus.php';
require __DIR__ . '/routes/public/forms.php';
require __DIR__ . '/routes/public/gallery.php';
require __DIR__ . '/routes/public/events.php';
require __DIR__ . '/routes/public/pages.php';
require __DIR__ . '/routes/public/admissions.php';
require __DIR__ . '/routes/public/payments.php';
require __DIR__ . '/routes/public/contact.php';
require __DIR__ . '/routes/public/settings.php';
require __DIR__ . '/routes/public/directory.php';
require __DIR__ . '/routes/public/principals.php';
require __DIR__ . '/routes/public/academic-approvals.php';
require __DIR__ . '/routes/public/achievements.php';
require __DIR__ . '/routes/public/student-info.php';
require __DIR__ . '/routes/public/media.php';

// ============= STUDENT ROUTES =============
require __DIR__ . '/routes/student/dashboard.php';
require __DIR__ . '/routes/student/fees.php';

// ============= TEACHER ROUTES =============
require __DIR__ . '/routes/teacher/results.php';
require __DIR__ . '/routes/teacher/leave.php';
require __DIR__ . '/routes/teacher/forms.php';

// ============= STAFF ROUTES =============
require __DIR__ . '/routes/staff/leave.php';

// ============= EXAM CONTROLLER ROUTES =============
require __DIR__ . '/routes/exam-controller/results.php';

// ============= PRINCIPAL ROUTES =============
require __DIR__ . '/routes/principal/teachers.php';
require __DIR__ . '/routes/principal/staff.php';
require __DIR__ . '/routes/principal/results.php';
require __DIR__ . '/routes/principal/fees.php';
require __DIR__ . '/routes/principal/leave.php';

// ============= ADMINISTRATION PANEL ROUTES =============
require __DIR__ . '/routes/administration/notices.php';
require __DIR__ . '/routes/administration/syllabus.php';
require __DIR__ . '/routes/administration/routines.php';
require __DIR__ . '/routes/administration/fees.php';
require __DIR__ . '/routes/administration/students.php';
require __DIR__ . '/routes/administration/results.php';
require __DIR__ . '/routes/administration/forms.php';

// ============= ADMIN PANEL ROUTES =============
require __DIR__ . '/routes/admin/notices.php';
require __DIR__ . '/routes/admin/results.php';
require __DIR__ . '/routes/admin/routines.php';
require __DIR__ . '/routes/admin/syllabus.php';
require __DIR__ . '/routes/admin/teachers-staff.php';
require __DIR__ . '/routes/admin/forms.php';
require __DIR__ . '/routes/admin/pages.php';
require __DIR__ . '/routes/admin/media.php';
require __DIR__ . '/routes/admin/users.php';
require __DIR__ . '/routes/admin/fees.php';
require __DIR__ . '/routes/admin/transactions.php';
require __DIR__ . '/routes/admin/leave.php';
require __DIR__ . '/routes/admin/contact.php';
require __DIR__ . '/routes/admin/settings.php';
require __DIR__ . '/routes/admin/governing-body.php'; // GET/POST/PUT/DELETE /api/admin/governing-body
require __DIR__ . '/routes/admin/teachers-council.php'; // GET/POST/PUT/DELETE /api/admin/teachers-council
require __DIR__ . '/routes/admin/career-club.php'; // GET/POST/PUT/DELETE /api/admin/career-club
require __DIR__ . '/routes/admin/co-curricular.php'; // GET/POST/PUT/DELETE /api/admin/co-curricular/{club}
require __DIR__ . '/routes/admin/subjects.php';
require __DIR__ . '/routes/admin/students.php';
require __DIR__ . '/routes/admin/exam-subject-config.php';
require __DIR__ . '/routes/admin/exams.php';
require __DIR__ . '/routes/admin/exam-papers.php'; // GET/PUT /api/admin/exam-papers
require __DIR__ . '/routes/admin/custom-fields.php'; // GET/POST/PUT/DELETE /api/admin/custom-fields
require __DIR__ . '/routes/admin/principals.php';
require __DIR__ . '/routes/admin/academic-approvals.php';
require __DIR__ . '/routes/admin/achievements.php';
require __DIR__ . '/routes/admin/gallery.php';
require __DIR__ . '/routes/admin/student-info.php';
require __DIR__ . '/routes/admin/transcript.php';
require __DIR__ . '/routes/admin/exam-routines.php'; // GET/POST/PUT/DELETE /api/admin/exam-routines
require __DIR__ . '/routes/admin/admit-card.php';
require __DIR__ . '/routes/admin/admit-card-config.php';
require __DIR__ . '/routes/admin/exam-fees.php';
require __DIR__ . '/routes/admin/mark-entry-form.php'; // POST /api/admin/mark-entry-form
require __DIR__ . '/routes/admin/transcript-verify.php'; // transcript QR verify (admin + public); needs transcript.php above
require __DIR__ . '/routes/admin/result-summary.php'; // POST /api/admin/result-summary; needs transcript.php above
require __DIR__ . '/routes/admin/id-cards.php'; // GET /api/admin/id-cards (restored, Session 77)
require __DIR__ . '/routes/admin/card-templates.php'; // ID card designer templates + QR verify helpers (2026-11-20)
require __DIR__ . '/routes/public/id-verify.php'; // public ID card QR scan (needs card-templates.php above)
require __DIR__ . '/routes/student/results.php'; // GET /api/student/results (restored, Session 77); needs transcript.php
require __DIR__ . '/routes/teacher/attendance.php'; // teacher + admin attendance, /api/teacher/subjects (restored, Session 77)
require __DIR__ . '/routes/admin/changelog.php'; // GET /api/admin/changelog (restored, Session 77)
require __DIR__ . '/routes/student/attendance.php'; // GET /api/student/attendance (Session 79)
require __DIR__ . '/routes/admin/permissions.php'; // user permission management (Session 80)
require __DIR__ . '/routes/admin/seat-plan.php'; // exam rooms + seat plans (Session 80, admin only)
require __DIR__ . '/routes/admin/exam-duty.php'; // hall duty allocation + letters data (Session 80, admin only)
if (preg_match('#/api/(admin/(tuition-fees|fees|transactions|due-rules)|admin-panel/fees|principal/fees|student/(fees|pay-fee)|payments)#', $requestUri)) {
    duesEnsureSchema(); // adds tuition_fees.fee_type, payments.fee_type/collected_by once (Session 100)
}
require __DIR__ . '/routes/admin/due-rules.php'; // due rules settings + preview (Session 100)
require __DIR__ . '/routes/admin/board-exam.php'; // Board Exam Management: exams, venues, rooms, candidates, routine, seats (Session 99)
require __DIR__ . '/routes/admin/board-duty.php'; // Board exam hall duty + letters incl. external teachers (Session 99)
require __DIR__ . '/routes/admin/student-photos.php'; // POST /api/admin/students/{id}/photo — bulk photo upload (Session 86)
require __DIR__ . '/routes/admin/catalog.php'; // exam names + student groups catalog (Session 88)
require __DIR__ . '/routes/admin/quick-access.php'; // dashboard Quick Access defaults + per-user lists (Session 110)
require __DIR__ . '/routes/admin/audit.php'; // Update History — admin only (Session 121)
require __DIR__ . '/routes/profile-card.php'; // dashboard profile card + admin field settings (Session 122)
require __DIR__ . '/routes/student-docs.php'; // student images: receipts, admission forms … (Session 123)
require __DIR__ . '/routes/result-images.php'; // teachers' result sheet pictures (Session 123)
require __DIR__ . '/routes/public/exam-fee-verify.php';

// Session 124: one-time rename of old ICT spellings in stored data (skipped once its flag file exists)
try { subjectNamesMigrate(); } catch (Throwable $e) { error_log('ICT rename failed: ' . $e->getMessage()); }
try { resultKeysWidenMigrate(); } catch (Throwable $e) { error_log('Result key repair failed: ' . $e->getMessage()); }

// Dispatch
$router->dispatch();


