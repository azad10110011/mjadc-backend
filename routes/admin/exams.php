<?php
// Exam name/options endpoints for fee collection & routine pages.

// GET /api/admin/exams?year=X — list exams (id, name, year, class)
$router->get('/api/admin/exams', function () {
    Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $year = trim($_GET['year'] ?? '');
    if ($year) {
        $rows = Database::fetchAll("SELECT id, year, class, exam_name FROM exams WHERE year = ? ORDER BY id DESC", [(int)$year]);
    } else {
        $rows = Database::fetchAll("SELECT id, year, class, exam_name FROM exams ORDER BY id DESC LIMIT 100");
    }
    Response::success($rows);
});
