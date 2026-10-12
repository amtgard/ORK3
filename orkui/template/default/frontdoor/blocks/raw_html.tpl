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
 * script, style, iframe, object, embed, form (+ noscript, svg, math, link,
 * meta, base, input, button, textarea, select). So pasting a third-party
 * embed (a YouTube/Vimeo iframe, an analytics script, a form)
 * yields a BLANK block, silently. That is intentional (do NOT weaken the
 * sanitizer) — but it must not be a mystery to the author, so the preview
 * surfaces a note when the cleaned output came back empty (see below). For real
 * video embeds, authors should use the dedicated Video Embed block, which has a
 * curated provider allowlist.
 */
$fdbHtml = $blockFields['html'] ?? '';
// Editor/preview context? $fdIsPreview comes from render_blocks.tpl (the only
// includer of this partial): the Site controller sets SitePreview only for an
// authorized officer previewing an unpublished site, and the Cms controllers set
// PreviewPage only for a page/post draft preview, so this note is NEVER shown to
// a public visitor.
?>
<?php if ($fdbHtml !== ''): ?>
<div class="fd-pad fdb-rawhtml">
    <?php /* sanitized at save (CmsSanitizer::Clean) */ ?>
    <?= $fdbHtml ?>
</div>
<?php elseif ($fdIsPreview): ?>
<?php /* Empty AFTER sanitize → likely a stripped embed. Author-only hint. */ ?>
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
