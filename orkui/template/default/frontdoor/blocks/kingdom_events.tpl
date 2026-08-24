<?php
/**
 * Partial: kingdom_events.tpl — DYNAMIC block (org-scoped).
 *
 * A scoped version of the global `events_feed` block: shows the soonest UPCOMING
 * events for the site's owning kingdom as date cards linking to each event.
 *
 * Self-sourcing like blog_feed.tpl: the global events_feed reads $EventSummary
 * (hydrated only by the base front-door Controller::index()). No controller
 * injects a kingdom-scoped feed onto arbitrary site pages, so this partial sources
 * it itself via the SearchService lib (new APIModel('SearchService') → Event),
 * exactly the kingdom-owned upcoming pattern used on the kingdom profile
 * (Search_Event(null, $kingdom_id, 0, …, $date_order=true)).
 *
 * Scope: derives kingdom_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * kingdom scope (global front door / park / unit sites) — never errors, never fatals.
 *
 * Static .ke-* CSS lives in frontdoor/css/blocks.css (loaded on every CMS surface,
 * org sites included) — this block emits no inline style element.
 *
 * Receives: $blockFields { kicker?, heading?, limit?, more_href? }, UIR, $SiteNavScope*.
 *
 * This file is a thin ADAPTER: the body it shares with park_events.tpl lives in
 * _shared/events.tpl. The filename must stay exactly `kingdom_events.tpl` —
 * render_blocks.tpl dispatches on `blocks/{type}.tpl`.
 */
$fdEvtScopeKind    = 'kingdom';
$fdEvtCssNs        = 'ke';
// Kingdom feeds span every park in the kingdom, so each card names its park.
$fdEvtShowParkName = true;
include __DIR__ . '/_shared/events.tpl';
