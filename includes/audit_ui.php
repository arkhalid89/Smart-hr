<?php
/**
 * Human-readable presentation of audit log entries.
 * Turns the stored old_values / new_values JSON into labelled fields with
 * resolved master data, formatted dates and Yes/No flags.
 */
declare(strict_types=1);

/** Column -> master table, so 7 becomes "Revenue Department". */
const AUDIT_FK_MAP = [
    'department_id'           => 'departments',
    'designation_id'          => 'designations',
    'new_designation_id'      => 'designations',
    'previous_designation_id' => 'designations',
    'pay_scale_id'            => 'pay_scales',
    'new_scale_id'            => 'pay_scales',
    'previous_scale_id'       => 'pay_scales',
    'leave_type_id'           => 'leave_types',
    'award_type_id'           => 'award_types',
    'punishment_type_id'      => 'punishment_types',
    'reinstatement_type_id'   => 'reinstatement_types',
    'location_id'             => 'posting_locations',
    'employment_type_id'      => 'employment_types',
    'blood_group_id'          => 'blood_groups',
    'marital_status_id'       => 'marital_statuses',
    'deputation_org_id'       => 'deputation_orgs',
    'role_id'                 => 'roles',
];

/** Columns whose wording the generic humaniser would get wrong. */
const AUDIT_LABELS = [
    'cnic'                  => 'CNIC',
    'dob'                   => 'Date of Birth',
    'father_husband_name'   => 'Father / Husband Name',
    'employee_code'         => 'Employee Code',
    'gp_fund_no'            => 'GP Fund No.',
    'pension_no'            => 'Pension No.',
    'seniority_no'          => 'Seniority No.',
    'service_book_no'       => 'Service Book No.',
    'order_no'              => 'Order No.',
    'ref_no'                => 'Reference No.',
    'notification_no'       => 'Notification No.',
    'whatsapp_no'           => 'WhatsApp No.',
    'contact'               => 'Mobile No.',
    'eol_break_days'        => 'EOL Break (days)',
    'is_break_in_service'   => 'Break in Service',
    'duration_days'         => 'Duration (days)',
    'letter_no'             => 'Letter No.',
    'is_lpr_eligible'       => 'LPR Eligible',
    'has_break_in_service'  => 'Break in Service',
    'basic_salary'          => 'Basic Salary',
    'new_basic'             => 'New Basic Pay',
    'previous_basic'        => 'Previous Basic Pay',
    'ip_address'            => 'IP Address',
    'is_current'            => 'Current Posting',
    'is_active'             => 'Active',
    'is_deleted'            => 'Deleted',
    'is_voided'             => 'Voided',
    'void_reason'           => 'Reason for Voiding',
    'voided_by'             => 'Voided By',
    'voided_at'             => 'Voided On',
    'from_date'             => 'From',
    'to_date'               => 'To',
    'no_of_days'            => 'Number of Days',
    'reporting_officer'     => 'Reporting Officer',
];

/** Record type wording for the header line. */
const AUDIT_TABLE_LABELS = [
    'employees'          => 'Employee',
    'employee_family'    => 'Family Member',
    'employee_documents' => 'Document',
    'postings'           => 'Posting',
    'promotions'         => 'Promotion',
    'increments'         => 'Increment',
    'leaves'             => 'Leave',
    'awards'             => 'Award',
    'punishments'        => 'Punishment',
    'reinstatements'     => 'Reinstatement',
    'deputations'        => 'Deputation',
    'exits'              => 'Exit',
    'rejoinings'         => 'Rejoining',
    'users'              => 'User Account',
    'sanctioned_posts'   => 'Sanctioned Post',
    'settings'           => 'System Setting',
    'departments'        => 'Department',
    'designations'       => 'Designation',
    'pay_scales'         => 'Pay Scale',
    'leave_types'        => 'Leave Type',
    'award_types'        => 'Award Type',
    'punishment_types'   => 'Punishment Type',
    'posting_locations'  => 'Posting Location',
    'employment_types'   => 'Employment Type',
    'blood_groups'       => 'Blood Group',
    'marital_statuses'   => 'Marital Status',
    'deputation_orgs'    => 'Deputation Organisation',
    'reinstatement_types' => 'Reinstatement Type',
];

/** Action -> [badge colour, plain-English verb]. */
const AUDIT_ACTIONS = [
    'CREATE'      => ['success',      'Record created'],
    'UPDATE'      => ['primary',      'Record updated'],
    'DELETE'      => ['danger',       'Moved to trash'],
    'HARD_DELETE' => ['danger',       'Permanently deleted'],
    'RESTORE'     => ['info',         'Restored from trash'],
    'VOID'        => ['dark',         'Record voided'],
    'STATUS'      => ['warning',      'Status changed'],
    'LOGIN'       => ['light border', 'Signed in'],
    'LOGOUT'      => ['light border', 'Signed out'],
    'EXPORT'      => ['secondary',    'Data exported'],
    'BACKUP'      => ['secondary',    'Database backup'],
    'RESET'       => ['danger',       'Data reset'],
    'NOTIFY'      => ['info',         'Message sent'],
    'NOTIFY_TEST' => ['info',         'Test message sent'],
];

