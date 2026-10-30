<?php
/**
 * Users & Roles (Super Admin).
 *
 *   Officer         belongs to one department and reaches what that
 *                   department's level reaches:
 *                     district level (e.g. DC Office)   the whole district
 *                     tehsil level   (e.g. AC Office)   the whole tehsil
 *                     department level                  that department only
 *                   Every officer is a signing authority; officers at district
 *                   or tehsil level also publish service records.
 *   Report Viewer   belongs to a department too and reaches what its level
 *                   reaches, but only reads reports and profiles
 *   Employee        one per employee — sees only their own record
 *   Super Admin     not tied to a place
 *
 * Employee sign-ins are created automatically when an employee is registered;
 * this page is where their temporary password can be read out or reset.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();

$roles     = db_all('SELECT * FROM roles ORDER BY FIELD(name, "super_admin", "officer", "report_viewer", "employee"), id');
$roleById  = array_column($roles, null, 'id');

const ROLE_STYLE = [
    'super_admin'   => ['danger',    'bi-shield-lock',    'full access, system settings'],
    'officer'       => ['success',   'bi-bank',           'their department\'s reach, signing authority'],
    'report_viewer' => ['info',      'bi-bar-chart',      "reports within their department's reach"],
    'employee'      => ['secondary', 'bi-person-badge',   'own record only'],
];

/* ---------- actions ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = (string)post('action');
    $id = post_int('id', 0);
    $isAjax = is_ajax();
    $done = function (string $msg, bool $ok = true) use ($isAjax) {
        if ($isAjax) $ok ? json_ok($msg) : json_error($msg);
        flash($ok ? 'success' : 'danger', $msg);
        redirect('admin/users.php');
    };

    if ($action === 'save') {
        $roleId   = post_int('role_id', 0);
        $roleName = $roleById[$roleId]['name'] ?? null;
        if (!$roleName) $done('Choose a role.', false);

        $d = [
            'username'    => strtolower(trim((string)post('username', ''))),
            'full_name'   => trim((string)post('full_name', '')),
            'email'       => nullable(post('email')),
            'phone'       => nullable(post('phone')),
            'role_id'     => $roleId,
            'employee_id' => post_int('employee_id'),
            'department_id' => null,
        ];
        $password = (string)post('password', '');

        /* an officer's or report viewer's reach is the level of their department */
        if (in_array($roleName, ['officer', 'report_viewer'], true)) {
            $d['department_id'] = post_int('department_id');
            if (!$d['department_id'] || !db_value('SELECT id FROM departments WHERE id = ? AND is_deleted = 0', [$d['department_id']])) {
                $done('Choose the department — district, then tehsil, then department.', false);
            }
        }
        if ($roleName === 'employee' && !$d['employee_id']) $done('Choose the employee this sign-in belongs to.', false);

        if (!preg_match('/^[a-z0-9._-]{3,60}$/', $d['username'])) $done('Username must be 3-60 characters: letters, numbers, dot, dash or underscore.', false);
        if ($d['full_name'] === '') $done('Full name is required.', false);
        if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $done('Email is invalid.', false);
        if (db_value('SELECT id FROM users WHERE username = ? AND id <> ?', [$d['username'], $id])) $done('Username already exists.', false);
        if ($d['employee_id'] && !db_value('SELECT id FROM employees WHERE id = ? AND is_deleted = 0', [$d['employee_id']])) $done('Linked employee not found.', false);
        if ($d['employee_id'] && db_value('SELECT id FROM users WHERE employee_id = ? AND id <> ? AND is_deleted = 0', [$d['employee_id'], $id])) $done('That employee already has a sign-in.', false);

        if ($id) {
            $old = db_find('users', $id);
            if (!$old) $done('User not found.', false);
            if ($id === user_id() && $roleName !== 'super_admin') $done('You cannot change your own role.', false);
            if ($password !== '') {
                if (strlen($password) < 6) $done('Password must be at least 6 characters.', false);
                $d['password'] = $password;
                /* a password set by the Super Admin is temporary for anyone else */
                if ($id !== user_id()) $d['must_change_password'] = 1;
            }
            db_update('users', $d, 'id = ?', [$id]);
            log_activity('UPDATE', 'users', $id, $old, array_merge($old, $d), 'User updated: ' . $d['username']);
        } else {
            if (strlen($password) < 6) $done('Password must be at least 6 characters.', false);
            $d['password'] = $password;
            $d['must_change_password'] = 1;
            $d['created_by'] = user_id();
            $id = db_insert('users', $d);
            log_activity('CREATE', 'users', $id, null, $d, 'User created: ' . $d['username']);
        }
        if ($id === user_id()) refresh_session_user();
        $done('User saved.' . ($password !== '' && $id !== user_id() ? ' They will be asked to choose their own password at next sign-in.' : ''));
    }

    $u = $id ? db_find('users', $id) : null;
    if (!$u) $done('User not found.', false);
    if ($id === user_id()) $done('You cannot modify your own account status here.', false);

    if ($action === 'reset_password') {
        $temp = employee_temp_password();
        db_update('users', ['password' => $temp, 'must_change_password' => 1], 'id = ?', [$id]);
        log_activity('UPDATE', 'users', $id, null, ['password' => 'reset'], 'Password reset for ' . $u['username']);
        $done('Temporary password for ' . $u['username'] . ' is ' . $temp . ' — they must change it at first sign-in.');
    }
    if ($action === 'toggle') {
        $new = $u['is_active'] ? 0 : 1;
        db_update('users', ['is_active' => $new], 'id = ?', [$id]);
        log_activity('UPDATE', 'users', $id, ['is_active' => $u['is_active']], ['is_active' => $new], 'User ' . ($new ? 'activated' : 'deactivated') . ': ' . $u['username']);
        $done('User ' . ($new ? 'activated.' : 'deactivated.'));
    }
    if ($action === 'delete') {
        db_update('users', ['is_deleted' => 1, 'is_active' => 0, 'deleted_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);
        log_activity('DELETE', 'users', $id, $u, null, 'User moved to recycle bin: ' . $u['username']);
        $done('User moved to the recycle bin.');
    }
    if ($action === 'restore') {
        db_update('users', ['is_deleted' => 0, 'is_active' => 1, 'deleted_at' => null], 'id = ?', [$id]);
        log_activity('RESTORE', 'users', $id, null, $u, 'User restored: ' . $u['username']);
        $done('User restored.');
    }
    if ($action === 'hard_delete') {
        if (!$u['is_deleted']) $done('Move the user to the recycle bin first.', false);
        if ((int)$u['id'] === 1) $done('The primary Super Admin cannot be deleted.', false);
        db_exec('DELETE FROM signing_profiles WHERE user_id = ?', [$id]);
        db_exec('DELETE FROM users WHERE id = ?', [$id]);
        log_activity('HARD_DELETE', 'users', $id, $u, null, 'User permanently deleted: ' . $u['username']);
        $done('User permanently deleted.');
    }
    $done('Unknown action.', false);
}

/* ---------- AJAX form ---------- */
if (get('action') === 'form') {
    $id = get_int('id', 0);
    $u  = $id ? db_find('users', $id) : [];
    $empName = !empty($u['employee_id']) ? db_value('SELECT CONCAT(name, " — ", employee_code) FROM employees WHERE id = ?', [(int)$u['employee_id']]) : '';
    $curRole = (int)($u['role_id'] ?? (int)db_value('SELECT id FROM roles WHERE name = "officer"'));
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <div class="col-md-6"><label class="form-label">Role <span class="req">*</span></label><select name="role_id" id="u_role" class="form-select" required>
        <?php foreach ($GLOBALS['roles'] as $r): ?><option value="<?= $r['id'] ?>" data-name="<?= e($r['name']) ?>" <?= $curRole === (int)$r['id'] ? 'selected' : '' ?>><?= e($r['label']) ?></option><?php endforeach; ?></select>
        <div class="form-text" id="u_role_hint"></div></div>

    <div class="col-12 f-department"><div class="form-section-title mb-2">Department</div>
        <?= geo_picker(['name' => 'department_id', 'value' => $u['department_id'] ?? null, 'required' => false, 'id' => 'ugeo', 'emit' => false]) ?>
        <div class="form-text">The department's level decides the reach: a district-level office sees the whole district, a tehsil-level office the tehsil, anything else only itself.</div></div>

    <div class="col-md-6"><label class="form-label">Full Name <span class="req">*</span></label><input type="text" name="full_name" class="form-control" value="<?= e($u['full_name'] ?? '') ?>" required maxlength="120"></div>
    <div class="col-md-6"><label class="form-label">Username <span class="req">*</span></label><input type="text" name="username" class="form-control" value="<?= e($u['username'] ?? '') ?>" required maxlength="60" pattern="[a-z0-9._-]{3,60}"><div class="form-text f-employee-hint">Employees sign in with their CNIC digits.</div></div>
    <div class="col-md-6"><label class="form-label">Password <?= $id ? '<span class="text-muted fw-normal">(leave blank to keep)</span>' : '<span class="req">*</span>' ?></label><input type="text" name="password" class="form-control" autocomplete="new-password" minlength="6" <?= $id ? '' : 'required' ?>><div class="form-text">Temporary — the user chooses their own at next sign-in.</div></div>
    <div class="col-md-6"><label class="form-label">Email</label><input type="email" name="email" class="form-control" value="<?= e($u['email'] ?? '') ?>" maxlength="120"></div>
    <div class="col-md-6"><label class="form-label">Phone</label><input type="text" name="phone" class="form-control" value="<?= e($u['phone'] ?? '') ?>" maxlength="30"></div>
    <div class="col-12"><label class="form-label">Employee record <span class="text-muted fw-normal f-employee-opt">(optional — an officer can also be on the register)</span><span class="req f-employee-req">*</span></label>
        <select name="employee_id" id="u_employee" class="form-select" data-placeholder="Search employee…"><?php if ($empName): ?><option value="<?= (int)$u['employee_id'] ?>" selected><?= e($empName) ?></option><?php endif; ?></select></div>
    <?php
    exit;
}

$showDeleted = get('deleted') === '1';
$group = get('group') === 'employees' ? 'employees' : 'officers';
$users = db_all('SELECT u.*, r.label AS role_label, r.name AS role_name, e.name AS emp_name, e.employee_code,
                        dp.name AS department_name, dp.level AS department_level, ds.name AS district_name, t.name AS tehsil_name
                   FROM users u JOIN roles r ON r.id = u.role_id
                   LEFT JOIN employees e    ON e.id = u.employee_id
                   LEFT JOIN departments dp ON dp.id = u.department_id
                   LEFT JOIN districts ds   ON ds.id = dp.district_id
                   LEFT JOIN tehsils t      ON t.id = dp.tehsil_id
                  WHERE u.is_deleted = ? AND ' . ($group === 'employees' ? 'r.name = "employee"' : 'r.name <> "employee"') . '
                  ORDER BY FIELD(r.name, "super_admin", "officer", "report_viewer", "employee"), FIELD(dp.level, "district", "tehsil", "department"), u.full_name', [$showDeleted ? 1 : 0]);
$unassigned = (int)db_value('SELECT COUNT(*) FROM users u JOIN roles r ON r.id = u.role_id
                              WHERE u.is_deleted = 0 AND u.is_active = 1 AND r.name IN ("officer", "report_viewer") AND u.department_id IS NULL', [], 0);

$page_title  = 'Users & Roles';
$active_menu = 'users';
$breadcrumbs = ['Administration' => null, 'Users & Roles' => null];
$page_actions = '<a href="' . url('admin/users.php?group=' . $group . ($showDeleted ? '' : '&deleted=1')) . '" class="btn btn-outline-secondary btn-sm"><i class="bi bi-recycle me-1"></i>' . ($showDeleted ? 'Active users' : 'Recycle bin') . '</a>'
    . '<button class="btn btn-primary btn-sm" id="btnAddUser"><i class="bi bi-person-plus me-1"></i>New User</button>';
require ROOT_PATH . '/includes/header.php';
echo geo_data_script();
?>
<?php if ($unassigned): ?>
<div class="alert alert-warning d-flex gap-2 align-items-start">
    <i class="bi bi-exclamation-triangle-fill mt-1"></i>
    <div><strong><?= $unassigned ?> officer / report viewer account<?= $unassigned === 1 ? ' has' : 's have' ?> no department.</strong>
        Without a department they see no employees (and an officer cannot sign). Edit the account and choose one.</div>
</div>
<?php endif; ?>

<div class="row g-3 mb-3">
    <?php foreach ($roles as $r): [$c, $icon, $desc] = ROLE_STYLE[$r['name']] ?? ['secondary', 'bi-person', '']; $n = (int)db_value('SELECT COUNT(*) FROM users WHERE role_id = ? AND is_deleted = 0', [(int)$r['id']], 0); ?>
    <div class="col-6 col-lg"><div class="card stat-card h-100"><div class="stat-icon bg-soft-<?= $c ?>"><i class="bi <?= $icon ?>"></i></div>
        <div><div class="stat-value"><?= $n ?></div><div class="stat-label"><?= e($r['label']) ?><br><span class="text-muted"><?= e($desc) ?></span></div></div></div></div>
    <?php endforeach; ?>
</div>

<ul class="nav nav-pills mb-3">
    <li class="nav-item"><a class="nav-link <?= $group === 'officers' ? 'active' : '' ?>" href="<?= url('admin/users.php' . ($showDeleted ? '?deleted=1' : '')) ?>"><i class="bi bi-person-gear me-1"></i>Officers &amp; staff accounts</a></li>
    <li class="nav-item"><a class="nav-link <?= $group === 'employees' ? 'active' : '' ?>" href="<?= url('admin/users.php?group=employees' . ($showDeleted ? '&deleted=1' : '')) ?>"><i class="bi bi-person-badge me-1"></i>Employee sign-ins</a></li>
</ul>

<div class="card">
    <?php if ($group === 'employees' && !$showDeleted): ?>
    <div class="card-header"><span class="card-title">Employee sign-ins</span>
        <span class="small text-muted">Created automatically when an employee is registered · username is the CNIC without dashes</span></div>
    <?php endif; ?>
    <div class="table-responsive"><table class="table table-hover align-middle mb-0 datatable">
        <thead><tr><th style="width:40px">#</th><th>User</th><th>Username</th><th>Role</th><th><?= $group === 'employees' ? 'Employee' : 'Department &amp; reach' ?></th>
            <?php if ($group === 'employees'): ?><th>First sign-in</th><?php else: ?><th>Linked Employee</th><?php endif; ?>
            <th>Last Login</th><th>Status</th><th class="text-end no-sort">Actions</th></tr></thead>
        <tbody>
        <?php foreach ($users as $i => $u): [$c] = ROLE_STYLE[$u['role_name']] ?? ['secondary']; ?>
            <tr><td class="text-muted"><?= $i + 1 ?></td>
                <td><div class="d-flex align-items-center gap-2"><span class="avatar-initials" style="width:30px;height:30px;font-size:13px"><?= e(strtoupper(mb_substr($u['full_name'], 0, 1))) ?></span><div><div class="fw-semibold"><?= e($u['full_name']) ?></div><div class="small text-muted"><?= e($u['email'] ?: '') ?></div></div></div></td>
                <td><code><?= e($u['username']) ?></code></td>
                <td><span class="badge text-bg-<?= $c ?>"><?= e($u['role_label']) ?></span></td>
                <td class="small">
                    <?php if ($u['role_name'] === 'employee'): ?>
                        <?= $u['emp_name'] ? '<a href="' . url('employees/view.php?id=' . $u['employee_id']) . '">' . e($u['emp_name']) . '</a><br><span class="text-muted">' . e($u['employee_code']) . '</span>' : '<span class="text-danger">record missing</span>' ?>
                    <?php elseif (in_array($u['role_name'], ['officer', 'report_viewer'], true)): ?>
                        <?php if ($u['department_name']): ?>
                            <?= e($u['department_name']) ?>
                            <br><span class="badge <?= $u['department_level'] === 'department' ? 'bg-secondary-subtle text-secondary-emphasis' : 'bg-success-subtle text-success-emphasis' ?> border"><?= e(level_label($u['department_level'])) ?></span>
                            <span class="text-muted"><?= e(($u['tehsil_name'] ? $u['tehsil_name'] . ', ' : '') . $u['district_name']) ?></span>
                        <?php else: ?><span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">No department assigned</span><?php endif; ?>
                    <?php else: ?><span class="text-muted">Whole province</span><?php endif; ?>
                </td>
                <?php if ($group === 'employees'): ?>
                <td class="small"><?php if ((int)$u['must_change_password']): ?>
                    <span class="text-muted">Temporary password</span><br><code class="user-select-all"><?= e($u['password']) ?></code>
                <?php else: ?><span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Own password set</span><?php endif; ?></td>
                <?php else: ?>
                <td class="small"><?= $u['emp_name'] ? '<a href="' . url('employees/view.php?id=' . $u['employee_id']) . '">' . e($u['emp_name']) . '</a><br><span class="text-muted">' . e($u['employee_code']) . '</span>' : '-' ?></td>
                <?php endif; ?>
                <td class="small"><?= fmt_datetime($u['last_login_at']) ?></td>
                <td><?= $showDeleted ? '<span class="badge text-bg-dark">Deleted</span>' : ($u['is_active'] ? '<span class="badge text-bg-success">Active</span>' : '<span class="badge text-bg-secondary">Inactive</span>') ?></td>
                <td class="text-end table-actions">
                    <?php if ($showDeleted): ?>
                        <button class="btn btn-ghost btn-sm text-success" data-uact="restore" data-id="<?= $u['id'] ?>" title="Restore"><i class="bi bi-arrow-counterclockwise"></i></button>
                        <button class="btn btn-ghost btn-sm text-danger" data-uact="hard_delete" data-id="<?= $u['id'] ?>" title="Delete permanently"><i class="bi bi-trash3"></i></button>
                    <?php else: ?>
                        <button class="btn btn-ghost btn-sm" data-uact="edit" data-id="<?= $u['id'] ?>" title="Edit"><i class="bi bi-pencil"></i></button>
                        <?php if ((int)$u['id'] !== user_id()): ?>
                        <button class="btn btn-ghost btn-sm" data-uact="reset_password" data-id="<?= $u['id'] ?>" title="Issue a temporary password"><i class="bi bi-key"></i></button>
                        <?php if ($u['role_name'] === 'officer' && $u['department_name']): ?>
                        <a class="btn btn-ghost btn-sm" href="<?= url('admin/signing_profile.php?user=' . (int)$u['id']) ?>" title="Signing profile"><i class="bi bi-pen"></i></a>
                        <?php endif; ?>
                        <button class="btn btn-sm btn-outline-<?= $u['is_active'] ? 'warning' : 'success' ?>" data-uact="toggle" data-id="<?= $u['id'] ?>" title="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>"><i class="bi bi-<?= $u['is_active'] ? 'pause-circle' : 'play-circle' ?>"></i></button>
                        <button class="btn btn-ghost btn-sm text-danger" data-uact="delete" data-id="<?= $u['id'] ?>" title="Delete"><i class="bi bi-trash"></i></button>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table></div>
</div>

<div class="modal fade" id="userModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-centered"><form class="modal-content" id="userForm" novalidate>
    <div class="modal-header"><h5 class="modal-title" id="userModalTitle">New User</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id" id="userId" value="0"><div class="row g-3" id="userFields"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save User</button></div>
</form></div></div>

<?php
$extra_js = <<<JS
<script>
(function(){
  const modal = new bootstrap.Modal('#userModal');
  const hints = {
    super_admin:   'Full access, including system settings and all districts.',
    officer:       'Reaches what their department reaches. District- and tehsil-level officers publish service records and orders; every officer signs.',
    report_viewer: 'Read-only reports and profiles, limited to what their department reaches — district, tehsil or the department itself.',
    employee:      'Sees only their own profile, postings, posting orders and notifications.'
  };
  function fields(){
    const r = $('#u_role').find(':selected').data('name');
    const scoped = r === 'officer' || r === 'report_viewer';
    $('.f-department').toggle(scoped);
    $('.f-department [data-geo=district], .f-department [data-geo=department]').prop('required', scoped);
    $('.f-employee-req').toggle(r === 'employee');
    $('.f-employee-opt').toggle(r !== 'employee');
    $('.f-employee-hint').toggle(r === 'employee');
    $('#u_role_hint').text(hints[r] || '');
  }
  function open(id){
    $('#userId').val(id); $('#userModalTitle').text(id ? 'Edit User' : 'New User'); $('#userFields').html('<div class="text-muted">Loading…</div>'); modal.show();
    $.get(HR.url('admin/users.php'), { action: 'form', id: id }, html => {
      $('#userFields').html(html); HR.initWidgets('#userFields'); HR.initGeo('#userFields');
      const \$e = $('#u_employee'); \$e.select2({ theme: 'bootstrap-5', width: '100%', allowClear: true, placeholder: \$e.data('placeholder'), dropdownParent: $('#userModal'), minimumInputLength: 2,
        ajax: { url: HR.url('employees/ajax/select.php'), dataType: 'json', delay: 250, data: p => ({ q: p.term }), processResults: d => d } });
      $('#u_role').on('change', fields); fields();
    });
  }
  $('#btnAddUser').on('click', () => open(0));
  $(document).on('click', '[data-uact]', function(){
    const a = this.dataset.uact, id = this.dataset.id;
    if (a === 'edit') return open(id);
    const msgs = { delete: 'Move this user to the recycle bin? They will no longer be able to sign in.',
                   hard_delete: 'Permanently delete this user? This cannot be undone.',
                   reset_password: 'Issue a new temporary password? The current password stops working immediately.' };
    const run = () => HR.post('admin/users.php', { action: a, id: id }).done(r => {
      HR.toast(r.message, r.success ? 'success' : 'danger', a === 'reset_password' ? 12000 : undefined);
      if (r.success && a !== 'reset_password') setTimeout(() => location.reload(), 500);
      if (r.success && a === 'reset_password') setTimeout(() => location.reload(), 12000);
    });
    msgs[a] ? HR.confirm(msgs[a]).then(ok => ok && run()) : run();
  });
  $('#userForm').on('submit', function(e){ e.preventDefault();
    HR.post('admin/users.php', $(this).serialize() + '&action=save').done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); if (r.success) { modal.hide(); setTimeout(() => location.reload(), 700); } }); });
})();
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
