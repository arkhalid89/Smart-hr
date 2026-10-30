<?php
require_once __DIR__ . '/../includes/bootstrap.php';
require_staff();
csrf_verify();

$action = post('action');
if ($action === 'read_all') {
    db_exec('UPDATE notifications SET is_read = 1 WHERE (user_id IS NULL OR user_id = ?) AND is_read = 0', [user_id()]);
    json_ok('All notifications marked as read.');
}
json_error('Unknown action.');
