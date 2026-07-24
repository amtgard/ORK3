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
        <img src="<?= htmlspecialchars($logo['display'] ?? $logo['src'], ENT_QUOTES) ?>"
             alt="<?= htmlspecialchars($logo['alt'] ?? '', ENT_QUOTES) ?>"
             style="height:54px;margin-bottom:18px;opacity:.95;">
    <?php endif; ?>

    <?php if (!empty($heading)): ?>
        <h2 class="fd-serif" style="font-size:32px;color:var(--fd-primary-contrast);margin:0 0 8px;">
            <?= htmlspecialchars($heading, ENT_QUOTES) ?>
        </h2>
    <?php endif; ?>

    <?php if (!empty($subcopy)): ?>
        <p style="opacity:.8;margin:0 0 20px;white-space:pre-line;">
            <?= htmlspecialchars($subcopy, ENT_QUOTES) ?>
        </p>
    <?php endif; ?>

    <?php if (!empty($ctas)): ?>
        <div style="display:flex;flex-wrap:wrap;gap:12px;justify-content:center;">
            <?php foreach ($ctas as $i => $cta): ?>
                <?php
                $btnClass = ($cta['style'] ?? '') === 'gold' ? 'fd-btn-gold' : 'fd-btn-ghost';
                $ctaHref = CmsSanitizer::SafeHrefOrHash($cta['href'] ?? '');
                ?>
                <a class="<?= htmlspecialchars($btnClass, ENT_QUOTES) ?>"
                   href="<?= htmlspecialchars($ctaHref, ENT_QUOTES) ?>">
                    <?= htmlspecialchars($cta['label'] ?? '', ENT_QUOTES) ?>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($links)): ?>
        <div style="margin-top:18px;font-size:13px;opacity:.75;">
            <?= htmlspecialchars($links, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>
</div>
