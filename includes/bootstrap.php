<?php
/**
 * Single include for every page:  require_once __DIR__ . '/../includes/bootstrap.php';
 * Loads config, DB, helpers, auth, audit and boots the session.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/geo.php';
require_once __DIR__ . '/employee.php';
require_once __DIR__ . '/notify.php';
require_once __DIR__ . '/service_engine.php';

session_boot();
