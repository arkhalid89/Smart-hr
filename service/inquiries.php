<?php
/**
 * Inquiries — disciplinary proceedings against an employee.
 * One page does both: initiate an inquiry, and conclude an open one.
 */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_ui.php';

$statusBadge = function (string $status): string {
    return match ($status) {
        'Concluded'     => '<span class="badge bg-secondary-subtle text-body-secondary border">Concluded</span>',
        'Under Inquiry' => '<span class="badge bg-warning-subtle text-warning-emphasis border border-warning-subtle">Under Inquiry</span>',
        default         => '<span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">Open</span>',
    };
};

$GLOBALS['inq_badge'] = $statusBadge;

service_page([
    'key' => 'inquiries', 'title' => 'Inquiries', 'singular' => 'Inquiry', 'icon' => 'bi-search', 'tab' => 'inquiries',
    'doc_type' => 'Inquiry Document',

    'form' => function (array $emp) {
        $open = svc_open_inquiries((int)$emp['id']);
        $old  = $GLOBALS['svc_old'];
        $mode = ($old['mode'] ?? '') === 'conclude' && $open ? 'conclude' : 'initiate';
        ?>
        <?php if ($open): ?>
        <div class="col-12">
            <div class="alert alert-warning py-2 mb-0">
                <i class="bi bi-exclamation-triangle me-1"></i>
                <strong><?= count($open) ?> inquiry<?= count($open) === 1 ? '' : ' proceedings' ?> pending against this employee:</strong>
                <?= e(implode('; ', array_map(fn($i) => $i['ref_no'] . ' — ' . $i['subject'] . ' (' . $i['status'] . ', ' . days_between($i['initiated_date'], today()) . ' days)', $open))) ?>
            </div>
        </div>
        <?php endif; ?>

        <div class="col-12">
            <div class="btn-group btn-group-sm" role="group" id="inqMode">
                <input type="radio" class="btn-check" name="mode" id="m_initiate" value="initiate" <?= $mode === 'initiate' ? 'checked' : '' ?>>
                <label class="btn btn-outline-primary" for="m_initiate"><i class="bi bi-plus-circle me-1"></i>Initiate Inquiry</label>
                <input type="radio" class="btn-check" name="mode" id="m_conclude" value="conclude" <?= $mode === 'conclude' ? 'checked' : '' ?> <?= $open ? '' : 'disabled' ?>>
                <label class="btn btn-outline-primary" for="m_conclude" title="<?= $open ? '' : 'No open inquiry to conclude' ?>"><i class="bi bi-check2-circle me-1"></i>Conclude Inquiry</label>
            </div>
        </div>

        <!-- ============ initiate ============ -->
        <div class="col-12 inq-pane" id="pane-initiate"><div class="row g-3">
            <div class="col-md-4"><label class="form-label">Inquiry Type <span class="req">*</span></label>
                <select name="inquiry_type_id" class="form-select select2" data-placeholder="Select type"><option value=""></option><?= options_html(master_options('inquiry_types'), $old['inquiry_type_id'] ?? '') ?></select></div>
            <div class="col-md-5"><label class="form-label">Subject <span class="req">*</span></label>
                <input type="text" name="subject" class="form-control" maxlength="200" value="<?= svc_old('subject') ?>" placeholder="e.g. Unauthorised absence from duty"></div>
            <div class="col-md-3"><label class="form-label">Initiated On <span class="req">*</span></label>
                <input type="date" name="initiated_date" class="form-control" value="<?= svc_old('initiated_date', today()) ?>" max="<?= today() ?>"></div>

            <div class="col-md-3"><label class="form-label">Order No.</label>
                <input type="text" name="order_no" class="form-control" maxlength="80" value="<?= svc_old('order_no') ?>"></div>
            <div class="col-md-3"><label class="form-label">Order Date</label>
                <input type="date" name="order_date" class="form-control" value="<?= svc_old('order_date') ?>"></div>
            <div class="col-md-3"><label class="form-label">Inquiry Officer</label>
                <input type="text" name="inquiry_officer" class="form-control" maxlength="150" value="<?= svc_old('inquiry_officer') ?>" placeholder="Name &amp; designation"></div>
            <div class="col-md-3"><label class="form-label">Ordering Authority</label>
                <input type="text" name="ordering_authority" class="form-control" maxlength="150" value="<?= svc_old('ordering_authority') ?>"></div>

            <div class="col-md-3"><label class="form-label">Current Stage</label>
                <select name="status" class="form-select">
                    <?php foreach (INQUIRY_OPEN_STATUSES as $s): ?>
                        <option value="<?= e($s) ?>" <?= ($old['status'] ?? 'Open') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-9"><label class="form-label">Allegation / Charge</label>
                <textarea name="allegation" class="form-control" rows="2" maxlength="1000"><?= svc_old('allegation') ?></textarea></div>
            <div class="col-12"><label class="form-label">Remarks</label>
                <input type="text" name="remarks" class="form-control" maxlength="500" value="<?= svc_old('remarks') ?>"></div>
        </div></div>

        <!-- ============ conclude ============ -->
        <div class="col-12 inq-pane" id="pane-conclude"><div class="row g-3">
            <?php if (!$open): ?>
                <div class="col-12"><div class="alert alert-light border mb-0">No open inquiry against this employee.</div></div>
            <?php else: ?>
            <div class="col-md-6"><label class="form-label">Inquiry to Conclude <span class="req">*</span></label>
                <select name="inquiry_id" class="form-select select2" data-placeholder="Select the open inquiry"><option value=""></option>
                    <?php foreach ($open as $i): ?>
                        <option value="<?= (int)$i['id'] ?>" <?= (string)($old['inquiry_id'] ?? '') === (string)$i['id'] ? 'selected' : '' ?>>
                            <?= e($i['ref_no'] . ' — ' . $i['subject'] . ' (' . fmt_date($i['initiated_date']) . ')') ?>
                        </option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label">Outcome <span class="req">*</span></label>
                <select name="outcome" class="form-select"><option value=""></option>
                    <?php foreach (INQUIRY_OUTCOMES as $oc): ?>
                        <option value="<?= e($oc) ?>" <?= ($old['outcome'] ?? '') === $oc ? 'selected' : '' ?>><?= e($oc) ?></option>
                    <?php endforeach; ?>
                </select></div>
            <div class="col-md-3"><label class="form-label">Concluded On <span class="req">*</span></label>
                <input type="date" name="concluded_date" class="form-control" value="<?= svc_old('concluded_date', today()) ?>" max="<?= today() ?>"></div>
            <div class="col-md-3"><label class="form-label">Closing Order No.</label>
                <input type="text" name="closing_order_no" class="form-control" maxlength="80" value="<?= svc_old('closing_order_no') ?>"></div>
            <div class="col-md-9"><label class="form-label">Findings of the Inquiry Officer</label>
                <textarea name="findings" class="form-control" rows="2" maxlength="1000"><?= svc_old('findings') ?></textarea></div>
            <div class="col-12"><div class="alert alert-light border py-2 mb-0 small">
                <i class="bi bi-info-circle me-1"></i>Concluding records the outcome against the same inquiry reference. If a penalty was imposed, record it separately under <a href="<?= url('service/punishments.php?employee_id=' . (int)$emp['id']) ?>">Punishments</a> so that the status automation applies.
            </div></div>
            <?php endif; ?>
        </div></div>

        <script>
        document.addEventListener('DOMContentLoaded', function () {
            function sync() {
                var mode = document.querySelector('input[name="mode"]:checked').value;
                document.getElementById('pane-initiate').style.display = mode === 'initiate' ? '' : 'none';
                document.getElementById('pane-conclude').style.display = mode === 'conclude' ? '' : 'none';
            }
            document.querySelectorAll('input[name="mode"]').forEach(function (r) { r.addEventListener('change', sync); });
            sync();
        });
        </script>
    <?php },

    'save' => function (array $emp, array $p) {
        return ($p['mode'] ?? 'initiate') === 'conclude'
            ? svc_inquiry_conclude($emp, $p)
            : svc_inquiry($emp, $p);
    },

    'flash' => function (int $id, array $emp, array $p, string $ref) {
        return ($p['mode'] ?? 'initiate') === 'conclude'
            ? 'Inquiry ' . $ref . ' concluded — ' . ($p['outcome'] ?? '') . ' (' . $emp['name'] . ').'
            : 'Inquiry ' . $ref . ' initiated against ' . $emp['name'] . '.';
    },

    'list' => [
        'date_col' => 'r.initiated_date',
        'sql' => 'SELECT r.*, e.name AS emp_name, e.employee_code, d.name AS department, t.name AS inquiry_type, t.is_formal, u.full_name AS by_user
                  FROM inquiries r JOIN employees e ON e.id = r.employee_id
                  JOIN inquiry_types t ON t.id = r.inquiry_type_id
                  LEFT JOIN departments d ON d.id = e.department_id LEFT JOIN users u ON u.id = r.created_by
                  WHERE e.is_deleted = 0 AND {WHERE} ORDER BY (r.status <> "Concluded") DESC, r.initiated_date DESC, r.id DESC',
        'extra' => function (array $g) {
            if (($g['state'] ?? '') === 'open')      return ['r.status <> "Concluded"', []];
            if (($g['state'] ?? '') === 'concluded') return ['r.status = "Concluded"', []];
            return [null, []];
        },
        'filters' => function (array $g) { ?>
            <select name="state" class="form-select form-select-sm w-auto" title="Inquiry status">
                <option value="">All inquiries</option>
                <option value="open" <?= ($g['state'] ?? '') === 'open' ? 'selected' : '' ?>>Open only</option>
                <option value="concluded" <?= ($g['state'] ?? '') === 'concluded' ? 'selected' : '' ?>>Concluded only</option>
            </select>
        <?php },
        'summary' => function (array $rows) {
            $open = array_filter($rows, fn($r) => $r['status'] !== 'Concluded' && !$r['is_voided']);
            if (!$open) return ' <span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">No inquiry pending</span>';
            $emps = count(array_unique(array_column($open, 'employee_id')));
            return ' <span class="badge bg-danger-subtle text-danger-emphasis border border-danger-subtle">'
                . count($open) . ' open against ' . $emps . ' employee' . ($emps === 1 ? '' : 's') . '</span>';
        },
        'columns' => [
            'Subject' => fn($r) => '<span class="fw-semibold">' . e($r['subject']) . '</span><br><span class="small text-muted">' . e($r['inquiry_type']) . (!empty($r['is_formal']) ? ' · formal' : '') . '</span>',
            'Initiated' => fn($r) => fmt_date($r['initiated_date']) . ($r['order_no'] ? '<br><span class="small text-muted">Order ' . e($r['order_no']) . '</span>' : ''),
            'Inquiry Officer' => fn($r) => e($r['inquiry_officer'] ?: '-'),
            'Status' => fn($r) => $GLOBALS['inq_badge']($r['status']),
            'Outcome' => fn($r) => $r['status'] === 'Concluded'
                ? e($r['outcome'] ?: '-') . '<br><span class="small text-muted">' . fmt_date($r['concluded_date']) . '</span>'
                : '<span class="small text-muted">pending</span>',
            'Days' => fn($r) => number_format(days_between($r['initiated_date'], $r['concluded_date'] ?: today())),
        ],
    ],
    'export' => [
        'headers' => ['Employee', 'Code', 'Department', 'Inquiry Type', 'Subject', 'Allegation', 'Initiated', 'Order No', 'Inquiry Officer', 'Ordering Authority', 'Status', 'Outcome', 'Concluded', 'Closing Order No', 'Findings', 'Days'],
        'row' => fn($r) => [$r['emp_name'], $r['employee_code'], $r['department'], $r['inquiry_type'], $r['subject'], $r['allegation'],
            $r['initiated_date'], $r['order_no'], $r['inquiry_officer'], $r['ordering_authority'], $r['status'], $r['outcome'],
            $r['concluded_date'], $r['closing_order_no'], $r['findings'], days_between($r['initiated_date'], $r['concluded_date'] ?: today())],
    ],
]);
