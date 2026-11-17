<?php

// Fee dues — shared by mark entry, mark-entry forms, result / marksheet hold and fee collection (Session 100).
//
// A student's status for one exam:
//   exam fee   paid = (paid + waived) ≥ that exam's fee (Exam Fee Set, "Pre-Test (12th)" or "Pre-Test").
//              When no fee amount is set, any payment counts as paid.
//   tuition    clear = no outstanding tuition / other fee up to the current month
//              (amount_due − amount_paid − waiver, rows of future months are not due yet)
//   all clear  = exam fee paid AND tuition clear
//
// Admin rules (site_settings "due_rules"):
//   mark_entry   which students subject teachers / exam controller can enter marks for:
//                all | exam_paid | tuition_paid | all_paid
//   result_hold  whose result + marksheet is held (student panel, public result, QR verify, transcripts):
//                none | exam_due | tuition_due | any_due

const DUES_FILTERS = [
    'all' => 'All students',
    'exam_paid' => 'Exam fee paid',
    'tuition_paid' => 'Tuition fee clear (no due)',
    'all_paid' => 'All dues paid',
    'exam_due' => 'Exam fee due',
    'tuition_due' => 'Tuition fee due',
    'any_due' => 'Any due',
];
const DUES_HOLDS = ['none' => 'Do not hold', 'exam_due' => 'Hold if exam fee is due', 'tuition_due' => 'Hold if tuition fee is due', 'any_due' => 'Hold if any fee is due'];

/** Columns the fee pages always used but no migration ever added (fee_type, collected_by). */
if (!function_exists('duesEnsureSchema')) {
function duesEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        if (!Database::fetch("SHOW COLUMNS FROM tuition_fees LIKE 'fee_type'")) {
            Database::query("ALTER TABLE tuition_fees ADD COLUMN fee_type VARCHAR(50) NOT NULL DEFAULT 'Tuition Fee' AFTER month");
        }
        $cols = array_column(Database::fetchAll("SHOW INDEX FROM tuition_fees WHERE Key_name = 'unique_fee'"), 'Column_name');
        if ($cols && !in_array('fee_type', $cols, true)) {
            // one row per student / year / month / fee type (an exam or admission fee no longer collides with tuition)
            if (!Database::fetch("SHOW INDEX FROM tuition_fees WHERE Key_name = 'idx_tf_student'")) {
                Database::query("ALTER TABLE tuition_fees ADD KEY idx_tf_student (student_id)");
            }
            Database::query("ALTER TABLE tuition_fees DROP INDEX unique_fee, ADD UNIQUE KEY unique_fee (student_id, year, month, fee_type)");
        }
        if (!Database::fetch("SHOW COLUMNS FROM payments LIKE 'fee_type'")) {
            Database::query("ALTER TABLE payments ADD COLUMN fee_type VARCHAR(50) NULL AFTER payment_type");
        }
        if (!Database::fetch("SHOW COLUMNS FROM payments LIKE 'collected_by'")) {
            Database::query("ALTER TABLE payments ADD COLUMN collected_by VARCHAR(150) NULL");
        }
    } catch (Throwable $e) {
        error_log('duesEnsureSchema: ' . $e->getMessage());
    }
}}

if (!function_exists('duesSettings')) {
function duesSettings(): array
{
    $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'due_rules'");
    $v = $row ? (json_decode((string)$row['setting_value'], true) ?: []) : [];
    return [
        'mark_entry' => in_array($v['mark_entry'] ?? '', ['all', 'exam_paid', 'tuition_paid', 'all_paid'], true) ? $v['mark_entry'] : 'all',
        'result_hold' => isset(DUES_HOLDS[$v['result_hold'] ?? '']) ? $v['result_hold'] : 'none',
    ];
}}

