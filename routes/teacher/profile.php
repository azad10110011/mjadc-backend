<?php

// GET /api/teacher/profile — returns current teacher's data including result_subjects
$router->get('/api/teacher/profile', function () {
    $user = Auth::requireAnyRole(['teacher', 'exam_controller']);

    $row = Database::fetch("SELECT id, name, email FROM users WHERE id = ?", [$user['id']]);
    if (!$row) Response::error('User not found', 404);

    $row['roles'] = $user['roles'];
    $isTeacher = in_array('teacher', $row['roles']);
    $row['subjects'] = $isTeacher ? getTeacherSubjects((int)$row['id'], 'public') : [];
    $row['result_subjects'] = $isTeacher ? getTeacherSubjects((int)$row['id'], 'result') : [];

    Response::success($row);
});