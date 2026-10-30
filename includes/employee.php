<?php
/**
 * Employee helpers: loading, code generation, computed dates,
 * access checks and transaction guards used by every service module.
 */
declare(strict_types=1);

/** Employee row joined with master names. */
function employee_get(int $id, bool $includeDeleted = false): ?array
{
    $sql = 'SELECT e.*,
                d.name AS department, d.code AS department_code, d.level AS department_level,
                d.district_id AS dept_district_id, d.tehsil_id AS dept_tehsil_id,
                ds.name AS district, th.name AS tehsil,
                g.name AS designation, ps.name AS pay_scale, ps.scale_number,
                et.name AS employment_type, et.is_contract, et.has_probation,
                bg.name AS blood_group, ms.name AS marital_status
            FROM employees e
            LEFT JOIN departments d      ON d.id = e.department_id
            LEFT JOIN districts ds       ON ds.id = d.district_id
            LEFT JOIN tehsils th         ON th.id = d.tehsil_id
            LEFT JOIN designations g     ON g.id = e.designation_id
            LEFT JOIN pay_scales ps      ON ps.id = e.pay_scale_id
            LEFT JOIN employment_types et ON et.id = e.employment_type_id
            LEFT JOIN blood_groups bg    ON bg.id = e.blood_group_id
            LEFT JOIN marital_statuses ms ON ms.id = e.marital_status_id
            WHERE e.id = ?' . ($includeDeleted ? '' : ' AND e.is_deleted = 0');
    return db_row($sql, [$id]);
}

/**
 * Load an employee or abort. $manage = true when the caller is about to change
 * the record, which needs the employee to be inside the user's jurisdiction;
 * otherwise viewing one's own record is also allowed.
 */
function employee_load_or_fail(int $id, bool $includeDeleted = false, bool $manage = false): array
{
    $emp = $id ? employee_get($id, $includeDeleted) : null;
    if (!$emp) {
        if (is_ajax()) json_error('Employee not found.', 404);
        flash('danger', 'Employee not found.');
        redirect(is_employee_user() ? 'employees/view.php?id=' . (int)user_employee_id() : 'employees/index.php');
    }
    require_employee_access($emp, $manage);
    return $emp;
}

/* =====================================================================
 * PLACE — an employee belongs to their department, and through it to a
 * tehsil and a district (see includes/geo.php for the picker)
 * ===================================================================*/

/** Offices (sites) of the departments the user reaches, for dropdowns.
 *  Each option carries data-department so the picker can narrow them. */
function posting_location_options(?int $selectedId = null): array
{
    [$w, $p] = location_scope('l');
    return db_all("SELECT l.id, l.name, l.department_id FROM posting_locations l
                    WHERE l.is_deleted = 0 AND ((l.is_active = 1 AND $w) OR l.id = ?)
                    ORDER BY l.sort_order, l.name", array_merge($p, [(int)$selectedId]));
}

/** <option>s for posting_location_options(), tagged with their department. */
function posting_location_options_html(?int $selectedId = null): string
{
    $h = '';
    foreach (posting_location_options($selectedId) as $l) {
        $h .= '<option value="' . (int)$l['id'] . '" data-department="' . (int)$l['department_id'] . '"'
            . ((int)$l['id'] === (int)$selectedId ? ' selected' : '') . '>' . e($l['name']) . '</option>';
    }
    return $h;
}

/** Whether an office belongs to the given department. */
function location_in_department(?int $locationId, ?int $departmentId): bool
{
    if (!$locationId) return true;
    return (bool)db_value('SELECT id FROM posting_locations WHERE id = ? AND department_id = ?', [$locationId, (int)$departmentId]);
}

/* =====================================================================
 * EMPLOYEE LOGIN — every registered employee can see their own record
 * ===================================================================*/

/** SQL (on employee_documents alias d) limiting what an employee sees of their own file. */
function employee_self_document_filter(string $alias = 'd'): string
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'd';
    return is_employee_user()
        ? "($a.related_table IS NULL OR $a.related_table IN ('employees', 'postings'))"
        : '1=1';
}

/** The username an employee signs in with: their CNIC without dashes. */
function employee_login_username(string $cnic): string
{
    return str_replace('-', '', trim($cnic));
}

