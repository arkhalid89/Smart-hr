<?php
/**
 * Selective data reset (Super Admin).
 * The officer chooses exactly which tables are cleared; nothing else is touched.
 * A safety backup is written first and every reset is recorded in the audit log.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/backup.php';
require_super_admin();

/**
 * Reset groups. 'tables' are cleared in the listed order (children first).
 * 'requires' pulls in dependent groups automatically so the data stays consistent.
 */
$groups = [
    'service' => ['label' => 'Service records', 'icon' => 'bi-journal-text', 'danger' => 'high',
        'desc' => 'Postings, promotions, increments, leaves, awards, punishments, inquiries, reinstatements, deputations, exits and rejoinings — plus the employee timeline and the edited order documents built from them.',
        'tables' => ['timeline_events', 'order_documents', 'postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'inquiries'],
        'after' => 'clear_employee_service'],
    'employees' => ['label' => 'Employees', 'icon' => 'bi-people', 'danger' => 'high',
        'desc' => 'Employee register with family / nominee records, uploaded documents and the sign-ins of the employees themselves. Requires the service records and the message log to be cleared as well.',
        'tables' => ['employee_family', 'employee_documents', 'employees'],
        'requires' => ['service', 'notifications'], 'after' => 'clear_employee_files'],
    'notifications' => ['label' => 'Notifications & messages', 'icon' => 'bi-send', 'danger' => 'low',
        'desc' => 'In-app alert bell items and the Email / SMS / WhatsApp delivery log.',
        'tables' => ['notifications', 'notification_log']],
    'audit' => ['label' => 'Audit logs', 'icon' => 'bi-clock-history', 'danger' => 'medium',
        'desc' => 'System activity trail (who did what, when, from which IP, with before / after values).',
        'tables' => ['audit_logs']],
    'sanctioned' => ['label' => 'Sanctioned posts', 'icon' => 'bi-clipboard-data', 'danger' => 'low',
        'desc' => 'Department × designation sanctioned strength used for the vacancy position.',
        'tables' => ['sanctioned_posts']],
    'masters' => ['label' => 'Master data', 'icon' => 'bi-database', 'danger' => 'high',
        'desc' => 'The Punjab district / tehsil / department structure, designations, pay scales, leave / award / punishment / inquiry / reinstatement types, posting offices (with map coordinates), employment types, blood groups, marital status and deputation organisations. Requires employees and sanctioned posts to be cleared.',
        'tables' => ['districts', 'tehsils', 'designations', 'pay_scales', 'leave_types', 'award_types', 'punishment_types', 'inquiry_types', 'posting_locations', 'employment_types', 'blood_groups', 'marital_statuses', 'deputation_orgs', 'reinstatement_types', 'departments'],
        'requires' => ['employees', 'sanctioned'], 'after' => 'reseed_masters'],
    'users' => ['label' => 'User accounts', 'icon' => 'bi-person-gear', 'danger' => 'high',
        'desc' => 'All login accounts except your own — officers, report viewers and employee sign-ins — with the signing profiles and signatures of officers. Your Super Admin account is always preserved.',
        'tables' => [], 'after' => 'clear_users'],
    'settings' => ['label' => 'System settings', 'icon' => 'bi-gear', 'danger' => 'medium',
        'desc' => 'Organisation details, code / reference patterns, service rules, alert windows and messaging gateway configuration — restored to factory defaults.',
        'tables' => [], 'after' => 'reset_settings'],
];

/* ---------- row counts for the UI ---------- */
$counts = [];
foreach ($groups as $k => $g) {
    $n = 0;
    foreach ($g['tables'] as $t) $n += (int)db_value("SELECT COUNT(*) FROM `" . db_ident($t) . "`", [], 0);
    if ($k === 'users')    $n = (int)db_value('SELECT COUNT(*) FROM users WHERE id <> ?', [user_id()], 0);
    if ($k === 'settings') $n = (int)db_value('SELECT COUNT(*) FROM settings', [], 0);
    $counts[$k] = $n;
}

$result = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $selected = array_values(array_intersect(array_keys($groups), (array)($_POST['groups'] ?? [])));
    /* pull in required groups */
    $changed = true;
    while ($changed) {
        $changed = false;
        foreach ($selected as $k) {
            foreach ($groups[$k]['requires'] ?? [] as $dep) {
                if (!in_array($dep, $selected, true)) { $selected[] = $dep; $changed = true; }
            }
        }
    }
    /* keep the defined order (children first) */
    $selected = array_values(array_filter(array_keys($groups), fn($k) => in_array($k, $selected, true)));

    if (!$selected) {
        flash('warning', 'Select at least one item to reset.');
        redirect('admin/reset_db.php');
    }
    if (post('confirm_text') !== 'RESET') {
        flash('danger', 'You must type RESET to confirm.');
        redirect('admin/reset_db.php');
    }
    if (!verify_password((string)post('password', ''), (string)db_value('SELECT password FROM users WHERE id = ?', [user_id()], ''))) {
        flash('danger', 'Your password is incorrect.');
        redirect('admin/reset_db.php');
    }

    set_time_limit(600);
    $steps = [];
    $cleared = [];
    try {
        $steps[] = 'Safety backup saved: ' . backup_save();
        db()->query('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($selected as $k) {
            $g = $groups[$k];
            foreach ($g['tables'] as $t) {
                $rows = (int)db_value("SELECT COUNT(*) FROM `" . db_ident($t) . "`", [], 0);
                db()->query('TRUNCATE TABLE `' . db_ident($t) . '`');
                if ($rows) $cleared[$t] = $rows;
            }
            /* group-specific follow-up work */
            switch ($g['after'] ?? '') {
                case 'clear_employee_service':
                    db_exec('UPDATE employees SET current_posting_id = NULL, status = IF(probation_start IS NOT NULL AND confirmation_date IS NULL, "Probation", "Active"), service_closed = 0, eol_break_days = 0, has_break_in_service = 0, is_lpr_eligible = 0');
                    $steps[] = 'Employee statuses re-derived after clearing service records.';
                    break;
                case 'clear_employee_files':
                    $removed = 0;
                    foreach (['photos', 'documents'] as $sub) {
                        $dir = UPLOAD_PATH . '/' . $sub;
                        if (!is_dir($dir)) continue;
                        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
                        foreach ($it as $f) { $f->isDir() ? @rmdir($f->getPathname()) : (@unlink($f->getPathname()) && $removed++); }
                    }
                    /* an employee's own sign-in goes with the register; an officer who
                       was also on it just loses the link */
                    $logins = db_exec('DELETE u FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = "employee"');
                    db_exec('UPDATE users SET employee_id = NULL');
                    $steps[] = "Uploaded photos and documents removed ($removed files); $logins employee sign-ins removed.";
                    break;
                case 'reseed_masters':
                    $n = reset_run_section(ROOT_PATH . '/database/schema.sql', ['districts', 'tehsils', 'departments', 'pay_scales', 'designations', 'leave_types', 'award_types', 'punishment_types', 'inquiry_types', 'posting_locations', 'employment_types', 'blood_groups', 'marital_statuses', 'deputation_orgs', 'reinstatement_types', 'sanctioned_posts']);
                    /* an officer whose department no longer exists keeps the account but must be re-assigned */
                    $lost = db_exec('UPDATE users u LEFT JOIN departments d ON d.id = u.department_id SET u.department_id = NULL WHERE u.department_id IS NOT NULL AND d.id IS NULL');
                    $steps[] = "Default master data restored ($n statements)." . ($lost ? " $lost officer account(s) need their department chosen again in Users & Roles." : '');
                    break;
                case 'clear_users':
                    foreach (db_all('SELECT signature_file FROM signing_profiles WHERE user_id <> ? AND signature_file IS NOT NULL', [user_id()]) as $sg) {
                        @unlink(ROOT_PATH . '/uploads/signatures/' . basename((string)$sg['signature_file']));
                    }
                    db_exec('DELETE FROM signing_profiles WHERE user_id <> ?', [user_id()]);
                    $n = db_exec('DELETE FROM users WHERE id <> ?', [user_id()]);
                    $cleared['users'] = $n;
                    break;
                case 'reset_settings':
                    $keep = db_value('SELECT setting_value FROM settings WHERE setting_key = "last_daily_check"', [], '');
                    db()->query('TRUNCATE TABLE `settings`');
                    $n = reset_run_section(ROOT_PATH . '/database/schema.sql', ['settings']);
                    setting_set('last_daily_check', (string)$keep, user_id());
                    $steps[] = "Settings restored to defaults ($n statements). Messaging gateway keys must be entered again.";
                    break;
            }
            $steps[] = 'Cleared: ' . $g['label'] . ($g['tables'] ? ' (' . implode(', ', $g['tables']) . ')' : '');
        }
        db()->query('SET FOREIGN_KEY_CHECKS = 1');

        /* optional demo data — only meaningful when employees were cleared */
        if (post('with_demo') && in_array('employees', $selected, true) && is_file(ROOT_PATH . '/database/demo_data.sql')) {
            $n = sql_run_file(ROOT_PATH . '/database/demo_data.sql');
            $steps[] = "Demo data loaded ($n statements).";
        }

        log_activity('RESET', 'system', null, ['rows' => $cleared], ['groups' => $selected], 'Selective data reset: ' . implode(', ', array_map(fn($k) => $groups[$k]['label'], $selected)));
        flash('success', 'Reset completed — ' . implode(' ', $steps));
        redirect('admin/reset_db.php');
    } catch (Throwable $e) {
        @db()->query('SET FOREIGN_KEY_CHECKS = 1');
        $result = 'Reset failed: ' . $e->getMessage() . ' — completed steps: ' . implode(' ', $steps);
    }
}

