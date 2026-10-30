<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$years = max(1, min(10, (int)(get('years') ?: 1)));
report_page([
    'key' => 'retirement', 'title' => 'Retirement List', 'icon' => 'bi-award', 'description' => "Employees due to retire within $years year(s) at age " . setting('retirement_age', 60) . ' (adjusted for EOL breaks).',
    'filters' => function () use ($years) { echo '<div class="col-md-2"><label class="form-label mb-1">Within (years)</label><select name="years" class="form-select form-select-sm">' . options_html([1, 2, 3, 5, 10], $years) . '</select></div>'; },
    'query' => function () use ($years) { [$w, $p] = report_scope(); $s = "'" . implode("','", SERVING_STATUSES) . "'"; return [report_base_select() . report_base_from() . " WHERE $w AND e.status IN ($s) AND e.retirement_date IS NOT NULL AND e.retirement_date <= DATE_ADD(CURDATE(), INTERVAL ? YEAR) ORDER BY e.retirement_date", array_merge($p, [$years])]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']) . ' <span class="small text-muted">' . e($r['pay_scale']) . '</span>', 'Department' => fn($r) => e($r['department']),
        'DOB / Age' => fn($r) => fmt_date($r['dob']) . ' <span class="small text-muted">(' . calc_age($r['dob']) . ')</span>', 'Joined' => fn($r) => fmt_date($r['joining_date']),
        'Retirement Date' => fn($r) => '<strong>' . fmt_date($r['retirement_date']) . '</strong>' . ($r['has_break_in_service'] ? ' <span class="badge text-bg-danger" title="Adjusted for EOL break">+' . (int)$r['eol_break_days'] . 'd</span>' : ''),
        'LPR' => fn($r) => $r['is_lpr_eligible'] ? '<span class="badge text-bg-warning">Eligible</span>' : '-', 'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Scale', 'Department', 'Date of Birth', 'Age', 'Joining Date', 'Retirement Date', 'EOL Break Days', 'LPR Eligible', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['pay_scale'], $r['department'], $r['dob'], calc_age($r['dob']), $r['joining_date'], $r['retirement_date'], $r['eol_break_days'], $r['is_lpr_eligible'] ? 'Yes' : 'No', $r['status']]],
]);
