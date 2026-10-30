<?php
/**
 * Employee profile - 360° view (hero + segmented tabs).
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/audit_ui.php';
require_once ROOT_PATH . '/includes/order_ui.php';
require_login();

$id  = get_int('id', 0);
$emp = employee_load_or_fail($id, is_super_admin());
/* An employee signed in to see their own record gets a read-only view limited
   to their profile, postings, posting orders and notifications. */
$selfService = is_employee_user();
$canEdit = can_manage_employee($emp);
[$canTransact, $transactReason] = employee_can_transact($emp);

/* ---- related data ---- */
$posting  = $emp['current_posting_id'] ? db_row('SELECT p.*, l.name AS location, o.name AS dep_org, d.name AS department FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id LEFT JOIN deputation_orgs o ON o.id = p.deputation_org_id LEFT JOIN departments d ON d.id = p.department_id WHERE p.id = ?', [(int)$emp['current_posting_id']]) : null;
$postings = db_all('SELECT p.*, l.name AS location, o.name AS dep_org, d.name AS department, u.full_name AS by_user FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id LEFT JOIN deputation_orgs o ON o.id = p.deputation_org_id LEFT JOIN departments d ON d.id = p.department_id LEFT JOIN users u ON u.id = p.created_by WHERE p.employee_id = ? ORDER BY p.effective_date DESC, p.id DESC', [$id]);
$promotions = db_all('SELECT p.*, pd.name AS prev_desig, nd.name AS new_desig, ps.name AS prev_scale, ns.name AS new_scale FROM promotions p LEFT JOIN designations pd ON pd.id = p.previous_designation_id LEFT JOIN designations nd ON nd.id = p.new_designation_id LEFT JOIN pay_scales ps ON ps.id = p.previous_scale_id LEFT JOIN pay_scales ns ON ns.id = p.new_scale_id WHERE p.employee_id = ? ORDER BY p.effective_date DESC, p.id DESC', [$id]);
$increments = db_all('SELECT * FROM increments WHERE employee_id = ? ORDER BY effective_date DESC, id DESC', [$id]);
$leaves     = db_all('SELECT l.*, t.name AS leave_type, t.is_eol, t.is_lpr FROM leaves l JOIN leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? ORDER BY l.from_date DESC, l.id DESC', [$id]);
$awards     = db_all('SELECT a.*, t.name AS award_type FROM awards a JOIN award_types t ON t.id = a.award_type_id WHERE a.employee_id = ? ORDER BY a.order_date DESC, a.id DESC', [$id]);
$punishments = db_all('SELECT p.*, t.name AS punishment_type, t.is_suspension FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? ORDER BY p.order_date DESC, p.id DESC', [$id]);
$reinstatements = db_all('SELECT r.*, t.name AS reinstatement_type FROM reinstatements r JOIN reinstatement_types t ON t.id = r.reinstatement_type_id WHERE r.employee_id = ? ORDER BY r.reinstatement_date DESC, r.id DESC', [$id]);
$deputations = db_all('SELECT d.*, o.name AS org FROM deputations d JOIN deputation_orgs o ON o.id = d.deputation_org_id WHERE d.employee_id = ? ORDER BY d.start_date DESC, d.id DESC', [$id]);
$exits      = db_all('SELECT * FROM exits WHERE employee_id = ? ORDER BY exit_date DESC, id DESC', [$id]);
$rejoinings = db_all('SELECT * FROM rejoinings WHERE employee_id = ? ORDER BY rejoining_date DESC, id DESC', [$id]);
$inquiries  = db_all('SELECT i.*, t.name AS inquiry_type, t.is_formal FROM inquiries i JOIN inquiry_types t ON t.id = i.inquiry_type_id WHERE i.employee_id = ? ORDER BY (i.status <> "Concluded") DESC, i.initiated_date DESC, i.id DESC', [$id]);
$openInquiries = array_values(array_filter($inquiries, fn($i) => !$i['is_voided'] && $i['status'] !== 'Concluded'));
$timeline   = db_all('SELECT * FROM timeline_events WHERE employee_id = ? ORDER BY event_date ASC, id ASC', [$id]);
$documents  = db_all('SELECT d.*, u.full_name AS uploader FROM employee_documents d LEFT JOIN users u ON u.id = d.uploaded_by WHERE d.employee_id = ? AND d.is_deleted = 0 ORDER BY d.id DESC', [$id]);
$family     = db_all('SELECT * FROM employee_family WHERE employee_id = ? AND is_deleted = 0 ORDER BY is_nominee DESC, id', [$id]);
$audit      = is_super_admin() ? db_all('SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id WHERE a.employee_id = ? ORDER BY a.id DESC LIMIT 100', [$id]) : [];
$linkedUser = db_row('SELECT id, username, full_name FROM users WHERE employee_id = ? AND is_deleted = 0', [$id]);
$messages   = db_all('SELECT n.*, u.full_name AS sender FROM notification_log n LEFT JOIN users u ON u.id = n.sent_by WHERE n.employee_id = ? ORDER BY n.id DESC', [$id]);
$alerts     = db_all('SELECT * FROM notifications WHERE link = ? OR link LIKE ? ORDER BY id DESC',
                     ['employees/view.php?id=' . $id, 'employees/view.php?id=' . $id . '#%']);

