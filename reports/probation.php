<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$mode = get('mode', 'due');
report_page([
    'key' => 'probation', 'title' => 'Probation Completion Report', 'icon' => 'bi-hourglass-split', 'description' => 'Unconfirmed employees whose probation period has ended or ends soon.',
    'filters' => function () use ($mode) { echo '<div class="col-md-3"><label class="form-label mb-1">Show</label><select name="mode" class="form-select form-select-sm"><option value="due" ' . ($mode === 'due' ? 'selected' : '') . '>Completed / due within 30 days</option><option value="all" ' . ($mode === 'all' ? 'selected' : '') . '>All on probation</option><option value="confirmed" ' . ($mode === 'confirmed' ? 'selected' : '') . '>Confirmed (history)</option></select></div>'; },
    'query' => function () use ($mode) { [$w, $p] = report_scope();
        if ($mode === 'confirmed') return [report_base_select() . report_base_from() . " WHERE $w AND e.confirmation_date IS NOT NULL ORDER BY e.confirmation_date DESC", $p];
        $extra = $mode === 'all' ? '' : ' AND e.probation_end <= DATE_ADD(CURDATE(), INTERVAL 30 DAY)';
        return [report_base_select() . report_base_from() . " WHERE $w AND e.confirmation_date IS NULL AND e.status IN ('Probation','Active') AND e.probation_end IS NOT NULL $extra ORDER BY e.probation_end", $p]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Department' => fn($r) => e($r['department']), 'Joined' => fn($r) => fmt_date($r['joining_date']),
        'Probation' => fn($r) => fmt_date($r['probation_start']) . ' → <strong>' . fmt_date($r['probation_end']) . '</strong>',
        'Confirmation' => function ($r) { if ($r['confirmation_date']) return '<span class="badge text-bg-success">Confirmed</span> ' . fmt_date($r['confirmation_date']); $d = (int)date_create(today())->diff(date_create($r['probation_end']))->format('%r%a'); return $d <= 0 ? '<span class="badge text-bg-danger">Overdue ' . abs($d) . 'd</span>' : '<span class="badge text-bg-warning">Due in ' . $d . 'd</span>'; },
        'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'Joining Date', 'Probation Start', 'Probation End', 'Confirmation Date', 'Confirmation Order', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['joining_date'], $r['probation_start'], $r['probation_end'], $r['confirmation_date'], $r['confirmation_order_no'], $r['status']]],
]);
