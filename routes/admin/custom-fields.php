<?php
// Admin: custom entry fields for Add Student / Teacher / Staff forms
// Field types: text, number, date, dropdown, yes_no
// Built-in form fields are seeded rows (is_builtin=1) with field_key mapping
// to the form state/column — they are fully reorderable; non-logic fields
// are CRUD-able. Labels must be unique within a target (values are keyed by label).
$adminMw = function () { Auth::requireRole('admin'); };
$validTargets = ['student', 'teacher', 'staff'];
$validTypes = ['text', 'number', 'date', 'dropdown', 'yes_no'];

// GET /api/admin/custom-fields?target=student|teacher|staff
$router->get('/api/admin/custom-fields', function () use ($validTargets) {
    Auth::requireRole('admin');
    $target = $_GET['target'] ?? null;
    if ($target && !in_array($target, $validTargets)) Response::error('Invalid target', 400);
    if ($target) {
        $fields = Database::fetchAll(
            "SELECT id, target, field_key, is_builtin, label, field_type, options, required, sort_order
             FROM custom_entry_fields WHERE target = ? ORDER BY sort_order, id",
            [$target]
        );
    } else {
        $fields = Database::fetchAll(
            "SELECT id, target, field_key, is_builtin, label, field_type, options, required, sort_order
             FROM custom_entry_fields ORDER BY target, sort_order, id"
        );
    }
    foreach ($fields as &$f) {
        $f['required'] = (bool)$f['required'];
        $f['is_builtin'] = (bool)$f['is_builtin'];
        $f['field_key'] = $f['field_key'] ?: null;
        $f['options'] = $f['options'] ? array_map('trim', explode(',', $f['options'])) : [];
    }
    Response::success($fields);
}, [$adminMw]);

// POST /api/admin/custom-fields
$router->post('/api/admin/custom-fields', function () use ($validTargets, $validTypes) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $target = $data['target'] ?? '';
    $label = trim($data['label'] ?? '');
    $fieldType = $data['field_type'] ?? 'text';
    if (!in_array($target, $validTargets)) Response::validationError(['target' => 'target must be student, teacher or staff']);
    if ($label === '') Response::validationError(['label' => 'Label is required']);
    if (!in_array($fieldType, $validTypes)) Response::validationError(['field_type' => 'Invalid field type']);
    // duplicate label check (within the same target form)
    $dup = Database::fetch("SELECT id FROM custom_entry_fields WHERE target = ? AND label = ?", [$target, $label]);
    if ($dup) Response::validationError(['label' => "A field named \"$label\" already exists on this form. Labels must be unique."]);
    $options = null;
    if ($fieldType === 'dropdown') {
        $opts = array_values(array_filter(array_map('trim', (array)($data['options'] ?? [])), function ($o) { return $o !== ''; }));
        if (empty($opts)) Response::validationError(['options' => 'Dropdown requires at least one option']);
        $options = implode(',', $opts);
    }
    $maxSort = Database::fetch("SELECT COALESCE(MAX(sort_order), 0) + 1 as next FROM custom_entry_fields WHERE target = ?", [$target]);
    $id = Database::insert('custom_entry_fields', [
        'target' => $target,
        'field_key' => null,
        'is_builtin' => 0,
        'label' => $label,
        'field_type' => $fieldType,
        'options' => $options,
        'required' => !empty($data['required']) ? 1 : 0,
        'sort_order' => $maxSort['next'],
    ]);
    Response::created(['id' => $id], 'Field added');
}, [$adminMw]);

// PUT /api/admin/custom-fields/{id} — works for built-in and custom fields
$router->put('/api/admin/custom-fields/{id}', function (array $params) use ($validTypes) {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $existing = Database::fetch("SELECT * FROM custom_entry_fields WHERE id = ?", [$params['id']]);
    if (!$existing) Response::notFound('Field not found');
    $updateData = [];
    if (isset($data['label'])) {
        $label = trim($data['label']);
        if ($label === '') Response::validationError(['label' => 'Label is required']);
        // duplicate label check (excluding this row itself)
        $dup = Database::fetch(
            "SELECT id FROM custom_entry_fields WHERE target = ? AND label = ? AND id != ?",
            [$existing['target'], $label, $params['id']]
        );
        if ($dup) Response::validationError(['label' => "A field named \"$label\" already exists on this form. Labels must be unique."]);
        $updateData['label'] = $label;
    }
    if (isset($data['field_type'])) {
        if (!in_array($data['field_type'], $validTypes)) Response::validationError(['field_type' => 'Invalid field type']);
        $updateData['field_type'] = $data['field_type'];
    }
    $fieldType = $updateData['field_type'] ?? $existing['field_type'];
    if ($fieldType === 'dropdown') {
        if (isset($data['options'])) {
            $opts = array_values(array_filter(array_map('trim', (array)$data['options']), function ($o) { return $o !== ''; }));
            if (empty($opts)) Response::validationError(['options' => 'Dropdown requires at least one option']);
            $updateData['options'] = implode(',', $opts);
        }
    } elseif ($existing['field_type'] === 'dropdown' && isset($updateData['field_type'])) {
        // switching away from dropdown clears the options
        $updateData['options'] = null;
    }
    if (isset($data['required'])) $updateData['required'] = !empty($data['required']) ? 1 : 0;
    if (!empty($updateData)) {
        Database::update('custom_entry_fields', $updateData, 'id = ?', ['id' => $params['id']]);
    }
    Response::success(null, 'Field updated');
}, [$adminMw]);

// DELETE /api/admin/custom-fields/{id} — deletes non-logic fields
$router->delete('/api/admin/custom-fields/{id}', function (array $params) {
    Auth::requireRole('admin');
    $existing = Database::fetch("SELECT * FROM custom_entry_fields WHERE id = ?", [$params['id']]);
    if (!$existing) Response::notFound('Field not found');
    Database::delete('custom_entry_fields', 'id = ?', [$params['id']]);
    Response::success(null, 'Field deleted');
}, [$adminMw]);

// POST /api/admin/custom-fields/reorder — batch reorder within a target
$router->post('/api/admin/custom-fields/reorder', function () {
    Auth::requireRole('admin');
    $data = json_decode(file_get_contents('php://input'), true);
    $ids = $data['ids'] ?? [];
    if (!is_array($ids) || empty($ids)) Response::validationError(['ids' => 'ids array required']);
    foreach ($ids as $i => $id) {
        Database::update('custom_entry_fields', ['sort_order' => $i + 1], 'id = ?', ['id' => (int)$id]);
    }
    Response::success(null, 'Reordered');
}, [$adminMw]);
