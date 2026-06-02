<?php

$router->get('/api/student-info', function () {
    $row = Database::fetch(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'student_info'"
    );

    $default = [
        'headings' => [],
        'rows' => [],
    ];

    if ($row && $row['setting_value']) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            Response::success([
                'headings' => $decoded['headings'] ?? [],
                'rows' => $decoded['rows'] ?? [],
            ]);
            return;
        }
    }

    Response::success($default);
});