/* Every record of this employee that produces an official order, newest first. */
$orders = [];
foreach (ORDER_TABLES as $tbl => $kind) {
    if ($selfService && $tbl !== 'postings') continue;     // employees receive their posting orders
    $dateCol = SVC_TYPES[$tbl][2] ?? 'created_at';
    $numCol  = order_number_column($tbl);
    foreach (db_all('SELECT id, ref_no, `' . db_ident($numCol) . '` AS onum, is_voided, `' . db_ident($dateCol) . '` AS d
                       FROM `' . db_ident($tbl) . '` WHERE employee_id = ? ORDER BY id DESC', [$id]) as $r) {
        $orders[] = ['table' => $tbl, 'kind' => $kind, 'id' => (int)$r['id'], 'ref_no' => $r['ref_no'],
                     'order_no' => (string)($r['onum'] ?? ''), 'date' => $r['d'], 'is_voided' => (int)$r['is_voided']];
    }
}
if ($selfService) $orders = array_values(array_filter($orders, fn($o) => !$o['is_voided']));
usort($orders, fn($a, $b) => [$b['date'], $b['id']] <=> [$a['date'], $a['id']]);

/* ---- derived ---- */
$age = calc_age($emp['dob']);
$serviceEnd = in_array($emp['status'], EXITED_STATUSES, true) ? (db_value('SELECT MAX(exit_date) FROM exits WHERE employee_id = ? AND is_voided = 0', [$id]) ?: today()) : today();
$serviceDiff = date_create($emp['joining_date'])->diff(date_create($serviceEnd));
$leaveYear = (int)date('Y');
$leaveDaysYear = 0; foreach ($leaves as $l) if ($l['status'] === 'Approved' && !$l['is_voided'] && substr($l['from_date'], 0, 4) === (string)$leaveYear) $leaveDaysYear += (int)$l['duration_days'];
$docsByRef = []; foreach ($documents as $d) if ($d['related_table']) $docsByRef[$d['related_table'] . '|' . $d['related_id']][] = $d;
$postingById = []; foreach ($postings as $p) $postingById[(int)$p['id']] = $p;
$serviceCount = count($promotions) + count($increments) + count($awards) + count($punishments) + count($reinstatements) + count($deputations) + count($exits) + count($rejoinings);
$void = fn(array $r) => $r['is_voided'] ? '<span class="badge text-bg-dark" title="' . e($r['void_reason']) . '">Voided</span> ' : '';
$docLink = fn(array $d, bool $inline = true) => url('employees/download.php?id=' . $d['id'] . ($inline ? '&inline=1' : ''));
$attachBtns = function (string $table, ?int $refId) use ($docsByRef, $docLink): string {
    $docs = $docsByRef[$table . '|' . $refId] ?? [];
    if (!$docs) return '';
    $h = '';
    foreach ($docs as $d) $h .= '<a class="btn btn-ghost btn-sm" href="' . $docLink($d) . '" target="_blank" title="View attachment: ' . e($d['title']) . '"><i class="bi bi-paperclip"></i></a>';
    return $h;
};
$daysLabel = function (int $n): string { return $n === 1 ? '1 day' : number_format($n) . ' days'; };
$statusColor = ['Probation' => '#0ea5e9', 'Active' => '#16a34a', 'Suspended' => '#dc2626', 'On Deputation' => '#7c3aed', 'On LPR' => '#d97706'][$emp['status']] ?? '#64748b';
$tlTypeGroup = ['Joining' => 'career', 'Rejoining' => 'career', 'Confirmation' => 'career', 'Exit' => 'career', 'Status' => 'status', 'Posting' => 'posting', 'Transfer' => 'posting', 'Deputation' => 'posting', 'Reversion' => 'posting',
    'Promotion' => 'pay', 'Increment' => 'pay', 'Award' => 'pay', 'Leave' => 'leave', 'Suspension' => 'discipline', 'Punishment' => 'discipline', 'Reinstatement' => 'discipline'];
$tlIcon = ['Joining' => 'bi-door-open', 'Rejoining' => 'bi-arrow-repeat', 'Confirmation' => 'bi-patch-check', 'Exit' => 'bi-box-arrow-right', 'Status' => 'bi-toggle-on', 'Posting' => 'bi-geo-alt', 'Transfer' => 'bi-arrow-left-right', 'Deputation' => 'bi-box-arrow-up-right', 'Reversion' => 'bi-box-arrow-in-left',
    'Promotion' => 'bi-graph-up-arrow', 'Increment' => 'bi-cash-coin', 'Award' => 'bi-trophy', 'Leave' => 'bi-calendar-x', 'Suspension' => 'bi-slash-circle', 'Punishment' => 'bi-exclamation-octagon', 'Reinstatement' => 'bi-arrow-counterclockwise'];

$page_title  = $emp['name'];
$page_subtitle = $emp['designation'] . ' · ' . $emp['department'];
$active_menu = 'employees';
$breadcrumbs = ['Employees' => 'employees/index.php', $emp['name'] => null];
$page_actions = '';
if ($canEdit) {
    $page_actions .= '<a href="' . url('employees/edit.php?id=' . $id) . '" class="btn btn-primary btn-sm"><i class="bi bi-pencil me-1"></i>Edit Profile</a>';
    if ($canTransact) {
        $page_actions .= '<div class="dropdown"><button class="btn btn-outline-primary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-plus-lg me-1"></i>Service Record</button><ul class="dropdown-menu dropdown-menu-end shadow">';
        foreach (['postings' => ['Posting / Transfer', 'bi-geo-alt'], 'promotions' => ['Promotion / Upgradation', 'bi-graph-up-arrow'], 'increments' => ['Increment', 'bi-cash-coin'], 'leaves' => ['Leave', 'bi-calendar-x'], 'awards' => ['Award', 'bi-trophy'], 'punishments' => ['Punishment / Suspension', 'bi-exclamation-octagon'], 'reinstatements' => ['Reinstatement', 'bi-arrow-counterclockwise'], 'deputations' => ['Deputation / Reversion', 'bi-box-arrow-up-right'], 'exits' => ['Exit / Separation', 'bi-door-open']] as $k => [$l, $i]) {
            $page_actions .= '<li><a class="dropdown-item" href="' . url("service/$k.php?employee_id=$id") . '"><i class="bi ' . $i . ' me-2 text-muted"></i>' . $l . '</a></li>';
        }
        $page_actions .= '</ul></div>';
    } elseif (!$emp['service_closed'] && in_array($emp['status'], EXITED_STATUSES, true)) {
        $page_actions .= '<a href="' . url('service/rejoining.php?employee_id=' . $id) . '" class="btn btn-outline-success btn-sm"><i class="bi bi-arrow-repeat me-1"></i>Rejoin</a>';
    }
}
if (!$selfService) $page_actions .= '<div class="dropdown"><button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-printer me-1"></i>Print</button><ul class="dropdown-menu dropdown-menu-end shadow">'
    . '<li><a class="dropdown-item" href="' . url('reports/profile_print.php?id=' . $id) . '" target="_blank"><i class="bi bi-person-vcard me-2 text-muted"></i>Profile report</a></li>'
    . '<li><a class="dropdown-item" href="' . url('reports/service_book.php?id=' . $id) . '" target="_blank"><i class="bi bi-journal-text me-2 text-muted"></i>Service book</a></li></ul></div>';
require ROOT_PATH . '/includes/header.php';
?>
<?php if ($emp['is_deleted']): ?><div class="alert alert-danger py-2"><i class="bi bi-trash me-1"></i>This employee is in the <strong>recycle bin</strong>.</div><?php endif; ?>
<?php if (!$canTransact && !$emp['is_deleted']): ?><div class="alert alert-warning py-2"><i class="bi bi-lock me-1"></i><?= e($transactReason) ?></div><?php endif; ?>

<!-- ===== Hero ===== -->
<div class="card profile-hero mb-3">
    <div class="card-body">
        <div class="d-flex flex-wrap gap-4 align-items-end">
            <div class="position-relative"><img src="<?= photo_url($emp['photo'], $id) ?>" class="profile-photo" alt=""><span class="status-ring" style="background:<?= $statusColor ?>" title="<?= e($emp['status']) ?>"></span></div>
            <div class="flex-grow-1 min-w-0">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <span class="hero-name"><?= e($emp['name']) ?></span>
                    <?= status_badge($emp['status']) ?>
                    <?php if (!$emp['is_active']): ?><span class="badge text-bg-secondary">Inactive</span><?php endif; ?>
                    <?php if (!$emp['confirmation_date'] && in_array($emp['status'], ['Probation', 'Active'])): ?><span class="badge text-bg-light border">Unconfirmed</span><?php endif; ?>
                    <?php if ($emp['is_lpr_eligible']): ?><span class="badge text-bg-warning">LPR Eligible</span><?php endif; ?>
                    <?php if ($emp['has_break_in_service']): ?><span class="badge text-bg-danger">Break in Service</span><?php endif; ?>
                    <?php if ($emp['service_closed']): ?><span class="badge text-bg-dark">Service Book Closed</span><?php endif; ?>
                    <?php if ($openInquiries && !$selfService): ?><a href="#tab-inquiries" class="badge text-bg-warning text-decoration-none" data-bs-toggle="tab" title="Open disciplinary proceedings"><i class="bi bi-exclamation-triangle me-1"></i><?= count($openInquiries) ?> Inquiry Pending</a><?php endif; ?>
                </div>
                <div class="hero-meta mt-1">
                    <span><i class="bi bi-person-badge"></i><?= e($emp['designation']) ?><?= $emp['pay_scale'] ? ' (' . e($emp['pay_scale']) . ')' : '' ?></span>
                    <span><i class="bi bi-building"></i><?= e($emp['department']) ?></span>
                    <span><i class="bi bi-upc"></i><code><?= e($emp['employee_code']) ?></code></span>
                    <span><i class="bi bi-credit-card-2-front"></i><?= e($emp['cnic']) ?></span>
                    <?php if ($emp['contact']): ?><span><i class="bi bi-telephone"></i><?= e($emp['contact']) ?></span><?php endif; ?>
                </div>
            </div>
            <div class="posting-pill">
                <div class="x-small text-uppercase text-muted fw-semibold">Current posting</div>
                <?php if ($posting): ?>
                    <div class="fw-semibold"><?= e($posting['location'] ?: ($posting['office'] ?: $posting['department'])) ?></div>
                    <div class="small text-muted"><?= e($posting['posting_type']) ?> · since <?= fmt_date($posting['effective_date']) ?> · <?= $daysLabel(days_between($posting['effective_date'], today())) ?></div>
                    <?php if ($posting['reporting_officer']): ?><div class="small"><i class="bi bi-person-check me-1 text-muted"></i>Reports to <strong><?= e($posting['reporting_officer']) ?></strong></div><?php endif; ?>
                    <?php if (is_super_admin() || is_officer()): ?><a href="<?= url('employees/map.php?employee=' . $id) ?>" class="btn btn-sm btn-light mt-2"><i class="bi bi-geo-alt me-1"></i>View on Map</a><?php endif; ?>
                    <?php if ($posting['dep_org']): ?><div class="small text-primary">On deputation to <?= e($posting['dep_org']) ?></div><?php endif; ?>
                <?php else: ?><div class="text-muted">&mdash;</div>
                    <?php if (is_super_admin() || is_officer()): ?><a href="<?= url('employees/map.php?employee=' . $id) ?>" class="btn btn-sm btn-light mt-2"><i class="bi bi-geo-alt me-1"></i>View on Map</a><?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
        <div class="hero-stats mt-4">
            <div class="hero-stat"><div class="v"><?= $serviceDiff->y ?>y <?= $serviceDiff->m ?>m</div><div class="l">Length of service · joined <?= fmt_date($emp['joining_date']) ?></div></div>
            <div class="hero-stat"><div class="v"><?= $age ?> yrs</div><div class="l">Age · born <?= fmt_date($emp['dob']) ?></div></div>
            <div class="hero-stat"><div class="v"><?= fmt_date($emp['retirement_date']) ?></div><div class="l">Retirement date<?= $emp['eol_break_days'] ? ' (+' . (int)$emp['eol_break_days'] . 'd EOL)' : '' ?></div></div>
            <div class="hero-stat"><div class="v">Rs. <?= fmt_money($emp['basic_salary']) ?></div><div class="l">Basic salary · <?= count(array_filter($increments, fn($i) => !$i['is_voided'])) ?> increments</div></div>
            <div class="hero-stat"><div class="v"><?= $leaveDaysYear ?> days</div><div class="l">Leave taken in <?= $leaveYear ?></div></div>
            <div class="hero-stat"><div class="v"><?= count($postings) ?> · <?= count($documents) ?></div><div class="l">Postings · Documents</div></div>
        </div>
    </div>
</div>

<!-- ===== Tabs ===== -->
<?php
$tabs = ['overview' => ['Overview', 'bi-person-lines-fill', null], 'postings' => ['Postings', 'bi-geo-alt', count($postings)], 'service' => ['Service Records', 'bi-journal-text', $serviceCount],
    'leaves' => ['Leaves', 'bi-calendar-x', count($leaves)], 'inquiries' => ['Inquiries', 'bi-search', count($inquiries)],
    'family' => ['Family', 'bi-people', count($family)],
    'documents' => ['Documents & Orders', 'bi-folder2-open', count($documents) + count($orders)],
    'notifications' => ['Notifications', 'bi-send', count($messages) + count($alerts)],
    'timeline' => ['Timeline', 'bi-clock-history', count($timeline)]];
if (is_super_admin()) $tabs['audit'] = ['Audit', 'bi-shield-check', null];
if ($selfService) {
    $tabs = array_intersect_key($tabs, array_flip(['overview', 'postings', 'family', 'documents', 'notifications']));
    $tabs['documents'][0] = 'My Orders & Documents';
    $tabs['documents'][2] = count($documents) + count($orders);
    $tabs['notifications'][2] = count($messages);
}
?>
<ul class="nav profile-tabs mb-3" id="empTabs" role="tablist">
    <?php foreach ($tabs as $k => [$l, $i, $c]): ?>
    <li class="nav-item"><a class="nav-link<?= $k === 'overview' ? ' active' : '' ?>" data-bs-toggle="tab" href="#tab-<?= $k ?>"><i class="bi <?= $i ?>"></i><?= $l ?><?= $c !== null ? ' <span class="cnt">' . $c . '</span>' : '' ?></a></li>
    <?php endforeach; ?>
</ul>

<div class="tab-content">
<!-- ================= OVERVIEW ================= -->
<div class="tab-pane fade show active" id="tab-overview">
    <div class="row g-3">
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><span class="card-title"><i class="bi bi-person me-1 text-primary"></i>Personal</span></div>
            <div class="card-body"><div class="info-tiles">
                <?php foreach (['Father / Husband' => $emp['father_husband_name'], 'Gender' => $emp['gender'], 'Date of Birth' => fmt_date($emp['dob']) . " ($age yrs)", 'Marital Status' => $emp['marital_status'], 'Blood Group' => $emp['blood_group'], 'Mobile (SMS)' => $emp['contact'], 'WhatsApp' => $emp['whatsapp_no'] ?: ($emp['contact'] ? $emp['contact'] . ' (same as mobile)' : null), 'Email' => $emp['email'], 'Address' => $emp['address']] as $k => $v): ?>
                <div class="info-tile"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v ?: '—') ?></div></div>
                <?php endforeach; ?>
            </div></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><span class="card-title"><i class="bi bi-briefcase me-1 text-primary"></i>Employment</span></div>
            <div class="card-body"><div class="info-tiles">
                <?php foreach (['Department' => $emp['department'], 'Designation' => $emp['designation'], 'Pay Scale' => $emp['pay_scale'], 'Employment Type' => $emp['employment_type'], 'Joining Date' => fmt_date($emp['joining_date']),
                    'Contract' => $emp['contract_to'] ? fmt_date($emp['contract_from']) . ' → ' . fmt_date($emp['contract_to']) : null,
                    'Probation' => $emp['probation_start'] ? fmt_date($emp['probation_start']) . ' → ' . fmt_date($emp['probation_end']) : null,
                    'Confirmation' => $emp['confirmation_date'] ? fmt_date($emp['confirmation_date']) . ($emp['confirmation_order_no'] ? ' · ' . $emp['confirmation_order_no'] : '') : 'Not confirmed'] as $k => $v): ?>
                <div class="info-tile"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v ?: '—') ?></div></div>
                <?php endforeach; ?>
            </div></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><span class="card-title"><i class="bi bi-bank me-1 text-primary"></i>Government Record</span></div>
            <div class="card-body"><div class="info-tiles">
                <?php foreach (['Service Book No.' => $emp['service_book_no'], 'GP Fund No.' => $emp['gp_fund_no'], 'Pension No.' => $emp['pension_no'], 'Seniority No.' => $emp['seniority_no'], 'System Login' => $linkedUser['username'] ?? null, 'Remarks' => $emp['remarks']] as $k => $v): ?>
                <div class="info-tile"><div class="k"><?= e($k) ?></div><div class="v"><?= e($v ?: '—') ?></div></div>
                <?php endforeach; ?>
            </div></div></div>
        </div>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><span class="card-title"><i class="bi bi-people me-1 text-primary"></i>Nominees &amp; Claimants</span><a href="#tab-family" class="small" data-tab-link="family">Manage</a></div>
            <div class="card-body">
                <?php $nom = array_filter($family, fn($f) => $f['is_nominee'] || $f['is_pension_claimant']); if (!$nom): ?><div class="empty-state py-3"><i class="bi bi-person-plus"></i>No nominee recorded.</div>
                <?php else: ?><div class="family-grid"><?php foreach ($nom as $n): ?>
                    <div class="family-card"><div class="rel"><?= e($n['relation']) ?></div><div class="nm"><?= e($n['name']) ?></div><div class="meta"><?= $n['is_nominee'] ? '<span class="badge text-bg-primary">Nominee' . ($n['nominee_share'] !== null ? ' ' . (float)$n['nominee_share'] . '%' : '') . '</span> ' : '' ?><?= $n['is_pension_claimant'] ? '<span class="badge text-bg-danger">Pension Claimant</span>' : '' ?></div></div>
                <?php endforeach; ?></div><?php endif; ?>
            </div></div>
        </div>
        <div class="col-12">
            <div class="card"><div class="card-header"><span class="card-title"><i class="bi bi-send me-1 text-primary"></i>Messages Sent to Employee</span><a href="#tab-notifications" data-bs-toggle="tab" class="small">All notifications</a></div>
            <?php if (!$messages): ?><div class="empty-state py-3 small">No Email / SMS / WhatsApp messages sent yet. Tick a channel on any service record form to notify the employee.</div>
            <?php else: ?><div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0"><thead><tr><th>When</th><th>Channel</th><th>To</th><th>Message</th><th>Status</th><th>Sent by</th></tr></thead><tbody>
                <?php foreach ($messages as $m): $ico = ['email' => 'bi-envelope', 'sms' => 'bi-chat-dots', 'whatsapp' => 'bi-whatsapp'][$m['channel']] ?? 'bi-send'; ?>
                <tr><td class="small text-nowrap"><?= fmt_datetime($m['created_at']) ?></td><td><i class="bi <?= $ico ?> me-1 text-muted"></i><?= e(NOTIFY_CHANNELS[$m['channel']] ?? $m['channel']) ?></td><td class="small"><?= e($m['recipient'] ?: '—') ?></td><td class="small text-truncate" style="max-width:360px" title="<?= e($m['message']) ?>"><?= e($m['message']) ?></td>
                    <td><span class="badge text-bg-<?= ['sent' => 'success', 'failed' => 'danger', 'skipped' => 'secondary', 'not_configured' => 'warning'][$m['status']] ?? 'secondary' ?>" title="<?= e($m['response']) ?>"><?= e(str_replace('_', ' ', $m['status'])) ?></span></td><td class="small"><?= e($m['sender'] ?: '—') ?></td></tr>
                <?php endforeach; ?></tbody></table></div><?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- ================= POSTINGS ================= -->
