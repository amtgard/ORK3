<?php
/**
 * Partial: divider.tpl
 * Receives: $blockFields (style['line'|'dots' default 'line']), UIR
 * Self-contained: scoped .fdb-divider-* styles (light + dark). No JS.
 */
$style = $blockFields['style'] ?? 'line';
$style = in_array($style, ['line', 'dots'], true) ? $style : 'line';
?>
<style>
.fdb-divider {
    border: 0;
    margin: 0;
}
.fdb-divider-line {
    height: 1px;
    background: #e2e6ef;
}
.fdb-divider-dots {
    height: 0;
    border-top: 3px dotted #cfd6e4;
    width: 90px;
    margin: 0 auto;
}
html[data-theme="dark"] .fdb-divider-line {
    background: #2a3450;
}
html[data-theme="dark"] .fdb-divider-dots {
    border-top-color: #3a4566;
}
</style>
<div class="fd-pad" style="padding-top:8px;padding-bottom:8px;">
    <hr class="fdb-divider fdb-divider-<?= $style ?>">
</div>
