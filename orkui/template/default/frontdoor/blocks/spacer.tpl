<?php
/**
 * Partial: spacer.tpl
 * Receives: $blockFields (size['sm'|'md'|'lg']), UIR
 * Vertical whitespace only. Self-contained scoped styles. No JS, theme-agnostic.
 */
$size = $blockFields['size'] ?? 'md';
$size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
?>
<style>
.fdb-spacer-sm { height: 16px; }
.fdb-spacer-md { height: 36px; }
.fdb-spacer-lg { height: 72px; }
</style>
<div class="fdb-spacer-<?= $size ?>" aria-hidden="true"></div>
