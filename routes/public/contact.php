<?php

// GET /api/contact
$router->get('/api/contact', function () {
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
