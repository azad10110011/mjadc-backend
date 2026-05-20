<?php

// GET /api/admin-panel/syllabus
$router->get('/api/admin-panel/syllabus', function () {
    Auth::requireRole('administration');
    $syllabi = Database::fetchAll("SELECT * FROM syllabus ORDER BY class, department");
    Response::success($syllabi);
});

// POST /api/admin-panel/syllabus
$router->post('/api/admin-panel/syllabus', function () {
    try {
        $user = Auth::requireRole('administration');

        $class = $_POST['class'] ?? '';
        $department = $_POST['department'] ?? null;

        if (!$class) {
            Response::validationError(['Class is required']);
        }

        if (!isset($_FILES['pdf']) || $_FILES['pdf']['error'] !== UPLOAD_ERR_OK) {
            $errorCode = $_FILES['pdf']['error'] ?? -1;
            $messages = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds server upload_max_filesize limit',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form max file size limit',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded',
                UPLOAD_ERR_NO_FILE => 'No PDF file was selected',
                UPLOAD_ERR_NO_TMP_DIR => 'Server missing temporary directory',
                UPLOAD_ERR_CANT_WRITE => 'Server cannot write file to disk',
            ];
            $msg = $messages[$errorCode] ?? 'File upload failed';
            Response::validationError([$msg]);
        }

        $ext = strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION));
        if ($ext !== 'pdf') {
            Response::validationError(['Only PDF files are allowed']);
        }

        $uploadDir = __DIR__ . '/../../uploads/syllabus';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $filename = 'syllabus_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['pdf']['name']);
        $destPath = $uploadDir . '/' . $filename;

        if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
            Response::error('Failed to save uploaded file. Check directory permissions.', 500);
        }

        $syllabusId = Database::insert('syllabus', [
            'class' => $class,
            'department' => $department,
            'pdf_path' => "uploads/syllabus/{$filename}",
            'uploaded_by' => $user['id'],
        ]);

        Response::created(['id' => $syllabusId], 'Syllabus uploaded');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});

// POST /api/admin-panel/syllabus/{id}
$router->post('/api/admin-panel/syllabus/{id}', function (array $params) {
    try {
        $user = Auth::requireRole('administration');

        $syllabus = Database::fetch("SELECT * FROM syllabus WHERE id = ?", [$params['id']]);
        if (!$syllabus) {
            Response::notFound('Syllabus not found');
        }

        $class = $_POST['class'] ?? $syllabus['class'];
        $department = array_key_exists('department', $_POST) ? $_POST['department'] : $syllabus['department'];

        $updateData = [
            'class' => $class,
            'department' => $department,
        ];

        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                Response::validationError(['Only PDF files are allowed']);
            }

            $uploadDir = __DIR__ . '/../../uploads/syllabus';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $filename = 'syllabus_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['pdf']['name']);
            $destPath = $uploadDir . '/' . $filename;

            if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
                Response::error('Failed to save uploaded file', 500);
            }

            if ($syllabus['pdf_path']) {
                $oldPath = __DIR__ . '/../../' . $syllabus['pdf_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $updateData['pdf_path'] = "uploads/syllabus/{$filename}";
        }

        Database::update('syllabus', $updateData, 'id = ?', ['id' => $params['id']]);
        Response::success(null, 'Syllabus updated');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});

// DELETE /api/admin-panel/syllabus/{id}
$router->delete('/api/admin-panel/syllabus/{id}', function (array $params) {
    Auth::requireRole('administration');
    Database::delete('syllabus', 'id = ?', [$params['id']]);
    Response::success(null, 'Syllabus deleted');
});
