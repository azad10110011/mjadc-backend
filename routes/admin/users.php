<?php

// GET /api/admin/users
$router->get('/api/admin/users', function () {
    Auth::requireRole('admin');

    $users = Database::fetchAll(
        "SELECT u.id, u.name, u.email, u.gender, u.date_of_birth, u.default_role, u.status, u.created_at,
                GROUP_CONCAT(DISTINCT ur.role) as roles
         FROM users u
         LEFT JOIN user_roles ur ON u.id = ur.user_id
         GROUP BY u.id
         ORDER BY u.created_at DESC"
    );

    foreach ($users as &$u) {
        $u['roles'] = $u['roles'] ? explode(',', $u['roles']) : [];
        // Load teacher subjects if user has teacher role
        if (in_array('teacher', $u['roles'])) {
            $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$u['id']]);
            if ($teacher) {
                $rows = Database::fetchAll(
                    "SELECT subject, type FROM teacher_subjects WHERE teacher_id = ?",
                    [$teacher['id']]
                );
                $u['subjects'] = [];
                $u['result_subjects'] = [];
                foreach ($rows as $r) {
                    if ($r['type'] === 'result') {
                        $u['result_subjects'][] = $r['subject'];
                    } else {
                        $u['subjects'][] = $r['subject'];
                    }
                }
            } else {
                $u['subjects'] = [];
                $u['result_subjects'] = [];
            }
        }
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
        'default_role' => $data['default_role'] ?? null,
    ]);

    if (isset($data['roles']) && is_array($data['roles'])) {
        foreach ($data['roles'] as $role) {
            Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }
    }

    // Handle teacher subjects
    if (in_array('teacher', $data['roles'] ?? []) && (isset($data['subjects']) || isset($data['result_subjects']))) {
        $teacherId = Database::insert('teachers', [
            'user_id' => $userId,
            'name' => $data['name'],
            'gender' => $data['gender'] ?? 'male',
            'designation' => $data['designation'] ?? 'Teacher',
            'joining_date' => $data['joining_date'] ?? date('Y-m-d'),
            'mobile' => $data['mobile'] ?? null,
            'email' => $data['email'],
            'subject' => ($data['subjects'] ?? [])[0] ?? null,
        ]);
        foreach (($data['subjects'] ?? []) as $sub) {
            Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'public']);
        }
        foreach (($data['result_subjects'] ?? []) as $sub) {
            Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'result']);
        }
    }

    Response::created(['id' => $userId], 'User created');
});

// PUT /api/admin/users/{id}
$router->put('/api/admin/users/{id}', function (array $params) {
    Auth::requireRole('admin');

    try {
        $data = json_decode(file_get_contents('php://input'), true);

        // Check duplicate email (excluding current user)
        if (isset($data['email'])) {
            $existing = Database::fetch("SELECT id FROM users WHERE email = ? AND id != ?", [$data['email'], $params['id']]);
            if ($existing) {
                Response::error('Email already exists', 409);
            }
        }

        // Update basic info
        $updateData = [];
        foreach (['name', 'email', 'gender', 'status', 'date_of_birth', 'default_role'] as $f) {
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

        // Handle teacher subjects
        if (in_array('teacher', $data['roles'] ?? [])) {
            $teacher = Database::fetch("SELECT id FROM teachers WHERE user_id = ?", [$params['id']]);
            if (!$teacher) {
                // Create teacher record if not exists
                $teacherId = Database::insert('teachers', [
                    'user_id' => $params['id'],
                    'name' => $data['name'] ?? '',
                    'gender' => $data['gender'] ?? 'male',
                    'designation' => $data['designation'] ?? 'Teacher',
                    'joining_date' => $data['joining_date'] ?? date('Y-m-d'),
                    'mobile' => $data['mobile'] ?? null,
                    'email' => $data['email'] ?? '',
                ]);
            } else {
                $teacherId = $teacher['id'];
            }

            if (isset($data['subjects']) || isset($data['result_subjects'])) {
                Database::delete('teacher_subjects', 'teacher_id = ?', [$teacherId]);
                foreach (($data['subjects'] ?? []) as $sub) {
                    Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'public']);
                }
                foreach (($data['result_subjects'] ?? []) as $sub) {
                    Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'result']);
                }
                // Sync primary subject into teachers.subject
                $firstPublic = ($data['subjects'] ?? [])[0] ?? null;
                Database::update('teachers', ['subject' => $firstPublic], 'id = ?', ['id' => $teacherId]);
            }
        }

        Response::success(null, 'User updated');
    } catch (\Throwable $e) {
        Response::error('Failed to update user: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/users/{id}
$router->delete('/api/admin/users/{id}', function (array $params) {
    Auth::requireRole('admin');

    $userId = $params['id'];
    if ($userId == 1) {
        Response::forbidden('Cannot delete the primary admin account');
        return;
    }

    // Null out or remove all FK references before deleting user

    // Tables with nullable FK — set to NULL
    Database::query("UPDATE teachers SET user_id = NULL WHERE user_id = ?", [$userId]);
    Database::query("UPDATE staff SET user_id = NULL WHERE user_id = ?", [$userId]);
    Database::query("UPDATE students SET user_id = NULL WHERE user_id = ?", [$userId]);
    Database::query("UPDATE exam_results SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
    Database::query("UPDATE exam_results SET approved_by = NULL WHERE approved_by = ?", [$userId]);
    Database::query("UPDATE leave_applications SET reviewed_by = NULL WHERE reviewed_by = ?", [$userId]);
    Database::query("UPDATE syllabus SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
    Database::query("UPDATE routines SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
    Database::query("UPDATE downloadable_forms SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
    Database::query("UPDATE gallery SET uploaded_by = NULL WHERE uploaded_by = ?", [$userId]);
    Database::query("UPDATE events SET created_by = NULL WHERE created_by = ?", [$userId]);
    Database::query("UPDATE site_settings SET updated_by = NULL WHERE updated_by = ?", [$userId]);
    Database::query("UPDATE page_content SET updated_by = NULL WHERE updated_by = ?", [$userId]);
    Database::query("UPDATE notifications SET user_id = NULL WHERE user_id = ?", [$userId]);

    // Tables with non-nullable FK — delete dependent records
    Database::delete('leave_applications', 'applicant_id = ?', [$userId]);
    Database::delete('leave_taken', 'user_id = ?', [$userId]);

    // Reassign notices to admin (user 1) instead of deleting
    Database::query("UPDATE notices SET created_by = 1 WHERE created_by = ?", [$userId]);

    // Delete user (cascades to user_roles)
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
