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
        'date_of_birth' => $data['date_of_birth'] ?? null,
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
        'name_bangla' => $data['name_bangla'] ?? null,
        'name_english' => $data['name_english'] ?? null,
        'first_mpo_date' => $data['first_mpo_date'] ?? null,
        'nid_number' => $data['nid_number'] ?? null,
        'whatsapp_number' => $data['whatsapp_number'] ?? null,
        'present_address' => $data['present_address'] ?? null,
        'permanent_address' => $data['permanent_address'] ?? null,
    ]);

    Response::created(['id' => $staffId], 'Staff added successfully');
});

// GET /api/principal/staff
$router->get('/api/principal/staff', function () {
    Auth::requireRole('principal');

    $staff = Database::fetchAll(
        "SELECT s.id, s.name, s.name_bangla, s.name_english, s.designation, s.subject, s.joining_date, s.first_mpo_date, s.nid_number, s.mobile, s.whatsapp_number, s.email, s.photo_path, s.present_address, s.permanent_address, u.date_of_birth
         FROM staff s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.name"
    );
    Response::success($staff);
});
