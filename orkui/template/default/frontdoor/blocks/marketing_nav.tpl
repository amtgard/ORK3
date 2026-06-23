<?php
/**
 * Partial: marketing_nav.tpl
 * Receives: $blockFields (logo, items[], cta, login), $LoggedIn, $ViewerName, $UserKingdomId, UIR
 */
$logo  = $blockFields['logo']  ?? [];
$items = $blockFields['items'] ?? [];
$cta   = $blockFields['cta']   ?? [];
$login = $blockFields['login'] ?? [];
?>
<nav class="fd-nav">
    <?php if (!empty($logo['src'])): ?>
        <img class="fd-logo"
             src="<?= htmlspecialchars($logo['src'], ENT_QUOTES) ?>"
             alt="<?= htmlspecialchars($logo['alt'] ?? '', ENT_QUOTES) ?>">
    <?php endif; ?>

    <div class="fd-navlinks">
        <?php foreach ($items as $item): ?>
            <div class="fd-navitem">
                <?php if (!empty($item['children'])): ?>
                    <a href="<?= htmlspecialchars($item['href'] ?? '#', ENT_QUOTES) ?>">
                        <?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?> &#9660;
                    </a>
                    <div class="fd-dropdown">
                        <?php foreach ($item['children'] as $child): ?>
                            <a href="<?= htmlspecialchars($child['href'] ?? '#', ENT_QUOTES) ?>">
                                <?= htmlspecialchars($child['label'] ?? '', ENT_QUOTES) ?>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <a href="<?= htmlspecialchars($item['href'] ?? '#', ENT_QUOTES) ?>">
                        <?= htmlspecialchars($item['label'] ?? '', ENT_QUOTES) ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($login['label'])): ?>
        <a class="fd-nav-login" href="<?= htmlspecialchars($login['href'] ?? '#', ENT_QUOTES) ?>">
            <?= htmlspecialchars($login['label'], ENT_QUOTES) ?>
        </a>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <a class="fd-nav-cta" href="<?= htmlspecialchars($cta['href'] ?? '#', ENT_QUOTES) ?>">
            <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
        </a>
    <?php endif; ?>

    <button class="fd-nav-toggle" aria-label="Menu">&#9776;</button>
</nav>
