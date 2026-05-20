<?php

// GET /api/settings/footer-text
$router->get('/api/settings/footer-text', function () {
    $setting = Database::fetch(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'footer_text'"
    );
    Response::success([
        'setting_value' => $setting ? $setting['setting_value'] : '© 2026-MJADC. WebSite Created & Designed By MAK Azad, Lecturer (ICT)',
    ]);
});

// GET /api/settings/{key}
$router->get('/api/settings/{key}', function (array $params) {
    $setting = Database::fetch(
        "SELECT setting_key, setting_value, updated_at 
         FROM site_settings WHERE setting_key = ?",
        [$params['key']]
    );
    if (!$setting) {
        Response::notFound('Setting not found');
    }
    Response::success($setting);
});
