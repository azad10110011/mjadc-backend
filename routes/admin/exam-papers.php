<?php
// Admin exam paper selection per exam
// GET  /api/admin/exam-papers?year=&class=&exam_name=
//      -> { configured: bool, selections: [{ subject_id, paper_id, paper_name, selection_key, display_mode }] }
// PUT  /api/admin/exam-papers  { year, class, exam_name, selections: [{ subject_id, paper_id, display_mode }] }

$router->get('/api/admin/exam-papers', function () {
    Auth::requireAnyRole(['admin', 'exam_controller']);
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
        Response::success(['configured' => false, 'selections' => []]);
    }
    $rows = Database::fetchAll(
        "SELECT esc.*, sp.name AS paper_name, sp.code AS paper_code, s.name AS subject_name, s.code AS subject_code
         FROM exam_subject_config esc
         LEFT JOIN subject_papers sp ON esc.selected_paper_id = sp.id
         LEFT JOIN subjects s ON esc.subject_id = s.id
         WHERE esc.exam_id = ?",
        [$exam['id']]
    );
    $selections = [];
    foreach ($rows as $row) {
        if ($row['paper_selection'] === 'single') {
            if ($row['selected_paper_id'] !== null) {
                $selections[] = [
                    'subject_id' => (int)$row['subject_id'],
                    'paper_id' => (int)$row['selected_paper_id'],
                    'paper_name' => $row['paper_name'],
                    'paper_code' => $row['paper_code'],
                    'subject_name' => $row['subject_name'],
                    'selection_key' => 'paper-' . $row['selected_paper_id'],
                    'display_mode' => 'separate',
                ];
            } else {
                // Whole subject stored (subject with no papers)
                $selections[] = [
                    'subject_id' => (int)$row['subject_id'],
                    'paper_id' => null,
                    'paper_name' => null,
                    'selection_key' => 'subject-' . $row['subject_id'],
                    'display_mode' => 'separate',
                ];
            }
        } else {
            // 'all' -> every paper of the subject is included
            $papers = Database::fetchAll(
                "SELECT id FROM subject_papers WHERE parent_id = ? ORDER BY name",
                [$row['subject_id']]
            );
            foreach ($papers as $p) {
                $selections[] = [
                    'subject_id' => (int)$row['subject_id'],
                    'paper_id' => (int)$p['id'],
                    'paper_name' => null,
                    'selection_key' => 'paper-' . $p['id'],
                    'display_mode' => $row['calculation_mode'] === 'average' ? 'average' : 'separate',
                ];
            }
        }
    }
    Response::success([
        'configured' => count($rows) > 0,
        'selections' => $selections,
    ]);
});

$router->put('/api/admin/exam-papers', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $year = $data['year'] ?? '';
    $class = $data['class'] ?? '';
    $examName = $data['exam_name'] ?? '';
    $selections = $data['selections'] ?? [];
    if (!$year || !$class || !$examName) {
        Response::validationError(['year, class, exam_name are required']);
    }
    if (!is_array($selections)) {
        Response::validationError(['selections' => 'selections must be an array']);
    }
    $exam = Database::fetch(
        "SELECT id FROM exams WHERE year = ? AND class = ? AND exam_name = ?",
        [$year, $class, $examName]
    );
    if (!$exam) {
        Response::notFound('Exam not found');
    }
    $examId = $exam['id'];

    // Group selections by subject
    $bySubject = [];
    foreach ($selections as $sel) {
        $subjectId = (int)($sel['subject_id'] ?? 0);
        $paperId = isset($sel['paper_id']) && $sel['paper_id'] !== null ? (int)$sel['paper_id'] : null;
        $mode = (($sel['display_mode'] ?? 'separate') === 'average') ? 'average' : 'separate';
        if (!$subjectId) continue;
        if ($paperId) {
            // Verify paper belongs to this subject
            $paper = Database::fetch(
                "SELECT id FROM subject_papers WHERE id = ? AND parent_id = ?",
                [$paperId, $subjectId]
            );
            if (!$paper) continue;
            $bySubject[$subjectId]['papers'][] = $paperId;
            $bySubject[$subjectId]['mode'] = $mode;
        } else {
            // Whole subject (subject with no papers)
            $bySubject[$subjectId]['papers'] = $bySubject[$subjectId]['papers'] ?? [];
            $bySubject[$subjectId]['mode'] = 'separate';
            $bySubject[$subjectId]['whole_subject'] = true;
        }
    }

    Database::delete('exam_subject_config', 'exam_id = ?', [$examId]);
    foreach ($bySubject as $subjectId => $info) {
        $paperIds = $info['papers'];
        if (count($paperIds) > 1) {
            Database::insert('exam_subject_config', [
                'exam_id' => $examId,
                'subject_id' => $subjectId,
                'paper_selection' => 'all',
                'selected_paper_id' => null,
                'calculation_mode' => $info['mode'] === 'average' ? 'average' : 'separate',
            ]);
        } elseif (count($paperIds) === 1) {
            Database::insert('exam_subject_config', [
                'exam_id' => $examId,
                'subject_id' => $subjectId,
                'paper_selection' => 'single',
                'selected_paper_id' => $paperIds[0],
                'calculation_mode' => 'separate',
            ]);
        } elseif (!empty($info['whole_subject'])) {
            // Subject without papers: selected subject row itself counts
            Database::insert('exam_subject_config', [
                'exam_id' => $examId,
                'subject_id' => $subjectId,
                'paper_selection' => 'single',
                'selected_paper_id' => null,
                'calculation_mode' => 'separate',
            ]);
        }
        // count(paperIds) === 0 without whole_subject flag -> subject not selected, skip
    }
    Response::success(null, 'Exam paper configuration saved');
});
