<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$m = (int)setting('eol_break_months', 6);
report_page([
    'key' => 'break_in_service', 'title' => 'Break in Service Report', 'icon' => 'bi-exclamation-triangle', 'description' => "Employees with EOL (without pay) longer than $m months — affects pension and retirement date.",
    'query' => function () { [$w, $p] = report_scope();
        return [report_base_select() . ', l.from_date, l.to_date, l.duration_days, l.letter_no, t.name AS leave_type ' . report_base_from() . " JOIN leaves l ON l.employee_id = e.id AND l.is_break_in_service = 1 AND l.is_voided = 0 JOIN leave_types t ON t.id = l.leave_type_id WHERE $w ORDER BY l.from_date DESC", $p]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Department' => fn($r) => e($r['department']), 'EOL Period' => fn($r) => fmt_date($r['from_date']) . ' → ' . fmt_date($r['to_date']),
        'Days' => fn($r) => '<span class="badge text-bg-danger">' . (int)$r['duration_days'] . '</span>', 'Letter' => fn($r) => e($r['letter_no'] ?: '-'), 'Total Break Days' => fn($r) => (int)$r['eol_break_days'],
        'Original Retirement' => fn($r) => fmt_date(calc_retirement_date($r['dob'], 0)), 'Adjusted Retirement' => fn($r) => '<strong>' . fmt_date($r['retirement_date']) . '</strong>', 'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'EOL From', 'EOL To', 'Days', 'Letter No', 'Total Break Days', 'Original Retirement', 'Adjusted Retirement', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['from_date'], $r['to_date'], $r['duration_days'], $r['letter_no'], $r['eol_break_days'], calc_retirement_date($r['dob'], 0), $r['retirement_date'], $r['status']]],
]);
