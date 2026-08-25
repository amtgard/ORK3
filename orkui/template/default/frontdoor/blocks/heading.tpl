<?php
/**
 * Partial: heading.tpl
 * Receives: $blockFields (text, level[1..4 default 2], align[left|center|right]), UIR
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
<?php if ($text !== ''): ?>
<div class="fd-pad fdb-heading-wrap fdb-heading-<?= $align ?>">
    <<?= $tag ?> class="fdb-heading fdb-heading-<?= $level ?>">
        <?= htmlspecialchars($text, ENT_QUOTES) ?>
    </<?= $tag ?>>
</div>
<?php endif; ?>
