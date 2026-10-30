<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
if (is_employee_user()) redirect('employees/view.php?id=' . (int)user_employee_id());
require_staff();

[$scopeSql, $scopeParams] = emp_scope('e');
$base = "FROM employees e WHERE e.is_deleted = 0 AND $scopeSql";
$servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
$today = today();

/* ---------------- Status counts ---------------- */
$counts = array_fill_keys(EMP_STATUSES, 0);
foreach (db_all("SELECT e.status, COUNT(*) c $base GROUP BY e.status", $scopeParams) as $r) $counts[$r['status']] = (int)$r['c'];
$total = array_sum($counts);
$serving = 0; foreach (SERVING_STATUSES as $s) $serving += $counts[$s];
$newThisYear = (int)db_value("SELECT COUNT(*) $base AND YEAR(e.joining_date) = YEAR(CURDATE())", $scopeParams, 0);
$exitsThisYear = (int)db_value("SELECT COUNT(*) FROM exits x JOIN employees e ON e.id = x.employee_id WHERE x.is_voided = 0 AND e.is_deleted = 0 AND $scopeSql AND YEAR(x.exit_date) = YEAR(CURDATE())", $scopeParams, 0);

/* ---------------- Sanctioned vs filled ---------------- */
[$dScope, $dParams] = department_scope('d');
$sanct = db_row("SELECT COALESCE(SUM(sp.sanctioned_count),0) AS sanctioned FROM sanctioned_posts sp JOIN departments d ON d.id = sp.department_id WHERE sp.is_deleted = 0 AND sp.is_active = 1 AND $dScope", $dParams);
$sanctioned = (int)($sanct['sanctioned'] ?? 0);
$vacant = max(0, $sanctioned - $serving);

