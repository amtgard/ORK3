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
 * Static .pe-* CSS lives in frontdoor.css (loaded under orgsite.css on org sites),
 * matching park_officers/park_meeting — no per-render inline <style>.
 *
 * Receives: $blockFields { kicker?, heading?, limit?, more_href? }, UIR, $SiteNavScope*.
 */
$peScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$peScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$peParkId    = ($peScopeType === 'park') ? $peScopeId : 0;

if ($peParkId <= 0) {
    return;
}

$peKicker   = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$peHeading  = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Upcoming Events';
$peLimit    = fdClampLimit($blockFields['limit'] ?? null, 3, 12);
$peMoreHref = isset($blockFields['more_href']) ? trim((string) $blockFields['more_href']) : '';
if ($peMoreHref === '#') {
    // Blank URL fields are rewritten to '#' by the save sanitizer — treat as unset.
    $peMoreHref = '';
}

$peRows = [];
if (class_exists('APIModel')) {
    try {
        $peModel = new APIModel('SearchService');
        // Park-owned upcoming events, date-ordered (date_order=true). SearchService
        // caches this call internally (300s), so no GhettoCache wrapper here — the
        // same arrangement kingdom_events relies on.
        $peResult = $peModel->Event(null, null, (int) $peParkId, null, null, $peLimit, null, true);
        if (is_array($peResult)) {
            $peRows = array_values($peResult);
        }
    } catch (\Throwable $e) {
        $peRows = [];
    }
}
$peRows = array_slice($peRows, 0, $peLimit);
?>
<div class="fd-pad fd-section-light pe-block">
    <div class="pe-head">
        <div>
            <?php if ($peKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($peKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($peHeading !== ''): ?>
                <h2 class="pe-title fd-sec-title"><?= htmlspecialchars($peHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
        </div>
        <?php if ($peMoreHref !== ''): ?>
            <a class="pe-more" href="<?= htmlspecialchars($peMoreHref, ENT_QUOTES) ?>">All events &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($peRows)): ?>
        <div class="pe-empty">No upcoming events right now.</div>
    <?php else: ?>
        <div class="pe-grid">
            <?php foreach ($peRows as $peRow): ?>
                <?php
                if (!is_array($peRow)) {
                    continue;
                }
                $peEventId  = (int) ($peRow['EventId'] ?? 0);
                $peDetailId = (int) ($peRow['NextDetailId'] ?? 0);
                if ($peEventId <= 0 || $peDetailId <= 0) {
                    continue;
                }
                $peName    = htmlspecialchars(stripslashes((string) ($peRow['Name'] ?? '')), ENT_QUOTES);
                $peRsvp    = (int) ($peRow['RsvpGoing'] ?? 0);
                $peDateOut = fdFormatDate($peRow['NextDate'] ?? '', 'D · M j');
                ?>
                <a class="pe-card" href="<?= UIR ?>Event/detail/<?= $peEventId ?>/<?= $peDetailId ?>">
                    <div class="pe-card-accent"></div>
                    <div class="pe-card-body">
                        <?php if ($peDateOut !== ''): ?>
                            <div class="pe-card-date"><?= htmlspecialchars($peDateOut, ENT_QUOTES) ?></div>
                        <?php endif; ?>
                        <div class="pe-card-name"><?= $peName ?></div>
                        <?php // Park name is deliberately omitted: on a park's own site
                              // every event in this feed belongs to that park, so the
                              // kingdom block's ParkName sub-line would repeat the site
                              // name on every card. ?>
                        <?php if ($peRsvp > 0): ?>
                            <div class="pe-card-rsvp"><?= $peRsvp ?> going</div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
