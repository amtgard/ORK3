<?php
/**
 * Partial: events_feed.tpl
 * Receives: $blockFields (kicker, heading, limit, more_href), $EventSummary (array of rows), UIR
 * Row keys: EventId, Name, KingdomId, KingdomName, ParkId, ParkName, NextDate (Y-m-d H:i:s), NextDetailId, RsvpGoing
 */
$kicker   = $blockFields['kicker']    ?? '';
$heading  = $blockFields['heading']   ?? '';
// A cleared number input arrives as 0/blank — fall back to a sane default rather
// than slicing to an empty grid under a live heading (sibling blocks do the same).
// No upper bound here, unlike the sibling list blocks.
$limit    = fdClampLimit($blockFields['limit'] ?? null, 3);
$moreHref = $blockFields['more_href'] ?? '';

// The block is addable on any page, but $EventSummary is only injected by the
// front-door index render path — guard the read so other scopes render cleanly.
$fdEvents = (isset($EventSummary) && is_array($EventSummary)) ? $EventSummary : [];

$hasRows = count($fdEvents) > 0;
$rows    = $hasRows ? array_slice($fdEvents, 0, $limit) : [];
?>
<div class="fd-pad fd-section-light">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;">
        <div>
            <?php if (!empty($kicker)): ?>
                <div class="fd-kicker fd-kicker-d">
                    <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($heading)): ?>
                <h2 class="fd-sec-title">
                    <?= htmlspecialchars($heading, ENT_QUOTES) ?>
                </h2>
            <?php endif; ?>
        </div>
        <?php if (!empty($moreHref)): ?>
            <a class="fd-link" href="<?= htmlspecialchars($moreHref, ENT_QUOTES) ?>">All events &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (!$hasRows): ?>
        <div class="fd-empty">No upcoming events right now.</div>
    <?php else: ?>
        <div class="fd-events-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:16px;">
            <?php foreach ($rows as $row): ?>
                <?php
                $eventId    = (int)$row['EventId'];
                $detailId   = (int)$row['NextDetailId'];
                if ($eventId <= 0 || $detailId <= 0) {
                    continue;
                }
                $rsvpGoing  = (int)($row['RsvpGoing'] ?? $row['RsvpCount'] ?? 0);
                $name       = htmlspecialchars(stripslashes($row['Name'] ?? ''), ENT_QUOTES);
                $kingdomName = htmlspecialchars(stripslashes($row['KingdomName'] ?? ''), ENT_QUOTES);
                $dateLabel  = fdFormatDate($row['NextDate'] ?? '', 'D · M j');
                ?>
                <a class="fd-card" href="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>"
                   style="text-decoration:none;color:inherit;display:block;">
                    <div style="height:8px;background:var(--gold);"></div>
                    <div style="padding:16px;">
                        <?php if (!empty($dateLabel)): ?>
                            <div class="fd-ev-date">
                                <?= htmlspecialchars($dateLabel, ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-weight:700;font-size:15px;margin:4px 0;">
                            <?= $name ?>
                        </div>
                        <div class="fd-ev-sub">
                            <?= $kingdomName ?>
                        </div>
                        <?php if ($rsvpGoing > 0): ?>
                            <div class="fd-ev-rsvp">
                                <?= $rsvpGoing ?> going
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
