<?php
/**
 * Developer tool: regenerates database/schema.sql and database/demo_data.sql
 * from a scratch database, so the fresh-install files always match what the
 * migrations produce.
 *
 *   1. create database smart_hr_build; load the previous schema.sql + demo_data.sql
 *   2. apply the new migration(s) to it
 *   3. docker exec docker-web-1 php /var/www/html/database/tools/build_schema.php /var/www/html/database
 *   4. load the new schema.sql + demo_data.sql into an empty database to check them
 *
 * Structure comes from SHOW CREATE TABLE (collation and AUTO_INCREMENT stripped
 * so it imports on MariaDB too); seed rows are written one per line.
 */
$out = $argv[1] ?? '/var/www/html/database';
$db = new mysqli('db', 'root', 'root', 'smart_hr_build', 3306);
$db->set_charset('utf8mb4');

function q(mysqli $db, string $sql): array { $r = $db->query($sql); return $r->fetch_all(MYSQLI_ASSOC); }

function ddl(mysqli $db, string $t): string
{
    $row = $db->query("SHOW CREATE TABLE `$t`")->fetch_row();
    $s = $row[1];
    $s = preg_replace('/ AUTO_INCREMENT=\d+/', '', $s);
    $s = preg_replace('/ COLLATE[= ]utf8mb4_0900_ai_ci/', '', $s);
    $s = str_replace('_latin1\'', '\'', $s);
    $s = str_replace('_utf8mb4\'', '\'', $s);
    return "DROP TABLE IF EXISTS `$t`;\n" . $s . ";\n";
}

function lit(mysqli $db, $v): string
{
    if ($v === null) return 'NULL';
    return "'" . $db->real_escape_string((string)$v) . "'";
}

/** INSERT with column list, one row per line. */
function inserts(mysqli $db, string $t, string $where = '1=1', array $skip = [], int $chunk = 400): string
{
    $rows = q($db, "SELECT * FROM `$t` WHERE $where ORDER BY 1");
    if (!$rows) return '';
    $cols = array_values(array_diff(array_keys($rows[0]), $skip));
    $head = "INSERT INTO `$t` (" . implode(', ', array_map(fn($c) => "`$c`", $cols)) . ") VALUES\n";
    $out = '';
    foreach (array_chunk($rows, $chunk) as $part) {
        $lines = [];
        foreach ($part as $r) $lines[] = ' (' . implode(', ', array_map(fn($c) => lit($db, $r[$c]), $cols)) . ')';
        $out .= $head . implode(",\n", $lines) . ";\n";
    }
    return $out;
}

$sections = [
    'AUTH & CONFIG' => [
        'intro' => "-- Roles: super_admin, officer, report_viewer, employee. An officer belongs to\n-- one department and reaches what that department's level reaches.",
        'tables' => ['roles', 'users', 'settings', 'audit_logs'],
    ],
    'GEOGRAPHY — District -> Tehsil -> Department' => [
        'intro' => "-- Level 1  districts     (grouped by division)\n"
                 . "-- Level 2  tehsils       each belongs to one district\n"
                 . "-- Level 3  departments   each sits in one district and, unless it is at district\n"
                 . "--                        headquarters, in one tehsil OF THAT district. The\n"
                 . "--                        composite key (tehsil_id, district_id) -> tehsils(id, district_id)\n"
                 . "--                        makes a tehsil from another district impossible.\n"
                 . "-- departments.level is the authority the office carries:\n"
                 . "--   district    the whole district (Deputy Commissioner Office) — no tehsil\n"
                 . "--   tehsil      its tehsil (Assistant Commissioner Office)      — tehsil required\n"
                 . "--   department  only itself (a single authority)",
        'tables' => ['districts', 'tehsils', 'departments', 'signing_profiles'],
    ],
    'MASTER DATA  (all share: is_active, is_deleted, sort_order)' => [
        'intro' => "-- Offices & sites (posting_locations) belong to a department; their district and\n-- tehsil are the department's and are not stored twice.",
        'tables' => ['pay_scales', 'designations', 'leave_types', 'award_types', 'punishment_types', 'inquiry_types',
                     'posting_locations', 'employment_types', 'blood_groups', 'marital_statuses', 'deputation_orgs',
                     'reinstatement_types', 'sanctioned_posts'],
    ],
    'EMPLOYEES' => [
        'intro' => "-- An employee belongs to a department; tehsil and district follow from it.",
        'tables' => ['employees', 'employee_family', 'employee_documents'],
    ],
    'SERVICE RECORDS  (append-only; never edited; super admin may VOID)' => [
        'intro' => '',
        'tables' => ['postings', 'promotions', 'increments', 'leaves', 'punishments', 'reinstatements', 'awards',
                     'deputations', 'exits', 'rejoinings', 'inquiries', 'order_documents', 'timeline_events',
                     'notifications', 'notification_log'],
    ],
];

$all = array_column(q($db, 'SHOW TABLES'), 'Tables_in_smart_hr_build');
$listed = array_merge(...array_map(fn($s) => $s['tables'], array_values($sections)));
$missing = array_diff($all, $listed);
if ($missing) { fwrite(STDERR, 'Unlisted tables: ' . implode(', ', $missing) . "\n"); exit(1); }

