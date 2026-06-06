<?php

// GET /api/media/serve?path=uploads/gallery/filename.jpg
$router->get('/api/media/serve', function () {
    $path = $_GET['path'] ?? '';
    $path = ltrim($path, '/');
    $path = str_replace('\\', '/', $path);

    // Security: prevent directory traversal
    if (strpos($path, '..') !== false) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    // Only allow files in uploads/ directory
    if (strpos($path, 'uploads/') !== 0) {
        http_response_code(403);
        echo 'Forbidden';
        return;
    }

    $fullPath = __DIR__ . '/../../' . $path;

    if (!file_exists($fullPath) || is_dir($fullPath)) {
        http_response_code(404);
        echo 'Not Found';
        return;
    }

    $ext = strtolower(pathinfo($fullPath, PATHINFO_EXTENSION));
    $mimeTypes = [
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    ];

    $mime = $mimeTypes[$ext] ?? 'application/octet-stream';

    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($fullPath));
    header('Cache-Control: public, max-age=31536000');
    header('Expires: ' . gmdate('D, d M Y H:i:s', time() + 31536000) . ' GMT');
    header('Pragma: cache');

    readfile($fullPath);
});
