<?php
/**
 * Partial: spacer.tpl
 * Receives: $blockFields (size['sm'|'md'|'lg']), UIR
 * Vertical whitespace only. Self-contained scoped styles. No JS, theme-agnostic.
 */
$size = $blockFields['size'] ?? 'md';
$size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
?>
<?php // Emit this block's static CSS at most once per request (dedupes repeats). ?>
<?php if (empty($fdStyleOnce['spacer'])) : $fdStyleOnce['spacer'] = true; ?>
<style>
.fdb-spacer-sm { height: 16px; }
.fdb-spacer-md { height: 36px; }
.fdb-spacer-lg { height: 72px; }
</style>
<?php endif; ?>
<div class="fdb-spacer-<?= $size ?>" aria-hidden="true"></div>
