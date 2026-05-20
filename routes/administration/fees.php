<?php

// GET /api/admin-panel/fees/search?student_id=
$router->get('/api/admin-panel/fees/search', function () {
    Auth::requireRole('administration');

    $studentId = $_GET['student_id'] ?? '';
    if (!$studentId) {
        Response::validationError(['student_id is required']);
    }

    $student = Database::fetch(
        "SELECT s.*, 
                COALESCE(SUM(tf.amount_due), 0) as total_due,
                COALESCE(SUM(tf.amount_paid), 0) as total_paid,
                COALESCE(SUM(tf.waiver_amount), 0) as total_waiver,
                GREATEST(COALESCE(SUM(tf.amount_due - tf.amount_paid - COALESCE(tf.waiver_amount, 0)), 0), 0) as unpaid
         FROM students s
         LEFT JOIN tuition_fees tf ON s.id = tf.student_id
         WHERE s.student_id = ?
         GROUP BY s.id",
        [$studentId]
    );

    if (!$student) {
        Response::notFound('Student not found');
    }

    Response::success($student);
});

// POST /api/admin-panel/fees/collect
$router->post('/api/admin-panel/fees/collect', function () {
    $user = Auth::requireRole('administration');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('student_id', 'Student ID')
        ->required('amount', 'Amount');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
    if (!$student) {
        Response::notFound('Student not found');
    }

    $currentMonth = (int)date('m');
    $currentYear = (int)date('Y');
    $transactionId = 'CASH' . time() . rand(100, 999);
    $collectedBy = $user['name'] ?? 'Unknown';
    $feeType = $data['fee_type'] ?? 'Tuition Fee';

    // Update or create fee record for current month/year
    $existing = Database::fetch(
        "SELECT id, amount_paid FROM tuition_fees WHERE student_id = ? AND year = ? AND month = ?",
        [$student['id'], $currentYear, $currentMonth]
    );

    if ($existing) {
        $newPaid = (float)$existing['amount_paid'] + (float)$data['amount'];
        Database::update('tuition_fees', [
            'amount_paid' => $newPaid,
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => 'cash',
            'transaction_id' => $transactionId,
        ], 'id = ?', ['id' => $existing['id']]);
    } else {
        Database::insert('tuition_fees', [
            'student_id' => $student['id'],
            'year' => $currentYear,
            'month' => $currentMonth,
            'fee_type' => $feeType,
            'amount_due' => 0,
            'amount_paid' => $data['amount'],
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => 'cash',
            'transaction_id' => $transactionId,
        ]);
    }

    // Record payment
    Database::insert('payments', [
        'transaction_id' => $transactionId,
        'student_id' => $student['id'],
        'amount' => $data['amount'],
        'payment_type' => 'tuition_fee',
        'fee_type' => $feeType,
        'payment_method' => 'cash',
        'month_paid' => $currentMonth,
        'year_paid' => $currentYear,
        'status' => 'success',
        'collected_by' => $collectedBy,
    ]);

    Response::success([
        'transaction_id' => $transactionId,
        'collected_by' => $collectedBy,
        'receipt' => "Payment of {$data['amount']} BDT received for {$currentMonth}/{$currentYear}",
    ], 'Payment recorded');
});

// GET /api/admin-panel/fees/history?student_id=
$router->get('/api/admin-panel/fees/history', function () {
    Auth::requireRole('administration');

    $studentId = $_GET['student_id'] ?? '';
    if (!$studentId) {
        Response::validationError(['student_id is required']);
    }

    $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$studentId]);
    if (!$student) {
        Response::notFound('Student not found');
    }

    $payments = Database::fetchAll(
        "SELECT * FROM payments WHERE student_id = ? ORDER BY created_at DESC",
        [$student['id']]
    );

    Response::success($payments);
});

// GET /api/admin-panel/fees/collected-summary?from=&to=&collected_by=&fee_type=&year=
$router->get('/api/admin-panel/fees/collected-summary', function () {
    Auth::requireRole('administration');

    $from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
    $to = $_GET['to'] ?? date('Y-m-d');
    $collectedBy = $_GET['collected_by'] ?? '';
    $feeType = $_GET['fee_type'] ?? '';
    $year = $_GET['year'] ?? '';

    $where = "DATE(p.created_at) >= ? AND DATE(p.created_at) <= ?";
    $params = [$from, $to];

    if ($collectedBy !== '') {
        $where .= " AND p.collected_by = ?";
        $params[] = $collectedBy;
    }

    if ($feeType !== '') {
        $where .= " AND p.fee_type = ?";
        $params[] = $feeType;
    }

    if ($year !== '') {
        $where .= " AND p.year_paid = ?";
        $params[] = $year;
    }

    $payments = Database::fetchAll(
        "SELECT p.id, p.transaction_id, p.amount, p.payment_method, p.fee_type, p.year_paid, p.collected_by, p.created_at,
                s.student_id, s.name as student_name
         FROM payments p
         JOIN students s ON p.student_id = s.id
         WHERE {$where}
         ORDER BY p.created_at DESC",
        $params
    );

    $totalAmount = 0;
    foreach ($payments as $p) {
        $totalAmount += (float)$p['amount'];
    }

    // Get distinct collectors for filter dropdown
    $collectors = Database::fetchAll(
        "SELECT DISTINCT collected_by FROM payments WHERE collected_by IS NOT NULL AND collected_by != '' ORDER BY collected_by"
    );

    Response::success([
        'from' => $from,
        'to' => $to,
        'total_amount' => $totalAmount,
        'total_transactions' => count($payments),
        'payments' => $payments,
        'collectors' => array_map(fn($c) => $c['collected_by'], $collectors),
    ]);
});
