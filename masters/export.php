<?php
/** Export one master type to Excel / CSV. ?type=departments&format=xlsx|csv */
require_once __DIR__ . '/../includes/bootstrap.php';
require_once ROOT_PATH . '/includes/masters_config.php';
require_once ROOT_PATH . '/includes/export.php';
require_role('super_admin', 'officer');

$type = (string)get('type', '');
$cfg  = master_get($type);
if (!$cfg) { http_response_code(404); die('Unknown master type.'); }

$table = db_ident($cfg['table']);
$rows  = db_all("SELECT * FROM `$table` WHERE is_deleted = 0 ORDER BY " . ($type === 'sanctioned_posts' ? 'department_id, designation_id' : 'sort_order, name'));

$lookups = [];
foreach ($cfg['fields'] as $f) {
    if ($f['type'] === 'select') {
        foreach (db_all('SELECT id, name FROM `' . db_ident($f['source']) . '`') as $r) $lookups[$f['source']][(int)$r['id']] = $r['name'];
    }
}
$headers = ['#'];
foreach ($cfg['fields'] as $f) $headers[] = $f['label'];
$headers[] = 'Status';
$headers[] = 'Created';

$out = [];
foreach ($rows as $i => $r) {
    $line = [$i + 1];
    foreach ($cfg['fields'] as $f) {
        $v = $r[$f['name']] ?? null;
        $line[] = match ($f['type']) {
            'checkbox' => $v ? 'Yes' : 'No',
            'select'   => $lookups[$f['source']][(int)$v] ?? '',
            default    => $v,
        };
    }
    $line[] = $r['is_active'] ? 'Active' : 'Inactive';
    $line[] = fmt_date($r['created_at']);
    $out[] = $line;
}
log_activity('EXPORT', $table, null, null, null, $cfg['label'] . ' exported (' . strtoupper((string)get('format', 'xlsx')) . ')');
export_table('master_' . $type, $headers, $out, setting('org_name', ORG_NAME) . ' — ' . $cfg['label']);
