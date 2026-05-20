<?php

// GET /api/admin/users
$router->get('/api/admin/users', function () {
    Auth::requireRole('admin');

    $users = Database::fetchAll(
        "SELECT u.id, u.name, u.email, u.gender, u.date_of_birth, u.status, u.created_at,
                GROUP_CONCAT(ur.role) as roles
         FROM users u
         LEFT JOIN user_roles ur ON u.id = ur.user_id
         GROUP BY u.id
         ORDER BY u.created_at DESC"
    );

    foreach ($users as &$u) {
        $u['roles'] = $u['roles'] ? explode(',', $u['roles']) : [];
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

    // Check duplicate email
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

    Response::created(['id' => $userId], 'User created');
});

// PUT /api/admin/users/{id}
$router->put('/api/admin/users/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);

    // Update basic info
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

    // Update roles
    if (isset($data['roles']) && is_array($data['roles'])) {
        Database::delete('user_roles', 'user_id = ?', [$params['id']]);
        foreach ($data['roles'] as $role) {
            Database::insert('user_roles', ['user_id' => $params['id'], 'role' => $role]);
        }
    }

    Response::success(null, 'User updated');
});

// DELETE /api/admin/users/{id}
$router->delete('/api/admin/users/{id}', function (array $params) {
    Auth::requireRole('admin');

    $userId = $params['id'];
    if ($userId == 1) {
        Response::forbidden('Cannot delete the primary admin account');
    }

    Database::delete('users', 'id = ?', [$userId]);
    Response::success(null, 'User deleted');
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
        $password = bin2hex(random_bytes(4)); // 8 char random
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
