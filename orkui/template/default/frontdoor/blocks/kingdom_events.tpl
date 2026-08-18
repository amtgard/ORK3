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
 * Receives: $blockFields { kicker?, heading?, limit?, more_href? }, UIR, $SiteNavScope*.
 */
$keScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$keScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$keKingdomId = ($keScopeType === 'kingdom') ? $keScopeId : 0;

// Dropped on a non-kingdom / global page → no single kingdom to source. Render
// nothing at all rather than a broken or misleading empty box.
if ($keKingdomId <= 0) {
    return;
}

$keKicker   = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$keHeading  = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Upcoming Events';
$keLimit    = fdClampLimit($blockFields['limit'] ?? null, 3, 12);
$keMoreHref = isset($blockFields['more_href']) ? trim((string) $blockFields['more_href']) : '';
if ($keMoreHref === '#') {
    // Blank URL fields are rewritten to '#' by the save sanitizer — treat as unset.
    $keMoreHref = '';
}

$keRows = [];
if (class_exists('APIModel')) {
    try {
        $keModel = new APIModel('SearchService');
        // Kingdom-owned upcoming events, date-ordered (date_order=true).
        $keResult = $keModel->Event(null, $keKingdomId, 0, null, null, $keLimit, null, true);
        if (is_array($keResult)) {
            $keRows = array_values($keResult);
        }
    } catch (\Throwable $e) {
        $keRows = [];
    }
}
$keRows = array_slice($keRows, 0, $keLimit);
?>
<div class="fd-pad fd-section-light ke-block">
    <div class="ke-head">
        <div>
            <?php if ($keKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($keKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($keHeading !== ''): ?>
                <h2 class="ke-title fd-sec-title"><?= htmlspecialchars($keHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
        </div>
        <?php if ($keMoreHref !== ''): ?>
            <a class="ke-more" href="<?= htmlspecialchars($keMoreHref, ENT_QUOTES) ?>">All events &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($keRows)): ?>
        <div class="ke-empty">No upcoming events right now.</div>
    <?php else: ?>
        <div class="ke-grid">
            <?php foreach ($keRows as $keRow): ?>
                <?php
                if (!is_array($keRow)) {
                    continue;
                }
                $keEventId  = (int) ($keRow['EventId'] ?? 0);
                $keDetailId = (int) ($keRow['NextDetailId'] ?? 0);
                if ($keEventId <= 0 || $keDetailId <= 0) {
                    continue;
                }
                $keName     = htmlspecialchars(stripslashes((string) ($keRow['Name'] ?? '')), ENT_QUOTES);
                $keParkName = htmlspecialchars(stripslashes((string) ($keRow['ParkName'] ?? '')), ENT_QUOTES);
                $keRsvp     = (int) ($keRow['RsvpGoing'] ?? 0);
                $keDateOut  = fdFormatDate($keRow['NextDate'] ?? '', 'D · M j');
                ?>
                <a class="ke-card" href="<?= UIR ?>Event/detail/<?= $keEventId ?>/<?= $keDetailId ?>">
                    <div class="ke-card-accent"></div>
                    <div class="ke-card-body">
                        <?php if ($keDateOut !== ''): ?>
                            <div class="ke-card-date"><?= htmlspecialchars($keDateOut, ENT_QUOTES) ?></div>
                        <?php endif; ?>
                        <div class="ke-card-name"><?= $keName ?></div>
                        <?php if ($keParkName !== ''): ?>
                            <div class="ke-card-sub"><?= $keParkName ?></div>
                        <?php endif; ?>
                        <?php if ($keRsvp > 0): ?>
                            <div class="ke-card-rsvp"><?= $keRsvp ?> going</div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
