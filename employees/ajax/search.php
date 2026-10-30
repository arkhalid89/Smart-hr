<?php
/** Global live search: name / CNIC / employee code. Department scoped for admins. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_staff();

$q = trim((string)get('q', ''));
if (mb_strlen($q) < 2) json_response(['results' => []]);

[$scopeSql, $scopeParams] = emp_scope('e');
$like = '%' . $q . '%';
$cnicDigits = preg_replace('/\D/', '', $q);
$cnicLike = $cnicDigits !== '' ? '%' . $cnicDigits . '%' : '%__none__%';

$rows = db_all("SELECT e.id, e.employee_code, e.cnic, e.name, e.status, e.photo,
        d.name AS department, g.name AS designation
    FROM employees e
    LEFT JOIN departments d ON d.id = e.department_id
    LEFT JOIN designations g ON g.id = e.designation_id
    WHERE e.is_deleted = 0 AND $scopeSql
      AND (e.name LIKE ? OR e.employee_code LIKE ? OR e.cnic LIKE ? OR REPLACE(e.cnic,'-','') LIKE ? OR e.father_husband_name LIKE ?)
    ORDER BY e.name LIMIT 10",
    array_merge($scopeParams, [$like, $like, $like, $cnicLike, $like]));

$badge = ['Probation'=>'info','Active'=>'success','Suspended'=>'danger','On Deputation'=>'primary','On LPR'=>'warning'];
$out = [];
foreach ($rows as $r) {
    $out[] = [
        'id' => (int)$r['id'], 'employee_code' => $r['employee_code'], 'cnic' => $r['cnic'], 'name' => $r['name'],
        'status' => $r['status'], 'badge' => $badge[$r['status']] ?? 'secondary',
        'department' => $r['department'] ?? '-', 'designation' => $r['designation'] ?? '-',
        'photo' => photo_url($r['photo'], (int)$r['id']),
    ];
}
/* service records by reference number (e.g. AWD-2022-0001 or partial "AWD-2022") */
if (preg_match('/^[A-Za-z]{2,4}[-\d]/', $q)) {
    foreach (SVC_TYPES as $t => [$type, $label, $dateCol, $tab]) {
        if (count($out) >= 12) break;
        $recs = db_all("SELECT r.id, r.ref_no, r.`$dateCol` AS d, e.id AS emp_id, e.name, e.employee_code, e.photo, e.status FROM `$t` r JOIN employees e ON e.id = r.employee_id WHERE e.is_deleted = 0 AND $scopeSql AND r.ref_no LIKE ? ORDER BY r.ref_no LIMIT 5", array_merge($scopeParams, [strtoupper($q) . '%']));
        foreach ($recs as $r) {
            $out[] = ['id' => (int)$r['emp_id'], 'kind' => 'record', 'url' => svc_record_url($t, (int)$r['id'], (int)$r['emp_id']), 'employee_code' => $r['ref_no'], 'cnic' => $label . ' · ' . fmt_date($r['d']), 'name' => $r['name'] . ' (' . $r['employee_code'] . ')',
                'status' => $label, 'badge' => 'info', 'department' => 'Service record', 'designation' => 'open', 'photo' => photo_url($r['photo'], (int)$r['emp_id'])];
        }
    }
}
json_response(['results' => $out]);
