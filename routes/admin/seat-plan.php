<?php

// Exam Seat Plan Management (Session 80) — Admin only (not grantable).
//
// Rooms:   GET/POST /api/admin/seat-plan/rooms, PUT/DELETE /api/admin/seat-plan/rooms/{id}
// Plans:   GET/POST /api/admin/seat-plan/plans, GET/DELETE /api/admin/seat-plan/plans/{id},
//          POST /api/admin/seat-plan/plans/{id}/generate
//
// Capacity of a room = columns × benches per column × students per bench.
// Generation walks every enabled room in order, seat by seat (column → bench → position) and takes
// eligible students (room's groups + gender rule) from the remaining pool, so a student is seated once
// at most and a room is never over-filled. Rules:
//   group_mode  mixed      → round-robin across groups (neighbours on a bench differ when possible)
//               sequential → group by group (Science, Business Studies, Humanities), then class, then ID
//   gender_mode mixed      → anyone;  male / female → only that gender;
//               separate   → both genders in the room but never on the same bench

if (!function_exists('seatPlanEnsureSchema')) {
function seatPlanEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    Database::query("CREATE TABLE IF NOT EXISTS exam_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) NOT NULL,
        enabled TINYINT(1) NOT NULL DEFAULT 1,
        columns_count INT NOT NULL DEFAULT 3,
        benches_per_column INT NOT NULL DEFAULT 5,
        column_benches TEXT NULL,
        door_position VARCHAR(20) NOT NULL DEFAULT 'front-right',
        seats_per_bench INT NOT NULL DEFAULT 2,
        student_groups TEXT NULL,
        group_mode ENUM('mixed','sequential') NOT NULL DEFAULT 'mixed',
        gender_mode ENUM('mixed','male','female','separate') NOT NULL DEFAULT 'mixed',
        invigilators INT NOT NULL DEFAULT 2,
        teacher_gender ENUM('any','male','female','mixed') NOT NULL DEFAULT 'any',
        notes TEXT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_room_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Session 81: benches can differ per column (e.g. [10, 9, 10]); add the column to older tables
    if (!Database::fetch("SHOW COLUMNS FROM exam_rooms LIKE 'column_benches'")) {
        Database::query("ALTER TABLE exam_rooms ADD COLUMN column_benches TEXT NULL AFTER benches_per_column");
    }
    // Session 81: where the door is (wall-place, e.g. front-right) for the graphical room layout
    if (!Database::fetch("SHOW COLUMNS FROM exam_rooms LIKE 'door_position'")) {
        Database::query("ALTER TABLE exam_rooms ADD COLUMN door_position VARCHAR(20) NOT NULL DEFAULT 'front-right' AFTER column_benches");
    }
    Database::query("CREATE TABLE IF NOT EXISTS seat_plans (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(150) NOT NULL,
        year VARCHAR(10) NOT NULL,
        exam_name VARCHAR(100) NOT NULL,
        classes TEXT NOT NULL,
        warnings TEXT NULL,
        generated_at DATETIME NULL,
        created_by INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS seat_assignments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        room_id INT NOT NULL,
        seat_no INT NOT NULL,
        col_no INT NOT NULL,
        bench_no INT NOT NULL,
        seat_pos INT NOT NULL,
        student_id INT NOT NULL,
        UNIQUE KEY uniq_plan_student (plan_id, student_id),
        UNIQUE KEY uniq_plan_seat (plan_id, room_id, col_no, bench_no, seat_pos),
        KEY idx_plan_room (plan_id, room_id),
        CONSTRAINT fk_seat_plan FOREIGN KEY (plan_id) REFERENCES seat_plans(id) ON DELETE CASCADE,
        CONSTRAINT fk_seat_room FOREIGN KEY (room_id) REFERENCES exam_rooms(id) ON DELETE CASCADE,
        CONSTRAINT fk_seat_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}}

if (!function_exists('seatPlanDoorPositions')) {
/** Door spots: wall (front = board side, back, left, right) + place along that wall. */
function seatPlanDoorPositions(): array
{
    return ['front-left', 'front-center', 'front-right', 'back-left', 'back-center', 'back-right',
            'left-front', 'left-middle', 'left-back', 'right-front', 'right-middle', 'right-back'];
}}

if (!function_exists('seatPlanGroups')) {
function seatPlanGroups(): array { return catalogGroupNames(); } // Session 88: admin-managed groups
}

if (!function_exists('seatPlanRoomOut')) {
function seatPlanRoomOut(array $r): array
{
    $r['id'] = (int)$r['id'];
    $r['enabled'] = (bool)$r['enabled'];
    foreach (['columns_count', 'benches_per_column', 'seats_per_bench', 'invigilators', 'sort_order'] as $k) $r[$k] = (int)$r[$k];
    $r['student_groups'] = json_decode((string)$r['student_groups'], true) ?: [];
    // benches in each column; rooms saved before Session 81 have the same count in every column
    $cb = array_values(array_map('intval', json_decode((string)($r['column_benches'] ?? ''), true) ?: []));
    if (count($cb) !== $r['columns_count']) $cb = array_fill(0, max(0, $r['columns_count']), $r['benches_per_column']);
    $r['column_benches'] = $cb;
    $r['door_position'] = in_array($r['door_position'] ?? '', seatPlanDoorPositions(), true) ? $r['door_position'] : 'front-right';
    $r['total_benches'] = array_sum($cb);
    $r['capacity'] = $r['total_benches'] * $r['seats_per_bench'];
    return $r;
}}

if (!function_exists('seatPlanValidateRoom')) {
function seatPlanValidateRoom(array $d, ?int $id = null): array
{
    $errors = [];
    $name = trim((string)($d['name'] ?? ''));
    if ($name === '') $errors[] = 'Room number / name is required';
    $clean = ['name' => mb_substr($name, 0, 100)];
    foreach (['seats_per_bench' => [1, 10], 'invigilators' => [0, 20]] as $k => [$min, $max]) {
        $v = filter_var($d[$k] ?? null, FILTER_VALIDATE_INT);
        if ($v === false || $v < $min || $v > $max) $errors[] = str_replace('_', ' ', $k) . " must be a whole number from $min to $max";
        else $clean[$k] = $v;
    }
    // Benches per column (Session 81): column_benches = [10, 9, 10, …]; older clients may still send
    // columns_count + benches_per_column (same count everywhere).
    $cb = $d['column_benches'] ?? null;
    if (!is_array($cb) || !$cb) {
        $cols = filter_var($d['columns_count'] ?? null, FILTER_VALIDATE_INT);
        $bpc = filter_var($d['benches_per_column'] ?? null, FILTER_VALIDATE_INT);
        $cb = ($cols !== false && $bpc !== false && $cols > 0) ? array_fill(0, min($cols, 31), $bpc) : [];
    }
    $cb = array_values($cb);
    if (count($cb) < 1 || count($cb) > 30) {
        $errors[] = 'A room needs 1 to 30 columns';
    } else {
        foreach ($cb as $i => $v) {
            $v = filter_var($v, FILTER_VALIDATE_INT);
            if ($v === false || $v < 1 || $v > 60) { $errors[] = 'Column ' . ($i + 1) . ': benches must be a whole number from 1 to 60'; continue; }
            $cb[$i] = $v;
        }
        $clean['column_benches'] = json_encode(array_map('intval', $cb));
        $clean['columns_count'] = count($cb);
        $clean['benches_per_column'] = max(array_map('intval', $cb)); // kept for older readers = largest column
    }
    $groups = array_values(array_intersect(seatPlanGroups(), (array)($d['student_groups'] ?? [])));
    $clean['student_groups'] = json_encode($groups);
    $clean['group_mode'] = in_array($d['group_mode'] ?? '', ['mixed', 'sequential'], true) ? $d['group_mode'] : 'mixed';
    $clean['gender_mode'] = in_array($d['gender_mode'] ?? '', ['mixed', 'male', 'female', 'separate'], true) ? $d['gender_mode'] : 'mixed';
    $clean['teacher_gender'] = in_array($d['teacher_gender'] ?? '', ['any', 'male', 'female', 'mixed'], true) ? $d['teacher_gender'] : 'any';
    $clean['door_position'] = in_array($d['door_position'] ?? '', seatPlanDoorPositions(), true) ? $d['door_position'] : 'front-right';
    $clean['enabled'] = !empty($d['enabled']) ? 1 : 0;
    $clean['notes'] = mb_substr(trim((string)($d['notes'] ?? '')), 0, 2000);
    $clean['sort_order'] = (int)($d['sort_order'] ?? 0);
    if ($name !== '') {
        $dup = Database::fetch("SELECT id FROM exam_rooms WHERE name = ?" . ($id ? " AND id <> ?" : ''), $id ? [$name, $id] : [$name]);
        if ($dup) $errors[] = "A room named \"$name\" already exists";
    }
    if ($errors) Response::validationError($errors);
    return $clean;
}}

// ---------------- rooms ----------------
$router->get('/api/admin/seat-plan/rooms', function () {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $rows = Database::fetchAll("SELECT * FROM exam_rooms ORDER BY sort_order, name");
    Response::success(array_map('seatPlanRoomOut', $rows));
});

$router->post('/api/admin/seat-plan/rooms', function () {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $clean = seatPlanValidateRoom(json_decode(file_get_contents('php://input'), true) ?: []);
    $id = Database::insert('exam_rooms', $clean);
    Response::success(seatPlanRoomOut(Database::fetch("SELECT * FROM exam_rooms WHERE id = ?", [$id])), 'Room added');
});

$router->put('/api/admin/seat-plan/rooms/{id}', function ($params) {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $id = (int)$params['id'];
    if (!Database::fetch("SELECT id FROM exam_rooms WHERE id = ?", [$id])) Response::notFound('Room not found');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    if (array_keys($data) === ['enabled']) {
        // quick enable / disable toggle
        Database::query("UPDATE exam_rooms SET enabled = ? WHERE id = ?", [!empty($data['enabled']) ? 1 : 0, $id]);
    } else {
        $clean = seatPlanValidateRoom($data, $id);
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($clean)));
        Database::query("UPDATE exam_rooms SET $sets WHERE id = ?", [...array_values($clean), $id]);
    }
    Response::success(seatPlanRoomOut(Database::fetch("SELECT * FROM exam_rooms WHERE id = ?", [$id])), 'Room saved');
});

