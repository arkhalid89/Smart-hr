<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

service_page([
    'key' => 'leaves', 'title' => 'Leaves', 'singular' => 'Leave', 'icon' => 'bi-calendar-x', 'tab' => 'leaves', 'doc_type' => 'Leave Application',
    'form' => function (array $emp) {
        $o = $GLOBALS['svc_old'];
        $months = (int)setting('eol_break_months', 6);
        ?>
        <div class="col-md-4"><label class="form-label">Leave Type <span class="req">*</span></label><select name="leave_type_id" class="form-select select2" data-placeholder="Select leave type" required><option value=""></option>
            <?php foreach (master_options('leave_types') as $t): ?><option value="<?= $t['id'] ?>" data-eol="<?= $t['is_eol'] ?>" data-lpr="<?= $t['is_lpr'] ?>" data-max="<?= (int)$t['max_days'] ?>" <?= (string)($o['leave_type_id'] ?? '') === (string)$t['id'] ? 'selected' : '' ?>><?= e($t['name']) ?><?= $t['max_days'] ? ' (max ' . $t['max_days'] . ' days)' : '' ?></option><?php endforeach; ?></select>
            <div class="form-text" id="leaveHint">EOL without pay longer than <?= $months ?> months is flagged as a break in service and shifts the retirement date.</div></div>
        <div class="col-md-2"><label class="form-label">From <span class="req">*</span></label><input type="date" name="from_date" class="form-control" data-from value="<?= svc_old('from_date') ?>" required></div>
        <div class="col-md-2"><label class="form-label">To <span class="req">*</span></label><input type="date" name="to_date" class="form-control" data-to value="<?= svc_old('to_date') ?>" required></div>
        <div class="col-md-2"><label class="form-label">Duration (days)</label><input type="text" class="form-control" data-duration readonly></div>
        <div class="col-md-2"><label class="form-label">Status</label><select name="status" class="form-select"><?= options_html(['Approved', 'Pending', 'Rejected', 'Cancelled'], $o['status'] ?? 'Approved') ?></select></div>
        <div class="col-md-3"><label class="form-label">Letter No.</label><input type="text" name="letter_no" class="form-control" value="<?= svc_old('letter_no') ?>" maxlength="80"></div>
        <div class="col-md-3"><label class="form-label">Letter Date</label><input type="date" name="letter_date" class="form-control" value="<?= svc_old('letter_date') ?>"></div>
        <div class="col-md-6"><label class="form-label">Remarks</label><input type="text" name="remarks" class="form-control" value="<?= svc_old('remarks') ?>" maxlength="500"></div>
        <script>document.addEventListener("DOMContentLoaded", function(){ $('select[name=leave_type_id]').on('change', function(){ const o = $(this).find(':selected');
            $('#leaveHint').text(o.data('lpr') == 1 ? 'Approving LPR sets the employee status to On LPR.' : (o.data('eol') == 1 ? 'EOL without pay longer than <?= $months ?> months = break in service (retirement date shifts).' : (o.data('max') ? 'Maximum ' + o.data('max') + ' days per instance.' : ''))); }); });</script>
        <?php
    },
    'save' => fn(array $emp, array $p) => svc_leave($emp, ['leave_type_id' => post_int('leave_type_id'), 'from_date' => post_date('from_date'), 'to_date' => post_date('to_date'), 'status' => post('status'), 'letter_no' => post('letter_no'), 'letter_date' => post_date('letter_date'), 'remarks' => post('remarks')]),
    'list' => [
        'date_col' => 'r.from_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, t.name AS leave_type, t.is_eol, t.is_lpr, u.full_name AS by_user
                  FROM leaves r JOIN employees e ON e.id = r.employee_id JOIN leave_types t ON t.id = r.leave_type_id LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE e.is_deleted = 0 AND {WHERE} ORDER BY r.from_date DESC, r.id DESC',
        'columns' => [
            'Type' => fn($r) => e($r['leave_type']) . ($r['is_eol'] ? ' <span class="badge text-bg-warning">EOL</span>' : '') . ($r['is_lpr'] ? ' <span class="badge text-bg-info">LPR</span>' : ''),
            'Period' => fn($r) => fmt_date($r['from_date']) . ' → ' . fmt_date($r['to_date']),
            'Days' => fn($r) => (int)$r['duration_days'],
            'Letter' => fn($r) => e($r['letter_no'] ?: '-') . ($r['letter_date'] ? '<br><span class="small text-muted">' . fmt_date($r['letter_date']) . '</span>' : ''),
            'Status' => fn($r) => '<span class="badge text-bg-' . ($r['status'] === 'Approved' ? 'success' : ($r['status'] === 'Pending' ? 'warning' : 'secondary')) . '">' . e($r['status']) . '</span>' . ($r['is_break_in_service'] ? ' <span class="badge text-bg-danger">Break in service</span>' : ''),
        ],
    ],
    'export' => ['headers' => ['Employee', 'Code', 'Leave Type', 'From', 'To', 'Days', 'Status', 'Letter No', 'Letter Date', 'Break in Service', 'Remarks'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['leave_type'], $r['from_date'], $r['to_date'], $r['duration_days'], $r['status'], $r['letter_no'], $r['letter_date'], $r['is_break_in_service'] ? 'Yes' : 'No', $r['remarks']]],
]);
