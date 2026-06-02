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
        "SELECT id, name, name_bangla, designation, subject, email, mobile, photo_path 
         FROM teachers ORDER BY sort_order"
    );
    Response::success($teachers);
});

// GET /api/staff-list
$router->get('/api/staff-list', function () {
    $staff = Database::fetchAll(
        "SELECT id, name, name_bangla, designation, mobile, photo_path 
         FROM staff ORDER BY sort_order"
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
        "SELECT id, name, designation, subject, email, mobile, photo_path 
         FROM teachers WHERE `group` = ? ORDER BY sort_order",
        [$group]
    );
    Response::success($teachers);
});
