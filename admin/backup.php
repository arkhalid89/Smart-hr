<?php
/** Database backup: generate & download a full .sql dump; keep copies in storage/backups. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/backup.php';
require_super_admin();   // a full backup holds every district's data and every user's password

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)post('action');
    if ($action === 'download') {
        set_time_limit(300);
        $sql = backup_generate_sql();
        $name = 'smart_hr_backup_' . date('Ymd_His') . '.sql';
        if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
        @file_put_contents(BACKUP_PATH . '/' . $name, $sql);
        log_activity('BACKUP', 'system', null, null, ['file' => $name, 'size' => strlen($sql)], 'Full database backup downloaded');
        if (ob_get_level()) ob_end_clean();
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }
    if ($action === 'delete' && is_super_admin()) {
        $f = basename((string)post('file'));
        if (preg_match('/^smart_hr_backup_\d{8}_\d{6}\.sql$/', $f) && is_file(BACKUP_PATH . '/' . $f)) {
            unlink(BACKUP_PATH . '/' . $f);
            log_activity('DELETE', 'system', null, null, ['file' => $f], 'Backup file deleted');
            flash('success', 'Backup file deleted.');
        }
        redirect('admin/backup.php');
    }
}
if (get('file')) {
    $f = basename((string)get('file'));
    if (preg_match('/^smart_hr_backup_\d{8}_\d{6}\.sql$/', $f) && is_file(BACKUP_PATH . '/' . $f)) {
        log_activity('BACKUP', 'system', null, null, ['file' => $f], 'Stored backup downloaded');
        header('Content-Type: application/sql'); header('Content-Disposition: attachment; filename="' . $f . '"'); header('Content-Length: ' . filesize(BACKUP_PATH . '/' . $f));
        readfile(BACKUP_PATH . '/' . $f); exit;
    }
}

$files = [];
if (is_dir(BACKUP_PATH)) {
    foreach (glob(BACKUP_PATH . '/smart_hr_backup_*.sql') as $p) $files[] = ['name' => basename($p), 'size' => filesize($p), 'time' => filemtime($p)];
    usort($files, fn($a, $b) => $b['time'] <=> $a['time']);
}
$tables = db_all('SELECT TABLE_NAME AS t, TABLE_ROWS AS rows_est, ROUND((DATA_LENGTH + INDEX_LENGTH)/1024) AS kb FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? ORDER BY TABLE_NAME', [DB_NAME]);
$totalKb = array_sum(array_column($tables, 'kb'));

$page_title  = 'Database Backup';
$active_menu = 'backup';
$breadcrumbs = ['Database Backup' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card mb-3">
            <div class="card-header"><span class="card-title"><i class="bi bi-cloud-arrow-down me-1 text-primary"></i>Full Database Backup</span></div>
            <div class="card-body">
                <p class="text-muted small">Generates a complete <code>.sql</code> dump of all <?= count($tables) ?> tables (structure + data, ≈<?= number_format($totalKb) ?> KB) using pure PHP — no server tools required. A copy is also kept in <code>storage/backups/</code>. Import with phpMyAdmin or the Reset Database tool to restore.</p>
                <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="download">
                    <button class="btn btn-primary w-100 py-2"><i class="bi bi-download me-1"></i>Generate &amp; Download Backup</button></form>
                <div class="form-text mt-2"><i class="bi bi-shield-check me-1"></i>Employee documents and photos live in <code>/uploads</code> — back that folder up separately via cPanel File Manager.</div>
            </div>
        </div>
        <div class="card">
            <div class="card-header"><span class="card-title">Tables</span></div>
            <div class="table-responsive" style="max-height:340px;overflow:auto"><table class="table table-sm mb-0"><thead><tr><th>Table</th><th class="text-end">Rows</th><th class="text-end">Size</th></tr></thead><tbody>
                <?php foreach ($tables as $t): ?><tr><td><code><?= e($t['t']) ?></code></td><td class="text-end"><?= number_format((int)$t['rows_est']) ?></td><td class="text-end"><?= number_format((int)$t['kb']) ?> KB</td></tr><?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card">
            <div class="card-header"><span class="card-title">Stored Backups <span class="badge rounded-pill text-bg-light border"><?= count($files) ?></span></span></div>
            <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                <thead><tr><th>File</th><th>Created</th><th>Size</th><th class="text-end">Actions</th></tr></thead><tbody>
                <?php if (!$files): ?><tr><td colspan="4" class="text-center text-muted py-4">No stored backups yet.</td></tr><?php endif; ?>
                <?php foreach ($files as $f): ?>
                <tr><td><code><?= e($f['name']) ?></code></td><td><?= date('d M Y, h:i A', $f['time']) ?></td><td><?= number_format($f['size'] / 1024) ?> KB</td>
                    <td class="text-end table-actions"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/backup.php?file=' . urlencode($f['name'])) ?>"><i class="bi bi-download"></i></a>
                    <?php if (is_super_admin()): ?><form method="post" class="d-inline"><?= csrf_field() ?><input type="hidden" name="action" value="delete"><input type="hidden" name="file" value="<?= e($f['name']) ?>"><button class="btn btn-sm btn-outline-danger" data-confirm="Delete this backup file?"><i class="bi bi-trash"></i></button></form><?php endif; ?></td></tr>
                <?php endforeach; ?>
            </tbody></table></div>
        </div>
    </div>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
