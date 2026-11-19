<?php

/**
 * Subject names (Session 124).
 * "Information and Communications Technology" is the one official name of ICT. Every older spelling —
 * "ICT", "Information and Communication Technology", "Information & Communication(s) Technology" — is
 * treated as the same subject everywhere, and stored data is renamed once by subjectNamesMigrate().
 */

if (!function_exists('subjectIctName')) {

    define('SUBJECT_ICT', 'Information and Communications Technology');

    function subjectIctName(): string
    {
        return SUBJECT_ICT;
    }

    /** Every spelling we have seen (lower-case compare). */
    function subjectIctVariants(): array
    {
        return ['ICT', 'Information and Communication Technology', 'Information & Communication Technology',
                'Information & Communications Technology', SUBJECT_ICT];
    }

    function subjectIsIct(?string $name): bool
    {
        $n = mb_strtolower(trim((string)$name));
        if ($n === '') return false;
        foreach (subjectIctVariants() as $v) if ($n === mb_strtolower($v)) return true;
        return false;
    }

    /** The official spelling of a subject name; also fixes each half of "Subject - Paper" keys. */
    function subjectCanonicalName(?string $name): string
    {
        $name = (string)$name;
        if (strpos($name, ' - ') !== false) {
            return implode(' - ', array_map(fn($p) => subjectCanonicalName($p), explode(' - ', $name)));
        }
        return subjectIsIct($name) ? SUBJECT_ICT : $name;
    }

    /** Rename inside a JSON array of subject names (returns the original string if nothing changed). */
    function subjectCanonicalJsonList(?string $json): ?string
    {
        if ($json === null || $json === '') return $json;
        $list = json_decode($json, true);
        if (!is_array($list)) return $json;
        $changed = false;
        $out = [];
        foreach ($list as $v) {
            $c = is_string($v) ? subjectCanonicalName($v) : $v;
            if ($c !== $v) $changed = true;
            if (!in_array($c, $out, true)) $out[] = $c; else $changed = true;
        }
        return $changed ? json_encode(array_values($out), JSON_UNESCAPED_UNICODE) : $json;
    }

    /** Use the official spelling in a students row before it is saved (old clients may still send "ICT"). */
    function subjectNormalizeStudentRow(array $row): array
    {
        foreach (['compulsory_subjects', 'selective_subjects'] as $k) {
            if (isset($row[$k]) && is_string($row[$k])) $row[$k] = subjectCanonicalJsonList($row[$k]);
        }
        if (isset($row['optional_subject']) && is_string($row['optional_subject'])) $row['optional_subject'] = subjectCanonicalName($row['optional_subject']);
        return $row;
    }

    /**
     * One-time data rename of every old ICT spelling to the official name. Safe to run again (it only
     * touches rows that still hold an old spelling). Runs once per server; the flag file is written after.
     * Duplicates (e.g. a student having results under both "ICT" and the long name) keep the existing
     * official row and leave the older one for the admin to review (reported in the return value).
     */
    function subjectNamesMigrate(bool $force = false): array
    {
        $flag = __DIR__ . '/../uploads/.migr_ict_name_v1';
        if (!$force && is_file($flag)) return ['skipped' => true];
        $report = [];
        $old = array_values(array_filter(subjectIctVariants(), fn($v) => $v !== SUBJECT_ICT));
        $ph = implode(',', array_fill(0, count($old), '?'));
        $tableExists = fn($t) => (bool)Database::fetch("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?", [$t]);
        if (function_exists('auditSetNote')) { try { auditSetNote('Renamed ICT to the official subject name'); } catch (\Throwable $e) {} }

        // 1. plain name columns (exact old spelling)
        foreach ([['subjects', 'name'], ['subject_papers', 'name'], ['teacher_subjects', 'subject'], ['teachers', 'subject'],
                  ['staff', 'subject'], ['teacher_attendance', 'subject'], ['students', 'optional_subject']] as [$t, $c]) {
            if (!$tableExists($t)) continue;
            $report["$t.$c"] = Database::query("UPDATE IGNORE `$t` SET `$c` = ? WHERE `$c` IN ($ph)", array_merge([SUBJECT_ICT], $old))->rowCount();
        }

        // 1b. a teacher assigned BOTH "ICT" and the long name: after the rename the old row is an exact
        //     duplicate of the official one (same teacher + type) — remove it
        if ($tableExists('teacher_subjects')) {
            $report['teacher_subjects duplicates removed'] = Database::query(
                "DELETE old FROM teacher_subjects old
                 JOIN teacher_subjects official ON official.teacher_id = old.teacher_id AND official.type = old.type AND official.subject = ?
                 WHERE old.subject IN ($ph)",
                array_merge([SUBJECT_ICT], $old)
            )->rowCount();
        }

        // 2. subject_parts: rename, but don't create a second "CQ" for the same subject
        if ($tableExists('subject_parts')) {
            $have = array_map('mb_strtolower', array_column(Database::fetchAll("SELECT part_name FROM subject_parts WHERE subject = ?", [SUBJECT_ICT]), 'part_name'));
            $n = 0;
            foreach (Database::fetchAll("SELECT id, part_name FROM subject_parts WHERE subject IN ($ph)", $old) as $p) {
                if (in_array(mb_strtolower($p['part_name']), $have, true)) {
                    Database::query("DELETE FROM subject_parts WHERE id = ?", [$p['id']]); // duplicate of an official part
                } else {
                    Database::query("UPDATE subject_parts SET subject = ? WHERE id = ?", [SUBJECT_ICT, $p['id']]);
                    $have[] = mb_strtolower($p['part_name']);
                }
                $n++;
            }
            $report['subject_parts.subject'] = $n;
        }

        // 3. result keys ("ICT", "ICT - ICT", "Information and Communication Technology - …")
        foreach ([['exam_results', 'subject'], ['result_images', 'subject']] as [$t, $c]) {
            if (!$tableExists($t)) continue;
            $n = 0;
            $left = 0;
            $rows = Database::fetchAll("SELECT id, `$c` AS v FROM `$t` WHERE `$c` REGEXP '(^|[^A-Za-z])ICT([^A-Za-z]|$)' OR `$c` LIKE '%Information%Communication%'");
            foreach ($rows as $r) {
                $new = subjectCanonicalName($r['v']);
                if ($new === $r['v']) continue;
                $done = Database::query("UPDATE IGNORE `$t` SET `$c` = ? WHERE id = ?", [$new, $r['id']])->rowCount();
                if ($done) $n++; else $left++;
            }
            $report["$t.$c"] = $n;
            if ($left) $report["$t.$c (duplicates kept under the old name)"] = $left;
        }

        // 4. JSON lists of subject names
        foreach ([['students', 'compulsory_subjects'], ['students', 'selective_subjects'], ['exam_routines', 'subjects']] as [$t, $c]) {
            if (!$tableExists($t)) continue;
            $n = 0;
            foreach (Database::fetchAll("SELECT id, `$c` AS v FROM `$t` WHERE `$c` LIKE '%ICT%' OR `$c` LIKE '%Information%Communication%'") as $r) {
                $new = subjectCanonicalJsonList($r['v']);
                if ($new !== $r['v']) { Database::query("UPDATE `$t` SET `$c` = ? WHERE id = ?", [$new, $r['id']]); $n++; }
            }
            $report["$t.$c"] = $n;
        }

        if (function_exists('auditSetNote')) { try { auditSetNote(null); } catch (\Throwable $e) {} }
        @file_put_contents($flag, json_encode(['at' => date('c'), 'report' => $report]));
        return $report;
    }
}

