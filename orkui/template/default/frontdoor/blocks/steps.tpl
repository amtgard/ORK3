<?php
/**
 * Partial: steps.tpl
 * Receives: $blockFields (kicker, heading, band, steps[], cta?), UIR
 * steps[] each: n, title, body
 */
$kicker  = $blockFields['kicker']  ?? '';
$heading = $blockFields['heading'] ?? '';
$band    = $blockFields['band']    ?? 'light';
$steps   = $blockFields['steps']   ?? [];
$cta     = $blockFields['cta']     ?? [];

$isDark  = ($band === 'dark');
$bgStyle = $isDark ? 'background:var(--navy);color:var(--fd-primary-contrast);' : 'background:#f7f8fb;';
?>
<div class="fd-pad" style="<?= $bgStyle ?>">
    <div style="text-align:center;margin-bottom:26px;">
        <?php if (!empty($kicker)): ?>
            <div class="fd-kicker" style="margin-bottom:8px;">
                <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($heading)): ?>
            <h2 class="fd-sec-title fd-serif" style="<?= $isDark ? 'color:var(--fd-primary-contrast);' : '' ?>">
                <?= htmlspecialchars($heading, ENT_QUOTES) ?>
            </h2>
        <?php endif; ?>
    </div>

    <?php if (!empty($steps)): ?>
        <style>
            @media (max-width:680px){.fdb-steps-grid{grid-template-columns:1fr !important;}}
        </style>
        <div class="fdb-steps-grid" style="display:grid;grid-template-columns:repeat(<?= count($steps) ?>,1fr);gap:20px;">
            <?php foreach ($steps as $step): ?>
                <?php
                $n     = (int)($step['n']     ?? 0);
                $title = $step['title'] ?? '';
                $body  = $step['body']  ?? '';
                ?>
                <div style="text-align:center;">
                    <?php if ($n > 0): ?>
                        <div style="width:54px;height:54px;border-radius:50%;background:var(--gold);color:#1a1205;font-weight:800;font-size:22px;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;">
                            <?= $n ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($title)): ?>
                        <div style="font-weight:700;font-size:17px;margin-bottom:6px;">
                            <?= htmlspecialchars($title, ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($body)): ?>
                        <div class="fd-body-text" style="font-size:14px;<?= $isDark ? 'opacity:.75;' : '' ?>">
                            <?= htmlspecialchars($body, ENT_QUOTES) ?>
                        </div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <div style="text-align:center;margin-top:26px;">
            <a class="fd-btn-gold" href="<?= htmlspecialchars((!empty($cta['href']) && CmsSanitizer::IsSafeUrl($cta['href'])) ? $cta['href'] : '#', ENT_QUOTES) ?>">
                <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
