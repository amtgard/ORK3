<?php

include_once("../startup.php");
define('UIR', HTTP_UI_REMOTE . 'index.php?Route=');

ini_set("error_reporting", E_ALL & ~E_DEPRECATED & ~E_NOTICE & ~E_WARNING);

if (isset($_REQUEST['Route']) && $_REQUEST['Route'] === 'Health') {
    $ok = (new Health())->PingDb();
    http_response_code($ok ? 200 : 503);
    if ($_SERVER['REQUEST_METHOD'] !== 'HEAD') {
        header('Content-Type: text/plain');
        echo $ok ? "OK\n" : "DB UNAVAILABLE\n";
    }
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'HEAD') {
    http_response_code(200);
    exit;
}

/***********************************************
 * Simple MVC Site
 *
 * Requests are generated in controller/request/action format via GET or POST
 * Additional parameters are labeled as usual
 *
 * 1. The system searches for the controller in .../controller and instantiates the class as $C
 * 2. $C->$request($action, $additional_params) is called on the controller
 * 3. $C->view() is called, with the default template being .../template/<default|selected template set>/<optional kingdom template/<controller>[_request[_action]].tpl
 * 1. view() instantes a View class from .../view as {$controller}_{$view} to which all of the builtin controller $__data is passed prior to output
 * 2. view() wraps the local Controller template (.../template/<default|selected template set>/<optional kingdom template/{controller}.tpl) around the output and hands it back to the Controller
 * 3. view() substitutes the selected language strings .../language/controller.lang, .../language/controller_request.lang, .../language/controller_request_action.lang
 * 4. The output from view() is passed to the framework prettifier for disply
 ***********************************************/
$DONOTWEBSERVICE = true;

$Settings = new Settings();
$Session = new Session();
$Request = new Request();

if (empty($_REQUEST[ 'Route' ])) {
    $_REQUEST[ 'Route' ] = '';
}

// Redirect legacy index routes to their profile equivalents
$_legacyRedirects = [
    'Park/index/'       => 'Park/profile/',
    'Kingdom/index/'    => 'Kingdom/profile/',
    'Player/index/'     => 'Player/profile/',
    'Attendance/event/' => 'Event/detail/',
];
foreach ($_legacyRedirects as $_old => $_new) {
    if (strncasecmp($_REQUEST['Route'], $_old, strlen($_old)) === 0) {
        $remainder = substr($_REQUEST['Route'], strlen($_old));
        header('Location: ' . UIR . $_new . $remainder, true, 301);
        exit;
    }
}

// Redirect Event/index/{id} to the kingdom event attendance report with the event name as filter
if (preg_match('#^Event/index/(\d+)#i', $_REQUEST['Route'], $_m)) {
    $_event_id = (int)$_m[1];
    $_summary = (new Event())->GetEventSummaryForRedirect($_event_id);
    if ($_summary['KingdomId'] > 0 && $_summary['Name'] !== '') {
        header('Location: ' . UIR . 'Reports/event_attendance/Kingdom/' . $_summary['KingdomId'] . '&filter=' . rawurlencode($_summary['Name']), true, 302);
        exit;
    }
}

$route = explode('/', $_REQUEST[ "Route" ]);
logtrace('Index: Route', $route);
$Session->times['Route'] = time();
if (file_exists(DIR_CONTROLLER . 'controller.' . trim($route[ 0 ]) . '.php')) {
    include_once(DIR_CONTROLLER . 'controller.' . trim($route[ 0 ]) . '.php');
    $class = 'Controller_' . trim($route[ 0 ]);
    $call = trim($route[ 1 ]);
    $action = trim($route[ 2 ]);
    if (count($route) == 1) {
        logtrace("Index: Route(1): $class(index)", null);

        if (required_parameter_count($class, 'index') > 0) {
            header("Location: " . UIR);
            return;
        }

        $C = new $class("index");
        $C->index();
    } elseif (count($route) == 2) {
        logtrace("Index: Route(2): $class($call)", null);

        if (required_parameter_count($class, $call) > 0) {
            header("Location: " . UIR);
            return;
        }

        $C = new $class($call);
        $C->$call();
    } elseif (count($route) == 3) {
        logtrace("Index: Route(3): $class($call,$action)", null);

        $C = new $class($call, $action);
        $C->$call($action);
    } elseif (count($route) > 3) {
        $action = implode('/', array_slice($route, 2));
        logtrace("Index: Route(3+): $class($call,$action)", null);

        $C = new $class($call, $action);
        $C->$call($action);
    }
} else {
    $C = new Controller("index");
    $C->index();
}
$Session->times['Route Complete'] = time();

$CONTENT = $C->view();

$Session->times['Composite'] = time();

echo $CONTENT;

logtrace("Timing Information", $Session->times);

if (DUMPTRACE) {
    logtrace('Session', $_SESSION);
    dumplogtrace();
}

function required_parameter_count($class, $call)
{
    $classMethod = new ReflectionMethod($class, $call);
    $required = 0;
    foreach ($classMethod->getParameters() as $param) {
        if (!$param->isOptional()) {
            $required++;
        }
    }
    return $required;
}
