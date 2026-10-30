<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$mode = get('mode', 'current');
report_page([
    'key' => 'suspension', 'title' => 'Suspension Report', 'icon' => 'bi-slash-circle', 'description' => 'Currently suspended employees and reinstatement status.',
    'filters' => function () use ($mode) { echo '<div class="col-md-2"><label class="form-label mb-1">Show</label><select name="mode" class="form-select form-select-sm"><option value="current" ' . ($mode === 'current' ? 'selected' : '') . '>Currently suspended</option><option value="all" ' . ($mode === 'all' ? 'selected' : '') . '>All suspensions</option></select></div>'; },
    'query' => function () use ($mode) { [$w, $p] = report_scope(); $st = $mode === 'current' ? ' AND pu.is_reinstated = 0' : '';
        return [report_base_select() . ', pu.order_no AS s_order, pu.order_date AS s_order_date, pu.order_by_authority, pu.suspension_start, pu.suspension_end, pu.pay_status, pu.is_reinstated, pu.description,
            (SELECT CONCAT(r.reinstatement_date, "|", t.name, "|", r.with_back_benefits, "|", r.missed_increments) FROM reinstatements r JOIN reinstatement_types t ON t.id = r.reinstatement_type_id WHERE r.punishment_id = pu.id AND r.is_voided = 0 ORDER BY r.id DESC LIMIT 1) AS reins '
            . report_base_from() . " JOIN punishments pu ON pu.employee_id = e.id AND pu.is_voided = 0 JOIN punishment_types pt ON pt.id = pu.punishment_type_id AND pt.is_suspension = 1 WHERE $w $st ORDER BY pu.is_reinstated, pu.suspension_start DESC", $p]; },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']), 'Department' => fn($r) => e($r['department']),
        'Suspended' => fn($r) => fmt_date($r['suspension_start']) . ' → ' . ($r['suspension_end'] ? fmt_date($r['suspension_end']) : '<em>indefinite</em>') . '<br><span class="small text-muted">' . e($r['pay_status']) . '</span>',
        'Order' => fn($r) => e($r['s_order'] ?: '-') . '<br><span class="small text-muted">' . e($r['order_by_authority'] ?: '') . '</span>',
        'Days' => fn($r) => days_between($r['suspension_start'], $r['is_reinstated'] && $r['reins'] ? explode('|', $r['reins'])[0] : ($r['suspension_end'] ?: today())),
        'Reinstatement' => function ($r) { if (!$r['is_reinstated']) return '<span class="badge text-bg-danger">Pending</span>'; if (!$r['reins']) return '<span class="badge text-bg-success">Reinstated</span>'; [$d, $t, $bb, $n] = explode('|', $r['reins']); return '<span class="badge text-bg-success">Reinstated</span> ' . fmt_date($d) . '<br><span class="small">' . e($t) . ($bb ? " · $n increment(s) restored" : '') . '</span>'; },
        'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Department', 'Suspension Start', 'Suspension End', 'Pay Status', 'Order No', 'Authority', 'Reinstated', 'Reinstatement Details', 'Status'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['department'], $r['suspension_start'], $r['suspension_end'], $r['pay_status'], $r['s_order'], $r['order_by_authority'], $r['is_reinstated'] ? 'Yes' : 'No', str_replace('|', ' / ', (string)$r['reins']), $r['status']]],
]);
