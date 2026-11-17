<?php

// Board Exam Management (Session 99) — admin only (not grantable).
// One board exam (e.g. "HSC Examination 2026") owns: venues → rooms (room names unique per venue only),
// candidates imported from other colleges, a routine, ONE fixed seat per candidate for the whole exam,
// and hall duties (see board-duty.php). External teachers are a shared list reused across exams.
//
//   Exams      GET/POST /api/admin/board-exams · PUT/DELETE /api/admin/board-exams/{id}
//              POST /api/admin/board-exams/{id}/copy-venues { from_exam_id }
//   Venues     GET/POST /api/admin/board-exams/{exam}/venues · PUT/DELETE /api/admin/board-venues/{id}
//   Rooms      POST /api/admin/board-venues/{venue}/rooms · PUT/DELETE /api/admin/board-rooms/{id}
//   Candidates GET/DELETE /api/admin/board-exams/{exam}/candidates · POST …/candidates/import · DELETE /api/admin/board-candidates/{id}
//   Routine    GET/POST /api/admin/board-exams/{exam}/routine · DELETE /api/admin/board-routine/{id}
//   Seats      POST /api/admin/board-exams/{exam}/seats/generate · GET …/seats · GET …/attendance?date=&time=
//   External   GET/POST /api/admin/board-external-teachers · PUT/DELETE /api/admin/board-external-teachers/{id}

