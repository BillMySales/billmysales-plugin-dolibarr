<?php

declare(strict_types=1);

/**
 * Declares, for PHPStan only (never executed), the handful of Dolibarr
 * global constants the plugin references directly. The community stubs
 * package (vendor/caprel/dolibarr-stubs-all, scanned in phpstan.neon)
 * defines these too, deep inside a copy of Dolibarr's real source tree
 * (thousands of files); relying on that scan alone proved unreliable in
 * CI (intermittent "Constant ... not found" errors that never reproduced
 * locally), so the ones this plugin actually uses are declared here as
 * well, small and self-contained.
 */

define('DOL_DOCUMENT_ROOT', __DIR__ . '/vendor/caprel/dolibarr-stubs-all/dolibarr-23');
define('DOL_URL_ROOT', '');
define('DOL_MAIN_URL_ROOT', '');
define('DOL_VERSION', '');
define('MAIN_DB_PREFIX', '');
