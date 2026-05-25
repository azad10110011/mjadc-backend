<?php

// GET /api/admin/subject-parts?subject=
$router->get('/api/admin/subject-parts', function () {
    Auth::requireRole('admin');

    $subject = $_GET['subject'] ?? '';

    if ($subject) {
        $parts = Database::fetchAll(
            "SELECT id, subject, part_name, full_mark, pass_mark, sort_order
             FROM subject_parts
             WHERE subject = ?
             ORDER BY sort_order",
            [$subject]
        );
    } else {
        $parts = Database::fetchAll(
            "SELECT id, subject, part_name, full_mark, pass_mark, sort_order
             FROM subject_parts
             ORDER BY subject, sort_order"
        );
    }

    Response::success($parts);
});

// POST /api/admin/subject-parts
$router->post('/api/admin/subject-parts', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('subject', 'Subject')
        ->required('part_name', 'Part Name')
        ->required('full_mark', 'Full Mark')
        ->required('pass_mark', 'Pass Mark');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $existing = Database::fetch(
        "SELECT id FROM subject_parts WHERE subject = ? AND part_name = ?",
        [$data['subject'], $data['part_name']]
    );
    if ($existing) {
        Response::error('This part already exists for this subject', 409);
    }

    $id = Database::insert('subject_parts', [
        'subject' => $data['subject'],
        'part_name' => $data['part_name'],
        'full_mark' => $data['full_mark'],
        'pass_mark' => $data['pass_mark'],
        'sort_order' => $data['sort_order'] ?? 0,
    ]);

    Response::created(['id' => $id], 'Subject part added');
});

// PUT /api/admin/subject-parts/{id}
$router->put('/api/admin/subject-parts/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $updateData = [];

    foreach (['part_name', 'full_mark', 'pass_mark', 'sort_order'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }

    if (empty($updateData)) {
        Response::error('No data to update', 400);
    }

    Database::update('subject_parts', $updateData, 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'Subject part updated');
});

// DELETE /api/admin/subject-parts/{id}
$router->delete('/api/admin/subject-parts/{id}', function (array $params) {
    Auth::requireRole('admin');

    Database::delete('subject_parts', 'id = ?', [$params['id']]);
    Response::success(null, 'Subject part deleted');
});

// POST /api/admin/subject-parts/init-defaults
// Initialize default parts (MCQ, CQ, Practical) for ALL subjects
$router->post('/api/admin/subject-parts/init-defaults', function () {
    Auth::requireRole('admin');

    $configuredRows = Database::fetchAll("SELECT DISTINCT subject FROM subject_parts");
    $configuredSubjects = array_column($configuredRows, 'subject');

    $allSubjects = Database::fetchAll("SELECT name FROM subjects ORDER BY name");
    $defaults = getDefaultSubjectParts();
    $count = 0;

    foreach ($allSubjects as $row) {
        $subject = $row['name'];
        if (in_array($subject, $configuredSubjects)) continue;

        foreach ($defaults as $part) {
            Database::insert('subject_parts', [
                'subject' => $subject,
                'part_name' => $part['part_name'],
                'full_mark' => $part['full_mark'],
                'pass_mark' => $part['pass_mark'],
                'sort_order' => $part['sort_order'],
            ]);
            $count++;
        }
    }

    Response::success(['initialized' => $count], "{$count} default parts initialized");
});

// GET /api/admin/subject-parts/defaults
// Returns default parts (for reference / when no config exists)
$router->get('/api/admin/subject-parts/defaults', function () {
    Auth::requireRole('admin');
    Response::success(getDefaultSubjectParts());
});
