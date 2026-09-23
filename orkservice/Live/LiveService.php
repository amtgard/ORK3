<?php

if (!defined('CONFIG')) {
    require_once('../svcutil.php');
} else {
    require_once(DIR_SERVICE . 'svcutil.php');
    $DONOTWEBSERVICE = true;
}

define('LIVE_SERVICE', 'Live');

$server = new JSONService();

require_once(DIR_SERVICE . 'Common.definitions.php');
require_once(LIVE_SERVICE . 'Service.registration.php');

if (!isset($DONOTWEBSERVICE)) {
    $server->Service();
    exit();
}
