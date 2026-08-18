<?php
/**
 * Partial: kingdoms_teaser.tpl
 * Receives: $blockFields (kicker, heading, limit, more_href), $ActiveKingdomSummary (array), UIR
 * Row keys: KingdomId, ParentKingdomId, KingdomName, ParkCount
 * Only renders parent kingdoms (ParentKingdomId === 0).
 */
$kicker   = $blockFields['kicker']    ?? '';
$heading  = $blockFields['heading']   ?? '';
// #66: a cleared number input arrives as 0/blank → fall back to the default
// rather than emptying the grid under a live heading.
// #11: clamp to a hard max so an authored huge value can't blow out the grid.
$limit    = fdClampLimit(
    $blockFields['limit'] ?? null,
    CmsRenderCache::TEASER_LIMIT_DEFAULT,
    CmsRenderCache::TEASER_LIMIT_MAX
);
$moreHref = $blockFields['more_href'] ?? '';
$moreHref = (is_string($moreHref) && $moreHref !== '' && CmsSanitizer::IsSafeUrl($moreHref)) ? $moreHref : '';

// #11: resolve the parent-kingdom teaser list — filter → slice → resolve each
// heraldry URL (a per-row file_exists() disk probe via resolve_image_ext) — ONCE,
// then cache the fully-hydrated result in GhettoCache keyed by the clamped limit
// (mirrors kingdom_parks.tpl $kpResolved/$kpCache). The kingdom set is global and
// safe to share across viewers; a short TTL keeps it fresh. Cached hits skip the
// per-row disk probes entirely.
// $ktResolved = ['shown' => [ ['id','name','heraldry'], … ], 'total' => int].
//
// This is the ONE cached block whose key carries no org id — it is the global
// front door's own. That is why it was invisible to CmsAjax::clearrendercache
// for so long (the flush loop only ever ran over a kingdom/park id); it is now
// enumerated there under the global scope, via the same registry.
// Captured for the closure (which does not inherit template scope): this block
// is also included from contexts that never inject the summary at all.
$ktSummary = (isset($ActiveKingdomSummary) && is_array($ActiveKingdomSummary)) ? $ActiveKingdomSummary : [];
$ktResolved = fdBlockCache(
    CmsRenderCache::NS_KINGDOMS_TEASER,
    CmsRenderCache::TeaserKey($limit),
    CmsRenderCache::TTL,
    function () use ($limit, $ktSummary) {
        // Filter to parent kingdoms only.
        $allKingdoms = [];
        if (is_array($ktSummary['ActiveKingdomsSummaryList'] ?? null)) {
            foreach ($ktSummary['ActiveKingdomsSummaryList'] as $r) {
                if ((int)$r['ParentKingdomId'] === 0) {
                    $allKingdoms[] = $r;
                }
            }
        }
        $totalParent = count($allKingdoms);
        $shownRows   = [];
        foreach (array_slice($allKingdoms, 0, $limit) as $r) {
            $kid = (int)$r['KingdomId'];
            $shownRows[] = [
                'id'       => $kid,
                'name'     => stripslashes($r['KingdomName'] ?? ''),
                'heraldry' => HTTP_KINGDOM_HERALDRY . Common::resolve_image_ext(DIR_KINGDOM_HERALDRY, sprintf('%04d', $kid)),
            ];
        }
        return ['shown' => $shownRows, 'total' => $totalParent];
    },
    // Only cache once we actually have kingdom data — never poison the key with an
    // empty result from a context that doesn't inject $ActiveKingdomSummary.
    static function ($built) {
        return (int)($built['total'] ?? 0) > 0;
    }
);

$shown        = $ktResolved['shown'];
$moreCount    = (int)$ktResolved['total'] - count($shown);
?>
<div class="fd-pad fd-section-muted">
    <div style="display:flex;justify-content:space-between;align-items:flex-end;gap:12px;flex-wrap:wrap;margin-bottom:18px;">
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
            <a class="fd-link" href="<?= htmlspecialchars($moreHref, ENT_QUOTES) ?>" style="white-space:nowrap;">Browse the full Kingdoms Directory &rarr;</a>
        <?php endif; ?>
    </div>

    <?php if (empty($shown)): ?>
        <div class="fd-empty">Kingdoms list unavailable.</div>
    <?php else: ?>
        <div class="fd-kingdoms-grid" style="display:grid;grid-template-columns:repeat(7,1fr);gap:10px;">
            <?php foreach ($shown as $row): ?>
                <?php
                $kingdomId   = (int)$row['id'];
                $kingdomName = htmlspecialchars($row['name'], ENT_QUOTES);
                $heraldryUrl = htmlspecialchars($row['heraldry'], ENT_QUOTES);
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
