<?php
/** System settings (Super Admin): employee code pattern, rules, alert windows, organization. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_super_admin();

$groups = [
    'Organization' => [
        ['org_name', 'Organization Name', 'text', 'Shown in the header, reports and exports.'],
        ['org_address', 'Organization Address', 'text', 'Printed on reports.'],
    ],
    'Code & Reference Patterns' => [
        ['employee_code_pattern', 'Code Pattern', 'text', 'Tokens: {PREFIX} {DEPT} {YYYY} {YY} {MM} {SEQ:n}. Example: {PREFIX}-{DEPT}-{YYYY}-{SEQ:4} → DCO-GEN-2026-0001'],
        ['employee_code_prefix', 'Prefix ({PREFIX})', 'text', 'Short organization prefix.'],
        ['employee_code_seq_scope', 'Sequence Scope', 'select:global,department,year', 'global = one running number; department = restart per department; year = restart per year.'],
        ['service_ref_pattern', 'Service Record Reference Pattern', 'text', 'Tokens: {TYPE} (PST, PRM, INC, LVE, AWD, PUN, RIN, DEP, EXT, RJN) {PREFIX} {YYYY} {YY} {SEQ:n}. Sequence restarts per type per year. Example: {TYPE}-{YYYY}-{SEQ:4} → AWD-2026-0001'],
    ],
    'Service Rules (Punjab Govt.)' => [
        ['retirement_age', 'Retirement (Superannuation) Age', 'number', 'Retirement date = DOB + this many years (+ EOL breaks).'],
        ['probation_months', 'Default Probation (months)', 'number', 'Used to pre-fill probation end date.'],
        ['lpr_months', 'LPR Eligibility Window (months)', 'number', 'Employees are flagged LPR-eligible this many months before retirement.'],
        ['eol_break_months', 'EOL Break Threshold (months)', 'number', 'EOL without pay longer than this = break in service.'],
        ['allow_terminated_rejoin', 'Allow Terminated Employees to Rejoin (Super Admin override)', 'select:1,0', '1 = allowed with override, 0 = never.'],
    ],
    'Dashboard Alerts' => [
        ['contract_expiry_alert_days', 'Contract Expiry Alert (days)', 'number', ''],
        ['retirement_alert_months', 'Upcoming Retirement Alert (months)', 'number', ''],
        ['deputation_alert_days', 'Deputation Reversion Alert (days)', 'number', ''],
    ],
    'Official Orders — defaults (each officer keeps their own)' => [
        ['order_letterhead_1', 'Letterhead Line 1', 'text', 'Used only for orders with no officer to sign them (e.g. entered by the Super Admin for a tehsil with no officer account). Officers set their own under My Office & Signature.'],
        ['order_letterhead_2', 'Letterhead Line 2', 'text', 'The issuing office, e.g. ASSISTANT COMMISSIONER'],
        ['order_letterhead_3', 'Letterhead Line 3', 'text', 'District / station, e.g. DISTRICT NANKANA SAHIB'],
        ['order_show_emblem', 'Print Government Emblem', 'select:1,0', '1 = show the Government of the Punjab emblem above the letterhead.'],
        ['order_prefix', 'Order Heading', 'text', 'Centred heading above the body, e.g. ORDER or OFFICE ORDER.'],
        ['order_authority_name', 'Signing Authority — Name', 'text', 'Leave blank to print "Sd/-" instead of a name.'],
        ['order_signature_file', 'Signature Image', 'signature', 'Upload a scanned signature (PNG with a transparent background looks best). It is printed above the name on every order, including the Word download.'],
        ['order_signature_width', 'Signature Width (pixels)', 'number', 'Printed width — 120 to 200 suits most signatures.'],
        ['order_authority_designation', 'Signing Authority — Designation', 'text', 'e.g. Assistant Commissioner'],
        ['order_authority_office', 'Signing Authority — Office', 'text', 'e.g. District Nankana Sahib'],
        ['order_copy_to', 'Distribution List (copy to)', 'textarea', 'One entry per line. Printed as the numbered endorsement below the signature. Leave blank to omit the endorsement.'],
        ['order_footer_note', 'Closing Sentence', 'textarea', 'Printed as the last paragraph of every order.'],
    ],
    'Posting Map' => [
        ['map_default_lat', 'Default Centre — Latitude', 'text', 'Used only when no posting location has coordinates yet.'],
        ['map_default_lng', 'Default Centre — Longitude', 'text', ''],
        ['map_default_zoom', 'Initial Zoom', 'number', '1 (world) to 18 (street). 10–12 suits a district.'],
    ],
    'Automation' => [
        ['cron_key', 'Cron Secret Key', 'text', 'Required to call cron/daily.php?key=… from a URL. Leave blank to allow CLI only (login-time fallback always works).'],
    ],
    'Notifications — Messaging to Employees' => [
        ['notify_default_channels', 'Pre-selected Channels', 'text', 'Comma list pre-ticked on every service form, e.g. sms,whatsapp. The officer can change per record.'],
        ['notify_template', 'Message Template', 'textarea', 'Tokens: {name} {code} {record} {summary} {date} {org} {designation} {department}'],
    ],
    'Email (SMTP)' => [
        ['mail_from_address', 'From Address', 'text', 'Required to enable Email. With no SMTP host, PHP mail() is used (works on most cPanel hosts).'],
        ['mail_from_name', 'From Name', 'text', ''],
        ['smtp_host', 'SMTP Host', 'text', 'e.g. mail.yourdomain.com or smtp.gmail.com (blank = PHP mail)'],
        ['smtp_port', 'SMTP Port', 'number', '587 (TLS) · 465 (SSL) · 25 (none)'],
        ['smtp_encryption', 'Encryption', 'select:tls,ssl,none', ''],
        ['smtp_username', 'SMTP Username', 'text', ''],
        ['smtp_password', 'SMTP Password', 'password', ''],
    ],
    'SMS Gateway (any HTTP API)' => [
        ['sms_country_code', 'Country Code', 'text', 'Local numbers 03xx… are converted to 923xx…'],
        ['sms_sender_id', 'Sender ID / Mask', 'text', 'Available as {sender}'],
        ['sms_api_url', 'API URL', 'text', 'Placeholders: {to} {message} {message_url} {sender} — e.g. https://api.example.com/send?key=KEY&to={to}&msg={message_url}'],
        ['sms_api_method', 'HTTP Method', 'select:GET,POST', ''],
        ['sms_api_headers', 'HTTP Headers', 'textarea', 'One per line, e.g. Authorization: Bearer YOUR_KEY'],
        ['sms_api_body', 'POST Body', 'textarea', 'Form or JSON. Placeholders: {to} {message} {message_json} {sender} — e.g. {"to":"{to}","text":"{message_json}"}'],
    ],
    'WhatsApp' => [
        ['wa_provider', 'Provider', 'select:meta,generic', 'meta = WhatsApp Business Cloud API (Graph); generic = any HTTP gateway'],
        ['wa_phone_number_id', 'Meta: Phone Number ID', 'text', 'From Meta Business → WhatsApp → API setup'],
        ['wa_access_token', 'Meta: Access Token', 'password', 'Permanent system-user token'],
        ['wa_api_version', 'Meta: API Version', 'text', 'e.g. v20.0'],
        ['wa_template_name', 'Meta: Template Name', 'text', 'Approved template with one {{1}} body parameter (blank = plain text, only inside 24h window)'],
        ['wa_template_lang', 'Meta: Template Language', 'text', 'e.g. en or en_US'],
        ['wa_api_url', 'Generic: API URL', 'text', 'Placeholders: {to} {message} {message_url} {sender}'],
        ['wa_api_method', 'Generic: HTTP Method', 'select:GET,POST', ''],
        ['wa_api_headers', 'Generic: HTTP Headers', 'textarea', 'One per line'],
        ['wa_api_body', 'Generic: POST Body', 'textarea', 'Placeholders: {to} {message} {message_json} {sender}'],
        ['wa_sender', 'Generic: Sender', 'text', ''],
    ],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $errors = [];

    /* ---- signature image: upload or remove ---- */
    require_once ROOT_PATH . '/includes/order_ui.php';
    $sigDir = order_signature_dir();
    if (post('remove_signature')) {
        $cur = trim((string)setting('order_signature_file', ''));
        if ($cur !== '' && is_file($sigDir . '/' . basename($cur))) @unlink($sigDir . '/' . basename($cur));
        setting_set('order_signature_file', '', user_id());
        log_activity('UPDATE', 'settings', null, ['order_signature_file' => $cur], ['order_signature_file' => ''], 'Order signature removed');
    } elseif (!empty($_FILES['signature_file']['name']) && is_uploaded_file($_FILES['signature_file']['tmp_name'])) {
        $f    = $_FILES['signature_file'];
        $ext  = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        $info = @getimagesize($f['tmp_name']);
        if ($f['error'] !== UPLOAD_ERR_OK) {
            $errors[] = 'Signature upload failed (error ' . (int)$f['error'] . ').';
        } elseif (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) || !$info) {
            $errors[] = 'The signature must be a PNG, JPG, GIF or WEBP image.';
        } elseif ($f['size'] > 2 * 1024 * 1024) {
            $errors[] = 'The signature image must be 2 MB or smaller.';
        } else {
            if (!is_dir($sigDir)) @mkdir($sigDir, 0775, true);
            $name = 'signature_' . date('YmdHis') . '.' . $ext;
            if (!@move_uploaded_file($f['tmp_name'], $sigDir . '/' . $name)) {
                $errors[] = 'Could not save the signature — check that uploads/signatures is writable.';
            } else {
                $prev = trim((string)setting('order_signature_file', ''));
                if ($prev !== '' && $prev !== $name && is_file($sigDir . '/' . basename($prev))) @unlink($sigDir . '/' . basename($prev));
                setting_set('order_signature_file', $name, user_id());
                log_activity('UPDATE', 'settings', null, ['order_signature_file' => $prev], ['order_signature_file' => $name], 'Order signature uploaded');
            }
        }
    }
    $pattern = trim((string)post('employee_code_pattern', ''));
    if (!str_contains($pattern, '{SEQ')) $errors[] = 'Code pattern must contain a {SEQ:n} token.';
    if (preg_match('/\{(?!PREFIX|DEPT|YYYY|YY|MM|SEQ)[A-Z]+/', $pattern)) $errors[] = 'Code pattern contains an unknown token.';
    foreach (['retirement_age' => [50, 70], 'probation_months' => [0, 60], 'lpr_months' => [0, 24], 'eol_break_months' => [1, 36], 'contract_expiry_alert_days' => [1, 365], 'retirement_alert_months' => [1, 60], 'deputation_alert_days' => [1, 365], 'map_default_zoom' => [1, 18]] as $k => [$min, $max]) {
        $v = (int)post($k);
        if ($v < $min || $v > $max) $errors[] = "$k must be between $min and $max.";
    }
    if ($errors) {
        flash('danger', implode(' ', $errors));
    } else {
        $old = []; $new = [];
        foreach ($groups as $fields) foreach ($fields as [$key, , $fType]) {
            if ($fType === 'signature') continue;      // handled by the upload block above
            $val = trim((string)post($key, ''));
            $old[$key] = setting($key, '');
            if ((string)$old[$key] !== $val) { setting_set($key, $val, user_id()); $new[$key] = $val; }
        }
        if ($new) log_activity('UPDATE', 'settings', null, array_intersect_key($old, $new), $new, 'System settings updated');
        flash('success', $new ? 'Settings saved.' : 'No changes.');
    }
    redirect('admin/settings.php');
}

