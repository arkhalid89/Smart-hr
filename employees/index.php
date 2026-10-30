<?php
/**
 * Employee listing with advanced filters, server-side pagination and export.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();        // department-level officers and report viewers see their staff read-only
$canEdit = can_publish();

$g = $_GET;
[$where, $params] = employee_filters($g);

$perPage = (int)($g["per"] ?? 25);
if (!in_array($perPage, [10, 25, 50, 100], true)) $perPage = 25;
$page    = max(1, (int)($g['page'] ?? 1));
$sortable = ['name' => 'e.name', 'code' => 'e.employee_code', 'dept' => 'd.name', 'desig' => 'g.name', 'joining' => 'e.joining_date', 'status' => 'e.status', 'dob' => 'e.dob'];
$sort  = $sortable[$g['sort'] ?? 'name'] ?? 'e.name';
$dir   = (($g['dir'] ?? 'asc') === 'desc') ? 'DESC' : 'ASC';

$from = "FROM employees e
         LEFT JOIN departments d ON d.id = e.department_id
         LEFT JOIN designations g ON g.id = e.designation_id
         LEFT JOIN pay_scales ps ON ps.id = e.pay_scale_id
         LEFT JOIN employment_types et ON et.id = e.employment_type_id
         WHERE $where";
$total = (int)db_value("SELECT COUNT(*) $from", $params, 0);
$pages = max(1, (int)ceil($total / $perPage));
if ($page > $pages) $page = $pages;
$offset = ($page - 1) * $perPage;
$rows = db_all("SELECT e.id, e.employee_code, e.cnic, e.name, e.father_husband_name, e.gender, e.dob, e.photo, e.status,
        e.joining_date, e.contact, e.is_active, e.confirmation_date, e.contract_to, e.retirement_date,
        d.name AS department, g.name AS designation, ps.name AS pay_scale, et.name AS employment_type
    $from ORDER BY $sort $dir, e.id ASC LIMIT ? OFFSET ?", array_merge($params, [$perPage, $offset]));

/* filter dropdown data */
$geoNames = [
    'district_id'   => !empty($g['district_id']) ? db_value('SELECT name FROM districts WHERE id = ?', [(int)$g['district_id']]) : null,
    'tehsil_id'     => isset($g['tehsil_id']) && $g['tehsil_id'] !== '' ? ((string)$g['tehsil_id'] === '0' ? 'District headquarters' : db_value('SELECT name FROM tehsils WHERE id = ?', [(int)$g['tehsil_id']])) : null,
    'department_id' => !empty($g['department_id']) ? db_value('SELECT name FROM departments WHERE id = ?', [(int)$g['department_id']]) : null,
];
$designations = master_options('designations');
$empTypes     = master_options('employment_types');
$showDeleted  = is_super_admin() && ($g['deleted'] ?? '') === '1';

$qs = fn(array $over = []) => 'employees/index.php?' . http_build_query(array_filter(array_merge($g, $over), fn($v) => $v !== '' && $v !== null));
$sortLink = function (string $key, string $label) use ($g, $qs) {
    $active = ($g['sort'] ?? 'name') === $key;
    $nextDir = $active && ($g['dir'] ?? 'asc') === 'asc' ? 'desc' : 'asc';
    $icon = $active ? ($nextDir === 'desc' ? 'bi-sort-up' : 'bi-sort-down') : 'bi-arrow-down-up text-muted';
    return '<a href="' . e(url($qs(['sort' => $key, 'dir' => $nextDir, 'page' => 1]))) . '" class="text-reset text-decoration-none">' . e($label) . ' <i class="bi ' . $icon . ' small"></i></a>';
};

$page_title  = $showDeleted ? 'Deleted Employees' : 'Employees';
$active_menu = 'employees';
$breadcrumbs = ['Employees' => null];
$page_actions = ($canEdit ? '<a href="' . url('employees/add.php') . '" class="btn btn-primary btn-sm"><i class="bi bi-person-plus me-1"></i>New Employee</a>' : '')
    . '<div class="btn-group btn-group-sm"><a href="' . e(url('employees/export.php?' . http_build_query($g) . '&format=xlsx')) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
    . '<a href="' . e(url('employees/export.php?' . http_build_query($g) . '&format=csv')) . '" class="btn btn-outline-success">CSV</a></div>';
