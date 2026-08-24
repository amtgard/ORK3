<?php

/*************************************************************************
 * CmsRenderCache — the render-cache key registry for the front-door
 * live-data blocks.
 *
 * WHY THIS EXISTS
 * GhettoCache/Memcached has no prefix scan, so "flush this org's public site"
 * can only be implemented by ENUMERATING every key the blocks could have
 * written. That enumeration lives in Controller_CmsAjax::clearrendercache,
 * while the keys themselves are written by the block partials under
 * template/default/frontdoor/blocks/. Those two sides used to declare the
 * namespace, the key FORMAT and the clamp BOUNDS independently — and drift
 * between them fails SILENTLY in the worst possible direction: the endpoint
 * reports "cleared 433 entries" while busting keys nothing ever wrote, and the
 * officer who just fixed their roster keeps seeing the stale one.
 *
 * So both sides read this file instead. The bounds are not a validation
 * preference — they DEFINE the finite key space, which is what makes the
 * enumeration possible at all. Widening a max here widens the flush loop in
 * lockstep; changing a key format changes both writer and buster together.
 *
 * Pure data: no DB, no filesystem, no request state (CmsSanitizer is the
 * precedent), so it is safe to read statically from a controller, a lib, or a
 * .tpl partial.
 *
 * Consumers:
 *   frontdoor/blocks/_shared/officers.tpl     kingdom_officers + park_officers
 *   frontdoor/blocks/kingdom_parks.tpl
 *   frontdoor/blocks/kingdom_parks_map.tpl
 *   frontdoor/blocks/park_meeting.tpl
 *   frontdoor/blocks/park_hero.tpl
 *   frontdoor/blocks/kingdoms_teaser.tpl
 *   frontdoor/_park_strip.tpl                 every page of an org site, not just home
 *   Controller_CmsAjax::clearrendercache      the enumeration/flush loop
 *
 * EMPTY IS NOT FAILED — BUT ONLY $storeIf CAN SAY SO
 * Remember() below stores an empty build as an internal sentinel on the shorter
 * TTL_EMPTY clock, so a sparsely-populated org is not condemned to re-query on
 * every anonymous hit forever. The sentinel is private to this file — callers
 * only ever see what their $build() returned.
 *
 * The ONLY channel by which a caller can say "this build FAILED, store nothing"
 * is $storeIf. Remember() cannot see that a builder caught a \Throwable: a
 * builder that swallows its own exception and returns an all-empty shape is
 * indistinguishable from a legitimately empty org, and its failure WILL be
 * cached for TTL_EMPTY. A block that catches \Throwable must set a failure flag
 * and veto through $storeIf — see blocks/_shared/officers.tpl ($fdOffFailed).
 *
 * NOT covered: kingdom_events / park_events. Those render from
 * SearchService::Event, which caches internally on its own key and exposes no
 * bust hook — deliberately out of reach, not an oversight.
 *************************************************************************/

class CmsRenderCache
{
    /** Seconds a rendered block payload stays warm. */
    public const TTL = 300;

    /**
     * Seconds a VERIFIED-EMPTY build stays warm — deliberately shorter than TTL.
     *
     * A sparsely-populated org (a park with no city, no map URL and no park-day
     * rows; a kingdom with no parks yet) renders nothing legitimately, and used
     * to be permanently uncacheable: the empty-payload gate refused to store it,
     * so _park_strip.tpl — which runs on EVERY page of an org site — re-ran its
     * queries on every anonymous hit, forever. Such a build is now stored as an
     * internal sentinel on this shorter clock instead, so the queries are skipped
     * while the org stays empty, and a newly-populated org shows up within a
     * minute rather than waiting the full TTL out. Short on purpose for the other
     * reason too: PDO runs in ERRMODE_WARNING, so a silent query failure can look
     * like a legitimate empty, and a minute is a cheap ceiling on that mistake.
     */
    public const TTL_EMPTY = 60;

    /**
     * Marker key of the internal empty-build sentinel.
     *
     * NEVER escapes this file: Remember() wraps on write and unwraps on read, so
     * every caller receives exactly what $build() returned. Named distinctively
     * so it cannot collide with a real block payload slot.
     *
     * Mixed-deploy/rollback window: a node still running the pre-sentinel code
     * that reads a sentinel written by a new node gets the raw wrapper array
     * back and renders its empty state. Bounded to TTL_EMPTY and to orgs that
     * were empty anyway, so it needs no versioned key.
     */
    private const EMPTY_MARKER = '__fd_render_cache_empty__';

