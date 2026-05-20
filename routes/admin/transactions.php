<?php

// GET /api/admin/transactions?student_id=&fee_type=&payment_method=&from=&to=&page=&per_page=
$router->get('/api/admin/transactions', function () {
    Auth::requireRole('admin');

    $studentId = $_GET['student_id'] ?? '';
    $feeType = $_GET['fee_type'] ?? '';
    $paymentMethod = $_GET['payment_method'] ?? '';
    $from = $_GET['from'] ?? '';
    $to = $_GET['to'] ?? '';
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = max(1, min(100, (int)($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];

    if ($studentId !== '') {
        $where[] = 's.student_id = ?';
        $params[] = $studentId;
    }
    if ($feeType !== '') {
        $where[] = 'p.fee_type = ?';
        $params[] = $feeType;
    }
    if ($paymentMethod !== '') {
        $where[] = 'p.payment_method = ?';
        $params[] = $paymentMethod;
    }
    if ($from !== '') {
        $where[] = 'DATE(p.created_at) >= ?';
        $params[] = $from;
    }
    if ($to !== '') {
        $where[] = 'DATE(p.created_at) <= ?';
        $params[] = $to;
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $countRow = Database::fetch(
        "SELECT COUNT(*) as total FROM payments p JOIN students s ON p.student_id = s.id {$whereClause}",
        $params
    );
    $total = (int)($countRow['total'] ?? 0);

    $payments = Database::fetchAll(
        "SELECT p.id, p.transaction_id, p.amount, p.payment_type, p.fee_type, p.payment_method,
                p.month_paid, p.year_paid, p.status, p.collected_by, p.created_at,
                s.student_id, s.name as student_name, s.class as student_class
         FROM payments p
         JOIN students s ON p.student_id = s.id
         {$whereClause}
         ORDER BY p.created_at DESC
         LIMIT {$perPage} OFFSET {$offset}",
        $params
    );

    $totalAmount = 0;
    foreach ($payments as $p) {
        $totalAmount += (float)$p['amount'];
    }

    Response::success([
        'payments' => $payments,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'total_pages' => max(1, (int)ceil($total / $perPage)),
        'total_amount' => $totalAmount,
    ]);
});

// POST /api/admin/transactions
$router->post('/api/admin/transactions', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('student_id', 'Student ID')
        ->required('amount', 'Amount');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
    if (!$student) Response::notFound('Student not found');

    $amount = (float)$data['amount'];
    $feeType = $data['fee_type'] ?? 'Tuition Fee';
    $paymentMethod = $data['payment_method'] ?? 'cash';
    $monthPaid = (int)($data['month_paid'] ?? date('m'));
    $yearPaid = (int)($data['year_paid'] ?? date('Y'));
    $collectedBy = $data['collected_by'] ?? 'Admin';
    $transactionId = $data['transaction_id'] ?? ('TXN' . time() . rand(100, 999));

    // Update or create tuition_fees record
    $existing = Database::fetch(
        "SELECT id, amount_due, amount_paid FROM tuition_fees WHERE student_id = ? AND year = ? AND month = ? AND fee_type = ?",
        [$student['id'], $yearPaid, $monthPaid, $feeType]
    );

    if ($existing) {
        $newPaid = (float)$existing['amount_paid'] + $amount;
        Database::update('tuition_fees', [
            'amount_paid' => $newPaid,
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => $paymentMethod,
            'transaction_id' => $transactionId,
        ], 'id = ?', ['id' => $existing['id']]);
    } else {
        Database::insert('tuition_fees', [
            'student_id' => $student['id'],
            'year' => $yearPaid,
            'month' => $monthPaid,
            'fee_type' => $feeType,
            'amount_due' => $amount,
            'amount_paid' => $amount,
            'paid_at' => date('Y-m-d H:i:s'),
            'payment_method' => $paymentMethod,
            'transaction_id' => $transactionId,
        ]);
    }

    // Create payment record
    Database::insert('payments', [
        'transaction_id' => $transactionId,
        'student_id' => $student['id'],
        'amount' => $amount,
        'payment_type' => 'tuition_fee',
        'fee_type' => $feeType,
        'payment_method' => $paymentMethod,
        'month_paid' => $monthPaid,
        'year_paid' => $yearPaid,
        'status' => 'success',
        'collected_by' => $collectedBy,
    ]);

    $paymentId = Database::getInstance()->lastInsertId();

    Response::created(['id' => (int)$paymentId], 'Transaction created');
});

// PUT /api/admin/transactions/{id}
$router->put('/api/admin/transactions/{id}', function (array $params) {
    Auth::requireRole('admin');

    $payment = Database::fetch("SELECT * FROM payments WHERE id = ?", [$params['id']]);
    if (!$payment) Response::notFound('Transaction not found');

    $data = json_decode(file_get_contents('php://input'), true);

    $oldAmount = (float)$payment['amount'];
    $newAmount = (float)($data['amount'] ?? $oldAmount);
    $feeType = $data['fee_type'] ?? $payment['fee_type'];
    $paymentMethod = $data['payment_method'] ?? $payment['payment_method'];
    $collectedBy = $data['collected_by'] ?? $payment['collected_by'];
    $monthPaid = (int)($data['month_paid'] ?? $payment['month_paid']);
    $yearPaid = (int)($data['year_paid'] ?? $payment['year_paid']);

    // Adjust tuition_fees
    $diff = $newAmount - $oldAmount;

    $tf = Database::fetch(
        "SELECT id, amount_due, amount_paid FROM tuition_fees WHERE student_id = ? AND year = ? AND month = ? AND fee_type = ?",
        [$payment['student_id'], $yearPaid, $monthPaid, $feeType]
    );

    if ($tf) {
        $newPaid = max(0, (float)$tf['amount_paid'] + $diff);
        Database::update('tuition_fees', [
            'amount_paid' => $newPaid,
        ], 'id = ?', ['id' => $tf['id']]);
    }

    Database::update('payments', [
        'amount' => $newAmount,
        'fee_type' => $feeType,
        'payment_method' => $paymentMethod,
        'collected_by' => $collectedBy,
        'month_paid' => $monthPaid,
        'year_paid' => $yearPaid,
    ], 'id = ?', ['id' => $payment['id']]);

    Response::success(null, 'Transaction updated');
});

// DELETE /api/admin/transactions/{id}
$router->delete('/api/admin/transactions/{id}', function (array $params) {
    Auth::requireRole('admin');

    $payment = Database::fetch("SELECT * FROM payments WHERE id = ?", [$params['id']]);
    if (!$payment) Response::notFound('Transaction not found');

    // Subtract amount from tuition_fees
    $tf = Database::fetch(
        "SELECT id, amount_paid FROM tuition_fees WHERE student_id = ? AND year = ? AND month = ? AND fee_type = ?",
        [$payment['student_id'], $payment['year_paid'], $payment['month_paid'], $payment['fee_type']]
    );

    if ($tf) {
        $newPaid = max(0, (float)$tf['amount_paid'] - (float)$payment['amount']);
        Database::update('tuition_fees', [
            'amount_paid' => $newPaid,
        ], 'id = ?', ['id' => $tf['id']]);
    }

    Database::delete('payments', 'id = ?', [$payment['id']]);

    Response::success(null, 'Transaction deleted');
});
