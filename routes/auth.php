<?php

// POST /api/auth/login
$router->post('/api/auth/login', function () {
    $data = json_decode(file_get_contents('php://input'), true);
    $validator = validate($data);
    $validator->required('email', 'Email')->required('password', 'Password');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $email = (string)$data['email'];
    loginThrottleGuard($email); // Session 112: block while locked out (exits with 429)

    // Session 116: students may be stored as the bare Student ID or as "<id>@student.mjadc.local";
    // accept either form so the student page and the mobile app login both work.
    $candidates = [$email];
    if (preg_match('/^(.+)@student\.mjadc\.local$/i', $email, $mm)) $candidates[] = $mm[1];
    elseif (strpos($email, '@') === false) $candidates[] = $email . '@student.mjadc.local';
    $user = null;
    foreach ($candidates as $cand) {
        $user = Database::fetch(
            "SELECT u.*, GROUP_CONCAT(ur.role) as roles
             FROM users u
             LEFT JOIN user_roles ur ON u.id = ur.user_id
             WHERE u.email = ? AND u.status = 'active'
             GROUP BY u.id",
            [$cand]
        );
        if ($user) break;
    }

    if (!$user) {
        loginThrottleFail($email);
        Response::unauthorized('Invalid email or password');
    }
    if ($user['status'] === 'frozen') {
        Response::unauthorized('Your account is frozen. Contact the administrator.');
    }
    if (!Auth::verifyPassword($data['password'], $user['password_hash'])) {
        loginThrottleFail($email);
        Response::unauthorized('Invalid email or password');
    }

    loginThrottleReset($email); // Session 112: clear the counter on success

    $roles = $user['roles'] ? explode(',', $user['roles']) : [];
    // Session 116: the mobile apps ask for a long-lived session ("client":"app").
    $client = (($data['client'] ?? '') === 'app') ? 'app' : 'web';
    $token = JWTHandler::generateToken((int)$user['id'], $user['email'], $roles, $client, (string)$user['password_hash']);

    unset($user['password_hash']);
    $user['roles'] = $roles;
    $user['must_change_password'] = (int)($user['must_change_password'] ?? 0); // Session 112
    $user['permissions'] = Permissions::effective($user); // Session 80: drives the dynamic sidebar
    $user['permission_keys'] = array_column(Permissions::catalog(), 'key'); // which pages are permission-controlled

    Response::success([
        'token' => $token,
        'user' => $user,
    ], 'Login successful');
});

// GET /api/auth/me
$router->get('/api/auth/me', function () {
    $user = Auth::requireAuth();
    unset($user['password_hash'], $user['_token_client'], $user['_token_iat']);
    $user['must_change_password'] = (int)($user['must_change_password'] ?? 0); // Session 112
    $user['permissions'] = Permissions::effective($user); // Session 80: drives the dynamic sidebar
    $user['permission_keys'] = array_column(Permissions::catalog(), 'key'); // which pages are permission-controlled
    Response::success($user);
});

// POST /api/auth/refresh  (Session 116)
// Returns a fresh token of the same kind. The mobile apps call it now and then, so an app session
// (1 year) keeps renewing and the user is never signed out while they keep using the app.
$router->post('/api/auth/refresh', function () {
    $user = Auth::requireAuth();
    $token = JWTHandler::generateToken(
        (int)$user['id'], (string)$user['email'], (array)$user['roles'],
        (string)$user['_token_client'], (string)$user['password_hash']
    );
    Response::success(['token' => $token], 'Session renewed');
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

    // Session 112: a weak/blank new password is rejected, and the forced-change flag is cleared.
    if (strlen((string)$data['new_password']) < 6) {
        Response::validationError(['new_password' => 'New password must be at least 6 characters']);
    }

    securityEnsureSchema();
    $newHash = Auth::hashPassword($data['new_password']);
    Database::update('users', [
        'password_hash' => $newHash,
        'must_change_password' => 0,
    ], 'id = ?', ['id' => $user['id']]);

    // Session 116: the new password ends every other session; this device gets a fresh token.
    $token = JWTHandler::generateToken((int)$user['id'], (string)$user['email'], (array)$user['roles'], (string)$user['_token_client'], $newHash);
    Response::success(['token' => $token], 'Password changed successfully');
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
