<?php
// Exam fee collection: partial payments, waivers, dues, will-pay-within, reports.
// Roles: admin + exam_controller collect & waive; administration views own reports only.
// QR verification remains in routes/public/exam-fee-verify.php (sums paid installments).

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$examFeeTotal = function (string $examName, string $class = null): float {
    // Fee amount configured per exam: use max amount found in existing collections or default 0
    // If a settings row exists use it; otherwise 0 means "no fixed total" â€” dues computed against it.
    $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1", ['exam_fee_amount_' . strtolower(str_replace(' ', '_', $examName))]);
    if ($row) return (float)$row['setting_value'];
    return 0.0;
};

$examFeeStatus = function (int $studentUserId, string $year, string $examName) use ($examFeeTotal): array {
    $rows = Database::fetchAll(
        "SELECT id, amount, payment_method, collected_by, collected_at FROM exam_fees
         WHERE student_user_id = ? AND year = ? AND exam_name = ? ORDER BY id",
        [$studentUserId, $year, $examName]
    );
    $paid = 0.0;
    foreach ($rows as $r) $paid += (float)$r['amount'];
    $waiverRows = Database::fetchAll(
        "SELECT amount FROM exam_fee_waivers WHERE student_user_id = ? AND year = ? AND exam_name = ?",
        [$studentUserId, $year, $examName]
    );
    $waived = 0.0;
    foreach ($waiverRows as $r) $waived += (float)$r['amount'];
    $installments = [];
    $last = null;
    foreach ($rows as $r) {
        $installments[] = [
            'id' => (int)$r['id'],
            'amount' => (float)$r['amount'],
            'payment_method' => $r['payment_method'],
            'collected_at' => $r['collected_at'],
        ];
        $last = $r;
    }
    $total = $examFeeTotal($examName);
    $due = $total > 0 ? max(0, $total - $paid - $waived) : 0.0;
    return [
        'records' => count($rows) > 0,
        'paid' => count($rows) > 0,
        'paid_total' => $paid,
        'waived_total' => $waived,
        'total_fee' => $total,
        'due' => $due,
        'fully_paid' => $total > 0 ? ($paid + $waived >= $total) : (count($rows) > 0),
        'will_pay_within' => $last['will_pay_within'] ?? null,
        'installments' => $installments,
    ];
};

