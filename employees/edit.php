<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_form_helpers.php';
require_editor();

$id  = get_int('id', 0);
$emp = employee_load_or_fail($id, false, true);
$errors = [];
$v = $emp;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$d, $errors] = employee_validate($emp);
    $v = array_merge($emp, $_POST, $d);
    if (!$errors) {
        $d['updated_by'] = user_id();
        // recompute retirement date if DOB changed
        if ($d['dob'] !== $emp['dob']) $d['retirement_date'] = calc_retirement_date($d['dob'], (int)$emp['eol_break_days']);

        $newlyConfirmed = $d['confirmation_date'] && !$emp['confirmation_date'];
        if ($newlyConfirmed && $emp['status'] === 'Probation') $d['status'] = 'Active';
        // confirmation removed while Active & still within original probation flow -> back to Probation
        if (!$d['confirmation_date'] && $emp['confirmation_date'] && $emp['status'] === 'Active' && $d['probation_start']) $d['status'] = 'Probation';

        db_begin();
        try {
            $photo = employee_handle_photo($id, $errors, $emp['photo']);
            if ($photo) $d['photo'] = $photo;
            $d['department_id'] = (int)$emp['department_id'];    // changes only through a posting
            db_update('employees', $d, 'id = ?', [$id]);
            employee_sync_login($id);

            if ($newlyConfirmed) {
                add_timeline_event($id, 'Confirmation', $d['confirmation_date'], 'Service confirmed',
                    $d['confirmation_order_no'] ? 'Order No. ' . $d['confirmation_order_no'] : null, 'employees', $id);
            }
            if ((int)$d['department_id'] !== (int)$emp['department_id']) {
                // keep the current posting row consistent with the profile department
                db_exec('UPDATE postings SET department_id = ? WHERE employee_id = ? AND is_current = 1', [$d['department_id'], $id]);
            }
            employee_recalculate($id);
            db_commit();
            log_activity('UPDATE', 'employees', $id, $emp, array_merge($emp, $d), 'Employee profile updated: ' . $d['name'], $id);
            flash('success', 'Employee profile updated.');
            redirect('employees/view.php?id=' . $id);
        } catch (Throwable $e) {
            db_rollback();
            $errors[] = 'Could not save: ' . $e->getMessage();
        }
    }
}

$opts   = employee_form_options($emp);
$isEdit = true;
$page_title  = 'Edit Employee';
$active_menu = 'employees';
$breadcrumbs = ['Employees' => 'employees/index.php', $emp['name'] => 'employees/view.php?id=' . $id, 'Edit' => null];
require ROOT_PATH . '/includes/header.php';
if ($errors) {
    echo '<div class="alert alert-danger"><strong>Please fix the following:</strong><ul class="mb-0">';
    foreach ($errors as $er) echo '<li>' . e($er) . '</li>';
    echo '</ul></div>';
}
if (!in_array($emp['status'], SERVING_STATUSES, true)) {
    echo '<div class="alert alert-warning py-2"><i class="bi bi-info-circle me-1"></i>This employee is <strong>' . e($emp['status']) . '</strong>. Profile corrections are allowed; service transactions are not.</div>';
}
require __DIR__ . '/_form.php';
require ROOT_PATH . '/includes/footer.php';
