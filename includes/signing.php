<?php
/**
 * Signing authorities.
 *
 * Every officer signs for their department: the Deputy Commissioner for a
 * district-level office, the Assistant Commissioner for a tehsil-level one,
 * the head of a single-authority department for that department. Each keeps
 * their own signing profile — letterhead lines, the name / designation /
 * office printed under the signature, the default distribution (CC) list,
 * the order heading and a scanned signature — under "My Office & Signature".
 *
 * Who signs an order (service records are published at district or tehsil
 * level):
 *   1. the officer who recorded the service record;
 *   2. otherwise (the entry was made by the Super Admin) an officer of the
 *      tehsil-level office of the employee's tehsil, or of the district-level
 *      office of their district for staff at district headquarters;
 *   3. otherwise the office-wide defaults in System Settings -> Official Orders.
 */
declare(strict_types=1);

/** Where uploaded signatures are kept (closed to direct web access). */
function signing_dir(): string
{
    return ROOT_PATH . '/uploads/signatures';
}

/** A signature file as an inline data URI, or '' when there is none. */
function signing_signature_src(?string $file): string
{
    $file = trim((string)$file);
    if ($file === '') return '';
    $path = signing_dir() . '/' . basename($file);
    if (!is_file($path)) return '';
    $mime = match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
        'jpg', 'jpeg' => 'image/jpeg',
        'gif'         => 'image/gif',
        'webp'        => 'image/webp',
        default       => 'image/png',
    };
    return 'data:' . $mime . ';base64,' . base64_encode((string)file_get_contents($path));
}

/** The office-wide defaults kept in System Settings -> Official Orders. */
function signing_global(): array
{
    return [
        'source'      => 'global',
        'user_id'     => null,
        'letterhead'  => [
            (string)setting('order_letterhead_1', 'THE OFFICE OF'),
            (string)setting('order_letterhead_2', ''),
            (string)setting('order_letterhead_3', ''),
        ],
        'show_emblem' => (string)setting('order_show_emblem', '1') === '1',
        'heading'     => (string)setting('order_prefix', 'ORDER'),
        'name'        => trim((string)setting('order_authority_name', '')),
        'designation' => trim((string)setting('order_authority_designation', '')),
        'office'      => trim((string)setting('order_authority_office', '')),
        'copy_to'     => (string)setting('order_copy_to', ''),
        'footer_note' => trim((string)setting('order_footer_note', '')),
        'signature'   => (string)setting('order_signature_file', ''),
        'signature_width' => (int)setting('order_signature_width', 150),
    ];
}

/**
 * What an officer starts with before editing anything: the letterhead and the
 * designation follow from their department — its head of office and place.
 */
function signing_defaults(array $user): array
{
    $g   = signing_global();
    $dep = !empty($user['department_id'])
        ? db_row('SELECT d.name, d.level, d.head_designation, ds.name AS district, t.name AS tehsil
                    FROM departments d JOIN districts ds ON ds.id = d.district_id LEFT JOIN tehsils t ON t.id = d.tehsil_id
                   WHERE d.id = ?', [(int)$user['department_id']])
        : null;
    $head  = trim((string)($dep['head_designation'] ?? '')) ?: (string)($dep['name'] ?? '');
    $place = $dep ? ($dep['tehsil'] ? 'TEHSIL ' . strtoupper($dep['tehsil']) : 'DISTRICT ' . strtoupper($dep['district'])) : '';

    return [
        'letterhead_1' => 'THE OFFICE OF',
        'letterhead_2' => strtoupper($head),
        'letterhead_3' => $place,
        'show_emblem'  => 1,
        'order_prefix' => $g['heading'] ?: 'ORDER',
        'authority_name'        => (string)($user['full_name'] ?? ''),
        'authority_designation' => $head,
        'authority_office'      => $dep ? trim(($dep['tehsil'] ? $dep['tehsil'] . ', District ' : '') . $dep['district']) : '',
        'copy_to'      => $g['copy_to'],
        'footer_note'  => '',
        'signature_file'  => '',
        'signature_width' => 150,
    ];
}

/** The stored profile of an officer merged over their defaults, or null. */
function signing_profile(int $userId): ?array
{
    $user = db_row('SELECT u.*, r.name AS role FROM users u JOIN roles r ON r.id = u.role_id WHERE u.id = ? AND u.is_deleted = 0', [$userId]);
    if (!$user || $user['role'] !== 'officer' || empty($user['department_id'])) return null;
    $row  = db_row('SELECT * FROM signing_profiles WHERE user_id = ?', [$userId]) ?: [];
    $base = signing_defaults($user);
    foreach ($base as $k => $v) {
        if (array_key_exists($k, $row) && $row[$k] !== null && $row[$k] !== '') $base[$k] = $row[$k];
    }
    /* a deliberately empty CC list or closing sentence is kept empty */
    if ($row && array_key_exists('copy_to', $row) && $row['copy_to'] === '') $base['copy_to'] = '';
    $base['user']   = $user;
    $base['stored'] = (bool)$row;
    return $base;
}

/** Save an officer's own profile. */
function signing_profile_save(int $userId, array $in): void
{
    $clean = fn($v, int $len) => mb_substr(trim((string)$v), 0, $len);
    $data = [
        'letterhead_1' => $clean($in['letterhead_1'] ?? '', 120),
        'letterhead_2' => $clean($in['letterhead_2'] ?? '', 120),
        'letterhead_3' => $clean($in['letterhead_3'] ?? '', 120),
        'show_emblem'  => !empty($in['show_emblem']) ? 1 : 0,
        'order_prefix' => $clean($in['order_prefix'] ?? '', 40),
        'authority_name'        => $clean($in['authority_name'] ?? '', 120),
        'authority_designation' => $clean($in['authority_designation'] ?? '', 120),
        'authority_office'      => $clean($in['authority_office'] ?? '', 160),
        'copy_to'      => trim(str_replace("\r\n", "\n", (string)($in['copy_to'] ?? ''))),
        'footer_note'  => $clean($in['footer_note'] ?? '', 300),
        'signature_width' => max(60, min(400, (int)($in['signature_width'] ?? 150))),
    ];
    if (db_value('SELECT user_id FROM signing_profiles WHERE user_id = ?', [$userId])) {
        db_update('signing_profiles', $data, 'user_id = ?', [$userId]);
    } else {
        db_insert('signing_profiles', $data + ['user_id' => $userId]);
    }
}

/** Store a newly uploaded signature for an officer. Returns an error or null. */
function signing_store_signature(int $userId, array $file): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) return 'The signature upload failed. Please try again.';
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) || !@getimagesize($file['tmp_name'])) {
        return 'The signature must be a PNG, JPG, GIF or WEBP image.';
    }
    if ($file['size'] > 2 * 1024 * 1024) return 'The signature image must be 2 MB or smaller.';
    $dir = signing_dir();
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    $name = 'user' . $userId . '_' . date('YmdHis') . '.' . $ext;
    if (!@move_uploaded_file($file['tmp_name'], $dir . '/' . $name)) {
        return 'Could not save the signature — check that uploads/signatures is writable.';
    }
    $prev = (string)db_value('SELECT signature_file FROM signing_profiles WHERE user_id = ?', [$userId], '');
    if ($prev !== '' && is_file($dir . '/' . basename($prev))) @unlink($dir . '/' . basename($prev));
    if (db_value('SELECT user_id FROM signing_profiles WHERE user_id = ?', [$userId])) {
        db_update('signing_profiles', ['signature_file' => $name], 'user_id = ?', [$userId]);
    } else {
        db_insert('signing_profiles', ['user_id' => $userId, 'signature_file' => $name]);
    }
    return null;
}

