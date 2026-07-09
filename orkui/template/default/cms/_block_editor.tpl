<?php
/**
 * cms/_block_editor.tpl — SHARED CMS block-body editor. PLAIN PHP (extract()+include).
 *
 * Factored out of Cms_edit.tpl so BOTH the page editor (Cms_edit.tpl) and the
 * post editor (Cms_editpost.tpl) reuse the identical block-list UI, media picker,
 * add-block chooser, confirm modal, toast, and the whole block/TinyMCE engine.
 *
 * The host template owns the page/post META form and its SAVE flow; this partial
 * owns the BLOCK body and exposes a small JS API on `window.CmsBlockEditor`:
 *
 *   CmsBlockEditor.init({
 *     blocks:     [...],   // initial blocks (renderer shape)
 *     catalog:    [...],   // block catalog ([{type,label,group,dynamic,available}])
 *     labels:     {...},   // type → label map
 *     pageTypes:  [...],   // presets ([{type,label,blocks:[...]}]) — may be []
 *     blockAllow: {...},   // page-type key → [allowed block types] (scoped add-block chooser)
 *     pageType:   '…',     // current page type key ('post' for blog bodies)
 *     ajaxUrl:    '…/CmsAjax/',
 *     onDirty:    function(){}      // host marks its own meta form dirty/autosave
 *   });
 *   CmsBlockEditor.serialize();         // → block array for POST
 *   CmsBlockEditor.seedFromPreset(type);// reseed from a page-type preset
 *   CmsBlockEditor.isPristine();        // true when no block has authored content
 *   CmsBlockEditor.replaceModel(blocks);// swap the whole model + rerender
 *   CmsBlockEditor.hasJsonError();      // a JSON-fallback block holds invalid JSON
 *   CmsBlockEditor.toast(msg, kind);    // shared toast helper for the host
 *
 * Receives (from the host template, before including):
 *   $beBlocks    initial block list (renderer shape) — defaults to []
 *   $beCatalog   block catalog                         — defaults to []
 *   $beLabels    type→label map                        — defaults to {}
 *   $bePageTypes page-type presets                     — defaults to []
 *   $beHeading   blocks-column heading text            — defaults to 'Blocks'
 *   UIR (constant)
 */

$beBlocks    = isset($beBlocks) && is_array($beBlocks) ? $beBlocks : array();
$beCatalog   = isset($beCatalog) && is_array($beCatalog) ? $beCatalog : array();
$beLabels    = isset($beLabels) && is_array($beLabels) ? $beLabels : array();
$bePageTypes = isset($bePageTypes) && is_array($bePageTypes) ? $bePageTypes : array();
$beHeading   = isset($beHeading) ? (string)$beHeading : 'Blocks';
?>
<?php /* ---- Blocks column ---- */ ?>
<div class="cms-blocks-col">
    <div class="cms-blocks-head">
        <h2><?= htmlspecialchars($beHeading, ENT_QUOTES, 'UTF-8') ?></h2>
        <span class="cms-spacer"></span>
        <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="cmsCollapseAll" data-tip="Collapse or expand every block" style="display:none;"><i class="fas fa-angle-double-up"></i> Collapse all</button>
        <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsAddBlockBtn"><i class="fas fa-plus"></i> Add block</button>
    </div>

    <div id="cmsBlockList"></div>

    <div class="cms-empty" id="cmsBlockEmpty" style="display:none;border:1px dashed var(--ork-border-dark);border-radius:10px;">
        <div class="cms-empty-icon"><i class="fas fa-layer-group"></i></div>
        <div class="cms-empty-copy">No blocks yet. Add your first block.</div>
        <div class="cms-empty-cta">
            <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsAddBlockBtnEmpty"><i class="fas fa-plus"></i> Add block</button>
        </div>
    </div>
</div>

<?php /* ---- Add-block chooser modal ---- */ ?>
<div class="cms-modal-overlay" id="cmsAddModal">
    <div class="cms-modal cms-modal-wide" role="dialog" aria-modal="true" aria-label="Choose a block">
        <div class="cms-modal-head">
            <h3>Choose a block</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <div class="cms-typesearch">
                <i class="fas fa-search"></i>
                <input type="text" class="cms-input" id="cmsAddSearch" placeholder="Search blocks…" autocomplete="off">
            </div>
            <div id="cmsAddGroups"></div>
            <div class="cms-addshowall" id="cmsAddShowAllWrap" style="display:none;">
                <button type="button" class="cms-link-btn" id="cmsAddShowAll"></button>
            </div>
            <div class="cms-typegrid-empty" id="cmsAddNoMatch" style="display:none;">No blocks match your search.</div>
        </div>
    </div>
</div>

<?php /* ---- Block-editor enhancement styles (inline alt editor + Load-more).
        Uses ORK theme vars so light/dark are handled without extra overrides.
        Scoped to #cmsMediaGrid so it only affects THIS picker. ---- */ ?>
<style>
/* Widen picker tiles enough to hold the inline alt editor comfortably. */
#cmsMediaGrid { grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); }
#cmsMediaGrid .cms-media-tile { cursor: default; }
#cmsMediaGrid .cms-media-tile img,
#cmsMediaGrid .cms-media-tile .cms-media-cap { cursor: pointer; }
.cms-media-alt {
    padding: 7px 8px;
    border-top: 1px solid var(--ork-border);
    background: var(--ork-bg-tertiary);
    display: flex;
    flex-direction: column;
    gap: 6px;
}
.cms-media-alt-row { display: flex; gap: 6px; align-items: center; }
.cms-media-alt-input { flex: 1 1 auto; min-width: 0; font-size: 12px; padding: 4px 6px; }
.cms-media-alt-save { flex: 0 0 auto; }
.cms-media-alt-deco { font-size: 11px; color: var(--ork-text-secondary); display: flex; align-items: center; gap: 4px; }
.cms-media-alt-deco input { margin: 0; }
.cms-media-more { display: block; margin: 12px auto 2px; }
</style>

