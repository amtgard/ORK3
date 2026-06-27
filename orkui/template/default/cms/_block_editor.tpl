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
        <div class="cms-empty-icon"><i class="fas fa-layer-group"></i></div>
        <div class="cms-empty-copy">No blocks yet. Add your first block.</div>
        <div class="cms-empty-cta">
            <button type="button" class="cms-btn cms-btn-primary cms-btn-sm" id="cmsAddBlockBtnEmpty"><i class="fas fa-plus"></i> Add block</button>
        </div>
    </div>
</div>

<?php /* ---- Add-block chooser modal ---- */ ?>
<div class="cms-modal-overlay" id="cmsAddModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-label="Choose a block">
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
    var blockAllow = {};        // page-type key -> [allowed block types]
    var pageType = '';          // current page type ('post' for blog bodies)
    var showAllBlocks = false;  // chooser "Show all blocks" toggle state
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

    /* ---- modal helpers + shared focus trap ----
     * On open we remember the element that triggered the modal, move focus into
     * the dialog (search field / first focusable), and trap Tab cycling inside.
     * On close we restore focus to the trigger. Esc-close is handled below. */
    var FOCUSABLE = 'a[href],button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),[tabindex]:not([tabindex="-1"])';

    function focusablesIn(elx) {
        return Array.prototype.filter.call(
            elx.querySelectorAll(FOCUSABLE),
            function (n) { return n.offsetWidth || n.offsetHeight || n.getClientRects().length; }
        );
    }

    function openModal(elx) {
        if (!elx) { return; }
        elx._returnFocus = (document.activeElement && document.activeElement !== document.body)
            ? document.activeElement : null;
        elx.classList.add('cms-open');
        // Move focus into the dialog: prefer a search input, else the first focusable.
        setTimeout(function () {
            var search = elx.querySelector('input[type="text"], input:not([type])');
            var first = search || focusablesIn(elx)[0];
            if (first) { try { first.focus(); } catch (e) {} }
        }, 30);
    }
    function closeModal(elx) {
        if (!elx) { return; }
        elx.classList.remove('cms-open');
        var rf = elx._returnFocus;
        elx._returnFocus = null;
        if (rf && document.contains(rf)) { try { rf.focus(); } catch (e) {} }
    }
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
            return;
        }
        if (e.key !== 'Tab') { return; }
        // Trap Tab inside the topmost open modal.
        var open = document.querySelector('.cms-modal-overlay.cms-open');
        if (!open) { return; }
        var items = focusablesIn(open);
        if (!items.length) { e.preventDefault(); return; }
        var firstEl = items[0], lastEl = items[items.length - 1];
        var active = document.activeElement;
        if (e.shiftKey) {
            if (active === firstEl || !open.contains(active)) { e.preventDefault(); lastEl.focus(); }
        } else {
            if (active === lastEl || !open.contains(active)) { e.preventDefault(); firstEl.focus(); }
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
        }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
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
            case 'staff_roster':
                return 'Staff Roster — ' + ((f.people || []).length) + ' people';
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
            case 'steps':
                return (f.heading ? strip(f.heading) + ' — ' : '') + ((f.steps || []).length) + ' step(s)';
            case 'photo_mosaic':
                return ((f.images || []).length) + ' image(s)';
            case 'table':
                return ((f.rows || []).length) + ' row(s)';
            case 'divider':
                return f.style || 'line';
            case 'spacer':
                return f.size || 'md';
            case 'raw_html':
                return f.html ? 'HTML set' : 'no HTML';
            case 'marketing_nav':
                return (f.cta && f.cta.label) ? strip(f.cta.label) : 'logo + buttons';
            case 'kingdoms_teaser':
            case 'events_feed':
            case 'blog_feed': {
                var hd = strip(f.heading || '');
                return hd ? ('live · ' + hd) : 'live data';
            }
            case 'member_bar':
            case 'stat_ticker':
            case 'tournaments_feed':
            case 'recap_highlight':
                return 'live data';
            case 'columns':
                return ((f.columns || []).length) + ' column(s)';
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
        var isDark = (document.documentElement.getAttribute('data-theme') === 'dark');
        tinymce.init({
            target: textarea,
            menubar: false,
            statusbar: true,            // surfaces the word count + resize handle
            // autoresize grows the editor with content instead of a fixed box.
            min_height: 220,
            max_height: 640,
            autoresize_bottom_margin: 16,
            plugins: 'lists link autolink autoresize wordcount fullscreen searchreplace charmap quickbars table image emoticons',
            toolbar: 'undo redo | blocks | bold italic underline strikethrough subscript superscript | '
                + 'bullist numlist | link image table blockquote hr | emoticons charmap | '
                + 'removeformat | searchreplace fullscreen',
            toolbar_mode: 'wrap',       // wrap tools onto multiple rows (vs. hiding behind "…")
            // Only the headings the sanitizer keeps (h2–h4); H1/H5/H6/pre would be
            // stripped on save, so don't offer them.
            block_formats: 'Paragraph=p; Heading 2=h2; Heading 3=h3; Heading 4=h4',
            // WYSIWYG truth: the sanitizer drops all inline styles, so forbid them
            // in the editor too (no orphaned colour/size/alignment that won't save).
            valid_styles: { '*': '' },
            // Links: https default, title field, new-tab option (sanitizer hardens
            // target=_blank → rel=noopener on save).
            link_default_protocol: 'https',
            link_title: true,
            link_context_toolbar: true,
            // Quick selection toolbar; suppress the empty-line insert toolbar.
            quickbars_selection_toolbar: 'bold italic underline | quicklink blockquote',
            quickbars_insert_toolbar: false,
            // Tables author clean (the sanitizer strips border/style; CSS skins them).
            table_toolbar: 'tableprops tabledelete | tableinsertrowbefore tableinsertrowafter tabledeleterow | '
                + 'tableinsertcolbefore tableinsertcolafter tabledeletecol',
            table_appearance_options: false,
            // Inline images come from the CMS media library, not pasted data URIs.
            paste_data_images: false,
            file_picker_types: 'image',
            file_picker_callback: function (cb) {
                openMediaPicker(function (m) {
                    if (m && m.src) { cb(m.src, { alt: m.alt || '' }); }
                });
            },
            skin: isDark ? 'oxide-dark' : 'oxide',
            content_css: isDark ? 'dark' : 'default',
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

    function repeater(block, key, singular, blank, itemRender, addLabel) {
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
            var add = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-plus"></i> ' + esc(addLabel || ('Add ' + singular)));
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
        if (tip) { b.setAttribute('aria-label', tip); }
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

    function tnFixedAcPosition(input, dropdown) {
        var r = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = (r.bottom + 2) + 'px';
        dropdown.style.width = r.width + 'px';
        dropdown.style.zIndex = '99999';
    }

    function personaLinkField(person) {
        var wrap = el('div', 'cms-field'); wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', 'Link Amtgard persona (optional)'));

        var chip = el('div', 'cms-persona-chip');
        function renderChip() {
            chip.innerHTML = '';
            if (person.mundane_id && person.mundane_id > 0) {
                chip.appendChild(el('span', null, esc('Linked: ' + (person.persona_name || ('#' + person.mundane_id)))));
                var unlink = el('button', 'cms-link-btn'); unlink.type = 'button'; unlink.textContent = 'Unlink';
                unlink.addEventListener('click', function () { person.mundane_id = 0; markDirty(); renderChip(); });
                chip.appendChild(unlink);
                chip.style.display = '';
            } else {
                chip.style.display = 'none';
            }
        }

        var input = el('input', 'cms-input'); input.type = 'text';
        input.placeholder = 'Search by persona or name…';
        var dd = el('div', 'kn-ac-results cms-persona-ac'); dd.style.display = 'none';
        document.body.appendChild(dd);

        var timer = null, ctrl = null;
        function closeDd() { dd.classList.remove('kn-ac-open'); dd.style.display = 'none'; }
        function showDd() { tnFixedAcPosition(input, dd); dd.style.display = 'block'; dd.classList.add('kn-ac-open'); }

        function pick(row) {
            person.mundane_id = parseInt(row.MundaneId, 10) || 0;
            person.persona_name = row.Persona || person.persona_name;
            input.value = ''; closeDd(); markDirty(); renderChip();
            fetch(UIR + 'CmsAjax/personlookup&mundane_id=' + person.mundane_id)
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        if (d.persona) { person.persona_name = d.persona; }
                        if (d.mundane_name) { person.mundane_name = d.mundane_name; }
                        markDirty();
                        renderList();
                    }
                })
                .catch(function () { /* names stay as typed; non-fatal */ });
        }

        function search(term) {
            if (ctrl) { ctrl.abort(); }
            ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            var url = UIR + 'KingdomAjax/playersearch/0&scope=all&include_inactive=1&q=' + encodeURIComponent(term);
            fetch(url, ctrl ? { signal: ctrl.signal } : undefined)
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (rows) {
                    dd.innerHTML = '';
                    if (!rows || !rows.length) {
                        dd.appendChild(el('div', 'kn-ac-item kn-ac-none', 'No matches')); showDd(); return;
                    }
                    rows.forEach(function (row) {
                        var loc = [row.KAbbr, row.PAbbr].filter(Boolean).join(':');
                        var item = el('div', 'kn-ac-item',
                            esc(row.Persona) + (loc ? ' <span class="kn-ac-meta">' + esc(loc) + '</span>' : ''));
                        item.addEventListener('mousedown', function (e) { e.preventDefault(); pick(row); });
                        dd.appendChild(item);
                    });
                    showDd();
                })
                .catch(function () { /* ignore aborted/failed search */ });
        }

        input.addEventListener('input', function () {
            var term = input.value.trim();
            if (timer) { clearTimeout(timer); }
            if (term.length < 2) { closeDd(); return; }
            timer = setTimeout(function () { search(term); }, 200);
        });
        input.addEventListener('blur', function () { setTimeout(closeDd, 150); });

        wrap.appendChild(chip);
        wrap.appendChild(input);
        renderChip();
        return wrap;
    }

    /* ---- bound primitive helpers used by the schema renderer ---- */
    function numberBound(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var inp = el('input', 'cms-input'); inp.type = 'number';
        if (ph) { inp.placeholder = ph; }
        inp.value = (obj[key] != null && obj[key] !== '') ? obj[key] : '';
        inp.addEventListener('input', function () {
            obj[key] = inp.value === '' ? '' : Number(inp.value);
            markDirty();
        });
        wrap.appendChild(inp);
        return wrap;
    }

    /* ================= declarative block-schema registry =================
     * Each entry is a list of field specs the generic renderer walks:
     *   { key, type, label, help?, placeholder?, options?, of? }
     * Supported field `type`s (all reuse the existing helpers below):
     *   'text'      single-line input
     *   'textarea'  multi-line input
     *   'mono'      monospace multi-line input (raw_html, table rows)
     *   'richtext'  TinyMCE editor
     *   'select'    dropdown (needs options:[{value,label}])
     *   'bool'      Yes/No dropdown (stored as 1/0)
     *   'number'    numeric input
     *   'url'       single-line input (semantic alias of text)
     *   'image'     media-library picker
     *   'group'     a small object of sub-fields (of:[specs]) → obj[key]={…}
     *   'repeater'  repeating list (of:[specs] for object items, or one image spec)
     *   'note'      static info paragraph (no data) — { html }
     * The renderer is buildBlockBody()'s default path; bespoke forms (hero,
     * card_grid, etc.) remain hand-built and take precedence over a schema. */
    var BLOCK_SCHEMA = {
        marketing_nav: [
            { key: 'logo', type: 'image', label: 'Logo' },
            { key: 'cta', type: 'group', label: 'Call-to-action button', of: [
                { key: 'label', type: 'text', label: 'Label', placeholder: 'e.g. Find a Park' },
                { key: 'href', type: 'url', label: 'Link (href)', placeholder: 'https://…' }
            ] },
            { key: 'login', type: 'group', label: 'Login button', of: [
                { key: 'label', type: 'text', label: 'Label', placeholder: 'e.g. Sign in' },
                { key: 'href', type: 'url', label: 'Link (href)', placeholder: 'https://…' }
            ] },
            { type: 'note', html: 'Menu links are managed in the <a href="' + esc(UIR) + 'Cms/nav">Navigation tab</a>. This block only controls the logo and the buttons above.' }
        ],
        steps: [
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'band', type: 'select', label: 'Background band', options: [
                { value: 'light', label: 'Light' }, { value: 'dark', label: 'Dark (navy)' }
            ] },
            { key: 'cta', type: 'group', label: 'Optional call-to-action', of: [
                { key: 'label', type: 'text', label: 'CTA label' },
                { key: 'href', type: 'url', label: 'CTA link', placeholder: 'https://…' }
            ] },
            { key: 'steps', type: 'repeater', label: 'Steps', singular: 'Step', of: [
                { key: 'n', type: 'number', label: 'Number', placeholder: 'e.g. 1' },
                { key: 'title', type: 'text', label: 'Title' },
                { key: 'body', type: 'textarea', label: 'Body' }
            ] }
        ],
        photo_mosaic: [
            { key: 'caption', type: 'text', label: 'Caption', help: 'Shown on the navy caption tile (first 4 images are laid out as a mosaic).' },
            { key: 'images', type: 'repeater', label: 'Images', singular: 'Image', of: '__image__' }
        ],
        divider: [
            { key: 'style', type: 'select', label: 'Style', options: [
                { value: 'line', label: 'Line' }, { value: 'dots', label: 'Dotted' }
            ] }
        ],
        spacer: [
            { key: 'size', type: 'select', label: 'Size', options: [
                { value: 'sm', label: 'Small' }, { value: 'md', label: 'Medium' }, { value: 'lg', label: 'Large' }
            ] }
        ],
        table: [
            { key: 'caption', type: 'text', label: 'Caption', placeholder: 'Optional table caption' },
            { key: 'header_first_row', type: 'bool', label: 'First row is a header' },
            { key: 'rows', type: 'table_rows', label: 'Rows',
              help: 'One row per line. Separate cells with a vertical bar  |  — e.g.  Column A | Column B | Column C' }
        ],
        raw_html: [
            { key: 'html', type: 'mono', label: 'HTML', help: 'Sanitized on save — unsafe tags/attributes are stripped.' }
        ],
        kingdoms_teaser: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max kingdoms shown', placeholder: '12' },
            { key: 'more_href', type: 'url', label: '“Browse all” link', placeholder: 'https://…' }
        ],
        events_feed: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: '“All events” link', placeholder: 'https://…' }
        ],
        blog_feed: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Latest News' },
            { key: 'limit', type: 'number', label: 'Max posts shown', placeholder: '3' },
            { key: 'tag', type: 'text', label: 'Filter by tag (optional)', placeholder: 'Leave blank for all posts' }
        ],
        member_bar: []  // pure info card; no knobs
    };

    /* dynamic block types render an info card (icon + description) above any knobs */
    var DYNAMIC_TYPES = {
        member_bar: true, kingdoms_teaser: true, events_feed: true, blog_feed: true,
        stat_ticker: true, tournaments_feed: true, recap_highlight: true
    };

    function catalogEntry(type) {
        for (var i = 0; i < (catalog || []).length; i++) {
            if (catalog[i] && catalog[i].type === type) { return catalog[i]; }
        }
        return null;
    }

    /* ---- info card for dynamic blocks (icon + one-line live description) ---- */
    function dynamicInfoCard(type) {
        var ent = catalogEntry(type) || {};
        var icon = ent.icon || 'fa-bolt';
        var desc = ent.description || 'This block pulls live data when the page is viewed.';
        var card = el('div', 'cms-dyninfo');
        card.appendChild(el('div', 'cms-dyninfo-icon', '<i class="fas ' + esc(icon) + '"></i>'));
        var txt = el('div', 'cms-dyninfo-text');
        txt.appendChild(el('div', 'cms-dyninfo-title', '<i class="fas fa-bolt"></i> Live block'));
        txt.appendChild(el('div', 'cms-dyninfo-body', esc(desc)));
        card.appendChild(txt);
        return card;
    }

    /* ---- table rows editor: textarea, one row/line, cells split on " | " ---- */
    function tableRowsField(block, spec) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(spec.label || 'Rows')));
        var ta = el('textarea', 'cms-textarea');
        ta.style.minHeight = '140px';
        ta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
        ta.placeholder = 'Column A | Column B | Column C\nRow 1 cell | Row 1 cell | Row 1 cell';
        // model rows (array of arrays) → text
        var rows = Array.isArray(block.fields[spec.key]) ? block.fields[spec.key] : [];
        ta.value = rows.map(function (r) {
            return (Array.isArray(r) ? r : []).map(function (c) { return String(c == null ? '' : c); }).join(' | ');
        }).join('\n');
        ta.addEventListener('input', function () {
            var lines = ta.value.split('\n');
            var out = [];
            lines.forEach(function (line) {
                if (line.trim() === '') { return; }
                out.push(line.split('|').map(function (c) { return c.trim(); }));
            });
            block.fields[spec.key] = out;
            markDirty();
        });
        wrap.appendChild(ta);
        if (spec.help) { wrap.appendChild(el('div', 'cms-help', spec.help)); }
        return wrap;
    }

    /* ---- one schema field → DOM, bound to `obj` (block.fields or a group obj) --- */
    function renderSchemaField(block, obj, spec) {
        var node;
        switch (spec.type) {
            case 'note':
                node = el('div', 'cms-note');
                node.innerHTML = '<i class="fas fa-info-circle"></i> <span>' + (spec.html || '') + '</span>';
                return node;

            case 'image':
                return imageBound(obj, spec.key, spec.label);

            case 'richtext':
                return fieldRich({ fields: obj }, spec.key, spec.label);

            case 'textarea':
                node = textBoundArea(obj, spec.key, spec.label, spec.placeholder);
                break;

            case 'mono': {
                node = el('div', 'cms-field');
                node.appendChild(el('label', 'cms-label', esc(spec.label)));
                var mta = el('textarea', 'cms-textarea');
                mta.style.minHeight = '180px';
                mta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
                if (spec.placeholder) { mta.placeholder = spec.placeholder; }
                mta.value = obj[spec.key] != null ? obj[spec.key] : '';
                mta.addEventListener('input', function () { obj[spec.key] = mta.value; markDirty(); });
                node.appendChild(mta);
                break;
            }

            case 'select':
                node = selectBound(obj, spec.key, spec.label, spec.options,
                    (spec.options && spec.options.length) ? spec.options[0].value : '');
                break;

            case 'bool': {
                var boolOpts = [{ value: '1', label: 'Yes' }, { value: '0', label: 'No' }];
                node = el('div', 'cms-field');
                node.appendChild(el('label', 'cms-label', esc(spec.label)));
                var bsel = el('select', 'cms-select');
                var cur = (obj[spec.key] === undefined) ? 1 : (obj[spec.key] ? 1 : 0);
                boolOpts.forEach(function (o) {
                    var op = el('option'); op.value = o.value; op.textContent = o.label;
                    if (String(cur) === o.value) { op.selected = true; }
                    bsel.appendChild(op);
                });
                obj[spec.key] = cur;
                bsel.addEventListener('change', function () { obj[spec.key] = Number(bsel.value); markDirty(); });
                node.appendChild(bsel);
                break;
            }

            case 'number':
                node = numberBound(obj, spec.key, spec.label, spec.placeholder);
                break;

            case 'table_rows':
                return tableRowsField({ fields: obj }, spec);

            case 'group': {
                if (!obj[spec.key] || typeof obj[spec.key] !== 'object' || Array.isArray(obj[spec.key])) {
                    obj[spec.key] = {};
                }
                var gwrap = el('div', 'cms-group');
                gwrap.appendChild(el('div', 'cms-label', esc(spec.label)));
                var inner = el('div', 'cms-group-body');
                (spec.of || []).forEach(function (sub) {
                    inner.appendChild(renderSchemaField(block, obj[spec.key], sub));
                });
                gwrap.appendChild(inner);
                node = gwrap;
                break;
            }

            case 'repeater': {
                var groupWrap = el('div', null);
                groupWrap.appendChild(el('div', 'cms-label', esc(spec.label)));
                if (spec.of === '__image__') {
                    // repeater of images: each item is a media-ref object
                    groupWrap.appendChild(repeater(block, spec.key, spec.singular || 'Image', {}, function (item, i) {
                        return imageBound(block.fields[spec.key], i, spec.singular || 'Image');
                    }));
                } else {
                    var blank = {};
                    (spec.of || []).forEach(function (sub) { blank[sub.key] = (sub.type === 'number') ? '' : ''; });
                    groupWrap.appendChild(repeater(block, spec.key, spec.singular || 'Item', blank, function (item) {
                        var ibox = el('div', null);
                        (spec.of || []).forEach(function (sub) {
                            ibox.appendChild(renderSchemaField(block, item, sub));
                        });
                        return ibox;
                    }));
                }
                node = groupWrap;
                break;
            }

            case 'url':
            case 'text':
            default:
                node = textBound(obj, spec.key, spec.label, spec.placeholder);
                break;
        }
        if (spec.help && node) { node.appendChild(el('div', 'cms-help', spec.help)); }
        return node;
    }

    /* ---- generic schema renderer: walk a schema, emit a friendly form ---- */
    function renderSchemaForm(schema, block, mount) {
        (schema || []).forEach(function (spec) {
            mount.appendChild(renderSchemaField(block, block.fields, spec));
        });
        return mount;
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
                    g.appendChild(textBound(card, 'icon', 'Icon (Font Awesome class, e.g. fa-shield-alt)'));
                    g.appendChild(textBound(card, 'href', 'Link (href)'));
                    box.appendChild(g);
                    box.appendChild(textBound(card, 'title', 'Title'));
                    box.appendChild(textBound(card, 'blurb', 'Blurb'));
                    return box;
                }));
            return body;
        }

        if (t === 'staff_roster') {
            body.appendChild(fieldText(block, 'kicker', 'Kicker'));
            body.appendChild(fieldText(block, 'heading', 'Heading'));
            body.appendChild(fieldText(block, 'subheading', 'Subheading'));
            body.appendChild(fieldSelect(block, 'presentation', 'Presentation style',
                [{ value: 'amtgard', label: 'Amtgard name leads' },
                 { value: 'mundane', label: 'Real name leads' }], 'amtgard'));
            body.appendChild(el('div', 'cms-help', 'Choose which name leads on every card. Link a persona to auto-fill names; you can still edit them.'));
            body.appendChild(el('div', 'cms-label', 'People'));
            body.appendChild(repeater(block, 'people', 'Person',
                { image: {}, persona_name: '', mundane_name: '', role: '', bio: '', mundane_id: 0, href: '' },
                function (person) {
                    var box = el('div', null);
                    box.appendChild(imageBound(person, 'image', 'Photo'));
                    box.appendChild(personaLinkField(person));
                    box.appendChild(textBound(person, 'persona_name', 'Amtgard name'));
                    box.appendChild(textBound(person, 'mundane_name', 'Real name'));
                    box.appendChild(textBound(person, 'role', 'Role / title'));
                    box.appendChild(textBoundArea(person, 'bio', 'Bio'));
                    box.appendChild(textBound(person, 'href', 'Manual link (used only if no persona is linked)'));
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

        // ----- DYNAMIC blocks: live info card + any genuine knobs -----
        if (DYNAMIC_TYPES[t]) {
            body.appendChild(dynamicInfoCard(t));
            if (BLOCK_SCHEMA[t] && BLOCK_SCHEMA[t].length) {
                renderSchemaForm(BLOCK_SCHEMA[t], block, body);
            }
            return body;
        }

        // ----- Schema-driven friendly form (authored blocks w/ a schema) -----
        if (BLOCK_SCHEMA[t]) {
            renderSchemaForm(BLOCK_SCHEMA[t], block, body);
            return body;
        }

        // ----- columns: nested-block editing is genuinely hard → advanced JSON -----
        if (t === 'columns') {
            body.appendChild(jsonField(block, 'Columns — advanced',
                'Each column is a list of blocks. Nested-block editing isn’t available here yet — edit the column structure as JSON. Parsed on save; invalid JSON keeps the last valid value.'));
            return body;
        }

        // ----- LAST-RESORT JSON fallback (unknown / not-yet-shipped types) -----
        body.appendChild(jsonField(block, 'Fields (JSON)',
            'This block type has no friendly form yet — edit its fields as JSON. It is parsed on save; invalid JSON keeps the last valid value.'));
        return body;
    }

    /* ---- toggle a block card's error state + quiet inline message ---- *
     * Finds the rendered card for this block and reflects block._jsonError
     * without a full rerender (keeps the textarea focus + caret intact). */
    function reflectBlockError(block) {
        var idx = model.indexOf(block);
        if (idx < 0 || !listEl) { return; }
        var cards = listEl.querySelectorAll('.cms-block-card');
        var card = cards[idx];
        if (!card) { return; }
        card.classList.toggle('cms-block-error', !!block._jsonError);
        if (card._errMsg) { card._errMsg.style.display = block._jsonError ? '' : 'none'; }
    }

    /* ---- shared JSON editor field (columns-advanced + last-resort fallback) ---- */
    function jsonField(block, label, help) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', label));
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
                } else {
                    throw new Error('not an object');
                }
            } catch (err) {
                ta.style.borderColor = 'var(--ork-badge-red-text)';
                block._jsonError = true;
            }
            reflectBlockError(block);
            markDirty();
        });
        wrap.appendChild(ta);
        wrap.appendChild(el('div', 'cms-help', help));
        return wrap;
    }

    /* ---- icon for a block type (from the catalog) ---- */
    function iconFor(type) {
        var ent = catalogEntry(type);
        return (ent && ent.icon) ? ent.icon : 'fa-cube';
    }

    /* ---- a thin hover-reveal "+" inserter zone that opens the chooser at idx --- */
    function inserterZone(idx) {
        var zone = el('div', 'cms-inserter');
        zone.setAttribute('data-tip', 'Insert a block here');
        var btn = el('button', 'cms-inserter-btn', '<i class="fas fa-plus"></i>');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Insert a block here');
        btn.addEventListener('click', function () { openAddChooser(idx); });
        zone.appendChild(btn);
        return zone;
    }

    /* ---- render the whole block list ---- */
    function renderList() {
        destroyTinyIn(listEl);
        listEl.innerHTML = '';
        emptyEl.style.display = model.length ? 'none' : '';

        if (model.length) { listEl.appendChild(inserterZone(0)); }

        model.forEach(function (block, idx) {
            var card = el('div', 'cms-block-card' + (block.enabled ? '' : ' cms-block-disabled') + (block._jsonError ? ' cms-block-error' : ''));
            // draggable is enabled only while the drag handle is pressed (wireDrag),
            // so text selection inside field inputs never starts a card drag.
            card.setAttribute('draggable', 'false');

            var head = el('div', 'cms-block-head');

            var handle = el('span', 'cms-drag-handle', '<i class="fas fa-grip-vertical"></i>');
            handle.setAttribute('data-tip', 'Drag to reorder');
            head.appendChild(handle);

            var collapseBtn = iconBtn('fa-chevron-down', 'Collapse / expand', false);
            head.appendChild(collapseBtn);
            head.appendChild(el('span', 'cms-block-icon', '<i class="fas ' + esc(iconFor(block.type)) + '"></i>'));
            head.appendChild(el('span', 'cms-block-type', esc(labelFor(block.type))));
            head.appendChild(el('span', 'cms-block-typekey', esc(block.type)));
            head.appendChild(el('span', 'cms-block-summary', esc(summarize(block))));

            var tools = el('div', 'cms-block-tools');
            var up = iconBtn('fa-arrow-up', 'Move up', idx === 0);
            var down = iconBtn('fa-arrow-down', 'Move down', idx === model.length - 1);
            up.addEventListener('click', function () { swap(model, idx, idx - 1); renderList(); markDirty(); });
            down.addEventListener('click', function () { swap(model, idx, idx + 1); renderList(); markDirty(); });

            var dup = iconBtn('fa-clone', 'Duplicate block', false);
            dup.addEventListener('click', function () { duplicateBlock(idx); });

            var sw = el('label', 'cms-switch');
            var cb = el('input'); cb.type = 'checkbox'; cb.checked = block.enabled;
            function syncSwitchAria() {
                cb.setAttribute('aria-label', cb.checked
                    ? 'Block enabled, click to disable'
                    : 'Block disabled, click to enable');
            }
            syncSwitchAria();
            cb.addEventListener('change', function () {
                block.enabled = cb.checked;
                card.classList.toggle('cms-block-disabled', !block.enabled);
                syncSwitchAria();
                markDirty();
            });
            sw.appendChild(cb);
            sw.appendChild(el('span', 'cms-slider'));

            var del = iconBtn('fa-trash', 'Delete block', false, true);
            del.addEventListener('click', function () { askDeleteBlock(idx); });

            tools.appendChild(up);
            tools.appendChild(down);
            tools.appendChild(dup);
            tools.appendChild(sw);
            tools.appendChild(del);
            head.appendChild(tools);
            card.appendChild(head);

            var body = el('div', 'cms-block-body');
            // quiet inline error message (shown only when this block blocks autosave)
            var errMsg = el('div', 'cms-block-error-msg', '<i class="fas fa-exclamation-triangle"></i> <span>This block has invalid input and won’t be saved until it’s fixed.</span>');
            errMsg.style.display = block._jsonError ? '' : 'none';
            card._errMsg = errMsg;
            body.appendChild(errMsg);
            body.appendChild(buildBlockBody(block));
            card.appendChild(body);

            collapseBtn.addEventListener('click', function () {
                body.classList.toggle('cms-collapsed');
                var icon = collapseBtn.querySelector('i');
                if (icon) { icon.className = body.classList.contains('cms-collapsed') ? 'fas fa-chevron-right' : 'fas fa-chevron-down'; }
            });

            wireDrag(card, handle, idx);

            listEl.appendChild(card);
            listEl.appendChild(inserterZone(idx + 1));
        });

        listEl.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
    }

    /* ================= HTML5 drag-and-drop reorder ================= */
    var dragFromIdx = null;
    function wireDrag(card, handle, idx) {
        // Only the handle initiates a drag (keeps text selection in field inputs).
        handle.addEventListener('mousedown', function () { card.setAttribute('draggable', 'true'); });
        handle.addEventListener('mouseup', function () { card.setAttribute('draggable', 'false'); });
        card.addEventListener('dragstart', function (e) {
            dragFromIdx = idx;
            card.classList.add('cms-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(idx)); } catch (err) {}
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('cms-dragging');
            card.setAttribute('draggable', 'false');
            listEl.querySelectorAll('.cms-drag-over').forEach(function (n) { n.classList.remove('cms-drag-over'); });
            dragFromIdx = null;
        });
        card.addEventListener('dragover', function (e) {
            if (dragFromIdx === null) { return; }
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
            card.classList.add('cms-drag-over');
        });
        card.addEventListener('dragleave', function () { card.classList.remove('cms-drag-over'); });
        card.addEventListener('drop', function (e) {
            e.preventDefault();
            card.classList.remove('cms-drag-over');
            if (dragFromIdx === null || dragFromIdx === idx) { return; }
            var moved = model.splice(dragFromIdx, 1)[0];
            var dest = (dragFromIdx < idx) ? idx - 1 : idx;
            model.splice(dest, 0, moved);
            dragFromIdx = null;
            renderList();
            markDirty();
        });
    }

    /* ---- duplicate a block (deep copy of its fields) at idx+1 ---- */
    function duplicateBlock(idx) {
        var src = model[idx];
        if (!src) { return; }
        var copy = {
            type:    src.type,
            enabled: src.enabled,
            source:  src.source,
            fields:  JSON.parse(JSON.stringify(src.fields || {}))
        };
        model.splice(idx + 1, 0, copy);
        renderList();
        markDirty();
        toast('Block duplicated.', 'ok');
    }

    /* ---- confirm modal (delete block; also reused by host for delete page/post) ---- */
    var confirmModal, confirmTitle, confirmBody, confirmOk;
    var confirmAction = null;

    function confirmDialog(title, body, okLabel, fn) {
        if (!confirmTitle || !confirmBody || !confirmOk) { return; }
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

    /* ================= Add block ================= *
     * The chooser is searchable + grouped + icon'd, and can insert a new block
     * at a specific index (insertAt). insertAt === null → append at the end. */
    var addModal, addGroupsEl, addSearchEl, addNoMatchEl, addShowAllWrap, addShowAllBtn;
    var addInsertAt = null;   // index to splice at, or null to append

    // Stable group order for the chooser sections.
    var GROUP_ORDER = ['Layout', 'Content', 'Media', 'Dynamic', 'Advanced'];

    function insertNewBlock(c) {
        var nb = {
            type: c.type,
            enabled: true,
            source: c.dynamic ? 'dynamic' : 'authored',
            fields: {}
        };
        if (addInsertAt === null || addInsertAt < 0 || addInsertAt > model.length) {
            model.push(nb);
        } else {
            model.splice(addInsertAt, 0, nb);
        }
        closeModal(addModal);
        renderList();
        markDirty();
        // scroll the newly-added card into view
        var cards = listEl.querySelectorAll('.cms-block-card');
        var pos = (addInsertAt === null) ? cards.length - 1 : addInsertAt;
        if (cards[pos]) { cards[pos].scrollIntoView({ behavior: 'smooth', block: 'center' }); }
    }

    function typeCard(c) {
        var cardBtn = el('button', 'cms-typecard' + (c.available ? '' : ' cms-typecard-disabled'));
        cardBtn.type = 'button';
        if (!c.available) { cardBtn.disabled = true; }
        var icoHtml = '<span class="cms-typecard-icon"><i class="fas ' + esc(c.icon || 'fa-cube') + '"></i></span>';
        var badge = c.available
            ? (c.dynamic ? '<span class="cms-typecard-badge cms-badge-dynamic">live</span>' : '')
            : '<span class="cms-typecard-badge cms-badge-soon">coming soon</span>';
        cardBtn.innerHTML =
            icoHtml +
            '<span class="cms-typecard-text">' +
                '<strong>' + esc(c.label) + badge + '</strong>' +
                '<span class="cms-typecard-key">' + esc(c.type) + '</span>' +
            '</span>';
        if (c.available) {
            cardBtn.addEventListener('click', function () { insertNewBlock(c); });
        }
        return cardBtn;
    }

    // The set of block types sensible for the current page type. Empty/unknown
    // → allow everything (no scoping). Universal blocks are part of each list.
    function allowedTypeSet() {
        var arr = (blockAllow && blockAllow[pageType]) ? blockAllow[pageType] : null;
        if (!arr || !arr.length) { return null; } // null → no restriction
        var set = {};
        arr.forEach(function (t) { set[t] = true; });
        return set;
    }

    function renderAddChooser(filter) {
        addGroupsEl.innerHTML = '';
        var q = (filter || '').trim().toLowerCase();

        // All addable catalog entries (legacy/non-addable always excluded).
        var addable = (catalog || []).filter(function (c) { return c.addable !== false; });

        // Scope to the page type unless searching or "Show all" is on. When the
        // user is typing a query we search across ALL blocks so anything is
        // findable; the scope only governs the default browse view.
        var allowed = allowedTypeSet();
        var scoped = allowed && !q && !showAllBlocks;
        var hiddenCount = 0;
        var list = addable.filter(function (c) {
            if (q) {
                return String(c.label || '').toLowerCase().indexOf(q) !== -1
                    || String(c.type || '').toLowerCase().indexOf(q) !== -1;
            }
            if (scoped && !allowed[c.type]) { hiddenCount++; return false; }
            return true;
        });

        // bucket by group, preserving GROUP_ORDER then any extras alphabetically
        var buckets = {};
        list.forEach(function (c) {
            var g = c.group || 'Other';
            (buckets[g] = buckets[g] || []).push(c);
        });
        var groups = Object.keys(buckets).sort(function (a, b) {
            var ia = GROUP_ORDER.indexOf(a), ib = GROUP_ORDER.indexOf(b);
            if (ia === -1 && ib === -1) { return a.localeCompare(b); }
            if (ia === -1) { return 1; }
            if (ib === -1) { return -1; }
            return ia - ib;
        });

        var any = false;
        groups.forEach(function (g) {
            var items = buckets[g];
            if (!items.length) { return; }
            any = true;
            var sec = el('div', 'cms-typegroup');
            sec.appendChild(el('div', 'cms-typegroup-title', esc(g)));
            var grid = el('div', 'cms-typegrid');
            items.forEach(function (c) { grid.appendChild(typeCard(c)); });
            sec.appendChild(grid);
            addGroupsEl.appendChild(sec);
        });

        addNoMatchEl.style.display = any ? 'none' : '';

        // "Show all blocks" affordance — only when scoping actually hid some and
        // we're not searching. Re-render expanded (or re-scoped) on click.
        if (addShowAllWrap && addShowAllBtn) {
            if (!q && allowed && (showAllBlocks || hiddenCount > 0)) {
                addShowAllWrap.style.display = '';
                addShowAllBtn.innerHTML = showAllBlocks
                    ? '<i class="fas fa-chevron-up"></i> Show only blocks suited to this page'
                    : '<i class="fas fa-chevron-down"></i> Show all blocks (' + hiddenCount + ' more)';
            } else {
                addShowAllWrap.style.display = 'none';
            }
        }
    }

    function openAddChooser(insertAt) {
        addInsertAt = (insertAt === undefined) ? null : insertAt;
        showAllBlocks = false; // always reopen in scoped view
        if (addSearchEl) { addSearchEl.value = ''; }
        renderAddChooser('');
        openModal(addModal);
        if (addSearchEl) { setTimeout(function () { addSearchEl.focus(); }, 30); }
    }

    function wireAddBlock() {
        addModal       = document.getElementById('cmsAddModal');
        addGroupsEl    = document.getElementById('cmsAddGroups');
        addSearchEl    = document.getElementById('cmsAddSearch');
        addNoMatchEl   = document.getElementById('cmsAddNoMatch');
        addShowAllWrap = document.getElementById('cmsAddShowAllWrap');
        addShowAllBtn  = document.getElementById('cmsAddShowAll');
        var addBtn      = document.getElementById('cmsAddBlockBtn');
        var addBtnEmpty = document.getElementById('cmsAddBlockBtnEmpty');
        if (!addModal || !addGroupsEl) { return; }

        if (addShowAllBtn) {
            addShowAllBtn.addEventListener('click', function () {
                showAllBlocks = !showAllBlocks;
                renderAddChooser(addSearchEl ? addSearchEl.value : '');
            });
        }

        if (addBtn)      { addBtn.addEventListener('click', function () { openAddChooser(null); }); }
        if (addBtnEmpty) { addBtnEmpty.addEventListener('click', function () { openAddChooser(null); }); }
        if (addSearchEl) {
            addSearchEl.addEventListener('input', function () { renderAddChooser(addSearchEl.value); });
            addSearchEl.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') { return; }
                e.preventDefault();
                // Pickable cards are the enabled (available + addable) type cards.
                var pickable = addGroupsEl.querySelectorAll('.cms-typecard:not(:disabled)');
                if (pickable.length === 1) {
                    pickable[0].click();           // exactly one match → pick it
                } else if (pickable.length > 1) {
                    pickable[0].focus();           // many → move keyboard focus to the first
                }
            });
        }
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
        // AJAX already ends in '...?Route=CmsAjax/', so the query must be joined
        // with '&' — a second '?' would corrupt the Route param (empties $_GET).
        var url = AJAX + 'medialist' + (q ? '&' + new URLSearchParams({ q: q }).toString() : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
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

        if (mediaSearchBtn) {
            mediaSearchBtn.addEventListener('click', function () { loadMedia(mediaSearch.value.trim()); });
        }
        if (mediaSearch) {
            mediaSearch.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); loadMedia(mediaSearch.value.trim()); } });
        }
        if (uploadInput) {
            uploadInput.addEventListener('change', function () { doUpload(uploadInput.files[0]); uploadInput.value = ''; });
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
        blockAllow = (opts.blockAllow && typeof opts.blockAllow === 'object') ? opts.blockAllow : {};
        pageType  = (typeof opts.pageType === 'string') ? opts.pageType : '';
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
        setPageType: function (type) {
            pageType = (typeof type === 'string') ? type : '';
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