if (!function_exists('boardEnsureSchema')) {
function boardEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    Database::query("CREATE TABLE IF NOT EXISTS board_exams (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        year INT NOT NULL,
        center_name VARCHAR(200) NULL,
        center_code VARCHAR(50) NULL,
        seat_mode VARCHAR(20) NOT NULL DEFAULT 'college_mixed',
        per_invigilator INT NOT NULL DEFAULT 0,
        seat_warnings TEXT NULL,
        seats_generated_at DATETIME NULL,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_board_exam (name, year)
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_venues (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        name VARCHAR(150) NOT NULL,
        address VARCHAR(255) NULL,
        colleges TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_board_venue (exam_id, name),
        CONSTRAINT fk_bv_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        venue_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        columns_count INT NOT NULL DEFAULT 3,
        benches_per_column INT NOT NULL DEFAULT 10,
        column_benches TEXT NULL,
        door_position VARCHAR(20) NOT NULL DEFAULT 'front-right',
        seats_per_bench INT NOT NULL DEFAULT 2,
        gender_mode VARCHAR(10) NOT NULL DEFAULT 'mixed',
        invigilators INT NOT NULL DEFAULT 2,
        teacher_gender VARCHAR(10) NOT NULL DEFAULT 'any',
        notes TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_board_room (venue_id, name),
        CONSTRAINT fk_br_venue FOREIGN KEY (venue_id) REFERENCES board_venues(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_candidates (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        roll_no VARCHAR(30) NOT NULL,
        reg_no VARCHAR(30) NULL,
        name VARCHAR(150) NOT NULL,
        college VARCHAR(200) NOT NULL DEFAULT '',
        group_name VARCHAR(60) NULL,
        gender VARCHAR(10) NULL,
        candidate_type VARCHAR(30) NULL,
        subjects TEXT NULL,
        UNIQUE KEY uniq_board_roll (exam_id, roll_no),
        KEY idx_board_college (exam_id, college),
        CONSTRAINT fk_bc_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_routine (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        exam_date DATE NOT NULL,
        exam_time VARCHAR(20) NOT NULL DEFAULT '',
        subject_code VARCHAR(20) NOT NULL DEFAULT '',
        subject_name VARCHAR(150) NOT NULL DEFAULT '',
        UNIQUE KEY uniq_board_routine (exam_id, exam_date, exam_time, subject_code, subject_name),
        CONSTRAINT fk_brt_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_seats (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        room_id INT NOT NULL,
        seat_no INT NOT NULL,
        col_no INT NOT NULL,
        bench_no INT NOT NULL,
        seat_pos INT NOT NULL,
        candidate_id INT NOT NULL,
        UNIQUE KEY uniq_bs_candidate (exam_id, candidate_id),
        UNIQUE KEY uniq_bs_seat (room_id, col_no, bench_no, seat_pos),
        CONSTRAINT fk_bs_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE,
        CONSTRAINT fk_bs_room FOREIGN KEY (room_id) REFERENCES board_rooms(id) ON DELETE CASCADE,
        CONSTRAINT fk_bs_candidate FOREIGN KEY (candidate_id) REFERENCES board_candidates(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_external_teachers (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        college VARCHAR(200) NOT NULL DEFAULT '',
        designation VARCHAR(100) NULL,
        subject VARCHAR(150) NULL,
        mobile VARCHAR(30) NULL,
        email VARCHAR(150) NULL,
        gender VARCHAR(10) NOT NULL DEFAULT 'male',
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) $opt");
    $done = true;
}}

if (!function_exists('boardBody')) {
function boardBody(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }
}

if (!function_exists('boardStr')) {
function boardStr($v, int $max): string { return mb_substr(trim(preg_replace('/\s+/', ' ', (string)$v)), 0, $max); }
}

if (!function_exists('boardLoadExam')) {
function boardLoadExam(int $id): array
{
    boardEnsureSchema();
    $e = Database::fetch("SELECT * FROM board_exams WHERE id = ?", [$id]);
    if (!$e) Response::notFound('Board exam not found');
    $e['id'] = (int)$e['id'];
    $e['year'] = (int)$e['year'];
    $e['per_invigilator'] = (int)$e['per_invigilator'];
    $e['seat_warnings'] = json_decode((string)$e['seat_warnings'], true) ?: [];
    return $e;
}}

/** Subject tokens of a candidate: lower-case codes / names from the import. */
if (!function_exists('boardSubjectTokens')) {
function boardSubjectTokens($subjects): array
{
    $list = is_array($subjects) ? $subjects : (json_decode((string)$subjects, true) ?: []);
    $out = [];
    foreach ($list as $s) { $t = mb_strtolower(trim((string)$s)); if ($t !== '') $out[$t] = true; }
    return $out;
}}

if (!function_exists('boardRoomOut')) {
function boardRoomOut(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['venue_id'] = (int)$r['venue_id'];
    $r['enabled'] = (bool)$r['enabled'];
    foreach (['columns_count', 'benches_per_column', 'seats_per_bench', 'invigilators', 'sort_order'] as $k) $r[$k] = (int)$r[$k];
    $cb = array_values(array_map('intval', json_decode((string)($r['column_benches'] ?? ''), true) ?: []));
    if (count($cb) !== $r['columns_count']) $cb = array_fill(0, max(0, $r['columns_count']), $r['benches_per_column']);
    $r['column_benches'] = $cb;
    $r['total_benches'] = array_sum($cb);
    $r['capacity'] = $r['total_benches'] * $r['seats_per_bench'];
    return $r;
}}

if (!function_exists('boardValidateRoom')) {
function boardValidateRoom(array $d, int $venueId, ?int $id = null): array
{
    $errors = [];
    $name = boardStr($d['name'] ?? '', 100);
    if ($name === '') $errors[] = 'Room number / name is required';
    $clean = ['name' => $name];
    foreach (['seats_per_bench' => [1, 10], 'invigilators' => [0, 20]] as $k => [$min, $max]) {
        $v = filter_var($d[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) $errors[] = str_replace('_', ' ', $k) . " must be a whole number from $min to $max";
        else $clean[$k] = $v;
    }
    $cb = array_values((array)($d['column_benches'] ?? []));
    if (count($cb) < 1 || count($cb) > 30) $errors[] = 'A room needs 1 to 30 columns';
    else {
        foreach ($cb as $i => $v) {
            $v = filter_var($v, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1 || $v > 60) { $errors[] = 'Column ' . ($i + 1) . ': benches must be 1 to 60'; continue; }
            $cb[$i] = $v;
        }
        $clean['column_benches'] = json_encode(array_map('intval', $cb));
        $clean['columns_count'] = count($cb);
        $clean['benches_per_column'] = max(array_map('intval', $cb));
    }
    $clean['door_position'] = in_array($d['door_position'] ?? '', seatPlanDoorPositions(), true) ? $d['door_position'] : 'front-right';
    $clean['gender_mode'] = in_array($d['gender_mode'] ?? '', ['mixed', 'male', 'female', 'separate'], true) ? $d['gender_mode'] : 'mixed';
    $clean['teacher_gender'] = in_array($d['teacher_gender'] ?? '', ['any', 'male', 'female', 'mixed'], true) ? $d['teacher_gender'] : 'any';
    $clean['enabled'] = !empty($d['enabled']) ? 1 : 0;
    $clean['notes'] = mb_substr(trim((string)($d['notes'] ?? '')), 0, 2000);
    $clean['sort_order'] = (int)($d['sort_order'] ?? 0);
    if ($name !== '' && Database::fetch("SELECT id FROM board_rooms WHERE venue_id = ? AND name = ?" . ($id ? " AND id <> ?" : ''), $id ? [$venueId, $name, $id] : [$venueId, $name])) {
        $errors[] = "This venue already has a room \"$name\"";
    }
    if ($errors) Response::validationError($errors);
    return $clean;
}}

/** Venues of an exam with their rooms (in seating order). */
if (!function_exists('boardVenues')) {
function boardVenues(int $examId): array
{
    $venues = Database::fetchAll("SELECT * FROM board_venues WHERE exam_id = ? ORDER BY sort_order, name", [$examId]);
    $rooms = [];
    if ($venues) {
        $ids = implode(',', array_map(fn($v) => (int)$v['id'], $venues));
        foreach (Database::fetchAll("SELECT * FROM board_rooms WHERE venue_id IN ($ids) ORDER BY sort_order, name") as $r) $rooms[(int)$r['venue_id']][] = boardRoomOut($r);
    }
    foreach ($venues as &$v) {
        $v['id'] = (int)$v['id'];
        $v['sort_order'] = (int)$v['sort_order'];
        $v['colleges'] = json_decode((string)$v['colleges'], true) ?: [];
        $v['rooms'] = $rooms[$v['id']] ?? [];
        $v['capacity'] = array_sum(array_map(fn($r) => $r['enabled'] ? $r['capacity'] : 0, $v['rooms']));
    }
    unset($v);
    return $venues;
}}

// ================================================================== exams
$router->get('/api/admin/board-exams', function () {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $rows = Database::fetchAll(
        "SELECT e.*, (SELECT COUNT(*) FROM board_venues v WHERE v.exam_id = e.id) AS venues,
                (SELECT COUNT(*) FROM board_rooms r JOIN board_venues v ON v.id = r.venue_id WHERE v.exam_id = e.id) AS rooms,
                (SELECT COUNT(*) FROM board_candidates c WHERE c.exam_id = e.id) AS candidates,
                (SELECT COUNT(*) FROM board_seats s WHERE s.exam_id = e.id) AS seated,
                (SELECT COUNT(DISTINCT exam_date, exam_time) FROM board_routine t WHERE t.exam_id = e.id) AS sessions
         FROM board_exams e ORDER BY e.year DESC, e.id DESC");
    foreach ($rows as &$r) {
        foreach (['id', 'year', 'per_invigilator', 'venues', 'rooms', 'candidates', 'seated', 'sessions'] as $k) $r[$k] = (int)$r[$k];
        $r['seat_warnings'] = json_decode((string)$r['seat_warnings'], true) ?: [];
    }
    unset($r);
    Response::success($rows);
});

if (!function_exists('boardValidateExam')) {
function boardValidateExam(array $d, ?int $id = null): array
{
    $errors = [];
    $name = boardStr($d['name'] ?? '', 150);
    $year = filter_var($d['year'] ?? null, FILTER_VALIDATE_INT);
    if ($name === '') $errors[] = 'Exam name is required (e.g. HSC Examination)';
    if ($year === false || $year < 2000 || $year > 2100) $errors[] = 'Enter a valid year';
    $per = filter_var($d['per_invigilator'] ?? 0, FILTER_VALIDATE_INT);
    if ($per === false || $per < 0 || $per > 200) $errors[] = 'Candidates per invigilator must be 0–200 (0 = use each room\'s number)';
    if (!$errors && Database::fetch("SELECT id FROM board_exams WHERE name = ? AND year = ?" . ($id ? " AND id <> ?" : ''), $id ? [$name, $year, $id] : [$name, $year])) {
        $errors[] = "$name $year already exists";
    }
    if ($errors) Response::validationError($errors);
    return [
        'name' => $name, 'year' => $year,
        'center_name' => boardStr($d['center_name'] ?? '', 200) ?: null,
        'center_code' => boardStr($d['center_code'] ?? '', 50) ?: null,
        'seat_mode' => in_array($d['seat_mode'] ?? '', ['roll', 'college_mixed'], true) ? $d['seat_mode'] : 'college_mixed',
        'per_invigilator' => $per,
        'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 2000) ?: null,
    ];
}}

$router->post('/api/admin/board-exams', function () {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $clean = boardValidateExam(boardBody());
    $id = Database::insert('board_exams', $clean);
    Response::success(boardLoadExam((int)$id), "Board exam created: {$clean['name']} {$clean['year']}");
});

$router->put('/api/admin/board-exams/{id}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['id']);
    $clean = boardValidateExam(boardBody(), $exam['id']);
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
    Database::query("UPDATE board_exams SET $sets WHERE id = ?", [...array_values($clean), $exam['id']]);
    Response::success(boardLoadExam($exam['id']), 'Board exam saved');
});

$router->delete('/api/admin/board-exams/{id}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['id']);
    Database::query("DELETE FROM board_exams WHERE id = ?", [$exam['id']]); // venues, rooms, candidates, seats, duties cascade
    Response::success(null, "{$exam['name']} {$exam['year']} deleted with its venues, candidates, seats and duties");
});

// Copy venues + rooms from another board exam (e.g. last year's) — skips venues that already exist
$router->post('/api/admin/board-exams/{id}/copy-venues', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['id']);
    $from = boardLoadExam((int)(boardBody()['from_exam_id'] ?? 0));
    if ($from['id'] === $exam['id']) Response::validationError(['Choose a different exam to copy from']);
    $nv = 0; $nr = 0;
    foreach (boardVenues($from['id']) as $v) {
        if (Database::fetch("SELECT id FROM board_venues WHERE exam_id = ? AND name = ?", [$exam['id'], $v['name']])) continue;
        $vid = Database::insert('board_venues', ['exam_id' => $exam['id'], 'name' => $v['name'], 'address' => $v['address'], 'colleges' => json_encode([]), 'sort_order' => $v['sort_order']]);
        $nv++;
        foreach ($v['rooms'] as $r) {
            Database::insert('board_rooms', [
                'venue_id' => $vid, 'name' => $r['name'], 'enabled' => $r['enabled'] ? 1 : 0, 'columns_count' => $r['columns_count'],
                'benches_per_column' => $r['benches_per_column'], 'column_benches' => json_encode($r['column_benches']),
                'door_position' => $r['door_position'], 'seats_per_bench' => $r['seats_per_bench'], 'gender_mode' => $r['gender_mode'],
                'invigilators' => $r['invigilators'], 'teacher_gender' => $r['teacher_gender'], 'notes' => $r['notes'], 'sort_order' => $r['sort_order'],
            ]);
            $nr++;
        }
    }
    Response::success(['venues' => $nv, 'rooms' => $nr], "Copied $nv venue(s) and $nr room(s) from {$from['name']} {$from['year']}");
});

// ================================================================== venues + rooms
$router->get('/api/admin/board-exams/{exam}/venues', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $colleges = array_column(Database::fetchAll("SELECT college, COUNT(*) n FROM board_candidates WHERE exam_id = ? GROUP BY college ORDER BY college", [$exam['id']]), 'n', 'college');
    Response::success(['exam' => $exam, 'venues' => boardVenues($exam['id']), 'colleges' => $colleges]);
});

if (!function_exists('boardValidateVenue')) {
function boardValidateVenue(array $d, int $examId, ?int $id = null): array
{
    $name = boardStr($d['name'] ?? '', 150);
    if ($name === '') Response::validationError(['Venue name is required']);
    if (Database::fetch("SELECT id FROM board_venues WHERE exam_id = ? AND name = ?" . ($id ? " AND id <> ?" : ''), $id ? [$examId, $name, $id] : [$examId, $name])) {
        Response::validationError(["A venue named \"$name\" already exists in this exam"]);
    }
    $colleges = array_values(array_unique(array_filter(array_map(fn($c) => boardStr($c, 200), (array)($d['colleges'] ?? [])), fn($c) => $c !== '')));
    return ['name' => $name, 'address' => boardStr($d['address'] ?? '', 255) ?: null, 'colleges' => json_encode($colleges, JSON_UNESCAPED_UNICODE), 'sort_order' => (int)($d['sort_order'] ?? 0)];
}}

$router->post('/api/admin/board-exams/{exam}/venues', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $clean = boardValidateVenue(boardBody(), $exam['id']);
    Database::insert('board_venues', ['exam_id' => $exam['id']] + $clean);
    Response::success(null, "Venue \"{$clean['name']}\" added");
});

$router->put('/api/admin/board-venues/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $v = Database::fetch("SELECT * FROM board_venues WHERE id = ?", [(int)$p['id']]);
    if (!$v) Response::notFound('Venue not found');
    $clean = boardValidateVenue(boardBody(), (int)$v['exam_id'], (int)$v['id']);
    Database::query("UPDATE board_venues SET name = ?, address = ?, colleges = ?, sort_order = ? WHERE id = ?", [...array_values($clean), $v['id']]);
    Response::success(null, 'Venue saved');
});

$router->delete('/api/admin/board-venues/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $v = Database::fetch("SELECT * FROM board_venues WHERE id = ?", [(int)$p['id']]);
    if (!$v) Response::notFound('Venue not found');
    $n = (int)Database::fetch("SELECT COUNT(*) n FROM board_seats s JOIN board_rooms r ON r.id = s.room_id WHERE r.venue_id = ?", [$v['id']])['n'];
    if ($n) Response::validationError(["$n candidate(s) are seated in this venue. Regenerate the seat plan without it first (disable its rooms), then delete."]);
    Database::query("DELETE FROM board_venues WHERE id = ?", [$v['id']]);
    Response::success(null, "Venue \"{$v['name']}\" deleted");
});

