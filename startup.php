<?php

/*************************************************************************
 * CANONICAL-HOST GUARD
 *
 * The deployment config (included immediately below) mints HTTP_UI and the
 * rest of the absolute-URL constants out of the raw, client-supplied
 * $_SERVER['HTTP_HOST'], so the Host has to be trusted BEFORE the config
 * runs. The matcher lives in host-guard.php, not here, because the entry
 * points that include a config WITHOUT startup.php (index.php, dbsetup.php,
 * import/*.php) need it too — and because config.dist.php's documented
 * enablement line would otherwise fatal in exactly those entry points.
 *
 * The call below is the pre-config run, which is what picks the allowlist
 * up when it is supplied as the ORK_CANONICAL_HOSTS environment variable
 * (the constant does not exist yet at this point). When the allowlist is
 * defined in the config instead, the config re-runs the guard itself, right
 * after the define and before the URL constants. The function is idempotent
 * and a no-op when no allowlist is configured.
 *************************************************************************/

require_once(dirname(__FILE__) . '/host-guard.php');

ork_enforce_canonical_host();

if (getenv('ENVIRONMENT') == 'TEST') {
    include_once(dirname(__FILE__) . '/config.test.php');
} elseif (getenv('ENVIRONMENT') == 'DEV') {
    include_once(dirname(__FILE__) . '/config.dev.php');
} else {
    include_once(dirname(__FILE__) . '/config.php');
}

function mysql_real_escape_string($str)
{
    return $str;
}

// System Setup

global $DB, $LOG;

if (defined('ORK3_STARTUP_COMPLETE')) {
    return;
}

if (isset($LOG)) {
    return;
}

$LOG;
$DB;

if (!isset($DB)) {
    $DB = new YapoMysql(DB_HOSTNAME, DB_DATABASE, DB_USERNAME, DB_PASSWORD);
}

if (!DO_SETUP) {
    if (!isset($LOG)) {
        $LOG = new Log();
    }

    $classes = scandir(DIR_SYSTEMLIB);
    foreach ($classes as $k => $file) {
        $path_parts = pathinfo($file);
        if ('php' === ($path_parts['extension'] ?? '')) {
            require_once(DIR_SYSTEMLIB . $path_parts['basename']);
        }
    }

    $classes = scandir(DIR_ORK3);
    $GLOBALS['ORK3_SYSTEM'] = [];
    require_once(DIR_ORK3 . 'class.Ork3.php');
    $ORK3 = new Ork3();
    $LIB = new Ork3LibContainer();
    foreach ($classes as $k => $file) {
        $path_parts = pathinfo($file);
        if ('php' === ($path_parts['extension'] ?? '')) {
            require_once(DIR_ORK3 . $path_parts['basename']);
        }
    }
    foreach ($classes as $k => $file) {
        $path_parts = pathinfo($file);
        if ('php' === ($path_parts['extension'] ?? '')) {
            $class = explode('.', $path_parts['basename']);
            $class_name = $class[1];
            $chad_name = strtolower($class_name);
            if ('php' != $class_name && 'Ork3' != $class_name) {
                $LIB->$chad_name = new $class_name();
            }
        }
    }
    Ork3::$Lib = $LIB;
    Ork3::$Lib->Log = $LOG;
}

if (!defined('ORK3_STARTUP_COMPLETE')) {
    define('ORK3_STARTUP_COMPLETE', true);
}
