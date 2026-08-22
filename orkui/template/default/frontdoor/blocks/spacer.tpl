<?php
/**
 * Partial: spacer.tpl
 * Receives: $blockFields (size['sm'|'md'|'lg']), UIR
 * Vertical whitespace only. CSS lives in frontdoor/css/blocks.css. No JS.
 */
$size = $blockFields['size'] ?? 'md';
$size = in_array($size, ['sm', 'md', 'lg'], true) ? $size : 'md';
?>
<div class="fdb-spacer-<?= $size ?>" aria-hidden="true"></div>
