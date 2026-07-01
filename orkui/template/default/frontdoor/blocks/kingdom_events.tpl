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
$keLimit    = isset($blockFields['limit']) ? (int) $blockFields['limit'] : 3;
if ($keLimit < 1) {
    $keLimit = 3;
}
if ($keLimit > 12) {
    $keLimit = 12;
}
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
<style>
.ke-block { background: var(--fd-bg); }
.ke-head { display: flex; justify-content: space-between; align-items: flex-end; margin-bottom: 18px; gap: 12px; }
.ke-title { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0; font-size: 24px; }
.ke-more { color: #1d4ed8; font-weight: 600; font-size: 14px; text-decoration: none; white-space: nowrap; }
.ke-more:hover { text-decoration: underline; }
.ke-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; }
.ke-card {
    display: block; text-decoration: none; color: inherit;
    background: var(--fd-bg); border: 1px solid #e4e8f0; border-radius: 10px; overflow: hidden;
    transition: box-shadow .15s ease, transform .15s ease;
}
.ke-card:hover { box-shadow: 0 6px 18px rgba(20,30,60,.12); transform: translateY(-2px); }
.ke-card-accent { height: 8px; background: var(--gold, #d4af37); }
.ke-card-body { padding: 16px; }
.ke-card-date { font-size: 12px; color: #b8860b; font-weight: 700; text-transform: uppercase; letter-spacing: .03em; }
.ke-card-name { font-weight: 700; font-size: 15px; margin: 4px 0; color: var(--fd-text); }
.ke-card-sub { font-size: 12px; color: #778; }
.ke-card-rsvp { font-size: 12px; color: #1d4ed8; margin-top: 6px; font-weight: 600; }
.ke-empty { color: #8899aa; font-style: italic; text-align: center; padding: 18px; }

@media (max-width: 820px) { .ke-grid { grid-template-columns: 1fr; } }

html[data-theme="dark"] .ke-block { background: transparent; }
html[data-theme="dark"] .ke-card { background: #1b2233; border-color: #2c3650; }
html[data-theme="dark"] .ke-card-name { color: #eef2fa; }
html[data-theme="dark"] .ke-card-sub { color: #b6c0d4; }
html[data-theme="dark"] .ke-card:hover { box-shadow: 0 6px 18px rgba(0,0,0,.45); }
</style>
<div class="fd-pad fd-section-light ke-block" style="background:#fff;">
    <div class="ke-head">
        <div>
            <?php if ($keKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($keKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($keHeading !== ''): ?>
                <h3 class="ke-title fd-sec-title"><?= htmlspecialchars($keHeading, ENT_QUOTES) ?></h3>
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
                $keDateOut  = '';
                if (!empty($keRow['NextDate'])) {
                    $keTs = strtotime((string) $keRow['NextDate']);
                    if ($keTs !== false) {
                        $keDateOut = date('D · M j', $keTs);
                    }
                }
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
