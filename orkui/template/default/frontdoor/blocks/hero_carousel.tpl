<?php
// hero_carousel.tpl — Plain PHP partial (extract()+include). No Smarty.
// Receives: $blockFields, $ActiveKingdomSummary, $EventSummary, UIR (constant)

// --- Stat ticker computation ---
$list = (isset($ActiveKingdomSummary['ActiveKingdomsSummaryList']) && is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList']))
    ? $ActiveKingdomSummary['ActiveKingdomsSummaryList']
    : [];
$wkStart  = strtotime('-6 month');
$wkCount  = max(1, (int)ceil((time() - $wkStart) / (7 * 86400)));
$kCount   = 0;
$parks    = 0;
$att      = 0;
foreach ($list as $r) {
    if ((int)$r['ParentKingdomId'] === 0) {
        $kCount++;
        $parks += (int)$r['ParkCount'];
        $att   += (int)$r['Attendance'];
    }
}
$weekly      = $att > 0 ? round($att / $wkCount) : 0;
$eventsCount = is_array($EventSummary ?? null) ? count($EventSummary) : 0;

// --- Field helpers ---
$logo       = $blockFields['logo']        ?? [];
$autoplayMs = (int)($blockFields['autoplay_ms'] ?? 4500);
$slides     = $blockFields['slides']      ?? [];
$ctas       = $blockFields['ctas']        ?? [];
?>
<div class="fd-carousel" data-autoplay="<?= $autoplayMs ?>">

    <?php if (!empty($logo['src'])): ?>
    <img
        src="<?= htmlspecialchars($logo['src'], ENT_QUOTES, 'UTF-8') ?>"
        alt="<?= htmlspecialchars($logo['alt'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
        style="position:absolute;top:24px;left:56px;height:48px;z-index:4;filter:drop-shadow(0 2px 8px rgba(0,0,0,.6))"
    >
    <?php endif; ?>

    <?php foreach ($slides as $idx => $slide):
        $isFirst   = ($idx === 0);
        $imgSrc    = htmlspecialchars($slide['image']['src'] ?? '', ENT_QUOTES, 'UTF-8');
        $imgAlt    = htmlspecialchars($slide['image']['alt'] ?? '', ENT_QUOTES, 'UTF-8');
        $kicker    = htmlspecialchars($slide['kicker']   ?? '', ENT_QUOTES, 'UTF-8');
        $headline  = htmlspecialchars($slide['headline'] ?? '', ENT_QUOTES, 'UTF-8');
        $subcopy   = htmlspecialchars($slide['subcopy']  ?? '', ENT_QUOTES, 'UTF-8');
    ?>
    <div class="fd-slide<?= $isFirst ? ' is-active' : '' ?>">
        <img class="fd-slide-img" src="<?= $imgSrc ?>" alt="<?= $imgAlt ?>">
        <div class="fd-slide-scrim"></div>
        <div class="fd-slide-body">
            <?php if ($kicker !== ''): ?>
            <div class="fd-kicker" style="margin-bottom:14px"><?= $kicker ?></div>
            <?php endif; ?>
            <div class="fd-serif fd-hero-headline" style="font-size:58px;line-height:1.0;text-shadow:0 3px 18px rgba(0,0,0,.6);margin-bottom:16px"><?= $headline ?></div>
            <?php if ($subcopy !== ''): ?>
            <p style="margin:0 0 26px;font-size:18px;color:rgba(255,255,255,.88);max-width:470px"><?= $subcopy ?></p>
            <?php endif; ?>
            <?php foreach ($ctas as $ctaIdx => $cta):
                $ctaLabel = htmlspecialchars($cta['label'] ?? '', ENT_QUOTES, 'UTF-8');
                $ctaHref  = htmlspecialchars($cta['href']  ?? '#',  ENT_QUOTES, 'UTF-8');
                $ctaClass = ($cta['style'] ?? '') === 'ghost' ? 'fd-btn-ghost' : 'fd-btn-gold';
                $ctaStyle = $ctaIdx > 0 ? ' style="margin-left:10px"' : '';
            ?>
            <a class="<?= $ctaClass ?>" href="<?= $ctaHref ?>"<?= $ctaStyle ?>><?= $ctaLabel ?></a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>

    <?php if (!empty($slides)): ?>
    <div class="fd-dots">
        <?php foreach ($slides as $idx => $slide): ?>
        <button class="fd-dot<?= $idx === 0 ? ' on' : '' ?>"></button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Live stat ticker pinned to carousel base -->
    <div class="fd-stat-ticker">
        <div class="fd-stat-ticker-cell">
            <div class="fd-stat-num"><?= number_format($kCount) ?></div>
            <div class="fd-stat-label">Kingdoms</div>
        </div>
        <div class="fd-stat-ticker-cell">
            <div class="fd-stat-num"><?= number_format($parks) ?></div>
            <div class="fd-stat-label">Parks</div>
        </div>
        <div class="fd-stat-ticker-cell">
            <div class="fd-stat-num">~<?= number_format($weekly) ?></div>
            <div class="fd-stat-label">Players / Week</div>
        </div>
        <div class="fd-stat-ticker-cell">
            <div class="fd-stat-num"><?= number_format($eventsCount) ?></div>
            <div class="fd-stat-label">Events</div>
        </div>
    </div>

</div>
