<?php
/** System audit logs with filters and before/after diff viewer. Admins see logs of their departments' employees + their own actions. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();
require_once ROOT_PATH . '/includes/audit_ui.php';

$g = $_GET;
$where = ['1=1']; $params = [];
if (!is_super_admin()) {
    [$scope, $sp] = emp_scope('e');
    $where[] = "(a.user_id = ? OR (a.employee_id IS NOT NULL AND $scope))";
    $params = array_merge([user_id()], $sp);
}
if (!empty($g['user_id'])) { $where[] = 'a.user_id = ?'; $params[] = (int)$g['user_id']; }
if (!empty($g['action'])) { $where[] = 'a.action = ?'; $params[] = $g['action']; }
if (!empty($g['table'])) { $where[] = 'a.table_name = ?'; $params[] = $g['table']; }
if (!empty($g['employee_id'])) { $where[] = 'a.employee_id = ?'; $params[] = (int)$g['employee_id']; }
if (!empty($g['from'])) { $where[] = 'a.created_at >= ?'; $params[] = $g['from'] . ' 00:00:00'; }
if (!empty($g['to'])) { $where[] = 'a.created_at <= ?'; $params[] = $g['to'] . ' 23:59:59'; }
if (!empty($g['q'])) { $where[] = '(a.description LIKE ? OR a.ip_address LIKE ? OR e.name LIKE ?)'; $l = '%' . $g['q'] . '%'; array_push($params, $l, $l, $l); }
$w = implode(' AND ', $where);
$from = "FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id LEFT JOIN employees e ON e.id = a.employee_id WHERE $w";

$perPage = 50; $page = max(1, (int)($g['page'] ?? 1));
$total = (int)db_value("SELECT COUNT(*) $from", $params, 0);
$pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
$rows = db_all("SELECT a.*, u.full_name, e.name AS emp_name, e.employee_code $from ORDER BY a.id DESC LIMIT ? OFFSET ?", array_merge($params, [$perPage, ($page - 1) * $perPage]));

if (get('export')) {
    require_once ROOT_PATH . '/includes/export.php';
    $all = db_all("SELECT a.*, u.full_name, e.name AS emp_name $from ORDER BY a.id DESC LIMIT 5000", $params);
    $out = [];
    foreach ($all as $i => $r) {
        $o = $r['old_values'] ? json_decode((string)$r['old_values'], true) : null;
        $n = $r['new_values'] ? json_decode((string)$r['new_values'], true) : null;
        $out[] = [
            $i + 1, $r['created_at'], $r['full_name'] ?: $r['username'],
            audit_action_meta($r['action'])[1], audit_table_label($r['table_name']),
            audit_record_ref($r['table_name'], (int)$r['record_id']), $r['emp_name'], $r['description'],
            audit_changes_text(is_array($o) ? $o : null, is_array($n) ? $n : null, (string)$r['action']),
            $r['ip_address'],
        ];
    }
    export_table('audit_logs', ['#', 'Timestamp', 'User', 'Action', 'Record Type', 'Reference', 'Employee', 'Description', 'Changes', 'IP Address'], $out, 'Audit Logs');
}

$users = db_all('SELECT id, full_name FROM users WHERE is_deleted = 0 ORDER BY full_name');
$actions = array_column(db_all('SELECT DISTINCT action FROM audit_logs ORDER BY action'), 'action');
$tables = array_column(db_all('SELECT DISTINCT table_name FROM audit_logs WHERE table_name IS NOT NULL ORDER BY table_name'), 'table_name');
$qs = fn(array $o = []) => 'admin/audit_logs.php?' . http_build_query(array_filter(array_merge($g, $o), fn($v) => $v !== '' && $v !== null));

$page_title  = 'Audit Logs';
$active_menu = 'audit';
$breadcrumbs = ['Audit Logs' => null];
$page_actions = '<a href="' . e(url($qs(['export' => 1, 'format' => 'xlsx']))) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>';
require ROOT_PATH . '/includes/header.php';
?>
<form method="get" class="filter-bar mb-3"><div class="row g-2 align-items-end">
    <div class="col-md-2"><label class="form-label mb-1">User</label><select name="user_id" class="form-select form-select-sm select2" data-allow-clear="1" data-placeholder="All"><option value="">All</option><?= options_html($users, $g['user_id'] ?? '', 'id', 'full_name') ?></select></div>
    <div class="col-md-2"><label class="form-label mb-1">Action</label><select name="action" class="form-select form-select-sm"><option value="">All</option><?= options_html($actions, $g['action'] ?? '') ?></select></div>
    <div class="col-md-2"><label class="form-label mb-1">Table</label><select name="table" class="form-select form-select-sm"><option value="">All</option><?= options_html($tables, $g['table'] ?? '') ?></select></div>
    <div class="col-md-2"><label class="form-label mb-1">From</label><input type="date" name="from" class="form-control form-control-sm" value="<?= e($g['from'] ?? '') ?>"></div>
    <div class="col-md-2"><label class="form-label mb-1">To</label><input type="date" name="to" class="form-control form-control-sm" value="<?= e($g['to'] ?? '') ?>"></div>
    <div class="col-md-2 d-flex gap-1"><input type="text" name="q" class="form-control form-control-sm" placeholder="Search…" value="<?= e($g['q'] ?? '') ?>"><button class="btn btn-sm btn-primary"><i class="bi bi-funnel"></i></button><a href="<?= url('admin/audit_logs.php') ?>" class="btn btn-sm btn-light"><i class="bi bi-x-lg"></i></a></div>
</div></form>
<div class="card">
    <div class="card-header d-flex justify-content-between"><span class="card-title"><?= number_format($total) ?> entries</span><span class="small text-muted">Page <?= $page ?> / <?= $pages ?></span></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>#</th><th>When</th><th>User</th><th>Action</th><th>Record</th><th>Employee</th><th>Description</th><th>Changes</th><th class="text-end">Details</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): $old = $a['old_values'] ? json_decode($a['old_values'], true) : null; $new = $a['new_values'] ? json_decode($a['new_values'], true) : null; ?>
        <tr>
            <td class="text-muted small"><?= $a['id'] ?></td>
            <td class="small text-nowrap"><?= fmt_datetime($a['created_at']) ?></td>
            <td class="small"><?= e($a['full_name'] ?: $a['username']) ?></td>
            <td><span class="badge text-bg-<?= audit_action_meta($a['action'])[0] ?>"><?= e($a['action']) ?></span></td>
            <td class="small text-muted text-nowrap"><?= e(audit_record_ref($a['table_name'], (int)$a['record_id'])) ?: '-' ?></td>
            <td class="small"><?= $a['employee_id'] ? '<a href="' . url('employees/view.php?id=' . $a['employee_id'] . '#tab-audit') . '">' . e($a['emp_name'] ?: '#' . $a['employee_id']) . '</a>' : '-' ?></td>
            <td class="small"><?= e($a['description']) ?><div class="text-muted"><?= e($a['ip_address']) ?></div></td>
            <td class="small audit-summary">
                <?php if (is_array($new) && $a['action'] === 'UPDATE'): $i = 0; ?>
                    <?php foreach ($new as $k => $v): if ($i++ >= 2) break; ?>
                        <div class="text-truncate"><span class="text-muted"><?= e(audit_field_label((string)$k)) ?>:</span> <?= strip_tags(audit_field_value((string)$k, $old[$k] ?? null)) ?> &rarr; <strong><?= strip_tags(audit_field_value((string)$k, $v)) ?></strong></div>
                    <?php endforeach; ?>
                    <?php if (count($new) > 2): ?><div class="text-muted"><?= count($new) - 2 ?> more field<?= count($new) - 2 > 1 ? 's' : '' ?></div><?php endif; ?>
                <?php elseif (is_array($new) || is_array($old)): ?>
                    <span class="text-muted"><?= count(array_diff_key(($new ?: $old), array_flip(AUDIT_HIDDEN_FIELDS))) ?> field<?= count(array_diff_key(($new ?: $old), array_flip(AUDIT_HIDDEN_FIELDS))) === 1 ? '' : 's' ?> recorded</span>
                <?php else: ?><span class="text-muted">-</span><?php endif; ?>
            </td>
            <td class="text-end"><?= audit_view_button((int)$a['id']) ?></td>
        </tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="9" class="text-center text-muted py-4">No log entries match.</td></tr><?php endif; ?>
        </tbody></table></div>
    <?php if ($pages > 1): ?><div class="card-footer bg-transparent"><ul class="pagination pagination-sm mb-0 justify-content-end">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page - 1]))) ?>">&laquo;</a></li>
        <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $p]))) ?>"><?= $p ?></a></li><?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page + 1]))) ?>">&raquo;</a></li></ul></div><?php endif; ?>
</div>
<?= audit_modal_html() ?>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