$router->post('/api/admin/board-venues/{venue}/rooms', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $v = Database::fetch("SELECT id FROM board_venues WHERE id = ?", [(int)$p['venue']]);
    if (!$v) Response::notFound('Venue not found');
    $clean = boardValidateRoom(boardBody(), (int)$v['id']);
    Database::insert('board_rooms', ['venue_id' => (int)$v['id']] + $clean);
    Response::success(null, "Room \"{$clean['name']}\" added");
});

$router->put('/api/admin/board-rooms/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $r = Database::fetch("SELECT * FROM board_rooms WHERE id = ?", [(int)$p['id']]);
    if (!$r) Response::notFound('Room not found');
    $d = boardBody();
    if (array_keys($d) === ['enabled']) {
        Database::query("UPDATE board_rooms SET enabled = ? WHERE id = ?", [!empty($d['enabled']) ? 1 : 0, $r['id']]);
    } else {
        $clean = boardValidateRoom($d, (int)$r['venue_id'], (int)$r['id']);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
        Database::query("UPDATE board_rooms SET $sets WHERE id = ?", [...array_values($clean), $r['id']]);
    }
    Response::success(null, 'Room saved');
});

$router->delete('/api/admin/board-rooms/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $r = Database::fetch("SELECT * FROM board_rooms WHERE id = ?", [(int)$p['id']]);
    if (!$r) Response::notFound('Room not found');
    $n = (int)Database::fetch("SELECT COUNT(*) n FROM board_seats WHERE room_id = ?", [$r['id']])['n'];
    if ($n) Response::validationError(["$n candidate(s) are seated in this room. Disable it and regenerate the seat plan first."]);
    Database::query("DELETE FROM board_rooms WHERE id = ?", [$r['id']]);
    Response::success(null, "Room \"{$r['name']}\" deleted");
});

