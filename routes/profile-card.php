<?php

// Dashboard profile card (Session 122).
// Every user's dashboard shows: photo, name, designation, mobile, joining date, experience, retired date.
//  - The user may change only their DASHBOARD picture (stored separately in user_profile_prefs;
//    the public photo in teachers/staff/students.photo_path is never touched).
//  - Admin chooses which fields show — for everyone (default) or per user (setting "profile_card").
//  - Retired date = date of birth + retirement age (default 60, admin can change). Not for students.

if (!function_exists('profileCardEnsureSchema')) {

    // Session 125: + subject (teachers), mpo_index (Index), pds_id (PDS ID). New fields default to ON for everyone.
    define('PROFILE_CARD_FIELDS', ['photo', 'name', 'designation', 'subject', 'mobile', 'joining_date', 'experience', 'retired_date', 'mpo_index', 'pds_id']);

    function profileCardEnsureSchema(): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        Database::query(
            "CREATE TABLE IF NOT EXISTS user_profile_prefs (
                user_id INT PRIMARY KEY,
                dashboard_photo VARCHAR(255) NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                CONSTRAINT fk_profile_prefs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );
        if (function_exists('auditTrackTable')) auditTrackTable('user_profile_prefs'); // Update History covers it at once (Session 121)
    }

    /**
     * Where an item ADDED by admin can take its value from (Session 125). key => [label, applies to].
     * Columns of the person's teacher / staff / student record (or the user account), and admin's
     * custom entry fields ("custom:<label>", stored in custom_fields JSON).
     */
    function profileCardSources(): array
    {
        $src = [
            'email' => ['Email', 'all'], 'gender' => ['Gender', 'all'], 'date_of_birth' => ['Date of birth', 'all'],
            'blood_group' => ['Blood group', 'all'], 'present_address' => ['Present address', 'all'],
            'permanent_address' => ['Permanent address', 'all'],
            'whatsapp_number' => ['WhatsApp number', 'teachers & staff'], 'emergency_contact' => ['Emergency contact', 'teachers & staff'],
            'nid_number' => ['NID number', 'teachers & staff'], 'first_mpo_date' => ['First MPO date', 'teachers & staff'],
            'name_bangla' => ['Name (Bangla)', 'teachers & staff'], 'name_english' => ['Name (English)', 'teachers & staff'],
            'group' => ['Group / department', 'teachers'], 'email_official' => ['Official email (record)', 'teachers & staff'],
            'student_id' => ['Student ID', 'students'], 'registration_no' => ['Registration no.', 'students'],
            'class_roll' => ['Class roll', 'students'], 'section' => ['Section', 'students'],
            'academic_session' => ['HSC batch', 'students'], 'father_name' => ["Father's name", 'students'],
            'mother_name' => ["Mother's name", 'students'], 'parent_mobile' => ['Parent mobile', 'students'],
            'whatsapp' => ['WhatsApp (student)', 'students'], 'optional_subject' => ['Optional subject', 'students'],
        ];
        try {
            foreach (Database::fetchAll("SELECT target, label FROM custom_entry_fields WHERE is_builtin = 0 ORDER BY sort_order, id") as $c) {
                $src['custom:' . $c['label']] = [$c['label'] . ' (custom field)', (string)$c['target']];
            }
        } catch (\Throwable $e) {}
        return $src;
    }

    /** Clean list of added items: [{id, label, source}] */
    function profileCardCleanExtras($list): array
    {
        $sources = profileCardSources();
        $out = [];
        $seen = [];
        foreach ((array)$list as $x) {
            if (!is_array($x)) continue;
            $label = trim(mb_substr((string)($x['label'] ?? ''), 0, 40));
            $source = (string)($x['source'] ?? '');
            if ($label === '' || !isset($sources[$source])) continue;
            $id = preg_match('/^[a-z0-9]{1,12}$/', (string)($x['id'] ?? '')) ? (string)$x['id'] : substr(bin2hex(random_bytes(4)), 0, 8);
            if (isset($seen[$id])) continue;
            $seen[$id] = true;
            $out[] = ['id' => $id, 'label' => $label, 'source' => $source];
        }
        return $out;
    }

    /** Every visibility key: built-in fields + one "x_<id>" per added item. */
    function profileCardKeys(array $extras): array
    {
        return array_merge(PROFILE_CARD_FIELDS, array_map(fn($x) => 'x_' . $x['id'], $extras));
    }

    /** { default: {field: bool}, users: {userId: {field: bool}}, retirement_age: int, extras: [...] } */
    function profileCardSettings(): array
    {
        $row = Database::fetch("SELECT setting_value FROM site_settings WHERE setting_key = 'profile_card'");
        $s = $row ? json_decode((string)$row['setting_value'], true) : null;
        $s = is_array($s) ? $s : [];
        $extras = profileCardCleanExtras($s['extras'] ?? []);
        $all = array_fill_keys(profileCardKeys($extras), true); // anything not set yet shows by default
        $default = array_merge($all, array_intersect_key((array)($s['default'] ?? []), $all));
        $users = [];
        foreach ((array)($s['users'] ?? []) as $uid => $f) {
            if (is_array($f)) $users[(string)(int)$uid] = array_map('boolval', array_merge($default, array_intersect_key($f, $all)));
        }
        $age = (int)($s['retirement_age'] ?? 60);
        return ['default' => array_map('boolval', $default), 'users' => $users, 'retirement_age' => $age >= 40 && $age <= 80 ? $age : 60, 'extras' => $extras];
    }

    function profileCardSave(array $s, int $by): void
    {
        $json = json_encode($s, JSON_UNESCAPED_UNICODE);
        Database::query(
            "INSERT INTO site_settings (setting_key, setting_value, updated_by) VALUES ('profile_card', ?, ?)
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)",
            [$json, $by]
        );
    }

    function profileCardCleanFields($f, ?array $keys = null): array
    {
        $out = [];
        foreach ($keys ?? profileCardKeys(profileCardSettings()['extras']) as $k) $out[$k] = !empty($f[$k]);
        return $out;
    }

    /** Value of one added item for a person (record row + user account). */
    function profileCardExtraValue(string $source, array $rec, array $user): ?string
    {
        if (strpos($source, 'custom:') === 0) {
            $cf = json_decode((string)($rec['custom_fields'] ?? ''), true);
            $v = is_array($cf) ? ($cf[substr($source, 7)] ?? null) : null;
        } elseif ($source === 'email_official') {
            $v = $rec['email'] ?? null;
        } elseif ($source === 'academic_session') {
            $v = $rec['academic_session'] ?? null;
        } else {
            $v = array_key_exists($source, $rec) && $rec[$source] !== null && $rec[$source] !== '' ? $rec[$source] : ($user[$source] ?? null);
        }
        if (!is_scalar($v)) return null;
        $v = trim((string)$v);
        if ($v === '') return null;
        if (in_array($source, ['date_of_birth', 'first_mpo_date'], true)) return profileCardDate($v);
        if ($source === 'gender') return ucfirst($v);
        if ($source === 'optional_subject' && function_exists('subjectCanonicalName')) return subjectCanonicalName($v);
        return $v;
    }

    /** "12 years 3 months" between two dates (Y-m-d). */
    function profileCardSpan(string $from, string $to): ?string
    {
        try {
            $a = new DateTime($from);
            $b = new DateTime($to);
        } catch (\Throwable $e) {
            return null;
        }
        if ($b < $a) return null;
        $d = $a->diff($b);
        $parts = [];
        if ($d->y) $parts[] = $d->y . ' year' . ($d->y === 1 ? '' : 's');
        if ($d->m) $parts[] = $d->m . ' month' . ($d->m === 1 ? '' : 's');
        return $parts ? implode(' ', $parts) : 'Less than a month';
    }

    function profileCardRoleLabel(array $roles): string
    {
        $labels = ['admin' => 'Administrator', 'principal' => 'Principal', 'administration' => 'Administration',
                   'exam_controller' => 'Exam Controller', 'teacher' => 'Teacher', 'staff' => 'Staff', 'student' => 'Student'];
        foreach (array_keys($labels) as $r) if (in_array($r, $roles, true)) return $labels[$r];
        return 'User';
    }

    /** A real date (Y-m-d…, year ≥ 1900) or null — MySQL zero dates like 0000-00-00 count as unknown. */
    function profileCardDate($v): ?string
    {
        $v = trim((string)$v);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $v, $m)) return null;
        if ((int)$m[1] < 1900 || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return null;
        return "{$m[1]}-{$m[2]}-{$m[3]}";
    }

    /** The card values for one user (everything, before applying visibility). */
    function profileCardValues(array $user): array
    {
        profileCardEnsureSchema();
        $uid = (int)$user['id'];
        $roles = (array)($user['roles'] ?? []);
        $settings = profileCardSettings();
        $dob = $user['date_of_birth'] ?? null;

        $designation = null; $mobile = null; $joining = null; $publicPhoto = null; $isStudent = false; $name = $user['name'] ?? '';
        $subject = null; $mpoIndex = null; $pdsId = null;
        $t = Database::fetch("SELECT name, designation, mobile, joining_date, photo_path, subject, mpo_index, pds_id, 1 AS is_teacher FROM teachers WHERE user_id = ? LIMIT 1", [$uid])
          ?: Database::fetch("SELECT name, designation, mobile, joining_date, photo_path, subject, mpo_index, pds_id, 0 AS is_teacher FROM staff WHERE user_id = ? LIMIT 1", [$uid]);
        if ($t) {
            $designation = $t['designation'] ?: null;
            // subject only for teachers; Index (MPO) and PDS ID for teachers and staff
            if ((int)$t['is_teacher'] === 1 && trim((string)$t['subject']) !== '') {
                $subject = function_exists('subjectCanonicalName') ? subjectCanonicalName(trim((string)$t['subject'])) : trim((string)$t['subject']);
            }
            $mpoIndex = trim((string)$t['mpo_index']) !== '' ? trim((string)$t['mpo_index']) : null;
            $pdsId = trim((string)$t['pds_id']) !== '' ? trim((string)$t['pds_id']) : null;
            $mobile = $t['mobile'] ?: null;
            $joining = $t['joining_date'] ?: null;
            $publicPhoto = $t['photo_path'] ?: null;
            $name = $t['name'] ?: $name;
        } else {
            $s = Database::fetch("SELECT name, student_id, class, student_group, mobile, joining_year, photo_path, date_of_birth FROM students WHERE user_id = ? LIMIT 1", [$uid]);
            if ($s) {
                $isStudent = true;
                $designation = trim('Student ID ' . $s['student_id'] . ' · ' . $s['class'] . ($s['student_group'] ? ' · ' . $s['student_group'] : ''));
                $mobile = $s['mobile'] ?: null;
                $joining = $s['joining_year'] ? (string)$s['joining_year'] : null; // year only
                $publicPhoto = $s['photo_path'] ?: null;
                $name = $s['name'] ?: $name;
                $dob = $dob ?: ($s['date_of_birth'] ?? null);
            }
        }
        if (!$designation) $designation = profileCardRoleLabel($roles);
        $dob = profileCardDate($dob);
        if (!$isStudent) $joining = profileCardDate($joining);

        $retired = null; $experience = null;
        if (!$isStudent) {
            if ($dob) {
                try { $retired = (new DateTime($dob))->modify('+' . $settings['retirement_age'] . ' years')->format('Y-m-d'); } catch (\Throwable $e) {}
            }
            if ($joining) {
                $end = date('Y-m-d');
                if ($retired && $retired < $end) $end = $retired;
                $experience = profileCardSpan($joining, $end);
            }
        }

        $pref = Database::fetch("SELECT dashboard_photo FROM user_profile_prefs WHERE user_id = ?", [$uid]);
        $dashPhoto = $pref['dashboard_photo'] ?? null;

        // items admin added (Session 125)
        $extraValues = [];
        if ($settings['extras']) {
            $rec = Database::fetch("SELECT * FROM teachers WHERE user_id = ? LIMIT 1", [$uid])
                ?: Database::fetch("SELECT * FROM staff WHERE user_id = ? LIMIT 1", [$uid])
                ?: Database::fetch("SELECT * FROM students WHERE user_id = ? LIMIT 1", [$uid]) ?: [];
            foreach ($settings['extras'] as $x) {
                $extraValues[] = ['key' => 'x_' . $x['id'], 'label' => $x['label'], 'value' => profileCardExtraValue($x['source'], $rec, $user)];
            }
        }

        return [
            'photo' => $dashPhoto ?: $publicPhoto,
            'has_custom_photo' => (bool)$dashPhoto,
            'name' => $name,
            'designation' => $designation,
            'subject' => $subject,
            'mpo_index' => $mpoIndex,
            'pds_id' => $pdsId,
            'mobile' => $mobile,
            'joining_date' => $joining,
            'experience' => $experience,
            'retired_date' => $retired,
            'is_student' => $isStudent,
            'extras' => $extraValues,
        ];
    }

    function profileCardVisible(int $uid): array
    {
        $s = profileCardSettings();
        return $s['users'][(string)$uid] ?? $s['default'];
    }

    function profileCardDir(): string
    {
        $dir = __DIR__ . '/../uploads/profiles/dashboard';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir;
    }

    function profileCardDeleteFile(?string $rel): void
    {
        if ($rel && strpos($rel, 'uploads/profiles/dashboard/') === 0 && strpos($rel, '..') === false) {
            @unlink(__DIR__ . '/../' . $rel);
        }
    }
}

