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
     * Shared by every list block (blog_feed, events_feed, kingdom_events/
     * officers/parks, kingdoms_teaser, park_events/meeting/officers). Two bits
     * of the contract are non-obvious:
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

if (!function_exists('fdScopedOrgId')) {
    /**
     * Resolve the org id an org-scoped partial should source from, or 0.
     *
     * Every scoped block/partial (kingdom_* , park_* , _shared/events,
     * _shared/officers, _park_strip) opens with the same gate: the render-time
     * site scope must be the kind this partial requires, otherwise there is no
     * single org to source and the partial renders nothing at all rather than a
     * broken or misleading empty box. This is that test, named once.
     *
     * The scope pair is PASSED IN rather than read here: $SiteNavScopeType /
     * $SiteNavScopeId are template locals from the render scope, not globals, so
     * a function cannot reach them. Callers pass `$SiteNavScopeType ?? ''` and
     * `$SiteNavScopeId ?? 0`.
     *
     * @param mixed  $scopeType the current site scope kind ('kingdom'|'park'|…)
     * @param mixed  $scopeId   the current site scope id
     * @param string $wantKind  the scope kind this partial requires
     * @return int the org id when the scope matches and is positive, else 0
     */
    function fdScopedOrgId($scopeType, $scopeId, $wantKind)
    {
        if ((string) $scopeType !== (string) $wantKind) {
            return 0;
        }
        $id = (int) $scopeId;
        return $id > 0 ? $id : 0;
    }
}

if (!function_exists('fdSoonestParkDay')) {
    /**
     * Pick the soonest park day that has not already happened.
     *
     * Park::CalculateNextParkDay() can hand back a date in the PAST:
     * 'week-of-month' resolves the Nth weekday of the CURRENT month (1st Sunday
     * is 2026-08-02 for the whole of August) and 'monthly' behaves the same way.
     * A plain min() over its output would therefore publish a date that has been
     * and gone AND suppress the park's correct weekly day, which is strictly
     * worse than showing nothing — hence the past-date guard below. It lives at
     * the consumer because the calculator is shared with the CRM side.
     *
     * Both park-scope surfaces call this (park_hero.tpl's "Next game day" line
     * and _park_strip.tpl's sticky when/where strip), so the guard cannot drift.
     *
     * @param array $parkDays the rows from Park::GetParkDays()['ParkDays']
     * @return array|null ['d' => date string, 't' => Time, 'w' => WeekDay], or
     *                    null when no row resolves to a present/future date
     */
    function fdSoonestParkDay(array $parkDays)
    {
        if (!class_exists('Park')) {
            return null;
        }
        $soonest = null;
        $todayTs = strtotime('today');
        foreach ($parkDays as $day) {
            if (!is_array($day)) {
                continue;
            }
            $next = Park::CalculateNextParkDay(
                $day['Recurrence'] ?? '',
                $day['WeekOfMonth'] ?? 0,
                $day['MonthDay'] ?? 0,
                $day['WeekDay'] ?? '',
                null,
                $day['StartDate'] ?? null,
                $day['WeekInterval'] ?? 0
            );
            if (!$next) {
                continue;
            }
            $nextTs = strtotime($next);
            if ($nextTs === false || $nextTs < $todayTs) {
                continue;
            }
            if ($soonest === null || $nextTs < strtotime($soonest['d'])) {
                $soonest = array(
                    'd' => $next,
                    't' => (string) ($day['Time'] ?? ''),
                    'w' => (string) ($day['WeekDay'] ?? ''),
                );
            }
        }
        return $soonest;
    }
}