/**
 * Session 126: exam_results.subject was VARCHAR(50), but a result key is "Subject - Paper" and can be longer
 * (e.g. "Information and Communications Technology - Information and Communications Technology").
 * The live server silently cut such keys to 50 characters, so later uploads could not find the row and
 * failed with "Duplicate entry … for key unique_result". This widens the column once and puts every cut key
 * back to its full name, matched against that exam's configured subjects/papers. Runs once (flag file);
 * rows that can't be matched to exactly one full key are left as they are and logged.
 */
function resultKeysWidenMigrate(bool $force = false): array
{
    $flag = __DIR__ . '/../uploads/.migr_result_key_v1';
    if (!$force && is_file($flag)) return ['skipped' => true];
    $report = ['widened' => false, 'repaired' => 0, 'unmatched' => []];

    $col = Database::fetch("SELECT CHARACTER_MAXIMUM_LENGTH AS len, IS_NULLABLE AS n FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'exam_results' AND COLUMN_NAME = 'subject'");
    if (!$col) return ['skipped' => 'no exam_results.subject'];
    if ((int)$col['len'] < 191) {
        Database::getInstance()->exec("ALTER TABLE exam_results MODIFY subject VARCHAR(191) " . ($col['n'] === 'YES' ? 'NULL' : 'NOT NULL'));
        $report['widened'] = true;
    }
    $oldLen = min((int)$col['len'], 191);

    if (function_exists('auditSetNote')) auditSetNote('Restored result subject names that were cut short');
    $cut = Database::fetchAll("SELECT r.id, r.student_id, r.exam_id, r.subject, e.year, e.class, e.exam_name
        FROM exam_results r JOIN exams e ON e.id = r.exam_id WHERE CHAR_LENGTH(r.subject) = ?", [$oldLen]);
    $itemsByExam = [];
    foreach ($cut as $r) {
        if (!isset($itemsByExam[$r['exam_id']])) {
            $keys = [];
            if (function_exists('teacherResultGetConfiguredItems')) {
                foreach (teacherResultGetConfiguredItems((int)$r['year'], (string)$r['class'], (string)$r['exam_name']) as $it) {
                    $k = $it['subject_name'] . (!empty($it['paper_name']) ? ' - ' . $it['paper_name'] : '');
                    $keys[$k] = true;
                }
            }
            $itemsByExam[$r['exam_id']] = array_keys($keys);
        }
        $matches = array_values(array_filter($itemsByExam[$r['exam_id']], fn($k) => mb_strlen($k) > $oldLen && mb_substr($k, 0, $oldLen) === $r['subject']));
        if (count($matches) !== 1) { $report['unmatched'][] = $r['id'] . ': ' . $r['subject']; continue; }
        $clash = Database::fetch("SELECT id FROM exam_results WHERE student_id = ? AND exam_id = ? AND subject = ?", [$r['student_id'], $r['exam_id'], $matches[0]]);
        if ($clash) { $report['unmatched'][] = $r['id'] . ': full row already exists (#' . $clash['id'] . ')'; continue; }
        Database::query("UPDATE exam_results SET subject = ? WHERE id = ?", [$matches[0], $r['id']]);
        $report['repaired']++;
    }
    if (function_exists('auditSetNote')) auditSetNote(null);

    $report['at'] = date('c');
    @file_put_contents($flag, json_encode($report, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    if ($report['unmatched']) error_log('Result key repair — not matched: ' . implode(' | ', array_slice($report['unmatched'], 0, 50)));
    return $report;
}
