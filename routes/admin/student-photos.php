<?php

// POST /api/admin/students/{id}/photo   (multipart: photo)   — Session 86
// Replaces ONLY the student's photo (used by Student Management → Bulk Photo Upload).
// Admin + Administration (or anyone granted All Students). Safer than the old upload: the file must
// really be an image (checked from its content, not the name), max 5 MB; stored as
// uploads/profiles/<time>_<studentID>.<ext> like other profile photos.

$router->post('/api/admin/students/{id}/photo', function (array $params) {
    Auth::requireAnyRole(['admin', 'administration']);
    $student = Database::fetch("SELECT id, student_id, name FROM students WHERE id = ?", [(int)$params['id']]);
    if (!$student) {
        Response::notFound('Student not found');
    }

    $f = $_FILES['photo'] ?? null;
    if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        Response::validationError(['Choose a photo file' . ($f && $f['error'] === UPLOAD_ERR_INI_SIZE ? ' (file is larger than the server allows)' : '')]);
    }
    if ($f['size'] > 5 * 1024 * 1024) {
        Response::validationError(['Photo must be 5 MB or smaller']);
    }
    $info = @getimagesize($f['tmp_name']);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($types[$info[2]])) {
        Response::validationError(['The file is not a JPG, PNG, GIF or WEBP image']);
    }

    $destDir = __DIR__ . '/../../uploads/profiles';
    if (!is_dir($destDir)) mkdir($destDir, 0755, true);
    $safeId = preg_replace('/[^A-Za-z0-9_-]/', '_', (string)$student['student_id']);
    $filename = time() . '_' . $safeId . '_' . bin2hex(random_bytes(3)) . '.' . $types[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], $destDir . '/' . $filename)) {
        Response::error('Could not save the photo on the server', 500);
    }
    $path = "uploads/profiles/{$filename}";
    Database::query("UPDATE students SET photo_path = ? WHERE id = ?", [$path, $student['id']]);

    Response::success(['photo_path' => $path], "Photo updated for {$student['student_id']}");
});
