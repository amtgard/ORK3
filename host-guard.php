<?php

/*************************************************************************
 * CANONICAL-HOST GUARD
 *
 * The deployment config MINTS HTTP_SERVICE, HTTP_UI, HTTP_ASSETS,
 * HTTP_HERALDRY and friends out of the raw, client-supplied
 * $_SERVER['HTTP_HOST']. The front web server is a catch-all, so a request
 * carrying `Host: evil.example` would otherwise be echoed into every
 * absolute asset and app URL on the page — not just the two meta tags
 * CmsMeta guards. The Host therefore has to be trusted BEFORE the URL
 * constants are minted, which is why these are plain functions in a file of
 * their own: no class is loaded that early, and the entry points that
 * include a config WITHOUT startup.php (index.php, dbsetup.php,
 * import/*.php) must be able to require just this.
 *
 * CmsMeta::HostIsAllowed()/NormalizeHost() delegate to these same functions
 * so there is exactly ONE matcher in the tree.
 *
 * The allowlist, ORK_CANONICAL_HOSTS, is a comma/whitespace-separated
 * string (or an array) of 'host' / 'host:port' entries. It is normally
 * defined in the deployment config, which requires this file and re-runs
 * the guard itself right after the constant is defined and before the URL
 * constants are minted (see config.dist.php). startup.php and index.php
 * also run it pre-config, which is what picks the allowlist up when it is
 * supplied as the ORK_CANONICAL_HOSTS environment variable instead. The
 * function is idempotent.
 *
 * With NO allowlist configured nothing changes: the legacy request-derived
 * behaviour is kept exactly as-is.
 *************************************************************************/

/**
 * Lowercase a host, drop a trailing dot and the redundant default port so
 * 'Example.COM.:443' and 'example.com' compare equal.
 *
 * The trailing dot belongs to the root label, so it is stripped from the
 * HOST part and not from the end of the string — 'example.com.:8080' has to
 * normalize to 'example.com:8080', not stay as-is.
 *
 * @param string $host
 * @return string
 */
function ork_normalize_host($host)
{
    $host = strtolower(trim((string) $host));

    $port = '';
    if (preg_match('/^(.*?)(:\d+)$/', $host, $m)) {
        // Only a real port: an unbracketed IPv6 literal has colons of its own.
        if (strpos($m[1], ':') === false || substr($m[1], -1) === ']') {
            $host = $m[1];
            $port = ($m[2] === ':80' || $m[2] === ':443') ? '' : $m[2];
        }
    }

    return rtrim($host, '.') . $port;
}

/**
 * The deployment's canonical hosts, normalized, or array() when unconfigured.
 *
 * @return array
 */
function ork_canonical_hosts()
{
    $allowed = null;
    if (defined('ORK_CANONICAL_HOSTS')) {
        $allowed = constant('ORK_CANONICAL_HOSTS');
    } elseif (getenv('ORK_CANONICAL_HOSTS') !== false) {
        $allowed = getenv('ORK_CANONICAL_HOSTS');
    }
    if (is_string($allowed)) {
        $allowed = preg_split('/[\s,]+/', $allowed, -1, PREG_SPLIT_NO_EMPTY);
    }
    if (!is_array($allowed)) {
        return array();
    }

    $hosts = array();
    foreach ($allowed as $candidate) {
        $host = ork_normalize_host((string) $candidate);
        if ($host !== '') {
            $hosts[] = $host;
        }
    }
    return $hosts;
}

/**
 * Is this request Host one of the deployment's canonical hosts?
 *
 * Configured   -> membership in the allowlist decides.
 * Unconfigured -> the host is accepted if it is syntactically a hostname/IP
 *                 with an optional port (the legacy behaviour).
 *
 * @param string $host raw $_SERVER['HTTP_HOST']
 * @return bool
 */
function ork_host_is_allowed($host)
{
    $host = ork_normalize_host($host);
    if ($host === '') {
        return false;
    }

    $allowed = ork_canonical_hosts();
    if ($allowed === array()) {
        return (bool) preg_match('/^[a-z0-9.\-\[\]:]+$/', $host);
    }
    return in_array($host, $allowed, true);
}

/**
 * Replace an untrusted request Host with the deployment's FIRST canonical
 * host, so everything minted from $_SERVER['HTTP_HOST'] downstream is built
 * from a trusted value and the request still serves.
 *
 * No-op when no allowlist is configured, or when there is no Host header at
 * all (CLI/cron), or when the Host is already canonical.
 *
 * @return void
 */
function ork_enforce_canonical_host()
{
    $allowed = ork_canonical_hosts();
    if ($allowed === array()) {
        return;
    }
    $host = (string) (isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : '');
    if ($host === '') {
        return;
    }
    if (!ork_host_is_allowed($host)) {
        $_SERVER['HTTP_HOST'] = $allowed[0];
    }
}
