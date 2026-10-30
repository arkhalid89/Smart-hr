<?php
/**
 * Generic AJAX handler for all master data types.
 *
 * GET  ?action=table&type=departments[&show=deleted]   -> HTML table
 * GET  ?action=form&type=departments[&id=5]            -> HTML form body
 * POST action=save|toggle|delete|restore|hard_delete   -> JSON
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/masters_config.php';
require_role('super_admin', 'officer');

$type   = (string)($_REQUEST['type'] ?? '');
$action = (string)($_REQUEST['action'] ?? '');
$cfg    = master_get($type);
if (!$cfg) json_error('Unknown or unauthorized master type.', 404);

$table  = db_ident($cfg['table']);
$fields = $cfg['fields'];

/* ------------------------------------------------------------------ helpers */
function mf_by_name(array $fields, string $name): ?array
{
    foreach ($fields as $f) if ($f['name'] === $name) return $f;
    return null;
}
function master_usage_count(array $cfg, int $id): int
{
    $n = 0;
    foreach ($cfg['usage'] as $t => $col) {
        $t = db_ident($t); $col = db_ident($col);
        $n += (int)db_value("SELECT COUNT(*) FROM `$t` WHERE `$col` = ?", [$id], 0);
    }
    return $n;
}
/** Lookup map id => name for select fields */
function master_lookup(string $source): array
{
    static $cache = [];
    if (!isset($cache[$source])) {
        $cache[$source] = [];
        foreach (db_all('SELECT id, name FROM `' . db_ident($source) . '`') as $r) $cache[$source][(int)$r['id']] = $r['name'];
    }
    return $cache[$source];
}
function master_display(array $f, mixed $v): string
{
    switch ($f['type']) {
        case 'checkbox': return $v ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-dash-circle text-muted"></i>';
        case 'select':   return e(master_lookup($f['source'])[(int)$v] ?? '-');
        case 'decimal':  return $v === null ? '-' : number_format((float)$v, 0);
        case 'coord':    return $v === null || $v === '' ? '<span class="text-muted">not set</span>' : number_format((float)$v, 4);
        case 'department': return e(master_lookup('departments')[(int)$v] ?? '-');
        default:         return e($v === null || $v === '' ? '-' : $v);
    }
}

