<?php
/**
 * Global helper functions: URLs, escaping, flash messages, CSRF,
 * input, dates, settings and application constants.
 */
declare(strict_types=1);

/* =====================================================================
 * APPLICATION CONSTANTS
 * ===================================================================*/
const EMP_STATUSES = [
    'Probation', 'Active', 'Suspended', 'On Deputation', 'On LPR', 'Contract Expired',
    'Resigned', 'Retired', 'Medical Retired', 'Terminated', 'Dismissed', 'Deceased (In Service)',
];
/** Statuses where employee is still on the rolls (service open). */
const SERVING_STATUSES = ['Probation', 'Active', 'Suspended', 'On Deputation', 'On LPR'];
/** Statuses that count as exits. */
const EXITED_STATUSES = ['Contract Expired', 'Resigned', 'Retired', 'Medical Retired', 'Terminated', 'Dismissed', 'Deceased (In Service)'];

const POSTING_TYPES = [
    'Initial Posting', 'Routine Posting', 'Transfer Posting', 'Promotion Posting',
    'Temporary Posting', 'Additional Charge', 'Deputation', 'Punishment Posting', 'Reversion',
];
const EXIT_TYPES = [
    'Resignation', 'Normal Retirement', 'Medical Invalidation', 'Termination', 'Dismissal', 'Death in Service', 'Contract Expired',
];
const REJOIN_TYPES = ['Renewal', 'Fresh', 'Retirement Contract', 'Resignation Rejoin', 'Appeal Reinstatement'];
const FAMILY_RELATIONS = ['Spouse', 'Son', 'Daughter', 'Father', 'Mother', 'Brother', 'Sister', 'Other'];
const DOC_TYPES = [
    'CNIC', 'Educational Certificate', 'Appointment Letter', 'Joining Report', 'Service Document',
    'Medical Board Report', 'Suspension Order', 'Reinstatement Order', 'Promotion Order',
    'Transfer Order', 'Leave Application', 'Photo', 'Other',
];

/** Bootstrap badge color per status. */
function status_badge(string $status): string
{
    $map = [
        'Probation' => 'info', 'Active' => 'success', 'Suspended' => 'danger',
        'On Deputation' => 'primary', 'On LPR' => 'warning', 'Contract Expired' => 'secondary',
        'Resigned' => 'secondary', 'Retired' => 'dark', 'Medical Retired' => 'dark',
        'Terminated' => 'danger', 'Dismissed' => 'danger', 'Deceased (In Service)' => 'dark',
    ];
    $c = $map[$status] ?? 'secondary';
    return '<span class="badge text-bg-' . $c . '">' . e($status) . '</span>';
}

/* =====================================================================
 * URL / OUTPUT
 * ===================================================================*/
function url(string $path = ''): string
{
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}
function asset(string $path): string
{
    $file = ROOT_PATH . '/assets/' . ltrim($path, '/');
    $v = APP_VERSION . (is_file($file) ? '.' . filemtime($file) : '');   // cache-busting on every file change
    return url('assets/' . ltrim($path, '/')) . '?v=' . $v;
}
function redirect(string $path, int $code = 302): never
{
    $target = preg_match('#^https?://#', $path) ? $path : url($path);
    header('Location: ' . $target, true, $code);
    exit;
}
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
function current_path(): string
{
    $uri  = strtok($_SERVER['REQUEST_URI'] ?? '/', '?');
    $base = parse_url(BASE_URL, PHP_URL_PATH) ?: '';
    if ($base && str_starts_with($uri, $base)) {
        $uri = substr($uri, strlen($base));
    }
    return '/' . ltrim($uri, '/');
}
function is_ajax(): bool
{
    return strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
}
function json_response(array $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}
function json_ok(string $message = 'OK', array $extra = []): never
{
    json_response(array_merge(['success' => true, 'message' => $message], $extra));
}
function json_error(string $message, int $code = 400, array $extra = []): never
{
    json_response(array_merge(['success' => false, 'message' => $message], $extra), $code);
}

/* =====================================================================
 * FLASH MESSAGES
 * ===================================================================*/
function flash(string $type, string $message): void
{
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}
function flash_render(): string
{
    if (empty($_SESSION['_flash'])) return '';
    $html = '';
    foreach ($_SESSION['_flash'] as $f) {
        $html .= '<div class="alert alert-' . e($f['type']) . ' alert-dismissible fade show py-2" role="alert">'
            . e($f['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>';
    }
    unset($_SESSION['_flash']);
    return $html;
}

/* =====================================================================
 * CSRF
 * ===================================================================*/
function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(24));
    }
    return $_SESSION['_csrf'];
}
function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}
function csrf_verify(): void
{
    $token = $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (!$token || !hash_equals(csrf_token(), (string)$token)) {
        if (is_ajax()) json_error('Invalid or expired security token. Please reload the page.', 403);
        http_response_code(403);
        die('Invalid or expired security token. Please go back and try again.');
    }
}

/* =====================================================================
 * INPUT
 * ===================================================================*/
