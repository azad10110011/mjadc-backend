<?php

// Admin › Update History (Session 121). Admin accounts only (not grantable).
// Fixed paths are registered BEFORE /api/admin/audit/{id} (the router takes the first match).

if (!function_exists('auditRequireAdmin')) {
    function auditRequireAdmin(): array
    {
        $user = Auth::requireRole('admin');
        if (!in_array('admin', (array)($user['roles'] ?? []), true)) Response::forbidden('Update History is for admins only.');
        auditEnsureSchema();
        return $user;
    }

    /** Sections for the filter (tables not listed fall under "Other"). */
    function auditSections(): array
    {
        return [
            'Students & Admissions' => ['students', 'admissions', 'custom_entry_fields', 'student_documents'],
            'Results & Exams' => ['exam_results', 'exams', 'exam_subject_config', 'exam_routines', 'result_changelog', 'subjects', 'subject_papers', 'subject_parts', 'transcript_verifications', 'result_images'],
            'Exam Rooms & Duty' => ['exam_rooms', 'seat_plans', 'seat_assignments', 'exam_duties', 'duty_config', 'duty_teacher_global', 'duty_teacher_rules'],
            'Fees & Payments' => ['tuition_fees', 'payments', 'exam_fees', 'exam_fee_waivers'],
            'Teachers & Staff' => ['teachers', 'staff', 'teacher_subjects', 'teacher_attendance', 'leave_allocations', 'leave_applications', 'leave_taken', 'principals'],
            'Users & Permissions' => ['users', 'user_roles', 'user_permissions', 'user_quick_access', 'user_profile_prefs'],
            'Board Exam' => ['board_exams', 'board_venues', 'board_rooms', 'board_candidates', 'board_routine', 'board_seats', 'board_duties', 'board_duty_config', 'board_duty_rules', 'board_external_teachers'],
            'Website Content' => ['notices', 'page_content', 'gallery', 'achievements', 'achievement_images', 'academic_approvals', 'annual_reports', 'downloadable_forms', 'events', 'governing_body', 'teachers_council', 'career_club', 'co_curricular', 'routines', 'syllabus'],
            'Settings' => ['site_settings'],
        ];
    }

    /** Changed field names of one entry. */
    function auditChangedFields(?array $old, ?array $new): array
    {
        if ($old === null || $new === null) return array_keys($old ?? $new ?? []);
        $out = [];
        foreach ($new as $k => $v) {
            if (auditNoise($k)) continue;
            // the password is never stored; it only says whether it changed
            if (auditRedacted($k)) { if ($v === '(changed)') $out[] = $k; continue; }
            if (!array_key_exists($k, $old) || (string)json_encode($old[$k]) !== (string)json_encode($v)) $out[] = $k;
        }
        return $out;
    }

    function auditEntryOut(array $r, bool $full = true): array
    {
        $old = $r['old_data'] !== null ? json_decode($r['old_data'], true) : null;
        $new = $r['new_data'] !== null ? json_decode($r['new_data'], true) : null;
        $out = [
            'id' => (int)$r['id'],
            'table' => $r['table_name'],
            'row_pk' => $r['row_pk'],
            'action' => $r['action'],
            'changed' => auditChangedFields($old, $new),
            'user_id' => $r['user_id'] !== null ? (int)$r['user_id'] : null,
            'user_name' => $r['user_name'],
            'user_roles' => $r['user_roles'],
            'ip' => $r['ip'],
            'device' => $r['device'],
            'note' => $r['note'],
            'created_at' => $r['created_at'],
        ];
        if ($full) {
            $out['old'] = $old;
            $out['new'] = $new;
            $out['user_agent'] = $r['user_agent'];
            $out['client'] = $r['client'] ? json_decode($r['client'], true) : null;
            $out['request_uri'] = $r['request_uri'];
            $out['method'] = $r['method'];
        }
        return $out;
    }
}

