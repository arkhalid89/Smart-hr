<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'rejoining', 'title' => 'Rejoining', 'singular' => 'Rejoining', 'icon' => 'bi-arrow-repeat', 'doc_type' => 'Joining Report', 'skip_transact_guard' => true,
    'guard' => function ($emp) { [$ok, $why] = svc_rejoin_allowed($emp); return $ok ? null : ($why ?: 'Rejoining is not allowed.'); },
    'form' => function (array $emp) {
        $o = $GLOBALS['svc_old'];
        [$ok, $why, $override] = svc_rejoin_allowed($emp);
        $exit = db_row('SELECT * FROM exits WHERE employee_id = ? AND is_voided = 0 ORDER BY exit_date DESC, id DESC LIMIT 1', [(int)$emp['id']]);
        $retired = in_array($emp['status'], ['Retired', 'Medical Retired'], true);
        echo '<div class="col-12"><div class="alert alert-' . ($override ? 'danger' : 'info') . ' py-2 mb-0 small"><i class="bi bi-info-circle me-1"></i>'
           . 'Status: <strong>' . e($emp['status']) . '</strong>' . ($exit ? ' · ' . e($exit['exit_type']) . ' on ' . fmt_date($exit['exit_date']) : '')
           . ($why ? ' — ' . e($why) : '') . ($override ? ' <strong>You are overriding as Super Admin.</strong>' : '')
           . '<br>The existing employee ID <code>' . e($emp['employee_code']) . '</code> is reused and the full history is preserved.</div></div>';
        $types = $retired ? ['Retirement Contract'] : array_diff(REJOIN_TYPES, ['Retirement Contract']);
        ?>
        <div class="col-md-3"><label class="form-label">Rejoin Type <span class="req">*</span></label><select name="rejoin_type" id="rejoin_type" class="form-select select2" required><?= options_html(array_values($types), $o['rejoin_type'] ?? ($emp['status'] === 'Contract Expired' ? 'Renewal' : ($emp['status'] === 'Resigned' ? 'Resignation Rejoin' : ($retired ? 'Retirement Contract' : 'Appeal Reinstatement')))) ?></select>
            <div class="form-text">"Fresh" restarts probation.</div></div>
        <div class="col-md-2"><label class="form-label">Rejoining Date <span class="req">*</span></label><input type="date" name="rejoining_date" class="form-control" value="<?= svc_old('rejoining_date', today()) ?>" required></div>
        <div class="col-md-2"><label class="form-label">New Contract From<?= $retired ? ' <span class="req">*</span>' : '' ?></label><input type="date" name="new_contract_from" class="form-control" value="<?= svc_old('new_contract_from') ?>"></div>
        <div class="col-md-2"><label class="form-label">New Contract To<?= $retired ? ' <span class="req">*</span>' : '' ?></label><input type="date" name="new_contract_to" class="form-control" value="<?= svc_old('new_contract_to') ?>"></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-12"><div class="form-section-title">Rejoins at</div>
            <?= geo_picker(['name' => 'department_id', 'value' => $o['department_id'] ?? $emp['department_id'], 'required' => true, 'id' => 'rjgeo']) ?></div>
        <div class="col-md-3"><label class="form-label">Office / site</label><select name="location_id" class="form-select" data-geo-locations><option value="">— Department's main office —</option><?= posting_location_options_html(isset($o['location_id']) ? (int)$o['location_id'] : null) ?></select></div>
        <div class="col-md-3"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_rejoin($emp, ['rejoin_type' => post('rejoin_type'), 'rejoining_date' => post_date('rejoining_date'), 'new_contract_from' => post_date('new_contract_from'), 'new_contract_to' => post_date('new_contract_to'),
        'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'department_id' => post_int('department_id'), 'location_id' => post_int('location_id'), 'remarks' => post('remarks')]),
    'list' => [
        'date_col' => 'r.rejoining_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, e.status AS emp_status, d.name AS department, x.exit_type, x.exit_date, u.full_name AS by_user FROM rejoinings r JOIN employees e ON e.id = r.employee_id
                  LEFT JOIN exits x ON x.id = r.exit_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.rejoining_date DESC, r.id DESC',
        'columns' => [
            'Rejoined' => fn($r) => fmt_date($r['rejoining_date']),
            'Type' => fn($r) => e($r['rejoin_type']) . ($r['is_override'] ? ' <span class="badge text-bg-danger">Override</span>' : ''),
            'Previous Exit' => fn($r) => e($r['exit_type'] ?: $r['previous_status']) . ($r['exit_date'] ? '<br><span class="small text-muted">' . fmt_date($r['exit_date']) . '</span>' : ''),
            'New Contract' => fn($r) => $r['new_contract_to'] ? fmt_date($r['new_contract_from']) . ' → ' . fmt_date($r['new_contract_to']) : '-',
            'Order' => fn($r) => e($r['order_no'] ?: '-'),
            'Status Now' => fn($r) => status_badge($r['emp_status']),
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Rejoining Date', 'Type', 'Previous Status', 'Previous Exit', 'Exit Date', 'Contract From', 'Contract To', 'Order No', 'Override', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['rejoining_date'], $r['rejoin_type'], $r['previous_status'], $r['exit_type'], $r['exit_date'], $r['new_contract_from'], $r['new_contract_to'], $r['order_no'], $r['is_override'] ? 'Yes' : 'No', $r['remarks']]],
]);
