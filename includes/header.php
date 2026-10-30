<?php
/**
 * Global layout: <head> (all 3rd-party CSS via CDN), fixed top header,
 * left sidebar and the opening of the content area.
 *
 * Variables a page may set before including:
 *   $page_title   string
 *   $active_menu  string  key of the sidebar item to highlight
 *   $breadcrumbs  array   ['Label' => 'path' | null]
 *   $extra_css    string  extra <link>/<style> tags
 */
declare(strict_types=1);
if (!isset($page_title))  $page_title  = APP_NAME;
if (!isset($active_menu)) $active_menu = '';
if (!isset($breadcrumbs)) $breadcrumbs = [];
if (!isset($page_subtitle)) $page_subtitle = '';

$user = current_user(); // NOTE: pages must not reuse the $user variable name
$orgName = setting('org_name', ORG_NAME);

/* -------- sidebar menu definition (role based) -------- */
$ownEmp = user_employee_id();
$menu = [
    /* -- an employee sees only their own record -------------------------- */
    ['section' => 'My Record', 'roles' => ['employee']],
    ['key' => 'me',        'label' => 'My Profile',    'icon' => 'bi-person-vcard', 'url' => 'employees/view.php?id=' . (int)$ownEmp,                        'roles' => ['employee']],
    ['key' => 'me_post',   'label' => 'My Postings',   'icon' => 'bi-geo-alt',      'url' => 'employees/view.php?id=' . (int)$ownEmp . '#tab-postings',      'roles' => ['employee']],
    ['key' => 'me_orders', 'label' => 'My Orders',     'icon' => 'bi-file-earmark-text', 'url' => 'employees/view.php?id=' . (int)$ownEmp . '#tab-documents', 'roles' => ['employee']],
    ['key' => 'me_notif',  'label' => 'Notifications', 'icon' => 'bi-bell',         'url' => 'employees/view.php?id=' . (int)$ownEmp . '#tab-notifications', 'roles' => ['employee']],

    /* -- officers --------------------------------------------------------- */
    ['section' => 'Overview'],
    ['key' => 'dashboard', 'label' => 'Dashboard', 'icon' => 'bi-speedometer2', 'url' => 'dashboard/index.php', 'roles' => ['super_admin', 'officer', 'report_viewer']],
    ['section' => 'People'],
    ['key' => 'employees', 'label' => 'Employees', 'icon' => 'bi-people', 'url' => 'employees/index.php', 'roles' => ['super_admin', 'officer', 'report_viewer']],
    ['key' => 'departments', 'label' => 'Departments', 'icon' => 'bi-building', 'url' => 'employees/departments.php', 'roles' => ['super_admin', 'officer', 'report_viewer']],
    ['key' => 'map', 'label' => 'Posting Map', 'icon' => 'bi-map', 'url' => 'employees/map.php', 'roles' => ['super_admin', 'officer']],
    ['key' => 'service', 'label' => 'Service Records', 'icon' => 'bi-journal-text', 'roles' => ['super_admin', 'publisher'], 'children' => [
        ['key' => 'postings',       'label' => 'Postings / Transfers', 'url' => 'service/postings.php'],
        ['key' => 'promotions',     'label' => 'Promotions',           'url' => 'service/promotions.php'],
        ['key' => 'increments',     'label' => 'Increments',           'url' => 'service/increments.php'],
        ['key' => 'leaves',         'label' => 'Leaves',               'url' => 'service/leaves.php'],
        ['key' => 'awards',         'label' => 'Awards',               'url' => 'service/awards.php'],
        ['key' => 'punishments',    'label' => 'Punishments',          'url' => 'service/punishments.php'],
        ['key' => 'inquiries',      'label' => 'Inquiries',            'url' => 'service/inquiries.php'],
        ['key' => 'reinstatements', 'label' => 'Reinstatements',       'url' => 'service/reinstatements.php'],
        ['key' => 'deputations',    'label' => 'Deputations',          'url' => 'service/deputations.php'],
        ['key' => 'exits',          'label' => 'Exit / Separation',    'url' => 'service/exits.php'],
        ['key' => 'rejoining',      'label' => 'Rejoining',            'url' => 'service/rejoining.php'],
    ]],
    ['section' => 'Insights'],
    ['key' => 'reports', 'label' => 'Reports', 'icon' => 'bi-bar-chart-line', 'url' => 'reports/index.php', 'roles' => ['super_admin', 'officer', 'report_viewer']],
    ['key' => 'audit', 'label' => 'Audit Logs', 'icon' => 'bi-clock-history', 'url' => 'admin/audit_logs.php', 'roles' => ['super_admin']],
    ['section' => 'My Office'],
    ['key' => 'signing', 'label' => 'My Office & Signature', 'icon' => 'bi-pen', 'url' => 'admin/signing_profile.php', 'roles' => ['officer']],
    ['key' => 'notification_log', 'label' => 'Notification Log', 'icon' => 'bi-send', 'url' => 'admin/notification_log.php', 'roles' => ['super_admin', 'officer']],
    ['section' => 'System'],
    ['key' => 'masters', 'label' => 'Master Data', 'icon' => 'bi-database', 'url' => 'masters/index.php', 'roles' => ['super_admin', 'district_officer']],
    ['key' => 'backup', 'label' => 'Database Backup', 'icon' => 'bi-cloud-arrow-down', 'url' => 'admin/backup.php', 'roles' => ['super_admin']],
    ['key' => 'admin', 'label' => 'Administration', 'icon' => 'bi-gear', 'roles' => ['super_admin'], 'children' => [
        ['key' => 'users',      'label' => 'Users & Roles',       'url' => 'admin/users.php'],
        ['key' => 'signing',    'label' => 'Signing Authorities', 'url' => 'admin/signing_profile.php'],
        ['key' => 'settings',   'label' => 'System Settings',     'url' => 'admin/settings.php'],
        ['key' => 'sanctioned', 'label' => 'Sanctioned Posts',    'url' => 'admin/sanctioned_posts.php'],
        ['key' => 'trash',      'label' => 'Recycle Bin',         'url' => 'admin/trash.php'],
        ['key' => 'reset',      'label' => 'Reset Data',          'url' => 'admin/reset_db.php'],
    ]],
];

