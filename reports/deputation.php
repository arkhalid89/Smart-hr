<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$mode = get('mode', 'active');
report_page([
    'key' => 'deputation', 'title' => 'Deputation Report', 'icon' => 'bi-box-arrow-up-right', 'description' => 'Employees on deputation with expected reversion dates.',
    'filters' => function () use ($mode) { echo '<div class="col-md-2"><label class="form-label mb-1">Show</label><select name="mode" class="form-select form-select-sm"><option value="active" ' . ($mode === 'active' ? 'selected' : '') . '>Currently on deputation</option><option value="all" ' . ($mode === 'all' ? 'selected' : '') . '>All (incl. reverted)</option></select></div>'; },
    'query' => function () use ($mode) { [$w, $p] = report_scope(); $st = $mode === 'active' ? " AND dp.status = 'Active'" : '';
        return [report_base_select() . ', dp.start_date, dp.expected_reversion_date, dp.actual_reversion_date, dp.status AS dep_status, dp.order_no AS dep_order, o.name AS org ' . report_base_from() . " JOIN deputations dp ON dp.employee_id = e.id AND dp.is_voided = 0 JOIN deputation_orgs o ON o.id = dp.deputation_org_id WHERE $w $st ORDER BY dp.status, dp.expected_reversion_date", $p]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Parent Department' => fn($r) => e($r['department']), 'Deputed To' => fn($r) => '<strong>' . e($r['org']) . '</strong>', 'From' => fn($r) => fmt_date($r['start_date']),
        'Expected Reversion' => function ($r) { if ($r['dep_status'] !== 'Active') return fmt_date($r['expected_reversion_date']); if (!$r['expected_reversion_date']) return '<span class="text-muted">Not set</span>'; $d = (int)date_create(today())->diff(date_create($r['expected_reversion_date']))->format('%r%a'); return fmt_date($r['expected_reversion_date']) . ' ' . ($d < 0 ? '<span class="badge text-bg-danger">Overdue</span>' : ($d <= 30 ? '<span class="badge text-bg-warning">' . $d . 'd</span>' : '')); },
        'Reverted On' => fn($r) => fmt_date($r['actual_reversion_date']), 'Order' => fn($r) => e($r['dep_order'] ?: '-'), 'Deputation' => fn($r) => $r['dep_status'] === 'Active' ? '<span class="badge text-bg-primary">Active</span>' : '<span class="badge text-bg-success">Reverted</span>'],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Parent Department', 'Deputed To', 'Start', 'Expected Reversion', 'Actual Reversion', 'Order No', 'Deputation Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['org'], $r['start_date'], $r['expected_reversion_date'], $r['actual_reversion_date'], $r['dep_order'], $r['dep_status']]],
]);