$router->delete('/api/admin/seat-plan/rooms/{id}', function ($params) {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $id = (int)$params['id'];
    $used = Database::fetch("SELECT COUNT(*) n FROM seat_assignments WHERE room_id = ?", [$id]);
    if ((int)($used['n'] ?? 0) > 0) {
        Response::validationError(['This room is used in a seat plan. Disable it instead, or delete/regenerate those plans first.']);
    }
    Database::query("DELETE FROM exam_rooms WHERE id = ?", [$id]);
    Response::success(null, 'Room deleted');
});

// ---------------- generation ----------------
if (!function_exists('seatPlanGenerate')) {
function seatPlanGenerate(int $planId): array
{
    $plan = Database::fetch("SELECT * FROM seat_plans WHERE id = ?", [$planId]);
    $classes = json_decode($plan['classes'], true) ?: [];
    $warnings = [];

    $rooms = array_map('seatPlanRoomOut', Database::fetchAll("SELECT * FROM exam_rooms WHERE enabled = 1 ORDER BY sort_order, name"));
    if (!$rooms) {
        return ['placed' => 0, 'total' => 0, 'warnings' => ['No enabled rooms. Add or enable rooms first.']];
    }
    if (!$classes) {
        return ['placed' => 0, 'total' => 0, 'warnings' => ['Select at least one class.']];
    }
    $ph = implode(',', array_fill(0, count($classes), '?'));
    $students = Database::fetchAll(
        "SELECT id, student_id, name, class, student_group, gender FROM students WHERE class IN ($ph)
         ORDER BY " . catalogGroupOrderSql('student_group') . ", class, student_id",
        $classes
    );
    $remaining = [];
    foreach ($students as $s) $remaining[(int)$s['id']] = $s;

    $assign = [];
    foreach ($rooms as $room) {
        $allowedGroups = $room['student_groups'] ?: seatPlanGroups();
        // eligible pools for this room: gender => group => [student ids in order]
        $pools = [];
        foreach ($remaining as $sid => $s) {
            if (!in_array($s['student_group'], $allowedGroups, true)) continue;
            $g = $s['gender'] === 'female' ? 'female' : 'male';
            if (in_array($room['gender_mode'], ['male', 'female'], true) && $g !== $room['gender_mode']) continue;
            $key = $room['gender_mode'] === 'separate' ? $g : 'any';
            $pools[$key][$s['student_group']][] = $sid;
        }
        if (!$pools) continue;
        $rr = []; // round-robin pointer per pool key

        $take = function (string $key, ?string $avoidGroup) use (&$pools, &$rr, $room) {
            if (empty($pools[$key])) return null;
            $groups = array_values(array_filter(seatPlanGroups(), fn($g) => !empty($pools[$key][$g])));
            if (!$groups) return null;
            if ($room['group_mode'] === 'sequential') {
                $grp = $groups[0];
            } else {
                $start = $rr[$key] ?? 0;
                $grp = null;
                for ($i = 0; $i < count($groups); $i++) {
                    $cand = $groups[($start + $i) % count($groups)];
                    if ($cand !== $avoidGroup || count($groups) === 1) { $grp = $cand; break; }
                }
                $rr[$key] = (array_search($grp, $groups, true) + 1) % max(1, count($groups));
            }
            return array_shift($pools[$key][$grp]);
        };
        // NB: by reference — an arrow fn would capture a stale copy of $pools (all benches went to one gender)
        $poolSize = function (string $key) use (&$pools) { return array_sum(array_map('count', $pools[$key] ?? [])); };

        $seatNo = 0;
        for ($c = 1; $c <= $room['columns_count']; $c++) {
            for ($b = 1; $b <= $room['column_benches'][$c - 1]; $b++) {
                // whole bench gets one gender when the room keeps genders separate
                if ($room['gender_mode'] === 'separate') {
                    $m = $poolSize('male'); $f = $poolSize('female');
                    if ($m + $f === 0) break 2;
                    $key = $m >= $f ? 'male' : 'female';
                } else {
                    $key = 'any';
                    if ($poolSize('any') === 0) break 2;
                }
                $prevGroup = null;
                for ($p = 1; $p <= $room['seats_per_bench']; $p++) {
                    $sid = $take($key, $prevGroup);
                    if ($sid === null) break;
                    $seatNo++;
                    $assign[] = [$room['id'], $seatNo, $c, $b, $p, $sid];
                    $prevGroup = $remaining[$sid]['student_group'];
                    unset($remaining[$sid]);
                }
            }
        }
    }

    // write (one transaction-like batch)
    Database::query("DELETE FROM seat_assignments WHERE plan_id = ?", [$planId]);
    foreach (array_chunk($assign, 300) as $chunk) {
        $vals = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,?,?)'));
        $params = [];
        foreach ($chunk as [$rid, $no, $c, $b, $p, $sid]) array_push($params, $planId, $rid, $no, $c, $b, $p, $sid);
        Database::query("INSERT INTO seat_assignments (plan_id, room_id, seat_no, col_no, bench_no, seat_pos, student_id) VALUES $vals", $params);
    }

    if ($remaining) {
        $byGroup = [];
        foreach ($remaining as $s) $byGroup[$s['student_group'] ?: 'No group'][] = $s['student_id'];
        foreach ($byGroup as $g => $ids) {
            $warnings[] = count($ids) . " $g student(s) could not be seated (not enough eligible room capacity): " . implode(', ', array_slice($ids, 0, 15)) . (count($ids) > 15 ? ' …' : '');
        }
    }
    Database::query("UPDATE seat_plans SET warnings = ?, generated_at = NOW() WHERE id = ?", [json_encode($warnings), $planId]);
    return ['placed' => count($assign), 'total' => count($students), 'warnings' => $warnings];
}}