/* ================================================================== TABLE */
if ($action === 'table') {
    $showDeleted = get('show') === 'deleted' && is_super_admin();
    $listFields  = array_values(array_filter($fields, fn($f) => !empty($f['list'])));
    $orderBy = $cfg['order'] ?? ($type === 'sanctioned_posts' ? 'department_id, designation_id' : 'sort_order, name');
    $where = 'is_deleted = ?'; $wp = [$showDeleted ? 1 : 0];
    if (!empty($cfg['geo_filter'])) {
        $did = get_int('district_id', 0) ?: (int)db_value('SELECT id FROM districts WHERE is_deleted = 0 ORDER BY division, name LIMIT 1');
        $where .= ' AND ' . $cfg['geo_filter']; $wp[] = $did;
    }
    $rows = db_all("SELECT * FROM `$table` WHERE $where ORDER BY $orderBy", $wp);
    $placeOf = [];
    if ($type === 'departments' && $rows) {
        foreach (db_all('SELECT d.id, ds.name AS district, t.name AS tehsil FROM departments d JOIN districts ds ON ds.id = d.district_id LEFT JOIN tehsils t ON t.id = d.tehsil_id WHERE d.id IN (' . db_in(array_column($rows, 'id')) . ')', array_map('intval', array_column($rows, 'id'))) as $pp) {
            $placeOf[(int)$pp['id']] = $pp;
        }
    }

    // computed columns for sanctioned posts
    $filled = [];
    if ($type === 'sanctioned_posts') {
        $servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
        foreach (db_all("SELECT department_id, designation_id, COUNT(*) c FROM employees WHERE is_deleted = 0 AND status IN ($servingIn) GROUP BY department_id, designation_id") as $r) {
            $filled[$r['department_id'] . '-' . $r['designation_id']] = (int)$r['c'];
        }
    }
    ob_start();
    ?>
    <div class="table-responsive">
    <table class="table table-hover align-middle datatable mb-0" data-page-length="25">
        <thead><tr>
            <th style="width:50px">#</th>
            <?php foreach ($listFields as $f): ?><th><?= e($f['label']) ?></th><?php endforeach; ?>
            <?php if (!empty($cfg['computed'])): foreach ($cfg['computed'] as $k => $l): ?><th><?= e($l) ?></th><?php endforeach; endif; ?>
            <?php if ($cfg['usage']): ?><th>In Use</th><?php endif; ?>
            <th>Status</th>
            <th class="text-end no-sort" style="width:150px">Actions</th>
        </tr></thead>
        <tbody>
        <?php if (!$rows): ?>
            <tr><td colspan="<?= count($listFields) + 5 ?>" class="text-center text-muted py-4">No records found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rows as $i => $r): $id = (int)$r['id']; $use = $cfg['usage'] ? master_usage_count($cfg, $id) : 0; ?>
            <tr data-id="<?= $id ?>">
                <td class="text-muted"><?= $i + 1 ?></td>
                <?php foreach ($listFields as $f): ?><td><?php
                    if ($f['type'] === 'place') {
                        $pl = $placeOf[$id] ?? null;
                        $badge = ['district' => 'text-bg-success', 'tehsil' => 'text-bg-primary', 'department' => 'text-bg-light border'][$r['level']] ?? 'text-bg-light border';
                        echo '<span class="badge ' . $badge . '">' . e(level_label($r['level'])) . '</span><br><span class="small text-muted">'
                           . e($pl ? ($pl['tehsil'] ? 'Tehsil ' . $pl['tehsil'] : 'District HQ') . ', ' . $pl['district'] : '') . '</span>';
                    } else {
                        echo master_display($f, $r[$f['name']] ?? null);
                    }
                ?></td><?php endforeach; ?>
                <?php if ($type === 'sanctioned_posts'):
                    $fc = $filled[$r['department_id'] . '-' . $r['designation_id']] ?? 0;
                    $vac = (int)$r['sanctioned_count'] - $fc; ?>
                    <td><?= $fc ?></td>
                    <td><span class="badge <?= $vac > 0 ? 'text-bg-warning' : ($vac < 0 ? 'text-bg-danger' : 'text-bg-success') ?>"><?= $vac ?></span></td>
                <?php endif; ?>
                <?php if ($cfg['usage']): ?><td><span class="badge text-bg-light border"><?= $use ?></span></td><?php endif; ?>
                <td>
                    <?php if ($showDeleted): ?><span class="badge text-bg-dark">Deleted</span>
                    <?php elseif ($r['is_active']): ?><span class="badge text-bg-success">Active</span>
                    <?php else: ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                </td>
                <td class="text-end table-actions">
                    <?php if ($showDeleted): ?>
                        <button class="btn btn-ghost btn-sm text-success" data-act="restore" data-id="<?= $id ?>" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button class="btn btn-ghost btn-sm text-danger" data-act="hard_delete" data-id="<?= $id ?>" title="Delete permanently" <?= $use ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button>
                    <?php else: ?>
                        <button class="btn btn-ghost btn-sm" data-act="edit" data-id="<?= $id ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                        <button class="btn btn-ghost btn-sm <?= $r["is_active"] ? "text-warning" : "text-success" ?>" data-act="toggle" data-id="<?= $id ?>" title="<?= $r['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $r['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i></button>
                        <?php if (is_super_admin()): ?>
                        <button class="btn btn-ghost btn-sm text-danger" data-act="delete" data-id="<?= $id ?>" title="Delete (move to recycle bin)"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php
    header('Content-Type: text/html; charset=utf-8');
    echo ob_get_clean();
    exit;
}

