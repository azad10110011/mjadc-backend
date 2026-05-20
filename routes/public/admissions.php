<?php

// POST /api/admissions/apply
$router->post('/api/admissions/apply', function () {
    $data = json_decode(file_get_contents('php://input'), true);

    $validator = validate($data);
    $validator->required('applicant_name', 'Full Name')
        ->required('programme', 'Programme')
        ->required('mobile', 'Mobile Number');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $admissionId = Database::insert('admissions', [
        'applicant_name' => $data['applicant_name'],
        'dob' => $data['dob'] ?? null,
        'gender' => $data['gender'] ?? null,
        'religion' => $data['religion'] ?? null,
        'nationality' => $data['nationality'] ?? 'Bangladeshi',
        'father_name' => $data['father_name'] ?? null,
        'mother_name' => $data['mother_name'] ?? null,
        'guardian_contact' => $data['guardian_contact'] ?? null,
        'previous_institution' => $data['previous_institution'] ?? null,
        'previous_board' => $data['previous_board'] ?? null,
        'previous_roll' => $data['previous_roll'] ?? null,
        'passing_year' => $data['passing_year'] ?? null,
        'previous_gpa' => $data['previous_gpa'] ?? null,
        'programme' => $data['programme'],
        'class_group' => $data['class_group'] ?? null,
        'mobile' => $data['mobile'],
        'email' => $data['email'] ?? null,
    ]);

    // Handle file uploads
    $uploadPath = __DIR__ . '/../../uploads/documents/';

    if (isset($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION);
        $filename = "admission_{$admissionId}_photo.{$ext}";
        move_uploaded_file($_FILES['photo']['tmp_name'], $uploadPath . $filename);
        Database::update('admissions', ['photo_path' => "uploads/documents/{$filename}"], 'id = ?', ['id' => $admissionId]);
    }

    if (isset($_FILES['certificate']) && $_FILES['certificate']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['certificate']['name'], PATHINFO_EXTENSION);
        $filename = "admission_{$admissionId}_cert.{$ext}";
        move_uploaded_file($_FILES['certificate']['tmp_name'], $uploadPath . $filename);
        Database::update('admissions', ['certificate_path' => "uploads/documents/{$filename}"], 'id = ?', ['id' => $admissionId]);
    }

    Response::created([
        'id' => $admissionId,
        'applicant_name' => $data['applicant_name'],
        'message' => 'Application submitted. Please pay the admission fee.',
    ]);
});

// GET /api/admissions/status/{id}
$router->get('/api/admissions/status/{id}', function (array $params) {
    $admission = Database::fetch(
        "SELECT id, applicant_name, programme, fee_paid, status, submitted_at 
         FROM admissions WHERE id = ?",
        [$params['id']]
    );
    if (!$admission) {
        Response::notFound('Application not found');
    }
    Response::success($admission);
});