/** Run only the INSERT statements of the given tables from a .sql file. */
function reset_run_section(string $file, array $tables): int
{
    $sql = (string)file_get_contents($file);
    $n = 0;
    foreach ($tables as $t) {
        if (preg_match_all('/INSERT (?:IGNORE )?INTO\s+`?' . preg_quote($t, '/') . '`?\s*\(.*?;\s*$/mis', $sql, $m)) {
            foreach ($m[0] as $stmt) {
                if (!preg_match('/INSERT (?:IGNORE )?INTO\s+`?' . preg_quote($t, '/') . '`?\s/i', $stmt)) continue;
                db()->query(rtrim(trim($stmt), ';'));
                $n++;
            }
        }
    }
    return $n;
}

$backups = is_dir(BACKUP_PATH) ? count(glob(BACKUP_PATH . '/smart_hr_backup_*.sql')) : 0;
$page_title  = 'Reset Data';
$page_subtitle = 'Clear selected parts of the register — nothing else is touched';
$active_menu = 'reset';
$breadcrumbs = ['Administration' => null, 'Reset Data' => null];
require ROOT_PATH . '/includes/header.php';
$tone = ['high' => 'danger', 'medium' => 'warning', 'low' => 'secondary'];
?>
<?php if ($result): ?><div class="alert alert-danger"><?= e($result) ?></div><?php endif; ?>

