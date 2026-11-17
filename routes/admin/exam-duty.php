<?php

// Teacher Examination Hall Duty Allocation (Session 80) — Admin only (not grantable).
// A duty schedule belongs to a seat plan (which rooms are used, which classes sit there).
// Sessions  = distinct (exam_date, exam_time) in the exam routine for the plan's year + exam + classes.
// A room needs invigilators in a session if it seats a class that sits that session.
//
//   GET    /api/admin/exam-duty/plans                   seat plans + duty status
//   GET    /api/admin/exam-duty/{plan}                  everything for the planner (+ validation warnings)
//   PUT    /api/admin/exam-duty/{plan}/config           min/max duties, own-subject rule, letter details
//   PUT    /api/admin/exam-duty/{plan}/teachers/{tid}   exclude / per-teacher min-max
//   POST   /api/admin/exam-duty/{plan}/generate         auto-allocate (keeps fixed / pre-assigned duties)
//   POST   /api/admin/exam-duty/{plan}/duties           add one duty (manual or pre-assigned "fixed")
//   PUT    /api/admin/exam-duty/{plan}/duties/{id}      reassign teacher / toggle fixed
//   DELETE /api/admin/exam-duty/{plan}/duties/{id}
//   POST   /api/admin/exam-duty/{plan}/finalize         { finalized: true|false }
//   GET    /api/admin/exam-duty/{plan}/letters          appointment letter data (finalized schedules only)

