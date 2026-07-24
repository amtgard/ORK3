<?php

/*
 * Shared content-block renderer.
 * Expects $fdBlocks in scope: an ordered list of enabled content blocks.
 * Includes one "dumb" partial per block type from frontdoor/blocks/{type}.tpl.
 * Each partial renders $blockFields (+ shared $data + $blockMeta) and fetches nothing.
 *
 * Callers (home page, CMS pages, blog) set $fdBlocks then `include` this file.
 */
$fdBlocks    = isset($fdBlocks) && is_array($fdBlocks) ? $fdBlocks : [];
// #103: columns.tpl re-enters this renderer once per nested column, so a
// columns-in-columns chain recurses back through here. Thread a depth counter
// (shared across the whole include chain — same variable scope) and render
// nothing past a small max, so a pathological nesting can't overflow the PHP
// call stack. Checked BEFORE incrementing; decremented after the loop below.
$fdRenderDepth = isset($fdRenderDepth) ? (int) $fdRenderDepth : 0;
if ($fdRenderDepth >= 4) {
    return;
}
$fdRenderDepth++;
$fdBlockDir  = DIR_TEMPLATE . 'default/frontdoor/blocks/';
// Shared PLAIN-PHP helpers (fdFormatDate, …) — guarded, so a repeat include (e.g.
// columns.tpl re-entering render_blocks) is a no-op. Blocks below rely on these.
require_once DIR_TEMPLATE . 'default/frontdoor/_helpers.tpl';
// Shared "emit this block's inline <style> at most once per request" flag.
// Partials are include()d into this scope, so an assignment they make here
// persists across the loop below (and dedupes repeated block types). Keyed by
// block type, e.g. $fdStyleOnce['heading'].
$fdStyleOnce = isset($fdStyleOnce) && is_array($fdStyleOnce) ? $fdStyleOnce : [];
// #90: preview/admin surfaces (an authorized officer previewing an unpublished
// site, or the CMS draft preview) get a visible placeholder when a block throws,
// so authors can see something is wrong. Public visitors keep the silent swallow.
$fdIsPreview = ! empty($data['SitePreview']) || ! empty($PreviewPage);
foreach ($fdBlocks as $block) {
    if (empty($block['enabled'])) {
        continue;
    }
    $type = preg_replace('/[^a-z_]/', '', (string) $block['type']);
    $partial = $fdBlockDir . $type . '.tpl';
    if (! file_exists($partial)) {
        continue;
    }
    $blockFields = isset($block['fields']) && is_array($block['fields']) ? $block['fields'] : [];
    $blockMeta   = $block;
    // Contain a broken block: a fatal in one partial must not blank the whole
    // page — skip it and render the rest.
    try {
        include $partial;
    } catch (\Throwable $e) {
        // Public visitors: intentionally swallow — one bad block shouldn't take
        // down the page. Preview/admin only: emit a small inline placeholder so
        // the author knows a block failed. Never leak exception details.
        if ($fdIsPreview) {
            echo '<div class="fd-block-error">'
                . '<strong>This block could not be rendered.</strong>'
                . ' It is hidden from public visitors.</div>';
        }
    }
}
// Balance the increment above so the shared counter reflects true nesting depth
// (columns.tpl re-enters this renderer in the same scope), not total invocations.
$fdRenderDepth--;
