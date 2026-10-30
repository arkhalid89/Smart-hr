<?php
/**
 * Audit logging + timeline events + notification hooks.
 */
declare(strict_types=1);

/**
 * Write an audit log entry.
 * $old / $new are assoc arrays (before / after values) or null.
 */
function log_activity(
    string $action,
    ?string $table = null,
    ?int $recordId = null,
    ?array $old = null,
    ?array $new = null,
    ?string $description = null,
    ?int $employeeId = null
): void {
    // keep only changed keys for UPDATE to make the diff readable
    if ($action === 'UPDATE' && $old !== null && $new !== null) {
        $changedOld = [];
        $changedNew = [];
        foreach ($new as $k => $v) {
            $ov = $old[$k] ?? null;
            if ((string)$ov !== (string)$v) {
                $changedOld[$k] = $ov;
                $changedNew[$k] = $v;
            }
        }
        if (!$changedNew) return;   // nothing actually changed
        $old = $changedOld;
        $new = $changedNew;
    }
    // never store passwords in the log
    foreach (['password'] as $s) {
        if (isset($old[$s])) $old[$s] = '***';
        if (isset($new[$s])) $new[$s] = '***';
    }
    try {
        db_insert('audit_logs', [
            'user_id'     => user_id(),
            'username'    => current_user()['username'] ?? null,
            'action'      => $action,
            'table_name'  => $table,
            'record_id'   => $recordId,
            'employee_id' => $employeeId,
            'description' => $description ? mb_substr($description, 0, 255) : null,
            'old_values'  => json_safe($old),
            'new_values'  => json_safe($new),
            'ip_address'  => client_ip(),
            'user_agent'  => mb_substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
        ]);
    } catch (Throwable $e) {
        // logging must never break the main action
        if (APP_ENV === 'local') error_log('audit failed: ' . $e->getMessage());
    }
}

/** Append an event to the employee's visual timeline. */
function add_timeline_event(
    int $employeeId,
    string $type,
    string $date,
    string $title,
    ?string $description = null,
    ?string $refTable = null,
    ?int $refId = null
): int {
    return db_insert('timeline_events', [
        'employee_id'     => $employeeId,
        'event_type'      => $type,
        'event_date'      => $date,
        'title'           => mb_substr($title, 0, 150),
        'description'     => $description ? mb_substr($description, 0, 500) : null,
        'reference_table' => $refTable,
        'reference_id'    => $refId,
        'created_by'      => user_id(),
    ]);
}

/** In-app notification (bell icon). user_id NULL = broadcast. */
function notify(string $type, string $title, ?string $message = null, ?string $link = null, ?int $userId = null): void
{
    db_insert('notifications', [
        'user_id' => $userId, 'type' => $type, 'title' => mb_substr($title, 0, 150),
        'message' => $message ? mb_substr($message, 0, 500) : null, 'link' => $link,
    ]);
}
