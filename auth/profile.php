<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$uid  = user_id();
$acct = db_row('SELECT u.*, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [$uid]);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $data = [
        'full_name' => (string)post('full_name', ''),
        'email'     => nullable(post('email')),
        'phone'     => nullable(post('phone')),
    ];
    if ($data['full_name'] === '') {
        flash('danger', 'Full name is required.');
    } elseif ($data['email'] && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
        flash('danger', 'Please enter a valid email address.');
    } else {
        db_update('users', $data, 'id = ?', [$uid]);
        log_activity('UPDATE', 'users', $uid, $acct, array_merge($acct, $data), 'Profile updated');
        refresh_session_user();
        flash('success', 'Profile updated successfully.');
    }
    redirect('auth/profile.php');
}

$jurisdiction = jurisdiction_label();
$employee = $acct['employee_id'] ? db_row('SELECT e.*, d.name AS department, g.name AS designation FROM employees e LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN designations g ON g.id = e.designation_id WHERE e.id = ?', [(int)$acct['employee_id']]) : null;

$page_title  = 'My Profile';
$breadcrumbs = ['My Profile' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="row g-3">
    <div class="col-lg-5">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Account Details</span></div>
            <div class="card-body">
                <div class="d-flex align-items-center gap-3 mb-3">
                    <span class="avatar-initials" style="width:56px;height:56px;font-size:22px"><?= e(strtoupper(mb_substr($acct['full_name'], 0, 1))) ?></span>
                    <div>
                        <div class="fw-semibold fs-5"><?= e($acct['full_name']) ?></div>
                        <span class="badge text-bg-primary"><?= e($acct['role_label']) ?></span>
                    </div>
                </div>
                <dl class="dl-grid">
                    <dt>Username</dt><dd><?= e($acct['username']) ?></dd>
                    <dt>Email</dt><dd><?= e($acct['email'] ?: '-') ?></dd>
                    <dt>Phone</dt><dd><?= e($acct['phone'] ?: '-') ?></dd>
                    <dt>Last login</dt><dd><?= fmt_datetime($acct['last_login_at']) ?> <span class="text-muted small">(<?= e($acct['last_login_ip'] ?: '-') ?>)</span></dd>
                    <dt>Member since</dt><dd><?= fmt_date($acct['created_at']) ?></dd>
                    <?php if ($jurisdiction !== ''): ?>
                    <dt>Jurisdiction</dt>
                    <dd><span class="badge text-bg-secondary"><?= e($jurisdiction) ?></span></dd>
                    <?php endif; ?>
                </dl>
                <?php if ($employee): ?>
                <hr>
                <div class="form-section-title">Linked Employee Profile</div>
                <dl class="dl-grid">
                    <dt>Employee Code</dt><dd><?= e($employee['employee_code']) ?></dd>
                    <dt>Designation</dt><dd><?= e($employee['designation']) ?></dd>
                    <dt>Department</dt><dd><?= e($employee['department']) ?></dd>
                    <dt>Status</dt><dd><?= status_badge($employee['status']) ?></dd>
                </dl>
                <a href="<?= url('employees/view.php?id=' . $employee['id']) ?>" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-person-vcard me-1"></i>Open Employee Profile</a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="col-lg-7">
        <div class="card h-100">
            <div class="card-header"><span class="card-title">Edit Profile</span></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="row g-3">
                        <div class="col-md-12">
                            <label class="form-label">Full Name <span class="req">*</span></label>
                            <input type="text" name="full_name" class="form-control" value="<?= e($acct['full_name']) ?>" required maxlength="120">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Email</label>
                            <input type="email" name="email" class="form-control" value="<?= e($acct['email']) ?>" maxlength="120">
                        </div>
                        <div class="col-md-6">
                            <label class="form-label">Phone</label>
                            <input type="text" name="phone" class="form-control" value="<?= e($acct['phone']) ?>" maxlength="30">
                        </div>
                    </div>
                    <div class="mt-4 d-flex gap-2">
                        <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Changes</button>
                        <a href="<?= url('auth/change_password.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-key me-1"></i>Change Password</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
