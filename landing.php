<?php
/**
 * Public information / landing page (no login required).
 * Presents the system to visiting officials and links to the sign-in page.
 */
require_once __DIR__ . '/includes/bootstrap.php';

$orgName = setting('org_name', ORG_NAME);
$orgAddr = setting('org_address', '');
/* headline figures — public, non-identifying counts only */
try {
    $servingIn = "'" . implode("','", SERVING_STATUSES) . "'";
    $stats = [
        'employees'   => (int)db_value("SELECT COUNT(*) FROM employees WHERE is_deleted = 0 AND status IN ($servingIn)", [], 0),
        /* offices actually staffed — the master list covers every department in Punjab */
        'departments' => (int)db_value('SELECT COUNT(DISTINCT department_id) FROM employees WHERE is_deleted = 0', [], 0),
        'records'     => (int)db_value('SELECT (SELECT COUNT(*) FROM postings) + (SELECT COUNT(*) FROM promotions) + (SELECT COUNT(*) FROM increments) + (SELECT COUNT(*) FROM leaves) + (SELECT COUNT(*) FROM punishments) + (SELECT COUNT(*) FROM awards) + (SELECT COUNT(*) FROM exits)', [], 0),
        'sanctioned'  => (int)db_value('SELECT COALESCE(SUM(sanctioned_count),0) FROM sanctioned_posts WHERE is_deleted = 0 AND is_active = 1', [], 0),
    ];
} catch (Throwable $e) {
    $stats = ['employees' => 0, 'departments' => 0, 'records' => 0, 'sanctioned' => 0];
}

