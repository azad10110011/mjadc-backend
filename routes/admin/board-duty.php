<?php

// Board Exam hall duty (Session 99) — admin only (not grantable). Same rules as the internal Hall Duty
// Allocation (min/max, own-subject rule, priority, fair pace, pinned duties), but:
//   • teachers = our teachers (optional) + external teachers hired from other colleges  (key t<id> / x<id>)
//   • rooms come from all venues; a room needs invigilators in a session when any candidate seated
//     there takes one of that session's subjects
//   • invigilators per room = the room's number, or ceil(candidates / N) when the exam sets
//     "candidates per invigilator" N
//
//   GET  /api/admin/board-duty/{exam}                      planner data + warnings
//   PUT  /api/admin/board-duty/{exam}/config               min/max, own-subject rule, use our teachers, letter details
//   PUT  /api/admin/board-duty/{exam}/teachers/{key}       exclude / per-teacher min-max
//   PUT  /api/admin/board-duty/{exam}/priority             { order: [key, …] }
//   POST /api/admin/board-duty/{exam}/generate
//   POST /api/admin/board-duty/{exam}/duties · PUT/DELETE /api/admin/board-duty/{exam}/duties/{id}
//   POST /api/admin/board-duty/{exam}/finalize             { finalized }
//   GET  /api/admin/board-duty/{exam}/letters              finalized schedules only

