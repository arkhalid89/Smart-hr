<?php
/**
 * Posting Map — every employee plotted at the office they are currently posted to.
 *
 * Officers only. Each officer sees the employees their department reaches —
 * a district-level office the district, a tehsil-level office the tehsil,
 * anything else only itself — exactly as everywhere else in the system.
 * Coordinates are held only by existing master records — a posting location
 * (office) or the department it belongs to. For each employee the map falls
 * back in that order: the office's own coordinates, then the coordinates of
 * the department the office belongs to, then the employee's own department.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_officer();

[$scope, $scopeParams] = emp_scope('e');

$focusEmp   = get_int('employee', 0);          // opened from an employee profile
$geo        = ['district_id' => get('district_id', ''), 'tehsil_id' => get('tehsil_id', ''), 'department_id' => get('department_id', '')];
$statusF    = (string)get('status', 'serving');

$where  = [$scope, 'e.is_deleted = 0'];
$params = $scopeParams;
[$gw, $gp] = geo_filter_sql($geo, 'e');
$where[] = $gw; $params = array_merge($params, $gp);
if ($statusF === 'serving') {
    $where[] = 'e.status NOT IN (' . implode(',', array_fill(0, count(EXITED_STATUSES), '?')) . ')';
    $params = array_merge($params, EXITED_STATUSES);
}
$w = implode(' AND ', $where);

/* Employees with their current posting location. */
$rows = db_all(
    "SELECT e.id, e.name, e.employee_code, e.photo, e.status, e.contact,
            g.name AS designation, ps.name AS pay_scale, d.name AS department,
            l.id AS location_id, l.name AS location, l.address, l.latitude, l.longitude,
            ld.id AS loc_dept_id, ld.name AS loc_dept, ld.latitude AS loc_dept_lat, ld.longitude AS loc_dept_lng,
            d.id AS emp_dept_id, d.latitude AS emp_dept_lat, d.longitude AS emp_dept_lng,
            p.effective_date, p.reporting_officer
       FROM employees e
       LEFT JOIN designations g  ON g.id = e.designation_id
       LEFT JOIN pay_scales  ps  ON ps.id = e.pay_scale_id
       LEFT JOIN departments d   ON d.id = e.department_id
       LEFT JOIN postings p      ON p.id = e.current_posting_id AND p.is_voided = 0
       LEFT JOIN posting_locations l ON l.id = p.location_id
       LEFT JOIN departments ld  ON ld.id = l.department_id
      WHERE $w
      ORDER BY l.name, e.name",
    $params
);

/* Group by map point. A point is the posting office when the office itself has
   coordinates, otherwise the department that stands in for it. */
$points   = [];    // key => marker
$unmapped = [];    // employees that cannot be placed at all
$noCoords = [];    // offices in use whose own coordinates are missing

