<?php

// POST /api/principal/staff
$router->post('/api/principal/staff', function () {
    $user = Auth::requireRole('principal');

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
    ]);

    $role = $data['role'] ?? 'administration';
    Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);

    $staffId = Database::insert('staff', [
        'user_id' => $userId,
        'name' => $data['name'],
        'gender' => $data['gender'],
        'designation' => $data['designation'],
        'subject' => $data['subject'] ?? null,
        'joining_date' => $data['joining_date'],
        'mobile' => $data['mobile'],
        'email' => $data['email'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
    ]);

    Response::created(['id' => $staffId], 'Staff added successfully');
});

// GET /api/principal/staff
$router->get('/api/principal/staff', function () {
    Auth::requireRole('principal');

    $staff = Database::fetchAll(
        "SELECT id, name, designation, subject, joining_date, mobile, email, photo_path 
         FROM staff ORDER BY name"
    );
    Response::success($staff);
});
