<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

/* The same page handles Deputation (when serving) and Reversion (when On Deputation). */
$isReversion = false;
if (!empty($_REQUEST['employee_id'])) {
    $e = employee_get((int)$_REQUEST['employee_id']);
    $isReversion = $e && $e['status'] === 'On Deputation';
}

service_page([
    'key' => 'deputations', 'title' => 'Deputations & Reversions', 'singular' => $isReversion ? 'Reversion' : 'Deputation', 'icon' => 'bi-box-arrow-up-right', 'doc_type' => 'Service Document',
    'form' => function (array $emp) use ($isReversion) {
        $o = $GLOBALS['svc_old'];
        if ($isReversion) {
            $dep = db_row('SELECT d.*, o.name AS org FROM deputations d JOIN deputation_orgs o ON o.id = d.deputation_org_id WHERE d.employee_id = ? AND d.status = "Active" AND d.is_voided = 0 ORDER BY d.id DESC LIMIT 1', [(int)$emp['id']]);
            echo '<div class="col-12"><div class="alert alert-primary py-2 mb-0 small"><i class="bi bi-box-arrow-up-right me-1"></i><strong>On deputation to ' . e($dep['org'] ?? '-') . '</strong> since ' . fmt_date($dep['start_date'] ?? null) . ($dep && $dep['expected_reversion_date'] ? ' · expected reversion ' . fmt_date($dep['expected_reversion_date']) : '') . '. Record the reversion below to bring the employee back to the parent department.</div></div>';
            ?>
            <input type="hidden" name="mode" value="reversion">
            <div class="col-md-3"><label class="form-label">Reversion Date <span class="req">*</span></label><input type="date" name="actual_reversion_date" class="form-control" value="<?= svc_old('actual_reversion_date', today()) ?>" required></div>
            <div class="col-md-3"><label class="form-label">Reversion Order No.</label><input type="text" name="reversion_order_no" class="form-control" value="<?= svc_old('reversion_order_no') ?>" maxlength="80"></div>
            <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="reversion_order_date" class="form-control" value="<?= svc_old('reversion_order_date') ?>"></div>
            <div class="col-12"><div class="form-section-title">Revert to</div>
                <?= geo_picker(['name' => 'department_id', 'value' => $o['department_id'] ?? $emp['department_id'], 'required' => true, 'id' => 'revgeo']) ?></div>
            <div class="col-md-4"><label class="form-label">Office / site</label><select name="location_id" class="form-select" data-geo-locations><option value="">— Department's main office —</option><?= posting_location_options_html(isset($o['location_id']) ? (int)$o['location_id'] : null) ?></select></div>
            <div class="col-md-8"><label class="form-label">Remarks</label><input type="text" name="reversion_remarks" class="form-control" value="<?= svc_old('reversion_remarks') ?>" maxlength="500"></div>
            <?php
            return;
        }
        if ($emp['status'] === 'Suspended') echo '<div class="col-12"><div class="alert alert-warning py-2 mb-0 small">Employee is suspended: deputation is blocked until reinstatement.</div></div>';
        ?>
        <input type="hidden" name="mode" value="deputation">
        <div class="col-md-4"><label class="form-label">Deputation To (Organization) <span class="req">*</span></label><select name="deputation_org_id" class="form-select select2" data-placeholder="Select organization" required><option value=""></option><?= options_html(master_options('deputation_orgs'), $o['deputation_org_id'] ?? '') ?></select></div>
        <div class="col-md-2"><label class="form-label">Start Date <span class="req">*</span></label><input type="date" name="start_date" class="form-control" value="<?= svc_old('start_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Expected Reversion Date</label><input type="date" name="expected_reversion_date" class="form-control" value="<?= svc_old('expected_reversion_date') ?>"><div class="form-text">Alerts are raised <?= (int)setting('deputation_alert_days', 30) ?> days before.</div></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-9"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <?php
    },
    'save' => function (array $emp, array $p) {
        if (($p['mode'] ?? '') === 'reversion') {
            return svc_reversion($emp, ['actual_reversion_date' => post_date('actual_reversion_date'), 'reversion_order_no' => post('reversion_order_no'), 'reversion_order_date' => post_date('reversion_order_date'),
                'department_id' => post_int('department_id'), 'location_id' => post_int('location_id'), 'reversion_remarks' => post('reversion_remarks')]);
        }
        return svc_deputation($emp, ['deputation_org_id' => post_int('deputation_org_id'), 'start_date' => post_date('start_date'), 'expected_reversion_date' => post_date('expected_reversion_date'),
            'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'remarks' => post('remarks')]);
    },
    'list' => [
        'date_col' => 'r.start_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, o.name AS org, u.full_name AS by_user FROM deputations r JOIN employees e ON e.id = r.employee_id
                  JOIN deputation_orgs o ON o.id = r.deputation_org_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.start_date DESC, r.id DESC',
        'columns' => [
            'Organization' => fn($r) => '<strong>' . e($r['org']) . '</strong>',
            'From' => fn($r) => fmt_date($r['start_date']),
            'Expected Reversion' => fn($r) => fmt_date($r['expected_reversion_date']) . ($r['status'] === 'Active' && $r['expected_reversion_date'] && $r['expected_reversion_date'] < today() ? ' <span class="badge text-bg-danger">Overdue</span>' : ''),
            'Reverted' => fn($r) => $r['actual_reversion_date'] ? fmt_date($r['actual_reversion_date']) . ($r['reversion_order_no'] ? '<br><span class="small text-muted">Order ' . e($r['reversion_order_no']) . '</span>' : '') : '-',
            'Order' => fn($r) => e($r['order_no'] ?: '-'),
            'Status' => fn($r) => $r['status'] === 'Active' ? '<span class="badge text-bg-primary">On Deputation</span>' : '<span class="badge text-bg-success">Reverted</span>',
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Organization', 'Start', 'Expected Reversion', 'Actual Reversion', 'Order No', 'Reversion Order', 'Status', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['org'], $r['start_date'], $r['expected_reversion_date'], $r['actual_reversion_date'], $r['order_no'], $r['reversion_order_no'], $r['status'], $r['remarks']]],
]);
