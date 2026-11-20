<?php
// ID Card templates + QR verification (added 2026-11-20).
//  - The ID card layout is stored as JSON (one template per audience: students / teachers / staff) so Admin can
//    add/remove items and move/resize them in the graphical designer. The ID Cards page renders from this template.
//  - Each card carries a QR -> /verify/id/?type=<a>&id=<dbId>&t=<token>. <token> is an HMAC so links can't be guessed.
//  - Admin-only APIs here; the public scan endpoint lives in routes/public/id-verify.php.

if (!function_exists('cardTplEnsureSchema')) {
function cardTplEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    // person_id = 0 holds the audience-wide default; any other id is a per-person override design.
    Database::query(
        "CREATE TABLE IF NOT EXISTS card_templates (
            audience VARCHAR(16) NOT NULL,
            person_id INT NOT NULL DEFAULT 0,
            layout_json MEDIUMTEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (audience, person_id)
        )"
    );
    // Upgrade an older table that keyed only by audience (added 2026-11-20).
    $hasCol = Database::fetch("SELECT COUNT(*) AS n FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'card_templates' AND COLUMN_NAME = 'person_id'");
    if (!$hasCol || (int)$hasCol['n'] === 0) {
        Database::getInstance()->exec("ALTER TABLE card_templates ADD COLUMN person_id INT NOT NULL DEFAULT 0");
        Database::getInstance()->exec("ALTER TABLE card_templates DROP PRIMARY KEY, ADD PRIMARY KEY (audience, person_id)");
    }
    // Shared with transcript verification; CREATE IF NOT EXISTS is idempotent.
    Database::query(
        "CREATE TABLE IF NOT EXISTS app_secrets (
            name VARCHAR(64) PRIMARY KEY,
            value VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        )"
    );
    $done = true;
}}

if (!function_exists('cardVerifySecret')) {
function cardVerifySecret(): string
{
    static $secret = null;
    if ($secret !== null) return $secret;
    cardTplEnsureSchema();
    Database::query("INSERT IGNORE INTO app_secrets (name, value) VALUES ('id_card_verify', ?)", [bin2hex(random_bytes(32))]);
    $row = Database::fetch("SELECT value FROM app_secrets WHERE name = 'id_card_verify'");
    $secret = $row['value'];
    return $secret;
}}

// token over "<type>|<dbId>" — enough to stop walking ids; the scan endpoint re-reads live data.
if (!function_exists('cardVerifyToken')) {
function cardVerifyToken(string $audience, int $id): string
{
    return substr(hash_hmac('sha256', $audience . '|' . $id, cardVerifySecret()), 0, 20);
}}

if (!function_exists('cardAudiences')) {
function cardAudiences(): array { return ['students', 'teachers', 'staff']; }}

// ---- ID card expiry (added 2026-11-20): a default "valid till" date for everyone of an audience,
// plus per-person overrides. person_id = 0 holds the audience default.
if (!function_exists('cardExpiryEnsure')) {
function cardExpiryEnsure(): void
{
    static $done = false;
    if ($done) return;
    Database::query(
        "CREATE TABLE IF NOT EXISTS card_expiry (
            audience VARCHAR(16) NOT NULL,
            person_id INT NOT NULL,
            expire_date DATE NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (audience, person_id)
        )"
    );
    $done = true;
}}

// { default: 'YYYY-MM-DD'|null, overrides: [person_id => 'YYYY-MM-DD'] } for one audience.
if (!function_exists('cardExpiryMap')) {
function cardExpiryMap(string $audience): array
{
    cardExpiryEnsure();
    $rows = Database::fetchAll("SELECT person_id, expire_date FROM card_expiry WHERE audience = ?", [$audience]);
    $default = null; $ov = [];
    foreach ($rows as $r) {
        $d = $r['expire_date'] ?: null;
        if ((int)$r['person_id'] === 0) $default = $d;
        else $ov[(int)$r['person_id']] = $d;
    }
    return ['default' => $default, 'overrides' => $ov];
}}