/* ================================================================== FORM */
if ($action === 'form') {
    $id  = get_int('id', 0);
    $row = $id ? db_find($table, $id) : null;
    if ($id && !$row) json_error('Record not found.', 404);
    ob_start();
    foreach ($fields as $f) {
        $name  = $f['name'];
        $val   = $row[$name] ?? ($f['default'] ?? '');
        $req   = !empty($f['required']);
        $label = e($f['label']) . ($req ? ' <span class="req">*</span>' : '');
        $help  = !empty($f['help']) ? '<div class="form-text">' . e($f['help']) . '</div>' : '';
        $col   = in_array($f['type'], ['textarea', 'place', 'department'], true) || $name === 'name' ? 'col-12' : 'col-md-6';
        echo '<div class="' . $col . '">';
        switch ($f['type']) {
            case 'textarea':
                echo '<label class="form-label">' . $label . '</label><textarea class="form-control" name="' . $name . '" rows="2" maxlength="255">' . e($val) . '</textarea>' . $help;
                break;
            case 'checkbox':
                echo '<div class="form-check mt-md-4 pt-md-2"><input class="form-check-input" type="checkbox" name="' . $name . '" id="f_' . $name . '" value="1"' . ($val ? ' checked' : '') . '>'
                   . '<label class="form-check-label" for="f_' . $name . '">' . e($f['label']) . '</label></div>' . $help;
                break;
            case 'select':
                $opts = master_options($f['source'], $val ? (int)$val : null);
                echo '<label class="form-label">' . $label . '</label><select class="form-select select2" name="' . $name . '" data-allow-clear="' . ($req ? '0' : '1') . '" data-placeholder="Select ' . e($f['label']) . '"' . ($req ? ' required' : '') . '>'
                   . '<option value="">' . ($req ? 'Select…' : '— None —') . '</option>' . options_html($opts, $val) . '</select>' . $help;
                break;
            case 'static':
                echo '<label class="form-label">' . $label . '</label><select class="form-select" name="' . $name . '"' . ($req ? ' required' : '') . '>' . options_html($f['options'], $val) . '</select>' . $help;
                break;
            case 'department':
                /* District -> Tehsil -> Department, the data is already on the page */
                echo geo_picker(['name' => $name, 'value' => $val ? (int)$val : null, 'required' => $req, 'emit' => false, 'id' => 'mf_' . $name]) . $help;
                break;
            case 'place':
                /* the level, then the district, then — for a tehsil-level office,
                   or a single-authority office inside a tehsil — the tehsil */
                $lv  = $row['level'] ?? 'department';
                $dId = (int)($row['district_id'] ?? 0);
                $tId = (int)($row['tehsil_id'] ?? 0);
                $dOpts = db_all('SELECT id, name, division FROM districts WHERE is_deleted = 0 AND (is_active = 1 OR id = ?) ORDER BY division, name', [$dId]);
                $tOpts = db_all('SELECT id, name, district_id FROM tehsils WHERE is_deleted = 0 AND (is_active = 1 OR id = ?) ORDER BY sort_order, name', [$tId]);
                echo '<div class="row g-2 place-field">';
                echo '<div class="col-md-4"><label class="form-label">Level <span class="req">*</span></label><select class="form-select" name="level" data-place="level">';
                foreach (['district' => 'District level — covers the whole district', 'tehsil' => 'Tehsil level — covers the whole tehsil', 'department' => 'Department level — a single authority'] as $k => $t) {
                    echo '<option value="' . $k . '"' . ($lv === $k ? ' selected' : '') . '>' . e($t) . '</option>';
                }
                echo '</select></div>';
                echo '<div class="col-md-4"><label class="form-label">District <span class="req">*</span></label><select class="form-select" name="district_id" data-place="district" required><option value="">Choose district…</option>';
                $dv = null;
                foreach ($dOpts as $d) {
                    if ($d['division'] !== $dv) { if ($dv !== null) echo '</optgroup>'; $dv = $d['division']; echo '<optgroup label="' . e($dv . ' Division') . '">'; }
                    echo '<option value="' . (int)$d['id'] . '"' . ((int)$d['id'] === $dId ? ' selected' : '') . '>' . e($d['name']) . '</option>';
                }
                if ($dv !== null) echo '</optgroup>';
                echo '</select></div>';
                echo '<div class="col-md-4" data-place-tehsil-wrap><label class="form-label">Tehsil <span class="req" data-place-tehsil-req>*</span></label><select class="form-select" name="tehsil_id" data-place="tehsil">';
                echo '<option value="">— District headquarters —</option>';
                foreach ($tOpts as $t) {
                    echo '<option value="' . (int)$t['id'] . '" data-district="' . (int)$t['district_id'] . '"' . ((int)$t['id'] === $tId ? ' selected' : '') . '>' . e($t['name']) . '</option>';
                }
                echo '</select></div></div>';
                echo '<div class="form-text">A district-level office covers every tehsil and department of its district; a tehsil-level office covers its tehsil; a department-level office is a single authority.</div>';
                echo $help;
                break;
            case 'number':
                echo '<label class="form-label">' . $label . '</label><input type="number" step="1" class="form-control" name="' . $name . '" value="' . e($val) . '"' . ($req ? ' required' : '') . '>' . $help;
                break;
            case 'decimal':
                echo '<label class="form-label">' . $label . '</label><input type="number" step="0.01" min="0" class="form-control" name="' . $name . '" value="' . e($val) . '"' . ($req ? ' required' : '') . '>' . $help;
                break;
            case 'coord':
                echo '<label class="form-label">' . $label . '</label><input type="number" step="any" min="-180" max="180" class="form-control" name="' . $name . '" value="' . e($val) . '"' . ($req ? ' required' : '') . '>' . $help;
                break;
            default:
                echo '<label class="form-label">' . $label . '</label><input type="text" class="form-control" name="' . $name . '" value="' . e($val) . '" maxlength="' . (int)($f['maxlength'] ?? 120) . '"' . ($req ? ' required' : '') . '>' . $help;
        }
        echo '</div>';
    }
    if ($row) {
        echo '<div class="col-12"><div class="form-check form-switch"><input class="form-check-input" type="checkbox" name="is_active" id="f_is_active" value="1"' . ($row['is_active'] ? ' checked' : '') . '><label class="form-check-label" for="f_is_active">Active</label></div></div>';
    }
    header('Content-Type: text/html; charset=utf-8');
    echo ob_get_clean();
    exit;
}

