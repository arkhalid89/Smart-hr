<?php
/**
 * Daily automation. Runs in two ways:
 *   1. cPanel cron:   php /home/USER/public_html/hr/cron/daily.php
 *      or:           curl -s https://example.com/hr/cron/daily.php?key=CRON_KEY
 *   2. Login-time fallback (auth/login.php) - runs at most once per day.
 *
 * Tasks: contract expiry -> status "Contract Expired" (+ exit record),
 *        LPR eligibility flags, deputation reversion alerts, probation
 *        completion alerts, upcoming retirement alerts, contract expiry alerts.
 */
declare(strict_types=1);

if (!defined('ROOT_PATH')) {
    require_once __DIR__ . '/../includes/bootstrap.php';
    $direct = true;
}
require_once ROOT_PATH . '/includes/service_engine.php';

function run_daily_checks(bool $force = false): array
{
    $today = today();
    if (!$force && setting('last_daily_check') === $today) return ['skipped' => true];
    $summary = ['contract_expired' => 0, 'lpr_flagged' => 0, 'alerts' => 0];

    /* 1. Contract expiry */
    $expired = db_all("SELECT * FROM employees WHERE is_deleted = 0 AND status IN ('Probation','Active','On LPR') AND contract_to IS NOT NULL AND contract_to < ?", [$today]);
    foreach ($expired as $e) {
        db_begin();
        try {
            $emp = employee_get((int)$e['id']);
            svc_exit($emp, ['exit_type' => 'Contract Expired', 'exit_date' => $e['contract_to'], 'remarks' => 'Auto: contract ended on ' . fmt_date($e['contract_to'])], true);
            db_commit();
            $summary['contract_expired']++;
            notify('contract', 'Contract expired', $e['name'] . ' (' . $e['employee_code'] . ') — status set to Contract Expired.', 'employees/view.php?id=' . $e['id']);
        } catch (Throwable $ex) { db_rollback(); }
    }

    /* 2. LPR eligibility flags */
    $lprMonths = (int)setting('lpr_months', 6);
    $rows = db_all("SELECT id, name, employee_code, retirement_date, is_lpr_eligible FROM employees WHERE is_deleted = 0 AND status IN ('Active','Probation','Suspended','On Deputation') AND retirement_date IS NOT NULL AND retirement_date >= ? AND retirement_date <= DATE_ADD(?, INTERVAL ? MONTH)", [$today, $today, $lprMonths]);
    foreach ($rows as $r) {
        if (!(int)$r['is_lpr_eligible']) {
            db_update('employees', ['is_lpr_eligible' => 1], 'id = ?', [(int)$r['id']]);
            notify('lpr', 'LPR eligible', $r['name'] . ' (' . $r['employee_code'] . ') retires on ' . fmt_date($r['retirement_date']) . ' — eligible for Leave Preparatory to Retirement.', 'employees/view.php?id=' . $r['id']);
            $summary['lpr_flagged']++;
        }
    }
    db_exec("UPDATE employees SET is_lpr_eligible = 0 WHERE is_lpr_eligible = 1 AND (status NOT IN ('Active','Probation','Suspended','On Deputation','On LPR') OR retirement_date < ? OR retirement_date > DATE_ADD(?, INTERVAL ? MONTH))", [$today, $today, $lprMonths]);

    /* 3. Deputation reversion due */
    $depDays = (int)setting('deputation_alert_days', 30);
    foreach (db_all("SELECT d.id, d.expected_reversion_date, e.id AS emp_id, e.name, e.employee_code, o.name AS org FROM deputations d JOIN employees e ON e.id = d.employee_id JOIN deputation_orgs o ON o.id = d.deputation_org_id
        WHERE d.status = 'Active' AND d.is_voided = 0 AND e.is_deleted = 0 AND d.expected_reversion_date IS NOT NULL AND d.expected_reversion_date <= DATE_ADD(?, INTERVAL ? DAY)", [$today, $depDays]) as $r) {
        if (!db_value('SELECT id FROM notifications WHERE type = "deputation" AND link = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)', ['employees/view.php?id=' . $r['emp_id']])) {
            notify('deputation', 'Deputation reversion due', $r['name'] . ' — expected back from ' . $r['org'] . ' on ' . fmt_date($r['expected_reversion_date']) . '.', 'employees/view.php?id=' . $r['emp_id']);
            $summary['alerts']++;
        }
    }

    /* 4. Probation completion */
    foreach (db_all("SELECT id, name, employee_code, probation_end FROM employees WHERE is_deleted = 0 AND status = 'Probation' AND confirmation_date IS NULL AND probation_end IS NOT NULL AND probation_end <= ?", [$today]) as $r) {
        if (!db_value('SELECT id FROM notifications WHERE type = "probation" AND link = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', ['employees/view.php?id=' . $r['id']])) {
            notify('probation', 'Probation completed', $r['name'] . ' (' . $r['employee_code'] . ') completed probation on ' . fmt_date($r['probation_end']) . ' — confirmation pending.', 'employees/view.php?id=' . $r['id']);
            $summary['alerts']++;
        }
    }

    /* 5. Upcoming retirements & contract expiries */
    $retMonths = (int)setting('retirement_alert_months', 12);
    foreach (db_all("SELECT id, name, employee_code, retirement_date FROM employees WHERE is_deleted = 0 AND status IN ('Active','Probation','Suspended','On Deputation','On LPR') AND retirement_date BETWEEN ? AND DATE_ADD(?, INTERVAL ? MONTH)", [$today, $today, $retMonths]) as $r) {
        if (!db_value('SELECT id FROM notifications WHERE type = "retirement" AND link = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)', ['employees/view.php?id=' . $r['id']])) {
            notify('retirement', 'Upcoming retirement', $r['name'] . ' (' . $r['employee_code'] . ') retires on ' . fmt_date($r['retirement_date']) . '.', 'employees/view.php?id=' . $r['id']);
            $summary['alerts']++;
        }
    }
    $cDays = (int)setting('contract_expiry_alert_days', 60);
    foreach (db_all("SELECT id, name, employee_code, contract_to FROM employees WHERE is_deleted = 0 AND status IN ('Active','Probation') AND contract_to BETWEEN ? AND DATE_ADD(?, INTERVAL ? DAY)", [$today, $today, $cDays]) as $r) {
        if (!db_value('SELECT id FROM notifications WHERE type = "contract_due" AND link = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)', ['employees/view.php?id=' . $r['id']])) {
            notify('contract_due', 'Contract expiring soon', $r['name'] . ' (' . $r['employee_code'] . ') — contract ends ' . fmt_date($r['contract_to']) . '.', 'employees/view.php?id=' . $r['id']);
            $summary['alerts']++;
        }
    }

    setting_set('last_daily_check', $today);
    log_activity('CRON', 'system', null, null, $summary, 'Daily checks executed');
    return $summary;
}

/* ---- direct execution (CLI or URL with key) ---- */
if (!empty($direct)) {
    $isCli = PHP_SAPI === 'cli';
    $key   = (string)setting('cron_key', '');
    if (!$isCli && (!$key || (get('key') ?? '') !== $key)) {
        http_response_code(403);
        die('Forbidden. Set a cron_key in System Settings and call ?key=...');
    }
    $result = run_daily_checks(true);
    header('Content-Type: text/plain');
    echo 'Daily checks completed ' . date('Y-m-d H:i:s') . "\n" . json_encode($result) . "\n";
}
