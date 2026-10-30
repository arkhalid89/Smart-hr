<?php
/** Delivery log of Email / SMS / WhatsApp messages sent to employees (department scoped for admins). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_officer();

$g = $_GET;
[$scope, $params] = emp_scope('e');
$where = [$scope];
if (!empty($g['employee_id'])) { $where[] = 'n.employee_id = ?'; $params[] = (int)$g['employee_id']; }
if (!empty($g['channel'])) { $where[] = 'n.channel = ?'; $params[] = $g['channel']; }
if (!empty($g['status'])) { $where[] = 'n.status = ?'; $params[] = $g['status']; }
if (!empty($g['from'])) { $where[] = 'n.created_at >= ?'; $params[] = $g['from'] . ' 00:00:00'; }
if (!empty($g['to'])) { $where[] = 'n.created_at <= ?'; $params[] = $g['to'] . ' 23:59:59'; }
if (!empty($g['q'])) { $where[] = '(e.name LIKE ? OR n.recipient LIKE ? OR n.message LIKE ?)'; $l = '%' . $g['q'] . '%'; array_push($params, $l, $l, $l); }
$w = implode(' AND ', $where);
$from = "FROM notification_log n JOIN employees e ON e.id = n.employee_id LEFT JOIN users u ON u.id = n.sent_by WHERE $w";
$perPage = 50; $page = max(1, (int)($g['page'] ?? 1));
$total = (int)db_value("SELECT COUNT(*) $from", $params, 0);
$pages = max(1, (int)ceil($total / $perPage)); $page = min($page, $pages);
$rows = db_all("SELECT n.*, e.name AS emp_name, e.employee_code, u.full_name AS sender $from ORDER BY n.id DESC LIMIT ? OFFSET ?", array_merge($params, [$perPage, ($page - 1) * $perPage]));
$stats = db_all("SELECT n.channel, n.status, COUNT(*) c FROM notification_log n JOIN employees e ON e.id = n.employee_id WHERE $scope GROUP BY n.channel, n.status", array_slice($params, 0, count(emp_scope('e')[1])));

if (get('export')) {
    require_once ROOT_PATH . '/includes/export.php';
    $all = db_all("SELECT n.*, e.name AS emp_name, e.employee_code, u.full_name AS sender $from ORDER BY n.id DESC LIMIT 5000", $params);
    $out = []; foreach ($all as $i => $r) $out[] = [$i + 1, $r['created_at'], $r['emp_name'], $r['employee_code'], $r['channel'], $r['recipient'], $r['subject'], $r['message'], $r['status'], $r['response'], $r['sender']];
    export_table('notification_log', ['#', 'Sent At', 'Employee', 'Code', 'Channel', 'Recipient', 'Subject', 'Message', 'Status', 'Gateway Response', 'Sent By'], $out, 'Notification Log');
}
$qs = fn(array $o = []) => 'admin/notification_log.php?' . http_build_query(array_filter(array_merge($g, $o), fn($v) => $v !== '' && $v !== null));

$page_title  = 'Notification Log';
$page_subtitle = 'Email, SMS and WhatsApp messages sent to employees';
$active_menu = 'notification_log';
$breadcrumbs = ['Notification Log' => null];
$page_actions = '<a href="' . e(url($qs(['export' => 1, 'format' => 'xlsx']))) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>' . (is_super_admin() ? '<a href="' . url('admin/settings.php') . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-gear me-1"></i>Gateway settings</a>' : '');
require ROOT_PATH . '/includes/header.php';
$cnt = []; foreach ($stats as $s) $cnt[$s['channel']][$s['status']] = (int)$s['c'];
?>
<div class="row g-3 mb-3">
    <?php foreach (NOTIFY_CHANNELS as $key => $label): $c = $cnt[$key] ?? []; $ready = notify_channel_ready($key); ?>
    <div class="col-md-4"><div class="card stat-card"><div class="stat-icon bg-soft-<?= ['email' => 'info', 'sms' => 'primary', 'whatsapp' => 'success'][$key] ?>"><i class="bi <?= ['email' => 'bi-envelope', 'sms' => 'bi-chat-dots', 'whatsapp' => 'bi-whatsapp'][$key] ?>"></i></div>
        <div><div class="stat-value"><?= $c['sent'] ?? 0 ?> <span class="fs-6 fw-normal text-muted">sent</span></div><div class="stat-label"><?= $label ?> · <?= ($c['failed'] ?? 0) ?> failed · <?= $ready ? '<span class="text-success">configured</span>' : '<span class="text-warning">not configured</span>' ?></div></div></div></div>
    <?php endforeach; ?>
</div>
<form method="get" class="toolbar mb-3"><div class="toolbar-row">
    <div class="search-field flex-grow-1"><i class="bi bi-search"></i><input type="text" name="q" class="form-control" placeholder="Employee, recipient or message…" value="<?= e($g['q'] ?? '') ?>"></div>
    <select name="channel" class="form-select w-auto"><option value="">All channels</option><?= options_html(array_keys(NOTIFY_CHANNELS), $g['channel'] ?? '') ?></select>
    <select name="status" class="form-select w-auto"><option value="">All statuses</option><?= options_html(['sent', 'failed', 'skipped', 'not_configured'], $g['status'] ?? '') ?></select>
    <input type="date" name="from" class="form-control w-auto" value="<?= e($g['from'] ?? '') ?>"><input type="date" name="to" class="form-control w-auto" value="<?= e($g['to'] ?? '') ?>">
    <?php if (!empty($g['employee_id'])): ?><input type="hidden" name="employee_id" value="<?= (int)$g['employee_id'] ?>"><?php endif; ?>
    <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Filter</button><a href="<?= url('admin/notification_log.php') ?>" class="btn btn-ghost"><i class="bi bi-x-lg"></i></a>
</div></form>
<div class="card">
    <div class="card-header"><span class="card-title"><?= number_format($total) ?> messages</span><span class="small text-muted">Page <?= $page ?> / <?= $pages ?></span></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th style="width:40px">#</th><th>Sent At</th><th>Employee</th><th>Channel</th><th>Recipient</th><th>Message</th><th>Status</th><th>Gateway Response</th><th>Sent By</th></tr></thead>
        <tbody>
        <?php if (!$rows): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-send"></i>No messages match.</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $i => $r): ?>
        <tr>
            <td class="text-muted small"><?= ($page - 1) * $perPage + $i + 1 ?></td>
            <td class="small text-nowrap"><?= fmt_datetime($r['created_at']) ?></td>
            <td><a href="<?= url('employees/view.php?id=' . $r['employee_id']) ?>" class="fw-semibold text-reset"><?= e($r['emp_name']) ?></a><div class="small text-muted"><?= e($r['employee_code']) ?></div></td>
            <td><i class="bi <?= ['email' => 'bi-envelope', 'sms' => 'bi-chat-dots', 'whatsapp' => 'bi-whatsapp'][$r['channel']] ?? 'bi-send' ?> me-1 text-muted"></i><?= e(NOTIFY_CHANNELS[$r['channel']] ?? $r['channel']) ?></td>
            <td class="small"><?= e($r['recipient'] ?: '—') ?></td>
            <td class="small" style="max-width:320px"><div class="text-truncate" title="<?= e($r['message']) ?>"><?= e($r['message']) ?></div><?= $r['related_table'] ? '<span class="ref-no x-small">' . e(svc_ref_of($r['related_table'], (int)$r['related_id'])) . '</span>' : '' ?></td>
            <td><span class="badge text-bg-<?= ['sent' => 'success', 'failed' => 'danger', 'skipped' => 'secondary', 'not_configured' => 'warning'][$r['status']] ?? 'secondary' ?>"><?= e(str_replace('_', ' ', $r['status'])) ?></span></td>
            <td class="small text-muted" style="max-width:260px"><div class="text-truncate" title="<?= e($r['response']) ?>"><?= e($r['response']) ?></div></td>
            <td class="small"><?= e($r['sender'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody></table></div>
    <?php if ($pages > 1): ?><div class="card-footer bg-transparent"><ul class="pagination pagination-sm mb-0 justify-content-end">
        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page - 1]))) ?>">&laquo;</a></li>
        <?php for ($p = max(1, $page - 3); $p <= min($pages, $page + 3); $p++): ?><li class="page-item <?= $p === $page ? 'active' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $p]))) ?>"><?= $p ?></a></li><?php endfor; ?>
        <li class="page-item <?= $page >= $pages ? 'disabled' : '' ?>"><a class="page-link" href="<?= e(url($qs(['page' => $page + 1]))) ?>">&raquo;</a></li></ul></div><?php endif; ?>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