if (!function_exists('dutyEnsureSchema')) {
function dutyEnsureSchema(): void
{
    static $done = false;
    if ($done) return;
    seatPlanEnsureSchema();
    Database::query("CREATE TABLE IF NOT EXISTS duty_config (
        plan_id INT PRIMARY KEY,
        min_duties INT NOT NULL DEFAULT 0,
        max_duties INT NOT NULL DEFAULT 5,
        avoid_own_subject TINYINT(1) NOT NULL DEFAULT 1,
        own_subject_rule VARCHAR(10) NOT NULL DEFAULT 'avoid',
        finalized TINYINT(1) NOT NULL DEFAULT 0,
        finalized_at DATETIME NULL,
        memo_no VARCHAR(100) NULL,
        issue_date DATE NULL,
        signatory_name VARCHAR(150) NULL,
        signatory_designation VARCHAR(150) NULL,
        reporting_minutes INT NOT NULL DEFAULT 30,
        instructions TEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_duty_config_plan FOREIGN KEY (plan_id) REFERENCES seat_plans(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    // Session 83: own-subject rule none | avoid | must (replaces the avoid_own_subject on/off switch)
    if (!Database::fetch("SHOW COLUMNS FROM duty_config LIKE 'own_subject_rule'")) {
        Database::query("ALTER TABLE duty_config ADD COLUMN own_subject_rule VARCHAR(10) NOT NULL DEFAULT 'avoid' AFTER avoid_own_subject");
        Database::query("UPDATE duty_config SET own_subject_rule = IF(avoid_own_subject = 1, 'avoid', 'none')");
    }
    // Session 83: settings that apply to ALL exams — disabled from exam duty, priority for extra duties
    Database::query("CREATE TABLE IF NOT EXISTS duty_teacher_global (
        teacher_id INT PRIMARY KEY,
        disabled TINYINT(1) NOT NULL DEFAULT 0,
        priority INT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        CONSTRAINT fk_dtg_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS duty_teacher_rules (
        plan_id INT NOT NULL,
        teacher_id INT NOT NULL,
        excluded TINYINT(1) NOT NULL DEFAULT 0,
        min_duties INT NULL,
        max_duties INT NULL,
        PRIMARY KEY (plan_id, teacher_id),
        CONSTRAINT fk_dtr_plan FOREIGN KEY (plan_id) REFERENCES seat_plans(id) ON DELETE CASCADE,
        CONSTRAINT fk_dtr_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    Database::query("CREATE TABLE IF NOT EXISTS exam_duties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        plan_id INT NOT NULL,
        exam_date DATE NOT NULL,
        exam_time VARCHAR(20) NOT NULL DEFAULT '',
        room_id INT NOT NULL,
        teacher_id INT NOT NULL,
        fixed TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_teacher_session (plan_id, exam_date, exam_time, teacher_id),
        KEY idx_session_room (plan_id, exam_date, exam_time, room_id),
        CONSTRAINT fk_duty_plan FOREIGN KEY (plan_id) REFERENCES seat_plans(id) ON DELETE CASCADE,
        CONSTRAINT fk_duty_room FOREIGN KEY (room_id) REFERENCES exam_rooms(id) ON DELETE CASCADE,
        CONSTRAINT fk_duty_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $done = true;
}}

if (!function_exists('dutyLoadPlan')) {
function dutyLoadPlan(int $planId): array
{
    dutyEnsureSchema();
    $plan = Database::fetch("SELECT * FROM seat_plans WHERE id = ?", [$planId]);
    if (!$plan) Response::notFound('Seat plan not found');
    $plan['classes'] = json_decode($plan['classes'], true) ?: [];
    $cfg = Database::fetch("SELECT * FROM duty_config WHERE plan_id = ?", [$planId]);
    if (!$cfg) {
        Database::query("INSERT INTO duty_config (plan_id) VALUES (?)", [$planId]);
        $cfg = Database::fetch("SELECT * FROM duty_config WHERE plan_id = ?", [$planId]);
    }
    foreach (['min_duties', 'max_duties', 'reporting_minutes'] as $k) $cfg[$k] = (int)$cfg[$k];
    $cfg['own_subject_rule'] = in_array($cfg['own_subject_rule'] ?? '', ['none', 'avoid', 'must'], true) ? $cfg['own_subject_rule'] : 'avoid';
    $cfg['avoid_own_subject'] = $cfg['own_subject_rule'] === 'avoid'; // kept for older clients
    $cfg['finalized'] = (bool)$cfg['finalized'];
    $plan['config'] = $cfg;
    return $plan;
}}

/** Sessions with classes + subjects, and the rooms each one needs. */
if (!function_exists('dutySessions')) {
function dutySessions(array $plan): array
{
    if (!$plan['classes']) return [];
    $ph = implode(',', array_fill(0, count($plan['classes']), '?'));
    $rows = Database::fetchAll(
        "SELECT exam_date, COALESCE(exam_time, '') AS exam_time, class, subjects FROM exam_routines
         WHERE year = ? AND exam_name = ? AND class IN ($ph) ORDER BY exam_date, exam_time, class",
        [$plan['year'], $plan['exam_name'], ...$plan['classes']]
    );
    // classes seated per room in this plan
    $roomClasses = [];
    foreach (Database::fetchAll(
        "SELECT a.room_id, s.class, COUNT(*) n FROM seat_assignments a JOIN students s ON s.id = a.student_id
         WHERE a.plan_id = ? GROUP BY a.room_id, s.class", [$plan['id']]) as $r) {
        $roomClasses[(int)$r['room_id']][$r['class']] = (int)$r['n'];
    }
    $rooms = [];
    if ($roomClasses) {
        foreach (Database::fetchAll("SELECT * FROM exam_rooms WHERE id IN (" . implode(',', array_keys($roomClasses)) . ") ORDER BY sort_order, name") as $r) {
            $rooms[] = seatPlanRoomOut($r);
        }
    }
    $sessions = [];
    foreach ($rows as $r) {
        $k = $r['exam_date'] . '|' . $r['exam_time'];
        if (!isset($sessions[$k])) $sessions[$k] = ['key' => $k, 'exam_date' => $r['exam_date'], 'exam_time' => $r['exam_time'], 'classes' => [], 'subjects' => [], 'rooms' => []];
        $sessions[$k]['classes'][] = $r['class'];
        foreach ((json_decode((string)$r['subjects'], true) ?: []) as $sub) $sessions[$k]['subjects'][] = $sub;
    }
    foreach ($sessions as &$s) {
        $s['classes'] = array_values(array_unique($s['classes']));
        $s['subjects'] = array_values(array_unique($s['subjects']));
        foreach ($rooms as $room) {
            $students = 0;
            foreach ($s['classes'] as $c) $students += $roomClasses[$room['id']][$c] ?? 0;
            if ($students > 0 && $room['invigilators'] > 0) {
                $s['rooms'][] = ['room_id' => $room['id'], 'name' => $room['name'], 'need' => $room['invigilators'], 'teacher_gender' => $room['teacher_gender'], 'students' => $students];
            }
        }
    }
    unset($s);
    return array_values($sessions);
}}

if (!function_exists('dutyTeachers')) {
function dutyTeachers(int $planId): array
{
    $rows = Database::fetchAll(
        "SELECT t.id, t.name, t.gender, t.designation, t.subject, t.mobile,
                COALESCE(r.excluded, 0) AS excluded, r.min_duties, r.max_duties,
                COALESCE(g.disabled, 0) AS disabled, g.priority
         FROM teachers t LEFT JOIN duty_teacher_rules r ON r.teacher_id = t.id AND r.plan_id = ?
         LEFT JOIN duty_teacher_global g ON g.teacher_id = t.id
         ORDER BY t.sort_order, t.name", [$planId]);
    foreach ($rows as &$t) {
        $t['id'] = (int)$t['id'];
        $t['name'] = trim($t['name']);
        $t['excluded'] = (bool)$t['excluded'];
        $t['disabled'] = (bool)$t['disabled'];
        $t['priority'] = $t['priority'] === null ? null : (int)$t['priority'];
        $t['off'] = $t['excluded'] || $t['disabled']; // not available for this exam
        $t['min_duties'] = $t['min_duties'] === null ? null : (int)$t['min_duties'];
        $t['max_duties'] = $t['max_duties'] === null ? null : (int)$t['max_duties'];
    }
    unset($t);
    return $rows;
}}

/** Does this teacher's subject sit in this session? */
if (!function_exists('dutyOwnSubject')) {
function dutyOwnSubject(?string $teacherSubject, array $sessionSubjects): bool
{
    $ts = mb_strtolower(trim((string)$teacherSubject));
    if ($ts === '') return false;
    foreach ($sessionSubjects as $sub) {
        $s = mb_strtolower(trim((string)$sub));
        if ($s === $ts || str_contains($s, $ts) || str_contains($ts, $s)) return true;
    }
    return false;
}}

if (!function_exists('dutyLimits')) {
function dutyLimits(array $t, array $cfg): array
{
    return [$t['min_duties'] ?? $cfg['min_duties'], $t['max_duties'] ?? $cfg['max_duties']];
}}

/** Rule check over the current schedule → list of human-readable warnings. */
if (!function_exists('dutyWarnings')) {
function dutyWarnings(array $plan, array $sessions, array $teachers, array $duties): array
{
    $cfg = $plan['config'];
    $w = [];
    $byT = [];
    foreach ($teachers as $t) $byT[$t['id']] = $t;
    $count = [];
    $cell = [];
    foreach ($duties as $d) {
        $count[$d['teacher_id']] = ($count[$d['teacher_id']] ?? 0) + 1;
        $cell[$d['exam_date'] . '|' . $d['exam_time']][$d['room_id']][] = $d;
    }
    foreach ($sessions as $s) {
        foreach ($s['rooms'] as $room) {
            $list = $cell[$s['key']][$room['room_id']] ?? [];
            $label = date('d M', strtotime($s['exam_date'])) . ($s['exam_time'] ? " {$s['exam_time']}" : '') . " · {$room['name']}";
            if (count($list) < $room['need']) $w[] = "$label: needs {$room['need']} invigilator(s), has " . count($list);
            $g = array_map(fn($d) => $byT[$d['teacher_id']]['gender'] ?? '', $list);
            if ($room['teacher_gender'] === 'male' && in_array('female', $g, true)) $w[] = "$label: male invigilators required";
            if ($room['teacher_gender'] === 'female' && in_array('male', $g, true)) $w[] = "$label: female invigilators required";
            if ($room['teacher_gender'] === 'mixed' && count($list) >= 2 && count(array_unique($g)) < 2) $w[] = "$label: needs both male and female invigilators";
            foreach ($list as $d) {
                $t = $byT[$d['teacher_id']] ?? null;
                if (!$t) continue;
                if ($t['disabled']) $w[] = "$label: {$t['name']} is disabled from exam duty";
                elseif ($t['excluded']) $w[] = "$label: {$t['name']} is excluded from this exam's duty";
                if ($cfg['own_subject_rule'] === 'avoid' && dutyOwnSubject($t['subject'], $s['subjects'])) $w[] = "$label: {$t['name']} teaches {$t['subject']} (own subject exam)";
            }
        }
    }
    // "must have duty on own subject's exam day": every available subject teacher needs a duty that session
    if ($cfg['own_subject_rule'] === 'must') {
        $busy = [];
        foreach ($duties as $d) $busy[$d['exam_date'] . '|' . $d['exam_time']][$d['teacher_id']] = true;
        foreach ($sessions as $s) {
            if (!$s['rooms']) continue;
            foreach ($teachers as $t) {
                if ($t['off'] || !dutyOwnSubject($t['subject'], $s['subjects']) || isset($busy[$s['key']][$t['id']])) continue;
                $w[] = date('d M', strtotime($s['exam_date'])) . ": {$t['name']} ({$t['subject']}) has no duty on own subject's exam day";
            }
        }
    }
    foreach ($teachers as $t) {
        if ($t['off']) continue;
        [$min, $max] = dutyLimits($t, $cfg);
        $n = $count[$t['id']] ?? 0;
        if ($n > $max) $w[] = "{$t['name']}: $n duties, above maximum $max";
        if ($n < $min) $w[] = "{$t['name']}: $n duties, below minimum $min";
    }
    return $w;
}}

if (!function_exists('dutyLoadDuties')) {
function dutyLoadDuties(int $planId): array
{
    $rows = Database::fetchAll(
        "SELECT d.id, d.exam_date, d.exam_time, d.room_id, d.teacher_id, d.fixed, r.name AS room_name, t.name AS teacher_name
         FROM exam_duties d JOIN exam_rooms r ON r.id = d.room_id JOIN teachers t ON t.id = d.teacher_id
         WHERE d.plan_id = ? ORDER BY d.exam_date, d.exam_time, r.sort_order, r.name, t.name", [$planId]);
    foreach ($rows as &$d) {
        foreach (['id', 'room_id', 'teacher_id'] as $k) $d[$k] = (int)$d[$k];
        $d['fixed'] = (bool)$d['fixed'];
        $d['teacher_name'] = trim($d['teacher_name']);
    }
    unset($d);
    return $rows;
}}

if (!function_exists('dutyRequireOpen')) {
function dutyRequireOpen(array $plan): void
{
    if ($plan['config']['finalized']) {
        Response::validationError(['This duty schedule is finalized. Un-finalize it first to make changes.']);
    }
}}

// ------------------------------------------------------------------ routes
// PUT /api/admin/exam-duty-settings/teachers/{tid}  { disabled }   — applies to ALL exams (Session 83)
$router->put('/api/admin/exam-duty-settings/teachers/{tid}', function ($params) {
    Auth::requireRole('admin');
    dutyEnsureSchema();
    $tid = (int)$params['tid'];
    $t = Database::fetch("SELECT id, name FROM teachers WHERE id = ?", [$tid]);
    if (!$t) Response::notFound('Teacher not found');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    Database::query(
        "INSERT INTO duty_teacher_global (teacher_id, disabled) VALUES (?, ?) ON DUPLICATE KEY UPDATE disabled = VALUES(disabled)",
        [$tid, !empty($d['disabled']) ? 1 : 0]
    );
    Response::success(null, trim($t['name']) . (!empty($d['disabled']) ? ' disabled from exam duty (all exams)' : ' enabled for exam duty'));
});

// PUT /api/admin/exam-duty-settings/priority  { order: [teacherId, …] }  — 1 = first to get extra duties
$router->put('/api/admin/exam-duty-settings/priority', function () {
    Auth::requireRole('admin');
    dutyEnsureSchema();
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $order = array_values(array_unique(array_map('intval', (array)($d['order'] ?? []))));
    if (count($order) > 500) Response::validationError(['Too many teachers in the priority list']);
    if ($order) {
        $found = Database::fetchAll("SELECT id FROM teachers WHERE id IN (" . implode(',', array_fill(0, count($order), '?')) . ")", $order);
        if (count($found) !== count($order)) Response::validationError(['Unknown teacher in the priority list']);
    }
    Database::query("UPDATE duty_teacher_global SET priority = NULL");
    foreach ($order as $i => $tid) {
        Database::query(
            "INSERT INTO duty_teacher_global (teacher_id, priority) VALUES (?, ?) ON DUPLICATE KEY UPDATE priority = VALUES(priority)",
            [$tid, $i + 1]
        );
    }
    Response::success(['count' => count($order)], count($order) ? 'Priority list saved (' . count($order) . ' teachers)' : 'Priority list cleared');
});

$router->get('/api/admin/exam-duty/plans', function () {
    Auth::requireRole('admin');
    dutyEnsureSchema();
    Response::success(Database::fetchAll(
        "SELECT p.id, p.name, p.year, p.exam_name, p.classes, COALESCE(c.finalized, 0) AS finalized,
                (SELECT COUNT(*) FROM exam_duties d WHERE d.plan_id = p.id) AS duties
         FROM seat_plans p LEFT JOIN duty_config c ON c.plan_id = p.id ORDER BY p.id DESC"));
});

$router->get('/api/admin/exam-duty/{plan}', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    $sessions = dutySessions($plan);
    $teachers = dutyTeachers($plan['id']);
    $duties = dutyLoadDuties($plan['id']);
    Response::success([
        'plan' => $plan,
        'sessions' => $sessions,
        'teachers' => $teachers,
        'duties' => $duties,
        'warnings' => dutyWarnings($plan, $sessions, $teachers, $duties),
    ]);
});

$router->put('/api/admin/exam-duty/{plan}/config', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $min = filter_var($d['min_duties'] ?? $plan['config']['min_duties'], FILTER_VALIDATE_INT);
    $max = filter_var($d['max_duties'] ?? $plan['config']['max_duties'], FILTER_VALIDATE_INT);
    $errors = [];
    if ($min === false || $min < 0 || $min > 100) $errors[] = 'Minimum duties must be 0–100';
    if ($max === false || $max < 0 || $max > 100) $errors[] = 'Maximum duties must be 0–100';
    if (!$errors && $min > $max) $errors[] = 'Minimum duties cannot be more than maximum';
    // own_subject_rule: none | avoid | must (older clients send avoid_own_subject true/false)
    $rule = $d['own_subject_rule'] ?? (array_key_exists('avoid_own_subject', $d) ? (!empty($d['avoid_own_subject']) ? 'avoid' : 'none') : $plan['config']['own_subject_rule']);
    if (!in_array($rule, ['none', 'avoid', 'must'], true)) $errors[] = 'Own-subject rule must be none, avoid or must';
    $issue = trim((string)($d['issue_date'] ?? ''));
    if ($issue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) $errors[] = 'Issue date must be YYYY-MM-DD';
    if ($errors) Response::validationError($errors);
    Database::query(
        "UPDATE duty_config SET min_duties = ?, max_duties = ?, avoid_own_subject = ?, own_subject_rule = ?, memo_no = ?, issue_date = ?,
                signatory_name = ?, signatory_designation = ?, reporting_minutes = ?, instructions = ? WHERE plan_id = ?",
        [$min, $max, $rule === 'avoid' ? 1 : 0, $rule,
         mb_substr(trim((string)($d['memo_no'] ?? '')), 0, 100) ?: null, $issue ?: null,
         mb_substr(trim((string)($d['signatory_name'] ?? '')), 0, 150) ?: null,
         mb_substr(trim((string)($d['signatory_designation'] ?? '')), 0, 150) ?: null,
         max(0, min(180, (int)($d['reporting_minutes'] ?? 30))),
         trim((string)($d['instructions'] ?? '')) ?: null, $plan['id']]
    );
    Response::success(dutyLoadPlan($plan['id'])['config'], 'Settings saved');
});

$router->put('/api/admin/exam-duty/{plan}/teachers/{tid}', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    $tid = (int)$params['tid'];
    if (!Database::fetch("SELECT id FROM teachers WHERE id = ?", [$tid])) Response::notFound('Teacher not found');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $int = function ($v) {
        if ($v === null || $v === '') return null;
        $i = filter_var($v, FILTER_VALIDATE_INT);
        if ($i === false || $i < 0 || $i > 100) Response::validationError(['Per-teacher limits must be 0–100 or empty']);
        return $i;
    };
    $min = $int($d['min_duties'] ?? null);
    $max = $int($d['max_duties'] ?? null);
    if ($min !== null && $max !== null && $min > $max) Response::validationError(['Minimum cannot be more than maximum']);
    Database::query(
        "INSERT INTO duty_teacher_rules (plan_id, teacher_id, excluded, min_duties, max_duties) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE excluded = VALUES(excluded), min_duties = VALUES(min_duties), max_duties = VALUES(max_duties)",
        [$plan['id'], $tid, !empty($d['excluded']) ? 1 : 0, $min, $max]
    );
    Response::success(null, 'Teacher rule saved');
});

$router->post('/api/admin/exam-duty/{plan}/generate', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    dutyRequireOpen($plan);
    $cfg = $plan['config'];
    $sessions = dutySessions($plan);
    if (!$sessions) Response::validationError(['No exam routine found for this exam and class(es). Add the Exam Routine first.']);
    $teachers = dutyTeachers($plan['id']);
    $byT = [];
    foreach ($teachers as $t) $byT[$t['id']] = $t;

    // keep fixed (pre-assigned) duties, drop the rest
    Database::query("DELETE FROM exam_duties WHERE plan_id = ? AND fixed = 0", [$plan['id']]);
    $count = [];
    $busy = [];   // session key => [teacher_id => true]
    $cell = [];   // session key => room_id => [teacher ids]
    foreach (dutyLoadDuties($plan['id']) as $d) {
        $k = $d['exam_date'] . '|' . $d['exam_time'];
        $count[$d['teacher_id']] = ($count[$d['teacher_id']] ?? 0) + 1;
        $busy[$k][$d['teacher_id']] = true;
        $cell[$k][$d['room_id']][] = $d['teacher_id'];
    }

    // ---- targets (Session 83): everyone gets their minimum; the extra duties that are still needed go
    // down the priority list first (each up to their maximum); whatever remains is spread over the rest.
    $totalSlots = 0;
    foreach ($sessions as $s) foreach ($s['rooms'] as $r) $totalSlots += $r['need'];
    $avail = array_values(array_filter($teachers, fn($t) => !$t['off']));
    $target = [];
    foreach ($avail as $t) { [$mn, $mx] = dutyLimits($t, $cfg); $target[$t['id']] = min($mn, $mx); }
    $extra = $totalSlots - array_sum($target);
    $ranked = array_values(array_filter($avail, fn($t) => $t['priority'] !== null));
    usort($ranked, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($ranked as $t) {
        if ($extra <= 0) break;
        [, $mx] = dutyLimits($t, $cfg);
        $add = min($mx - $target[$t['id']], $extra);
        if ($add > 0) { $target[$t['id']] += $add; $extra -= $add; }
    }
    $rest = array_values(array_filter($avail, fn($t) => $t['priority'] === null));
    usort($rest, fn($a, $b) => (($a['id'] * 7919) % 1009) <=> (($b['id'] * 7919) % 1009));
    while ($extra > 0) {
        $gave = false;
        foreach ($rest as $t) {
            if ($extra <= 0) break;
            [, $mx] = dutyLimits($t, $cfg);
            if ($target[$t['id']] < $mx) { $target[$t['id']]++; $extra--; $gave = true; }
        }
        if (!$gave) break; // everyone at maximum — remaining slots will be reported as unfilled
    }

    $inserts = [];
    $nSessions = max(1, count($sessions));
    foreach ($sessions as $si => $s) {
        foreach ($s['rooms'] as $room) {
            $have = $cell[$s['key']][$room['room_id']] ?? [];
            while (count($have) < $room['need']) {
                // gender the next pick must have
                $need = null;
                $g = array_map(fn($id) => $byT[$id]['gender'] ?? '', $have);
                if (in_array($room['teacher_gender'], ['male', 'female'], true)) $need = $room['teacher_gender'];
                elseif ($room['teacher_gender'] === 'mixed') {
                    $left = $room['need'] - count($have);
                    $missing = array_values(array_diff(['male', 'female'], $g));
                    if ($left <= count($missing) && $missing) $need = $missing[0];
                }
                $best = null; $bestKey = null;
                foreach ($teachers as $t) {
                    if ($t['off'] || isset($busy[$s['key']][$t['id']])) continue;
                    if ($need && $t['gender'] !== $need) continue;
                    [$min, $max] = dutyLimits($t, $cfg);
                    $n = $count[$t['id']] ?? 0;
                    if ($n >= $max) continue;
                    $own = dutyOwnSubject($t['subject'], $s['subjects']);
                    if ($cfg['own_subject_rule'] === 'avoid' && $own) continue;
                    $must = $cfg['own_subject_rule'] === 'must' && $own;
                    $tg = $target[$t['id']] ?? $min;
                    // 1 must-rule teachers first
                    // 2 furthest behind a fair pace: by session k of N a teacher with target T "should" have
                    //   T*k/N duties. Priority teachers (higher T) fall behind faster, so their extra duties are
                    //   spread over the whole exam instead of piling up in the last sessions; everyone still
                    //   heads to their own target (minimum first, since T ≥ minimum)
                    // 3 below minimum  4 priority rank  5 stable spread across sessions
                    $lag = round($tg * ($si + 1) / $nSessions - $n, 4);
                    $key = [$must ? 0 : 1, -$lag, $n < $min ? 0 : 1, $t['priority'] ?? 100000, ($t['id'] * 7919 + $si * 104729) % 1000];
                    if ($bestKey === null || $key < $bestKey) { $best = $t; $bestKey = $key; }
                }
                if (!$best) break; // nobody eligible: reported as a warning
                $have[] = $best['id'];
                $busy[$s['key']][$best['id']] = true;
                $count[$best['id']] = ($count[$best['id']] ?? 0) + 1;
                $inserts[] = [$plan['id'], $s['exam_date'], $s['exam_time'], $room['room_id'], $best['id']];
            }
            $cell[$s['key']][$room['room_id']] = $have;
        }
    }
    foreach (array_chunk($inserts, 300) as $chunk) {
        $vals = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,0)'));
        Database::query("INSERT INTO exam_duties (plan_id, exam_date, exam_time, room_id, teacher_id, fixed) VALUES $vals", array_merge(...$chunk));
    }
    $duties = dutyLoadDuties($plan['id']);
    $warnings = dutyWarnings($plan, $sessions, $teachers, $duties);
    Response::success(['assigned' => count($inserts), 'total' => count($duties), 'warnings' => $warnings],
        count($inserts) . ' duties allocated' . ($warnings ? ' — ' . count($warnings) . ' warning(s) to review' : ''));
});

$router->post('/api/admin/exam-duty/{plan}/duties', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    dutyRequireOpen($plan);
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $date = (string)($d['exam_date'] ?? '');
    $time = (string)($d['exam_time'] ?? '');
    $roomId = (int)($d['room_id'] ?? 0);
    $tid = (int)($d['teacher_id'] ?? 0);
    $session = null;
    foreach (dutySessions($plan) as $s) if ($s['exam_date'] === $date && $s['exam_time'] === $time) $session = $s;
    if (!$session) Response::validationError(['That date / time is not an exam session of this exam']);
    if (!in_array($roomId, array_column($session['rooms'], 'room_id'), true)) Response::validationError(['That room is not used in this session']);
    $t = Database::fetch("SELECT id, name FROM teachers WHERE id = ?", [$tid]);
    if (!$t) Response::validationError(['Select a teacher']);
    if (Database::fetch("SELECT id FROM exam_duties WHERE plan_id = ? AND exam_date = ? AND exam_time = ? AND teacher_id = ?", [$plan['id'], $date, $time, $tid])) {
        Response::validationError([trim($t['name']) . ' already has a duty in this session']);
    }
    $id = Database::insert('exam_duties', ['plan_id' => $plan['id'], 'exam_date' => $date, 'exam_time' => $time, 'room_id' => $roomId, 'teacher_id' => $tid, 'fixed' => !empty($d['fixed']) ? 1 : 0]);
    Response::success(['id' => (int)$id], 'Duty added');
});

$router->put('/api/admin/exam-duty/{plan}/duties/{id}', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    dutyRequireOpen($plan);
    $duty = Database::fetch("SELECT * FROM exam_duties WHERE id = ? AND plan_id = ?", [(int)$params['id'], $plan['id']]);
    if (!$duty) Response::notFound('Duty not found');
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    if (array_key_exists('teacher_id', $d)) {
        $tid = (int)$d['teacher_id'];
        if (!Database::fetch("SELECT id FROM teachers WHERE id = ?", [$tid])) Response::validationError(['Teacher not found']);
        $clash = Database::fetch("SELECT id FROM exam_duties WHERE plan_id = ? AND exam_date = ? AND exam_time = ? AND teacher_id = ? AND id <> ?",
            [$plan['id'], $duty['exam_date'], $duty['exam_time'], $tid, $duty['id']]);
        if ($clash) Response::validationError(['That teacher already has a duty in this session']);
        Database::query("UPDATE exam_duties SET teacher_id = ? WHERE id = ?", [$tid, $duty['id']]);
    }
    if (array_key_exists('fixed', $d)) {
        Database::query("UPDATE exam_duties SET fixed = ? WHERE id = ?", [!empty($d['fixed']) ? 1 : 0, $duty['id']]);
    }
    Response::success(null, 'Duty updated');
});

$router->delete('/api/admin/exam-duty/{plan}/duties/{id}', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    dutyRequireOpen($plan);
    Database::query("DELETE FROM exam_duties WHERE id = ? AND plan_id = ?", [(int)$params['id'], $plan['id']]);
    Response::success(null, 'Duty removed');
});

$router->post('/api/admin/exam-duty/{plan}/finalize', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    $d = json_decode(file_get_contents('php://input'), true) ?: [];
    $final = !empty($d['finalized']);
    if ($final) {
        $n = Database::fetch("SELECT COUNT(*) n FROM exam_duties WHERE plan_id = ?", [$plan['id']]);
        if ((int)$n['n'] === 0) Response::validationError(['There are no duties to finalize. Generate or add duties first.']);
    }
    Database::query("UPDATE duty_config SET finalized = ?, finalized_at = ? WHERE plan_id = ?", [$final ? 1 : 0, $final ? date('Y-m-d H:i:s') : null, $plan['id']]);
    Response::success(['finalized' => $final], $final ? 'Duty schedule finalized' : 'Duty schedule re-opened for changes');
});

$router->get('/api/admin/exam-duty/{plan}/letters', function ($params) {
    Auth::requireRole('admin');
    $plan = dutyLoadPlan((int)$params['plan']);
    if (!$plan['config']['finalized']) {
        Response::validationError(['Finalize the duty schedule first (Hall Duty Allocation → Finalize). Letters use the finalized schedule.']);
    }
    $sessions = [];
    foreach (dutySessions($plan) as $s) $sessions[$s['key']] = $s;
    $byTeacher = [];
    foreach (dutyLoadDuties($plan['id']) as $d) {
        $s = $sessions[$d['exam_date'] . '|' . $d['exam_time']] ?? null;
        $byTeacher[$d['teacher_id']][] = [
            'exam_date' => $d['exam_date'], 'exam_time' => $d['exam_time'], 'room' => $d['room_name'],
            'subjects' => $s['subjects'] ?? [], 'classes' => $s['classes'] ?? [],
        ];
    }
    $teachers = [];
    foreach (dutyTeachers($plan['id']) as $t) {
        if (empty($byTeacher[$t['id']])) continue;
        $teachers[] = ['id' => $t['id'], 'name' => $t['name'], 'designation' => $t['designation'], 'subject' => $t['subject'], 'gender' => $t['gender'], 'duties' => $byTeacher[$t['id']]];
    }
    Response::success(['plan' => $plan, 'teachers' => $teachers]);
});