    /* ---- namespaces (the GhettoCache "call" argument) ---- */
    public const NS_KINGDOM_OFFICERS  = 'frontdoor.kingdom_officers';
    public const NS_PARK_OFFICERS     = 'frontdoor.park_officers';
    public const NS_KINGDOM_PARKS     = 'frontdoor.kingdom_parks';
    public const NS_KINGDOM_PARKS_MAP = 'frontdoor.kingdom_parks_map';
    public const NS_PARK_MEETING      = 'frontdoor.park_meeting';
    public const NS_KINGDOMS_TEASER   = 'frontdoor.kingdoms_teaser';
    public const NS_PARK_HERO         = 'frontdoor.park_hero';
    public const NS_PARK_STRIP        = 'frontdoor.park_strip';

    /* ---- org-id key prefixes ---- */
    public const PREFIX_KINGDOM = 'k';
    public const PREFIX_PARK    = 'p';

    /* ---- clamp bounds: these ARE the enumerated key space ---- */
    public const OFFICERS_LIMIT_DEFAULT = 12;
    public const OFFICERS_LIMIT_MAX     = 24;
    public const PARKS_LIMIT_DEFAULT    = 24;
    public const PARKS_LIMIT_MAX        = 60;
    public const MEETING_LIMIT_DEFAULT  = 6;
    public const MEETING_LIMIT_MAX      = 12;
    public const TEASER_LIMIT_DEFAULT   = 12;
    public const TEASER_LIMIT_MAX       = 24;

    /** The sort modes kingdom_parks keys on (any other value clamps to the first). */
    public const PARKS_SORTS = array('name', 'city', 'state');

    /* ------------------------------------------------------------------ *
     * key builders — the ONE definition of each key's format
     * ------------------------------------------------------------------ */

    /** kingdom_officers / park_officers: 'k17.l12' / 'p42.l12'. */
    public static function OfficersKey($prefix, $orgId, $limit)
    {
        return (string)$prefix . (int)$orgId . '.l' . (int)$limit;
    }

    /** kingdom_parks: 'k17.l24.sname.h0'. */
    public static function ParksKey($kingdomId, $limit, $sort, $showHeraldry)
    {
        return self::PREFIX_KINGDOM . (int)$kingdomId . '.l' . (int)$limit
            . '.s' . (string)$sort . '.h' . ($showHeraldry ? 1 : 0);
    }

    /** kingdom_parks_map: 'k17' (the map varies on nothing else). */
    public static function ParksMapKey($kingdomId)
    {
        return self::PREFIX_KINGDOM . (int)$kingdomId;
    }

    /** park_meeting: 'p42.l6.d1'. */
    public static function MeetingKey($parkId, $limit, $showDirections)
    {
        return self::PREFIX_PARK . (int)$parkId . '.l' . (int)$limit
            . '.d' . ($showDirections ? 1 : 0);
    }

    /** park_hero: 'p42.w0' — varies on the park and the weather flag only. */
    public static function ParkHeroKey($parkId, $showWeather)
    {
        return self::PREFIX_PARK . (int)$parkId . '.w' . ($showWeather ? 1 : 0);
    }

    /** park_strip: 'p42' (the strip varies on nothing else). */
    public static function ParkStripKey($parkId)
    {
        return self::PREFIX_PARK . (int)$parkId;
    }

    /** kingdoms_teaser: 'l12' — global, so no org id in the key. */
    public static function TeaserKey($limit)
    {
        return 'l' . (int)$limit;
    }

    /* ------------------------------------------------------------------ *
     * enumerations — every key a scope could hold, for the flush loop
     * ------------------------------------------------------------------ */

    /**
     * Every key the kingdom-scoped blocks can write for one kingdom.
     *
     * @param int $kingdomId
     * @return array<int,array{ns:string,key:string}>
     */
    public static function KingdomKeys($kingdomId)
    {
        $kingdomId = (int)$kingdomId;
        $out = array();
        if ($kingdomId <= 0) {
            return $out;
        }
        for ($l = 1; $l <= self::OFFICERS_LIMIT_MAX; $l++) {
            $out[] = array(
                'ns'  => self::NS_KINGDOM_OFFICERS,
                'key' => self::OfficersKey(self::PREFIX_KINGDOM, $kingdomId, $l),
            );
        }
        foreach (self::PARKS_SORTS as $sort) {
            foreach (array(0, 1) as $h) {
                for ($l = 1; $l <= self::PARKS_LIMIT_MAX; $l++) {
                    $out[] = array(
                        'ns'  => self::NS_KINGDOM_PARKS,
                        'key' => self::ParksKey($kingdomId, $l, $sort, $h),
                    );
                }
            }
        }
        $out[] = array(
            'ns'  => self::NS_KINGDOM_PARKS_MAP,
            'key' => self::ParksMapKey($kingdomId),
        );
        return $out;
    }

