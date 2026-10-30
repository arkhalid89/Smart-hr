<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

$manualTypes = ['Routine Posting', 'Transfer Posting', 'Temporary Posting', 'Additional Charge', 'Punishment Posting', 'Promotion Posting'];

service_page([
    'key' => 'postings', 'title' => 'Postings & Transfers', 'singular' => 'Posting', 'icon' => 'bi-geo-alt', 'tab' => 'postings', 'doc_type' => 'Transfer Order',
    'guard' => fn($emp) => $emp['status'] === 'On Deputation' ? 'Employee is on deputation — record the reversion in the Deputations module first.' : null,
    'form' => function (array $emp) use ($manualTypes) {
        $cur = $emp['current_posting_id'] ? db_row('SELECT p.*, l.name AS location FROM postings p LEFT JOIN posting_locations l ON l.id = p.location_id WHERE p.id = ?', [(int)$emp['current_posting_id']]) : null;
        if ($cur) echo '<div class="col-12"><div class="alert alert-light border py-2 mb-0 small"><i class="bi bi-geo me-1"></i><strong>Current:</strong> ' . e($cur['posting_type']) . ' · ' . e($cur['location'] ?: $cur['office'] ?: '-') . ' · since ' . fmt_date($cur['effective_date']) . ($cur['section'] ? ' · Section: ' . e($cur['section']) : '') . '</div></div>';
        if ($emp['status'] === 'Suspended') echo '<div class="col-12"><div class="alert alert-warning py-2 mb-0 small"><i class="bi bi-exclamation-triangle me-1"></i>Employee is suspended: transfers are blocked until reinstatement.</div></div>';
        ?>
        <div class="col-md-3"><label class="form-label">Posting Type <span class="req">*</span></label><select name="posting_type" class="form-select select2" required><?= options_html($manualTypes, $GLOBALS['svc_old']['posting_type'] ?? 'Transfer Posting') ?></select></div>
        <div class="col-md-3"><label class="form-label">Effective Date <span class="req">*</span></label><input type="date" name="effective_date" class="form-control" value="<?= svc_old('effective_date', today()) ?>" required></div>
        <div class="col-12"><div class="form-section-title">Posted to</div>
            <?= geo_picker(['name' => 'department_id', 'value' => $GLOBALS['svc_old']['department_id'] ?? $emp['department_id'], 'required' => true, 'id' => 'postgeo']) ?></div>
        <div class="col-md-3"><label class="form-label">Office / site</label><select name="location_id" class="form-select" data-geo-locations><option value="">— Department's main office —</option><?= posting_location_options_html(isset($GLOBALS['svc_old']['location_id']) ? (int)$GLOBALS['svc_old']['location_id'] : null) ?></select></div>
        <div class="col-md-3"><label class="form-label">Office</label><input type="text" name="office" class="form-control" value="<?= svc_old('office', $cur['office'] ?? '') ?>" maxlength="150"></div>
        <div class="col-md-3"><label class="form-label">Section</label><input type="text" name="section" class="form-control" value="<?= svc_old('section') ?>" maxlength="120"></div>
        <div class="col-md-3"><label class="form-label">Office Order No.</label><input type="text" name="order_no" class="form-control" value="<?= svc_old('order_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Order Date</label><input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
        <div class="col-md-4"><label class="form-label">Reporting Officer</label><input type="text" name="reporting_officer" class="form-control" value="<?= svc_old('reporting_officer') ?>" maxlength="120"></div>
        <div class="col-md-8"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <?php
    },
    'save' => function (array $emp, array $p) use ($manualTypes) {
        if (!in_array($p['posting_type'] ?? '', $manualTypes, true)) throw new RuntimeException('Invalid posting type.');
        svc_guard($emp, ['not_suspended' => $p['posting_type'] === 'Transfer Posting', 'action' => 'Transfer', 'not_on_deputation' => true]);
        $type = $p['posting_type'];
        return svc_new_posting($emp, [
            'posting_type' => $type, 'effective_date' => post_date('effective_date'), 'department_id' => post_int('department_id'), 'location_id' => post_int('location_id'),
            'office' => post('office'), 'section' => post('section'), 'order_no' => post('order_no'), 'order_date' => post_date('order_date'),
            'reporting_officer' => post('reporting_officer'), 'remarks' => post('remarks'),
        ], $type === 'Transfer Posting' ? 'Transfer' : 'Posting', $type);
    },
    'list' => [
        'date_col' => 'r.effective_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, pd.name AS posting_dept, l.name AS location, o.name AS dep_org, u.full_name AS by_user
                  FROM postings r JOIN employees e ON e.id = r.employee_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN departments pd ON pd.id = r.department_id
                  LEFT JOIN posting_locations l ON l.id = r.location_id LEFT JOIN deputation_orgs o ON o.id = r.deputation_org_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.effective_date DESC, r.id DESC',
        'columns' => [
            'Effective' => fn($r) => fmt_date($r['effective_date']) . ($r['end_date'] ? '<br><span class="small text-muted">to ' . fmt_date($r['end_date']) . '</span>' : ''),
            'Type' => fn($r) => e($r['posting_type']) . ($r['dep_org'] ? '<br><span class="small text-primary">' . e($r['dep_org']) . '</span>' : ''),
            'Location / Office' => fn($r) => e($r['location'] ?: '-') . ($r['office'] ? '<br><span class="small text-muted">' . e($r['office']) . '</span>' : ''),
            'Department' => fn($r) => e($r['posting_dept']) . ($r['section'] ? '<br><span class="small text-muted">' . e($r['section']) . '</span>' : ''),
            'Order' => fn($r) => e($r['order_no'] ?: '-') . ($r['order_date'] ? '<br><span class="small text-muted">' . fmt_date($r['order_date']) . '</span>' : ''),
            'Status' => fn($r) => $r['is_current'] ? '<span class="badge text-bg-success">Current</span>' : '<span class="badge text-bg-secondary">Historical</span>',
        ],
    ],
    'export' => [
        'headers' => ['Employee', 'Code', 'Effective Date', 'End Date', 'Type', 'Location', 'Office', 'Department', 'Section', 'Order No', 'Order Date', 'Reporting Officer', 'Deputation Org', 'Current', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['effective_date'], $r['end_date'], $r['posting_type'], $r['location'], $r['office'], $r['posting_dept'], $r['section'], $r['order_no'], $r['order_date'], $r['reporting_officer'], $r['dep_org'], $r['is_current'] ? 'Yes' : 'No', $r['remarks']],
    ],
]);