$page_title  = 'System Settings';
$active_menu = 'settings';
$breadcrumbs = ['Administration' => null, 'System Settings' => null];
require ROOT_PATH . '/includes/header.php';
require_once ROOT_PATH . '/includes/order_ui.php';
$deptCode = db_value('SELECT code FROM departments WHERE is_deleted = 0 ORDER BY sort_order LIMIT 1', [], 'GEN');
?>
<form method="post" enctype="multipart/form-data" novalidate>
    <?= csrf_field() ?>
    <div class="row g-3">
        <?php foreach ($groups as $title => $fields): ?>
        <div class="col-lg-6">
            <div class="card h-100"><div class="card-header"><span class="card-title"><?= e($title) ?></span></div>
            <div class="card-body">
                <?php foreach ($fields as [$key, $label, $type, $help]): $val = setting($key, ''); ?>
                <div class="mb-3">
                    <label class="form-label"><?= e($label) ?></label>
                    <?php if ($type === 'textarea'): ?>
                        <textarea name="<?= $key ?>" class="form-control font-monospace" rows="2" id="s_<?= $key ?>"><?= e($val) ?></textarea>
                    <?php elseif ($type === 'signature'): ?>
                        <?php $sigSrc = order_signature_src(); ?>
                        <?php if ($sigSrc): ?>
                            <div class="d-flex align-items-center gap-3 mb-2 p-2 border rounded" style="background:var(--hr-surface-2)">
                                <img src="<?= e($sigSrc) ?>" alt="Current signature" style="max-height:60px;max-width:200px;object-fit:contain">
                                <div class="small">
                                    <div class="fw-semibold">Current signature</div>
                                    <div class="text-muted"><?= e($val) ?></div>
                                </div>
                                <div class="form-check ms-auto">
                                    <input class="form-check-input" type="checkbox" name="remove_signature" value="1" id="rmSig">
                                    <label class="form-check-label small" for="rmSig">Remove</label>
                                </div>
                            </div>
                        <?php endif; ?>
                        <input type="file" name="signature_file" class="form-control" accept=".png,.jpg,.jpeg,.gif,.webp">
                    <?php elseif (str_starts_with($type, 'select:')): ?>
                        <select name="<?= $key ?>" class="form-select"><?= options_html(explode(',', substr($type, 7)), $val) ?></select>
                    <?php else: ?>
                        <input type="<?= $type ?>" name="<?= $key ?>" class="form-control <?= $key === 'employee_code_pattern' ? 'font-monospace' : '' ?>" value="<?= e($val) ?>" <?= $type === 'number' ? 'min="0"' : '' ?> id="s_<?= $key ?>">
                    <?php endif; ?>
                    <?php if ($help): ?><div class="form-text"><?= e($help) ?></div><?php endif; ?>
                    <?php if ($key === 'employee_code_pattern'): ?><div class="form-text">Preview: <strong class="font-monospace" id="codePreview"><?= e(employee_code_preview((string)$deptCode)) ?></strong></div><?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div></div>
        </div>
        <?php endforeach; ?>
    </div>
    <div class="mt-3 d-flex gap-2"><button class="btn btn-primary"><i class="bi bi-check2 me-1"></i>Save Settings</button><a href="<?= url('dashboard/index.php') ?>" class="btn btn-light">Cancel</a></div>
