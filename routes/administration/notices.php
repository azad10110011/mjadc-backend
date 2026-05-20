<?php

$adminMw = function () { Auth::requireRole('administration'); };

// GET /api/admin-panel/notices
$router->get('/api/admin-panel/notices', function () {
    Auth::requireRole('administration');
    $notices = Database::fetchAll(
        "SELECT n.*, u.name as author 
         FROM notices n JOIN users u ON n.created_by = u.id 
         ORDER BY n.created_at DESC"
    );
    Response::success($notices);
}, [$adminMw]);

// POST /api/admin-panel/notices
$router->post('/api/admin-panel/notices', function () {
    $user = Auth::requireRole('administration');
    $data = json_decode(file_get_contents('php://input'), true);

    $validator = validate($data);
    $validator->required('title', 'Title');

    if (!$validator->passes()) {
        Response::validationError($validator->errors());
    }

    $noticeId = Database::insert('notices', [
        'title' => $data['title'],
        'body' => $data['body'] ?? '',
        'status' => $data['status'] ?? 'draft',
        'published_at' => ($data['status'] ?? 'draft') === 'published' ? date('Y-m-d H:i:s') : null,
        'created_by' => $user['id'],
    ]);

    // Handle PDF upload
    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION);
        $filename = "notice_{$noticeId}.{$ext}";
        move_uploaded_file($_FILES['pdf']['tmp_name'], __DIR__ . "/../../uploads/notices/{$filename}");
        Database::update('notices', ['pdf_path' => "uploads/notices/{$filename}"], 'id = ?', ['id' => $noticeId]);
    }

    Response::created(['id' => $noticeId], 'Notice created');
}, [$adminMw]);

// PUT /api/admin-panel/notices/{id}
$router->put('/api/admin-panel/notices/{id}', function (array $params) {
    $user = Auth::requireRole('administration');
    $data = json_decode(file_get_contents('php://input'), true);

    $updateData = [];
    foreach (['title', 'body', 'status'] as $field) {
        if (isset($data[$field])) {
            $updateData[$field] = $data[$field];
        }
    }
    if (isset($data['status']) && $data['status'] === 'published') {
        $updateData['published_at'] = date('Y-m-d H:i:s');
    }

    if (!empty($updateData)) {
        Database::update('notices', $updateData, 'id = ?', ['id' => $params['id']]);
    }

    Response::success(null, 'Notice updated');
}, [$adminMw]);

// DELETE /api/admin-panel/notices/{id}
$router->delete('/api/admin-panel/notices/{id}', function (array $params) {
    Auth::requireRole('administration');
    Database::delete('notices', 'id = ?', [$params['id']]);
    Response::success(null, 'Notice deleted');
}, [$adminMw]);
