<?php
/** Resolve a service-record reference number (e.g. AWD-2022-0001) and open it on the employee profile. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();
$ref = trim((string)get('ref', ''));
$hit = $ref !== '' ? svc_find_ref($ref) : null;
if (!$hit) { flash('warning', 'No service record found for reference "' . $ref . '".'); redirect('dashboard/index.php'); }
$emp = employee_get($hit['employee_id'], is_super_admin());
if (!$emp) { flash('warning', 'The employee for reference ' . $ref . ' is not available.'); redirect('dashboard/index.php'); }
require_employee_access($emp);
redirect(svc_record_url($hit['table'], $hit['id'], $hit['employee_id']));