// ================================================================== candidates
$router->get('/api/admin/board-exams/{exam}/candidates', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $rows = Database::fetchAll(
        "SELECT c.*, s.seat_no, s.col_no, s.bench_no, s.seat_pos, r.name AS room_name, v.name AS venue_name
         FROM board_candidates c
         LEFT JOIN board_seats s ON s.candidate_id = c.id
         LEFT JOIN board_rooms r ON r.id = s.room_id
         LEFT JOIN board_venues v ON v.id = r.venue_id
         WHERE c.exam_id = ? ORDER BY c.college, LENGTH(c.roll_no), c.roll_no", [$exam['id']]);
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['subjects'] = json_decode((string)$r['subjects'], true) ?: []; }
    unset($r);
    Response::success($rows);
});

// { rows: [{roll_no, reg_no, name, college, group_name, gender, candidate_type, subjects: "101,102,…" | []}], replace: bool }
$router->post('/api/admin/board-exams/{exam}/candidates/import', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $d = boardBody();
    $rows = (array)($d['rows'] ?? []);
    if (!$rows) Response::validationError(['No rows to import']);
    if (count($rows) > 20000) Response::validationError(['Too many rows (max 20,000 per import)']);
    $errors = []; $clean = []; $seen = [];
    foreach ($rows as $i => $r) {
        $line = $i + 2; // spreadsheet line (row 1 = headings)
        $roll = boardStr($r['roll_no'] ?? '', 30);
        $name = boardStr($r['name'] ?? '', 150);
        if ($roll === '' && $name === '') continue; // blank line
        if ($roll === '') { $errors[] = "Line $line: Roll is missing"; continue; }
        if ($name === '') { $errors[] = "Line $line ($roll): Name is missing"; continue; }
        if (isset($seen[$roll])) { $errors[] = "Line $line: Roll $roll appears twice in the file"; continue; }
        $seen[$roll] = true;
        $g = strtolower(trim((string)($r['gender'] ?? '')));
        $gender = in_array($g, ['f', 'female', 'girl', 'মহিলা'], true) ? 'female' : (in_array($g, ['m', 'male', 'boy', 'পুরুষ'], true) ? 'male' : null);
        $subs = $r['subjects'] ?? [];
        if (!is_array($subs)) $subs = preg_split('/[,;|\/]+/', (string)$subs);
        $subs = array_values(array_unique(array_filter(array_map(fn($s) => boardStr($s, 60), $subs), fn($s) => $s !== '')));
        $clean[] = [$roll, boardStr($r['reg_no'] ?? '', 30) ?: null, $name, boardStr($r['college'] ?? '', 200),
                    boardStr($r['group_name'] ?? '', 60) ?: null, $gender, boardStr($r['candidate_type'] ?? '', 30) ?: null,
                    json_encode($subs, JSON_UNESCAPED_UNICODE)];
    }
    if ($errors) Response::validationError(array_slice($errors, 0, 25));
    if (!$clean) Response::validationError(['No candidates found in the file']);
    if (!empty($d['replace'])) Database::query("DELETE FROM board_candidates WHERE exam_id = ?", [$exam['id']]);
    $before = (int)Database::fetch("SELECT COUNT(*) n FROM board_candidates WHERE exam_id = ?", [$exam['id']])['n'];
    foreach (array_chunk($clean, 400) as $chunk) {
        $vals = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?,?,?)'));
        $params = [];
        foreach ($chunk as $c) array_push($params, $exam['id'], ...$c);
        Database::query(
            "INSERT INTO board_candidates (exam_id, roll_no, reg_no, name, college, group_name, gender, candidate_type, subjects) VALUES $vals
             ON DUPLICATE KEY UPDATE reg_no = VALUES(reg_no), name = VALUES(name), college = VALUES(college), group_name = VALUES(group_name),
                gender = VALUES(gender), candidate_type = VALUES(candidate_type), subjects = VALUES(subjects)", $params);
    }
    $after = (int)Database::fetch("SELECT COUNT(*) n FROM board_candidates WHERE exam_id = ?", [$exam['id']])['n'];
    $added = $after - $before;
    Response::success(['imported' => count($clean), 'added' => $added, 'updated' => count($clean) - $added, 'total' => $after],
        count($clean) . " candidate(s) imported ($added new, " . (count($clean) - $added) . " updated). Regenerate the seat plan to seat new candidates.");
});

