<?php

// GET /api/governing-body
$router->get('/api/governing-body', function () {
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(position, '') as position, mobile, photo_path 
         FROM governing_body ORDER BY sort_order"
    );
    Response::success($members);
});

// GET /api/teachers-council
$router->get('/api/teachers-council', function () {
    $members = Database::fetchAll(
        "SELECT id, name, designation, COALESCE(position, '') as position, photo_path 
         FROM teachers ORDER BY sort_order"
    );
    Response::success($members);
});

// GET /api/teachers-list
$router->get('/api/teachers-list', function () {
    $teachers = Database::fetchAll(
        "SELECT t.id, t.name, t.name_bangla, t.designation, t.subject, t.email, t.mobile, t.photo_path, t.pds_id, t.mpo_index, u.date_of_birth
         FROM teachers t LEFT JOIN users u ON t.user_id = u.id ORDER BY t.sort_order"
    );
    Response::success($teachers);
});

// GET /api/staff-list
$router->get('/api/staff-list', function () {
    $staff = Database::fetchAll(
        "SELECT s.id, s.name, s.name_bangla, s.designation, s.subject, s.mobile, s.photo_path, s.pds_id, s.mpo_index, u.date_of_birth
         FROM staff s LEFT JOIN users u ON s.user_id = u.id ORDER BY s.sort_order"
    );
    Response::success($staff);
});

// GET /api/co-curricular/{club}
$router->get('/api/co-curricular/{club}', function (array $params) {
    $members = Database::fetchAll(
        "SELECT id, club, name, designation, mobile, photo_path 
         FROM co_curricular WHERE club = ? ORDER BY sort_order",
        [$params['club']]
    );
    Response::success($members);
});

// GET /api/departments/{dept}/teachers
$router->get('/api/departments/{dept}/teachers', function (array $params) {
    $groupMap = [
        'science' => 'Science',
        'business-studies' => 'Business Studies',
        'humanities' => 'Humanities',
        'general' => 'General',
        'bmt' => 'BMT',
    ];

    $group = $groupMap[$params['dept']] ?? null;
    if (!$group) {
        Response::notFound('Department not found');
    }

    $teachers = Database::fetchAll(
        "SELECT t.id, t.name, t.designation, t.subject, t.email, t.mobile, t.photo_path, t.pds_id, t.mpo_index, u.date_of_birth
         FROM teachers t LEFT JOIN users u ON t.user_id = u.id WHERE t.`group` = ? ORDER BY t.sort_order",
        [$group]
    );
    Response::success($teachers);
});
