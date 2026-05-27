<?php

$router->get('/api/contact', function () {
    $settings = Database::fetchAll("SELECT setting_key, setting_value FROM site_settings WHERE setting_key IN ('contact_address','contact_phone','contact_email','contact_map')");
    $data = ['address' => '', 'phone' => '', 'email' => '', 'map' => ''];
    foreach ($settings as $s) {
        $data[str_replace('contact_', '', $s['setting_key'])] = $s['setting_value'];
    }
    Response::success($data);
});