if (!function_exists('boardDutySchema')) {
function boardDutySchema(): void
{
    static $done = false;
    if ($done) return;
    boardEnsureSchema();
    $opt = "ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
    Database::query("CREATE TABLE IF NOT EXISTS board_duty_config (
        exam_id INT PRIMARY KEY,
        min_duties INT NOT NULL DEFAULT 0,
        max_duties INT NOT NULL DEFAULT 5,
        own_subject_rule VARCHAR(10) NOT NULL DEFAULT 'avoid',
        use_internal TINYINT(1) NOT NULL DEFAULT 1,
        finalized TINYINT(1) NOT NULL DEFAULT 0,
        finalized_at DATETIME NULL,
        memo_no VARCHAR(100) NULL,
        issue_date DATE NULL,
        signatory_name VARCHAR(150) NULL,
        signatory_designation VARCHAR(150) NULL,
        reporting_minutes INT NOT NULL DEFAULT 30,
        instructions TEXT NULL,
        CONSTRAINT fk_bdc_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_duty_rules (
        exam_id INT NOT NULL,
        tkey VARCHAR(20) NOT NULL,
        excluded TINYINT(1) NOT NULL DEFAULT 0,
        min_duties INT NULL,
        max_duties INT NULL,
        priority INT NULL,
        PRIMARY KEY (exam_id, tkey),
        CONSTRAINT fk_bdr_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE
    ) $opt");
    Database::query("CREATE TABLE IF NOT EXISTS board_duties (
        id INT AUTO_INCREMENT PRIMARY KEY,
        exam_id INT NOT NULL,
        exam_date DATE NOT NULL,
        exam_time VARCHAR(20) NOT NULL DEFAULT '',
        room_id INT NOT NULL,
        tkey VARCHAR(20) NOT NULL,
        fixed TINYINT(1) NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_bd_session (exam_id, exam_date, exam_time, tkey),
        KEY idx_bd_room (exam_id, exam_date, exam_time, room_id),
        CONSTRAINT fk_bd_exam FOREIGN KEY (exam_id) REFERENCES board_exams(id) ON DELETE CASCADE,
        CONSTRAINT fk_bd_room FOREIGN KEY (room_id) REFERENCES board_rooms(id) ON DELETE CASCADE
    ) $opt");
    $done = true;
}}

if (!function_exists('boardDutyExam')) {
function boardDutyExam(int $id): array
{
    boardDutySchema();
    $exam = boardLoadExam($id);
    $cfg = Database::fetch("SELECT * FROM board_duty_config WHERE exam_id = ?", [$exam['id']]);
    if (!$cfg) {
        Database::query("INSERT INTO board_duty_config (exam_id) VALUES (?)", [$exam['id']]);
        $cfg = Database::fetch("SELECT * FROM board_duty_config WHERE exam_id = ?", [$exam['id']]);
    }
    foreach (['min_duties', 'max_duties', 'reporting_minutes'] as $k) $cfg[$k] = (int)$cfg[$k];
    $cfg['own_subject_rule'] = in_array($cfg['own_subject_rule'], ['none', 'avoid', 'must'], true) ? $cfg['own_subject_rule'] : 'avoid';
    $cfg['use_internal'] = (bool)$cfg['use_internal'];
    $cfg['finalized'] = (bool)$cfg['finalized'];
    $exam['config'] = $cfg;
    return $exam;
}}

/** Sessions: date/time, subjects, and the rooms (all venues) that need invigilators. */
if (!function_exists('boardDutySessions')) {
function boardDutySessions(array $exam): array
{
    $routine = Database::fetchAll("SELECT * FROM board_routine WHERE exam_id = ? ORDER BY exam_date, exam_time", [$exam['id']]);
    if (!$routine) return [];
    $rooms = [];
    foreach (boardVenues($exam['id']) as $v) foreach ($v['rooms'] as $r) $rooms[$r['id']] = $r + ['venue' => $v['name'], 'label' => "{$v['name']} · {$r['name']}"];
    $seatTok = []; // room_id => [token sets]
    foreach (boardSeatRows($exam['id']) as $s) $seatTok[$s['room_id']][] = boardSubjectTokens($s['subjects']);
    $sessions = [];
    foreach ($routine as $r) {
        $k = $r['exam_date'] . '|' . $r['exam_time'];
        $sessions[$k] ??= ['key' => $k, 'exam_date' => $r['exam_date'], 'exam_time' => $r['exam_time'], 'subjects' => [], 'tokens' => [], 'rooms' => []];
        $sessions[$k]['subjects'][] = trim($r['subject_code'] . ' ' . $r['subject_name']);
        foreach ([$r['subject_code'], $r['subject_name']] as $t) if (trim($t) !== '') $sessions[$k]['tokens'][mb_strtolower(trim($t))] = true;
    }
    $per = (int)$exam['per_invigilator'];
    foreach ($sessions as &$s) {
        foreach ($seatTok as $rid => $list) {
            $n = 0;
            foreach ($list as $tok) if (array_intersect_key($tok, $s['tokens'])) $n++;
            if (!$n || !isset($rooms[$rid])) continue;
            $need = $per > 0 ? (int)ceil($n / $per) : $rooms[$rid]['invigilators'];
            if ($need <= 0) continue;
            $s['rooms'][] = ['room_id' => $rid, 'name' => $rooms[$rid]['label'], 'venue' => $rooms[$rid]['venue'], 'need' => $need,
                'teacher_gender' => $rooms[$rid]['teacher_gender'], 'students' => $n, 'sort' => [$rooms[$rid]['venue'], $rooms[$rid]['sort_order'], $rooms[$rid]['name']]];
        }
        usort($s['rooms'], fn($a, $b) => $a['sort'] <=> $b['sort']);
        foreach ($s['rooms'] as &$r) unset($r['sort']);
        unset($r, $s['tokens']);
        $s['subjects'] = array_values(array_unique($s['subjects']));
    }
    unset($s);
    return array_values($sessions);
}}

/** Teacher pool: our teachers (if used) + enabled external teachers, with this exam's rules. */
if (!function_exists('boardDutyTeachers')) {
function boardDutyTeachers(array $exam): array
{
    $rules = [];
    foreach (Database::fetchAll("SELECT * FROM board_duty_rules WHERE exam_id = ?", [$exam['id']]) as $r) $rules[$r['tkey']] = $r;
    $out = [];
    $add = function (string $key, array $t, string $type, bool $globalOff) use (&$out, $rules) {
        $r = $rules[$key] ?? [];
        $excluded = !empty($r['excluded']);
        $out[] = [
            'key' => $key, 'type' => $type, 'name' => trim($t['name']), 'gender' => $t['gender'] === 'female' ? 'female' : 'male',
            'designation' => $t['designation'] ?? '', 'subject' => $t['subject'] ?? null, 'college' => $t['college'] ?? '', 'mobile' => $t['mobile'] ?? null,
            'excluded' => $excluded, 'disabled' => $globalOff, 'off' => $excluded || $globalOff,
            'min_duties' => isset($r['min_duties']) ? (int)$r['min_duties'] : null,
            'max_duties' => isset($r['max_duties']) ? (int)$r['max_duties'] : null,
            'priority' => isset($r['priority']) ? (int)$r['priority'] : null,
        ];
    };
    if ($exam['config']['use_internal']) {
        dutyEnsureSchema(); // duty_teacher_global (disabled from exam duty — all exams)
        foreach (Database::fetchAll(
            "SELECT t.id, t.name, t.gender, t.designation, t.subject, t.mobile, COALESCE(g.disabled, 0) AS disabled
             FROM teachers t LEFT JOIN duty_teacher_global g ON g.teacher_id = t.id ORDER BY t.sort_order, t.name") as $t) {
            $add('t' . $t['id'], $t + ['college' => ''], 'internal', (bool)$t['disabled']);
        }
    }
    foreach (Database::fetchAll("SELECT * FROM board_external_teachers ORDER BY college, name") as $t) {
        $add('x' . $t['id'], $t, 'external', !$t['enabled']);
    }
    return $out;
}}

if (!function_exists('boardDutyLoad')) {
function boardDutyLoad(int $examId, array $teachers): array
{
    $byKey = array_column($teachers, null, 'key');
    $rows = Database::fetchAll(
        "SELECT d.*, r.name AS room_name, v.name AS venue_name FROM board_duties d
         JOIN board_rooms r ON r.id = d.room_id JOIN board_venues v ON v.id = r.venue_id
         WHERE d.exam_id = ? ORDER BY d.exam_date, d.exam_time, v.sort_order, v.name, r.sort_order, r.name", [$examId]);
    foreach ($rows as &$d) {
        $d['id'] = (int)$d['id']; $d['room_id'] = (int)$d['room_id']; $d['fixed'] = (bool)$d['fixed'];
        $d['room_label'] = "{$d['venue_name']} · {$d['room_name']}";
        $t = $byKey[$d['tkey']] ?? null;
        $d['teacher_name'] = $t['name'] ?? '(removed teacher)';
        $d['teacher_type'] = $t['type'] ?? (str_starts_with($d['tkey'], 'x') ? 'external' : 'internal');
    }
    unset($d);
    return $rows;
}}

if (!function_exists('boardDutyWarnings')) {
function boardDutyWarnings(array $exam, array $sessions, array $teachers, array $duties): array
{
    $cfg = $exam['config'];
    $w = []; $byT = array_column($teachers, null, 'key'); $count = []; $cell = [];
    foreach ($duties as $d) {
        $count[$d['tkey']] = ($count[$d['tkey']] ?? 0) + 1;
        $cell[$d['exam_date'] . '|' . $d['exam_time']][$d['room_id']][] = $d;
    }
    foreach ($sessions as $s) {
        foreach ($s['rooms'] as $room) {
            $list = $cell[$s['key']][$room['room_id']] ?? [];
            $label = date('d M', strtotime($s['exam_date'])) . ($s['exam_time'] ? " {$s['exam_time']}" : '') . " · {$room['name']}";
            if (count($list) < $room['need']) $w[] = "$label: needs {$room['need']} invigilator(s), has " . count($list);
            $g = array_map(fn($d) => $byT[$d['tkey']]['gender'] ?? '', $list);
            if ($room['teacher_gender'] === 'male' && in_array('female', $g, true)) $w[] = "$label: male invigilators required";
            if ($room['teacher_gender'] === 'female' && in_array('male', $g, true)) $w[] = "$label: female invigilators required";
            if ($room['teacher_gender'] === 'mixed' && count($list) >= 2 && count(array_unique($g)) < 2) $w[] = "$label: needs both male and female invigilators";
            foreach ($list as $d) {
                $t = $byT[$d['tkey']] ?? null;
                if (!$t) { $w[] = "$label: a duty belongs to a teacher who is no longer in the list"; continue; }
                if ($t['off']) $w[] = "$label: {$t['name']} is not available (disabled / excluded)";
                if ($cfg['own_subject_rule'] === 'avoid' && dutyOwnSubject($t['subject'], $s['subjects'])) $w[] = "$label: {$t['name']} teaches {$t['subject']} (own subject exam)";
            }
        }
    }
    if ($cfg['own_subject_rule'] === 'must') {
        $busy = [];
        foreach ($duties as $d) $busy[$d['exam_date'] . '|' . $d['exam_time']][$d['tkey']] = true;
        foreach ($sessions as $s) {
            if (!$s['rooms']) continue;
            foreach ($teachers as $t) {
                if ($t['off'] || !dutyOwnSubject($t['subject'], $s['subjects']) || isset($busy[$s['key']][$t['key']])) continue;
                $w[] = date('d M', strtotime($s['exam_date'])) . ": {$t['name']} ({$t['subject']}) has no duty on own subject's exam day";
            }
        }
    }
    foreach ($teachers as $t) {
        if ($t['off']) continue;
        [$min, $max] = dutyLimits($t, $cfg);
        $n = $count[$t['key']] ?? 0;
        if ($n > $max) $w[] = "{$t['name']}: $n duties, above maximum $max";
        if ($n < $min) $w[] = "{$t['name']}: $n duties, below minimum $min";
    }
    return $w;
}}

if (!function_exists('boardDutyOpen')) {
function boardDutyOpen(array $exam): void
{
    if ($exam['config']['finalized']) Response::validationError(['This duty schedule is finalized. Re-open it first to make changes.']);
}}

if (!function_exists('boardDutyKeyValid')) {
function boardDutyKeyValid(array $teachers, string $key): array
{
    foreach ($teachers as $t) if ($t['key'] === $key) return $t;
    Response::validationError(['Teacher not found (or not in this exam\'s teacher list)']);
}}

// ------------------------------------------------------------------ routes
$router->get('/api/admin/board-duty/{exam}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    $sessions = boardDutySessions($exam);
    $teachers = boardDutyTeachers($exam);
    $duties = boardDutyLoad($exam['id'], $teachers);
    Response::success(['exam' => $exam, 'sessions' => $sessions, 'teachers' => $teachers, 'duties' => $duties,
        'warnings' => boardDutyWarnings($exam, $sessions, $teachers, $duties)]);
});

$router->put('/api/admin/board-duty/{exam}/config', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    $c = $exam['config'];
    $d = boardBody();
    $min = filter_var($d['min_duties'] ?? $c['min_duties'], FILTER_VALIDATE_INT);
    $max = filter_var($d['max_duties'] ?? $c['max_duties'], FILTER_VALIDATE_INT);
    $rule = $d['own_subject_rule'] ?? $c['own_subject_rule'];
    $issue = trim((string)($d['issue_date'] ?? $c['issue_date'] ?? ''));
    $errors = [];
    if ($min === false || $min < 0 || $min > 100) $errors[] = 'Minimum duties must be 0–100';
    if ($max === false || $max < 0 || $max > 100) $errors[] = 'Maximum duties must be 0–100';
    if (!$errors && $min > $max) $errors[] = 'Minimum duties cannot be more than maximum';
    if (!in_array($rule, ['none', 'avoid', 'must'], true)) $errors[] = 'Own-subject rule must be none, avoid or must';
    if ($issue !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $issue)) $errors[] = 'Issue date must be YYYY-MM-DD';
    $useInternal = array_key_exists('use_internal', $d) ? (!empty($d['use_internal']) ? 1 : 0) : ($c['use_internal'] ? 1 : 0);
    if ($c['finalized'] && $useInternal !== ($c['use_internal'] ? 1 : 0)) $errors[] = 'Re-open the duty schedule before changing which teachers are used';
    if ($errors) Response::validationError($errors);
    if (!$useInternal && $c['use_internal']) {
        // switching our own teachers off removes their duties (pinned too) in this exam
        Database::query("DELETE FROM board_duties WHERE exam_id = ? AND tkey LIKE 't%'", [$exam['id']]);
    }
    $pick = fn($k, $len) => array_key_exists($k, $d) ? (mb_substr(trim((string)$d[$k]), 0, $len) ?: null) : $c[$k];
    Database::query(
        "UPDATE board_duty_config SET min_duties = ?, max_duties = ?, own_subject_rule = ?, use_internal = ?, memo_no = ?, issue_date = ?,
                signatory_name = ?, signatory_designation = ?, reporting_minutes = ?, instructions = ? WHERE exam_id = ?",
        [$min, $max, $rule, $useInternal, $pick('memo_no', 100), $issue ?: null, $pick('signatory_name', 150), $pick('signatory_designation', 150),
         max(0, min(180, (int)($d['reporting_minutes'] ?? $c['reporting_minutes']))),
         array_key_exists('instructions', $d) ? (trim((string)$d['instructions']) ?: null) : $c['instructions'], $exam['id']]);
    Response::success(boardDutyExam($exam['id'])['config'], 'Settings saved');
});

