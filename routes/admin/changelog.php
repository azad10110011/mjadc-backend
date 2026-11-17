<?php

// GET /api/admin/changelog?action=&limit=   (Session 77)
// Admin → User History reads result change logs from result_changelog. The page always called this,
// but the endpoint was missing (404 locally and on live).

$router->get('/api/admin/changelog', function () {
    Auth::requireRole('admin');

    $action = trim((string)($_GET['action'] ?? ''));
    $limit = max(1, min(1000, (int)($_GET['limit'] ?? 200)));

    $where = '';
    $params = [];
    if ($action !== '') {
        $where = 'WHERE rc.action = ?';
        $params[] = $action;
    }

    $rows = Database::fetchAll(
        "SELECT rc.id, rc.exam_result_id, rc.action, rc.old_data, rc.new_data, rc.user_id, rc.created_at,
                u.name AS user_name, u.email AS user_email,
                er.subject, s.student_id, e.exam_name, e.class, e.year
         FROM result_changelog rc
         LEFT JOIN users u ON u.id = rc.user_id
         LEFT JOIN exam_results er ON er.id = rc.exam_result_id
         LEFT JOIN students s ON s.id = er.student_id
         LEFT JOIN exams e ON e.id = er.exam_id
         $where
         ORDER BY rc.created_at DESC, rc.id DESC
         LIMIT $limit",
        $params
    );

    foreach ($rows as &$r) {
        $r['old_data'] = $r['old_data'] !== null ? json_decode($r['old_data'], true) : null;
        $r['new_data'] = $r['new_data'] !== null ? json_decode($r['new_data'], true) : null;
    }
    unset($r);

    Response::success($rows);
});