require ROOT_PATH . '/includes/header.php';
?>
<?php
/* active filter chips */
$chips = [];
$chipDefs = ['q' => 'Search', 'district_id' => 'District', 'tehsil_id' => 'Tehsil', 'department_id' => 'Department', 'designation_id' => 'Designation', 'status' => 'Status', 'probation' => 'Probation', 'employment_type_id' => 'Type', 'gender' => 'Gender', 'serving' => 'Serving only', 'deputation' => 'On deputation', 'lpr' => 'LPR eligible', 'break' => 'Break in service', 'active' => 'Inactive records', 'deleted' => 'Recycle bin'];
$lookup = ['district_id' => [($g['district_id'] ?? '') => $geoNames['district_id']], 'tehsil_id' => [($g['tehsil_id'] ?? '') => $geoNames['tehsil_id']], 'department_id' => [($g['department_id'] ?? '') => $geoNames['department_id']], 'designation_id' => array_column($designations, 'name', 'id'), 'employment_type_id' => array_column($empTypes, 'name', 'id')];
foreach ($chipDefs as $k => $label) {
    if (($g[$k] ?? '') === '' || ($k === 'active' && $g[$k] !== '0')) continue;
    $v = $g[$k];
    $text = isset($lookup[$k]) ? ($lookup[$k][$v] ?? $v) : (in_array($k, ['serving', 'deputation', 'lpr', 'break', 'active', 'deleted']) ? '' : $v);
    $chips[] = [$k, $label . ($text !== '' ? ': ' . $text : '')];
}
$moreOpen = !empty($g['district_id']) || (($g['tehsil_id'] ?? '') !== '') || !empty($g['department_id']) || !empty($g['probation']) || !empty($g['employment_type_id']) || !empty($g['gender']) || !empty($g['serving']) || !empty($g['deputation']) || !empty($g['lpr']) || !empty($g['break']) || ($g['active'] ?? '') === '0';
?>
<form method="get" class="toolbar mb-3" id="empFilters">
    <div class="toolbar-row">
        <div class="search-field flex-grow-1">
            <i class="bi bi-search"></i>
            <input type="text" name="q" class="form-control" value="<?= e($g['q'] ?? '') ?>" placeholder="Search by name, CNIC, code, father name or phone…">
        </div>
        <select name="designation_id" class="form-select select2 w-auto" data-allow-clear="1" data-placeholder="Designation"><option value=""></option><?= options_html($designations, $g['designation_id'] ?? '') ?></select>
        <select name="status" class="form-select select2 w-auto" data-allow-clear="1" data-placeholder="Status"><option value=""></option><?= options_html(EMP_STATUSES, $g['status'] ?? '') ?></select>
        <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button>
        <button class="btn btn-light" type="button" data-bs-toggle="collapse" data-bs-target="#moreFilters" aria-expanded="<?= $moreOpen ? 'true' : 'false' ?>"><i class="bi bi-sliders me-1"></i>More</button>
        <?php if ($chips): ?><a href="<?= url('employees/index.php') ?>" class="btn btn-ghost" title="Clear all filters"><i class="bi bi-x-lg"></i></a><?php endif; ?>
    </div>
    <div class="collapse <?= $moreOpen ? 'show' : '' ?>" id="moreFilters">
        <div class="toolbar-row more d-block">
            <?= geo_picker(['mode' => 'filter', 'values' => $g, 'size' => 'sm', 'labels' => false, 'col' => 'col-md-4', 'id' => 'empgeo']) ?>
        </div>
        <div class="toolbar-row more">
            <select name="probation" class="form-select w-auto"><option value="">Probation: any</option><option value="unconfirmed" <?= ($g['probation'] ?? '') === 'unconfirmed' ? 'selected' : '' ?>>Unconfirmed</option><option value="confirmed" <?= ($g['probation'] ?? '') === 'confirmed' ? 'selected' : '' ?>>Confirmed</option></select>
            <select name="employment_type_id" class="form-select w-auto"><option value="">Type: any</option><?= options_html($empTypes, $g['employment_type_id'] ?? '') ?></select>
            <select name="gender" class="form-select w-auto"><option value="">Gender: any</option><?= options_html(['Male', 'Female', 'Other'], $g['gender'] ?? '') ?></select>
            <div class="vr d-none d-md-block"></div>
            <?php foreach (['serving' => ['1', 'Serving only'], 'deputation' => ['1', 'On deputation'], 'lpr' => ['1', 'LPR eligible'], 'break' => ['1', 'Break in service'], 'active' => ['0', 'Inactive records']] as $k => [$v, $l]): ?>
            <label class="chip-check"><input type="checkbox" name="<?= $k ?>" value="<?= $v ?>" <?= ($g[$k] ?? '') === $v ? 'checked' : '' ?> onchange="this.form.submit()"><span><?= $l ?></span></label>
            <?php endforeach; ?>
            <?php if (is_super_admin()): ?><label class="chip-check danger"><input type="checkbox" name="deleted" value="1" <?= $showDeleted ? 'checked' : '' ?> onchange="this.form.submit()"><span><i class="bi bi-recycle me-1"></i>Recycle bin</span></label><?php endif; ?>
        </div>
    </div>
    <?php if ($chips): ?>
    <div class="chip-row">
        <?php foreach ($chips as [$k, $label]): ?><a href="<?= e(url($qs([$k => '', 'page' => 1]))) ?>" class="chip"><?= e($label) ?><i class="bi bi-x"></i></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
