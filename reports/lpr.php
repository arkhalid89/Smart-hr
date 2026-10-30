<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$m = (int)setting('lpr_months', 6);
report_page([
    'key' => 'lpr', 'title' => 'LPR Eligibility Report', 'icon' => 'bi-calendar-check', 'description' => "Employees within $m months of retirement — eligible for Leave Preparatory to Retirement — and those already on LPR.",
    'query' => function () use ($m) { [$w, $p] = report_scope(); return [report_base_select() . ", (SELECT CONCAT(l.from_date,'|',l.to_date) FROM leaves l JOIN leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = e.id AND t.is_lpr = 1 AND l.is_voided = 0 AND l.status = 'Approved' ORDER BY l.from_date DESC LIMIT 1) AS lpr_leave " . report_base_from() . " WHERE $w AND e.status IN ('Active','Probation','Suspended','On Deputation','On LPR') AND e.retirement_date IS NOT NULL AND e.retirement_date >= CURDATE() AND e.retirement_date <= DATE_ADD(CURDATE(), INTERVAL ? MONTH) ORDER BY e.retirement_date", array_merge($p, [$m])]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Department' => fn($r) => e($r['department']), 'Retirement Date' => fn($r) => '<strong>' . fmt_date($r['retirement_date']) . '</strong>',
        'LPR Window From' => fn($r) => fmt_date(date_create($r['retirement_date'])->modify("-$m months")->format('Y-m-d')),
        'LPR Status' => function ($r) { if ($r['status'] === 'On LPR') { [$f, $t] = explode('|', $r['lpr_leave'] ?: '|'); return '<span class="badge text-bg-warning">On LPR</span> <span class="small">' . fmt_date($f) . ' → ' . fmt_date($t) . '</span>'; } return '<span class="badge text-bg-info">Eligible</span>'; },
        'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'Retirement Date', 'LPR Window From', 'LPR Status', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['retirement_date'], date_create($r['retirement_date'])->modify("-$m months")->format('Y-m-d'), $r['status'] === 'On LPR' ? 'On LPR' : 'Eligible', $r['status']]],
]);