$router->delete('/api/admin/board-candidates/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    Database::query("DELETE FROM board_candidates WHERE id = ?", [(int)$p['id']]);
    Response::success(null, 'Candidate removed');
});

// DELETE …/candidates?college=  (empty = all candidates of the exam)
$router->delete('/api/admin/board-exams/{exam}/candidates', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $college = trim((string)($_GET['college'] ?? ''));
    $st = $college === ''
        ? Database::query("DELETE FROM board_candidates WHERE exam_id = ?", [$exam['id']])
        : Database::query("DELETE FROM board_candidates WHERE exam_id = ? AND college = ?", [$exam['id'], $college]);
    Response::success(['deleted' => $st->rowCount()], $st->rowCount() . ' candidate(s) deleted');
});

// ================================================================== routine
$router->get('/api/admin/board-exams/{exam}/routine', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $rows = Database::fetchAll("SELECT * FROM board_routine WHERE exam_id = ? ORDER BY exam_date, exam_time, subject_code, subject_name", [$exam['id']]);
    // candidates taking each routine subject
    $cands = Database::fetchAll("SELECT subjects FROM board_candidates WHERE exam_id = ?", [$exam['id']]);
    $tok = array_map(fn($c) => boardSubjectTokens($c['subjects']), $cands);
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $keys = array_filter([mb_strtolower($r['subject_code']), mb_strtolower($r['subject_name'])], fn($x) => $x !== '');
        $r['candidates'] = count(array_filter($tok, function ($t) use ($keys) { foreach ($keys as $k) if (isset($t[$k])) return true; return false; }));
    }
    unset($r);
    Response::success($rows);
});