// The effective expiry for one person (override first, then the audience default).
if (!function_exists('cardExpiryFor')) {
function cardExpiryFor(string $audience, int $personId): ?string
{
    $m = cardExpiryMap($audience);
    if (array_key_exists($personId, $m['overrides'])) return $m['overrides'][$personId];
    return $m['default'];
}}

// Field keys an element can bind to, per audience, with a human label. Also used to validate a saved template
// and to populate the designer's "Add item" menu. Keys not listed here are rejected on save.
if (!function_exists('cardFieldKeys')) {
function cardFieldKeys(string $audience): array
{
    $common = [
        'name' => 'Name', 'blood_group' => 'Blood group',
        'mobile' => 'Mobile', 'emergency' => 'Emergency contact', 'valid' => 'Valid (year–year)',
        'expire_date' => 'Valid till (expiry)',
        'college_name' => 'College name', 'college_address' => 'College address',
    ];
    if ($audience === 'students') return $common + [
        'student_id' => 'Student ID', 'class' => 'Class', 'section' => 'Section', 'student_group' => 'Group',
        'full_class' => 'Class (with group/section)', 'academic_session' => 'Session', 'father_name' => "Father's name",
        'mother_name' => "Mother's name", 'date_of_birth' => 'Date of birth', 'address' => 'Address',
        'parent_mobile' => 'Parent mobile',
    ];
    if ($audience === 'teachers') return $common + [
        'name_bangla' => 'Name (Bangla)', 'designation' => 'Designation', 'subject' => 'Subject',
        'teacher_group' => 'Group', 'joining_date' => 'Joining date', 'email' => 'Email',
    ];
    return $common + ['name_bangla' => 'Name (Bangla)', 'designation' => 'Designation', 'joining_date' => 'Joining date', 'email' => 'Email'];
}}

// Default layout per audience (card 54mm x 86mm, portrait). Reproduced by "Reset to default".
if (!function_exists('cardDefaultTemplate')) {
function cardDefaultTemplate(string $audience): array
{
    $typeLabel = ['students' => 'STUDENT', 'teachers' => 'TEACHER', 'staff' => 'STAFF'][$audience] ?? 'ID';
    $els = [
        ['id' => 'bg_head', 'type' => 'box', 'x' => 0, 'y' => 0, 'w' => 54, 'h' => 17, 'z' => 1, 'bg' => '#1e3a5f'],
        ['id' => 'logo', 'type' => 'image', 'src' => 'logo', 'x' => 3, 'y' => 2.5, 'w' => 12, 'h' => 12, 'z' => 2],
        ['id' => 'cname', 'type' => 'field', 'field' => 'college_name', 'x' => 16, 'y' => 3, 'w' => 36, 'h' => 6, 'z' => 2, 'fontSize' => 9, 'bold' => true, 'color' => '#ffffff', 'align' => 'left'],
        ['id' => 'caddr', 'type' => 'field', 'field' => 'college_address', 'x' => 16, 'y' => 10, 'w' => 36, 'h' => 5, 'z' => 2, 'fontSize' => 7, 'color' => '#dbeafe', 'align' => 'left'],
        ['id' => 'typebar', 'type' => 'text', 'text' => $typeLabel . ' ID CARD', 'x' => 0, 'y' => 17, 'w' => 54, 'h' => 5, 'z' => 2, 'fontSize' => 8, 'bold' => true, 'color' => '#1e3a5f', 'bg' => '#fbbf24', 'align' => 'center'],
        ['id' => 'photo', 'type' => 'photo', 'x' => 19, 'y' => 24, 'w' => 16, 'h' => 20, 'z' => 2, 'border' => '#d1d5db'],
        ['id' => 'pname', 'type' => 'field', 'field' => 'name', 'x' => 2, 'y' => 45, 'w' => 50, 'h' => 5, 'z' => 2, 'fontSize' => 10, 'bold' => true, 'color' => '#111827', 'align' => 'center'],
    ];
    $rows = $audience === 'students'
        ? [['student_id', 'ID:'], ['full_class', 'Class:'], ['academic_session', 'Session:'], ['blood_group', 'Blood:'], ['emergency', 'Contact:']]
        : ($audience === 'teachers'
            ? [['designation', 'Designation:'], ['subject', 'Subject:'], ['joining_date', 'Joining:'], ['blood_group', 'Blood:'], ['emergency', 'Contact:']]
            : [['designation', 'Designation:'], ['joining_date', 'Joining:'], ['blood_group', 'Blood:'], ['emergency', 'Contact:']]);
    $y = 51;
    foreach ($rows as $i => [$key, $label]) {
        $els[] = ['id' => 'f_' . $key, 'type' => 'field', 'field' => $key, 'label' => $label, 'x' => 3, 'y' => $y, 'w' => 36, 'h' => 4.5, 'z' => 2, 'fontSize' => 7.5, 'color' => '#374151', 'align' => 'left'];
        $y += 5;
    }
    $els[] = ['id' => 'f_expire', 'type' => 'field', 'field' => 'expire_date', 'label' => 'Valid till:', 'x' => 3, 'y' => 70, 'w' => 30, 'h' => 4, 'z' => 2, 'fontSize' => 6.5, 'color' => '#b91c1c', 'align' => 'left'];
    $els[] = ['id' => 'qr', 'type' => 'qr', 'x' => 40, 'y' => 70, 'w' => 13, 'h' => 13, 'z' => 2];
    $els[] = ['id' => 'sig', 'type' => 'image', 'src' => 'signature', 'x' => 4, 'y' => 76, 'w' => 20, 'h' => 7, 'z' => 2];
    $els[] = ['id' => 'sigt', 'type' => 'text', 'text' => 'Principal', 'x' => 4, 'y' => 82.5, 'w' => 20, 'h' => 3.5, 'z' => 2, 'fontSize' => 6, 'color' => '#4b5563', 'align' => 'center'];
    return ['width_mm' => 54, 'height_mm' => 86, 'background' => '#ffffff', 'elements' => $els];
}}

