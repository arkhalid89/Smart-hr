<?php
/**
 * Pure-PHP MySQL dump (no mysqldump binary needed - shared hosting safe).
 * Produces a .sql file compatible with phpMyAdmin / mysql CLI import.
 */
declare(strict_types=1);

function backup_generate_sql(bool $withData = true): string
{
    $db = db();
    $out = "-- " . APP_NAME . " database backup\n-- Database: " . DB_NAME . "\n-- Generated: " . date('Y-m-d H:i:s') . " by " . (current_user()['username'] ?? 'system') . "\n\n";
    $out .= "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\nSET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n\n";
    $tables = [];
    $res = $db->query('SHOW TABLES');
    while ($row = $res->fetch_row()) $tables[] = $row[0];

    foreach ($tables as $t) {
        $create = $db->query("SHOW CREATE TABLE `$t`")->fetch_row()[1];
        $out .= "-- ---------------------------------------------------------\n-- Table: $t\n-- ---------------------------------------------------------\n";
        $out .= "DROP TABLE IF EXISTS `$t`;\n$create;\n\n";
        if (!$withData) continue;
        $rs = $db->query("SELECT * FROM `$t`", MYSQLI_USE_RESULT);
        $batch = [];
        $cols = null;
        while ($r = $rs->fetch_assoc()) {
            if ($cols === null) $cols = '`' . implode('`,`', array_keys($r)) . '`';
            $vals = [];
            foreach ($r as $v) $vals[] = $v === null ? 'NULL' : "'" . $db->real_escape_string((string)$v) . "'";
            $batch[] = '(' . implode(',', $vals) . ')';
            if (count($batch) >= 200) { $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n"; $batch = []; }
        }
        $rs->free();
        if ($batch) $out .= "INSERT INTO `$t` ($cols) VALUES\n" . implode(",\n", $batch) . ";\n";
        $out .= "\n";
    }
    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n-- End of backup\n";
    return $out;
}

/** Save a backup file into storage/backups and return its filename. */
function backup_save(): string
{
    if (!is_dir(BACKUP_PATH)) @mkdir(BACKUP_PATH, 0755, true);
    $name = 'smart_hr_backup_' . date('Ymd_His') . '.sql';
    file_put_contents(BACKUP_PATH . '/' . $name, backup_generate_sql());
    return $name;
}

/**
 * Execute a .sql file (schema / demo / restore). Splits on statement boundaries
 * while respecting quotes, and runs each statement with mysqli.
 */
function sql_run_file(string $path): int
{
    $sql = file_get_contents($path);
    if ($sql === false) throw new RuntimeException("Cannot read $path");
    return sql_run_string($sql);
}
function sql_run_string(string $sql): int
{
    $db = db();
    $count = 0;
    $len = strlen($sql);
    $stmt = '';
    $inStr = false; $strCh = ''; $i = 0;
    while ($i < $len) {
        $ch = $sql[$i];
        if (!$inStr) {
            // skip comments
            if ($ch === '-' && substr($sql, $i, 2) === '--' && (trim($stmt) === '' || true) && ($i + 2 >= $len || ctype_space($sql[$i + 2]))) { $nl = strpos($sql, "\n", $i); if ($nl === false) break; $i = $nl + 1; continue; }
            if ($ch === '#' && trim($stmt) === '') { $nl = strpos($sql, "\n", $i); if ($nl === false) break; $i = $nl + 1; continue; }
            if ($ch === '/' && substr($sql, $i, 2) === '/*') { $end = strpos($sql, '*/', $i); if ($end === false) break; $i = $end + 2; continue; }
            if ($ch === "'" || $ch === '"' || $ch === '`') { $inStr = true; $strCh = $ch; $stmt .= $ch; $i++; continue; }
            if ($ch === ';') {
                $s = trim($stmt);
                if ($s !== '') { $db->query($s); $count++; }
                $stmt = ''; $i++; continue;
            }
            $stmt .= $ch; $i++; continue;
        }
        // inside string
        if ($ch === '\\') { $stmt .= $ch . ($sql[$i + 1] ?? ''); $i += 2; continue; }
        if ($ch === $strCh) { $inStr = false; }
        $stmt .= $ch; $i++;
    }
    $s = trim($stmt);
    if ($s !== '') { $db->query($s); $count++; }
    return $count;
}