// GET /api/profile-card — the signed-in user's card
$router->get('/api/profile-card', function () {
    $user = Auth::requireAuth();
    Response::success(['fields' => profileCardVisible((int)$user['id']), 'values' => profileCardValues($user)]);
});

// POST /api/profile-card/photo (multipart "photo") — change MY dashboard picture only
$router->post('/api/profile-card/photo', function () {
    $user = Auth::requireAuth();
    profileCardEnsureSchema();
    $f = $_FILES['photo'] ?? null;
    if (!$f || $f['error'] !== UPLOAD_ERR_OK) Response::validationError(['photo' => 'Choose a picture']);
    if ($f['size'] > 5 * 1024 * 1024) Response::validationError(['photo' => 'Picture must be 5 MB or smaller']);
    $info = @getimagesize($f['tmp_name']);
    $ext = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'][$info[2] ?? 0] ?? null;
    if (!$ext) Response::validationError(['photo' => 'Use a JPG, PNG or WebP picture']);
    $name = 'u' . (int)$user['id'] . '_' . time() . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], profileCardDir() . '/' . $name)) Response::error('Could not save the picture', 500);
    $rel = 'uploads/profiles/dashboard/' . $name;
    $old = Database::fetch("SELECT dashboard_photo FROM user_profile_prefs WHERE user_id = ?", [(int)$user['id']]);
    Database::query(
        "INSERT INTO user_profile_prefs (user_id, dashboard_photo) VALUES (?, ?) ON DUPLICATE KEY UPDATE dashboard_photo = VALUES(dashboard_photo)",
        [(int)$user['id'], $rel]
    );
    profileCardDeleteFile($old['dashboard_photo'] ?? null);
    Response::success(['photo' => $rel], 'Dashboard picture updated');
});

