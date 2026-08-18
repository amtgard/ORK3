<?php

if (getenv('ENVIRONMENT') == 'DEV') {
    include_once('config.dev.php');
} else {
    include_once('config.php');
}

// Both environments send the document root to the UI. This used to sit inside
// the else branch only, so under ENVIRONMENT=DEV a request for "/" loaded the
// config and then output nothing at all — a blank 200. HTTP_UI is defined by
// both config files, so the redirect is valid in either.
header("HTTP/1.1 302 Moved Temporarily");
header("Location: " . HTTP_UI);