// Validate + clamp a template coming from the client so a bad layout can never break printing.
if (!function_exists('cardSanitizeLayout')) {
function cardSanitizeLayout(array $in, string $audience): array
{
    $allowedTypes = ['field', 'text', 'photo', 'qr', 'image', 'box', 'line'];
    $keys = cardFieldKeys($audience);
    $num = function ($v, $min, $max, $def) { $v = is_numeric($v) ? (float)$v : $def; return max($min, min($max, round($v, 2))); };
    $color = function ($v, $def) { $v = is_string($v) ? trim($v) : ''; return preg_match('/^#[0-9a-fA-F]{3,8}$/', $v) ? $v : $def; };
    $str = function ($v, $max) { return mb_substr(is_string($v) ? $v : '', 0, $max); };

    $w = $num($in['width_mm'] ?? 54, 20, 300, 54);
    $h = $num($in['height_mm'] ?? 86, 20, 300, 86);
    $elsIn = is_array($in['elements'] ?? null) ? $in['elements'] : [];
    $els = [];
    foreach (array_slice($elsIn, 0, 80) as $i => $e) {
        if (!is_array($e)) continue;
        $type = in_array($e['type'] ?? '', $allowedTypes, true) ? $e['type'] : 'text';
        $el = [
            'id' => $str($e['id'] ?? ('el' . $i), 24) ?: ('el' . $i),
            'type' => $type,
            'x' => $num($e['x'] ?? 0, -50, 350, 0),
            'y' => $num($e['y'] ?? 0, -50, 350, 0),
            'w' => $num($e['w'] ?? 20, 1, 350, 20),
            'h' => $num($e['h'] ?? 6, 0.3, 350, 6),
            'z' => (int)$num($e['z'] ?? 2, 0, 999, 2),
            'fontSize' => $num($e['fontSize'] ?? 8, 3, 72, 8),
            'align' => in_array($e['align'] ?? 'left', ['left', 'center', 'right'], true) ? $e['align'] : 'left',
            'color' => $color($e['color'] ?? '#374151', '#374151'),
        ];
        if (!empty($e['bold'])) $el['bold'] = true;
        if (!empty($e['italic'])) $el['italic'] = true;
        if (isset($e['bg']) && $e['bg'] !== '') $el['bg'] = $color($e['bg'], '');
        if (isset($e['border']) && $e['border'] !== '') $el['border'] = $color($e['border'], '');
        if (isset($e['radius'])) $el['radius'] = $num($e['radius'], 0, 50, 0);
        if ($type === 'field') {
            $f = $str($e['field'] ?? '', 32);
            if (!isset($keys[$f])) continue; // unknown binding -> drop the element
            $el['field'] = $f;
            // Label is a free template (brackets, commas, {placeholders} all allowed); only length-capped.
            if (isset($e['label'])) $el['label'] = $str($e['label'], 120);
        } elseif ($type === 'text') {
            $el['text'] = $str($e['text'] ?? '', 200);
        } elseif ($type === 'image') {
            $el['src'] = in_array($e['src'] ?? '', ['logo', 'signature'], true) ? $e['src'] : 'logo';
        }
        $els[] = $el;
    }
    return ['width_mm' => $w, 'height_mm' => $h, 'background' => $color($in['background'] ?? '#ffffff', '#ffffff'), 'elements' => $els];
}}

