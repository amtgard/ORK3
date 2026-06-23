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
$caps    = isset($Caps) && is_array($Caps) ? $Caps : array();
$heroRef = (isset($HeroRef) && is_array($HeroRef)) ? $HeroRef : null;

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

<div class="cms-wrap">

    <div class="cms-topbar">
        <a class="cms-btn cms-btn-ghost cms-btn-sm" href="<?= UIR ?>Cms/posts"><i class="fas fa-arrow-left"></i> Posts</a>
        <h1 class="cms-title"><?= $isNew ? 'New Post' : $h('Edit: ' . $pTitle) ?></h1>
        <span class="cms-spacer"></span>
    </div>

    <div class="cms-editor">

        <?php /* ---- Meta panel ---- */ ?>
        <div class="cms-meta-panel">
            <h2>Post settings</h2>

            <div class="cms-status-row">
                Status:
                <span class="cms-badge cms-badge-<?= $isPublished ? 'published' : 'draft' ?>" id="cmsStatusBadge">
                    <?= $isPublished ? 'Published' : 'Draft' ?>
                </span>
            </div>

            <?php if ($pAuthor !== ''): ?>
            <div class="cms-help" style="margin-top:-4px;margin-bottom:10px;">By <?= $h($pAuthor) ?></div>
            <?php endif; ?>

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

            <div class="cms-action-row">
                <?php if ($canEdit): ?>
                    <button type="button" class="cms-btn cms-btn-primary" id="cmsSaveBtn"><i class="fas fa-save"></i> Save</button>
                <?php endif; ?>
                <a class="cms-btn cms-btn-ghost" id="cmsPreviewBtn" href="<?= ($pSlug !== '') ? UIR . 'Blog/post/' . $h($pSlug) : '#' ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i> Preview</a>
            </div>

            <?php if ($canPublish): ?>
            <div class="cms-action-row">
                <button type="button" class="cms-btn cms-btn-ghost" id="cmsPubBtn" data-status="<?= $isPublished ? 'published' : 'draft' ?>"<?= $isNew ? ' disabled' : '' ?>>
                    <?php if ($isPublished): ?><i class="fas fa-eye-slash"></i> Unpublish<?php else: ?><i class="fas fa-globe"></i> Publish<?php endif; ?>
                </button>
            </div>
            <?php endif; ?>

            <?php if ($canDelete): ?>
            <div class="cms-action-row">
                <button type="button" class="cms-btn cms-btn-danger" id="cmsDeleteBtn"><i class="fas fa-trash"></i> Delete post</button>
            </div>
            <?php endif; ?>

            <div class="cms-help" id="cmsSavedHint" style="margin-top:12px;"></div>
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

    </div>
</div>

<script>
(function () {
    'use strict';

    var UIR = <?= json_encode(UIR) ?>;

    var STATE = {
        postId:  <?= (int)$postId ?>,
        isNew:   <?= $isNew ? 'true' : 'false' ?>,
        heroId:  <?= (int)($post['hero_media_id'] ?? 0) ?>,
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
        return fetch(UIR + 'CmsAjax/' + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
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
    var slugTouched = (slugInput.value.trim() !== '');

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
        if (savedHint) { savedHint.textContent = 'Unsaved changes…'; }
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
            if (!isAuto) { toast('Fix the invalid JSON in a block before saving.', 'error'); }
            return;
        }

        saving = true;
        clearTimeout(autosaveTimer);
        if (saveBtn) { saveBtn.disabled = true; }
        if (savedHint) { savedHint.innerHTML = '<span class="cms-spin"></span> Saving…'; }

        var params = {
            post_id: STATE.postId,
            title: title,
            slug: slugInput.value.trim(),
            excerpt: excerptInput.value.trim(),
            hero_media_id: STATE.heroId || 0,
            tags: tagsInput.value,
            blocks: JSON.stringify(BE.serialize())
        };

        post('savepost', params).then(function (res) {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            if (!res || !res.ok) {
                if (savedHint) { savedHint.textContent = ''; }
                toast((res && res.error) || 'Save failed.', 'error');
                return;
            }
            dirty = false;
            if (res.is_new && res.post_id) {
                STATE.postId = res.post_id;
                STATE.isNew = false;
                postIdSynced();
            }
            if (res.slug) { slugInput.value = res.slug; slugTouched = true; }
            if (res.tags && Array.isArray(res.tags)) {
                tagsInput.value = res.tags.map(function (t) { return t.name; }).join(', ');
            }
            if (savedHint) { savedHint.textContent = 'Saved ' + new Date().toLocaleTimeString(); }
            toast('Post saved.', 'ok');
        }).catch(function () {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            if (savedHint) { savedHint.textContent = ''; }
            toast('Network error.', 'error');
        });
    }

    // After a new post gets its id, enable Preview/Publish and update URL.
    function postIdSynced() {
        var prev = document.getElementById('cmsPreviewBtn');
        if (prev && slugInput.value.trim() !== '') { prev.href = UIR + 'Blog/post/' + slugInput.value.trim(); }
        var pub = document.getElementById('cmsPubBtn');
        if (pub) { pub.disabled = false; }
        try {
            history.replaceState(null, '', UIR + 'Cms/editpost/' + STATE.postId);
        } catch (e) {}
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
                    window.location.href = UIR + 'Cms/posts';
                }).catch(function () { if (okEl) { okEl.disabled = false; } toast('Network error.', 'error'); });
            });
        });
    }

    /* ================= boot the shared block engine ================= */
    if (BE) {
        BE.init({
            blocks:    <?= json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            catalog:   <?= json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            labels:    <?= json_encode($catalogLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
            pageTypes: [],
            canEdit:   STATE.canEdit,
            onDirty:   markDirty
        });
    }
})();
</script>
