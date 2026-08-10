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

<?php /* ---- Server-dynamic bootstrap: the ONLY PHP the engine needs. It stays
       inline because it carries server values; the engine itself is now the
       standalone static asset loaded immediately below. ---- */ ?>
<script>
window.CmsBlockEditorBoot = {
    UIR: <?= json_encode(UIR) ?>,
    tinymceSrc: <?= json_encode($beTinySrc) ?>
};
</script>
<?php /* ---- C27 seam REALIZED: the engine below now lives in a standalone static
       asset (script/cms-block-editor.js) — same document position, and SYNCHRONOUS
       (no defer/async) on purpose: both host templates call CmsBlockEditor.init(...)
       from a later inline <script>, so the engine must already be defined. ---- */ ?>
<script src="<?= HTTP_TEMPLATE ?>default/script/cms-block-editor.js?v=<?= filemtime(__DIR__ . '/../script/cms-block-editor.js') ?>"></script>