$router->post('/api/admin/board-exams/{exam}/routine', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $d = boardBody();
    $date = (string)($d['exam_date'] ?? '');
    $time = preg_match('/^\d{1,2}:\d{2}$/', (string)($d['exam_time'] ?? '')) ? (string)$d['exam_time'] : '';
    $code = boardStr($d['subject_code'] ?? '', 20);
    $name = boardStr($d['subject_name'] ?? '', 150);
    $errors = [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $errors[] = 'Exam date is required';
    if ($code === '' && $name === '') $errors[] = 'Enter the subject code and/or name';
    if ($errors) Response::validationError($errors);
    if (Database::fetch("SELECT id FROM board_routine WHERE exam_id = ? AND exam_date = ? AND exam_time = ? AND subject_code = ? AND subject_name = ?", [$exam['id'], $date, $time, $code, $name])) {
        Response::validationError(['This subject is already in the routine for that date and time']);
    }
    Database::insert('board_routine', ['exam_id' => $exam['id'], 'exam_date' => $date, 'exam_time' => $time, 'subject_code' => $code, 'subject_name' => $name]);
    Response::success(null, 'Routine entry added');
});

$router->delete('/api/admin/board-routine/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    Database::query("DELETE FROM board_routine WHERE id = ?", [(int)$p['id']]);
    Response::success(null, 'Routine entry removed');
});

