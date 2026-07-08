<?php
/**
 * Partial: kingdoms_teaser.tpl
 * Receives: $blockFields (kicker, heading, limit, more_href), $ActiveKingdomSummary (array), UIR
 * Row keys: KingdomId, ParentKingdomId, KingdomName, ParkCount
 * Only renders parent kingdoms (ParentKingdomId === 0).
 */
$kicker   = $blockFields['kicker']    ?? '';
$heading  = $blockFields['heading']   ?? '';
$limit    = (int)($blockFields['limit'] ?? 12);
$moreHref = $blockFields['more_href'] ?? '';

// Filter to parent kingdoms only
$allKingdoms = [];
if (is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'] ?? null)) {
    foreach ($ActiveKingdomSummary['ActiveKingdomsSummaryList'] as $r) {
        if ((int)$r['ParentKingdomId'] === 0) {
            $allKingdoms[] = $r;
        }
    }
}

$totalParent  = count($allKingdoms);
$shown        = array_slice($allKingdoms, 0, $limit);
$moreCount    = $totalParent - count($shown);
?>
<div class="fd-pad fd-section-muted" style="background:#f7f8fb;">
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
            <a class="fd-link" href="<?= htmlspecialchars($moreHref, ENT_QUOTES) ?>">Browse the full Kingdoms Directory &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($shown)): ?>
        <div class="fd-empty">Kingdoms list unavailable.</div>
    <?php else: ?>
        <div class="fd-kingdoms-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px;">
            <?php foreach ($shown as $row): ?>
                <?php
                $kingdomId   = (int)$row['KingdomId'];
                $kingdomName = htmlspecialchars(stripslashes($row['KingdomName'] ?? ''), ENT_QUOTES);
                $heraldryUrl = htmlspecialchars(
                    HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf('%04d', $kingdomId)),
                    ENT_QUOTES
                );
                ?>
                <a class="fd-card" href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"
                   style="padding:12px;text-align:center;text-decoration:none;color:inherit;display:block;">
                    <div style="height:48px;display:flex;align-items:center;justify-content:center;">
                        <img src="<?= $heraldryUrl ?>"
                             onerror="this.style.display='none'"
                             alt="<?= $kingdomName ?> heraldry"
                             style="max-height:48px;max-width:100%;object-fit:contain;">
                    </div>
                    <div style="font-size:11px;font-weight:600;margin-top:6px;">
                        <?= $kingdomName ?>
                    </div>
                </a>
            <?php endforeach; ?>

            <?php if ($moreCount > 0 && !empty($moreHref)): ?>
                <a class="fd-card" href="<?= htmlspecialchars($moreHref, ENT_QUOTES) ?>"
                   style="padding:12px;text-align:center;display:flex;flex-direction:column;align-items:center;
                          justify-content:center;background:var(--navy);color:var(--fd-primary-contrast);text-decoration:none;
                          border-color:var(--navy);">
                    <div style="font-size:13px;font-weight:700;">+<?= $moreCount ?> more &rarr;</div>
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>
