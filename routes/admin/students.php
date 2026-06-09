<?php

// GET /api/admin/students
$router->get('/api/admin/students', function () {
    Auth::requireRole('admin');
    $students = Database::fetchAll(
        "SELECT s.*, u.status as user_status, u.id as user_id
         FROM students s
         LEFT JOIN users u ON s.user_id = u.id
         ORDER BY s.sort_order, s.name"
    );
    foreach ($students as &$s) {
        $s['compulsory_subjects'] = $s['compulsory_subjects'] ? json_decode($s['compulsory_subjects'], true) : [];
        $s['selective_subjects'] = $s['selective_subjects'] ? json_decode($s['selective_subjects'], true) : [];
    }
    Response::success($students);
});

// POST /api/admin/students
$router->post('/api/admin/students', function () {
    Auth::requireRole('admin');

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

    $validator = validate($data);
    $validator->required('name', 'Name')
        ->required('student_id', 'Student ID')
        ->required('mobile', 'Mobile')
        ->required('class', 'Class')
        ->required('gender', 'Gender');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $existing = Database::fetch("SELECT id FROM students WHERE student_id = ?", [$data['student_id']]);
    if ($existing) {
        Response::error('Student ID already exists', 409);
    }

    // Create user account
    $userId = Database::insert('users', [
        'name' => $data['name'],
        'email' => $data['student_id'],
        'password_hash' => Auth::hashPassword($data['mobile']),
        'gender' => $data['gender'],
        'date_of_birth' => $data['date_of_birth'] ?? null,
    ]);
    Database::insert('user_roles', ['user_id' => $userId, 'role' => 'student']);

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM students");
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
        'compulsory_subjects' => isset($data['compulsory_subjects']) ? json_encode($data['compulsory_subjects']) : null,
        'selective_subjects' => isset($data['selective_subjects']) ? json_encode($data['selective_subjects']) : null,
        'optional_subject' => $data['optional_subject'] ?? null,
        'photo_path' => $data['photo_path'] ?? null,
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $studentId, 'user_id' => $userId], 'Student created');
});

// POST /api/admin/students/{id} (using POST for multipart support with PHP built-in server)
$router->post('/api/admin/students/{id}', function (array $params) {
    Auth::requireRole('admin');

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
    if (isset($data['optional_subject'])) {
        $updateData['optional_subject'] = $data['optional_subject'];
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

// PUT /api/admin/students/{id}
$router->put('/api/admin/students/{id}', function (array $params) {
    Auth::requireRole('admin');

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
    if (isset($data['optional_subject'])) {
        $updateData['optional_subject'] = $data['optional_subject'];
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

// DELETE /api/admin/students/{id}
$router->delete('/api/admin/students/{id}', function (array $params) {
    Auth::requireRole('admin');
    $student = Database::fetch("SELECT user_id FROM students WHERE id = ?", [$params['id']]);
    $studentId = $params['id'];

    // Delete related records first to avoid FK constraints
    Database::delete('exam_results', 'student_id = ?', [$studentId]);
    Database::delete('tuition_fees', 'student_id = ?', [$studentId]);
    Database::delete('payments', 'student_id = ?', [$studentId]);

    // Delete user account (clean up all FK references first)
    if ($student && $student['user_id']) {
        $uid = $student['user_id'];
        Database::query("UPDATE exam_results SET uploaded_by = NULL WHERE uploaded_by = ?", [$uid]);
        Database::query("UPDATE exam_results SET approved_by = NULL WHERE approved_by = ?", [$uid]);
        Database::query("UPDATE leave_applications SET reviewed_by = NULL WHERE reviewed_by = ?", [$uid]);
        Database::query("UPDATE syllabus SET uploaded_by = NULL WHERE uploaded_by = ?", [$uid]);
        Database::query("UPDATE routines SET uploaded_by = NULL WHERE uploaded_by = ?", [$uid]);
        Database::query("UPDATE downloadable_forms SET uploaded_by = NULL WHERE uploaded_by = ?", [$uid]);
        Database::query("UPDATE gallery SET uploaded_by = NULL WHERE uploaded_by = ?", [$uid]);
        Database::query("UPDATE events SET created_by = NULL WHERE created_by = ?", [$uid]);
        Database::query("UPDATE site_settings SET updated_by = NULL WHERE updated_by = ?", [$uid]);
        Database::query("UPDATE page_content SET updated_by = NULL WHERE updated_by = ?", [$uid]);
        Database::query("UPDATE notifications SET user_id = NULL WHERE user_id = ?", [$uid]);
        Database::delete('leave_applications', 'applicant_id = ?', [$uid]);
        Database::delete('leave_taken', 'user_id = ?', [$uid]);
        Database::query("UPDATE notices SET created_by = 1 WHERE created_by = ?", [$uid]);
        Database::delete('users', 'id = ?', [$uid]);
    }

    Database::delete('students', 'id = ?', [$studentId]);
    Response::success(null, 'Student deleted');
});

// POST /api/admin/students/{id}/move-up
$router->post('/api/admin/students/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM students WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Student not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM students WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('students', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('students', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
});

// POST /api/admin/students/{id}/move-down
$router->post('/api/admin/students/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM students WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Student not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM students WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('students', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('students', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
});

// POST /api/admin/students/reorder – batch reorder students
$router->post('/api/admin/students/reorder', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) Response::validationError(['ids' => 'ids array required']);
    foreach ($ids as $i => $id) {
        Database::update('students', ['sort_order' => $i + 1], 'id = ?', ['id' => (int)$id]);
    }
    Response::success(null, 'Reordered');
});

// POST /api/admin/students/bulk-change-class – change class for all students in a class
$router->post('/api/admin/students/bulk-change-class', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $fromClass = $data['from_class'] ?? '';
    $toClass = $data['to_class'] ?? '';
    if (!$fromClass || !$toClass) Response::validationError(['from_class' => 'from_class and to_class required']);
    if ($fromClass === $toClass) Response::error('Source and target class are the same', 400);

    $count = Database::fetch("SELECT COUNT(*) as cnt FROM students WHERE class = ?", [$fromClass])['cnt'];
    if ($count == 0) Response::error('No students found in source class', 400);

    Database::query("UPDATE students SET class = ? WHERE class = ?", [$toClass, $fromClass]);
    Response::success(['affected' => (int)$count], "Moved $count students from $fromClass to $toClass");
});
