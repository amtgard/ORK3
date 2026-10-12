<?php

/*************************************************************************
 * CmsMeta — canonical + Open Graph metadata assembly for the PUBLIC CMS
 * surfaces (the front door, the global CMS pages/blog, and the per-org
 * sites).
 *
 * Every public renderer publishes a $PageMeta array that default.theme
 * turns into <link rel="canonical"> + <meta property="og:*">. Before this
 * class each controller hand-rolled that array literal and its own copy of
 * the request-origin derivation, so the six keys and the http/https
 * detection drifted independently across five files.
 *
 * Pure logic: no DB access, so this does NOT extend Ork3 (CmsSanitizer is
 * the precedent). All entry points are static.
 *
 * WHAT THIS CLASS DELIBERATELY DOES NOT DO
 *  - It does not decide the og:title FALLBACK. A global CMS page falls back
 *    to the application brand; an org-site page falls back to that site's
 *    NAME. Those are different products speaking to different audiences, so
 *    the fallback stays at the call site and is passed in already-resolved.
 *  - It does not invent an origin when the request has no Host header (CLI
 *    renders, test harnesses). Origin() returns '' and Absolutize() then
 *    leaves the URL root-relative — callers that need a canonical suppress
 *    it themselves. Silently substituting a configured hostname here would
 *    publish a canonical that points at the wrong deployment.
 *
 * Usage:
 *   $this->data['PageMeta'] = CmsMeta::Build(array('canonical' => $url, ...));
 *   $abs = CmsMeta::Absolutize($mediaUrl, CmsMeta::Origin());
 *************************************************************************/

class CmsMeta
{
    /**
     * The exact $PageMeta key set default.theme reads, with the neutral
     * default for each. Declared in emission order so the built array keeps
     * the shape the templates (and the surface snapshots) already expect.
     *
     * og_sitename defaults to '' rather than a brand: the brand literal is
     * Controller::APP_BRAND, and only the Amtgard-level surfaces use it —
     * an org site passes its own site name.
     */
    private static $DEFAULTS = array(
        'canonical'   => '',
        'og_type'     => 'website',
        'og_title'    => '',
        'og_desc'     => '',
        'og_image'    => '',
        'og_sitename' => '',
    );

    /**
     * Build a $PageMeta array: the canonical key set, with the caller's
     * overrides applied. Unknown keys in $overrides are ignored so a typo
     * cannot quietly add a tag default.theme never emits.
     *
     * @param array $overrides subset of the keys above (already resolved —
     *                         fallbacks belong to the caller, see the header)
     * @return array{canonical:string,og_type:string,og_title:string,og_desc:string,og_image:string,og_sitename:string}
     */
    public static function Build(array $overrides = array())
    {
        $meta = self::$DEFAULTS;
        foreach ($meta as $key => $_default) {
            if (array_key_exists($key, $overrides)) {
                $meta[$key] = (string) $overrides[$key];
            }
        }
        return $meta;
    }

    /**
     * The scheme+host origin for absolute canonical/OG URLs, derived from
     * the live request (honors the CF/proxy-forwarded proto when present).
     *
     * Host is CLIENT-SUPPLIED, and the front web server is a catch-all
     * (server_name _), so a request carrying `Host: evil.example` would
     * otherwise be echoed straight into every canonical/og:url and into the
     * absolute links of the cached RSS feed — the classic host-header
     * SEO/cache-poisoning vector. The Host is therefore checked against the
     * deployment's canonical-host allowlist, ORK_CANONICAL_HOSTS (a
     * comma/whitespace-separated string or an array of 'host' / 'host:port'
     * entries, defined in the deployment config). A Host outside that list
     * takes the same "cannot build an absolute URL" path as a missing Host.
     *
     * The allowlist is enforced beyond these meta tags, not here:
     * host-guard.php's ork_enforce_canonical_host() replaces a non-canonical
     * Host with the first canonical one, and the config runs it before it
     * mints HTTP_UI/HTTP_ASSETS and the rest of the absolute-URL constants.
     * The matcher below is the same one (ork_host_is_allowed()) so the two
     * cannot drift. Coverage is only as wide as the entry points that reach
     * a guard call, so a deployment whose config predates the require_once/
     * ork_enforce_canonical_host() pair in config.dist.php is protected only
     * where startup.php or index.php runs the pre-config env-var route.
     *
     * When ORK_CANONICAL_HOSTS is NOT defined the legacy derivation is kept
     * (a request-derived origin) so an unconfigured deployment does not
     * silently lose every canonical and feed link — but the poisoning
     * protection is INERT, for the WHOLE application and not merely these
     * meta tags, until the constant is configured. Define it; see the
     * commented block in config.dist.php.
     *
     * Returns '' when there is no Host header at all (CLI/test render) —
     * see the "empty host" note in the class header. Callers must treat ''
     * as "cannot build an absolute URL", never as a prefix to concatenate
     * blindly into a canonical.
     *
     * @return string e.g. 'https://ork.amtgard.com', or ''
     */
    public static function Origin()
    {
        $host = self::NormalizeHost((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host === '' || !self::HostIsAllowed($host)) {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . $host;
    }

    /**
     * Is this request Host one of the deployment's canonical hosts?
     *
     * Configured  → membership in ORK_CANONICAL_HOSTS decides (case-insensitive,
     *               trailing dot and default-port forms normalized away).
     * Unconfigured → the host is accepted only if it is syntactically a
     *               hostname/IP with an optional port; see Origin().
     *
     * Delegates to host-guard.php's ork_host_is_allowed() — the same matcher
     * the pre-config Host guard uses — so there is one implementation, not two.
     *
     * @param string $host raw $_SERVER['HTTP_HOST']
     * @return bool
     */
    private static function HostIsAllowed($host)
    {
        return ork_host_is_allowed($host);
    }

    /**
     * Lowercase a host, drop a trailing dot and the redundant default port so
     * 'Example.COM.:443' and 'example.com' compare equal. Delegates to
     * host-guard.php's ork_normalize_host() (see HostIsAllowed()).
     *
     * @param string $host
     * @return string
     */
    private static function NormalizeHost($host)
    {
        return ork_normalize_host($host);
    }

    /**
     * Resolve a possibly-relative asset URL against an origin for og:image.
     *
     * '' stays ''; an already-absolute http(s) URL is returned untouched;
     * anything else is joined onto $origin with exactly one slash. With an
     * EMPTY origin the result is deliberately the root-relative form
     * ('/media/x.png') — that is what every call site produced before this
     * helper existed, and it keeps a host-less render (CLI/tests) from
     * emitting a bogus absolute URL.
     *
     * @param mixed  $url
     * @param string $origin from Origin()
     * @return string
     */
    public static function Absolutize($url, $origin)
    {
        $url = (string) $url;
        if ($url === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $url)) {
            return $url;
        }
        return (string) $origin . '/' . ltrim($url, '/');
    }
}
