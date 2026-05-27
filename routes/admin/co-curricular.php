<?php

$clubSlugMap = [
    'bncc' => 'BNCC',
    'rover-scout' => 'Rover Scout',
    'science-club' => 'Science Club',
    'debating-club' => 'Debating Club',
];

// GET /api/admin/co-curricular/{clubSlug}
$router->get('/api/admin/co-curricular/{clubSlug}', function (array $params) use ($clubSlugMap) {
    Auth::requireRole('admin');
    $club = $clubSlugMap[$params['clubSlug']] ?? null;
    if (!$club) Response::notFound('Invalid club');
    $members = Database::fetchAll("SELECT id, club, name, designation, mobile, photo_path FROM co_curricular WHERE club = ? ORDER BY id", [$club]);
    Response::success($members);
});

// POST /api/admin/co-curricular/{clubSlug}
$router->post('/api/admin/co-curricular/{clubSlug}', function (array $params) use ($clubSlugMap) {
    try {
        Auth::requireRole('admin');
        $club = $clubSlugMap[$params['clubSlug']] ?? null;
        if (!$club) Response::notFound('Invalid club');
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) {
            Response::validationError(['Request body is required']);
        }
        $validator = validate($data);
        $validator->required('name', 'Name')->required('designation', 'Designation');
        if (!$validator->passes()) Response::validationError($validator->errors());

        $id = Database::insert('co_curricular', [
            'club' => $club, 'name' => $data['name'], 'designation' => $data['designation'],
            'mobile' => $data['mobile'] ?? null, 'photo_path' => $data['photo_path'] ?? null,
        ]);
        Response::created(['id' => $id], 'Member added');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

// PUT /api/admin/co-curricular/{clubSlug}/{id}
$router->put('/api/admin/co-curricular/{clubSlug}/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        $data = json_decode(file_get_contents('php://input'), true);
        $updateData = [];
        foreach (['name', 'designation', 'mobile', 'photo_path'] as $f) {
            if (isset($data[$f])) $updateData[$f] = $data[$f];
        }
        if (!empty($updateData)) Database::update('co_curricular', $updateData, 'id = ?', ['id' => $params['id']]);
        Response::success(null, 'Member updated');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});

// DELETE /api/admin/co-curricular/{clubSlug}/{id}
$router->delete('/api/admin/co-curricular/{clubSlug}/{id}', function (array $params) {
    try {
        Auth::requireRole('admin');
        Database::delete('co_curricular', 'id = ?', [$params['id']]);
        Response::success(null, 'Member deleted');
    } catch (\Throwable $e) {
        Response::error('Error: ' . $e->getMessage(), 500);
    }
});
