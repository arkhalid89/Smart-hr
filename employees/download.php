<?php
/** Streams an employee document from the protected uploads directory. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();
$id  = get_int('id', 0);
$doc = $id ? db_row('SELECT * FROM employee_documents d WHERE d.id = ? AND d.is_deleted = 0 AND ' . employee_self_document_filter('d'), [$id]) : null;
if (!$doc) { http_response_code(404); die('Document not found.'); }
$emp = employee_get((int)$doc['employee_id'], true);
if (!$emp) { http_response_code(404); die('Employee not found.'); }
require_employee_access($emp);
$file = UPLOAD_PATH . '/documents/' . $doc['file_path'];
if (!is_file($file)) { http_response_code(404); die('File is missing on the server.'); }
$inline = get('inline') === '1' && in_array($doc['mime_type'], ['application/pdf', 'image/jpeg', 'image/png'], true);
header('Content-Type: ' . ($doc['mime_type'] ?: 'application/octet-stream'));
header('Content-Length: ' . filesize($file));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . basename((string)$doc['original_name']) . '"');
header('X-Content-Type-Options: nosniff');
readfile($file);
