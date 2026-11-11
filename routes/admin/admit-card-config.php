<?php
// GET /api/admin/admit-card-config - current admit card configuration
$router->get('/api/admin/admit-card-config', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $row = Database::fetch(
        "SELECT setting_value FROM site_settings WHERE setting_key = 'admit_card_config'"
    );
    if ($row) {
        $decoded = json_decode($row['setting_value'], true);
        if (is_array($decoded)) {
            Response::success($decoded);
        }
    }
    Response::success(null);
});

// PUT /api/admin/admit-card-config - save admit card configuration
$router->put('/api/admin/admit-card-config', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    if (!isset($data['fields']) || !is_array($data['fields'])) {
        Response::validationError(['fields array is required']);
    }
    $config = [
        'fields' => array_values(array_map('strval', $data['fields'])),
        'per_page' => max(1, min(2, (int)($data['per_page'] ?? 2))),
        'photo' => !empty($data['photo']),
        'signatures' => !empty($data['signatures']),
        'college_name' => (string)($data['college_name'] ?? 'Miah Jinnah Alam Degree College'),
        'college_address' => (string)($data['college_address'] ?? 'Garaganj, Shailkupa, Jhenaidah'),
    ];
    $json = json_encode($config, JSON_UNESCAPED_UNICODE);
    $existing = Database::fetch("SELECT id FROM site_settings WHERE setting_key = 'admit_card_config'");
    if ($existing) {
        Database::update('site_settings', [
            'setting_value' => $json,
            'updated_by' => $user['id'],
        ], 'setting_key = ?', ['setting_key' => 'admit_card_config']);
    } else {
        Database::insert('site_settings', [
            'setting_key' => 'admit_card_config',
            'setting_value' => $json,
            'updated_by' => $user['id'],
        ]);
    }
    Response::success($config, 'Admit card configuration saved');
});
