<?php

// GET /api/admin/forms
$router->get('/api/admin/forms', function () {
    Auth::requireRole('admin');
    $forms = Database::fetchAll("SELECT * FROM downloadable_forms ORDER BY form_name");
    Response::success($forms);
});

// POST /api/admin/forms
$router->post('/api/admin/forms', function () {
    $user = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    validate($data)->required('form_name', 'Form Name')->validate();

    $formId = Database::insert('downloadable_forms', [
        'form_name' => $data['form_name'],
        'pdf_path' => '',
        'uploaded_by' => $user['id'],
    ]);

    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION);
        $filename = "form_{$formId}.{$ext}";
        move_uploaded_file($_FILES['pdf']['tmp_name'], __DIR__ . "/../../uploads/forms/{$filename}");
        Database::update('downloadable_forms', ['pdf_path' => "uploads/forms/{$filename}"], 'id = ?', ['id' => $formId]);
    }

    Response::created(['id' => $formId], 'Form uploaded');
});

// DELETE /api/admin/forms/{id}
$router->delete('/api/admin/forms/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('downloadable_forms', 'id = ?', [$params['id']]);
    Response::success(null, 'Form deleted');
});
