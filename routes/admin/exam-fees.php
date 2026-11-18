<?php
// Exam fee collection: partial payments, waivers, dues, will-pay-within, reports.
// Roles: admin + exam_controller collect & waive; administration collects via the Exam Fee Collection permission (Session 111).
// Reports: admin / exam_controller see every collector; everyone else only their own collections.
// QR verification remains in routes/public/exam-fee-verify.php (sums paid installments).

// ---------------------------------------------------------------------------
// Helpers
// ---------------------------------------------------------------------------
$examFeeTotal = function (string $examName, ?string $class = null): float {
    // Session 100: Exam Fee Set saves "Pre-Test (12th)"; collection uses "Pre-Test" — try the class key first.
    return duesExamFee($examName, $class);
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
    $cls = Database::fetch("SELECT class FROM students WHERE user_id = ?", [$studentUserId])['class'] ?? null;
    $total = $examFeeTotal($examName, $cls);
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
// POST /api/admin/exam-fees/collect - record a payment (admin / exam_controller; administration via permission)
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

    $total = $examFeeTotal($examName, ($data['class'] ?? null) ?: null);
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
    $uids = array_unique(array_merge(array_keys($paidBy), array_keys($waivedBy)));
    $classOf = $uids ? array_column(Database::fetchAll("SELECT user_id, class FROM students WHERE user_id IN (" . implode(',', array_map('intval', $uids)) . ")"), 'class', 'user_id') : [];
    foreach ($uids as $uid) {
        $paid = $paidBy[$uid] ?? 0.0;
        $waived = $waivedBy[$uid] ?? 0.0;
        $sTotal = $examFeeTotal($examName, $classOf[$uid] ?? null) ?: $total; // each student's own class fee
        $due = $sTotal > 0 ? max(0, $sTotal - $paid - $waived) : 0.0;
        $map[$uid] = array_merge($meta[$uid] ?? [], [
            'paid' => count($paidBy) > 0 && isset($paidBy[$uid]),
            'paid_total' => $paid,
            'waived_total' => $waived,
            'total_fee' => $sTotal,
            'due' => $due,
            'fully_paid' => $sTotal > 0 ? ($paid + $waived >= $sTotal) : isset($paidBy[$uid]),
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

    // Session 80: only Admin and Exam Controller may see other users' / all collections.
    // Everyone else (administration, or any user granted the permission) is forced to their own rows,
    // whatever collected_by they send. (Before, only the administration role was restricted, and a user
    // who was exam_controller + administration with default panel administration was wrongly restricted.)
    $canSeeAll = (bool)array_intersect(['admin', 'exam_controller'], (array)($user['roles'] ?? []));
    if (!$canSeeAll) {
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
        'restricted_own' => !$canSeeAll,
        'can_see_all' => $canSeeAll,
        'collected_by' => $collectedBy,
    ]);
});

// GET /api/admin/exam-fees/collectors?year=  (Session 80) — users who collected exam fees, for the
// report's user dropdown. Admin and Exam Controller only (others may only see their own report).
$router->get('/api/admin/exam-fees/collectors', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $year = (int)($_GET['year'] ?? date('Y'));
    Response::success(Database::fetchAll(
        "SELECT ef.collected_by AS id, COALESCE(u.name, CONCAT('User #', ef.collected_by)) AS name, u.email,
                COUNT(*) AS collections, SUM(ef.amount) AS total
         FROM exam_fees ef LEFT JOIN users u ON u.id = ef.collected_by
         WHERE ef.year = ? AND ef.collected_by IS NOT NULL
         GROUP BY ef.collected_by, u.name, u.email
         ORDER BY name",
        [$year]
    ));
});

