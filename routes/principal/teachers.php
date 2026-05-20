<?php

// POST /api/principal/teachers
$router->post('/api/principal/teachers', function () {
    $user = Auth::requireRole('principal');

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

    // Create user account
    $tempPassword = Auth::hashPassword('password123');
    $userId = Database::insert('users', [
        'name' => $data['name'],
        'email' => $data['email'],
        'password_hash' => $tempPassword,
        'gender' => $data['gender'],
    ]);

    // Assign roles
    $roles = $data['roles'] ?? ['teacher'];
    foreach ($roles as $role) {
        Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
    }

    // Create teacher record
    $teacherId = Database::insert('teachers', [
        'user_id' => $userId,
        'name' => $data['name'],
        'gender' => $data['gender'],
        'designation' => $data['designation'],
        'subject' => $data['subject'] ?? null,
        'joining_date' => $data['joining_date'],
        'mobile' => $data['mobile'],
        'email' => $data['email'],
        'photo_path' => $data['photo_path'] ?? null,
    ]);

    Response::created(['id' => $teacherId, 'user_id' => $userId], 'Teacher added successfully');
});

// GET /api/principal/teachers
$router->get('/api/principal/teachers', function () {
    Auth::requireRole('principal');

    $teachers = Database::fetchAll(
        "SELECT id, name, designation, subject, joining_date, mobile, email, photo_path 
         FROM teachers ORDER BY name"
    );
    Response::success($teachers);
});
