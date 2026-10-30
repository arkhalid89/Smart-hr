<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_editor();
$cnic = normalize_cnic((string)get('cnic', ''));
$id   = get_int('id', 0);
if (!valid_cnic($cnic)) json_response(['ok' => false, 'message' => 'Invalid CNIC format (35202-1234567-1).']);
$dup = db_row('SELECT id, name, employee_code, is_deleted FROM employees WHERE cnic = ? AND id <> ?', [$cnic, $id]);
if ($dup) json_response(['ok' => false, 'message' => 'Already registered: ' . $dup['name'] . ' (' . $dup['employee_code'] . ')' . ($dup['is_deleted'] ? ' – in recycle bin' : '')]);
json_response(['ok' => true, 'message' => 'CNIC is available.']);