function post(string $key, mixed $default = null): mixed
{
    if (!isset($_POST[$key])) return $default;
    $v = $_POST[$key];
    return is_string($v) ? trim($v) : $v;
}
function get(string $key, mixed $default = null): mixed
{
    if (!isset($_GET[$key])) return $default;
    $v = $_GET[$key];
    return is_string($v) ? trim($v) : $v;
}
function post_int(string $key, ?int $default = null): ?int
{
    $v = post($key);
    return ($v === null || $v === '') ? $default : (int)$v;
}
function get_int(string $key, ?int $default = null): ?int
{
    $v = get($key);
    return ($v === null || $v === '') ? $default : (int)$v;
}
/** Empty string -> null (for optional DB columns). */
function nullable(mixed $v): mixed
{
    return ($v === '' || $v === null) ? null : $v;
}
function post_date(string $key): ?string
{
    $v = post($key);
    if (!$v) return null;
    $d = date_create($v);
    return $d ? $d->format('Y-m-d') : null;
}
function post_decimal(string $key): ?float
{
    $v = post($key);
    if ($v === null || $v === '') return null;
    return (float)str_replace(',', '', (string)$v);
}

/* =====================================================================
 * DATES
 * ===================================================================*/
function fmt_date(?string $date, string $format = 'd M Y'): string
{
    if (!$date || $date === '0000-00-00') return '-';
    $d = date_create($date);
    return $d ? $d->format($format) : '-';
}
function fmt_datetime(?string $dt): string
{
    return fmt_date($dt, 'd M Y, h:i A');
}
function calc_age(?string $dob, ?string $asOf = null): ?int
{
    if (!$dob) return null;
    $d = date_create($dob);
    if (!$d) return null;
    $ref = $asOf ? date_create($asOf) : new DateTime();
    return (int)$d->diff($ref)->y;
}
function days_between(string $from, string $to): int
{
    $a = date_create($from);
    $b = date_create($to);
    if (!$a || !$b) return 0;
    return (int)$a->diff($b)->days + 1;   // inclusive
}
function date_add_months(string $date, int $months): string
{
    return date_create($date)->modify("+$months months")->format('Y-m-d');
}
function date_add_days(string $date, int $days): string
{
    return date_create($date)->modify(($days >= 0 ? '+' : '') . "$days days")->format('Y-m-d');
}
function today(): string
{
    return date('Y-m-d');
}
function fmt_money(mixed $v): string
{
    if ($v === null || $v === '') return '-';
    return number_format((float)$v, 0);
}

/* =====================================================================
 * SETTINGS
 * ===================================================================*/
function setting(string $key, mixed $default = null): mixed
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db_all('SELECT setting_key, setting_value FROM settings') as $r) {
            $cache[$r['setting_key']] = $r['setting_value'];
        }
    }
    return array_key_exists($key, $cache) && $cache[$key] !== null && $cache[$key] !== '' ? $cache[$key] : $default;
}
function setting_set(string $key, string $value, ?int $userId = null): void
{
    db_exec(
        'INSERT INTO settings (setting_key, setting_value, updated_by) VALUES (?,?,?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by)',
        [$key, $value, $userId]
    );
}

/* =====================================================================
 * MASTER DATA HELPERS
 * ===================================================================*/
/** Active master rows for dropdowns; always includes the currently selected id even if inactive. */
function master_options(string $table, ?int $selectedId = null, string $orderBy = 'sort_order, name'): array
{
    $table   = db_ident($table);
    $orderBy = preg_replace('/[^a-zA-Z0-9_, ]/', '', $orderBy);
    if ($selectedId) {
        return db_all("SELECT * FROM `$table` WHERE is_deleted = 0 AND (is_active = 1 OR id = ?) ORDER BY $orderBy", [$selectedId]);
    }
    return db_all("SELECT * FROM `$table` WHERE is_deleted = 0 AND is_active = 1 ORDER BY $orderBy");
}
function master_name(string $table, ?int $id): string
{
    if (!$id) return '-';
    $table = db_ident($table);
    return (string)db_value("SELECT name FROM `$table` WHERE id = ?", [$id], '-');
}
/** Render <option> list. */
function options_html(array $rows, mixed $selected = null, string $valueKey = 'id', string $labelKey = 'name'): string
{
    $html = '';
    foreach ($rows as $r) {
        $v = is_array($r) ? $r[$valueKey] : $r;
        $l = is_array($r) ? $r[$labelKey] : $r;
        $sel = ((string)$v === (string)$selected) ? ' selected' : '';
        $html .= '<option value="' . e($v) . '"' . $sel . '>' . e($l) . '</option>';
    }
    return $html;
}

/* =====================================================================
 * MISC
 * ===================================================================*/
function client_ip(): string
{
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) {
            return trim(explode(',', $_SERVER[$k])[0]);
        }
    }
    return '0.0.0.0';
}
function valid_cnic(string $cnic): bool
{
    return (bool)preg_match('/^\d{5}-\d{7}-\d$/', $cnic);
}
function normalize_cnic(string $cnic): string
{
    $d = preg_replace('/\D/', '', $cnic);
    if (strlen($d) === 13) {
        return substr($d, 0, 5) . '-' . substr($d, 5, 7) . '-' . substr($d, 12, 1);
    }
    return $cnic;
}
/** Employee photo URL (served through PHP because /uploads is protected). */
function photo_url(?string $photo, int $employeeId = 0): string
{
    if ($photo) return url('employees/photo.php?id=' . $employeeId);
    return asset('img/avatar.svg');
}
/** Convert an array of rows to a JSON-safe version (for audit logs). */
function json_safe(?array $data): ?string
{
    if ($data === null) return null;
    return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PARTIAL_OUTPUT_ON_ERROR);
}
