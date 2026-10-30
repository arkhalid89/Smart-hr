<?php
/**
 * Authentication, session and role-based access control.
 *
 * The administration is a chain: District -> Tehsil -> Department. Every
 * department sits in a district (and in a tehsil unless it is at district
 * headquarters) and carries an authority level:
 *
 *   district    the whole district        (e.g. Deputy Commissioner Office)
 *   tehsil      the whole tehsil          (e.g. Assistant Commissioner Office)
 *   department  only itself               (a single authority)
 *
 * Roles:
 *   super_admin    everything, including system settings
 *   officer        belongs to a department; reaches what that department's
 *                  level reaches. District- and tehsil-level officers publish
 *                  service records; every officer is a signing authority.
 *   report_viewer  belongs to a department like an officer and reaches the same
 *                  (district / tehsil / department), but only reads reports
 *   employee       their own profile, postings, posting orders, notifications
 *
 * An employee belongs to the department of their current posting, and through
 * it to a tehsil and district — nothing about place is stored twice.
 */
declare(strict_types=1);

function session_boot(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => (parse_url(BASE_URL, PHP_URL_PATH) ?: '/'),
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => str_starts_with(BASE_URL, 'https://'),
    ]);
    session_start();

    // idle timeout
    if (!empty($_SESSION['user']) && !empty($_SESSION['_last_activity'])
        && (time() - (int)$_SESSION['_last_activity']) > SESSION_LIFETIME) {
        session_unset();
        session_destroy();
        session_start();
        flash('warning', 'Your session expired. Please sign in again.');
    }
    $_SESSION['_last_activity'] = time();
}

/** Currently logged in user (array) or null. */
function current_user(): ?array
{
    return $_SESSION['user'] ?? null;
}
function user_id(): ?int
{
    return isset($_SESSION['user']['id']) ? (int)$_SESSION['user']['id'] : null;
}
function user_role(): string
{
    return $_SESSION['user']['role'] ?? 'guest';
}
function is_logged_in(): bool
{
    return !empty($_SESSION['user']);
}
function is_super_admin(): bool
{
    return user_role() === 'super_admin';
}
/** Belongs to a department and acts with that department's authority. */
function is_officer(): bool
{
    return user_role() === 'officer';
}
function is_report_viewer(): bool
{
    return user_role() === 'report_viewer';
}
/** An employee signed in to see their own record. */
function is_employee_user(): bool
{
    return user_role() === 'employee';
}
/** Only the Super Admin is not limited to a place. */
function has_full_scope(): bool
{
    return is_super_admin();
}
/** Roles whose reach is the authority level of their department. */
function has_department_scope(): bool
{
    return is_officer() || is_report_viewer();
}

/**
 * The signed-in officer's or report viewer's department with its level and
 * place, read fresh on each request so a change of level or tehsil takes
 * effect immediately.
 */
function user_department(): ?array
{
    static $cache = [];
    $id = (int)($_SESSION['user']['department_id'] ?? 0);
    if (!$id || !has_department_scope()) return null;
    if (!array_key_exists($id, $cache)) {
        $cache[$id] = db_row(
            'SELECT d.id, d.name, d.code, d.level, d.district_id, d.tehsil_id, d.head_designation,
                    ds.name AS district, t.name AS tehsil
               FROM departments d
               JOIN districts ds ON ds.id = d.district_id
               LEFT JOIN tehsils t ON t.id = d.tehsil_id
              WHERE d.id = ? AND d.is_deleted = 0', [$id]) ?: null;
    }
    return $cache[$id];
}
/** 'district' | 'tehsil' | 'department', or null for anyone without a department. */
function user_level(): ?string
{
    return user_department()['level'] ?? null;
}
/** Every officer with a department signs what their office issues. */
function is_signing_authority(): bool
{
    return is_officer() && user_department() !== null;
}
/**
 * Publishing service records, and registering or editing employees, happens
 * at district or tehsil level only.
 */
function can_publish(): bool
{
    return is_super_admin() || (is_officer() && in_array(user_level(), ['district', 'tehsil'], true));
}
function can_edit(): bool
{
    return can_publish();
}
/** The employee record the signed-in user is linked to, if any. */
function user_employee_id(): ?int
{
    $v = $_SESSION['user']['employee_id'] ?? null;
    return $v ? (int)$v : null;
}

/**
 * SQL restricting a departments query to what the user reaches.
 * $alias '' means unqualified columns. Returns [sqlFragment, params].
 */
function department_scope(string $alias = 'd'): array
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias);
    $p = $a === '' ? '' : $a . '.';
    if (has_full_scope()) return ['1=1', []];
    $dep = user_department();
    if (!$dep) return ['1=0', []];
    return match ($dep['level']) {
        'district' => ["{$p}district_id = ?", [(int)$dep['district_id']]],
        'tehsil'   => ["{$p}tehsil_id = ?",   [(int)$dep['tehsil_id']]],
        default    => ["{$p}id = ?",          [(int)$dep['id']]],
    };
}

