<?php
/** Chronological Service Book / Timeline report for one employee (print + Excel). */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_print.php';
require_staff();
$id  = get_int('id', 0);
$emp = employee_load_or_fail($id, is_super_admin());
$events = db_all('SELECT * FROM timeline_events WHERE employee_id = ? ORDER BY event_date, id', [$id]);
$increments = db_all('SELECT * FROM increments WHERE employee_id = ? AND is_voided = 0 ORDER BY effective_date', [$id]);
$postings = []; foreach (db_all('SELECT p.*, l.name AS location, d.name AS department FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id LEFT JOIN departments d ON d.id = p.department_id WHERE p.employee_id = ?', [$id]) as $p) $postings[(int)$p['id']] = $p;
$inquiries = db_all('SELECT i.*, t.name AS inquiry_type, t.is_formal FROM inquiries i JOIN inquiry_types t ON t.id = i.inquiry_type_id
                     WHERE i.employee_id = ? AND i.is_voided = 0 ORDER BY i.initiated_date, i.id', [$id]);
$openInquiries = array_values(array_filter($inquiries, fn($q) => $q['status'] !== 'Concluded'));
$docCounts = []; foreach (db_all('SELECT related_table, related_id, COUNT(*) c FROM employee_documents WHERE employee_id = ? AND is_deleted = 0 AND related_table IS NOT NULL GROUP BY related_table, related_id', [$id]) as $d) $docCounts[$d['related_table'] . '|' . $d['related_id']] = (int)$d['c'];
log_activity('EXPORT', 'reports', $id, null, null, 'Service book ' . (get('export') ? 'exported' : 'printed') . ': ' . $emp['name'], $id);
$serviceEnd = in_array($emp['status'], EXITED_STATUSES, true) ? (db_value('SELECT MAX(exit_date) FROM exits WHERE employee_id = ? AND is_voided = 0', [$id]) ?: today()) : today();
$serviceDays = date_create($emp['joining_date'])->diff(date_create($serviceEnd));

/* ---- build rows with duration (days until the next event / until today) ---- */
$rows = [];
$n = count($events);
foreach ($events as $i => $ev) {
    $next = $events[$i + 1] ?? null;
    $span = max(0, ($next ? days_between($ev['event_date'], $next['event_date']) : days_between($ev['event_date'], today())) - 1);
    $ref = $ev['reference_table'] === 'postings' ? ($postings[(int)$ev['reference_id']] ?? null) : null;
    $extra = [];
    if ($ref) {
        if ($ref['reporting_officer']) $extra[] = 'Reporting Officer: ' . $ref['reporting_officer'];
        if ($ref['order_no'] && !str_contains((string)$ev['description'], (string)$ref['order_no'])) $extra[] = 'Order ' . $ref['order_no'] . ($ref['order_date'] ? ' dt. ' . fmt_date($ref['order_date']) : '');
        if ($ref['effective_date']) $extra[] = 'Posting tenure: ' . days_between($ref['effective_date'], $ref['is_current'] ? today() : ($ref['end_date'] ?: $ref['effective_date'])) . ' days' . ($ref['is_current'] ? ' (current)' : '');
    }
    $rows[] = [
        'date' => $ev['event_date'], 'type' => $ev['event_type'], 'title' => $ev['title'], 'desc' => (string)$ev['description'],
        'reporting' => $ref['reporting_officer'] ?? '', 'extra' => implode(' · ', $extra), 'days' => $span, 'days_label' => $next ? $span . ' days → next event' : $span . ' days (to date)',
        'ref' => $ev['reference_table'] && $ev['reference_table'] !== 'employees' ? svc_ref_of($ev['reference_table'], (int)$ev['reference_id']) : '',
        'docs' => $ev['reference_table'] ? ($docCounts[$ev['reference_table'] . '|' . $ev['reference_id']] ?? 0) : 0,
    ];
}

/* ---- Excel export ---- */
if (get('export')) {
    require_once ROOT_PATH . '/includes/export.php';
    $out = [];
    foreach ($rows as $i => $r) $out[] = [$i + 1, $r['date'], $r['type'], $r['ref'], $r['title'], $r['desc'], $r['reporting'], $r['extra'], $r['days'], $r['docs']];
    foreach ($inquiries as $q) {
        $out[] = [count($out) + 1, $q['initiated_date'], 'Inquiry', $q['ref_no'], $q['subject'],
            trim((string)$q['allegation'] . ($q['findings'] ? ' | Findings: ' . $q['findings'] : '')),
            $q['inquiry_officer'], trim($q['inquiry_type'] . ($q['order_no'] ? ' · Order ' . $q['order_no'] : '')
                . ' · ' . $q['status'] . ($q['outcome'] ? ' · ' . $q['outcome'] : '')),
            days_between($q['initiated_date'], $q['concluded_date'] ?: today()),
            $docCounts['inquiries|' . $q['id']] ?? 0];
    }
    export_table('service_book_' . $emp['employee_code'], ['#', 'Date', 'Event', 'Ref No.', 'Particulars', 'Details', 'Reporting Officer', 'Order / Tenure', 'Days (until next event)', 'Attachments'], $out,
        setting('org_name', ORG_NAME) . ' — Service Book: ' . $emp['name'] . ' (' . $emp['employee_code'] . ')');
}

print_header('Service Book', $emp['employee_code'] . ' · ' . fmt_date(today()));
?>
<div class="toolbar no-print" style="right:230px"><a class="btn btn-outline-success btn-sm" href="<?= url('reports/service_book.php?id=' . $id . '&export=1&format=xlsx') ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a></div>
<div class="d-flex justify-content-between gap-4 mb-2">
    <div>
        <h2 class="h4 mb-1"><?= e($emp['name']) ?> <span class="badge bg-secondary"><?= e($emp['status']) ?></span></h2>
        <div class="text-muted"><?= e($emp['designation']) ?> (<?= e($emp['pay_scale'] ?: '-') ?>) · <?= e($emp['department']) ?></div>
        <dl class="kv mt-2">
            <dt>Employee Code</dt><dd><?= e($emp['employee_code']) ?></dd><dt>CNIC</dt><dd><?= e($emp['cnic']) ?></dd>
            <dt>Father / Husband</dt><dd><?= e($emp['father_husband_name']) ?></dd><dt>Date of Birth</dt><dd><?= fmt_date($emp['dob']) ?></dd>
            <dt>Joined</dt><dd><?= fmt_date($emp['joining_date']) ?></dd><dt>Length of Service</dt><dd><?= $serviceDays->y ?> years, <?= $serviceDays->m ?> months, <?= $serviceDays->d ?> days<?= $emp['eol_break_days'] ? ' (less ' . (int)$emp['eol_break_days'] . ' days break)' : '' ?></dd>
            <dt>Retirement Date</dt><dd><?= fmt_date($emp['retirement_date']) ?></dd><dt>Service Book No.</dt><dd><?= e($emp['service_book_no'] ?: '-') ?></dd>
        </dl>
    </div>
    <div class="photo-wrap"><img src="<?= photo_url($emp['photo'], $id) ?>" class="photo" alt="Photograph of <?= e($emp['name']) ?>"><div class="photo-cap"><?= e($emp['employee_code']) ?></div></div>
</div>

<h2 class="sec">Chronological Service History</h2>
<table class="table table-sm table-bordered"><thead><tr><th style="width:84px">Date</th><th style="width:100px">Event</th><th style="width:110px">Ref No.</th><th>Particulars</th><th style="width:150px">Reporting Officer / Order</th><th style="width:78px" class="text-end">Days</th><th style="width:40px" class="text-center">Att.</th></tr></thead><tbody>
<?php $year = null; foreach ($rows as $r): ?>
    <?php if (substr($r['date'], 0, 4) !== $year): $year = substr($r['date'], 0, 4); ?><tr class="table-light"><td colspan="7" class="fw-bold"><?= $year ?></td></tr><?php endif; ?>
    <tr>
        <td><?= fmt_date($r['date']) ?></td>
        <td><strong><?= e($r['type']) ?></strong></td>
        <td class="small"><?= e($r['ref']) ?></td>
        <td><?= e($r['title']) ?><?= $r['desc'] ? '<br><span class="text-muted">' . e($r['desc']) . '</span>' : '' ?></td>
        <td class="small"><?= $r['reporting'] ? '<strong>' . e($r['reporting']) . '</strong>' : '' ?><?= $r['extra'] ? '<br><span class="text-muted">' . e(preg_replace('/^Reporting Officer: [^·]+·?\s*/', '', $r['extra'])) . '</span>' : '' ?></td>
        <td class="text-end" title="<?= e($r['days_label']) ?>"><?= number_format($r['days']) ?></td>
        <td class="text-center"><?= $r['docs'] ? '<i class="bi bi-paperclip"></i> ' . $r['docs'] : '' ?></td>
    </tr>
<?php endforeach; ?>
<?php if (!$rows): ?><tr><td colspan="7" class="text-muted">No events recorded.</td></tr><?php endif; ?>
</tbody></table>
<div class="text-muted" style="font-size:11px">Days = number of days from the event until the next recorded event (or until today for the latest event). Att. = attached documents.</div>

<h2 class="sec">Pay Progression</h2>
<table class="table table-sm table-bordered"><thead><tr><th>Effective</th><th>Previous</th><th>New</th><th>Order</th><th>Kind</th></tr></thead><tbody>
<?php foreach ($increments as $i): ?><tr><td><?= fmt_date($i['effective_date']) ?></td><td><?= fmt_money($i['previous_salary']) ?></td><td><?= fmt_money($i['new_salary']) ?></td><td><?= e($i['order_no'] ?: '-') ?></td><td><?= $i['is_back_benefit'] ? 'Back benefit' : 'Annual increment' ?></td></tr><?php endforeach; ?>
<?php if (!$increments): ?><tr><td colspan="5" class="text-muted">None</td></tr><?php endif; ?>
</tbody></table>

<h2 class="sec">Inquiries / Disciplinary Proceedings</h2>
<?php if ($openInquiries): ?>
<div style="border:1.5px solid #b45309;background:#fffbeb;padding:6px 10px;margin-bottom:8px;font-weight:600;color:#92400e;-webkit-print-color-adjust:exact;print-color-adjust:exact">
    <?= count($openInquiries) ?> inquiry proceeding<?= count($openInquiries) === 1 ? ' is' : 's are' ?> pending against this official as on <?= fmt_date(today()) ?>.
</div>
<?php endif; ?>
<table class="table table-sm table-bordered"><thead><tr><th style="width:96px">Ref No.</th><th style="width:78px">Initiated</th><th>Subject / Allegation</th><th style="width:130px">Type</th><th style="width:120px">Inquiry Officer</th><th style="width:96px">Status</th><th style="width:130px">Outcome</th><th style="width:52px" class="text-end">Days</th></tr></thead><tbody>
<?php foreach ($inquiries as $q): ?><tr>
    <td class="small"><?= e($q['ref_no']) ?></td>
    <td><?= fmt_date($q['initiated_date']) ?><?= $q['order_no'] ? '<br><span class="text-muted" style="font-size:10px">Order ' . e($q['order_no']) . '</span>' : '' ?></td>
    <td><?= e($q['subject']) ?><?= $q['allegation'] ? '<br><span class="text-muted">' . e($q['allegation']) . '</span>' : '' ?></td>
    <td class="small"><?= e($q['inquiry_type']) ?></td>
    <td class="small"><?= e($q['inquiry_officer'] ?: '-') ?></td>
    <td><?= $q['status'] === 'Concluded' ? 'Concluded' : '<strong>' . e($q['status']) . '</strong>' ?></td>
    <td class="small"><?= $q['status'] === 'Concluded' ? e($q['outcome'] ?: '-') . '<br><span class="text-muted">' . fmt_date($q['concluded_date']) . '</span>' : '—' ?></td>
    <td class="text-end"><?= number_format(days_between($q['initiated_date'], $q['concluded_date'] ?: today())) ?></td>
</tr><?php endforeach; ?>
<?php if (!$inquiries): ?><tr><td colspan="8" class="text-muted">No inquiry has been initiated against this official.</td></tr><?php endif; ?>
</tbody></table>
<?php print_footer(); ?>
