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
         FROM teachers ORDER BY name"
    );
    Response::success($members);
});

// GET /api/teachers-list
$router->get('/api/teachers-list', function () {
    $teachers = Database::fetchAll(
        "SELECT id, name, name_bangla, designation, subject, email, mobile, photo_path 
         FROM teachers ORDER BY name"
    );
    Response::success($teachers);
});

// GET /api/staff-list
$router->get('/api/staff-list', function () {
    $staff = Database::fetchAll(
        "SELECT id, name, name_bangla, designation, mobile, photo_path 
         FROM staff ORDER BY name"
    );
    Response::success($staff);
});

// GET /api/co-curricular/{club}
$router->get('/api/co-curricular/{club}', function (array $params) {
    $members = Database::fetchAll(
        "SELECT id, club, name, designation, mobile, photo_path 
         FROM co_curricular WHERE club = ?",
        [$params['club']]
    );
    Response::success($members);
});

// GET /api/departments/{dept}/teachers
$router->get('/api/departments/{dept}/teachers', function (array $params) {
    $deptMap = [
        'science' => ['Physics', 'Chemistry', 'Botany', 'Higher Math'],
        'business-studies' => ['Management', 'Marketing', 'Production Management & Marketing', 'Accounting', 'Finance Banking & Insurance', 'Finance & Banking'],
        'humanities' => ['Bangla', 'English', 'Political Science', 'Economics', 'Geography', 'Philosophy', 'Sociology', 'Social Welfare', 'History', 'Islamic History', 'Islamic Studies', 'Psychology', 'Statistics', 'Agriculture', 'Home Economics'],
        'bmt' => ['Management', 'Marketing', 'Production Management & Marketing', 'Accounting'],
    ];

    $subjects = $deptMap[$params['dept']] ?? [];
    if (empty($subjects)) {
        Response::notFound('Department not found');
    }

    $placeholders = implode(',', array_fill(0, count($subjects), '?'));
    $teachers = Database::fetchAll(
        "SELECT id, name, designation, subject, email, mobile, photo_path 
         FROM teachers WHERE subject IN ({$placeholders}) ORDER BY name",
        $subjects
    );
    Response::success($teachers);
});
