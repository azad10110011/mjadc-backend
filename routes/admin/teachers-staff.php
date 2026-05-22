<?php

// GET /api/admin/teachers-staff/teachers
$router->get('/api/admin/teachers-staff/teachers', function () {
    Auth::requireRole('admin');
    $teachers = Database::fetchAll("SELECT t.id, t.name, t.designation, t.subject, t.email, t.mobile, t.photo_path, t.joining_date, t.gender, u.date_of_birth, u.status as user_status, t.user_id FROM teachers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.name");
    Response::success($teachers);
});

// GET /api/admin/teachers-staff/staff
$router->get('/api/admin/teachers-staff/staff', function () {
    Auth::requireRole('admin');
    $staff = Database::fetchAll("SELECT s.id, s.name, s.designation, s.subject, s.email, s.mobile, s.photo_path, s.joining_date, s.gender, u.date_of_birth, u.status as user_status, s.user_id FROM staff s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.name");
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

        $teacherId = Database::insert('teachers', [
            'user_id' => $userId, 'name' => $data['name'], 'gender' => $data['gender'],
            'designation' => $data['designation'], 'subject' => $data['subject'] ?? null,
            'joining_date' => $data['joining_date'], 'mobile' => $data['mobile'],
            'email' => $data['email'], 'photo_path' => $data['photo_path'] ?? null,
        ]);

        Response::created(['id' => $teacherId], 'Teacher added');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// PUT /api/admin/teachers-staff/teacher/{id}
$router->put('/api/admin/teachers-staff/teacher/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $updateData = [];
    foreach (['name', 'designation', 'subject', 'mobile', 'email', 'joining_date', 'gender', 'photo_path'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (!empty($updateData)) {
        Database::update('teachers', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    if (isset($data['date_of_birth'])) {
        $teacher = Database::fetch("SELECT user_id FROM teachers WHERE id = ?", [$params['id']]);
        if ($teacher) {
            Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $teacher['user_id']]);
        }
    }
    Response::success(null, 'Teacher updated');
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

        $staffId = Database::insert('staff', [
            'user_id' => $userId, 'name' => $data['name'], 'gender' => $data['gender'],
            'designation' => $data['designation'], 'subject' => $data['subject'] ?? null,
            'joining_date' => $data['joining_date'], 'mobile' => $data['mobile'],
            'email' => $data['email'] ?? null, 'photo_path' => $data['photo_path'] ?? null,
        ]);

        Response::created(['id' => $staffId], 'Staff added');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// PUT /api/admin/teachers-staff/staff/{id}
$router->put('/api/admin/teachers-staff/staff/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $updateData = [];
    foreach (['name', 'designation', 'subject', 'mobile', 'email', 'joining_date', 'gender', 'photo_path'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (!empty($updateData)) {
        Database::update('staff', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    if (isset($data['date_of_birth'])) {
        $record = Database::fetch("SELECT user_id FROM staff WHERE id = ?", [$params['id']]);
        if ($record) {
            Database::update('users', ['date_of_birth' => $data['date_of_birth']], 'id = ?', ['id' => $record['user_id']]);
        }
    }
    Response::success(null, 'Staff updated');
});

// DELETE /api/admin/teachers-staff/staff/{id}
$router->delete('/api/admin/teachers-staff/staff/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('staff', 'id = ?', [$params['id']]);
    Response::success(null, 'Staff deleted');
});
