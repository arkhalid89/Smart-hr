<?php
/**
 * Database layer - MySQLi with prepared statements.
 * All queries in the app go through these helpers.
 */
declare(strict_types=1);

function db(): mysqli
{
    static $conn = null;
    if ($conn instanceof mysqli) {
        return $conn;
    }
    mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
    try {
        $conn = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
        $conn->set_charset('utf8mb4');
        $conn->query("SET time_zone = '+05:00'");
    } catch (mysqli_sql_exception $e) {
        http_response_code(500);
        if (APP_ENV === 'local') {
            die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
        }
        die('Database connection failed. Please check config.php');
    }
    return $conn;
}

/** Build the bind-type string automatically from values. */
function db_types(array $params): string
{
    $types = '';
    foreach ($params as $p) {
        if (is_int($p))         $types .= 'i';
        elseif (is_float($p))   $types .= 'd';
        else                    $types .= 's';
    }
    return $types;
}

/** Prepare + bind + execute. Returns the statement. */
function db_stmt(string $sql, array $params = []): mysqli_stmt
{
    $stmt = db()->prepare($sql);
    if ($params) {
        $stmt->bind_param(db_types($params), ...$params);
    }
    $stmt->execute();
    return $stmt;
}

/** SELECT -> all rows (assoc). */
function db_all(string $sql, array $params = []): array
{
    $stmt = db_stmt($sql, $params);
    $res  = $stmt->get_result();
    $rows = $res ? $res->fetch_all(MYSQLI_ASSOC) : [];
    $stmt->close();
    return $rows;
}

/** SELECT -> single row or null. */
function db_row(string $sql, array $params = []): ?array
{
    $stmt = db_stmt($sql, $params);
    $res  = $stmt->get_result();
    $row  = $res ? $res->fetch_assoc() : null;
    $stmt->close();
    return $row ?: null;
}

/** SELECT -> first column of first row. */
function db_value(string $sql, array $params = [], mixed $default = null): mixed
{
    $row = db_row($sql, $params);
    if (!$row) return $default;
    return array_values($row)[0] ?? $default;
}

/** INSERT/UPDATE/DELETE -> affected rows. */
function db_exec(string $sql, array $params = []): int
{
    $stmt = db_stmt($sql, $params);
    $n = $stmt->affected_rows;
    $stmt->close();
    return $n;
}

/** INSERT from assoc array -> new id. */
function db_insert(string $table, array $data): int
{
    $cols = array_keys($data);
    $sql  = sprintf(
        'INSERT INTO `%s` (%s) VALUES (%s)',
        $table,
        implode(',', array_map(fn($c) => "`$c`", $cols)),
        implode(',', array_fill(0, count($cols), '?'))
    );
    $stmt = db_stmt($sql, array_values($data));
    $id = (int)$stmt->insert_id;
    $stmt->close();
    return $id;
}

/** UPDATE from assoc array with a WHERE clause -> affected rows. */
function db_update(string $table, array $data, string $where, array $whereParams = []): int
{
    $set = implode(', ', array_map(fn($c) => "`$c` = ?", array_keys($data)));
    $sql = "UPDATE `$table` SET $set WHERE $where";
    return db_exec($sql, array_merge(array_values($data), $whereParams));
}

/** Fetch a single row by primary key. */
function db_find(string $table, int $id, string $pk = 'id'): ?array
{
    return db_row("SELECT * FROM `$table` WHERE `$pk` = ? LIMIT 1", [$id]);
}

function db_begin(): void    { db()->begin_transaction(); }
function db_commit(): void   { db()->commit(); }
function db_rollback(): void { db()->rollback(); }

/** Escape identifier-safe strings for dynamic ORDER BY / column names. */
function db_ident(string $name): string
{
    return preg_replace('/[^a-zA-Z0-9_]/', '', $name);
}

/** Build "IN (?,?,?)" placeholders. */
function db_in(array $values): string
{
    return implode(',', array_fill(0, max(1, count($values)), '?'));
}
