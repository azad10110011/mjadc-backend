<?php

// GET /api/principal/fees/summary
$router->get('/api/principal/fees/summary', function () {
    Auth::requireRole('principal');

    $year = $_GET['year'] ?? 'all';
    $month = $_GET['month'] ?? 'all';
    $class = $_GET['class'] ?? 'all';

    $where = [];
    $params = [];

    if ($year !== 'all') { $where[] = 'tf.year = ?'; $params[] = $year; }
    if ($month !== 'all') { $where[] = 'tf.month = ?'; $params[] = $month; }
    if ($class !== 'all') { $where[] = 's.class = ?'; $params[] = $class; }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $summary = Database::fetchAll(
        "SELECT s.class, s.section,
                COUNT(DISTINCT s.id) as student_count,
                COALESCE(SUM(tf.amount_due), 0) as total_due,
                COALESCE(SUM(tf.amount_paid), 0) as total_paid,
                COALESCE(SUM(tf.waiver_amount), 0) as total_waiver
         FROM tuition_fees tf
         JOIN students s ON tf.student_id = s.id
         {$whereClause}
         GROUP BY s.class, s.section
         ORDER BY s.class, s.section",
        $params
    );

    Response::success($summary);
});

// GET /api/principal/fees/report
$router->get('/api/principal/fees/report', function () {
    Auth::requireRole('principal');

    $year = $_GET['year'] ?? date('Y');
    $class = $_GET['class'] ?? 'all';
    $month = $_GET['month'] ?? 'all';

    $where = ['tf.year = ?'];
    $params = [$year];

    if ($class !== 'all') { $where[] = 's.class = ?'; $params[] = $class; }
    if ($month !== 'all') { $where[] = 'tf.month = ?'; $params[] = $month; }

    $whereClause = 'WHERE ' . implode(' AND ', $where);

    $report = Database::fetchAll(
        "SELECT s.student_id, s.name,
                COALESCE(SUM(tf.amount_due), 0) as total_due,
                COALESCE(SUM(tf.amount_paid), 0) as total_paid,
                GREATEST(COALESCE(SUM(tf.amount_due - tf.amount_paid - COALESCE(tf.waiver_amount, 0)), 0), 0) as unpaid
         FROM tuition_fees tf
         JOIN students s ON tf.student_id = s.id
         {$whereClause}
         GROUP BY s.student_id, s.name
         ORDER BY s.student_id",
        $params
    );

    Response::success($report);
});

// POST /api/principal/fees/waiver
$router->post('/api/principal/fees/waiver', function () {
    Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('student_id', 'Student ID')
        ->required('amount', 'Waiver Amount')
        ->numeric('amount');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $student = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
    if (!$student) {
        Response::notFound('Student not found');
    }

    // Apply waiver to all unpaid months (only increment waiver_amount, never modify amount_due)
    Database::query(
        "UPDATE tuition_fees 
         SET waiver_amount = waiver_amount + ?
         WHERE student_id = ? AND (amount_due - amount_paid - waiver_amount) > 0",
        [$data['amount'], $student['id']]
    );

    // Log waiver in settings or a waiver log table
    Response::success(null, 'Waiver applied successfully');
});