/** A readable temporary password (no 0/O/1/l ambiguity). */
function employee_temp_password(): string
{
    $alphabet = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $out = '';
    for ($i = 0; $i < 8; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $out;
}

/**
 * Create the sign-in for a newly registered employee. Skipped when the
 * employee is already linked to an account (e.g. an AC who is also on the
 * register). Returns ['username' => ..., 'password' => ...] or null.
 */
function employee_create_login(array $emp): ?array
{
    if (db_value('SELECT id FROM users WHERE employee_id = ? AND is_deleted = 0', [(int)$emp['id']])) return null;
    $username = employee_login_username((string)$emp['cnic']);
    if ($username === '' || db_value('SELECT id FROM users WHERE username = ?', [$username])) return null;
    $roleId = (int)db_value('SELECT id FROM roles WHERE name = "employee"');
    if (!$roleId) return null;
    $password = employee_temp_password();
    $uid = db_insert('users', [
        'username' => $username, 'password' => $password, 'full_name' => $emp['name'],
        'email' => $emp['email'] ?? null, 'phone' => $emp['contact'] ?? null,
        'role_id' => $roleId, 'employee_id' => (int)$emp['id'],
        'must_change_password' => 1, 'is_active' => 1, 'created_by' => user_id(),
    ]);
    log_activity('CREATE', 'users', $uid, null, ['username' => $username, 'role' => 'employee'], 'Employee login created for ' . $emp['name'], (int)$emp['id']);
    return ['username' => $username, 'password' => $password];
}

/** Keep an employee's login in step with their record (name, CNIC, active). */
function employee_sync_login(int $empId): void
{
    $emp = db_row('SELECT id, name, cnic, email, contact, is_deleted FROM employees WHERE id = ?', [$empId]);
    if (!$emp) return;
    $u = db_row('SELECT u.id, u.username FROM users u JOIN roles r ON r.id = u.role_id
                  WHERE u.employee_id = ? AND r.name = "employee" AND u.is_deleted = 0', [$empId]);
    if (!$u) return;
    $upd = ['full_name' => $emp['name'], 'is_active' => (int)$emp['is_deleted'] ? 0 : 1];
    $wanted = employee_login_username((string)$emp['cnic']);
    if ($wanted !== $u['username'] && !db_value('SELECT id FROM users WHERE username = ? AND id <> ?', [$wanted, (int)$u['id']])) {
        $upd['username'] = $wanted;
    }
    db_update('users', $upd, 'id = ?', [(int)$u['id']]);
}

/* =====================================================================
 * EMPLOYEE CODE GENERATOR
 * Pattern tokens: {PREFIX} {DEPT} {YYYY} {YY} {MM} {SEQ:n}
 * Sequence scope (setting employee_code_seq_scope): global | department | year
 * ===================================================================*/
function employee_code_preview(string $deptCode, ?string $joiningDate = null): string
{
    return employee_generate_code($deptCode, $joiningDate, true);
}

function employee_generate_code(string $deptCode, ?string $joiningDate = null, bool $preview = false): string
{
    $pattern = (string)setting('employee_code_pattern', '{PREFIX}-{DEPT}-{YYYY}-{SEQ:4}');
    $prefix  = (string)setting('employee_code_prefix', 'EMP');
    $scope   = (string)setting('employee_code_seq_scope', 'global');
    $date    = $joiningDate ? date_create($joiningDate) : new DateTime();
    if (!$date) $date = new DateTime();
    $deptCode = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $deptCode) ?: 'GEN');

    $seqLen = 4;
    if (preg_match('/\{SEQ:(\d+)\}/', $pattern, $m)) $seqLen = max(1, (int)$m[1]);

    $tokens = [
        '{PREFIX}' => $prefix,
        '{DEPT}'   => $deptCode,
        '{YYYY}'   => $date->format('Y'),
        '{YY}'     => $date->format('y'),
        '{MM}'     => $date->format('m'),
    ];

    /* Build a regex from the pattern so we can read the SEQ of existing codes.
     * Scope decides which static parts must match (global = only PREFIX). */
    $regex = '';
    $literalParts = preg_split('/(\{[A-Z]+(?::\d+)?\})/', $pattern, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY);
    foreach ($literalParts as $part) {
        if (preg_match('/^\{SEQ(?::\d+)?\}$/', $part)) {
            $regex .= '(?P<seq>\d+)';
        } elseif ($part === '{DEPT}') {
            $regex .= $scope === 'department' ? preg_quote($deptCode, '/') : '[A-Z0-9]+';
        } elseif ($part === '{YYYY}') {
            $regex .= $scope === 'year' ? $tokens['{YYYY}'] : '\d{4}';
        } elseif ($part === '{YY}') {
            $regex .= $scope === 'year' ? $tokens['{YY}'] : '\d{2}';
        } elseif ($part === '{MM}') {
            $regex .= '\d{2}';
        } elseif ($part === '{PREFIX}') {
            $regex .= preg_quote($prefix, '/');
        } else {
            $regex .= preg_quote($part, '/');
        }
    }
    $regex = '/^' . $regex . '$/';

    $max = 0;
    foreach (db_all('SELECT employee_code FROM employees') as $r) {
        if (preg_match($regex, (string)$r['employee_code'], $m)) {
            $max = max($max, (int)$m['seq']);
        }
    }
    $next = $max + 1;

    for ($try = 0; $try < 50; $try++) {
        $code = str_replace(array_keys($tokens), array_values($tokens), $pattern);
        $code = preg_replace('/\{SEQ(?::\d+)?\}/', str_pad((string)$next, $seqLen, '0', STR_PAD_LEFT), $code);
        $code = strtoupper($code);
        if ($preview) return $code;
        $exists = db_value('SELECT id FROM employees WHERE employee_code = ?', [$code]);
        if (!$exists) return $code;
        $next++;
    }
    throw new RuntimeException('Unable to generate a unique employee code.');
}