if (!function_exists('cardTemplateFor')) {
// $personId > 0 returns that person's own design if one exists, otherwise falls back to the
// audience-wide default (person_id = 0) and then the built-in layout.
function cardTemplateFor(string $audience, int $personId = 0): array
{
    cardTplEnsureSchema();
    if ($personId > 0) {
        $row = Database::fetch("SELECT layout_json FROM card_templates WHERE audience = ? AND person_id = ?", [$audience, $personId]);
        if ($row) {
            $t = json_decode($row['layout_json'], true);
            if (is_array($t) && isset($t['elements'])) return cardSanitizeLayout($t, $audience);
        }
    }
    $row = Database::fetch("SELECT layout_json FROM card_templates WHERE audience = ? AND person_id = 0", [$audience]);
    if ($row) {
        $t = json_decode($row['layout_json'], true);
        if (is_array($t) && isset($t['elements'])) return cardSanitizeLayout($t, $audience);
    }
    return cardDefaultTemplate($audience);
}}

// Ids of people (of one audience) who have their own saved design.
if (!function_exists('cardCustomIds')) {
function cardCustomIds(string $audience): array
{
    cardTplEnsureSchema();
    $rows = Database::fetchAll("SELECT person_id FROM card_templates WHERE audience = ? AND person_id > 0", [$audience]);
    return array_map(fn($r) => (int)$r['person_id'], $rows);
}}

// A sample person for the designer preview (first real record, else a dummy).
if (!function_exists('cardSamplePerson')) {
function cardSamplePerson(string $audience, int $personId = 0): array
{
    $sql = match ($audience) {
        'students' => "SELECT id, student_id, name, father_name, mother_name, class, section, student_group, photo_path, academic_session, gender, mobile, parent_mobile, address, date_of_birth, blood_group FROM students",
        'teachers' => "SELECT id, name, name_bangla, designation, subject, `group` AS teacher_group, joining_date, photo_path, mobile, email, gender, blood_group FROM teachers",
        'staff' => "SELECT id, name, name_bangla, designation, joining_date, photo_path, mobile, email, gender, blood_group FROM staff",
    };
    $sql .= $personId > 0 ? " WHERE id = ?" : " ORDER BY name LIMIT 1";
    $p = $personId > 0 ? Database::fetch($sql, [$personId]) : Database::fetch($sql);
    $p = $p ?: [];
    if (!$p) $p = ['id' => 0, 'name' => 'Sample Name', 'designation' => 'Sample', 'student_id' => '000000', 'class' => 'XII', 'blood_group' => 'O+'];
    $p['verify_token'] = cardVerifyToken($audience, (int)($p['id'] ?? 0));
    $p['expire_date'] = cardExpiryFor($audience, (int)($p['id'] ?? 0));
    return $p;
}}

