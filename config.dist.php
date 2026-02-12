<?php

date_default_timezone_set('America/Chicago');
define('CONFIG', true);

error_reporting(E_ALL);
ini_set('display_errors', '1');

// HTTP
define('ORK_DIST_NAME', 'ork');

define('HTTP_SERVICE', 'http://' . $_SERVER['HTTP_HOST'] . '/' . ORK_DIST_NAME . '/orkservice/');
define('HTTP_UI', 'http://' . $_SERVER['HTTP_HOST'] . '/' . ORK_DIST_NAME . '/orkui/');
define('HTTP_UI_REMOTE', 'http://' . $_SERVER['HTTP_HOST'] . '/' . ORK_DIST_NAME . '/orkui/');
define('HTTP_TEMPLATE', HTTP_UI . 'template/');
define('HTTP_ASSETS', 'http://' . $_SERVER['HTTP_HOST'] . '/' . ORK_DIST_NAME . '/assets/');
define('HTTP_WAIVERS', HTTP_ASSETS . 'waivers/');
define('HTTP_HERALDRY', HTTP_ASSETS . 'heraldry/');
define('HTTP_PLAYER_IMAGE', HTTP_ASSETS . 'players/');
define('HTTP_PLAYER_HERALDRY', HTTP_HERALDRY . 'player/');
define('HTTP_PARK_HERALDRY', HTTP_HERALDRY . 'park/');
define('HTTP_KINGDOM_HERALDRY', HTTP_HERALDRY . 'kingdom/');
define('HTTP_EVENT_HERALDRY', HTTP_HERALDRY . 'event/');
define('HTTP_UNIT_HERALDRY', HTTP_HERALDRY . 'unit/');

define('HERALDRY_PLAYER_DEFAULT', HTTP_PLAYER_HERALDRY . '000000.jpg');
define('HERALDRY_PARK_DEFAULT', HTTP_PARK_HERALDRY . '00000.jpg');
define('HERALDRY_KINGDOM_DEFAULT', HTTP_KINGDOM_HERALDRY . '0000.jpg');
define('HERALDRY_EVENT_DEFAULT', HTTP_EVENT_HERALDRY . '00000.jpg');
define('HERALDRY_UNIT_DEFAULT', HTTP_UNIT_HERALDRY . '00000.jpg');

// HTTPS
define('HTTPS_SERVICE', "https://{$_SERVER[HTTP_HOST]}/" . ORK_DIST_NAME . '/orkservice/');
define('HTTPS_UI', "https://{$_SERVER[HTTP_HOST]}/" . ORK_DIST_NAME . '/orkui/');

// DIR
define('DIR_BASENAME', dirname(__FILE__) . '/');
define('DIR_SERVICE', DIR_BASENAME . "orkservice/");
define('DIR_UI', DIR_BASENAME . "orkui/");
define('DIR_SYSTEM', DIR_BASENAME . "system/");
define('DIR_ASSETS', DIR_BASENAME . "assets/");
define('DIR_TMP', DIR_ASSETS . 'tmp/');
define('DIR_WAIVERS', DIR_ASSETS . "waivers/");
define('DIR_HERALDRY', DIR_ASSETS . "heraldry/");
define('DIR_PLAYER_IMAGE', DIR_ASSETS . "players/");
define('DIR_PLAYER_HERALDRY', DIR_HERALDRY . "player/");
define('DIR_PARK_HERALDRY', DIR_HERALDRY . "park/");
define('DIR_KINGDOM_HERALDRY', DIR_HERALDRY . "kingdom/");
define('DIR_EVENT_HERALDRY', DIR_HERALDRY . "event/");
define('DIR_UNIT_HERALDRY', DIR_HERALDRY . "unit/");
define('DIR_CACHE', DIR_BASENAME . 'cache/');

// System
define('DIR_LIB', DIR_SYSTEM . 'lib/');
define('DIR_ORK3', DIR_LIB . 'ork3/');
define('DIR_SYSTEMLIB', DIR_LIB . 'system/');
define('DIR_LOGS', DIR_SYSTEM . 'logs/');

// UI
define('DIR_CONTROLLER', DIR_UI . 'controller/');
define('DIR_LANGUAGE', DIR_UI . 'language/');
define('DIR_TEMPLATE', DIR_UI . 'template/');
define('DIR_VIEW', DIR_UI . 'view/');
define('DIR_MODEL', DIR_UI . 'model/');
define('CUSTOM_CSS', HTTP_TEMPLATE . 'default/style/custom.css');

// DB
define('DB_DRIVER', 'mysql');
define('DB_HOSTNAME', 'mysql.amtgard.com');
define('DB_USERNAME', 'DB_USERNAME');
define('DB_PASSWORD', 'DB_PASSWORD');
define('DB_DATABASE', 'DB_DATABASE');
define('DB_PREFIX', 'ork_');

// System Config
define('LOGIN_TIMEOUT', 72 * 60 * 60);
define('APP_STAGE', 'DEV');
define('UI_LOCALITY', 'LOCAL'); // REMOTE
define('ORK3_SERVICE_URL', HTTP_SERVICE);

define('SETUP_ADMIN_EMAIL', 'amtgardinc@gmail.com');
define('DO_SETUP', false);

define('TRACE', false);
define('DUMPTRACE', false);

define('GOOGLE_MAPS_ACCESS_API_KEY', '');
define('SENDGRID_API_KEY', '');
define('BEHOLD_KEY', '');

define('IDP_CLIENT_ID', 'ORK_CLIENT_ID');
define('IDP_CLIENT_SECRET', 'ORK_CLIENT_SECRET');
define('IDP_BASE_URL', 'https://idp.amtgard.com');

// INCLUDE
require_once(DIR_LIB . 'mail.php');
require_once(DIR_LIB . 'Yapo2/class.Yapo.php');

require_once(DIR_SYSTEMLIB . 'class.Log.php');

define('DIR_CACHE', DIR_BASENAME . '');

define('CACHE_ENABLED', false);