<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/gallery
$router->get('/api/admin/gallery', function () {
    Auth::requireRole('admin');
    $images = Database::fetchAll(
        "SELECT g.id, g.caption, g.event_name, g.photo_path, g.uploaded_at,
                u.name as uploaded_by_name
         FROM gallery g
         LEFT JOIN users u ON g.uploaded_by = u.id
         ORDER BY g.uploaded_at DESC"
    );
    Response::success($images);
}, [$adminMw]);

// POST /api/admin/gallery
$router->post('/api/admin/gallery', function () {
    $user = Auth::requireRole('admin');
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $uploadDir = __DIR__ . '/../../uploads/gallery';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
    $caption = $_POST['caption'] ?? '';
    $eventName = $_POST['event_name'] ?? '';

    $uploaded = [];

    if (isset($_FILES['files'])) {
        $fileCount = is_array($_FILES['files']['name']) ? count($_FILES['files']['name']) : 0;
        for ($i = 0; $i < $fileCount; $i++) {
            if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;
            $ext = strtolower(pathinfo($_FILES['files']['name'][$i], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowedExts)) continue;

            $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $_FILES['files']['name'][$i]);
            $destPath = "{$uploadDir}/{$filename}";
            move_uploaded_file($_FILES['files']['tmp_name'][$i], $destPath);

            $id = Database::insert('gallery', [
                'caption' => $caption,
                'event_name' => $eventName,
                'photo_path' => "uploads/gallery/{$filename}",
                'uploaded_by' => $user['id'],
            ]);
            $uploaded[] = ['id' => $id, 'photo_path' => "uploads/gallery/{$filename}"];
        }
    }

    if (empty($uploaded)) {
        Response::validationError(['No valid files uploaded']);
    }

    Response::created(['images' => $uploaded], count($uploaded) . ' image(s) uploaded');
}, [$adminMw]);

// DELETE /api/admin/gallery/{id}
$router->delete('/api/admin/gallery/{id}', function (array $params) {
    Auth::requireRole('admin');
    $image = Database::fetch("SELECT id, photo_path FROM gallery WHERE id = ?", [$params['id']]);
    if (!$image) Response::notFound('Image not found');

    $fullPath = __DIR__ . '/../../' . $image['photo_path'];
    if (file_exists($fullPath)) unlink($fullPath);

    Database::delete('gallery', 'id = ?', [$params['id']]);
    Response::success(null, 'Image deleted');
}, [$adminMw]);