/**
 * SQL restricting an employees query to the user's reach.
 * Usage: [$w, $p] = emp_scope('e');
 */
function emp_scope(string $alias = 'e'): array
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'e';
    if (has_full_scope()) return ['1=1', []];
    if (is_employee_user()) return ($id = user_employee_id()) ? ["$a.id = ?", [$id]] : ['1=0', []];
    [$w, $p] = department_scope('scope_d');
    if ($w === '1=0') return ['1=0', []];
    return ["$a.department_id IN (SELECT scope_d.id FROM departments scope_d WHERE $w)", $p];
}

/**
 * Kept for existing call sites. "<alias>.department_id" filters employees;
 * any other column is a departments list ('id', 'd.id').
 */
function dept_scope(string $column): array
{
    if (preg_match('/^([A-Za-z_][A-Za-z0-9_]*)\.department_id$/', $column, $m)) return emp_scope($m[1]);
    if (preg_match('/^(?:([A-Za-z_][A-Za-z0-9_]*)\.)?id$/', $column, $m)) return department_scope($m[1] ?? '');
    return ['1=1', []];
}

/** Offices (posting_locations) belonging to departments the user reaches. */
function location_scope(string $alias = 'l'): array
{
    $a = preg_replace('/[^A-Za-z0-9_]/', '', $alias) ?: 'l';
    if (has_full_scope()) return ['1=1', []];
    [$w, $p] = department_scope('ls_d');
    if ($w === '1=0') return ['1=0', []];
    return ["$a.department_id IN (SELECT ls_d.id FROM departments ls_d WHERE $w)", $p];
}

/** An employee's department, tehsil and district: [department_id, tehsil_id, district_id]. */
function employee_place(array $emp): array
{
    if (array_key_exists('dept_district_id', $emp)) {
        return [(int)$emp['department_id'], $emp['dept_tehsil_id'] !== null ? (int)$emp['dept_tehsil_id'] : null, (int)$emp['dept_district_id']];
    }
    $row = isset($emp['department_id'])
        ? db_row('SELECT id AS department_id, tehsil_id, district_id FROM departments WHERE id = ?', [(int)$emp['department_id']])
        : db_row('SELECT d.id AS department_id, d.tehsil_id, d.district_id FROM employees e JOIN departments d ON d.id = e.department_id WHERE e.id = ?', [(int)($emp['id'] ?? 0)]);
    if (!$row) return [0, null, 0];
    return [(int)$row['department_id'], $row['tehsil_id'] !== null ? (int)$row['tehsil_id'] : null, (int)$row['district_id']];
}

/** Whether the employee falls inside the signed-in user's reach. */
function in_jurisdiction(array $emp): bool
{
    if (has_full_scope()) return true;
    if (is_employee_user()) return user_employee_id() !== null && (int)$emp['id'] === user_employee_id();
    $dep = user_department();
    if (!$dep) return false;
    [$deptId, $tehsilId, $districtId] = employee_place($emp);
    return match ($dep['level']) {
        'district' => $districtId === (int)$dep['district_id'],
        'tehsil'   => $tehsilId !== null && $tehsilId === (int)$dep['tehsil_id'],
        default    => $deptId === (int)$dep['id'],
    };
}

