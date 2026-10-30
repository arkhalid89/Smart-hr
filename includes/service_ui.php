<?php
/**
 * Shared scaffolding for every service-record page (service/*.php).
 *
 *  $cfg = [
 *    'key'      => 'leaves',              // sidebar key / table name
 *    'title'    => 'Leaves',
 *    'icon'     => 'bi-calendar-x',
 *    'singular' => 'Leave',
 *    'save'     => function(array $emp, array $post): int { ... return record id; },
 *    'guard'    => function(array $emp): ?string { return null|reason },   // optional extra rule for showing form
 *    'form'     => function(array $emp): void { echo fields },
 *    'list'     => ['sql' => ..., 'params' => [...], 'columns' => [ 'Label' => fn($r) => html ]],
 *    'export'   => ['headers' => [...], 'row' => fn($r) => [...]],
 *    'doc_type' => 'Leave Application',   // document type for the optional upload
 *  ]
 */
declare(strict_types=1);
require_once ROOT_PATH . '/includes/service_engine.php';
require_once ROOT_PATH . '/includes/order_ui.php';

function service_page(array $cfg): void
{
    require_editor();
    $empId = (int)($_REQUEST['employee_id'] ?? 0);
    $emp   = $empId ? employee_get($empId) : null;
    if ($empId && !$emp) { flash('danger', 'Employee not found.'); redirect('service/' . $cfg['key'] . '.php'); }
    if ($emp) require_employee_access($emp, true);

    /* ---------- save ---------- */
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $emp) {
        csrf_verify();
        db_begin();
        try {
            $id = ($cfg['save'])($emp, $_POST);
            $docId = document_store($empId, 'document', $cfg['doc_type'] ?? 'Service Document', (string)post('document_title', $cfg['singular'] . ' #' . $id), $cfg['key'], $id);
            db_commit();
            $refNo = svc_ref_of($cfg['key'], $id);
            $msg = !empty($cfg['flash'])
                ? ($cfg['flash'])($id, $emp, $_POST, $refNo)
                : $cfg['singular'] . ' ' . $refNo . ' recorded successfully for ' . $emp['name'] . '.';
            flash('success', $msg . ($docId ? ' Document attached.' : ''));

            /* ---- notify the employee (Email / SMS / WhatsApp as selected by the officer) ---- */
            $channels = array_values(array_intersect(array_keys(NOTIFY_CHANNELS), (array)($_POST['notify'] ?? [])));
            if ($channels) {
                $ev = db_row('SELECT title, description, event_date FROM timeline_events WHERE employee_id = ? AND reference_table = ? AND reference_id = ? ORDER BY id DESC LIMIT 1', [$empId, $cfg['key'], $id]);
                $summary = ($ev ? trim($ev['title'] . ($ev['description'] ? ' (' . $ev['description'] . ')' : '')) : $cfg['singular']) . ' [Ref ' . $refNo . ']';
                $message = trim((string)post('notify_message', '')) ?: notify_default_message($emp, (preg_match('/^[aeiou]/i', $cfg['singular']) ? 'an ' : 'a ') . strtolower($cfg['singular']), $summary, $ev['event_date'] ?? null);
                $subject = setting('org_name', ORG_NAME) . ' — ' . $cfg['singular'] . ' ' . $refNo;
                $res = notify_employee(employee_get($empId) ?: $emp, $channels, $subject, $message, $cfg['key'], $id);
                $allOk = $res && !in_array(false, array_column($res, 'ok'), true);
                flash($allOk ? 'success' : 'warning', 'Employee notification — ' . notify_result_text($res));
            }
            /* Go straight to the order so the officer can set the filing number and
               adjust the wording and the distribution list before printing. */
            if (order_supported($cfg['key'])) {
                redirect('reports/order.php?table=' . rawurlencode($cfg['key']) . '&id=' . $id . '&new=1');
            }
            redirect('employees/view.php?id=' . $empId . '#tab-' . ($cfg['tab'] ?? 'service'));
        } catch (Throwable $e) {
            db_rollback();
            flash('danger', $e->getMessage());
            $_SESSION['_svc_old'] = $_POST;
            redirect('service/' . $cfg['key'] . '.php?employee_id=' . $empId);
        }
    }

    /* ---------- export ---------- */
    if (get('export') && !empty($cfg['export'])) {
        require_once ROOT_PATH . '/includes/export.php';
        [$sql, $params] = service_list_query($cfg);
        $rows = db_all($sql, $params);
        $out = [];
        foreach ($rows as $i => $r) $out[] = array_merge([$i + 1, $r['ref_no']], ($cfg['export']['row'])($r));
        log_activity('EXPORT', $cfg['key'], null, null, null, $cfg['title'] . ' exported');
        export_table($cfg['key'], array_merge(['#', 'Ref No.'], $cfg['export']['headers']), $out, setting('org_name', ORG_NAME) . ' — ' . $cfg['title']);
    }

    /* ---------- page ---------- */
    $old = $_SESSION['_svc_old'] ?? [];
    unset($_SESSION['_svc_old']);
    $GLOBALS['svc_old'] = $old;

    $page_title  = $cfg['title'];
    $active_menu = $cfg['key'];
    $breadcrumbs = ['Service Records' => null, $cfg['title'] => null];
    $page_actions = '<div class="btn-group btn-group-sm"><a href="' . e(url('service/' . $cfg['key'] . '.php?' . http_build_query(array_merge($_GET, ['export' => 'xlsx', 'format' => 'xlsx'])))) . '" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>'
        . '<a href="' . e(url('service/' . $cfg['key'] . '.php?' . http_build_query(array_merge($_GET, ['export' => 'csv', 'format' => 'csv'])))) . '" class="btn btn-outline-success">CSV</a></div>';
    require ROOT_PATH . '/includes/header.php';

    /* employee picker + form */
    echo '<div class="row g-3 mb-3"><div class="col-lg-12">';
    echo '<div class="card svc-card"><div class="card-header d-flex flex-wrap align-items-center justify-content-between gap-2">'
       . '<span class="card-title"><i class="bi ' . $cfg['icon'] . ' me-1 text-primary"></i>Record ' . e($cfg['singular']) . '</span>'
       . '<form method="get" class="d-flex align-items-center gap-2 flex-grow-1 flex-lg-grow-0" style="min-width:min(420px,100%)">'
       . '<select name="employee_id" id="svcEmployee" class="form-select form-select-sm" data-placeholder="Search employee by name, CNIC or code…"></select>'
       . '<button class="btn btn-sm btn-primary"><i class="bi bi-arrow-right"></i></button></form></div>';

    if ($emp) {
        $reason = null;
        [$ok, $why] = empty($cfg['skip_transact_guard']) ? employee_can_transact($emp) : [true, ''];
        if (!$ok) $reason = $why;
        elseif (!empty($cfg['guard'])) $reason = ($cfg['guard'])($emp);
        echo '<div class="card-body">';
        echo '<div class="svc-emp d-flex flex-wrap align-items-center gap-3 mb-4">'
           . '<img src="' . photo_url($emp['photo'], (int)$emp['id']) . '" class="rounded-circle" width="56" height="56" style="object-fit:cover" alt="">'
           . '<div><div class="fw-bold fs-6"><a href="' . url('employees/view.php?id=' . $emp['id']) . '" class="text-reset">' . e($emp['name']) . '</a> ' . status_badge($emp['status']) . '</div>'
           . '<div class="small text-muted">' . e($emp['employee_code']) . ' · ' . e($emp['designation']) . ' (' . e($emp['pay_scale'] ?: '-') . ') · ' . e($emp['department']) . ' · Joined ' . fmt_date($emp['joining_date']) . '</div></div>'
           . '<div class="ms-auto small text-muted">Basic salary: <strong>' . fmt_money($emp['basic_salary']) . '</strong>' . ($emp['confirmation_date'] ? ' · Confirmed ' . fmt_date($emp['confirmation_date']) : ' · <span class="text-warning">Unconfirmed</span>') . '</div></div>';
        if ($reason) {
            echo '<div class="alert alert-warning mb-0"><i class="bi bi-lock me-1"></i>' . e($reason) . '</div>';
        } else {
            echo '<form method="post" enctype="multipart/form-data" novalidate class="svc-form">' . csrf_field()
               . '<input type="hidden" name="employee_id" value="' . (int)$emp['id'] . '"><div class="row g-3">';
            ($cfg['form'])($emp);
            echo '<div class="col-12"><div class="form-section-title">Supporting Document <span class="text-muted fw-normal text-lowercase">(optional)</span></div></div>'
               . '<div class="col-md-4"><label class="form-label">Document Title</label><input type="text" name="document_title" class="form-control" maxlength="150" placeholder="e.g. Office order"></div>'
               . '<div class="col-md-5"><label class="form-label">File</label><input type="file" name="document" class="form-control" accept=".pdf,.jpg,.jpeg,.png,.webp,.doc,.docx"><div class="form-text">PDF / image / Word, max ' . MAX_UPLOAD_MB . ' MB</div></div>';
            service_notify_section($emp, $cfg);
            echo '<div class="col-12 d-flex gap-2 mt-2"><button class="btn btn-primary" type="submit"><i class="bi bi-check2 me-1"></i>Save ' . e($cfg['singular']) . '</button>'
               . '<a href="' . url('employees/view.php?id=' . $emp['id']) . '" class="btn btn-light">Cancel</a></div>';
            echo '</div></form>';
        }
        echo '</div>';
    } else {
        echo '<div class="card-body text-muted"><i class="bi bi-info-circle me-1"></i>Select an employee above to record a new ' . strtolower(e($cfg['singular'])) . '. Existing records are listed below.</div>';
    }
    echo '</div></div></div>';

    /* list */
    service_render_list($cfg);

    $GLOBALS['extra_js'] = ($GLOBALS['extra_js'] ?? '') . service_page_js($emp);
    $extra_js = $GLOBALS['extra_js'];
    require ROOT_PATH . '/includes/footer.php';
}