// ---- Admin routes (admin only) ----

$router->get('/api/admin/card-templates', function () {
    Auth::requireRole('admin');
    $audience = $_GET['audience'] ?? 'students';
    if (!in_array($audience, $aud = cardAudiences(), true)) Response::validationError(['Invalid audience']);
    $keys = cardFieldKeys($audience);
    $fields = [];
    foreach ($keys as $k => $label) $fields[] = ['key' => $k, 'label' => $label];
    // Optional: person_id = load that person's own design (or the default); ids = 1,2,3 returns each
    // person's own design in a map (used at print time when some people have a custom design).
    $personId = (int)($_GET['person_id'] ?? 0);
    $out = [
        'audience' => $audience,
        'template' => cardTemplateFor($audience, $personId),
        'fields' => $fields,
        'sample' => cardSamplePerson($audience, $personId),
        'custom_ids' => cardCustomIds($audience),
    ];
    $idsParam = trim((string)($_GET['ids'] ?? ''));
    if ($idsParam !== '') {
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $idsParam)), fn($i) => $i > 0)));
        $personTemplates = [];
        if ($ids) {
            $in = implode(',', array_fill(0, count($ids), '?'));
            $rows = Database::fetchAll("SELECT person_id, layout_json FROM card_templates WHERE audience = ? AND person_id IN ($in)", array_merge([$audience], $ids));
            foreach ($rows as $r) {
                $t = json_decode($r['layout_json'], true);
                if (is_array($t) && isset($t['elements'])) $personTemplates[(int)$r['person_id']] = cardSanitizeLayout($t, $audience);
            }
        }
        $out['person_templates'] = $personTemplates;
    }
    Response::success($out);
});

$router->put('/api/admin/card-templates', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $audience = $data['audience'] ?? '';
    if (!in_array($audience, cardAudiences(), true)) Response::validationError(['Invalid audience']);
    $clean = cardSanitizeLayout($data, $audience);
    cardTplEnsureSchema();
    // ids = [] -> save as the audience-wide default (everyone). ids = [7,9] -> save as a personal
    // design used only for those people (they fall back to the default if their row is removed).
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['ids'] ?? [])), fn($i) => $i > 0)));
    $json = json_encode($clean, JSON_UNESCAPED_UNICODE);
    if (!$ids) {
        Database::query(
            "INSERT INTO card_templates (audience, person_id, layout_json, updated_by) VALUES (?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), updated_by = VALUES(updated_by)",
            [$audience, $json, (int)$user['id']]
        );
    } else {
        foreach ($ids as $pid) {
            Database::query(
                "INSERT INTO card_templates (audience, person_id, layout_json, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE layout_json = VALUES(layout_json), updated_by = VALUES(updated_by)",
                [$audience, $pid, $json, (int)$user['id']]
            );
        }
    }
    Response::success(['template' => $clean, 'saved_for' => $ids ? $ids : 'all'], $ids ? 'ID card design saved for the selected people' : 'ID card design saved for all');
});

$router->post('/api/admin/card-templates/reset', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $audience = $data['audience'] ?? '';
    if (!in_array($audience, cardAudiences(), true)) Response::validationError(['Invalid audience']);
    cardTplEnsureSchema();
    // ids given -> remove only those people's personal designs (they go back to the default);
    // no ids -> wipe the audience-wide default AND every personal design.
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['ids'] ?? [])), fn($i) => $i > 0)));
    if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        Database::query("DELETE FROM card_templates WHERE audience = ? AND person_id IN ($in)", array_merge([$audience], $ids));
    } else {
        Database::query("DELETE FROM card_templates WHERE audience = ?", [$audience]);
    }
    Response::success(['template' => cardDefaultTemplate($audience), 'custom_ids' => cardCustomIds($audience)], 'Reset to the default design');
});

