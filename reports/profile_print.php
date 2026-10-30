<?php
/** Comprehensive printable employee profile. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_print.php';
require_staff();
$id  = get_int('id', 0);
$emp = employee_load_or_fail($id, is_super_admin());
$posting = $emp['current_posting_id'] ? db_row('SELECT p.*, l.name AS location, o.name AS org FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id LEFT JOIN deputation_orgs o ON o.id = p.deputation_org_id WHERE p.id = ?', [(int)$emp['current_posting_id']]) : null;
$family = db_all('SELECT * FROM employee_family WHERE employee_id = ? AND is_deleted = 0 ORDER BY is_nominee DESC, id', [$id]);
$postings = db_all('SELECT p.*, l.name AS location, d.name AS department FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id LEFT JOIN departments d ON d.id = p.department_id WHERE p.employee_id = ? AND p.is_voided = 0 ORDER BY p.effective_date', [$id]);
$promotions = db_all('SELECT p.*, nd.name AS new_desig, ns.name AS new_scale, ps.name AS prev_scale FROM promotions p LEFT JOIN designations nd ON nd.id = p.new_designation_id LEFT JOIN pay_scales ns ON ns.id = p.new_scale_id LEFT JOIN pay_scales ps ON ps.id = p.previous_scale_id WHERE p.employee_id = ? AND p.is_voided = 0 ORDER BY p.effective_date', [$id]);
$deputations = db_all('SELECT d.*, o.name AS org FROM deputations d JOIN deputation_orgs o ON o.id = d.deputation_org_id WHERE d.employee_id = ? AND d.is_voided = 0 ORDER BY d.start_date', [$id]);
$punish = db_all('SELECT p.*, t.name AS type FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? AND p.is_voided = 0 ORDER BY p.order_date', [$id]);
$leaves = db_all('SELECT l.*, t.name AS type FROM leaves l JOIN leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? AND l.is_voided = 0 AND l.status = "Approved" ORDER BY l.from_date', [$id]);
$exits = db_all('SELECT * FROM exits WHERE employee_id = ? AND is_voided = 0 ORDER BY exit_date', [$id]);
$inquiries = db_all('SELECT i.*, t.name AS inquiry_type FROM inquiries i JOIN inquiry_types t ON t.id = i.inquiry_type_id
                     WHERE i.employee_id = ? AND i.is_voided = 0 ORDER BY (i.status <> "Concluded") DESC, i.initiated_date', [$id]);
$openInquiries = array_values(array_filter($inquiries, fn($q) => $q['status'] !== 'Concluded'));
log_activity('EXPORT', 'reports', $id, null, null, 'Profile report printed: ' . $emp['name'], $id);

print_header('Employee Profile Report', $emp['employee_code'] . ' · ' . fmt_date(today()));
?>
<div class="d-flex justify-content-between gap-4">
    <div class="flex-grow-1">
        <h2 class="h4 mb-1"><?= e($emp['name']) ?> <span class="badge bg-secondary"><?= e($emp['status']) ?></span></h2>
        <div class="text-muted mb-3"><?= e($emp['designation']) ?> (<?= e($emp['pay_scale'] ?: '-') ?>) · <?= e($emp['department']) ?></div>
        <div class="row">
            <div class="col-6"><dl class="kv">
                <dt>Employee Code</dt><dd><?= e($emp['employee_code']) ?></dd><dt>CNIC</dt><dd><?= e($emp['cnic']) ?></dd>
                <dt>Father / Husband</dt><dd><?= e($emp['father_husband_name']) ?></dd><dt>Gender</dt><dd><?= e($emp['gender']) ?></dd>
                <dt>Date of Birth</dt><dd><?= fmt_date($emp['dob']) ?> (<?= calc_age($emp['dob']) ?> yrs)</dd><dt>Marital Status</dt><dd><?= e($emp['marital_status'] ?: '-') ?></dd>
                <dt>Blood Group</dt><dd><?= e($emp['blood_group'] ?: '-') ?></dd><dt>Contact</dt><dd><?= e($emp['contact'] ?: '-') ?></dd><dt>Address</dt><dd><?= e($emp['address'] ?: '-') ?></dd></dl></div>
            <div class="col-6"><dl class="kv">
                <dt>Joining Date</dt><dd><?= fmt_date($emp['joining_date']) ?></dd><dt>Employment Type</dt><dd><?= e($emp['employment_type'] ?: '-') ?></dd>
                <?php if ($emp['contract_to']): ?><dt>Contract</dt><dd><?= fmt_date($emp['contract_from']) ?> → <?= fmt_date($emp['contract_to']) ?></dd><?php endif; ?>
                <dt>Basic Salary</dt><dd>Rs. <?= fmt_money($emp['basic_salary']) ?></dd>
                <dt>Probation</dt><dd><?= $emp['probation_start'] ? fmt_date($emp['probation_start']) . ' → ' . fmt_date($emp['probation_end']) : '-' ?></dd>
                <dt>Confirmation</dt><dd><?= $emp['confirmation_date'] ? fmt_date($emp['confirmation_date']) . ($emp['confirmation_order_no'] ? ' (' . e($emp['confirmation_order_no']) . ')' : '') : 'Not confirmed' ?></dd>
                <dt>Retirement Date</dt><dd><?= fmt_date($emp['retirement_date']) ?><?= $emp['eol_break_days'] ? ' (incl. ' . (int)$emp['eol_break_days'] . ' days EOL break)' : '' ?></dd>
                <dt>Service Book No.</dt><dd><?= e($emp['service_book_no'] ?: '-') ?></dd><dt>GP Fund / Pension</dt><dd><?= e($emp['gp_fund_no'] ?: '-') ?> / <?= e($emp['pension_no'] ?: '-') ?></dd><dt>Seniority No.</dt><dd><?= e($emp['seniority_no'] ?: '-') ?></dd></dl></div>
        </div>
    </div>
    <div class="photo-wrap"><img src="<?= photo_url($emp['photo'], $id) ?>" class="photo" alt="Photograph of <?= e($emp['name']) ?>"><div class="photo-cap"><?= e($emp['employee_code']) ?></div></div>
</div>

<h2 class="sec">Current Posting</h2>
<?php if ($posting): ?><dl class="kv"><dt>Location</dt><dd><?= e($posting['location'] ?: $posting['office'] ?: '-') ?></dd><dt>Type</dt><dd><?= e($posting['posting_type']) ?><?= $posting['org'] ? ' — ' . e($posting['org']) : '' ?></dd><dt>Since</dt><dd><?= fmt_date($posting['effective_date']) ?></dd><dt>Order</dt><dd><?= e($posting['order_no'] ?: '-') ?></dd><dt>Reporting Officer</dt><dd><?= e($posting['reporting_officer'] ?: '-') ?></dd></dl><?php else: ?><div class="text-muted">—</div><?php endif; ?>

<h2 class="sec">Posting History</h2>
<table class="table table-sm table-bordered"><thead><tr><th>From</th><th>To</th><th>Days</th><th>Type</th><th>Location / Office</th><th>Department</th><th>Order</th><th>Reporting Officer</th></tr></thead><tbody>
<?php foreach ($postings as $p): ?><tr><td><?= fmt_date($p["effective_date"]) ?></td><td><?= $p["is_current"] ? "Present" : fmt_date($p["end_date"]) ?></td><td><?= days_between($p["effective_date"], $p["is_current"] ? today() : ($p["end_date"] ?: $p["effective_date"])) ?></td><td><?= e($p["posting_type"]) ?></td><td><?= e($p["location"] ?: $p["office"] ?: "-") ?></td><td><?= e($p["department"]) ?></td><td><?= e($p["order_no"] ?: "-") ?></td><td><?= e($p["reporting_officer"] ?: "-") ?></td></tr><?php endforeach; ?>
<?php if (!$postings): ?><tr><td colspan="8" class="text-muted">None</td></tr><?php endif; ?></tbody></table>

<h2 class="sec">Promotions &amp; Upgradations</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Type</th><th>To</th><th>Scale</th><th>Order</th><th>Authority</th></tr></thead><tbody>
<?php foreach ($promotions as $p): ?><tr><td><?= fmt_date($p['effective_date']) ?></td><td><?= e($p['promotion_type']) ?></td><td><?= e($p['new_desig'] ?: '(same post)') ?></td><td><?= e($p['prev_scale'] ?: '-') ?> → <?= e($p['new_scale']) ?></td><td><?= e($p['order_no'] ?: '-') ?></td><td><?= e($p['order_by_authority'] ?: '-') ?></td></tr><?php endforeach; ?>
<?php if (!$promotions): ?><tr><td colspan="6" class="text-muted">None</td></tr><?php endif; ?></tbody></table>

<div class="row">
<div class="col-6"><h2 class="sec">Deputations</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Organization</th><th>From</th><th>Reverted</th></tr></thead><tbody>
<?php foreach ($deputations as $d): ?><tr><td><?= e($d['org']) ?></td><td><?= fmt_date($d['start_date']) ?></td><td><?= $d['actual_reversion_date'] ? fmt_date($d['actual_reversion_date']) : 'On deputation' ?></td></tr><?php endforeach; ?>
<?php if (!$deputations): ?><tr><td colspan="3" class="text-muted">None</td></tr><?php endif; ?></tbody></table></div>
<div class="col-6"><h2 class="sec">Punishments / Suspensions</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Status</th></tr></thead><tbody>
<?php foreach ($punish as $p): ?><tr><td><?= fmt_date($p['order_date']) ?></td><td><?= e($p['type']) ?></td><td><?= e($p['order_no'] ?: '-') ?></td><td><?= $p['suspension_start'] ? ($p['is_reinstated'] ? 'Reinstated' : 'Suspended') : '-' ?></td></tr><?php endforeach; ?>
<?php if (!$punish): ?><tr><td colspan="4" class="text-muted">None</td></tr><?php endif; ?></tbody></table></div>
</div>

<h2 class="sec">Leave Record (approved)</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Type</th><th>From</th><th>To</th><th>Days</th><th>Letter</th><th>Remarks</th></tr></thead><tbody>
<?php foreach ($leaves as $l): ?><tr><td><?= e($l['type']) ?><?= $l['is_break_in_service'] ? ' <strong>(Break in service)</strong>' : '' ?></td><td><?= fmt_date($l['from_date']) ?></td><td><?= fmt_date($l['to_date']) ?></td><td><?= (int)$l['duration_days'] ?></td><td><?= e($l['letter_no'] ?: '-') ?></td><td><?= e($l['remarks'] ?: '-') ?></td></tr><?php endforeach; ?>
<?php if (!$leaves): ?><tr><td colspan="6" class="text-muted">None</td></tr><?php endif; ?></tbody></table>

<?php if ($exits): ?><h2 class="sec">Exit / Separation</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Date</th><th>Type</th><th>Order</th><th>Details</th><th>Rejoined</th></tr></thead><tbody>
<?php foreach ($exits as $x): ?><tr><td><?= fmt_date($x['exit_date']) ?></td><td><?= e($x['exit_type']) ?></td><td><?= e($x['order_no'] ?: '-') ?></td><td><?= e(trim(($x['medical_board_ref'] ? 'Medical Board ' . $x['medical_board_ref'] . ' ' : '') . ($x['date_of_death'] ? 'Date of death ' . fmt_date($x['date_of_death']) . ' ' : '') . ($x['remarks'] ?: ''))) ?: '-' ?></td><td><?= $x['is_rejoined'] ? 'Yes' : 'No' ?></td></tr><?php endforeach; ?></tbody></table><?php endif; ?>

<h2 class="sec">Inquiries / Disciplinary Proceedings</h2>
<?php if ($openInquiries): ?>
<div style="border:1.5px solid #b45309;background:#fffbeb;padding:6px 10px;margin-bottom:8px;font-weight:600;color:#92400e;-webkit-print-color-adjust:exact;print-color-adjust:exact">
    <?= count($openInquiries) ?> inquiry proceeding<?= count($openInquiries) === 1 ? ' is' : 's are' ?> pending against this official as on <?= fmt_date(today()) ?>.
</div>
<?php endif; ?>
<table class="table table-sm table-bordered"><thead><tr><th>Ref No.</th><th>Initiated</th><th>Subject</th><th>Type</th><th>Inquiry Officer</th><th>Status</th><th>Outcome</th><th>Concluded</th></tr></thead><tbody>
<?php foreach ($inquiries as $q): ?><tr>
    <td><?= e($q['ref_no']) ?></td><td><?= fmt_date($q['initiated_date']) ?></td>
    <td><?= e($q['subject']) ?><?= $q['allegation'] ? '<br><span class="text-muted">' . e($q['allegation']) . '</span>' : '' ?></td>
    <td><?= e($q['inquiry_type']) ?></td><td><?= e($q['inquiry_officer'] ?: '-') ?></td>
    <td><?= $q['status'] === 'Concluded' ? 'Concluded' : '<strong>' . e($q['status']) . '</strong>' ?></td>
    <td><?= e($q['outcome'] ?: '-') ?></td><td><?= $q['concluded_date'] ? fmt_date($q['concluded_date']) : '-' ?></td>
</tr><?php endforeach; ?>
<?php if (!$inquiries): ?><tr><td colspan="8" class="text-muted">No inquiry has been initiated against this official.</td></tr><?php endif; ?>
</tbody></table>

<h2 class="sec">Family &amp; Nominees</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Name</th><th>Relation</th><th>CNIC</th><th>DOB</th><th>Contact</th><th>Nominee</th><th>Flags</th></tr></thead><tbody>
<?php foreach ($family as $f): ?><tr><td><?= e($f['name']) ?></td><td><?= e($f['relation']) ?></td><td><?= e($f['cnic'] ?: '-') ?></td><td><?= fmt_date($f['dob']) ?></td><td><?= e($f['contact'] ?: '-') ?></td><td><?= $f['is_nominee'] ? 'Yes' . ($f['nominee_share'] !== null ? ' (' . (float)$f['nominee_share'] . '%)' : '') : '-' ?></td><td><?= trim(($f['is_emergency_contact'] ? 'Emergency ' : '') . ($f['is_pension_claimant'] ? 'Pension claimant' : '')) ?: '-' ?></td></tr><?php endforeach; ?>
<?php if (!$family): ?><tr><td colspan="7" class="text-muted">None recorded</td></tr><?php endif; ?></tbody></table>
<?php print_footer(); ?>
