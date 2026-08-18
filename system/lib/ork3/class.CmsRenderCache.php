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
 *   frontdoor/blocks/kingdoms_teaser.tpl
 *   Controller_CmsAjax::clearrendercache      the enumeration/flush loop
 *
 * NOT covered: kingdom_events / park_events. Those render from
 * SearchService::Event, which caches internally on its own key and exposes no
 * bust hook — deliberately out of reach, not an oversight.
 *************************************************************************/

class CmsRenderCache
{
    /** Seconds a rendered block payload stays warm. */
    public const TTL = 300;

    /* ---- namespaces (the GhettoCache "call" argument) ---- */
    public const NS_KINGDOM_OFFICERS  = 'frontdoor.kingdom_officers';
    public const NS_PARK_OFFICERS     = 'frontdoor.park_officers';
    public const NS_KINGDOM_PARKS     = 'frontdoor.kingdom_parks';
    public const NS_KINGDOM_PARKS_MAP = 'frontdoor.kingdom_parks_map';
    public const NS_PARK_MEETING      = 'frontdoor.park_meeting';
    public const NS_KINGDOMS_TEASER   = 'frontdoor.kingdoms_teaser';

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
     * @param  callable|null $storeIf (array $built) => bool — optional gate on
     *                                WRITING the result. kingdoms_teaser uses it
     *                                to refuse to cache an empty list built in a
     *                                context that never injected its source data,
     *                                which would otherwise pin an empty grid on
     *                                the front door for the whole TTL.
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
            return $hit;
        }

        $built = $build();
        if ($storeIf === null || $storeIf($built)) {
            $cache->cache($ns, $key, $built);
        }
        return $built;
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
