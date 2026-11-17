<?php

// Fee Due Rules (Session 100) — admin.
//   GET  /api/admin/due-rules            { settings, filters, holds }
//   PUT  /api/admin/due-rules            { mark_entry, result_hold }
//   POST /api/admin/due-rules/preview    { year, class, exam_name, group? } → every student's due status + counts

$router->get('/api/admin/due-rules', function () {
    Auth::requireRole('admin');
    Response::success(['settings' => duesSettings(), 'filters' => DUES_FILTERS, 'holds' => DUES_HOLDS]);
});

$router->put('/api/admin/due-rules', function () {
    $user = Auth::requireRole('admin');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $cur = duesSettings();
    $mark = $d['mark_entry'] ?? $cur['mark_entry'];
    $hold = $d['result_hold'] ?? $cur['result_hold'];
    $errors = [];
    if (!in_array($mark, ['all', 'exam_paid', 'tuition_paid', 'all_paid'], true)) $errors[] = 'Mark entry rule must be all, exam_paid, tuition_paid or all_paid';
    if (!isset(DUES_HOLDS[$hold])) $errors[] = 'Result hold rule must be none, exam_due, tuition_due or any_due';
    if ($errors) Response::validationError($errors);
    $json = json_encode(['mark_entry' => $mark, 'result_hold' => $hold]);
    if (Database::fetch("SELECT id FROM site_settings WHERE setting_key = 'due_rules'")) {
        Database::query("UPDATE site_settings SET setting_value = ?, updated_by = ? WHERE setting_key = 'due_rules'", [$json, $user['id']]);
    } else {
        Database::query("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('due_rules', ?, ?)", [$json, $user['id']]);
    }
    Response::success(duesSettings(), 'Due rules saved');
});

$router->post('/api/admin/due-rules/preview', function () {
    Auth::requireRole('admin');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $year = (int)($d['year'] ?? 0);
    $class = trim((string)($d['class'] ?? ''));
    $exam = trim((string)($d['exam_name'] ?? ''));
    $group = trim((string)($d['group'] ?? ''));
    if (!$year || $class === '' || $exam === '') Response::validationError(['year, class and exam_name are required']);
    $sql = "SELECT id, user_id, student_id, name, class, class_roll, student_group FROM students WHERE class = ?";
    $params = [$class];
    if ($group !== '') { $sql .= " AND student_group = ?"; $params[] = $group; }
    $rows = Database::fetchAll($sql . " ORDER BY " . (function_exists('catalogGroupOrderSql') ? catalogGroupOrderSql('student_group') . ', ' : '') . "CAST(class_roll AS UNSIGNED), student_id", $params);
    $status = duesStatusFor($rows, $year, $exam);
    $rules = duesSettings();
    $counts = ['total' => count($rows), 'exam_paid' => 0, 'tuition_clear' => 0, 'all_clear' => 0, 'mark_entry_allowed' => 0, 'held' => 0];
    $list = [];
    foreach ($rows as $r) {
        $st = $status[(int)$r['id']];
        $allowed = duesMatches($st, $rules['mark_entry']);
        $held = duesHeld($st, $rules['result_hold']);
        $counts['exam_paid'] += $st['exam_paid'] ? 1 : 0;
        $counts['tuition_clear'] += $st['tuition_clear'] ? 1 : 0;
        $counts['all_clear'] += $st['all_clear'] ? 1 : 0;
        $counts['mark_entry_allowed'] += $allowed ? 1 : 0;
        $counts['held'] += $held ? 1 : 0;
        $list[] = ['student_id' => $r['student_id'], 'name' => $r['name'], 'class_roll' => $r['class_roll'], 'group' => $r['student_group'],
            'mark_entry_allowed' => $allowed, 'held' => $held] + $st;
    }
    Response::success(['rules' => $rules, 'counts' => $counts, 'exam_fee' => duesExamFee($exam, $class), 'students' => $list]);
});