$router->put('/api/admin/board-duty/{exam}/teachers/{key}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    $t = boardDutyKeyValid(boardDutyTeachers($exam), (string)$p['key']);
    $d = boardBody();
    $int = function ($v) {
        if ($v === null || $v === '') return null;
        $i = filter_var($v, FILTER_VALIDATE_INT);
        if ($i === false || $i < 0 || $i > 100) Response::validationError(['Per-teacher limits must be 0–100 or empty']);
        return $i;
    };
    $min = $int($d['min_duties'] ?? null); $max = $int($d['max_duties'] ?? null);
    if ($min !== null && $max !== null && $min > $max) Response::validationError(['Minimum cannot be more than maximum']);
    Database::query(
        "INSERT INTO board_duty_rules (exam_id, tkey, excluded, min_duties, max_duties) VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE excluded = VALUES(excluded), min_duties = VALUES(min_duties), max_duties = VALUES(max_duties)",
        [$exam['id'], $t['key'], !empty($d['excluded']) ? 1 : 0, $min, $max]);
    Response::success(null, "{$t['name']}: " . (!empty($d['excluded']) ? 'excluded from this exam' : 'rule saved'));
});

$router->put('/api/admin/board-duty/{exam}/priority', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    $keys = array_column(boardDutyTeachers($exam), 'key');
    $order = array_values(array_unique(array_map('strval', (array)(boardBody()['order'] ?? []))));
    foreach ($order as $k) if (!in_array($k, $keys, true)) Response::validationError(['Unknown teacher in the priority list']);
    Database::query("UPDATE board_duty_rules SET priority = NULL WHERE exam_id = ?", [$exam['id']]);
    foreach ($order as $i => $k) {
        Database::query("INSERT INTO board_duty_rules (exam_id, tkey, priority) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE priority = VALUES(priority)", [$exam['id'], $k, $i + 1]);
    }
    Response::success(null, $order ? 'Priority list saved (' . count($order) . ' teachers)' : 'Priority list cleared');
});