foreach ($rows as $r) {
    /* resolution chain: office -> office's department -> employee's department */
    if ($r['location_id'] && $r['latitude'] !== null && $r['longitude'] !== null) {
        $key  = 'L' . (int)$r['location_id'];
        $meta = ['kind' => 'office', 'ref_id' => (int)$r['location_id'], 'name' => $r['location'],
                 'address' => $r['address'], 'via' => '', 'dept' => $r['loc_dept'] ?: null,
                 'lat' => (float)$r['latitude'], 'lng' => (float)$r['longitude']];
    } elseif ($r['location_id'] && $r['loc_dept_lat'] !== null) {
        $key  = 'D' . (int)$r['loc_dept_id'];
        $meta = ['kind' => 'department', 'ref_id' => (int)$r['loc_dept_id'], 'name' => $r['loc_dept'],
                 'address' => $r['address'], 'via' => 'Shown at the department location - "' . $r['location'] . '" has no coordinates of its own',
                 'dept' => $r['loc_dept'], 'lat' => (float)$r['loc_dept_lat'], 'lng' => (float)$r['loc_dept_lng']];
        $noCoords[(int)$r['location_id']] = $r['location'];
    } elseif ($r['emp_dept_lat'] !== null) {
        $key  = 'D' . (int)$r['emp_dept_id'];
        $meta = ['kind' => 'department', 'ref_id' => (int)$r['emp_dept_id'], 'name' => $r['department'],
                 'address' => '', 'dept' => $r['department'],
                 'via' => $r['location_id']
                     ? 'Shown at the department location - "' . $r['location'] . '" has no coordinates of its own'
                     : 'Shown at the department location - no posting office recorded',
                 'lat' => (float)$r['emp_dept_lat'], 'lng' => (float)$r['emp_dept_lng']];
        if ($r['location_id']) $noCoords[(int)$r['location_id']] = $r['location'];
    } else {
        $unmapped[] = $r;
        if ($r['location_id']) $noCoords[(int)$r['location_id']] = $r['location'];
        continue;
    }

    if (!isset($points[$key])) {
        $points[$key] = $meta + ['id' => $key, 'employees' => [], 'departments' => []];
    }
    $points[$key]['employees'][] = [
        'id' => (int)$r['id'], 'name' => $r['name'], 'code' => $r['employee_code'],
        'designation' => $r['designation'], 'scale' => $r['pay_scale'], 'department' => $r['department'],
        'status' => $r['status'], 'since' => $r['effective_date'] ? fmt_date($r['effective_date']) : '',
        'office' => $r['location'] ?: '',
        'photo' => photo_url($r['photo'], (int)$r['id']),
        'url' => url('employees/view.php?id=' . (int)$r['id']),
    ];
    $points[$key]['departments'][$r['department']] = ($points[$key]['departments'][$r['department']] ?? 0) + 1;
}
foreach ($points as $k => $p) { $points[$k]['count'] = count($p['employees']); }
uasort($points, fn($a, $b) => $b['count'] <=> $a['count']);

