<?php
/**
 * Shared partial: _shared/events.tpl — the common body of the two DYNAMIC
 * upcoming-events blocks, kingdom_events.tpl and park_events.tpl.
 *
 * Those two were identical apart from four values (scope kind, CSS namespace,
 * which positional slot of SearchService::Event() receives the org id, and the
 * variable prefix). Everything else — the scope gate, the field parsing incl.
 * the '#'-means-unset rewrite, the fetch + slice and the entire card grid —
 * lived twice. It now lives here once; the two block files are thin adapters.
 *
 * WHY THIS IS IN A SUBDIRECTORY, and why the adapters keep their exact
 * filenames: render_blocks.tpl:34-38 dispatches on `blocks/{type}.tpl` after
 * running the block type through preg_replace('/[^a-z_]/', '', ...). That
 * sanitizer strips '/' and '.', so no authored block type can ever resolve to
 * `_shared/events.tpl`. Putting the shared body in a subdirectory is therefore
 * a security property, not a stylistic one — a sibling file named
 * `blocks/events.tpl` WOULD be reachable as a block type named "events".
 *
 * Caller (adapter) must set, before including:
 *   $fdEvtScopeKind    'kingdom'|'park' — the site scope this block requires,
 *                      and the SearchService::Event() slot the org id fills
 *   $fdEvtCssNs        CSS namespace for this block's static classes ('ke'|'pe'),
 *                      whose rules live in frontdoor/css/blocks.css
 *   $fdEvtShowParkName true to render the per-card ParkName sub-line (kingdom
 *                      feeds span parks); false where every card belongs to the
 *                      site's own park and the line would just repeat its name
 *
 * Also receives, from render_blocks.tpl: $blockFields { kicker?, heading?,
 * limit?, more_href? }, UIR, $SiteNavScopeType / $SiteNavScopeId.
 *
 * No GhettoCache wrapper: SearchService caches this call internally (300s).
 */
$fdEvtScopeType = isset($SiteNavScopeType) ? (string) $SiteNavScopeType : 'global';
$fdEvtScopeId   = isset($SiteNavScopeId) ? (int) $SiteNavScopeId : 0;
$fdEvtOrgId     = ($fdEvtScopeType === $fdEvtScopeKind) ? $fdEvtScopeId : 0;

// Dropped on a page outside this block's scope (global front door, or the other
// org kind) → no single org to source. Render nothing at all rather than a
// broken or misleading empty box.
if ($fdEvtOrgId <= 0) {
    return;
}

$fdEvtKicker   = isset($blockFields['kicker']) ? trim((string) $blockFields['kicker']) : '';
$fdEvtHeading  = isset($blockFields['heading']) ? trim((string) $blockFields['heading']) : 'Upcoming Events';
$fdEvtLimit    = fdClampLimit($blockFields['limit'] ?? null, 3, 12);
$fdEvtMoreHref = isset($blockFields['more_href']) ? trim((string) $blockFields['more_href']) : '';
if ($fdEvtMoreHref === '#') {
    // Blank URL fields are rewritten to '#' by the save sanitizer — treat as unset.
    $fdEvtMoreHref = '';
}

$fdEvtRows = [];
if (class_exists('APIModel')) {
    try {
        $fdEvtModel = new APIModel('SearchService');
        // Org-owned upcoming events, date-ordered (date_order=true). The org id
        // fills the kingdom slot (2nd positional) or the park slot (3rd),
        // matching the kingdom-owned upcoming pattern on the kingdom profile.
        $fdEvtResult = ($fdEvtScopeKind === 'kingdom')
            ? $fdEvtModel->Event(null, $fdEvtOrgId, 0, null, null, $fdEvtLimit, null, true)
            : $fdEvtModel->Event(null, null, (int) $fdEvtOrgId, null, null, $fdEvtLimit, null, true);
        if (is_array($fdEvtResult)) {
            $fdEvtRows = array_values($fdEvtResult);
        }
    } catch (\Throwable $e) {
        $fdEvtRows = [];
    }
}
$fdEvtRows = array_slice($fdEvtRows, 0, $fdEvtLimit);
?>
<div class="fd-pad fd-section-light <?= $fdEvtCssNs ?>-block">
    <div class="<?= $fdEvtCssNs ?>-head">
        <div>
            <?php if ($fdEvtKicker !== ''): ?>
                <div class="fd-kicker fd-kicker-d"><?= htmlspecialchars($fdEvtKicker, ENT_QUOTES) ?></div>
            <?php endif; ?>
            <?php if ($fdEvtHeading !== ''): ?>
                <h2 class="<?= $fdEvtCssNs ?>-title fd-sec-title"><?= htmlspecialchars($fdEvtHeading, ENT_QUOTES) ?></h2>
            <?php endif; ?>
        </div>
        <?php if ($fdEvtMoreHref !== ''): ?>
            <a class="<?= $fdEvtCssNs ?>-more" href="<?= htmlspecialchars($fdEvtMoreHref, ENT_QUOTES) ?>">All events &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($fdEvtRows)): ?>
        <div class="<?= $fdEvtCssNs ?>-empty">No upcoming events right now.</div>
    <?php else: ?>
        <div class="<?= $fdEvtCssNs ?>-grid">
            <?php foreach ($fdEvtRows as $fdEvtRow): ?>
                <?php
                if (!is_array($fdEvtRow)) {
                    continue;
                }
                $fdEvtEventId  = (int) ($fdEvtRow['EventId'] ?? 0);
                $fdEvtDetailId = (int) ($fdEvtRow['NextDetailId'] ?? 0);
                if ($fdEvtEventId <= 0 || $fdEvtDetailId <= 0) {
                    continue;
                }
                $fdEvtName     = htmlspecialchars(stripslashes((string) ($fdEvtRow['Name'] ?? '')), ENT_QUOTES);
                $fdEvtParkName = $fdEvtShowParkName
                    ? htmlspecialchars(stripslashes((string) ($fdEvtRow['ParkName'] ?? '')), ENT_QUOTES)
                    : '';
                $fdEvtRsvp     = (int) ($fdEvtRow['RsvpGoing'] ?? 0);
                $fdEvtDateOut  = fdFormatDate($fdEvtRow['NextDate'] ?? '', 'D · M j');
                ?>
                <a class="<?= $fdEvtCssNs ?>-card" href="<?= UIR ?>Event/detail/<?= $fdEvtEventId ?>/<?= $fdEvtDetailId ?>">
                    <div class="<?= $fdEvtCssNs ?>-card-accent"></div>
                    <div class="<?= $fdEvtCssNs ?>-card-body">
                        <?php if ($fdEvtDateOut !== ''): ?>
                            <div class="<?= $fdEvtCssNs ?>-card-date"><?= htmlspecialchars($fdEvtDateOut, ENT_QUOTES) ?></div>
                        <?php endif; ?>
                        <div class="<?= $fdEvtCssNs ?>-card-name"><?= $fdEvtName ?></div>
                        <?php if ($fdEvtShowParkName && $fdEvtParkName !== ''): ?>
                            <div class="<?= $fdEvtCssNs ?>-card-sub"><?= $fdEvtParkName ?></div>
                        <?php endif; ?>
                        <?php if ($fdEvtRsvp > 0): ?>
                            <div class="<?= $fdEvtCssNs ?>-card-rsvp"><?= $fdEvtRsvp ?> going</div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
