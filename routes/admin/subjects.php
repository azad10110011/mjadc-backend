<?php

// GET /api/admin/subjects
$router->get('/api/admin/subjects', function () {
    Auth::requireRole('admin');
    $subjects = Database::fetchAll("SELECT id, name, type, parent_id, created_at FROM subjects ORDER BY name");
    Response::success($subjects);
});

// GET /api/admin/subjects/tree — grouped hierarchy: subject → papers → parts
$router->get('/api/admin/subjects/tree', function () {
    Auth::requireRole('admin');

    $all = Database::fetchAll(
        "SELECT id, name, type, parent_id, created_at FROM subjects ORDER BY type, name"
    );

    $partsBySubject = [];
    $partRows = Database::fetchAll(
        "SELECT id, subject, part_name, full_mark, pass_mark, sort_order FROM subject_parts ORDER BY subject, sort_order"
    );
    foreach ($partRows as $p) {
        $partsBySubject[$p['subject']][] = $p;
    }

    $papers = [];
    $subjects = [];
    foreach ($all as $s) {
        if ($s['parent_id']) {
            $papers[$s['parent_id']][] = $s;
        } else {
            $subjects[] = $s;
        }
    }

    $tree = [];
    foreach ($subjects as $s) {
        $entry = [
            'id' => (int)$s['id'],
            'name' => $s['name'],
            'type' => $s['type'],
            'created_at' => $s['created_at'],
            'papers' => [],
        ];

        $subjectPapers = $papers[$s['id']] ?? [];
        foreach ($subjectPapers as $p) {
            $entry['papers'][] = [
                'id' => (int)$p['id'],
                'name' => $p['name'],
                'type' => $p['type'],
                'parent_id' => (int)$p['parent_id'],
                'created_at' => $p['created_at'],
                'parts' => $partsBySubject[$p['name']] ?? [],
            ];
        }
        $tree[] = $entry;
    }

    Response::success($tree);
});

// POST /api/admin/subjects
$router->post('/api/admin/subjects', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    if ($name === '') {
        Response::error('Subject name is required', 400);
    }

    $type = $data['type'] ?? 'public';
    $allowedTypes = ['public', 'result', 'both'];
    if (!in_array($type, $allowedTypes)) {
        Response::error('Invalid type. Allowed: public, result, both', 400);
    }

    $existing = Database::fetch("SELECT id FROM subjects WHERE name = ?", [$name]);
    if ($existing) {
        Response::error('Subject already exists', 409);
    }

    $insertData = ['name' => $name, 'type' => $type];
    if (isset($data['parent_id'])) {
        $insertData['parent_id'] = (int)$data['parent_id'];
    }

    $id = Database::insert('subjects', $insertData);
    Response::created(['id' => $id, 'name' => $name, 'type' => $type, 'parent_id' => $insertData['parent_id'] ?? null], 'Subject created');
});

// PUT /api/admin/subjects/{id}
$router->put('/api/admin/subjects/{id}', function (array $params) {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);
    $id = (int)$params['id'];

    $updateData = [];
    if (isset($data['name'])) {
        $name = trim($data['name']);
        if ($name === '') {
            Response::error('Subject name cannot be empty', 400);
        }
        $updateData['name'] = $name;
    }
    if (isset($data['type'])) {
        $allowedTypes = ['public', 'result', 'both'];
        if (!in_array($data['type'], $allowedTypes)) {
            Response::error('Invalid type. Allowed: public, result, both', 400);
        }
        $updateData['type'] = $data['type'];
    }
    if (array_key_exists('parent_id', $data)) {
        $updateData['parent_id'] = $data['parent_id'] ? (int)$data['parent_id'] : null;
    }

    if (empty($updateData)) {
        Response::error('No data to update', 400);
    }

    Database::update('subjects', $updateData, 'id = ?', ['id' => $id]);
    Response::success(null, 'Subject updated');
});

// DELETE /api/admin/subjects/{id}
$router->delete('/api/admin/subjects/{id}', function (array $params) {
    Auth::requireRole('admin');

    $id = (int)$params['id'];
    // Set child papers' parent_id to null, then delete
    Database::query("UPDATE subjects SET parent_id = NULL WHERE parent_id = ?", [$id]);
    Database::delete('subjects', 'id = ?', [$id]);
    Response::success(null, 'Subject deleted');
});