function svc_old(string $key, $default = ''): string
{
    return e($GLOBALS['svc_old'][$key] ?? $default);
}

function service_list_query(array $cfg): array
{
    [$scope, $params] = emp_scope('e');
    $where = [$scope];
    if (!empty($_GET['employee_id'])) { $where[] = 'e.id = ?'; $params[] = (int)$_GET['employee_id']; }
    if (!empty($_GET['q'])) { $where[] = '(e.name LIKE ? OR e.employee_code LIKE ? OR e.cnic LIKE ? OR r.ref_no LIKE ?)'; $l = '%' . $_GET['q'] . '%'; array_push($params, $l, $l, $l, $l); }
    $dateCol = $cfg['list']['date_col'] ?? 'r.created_at';
    if (!empty($_GET['from'])) { $where[] = "$dateCol >= ?"; $params[] = $_GET['from']; }
    if (!empty($_GET['to']))   { $where[] = "$dateCol <= ?"; $params[] = $_GET['to']; }
    if (empty($_GET['voided'])) $where[] = 'r.is_voided = 0';
    if (!empty($cfg['list']['extra'])) {
        [$frag, $extraParams] = ($cfg['list']['extra'])($_GET);
        if ($frag) { $where[] = $frag; $params = array_merge($params, $extraParams); }
    }
    $sql = str_replace('{WHERE}', implode(' AND ', $where), $cfg['list']['sql']);
    return [$sql, array_merge($cfg['list']['params'] ?? [], $params)];
}

