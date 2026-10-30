<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'promotions', 'title' => 'Promotions & Upgradations', 'singular' => 'Promotion', 'icon' => 'bi-graph-up-arrow', 'doc_type' => 'Promotion Order',
    'form' => function (array $emp) {
        $o = $GLOBALS['svc_old'];
        if ($emp['status'] === 'Suspended') echo '<div class="col-12"><div class="alert alert-warning py-2 mb-0 small">Employee is suspended: promotions and upgradations are blocked until reinstatement.</div></div>';
        elseif (!$emp['confirmation_date']) echo '<div class="col-12"><div class="alert alert-info py-2 mb-0 small"><i class="bi bi-info-circle me-1"></i>Employee is not confirmed: only <strong>Upgradation</strong> (scale change without post change) is allowed.</div></div>';
        ?>
        <div class="col-md-3"><label class="form-label">Type <span class="req">*</span></label>
            <select name="promotion_type" id="promotion_type" class="form-select">
                <option value="Promotion" <?= ($o['promotion_type'] ?? '') === 'Promotion' ? 'selected' : '' ?> <?= !$emp['confirmation_date'] ? 'disabled' : '' ?>>Regular Promotion (post + scale)</option>
                <option value="Upgradation" <?= ($o['promotion_type'] ?? (!$emp['confirmation_date'] ? 'Upgradation' : '')) === 'Upgradation' ? 'selected' : '' ?>>Special Upgradation (scale only)</option>
            </select></div>
        <div class="col-md-3 upg-field"><label class="form-label">Upgradation Kind</label><select name="upgradation_kind" class="form-select"><?= options_html(['Financial', 'Notional'], $o['upgradation_kind'] ?? 'Financial') ?></select></div>
        <div class="col-md-3 promo-field"><label class="form-label">New Designation <span class="req">*</span></label><select name="new_designation_id" class="form-select select2" data-placeholder="Select"><option value=""></option>
            <?php foreach (master_options('designations') as $d): ?><option value="<?= $d['id'] ?>" data-scale="<?= (int)$d['pay_scale_id'] ?>" <?= (string)($o['new_designation_id'] ?? '') === (string)$d['id'] ? 'selected' : '' ?>><?= e($d['name']) ?></option><?php endforeach; ?></select></div>
        <div class="col-md-3"><label class="form-label">New Pay Scale <span class="req">*</span></label><select name="new_scale_id" id="new_scale_id" class="form-select select2" data-placeholder="Select" required><option value=""></option>
            <?php foreach (master_options('pay_scales') as $s): ?><option value="<?= $s['id'] ?>" data-min="<?= e($s['min_salary']) ?>" <?= (string)($o['new_scale_id'] ?? '') === (string)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?></select>
            <div class="form-text">Current: <?= e($emp['designation']) ?> / <?= e($emp['pay_scale'] ?: '-') ?></div></div>
        <div class="col-md-3"><label class="form-label">New Basic Salary</label><input type="number" name="new_salary" id="new_salary" class="form-control" min="0" step="1" value="<?= svc_old('new_salary') ?>"><div class="form-text">Blank = scale minimum (if higher)</div></div>
        <div class="col-md-3"><label class="form-label">Effective Date <span class="req">*</span></label><input type="date" name="effective_date" class="form-control" value="<?= svc_old('effective_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-4"><label class="form-label">Order by Authority</label><input type="text" name="order_by_authority" class="form-control" value="<?= svc_old('order_by_authority') ?>" maxlength="150" placeholder="e.g. Deputy Commissioner"></div>
        <div class="col-md-5"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <div class="col-md-3 promo-field"><label class="form-label d-block">&nbsp;</label><div class="form-check"><input class="form-check-input" type="checkbox" name="create_posting" id="create_posting" value="1" checked><label class="form-check-label" for="create_posting">Also record a Promotion Posting</label></div></div>
        <script>
        document.addEventListener("DOMContentLoaded", function(){
          function t(){ const u = $('#promotion_type').val() === 'Upgradation'; $('.upg-field').toggle(u); $('.promo-field').toggle(!u); }
          $('#promotion_type').on('change', t); t();
          $('select[name=new_designation_id]').on('change', function(){ const s = $(this).find(':selected').data('scale'); if (s) $('#new_scale_id').val(String(s)).trigger('change'); });
          $('#new_scale_id').on('change', function(){ const m = $(this).find(':selected').data('min'); if (m && !$('#new_salary').val()) $('#new_salary').attr('placeholder', 'Min ' + Math.round(m)); });
        });
        </script>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_promotion($emp, [
        'promotion_type' => post('promotion_type'), 'upgradation_kind' => post('upgradation_kind'), 'new_designation_id' => post_int('new_designation_id'),
        'new_scale_id' => post_int('new_scale_id'), 'new_salary' => post_decimal('new_salary'), 'effective_date' => post_date('effective_date'),
        'order_no' => post('order_no'), 'order_date' => post_date('order_date'), 'order_by_authority' => post('order_by_authority'), 'remarks' => post('remarks'),
        'create_posting' => post('create_posting') ? 1 : 0,
    ]),
    'list' => [
        'date_col' => 'r.effective_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, pd.name AS prev_desig, nd.name AS new_desig, ps.name AS prev_scale, ns.name AS new_scale, u.full_name AS by_user
                  FROM promotions r JOIN employees e ON e.id = r.employee_id LEFT JOIN departments d ON d.id = e.department_id
                  LEFT JOIN designations pd ON pd.id = r.previous_designation_id LEFT JOIN designations nd ON nd.id = r.new_designation_id
                  LEFT JOIN pay_scales ps ON ps.id = r.previous_scale_id LEFT JOIN pay_scales ns ON ns.id = r.new_scale_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.effective_date DESC, r.id DESC',
        'columns' => [
            'Effective' => fn($r) => fmt_date($r['effective_date']),
            'Type' => fn($r) => '<span class="badge text-bg-' . ($r['promotion_type'] === 'Promotion' ? 'primary' : 'info') . '">' . e($r['promotion_type']) . '</span>' . ($r['upgradation_kind'] ? ' <span class="small">' . e($r['upgradation_kind']) . '</span>' : ''),
            'From' => fn($r) => e($r['prev_desig'] ?: '-') . ' <span class="text-muted">/</span> ' . e($r['prev_scale'] ?: '-'),
            'To' => fn($r) => '<strong>' . e($r['new_desig'] ?: $r['prev_desig']) . '</strong> <span class="text-muted">/</span> <strong>' . e($r['new_scale']) . '</strong>',
            'Order' => fn($r) => e($r['order_no'] ?: '-') . ($r['order_date'] ? '<br><span class="small text-muted">' . fmt_date($r['order_date']) . '</span>' : '') . ($r['order_by_authority'] ? '<br><span class="small text-muted">' . e($r['order_by_authority']) . '</span>' : ''),
        ],
    ],
    'export' => [
        'headers' => ['Employee', 'Code', 'Effective Date', 'Type', 'Kind', 'Previous Designation', 'New Designation', 'Previous Scale', 'New Scale', 'Order No', 'Order Date', 'Authority', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['effective_date'], $r['promotion_type'], $r['upgradation_kind'], $r['prev_desig'], $r['new_desig'], $r['prev_scale'], $r['new_scale'], $r['order_no'], $r['order_date'], $r['order_by_authority'], $r['remarks']],
    ],
]);
