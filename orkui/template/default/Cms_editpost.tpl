<?php
/**
 * Cms_editpost.tpl — CMS blog-post editor. PLAIN PHP (extract()+include), NEVER Smarty.
 *
 * Reuses the SHARED block-body editor (cms/_block_editor.tpl) — the SAME engine
 * the page editor uses — for the post BODY, and adds a post-specific META form
 * (title→auto-slug, excerpt, hero image via the shared media picker, tags as a
 * comma input, status + Save/Publish/Delete). Saves via CmsAjax/savepost.
 *
 * Receives (from Controller_Cms::editpost):
 *   $Post         ['post_id','slug','title','excerpt','status','published_at',
 *                  'hero_media_id','author_id','author_name','scope_type',
 *                  'scope_id','tags'=>[['name','slug'],...]]
 *   $Blocks       list of ['id','type','enabled','order','source','fields'=>[...]]
 *   $IsNew        bool
 *   $HeroRef      media-ref ['media_id','src','thumb','alt',...] or null
 *   $BlockCatalog list of ['type','label','group','dynamic','available']
 *   $Caps         ['create','edit','publish','delete','media','nav','roles' => bool]
 *   UIR, HTTP_TEMPLATE (constants)
 *
 * Posts to CmsAjax: savepost, publishpost, unpublishpost, deletepost, mediaupload, medialist.
 */

$post    = isset($Post) && is_array($Post) ? $Post : array();
$blocks  = isset($Blocks) && is_array($Blocks) ? $Blocks : array();
$isNew   = !empty($IsNew);
$catalog = isset($BlockCatalog) && is_array($BlockCatalog) ? $BlockCatalog : array();
$blockAllow = isset($BlockAllow) && is_array($BlockAllow) ? $BlockAllow : array();
$allTags = isset($AllTags) && is_array($AllTags) ? $AllTags : array();
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();
$heroRef = (isset($HeroRef) && is_array($HeroRef)) ? $HeroRef : null;
// Active scope query ('&scope=k:5' or '') threaded onto every intra-admin link
// so breadcrumbs + post-save redirects stay in the current org scope.
$scopeQ  = isset($CmsScopeQuery) ? (string)$CmsScopeQuery : '';

$postId      = (int)($post['post_id'] ?? 0);
$pTitle      = (string)($post['title'] ?? '');
$pSlug       = (string)($post['slug'] ?? '');
$pExcerpt    = (string)($post['excerpt'] ?? '');
$pStatus     = (string)($post['status'] ?? 'draft');
$pAuthor     = trim((string)($post['author_name'] ?? ''));
$isPublished = ($pStatus === 'published');

$tags = (isset($post['tags']) && is_array($post['tags'])) ? $post['tags'] : array();
$tagNames = array();
foreach ($tags as $tg) {
    $n = trim((string)($tg['name'] ?? ''));
    if ($n !== '') {
        $tagNames[] = $n;
    }
}
$tagStr = implode(', ', $tagNames);

