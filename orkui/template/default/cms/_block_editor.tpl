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
 *     canEdit:    bool,
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
 *   $beCanEdit   bool                                  — defaults to false
 *   $beHeading   blocks-column heading text            — defaults to 'Blocks'
 *   UIR (constant)
 */

$beBlocks    = isset($beBlocks) && is_array($beBlocks) ? $beBlocks : array();
$beCatalog   = isset($beCatalog) && is_array($beCatalog) ? $beCatalog : array();
$beLabels    = isset($beLabels) && is_array($beLabels) ? $beLabels : array();
$bePageTypes = isset($bePageTypes) && is_array($bePageTypes) ? $bePageTypes : array();
$beCanEdit   = !empty($beCanEdit);
$beHeading   = isset($beHeading) ? (string)$beHeading : 'Blocks';
?>
<?php /* ---- Blocks column ---- */ ?>
<div class="cms-blocks-col">
    <div class="cms-blocks-head">
        <h2><?= htmlspecialchars($beHeading, ENT_QUOTES, 'UTF-8') ?></h2>
        <span class="cms-spacer"></span>
        <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsAddBlockBtn"><i class="fas fa-plus"></i> Add block</button>
    </div>

    <div id="cmsBlockList"></div>

    <div class="cms-empty" id="cmsBlockEmpty" style="display:none;border:1px dashed var(--ork-border-dark);border-radius:10px;">
        No blocks yet. Use <strong>Add block</strong> to build the body.
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

<script src="https://cdn.jsdelivr.net/npm/tinymce@7.6.0/tinymce.min.js" referrerpolicy="origin"></script>
<script>
/* ============================================================================
 * window.CmsBlockEditor — shared block-body editor engine (pages + posts).
 * The host template calls CmsBlockEditor.init(opts) after the DOM is ready.
 * ========================================================================== */
