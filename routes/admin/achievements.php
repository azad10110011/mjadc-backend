<?php

$adminMw = function () { Auth::requireRole('admin'); };

// GET /api/admin/achievements
$router->get('/api/admin/achievements', function () {
    Auth::requireRole('admin');
    $achievements = Database::fetchAll(
        "SELECT a.id, a.title, a.sort_order,
                (SELECT COUNT(*) FROM achievement_images WHERE achievement_id = a.id) as image_count
         FROM achievements a ORDER BY a.sort_order"
    );
    Response::success($achievements);
}, [$adminMw]);

// GET /api/admin/achievements/{id} (with images)
$router->get('/api/admin/achievements/{id}', function (array $params) {
    Auth::requireRole('admin');
    $achievement = Database::fetch(
        "SELECT id, title, sort_order FROM achievements WHERE id = ?",
        [$params['id']]
    );
    if (!$achievement) Response::notFound('Achievement not found');
    $achievement['images'] = Database::fetchAll(
        "SELECT id, image_path, sort_order FROM achievement_images WHERE achievement_id = ? ORDER BY sort_order",
        [$params['id']]
    );
    Response::success($achievement);
}, [$adminMw]);

// POST /api/admin/achievements
$router->post('/api/admin/achievements', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    if (empty($data['title'])) {
        Response::validationError(['title is required']);
    }

    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM achievements");
    $id = Database::insert('achievements', [
        'title' => $data['title'],
        'sort_order' => $maxSort['next'],
    ]);

    Response::created(['id' => $id], 'Achievement added');
}, [$adminMw]);

// PUT /api/admin/achievements/{id}
$router->put('/api/admin/achievements/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['title'] as $field) {
        if (isset($data[$field])) $updateData[$field] = $data[$field];
    }

    if (!empty($updateData)) {
        Database::update('achievements', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Achievement updated');
}, [$adminMw]);

// DELETE /api/admin/achievements/{id}
$router->delete('/api/admin/achievements/{id}', function (array $params) {
    Auth::requireRole('admin');
    $images = Database::fetchAll("SELECT image_path FROM achievement_images WHERE achievement_id = ?", [$params['id']]);
    foreach ($images as $img) {
        $fullPath = __DIR__ . '/../../' . $img['image_path'];
        if (file_exists($fullPath)) unlink($fullPath);
    }
    Database::delete('achievements', 'id = ?', [$params['id']]);
    Response::success(null, 'Achievement deleted');
}, [$adminMw]);

// POST /api/admin/achievements/{id}/move-up
$router->post('/api/admin/achievements/{id}/move-up', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM achievements WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Achievement not found');

    $prev = Database::fetch(
        "SELECT id, sort_order FROM achievements WHERE sort_order < ? ORDER BY sort_order DESC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$prev) Response::error('Already at top', 400);

    Database::update('achievements', ['sort_order' => $prev['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('achievements', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $prev['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/achievements/{id}/move-down
$router->post('/api/admin/achievements/{id}/move-down', function (array $params) {
    Auth::requireRole('admin');
    $current = Database::fetch("SELECT id, sort_order FROM achievements WHERE id = ?", [$params['id']]);
    if (!$current) Response::notFound('Achievement not found');

    $next = Database::fetch(
        "SELECT id, sort_order FROM achievements WHERE sort_order > ? ORDER BY sort_order ASC LIMIT 1",
        [$current['sort_order']]
    );
    if (!$next) Response::error('Already at bottom', 400);

    Database::update('achievements', ['sort_order' => $next['sort_order']], 'id = ?', ['id' => $current['id']]);
    Database::update('achievements', ['sort_order' => $current['sort_order']], 'id = ?', ['id' => $next['id']]);
    Response::success(null, 'Reordered');
}, [$adminMw]);

// POST /api/admin/achievements/{id}/images
$router->post('/api/admin/achievements/{id}/images', function (array $params) {
    Auth::requireRole('admin');

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        Response::validationError(['File upload failed']);
    }

    $file = $_FILES['file'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    if (!in_array($ext, $allowedExts)) {
        Response::validationError(['File type not allowed']);
    }

    $uploadDir = __DIR__ . '/../../uploads/achievements';
    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

    $filename = time() . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
    $destPath = "{$uploadDir}/{$filename}";
    move_uploaded_file($file['tmp_name'], $destPath);

    $maxSort = Database::fetch(
        "SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM achievement_images WHERE achievement_id = ?",
        [$params['id']]
    );
    $imgId = Database::insert('achievement_images', [
        'achievement_id' => $params['id'],
        'image_path' => "uploads/achievements/{$filename}",
        'sort_order' => $maxSort['next'],
    ]);

    Response::created([
        'id' => $imgId,
        'image_path' => "uploads/achievements/{$filename}",
    ], 'Image uploaded');
}, [$adminMw]);

// DELETE /api/admin/achievements/{id}/images/{imageId}
$router->delete('/api/admin/achievements/{id}/images/{imageId}', function (array $params) {
    Auth::requireRole('admin');
    $img = Database::fetch("SELECT id, image_path FROM achievement_images WHERE id = ? AND achievement_id = ?", [$params['imageId'], $params['id']]);
    if (!$img) Response::notFound('Image not found');

    $fullPath = __DIR__ . '/../../' . $img['image_path'];
    if (file_exists($fullPath)) unlink($fullPath);

    Database::delete('achievement_images', 'id = ?', [$params['imageId']]);
    Response::success(null, 'Image deleted');
}, [$adminMw]);
