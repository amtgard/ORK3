<?php
/*
 * Partial: raw_html.tpl  (LAYOUT block) — "Custom HTML (limited)"
 * Receives: $blockFields { html }, shared $data, UIR
 *
 * `html` is sanitized server-side at save (CmsSanitizer::Clean) — emitted raw,
 * no extra escaping. Wrapped in a scoped container only.
 *
 * IMPORTANT — this block is LIMITED, not arbitrary HTML. CmsSanitizer removes,
 * ENTIRELY, a set of container tags that can carry active/embedded payloads:
 * <script>, <style>, <iframe>, <object>, <embed>, <form> (+ noscript, svg,
 * math, link, meta, base, input, button, textarea, select). So pasting a
 * third-party embed (a YouTube/Vimeo <iframe>, an analytics <script>, a form)
 * yields a BLANK block, silently. That is intentional (do NOT weaken the
 * sanitizer) — but it must not be a mystery to the author, so the preview
 * surfaces a note when the cleaned output came back empty (see below). For real
 * video embeds, authors should use the dedicated Video Embed block, which has a
 * curated provider allowlist.
 */
$fdbHtml = $blockFields['html'] ?? '';
// Editor/preview context? render_blocks shares $data; the Site controller sets
// SitePreview only for an authorized officer previewing an unpublished site, so
// this note is NEVER shown to a public visitor.
$fdbIsPreview = !empty($data['SitePreview']);
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
    <?php /* sanitized at save (CmsSanitizer::Clean) */ ?>
    <?= $fdbHtml ?>
</div>
<?php elseif ($fdbIsPreview): ?>
<?php /* Empty AFTER sanitize → likely a stripped embed. Author-only hint. */ ?>
<style>
/* scoped: fdb-rawhtml-empty (preview-only author note) */
.fdb-rawhtml-empty { margin: 12px 16px; padding: 14px 16px; border: 1px dashed #c9a227;
    border-radius: 10px; background: #fbf6e7; color: #5c4a12; font-size: 14px; line-height: 1.5; }
.fdb-rawhtml-empty strong { color: #3f3208; }
.fdb-rawhtml-empty code { background: rgba(0,0,0,.06); padding: 1px 5px; border-radius: 4px;
    font-size: 12px; }
html[data-theme="dark"] .fdb-rawhtml-empty { border-color: #6d5a1c; background: #241f10;
    color: #e8d99a; }
html[data-theme="dark"] .fdb-rawhtml-empty strong { color: #f4ecc7; }
html[data-theme="dark"] .fdb-rawhtml-empty code { background: rgba(255,255,255,.1); }
</style>
<div class="fd-pad">
    <div class="fdb-rawhtml-empty" role="note">
        <strong>This Custom HTML block is empty.</strong>
        If you pasted an embed, its markup was removed on save &mdash; this block does not allow
        <code>&lt;iframe&gt;</code>, <code>&lt;script&gt;</code>, <code>&lt;style&gt;</code>,
        <code>&lt;embed&gt;</code>, <code>&lt;object&gt;</code> or <code>&lt;form&gt;</code> tags.
        For a video, use the <strong>Video Embed</strong> block instead. (Only you, as an editor, see this note.)
    </div>
</div>
<?php endif; ?>
