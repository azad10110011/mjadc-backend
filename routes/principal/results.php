<?php

// GET /api/principal/results/publish
$router->get('/api/principal/results/publish', function () {
    Auth::requireRole('principal');

    $departments = ['Science', 'Business Studies', 'Humanities'];

    $data = [];
    foreach ($departments as $dept) {
        $results = Database::fetchAll(
            "SELECT s.student_id, s.name, AVG(er.total) as total_mark, 
                    AVG(er.gpa) as gpa, er.status
             FROM exam_results er
             JOIN students s ON er.student_id = s.id
             JOIN exams e ON er.exam_id = e.id
             JOIN teachers t ON t.subject = er.subject
             WHERE er.status = 'approved' AND t.subject IN (
                 SELECT subject FROM teachers WHERE 
                 (CASE WHEN ? = 'Science' THEN subject IN ('Physics','Chemistry','Biology','Higher Math')
                  WHEN ? = 'Business Studies' THEN subject IN ('Management','Marketing','Accounting')
                  WHEN ? = 'Humanities' THEN subject IN ('Bangla','English','History','Economics')
                 END)
             )
             GROUP BY s.student_id, s.name, er.status
             ORDER BY AVG(er.gpa) DESC",
            [$dept, $dept, $dept]
        );

        $data[] = [
            'department' => $dept,
            'results' => $results,
        ];
    }

    Response::success($data);
});

// POST /api/principal/results/publish
$router->post('/api/principal/results/publish', function () {
    $user = Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);
    $examName = $data['exam_name'] ?? '';
    $department = $data['department'] ?? '';

    if (!$examName) {
        Response::validationError(['exam_name is required']);
    }

    $updated = Database::query(
        "UPDATE exam_results er 
         JOIN exams e ON er.exam_id = e.id 
         SET er.status = 'published', er.published_at = NOW()
         WHERE e.exam_name = ? AND er.status = 'approved'",
        [$examName]
    );

    Response::success(['updated' => $updated->rowCount()], 'Results published');
});

// POST /api/principal/results/back-to-exam-controller
$router->post('/api/principal/results/back-to-exam-controller', function () {
    Auth::requireRole('principal');

    $data = json_decode(file_get_contents('php://input'), true);
    $examName = $data['exam_name'] ?? '';
    $subject = $data['subject'] ?? '';

    $updated = Database::query(
        "UPDATE exam_results er 
         JOIN exams e ON er.exam_id = e.id 
         SET er.status = 'draft' 
         WHERE e.exam_name = ? AND er.subject = ? AND er.status = 'approved'",
        [$examName, $subject]
    );

    Response::success(['updated' => $updated->rowCount()], 'Results returned to Exam Controller');
});
