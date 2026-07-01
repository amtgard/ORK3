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
                        <div class="cms-media-card-body">
                            <div class="cms-media-card-name" data-tip="<?= $h($fn) ?>"><?= $h($fn) ?></div>
                            <?php if ($alt !== ''): ?>
                                <div class="cms-media-card-alt"><?= $h($alt) ?></div>
                            <?php else: ?>
                                <div class="cms-media-card-alt cms-media-noalt">No alt text</div>
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
    <?php endif; ?>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

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
            var fn = m.filename || ('#' + (m.media_id || ''));
            var alt = m.alt || '';
            var card = document.createElement('div');
            card.className = 'cms-media-card';
            card.innerHTML =
                '<img class="cms-media-card-thumb" src="' + esc(m.thumb || m.src) + '" alt="' + esc(alt) + '" loading="lazy">' +
                '<div class="cms-media-card-body">' +
                    '<div class="cms-media-card-name" data-tip="' + esc(fn) + '">' + esc(fn) + '</div>' +
                    (alt
                        ? '<div class="cms-media-card-alt">' + esc(alt) + '</div>'
                        : '<div class="cms-media-card-alt cms-media-noalt">No alt text</div>') +
                '</div>';
            grid.appendChild(card);
        });
        area.innerHTML = '';
        area.appendChild(grid);
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

    /* ---- Upload (mirrors the block-editor picker) ---- */
    function doUpload(file) {
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { toast('Image is larger than 8MB.', 'error'); return; }
        var reader = new FileReader();
        reader.onerror = function () { toast('Could not read file.', 'error'); };
        reader.onload = function () {
            toast('Uploading…');
            post('mediaupload', { data: reader.result, filename: file.name, alt: '' }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); return; }
                toast('Image uploaded.', 'ok');
                loadMedia((searchEl && searchEl.value.trim()) || '');
            }).catch(function () { toast('Network error.', 'error'); });
        };
        reader.readAsDataURL(file);
    }

    var uploadInput = document.getElementById('cmsUploadInput');
    var uploadDrop = document.getElementById('cmsUploadDrop');
    var uploadBtn = document.getElementById('cmsMediaUploadBtn');

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
})();
</script>