function service_render_list(array $cfg): void
{
    [$sql, $params] = service_list_query($cfg);
    $rows = db_all($sql . ' LIMIT 500', $params);
    $cols = $cfg['list']['columns'];
    $g = $_GET;
    ?>
    <div class="card">
        <div class="card-header">
            <span class="card-title"><?= e($cfg['title']) ?> Register <span class="badge rounded-pill text-bg-light border"><?= count($rows) ?></span>
                <?php if (!empty($cfg['list']['summary'])) echo ($cfg['list']['summary'])($rows); ?></span>
            <div class="d-flex align-items-center gap-2">
                <?php $hasF = !empty($g['q']) || !empty($g['from']) || !empty($g['to']) || !empty($g['voided']); ?>
                <button class="btn btn-sm <?= $hasF ? 'btn-primary' : 'btn-light' ?>" type="button" data-bs-toggle="collapse" data-bs-target="#svcFilters"><i class="bi bi-sliders me-1"></i>Filters<?= $hasF ? ' · on' : '' ?></button>
                <?php if ($hasF || !empty($g['employee_id'])): ?><a href="<?= url('service/' . $cfg['key'] . '.php') ?>" class="btn btn-ghost btn-sm" title="Clear"><i class="bi bi-x-lg"></i></a><?php endif; ?>
            </div>
        </div>
        <div class="collapse <?= $hasF ? 'show' : '' ?>" id="svcFilters">
            <form method="get" class="toolbar-row border-bottom px-3 py-2">
                <?php if (!empty($g['employee_id'])): ?><input type="hidden" name="employee_id" value="<?= (int)$g['employee_id'] ?>"><?php endif; ?>
                <div class="search-field"><i class="bi bi-search"></i><input type="text" name="q" class="form-control form-control-sm" placeholder="Ref no. / employee / code / CNIC" value="<?= e($g['q'] ?? '') ?>"></div>
                <input type="date" name="from" class="form-control form-control-sm w-auto" value="<?= e($g['from'] ?? '') ?>" title="From date">
                <span class="text-muted small">to</span>
                <input type="date" name="to" class="form-control form-control-sm w-auto" value="<?= e($g['to'] ?? '') ?>" title="To date">
                <?php if (!empty($cfg['list']['filters'])) ($cfg['list']['filters'])($g); ?>
                <?php if (is_super_admin()): ?><label class="chip-check"><input type="checkbox" name="voided" value="1" <?= !empty($g['voided']) ? 'checked' : '' ?>><span>Show voided</span></label><?php endif; ?>
                <button class="btn btn-sm btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
            </form>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0 datatable" data-page-length="25">
                <thead><tr><th style="width:48px">#</th><th>Ref No.</th><th>Employee</th><?php foreach ($cols as $label => $fn): ?><th><?= e($label) ?></th><?php endforeach; ?><th>Recorded</th><th class="text-end no-sort">Actions</th></tr></thead>
                <tbody>
                <?php foreach ($rows as $i => $r): ?>
                    <tr class="<?= $r['is_voided'] ? 'table-secondary text-muted' : '' ?>">
                        <td class="text-muted"><?= $i + 1 ?></td>
                        <td><a href="<?= svc_record_url($cfg['key'], (int)$r['id'], (int)$r['employee_id']) ?>" class="ref-no" title="Open record"><?= e($r['ref_no'] ?: '—') ?></a></td>
                        <td><a href="<?= url('employees/view.php?id=' . $r['employee_id']) ?>" class="text-reset text-decoration-none"><span class="fw-semibold"><?= e($r['emp_name']) ?></span><br><span class="small text-muted"><?= e($r['employee_code']) ?> · <?= e($r['department']) ?></span></a></td>
                        <?php foreach ($cols as $fn): ?><td><?= $fn($r) ?></td><?php endforeach; ?>
                        <td class="small text-muted"><?= fmt_date($r['created_at']) ?><br><?= e($r['by_user'] ?? '') ?></td>
                        <td class="text-end table-actions">
                            <?php if ($r['is_voided']): ?><span class="badge text-bg-dark" title="<?= e($r['void_reason']) ?>">Voided</span>
                            <?php else: ?>
                                <?= order_button($cfg['key'], (int)$r['id']) ?>
                                <a href="<?= url('employees/view.php?id=' . $r['employee_id'] . '#tab-' . ($cfg['tab'] ?? 'service')) ?>" class="btn btn-ghost btn-sm" title="Open profile"><i class="bi bi-person"></i></a>
                                <?php if (is_super_admin()): ?><button class="btn btn-ghost btn-sm text-danger" data-void="<?= e($cfg['key']) ?>" data-id="<?= (int)$r['id'] ?>" title="Void record (correction)"><i class="bi bi-x-octagon"></i></button><?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <!-- void modal -->
    <div class="modal fade" id="voidModal" tabindex="-1"><div class="modal-dialog modal-dialog-centered"><form class="modal-content" id="voidForm">
        <div class="modal-header"><h5 class="modal-title">Void Record</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
        <div class="modal-body">
            <p class="small text-muted">Voiding keeps the record in history (marked voided), removes its timeline entry and re-derives the employee status from the remaining records. Use only for data-entry corrections.</p>
            <input type="hidden" name="table" id="voidTable"><input type="hidden" name="id" id="voidId">
            <label class="form-label">Reason <span class="req">*</span></label><textarea name="reason" class="form-control" rows="2" required maxlength="255"></textarea>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button class="btn btn-danger">Void Record</button></div>
    </form></div></div>
    <?php
}

