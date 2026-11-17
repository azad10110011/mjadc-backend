<?php

/**
 * Per-user permissions (Session 80).
 *
 * effective(user) = (catalog items whose default roles the user has) + grants − revokes.
 * Admin users always have everything and are never restricted by this class.
 *
 * Backend enforcement happens centrally in Auth::requireRole / requireAnyRole via allows():
 *   • user HAS the route's role  → allowed, unless the request URL belongs ONLY to permissions the
 *                                   admin explicitly revoked for this user;
 *   • user LACKS the route's role → allowed only if the URL belongs to a permission the user has
 *                                   (e.g. a teacher granted "Exam Fee Collection").
 * Catalog: config/permissions.php (key = page path, apis = "METHOD /api/path" patterns).
 */
class Permissions
{
    private static ?array $catalog = null;
    private static array $overrideCache = [];
    private static bool $schemaReady = false;

    public static function catalog(): array
    {
        if (self::$catalog === null) {
            $items = require __DIR__ . '/../config/permissions.php';
            foreach ($items as &$it) {
                $it['roles'] = $it['roles'] ?? [];
                $it['apis'] = $it['apis'] ?? [];
                $it['grantable'] = $it['grantable'] ?? true;
            }
            unset($it);
            self::$catalog = $items;
        }
        return self::$catalog;
    }

    public static function find(string $key): ?array
    {
        foreach (self::catalog() as $it) {
            if ($it['key'] === $key) return $it;
        }
        return null;
    }

    public static function ensureSchema(): void
    {
        if (self::$schemaReady) return;
        Database::query(
            "CREATE TABLE IF NOT EXISTS user_permissions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                perm_key VARCHAR(150) NOT NULL,
                effect ENUM('grant','revoke') NOT NULL,
                updated_by INT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_user_perm (user_id, perm_key),
                KEY idx_user (user_id),
                CONSTRAINT fk_user_permissions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        self::$schemaReady = true;
    }

    /** key => 'grant'|'revoke' for one user (cached per request). */
    public static function overrides(int $userId): array
    {
        if (!isset(self::$overrideCache[$userId])) {
            self::ensureSchema();
            $map = [];
            foreach (Database::fetchAll("SELECT perm_key, effect FROM user_permissions WHERE user_id = ?", [$userId]) as $r) {
                $map[$r['perm_key']] = $r['effect'];
            }
            self::$overrideCache[$userId] = $map;
        }
        return self::$overrideCache[$userId];
    }

    public static function clearCache(int $userId): void
    {
        unset(self::$overrideCache[$userId]);
    }

    public static function isAdmin(array $user): bool
    {
        return in_array('admin', (array)($user['roles'] ?? []), true);
    }

    /** Is this permission on by default for these roles? */
    public static function isDefault(array $item, array $roles): bool
    {
        return (bool)array_intersect($item['roles'], $roles);
    }

    /** Effective permission keys of a user. */
    public static function effective(array $user): array
    {
        $all = array_column(self::catalog(), 'key');
        if (self::isAdmin($user)) return $all;
        $roles = (array)($user['roles'] ?? []);
        $ov = self::overrides((int)$user['id']);
        $out = [];
        foreach (self::catalog() as $it) {
            $effect = $ov[$it['key']] ?? null;
            $on = self::isDefault($it, $roles);
            if ($effect === 'revoke') $on = false;
            if ($effect === 'grant' && $it['grantable']) $on = true;
            if ($on) $out[] = $it['key'];
        }
        return $out;
    }

    /**
     * Panel tasks chosen on the create / edit user form (Session 102).
     * $off = keys of the user's role-default tasks the admin UNticked → stored as REVOKE;
     * every other role-default task is switched back on (its revoke removed). Extra grants are kept.
     * Admins are never restricted, so nothing is stored for them.
     */
    public static function applyPanelTasks(int $userId, array $off, int $by): void
    {
        self::ensureSchema();
        $roles = array_column(Database::fetchAll("SELECT role FROM user_roles WHERE user_id = ?", [$userId]), 'role');
        if (in_array('admin', $roles, true)) return;
        $off = array_flip(array_map('strval', $off));
        foreach (self::catalog() as $it) {
            if (!self::isDefault($it, $roles)) continue;
            if (isset($off[$it['key']])) {
                Database::query(
                    "INSERT INTO user_permissions (user_id, perm_key, effect, updated_by) VALUES (?, ?, 'revoke', ?)
                     ON DUPLICATE KEY UPDATE effect = 'revoke', updated_by = VALUES(updated_by)",
                    [$userId, $it['key'], $by]);
            } else {
                Database::query("DELETE FROM user_permissions WHERE user_id = ? AND perm_key = ? AND effect = 'revoke'", [$userId, $it['key']]);
            }
        }
        self::clearCache($userId);
    }

    /** Does this user have this feature (page key)? Admin always does. (Session 101) */
    public static function has(array $user, string $key): bool
    {
        if (self::isAdmin($user)) return true;
        foreach (self::effective($user) as $k) {
            if ($k === $key || strpos($k, $key . '#') === 0) return true;
        }
        return false;
    }

    /** "METHOD /api/path" pattern vs request. Path matches itself or anything below it. */
    public static function patternMatches(string $pattern, string $method, string $path): bool
    {
        [$m, $p] = array_pad(preg_split('/\s+/', trim($pattern), 2), 2, '');
        if ($m !== '*' && strcasecmp($m, $method) !== 0) return false;
        $p = rtrim($p, '/');
        return $path === $p || strpos($path, $p . '/') === 0;
    }

    public static function itemMatches(array $item, string $method, string $path): bool
    {
        foreach ($item['apis'] as $pat) {
            if (self::patternMatches($pat, $method, $path)) return true;
        }
        return false;
    }

    /** Request path relative to the API folder, e.g. "/api/admin/exam-fees/report". */
    public static function requestPath(): string
    {
        $uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        $base = dirname($_SERVER['SCRIPT_NAME'] ?? '/');
        if ($base !== '/' && $base !== '\\' && strpos($uri, $base) === 0) {
            $uri = substr($uri, strlen($base));
        }
        return '/' . ltrim($uri, '/');
    }

    /**
     * Central decision used by Auth::requireRole/requireAnyRole.
     * $roleOk = the user has one of the roles the route asked for.
     */
    public static function allows(array $user, bool $roleOk): bool
    {
        if (self::isAdmin($user)) return $roleOk; // admins: exactly the old role behaviour, never restricted further
        $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
        $path = self::requestPath();
        $effective = array_flip(self::effective($user));

        $matchedAllowed = false;
        $matchedRevoked = false;
        $ov = self::overrides((int)$user['id']);
        foreach (self::catalog() as $it) {
            if (!self::itemMatches($it, $method, $path)) continue;
            if (isset($effective[$it['key']])) {
                $matchedAllowed = true;
            } elseif (($ov[$it['key']] ?? null) === 'revoke') {
                $matchedRevoked = true;
            }
        }
        if ($roleOk) {
            // a revoked feature's endpoints are blocked unless another allowed feature also uses them
            return !($matchedRevoked && !$matchedAllowed);
        }
        return $matchedAllowed;
    }
}
