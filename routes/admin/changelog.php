<?php

// GET /api/admin/changelog?exam_name=&class=&year=&subject=&user_id=&action=&limit=
$router->get('/api/admin/changelog', function () {
    Auth::requireRole('admin');

    $where = [];
    $params = [];

    if (!empty($_GET['user_id'])) {
        $where[] = 'cl.user_id = ?';
        $params[] = $_GET['user_id'];
    }

    if (!empty($_GET['action'])) {
        $where[] = 'cl.action = ?';
        $params[] = $_GET['action'];
    }

    if (!empty($_GET['exam_name']) || !empty($_GET['class']) || !empty($_GET['year']) || !empty($_GET['subject'])) {
        $joinWhere = [];
        if (!empty($_GET['exam_name'])) {
            $joinWhere[] = 'e.exam_name = ?';
            $params[] = $_GET['exam_name'];
        }
        if (!empty($_GET['class'])) {
            $joinWhere[] = 'e.class = ?';
            $params[] = $_GET['class'];
        }
        if (!empty($_GET['year'])) {
            $joinWhere[] = 'e.year = ?';
            $params[] = $_GET['year'];
        }
        if (!empty($_GET['subject'])) {
            $joinWhere[] = 'er.subject = ?';
            $params[] = $_GET['subject'];
        }
        if (!empty($joinWhere)) {
            $where[] = '(' . implode(' AND ', $joinWhere) . ')';
        }
    }

    $whereClause = $where ? 'WHERE ' . implode(' AND ', $where) : '';

    $limit = min((int)($_GET['limit'] ?? 100), 500);

    $logs = Database::fetchAll(
        "SELECT cl.*, u.name as user_name, u.email as user_email,
                er.subject, er.student_id, e.exam_name, e.class, e.year
         FROM result_changelog cl
         LEFT JOIN users u ON cl.user_id = u.id
         LEFT JOIN exam_results er ON cl.exam_result_id = er.id
         LEFT JOIN exams e ON er.exam_id = e.id
         {$whereClause}
         ORDER BY cl.created_at DESC
         LIMIT {$limit}",
        $params
    );

    foreach ($logs as &$log) {
        $log['old_data'] = $log['old_data'] ? json_decode($log['old_data'], true) : null;
        $log['new_data'] = $log['new_data'] ? json_decode($log['new_data'], true) : null;
    }

    Response::success($logs);
});
