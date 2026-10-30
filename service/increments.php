<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'increments', 'title' => 'Increments', 'singular' => 'Increment', 'icon' => 'bi-cash-coin', 'doc_type' => 'Service Document',
    'guard' => fn($emp) => $emp['status'] === 'Suspended' ? 'Employee is suspended: increments are auto-blocked until reinstatement.' : null,
    'form' => function (array $emp) {
        $scale = $emp['pay_scale_id'] ? db_find('pay_scales', (int)$emp['pay_scale_id']) : null;
        $step  = $scale ? (float)$scale['increment_amount'] : 0;
        $suggest = $emp['basic_salary'] !== null ? (float)$emp['basic_salary'] + $step : '';
        $last = db_value('SELECT MAX(effective_date) FROM increments WHERE employee_id = ? AND is_voided = 0', [(int)$emp['id']]);
        ?>
        <div class="col-md-3"><label class="form-label">Previous Salary</label><input type="text" class="form-control" value="<?= fmt_money($emp['basic_salary']) ?>" readonly>
            <div class="form-text"><?= $scale ? e($scale['name']) . ' · increment ' . fmt_money($scale['increment_amount']) . ' · max ' . fmt_money($scale['max_salary']) : 'No pay scale set' ?></div></div>
        <div class="col-md-3"><label class="form-label">New Salary <span class="req">*</span></label><input type="number" name="new_salary" class="form-control" min="1" step="1" value="<?= svc_old('new_salary', $suggest !== '' ? (string)(int)$suggest : '') ?>" required></div>
        <div class="col-md-3"><label class="form-label">Effective Date <span class="req">*</span></label><input type="date" name="effective_date" class="form-control" value="<?= svc_old('effective_date', date('Y') . '-12-01') ?>" required>
            <div class="form-text">Annual increment: 1 December.<?= $last ? ' Last: ' . fmt_date($last) : '' ?></div></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-9"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_increment($emp, ['new_salary' => post_decimal('new_salary'), 'effective_date' => post_date('effective_date'), 'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'remarks' => post('remarks')]),
    'list' => [
        'date_col' => 'r.effective_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, u.full_name AS by_user FROM increments r JOIN employees e ON e.id = r.employee_id
                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.effective_date DESC, r.id DESC',
        'columns' => [
            'Effective' => fn($r) => fmt_date($r['effective_date']),
            'Previous' => fn($r) => fmt_money($r['previous_salary']),
            'New' => fn($r) => '<strong>' . fmt_money($r['new_salary']) . '</strong> <span class="small text-success">+' . fmt_money((float)$r['new_salary'] - (float)$r['previous_salary']) . '</span>',
            'Order' => fn($r) => e($r['order_no'] ?: '-'),
            'Kind' => fn($r) => $r['is_back_benefit'] ? '<span class="badge text-bg-info">Back benefit</span>' : '<span class="badge text-bg-light border">Annual</span>',
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Effective Date', 'Previous Salary', 'New Salary', 'Order No', 'Order Date', 'Back Benefit', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['effective_date'], $r['previous_salary'], $r['new_salary'], $r['order_no'], $r['order_date'], $r['is_back_benefit'] ? 'Yes' : 'No', $r['remarks']]],
]);
