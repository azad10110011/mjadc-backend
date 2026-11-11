<?php

// GET /api/admin/exam-subject-config?exam_id=X
$router->get('/api/admin/exam-subject-config', function () {
    Auth::requireRole('admin');

    $examId = $_GET['exam_id'] ?? '';
    if (!$examId) {
        Response::validationError(['exam_id' => 'exam_id is required']);
    }

    $configs = Database::fetchAll(
        "SELECT esc.*, s.name as subject_name, sp.name as paper_name
         FROM exam_subject_config esc
         JOIN subjects s ON esc.subject_id = s.id
         LEFT JOIN subject_papers sp ON esc.selected_paper_id = sp.id
         WHERE esc.exam_id = ?
         ORDER BY s.name",
        [$examId]
    );
    Response::success($configs);
});

// POST /api/admin/exam-subject-config  (upsert)
$router->post('/api/admin/exam-subject-config', function () {
    Auth::requireRole('admin');

    $data = json_decode(file_get_contents('php://input'), true);

    $examId = $data['exam_id'] ?? null;
    $subjectId = $data['subject_id'] ?? null;
    $paperSelection = $data['paper_selection'] ?? 'all';
    $selectedPaperId = !empty($data['selected_paper_id']) ? (int)$data['selected_paper_id'] : null;
    $calculationMode = $data['calculation_mode'] ?? 'separate';

    if (!$examId || !$subjectId) {
        Response::validationError(['exam_id' => 'Exam and subject are required']);
    }

    if (!in_array($paperSelection, ['single', 'all'])) {
        Response::validationError(['paper_selection' => 'Invalid paper_selection']);
    }

    if ($paperSelection === 'single' && !$selectedPaperId) {
        Response::validationError(['selected_paper_id' => 'Selected paper is required when paper_selection is single']);
    }

    if (!in_array($calculationMode, ['separate', 'average'])) {
        Response::validationError(['calculation_mode' => 'Invalid calculation_mode']);
    }

    // Upsert
    $existing = Database::fetch(
        "SELECT id FROM exam_subject_config WHERE exam_id = ? AND subject_id = ?",
        [$examId, $subjectId]
    );

    if ($existing) {
        Database::update('exam_subject_config', [
            'paper_selection' => $paperSelection,
            'selected_paper_id' => $selectedPaperId,
            'calculation_mode' => $calculationMode,
        ], 'id = ?', ['id' => $existing['id']]);
        Response::success(null, 'Config updated');
    } else {
        Database::insert('exam_subject_config', [
            'exam_id' => $examId,
            'subject_id' => $subjectId,
            'paper_selection' => $paperSelection,
            'selected_paper_id' => $selectedPaperId,
            'calculation_mode' => $calculationMode,
        ]);
        Response::created(null, 'Config created');
    }
});

// GET /api/admin/exam-subject-config/exam-id?year=&class=&exam_name=
$router->get('/api/admin/exam-subject-config/exam-id', function () {
    Auth::requireRole('admin');

    $year = $_GET['year'] ?? '';
    $class = $_GET['class'] ?? '';
    $examName = $_GET['exam_name'] ?? '';

    if (!$year || !$class || !$examName) {
        Response::validationError(['year, class, exam_name are required']);
    }

    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }
    Response::success(['exam_id' => $exam['id']]);
});

// DELETE /api/admin/exam-subject-config/{id}
$router->delete('/api/admin/exam-subject-config/{id}', function (array $params) {
    Auth::requireRole('admin');
    Database::delete('exam_subject_config', 'id = ?', [$params['id']]);
    Response::success(null, 'Config deleted');
});
