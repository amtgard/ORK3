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
$urlType = isset($_GET['type']) ? trim((string)$_GET['type']) : '';

$pageId       = (int)($page['page_id'] ?? 0);
$pTitle       = (string)($page['title'] ?? '');
$pSlug        = (string)($page['slug'] ?? '');
$pType        = $urlType !== '' ? $urlType : (string)($page['type'] ?? 'composed');
$pMeta        = (string)($page['meta_description'] ?? '');
$pStatus      = (string)($page['status'] ?? 'draft');
$pIsSystem    = !empty($page['is_system']);
$isPublished  = ($pStatus === 'published');

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

<div class="cms-wrap">

    <div class="cms-topbar">
        <a class="cms-btn cms-btn-ghost cms-btn-sm" href="<?= UIR ?>Cms/index"><i class="fas fa-arrow-left"></i> Pages</a>
        <h1 class="cms-title"><?= $isNew ? 'New Page' : $h('Edit: ' . $pTitle) ?></h1>
        <span class="cms-spacer"></span>
    </div>

    <div class="cms-editor">

        <?php /* ---- Meta panel ---- */ ?>
        <div class="cms-meta-panel">
            <h2>Page settings</h2>

            <div class="cms-status-row">
                Status:
                <span class="cms-badge cms-badge-<?= $isPublished ? 'published' : 'draft' ?>" id="cmsStatusBadge">
                    <?= $isPublished ? 'Published' : 'Draft' ?>
                </span>
                <?php if ($pIsSystem): ?><span class="cms-badge cms-badge-system">System</span><?php endif; ?>
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsTitle">Title</label>
                <input type="text" class="cms-input" id="cmsTitle" value="<?= $h($pTitle) ?>" placeholder="Page title">
            </div>

            <div class="cms-field">
                <label class="cms-label" for="cmsSlug">Slug</label>
                <input type="text" class="cms-input" id="cmsSlug" value="<?= $h($pSlug) ?>" placeholder="page-slug">
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
                <textarea class="cms-textarea" id="cmsMeta" placeholder="Short summary for search engines." style="min-height:70px;"><?= $h($pMeta) ?></textarea>
            </div>

            <div class="cms-action-row">
                <?php if ($canEdit): ?>
                    <button type="button" class="cms-btn cms-btn-primary" id="cmsSaveBtn"><i class="fas fa-save"></i> Save</button>
                <?php endif; ?>
                <a class="cms-btn cms-btn-ghost" id="cmsPreviewBtn" href="<?= $pageId > 0 ? UIR . 'Cms/preview/' . $pageId : '#' ?>" target="_blank" rel="noopener"><i class="fas fa-eye"></i> Preview</a>
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
                <button type="button" class="cms-btn cms-btn-danger" id="cmsDeleteBtn"><i class="fas fa-trash"></i> Delete page</button>
            </div>
            <?php endif; ?>

            <div class="cms-help" id="cmsSavedHint" style="margin-top:12px;"></div>
        </div>

        <?php /* ---- Blocks column ---- */ ?>
        <div class="cms-blocks-col">
            <div class="cms-blocks-head">
                <h2>Blocks</h2>
                <span class="cms-spacer"></span>
                <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsAddBlockBtn"><i class="fas fa-plus"></i> Add block</button>
            </div>

            <div id="cmsBlockList"></div>

            <div class="cms-empty" id="cmsBlockEmpty" style="display:none;border:1px dashed var(--ork-border-dark);border-radius:10px;">
                No blocks yet. Use <strong>Add block</strong> to build the page.
            </div>
        </div>

    </div>
</div>

<?php /* ---- Add-block chooser modal ---- */ ?>
<div class="cms-modal-overlay" id="cmsAddModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Add a block">
        <div class="cms-modal-head">
            <h3>Add a block</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <div class="cms-typegrid" id="cmsAddGrid"></div>
        </div>
    </div>
</div>

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

