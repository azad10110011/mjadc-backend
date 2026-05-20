<?php

// GET /api/student/fees
$router->get('/api/student/fees', function () {
    $user = Auth::requireRole('student');

    $student = Database::fetch("SELECT id FROM students WHERE user_id = ?", [$user['id']]);
    if (!$student) {
        Response::notFound('Student profile not found');
    }

    $fees = Database::fetchAll(
        "SELECT id, year, month, amount_due, amount_paid, waiver_amount,
                GREATEST(amount_due - amount_paid - COALESCE(waiver_amount, 0), 0) as outstanding,
                payment_method, paid_at, transaction_id
         FROM tuition_fees 
         WHERE student_id = ?
         ORDER BY year DESC, month DESC",
        [$student['id']]
    );

    Response::success($fees);
});

// POST /api/student/pay-fee
$router->post('/api/student/pay-fee', function () {
    $user = Auth::requireRole('student');
    $data = json_decode(file_get_contents('php://input'), true);

    $validator = validate($data);
    $validator->required('fee_id', 'Fee ID');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $fee = Database::fetch(
        "SELECT tf.*, s.name as student_name, s.student_id 
         FROM tuition_fees tf 
         JOIN students s ON tf.student_id = s.id 
         WHERE tf.id = ? AND s.user_id = ?",
        [$data['fee_id'], $user['id']]
    );

    if (!$fee) {
        Response::notFound('Fee record not found');
    }

    $outstanding = $fee['amount_due'] - $fee['amount_paid'] - $fee['waiver_amount'];
    if ($outstanding <= 0) {
        Response::error('No outstanding amount for this month');
    }

    // Initiate payment
    $transactionId = 'TXN' . time() . rand(1000, 9999);
    Database::insert('payments', [
        'transaction_id' => $transactionId,
        'student_id' => $fee['student_id'],
        'amount' => $outstanding,
        'payment_type' => 'tuition_fee',
        'month_paid' => $fee['month'],
        'year_paid' => $fee['year'],
        'status' => 'pending',
    ]);

    Response::success([
        'transaction_id' => $transactionId,
        'amount' => $outstanding,
        'student_name' => $fee['student_name'],
        'student_id' => $fee['student_id'],
        'payment_url' => 'https://sandbox.sonalibank.com.bd/api/payment/initiate',
    ], 'Redirecting to payment gateway');
});
