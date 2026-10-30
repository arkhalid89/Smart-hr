<?php
require_once __DIR__ . '/../../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/service_engine.php';
require_super_admin();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_error('Invalid request.', 405);
csrf_verify();
db_begin();
try {
    svc_void((string)post('table'), post_int('id', 0), (string)post('reason', ''));
    db_commit();
    json_ok('Record voided and employee status re-derived.');
} catch (Throwable $e) {
    db_rollback();
    json_error($e->getMessage());
}
