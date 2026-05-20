<?php

// GET /api/teacher/leave/summary
$router->get('/api/teacher/leave/summary', function () {
    $user = Auth::requireRole('teacher');

    $currentYear = date('Y');
    $allocations = Database::fetchAll(
        "SELECT * FROM leave_allocations WHERE role_type = 'teacher'"
    );

    $summary = [];
    foreach ($allocations as $alloc) {
        $taken = Database::fetch(
            "SELECT days_taken FROM leave_taken 
             WHERE user_id = ? AND year = ? AND leave_type = ?",
            [$user['id'], $currentYear, $alloc['leave_type']]
        );

        $daysTaken = $taken ? (int)$taken['days_taken'] : 0;

        $summary[] = [
            'type' => $alloc['leave_type'],
            'allocated' => (int)$alloc['total_days'],
            'taken' => $daysTaken,
            'remaining' => max(0, (int)$alloc['total_days'] - $daysTaken),
        ];
    }

    Response::success($summary);
});

// POST /api/teacher/leave/apply
$router->post('/api/teacher/leave/apply', function () {
    $user = Auth::requireRole('teacher');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('leave_type', 'Leave Type')
        ->required('from_date', 'From Date')
        ->required('to_date', 'To Date')
        ->required('reason', 'Reason');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    // Check maternity leave visibility
    if ($data['leave_type'] === 'maternity' && $user['gender'] !== 'female') {
        Response::forbidden('Maternity leave is only available for female teachers');
    }

    $leaves = Database::fetchAll(
        "SELECT lt.days_taken, la.total_days 
         FROM leave_taken lt
         JOIN leave_allocations la ON la.role_type = 'teacher' AND la.leave_type = lt.leave_type
         WHERE lt.user_id = ? AND lt.year = ? AND lt.leave_type = ?",
        [$user['id'], date('Y'), $data['leave_type']]
    );

    $from = new DateTime($data['from_date']);
    $to = new DateTime($data['to_date']);
    $daysRequested = $from->diff($to)->days + 1;

    $existingTaken = 0;
    $totalAllowed = 0;
    if (!empty($leaves)) {
        foreach ($leaves as $l) {
            $existingTaken += (int)$l['days_taken'];
            if (!$totalAllowed) $totalAllowed = (int)$l['total_days'];
        }
    }

    // If no existing record, get from allocation table
    if (!$totalAllowed) {
        $alloc = Database::fetch(
            "SELECT total_days FROM leave_allocations WHERE role_type = 'teacher' AND leave_type = ?",
            [$data['leave_type']]
        );
        $totalAllowed = $alloc ? (int)$alloc['total_days'] : 0;
    }

    // Only check balance for leave types that have allocations > 0
    if ($totalAllowed > 0 && ($existingTaken + $daysRequested > $totalAllowed)) {
        Response::error('Insufficient leave balance');
    }

    $applicationId = Database::insert('leave_applications', [
        'applicant_id' => $user['id'],
        'applicant_role' => 'teacher',
        'leave_type' => $data['leave_type'],
        'from_date' => $data['from_date'],
        'to_date' => $data['to_date'],
        'reason' => $data['reason'],
        'document_path' => null,
        'status' => 'pending',
    ]);

    Response::created(['id' => $applicationId], 'Leave application submitted');
});

// GET /api/teacher/leave/applications
$router->get('/api/teacher/leave/applications', function () {
    $user = Auth::requireRole('teacher');

    $applications = Database::fetchAll(
        "SELECT id, leave_type, from_date, to_date, reason, status, created_at 
         FROM leave_applications 
         WHERE applicant_id = ?
         ORDER BY created_at DESC",
        [$user['id']]
    );

    Response::success($applications);
});
