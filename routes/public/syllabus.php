<?php

// GET /api/syllabus
$router->get('/api/syllabus', function () {
    $syllabi = Database::fetchAll(
        "SELECT id, class, department, subject, pdf_path, uploaded_at 
         FROM syllabus ORDER BY class, department"
    );
    Response::success($syllabi);
});
