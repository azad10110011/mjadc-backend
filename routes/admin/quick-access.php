<?php

// Dashboard Quick Access (Session 110).
// Every panel dashboard shows a "Quick Access" list of page shortcuts. The admin decides:
//   • the DEFAULT list of each panel (site_settings 'quick_access_defaults'):
//       { panels: { "<panel>": ["/path", …] }, add_assigned: bool }
//     A panel not in `panels` uses the built-in list in the frontend (lib/quickAccess.tsx).
//     add_assigned = also show pages granted to the user from OTHER panels (default true).
//   • a CUSTOM list for one user in one panel (table user_quick_access) — replaces the default.
// Items are page paths; the dashboard still hides any page the user may not open (permissions).
//
//   GET    /api/quick-access                     (any logged-in user) defaults + the user's own lists
//   GET    /api/admin/quick-access               (admin) defaults
//   PUT    /api/admin/quick-access/defaults      (admin) { panel, items: [...] | null }  null = built-in
//   PUT    /api/admin/quick-access/options       (admin) { add_assigned: bool }
//   GET    /api/admin/quick-access/user/{id}     (admin) that user's custom lists
//   PUT    /api/admin/quick-access/user/{id}     (admin) { panel, items: [...] | null }  null = use default

const QA_PANELS = ['admin', 'principal', 'administration', 'exam_controller', 'teacher', 'staff', 'student'];
const QA_MAX_ITEMS = 40;

if (!function_exists('qaEnsureSchema')) {
function qaEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    Database::query(
        "CREATE TABLE IF NOT EXISTS user_quick_access (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            panel VARCHAR(30) NOT NULL,
            items TEXT NOT NULL,
            updated_by INT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_user_panel (user_id, panel),
            CONSTRAINT fk_user_quick_access_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    );
    $done = true;
}

function qaDefaults(): array
{
    $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'quick_access_defaults'");
    $v = $row ? json_decode((string)$row['setting_value'], true) : null;
    $panels = [];
    foreach ((is_array($v['panels'] ?? null) ? $v['panels'] : []) as $p => $items) {
        if (in_array($p, QA_PANELS, true) && is_array($items)) $panels[$p] = array_values(array_filter($items, 'is_string'));
    }
    return ['panels' => (object)$panels, 'add_assigned' => !isset($v['add_assigned']) || (bool)$v['add_assigned']];
}

function qaSaveDefaults(array $d, int $by): void
{
    $json = json_encode(['panels' => (object)$d['panels'], 'add_assigned' => (bool)$d['add_assigned']], JSON_UNESCAPED_SLASHES);
    if (Database::fetch("SELECT id FROM site_settings WHERE setting_key = 'quick_access_defaults'")) {
        Database::query("UPDATE site_settings SET setting_value = ?, updated_by = ? WHERE setting_key = 'quick_access_defaults'", [$json, $by]);
    } else {
        Database::query("INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('quick_access_defaults', ?, ?)", [$json, $by]);
    }
}

function qaUserLists(int $userId): array
{
    qaEnsureSchema();
    $out = [];
    foreach (Database::fetchAll("SELECT panel, items FROM user_quick_access WHERE user_id = ?", [$userId]) as $r) {
        $items = json_decode((string)$r['items'], true);
        if (is_array($items)) $out[$r['panel']] = array_values(array_filter($items, 'is_string'));
    }
    return $out;
}

/** Validate the request body { panel, items } → [panel, items|null]. */
function qaReadBody(): array
{
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $panel = (string)($d['panel'] ?? '');
    $errors = [];
    if (!in_array($panel, QA_PANELS, true)) $errors[] = 'Unknown panel';
    $items = $d['items'] ?? null;
    if ($items !== null) {
        if (!is_array($items)) {
            $errors[] = 'items must be a list of page paths or null';
        } else {
            $clean = [];
            foreach ($items as $it) {
                $it = trim((string)$it);
                if (!preg_match('#^/[A-Za-z0-9_\-/]*(\?[A-Za-z0-9_=&\-]*)?$#', $it) || strlen($it) > 150) { $errors[] = "Invalid page: {$it}"; continue; }
                if (!in_array($it, $clean, true)) $clean[] = $it;
            }
            if (count($clean) > QA_MAX_ITEMS) $errors[] = 'At most ' . QA_MAX_ITEMS . ' shortcuts per list';
            $items = $clean;
        }
    }
    if ($errors) Response::validationError($errors);
    return [$panel, $items];
}
}

$router->get('/api/quick-access', function () {
    $user = Auth::requireAuth();
    Response::success(['defaults' => qaDefaults(), 'mine' => (object)qaUserLists((int)$user['id'])]);
});

$router->get('/api/admin/quick-access', function () {
    Auth::requireRole('admin');
    Response::success(qaDefaults());
});

$router->put('/api/admin/quick-access/defaults', function () {
    $admin = Auth::requireRole('admin');
    [$panel, $items] = qaReadBody();
    $d = qaDefaults();
    $panels = (array)$d['panels'];
    if ($items === null) unset($panels[$panel]); else $panels[$panel] = $items;
    $d['panels'] = $panels;
    qaSaveDefaults($d, (int)$admin['id']);
    Response::success(qaDefaults(), $items === null ? 'Reset to the built-in list' : 'Default Quick Access saved');
});

$router->put('/api/admin/quick-access/options', function () {
    $admin = Auth::requireRole('admin');
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    $d = qaDefaults();
    $d['panels'] = (array)$d['panels'];
    if (array_key_exists('add_assigned', $body)) $d['add_assigned'] = (bool)$body['add_assigned'];
    qaSaveDefaults($d, (int)$admin['id']);
    Response::success(qaDefaults(), 'Saved');
});

$router->get('/api/admin/quick-access/user/{id}', function ($params) {
    Auth::requireRole('admin');
    $uid = (int)$params['id'];
    if (!Database::fetch("SELECT id FROM users WHERE id = ?", [$uid])) Response::notFound('User not found');
    Response::success(['lists' => (object)qaUserLists($uid)]);
});

$router->put('/api/admin/quick-access/user/{id}', function ($params) {
    $admin = Auth::requireRole('admin');
    $uid = (int)$params['id'];
    if (!Database::fetch("SELECT id FROM users WHERE id = ?", [$uid])) Response::notFound('User not found');
    [$panel, $items] = qaReadBody();
    qaEnsureSchema();
    if ($items === null) {
        Database::query("DELETE FROM user_quick_access WHERE user_id = ? AND panel = ?", [$uid, $panel]);
    } else {
        Database::query(
            "INSERT INTO user_quick_access (user_id, panel, items, updated_by) VALUES (?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE items = VALUES(items), updated_by = VALUES(updated_by)",
            [$uid, $panel, json_encode($items, JSON_UNESCAPED_SLASHES), $admin['id']]
        );
    }
    Response::success(['lists' => (object)qaUserLists($uid)], $items === null ? 'This user now uses the panel default' : 'Quick Access saved for this user');
});