/** Pseudo tables: record_id there is not a row of that table, so no "#id" is shown. */
const AUDIT_PSEUDO_TABLES = ['reports' => 'Report', 'system' => 'System', 'settings' => 'System Settings', 'backup' => 'Database Backup'];

/** Columns stored as 0/1 or true/false that read better as Yes / No. */
const AUDIT_BOOL_FIELDS = ['service_closed', 'void', 'active', 'override', 'sa_override', 'paid', 'approved', 'withheld', 'notified'];

/** Preferred reading order — identifying details first, narrative last. */
const AUDIT_FIELD_ORDER = [
    'ref_no', 'employee_id', 'employee_code', 'name', 'order_no', 'notification_no',
    'order_date', 'effective_date', 'from_date', 'to_date', 'no_of_days',
    'rejoining_date', 'reinstatement_date', 'exit_date', 'start_date', 'end_date',
    'joining_date', 'promotion_date', 'increment_date',
    'department_id', 'designation_id', 'new_designation_id', 'previous_designation_id',
    'pay_scale_id', 'new_scale_id', 'previous_scale_id', 'location_id',
    'leave_type_id', 'award_type_id', 'punishment_type_id', 'reinstatement_type_id',
    'deputation_org_id', 'reporting_officer', 'status', 'is_current',
];

/** Fields that are never worth showing in a detail card. */
const AUDIT_HIDDEN_FIELDS = ['id', 'created_at', 'updated_at', 'created_by', 'updated_by', 'deleted_at', 'deleted_by'];

function audit_yes_no(bool $yes): string
{
    return $yes
        ? '<span class="badge bg-success-subtle text-success-emphasis border border-success-subtle">Yes</span>'
        : '<span class="badge bg-secondary-subtle text-body-secondary border">No</span>';
}

function audit_action_meta(?string $action): array
{
    return AUDIT_ACTIONS[$action ?? ''] ?? ['secondary', ucfirst(strtolower(str_replace('_', ' ', (string)$action)))];
}

function audit_table_label(?string $table): string
{
    if (!$table) return '';
    return AUDIT_PSEUDO_TABLES[$table] ?? AUDIT_TABLE_LABELS[$table] ?? ucwords(str_replace('_', ' ', $table));
}

/** "probation_end" -> "Probation End"; "department_id" -> "Department". */
function audit_field_label(string $key): string
{
    if (isset(AUDIT_LABELS[$key])) return AUDIT_LABELS[$key];
    $k = preg_replace('/^(is|has)_/', '', $key);     // "is_current" reads as "Current"
    $k = preg_replace('/_id$/', '', (string)$k);
    $k = str_replace('_', ' ', (string)$k);
    $k = ucwords($k);
    return str_replace([' No', 'Cnic', 'Eol', 'Lpr', 'Gp '], [' No.', 'CNIC', 'EOL', 'LPR', 'GP '], $k);
}

/** Resolve one stored value into display HTML. */
function audit_field_value(string $key, mixed $value): string
{
    if (is_array($value)) {
        if (!$value) return '<span class="audit-empty">empty</span>';
        $parts = [];
        foreach ($value as $k => $v) {
            $parts[] = (is_int($k) ? '' : '<span class="text-muted">' . e(audit_field_label((string)$k)) . ':</span> ')
                . audit_field_value(is_int($k) ? $key : (string)$k, $v);
        }
        return '<div class="audit-nested">' . implode('<br>', $parts) . '</div>';
    }
    if ($value === null || $value === '') return '<span class="audit-empty">Not set</span>';
    if (is_bool($value)) return audit_yes_no($value);

    $s = (string)$value;
    if ($s === '') return '<span class="audit-empty">Not set</span>';

    if ($key === 'password') return '<span class="audit-empty">••••••••</span>';

    // foreign keys -> master data names
    if (isset(AUDIT_FK_MAP[$key]) && ctype_digit($s)) {
        $name = master_name(AUDIT_FK_MAP[$key], (int)$s);
        return $name !== '-' ? e($name) : '<span class="audit-empty">Not set</span>';
    }
    if (in_array($key, ['user_id', 'created_by', 'updated_by', 'deleted_by', 'voided_by', 'approved_by', 'recommended_by'], true) && ctype_digit($s)) {
        $n = db_value('SELECT full_name FROM users WHERE id = ?', [(int)$s], '');
        return $n ? e($n) : '#' . e($s);
    }
    if ($key === 'employee_id' && ctype_digit($s)) {
        $n = db_value('SELECT CONCAT(name, " (", employee_code, ")") FROM employees WHERE id = ?', [(int)$s], '');
        return $n ? e($n) : '#' . e($s);
    }

    // yes / no flags
    if (preg_match('/^(is_|has_)/', $key) || in_array($key, AUDIT_BOOL_FIELDS, true)) {
        return audit_yes_no((int)$s === 1);
    }

    // dates / datetimes
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}/', $s)) return e(fmt_datetime($s));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return e(fmt_date($s));

    // money
    if (in_array($key, ['basic_salary', 'new_basic', 'previous_basic', 'amount'], true) && is_numeric($s)) {
        return 'Rs. ' . e(number_format((float)$s, 0));
    }

    if (mb_strlen($s) > 160) return '<span class="audit-long">' . e($s) . '</span>';
    return nl2br(e($s));
}