/** May see this employee: within reach, or it is the user's own record. */
function can_view_employee(array $emp): bool
{
    if (in_jurisdiction($emp)) return true;
    return user_employee_id() !== null && (int)$emp['id'] === user_employee_id();
}

/** May change this employee's record: a publishing role, within reach. */
function can_manage_employee(array $emp): bool
{
    return can_publish() && in_jurisdiction($emp);
}

/** Whether the user may place someone in this department (post, register). */
function department_allowed(?int $departmentId): bool
{
    if (!$departmentId || !can_publish()) return false;
    [$w, $p] = department_scope('d');
    return (bool)db_value("SELECT d.id FROM departments d WHERE d.id = ? AND d.is_deleted = 0 AND $w", array_merge([$departmentId], $p));
}

/**
 * Whether a system alert is for this user. Alerts about an employee carry a
 * link to their profile; those outside the user's reach are hidden.
 */
function notification_visible(array $n): bool
{
    if (has_full_scope()) return true;
    if (!preg_match('#employees/view\.php\?id=(\d+)#', (string)($n['link'] ?? ''), $m)) return true;
    return can_view_employee(['id' => (int)$m[1]]);
}

/** Human description of the user's reach, e.g. "Tehsil Shahkot". */
function jurisdiction_label(): string
{
    if (has_full_scope()) return 'All districts';
    if (is_employee_user()) return 'Own record';
    $dep = user_department();
    if (!$dep) return 'No department assigned';
    return match ($dep['level']) {
        'district' => 'District ' . $dep['district'],
        'tehsil'   => 'Tehsil ' . $dep['tehsil'] . ', District ' . $dep['district'],
        default    => $dep['name'],
    };
}

/** "District level", "Tehsil level" or "Department level". */
function level_label(?string $level): string
{
    return match ($level) {
        'district' => 'District level',
        'tehsil'   => 'Tehsil level',
        'department' => 'Department level',
        default    => '',
    };
}

/* ---------------------------------------------------------------------
 * Guards
 * -------------------------------------------------------------------*/
function require_login(): void
{
    if (!is_logged_in()) {
        if (is_ajax()) json_error('Please sign in.', 401);
        $_SESSION['_redirect_after_login'] = current_path();
        redirect('auth/login.php');
    }
    if (must_change_password() && !defined('ALLOW_PASSWORD_CHANGE_PAGE')) {
        if (is_ajax()) json_error('Please set your own password first.', 403);
        flash('warning', 'Please choose your own password before continuing.');
        redirect('auth/change_password.php');
    }
}
/** Restrict page to one or more roles. */
function require_role(string ...$roles): void
{
    require_login();
    if (!in_array(user_role(), $roles, true)) {
        if (is_ajax()) json_error('You do not have permission to perform this action.', 403);
        http_response_code(403);
        $page_title = 'Access Denied';
        require ROOT_PATH . '/includes/header.php';
        echo '<div class="alert alert-danger"><h5 class="mb-1"><i class="bi bi-shield-lock"></i> Access Denied</h5>'
           . 'You do not have permission to view this page.</div>';
        require ROOT_PATH . '/includes/footer.php';
        exit;
    }
}
function require_super_admin(): void { require_role('super_admin'); }
/** Officers of any level and the Super Admin. */
function require_officer(): void     { require_role('super_admin', 'officer'); }
/** Pages for officers and the reporting user — never for an employee login. */
function require_staff(): void       { require_role('super_admin', 'officer', 'report_viewer'); }
/** Publishing service records and editing employees: district or tehsil level. */
function require_editor(): void
{
    require_role('super_admin', 'officer');
    if (can_publish()) return;
    $msg = 'Service records are published by district and tehsil offices. Your department (' . jurisdiction_label() . ') has view access only.';
    if (is_ajax()) json_error($msg, 403);
    http_response_code(403);
    $page_title = 'Access Denied';
    require ROOT_PATH . '/includes/header.php';
    echo '<div class="alert alert-warning"><h5 class="mb-1"><i class="bi bi-shield-lock"></i> View access only</h5>' . e($msg) . '</div>';
    require ROOT_PATH . '/includes/footer.php';
    exit;
}

