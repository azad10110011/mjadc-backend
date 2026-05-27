<?php

$router->get('/api/admin/contact', function () {
    Auth::requireRole('admin');
    $settings = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('contact_address','contact_phone','contact_email','contact_map')");
    $data = ['address' => '', 'phone' => '', 'email' => '', 'map' => ''];
    foreach ($settings as $s) {
        $data[str_replace('contact_', '', $s['setting_key'])] = $s['setting_value'];
    }
    Response::success($data);
});

$router->put('/api/admin/contact', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $fields = ['address', 'phone', 'email', 'map'];
    foreach ($fields as $f) {
        if (isset($data[$f])) {
            $key = 'contact_' . $f;
            $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = ?", [$key]);
            if ($existing) {
                Database::update('site_settings', [
                    'setting_value' => $data[$f],
                    'updated_by' => $user['id'],
                ], 'setting_key = ?', ['setting_key' => $key]);
            } else {
                Database::insert('site_settings', [
                    'setting_key' => $key,
                    'setting_value' => $data[$f],
                    'updated_by' => $user['id'],
                ]);
            }
        }
    }
    Response::success(null, 'Contact info saved');
});