/* ================================================================== WRITE ACTIONS */
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request method.', 405);
csrf_verify();
$id = post_int('id', 0);

if ($action === 'save') {
    $data = [];
    $errors = [];
    foreach ($fields as $f) {
        $name = $f['name'];
        switch ($f['type']) {
            case 'checkbox': $v = post($name) ? 1 : 0; break;
            case 'number':   $v = post($name) === null || post($name) === '' ? null : (int)post($name); break;
            case 'decimal':  $v = post_decimal($name); break;
            case 'coord':    $v = post_decimal($name); break;
            case 'select':   $v = post_int($name); break;
            case 'department': $v = post_int($name); break;
            case 'place':    $v = null; break;          // handled below
            default:         $v = nullable(post($name));
        }
        if ($f['type'] === 'place') {
            $lv = (string)post('level', 'department');
            if (!in_array($lv, ['district', 'tehsil', 'department'], true)) $errors[] = 'Choose the level.';
            $did = post_int('district_id');
            $tid = $lv === 'district' ? null : post_int('tehsil_id');
            if (!$did || !db_value('SELECT id FROM districts WHERE id = ? AND is_deleted = 0', [$did])) $errors[] = 'Choose the district.';
            if ($lv === 'tehsil' && !$tid) $errors[] = 'A tehsil-level office needs its tehsil.';
            if ($tid && (int)db_value('SELECT district_id FROM tehsils WHERE id = ?', [$tid], 0) !== (int)$did) $errors[] = 'That tehsil is not in the chosen district.';
            if ($lv === 'district' && $did && db_value('SELECT id FROM departments WHERE district_id = ? AND level = "district" AND is_deleted = 0 AND id <> ?', [$did, $id])) {
                $errors[] = 'This district already has a district-level office.';
            }
            $data['level'] = $lv; $data['district_id'] = $did; $data['tehsil_id'] = $tid ?: null;
            continue;
        }
        if (!empty($f['required']) && ($v === null || $v === '')) {
            $errors[] = $f['label'] . ' is required.';
        }
        if ($f['type'] === 'number' && $v !== null && $v < 0) $errors[] = $f['label'] . ' cannot be negative.';
        if ($f['type'] === 'coord' && $v !== null) {
            $limit = $name === 'latitude' ? 90 : 180;
            if ($v < -$limit || $v > $limit) $errors[] = $f['label'] . ' must be between -' . $limit . ' and ' . $limit . ' degrees.';
        }
        if ($name === 'sort_order' && $v === null) $v = 0;
        $data[$name] = $v;
    }
    if ($errors) json_error(implode(' ', $errors));

    // uniqueness checks (case-insensitive) among non-deleted rows
    foreach ($fields as $f) {
        if (!empty($f['unique']) && $data[$f['name']] !== null) {
            $dup = db_value("SELECT id FROM `$table` WHERE LOWER(`{$f['name']}`) = LOWER(?) AND is_deleted = 0 AND id <> ?", [$data[$f['name']], $id]);
            if ($dup) json_error($f['label'] . ' "' . $data[$f['name']] . '" already exists.');
        }
    }
    if (!empty($cfg['unique_together'])) {
        $w = []; $p = [];
        foreach ($cfg['unique_together'] as $c) { $w[] = "`$c` = ?"; $p[] = $data[$c]; }
        $p[] = $id;
        $dup = db_value("SELECT id FROM `$table` WHERE " . implode(' AND ', $w) . " AND is_deleted = 0 AND id <> ?", $p);
        if ($dup) json_error('A record with the same combination already exists.');
    }
    if (isset($data['code'])) $data['code'] = strtoupper((string)$data['code']);
    if ($type === 'tehsils' && $id) {
        $was = (int)db_value('SELECT district_id FROM tehsils WHERE id = ?', [$id], 0);
        if ($was && $was !== (int)$data['district_id'] && db_value('SELECT id FROM departments WHERE tehsil_id = ? LIMIT 1', [$id])) {
            json_error('This tehsil has departments, so it cannot move to another district. Move or remove its departments first.');
        }
    }
    if ($type === 'departments' && $id) {
        $old = db_row('SELECT district_id, tehsil_id FROM departments WHERE id = ?', [$id]);
        $moved = $old && ((int)$old['district_id'] !== (int)$data['district_id'] || (int)$old['tehsil_id'] !== (int)$data['tehsil_id']);
        if ($moved && db_value('SELECT id FROM employees WHERE department_id = ? AND is_deleted = 0 LIMIT 1', [$id])) {
            /* allowed — the staff move with their office — but say so */
            $movedNote = ' Its staff now fall under the new tehsil / district.';
        }
    }

    if ($id) {
        $old = db_find($table, $id);
        if (!$old || $old['is_deleted']) json_error('Record not found.', 404);
        $data['is_active'] = post('is_active') ? 1 : 0;
        db_update($table, $data, 'id = ?', [$id]);
        log_activity('UPDATE', $table, $id, $old, array_merge($old, $data), $cfg['singular'] . ' updated');
        json_ok($cfg['singular'] . ' updated successfully.' . ($movedNote ?? ''), ['id' => $id]);
    }
    $data['is_active'] = 1;
    $newId = db_insert($table, $data);
    log_activity('CREATE', $table, $newId, null, $data, $cfg['singular'] . ' created');
    json_ok($cfg['singular'] . ' added successfully.', ['id' => $newId]);
}

