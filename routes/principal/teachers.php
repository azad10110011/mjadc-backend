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
        'date_of_birth' => $data['date_of_birth'] ?? null,
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
        'name_bangla' => $data['name_bangla'] ?? null,
        'name_english' => $data['name_english'] ?? null,
        'first_mpo_date' => $data['first_mpo_date'] ?? null,
        'nid_number' => $data['nid_number'] ?? null,
        'whatsapp_number' => $data['whatsapp_number'] ?? null,
        'present_address' => $data['present_address'] ?? null,
        'permanent_address' => $data['permanent_address'] ?? null,
    ]);

    Response::created(['id' => $teacherId, 'user_id' => $userId], 'Teacher added successfully');
});

// GET /api/principal/teachers
$router->get('/api/principal/teachers', function () {
    Auth::requireRole('principal');

    $teachers = Database::fetchAll(
        "SELECT t.id, t.name, t.name_bangla, t.name_english, t.designation, t.subject, t.joining_date, t.first_mpo_date, t.nid_number, t.mobile, t.whatsapp_number, t.email, t.photo_path, t.present_address, t.permanent_address, u.date_of_birth
         FROM teachers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.name"
    );
    Response::success($teachers);
});