<?php /* ---- Confirm modal (Delete) ---- */ ?>
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

<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>
<script>
(function () {
    'use strict';

    var UIR  = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

    // Server state injected safely.
    var STATE = {
        pageId:  <?= (int)$pageId ?>,
        isNew:   <?= $isNew ? 'true' : 'false' ?>,
        blocks:  <?= json_encode($blocks, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        catalog: <?= json_encode($catalog, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        labels:  <?= json_encode($catalogLabels, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>,
        canEdit:    <?= $canEdit ? 'true' : 'false' ?>,
        canPublish: <?= $canPublish ? 'true' : 'false' ?>
    };

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
    var toastEl = document.getElementById('cmsToast');
    var toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- modal helpers ---- */
    function openModal(elx) { if (elx) { elx.classList.add('cms-open'); } }
    function closeModal(elx) { if (elx) { elx.classList.remove('cms-open'); } }
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
        }
    });

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(AJAX + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ================= block model ================= *
     * In-memory list of blocks. Each: {type, enabled(bool), source, fields{}}.
     * Order = array order. The DOM is rebuilt from this on every structural change;
     * per-field inputs write directly back into the model on input.
     * ================================================ */

    var model = (STATE.blocks || []).map(function (b) {
        return {
            type:    String(b.type || ''),
            enabled: (b.enabled === undefined ? true : !!b.enabled),
            source:  (b.source === 'dynamic' ? 'dynamic' : 'authored'),
            fields:  (b.fields && typeof b.fields === 'object') ? b.fields : {}
        };
    });

    function labelFor(type) {
        return STATE.labels[type] || type;
    }

    // Which types get friendly forms (everything else → JSON fallback).
    var FRIENDLY = {
        rich_text: 1, richtext: 1, image: 1, hero_carousel: 1,
        card_grid: 1, cta_band: 1, heading: 1, quote: 1
    };

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
            case 'cta_band':
                return strip(f.heading || '') || ((f.ctas || []).length + ' CTA(s)');
            case 'heading':
                return strip(f.text || f.heading || '');
            case 'quote':
                return strip(f.text || f.quote || '');
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
    function initTiny(textarea) {
        if (!tinyReady || !textarea) { return; }
        tinymce.init({
            target: textarea,
            menubar: false,
            statusbar: false,
            height: 240,
            plugins: 'lists link',
            toolbar: 'undo redo | blocks | bold italic | bullist numlist | link blockquote | removeformat',
            skin: (document.documentElement.getAttribute('data-theme') === 'dark') ? 'oxide-dark' : 'oxide',
            content_css: (document.documentElement.getAttribute('data-theme') === 'dark') ? 'dark' : 'default',
            setup: function (ed) {
                ed.on('change keyup input', function () {
                    ed.save(); // write HTML back into the bound textarea
                    // push into the model via the textarea's own input handler
                    ed.targetElm.dispatchEvent(new Event('input', { bubbles: false }));
                    markDirty();
                });
            }
        });
    }
    // Pull every TinyMCE editor's content back into its textarea before reading.
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

    /* ================= field builders ================= */

    // text/textarea field bound to fields[key]
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

    // select field
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

    // rich text body via TinyMCE
    function fieldRich(block, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var host = el('div', 'cms-richtext-host');
        var ta = el('textarea', 'cms-textarea');
        ta.id = 'cmsrt_' + (++tinyCounter);
        ta.setAttribute('data-tiny', '1');
        ta.value = block.fields[key] != null ? block.fields[key] : '';
        // keep model in sync even before/without TinyMCE (fallback textarea)
        ta.addEventListener('input', function () { block.fields[key] = ta.value; markDirty(); });
        host.appendChild(ta);
        wrap.appendChild(host);
        return wrap;
    }

    // image picker bound to a fields[key] media-ref object
    function fieldImage(block, container, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var row = el('div', 'cms-media-field');

        var ref = (container[key] && typeof container[key] === 'object') ? container[key] : null;
        var thumb;
        if (ref && ref.thumb) {
            thumb = el('img', 'cms-media-thumb');
            thumb.src = ref.thumb || ref.src;
        } else {
            thumb = el('div', 'cms-media-thumb cms-empty-thumb', '<i class="fas fa-image"></i>');
        }

        var meta = el('div', 'cms-media-meta');
        var nameEl = el('div', 'cms-media-name', ref ? esc(ref.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>');
        var btnRow = el('div', null);
        btnRow.style.marginTop = '6px';
        var chooseBtn = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-image"></i> Choose image');
        chooseBtn.type = 'button';
        var clearBtn = el('button', 'cms-btn cms-btn-sm cms-btn-ghost', 'Clear');
        clearBtn.type = 'button';
        clearBtn.style.marginLeft = '6px';
        if (!ref) { clearBtn.style.display = 'none'; }

        function render(newRef) {
            container[key] = newRef || {};
            // rebuild thumb
            var fresh;
            if (newRef && newRef.thumb) {
                fresh = el('img', 'cms-media-thumb');
                fresh.src = newRef.thumb || newRef.src;
            } else {
                fresh = el('div', 'cms-media-thumb cms-empty-thumb', '<i class="fas fa-image"></i>');
            }
            row.replaceChild(fresh, thumb);
            thumb = fresh;
            nameEl.innerHTML = newRef ? esc(newRef.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>';
            clearBtn.style.display = newRef ? '' : 'none';
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

    // repeatable sub-items (slides, cards, ctas). itemRender(item,index) -> DOM.
    function repeater(block, key, singular, blank, itemRender) {
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
            var add = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-plus"></i> Add ' + esc(singular.toLowerCase()));
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
        if (disabled) { b.disabled = true; }
        return b;
    }

    // CTA repeater shared by hero/cta_band ({label,href,style}).
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

    // plain text input bound to an arbitrary object[key] (sub-items)
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
    // image picker bound to a sub-item's nested image-ref object
    function imageBound(obj, key, label) {
        if (!obj[key] || typeof obj[key] !== 'object') { obj[key] = {}; }
        return fieldImage({ fields: obj }, obj, key, label);
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
            body.appendChild(fieldImage(block, block.fields, 'image', 'Image'));
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
                    g.appendChild(textBound(card, 'icon', 'Icon (Font Awesome class, e.g. fa-shield)'));
                    g.appendChild(textBound(card, 'href', 'Link (href)'));
                    box.appendChild(g);
                    box.appendChild(textBound(card, 'title', 'Title'));
                    box.appendChild(textBound(card, 'blurb', 'Blurb'));
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
            body.appendChild(fieldSelect(block, 'level', 'Level',
                [{ value: 'h2', label: 'H2' }, { value: 'h3', label: 'H3' }, { value: 'h4', label: 'H4' }], 'h2'));
            return body;
        }

        if (t === 'quote') {
            body.appendChild(fieldText(block, 'text', 'Quote text', { textarea: true }));
            body.appendChild(fieldText(block, 'cite', 'Attribution'));
            return body;
        }

        // ----- JSON fallback for any other type -----
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', 'Fields (JSON)'));
        var ta = el('textarea', 'cms-textarea');
        ta.style.minHeight = '160px';
        ta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
        ta.value = JSON.stringify(block.fields || {}, null, 2);
        ta.addEventListener('input', function () {
            try {
                var parsed = JSON.parse(ta.value);
                if (parsed && typeof parsed === 'object') {
                    block.fields = parsed;
                    ta.style.borderColor = '';
                    block._jsonError = false;
                }
            } catch (err) {
                ta.style.borderColor = 'var(--ork-badge-red-text)';
                block._jsonError = true;
            }
            markDirty();
        });
        wrap.appendChild(ta);
        wrap.appendChild(el('div', 'cms-help', 'This block type has no friendly form yet — edit its fields as JSON. It is parsed on save; invalid JSON keeps the last valid value.'));
        body.appendChild(wrap);
        return body;
    }

    /* ---- render the whole block list ---- */
    var listEl = document.getElementById('cmsBlockList');
    var emptyEl = document.getElementById('cmsBlockEmpty');

    function renderList() {
        destroyTinyIn(listEl);
        listEl.innerHTML = '';
        emptyEl.style.display = model.length ? 'none' : '';

        model.forEach(function (block, idx) {
            var card = el('div', 'cms-block-card' + (block.enabled ? '' : ' cms-block-disabled'));

            // head
            var head = el('div', 'cms-block-head');
            var collapseBtn = iconBtn('fa-chevron-down', 'Collapse / expand', false);
            head.appendChild(collapseBtn);
            head.appendChild(el('span', 'cms-block-type', esc(labelFor(block.type))));
            head.appendChild(el('span', 'cms-block-typekey', esc(block.type)));
            head.appendChild(el('span', 'cms-block-summary', esc(summarize(block))));

            var tools = el('div', 'cms-block-tools');
            var up = iconBtn('fa-arrow-up', 'Move up', idx === 0);
            var down = iconBtn('fa-arrow-down', 'Move down', idx === model.length - 1);
            up.addEventListener('click', function () { swap(model, idx, idx - 1); renderList(); markDirty(); });
            down.addEventListener('click', function () { swap(model, idx, idx + 1); renderList(); markDirty(); });

            // enabled toggle
            var sw = el('label', 'cms-switch');
            var cb = el('input'); cb.type = 'checkbox'; cb.checked = block.enabled;
            cb.addEventListener('change', function () {
                block.enabled = cb.checked;
                card.classList.toggle('cms-block-disabled', !block.enabled);
                markDirty();
            });
            sw.appendChild(cb);
            sw.appendChild(el('span', 'cms-slider'));

            var del = iconBtn('fa-trash', 'Delete block', false, true);
            del.addEventListener('click', function () { askDeleteBlock(idx); });

            tools.appendChild(up);
            tools.appendChild(down);
            tools.appendChild(sw);
            tools.appendChild(del);
            head.appendChild(tools);
            card.appendChild(head);

            // body
            var body = el('div', 'cms-block-body');
            body.appendChild(buildBlockBody(block));
            card.appendChild(body);

            collapseBtn.addEventListener('click', function () {
                body.classList.toggle('cms-collapsed');
                var icon = collapseBtn.querySelector('i');
                if (icon) { icon.className = body.classList.contains('cms-collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-down'; }
            });

            listEl.appendChild(card);
        });

        // init any TinyMCE textareas now that they're in the DOM
        listEl.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
    }

    /* ---- delete a block (confirm modal) ---- */
    var confirmModal = document.getElementById('cmsConfirmModal');
    var confirmTitle = document.getElementById('cmsConfirmTitle');
    var confirmBody = document.getElementById('cmsConfirmBody');
    var confirmOk = document.getElementById('cmsConfirmOk');
    var confirmAction = null;

    function confirmDialog(title, body, okLabel, fn) {
        confirmTitle.textContent = title;
        confirmBody.textContent = body;
        confirmOk.textContent = okLabel || 'Delete';
        confirmAction = fn;
        openModal(confirmModal);
    }
    confirmOk.addEventListener('click', function () {
        var fn = confirmAction;
        confirmAction = null;
        if (fn) { fn(); }
    });

    function askDeleteBlock(idx) {
        var label = labelFor(model[idx].type);
        confirmDialog('Remove block', 'Remove the "' + label + '" block? You can re-add it later.', 'Remove', function () {
            closeModal(confirmModal);
            model.splice(idx, 1);
            renderList();
            markDirty();
        });
    }

    /* ================= Add block ================= */
    var addModal = document.getElementById('cmsAddModal');
    var addGrid = document.getElementById('cmsAddGrid');
    document.getElementById('cmsAddBlockBtn').addEventListener('click', function () {
        addGrid.innerHTML = '';
        (STATE.catalog || []).forEach(function (c) {
            if (!c.available) { return; }
            var cardBtn = el('button', 'cms-typecard');
            cardBtn.type = 'button';
            cardBtn.innerHTML = '<strong>' + esc(c.label) + '</strong><span>' + esc(c.group) + (c.dynamic ? ' · dynamic' : '') + '</span>';
            cardBtn.addEventListener('click', function () {
                model.push({
                    type: c.type,
                    enabled: true,
                    source: c.dynamic ? 'dynamic' : 'authored',
                    fields: {}
                });
                closeModal(addModal);
                renderList();
                markDirty();
                // scroll to the new block
                var cards = listEl.querySelectorAll('.cms-block-card');
                if (cards.length) { cards[cards.length - 1].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
            });
            addGrid.appendChild(cardBtn);
        });
        if (!addGrid.children.length) {
            addGrid.appendChild(el('div', 'cms-media-empty', 'No block types available.'));
        }
        openModal(addModal);
    });

    /* ================= Media picker ================= */
    var mediaModal = document.getElementById('cmsMediaModal');
    var mediaGrid = document.getElementById('cmsMediaGrid');
    var mediaSearch = document.getElementById('cmsMediaSearch');
    var mediaSearchBtn = document.getElementById('cmsMediaSearchBtn');
    var uploadInput = document.getElementById('cmsUploadInput');
    var uploadDrop = document.getElementById('cmsUploadDrop');
    var mediaCallback = null;

    function openMediaPicker(cb) {
        mediaCallback = cb;
        openModal(mediaModal);
        loadMedia('');
    }

    function renderMediaList(items) {
        mediaGrid.innerHTML = '';
        if (!items || !items.length) {
            mediaGrid.appendChild(el('div', 'cms-media-empty', 'No media yet. Upload an image above.'));
            return;
        }
        items.forEach(function (m) {
            var tile = el('div', 'cms-media-tile');
            var img = el('img');
            img.src = m.thumb || m.src;
            img.alt = m.alt || '';
            tile.appendChild(img);
            tile.appendChild(el('div', 'cms-media-cap', esc(m.alt || m.filename || ('#' + (m.media_id || '')))));
            tile.addEventListener('click', function () {
                if (mediaCallback) { mediaCallback(m); }
                closeModal(mediaModal);
            });
            mediaGrid.appendChild(tile);
        });
    }

    function loadMedia(q) {
        mediaGrid.innerHTML = '<div class="cms-media-empty">Loading…</div>';
        var url = AJAX + 'medialist?' + new URLSearchParams(q ? { q: q } : {}).toString();
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) { mediaGrid.innerHTML = '<div class="cms-media-empty">' + esc((res && res.error) || 'Could not load media.') + '</div>'; return; }
                renderMediaList(res.media || []);
            })
            .catch(function () { mediaGrid.innerHTML = '<div class="cms-media-empty">Network error.</div>'; });
    }

    mediaSearchBtn.addEventListener('click', function () { loadMedia(mediaSearch.value.trim()); });
    mediaSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadMedia(mediaSearch.value.trim()); } });

    function doUpload(file) {
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { toast('Image is larger than 8MB.', 'error'); return; }
        var reader = new FileReader();
        reader.onload = function () {
            mediaGrid.innerHTML = '<div class="cms-media-empty"><span class="cms-spin"></span> Uploading…</div>';
            post('mediaupload', { data: reader.result, filename: file.name, alt: '' }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); loadMedia(''); return; }
                toast('Image uploaded.', 'ok');
                // refresh, then prepend the new ref visually by reloading
                loadMedia('');
            }).catch(function () { toast('Network error.', 'error'); loadMedia(''); });
        };
        reader.readAsDataURL(file);
    }
    uploadInput.addEventListener('change', function () { doUpload(uploadInput.files[0]); uploadInput.value = ''; });
    ['dragenter', 'dragover'].forEach(function (ev) {
        uploadDrop.addEventListener(ev, function (e) { e.preventDefault(); uploadDrop.classList.add('cms-drag-active'); });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        uploadDrop.addEventListener(ev, function (e) { e.preventDefault(); uploadDrop.classList.remove('cms-drag-active'); });
    });
    uploadDrop.addEventListener('drop', function (e) {
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files[0]) { doUpload(e.dataTransfer.files[0]); }
    });

    /* ================= meta form helpers ================= */
    var titleInput = document.getElementById('cmsTitle');
    var slugInput  = document.getElementById('cmsSlug');
    var typeInput  = document.getElementById('cmsType');
    var metaInput  = document.getElementById('cmsMeta');
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
    typeInput.addEventListener('change', markDirty);
    metaInput.addEventListener('input', markDirty);

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

    function serializeBlocks() {
        syncTiny();
        return model.map(function (b, i) {
            return {
                type:    b.type,
                enabled: b.enabled ? 1 : 0,
                order:   i * 10,
                source:  (b.source === 'dynamic' ? 'dynamic' : 'authored'),
                fields:  b.fields || {}
            };
        });
    }

    function doSave(isAuto) {
        if (saving || !STATE.canEdit) { return; }
        var title = titleInput.value.trim();
        if (title === '') {
            if (!isAuto) { toast('A page title is required.', 'error'); }
            return;
        }
        // a JSON-fallback block with broken JSON blocks save
        var bad = model.some(function (b) { return b._jsonError; });
        if (bad) {
            if (!isAuto) { toast('Fix the invalid JSON in a block before saving.', 'error'); }
            return;
        }

        saving = true;
        clearTimeout(autosaveTimer);
        if (saveBtn) { saveBtn.disabled = true; }
        if (savedHint) { savedHint.innerHTML = '<span class="cms-spin"></span> Saving…'; }

        var params = {
            page_id: STATE.pageId,
            title: title,
            slug: slugInput.value.trim(),
            type: typeInput.value,
            meta_description: metaInput.value.trim(),
            blocks: JSON.stringify(serializeBlocks())
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
            if (savedHint) { savedHint.textContent = 'Saved ' + new Date().toLocaleTimeString(); }
            toast('Page saved.', 'ok');
        }).catch(function () {
            saving = false;
            if (saveBtn) { saveBtn.disabled = false; }
            if (savedHint) { savedHint.textContent = ''; }
            toast('Network error.', 'error');
        });
    }

    // After a new page gets its id, enable Preview/Publish and update URL.
    function params_pageId_synced() {
        var prev = document.getElementById('cmsPreviewBtn');
        if (prev) { prev.href = UIR + 'Cms/preview/' + STATE.pageId; }
        var pub = document.getElementById('cmsPubBtn');
        if (pub) { pub.disabled = false; }
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
            }).catch(function () { pubBtn.disabled = false; toast('Network error.', 'error'); });
        });
    }

    /* ================= delete page ================= */
    var deleteBtn = document.getElementById('cmsDeleteBtn');
    if (deleteBtn) {
        deleteBtn.addEventListener('click', function () {
            confirmDialog('Delete page', 'Delete this page and all of its blocks? This cannot be undone.', 'Delete', function () {
                confirmOk.disabled = true;
                post('deletepage', { page_id: STATE.pageId }).then(function (res) {
                    confirmOk.disabled = false;
                    closeModal(confirmModal);
                    if (!res || !res.ok) { toast((res && res.error) || 'Delete failed.', 'error'); return; }
                    dirty = false;
                    window.location.href = UIR + 'Cms/index';
                }).catch(function () { confirmOk.disabled = false; toast('Network error.', 'error'); });
            });
        });
    }

    /* ================= boot ================= */
    renderList();
})();
</script>