// ---------------------------------------------------------------------------
// POST /api/admin/exam-fees/collect â€” record a payment (admin / exam_controller)
// Accepts partial payment: amount, will_pay_within (date), payment_method
// ---------------------------------------------------------------------------
$router->post('/api/admin/exam-fees/collect', function () use ($examFeeStatus) {
    $user = Auth::requireAnyRole(['admin', 'exam_controller']);
    $data = json_decode(file_get_contents('php://input'), true);
    $studentUserId = (int)($data['student_user_id'] ?? 0);
    $year = (int)($data['year'] ?? 0);
    $examName = trim($data['exam_name'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $method = in_array($data['payment_method'] ?? 'cash', ['cash', 'online']) ? $data['payment_method'] : 'cash';
    $transactionId = trim($data['transaction_id'] ?? '') ?: null;
    $willPayWithin = trim($data['will_pay_within'] ?? '') ?: null;

    if (!$studentUserId || !$year || !$examName || $amount <= 0) {
        Response::validationError(['student_user_id, year, exam_name and a positive amount are required']);
    }

    $st = $examFeeStatus($studentUserId, $year, $examName);
    if ($st['total_fee'] > 0 && $st['fully_paid']) {
        Response::error('This student has already paid the full exam fee', 409);
    }

    // Single-row-per-student-exam model: first collect creates the row, subsequent partials accumulate
    $existing = Database::fetch(
        "SELECT id, amount FROM exam_fees WHERE student_user_id = ? AND year = ? AND exam_name = ?",
        [$studentUserId, $year, $examName]
    );
    if ($existing) {
        Database::update('exam_fees', ['amount' => (float)$existing['amount'] + $amount], 'id = ?', [(int)$existing['id']]);
        $feeId = (int)$existing['id'];
    } else {
        Database::insert('exam_fees', [
            'student_user_id' => $studentUserId,
            'year' => $year,
            'exam_name' => $examName,
            'amount' => $amount,
            'payment_method' => $method,
            'transaction_id' => $transactionId,
            'will_pay_within' => $willPayWithin,
            'collected_by' => $user['id'],
        ]);
        $feeId = (int)Database::getInstance()->lastInsertId();
    }
    $st2 = $examFeeStatus($studentUserId, $year, $examName);
    Response::success(['collected' => true, 'fee_id' => $feeId, 'status' => $st2], 'Payment recorded');
});

// ---------------------------------------------------------------------------
// POST /api/admin/exam-fees/waiver â€” waive part of a student's exam fee
// ---------------------------------------------------------------------------
$router->post('/api/admin/exam-fees/waiver', function () use ($examFeeStatus) {
    $user = Auth::requireAnyRole(['admin', 'exam_controller']);
    $data = json_decode(file_get_contents('php://input'), true);
    $studentUserId = (int)($data['student_user_id'] ?? 0);
    $year = (int)($data['year'] ?? 0);
    $examName = trim($data['exam_name'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    $reason = trim($data['reason'] ?? '') ?: null;
    if (!$studentUserId || !$year || !$examName || $amount <= 0) {
        Response::validationError(['student_user_id, year, exam_name and a positive amount are required']);
    }
    Database::insert('exam_fee_waivers', [
        'student_user_id' => $studentUserId,
        'year' => $year,
        'exam_name' => $examName,
        'amount' => $amount,
        'reason' => $reason,
        'granted_by' => $user['id'],
    ]);
    $st = $examFeeStatus($studentUserId, $year, $examName);
    Response::success(['waived' => true, 'status' => $st], 'Waiver applied');
});

// ---------------------------------------------------------------------------
// POST /api/admin/exam-fees/status â€” per-student status for a whole exam batch
// Also returns the configured fee total per exam (so UI can compute dues)
// ---------------------------------------------------------------------------
$router->post('/api/admin/exam-fees/status', function () use ($examFeeTotal) {
    Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $data = json_decode(file_get_contents('php://input'), true);
    $year = (int)($data['year'] ?? 0);
    $examName = trim($data['exam_name'] ?? '');
    if (!$year || !$examName) Response::validationError(['year and exam_name are required']);

    $total = $examFeeTotal($examName);
    $rows = Database::fetchAll(
        "SELECT id, student_user_id, amount, payment_method, will_pay_within, collected_by, collected_at
         FROM exam_fees WHERE year = ? AND exam_name = ?",
        [$year, $examName]
    );
    $paidBy = [];   // uid => sum
    $meta = [];
    foreach ($rows as $r) {
        $uid = (string)$r['student_user_id'];
        $paidBy[$uid] = ($paidBy[$uid] ?? 0) + (float)$r['amount'];
        $meta[$uid] = [
            'fee_id' => (int)$r['id'],
            'payment_method' => $r['payment_method'],
            'collected_by' => (int)$r['collected_by'],
            'collected_at' => $r['collected_at'],
            'will_pay_within' => $r['will_pay_within'],
        ];
    }
    $waivers = Database::fetchAll(
        "SELECT student_user_id, amount FROM exam_fee_waivers WHERE year = ? AND exam_name = ?",
        [$year, $examName]
    );
    $waivedBy = [];
    foreach ($waivers as $w) {
        $uid = (string)$w['student_user_id'];
        $waivedBy[$uid] = ($waivedBy[$uid] ?? 0) + (float)$w['amount'];
    }
    $map = [];
    foreach (array_unique(array_merge(array_keys($paidBy), array_keys($waivedBy))) as $uid) {
        $paid = $paidBy[$uid] ?? 0.0;
        $waived = $waivedBy[$uid] ?? 0.0;
        $due = $total > 0 ? max(0, $total - $paid - $waived) : 0.0;
        $map[$uid] = array_merge($meta[$uid] ?? [], [
            'paid' => count($paidBy) > 0 && isset($paidBy[$uid]),
            'paid_total' => $paid,
            'waived_total' => $waived,
            'total_fee' => $total,
            'due' => $due,
            'fully_paid' => $total > 0 ? ($paid + $waived >= $total) : isset($paidBy[$uid]),
            'will_pay_within' => $meta[$uid]['will_pay_within'] ?? null,
        ]);
    }
    Response::success(['total_fee' => $total, 'students' => $map]);
});

// ---------------------------------------------------------------------------
// POST /api/admin/exam-fees/report â€” collection report with filters
// Body: { year, exam_name?, date_from?, date_to?, collected_by? , scope: 'all'|'today'|'range'|'user' }
// Roles: admin/exam_controller see everything; administration only sees own collected rows.
// ---------------------------------------------------------------------------
$router->post('/api/admin/exam-fees/report', function () {
    $user = Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $data = json_decode(file_get_contents('php://input'), true);
    $year = (int)($data['year'] ?? date('Y'));
    $examName = trim($data['exam_name'] ?? '') ?: null;
    $dateFrom = trim($data['date_from'] ?? '') ?: null;
    $dateTo = trim($data['date_to'] ?? '') ?: null;
    $collectedBy = isset($data['collected_by']) ? (int)$data['collected_by'] : null;

    // administration restriction: only own collections
    if ($user['default_role'] === 'administration' || (isset($user['roles']) && in_array('administration', (array)$user['roles']) && !in_array('admin', (array)$user['roles']))) {
        $collectedBy = (int)$user['id'];
    }

    $sql = "SELECT ef.*, u.name AS collector_name, s.name AS student_name, s.student_id, s.class, s.student_group
            FROM exam_fees ef
            LEFT JOIN users u ON u.id = ef.collected_by
            LEFT JOIN students s ON s.user_id = ef.student_user_id
            WHERE ef.year = ?";
    $params = [$year];
    if ($examName) { $sql .= " AND ef.exam_name = ?"; $params[] = $examName; }
    if ($dateFrom) { $sql .= " AND DATE(ef.collected_at) >= ?"; $params[] = $dateFrom; }
    if ($dateTo) { $sql .= " AND DATE(ef.collected_at) <= ?"; $params[] = $dateTo; }
    if ($collectedBy) { $sql .= " AND ef.collected_by = ?"; $params[] = $collectedBy; }
    $sql .= " ORDER BY ef.collected_at DESC";
    $rows = Database::fetchAll($sql, $params);

    $total = 0.0;
    foreach ($rows as $r) $total += (float)$r['amount'];

    // per-user summary for the same filter
    $sql2 = "SELECT ef.collected_by, u.name, COUNT(*) collections, SUM(ef.amount) total
             FROM exam_fees ef LEFT JOIN users u ON u.id = ef.collected_by
             WHERE ef.year = ?";
    $params2 = [$year];
    if ($examName) { $sql2 .= " AND ef.exam_name = ?"; $params2[] = $examName; }
    if ($dateFrom) { $sql2 .= " AND DATE(ef.collected_at) >= ?"; $params2[] = $dateFrom; }
    if ($dateTo) { $sql2 .= " AND DATE(ef.collected_at) <= ?"; $params2[] = $dateTo; }
    if ($collectedBy) { $sql2 .= " AND ef.collected_by = ?"; $params2[] = $collectedBy; }
    $sql2 .= " GROUP BY ef.collected_by, u.name ORDER BY total DESC";
    $byUser = Database::fetchAll($sql2, $params2);

    Response::success([
        'total' => $total,
        'count' => count($rows),
        'rows' => $rows,
        'by_user' => $byUser,
        'restricted_own' => $collectedBy === (int)$user['id'],
    ]);
});

// ---------------------------------------------------------------------------
// DELETE /api/admin/exam-fees/by-student/{uid}/{year}/{exam} â€” undo all partials for a student (admin)
// ---------------------------------------------------------------------------
$router->delete('/api/admin/exam-fees/by-student/{uid}/{year}/{exam}', function ($uid, $year, $exam) {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    Database::delete('exam_fees', 'student_user_id = ? AND year = ? AND exam_name = ?', [(int)$uid, (int)$year, urldecode($exam)]);
    Response::success(['deleted' => true]);
});

// DELETE /api/admin/exam-fees/{id} â€” undo a single installment (admin only)
$router->delete('/api/admin/exam-fees/{id}', function ($params) {
    Auth::requireRole('admin');
    Database::delete('exam_fees', 'id = ?', [(int)$params['id']]);
    Response::success(['deleted' => true], 'Fee record removed');
});

// ---------------------------------------------------------------------------
// GET /api/admin/exam-fees/amount?exam_name=X â€” get/set the per-exam fee amount (admin)
// POST { exam_name, amount } (admin only) stores in site_settings
// ---------------------------------------------------------------------------
$router->get('/api/admin/exam-fees/amount', function () {
    Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $examName = trim($_GET['exam_name'] ?? '');
    if (!$examName) Response::validationError(['exam_name is required']);
    $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1", ['exam_fee_amount_' . strtolower(str_replace(' ', '_', $examName))]);
    Response::success(['exam_name' => $examName, 'amount' => $row ? (float)$row['setting_value'] : 0.0]);
});

$router->post('/api/admin/exam-fees/amount', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $examName = trim($data['exam_name'] ?? '');
    $amount = (float)($data['amount'] ?? 0);
    if (!$examName) Response::validationError(['exam_name is required']);
    $key = 'exam_fee_amount_' . strtolower(str_replace(' ', '_', $examName));
    $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = ? LIMIT 1", [$key]);
    if ($existing) {
        Database::update('site_settings', ['setting_value' => (string)$amount], 'setting_key = ?', [$key]);
    } else {
        Database::insert('site_settings', ['setting_key' => $key, 'setting_value' => (string)$amount]);
    }
    Response::success(['saved' => true, 'exam_name' => $examName, 'amount' => $amount]);
});