// ---------------------------------------------------------------------------
// PUT /api/admin/exam-fees/{id} - edit a payment record (admin only)
$router->put('/api/admin/exam-fees/{id}', function ($params) {
    Auth::requireRole('admin');
    $id = (int)$params['id'];
    $data = json_decode(file_get_contents('php://input'), true);
    $updates = [];
    if (isset($data['amount']) && (float)$data['amount'] > 0) $updates['amount'] = (float)$data['amount'];
    if (isset($data['payment_method']) && in_array($data['payment_method'], ['cash', 'online'])) $updates['payment_method'] = $data['payment_method'];
    if (isset($data['will_pay_within'])) $updates['will_pay_within'] = trim($data['will_pay_within']) ?: null;
    if (empty($updates)) Response::validationError(['nothing to update']);
    Database::update('exam_fees', $updates, 'id = ?', [$id]);
    Response::success(['updated' => true], 'Payment record updated');
});

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

$router->get('/api/admin/exam-fees/amounts', function () {
    Auth::requireAnyRole(['admin', 'exam_controller', 'administration']);
    $rows = Database::fetchAll(
        "SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'exam_fee_amount_%' ORDER BY setting_key"
    );
    $list = [];
    foreach ($rows as $r) {
        $name = preg_replace('/^exam_fee_amount_/', '', $r['setting_key']);
        $name = str_replace('_', ' ', $name);
        // restore "(class)" suffix formatting from underscore-serialized keys
        $name = preg_replace('/\s*\((.*)\)$/u', ' ($1)', $name);
        $name = ucwords($name);
        $list[] = ['exam_name' => $name, 'amount' => (float)$r['setting_value']];
    }
    Response::success($list);
});

