<?php
/**
 * Partial: raw_html.tpl  (LAYOUT block)
 * Receives: $blockFields { html }, shared $data, UIR
 *
 * `html` is sanitized server-side at save (CmsSanitizer) — emitted raw, no
 * extra escaping. Wrapped in a scoped container only.
 */
$fdbHtml = $blockFields['html'] ?? '';
?>
<?php if ($fdbHtml !== ''): ?>
<style>
/* scoped: fdb-rawhtml */
.fdb-rawhtml { word-wrap: break-word; overflow-wrap: anywhere; }
.fdb-rawhtml img { max-width: 100%; height: auto; }
.fdb-rawhtml iframe { max-width: 100%; }
html[data-theme="dark"] .fdb-rawhtml { color: #e6e8ee; }
</style>
<div class="fd-pad fdb-rawhtml">
    <?php /* sanitized */ ?>
    <?= $fdbHtml ?>
</div>
<?php endif; ?>
