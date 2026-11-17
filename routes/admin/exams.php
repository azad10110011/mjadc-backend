<?php
// Exam name/options endpoints for fee collection & routine pages.

// GET /api/admin/exams?year=X — list exams (id, name, year, class)
$router->get('/api/admin/exams', function () {
    Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $year = trim($_GET['year'] ?? '');
    if ($year && preg_match('/^\d{4}$/', $year)) {
        // Session 88: exams that only have a routine so far are created too, so every exam shows up
        Database::query("INSERT IGNORE INTO exams (year, class, exam_name)
                         SELECT DISTINCT year, class, exam_name FROM exam_routines
                         WHERE year = ? AND class IN ('11th','12th') AND exam_name <> ''", [$year]);
    }
    if ($year) {
        $rows = Database::fetchAll("SELECT id, year, class, exam_name FROM exams WHERE year = ? ORDER BY id DESC", [(int)$year]);
    } else {
        $rows = Database::fetchAll("SELECT id, year, class, exam_name FROM exams ORDER BY id DESC LIMIT 100");
    }
    Response::success($rows);
});

// POST /api/admin/exams  { year, class, exam_name }   — create an exam (Session 88). The name is also
// added to the class's exam-name list in the catalog.
$router->post('/api/admin/exams', function () {
    $user = Auth::requireRole('admin');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $year = trim((string)($d['year'] ?? ''));
    $class = trim((string)($d['class'] ?? ''));
    $name = trim(preg_replace('/\s+/', ' ', (string)($d['exam_name'] ?? '')));
    $errors = [];
    if (!preg_match('/^\d{4}$/', $year) || (int)$year < 2000 || (int)$year > 2100) $errors[] = 'Enter a valid year';
    if (!in_array($class, ['11th', '12th'], true)) $errors[] = 'Class must be 11th or 12th';
    if ($name === '' || mb_strlen($name) > 50) $errors[] = 'Exam name is required (max 50 characters)';
    elseif (!preg_match('/^[\p{L}\p{N} ().,\'&\/-]+$/u', $name)) $errors[] = 'Exam name contains characters that are not allowed';
    if ($errors) Response::validationError($errors);
    if (Database::fetch("SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?", [$year, $class, $name])) {
        Response::validationError(["$name $year ($class) already exists"]);
    }
    $id = catalogEnsureExam($year, $class, $name);
    $all = catalogSetting('catalog_exam_names', catalogDefaults()['exam_names']);
    $list = (array)($all[$class] ?? []);
    if (!in_array(mb_strtolower($name), array_map('mb_strtolower', $list), true)) {
        $list[] = $name;
        $all[$class] = $list;
        catalogSave('catalog_exam_names', $all, (int)$user['id']);
    }
    Response::success(['id' => $id, 'year' => $year, 'class' => $class, 'exam_name' => $name], "Exam created: $name $year ($class)");
});

// DELETE /api/admin/exams/{id} — only when nothing uses it yet (no results / fees)   (Session 88)
$router->delete('/api/admin/exams/{id}', function ($params) {
    Auth::requireRole('admin');
    $exam = Database::fetch("SELECT * FROM exams WHERE id = ?", [(int)$params['id']]);
    if (!$exam) Response::notFound('Exam not found');
    $uses = [];
    $n = (int)(Database::fetch("SELECT COUNT(*) n FROM exam_results WHERE exam_id = ?", [$exam['id']])['n'] ?? 0);
    if ($n) $uses[] = "$n result(s)";
    $n = (int)(Database::fetch("SELECT COUNT(*) n FROM exam_routines WHERE year = ? AND class = ? AND exam_name = ?", [$exam['year'], $exam['class'], $exam['exam_name']])['n'] ?? 0);
    if ($n) $uses[] = "$n routine day(s)";
    $fees = Database::fetch("SHOW TABLES LIKE 'exam_fees'") ? (int)(Database::fetch("SELECT COUNT(*) n FROM exam_fees WHERE year = ? AND exam_name IN (?, ?)", [$exam['year'], $exam['exam_name'], "{$exam['exam_name']} ({$exam['class']})"])['n'] ?? 0) : 0;
    if ($fees) $uses[] = "$fees fee payment(s)";
    if ($uses) Response::validationError(["Cannot delete {$exam['exam_name']} {$exam['year']} ({$exam['class']}): it has " . implode(', ', $uses)]);
    Database::query("DELETE FROM exams WHERE id = ?", [$exam['id']]);
    Response::success(null, 'Exam deleted');
});