    /**
     * Every key the park-scoped blocks can write for one park.
     *
     * @param int $parkId
     * @return array<int,array{ns:string,key:string}>
     */
    public static function ParkKeys($parkId)
    {
        $parkId = (int)$parkId;
        $out = array();
        if ($parkId <= 0) {
            return $out;
        }
        for ($l = 1; $l <= self::OFFICERS_LIMIT_MAX; $l++) {
            $out[] = array(
                'ns'  => self::NS_PARK_OFFICERS,
                'key' => self::OfficersKey(self::PREFIX_PARK, $parkId, $l),
            );
        }
        foreach (array(0, 1) as $d) {
            for ($l = 1; $l <= self::MEETING_LIMIT_MAX; $l++) {
                $out[] = array(
                    'ns'  => self::NS_PARK_MEETING,
                    'key' => self::MeetingKey($parkId, $l, $d),
                );
            }
        }
        foreach (array(0, 1) as $w) {
            $out[] = array(
                'ns'  => self::NS_PARK_HERO,
                'key' => self::ParkHeroKey($parkId, $w),
            );
        }
        $out[] = array(
            'ns'  => self::NS_PARK_STRIP,
            'key' => self::ParkStripKey($parkId),
        );
        return $out;
    }

    /**
     * Every key the GLOBAL front-door blocks can write.
     *
     * kingdoms_teaser is the one cached block with no org in its key, which is
     * exactly why it went unbusted for so long: the flush loop only ever ran
     * over an org id. A global-scope actor (the front door's own editor) is the
     * only one who may clear it — a kingdom officer must not be able to flush a
     * cache shared by every other org.
     *
     * @return array<int,array{ns:string,key:string}>
     */
    public static function GlobalKeys()
    {
        $out = array();
        for ($l = 1; $l <= self::TEASER_LIMIT_MAX; $l++) {
            $out[] = array(
                'ns'  => self::NS_KINGDOMS_TEASER,
                'key' => self::TeaserKey($l),
            );
        }
        return $out;
    }

    /* ------------------------------------------------------------------ *
     * cache mechanics — the ONE place that touches the GhettoCache handle
     *
     * The key space above and the read/write below are two halves of one
     * contract (a key format that changes here must flush there), so they live
     * together in this class. Controllers and templates call the two public
     * methods; neither reaches for Ork3::$Lib->ghettocache itself.
     * ------------------------------------------------------------------ */

    /**
     * The GhettoCache handle, or null when none is configured.
     *
     * Probed defensively on purpose: a missing/failed Memcached must degrade the
     * front door to uncached rendering, never to an error.
     *
     * @return object|null
     */
    private static function _handle()
    {
        return (isset(Ork3::$Lib) && is_object(Ork3::$Lib)
            && isset(Ork3::$Lib->ghettocache) && is_object(Ork3::$Lib->ghettocache))
            ? Ork3::$Lib->ghettocache : null;
    }

