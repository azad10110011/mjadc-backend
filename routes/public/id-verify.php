<?php
// Public ID card verification (added 2026-11-20). Anyone scanning a card's QR lands here — no login.
//   GET /api/public/id-verify?type=students|teachers|staff&id=<dbId>&t=<token>
// Needs routes/admin/card-templates.php loaded first (cardVerifyToken / cardVerifySecret).
// Returns only a safe subset (name, photo, id/designation, class, status) — never mobile/address/etc.

$router->get('/api/public/id-verify', function () {
    $audience = trim($_GET['type'] ?? '');
    $id = (int)($_GET['id'] ?? 0);
    $token = trim($_GET['t'] ?? '');
    if (!in_array($audience, ['students', 'teachers', 'staff'], true) || !$id || $token === '') {
        Response::error('Invalid verification link', 400);
    }
    if (!function_exists('cardVerifyToken') || !hash_equals(cardVerifyToken($audience, $id), $token)) {
        Response::error('Invalid verification link', 404);
    }

    if ($audience === 'students') {
        $p = Database::fetch("SELECT id, user_id, student_id, name, class, section, student_group, academic_session, photo_path, blood_group FROM students WHERE id = ?", [$id]);
    } elseif ($audience === 'teachers') {
        $p = Database::fetch("SELECT id, user_id, name, designation, subject, photo_path, blood_group FROM teachers WHERE id = ?", [$id]);
    } else {
        $p = Database::fetch("SELECT id, user_id, name, designation, photo_path, blood_group FROM staff WHERE id = ?", [$id]);
    }
    if (!$p) {
        Response::success(['verified' => false, 'message' => 'This ID card is not in the college records.']);
    }

    // Live status: a frozen/removed account shows as no longer active, so a scanned old card fails on its own.
    $active = true;
    if (!empty($p['user_id'])) {
        $u = Database::fetch("SELECT status FROM users WHERE id = ?", [(int)$p['user_id']]);
        if ($u && isset($u['status']) && $u['status'] !== 'active') $active = false;
    }

    // Expiry: a card past its "valid till" date is shown as expired.
    $expire = function_exists('cardExpiryFor') ? cardExpiryFor($audience, (int)$p['id']) : null;
    $expired = $expire !== null && $expire < date('Y-m-d');

    $out = [
        'verified' => true,
        'active' => $active,
        'expired' => $expired,
        'expire_date' => $expire,
        'type' => $audience,
        'name' => $p['name'],
        'photo_path' => $p['photo_path'] ?: null,
        'blood_group' => $p['blood_group'] ?? null,
        'college_name' => 'Miah Jinnah Alam Degree College',
        'as_of' => date('Y-m-d'),
    ];
    if ($audience === 'students') {
        $out['identifier'] = $p['student_id'];
        $out['detail'] = trim(($p['class'] ?? '') . ($p['student_group'] ? ' (' . $p['student_group'] . ')' : '') . ($p['section'] ? ' | ' . $p['section'] : ''));
        $out['session'] = $p['academic_session'] ?? null;
        $out['role_label'] = 'Student';
    } else {
        $out['identifier'] = $p['designation'] ?? '';
        $out['detail'] = $audience === 'teachers' ? ($p['subject'] ?? '') : ($p['designation'] ?? '');
        $out['role_label'] = $audience === 'teachers' ? 'Teacher' : 'Staff';
    }
    if (!$active) $out['message'] = 'This card holder is no longer active at the college.';
    elseif ($expired) $out['message'] = 'This ID card expired on ' . $expire . '.';
    Response::success($out);
});