/** The record this entry points at, as a human reference (PST-2026-0004 when available). */
function audit_record_ref(?string $table, ?int $recordId): string
{
    if (!$table) return '';
    if (isset(AUDIT_PSEUDO_TABLES[$table])) return AUDIT_PSEUDO_TABLES[$table];
    if (!$recordId) return '';
    $label = audit_table_label($table);
    if (defined('SVC_TYPES') && isset(SVC_TYPES[$table])) {
        $ref = db_value('SELECT ref_no FROM `' . db_ident($table) . '` WHERE id = ?', [$recordId], '');
        if ($ref) return $label . ' ' . $ref;
    }
    return $label . ' #' . $recordId;
}

/** Identifying fields first, free-text remarks last; original order otherwise. */
function audit_sort_fields(array $values): array
{
    $keys = array_keys($values);
    $rank = [];
    foreach ($keys as $i => $k) {
        $p = array_search($k, AUDIT_FIELD_ORDER, true);
        if ($p !== false)                    $rank[$k] = [0, $p, $i];
        elseif (in_array($k, ['remarks', 'description', 'reason', 'notes'], true)) $rank[$k] = [2, 0, $i];
        else                                 $rank[$k] = [1, 0, $i];
    }
    uksort($values, fn($a, $b) => $rank[$a] <=> $rank[$b]);
    return $values;
}

/** One "Field / Before / After" row set, or a plain value list. */
function audit_changes_html(?array $old, ?array $new, string $action): string
{
    $isUpdate = $action === 'UPDATE' && $old !== null && $new !== null;
    $source   = $isUpdate ? $new : ($new ?: $old ?: []);
    $source   = array_diff_key($source, array_flip(AUDIT_HIDDEN_FIELDS));
    $source   = audit_sort_fields($source);

    if (!$source) {
        return '<div class="audit-none"><i class="bi bi-info-circle me-1"></i>This entry records an action only — no field values were stored.</div>';
    }

    if ($isUpdate) {
        $h = '<table class="table audit-table mb-0"><thead><tr><th style="width:30%">Field</th><th style="width:35%">Before</th><th style="width:35%">After</th></tr></thead><tbody>';
        foreach ($source as $k => $v) {
            $h .= '<tr><th scope="row">' . e(audit_field_label((string)$k)) . '</th>'
                . '<td class="audit-before">' . audit_field_value((string)$k, $old[$k] ?? null) . '</td>'
                . '<td class="audit-after">' . audit_field_value((string)$k, $v) . '</td></tr>';
        }
        return $h . '</tbody></table>';
    }

    $caption = $new ? 'Values recorded' : 'Values at the time of this action';
    $h = '<div class="audit-caption">' . e($caption) . '</div><table class="table audit-table audit-table-flat mb-0"><tbody>';
    foreach ($source as $k => $v) {
        $h .= '<tr><th scope="row" style="width:38%">' . e(audit_field_label((string)$k)) . '</th>'
            . '<td>' . audit_field_value((string)$k, $v) . '</td></tr>';
    }
    return $h . '</tbody></table>';
}

/** Same information as the detail card, flattened to one line for Excel / CSV export. */
function audit_changes_text(?array $old, ?array $new, string $action): string
{
    $isUpdate = $action === 'UPDATE' && $old !== null && $new !== null;
    $source   = $isUpdate ? $new : ($new ?: $old ?: []);
    $source   = audit_sort_fields(array_diff_key($source, array_flip(AUDIT_HIDDEN_FIELDS)));
    if (!$source) return '';

    $plain = fn(string $k, mixed $v): string => trim(html_entity_decode(strip_tags(audit_field_value($k, $v)), ENT_QUOTES, 'UTF-8'));
    $parts = [];
    foreach ($source as $k => $v) {
        $label = audit_field_label((string)$k);
        $parts[] = $isUpdate
            ? $label . ': ' . $plain((string)$k, $old[$k] ?? null) . ' -> ' . $plain((string)$k, $v)
            : $label . ': ' . $plain((string)$k, $v);
    }
    return implode('; ', $parts);
}

