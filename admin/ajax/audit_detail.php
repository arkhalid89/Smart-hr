<?php
/** Renders one audit log entry as a readable detail card (Super Admin only). */
require_once __DIR__ . '/../../includes/bootstrap.php';
require_super_admin();
require_once ROOT_PATH . '/includes/audit_ui.php';

$id = (int)get('id');
header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($id <= 0) {
    http_response_code(400);
    exit('<div class="alert alert-warning mb-0">No audit entry requested.</div>');
}

$row = db_row(
    'SELECT a.*, u.full_name, e.name AS emp_name, e.employee_code
       FROM audit_logs a
       LEFT JOIN users u ON u.id = a.user_id
       LEFT JOIN employees e ON e.id = a.employee_id
      WHERE a.id = ?',
    [$id]
);

if (!$row) {
    http_response_code(404);
    exit('<div class="alert alert-warning mb-0">That audit entry no longer exists.</div>');
}

echo audit_detail_html($row);
