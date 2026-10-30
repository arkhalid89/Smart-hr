<?php
/**
 * The District -> Tehsil -> Department picker.
 *
 * Everywhere a department is chosen, it is chosen in that order: a district,
 * then a tehsil of THAT district (or "District headquarters" for offices that
 * sit at district level), then a department of that tehsil. The lists only
 * contain what the signed-in user reaches.
 *
 *   echo geo_picker(['name' => 'department_id', 'value' => $deptId, 'required' => true]);
 *   echo geo_picker(['mode' => 'filter', 'values' => $_GET]);            // optional filters
 *
 * The data is emitted once per page as window.HR_GEO; assets/js/app.js does
 * the cascading.
 */
declare(strict_types=1);

/** Districts, tehsils and departments within the user's reach. */
function geo_dataset(): array
{
    [$w, $p] = department_scope('d');
    $departments = db_all(
        "SELECT d.id, d.name, d.level, d.district_id, d.tehsil_id
           FROM departments d
          WHERE d.is_deleted = 0 AND d.is_active = 1 AND $w
          ORDER BY d.district_id, (d.tehsil_id IS NOT NULL), d.tehsil_id, FIELD(d.level, 'district', 'tehsil', 'department'), d.sort_order, d.name", $p);

    $districtIds = array_values(array_unique(array_map(fn($d) => (int)$d['district_id'], $departments)));
    $tehsilIds   = array_values(array_unique(array_filter(array_map(fn($d) => $d['tehsil_id'] !== null ? (int)$d['tehsil_id'] : null, $departments))));

    $districts = $districtIds ? db_all('SELECT id, name, division FROM districts WHERE id IN (' . db_in($districtIds) . ') AND is_deleted = 0 ORDER BY division, name', $districtIds) : [];
    $tehsils   = $tehsilIds   ? db_all('SELECT id, district_id, name FROM tehsils WHERE id IN (' . db_in($tehsilIds) . ') AND is_deleted = 0 ORDER BY sort_order, name', $tehsilIds) : [];

    $int = fn(array $rows, array $keys) => array_map(function ($r) use ($keys) {
        foreach ($keys as $k) $r[$k] = $r[$k] === null ? null : (int)$r[$k];
        return $r;
    }, $rows);

    return [
        'districts'   => $int($districts, ['id']),
        'tehsils'     => $int($tehsils, ['id', 'district_id']),
        'departments' => $int($departments, ['id', 'district_id', 'tehsil_id']),
    ];
}

/** The <script> carrying the dataset — printed once per page. */
function geo_data_script(): string
{
    static $done = false;
    if ($done) return '';
    $done = true;
    return '<script>window.HR_GEO = ' . json_encode(geo_dataset(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>';
}

/** District and tehsil of a department, for pre-selecting the picker. */
function geo_of_department(?int $departmentId): array
{
    if (!$departmentId) return [null, null];
    $d = db_row('SELECT district_id, tehsil_id FROM departments WHERE id = ?', [$departmentId]);
    return $d ? [(int)$d['district_id'], $d['tehsil_id'] !== null ? (int)$d['tehsil_id'] : null] : [null, null];
}

/**
 * Render the three linked selects.
 *
 *   mode      'form'   (default) — a department must be chosen
 *             'filter' — every level optional, submits district_id / tehsil_id / department_id
 *   name      department field name (form mode), default department_id
 *   value     pre-selected department id (form mode)
 *   values    array holding district_id / tehsil_id / department_id (filter mode)
 *   required  form mode: the department is required (default true)
 *   size      'sm' for compact filter bars
 *   col       Bootstrap column class for each select, default col-md-4
 *   labels    show labels (default true)
 *   id        a prefix for element ids
 *   emit      print the dataset script (default true; false when the page already has it)
 */
function geo_picker(array $o = []): string
{
    $mode     = $o['mode'] ?? 'form';
    $filter   = $mode === 'filter';
    $name     = $o['name'] ?? 'department_id';
    $required = $filter ? false : ($o['required'] ?? true);
    $col      = $o['col'] ?? 'col-md-4';
    $sm       = ($o['size'] ?? '') === 'sm' ? ' form-select-sm' : '';
    $labels   = $o['labels'] ?? true;
    $id       = preg_replace('/[^A-Za-z0-9_]/', '', (string)($o['id'] ?? 'geo' . substr(md5($name . microtime()), 0, 6)));

    if ($filter) {
        $v = $o['values'] ?? [];
        $deptId = !empty($v['department_id']) ? (int)$v['department_id'] : null;
        [$dDistrict, $dTehsil] = geo_of_department($deptId);
        $districtId = !empty($v['district_id']) ? (int)$v['district_id'] : $dDistrict;
        $tehsilSel  = isset($v['tehsil_id']) && $v['tehsil_id'] !== '' ? (string)$v['tehsil_id'] : ($deptId ? ($dTehsil === null ? '0' : (string)$dTehsil) : '');
    } else {
        $deptId = !empty($o['value']) ? (int)$o['value'] : null;
        [$districtId, $dTehsil] = geo_of_department($deptId);
        $tehsilSel = $deptId ? ($dTehsil === null ? '0' : (string)$dTehsil) : '';
    }

    $lab = fn(string $t, bool $req = false) => $labels
        ? '<label class="form-label' . ($filter ? ' mb-1' : '') . '" for="' . $id . '_' . strtolower(strtok($t, ' ')) . '">' . e($t) . ($req ? ' <span class="req">*</span>' : '') . '</label>'
        : '';

    $h  = ($o['emit'] ?? true) ? geo_data_script() : '';
    $h .= '<div class="row g-2 geo-picker" data-geo-picker data-mode="' . e($mode) . '"'
        . ' data-selected-district="' . e((string)($districtId ?? '')) . '"'
        . ' data-selected-tehsil="' . e($tehsilSel) . '"'
        . ' data-selected-department="' . e((string)($deptId ?? '')) . '">';
    $h .= '<div class="' . e($col) . '">' . $lab('District', $required)
        . '<select class="form-select' . $sm . '" id="' . $id . '_district" data-geo="district"' . ($filter ? ' name="district_id"' : '') . ($required ? ' required' : '') . '></select></div>';
    $h .= '<div class="' . e($col) . '">' . $lab('Tehsil', $required)
        . '<select class="form-select' . $sm . '" id="' . $id . '_tehsil" data-geo="tehsil"' . ($filter ? ' name="tehsil_id"' : '') . '></select></div>';
    $h .= '<div class="' . e($col) . '">' . $lab('Department', $required)
        . '<select class="form-select' . $sm . '" id="' . $id . '_department" data-geo="department" name="' . e($filter ? 'department_id' : $name) . '"' . ($required ? ' required' : '') . '></select></div>';
    $h .= '</div>';
    return $h;
}

/**
 * SQL narrowing an employees query by the picker's filter values
 * (district_id / tehsil_id / department_id, each optional).
 * tehsil_id = 0 means "district headquarters" (departments with no tehsil).
 */
function geo_filter_sql(array $g, string $alias = 'e'): array
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'e';
    if (!empty($g['department_id'])) return ["$a.department_id = ?", [(int)$g['department_id']]];
    $w = []; $p = [];
    if (!empty($g['district_id'])) { $w[] = 'gf.district_id = ?'; $p[] = (int)$g['district_id']; }
    if (isset($g['tehsil_id']) && $g['tehsil_id'] !== '') {
        if ((string)$g['tehsil_id'] === '0') $w[] = 'gf.tehsil_id IS NULL';
        else { $w[] = 'gf.tehsil_id = ?'; $p[] = (int)$g['tehsil_id']; }
    }
    if (!$w) return ['1=1', []];
    return ["$a.department_id IN (SELECT gf.id FROM departments gf WHERE " . implode(' AND ', $w) . ')', $p];
}