/** Full detail card for one audit row (as fetched with the users/employees join). */
function audit_detail_html(array $a): string
{
    $old = !empty($a['old_values']) ? json_decode((string)$a['old_values'], true) : null;
    $new = !empty($a['new_values']) ? json_decode((string)$a['new_values'], true) : null;
    if (!is_array($old)) $old = null;
    if (!is_array($new)) $new = null;

    [$colour, $verb] = audit_action_meta($a['action'] ?? null);
    $recordRef = audit_record_ref($a['table_name'] ?? null, isset($a['record_id']) ? (int)$a['record_id'] : null);
    $who       = $a['full_name'] ?: ($a['username'] ?: 'System');

    $meta = [
        'Date &amp; time' => e(fmt_datetime($a['created_at'] ?? null)),
        'Performed by'    => e($who) . ($a['username'] && $a['full_name'] ? ' <span class="text-muted">(' . e($a['username']) . ')</span>' : ''),
        'Record'          => $recordRef ? e($recordRef) : '<span class="audit-empty">Not linked</span>',
        'IP address'      => e($a['ip_address'] ?? '') ?: '<span class="audit-empty">Unknown</span>',
    ];
    if (!empty($a['employee_id'])) {
        $name = $a['emp_name'] ?? db_value('SELECT name FROM employees WHERE id = ?', [(int)$a['employee_id']], '');
        $meta['Employee'] = '<a href="' . e(url('employees/view.php?id=' . (int)$a['employee_id'])) . '">'
            . e($name ?: '#' . (int)$a['employee_id']) . '</a>';
    }

    $h  = '<div class="audit-card">';
    $h .= '<div class="audit-head">';
    $h .= '<span class="badge text-bg-' . $colour . ' audit-verb">' . e($a['action'] ?? '') . '</span>';
    $h .= '<div><div class="audit-title">' . e($verb)
        . ($a['table_name'] ? ' <span class="text-muted">· ' . e(audit_table_label($a['table_name'])) . '</span>' : '') . '</div>';
    if (!empty($a['description'])) $h .= '<div class="audit-desc">' . e($a['description']) . '</div>';
    $h .= '</div><span class="audit-id">Entry #' . (int)($a['id'] ?? 0) . '</span></div>';

    $h .= '<dl class="audit-meta">';
    foreach ($meta as $k => $v) $h .= '<div><dt>' . $k . '</dt><dd>' . $v . '</dd></div>';
    $h .= '</dl>';

    $h .= '<div class="audit-changes">' . audit_changes_html($old, $new, (string)($a['action'] ?? '')) . '</div>';

    if (!empty($a['user_agent'])) {
        $h .= '<div class="audit-agent"><i class="bi bi-display me-1"></i>' . e($a['user_agent']) . '</div>';
    }
    return $h . '</div>';
}

/** The "View" trigger used in listings. */
function audit_view_button(int $id, string $class = 'btn btn-sm btn-outline-secondary'): string
{
    return '<button type="button" class="' . e($class) . ' audit-view" data-audit-id="' . $id . '">'
        . '<i class="bi bi-eye me-1"></i>View</button>';
}

/** Modal shell + loader. Include once per page that renders audit_view_button(). */
function audit_modal_html(): string
{
    $endpoint = url('admin/ajax/audit_detail.php');
    return <<<HTML
<div class="modal fade" id="auditDetailModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title"><i class="bi bi-clock-history me-2"></i>Audit Entry</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body" id="auditDetailBody">
        <div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var el = document.getElementById('auditDetailModal');
    if (!el) return;
    var modal = new bootstrap.Modal(el);
    var body  = document.getElementById('auditDetailBody');
    document.addEventListener('click', function (ev) {
        var btn = ev.target.closest('.audit-view');
        if (!btn) return;
        ev.preventDefault();
        body.innerHTML = '<div class="text-center text-muted py-5"><div class="spinner-border spinner-border-sm me-2"></div>Loading…</div>';
        modal.show();
        fetch('{$endpoint}?id=' + encodeURIComponent(btn.dataset.auditId), { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) throw new Error('HTTP ' + r.status); return r.text(); })
            .then(function (html) { body.innerHTML = html; })
            .catch(function () {
                body.innerHTML = '<div class="alert alert-danger mb-0">Could not load this audit entry. Please refresh the page and try again.</div>';
            });
    });
});
</script>
HTML;
}
