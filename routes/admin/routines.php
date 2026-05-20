<?php

// GET /api/admin/routines
$router->get('/api/admin/routines', function () {
    Auth::requireRole('admin');
    $routines = Database::fetchAll("SELECT * FROM routines ORDER BY class, section");
    Response::success($routines);
});

// POST /api/admin/routines
$router->post('/api/admin/routines', function () {
    try {
        $user = Auth::requireRole('admin');

        $class = $_POST['class'] ?? '';
        $section = $_POST['section'] ?? null;

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

        $uploadDir = __DIR__ . '/../../uploads/routines';
        if (!is_dir($uploadDir)) {
            @mkdir($uploadDir, 0755, true);
        }

        $filename = 'routine_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['pdf']['name']);
        $destPath = $uploadDir . '/' . $filename;

        if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
            Response::error('Failed to save uploaded file. Check directory permissions.', 500);
        }

        $routineId = Database::insert('routines', [
            'class' => $class,
            'section' => $section,
            'pdf_path' => "uploads/routines/{$filename}",
            'uploaded_by' => $user['id'],
        ]);

        Response::created(['id' => $routineId], 'Routine uploaded');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});

// POST /api/admin/routines/{id}
$router->post('/api/admin/routines/{id}', function (array $params) {
    try {
        $user = Auth::requireRole('admin');

        $routine = Database::fetch("SELECT * FROM routines WHERE id = ?", [$params['id']]);
        if (!$routine) {
            Response::notFound('Routine not found');
        }

        $class = $_POST['class'] ?? $routine['class'];
        $section = array_key_exists('section', $_POST) ? $_POST['section'] : $routine['section'];

        $updateData = [
            'class' => $class,
            'section' => $section,
        ];

        // If a new PDF is uploaded, replace the old one
        if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION));
            if ($ext !== 'pdf') {
                Response::validationError(['Only PDF files are allowed']);
            }

            $uploadDir = __DIR__ . '/../../uploads/routines';
            if (!is_dir($uploadDir)) {
                @mkdir($uploadDir, 0755, true);
            }

            $filename = 'routine_' . time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['pdf']['name']);
            $destPath = $uploadDir . '/' . $filename;

            if (!@move_uploaded_file($_FILES['pdf']['tmp_name'], $destPath)) {
                Response::error('Failed to save uploaded file', 500);
            }

            // Delete old PDF file
            if ($routine['pdf_path']) {
                $oldPath = __DIR__ . '/../../' . $routine['pdf_path'];
                if (file_exists($oldPath)) {
                    @unlink($oldPath);
                }
            }

            $updateData['pdf_path'] = "uploads/routines/{$filename}";
        }

        Database::update('routines', $updateData, 'id = ?', ['id' => $params['id']]);
        Response::success(null, 'Routine updated');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});

// DELETE /api/admin/routines/{id}
$router->delete('/api/admin/routines/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');

        $routine = Database::fetch("SELECT * FROM routines WHERE id = ?", [$params['id']]);
        if ($routine && $routine['pdf_path']) {
            $filePath = __DIR__ . '/../../' . $routine['pdf_path'];
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }

        Database::delete('routines', 'id = ?', [$params['id']]);
        Response::success(null, 'Routine deleted');
    } catch (\Exception $e) {
        while (ob_get_level()) ob_end_clean();
        http_response_code(500);
        echo json_encode(['status' => 500, 'message' => $e->getMessage()]);
        exit;
    }
});
