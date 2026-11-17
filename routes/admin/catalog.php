<?php

// Exam names + student groups catalog (Session 88) — admin-managed lists used by every page.
//   GET    /api/catalog                         { exam_names: {11th:[…],12th:[…]}, groups: [{name, code}] }  (public)
//   PUT    /api/admin/catalog/exam-names        { class, names:[…] }   (admin; names still in use cannot be removed)
//   POST   /api/admin/catalog/groups            { name, code }         (admin; code = Student ID group digit)
//   DELETE /api/admin/catalog/groups?name=      (admin; only when no student / subject / teacher uses it)
// Stored in site_settings: catalog_exam_names (JSON), catalog_groups (JSON).
// Exam names that exist in the exams table always appear, so nothing in use can disappear.

if (!function_exists('catalogDefaults')) {
function catalogDefaults(): array
{
    return [
        'exam_names' => ['11th' => ['Half Yearly', 'Year Final', 'Model Test'], '12th' => ['Pre-Test', 'Test', 'Model Test']],
        'groups' => [['name' => 'Science', 'code' => '1'], ['name' => 'Business Studies', 'code' => '2'], ['name' => 'Humanities', 'code' => '3']],
    ];
}}

if (!function_exists('catalogSetting')) {
function catalogSetting(string $key, $default)
{
    $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = ?", [$key]);
    $v = $row ? json_decode((string)$row['setting_value'], true) : null;
    return is_array($v) ? $v : $default;
}}

if (!function_exists('catalogSave')) {
function catalogSave(string $key, $value, int $userId): void
{
    $json = json_encode($value, JSON_UNESCAPED_UNICODE);
    if (Database::fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key])) {
        Database::query("UPDATE site_settings SET setting_value = ?, updated_by = ? WHERE setting_key = ?", [$json, $userId, $key]);
    } else {
        Database::query("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES (?, ?, ?)", [$key, $json, $userId]);
    }
}}

/** [{name, code}] in display order. */
if (!function_exists('catalogGroups')) {
function catalogGroups(): array
{
    $groups = catalogSetting('catalog_groups', catalogDefaults()['groups']);
    return array_values(array_filter($groups, fn($g) => is_array($g) && trim((string)($g['name'] ?? '')) !== ''));
}}

if (!function_exists('catalogGroupNames')) {
function catalogGroupNames(): array { return array_map(fn($g) => (string)$g['name'], catalogGroups()); }
}

/** SQL fragment ordering rows by the catalog group order; unknown groups last. */
if (!function_exists('catalogGroupOrderSql')) {
function catalogGroupOrderSql(string $column): string
{
    $names = catalogGroupNames();
    if (!$names) return '0';
    $quoted = implode(', ', array_map(fn($n) => Database::getInstance()->quote($n), $names));
    return "CASE WHEN FIELD($column, $quoted) = 0 THEN 999 ELSE FIELD($column, $quoted) END";
}}

/** {class: [names]} = saved list ∪ names already used in the exams table. */
if (!function_exists('catalogExamNames')) {
function catalogExamNames(): array
{
    $saved = catalogSetting('catalog_exam_names', catalogDefaults()['exam_names']);
    $out = [];
    foreach (['11th', '12th'] as $c) {
        $out[$c] = array_values(array_unique(array_filter(array_map('strval', (array)($saved[$c] ?? [])), fn($n) => trim($n) !== '')));
    }
    foreach (Database::fetchAll("SELECT DISTINCT class, exam_name FROM exams") as $r) {
        if (isset($out[$r['class']]) && !in_array($r['exam_name'], $out[$r['class']], true)) $out[$r['class']][] = $r['exam_name'];
    }
    return $out;
}}

/** Make sure an exam (year + class + name) exists; returns its id. */
if (!function_exists('catalogEnsureExam')) {
function catalogEnsureExam(string $year, string $class, string $name): ?int
{
    if (!preg_match('/^\d{4}$/', $year) || !in_array($class, ['11th', '12th'], true) || trim($name) === '') return null;
    Database::query("INSERT IGNORE INTO exams (year, class, exam_name) VALUES (?, ?, ?)", [$year, $class, trim($name)]);
    $row = Database::fetch("SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?", [$year, $class, trim($name)]);
    return $row ? (int)$row['id'] : null;
}}

