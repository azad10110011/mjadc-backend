<?php

$router->get('/api/student-info', function () {
    $setting = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'student_info'");
    $data = $setting ? json_decode($setting['setting_value'], true) : ['headings' => [], 'rows' => []];
    Response::success($data);
});
