<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$days = max(1, (int)(get('days') ?: setting('contract_expiry_alert_days', 60)));
report_page([
    'key' => 'contract_expiry', 'title' => 'Contract Expiry Report', 'icon' => 'bi-file-earmark-x', 'description' => "Contracts expiring within $days days (plus already expired but still serving).",
    'filters' => function () use ($days) { echo '<div class="col-md-2"><label class="form-label mb-1">Within (days)</label><input type="number" name="days" class="form-control form-control-sm" value="' . $days . '" min="1" max="730"></div>'; },
    'query' => function () use ($days) { [$w, $p] = report_scope(); $s = "'" . implode("','", SERVING_STATUSES) . "'"; return [report_base_select() . report_base_from() . " WHERE $w AND e.status IN ($s) AND e.contract_to IS NOT NULL AND e.contract_to <= DATE_ADD(CURDATE(), INTERVAL ? DAY) ORDER BY e.contract_to", array_merge($p, [$days])]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Department' => fn($r) => e($r['department']), 'Type' => fn($r) => e($r['employment_type']),
        'Contract' => fn($r) => fmt_date($r['contract_from']) . ' → <strong>' . fmt_date($r['contract_to']) . '</strong>',
        'Days Left' => function ($r) { $d = (int)date_create(today())->diff(date_create($r['contract_to']))->format('%r%a'); return $d < 0 ? '<span class="badge text-bg-danger">Expired ' . abs($d) . 'd ago</span>' : '<span class="badge text-bg-' . ($d <= 15 ? 'danger' : ($d <= 30 ? 'warning' : 'info')) . '">' . $d . ' days</span>'; },
        'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'Employment Type', 'Contract From', 'Contract To', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['employment_type'], $r['contract_from'], $r['contract_to'], $r['status']]],
]);
