<?php
/**
 * Demo data generator (developer tool).
 *   php database/seed_demo.php            -> seeds employees + service history through the engine
 *   php database/seed_demo.php --export   -> also writes database/demo_data.sql (data-only INSERTs)
 *
 * Runs against the database in config.php. Assumes schema.sql is loaded (masters present)
 * and that no employees exist yet.
 *
 * The shipped demo_data.sql also carries inquiries and employee sign-ins added
 * after this generator was written; regenerate it with care.
 *
 * The staff belong to the DC Office Nankana Sahib and the Arazi Record Centre;
 * the old branch codes (GEN, REV ...) used below are mapped onto them, and every
 * posting goes to the department that owns the office it names.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') die('CLI only');
require_once __DIR__ . '/../config.php';
require_once ROOT_PATH . '/includes/db.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/includes/audit.php';
require_once ROOT_PATH . '/includes/auth.php';
require_once ROOT_PATH . '/includes/employee.php';
require_once ROOT_PATH . '/includes/service_engine.php';
require_once ROOT_PATH . '/includes/backup.php';
$_SESSION = ['user' => ['id' => 1, 'username' => 'superadmin', 'full_name' => 'System Super Admin', 'role' => 'super_admin', 'role_label' => 'Super Admin', 'departments' => []]];
$_SERVER['REMOTE_ADDR'] = '127.0.0.1';

if ((int)db_value('SELECT COUNT(*) FROM employees') > 0) die("Employees already exist. Reset the database first.\n");

$desig = []; foreach (db_all('SELECT id, name, pay_scale_id FROM designations') as $r) $desig[$r['name']] = $r;
$scaleMin = []; foreach (db_all('SELECT id, min_salary FROM pay_scales') as $r) $scaleMin[(int)$r['id']] = (float)$r['min_salary'];
$dept = []; foreach (db_all('SELECT id, code FROM departments') as $r) $dept[$r['code']] = (int)$r['id'];
foreach (['GEN' => 'NNS-DC', 'ACC' => 'NNS-DC', 'EST' => 'NNS-DC', 'CMP' => 'NNS-DC', 'LAC' => 'NNS-DC', 'IT' => 'NNS-DC', 'REV' => 'NNS-NSH-ARC', 'REC' => 'NNS-NSH-ARC'] as $old => $new) {
    if (isset($dept[$new])) $dept[$old] = $dept[$new];
}
$locDept = []; foreach (db_all('SELECT id, department_id FROM posting_locations') as $r) $locDept[(int)$r['id']] = (int)$r['department_id'];
$et = []; foreach (db_all('SELECT id, name FROM employment_types') as $r) $et[$r['name']] = (int)$r['id'];
$lt = []; foreach (db_all('SELECT id, name, is_eol, is_lpr FROM leave_types') as $r) { if ($r['is_eol']) $lt['EOL'] = (int)$r['id']; elseif ($r['is_lpr']) $lt['LPR'] = (int)$r['id']; else $lt[$r['name']] = (int)$r['id']; }
$pt = []; foreach (db_all('SELECT id, name FROM punishment_types') as $r) $pt[$r['name']] = (int)$r['id'];
$rt = []; foreach (db_all('SELECT id, name FROM reinstatement_types') as $r) $rt[$r['name']] = (int)$r['id'];
$org = []; foreach (db_all('SELECT id, name FROM deputation_orgs') as $r) $org[$r['name']] = (int)$r['id'];
$aw = array_column(db_all('SELECT id FROM award_types'), 'id');
$loc = array_column(db_all('SELECT id FROM posting_locations'), 'id');

$seq = 1000000;
function emp(array $a): int
{
    global $desig, $scaleMin, $dept, $et, $seq, $loc, $locDept;
    $d = $desig[$a['desig']];
    $deptId = $dept[$a['dept']];
    /* the office decides the department; without one, an office of the department */
    $locId = $a['loc'] ?? (array_keys($locDept, $deptId, true)[0] ?? null);
    if ($locId && isset($locDept[$locId])) $deptId = $locDept[$locId];
    $etId = $et[$a['type'] ?? 'Regular'];
    $joining = $a['joining'];
    $probation = ($a['type'] ?? 'Regular') === 'Regular';
    $row = [
        'employee_code' => employee_generate_code(array_search($deptId, $dept, true), $joining),
        'cnic' => '35202-' . (++$seq) . '-' . ($a['gender'] === 'Female' ? '2' : '1'),
        'name' => $a['name'], 'father_husband_name' => $a['father'], 'gender' => $a['gender'], 'dob' => $a['dob'],
        'marital_status_id' => $a['marital'] ?? 2, 'blood_group_id' => rand(1, 8), 'contact' => '03' . rand(0, 4) . rand(0, 9) . '-' . rand(1000000, 9999999),
        'address' => $a['address'] ?? 'Nankana Sahib', 'department_id' => $deptId, 'designation_id' => (int)$d['id'], 'pay_scale_id' => (int)$d['pay_scale_id'],
        'basic_salary' => $scaleMin[(int)$d['pay_scale_id']] + ($a['increments'] ?? 0) * 500, 'joining_date' => $joining, 'employment_type_id' => $etId,
        'contract_from' => $a['contract_from'] ?? null, 'contract_to' => $a['contract_to'] ?? null,
        'probation_start' => $probation ? $joining : null, 'probation_end' => $probation ? calc_probation_end($joining) : null,
        'confirmation_date' => $a['confirmed'] ?? null, 'confirmation_order_no' => isset($a['confirmed']) ? 'EST/CONF/' . substr($a['confirmed'], 0, 4) . '/' . rand(10, 99) : null,
        'status' => isset($a['confirmed']) || !$probation ? 'Active' : 'Probation',
        'service_book_no' => 'SB-' . rand(100, 999), 'gp_fund_no' => 'GPF-' . rand(10000, 99999), 'retirement_date' => calc_retirement_date($a['dob']), 'created_by' => 1,
    ];
    $id = db_insert('employees', $row);
    $pid = svc_insert('postings', ['employee_id' => $id, 'office' => 'DC Office Nankana Sahib', 'department_id' => $deptId, 'section' => $a['section'] ?? null, 'location_id' => $locId,
        'posting_type' => 'Initial Posting', 'effective_date' => $joining, 'order_no' => 'EST/' . substr($joining, 0, 4) . '/' . rand(100, 999), 'order_date' => $joining, 'remarks' => 'Initial posting on joining', 'is_current' => 1, 'created_by' => 1]);
    db_update('employees', ['current_posting_id' => $pid], 'id = ?', [$id]);
    add_timeline_event($id, 'Joining', $joining, 'Joined as ' . $d['name'] . ($row['status'] === 'Probation' ? ' (Probation)' : ''), 'Department: ' . $a['dept'], 'employees', $id);
    add_timeline_event($id, 'Posting', $joining, 'Initial posting', null, 'postings', $pid);
    if (!empty($a['confirmed'])) add_timeline_event($id, 'Confirmation', $a['confirmed'], 'Service confirmed', 'Order No. ' . $row['confirmation_order_no'], 'employees', $id);
    // family
    if (($a['marital'] ?? 2) === 2) {
        db_insert('employee_family', ['employee_id' => $id, 'relation' => 'Spouse', 'name' => $a['spouse'] ?? ($a['gender'] === 'Male' ? 'Mst. ' . explode(' ', $a['name'])[0] . ' Bibi' : 'Muhammad ' . explode(' ', $a['father'])[0]), 'cnic' => '35202-' . (++$seq) . '-' . ($a['gender'] === 'Male' ? '2' : '1'), 'dob' => date('Y-m-d', strtotime($a['dob'] . ' +2 years')), 'contact' => '0300-' . rand(1000000, 9999999), 'is_nominee' => 1, 'nominee_share' => 100, 'is_emergency_contact' => 1, 'created_by' => 1]);
    }
    employee_recalculate($id);
    return $id;
}
$E = fn(int $id) => employee_get($id);

