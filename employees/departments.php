<?php
/**
 * Departments — a read-only directory of the departments the user reaches,
 * with their level, place, head of office and staffing position.
 *
 * Officers and report viewers see what their department's level reaches
 * (the district, the tehsil, or only their own department); the Super Admin
 * sees the province. Departments are maintained in Master Data.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();

$g = $_GET;
[$scope, $params] = department_scope('d');
$where = ['d.is_deleted = 0', $scope];

/* the district / tehsil / department picker, applied to departments directly */
if (!empty($g['department_id'])) {
    $where[] = 'd.id = ?'; $params[] = (int)$g['department_id'];
} else {
    if (!empty($g['district_id'])) { $where[] = 'd.district_id = ?'; $params[] = (int)$g['district_id']; }
    if (isset($g['tehsil_id']) && $g['tehsil_id'] !== '') {
        if ((string)$g['tehsil_id'] === '0') $where[] = 'd.tehsil_id IS NULL';
        else { $where[] = 'd.tehsil_id = ?'; $params[] = (int)$g['tehsil_id']; }
    }
}
if (!empty($g['level']) && in_array($g['level'], ['district', 'tehsil', 'department'], true)) { $where[] = 'd.level = ?'; $params[] = $g['level']; }

/* the province holds over a thousand departments: without a district chosen,
   the Super Admin starts with the staffed ones only */
$staffedOnly = isset($g['staffed']) ? $g['staffed'] === '1' : (has_full_scope() && empty($g['district_id']) && empty($g['department_id']));

$servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
$sql = "SELECT d.id, d.name, d.code, d.level, d.head_designation, d.address, d.latitude, d.is_active,
               ds.name AS district, t.name AS tehsil,
               (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_deleted = 0 AND e.status IN ($servingIn)) AS serving,
               (SELECT COUNT(*) FROM employees e WHERE e.department_id = d.id AND e.is_deleted = 0) AS on_register,
               (SELECT COALESCE(SUM(sp.sanctioned_count), 0) FROM sanctioned_posts sp WHERE sp.department_id = d.id AND sp.is_deleted = 0 AND sp.is_active = 1) AS sanctioned,
               (SELECT COUNT(*) FROM posting_locations l WHERE l.department_id = d.id AND l.is_deleted = 0) AS offices
          FROM departments d
          JOIN districts ds ON ds.id = d.district_id
          LEFT JOIN tehsils t ON t.id = d.tehsil_id
         WHERE " . implode(' AND ', $where) . ($staffedOnly ? ' HAVING on_register > 0 OR sanctioned > 0' : '') . "
         ORDER BY ds.name, (d.tehsil_id IS NOT NULL), t.name, FIELD(d.level, 'district', 'tehsil', 'department'), d.sort_order, d.name";
$rows = db_all($sql, $params);

if (get('export')) {
    require_once ROOT_PATH . '/includes/export.php';
    $out = [];
    foreach ($rows as $i => $r) {
        $out[] = [$i + 1, $r['name'], $r['code'], level_label($r['level']), $r['tehsil'] ?: 'District HQ', $r['district'], $r['head_designation'],
                  (int)$r['serving'], (int)$r['on_register'], (int)$r['sanctioned'], max(0, (int)$r['sanctioned'] - (int)$r['serving']), (int)$r['offices'], $r['address']];
    }
    log_activity('EXPORT', 'departments', null, null, ['rows' => count($out)], 'Department directory exported');
    export_table('departments', ['#', 'Department', 'Code', 'Level', 'Tehsil', 'District', 'Head of Office', 'Serving', 'On Register', 'Sanctioned', 'Vacant', 'Offices', 'Address'],
        $out, setting('org_name', ORG_NAME) . ' — Departments (' . fmt_date(today()) . ')');
}

$tot = ['serving' => 0, 'sanctioned' => 0, 'levels' => ['district' => 0, 'tehsil' => 0, 'department' => 0]];
foreach ($rows as $r) {
    $tot['serving'] += (int)$r['serving'];
    $tot['sanctioned'] += (int)$r['sanctioned'];
    $tot['levels'][$r['level']]++;
}
$qs = fn(array $o = []) => 'employees/departments.php?' . http_build_query(array_filter(array_merge($g, $o), fn($v) => $v !== '' && $v !== null));

