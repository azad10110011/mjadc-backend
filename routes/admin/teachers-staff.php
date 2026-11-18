<?php

/** Replace the teacher's exam-mark (result) subjects when the request sends result_subjects (Session 90). */
if (!function_exists('teacherSyncResultSubjects')) {
function teacherSyncResultSubjects(int $teacherId, array $data): void
{
    if (!isset($data['result_subjects']) || !is_array($data['result_subjects'])) return;
    Database::delete('teacher_subjects', 'teacher_id = ? AND type = ?', [$teacherId, 'result']);
    foreach (array_unique(array_filter(array_map(fn($x) => trim((string)$x), $data['result_subjects']))) as $sub) {
        Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'result']);
    }
}}

// GET /api/admin/teachers-staff/teachers
$router->get('/api/admin/teachers-staff/teachers', function () {
    Auth::requireRole('admin');
    $teachers = Database::fetchAll("SELECT t.id, t.user_id, (SELECT GROUP_CONCAT(ur.role) FROM user_roles ur WHERE ur.user_id = t.user_id) AS roles, t.name, t.name_bangla, t.name_english, t.designation, t.subject, t.`group`, t.email, t.mobile, t.whatsapp_number, t.photo_path, t.joining_date, t.first_mpo_date, t.nid_number, t.present_address, t.permanent_address, t.gender, t.pds_id, t.mpo_index, u.date_of_birth FROM teachers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.sort_order, t.name");
    $now = new DateTime();
    foreach ($teachers as &$t) {
        $t['experience'] = $t['first_mpo_date'] ? $now->diff(new DateTime($t['first_mpo_date']))->format('%y years, %m months, %d days') : null;
        // Include subjects from teacher_subjects table
        $rows = Database::fetchAll("SELECT subject, type FROM teacher_subjects WHERE teacher_id = ?", [$t['id']]);
        $t['subjects'] = [];
        $t['result_subjects'] = [];
        foreach ($rows as $r) {
            if ($r['type'] === 'result') {
                $t['result_subjects'][] = $r['subject'];
            } else {
                $t['subjects'][] = $r['subject'];
            }
        }
    }
    Response::success($teachers);
});

