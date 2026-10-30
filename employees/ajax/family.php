<?php
/**
 * Family / nominee AJAX CRUD.
 *   GET  ?action=list&employee_id=   -> HTML table
 *   GET  ?action=form&id=            -> HTML form fields
 *   POST action=save|delete
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_login();

$action = (string)($_REQUEST['action'] ?? '');
if ($action !== 'list') require_editor();

if ($action === 'list') {
    $empId = get_int('employee_id', 0);
    $emp = employee_load_or_fail($empId, true);
    $rows = db_all('SELECT * FROM employee_family WHERE employee_id = ? AND is_deleted = 0 ORDER BY is_nominee DESC, id', [$empId]);
    header('Content-Type: text/html; charset=utf-8');
    if (!$rows) { echo '<div class="empty-state py-4"><i class="bi bi-people"></i>No family / nominee records yet.</div>'; exit; }
    if (get('view') === 'cards') {
        $icons = ['Spouse' => 'bi-heart', 'Son' => 'bi-person', 'Daughter' => 'bi-person-dress', 'Father' => 'bi-person-standing', 'Mother' => 'bi-person-standing-dress'];
        echo '<div class="family-grid">';
        foreach ($rows as $r) {
            echo '<div class="family-card">'
               . (can_edit() ? '<div class="acts"><button class="btn btn-ghost btn-sm" data-fam-act="edit" data-id="' . $r['id'] . '" title="Edit"><i class="bi bi-pencil"></i></button><button class="btn btn-ghost btn-sm text-danger" data-fam-act="delete" data-id="' . $r['id'] . '" title="Remove"><i class="bi bi-trash"></i></button></div>' : '')
               . '<div class="rel"><i class="bi ' . ($icons[$r['relation']] ?? 'bi-person') . ' me-1"></i>' . e($r['relation']) . '</div>'
               . '<div class="nm">' . e($r['name']) . '</div>'
               . '<div class="meta">' . ($r['cnic'] ? e($r['cnic']) . ' · ' : '') . ($r['dob'] ? fmt_date($r['dob']) . ' (' . calc_age($r['dob']) . ')' : '') . ($r['contact'] ? '<br><i class="bi bi-telephone me-1"></i>' . e($r['contact']) : '') . '</div>'
               . '<div class="mt-2">' . ($r['is_nominee'] ? '<span class="badge text-bg-primary">Nominee' . ($r['nominee_share'] !== null ? ' ' . (float)$r['nominee_share'] . '%' : '') . '</span> ' : '')
               . ($r['is_emergency_contact'] ? '<span class="badge text-bg-warning">Emergency</span> ' : '') . ($r['is_pension_claimant'] ? '<span class="badge text-bg-danger">Pension Claimant</span>' : '') . '</div>'
               . '</div>';
        }
        echo '</div>';
        exit;
    }
    ?>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>Name</th><th>Relation</th><th>CNIC</th><th>DOB / Age</th><th>Contact</th><th>Nominee</th><th>Flags</th><th class="text-end">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
            <tr>
                <td class="fw-semibold"><?= e($r['name']) ?></td>
                <td><?= e($r['relation']) ?></td>
                <td><?= e($r['cnic'] ?: '-') ?></td>
                <td><?= $r['dob'] ? fmt_date($r['dob']) . ' <span class="text-muted small">(' . calc_age($r['dob']) . ')</span>' : '-' ?></td>
                <td><?= e($r['contact'] ?: '-') ?></td>
                <td><?= $r['is_nominee'] ? '<span class="badge text-bg-primary">Nominee' . ($r['nominee_share'] !== null ? ' ' . (float)$r['nominee_share'] . '%' : '') . '</span>' : '-' ?></td>
                <td>
                    <?php if ($r['is_emergency_contact']): ?><span class="badge text-bg-warning">Emergency</span><?php endif; ?>
                    <?php if ($r['is_pension_claimant']): ?><span class="badge text-bg-danger">Pension Claimant</span><?php endif; ?>
                </td>
                <td class="text-end table-actions">
                    <button class="btn btn-ghost btn-sm" data-fam-act="edit" data-id="<?= $r['id'] ?>"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-ghost btn-sm text-danger" data-fam-act="delete" data-id="<?= $r['id'] ?>"><i class="bi bi-trash"></i></button>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php
    exit;
}

if ($action === 'form') {
    $id  = get_int('id', 0);
    $row = $id ? db_find('employee_family', $id) : [];
    if ($id && !$row) json_error('Record not found.', 404);
    if ($row) employee_load_or_fail((int)$row['employee_id'], true, true);
    $g = fn($k, $d = '') => e($row[$k] ?? $d);
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="col-md-6"><label class="form-label">Name <span class="req">*</span></label><input type="text" name="name" class="form-control" value="<?= $g('name') ?>" required maxlength="120"></div>
    <div class="col-md-6"><label class="form-label">Relation <span class="req">*</span></label><select name="relation" class="form-select" required><?= options_html(FAMILY_RELATIONS, $row['relation'] ?? 'Spouse') ?></select></div>
    <div class="col-md-4"><label class="form-label">CNIC / B-Form</label><input type="text" name="cnic" class="form-control" data-cnic value="<?= $g('cnic') ?>" maxlength="15" placeholder="35202-1234567-1"></div>
    <div class="col-md-4"><label class="form-label">Date of Birth</label><input type="date" name="dob" class="form-control" value="<?= $g('dob') ?>" max="<?= today() ?>"></div>
    <div class="col-md-4"><label class="form-label">Contact</label><input type="text" name="contact" class="form-control" value="<?= $g('contact') ?>" maxlength="30"></div>
    <div class="col-12"><label class="form-label">Address</label><input type="text" name="address" class="form-control" value="<?= $g('address') ?>" maxlength="255"></div>
    <div class="col-md-4">
        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_nominee" id="fam_nominee" value="1" <?= !empty($row['is_nominee']) ? 'checked' : '' ?>><label class="form-check-label" for="fam_nominee">Nominee (pension / gratuity)</label></div>
    </div>
    <div class="col-md-4"><label class="form-label">Nominee Share %</label><input type="number" name="nominee_share" class="form-control" min="0" max="100" step="0.01" value="<?= $g('nominee_share') ?>"></div>
    <div class="col-md-4">
        <div class="form-check form-switch mt-2"><input class="form-check-input" type="checkbox" name="is_emergency_contact" id="fam_emerg" value="1" <?= !empty($row['is_emergency_contact']) ? 'checked' : '' ?>><label class="form-check-label" for="fam_emerg">Emergency contact</label></div>
        <?php if (!empty($row['is_pension_claimant'])): ?><div class="form-text text-danger">Flagged as pension claimant.</div><?php endif; ?>
    </div>
    <div class="col-12"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= $g('remarks') ?>" maxlength="255"></div>
    <?php
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request.', 405);
csrf_verify();

if ($action === 'save') {
    $id    = post_int('id', 0);
    $empId = post_int('employee_id', 0);
    $emp   = employee_load_or_fail($empId, true, true);
    $d = [
        'employee_id' => $empId,
        'relation'    => in_array(post('relation'), FAMILY_RELATIONS, true) ? post('relation') : 'Other',
        'name'        => (string)post('name', ''),
        'cnic'        => nullable(post('cnic')) ? normalize_cnic((string)post('cnic')) : null,
        'dob'         => post_date('dob'),
        'contact'     => nullable(post('contact')),
        'address'     => nullable(post('address')),
        'is_nominee'  => post('is_nominee') ? 1 : 0,
        'nominee_share' => post_decimal('nominee_share'),
        'is_emergency_contact' => post('is_emergency_contact') ? 1 : 0,
        'remarks'     => nullable(post('remarks')),
    ];
    if ($d['name'] === '') json_error('Name is required.');
    if ($d['cnic'] && !valid_cnic($d['cnic'])) json_error('CNIC format is invalid.');
    if ($d['dob'] && $d['dob'] > today()) json_error('Date of birth cannot be in the future.');
    if (!$d['is_nominee']) $d['nominee_share'] = null;
    if ($d['nominee_share'] !== null && ($d['nominee_share'] < 0 || $d['nominee_share'] > 100)) json_error('Nominee share must be between 0 and 100.');
    // total nominee share cannot exceed 100
    if ($d['is_nominee'] && $d['nominee_share'] !== null) {
        $other = (float)db_value('SELECT COALESCE(SUM(nominee_share),0) FROM employee_family WHERE employee_id = ? AND is_nominee = 1 AND is_deleted = 0 AND id <> ?', [$empId, $id], 0);
        if ($other + $d['nominee_share'] > 100.0001) json_error('Total nominee share would exceed 100% (other nominees: ' . $other . '%).');
    }
    if ($id) {
        $old = db_find('employee_family', $id);
        if (!$old || (int)$old['employee_id'] !== $empId) json_error('Record not found.', 404);
        db_update('employee_family', $d, 'id = ?', [$id]);
        log_activity('UPDATE', 'employee_family', $id, $old, array_merge($old, $d), 'Family member updated: ' . $d['name'] . ' (' . $emp['name'] . ')', $empId);
        json_ok('Family record updated.');
    }
    $d['created_by'] = user_id();
    $newId = db_insert('employee_family', $d);
    log_activity('CREATE', 'employee_family', $newId, null, $d, 'Family member added: ' . $d['name'] . ' (' . $emp['name'] . ')', $empId);
    json_ok('Family record added.');
}

if ($action === 'delete') {
    $id  = post_int('id', 0);
    $row = $id ? db_find('employee_family', $id) : null;
    if (!$row) json_error('Record not found.', 404);
    $emp = employee_load_or_fail((int)$row['employee_id'], true, true);
    db_update('employee_family', ['is_deleted' => 1], 'id = ?', [$id]);
    log_activity('DELETE', 'employee_family', $id, $row, null, 'Family member removed: ' . $row['name'] . ' (' . $emp['name'] . ')', (int)$row['employee_id']);
    json_ok('Family record removed.');
}
json_error('Unknown action.');
