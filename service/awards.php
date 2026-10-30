<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'awards', 'title' => 'Awards', 'singular' => 'Award', 'icon' => 'bi-trophy', 'doc_type' => 'Service Document',
    'form' => function (array $emp) { ?>
        <div class="col-md-4"><label class="form-label">Award Type <span class="req">*</span></label><select name="award_type_id" class="form-select select2" data-placeholder="Select award" required><option value=""></option><?= options_html(master_options('award_types'), $GLOBALS['svc_old']['award_type_id'] ?? '') ?></select></div>
        <div class="col-md-3"><label class="form-label">Order Date <span class="req">*</span></label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date', today()) ?>" required></div>
        <div class="col-md-3"><label class="form-label">Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-12"><label class="form-label">Description</label><textarea name="description" class="form-control" rows="2" maxlength="500"><?= svc_old('description') ?></textarea></div>
    <?php },
    'save' => fn(array $emp, array $p) => svc_award($emp, ['award_type_id' => post_int('award_type_id'), 'order_date' => post_date('order_date'), 'order_no' => post('order_no'), 'description' => post('description')]),
    'list' => [
        'date_col' => 'r.order_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, t.name AS award_type, u.full_name AS by_user FROM awards r JOIN employees e ON e.id = r.employee_id
                  JOIN award_types t ON t.id = r.award_type_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.order_date DESC, r.id DESC',
        'columns' => [
            'Date' => fn($r) => fmt_date($r['order_date']),
            'Award' => fn($r) => '<i class="bi bi-trophy text-warning me-1"></i>' . e($r['award_type']),
            'Order' => fn($r) => e($r['order_no'] ?: '-'),
            'Description' => fn($r) => '<span class="small">' . e($r['description'] ?: '-') . '</span>',
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Award', 'Order Date', 'Order No', 'Description'], 'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['award_type'], $r['order_date'], $r['order_no'], $r['description']]],
]);