// ---- ID card expiry (admin only) ----

if (!function_exists('cardExpiryValid')) {
function cardExpiryValid($v): ?string
{
    if ($v === null || $v === '' ) return null;
    $v = trim((string)$v);
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : false; // false = invalid
}}

// GET /api/admin/card-expiry?audience=  -> default + the list of people who have a specific date
$router->get('/api/admin/card-expiry', function () {
    Auth::requireRole('admin');
    $audience = $_GET['audience'] ?? 'students';
    if (!in_array($audience, cardAudiences(), true)) Response::validationError(['Invalid audience']);
    $m = cardExpiryMap($audience);
    $overrides = [];
    if ($m['overrides']) {
        $ids = array_keys($m['overrides']);
        $in = implode(',', array_fill(0, count($ids), '?'));
        $table = $audience; // students|teachers|staff
        $nameCol = $audience === 'students' ? 'name, student_id' : 'name';
        $rows = Database::fetchAll("SELECT id, $nameCol FROM `$table` WHERE id IN ($in)", $ids);
        $byId = [];
        foreach ($rows as $r) $byId[(int)$r['id']] = $r;
        foreach ($m['overrides'] as $pid => $date) {
            $r = $byId[$pid] ?? null;
            $overrides[] = ['id' => $pid, 'name' => $r['name'] ?? ('#' . $pid), 'identifier' => $r['student_id'] ?? '', 'expire_date' => $date];
        }
    }
    Response::success(['audience' => $audience, 'default' => $m['default'], 'overrides' => $overrides]);
});

// PUT /api/admin/card-expiry/default  { audience, expire_date|null }  (null/"" = no expiry for all)
$router->put('/api/admin/card-expiry/default', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $audience = $data['audience'] ?? '';
    if (!in_array($audience, cardAudiences(), true)) Response::validationError(['Invalid audience']);
    $date = cardExpiryValid($data['expire_date'] ?? null);
    if ($date === false) Response::validationError(['Enter a valid date (YYYY-MM-DD)']);
    cardExpiryEnsure();
    if ($date === null) {
        Database::query("DELETE FROM card_expiry WHERE audience = ? AND person_id = 0", [$audience]);
    } else {
        Database::query(
            "INSERT INTO card_expiry (audience, person_id, expire_date, updated_by) VALUES (?, 0, ?, ?)
             ON DUPLICATE KEY UPDATE expire_date = VALUES(expire_date), updated_by = VALUES(updated_by)",
            [$audience, $date, (int)$user['id']]
        );
    }
    Response::success(['default' => $date], 'Default expiry saved');
});

// PUT /api/admin/card-expiry/people  { audience, ids:[], expire_date|null }  (null = remove override -> use default)
$router->put('/api/admin/card-expiry/people', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $audience = $data['audience'] ?? '';
    if (!in_array($audience, cardAudiences(), true)) Response::validationError(['Invalid audience']);
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($data['ids'] ?? [])), fn($i) => $i > 0)));
    if (!$ids) Response::validationError(['Select at least one person']);
    $date = cardExpiryValid($data['expire_date'] ?? null);
    if ($date === false) Response::validationError(['Enter a valid date (YYYY-MM-DD)']);
    cardExpiryEnsure();
    $in = implode(',', array_fill(0, count($ids), '?'));
    if ($date === null) {
        Database::query("DELETE FROM card_expiry WHERE audience = ? AND person_id IN ($in)", array_merge([$audience], $ids));
    } else {
        foreach ($ids as $pid) {
            Database::query(
                "INSERT INTO card_expiry (audience, person_id, expire_date, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE expire_date = VALUES(expire_date), updated_by = VALUES(updated_by)",
                [$audience, $pid, $date, (int)$user['id']]
            );
        }
    }
    Response::success(['count' => count($ids), 'expire_date' => $date], $date === null ? 'Removed the expiry for the selected people' : 'Expiry date saved for the selected people');
});