$router->post('/api/admin/board-duty/{exam}/generate', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    boardDutyOpen($exam);
    $cfg = $exam['config'];
    $sessions = boardDutySessions($exam);
    if (!$sessions) Response::validationError(['No routine yet. Add the board exam routine first.']);
    if (!array_filter($sessions, fn($s) => $s['rooms'])) Response::validationError(['No seated candidates take any routine subject. Generate the seat plan (and check subject codes) first.']);
    $teachers = boardDutyTeachers($exam);
    $byT = array_column($teachers, null, 'key');

    Database::query("DELETE FROM board_duties WHERE exam_id = ? AND fixed = 0", [$exam['id']]);
    $count = []; $busy = []; $cell = [];
    foreach (boardDutyLoad($exam['id'], $teachers) as $d) {
        $k = $d['exam_date'] . '|' . $d['exam_time'];
        $count[$d['tkey']] = ($count[$d['tkey']] ?? 0) + 1;
        $busy[$k][$d['tkey']] = true;
        $cell[$k][$d['room_id']][] = $d['tkey'];
    }
    // targets: minimum for everyone, extra slots down the priority list, the rest spread evenly
    $totalSlots = 0;
    foreach ($sessions as $s) foreach ($s['rooms'] as $r) $totalSlots += $r['need'];
    $avail = array_values(array_filter($teachers, fn($t) => !$t['off']));
    $target = [];
    foreach ($avail as $t) { [$mn, $mx] = dutyLimits($t, $cfg); $target[$t['key']] = min($mn, $mx); }
    $extra = $totalSlots - array_sum($target);
    $ranked = array_values(array_filter($avail, fn($t) => $t['priority'] !== null));
    usort($ranked, fn($a, $b) => $a['priority'] <=> $b['priority']);
    foreach ($ranked as $t) {
        if ($extra <= 0) break;
        [, $mx] = dutyLimits($t, $cfg);
        $add = min($mx - $target[$t['key']], $extra);
        if ($add > 0) { $target[$t['key']] += $add; $extra -= $add; }
    }
    $hash = fn(string $k) => crc32($k) % 1009;
    $rest = array_values(array_filter($avail, fn($t) => $t['priority'] === null));
    usort($rest, fn($a, $b) => $hash($a['key']) <=> $hash($b['key']));
    while ($extra > 0) {
        $gave = false;
        foreach ($rest as $t) {
            if ($extra <= 0) break;
            [, $mx] = dutyLimits($t, $cfg);
            if ($target[$t['key']] < $mx) { $target[$t['key']]++; $extra--; $gave = true; }
        }
        if (!$gave) break;
    }
    $inserts = [];
    $nS = max(1, count($sessions));
    foreach ($sessions as $si => $s) {
        foreach ($s['rooms'] as $room) {
            $have = $cell[$s['key']][$room['room_id']] ?? [];
            while (count($have) < $room['need']) {
                $need = null;
                $g = array_map(fn($k) => $byT[$k]['gender'] ?? '', $have);
                if (in_array($room['teacher_gender'], ['male', 'female'], true)) $need = $room['teacher_gender'];
                elseif ($room['teacher_gender'] === 'mixed') {
                    $missing = array_values(array_diff(['male', 'female'], $g));
                    if ($room['need'] - count($have) <= count($missing) && $missing) $need = $missing[0];
                }
                $best = null; $bestKey = null;
                foreach ($teachers as $t) {
                    if ($t['off'] || isset($busy[$s['key']][$t['key']])) continue;
                    if ($need && $t['gender'] !== $need) continue;
                    [$min, $max] = dutyLimits($t, $cfg);
                    $n = $count[$t['key']] ?? 0;
                    if ($n >= $max) continue;
                    $own = dutyOwnSubject($t['subject'], $s['subjects']);
                    if ($cfg['own_subject_rule'] === 'avoid' && $own) continue;
                    $must = $cfg['own_subject_rule'] === 'must' && $own;
                    $tg = $target[$t['key']] ?? $min;
                    $lag = round($tg * ($si + 1) / $nS - $n, 4);
                    $key = [$must ? 0 : 1, -$lag, $n < $min ? 0 : 1, $t['priority'] ?? 100000, ($hash($t['key']) * 7919 + $si * 104729) % 1000];
                    if ($bestKey === null || $key < $bestKey) { $best = $t; $bestKey = $key; }
                }
                if (!$best) break;
                $have[] = $best['key'];
                $busy[$s['key']][$best['key']] = true;
                $count[$best['key']] = ($count[$best['key']] ?? 0) + 1;
                $inserts[] = [$exam['id'], $s['exam_date'], $s['exam_time'], $room['room_id'], $best['key']];
            }
            $cell[$s['key']][$room['room_id']] = $have;
        }
    }
    foreach (array_chunk($inserts, 300) as $chunk) {
        $vals = implode(',', array_fill(0, count($chunk), '(?,?,?,?,?,0)'));
        Database::query("INSERT INTO board_duties (exam_id, exam_date, exam_time, room_id, tkey, fixed) VALUES $vals", array_merge(...$chunk));
    }
    $duties = boardDutyLoad($exam['id'], $teachers);
    $warnings = boardDutyWarnings($exam, $sessions, $teachers, $duties);
    Response::success(['assigned' => count($inserts), 'total' => count($duties), 'warnings' => $warnings],
        count($inserts) . ' duties allocated' . ($warnings ? ' — ' . count($warnings) . ' warning(s) to review' : ''));
});