function service_page_js(?array $emp): string
{
    $sel = $emp ? json_encode(['id' => (int)$emp['id'], 'text' => $emp['name'] . ' — ' . $emp['employee_code']]) : 'null';
    return <<<JS
<script>
(function(){
  const \$s = $('#svcEmployee');
  \$s.select2({ theme: 'bootstrap-5', width: '100%', placeholder: \$s.data('placeholder'), minimumInputLength: 2,
    ajax: { url: HR.url('employees/ajax/select.php'), dataType: 'json', delay: 250, data: p => ({ q: p.term }), processResults: d => d } });
  const sel = $sel;
  if (sel) { \$s.append(new Option(sel.text, sel.id, true, true)).trigger('change'); }
  \$s.on('select2:select', function(){ this.form.submit(); });
  const vm = new bootstrap.Modal('#voidModal');
  $(document).on('click', 'button[data-void]', function(){ $('#voidTable').val(this.dataset.void); $('#voidId').val(this.dataset.id); vm.show(); });
  $('#voidForm').on('submit', function(e){ e.preventDefault();
    HR.post('service/ajax/void.php', $(this).serialize()).done(r => { HR.toast(r.message, r.success ? 'success' : 'danger'); if (r.success) setTimeout(() => location.reload(), 600); }); });
})();
</script>
JS;
}

