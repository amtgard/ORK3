<?php

/*******************************************************************************

			
*******************************************************************************/

	if (!defined('CONFIG')) {
		require_once("../svcutil.php"); 
	} else {
		require_once(DIR_SERVICE.'svcutil.php');
		$DONOTWEBSERVICE = true;
	}
	
	define("PARK_SERVICE","Park");
	define("AUTH_SERVICE","Authorization");
	define("KINGDOM_SERVICE","Kingdom");
	define("TREASURY_SERVICE","Treasury");

	$namespace = HTTP_SERVICE.PARK_SERVICE.'/'.PARK_SERVICE.'Service.php?wsdl';
	$server = new soap_server();
	$server->debug_flag = false;
	$server->configureWSDL(PARK_SERVICE.'Service', $namespace);
	$server->wsdl->schemaTargetNamespace = $namespace;
	
	require_once(DIR_SERVICE . 'Common.SOAP.php');
	
	require_once(PARK_SERVICE."Service.definitions.php");
	require_once(PARK_SERVICE."Service.function.php");
	require_once(PARK_SERVICE."Service.registration.php");
	require_once(DIR_SERVICE.'Common.definitions.php');
	
	require_once(DIR_SERVICE.AUTH_SERVICE.'/'.AUTH_SERVICE."Service.definitions.php");
	require_once(DIR_SERVICE.AUTH_SERVICE.'/'.AUTH_SERVICE."Service.function.php");
	require_once(DIR_SERVICE.KINGDOM_SERVICE.'/'.KINGDOM_SERVICE."Service.definitions.php");
	require_once(DIR_SERVICE.KINGDOM_SERVICE.'/'.KINGDOM_SERVICE."Service.function.php");
	/*
	require_once(DIR_SERVICE.TREASURY_SERVICE.'/'.TREASURY_SERVICE."Service.definitions.php");
	require_once(DIR_SERVICE.TREASURY_SERVICE.'/'.TREASURY_SERVICE."Service.function.php");
	*/

	if (!isset($DONOTWEBSERVICE)) {

		$HTTP_RAW_POST_DATA = isset($GLOBALS['HTTP_RAW_POST_DATA'])
			? $GLOBALS['HTTP_RAW_POST_DATA'] : '';
		$server->service($HTTP_RAW_POST_DATA);
		exit();
	}
?>
