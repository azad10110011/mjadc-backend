<?php

$router->get('/api/admin/student-info', function () {
    Auth::requireRole('admin');
    $setting = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'student_info'");
    $data = $setting ? json_decode($setting['setting_value'], true) : ['headings' => [], 'rows' => [], 'fontSize' => 'text-sm', 'fontStyle' => 'font-normal'];
    Response::success($data);
});

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
        Database::update('site_settings', ['setting_value' => $value, 'updated_by' => $user['id']], "setting_key = 'student_info'");
    } else {
        Database::insert('site_settings', ['setting_key' => 'student_info', 'setting_value' => $value, 'updated_by' => $user['id']]);
    }
    Response::success(null, 'Student info saved');
});
