<?php
/**
 * Partial: rich_text.tpl  (CMS alias of richtext.tpl)
 * Receives: $blockFields (kicker, heading, body, align, cta?), UIR
 * A block typed 'rich_text' renders identically to the shipped 'richtext' block.
 * body is sanitized server-side (CmsSanitizer / HTML Purifier) — emitted raw passthrough.
 */
$kicker  = $blockFields['kicker']  ?? '';
$heading = $blockFields['heading'] ?? '';
$body    = $blockFields['body']    ?? '';
$align   = $blockFields['align']   ?? 'left';
$cta     = $blockFields['cta']     ?? [];

$textAlign  = ($align === 'center') ? 'text-align:center;' : '';
$marginAuto = ($align === 'center') ? 'margin:0 auto;' : '';
?>
<div class="fd-pad fd-section-light" style="background:var(--fd-bg);<?= $textAlign ?>">
    <?php if (!empty($kicker)): ?>
        <div class="fd-kicker fd-kicker-d" style="margin-bottom:10px;">
            <?= htmlspecialchars($kicker, ENT_QUOTES) ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($heading)): ?>
        <h3 class="fd-sec-title" style="font-size:34px;margin-bottom:14px;">
            <?= htmlspecialchars($heading, ENT_QUOTES) ?>
        </h3>
    <?php endif; ?>

    <?php if (!empty($body)): ?>
        <div class="fd-body-text" style="max-width:680px;<?= $marginAuto ?>font-size:18px;line-height:1.6;">
            <?php /* sanitized passthrough */ ?>
            <?= $body ?>
        </div>
    <?php endif; ?>

    <?php if (!empty($cta['label'])): ?>
        <div style="margin-top:18px;">
            <a class="fd-link" href="<?= htmlspecialchars((!empty($cta['href']) && CmsSanitizer::IsSafeUrl($cta['href'])) ? $cta['href'] : '#', ENT_QUOTES) ?>">
                <?= htmlspecialchars($cta['label'], ENT_QUOTES) ?>
            </a>
        </div>
    <?php endif; ?>
</div>
