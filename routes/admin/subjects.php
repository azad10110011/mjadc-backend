<?php

// GET /api/admin/subjects/tree – subjects with nested papers and parts
$router->get('/api/admin/subjects/tree', function () {
    Auth::requireRole('admin');
    $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY `group`, name");
    foreach ($subjects as &$s) {
        $papers = Database::fetchAll("SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name", [$s['id']]);
        foreach ($papers as &$p) {
            $p['parts'] = Database::fetchAll("SELECT * FROM subject_parts WHERE subject = ? ORDER BY sort_order", [$p['name']]);
        }
        $s['papers'] = $papers;
        $s['parts'] = Database::fetchAll("SELECT * FROM subject_parts WHERE subject = ? ORDER BY sort_order", [$s['name']]);
    }
    Response::success($subjects);
});

// GET /api/admin/subjects/by-group?group=Science – subjects filtered by group (General group included)
$router->get('/api/admin/subjects/by-group', function () {
    Auth::requireRole('admin');
    $group = $_GET['group'] ?? '';
    if (!$group) {
        $subjects = Database::fetchAll("SELECT * FROM subjects ORDER BY name");
    } else {
        $subjects = Database::fetchAll(
            "SELECT * FROM subjects WHERE `group` = ? OR `group` = 'General' ORDER BY `group`, name",
            [$group]
        );
    }
    $result = [];
    foreach ($subjects as $s) {
        $papers = Database::fetchAll("SELECT * FROM subject_papers WHERE parent_id = ? ORDER BY name", [$s['id']]);
        foreach ($papers as &$p) {
            $p['parts'] = Database::fetchAll("SELECT * FROM subject_parts WHERE subject = ? ORDER BY sort_order", [$p['name']]);
        }
        $s['papers'] = $papers;
        $s['parts'] = Database::fetchAll("SELECT * FROM subject_parts WHERE subject = ? ORDER BY sort_order", [$s['name']]);
        $result[] = $s;
    }
    Response::success($result);
});

// POST /api/admin/subjects – create subject or paper
$router->post('/api/admin/subjects', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    if (!$name) Response::validationError(['name' => 'Name is required']);

    if (!empty($data['parent_id'])) {
        // Creating a paper under a subject
        $insertData = [
            'parent_id' => (int)$data['parent_id'],
            'name' => $name,
        ];
        if (!empty($data['code'])) $insertData['code'] = $data['code'];
        $id = Database::insert('subject_papers', $insertData);
    } else {
        // Creating a new subject
        $type = $data['type'] ?? 'public';
        $insertData = [
            'name' => $name,
            'type' => $type,
        ];
        if (!empty($data['code'])) $insertData['code'] = $data['code'];
        if (!empty($data['group'])) $insertData['group'] = $data['group'];
        $id = Database::insert('subjects', $insertData);
    }
    Response::created(['id' => $id]);
});

// PUT /api/admin/subjects/{id} – rename subject or paper
$router->put('/api/admin/subjects/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $name = trim($data['name'] ?? '');
    if (!$name) Response::validationError(['name' => 'Name is required']);

    $id = (int)$params['id'];
    $updateData = ['name' => $name];
    if (isset($data['code'])) $updateData['code'] = $data['code'];
    if (isset($data['group'])) $updateData['group'] = $data['group'];
    if (isset($data['type'])) $updateData['type'] = $data['type'];
    $updated = Database::update('subjects', $updateData, 'id = ?', ['id' => $id]);
    if ($updated === 0) {
        $paperUpdate = ['name' => $name];
        if (isset($data['code'])) $paperUpdate['code'] = $data['code'];
        Database::update('subject_papers', $paperUpdate, 'id = ?', ['id' => $id]);
    }
    Response::success(null, 'Renamed');
});

