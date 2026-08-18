<?php
/*
 * frontdoor/_helpers.tpl — shared PLAIN-PHP helpers for the front-door, blog,
 * and org-site templates (extract()+include; never Smarty).
 *
 * Included (function_exists-guarded, so it is safe to include more than once per
 * request) wherever a raw DB date needs human-readable display formatting.
 * render_blocks.tpl includes it once up front so every block partial can rely on
 * it; the standalone blog page templates include it themselves because they
 * format dates OUTSIDE the block-render loop.
 */
if (!function_exists('fdFormatDate')) {
    /**
     * Format a raw date/datetime string for human-readable display.
     *
     * Guards bad input: returns '' when $raw is empty or unparseable (never a
     * raw ISO string or a bogus 1970 epoch), so callers can test the result
     * for '' and suppress the label. Replaces the strtotime()+date() idiom that
     * was copy-pasted across the blog + front-door date sites.
     *
     * @param mixed  $raw a date string (e.g. a DB "Y-m-d H:i:s")
     * @param string $fmt a PHP date() format (e.g. 'M j, Y')
     * @return string the formatted date, or '' on empty/invalid input
     */
    function fdFormatDate($raw, $fmt)
    {
        $raw = (string) $raw;
        if ($raw === '') {
            return '';
        }
        $ts = strtotime($raw);
        return $ts !== false ? date($fmt, $ts) : '';
    }
}

if (!function_exists('fdClampLimit')) {
    /**
     * Clamp an authored "how many items to show" field to a usable count.
     *
     * Replaces the identical read-cast-floor-ceiling idiom that every list block
     * (blog_feed, events_feed, kingdom_events/officers/parks, kingdoms_teaser,
     * park_events/meeting/officers) had copy-pasted inline.
     *
     * Semantics are EXACTLY the previous inline behaviour, including the two
     * non-obvious bits:
     *  - a value below 1 falls back to $default, NOT to 1. A cleared number input
     *    arrives as 0/''/null, and slicing to an empty grid under a live heading
     *    is worse than showing the default count.
     *  - $max === null means "no upper bound", which is what events_feed does
     *    today (it is the one list block with no ceiling). Do not invent one:
     *    the clamp bounds define the enumerated render-cache key space.
     *
     * Callers pass the RAW field (e.g. $blockFields['limit'] ?? null); the (int)
     * cast here reproduces both prior read idioms — `isset(..) ? (int)$v : $d`
     * and `(int)($v ?? $d)` — because (int) null is 0, which trips the < 1 floor.
     *
     * @param mixed    $raw     the authored field value, unvalidated
     * @param int      $default fallback when $raw is absent/blank/non-positive
     * @param int|null $max     inclusive upper bound, or null for no ceiling
     * @return int a positive item count
     */
    function fdClampLimit($raw, $default, $max = null)
    {
        $n = (int) $raw;
        if ($n < 1) {
            return (int) $default;
        }
        if ($max !== null && $n > (int) $max) {
            return (int) $max;
        }
        return $n;
    }
}

if (!function_exists('fdSiteInternalHref')) {
    /**
     * Re-point a 'Page/view/{slug}' href onto THIS site's own
     * 'Site/page/{siteSlug}/{slug}' route, at RENDER time.
     *
     * Mirrors org_header.tpl's $orgHref rewrite, which already does exactly
     * this for CmsNav-sourced nav links — CmsNav resolves page links to the
     * GLOBAL 'Page/view/' form, and org_header.tpl re-points them onto this
     * site's own route using the CURRENT $SiteSlug on every render. That is
     * why nav survives a site-slug rename for free (CmsSite::UpdateSite()
     * allows editing 'slug') and a block-authored href field otherwise would
     * not: seeding an already-resolved 'Site/page/{slug}/...' href bakes in
     * whatever slug existed at SEED time, and nothing re-visits already-seeded
     * block content when an officer later renames their site.
     *
     * Seeding the stable 'Page/view/{slug}' form instead (CmsSite::
     * _sitePageHref()) and rewriting it HERE on every render, using whatever
     * $SiteSlug is current for THIS request, means a rename fixes the link
     * everywhere at once — the same guarantee nav already has. First consumer:
     * steps.tpl's CTA (the park starter's "Your First Day" → New Players link).
     *
     * Only ever rewrites the exact prefix this codebase itself seeds/emits —
     * the block editor's href fields are always free text (no internal-page
     * picker exists), so an officer's own authored href can't realistically
     * collide with this prefix. Anything that doesn't match passes through
     * unchanged, including every existing kingdom-site authored link.
     *
     * @param string $href     the stored/authored href
     * @param string $siteSlug the CURRENT site slug for this render (the
     *                         $SiteSlug already in scope wherever a block
     *                         partial renders inside the org site shell); ''
     *                         when unresolved (e.g. a CMS preview outside the
     *                         site shell), in which case $href passes through
     *                         unchanged — the same graceful degrade the seed
     *                         used before render-time resolution existed.
     * @return string
     */
    function fdSiteInternalHref($href, $siteSlug)
    {
        $href     = (string) $href;
        $siteSlug = (string) $siteSlug;
        if ($siteSlug === '') {
            return $href;
        }
        $uir        = defined('UIR') ? UIR : 'index.php?Route=';
        $pagePrefix = $uir . 'Page/view/';
        if (strpos($href, $pagePrefix) === 0) {
            return $uir . 'Site/page/' . rawurlencode($siteSlug) . '/' . substr($href, strlen($pagePrefix));
        }
        return $href;
    }
}

if (!function_exists('fdBlockCache')) {
    /**
     * Read-through GhettoCache wrapper for the DYNAMIC front-door blocks.
     *
     * Presentation-side alias ONLY. The probe/hydrate/store mechanics — and the
     * GhettoCache handle itself — live in CmsRenderCache::Remember(), alongside
     * the namespace/key/TTL definitions they have to stay in step with (a key
     * format that changes there must flush in CmsAjax::clearrendercache). A
     * template does not talk to the cache layer; it calls this, and this forwards.
     *
     * Kept as a function so the five block templates read as
     * fdBlockCache(ns, key, ttl, fn) rather than repeating the lib class name.
     *
     * @param string        $ns      GhettoCache namespace (CmsRenderCache::NS_*)
     * @param string        $key     the key within that namespace
     * @param int           $ttl     seconds a hit stays valid (CmsRenderCache::TTL)
     * @param callable      $build   () => array — runs ONLY on a miss
     * @param callable|null $storeIf (array $built) => bool — optional gate on
     *                               WRITING the result. kingdoms_teaser uses it to
     *                               refuse to cache an empty list built in a
     *                               context that never injected its source data,
     *                               which would otherwise pin an empty grid on the
     *                               front door for the whole TTL.
     * @return mixed the cached payload, or $build()'s value verbatim — including
     *               when there is no cache handle at all
     */
    function fdBlockCache($ns, $key, $ttl, callable $build, ?callable $storeIf = null)
    {
        // Missing lib (or a stripped-down render context) must never turn a public
        // page into an error — fall back to building uncached, exactly as
        // Remember() does when no cache handle is configured.
        if (!class_exists('CmsRenderCache')) {
            return $build();
        }
        return CmsRenderCache::Remember($ns, $key, $ttl, $build, $storeIf);
    }
}
