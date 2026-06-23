<?php
/**
 * Partial: events_feed.tpl
 * Receives: $blockFields (kicker, heading, limit, more_href), $EventSummary (array of rows), UIR
 * Row keys: EventId, Name, KingdomId, KingdomName, ParkId, ParkName, NextDate (Y-m-d H:i:s), NextDetailId, RsvpGoing
 */
$kicker   = $blockFields['kicker']    ?? '';
$heading  = $blockFields['heading']   ?? '';
$limit    = (int)($blockFields['limit'] ?? 3);
$moreHref = $blockFields['more_href'] ?? '';

$hasRows = is_array($EventSummary) && count($EventSummary) > 0;
$rows    = $hasRows ? array_slice($EventSummary, 0, $limit) : [];
?>
<div class="fd-pad fd-section-light" style="background:#fff;">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:18px;">
        <div>
            <?php if (!empty($kicker)): ?>
                <div class="fd-kicker fd-kicker-d">
                    <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
                </div>
            <?php endif; ?>
            <?php if (!empty($heading)): ?>
                <h3 class="fd-sec-title">
                    <?= htmlspecialchars($heading, ENT_QUOTES) ?>
                </h3>
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
                $rsvpGoing  = (int)$row['RsvpGoing'];
                $name       = htmlspecialchars(stripslashes($row['Name'] ?? ''), ENT_QUOTES);
                $kingdomName = htmlspecialchars(stripslashes($row['KingdomName'] ?? ''), ENT_QUOTES);
                $nextDate   = $row['NextDate'] ?? '';
                $dateLabel  = '';
                if (!empty($nextDate)) {
                    $ts = strtotime($nextDate);
                    if ($ts !== false) {
                        $dateLabel = date('D · M j', $ts);
                    }
                }
                ?>
                <a class="fd-card" href="<?= UIR ?>Event/detail/<?= $detailId ?>"
                   style="text-decoration:none;color:inherit;display:block;">
                    <div style="height:8px;background:var(--gold);"></div>
                    <div style="padding:16px;">
                        <?php if (!empty($dateLabel)): ?>
                            <div style="font-size:12px;color:#b8860b;font-weight:700;text-transform:uppercase;">
                                <?= htmlspecialchars($dateLabel, ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                        <div style="font-weight:700;font-size:15px;margin:4px 0;">
                            <?= $name ?>
                        </div>
                        <div style="font-size:12px;color:#778;">
                            <?= $kingdomName ?>
                        </div>
                        <?php if ($rsvpGoing > 0): ?>
                            <div style="font-size:12px;color:#1d4ed8;margin-top:6px;font-weight:600;">
                                <?= $rsvpGoing ?> going
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
