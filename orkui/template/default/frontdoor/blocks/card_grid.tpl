<?php
/**
 * Partial: card_grid.tpl
 * Receives: $blockFields (kicker, heading, subheading, cards[]), UIR
 * cards[] each: image['src','alt'], icon, title, blurb, href
 */
$kicker     = $blockFields['kicker']     ?? '';
$heading    = $blockFields['heading']    ?? '';
$subheading = $blockFields['subheading'] ?? '';
$cards      = $blockFields['cards']      ?? [];
?>
<div class="fd-pad fd-section-muted" style="background:#f7f8fb;">
    <div style="text-align:center;margin-bottom:22px;">
        <?php if (!empty($kicker)): ?>
            <div class="fd-kicker fd-kicker-d" style="margin-bottom:8px;">
                <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($heading)): ?>
            <h3 class="fd-sec-title">
                <?= htmlspecialchars($heading, ENT_QUOTES) ?>
            </h3>
        <?php endif; ?>

        <?php if (!empty($subheading)): ?>
            <p style="color:#667;margin:6px 0 0;font-size:15px;">
                <?= htmlspecialchars($subheading, ENT_QUOTES) ?>
            </p>
        <?php endif; ?>
    </div>

    <?php if (!empty($cards)): ?>
        <div class="fd-paths-grid" style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;">
            <?php foreach ($cards as $card): ?>
                <?php
                $img   = $card['image'] ?? [];
                $icon  = $card['icon']  ?? '';
                $title = $card['title'] ?? '';
                $blurb = $card['blurb'] ?? '';
                $href  = (!empty($card['href']) && CmsSanitizer::IsSafeUrl($card['href'])) ? $card['href'] : '#';
                ?>
                <a class="fd-path" href="<?= htmlspecialchars($href, ENT_QUOTES) ?>">
                    <?php if (!empty($img['src'])): ?>
                        <img src="<?= htmlspecialchars($img['src'], ENT_QUOTES) ?>"
                             alt="<?= htmlspecialchars($img['alt'] ?? '', ENT_QUOTES) ?>">
                    <?php endif; ?>
                    <div class="fd-path-scrim"></div>
                    <div class="fd-path-label">
                        <div class="fd-serif" style="font-size:22px;">
                            <?php if (!empty($icon)): ?><i class="fas <?= htmlspecialchars($icon, ENT_QUOTES) ?>" style="margin-right:7px;color:var(--gold);"></i><?php endif; ?>
                            <?= htmlspecialchars($title, ENT_QUOTES) ?>
                        </div>
                        <?php if (!empty($blurb)): ?>
                            <div style="font-size:12px;opacity:.85;">
                                <?= htmlspecialchars($blurb, ENT_QUOTES) ?>
                            </div>
                        <?php endif; ?>
                    </div>
                </a>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
