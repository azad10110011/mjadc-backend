<?php

// Serve static files directly (for PHP built-in server)
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$staticFile = __DIR__ . $uri;
if ($uri !== '/' && file_exists($staticFile) && !is_dir($staticFile)) {
    return false;
}

// Set UTF-8 as default encoding for multi-byte string functions
mb_internal_encoding('UTF-8');

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
require __DIR__ . '/routes/public/settings.php';
require __DIR__ . '/routes/public/directory.php';

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
require __DIR__ . '/routes/admin/settings.php';
require __DIR__ . '/routes/admin/students.php';

// Dispatch
$router->dispatch();
