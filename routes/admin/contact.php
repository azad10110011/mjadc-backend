<?php

// GET /api/admin/contact
$router->get('/api/admin/contact', function () {
    Auth::requireRole('admin');
    $keys = ['contact_address', 'contact_phone', 'contact_email', 'contact_map'];
    $rows = Database::fetchAll(
        "SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('" . implode("','", $keys) . "')"
    );
    $data = ['address' => '', 'phone' => '', 'email' => '', 'map' => ''];
    foreach ($rows as $row) {
        $map = ['contact_address' => 'address', 'contact_phone' => 'phone', 'contact_email' => 'email', 'contact_map' => 'map'];
        $data[$map[$row['setting_key']]] = $row['setting_value'];
    }
    Response::success($data);
});

// PUT /api/admin/contact
$router->put('/api/admin/contact', function () {
    $user = Auth::requireRole('admin');
    $body = json_decode(file_get_contents('php://input'), true);
    $fields = [
        'contact_address' => $body['address'] ?? '',
        'contact_phone' => $body['phone'] ?? '',
        'contact_email' => $body['email'] ?? '',
        'contact_map' => $body['map'] ?? '',
    ];
    foreach ($fields as $key => $value) {
        $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
        if ($existing) {
            Database::update('site_settings', [
                'setting_value' => $value,
                'updated_by' => $user['id'],
            ], 'setting_key = ?', ['setting_key' => $key]);
        } else {
            Database::insert('site_settings', [
                'setting_key' => $key,
                'setting_value' => $value,
                'updated_by' => $user['id'],
            ]);
        }
    }
    Response::success(null, 'Contact info saved');
});