// ================================================================== seat plan
if (!function_exists('boardGenerateSeats')) {
function boardGenerateSeats(array $exam): array
{
    $venues = boardVenues($exam['id']);
    $cands = Database::fetchAll("SELECT id, roll_no, name, college, gender FROM board_candidates WHERE exam_id = ?", [$exam['id']]);
    usort($cands, fn($a, $b) => [strlen($a['roll_no']), $a['roll_no']] <=> [strlen($b['roll_no']), $b['roll_no']]); // numeric-aware roll order
    $remaining = [];
    foreach ($cands as $c) $remaining[(int)$c['id']] = $c;
    $warnings = [];
    if (!$cands) return ['placed' => 0, 'total' => 0, 'warnings' => ['No candidates. Import candidates first.']];
    $roomsTotal = 0;
    foreach ($venues as $v) foreach ($v['rooms'] as $r) if ($r['enabled']) $roomsTotal++;
    if (!$roomsTotal) return ['placed' => 0, 'total' => count($cands), 'warnings' => ['No enabled rooms. Add venues and rooms first.']];

    // venues limited to certain colleges seat first, so those colleges land there; open venues take the rest
    usort($venues, fn($a, $b) => [empty($a['colleges']) ? 1 : 0, $a['sort_order'], $a['name']] <=> [empty($b['colleges']) ? 1 : 0, $b['sort_order'], $b['name']]);
    $mixed = $exam['seat_mode'] === 'college_mixed';
    $assign = [];
    foreach ($venues as $venue) {
        $allowed = $venue['colleges'] ? array_flip($venue['colleges']) : null;
        foreach ($venue['rooms'] as $room) {
            if (!$room['enabled']) continue;
            // pools: gender key => college => [candidate ids in roll order]
            $pools = []; $collegeOrder = [];
            foreach ($remaining as $cid => $c) {
                if ($allowed !== null && !isset($allowed[$c['college']])) continue;
                $g = $c['gender'] === 'female' ? 'female' : 'male';
                if (in_array($room['gender_mode'], ['male', 'female'], true) && $g !== $room['gender_mode']) continue;
                $key = $room['gender_mode'] === 'separate' ? $g : 'any';
                $col = $mixed ? $c['college'] : '*';
                $pools[$key][$col][] = $cid;
                $collegeOrder[$col] = true;
            }
            if (!$pools) continue;
            $order = array_keys($collegeOrder);
            $rr = [];
            $take = function (string $key, ?string $avoid) use (&$pools, &$rr, $order) {
                $cols = array_values(array_filter($order, fn($c) => !empty($pools[$key][$c])));
                if (!$cols) return null;
                $start = $rr[$key] ?? 0; $pick = null;
                for ($i = 0; $i < count($cols); $i++) {
                    $cand = $cols[($start + $i) % count($cols)];
                    if ($cand !== $avoid || count($cols) === 1) { $pick = $cand; break; }
                }
                $rr[$key] = (array_search($pick, $cols, true) + 1) % max(1, count($cols));
                return array_shift($pools[$key][$pick]);
            };
            $size = function (string $key) use (&$pools) { return array_sum(array_map('count', $pools[$key] ?? [])); };
            $seatNo = 0;
            for ($c = 1; $c <= $room['columns_count']; $c++) {
                for ($b = 1; $b <= $room['column_benches'][$c - 1]; $b++) {
                    if ($room['gender_mode'] === 'separate') {
                        $m = $size('male'); $f = $size('female');
                        if ($m + $f === 0) break 2;
                        $key = $m >= $f ? 'male' : 'female';
                    } else {
                        $key = 'any';
                        if ($size('any') === 0) break 2;
                    }
                    $prev = null;
                    for ($s = 1; $s <= $room['seats_per_bench']; $s++) {
                        $cid = $take($key, $prev);
                        if ($cid === null) break;
                        $seatNo++;
                        $assign[] = [$exam['id'], $room['id'], $seatNo, $c, $b, $s, $cid];
                        $prev = $mixed ? $remaining[$cid]['college'] : null;
                        unset($remaining[$cid]);
                    }
                }
            }
        }
    }
    Database::query("DELETE FROM board_seats WHERE exam_id = ?", [$exam['id']]);
    foreach (array_chunk($assign, 400) as $chunk) {
        $vals = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?)'));
        Database::query("INSERT INTO board_seats (exam_id, room_id, seat_no, col_no, bench_no, seat_pos, candidate_id) VALUES $vals", array_merge(...$chunk));
    }
    if ($remaining) {
        $by = [];
        foreach ($remaining as $c) $by[$c['college'] ?: 'No college'][] = $c['roll_no'];
        foreach ($by as $col => $rolls) {
            $warnings[] = count($rolls) . " candidate(s) of $col could not be seated (not enough eligible seats — check venue colleges / room gender rules): "
                . implode(', ', array_slice($rolls, 0, 12)) . (count($rolls) > 12 ? ' …' : '');
        }
    }
    Database::query("UPDATE board_exams SET seat_warnings = ?, seats_generated_at = NOW() WHERE id = ?", [json_encode($warnings), $exam['id']]);
    return ['placed' => count($assign), 'total' => count($cands), 'warnings' => $warnings];
}}

$router->post('/api/admin/board-exams/{exam}/seats/generate', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $fin = Database::fetch("SHOW TABLES LIKE 'board_duty_config'") ? Database::fetch("SELECT finalized FROM board_duty_config WHERE exam_id = ?", [$exam['id']]) : null;
    if ($fin && (int)$fin['finalized']) Response::validationError(['Hall duties of this exam are finalized. Re-open them before changing the seat plan.']);
    $r = boardGenerateSeats($exam);
    Response::success($r, "{$r['placed']} of {$r['total']} candidate(s) seated" . ($r['warnings'] ? ' — ' . count($r['warnings']) . ' warning(s)' : ''));
});

if (!function_exists('boardSeatRows')) {
function boardSeatRows(int $examId): array
{
    $rows = Database::fetchAll(
        "SELECT s.room_id, s.seat_no, s.col_no, s.bench_no, s.seat_pos, c.id AS candidate_id, c.roll_no, c.reg_no, c.name, c.college,
                c.group_name, c.gender, c.subjects
         FROM board_seats s JOIN board_candidates c ON c.id = s.candidate_id
         WHERE s.exam_id = ? ORDER BY s.room_id, s.seat_no", [$examId]);
    foreach ($rows as &$r) {
        foreach (['room_id', 'seat_no', 'col_no', 'bench_no', 'seat_pos', 'candidate_id'] as $k) $r[$k] = (int)$r[$k];
        $r['subjects'] = json_decode((string)$r['subjects'], true) ?: [];
    }
    unset($r);
    return $rows;
}}

$router->get('/api/admin/board-exams/{exam}/seats', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $total = (int)Database::fetch("SELECT COUNT(*) n FROM board_candidates WHERE exam_id = ?", [$exam['id']])['n'];
    Response::success(['exam' => $exam, 'venues' => boardVenues($exam['id']), 'seats' => boardSeatRows($exam['id']), 'candidates' => $total]);
});