/** students.student_group was ENUM(3 groups) — widen it so new groups can be stored. */
if (!function_exists('catalogWidenGroupColumn')) {
function catalogWidenGroupColumn(): void
{
    $col = Database::fetch("SELECT COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'students' AND COLUMN_NAME = 'student_group'");
    if ($col && stripos($col['COLUMN_TYPE'], 'enum') === 0) {
        Database::query("ALTER TABLE students MODIFY student_group VARCHAR(50) NULL");
    }
}}

$router->get('/api/catalog', function () {
    Response::success(['exam_names' => catalogExamNames(), 'groups' => catalogGroups()]);
});

$router->put('/api/admin/catalog/exam-names', function () {
    $user = Auth::requireRole('admin');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $class = (string)($d['class'] ?? '');
    if (!in_array($class, ['11th', '12th'], true)) Response::validationError(['class must be 11th or 12th']);
    $names = [];
    foreach ((array)($d['names'] ?? []) as $n) {
        $n = trim(preg_replace('/\s+/', ' ', (string)$n));
        if ($n === '') continue;
        if (mb_strlen($n) > 50) Response::validationError(["\"$n\" is too long (max 50 characters)"]);
        if (!preg_match('/^[\p{L}\p{N} ().,\'&\/-]+$/u', $n)) Response::validationError(["\"$n\" contains characters that are not allowed"]);
        if (!in_array(mb_strtolower($n), array_map('mb_strtolower', $names), true)) $names[] = $n;
    }
    // names already used by an exam cannot be removed
    $used = array_column(Database::fetchAll("SELECT DISTINCT exam_name FROM exams WHERE class = ?", [$class]), 'exam_name');
    $removedUsed = array_values(array_diff($used, $names));
    if ($removedUsed) Response::validationError(['Cannot remove exam name(s) already used by an exam: ' . implode(', ', $removedUsed)]);
    $all = catalogSetting('catalog_exam_names', catalogDefaults()['exam_names']);
    $all[$class] = $names;
    catalogSave('catalog_exam_names', $all, (int)$user['id']);
    Response::success(catalogExamNames(), 'Exam names saved');
});

$router->post('/api/admin/catalog/groups', function () {
    $user = Auth::requireRole('admin');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $name = trim(preg_replace('/\s+/', ' ', (string)($d['name'] ?? '')));
    $code = trim((string)($d['code'] ?? ''));
    $groups = catalogGroups();
    $errors = [];
    if ($name === '' || mb_strlen($name) > 50) $errors[] = 'Group name is required (max 50 characters)';
    elseif (!preg_match('/^[\p{L}\p{N} ().,&\/-]+$/u', $name)) $errors[] = 'Group name contains characters that are not allowed';
    if (!preg_match('/^[0-9]$/', $code)) $errors[] = 'ID digit must be a single digit (0–9)';
    foreach ($groups as $g) {
        if (strcasecmp($g['name'], $name) === 0) $errors[] = "Group \"$name\" already exists";
        if ((string)$g['code'] === $code) $errors[] = "ID digit $code is already used by {$g['name']}";
    }
    if ($errors) Response::validationError(array_values(array_unique($errors)));
    catalogWidenGroupColumn();
    $groups[] = ['name' => $name, 'code' => $code];
    catalogSave('catalog_groups', $groups, (int)$user['id']);
    Response::success(catalogGroups(), "Group \"$name\" added");
});

$router->delete('/api/admin/catalog/groups', function () {
    $user = Auth::requireRole('admin');
    $name = trim((string)($_GET['name'] ?? ''));
    $groups = catalogGroups();
    if (!in_array($name, array_column($groups, 'name'), true)) Response::notFound('Group not found');
    $uses = [];
    $n = (int)(Database::fetch("SELECT COUNT(*) n FROM students WHERE student_group = ?", [$name])['n'] ?? 0);
    if ($n) $uses[] = "$n student(s)";
    $n = (int)(Database::fetch("SELECT COUNT(*) n FROM subjects WHERE `group` = ?", [$name])['n'] ?? 0);
    if ($n) $uses[] = "$n subject(s)";
    $n = (int)(Database::fetch("SELECT COUNT(*) n FROM teachers WHERE `group` = ?", [$name])['n'] ?? 0);
    if ($n) $uses[] = "$n teacher(s)";
    if ($uses) Response::validationError(["Cannot remove \"$name\": it is used by " . implode(', ', $uses)]);
    $groups = array_values(array_filter($groups, fn($g) => $g['name'] !== $name));
    catalogSave('catalog_groups', $groups, (int)$user['id']);
    Response::success(catalogGroups(), "Group \"$name\" removed");
});