echo "Seeding employees...\n";
/* ---- Senior officers ---- */
$dc  = emp(['name' => 'Muhammad Tariq Zaman', 'father' => 'Zaman Khan', 'gender' => 'Male', 'dob' => '1975-04-12', 'dept' => 'GEN', 'desig' => 'Deputy Commissioner', 'joining' => '2001-09-15', 'confirmed' => '2002-09-15', 'section' => 'Office of the DC', 'loc' => 1, 'increments' => 12]);
$adc = emp(['name' => 'Rana Shahid Mehmood', 'father' => 'Rana Bashir Ahmed', 'gender' => 'Male', 'dob' => '1978-11-02', 'dept' => 'GEN', 'desig' => 'Additional Deputy Commissioner', 'joining' => '2004-03-01', 'confirmed' => '2005-03-01', 'loc' => 1, 'increments' => 10]);
$ac  = emp(['name' => 'Ayesha Siddiqui', 'father' => 'Dr. Naveed Siddiqui', 'gender' => 'Female', 'dob' => '1988-06-25', 'dept' => 'GEN', 'desig' => 'Assistant Commissioner', 'joining' => '2014-01-10', 'confirmed' => '2015-01-10', 'loc' => 2, 'increments' => 6]);
/* ---- Regular staff ---- */
$e1 = emp(['name' => 'Muhammad Aslam', 'father' => 'Ghulam Rasool', 'gender' => 'Male', 'dob' => '1970-05-15', 'dept' => 'GEN', 'desig' => 'Assistant', 'joining' => '1995-03-01', 'confirmed' => '1996-03-05', 'increments' => 20]);
$e2 = emp(['name' => 'Shahbaz Ahmed', 'father' => 'Nazir Ahmed', 'gender' => 'Male', 'dob' => '1982-01-20', 'dept' => 'GEN', 'desig' => 'Senior Clerk', 'joining' => '2006-07-01', 'confirmed' => '2007-07-01', 'increments' => 9]);
$e3 = emp(['name' => 'Farhan Ali Butt', 'father' => 'Ali Muhammad Butt', 'gender' => 'Male', 'dob' => '1990-09-09', 'dept' => 'EST', 'desig' => 'Senior Clerk', 'joining' => '2012-02-15', 'confirmed' => '2013-02-15', 'increments' => 5]);
$e4 = emp(['name' => 'Nadia Perveen', 'father' => 'Muhammad Akram', 'gender' => 'Female', 'dob' => '1992-12-01', 'dept' => 'EST', 'desig' => 'Stenographer', 'joining' => '2016-04-01', 'confirmed' => '2017-04-01', 'increments' => 3]);
$e5 = emp(['name' => 'Abdul Rehman', 'father' => 'Abdul Ghafoor', 'gender' => 'Male', 'dob' => '1985-03-30', 'dept' => 'ACC', 'desig' => 'Accountant', 'joining' => '2009-10-01', 'confirmed' => '2010-10-01', 'increments' => 7]);
$e6 = emp(['name' => 'Usman Ghani', 'father' => 'Ghani Muhammad', 'gender' => 'Male', 'dob' => '1994-07-18', 'dept' => 'ACC', 'desig' => 'Junior Clerk', 'joining' => '2018-08-01', 'confirmed' => '2019-08-01', 'increments' => 2]);
$e7 = emp(['name' => 'Ghulam Murtaza', 'father' => 'Ghulam Mustafa', 'gender' => 'Male', 'dob' => '1979-02-14', 'dept' => 'REV', 'desig' => 'Kanungo', 'joining' => '2003-05-01', 'confirmed' => '2004-05-01', 'increments' => 11, 'loc' => 6]);
$e8 = emp(['name' => 'Rashid Minhas', 'father' => 'Sardar Minhas', 'gender' => 'Male', 'dob' => '1987-10-05', 'dept' => 'REV', 'desig' => 'Patwari', 'joining' => '2011-01-15', 'confirmed' => '2012-01-15', 'increments' => 6, 'loc' => 7]);
$e9 = emp(['name' => 'Imran Haider', 'father' => 'Haider Ali', 'gender' => 'Male', 'dob' => '1989-08-22', 'dept' => 'REV', 'desig' => 'Patwari', 'joining' => '2013-03-01', 'confirmed' => '2014-03-01', 'increments' => 5, 'loc' => 3]);
$e10 = emp(['name' => 'Sadia Noor', 'father' => 'Noor Muhammad', 'gender' => 'Female', 'dob' => '1996-05-05', 'dept' => 'IT', 'desig' => 'Computer Operator', 'joining' => '2020-01-06', 'confirmed' => '2021-01-06', 'increments' => 2, 'marital' => 1]);
$e11 = emp(['name' => 'Bilal Hussain', 'father' => 'Hussain Bakhsh', 'gender' => 'Male', 'dob' => '1997-11-11', 'dept' => 'IT', 'desig' => 'Computer Operator', 'joining' => '2021-03-01', 'confirmed' => '2022-03-01', 'increments' => 1, 'marital' => 1]);
$e12 = emp(['name' => 'Muhammad Boota', 'father' => 'Allah Ditta', 'gender' => 'Male', 'dob' => '1966-12-15', 'dept' => 'GEN', 'desig' => 'Naib Qasid', 'joining' => '1992-06-01', 'confirmed' => '1993-06-01', 'increments' => 22]);
$e13 = emp(['name' => 'Riaz Ahmed', 'father' => 'Sharif Ahmed', 'gender' => 'Male', 'dob' => '1983-04-04', 'dept' => 'GEN', 'desig' => 'Driver', 'joining' => '2008-02-01', 'confirmed' => '2009-02-01', 'increments' => 8]);
$e14 = emp(['name' => 'Kashif Mahmood', 'father' => 'Mahmood Ahmed', 'gender' => 'Male', 'dob' => '1991-06-30', 'dept' => 'CMP', 'desig' => 'Junior Clerk', 'joining' => '2015-09-01', 'confirmed' => '2016-09-01', 'increments' => 4]);
$e15 = emp(['name' => 'Hina Shahzadi', 'father' => 'Shahzad Ali', 'gender' => 'Female', 'dob' => '1993-02-17', 'dept' => 'LAC', 'desig' => 'Junior Clerk', 'joining' => '2017-05-01', 'confirmed' => '2018-05-01', 'increments' => 3]);
$e16 = emp(['name' => 'Allah Rakha', 'father' => 'Karam Din', 'gender' => 'Male', 'dob' => '1966-09-20', 'dept' => 'REC', 'desig' => 'Naib Qasid', 'joining' => '1990-01-01', 'confirmed' => '1991-01-01', 'increments' => 25]);
/* ---- Probation / new ---- */
$p1 = emp(['name' => 'Hamza Javed', 'father' => 'Javed Iqbal', 'gender' => 'Male', 'dob' => '1999-03-12', 'dept' => 'GEN', 'desig' => 'Junior Clerk', 'joining' => date('Y-m-d', strtotime('-8 months')), 'marital' => 1]);
$p2 = emp(['name' => 'Maryam Fatima', 'father' => 'Muhammad Yousaf', 'gender' => 'Female', 'dob' => '2000-10-02', 'dept' => 'EST', 'desig' => 'Computer Operator', 'joining' => date('Y-m-d', strtotime('-13 months')), 'marital' => 1]);
/* ---- Contract ---- */
$c1 = emp(['name' => 'Zeeshan Akhtar', 'father' => 'Akhtar Hussain', 'gender' => 'Male', 'dob' => '1995-01-25', 'dept' => 'IT', 'desig' => 'Computer Operator', 'joining' => date('Y-m-d', strtotime('-10 months')), 'type' => 'Contract', 'contract_from' => date('Y-m-d', strtotime('-10 months')), 'contract_to' => date('Y-m-d', strtotime('+45 days')), 'marital' => 1]);
$c2 = emp(['name' => 'Waqas Saleem', 'father' => 'Saleem Raza', 'gender' => 'Male', 'dob' => '1993-07-07', 'dept' => 'CMP', 'desig' => 'Computer Operator', 'joining' => '2023-01-01', 'type' => 'Contract', 'contract_from' => '2023-01-01', 'contract_to' => '2023-12-31']);
$c3 = emp(['name' => 'Saima Akram', 'father' => 'Akram Khan', 'gender' => 'Female', 'dob' => '1990-11-20', 'dept' => 'LAC', 'desig' => 'Junior Clerk', 'joining' => '2022-02-01', 'type' => 'Contract', 'contract_from' => '2022-02-01', 'contract_to' => '2024-01-31']);
/* ---- Exits ---- */
$x1 = emp(['name' => 'Iftikhar Ahmed', 'father' => 'Nazar Muhammad', 'gender' => 'Male', 'dob' => '1965-08-10', 'dept' => 'REV', 'desig' => 'Kanungo', 'joining' => '1988-04-01', 'confirmed' => '1989-04-01', 'increments' => 24]);
$x2 = emp(['name' => 'Tanveer Abbas', 'father' => 'Abbas Ali', 'gender' => 'Male', 'dob' => '1984-05-05', 'dept' => 'GEN', 'desig' => 'Senior Clerk', 'joining' => '2007-01-01', 'confirmed' => '2008-01-01', 'increments' => 8]);
$x3 = emp(['name' => 'Naveed Anjum', 'father' => 'Anjum Pervaiz', 'gender' => 'Male', 'dob' => '1980-12-12', 'dept' => 'ACC', 'desig' => 'Senior Clerk', 'joining' => '2005-06-01', 'confirmed' => '2006-06-01', 'increments' => 9]);
$x4 = emp(['name' => 'Asghar Ali', 'father' => 'Ali Sher', 'gender' => 'Male', 'dob' => '1972-03-03', 'dept' => 'GEN', 'desig' => 'Driver', 'joining' => '1998-09-01', 'confirmed' => '1999-09-01', 'increments' => 18]);
$x5 = emp(['name' => 'Rukhsana Kausar', 'father' => 'Kausar Ali', 'gender' => 'Female', 'dob' => '1981-07-15', 'dept' => 'EST', 'desig' => 'Assistant', 'joining' => '2004-08-01', 'confirmed' => '2005-08-01', 'increments' => 10]);
$x6 = emp(['name' => 'Javed Akhtar', 'father' => 'Akhtar Ali', 'gender' => 'Male', 'dob' => '1986-02-02', 'dept' => 'REV', 'desig' => 'Patwari', 'joining' => '2010-04-01', 'confirmed' => '2011-04-01', 'increments' => 6]);

