<?php
// Public: subjects with codes for entry forms.
// For each public/both subject: list all its paper codes joined with "_" ;
// if the subject has no papers, use the subject's own code.
$router->get('/api/subjects-with-codes', function () {
    $rows = Database::fetchAll(
        "SELECT s.id, s.name, s.`group`, s.code AS subject_code,
                GROUP_CONCAT(sp.code ORDER BY sp.id SEPARATOR '_') AS paper_codes
         FROM subjects s
         LEFT JOIN subject_papers sp ON sp.parent_id = s.id
         WHERE s.type IN ('public','both')
         GROUP BY s.id, s.name, s.`group`, s.code
         ORDER BY s.`group`, s.name"
    );
    $out = array_map(function ($r) {
        $codes = $r['paper_codes'] ?: $r['subject_code'];
        return [
            'id' => (int)$r['id'],
            'name' => $r['name'],
            'group' => $r['group'],
            'codes' => $codes ?: '',
            'label' => ($codes ? $codes . ' ' : '') . $r['name'],
        ];
    }, $rows);
    Response::success($out);
});
