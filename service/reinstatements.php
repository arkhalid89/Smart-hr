<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'reinstatements', 'title' => 'Reinstatements', 'singular' => 'Reinstatement', 'icon' => 'bi-arrow-counterclockwise', 'doc_type' => 'Reinstatement Order',
    'guard' => fn($emp) => $emp['status'] !== 'Suspended' ? 'Reinstatement can only be recorded for a suspended employee (current status: ' . $emp['status'] . ').' : null,
    'form' => function (array $emp) {
        $susp = db_row('SELECT p.*, t.name AS type FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? AND t.is_suspension = 1 AND p.is_reinstated = 0 AND p.is_voided = 0 ORDER BY p.id DESC LIMIT 1', [(int)$emp['id']]);
        if ($susp) echo '<div class="col-12"><div class="alert alert-light border py-2 mb-0 small"><strong>Open suspension:</strong> since ' . fmt_date($susp['suspension_start']) . ' · ' . e($susp['pay_status']) . ($susp['order_no'] ? ' · Order ' . e($susp['order_no']) : '') . '</div></div>';
        ?>
        <div class="col-md-4"><label class="form-label">Reinstatement Type <span class="req">*</span></label><select name="reinstatement_type_id" id="reins_type" class="form-select select2" data-placeholder="Select" required><option value=""></option>
            <?php foreach (master_options('reinstatement_types') as $t): ?><option value="<?= $t['id'] ?>" data-bb="<?= $t['with_back_benefits'] ?>" <?= (string)($GLOBALS['svc_old']['reinstatement_type_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?></option><?php endforeach; ?></select>
            <div class="form-text" id="bbHint"></div></div>
        <div class="col-md-2"><label class="form-label">Reinstatement Date <span class="req">*</span></label><input type="date" name="reinstatement_date" class="form-control" value="<?= svc_old('reinstatement_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-4"><label class="form-label">Order by Authority</label><input type="text" name="order_by_authority" class="form-control" value="<?= svc_old('order_by_authority') ?>" maxlength="150"></div>
        <div class="col-md-8"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <script>document.addEventListener("DOMContentLoaded", function(){ function t(){ $('#bbHint').text($('#reins_type').find(':selected').data('bb') == 1 ? 'With back benefits: missed annual increments (1 December) during suspension are auto-restored and service continuity is preserved.' : 'Without back benefits: no increments are restored for the suspension period.'); } $('#reins_type').on('change', t); t(); });</script>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_reinstatement($emp, ['reinstatement_type_id' => post_int('reinstatement_type_id'), 'reinstatement_date' => post_date('reinstatement_date'), 'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'order_by_authority' => post('order_by_authority'), 'remarks' => post('remarks')]),
    'list' => [
        'date_col' => 'r.reinstatement_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, t.name AS reinstatement_type, p.suspension_start, u.full_name AS by_user
                  FROM reinstatements r JOIN employees e ON e.id = r.employee_id JOIN reinstatement_types t ON t.id = r.reinstatement_type_id LEFT JOIN punishments p ON p.id = r.punishment_id
                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.reinstatement_date DESC, r.id DESC',
        'columns' => [
            'Date' => fn($r) => fmt_date($r['reinstatement_date']),
            'Type' => fn($r) => e($r['reinstatement_type']),
            'Suspended Since' => fn($r) => fmt_date($r['suspension_start']),
            'Back Benefits' => fn($r) => $r['with_back_benefits'] ? '<span class="badge text-bg-success">Yes</span> ' . (int)$r['missed_increments'] . ' increment(s)' : '<span class="badge text-bg-secondary">No</span>',
            'Order' => fn($r) => e($r['order_no'] ?: '-') . ($r['order_by_authority'] ? '<br><span class="small text-muted">' . e($r['order_by_authority']) . '</span>' : ''),
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Reinstatement Date', 'Type', 'Suspended Since', 'With Back Benefits', 'Missed Increments', 'Order No', 'Order Date', 'Authority', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['reinstatement_date'], $r['reinstatement_type'], $r['suspension_start'], $r['with_back_benefits'] ? 'Yes' : 'No', $r['missed_increments'], $r['order_no'], $r['order_date'], $r['order_by_authority'], $r['remarks']]],
]);
