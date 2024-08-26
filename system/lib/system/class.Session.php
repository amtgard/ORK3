<?php

class Session
{
	function __construct( $default_path = true, $path = '' )
	{
		$path = $default_path ? str_replace('//', '/', ( '/' . ORK_DIST_NAME . '/orkui/' )) : $path;
		$server = explode(':', $_SERVER[ 'HTTP_HOST' ])[0];
		session_set_cookie_params( LOGIN_TIMEOUT, $path, $server );
		session_start();
    
		if ( !isset( $_SESSION[ 'Session_Vars' ] ) ) $_SESSION[ 'Session_Vars' ] = [ ];
	}

	function __set( $name, $value )
	{
		$_SESSION[ 'Session_Vars' ][ $name ] = $value;
	}

	function __get( $name )
	{
		if ( array_key_exists( $name, $_SESSION[ 'Session_Vars' ] ) ) {
			return $_SESSION[ 'Session_Vars' ][ $name ];
		}
	}

	function __unset( $name )
	{
		if ( array_key_exists( $name, $_SESSION[ 'Session_Vars' ] ) ) {
			unset( $_SESSION[ 'Session_Vars' ][ $name ] );
		}
	}

	function __isset( $name )
	{
		if ( array_key_exists( $name, $_SESSION[ 'Session_Vars' ] ) ) return true;
		return false;
	}

	function store( $name, $value = null )
	{

	}
}
