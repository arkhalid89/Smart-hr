<?php
/**
 * Area-wise employee report — who is posted where.
 *
 * The "area" is the posting office recorded on the employee's current posting.
 * Where an office has not been recorded, the employee is grouped under their
 * own department so nobody is left out of the count. Every row also carries
 * the tehsil and district of the department, and the district / tehsil /
 * department picker narrows the report to one area of the province.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_report.php';

$areaFilter = get_int('location_id', 0);
$mode       = (string)get('mode', 'serving');

report_page([
    'key' => 'area_wise', 'title' => 'Area-wise Employees', 'icon' => 'bi-geo-alt',
    'description' => 'Employees grouped by the office / area they are currently posted to.',

    'filters' => function () use ($areaFilter, $mode) {
        [$lw, $lp] = location_scope('l');
        [$gw, $gp] = geo_filter_sql($_GET, 'l');
        $locs = db_all("SELECT l.id, l.name, d.name AS dept FROM posting_locations l
                          LEFT JOIN departments d ON d.id = l.department_id
                         WHERE l.is_deleted = 0 AND $lw AND $gw ORDER BY l.sort_order, l.name", array_merge($lp, $gp));
        echo '<div class="col-md-3"><label class="form-label mb-1">Area / Office</label>'
           . '<select name="location_id" class="form-select form-select-sm select2" data-allow-clear="1" data-placeholder="All areas">'
           . '<option value="">All areas</option>';
        foreach ($locs as $l) {
            echo '<option value="' . (int)$l['id'] . '"' . ($areaFilter === (int)$l['id'] ? ' selected' : '') . '>'
               . e($l['name']) . ($l['dept'] ? ' — ' . e($l['dept']) : '') . '</option>';
        }
        echo '</select></div>';
        echo '<div class="col-md-2"><label class="form-label mb-1">Include</label><select name="mode" class="form-select form-select-sm">'
           . '<option value="serving"' . ($mode === 'serving' ? ' selected' : '') . '>Serving only</option>'
           . '<option value="all"' . ($mode === 'all' ? ' selected' : '') . '>All (incl. separated)</option></select></div>';
    },

    'query' => function () use ($areaFilter, $mode) {
        [$w, $p] = report_scope();
        if ($mode === 'serving') {
            $w .= ' AND e.status NOT IN (' . implode(',', array_fill(0, count(EXITED_STATUSES), '?')) . ')';
            $p = array_merge($p, EXITED_STATUSES);
        }
        if ($areaFilter) { $w .= ' AND l.id = ?'; $p[] = $areaFilter; }

        return [report_base_select()
            . ', l.id AS location_id, l.name AS area, l.address AS area_address,
                 ld.name AS area_dept, pt.name AS tehsil, pd.name AS district, p.effective_date AS posted_since, p.reporting_officer, p.posting_type,
                 COALESCE(l.name, CONCAT(d.name, " (no office recorded)")) AS area_group '
            . report_base_from()
            . ' LEFT JOIN postings p ON p.id = e.current_posting_id AND p.is_voided = 0
                LEFT JOIN posting_locations l ON l.id = p.location_id
                LEFT JOIN departments ld ON ld.id = l.department_id
                LEFT JOIN tehsils pt ON pt.id = d.tehsil_id
                LEFT JOIN districts pd ON pd.id = d.district_id
               WHERE ' . $w . '
               ORDER BY pd.name, (pt.name IS NOT NULL), pt.name, (l.name IS NULL), l.name, d.name, e.name', $p];
    },

    'summary' => function (array $rows) {
        if (!$rows) return '';
        $areas = [];
        foreach ($rows as $r) {
            $k = $r['area_group'] ?: 'Not recorded';
            $areas[$k] = ($areas[$k] ?? 0) + 1;
        }
        arsort($areas);
        $h = '<div class="d-flex flex-wrap gap-2 align-items-center">'
           . '<span class="small text-muted me-1">' . count($rows) . ' employees across ' . count($areas) . ' areas:</span>';
        foreach ($areas as $name => $n) {
            $h .= '<span class="badge rounded-pill bg-primary-subtle text-primary-emphasis border border-primary-subtle">'
                . e($name) . ' <strong>' . $n . '</strong></span>';
        }
        return $h . '</div>';
    },

    'columns' => [
        'Area / Office' => fn($r) => $r['area']
            ? '<span class="fw-semibold">' . e($r['area']) . '</span>'
              . ($r['area_dept'] ? '<br><span class="small text-muted">' . e($r['area_dept']) . '</span>' : '')
              . ($r['area_address'] ? '<br><span class="small text-muted">' . e($r['area_address']) . '</span>' : '')
            : '<span class="text-muted">No office recorded</span>',
        'Tehsil / District' => fn($r) => e($r['tehsil'] ?: 'District HQ') . '<br><span class="small text-muted">' . e($r['district'] ?: '') . '</span>',
        'Employee'    => fn($r) => report_emp_cell($r),
        'Designation' => fn($r) => e($r['designation']) . ($r['pay_scale'] ? ' <span class="small text-muted">(' . e($r['pay_scale']) . ')</span>' : ''),
        'Department'  => fn($r) => e($r['department']),
        'Posted Since' => fn($r) => $r['posted_since']
            ? fmt_date($r['posted_since']) . '<br><span class="small text-muted">' . number_format(days_between($r['posted_since'], today())) . ' days</span>'
            : '<span class="text-muted">—</span>',
        'Reporting Officer' => fn($r) => e($r['reporting_officer'] ?: '-'),
        'Status' => fn($r) => status_badge($r['status']),
    ],

    'export' => [
        'headers' => ['Area / Office', 'Area Department', 'Address', 'Tehsil', 'District', 'Employee', 'Code', 'CNIC', 'Designation', 'Pay Scale', 'Department', 'Posting Type', 'Posted Since', 'Days at Post', 'Reporting Officer', 'Contact', 'Status'],
        'row' => fn($r) => [
            $r['area'] ?: 'No office recorded', $r['area_dept'], $r['area_address'], $r['tehsil'] ?: 'District HQ', $r['district'],
            $r['name'], $r['employee_code'], $r['cnic'], $r['designation'], $r['pay_scale'], $r['department'],
            $r['posting_type'], $r['posted_since'],
            $r['posted_since'] ? days_between($r['posted_since'], today()) : '',
            $r['reporting_officer'], $r['contact'], $r['status'],
        ],
    ],
]);