/**
 * Abort unless the user may see the employee ($manage = false) or change their
 * record ($manage = true).
 */
function require_employee_access(array $emp, bool $manage = false): void
{
    $ok = $manage ? can_manage_employee($emp) : can_view_employee($emp);
    if ($ok) return;
    $msg = $manage
        ? 'This employee is outside your jurisdiction (' . jurisdiction_label() . '), so the record cannot be changed.'
        : 'This employee is outside your jurisdiction (' . jurisdiction_label() . ').';
    if (is_ajax()) json_error($msg, 403);
    flash('danger', $msg);
    redirect(is_employee_user() ? 'employees/view.php?id=' . (int)user_employee_id() : 'dashboard/index.php');
}

/* ---------------------------------------------------------------------
 * Login / logout
 * -------------------------------------------------------------------*/
/**
 * Password check. Plain text at present (per requirement); switch this one
 * function to password_verify() when hashing is enabled later.
 */
function verify_password(string $input, string $stored): bool
{
    return hash_equals($stored, $input);
}

function attempt_login(string $username, string $password): array
{
    /* Employees sign in with their CNIC; accept it with or without dashes. */
    $username = trim($username);
    if (preg_match('/^\d{5}-\d{7}-\d$/', $username)) $username = str_replace('-', '', $username);

    $u = db_row(
        'SELECT u.*, r.name AS role, r.label AS role_label
           FROM users u JOIN roles r ON r.id = u.role_id
          WHERE u.username = ? AND u.is_deleted = 0 LIMIT 1',
        [$username]
    );
    if (!$u || !verify_password($password, $u['password'])) {
        return [false, 'Invalid username or password.'];
    }
    if (!(int)$u['is_active']) {
        return [false, 'Your account is inactive. Contact the Super Admin.'];
    }
    if ($u['role'] === 'employee') {
        $emp = $u['employee_id'] ? db_row('SELECT is_deleted FROM employees WHERE id = ?', [(int)$u['employee_id']]) : null;
        if (!$emp || (int)$emp['is_deleted']) return [false, 'Your employee record is not active. Contact your office.'];
    }
    session_regenerate_id(true);
    $_SESSION['user'] = session_user_payload($u);
    db_exec('UPDATE users SET last_login_at = NOW(), last_login_ip = ? WHERE id = ?', [client_ip(), (int)$u['id']]);
    log_activity('LOGIN', 'users', (int)$u['id'], null, null, 'User signed in');
    return [true, 'Welcome back, ' . $u['full_name']];
}

function logout_user(): void
{
    if (is_logged_in()) {
        log_activity('LOGOUT', 'users', user_id(), null, null, 'User signed out');
    }
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

/** What is kept in the session for a signed-in user. */
function session_user_payload(array $u): array
{
    return [
        'id'            => (int)$u['id'],
        'username'      => $u['username'],
        'full_name'     => $u['full_name'],
        'email'         => $u['email'],
        'role'          => $u['role'],
        'role_label'    => $u['role_label'],
        'employee_id'   => $u['employee_id'] ? (int)$u['employee_id'] : null,
        'department_id' => !empty($u['department_id']) ? (int)$u['department_id'] : null,
        'must_change_password' => (int)($u['must_change_password'] ?? 0),
    ];
}

/** Refresh the session copy of the user (after a profile or role change). */
function refresh_session_user(): void
{
    if (!is_logged_in()) return;
    $u = db_row('SELECT u.*, r.name AS role, r.label AS role_label FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ?', [user_id()]);
    if (!$u) return;
    $_SESSION['user'] = session_user_payload($u);
}

/** Force a password change before anything else (first sign-in of an employee). */
function must_change_password(): bool
{
    return !empty($_SESSION['user']['must_change_password']);
}