// DELETE /api/profile-card/photo — back to the default (public) picture
$router->delete('/api/profile-card/photo', function () {
    $user = Auth::requireAuth();
    profileCardEnsureSchema();
    $old = Database::fetch("SELECT dashboard_photo FROM user_profile_prefs WHERE user_id = ?", [(int)$user['id']]);
    Database::query("UPDATE user_profile_prefs SET dashboard_photo = NULL WHERE user_id = ?", [(int)$user['id']]);
    profileCardDeleteFile($old['dashboard_photo'] ?? null);
    Response::success(null, 'Using the default picture');
});

// ---- admin ----

// GET /api/admin/profile-card — settings + every user (for the filterable list)
$router->get('/api/admin/profile-card', function () {
    Auth::requireRole('admin');
    profileCardEnsureSchema();
    $s = profileCardSettings();
    $users = Database::fetchAll(
        "SELECT u.id, u.name, u.email, u.status, GROUP_CONCAT(ur.role ORDER BY ur.role) AS roles
         FROM users u LEFT JOIN user_roles ur ON ur.user_id = u.id
         GROUP BY u.id ORDER BY u.name"
    );
    foreach ($users as &$u) {
        $u['id'] = (int)$u['id'];
        $u['roles'] = $u['roles'] ? explode(',', $u['roles']) : [];
        $u['custom'] = isset($s['users'][(string)$u['id']]);
        $u['fields'] = $s['users'][(string)$u['id']] ?? null;
    }
    unset($u);
    $sources = [];
    foreach (profileCardSources() as $k => [$label, $applies]) $sources[] = ['key' => $k, 'label' => $label, 'applies' => $applies];
    Response::success(['fields' => profileCardKeys($s['extras']), 'default' => $s['default'], 'retirement_age' => $s['retirement_age'],
        'extras' => $s['extras'], 'sources' => $sources, 'users' => $users]);
});

