<?php
/**
 * Smart HR System - Central Configuration
 * ---------------------------------------
 * This is the ONLY file you need to edit when deploying.
 * Every internal link, asset path, redirect and form action is built
 * from BASE_URL, so the app works from any domain or sub-directory.
 */
declare(strict_types=1);

/* ---------- Application ---------- */
define('APP_NAME',    'Smart HR System');
define('APP_VERSION', '3.2.0');
define('ORG_NAME',    'DC Office Nankana Sahib');
define('APP_ENV',     getenv('APP_ENV') ?: 'production');   // local | production

/* ---------- Base URL (NO trailing slash) ----------
 * Examples:
 *   http://localhost:8080
 *   https://example.com
 *   https://example.com/hr
 */
define('BASE_URL', 'http://localhost:8080');

/* ---------- Database ---------- */
define('DB_HOST', APP_ENV === 'local' ? 'db' : 'localhost');
define('DB_PORT', 3306);
define('DB_NAME', 'smart_hr');
define('DB_USER', 'smart_hr');
define('DB_PASS', 'smart_hr');

/* ---------- Paths (server side, do not edit) ---------- */
define('ROOT_PATH',    __DIR__);
define('UPLOAD_PATH',  ROOT_PATH . '/uploads');
define('BACKUP_PATH',  ROOT_PATH . '/storage/backups');

/* ---------- Misc ---------- */
define('SESSION_NAME',      'smarthr_session');
define('SESSION_LIFETIME',  60 * 60 * 8);         // 8 hours
define('MAX_UPLOAD_MB',     10);
define('DEFAULT_RETIREMENT_AGE', 60);             // Punjab superannuation age

date_default_timezone_set('Asia/Karachi');

if (APP_ENV === 'local') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
}