</form>
<div class="card mt-4"><div class="card-header"><span class="card-title"><i class="bi bi-send me-1 text-primary"></i>Test Messaging</span><span class="small text-muted">Save settings first, then send a test</span></div>
<div class="card-body"><div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">Channel</label><select id="testChannel" class="form-select"><option value="email">Email</option><option value="sms">SMS</option><option value="whatsapp">WhatsApp</option></select></div>
    <div class="col-md-4"><label class="form-label">Recipient (email or mobile)</label><input type="text" id="testTo" class="form-control" placeholder="name@example.com or 0300-1234567"></div>
    <div class="col-md-3"><button type="button" class="btn btn-outline-primary w-100" id="btnTest"><i class="bi bi-send me-1"></i>Send Test</button></div><div class="col-md-2"><button type="button" class="btn btn-outline-secondary w-100" id="btnTwilio" title="Fill the SMS gateway fields for Twilio"><i class="bi bi-magic me-1"></i>Twilio preset</button></div>
    <div class="col-12"><pre id="testOut" class="small bg-light p-2 rounded mb-0" hidden></pre></div>
</div></div></div>
<?php
$extra_js = <<<JS
<script>
$('#btnTwilio').on('click', function(){
  const sid = prompt('Twilio Account SID (AC…):'); if (!sid) return;
  const tok = prompt('Twilio Auth Token:'); if (!tok) return;
  const from = prompt('Twilio phone number in E.164 (e.g. +15706308095) — or Messaging Service SID (MG…):'); if (!from) return;
  $('#s_sms_api_url').val('https://api.twilio.com/2010-04-01/Accounts/' + sid.trim() + '/Messages.json');
  $('select[name=sms_api_method]').val('POST');
  $('#s_sms_api_headers').val('Authorization: Basic ' + btoa(sid.trim() + ':' + tok.trim()) + '\nContent-Type: application/x-www-form-urlencoded');
  $('#s_sms_api_body').val('To=%2B{to}&' + (from.trim().startsWith('MG') ? 'MessagingServiceSid=' + from.trim() : 'From=' + encodeURIComponent(from.trim())) + '&Body={message_url}');
  $('#s_sms_sender_id').val(from.trim());
  HR.toast('Twilio fields filled — click Save Settings, then Send Test.', 'info');
  $('#s_sms_api_url')[0].scrollIntoView({ behavior: 'smooth', block: 'center' });
});
$('#btnTest').on('click', function(){ const b = $(this).prop('disabled', true); $('#testOut').prop('hidden', false).text('Sending…');
  HR.post('admin/ajax/notify_test.php', { channel: $('#testChannel').val(), to: $('#testTo').val() }).done(r => $('#testOut').text(r.message)).fail(x => $('#testOut').text((x.responseJSON && x.responseJSON.message) || 'Failed')).always(() => b.prop('disabled', false)); });
$('#s_employee_code_pattern, #s_employee_code_prefix').on('input', function(){
  const p = $('#s_employee_code_pattern').val(), pre = $('#s_employee_code_prefix').val() || 'EMP', d = new Date();
  let seq = 4; const m = p.match(/\\{SEQ:(\\d+)\\}/); if (m) seq = parseInt(m[1]);
  $('#codePreview').text(p.replace('{PREFIX}', pre).replace('{DEPT}', '$deptCode').replace('{YYYY}', d.getFullYear()).replace('{YY}', String(d.getFullYear()).slice(2)).replace('{MM}', String(d.getMonth()+1).padStart(2,'0')).replace(/\\{SEQ(:\\d+)?\\}/, '1'.padStart(seq, '0')).toUpperCase());
});
</script>
JS;
require ROOT_PATH . '/includes/footer.php';
