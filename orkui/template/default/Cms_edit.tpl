<?php
/**
 * Cms_edit.tpl — CMS block editor. PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Receives (from Controller_Cms::edit):
 *   $Page         ['page_id','slug','type','title','status','published_at',
 *                  'hero_media_id','meta_description','is_system','scope_type','scope_id']
 *   $Blocks       list of ['id','type','enabled','order','source','fields'=>[...]]
 *   $IsNew        bool
 *   $BlockCatalog list of ['type','label','group','dynamic','available']
 *   $PageTypes    list of ['type','label','blocks'=>[default block types]]
 *   $Caps         ['create','edit','publish','delete','media','nav','roles' => bool]
 *   UIR, HTTP_TEMPLATE (constants)
 *
 * Posts to CmsAjax: savepage, publish, unpublish, deletepage, mediaupload, medialist.
 */

$page    = isset($Page) && is_array($Page) ? $Page : array();
$blocks  = isset($Blocks) && is_array($Blocks) ? $Blocks : array();
$isNew   = !empty($IsNew);
$catalog = isset($BlockCatalog) && is_array($BlockCatalog) ? $BlockCatalog : array();
$blockAllow = isset($BlockAllow) && is_array($BlockAllow) ? $BlockAllow : array();
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();

// Page-type enum the meta form offers (mirror controller _pageTypes()).
$pageTypes = isset($PageTypes) && is_array($PageTypes) ? $PageTypes : array(
    array('type' => 'composed',   'label' => 'Composed / Landing'),
    array('type' => 'article',    'label' => 'Article / Text'),
    array('type' => 'media',      'label' => 'Media / Gallery'),
    array('type' => 'resource',   'label' => 'Resource / Document'),
    array('type' => 'blog_index', 'label' => 'Blog Index'),
    array('type' => 'dynamic',    'label' => 'Dynamic Data'),
);

// A "type=" hint may arrive on the New-page URL — seed the meta form's type.
// Only honor it if it matches a known page type (allowlist) to avoid
// reflecting an attacker-controlled value into the inline <script> block.
$urlType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';
if ($urlType !== '') {
    $allowedTypes = array_column($pageTypes, 'type');
    if (!in_array($urlType, $allowedTypes, true)) {
        $urlType = '';
    }
}

$pageId       = (int)($page['page_id'] ?? 0);
$pTitle       = (string)($page['title'] ?? '');
$pSlug        = (string)($page['slug'] ?? '');
$pType        = $urlType !== '' ? $urlType : (string)($page['type'] ?? 'composed');
$pMeta        = (string)($page['meta_description'] ?? '');
$pStatus      = (string)($page['status'] ?? 'draft');
$pIsSystem    = !empty($page['is_system']);
$isPublished  = ($pStatus === 'published');

// The system/front-door page (is_system, or slug 'home') renders as the public
// cinematic landing — flag it so the editor shows an identity banner.
$isFrontDoor  = ($pIsSystem || strtolower($pSlug) === 'home');

