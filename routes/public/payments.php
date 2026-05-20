<?php

// GET /api/payments/student/{student_id}
$router->get('/api/payments/student/{student_id}', function (array $params) {
    $student = Database::fetch(
        "SELECT id, student_id, name, class, section, mobile 
         FROM students WHERE student_id = ?",
        [$params['student_id']]
    );
    if (!$student) {
        Response::notFound('Student not found');
    }

    $fees = Database::fetchAll(
        "SELECT id, year, month, fee_type, amount_due, amount_paid, waiver_amount
         FROM tuition_fees WHERE student_id = ? ORDER BY year ASC, month ASC",
        [$student['id']]
    );

    $overpaidAmount = 0;
    foreach ($fees as &$fee) {
        $bal = (float)$fee['amount_due'] - (float)$fee['amount_paid'] - (float)$fee['waiver_amount'];
        if ($bal < 0) {
            $overpaidAmount += abs($bal);
            $fee['due'] = '0.00';
        } else {
            $fee['due'] = (string)$bal;
        }
    }
    unset($fee);

    if ($overpaidAmount > 0) {
        foreach ($fees as &$fee) {
            if ($overpaidAmount <= 0) break;
            $due = (float)$fee['due'];
            if ($due > 0) {
                $apply = min($due, $overpaidAmount);
                $fee['due'] = (string)($due - $apply);
                $overpaidAmount -= $apply;
            }
        }
        unset($fee);
    }

    usort($fees, fn($a, $b) => ($b['year'] ?? '') <=> ($a['year'] ?? '') ?: ($b['month'] ?? 0) <=> ($a['month'] ?? 0));

    $totalDue = array_sum(array_column($fees, 'amount_due'));
    $totalPaid = array_sum(array_column($fees, 'amount_paid'));
    $totalOutstanding = array_sum(array_column($fees, 'due'));

    Response::success([
        'student' => $student,
        'fees' => $fees,
        'total_due' => $totalDue,
        'total_paid' => $totalPaid,
        'total_outstanding' => $totalOutstanding,
    ]);
});

// POST /api/payments/initiate
$router->post('/api/payments/initiate', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('amount', 'Amount')->required('payment_type', 'Payment Type');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $transactionId = 'TXN' . time() . rand(1000, 9999);

    $paymentData = [
        'transaction_id' => $transactionId,
        'amount' => $data['amount'],
        'payment_type' => $data['payment_type'],
        'payment_method' => 'online',
        'status' => 'pending',
    ];

    if (isset($data['student_id'])) {
        $paymentData['student_id'] = $data['student_id'];
    }
    if (isset($data['admission_id'])) {
        $paymentData['admission_id'] = $data['admission_id'];
    }

    Database::insert('payments', $paymentData);

    // In production: initiate Sonali Bank API payment
    $sandboxUrl = 'https://sandbox.sonalibank.com.bd/api/payment/initiate';

    Response::success([
        'transaction_id' => $transactionId,
        'amount' => $data['amount'],
        'payment_url' => $sandboxUrl,
        'merchant_id' => $_ENV['SONALI_MERCHANT_ID'] ?? '',
    ], 'Payment initiated');
});

// POST /api/payments/callback
$router->post('/api/payments/callback', function () {
    $data = json_decode(file_get_contents('php://input'), true);

    // Verify with Sonali Bank
    $transactionId = $data['transaction_id'] ?? '';
    $bankTxnId = $data['bank_transaction_id'] ?? '';
    $status = $data['status'] ?? 'failed';

    $payment = Database::fetch("SELECT * FROM payments WHERE transaction_id = ?", [$transactionId]);
    if (!$payment) {
        Response::notFound('Transaction not found');
    }

    $dbStatus = $status === 'success' ? 'success' : 'failed';
    Database::update(
        'payments',
        [
            'status' => $dbStatus,
            'bank_transaction_id' => $bankTxnId,
        ],
        'transaction_id = ?',
        ['transaction_id' => $transactionId]
    );

    if ($dbStatus === 'success' && $payment['payment_type'] === 'admission_fee' && $payment['admission_id']) {
        Database::update(
            'admissions',
            ['fee_paid' => 1, 'transaction_id' => $transactionId],
            'id = ?',
            ['id' => $payment['admission_id']]
        );
    }

    if ($dbStatus === 'success' && $payment['payment_type'] === 'tuition_fee' && $payment['student_id']) {
        if ($payment['year_paid'] && $payment['month_paid']) {
            Database::query(
                "UPDATE tuition_fees SET amount_paid = amount_paid + ?, paid_at = NOW(), transaction_id = ?, payment_method = 'online'
                 WHERE student_id = ? AND year = ? AND month = ?",
                [
                    $payment['amount'],
                    $transactionId,
                    $payment['student_id'],
                    $payment['year_paid'],
                    $payment['month_paid'],
                ]
            );
        } else {
            $fees = Database::fetchAll(
                "SELECT id, GREATEST(amount_due - amount_paid - COALESCE(waiver_amount, 0), 0) as outstanding
                 FROM tuition_fees
                 WHERE student_id = ? AND (amount_due - amount_paid - COALESCE(waiver_amount, 0)) > 0
                 ORDER BY year ASC, month ASC",
                [$payment['student_id']]
            );
            $remaining = (float)$payment['amount'];
            foreach ($fees as $fee) {
                if ($remaining <= 0) break;
                $outstanding = (float)$fee['outstanding'];
                $apply = min($remaining, $outstanding);
                Database::query(
                    "UPDATE tuition_fees SET amount_paid = amount_paid + ?, paid_at = NOW(), transaction_id = ?, payment_method = 'online'
                     WHERE id = ?",
                    [$apply, $transactionId, $fee['id']]
                );
                $remaining -= $apply;
            }
        }
    }

    Response::success(['status' => $dbStatus, 'transaction_id' => $transactionId]);
});

// GET /api/payments/receipt/{transaction_id}
$router->get('/api/payments/receipt/{transaction_id}', function (array $params) {
    $payment = Database::fetch(
        "SELECT p.*, s.name as student_name, s.student_id 
         FROM payments p 
         LEFT JOIN students s ON p.student_id = s.id 
         WHERE p.transaction_id = ?",
        [$params['transaction_id']]
    );
    if (!$payment) {
        Response::notFound('Payment not found');
    }

    Database::update('payments', ['receipt_generated' => 1], 'transaction_id = ?', ['transaction_id' => $params['transaction_id']]);

    Response::success($payment);
});
