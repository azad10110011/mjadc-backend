<?php

// GET /api/admin/users
$router->get('/api/admin/users', function () {
    Auth::requireRole('admin');

    $rolesFilter = isset($_GET['roles']) ? explode(',', $_GET['roles']) : [];

    $sql = "SELECT u.id, u.name, u.email, u.gender, u.date_of_birth, u.status, u.created_at,
                   GROUP_CONCAT(ur.role) as roles
            FROM users u
            LEFT JOIN user_roles ur ON u.id = ur.user_id";

    if (!empty($rolesFilter)) {
        $placeholders = implode(',', array_fill(0, count($rolesFilter), '?'));
        $sql .= " WHERE u.id IN (
                    SELECT user_id FROM user_roles WHERE role IN ($placeholders)
                 )";
    }

    $sql .= " GROUP BY u.id ORDER BY u.created_at DESC";

    $users = !empty($rolesFilter)
        ? Database::fetchAll($sql, $rolesFilter)
        : Database::fetchAll($sql);

    foreach ($users as &$u) {
        $u['roles'] = $u['roles'] ? explode(',', $u['roles']) : [];

        $u['subjects'] = in_array('teacher', $u['roles'])
            ? getTeacherSubjects((int)$u['id'])
            : [];
    }

    Response::success($users);
});

// POST /api/admin/users
$router->post('/api/admin/users', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('name', 'Name')
        ->required('email', 'Email')
        ->required('password', 'Password')
        ->email('email');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $existing = Database::fetch("SELECT id FROM users WHERE email = ?", [$data['email']]);
    if ($existing) {
        Response::error('Email already exists', 409);
    }

    $userId = Database::insert('users', [
        'name' => $data['name'],
        'email' => $data['email'],
        'password_hash' => Auth::hashPassword($data['password']),
        'gender' => $data['gender'] ?? 'male',
        'date_of_birth' => $data['date_of_birth'] ?? null,
    ]);

    if (isset($data['roles']) && is_array($data['roles'])) {
        foreach ($data['roles'] as $role) {
            Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }
    }

    if (in_array('teacher', $data['roles'] ?? [])) {
        ensureTeacherRecord($userId, $data);
        if (!empty($data['subjects'])) {
            setTeacherSubjects($userId, $data['subjects']);
        }
    }

    Response::created(['id' => $userId], 'User created');
});

// PUT /api/admin/users/{id}
$router->put('/api/admin/users/{id}', function (array $params) {
    Auth::requireRole('admin');

    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if (!is_array($data)) {
        Response::error('Invalid or empty request body — JSON expected', 400);
    }

    $updateData = [];
    foreach (['name', 'email', 'gender', 'status', 'date_of_birth'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (isset($data['password']) && !empty($data['password'])) {
        $updateData['password_hash'] = Auth::hashPassword($data['password']);
    }

    if (!empty($updateData)) {
        Database::update('users', $updateData, 'id = ?', ['id' => $params['id']]);
    }

    if (isset($data['roles']) && is_array($data['roles'])) {
        Database::delete('user_roles', 'user_id = ?', [$params['id']]);
        foreach ($data['roles'] as $role) {
            Database::insert('user_roles', ['user_id' => $params['id'], 'role' => $role]);
        }
    }

    if (isset($data['subjects']) && is_array($data['subjects'])) {
        ensureTeacherRecord((int)$params['id'], $data);
        setTeacherSubjects((int)$params['id'], $data['subjects']);
    }

    Response::success(null, 'User updated');
});

// DELETE /api/admin/users/{id}
$router->delete('/api/admin/users/{id}', function (array $params) {
    $currentUser = Auth::requireRole('admin');

    $userId = (int)$params['id'];
    if ($userId == 1) {
        Response::forbidden('Cannot delete the primary admin account');
    }
    if ($userId == $currentUser['id']) {
        Response::forbidden('Cannot delete yourself');
    }

    $pdo = Database::getInstance();
    try {
        $pdo->beginTransaction();

        // Set NULL on all nullable FK references to this user
        Database::query("UPDATE exam_results SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
        Database::query("UPDATE exam_results SET approved_by = NULL WHERE approved_by = ?", [$userId]);
        Database::query("UPDATE syllabus SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
        Database::query("UPDATE routines SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
        Database::query("UPDATE downloadable_forms SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
        Database::query("UPDATE gallery SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
        Database::query("UPDATE events SET created_by = NULL WHERE created_by = ?", [$userId]);
        Database::query("UPDATE site_settings SET updated_by = NULL WHERE updated_by = ?", [$userId]);
        Database::query("UPDATE page_content SET updated_by = NULL WHERE updated_by = ?", [$userId]);
        Database::query("UPDATE notifications SET user_id = NULL WHERE user_id = ?", [$userId]);
        Database::query("UPDATE leave_applications SET reviewed_by = NULL WHERE reviewed_by = ?", [$userId]);

        // Delete from tables where FK is NOT NULL
        Database::query("DELETE FROM result_changelog WHERE user_id = ?", [$userId]);
        Database::query("DELETE FROM leave_applications WHERE applicant_id = ?", [$userId]);
        Database::query("UPDATE notices SET created_by = 1 WHERE created_by = ?", [$userId]);

        // user_roles has ON DELETE CASCADE, but delete explicitly
        Database::query("DELETE FROM user_roles WHERE user_id = ?", [$userId]);

        // students, teachers, staff have ON DELETE SET NULL — handled automatically

        Database::query("DELETE FROM users WHERE id = ?", [$userId]);

        $pdo->commit();
        Response::success(null, 'User deleted');
    } catch (\Exception $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        Response::error('Failed to delete user: ' . $e->getMessage(), 500);
    }
});

// POST /api/admin/users/{id}/freeze
$router->post('/api/admin/users/{id}/freeze', function (array $params) {
    Auth::requireRole('admin');
    Database::update('users', ['status' => 'frozen'], 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'User frozen');
});

// POST /api/admin/users/{id}/unfreeze
$router->post('/api/admin/users/{id}/unfreeze', function (array $params) {
    Auth::requireRole('admin');
    Database::update('users', ['status' => 'active'], 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'User unfrozen');
});

// POST /api/admin/users/{id}/reset-password
$router->post('/api/admin/users/{id}/reset-password', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $password = $data['password'] ?? null;
    if (!$password || strlen($password) < 4) {
        $password = bin2hex(random_bytes(4));
    }

    Database::update('users', [
        'password_hash' => Auth::hashPassword($password)
    ], 'id = ?', ['id' => $params['id']]);

    $user = Database::fetch("SELECT id, name, email FROM users WHERE id = ?", [$params['id']]);

    Response::success([
        'id' => $user['id'],
        'name' => $user['name'],
        'email' => $user['email'],
        'new_password' => $password,
    ], 'Password reset successfully');
});