<div class="alert alert-warning d-flex gap-2">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div><strong>This permanently deletes the selected data.</strong> A full database backup is written to <code>storage/backups/</code> before anything is cleared (<?= $backups ?> backup<?= $backups === 1 ? '' : 's' ?> currently stored). Your own Super Admin account and the login you are using are always preserved.</div>
</div>

<form method="post" novalidate id="resetForm">
    <?= csrf_field() ?>
    <div class="row g-3">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header"><span class="card-title">Select what to reset</span><button type="button" class="btn btn-ghost btn-sm" id="clearSel"><i class="bi bi-x-lg me-1"></i>Clear selection</button></div>
                <div class="list-group list-group-flush">
                    <?php foreach ($groups as $key => $g): ?>
                    <label class="list-group-item d-flex gap-3 align-items-start py-3 reset-row">
                        <input class="form-check-input mt-1 flex-shrink-0" type="checkbox" name="groups[]" value="<?= e($key) ?>" data-requires="<?= e(implode(',', $g['requires'] ?? [])) ?>">
                        <span class="stat-icon sm bg-soft-<?= $tone[$g['danger']] ?> flex-shrink-0"><i class="bi <?= $g['icon'] ?>"></i></span>
                        <span class="flex-grow-1 min-w-0">
                            <span class="d-flex flex-wrap align-items-center gap-2">
                                <span class="fw-semibold"><?= e($g['label']) ?></span>
                                <span class="badge text-bg-light border"><?= number_format($counts[$key]) ?> row<?= $counts[$key] === 1 ? '' : 's' ?></span>
                                <?php if (!empty($g['requires'])): ?><span class="badge text-bg-warning">also clears: <?= e(implode(', ', array_map(fn($r) => $groups[$r]['label'], $g['requires']))) ?></span><?php endif; ?>
                            </span>
                            <span class="d-block small text-muted mt-1"><?= e($g['desc']) ?></span>
                        </span>
                    </label>
                    <?php endforeach; ?>
                </div>
                <div class="card-footer bg-transparent">
                    <div class="form-check"><input class="form-check-input" type="checkbox" name="with_demo" id="with_demo" value="1" <?= is_file(ROOT_PATH . '/database/demo_data.sql') ? '' : 'disabled' ?>><label class="form-check-label" for="with_demo">After clearing, load the demo data set (sample employees covering every lifecycle scenario) — applies only when <em>Employees</em> is selected</label></div>
                </div>
            </div>
        </div>
        <div class="col-lg-4">
            <div class="card border-danger">
                <div class="card-header text-danger"><span class="card-title"><i class="bi bi-shield-exclamation me-1"></i>Confirm</span></div>
                <div class="card-body">
                    <div id="selSummary" class="alert alert-light border small mb-3">Nothing selected yet.</div>
                    <div class="mb-3">
                        <label class="form-label">Type <code>RESET</code> to confirm <span class="req">*</span></label>
                        <input type="text" name="confirm_text" class="form-control" autocomplete="off" required placeholder="RESET">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Your password <span class="req">*</span></label>
                        <input type="password" name="password" class="form-control" required autocomplete="current-password">
                    </div>
                    <button class="btn btn-danger w-100" id="resetBtn" disabled><i class="bi bi-eraser me-1"></i>Reset selected data</button>
                    <a href="<?= url('admin/backup.php') ?>" class="btn btn-light w-100 mt-2"><i class="bi bi-cloud-arrow-down me-1"></i>Take a backup first</a>
                </div>
            </div>
        </div>
    </div>