echo "Applying service history...\n";
$aw2 = fn(int $id, string $d, string $desc) => svc_award($E($id), ['award_type_id' => $aw[array_rand($aw)], 'order_date' => $d, 'order_no' => 'DC/AWD/' . substr($d, 0, 4) . '/' . rand(10, 99), 'description' => $desc]);
$inc = function (int $id, string $d) use ($E) { $e = $E($id); svc_increment($e, ['new_salary' => (float)$e['basic_salary'] + 1000, 'effective_date' => $d, 'order_no' => 'ACC/INC/' . substr($d, 0, 4)]); };
$leave = fn(int $id, string $type, string $f, string $t, string $letter = '') => svc_leave($E($id), ['leave_type_id' => $lt[$type], 'from_date' => $f, 'to_date' => $t, 'status' => 'Approved', 'letter_no' => $letter ?: 'EST/L/' . rand(100, 999), 'letter_date' => $f, 'remarks' => null]);
$transfer = fn(int $id, string $d, int $locId, string $dc = 'GEN') => svc_new_posting($E($id), ['posting_type' => 'Transfer Posting', 'effective_date' => $d, 'department_id' => $locDept[$locId] ?? ($dept[$dc] ?? null), 'location_id' => $locId, 'office' => 'DC Office Nankana Sahib', 'order_no' => 'EST/TR/' . substr($d, 0, 4) . '/' . rand(10, 99), 'order_date' => $d, 'remarks' => 'Routine transfer'], 'Transfer', 'Transfer Posting');