$router->post('/api/admin/board-duty/{exam}/duties', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    boardDutyOpen($exam);
    $d = boardBody();
    $date = (string)($d['exam_date'] ?? ''); $time = (string)($d['exam_time'] ?? ''); $roomId = (int)($d['room_id'] ?? 0);
    $session = null;
    foreach (boardDutySessions($exam) as $s) if ($s['exam_date'] === $date && $s['exam_time'] === $time) $session = $s;
    if (!$session) Response::validationError(['That date / time is not a session of this exam']);
    if (!in_array($roomId, array_column($session['rooms'], 'room_id'), true)) Response::validationError(['That room has no candidates in this session']);
    $t = boardDutyKeyValid(boardDutyTeachers($exam), (string)($d['tkey'] ?? ''));
    if (Database::fetch("SELECT id FROM board_duties WHERE exam_id = ? AND exam_date = ? AND exam_time = ? AND tkey = ?", [$exam['id'], $date, $time, $t['key']])) {
        Response::validationError(["{$t['name']} already has a duty in this session"]);
    }
    Database::insert('board_duties', ['exam_id' => $exam['id'], 'exam_date' => $date, 'exam_time' => $time, 'room_id' => $roomId, 'tkey' => $t['key'], 'fixed' => !empty($d['fixed']) ? 1 : 0]);
    Response::success(null, "{$t['name']} added");
});

