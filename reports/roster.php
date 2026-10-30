<?php
/** Departmental Roster: serving employees by department + sanctioned vs filled vs vacant posts. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';
$servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
report_page([
    'key' => 'roster', 'title' => 'Departmental Roster', 'icon' => 'bi-people', 'description' => 'Current serving employees by department, with sanctioned strength and vacancies.',
    'query' => function () use ($servingIn) { [$w, $p] = report_scope(); return [report_base_select() . ', p.posting_type, l.name AS location ' . report_base_from() . " LEFT JOIN postings p ON p.id = e.current_posting_id LEFT JOIN posting_locations l ON l.id = p.location_id WHERE $w AND e.status IN ($servingIn) ORDER BY d.sort_order, d.name, ps.scale_number DESC, e.name", $p]; },
    'summary' => function (array $rows) {
        [$dScope, $dp] = department_scope('d');
        [$gw, $gp] = geo_filter_sql($_GET, 'sp');
        $dep = ' AND ' . $gw; $dp = array_merge($dp, $gp);
        $servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
        $stats = db_all("SELECT d.name, g.name AS designation, sp.sanctioned_count,
            (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.designation_id = g.id AND e.is_deleted = 0 AND e.status IN ($servingIn)) AS filled
            FROM sanctioned_posts sp JOIN departments d ON d.id = sp.department_id JOIN designations g ON g.id = sp.designation_id WHERE sp.is_deleted = 0 AND sp.is_active = 1 AND $dScope $dep ORDER BY d.sort_order, g.sort_order", $dp);
        $s = $f = 0; foreach ($stats as $x) { $s += (int)$x['sanctioned_count']; $f += (int)$x['filled']; }
        $h = '<div class="d-flex flex-wrap gap-4 small mb-2"><span>Sanctioned posts: <strong>' . $s . '</strong></span><span>Filled: <strong class="text-success">' . $f . '</strong></span><span>Vacant: <strong class="text-danger">' . max(0, $s - $f) . '</strong></span><span>Serving employees listed: <strong>' . count($rows) . '</strong></span></div>';
        $h .= '<details><summary class="small text-primary">Sanctioned vs filled by post</summary><div class="table-responsive mt-2"><table class="table table-sm table-bordered mb-0 small"><thead><tr><th>Department</th><th>Designation</th><th class="text-end">Sanctioned</th><th class="text-end">Filled</th><th class="text-end">Vacant</th></tr></thead><tbody>';
        foreach ($stats as $x) { $v = (int)$x['sanctioned_count'] - (int)$x['filled']; $h .= '<tr><td>' . e($x['name']) . '</td><td>' . e($x['designation']) . '</td><td class="text-end">' . $x['sanctioned_count'] . '</td><td class="text-end">' . $x['filled'] . '</td><td class="text-end ' . ($v > 0 ? 'text-danger fw-bold' : ($v < 0 ? 'text-warning' : '')) . '">' . $v . '</td></tr>'; }
        return $h . '</tbody></table></div></details>';
    },
    'columns' => ['Employee' => fn($r) => report_emp_cell($r), 'Designation' => fn($r) => e($r['designation']) . '<br><span class="small text-muted">' . e($r['pay_scale'] ?: '-') . '</span>', 'Department' => fn($r) => e($r['department']),
        'Posting' => fn($r) => e($r['location'] ?: '-') . '<br><span class="small text-muted">' . e($r['posting_type'] ?: '') . '</span>', 'Type' => fn($r) => e($r['employment_type'] ?: '-'), 'Joined' => fn($r) => fmt_date($r['joining_date']), 'Status' => fn($r) => status_badge($r['status'])],
    'export' => ['headers' => ['Employee', 'Code', 'CNIC', 'Designation', 'Scale', 'Department', 'Posting Location', 'Employment Type', 'Joining Date', 'Status', 'Contact'],
        'row' => fn($r) => [$r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['pay_scale'], $r['department'], $r['location'], $r['employment_type'], $r['joining_date'], $r['status'], $r['contact']]],
]);
