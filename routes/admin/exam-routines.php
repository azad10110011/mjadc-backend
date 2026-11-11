<?php
// Admin: Exam Routine — date-wise subjects per year/class/exam.
// subjects column stores a JSON array of subject names for that date.
$adminMw = function () { Auth::requireRole('admin'); };

function routineNormalize(array $row): array
{
    $subs = json_decode($row['subjects'] ?? '[]', true);
    return [
        'id' => (int)$row['id'],
        'year' => $row['year'],
        'class' => $row['class'],
        'exam_name' => $row['exam_name'],
        'exam_date' => $row['exam_date'],
        'subjects' => is_array($subs) ? $subs : [],
    ];
}

// GET /api/admin/exam-routines?year=&class=&exam_name=
$router->get('/api/admin/exam-routines', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $exam = $_GET['exam_name'] ?? '';
    $where = [];
    $params = [];
    if ($year !== '') { $where[] = 'year = ?'; $params[] = $year; }
    if ($class !== '') { $where[] = 'class = ?'; $params[] = $class; }
    if ($exam !== '') { $where[] = 'exam_name = ?'; $params[] = $exam; }
    $sql = "SELECT * FROM exam_routines";
    if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
    $sql .= ' ORDER BY exam_date, id';
    $rows = Database::fetchAll($sql, $params);
    Response::success(array_map('routineNormalize', $rows));
}, [$adminMw]);

// POST /api/admin/exam-routines — create one date entry
$router->post('/api/admin/exam-routines', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $year = trim($data['year'] ?? '');
    $class = trim($data['class'] ?? '');
    $exam = trim($data['exam_name'] ?? '');
    $date = trim($data['exam_date'] ?? '');
    $subjects = array_values(array_filter(array_map('trim', (array)($data['subjects'] ?? [])), function ($s) { return $s !== ''; }));
    if (!$year || !$class || !$exam || !$date) Response::validationError(['year, class, exam_name and exam_date are required']);
    if (empty($subjects)) Response::validationError(['subjects' => 'Select at least one subject']);
    $id = Database::insert('exam_routines', [
        'year' => $year,
        'class' => $class,
        'exam_name' => $exam,
        'exam_date' => $date,
        'subjects' => json_encode($subjects),
    ]);
    Response::created(['id' => $id], 'Routine entry added');
}, [$adminMw]);

// PUT /api/admin/exam-routines/{id} — update date/subjects
$router->put('/api/admin/exam-routines/{id}', function (array $params) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $existing = Database::fetch("SELECT * FROM exam_routines WHERE id = ?", [$params['id']]);
    if (!$existing) Response::notFound('Routine entry not found');
    $upd = [];
    if (isset($data['exam_date'])) {
        if (!trim($data['exam_date'])) Response::validationError(['exam_date' => 'Date is required']);
        $upd['exam_date'] = trim($data['exam_date']);
    }
    if (isset($data['subjects'])) {
        $subjects = array_values(array_filter(array_map('trim', (array)$data['subjects']), function ($s) { return $s !== ''; }));
        if (empty($subjects)) Response::validationError(['subjects' => 'Select at least one subject']);
        $upd['subjects'] = json_encode($subjects);
    }
    if (!empty($upd)) Database::update('exam_routines', $upd, 'id = ?', ['id' => $params['id']]);
    Response::success(null, 'Routine entry updated');
}, [$adminMw]);

// DELETE /api/admin/exam-routines/{id}
$router->delete('/api/admin/exam-routines/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('exam_routines', 'id = ?', [$params['id']]);
    Response::success(null, 'Routine entry deleted');
}, [$adminMw]);
