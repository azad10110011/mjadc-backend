<?php

// GET /api/admin/id-cards?type=students|teachers|staff[&id=]   (Session 77)
// Restored from the June live snapshot (mjadc.ac.bd/mjadc-api) — the Admin → ID Cards page calls it,
// but the file was missing from this backend (404 locally and on live).
// Fix vs snapshot: the single-row lookup passed ['id' => $id] to a positional "?" placeholder.

$router->get('/api/admin/id-cards', function () {
    Auth::requireRole('admin');
    $type = $_GET['type'] ?? '';
    $id = isset($_GET['id']) ? (int)$_GET['id'] : null;

    $allowedTypes = ['students', 'teachers', 'staff'];
    if (!in_array($type, $allowedTypes, true)) {
        Response::validationError(['Invalid type. Must be students, teachers, or staff']);
    }

    $sql = match ($type) {
        'students' => "SELECT id, student_id, name, father_name, mother_name, class, section, student_group, class_roll, photo_path, academic_session, gender, mobile, parent_mobile, address, present_address, permanent_address, date_of_birth, blood_group FROM students",
        'teachers' => "SELECT id, name, name_bangla, designation, subject, `group` as teacher_group, joining_date, photo_path, mobile, email, gender, blood_group, emergency_contact FROM teachers",
        'staff' => "SELECT id, name, name_bangla, designation, joining_date, photo_path, mobile, email, gender, blood_group, emergency_contact FROM staff",
    };

    // Attach a per-person verify token (HMAC) and effective expiry date (helpers from card-templates.php).
    $expiryMap = function_exists('cardExpiryMap') ? cardExpiryMap($type) : ['default' => null, 'overrides' => []];
    $withToken = function ($row) use ($type, $expiryMap) {
        if ($row && function_exists('cardVerifyToken')) {
            $row['verify_token'] = cardVerifyToken($type, (int)$row['id']);
            $pid = (int)$row['id'];
            $row['expire_date'] = array_key_exists($pid, $expiryMap['overrides']) ? $expiryMap['overrides'][$pid] : $expiryMap['default'];
        }
        return $row;
    };

    if ($id) {
        $row = Database::fetch("$sql WHERE id = ?", [$id]);
        Response::success($row ? $withToken($row) : null);
    } else {
        Response::success(array_map($withToken, Database::fetchAll("$sql ORDER BY name")));
    }
});
