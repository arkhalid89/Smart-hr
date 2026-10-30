<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'punishments', 'title' => 'Punishments & Suspensions', 'singular' => 'Punishment', 'icon' => 'bi-exclamation-octagon', 'doc_type' => 'Suspension Order',
    'form' => function (array $emp) {
        $o = $GLOBALS['svc_old'];
        if ($emp['status'] === 'Suspended') echo '<div class="col-12"><div class="alert alert-danger py-2 mb-0 small"><i class="bi bi-slash-circle me-1"></i>Employee is currently <strong>suspended</strong>. Record a reinstatement to end the suspension.</div></div>';
        ?>
        <div class="col-md-4"><label class="form-label">Punishment Type <span class="req">*</span></label><select name="punishment_type_id" id="pun_type" class="form-select select2" data-placeholder="Select type" required><option value=""></option>
            <?php foreach (master_options('punishment_types') as $t): ?><option value="<?= $t['id'] ?>" data-susp="<?= $t['is_suspension'] ?>" data-exit="<?= ($t['is_removal'] || $t['is_dismissal']) ? 1 : 0 ?>" <?= (string)($o['punishment_type_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?> (<?= e($t['severity']) ?>)</option><?php endforeach; ?></select>
            <div class="form-text text-danger" id="punHint" hidden></div></div>
        <div class="col-md-2"><label class="form-label">Order Date <span class="req">*</span></label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order by Authority</label><input type="text" name="order_by_authority" class="form-control" value="<?= svc_old('order_by_authority') ?>" maxlength="150"></div>
        <div class="col-md-3 susp-field"><label class="form-label">Suspension Start <span class="req">*</span></label><input type="date" name="suspension_start" class="form-control" value="<?= svc_old('suspension_start') ?>"></div>
        <div class="col-md-3 susp-field"><label class="form-label">Suspension End</label><input type="date" name="suspension_end" class="form-control" value="<?= svc_old('suspension_end') ?>"><div class="form-text">Blank = indefinite (until reinstatement)</div></div>
        <div class="col-md-3 susp-field"><label class="form-label">Pay Status during Suspension</label><select name="pay_status" class="form-select"><?= options_html(['Subsistence Allowance', 'Full Pay', 'No Pay'], $o['pay_status'] ?? 'Subsistence Allowance') ?></select></div>
        <div class="col-12"><label class="form-label">Description / Remarks</label><textarea name="description" class="form-control" rows="2" maxlength="500"><?= svc_old('description') ?></textarea></div>
        <script>document.addEventListener("DOMContentLoaded", function(){ function t(){ const o = $('#pun_type').find(':selected'); const s = o.data('susp') == 1, x = o.data('exit') == 1;
            $('.susp-field').toggle(s); $('#punHint').prop('hidden', !(s || x)).text(s ? 'Status will change to Suspended. Transfers, promotions and increments will be blocked until reinstatement.' : (x ? 'This punishment ends the employee\'s service: an exit record (Termination / Dismissal) will be created automatically.' : '')); }
            $('#pun_type').on('change', t); t(); });</script>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_punishment($emp, ['punishment_type_id' => post_int('punishment_type_id'), 'order_date' => post_date('order_date'), 'order_no' => post('order_no'), 'order_by_authority' => post('order_by_authority'),
        'suspension_start' => post_date('suspension_start'), 'suspension_end' => post_date('suspension_end'), 'pay_status' => post('pay_status'), 'description' => post('description')]),
    'list' => [
        'date_col' => 'r.order_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, e.status AS emp_status, d.name AS department, t.name AS punishment_type, t.is_suspension, t.severity, u.full_name AS by_user
                  FROM punishments r JOIN employees e ON e.id = r.employee_id JOIN punishment_types t ON t.id = r.punishment_type_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.order_date DESC, r.id DESC',
        'columns' => [
            'Order Date' => fn($r) => fmt_date($r['order_date']),
            'Type' => fn($r) => e($r['punishment_type']) . ' <span class="badge text-bg-' . ($r['severity'] === 'Major' ? 'danger' : 'secondary') . '">' . e($r['severity']) . '</span>',
            'Order' => fn($r) => e($r['order_no'] ?: '-') . ($r['order_by_authority'] ? '<br><span class="small text-muted">' . e($r['order_by_authority']) . '</span>' : ''),
            'Suspension' => fn($r) => $r['is_suspension'] ? fmt_date($r['suspension_start']) . ' → ' . ($r['suspension_end'] ? fmt_date($r['suspension_end']) : '<em>indefinite</em>') . '<br><span class="small text-muted">' . e($r['pay_status']) . '</span>' : '-',
            'Status' => fn($r) => $r['is_suspension'] ? ($r['is_reinstated'] ? '<span class="badge text-bg-success">Reinstated</span>' : '<span class="badge text-bg-danger">Suspended</span>') : '<span class="badge text-bg-light border">Recorded</span>',
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Type', 'Severity', 'Order Date', 'Order No', 'Authority', 'Suspension Start', 'Suspension End', 'Pay Status', 'Reinstated', 'Description'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['punishment_type'], $r['severity'], $r['order_date'], $r['order_no'], $r['order_by_authority'], $r['suspension_start'], $r['suspension_end'], $r['pay_status'], $r['is_reinstated'] ? 'Yes' : 'No', $r['description']]],
]);
