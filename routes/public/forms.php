<?php

// GET /api/forms
$router->get('/api/forms', function () {
    $forms = Database::fetchAll(
        "SELECT id, form_name, pdf_path, uploaded_at 
         FROM downloadable_forms ORDER BY form_name"
    );
    Response::success($forms);
});

// GET /api/annual-reports
$router->get('/api/annual-reports', function () {
    $reports = Database::fetchAll(
        "SELECT id, year, pdf_path, uploaded_at 
         FROM annual_reports ORDER BY year DESC"
    );
    Response::success($reports);
});
