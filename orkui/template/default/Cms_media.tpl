<?php
/**
 * Cms_media.tpl — CMS media library.
 * PLAIN PHP (extract()+include), NEVER Smarty. Use <?php ?>/<?= ?> only.
 *
 * Receives (from Controller_Cms::media):
 *   $Media   list of media-refs: ['media_id','src','thumb','alt','filename','created_at', ...]
 *   $Search  current search string
 *   $Caps    ['create','edit','publish','delete','media','nav','roles' => bool]
 *   UIR, HTTP_TEMPLATE (constants)
 *
 * Upload mirrors the block-editor media picker: FileReader → base64 data URI →
 * CmsAjax/mediaupload (the same endpoint the picker uses).
 */

$media  = isset($Media) && is_array($Media) ? $Media : array();
$caps   = isset($Caps) && is_array($Caps) ? $Caps : array();
$search = isset($Search) ? (string)$Search : '';

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

<style>
/* ---- Media-page styling (reuses .cms-media-* tile tokens; dark-mode via vars) ---- */
.cms-media-page-grid {
    display: grid; grid-template-columns: repeat(auto-fill, minmax(160px, 1fr)); gap: 14px;
}
.cms-media-card {
    border: 1px solid var(--ork-border); border-radius: 11px; overflow: hidden;
    background: var(--ork-bg-secondary); display: flex; flex-direction: column;
}
.cms-media-card-thumb {
    width: 100%; height: 130px; object-fit: cover; display: block; background: var(--ork-bg-tertiary);
}
.cms-media-card-body { padding: 10px 12px; }
.cms-media-card-name {
    font-weight: 600; font-size: 13px; color: var(--ork-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cms-media-card-alt { font-size: 12px; color: var(--ork-text-muted); margin-top: 3px; min-height: 1em; }
.cms-media-card-alt.cms-media-noalt { font-style: italic; }
/* Upload drop-zone sits below the library; slimmer than the primary surface */
.cms-upload-drop-slim { margin-top: 16px; padding: 14px; }
</style>

<?php
/* ---- CMS shell setup (persistent rail + masthead) ---- */
$cmsActive  = 'media';
$cmsTitle   = 'Media';
$cmsSub     = 'Images used across your pages';
$cmsActions = !empty($caps['media'])
    ? '<button type="button" class="cms-btn cms-btn-primary" id="cmsMediaUploadBtn"><i class="fas fa-cloud-upload-alt"></i> Upload</button>'
    : '';
include __DIR__ . '/cms/_shell_top.tpl';
?>

    <div class="cms-filters">
        <input type="text" class="cms-input" id="cmsMediaSearch" placeholder="Search media…" value="<?= $h($search) ?>">
        <button type="button" class="cms-btn cms-btn-sm" id="cmsMediaSearchBtn"><i class="fas fa-search"></i> Search</button>
    </div>

    <div id="cmsMediaArea">
        <?php if (empty($media)): ?>
            <div class="cms-empty">
                <div class="cms-empty-icon"><i class="fas fa-images"></i></div>
                <div class="cms-empty-copy">
                    <?= $search !== '' ? 'No media matched your search.' : 'The library is empty. Upload your first image below.' ?>
                </div>
            </div>
        <?php else: ?>
            <div class="cms-media-page-grid">
                <?php foreach ($media as $m):
                    $mid   = (int)($m['media_id'] ?? 0);
                    $thumb = (string)($m['thumb'] ?? ($m['src'] ?? ''));
                    $alt   = (string)($m['alt'] ?? '');
                    $fn    = (string)($m['filename'] ?? ('#' . $mid));
                ?>
                    <div class="cms-media-card">
                        <img class="cms-media-card-thumb" src="<?= $h($thumb) ?>" alt="<?= $h($alt) ?>" loading="lazy">
                        <div class="cms-media-card-body" data-media-id="<?= $mid ?>">
                            <div class="cms-media-card-name" data-tip="<?= $h($fn) ?>"><?= $h($fn) ?></div>
                            <?php if ($alt !== ''): ?>
                                <div class="cms-media-card-alt"><?= $h($alt) ?></div>
                            <?php else: ?>
                                <div class="cms-media-card-alt cms-media-noalt">No alt text</div>
                            <?php endif; ?>
                            <?php if (!empty($caps['media'])): ?>
                                <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-edit-alt" style="margin-top:6px;"><i class="fas fa-pen"></i> Edit alt</button>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <?php if (!empty($caps['media'])): ?>
        <label class="cms-upload-drop cms-upload-drop-slim" id="cmsUploadDrop">
            <i class="fas fa-cloud-upload-alt" style="font-size:18px;"></i>
            <div style="margin-top:4px;">Click or drop an image to upload (JPG, PNG, GIF, WebP — max 8MB)</div>
            <input type="file" id="cmsUploadInput" accept="image/jpeg,image/png,image/gif,image/webp">
        </label>
        <?php /* C1: alt text authored at upload (kept OUT of the drop <label> so a
                click on the field never re-opens the file picker). */ ?>
        <div class="cms-upload-meta" style="margin-top:10px;max-width:520px;">
            <div class="cms-field" style="margin-bottom:6px;">
                <label class="cms-label" for="cmsUploadAlt">Alt text (image description)</label>
                <input type="text" class="cms-input" id="cmsUploadAlt" placeholder="Describe this image for screen-reader users">
            </div>
            <label class="cms-check-inline"><input type="checkbox" id="cmsUploadDecorative"> This image is decorative (no alt text)</label>
            <div class="cms-help">Alt text lets screen-reader users and search engines understand the image. Mark it “decorative” only when it carries no information (a texture, border, or ornament) — that intentionally saves an empty alt so assistive tech skips it.</div>
        </div>
    <?php endif; ?>

    <?php if (!empty($caps['media'])): ?>
    <?php /* ---- Trash: soft-deleted media, restorable or purgeable (C2). Lazy-loaded
            on open via CmsAjax/listtrashedmedia; Restore = restoremedia, Purge =
            purgemedia (permanent, confirmed). ---- */ ?>
    <div class="cms-trash-section" style="margin-top:26px;">
        <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsMediaTrashToggle" aria-expanded="false" aria-controls="cmsMediaTrashPanel">
            <i class="fas fa-trash-alt"></i> Trash <span class="cms-muted" id="cmsMediaTrashCount"></span>
        </button>
        <div id="cmsMediaTrashPanel" hidden style="margin-top:12px;">
            <div id="cmsMediaTrashArea"></div>
        </div>
    </div>
    <?php endif; ?>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<?php /* ---- Confirm modal (Purge — permanent) ---- */ ?>
<div class="cms-modal-overlay" id="cmsMediaConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Confirm">
        <div class="cms-modal-head">
            <h3>Permanently delete?</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p id="cmsMediaConfirmBody" style="margin:0;font-size:14px;"></p>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="cmsMediaConfirmOk">Delete permanently</button>
        </div>
    </div>
</div>

<div class="cms-toast" id="cmsToast"></div>

<script>
(function () {
    'use strict';
    var UIR = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---- toast ---- */
    var toastEl = document.getElementById('cmsToast');
    var toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- POST helper (urlencoded → JSON) ---- */
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

    var area = document.getElementById('cmsMediaArea');
    var searchEl = document.getElementById('cmsMediaSearch');
    var canEditMedia = <?= !empty($caps['media']) ? 'true' : 'false' ?>;

    // C1: shared card body markup — name + alt line + (when permitted) an
    // "Edit alt" affordance. Used by the JS re-render path; the initial PHP render
    // carries the same structure so the delegated editor works on first paint too.
    function cardBodyHtml(m) {
        var fn = m.filename || ('#' + (m.media_id || ''));
        var alt = m.alt || '';
        return '<div class="cms-media-card-body" data-media-id="' + esc(m.media_id || '') + '">' +
            '<div class="cms-media-card-name" data-tip="' + esc(fn) + '">' + esc(fn) + '</div>' +
            (alt
                ? '<div class="cms-media-card-alt">' + esc(alt) + '</div>'
                : '<div class="cms-media-card-alt cms-media-noalt">No alt text</div>') +
            (canEditMedia
                ? '<button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-edit-alt" style="margin-top:6px;"><i class="fas fa-pen"></i> Edit alt</button>'
                : '') +
            '</div>';
    }

    function renderGrid(items) {
        if (!items || !items.length) {
            var q = (searchEl && searchEl.value.trim()) || '';
            area.innerHTML = '<div class="cms-empty"><div class="cms-empty-icon"><i class="fas fa-images"></i></div>' +
                '<div class="cms-empty-copy">' +
                (q ? 'No media matched your search.' : 'The library is empty. Upload your first image below.') +
                '</div></div>';
            return;
        }
        var grid = document.createElement('div');
        grid.className = 'cms-media-page-grid';
        items.forEach(function (m) {
            var alt = m.alt || '';
            var card = document.createElement('div');
            card.className = 'cms-media-card';
            card.innerHTML =
                '<img class="cms-media-card-thumb" src="' + esc(m.thumb || m.src) + '" alt="' + esc(alt) + '" loading="lazy">' +
                cardBodyHtml(m);
            grid.appendChild(card);
        });
        area.innerHTML = '';
        area.appendChild(grid);
    }

    /* ---- C1: inline alt editor (delegated) — turns a card's alt line into an
       input + Save/Cancel and persists via CmsAjax/mediaupdate → CmsMedia::Update.
       SEAM: the mediaupdate endpoint lives in controller.CmsAjax (other lane); the
       persistence method (CmsMedia::Update) already exists. Errors gracefully if
       the endpoint isn't present yet. ---- */
    area.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.cms-media-edit-alt');
        if (editBtn) {
            var body = editBtn.closest('.cms-media-card-body');
            if (body && !body.querySelector('.cms-media-alt-edit')) { openAltEditor(body); }
            return;
        }
    });

    function openAltEditor(body) {
        var mediaId = body.getAttribute('data-media-id');
        var altLine = body.querySelector('.cms-media-card-alt');
        var curAlt = (altLine && !altLine.classList.contains('cms-media-noalt')) ? altLine.textContent : '';
        var editBtn = body.querySelector('.cms-media-edit-alt');
        if (editBtn) { editBtn.style.display = 'none'; }

        var wrap = document.createElement('div');
        wrap.className = 'cms-media-alt-edit';
        wrap.style.marginTop = '6px';
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'cms-input';
        input.value = curAlt;
        input.placeholder = 'Describe this image (leave blank if decorative)';
        var row = document.createElement('div');
        row.style.cssText = 'margin-top:6px;display:flex;gap:6px;';
        var saveB = document.createElement('button');
        saveB.type = 'button'; saveB.className = 'cms-btn cms-btn-sm cms-btn-primary'; saveB.textContent = 'Save';
        var cancelB = document.createElement('button');
        cancelB.type = 'button'; cancelB.className = 'cms-btn cms-btn-sm cms-btn-ghost'; cancelB.textContent = 'Cancel';
        row.appendChild(saveB); row.appendChild(cancelB);
        wrap.appendChild(input); wrap.appendChild(row);
        body.appendChild(wrap);
        input.focus();

        function close() {
            wrap.remove();
            if (editBtn) { editBtn.style.display = ''; }
        }
        cancelB.addEventListener('click', close);
        input.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { close(); }
            else if (ev.key === 'Enter') { ev.preventDefault(); saveB.click(); }
        });
        saveB.addEventListener('click', function () {
            var newAlt = input.value.trim();
            saveB.disabled = true;
            post('mediaupdate', { media_id: mediaId, alt: newAlt }).then(function (res) {
                saveB.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Could not save alt text.', 'error'); return; }
                if (altLine) {
                    altLine.textContent = newAlt || 'No alt text';
                    altLine.className = newAlt ? 'cms-media-card-alt' : 'cms-media-card-alt cms-media-noalt';
                }
                var img = body.parentNode ? body.parentNode.querySelector('.cms-media-card-thumb') : null;
                if (img) { img.alt = newAlt; }
                toast('Alt text saved.', 'ok');
                close();
            }).catch(function () { saveB.disabled = false; toast('Network error.', 'error'); });
        });
    }

    function loadMedia(q) {
        area.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy"><span class="cms-spin"></span> Loading…</div></div>';
        var url = AJAX + 'medialist' + (q ? '&' + new URLSearchParams({ q: q }).toString() : '')
            + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) {
                    area.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy">' +
                        esc((res && res.error) || 'Could not load media.') + '</div></div>';
                    return;
                }
                renderGrid(res.media || []);
            })
            .catch(function () {
                area.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy">Network error.</div></div>';
            });
    }

    var uploadAlt = document.getElementById('cmsUploadAlt');
    var uploadDecorative = document.getElementById('cmsUploadDecorative');

    // C1: a "decorative" tick INTENTIONALLY uploads an empty alt (assistive tech
    // skips the image) — an explicit choice, distinct from forgetting to describe it.
    function uploadAltValue() {
        if (uploadDecorative && uploadDecorative.checked) { return ''; }
        return uploadAlt ? uploadAlt.value.trim() : '';
    }
    function resetUploadMeta() {
        if (uploadAlt) { uploadAlt.value = ''; uploadAlt.disabled = false; }
        if (uploadDecorative) { uploadDecorative.checked = false; }
    }

    /* ---- Upload (mirrors the block-editor picker) ---- */
    function doUpload(file) {
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { toast('Image is larger than 8MB.', 'error'); return; }
        var alt = uploadAltValue();
        var reader = new FileReader();
        reader.onerror = function () { toast('Could not read file.', 'error'); };
        reader.onload = function () {
            toast('Uploading…');
            post('mediaupload', { data: reader.result, filename: file.name, alt: alt }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); return; }
                toast('Image uploaded.', 'ok');
                resetUploadMeta();
                loadMedia((searchEl && searchEl.value.trim()) || '');
            }).catch(function () { toast('Network error.', 'error'); });
        };
        reader.readAsDataURL(file);
    }

    var uploadInput = document.getElementById('cmsUploadInput');
    var uploadDrop = document.getElementById('cmsUploadDrop');
    var uploadBtn = document.getElementById('cmsMediaUploadBtn');

    // A decorative image needs no description — grey the alt field to teach why.
    if (uploadDecorative && uploadAlt) {
        uploadDecorative.addEventListener('change', function () {
            uploadAlt.disabled = uploadDecorative.checked;
            if (uploadDecorative.checked) { uploadAlt.value = ''; }
        });
    }

    if (uploadInput) {
        uploadInput.addEventListener('change', function () { doUpload(uploadInput.files[0]); uploadInput.value = ''; });
    }
    if (uploadBtn && uploadInput) {
        uploadBtn.addEventListener('click', function () { uploadInput.click(); });
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

    /* ---- Search (live, client-side fetch) ---- */
    var searchBtn = document.getElementById('cmsMediaSearchBtn');
    if (searchBtn && searchEl) {
        searchBtn.addEventListener('click', function () { loadMedia(searchEl.value.trim()); });
        searchEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); loadMedia(searchEl.value.trim()); }
        });
    }

    /* ====================================================================
     * Trash panel — lazy-load soft-deleted media; Restore or Purge.
     * Reads via CmsAjax/listtrashedmedia (GET); Restore = restoremedia,
     * Purge = purgemedia (permanent, confirmed via modal — no native confirm).
     * ==================================================================== */
    <?php if (!empty($caps['media'])): ?>
    (function () {
        var toggle  = document.getElementById('cmsMediaTrashToggle');
        var panel   = document.getElementById('cmsMediaTrashPanel');
        var trArea  = document.getElementById('cmsMediaTrashArea');
        var countEl = document.getElementById('cmsMediaTrashCount');
        if (!toggle || !panel || !trArea) { return; }
        var loaded = false;

        var confirmModal = document.getElementById('cmsMediaConfirmModal');
        var confirmBody  = document.getElementById('cmsMediaConfirmBody');
        var confirmOk    = document.getElementById('cmsMediaConfirmOk');
        var confirmAction = null;
        function openModal(el) { if (el) { el.classList.add('cms-open'); } }
        function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }
        function askConfirm(message, onYes) {
            confirmAction = onYes;
            if (confirmBody) { confirmBody.textContent = message; }
            openModal(confirmModal);
        }
        document.addEventListener('click', function (e) {
            var closer = e.target.closest('[data-close-modal]');
            if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
            if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) { closeModal(e.target); }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') { document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal); }
        });
        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                var fn = confirmAction; confirmAction = null;
                if (typeof fn === 'function') { fn(); }
            });
        }

        var emptyHtml = '<div class="cms-empty"><div class="cms-empty-icon"><i class="fas fa-trash-alt"></i></div><div class="cms-empty-copy">The Trash is empty.</div></div>';

        function render(items) {
            if (countEl) { countEl.textContent = items.length ? '(' + items.length + ')' : ''; }
            if (!items.length) { trArea.innerHTML = emptyHtml; return; }
            var grid = document.createElement('div');
            grid.className = 'cms-media-page-grid';
            items.forEach(function (m) {
                var alt = m.alt || '';
                var fn = m.filename || ('#' + (m.media_id || ''));
                var card = document.createElement('div');
                card.className = 'cms-media-card';
                card.setAttribute('data-trash-media-id', m.media_id || '');
                card.innerHTML =
                    '<img class="cms-media-card-thumb" src="' + esc(m.thumb || m.src) + '" alt="' + esc(alt) + '" loading="lazy">' +
                    '<div class="cms-media-card-body">' +
                        '<div class="cms-media-card-name" data-tip="' + esc(fn) + '">' + esc(fn) + '</div>' +
                        '<div style="margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;">' +
                            '<button type="button" class="cms-btn cms-btn-sm cms-btn-primary cms-trash-restore" data-media-id="' + esc(m.media_id || '') + '"><i class="fas fa-trash-restore"></i> Restore</button>' +
                            '<button type="button" class="cms-btn cms-btn-sm cms-btn-danger cms-trash-purge" data-media-id="' + esc(m.media_id || '') + '" data-filename="' + esc(fn) + '"><i class="fas fa-times"></i> Purge</button>' +
                        '</div>' +
                    '</div>';
                grid.appendChild(card);
            });
            trArea.innerHTML = '';
            trArea.appendChild(grid);
        }

        function load() {
            trArea.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy"><span class="cms-spin"></span> Loading…</div></div>';
            var url = AJAX + 'listtrashedmedia' + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
            fetch(url, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) {
                        trArea.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy">' +
                            esc((res && res.error) || 'Could not load the Trash.') + '</div></div>';
                        return;
                    }
                    loaded = true;
                    render(res.media || []);
                })
                .catch(function () {
                    trArea.innerHTML = '<div class="cms-empty"><div class="cms-empty-copy">Network error.</div></div>';
                });
        }

        toggle.addEventListener('click', function () {
            if (panel.hasAttribute('hidden')) {
                panel.removeAttribute('hidden');
                toggle.setAttribute('aria-expanded', 'true');
                if (!loaded) { load(); }
            } else {
                panel.setAttribute('hidden', '');
                toggle.setAttribute('aria-expanded', 'false');
            }
        });

        function removeCard(mid) {
            var card = trArea.querySelector('[data-trash-media-id="' + mid + '"]');
            if (card && card.parentNode) { card.parentNode.removeChild(card); }
            var remaining = trArea.querySelectorAll('[data-trash-media-id]').length;
            if (countEl) { countEl.textContent = remaining ? '(' + remaining + ')' : ''; }
            if (!remaining) { trArea.innerHTML = emptyHtml; }
        }

        trArea.addEventListener('click', function (e) {
            var restoreBtn = e.target.closest('.cms-trash-restore');
            if (restoreBtn) {
                var rid = restoreBtn.getAttribute('data-media-id');
                restoreBtn.disabled = true;
                post('restoremedia', { media_id: rid }).then(function (res) {
                    if (!res || !res.ok) { restoreBtn.disabled = false; toast((res && res.error) || 'Restore failed.', 'error'); return; }
                    toast('Media restored.', 'ok');
                    removeCard(rid);
                    // Refresh the main library so the restored image reappears.
                    loadMedia((searchEl && searchEl.value.trim()) || '');
                }).catch(function () { restoreBtn.disabled = false; toast('Network error.', 'error'); });
                return;
            }
            var purgeBtn = e.target.closest('.cms-trash-purge');
            if (purgeBtn) {
                var pid = purgeBtn.getAttribute('data-media-id');
                var pfn = purgeBtn.getAttribute('data-filename') || 'this image';
                askConfirm('Permanently delete "' + pfn + '"? This removes the file for good and cannot be undone.', function () {
                    if (confirmOk) { confirmOk.disabled = true; }
                    post('purgemedia', { media_id: pid }).then(function (res) {
                        if (confirmOk) { confirmOk.disabled = false; }
                        closeModal(confirmModal);
                        if (!res || !res.ok) { toast((res && res.error) || 'Purge failed.', 'error'); return; }
                        toast('Media permanently deleted.', 'ok');
                        removeCard(pid);
                    }).catch(function () { if (confirmOk) { confirmOk.disabled = false; } closeModal(confirmModal); toast('Network error.', 'error'); });
                });
                return;
            }
        });
    })();
    <?php endif; ?>
})();
</script>
