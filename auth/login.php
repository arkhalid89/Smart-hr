<?php
require_once __DIR__ . '/../includes/bootstrap.php';

if (is_logged_in()) {
    redirect('dashboard/index.php');
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $username = (string)post('username', '');
    $password = (string)post('password', '');
    if ($username === '' || $password === '') {
        $error = 'Please enter both username and password.';
    } else {
        [$ok, $msg] = attempt_login($username, $password);
        if ($ok) {
            // login-time automated checks (contract expiry, LPR flags ...)
            if (is_file(ROOT_PATH . '/cron/daily.php')) {
                require_once ROOT_PATH . '/cron/daily.php';
                if (function_exists('run_daily_checks')) { run_daily_checks(false); }
            }
            flash('success', $msg);
            $to = $_SESSION['_redirect_after_login'] ?? 'dashboard/index.php';
            unset($_SESSION['_redirect_after_login']);
            redirect(ltrim($to, '/') ?: 'dashboard/index.php');
        }
        $error = $msg;
    }
}
$orgName = setting('org_name', ORG_NAME);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem("hr_theme");if(!t)t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";document.documentElement.setAttribute("data-bs-theme",t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in | <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
<style>body{padding:0}</style>
</head>
<body>
<div class="gov-strip" aria-hidden="true"></div>
<div class="login-shell">

    <!-- ===== Brand panel ===== -->
    <aside class="login-brand">
        <span class="lb-shape lb-1"></span><span class="lb-shape lb-2"></span>
        <span class="lb-shape lb-3"></span><span class="lb-shape lb-4"></span>

        <div>
            <div class="d-flex align-items-center gap-3">
                <span class="lb-emblem"><img src="<?= asset('img/logo.svg') ?>" alt=""></span>
                <div>
                    <div class="lb-org">Government of the Punjab</div>
                    <div style="font-size:14px;opacity:.9"><?= e($orgName) ?></div>
                </div>
            </div>
            <h1 class="lb-title"><?= e(APP_NAME) ?></h1>
            <p class="lb-sub">Centralised employee lifecycle &amp; service record management — from appointment and probation through postings, promotions, leave and retirement — fully compliant with Punjab Civil Servants Rules and S&amp;GAD regulations.</p>
            <ul class="lb-points">
                <li><i class="bi bi-shield-check"></i><span>Immutable service history with a unique reference number for every record</span></li>
                <li><i class="bi bi-people"></i><span>Department-wise access control, sanctioned strength and vacancy position</span></li>
                <li><i class="bi bi-file-earmark-text"></i><span>Service book, roster, pension and LPR reports ready for submission</span></li>
            </ul>
        </div>
        <div class="lb-foot">
            <i class="bi bi-lock me-1"></i>Authorised use only. All activity is recorded in the system audit log.
            <div class="mt-1">v<?= e(APP_VERSION) ?> &middot; &copy; <?= date('Y') ?> <?= e($orgName) ?></div>
        </div>
    </aside>

    <!-- ===== Form panel ===== -->
    <main class="login-form-pane position-relative">
        <button class="btn btn-icon theme-fab" type="button" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
        <div class="login-form">
            <div class="d-lg-none mb-4 d-flex align-items-center gap-2">
                <img src="<?= asset('img/logo.svg') ?>" alt="" width="36" height="36">
                <div><div class="fw-bold" style="font-size:15px"><?= e(APP_NAME) ?></div><div class="small text-muted"><?= e($orgName) ?></div></div>
            </div>

            <h2 class="login-title">Welcome back</h2>
            <p class="login-sub">Sign in with your official credentials to continue.</p>

            <?= flash_render() ?>
            <?php if ($error): ?>
                <div class="alert alert-danger py-2 small d-flex gap-2"><i class="bi bi-exclamation-triangle-fill"></i><span><?= e($error) ?></span></div>
            <?php endif; ?>

            <form method="post" autocomplete="off" novalidate>
                <?= csrf_field() ?>
                <div class="mb-3">
                    <label class="form-label" for="username">Username</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-person"></i></span>
                        <input type="text" class="form-control" id="username" name="username" value="<?= e(post('username', '')) ?>" required autofocus autocapitalize="none" spellcheck="false">
                    </div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="password">Password</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="bi bi-lock"></i></span>
                        <input type="password" class="form-control" id="password" name="password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePw" tabindex="-1" aria-label="Show password"><i class="bi bi-eye"></i></button>
                    </div>
                </div>
                <button type="submit" class="btn btn-primary w-100 fw-semibold">Sign in <i class="bi bi-arrow-right ms-1"></i></button>
            </form>

            <div class="login-help">
                <div class="d-flex align-items-start gap-2 mb-2"><i class="bi bi-info-circle mt-1"></i><span>Forgot your password? Contact the system administrator of your office — passwords are reset from <em>Administration → Users &amp; Roles</em>.</span></div>
                <a href="<?= url('landing.php') ?>" class="text-decoration-none"><i class="bi bi-arrow-left me-1"></i>Back to information page</a>
            </div>
        </div>
    </main>
</div>
<script>
document.getElementById('themeToggle').addEventListener('click', function(){ const h = document.documentElement; const t = h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'; h.setAttribute('data-bs-theme', t); try { localStorage.setItem('hr_theme', t); } catch(e){} this.firstElementChild.className = t === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; });
if (document.documentElement.getAttribute('data-bs-theme') === 'dark') document.querySelector('#themeToggle i').className = 'bi bi-sun';
document.getElementById('togglePw').addEventListener('click', function () {
    const p = document.getElementById('password');
    p.type = p.type === 'password' ? 'text' : 'password';
    this.firstElementChild.className = p.type === 'password' ? 'bi bi-eye' : 'bi bi-eye-slash';
});
</script>
</body>
</html>