// promotions (confirmed employees only)
svc_promotion($E($e1), ['promotion_type' => 'Promotion', 'new_designation_id' => $desig['Superintendent']['id'], 'new_scale_id' => $desig['Superintendent']['pay_scale_id'], 'effective_date' => '2015-06-01', 'order_no' => 'S&GAD/PRO/2015/44', 'order_date' => '2015-05-20', 'order_by_authority' => 'Commissioner Lahore Division', 'remarks' => 'Promoted from Assistant', 'create_posting' => 1]);
svc_promotion($E($e2), ['promotion_type' => 'Promotion', 'new_designation_id' => $desig['Assistant']['id'], 'new_scale_id' => $desig['Assistant']['pay_scale_id'], 'effective_date' => '2018-03-01', 'order_no' => 'DC/PRO/2018/12', 'order_date' => '2018-02-15', 'order_by_authority' => 'Deputy Commissioner', 'remarks' => 'Promoted from Senior Clerk', 'create_posting' => 0]);
svc_promotion($E($e3), ['promotion_type' => 'Upgradation', 'upgradation_kind' => 'Financial', 'new_scale_id' => $desig['Senior Clerk']['pay_scale_id'], 'effective_date' => '2020-01-01', 'order_no' => 'FD/UPG/2019/3', 'order_date' => '2019-12-20', 'order_by_authority' => 'Finance Department Punjab', 'remarks' => 'Time-scale upgradation package']);
svc_promotion($E($ac), ['promotion_type' => 'Upgradation', 'upgradation_kind' => 'Notional', 'new_scale_id' => $desig['Assistant Commissioner']['pay_scale_id'], 'effective_date' => '2019-07-01', 'order_no' => 'S&GAD/UPG/2019/9', 'order_date' => '2019-06-15', 'order_by_authority' => 'S&GAD']);
// increments
foreach ([$e1, $e2, $e3, $e5, $e7, $e8, $e13] as $id) { $inc($id, '2023-12-01'); $inc($id, '2024-12-01'); $inc($id, '2025-12-01'); }
foreach ([$e4, $e6, $e10, $e14, $e15] as $id) { $inc($id, '2024-12-01'); $inc($id, '2025-12-01'); }
// leaves
$leave($e2, 'Casual Leave', '2025-03-10', '2025-03-14');
$leave($e4, 'Maternity Leave', '2024-05-01', '2024-07-29');
$leave($e6, 'Sick Leave', '2025-08-04', '2025-08-15');
$leave($e13, 'Earned / Annual Leave', '2025-06-01', '2025-06-30');
$leave($e8, 'EOL', '2022-01-01', '2022-09-30', 'EST/EOL/2021/7');   // > 6 months -> break in service
$leave($e15, 'EOL', '2024-02-01', '2024-04-30');                     // < 6 months
$leave($e3, 'Casual Leave', '2026-08-03', '2026-08-05');
// transfers
$transfer($e7, '2018-08-01', 3, 'REV'); $transfer($e7, '2023-04-15', 6, 'REV');
$transfer($e8, '2019-02-01', 4, 'REV');
$transfer($e14, '2021-01-01', 1, 'CMP');
$transfer($e13, '2016-01-01', 2, 'GEN'); $transfer($e13, '2022-06-01', 1, 'GEN');
// awards
$aw2($e1, '2019-08-14', 'Outstanding performance during Independence Day arrangements');
$aw2($e5, '2022-12-30', 'Timely closure of annual accounts');
$aw2($ac, '2023-03-23', 'Exemplary services in relief operations');
$aw2($e10, '2025-01-15', 'Digitisation of record room');
// minor punishments
svc_punishment($E($e6), ['punishment_type_id' => $pt['Censure'], 'order_date' => '2024-03-12', 'order_no' => 'DC/DISC/2024/5', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Late attendance on repeated occasions']);
svc_punishment($E($e13), ['punishment_type_id' => $pt['Fine'], 'order_date' => '2023-09-01', 'order_no' => 'DC/DISC/2023/11', 'order_by_authority' => 'ADC (General)', 'description' => 'Negligence in vehicle maintenance - fine Rs. 2,000']);
// suspension -> reinstated with back benefits (e2)
svc_punishment($E($e2), ['punishment_type_id' => $pt['Suspension'], 'order_date' => '2024-10-01', 'order_no' => 'DC/DISC/2024/22', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Inquiry under PEEDA Act 2006', 'suspension_start' => '2024-10-01', 'suspension_end' => null, 'pay_status' => 'Subsistence Allowance']);
svc_reinstatement($E($e2), ['reinstatement_type_id' => $rt['Reinstated with Back Benefits'], 'reinstatement_date' => '2025-02-15', 'order_no' => 'DC/REIN/2025/3', 'order_date' => '2025-02-10', 'order_by_authority' => 'Deputy Commissioner', 'remarks' => 'Inquiry concluded; charges not proved']);
// suspension without back benefits (e14)
svc_punishment($E($e14), ['punishment_type_id' => $pt['Suspension'], 'order_date' => '2023-05-01', 'order_no' => 'DC/DISC/2023/4', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Misconduct complaint', 'suspension_start' => '2023-05-01', 'suspension_end' => '2023-08-31', 'pay_status' => 'Subsistence Allowance']);
svc_reinstatement($E($e14), ['reinstatement_type_id' => $rt['Exonerated with Minor Penalty'], 'reinstatement_date' => '2023-09-01', 'order_no' => 'DC/REIN/2023/8', 'order_date' => '2023-08-28', 'order_by_authority' => 'Deputy Commissioner', 'remarks' => 'Censure awarded']);
// currently suspended (e9)
svc_punishment($E($e9), ['punishment_type_id' => $pt['Suspension'], 'order_date' => date('Y-m-d', strtotime('-40 days')), 'order_no' => 'DC/DISC/' . date('Y') . '/17', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Alleged tampering of revenue record - inquiry pending', 'suspension_start' => date('Y-m-d', strtotime('-40 days')), 'suspension_end' => null, 'pay_status' => 'Subsistence Allowance']);
// deputation reverted (e5) and active (e3, reversion due soon)
svc_deputation($E($e5), ['deputation_org_id' => $org['NADRA'], 'start_date' => '2021-01-01', 'expected_reversion_date' => '2022-12-31', 'order_no' => 'S&GAD/DEP/2020/31', 'order_date' => '2020-12-15', 'remarks' => 'Deputation for NADRA registration drive']);
svc_reversion($E($e5), ['actual_reversion_date' => '2023-01-02', 'reversion_order_no' => 'S&GAD/REV/2022/40', 'reversion_order_date' => '2022-12-28', 'department_id' => null, 'location_id' => null, 'reversion_remarks' => 'Reverted on completion of deputation period']);
svc_deputation($E($e3), ['deputation_org_id' => $org['Punjab Land Records Authority (PLRA)'], 'start_date' => date('Y-m-d', strtotime('-11 months')), 'expected_reversion_date' => date('Y-m-d', strtotime('+20 days')), 'order_no' => 'S&GAD/DEP/' . date('Y', strtotime('-11 months')) . '/8', 'order_date' => date('Y-m-d', strtotime('-11 months -10 days')), 'remarks' => 'Arazi Record Centre support']);
// LPR (e12: retires soon)
$lprFrom = date('Y-m-d', strtotime('-2 months'));
svc_leave($E($e12), ['leave_type_id' => $lt['LPR'], 'from_date' => $lprFrom, 'to_date' => date('Y-m-d', strtotime($lprFrom . ' +179 days')), 'status' => 'Approved', 'letter_no' => 'EST/LPR/' . date('Y') . '/2', 'letter_date' => $lprFrom, 'remarks' => 'Leave preparatory to retirement']);
// exits
svc_exit($E($x1), ['exit_type' => 'Normal Retirement', 'exit_date' => '2025-08-10', 'order_no' => 'EST/RET/2025/6', 'order_date' => '2025-07-25', 'remarks' => 'Superannuation at 60']);
svc_exit($E($x2), ['exit_type' => 'Resignation', 'exit_date' => '2024-06-30', 'order_no' => 'EST/RES/2024/3', 'order_date' => '2024-06-15', 'remarks' => 'Resigned to join private sector']);
svc_exit($E($x3), ['exit_type' => 'Medical Invalidation', 'exit_date' => '2024-11-30', 'order_no' => 'EST/MED/2024/1', 'order_date' => '2024-11-20', 'medical_board_ref' => 'MB/DHQ/2024/117', 'medical_board_date' => '2024-10-05', 'medical_recommendation' => 'Permanently unfit for further service']);
svc_exit($E($x4), ['exit_type' => 'Death in Service', 'exit_date' => '2025-03-18', 'date_of_death' => '2025-03-18', 'cause_of_death' => 'Cardiac arrest', 'remarks' => 'Family pension case initiated']);
svc_punishment($E($x5), ['punishment_type_id' => $pt['Removal from Service'], 'order_date' => '2023-11-15', 'order_no' => 'DC/DISC/2023/30', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Removed from service under PEEDA Act after inquiry']);
svc_punishment($E($x6), ['punishment_type_id' => $pt['Dismissal from Service'], 'order_date' => '2024-02-20', 'order_no' => 'DC/DISC/2024/2', 'order_by_authority' => 'Deputy Commissioner', 'description' => 'Dismissed for corruption - Anti Corruption case']);
// contract expired via daily check (c2, c3) then rejoin c3 on renewal
require_once ROOT_PATH . '/cron/daily.php';
run_daily_checks(true);
svc_rejoin($E($c3), ['rejoin_type' => 'Renewal', 'rejoining_date' => '2024-03-01', 'new_contract_from' => '2024-03-01', 'new_contract_to' => date('Y-m-d', strtotime('+14 months')), 'order_no' => 'EST/CONT/2024/9', 'order_date' => '2024-02-25', 'department_id' => null, 'location_id' => null, 'remarks' => 'Contract renewed for two years']);
// retired officer rejoined on contract (x1)
svc_rejoin($E($x1), ['rejoin_type' => 'Retirement Contract', 'rejoining_date' => '2025-10-01', 'new_contract_from' => '2025-10-01', 'new_contract_to' => '2026-09-30', 'order_no' => 'EST/CONT/2025/14', 'order_date' => '2025-09-20', 'department_id' => null, 'location_id' => null, 'remarks' => 'Re-employed on contract for revenue record digitisation']);
// resigned employee rejoined (x2) fresh -> probation
svc_rejoin($E($x2), ['rejoin_type' => 'Resignation Rejoin', 'rejoining_date' => '2025-05-01', 'new_contract_from' => null, 'new_contract_to' => null, 'order_no' => 'EST/REJ/2025/2', 'order_date' => '2025-04-20', 'department_id' => null, 'location_id' => null, 'remarks' => 'Resignation withdrawn with approval of competent authority']);
// link admin user to an employee (admin is also an employee)
db_exec('UPDATE users SET employee_id = ? WHERE id = 2', [$e3]);
run_daily_checks(true);

$n = db_value('SELECT COUNT(*) FROM employees');
echo "Done. $n employees seeded.\n";

if (in_array('--export', $argv, true)) {
    $tables = ['employees', 'employee_family', 'employee_documents', 'postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'timeline_events', 'notifications'];
    $sql = "-- Smart HR System - Demo data (DC Office Nankana Sahib)\n-- Generated " . date('Y-m-d H:i:s') . " by database/seed_demo.php\n-- Load AFTER schema.sql. Covers: probation, confirmation, promotion, upgradation, transfer,\n-- deputation & reversion, suspension, reinstatement (with/without back benefits), EOL break,\n-- LPR, contract expiry, resignation, retirement, medical retirement, death in service, rejoining.\n\nSET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n";
    $db = db();
    foreach ($tables as $t) {
        $rows = db_all("SELECT * FROM `$t`");
        $sql .= "DELETE FROM `$t`;\n";
        if (!$rows) continue;
        $cols = '`' . implode('`,`', array_keys($rows[0])) . '`';
        $chunks = array_chunk($rows, 100);
        foreach ($chunks as $chunk) {
            $vals = [];
            foreach ($chunk as $r) { $v = []; foreach ($r as $x) $v[] = $x === null ? 'NULL' : "'" . $db->real_escape_string((string)$x) . "'"; $vals[] = '(' . implode(',', $v) . ')'; }
            $sql .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $vals) . ";\n";
        }
        $sql .= "\n";
    }
    $sql .= "UPDATE users SET employee_id = (SELECT id FROM employees WHERE name = 'Farhan Ali Butt' LIMIT 1) WHERE id = 2;\nSET FOREIGN_KEY_CHECKS = 1;\n";
    file_put_contents(__DIR__ . '/demo_data.sql', $sql);
    echo "Exported database/demo_data.sql (" . number_format(strlen($sql) / 1024) . " KB)\n";
}
