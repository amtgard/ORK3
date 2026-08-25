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
$allTags = isset($AllTags) && is_array($AllTags) ? $AllTags : array();
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();
// Active scope query ('&scope=k:5' or '') threaded onto every intra-admin link
// so breadcrumbs + post-save redirects stay in the current org scope.
$scopeQ  = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';

// Page-type enum the meta form offers (mirror controller _pageTypes()).
$pageTypes = isset($PageTypes) && is_array($PageTypes) ? $PageTypes : array(
    array('type' => 'composed',   'label' => 'Landing page'),
    array('type' => 'article',    'label' => 'Article'),
    array('type' => 'media',      'label' => 'Photo gallery'),
    array('type' => 'resource',   'label' => 'Documents & downloads'),
    array('type' => 'blog_index', 'label' => 'News index'),
    array('type' => 'dynamic',    'label' => 'Live ORK data'),
);

// type key => one-line author-facing description (CmsBlockRegistry::PageTypeDefs).
// Rendered under the Type select and swapped on change, so the author reads what
// a type MAKES at the moment they pick it.
$pageTypeDesc = array();
foreach ($pageTypes as $pt) {
    if (!empty($pt['description'])) {
        $pageTypeDesc[(string)$pt['type']] = (string)$pt['description'];
    }
}

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
$pType        = ($isNew && $urlType !== '') ? $urlType : (string)($page['type'] ?? 'composed');
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

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'pages';
$cmsTitle   = $isNew ? 'New Page' : 'Edit: ' . $pTitle;
$cmsCrumbs  = array(
    array('label' => 'Dashboard', 'href' => UIR . 'Cms/dashboard' . $scopeQ),
    array('label' => 'Pages',           'href' => UIR . 'Cms/index' . $scopeQ),
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
        <select class="cms-select" id="cmsType" aria-describedby="cmsTypeHelp">
            <?php foreach ($pageTypes as $pt):
                $sel = ((string)$pt['type'] === $pType) ? ' selected' : '';
            ?>
                <option value="<?= $h($pt['type']) ?>"<?= $sel ?>><?= $h($pt['label']) ?></option>
            <?php endforeach; ?>
        </select>
        <div class="cms-help" id="cmsTypeHelp"><?= $h(isset($pageTypeDesc[$pType]) ? $pageTypeDesc[$pType] : '') ?></div>
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
            <span class="ork-badge cms-badge cms-badge-<?= $isPublished ? 'published' : 'draft' ?>" id="cmsStatusBadge">
                <?= $isPublished ? 'Published' : 'Draft' ?>
            </span>
            <?php if ($pIsSystem): ?><span class="ork-badge cms-badge cms-badge-system">System</span><?php endif; ?>
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
            <?php /* E2: no longer gated on "save first" — the pane renders the
                     editor's CURRENT blocks via CmsAjax/previewblocks, so a page
                     that has never been saved previews too. */ ?>
            <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="cmsPreviewToggle" data-tip="Show or hide the live preview">
                <i class="fas fa-eye"></i> Preview
            </button>
        </div>
    </div>

    <?php if ($canEdit && !$canPublish): ?>
    <div class="cms-note" role="note">
        <i class="fas fa-lock"></i>
        <span>Saved as a draft &mdash; a monarch or regent needs to publish this page before it's visible to the public.</span>
    </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
    <div class="cms-note cms-note-live" role="note" id="cmsPublishedLiveNote"<?= $isPublished ? '' : ' style="display:none;"' ?>>
        <i class="fas fa-exclamation-triangle"></i>
        <span>This page is <strong>published</strong> &mdash; any edit you save goes live to the public immediately. Autosave is turned off here so you can review before saving.</span>
    </div>
    <?php endif; ?>

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
        $beHeading = 'Blocks';
        include DIR_TEMPLATE . 'default/cms/_block_editor.tpl';
        ?>

        <?php /* ============ IN-CONTEXT PREVIEW PANE ============ */ ?>
        <aside class="cms-preview-pane" id="cmsPreviewPane" aria-hidden="true">
            <div class="cms-preview-pane-head">
                <span class="cms-preview-pane-title"><i class="fas fa-eye"></i> Live preview</span>
                <div class="cms-preview-devtoggle" role="group" aria-label="Preview width">
                    <button type="button" class="cms-devbtn cms-devbtn-active" data-device="desktop" data-tip="Desktop width" aria-label="Desktop width"><i class="fas fa-desktop" aria-hidden="true"></i></button>
                    <button type="button" class="cms-devbtn" data-device="mobile" data-tip="Mobile width" aria-label="Mobile width"><i class="fas fa-mobile-alt" aria-hidden="true"></i></button>
                </div>
                <span class="cms-spacer"></span>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsPreviewRefresh" data-tip="Rebuild the preview now" aria-label="Rebuild the preview now"><i class="fas fa-redo" aria-hidden="true"></i></button>
                <a class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsPreviewOpen" href="<?= $pageId > 0 ? UIR . 'Cms/preview/' . $pageId . $scopeQ : '#' ?>" target="_blank" rel="noopener" data-tip="Open the last SAVED version in a new tab &mdash; the pane beside you shows your unsaved edits" aria-label="Open the saved page in a new tab"><i class="fas fa-external-link-alt" aria-hidden="true"></i></a>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-preview-close" id="cmsPreviewClose" data-tip="Close preview" aria-label="Close preview"><i class="fas fa-times" aria-hidden="true"></i></button>
            </div>
            <div class="cms-preview-note" id="cmsPreviewNote" role="status" aria-live="polite" style="display:none;"></div>
            <div class="cms-preview-pane-body">
                <div class="cms-preview-frame-wrap" id="cmsPreviewFrameWrap" data-device="desktop">
                    <?php /* sandbox WITHOUT allow-same-origin, deliberately: this frame is fed
                             author-written content through srcdoc, and srcdoc otherwise inherits
                             the admin's own origin — one sanitizer bypass away from a script
                             running with the editing officer's session. The null origin the
                             sandbox forces takes that reach away. allow-scripts stays because the
                             preview has to behave like the real page: frontdoor.js runs the hero
                             carousels and the gallery lightboxes, and a preview that cannot
                             animate is not a preview. Scripts alone cannot reach the session; the
                             two tokens are only dangerous together, which is why allow-same-origin
                             must never be added back. Keep this in step with Cms_editpost.tpl. */ ?>
                    <iframe class="cms-preview-iframe" id="cmsPreviewIframe" title="Page preview"
                        sandbox="allow-scripts" src="about:blank"></iframe>
                </div>
            </div>
        </aside>

    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<script>
(function () {
    'use strict';

    var UIR  = window.CMS_UIR;

    // Server state injected safely. The block engine lives in the shared
    // cms/_block_editor.tpl partial; this script owns the page META form +
    // the save / publish / delete flow, wiring into window.CmsBlockEditor.
    var STATE = {
        pageId:  <?= (int)$pageId ?>,
        isNew:   <?= $isNew ? 'true' : 'false' ?>,
        // Optimistic-concurrency token = the row's updated_at at load. Sent
        // as base_version on every save; the server _fails (status 12) if a newer
        // stored version exists, and echoes the fresh version back on success.
        version: <?= json_encode((string)($page['updated_at'] ?? '')) ?>,
        published: <?= $isPublished ? 'true' : 'false' ?>,
        origSlug: <?= json_encode($pSlug) ?>,
        slugWarned: false,
        canEdit:    <?= $canEdit ? 'true' : 'false' ?>,
        canPublish: <?= $canPublish ? 'true' : 'false' ?>
    };

    var BE = window.CmsBlockEditor;

    /* ---- toast (delegate to the shared engine) ---- */
    function toast(msg, kind) { if (BE) { BE.toast(msg, kind); } }

    /* ---- POST helper (shared: CmsAdmin.post — same CSRF header + scope append,
           and like this host's old copy it RESOLVES on a non-OK status; the block
           engine's own post() is the one that throws, deliberately). ---- */
    var post = CmsAdmin.post;

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
    slugInput.addEventListener('input', function () { slugTouched = true; maybeWarnSlugChange(); markDirty(); });

    // Changing the slug of an already-published page can break inbound links
    // (the server-side reserved-slug guard + 301 redirect is the other lane). Warn
    // once, inline (never a native dialog), so the author makes the change knowingly.
    function maybeWarnSlugChange() {
        if (STATE.slugWarned || !STATE.published) { return; }
        if (slugInput.value.trim() === STATE.origSlug) { return; }
        STATE.slugWarned = true;
        toast('Heads up: this page is published — changing its slug can break existing links to it until redirects catch up.', 'error');
    }

    // On a NEW page, switching the type re-seeds the starter blocks — but only
    // when the user hasn't authored content yet (avoid clobbering real work).
    // One line saying what the chosen type MAKES, swapped as the choice changes.
    var typeHelp = document.getElementById('cmsTypeHelp');
    var TYPE_DESC = <?= json_encode($pageTypeDesc, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
    function paintTypeHelp() {
        if (typeHelp) { typeHelp.textContent = TYPE_DESC[typeInput.value] || ''; }
    }

    typeInput.addEventListener('change', function () {
        paintTypeHelp();
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
    var saving = false;
    // The block engine fires onDirty as it paints the initial starter scaffold
    // (BE.init + seedFromPreset below). Stay un-armed until that first render is done
    // so a brand-new, untouched page never triggers the leave-site prompt.
    var booted = false;

    // Debounced autosave (shared: CmsAdmin.autosave).
    // Never autosave an already-published page — a save goes live instantly,
    // so the author must save deliberately. STATE.published flips true on publish.
    var autosaveTimer = CmsAdmin.autosave({
        delay: 3000,
        enabled: function () { return STATE.canEdit && !STATE.published; },
        save: function () { doSave(true); }
    });

    function markDirty() {
        if (!booted) { return; }
        dirty = true;
        if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint cms-editbar-hint-dirty'; }
        autosaveTimer.schedule();
        // Keep the preview following the typing. Debounced inside the pane,
        // and a no-op while the pane has never been opened.
        if (preview) { preview.schedule(); }
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
            // Jump to + flash the offending block (and name it) rather than a
            // vague toast the author can't act on.
            if (!isAuto && BE.focusFirstError) { BE.focusFirstError(); }
            else if (!isAuto) { toast('Fix the invalid JSON in a block before saving.', 'error'); }
            return;
        }

        saving = true;
        autosaveTimer.cancel();
        if (saveBtn) { saveBtn.disabled = true; }
        if (savedHint) { savedHint.innerHTML = '<span class="cms-spin"></span> Saving…'; savedHint.className = 'cms-editbar-hint'; }

        var params = {
            page_id: STATE.pageId,
            title: title,
            slug: slugInput.value.trim(),
            type: typeInput.value,
            meta_description: metaInput.value.trim(),
            base_version: STATE.version || '',   // C15 optimistic-concurrency token
            blocks: JSON.stringify(BE.serialize())
        };

        // Watchdog: a request that never settles (stalled proxy/backend) would
        // otherwise leave Save disabled forever. Don't abort the save — just
        // give the UI back so the author can retry.
        var saveWatchdog = window.setTimeout(function () {
            saveWatchdog = 0;
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            dirty = true;
            if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint'; }
            toast('Still saving — the server has not responded.', 'error');
        }, 30000);

        post('savepage', params).then(function (res) {
            if (saveWatchdog) { window.clearTimeout(saveWatchdog); saveWatchdog = 0; }
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            // Concurrent-edit conflict — the stored row is newer than our base.
            if (res && (res.status === 12 || res.code === 12)) {
                if (savedHint) { savedHint.textContent = ''; }
                handleSaveConflict(res, isAuto);
                return;
            }
            if (!res || !res.ok) {
                if (savedHint) { savedHint.textContent = ''; }
                toast((res && res.error) || 'Save failed.', 'error');
                return;
            }
            dirty = false;
            // Adopt the fresh version the server echoes so the NEXT save's
            // base_version matches and doesn't spuriously conflict.
            if (res.version) { STATE.version = res.version; }
            // capture id for a freshly-created page so later saves are updates
            if (res.is_new && res.page_id) {
                STATE.pageId = res.page_id;
                STATE.isNew = false;
                pageIdSynced();
            }
            if (res.slug) { slugInput.value = res.slug; slugTouched = true; }
            STATE.origSlug = slugInput.value.trim();
            STATE.published = (STATE.published || res.status === 'published');
            if (savedHint) { savedHint.textContent = 'Saved ' + new Date().toLocaleTimeString(); savedHint.className = 'cms-editbar-hint cms-editbar-hint-saved'; }
            toast('Page saved.', 'ok');
            // Refresh the in-context preview so it reflects the just-saved draft.
            refreshPreview();
        }).catch(function () {
            if (saveWatchdog) { window.clearTimeout(saveWatchdog); saveWatchdog = 0; }
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            dirty = true;
            if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint'; }
            toast('Network error.', 'error');
        });
    }

    // A save was rejected because the stored row is newer than our base
    // version (someone else saved meanwhile). Offer a clear, non-native
    // reload-or-overwrite choice. On autosave we stay quiet (just a hint) so the
    // modal never ambushes the author mid-typing — their next manual save surfaces it.
    function handleSaveConflict(res, isAuto) {
        dirty = true;
        if (savedHint) { savedHint.textContent = 'Save blocked — this page changed elsewhere.'; savedHint.className = 'cms-editbar-hint cms-editbar-hint-dirty'; }
        if (isAuto) { return; }
        if (!BE || !BE.confirmDialog) {
            toast('This page was changed elsewhere. Reload before saving to avoid losing their changes.', 'error');
            return;
        }
        BE.confirmDialog(
            'This page changed elsewhere',
            'Someone else saved this page since you opened it. Cancel and reload to keep their version (your unsaved edits will be lost), or overwrite it with your version.',
            'Overwrite with mine',
            function () {
                BE.closeConfirm();
                // Adopt the server's fresh version so the retry passes the check,
                // then re-save — deliberately overwriting the other edit.
                if (res && res.version) { STATE.version = res.version; }
                doSave(false);
            }
        );
    }

    // After a new page gets its id, enable Preview/Publish and update URL.
    function pageIdSynced() {
        var openLink = document.getElementById('cmsPreviewOpen');
        if (openLink) { openLink.href = UIR + 'Cms/preview/' + STATE.pageId + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''); }
        var pub = document.getElementById('cmsPubBtn');
        if (pub) { pub.disabled = false; }
        try {
            history.replaceState(null, '', UIR + 'Cms/edit/' + STATE.pageId + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''));
        } catch (e) {}
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () { doSave(false); });
    }

    // Warn on unload with unsaved changes (shared: CmsAdmin.guardUnsaved).
    CmsAdmin.guardUnsaved(function () { return dirty; });

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
                STATE.published = nowPub;
                if (res.version) { STATE.version = res.version; }
                pubBtn.setAttribute('data-status', nowPub ? 'published' : 'draft');
                pubBtn.innerHTML = nowPub ? '<i class="fas fa-eye-slash"></i> Unpublish' : '<i class="fas fa-globe"></i> Publish';
                if (statusBadge) {
                    statusBadge.className = 'ork-badge cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                    statusBadge.textContent = nowPub ? 'Published' : 'Draft';
                }
                // Reflect the live-edits warning (and autosave state follows STATE.published).
                var liveNote = document.getElementById('cmsPublishedLiveNote');
                if (liveNote) { liveNote.style.display = nowPub ? '' : 'none'; }
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
                BE.confirmBusy(true);
                post('deletepage', { page_id: STATE.pageId }).then(function (res) {
                    BE.confirmBusy(false);
                    BE.closeConfirm();
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    dirty = false;
                    window.location.href = UIR + 'Cms/index' + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
                }).catch(function () { BE.confirmBusy(false); toast('Network error.', 'error'); });
            });
        });
    }

    /* ================= in-context preview pane (shared: CmsAdmin.previewPane) ====
       Pane/iframe/device-button wiring is identical between the two editors; only
       the preview URL, the "is it saved yet" test and the toast copy differ. ==== */
    var preview = CmsAdmin.previewPane({
        // The pane shows what is in the EDITOR, not what is in the database.
        // CmsAjax/previewblocks runs the posted block list through the identical
        // validation a save runs (canonical type allowlist, then CmsSanitizer via
        // CmsPage::_normalizeBlocks) and returns a rendered page document.
        live: function () {
            if (!BE) { return Promise.reject(new Error('Preview is unavailable on this page.')); }
            return post('previewblocks', {
                owner_type: 'page',
                owner_id:   STATE.pageId,
                title:      titleInput.value.trim(),
                blocks:     JSON.stringify(BE.serialize())
            }).then(function (res) {
                if (!res || !res.ok || typeof res.html !== 'string') {
                    throw new Error((res && res.error)
                        || 'Preview could not update. Your edits are safe \u2014 they are still in the form.');
                }
                return res.html;
            });
        },
        // Nothing to save first any more; an unsaved page previews fine.
        ready: function () { return true; },
        openPrefKey: 'cms_preview_open_page'
    });
    // Declared as a hoisted function so the save/publish handlers above can call it.
    function refreshPreview() { preview.refresh(); }

    /* ================= boot the shared block engine ================= */
    if (BE) {
        BE.init({
            blocks:    <?= json_encode($blocks, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            catalog:   <?= json_encode($catalog, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            labels:    <?= json_encode($catalogLabels, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageTypes: <?= json_encode($pageTypes, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            blockAllow: <?= json_encode($blockAllow, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            tags:      <?= json_encode($allTags, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageType:  typeInput ? typeInput.value : <?= json_encode($pType, JSON_HEX_TAG) ?>,
            onDirty:   markDirty
        });
        // For a brand-new page that arrived with no blocks, seed from the type preset.
        if (STATE.isNew && BE.isEmpty()) {
            BE.seedFromPreset(typeInput.value);
        }
    }
    // Initial scaffold render is complete — from here on, onDirty/markDirty
    // reflect genuine user interaction and may arm the unsaved-changes guard.
    booted = true;
})();
</script>