// GET /api/admin/teachers-staff/staff
$router->get('/api/admin/teachers-staff/staff', function () {
    Auth::requireRole('admin');
    $staff = Database::fetchAll("SELECT s.id, s.user_id, (SELECT GROUP_CONCAT(ur.role) FROM user_roles ur WHERE ur.user_id = s.user_id) AS roles, s.name, s.name_bangla, s.name_english, s.designation, s.subject, s.email, s.mobile, s.whatsapp_number, s.photo_path, s.joining_date, s.first_mpo_date, s.nid_number, s.present_address, s.permanent_address, s.gender, s.pds_id, s.mpo_index, u.date_of_birth FROM staff s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.sort_order, s.name");
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

        [$pwHash, $mustChange, $tempPw] = newAccountPassword($data['password'] ?? null); // Session 112
        $userId = Database::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'],
            'password_hash' => $pwHash,
            'must_change_password' => $mustChange,
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'default_role' => $data['default_role'] ?? null,
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
            'pds_id' => $data['pds_id'] ?? null,
            'mpo_index' => $data['mpo_index'] ?? null,
            'custom_fields' => isset($data['custom_fields']) && is_array($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
        ]);

        // Sync to teacher_subjects (single subject dropdown + multi-subject support)
        if ($data['subject'] ?? null) {
            Database::delete('teacher_subjects', 'teacher_id = ? AND type = ?', [$teacherId, 'public']);
            Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $data['subject'], 'type' => 'public']);
        }
        if (isset($data['subjects']) && is_array($data['subjects'])) {
            Database::delete('teacher_subjects', 'teacher_id = ? AND type = ?', [$teacherId, 'public']);
            foreach ($data['subjects'] as $sub) {
                Database::insert('teacher_subjects', ['teacher_id' => $teacherId, 'subject' => $sub, 'type' => 'public']);
            }
        }
        // Session 90: subjects the teacher may upload / update exam marks for
        teacherSyncResultSubjects((int)$teacherId, $data);

        // Session 102: panel tasks chosen on the form (unticked role tasks → removed for this user)
        if (isset($data['disabled_features']) && is_array($data['disabled_features']) && $userId) {
            Permissions::applyPanelTasks((int)$userId, $data['disabled_features'], (int)(Auth::getUser()['id'] ?? 0));
        }
        Response::created(['id' => $teacherId, 'temp_password' => $tempPw], $tempPw ? 'Teacher added. Temporary password: ' . $tempPw : 'Teacher added');
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
        foreach (['name', 'name_bangla', 'name_english', 'designation', 'subject', 'group', 'mobile', 'email', 'joining_date', 'first_mpo_date', 'nid_number', 'whatsapp_number', 'present_address', 'permanent_address', 'gender', 'photo_path', 'pds_id', 'mpo_index'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            $updateData['custom_fields'] = json_encode($data['custom_fields']);
        }
        if (!empty($updateData)) {
            Database::update('teachers', $updateData, 'id = ?', ['id' => $params['id']]);
        }

        // Handle user account (date_of_birth + password + role)
        $teacher = Database::fetch("SELECT * FROM teachers WHERE id = ?", [$params['id']]);
        if ($teacher && (isset($data['date_of_birth']) || isset($data['password']) || isset($data['roles']))) {
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
                } else {
                    [$pwHash, $mustChange] = newAccountPassword($data['password'] ?? null); // Session 112
                    $userId = Database::insert('users', [
                        'name' => $data['name'] ?? $teacher['name'],
                        'email' => $email,
                        'password_hash' => $pwHash,
                        'must_change_password' => $mustChange,
                        'gender' => $data['gender'] ?? $teacher['gender'],
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                    ]);
                }
                Database::update('teachers', ['user_id' => $userId], 'id = ?', ['id' => $params['id']]);
            }
            $userUpdate = [];
            if (isset($data['date_of_birth'])) $userUpdate['date_of_birth'] = $data['date_of_birth'];
            if (isset($data['password'])) $userUpdate['password_hash'] = Auth::hashPassword($data['password']);
            if (array_key_exists('default_role', $data)) $userUpdate['default_role'] = $data['default_role'];
            if (!empty($userUpdate)) Database::update('users', $userUpdate, 'id = ?', ['id' => $userId]);

            if (isset($data['roles']) && is_array($data['roles'])) {
                // Session 102: this form only manages these roles — keep any others (admin, student). Before, saving
                // here replaced ALL roles, so editing a teacher who is also admin removed their admin access.
                $managed = ['teacher', 'exam_controller', 'administration', 'principal', 'staff'];
                $keep = array_values(array_diff(array_column(Database::fetchAll("SELECT role FROM user_roles WHERE user_id = ?", [$userId]), 'role'), $managed));
                $newRoles = array_values(array_unique(array_merge($keep, array_values(array_intersect((array)$data['roles'], $managed)))));
                if ($newRoles) {
                    Database::delete('user_roles', 'user_id = ?', [$userId]);
                    foreach ($newRoles as $role) {
                        Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
                    }
                }
            }
        }

        // Sync to teacher_subjects
        if (isset($data['subject']) || isset($data['subjects'])) {
            Database::delete('teacher_subjects', 'teacher_id = ? AND type = ?', [$params['id'], 'public']);
            if (isset($data['subjects']) && is_array($data['subjects'])) {
                foreach ($data['subjects'] as $sub) {
                    Database::insert('teacher_subjects', ['teacher_id' => $params['id'], 'subject' => $sub, 'type' => 'public']);
                }
            } elseif ($data['subject'] ?? null) {
                Database::insert('teacher_subjects', ['teacher_id' => $params['id'], 'subject' => $data['subject'], 'type' => 'public']);
            }
        }

        teacherSyncResultSubjects((int)$params['id'], $data);
        $tUser = (int)(Database::fetch("SELECT user_id FROM teachers WHERE id = ?", [$params['id']])['user_id'] ?? 0);
        // Session 102: panel tasks chosen on the form (unticked role tasks → removed for this user)
        if (isset($data['disabled_features']) && is_array($data['disabled_features']) && $tUser) {
            Permissions::applyPanelTasks((int)$tUser, $data['disabled_features'], (int)(Auth::getUser()['id'] ?? 0));
        }

        Response::success(null, 'Teacher updated');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/teachers-staff/teacher/{id}
