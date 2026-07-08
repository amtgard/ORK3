<?php
/**
 * Partial: cta_band.tpl
 * Receives: $blockFields (logo, heading, subcopy, ctas[], links), $LoggedIn, $ViewerName, $UserKingdomId, UIR
 */
$logo    = $blockFields['logo']    ?? [];
$heading = $blockFields['heading'] ?? '';
$subcopy = $blockFields['subcopy'] ?? '';
$ctas    = $blockFields['ctas']    ?? [];
$links   = $blockFields['links']   ?? '';
?>
<div class="fd-pad" style="background:var(--navy);color:var(--fd-primary-contrast);text-align:center;">
    <?php if (!empty($logo['src'])): ?>
        <img src="<?= htmlspecialchars($logo['src'], ENT_QUOTES) ?>"
             alt="<?= htmlspecialchars($logo['alt'] ?? '', ENT_QUOTES) ?>"
             style="height:54px;margin-bottom:18px;opacity:.95;">
    <?php endif; ?>

    <?php if (!empty($heading)): ?>
        <h2 class="fd-serif" style="font-size:32px;color:var(--fd-primary-contrast);margin:0 0 8px;">
            <?= htmlspecialchars($heading, ENT_QUOTES) ?>
        </h2>
    <?php endif; ?>

    <?php if (!empty($subcopy)): ?>
        <p style="opacity:.8;margin:0 0 20px;">
            <?= htmlspecialchars($subcopy, ENT_QUOTES) ?>
        </p>
    <?php endif; ?>

    <?php foreach ($ctas as $i => $cta): ?>
        <?php
        $btnClass = ($cta['style'] ?? '') === 'gold' ? 'fd-btn-gold' : 'fd-btn-ghost';
        $marginLeft = $i > 0 ? ' style="margin-left:10px;"' : '';
        $ctaHref = (!empty($cta['href']) && CmsSanitizer::IsSafeUrl($cta['href'])) ? $cta['href'] : '#';
        ?>
        <a class="<?= htmlspecialchars($btnClass, ENT_QUOTES) ?>"
           href="<?= htmlspecialchars($ctaHref, ENT_QUOTES) ?>"<?= $marginLeft ?>>
            <?= htmlspecialchars($cta['label'] ?? '', ENT_QUOTES) ?>
        </a>
    <?php endforeach; ?>

    <?php if (!empty($links)): ?>
        <div style="margin-top:18px;font-size:12px;opacity:.5;">
            <?= htmlspecialchars($links, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>
</div>
