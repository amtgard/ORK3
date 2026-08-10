<?php
/**
 * Partial: park_officers.tpl — DYNAMIC block (org-scoped, PARK sites).
 *
 * The park-scope sibling of kingdom_officers.tpl: shows the CURRENT ORK officers
 * for the site's owning park. Pairs with the authored `staff_roster` block, which
 * covers non-ORK roles — this block is the live half sourced from ORK data, so a
 * park page can never carry the three-year-old officer list that plagues
 * hand-maintained chapter sites.
 *
 * Self-sourcing like kingdom_officers.tpl: no controller injects officers onto
 * arbitrary site pages, so this partial reads them itself via the Park lib
 * (new APIModel('Park') → Park::GetOfficers). Public view (Token '') exposes only
 * Persona — the lib suppresses real given/surnames for unauthenticated callers.
 *
 * Scope: derives park_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * park scope (global front door / kingdom / principality sites) — never errors,
 * never fatals.
 *
 * Receives: $blockFields { kicker?, heading?, limit? }, UIR, $SiteNavScope*.
 *
 * This file is a thin ADAPTER: the body it shares byte-for-byte with
 * kingdom_officers.tpl lives in _shared/officers.tpl. The filename must stay
 * exactly `park_officers.tpl` — render_blocks.tpl dispatches on `blocks/{type}.tpl`.
 */
$fdOffScopeKind  = 'park';
$fdOffCacheNs    = CmsRenderCache::NS_PARK_OFFICERS;
$fdOffKeyPrefix  = CmsRenderCache::PREFIX_PARK;
$fdOffModelClass = 'Park';
$fdOffArgKey     = 'ParkId';
include __DIR__ . '/_shared/officers.tpl';