// GET /api/admin/audit/status — install/refresh triggers if needed + summary for the page
$router->get('/api/admin/audit/status', function () {
    auditRequireAdmin();
    $sync = auditSync(false);
    $counts = [];
    foreach (Database::fetchAll("SELECT table_name, COUNT(*) n FROM audit_log GROUP BY table_name") as $r) $counts[$r['table_name']] = (int)$r['n'];
    $users = Database::fetchAll(
        "SELECT user_id, MAX(user_name) AS user_name, COUNT(*) AS n FROM audit_log WHERE user_id IS NOT NULL GROUP BY user_id ORDER BY user_name"
    );
    Response::success([
        'triggers' => $sync,
        'total_entries' => array_sum($counts),
        'table_counts' => $counts,
        'tables' => array_keys(auditSchema()),
        'sections' => auditSections(),
        'users' => $users,
    ]);
});

// POST /api/admin/audit/sync { force? } — rebuild the triggers
$router->post('/api/admin/audit/sync', function () {
    auditRequireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    Response::success(auditSync(!empty($data['force'])), 'Triggers refreshed');
});

// GET /api/admin/audit/record?table=&pk= — the current state of one record
$router->get('/api/admin/audit/record', function () {
    auditRequireAdmin();
    $table = (string)($_GET['table'] ?? '');
    $pk = (string)($_GET['pk'] ?? '');
    if (!auditTableInfo($table)) Response::validationError(['table' => 'Unknown table']);
    Response::success(['table' => $table, 'row_pk' => $pk, 'current' => auditCurrentRow($table, $pk)]);
});

// POST /api/admin/audit/apply { table, row_pk, data } — admin edits a record directly
$router->post('/api/admin/audit/apply', function () {
    auditRequireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $table = (string)($data['table'] ?? '');
    $pk = (string)($data['row_pk'] ?? '');
    $values = is_array($data['data'] ?? null) ? $data['data'] : [];
    if (!auditTableInfo($table)) Response::validationError(['table' => 'Unknown table']);
    try {
        auditSetNote('Edited in Update History');
        $result = auditWriteRow($table, $pk, $values, true);
        auditSetNote(null);
    } catch (\Throwable $e) {
        auditSetNote(null);
        Response::error($e->getMessage(), 422);
    }
    Response::success(['result' => $result, 'current' => auditCurrentRow($table, $pk)], 'Record saved');
});

// POST /api/admin/audit/delete { ids: [] } — remove history entries
$router->post('/api/admin/audit/delete', function () {
    auditRequireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_values(array_filter(array_map('intval', (array)($data['ids'] ?? []))));
    if (!$ids) Response::validationError(['ids' => 'Choose entries to delete']);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $n = Database::query("DELETE FROM audit_log WHERE id IN ($ph)", $ids)->rowCount();
    Response::success(['deleted' => $n], "$n history entr" . ($n === 1 ? 'y' : 'ies') . ' deleted');
});

