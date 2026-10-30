<?php
/** Serves employee photos from the protected uploads directory. */
require_once __DIR__ . '/../includes/bootstrap.php';
require_login();

$id  = get_int('id', 0);
$emp = $id ? db_row('SELECT id, photo, department_id FROM employees WHERE id = ?', [$id]) : null;
/* outside the viewer's jurisdiction the generic avatar is served instead */
$photo = ($emp && can_view_employee($emp)) ? $emp['photo'] : null;
$file = $photo ? UPLOAD_PATH . '/photos/' . basename((string)$photo) : null;
if (!$file || !is_file($file)) {
    header('Content-Type: image/svg+xml');
    readfile(ROOT_PATH . '/assets/img/avatar.svg');
    exit;
}
$mime = mime_content_type($file) ?: 'image/jpeg';
header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=3600');
readfile($file);