// DELETE /api/admin/exam-fees/amount?exam_name=X — remove a fee amount (admin)
$router->delete('/api/admin/exam-fees/amount', function () {
    Auth::requireRole('admin');
    $examName = trim($_GET['exam_name'] ?? '');
    if (!$examName) Response::validationError(['exam_name is required']);
    Database::delete('site_settings', 'setting_key = ?', ['exam_fee_amount_' . strtolower(str_replace(' ', '_', $examName))]);
    Response::success(['deleted' => true], 'Fee amount removed');
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

// ---------------------------------------------------------------------------
// POST /api/admin/exam-fees/paid-by-subject  (Session 96)
// { year, exam_name, class?, group? } → students who paid this exam's fee, listed under every
// subject they take (compulsory, selective, optional). Used by Exam Management › Exam Fee Paid List.
// ---------------------------------------------------------------------------
$router->post('/api/admin/exam-fees/paid-by-subject', function () use ($examFeeTotal) {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $year = (int)($d['year'] ?? 0);
    $examName = trim((string)($d['exam_name'] ?? ''));
    $class = trim((string)($d['class'] ?? ''));
    $group = trim((string)($d['group'] ?? ''));
    if ($year < 2000 || $examName === '') Response::validationError(['year and exam_name are required']);

    $where = 'f.year = ? AND f.exam_name = ?';
    $params = [$year, $examName];
    if ($class !== '') { $where .= ' AND s.class = ?'; $params[] = $class; }
    if ($group !== '') { $where .= ' AND s.student_group = ?'; $params[] = $group; }
    $rows = Database::fetchAll(
        "SELECT s.id, s.user_id, s.student_id, s.name, s.class, s.student_group, s.class_roll,
                s.compulsory_subjects, s.selective_subjects, s.optional_subject,
                SUM(f.amount) AS paid, MAX(f.collected_at) AS last_paid
         FROM exam_fees f JOIN students s ON s.user_id = f.student_user_id
         WHERE $where GROUP BY s.id",
        $params
    );

    // waivers count towards "fully paid"
    $waived = [];
    foreach (Database::fetchAll("SELECT student_user_id, SUM(amount) w FROM exam_fee_waivers WHERE year = ? AND exam_name = ? GROUP BY student_user_id", [$year, $examName]) as $w) {
        $waived[(int)$w['student_user_id']] = (float)$w['w'];
    }
    $feeFor = function (string $cls) use ($examFeeTotal, $examName): float {
        $t = $examFeeTotal("$examName ($cls)");
        return $t > 0 ? $t : $examFeeTotal($examName);
    };

    $norm = function (string $s): string {
        $s = trim($s);
        return strcasecmp($s, 'ICT') === 0 ? 'Information and Communication Technology' : $s;
    };
    $list = function ($v): array {
        $a = json_decode((string)$v, true);
        if (!is_array($a)) $a = $v ? explode(',', (string)$v) : [];
        return array_values(array_filter(array_map(fn($x) => trim((string)$x), $a), fn($x) => $x !== '' && stripos($x, 'n/a') !== 0));
    };

    $groupNames = function_exists('catalogGroupNames') ? catalogGroupNames() : ['Science', 'Business Studies', 'Humanities'];
    $groupRank = array_flip($groupNames);
    usort($rows, fn($a, $b) =>
        [($groupRank[$a['student_group']] ?? 99), (int)$a['class_roll'], $a['student_id']]
        <=> [($groupRank[$b['student_group']] ?? 99), (int)$b['class_roll'], $b['student_id']]);

    $subjects = [];   // name => ['types' => [], 'students' => []]
    $students = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $fee = $feeFor((string)$r['class']);
        $paid = (float)$r['paid'];
        $w = $waived[(int)$r['user_id']] ?? 0.0;
        $st = [
            'student_id' => $r['student_id'], 'name' => $r['name'], 'class' => $r['class'],
            'group' => $r['student_group'], 'class_roll' => $r['class_roll'],
            'paid' => $paid, 'waived' => $w, 'fee' => $fee,
            'status' => ($fee <= 0 || $paid + $w >= $fee) ? 'paid' : 'partial',
            'last_paid' => $r['last_paid'],
        ];
        $students[] = $st;
        $total += $paid;
        $take = [];
        foreach ($list($r['compulsory_subjects']) as $s) $take[$norm($s)] = 'compulsory';
        foreach ($list($r['selective_subjects']) as $s) $take[$norm($s)] = $take[$norm($s)] ?? 'selective';
        if (trim((string)$r['optional_subject']) !== '' && stripos((string)$r['optional_subject'], 'n/a') !== 0) {
            $take[$norm((string)$r['optional_subject'])] = $take[$norm((string)$r['optional_subject'])] ?? 'optional';
        }
        foreach ($take as $name => $type) {
            $subjects[$name] ??= ['subject' => $name, 'types' => [], 'students' => []];
            $subjects[$name]['types'][$type] = true;
            $subjects[$name]['students'][] = $st + ['type' => $type];
        }
    }

    // Session 98: subjects grouped by their subject group — General (Bangla, English, ICT first),
    // then the student groups in catalog order (Science, Business Studies, Humanities, …), then others.
    $groupOrder = array_merge(['General'], $groupNames);
    $gRank = array_flip($groupOrder);
    $subjectGroup = []; // lower(name) => group with the best rank (e.g. English: General beats BMT)
    foreach (Database::fetchAll("SELECT name, `group` FROM subjects") as $row) {
        $k = strtolower(trim((string)$row['name']));
        $g = (string)$row['group'];
        if (!isset($subjectGroup[$k]) || ($gRank[$g] ?? 50) < ($gRank[$subjectGroup[$k]] ?? 50)) $subjectGroup[$k] = $g;
    }
    $first = ['bangla' => 0, 'english' => 1, 'information and communication technology' => 2];
    $out = array_values($subjects);
    foreach ($out as &$s) {
        $s['subject_group'] = $subjectGroup[strtolower($s['subject'])] ?? 'Other';
        if (isset($first[strtolower($s['subject'])])) $s['subject_group'] = 'General';
    }
    unset($s);
    usort($out, fn($a, $b) =>
        [($gRank[$a['subject_group']] ?? 50), $a['subject_group'], ($first[strtolower($a['subject'])] ?? 9), $a['subject']]
        <=> [($gRank[$b['subject_group']] ?? 50), $b['subject_group'], ($first[strtolower($b['subject'])] ?? 9), $b['subject']]);
    foreach ($out as &$s) { $s['types'] = array_keys($s['types']); $s['count'] = count($s['students']); }
    unset($s);

    Response::success([
        'year' => $year, 'exam_name' => $examName, 'class' => $class, 'group' => $group,
        'total_students' => count($students), 'total_amount' => $total,
        'students' => $students, 'subjects' => $out,
    ]);
});
