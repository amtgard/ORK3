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
     * Returns '' when there is no Host header at all (CLI/test render) —
     * see the "empty host" note in the class header. Callers must treat ''
     * as "cannot build an absolute URL", never as a prefix to concatenate
     * blindly into a canonical.
     *
     * @return string e.g. 'https://ork.amtgard.com', or ''
     */
    public static function Origin()
    {
        $host = (string) ($_SERVER['HTTP_HOST'] ?? '');
        if ($host === '') {
            return '';
        }
        $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || ((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
        return ($https ? 'https://' : 'http://') . $host;
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