window.CmsBlockEditor = (function () {
    'use strict';

    var UIR  = <?= json_encode(UIR) ?>;
    var AJAX = UIR + 'CmsAjax/';

    var model = [];
    var catalog = [];
    var labels = {};
    var pageTypes = [];
    var canEdit = false;
    var onDirty = function () {};

    var listEl, emptyEl;

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
    var toastEl, toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { toastEl = document.getElementById('cmsToast'); }
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

    function markDirty() {
        try { onDirty(); } catch (e) {}
    }

    /* ================= block model ================= */
    function normBlock(b) {
        return {
            type:    String(b.type || ''),
            enabled: (b.enabled === undefined ? true : !!b.enabled),
            source:  (b.source === 'dynamic' ? 'dynamic' : 'authored'),
            fields:  (b.fields && typeof b.fields === 'object') ? JSON.parse(JSON.stringify(b.fields)) : {}
        };
    }

    function labelFor(type) {
        return labels[type] || type;
    }

    function presetBlocksFor(type) {
        var pts = pageTypes || [];
        for (var i = 0; i < pts.length; i++) {
            if (pts[i] && pts[i].type === type && Array.isArray(pts[i].blocks)) {
                return pts[i].blocks;
            }
        }
        return null;
    }

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
            case 'gallery':
                return ((f.images || []).length) + ' image(s)';
            case 'video_embed':
                return (f.provider || 'youtube') + (f.video_id || f.url ? ' · ' + strip(f.video_id || f.url) : ' · no video');
            case 'file_download':
                return ((f.files || []).length) + ' file(s)';
            case 'accordion':
                return ((f.items || []).length) + ' item(s)';
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
                    ed.save();
                    ed.targetElm.dispatchEvent(new Event('input', { bubbles: false }));
                    markDirty();
                });
            }
        });
    }
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

    function fieldNumSelect(block, key, label, options, dflt) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var sel = el('select', 'cms-select');
        var current = (block.fields[key] != null) ? Number(block.fields[key]) : dflt;
        options.forEach(function (o) {
            var op = el('option');
            op.value = String(o.value); op.textContent = o.label;
            if (current === o.value) { op.selected = true; }
            sel.appendChild(op);
        });
        block.fields[key] = current;
        sel.addEventListener('change', function () { block.fields[key] = Number(sel.value); markDirty(); });
        wrap.appendChild(sel);
        return wrap;
    }

    function fieldRich(block, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var host = el('div', 'cms-richtext-host');
        var ta = el('textarea', 'cms-textarea');
        ta.id = 'cmsrt_' + (++tinyCounter);
        ta.setAttribute('data-tiny', '1');
        ta.value = block.fields[key] != null ? block.fields[key] : '';
        ta.addEventListener('input', function () { block.fields[key] = ta.value; markDirty(); });
        host.appendChild(ta);
        wrap.appendChild(host);
        return wrap;
    }

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
    function textBoundArea(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var ta = el('textarea', 'cms-textarea');
        if (ph) { ta.placeholder = ph; }
        ta.value = obj[key] != null ? obj[key] : '';
        ta.addEventListener('input', function () { obj[key] = ta.value; markDirty(); });
        wrap.appendChild(ta);
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
            body.appendChild(fieldNumSelect(block, 'level', 'Level',
                [{ value: 2, label: 'H2' }, { value: 3, label: 'H3' }, { value: 4, label: 'H4' }], 2));
            body.appendChild(fieldSelect(block, 'align', 'Alignment',
                [{ value: 'left', label: 'Left' }, { value: 'center', label: 'Center' }, { value: 'right', label: 'Right' }], 'left'));
            return body;
        }

        if (t === 'quote') {
            body.appendChild(fieldText(block, 'text', 'Quote text', { textarea: true }));
            body.appendChild(fieldText(block, 'cite', 'Attribution'));
            return body;
        }

        if (t === 'gallery') {
            body.appendChild(el('div', 'cms-label', 'Images'));
            body.appendChild(repeater(block, 'images', 'Image', {}, function (img, i) {
                return imageBound(block.fields.images, i, 'Image');
            }));
            body.appendChild(fieldNumSelect(block, 'columns', 'Columns',
                [{ value: 2, label: '2' }, { value: 3, label: '3' }, { value: 4, label: '4' }], 3));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional gallery caption' }));
            return body;
        }

        if (t === 'video_embed') {
            body.appendChild(fieldSelect(block, 'provider', 'Provider',
                [{ value: 'youtube', label: 'YouTube' }, { value: 'vimeo', label: 'Vimeo' }], 'youtube'));
            body.appendChild(fieldText(block, 'url', 'Video URL', { placeholder: 'Paste the watch/share URL' }));
            body.appendChild(fieldText(block, 'video_id', 'Video ID (optional)', { placeholder: 'Used if no URL given' }));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional caption' }));
            return body;
        }

        if (t === 'file_download') {
            body.appendChild(el('div', 'cms-label', 'Files'));
            body.appendChild(repeater(block, 'files', 'File',
                { title: '', description: '', url: '', filetype: '', size_label: '' },
                function (file) {
                    var box = el('div', null);
                    box.appendChild(textBound(file, 'title', 'Title'));
                    box.appendChild(textBound(file, 'url', 'Link (URL)', 'https://…'));
                    box.appendChild(textBound(file, 'description', 'Description (optional)'));
                    var g = el('div', 'cms-grid2');
                    g.appendChild(textBound(file, 'filetype', 'File type (e.g. PDF)'));
                    g.appendChild(textBound(file, 'size_label', 'Size label (e.g. 2.4 MB)'));
                    box.appendChild(g);
                    return box;
                }));
            return body;
        }

        if (t === 'accordion') {
            body.appendChild(el('div', 'cms-label', 'Items'));
            body.appendChild(repeater(block, 'items', 'Item',
                { q: '', a: '' },
                function (item) {
                    var box = el('div', null);
                    box.appendChild(textBound(item, 'q', 'Question'));
                    box.appendChild(textBoundArea(item, 'a', 'Answer'));
                    return box;
                }));
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
    function renderList() {
        destroyTinyIn(listEl);
        listEl.innerHTML = '';
        emptyEl.style.display = model.length ? 'none' : '';

        model.forEach(function (block, idx) {
            var card = el('div', 'cms-block-card' + (block.enabled ? '' : ' cms-block-disabled'));

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

        listEl.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
    }

    /* ---- confirm modal (delete block; also reused by host for delete page/post) ---- */
    var confirmModal, confirmTitle, confirmBody, confirmOk;
    var confirmAction = null;

    function confirmDialog(title, body, okLabel, fn) {
        confirmTitle.textContent = title;
        confirmBody.textContent = body;
        confirmOk.textContent = okLabel || 'Delete';
        confirmAction = fn;
        openModal(confirmModal);
    }

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
    function wireAddBlock() {
        var addModal = document.getElementById('cmsAddModal');
        var addGrid = document.getElementById('cmsAddGrid');
        var addBtn = document.getElementById('cmsAddBlockBtn');
        if (!addBtn || !addModal || !addGrid) { return; }
        addBtn.addEventListener('click', function () {
            addGrid.innerHTML = '';
            (catalog || []).forEach(function (c) {
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
    }

    /* ================= Media picker ================= */
    var mediaModal, mediaGrid, mediaSearch, mediaSearchBtn, uploadInput, uploadDrop;
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

    function doUpload(file) {
        if (!file) { return; }
        if (file.size > 8 * 1024 * 1024) { toast('Image is larger than 8MB.', 'error'); return; }
        var reader = new FileReader();
        reader.onload = function () {
            mediaGrid.innerHTML = '<div class="cms-media-empty"><span class="cms-spin"></span> Uploading…</div>';
            post('mediaupload', { data: reader.result, filename: file.name, alt: '' }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); loadMedia(''); return; }
                toast('Image uploaded.', 'ok');
                loadMedia('');
            }).catch(function () { toast('Network error.', 'error'); loadMedia(''); });
        };
        reader.readAsDataURL(file);
    }

    function wireMediaPicker() {
        mediaModal = document.getElementById('cmsMediaModal');
        mediaGrid = document.getElementById('cmsMediaGrid');
        mediaSearch = document.getElementById('cmsMediaSearch');
        mediaSearchBtn = document.getElementById('cmsMediaSearchBtn');
        uploadInput = document.getElementById('cmsUploadInput');
        uploadDrop = document.getElementById('cmsUploadDrop');
        if (!mediaModal) { return; }

        mediaSearchBtn.addEventListener('click', function () { loadMedia(mediaSearch.value.trim()); });
        mediaSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadMedia(mediaSearch.value.trim()); } });
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
    }

    /* ================= pristine check (for preset reseeding) ================= */
    function blockHasContent(b) {
        var f = b.fields || {};
        return Object.keys(f).some(function (k) {
            var v = f[k];
            if (v == null) { return false; }
            if (typeof v === 'string') { return v.trim() !== ''; }
            if (Array.isArray(v)) { return v.length > 0; }
            if (typeof v === 'object') { return Object.keys(v).length > 0; }
            return !!v;
        });
    }

    /* ================= public API ================= */
    function init(opts) {
        opts = opts || {};
        catalog   = Array.isArray(opts.catalog) ? opts.catalog : [];
        labels    = (opts.labels && typeof opts.labels === 'object') ? opts.labels : {};
        pageTypes = Array.isArray(opts.pageTypes) ? opts.pageTypes : [];
        canEdit   = !!opts.canEdit;
        if (typeof opts.onDirty === 'function') { onDirty = opts.onDirty; }
        if (opts.ajaxUrl) { AJAX = opts.ajaxUrl; }

        model = (Array.isArray(opts.blocks) ? opts.blocks : []).map(normBlock);

        listEl  = document.getElementById('cmsBlockList');
        emptyEl = document.getElementById('cmsBlockEmpty');
        toastEl = document.getElementById('cmsToast');

        confirmModal = document.getElementById('cmsConfirmModal');
        confirmTitle = document.getElementById('cmsConfirmTitle');
        confirmBody  = document.getElementById('cmsConfirmBody');
        confirmOk    = document.getElementById('cmsConfirmOk');
        if (confirmOk) {
            confirmOk.addEventListener('click', function () {
                var fn = confirmAction;
                confirmAction = null;
                if (fn) { fn(); }
            });
        }

        wireAddBlock();
        wireMediaPicker();
        renderList();
    }

    return {
        init: init,
        serialize: function () {
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
        },
        seedFromPreset: function (type) {
            var preset = presetBlocksFor(type);
            if (!preset) { return false; }
            model = preset.map(normBlock);
            renderList();
            markDirty();
            return true;
        },
        replaceModel: function (blocks) {
            model = (Array.isArray(blocks) ? blocks : []).map(normBlock);
            renderList();
        },
        isPristine: function () {
            return model.every(function (b) { return !blockHasContent(b); });
        },
        isEmpty: function () { return model.length === 0; },
        hasJsonError: function () {
            return model.some(function (b) { return b._jsonError; });
        },
        confirmDialog: confirmDialog,
        closeConfirm: function () { closeModal(confirmModal); },
        confirmOkEl: function () { return confirmOk; },
        // Open the shared media-library picker; cb receives the chosen media-ref.
        // Lets the host (e.g. a post hero image) reuse the same picker the block
        // image fields use, without duplicating upload/search wiring.
        pickMedia: function (cb) { openMediaPicker(cb); },
        toast: toast
    };
})();
</script>
