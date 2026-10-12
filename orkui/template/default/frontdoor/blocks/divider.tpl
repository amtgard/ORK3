<?php
/**
 * Partial: divider.tpl
 * Receives: $blockFields (style['line'|'dots' default 'line']), UIR
 * Self-contained: scoped .fdb-divider-* styles (light + dark). No JS.
 */
$style = $blockFields['style'] ?? 'line';
$style = in_array($style, ['line', 'dots'], true) ? $style : 'line';
?>
<div class="fd-pad" style="padding-top:8px;padding-bottom:8px;">
    <hr class="fdb-divider fdb-divider-<?= $style ?>">
</div>