<div class="tab-pane fade" id="tab-postings">
    <div class="card"><div class="card-header"><span class="card-title">Posting History <span class="text-muted fw-normal small">· immutable, newest first</span></span>
        <?php if ($canEdit && $canTransact): ?><a href="<?= url('service/postings.php?employee_id=' . $id) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>New Posting</a><?php endif; ?></div>
    <div class="card-body">
        <?php if (!$postings): ?><div class="empty-state"><i class="bi bi-geo-alt"></i>No postings recorded.</div><?php else: ?>
        <div class="stepper">
        <?php $pn = count($postings); foreach ($postings as $p): $end = $p['is_current'] ? today() : ($p['end_date'] ?: $p['effective_date']); ?>
            <div class="step <?= $p['is_current'] ? 'current' : '' ?> <?= $p['is_voided'] ? 'voided' : '' ?>" data-ref="<?= e($p['ref_no']) ?>">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                    <div>
                        <div class="fw-semibold"><span class="badge text-bg-light border me-1">#<?= $pn-- ?></span><span class="ref-no me-2"><?= e($p['ref_no']) ?></span><?= e($p['location'] ?: ($p['office'] ?: $p['department'])) ?> <span class="badge text-bg-<?= $p['is_current'] ? 'success' : 'light border' ?> ms-1"><?= $p['is_current'] ? 'Current' : 'Historical' ?></span> <?= $void($p) ?></div>
                        <div class="small text-muted"><?= e($p['posting_type']) ?><?= $p['dep_org'] ? ' · <span class="text-primary">' . e($p['dep_org']) . '</span>' : '' ?> · <?= e($p['department']) ?><?= $p['section'] ? ' · ' . e($p['section']) : '' ?></div>
                    </div>
                    <div class="text-end"><div class="fw-semibold"><?= fmt_date($p['effective_date']) ?> → <?= $p['is_current'] ? 'Present' : fmt_date($p['end_date']) ?></div><div class="tl-days d-inline-block mt-1"><i class="bi bi-hourglass-split me-1"></i><?= $daysLabel(days_between($p['effective_date'], $end)) ?></div></div>
                </div>
                <div class="step-meta">
                    <span><b>Order:</b> <?= e($p['order_no'] ?: '—') ?><?= $p['order_date'] ? ' (' . fmt_date($p['order_date']) . ')' : '' ?></span>
                    <span><b>Reporting Officer:</b> <?= e($p['reporting_officer'] ?: '—') ?></span>
                    <?php if ($p['office']): ?><span><b>Office:</b> <?= e($p['office']) ?></span><?php endif; ?>
                    <?php if ($p['remarks']): ?><span><b>Remarks:</b> <?= e($p['remarks']) ?></span><?php endif; ?>
                    <span class="tl-actions"><?= $attachBtns('postings', (int)$p['id']) ?><?= order_button('postings', (int)$p['id']) ?></span>
                </div>
            </div>
        <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div></div>
