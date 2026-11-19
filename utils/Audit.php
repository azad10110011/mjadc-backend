<?php

/**
 * Update History — admin-only audit log of every change (Session 121).
 *
 * How it works:
 *  - Every audited table gets three database triggers (after insert / update / delete). They copy the row
 *    before and after the change (as JSON) into `audit_log`, so changes are captured no matter which code
 *    path made them (helpers, raw SQL, bulk updates).
 *  - Each request tells MySQL who is acting through session variables (@aud_*): user, roles, IP, browser
 *    user-agent, a readable device name, web/app, URL and method. The triggers copy them into the entry.
 *  - Triggers are (re)built from the live table structure; a signature per table detects new columns.
 *    `auditSync()` runs from the admin Update History page (and can be forced with "Refresh triggers").
 *  - password_hash is never stored — only "(changed)" / "(unchanged)".
 *
 * The history is truthful: an admin's edit is recorded as the admin. Restoring/editing never rewrites
 * who originally entered a record (owner columns such as uploaded_by keep their stored value).
 */

if (!function_exists('auditEnsureSchema')) {

    define('AUDIT_TRIGGER_VERSION', 'v1');

    function auditEnsureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        Database::query(
            "CREATE TABLE IF NOT EXISTS audit_log (
                id BIGINT AUTO_INCREMENT PRIMARY KEY,
                table_name VARCHAR(64) NOT NULL,
                row_pk VARCHAR(255) NOT NULL,
                action ENUM('insert','update','delete') NOT NULL,
                old_data LONGTEXT NULL,
                new_data LONGTEXT NULL,
                user_id INT NULL,
                user_name VARCHAR(190) NULL,
                user_roles VARCHAR(190) NULL,
                ip VARCHAR(64) NULL,
                user_agent VARCHAR(500) NULL,
                device VARCHAR(190) NULL,
                client VARCHAR(500) NULL,
                request_uri VARCHAR(500) NULL,
                method VARCHAR(10) NULL,
                note VARCHAR(255) NULL,
                created_at TIMESTAMP(3) DEFAULT CURRENT_TIMESTAMP(3),
                KEY idx_table_row (table_name, row_pk),
                KEY idx_created (created_at),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        Database::query(
            "CREATE TABLE IF NOT EXISTS audit_triggers (
                table_name VARCHAR(64) PRIMARY KEY,
                signature CHAR(32) NOT NULL,
                installed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB"
        );
    }

    /** Tables never audited: the log itself, secrets, login counters, one-off backups. */
    function auditExcluded(string $table): bool
    {
        if (in_array($table, ['audit_log', 'audit_triggers', 'app_secrets', 'login_attempts', 'notifications'], true)) return true;
        return (bool)preg_match('/backup/i', $table);
    }

    /** Columns that are hidden from the log (only "changed/unchanged" is stored). */
    function auditRedacted(string $column): bool
    {
        return $column === 'password_hash';
    }

    /** Columns whose change alone does not count as an update (timestamps maintained by MySQL). */
    function auditNoise(string $column): bool
    {
        return in_array($column, ['updated_at', 'last_updated'], true);
    }

    /** [table => ['cols' => [...], 'pk' => [...]]] for every audited table. */
    function auditSchema(): array
    {
        $rows = Database::fetchAll(
            "SELECT c.table_name AS t, c.column_name AS c, c.ordinal_position AS p
             FROM information_schema.columns c JOIN information_schema.tables t
               ON t.table_schema = c.table_schema AND t.table_name = c.table_name
             WHERE c.table_schema = DATABASE() AND t.table_type = 'BASE TABLE'
             ORDER BY c.table_name, c.ordinal_position"
        );
        $pk = Database::fetchAll(
            "SELECT table_name AS t, column_name AS c FROM information_schema.key_column_usage
             WHERE table_schema = DATABASE() AND constraint_name = 'PRIMARY' ORDER BY table_name, ordinal_position"
        );
        $out = [];
        foreach ($rows as $r) {
            if (auditExcluded($r['t'])) continue;
            $out[$r['t']]['cols'][] = $r['c'];
            $out[$r['t']]['pk'] = $out[$r['t']]['pk'] ?? [];
        }
        foreach ($pk as $r) {
            if (isset($out[$r['t']])) $out[$r['t']]['pk'][] = $r['c'];
        }
        // a table without a primary key cannot be restored row by row — skip it
        return array_filter($out, fn($x) => !empty($x['pk']));
    }

    function auditQuote(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }

    function auditJson(array $cols, string $ref): string
    {
        $parts = [];
        foreach ($cols as $c) {
            $val = auditRedacted($c)
                ? ($ref === 'NEW' ? "'(set)'" : "'(hidden)'")
                : "$ref." . auditQuote($c);
            $parts[] = "'" . str_replace("'", "''", $c) . "', $val";
        }
        return 'JSON_OBJECT(' . implode(', ', $parts) . ')';
    }

    function auditPkExpr(array $pk, string $ref): string
    {
        if (count($pk) === 1) return "CAST($ref." . auditQuote($pk[0]) . " AS CHAR)";
        return auditJson($pk, $ref);
    }

    /** SQL for the three triggers of one table. */
    function auditTriggerSql(string $table, array $cols, array $pk): array
    {
        $t = auditQuote($table);
        $tn = "'" . str_replace("'", "''", $table) . "'";
        $who = "@aud_uid, @aud_uname, @aud_roles, @aud_ip, @aud_ua, @aud_device, @aud_client, @aud_uri, @aud_method, @aud_note";
        $fields = "(table_name, row_pk, action, old_data, new_data, user_id, user_name, user_roles, ip, user_agent, device, client, request_uri, method, note)";

        $changed = [];
        foreach ($cols as $c) {
            if (auditNoise($c)) continue;
            $changed[] = "NOT (OLD." . auditQuote($c) . " <=> NEW." . auditQuote($c) . ")";
        }
        $cond = $changed ? implode(' OR ', $changed) : '0';

        // password_hash: say whether it changed, never the value
        $newJson = auditJson($cols, 'NEW');
        if (in_array('password_hash', $cols, true)) {
            $newJson = str_replace("'password_hash', '(set)'", "'password_hash', IF(OLD.`password_hash` <=> NEW.`password_hash`, '(unchanged)', '(changed)')", $newJson);
        }

        return [
            "aud_{$table}_ai" => "CREATE TRIGGER " . auditQuote("aud_{$table}_ai") . " AFTER INSERT ON $t FOR EACH ROW
                INSERT INTO audit_log $fields VALUES ($tn, " . auditPkExpr($pk, 'NEW') . ", 'insert', NULL, " . auditJson($cols, 'NEW') . ", $who)",
            "aud_{$table}_au" => "CREATE TRIGGER " . auditQuote("aud_{$table}_au") . " AFTER UPDATE ON $t FOR EACH ROW
                BEGIN
                  IF $cond THEN
                    INSERT INTO audit_log $fields VALUES ($tn, " . auditPkExpr($pk, 'NEW') . ", 'update', " . auditJson($cols, 'OLD') . ", $newJson, $who);
                  END IF;
                END",
            "aud_{$table}_ad" => "CREATE TRIGGER " . auditQuote("aud_{$table}_ad") . " AFTER DELETE ON $t FOR EACH ROW
                INSERT INTO audit_log $fields VALUES ($tn, " . auditPkExpr($pk, 'OLD') . ", 'delete', " . auditJson($cols, 'OLD') . ", NULL, $who)",
        ];
    }

    /**
     * Install / refresh triggers for every audited table whose structure changed.
     * @return array{installed:int, total:int, refreshed:string[], errors:array<string,string>}
     */
    function auditSync(bool $force = false): array
    {
        auditEnsureSchema();
        $pdo = Database::getInstance();
        $schema = auditSchema();
        $known = [];
        foreach (Database::fetchAll("SELECT table_name, signature FROM audit_triggers") as $r) $known[$r['table_name']] = $r['signature'];
        $existing = array_column(Database::fetchAll(
            "SELECT trigger_name FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'aud\\_%'"
        ), 'trigger_name');
        $existing = array_flip($existing);

        $refreshed = [];
        $errors = [];
        foreach ($schema as $table => $info) {
            $sig = md5(AUDIT_TRIGGER_VERSION . '|' . implode(',', $info['cols']) . '|' . implode(',', $info['pk']));
            $all = isset($existing["aud_{$table}_ai"], $existing["aud_{$table}_au"], $existing["aud_{$table}_ad"]);
            if (!$force && $all && ($known[$table] ?? '') === $sig) continue;
            try {
                foreach (auditTriggerSql($table, $info['cols'], $info['pk']) as $name => $sql) {
                    $pdo->exec('DROP TRIGGER IF EXISTS ' . auditQuote($name));
                    $pdo->exec($sql);
                }
                Database::query(
                    "INSERT INTO audit_triggers (table_name, signature) VALUES (?, ?) ON DUPLICATE KEY UPDATE signature = VALUES(signature)",
                    [$table, $sig]
                );
                $refreshed[] = $table;
            } catch (\Throwable $e) {
                $errors[$table] = $e->getMessage();
            }
        }
        // tables that no longer exist (or became excluded): drop their triggers
        foreach (array_keys($known) as $table) {
            if (!isset($schema[$table])) {
                foreach (['ai', 'au', 'ad'] as $k) {
                    try { $pdo->exec('DROP TRIGGER IF EXISTS ' . auditQuote("aud_{$table}_{$k}")); } catch (\Throwable $e) {}
                }
                Database::query("DELETE FROM audit_triggers WHERE table_name = ?", [$table]);
            }
        }
        $installed = (int)(Database::fetch(
            "SELECT COUNT(DISTINCT event_object_table) AS n FROM information_schema.triggers
             WHERE trigger_schema = DATABASE() AND trigger_name LIKE 'aud\\_%'"
        )['n'] ?? 0);
        return ['installed' => $installed, 'total' => count($schema), 'refreshed' => $refreshed, 'errors' => $errors];
    }

    /** Make sure a (possibly just created) table has its triggers; cheap when it already does. */
    function auditTrackTable(string $table): void
    {
        try {
            $n = (int)(Database::fetch(
                "SELECT COUNT(*) AS n FROM information_schema.triggers WHERE trigger_schema = DATABASE() AND event_object_table = ? AND trigger_name LIKE 'aud\\_%'",
                [$table]
            )['n'] ?? 0);
            if ($n < 3) auditSync(false);
        } catch (\Throwable $e) {
            // never block the feature that created the table
        }
    }

    /**
     * Safety net: at most every 10 minutes, cover tables created since the last check (features create
     * their tables on first use). Uses a timestamp file so normal requests do no extra DB work.
     */
    function auditPeriodicSync(): void
    {
        $stamp = __DIR__ . '/../uploads/.audit_sync';
        $last = @filemtime($stamp);
        if ($last && time() - $last < 600) return;
        @touch($stamp);
        try { auditSync(false); } catch (\Throwable $e) { /* best effort */ }
    }

    // ---- who is acting ---------------------------------------------------------------------------

    /** A readable device name from the user-agent (+ optional details the app/website sends). */
    function auditDeviceName(string $ua, array $client): string
    {
        $os = 'Unknown OS';
        $model = '';
        if (preg_match('/Android ([\d.]+)(?:; ([^;)]+))?/i', $ua, $m)) {
            $os = 'Android ' . $m[1];
            $model = trim(preg_replace('/\s*Build\/.*$/i', '', (string)($m[2] ?? '')));
            if ($model === 'K' || stripos($model, 'wv') !== false || stripos($model, 'Linux') !== false) $model = '';
        } elseif (preg_match('/(iPhone|iPad).*?OS ([\d_]+)/i', $ua, $m)) {
            $os = $m[1] . ' iOS ' . str_replace('_', '.', $m[2]);
        } elseif (preg_match('/Windows NT ([\d.]+)/', $ua, $m)) {
            $os = 'Windows ' . ($m[1] === '10.0' ? '10/11' : $m[1]);
        } elseif (stripos($ua, 'Mac OS X') !== false) {
            $os = 'macOS';
        } elseif (stripos($ua, 'Linux') !== false) {
            $os = 'Linux';
        }
        if (!empty($client['model'])) $model = (string)$client['model'];

        $browser = '';
        if (preg_match('/Edg\/([\d]+)/', $ua, $m)) $browser = 'Edge ' . $m[1];
        elseif (preg_match('/OPR\/([\d]+)/', $ua, $m)) $browser = 'Opera ' . $m[1];
        elseif (preg_match('/Firefox\/([\d]+)/', $ua, $m)) $browser = 'Firefox ' . $m[1];
        elseif (preg_match('/Chrome\/([\d]+)/', $ua, $m)) $browser = (stripos($ua, '; wv)') !== false ? 'WebView ' : 'Chrome ') . $m[1];
        elseif (preg_match('/Version\/([\d.]+).*Safari/', $ua, $m)) $browser = 'Safari ' . $m[1];

        $app = (string)($client['app'] ?? '');
        $parts = [];
        if ($app && $app !== 'web') $parts[] = $app === 'student' ? 'MJADC Student app' : 'MJADC app';
        $parts[] = $os;
        if ($model) $parts[] = $model;
        if ($browser && !($app && $app !== 'web')) $parts[] = $browser;
        return substr(implode(' · ', $parts), 0, 190);
    }

    function auditClientIp(): string
    {
        // LiteSpeed / proxies put the visitor first in X-Forwarded-For
        $xff = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? '';
        if ($xff) {
            $first = trim(explode(',', $xff)[0]);
            if (filter_var($first, FILTER_VALIDATE_IP)) return $first;
        }
        return (string)($_SERVER['REMOTE_ADDR'] ?? '');
    }

    /** Called once when the DB connection opens: request facts (no user yet). */
    function auditRequestContext(\PDO $pdo): void
    {
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500);
        $client = json_decode((string)($_SERVER['HTTP_X_CLIENT_INFO'] ?? ''), true);
        $client = is_array($client) ? $client : [];
        $clientStr = substr(json_encode($client, JSON_UNESCAPED_UNICODE) ?: '', 0, 500);
        $stmt = $pdo->prepare("SET @aud_uid = NULL, @aud_uname = NULL, @aud_roles = NULL, @aud_note = NULL,
            @aud_ip = ?, @aud_ua = ?, @aud_device = ?, @aud_client = ?, @aud_uri = ?, @aud_method = ?");
        $stmt->execute([
            auditClientIp(), $ua, auditDeviceName($ua, $client), $clientStr ?: null,
            substr((string)($_SERVER['REQUEST_URI'] ?? 'cli'), 0, 500), substr((string)($_SERVER['REQUEST_METHOD'] ?? 'CLI'), 0, 10),
        ]);
    }

    /** Called when the request's user is known (Auth::getUser). */
    function auditSetUser(array $user): void
    {
        Database::query(
            "SET @aud_uid = ?, @aud_uname = ?, @aud_roles = ?",
            [(int)$user['id'], substr((string)($user['name'] ?? ''), 0, 190), substr(implode(',', (array)($user['roles'] ?? [])), 0, 190)]
        );
    }

    /** A short note on the entries written by the next statements (e.g. "Restored from #12"). */
    function auditSetNote(?string $note): void
    {
        Database::query("SET @aud_note = ?", [$note === null ? null : substr($note, 0, 255)]);
    }

    // ---- restore / edit helpers ---------------------------------------------------------------

    /** Columns of an audited table (whitelist for building SQL). Null if the table isn't audited. */
    function auditTableInfo(string $table): ?array
    {
        $schema = auditSchema();
        return $schema[$table] ?? null;
    }

    /** WHERE clause + params for one row from row_pk. */
    function auditPkWhere(array $info, string $rowPk): array
    {
        if (count($info['pk']) === 1) {
            return [auditQuote($info['pk'][0]) . ' = ?', [$rowPk]];
        }
        $vals = json_decode($rowPk, true);
        if (!is_array($vals)) throw new \RuntimeException('Bad composite key');
        $w = [];
        $p = [];
        foreach ($info['pk'] as $c) {
            $w[] = auditQuote($c) . ' <=> ?';
            $p[] = $vals[$c] ?? null;
        }
        return [implode(' AND ', $w), $p];
    }

    /** Current row (or null). */
    function auditCurrentRow(string $table, string $rowPk): ?array
    {
        $info = auditTableInfo($table);
        if (!$info) return null;
        [$where, $params] = auditPkWhere($info, $rowPk);
        $row = Database::fetch("SELECT * FROM " . auditQuote($table) . " WHERE $where LIMIT 1", $params);
        if ($row && array_key_exists('password_hash', $row)) $row['password_hash'] = '(hidden)';
        return $row;
    }

    /**
     * Make a row hold $data (insert if missing). Redacted columns and unknown columns are ignored.
     * @param bool $mustExist true → only update an existing row
     */
    function auditWriteRow(string $table, string $rowPk, array $data, bool $mustExist = false): string
    {
        $info = auditTableInfo($table);
        if (!$info) throw new \RuntimeException('This table is not tracked');
        $cols = array_flip($info['cols']);
        $clean = [];
        foreach ($data as $c => $v) {
            if (!isset($cols[$c]) || auditRedacted($c)) continue;
            $clean[$c] = $v;
        }
        if (!$clean) throw new \RuntimeException('Nothing to write');
        [$where, $params] = auditPkWhere($info, $rowPk);
        $exists = (bool)Database::fetch("SELECT 1 FROM " . auditQuote($table) . " WHERE $where LIMIT 1", $params);
        if ($exists) {
            $set = [];
            $vals = [];
            foreach ($clean as $c => $v) {
                if (in_array($c, $info['pk'], true)) continue;
                $set[] = auditQuote($c) . ' = ?';
                $vals[] = $v;
            }
            if (!$set) return 'unchanged';
            Database::query("UPDATE " . auditQuote($table) . " SET " . implode(', ', $set) . " WHERE $where", array_merge($vals, $params));
            return 'updated';
        }
        if ($mustExist) throw new \RuntimeException('This record no longer exists');
        // re-create the deleted row with its original key
        if (count($info['pk']) === 1) $clean[$info['pk'][0]] = $rowPk;
        else foreach ((json_decode($rowPk, true) ?: []) as $c => $v) if (isset($cols[$c])) $clean[$c] = $v;
        $names = implode(', ', array_map('auditQuote', array_keys($clean)));
        $marks = implode(', ', array_fill(0, count($clean), '?'));
        Database::query("INSERT INTO " . auditQuote($table) . " ($names) VALUES ($marks)", array_values($clean));
        return 'recreated';
    }

    function auditDeleteRow(string $table, string $rowPk): bool
    {
        $info = auditTableInfo($table);
        if (!$info) throw new \RuntimeException('This table is not tracked');
        [$where, $params] = auditPkWhere($info, $rowPk);
        return Database::query("DELETE FROM " . auditQuote($table) . " WHERE $where LIMIT 1", $params)->rowCount() > 0;
    }
}
