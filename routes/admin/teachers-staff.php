<?php

// GET /api/admin/teachers-staff/teachers
$router->get('/api/admin/teachers-staff/teachers', function () {
    Auth::requireRole('admin');
    $teachers = Database::fetchAll("SELECT t.id, t.name, t.name_bangla, t.name_english, t.designation, t.subject, t.`group`, t.email, t.mobile, t.whatsapp_number, t.photo_path, t.joining_date, t.first_mpo_date, t.nid_number, t.present_address, t.permanent_address, t.gender, u.date_of_birth FROM teachers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.sort_order, t.name");
    $now = new DateTime();
    foreach ($teachers as &$t) {
        $t['experience'] = $t['first_mpo_date'] ? $now->diff(new DateTime($t['first_mpo_date']))->format('%y years, %m months, %d days') : null;
    }
    Response::success($teachers);
});

// GET /api/admin/teachers-staff/staff
$router->get('/api/admin/teachers-staff/staff', function () {
    Auth::requireRole('admin');
    $staff = Database::fetchAll("SELECT s.id, s.name, s.name_bangla, s.name_english, s.designation, s.subject, s.email, s.mobile, s.whatsapp_number, s.photo_path, s.joining_date, s.first_mpo_date, s.nid_number, s.present_address, s.permanent_address, s.gender, u.date_of_birth FROM staff s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.sort_order, s.name");
    $now = new DateTime();
    foreach ($staff as &$s) {
        $s['experience'] = $s['first_mpo_date'] ? $now->diff(new DateTime($s['first_mpo_date']))->format('%y years, %m months, %d days') : null;
    }
    Response::success($staff);
});

// POST /api/admin/teachers-staff/teacher
$router->post('/api/admin/teachers-staff/teacher', function () {
    try {
        $user = Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $validator = validate($data);
        $validator->required('name', 'Name')
            ->required('designation', 'Designation')
            ->required('gender', 'Gender')
            ->required('joining_date', 'Joining Date')
            ->required('mobile', 'Mobile')
            ->required('email', 'Email');
        if (!$validator->passes()) {
            Response::validationError($validator->errors());
        }

        $userId = Database::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => Auth::hashPassword($data['password'] ?? 'password123'),
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
        ]);

        $roles = $data['roles'] ?? ['teacher'];
        foreach ($roles as $role) {
            Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }

        $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM teachers");
        $teacherId = Database::insert('teachers', [
            'user_id' => $userId, 'name' => $data['name'], 'gender' => $data['gender'],
            'designation' => $data['designation'], 'subject' => $data['subject'] ?? null,
            'group' => $data['group'] ?? null,
            'joining_date' => $data['joining_date'], 'mobile' => $data['mobile'],
            'email' => $data['email'], 'photo_path' => $data['photo_path'] ?? null,
            'sort_order' => $maxSort['next'],
            'name_bangla' => $data['name_bangla'] ?? null,
            'name_english' => $data['name_english'] ?? null,
            'first_mpo_date' => $data['first_mpo_date'] ?? null,
            'nid_number' => $data['nid_number'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'present_address' => $data['present_address'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
        ]);

        Response::created(['id' => $teacherId], 'Teacher added');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// PUT /api/admin/teachers-staff/teacher/{id}
$router->put('/api/admin/teachers-staff/teacher/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        foreach (['name', 'name_bangla', 'name_english', 'designation', 'subject', 'group', 'mobile', 'email', 'joining_date', 'first_mpo_date', 'nid_number', 'whatsapp_number', 'present_address', 'permanent_address', 'gender', 'photo_path'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (!empty($updateData)) {
            Database::update('teachers', $updateData, 'id = ?', ['id' => $params['id']]);
        }
        if (isset($data['date_of_birth'])) {
            $teacher = Database::fetch("SELECT * FROM teachers WHERE id = ?", [$params['id']]);
            if ($teacher) {
                $userId = $teacher['user_id'];
                if ($userId) {
                    $userExists = Database::fetch("SELECT id FROM users WHERE id = ?", [$userId]);
                    if (!$userExists) $userId = null;
                }
                if (!$userId) {
                    $email = $data['email'] ?? $teacher['email'] ?? ('teacher_' . $params['id'] . '@mjadc.ac.bd');
                    $existingUser = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
                    if ($existingUser) {
                        $userId = $existingUser['id'];
                        Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $userId]);
                    } else {
                        $userId = Database::insert('users', [
                            'name' => $data['name'] ?? $teacher['name'],
                            'email' => $email,
                            'password_hash' => Auth::hashPassword('password123'),
                            'gender' => $data['gender'] ?? $teacher['gender'],
                            'date_of_birth' => $data['date_of_birth'],
                        ]);
                    }
                    Database::update('teachers', ['user_id' => $userId], 'id = ?', ['id' => $params['id']]);
                } else {
                    Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $userId]);
                }
            }
        }
        Response::success(null, 'Teacher updated');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/teachers-staff/teacher/{id}
