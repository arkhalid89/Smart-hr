<?php
/** Select2 AJAX source for employee pickers (serving + exited). Read-only and limited to the user's reach, so every staff role may use it. */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_staff();
$q = trim((string)get('q', ''));
[$scope, $params] = emp_scope('e');
$like = '%' . $q . '%';
$digits = preg_replace('/\D/', '', $q);
$cnicLike = $digits !== '' ? '%' . $digits . '%' : '%__none__%';
$rows = db_all("SELECT e.id, e.name, e.employee_code, e.status, g.name AS designation FROM employees e LEFT JOIN designations g ON g.id = e.designation_id
    WHERE e.is_deleted = 0 AND $scope AND (e.name LIKE ? OR e.employee_code LIKE ? OR REPLACE(e.cnic,'-','') LIKE ?) ORDER BY e.name LIMIT 20",
    array_merge($params, [$like, $like, $cnicLike]));
$out = [];
foreach ($rows as $r) $out[] = ['id' => (int)$r['id'], 'text' => $r['name'] . ' — ' . $r['employee_code'] . ' · ' . $r['designation'] . ' [' . $r['status'] . ']'];
json_response(['results' => $out]);
