<?php
/**
 * Service engine - every lifecycle transaction goes through here so that
 * business rules, status automation, posting history, timeline entries and
 * audit logs are applied consistently.
 *
 * All functions throw RuntimeException with a user-readable message when a
 * rule is violated. Callers wrap them in a DB transaction.
 */
declare(strict_types=1);

/* =====================================================================
 * STATUS
 * ===================================================================*/
function svc_set_status(int $empId, string $status, string $date, string $reason, ?string $refTable = null, ?int $refId = null): void
{
    $emp = db_row('SELECT status, name FROM employees WHERE id = ?', [$empId]);
    if (!$emp || $emp['status'] === $status) return;
    db_update('employees', ['status' => $status, 'updated_by' => user_id()], 'id = ?', [$empId]);
    add_timeline_event($empId, 'Status', $date, 'Status changed: ' . $emp['status'] . ' → ' . $status, $reason, $refTable, $refId);
    log_activity('STATUS', 'employees', $empId, ['status' => $emp['status']], ['status' => $status], $reason, $empId);
}

/** Status an employee should have when nothing exceptional is open. */
function svc_base_status(array $emp): string
{
    return empty($emp['confirmation_date']) && !empty($emp['probation_start']) ? 'Probation' : 'Active';
}

/**
 * Derive the correct status purely from non-voided records (used after a
 * Super Admin voids a record). Deterministic, so history stays consistent.
 */
function svc_recompute_status(int $empId): string
{
    $emp = db_find('employees', $empId);
    if (!$emp) return '';
    $exit = db_row('SELECT * FROM exits WHERE employee_id = ? AND is_voided = 0 AND is_rejoined = 0 ORDER BY exit_date DESC, id DESC LIMIT 1', [$empId]);
    $map = ['Resignation' => 'Resigned', 'Normal Retirement' => 'Retired', 'Medical Invalidation' => 'Medical Retired', 'Termination' => 'Terminated',
            'Dismissal' => 'Dismissed', 'Death in Service' => 'Deceased (In Service)', 'Contract Expired' => 'Contract Expired'];
    if ($exit) {
        $status = $map[$exit['exit_type']] ?? 'Resigned';
        db_update('employees', ['status' => $status, 'service_closed' => $exit['exit_type'] === 'Death in Service' ? 1 : 0], 'id = ?', [$empId]);
        return $status;
    }
    $status = svc_base_status($emp);
    if (db_value('SELECT id FROM deputations WHERE employee_id = ? AND status = "Active" AND is_voided = 0', [$empId])) $status = 'On Deputation';
    elseif (db_value('SELECT p.id FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? AND t.is_suspension = 1 AND p.is_reinstated = 0 AND p.is_voided = 0', [$empId])) $status = 'Suspended';
    elseif (db_value('SELECT l.id FROM leaves l JOIN leave_types t ON t.id = l.leave_type_id WHERE l.employee_id = ? AND t.is_lpr = 1 AND l.status = "Approved" AND l.is_voided = 0 AND l.to_date >= CURDATE()', [$empId])) $status = 'On LPR';
    db_update('employees', ['status' => $status, 'service_closed' => 0], 'id = ?', [$empId]);
    return $status;
}

/* =====================================================================
 * GUARDS
 * ===================================================================*/
function svc_guard(array $emp, array $opts = []): void
{
    [$ok, $why] = employee_can_transact($emp);
    if (!$ok) throw new RuntimeException($why);
    if (!empty($opts['not_suspended']) && $emp['status'] === 'Suspended') {
        throw new RuntimeException('Employee is suspended. ' . ($opts['action'] ?? 'This action') . ' is blocked until reinstatement.');
    }
    if (!empty($opts['confirmed']) && empty($emp['confirmation_date'])) {
        throw new RuntimeException('Employee is not confirmed (on probation). ' . ($opts['action'] ?? 'This action') . ' is not allowed; only scale upgradation is permitted.');
    }
    if (!empty($opts['not_on_deputation']) && $emp['status'] === 'On Deputation') {
        throw new RuntimeException('Employee is on deputation. Record the reversion first.');
    }
}
function svc_require_date(?string $d, string $label): string
{
    if (!$d) throw new RuntimeException("$label is required.");
    return $d;
}
function svc_date_not_before_joining(array $emp, string $date, string $label): void
{
    if ($date < $emp['joining_date']) throw new RuntimeException("$label cannot be before the joining date (" . fmt_date($emp['joining_date']) . ').');
}

/* =====================================================================
 * REFERENCE NUMBERS  (human-readable, unique, searchable)
 *   Pattern (System Settings): {TYPE}-{YYYY}-{SEQ:4}   e.g. AWD-2022-0003
 * ===================================================================*/
const SVC_TYPES = [
    'postings' => ['PST', 'Posting', 'effective_date', 'postings'], 'promotions' => ['PRM', 'Promotion', 'effective_date', 'service'],
    'increments' => ['INC', 'Increment', 'effective_date', 'service'], 'leaves' => ['LVE', 'Leave', 'from_date', 'leaves'],
    'awards' => ['AWD', 'Award', 'order_date', 'service'], 'punishments' => ['PUN', 'Punishment', 'order_date', 'service'],
    'reinstatements' => ['RIN', 'Reinstatement', 'reinstatement_date', 'service'], 'deputations' => ['DEP', 'Deputation', 'start_date', 'service'],
    'exits' => ['EXT', 'Exit', 'exit_date', 'service'], 'rejoinings' => ['RJN', 'Rejoining', 'rejoining_date', 'service'],
    'inquiries' => ['INQ', 'Inquiry', 'initiated_date', 'inquiries'],
];

