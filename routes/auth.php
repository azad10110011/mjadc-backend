<?php

// POST /api/auth/login
$router->post('/api/auth/login', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('email', 'Email')->required('password', 'Password');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $user = Database::fetch(
        "SELECT u.*, GROUP_CONCAT(ur.role) as roles 
         FROM users u 
         LEFT JOIN user_roles ur ON u.id = ur.user_id 
         WHERE u.email = ? AND u.status = 'active' 
         GROUP BY u.id",
        [$data['email']]
    );

    if (!$user || !Auth::verifyPassword($data['password'], $user['password_hash'])) {
        Response::unauthorized('Invalid email or password');
    }

    $roles = $user['roles'] ? explode(',', $user['roles']) : [];
    $token = JWTHandler::generateToken((int)$user['id'], $user['email'], $roles);

    unset($user['password_hash']);
    $user['roles'] = $roles;

    Response::success([
        'token' => $token,
        'user' => $user,
    ], 'Login successful');
});

// GET /api/auth/me
$router->get('/api/auth/me', function () {
    $user = Auth::requireAuth();
    unset($user['password_hash']);
    Response::success($user);
});

// POST /api/auth/logout
$router->post('/api/auth/logout', function () {
    Response::success(null, 'Logged out successfully');
});

// POST /api/auth/change-password
$router->post('/api/auth/change-password', function () {
    $user = Auth::requireAuth();

    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('current_password', 'Current password')
        ->required('new_password', 'New password');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $dbUser = Database::fetch("SELECT id, password_hash FROM users WHERE id = ?", [$user['id']]);
    if (!$dbUser || !Auth::verifyPassword($data['current_password'], $dbUser['password_hash'])) {
        Response::error('Current password is incorrect', 401);
    }

    Database::update('users', [
        'password_hash' => Auth::hashPassword($data['new_password'])
    ], 'id = ?', ['id' => $user['id']]);

    Response::success(null, 'Password changed successfully');
});

// POST /api/auth/reset-password
$router->post('/api/auth/reset-password', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    validate($data)->required('email', 'Email')->validate();

    $user = Database::fetch("SELECT id FROM users WHERE email = ?", [$data['email']]);
    if (!$user) {
        Response::success(null, 'If the email exists, a reset link has been sent');
    }

    // In production: send email with reset token
    Response::success(null, 'If the email exists, a reset link has been sent');
});