/* Locations with coordinates but nobody posted there — shown as empty offices. */
[$lw, $lp] = location_scope('l');
[$lgw, $lgp] = geo_filter_sql($geo, 'l');
$emptyLocs = db_all("SELECT l.id, l.name, l.address, l.latitude, l.longitude, d.name AS dept
                       FROM posting_locations l LEFT JOIN departments d ON d.id = l.department_id
                      WHERE l.is_deleted = 0 AND l.is_active = 1 AND l.latitude IS NOT NULL AND l.longitude IS NOT NULL AND $lw AND $lgw", array_merge($lp, $lgp));
foreach ($emptyLocs as $l) {
    $key = 'L' . (int)$l['id'];
    if (isset($points[$key])) continue;
    $points[$key] = ['id' => $key, 'kind' => 'office', 'ref_id' => (int)$l['id'], 'name' => $l['name'],
                     'address' => $l['address'], 'via' => '', 'dept' => $l['dept'],
                     'lat' => (float)$l['latitude'], 'lng' => (float)$l['longitude'],
                     'employees' => [], 'departments' => [], 'count' => 0];
}

/* An office only counts as missing when neither it nor its department has coordinates. */
$missingCoordCount = (int)db_value(
    "SELECT COUNT(*) FROM posting_locations l LEFT JOIN departments d ON d.id = l.department_id
      WHERE l.is_deleted = 0 AND l.is_active = 1 AND (l.latitude IS NULL OR l.longitude IS NULL)
        AND (d.id IS NULL OR d.latitude IS NULL OR d.longitude IS NULL) AND $lw AND $lgw", array_merge($lp, $lgp), 0);
$viaDeptCount = count($noCoords);

$totalPlaced = array_sum(array_column($points, 'count'));

/* When the map was opened from a profile, find that employee's point so the
   map can open straight on it. */
$focusKey = null; $focusName = '';
if ($focusEmp) {
    foreach ($points as $k => $pt) {
        foreach ($pt['employees'] as $emp) {
            if ($emp['id'] === $focusEmp) { $focusKey = $k; $focusName = $emp['name']; break 2; }
        }
    }
    if ($focusKey === null) {
        foreach ($unmapped as $u) if ((int)$u['id'] === $focusEmp) $focusName = $u['name'];
    }
}

$page_title  = 'Posting Map';
$page_subtitle = $totalPlaced . ' of ' . count($rows) . ' employees plotted at ' . count(array_filter($points, fn($p) => $p['count'] > 0)) . ' offices';
$active_menu = 'map';
$breadcrumbs = ['Employees' => 'employees/index.php', 'Posting Map' => null];
$page_actions = '<div class="btn-group btn-group-sm">'
    . '<a href="' . e(url('masters/index.php?type=departments')) . '" class="btn btn-outline-secondary"><i class="bi bi-building me-1"></i>Departments</a>'
    . '<a href="' . e(url('masters/index.php?type=posting_locations')) . '" class="btn btn-outline-secondary"><i class="bi bi-geo-alt me-1"></i>Offices</a></div>';

$extra_css = '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">'
    . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.min.css">'
    . '<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/MarkerCluster.Default.min.css">';

require ROOT_PATH . '/includes/header.php';
?>
<form method="get" class="filter-bar mb-3"><div class="row g-2 align-items-end">
    <div class="col-lg-7"><?= geo_picker(['mode' => 'filter', 'values' => $geo, 'size' => 'sm', 'id' => 'mapgeo']) ?></div>
    <div class="col-lg-3 col-md-6"><label class="form-label mb-1">Include</label>
        <select name="status" class="form-select form-select-sm">
            <option value="serving" <?= $statusF === 'serving' ? 'selected' : '' ?>>Serving employees only</option>
            <option value="all" <?= $statusF === 'all' ? 'selected' : '' ?>>All (including retired / separated)</option>
        </select></div>
    <div class="col-lg-2 col-md-6 d-flex gap-2">
        <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
        <a href="<?= url('employees/map.php') ?>" class="btn btn-sm btn-light">Reset</a>
    </div>
</div></form>

<?php if ($focusEmp && $focusName): ?>
<div class="alert alert-primary d-flex align-items-center gap-2 py-2">
    <i class="bi bi-person-circle"></i>
    <div class="flex-grow-1">Showing <strong><?= e($focusName) ?></strong> on the map.
        <?= $focusKey === null ? 'This employee could not be placed — see the table below for the reason.' : '' ?></div>
    <a href="<?= url('employees/map.php') ?>" class="btn btn-sm btn-light">Show everyone</a>
</div>
<?php endif; ?>

<?php if ($viaDeptCount): ?>
<div class="alert alert-info d-flex align-items-start gap-2 py-2">
    <i class="bi bi-diagram-3 mt-1"></i>
    <div><strong><?= $viaDeptCount ?> office<?= $viaDeptCount === 1 ? '' : 's' ?> plotted at the department location</strong> —
        <?= e(implode(', ', array_slice($noCoords, 0, 4))) ?><?= $viaDeptCount > 4 ? ' and ' . ($viaDeptCount - 4) . ' more' : '' ?>
        <?= $viaDeptCount === 1 ? 'has' : 'have' ?> no coordinates of their own, so the coordinates of the department they belong to are used.
        Give each office its own coordinates in <a href="<?= url('masters/index.php?type=posting_locations') ?>" class="alert-link">Master Data &rarr; Offices &amp; Sites</a> for exact pins.</div>
</div>
<?php endif; ?>

<?php if ($missingCoordCount): ?>
<div class="alert alert-warning d-flex align-items-start gap-2 py-2">
    <i class="bi bi-geo-alt mt-1"></i>
    <div><strong><?= $missingCoordCount ?> posting location<?= $missingCoordCount === 1 ? ' has' : 's have' ?> no coordinates, and neither does their department</strong> — employees posted there cannot be placed.
        Coordinates belong to an existing office or an existing department: set them in
        <a href="<?= url('masters/index.php?type=posting_locations') ?>" class="alert-link">Offices &amp; Sites</a> or
        <a href="<?= url('masters/index.php?type=departments') ?>" class="alert-link">Departments</a>
        (in Google Maps, right-click the office and the first line of the menu is the pair of numbers to copy).</div>
</div>
<?php endif; ?>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="card">
            <div class="card-header">
                <span class="card-title"><i class="bi bi-map me-1 text-primary"></i>Posting Locations</span>
                <span class="small text-muted"><?= $totalPlaced ?> employees plotted</span>
            </div>
            <div class="card-body p-0">
                <div id="postingMap" style="height:620px;max-height:70vh;min-height:420px;width:100%;border-radius:0 0 var(--radius-lg) var(--radius-lg)"></div>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Offices</span><span class="small text-muted"><?= count($points) ?></span></div>
            <div class="list-group list-group-flush map-office-list" style="max-height:620px;min-height:420px;overflow-y:auto">
                <?php foreach ($points as $p): ?>
                <a href="#" class="list-group-item list-group-item-action d-flex align-items-start gap-2 py-2<?= $focusKey === $p['id'] ? ' active-loc' : '' ?>" data-loc="<?= e($p['id']) ?>">
                    <i class="bi <?= $p['kind'] === 'department' ? 'bi-building-fill text-secondary' : 'bi-geo-alt-fill text-primary' ?> mt-1"></i>
                    <div class="flex-grow-1 min-w-0">
                        <div class="fw-semibold small text-truncate"><?= e($p['name']) ?></div>
                        <div class="text-muted" style="font-size:11.5px">
                            <?= e($p['address'] ?: ($p['kind'] === 'department' ? 'Department location' : '—')) ?>
                            <?php if ($p['kind'] === 'office' && !empty($p['dept'])): ?><br><i class="bi bi-diagram-3"></i> <?= e($p['dept']) ?><?php endif; ?>
                        </div>
                    </div>
                    <span class="badge rounded-pill <?= $p['count'] ? 'text-bg-primary' : 'text-bg-light border' ?>"><?= (int)$p['count'] ?></span>
                </a>
                <?php endforeach; ?>
                <?php if (!$points): ?><div class="empty-state p-4">No posting location has coordinates yet.</div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if ($unmapped): ?>
<div class="card mt-3">
    <div class="card-header">
        <span class="card-title"><i class="bi bi-pin-map me-1 text-warning"></i>Not on the map</span>
        <span class="badge rounded-pill text-bg-light border"><?= count($unmapped) ?></span>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>#</th><th>Employee</th><th>Designation</th><th>Department</th><th>Current Posting</th><th>Why not shown</th></tr></thead>
            <tbody>
            <?php foreach ($unmapped as $i => $r): ?>
                <tr>
                    <td class="text-muted small"><?= $i + 1 ?></td>
                    <td><a href="<?= url('employees/view.php?id=' . (int)$r['id']) ?>" class="text-reset text-decoration-none">
                        <span class="fw-semibold"><?= e($r['name']) ?></span><br><span class="small text-muted"><?= e($r['employee_code']) ?></span></a></td>
                    <td class="small"><?= e($r['designation'] ?: '-') ?></td>
                    <td class="small"><?= e($r['department'] ?: '-') ?></td>
                    <td class="small"><?= e($r['location'] ?: '—') ?></td>
                    <td class="small text-muted">
                        <?php if (!$r['location_id']): ?>No posting office recorded, and <?= e($r['department'] ?: 'the department') ?> has no coordinates
                        <?php else: ?>Neither &ldquo;<?= e($r['location']) ?>&rdquo; nor its department has coordinates<?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php
$mapData = [
    'points' => array_values($points),
    'focus'  => $focusKey,
    'focusEmployee' => $focusEmp ?: null,
    'center' => [(float)setting('map_default_lat', 31.4492), (float)setting('map_default_lng', 73.7089)],
    'zoom'   => (int)setting('map_default_zoom', 10),
];
$extra_js = '<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>'
    . '<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.markercluster/1.5.3/leaflet.markercluster.min.js"></script>'
    . '<script>window.HR_MAP = ' . json_encode($mapData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . ';</script>'
    . '<script src="' . asset('js/map.js') . '"></script>';
require ROOT_PATH . '/includes/footer.php';
