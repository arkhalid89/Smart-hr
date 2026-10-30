<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_editor();
$dept = get_int('department_id', 0);
$row  = $dept ? db_find('departments', $dept) : null;
if (!$row) json_response(['code' => '']);
json_response(['code' => employee_code_preview((string)$row['code'], get('joining_date') ?: null)]);
