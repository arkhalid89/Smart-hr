<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'exits', 'title' => 'Exit & Separation', 'singular' => 'Exit', 'icon' => 'bi-door-open', 'doc_type' => 'Service Document',
    'guard' => fn($emp) => $emp['status'] === 'On Deputation' ? 'Employee is on deputation — record the reversion first.' : null,
    'form' => function (array $emp) {
        $o = $GLOBALS['svc_old'];
        $types = array_diff(EXIT_TYPES, ['Contract Expired']);
        if ($emp['contract_to']) $types[] = 'Contract Expired';
        ?>
        <div class="col-12"><div class="alert alert-warning py-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i>Recording an exit changes the employee status and closes the current posting. Death in Service also <strong>closes the service book</strong> and flags nominees as pension claimants.</div></div>
        <div class="col-md-3"><label class="form-label">Exit Type <span class="req">*</span></label><select name="exit_type" id="exit_type" class="form-select select2" required><?= options_html(array_values($types), $o['exit_type'] ?? 'Resignation') ?></select></div>
        <div class="col-md-2"><label class="form-label">Exit Date <span class="req">*</span></label><input type="date" name="exit_date" class="form-control" value="<?= svc_old('exit_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-2"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-3 med-field"><label class="form-label">Medical Board Ref. No. <span class="req">*</span></label><input type="text" name="medical_board_ref" class="form-control" value="<?= svc_old('medical_board_ref') ?>" maxlength="80"></div>
        <div class="col-md-2 med-field"><label class="form-label">Medical Board Date</label><input type="date" name="medical_board_date" class="form-control" value="<?= svc_old('medical_board_date') ?>"></div>
        <div class="col-md-7 med-field"><label class="form-label">Board Recommendation</label><input type="text" name="medical_recommendation" class="form-control" value="<?= svc_old('medical_recommendation') ?>" maxlength="500"></div>
        <div class="col-md-3 death-field"><label class="form-label">Date of Death <span class="req">*</span></label><input type="date" name="date_of_death" class="form-control" value="<?= svc_old('date_of_death') ?>"></div>
        <div class="col-md-9 death-field"><label class="form-label">Cause of Death (if known)</label><input type="text" name="cause_of_death" class="form-control" value="<?= svc_old('cause_of_death') ?>" maxlength="255"></div>
        <div class="col-12"><label class="form-label">Remarks</label><textarea name="remarks" class="form-control" rows="2" maxlength="500"><?= svc_old('remarks') ?></textarea></div>
        <script>document.addEventListener("DOMContentLoaded", function(){ function t(){ const v = $('#exit_type').val(); $('.med-field').toggle(v === 'Medical Invalidation'); $('.death-field').toggle(v === 'Death in Service'); } $('#exit_type').on('change', t); t(); });</script>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_exit($emp, ['exit_type' => post('exit_type'), 'exit_date' => post_date('exit_date'), 'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'remarks' => post('remarks'),
        'medical_board_ref' => post('medical_board_ref'), 'medical_board_date' => post_date('medical_board_date'), 'medical_recommendation' => post('medical_recommendation'),
        'date_of_death' => post_date('date_of_death') ?: post_date('exit_date'), 'cause_of_death' => post('cause_of_death')]),
    'list' => [
        'date_col' => 'r.exit_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, e.status AS emp_status, d.name AS department, u.full_name AS by_user FROM exits r JOIN employees e ON e.id = r.employee_id
                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.exit_date DESC, r.id DESC',
        'columns' => [
            'Exit Date' => fn($r) => fmt_date($r['exit_date']),
            'Type' => fn($r) => '<span class="badge text-bg-' . (in_array($r['exit_type'], ['Termination', 'Dismissal', 'Death in Service']) ? 'danger' : 'dark') . '">' . e($r['exit_type']) . '</span>',
            'Order' => fn($r) => e($r['order_no'] ?: '-'),
            'Details' => fn($r) => '<span class="small">' . e(trim(($r['medical_board_ref'] ? 'Medical Board ' . $r['medical_board_ref'] . ' · ' : '') . ($r['date_of_death'] ? 'Died ' . fmt_date($r['date_of_death']) . ' · ' : '') . ($r['remarks'] ?: ''))) ?: '-' . '</span>',
            'Current Status' => fn($r) => status_badge($r['emp_status']) . ($r['is_rejoined'] ? ' <span class="badge text-bg-success">Rejoined</span>' : ''),
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Exit Type', 'Exit Date', 'Order No', 'Order Date', 'Medical Board Ref', 'Medical Board Date', 'Date of Death', 'Cause', 'Rejoined', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['exit_type'], $r['exit_date'], $r['order_no'], $r['order_date'], $r['medical_board_ref'], $r['medical_board_date'], $r['date_of_death'], $r['cause_of_death'], $r['is_rejoined'] ? 'Yes' : 'No', $r['remarks']]],
]);