$canEdit    = !empty($caps['edit']) || !empty($caps['create']);
$canPublish = !empty($caps['publish']);
$canDelete  = !empty($caps['delete']) && !$pIsSystem && !$isNew;

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Catalog used by JS for labels + the Add-block chooser (only `available` blocks
// are offered for adding, but we keep a full label map for any existing block).
$catalogLabels = array();
foreach ($catalog as $c) {
    $catalogLabels[$c['type']] = $c['label'];
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'pages';
$cmsTitle   = $isNew ? 'New Page' : 'Edit: ' . $pTitle;
$cmsCrumbs  = array(
    array('label' => 'Dashboard', 'href' => UIR . 'Cms/dashboard'),
    array('label' => 'Pages',           'href' => UIR . 'Cms/index'),
    array('label' => $isNew ? 'New Page' : $pTitle),
);
$cmsActions = '';

/* Page settings live in the rail (beneath the nav) — built before the shell renders. */
ob_start();
?>
<div class="cms-rail-settings">
    <h2 class="cms-rail-section-title">Page settings</h2>
    <div class="cms-field">
        <label class="cms-label" for="cmsTitle">Title</label>
        <input type="text" class="cms-input" id="cmsTitle" value="<?= $h($pTitle) ?>" placeholder="Page title">
    </div>
    <div class="cms-field">
        <label class="cms-label" for="cmsSlug">Slug</label>
        <input type="text" class="cms-input" id="cmsSlug" value="<?= $h($pSlug) ?>" placeholder="page-slug"<?= $pIsSystem ? ' readonly' : '' ?>>
        <div class="cms-help">URL path. Auto-filled from the title until you edit it.</div>
    </div>
    <div class="cms-field">
        <label class="cms-label" for="cmsType">Type</label>
        <select class="cms-select" id="cmsType">
            <?php foreach ($pageTypes as $pt):
                $sel = ((string)$pt['type'] === $pType) ? ' selected' : '';
            ?>
                <option value="<?= $h($pt['type']) ?>"<?= $sel ?>><?= $h($pt['label']) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="cms-field">
        <label class="cms-label" for="cmsMeta">Meta description</label>
        <textarea class="cms-textarea" id="cmsMeta" placeholder="Short summary for search engines." style="min-height:58px;"><?= $h($pMeta) ?></textarea>
    </div>
    <?php if ($canDelete): ?>
    <div class="cms-action-row" style="margin-top:10px;">
        <button type="button" class="cms-btn cms-btn-danger cms-btn-sm cms-btn-block" id="cmsDeleteBtn"><i class="fas fa-trash"></i> Delete page</button>
    </div>
    <?php endif; ?>
</div>
<?php
$cmsRailExtra = ob_get_clean();
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php /* ============ STICKY EDITOR ACTION BAR ============ */ ?>
    <div class="cms-editbar" id="cmsEditBar">
        <div class="cms-editbar-status">
            <span class="cms-badge cms-badge-<?= $isPublished ? 'published' : 'draft' ?>" id="cmsStatusBadge">
                <?= $isPublished ? 'Published' : 'Draft' ?>
            </span>
            <?php if ($pIsSystem): ?><span class="cms-badge cms-badge-system">System</span><?php endif; ?>
            <span class="cms-editbar-hint" id="cmsSavedHint"></span>
        </div>
        <div class="cms-editbar-actions">
            <?php if ($canEdit): ?>
                <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsSaveBtn"><i class="fas fa-save"></i> Save</button>
            <?php endif; ?>
            <?php if ($canPublish): ?>
                <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="cmsPubBtn" data-status="<?= $isPublished ? 'published' : 'draft' ?>"<?= $isNew ? ' disabled' : '' ?>>
                    <?php if ($isPublished): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                </button>
            <?php endif; ?>
            <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="cmsPreviewToggle"<?= $pageId > 0 ? '' : ' disabled data-needsave="1" data-tip="Save the page first to preview it."' ?>>
                <i class="fas fa-eye"></i> Preview
            </button>
        </div>
    </div>

    <?php if ($isFrontDoor): ?>
    <div class="cms-frontdoor-banner" role="note">
        <span class="cms-frontdoor-mark"><i class="fas fa-home"></i></span>
        <div class="cms-frontdoor-text">
            <strong>You're editing the public Front Door.</strong>
            <span>These blocks render as the public landing page visitors see first.</span>
        </div>
    </div>
    <?php endif; ?>

    <div class="cms-editor cms-editor-haspreview" id="cmsEditorGrid">

        <?php
        /* ---- Blocks column + modals + block engine: SHARED partial ---- */
        $beBlocks    = $blocks;
        $beCatalog   = $catalog;
        $beLabels    = $catalogLabels;
        $bePageTypes = $pageTypes;
        $beCanEdit   = $canEdit;
        $beHeading   = 'Blocks';
        include DIR_TEMPLATE . 'default/cms/_block_editor.tpl';
        ?>

        <?php /* ============ IN-CONTEXT PREVIEW PANE ============ */ ?>
        <aside class="cms-preview-pane" id="cmsPreviewPane" aria-hidden="true">
            <div class="cms-preview-pane-head">
                <span class="cms-preview-pane-title"><i class="fas fa-eye"></i> Preview</span>
                <div class="cms-preview-devtoggle" role="group" aria-label="Preview width">
                    <button type="button" class="cms-devbtn cms-devbtn-active" data-device="desktop" data-tip="Desktop width"><i class="fas fa-desktop"></i></button>
                    <button type="button" class="cms-devbtn" data-device="mobile" data-tip="Mobile width"><i class="fas fa-mobile-alt"></i></button>
                </div>
                <span class="cms-spacer"></span>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsPreviewRefresh" data-tip="Refresh preview"><i class="fas fa-redo"></i></button>
                <a class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsPreviewOpen" href="<?= $pageId > 0 ? UIR . 'Cms/preview/' . $pageId : '#' ?>" target="_blank" rel="noopener" data-tip="Open in new tab"><i class="fas fa-external-link-alt"></i></a>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-preview-close" id="cmsPreviewClose" data-tip="Close preview"><i class="fas fa-times"></i></button>
            </div>
            <div class="cms-preview-pane-body">
                <div class="cms-preview-frame-wrap" id="cmsPreviewFrameWrap" data-device="desktop">
                    <iframe class="cms-preview-iframe" id="cmsPreviewIframe" title="Page preview" src="about:blank"></iframe>
                </div>
            </div>
        </aside>

    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<script>
(function () {
    'use strict';

    var UIR  = <?= json_encode(UIR) ?>;

    // Server state injected safely. The block engine lives in the shared
    // cms/_block_editor.tpl partial; this script owns the page META form +
    // the save / publish / delete flow, wiring into window.CmsBlockEditor.
    var STATE = {
        pageId:  <?= (int)$pageId ?>,
        isNew:   <?= $isNew ? 'true' : 'false' ?>,
        canEdit:    <?= $canEdit ? 'true' : 'false' ?>,
        canPublish: <?= $canPublish ? 'true' : 'false' ?>
    };

    var BE = window.CmsBlockEditor;

    /* ---- toast (delegate to the shared engine) ---- */
    function toast(msg, kind) { if (BE) { BE.toast(msg, kind); } }

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(UIR + 'CmsAjax/' + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ================= meta form helpers ================= */
    var titleInput = document.getElementById('cmsTitle');
    var slugInput  = document.getElementById('cmsSlug');
    var typeInput  = document.getElementById('cmsType');
    var metaInput  = document.getElementById('cmsMeta');
    var savedHint  = document.getElementById('cmsSavedHint');
    var statusBadge = document.getElementById('cmsStatusBadge');
    if (!slugInput) { return; }
    var slugTouched = (slugInput.value.trim() !== '');

    function slugify(s) {
        return String(s || '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    }
    titleInput.addEventListener('input', function () {
        if (!slugTouched && !slugInput.readOnly) { slugInput.value = slugify(titleInput.value); }
        markDirty();
    });
    slugInput.addEventListener('input', function () { slugTouched = true; markDirty(); });

    // On a NEW page, switching the type re-seeds the starter blocks — but only
    // when the user hasn't authored content yet (avoid clobbering real work).
    typeInput.addEventListener('change', function () {
        if (BE && BE.setPageType) { BE.setPageType(typeInput.value); }
        if (STATE.isNew && BE && BE.isPristine()) {
            BE.seedFromPreset(typeInput.value);
        }
        markDirty();
    });
    metaInput.addEventListener('input', markDirty);

    /* ================= save flow ================= */
    var saveBtn = document.getElementById('cmsSaveBtn');
    var dirty = false;
    var autosaveTimer = null;
    var saving = false;

    function markDirty() {
        dirty = true;
        if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint cms-editbar-hint-dirty'; }
        clearTimeout(autosaveTimer);
        if (STATE.canEdit) {
            autosaveTimer = setTimeout(function () { doSave(true); }, 3000);
        }
    }

    function doSave(isAuto) {
        if (saving || !STATE.canEdit || !BE) { return; }
        var title = titleInput.value.trim();
        if (title === '') {
            if (!isAuto) { toast('A page title is required.', 'error'); }
            return;
        }
        // a JSON-fallback block with broken JSON blocks save
        if (BE.hasJsonError()) {
            if (!isAuto) { toast('Fix the invalid JSON in a block before saving.', 'error'); }
            return;
        }

        saving = true;
        clearTimeout(autosaveTimer);
        if (saveBtn) { saveBtn.disabled = true; }
        if (savedHint) { savedHint.innerHTML = '<span class="cms-spin"></span> Saving…'; savedHint.className = 'cms-editbar-hint'; }

        var params = {
            page_id: STATE.pageId,
            title: title,
            slug: slugInput.value.trim(),
            type: typeInput.value,
            meta_description: metaInput.value.trim(),
            blocks: JSON.stringify(BE.serialize())
        };

        post('savepage', params).then(function (res) {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            if (!res || !res.ok) {
                if (savedHint) { savedHint.textContent = ''; }
                toast((res && res.error) || 'Save failed.', 'error');
                return;
            }
            dirty = false;
            // capture id for a freshly-created page so later saves are updates
            if (res.is_new && res.page_id) {
                STATE.pageId = res.page_id;
                STATE.isNew = false;
                params_pageId_synced();
            }
            if (res.slug) { slugInput.value = res.slug; slugTouched = true; }
            if (savedHint) { savedHint.textContent = 'Saved ' + new Date().toLocaleTimeString(); savedHint.className = 'cms-editbar-hint cms-editbar-hint-saved'; }
            toast('Page saved.', 'ok');
            // Refresh the in-context preview so it reflects the just-saved draft.
            refreshPreview();
        }).catch(function () {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            dirty = true;
            if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint'; }
            toast('Network error.', 'error');
        });
    }

    // Declared up front so params_pageId_synced (called on first save of a new
    // page) can reference it without hitting the var-hoisting undefined window.
    var previewToggle = document.getElementById('cmsPreviewToggle');

    // After a new page gets its id, enable Preview/Publish and update URL.
    function params_pageId_synced() {
        var openLink = document.getElementById('cmsPreviewOpen');
        if (openLink) { openLink.href = UIR + 'Cms/preview/' + STATE.pageId; }
        var pub = document.getElementById('cmsPubBtn');
        if (pub) { pub.disabled = false; }
        // Preview is now possible — enable the toggle + clear its "save first" hint.
        if (previewToggle) {
            previewToggle.disabled = false;
            previewToggle.removeAttribute('data-needsave');
            previewToggle.removeAttribute('data-tip');
        }
        try {
            history.replaceState(null, '', UIR + 'Cms/edit/' + STATE.pageId);
        } catch (e) {}
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () { doSave(false); });
    }

    // Warn on unload with unsaved changes.
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ================= publish / unpublish ================= */
    var pubBtn = document.getElementById('cmsPubBtn');
    if (pubBtn) {
        pubBtn.addEventListener('click', function () {
            if (STATE.pageId <= 0) { toast('Save the page first.', 'error'); return; }
            var publishing = (pubBtn.getAttribute('data-status') !== 'published');
            pubBtn.disabled = true;
            post(publishing ? 'publish' : 'unpublish', { page_id: STATE.pageId }).then(function (res) {
                pubBtn.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Action failed.', 'error'); return; }
                var nowPub = (res.status === 'published');
                pubBtn.setAttribute('data-status', nowPub ? 'published' : 'draft');
                pubBtn.innerHTML = nowPub ? '<i class="fas fa-eye-slash"></i> Unpublish' : '<i class="fas fa-globe"></i> Publish';
                if (statusBadge) {
                    statusBadge.className = 'cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                    statusBadge.textContent = nowPub ? 'Published' : 'Draft';
                }
                toast(nowPub ? 'Page published.' : 'Page unpublished.', 'ok');
                refreshPreview();
            }).catch(function () { pubBtn.disabled = false; toast('Network error.', 'error'); });
        });
    }

    /* ================= delete page ================= */
    var deleteBtn = document.getElementById('cmsDeleteBtn');
    if (deleteBtn && BE) {
        deleteBtn.addEventListener('click', function () {
            BE.confirmDialog('Delete page', 'Delete this page and all of its blocks? This cannot be undone.', 'Delete', function () {
                var okEl = BE.confirmOkEl();
                if (okEl) { okEl.disabled = true; }
                post('deletepage', { page_id: STATE.pageId }).then(function (res) {
                    if (okEl) { okEl.disabled = false; }
                    BE.closeConfirm();
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    dirty = false;
                    window.location.href = UIR + 'Cms/index';
                }).catch(function () { if (okEl) { okEl.disabled = false; } toast('Network error.', 'error'); });
            });
        });
    }

    /* ================= in-context preview pane ================= */
    previewToggle = document.getElementById('cmsPreviewToggle');
    var previewPane   = document.getElementById('cmsPreviewPane');
    var previewIframe = document.getElementById('cmsPreviewIframe');
    var previewWrap   = document.getElementById('cmsPreviewFrameWrap');
    var previewClose  = document.getElementById('cmsPreviewClose');
    var previewRefresh = document.getElementById('cmsPreviewRefresh');
    var editorGrid    = document.getElementById('cmsEditorGrid');
    var previewLoaded = false;

    function previewUrl() {
        return UIR + 'Cms/preview/' + STATE.pageId + '?_t=' + Date.now();
    }
    function previewOpen() { return previewPane && previewPane.classList.contains('cms-preview-open'); }

    function loadPreview() {
        if (STATE.pageId <= 0 || !previewIframe) { return; }
        previewIframe.src = previewUrl();
        previewLoaded = true;
    }
    // Only reload when the pane is open (or already loaded) — avoids fetching a
    // preview the editor never opened.
    function refreshPreview() {
        if (STATE.pageId <= 0 || !previewIframe) { return; }
        if (previewOpen() || previewLoaded) { loadPreview(); }
    }

    function openPreview() {
        if (STATE.pageId <= 0) { toast('Save the page first to preview it.', 'error'); return; }
        if (previewPane) { previewPane.classList.add('cms-preview-open'); previewPane.setAttribute('aria-hidden', 'false'); }
        if (editorGrid) { editorGrid.classList.add('cms-preview-active'); }
        if (previewToggle) { previewToggle.classList.add('cms-btn-active'); }
        if (!previewLoaded) { loadPreview(); }
    }
    function closePreview() {
        if (previewPane) { previewPane.classList.remove('cms-preview-open'); previewPane.setAttribute('aria-hidden', 'true'); }
        if (editorGrid) { editorGrid.classList.remove('cms-preview-active'); }
        if (previewToggle) { previewToggle.classList.remove('cms-btn-active'); }
    }

    if (previewToggle) {
        previewToggle.addEventListener('click', function () {
            if (previewToggle.disabled) { return; }
            if (previewOpen()) { closePreview(); } else { openPreview(); }
        });
    }
    if (previewClose) { previewClose.addEventListener('click', closePreview); }
    if (previewRefresh) { previewRefresh.addEventListener('click', function () { loadPreview(); }); }

    // Desktop / Mobile device-width toggle.
    Array.prototype.forEach.call(document.querySelectorAll('.cms-devbtn'), function (btn) {
        btn.addEventListener('click', function () {
            var dev = btn.getAttribute('data-device') || 'desktop';
            Array.prototype.forEach.call(document.querySelectorAll('.cms-devbtn'), function (b) {
                b.classList.toggle('cms-devbtn-active', b === btn);
            });
            if (previewWrap) { previewWrap.setAttribute('data-device', dev); }
        });
    });

    /* ================= boot the shared block engine ================= */
    if (BE) {
        BE.init({
            blocks:    <?= json_encode($blocks, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            catalog:   <?= json_encode($catalog, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            labels:    <?= json_encode($catalogLabels, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageTypes: <?= json_encode($pageTypes, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            blockAllow: <?= json_encode($blockAllow, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageType:  typeInput ? typeInput.value : <?= json_encode($pType, JSON_HEX_TAG) ?>,
            canEdit:   STATE.canEdit,
            onDirty:   markDirty
        });
        // For a brand-new page that arrived with no blocks, seed from the type preset.
        if (STATE.isNew && BE.isEmpty()) {
            BE.seedFromPreset(typeInput.value);
        }
    }
})();
</script>
