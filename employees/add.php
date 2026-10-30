<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/_form_helpers.php';
require_once ROOT_PATH . '/includes/service_engine.php';
require_editor();

$errors = [];
$v = ['gender' => 'Male', 'joining_date' => today()];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    [$d, $errors] = employee_validate(null);
    $v = array_merge($_POST, $d);
    $initialLoc = post_int('posting_location_id');
    if ($d['department_id'] && !department_allowed((int)$d['department_id'])) {
        $errors[] = 'That department is outside your reach (' . jurisdiction_label() . ').';
    }
    if ($initialLoc && !location_in_department($initialLoc, (int)$d['department_id'])) {
        $errors[] = 'The posting office must belong to the department chosen.';
    }
    if (!$errors) {
        $dept = db_find('departments', (int)$d['department_id']);
        $et   = $d['employment_type_id'] ? db_find('employment_types', (int)$d['employment_type_id']) : null;
        $hasProbation = $et ? (int)$et['has_probation'] : 1;

        $d['status'] = $d['confirmation_date'] ? 'Active' : ($hasProbation && $d['probation_start'] ? 'Probation' : 'Active');
        if (!$hasProbation) { $d['probation_start'] = null; $d['probation_end'] = null; }
        $d['retirement_date'] = calc_retirement_date($d['dob']);
        $d['created_by'] = user_id();
        $d['is_active']  = 1;

        db_begin();
        try {
            $d['employee_code'] = employee_generate_code((string)$dept['code'], $d['joining_date']);
            $id = db_insert('employees', $d);

            // photo
            $photo = employee_handle_photo($id, $errors);
            if ($photo) db_update('employees', ['photo' => $photo], 'id = ?', [$id]);

            // initial posting (append-only history starts here)
            $postingId = svc_insert('postings', [
                'employee_id'   => $id,
                'office'        => nullable(post('posting_office')),
                'department_id' => $d['department_id'],
                'section'       => nullable(post('posting_section')),
                'location_id'   => post_int('posting_location_id'),
                'posting_type'  => 'Initial Posting',
                'effective_date'=> $d['joining_date'],
                'order_no'      => nullable(post('posting_order_no')),
                'order_date'    => $d['joining_date'],
                'reporting_officer' => nullable(post('posting_reporting_officer')),
                'remarks'       => 'Initial posting on joining',
                'is_current'    => 1,
                'created_by'    => user_id(),
            ]);
            db_update('employees', ['current_posting_id' => $postingId], 'id = ?', [$id]);

            // timeline
            add_timeline_event($id, 'Joining', $d['joining_date'],
                'Joined as ' . master_name('designations', (int)$d['designation_id']) . ($d['status'] === 'Probation' ? ' (Probation)' : ''),
                'Department: ' . $dept['name'] . ($d['pay_scale_id'] ? ' · Scale: ' . master_name('pay_scales', (int)$d['pay_scale_id']) : ''),
                'employees', $id);
            if ($d['confirmation_date']) {
                add_timeline_event($id, 'Confirmation', $d['confirmation_date'], 'Service confirmed', $d['confirmation_order_no'] ? 'Order No. ' . $d['confirmation_order_no'] : null, 'employees', $id);
            }
            add_timeline_event($id, 'Posting', $d['joining_date'], 'Initial posting: ' . ($d['department_id'] ? $dept['name'] : ''),
                trim((post('posting_location_id') ? master_name('posting_locations', post_int('posting_location_id')) : '') . (post('posting_order_no') ? ' · Order ' . post('posting_order_no') : '') . (post('posting_reporting_officer') ? ' · Reporting to ' . post('posting_reporting_officer') : ''), ' ·') ?: null, 'postings', $postingId);

            employee_recalculate($id);
            $login = employee_create_login(['id' => $id] + $d);
            db_commit();
            log_activity('CREATE', 'employees', $id, null, $d, 'Employee created: ' . $d['name'] . ' (' . $d['employee_code'] . ')', $id);
            flash('success', 'Employee ' . $d['name'] . ' created with code ' . $d['employee_code'] . '.');
            if ($login) {
                flash('info', 'Sign-in created for the employee — username ' . $login['username']
                    . ' (their CNIC), temporary password ' . $login['password']
                    . '. They will be asked to choose their own password at first sign-in. Pass these on now; the Super Admin can reset them later from Users & Roles.');
            }
            redirect('employees/view.php?id=' . $id);
        } catch (Throwable $e) {
            db_rollback();
            $errors[] = 'Could not save employee: ' . $e->getMessage();
        }
    }
}

$opts   = employee_form_options(null);
$isEdit = false;
$emp    = null;
$page_title  = 'New Employee';
$active_menu = 'employees';
$breadcrumbs = ['Employees' => 'employees/index.php', 'New Employee' => null];
require ROOT_PATH . '/includes/header.php';
if ($errors) {
    echo '<div class="alert alert-danger"><strong>Please fix the following:</strong><ul class="mb-0">';
    foreach ($errors as $er) echo '<li>' . e($er) . '</li>';
    echo '</ul></div>';
}
require __DIR__ . '/_form.php';
require ROOT_PATH . '/includes/footer.php';