/* ---------------- Alerts ---------------- */
$contractDays = (int)setting('contract_expiry_alert_days', 60);
$retireMonths = (int)setting('retirement_alert_months', 12);
$lprMonths    = (int)setting('lpr_months', 6);
$depDays      = (int)setting('deputation_alert_days', 30);
$sel = "SELECT e.id, e.employee_code, e.name, e.status, e.photo, e.contract_to, e.retirement_date, e.probation_end, d.name AS department, g.name AS designation";
$joins = "FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations g ON g.id = e.designation_id WHERE e.is_deleted = 0 AND $scopeSql";
$alerts = [
    'contracts' => ['Contract Expiry', 'bi-file-earmark-x', 'danger', db_all("$sel $joins AND e.status IN ($servingIn) AND e.contract_to IS NOT NULL AND e.contract_to BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY) ORDER BY e.contract_to LIMIT 12", array_merge($scopeParams, [$today, $today, $contractDays])),
        fn($r) => 'Contract ends <strong>' . fmt_date($r['contract_to']) . '</strong>', 'reports/contract_expiry.php', "Contracts ending in the next $contractDays days"],
    'retirements' => ['Retirements', 'bi-award', 'warning', db_all("$sel $joins AND e.status IN ($servingIn) AND e.retirement_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? MONTH) ORDER BY e.retirement_date LIMIT 12", array_merge($scopeParams, [$today, $today, $retireMonths])),
        fn($r) => 'Retires <strong>' . fmt_date($r['retirement_date']) . '</strong>', 'reports/retirement.php', "Retiring within $retireMonths months"],
    'lpr' => ['LPR Eligible', 'bi-calendar-check', 'info', db_all("$sel $joins AND e.status IN ('Active','Probation') AND e.retirement_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? MONTH) ORDER BY e.retirement_date LIMIT 12", array_merge($scopeParams, [$today, $today, $lprMonths])),
        fn($r) => 'Retirement ' . fmt_date($r['retirement_date']), 'reports/lpr.php', 'Eligible for Leave Preparatory to Retirement'],
    'probation' => ['Probation', 'bi-hourglass-split', 'primary', db_all("$sel $joins AND e.status = 'Probation' AND e.confirmation_date IS NULL AND e.probation_end IS NOT NULL AND e.probation_end <= DATE_ADD(?, INTERVAL 30 DAY) ORDER BY e.probation_end LIMIT 12", array_merge($scopeParams, [$today])),
        fn($r) => 'Probation ends <strong>' . fmt_date($r['probation_end']) . '</strong>', 'reports/probation.php', 'Confirmation due'],
    'deputation' => ['Reversions', 'bi-box-arrow-in-left', 'purple', db_all("SELECT e.id, e.employee_code, e.name, e.status, e.photo, d.name AS department, g.name AS designation, dp.expected_reversion_date, o.name AS org
        FROM deputations dp JOIN employees e ON e.id = dp.employee_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations g ON g.id = e.designation_id LEFT JOIN deputation_orgs o ON o.id = dp.deputation_org_id
        WHERE dp.status = 'Active' AND dp.is_voided = 0 AND e.is_deleted = 0 AND $scopeSql AND dp.expected_reversion_date IS NOT NULL AND dp.expected_reversion_date <= DATE_ADD(?, INTERVAL ? DAY) ORDER BY dp.expected_reversion_date LIMIT 12", array_merge($scopeParams, [$today, $depDays])),
        fn($r) => 'From ' . e($r['org']) . ' · expected <strong>' . fmt_date($r['expected_reversion_date']) . '</strong>', 'reports/deputation.php', 'Deputation reversions due'],
];
$alertTotal = array_sum(array_map(fn($a) => count($a[3]), $alerts));

/* ---------------- Department-wise ----------------
   Only departments that actually hold staff or sanctioned posts — the master
   list covers every office in the province. */
$deptStats = db_all("SELECT d.name,
        SUM(CASE WHEN e.status IN ($servingIn) THEN 1 ELSE 0 END) AS serving,
        COALESCE((SELECT SUM(sp.sanctioned_count) FROM sanctioned_posts sp WHERE sp.department_id = d.id AND sp.is_deleted = 0 AND sp.is_active = 1),0) AS sanctioned
    FROM departments d LEFT JOIN employees e ON e.department_id = d.id AND e.is_deleted = 0
    WHERE d.is_deleted = 0 AND d.is_active = 1 AND $dScope GROUP BY d.id, d.name
    HAVING COUNT(e.id) > 0 OR sanctioned > 0
    ORDER BY serving DESC, d.name LIMIT 40", $dParams);

/* ---------------- Recent activity ---------------- */
/* officers see their own entries; the full trail is the Super Admin's */
$activity = has_full_scope()
    ? db_all('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.action NOT IN ("LOGIN","LOGOUT","EXPORT","CRON") ORDER BY a.id DESC LIMIT 8')
    : db_all('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.user_id = ? AND a.action NOT IN ("LOGIN","LOGOUT","EXPORT","CRON") ORDER BY a.id DESC LIMIT 8', [(int)user_id()]);

$statusMeta = [
    'Active' => ['#16a34a', 'Active'], 'Probation' => ['#0ea5e9', 'Probation'], 'Suspended' => ['#dc2626', 'Suspended'], 'On Deputation' => ['#7c3aed', 'On Deputation'],
    'On LPR' => ['#d97706', 'On LPR'], 'Contract Expired' => ['#94a3b8', 'Contract Expired'], 'Resigned' => ['#64748b', 'Resigned'], 'Retired' => ['#334155', 'Retired'],
    'Medical Retired' => ['#475569', 'Medical Retired'], 'Terminated' => ['#b91c1c', 'Terminated'], 'Dismissed' => ['#7f1d1d', 'Dismissed'], 'Deceased (In Service)' => ['#0f172a', 'Deceased'],
];

$page_title  = 'Dashboard';
$page_subtitle = 'Workforce overview for ' . (has_full_scope() ? setting('org_name', ORG_NAME) : jurisdiction_label()) . ' · ' . fmt_date($today, 'l, d F Y');
$active_menu = 'dashboard';
if (can_edit()) {
    $page_actions = '<a href="' . url('employees/add.php') . '" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>New Employee</a>';
}
require ROOT_PATH . '/includes/header.php';
?>

<!-- ===== Hero KPIs ===== -->
<div class="row g-3 mb-3">
    <?php
    $kpi = [
        ['Total Employees', $total, 'bi-people', 'primary', 'employees/index.php', $newThisYear . ' joined this year'],
        ['Serving Strength', $serving, 'bi-person-check', 'success', 'employees/index.php?serving=1', $sanctioned ? round($serving / max(1, $sanctioned) * 100) . '% of sanctioned' : 'No sanctioned posts set'],
        ['Vacant Posts', $vacant, 'bi-door-open', 'warning', 'reports/roster.php', $sanctioned . ' sanctioned posts'],
        ['Needs Attention', $alertTotal, 'bi-bell', $alertTotal ? 'danger' : 'secondary', '#alerts', $exitsThisYear . ' exits this year'],
    ];
    foreach ($kpi as [$label, $val, $icon, $color, $link, $sub]): ?>
    <div class="col-6 col-xl-3">
        <a href="<?= e(str_starts_with($link, '#') ? $link : url($link)) ?>" class="card kpi-card text-reset text-decoration-none">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-start">
                    <div><div class="kpi-label"><?= e($label) ?></div><div class="kpi-value"><?= number_format($val) ?></div></div>
                    <div class="stat-icon bg-soft-<?= $color ?>"><i class="bi <?= $icon ?>"></i></div>
                </div>
                <div class="kpi-sub"><?= e($sub) ?></div>
            </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<div class="row g-3">
    <!-- ===== Composition ===== -->
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Workforce Composition</span><a href="<?= url('employees/index.php') ?>" class="small">All employees</a></div>
            <div class="card-body">
                <?php if (!$total): ?><div class="empty-state"><i class="bi bi-people"></i>No employees yet.</div><?php else: ?>
                <div class="segment-bar mb-3">
                    <?php foreach ($statusMeta as $st => [$c]): if (!$counts[$st]) continue; ?>
                        <span style="width:<?= round($counts[$st] / $total * 100, 2) ?>%;background:<?= $c ?>" title="<?= e($st) ?>: <?= $counts[$st] ?>"></span>
                    <?php endforeach; ?>
                </div>
                <div class="legend-grid">
                    <?php foreach ($statusMeta as $st => [$c, $label]): if (!$counts[$st]) continue; ?>
                    <a href="<?= url('employees/index.php?status=' . urlencode($st)) ?>" class="legend-item"><span class="legend-dot" style="background:<?= $c ?>"></span><span class="legend-label"><?= e($label) ?></span><span class="legend-value"><?= $counts[$st] ?></span></a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <!-- ===== Department chart ===== -->
    <div class="col-xl-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Department Strength vs Sanctioned</span><a href="<?= url('reports/roster.php') ?>" class="small">Roster report</a></div>
            <div class="card-body"><?php if (!$deptStats): ?><div class="empty-state"><i class="bi bi-building"></i>No departments configured.</div><?php else: ?><canvas id="deptChart" height="120"></canvas><?php endif; ?></div>
        </div>
    </div>

    <!-- ===== Alerts (tabbed) ===== -->
    <div class="col-xl-7" id="alerts">
        <div class="card h-100">
            <div class="card-header pb-0 border-0">
                <span class="card-title">Alerts &amp; Upcoming Events</span>
                <ul class="nav nav-pills nav-pills-sm mt-2" role="tablist">
                    <?php $i = 0; foreach ($alerts as $k => [$label, $icon, $color, $rows]): ?>
                    <li class="nav-item"><a class="nav-link <?= $i++ === 0 ? 'active' : '' ?>" data-bs-toggle="tab" href="#al-<?= $k ?>"><?= e($label) ?> <span class="count"><?= count($rows) ?></span></a></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="tab-content">
                <?php $i = 0; foreach ($alerts as $k => [$label, $icon, $color, $rows, $line, $link, $desc]): ?>
                <div class="tab-pane fade <?= $i++ === 0 ? 'show active' : '' ?>" id="al-<?= $k ?>">
                    <?php if (!$rows): ?><div class="empty-state py-4"><i class="bi bi-check2-circle text-success"></i>Nothing pending — <?= e(strtolower($desc)) ?>.</div>
                    <?php else: ?>
                    <div class="list-group list-group-flush">
                        <?php foreach ($rows as $r): ?>
                        <a href="<?= url('employees/view.php?id=' . $r['id']) ?>" class="list-group-item list-group-item-action alert-row">
                            <img src="<?= photo_url($r['photo'], (int)$r['id']) ?>" alt="">
                            <div class="flex-grow-1 min-w-0"><div class="fw-semibold text-truncate"><?= e($r['name']) ?> <span class="text-muted fw-normal small">· <?= e($r['designation']) ?></span></div><div class="small text-muted"><?= $line($r) ?></div></div>
                            <i class="bi bi-chevron-right text-muted small"></i>
                        </a>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <div class="card-footer bg-transparent py-2 small"><a href="<?= url($link) ?>">Open full report <i class="bi bi-arrow-right"></i></a></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- ===== Activity ===== -->
    <div class="col-xl-5">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Recent Activity</span><?php if (is_super_admin()): ?><a href="<?= url('admin/audit_logs.php') ?>" class="small">All logs</a><?php endif; ?></div>
            <?php if (!$activity): ?><div class="empty-state"><i class="bi bi-activity"></i>No activity yet.</div><?php else: ?>
            <div class="activity-list">
                <?php foreach ($activity as $a): ?>
                <div class="activity-item">
                    <span class="avatar-initials sm"><?= e(strtoupper(mb_substr($a['full_name'] ?: $a['username'] ?: '?', 0, 1))) ?></span>
                    <div class="min-w-0"><div class="small"><span class="fw-semibold"><?= e($a['full_name'] ?: $a['username']) ?></span> <span class="text-muted"><?= e(strtolower($a['action'])) ?></span> <?= e($a['description'] ?: ($a['table_name'] . ' #' . $a['record_id'])) ?></div><div class="x-small text-muted"><?= fmt_datetime($a['created_at']) ?></div></div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
$deptLabels = json_encode(array_column($deptStats, 'name'));
$deptServing = json_encode(array_map('intval', array_column($deptStats, 'serving')));
$deptSanct   = json_encode(array_map('intval', array_column($deptStats, 'sanctioned')));
$extra_js = <<<JS
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function(){
  Chart.defaults.font.family = 'Inter, system-ui, sans-serif'; Chart.defaults.font.size = 12; Chart.defaults.color = '#64748b';
  const dc = document.getElementById('deptChart');
  if (dc) new Chart(dc, { type: 'bar',
    data: { labels: $deptLabels, datasets: [
      { label: 'Sanctioned', data: $deptSanct, backgroundColor: '#e2e8f0', borderRadius: 6, barPercentage: .7, categoryPercentage: .6 },
      { label: 'Serving', data: $deptServing, backgroundColor: '#0f766e', borderRadius: 6, barPercentage: .7, categoryPercentage: .6 } ] },
    options: { responsive: true, maintainAspectRatio: true, plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, usePointStyle: true, pointStyle: 'circle' } } },
      scales: { y: { beginAtZero: true, ticks: { precision: 0 }, grid: { color: '#f1f5f9' }, border: { display: false } }, x: { grid: { display: false }, border: { display: false }, ticks: { maxRotation: 30, autoSkip: false, callback: function(v){ const l = this.getLabelForValue(v); return l.length > 18 ? l.slice(0, 17) + '…' : l; } } } } } });
  if (location.hash === '#alerts') document.getElementById('alerts').scrollIntoView({ behavior: 'smooth' });
})();
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
