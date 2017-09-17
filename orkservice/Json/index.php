<?php
/*******************************************************************************

			
*******************************************************************************/

	if(empty($_SERVER['HTTPS']) || $_SERVER['HTTPS'] == "off"){
			$redirect = 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
			header('HTTP/1.1 301 Moved Permanently');
			header('Location: ' . $redirect);
			exit();
	}

	if (!defined('CONFIG')) {
		require_once("../svcutil.php"); 
	} else { 
		require_once(DIR_SERVICE.'svcutil.php');
		$DONOTWEBSERVICE = true;
	}

	require_once(DIR_SERVICE . 'Common.SOAP.php');
	
	require_once(DIR_SERVICE.'Common.definitions.php');
		
	$J = new JsonServer(array(
		'Administration',
		'Attendance',
		'Authorization',
		'Award',
		'Calendar',
		'DataSet',
		'Event',
		'Game',
		'Heraldry',
		'Kingdom',
		'Map',
		'Park',
		'Player',
		'Principality',
		'Report',
		'SearchService',
		'Tournament',
		'Treasury',
		'Unit'
	));
	$J->JsonHeader();
	$J->RunServer();
	
?>