</div>

<?php if (isset($tabs['service'])): ?>
<!-- ================= SERVICE RECORDS ================= -->
<div class="tab-pane fade" id="tab-service">
    <div class="accordion" id="svcAcc">
    <?php
    $sections = [
        ['promo', 'Promotions & Upgradations', 'bi-graph-up-arrow', count($promotions), function () use ($promotions, $void, $attachBtns) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Effective</th><th>Type</th><th>From</th><th>To</th><th>Order</th><th>Authority</th><th></th></tr></thead><tbody>
            <?php foreach ($promotions as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['effective_date']) ?></td><td><?= $void($r) ?><span class="badge text-bg-<?= $r['promotion_type'] === 'Promotion' ? 'primary' : 'info' ?>"><?= e($r['promotion_type']) ?></span><?= $r['upgradation_kind'] ? ' <span class="small text-muted">' . e($r['upgradation_kind']) . '</span>' : '' ?></td>
                <td><?= e($r['prev_desig'] ?: '-') ?> <span class="text-muted">/</span> <?= e($r['prev_scale'] ?: '-') ?></td><td><strong><?= e($r['new_desig'] ?: $r['prev_desig']) ?></strong> <span class="text-muted">/</span> <strong><?= e($r['new_scale']) ?></strong></td>
                <td><?= e($r['order_no'] ?: '-') ?> <span class="small text-muted"><?= fmt_date($r['order_date']) ?></span></td><td><?= e($r['order_by_authority'] ?: '-') ?></td><td class="text-end tl-actions"><?= $attachBtns('promotions', (int)$r['id']) ?><?= order_button('promotions', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['incr', 'Increments', 'bi-cash-coin', count($increments), function () use ($increments, $void, $attachBtns) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Effective</th><th>Previous</th><th>New</th><th>Change</th><th>Order</th><th>Kind</th><th></th></tr></thead><tbody>
            <?php foreach ($increments as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['effective_date']) ?></td><td><?= fmt_money($r['previous_salary']) ?></td><td><strong><?= fmt_money($r['new_salary']) ?></strong></td><td class="text-success small">+<?= fmt_money((float)$r['new_salary'] - (float)$r['previous_salary']) ?></td><td><?= e($r['order_no'] ?: '-') ?></td><td><?= $void($r) ?><?= $r['is_back_benefit'] ? '<span class="badge text-bg-info">Back benefit</span>' : '<span class="badge text-bg-light border">Annual</span>' ?></td><td class="text-end tl-actions"><?= $attachBtns('increments', (int)$r['id']) ?><?= order_button('increments', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['awd', 'Awards', 'bi-trophy', count($awards), function () use ($awards, $void, $attachBtns) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Date</th><th>Award</th><th>Order</th><th>Description</th><th></th></tr></thead><tbody>
            <?php foreach ($awards as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['order_date']) ?></td><td><?= $void($r) ?><i class="bi bi-trophy text-warning me-1"></i><?= e($r['award_type']) ?></td><td><?= e($r['order_no'] ?: '-') ?></td><td class="small"><?= e($r['description'] ?: '-') ?></td><td class="text-end tl-actions"><?= $attachBtns('awards', (int)$r['id']) ?><?= order_button('awards', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['pun', 'Punishments & Suspensions', 'bi-exclamation-octagon', count($punishments), function () use ($punishments, $void, $attachBtns, $daysLabel) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Order Date</th><th>Type</th><th>Order / Authority</th><th>Suspension</th><th>Pay Status</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($punishments as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['order_date']) ?></td><td><?= $void($r) ?><?= e($r['punishment_type']) ?><?= $r['description'] ? '<div class="small text-muted">' . e($r['description']) . '</div>' : '' ?></td><td><?= e($r['order_no'] ?: '-') ?><div class="small text-muted"><?= e($r['order_by_authority'] ?: '') ?></div></td>
                <td><?= $r['is_suspension'] ? fmt_date($r['suspension_start']) . ' → ' . ($r['suspension_end'] ? fmt_date($r['suspension_end']) : '<em>indefinite</em>') . '<div class="small text-muted">' . $daysLabel(days_between($r['suspension_start'], $r['suspension_end'] ?: today())) . '</div>' : '-' ?></td><td><?= e($r['pay_status'] ?: '-') ?></td>
                <td><?= $r['is_suspension'] ? ($r['is_reinstated'] ? '<span class="badge text-bg-success">Reinstated</span>' : '<span class="badge text-bg-danger">Suspended</span>') : '' ?></td><td class="text-end tl-actions"><?= $attachBtns('punishments', (int)$r['id']) ?><?= order_button('punishments', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['rein', 'Reinstatements', 'bi-arrow-counterclockwise', count($reinstatements), function () use ($reinstatements, $void, $attachBtns) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Date</th><th>Type</th><th>Order / Authority</th><th>Back Benefits</th><th></th></tr></thead><tbody>
            <?php foreach ($reinstatements as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['reinstatement_date']) ?></td><td><?= $void($r) ?><?= e($r['reinstatement_type']) ?></td><td><?= e($r['order_no'] ?: '-') ?><div class="small text-muted"><?= e($r['order_by_authority'] ?: '') ?></div></td><td><?= $r['with_back_benefits'] ? '<span class="badge text-bg-success">Yes</span> ' . (int)$r['missed_increments'] . ' increment(s)' : 'No' ?></td><td class="text-end tl-actions"><?= $attachBtns('reinstatements', (int)$r['id']) ?><?= order_button('reinstatements', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['dep', 'Deputations & Reversions', 'bi-box-arrow-up-right', count($deputations), function () use ($deputations, $void, $attachBtns, $daysLabel) { ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Organization</th><th>From</th><th>Expected Reversion</th><th>Reverted</th><th>Duration</th><th>Status</th><th></th></tr></thead><tbody>
            <?php foreach ($deputations as $i => $r): ?><tr data-ref="<?= e($r['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['ref_no'] ?: '—') ?></span></td><td><?= $void($r) ?><strong><?= e($r['org']) ?></strong></td><td class="text-nowrap"><?= fmt_date($r['start_date']) ?></td><td><?= fmt_date($r['expected_reversion_date']) ?></td><td><?= fmt_date($r['actual_reversion_date']) ?></td><td class="small"><?= $daysLabel(days_between($r['start_date'], $r['actual_reversion_date'] ?: today())) ?></td><td><?= $r['status'] === 'Active' ? '<span class="badge text-bg-primary">On Deputation</span>' : '<span class="badge text-bg-success">Reverted</span>' ?></td><td class="text-end tl-actions"><?= $attachBtns('deputations', (int)$r['id']) ?><?= order_button('deputations', (int)$r['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
        ['exit', 'Exits & Rejoinings', 'bi-door-open', count($exits) + count($rejoinings), function () use ($exits, $rejoinings, $void, $attachBtns) {
            $ex = [];
            foreach ($exits as $r) $ex[] = ['d' => $r['exit_date'], 'ev' => 'Exit', 't' => $r['exit_type'], 'o' => $r['order_no'], 'x' => trim(($r['remarks'] ?: '') . ($r['medical_board_ref'] ? ' · Medical Board ' . $r['medical_board_ref'] : '') . ($r['date_of_death'] ? ' · Date of death ' . fmt_date($r['date_of_death']) : '')), 'v' => $r, 'tbl' => 'exits'];
            foreach ($rejoinings as $r) $ex[] = ['d' => $r['rejoining_date'], 'ev' => 'Rejoining', 't' => $r['rejoin_type'], 'o' => $r['order_no'], 'x' => trim(($r['new_contract_to'] ? 'Contract to ' . fmt_date($r['new_contract_to']) : '') . ($r['is_override'] ? ' · Super Admin override' : '') . ($r['remarks'] ? ' · ' . $r['remarks'] : '')), 'v' => $r, 'tbl' => 'rejoinings'];
            usort($ex, fn($a, $b) => strcmp($b['d'], $a['d'])); ?>
            <table class="table table-sm table-hover mb-0"><thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Date</th><th>Event</th><th>Type</th><th>Order</th><th>Details</th><th></th></tr></thead><tbody>
            <?php foreach ($ex as $i => $r): ?><tr data-ref="<?= e($r['v']['ref_no']) ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($r['v']['ref_no'] ?: '—') ?></span></td><td class="text-nowrap"><?= fmt_date($r['d']) ?></td><td><?= $void($r['v']) ?><span class="badge text-bg-<?= $r['ev'] === 'Exit' ? 'danger' : 'success' ?>"><?= $r['ev'] ?></span></td><td><?= e($r['t']) ?></td><td><?= e($r['o'] ?: '-') ?></td><td class="small"><?= e($r['x'] ?: '-') ?></td><td class="text-end tl-actions"><?= $attachBtns($r['tbl'], (int)$r['v']['id']) ?><?= order_button($r['tbl'], (int)$r['v']['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table><?php }],
    ];
    $first = true;
    foreach ($sections as [$key, $label, $icon, $count, $render]): ?>
        <div class="accordion-item">
            <h2 class="accordion-header"><button class="accordion-button <?= $first && $count ? '' : 'collapsed' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#acc-<?= $key ?>"><i class="bi <?= $icon ?> me-2 text-primary"></i><?= $label ?><span class="cnt"><?= $count ?></span></button></h2>
            <div id="acc-<?= $key ?>" class="accordion-collapse collapse <?= $first && $count ? 'show' : '' ?>" data-bs-parent="#svcAcc"><div class="accordion-body p-0">
                <?php if (!$count): ?><div class="empty-state py-3 small">Nothing recorded.</div><?php else: ?><div class="table-responsive"><?php $render(); ?></div><?php endif; ?>
            </div></div>
        </div>
    <?php if ($count) $first = false; endforeach; ?>
    </div>
</div>

<?php endif; ?>
<?php if (isset($tabs['leaves'])): ?>
<!-- ================= LEAVES ================= -->
<div class="tab-pane fade" id="tab-leaves">
    <div class="card">
        <div class="card-header"><span class="card-title">Leave Record</span><?php if ($canEdit && $canTransact): ?><a href="<?= url('service/leaves.php?employee_id=' . $id) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Leave</a><?php endif; ?></div>
        <?php $byType = []; foreach ($leaves as $l) if ($l['status'] === 'Approved' && !$l['is_voided']) $byType[$l['leave_type']] = ($byType[$l['leave_type']] ?? 0) + (int)$l['duration_days']; ?>
        <?php if ($byType): ?><div class="card-body border-bottom"><div class="summary-strip"><div class="summary-chip"><b><?= array_sum($byType) ?></b>total days (all time)</div><?php foreach ($byType as $t => $d): ?><div class="summary-chip"><b><?= $d ?></b><?= e($t) ?></div><?php endforeach; ?></div></div><?php endif; ?>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th style="width:40px">#</th><th>Ref No.</th><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Letter</th><th>Status</th><th>Flags</th><th></th></tr></thead>
            <tbody><?php if (!$leaves): ?><tr><td colspan="10"><div class="empty-state"><i class="bi bi-calendar-x"></i>No leave recorded.</div></td></tr><?php endif; ?>
            <?php foreach ($leaves as $i => $l): ?><tr data-ref="<?= e($l['ref_no']) ?>" class="<?= $l['is_voided'] ? 'text-muted' : '' ?>"><td class="text-muted"><?= $i + 1 ?></td><td><span class="ref-no"><?= e($l['ref_no'] ?: '—') ?></span></td>
                <td><?= $void($l) ?><?= e($l['leave_type']) ?><?= $l['remarks'] ? '<div class="small text-muted">' . e($l['remarks']) . '</div>' : '' ?></td><td class="text-nowrap"><?= fmt_date($l['from_date']) ?></td><td class="text-nowrap"><?= fmt_date($l['to_date']) ?></td><td><strong><?= (int)$l['duration_days'] ?></strong></td>
                <td><?= e($l['letter_no'] ?: '-') ?><?= $l['letter_date'] ? '<div class="small text-muted">' . fmt_date($l['letter_date']) . '</div>' : '' ?></td>
                <td><span class="badge text-bg-<?= $l['status'] === 'Approved' ? 'success' : ($l['status'] === 'Rejected' || $l['status'] === 'Cancelled' ? 'secondary' : 'warning') ?>"><?= e($l['status']) ?></span></td>
                <td><?= $l['is_eol'] ? '<span class="badge text-bg-warning">EOL</span> ' : '' ?><?= $l['is_break_in_service'] ? '<span class="badge text-bg-danger">Break in Service</span> ' : '' ?><?= $l['is_lpr'] ? '<span class="badge text-bg-info">LPR</span>' : '' ?></td>
                <td class="text-end tl-actions"><?= $attachBtns('leaves', (int)$l['id']) ?><?= order_button('leaves', (int)$l['id']) ?></td></tr><?php endforeach; ?>
            </tbody></table></div>
    </div>
</div>

<?php endif; ?>
<?php if (isset($tabs['inquiries'])): ?>
<!-- ================= INQUIRIES ================= -->
<div class="tab-pane fade" id="tab-inquiries">
    <?php if ($openInquiries): ?>
    <div class="alert alert-warning d-flex align-items-start gap-2">
        <i class="bi bi-exclamation-triangle-fill mt-1"></i>
        <div>
            <strong><?= count($openInquiries) ?> inquiry proceeding<?= count($openInquiries) === 1 ? '' : 's' ?> pending against this employee.</strong>
            Promotion, transfer and other benefits should be considered in the light of the pending proceedings.
        </div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><i class="bi bi-search me-1 text-primary"></i>Inquiries
                <span class="badge rounded-pill text-bg-light border"><?= count($inquiries) ?></span></span>
            <?php if ($canEdit && $canTransact): ?>
                <a href="<?= url('service/inquiries.php?employee_id=' . $id) ?>" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Record Inquiry</a>
            <?php endif; ?>
        </div>
        <div class="table-responsive"><table class="table table-hover align-middle mb-0">
            <thead><tr><th>Ref No.</th><th>Subject</th><th>Type</th><th>Initiated</th><th>Inquiry Officer</th><th>Status</th><th>Outcome</th><th class="text-end">Days</th><th class="text-end no-sort">Actions</th></tr></thead>
            <tbody>
            <?php if (!$inquiries): ?><tr><td colspan="9"><div class="empty-state"><i class="bi bi-shield-check"></i><div>No inquiry has ever been initiated against this employee.</div></div></td></tr><?php endif; ?>
            <?php foreach ($inquiries as $q): ?>
                <tr class="<?= $q['is_voided'] ? 'table-secondary text-muted' : '' ?>">
                    <td><a href="<?= svc_record_url('inquiries', (int)$q['id'], $id) ?>" class="ref-no"><?= e($q['ref_no'] ?: '—') ?></a></td>
                    <td><?= $void($q) ?><span class="fw-semibold"><?= e($q['subject']) ?></span>
                        <?php if ($q['allegation']): ?><div class="small text-muted"><?= e($q['allegation']) ?></div><?php endif; ?></td>
                    <td class="small"><?= e($q['inquiry_type']) ?><?= !empty($q['is_formal']) ? '<br><span class="badge text-bg-light border">Formal</span>' : '' ?></td>
                    <td class="small"><?= fmt_date($q['initiated_date']) ?><?= $q['order_no'] ? '<br><span class="text-muted">Order ' . e($q['order_no']) . '</span>' : '' ?></td>
                    <td class="small"><?= e($q['inquiry_officer'] ?: '-') ?></td>
                    <td><?php
                        echo match ($q['status']) {
                            'Concluded'     => '<span class="badge bg-secondary-subtle text-body-secondary border">Concluded</span>',
                            'Under Inquiry' => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Under Inquiry</span>',
                            default         => '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Open</span>',
                        };
                    ?></td>
                    <td class="small"><?= $q['status'] === 'Concluded' ? e($q['outcome'] ?: '-') . '<br><span class="text-muted">' . fmt_date($q['concluded_date']) . '</span>' : '<span class="text-muted">pending</span>' ?></td>
                    <td class="text-end small"><?= number_format(days_between($q['initiated_date'], $q['concluded_date'] ?: today())) ?></td>
                    <td class="text-end tl-actions">
                        <?= $attachBtns('inquiries', (int)$q['id']) ?>
                        <?= order_button('inquiries', (int)$q['id']) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody></table></div>
        <?php if ($inquiries): ?>
        <div class="card-footer bg-transparent small text-muted">
            Days = time from initiation to conclusion (or to today for a pending inquiry).
            Every inquiry carries its own reference number and appears in the service book and profile report.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php endif; ?>
<!-- ================= FAMILY ================= -->
<div class="tab-pane fade" id="tab-family">
    <div class="card"><div class="card-header"><span class="card-title">Family Members &amp; Nominees</span><?php if ($canEdit): ?><button class="btn btn-sm btn-primary" id="btnAddFamily"><i class="bi bi-plus-lg me-1"></i>Add Member</button><?php endif; ?></div>
    <div class="card-body" id="familyList"><div class="text-muted small">Loading…</div></div></div>
</div>

<!-- ================= DOCUMENTS ================= -->
<div class="tab-pane fade" id="tab-documents">
    <div class="row g-3">
        <?php if ($canEdit): ?>
        <div class="col-lg-4">
            <div class="card"><div class="card-header"><span class="card-title">Upload Document</span></div>
            <div class="card-body"><form id="docForm" enctype="multipart/form-data">
                <input type="hidden" name="employee_id" value="<?= $id ?>">
                <div class="mb-2"><label class="form-label">Document Type</label><select name="doc_type" class="form-select select2"><?= options_html(DOC_TYPES) ?></select></div>
                <div class="mb-2"><label class="form-label">Title</label><input type="text" name="title" class="form-control" maxlength="150" placeholder="e.g. CNIC copy (front/back)"></div>
                <div class="mb-3"><label class="form-label">File</label><input type="file" name="file" class="form-control" required accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx,.xls,.xlsx"><div class="form-text">Max <?= MAX_UPLOAD_MB ?> MB · PDF, images, Word, Excel</div></div>
                <button class="btn btn-primary w-100"><i class="bi bi-cloud-arrow-up me-1"></i>Upload</button>
            </form></div></div>
        </div>
        <?php endif; ?>
        <div class="<?= $canEdit ? 'col-lg-8' : 'col-12' ?>">
            <div class="card"><div class="card-header"><span class="card-title">Uploaded Documents <span class="badge rounded-pill text-bg-light border" id="docCount"><?= count($documents) ?></span></span></div>
            <div class="card-body" id="docList"><div class="text-muted small">Loading&hellip;</div></div></div>
        </div>

        <div class="col-12">
            <div class="card">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-file-earmark-text me-1 text-primary"></i>Generated Orders
                        <span class="badge rounded-pill text-bg-light border"><?= count($orders) ?></span></span>
                    <span class="small text-muted">Printed from the record every time &mdash; always current</span>
                </div>
                <div class="table-responsive"><table class="table table-hover align-middle mb-0">
                    <thead><tr><th style="width:48px">#</th><th>Order</th><th>Type</th><th>Date</th><th>Order No.</th><th class="text-end no-sort">Download</th></tr></thead>
                    <tbody>
                    <?php if (!$orders): ?><tr><td colspan="6"><div class="empty-state"><i class="bi bi-file-earmark-text"></i><div>No service record yet, so no order has been generated.</div></div></td></tr><?php endif; ?>
                    <?php foreach ($orders as $i => $o): ?>
                        <tr class="<?= $o['is_voided'] ? 'table-secondary text-muted' : '' ?>">
                            <td class="text-muted small"><?= $i + 1 ?></td>
                            <td><a href="<?= e(order_url($o['table'], $o['id'])) ?>" target="_blank" class="ref-no"><?= e($o['ref_no'] ?: '—') ?></a>
                                <?= $o['is_voided'] ? ' <span class="badge text-bg-dark">Voided</span>' : '' ?></td>
                            <td class="small"><?= e($o['kind']) ?></td>
                            <td class="small"><?= fmt_date($o['date']) ?></td>
                            <td class="small text-muted"><?= e($o['order_no'] ?: '—') ?></td>
                            <td class="text-end tl-actions">
                                <a href="<?= e(order_url($o['table'], $o['id'])) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Print / Save as PDF"><i class="bi bi-printer"></i></a>
                                <a href="<?= e(order_url($o['table'], $o['id'], '&download=doc')) ?>" class="btn btn-ghost btn-sm" title="Download Word"><i class="bi bi-file-earmark-word"></i></a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
            </div>
        </div>
    </div>
</div>

<!-- ================= NOTIFICATIONS ================= -->
<div class="tab-pane fade" id="tab-notifications">
    <div class="row g-3">
        <div class="<?= $selfService ? 'col-12' : 'col-lg-7' ?>">
            <div class="card h-100">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-send me-1 text-primary"></i>Messages Sent to Employee
                        <span class="badge rounded-pill text-bg-light border"><?= count($messages) ?></span></span>
                    <?php if (is_super_admin() || is_officer()): ?><a href="<?= url('admin/notification_log.php?employee_id=' . $id) ?>" class="small">Delivery log</a><?php endif; ?>
                </div>
                <?php if (!$messages): ?>
                    <div class="empty-state p-4"><i class="bi bi-send"></i><div>No Email / SMS / WhatsApp message has been sent to this employee yet. Tick a channel on any service record form to notify them.</div></div>
                <?php else: ?>
                <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
                    <thead><tr><th>Sent</th><th>Channel</th><th>Recipient</th><th>Message</th><th>Result</th></tr></thead>
                    <tbody>
                    <?php foreach ($messages as $m): $ico = ['email' => 'bi-envelope', 'sms' => 'bi-chat-dots', 'whatsapp' => 'bi-whatsapp'][$m['channel']] ?? 'bi-send'; ?>
                        <tr>
                            <td class="small text-nowrap"><?= fmt_datetime($m['created_at']) ?><br><span class="text-muted"><?= e($m['sender'] ?: 'System') ?></span></td>
                            <td class="small"><i class="bi <?= $ico ?> me-1"></i><?= e(NOTIFY_CHANNELS[$m['channel']] ?? $m['channel']) ?></td>
                            <td class="small"><?= e($m['recipient']) ?></td>
                            <td class="small"><?= e(mb_strimwidth((string)$m['message'], 0, 90, '…')) ?>
                                <?php if ($m['related_table']): ?><br><span class="text-muted"><?= e(svc_ref_of($m['related_table'], (int)$m['related_id'])) ?></span><?php endif; ?></td>
                            <td><?= $m['status'] === 'sent'
                                    ? '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Sent</span>'
                                    : '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle" title="' . e((string)$m['response']) . '">' . e(ucfirst(str_replace('_', ' ', (string)$m['status']))) . '</span>' ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody></table></div>
                <?php endif; ?>
            </div>
        </div>

        <?php if (!$selfService): ?>
        <div class="col-lg-5">
            <div class="card h-100">
                <div class="card-header">
                    <span class="card-title"><i class="bi bi-bell me-1 text-primary"></i>System Alerts
                        <span class="badge rounded-pill text-bg-light border"><?= count($alerts) ?></span></span>
                </div>
                <?php if (!$alerts): ?>
                    <div class="empty-state p-4"><i class="bi bi-bell-slash"></i><div>No alert has been raised for this employee.</div></div>
                <?php else: ?>
                <div class="list-group list-group-flush" style="max-height:520px;overflow-y:auto">
                    <?php foreach ($alerts as $a): ?>
                    <div class="list-group-item py-2">
                        <div class="d-flex justify-content-between gap-2">
                            <span class="fw-semibold small"><?= e($a['title']) ?></span>
                            <span class="small text-muted text-nowrap"><?= fmt_date($a['created_at']) ?></span>
                        </div>
                        <div class="small text-muted"><?= e($a['message']) ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($tabs['timeline'])): ?>
<!-- ================= TIMELINE ================= -->
<div class="tab-pane fade" id="tab-timeline">
    <div class="card">
        <div class="card-header">
            <span class="card-title">Service Timeline <span class="text-muted fw-normal small">· newest first</span></span>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="search-field"><i class="bi bi-search"></i><input type="text" class="form-control form-control-sm" id="tlSearch" placeholder="Search events or ref no…"></div>
                <a href="<?= url('reports/service_book.php?id=' . $id) ?>" target="_blank" class="btn btn-sm btn-outline-secondary"><i class="bi bi-printer me-1"></i>Service Book</a>
            </div>
        </div>
        <div class="card-body border-bottom py-2"><div class="tl-filters" id="tlFilters">
            <?php foreach (['all' => 'All', 'career' => 'Career', 'posting' => 'Postings', 'pay' => 'Pay & Promotion', 'leave' => 'Leaves', 'discipline' => 'Discipline', 'status' => 'Status changes'] as $k => $l): ?>
            <label class="chip-check"><input type="radio" name="tlf" value="<?= $k ?>" <?= $k === 'all' ? 'checked' : '' ?>><span><?= $l ?></span></label>
            <?php endforeach; ?>
        </div></div>
        <div class="card-body">
            <?php if (!$timeline): ?><div class="empty-state"><i class="bi bi-clock-history"></i>No events yet.</div><?php else: ?>
            <div class="timeline" id="tlList"><?php $tn = count($timeline); ?>
            <?php
            $n = count($timeline); $lastYear = null;
            for ($i = $n - 1; $i >= 0; $i--):
                $t = $timeline[$i];
                $next = $timeline[$i + 1] ?? null;
                $span = $next ? days_between($t['event_date'], $next['event_date']) - 1 : days_between($t['event_date'], today()) - 1;
                $ref = $t['reference_table'] === 'postings' ? ($postingById[(int)$t['reference_id']] ?? null) : null;
                $group = $tlTypeGroup[$t['event_type']] ?? 'status';
                $y = substr($t['event_date'], 0, 4);
                if ($y !== $lastYear): $lastYear = $y; ?><div class="tl-year" data-year="<?= $y ?>"><?= $y ?></div><?php endif; ?>
                <div class="timeline-item tl-<?= e($t['event_type']) ?>" data-group="<?= $group ?>" data-year="<?= $y ?>" data-text="<?= e(strtolower($t['title'] . ' ' . $t['description'] . ' ' . $t['event_type'] . ' ' . ($t['reference_table'] && $t['reference_table'] !== 'employees' ? svc_ref_of($t['reference_table'], (int)$t['reference_id']) : ''))) ?>">
                    <span class="tl-dot"></span>
                    <div>
                        <?php $tRef = $t['reference_table'] && $t['reference_table'] !== 'employees' ? svc_ref_of($t['reference_table'], (int)$t['reference_id']) : ''; ?>
                        <div class="tl-date"><span class="tl-no">#<?= $tn-- ?></span> <?= fmt_date($t['event_date']) ?> · <span class="badge text-bg-light border"><i class="bi <?= $tlIcon[$t['event_type']] ?? 'bi-dot' ?> me-1"></i><?= e($t['event_type']) ?></span><?= $tRef ? ' · <span class="ref-no">' . e($tRef) . '</span>' : '' ?></div>
                        <div class="tl-title"><?= e($t['title']) ?></div>
                        <?php if ($t['description']): ?><div class="tl-desc"><?= e($t['description']) ?></div><?php endif; ?>
                        <?php if ($ref && ($ref['reporting_officer'] || $ref['order_no']) && !str_contains((string)$t['description'], 'Reporting to')): ?>
                            <div class="tl-desc"><?= $ref['reporting_officer'] ? '<i class="bi bi-person-check me-1 text-muted"></i>Reporting officer: <strong>' . e($ref['reporting_officer']) . '</strong>' : '' ?><?= $ref['order_no'] && !str_contains((string)$t['description'], $ref['order_no']) ? ' · Order ' . e($ref['order_no']) : '' ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="tl-side">
                        <span class="tl-days" title="<?= $next ? 'Until next event' : 'Since this event' ?>"><i class="bi bi-hourglass-split me-1"></i><?= $next ? $daysLabel(max(0, $span)) . ' → next' : $daysLabel(max(0, $span)) . ' ago' ?></span>
                        <?php $acts = $t['reference_table'] && $t['reference_table'] !== 'employees'
                            ? $attachBtns($t['reference_table'], (int)$t['reference_id']) . order_button($t['reference_table'], (int)$t['reference_id'])
                            : ''; ?>
                        <div class="tl-actions">
                            <?= $acts ?>
                            <?php if ($t['reference_table'] && $t['reference_table'] !== 'employees'): $tabMap = ['postings' => 'postings', 'leaves' => 'leaves']; ?><a class="btn btn-ghost btn-sm" href="#tab-<?= $tabMap[$t['reference_table']] ?? 'service' ?>" data-tab-link="<?= $tabMap[$t['reference_table']] ?? 'service' ?>" data-ref-go="<?= e($tRef) ?>" title="Open record <?= e($tRef) ?>"><i class="bi bi-box-arrow-in-right"></i></a><?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endfor; ?>
            </div>
            <div class="empty-state d-none" id="tlEmpty"><i class="bi bi-funnel"></i>No events match the filter.</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php endif; ?>
<?php if (is_super_admin()): ?>
<!-- ================= AUDIT ================= -->
<div class="tab-pane fade" id="tab-audit">
    <div class="card"><div class="card-header"><span class="card-title">Change History <span class="text-muted fw-normal small">· last 100</span></span><a href="<?= url('admin/audit_logs.php?employee_id=' . $id) ?>" class="small">Full log</a></div>
    <div class="table-responsive"><table class="table table-sm table-hover align-middle mb-0">
        <thead><tr><th>When</th><th>User</th><th>Action</th><th>Description</th><th>Changes</th><th class="text-end">Details</th></tr></thead>
        <tbody><?php if (!$audit): ?><tr><td colspan="6"><div class="empty-state">No history.</div></td></tr><?php endif; ?>
        <?php foreach ($audit as $a): $old = $a['old_values'] ? json_decode($a['old_values'], true) : null; $new = $a['new_values'] ? json_decode($a['new_values'], true) : null; ?>
            <tr>
                <td class="small text-nowrap"><?= fmt_datetime($a['created_at']) ?><br><span class="text-muted"><?= e($a['ip_address']) ?></span></td>
                <td class="small"><?= e($a['full_name'] ?: $a['username']) ?></td>
                <td><span class="badge text-bg-<?= audit_action_meta($a['action'])[0] ?>"><?= e($a['action']) ?></span></td>
                <td class="small"><?= e($a['description']) ?><br><span class="text-muted"><?= e(audit_record_ref($a['table_name'], (int)$a['record_id'])) ?></span></td>
                <td class="small audit-summary">
                    <?php if ($a['action'] === 'UPDATE' && is_array($new)): $i = 0; ?>
                        <?php foreach ($new as $k => $v): if ($i++ >= 2) break; ?>
                            <div class="text-truncate"><span class="text-muted"><?= e(audit_field_label((string)$k)) ?>:</span> <?= strip_tags(audit_field_value((string)$k, $old[$k] ?? null)) ?> &rarr; <strong><?= strip_tags(audit_field_value((string)$k, $v)) ?></strong></div>
                        <?php endforeach; ?>
                        <?php if (count($new) > 2): ?><div class="text-muted"><?= count($new) - 2 ?> more</div><?php endif; ?>
                    <?php elseif (is_array($new) || is_array($old)): ?>
                        <span class="text-muted"><?= count(array_diff_key(($new ?: $old), array_flip(AUDIT_HIDDEN_FIELDS))) ?> fields recorded</span>
                    <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                </td>
                <td class="text-end"><?= audit_view_button((int)$a['id']) ?></td>
            </tr>
        <?php endforeach; ?></tbody></table></div></div>
</div>
<?php endif; ?>
</div>

<!-- Family modal -->
<div class="modal fade" id="familyModal" tabindex="-1" data-bs-backdrop="static"><div class="modal-dialog modal-lg modal-dialog-centered"><form class="modal-content" id="familyForm" novalidate>
    <div class="modal-header"><h5 class="modal-title" id="familyModalTitle">Add Family Member</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="id" id="familyId" value="0"><input type="hidden" name="employee_id" value="<?= $id ?>"><div class="row g-3" id="familyFields"></div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save</button></div>
</form></div></div>

<?php
$canEditJs = $canEdit ? 'true' : 'false';
$extra_js = <<<JS
<script>
(function(){
  const EMP = $id, canEdit = $canEditJs;
  const hash = location.hash;
  if (hash && document.querySelector('a[href="' + hash + '"]')) new bootstrap.Tab(document.querySelector('a[href="' + hash + '"]')).show();
  $('#empTabs a').on('shown.bs.tab', e => history.replaceState(null, '', e.target.getAttribute('href')));
  function goRef(ref){ if (!ref) return; const el = document.querySelector('[data-ref="' + ref + '"]'); if (!el) return; const acc = el.closest('.accordion-collapse'); if (acc && !acc.classList.contains('show')) bootstrap.Collapse.getOrCreateInstance(acc).show(); setTimeout(() => { el.classList.add('ref-hit'); el.scrollIntoView({ behavior: 'smooth', block: 'center' }); setTimeout(() => el.classList.remove('ref-hit'), 3500); }, 350); }
  $(document).on('click', '[data-tab-link]', function(e){ e.preventDefault(); new bootstrap.Tab(document.querySelector('a[href="#tab-' + this.dataset.tabLink + '"]')).show(); if (this.dataset.refGo) goRef(this.dataset.refGo); else window.scrollTo({ top: 0, behavior: 'smooth' }); });
  const refParam = new URLSearchParams(location.search).get('ref'); if (refParam) setTimeout(() => goRef(refParam), 300);

  // ---- timeline filters
  function tlApply(){
    const g = $('input[name=tlf]:checked').val() || 'all', q = ($('#tlSearch').val() || '').toLowerCase().trim();
    let shown = 0; const years = {};
    $('#tlList .timeline-item').each(function(){ const ok = (g === 'all' || this.dataset.group === g) && (!q || this.dataset.text.includes(q)); this.classList.toggle('hidden', !ok); if (ok) { shown++; years[this.dataset.year] = true; } });
    $('#tlList .tl-year').each(function(){ this.style.display = years[this.dataset.year] ? '' : 'none'; });
    $('#tlEmpty').toggleClass('d-none', shown > 0);
  }
  $('#tlFilters input').on('change', tlApply); $('#tlSearch').on('input', tlApply);

  // ---- family
  const famModal = new bootstrap.Modal('#familyModal');
  function loadFamily(){ $('#familyList').load(HR.url('employees/ajax/family.php?action=list&employee_id=' + EMP + '&view=cards')); }
  loadFamily();
  $('#btnAddFamily').on('click', () => openFamily(0));
  function openFamily(id){
    $('#familyId').val(id); $('#familyModalTitle').text(id ? 'Edit Family Member' : 'Add Family Member');
    $('#familyFields').html('<div class="text-muted">Loading…</div>'); famModal.show();
    $.get(HR.url('employees/ajax/family.php'), { action: 'form', id: id }, html => { $('#familyFields').html(html); HR.initWidgets('#familyFields'); });
  }
  $('#familyForm').on('submit', function(e){ e.preventDefault();
    HR.post('employees/ajax/family.php', $(this).serialize() + '&action=save').done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); if (r.success) { famModal.hide(); loadFamily(); } }); });
  $('#familyList').on('click', '[data-fam-act]', function(){
    const id = this.dataset.id;
    if (this.dataset.famAct === 'edit') return openFamily(id);
    HR.confirm('Remove this family member?').then(ok => ok && HR.post('employees/ajax/family.php', { action: 'delete', id: id }).done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); loadFamily(); }));
  });

  // ---- documents
  function loadDocs(){ $('#docList').load(HR.url('employees/ajax/documents.php?action=list&employee_id=' + EMP + '&view=cards'), function(){ $('#docCount').text($('#docList .doc-card').length); }); }
  loadDocs();
  $('#docForm').on('submit', function(e){ e.preventDefault();
    const fd = new FormData(this); fd.append('action', 'upload');
    const btn = $(this).find('button').prop('disabled', true);
    $.ajax({ url: HR.url('employees/ajax/documents.php'), method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json' })
      .done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); if (r.success) { this.reset(); $(this).find('select').val('CNIC').trigger('change'); loadDocs(); } })
      .fail(x => HR.toast((x.responseJSON && x.responseJSON.message) || 'Upload failed.', 'danger'))
      .always(() => btn.prop('disabled', false)); });
  $('#docList').on('click', '[data-doc-act="delete"]', function(){ const id = this.dataset.id;
    HR.confirm('Delete this document?').then(ok => ok && HR.post('employees/ajax/documents.php', { action: 'delete', id: id }).done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); loadDocs(); })); });
})();
</script>
JS;
if (is_super_admin()) echo audit_modal_html();
require ROOT_PATH . '/includes/footer.php';
