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
 * The block data (blocks/catalog/labels/pageTypes) is NOT supplied by this
 * partial — each host template serializes its own and passes it straight into
 * its own CmsBlockEditor.init({...}) call.
 *
 * Receives (from the host template, before including):
 *   $beHeading   blocks-column heading text            — defaults to 'Blocks'
 *   UIR (constant)
 */

$beHeading = isset($beHeading) ? (string)$beHeading : 'Blocks';
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

<?php // Media-picker styling (#cmsMediaGrid inline alt editor + Load-more) lives
      // in the shared, cacheable cms-admin.css, loaded once by cms/_shell_top.tpl
      // which every including surface pulls in first — no per-render inline block. ?>

<?php // Columns visual-splitter styling (.cms-cols-*) lives in the shared,
      // cacheable cms-admin.css — no per-render inline block. ?>

<?php /* ---- Media picker modal ----
        Two views over one library: Grid for recognising an image by sight,
        List for auditing and fixing descriptions. The toolbar is shared and
        sticky; only the results region swaps. Sorting, searching and the
        "needs a description" filter are all served by CmsAjax/medialist, so
        they are true of the whole library rather than of the rows lazy-loaded
        so far. */ ?>
<div class="cms-modal-overlay" id="cmsMediaModal">
    <div class="cms-modal cms-media-modal" role="dialog" aria-modal="true" aria-label="Media library">
        <div class="cms-modal-head">
            <h3>Media library</h3>
            <?php /* The view toggle sits in the head, next to the title, so it
                    reads as a property of the whole modal rather than a filter
                    on the results. */ ?>
            <div class="cms-viewtoggle" role="group" aria-label="View">
                <button type="button" class="cms-viewtoggle-btn cms-is-on" id="cmsMediaViewGrid"
                        data-view="grid" aria-pressed="true">
                    <i class="fas fa-th-large" aria-hidden="true"></i> Grid
                </button>
                <button type="button" class="cms-viewtoggle-btn" id="cmsMediaViewList"
                        data-view="list" aria-pressed="false">
                    <i class="fas fa-list" aria-hidden="true"></i> List
                </button>
            </div>
            <button type="button" class="cms-modal-close" data-close-modal aria-label="Close">&times;</button>
        </div>

        <div class="cms-media-toolbar">
            <div class="cms-media-search">
                <i class="fas fa-search" aria-hidden="true"></i>
                <label class="cms-sr-only" for="cmsMediaSearch">Search media</label>
                <input type="search" class="cms-input" id="cmsMediaSearch"
                       placeholder="Search by name or description" autocomplete="off">
            </div>
            <button type="button" class="cms-chip" id="cmsMediaNoAlt" aria-pressed="false"
                    data-tip="Show only images with no description">
                <span class="cms-dot-warn" aria-hidden="true"></span>
                <span id="cmsMediaNoAltLabel">0 need a description</span>
            </button>
            <span class="cms-media-count" id="cmsMediaCount" aria-live="polite"></span>
            <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsMediaUploadToggle"
                    aria-expanded="false" aria-controls="cmsUploadPanel">
                <i class="fas fa-plus" aria-hidden="true"></i> Upload
            </button>
        </div>

        <?php /* The upload panel is collapsed by default and titled, so the alt
                field inside it cannot be mistaken for the per-image description
                editors in the results below — they write to different things. */ ?>
        <div class="cms-upload-panel" id="cmsUploadPanel" hidden>
            <h4 class="cms-upload-panel-title"><i class="fas fa-cloud-upload-alt" aria-hidden="true"></i> New upload</h4>
            <label class="cms-upload-drop" id="cmsUploadDrop">
                <i class="fas fa-cloud-upload-alt" aria-hidden="true"></i>
                <div>Click or drop an image &mdash; JPG, PNG, GIF or WebP, up to 5&nbsp;MB</div>
                <input type="file" id="cmsUploadInput" accept="image/jpeg,image/png,image/gif,image/webp">
            </label>
            <?php /* Alt text is authored at upload time (kept OUT of the drop
                    <label> so clicking the field never re-triggers the file picker). */ ?>
            <div class="cms-upload-meta">
                <div class="cms-field">
                    <label class="cms-label" for="cmsUploadAlt">Describe the image you&rsquo;re uploading</label>
                    <input type="text" class="cms-input" id="cmsUploadAlt"
                           placeholder="A fighter in a blue tabard blocking a sword blow">
                </div>
                <label class="cms-check-inline"><input type="checkbox" id="cmsUploadDecorative"> This one is decorative &mdash; save it with no description</label>
                <div class="cms-help">Screen readers read this description aloud in place of the image. Skip it only for textures, borders and flourishes that carry no information &mdash; that intentionally saves an empty description so assistive tech passes over the image.</div>
            </div>
        </div>

        <div class="cms-media-body">
            <?php /* Grid view. */ ?>
            <div class="cms-media-grid" id="cmsMediaGrid">
                <div class="cms-media-empty">Loading&hellip;</div>
            </div>

            <?php /* List view. Sorting is server-side (medialist&sort=&dir=), so a
                    sort is true of the whole library, not of the loaded page. */ ?>
            <div class="cms-media-listwrap" id="cmsMediaListWrap" hidden>
                <table class="cms-media-list">
                    <thead>
                        <tr>
                            <th scope="col" class="cms-media-list-thumb"><span class="cms-sr-only">Preview</span></th>
                            <th scope="col" data-sort="filename" aria-sort="none">
                                <button type="button" class="cms-sort-btn">Name <i class="fas fa-sort" aria-hidden="true"></i></button>
                            </th>
                            <th scope="col" data-sort="alt" aria-sort="none">
                                <button type="button" class="cms-sort-btn">Description <i class="fas fa-sort" aria-hidden="true"></i></button>
                            </th>
                            <th scope="col" class="cms-ta-r cms-hide-sm" data-sort="px" aria-sort="none">
                                <button type="button" class="cms-sort-btn">Dimensions <i class="fas fa-sort" aria-hidden="true"></i></button>
                            </th>
                            <th scope="col" class="cms-ta-r cms-hide-sm" data-sort="bytes" aria-sort="none">
                                <button type="button" class="cms-sort-btn">Weight <i class="fas fa-sort" aria-hidden="true"></i></button>
                            </th>
                            <th scope="col" class="cms-ta-r cms-hide-sm" data-sort="created" aria-sort="descending">
                                <button type="button" class="cms-sort-btn">Added <i class="fas fa-sort" aria-hidden="true"></i></button>
                            </th>
                            <th scope="col" class="cms-media-list-act"><span class="cms-sr-only">Use</span></th>
                        </tr>
                    </thead>
                    <tbody id="cmsMediaList"></tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/_confirm_modal.tpl'; ?>

<div class="cms-toast" id="cmsToast" role="status" aria-live="polite" aria-atomic="true"></div>

<?php
/* TinyMCE source — prefer a self-hosted, vendored bundle if one exists under the
 * template's static assets; otherwise fall back to the pinned CDN build. Vendoring
 * the 7.6.0 bundle removes the third-party dependency + the silent-degradation risk
 * (a CDN outage otherwise turns every rich-text field into a raw-HTML textarea with
 * no warning). Dropping tinymce.min.js at the path below is an asset-add, not a
 * template change. */
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