/* -------- notification bell -------- */
/* System alerts point at an employee's profile; an officer only sees the ones
   about employees in their jurisdiction. Employees get no admin alerts — the
   messages sent to them are on their own Notifications tab. */
$notifCount = 0;
$notifItems = [];
if ($user && !is_employee_user()) {
    try {
        $pending = db_all(
            'SELECT * FROM notifications WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0 ORDER BY id DESC LIMIT 300',
            [(int)$user['id']]
        );
        $visible = array_values(array_filter($pending, 'notification_visible'));
        $notifCount = count($visible);
        $notifItems = array_slice($visible, 0, 8);
    } catch (Throwable $e) { /* table may not exist yet */ }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem("hr_theme");if(!t)t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";document.documentElement.setAttribute("data-bs-theme",t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf-token" content="<?= e(csrf_token()) ?>">
<meta name="base-url" content="<?= e(rtrim(BASE_URL, '/')) ?>">
<title><?= e($page_title) ?> | <?= e(APP_NAME) ?></title>
<link rel="icon" href="<?= asset('img/logo.svg') ?>" type="image/svg+xml">

<!-- ===== 3rd-party CSS (CDN) ===== -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&family=Source+Serif+4:opsz,wght@8..60,500;8..60,600;8..60,700&display=swap" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/select2-bootstrap-5-theme@1.3.0/dist/select2-bootstrap-5-theme.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/2.1.8/css/dataTables.bootstrap5.min.css" rel="stylesheet">
<link href="https://cdn.datatables.net/responsive/3.0.3/css/responsive.bootstrap5.min.css" rel="stylesheet">
<!-- ===== App CSS ===== -->
<link href="<?= asset('css/app.css') ?>" rel="stylesheet">
<?= $extra_css ?? '' ?>
</head>
<body>
<div class="gov-strip" aria-hidden="true"></div>

<!-- ===================== TOP HEADER ===================== -->
<header class="app-header">
    <div class="d-flex align-items-center h-100 px-3 gap-2">
        <button class="btn btn-icon d-lg-none" type="button" data-bs-toggle="offcanvas" data-bs-target="#sidebarMobile" aria-label="Menu">
            <i class="bi bi-list fs-4"></i>
        </button>
        <button class="btn btn-icon d-none d-lg-inline-flex" type="button" id="sidebarToggle" aria-label="Collapse sidebar">
            <i class="bi bi-layout-sidebar"></i>
        </button>
        <a class="brand d-flex align-items-center text-decoration-none" href="<?= url('dashboard/index.php') ?>">
            <img src="<?= asset('img/logo.svg') ?>" alt="Logo" class="brand-logo">
            <span class="d-none d-md-block ms-2">
                <span class="brand-title"><?= e(APP_NAME) ?></span>
                <span class="brand-sub"><?= e($orgName) ?></span>
            </span>
        </a>

        <!-- Global live search -->
        <?php if (can_edit() || is_report_viewer()): ?>
        <div class="global-search mx-auto flex-grow-1">
            <div class="position-relative">
                <i class="bi bi-search search-icon"></i>
                <input type="search" id="globalSearch" class="form-control form-control-sm" autocomplete="off"
                       placeholder="Search employee (name / CNIC / code) or record ref no…">
                <div id="globalSearchResults" class="search-results shadow" hidden></div>
            </div>
        </div>
        <?php else: ?><div class="mx-auto flex-grow-1"></div><?php endif; ?>

        <!-- Theme -->
        <button class="btn btn-icon" type="button" id="themeToggle" aria-label="Toggle dark mode" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>

        <!-- Notifications -->
        <?php if (!is_employee_user()): ?>
        <div class="dropdown">
            <button class="btn btn-icon position-relative" type="button" data-bs-toggle="dropdown" aria-label="Notifications">
                <i class="bi bi-bell fs-5"></i>
                <?php if ($notifCount): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger notif-badge"><?= $notifCount > 99 ? '99+' : $notifCount ?></span>
                <?php endif; ?>
            </button>
            <div class="dropdown-menu dropdown-menu-end notif-menu shadow">
                <div class="dropdown-header d-flex justify-content-between align-items-center">
                    <span>Notifications</span>
                    <?php if ($notifCount): ?><a href="#" class="small text-decoration-none" id="markAllRead">Mark all read</a><?php endif; ?>
                </div>
                <?php if (!$notifItems): ?>
                    <div class="px-3 py-3 text-muted small">No new notifications.</div>
                <?php else: foreach ($notifItems as $n): ?>
                    <a class="dropdown-item notif-item" href="<?= $n['link'] ? e(url($n['link'])) : '#' ?>">
                        <div class="fw-semibold small"><?= e($n['title']) ?></div>
                        <?php if ($n['message']): ?><div class="small text-muted text-truncate"><?= e($n['message']) ?></div><?php endif; ?>
                        <div class="x-small text-muted"><?= fmt_datetime($n['created_at']) ?></div>
                    </a>
                <?php endforeach; endif; ?>
                <div class="dropdown-divider"></div>
                <a class="dropdown-item text-center small" href="<?= url('dashboard/notifications.php') ?>">View all notifications</a>
            </div>
        </div>
        <?php endif; ?>

        <!-- User menu -->
        <div class="dropdown">
            <button class="btn user-menu d-flex align-items-center gap-2" type="button" data-bs-toggle="dropdown">
                <span class="avatar-initials"><?= e(strtoupper(mb_substr($user['full_name'] ?? 'U', 0, 1))) ?></span>
                <span class="d-none d-md-block text-start lh-1">
                    <span class="d-block fw-semibold small"><?= e($user['full_name'] ?? '') ?></span>
                    <span class="d-block x-small text-muted"><?= e($user['role_label'] ?? '') ?></span>
                </span>
                <i class="bi bi-chevron-down small d-none d-md-block"></i>
            </button>
            <ul class="dropdown-menu dropdown-menu-end shadow">
                <li><a class="dropdown-item" href="<?= url('auth/profile.php') ?>"><i class="bi bi-person me-2"></i>My Profile</a></li>
                <li><a class="dropdown-item" href="<?= url('auth/change_password.php') ?>"><i class="bi bi-key me-2"></i>Change Password</a></li>
                <li><hr class="dropdown-divider"></li>
                <li><a class="dropdown-item text-danger" href="<?= url('auth/logout.php') ?>"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
            </ul>
        </div>
    </div>
</header>

<?php
/* -------- sidebar renderer (used for desktop + mobile offcanvas) -------- */
function render_sidebar_menu(array $menu, string $active): string
{
    /* a district-level officer also carries the pseudo-role that opens Master Data */
    $roles = [user_role()];
    if (is_officer() && user_level() === 'district') $roles[] = 'district_officer';
    /* service records are published by district- and tehsil-level officers only */
    if (is_officer() && can_publish()) $roles[] = 'publisher';
    $h = '<ul class="nav flex-column sidebar-nav">';
    $pendingSection = null;
    foreach ($menu as $m) {
        if (isset($m['section'])) {
            $pendingSection = (isset($m['roles']) && !array_intersect($roles, $m['roles'])) ? null : $m['section'];
            continue;
        }
        if (!array_intersect($roles, $m['roles'])) continue;
        if ($pendingSection !== null) { $h .= '<li class="nav-section"><span class="label">' . e($pendingSection) . '</span></li>'; $pendingSection = null; }
        if (!empty($m['children'])) {
            $childKeys = array_column($m['children'], 'key');
            $open = in_array($active, $childKeys, true);
            $id = 'menu_' . $m['key'];
            $h .= '<li class="nav-item">'
                . '<a class="nav-link has-children' . ($open ? ' active' : ' collapsed') . '" data-bs-toggle="collapse" href="#' . $id . '" role="button" aria-expanded="' . ($open ? 'true' : 'false') . '">'
                . '<i class="bi ' . $m['icon'] . '"></i><span class="label">' . e($m['label']) . '</span><i class="bi bi-chevron-down caret"></i></a>'
                . '<div class="collapse' . ($open ? ' show' : '') . '" id="' . $id . '"><ul class="nav flex-column sub-nav">';
            foreach ($m['children'] as $c) {
                $h .= '<li class="nav-item"><a class="nav-link' . ($active === $c['key'] ? ' active' : '') . '" href="' . e(url($c['url'])) . '">'
                    . '<span class="dot"></span><span class="label">' . e($c['label']) . '</span></a></li>';
            }
            $h .= '</ul></div></li>';
        } else {
            $h .= '<li class="nav-item"><a class="nav-link' . ($active === $m['key'] ? ' active' : '') . '" href="' . e(url($m['url'])) . '">'
                . '<i class="bi ' . $m['icon'] . '"></i><span class="label">' . e($m['label']) . '</span></a></li>';
        }
    }
    return $h . '</ul>';
}
?>

<!-- ===================== SIDEBAR (desktop) ===================== -->
<aside class="app-sidebar d-none d-lg-block" id="appSidebar">
    <div class="sidebar-scroll">
        <?= render_sidebar_menu($menu, $active_menu) ?>
    </div>
    <div class="sidebar-footer small">
        <i class="bi bi-person-badge me-1"></i><span class="label"><?= e($user['role_label'] ?? '') ?></span>
    </div>
</aside>

<!-- ===================== SIDEBAR (mobile offcanvas) ===================== -->
<div class="offcanvas offcanvas-start app-sidebar-mobile" tabindex="-1" id="sidebarMobile">
    <div class="offcanvas-header">
        <span class="fw-semibold text-white"><?= e(APP_NAME) ?></span>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0">
        <?= render_sidebar_menu($menu, $active_menu) ?>
    </div>
</div>

<!-- ===================== CONTENT ===================== -->
<main class="app-content" id="appContent">
    <div class="content-inner">
        <?php if ($breadcrumbs || $page_title): ?>
        <div class="page-head d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
            <div>
                <?php if ($breadcrumbs): ?>
                <nav aria-label="breadcrumb"><ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="<?= url('dashboard/index.php') ?>"><i class="bi bi-house-door"></i></a></li>
                    <?php foreach ($breadcrumbs as $label => $link): ?>
                        <?php if ($link): ?><li class="breadcrumb-item"><a href="<?= e(url($link)) ?>"><?= e($label) ?></a></li>
                        <?php else: ?><li class="breadcrumb-item active"><?= e($label) ?></li><?php endif; ?>
                    <?php endforeach; ?>
                </ol></nav>
                <?php endif; ?>
                <h1 class="page-title mb-0"><?= e($page_title) ?></h1>
                <?php if ($page_subtitle): ?><div class="page-subtitle"><?= e($page_subtitle) ?></div><?php endif; ?>
            </div>
            <?php if (!empty($page_actions)): ?><div class="page-actions d-flex gap-2 flex-wrap"><?= $page_actions ?></div><?php endif; ?>
        </div>
        <?php endif; ?>
        <?= flash_render() ?>