$bar = str_repeat('-', 69);
$s  = "-- =====================================================================\n";
$s .= "--  Smart HR System - Database Schema (3.0.0)\n";
$s .= "--  MySQL 8.x / MariaDB 10.5+   |   Charset utf8mb4\n";
$s .= "--  Contains: structure + essential seed (roles, users, settings, the\n";
$s .= "--  Province of Punjab — 41 districts, their tehsils and departments —\n";
$s .= "--  and the other masters). Demo employees & service records are in\n";
$s .= "--  demo_data.sql. Existing 2.x installs upgrade with\n";
$s .= "--  migrations/2026_10_01_district_tehsil_department.sql instead.\n";
$s .= "-- =====================================================================\n";
$s .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET time_zone = '+05:00';\n";
foreach ($sections as $title => $sec) {
    $s .= "\n-- $bar\n-- $title\n-- $bar\n";
    if ($sec['intro']) $s .= $sec['intro'] . "\n";
    foreach ($sec['tables'] as $t) $s .= "\n" . ddl($db, $t);
}
$s .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";

$s .= "\n-- =====================================================================\n-- ESSENTIAL SEED DATA\n-- =====================================================================\n";
$s .= "SET FOREIGN_KEY_CHECKS = 0;\n";
$s .= "\n-- Roles. An officer's authority is the level of their department.\n" . inserts($db, 'roles');
$s .= "\n-- Province of Punjab (generated by database/tools/punjab_geography.py).\n";
$s .= inserts($db, 'districts') . inserts($db, 'tehsils') . inserts($db, 'departments');
$s .= "\n-- Default sign-ins (plain-text passwords — change them after installing):\n"
    . "--   superadmin / admin123   Super Admin\n"
    . "--   dc / dc123              officer, Deputy Commissioner Office Nankana Sahib (district level)\n"
    . "--   ac / ac123              officer, Assistant Commissioner Office Nankana Sahib (tehsil level)\n"
    . "--   reports / reports123    Report Viewer\n";
$s .= inserts($db, 'users', 'role_id <> (SELECT id FROM roles WHERE name = "employee")', ['employee_id', 'last_login_at', 'last_login_ip', 'created_at', 'updated_at']);
$s .= "\n" . inserts($db, 'settings', "setting_key NOT IN ('last_daily_check')", ['updated_by', 'updated_at']);
foreach (['pay_scales', 'designations', 'leave_types', 'award_types', 'punishment_types', 'inquiry_types', 'employment_types',
          'blood_groups', 'marital_statuses', 'deputation_orgs', 'reinstatement_types'] as $t) {
    $s .= "\n" . inserts($db, $t, '1=1', ['created_at', 'updated_at']);
}
$s .= "\n-- Offices & sites, each under its department.\n" . inserts($db, 'posting_locations', '1=1', ['created_at', 'updated_at']);
$s .= "\n-- Sanctioned strength of the DC Office Nankana Sahib.\n" . inserts($db, 'sanctioned_posts', '1=1', ['created_at', 'updated_at']);
$s .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
file_put_contents("$out/schema.sql", $s);

/* ---------------- demo data ---------------- */
$d  = "-- Smart HR System - Demo data (DC Office Nankana Sahib)\n";
$d .= "-- Generated " . date('Y-m-d H:i:s') . " from the 3.0.0 structure.\n";
$d .= "-- Load AFTER schema.sql. Covers: probation, confirmation, promotion, upgradation, transfer,\n";
$d .= "-- deputation & reversion, suspension, reinstatement (with/without back benefits), EOL break,\n";
$d .= "-- LPR, contract expiry, resignation, retirement, medical retirement, death in service, rejoining,\n";
$d .= "-- inquiries. Employees sit in the DC Office, the AC Offices of the tehsils and the Arazi Record Centre.\n\n";
$d .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n";
$demo = ['employees', 'employee_family', 'employee_documents', 'postings', 'promotions', 'increments', 'leaves', 'punishments',
         'reinstatements', 'awards', 'deputations', 'exits', 'rejoinings', 'inquiries', 'order_documents', 'timeline_events',
         'notifications', 'notification_log'];
foreach (array_reverse($demo) as $t) $d .= "DELETE FROM `$t`;\n";
$d .= "DELETE u FROM users u JOIN roles r ON r.id = u.role_id WHERE r.name = 'employee';\n";
foreach ($demo as $t) { $x = inserts($db, $t); if ($x) $d .= "\n" . $x; }
$d .= "\n-- Demo sign-in for every employee: username = CNIC without dashes,\n-- password employee123, to be changed at first sign-in.\n";
$d .= inserts($db, 'users', 'role_id = (SELECT id FROM roles WHERE name = "employee")', ['last_login_at', 'last_login_ip', 'updated_at']);
foreach (q($db, 'SELECT id, employee_id FROM users u WHERE employee_id IS NOT NULL AND role_id <> (SELECT id FROM roles WHERE name = "employee")') as $u) {
    $d .= "\n-- An officer can also be on the register.\nUPDATE users SET employee_id = " . (int)$u['employee_id'] . " WHERE id = " . (int)$u['id'] . ";\n";
}
$d .= "\nSET FOREIGN_KEY_CHECKS = 1;\n";
file_put_contents("$out/demo_data.sql", $d);
echo "schema.sql " . strlen($s) . " bytes, demo_data.sql " . strlen($d) . " bytes\n";
