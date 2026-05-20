<?php

// GET /api/admin/media
$router->get('/api/admin/media', function () {
    Auth::requireRole('admin');
    // List all files in uploads directory
    $uploadPath = __DIR__ . '/../../uploads';
    $files = [];

    $dirs = ['notices', 'syllabus', 'routines', 'forms', 'gallery', 'profiles', 'documents'];
    foreach ($dirs as $dir) {
        $path = "{$uploadPath}/{$dir}";
        if (!is_dir($path)) continue;
        $items = scandir($path);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') continue;
            $files[] = [
                'filename' => $item,
                'path' => "uploads/{$dir}/{$item}",
                'directory' => $dir,
                'size' => filesize("{$path}/{$item}"),
                'modified' => date('Y-m-d H:i:s', filemtime("{$path}/{$item}")),
            ];
        }
    }

    Response::success($files);
});

// POST /api/admin/media/upload
$router->post('/api/admin/media/upload', function () {
    $user = Auth::requireRole('admin');

    $directory = $_POST['directory'] ?? 'gallery';
    $allowedDirs = ['notices', 'syllabus', 'routines', 'forms', 'gallery', 'profiles', 'documents'];

    if (!in_array($directory, $allowedDirs)) {
        Response::validationError(['Invalid directory']);
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Response::validationError(['File upload failed']);
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'pdf', 'doc', 'docx'];

    if (!in_array($ext, $allowedExts)) {
        Response::validationError(['File type not allowed']);
    }

    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = __DIR__ . "/../../uploads/{$directory}/{$filename}";
    move_uploaded_file($file['tmp_name'], $destPath);

    if ($directory === 'gallery') {
        Database::insert('gallery', [
            'caption' => $_POST['caption'] ?? '',
            'event_name' => $_POST['event_name'] ?? '',
            'photo_path' => "uploads/gallery/{$filename}",
            'uploaded_by' => $user['id'],
        ]);
    }

    Response::created([
        'filename' => $filename,
        'path' => "uploads/{$directory}/{$filename}",
    ], 'File uploaded');
});

// DELETE /api/admin/media
$router->delete('/api/admin/media', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $path = $data['path'] ?? '';

    if (!$path) {
        Response::validationError(['path is required']);
    }

    $fullPath = __DIR__ . '/../../' . $path;
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }

    Response::success(null, 'File deleted');
});