$page_title    = 'Departments';
$page_subtitle = jurisdiction_label() . ' · read-only directory';
$active_menu   = 'departments';
$breadcrumbs   = ['Departments' => null];
$page_actions  = '<div class="btn-group btn-group-sm"><a href="' . e(url($qs(['export' => 1, 'format' => 'xlsx']))) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<a href="' . e(url($qs(['export' => 1, 'format' => 'csv']))) . '" class="btn btn-outline-success">CSV</a></div>'
    . (is_super_admin() ? '<a href="' . url('masters/index.php?type=departments') . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-pencil me-1"></i>Manage in Master Data</a>' : '');
require ROOT_PATH . '/includes/header.php';
?>
<form method="get" class="filter-bar mb-3"><div class="row g-2 align-items-end">
    <div class="col-lg-7"><?= geo_picker(['mode' => 'filter', 'values' => $g, 'size' => 'sm', 'id' => 'dirgeo']) ?></div>
    <div class="col-lg-2 col-md-4"><label class="form-label mb-1">Level</label>
        <select name="level" class="form-select form-select-sm">
            <option value="">All levels</option>
            <?php foreach (['district', 'tehsil', 'department'] as $l): ?><option value="<?= $l ?>" <?= ($g['level'] ?? '') === $l ? 'selected' : '' ?>><?= e(level_label($l)) ?></option><?php endforeach; ?>
        </select></div>
    <div class="col-lg-3 col-md-8 d-flex gap-2 align-items-center">
        <input type="hidden" name="staffed" value="0">
        <label class="chip-check mb-0"><input type="checkbox" name="staffed" value="1" <?= $staffedOnly ? 'checked' : '' ?>><span>With staff only</span></label>
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
        <a href="<?= url('employees/departments.php') ?>" class="btn btn-sm btn-light">Reset</a>
    </div>
</div></form>

<div class="row g-3 mb-3">
    <?php foreach ([
        ['Departments', count($rows), 'bi-building', 'primary', $tot['levels']['district'] . ' district · ' . $tot['levels']['tehsil'] . ' tehsil · ' . $tot['levels']['department'] . ' department level'],
        ['Serving staff', $tot['serving'], 'bi-person-check', 'success', 'in the departments listed'],
        ['Sanctioned posts', $tot['sanctioned'], 'bi-clipboard-data', 'info', 'sanctioned strength'],
        ['Vacant', max(0, $tot['sanctioned'] - $tot['serving']), 'bi-door-open', 'warning', 'sanctioned minus serving'],
    ] as [$label, $n, $icon, $c, $hint]): ?>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="stat-icon bg-soft-<?= $c ?>"><i class="bi <?= $icon ?>"></i></div>
        <div><div class="stat-value"><?= number_format($n) ?></div><div class="stat-label"><?= e($label) ?><br><span class="text-muted"><?= e($hint) ?></span></div></div></div></div>
    <?php endforeach; ?>
</div>

<div class="card">
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 datatable">
        <thead><tr><th style="width:40px">#</th><th>Department</th><th>Level</th><th>Tehsil / District</th><th>Head of Office</th>
            <th class="text-end">Serving</th><th class="text-end">Sanctioned</th><th class="text-end">Vacant</th><th class="text-end">Offices</th><th class="text-end no-sort">Staff</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i => $r): $vac = (int)$r['sanctioned'] - (int)$r['serving']; ?>
            <tr class="<?= $r['is_active'] ? '' : 'table-secondary' ?>">
                <td class="text-muted"><?= $i + 1 ?></td>
                <td><div class="fw-semibold"><?= e($r['name']) ?></div><div class="small text-muted"><code><?= e($r['code']) ?></code><?= $r['address'] ? ' · ' . e($r['address']) : '' ?><?= $r['is_active'] ? '' : ' · <span class="text-danger">inactive</span>' ?></div></td>
                <td><span class="badge <?= $r['level'] === 'department' ? 'bg-secondary-subtle text-secondary-emphasis' : 'bg-success-subtle text-success-emphasis' ?> border"><?= e(level_label($r['level'])) ?></span></td>
                <td class="small"><?= e($r['tehsil'] ?: 'District headquarters') ?><br><span class="text-muted">District <?= e($r['district']) ?></span></td>
                <td class="small"><?= e($r['head_designation'] ?: '—') ?></td>
                <td class="text-end"><?= (int)$r['serving'] ?></td>
                <td class="text-end"><?= (int)$r['sanctioned'] ?: '<span class="text-muted">—</span>' ?></td>
                <td class="text-end <?= $vac > 0 ? 'text-danger fw-semibold' : '' ?>"><?= (int)$r['sanctioned'] ? $vac : '<span class="text-muted">—</span>' ?></td>
                <td class="text-end"><?= (int)$r['offices'] ?></td>
                <td class="text-end text-nowrap">
                    <?php if ((int)$r['on_register']): ?><a href="<?= url('employees/index.php?department_id=' . (int)$r['id']) ?>" class="btn btn-sm btn-light" title="Employees of this department"><i class="bi bi-people me-1"></i><?= (int)$r['on_register'] ?></a><?php else: ?><span class="text-muted small">none</span><?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if (!$rows): ?><div class="empty-state p-4 text-center text-muted">No departments for the selected filters<?= $staffedOnly ? ' — untick <em>With staff only</em> to see unstaffed ones' : '' ?>.</div><?php endif; ?>
</div>
<?php require ROOT_PATH . '/includes/footer.php';