$row = $id ? db_find($table, $id) : null;
if (!$row) json_error('Record not found.', 404);

if ($action === 'toggle') {
    $new = $row['is_active'] ? 0 : 1;
    db_update($table, ['is_active' => $new], 'id = ?', [$id]);
    log_activity('UPDATE', $table, $id, ['is_active' => $row['is_active']], ['is_active' => $new], $cfg['singular'] . ($new ? ' activated' : ' deactivated'));
    json_ok($cfg['singular'] . ($new ? ' activated.' : ' deactivated.'));
}

if (!is_super_admin()) json_error('Only the Super Admin can delete or restore records.', 403);

if ($action === 'delete') {
    db_update($table, ['is_deleted' => 1, 'is_active' => 0], 'id = ?', [$id]);
    log_activity('DELETE', $table, $id, $row, null, $cfg['singular'] . ' moved to recycle bin');
    json_ok($cfg['singular'] . ' moved to the recycle bin.');
}
if ($action === 'restore') {
    db_update($table, ['is_deleted' => 0, 'is_active' => 1], 'id = ?', [$id]);
    log_activity('RESTORE', $table, $id, null, $row, $cfg['singular'] . ' restored');
    json_ok($cfg['singular'] . ' restored.');
}
if ($action === 'hard_delete') {
    $use = master_usage_count($cfg, $id);
    if ($use > 0) json_error("Cannot permanently delete: this record is referenced by $use record(s). Keep it inactive instead.");
    db_exec("DELETE FROM `$table` WHERE id = ?", [$id]);
    log_activity('HARD_DELETE', $table, $id, $row, null, $cfg['singular'] . ' permanently deleted');
    json_ok($cfg['singular'] . ' permanently deleted.');
}
json_error('Unknown action.');