function signing_remove_signature(int $userId): void
{
    $prev = (string)db_value('SELECT signature_file FROM signing_profiles WHERE user_id = ?', [$userId], '');
    if ($prev !== '' && is_file(signing_dir() . '/' . basename($prev))) @unlink(signing_dir() . '/' . basename($prev));
    db_exec('UPDATE signing_profiles SET signature_file = NULL WHERE user_id = ?', [$userId]);
}

/** A profile in the shape the order page prints from. */
function signing_as_signer(array $p, string $why): array
{
    return [
        'source'      => 'profile',
        'user_id'     => (int)$p['user']['id'],
        'why'         => $why,
        'letterhead'  => [(string)$p['letterhead_1'], (string)$p['letterhead_2'], (string)$p['letterhead_3']],
        'show_emblem' => (int)$p['show_emblem'] === 1,
        'heading'     => (string)$p['order_prefix'] ?: 'ORDER',
        'name'        => trim((string)$p['authority_name']),
        'designation' => trim((string)$p['authority_designation']),
        'office'      => trim((string)$p['authority_office']),
        'copy_to'     => (string)$p['copy_to'],
        'footer_note' => trim((string)$p['footer_note']),
        'signature'   => (string)$p['signature_file'],
        'signature_width' => (int)$p['signature_width'],
    ];
}

/** An active officer of the office with authority over a place, if any. */
function signing_officer_for(?int $districtId, ?int $tehsilId): ?array
{
    $pick = function (string $level, string $col, int $val): ?int {
        $id = db_value('SELECT u.id FROM users u
                          JOIN roles r ON r.id = u.role_id AND r.name = "officer"
                          JOIN departments d ON d.id = u.department_id AND d.level = ? AND d.' . $col . ' = ?
                         WHERE u.is_active = 1 AND u.is_deleted = 0 ORDER BY u.id LIMIT 1', [$level, $val]);
        return $id ? (int)$id : null;
    };
    if ($tehsilId && ($id = $pick('tehsil', 'tehsil_id', $tehsilId)) && ($p = signing_profile($id))) {
        return signing_as_signer($p, 'Tehsil-level office of the employee\'s tehsil');
    }
    if ($districtId && ($id = $pick('district', 'district_id', $districtId)) && ($p = signing_profile($id))) {
        return signing_as_signer($p, 'District-level office of the employee\'s district');
    }
    return null;
}

/**
 * Who signs the order for a service record — see the file header for the
 * order in which authorities are considered.
 */
function order_signer(array $row, array $emp): array
{
    if (!empty($row['created_by']) && ($p = signing_profile((int)$row['created_by']))) {
        return signing_as_signer($p, 'Issued by the officer who recorded this entry');
    }
    [, $tehsilId, $districtId] = employee_place($emp);
    if ($s = signing_officer_for($districtId ?: null, $tehsilId)) {
        return $s;
    }
    return signing_global() + ['why' => 'Office-wide defaults (System Settings)'];
}