// Attendance sheet data for one session: candidates sitting that session, room by room
$router->get('/api/admin/board-exams/{exam}/attendance', function ($p) {
    Auth::requireRole('admin');
    $exam = boardLoadExam((int)$p['exam']);
    $date = (string)($_GET['date'] ?? '');
    $time = (string)($_GET['time'] ?? '');
    $routine = Database::fetchAll("SELECT * FROM board_routine WHERE exam_id = ? AND exam_date = ? AND exam_time = ?", [$exam['id'], $date, $time]);
    if (!$routine) Response::validationError(['No routine entry on that date / time']);
    $keyMap = []; // token => "code name" label
    foreach ($routine as $r) {
        $label = trim($r['subject_code'] . ' ' . $r['subject_name']);
        foreach ([$r['subject_code'], $r['subject_name']] as $k) if (trim($k) !== '') $keyMap[mb_strtolower(trim($k))] = $label;
    }
    $out = [];
    foreach (boardSeatRows($exam['id']) as $s) {
        $papers = [];
        foreach (boardSubjectTokens($s['subjects']) as $t => $_) if (isset($keyMap[$t])) $papers[$keyMap[$t]] = true;
        if (!$papers) continue;
        $s['papers'] = array_keys($papers);
        unset($s['subjects']);
        $out[] = $s;
    }
    Response::success(['exam' => $exam, 'venues' => boardVenues($exam['id']), 'routine' => $routine, 'seats' => $out]);
});

// ================================================================== external teachers
if (!function_exists('boardValidateExternal')) {
function boardValidateExternal(array $d): array
{
    $errors = [];
    $name = boardStr($d['name'] ?? '', 150);
    $college = boardStr($d['college'] ?? '', 200);
    if ($name === '') $errors[] = 'Name is required';
    if ($college === '') $errors[] = 'College is required';
    $mobile = boardStr($d['mobile'] ?? '', 30);
    if ($mobile !== '' && !preg_match('/^[0-9+\-\s]{6,20}$/', $mobile)) $errors[] = 'Mobile number looks wrong';
    $email = boardStr($d['email'] ?? '', 150);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Email looks wrong';
    if ($errors) Response::validationError($errors);
    return [
        'name' => $name, 'college' => $college,
        'designation' => boardStr($d['designation'] ?? '', 100) ?: null,
        'subject' => boardStr($d['subject'] ?? '', 150) ?: null,
        'mobile' => $mobile ?: null, 'email' => $email ?: null,
        'gender' => ($d['gender'] ?? '') === 'female' ? 'female' : 'male',
        'enabled' => array_key_exists('enabled', $d) ? (!empty($d['enabled']) ? 1 : 0) : 1,
        'notes' => mb_substr(trim((string)($d['notes'] ?? '')), 0, 1000) ?: null,
    ];
}}

$router->get('/api/admin/board-external-teachers', function () {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $rows = Database::fetchAll("SELECT * FROM board_external_teachers ORDER BY college, name");
    foreach ($rows as &$r) { $r['id'] = (int)$r['id']; $r['enabled'] = (bool)$r['enabled']; }
    unset($r);
    Response::success($rows);
});

$router->post('/api/admin/board-external-teachers', function () {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $clean = boardValidateExternal(boardBody());
    if (Database::fetch("SELECT id FROM board_external_teachers WHERE name = ? AND college = ?", [$clean['name'], $clean['college']])) {
        Response::validationError(["{$clean['name']} of {$clean['college']} is already in the list"]);
    }
    Database::insert('board_external_teachers', $clean);
    Response::success(null, "{$clean['name']} added");
});

$router->put('/api/admin/board-external-teachers/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $t = Database::fetch("SELECT * FROM board_external_teachers WHERE id = ?", [(int)$p['id']]);
    if (!$t) Response::notFound('Teacher not found');
    $d = boardBody();
    if (array_keys($d) === ['enabled']) {
        Database::query("UPDATE board_external_teachers SET enabled = ? WHERE id = ?", [!empty($d['enabled']) ? 1 : 0, $t['id']]);
        Response::success(null, $t['name'] . (!empty($d['enabled']) ? ' enabled' : ' disabled'));
    }
    $clean = boardValidateExternal($d);
    $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
    Database::query("UPDATE board_external_teachers SET $sets WHERE id = ?", [...array_values($clean), $t['id']]);
    Response::success(null, "{$clean['name']} saved");
});

$router->delete('/api/admin/board-external-teachers/{id}', function ($p) {
    Auth::requireRole('admin');
    boardEnsureSchema();
    $t = Database::fetch("SELECT * FROM board_external_teachers WHERE id = ?", [(int)$p['id']]);
    if (!$t) Response::notFound('Teacher not found');
    if (Database::fetch("SHOW TABLES LIKE 'board_duties'") && Database::fetch("SELECT id FROM board_duties WHERE tkey = ? LIMIT 1", ['x' . $t['id']])) {
        Response::validationError(["{$t['name']} has board exam duties. Disable instead, or remove the duties first."]);
    }
    Database::query("DELETE FROM board_external_teachers WHERE id = ?", [$t['id']]);
    Response::success(null, "{$t['name']} deleted");
});