/** Generate the next reference number for a service table (sequence per type per year). */
function svc_next_ref(string $table, ?string $date = null): string
{
    $type = SVC_TYPES[$table][0] ?? strtoupper(substr($table, 0, 3));
    $pattern = (string)setting('service_ref_pattern', '{TYPE}-{YYYY}-{SEQ:4}');
    $d = $date ? date_create($date) : null; if (!$d) $d = new DateTime();
    $seqLen = 4; if (preg_match('/\{SEQ:(\d+)\}/', $pattern, $m)) $seqLen = max(1, (int)$m[1]);
    $tokens = ['{TYPE}' => $type, '{PREFIX}' => (string)setting('employee_code_prefix', 'EMP'), '{YYYY}' => $d->format('Y'), '{YY}' => $d->format('y')];
    $base = str_replace(array_keys($tokens), array_values($tokens), $pattern);
    $like = preg_replace('/\{SEQ(?::\d+)?\}/', '%', $base);
    $regex = '/^' . str_replace('\\%', '(\d+)', preg_quote($like, '/')) . '$/';
    $max = 0;
    foreach (db_all("SELECT ref_no FROM `" . db_ident($table) . "` WHERE ref_no LIKE ?", [$like]) as $r) {
        if (preg_match($regex, (string)$r['ref_no'], $m)) $max = max($max, (int)$m[1]);
    }
    $next = $max + 1;
    for ($i = 0; $i < 100; $i++) {
        $ref = preg_replace('/\{SEQ(?::\d+)?\}/', str_pad((string)$next, $seqLen, '0', STR_PAD_LEFT), $base);
        if (!db_value("SELECT id FROM `" . db_ident($table) . "` WHERE ref_no = ?", [$ref])) return $ref;
        $next++;
    }
    throw new RuntimeException('Unable to allocate a reference number.');
}

/** Insert a service record with an auto-assigned ref_no. */
function svc_insert(string $table, array $data): int
{
    if (empty($data['ref_no'])) {
        $dateCol = SVC_TYPES[$table][2] ?? null;
        $data['ref_no'] = svc_next_ref($table, $dateCol && !empty($data[$dateCol]) ? (string)$data[$dateCol] : null);
    }
    return db_insert($table, $data);
}

/** Reference number of a record (or a graceful fallback). */
function svc_ref_of(?string $table, ?int $id): string
{
    if (!$table || !$id || !isset(SVC_TYPES[$table])) return '';
    static $cache = [];
    $k = "$table|$id";
    if (!array_key_exists($k, $cache)) $cache[$k] = (string)(db_value("SELECT ref_no FROM `" . db_ident($table) . "` WHERE id = ?", [$id]) ?: (SVC_TYPES[$table][0] . '-#' . $id));
    return $cache[$k];
}

/** Find a service record by reference number -> ['table','id','employee_id','tab'] or null. */
function svc_find_ref(string $ref): ?array
{
    $ref = strtoupper(trim($ref));
    if ($ref === '') return null;
    $type = strtoupper(substr($ref, 0, 3));
    $order = [];
    foreach (SVC_TYPES as $t => $m) if ($m[0] === $type) array_unshift($order, $t); else $order[] = $t;
    foreach ($order as $t) {
        $row = db_row("SELECT id, employee_id FROM `$t` WHERE ref_no = ?", [$ref]);
        if ($row) return ['table' => $t, 'id' => (int)$row['id'], 'employee_id' => (int)$row['employee_id'], 'tab' => SVC_TYPES[$t][3], 'label' => SVC_TYPES[$t][1]];
    }
    return null;
}

/** Profile URL that opens the tab holding a record. */
function svc_record_url(string $table, int $id, int $empId): string
{
    return url('employees/view.php?id=' . $empId . '&ref=' . rawurlencode(svc_ref_of($table, $id)) . '#tab-' . (SVC_TYPES[$table][3] ?? 'service'));
}
/* =====================================================================
 * POSTINGS (append-only; one current)
 * ===================================================================*/