// Shared delete logic for subject or paper
$deleteSubjectOrPaper = function (array $params) {
    Auth::requireRole('admin');
    $id = (int)$params['id'];

    // Try deleting a subject
    $subject = Database::fetch("SELECT id, name FROM subjects WHERE id = ?", [$id]);
    if ($subject) {
        // Delete subject-level parts
        Database::delete('subject_parts', 'subject = ?', [$subject['name']]);
        // Delete paper-level parts
        $papers = Database::fetchAll("SELECT name FROM subject_papers WHERE parent_id = ?", [$id]);
        foreach ($papers as $p) {
            Database::delete('subject_parts', 'subject = ?', [$p['name']]);
        }
        Database::delete('subjects', 'id = ?', ['id' => $id]);
        Response::success(null, 'Deleted');
    }

    // Check if this is a paper
    $paper = Database::fetch("SELECT name FROM subject_papers WHERE id = ?", [$id]);
    if ($paper) {
        Database::delete('subject_parts', 'subject = ?', [$paper['name']]);
        Database::delete('subject_papers', 'id = ?', ['id' => $id]);
        Response::success(null, 'Deleted');
    }

    Response::notFound('Subject or paper not found');
};

// DELETE /api/admin/subjects/{id} – delete subject or paper
$router->delete('/api/admin/subjects/{id}', $deleteSubjectOrPaper);
// POST fallback for servers that block DELETE
$router->post('/api/admin/subjects/{id}', $deleteSubjectOrPaper);

// GET /api/admin/subject-parts – get parts for a paper
$router->get('/api/admin/subject-parts', function () {
    Auth::requireRole('admin');
    $subject = $_GET['subject'] ?? '';
    if (!$subject) Response::success([]);
    $parts = Database::fetchAll("SELECT * FROM subject_parts WHERE subject = ? ORDER BY sort_order", [$subject]);
    Response::success($parts);
});

// POST /api/admin/subject-parts – create a part
$router->post('/api/admin/subject-parts', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    if (empty($data['subject']) || empty($data['part_name'])) {
        Response::validationError(['subject' => 'Subject and part_name are required']);
    }
    $id = Database::insert('subject_parts', [
        'subject' => $data['subject'],
        'part_name' => $data['part_name'],
        'full_mark' => (float)($data['full_mark'] ?? 0),
        'pass_mark' => (float)($data['pass_mark'] ?? 0),
        'sort_order' => (int)($data['sort_order'] ?? 0),
    ]);
    Response::created(['id' => $id]);
});

// PUT /api/admin/subject-parts/{id} – update a part
$router->put('/api/admin/subject-parts/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $updateData = [];
    if (isset($data['full_mark'])) $updateData['full_mark'] = (float)$data['full_mark'];
    if (isset($data['pass_mark'])) $updateData['pass_mark'] = (float)$data['pass_mark'];
    if (isset($data['sort_order'])) $updateData['sort_order'] = (int)$data['sort_order'];
    if (isset($data['part_name'])) $updateData['part_name'] = $data['part_name'];
    if (empty($updateData)) Response::success(null, 'No changes');
    Database::update('subject_parts', $updateData, 'id = ?', ['id' => (int)$params['id']]);
    Response::success(null, 'Updated');
});

// DELETE /api/admin/subject-parts/{id} – delete a part
$deletePart = function (array $params) {
    Auth::requireRole('admin');
    Database::delete('subject_parts', 'id = ?', ['id' => (int)$params['id']]);
    Response::success(null, 'Deleted');
};
$router->delete('/api/admin/subject-parts/{id}', $deletePart);
$router->post('/api/admin/subject-parts/{id}', $deletePart);

// POST /api/admin/subject-parts/init-defaults – add default MCQs/CQ/Practical to all subjects without parts
$router->post('/api/admin/subject-parts/init-defaults', function () {
    Auth::requireRole('admin');
    $papers = Database::fetchAll("SELECT DISTINCT sp.name FROM subject_papers sp LEFT JOIN subject_parts pt ON pt.subject = sp.name WHERE pt.id IS NULL");
    $count = 0;
    $defaults = [
        ['part_name' => 'mcq', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 1],
        ['part_name' => 'cq', 'full_mark' => 50, 'pass_mark' => 17, 'sort_order' => 2],
        ['part_name' => 'practical', 'full_mark' => 50, 'pass_mark' => 8, 'sort_order' => 3],
    ];
    foreach ($papers as $paper) {
        foreach ($defaults as $def) {
            Database::insert('subject_parts', array_merge($def, ['subject' => $paper['name']]));
            $count++;
        }
    }
    Response::success(['initialized' => $count]);
});
