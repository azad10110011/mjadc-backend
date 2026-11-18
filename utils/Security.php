<?php

/**
 * Security helpers (Session 112):
 *   - login brute-force throttling (per IP + email)
 *   - forced password change after first login (users.must_change_password)
 *   - random temporary passwords for admin-created accounts
 *
 * Schema is created/altered lazily so no manual migration is needed on live.
 */

if (!function_exists('securityEnsureSchema')) {

    function securityEnsureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;

        // Throttle store: one row per "ip|email" identity.
        Database::query(
            "CREATE TABLE IF NOT EXISTS login_attempts (
                ident VARCHAR(190) PRIMARY KEY,
                attempts INT NOT NULL DEFAULT 0,
                locked_until DATETIME NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        // users.must_change_password — add once if missing (MySQL has no ADD COLUMN IF NOT EXISTS).
        $col = Database::fetch(
            "SELECT 1 FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'must_change_password'"
        );
        if (!$col) {
            Database::query("ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0");
        }
    }

    // ---- login throttling -------------------------------------------------

    define('LOGIN_MAX_ATTEMPTS', 5);   // failures before a lock
    define('LOGIN_LOCK_MINUTES', 15);  // how long the lock lasts

    function loginThrottleKey(string $email): string
    {
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        return substr($ip . '|' . strtolower(trim($email)), 0, 190);
    }

    /** Call BEFORE verifying the password. Exits with 429 while the identity is locked. */
    function loginThrottleGuard(string $email): void
    {
        securityEnsureSchema();
        $row = Database::fetch("SELECT locked_until FROM login_attempts WHERE ident = ?", [loginThrottleKey($email)]);
        if ($row && $row['locked_until'] && strtotime($row['locked_until']) > time()) {
            $mins = max(1, (int)ceil((strtotime($row['locked_until']) - time()) / 60));
            Response::error("Too many failed login attempts. Please try again in {$mins} minute(s).", 429);
        }
    }

    /** Call on a FAILED password check. Locks the identity once the limit is reached. */
    function loginThrottleFail(string $email): void
    {
        securityEnsureSchema();
        $key = loginThrottleKey($email);
        $row = Database::fetch("SELECT attempts FROM login_attempts WHERE ident = ?", [$key]);
        $attempts = ($row ? (int)$row['attempts'] : 0) + 1;
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $lockedUntil = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            Database::query(
                "INSERT INTO login_attempts (ident, attempts, locked_until) VALUES (?, 0, ?)
                 ON DUPLICATE KEY UPDATE attempts = 0, locked_until = VALUES(locked_until)",
                [$key, $lockedUntil]
            );
        } else {
            Database::query(
                "INSERT INTO login_attempts (ident, attempts, locked_until) VALUES (?, ?, NULL)
                 ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), locked_until = NULL",
                [$key, $attempts]
            );
        }
    }

    /** Call on a SUCCESSFUL login. Clears the counter. */
    function loginThrottleReset(string $email): void
    {
        securityEnsureSchema();
        Database::query("DELETE FROM login_attempts WHERE ident = ?", [loginThrottleKey($email)]);
    }

    // ---- temporary passwords ---------------------------------------------

    /** A readable random temp password (no ambiguous characters). */
    function genTempPassword(): string
    {
        $pick = function (string $set, int $n): string {
            $o = '';
            for ($i = 0; $i < $n; $i++) $o .= $set[random_int(0, strlen($set) - 1)];
            return $o;
        };
        $raw = $pick('ABCDEFGHJKLMNPQRSTUVWXYZ', 2)
             . $pick('abcdefghijkmnopqrstuvwxyz', 4)
             . $pick('23456789', 3)
             . $pick('@#%&*', 1);
        return $raw;
    }

    /**
     * Resolve the password for a newly created account.
     * @return array{0:string,1:int,2:?string} [password_hash, must_change(0|1), plain_temp|null]
     *   - explicit non-empty password → use it, no forced change.
     *   - otherwise → random temp, forced change, plain temp returned so the admin can pass it on.
     */
    function newAccountPassword($provided): array
    {
        securityEnsureSchema();
        $provided = is_string($provided) ? trim($provided) : '';
        if ($provided !== '') {
            return [Auth::hashPassword($provided), 0, null];
        }
        $temp = genTempPassword();
        return [Auth::hashPassword($temp), 1, $temp];
    }
}