/** Configured fee of an exam: "Pre-Test (12th)" first, then plain "Pre-Test". */
if (!function_exists('duesExamFee')) {
function duesExamFee(string $examName, ?string $class = null): float
{
    static $cache = [];
    $keys = [];
    if ($class) $keys[] = "$examName ($class)";
    $keys[] = $examName;
    if (preg_match('/^(.*) \((11th|12th|Old)\)$/', $examName, $m)) $keys[] = $m[1];
    foreach ($keys as $k) {
        $sk = 'exam_fee_amount_' . strtolower(str_replace(' ', '_', $k));
        if (!array_key_exists($sk, $cache)) {
            $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1", [$sk]);
            $cache[$sk] = $row ? (float)$row['setting_value'] : 0.0;
        }
        if ($cache[$sk] > 0) return $cache[$sk];
    }
    return 0.0;
}}

/**
 * Due status of students for one exam. $students: rows with id (students.id), user_id, class.
 * Returns [students.id => [exam_fee, exam_paid_amount, exam_paid, tuition_due, tuition_clear, all_clear]].
 */
if (!function_exists('duesStatusFor')) {
function duesStatusFor(array $students, int $year, string $examName): array
{
    duesEnsureSchema();
    if (!$students) return [];
    $ids = []; $uids = []; $classes = [];
    foreach ($students as $s) {
        $ids[] = (int)$s['id'];
        if (!empty($s['user_id'])) $uids[] = (int)$s['user_id'];
        $classes[$s['class'] ?? ''] = true;
    }
    $variants = [$examName];
    foreach (array_keys($classes) as $c) if ($c !== '') $variants[] = "$examName ($c)";
    $variants = array_values(array_unique($variants));

    $paid = []; $waived = [];
    if ($uids) {
        $u = implode(',', array_unique($uids));
        $v = implode(',', array_fill(0, count($variants), '?'));
        foreach (Database::fetchAll("SELECT student_user_id, SUM(amount) a FROM exam_fees WHERE year = ? AND exam_name IN ($v) AND student_user_id IN ($u) GROUP BY student_user_id", [$year, ...$variants]) as $r) {
            $paid[(int)$r['student_user_id']] = (float)$r['a'];
        }
        if (Database::fetch("SHOW TABLES LIKE 'exam_fee_waivers'")) {
            foreach (Database::fetchAll("SELECT student_user_id, SUM(amount) a FROM exam_fee_waivers WHERE year = ? AND exam_name IN ($v) AND student_user_id IN ($u) GROUP BY student_user_id", [$year, ...$variants]) as $r) {
                $waived[(int)$r['student_user_id']] = (float)$r['a'];
            }
        }
    }
    $tuition = [];
    $y = (int)date('Y'); $m = (int)date('n');
    foreach (Database::fetchAll(
        "SELECT student_id, SUM(GREATEST(amount_due - amount_paid - COALESCE(waiver_amount, 0), 0)) o FROM tuition_fees
         WHERE student_id IN (" . implode(',', $ids) . ") AND (month = 0 OR year < ? OR (year = ? AND month <= ?))
         GROUP BY student_id", [$y, $y, $m]) as $r) {
        $tuition[(int)$r['student_id']] = round((float)$r['o'], 2);
    }

    $out = [];
    foreach ($students as $s) {
        $uid = (int)($s['user_id'] ?? 0);
        $fee = duesExamFee($examName, $s['class'] ?? null);
        $p = $paid[$uid] ?? 0.0; $w = $waived[$uid] ?? 0.0;
        $examPaid = $fee > 0 ? ($p + $w >= $fee - 0.001) : ($p > 0 || $w > 0);
        $tDue = $tuition[(int)$s['id']] ?? 0.0;
        $out[(int)$s['id']] = [
            'exam_fee' => $fee, 'exam_paid_amount' => $p, 'exam_waived' => $w,
            'exam_due' => $fee > 0 ? max(0, round($fee - $p - $w, 2)) : ($examPaid ? 0 : null),
            'exam_paid' => $examPaid,
            'tuition_due' => $tDue, 'tuition_clear' => $tDue <= 0.001,
            'all_clear' => $examPaid && $tDue <= 0.001,
        ];
    }
    return $out;
}}

if (!function_exists('duesMatches')) {
function duesMatches(?array $st, string $filter): bool
{
    if ($filter === 'all' || !isset(DUES_FILTERS[$filter])) return true;
    if (!$st) return false;
    return match ($filter) {
        'exam_paid' => $st['exam_paid'],
        'tuition_paid' => $st['tuition_clear'],
        'all_paid' => $st['all_clear'],
        'exam_due' => !$st['exam_paid'],
        'tuition_due' => !$st['tuition_clear'],
        'any_due' => !$st['all_clear'],
        default => true,
    };
}}

/** Is this student's result / marksheet held under the admin's hold rule? */
if (!function_exists('duesHeld')) {
function duesHeld(?array $st, ?string $rule = null): bool
{
    $rule ??= duesSettings()['result_hold'];
    if ($rule === 'none' || !$st) return false;
    return match ($rule) {
        'exam_due' => !$st['exam_paid'],
        'tuition_due' => !$st['tuition_clear'],
        'any_due' => !$st['all_clear'],
        default => false,
    };
}}

/** Keep only students matching a filter; returns [kept rows, status map, hidden count]. */
if (!function_exists('duesFilterRows')) {
function duesFilterRows(array $rows, int $year, string $examName, string $filter): array
{
    if ($filter === 'all' || !isset(DUES_FILTERS[$filter]) || !$rows) return [$rows, [], 0];
    $status = duesStatusFor($rows, $year, $examName);
    $kept = array_values(array_filter($rows, fn($r) => duesMatches($status[(int)$r['id']] ?? null, $filter)));
    return [$kept, $status, count($rows) - count($kept)];
}}

if (!function_exists('duesHoldMessage')) {
function duesHoldMessage(?array $st): string
{
    $parts = [];
    if ($st && !$st['exam_paid']) $parts[] = 'exam fee';
    if ($st && !$st['tuition_clear']) $parts[] = 'tuition fee';
    return 'Your result is on hold because of unpaid ' . ($parts ? implode(' and ', $parts) : 'fees') . '. Please clear the dues at the college office.';
}}

/**
 * Spread money over a student's outstanding fee rows (oldest first) into amount_paid or waiver_amount.
 * $feeType null = any fee type. Returns the amount that did not fit (advance / overpayment).
 */
if (!function_exists('duesApplyToTuition')) {
function duesApplyToTuition(int $studentId, float $amount, string $column, ?string $feeType = null, ?string $txn = null, string $method = 'cash'): float
{
    duesEnsureSchema();
    if (!in_array($column, ['amount_paid', 'waiver_amount'], true) || $amount <= 0) return $amount;
    $sql = "SELECT id, GREATEST(amount_due - amount_paid - COALESCE(waiver_amount, 0), 0) o FROM tuition_fees
            WHERE student_id = ? AND (amount_due - amount_paid - COALESCE(waiver_amount, 0)) > 0";
    $params = [$studentId];
    if ($feeType !== null && $feeType !== '') { $sql .= " AND fee_type = ?"; $params[] = $feeType; }
    $sql .= " ORDER BY year ASC, month ASC, id ASC";
    $left = $amount;
    foreach (Database::fetchAll($sql, $params) as $row) {
        if ($left <= 0.001) break;
        $apply = min($left, (float)$row['o']);
        if ($column === 'amount_paid') {
            Database::query("UPDATE tuition_fees SET amount_paid = amount_paid + ?, paid_at = NOW(), payment_method = ?, transaction_id = ? WHERE id = ?",
                [$apply, $method, $txn, $row['id']]);
        } else {
            Database::query("UPDATE tuition_fees SET waiver_amount = COALESCE(waiver_amount, 0) + ? WHERE id = ?", [$apply, $row['id']]);
        }
        $left -= $apply;
    }
    return round(max(0, $left), 2);
}}