<?php /* ---- Columns visual splitter (enh #16) — theme-aware via ORK vars. ---- */ ?>
<style>
.cms-cols-countrow { display: flex; align-items: center; gap: 12px; margin: 4px 0 12px; flex-wrap: wrap; }
.cms-cols-countrow .cms-label { margin: 0; }
.cms-cols-seg { display: inline-flex; gap: 6px; }
.cms-cols-seg .cms-btn { min-width: 42px; justify-content: center; }
.cms-cols-seg .cms-cols-seg-active {
    background: var(--cms-gold, #f0b429);
    border-color: var(--cms-gold, #f0b429);
    color: #1a1205;
}
.cms-cols-grid { display: grid; gap: 12px; align-items: start; }
.cms-cols-grid-2 { grid-template-columns: 1fr 1fr; }
.cms-cols-grid-3 { grid-template-columns: 1fr 1fr 1fr; }
@media (max-width: 720px) { .cms-cols-grid-2, .cms-cols-grid-3 { grid-template-columns: 1fr; } }
.cms-cols-col {
    border: 1px dashed var(--ork-border-dark);
    border-radius: 8px;
    padding: 8px;
    background: var(--ork-bg-secondary);
    min-width: 0;
}
.cms-cols-col-head {
    font-size: 11px; font-weight: 700; letter-spacing: .05em; text-transform: uppercase;
    color: var(--ork-text-secondary); margin-bottom: 8px;
}
.cms-cols-childlist { display: flex; flex-direction: column; gap: 8px; }
.cms-cols-childcard { margin: 0; background: var(--ork-bg); }
.cms-cols-childcard .cms-block-body { padding: 8px 10px; }
.cms-cols-empty { font-size: 12.5px; font-style: italic; color: var(--ork-text-muted); padding: 4px 2px; }
.cms-cols-add { align-self: flex-start; }
</style>

<?php /* ---- Media picker modal ---- */ ?>
<div class="cms-modal-overlay" id="cmsMediaModal">
    <div class="cms-modal" role="dialog" aria-modal="true" aria-label="Choose image">
        <div class="cms-modal-head">
            <h3>Media library</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <label class="cms-upload-drop" id="cmsUploadDrop">
                <i class="fas fa-cloud-upload-alt" style="font-size:20px;"></i>
                <div style="margin-top:6px;">Click or drop an image to upload (JPG, PNG, GIF, WebP — max 8MB)</div>
                <input type="file" id="cmsUploadInput" accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <?php /* C1: alt text is authored at upload time (kept OUT of the drop
                    <label> so clicking the field never re-triggers the file picker). */ ?>
            <div class="cms-upload-meta">
                <div class="cms-field" style="margin-bottom:6px;">
                    <label class="cms-label" for="cmsUploadAlt">Alt text (image description)</label>
                    <input type="text" class="cms-input" id="cmsUploadAlt" placeholder="Describe this image for screen-reader users">
                </div>
                <label class="cms-check-inline"><input type="checkbox" id="cmsUploadDecorative"> This image is decorative (no alt text)</label>
                <div class="cms-help">Alt text lets screen-reader users and search engines understand the image. Mark an image “decorative” only when it carries no information (a texture, border, or purely ornamental flourish) — that intentionally saves an empty alt so assistive tech skips it.</div>
            </div>
            <div class="cms-media-toolbar">
                <input type="text" class="cms-input" id="cmsMediaSearch" placeholder="Search media…">
                <button type="button" class="cms-btn cms-btn-sm" id="cmsMediaSearchBtn"><i class="fas fa-search"></i> Search</button>
            </div>
            <div class="cms-media-grid" id="cmsMediaGrid">
                <div class="cms-media-empty">Loading…</div>
            </div>
        </div>
    </div>
</div>

<?php /* ---- Confirm modal (shared: delete block / delete page-or-post) ---- */ ?>
<div class="cms-modal-overlay" id="cmsConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Confirm">
        <div class="cms-modal-head">
            <h3 id="cmsConfirmTitle">Please confirm</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p id="cmsConfirmBody" style="margin:0;font-size:14px;"></p>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="cmsConfirmOk">Delete</button>
        </div>
    </div>
</div>

<div class="cms-toast" id="cmsToast"></div>

<?php
/* TinyMCE source — prefer a self-hosted, vendored bundle if one exists under the
 * template's static assets; otherwise fall back to the pinned CDN build. Vendoring
 * the 7.6.0 bundle removes the third-party dependency + the silent-degradation risk
 * (a CDN outage otherwise turns every rich-text field into a raw-HTML textarea with
 * no warning). See the C25 seam note: dropping tinymce.min.js at the path below is
 * an asset-add, not a template change. */
$beTinyLocalFs = __DIR__ . '/../script/tinymce/tinymce.min.js';
$beTinyBaseUrl = defined('HTTP_TEMPLATE') ? HTTP_TEMPLATE : '';
$beTinyLocal   = is_file($beTinyLocalFs);
$beTinySrc     = $beTinyLocal
    ? ($beTinyBaseUrl . 'default/script/tinymce/tinymce.min.js')
    : 'https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js';
// SRI: pin the third-party CDN build so a tampered/substituted file is rejected by
// the browser. The self-hosted bundle is same-origin — no integrity/crossorigin needed.
$beTinyIntegrity = 'sha384-tra1rGs8OanGKq1dD4jTW195QKiytSZz7fE5gSASuwkxuhlG+KjvAVlyHOB2Mlva';
?>
<script src="<?= htmlspecialchars($beTinySrc, ENT_QUOTES, 'UTF-8') ?>"<?php if (!$beTinyLocal): ?> integrity="<?= $beTinyIntegrity ?>" crossorigin="anonymous"<?php endif; ?> referrerpolicy="origin"></script>

<?php /* ---- Server-dynamic bootstrap: the ONLY PHP the engine below needs.
       Everything after this tiny <script> is static and can be lifted verbatim
       into a standalone asset (see the C27 extraction seam banner). ---- */ ?>
<script>
window.CmsBlockEditorBoot = {
    UIR: <?= json_encode(UIR) ?>,
    tinymceSrc: <?= json_encode($beTinySrc) ?>
};
</script>
<script>
/* ============================================================================
 * >>> EXTRACTION SEAM (C27) <<<
 * window.CmsBlockEditor — shared block-body editor engine (pages + posts).
 * This entire <script> body is STATIC (contains no PHP): its only server-provided
 * values arrive via window.CmsBlockEditorBoot (set in the tiny bootstrap above).
 * It can therefore be moved verbatim into a lintable/testable static asset — e.g.
 * template/default/script/cms-block-editor.js — keeping just the bootstrap inline.
 * Left inline for now because adding a new .js asset is outside this template's
 * change scope (recorded as a follow-up seam).
 * The host template calls CmsBlockEditor.init(opts) after the DOM is ready.
 * ========================================================================== */
window.CmsBlockEditor = (function () {
    'use strict';

    var BOOT = window.CmsBlockEditorBoot || {};
    var UIR  = BOOT.UIR || '';
    var AJAX = UIR + 'CmsAjax/';

    var model = [];
    var catalog = [];
    var labels = {};
    var pageTypes = [];
    var tagCatalog = [];        // C22: existing tags [{slug,name,post_count}] for blog_feed picker
    var blockAllow = {};        // page-type key -> [allowed block types]
    var pageType = '';          // current page type ('post' for blog bodies)
    var showAllBlocks = false;  // chooser "Show all blocks" toggle state
    var addGroupCollapsed = {}; // chooser: per-group collapsed state (by group name)
    var onDirty = function () {};

    var listEl, emptyEl, collapseAllBtn;

    /* ================= small helpers ================= */
    function el(tag, cls, html) {
        var n = document.createElement(tag);
        if (cls) { n.className = cls; }
        if (html != null) { n.innerHTML = html; }
        return n;
    }
    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ---- toast ---- */
    var toastEl, toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { toastEl = document.getElementById('cmsToast'); }
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- modal helpers + shared focus trap ----
     * On open we remember the element that triggered the modal, move focus into
     * the dialog (search field / first focusable), and trap Tab cycling inside.
     * On close we restore focus to the trigger. Esc-close is handled below. */
    var FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';

    function focusablesIn(elx) {
        return Array.prototype.filter.call(
            elx.querySelectorAll(FOCUSABLE),
            function (n) { return n.offsetWidth || n.offsetHeight || n.getClientRects().length; }
        );
    }

    function openModal(elx) {
        if (!elx) { return; }
        elx._returnFocus = (document.activeElement && document.activeElement !== document.body)
            ? document.activeElement : null;
        elx.classList.add('cms-open');
        // Move focus into the dialog: prefer a search input, else the first focusable.
        setTimeout(function () {
            var search = elx.querySelector('input[type="text"], input:not([type])');
            var first = search || focusablesIn(elx)[0];
            if (first) { try { first.focus(); } catch (e) {} }
        }, 30);
    }
    function closeModal(elx) {
        if (!elx) { return; }
        elx.classList.remove('cms-open');
        var rf = elx._returnFocus;
        elx._returnFocus = null;
        if (rf && document.contains(rf)) { try { rf.focus(); } catch (e) {} }
    }
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) {
            closeModal(e.target);
        }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal);
            return;
        }
        if (e.key !== 'Tab') { return; }
        // Trap Tab inside the topmost open modal.
        var open = document.querySelector('.cms-modal-overlay.cms-open');
        if (!open) { return; }
        var items = focusablesIn(open);
        if (!items.length) { e.preventDefault(); return; }
        var firstEl = items[0], lastEl = items[items.length - 1];
        var active = document.activeElement;
        if (e.shiftKey) {
            if (active === firstEl || !open.contains(active)) { e.preventDefault(); lastEl.focus(); }
        } else {
            if (active === lastEl || !open.contains(active)) { e.preventDefault(); firstEl.focus(); }
        }
    });

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(AJAX + endpoint + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

    function markDirty() {
        try { onDirty(); } catch (e) {}
    }

    /* ================= block model ================= */
    /* NOTE: 'rich_text' is the canonical block type; 'richtext' is a legacy DB alias.
       Both spellings are accepted on read (see summarize() / buildBlockBody()). */
    function normBlock(b) {
        return {
            // C15: carry the stable server row id so a save round-trips it and the
            // ReplaceBlocks upsert preserves the row (rather than delete+reinsert,
            // which would churn ids and lose per-block history). New/duplicated/
            // preset blocks have no id → 0, so the server assigns a fresh row.
            id:      (b.id != null && b.id !== '') ? (parseInt(b.id, 10) || 0) : 0,
            type:    String(b.type || ''),
            enabled: (b.enabled === undefined ? true : !!b.enabled),
            source:  (b.source === 'dynamic' ? 'dynamic' : 'authored'),
            fields:  (b.fields && typeof b.fields === 'object') ? JSON.parse(JSON.stringify(b.fields)) : {}
        };
    }

    function labelFor(type) {
        return labels[type] || type;
    }

    function presetBlocksFor(type) {
        var pts = pageTypes || [];
        for (var i = 0; i < pts.length; i++) {
            if (pts[i] && pts[i].type === type && Array.isArray(pts[i].blocks)) {
                return pts[i].blocks;
            }
        }
        return null;
    }

    /* ---- short human summary for the block card header ---- */
    function summarize(block) {
        var f = block.fields || {};
        switch (block.type) {
            case 'rich_text':
            case 'richtext':
                return strip(f.heading || f.body || '');
            case 'image':
                return (f.image && f.image.alt) || (f.image && f.image.src ? 'image set' : 'no image');
            case 'hero_carousel':
                return ((f.slides || []).length) + ' slide(s)';
            case 'card_grid':
                return (f.heading ? f.heading + ' — ' : '') + ((f.cards || []).length) + ' card(s)';
            case 'staff_roster':
                return 'Staff Roster — ' + ((f.people || []).length) + ' people';
            case 'cta_band':
                return strip(f.heading || '') || ((f.ctas || []).length + ' CTA(s)');
            case 'heading':
                return strip(f.text || f.heading || '');
            case 'quote':
                return strip(f.text || f.quote || '');
            case 'gallery':
                return ((f.images || []).length) + ' image(s)';
            case 'video_embed':
                return (f.provider || 'youtube') + (f.video_id || f.url ? ' · ' + strip(f.video_id || f.url) : ' · no video');
            case 'file_download':
                return ((f.files || []).length) + ' file(s)';
            case 'accordion':
                return ((f.items || []).length) + ' item(s)';
            case 'steps':
                return (f.heading ? strip(f.heading) + ' — ' : '') + ((f.steps || []).length) + ' step(s)';
            case 'photo_mosaic':
                return ((f.images || []).length) + ' image(s)';
            case 'table':
                return ((f.rows || []).length) + ' row(s)';
            case 'divider':
                return f.style || 'line';
            case 'spacer':
                return f.size || 'md';
            case 'raw_html':
                return f.html ? 'HTML set' : 'no HTML';
            case 'marketing_nav':
                return (f.cta && f.cta.label) ? strip(f.cta.label) : 'logo + buttons';
            case 'kingdoms_teaser':
            case 'events_feed':
            case 'blog_feed': {
                var hd = strip(f.heading || '');
                return hd ? ('live · ' + hd) : 'live data';
            }
            case 'member_bar':
            case 'stat_ticker':
            case 'tournaments_feed':
            case 'recap_highlight':
                return 'live data';
            case 'columns':
                return ((f.columns || []).length) + ' column(s)';
            default:
                return 'custom fields (JSON)';
        }
    }
    function strip(s) {
        var d = document.createElement('div');
        d.innerHTML = String(s || '');
        var t = (d.textContent || '').trim();
        return t.length > 60 ? t.slice(0, 60) + '…' : t;
    }

    /* ================= TinyMCE ================= */
    var tinyReady = (typeof tinymce !== 'undefined');
    var tinyCounter = 0;
    var tinyDegradedWarned = false;

    function currentIsDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }
    // Skin the editor at construction time; tracked so a runtime theme flip can
    // detect the change and reinit (TinyMCE can't hot-swap skin/content_css).
    var lastTinyDark = currentIsDark();

    function initTiny(textarea) {
        if (!tinyReady || !textarea) { return; }
        var isDark = currentIsDark();
        lastTinyDark = isDark;
        tinymce.init({
            target: textarea,
            menubar: false,
            statusbar: true,            // surfaces the word count + resize handle
            // autoresize grows the editor with content instead of a fixed box.
            min_height: 220,
            max_height: 640,
            autoresize_bottom_margin: 16,
            plugins: 'lists link autolink autoresize wordcount fullscreen searchreplace charmap quickbars table image emoticons',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough subscript superscript | '
                + 'bullist numlist | link image table blockquote hr | emoticons charmap | '
                + 'removeformat | searchreplace fullscreen',
            toolbar_mode: 'wrap',       // wrap tools onto multiple rows (vs. hiding behind "…")
            // Only the headings the sanitizer keeps (h2–h4); H1/H5/H6/pre would be
            // stripped on save, so don't offer them.
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
            // WYSIWYG truth: the sanitizer drops all inline styles, so forbid them
            // in the editor too (no orphaned colour/size/alignment that won't save).
            valid_styles: { '*': '' },
            // Links: https default, title field, new-tab option (sanitizer hardens
            // target=_blank → rel=noopener on save).
            link_default_protocol: 'https',
            link_title: true,
            link_context_toolbar: true,
            // Quick selection toolbar; suppress the empty-line insert toolbar.
            quickbars_selection_toolbar: 'bold italic underline | quicklink blockquote',
            quickbars_insert_toolbar: false,
            // Tables author clean (the sanitizer strips border/style; CSS skins them).
            table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | '
                + 'tableinsertcolbefore tableinsertcolafter tabledeletecol',
            table_appearance_options: false,
            // Inline images come from the CMS media library, not pasted data URIs.
            paste_data_images: false,
            file_picker_types: 'image',
            file_picker_callback: function (cb) {
                openMediaPicker(function (m) {
                    if (m && m.src) { cb(m.src, { alt: m.alt || '' }); }
                });
            },
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
            setup: function (ed) {
                ed.on('change keyup input', function () {
                    ed.save();
                    ed.targetElm.dispatchEvent(new Event('input', { bubbles: false }));
                    markDirty();
                });
            }
        });
    }
    function syncTiny() {
        if (tinyReady && tinymce.editors) {
            tinymce.editors.forEach(function (ed) { try { ed.save(); } catch (e) {} });
        }
    }
    function destroyTinyIn(node) {
        if (!tinyReady) { return; }
        node.querySelectorAll('textarea[data-tiny]').forEach(function (ta) {
            var ed = tinymce.get(ta.id);
            if (ed) { ed.remove(); }
        });
    }

    /* ---- C31: reinit open editors when the app theme flips at runtime ----
     * initTiny freezes skin/content_css at construction, so a light↔dark toggle
     * would otherwise leave stale editor chrome until a full renderList. On a real
     * theme change we save each open editor's content, remove it, and rebuild it —
     * which re-reads currentIsDark(). Caret/scroll reset is acceptable for a
     * deliberate, infrequent theme toggle (and only affects rich-text fields). */
    function reinitTinySkins() {
        if (!tinyReady || !tinymce.editors) { return; }
        var isDark = currentIsDark();
        if (isDark === lastTinyDark) { return; }
        lastTinyDark = isDark;
        var textareas = [];
        tinymce.editors.slice().forEach(function (ed) {
            try { ed.save(); } catch (e) {}
            var ta = ed.targetElm || document.getElementById(ed.id);
            if (ta) { textareas.push(ta); }
        });
        textareas.forEach(function (ta) {
            var ed = tinymce.get(ta.id);
            if (ed) { ed.remove(); }
        });
        textareas.forEach(function (ta) { initTiny(ta); });
    }

    function observeTheme() {
        if (typeof MutationObserver === 'undefined') { return; }
        var obs = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].attributeName === 'data-theme') { reinitTinySkins(); break; }
            }
        });
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    /* ---- C25: warn (once) when the TinyMCE bundle failed to load, so authors
     * know a rich-text field has silently degraded to a raw-HTML textarea and
     * don't unknowingly save mangled markup. ---- */
    function warnTinyDegradedIfNeeded() {
        if (tinyReady || tinyDegradedWarned) { return; }
        if (!listEl || !listEl.querySelector('textarea[data-tiny]')) { return; }
        tinyDegradedWarned = true;
        toast('Rich-text editor didn’t load — those fields show raw HTML. Check your connection before saving.', 'error');
    }

    /* ================= field builders ================= */
    function fieldText(block, key, label, opts) {
        opts = opts || {};
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var input;
        if (opts.textarea) {
            input = el('textarea', 'cms-textarea');
        } else {
            input = el('input', 'cms-input');
            input.type = 'text';
        }
        if (opts.placeholder) { input.placeholder = opts.placeholder; }
        input.value = block.fields[key] != null ? block.fields[key] : '';
        input.addEventListener('input', function () { block.fields[key] = input.value; markDirty(); });
        wrap.appendChild(input);
        return wrap;
    }

    function fieldSelect(block, key, label, options, dflt) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var sel = el('select', 'cms-select');
        options.forEach(function (o) {
            var op = el('option');
            op.value = o.value; op.textContent = o.label;
            if ((block.fields[key] || dflt) === o.value) { op.selected = true; }
            sel.appendChild(op);
        });
        if (block.fields[key] == null) { block.fields[key] = dflt; }
        sel.addEventListener('change', function () { block.fields[key] = sel.value; markDirty(); });
        wrap.appendChild(sel);
        return wrap;
    }

    function fieldNumSelect(block, key, label, options, dflt) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var sel = el('select', 'cms-select');
        var current = (block.fields[key] != null) ? Number(block.fields[key]) : dflt;
        options.forEach(function (o) {
            var op = el('option');
            op.value = String(o.value); op.textContent = o.label;
            if (current === o.value) { op.selected = true; }
            sel.appendChild(op);
        });
        block.fields[key] = current;
        sel.addEventListener('change', function () { block.fields[key] = Number(sel.value); markDirty(); });
        wrap.appendChild(sel);
        return wrap;
    }

    function fieldRich(block, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var host = el('div', 'cms-richtext-host');
        var ta = el('textarea', 'cms-textarea');
        ta.id = 'cmsrt_' + (++tinyCounter);
        ta.setAttribute('data-tiny', '1');
        ta.value = block.fields[key] != null ? block.fields[key] : '';
        ta.addEventListener('input', function () { block.fields[key] = ta.value; markDirty(); });
        host.appendChild(ta);
        wrap.appendChild(host);
        // C25: if TinyMCE never loaded, this is a raw-HTML textarea — say so inline.
        if (!tinyReady) {
            wrap.appendChild(el('div', 'cms-help-warn',
                '<i class="fas fa-exclamation-triangle"></i> <span>Rich-text editing is unavailable (the editor didn’t load). '
                + 'You’re editing raw HTML — save with care.</span>'));
        }
        return wrap;
    }

    function fieldImage(container, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var row = el('div', 'cms-media-field');

        var ref = (container[key] && typeof container[key] === 'object') ? container[key] : null;
        // An empty {} ref is "no image chosen" — only treat it as selected when it
        // actually carries an image (thumb/src). Gates the name label + Clear button.
        var hasImage = ref && (ref.thumb || ref.src);
        var thumb;
        if (ref && ref.thumb) {
            thumb = el('img', 'cms-media-thumb');
            thumb.src = ref.thumb || ref.src;
        } else {
            thumb = el('div', 'cms-media-thumb cms-empty-thumb', '<i class="fas fa-image"></i>');
        }

        var meta = el('div', 'cms-media-meta');
        var nameEl = el('div', 'cms-media-name', hasImage ? esc(ref.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>');
        var btnRow = el('div', null);
        btnRow.style.marginTop = '6px';
        var chooseBtn = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-image"></i> Choose image');
        chooseBtn.type = 'button';
        var clearBtn = el('button', 'cms-btn cms-btn-sm cms-btn-ghost', 'Clear');
        clearBtn.type = 'button';
        clearBtn.style.marginLeft = '6px';
        if (!hasImage) { clearBtn.style.display = 'none'; }

        function render(newRef) {
            container[key] = newRef || {};
            var newHasImage = newRef && (newRef.thumb || newRef.src);
            var fresh;
            if (newRef && newRef.thumb) {
                fresh = el('img', 'cms-media-thumb');
                fresh.src = newRef.thumb || newRef.src;
            } else {
                fresh = el('div', 'cms-media-thumb cms-empty-thumb', '<i class="fas fa-image"></i>');
            }
            row.replaceChild(fresh, thumb);
            thumb = fresh;
            nameEl.innerHTML = newHasImage ? esc(newRef.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>';
            clearBtn.style.display = newHasImage ? '' : 'none';
            markDirty();
        }

        chooseBtn.addEventListener('click', function () {
            openMediaPicker(function (mref) { render(mref); });
        });
        clearBtn.addEventListener('click', function () { render(null); });

        btnRow.appendChild(chooseBtn);
        btnRow.appendChild(clearBtn);
        meta.appendChild(nameEl);
        meta.appendChild(btnRow);
        row.appendChild(thumb);
        row.appendChild(meta);
        wrap.appendChild(row);
        return wrap;
    }

    function repeater(block, key, singular, blank, itemRender, addLabel) {
        if (!Array.isArray(block.fields[key])) { block.fields[key] = []; }
        var arr = block.fields[key];
        var wrap = el('div', 'cms-subitems');

        function rebuild() {
            wrap.innerHTML = '';
            arr.forEach(function (item, i) {
                var box = el('div', 'cms-subitem');
                var head = el('div', 'cms-subitem-head');
                head.appendChild(el('strong', null, esc(singular + ' ' + (i + 1))));
                var tools = el('div', 'cms-block-tools');
                var up = iconBtn('fa-arrow-up', 'Move up', i === 0);
                var down = iconBtn('fa-arrow-down', 'Move down', i === arr.length - 1);
                var del = iconBtn('fa-trash', 'Remove', false, true);
                up.addEventListener('click', function () { swap(arr, i, i - 1); rebuild(); markDirty(); });
                down.addEventListener('click', function () { swap(arr, i, i + 1); rebuild(); markDirty(); });
                del.addEventListener('click', function () { arr.splice(i, 1); rebuild(); markDirty(); });
                tools.appendChild(up); tools.appendChild(down); tools.appendChild(del);
                head.appendChild(tools);
                box.appendChild(head);
                box.appendChild(itemRender(item, i));
                wrap.appendChild(box);
            });
            var add = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-plus"></i> ' + esc(addLabel || ('Add ' + singular)));
            add.type = 'button';
            add.addEventListener('click', function () {
                arr.push(JSON.parse(JSON.stringify(blank)));
                rebuild(); markDirty();
            });
            wrap.appendChild(add);
        }
        rebuild();
        return wrap;
    }

    function swap(arr, a, b) {
        if (a < 0 || b < 0 || a >= arr.length || b >= arr.length) { return; }
        var t = arr[a]; arr[a] = arr[b]; arr[b] = t;
    }

    function iconBtn(icon, tip, disabled, danger) {
        var b = el('button', 'cms-icon-btn' + (danger ? ' cms-icon-danger' : ''), '<i class="fas ' + icon + '"></i>');
        b.type = 'button';
        b.setAttribute('data-tip', tip);
        if (tip) { b.setAttribute('aria-label', tip); }
        if (disabled) { b.disabled = true; }
        return b;
    }

    function ctaRepeater(block, styleOpts) {
        return repeater(block, 'ctas', 'CTA', { label: '', href: '#', style: styleOpts[0].value }, function (cta) {
            var box = el('div', null);
            var g = el('div', 'cms-grid2');
            g.appendChild(textBound(cta, 'label', 'Label'));
            g.appendChild(textBound(cta, 'href', 'Link (href)'));
            box.appendChild(g);
            box.appendChild(selectBound(cta, 'style', 'Style', styleOpts, styleOpts[0].value));
            return box;
        });
    }

    function textBound(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var inp = el('input', 'cms-input'); inp.type = 'text';
        if (ph) { inp.placeholder = ph; }
        inp.value = obj[key] != null ? obj[key] : '';
        inp.addEventListener('input', function () { obj[key] = inp.value; markDirty(); });
        wrap.appendChild(inp);
        return wrap;
    }
    function textBoundArea(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var ta = el('textarea', 'cms-textarea');
        if (ph) { ta.placeholder = ph; }
        ta.value = obj[key] != null ? obj[key] : '';
        ta.addEventListener('input', function () { obj[key] = ta.value; markDirty(); });
        wrap.appendChild(ta);
        return wrap;
    }
    function selectBound(obj, key, label, options, dflt) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var sel = el('select', 'cms-select');
        options.forEach(function (o) {
            var op = el('option'); op.value = o.value; op.textContent = o.label;
            if ((obj[key] || dflt) === o.value) { op.selected = true; }
            sel.appendChild(op);
        });
        if (obj[key] == null) { obj[key] = dflt; }
        sel.addEventListener('change', function () { obj[key] = sel.value; markDirty(); });
        wrap.appendChild(sel);
        return wrap;
    }
    function imageBound(obj, key, label) {
        if (!obj[key] || typeof obj[key] !== 'object') { obj[key] = {}; }
        return fieldImage(obj, key, label);
    }

    function tnFixedAcPosition(input, dropdown) {
        var r = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = (r.bottom + 2) + 'px';
        dropdown.style.width = r.width + 'px';
        dropdown.style.zIndex = '99999';
    }

    function personaLinkField(person, onResolve) {
        var wrap = el('div', 'cms-field'); wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', 'Link Amtgard persona (optional)'));

        var chip = el('div', 'cms-persona-chip');
        function renderChip() {
            chip.innerHTML = '';
            if (person.mundane_id && person.mundane_id > 0) {
                chip.appendChild(el('span', null, esc('Linked: ' + (person.persona_name || ('#' + person.mundane_id)))));
                var unlink = el('button', 'cms-link-btn'); unlink.type = 'button'; unlink.textContent = 'Unlink';
                unlink.addEventListener('click', function () { person.mundane_id = 0; markDirty(); renderChip(); });
                chip.appendChild(unlink);
                chip.style.display = '';
            } else {
                chip.style.display = 'none';
            }
        }

        var input = el('input', 'cms-input'); input.type = 'text';
        input.placeholder = 'Search by persona or name…';
        var dd = el('div', 'kn-ac-results cms-persona-ac'); dd.style.display = 'none';
        document.body.appendChild(dd);

        var timer = null, ctrl = null;
        function closeDd() { dd.classList.remove('kn-ac-open'); dd.style.display = 'none'; }
        function showDd() { tnFixedAcPosition(input, dd); dd.style.display = 'block'; dd.classList.add('kn-ac-open'); }

        function pick(row) {
            person.mundane_id = parseInt(row.MundaneId, 10) || 0;
            person.persona_name = row.Persona || person.persona_name;
            input.value = ''; closeDd(); markDirty(); renderChip();
            fetch(UIR + 'CmsAjax/personlookup&mundane_id=' + person.mundane_id + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''))
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        if (d.persona) { person.persona_name = d.persona; }
                        if (d.mundane_name) { person.mundane_name = d.mundane_name; }
                        markDirty();
                        renderChip();
                        // Refresh just the two bound name inputs (no full
                        // renderList — that would tear down TinyMCE in every
                        // other block on the page).
                        if (typeof onResolve === 'function') { onResolve(); }
                    }
                })
                .catch(function () { /* names stay as typed; non-fatal */ });
        }

        function search(term) {
            if (ctrl) { ctrl.abort(); }
            ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            // Scope the persona search to the current CMS site (same as personlookup /
            // medialist) instead of scope=all — cross-org scope=all leaked banned/inactive
            // personas into the picker. Drop include_inactive=1 for the same reason.
            var url = UIR + 'KingdomAjax/playersearch/0'
                + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '')
                + '&q=' + encodeURIComponent(term);
            fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (rows) {
                    dd.innerHTML = '';
                    if (!rows || !rows.length) {
                        dd.appendChild(el('div', 'kn-ac-item kn-ac-none', 'No matches')); showDd(); return;
                    }
                    rows.forEach(function (row) {
                        var loc = [row.KAbbr, row.PAbbr].filter(Boolean).join(':');
                        var item = el('div', 'kn-ac-item',
                            esc(row.Persona) + (loc ? ' <span class="kn-ac-meta">' + esc(loc) + '</span>' : ''));
                        item.addEventListener('mousedown', function (e) { e.preventDefault(); pick(row); });
                        dd.appendChild(item);
                    });
                    showDd();
                })
                .catch(function () { /* ignore aborted/failed search */ });
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            if (timer) { clearTimeout(timer); }
            if (term.length < 2) { closeDd(); return; }
            timer = setTimeout(function () { search(term); }, 200);
        });
        input.addEventListener('blur', function () { setTimeout(closeDd, 150); });

        wrap.appendChild(chip);
        wrap.appendChild(input);
        renderChip();
        return wrap;
    }

    /* ---- bound primitive helpers used by the schema renderer ---- */
    function numberBound(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var inp = el('input', 'cms-input'); inp.type = 'number';
        if (ph) { inp.placeholder = ph; }
        inp.value = (obj[key] != null && obj[key] !== '') ? obj[key] : '';
        inp.addEventListener('input', function () {
            obj[key] = inp.value === '' ? '' : Number(inp.value);
            markDirty();
        });
        wrap.appendChild(inp);
        return wrap;
    }

    /* ---- C22: validated tag picker (blog_feed) — a select over EXISTING tags
     * instead of a free-text field (a typo silently rendered an empty feed). Warns
     * inline when the chosen tag currently has no posts, and preserves any stored
     * legacy free-text value as a flagged "unknown tag" option rather than dropping
     * it. Binds obj[key] to the tag SLUG (what ListPosts filters on). ---- */
    function tagPickerField(obj, key, label, help) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var cur = (obj[key] != null) ? String(obj[key]) : '';
        var sel = el('select', 'cms-select');

        var opt0 = el('option'); opt0.value = ''; opt0.textContent = 'All posts (no tag filter)';
        if (cur === '') { opt0.selected = true; }
        sel.appendChild(opt0);

        var known = {};
        (tagCatalog || []).forEach(function (t) {
            var slug = String(t.slug || '');
            if (!slug) { return; }
            known[slug] = t;
            var op = el('option');
            op.value = slug;
            op.textContent = (t.name || slug) + ' (' + (Number(t.post_count) || 0) + ')';
            if (cur === slug) { op.selected = true; }
            sel.appendChild(op);
        });
        // A stored value not in the current tag library (legacy free-text/typo):
        // keep it selectable so a save doesn't silently discard it, but flag it.
        if (cur !== '' && !known[cur]) {
            var opX = el('option');
            opX.value = cur; opX.textContent = cur + ' — unknown tag';
            opX.selected = true;
            sel.appendChild(opX);
        }

        var warn = el('div', 'cms-help-warn');
        warn.style.display = 'none';
        warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span></span>';
        function refreshWarn() {
            var v = sel.value, msg = '';
            if (v !== '') {
                if (!known[v]) {
                    msg = 'No tag named “' + v + '” exists — this feed will render empty until a post uses it.';
                } else if ((Number(known[v].post_count) || 0) === 0) {
                    msg = 'The “' + (known[v].name || v) + '” tag has no published posts yet — this feed will render empty for now.';
                }
            }
            if (msg) { warn.querySelector('span').textContent = msg; warn.style.display = ''; }
            else { warn.style.display = 'none'; }
        }
        sel.addEventListener('change', function () { obj[key] = sel.value; markDirty(); refreshWarn(); });
        if (obj[key] == null) { obj[key] = cur; }

        wrap.appendChild(sel);
        wrap.appendChild(warn);
        if (help) { wrap.appendChild(el('div', 'cms-help', help)); }
        refreshWarn();
        return wrap;
    }

    /* ---- checkbox bound to obj[key] (stored as a JS boolean) ---- */
    function checkBound(obj, key, label, help) {
        var wrap = el('div', 'cms-field'); wrap.style.marginBottom = '8px';
        var lab = el('label', 'cms-check-inline');
        var cb = el('input'); cb.type = 'checkbox';
        cb.checked = !!obj[key];
        if (obj[key] === undefined) { obj[key] = false; }
        cb.addEventListener('change', function () { obj[key] = cb.checked; markDirty(); });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' ' + label));
        wrap.appendChild(lab);
        if (help) { wrap.appendChild(el('div', 'cms-help', help)); }
        return wrap;
    }

    /* ================= declarative block-schema registry =================
     * Each entry is a list of field specs the generic renderer walks:
     *   { key, type, label, help?, placeholder?, options?, of? }
     * Supported field `type`s (all reuse the existing helpers below):
     *   'text'      single-line input
     *   'textarea'  multi-line input
     *   'mono'      monospace multi-line input (raw_html, table rows)
     *   'richtext'  TinyMCE editor
     *   'select'    dropdown (needs options:[{value,label}])
     *   'bool'      Yes/No dropdown (stored as 1/0)
     *   'number'    numeric input
     *   'url'       single-line input (semantic alias of text)
     *   'image'     media-library picker
     *   'group'     a small object of sub-fields (of:[specs]) → obj[key]={…}
     *   'repeater'  repeating list (of:[specs] for object items, or one image spec)
     *   'note'      static info paragraph (no data) — { html }
     * The renderer is buildBlockBody()'s default path; bespoke forms (hero,
     * card_grid, etc.) remain hand-built and take precedence over a schema. */
    var BLOCK_SCHEMA = {
        marketing_nav: [
            { key: 'logo', type: 'image', label: 'Logo' },
            { key: 'cta', type: 'group', label: 'Call-to-action button', of: [
                { key: 'label', type: 'text', label: 'Label', placeholder: 'e.g. Find a Park' },
                { key: 'href', type: 'url', label: 'Link (href)', placeholder: 'https://…' }
            ] },
            { key: 'login', type: 'group', label: 'Login button', of: [
                { key: 'label', type: 'text', label: 'Label', placeholder: 'e.g. Sign in' },
                { key: 'href', type: 'url', label: 'Link (href)', placeholder: 'https://…' }
            ] },
            { type: 'note', html: 'Menu links are managed in the <a href="' + esc(UIR) + 'Cms/nav">Navigation tab</a>. This block only controls the logo and the buttons above.' }
        ],
        steps: [
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'band', type: 'select', label: 'Background band', options: [
                { value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark (navy)' }
            ] },
            { key: 'cta', type: 'group', label: 'Optional call-to-action', of: [
                { key: 'label', type: 'text', label: 'CTA label' },
                { key: 'href', type: 'url', label: 'CTA link', placeholder: 'https://…' }
            ] },
            { key: 'steps', type: 'repeater', label: 'Steps', singular: 'Step', of: [
                { key: 'n', type: 'number', label: 'Number', placeholder: 'e.g. 1' },
                { key: 'title', type: 'text', label: 'Title' },
                { key: 'body', type: 'textarea', label: 'Body' }
            ] }
        ],
        photo_mosaic: [
            { key: 'caption', type: 'text', label: 'Caption', help: 'Shown on the navy caption tile (first 4 images are laid out as a mosaic).' },
            { key: 'images', type: 'repeater', label: 'Images', singular: 'Image', of: '__image__' }
        ],
        divider: [
            { key: 'style', type: 'select', label: 'Style', options: [
                { value: 'line', label: 'Line' }, { value: 'dots', label: 'Dotted' }
            ] }
        ],
        spacer: [
            { key: 'size', type: 'select', label: 'Size', options: [
                { value: 'sm', label: 'Small' }, { value: 'md', label: 'Medium' }, { value: 'lg', label: 'Large' }
            ] }
        ],
        table: [
            { key: 'caption', type: 'text', label: 'Caption', placeholder: 'Optional table caption' },
            { key: 'header_first_row', type: 'bool', label: 'First row is a header',
              help: 'On by default. When “Yes”, the first row you enter becomes bold column headers so screen-reader users hear which column each value belongs to. Turn it off only for a table that has no header row.' },
            { key: 'rows', type: 'table_rows', label: 'Rows',
              help: 'One row per line. Separate cells with a vertical bar  |  — e.g.  Column A | Column B | Column C' }
        ],
        raw_html: [
            { key: 'html', type: 'mono', label: 'HTML', help: 'Sanitized on save — unsafe tags/attributes are stripped.' }
        ],
        kingdoms_teaser: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max kingdoms shown', placeholder: '12' },
            { key: 'more_href', type: 'url', label: '“Browse all” link', placeholder: 'https://…' }
        ],
        events_feed: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: '“All events” link', placeholder: 'https://…' }
        ],
        blog_feed: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Latest News' },
            { key: 'limit', type: 'number', label: 'Max posts shown', placeholder: '3' },
            { key: 'tag', type: 'tagpicker', label: 'Filter by tag (optional)',
              help: 'Pick from tags that already exist. “All posts” shows every published post.' }
        ],
        kingdom_officers: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Our Officers' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max officers shown', placeholder: '12' }
        ],
        kingdom_parks: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Our Parks' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'sort', type: 'select', label: 'Sort order', options: [
                { value: 'name', label: 'Park name (A–Z)' },
                { value: 'city', label: 'City, then park name' },
                { value: 'state', label: 'State, then city, then park name' }
            ] },
            { key: 'show_heraldry', type: 'bool', label: 'Display park heraldry' },
            { key: 'limit', type: 'number', label: 'Max parks shown', placeholder: '24' },
            { key: 'more_href', type: 'url', label: '“All parks” link', placeholder: 'https://…' }
        ],
        kingdom_parks_map: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Park Map' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' }
        ],
        kingdom_events: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Upcoming Events' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: '“All events” link', placeholder: 'https://…' }
        ],
        member_bar: []  // pure info card; no knobs
    };

    /* C20: block types that only expose a bare-JSON editor (no friendly form) are
     * a footgun for non-technical authors, so they're hidden from the Add-block
     * chooser. (enh #16: 'columns' now has a real visual splitter editor, so it is
     * no longer JSON-only and IS addable — the server already allows it.) An existing
     * block of a JSON-only type still renders its JSON editor; we only keep NEW ones
     * out of the chooser. */
    var JSON_ONLY_TYPES = {};

    /* dynamic block types render an info card (icon + description) above any knobs */
    var DYNAMIC_TYPES = {
        member_bar: true, kingdoms_teaser: true, events_feed: true, blog_feed: true,
        stat_ticker: true, tournaments_feed: true, recap_highlight: true,
        kingdom_officers: true, kingdom_parks: true, kingdom_parks_map: true, kingdom_events: true
    };

    function catalogEntry(type) {
        for (var i = 0; i < (catalog || []).length; i++) {
            if (catalog[i] && catalog[i].type === type) { return catalog[i]; }
        }
        return null;
    }

    /* ---- info card for dynamic blocks (icon + one-line live description) ---- */
    function dynamicInfoCard(type) {
        var ent = catalogEntry(type) || {};
        var icon = ent.icon || 'fa-bolt';
        var desc = ent.description || 'This block pulls live data when the page is viewed.';
        var card = el('div', 'cms-dyninfo');
        card.appendChild(el('div', 'cms-dyninfo-icon', '<i class="fas ' + esc(icon) + '"></i>'));
        var txt = el('div', 'cms-dyninfo-text');
        txt.appendChild(el('div', 'cms-dyninfo-title', '<i class="fas fa-bolt"></i> Live block'));
        txt.appendChild(el('div', 'cms-dyninfo-body', esc(desc)));
        card.appendChild(txt);
        return card;
    }

    /* ---- table rows editor: textarea, one row/line, cells split on " | " ---- */
    function tableRowsField(block, spec) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(spec.label || 'Rows')));
        var ta = el('textarea', 'cms-textarea');
        ta.style.minHeight = '140px';
        ta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
        ta.placeholder = 'Column A | Column B | Column C\nRow 1 cell | Row 1 cell | Row 1 cell';
        // model rows (array of arrays) → text
        var rows = Array.isArray(block.fields[spec.key]) ? block.fields[spec.key] : [];
        ta.value = rows.map(function (r) {
            return (Array.isArray(r) ? r : []).map(function (c) { return String(c == null ? '' : c); }).join(' | ');
        }).join('\n');
        ta.addEventListener('input', function () {
            var lines = ta.value.split('\n');
            var out = [];
            lines.forEach(function (line) {
                if (line.trim() === '') { return; }
                out.push(line.split('|').map(function (c) { return c.trim(); }));
            });
            block.fields[spec.key] = out;
            markDirty();
        });
        wrap.appendChild(ta);
        if (spec.help) { wrap.appendChild(el('div', 'cms-help', spec.help)); }
        return wrap;
    }

    /* ---- one schema field → DOM, bound to `obj` (block.fields or a group obj) --- */
    function renderSchemaField(block, obj, spec) {
        var node;
        switch (spec.type) {
            case 'note':
                node = el('div', 'cms-note');
                node.innerHTML = '<i class="fas fa-info-circle"></i> <span>' + (spec.html || '') + '</span>';
                return node;

            case 'image':
                return imageBound(obj, spec.key, spec.label);

            case 'richtext':
                return fieldRich({ fields: obj }, spec.key, spec.label);

            case 'textarea':
                node = textBoundArea(obj, spec.key, spec.label, spec.placeholder);
                break;

            case 'mono': {
                node = el('div', 'cms-field');
                node.appendChild(el('label', 'cms-label', esc(spec.label)));
                var mta = el('textarea', 'cms-textarea');
                mta.style.minHeight = '180px';
                mta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
                if (spec.placeholder) { mta.placeholder = spec.placeholder; }
                mta.value = obj[spec.key] != null ? obj[spec.key] : '';
                mta.addEventListener('input', function () { obj[spec.key] = mta.value; markDirty(); });
                node.appendChild(mta);
                break;
            }

            case 'select':
                node = selectBound(obj, spec.key, spec.label, spec.options,
                    (spec.options && spec.options.length) ? spec.options[0].value : '');
                break;

            case 'bool': {
                var boolOpts = [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }];
                node = el('div', 'cms-field');
                node.appendChild(el('label', 'cms-label', esc(spec.label)));
                var bsel = el('select', 'cms-select');
                var cur = (obj[spec.key] === undefined) ? 1 : (obj[spec.key] ? 1 : 0);
                boolOpts.forEach(function (o) {
                    var op = el('option'); op.value = o.value; op.textContent = o.label;
                    if (String(cur) === o.value) { op.selected = true; }
                    bsel.appendChild(op);
                });
                obj[spec.key] = cur;
                bsel.addEventListener('change', function () { obj[spec.key] = Number(bsel.value); markDirty(); });
                node.appendChild(bsel);
                break;
            }

            case 'number':
                node = numberBound(obj, spec.key, spec.label, spec.placeholder);
                break;

            case 'tagpicker':
                // Self-contained (renders its own help/warning) → return directly.
                return tagPickerField(obj, spec.key, spec.label, spec.help);

            case 'table_rows':
                return tableRowsField({ fields: obj }, spec);

            case 'group': {
                if (!obj[spec.key] || typeof obj[spec.key] !== 'object' || Array.isArray(obj[spec.key])) {
                    obj[spec.key] = {};
                }
                var gwrap = el('div', 'cms-group');
                gwrap.appendChild(el('div', 'cms-label', esc(spec.label)));
                var inner = el('div', 'cms-group-body');
                (spec.of || []).forEach(function (sub) {
                    inner.appendChild(renderSchemaField(block, obj[spec.key], sub));
                });
                gwrap.appendChild(inner);
                node = gwrap;
                break;
            }

            case 'repeater': {
                var groupWrap = el('div', null);
                groupWrap.appendChild(el('div', 'cms-label', esc(spec.label)));
                if (spec.of === '__image__') {
                    // repeater of images: each item is a media-ref object
                    groupWrap.appendChild(repeater(block, spec.key, spec.singular || 'Image', {}, function (item, i) {
                        return imageBound(block.fields[spec.key], i, spec.singular || 'Image');
                    }));
                } else {
                    var blank = {};
                    (spec.of || []).forEach(function (sub) { blank[sub.key] = ''; });
                    groupWrap.appendChild(repeater(block, spec.key, spec.singular || 'Item', blank, function (item) {
                        var ibox = el('div', null);
                        (spec.of || []).forEach(function (sub) {
                            ibox.appendChild(renderSchemaField(block, item, sub));
                        });
                        return ibox;
                    }));
                }
                node = groupWrap;
                break;
            }

            case 'url':
            case 'text':
            default:
                node = textBound(obj, spec.key, spec.label, spec.placeholder);
                break;
        }
        if (spec.help && node) { node.appendChild(el('div', 'cms-help', spec.help)); }
        return node;
    }

    /* ---- generic schema renderer: walk a schema, emit a friendly form ---- */
    function renderSchemaForm(schema, block, mount) {
        (schema || []).forEach(function (spec) {
            mount.appendChild(renderSchemaField(block, block.fields, spec));
        });
        return mount;
    }

    /* ---- build the body form for one block ---- */
    function buildBlockBody(block) {
        var body = el('div', null);
        var t = block.type;

        if (t === 'rich_text' || t === 'richtext') {
            body.appendChild(fieldText(block, 'kicker', 'Kicker', { placeholder: 'Small label above heading' }));
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldRich(block, 'body', 'Body'));
            body.appendChild(fieldSelect(block, 'align', 'Alignment',
                [{ value: 'left', label: 'Left' }, { value: 'center', label: 'Center' }], 'left'));
            if (!block.fields.cta || typeof block.fields.cta !== 'object') { block.fields.cta = {}; }
            var ctaWrap = el('div', null);
            ctaWrap.appendChild(el('div', 'cms-label', 'Optional CTA'));
            var g = el('div', 'cms-grid2');
            g.appendChild(textBound(block.fields.cta, 'label', 'CTA label'));
            g.appendChild(textBound(block.fields.cta, 'href', 'CTA link'));
            ctaWrap.appendChild(g);
            body.appendChild(ctaWrap);
            return body;
        }

        if (t === 'image') {
            if (!block.fields.image || typeof block.fields.image !== 'object') { block.fields.image = {}; }
            body.appendChild(fieldImage(block.fields, 'image', 'Image'));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional caption' }));
            body.appendChild(fieldText(block, 'href', 'Link (optional)', { placeholder: 'https://…' }));
            return body;
        }

        if (t === 'hero_carousel') {
            body.appendChild(fieldText(block, 'autoplay_ms', 'Autoplay (ms)', { placeholder: '4500' }));
            if (!block.fields.logo || typeof block.fields.logo !== 'object') { block.fields.logo = {}; }
            body.appendChild(imageBound(block.fields, 'logo', 'Logo (optional)'));
            body.appendChild(el('div', 'cms-label', 'Slides'));
            body.appendChild(repeater(block, 'slides', 'Slide',
                { image: {}, kicker: '', headline: '', subcopy: '' },
                function (slide) {
                    var box = el('div', null);
                    box.appendChild(imageBound(slide, 'image', 'Slide image'));
                    box.appendChild(textBound(slide, 'kicker', 'Kicker'));
                    box.appendChild(textBound(slide, 'headline', 'Headline'));
                    box.appendChild(textBound(slide, 'subcopy', 'Subcopy'));
                    return box;
                }));
            body.appendChild(el('div', 'cms-label', 'Call-to-action buttons'));
            body.appendChild(ctaRepeater(block,
                [{ value: 'gold', label: 'Gold (primary)' }, { value: 'ghost', label: 'Ghost' }]));
            return body;
        }

        if (t === 'card_grid') {
            body.appendChild(fieldText(block, 'kicker', 'Kicker'));
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldText(block, 'subheading', 'Subheading'));
            body.appendChild(el('div', 'cms-label', 'Cards'));
            body.appendChild(repeater(block, 'cards', 'Card',
                { image: {}, icon: '', title: '', blurb: '', href: '#' },
                function (card) {
                    var box = el('div', null);
                    box.appendChild(imageBound(card, 'image', 'Card image'));
                    var g = el('div', 'cms-grid2');
                    g.appendChild(textBound(card, 'icon', 'Icon (Font Awesome class, e.g. fa-shield-alt)'));
                    g.appendChild(textBound(card, 'href', 'Link (href)'));
                    box.appendChild(g);
                    box.appendChild(textBound(card, 'title', 'Title'));
                    box.appendChild(textBound(card, 'blurb', 'Blurb'));
                    return box;
                }));
            return body;
        }

        if (t === 'staff_roster') {
            body.appendChild(fieldText(block, 'kicker', 'Kicker'));
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldText(block, 'subheading', 'Subheading'));
            body.appendChild(fieldSelect(block, 'presentation', 'Presentation style',
                [{ value: 'amtgard', label: 'Amtgard name leads' },
                 { value: 'mundane', label: 'Real name leads' }], 'amtgard'));
            body.appendChild(el('div', 'cms-help', 'Choose which name leads on every card. Link a persona to auto-fill names; you can still edit them.'));
            body.appendChild(el('div', 'cms-label', 'People'));
            body.appendChild(repeater(block, 'people', 'Person',
                { image: {}, persona_name: '', mundane_name: '', role: '', bio: '', mundane_id: 0, href: '', show_mundane: false },
                function (person) {
                    var box = el('div', null);
                    box.appendChild(imageBound(person, 'image', 'Photo'));
                    var personaField = textBound(person, 'persona_name', 'Amtgard name');
                    var mundaneField = textBound(person, 'mundane_name', 'Real name');
                    box.appendChild(personaLinkField(person, function () {
                        // After persona-link auto-fill, sync the two bound inputs.
                        var pi = personaField.querySelector('input');
                        var mi = mundaneField.querySelector('input');
                        if (pi) { pi.value = person.persona_name || ''; }
                        if (mi) { mi.value = person.mundane_name || ''; }
                    }));
                    box.appendChild(personaField);
                    box.appendChild(mundaneField);
                    // C21: real-name consent gate. Off by default — the public roster
                    // suppresses a person's mundane name unless this is explicitly
                    // checked (even when the block's presentation is "Real name leads").
                    box.appendChild(checkBound(person, 'show_mundane', 'Publish this person’s real name',
                        'Off by default for privacy. Only turn this on with the person’s consent — otherwise the public card shows their Amtgard name only.'));
                    box.appendChild(textBound(person, 'role', 'Role / title'));
                    box.appendChild(textBoundArea(person, 'bio', 'Bio'));
                    box.appendChild(textBound(person, 'href', 'Manual link (used only if no persona is linked)'));
                    return box;
                }));
            return body;
        }

        if (t === 'cta_band') {
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldText(block, 'subcopy', 'Subcopy', { textarea: true }));
            if (!block.fields.logo || typeof block.fields.logo !== 'object') { block.fields.logo = {}; }
            body.appendChild(imageBound(block.fields, 'logo', 'Logo (optional)'));
            body.appendChild(el('div', 'cms-label', 'Call-to-action buttons'));
            body.appendChild(ctaRepeater(block,
                [{ value: 'gold', label: 'Gold (primary)' }, { value: 'ghost', label: 'Ghost' }]));
            body.appendChild(fieldText(block, 'links', 'Footnote links (optional)'));
            return body;
        }

        if (t === 'heading') {
            body.appendChild(fieldText(block, 'text', 'Heading text'));
            body.appendChild(fieldNumSelect(block, 'level', 'Level',
                [{ value: 2, label: 'H2' }, { value: 3, label: 'H3' }, { value: 4, label: 'H4' }], 2));
            body.appendChild(fieldSelect(block, 'align', 'Alignment',
                [{ value: 'left', label: 'Left' }, { value: 'center', label: 'Center' }, { value: 'right', label: 'Right' }], 'left'));
            return body;
        }

        if (t === 'quote') {
            body.appendChild(fieldText(block, 'text', 'Quote text', { textarea: true }));
            body.appendChild(fieldText(block, 'cite', 'Attribution'));
            return body;
        }

        if (t === 'gallery') {
            body.appendChild(el('div', 'cms-label', 'Images'));
            body.appendChild(repeater(block, 'images', 'Image', {}, function (img, i) {
                return imageBound(block.fields.images, i, 'Image');
            }));
            body.appendChild(fieldNumSelect(block, 'columns', 'Columns',
                [{ value: 2, label: '2' }, { value: 3, label: '3' }, { value: 4, label: '4' }], 3));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional gallery caption' }));
            return body;
        }

        if (t === 'video_embed') {
            body.appendChild(fieldSelect(block, 'provider', 'Provider',
                [{ value: 'youtube', label: 'YouTube' }, { value: 'vimeo', label: 'Vimeo' }], 'youtube'));
            body.appendChild(fieldText(block, 'url', 'Video URL', { placeholder: 'Paste the watch/share URL' }));
            body.appendChild(fieldText(block, 'video_id', 'Video ID (optional)', { placeholder: 'Used if no URL given' }));
            body.appendChild(fieldText(block, 'title', 'Video title', { placeholder: 'What is this video? e.g. Kingdom Coronation 2026' }));
            body.appendChild(el('div', 'cms-help', 'Names the player for screen-reader users and browser tabs. A clear title (“Kingdom Coronation 2026”) is far more useful than the generic “YouTube video player”.'));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional caption' }));
            return body;
        }

        if (t === 'file_download') {
            body.appendChild(el('div', 'cms-label', 'Files'));
            body.appendChild(repeater(block, 'files', 'File',
                { title: '', description: '', url: '', filetype: '', size_label: '' },
                function (file) {
                    var box = el('div', null);
                    box.appendChild(textBound(file, 'title', 'Title'));
                    box.appendChild(textBound(file, 'url', 'Link (URL)', 'https://…'));
                    box.appendChild(textBound(file, 'description', 'Description (optional)'));
                    var g = el('div', 'cms-grid2');
                    g.appendChild(textBound(file, 'filetype', 'File type (e.g. PDF)'));
                    g.appendChild(textBound(file, 'size_label', 'Size label (e.g. 2.4 MB)'));
                    box.appendChild(g);
                    return box;
                }));
            return body;
        }

        if (t === 'accordion') {
            body.appendChild(el('div', 'cms-label', 'Items'));
            body.appendChild(repeater(block, 'items', 'Item',
                { q: '', a: '' },
                function (item) {
                    var box = el('div', null);
                    box.appendChild(textBound(item, 'q', 'Question'));
                    box.appendChild(textBoundArea(item, 'a', 'Answer'));
                    return box;
                }));
            return body;
        }

        // ----- DYNAMIC blocks: live info card + any genuine knobs -----
        if (DYNAMIC_TYPES[t]) {
            body.appendChild(dynamicInfoCard(t));
            if (BLOCK_SCHEMA[t] && BLOCK_SCHEMA[t].length) {
                renderSchemaForm(BLOCK_SCHEMA[t], block, body);
            }
            return body;
        }

        // ----- Schema-driven friendly form (authored blocks w/ a schema) -----
        if (BLOCK_SCHEMA[t]) {
            renderSchemaForm(BLOCK_SCHEMA[t], block, body);
            return body;
        }

        // ----- columns: visual 2/3-column splitter (enh #16) -----
        // Representable structures get the visual editor (which only ever emits a
        // valid array-of-arrays-of-blocks, so it can never trip the JSON autosave
        // block). A legacy/edge structure the splitter can't represent degrades to
        // the JSON editor for THIS instance so no data is lost.
        if (t === 'columns') {
            if (columnsRepresentable(block)) {
                body.appendChild(columnsEditor(block));
            } else {
                body.appendChild(el('div', 'cms-note',
                    '<i class="fas fa-info-circle"></i> This Columns block has a custom structure the visual editor can’t show '
                    + '(only 2- and 3-column layouts are visual). Edit it as JSON below — your content is preserved.'));
                body.appendChild(jsonField(block, 'Columns — advanced (custom structure)',
                    'Each column is a list of blocks. Parsed on save; invalid JSON keeps the last valid value.'));
            }
            return body;
        }

        // ----- LAST-RESORT JSON fallback (unknown / not-yet-shipped types) -----
        body.appendChild(jsonField(block, 'Fields (JSON)',
            'This block type has no friendly form yet — edit its fields as JSON. It is parsed on save; invalid JSON keeps the last valid value.'));
        return body;
    }

    /* ---- toggle a block card's error state + quiet inline message ---- *
     * Finds the rendered card for this block and reflects block._jsonError
     * without a full rerender (keeps the textarea focus + caret intact). */
    function reflectBlockError(block) {
        if (!listEl) { return; }
        var row = rowForBlock(block);
        var card = row ? row.querySelector('.cms-block-card') : null;
        if (!card) { return; }
        card.classList.toggle('cms-block-error', !!block._jsonError);
        if (card._errMsg) { card._errMsg.style.display = block._jsonError ? '' : 'none'; }
    }

    /* ---- shared JSON editor field (columns-advanced + last-resort fallback) ----
     * C20: an invalid-JSON block sets block._jsonError, which the host uses to
     * BLOCK the whole page save. That used to be silent — the author got no cue
     * which block was at fault. We now (a) toast the moment JSON goes invalid,
     * naming the block, and (b) drive a loud inline banner via reflectBlockError. */
    function jsonField(block, label, help) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', label));
        var ta = el('textarea', 'cms-textarea');
        ta.style.minHeight = '160px';
        ta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
        ta.value = JSON.stringify(block.fields || {}, null, 2);
        var prevErr = !!block._jsonError;
        ta.addEventListener('input', function () {
            try {
                var parsed = JSON.parse(ta.value);
                if (parsed && typeof parsed === 'object') {
                    block.fields = parsed;
                    ta.style.borderColor = '';
                    block._jsonError = false;
                } else {
                    throw new Error('not an object');
                }
            } catch (err) {
                ta.style.borderColor = 'var(--ork-badge-red-text)';
                block._jsonError = true;
            }
            reflectBlockError(block);
            // Loud, once-per-transition: warn on valid→invalid; reassure on fix.
            if (block._jsonError && !prevErr) {
                toast('The “' + labelFor(block.type) + '” block has invalid JSON — fix it before saving.', 'error');
            } else if (!block._jsonError && prevErr) {
                toast('JSON fixed — the “' + labelFor(block.type) + '” block can save again.', 'ok');
            }
            prevErr = !!block._jsonError;
            markDirty();
        });
        wrap.appendChild(ta);
        wrap.appendChild(el('div', 'cms-help', help));
        return wrap;
    }

    /* ================= columns: visual 2/3-column splitter (enh #16) =================
     * Replaces the raw-JSON textarea for the `columns` LAYOUT block with a visual
     * editor: choose 2 or 3 columns, and fill each with a mini stack of child blocks
     * that REUSE the same per-block card chrome (icon + label + summary + enable /
     * reorder / remove) and the same field forms (buildBlockBody) as the page list.
     *
     * Data shape — matched EXACTLY to frontdoor/blocks/columns.tpl:
     *   block.fields.columns = [ [child, …], [child, …] (, [child, …]) ]
     *   child = { type, enabled, source, fields }  (renderer shape; render_blocks.tpl
     *   walks each column's array IN ORDER and skips !enabled / unknown types).
     *
     * The editor mutates block.fields.columns IN PLACE and never rebuilds the
     * surrounding fields object, so any unmodelled sibling field is preserved. It
     * only ever writes a valid array-of-arrays-of-objects, so a columns block edited
     * visually can never set block._jsonError (never blocks autosave).
     *
     * Nesting is bounded: the child add-chooser hides 'columns' (addExcludeColumns),
     * and an existing columns-in-columns child is edited as JSON, not a recursive
     * visual editor. */

    // True when the visual splitter can safely represent this block (else → JSON).
    function columnsRepresentable(block) {
        var cols = (block.fields || {}).columns;
        if (cols === undefined || cols === null) { return true; } // new/blank → default 2
        if (!Array.isArray(cols)) { return false; }
        if (cols.length === 0) { return true; }                   // empty → default 2
        if (cols.length !== 2 && cols.length !== 3) { return false; }
        for (var i = 0; i < cols.length; i++) {
            if (!Array.isArray(cols[i])) { return false; }
            for (var j = 0; j < cols[i].length; j++) {
                var ch = cols[i][j];
                if (!ch || typeof ch !== 'object' || Array.isArray(ch)) { return false; }
                if (typeof ch.type !== 'string' || ch.type === '') { return false; }
            }
        }
        return true;
    }

    // Normalize one child block to the renderer shape. No server id — children live
    // inside the parent columns block's fields JSON, not their own rows. The fields
    // object is kept BY REFERENCE so in-place edits (buildBlockBody) reach serialize.
    function normColChild(c) {
        return {
            type:    String((c && c.type) || ''),
            enabled: !(c && (c.enabled === false || c.enabled === 0 || c.enabled === '0')),
            source:  (c && c.source === 'dynamic') ? 'dynamic' : 'authored',
            fields:  (c && c.fields && typeof c.fields === 'object' && !Array.isArray(c.fields)) ? c.fields : {}
        };
    }

    function askDeleteChild(child, onOk) {
        confirmDialog('Remove block',
            'Remove the “' + labelFor(child.type) + '” block from this column? You can re-add it later.',
            'Remove', function () { closeModal(confirmModal); onOk(); });
    }

    // A child block's field editor. Bounds nesting: a columns-in-columns child is
    // edited as JSON (not a recursive visual editor); everything else reuses the
    // exact same field forms as the page-level list.
    function childBlockBody(child) {
        if (child.type === 'columns') {
            return jsonField(child, 'Nested columns — advanced (JSON)',
                'A columns block inside a column is edited as JSON to keep layouts from nesting without bound. Parsed on save; invalid JSON keeps the last valid value.');
        }
        return buildBlockBody(child);
    }

    // One child card — the SAME card chrome as a page-level block, bound to its slot
    // in the column array. `rebuild` re-renders only this column (surgical TinyMCE
    // teardown/init), never the whole page.
    function buildChildCard(colArr, idx, rebuild) {
        var child = colArr[idx];
        var card = el('div', 'cms-block-card cms-cols-childcard' + (child.enabled ? '' : ' cms-block-disabled'));

        var head = el('div', 'cms-block-head');
        head.appendChild(el('span', 'cms-block-icon', '<i class="fas ' + esc(iconFor(child.type)) + '"></i>'));
        head.appendChild(el('span', 'cms-block-type', esc(labelFor(child.type))));
        head.appendChild(el('span', 'cms-block-summary', esc(summarize(child))));

        var tools = el('div', 'cms-block-tools');
        var up = iconBtn('fa-arrow-up', 'Move up', idx === 0);
        var down = iconBtn('fa-arrow-down', 'Move down', idx === colArr.length - 1);
        up.addEventListener('click', function () { swap(colArr, idx, idx - 1); rebuild(); markDirty(); });
        down.addEventListener('click', function () { swap(colArr, idx, idx + 1); rebuild(); markDirty(); });

        var sw = el('label', 'cms-switch');
        var cb = el('input'); cb.type = 'checkbox'; cb.checked = child.enabled;
        cb.setAttribute('aria-label', child.enabled ? 'Block enabled, click to disable' : 'Block disabled, click to enable');
        cb.addEventListener('change', function () {
            child.enabled = cb.checked;
            card.classList.toggle('cms-block-disabled', !child.enabled);
            cb.setAttribute('aria-label', cb.checked ? 'Block enabled, click to disable' : 'Block disabled, click to enable');
            markDirty();
        });
        sw.appendChild(cb); sw.appendChild(el('span', 'cms-slider'));

        var del = iconBtn('fa-trash', 'Remove block', false, true);
        del.addEventListener('click', function () {
            askDeleteChild(child, function () { colArr.splice(idx, 1); rebuild(); markDirty(); });
        });

        tools.appendChild(up); tools.appendChild(down); tools.appendChild(sw); tools.appendChild(del);
        head.appendChild(tools);
        card.appendChild(head);

        var body = el('div', 'cms-block-body');
        body.appendChild(childBlockBody(child));
        card.appendChild(body);
        return card;
    }

    // One column panel: its own child list + an "Add block" that opens the shared
    // chooser routed to append into THIS column. The initial rebuild does NOT init
    // TinyMCE (the caller — renderList/insertRowAt or renderGrid — inits the batch);
    // later user-triggered rebuilds self-init their new editors.
    function buildColumnPanel(cols, ci) {
        var panel = el('div', 'cms-cols-col');
        var colArr = cols[ci];
        var ready = false;

        panel.appendChild(el('div', 'cms-cols-col-head', 'Column ' + (ci + 1)));
        var listWrap = el('div', 'cms-cols-childlist');
        panel.appendChild(listWrap);

        function rebuildChildren() {
            destroyTinyIn(listWrap);
            listWrap.innerHTML = '';
            if (!colArr.length) {
                listWrap.appendChild(el('div', 'cms-cols-empty', 'No blocks in this column yet.'));
            }
            colArr.forEach(function (child, idx) {
                listWrap.appendChild(buildChildCard(colArr, idx, rebuildChildren));
            });
            var add = el('button', 'cms-btn cms-btn-sm cms-cols-add', '<i class="fas fa-plus"></i> Add block');
            add.type = 'button';
            add.addEventListener('click', function () {
                openAddChooserForHandler(function (c) {
                    colArr.push({ type: c.type, enabled: true, source: c.dynamic ? 'dynamic' : 'authored', fields: {} });
                    rebuildChildren(); markDirty();
                });
            });
            listWrap.appendChild(add);
            if (ready) {
                listWrap.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
            }
        }

        rebuildChildren();   // ready=false → caller inits the initial batch
        ready = true;        // subsequent rebuilds self-init their new editors
        return panel;
    }

    // The columns visual editor body (only called for representable blocks).
    function columnsEditor(block) {
        var wrap = el('div', 'cms-cols-editor');

        // Normalize IN PLACE (preserves any sibling fields on the block).
        if (!Array.isArray(block.fields.columns)) { block.fields.columns = []; }
        var cols = block.fields.columns;
        for (var i = 0; i < cols.length; i++) {
            cols[i] = (Array.isArray(cols[i]) ? cols[i] : []).map(normColChild);
        }
        while (cols.length < 2) { cols.push([]); }   // new/blank → 2 columns
        if (cols.length > 3) { cols.length = 3; }     // (representable-check already caps this)

        wrap.appendChild(el('div', 'cms-help',
            'Split this row into side-by-side columns, each holding its own stack of blocks. Columns stack vertically on narrow screens.'));

        var countRow = el('div', 'cms-cols-countrow');
        countRow.appendChild(el('span', 'cms-label', 'Columns'));
        var seg = el('div', 'cms-cols-seg');
        [2, 3].forEach(function (n) {
            var b = el('button', 'cms-btn cms-btn-sm', String(n));
            b.type = 'button';
            b.setAttribute('data-n', String(n));
            b.setAttribute('data-tip', n + ' columns');
            b.addEventListener('click', function () { setCount(n); });
            seg.appendChild(b);
        });
        countRow.appendChild(seg);
        wrap.appendChild(countRow);

        var grid = el('div', 'cms-cols-grid');
        wrap.appendChild(grid);

        function syncChrome() {
            Array.prototype.forEach.call(seg.children, function (b) {
                b.classList.toggle('cms-cols-seg-active', Number(b.getAttribute('data-n')) === cols.length);
            });
            grid.className = 'cms-cols-grid cms-cols-grid-' + cols.length;
        }

        function renderGrid(firstBuild) {
            destroyTinyIn(grid);
            grid.innerHTML = '';
            cols.forEach(function (colArr, ci) { grid.appendChild(buildColumnPanel(cols, ci)); });
            syncChrome();
            // On the very first build the outer machinery (renderList/insertRowAt)
            // inits every data-tiny in the row; a later count change inits here.
            if (!firstBuild) {
                grid.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
            }
        }

        function setCount(n) {
            if (n === cols.length || (n !== 2 && n !== 3)) { return; }
            if (n === 3) {
                cols.push([]);                  // 2 → 3: append a new empty column
            } else {
                var third = cols.pop();         // 3 → 2: merge column 3 into column 2 (lossless)
                cols[1] = cols[1].concat(third);
            }
            renderGrid(false);
            markDirty();
        }

        renderGrid(true);
        return wrap;
    }

    /* ---- icon for a block type (from the catalog) ---- */
    function iconFor(type) {
        var ent = catalogEntry(type);
        return (ent && ent.icon) ? ent.icon : 'fa-cube';
    }

    /* ---- a thin hover-reveal "+" inserter zone that opens the chooser ----
     * Anchored to a BLOCK (insert BEFORE it), not a fixed index, so it keeps
     * pointing at the right slot after surgical reorders. anchorBlock == null →
     * the trailing zone that appends at the end. */
    function inserterZone(anchorBlock) {
        var zone = el('div', 'cms-inserter');
        zone.setAttribute('data-tip', 'Insert a block here');
        var btn = el('button', 'cms-inserter-btn', '<i class="fas fa-plus"></i>');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Insert a block here');
        btn.addEventListener('click', function () {
            var at = (anchorBlock == null) ? model.length : model.indexOf(anchorBlock);
            openAddChooser(at < 0 ? model.length : at);
        });
        zone.appendChild(btn);
        return zone;
    }

    /* ================= surgical DOM helpers (C9) =================
     * Each block is rendered as a "row" = [insert-before zone, card] wrapped in a
     * display:contents div (adds no layout box). Reorder/insert/remove touch a
     * SINGLE row node so we never destroy+rebuild every card — which is what tore
     * down every open TinyMCE editor on any change. Full renderList() is reserved
     * for replaceModel()/seedFromPreset(). */
    function rowNodes() {
        return Array.prototype.slice.call(listEl.querySelectorAll('.cms-block-row'));
    }
    function trailingInserter() {
        return listEl.querySelector('.cms-inserter-trailing');
    }
    function rowForBlock(block) {
        var rows = rowNodes();
        for (var i = 0; i < rows.length; i++) {
            if (rows[i]._block === block) { return rows[i]; }
        }
        return null;
    }
    // Keep every card's up/down disabled state honest after a structural change.
    function refreshRowChrome() {
        var rows = rowNodes();
        rows.forEach(function (r, i) {
            var card = r.querySelector('.cms-block-card');
            if (!card) { return; }
            if (card._upBtn) { card._upBtn.disabled = (i === 0); }
            if (card._downBtn) { card._downBtn.disabled = (i === rows.length - 1); }
        });
        updateCollapseAllBtn();
    }
    // Reorder existing row nodes to match model order (moves nodes, no rebuild).
    function syncRowOrder() {
        var rows = rowNodes();
        var trailing = trailingInserter();
        model.forEach(function (block) {
            for (var i = 0; i < rows.length; i++) {
                if (rows[i]._block === block) { listEl.insertBefore(rows[i], trailing); break; }
            }
        });
    }
    // Build one row (insert-before zone + card) bound to a block.
    function buildRow(block) {
        var row = el('div', 'cms-block-row');
        row._block = block;
        row.appendChild(inserterZone(block));
        row.appendChild(buildCard(block));
        return row;
    }
    // Insert a freshly-built row for `block` (already spliced into model at `at`),
    // init only its own TinyMCE, and refresh chrome — no global teardown.
    function insertRowAt(block, at, scroll) {
        var rows = rowNodes();               // DOM rows BEFORE inserting the new one
        var trailing = trailingInserter();
        if (!trailing) {                     // list may have been empty
            trailing = inserterZone(null);
            trailing.classList.add('cms-inserter-trailing');
            listEl.appendChild(trailing);
        }
        var row = buildRow(block);
        var ref = (at < rows.length) ? rows[at] : trailing;
        listEl.insertBefore(row, ref);
        emptyEl.style.display = model.length ? 'none' : '';
        row.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
        refreshRowChrome();
        if (scroll) {
            var card = row.querySelector('.cms-block-card');
            if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
        return row;
    }
    // Move a block within the model, then move its single row node.
    function moveBlock(from, to) {
        if (from === to || from < 0 || to < 0 || from >= model.length || to >= model.length) { return; }
        var moved = model.splice(from, 1)[0];
        model.splice(to, 0, moved);
        syncRowOrder();
        refreshRowChrome();
        markDirty();
    }
    // Remove a block + its single row node (destroying only that row's editors).
    function removeBlock(block) {
        var i = model.indexOf(block);
        if (i < 0) { return; }
        model.splice(i, 1);
        var row = rowForBlock(block);
        if (row) { destroyTinyIn(row); row.remove(); }
        if (!model.length) {
            var trailing = trailingInserter();
            if (trailing) { trailing.remove(); }
        }
        emptyEl.style.display = model.length ? 'none' : '';
        refreshRowChrome();
        markDirty();
    }

    /* ---- build one block card (bound to the block object, not an index, so its
     * handlers survive surgical reorders) ---- */
    function buildCard(block) {
        var card = el('div', 'cms-block-card' + (block.enabled ? '' : ' cms-block-disabled') + (block._jsonError ? ' cms-block-error' : ''));
        card._block = block;
        // draggable is enabled only while the drag handle is pressed (wireDrag),
        // so text selection inside field inputs never starts a card drag.
        card.setAttribute('draggable', 'false');

        var head = el('div', 'cms-block-head');

        var handle = el('span', 'cms-drag-handle', '<i class="fas fa-grip-vertical"></i>');
        handle.setAttribute('data-tip', 'Drag to reorder');
        head.appendChild(handle);

        var collapseBtn = iconBtn('fa-chevron-down', 'Collapse / expand', false);
        head.appendChild(collapseBtn);
        head.appendChild(el('span', 'cms-block-icon', '<i class="fas ' + esc(iconFor(block.type)) + '"></i>'));
        head.appendChild(el('span', 'cms-block-type', esc(labelFor(block.type))));
        head.appendChild(el('span', 'cms-block-typekey', esc(block.type)));
        head.appendChild(el('span', 'cms-block-summary', esc(summarize(block))));

        var tools = el('div', 'cms-block-tools');
        var up = iconBtn('fa-arrow-up', 'Move up', false);
        var down = iconBtn('fa-arrow-down', 'Move down', false);
        card._upBtn = up;
        card._downBtn = down;
        up.addEventListener('click', function () {
            var i = model.indexOf(block);
            if (i > 0) { moveBlock(i, i - 1); }
        });
        down.addEventListener('click', function () {
            var i = model.indexOf(block);
            if (i > -1 && i < model.length - 1) { moveBlock(i, i + 1); }
        });

        var dup = iconBtn('fa-clone', 'Duplicate block', false);
        dup.addEventListener('click', function () { duplicateBlock(block); });

        var sw = el('label', 'cms-switch');
        var cb = el('input'); cb.type = 'checkbox'; cb.checked = block.enabled;
        function syncSwitchAria() {
            cb.setAttribute('aria-label', cb.checked
                ? 'Block enabled, click to disable'
                : 'Block disabled, click to enable');
        }
        syncSwitchAria();
        cb.addEventListener('change', function () {
            block.enabled = cb.checked;
            card.classList.toggle('cms-block-disabled', !block.enabled);
            syncSwitchAria();
            markDirty();
        });
        sw.appendChild(cb);
        sw.appendChild(el('span', 'cms-slider'));

        var del = iconBtn('fa-trash', 'Delete block', false, true);
        del.addEventListener('click', function () { askDeleteBlock(block); });

        tools.appendChild(up);
        tools.appendChild(down);
        tools.appendChild(dup);
        tools.appendChild(sw);
        tools.appendChild(del);
        head.appendChild(tools);
        card.appendChild(head);

        var body = el('div', 'cms-block-body');
        // loud inline error message (shown only when this block blocks the save)
        var errMsg = el('div', 'cms-block-error-msg', '<i class="fas fa-exclamation-triangle"></i> <span>This block has invalid JSON and won’t be saved until you fix it.</span>');
        errMsg.style.display = block._jsonError ? '' : 'none';
        card._errMsg = errMsg;
        body.appendChild(errMsg);
        body.appendChild(buildBlockBody(block));
        card.appendChild(body);

        card._body = body;
        card._collapseBtn = collapseBtn;
        collapseBtn.addEventListener('click', function () {
            body.classList.toggle('cms-collapsed');
            var icon = collapseBtn.querySelector('i');
            if (icon) { icon.className = body.classList.contains('cms-collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-down'; }
            updateCollapseAllBtn();
        });

        wireDrag(card, handle, block);
        return card;
    }

    /* ================= collapse-all / expand-all ================= */
    // The header button toggles every card at once; its label reflects the NEXT
    // action based on whether any card is currently expanded (so it stays honest
    // even after cards are collapsed one at a time).
    function anyBlockExpanded() {
        return rowNodes().some(function (r) {
            var card = r.querySelector('.cms-block-card');
            return card && card._body && !card._body.classList.contains('cms-collapsed');
        });
    }
    function setAllCollapsed(collapse) {
        rowNodes().forEach(function (r) {
            var card = r.querySelector('.cms-block-card');
            if (!card || !card._body) { return; }
            card._body.classList.toggle('cms-collapsed', collapse);
            if (card._collapseBtn) {
                var ic = card._collapseBtn.querySelector('i');
                if (ic) { ic.className = collapse ? 'fas fa-chevron-right' : 'fas fa-chevron-down'; }
            }
        });
        updateCollapseAllBtn();
    }
    function updateCollapseAllBtn() {
        if (!collapseAllBtn) { return; }
        // Only worth showing once there's more than one block to act on.
        collapseAllBtn.style.display = (model.length > 1) ? '' : 'none';
        collapseAllBtn.innerHTML = anyBlockExpanded()
            ? '<i class="fas fa-angle-double-up"></i> Collapse all'
            : '<i class="fas fa-angle-double-down"></i> Expand all';
    }

    /* ---- render the whole block list (full rebuild — replaceModel/seed only) ---- */
    function renderList() {
        destroyTinyIn(listEl);
        listEl.innerHTML = '';
        emptyEl.style.display = model.length ? 'none' : '';

        model.forEach(function (block) { listEl.appendChild(buildRow(block)); });

        if (model.length) {
            var trailing = inserterZone(null);
            trailing.classList.add('cms-inserter-trailing');
            listEl.appendChild(trailing);
        }

        listEl.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
        refreshRowChrome();
        warnTinyDegradedIfNeeded();
    }

    /* ================= drag-and-drop reorder =================
     * Mouse: native HTML5 DnD (well-tested, integrates with dragover highlighting).
     * Touch / pen: native DnD never fires, so a Pointer-Events fallback reorders by
     * hit-testing the card under the finger. We branch on pointerType at pointerdown
     * so the mouse path is unchanged and touch devices gain reorder for the first
     * time. (Nav-manager drag lives in Cms_nav.tpl — tracked as a separate follow-up.) */
    var dragFromBlock = null;

    // Touch/pen reorder: track the finger, highlight the card underneath, and move
    // the dragged block onto it on release (same splice semantics as the mouse drop).
    function startPointerDrag(card, handle, block, downEvt) {
        var pointerId = downEvt.pointerId;
        var lastOver = null;
        card.classList.add('cms-dragging');
        try { handle.setPointerCapture(pointerId); } catch (e) {}

        function clearOver() {
            if (lastOver) { lastOver.classList.remove('cms-drag-over'); lastOver = null; }
        }
        function onMove(ev) {
            if (ev.pointerId !== pointerId) { return; }
            ev.preventDefault();
            var under = document.elementFromPoint(ev.clientX, ev.clientY);
            var overCard = under ? under.closest('.cms-block-card') : null;
            if (!overCard || overCard === card || !listEl.contains(overCard)) { clearOver(); return; }
            if (overCard !== lastOver) { clearOver(); overCard.classList.add('cms-drag-over'); lastOver = overCard; }
        }
        function teardown() {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onUp);
            handle.removeEventListener('pointercancel', onCancel);
            try { handle.releasePointerCapture(pointerId); } catch (e) {}
            card.classList.remove('cms-dragging');
        }
        function onUp() {
            var target = lastOver;
            teardown();
            clearOver();
            if (!target || target === card) { return; }
            var from = model.indexOf(block);
            var to   = model.indexOf(target._block);
            if (from < 0 || to < 0 || from === to) { return; }
            var moved = model.splice(from, 1)[0];
            var dest = (from < to) ? to - 1 : to;
            model.splice(dest, 0, moved);
            syncRowOrder();
            refreshRowChrome();
            markDirty();
        }
        function onCancel() { teardown(); clearOver(); }

        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onUp);
        handle.addEventListener('pointercancel', onCancel);
    }

    function wireDrag(card, handle, block) {
        // touch-action:none lets the handle capture the drag gesture instead of the
        // browser scrolling the page out from under a touch reorder.
        handle.style.touchAction = 'none';
        // Only the handle initiates a drag (keeps text selection in field inputs).
        handle.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse') {
                // Enable native HTML5 DnD for this mouse drag (handlers below).
                card.setAttribute('draggable', 'true');
                return;
            }
            // Touch / pen: run the Pointer-Events reorder fallback.
            e.preventDefault();
            startPointerDrag(card, handle, block, e);
        });
        handle.addEventListener('pointerup', function () { card.setAttribute('draggable', 'false'); });
        handle.addEventListener('pointercancel', function () { card.setAttribute('draggable', 'false'); });
        card.addEventListener('dragstart', function (e) {
            dragFromBlock = block;
            card.classList.add('cms-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(model.indexOf(block))); } catch (err) {}
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('cms-dragging');
            card.setAttribute('draggable', 'false');
            listEl.querySelectorAll('.cms-drag-over').forEach(function (n) { n.classList.remove('cms-drag-over'); });
            dragFromBlock = null;
        });
        card.addEventListener('dragover', function (e) {
            if (dragFromBlock === null) { return; }
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
            card.classList.add('cms-drag-over');
        });
        card.addEventListener('dragleave', function () { card.classList.remove('cms-drag-over'); });
        card.addEventListener('drop', function (e) {
            e.preventDefault();
            card.classList.remove('cms-drag-over');
            if (dragFromBlock === null || dragFromBlock === block) { dragFromBlock = null; return; }
            var from = model.indexOf(dragFromBlock);
            var target = model.indexOf(block);
            dragFromBlock = null;
            if (from < 0 || target < 0 || from === target) { return; }
            var moved = model.splice(from, 1)[0];
            var dest = (from < target) ? target - 1 : target;
            model.splice(dest, 0, moved);
            syncRowOrder();
            refreshRowChrome();
            markDirty();
        });
    }

    /* ---- duplicate a block (deep copy of its fields) right after it ---- */
    function duplicateBlock(block) {
        var i = model.indexOf(block);
        if (i < 0) { return; }
        var copy = {
            type:    block.type,
            enabled: block.enabled,
            source:  block.source,
            fields:  JSON.parse(JSON.stringify(block.fields || {}))
        };
        model.splice(i + 1, 0, copy);
        insertRowAt(copy, i + 1, false);
        markDirty();
        toast('Block duplicated.', 'ok');
    }

    /* ---- confirm modal (delete block; also reused by host for delete page/post) ---- */
    var confirmModal, confirmTitle, confirmBody, confirmOk;
    var confirmAction = null;

    function confirmDialog(title, body, okLabel, fn) {
        if (!confirmTitle || !confirmBody || !confirmOk) { return; }
        confirmTitle.textContent = title;
        confirmBody.textContent = body;
        confirmOk.textContent = okLabel || 'Delete';
        confirmAction = fn;
        openModal(confirmModal);
    }

    function askDeleteBlock(block) {
        var label = labelFor(block.type);
        confirmDialog('Remove block', 'Remove the "' + label + '" block? You can re-add it later.', 'Remove', function () {
            closeModal(confirmModal);
            removeBlock(block);
        });
    }

    /* ================= Add block ================= *
     * The chooser is searchable + grouped + icon'd, and can insert a new block
     * at a specific index (insertAt). insertAt === null → append at the end. */
    var addModal, addGroupsEl, addSearchEl, addNoMatchEl, addShowAllWrap, addShowAllBtn;
    var addInsertAt = null;      // index to splice at, or null to append
    // enh #16: when set, the chooser routes the picked catalog entry to this handler
    // (a columns child add) instead of inserting a new block into the page model.
    var addPickHandler = null;
    // enh #16: hide the 'columns' block from the chooser (prevents columns-in-columns).
    var addExcludeColumns = false;

    // Stable group order for the chooser sections.
    var GROUP_ORDER = ['Layout', 'Content', 'Media', 'Dynamic', 'Advanced'];

    function insertNewBlock(c) {
        var nb = {
            type: c.type,
            enabled: true,
            source: c.dynamic ? 'dynamic' : 'authored',
            fields: {}
        };
        var at = (addInsertAt === null || addInsertAt < 0 || addInsertAt > model.length)
            ? model.length : addInsertAt;
        model.splice(at, 0, nb);
        closeModal(addModal);
        // Surgical insert of just this card (keeps every other TinyMCE editor alive).
        insertRowAt(nb, at, true);
        markDirty();
    }

    function typeCard(c) {
        var cardBtn = el('button', 'cms-typecard' + (c.available ? '' : ' cms-typecard-disabled'));
        cardBtn.type = 'button';
        if (!c.available) { cardBtn.disabled = true; }
        var icoHtml = '<span class="cms-typecard-icon"><i class="fas ' + esc(c.icon || 'fa-cube') + '"></i></span>';
        var badge = c.available
            ? (c.dynamic ? '<span class="cms-typecard-badge cms-badge-dynamic">live</span>' : '')
            : '<span class="cms-typecard-badge cms-badge-soon">coming soon</span>';
        var descHtml = c.description
            ? '<span class="cms-typecard-desc">' + esc(c.description) + '</span>'
            : '';
        cardBtn.innerHTML =
            icoHtml +
            '<span class="cms-typecard-text">' +
                '<strong>' + esc(c.label) + badge + '</strong>' +
                descHtml +
                '<span class="cms-typecard-key">' + esc(c.type) + '</span>' +
            '</span>';
        if (c.available) {
            cardBtn.addEventListener('click', function () {
                if (addPickHandler) {
                    // enh #16: route into the columns-child add flow, not the page model.
                    var h = addPickHandler; addPickHandler = null;
                    closeModal(addModal);
                    h(c);
                } else {
                    insertNewBlock(c);
                }
            });
        }
        return cardBtn;
    }

    // The set of block types sensible for the current page type. Empty/unknown
    // → allow everything (no scoping). Universal blocks are part of each list.
    function allowedTypeSet() {
        var arr = (blockAllow && blockAllow[pageType]) ? blockAllow[pageType] : null;
        if (!arr || !arr.length) { return null; } // null → no restriction
        var set = {};
        arr.forEach(function (t) { set[t] = true; });
        return set;
    }

    function renderAddChooser(filter) {
        addGroupsEl.innerHTML = '';
        var q = (filter || '').trim().toLowerCase();

        // All addable catalog entries (legacy/non-addable + JSON-only always excluded).
        // enh #16: a columns child add also excludes 'columns' (no nested columns).
        var addable = (catalog || []).filter(function (c) {
            return c.addable !== false && !JSON_ONLY_TYPES[c.type]
                && !(addExcludeColumns && c.type === 'columns');
        });

        // Scope to the page type unless searching or "Show all" is on. When the
        // user is typing a query we search across ALL blocks so anything is
        // findable; the scope only governs the default browse view.
        var allowed = allowedTypeSet();
        var scoped = allowed && !q && !showAllBlocks;
        var hiddenCount = 0;
        var list = addable.filter(function (c) {
            if (q) {
                return String(c.label || '').toLowerCase().indexOf(q) !== -1
                    || String(c.type || '').toLowerCase().indexOf(q) !== -1;
            }
            if (scoped && !allowed[c.type]) { hiddenCount++; return false; }
            return true;
        });

        // bucket by group, preserving GROUP_ORDER then any extras alphabetically
        var buckets = {};
        list.forEach(function (c) {
            var g = c.group || 'Other';
            (buckets[g] = buckets[g] || []).push(c);
        });
        var groups = Object.keys(buckets).sort(function (a, b) {
            var ia = GROUP_ORDER.indexOf(a), ib = GROUP_ORDER.indexOf(b);
            if (ia === -1 && ib === -1) { return a.localeCompare(b); }
            if (ia === -1) { return 1; }
            if (ib === -1) { return -1; }
            return ia - ib;
        });

        var any = false;
        groups.forEach(function (g) {
            var items = buckets[g];
            if (!items.length) { return; }
            any = true;
            // Never collapsed while searching (matches must stay visible).
            var collapsed = !q && !!addGroupCollapsed[g];
            var sec = el('div', 'cms-typegroup' + (collapsed ? ' cms-typegroup-collapsed' : ''));
            var titleBtn = el('button', 'cms-typegroup-title');
            titleBtn.type = 'button';
            titleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            titleBtn.innerHTML =
                '<i class="fas fa-chevron-down cms-typegroup-caret"></i>' +
                '<span>' + esc(g) + '</span>' +
                '<span class="cms-typegroup-count">' + items.length + '</span>';
            var grid = el('div', 'cms-typegrid');
            items.forEach(function (c) { grid.appendChild(typeCard(c)); });
            titleBtn.addEventListener('click', function () {
                var nowCollapsed = !sec.classList.contains('cms-typegroup-collapsed');
                sec.classList.toggle('cms-typegroup-collapsed', nowCollapsed);
                titleBtn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                addGroupCollapsed[g] = nowCollapsed;
            });
            sec.appendChild(titleBtn);
            sec.appendChild(grid);
            addGroupsEl.appendChild(sec);
        });

        addNoMatchEl.style.display = any ? 'none' : '';

        // "Show all blocks" affordance — only when scoping actually hid some and
        // we're not searching. Re-render expanded (or re-scoped) on click.
        if (addShowAllWrap && addShowAllBtn) {
            if (!q && allowed && (showAllBlocks || hiddenCount > 0)) {
                addShowAllWrap.style.display = '';
                addShowAllBtn.innerHTML = showAllBlocks
                    ? '<i class="fas fa-chevron-up"></i> Show only blocks suited to this page'
                    : '<i class="fas fa-chevron-down"></i> Show all blocks (' + hiddenCount + ' more)';
            } else {
                addShowAllWrap.style.display = 'none';
            }
        }
    }

    function openAddChooser(insertAt) {
        addInsertAt = (insertAt === undefined) ? null : insertAt;
        addPickHandler = null;      // page-level add: default insert behavior
        addExcludeColumns = false;  // columns allowed at the page level
        showAllBlocks = false; // always reopen in scoped view
        if (addSearchEl) { addSearchEl.value = ''; }
        renderAddChooser('');
        openModal(addModal);
        if (addSearchEl) { setTimeout(function () { addSearchEl.focus(); }, 30); }
    }

    // enh #16: open the same chooser for a columns child add. The picked catalog
    // entry is passed to `handler` (which appends it into a column) instead of the
    // page model, and 'columns' is hidden so a column can't itself hold columns.
    function openAddChooserForHandler(handler) {
        addInsertAt = null;
        addPickHandler = handler;
        addExcludeColumns = true;
        showAllBlocks = false;
        if (addSearchEl) { addSearchEl.value = ''; }
        renderAddChooser('');
        openModal(addModal);
        if (addSearchEl) { setTimeout(function () { addSearchEl.focus(); }, 30); }
    }

    function wireAddBlock() {
        addModal       = document.getElementById('cmsAddModal');
        addGroupsEl    = document.getElementById('cmsAddGroups');
        addSearchEl    = document.getElementById('cmsAddSearch');
        addNoMatchEl   = document.getElementById('cmsAddNoMatch');
        addShowAllWrap = document.getElementById('cmsAddShowAllWrap');
        addShowAllBtn  = document.getElementById('cmsAddShowAll');
        var addBtn      = document.getElementById('cmsAddBlockBtn');
        var addBtnEmpty = document.getElementById('cmsAddBlockBtnEmpty');
        if (!addModal || !addGroupsEl) { return; }

        if (addShowAllBtn) {
            addShowAllBtn.addEventListener('click', function () {
                showAllBlocks = !showAllBlocks;
                renderAddChooser(addSearchEl ? addSearchEl.value : '');
            });
        }

        if (addBtn)      { addBtn.addEventListener('click', function () { openAddChooser(null); }); }
        if (addBtnEmpty) { addBtnEmpty.addEventListener('click', function () { openAddChooser(null); }); }
        if (addSearchEl) {
            addSearchEl.addEventListener('input', function () { renderAddChooser(addSearchEl.value); });
            addSearchEl.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                // Pickable cards are the enabled (available + addable) type cards.
                var pickable = addGroupsEl.querySelectorAll('.cms-typecard:not(:disabled)');
                if (pickable.length === 1) {
                    pickable[0].click();           // exactly one match → pick it
                } else if (pickable.length > 1) {
                    pickable[0].focus();           // many → move keyboard focus to the first
                }
            });
        }
    }

    /* ================= Media picker ================= */
    var mediaModal, mediaGrid, mediaSearch, mediaSearchBtn, uploadInput, uploadDrop, uploadAlt, uploadDecorative;
    var mediaCallback = null;
    // Lazy-load paging state. A large media library used to be fetched + rendered in
    // one shot; now the picker pulls one page at a time (medialist offset/limit) and
    // appends more as the author scrolls (IntersectionObserver) or clicks "Load more".
    var MEDIA_PAGE = 24;
    var mediaQuery = '', mediaOffset = 0, mediaHasMore = false, mediaLoading = false;
    var mediaMoreBtn = null, mediaMoreIO = null;

    function openMediaPicker(cb) {
        mediaCallback = cb;
        openModal(mediaModal);
        loadMedia('');
    }

    // Build one picker tile: click the image/caption to pick it; edit its alt inline
    // (writes through to the media row) without picking.
    function buildMediaTile(m) {
        var tile = el('div', 'cms-media-tile');
        var img = el('img');
        img.src = m.thumb || m.src;
        img.alt = m.alt || '';
        var cap = el('div', 'cms-media-cap', esc(m.alt || m.filename || ('#' + (m.media_id || ''))));

        function pick() {
            if (mediaCallback) { mediaCallback(m); }
            closeModal(mediaModal);
        }
        img.addEventListener('click', pick);
        cap.addEventListener('click', pick);

        tile.appendChild(img);
        tile.appendChild(cap);
        // #05 + #17: inline alt editing in the picker. Editing here writes the
        // description back to the shared media row (CmsAjax/mediaupdate — CSRF- and
        // scope-guarded via post()), so it's reusable everywhere the image appears.
        if (m.media_id) { tile.appendChild(buildAltEditor(m, cap, img)); }
        return tile;
    }

    // Inline alt editor for a picker tile. The "decorative" tick INTENTIONALLY saves
    // an empty alt (assistive tech then skips the image) — the same teaching pattern
    // as the upload panel, but applied to an existing library image.
    function buildAltEditor(m, cap, img) {
        var box = el('div', 'cms-media-alt');
        // Interacting with the editor must not trigger the tile's "pick" click.
        box.addEventListener('click', function (e) { e.stopPropagation(); });

        var input = el('input', 'cms-input cms-media-alt-input');
        input.type = 'text';
        input.placeholder = 'Describe this image…';
        input.value = m.alt || '';

        var saveBtn = el('button', 'cms-btn cms-btn-sm cms-media-alt-save', 'Save');
        saveBtn.type = 'button';
        saveBtn.setAttribute('data-tip', 'Save this description to the media library');

        var decoLab = el('label', 'cms-check-inline cms-media-alt-deco');
        var deco = el('input'); deco.type = 'checkbox';
        decoLab.appendChild(deco);
        decoLab.appendChild(document.createTextNode(' Decorative (no alt text)'));

        deco.addEventListener('change', function () {
            input.disabled = deco.checked;
            if (deco.checked) { input.value = ''; }
        });

        function save() {
            var alt = deco.checked ? '' : input.value.trim();
            var prev = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';
            // post() sends X-CSRF-Token (window.CMS_CSRF) + the active scope.
            post('mediaupdate', { media_id: m.media_id, alt: alt }).then(function (res) {
                saveBtn.disabled = false;
                saveBtn.textContent = prev;
                if (!res || !res.ok) { toast((res && res.error) || 'Could not save the description.', 'error'); return; }
                // Reflect the sanitized value the server echoed back.
                m.alt = (res.alt != null) ? String(res.alt) : alt;
                input.value = m.alt;
                if (img) { img.alt = m.alt; }
                if (cap) { cap.textContent = m.alt || m.filename || ('#' + (m.media_id || '')); }
                toast(deco.checked ? 'Marked decorative — empty alt saved.' : 'Description saved.', 'ok');
            }).catch(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = prev;
                toast('Network error saving the description.', 'error');
            });
        }
        saveBtn.addEventListener('click', save);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });

        var row = el('div', 'cms-media-alt-row');
        row.appendChild(input);
        row.appendChild(saveBtn);
        box.appendChild(row);
        box.appendChild(decoLab);
        return box;
    }

    // Append a page of tiles. `reset` clears the grid first (new search / reopen).
    function appendMediaTiles(items, reset) {
        if (reset) { mediaGrid.innerHTML = ''; }
        if (reset && (!items || !items.length)) {
            mediaGrid.appendChild(el('div', 'cms-media-empty', 'No media yet. Upload an image above.'));
            return;
        }
        (items || []).forEach(function (m) { mediaGrid.appendChild(buildMediaTile(m)); });
    }

    // Create (once) the "Load more" control + its IntersectionObserver, then reflect
    // the current paging state onto it.
    function syncMediaMore() {
        if (!mediaMoreBtn && mediaGrid && mediaGrid.parentNode) {
            mediaMoreBtn = el('button', 'cms-btn cms-btn-sm cms-btn-ghost cms-media-more', 'Load more images');
            mediaMoreBtn.type = 'button';
            mediaMoreBtn.style.display = 'none';
            mediaMoreBtn.addEventListener('click', function () { loadMediaPage(false); });
            mediaGrid.parentNode.insertBefore(mediaMoreBtn, mediaGrid.nextSibling);
            // Auto-load the next page when the button scrolls into view inside the
            // modal body. The manual click above is the fallback if IO is unavailable.
            if (typeof IntersectionObserver !== 'undefined') {
                mediaMoreIO = new IntersectionObserver(function (entries) {
                    if (entries[0] && entries[0].isIntersecting) { loadMediaPage(false); }
                }, { root: mediaGrid.parentNode, rootMargin: '150px' });
                mediaMoreIO.observe(mediaMoreBtn);
            }
        }
        if (!mediaMoreBtn) { return; }
        mediaMoreBtn.style.display = mediaHasMore ? '' : 'none';
        mediaMoreBtn.disabled = mediaLoading;
        mediaMoreBtn.textContent = mediaLoading ? 'Loading…' : 'Load more images';
    }

    // Fetch one page. `reset` starts over (offset 0, new/blank search).
    function loadMediaPage(reset) {
        if (mediaLoading) { return; }
        if (!reset && !mediaHasMore) { return; }
        if (reset) {
            mediaOffset = 0;
            mediaHasMore = false;
            if (mediaMoreBtn) { mediaMoreBtn.style.display = 'none'; }
            mediaGrid.innerHTML = '<div class="cms-media-empty">Loading…</div>';
        }
        mediaLoading = true;
        syncMediaMore();

        // AJAX already ends in '...?Route=CmsAjax/', so params must be joined with
        // '&' — a second '?' would corrupt the Route param (empties $_GET).
        var params = { limit: String(MEDIA_PAGE), offset: String(mediaOffset) };
        if (mediaQuery) { params.q = mediaQuery; }
        var url = AJAX + 'medialist&' + new URLSearchParams(params).toString()
            + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (res) {
                mediaLoading = false;
                if (!res || !res.ok) {
                    if (reset) { mediaGrid.innerHTML = '<div class="cms-media-empty">' + esc((res && res.error) || 'Could not load media.') + '</div>'; }
                    else { toast((res && res.error) || 'Could not load more media.', 'error'); }
                    syncMediaMore();
                    return;
                }
                var items = res.media || [];
                mediaHasMore = !!res.has_more;
                mediaOffset += items.length;
                appendMediaTiles(items, reset);
                syncMediaMore();
            })
            .catch(function () {
                mediaLoading = false;
                if (reset) { mediaGrid.innerHTML = '<div class="cms-media-empty">Network error.</div>'; }
                else { toast('Network error loading more media.', 'error'); }
                syncMediaMore();
            });
    }

    // Back-compat entry point: (re)load the picker from the top for query `q`.
    function loadMedia(q) {
        mediaQuery = (q == null) ? '' : String(q);
        loadMediaPage(true);
    }

    // C1: alt text authored at upload. A "decorative" tick INTENTIONALLY sends an
    // empty alt (assistive tech then skips the image) — distinct from simply
    // forgetting to describe it, which is why the choice is explicit.
    function uploadAltValue() {
        if (uploadDecorative && uploadDecorative.checked) { return ''; }
        return uploadAlt ? uploadAlt.value.trim() : '';
    }
    function resetUploadMeta() {
        if (uploadAlt) { uploadAlt.value = ''; }
        if (uploadDecorative) { uploadDecorative.checked = false; }
        if (uploadAlt) { uploadAlt.disabled = false; }
    }

    function doUpload(file) {
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { toast('Image is larger than 8MB.', 'error'); return; }
        var alt = uploadAltValue();
        var reader = new FileReader();
        reader.onerror = function () { toast('Could not read file.', 'error'); loadMedia(''); };
        reader.onload = function () {
            mediaGrid.innerHTML = '<div class="cms-media-empty"><span class="cms-spin"></span> Uploading…</div>';
            post('mediaupload', { data: reader.result, filename: file.name, alt: alt }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); loadMedia(''); return; }
                toast('Image uploaded.', 'ok');
                resetUploadMeta();
                loadMedia('');
            }).catch(function () { toast('Network error.', 'error'); loadMedia(''); });
        };
        reader.readAsDataURL(file);
    }

    function wireMediaPicker() {
        mediaModal = document.getElementById('cmsMediaModal');
        mediaGrid = document.getElementById('cmsMediaGrid');
        mediaSearch = document.getElementById('cmsMediaSearch');
        mediaSearchBtn = document.getElementById('cmsMediaSearchBtn');
        uploadInput = document.getElementById('cmsUploadInput');
        uploadDrop = document.getElementById('cmsUploadDrop');
        uploadAlt = document.getElementById('cmsUploadAlt');
        uploadDecorative = document.getElementById('cmsUploadDecorative');
        if (!mediaModal) { return; }

        // A decorative image needs no description — grey the alt field to teach why.
        if (uploadDecorative && uploadAlt) {
            uploadDecorative.addEventListener('change', function () {
                uploadAlt.disabled = uploadDecorative.checked;
                if (uploadDecorative.checked) { uploadAlt.value = ''; }
            });
        }

        if (mediaSearchBtn) {
            mediaSearchBtn.addEventListener('click', function () { loadMedia(mediaSearch.value.trim()); });
        }
        if (mediaSearch) {
            mediaSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadMedia(mediaSearch.value.trim()); } });
        }
        if (uploadInput) {
            uploadInput.addEventListener('change', function () { doUpload(uploadInput.files[0]); uploadInput.value = ''; });
        }
        if (uploadDrop) {
            ['dragenter', 'dragover'].forEach(function (ev) {
                uploadDrop.addEventListener(ev, function (e) { e.preventDefault(); uploadDrop.classList.add('cms-drag-active'); });
            });
            ['dragleave', 'drop'].forEach(function (ev) {
                uploadDrop.addEventListener(ev, function (e) { e.preventDefault(); uploadDrop.classList.remove('cms-drag-active'); });
            });
            uploadDrop.addEventListener('drop', function (e) {
                if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) { doUpload(e.dataTransfer.files[0]); }
            });
        }
    }

    /* ================= pristine check (for preset reseeding) ================= */
    function blockHasContent(b) {
        var f = b.fields || {};
        return Object.keys(f).some(function (k) {
            var v = f[k];
            if (v == null) { return false; }
            if (typeof v === 'string') { return v.trim() !== ''; }
            if (Array.isArray(v)) { return v.length > 0; }
            if (typeof v === 'object') { return Object.keys(v).length > 0; }
            return !!v;
        });
    }

    /* ================= public API ================= */
    function init(opts) {
        opts = opts || {};
        catalog   = Array.isArray(opts.catalog) ? opts.catalog : [];
        labels    = (opts.labels && typeof opts.labels === 'object') ? opts.labels : {};
        pageTypes = Array.isArray(opts.pageTypes) ? opts.pageTypes : [];
        tagCatalog = Array.isArray(opts.tags) ? opts.tags : [];
        blockAllow = (opts.blockAllow && typeof opts.blockAllow === 'object') ? opts.blockAllow : {};
        pageType  = (typeof opts.pageType === 'string') ? opts.pageType : '';
        if (typeof opts.onDirty === 'function') { onDirty = opts.onDirty; }
        if (opts.ajaxUrl) { AJAX = opts.ajaxUrl; }

        model = (Array.isArray(opts.blocks) ? opts.blocks : []).map(normBlock);

        listEl  = document.getElementById('cmsBlockList');
        emptyEl = document.getElementById('cmsBlockEmpty');
        toastEl = document.getElementById('cmsToast');

        confirmModal = document.getElementById('cmsConfirmModal');
        confirmTitle = document.getElementById('cmsConfirmTitle');
        confirmBody  = document.getElementById('cmsConfirmBody');
        confirmOk    = document.getElementById('cmsConfirmOk');
        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                var fn = confirmAction;
                confirmAction = null;
                if (fn) { fn(); }
            });
        }

        collapseAllBtn = document.getElementById('cmsCollapseAll');
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                // Any expanded → collapse them all; all already collapsed → expand all.
                setAllCollapsed(anyBlockExpanded());
            });
        }

        wireAddBlock();
        wireMediaPicker();
        renderList();
        observeTheme();   // C31: reskin open editors when the app theme flips
    }

    return {
        init: init,
        serialize: function () {
            syncTiny();
            return model.map(function (b, i) {
                return {
                    // C15: send the stable id (0 = new row) so the server upsert
                    // matches existing rows instead of recreating them.
                    id:      b.id || 0,
                    type:    b.type,
                    enabled: b.enabled ? 1 : 0,
                    order:   i * 10,
                    source:  (b.source === 'dynamic' ? 'dynamic' : 'authored'),
                    fields:  b.fields || {}
                };
            });
        },
        setPageType: function (type) {
            pageType = (typeof type === 'string') ? type : '';
        },
        seedFromPreset: function (type) {
            var preset = presetBlocksFor(type);
            if (!preset) { return false; }
            model = preset.map(normBlock);
            renderList();
            markDirty();
            return true;
        },
        replaceModel: function (blocks) {
            model = (Array.isArray(blocks) ? blocks : []).map(normBlock);
            renderList();
        },
        isPristine: function () {
            return model.every(function (b) { return !blockHasContent(b); });
        },
        isEmpty: function () { return model.length === 0; },
        hasJsonError: function () {
            return model.some(function (b) { return b._jsonError; });
        },
        // C20: jump the author to the first save-blocking (invalid-JSON) block and
        // name it — call this from the host's save handler when hasJsonError() is
        // true so the block is loud + recoverable instead of a silent failed save.
        focusFirstError: function () {
            for (var i = 0; i < model.length; i++) {
                if (!model[i]._jsonError) { continue; }
                var row = rowForBlock(model[i]);
                var card = row ? row.querySelector('.cms-block-card') : null;
                if (card) {
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.classList.add('cms-block-error-flash');
                    setTimeout(function (c) { return function () { c.classList.remove('cms-block-error-flash'); }; }(card), 1500);
                }
                toast('The “' + labelFor(model[i].type) + '” block has invalid JSON — fix it, then save again.', 'error');
                return true;
            }
            return false;
        },
        confirmDialog: confirmDialog,
        closeConfirm: function () { closeModal(confirmModal); },
        confirmOkEl: function () { return confirmOk; },
        // Open the shared media-library picker; cb receives the chosen media-ref.
        // Lets the host (e.g. a post hero image) reuse the same picker the block
        // image fields use, without duplicating upload/search wiring.
        pickMedia: function (cb) { openMediaPicker(cb); },
        toast: toast
    };
})();
</script>