// ---------------- plans ----------------
$router->get('/api/admin/seat-plan/plans', function () {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $rows = Database::fetchAll(
        "SELECT p.*, (SELECT COUNT(*) FROM seat_assignments a WHERE a.plan_id = p.id) AS seated,
                (SELECT COUNT(DISTINCT a.room_id) FROM seat_assignments a WHERE a.plan_id = p.id) AS rooms_used
         FROM seat_plans p ORDER BY p.id DESC"
    );
    foreach ($rows as &$r) {
        $r['classes'] = json_decode($r['classes'], true) ?: [];
        $r['warnings'] = json_decode((string)$r['warnings'], true) ?: [];
    }
    unset($r);
    Response::success($rows);
});

$router->post('/api/admin/seat-plan/plans', function () {
    $user = Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $year = trim((string)($d['year'] ?? ''));
    $exam = trim((string)($d['exam_name'] ?? ''));
    $classes = array_values(array_intersect(['11th', '12th'], (array)($d['classes'] ?? [])));
    $errors = [];
    if (!preg_match('/^\d{4}$/', $year)) $errors[] = 'Year is required';
    if ($exam === '') $errors[] = 'Exam name is required';
    if (!$classes) $errors[] = 'Select at least one class';
    if ($errors) Response::validationError($errors);
    $name = trim((string)($d['name'] ?? '')) ?: "$exam $year (" . implode(' + ', $classes) . ')';
    $id = Database::insert('seat_plans', ['name' => mb_substr($name, 0, 150), 'year' => $year, 'exam_name' => $exam, 'classes' => json_encode($classes), 'created_by' => $user['id']]);
    $result = seatPlanGenerate((int)$id);
    Response::success(['id' => (int)$id] + $result, "Seat plan generated: {$result['placed']} of {$result['total']} students seated");
});