$router->delete('/api/admin/teachers-staff/teacher/{id}', function (array $params) {
    Auth::requireRole('admin');
    $teacher = Database::fetch("SELECT user_id FROM teachers WHERE id = ?", [$params['id']]);

    // Clean up related records before deleting
    Database::delete('teacher_subjects', 'teacher_id = ?', [$params['id']]);
    if ($teacher && $teacher['user_id']) {
        $uid = $teacher['user_id'];
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

        [$pwHash, $mustChange, $tempPw] = newAccountPassword($data['password'] ?? null); // Session 112
        $userId = Database::insert('users', [
            'name' => $data['name'],
            'email' => $data['email'] ?? ($data['name'] . '@mjadc.ac.bd'),
            'password_hash' => $pwHash,
            'must_change_password' => $mustChange,
            'gender' => $data['gender'],
            'date_of_birth' => $data['date_of_birth'] ?? null,
            'default_role' => $data['default_role'] ?? null,
        ]);

        $roles = $data['roles'] ?? ['administration'];
        foreach ($roles as $role) {
            Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
        }

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
            'pds_id' => $data['pds_id'] ?? null,
            'mpo_index' => $data['mpo_index'] ?? null,
            'custom_fields' => isset($data['custom_fields']) && is_array($data['custom_fields']) ? json_encode($data['custom_fields']) : null,
        ]);

        // Session 102: panel tasks chosen on the form (unticked role tasks → removed for this user)
        if (isset($data['disabled_features']) && is_array($data['disabled_features']) && $userId) {
            Permissions::applyPanelTasks((int)$userId, $data['disabled_features'], (int)(Auth::getUser()['id'] ?? 0));
        }
        Response::created(['id' => $staffId, 'temp_password' => $tempPw], $tempPw ? 'Staff added. Temporary password: ' . $tempPw : 'Staff added');
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
        foreach (['name', 'name_bangla', 'name_english', 'designation', 'subject', 'mobile', 'email', 'joining_date', 'first_mpo_date', 'nid_number', 'whatsapp_number', 'present_address', 'permanent_address', 'gender', 'photo_path', 'pds_id', 'mpo_index'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (isset($data['custom_fields']) && is_array($data['custom_fields'])) {
            $updateData['custom_fields'] = json_encode($data['custom_fields']);
        }
        if (!empty($updateData)) {
            Database::update('staff', $updateData, 'id = ?', ['id' => $params['id']]);
        }

        // Handle user account (date_of_birth + password + roles)
        $record = Database::fetch("SELECT * FROM staff WHERE id = ?", [$params['id']]);
        if ($record && (isset($data['date_of_birth']) || isset($data['password']) || isset($data['roles']))) {
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
                } else {
                    [$pwHash, $mustChange] = newAccountPassword($data['password'] ?? null); // Session 112
                    $userId = Database::insert('users', [
                        'name' => $data['name'] ?? $record['name'],
                        'email' => $email,
                        'password_hash' => $pwHash,
                        'must_change_password' => $mustChange,
                        'gender' => $data['gender'] ?? $record['gender'],
                        'date_of_birth' => $data['date_of_birth'] ?? null,
                    ]);
                }
                Database::update('staff', ['user_id' => $userId], 'id = ?', ['id' => $params['id']]);
            }
            $userUpdate = [];
            if (isset($data['date_of_birth'])) $userUpdate['date_of_birth'] = $data['date_of_birth'];
            if (isset($data['password'])) $userUpdate['password_hash'] = Auth::hashPassword($data['password']);
            if (array_key_exists('default_role', $data)) $userUpdate['default_role'] = $data['default_role'];
            if (!empty($userUpdate)) Database::update('users', $userUpdate, 'id = ?', ['id' => $userId]);

            if (isset($data['roles']) && is_array($data['roles'])) {
                // Session 102: this form only manages these roles — keep any others (admin, student). Before, saving
                // here replaced ALL roles, so editing a teacher who is also admin removed their admin access.
                $managed = ['teacher', 'exam_controller', 'administration', 'principal', 'staff'];
                $keep = array_values(array_diff(array_column(Database::fetchAll("SELECT role FROM user_roles WHERE user_id = ?", [$userId]), 'role'), $managed));
                $newRoles = array_values(array_unique(array_merge($keep, array_values(array_intersect((array)$data['roles'], $managed)))));
                if ($newRoles) {
                    Database::delete('user_roles', 'user_id = ?', [$userId]);
                    foreach ($newRoles as $role) {
                        Database::insert('user_roles', ['user_id' => $userId, 'role' => $role]);
                    }
                }
            }
        }
        $sUser = (int)(Database::fetch("SELECT user_id FROM staff WHERE id = ?", [$params['id']])['user_id'] ?? 0);
        // Session 102: panel tasks chosen on the form (unticked role tasks → removed for this user)
        if (isset($data['disabled_features']) && is_array($data['disabled_features']) && $sUser) {
            Permissions::applyPanelTasks((int)$sUser, $data['disabled_features'], (int)(Auth::getUser()['id'] ?? 0));
        }
        Response::success(null, 'Staff updated');
    } catch (\Throwable $e) {
        Response::error('Internal server error: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/teachers-staff/staff/{id}
$router->delete('/api/admin/teachers-staff/staff/{id}', function (array $params) {
    Auth::requireRole('admin');
    $record = Database::fetch("SELECT user_id FROM staff WHERE id = ?", [$params['id']]);

    // Clean up related records before deleting
    if ($record && $record['user_id']) {
        $uid = $record['user_id'];
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

// POST /api/admin/teachers-staff/teacher/reorder â€“ batch reorder teachers
$router->post('/api/admin/teachers-staff/teacher/reorder', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) Response::validationError(['ids' => 'ids array required']);
    foreach ($ids as $i => $id) {
        Database::update('teachers', ['sort_order' => $i + 1], 'id = ?', ['id' => (int)$id]);
    }
    Response::success(null, 'Reordered');
});

// POST /api/admin/teachers-staff/staff/reorder â€“ batch reorder staff
$router->post('/api/admin/teachers-staff/staff/reorder', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) Response::validationError(['ids' => 'ids array required']);
    foreach ($ids as $i => $id) {
        Database::update('staff', ['sort_order' => $i + 1], 'id = ?', ['id' => (int)$id]);
    }
    Response::success(null, 'Reordered');
});


