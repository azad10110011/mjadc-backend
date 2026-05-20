<?php

// GET /api/admin/settings
$router->get('/api/admin/settings', function () {
    Auth::requireRole('admin');
    $settings = Database::fetchAll("SELECT * FROM site_settings ORDER BY setting_key");
    Response::success($settings);
});

// PUT /api/admin/settings/{key}
$router->put('/api/admin/settings/{key}', function (array $params) {
    $user = Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['setting_value'])) {
        Response::validationError(['setting_value is required']);
    }

    $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$params['key']]);
    if ($existing) {
        Database::update('site_settings', [
            'setting_value' => $data['setting_value'],
            'updated_by' => $user['id'],
        ], 'setting_key = ?', ['setting_key' => $params['key']]);
    } else {
        Database::insert('site_settings', [
            'setting_key' => $params['key'],
            'setting_value' => $data['setting_value'],
            'updated_by' => $user['id'],
        ]);
    }

    Response::success(null, 'Setting updated');
});