$router->post('/api/admin/seat-plan/plans/{id}/generate', function ($params) {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $id = (int)$params['id'];
    if (!Database::fetch("SELECT id FROM seat_plans WHERE id = ?", [$id])) Response::notFound('Seat plan not found');
    $result = seatPlanGenerate($id);
    Response::success(['id' => $id] + $result, "Seat plan regenerated: {$result['placed']} of {$result['total']} students seated");
});

$router->get('/api/admin/seat-plan/plans/{id}', function ($params) {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    $id = (int)$params['id'];
    $plan = Database::fetch("SELECT * FROM seat_plans WHERE id = ?", [$id]);
    if (!$plan) Response::notFound('Seat plan not found');
    $plan['classes'] = json_decode($plan['classes'], true) ?: [];
    $plan['warnings'] = json_decode((string)$plan['warnings'], true) ?: [];
    $seats = Database::fetchAll(
        "SELECT a.room_id, a.seat_no, a.col_no, a.bench_no, a.seat_pos,
                s.id AS sid, s.student_id, s.name, s.class, s.student_group, s.gender, s.class_roll
         FROM seat_assignments a JOIN students s ON s.id = a.student_id
         WHERE a.plan_id = ? ORDER BY a.room_id, a.seat_no",
        [$id]
    );
    $roomIds = array_values(array_unique(array_map(fn($s) => (int)$s['room_id'], $seats)));
    $rooms = $roomIds
        ? array_map('seatPlanRoomOut', Database::fetchAll("SELECT * FROM exam_rooms WHERE id IN (" . implode(',', $roomIds) . ") ORDER BY sort_order, name"))
        : [];
    Response::success(['plan' => $plan, 'rooms' => $rooms, 'seats' => $seats]);
});

$router->delete('/api/admin/seat-plan/plans/{id}', function ($params) {
    Auth::requireRole('admin');
    seatPlanEnsureSchema();
    Database::query("DELETE FROM seat_plans WHERE id = ?", [(int)$params['id']]);
    Response::success(null, 'Seat plan deleted');
});