// PUT /api/admin/profile-card/extras { extras: [{id?, label, source}] } — items admin adds to the card (Session 125)
$router->put('/api/admin/profile-card/extras', function () {
    $admin = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $s = profileCardSettings();
    $extras = profileCardCleanExtras($data['extras'] ?? []);
    $keep = array_flip(profileCardKeys($extras));
    $s['extras'] = $extras;
    // removed items drop out of every saved switch set; new ones show by default
    $s['default'] = array_intersect_key($s['default'], $keep);
    foreach ($s['users'] as $k => $f) $s['users'][$k] = array_intersect_key($f, $keep);
    profileCardSave($s, (int)$admin['id']);
    Response::success(profileCardSettings(), 'Card items saved');
});

// PUT /api/admin/profile-card/defaults { fields?, retirement_age? } — for everyone
$router->put('/api/admin/profile-card/defaults', function () {
    $admin = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $s = profileCardSettings();
    if (isset($data['fields'])) $s['default'] = profileCardCleanFields($data['fields']);
    if (isset($data['retirement_age'])) {
        $age = (int)$data['retirement_age'];
        if ($age < 40 || $age > 80) Response::validationError(['retirement_age' => 'Retirement age must be 40–80']);
        $s['retirement_age'] = $age;
    }
    profileCardSave($s, (int)$admin['id']);
    Response::success(profileCardSettings(), 'Saved for all users');
});

// PUT /api/admin/profile-card/users { user_ids: [], fields: {...} | null } — custom for chosen users (null = use default)
$router->put('/api/admin/profile-card/users', function () {
    $admin = Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true) ?: [];
    $ids = array_values(array_filter(array_map('intval', (array)($data['user_ids'] ?? []))));
    if (!$ids) Response::validationError(['user_ids' => 'Choose at least one user']);
    $s = profileCardSettings();
    foreach ($ids as $id) {
        if (($data['fields'] ?? null) === null) unset($s['users'][(string)$id]);
        else $s['users'][(string)$id] = profileCardCleanFields($data['fields']);
    }
    profileCardSave($s, (int)$admin['id']);
    Response::success(['updated' => count($ids)], count($ids) . ' user(s) updated');
});