$router->put('/api/admin/board-duty/{exam}/duties/{id}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    boardDutyOpen($exam);
    $duty = Database::fetch("SELECT * FROM board_duties WHERE id = ? AND exam_id = ?", [(int)$p['id'], $exam['id']]);
    if (!$duty) Response::notFound('Duty not found');
    $d = boardBody();
    if (array_key_exists('fixed', $d)) Database::query("UPDATE board_duties SET fixed = ? WHERE id = ?", [!empty($d['fixed']) ? 1 : 0, $duty['id']]);
    Response::success(null, 'Duty updated');
});

$router->delete('/api/admin/board-duty/{exam}/duties/{id}', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    boardDutyOpen($exam);
    Database::query("DELETE FROM board_duties WHERE id = ? AND exam_id = ?", [(int)$p['id'], $exam['id']]);
    Response::success(null, 'Duty removed');
});

$router->post('/api/admin/board-duty/{exam}/finalize', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    $final = !empty(boardBody()['finalized']);
    if ($final && !(int)Database::fetch("SELECT COUNT(*) n FROM board_duties WHERE exam_id = ?", [$exam['id']])['n']) {
        Response::validationError(['There are no duties to finalize. Generate or add duties first.']);
    }
    Database::query("UPDATE board_duty_config SET finalized = ?, finalized_at = ? WHERE exam_id = ?", [$final ? 1 : 0, $final ? date('Y-m-d H:i:s') : null, $exam['id']]);
    Response::success(['finalized' => $final], $final ? 'Duty schedule finalized' : 'Duty schedule re-opened for changes');
});

