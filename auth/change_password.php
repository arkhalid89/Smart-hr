<?php
define('ALLOW_PASSWORD_CHANGE_PAGE', true);
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$uid = user_id();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $current = (string)post('current_password', '');
    $new     = (string)post('new_password', '');
    $confirm = (string)post('confirm_password', '');
    $stored  = (string)db_value('SELECT password FROM users WHERE id = ?', [$uid], '');

    if (!verify_password($current, $stored)) {
        flash('danger', 'Current password is incorrect.');
    } elseif (strlen($new) < 6) {
        flash('danger', 'New password must be at least 6 characters.');
    } elseif ($new !== $confirm) {
        flash('danger', 'New password and confirmation do not match.');
    } elseif ($new === $current) {
        flash('warning', 'New password must be different from the current one.');
    } else {
        db_update('users', ['password' => $new, 'must_change_password' => 0], 'id = ?', [$uid]);   // plain text per current requirement
        log_activity('UPDATE', 'users', $uid, null, null, 'Password changed');
        refresh_session_user();
        flash('success', 'Password changed successfully.');
        redirect(is_employee_user() ? 'employees/view.php?id=' . (int)user_employee_id() : 'auth/profile.php');
    }
    redirect('auth/change_password.php');
}

$page_title  = 'Change Password';
$breadcrumbs = ['My Profile' => 'auth/profile.php', 'Change Password' => null];
require ROOT_PATH . '/includes/header.php';
?>
<div class="row justify-content-center">
    <div class="col-lg-5 col-md-7">
        <div class="card">
            <div class="card-header"><span class="card-title">Change Password</span></div>
            <div class="card-body">
                <form method="post" novalidate>
                    <?= csrf_field() ?>
                    <div class="mb-3">
                        <label class="form-label">Current Password <span class="req">*</span></label>
                        <input type="password" name="current_password" class="form-control" required autocomplete="current-password">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">New Password <span class="req">*</span></label>
                        <input type="password" name="new_password" class="form-control" required minlength="6" autocomplete="new-password">
                        <div class="form-text">Minimum 6 characters.</div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label">Confirm New Password <span class="req">*</span></label>
                        <input type="password" name="confirm_password" class="form-control" required minlength="6" autocomplete="new-password">
                    </div>
                    <div class="d-flex gap-2">
                        <button class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Update Password</button>
                        <a href="<?= url('auth/profile.php') ?>" class="btn btn-light">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
<?php require ROOT_PATH . '/includes/footer.php'; ?>
