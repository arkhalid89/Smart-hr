<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
report_page([
    'key' => 'death_in_service', 'title' => 'Death in Service Report', 'icon' => 'bi-heart', 'description' => 'Deceased employees with nominee / pension claimant details for family pension and gratuity processing.',
    'query' => function () { [$w, $p] = report_scope();
        return [report_base_select() . ', x.exit_date, x.date_of_death, x.cause_of_death, x.order_no AS x_order,
            (SELECT GROUP_CONCAT(CONCAT(f.name, " (", f.relation, IF(f.nominee_share IS NULL, "", CONCAT(" ", f.nominee_share, "%")), IF(f.cnic IS NULL, "", CONCAT(" CNIC ", f.cnic)), IF(f.contact IS NULL, "", CONCAT(" Tel ", f.contact)), ")") SEPARATOR "; ") FROM employee_family f WHERE f.employee_id = e.id AND f.is_deleted = 0 AND (f.is_pension_claimant = 1 OR f.is_nominee = 1)) AS claimants '
            . report_base_from() . " JOIN exits x ON x.employee_id = e.id AND x.exit_type = 'Death in Service' AND x.is_voided = 0 WHERE $w ORDER BY x.date_of_death DESC", $p]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']) . ' <span class="small text-muted">' . e($r['pay_scale']) . '</span>', 'Department' => fn($r) => e($r['department']),
        'Date of Death' => fn($r) => '<strong>' . fmt_date($r['date_of_death'] ?: $r['exit_date']) . '</strong>', 'Cause' => fn($r) => e($r['cause_of_death'] ?: '-'), 'Service' => fn($r) => fmt_date($r['joining_date']) . ' → ' . fmt_date($r['exit_date']),
        'Pension No / GPF' => fn($r) => e($r['pension_no'] ?: '-') . ' / ' . e($r['gp_fund_no'] ?: '-'),
        'Nominees / Claimants' => fn($r) => $r['claimants'] ? '<span class="small">' . e($r['claimants']) . '</span>' : '<span class="badge text-bg-warning">No nominee recorded</span>',
        'Service Book' => fn($r) => $r['service_closed'] ? '<span class="badge text-bg-dark">Closed</span>' : '-'],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Scale', 'Department', 'Date of Death', 'Cause', 'Joining Date', 'Exit Date', 'Order No', 'Pension No', 'GP Fund No', 'Nominees / Claimants'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['pay_scale'], $r['department'], $r['date_of_death'] ?: $r['exit_date'], $r['cause_of_death'], $r['joining_date'], $r['exit_date'], $r['x_order'], $r['pension_no'], $r['gp_fund_no'], $r['claimants']]],
]);