</form>

<?php
$labels = json_encode(array_map(fn($g) => $g['label'], $groups));
$extra_js = <<<JS
<script>
(function(){
  const LABELS = $labels;
  const \$boxes = $('#resetForm input[name="groups[]"]');
  function resolve(){
    // auto-tick dependencies
    let added = true;
    while (added) {
      added = false;
      \$boxes.filter(':checked').each(function(){
        (this.dataset.requires || '').split(',').filter(Boolean).forEach(dep => {
          const d = \$boxes.filter('[value="' + dep + '"]');
          if (d.length && !d.prop('checked')) { d.prop('checked', true); added = true; }
        });
      });
    }
    const sel = \$boxes.filter(':checked').map((i, el) => LABELS[el.value]).get();
    \$boxes.each(function(){ \$(this).closest('.reset-row').toggleClass('bg-danger-subtle', this.checked); });
    \$('#selSummary').html(sel.length
      ? '<strong>Will be cleared:</strong><br>' + sel.map(s => '• ' + s).join('<br>')
      : 'Nothing selected yet.').toggleClass('alert-danger', sel.length > 0).toggleClass('alert-light', sel.length === 0);
    \$('#resetBtn').prop('disabled', sel.length === 0);
  }
  \$boxes.on('change', resolve);
  \$('#clearSel').on('click', () => { \$boxes.prop('checked', false); resolve(); });
  \$('#resetForm').on('submit', function(e){
    const sel = \$boxes.filter(':checked').map((i, el) => LABELS[el.value]).get();
    if (this.dataset.ok === '1') return;
    e.preventDefault();
    HR.confirm('Permanently delete: ' + sel.join(', ') + '? A backup is taken first, but this cannot be undone.').then(ok => { if (ok) { this.dataset.ok = '1'; this.submit(); } });
  });
  resolve();
})();
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