    /**
     * Read-through cache for the DYNAMIC front-door blocks.
     *
     * Every live-data block ran the same probe/hydrate/store dance inline; five
     * copies meant five chances to skip the null-handle guard. This owns those
     * mechanics once. The namespace, key format and TTL still come from the
     * constants above, because BustScope() has to enumerate that same key space.
     *
     * @param  string        $ns      namespace (self::NS_*)
     * @param  string        $key     key within that namespace
     * @param  int           $ttl     seconds a hit stays valid (self::TTL)
     * @param  callable      $build   () => array — runs ONLY on a miss
     * @param  callable|null $storeIf (array $built) => bool — optional EXTRA veto
     *                                on WRITING the result, and the caller's way
     *                                of saying "this build FAILED". Returning
     *                                false stores NOTHING AT ALL, empty or not,
     *                                so the next hit retries. kingdoms_teaser
     *                                uses it to refuse to cache an empty list
     *                                built in a context that never injected its
     *                                source data, which would otherwise pin an
     *                                empty grid on the front door for the TTL.
     *
     * FAILED vs LEGITIMATELY EMPTY. A dynamic block returns an empty build in two
     * very different situations, and this method treats them differently:
     *
     *   FAILED — $storeIf returned false. Nothing is stored at all, so the next
     *            hit retries. This is the ONLY failure signal Remember() can
     *            see. It has NO way to learn that $build() caught a \Throwable
     *            internally: a builder that swallows its own exception and
     *            returns an all-empty shape looks exactly like a legitimately
     *            empty org, and that failure IS cached — for TTL_EMPTY, not the
     *            full TTL. A block that catches \Throwable and still wants a
     *            failed build to go unstored MUST raise a flag in the builder
     *            and veto through $storeIf; blocks/_shared/officers.tpl does
     *            this with $fdOffFailed.
     *   EMPTY  — the build ran to completion and there genuinely is nothing to
     *            show. Stored as an internal sentinel on the SHORT TTL_EMPTY
     *            clock. This is what stops a sparsely-populated org from being
     *            permanently uncached and re-querying on every single hit
     *            (worst case: _park_strip.tpl, on every page of an org site).
     *            TTL_EMPTY is the ceiling on both cases: it is deliberately
     *            short precisely because a swallowed failure lands here too.
     *
     * The sentinel is an implementation detail of this file: it is unwrapped back
     * to the payload $build() returned before anything is handed to a caller.
     *
     * @return mixed the cached payload, or $build()'s value verbatim — including
     *               when there is no cache handle at all
     */
    public static function Remember($ns, $key, $ttl, callable $build, ?callable $storeIf = null)
    {
        $cache = self::_handle();

        // No cache configured → this is a plain function call.
        if ($cache === null) {
            return $build();
        }

        $hit = $cache->get($ns, $key, $ttl);
        if (is_array($hit)) {
            return self::_unwrapEmpty($hit);
        }

        $built = $build();

        // An explicit $storeIf veto means the build FAILED: store nothing at all,
        // however empty it looks, so the next hit retries.
        if ($storeIf !== null && !$storeIf($built)) {
            return $built;
        }

        if (self::_isEmptyPayload($built)) {
            // Verified empty: pin the sentinel, but only for TTL_EMPTY. GhettoCache
            // takes a write's expiration from the lifetime the matching get()
            // recorded, so re-probing the key with the shorter clock is how the
            // shorter clock is communicated. One extra (missing) memcached read,
            // on an empty miss only.
            // min() keeps "shorter than TTL" true for ANY caller: a caller that
            // passes a $ttl below TTL_EMPTY must not have its empty LENGTHENED.
            $cache->get($ns, $key, min((int)$ttl, self::TTL_EMPTY));
            $cache->cache($ns, $key, array(self::EMPTY_MARKER => 1, 'payload' => $built));
            return $built;
        }

        $cache->cache($ns, $key, $built);
        return $built;
    }

    /**
     * Turn a cache hit back into what $build() returned.
     *
     * Only the verified-empty sentinel written above is rewritten; every other
     * hit passes through byte-identical.
     *
     * @param  array $hit
     * @return mixed
     */
    private static function _unwrapEmpty(array $hit)
    {
        if (array_key_exists(self::EMPTY_MARKER, $hit) && array_key_exists('payload', $hit)) {
            return $hit['payload'];
        }
        return $hit;
    }

    /**
     * Does this build carry nothing — i.e. does it belong on the TTL_EMPTY clock
     * rather than the full TTL?
     *
     * True for an empty array, and for a keyed payload whose every slot is empty
     * (park_meeting's failure shape is ['Meetings' => [], 'Fallback' => [],
     * 'Directions' => ''], not a bare []). Anything else — including a non-array
     * — is a real payload and caches as before.
     *
     * @param  mixed $built
     * @return bool
     */
    private static function _isEmptyPayload($built)
    {
        if (!is_array($built)) {
            return false;
        }
        foreach ($built as $slot) {
            if (is_array($slot)) {
                if ($slot !== array()) {
                    return false;
                }
            } elseif ($slot !== null && $slot !== '' && $slot !== 0 && $slot !== false) {
                return false;
            }
        }
        return true;
    }

    /**
     * Flush every cached block payload belonging to one scope.
     *
     * The org blocks source by kingdom_id / park_id and render nothing outside
     * their own scope, so a scope maps to exactly one of the three key sets.
     * Anything that is not a kingdom/park scope is the global front door, whose
     * only scope-less cached block is kingdoms_teaser.
     *
     * @param  string $scopeType 'kingdom' | 'park' | 'global'
     * @param  int    $scopeId
     * @return int    number of keys busted (0 when no cache is configured)
     */
    public static function BustScope($scopeType, $scopeId)
    {
        $scopeType = (string)$scopeType;
        $scopeId   = (int)$scopeId;

        if ($scopeType === 'kingdom' && $scopeId > 0) {
            $keys = self::KingdomKeys($scopeId);
        } elseif ($scopeType === 'park' && $scopeId > 0) {
            $keys = self::ParkKeys($scopeId);
        } else {
            $keys = self::GlobalKeys();
        }

        $cache = self::_handle();
        if ($cache === null) {
            return 0;
        }

        $cleared = 0;
        foreach ($keys as $entry) {
            $cache->bust($entry['ns'], $entry['key']);
            $cleared++;
        }
        return $cleared;
    }
}