/**
 * "Notify employee" block rendered inside every service form:
 * channel toggles (Email / SMS / WhatsApp) with recipient preview and an
 * optional custom message (blank = template from System Settings).
 */
function service_notify_section(array $emp, array $cfg): void
{
    $defaults = array_filter(array_map('trim', explode(',', (string)setting('notify_default_channels', ''))));
    $old = $GLOBALS['svc_old']['notify'] ?? null;
    $selected = is_array($old) ? $old : $defaults;
    $recips = [
        'email'    => ['bi-envelope', trim((string)($emp['email'] ?? '')), 'email address'],
        'sms'      => ['bi-chat-dots', notify_phone($emp['contact'] ?? null), 'mobile number'],
        'whatsapp' => ['bi-whatsapp', notify_phone(($emp['whatsapp_no'] ?? null) ?: ($emp['contact'] ?? null)), 'WhatsApp number'],
    ];
    $preview = notify_default_message($emp, (preg_match('/^[aeiou]/i', $cfg['singular']) ? 'an ' : 'a ') . strtolower($cfg['singular']), '…');
    echo '<div class="col-12"><div class="form-section-title">Notify Employee <span class="text-muted fw-normal text-lowercase">(optional)</span></div></div>';
    echo '<div class="col-12"><div class="notify-channels">';
    foreach (NOTIFY_CHANNELS as $key => $label) {
        [$icon, $to, $what] = $recips[$key];
        $ready = notify_channel_ready($key);
        $dis = !$to ? ' disabled' : '';
        $hint = !$to ? 'No ' . $what . ' on profile' : ($ready ? e($to) : e($to) . ' · gateway not configured');
        echo '<label class="notify-chip' . (!$to ? ' unavailable' : '') . '"><input type="checkbox" name="notify[]" value="' . $key . '"' . (in_array($key, $selected, true) && $to ? ' checked' : '') . $dis . '>'
           . '<span class="nc-box"><i class="bi ' . $icon . '"></i><span><span class="nc-label">' . $label . '</span><span class="nc-hint">' . $hint . '</span></span></span></label>';
    }
    echo '</div></div>';
    echo '<div class="col-12"><label class="form-label">Message <span class="text-muted fw-normal">(blank = default template)</span></label>'
       . '<textarea name="notify_message" class="form-control" rows="2" maxlength="1000" placeholder="' . e($preview) . '">' . e((string)($GLOBALS['svc_old']['notify_message'] ?? '')) . '</textarea>'
       . '<div class="form-text">Sent after the record is saved. Delivery results are shown on the profile and logged under Administration → Notification Log.</div></div>';
}
