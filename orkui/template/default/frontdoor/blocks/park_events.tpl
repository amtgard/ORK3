<?php
/**
 * Partial: park_events.tpl — DYNAMIC block (org-scoped, PARK sites).
 *
 * The park-scope sibling of kingdom_events.tpl: the soonest UPCOMING events for
 * the site's owning park, as date cards linking to each event.
 *
 * Self-sourcing like kingdom_events.tpl — no controller injects a park-scoped feed
 * onto arbitrary site pages, so this partial sources it itself via the
 * SearchService lib. SearchService::Event() takes park_id as its THIRD positional
 * argument, so the park-owned upcoming feed is the same call the kingdom block
 * makes with the kingdom slot filled instead.
 *
 * Scope: derives park_id from the render-time site scope ($SiteNavScopeType /
 * $SiteNavScopeId, set by Controller_Site::_bootShell). Renders NOTHING outside a
 * park scope — never errors, never fatals.
 *
 * Static .pe-* CSS lives in frontdoor/css/blocks.css (loaded on every CMS surface,
 * org sites included), matching park_officers/park_meeting — this block emits no
 * inline style element.
 *
 * Receives: $blockFields { kicker?, heading?, limit?, more_href? }, UIR, $SiteNavScope*.
 *
 * This file is a thin ADAPTER: the body it shares with kingdom_events.tpl lives in
 * _shared/events.tpl. The filename must stay exactly `park_events.tpl` —
 * render_blocks.tpl dispatches on `blocks/{type}.tpl`.
 */
$fdEvtScopeKind    = 'park';
$fdEvtCssNs        = 'pe';
// Park name is deliberately omitted: on a park's own site every event in this
// feed belongs to that park, so the kingdom block's ParkName sub-line would
// repeat the site name on every card.
$fdEvtShowParkName = false;
include __DIR__ . '/_shared/events.tpl';
