<?php

// User permissions management (Session 80) — Admin → Users → User Permissions. Admin only.
//   GET /api/admin/permissions/catalog          all permissions (grouped on the client)
//   GET /api/admin/permissions/user/{id}        one user's roles, overrides and effective permissions
//   PUT /api/admin/permissions/user/{id}        { overrides: { "<key>": "grant" | "revoke" | "default" } }

$router->get('/api/admin/permissions/catalog', function () {
    Auth::requireRole('admin');
    $out = [];
    foreach (Permissions::catalog() as $it) {
        $out[] = ['key' => $it['key'], 'label' => $it['label'], 'group' => $it['group'], 'roles' => $it['roles'], 'grantable' => $it['grantable']];
    }
    Response::success($out);
});

$router->get('/api/admin/permissions/user/{id}', function ($params) {
    Auth::requireRole('admin');
    $target = permissionsLoadUser((int)$params['id']);
    Response::success([
        'user' => ['id' => (int)$target['id'], 'name' => $target['name'], 'email' => $target['email'], 'roles' => $target['roles'], 'status' => $target['status']],
        'is_admin' => Permissions::isAdmin($target),
        'overrides' => (object)Permissions::overrides((int)$target['id']),
        'effective' => Permissions::effective($target),
    ]);
});

$router->put('/api/admin/permissions/user/{id}', function ($params) {
    $admin = Auth::requireRole('admin');
    $target = permissionsLoadUser((int)$params['id']);
    if (Permissions::isAdmin($target)) {
        Response::validationError(['Admins always have every permission — nothing to change.']);
    }
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $overrides = $data['overrides'] ?? null;
    if (!is_array($overrides)) {
        Response::validationError(['overrides must be an object of { permission: "grant" | "revoke" | "default" }']);
    }

    $errors = [];
    $clean = [];
    foreach ($overrides as $key => $effect) {
        $item = Permissions::find((string)$key);
        if (!$item) { $errors[] = "Unknown permission: {$key}"; continue; }
        if (!in_array($effect, ['grant', 'revoke', 'default'], true)) { $errors[] = "Invalid value for {$item['label']}"; continue; }
        $isDefault = Permissions::isDefault($item, $target['roles']);
        if ($effect === 'grant' && !$item['grantable']) { $errors[] = "{$item['label']} is admin-only and cannot be granted"; continue; }
        // store only real changes: granting a default permission or revoking a non-default one is a no-op
        if (($effect === 'grant' && $isDefault) || ($effect === 'revoke' && !$isDefault)) $effect = 'default';
        $clean[$item['key']] = $effect;
    }
    if ($errors) {
        Response::validationError($errors);
    }

    Permissions::ensureSchema();
    $uid = (int)$target['id'];
    foreach ($clean as $key => $effect) {
        if ($effect === 'default') {
            Database::query("DELETE FROM user_permissions WHERE user_id = ? AND perm_key = ?", [$uid, $key]);
        } else {
            Database::query(
                "INSERT INTO user_permissions (user_id, perm_key, effect, updated_by) VALUES (?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE effect = VALUES(effect), updated_by = VALUES(updated_by)",
                [$uid, $key, $effect, $admin['id']]
            );
        }
    }
    Permissions::clearCache($uid);

    Response::success([
        'overrides' => (object)Permissions::overrides($uid),
        'effective' => Permissions::effective($target),
    ], 'Permissions saved');
});

if (!function_exists('permissionsLoadUser')) {
function permissionsLoadUser(int $id): array
{
    $u = Database::fetch(
        "SELECT u.id, u.name, u.email, u.status, GROUP_CONCAT(ur.role) AS roles
         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id
         WHERE u.id = ? GROUP BY u.id",
        [$id]
    );
    if (!$u) {
        Response::notFound('User not found');
    }
    $u['roles'] = $u['roles'] ? explode(',', $u['roles']) : [];
    return $u;
}}
