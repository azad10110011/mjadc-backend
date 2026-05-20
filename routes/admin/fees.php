<?php

// GET /api/admin/tuition-fees
$router->get('/api/admin/tuition-fees', function () {
    Auth::requireRole('admin');

    $year = $_GET['year'] ?? 'all';
    $month = $_GET['month'] ?? 'all';
    $class = $_GET['class'] ?? 'all';

    $where = [];
    $params = [];

    if ($year !== 'all') { $where[] = 'tf.year = ?'; $params[] = $year; }
    if ($month !== 'all') { $where[] = 'tf.month = ?'; $params[] = $month; }
    if ($class !== 'all') { $where[] = 's.class = ?'; $params[] = $class; }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $fees = Database::fetchAll(
        "SELECT s.student_id, s.name as student_name, s.class,
                COALESCE(SUM(tf.amount_due), 0) as total_due,
                COALESCE(SUM(tf.amount_paid), 0) as total_paid,
                GREATEST(COALESCE(SUM(tf.amount_due - tf.amount_paid - COALESCE(tf.waiver_amount, 0)), 0), 0) as unpaid
         FROM tuition_fees tf
         JOIN students s ON tf.student_id = s.id
         {$whereClause}
         GROUP BY s.id
         ORDER BY s.student_id",
        $params
    );

    Response::success($fees);
});

// POST /api/admin/fees/add
$router->post('/api/admin/fees/add', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('fee_type', 'Fee type')
        ->required('amount', 'Amount');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $amount = (float)$data['amount'];
    $feeType = $data['fee_type'];
    $note = $data['note'] ?? '';
    $months = $data['months'] ?? [];
    $currentYear = (int)date('Y');

    // Get target student IDs
    $studentIds = [];
    if (!empty($data['student_id'])) {
        $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
        if (!$student) Response::notFound('Student not found');
        $studentIds[] = (int)$student['id'];
    } elseif (!empty($data['class'])) {
        $students = Database::fetchAll("SELECT id FROM students WHERE class = ?", [$data['class']]);
        foreach ($students as $s) $studentIds[] = (int)$s['id'];
        if (empty($studentIds)) Response::error('No students found in this class', 404);
    } else {
        Response::error('Student ID or Class is required', 422);
    }

    $created = 0;
    if ($feeType === 'Tuition Fee' && !empty($months)) {
        foreach ($studentIds as $sid) {
            foreach ($months as $m) {
                $existing = Database::fetch(
                    "SELECT id FROM tuition_fees WHERE student_id = ? AND year = ? AND month = ?",
                    [$sid, $currentYear, $m]
                );
                if (!$existing) {
                    Database::insert('tuition_fees', [
                        'student_id' => $sid,
                        'year' => $currentYear,
                        'month' => $m,
                        'fee_type' => $feeType,
                        'amount_due' => $amount,
                        'amount_paid' => 0,
                    ]);
                    $created++;
                }
            }
        }
    } else {
        // Other fee types: create one record per student
        foreach ($studentIds as $sid) {
            Database::insert('tuition_fees', [
                'student_id' => $sid,
                'year' => $currentYear,
                'month' => 0,
                'fee_type' => $feeType,
                'amount_due' => $amount,
                'amount_paid' => 0,
            ]);
            $created++;
        }
    }

    Response::created(['records_created' => $created], "{$created} fee record(s) created");
});

// GET /api/admin/fees/student-due?student_id=
$router->get('/api/admin/fees/student-due', function () {
    Auth::requireRole('admin');

    $studentId = $_GET['student_id'] ?? '';
    if (!$studentId) {
        Response::error('Student ID is required', 422);
    }

    $student = Database::fetch("SELECT id, student_id, name, class FROM students WHERE student_id = ?", [$studentId]);
    if (!$student) {
        Response::notFound('Student not found');
    }

    $records = Database::fetchAll(
        "SELECT id, year, month, amount_due, amount_paid, 
                COALESCE(waiver_amount, 0) as waiver_amount,
                GREATEST(amount_due - amount_paid - COALESCE(waiver_amount, 0), 0) as outstanding
         FROM tuition_fees 
         WHERE student_id = ?
         ORDER BY year DESC, month DESC",
        [$student['id']]
    );

    $totalDue = 0;
    $totalPaid = 0;
    $totalWaiver = 0;
    $totalOutstanding = 0;
    foreach ($records as $r) {
        $totalDue += (float)$r['amount_due'];
        $totalPaid += (float)$r['amount_paid'];
        $totalWaiver += (float)$r['waiver_amount'];
        $totalOutstanding += (float)$r['outstanding'];
    }

    Response::success([
        'student' => $student,
        'records' => $records,
        'summary' => [
            'total_due' => $totalDue,
            'total_paid' => $totalPaid,
            'total_waiver' => $totalWaiver,
            'total_outstanding' => $totalOutstanding,
        ],
    ]);
});

// POST /api/admin/fees/waiver
$router->post('/api/admin/fees/waiver', function () {
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

    // Apply waiver to the student's fees: order by newest first, apply until amount is exhausted
    $fees = Database::fetchAll(
        "SELECT id, amount_due, amount_paid, waiver_amount, 
                (amount_due - amount_paid - COALESCE(waiver_amount, 0)) as outstanding
         FROM tuition_fees 
         WHERE student_id = ? AND (amount_due - amount_paid - COALESCE(waiver_amount, 0)) > 0
         ORDER BY year DESC, month DESC",
        [$student['id']]
    );

    $remaining = $amount;
    $updated = 0;
    foreach ($fees as $fee) {
        if ($remaining <= 0) break;
        $outstanding = (float)$fee['outstanding'];
        $apply = min($remaining, $outstanding);
        $currentWaiver = (float)($fee['waiver_amount'] ?? 0);
        Database::update('tuition_fees', [
            'waiver_amount' => $currentWaiver + $apply
        ], 'id = ?', ['id' => $fee['id']]);
        $remaining -= $apply;
        $updated++;
    }

    Response::success(['waiver_amount' => $amount, 'records_updated' => $updated], 'Waiver applied successfully');
});

// GET /api/admin/fees/collected-summary?from=&to=&collected_by=&fee_type=&year=
$router->get('/api/admin/fees/collected-summary', function () {
    Auth::requireRole('admin');

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
