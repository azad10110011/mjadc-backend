<?php

// GET /api/admin-panel/students
$router->get('/api/admin-panel/students', function () {
    Auth::requireRole('administration');
    $students = Database::fetchAll(
        "SELECT s.*, u.status as user_status, u.id as user_id
         FROM students s
         LEFT JOIN users u ON s.user_id = u.id
         ORDER BY s.created_at DESC"
    );
    foreach ($students as &$s) {
        $s['compulsory_subjects'] = $s['compulsory_subjects'] ? json_decode($s['compulsory_subjects'], true) : [];
        $s['selective_subjects'] = $s['selective_subjects'] ? json_decode($s['selective_subjects'], true) : [];
        if (is_string($s['selective_subjects'])) {
            $s['selective_subjects'] = json_decode($s['selective_subjects'], true) ?? [];
        }
        if (is_string($s['compulsory_subjects'])) {
            $s['compulsory_subjects'] = json_decode($s['compulsory_subjects'], true) ?? [];
        }
    }
    Response::success($students);
});

// POST /api/admin-panel/students
$router->post('/api/admin-panel/students', function () {
    try {
        $user = Auth::requireRole('administration');

        $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
        $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

        if ($isMultipart) {
            $data = $_POST;
            $photoPath = null;
            if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
                $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
                $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
                if (in_array($ext, $allowedExts)) {
                    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['photo']['name']);
                    $destDir = __DIR__ . '/../../uploads/profiles';
                    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                    move_uploaded_file($_FILES['photo']['tmp_name'], $destDir . '/' . $filename);
                    $photoPath = "uploads/profiles/{$filename}";
                }
            }
            $data['photo_path'] = $photoPath;
        } else {
            $data = json_decode(file_get_contents('php://input'), true);
        }

        $validator = validate($data);
        $validator->required('name', 'Name')
            ->required('student_id', 'Student ID')
            ->required('mobile', 'Mobile')
            ->required('class', 'Class')
            ->required('gender', 'Gender');

        if (!$validator->passes()) {
            Response::validationError($validator->errors());
        }

        // Check duplicate student_id
        $existing = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
        if ($existing) {
            Response::error('Student ID already exists', 409);
        }

        // Check duplicate email in users
        $existingUser = Database::fetch("SELECT id FROM users WHERE email = ?", [$data['student_id']]);
        if ($existingUser) {
            Response::error('A user with this Student ID already exists', 409);
        }

        // Create user account with student role
        $userId = Database::insert('users', [
            'name' => $data['name'],
            'email' => $data['student_id'],
            'password_hash' => Auth::hashPassword($data['mobile']),
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
        ]);
        Database::insert('user_roles', ['user_id' => $userId, 'role' => 'student']);

        // Create student record
        $studentId = Database::insert('students', [
            'user_id' => $userId,
            'student_id' => $data['student_id'],
            'name' => $data['name'],
            'father_name' => $data['father_name'] ?? null,
            'mother_name' => $data['mother_name'] ?? null,
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'class' => $data['class'],
            'section' => $data['section'] ?? null,
            'joining_year' => $data['joining_year'] ?? date('Y'),
            'mobile' => $data['mobile'],
            'email' => $data['email'] ?? null,
            'gender' => $data['gender'],
            'parent_mobile' => $data['parent_mobile'] ?? null,
            'whatsapp' => $data['whatsapp'] ?? null,
            'address' => $data['present_address'] ?? null,
            'present_address' => $data['present_address'] ?? null,
            'permanent_address' => $data['permanent_address'] ?? null,
            'student_group' => $data['student_group'] ?? null,
            'compulsory_subjects' => isset($data['compulsory_subjects']) ? (is_array($data['compulsory_subjects']) ? json_encode($data['compulsory_subjects']) : $data['compulsory_subjects']) : null,
            'selective_subjects' => isset($data['selective_subjects']) ? (is_array($data['selective_subjects']) ? json_encode($data['selective_subjects']) : $data['selective_subjects']) : null,
            'photo_path' => $data['photo_path'] ?? null,
        ]);

        Response::created(['id' => $studentId, 'user_id' => $userId], 'Student created');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});

// POST /api/admin-panel/students/{id} (update)
$router->post('/api/admin-panel/students/{id}', function (array $params) {
    Auth::requireRole('administration');

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = strpos($contentType, 'multipart/form-data') !== false;

    if ($isMultipart) {
        $data = $_POST;
        if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            $allowedExts = ['jpg', 'jpeg', 'png', 'gif'];
            if (in_array($ext, $allowedExts)) {
                $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['photo']['name']);
                $destDir = __DIR__ . '/../../uploads/profiles';
                if (!is_dir($destDir)) mkdir($destDir, 0755, true);
                move_uploaded_file($_FILES['photo']['tmp_name'], $destDir . '/' . $filename);
                $data['photo_path'] = "uploads/profiles/{$filename}";
            }
        }
    } else {
        $data = json_decode(file_get_contents('php://input'), true);
    }

    $updateData = [];
    foreach (['name', 'father_name', 'mother_name', 'date_of_birth', 'class', 'section',
              'joining_year', 'mobile', 'email', 'gender', 'parent_mobile', 'whatsapp',
              'student_group', 'photo_path'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (isset($data['compulsory_subjects'])) {
        $updateData['compulsory_subjects'] = is_array($data['compulsory_subjects']) ? json_encode($data['compulsory_subjects']) : $data['compulsory_subjects'];
    }
    if (isset($data['selective_subjects'])) {
        $updateData['selective_subjects'] = is_array($data['selective_subjects']) ? json_encode($data['selective_subjects']) : $data['selective_subjects'];
    }
    if (isset($data['present_address'])) {
        $updateData['present_address'] = $data['present_address'];
        $updateData['address'] = $data['present_address'];
    }
    if (isset($data['permanent_address'])) {
        $updateData['permanent_address'] = $data['permanent_address'];
    }

    if (!empty($updateData)) {
        Database::update('students', $updateData, 'id = ?', ['id' => $params['id']]);
    }

    Response::success(null, 'Student updated');
});

// DELETE /api/admin-panel/students/{id}
$router->delete('/api/admin-panel/students/{id}', function (array $params) {
    Auth::requireRole('administration');
    $student = Database::fetch("SELECT user_id FROM students WHERE id = ?", [$params['id']]);
    if ($student && $student['user_id']) {
        Database::delete('users', 'id = ?', [$student['user_id']]);
    }
    Database::delete('students', 'id = ?', [$params['id']]);
    Response::success(null, 'Student deleted');
});
