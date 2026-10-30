<?php
/** Export the (filtered) employee list to Excel / CSV. Uses the same filters as index.php. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/export.php';
require_staff();

[$where, $params] = employee_filters($_GET);
$rows = db_all("SELECT e.*, d.name AS department, g.name AS designation, ps.name AS pay_scale, et.name AS employment_type,
        bg.name AS blood_group, ms.name AS marital_status
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations g ON g.id = e.designation_id
    LEFT JOIN pay_scales ps ON ps.id = e.pay_scale_id
    LEFT JOIN employment_types et ON et.id = e.employment_type_id
    LEFT JOIN blood_groups bg ON bg.id = e.blood_group_id
    LEFT JOIN marital_statuses ms ON ms.id = e.marital_status_id
    WHERE $where ORDER BY d.sort_order, e.name", $params);

$headers = ['#', 'Employee Code', 'Name', 'Father/Husband Name', 'CNIC', 'Gender', 'Date of Birth', 'Age', 'Marital Status', 'Blood Group',
    'Contact', 'Email', 'Address', 'Department', 'Designation', 'Pay Scale', 'Basic Salary', 'Employment Type', 'Joining Date',
    'Contract From', 'Contract To', 'Status', 'Probation Start', 'Probation End', 'Confirmation Date', 'Confirmation Order',
    'Retirement Date', 'LPR Eligible', 'Break in Service', 'Service Book No', 'GP Fund No', 'Pension No', 'Seniority No', 'Active'];
$out = [];
foreach ($rows as $i => $r) {
    $out[] = [$i + 1, $r['employee_code'], $r['name'], $r['father_husband_name'], $r['cnic'], $r['gender'], $r['dob'], calc_age($r['dob']),
        $r['marital_status'], $r['blood_group'], $r['contact'], $r['email'], $r['address'], $r['department'], $r['designation'], $r['pay_scale'],
        $r['basic_salary'], $r['employment_type'], $r['joining_date'], $r['contract_from'], $r['contract_to'], $r['status'],
        $r['probation_start'], $r['probation_end'], $r['confirmation_date'], $r['confirmation_order_no'], $r['retirement_date'],
        $r['is_lpr_eligible'] ? 'Yes' : 'No', $r['has_break_in_service'] ? 'Yes' : 'No', $r['service_book_no'], $r['gp_fund_no'],
        $r['pension_no'], $r['seniority_no'], $r['is_active'] ? 'Yes' : 'No'];
}
log_activity('EXPORT', 'employees', null, null, null, 'Employee list exported (' . count($out) . ' rows)');
export_table('employees', $headers, $out, setting('org_name', ORG_NAME) . ' — Employee List');
