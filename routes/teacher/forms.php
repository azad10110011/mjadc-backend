<?php

// GET /api/teacher/forms
$router->get('/api/teacher/forms', function () {
    Auth::requireRole('teacher');

    $forms = Database::fetchAll(
        "SELECT id, form_name, pdf_path, uploaded_at 
         FROM downloadable_forms ORDER BY form_name"
    );
    Response::success($forms);
});
