<?php

if (getenv('ENVIRONMENT') == 'DEV') {
	include_once( dirname( __FILE__ ) . '/config.dev.php');
} else {
	include_once( dirname( __FILE__ ) . '/config.php' );
}

// System Setup

if ( isset( $LOG ) )
	return;

$LOG;
$DB;

if ( !isset( $DB ) ) {
	$DB = new yapo_mysql( DB_HOSTNAME, DB_DATABASE, DB_USERNAME, DB_PASSWORD );
}

if ( !DO_SETUP ) {
	if ( !isset( $LOG ) ) {
		$LOG = new Log();
	}

	$classes = scandir( DIR_SYSTEMLIB );
	foreach ( $classes as $k => $file ) {
		$path_parts = pathinfo( $file );
		if ( 'php' == $path_parts[ 'extension' ] ) {
			require_once( DIR_SYSTEMLIB . $path_parts[ 'basename' ] );
		}
	}

	$classes = scandir( DIR_ORK3 );
	$GLOBALS[ 'ORK3_SYSTEM' ] = [ ];
	require_once( DIR_ORK3 . 'class.Ork3.php' );
	$ORK3 = new Ork3();
	$LIB = new Ork3LibContainer();
	foreach ( $classes as $k => $file ) {
		$path_parts = pathinfo( $file );
		if ( 'php' == $path_parts[ 'extension' ] ) {
			require_once( DIR_ORK3 . $path_parts[ 'basename' ] );
		}
	}
	foreach ( $classes as $k => $file ) {
		$path_parts = pathinfo( $file );
		if ( 'php' == $path_parts[ 'extension' ] ) {
			$class = explode( '.', $path_parts[ 'basename' ] );
			$class_name = $class[ 1 ];
			$chad_name = strtolower( $class_name );
			if ( 'php' != $class_name && 'Ork3' != $class_name ) {
				$LIB->$chad_name = new $class_name();
			}
		}
	}
	Ork3::$Lib = $LIB;
	Ork3::$Lib->Log = $LOG;
}
