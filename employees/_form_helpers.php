<?php
/**
 * Shared validation + photo handling for employee add / edit.
 */
declare(strict_types=1);

/**
 * Validate POSTed employee data.
 * @return array [data, errors]
 */
function employee_validate(?array $existing = null): array
{
    $errors = [];
    $d = [
        'cnic'                => normalize_cnic((string)post('cnic', '')),
        'name'                => (string)post('name', ''),
        'father_husband_name' => (string)post('father_husband_name', ''),
        'gender'              => in_array(post('gender'), ['Male', 'Female', 'Other'], true) ? post('gender') : 'Male',
        'dob'                 => post_date('dob'),
        'marital_status_id'   => post_int('marital_status_id'),
        'blood_group_id'      => post_int('blood_group_id'),
        'contact'             => nullable(post('contact')),
        'whatsapp_no'         => nullable(post('whatsapp_no')),
        'email'               => nullable(post('email')),
        'address'             => nullable(post('address')),
        'department_id'       => post_int('department_id'),
        'designation_id'      => post_int('designation_id'),
        'pay_scale_id'        => post_int('pay_scale_id'),
        'basic_salary'        => post_decimal('basic_salary'),
        'joining_date'        => post_date('joining_date'),
        'employment_type_id'  => post_int('employment_type_id'),
        'contract_from'       => post_date('contract_from'),
        'contract_to'         => post_date('contract_to'),
        'probation_start'     => post_date('probation_start'),
        'probation_end'       => post_date('probation_end'),
        'confirmation_date'   => post_date('confirmation_date'),
        'confirmation_order_no' => nullable(post('confirmation_order_no')),
        'service_book_no'     => nullable(post('service_book_no')),
        'gp_fund_no'          => nullable(post('gp_fund_no')),
        'pension_no'          => nullable(post('pension_no')),
        'seniority_no'        => nullable(post('seniority_no')),
        'remarks'             => nullable(post('remarks')),
    ];

    if ($d['name'] === '')                 $errors[] = 'Employee name is required.';
    if ($d['father_husband_name'] === '')  $errors[] = 'Father / Husband name is required.';
    if (!valid_cnic($d['cnic']))           $errors[] = 'CNIC must be in the format 35202-1234567-1.';
    if (!$d['dob'])                        $errors[] = 'Date of birth is required.';
    elseif ($d['dob'] > today())           $errors[] = 'Date of birth cannot be in the future.';
    elseif (calc_age($d['dob']) < 15)      $errors[] = 'Employee must be at least 15 years old.';
    if (!$d['department_id'])              $errors[] = 'Department is required.';
    if (!$d['designation_id'])             $errors[] = 'Designation is required.';
    if (!$d['joining_date'])               $errors[] = 'Joining date is required.';
    elseif ($d['dob'] && $d['joining_date'] <= $d['dob']) $errors[] = 'Joining date must be after date of birth.';
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Email address is invalid.';
    if ($d['basic_salary'] !== null && $d['basic_salary'] < 0) $errors[] = 'Basic salary cannot be negative.';
    if ($d['contract_from'] && $d['contract_to'] && $d['contract_to'] < $d['contract_from']) $errors[] = 'Contract end date must be after contract start date.';
    if ($d['probation_start'] && $d['probation_end'] && $d['probation_end'] < $d['probation_start']) $errors[] = 'Probation end must be after probation start.';
    if ($d['confirmation_date'] && $d['joining_date'] && $d['confirmation_date'] < $d['joining_date']) $errors[] = 'Confirmation date cannot be before joining date.';

    // employment type rules
    $et = $d['employment_type_id'] ? db_find('employment_types', $d['employment_type_id']) : null;
    if ($et && (int)$et['is_contract'] && (!$d['contract_from'] || !$d['contract_to'])) {
        $errors[] = 'Contract From / To dates are required for ' . $et['name'] . ' employees.';
    }

    // uniqueness (CNIC) - includes deleted rows: a deleted employee should be restored, not re-created
    $dupSql = 'SELECT id, name, is_deleted FROM employees WHERE cnic = ?' . ($existing ? ' AND id <> ?' : '');
    $dup = db_row($dupSql, $existing ? [$d['cnic'], (int)$existing['id']] : [$d['cnic']]);
    if ($dup) {
        $errors[] = 'CNIC ' . $d['cnic'] . ' already belongs to ' . $dup['name'] . ($dup['is_deleted'] ? ' (in recycle bin - restore it instead).' : '.');
    }

    // defaults derived from settings when left blank (new records only)
    if (!$existing && $d['joining_date']) {
        if (!$d['probation_start'] && ($et === null || (int)$et['has_probation'])) $d['probation_start'] = $d['joining_date'];
        if ($d['probation_start'] && !$d['probation_end']) $d['probation_end'] = calc_probation_end($d['probation_start']);
    }
    return [$d, $errors];
}

/**
 * Handle photo upload. Returns new filename, null if none uploaded, or adds to $errors.
 */
function employee_handle_photo(int $employeeId, array &$errors, ?string $oldPhoto = null): ?string
{
    if (empty($_FILES['photo']['name'])) return null;
    $f = $_FILES['photo'];
    if ($f['error'] !== UPLOAD_ERR_OK) { $errors[] = 'Photo upload failed (error ' . $f['error'] . ').'; return null; }
    if ($f['size'] > 2 * 1024 * 1024) { $errors[] = 'Photo must be 2 MB or smaller.'; return null; }
    $info = @getimagesize($f['tmp_name']);
    $allowed = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    if (!$info || !isset($allowed[$info[2]])) { $errors[] = 'Photo must be a JPG, PNG or WEBP image.'; return null; }
    $dir = UPLOAD_PATH . '/photos';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $name = $employeeId . '_' . bin2hex(random_bytes(4)) . '.' . $allowed[$info[2]];
    if (!move_uploaded_file($f['tmp_name'], "$dir/$name")) { $errors[] = 'Could not save the photo. Check folder permissions.'; return null; }
    if ($oldPhoto) @unlink("$dir/" . basename($oldPhoto));
    return $name;
}

/** Options for the form selects. */
function employee_form_options(?array $emp = null): array
{
    return [

        'designations'  => master_options('designations', $emp['designation_id'] ?? null),
        'pay_scales'    => master_options('pay_scales', $emp['pay_scale_id'] ?? null),
        'employment_types' => master_options('employment_types', $emp['employment_type_id'] ?? null),
        'blood_groups'  => master_options('blood_groups', $emp['blood_group_id'] ?? null),
        'marital_statuses' => master_options('marital_statuses', $emp['marital_status_id'] ?? null),

    ];
}
