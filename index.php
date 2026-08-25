<?php

// HTTP_UI is minted from the client-supplied Host, and it is emitted below
// as a Location: header — a host-header open redirect from "/" unless the
// Host is trusted first. This entry point loads a config WITHOUT startup.php,
// so it requires the guard itself. No-op when no allowlist is configured; the
// config re-runs it after defining ORK_CANONICAL_HOSTS (see config.dist.php),
// which is what covers the constant-configured case as well as the env var.
require_once(dirname(__FILE__) . '/host-guard.php');
ork_enforce_canonical_host();

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