function svc_new_posting(array $emp, array $p, string $timelineType = 'Posting', ?string $title = null): int
{
    $empId = (int)$emp['id'];
    $date  = svc_require_date($p['effective_date'] ?? null, 'Effective date');
    svc_date_not_before_joining($emp, $date, 'Effective date');
    $deptId = (int)($p['department_id'] ?: $emp['department_id']);
    /* A tehsil authority posts within its tehsil and a district authority
       within its district; a move across tehsils is the district's order. */
    if ($deptId !== (int)$emp['department_id'] && !department_allowed($deptId)) {
        throw new RuntimeException(user_level() === 'tehsil'
            ? 'That department is outside your tehsil. A posting to another tehsil is ordered at district level.'
            : 'That department is outside your reach (' . jurisdiction_label() . ').');
    }
    $locId = ($p['location_id'] ?? null) ? (int)$p['location_id'] : null;
    if (!location_in_department($locId, $deptId)) {
        throw new RuntimeException('The office chosen does not belong to that department.');
    }

    // close previous current posting
    db_exec('UPDATE postings SET is_current = 0, end_date = COALESCE(end_date, ?) WHERE employee_id = ? AND is_current = 1', [date_add_days($date, -1) < $emp['joining_date'] ? $date : date_add_days($date, -1), $empId]);

    $id = svc_insert('postings', [
        'employee_id'   => $empId,
        'office'        => nullable($p['office'] ?? null),
        'department_id' => $deptId,
        'section'       => nullable($p['section'] ?? null),
        'location_id'   => ($p['location_id'] ?? null) ?: null,
        'posting_type'  => $p['posting_type'],
        'effective_date'=> $date,
        'order_no'      => nullable($p['order_no'] ?? null),
        'order_date'    => ($p['order_date'] ?? null) ?: null,
        'reporting_officer' => nullable($p['reporting_officer'] ?? null),
        'deputation_org_id' => $p['deputation_org_id'] ?? null,
        'expected_reversion_date' => $p['expected_reversion_date'] ?? null,
        'remarks'       => nullable($p['remarks'] ?? null),
        'is_current'    => 1,
        'created_by'    => user_id(),
    ]);
    $upd = ['current_posting_id' => $id];
    if ($deptId !== (int)$emp['department_id']) $upd['department_id'] = $deptId;
    db_update('employees', $upd, 'id = ?', [$empId]);

    $loc = $p['location_id'] ? master_name('posting_locations', (int)($p['location_id'] ?? 0)) : null;
    $desc = trim(($loc ? $loc . ' · ' : '') . master_name('departments', $deptId) . (!empty($p['order_no']) ? ' · Order ' . $p['order_no'] : '') . (!empty($p['reporting_officer']) ? ' · Reporting to ' . $p['reporting_officer'] : ''));
    add_timeline_event($empId, $timelineType, $date, $title ?? $p['posting_type'], $desc, 'postings', $id);
    log_activity('CREATE', 'postings', $id, null, $p, $p['posting_type'] . ' recorded for ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * PROMOTION / UPGRADATION
 * ===================================================================*/
function svc_promotion(array $emp, array $p): int
{
    $isPromo = ($p['promotion_type'] ?? 'Promotion') === 'Promotion';
    svc_guard($emp, ['not_suspended' => true, 'action' => 'Promotion', 'confirmed' => $isPromo]);
    $date = svc_require_date($p['effective_date'] ?? null, 'Effective date');
    svc_date_not_before_joining($emp, $date, 'Effective date');
    if (empty($p['new_scale_id'])) throw new RuntimeException('New pay scale is required.');
    if ($isPromo && !$p['new_designation_id']) throw new RuntimeException('New designation is required for a promotion.');
    $newScale = db_find('pay_scales', (int)($p['new_scale_id'] ?? 0));
    $oldScale = $emp['pay_scale_id'] ? db_find('pay_scales', (int)$emp['pay_scale_id']) : null;
    if ($oldScale && $newScale && (int)$newScale['scale_number'] < (int)$oldScale['scale_number']) {
        throw new RuntimeException('New scale (' . $newScale['name'] . ') is lower than the current scale (' . $oldScale['name'] . '). Use a punishment record for reductions.');
    }
    $empId = (int)$emp['id'];
    $id = svc_insert('promotions', [
        'employee_id' => $empId,
        'promotion_type' => $isPromo ? 'Promotion' : 'Upgradation',
        'upgradation_kind' => $isPromo ? null : ($p['upgradation_kind'] ?: 'Financial'),
        'previous_designation_id' => $emp['designation_id'],
        'new_designation_id' => $isPromo ? (int)($p['new_designation_id'] ?? 0) : null,
        'previous_scale_id' => $emp['pay_scale_id'],
        'new_scale_id' => (int)($p['new_scale_id'] ?? 0),
        'effective_date' => $date,
        'order_no' => nullable($p['order_no'] ?? null), 'order_date' => ($p['order_date'] ?? null) ?: null,
        'order_by_authority' => nullable($p['order_by_authority'] ?? null), 'remarks' => nullable($p['remarks'] ?? null),
        'created_by' => user_id(),
    ]);
    $upd = ['pay_scale_id' => (int)($p['new_scale_id'] ?? 0)];
    if ($isPromo) $upd['designation_id'] = (int)($p['new_designation_id'] ?? 0);
    if (!empty($p['new_salary'])) $upd['basic_salary'] = (float)$p['new_salary'];
    elseif ($newScale && $newScale['min_salary'] && (!$emp['basic_salary'] || (float)$emp['basic_salary'] < (float)$newScale['min_salary'])) $upd['basic_salary'] = (float)$newScale['min_salary'];
    db_update('employees', $upd, 'id = ?', [$empId]);

    $title = $isPromo ? 'Promoted to ' . master_name('designations', (int)($p['new_designation_id'] ?? 0)) . ' (' . $newScale['name'] . ')'
                      : 'Upgraded to ' . $newScale['name'] . ' (' . ($p['upgradation_kind'] ?: 'Financial') . ' upgradation)';
    add_timeline_event($empId, 'Promotion', $date, $title, ($oldScale ? 'From ' . $oldScale['name'] : '') . ($p['order_no'] ? ' · Order ' . $p['order_no'] : ''), 'promotions', $id);
    if ($isPromo && !empty($p['create_posting'])) {
        $emp2 = employee_get($empId);
        svc_new_posting($emp2, ['posting_type' => 'Promotion Posting', 'effective_date' => $date, 'department_id' => $emp['department_id'],
            'location_id' => $p['location_id'] ?? null, 'order_no' => $p['order_no'] ?? null, 'order_date' => $p['order_date'] ?? null, 'remarks' => 'Posting on promotion'], 'Posting', 'Promotion posting');
    }
    log_activity('CREATE', 'promotions', $id, null, $p, $title . ' — ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * INCREMENT
 * ===================================================================*/
function svc_increment(array $emp, array $p, bool $backBenefit = false, ?int $reinstatementId = null): int
{
    if (!$backBenefit) svc_guard($emp, ['not_suspended' => true, 'action' => 'Increment']);
    $date = svc_require_date($p['effective_date'] ?? null, 'Effective date');
    svc_date_not_before_joining($emp, $date, 'Effective date');
    $new = (float)($p['new_salary'] ?? 0);
    if ($new <= 0) throw new RuntimeException('New salary must be greater than zero.');
    $prev = $emp['basic_salary'] !== null ? (float)$emp['basic_salary'] : null;
    if ($prev !== null && $new < $prev) throw new RuntimeException('New salary cannot be lower than the current salary (' . fmt_money($prev) . ').');
    // increments withheld?
    if (!$backBenefit && db_value('SELECT p.id FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? AND p.is_voided = 0 AND t.name LIKE "%Withholding of Increment%" AND (p.suspension_end IS NULL OR p.suspension_end >= ?) AND p.order_date >= DATE_SUB(?, INTERVAL 1 YEAR)', [(int)$emp['id'], $date, $date])) {
        throw new RuntimeException('An increment-withholding punishment is in force for this employee.');
    }
    $empId = (int)$emp['id'];
    $id = svc_insert('increments', [
        'employee_id' => $empId, 'previous_salary' => $prev, 'new_salary' => $new, 'effective_date' => $date,
        'order_no' => nullable($p['order_no'] ?? null), 'order_date' => $p['order_date'] ?? null,
        'is_back_benefit' => $backBenefit ? 1 : 0, 'reinstatement_id' => $reinstatementId,
        'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ]);
    db_update('employees', ['basic_salary' => $new], 'id = ?', [$empId]);
    add_timeline_event($empId, 'Increment', $date, ($backBenefit ? 'Back-benefit increment' : 'Annual increment') . ': ' . fmt_money($prev) . ' → ' . fmt_money($new), $p['order_no'] ? 'Order ' . $p['order_no'] : null, 'increments', $id);
    log_activity('CREATE', 'increments', $id, null, ['previous' => $prev, 'new' => $new, 'date' => $date], 'Increment recorded for ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * LEAVE  (EOL > N months = break in service, LPR -> On LPR)
 * ===================================================================*/
function svc_leave(array $emp, array $p): int
{
    svc_guard($emp);
    $type = db_find('leave_types', (int)($p['leave_type_id'] ?? 0));
    if (!$type) throw new RuntimeException('Leave type is required.');
    $from = svc_require_date($p['from_date'], 'From date');
    $to   = svc_require_date($p['to_date'], 'To date');
    if ($to < $from) throw new RuntimeException('To date cannot be before From date.');
    svc_date_not_before_joining($emp, $from, 'Leave start');
    $days = days_between($from, $to);
    if ($type['max_days'] && $days > (int)$type['max_days']) throw new RuntimeException($type['name'] . ' cannot exceed ' . $type['max_days'] . ' days per instance.');
    $empId = (int)$emp['id'];
    // overlap check
    if (db_value('SELECT id FROM leaves WHERE employee_id = ? AND is_voided = 0 AND status <> "Rejected" AND status <> "Cancelled" AND from_date <= ? AND to_date >= ?', [$empId, $to, $from])) {
        throw new RuntimeException('This leave overlaps an existing leave record.');
    }
    $status = in_array($p['status'] ?? 'Approved', ['Approved', 'Pending', 'Rejected', 'Cancelled'], true) ? $p['status'] : 'Approved';
    $breakMonths = (int)setting('eol_break_months', 6);
    $isBreak = (int)$type['is_eol'] && $status === 'Approved' && $to > date_add_months($from, $breakMonths) ? 1 : 0;

    $id = svc_insert('leaves', [
        'employee_id' => $empId, 'leave_type_id' => (int)$type['id'], 'from_date' => $from, 'to_date' => $to, 'duration_days' => $days,
        'status' => $status, 'letter_no' => nullable($p['letter_no'] ?? null), 'letter_date' => ($p['letter_date'] ?? null) ?: null,
        'is_break_in_service' => $isBreak, 'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ]);
    if ($isBreak) {
        db_exec('UPDATE employees SET eol_break_days = eol_break_days + ? WHERE id = ?', [$days, $empId]);
        employee_recalculate($empId);
        $newRet = db_value('SELECT retirement_date FROM employees WHERE id = ?', [$empId]);
        add_timeline_event($empId, 'Leave', $from, 'Break in service: EOL without pay ' . $days . ' days', 'Retirement date adjusted to ' . fmt_date($newRet), 'leaves', $id);
        notify('break', 'Break in service flagged', $emp['name'] . ': EOL of ' . $days . ' days exceeds ' . $breakMonths . ' months.', 'employees/view.php?id=' . $empId);
    } else {
        add_timeline_event($empId, 'Leave', $from, $type['name'] . ' (' . $days . ' days)', fmt_date($from) . ' → ' . fmt_date($to) . ($status !== 'Approved' ? ' · ' . $status : ''), 'leaves', $id);
    }
    if ((int)$type['is_lpr'] && $status === 'Approved') {
        svc_set_status($empId, 'On LPR', $from, 'LPR approved ' . fmt_date($from) . ' → ' . fmt_date($to), 'leaves', $id);
    }
    log_activity('CREATE', 'leaves', $id, null, $p + ['duration_days' => $days, 'is_break_in_service' => $isBreak], $type['name'] . ' recorded for ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * AWARD
 * ===================================================================*/
function svc_award(array $emp, array $p): int
{
    svc_guard($emp);
    $date = svc_require_date($p['order_date'], 'Order date');
    if (empty($p['award_type_id'])) throw new RuntimeException('Award type is required.');
    $empId = (int)$emp['id'];
    $id = svc_insert('awards', ['employee_id' => $empId, 'award_type_id' => (int)($p['award_type_id'] ?? 0), 'order_no' => nullable($p['order_no'] ?? null),
        'order_date' => $date, 'description' => nullable($p['description'] ?? null), 'created_by' => user_id()]);
    add_timeline_event($empId, 'Award', $date, 'Award: ' . master_name('award_types', (int)($p['award_type_id'] ?? 0)), nullable($p['description'] ?? null), 'awards', $id);
    log_activity('CREATE', 'awards', $id, null, $p, 'Award recorded for ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * PUNISHMENT (suspension / removal / dismissal automation)
 * ===================================================================*/
function svc_punishment(array $emp, array $p): int
{
    svc_guard($emp);
    $type = db_find('punishment_types', (int)($p['punishment_type_id'] ?? 0));
    if (!$type) throw new RuntimeException('Punishment type is required.');
    $date = svc_require_date($p['order_date'], 'Order date');
    $empId = (int)$emp['id'];
    $d = [
        'employee_id' => $empId, 'punishment_type_id' => (int)$type['id'], 'order_no' => nullable($p['order_no'] ?? null), 'order_date' => $date,
        'order_by_authority' => nullable($p['order_by_authority'] ?? null), 'description' => nullable($p['description'] ?? null), 'created_by' => user_id(),
    ];
    if ((int)$type['is_suspension']) {
        if ($emp['status'] === 'Suspended') throw new RuntimeException('Employee is already suspended.');
        $d['suspension_start'] = svc_require_date($p['suspension_start'], 'Suspension start date');
        $d['suspension_end']   = ($p['suspension_end'] ?? null) ?: null;
        if ($d['suspension_end'] && $d['suspension_end'] < $d['suspension_start']) throw new RuntimeException('Suspension end cannot be before start.');
        $d['pay_status'] = in_array($p['pay_status'] ?? '', ['Full Pay', 'Subsistence Allowance', 'No Pay'], true) ? $p['pay_status'] : 'Subsistence Allowance';
    }
    $id = svc_insert('punishments', $d);
    $title = 'Punishment: ' . $type['name'];
    add_timeline_event($empId, (int)$type['is_suspension'] ? 'Suspension' : 'Punishment', $date, $title, trim(($p['order_no'] ? 'Order ' . $p['order_no'] . ' · ' : '') . ($p['description'] ?? '')), 'punishments', $id);

    if ((int)$type['is_suspension']) {
        svc_set_status($empId, 'Suspended', $d['suspension_start'], 'Suspended (' . $d['pay_status'] . ')' . ($d['suspension_end'] ? ' until ' . fmt_date($d['suspension_end']) : ' – indefinite'), 'punishments', $id);
    } elseif ((int)$type['is_removal']) {
        svc_exit($emp, ['exit_type' => 'Termination', 'exit_date' => $date, 'order_no' => $p['order_no'], 'order_date' => $date, 'remarks' => 'Removal from service: ' . ($p['description'] ?? '')], true);
    } elseif ((int)$type['is_dismissal']) {
        svc_exit($emp, ['exit_type' => 'Dismissal', 'exit_date' => $date, 'order_no' => $p['order_no'], 'order_date' => $date, 'remarks' => 'Dismissal from service: ' . ($p['description'] ?? '')], true);
    }
    log_activity('CREATE', 'punishments', $id, null, $d, $title . ' — ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * REINSTATEMENT (+ back benefits: missed annual increments on 1 Dec)
 * ===================================================================*/
function svc_reinstatement(array $emp, array $p): int
{
    if ($emp['status'] !== 'Suspended') throw new RuntimeException('Reinstatement can only be recorded for a suspended employee (current status: ' . $emp['status'] . ').');
    $type = db_find('reinstatement_types', (int)($p['reinstatement_type_id'] ?? 0));
    if (!$type) throw new RuntimeException('Reinstatement type is required.');
    $date = svc_require_date($p['reinstatement_date'], 'Reinstatement date');
    $empId = (int)$emp['id'];
    $susp = db_row('SELECT p.* FROM punishments p JOIN punishment_types t ON t.id = p.punishment_type_id WHERE p.employee_id = ? AND t.is_suspension = 1 AND p.is_reinstated = 0 AND p.is_voided = 0 ORDER BY p.id DESC LIMIT 1', [$empId]);
    if ($susp && $date < $susp['suspension_start']) throw new RuntimeException('Reinstatement date cannot be before the suspension start (' . fmt_date($susp['suspension_start']) . ').');
    $withBB = (int)$type['with_back_benefits'];

    // missed increments = 1st December dates within suspension period
    $missed = [];
    if ($withBB && $susp) {
        $y = (int)substr($susp['suspension_start'], 0, 4);
        for (; $y <= (int)substr($date, 0, 4); $y++) {
            $inc = "$y-12-01";
            if ($inc >= $susp['suspension_start'] && $inc <= $date) $missed[] = $inc;
        }
    }
    $id = svc_insert('reinstatements', [
        'employee_id' => $empId, 'punishment_id' => $susp['id'] ?? null, 'reinstatement_date' => $date, 'reinstatement_type_id' => (int)$type['id'],
        'order_no' => nullable($p['order_no'] ?? null), 'order_date' => ($p['order_date'] ?? null) ?: null, 'order_by_authority' => nullable($p['order_by_authority'] ?? null),
        'with_back_benefits' => $withBB, 'missed_increments' => count($missed), 'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ]);
    if ($susp) {
        db_update('punishments', ['is_reinstated' => 1, 'suspension_end' => $susp['suspension_end'] ?: $date], 'id = ?', [(int)$susp['id']]);
    }
    $status = svc_base_status($emp);
    svc_set_status($empId, $status, $date, 'Reinstated: ' . $type['name'], 'reinstatements', $id);
    add_timeline_event($empId, 'Reinstatement', $date, 'Reinstated — ' . $type['name'], ($withBB ? count($missed) . ' missed increment(s) restored' : 'Without back benefits') . ($p['order_no'] ? ' · Order ' . $p['order_no'] : ''), 'reinstatements', $id);

    // back benefits: create the missed increments (service continuity restored)
    if ($withBB && $missed) {
        $scale = $emp['pay_scale_id'] ? db_find('pay_scales', (int)$emp['pay_scale_id']) : null;
        $step  = $scale && $scale['increment_amount'] ? (float)$scale['increment_amount'] : 0.0;
        $cur   = employee_get($empId);
        foreach ($missed as $incDate) {
            if ($step <= 0) break;
            $new = (float)($cur['basic_salary'] ?? 0) + $step;
            if ($scale && $scale['max_salary'] && $new > (float)$scale['max_salary']) break;
            svc_increment($cur, ['effective_date' => $incDate, 'new_salary' => $new, 'order_no' => $p['order_no'] ?? null, 'order_date' => $date, 'remarks' => 'Back benefit on reinstatement'], true, $id);
            $cur = employee_get($empId);
        }
    }
    log_activity('CREATE', 'reinstatements', $id, null, $p + ['missed_increments' => count($missed)], 'Reinstated ' . $emp['name'] . ' (' . $type['name'] . ')', $empId);
    return $id;
}

/* =====================================================================
 * DEPUTATION / REVERSION
 * ===================================================================*/
function svc_deputation(array $emp, array $p): int
{
    svc_guard($emp, ['not_suspended' => true, 'action' => 'Deputation', 'not_on_deputation' => true]);
    if (empty($p['deputation_org_id'])) throw new RuntimeException('Deputation organization is required.');
    $start = svc_require_date($p['start_date'], 'Start date');
    svc_date_not_before_joining($emp, $start, 'Deputation start');
    if ($p['expected_reversion_date'] && $p['expected_reversion_date'] < $start) throw new RuntimeException('Expected reversion cannot be before the start date.');
    $empId = (int)$emp['id'];
    $id = svc_insert('deputations', [
        'employee_id' => $empId, 'deputation_org_id' => (int)($p['deputation_org_id'] ?? 0), 'start_date' => $start,
        'expected_reversion_date' => ($p['expected_reversion_date'] ?? null) ?: null, 'order_no' => nullable($p['order_no'] ?? null), 'order_date' => ($p['order_date'] ?? null) ?: null,
        'remarks' => nullable($p['remarks'] ?? null), 'status' => 'Active', 'created_by' => user_id(),
    ]);
    $org = master_name('deputation_orgs', (int)($p['deputation_org_id'] ?? 0));
    svc_new_posting($emp, ['posting_type' => 'Deputation', 'effective_date' => $start, 'department_id' => $emp['department_id'], 'office' => $org,
        'location_id' => null, 'order_no' => $p['order_no'], 'order_date' => ($p['order_date'] ?? null) ?: null, 'deputation_org_id' => (int)($p['deputation_org_id'] ?? 0),
        'expected_reversion_date' => ($p['expected_reversion_date'] ?? null) ?: null, 'remarks' => 'On deputation to ' . $org], 'Deputation', 'Deputation to ' . $org);
    svc_set_status($empId, 'On Deputation', $start, 'Deputation to ' . $org . ($p['expected_reversion_date'] ? ', expected reversion ' . fmt_date($p['expected_reversion_date']) : ''), 'deputations', $id);
    log_activity('CREATE', 'deputations', $id, null, $p, 'Deputation to ' . $org . ' — ' . $emp['name'], $empId);
    return $id;
}
function svc_reversion(array $emp, array $p): int
{
    $dep = db_row('SELECT d.*, o.name AS org FROM deputations d JOIN deputation_orgs o ON o.id = d.deputation_org_id WHERE d.employee_id = ? AND d.status = "Active" AND d.is_voided = 0 ORDER BY d.id DESC LIMIT 1', [(int)$emp['id']]);
    if (!$dep) throw new RuntimeException('No active deputation found for this employee.');
    $date = svc_require_date($p['actual_reversion_date'], 'Reversion date');
    if ($date < $dep['start_date']) throw new RuntimeException('Reversion date cannot be before the deputation start (' . fmt_date($dep['start_date']) . ').');
    $empId = (int)$emp['id'];
    db_update('deputations', ['status' => 'Reverted', 'actual_reversion_date' => $date, 'reversion_order_no' => nullable($p['reversion_order_no'] ?? null),
        'reversion_order_date' => ($p['reversion_order_date'] ?? null) ?: null, 'reversion_remarks' => nullable($p['reversion_remarks'] ?? null)], 'id = ?', [(int)$dep['id']]);
    // return to the last parent-department posting location
    $last = db_row('SELECT * FROM postings WHERE employee_id = ? AND posting_type <> "Deputation" AND is_voided = 0 ORDER BY effective_date DESC, id DESC LIMIT 1', [$empId]);
    svc_new_posting($emp, ['posting_type' => 'Reversion', 'effective_date' => $date, 'department_id' => $p['department_id'] ?: ($last['department_id'] ?? $emp['department_id']),
        'location_id' => $p['location_id'] ?: ($last['location_id'] ?? null), 'office' => $last['office'] ?? null, 'section' => $last['section'] ?? null,
        'order_no' => $p['reversion_order_no'], 'order_date' => ($p['reversion_order_date'] ?? null) ?: null, 'remarks' => 'Reverted from ' . $dep['org']], 'Reversion', 'Reverted from ' . $dep['org']);
    svc_set_status($empId, svc_base_status($emp), $date, 'Reverted from deputation (' . $dep['org'] . ')', 'deputations', (int)$dep['id']);
    log_activity('UPDATE', 'deputations', (int)$dep['id'], ['status' => 'Active'], ['status' => 'Reverted', 'actual_reversion_date' => $date], 'Reversion from ' . $dep['org'] . ' — ' . $emp['name'], $empId);
    return (int)$dep['id'];
}

/* =====================================================================
 * EXIT / SEPARATION
 * ===================================================================*/
function svc_exit(array $emp, array $p, bool $fromPunishment = false): int
{
    if (!$fromPunishment) svc_guard($emp);
    $type = $p['exit_type'] ?? '';
    if (!in_array($type, EXIT_TYPES, true)) throw new RuntimeException('Exit type is required.');
    $date = svc_require_date($p['exit_date'], 'Exit date');
    svc_date_not_before_joining($emp, $date, 'Exit date');
    $empId = (int)$emp['id'];
    if ($emp['status'] === 'On Deputation') throw new RuntimeException('Employee is on deputation. Record the reversion before an exit.');
    $d = [
        'employee_id' => $empId, 'exit_type' => $type, 'exit_date' => $date, 'order_no' => nullable($p['order_no'] ?? null), 'order_date' => $p['order_date'] ?? null,
        'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ];
    if ($type === 'Medical Invalidation') {
        $d['medical_board_ref'] = nullable($p['medical_board_ref'] ?? null);
        $d['medical_board_date'] = $p['medical_board_date'] ?? null;
        $d['medical_recommendation'] = nullable($p['medical_recommendation'] ?? null);
        if (!$d['medical_board_ref']) throw new RuntimeException('Medical Board reference number is required for medical invalidation.');
    }
    if ($type === 'Death in Service') {
        $d['date_of_death'] = svc_require_date($p['date_of_death'] ?? $date, 'Date of death');
        $d['cause_of_death'] = nullable($p['cause_of_death'] ?? null);
    }
    $id = svc_insert('exits', $d);
    $map = ['Resignation' => 'Resigned', 'Normal Retirement' => 'Retired', 'Medical Invalidation' => 'Medical Retired', 'Termination' => 'Terminated',
            'Dismissal' => 'Dismissed', 'Death in Service' => 'Deceased (In Service)', 'Contract Expired' => 'Contract Expired'];
    // close current posting
    db_exec('UPDATE postings SET is_current = 0, end_date = COALESCE(end_date, ?) WHERE employee_id = ? AND is_current = 1', [$date, $empId]);
    svc_set_status($empId, $map[$type], $date, $type . ($p['order_no'] ?? '' ? ' · Order ' . $p['order_no'] : ''), 'exits', $id);
    $upd = ['is_lpr_eligible' => 0];
    if ($type === 'Death in Service') {
        $upd['service_closed'] = 1;
        db_exec('UPDATE employee_family SET is_pension_claimant = 1 WHERE employee_id = ? AND is_deleted = 0 AND (is_nominee = 1 OR relation = "Spouse")', [$empId]);
        notify('death', 'Death in service recorded', $emp['name'] . ' — nominees flagged for family pension / gratuity.', 'employees/view.php?id=' . $empId);
    }
    db_update('employees', $upd, 'id = ?', [$empId]);
    add_timeline_event($empId, 'Exit', $date, $type, trim(($p['remarks'] ?? '') . ($type === 'Medical Invalidation' && !empty($d['medical_board_ref']) ? ' · Medical Board ' . $d['medical_board_ref'] : '')) ?: null, 'exits', $id);
    log_activity('CREATE', 'exits', $id, null, $d, $type . ' — ' . $emp['name'], $empId);
    return $id;
}

/* =====================================================================
 * REJOINING (reuses the same employee id)
 * ===================================================================*/
function svc_rejoin_allowed(array $emp): array
{
    if ((int)$emp['service_closed']) return [false, 'Service book is closed.', false];
    return match ($emp['status']) {
        'Contract Expired', 'Resigned' => [true, '', false],
        'Retired', 'Medical Retired'   => [true, 'Retired employees may only rejoin on contract basis.', false],
        'Terminated'                   => [(bool)(int)setting('allow_terminated_rejoin', 1) && is_super_admin(), 'Terminated employees can only be rejoined by the Super Admin (override).', true],
        'Dismissed'                    => [false, 'Dismissed employees cannot rejoin.', false],
        default                        => [false, 'Employee is currently in service.', false],
    };
}
function svc_rejoin(array $emp, array $p): int
{
    [$ok, $why, $override] = svc_rejoin_allowed($emp);
    if (!$ok) throw new RuntimeException($why ?: 'Rejoining is not allowed for this employee.');
    $date = svc_require_date($p['rejoining_date'], 'Rejoining date');
    $lastExit = db_row('SELECT * FROM exits WHERE employee_id = ? AND is_voided = 0 ORDER BY exit_date DESC, id DESC LIMIT 1', [(int)$emp['id']]);
    if ($lastExit && $date < $lastExit['exit_date']) throw new RuntimeException('Rejoining date cannot be before the exit date (' . fmt_date($lastExit['exit_date']) . ').');
    $type = in_array($p['rejoin_type'] ?? '', REJOIN_TYPES, true) ? $p['rejoin_type'] : 'Fresh';
    if (in_array($emp['status'], ['Retired', 'Medical Retired'], true)) {
        $type = 'Retirement Contract';
        if (!$p['new_contract_from'] || !$p['new_contract_to']) throw new RuntimeException('Contract dates are required for a retirement contract.');
    }
    if ($p['new_contract_from'] && $p['new_contract_to'] && $p['new_contract_to'] < $p['new_contract_from']) throw new RuntimeException('Contract end must be after contract start.');
    $empId = (int)$emp['id'];
    $id = svc_insert('rejoinings', [
        'employee_id' => $empId, 'exit_id' => $lastExit['id'] ?? null, 'previous_status' => $emp['status'], 'rejoin_type' => $type, 'rejoining_date' => $date,
        'new_contract_from' => ($p['new_contract_from'] ?? null) ?: null, 'new_contract_to' => ($p['new_contract_to'] ?? null) ?: null,
        'order_no' => nullable($p['order_no'] ?? null), 'order_date' => ($p['order_date'] ?? null) ?: null, 'is_override' => $override ? 1 : 0, 'override_by' => $override ? user_id() : null,
        'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ]);
    if ($lastExit) db_update('exits', ['is_rejoined' => 1], 'id = ?', [(int)$lastExit['id']]);
    $upd = ['is_active' => 1, 'service_closed' => 0];
    if ($p['new_contract_from'] || $p['new_contract_to']) { $upd['contract_from'] = ($p['new_contract_from'] ?? null) ?: null; $upd['contract_to'] = ($p['new_contract_to'] ?? null) ?: null; }
    if ($type === 'Retirement Contract') {
        $ct = db_value('SELECT id FROM employment_types WHERE is_contract = 1 AND is_deleted = 0 ORDER BY sort_order LIMIT 1');
        if ($ct) $upd['employment_type_id'] = (int)$ct;
    }
    if ($type === 'Fresh') { $upd['probation_start'] = $date; $upd['probation_end'] = calc_probation_end($date); $upd['confirmation_date'] = null; $upd['confirmation_order_no'] = null; }
    db_update('employees', $upd, 'id = ?', [$empId]);
    $newStatus = $type === 'Fresh' ? 'Probation' : 'Active';
    $emp2 = employee_get($empId);
    // reopen a posting so the employee has a current location again
    $last = db_row('SELECT * FROM postings WHERE employee_id = ? AND is_voided = 0 ORDER BY effective_date DESC, id DESC LIMIT 1', [$empId]);
    svc_new_posting($emp2, ['posting_type' => 'Routine Posting', 'effective_date' => $date, 'department_id' => $p['department_id'] ?: ($last['department_id'] ?? $emp['department_id']),
        'location_id' => $p['location_id'] ?: ($last['location_id'] ?? null), 'office' => $last['office'] ?? null, 'section' => $last['section'] ?? null,
        'order_no' => $p['order_no'], 'order_date' => ($p['order_date'] ?? null) ?: null, 'remarks' => 'Posting on rejoining (' . $type . ')'], 'Posting', 'Posting on rejoining');
    svc_set_status($empId, $newStatus, $date, 'Rejoined (' . $type . ')' . ($override ? ' — Super Admin override' : ''), 'rejoinings', $id);
    add_timeline_event($empId, 'Rejoining', $date, 'Rejoined service — ' . $type, ($p['new_contract_to'] ? 'Contract until ' . fmt_date($p['new_contract_to']) : null), 'rejoinings', $id);
    employee_recalculate($empId);
    log_activity('CREATE', 'rejoinings', $id, null, $p + ['override' => $override], 'Rejoined ' . $emp['name'] . ' (' . $type . ')', $empId);
    return $id;
}

/* =====================================================================
 * INQUIRIES (disciplinary proceedings — PEEDA / E&D rules)
 *
 * An inquiry is opened, stays open while it is being conducted, and is
 * concluded with an outcome. Concluding updates the same record (like a
 * deputation reversion) so that one inquiry is one reference number.
 * ===================================================================*/
const INQUIRY_OUTCOMES = ['Exonerated', 'Warning Issued', 'Minor Penalty Imposed', 'Major Penalty Imposed', 'Filed / Dropped', 'Referred to Another Forum'];
const INQUIRY_OPEN_STATUSES = ['Open', 'Under Inquiry'];

/** Open inquiries against an employee (empty array = none pending). */
function svc_open_inquiries(int $empId): array
{
    return db_all('SELECT i.*, t.name AS inquiry_type FROM inquiries i JOIN inquiry_types t ON t.id = i.inquiry_type_id
                   WHERE i.employee_id = ? AND i.is_voided = 0 AND i.status <> "Concluded" ORDER BY i.initiated_date, i.id', [$empId]);
}

function svc_has_open_inquiry(int $empId): bool
{
    return (bool)db_value('SELECT id FROM inquiries WHERE employee_id = ? AND is_voided = 0 AND status <> "Concluded" LIMIT 1', [$empId]);
}

function svc_inquiry(array $emp, array $p): int
{
    svc_guard($emp);
    $type = db_find('inquiry_types', (int)($p['inquiry_type_id'] ?? 0));
    if (!$type) throw new RuntimeException('Inquiry type is required.');
    $subject = trim((string)($p['subject'] ?? ''));
    if ($subject === '') throw new RuntimeException('Subject of the inquiry is required.');
    $date  = svc_require_date($p['initiated_date'] ?? null, 'Initiation date');
    $empId = (int)$emp['id'];
    svc_date_not_before_joining($emp, $date, 'Initiation date');
    if ($date > today()) throw new RuntimeException('Initiation date cannot be in the future.');
    $status = in_array($p['status'] ?? '', INQUIRY_OPEN_STATUSES, true) ? $p['status'] : 'Open';

    $d = [
        'employee_id' => $empId, 'inquiry_type_id' => (int)$type['id'], 'subject' => mb_substr($subject, 0, 200),
        'allegation' => nullable($p['allegation'] ?? null), 'order_no' => nullable($p['order_no'] ?? null),
        'order_date' => ($p['order_date'] ?? null) ?: null, 'initiated_date' => $date,
        'inquiry_officer' => nullable($p['inquiry_officer'] ?? null), 'ordering_authority' => nullable($p['ordering_authority'] ?? null),
        'status' => $status, 'remarks' => nullable($p['remarks'] ?? null), 'created_by' => user_id(),
    ];
    $id = svc_insert('inquiries', $d);
    $desc = trim(implode(' · ', array_filter([
        $type['name'],
        $d['inquiry_officer'] ? 'Inquiry Officer: ' . $d['inquiry_officer'] : '',
        $d['order_no'] ? 'Order ' . $d['order_no'] : '',
    ])));
    add_timeline_event($empId, 'Inquiry', $date, 'Inquiry initiated: ' . $subject, $desc, 'inquiries', $id);
    log_activity('CREATE', 'inquiries', $id, null, $d, 'Inquiry initiated against ' . $emp['name'] . ': ' . $subject, $empId);
    return $id;
}

/** Conclude an open inquiry with an outcome. Returns the inquiry id. */
function svc_inquiry_conclude(array $emp, array $p): int
{
    $empId = (int)$emp['id'];
    $inq = db_row('SELECT i.*, t.name AS inquiry_type FROM inquiries i JOIN inquiry_types t ON t.id = i.inquiry_type_id
                   WHERE i.id = ? AND i.employee_id = ? AND i.is_voided = 0', [(int)($p['inquiry_id'] ?? 0), $empId]);
    if (!$inq) throw new RuntimeException('Select an open inquiry to conclude.');
    if ($inq['status'] === 'Concluded') throw new RuntimeException('Inquiry ' . $inq['ref_no'] . ' is already concluded.');
    $outcome = (string)($p['outcome'] ?? '');
    if (!in_array($outcome, INQUIRY_OUTCOMES, true)) throw new RuntimeException('Select the outcome of the inquiry.');
    $date = svc_require_date($p['concluded_date'] ?? null, 'Conclusion date');
    if ($date < $inq['initiated_date']) throw new RuntimeException('Conclusion date cannot be before the inquiry was initiated (' . fmt_date($inq['initiated_date']) . ').');
    if ($date > today()) throw new RuntimeException('Conclusion date cannot be in the future.');

    $new = [
        'status' => 'Concluded', 'outcome' => $outcome, 'concluded_date' => $date,
        'closing_order_no' => nullable($p['closing_order_no'] ?? null), 'findings' => nullable($p['findings'] ?? null),
        'concluded_by' => user_id(),
    ];
    db_update('inquiries', $new, 'id = ?', [(int)$inq['id']]);
    add_timeline_event($empId, 'Inquiry', $date, 'Inquiry concluded: ' . $inq['subject'], trim($outcome
        . ($new['closing_order_no'] ? ' · Order ' . $new['closing_order_no'] : '')
        . ' · ' . days_between($inq['initiated_date'], $date) . ' days'), 'inquiries', (int)$inq['id']);
    log_activity('UPDATE', 'inquiries', (int)$inq['id'], ['status' => $inq['status'], 'outcome' => $inq['outcome']], $new,
        'Inquiry ' . $inq['ref_no'] . ' concluded — ' . $outcome, $empId);
    return (int)$inq['id'];
}

/* =====================================================================
 * VOID (Super Admin correction that preserves history)
 * ===================================================================*/
const VOIDABLE_TABLES = ['postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'inquiries'];

function svc_void(string $table, int $id, string $reason): void
{
    if (!is_super_admin()) throw new RuntimeException('Only the Super Admin can void records.');
    if (!in_array($table, VOIDABLE_TABLES, true)) throw new RuntimeException('Invalid record type.');
    $row = db_find($table, $id);
    if (!$row) throw new RuntimeException('Record not found.');
    if ($row['is_voided']) throw new RuntimeException('Record is already voided.');
    if (trim($reason) === '') throw new RuntimeException('A reason is required to void a record.');
    $empId = (int)$row['employee_id'];
    db_update($table, ['is_voided' => 1, 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => user_id(), 'voided_at' => date('Y-m-d H:i:s')], 'id = ?', [$id]);

    // side effects
    if ($table === 'postings' && $row['is_current']) {
        db_update('postings', ['is_current' => 0], 'id = ?', [$id]);
        $prev = db_row('SELECT * FROM postings WHERE employee_id = ? AND is_voided = 0 ORDER BY effective_date DESC, id DESC LIMIT 1', [$empId]);
        if ($prev) db_update('postings', ['is_current' => 1, 'end_date' => null], 'id = ?', [(int)$prev['id']]);
        db_update('employees', ['current_posting_id' => $prev['id'] ?? null], 'id = ?', [$empId]);
    }
    if ($table === 'leaves' && $row['is_break_in_service']) {
        db_exec('UPDATE employees SET eol_break_days = GREATEST(0, eol_break_days - ?) WHERE id = ?', [(int)$row['duration_days'], $empId]);
        employee_recalculate($empId);
    }
    if ($table === 'reinstatements' && $row['punishment_id']) {
        db_update('punishments', ['is_reinstated' => 0], 'id = ?', [(int)$row['punishment_id']]);
        db_exec('UPDATE punishments SET suspension_end = NULL WHERE id = ? AND suspension_end = ?', [(int)$row['punishment_id'], $row['reinstatement_date']]);
        db_exec('UPDATE increments SET is_voided = 1, void_reason = ?, voided_by = ?, voided_at = NOW() WHERE reinstatement_id = ? AND is_voided = 0', ['Reinstatement voided: ' . $reason, user_id(), $id]);
    }
    if ($table === 'exits') {
        db_exec('UPDATE employee_family SET is_pension_claimant = 0 WHERE employee_id = ?', [$empId]);
    }
    if ($table === 'rejoinings' && $row['exit_id']) {
        db_update('exits', ['is_rejoined' => 0], 'id = ?', [(int)$row['exit_id']]);
    }
    if ($table === 'promotions') {
        db_update('employees', ['pay_scale_id' => $row['previous_scale_id'], 'designation_id' => $row['previous_designation_id'] ?: db_value('SELECT designation_id FROM employees WHERE id = ?', [$empId])], 'id = ?', [$empId]);
    }
    if ($table === 'increments' && $row['previous_salary'] !== null) {
        db_update('employees', ['basic_salary' => $row['previous_salary']], 'id = ?', [$empId]);
    }
    db_exec('DELETE FROM timeline_events WHERE reference_table = ? AND reference_id = ?', [$table, $id]);
    $new = svc_recompute_status($empId);
    add_timeline_event($empId, 'Status', today(), 'Record voided: ' . svc_ref_of($table, $id), $reason . ' · Status now ' . $new, $table, $id);
    log_activity('VOID', $table, $id, $row, ['is_voided' => 1, 'void_reason' => $reason], 'Voided ' . svc_ref_of($table, $id) . ': ' . $reason, $empId);
}
