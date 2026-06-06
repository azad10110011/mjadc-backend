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

if (strpos($relativePath, 'uploads/') === 0) {
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
require_once __DIR__ . '/utils/Response.php';
require_once __DIR__ . '/utils/Router.php';
require_once __DIR__ . '/utils/Validator.php';
require_once __DIR__ . '/utils/JWTHandler.php';
require_once __DIR__ . '/middleware/Cors.php';
require_once __DIR__ . '/middleware/Auth.php';

// Handle CORS
Cors::handle();

// Create router
$router = new Router();

// ============= AUTH ROUTES =============
require __DIR__ . '/routes/auth.php';

// ============= PUBLIC ROUTES =============
require __DIR__ . '/routes/subjects.php';
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
require __DIR__ . '/routes/admin/principals.php';
require __DIR__ . '/routes/admin/academic-approvals.php';
require __DIR__ . '/routes/admin/achievements.php';
require __DIR__ . '/routes/admin/gallery.php';
require __DIR__ . '/routes/admin/student-info.php';
require __DIR__ . '/routes/admin/transcript.php';

// Dispatch
$router->dispatch();
