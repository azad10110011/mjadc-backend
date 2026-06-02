<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/student-info
$router->get('/api/admin/student-info', function () {
    Auth::requireRole('admin');
    $row = Database::fetch(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'student_info'"
    );

    $default = [
        'headings' => [],
        'rows' => [],
        'fontSize' => 'text-sm',
        'fontStyle' => 'font-normal',
    ];

    if ($row && $row['setting_value']) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            $decoded = array_merge($default, $decoded);
            Response::success($decoded);
            return;
        }
    }

    Response::success($default);
}, [$adminMw]);

// PUT /api/admin/student-info
$router->put('/api/admin/student-info', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (!isset($data['headings']) || !isset($data['rows'])) {
        Response::validationError(['headings and rows are required']);
    }

    $value = json_encode([
        'headings' => $data['headings'],
        'rows' => $data['rows'],
        'fontSize' => $data['fontSize'] ?? 'text-sm',
        'fontStyle' => $data['fontStyle'] ?? 'font-normal',
    ]);

    $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = 'student_info'");
    if ($existing) {
        Database::update('site_settings', [
            'setting_value' => $value,
            'updated_by' => $user['id'],
        ], 'setting_key = ?', ['setting_key' => 'student_info']);
    } else {
        Database::insert('site_settings', [
            'setting_key' => 'student_info',
            'setting_value' => $value,
            'updated_by' => $user['id'],
        ]);
    }

    Response::success(null, 'Student info saved');
}, [$adminMw]);
