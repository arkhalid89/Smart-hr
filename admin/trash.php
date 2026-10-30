<?php
/** Recycle bin (Super Admin): every soft-deleted record in one place - restore or delete permanently. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/masters_config.php';
require_super_admin();

$sections = [
    'employees' => ['label' => 'Employees', 'link' => 'employees/index.php?deleted=1', 'sql' => 'SELECT id, CONCAT(name, " (", employee_code, ")") AS label, deleted_at AS ts FROM employees WHERE is_deleted = 1 ORDER BY deleted_at DESC'],
    'users'     => ['label' => 'Users', 'link' => 'admin/users.php?deleted=1', 'sql' => 'SELECT id, CONCAT(full_name, " (", username, ")") AS label, deleted_at AS ts FROM users WHERE is_deleted = 1 ORDER BY deleted_at DESC'],
    'employee_family' => ['label' => 'Family / Nominee Records', 'sql' => 'SELECT f.id, CONCAT(f.name, " — ", f.relation, " of ", e.name) AS label, f.updated_at AS ts FROM employee_family f JOIN employees e ON e.id = f.employee_id WHERE f.is_deleted = 1 ORDER BY f.id DESC'],
    'employee_documents' => ['label' => 'Documents', 'sql' => 'SELECT d.id, CONCAT(d.title, " — ", e.name) AS label, d.created_at AS ts FROM employee_documents d JOIN employees e ON e.id = d.employee_id WHERE d.is_deleted = 1 ORDER BY d.id DESC'],
];
foreach (masters_config() as $key => $c) {
    $sections[$key] = ['label' => $c['label'], 'link' => 'masters/index.php?type=' . $key, 'sql' => $key === 'sanctioned_posts'
        ? 'SELECT s.id, CONCAT(d.name, " / ", g.name) AS label, s.updated_at AS ts FROM sanctioned_posts s JOIN departments d ON d.id = s.department_id JOIN designations g ON g.id = s.designation_id WHERE s.is_deleted = 1'
        : "SELECT id, name AS label, updated_at AS ts FROM `$key` WHERE is_deleted = 1 ORDER BY updated_at DESC"];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $table = db_ident((string)post('table'));
    $id = post_int('id', 0);
    $action = (string)post('action');
    if (!isset($sections[$table]) || !$id) json_error('Invalid request.');
    $row = db_find($table, $id);
    if (!$row || !$row['is_deleted']) json_error('Record not found in recycle bin.');
    if ($action === 'restore') {
        $upd = ['is_deleted' => 0];
        if (array_key_exists('is_active', $row)) $upd['is_active'] = 1;
        if (array_key_exists('deleted_at', $row)) $upd['deleted_at'] = null;
        db_update($table, $upd, 'id = ?', [$id]);
        log_activity('RESTORE', $table, $id, null, $upd, ucfirst(str_replace('_', ' ', rtrim($table, 's'))) . ' restored from recycle bin', $row['employee_id'] ?? ($table === 'employees' ? $id : null));
        json_ok('Record restored.');
    }
    if ($action === 'hard_delete') {
        if ($table === 'employees') json_error('Use the Employees recycle bin to permanently delete an employee (cascades history).');
        if ($table === 'users' && $id === 1) json_error('The primary Super Admin cannot be deleted.');
        if (isset(masters_config()[$table])) {
            $cfg = masters_config()[$table]; $n = 0;
            foreach ($cfg['usage'] as $t => $col) $n += (int)db_value('SELECT COUNT(*) FROM `' . db_ident($t) . '` WHERE `' . db_ident($col) . '` = ?', [$id], 0);
            if ($n) json_error("Cannot delete: referenced by $n record(s).");
        }
        if ($table === 'employee_documents') @unlink(UPLOAD_PATH . '/documents/' . $row['file_path']);
        db_exec("DELETE FROM `$table` WHERE id = ?", [$id]);
        log_activity('HARD_DELETE', $table, $id, $row, null, ucfirst(str_replace('_', ' ', rtrim($table, 's'))) . ' permanently deleted', $row['employee_id'] ?? null);
        json_ok('Record permanently deleted.');
    }
    json_error('Unknown action.');
}

$data = [];
foreach ($sections as $key => $s) $data[$key] = db_all($s['sql']);
$total = array_sum(array_map('count', $data));

$page_title  = 'Recycle Bin';
$active_menu = 'trash';
$breadcrumbs = ['Administration' => null, 'Recycle Bin' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="alert alert-light border small"><i class="bi bi-info-circle me-1"></i>Soft-deleted records stay here until restored or permanently deleted. <strong><?= $total ?></strong> item(s) in the bin.</div>
<div class="row g-3">
<?php foreach ($sections as $key => $s): $rows = $data[$key]; if (!$rows) continue; ?>
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header d-flex justify-content-between align-items-center"><span class="card-title"><?= e($s['label']) ?> <span class="badge rounded-pill text-bg-light border"><?= count($rows) ?></span></span>
                <?php if (!empty($s['link'])): ?><a href="<?= url($s['link']) ?>" class="small">Open</a><?php endif; ?></div>
            <div class="table-responsive" style="max-height:320px;overflow:auto"><table class="table table-sm table-hover align-middle mb-0"><tbody>
                <?php foreach ($rows as $r): ?>
                <tr><td><?= e($r['label']) ?><br><span class="x-small text-muted">deleted <?= fmt_datetime($r['ts']) ?></span></td>
                    <td class="text-end table-actions"><button class="btn btn-ghost btn-sm text-success" data-tact="restore" data-table="<?= e($key) ?>" data-id="<?= $r['id'] ?>" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                    <button class="btn btn-ghost btn-sm text-danger" data-tact="hard_delete" data-table="<?= e($key) ?>" data-id="<?= $r['id'] ?>" title="Delete permanently" <?= $key === 'employees' ? 'disabled' : '' ?>><i class="bi bi-trash3"></i></button></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
<?php endforeach; ?>
<?php if (!$total): ?><div class="col-12"><div class="card"><div class="card-body text-center text-muted py-5"><i class="bi bi-recycle display-5 d-block mb-2"></i>The recycle bin is empty.</div></div></div><?php endif; ?>
</div>
<?php
$extra_js = <<<JS
<script>
$(document).on('click', 'button[data-tact]', function(){
  const a = this.dataset.tact, t = this.dataset.table, id = this.dataset.id;
  const run = () => HR.post('admin/trash.php', { action: a, table: t, id: id }).done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); if (r.success) setTimeout(() => location.reload(), 500); });
  a === 'hard_delete' ? HR.confirm('Permanently delete this record? This cannot be undone.').then(ok => ok && run()) : run();
});
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