$router->delete('/api/admin/teachers-staff/teacher/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('teachers', 'id = ?', [$params['id']]);
    Response::success(null, 'Teacher deleted');
});

// POST /api/admin/teachers-staff/staff
$router->post('/api/admin/teachers-staff/staff', function () {
    try {
        $user = Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $validator = validate($data);
        $validator->required('name', 'Name')
            ->required('designation', 'Designation')
            ->required('gender', 'Gender')
            ->required('joining_date', 'Joining Date')
            ->required('mobile', 'Mobile');
        if (!$validator->passes()) {
            Response::validationError($validator->errors());
        }

        $userId = Database::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'] ?? ($data['name'] . '@mjadc.ac.bd'),
            'password_hash' => Auth::hashPassword('password123'),
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
        ]);

        $role = $data['role'] ?? 'administration';
        Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);

        $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM staff");
        $staffId = Database::insert('staff', [
            'user_id' => $userId, 'name' => $data['name'], 'gender' => $data['gender'],
            'designation' => $data['designation'], 'subject' => $data['subject'] ?? null,
            'joining_date' => $data['joining_date'], 'mobile' => $data['mobile'],
            'email' => $data['email'] ?? null, 'photo_path' => $data['photo_path'] ?? null,
            'sort_order' => $maxSort['next'],
            'name_bangla' => $data['name_bangla'] ?? null,
            'name_english' => $data['name_english'] ?? null,
            'first_mpo_date' => $data['first_mpo_date'] ?? null,
            'nid_number' => $data['nid_number'] ?? null,
            'whatsapp_number' => $data['whatsapp_number'] ?? null,
            'present_address' => $data['present_address'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
        ]);

        Response::created(['id' => $staffId], 'Staff added');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// PUT /api/admin/teachers-staff/staff/{id}
$router->put('/api/admin/teachers-staff/staff/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        foreach (['name', 'name_bangla', 'name_english', 'designation', 'subject', 'mobile', 'email', 'joining_date', 'first_mpo_date', 'nid_number', 'whatsapp_number', 'present_address', 'permanent_address', 'gender', 'photo_path'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (!empty($updateData)) {
            Database::update('staff', $updateData, 'id = ?', ['id' => $params['id']]);
        }
        if (isset($data['date_of_birth'])) {
            $record = Database::fetch("SELECT * FROM staff WHERE id = ?", [$params['id']]);
            if ($record) {
                $userId = $record['user_id'];
                if ($userId) {
                    $userExists = Database::fetch("SELECT id FROM users WHERE id = ?", [$userId]);
                    if (!$userExists) $userId = null;
                }
                if (!$userId) {
                    $email = $data['email'] ?? $record['email'] ?? ('staff_' . $params['id'] . '@mjadc.ac.bd');
                    $existingUser = Database::fetch("SELECT id FROM users WHERE email = ?", [$email]);
                    if ($existingUser) {
                        $userId = $existingUser['id'];
                        Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $userId]);
                    } else {
                        $userId = Database::insert('users', [
                            'name' => $data['name'] ?? $record['name'],
                            'email' => $email,
                            'password_hash' => Auth::hashPassword('password123'),
                            'gender' => $data['gender'] ?? $record['gender'],
                            'date_of_birth' => $data['date_of_birth'],
                        ]);
                    }
                    Database::update('staff', ['user_id' => $userId], 'id = ?', ['id' => $params['id']]);
                } else {
                    Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $userId]);
                }
            }
        }
        Response::success(null, 'Staff updated');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/teachers-staff/staff/{id}
$router->delete('/api/admin/teachers-staff/staff/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('staff', 'id = ?', [$params['id']]);
    Response::success(null, 'Staff deleted');
});

// POST /api/admin/teachers-staff/teacher/{id}/move-up
$router->post('/api/admin/teachers-staff/teacher/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM teachers WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Teacher not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM teachers WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('teachers', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('teachers', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
});

// POST /api/admin/teachers-staff/teacher/{id}/move-down
$router->post('/api/admin/teachers-staff/teacher/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM teachers WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Teacher not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM teachers WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('teachers', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('teachers', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
});

// POST /api/admin/teachers-staff/staff/{id}/move-up
$router->post('/api/admin/teachers-staff/staff/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM staff WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Staff not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM staff WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('staff', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('staff', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
});

// POST /api/admin/teachers-staff/staff/{id}/move-down
$router->post('/api/admin/teachers-staff/staff/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM staff WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Staff not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM staff WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('staff', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('staff', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
});