$modules = [
    ['bi-person-vcard', 'Employee Register', 'Complete personal, employment and government record — CNIC, GP Fund, pension and seniority — with a unique employee code generated from a configurable pattern.'],
    ['bi-geo-alt', 'Postings &amp; Transfers', 'Physical posting history that is never overwritten: office, section, location, order number and reporting officer, with only one current posting at a time.'],
    ['bi-graph-up-arrow', 'Promotions &amp; Upgradations', 'Regular promotions and special (notional / financial) upgradations with scale progression, competent-authority orders and automatic pay revision.'],
    ['bi-calendar-x', 'Leave &amp; LPR', 'All leave types with letter number and duration. EOL beyond the prescribed limit is flagged as a break in service and the retirement date is adjusted automatically.'],
    ['bi-exclamation-octagon', 'Discipline &amp; Reinstatement', 'Punishments under PEEDA, suspension with pay status, and reinstatement with or without back benefits — missed increments are restored automatically.'],
    ['bi-box-arrow-up-right', 'Deputation &amp; Reversion', 'Deputation to other organisations with expected reversion tracking, alerts before due date and automatic return to the parent department.'],
    ['bi-door-open', 'Exit &amp; Rejoining', 'Resignation, superannuation, medical invalidation, termination, dismissal and death in service — with rejoining that reuses the same employee record.'],
    ['bi-bar-chart-line', 'Statutory Reports', 'Departmental roster with sanctioned and vacant posts, contract expiry, retirement, LPR, probation, deputation, suspension, break-in-service and death-in-service reports.'],
    ['bi-send', 'Officer Notifications', 'On every service entry the dealing officer may inform the employee by email, SMS or WhatsApp, with a full delivery log for record.'],
];
$assurance = [
    ['bi-shield-lock', 'Role-based access', 'District → Tehsil → Department: officers and report viewers reach what the level of their department reaches; employees see only their own record.'],
    ['bi-clock-history', 'Complete audit trail', 'Every create, update and delete is logged with user, timestamp, IP address and before / after values.'],
    ['bi-journal-text', 'Immutable service book', 'Service records are append-only; corrections are made by a Super Admin void entry that preserves the original.'],
    ['bi-database-check', 'Backup &amp; recovery', 'Full database export from within the application, plus selective reset tools for controlled data clean-up.'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<script>(function(){try{var t=localStorage.getItem("hr_theme");if(!t)t=window.matchMedia&&window.matchMedia("(prefers-color-scheme: dark)").matches?"dark":"light";document.documentElement.setAttribute("data-bs-theme",t);}catch(e){}})();</script>
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="description" content="<?= e(APP_NAME) ?> — employee lifecycle and service record management for <?= e($orgName) ?>, Government of the Punjab.">
<title><?= e(APP_NAME) ?> | <?= e($orgName) ?></title>
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

<!-- ===== Navigation ===== -->
<nav class="lp-nav">
  <div class="container d-flex align-items-center justify-content-between py-2">
    <a href="<?= url('landing.php') ?>" class="d-flex align-items-center gap-2 text-decoration-none">
      <img src="<?= asset('img/logo.svg') ?>" alt="" width="38" height="38">
      <span><span class="brand-title d-block" style="font-size:15px"><?= e(APP_NAME) ?></span><span class="brand-sub d-block"><?= e($orgName) ?></span></span>
    </a>
    <div class="d-flex align-items-center gap-1">
      <ul class="nav d-none d-lg-flex me-2">
        <li class="nav-item"><a class="nav-link" href="#modules">Modules</a></li>
        <li class="nav-item"><a class="nav-link" href="#compliance">Compliance</a></li>
        <li class="nav-item"><a class="nav-link" href="#how">How it works</a></li>
        <li class="nav-item"><a class="nav-link" href="#support">Support</a></li>
      </ul>
      <button class="btn btn-icon" type="button" id="themeToggle" title="Toggle theme"><i class="bi bi-moon-stars"></i></button>
      <a href="<?= url('auth/login.php') ?>" class="btn btn-primary btn-sm px-3"><i class="bi bi-box-arrow-in-right me-1"></i>Official Login</a>
    </div>
  </div>
</nav>

<!-- ===== Hero ===== -->
<header class="lp-hero">
  <div class="container">
    <div class="row g-4 align-items-center">
      <div class="col-lg-7">
        <span class="lp-eyebrow"><i class="bi bi-patch-check-fill"></i>Government of the Punjab</span>
        <h1 class="lp-title">Smart HR System for the District Administration</h1>
        <p class="lp-lead">A single, authoritative record of every government servant in the office — appointment, probation, confirmation, postings, promotions, leave, discipline, deputation and retirement — maintained in accordance with the Punjab Civil Servants (Appointment &amp; Conditions of Service) Rules and S&amp;GAD instructions.</p>
        <div class="d-flex flex-wrap gap-2 mt-4">
          <a href="<?= url('auth/login.php') ?>" class="btn btn-light btn-lg px-4 fw-semibold text-success"><i class="bi bi-box-arrow-in-right me-2"></i>Sign in to the system</a>
          <a href="#modules" class="btn btn-outline-light btn-lg px-4">Explore modules</a>
        </div>
        <div class="mt-4 small" style="opacity:.85"><i class="bi bi-geo-alt me-1"></i><?= e($orgAddr ?: $orgName) ?></div>
      </div>
      <div class="col-lg-5">
        <div class="lp-hero-card">
          <div class="text-uppercase fw-semibold mb-3" style="font-size:11.5px;letter-spacing:.12em;opacity:.85">Position at a glance</div>
          <div class="row g-3">
            <div class="col-6"><div class="lp-stat"><?= number_format($stats['employees']) ?></div><div class="lp-stat-label">Serving employees</div></div>
            <div class="col-6"><div class="lp-stat"><?= number_format($stats['sanctioned']) ?></div><div class="lp-stat-label">Sanctioned posts</div></div>
            <div class="col-6"><div class="lp-stat"><?= number_format($stats['departments']) ?></div><div class="lp-stat-label">Offices with staff</div></div>
            <div class="col-6"><div class="lp-stat"><?= number_format($stats['records']) ?></div><div class="lp-stat-label">Service entries on record</div></div>
          </div>
          <div class="mt-3 pt-3 border-top border-light border-opacity-25 small" style="opacity:.8"><i class="bi bi-lock me-1"></i>Figures are aggregate only. No employee data is shown before sign-in.</div>
        </div>
      </div>
    </div>
  </div>
</header>

<!-- ===== Modules ===== -->
<section class="lp-section" id="modules">
  <div class="container">
    <div class="text-center mb-5">
      <div class="lp-kicker">Functional scope</div>
      <h2 class="lp-h2">Every stage of the service career, in one record</h2>
      <p class="text-muted mb-0 mx-auto" style="max-width:46rem">Each entry is appended to an immutable service book with its own reference number, supporting document and audit entry — the digital equivalent of the official service register.</p>
    </div>
    <div class="row g-3">
      <?php foreach ($modules as [$icon, $title, $desc]): ?>
      <div class="col-md-6 col-xl-4">
        <div class="lp-card">
          <div class="lp-ico"><i class="bi <?= $icon ?>"></i></div>
          <h3><?= $title ?></h3>
          <p><?= $desc ?></p>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<!-- ===== Compliance ===== -->
<section class="lp-section alt" id="compliance">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-5">
        <div class="lp-kicker">Rules &amp; governance</div>
        <h2 class="lp-h2">Built around the rules your office already follows</h2>
        <p class="text-muted">Statutory conditions are enforced by the system itself, so the register cannot drift from the rule position.</p>
        <div class="lp-compliance mt-3">
          <span class="lp-chip">Superannuation at 60 years</span>
          <span class="lp-chip">Probation &amp; confirmation</span>
          <span class="lp-chip">EOL &gt; 6 months = break in service</span>
          <span class="lp-chip">LPR eligibility flagging</span>
          <span class="lp-chip">Suspension blocks promotion &amp; increment</span>
          <span class="lp-chip">Reinstatement with back benefits</span>
          <span class="lp-chip">Family pension on death in service</span>
          <span class="lp-chip">Dismissed employees cannot rejoin</span>
        </div>
      </div>
      <div class="col-lg-7">
        <div class="row g-3">
          <?php foreach ($assurance as [$icon, $title, $desc]): ?>
          <div class="col-md-6">
            <div class="lp-card">
              <div class="lp-ico"><i class="bi <?= $icon ?>"></i></div>
              <h3><?= $title ?></h3>
              <p><?= $desc ?></p>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== How it works ===== -->
<section class="lp-section" id="how">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-5">
        <div class="lp-kicker">Working method</div>
        <h2 class="lp-h2">From office order to permanent record in four steps</h2>
        <p class="text-muted">The dealing officer records the order; the system maintains the consequences — status, seniority position, pay, alerts and the employee's own intimation.</p>
        <a href="<?= url('auth/login.php') ?>" class="btn btn-primary mt-2"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in to begin</a>
      </div>
      <div class="col-lg-7">
        <div class="d-grid gap-4">
          <?php foreach ([
            ['Record the office order', 'Select the employee, enter the order number, date and competent authority, and attach the scanned order.'],
            ['System applies the rule position', 'Status, pay scale, posting, retirement date and eligibility flags are updated automatically, and the employee timeline is extended.'],
            ['Intimate the employee', 'Choose email, SMS or WhatsApp on the same form; the delivery result is logged against the record.'],
            ['Report and submit', 'Roster, vacancy, retirement, LPR and pension statements are produced from the same data in Excel, CSV or print format.'],
          ] as $i => [$t, $d]): ?>
          <div class="lp-step">
            <div class="lp-step-no"><?= $i + 1 ?></div>
            <div><div class="fw-semibold mb-1"><?= e($t) ?></div><div class="text-muted small"><?= e($d) ?></div></div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
</section>

<!-- ===== CTA / support ===== -->
<section class="lp-section alt" id="support">
  <div class="container">
    <div class="lp-cta">
      <div class="row g-4 align-items-center">
        <div class="col-lg-8">
          <h2 class="lp-h2 text-white mb-2">Access is restricted to authorised officers</h2>
          <p class="mb-0" style="opacity:.9">Accounts are created by the system administrator of the office. Department Administrators may view and maintain only the branches assigned to them; every action is attributable and recorded.</p>
        </div>
        <div class="col-lg-4 text-lg-end">
          <a href="<?= url('auth/login.php') ?>" class="btn btn-light btn-lg px-4 fw-semibold text-success"><i class="bi bi-box-arrow-in-right me-2"></i>Official Login</a>
        </div>
      </div>
    </div>
  </div>
</section>

<footer class="lp-foot">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-5">
        <div class="d-flex align-items-center gap-2 mb-2">
          <img src="<?= asset('img/logo.svg') ?>" alt="" width="34" height="34">
          <span class="fw-bold text-white"><?= e(APP_NAME) ?></span>
        </div>
        <div style="opacity:.8"><?= e($orgName) ?><?= $orgAddr ? '<br>' . e($orgAddr) : '' ?></div>
      </div>
      <div class="col-md-4">
        <div class="fw-semibold text-white mb-2">System</div>
        <div style="opacity:.8">Version <?= e(APP_VERSION) ?><br>Punjab Civil Servants Rules &amp; S&amp;GAD compliant<br>Audit-logged and access-controlled</div>
      </div>
      <div class="col-md-3">
        <div class="fw-semibold text-white mb-2">Access</div>
        <a href="<?= url('auth/login.php') ?>">Official login</a>
      </div>
    </div>
    <hr style="border-color:rgba(255,255,255,.12)">
    <div class="d-flex flex-wrap justify-content-between gap-2" style="opacity:.75">
      <span>&copy; <?= date('Y') ?> <?= e($orgName) ?>, Government of the Punjab. All rights reserved.</span>
      <span>Unauthorised access to this system is an offence.</span>
    </div>
  </div>
</footer>

<script>
document.getElementById('themeToggle').addEventListener('click', function(){ const h = document.documentElement; const t = h.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark'; h.setAttribute('data-bs-theme', t); try { localStorage.setItem('hr_theme', t); } catch(e){} this.firstElementChild.className = t === 'dark' ? 'bi bi-sun' : 'bi bi-moon-stars'; });
if (document.documentElement.getAttribute('data-bs-theme') === 'dark') document.querySelector('#themeToggle i').className = 'bi bi-sun';
document.querySelectorAll('a[href^="#"]').forEach(a => a.addEventListener('click', function(e){ const t = document.querySelector(this.getAttribute('href')); if (t) { e.preventDefault(); t.scrollIntoView({ behavior: 'smooth' }); } }));
</script>
</body>
</html>
