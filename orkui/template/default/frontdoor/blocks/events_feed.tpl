<?php
/**
 * Partial: events_feed.tpl
 * Receives: $blockFields (kicker, heading, limit, more_href), $EventSummary (array of rows), UIR
 * Row keys: EventId, Name, KingdomId, KingdomName, ParkId, ParkName, NextDate (Y-m-d H:i:s), NextDetailId, RsvpGoing
 */
$efKicker   = $blockFields['kicker']    ?? '';
$efHeading  = $blockFields['heading']   ?? '';
// A cleared number input arrives as 0/blank — fall back to a sane default rather
// than slicing to an empty grid under a live heading (sibling blocks do the same).
// No upper bound here, unlike the sibling list blocks.
$efLimit    = fdClampLimit($blockFields['limit'] ?? null, 3);
$efMoreHref = $blockFields['more_href'] ?? '';

// The block is addable on any page, but $EventSummary is only injected by the
// front-door index render path — guard the read so other scopes render cleanly.
$efEvents = (isset($EventSummary) && is_array($EventSummary)) ? $EventSummary : [];

$efHasRows = count($efEvents) > 0;
$efRows    = $efHasRows ? array_slice($efEvents, 0, $efLimit) : [];
?>
<div class="fd-pad fd-section-light">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;">
        <div>
            <?php if (!empty($efKicker)): ?>
                <div class="fd-kicker fd-kicker-d">
                    <?= htmlspecialchars($efKicker, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($efHeading)): ?>
                <h2 class="fd-sec-title">
                    <?= htmlspecialchars($efHeading, ENT_QUOTES) ?>
                </h2>
            <?php endif; ?>
        </div>
        <?php if (!empty($efMoreHref)): ?>
            <a class="fd-link" href="<?= htmlspecialchars($efMoreHref, ENT_QUOTES) ?>">All events &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (!$efHasRows): ?>
        <div class="fd-empty">No upcoming events right now.</div>
    <?php else: ?>
        <div class="fd-events-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
            <?php foreach ($efRows as $efRow): ?>
                <?php
                $efEventId    = (int)$efRow['EventId'];
                $efDetailId   = (int)$efRow['NextDetailId'];
                if ($efEventId <= 0 || $efDetailId <= 0) {
                    continue;
                }
                $efRsvpGoing  = (int)($efRow['RsvpGoing'] ?? $efRow['RsvpCount'] ?? 0);
                $efName       = htmlspecialchars(stripslashes($efRow['Name'] ?? ''), ENT_QUOTES);
                $efKingdomName = htmlspecialchars(stripslashes($efRow['KingdomName'] ?? ''), ENT_QUOTES);
                $efDateLabel  = fdFormatDate($efRow['NextDate'] ?? '', 'D · M j');
                ?>
                <a class="fd-card" href="<?= UIR ?>Event/detail/<?= $efEventId ?>/<?= $efDetailId ?>"
                   style="text-decoration:none;color:inherit;display:block;">
                    <div style="height:8px;background:var(--gold);"></div>
                    <div style="padding:16px;">
                        <?php if (!empty($efDateLabel)): ?>
                            <div class="fd-ev-date">
                                <?= htmlspecialchars($efDateLabel, ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-weight:700;font-size:15px;margin:4px 0;">
                            <?= $efName ?>
                        </div>
                        <div class="fd-ev-sub">
                            <?= $efKingdomName ?>
                        </div>
                        <?php if ($efRsvpGoing > 0): ?>
                            <div class="fd-ev-rsvp">
                                <?= $efRsvpGoing ?> going
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
