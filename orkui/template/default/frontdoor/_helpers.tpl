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

if (!function_exists('fdBlockCache')) {
    /**
     * Read-through GhettoCache wrapper for the DYNAMIC front-door blocks.
     *
     * Every live-data block ran the same six-line dance inline: probe
     * Ork3::$Lib->ghettocache defensively, get() under a namespace+key, accept the
     * hit only when it is an array, otherwise build the payload and cache() it.
     * Five copies meant five chances for one of them to skip the null-handle guard
     * (which is what makes the blocks survive a Memcached outage) or to store on a
     * path that should not have.
     *
     * The namespace, the key FORMAT and the clamp bounds are NOT decided here —
     * they come from CmsRenderCache, because CmsAjax::clearrendercache has to
     * enumerate that exact key space to flush it. This helper only owns the
     * probe/hydrate/store mechanics.
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
        $cache = (isset(Ork3::$Lib) && is_object(Ork3::$Lib)
            && isset(Ork3::$Lib->ghettocache) && is_object(Ork3::$Lib->ghettocache))
            ? Ork3::$Lib->ghettocache : null;

        // No cache configured → this is a plain function call. Never let a missing
        // Memcached turn a public page into an error.
        if ($cache === null) {
            return $build();
        }

        $hit = $cache->get($ns, $key, $ttl);
        if (is_array($hit)) {
            return $hit;
        }

        $built = $build();
        if ($storeIf === null || $storeIf($built)) {
            $cache->cache($ns, $key, $built);
        }
        return $built;
    }
}