if (!function_exists('fdSiteInternalHref')) {
    /**
     * Re-point a 'Page/view/{slug}' href onto THIS site's own
     * 'Site/page/{siteSlug}/{slug}' route, at RENDER time.
     *
     * Mirrors org_header.tpl's $orgHref rewrite, which does exactly this for
     * CmsNav-sourced nav links. Why the rewrite happens at render time rather
     * than at seed time: CmsSite::UpdateSite() allows editing 'slug', and
     * nothing re-visits already-seeded block content, so an href baked into the
     * 'Site/page/{slug}/...' form at SEED time is stranded by a rename. Seeds
     * therefore store the stable 'Page/view/{slug}' form (CmsSite::
     * _sitePageHref()) and this resolves it against whatever $SiteSlug is
     * current for THIS request, so a rename fixes every link at once — the same
     * guarantee nav has. Consumer: steps.tpl's CTA (the park starter's
     * "Your First Day" → New Players link).
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
     *                         unchanged — the stable 'Page/view/{slug}' form is
     *                         itself a working link, so that degrades cleanly.
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
     * Kept as a function so the seven consuming templates read as
     * fdBlockCache(ns, key, ttl, fn) rather than repeating the lib class name.
     *
     * @param string        $ns      GhettoCache namespace (CmsRenderCache::NS_*)
     * @param string        $key     the key within that namespace
     * @param int           $ttl     seconds a hit stays valid (CmsRenderCache::TTL)
     * @param callable      $build   () => array — runs ONLY on a miss
     * @param callable|null $storeIf (array $built) => bool — optional EXTRA veto on
     *                               WRITING the result. It can only NARROW what gets
     *                               cached: Remember() ANDs it with its own
     *                               empty-payload gate, which always applies on top,
     *                               so no caller can opt back into pinning an empty
     *                               build. kingdoms_teaser uses it to refuse to cache
     *                               an empty list built in a context that never
     *                               injected its source data, which would otherwise
     *                               pin an empty grid on the front door for the TTL.
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

if (!function_exists('fdHeroRenderableSlides')) {
    /**
     * Filter a hero_carousel 'slides' field down to the slides that actually
     * render — those carrying an image src, headline, subcopy or kicker.
     *
     * TWO call sites must agree on this predicate exactly, or a page ships zero
     * <h1>s or two of them: hero_carousel.tpl uses it to decide what to draw
     * (and to give the FIRST surviving slide the page <h1>), and Site_shell.tpl
     * uses it to decide whether a hero block already supplies that <h1> and the
     * fallback page-title heading must therefore be suppressed. It lives here so
     * the two cannot drift.
     *
     * The emptiness test is the concatenate-then-trim form both sites used
     * inline, kept verbatim: a slide survives when ANY of the four fields has
     * non-whitespace content. Non-array entries are dropped.
     *
     * @param array $slides the raw authored slides field
     * @return array re-indexed list of the renderable slides, order preserved
     */
    function fdHeroRenderableSlides(array $slides)
    {
        return array_values(array_filter($slides, static function ($s) {
            if (!is_array($s)) {
                return false;
            }
            $img = is_array($s['image'] ?? null) ? $s['image'] : [];
            return trim(
                (string) ($img['src'] ?? '')
                . (string) ($s['headline'] ?? '')
                . (string) ($s['subcopy'] ?? '')
                . (string) ($s['kicker'] ?? '')
            ) !== '';
        }));
    }
}

if (!function_exists('fdEmptyBlockNotice')) {
    /**
     * Emit the preview-only "this block is empty" note.
     *
     * Six blocks (cta_band, card_grid, hero_carousel, photo_mosaic, rich_text,
     * steps) share one wrapper: when every field is blank they render nothing at
     * all for a public visitor, and a small hint for an author in preview so the
     * empty block is discoverable in the editor instead of a blank stripe. The
     * .fd-empty styling was already shared; this shares the markup too.
     *
     * The per-block emptiness TEST stays in each block — only the note is shared,
     * and each block keeps its own wording, which is passed in.
     *
     * Callers gate this on $fdIsPreview (set by render_blocks.tpl) themselves,
     * exactly as the inlined copies did.
     *
     * @param string $message the block's own note text (a template literal;
     *                        emitted as-is, as the inline copies did)
     * @return void
     */
    function fdEmptyBlockNotice($message)
    {
        echo '<div class="fd-pad fd-empty">' . $message . '</div>';
    }
}