</form>
<div class="card">
    <div class="card-header d-flex justify-content-between align-items-center">
        <span class="card-title"><?= number_format($total) ?> employee<?= $total === 1 ? '' : 's' ?> found</span>
        <div class="d-flex align-items-center gap-3 small text-muted"><span>Page <?= $page ?> of <?= $pages ?></span><span>Rows: <?php foreach ([25, 50, 100] as $n): ?><a href="<?= e(url($qs(['per' => $n, 'page' => 1]))) ?>" class="<?= $perPage === $n ? 'fw-bold text-primary' : '' ?>"><?= $n ?></a> <?php endforeach; ?></span></div>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead><tr>
                <th style="width:48px">#</th>
                <th><?= $sortLink('name', 'Employee') ?></th>
                <th><?= $sortLink('code', 'Code') ?></th>
                <th>CNIC</th>
                <th><?= $sortLink('desig', 'Designation') ?></th>
                <th><?= $sortLink('dept', 'Department') ?></th>
                <th>Scale</th>
                <th><?= $sortLink('joining', 'Joining') ?></th>
                <th><?= $sortLink('status', 'Status') ?></th>
                <th class="text-end">Actions</th>
            </tr></thead>
            <tbody>
            <?php if (!$rows): ?>
                <tr><td colspan="10" class="text-center text-muted py-5"><i class="bi bi-people display-6 d-block mb-2"></i>No employees match the current filters.</td></tr>
            <?php endif; ?>
            <?php foreach ($rows as $i => $r): ?>
                <tr class="<?= $r['is_active'] ? '' : 'table-secondary' ?>">
                    <td class="text-muted"><?= $offset + $i + 1 ?></td>
                    <td>
                        <a href="<?= url('employees/view.php?id=' . $r['id']) ?>" class="emp-cell text-reset text-decoration-none">
                            <img src="<?= photo_url($r['photo'], (int)$r['id']) ?>" alt="" loading="lazy">
                            <span><span class="emp-name d-block"><?= e($r['name']) ?></span>
                            <span class="emp-meta"><?= e($r['father_husband_name']) ?> · <?= e($r['gender']) ?>, <?= calc_age($r['dob']) ?> yrs</span></span>
                        </a>
                    </td>
                    <td class="text-nowrap"><code><?= e($r["employee_code"]) ?></code></td>
                    <td class="text-muted text-nowrap"><?= e($r["cnic"]) ?></td>
                    <td><?= e($r['designation']) ?><?php if (!$r['confirmation_date'] && in_array($r['status'], ['Probation', 'Active'])): ?> <span class="badge text-bg-light border" title="Not yet confirmed">Unconfirmed</span><?php endif; ?></td>
                    <td><?= e($r['department']) ?></td>
                    <td><?= e($r['pay_scale'] ?: '-') ?></td>
                    <td class="text-nowrap"><?= fmt_date($r["joining_date"]) ?></td>
                    <td><?= status_badge($r['status']) ?><?php if (!$r['is_active']): ?> <span class="badge text-bg-secondary">Inactive</span><?php endif; ?></td>
                    <td class="text-end table-actions">
                        <?php if ($showDeleted): ?>
                            <button class="btn btn-ghost btn-sm text-success" data-emp-act="restore" data-id="<?= $r['id'] ?>" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                            <button class="btn btn-ghost btn-sm text-danger" data-emp-act="hard_delete" data-id="<?= $r['id'] ?>" title="Delete permanently"><i class="bi bi-trash3"></i></button>
                        <?php else: ?>
                            <a href="<?= url('employees/view.php?id=' . $r['id']) ?>" class="btn btn-ghost btn-sm" title="View profile"><i class="bi bi-eye"></i></a>
                            <?php if ($canEdit): ?><a href="<?= url('employees/edit.php?id=' . $r['id']) ?>" class="btn btn-ghost btn-sm" title="Edit"><i class="bi bi-pencil"></i></a><?php endif; ?>
                            <div class="dropdown d-inline-block">
                                <button class="btn btn-ghost btn-sm" data-bs-toggle="dropdown" title="More"><i class="bi bi-three-dots-vertical"></i></button>
                                <ul class="dropdown-menu dropdown-menu-end">
                                    <li><a class="dropdown-item" href="<?= url('reports/profile_print.php?id=' . $r['id']) ?>" target="_blank"><i class="bi bi-printer me-2"></i>Print profile</a></li>
                                    <li><a class="dropdown-item" href="<?= url('reports/service_book.php?id=' . $r['id']) ?>" target="_blank"><i class="bi bi-journal-text me-2"></i>Service book</a></li>
                                    <?php if ($canEdit): ?><li><hr class="dropdown-divider"></li>
                                    <li><button class="dropdown-item" data-emp-act="toggle" data-id="<?= $r['id'] ?>"><i class="bi bi-<?= $r['is_active'] ? 'pause-circle' : 'play-circle' ?> me-2"></i><?= $r['is_active'] ? 'Mark inactive' : 'Mark active' ?></button></li>
                                    <?php endif; ?>
                                    <?php if (is_super_admin()): ?><li><button class="dropdown-item text-danger" data-emp-act="delete" data-id="<?= $r['id'] ?>"><i class="bi bi-trash me-2"></i>Move to recycle bin</button></li><?php endif; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php if ($pages > 1): ?>
    <div class="card-footer bg-transparent">
        <nav><ul class="pagination pagination-sm mb-0 justify-content-end flex-wrap">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page - 1]))) ?>">&laquo;</a></li>
            <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?>
                <li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $p]))) ?>"><?= $p ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page + 1]))) ?>">&raquo;</a></li>
        </ul></nav>
    </div>
    <?php endif; ?>
</div>

<?php
$extra_js = <<<JS
<script>
$(document).on('click', '[data-emp-act]', function () {
  const id = this.dataset.id, a = this.dataset.act || this.dataset.empAct;
  const msgs = { delete: 'Move this employee to the recycle bin? The record stays recoverable by the Super Admin.',
                 hard_delete: 'PERMANENTLY delete this employee and ALL service history? This cannot be undone.',
                 toggle: null, restore: null };
  const run = () => HR.post('employees/ajax/actions.php', { action: a, id: id })
     .done(res => { HR.toast(res.message, res.success ? 'success' : 'danger'); if (res.success) setTimeout(() => location.reload(), 600); });
  if (msgs[a]) HR.confirm(msgs[a]).then(ok => ok && run()); else run();
});
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
