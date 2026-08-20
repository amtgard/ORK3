<?php

class Session
{
    public function __construct($default_path = true, $path = '')
    {
        $path = $default_path ? str_replace('//', '/', ('/' . ORK_DIST_NAME . '/orkui/')) : $path;
        $server = explode(':', $_SERVER[ 'HTTP_HOST' ])[0];
        session_set_cookie_params(LOGIN_TIMEOUT, $path, $server);
        session_start();

        // Sliding window: the params above only apply when the cookie is first
        // minted, making LOGIN_TIMEOUT an ABSOLUTE cliff — an actively-working
        // user (e.g. mid qual-test) was logged out 72h after login regardless
        // of activity. Re-issue the cookie on every request so the timeout is
        // measured from last activity instead. Pairs with the sliding
        // ork_session.expires on the server side.
        if (isset($_COOKIE[ session_name() ])) {
            setcookie(session_name(), session_id(), time() + LOGIN_TIMEOUT, $path, $server);
        }

        if (!isset($_SESSION[ 'Session_Vars' ])) {
            $_SESSION[ 'Session_Vars' ] = [ ];
        }
    }

    public function __set($name, $value)
    {
        $_SESSION[ 'Session_Vars' ][ $name ] = $value;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $_SESSION[ 'Session_Vars' ])) {
            return $_SESSION[ 'Session_Vars' ][ $name ];
        }
    }

    public function __unset($name)
    {
        if (array_key_exists($name, $_SESSION[ 'Session_Vars' ])) {
            unset($_SESSION[ 'Session_Vars' ][ $name ]);
        }
    }

    public function __isset($name)
    {
        if (array_key_exists($name, $_SESSION[ 'Session_Vars' ])) {
            return true;
        }
        return false;
    }

    public function store($name, $value = null)
    {

    }
}
