<?php
/**
 * Employee record actions (AJAX):
 *   toggle      - activate / inactivate (admin + super admin)
 *   delete      - soft delete to recycle bin (super admin)
 *   restore     - restore from recycle bin (super admin)
 *   hard_delete - permanent delete incl. all service history & files (super admin)
 */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_editor();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request.', 405);
csrf_verify();

$action = (string)post('action');
$id     = post_int('id', 0);
$emp    = $id ? employee_get($id, true) : null;
if (!$emp) json_error('Employee not found.', 404);
require_employee_access($emp, true);

if ($action === 'toggle') {
    if ($emp['is_deleted']) json_error('Record is in the recycle bin.');
    $new = $emp['is_active'] ? 0 : 1;
    db_update('employees', ['is_active' => $new, 'updated_by' => user_id()], 'id = ?', [$id]);
    log_activity('UPDATE', 'employees', $id, ['is_active' => $emp['is_active']], ['is_active' => $new], 'Employee ' . ($new ? 'activated' : 'inactivated') . ': ' . $emp['name'], $id);
    employee_sync_login($id);
    json_ok('Employee ' . ($new ? 'activated.' : 'marked inactive.'));
}

if (!is_super_admin()) json_error('Only the Super Admin can delete or restore employee records.', 403);

if ($action === 'delete') {
    db_update('employees', ['is_deleted' => 1, 'is_active' => 0, 'deleted_at' => date('Y-m-d H:i:s'), 'deleted_by' => user_id()], 'id = ?', [$id]);
    /* An officer account linked to this record just loses the link. The
       employee's own sign-in stays linked and is switched off below, so that
       restoring the record switches it back on. */
    db_exec('UPDATE users u JOIN roles r ON r.id = u.role_id SET u.employee_id = NULL WHERE u.employee_id = ? AND r.name <> "employee"', [$id]);
    log_activity('DELETE', 'employees', $id, ['is_deleted' => 0], ['is_deleted' => 1], 'Employee moved to recycle bin: ' . $emp['name'], $id);
    employee_sync_login($id);
    json_ok('Employee moved to the recycle bin.');
}
if ($action === 'restore') {
    db_update('employees', ['is_deleted' => 0, 'is_active' => 1, 'deleted_at' => null, 'deleted_by' => null], 'id = ?', [$id]);
    log_activity('RESTORE', 'employees', $id, ['is_deleted' => 1], ['is_deleted' => 0], 'Employee restored: ' . $emp['name'], $id);
    employee_sync_login($id);
    json_ok('Employee restored.');
}
if ($action === 'hard_delete') {
    if (!$emp['is_deleted']) json_error('Move the record to the recycle bin first.');
    db_begin();
    try {
        /* edited order wording belongs to the records about to be removed */
        foreach (['postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'inquiries'] as $t) {
            db_exec("DELETE od FROM order_documents od JOIN `$t` r ON r.id = od.record_id WHERE od.record_table = ? AND r.employee_id = ?", [$t, $id]);
        }
        foreach (['postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'inquiries', 'timeline_events', 'employee_family', 'employee_documents', 'notification_log'] as $t) {
            db_exec("DELETE FROM `$t` WHERE employee_id = ?", [$id]);
        }
        /* the employee's own sign-in goes with them; an officer account that
           was merely linked to this record just loses the link */
        db_exec('DELETE u FROM users u JOIN roles r ON r.id = u.role_id WHERE u.employee_id = ? AND r.name = "employee"', [$id]);
        db_exec('UPDATE users SET employee_id = NULL WHERE employee_id = ?', [$id]);
        db_exec('DELETE FROM employees WHERE id = ?', [$id]);
        db_commit();
    } catch (Throwable $e) {
        db_rollback();
        json_error('Delete failed: ' . $e->getMessage(), 500);
    }
    // remove files
    if ($emp['photo']) @unlink(UPLOAD_PATH . '/photos/' . basename($emp['photo']));
    $dir = UPLOAD_PATH . '/documents/' . $id;
    if (is_dir($dir)) { foreach (glob("$dir/*") as $f) @unlink($f); @rmdir($dir); }
    log_activity('HARD_DELETE', 'employees', $id, $emp, null, 'Employee permanently deleted: ' . $emp['name'] . ' (' . $emp['employee_code'] . ')', $id);
    json_ok('Employee and all related history permanently deleted.');
}
json_error('Unknown action.');
