<?php

/**
 * Private image storage (Session 123) — for records that must not be public (student money receipts,
 * admission forms, teachers' result sheets). Files live in uploads/private/<area>/ which denies direct
 * web access (.htaccess) and is refused by index.php's uploads shortcut; they are sent only through
 * authenticated API endpoints via privateServe().
 */

if (!function_exists('privateDir')) {

    function privateRoot(): string
    {
        $root = __DIR__ . '/../uploads/private';
        if (!is_dir($root)) @mkdir($root, 0755, true);
        $ht = $root . '/.htaccess';
        if (!is_file($ht)) {
            @file_put_contents($ht, "# Private files: never served directly (Session 123)\n<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n<IfModule !mod_authz_core.c>\n    Deny from all\n</IfModule>\nOptions -Indexes\n");
        }
        return $root;
    }

    function privateDir(string $area): string
    {
        $area = preg_replace('/[^a-z0-9_-]/i', '', $area);
        $dir = privateRoot() . '/' . $area;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    /**
     * Validate + store an uploaded image. Returns the stored path relative to the backend root
     * (e.g. "uploads/private/student-docs/s12_1700000000_ab12cd.jpg"); exits with 422 on a bad file.
     */
    function privateSaveImage(?array $file, string $area, string $prefix, int $maxMb = 8): string
    {
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::validationError(['image' => 'Choose a picture']);
        if ($file['size'] > $maxMb * 1024 * 1024) Response::validationError(['image' => "Picture must be {$maxMb} MB or smaller"]);
        $info = @getimagesize($file['tmp_name']);
        $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
        if (!$ext) Response::validationError(['image' => 'Use a JPG, PNG or WebP picture']);
        $name = preg_replace('/[^a-z0-9_-]/i', '', $prefix) . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        if (!move_uploaded_file($file['tmp_name'], privateDir($area) . '/' . $name)) Response::error('Could not save the picture', 500);
        return 'uploads/private/' . preg_replace('/[^a-z0-9_-]/i', '', $area) . '/' . $name;
    }

    /** Stream a stored private file (caller has already checked permission). */
    function privateServe(string $rel): void
    {
        if (strpos($rel, 'uploads/private/') !== 0 || strpos($rel, '..') !== false) Response::notFound('File not found');
        $path = __DIR__ . '/../' . $rel;
        if (!is_file($path)) Response::notFound('File not found');
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $mime = ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream';
        while (ob_get_level()) ob_end_clean();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, max-age=3600');
        header('X-Content-Type-Options: nosniff');
        readfile($path);
        exit;
    }

    function privateDelete(?string $rel): void
    {
        if ($rel && strpos($rel, 'uploads/private/') === 0 && strpos($rel, '..') === false) @unlink(__DIR__ . '/../' . $rel);
    }
}
