<?php
/**
 * Partial: heading.tpl
 * Receives: $blockFields (text, level[2..4 default 2], align[left|center|right]), UIR
 * Self-contained: scoped .fdb-heading-* styles (light + dark). No JS.
 */
$text  = $blockFields['text']  ?? '';
$level = (int) ($blockFields['level'] ?? 2);
// Allow h1..h4 (h1 for pages with no hero headline of their own). Visual size is
// class-driven, so the chosen tag only affects the document outline.
if ($level < 1 || $level > 4) {
    $level = 2;
}
$align = $blockFields['align'] ?? 'left';
$align = in_array($align, ['left', 'center', 'right'], true) ? $align : 'left';
$tag   = 'h' . $level;
?>
<?php // Emit this block's static CSS at most once per request (dedupes repeats). ?>
<?php if (empty($fdStyleOnce['heading'])) : $fdStyleOnce['heading'] = true; ?>
<style>
/* Reset the global orkui.css h1-h6 gray-box styling for this block's heading. */
.fdb-heading-wrap .fdb-heading {
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 0;
    text-shadow: none;
    margin: 0;
    color: var(--ink, #1a2236);
    font-family: Georgia, "Times New Roman", serif;
    line-height: 1.25;
}
.fdb-heading-wrap .fdb-heading-1 { font-size: 40px; }
.fdb-heading-wrap .fdb-heading-2 { font-size: 34px; }
.fdb-heading-wrap .fdb-heading-3 { font-size: 26px; }
.fdb-heading-wrap .fdb-heading-4 { font-size: 21px; }
.fdb-heading-left   { text-align: left; }
.fdb-heading-center { text-align: center; }
.fdb-heading-right  { text-align: right; }
html[data-theme="dark"] .fdb-heading-wrap .fdb-heading {
    color: #eef2fb;
}
</style>
<?php endif; ?>
<?php if ($text !== ''): ?>
<div class="fd-pad fdb-heading-wrap fdb-heading-<?= $align ?>">
    <<?= $tag ?> class="fdb-heading fdb-heading-<?= $level ?>">
        <?= htmlspecialchars($text, ENT_QUOTES) ?>
    </<?= $tag ?>>
</div>
<?php endif; ?>
