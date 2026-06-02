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

// GET /api/settings/last-updated
$router->get('/api/settings/last-updated', function () {
    Database::query("SET time_zone = '+06:00'");
    $setting = Database::fetch(
        "SELECT MAX(ts) AS last_updated FROM (
            SELECT MAX(updated_at) AS ts FROM site_settings
            UNION ALL SELECT MAX(updated_at) AS ts FROM page_content
            UNION ALL SELECT MAX(updated_at) AS ts FROM students
            UNION ALL SELECT MAX(updated_at) AS ts FROM staff
            UNION ALL SELECT MAX(updated_at) AS ts FROM notices
            UNION ALL SELECT MAX(updated_at) AS ts FROM exam_results
            UNION ALL SELECT MAX(updated_at) AS ts FROM tuition_fees
            UNION ALL SELECT MAX(updated_at) AS ts FROM achievements
            UNION ALL SELECT MAX(updated_at) AS ts FROM users
            UNION ALL SELECT MAX(updated_at) AS ts FROM leave_allocations
            UNION ALL SELECT MAX(created_at) AS ts FROM teachers
            UNION ALL SELECT MAX(created_at) AS ts FROM subjects
            UNION ALL SELECT MAX(created_at) AS ts FROM events
            UNION ALL SELECT MAX(created_at) AS ts FROM notifications
            UNION ALL SELECT MAX(uploaded_at) AS ts FROM syllabus
            UNION ALL SELECT MAX(uploaded_at) AS ts FROM routines
            UNION ALL SELECT MAX(uploaded_at) AS ts FROM downloadable_forms
            UNION ALL SELECT MAX(uploaded_at) AS ts FROM gallery
            UNION ALL SELECT MAX(uploaded_at) AS ts FROM annual_reports
            UNION ALL SELECT MAX(submitted_at) AS ts FROM admissions
        ) AS all_updates"
    );
    $lastUpdated = $setting && $setting['last_updated'] ? $setting['last_updated'] : date('Y-m-d H:i:s');
    $dt = new DateTime($lastUpdated, new DateTimeZone('Asia/Dhaka'));
    Response::success([
        'last_updated' => $dt->format('Y-m-d H:i:s'),
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
