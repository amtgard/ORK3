<?php

error_reporting(E_ALL ^ E_STRICT ^ E_NOTICE ^ E_WARNING);

include_once("../startup.php");
define('UIR', HTTP_UI_REMOTE.'index.php?Route=');

/***********************************************
	Simple MVC Site

	Requests are generated in controller/request/action format via GET or POST
	Additional parameters are labeled as usual
	
	1. The system searches for the controller in .../controller and instantiates the class as $C
	2. $C->$request($action, $additional_params) is called on the controller
	3. $C->view() is called, with the default template being .../template/<default|selected template set>/<optional kingdom template/<controller>[_request[_action]].tpl
		1. view() instantes a View class from .../view as {$controller}_{$view} to which all of the builtin controller $__data is passed prior to output
		2. view() wraps the local Controller template (.../template/<default|selected template set>/<optional kingdom template/{controller}.tpl) around the output and hands it back to the Controller
		3. view() substitutes the selected language strings .../language/controller.lang, .../language/controller_request.lang, .../language/controller_request_action.lang
	4. The output from view() is passed to the framework prettifier for disply
***********************************************/
$DONOTWEBSERVICE = true;

$Settings = new Settings();
$Session = new Session();
$Request = new Request();

if (empty($_REQUEST['Route'])) {
	$_REQUEST['Route'] = '';
}
$route = explode('/',$_REQUEST["Route"]);
logtrace('Index: Route', $route);
Ork3::$Lib->session->times['Route'] = time();
if (file_exists(DIR_CONTROLLER.'controller.'.trim($route[0]).'.php')) {
	include_once(DIR_CONTROLLER.'controller.'.trim($route[0]).'.php');
	$class = 'Controller_'.trim($route[0]);
	$call = trim($route[1]);
	$action = trim($route[2]);
	if (count($route) == 1) {
		logtrace("Index: Route(1): $class(index)", null);

		$classMethod = new ReflectionMethod($class, "index");
		if (count($classMethod->getParameters()) > 0) {
			header("Location: " . UIR);
			return;
		}

		$C = new $class("index");
		$C->index();
	} else if (count($route) == 2) {
		logtrace("Index: Route(2): $class($call)", null);

		$classMethod = new ReflectionMethod($class, $call);
		if (count($classMethod->getParameters()) > 0) {
			header("Location: " . UIR);
			return;
		}

		$C = new $class($call);
		$C->$call();
	} else if (count($route) == 3) {
		logtrace("Index: Route(3): $class($call,$action)", null);

		$C = new $class($call,$action);
		$C->$call($action);
	} else if (count($route) > 3) {
		$action = implode('/',array_slice($route,2));
		logtrace("Index: Route(3+): $class($call,$action)", null);

		$C = new $class($call,$action);
		$C->$call($action);
	}
} else {
	$C = new Controller("index");
	$C->index();
}
Ork3::$Lib->session->times['Route Complete'] = time();

$CONTENT = $C->view();

Ork3::$Lib->session->times['Composite'] = time();

echo $CONTENT;

logtrace("Timing Information", Ork3::$Lib->session->times);

if (DUMPTRACE) {
	logtrace('Session',$_SESSION);
	dumplogtrace();
}
?>
