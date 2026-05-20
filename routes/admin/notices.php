<?php

$adminMw = function () { Auth::requireRole('admin'); };

function getNoticeData(): array
{
    $ct = $_SERVER['CONTENT_TYPE'] ?? '';
    if (preg_match('/multipart\/form-data/i', $ct)) {
        return $_POST;
    }
    return json_decode(file_get_contents('php://input'), true) ?? [];
}

function getUploadDir(): string
{
    $dir = __DIR__ . '/../../uploads/notices/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    return $dir;
}

function deleteOldPdf(string $pdfPath): void
{
    $fullPath = __DIR__ . '/../../' . $pdfPath;
    if (file_exists($fullPath)) {
        unlink($fullPath);
    }
}

// GET /api/admin/notices
$router->get('/api/admin/notices', function () {
    Auth::requireRole('admin');
    $notices = Database::fetchAll(
        "SELECT n.*, u.name as author FROM notices n 
         JOIN users u ON n.created_by = u.id 
         ORDER BY n.created_at DESC"
    );
    Response::success($notices);
}, [$adminMw]);

// POST /api/admin/notices (create with optional PDF)
$router->post('/api/admin/notices', function () {
    $user = Auth::requireRole('admin');
    $data = getNoticeData();
    validate($data)->required('title', 'Title')->validate();

    $noticeId = Database::insert('notices', [
        'title' => $data['title'],
        'body' => $data['body'] ?? '',
        'status' => $data['status'] ?? 'draft',
        'published_at' => ($data['status'] ?? 'draft') === 'published' ? date('Y-m-d H:i:s') : null,
        'created_by' => $user['id'],
    ]);

    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $ext = pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION);
        $filename = "notice_{$noticeId}.{$ext}";
        move_uploaded_file($_FILES['pdf']['tmp_name'], getUploadDir() . $filename);
        Database::update('notices', ['pdf_path' => "uploads/notices/{$filename}"], 'id = ?', ['id' => $noticeId]);
    }

    Response::created(['id' => $noticeId], 'Notice created');
}, [$adminMw]);

// POST /api/admin/notices/{id} (update with file upload support)
$router->post('/api/admin/notices/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = getNoticeData();
    $updateData = [];
    foreach (['title', 'body', 'status'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (isset($data['status'])) {
        $updateData['published_at'] = $data['status'] === 'published' ? date('Y-m-d H:i:s') : null;
    }

    // Handle new PDF upload
    if (isset($_FILES['pdf']) && $_FILES['pdf']['error'] === UPLOAD_ERR_OK) {
        $old = Database::fetch("SELECT pdf_path FROM notices WHERE id = ?", [$params['id']]);
        if ($old && $old['pdf_path']) deleteOldPdf($old['pdf_path']);

        $ext = pathinfo($_FILES['pdf']['name'], PATHINFO_EXTENSION);
        $filename = "notice_{$params['id']}.{$ext}";
        move_uploaded_file($_FILES['pdf']['tmp_name'], getUploadDir() . $filename);
        $updateData['pdf_path'] = "uploads/notices/{$filename}";
    }

    // Handle PDF removal
    if (!empty($data['remove_pdf'])) {
        $old = Database::fetch("SELECT pdf_path FROM notices WHERE id = ?", [$params['id']]);
        if ($old && $old['pdf_path']) deleteOldPdf($old['pdf_path']);
        $updateData['pdf_path'] = null;
    }

    if (!empty($updateData)) {
        Database::update('notices', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Notice updated');
}, [$adminMw]);

// PUT /api/admin/notices/{id} (update without file - backward compatible)
$router->put('/api/admin/notices/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $updateData = [];
    foreach (['title', 'body', 'status'] as $f) {
        if (isset($data[$f])) $updateData[$f] = $data[$f];
    }
    if (!empty($data['remove_pdf'])) {
        $old = Database::fetch("SELECT pdf_path FROM notices WHERE id = ?", [$params['id']]);
        if ($old && $old['pdf_path']) deleteOldPdf($old['pdf_path']);
        $updateData['pdf_path'] = null;
    }
    if (!empty($updateData)) {
        Database::update('notices', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Notice updated');
}, [$adminMw]);

// DELETE /api/admin/notices/{id}
$router->delete('/api/admin/notices/{id}', function (array $params) {
    Auth::requireRole('admin');
    $notice = Database::fetch("SELECT pdf_path FROM notices WHERE id = ?", [$params['id']]);
    if ($notice && $notice['pdf_path']) deleteOldPdf($notice['pdf_path']);
    Database::delete('notices', 'id = ?', [$params['id']]);
    Response::success(null, 'Notice deleted');
}, [$adminMw]);
