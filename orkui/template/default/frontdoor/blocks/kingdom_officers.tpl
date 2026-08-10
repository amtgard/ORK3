<?php
/**
 * Partial: kingdom_officers.tpl — DYNAMIC block (org-scoped).
 *
 * Shows the CURRENT ORK officers (kingdom seats) for the site's owning kingdom.
 * Pairs with the authored `staff_roster` block, which covers the Board of
 * Directors / non-ORK roles — this block is the live half sourced from ORK data.
 *
 * Self-sourcing like blog_feed.tpl: no controller injects officers onto arbitrary
 * site pages, so this partial reads them itself via the Kingdom lib
 * (new APIModel('Kingdom') → Kingdom::GetOfficers). Public view (Token '') exposes
 * only Persona — real given/surnames are suppressed by the lib.
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * kingdom scope (global front door / park / unit sites) — never errors, never fatals.
 *
 * Receives: $blockFields { kicker?, heading?, limit? }, UIR, $SiteNavScope*.
 *
 * This file is a thin ADAPTER: the body it shares byte-for-byte with
 * park_officers.tpl lives in _shared/officers.tpl. The filename must stay exactly
 * `kingdom_officers.tpl` — render_blocks.tpl dispatches on `blocks/{type}.tpl`.
 */
$fdOffScopeKind  = 'kingdom';
$fdOffCacheNs    = CmsRenderCache::NS_KINGDOM_OFFICERS;
$fdOffKeyPrefix  = CmsRenderCache::PREFIX_KINGDOM;
$fdOffModelClass = 'Kingdom';
$fdOffArgKey     = 'KingdomId';
include __DIR__ . '/_shared/officers.tpl';