$router->get('/api/admin/board-duty/{exam}/letters', function ($p) {
    Auth::requireRole('admin');
    $exam = boardDutyExam((int)$p['exam']);
    if (!$exam['config']['finalized']) Response::validationError(['Finalize the board hall duty schedule first. Letters use the finalized schedule.']);
    $sessions = array_column(boardDutySessions($exam), null, 'key');
    $teachers = boardDutyTeachers($exam);
    $by = [];
    foreach (boardDutyLoad($exam['id'], $teachers) as $d) {
        $s = $sessions[$d['exam_date'] . '|' . $d['exam_time']] ?? null;
        $by[$d['tkey']][] = ['exam_date' => $d['exam_date'], 'exam_time' => $d['exam_time'], 'room' => $d['room_label'], 'subjects' => $s['subjects'] ?? [], 'classes' => []];
    }
    $out = [];
    foreach ($teachers as $t) {
        if (empty($by[$t['key']])) continue;
        $out[] = ['id' => $t['key'], 'name' => $t['name'], 'designation' => $t['designation'] ?: 'Teacher', 'subject' => $t['subject'], 'gender' => $t['gender'],
            'type' => $t['type'], 'college' => $t['college'], 'mobile' => $t['mobile'], 'duties' => $by[$t['key']]];
    }
    Response::success(['exam' => $exam, 'teachers' => $out]);
});
