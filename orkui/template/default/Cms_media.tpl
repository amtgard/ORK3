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
    position: relative;
    border: 1px solid var(--ork-border); border-radius: 11px; overflow: hidden;
    background: var(--ork-bg-secondary); display: flex; flex-direction: column;
}
.cms-media-card.cms-media-selected {
    border-color: var(--ork-accent, #c9a24b);
    box-shadow: 0 0 0 2px var(--ork-accent, #c9a24b) inset;
}
.cms-media-card-thumb {
    width: 100%; height: 130px; object-fit: cover; display: block; background: var(--ork-bg-tertiary);
}
.cms-media-card-body { padding: 10px 12px; }
.cms-media-card-name {
    font-weight: 600; font-size: 13px; color: var(--ork-text);
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.cms-media-card-title { font-size: 12px; color: var(--ork-text); margin-top: 2px; font-weight: 500;
    white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.cms-media-card-alt { font-size: 12px; color: var(--ork-text-muted); margin-top: 3px; min-height: 1em; }
.cms-media-card-alt.cms-media-noalt { font-style: italic; }
.cms-media-card-actions { margin-top: 8px; display: flex; gap: 6px; flex-wrap: wrap; }
.cms-media-card-usage { font-size: 11px; color: var(--ork-text-muted); margin-top: 6px; min-height: 1em; }
.cms-media-card-usage.cms-media-inuse { color: var(--ork-warn, #b8860b); font-weight: 600; }
/* Per-card bulk-select checkbox (top-left overlay on the thumb) */
.cms-media-card-sel {
    position: absolute; top: 7px; left: 7px; z-index: 2;
    background: rgba(0,0,0,.45); border-radius: 6px; padding: 3px 4px; line-height: 0;
}
.cms-media-card-sel input { width: 16px; height: 16px; margin: 0; cursor: pointer; accent-color: var(--ork-accent, #c9a24b); }
/* Inline edit form (rename + alt + title) */
.cms-media-edit-form { margin-top: 8px; display: flex; flex-direction: column; gap: 6px; }
.cms-media-edit-form .cms-label { font-size: 11px; margin-bottom: 2px; }
/* Bulk action bar — appears when 1+ cards are selected */
.cms-media-bulkbar {
    display: none; align-items: center; gap: 12px; flex-wrap: wrap;
    margin-bottom: 12px; padding: 10px 14px; border-radius: 10px;
    border: 1px solid var(--ork-border); background: var(--ork-bg-secondary);
}
.cms-media-bulkbar.cms-show { display: flex; }
.cms-media-bulkbar-count { font-weight: 600; font-size: 13px; color: var(--ork-text); }
.cms-usage-list { margin: 8px 0 0; padding-left: 18px; font-size: 13px; color: var(--ork-text-muted); }
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

    <?php if (!empty($caps['media'])): ?>
        <div class="cms-media-bulkbar" id="cmsMediaBulkBar" role="region" aria-label="Bulk actions">
            <span class="cms-media-bulkbar-count" id="cmsMediaBulkCount">0 selected</span>
            <button type="button" class="cms-btn cms-btn-sm cms-btn-danger" id="cmsMediaBulkDelete"><i class="fas fa-trash-alt"></i> Delete selected</button>
            <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost" id="cmsMediaBulkClear">Clear selection</button>
        </div>
    <?php endif; ?>

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
                    $title = (string)($m['title'] ?? '');
                    $fn    = (string)($m['filename'] ?? ('#' . $mid));
                ?>
                    <div class="cms-media-card" data-media-id="<?= $mid ?>">
                        <?php if (!empty($caps['media'])): ?>
                            <label class="cms-media-card-sel"><input type="checkbox" class="cms-media-check" data-media-id="<?= $mid ?>" aria-label="Select <?= $h($fn) ?>"></label>
                        <?php endif; ?>
                        <img class="cms-media-card-thumb" src="<?= $h($thumb) ?>" alt="<?= $h($alt) ?>" loading="lazy">
                        <div class="cms-media-card-body" data-media-id="<?= $mid ?>">
                            <div class="cms-media-card-name" data-tip="<?= $h($fn) ?>"><?= $h($fn) ?></div>
                            <?php if ($title !== ''): ?>
                                <div class="cms-media-card-title"><?= $h($title) ?></div>
                            <?php endif; ?>
                            <?php if ($alt !== ''): ?>
                                <div class="cms-media-card-alt"><?= $h($alt) ?></div>
                            <?php else: ?>
                                <div class="cms-media-card-alt cms-media-noalt">No alt text</div>
                            <?php endif; ?>
                            <div class="cms-media-card-usage" data-media-id="<?= $mid ?>"></div>
                            <?php if (!empty($caps['media'])): ?>
                                <div class="cms-media-card-actions">
                                    <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-edit" data-tip="Rename, edit alt &amp; title"><i class="fas fa-pen"></i> Edit</button>
                                    <button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-usage" data-tip="See where this image is used"><i class="fas fa-link"></i> Where used</button>
                                    <button type="button" class="cms-btn cms-btn-sm cms-btn-danger cms-media-delete"><i class="fas fa-trash-alt"></i> Delete</button>
                                </div>
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

<?php /* ---- Confirm / info modal (shared: trash-delete, purge, and the
        "still in use" block message — no native confirm(); title, body, and the
        primary button are all set by JS per use). ---- */ ?>
<div class="cms-modal-overlay" id="cmsMediaConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-labelledby="cmsMediaConfirmTitle">
        <div class="cms-modal-head">
            <h3 id="cmsMediaConfirmTitle">Confirm</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p id="cmsMediaConfirmBody" style="margin:0;font-size:14px;"></p>
            <div id="cmsMediaConfirmExtra"></div>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal id="cmsMediaConfirmCancel">Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="cmsMediaConfirmOk">Delete</button>
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

    /* ---- shared confirm/info modal (no native confirm()/alert()). Title, body,
       an optional detail region, and the primary button are all set per call.
       onOk omitted → info-only (single "Close" button). Used by the active-media
       delete flow, the "still in use" block message, and the Trash purge. ---- */
    var modalEl     = document.getElementById('cmsMediaConfirmModal');
    var modalTitle  = document.getElementById('cmsMediaConfirmTitle');
    var modalBody   = document.getElementById('cmsMediaConfirmBody');
    var modalExtra  = document.getElementById('cmsMediaConfirmExtra');
    var modalOk     = document.getElementById('cmsMediaConfirmOk');
    var modalCancel = document.getElementById('cmsMediaConfirmCancel');
    var modalAction = null;

    function openModal(el) { if (el) { el.classList.add('cms-open'); } }
    function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }
    function hideModal() { closeModal(modalEl); }

    function showConfirm(opts) {
        opts = opts || {};
        if (modalTitle) { modalTitle.textContent = opts.title || 'Confirm'; }
        if (modalBody)  { modalBody.textContent = opts.message || ''; }
        if (modalExtra) { modalExtra.innerHTML = opts.extraHtml || ''; }
        if (modalOk) {
            if (opts.onOk) {
                modalOk.style.display = '';
                modalOk.disabled = false;
                modalOk.textContent = opts.okLabel || 'Delete';
                modalOk.className = 'cms-btn ' + (opts.okKind || 'cms-btn-danger');
            } else {
                modalOk.style.display = 'none';
            }
        }
        if (modalCancel) { modalCancel.textContent = opts.onOk ? 'Cancel' : 'Close'; }
        modalAction = (typeof opts.onOk === 'function') ? opts.onOk : null;
        openModal(modalEl);
    }

    if (modalOk) {
        modalOk.addEventListener('click', function () {
            if (typeof modalAction === 'function') { modalAction(); }
        });
    }
    document.addEventListener('click', function (e) {
        var closer = e.target.closest('[data-close-modal]');
        if (closer) { closeModal(closer.closest('.cms-modal-overlay')); return; }
        if (e.target.classList && e.target.classList.contains('cms-modal-overlay')) { closeModal(e.target); }
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape') {
            document.querySelectorAll('.cms-modal-overlay.cms-open').forEach(closeModal);
        }
    });

    /* ---- bulk selection state (media_id string → true) ---- */
    var selected = Object.create(null);

    var area = document.getElementById('cmsMediaArea');
    var searchEl = document.getElementById('cmsMediaSearch');
    var canEditMedia = <?= !empty($caps['media']) ? 'true' : 'false' ?>;

    // Shared card body markup — name + optional title + alt line + a where-used
    // line + (when permitted) the Edit / Where used / Delete actions. Used by the
    // JS re-render path; the initial PHP render carries the SAME structure so the
    // delegated handlers work on first paint too.
    function cardBodyHtml(m) {
        var mid = m.media_id || '';
        var fn = m.filename || ('#' + mid);
        var alt = m.alt || '';
        var title = m.title || '';
        return '<div class="cms-media-card-body" data-media-id="' + esc(mid) + '">' +
            '<div class="cms-media-card-name" data-tip="' + esc(fn) + '">' + esc(fn) + '</div>' +
            (title ? '<div class="cms-media-card-title">' + esc(title) + '</div>' : '') +
            (alt
                ? '<div class="cms-media-card-alt">' + esc(alt) + '</div>'
                : '<div class="cms-media-card-alt cms-media-noalt">No alt text</div>') +
            '<div class="cms-media-card-usage" data-media-id="' + esc(mid) + '"></div>' +
            (canEditMedia
                ? '<div class="cms-media-card-actions">' +
                    '<button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-edit" data-tip="Rename, edit alt &amp; title"><i class="fas fa-pen"></i> Edit</button>' +
                    '<button type="button" class="cms-btn cms-btn-sm cms-btn-ghost cms-media-usage" data-tip="See where this image is used"><i class="fas fa-link"></i> Where used</button>' +
                    '<button type="button" class="cms-btn cms-btn-sm cms-btn-danger cms-media-delete"><i class="fas fa-trash-alt"></i> Delete</button>' +
                  '</div>'
                : '') +
            '</div>';
    }

    function renderGrid(items) {
        selected = Object.create(null); // a fresh list clears any prior selection
        updateBulkBar();
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
            var mid = m.media_id || '';
            var card = document.createElement('div');
            card.className = 'cms-media-card';
            card.setAttribute('data-media-id', mid);
            card.innerHTML =
                (canEditMedia
                    ? '<label class="cms-media-card-sel"><input type="checkbox" class="cms-media-check" data-media-id="' + esc(mid) + '" aria-label="Select ' + esc(m.filename || ('#' + mid)) + '"></label>'
                    : '') +
                '<img class="cms-media-card-thumb" src="' + esc(m.thumb || m.src) + '" alt="' + esc(alt) + '" loading="lazy">' +
                cardBodyHtml(m);
            grid.appendChild(card);
        });
        area.innerHTML = '';
        area.appendChild(grid);
    }

    /* ---- Trash invalidation hook (set by the Trash IIFE below) so a delete that
       moves an item to Trash refreshes the Trash panel/count. ---- */
    var trashInvalidate = null;
    function invalidateTrash() { if (typeof trashInvalidate === 'function') { trashInvalidate(); } }

    /* ---- bulk-select bar ---- */
    var bulkBar   = document.getElementById('cmsMediaBulkBar');
    var bulkCount = document.getElementById('cmsMediaBulkCount');
    function selectedIds() { return Object.keys(selected); }
    function updateBulkBar() {
        if (!bulkBar) { return; }
        var n = selectedIds().length;
        if (bulkCount) { bulkCount.textContent = n + ' selected'; }
        bulkBar.classList.toggle('cms-show', n > 0);
    }
    function clearSelection() {
        selected = Object.create(null);
        area.querySelectorAll('.cms-media-check').forEach(function (cb) { cb.checked = false; });
        area.querySelectorAll('.cms-media-card.cms-media-selected').forEach(function (c) { c.classList.remove('cms-media-selected'); });
        updateBulkBar();
    }

    // media_id is always a server int — keep only digits for a safe attr selector.
    function idSel(mid) { return String(mid).replace(/[^0-9]/g, ''); }

    function removeActiveCard(mid) {
        delete selected[mid];
        var card = area.querySelector('.cms-media-card[data-media-id="' + idSel(mid) + '"]');
        if (card && card.parentNode) { card.parentNode.removeChild(card); }
        if (!area.querySelector('.cms-media-card')) {
            var q = (searchEl && searchEl.value.trim()) || '';
            area.innerHTML = '<div class="cms-empty"><div class="cms-empty-icon"><i class="fas fa-images"></i></div>' +
                '<div class="cms-empty-copy">' +
                (q ? 'No media matched your search.' : 'The library is empty. Upload your first image below.') +
                '</div></div>';
        }
        updateBulkBar();
    }

    /* ---- where-used helpers (CmsAjax/mediausage → CmsMedia::ReferenceUsage) ---- */
    function fetchUsage(mid) {
        var url = AJAX + 'mediausage' + '&' + new URLSearchParams({ media_id: mid }).toString()
            + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
        return fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }
    function usageParts(u) {
        var parts = [];
        function pl(n, one, many) { if (n) { parts.push(n + ' ' + (n === 1 ? one : many)); } }
        pl(u.pages || 0, 'page', 'pages');
        pl(u.posts || 0, 'post', 'posts');
        pl(u.logos || 0, 'site logo', 'site logos');
        pl(u.blocks || 0, 'content block', 'content blocks');
        return parts;
    }
    function usageSummary(u) {
        if (!u || !u.total) { return 'Not used anywhere — safe to delete.'; }
        return 'Used in ' + u.total + ' place' + (u.total === 1 ? '' : 's') + ': ' + usageParts(u).join(', ') + '.';
    }
    function usageListHtml(u) {
        var parts = usageParts(u);
        if (!parts.length) { return ''; }
        return '<ul class="cms-usage-list"><li>' + parts.map(esc).join('</li><li>') + '</li></ul>';
    }

    /* ---- delegated card actions: Edit / Where used / Delete + checkbox ---- */
    area.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.cms-media-edit');
        if (editBtn) {
            var eBody = editBtn.closest('.cms-media-card-body');
            if (eBody && !eBody.querySelector('.cms-media-edit-form')) { openEditForm(eBody); }
            return;
        }
        var usageBtn = e.target.closest('.cms-media-usage');
        if (usageBtn) {
            var uBody = usageBtn.closest('.cms-media-card-body');
            if (uBody) { showCardUsage(uBody, usageBtn); }
            return;
        }
        var delBtn = e.target.closest('.cms-media-delete');
        if (delBtn) {
            var dBody = delBtn.closest('.cms-media-card-body');
            if (dBody) { startDelete(dBody, delBtn); }
            return;
        }
    });

    area.addEventListener('change', function (e) {
        var cb = e.target.closest('.cms-media-check');
        if (!cb) { return; }
        var mid = cb.getAttribute('data-media-id');
        var card = cb.closest('.cms-media-card');
        if (cb.checked) { selected[mid] = true; if (card) { card.classList.add('cms-media-selected'); } }
        else { delete selected[mid]; if (card) { card.classList.remove('cms-media-selected'); } }
        updateBulkBar();
    });

    /* ---- inline edit form: rename + alt + title (persists via mediaupdate). ---- */
    function openEditForm(body) {
        var mediaId = body.getAttribute('data-media-id');
        var nameLine  = body.querySelector('.cms-media-card-name');
        var titleLine = body.querySelector('.cms-media-card-title');
        var altLine   = body.querySelector('.cms-media-card-alt');
        var actions   = body.querySelector('.cms-media-card-actions');
        var curName  = nameLine ? nameLine.textContent : '';
        var curTitle = titleLine ? titleLine.textContent : '';
        var curAlt   = (altLine && !altLine.classList.contains('cms-media-noalt')) ? altLine.textContent : '';
        if (actions) { actions.style.display = 'none'; }

        var form = document.createElement('div');
        form.className = 'cms-media-edit-form';
        function field(labelText, value, placeholder) {
            var wrap = document.createElement('div');
            var lab = document.createElement('label');
            lab.className = 'cms-label'; lab.textContent = labelText;
            var inp = document.createElement('input');
            inp.type = 'text'; inp.className = 'cms-input'; inp.value = value; inp.placeholder = placeholder || '';
            wrap.appendChild(lab); wrap.appendChild(inp);
            form.appendChild(wrap);
            return inp;
        }
        var nameInp  = field('Filename', curName, 'image-name');
        var titleInp = field('Title (optional)', curTitle, 'A short caption / title');
        var altInp   = field('Alt text', curAlt, 'Describe this image (leave blank if decorative)');

        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:6px;margin-top:2px;';
        var saveB = document.createElement('button');
        saveB.type = 'button'; saveB.className = 'cms-btn cms-btn-sm cms-btn-primary'; saveB.textContent = 'Save';
        var cancelB = document.createElement('button');
        cancelB.type = 'button'; cancelB.className = 'cms-btn cms-btn-sm cms-btn-ghost'; cancelB.textContent = 'Cancel';
        row.appendChild(saveB); row.appendChild(cancelB);
        form.appendChild(row);
        body.appendChild(form);
        nameInp.focus();

        function close() {
            form.remove();
            if (actions) { actions.style.display = ''; }
        }
        cancelB.addEventListener('click', close);
        form.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') { close(); }
            else if (ev.key === 'Enter') { ev.preventDefault(); saveB.click(); }
        });
        saveB.addEventListener('click', function () {
            saveB.disabled = true;
            post('mediaupdate', {
                media_id: mediaId,
                filename: nameInp.value.trim(),
                title: titleInp.value.trim(),
                alt: altInp.value.trim()
            }).then(function (res) {
                saveB.disabled = false;
                if (!res || !res.ok) { toast((res && res.error) || 'Could not save changes.', 'error'); return; }
                var newName = (res.filename != null) ? res.filename : nameInp.value.trim();
                var newTitle = (res.title != null) ? res.title : titleInp.value.trim();
                var newAlt = (res.alt != null) ? res.alt : altInp.value.trim();
                if (nameLine && newName) { nameLine.textContent = newName; nameLine.setAttribute('data-tip', newName); }
                if (altLine) {
                    altLine.textContent = newAlt || 'No alt text';
                    altLine.className = newAlt ? 'cms-media-card-alt' : 'cms-media-card-alt cms-media-noalt';
                }
                // Title line: create/update/remove to match the saved value.
                if (newTitle) {
                    if (!titleLine) {
                        titleLine = document.createElement('div');
                        titleLine.className = 'cms-media-card-title';
                        if (nameLine && nameLine.nextSibling) { body.insertBefore(titleLine, nameLine.nextSibling); }
                        else { body.appendChild(titleLine); }
                    }
                    titleLine.textContent = newTitle;
                } else if (titleLine) {
                    titleLine.remove(); titleLine = null;
                }
                var img = body.parentNode ? body.parentNode.querySelector('.cms-media-card-thumb') : null;
                if (img) { img.alt = newAlt; }
                toast('Changes saved.', 'ok');
                close();
            }).catch(function () { saveB.disabled = false; toast('Network error.', 'error'); });
        });
    }

    /* ---- per-card "Where used" — fetch usage and show it inline on the card. ---- */
    function showCardUsage(body, btn) {
        var mediaId = body.getAttribute('data-media-id');
        var line = body.querySelector('.cms-media-card-usage');
        if (!line) { return; }
        btn.disabled = true;
        line.textContent = 'Checking…'; line.className = 'cms-media-card-usage';
        fetchUsage(mediaId).then(function (res) {
            btn.disabled = false;
            if (!res || !res.ok) { line.textContent = (res && res.error) || 'Could not check usage.'; return; }
            var u = res.usage || {};
            line.textContent = usageSummary(u);
            line.className = 'cms-media-card-usage' + (u.total ? ' cms-media-inuse' : '');
        }).catch(function () { btn.disabled = false; line.textContent = 'Network error.'; });
    }

    /* ---- single delete: check usage first, then confirm (Trash) or block. ---- */
    function startDelete(body, btn) {
        var mediaId = body.getAttribute('data-media-id');
        var nameLine = body.querySelector('.cms-media-card-name');
        var fn = nameLine ? nameLine.textContent : ('#' + mediaId);
        btn.disabled = true;
        fetchUsage(mediaId).then(function (res) {
            btn.disabled = false;
            if (!res || !res.ok) { toast((res && res.error) || 'Could not check usage.', 'error'); return; }
            var u = res.usage || {};
            if (u.total > 0) {
                // In use — deletion is refused server-side; explain, don't offer OK.
                showConfirm({
                    title: 'Still in use',
                    message: '“' + fn + '” is used in ' + u.total + ' place' + (u.total === 1 ? '' : 's')
                        + '. Remove it from the following before deleting it:',
                    extraHtml: usageListHtml(u)
                });
                return;
            }
            showConfirm({
                title: 'Move to Trash?',
                message: '“' + fn + '” will be moved to the Trash. You can restore it later.',
                okLabel: 'Move to Trash',
                onOk: function () {
                    modalOk.disabled = true;
                    post('mediadelete', { media_id: mediaId }).then(function (dr) {
                        hideModal();
                        if (!dr || !dr.ok) { toast((dr && dr.error) || 'Delete failed.', 'error'); return; }
                        removeActiveCard(mediaId);
                        toast('Moved to Trash.', 'ok');
                        invalidateTrash();
                    }).catch(function () { hideModal(); toast('Network error.', 'error'); });
                }
            });
        }).catch(function () { btn.disabled = false; toast('Network error.', 'error'); });
    }

    /* ---- bulk delete (selected cards → Trash; in-use are skipped server-side). ---- */
    var bulkDeleteBtn = document.getElementById('cmsMediaBulkDelete');
    var bulkClearBtn  = document.getElementById('cmsMediaBulkClear');
    if (bulkClearBtn) { bulkClearBtn.addEventListener('click', clearSelection); }
    if (bulkDeleteBtn) {
        bulkDeleteBtn.addEventListener('click', function () {
            var ids = selectedIds();
            if (!ids.length) { return; }
            showConfirm({
                title: 'Move ' + ids.length + ' image' + (ids.length === 1 ? '' : 's') + ' to Trash?',
                message: 'Selected images will be moved to the Trash. Any that are still in use will be skipped. '
                    + 'You can restore anything from the Trash.',
                okLabel: 'Move to Trash',
                onOk: function () {
                    modalOk.disabled = true;
                    post('mediabulkdelete', { media_ids: JSON.stringify(ids) }).then(function (res) {
                        hideModal();
                        if (!res || !res.ok) { toast((res && res.error) || 'Bulk delete failed.', 'error'); return; }
                        (res.deleted || []).forEach(function (mid) { removeActiveCard(mid); });
                        var msg = (res.deleted_count || 0) + ' moved to Trash';
                        if (res.in_use_count) { msg += ', ' + res.in_use_count + ' skipped (in use)'; }
                        if (res.failed_count) { msg += ', ' + res.failed_count + ' could not be deleted'; }
                        toast(msg, (res.in_use_count || res.failed_count) ? 'error' : 'ok');
                        clearSelection();
                        invalidateTrash();
                    }).catch(function () { hideModal(); toast('Network error.', 'error'); });
                }
            });
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

        // The shared confirm/info modal (showConfirm/hideModal) lives in the outer
        // scope — reuse it here rather than a second, duplicate modal controller.

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

        // Hook the outer-scope delete flows: after an item is moved to Trash, the
        // cached Trash view is stale — reload it if open, else force a reload on
        // next open (and light up the count).
        trashInvalidate = function () {
            loaded = false;
            if (!panel.hasAttribute('hidden')) { load(); }
        };

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
                showConfirm({
                    title: 'Permanently delete?',
                    message: 'Permanently delete “' + pfn + '”? This removes the file for good and cannot be undone.',
                    okLabel: 'Delete permanently',
                    onOk: function () {
                        modalOk.disabled = true;
                        post('purgemedia', { media_id: pid }).then(function (res) {
                            hideModal();
                            if (!res || !res.ok) { toast((res && res.error) || 'Purge failed.', 'error'); return; }
                            toast('Media permanently deleted.', 'ok');
                            removeCard(pid);
                        }).catch(function () { hideModal(); toast('Network error.', 'error'); });
                    }
                });
                return;
            }
        });
    })();
    <?php endif; ?>
})();
</script>