$canEdit    = !empty($caps['edit']) || !empty($caps['create']);
$canPublish = !empty($caps['publish']);
$canDelete  = !empty($caps['delete']) && !$isNew;

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Catalog label map (for the shared engine).
$catalogLabels = array();
foreach ($catalog as $c) {
    $catalogLabels[$c['type']] = $c['label'];
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'posts';
$cmsTitle   = $isNew ? 'New Post' : 'Edit: ' . $pTitle;
$cmsCrumbs  = array(
    array('label' => 'Dashboard', 'href' => UIR . 'Cms/dashboard' . $scopeQ),
    array('label' => 'Posts',           'href' => UIR . 'Cms/posts' . $scopeQ),
    array('label' => $isNew ? 'New Post' : $pTitle),
);
$cmsActions = '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <?php /* ============ STICKY EDITOR ACTION BAR ============ */ ?>
    <div class="cms-editbar" id="cmsEditBar">
        <div class="cms-editbar-status">
            <span class="cms-badge cms-badge-<?= $isPublished ? 'published' : 'draft' ?>" id="cmsStatusBadge">
                <?= $isPublished ? 'Published' : 'Draft' ?>
            </span>
            <?php if ($pAuthor !== ''): ?><span class="cms-editbar-author">By <?= $h($pAuthor) ?></span><?php endif; ?>
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
            <button type="button" class="cms-btn cms-btn-ghost cms-btn-sm" id="cmsPreviewToggle"<?= $postId > 0 ? '' : ' disabled data-needsave="1" data-tip="Save the post first to preview it."' ?>>
                <i class="fas fa-eye"></i> Preview
            </button>
        </div>
    </div>

    <div class="cms-editor cms-editor-haspreview" id="cmsEditorGrid">

        <?php /* ---- Meta panel ---- */ ?>
        <div class="cms-meta-panel">
            <h2>Post settings</h2>

            <div class="cms-field">
                <label class="cms-label" for="cmsTitle">Title</label>
                <input type="text" class="cms-input" id="cmsTitle" value="<?= $h($pTitle) ?>" placeholder="Post title">
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsSlug">Slug</label>
                <input type="text" class="cms-input" id="cmsSlug" value="<?= $h($pSlug) ?>" placeholder="post-slug">
                <div class="cms-help">URL path. Auto-filled from the title until you edit it.</div>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsExcerpt">Excerpt</label>
                <textarea class="cms-textarea" id="cmsExcerpt" placeholder="Short summary shown in lists and previews." style="min-height:70px;"><?= $h($pExcerpt) ?></textarea>
            </div>

            <div class="cms-field">
                <label class="cms-label">Hero image</label>
                <div class="cms-media-field" id="cmsHeroField">
                    <?php if ($heroRef && !empty($heroRef['thumb'])): ?>
                        <img class="cms-media-thumb" id="cmsHeroThumb" src="<?= $h($heroRef['thumb'] ?: $heroRef['src']) ?>" alt="">
                    <?php else: ?>
                        <div class="cms-media-thumb cms-empty-thumb" id="cmsHeroThumb"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div class="cms-media-meta">
                        <div class="cms-media-name" id="cmsHeroName">
                            <?php if ($heroRef): ?><?= $h($heroRef['alt'] ?? 'Selected image') ?><?php else: ?><span class="cms-muted">No image selected</span><?php endif; ?>
                        </div>
                        <div style="margin-top:6px;">
                            <button type="button" class="cms-btn cms-btn-sm" id="cmsHeroChoose"><i class="fas fa-image"></i> Choose image</button>
                            <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsHeroClear" style="margin-left:6px;<?= $heroRef ? '' : 'display:none;' ?>">Clear</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsTags">Tags</label>
                <input type="text" class="cms-input" id="cmsTags" value="<?= $h($tagStr) ?>" placeholder="news, events, tournament">
                <div class="cms-help">Comma-separated. New tags are created automatically.</div>
            </div>

            <?php if ($canDelete): ?>
            <div class="cms-action-row" style="margin-top:14px;">
                <button type="button" class="cms-btn cms-btn-danger" id="cmsDeleteBtn"><i class="fas fa-trash"></i> Delete post</button>
            </div>
            <?php endif; ?>
        </div>

        <?php
        /* ---- Body blocks + modals + block engine: SHARED partial ---- */
        $beBlocks    = $blocks;
        $beCatalog   = $catalog;
        $beLabels    = $catalogLabels;
        $bePageTypes = array(); // posts have no page-type presets
        $beCanEdit   = $canEdit;
        $beHeading   = 'Post body';
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
                <a class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsPreviewOpen" href="<?= $postId > 0 ? UIR . 'Cms/previewpost/' . $postId . $scopeQ : '#' ?>" target="_blank" rel="noopener" data-tip="Open in new tab"><i class="fas fa-external-link-alt"></i></a>
                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-preview-close" id="cmsPreviewClose" data-tip="Close preview"><i class="fas fa-times"></i></button>
            </div>
            <div class="cms-preview-note cms-muted">Preview shows the current draft.</div>
            <div class="cms-preview-pane-body">
                <div class="cms-preview-frame-wrap" id="cmsPreviewFrameWrap" data-device="desktop">
                    <iframe class="cms-preview-iframe" id="cmsPreviewIframe" title="Post preview" src="about:blank"></iframe>
                </div>
            </div>
        </aside>

    </div>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<script>
(function () {
    'use strict';

    var UIR = <?= json_encode(UIR) ?>;

    var STATE = {
        postId:  <?= (int)$postId ?>,
        isNew:   <?= $isNew ? 'true' : 'false' ?>,
        heroId:  <?= (int)($post['hero_media_id'] ?? 0) ?>,
        slug:    <?= json_encode($pSlug) ?>,
        // C15: optimistic-concurrency token = the row's updated_at at load. Sent as
        // base_version on save; the server _fails (status 12) on a stale base and
        // echoes the fresh version back on success.
        version: <?= json_encode((string)($post['updated_at'] ?? '')) ?>,
        canEdit:    <?= $canEdit ? 'true' : 'false' ?>,
        canPublish: <?= $canPublish ? 'true' : 'false' ?>
    };

    var BE = window.CmsBlockEditor;

    function esc(v) {
        return String(v == null ? '' : v)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function toast(msg, kind) { if (BE) { BE.toast(msg, kind); } }

    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(UIR + 'CmsAjax/' + endpoint + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''), {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': (window.CMS_CSRF || '') },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ================= meta form ================= */
    var titleInput = document.getElementById('cmsTitle');
    var slugInput  = document.getElementById('cmsSlug');
    var excerptInput = document.getElementById('cmsExcerpt');
    var tagsInput  = document.getElementById('cmsTags');
    var savedHint  = document.getElementById('cmsSavedHint');
    var statusBadge = document.getElementById('cmsStatusBadge');
    var slugTouched = slugInput ? (slugInput.value.trim() !== '') : false;
    if (!titleInput || !slugInput) { return; }

    function slugify(s) {
        return String(s || '').toLowerCase()
            .replace(/[^a-z0-9]+/g, '-').replace(/-+/g, '-').replace(/^-|-$/g, '');
    }
    titleInput.addEventListener('input', function () {
        if (!slugTouched) { slugInput.value = slugify(titleInput.value); }
        markDirty();
    });
    slugInput.addEventListener('input', function () { slugTouched = true; markDirty(); });
    excerptInput.addEventListener('input', markDirty);
    tagsInput.addEventListener('input', markDirty);

    /* ================= hero image (shared media picker) ================= */
    var heroThumb  = document.getElementById('cmsHeroThumb');
    var heroName   = document.getElementById('cmsHeroName');
    var heroChoose = document.getElementById('cmsHeroChoose');
    var heroClear  = document.getElementById('cmsHeroClear');

    function setHero(ref) {
        STATE.heroId = (ref && ref.media_id) ? Number(ref.media_id) : 0;
        var fresh;
        if (ref && (ref.thumb || ref.src)) {
            fresh = document.createElement('img');
            fresh.className = 'cms-media-thumb';
            fresh.src = ref.thumb || ref.src;
            fresh.alt = '';
        } else {
            fresh = document.createElement('div');
            fresh.className = 'cms-media-thumb cms-empty-thumb';
            fresh.innerHTML = '<i class="fas fa-image"></i>';
        }
        fresh.id = 'cmsHeroThumb';
        heroThumb.parentNode.replaceChild(fresh, heroThumb);
        heroThumb = fresh;
        heroName.innerHTML = ref ? esc(ref.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>';
        heroClear.style.display = ref ? '' : 'none';
        markDirty();
    }
    if (heroChoose && BE) {
        heroChoose.addEventListener('click', function () {
            BE.pickMedia(function (ref) { setHero(ref); });
        });
    }
    if (heroClear) {
        heroClear.addEventListener('click', function () { setHero(null); });
    }

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
            if (!isAuto) { toast('A post title is required.', 'error'); }
            return;
        }
        if (BE.hasJsonError()) {
            // C20: jump to + name the offending block instead of a vague toast.
            if (!isAuto && BE.focusFirstError) { BE.focusFirstError(); }
            else if (!isAuto) { toast('Fix the invalid JSON in a block before saving.', 'error'); }
            return;
        }

        saving = true;
        clearTimeout(autosaveTimer);
        if (saveBtn) { saveBtn.disabled = true; }
        if (savedHint) { savedHint.innerHTML = '<span class="cms-spin"></span> Saving…'; savedHint.className = 'cms-editbar-hint'; }

        var params = {
            post_id: STATE.postId,
            title: title,
            slug: slugInput.value.trim(),
            excerpt: excerptInput.value.trim(),
            hero_media_id: STATE.heroId || 0,
            tags: tagsInput.value,
            base_version: STATE.version || '',   // C15 optimistic-concurrency token
            blocks: JSON.stringify(BE.serialize())
        };

        post('savepost', params).then(function (res) {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            // C15: concurrent-edit conflict — the stored row is newer than our base.
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
            // C15: adopt the fresh version so the NEXT save doesn't spuriously conflict.
            if (res.version) { STATE.version = res.version; }
            if (res.is_new && res.post_id) {
                STATE.postId = res.post_id;
                STATE.isNew = false;
                postIdSynced();
            }
            if (res.slug) { slugInput.value = res.slug; slugTouched = true; STATE.slug = res.slug; }
            if (res.tags && Array.isArray(res.tags)) {
                tagsInput.value = res.tags.map(function (t) { return t.name; }).join(', ');
            }
            if (savedHint) { savedHint.textContent = 'Saved ' + new Date().toLocaleTimeString(); savedHint.className = 'cms-editbar-hint cms-editbar-hint-saved'; }
            toast('Post saved.', 'ok');
            previewSlugSynced();
            refreshPreview();
        }).catch(function () {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            dirty = true;
            if (savedHint) { savedHint.textContent = 'Unsaved changes…'; savedHint.className = 'cms-editbar-hint'; }
            toast('Network error.', 'error');
        });
    }

    // C15: save rejected because the stored row is newer than our base version.
    // Non-native reload-or-overwrite choice; stays quiet on autosave.
    function handleSaveConflict(res, isAuto) {
        dirty = true;
        if (savedHint) { savedHint.textContent = 'Save blocked — this post changed elsewhere.'; savedHint.className = 'cms-editbar-hint cms-editbar-hint-dirty'; }
        if (isAuto) { return; }
        if (!BE || !BE.confirmDialog) {
            toast('This post was changed elsewhere. Reload before saving to avoid losing their changes.', 'error');
            return;
        }
        BE.confirmDialog(
            'This post changed elsewhere',
            'Someone else saved this post since you opened it. Cancel and reload to keep their version (your unsaved edits will be lost), or overwrite it with your version.',
            'Overwrite with mine',
            function () {
                BE.closeConfirm();
                if (res && res.version) { STATE.version = res.version; }
                doSave(false);
            }
        );
    }

    // Declared up front so previewSlugSynced (called on first save of a new
    // post) can reference it without hitting the var-hoisting undefined window.
    var previewToggle = document.getElementById('cmsPreviewToggle');

    // After a new post gets its id, enable Publish + update URL.
    function postIdSynced() {
        var pub = document.getElementById('cmsPubBtn');
        if (pub) { pub.disabled = false; }
        try {
            history.replaceState(null, '', UIR + 'Cms/editpost/' + STATE.postId + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''));
        } catch (e) {}
    }
    // Once the post is saved (has an id), the draft preview becomes possible.
    function previewSlugSynced() {
        if (STATE.postId <= 0) { return; }
        var openLink = document.getElementById('cmsPreviewOpen');
        if (openLink) { openLink.href = UIR + 'Cms/previewpost/' + STATE.postId + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''); }
        if (previewToggle) {
            previewToggle.disabled = false;
            previewToggle.removeAttribute('data-needsave');
            previewToggle.removeAttribute('data-tip');
        }
    }

    if (saveBtn) {
        saveBtn.addEventListener('click', function () { doSave(false); });
    }

    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ================= publish / unpublish ================= */
    var pubBtn = document.getElementById('cmsPubBtn');
    if (pubBtn) {
        pubBtn.addEventListener('click', function () {
            if (STATE.postId <= 0) { toast('Save the post first.', 'error'); return; }
            var publishing = (pubBtn.getAttribute('data-status') !== 'published');
            pubBtn.disabled = true;
            post(publishing ? 'publishpost' : 'unpublishpost', { post_id: STATE.postId }).then(function (res) {
                pubBtn.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Action failed.', 'error'); return; }
                var nowPub = (res.status === 'published');
                pubBtn.setAttribute('data-status', nowPub ? 'published' : 'draft');
                pubBtn.innerHTML = nowPub ? '<i class="fas fa-eye-slash"></i> Unpublish' : '<i class="fas fa-globe"></i> Publish';
                if (statusBadge) {
                    statusBadge.className = 'cms-badge cms-badge-' + (nowPub ? 'published' : 'draft');
                    statusBadge.textContent = nowPub ? 'Published' : 'Draft';
                }
                toast(nowPub ? 'Post published.' : 'Post unpublished.', 'ok');
                refreshPreview();
            }).catch(function () { pubBtn.disabled = false; toast('Network error.', 'error'); });
        });
    }

    /* ================= delete post ================= */
    var deleteBtn = document.getElementById('cmsDeleteBtn');
    if (deleteBtn && BE) {
        deleteBtn.addEventListener('click', function () {
            BE.confirmDialog('Delete post', 'Delete this post and all of its content blocks? This cannot be undone.', 'Delete', function () {
                var okEl = BE.confirmOkEl();
                if (okEl) { okEl.disabled = true; }
                post('deletepost', { post_id: STATE.postId }).then(function (res) {
                    if (okEl) { okEl.disabled = false; }
                    BE.closeConfirm();
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    dirty = false;
                    window.location.href = UIR + 'Cms/posts' + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
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
        return UIR + 'Cms/previewpost/' + STATE.postId + '?_t=' + Date.now() + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
    }
    function previewOpen() { return previewPane && previewPane.classList.contains('cms-preview-open'); }

    function loadPreview() {
        if (STATE.postId <= 0 || !previewIframe) { return; }
        previewIframe.src = previewUrl();
        previewLoaded = true;
    }
    function refreshPreview() {
        if (STATE.postId <= 0 || !previewIframe) { return; }
        if (previewOpen() || previewLoaded) { loadPreview(); }
    }

    function openPreview() {
        if (STATE.postId <= 0) { toast('Save the post first to preview it.', 'error'); return; }
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
            pageTypes: [],
            blockAllow: <?= json_encode($blockAllow, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            tags:      <?= json_encode($allTags, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageType:  'post',
            canEdit:   STATE.canEdit,
            onDirty:   markDirty
        });
    }
})();
</script>