/* =====================================================================
 * COMPUTED DATES
 * ===================================================================*/
function calc_retirement_date(string $dob, int $eolBreakDays = 0): string
{
    $age = (int)setting('retirement_age', DEFAULT_RETIREMENT_AGE);
    $d = date_create($dob)->modify("+$age years");
    if ($eolBreakDays > 0) $d->modify("+$eolBreakDays days");
    return $d->format('Y-m-d');
}
function calc_probation_end(string $joiningDate): string
{
    return date_add_months($joiningDate, (int)setting('probation_months', 12));
}
/** Recalculate retirement date & LPR flag from stored data (called after EOL changes). */
function employee_recalculate(int $id): void
{
    $e = db_row('SELECT dob, eol_break_days, status, retirement_date FROM employees WHERE id = ?', [$id]);
    if (!$e) return;
    $ret = calc_retirement_date($e['dob'], (int)$e['eol_break_days']);
    $lprFrom = date_create($ret)->modify('-' . (int)setting('lpr_months', 6) . ' months')->format('Y-m-d');
    $lpr = (today() >= $lprFrom && today() <= $ret && in_array($e['status'], ['Active', 'Probation', 'Suspended', 'On Deputation'], true)) ? 1 : 0;
    db_update('employees', [
        'retirement_date' => $ret,
        'has_break_in_service' => (int)$e['eol_break_days'] > 0 ? 1 : 0,
        'is_lpr_eligible' => $lpr,
    ], 'id = ?', [$id]);
}

/* =====================================================================
 * GUARDS
 * ===================================================================*/
/** Whether any service transaction may be recorded for this employee. */
function employee_can_transact(array $emp): array
{
    if ((int)$emp['is_deleted']) return [false, 'Employee record is deleted.'];
    if ((int)$emp['service_closed']) return [false, 'Service book is closed (' . $emp['status'] . '). No further transactions allowed.'];
    if (!in_array($emp['status'], SERVING_STATUSES, true)) {
        return [false, 'Employee is not in service (' . $emp['status'] . '). Use the Rejoining module first.'];
    }
    return [true, ''];
}
function employee_is_suspended(array $emp): bool
{
    return $emp['status'] === 'Suspended';
}
function employee_is_confirmed(array $emp): bool
{
    return !empty($emp['confirmation_date']);
}

/* =====================================================================
 * LISTING / FILTER HELPERS
 * ===================================================================*/