// GET /api/admin/audit?section=&table=&row_pk=&user_id=&action=&from=&to=&q=&page=&per_page=
$router->get('/api/admin/audit', function () {
    auditRequireAdmin();
    $where = [];
    $p = [];
    $section = (string)($_GET['section'] ?? '');
    $sections = auditSections();
    if ($section !== '') {
        if ($section === 'Other') {
            $listed = array_merge(...array_values($sections));
            $where[] = 'table_name NOT IN (' . implode(',', array_fill(0, count($listed), '?')) . ')';
            $p = array_merge($p, $listed);
        } elseif (isset($sections[$section])) {
            $where[] = 'table_name IN (' . implode(',', array_fill(0, count($sections[$section]), '?')) . ')';
            $p = array_merge($p, $sections[$section]);
        }
    }
    foreach (['table' => 'table_name', 'row_pk' => 'row_pk', 'action' => 'action'] as $q => $col) {
        if (($_GET[$q] ?? '') !== '') { $where[] = "$col = ?"; $p[] = (string)$_GET[$q]; }
    }
    if (($_GET['user_id'] ?? '') !== '') {
        if ($_GET['user_id'] === 'none') $where[] = 'user_id IS NULL';
        else { $where[] = 'user_id = ?'; $p[] = (int)$_GET['user_id']; }
    }
    if (($_GET['from'] ?? '') !== '') { $where[] = 'created_at >= ?'; $p[] = $_GET['from'] . ' 00:00:00'; }
    if (($_GET['to'] ?? '') !== '') { $where[] = 'created_at <= ?'; $p[] = $_GET['to'] . ' 23:59:59.999'; }
    $q = trim((string)($_GET['q'] ?? ''));
    if ($q !== '') {
        $where[] = '(row_pk = ? OR user_name LIKE ? OR ip LIKE ? OR device LIKE ? OR old_data LIKE ? OR new_data LIKE ?)';
        $like = '%' . $q . '%';
        array_push($p, $q, $like, $like, $like, $like, $like);
    }
    $sql = $where ? 'WHERE ' . implode(' AND ', $where) : '';
    $per = max(10, min(100, (int)($_GET['per_page'] ?? 50)));
    $page = max(1, (int)($_GET['page'] ?? 1));
    $total = (int)(Database::fetch("SELECT COUNT(*) n FROM audit_log $sql", $p)['n'] ?? 0);
    $rows = Database::fetchAll(
        "SELECT * FROM audit_log $sql ORDER BY id DESC LIMIT $per OFFSET " . (($page - 1) * $per),
        $p
    );
    Response::success([
        'total' => $total, 'page' => $page, 'per_page' => $per,
        'entries' => array_map(fn($r) => auditEntryOut($r, true), $rows),
    ]);
});

// GET /api/admin/audit/{id} — one entry + the record as it is now
$router->get('/api/admin/audit/{id}', function (array $params) {
    auditRequireAdmin();
    $r = Database::fetch("SELECT * FROM audit_log WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('History entry not found');
    $out = auditEntryOut($r, true);
    $out['current'] = auditCurrentRow($r['table_name'], $r['row_pk']);
    $out['tracked'] = (bool)auditTableInfo($r['table_name']);
    Response::success($out);
});

// POST /api/admin/audit/{id}/restore { to: 'before' | 'after' }
// before = make the record look like it did before this change; after = put this change back.
$router->post('/api/admin/audit/{id}/restore', function (array $params) {
    auditRequireAdmin();
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $to = ($data['to'] ?? 'before') === 'after' ? 'after' : 'before';
    $r = Database::fetch("SELECT * FROM audit_log WHERE id = ?", [(int)$params['id']]);
    if (!$r) Response::notFound('History entry not found');
    $old = $r['old_data'] !== null ? json_decode($r['old_data'], true) : null;
    $new = $r['new_data'] !== null ? json_decode($r['new_data'], true) : null;
    $state = $to === 'before' ? $old : $new; // null = the record did not exist at that point
    try {
        auditSetNote("Restored to " . ($to === 'before' ? 'before' : 'after') . " change #{$r['id']}");
        if ($state === null) {
            $result = auditDeleteRow($r['table_name'], $r['row_pk']) ? 'deleted' : 'already gone';
        } else {
            $result = auditWriteRow($r['table_name'], $r['row_pk'], $state, false);
        }
        auditSetNote(null);
    } catch (\Throwable $e) {
        auditSetNote(null);
        Response::error('Could not restore: ' . $e->getMessage(), 422);
    }
    Response::success([
        'result' => $result,
        'current' => auditCurrentRow($r['table_name'], $r['row_pk']),
    ], 'Restored');
});

// DELETE /api/admin/audit/{id}
$router->delete('/api/admin/audit/{id}', function (array $params) {
    auditRequireAdmin();
    $n = Database::query("DELETE FROM audit_log WHERE id = ?", [(int)$params['id']])->rowCount();
    if (!$n) Response::notFound('History entry not found');
    Response::success(null, 'History entry deleted');
});