/** Build WHERE + params for the employee list from GET filters. */
function employee_filters(array $g): array
{
    [$scopeSql, $params] = emp_scope('e');
    $where = ["e.is_deleted = " . (is_super_admin() && ($g['deleted'] ?? '') === '1' ? '1' : '0'), $scopeSql];

    if (!empty($g['q'])) {
        $like = '%' . $g['q'] . '%';
        $digits   = preg_replace('/\D/', '', (string)$g['q']);
        $cnicLike = $digits !== '' ? '%' . $digits . '%' : '%__none__%';
        $where[] = '(e.name LIKE ? OR e.employee_code LIKE ? OR e.cnic LIKE ? OR REPLACE(e.cnic,"-","") LIKE ? OR e.father_husband_name LIKE ? OR e.contact LIKE ?)';
        array_push($params, $like, $like, $like, $cnicLike, $like, $like);
    }
    [$gw, $gp] = geo_filter_sql($g, 'e');
    if ($gw !== '1=1') { $where[] = $gw; $params = array_merge($params, $gp); }
    foreach (['designation_id', 'employment_type_id', 'pay_scale_id'] as $f) {
        if (!empty($g[$f])) { $where[] = "e.$f = ?"; $params[] = (int)$g[$f]; }
    }
    if (!empty($g['status'])) {
        if ($g['status'] === 'Retired') { $where[] = "e.status IN ('Retired','Medical Retired')"; }
        elseif ($g['status'] === 'Terminated') { $where[] = "e.status IN ('Terminated','Dismissed')"; }
        else { $where[] = 'e.status = ?'; $params[] = $g['status']; }
    }
    if (!empty($g['serving'])) $where[] = "e.status IN ('" . implode("','", SERVING_STATUSES) . "')";
    if (!empty($g['gender'])) { $where[] = 'e.gender = ?'; $params[] = $g['gender']; }
    if (($g['probation'] ?? '') === 'unconfirmed') $where[] = 'e.confirmation_date IS NULL AND e.status IN ("Probation","Active")';
    if (($g['probation'] ?? '') === 'confirmed')   $where[] = 'e.confirmation_date IS NOT NULL';
    if (($g['deputation'] ?? '') === '1') $where[] = "e.status = 'On Deputation'";
    if (($g['active'] ?? '') === '1') $where[] = 'e.is_active = 1';
    if (($g['active'] ?? '') === '0') $where[] = 'e.is_active = 0';
    if (!empty($g['lpr'])) $where[] = 'e.is_lpr_eligible = 1';
    if (!empty($g['break'])) $where[] = 'e.has_break_in_service = 1';

    return [implode(' AND ', $where), $params];
}

/* =====================================================================
 * DOCUMENT STORAGE (shared by profile + every service module)
 * ===================================================================*/
const DOC_ALLOWED_EXT = ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'doc', 'docx', 'xls', 'xlsx'];

/**
 * Validate + move an uploaded file ($_FILES[$field]) into uploads/documents/{emp}/
 * and register it in employee_documents. Returns the document id, or null when
 * no file was submitted. Throws / json_error on validation failure.
 */
function document_store(int $employeeId, string $field, string $docType, string $title = '', ?string $relatedTable = null, ?int $relatedId = null): ?int
{
    if (empty($_FILES[$field]['name'])) return null;
    $f = $_FILES[$field];
    $fail = function (string $msg) { if (is_ajax()) json_error($msg); throw new RuntimeException($msg); };
    if ($f['error'] !== UPLOAD_ERR_OK) $fail('File upload failed (error code ' . $f['error'] . ').');
    if ($f['size'] > MAX_UPLOAD_MB * 1024 * 1024) $fail('File must be ' . MAX_UPLOAD_MB . ' MB or smaller.');
    $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, DOC_ALLOWED_EXT, true)) $fail('File type .' . $ext . ' is not allowed. Allowed: ' . implode(', ', DOC_ALLOWED_EXT));
    $mime = mime_content_type($f['tmp_name']) ?: 'application/octet-stream';
    if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'], true) && !@getimagesize($f['tmp_name'])) $fail('The uploaded image is not valid.');
    if ($ext === 'pdf' && $mime !== 'application/pdf') $fail('The uploaded file is not a valid PDF.');

    $dir = UPLOAD_PATH . '/documents/' . $employeeId;
    if (!is_dir($dir) && !@mkdir($dir, 0755, true)) $fail('Cannot create the document folder. Check permissions on /uploads.');
    $name = date('Ymd') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) $fail('Could not save the file. Check permissions on /uploads.');

    $docType = in_array($docType, DOC_TYPES, true) ? $docType : 'Other';
    $id = db_insert('employee_documents', [
        'employee_id'   => $employeeId,
        'doc_type'      => $docType,
        'title'         => $title !== '' ? mb_substr($title, 0, 150) : pathinfo((string)$f['name'], PATHINFO_FILENAME),
        'file_path'     => $employeeId . '/' . $name,
        'original_name' => mb_substr((string)$f['name'], 0, 255),
        'mime_type'     => $mime,
        'file_size'     => (int)$f['size'],
        'related_table' => $relatedTable,
        'related_id'    => $relatedId,
        'uploaded_by'   => user_id(),
    ]);
    log_activity('CREATE', 'employee_documents', $id, null, ['title' => $title, 'doc_type' => $docType, 'file' => $f['name']], 'Document uploaded: ' . ($title ?: $f['name']), $employeeId);
    return $id;
}
