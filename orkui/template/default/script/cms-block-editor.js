/* ============================================================================
 * cms-block-editor.js — shared block-body editor engine (pages + posts).
 *
 * window.CmsBlockEditor. Lifted verbatim out of cms/_block_editor.tpl (the C27
 * extraction seam): the body was already 100% static, so it now lives here as a
 * lintable/testable asset. Its only server-provided values arrive via
 * window.CmsBlockEditorBoot, still emitted inline by that partial immediately
 * before this file is loaded — SYNCHRONOUSLY, because both host templates
 * (Cms_edit.tpl / Cms_editpost.tpl) call CmsBlockEditor.init(opts) from a later
 * inline <script> once the DOM is ready.
 *
 * Modal open/close is delegated to the shared CmsAdmin.modal controller
 * (script/cms-admin.js) — this file installs NO document-level listeners.
 * post() stays local on purpose: it THROWS on a non-OK HTTP status, which the
 * editor's save/publish flows rely on. CmsAdmin.post does not.
 * ========================================================================== */
window.CmsBlockEditor = (function () {
    'use strict';

    var BOOT = window.CmsBlockEditorBoot || {};
    var UIR  = BOOT.UIR || '';
    var AJAX = UIR + 'CmsAjax/';

    var model = [];
    var catalog = [];
    var labels = {};
    var pageTypes = [];
    var tagCatalog = [];        // C22: existing tags [{slug,name,post_count}] for blog_feed picker
    var blockAllow = {};        // page-type key -> [allowed block types]
    var pageType = '';          // current page type ('post' for blog bodies)
    var showAllBlocks = false;  // chooser "Show all blocks" toggle state
    var addGroupCollapsed = {}; // chooser: per-group collapsed state (by group name)
    var onDirty = function () {};

    var listEl, emptyEl, collapseAllBtn;

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

    /* ---- toast (shared: CmsAdmin.toast — identical behaviour, one source) ----
     * post() is intentionally NOT shared: this editor relies on it REJECTING on a
     * non-OK HTTP status (CmsAdmin.post resolves regardless), so it stays local. */
    function toast(msg, kind) {
        if (window.CmsAdmin && CmsAdmin.toast) { CmsAdmin.toast(msg, kind); }
    }

    /* ---- modal helpers (shared: CmsAdmin.modal) ----
     * The open/close focus handling, the [data-close-modal]/backdrop click
     * delegate, Esc-close and the Tab focus trap all live in cms-admin.js. This
     * editor used to install its OWN document-level click + keydown listeners on
     * top of those, so two controllers ran per event and Esc vs. the X button
     * restored focus to different elements. Delegating removes the race. */
    function openModal(elx) {
        // preferTextInput reproduces this editor's original private helper: focus on a
        // short tick, preferring the first text input. The add-block chooser depends on
        // it (typing must filter immediately). It is opt-in so the admin modals that
        // already used CmsAdmin.modal keep their previous synchronous focus behaviour.
        if (window.CmsAdmin && CmsAdmin.modal) { CmsAdmin.modal.open(elx, { preferTextInput: true }); }
    }
    function closeModal(elx) {
        if (window.CmsAdmin && CmsAdmin.modal) { CmsAdmin.modal.close(elx); }
    }

    /* ---- POST helper ---- */
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

    function markDirty() {
        // Collapsed cards navigate by their summary line, so it has to track the
        // edit that just happened — including an edit made in a SIBLING block
        // (a columns child, say) that shows up in another card's summary.
        if (listEl) { try { refreshSummaries(); } catch (e) {} }
        try { onDirty(); } catch (e) {}
    }

    /* ================= block model ================= */
    /* NOTE: 'rich_text' is the canonical block type; 'richtext' is a legacy DB alias.
       Both spellings are accepted on read (see summarize() / buildBlockBody()). */
    function normBlock(b) {
        return {
            // C15: carry the stable server row id so a save round-trips it and the
            // ReplaceBlocks upsert preserves the row (rather than delete+reinsert,
            // which would churn ids and lose per-block history). New/duplicated/
            // preset blocks have no id → 0, so the server assigns a fresh row.
            id:      (b.id != null && b.id !== '') ? (parseInt(b.id, 10) || 0) : 0,
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

    /* ---- short human summary for the block card header ----
     * Blocks now start COLLAPSED, so this line is the author's ONLY view of what
     * is inside a block until they open it — it has to be populated for every
     * type, never empty and never just the block's own name. summarize() is the
     * entry point: it takes the hand-written line where there is one, and falls
     * back to the first text the author actually typed where there isn't. */
    function summarize(block) {
        var s = summarizeRaw(block);
        if (typeof s === 'string' && s.trim() !== '') { return s; }
        var alt = firstTextIn(block.fields || {});
        return alt !== '' ? alt : 'Not filled in yet';
    }

    // A live/dynamic block quotes no authored copy — say what it will PUT on the
    // page, prefixed with the author's own heading when they set one.
    function liveSummary(f, what) {
        var hd = strip(f.heading || f.kicker || '');
        return hd !== '' ? (hd + ' · live ' + what) : ('live · ' + what);
    }

    // Last-resort summary: quote the first text the author actually typed. The
    // preferred keys are tried in author-visible order first, then any remaining
    // string field, so a block type with no hand-written case above still gets a
    // real line instead of "custom fields (JSON)".
    var SUMMARY_KEYS = ['heading', 'title', 'text', 'label', 'name', 'caption',
        'kicker', 'subheading', 'subcopy', 'quote', 'body', 'html'];
    // Fields the author PICKS from a fixed set (dropdown / toggle / preset) rather
    // than types, plus the URL-ish ones. The catch-all loop below must never quote
    // either: an empty Heading block summarised as "left" — its align value — and
    // an empty Rich Text did the same, because the catch-all beat the
    // "Not filled in yet" answer those blocks should have fallen through to. A
    // route is no more a description of a block than a config token is.
    var CONFIG_KEYS = {
        align: 1, style: 1, size: 1, band: 1, level: 1, variant: 1, layout: 1,
        presentation: 1, sort: 1, provider: 1, theme: 1, mode: 1, format: 1,
        position: 1, direction: 1, ratio: 1, width: 1, columns: 1, kind: 1,
        target: 1, rel: 1, status: 1, type: 1, icon: 1, scope: 1, source: 1, tag: 1
    };
    // Suffix rule so per-field variants (cta_href, more_href, max_width, video_id,
    // placeholder_image_src, …) are covered without listing every one.
    var CONFIG_SUFFIX = /(^|_)(href|url|src|id|class|slug|width|color|colour|align|style|size|level|variant|layout)$/;
    function isConfigKey(k) {
        return Object.prototype.hasOwnProperty.call(CONFIG_KEYS, k) || CONFIG_SUFFIX.test(k);
    }
    function firstTextIn(f) {
        var i, k, v;
        if (!f || typeof f !== 'object') { return ''; }
        for (i = 0; i < SUMMARY_KEYS.length; i++) {
            v = f[SUMMARY_KEYS[i]];
            if (typeof v === 'string' && v.trim() !== '') {
                return (SUMMARY_KEYS[i] === 'body' || SUMMARY_KEYS[i] === 'html') ? stripHtml(v) : strip(v);
            }
        }
        for (k in f) {
            if (!Object.prototype.hasOwnProperty.call(f, k)) { continue; }
            if (isConfigKey(k)) { continue; }
            v = f[k];
            if (typeof v === 'string' && v.trim() !== '') { return strip(v); }
        }
        return '';
    }

    function summarizeRaw(block) {
        var f = block.fields || {};
        switch (block.type) {
            case 'rich_text':
            case 'richtext':
                return f.heading ? strip(f.heading) : stripHtml(f.body || '');
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
            // Divider and Spacer hold no author copy by nature, so there is nothing
            // to quote — but "line" and "md" were the stored config tokens, not a
            // summary. Say what the block PUTS on the page, the way the live blocks
            // do, so a collapsed row still reads as a sentence.
            case 'divider':
                return (String(f.style || 'line') === 'dots')
                    ? 'a dotted rule between sections'
                    : 'a solid rule between sections';
            case 'spacer': {
                var sz = String(f.size || 'md');
                return 'a ' + (sz === 'sm' ? 'small' : (sz === 'lg' ? 'large' : 'medium'))
                    + ' gap between blocks';
            }
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
                return 'live data';
            case 'columns':
                return ((f.columns || []).length) + ' column(s)';
            // Kingdom-/park-scoped live blocks. These fell through to the old
            // "custom fields (JSON)" default — dev jargon, and identical on all
            // eight, so a collapsed park page read as eight anonymous rows.
            case 'kingdom_officers':
            case 'park_officers':
                return liveSummary(f, 'officer list');
            case 'kingdom_parks':
                return liveSummary(f, 'park list');
            case 'kingdom_parks_map':
                return liveSummary(f, 'park map');
            case 'kingdom_events':
            case 'park_events':
                return liveSummary(f, 'upcoming events');
            case 'park_meeting':
                return liveSummary(f, 'meeting times & directions');
            case 'park_hero':
                return liveSummary(f, 'park crest & next game day');
            default:
                return firstTextIn(f);
        }
    }
    // Block fields other than `body`/`html` are PLAIN TEXT on write (CmsPage::HTML_FIELDS
    // is only body+html), so they must never be parsed as HTML on read — a detached
    // div + innerHTML still fires <img onerror>. Purely textual, no DOM construction.
    function strip(s) {
        var t = String(s || '').replace(/\s+/g, ' ').trim();
        return t.length > 60 ? t.slice(0, 60) + '…' : t;
    }
    // rich_text's `body` is the one genuinely-HTML field (CmsSanitizer-cleaned). Drop
    // its tags textually so the summary isn't raw markup — still never via innerHTML.
    // Entities must be decoded too: TinyMCE defaults to entity_encoding 'named', so a
    // body routinely holds &amp;/&mdash;/&nbsp;, and the seed data already does. Without
    // decoding, esc() at the consumer double-escapes and the card shows "&amp;amp;".
    // Single pass over the source string, so "&amp;lt;" cannot cascade into a real "<".
    // Punctuation + the Latin-1 letters. The accented set is not optional: TinyMCE's
    // 'named' encoding turns every non-ASCII letter into a named entity, so an author
    // typing "café" stores "caf&eacute;" and a punctuation-only map would render the
    // summary as literal "caf&eacute;". Built from the HTML4 Latin-1 block so European
    // persona and park names survive. Anything unmapped is left as-is, never mangled.
    var STRIP_ENT = (function () {
        var m = {
            nbsp: ' ', amp: '&', lt: '<', gt: '>', quot: '"', apos: "'",
            mdash: '—', ndash: '–', hellip: '…', bull: '•', middot: '·',
            rsquo: '’', lsquo: '‘', ldquo: '“', rdquo: '”', deg: '°',
            copy: '©', reg: '®', trade: '™', laquo: '«', raquo: '»'
        };
        // HTML4 Latin-1 letters, U+00C0–U+00FF, in code-point order.
        var names = ('Agrave Aacute Acirc Atilde Auml Aring AElig Ccedil Egrave Eacute '
            + 'Ecirc Euml Igrave Iacute Icirc Iuml ETH Ntilde Ograve Oacute Ocirc Otilde '
            + 'Ouml times Oslash Ugrave Uacute Ucirc Uuml Yacute THORN szlig '
            + 'agrave aacute acirc atilde auml aring aelig ccedil egrave eacute '
            + 'ecirc euml igrave iacute icirc iuml eth ntilde ograve oacute ocirc otilde '
            + 'ouml divide oslash ugrave uacute ucirc uuml yacute thorn yuml').split(' ');
        // Keyed by EXACT name — &Eacute; (É) and &eacute; (é) are different characters,
        // so this table must never be looked up case-insensitively.
        for (var i = 0; i < names.length; i++) { m[names[i]] = String.fromCharCode(0xC0 + i); }
        return m;
    })();
    function stripHtml(s) {
        return strip(String(s || '')
            .replace(/<[^>]*>/g, ' ')
            .replace(/&#(\d+);/g, function (_, d) { return String.fromCodePoint(+d); })
            .replace(/&#x([0-9a-f]+);/gi, function (_, h) { return String.fromCodePoint(parseInt(h, 16)); })
            // Case-EXACT, per the table's own contract above. Folding the key resolves
            // every uppercase name to its lowercase sibling's code point — &AElig; came
            // out as 'æ', &THORN; as 'þ', &Eacute; as 'é' — which case-flattens exactly
            // the Norse/Old-English names the Latin-1 table was added for. TinyMCE emits
            // the ASCII-punctuation names (rsquo, ldquo, copy, ...) in canonical
            // lowercase and the table keys those that way, so nothing needs folding.
            .replace(/&([a-zA-Z]+);/g, function (m, n) {
                return Object.prototype.hasOwnProperty.call(STRIP_ENT, n) ? STRIP_ENT[n] : m;
            }));
    }

    /* ================= TinyMCE ================= */
    var tinyReady = (typeof tinymce !== 'undefined');
    var tinyCounter = 0;
    var tinyDegradedWarned = false;

    function currentIsDark() {
        return document.documentElement.getAttribute('data-theme') === 'dark';
    }
    // Skin the editor at construction time; tracked so a runtime theme flip can
    // detect the change and reinit (TinyMCE can't hot-swap skin/content_css).
    var lastTinyDark = currentIsDark();

    function initTiny(textarea) {
        if (!tinyReady || !textarea) { return; }
        // Idempotent: lazy body-building means a textarea can be reached by both
        // the per-body init and a caller's batch init. A second tinymce.init() on
        // the same target would stack a duplicate editor over the first.
        if (textarea.id && tinymce.get(textarea.id)) { return; }
        var isDark = currentIsDark();
        lastTinyDark = isDark;
        tinymce.init({
            target: textarea,
            // TinyMCE 7 is GPL-or-commercial. Without this it assumes a commercial
            // trial and logs "TinyMCE is running in evaluation mode" to the console
            // on every editor load — once per rich-text block, so a page with four
            // of them logs four times. 'gpl' declares we're using it under the open
            // source licence, which is how it ships here (self-hosted / CDN build,
            // no Tiny Cloud key). Purely a declaration; no behaviour changes.
            license_key: 'gpl',
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

    /* ---- C31: reinit open editors when the app theme flips at runtime ----
     * initTiny freezes skin/content_css at construction, so a light↔dark toggle
     * would otherwise leave stale editor chrome until a full renderList. On a real
     * theme change we save each open editor's content, remove it, and rebuild it —
     * which re-reads currentIsDark(). Caret/scroll reset is acceptable for a
     * deliberate, infrequent theme toggle (and only affects rich-text fields). */
    function reinitTinySkins() {
        if (!tinyReady || !tinymce.editors) { return; }
        var isDark = currentIsDark();
        if (isDark === lastTinyDark) { return; }
        lastTinyDark = isDark;
        var textareas = [];
        tinymce.editors.slice().forEach(function (ed) {
            try { ed.save(); } catch (e) {}
            var ta = ed.targetElm || document.getElementById(ed.id);
            if (ta) { textareas.push(ta); }
        });
        textareas.forEach(function (ta) {
            var ed = tinymce.get(ta.id);
            if (ed) { ed.remove(); }
        });
        textareas.forEach(function (ta) { initTiny(ta); });
    }

    function observeTheme() {
        if (typeof MutationObserver === 'undefined') { return; }
        var obs = new MutationObserver(function (muts) {
            for (var i = 0; i < muts.length; i++) {
                if (muts[i].attributeName === 'data-theme') { reinitTinySkins(); break; }
            }
        });
        obs.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    }

    /* ---- C25: warn (once) when the TinyMCE bundle failed to load, so authors
     * know a rich-text field has silently degraded to a raw-HTML textarea and
     * don't unknowingly save mangled markup. ---- */
    // `scope` is the container that was JUST built — block bodies are built
    // lazily, so at the end of renderList() nothing rich-text exists in listEl
    // yet and a listEl-only check could never fire.
    function warnTinyDegradedIfNeeded(scope) {
        if (tinyReady || tinyDegradedWarned) { return; }
        var root = scope || listEl;
        if (!root || !root.querySelector('textarea[data-tiny]')) { return; }
        tinyDegradedWarned = true;
        toast('Rich-text editor didn’t load — those fields show raw HTML. Check your connection before saving.', 'error');
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

    // Block-level select === selectBound over block.fields, minus the 8px
    // margin the bound helpers add inside repeater rows.
    function fieldSelect(block, key, label, options, dflt) {
        return selectBound(block.fields, key, label, options, dflt, true);
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
        // C25: if TinyMCE never loaded, this is a raw-HTML textarea — say so inline.
        if (!tinyReady) {
            wrap.appendChild(el('div', 'cms-help-warn',
                '<i class="fas fa-exclamation-triangle"></i> <span>Rich-text editing is unavailable (the editor didn’t load). '
                + 'You’re editing raw HTML — save with care.</span>'));
        }
        return wrap;
    }

    /* ---- E3: the three states of an image field must be TELLABLE APART ----
     * "No image chosen", "here is the image" and "the file this block points at
     * is gone" used to render as two states plus the browser's broken-image
     * glyph — and that glyph looks identical to a thumbnail that merely failed
     * to reach this one request, so an author could not tell a page that is
     * fine from a page that is publishing a broken image.
     *
     * A missing file gets the shared CmsAdmin.thumbFallback placeholder (the
     * same treatment Cms_media.tpl uses on the media grid) plus a caption that
     * says what it means for the public site. */
    var MISSING_IMG_TIP = 'This image is missing from the media library — it will be broken on the public site too.';

    function fieldImage(container, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var row = el('div', 'cms-media-field');

        var ref = (container[key] && typeof container[key] === 'object') ? container[key] : null;
        // An empty {} ref is "no image chosen" — only treat it as selected when it
        // actually carries an image (thumb/src). Gates the name label + Clear button.
        var hasImage = ref && (ref.thumb || ref.src);

        // Declared before buildThumb so its error handler can rewrite the caption.
        var nameEl = el('div', 'cms-media-name', hasImage ? esc(ref.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>');
        function markMissing() {
            nameEl.innerHTML = '<span class="cms-missing-label"><i class="fas fa-exclamation-triangle" aria-hidden="true"></i> Image missing</span>';
            nameEl.setAttribute('data-tip', MISSING_IMG_TIP);
        }
        function buildThumb(r) {
            if (r && (r.thumb || r.src)) {
                var img = el('img', 'cms-media-thumb');
                img.alt = '';
                img.addEventListener('error', function () {
                    if (window.CmsAdmin && CmsAdmin.thumbFallback) {
                        CmsAdmin.thumbFallback(img, 'cms-media-thumb', { icon: 'fa-unlink', tip: MISSING_IMG_TIP });
                    }
                    markMissing();
                });
                img.src = r.thumb || r.src;
                return img;
            }
            return el('div', 'cms-media-thumb cms-empty-thumb', '<i class="fas fa-image" aria-hidden="true"></i>');
        }

        var thumb = buildThumb(ref);

        var meta = el('div', 'cms-media-meta');
        var btnRow = el('div', null);
        btnRow.style.marginTop = '6px';
        var chooseBtn = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-image"></i> Choose image');
        chooseBtn.type = 'button';
        var clearBtn = el('button', 'cms-btn cms-btn-sm cms-btn-ghost', 'Clear');
        clearBtn.type = 'button';
        clearBtn.style.marginLeft = '6px';
        if (!hasImage) { clearBtn.style.display = 'none'; }

        function render(newRef) {
            container[key] = newRef || {};
            var newHasImage = newRef && (newRef.thumb || newRef.src);
            var fresh = buildThumb(newRef);
            row.replaceChild(fresh, thumb);
            thumb = fresh;
            nameEl.removeAttribute('data-tip');
            nameEl.innerHTML = newHasImage ? esc(newRef.alt || 'Selected image') : '<span class="cms-muted">No image selected</span>';
            clearBtn.style.display = newHasImage ? '' : 'none';
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

        // rebuild() re-renders every item, so the control the author just used
        // is gone from the DOM and focus lands back on <body>. Put a keyboard
        // author back on the equivalent control of the item they acted on.
        function refocusTool(idx, ord) {
            var box = (idx >= 0) ? wrap.children[idx] : null;
            var btns = box ? box.querySelectorAll('.cms-block-tools .cms-icon-btn') : null;
            var target = (btns && btns[ord] && !btns[ord].disabled) ? btns[ord] : null;
            if (!target && btns) {
                for (var k = 0; k < btns.length; k++) {
                    if (!btns[k].disabled) { target = btns[k]; break; }
                }
            }
            if (!target) { target = wrap.lastChild; }   // the Add button
            if (target && target.focus) { target.focus(); }
        }

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
                up.addEventListener('click', function () { swap(arr, i, i - 1); rebuild(); refocusTool(i - 1, 0); markDirty(); });
                down.addEventListener('click', function () { swap(arr, i, i + 1); rebuild(); refocusTool(i + 1, 1); markDirty(); });
                del.addEventListener('click', function () { arr.splice(i, 1); rebuild(); refocusTool(Math.min(i, arr.length - 1), 2); markDirty(); });
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
                rebuild();
                var box = wrap.children[arr.length - 1];
                var first = box ? box.querySelector('input, textarea, select') : null;
                if (first) { first.focus(); } else { refocusTool(arr.length - 1, 0); }
                markDirty();
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
            // Not a .cms-grid2 row any more: the link control is a full-width
            // chooser, so the label and the link stack instead of sharing a row.
            box.appendChild(textBound(cta, 'label', 'Label'));
            box.appendChild(linkBound(cta, 'href', 'Where the button goes'));
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
    function selectBound(obj, key, label, options, dflt, noMargin) {
        var wrap = el('div', 'cms-field');
        if (!noMargin) { wrap.style.marginBottom = '8px'; }
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var sel = el('select', 'cms-select');
        // A stored '' or 0 is a real choice, not "unset" — || would paint the
        // default as selected while the model still held the author's value.
        var cur = (obj[key] != null && obj[key] !== '') ? obj[key] : dflt;
        options.forEach(function (o) {
            var op = el('option'); op.value = o.value; op.textContent = o.label;
            if (cur === o.value) { op.selected = true; }
            sel.appendChild(op);
        });
        if (obj[key] == null) { obj[key] = dflt; }
        sel.addEventListener('change', function () { obj[key] = sel.value; markDirty(); });
        wrap.appendChild(sel);
        return wrap;
    }
    function imageBound(obj, key, label) {
        if (!obj[key] || typeof obj[key] !== 'object') { obj[key] = {}; }
        return fieldImage(obj, key, label);
    }

    /* ---- icon picker (V1) -------------------------------------------------
     * A card icon used to be a raw CSS class an author had to know and type
     * ("Icon (Font Awesome class, e.g. fa-shield-alt)"). This shows the actual
     * rendered glyph plus a plain-English name instead.
     *
     * The set is DERIVED from what the product already uses — the per-block
     * icons in CmsBlockRegistry::BlockDefs() and the glyphs the front-door
     * templates ship — trimmed to the ones that suit Amtgard content. Every
     * name below was checked against the Font Awesome build the shell actually
     * loads (5.8.2 free, `fas`), because a name that only exists in a later
     * release renders as an empty box. NOTE: the registry's own 'fa-icons'
     * (used for the photo_mosaic block) is NOT in 5.8.2 and is deliberately
     * absent here.
     *
     * The free-text escape hatch stays for an author who knows the code they
     * want, but it sits UNDER the swatches, not in front of them, and it is
     * labelled as the advanced option it is. The vendor's name is mentioned
     * exactly once, in the help line — never in the swatch tooltips (a button
     * already reading "Shield" gained nothing from a tip reading
     * "fa-shield-alt"), never in the placeholder, never as the input's
     * accessible name. */
    var ICON_CHOICES = [
        { c: 'fa-shield-alt',      n: 'Shield' },
        { c: 'fa-crown',           n: 'Crown' },
        { c: 'fa-khanda',          n: 'Crossed swords' },
        { c: 'fa-fist-raised',     n: 'Raised fist' },
        { c: 'fa-dragon',          n: 'Dragon' },
        { c: 'fa-hat-wizard',      n: 'Wizard hat' },
        { c: 'fa-scroll',          n: 'Scroll' },
        { c: 'fa-book-open',       n: 'Rulebook' },
        { c: 'fa-map-marker-alt',  n: 'Location pin' },
        { c: 'fa-map',             n: 'Map' },
        { c: 'fa-calendar-day',    n: 'Calendar' },
        { c: 'fa-campground',      n: 'Campground' },
        { c: 'fa-users',           n: 'People' },
        { c: 'fa-user-shield',     n: 'Officer' },
        { c: 'fa-handshake',       n: 'Welcome' },
        { c: 'fa-trophy',          n: 'Trophy' },
        { c: 'fa-medal',           n: 'Award' },
        { c: 'fa-landmark',        n: 'Kingdom' },
        { c: 'fa-gavel',           n: 'Rules' },
        { c: 'fa-bullhorn',        n: 'Announcement' },
        { c: 'fa-envelope',        n: 'Contact' },
        { c: 'fa-question-circle', n: 'Questions' },
        { c: 'fa-download',        n: 'Download' },
        { c: 'fa-camera',          n: 'Photos' },
        { c: 'fa-images',          n: 'Gallery' },
        { c: 'fa-info-circle',     n: 'Information' }
    ];

    var iconPickSeq = 0;

    function iconBound(obj, key, label) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));

        var freeId = 'cmsIconCode' + (++iconPickSeq);
        var grid = el('div', 'cms-iconpick-grid');
        var free = el('input', 'cms-input');
        free.type = 'text';
        free.id = freeId;
        free.placeholder = 'e.g. fa-dragon';
        var freeLabel = el('label', 'cms-label', 'Or type an icon code (advanced)');
        freeLabel.setAttribute('for', freeId);
        var preview = el('span', 'cms-iconpick-preview');

        function current() {
            return String(obj[key] == null ? '' : obj[key]).trim();
        }

        function paint() {
            var v = current();
            grid.querySelectorAll('.cms-iconswatch').forEach(function (b) {
                var on = (b.getAttribute('data-icon') || '') === v;
                b.classList.toggle('cms-iconswatch-active', on);
                b.setAttribute('aria-pressed', on ? 'true' : 'false');
            });
            if (free.value.trim() !== v) { free.value = v; }
            preview.innerHTML = v
                ? '<i class="fas ' + esc(v) + '" aria-hidden="true"></i>'
                : '<i class="fas fa-ban" aria-hidden="true"></i>';
        }

        function choose(v) {
            obj[key] = v;
            markDirty();
            paint();
        }

        // "No icon" leads the grid so clearing is one click, not a text wipe.
        var none = el('button', 'cms-iconswatch cms-iconswatch-none',
            '<i class="fas fa-ban" aria-hidden="true"></i><span>No icon</span>');
        none.type = 'button';
        none.setAttribute('data-icon', '');
        none.addEventListener('click', function () { choose(''); });
        grid.appendChild(none);

        ICON_CHOICES.forEach(function (ic) {
            var b = el('button', 'cms-iconswatch',
                '<i class="fas ' + esc(ic.c) + '" aria-hidden="true"></i><span>' + esc(ic.n) + '</span>');
            b.type = 'button';
            b.setAttribute('data-icon', ic.c);
            // No tooltip: the <span> below the glyph already names the icon, and
            // it is that <span> the swatch takes its accessible name from. A tip
            // here could only repeat the label or leak the CSS class.
            b.addEventListener('click', function () { choose(ic.c); });
            grid.appendChild(b);
        });

        free.addEventListener('input', function () {
            obj[key] = free.value.trim();
            markDirty();
            paint();
        });

        var customRow = el('div', 'cms-iconpick-custom');
        customRow.appendChild(preview);
        customRow.appendChild(free);

        wrap.appendChild(grid);
        wrap.appendChild(freeLabel);
        wrap.appendChild(customRow);
        wrap.appendChild(el('div', 'cms-help',
            'Only needed for an icon that is not in the grid. Codes come from the Font Awesome 5 solid set.'));
        paint();
        return wrap;
    }

    /* ---- link chooser (V2) ------------------------------------------------
     * Every link field in this editor used to be a bare text input labelled
     * "Link (href)". The value a real page actually carries is
     *   /orkui/index.php?Route=Page/view/mission
     * i.e. the author's workflow was: open the target page, copy the framework
     * route out of the address bar, paste it here. That is a developer's URL,
     * not something a park officer should ever have to see.
     *
     * linkBound() offers two modes instead:
     *   "A page on this site"  — the pages in the CURRENT SCOPE by TITLE,
     *                            searchable; picking one stores the same URL
     *                            shape that has always worked.
     *   "Another address"      — free text, for links off this site.
     *
     * Round-trip: a stored href that matches a page in this scope opens in page
     * mode with that page highlighted; anything else opens in address mode
     * showing the raw value untouched. A field with nothing in it yet (or the
     * placeholder '#') opens in page mode, because that is the answer the author
     * wants nine times in ten — free text must not be the primary control. */

    // Per-scope cache: opening ten choosers costs ONE request. Keyed by the
    // scope selector so a scope switch (which reloads the page anyway) can never
    // serve another org's page list out of a stale cache.
    var pageListCache = {};
    var pageListReq   = {};

    function loadPageList() {
        var key = String(window.CMS_SCOPE || '');
        if (pageListCache[key]) { return Promise.resolve(pageListCache[key]); }
        if (pageListReq[key]) { return pageListReq[key]; }
        pageListReq[key] = fetch(
            AJAX + 'pagelist' + (key ? '&scope=' + encodeURIComponent(key) : ''),
            { credentials: 'same-origin' }
        )
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (d) {
                var rows = (d && d.ok && Array.isArray(d.pages)) ? d.pages : [];
                pageListCache[key] = rows;
                return rows;
            })
            .catch(function () {
                // Do NOT cache a failure — a transient error would otherwise
                // leave every chooser in this session permanently empty.
                delete pageListReq[key];
                return [];
            });
        return pageListReq[key];
    }

    // Compare hrefs origin-insensitively: the server builds absolute URLs from
    // UIR, while values seeded/typed earlier are often site-relative
    // ('/orkui/index.php?Route=Page/view/mission'). Same page, different string.
    function normHref(u) {
        var s = String(u == null ? '' : u).trim();
        return s.replace(/^[a-z][a-z0-9+.-]*:\/\/[^/]*/i, '');
    }

    function linkBound(obj, key, label, ph) {
        var wrap = el('div', 'cms-field');
        wrap.style.marginBottom = '8px';
        wrap.appendChild(el('label', 'cms-label', esc(label)));

        var modes = el('div', 'cms-seg cms-linkpick-modes');
        var pageBtn = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-file"></i> A page on this site');
        pageBtn.type = 'button';
        var addrBtn = el('button', 'cms-btn cms-btn-sm', '<i class="fas fa-globe"></i> Another address');
        addrBtn.type = 'button';
        modes.appendChild(pageBtn);
        modes.appendChild(addrBtn);
        wrap.appendChild(modes);

        var pagePane = el('div', 'cms-linkpick-pane');
        var filter = el('input', 'cms-input cms-linkpick-filter');
        filter.type = 'text';
        filter.placeholder = 'Search pages by name…';
        filter.setAttribute('aria-label', 'Search pages by name');
        var list = el('div', 'cms-linkpick-list');
        pagePane.appendChild(filter);
        pagePane.appendChild(list);

        var addrPane = el('div', 'cms-linkpick-pane');
        var addr = el('input', 'cms-input');
        addr.type = 'text';
        addr.placeholder = ph || 'https://…';
        addr.value = obj[key] != null ? obj[key] : '';
        addr.addEventListener('input', function () { obj[key] = addr.value; markDirty(); });
        addrPane.appendChild(addr);
        addrPane.appendChild(el('div', 'cms-help',
            'For somewhere off this site — a Facebook group, a Google form, another kingdom.'));

        wrap.appendChild(pagePane);
        wrap.appendChild(addrPane);

        function setMode(m) {
            var isPage = (m === 'page');
            pageBtn.classList.toggle('cms-seg-active', isPage);
            addrBtn.classList.toggle('cms-seg-active', !isPage);
            pageBtn.setAttribute('aria-pressed', isPage ? 'true' : 'false');
            addrBtn.setAttribute('aria-pressed', isPage ? 'false' : 'true');
            pagePane.style.display = isPage ? '' : 'none';
            addrPane.style.display = isPage ? 'none' : '';
        }
        pageBtn.addEventListener('click', function () { setMode('page'); });
        addrBtn.addEventListener('click', function () { setMode('address'); });

        // Start in address mode so a chooser whose page list never arrives still
        // shows the author the value they have. paint() moves it once the list
        // resolves.
        setMode('address');
        list.appendChild(el('div', 'cms-linkpick-empty', 'Loading pages…'));

        function paint(rows) {
            var term = filter.value.trim().toLowerCase();
            var cur = normHref(obj[key]);
            list.innerHTML = '';
            var shown = 0;
            var hidden = 0;
            rows.forEach(function (p) {
                var title = String(p.title || p.slug || '');
                if (term
                    && title.toLowerCase().indexOf(term) < 0
                    && String(p.slug || '').toLowerCase().indexOf(term) < 0) {
                    return;
                }
                // Cap the rendered list — a site with hundreds of pages would
                // otherwise rebuild hundreds of buttons on every keystroke.
                if (shown >= 50) { hidden++; return; }
                shown++;
                var opt = el('button', 'cms-linkpick-opt',
                    '<span class="cms-linkpick-opt-title">' + esc(title) + '</span>'
                    + '<span class="cms-linkpick-opt-meta">'
                    + esc(p.status === 'published' ? 'Published' : 'Not published yet')
                    + '</span>');
                opt.type = 'button';
                if (p.href && normHref(p.href) === cur) {
                    opt.classList.add('cms-linkpick-opt-active');
                    opt.setAttribute('aria-current', 'true');
                }
                opt.addEventListener('click', function () {
                    obj[key] = String(p.href || '');
                    addr.value = obj[key];
                    markDirty();
                    paint(rows);
                });
                list.appendChild(opt);
            });
            if (!shown) {
                list.appendChild(el('div', 'cms-linkpick-empty',
                    rows.length ? 'No pages match that search.' : 'No pages on this site yet.'));
            } else if (hidden) {
                list.appendChild(el('div', 'cms-linkpick-empty',
                    esc(hidden + ' more page' + (hidden === 1 ? '' : 's') + ' — refine your search.')));
            }
        }

        loadPageList().then(function (rows) {
            var paintTimer = null;
            filter.addEventListener('input', function () {
                if (paintTimer) { clearTimeout(paintTimer); }
                paintTimer = setTimeout(function () { paint(rows); }, 150);
            });
            paint(rows);
            var cur = normHref(obj[key]);
            var known = false;
            for (var i = 0; i < rows.length; i++) {
                if (rows[i].href && normHref(rows[i].href) === cur) { known = true; break; }
            }
            // '#' is the blank-CTA placeholder this editor has always seeded, so
            // it counts as "nothing chosen yet", not as a raw address.
            var empty = (cur === '' || cur === '#');
            setMode((known || (empty && rows.length)) ? 'page' : 'address');
        });

        return wrap;
    }

    function tnFixedAcPosition(input, dropdown) {
        var r = input.getBoundingClientRect();
        dropdown.style.position = 'fixed';
        dropdown.style.left = r.left + 'px';
        dropdown.style.top = (r.bottom + 2) + 'px';
        dropdown.style.width = r.width + 'px';
        dropdown.style.zIndex = '99999';
    }

    // ONE body-appended autocomplete dropdown for the whole editor instance. The body
    // append is required so tnFixedAcPosition can position:fixed it outside the
    // repeater's overflow context, but the repeater's rebuild() (wrap.innerHTML = '')
    // only detaches the cards — a per-person dropdown would leak one node, with live
    // listeners, on every add/remove/reorder. Created once, repositioned per input.
    var personaDd = null;
    function personaAcDropdown() {
        if (!personaDd || !personaDd.parentNode) {
            personaDd = el('div', 'kn-ac-results cms-persona-ac');
            personaDd.style.display = 'none';
            document.body.appendChild(personaDd);
        }
        return personaDd;
    }

    function personaLinkField(person, onResolve) {
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
        var dd = personaAcDropdown();

        var timer = null, ctrl = null;
        function closeDd() { dd.classList.remove('kn-ac-open'); dd.style.display = 'none'; }
        function showDd() { tnFixedAcPosition(input, dd); dd.style.display = 'block'; dd.classList.add('kn-ac-open'); }

        function pick(row) {
            person.mundane_id = parseInt(row.MundaneId, 10) || 0;
            person.persona_name = row.Persona || person.persona_name;
            input.value = ''; closeDd(); markDirty(); renderChip();
            fetch(AJAX + 'personlookup&mundane_id=' + person.mundane_id + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : ''))
                .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        if (d.persona) { person.persona_name = d.persona; }
                        if (d.mundane_name) { person.mundane_name = d.mundane_name; }
                        markDirty();
                        renderChip();
                        // Refresh just the two bound name inputs (no full
                        // renderList — that would tear down TinyMCE in every
                        // other block on the page).
                        if (typeof onResolve === 'function') { onResolve(); }
                    }
                })
                .catch(function () { /* names stay as typed; non-fatal */ });
        }

        function search(term) {
            if (ctrl) { ctrl.abort(); }
            ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
            // Scope the persona search to the current CMS site (same as personlookup /
            // medialist) instead of scope=all — cross-org scope=all leaked banned/inactive
            // personas into the picker. Drop include_inactive=1 for the same reason.
            var url = UIR + 'KingdomAjax/playersearch/0'
                + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '')
                + '&q=' + encodeURIComponent(term);
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
        // The dropdown is a SINGLETON shared by every persona field, so a search
        // this field started must not land (and reopen the dropdown) after the
        // author has moved to another row: cancel both the pending debounce and
        // the in-flight request on blur.
        input.addEventListener('blur', function () {
            if (timer) { clearTimeout(timer); timer = null; }
            if (ctrl) { ctrl.abort(); ctrl = null; }
            setTimeout(closeDd, 150);
        });

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

    /* ---- C22: validated tag picker (blog_feed) — a select over EXISTING tags
     * instead of a free-text field (a typo silently rendered an empty feed). Warns
     * inline when the chosen tag currently has no posts, and preserves any stored
     * legacy free-text value as a flagged "unknown tag" option rather than dropping
     * it. Binds obj[key] to the tag SLUG (what ListPosts filters on). ---- */
    function tagPickerField(obj, key, label, help) {
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', esc(label)));
        var cur = (obj[key] != null) ? String(obj[key]) : '';
        var sel = el('select', 'cms-select');

        var opt0 = el('option'); opt0.value = ''; opt0.textContent = 'All posts (no tag filter)';
        if (cur === '') { opt0.selected = true; }
        sel.appendChild(opt0);

        var known = {};
        (tagCatalog || []).forEach(function (t) {
            var slug = String(t.slug || '');
            if (!slug) { return; }
            known[slug] = t;
            var op = el('option');
            op.value = slug;
            op.textContent = (t.name || slug) + ' (' + (Number(t.post_count) || 0) + ')';
            if (cur === slug) { op.selected = true; }
            sel.appendChild(op);
        });
        // A stored value not in the current tag library (legacy free-text/typo):
        // keep it selectable so a save doesn't silently discard it, but flag it.
        if (cur !== '' && !known[cur]) {
            var opX = el('option');
            opX.value = cur; opX.textContent = cur + ' — unknown tag';
            opX.selected = true;
            sel.appendChild(opX);
        }

        var warn = el('div', 'cms-help-warn');
        warn.style.display = 'none';
        warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> <span></span>';
        function refreshWarn() {
            var v = sel.value, msg = '';
            if (v !== '') {
                if (!known[v]) {
                    msg = 'No tag named “' + v + '” exists — this feed will render empty until a post uses it.';
                } else if ((Number(known[v].post_count) || 0) === 0) {
                    msg = 'The “' + (known[v].name || v) + '” tag has no published posts yet — this feed will render empty for now.';
                }
            }
            if (msg) { warn.querySelector('span').textContent = msg; warn.style.display = ''; }
            else { warn.style.display = 'none'; }
        }
        sel.addEventListener('change', function () { obj[key] = sel.value; markDirty(); refreshWarn(); });
        if (obj[key] == null) { obj[key] = cur; }

        wrap.appendChild(sel);
        wrap.appendChild(warn);
        if (help) { wrap.appendChild(el('div', 'cms-help', help)); }
        refreshWarn();
        return wrap;
    }

    /* ---- checkbox bound to obj[key] (stored as a JS boolean) ---- */
    function checkBound(obj, key, label, help) {
        var wrap = el('div', 'cms-field'); wrap.style.marginBottom = '8px';
        var lab = el('label', 'cms-check-inline');
        var cb = el('input'); cb.type = 'checkbox';
        cb.checked = !!obj[key];
        if (obj[key] === undefined) { obj[key] = false; }
        cb.addEventListener('change', function () { obj[key] = cb.checked; markDirty(); });
        lab.appendChild(cb);
        lab.appendChild(document.createTextNode(' ' + label));
        wrap.appendChild(lab);
        if (help) { wrap.appendChild(el('div', 'cms-help', help)); }
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
                { key: 'href', type: 'url', label: 'Where the button goes', placeholder: 'https://…' }
            ] },
            { key: 'login', type: 'group', label: 'Login button', of: [
                { key: 'label', type: 'text', label: 'Label', placeholder: 'e.g. Sign in' },
                { key: 'href', type: 'url', label: 'Where the button goes', placeholder: 'https://…' }
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
                { key: 'href', type: 'url', label: 'Where the button goes', placeholder: 'https://…' }
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
            { key: 'header_first_row', type: 'bool', label: 'First row is a header',
              help: 'On by default. When “Yes”, the first row you enter becomes bold column headers so screen-reader users hear which column each value belongs to. Turn it off only for a table that has no header row.' },
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
            { key: 'more_href', type: 'url', label: 'Where “Browse all” goes', placeholder: 'https://…' }
        ],
        events_feed: [
            { key: 'heading', type: 'text', label: 'Heading' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: 'Where “All events” goes', placeholder: 'https://…' }
        ],
        blog_feed: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Latest News' },
            { key: 'limit', type: 'number', label: 'Max posts shown', placeholder: '3' },
            { key: 'tag', type: 'tagpicker', label: 'Filter by tag (optional)',
              help: 'Pick from tags that already exist. “All posts” shows every published post.' }
        ],
        kingdom_officers: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Our Officers' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max officers shown', placeholder: '12' }
        ],
        // park_hero is the FIRST block on every park home page. Without a schema
        // here a dynamic block renders the live-info card and stops, so this one
        // was uneditable — an officer could not touch the eyebrow, the headline,
        // the call-to-action or the weather readout on their own front page.
        // Keys mirror exactly what park_hero.tpl reads; anything not listed is
        // pulled live from the park's ORK record and is not an editable field.
        park_hero: [
            { key: 'heading', type: 'text', label: 'Headline',
              help: 'Leave blank to use the park’s own name.' },
            { key: 'kicker', type: 'text', label: 'Eyebrow',
              help: 'The small line above the name. Leave blank to use the park’s rank and kingdom, e.g. “Shire · Burning Lands”.' },
            { key: 'cta_label', type: 'text', label: 'Button label', placeholder: 'Plan your first visit' },
            { key: 'cta_href', type: 'text', label: 'Button link', placeholder: '#pk-meet',
              help: 'Defaults to #pk-meet, which jumps to the “When & Where We Meet” block further down this page.' },
            { key: 'show_weather', type: 'bool', label: 'Show the forecast for the next game day',
              help: 'Only appears when the next game day is within a week and a reading exists — it never shows a guess.' },
            { key: 'placeholder_image', type: 'image', label: 'Background photo (optional)',
              help: 'Sits faintly behind the crest. The hero is designed to look finished with no photo at all, so leave it empty unless you have a good wide shot of your park.' }
        ],
        park_officers: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Our Officers' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max officers shown', placeholder: '12' }
        ],
        park_meeting: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'When & Where We Meet' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'show_map', type: 'bool', label: 'Show a “Get directions” link',
              help: 'Uses the map link on the park day, or builds one from the address.' },
            { key: 'show_directions', type: 'bool', label: 'Include the park’s written directions',
              help: 'The “Directions” text from the park’s ORK record, shown under the meeting times.' },
            { key: 'limit', type: 'number', label: 'Max meeting days shown', placeholder: '6' }
        ],
        park_events: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Upcoming Events' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: 'Where “All events” goes', placeholder: 'https://…' }
        ],
        kingdom_parks: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Our Parks' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'sort', type: 'select', label: 'Sort order', options: [
                { value: 'name', label: 'Park name (A–Z)' },
                { value: 'city', label: 'City, then park name' },
                { value: 'state', label: 'State, then city, then park name' }
            ] },
            { key: 'show_heraldry', type: 'bool', label: 'Display park heraldry' },
            { key: 'limit', type: 'number', label: 'Max parks shown', placeholder: '24' },
            { key: 'more_href', type: 'url', label: 'Where “All parks” goes', placeholder: 'https://…' }
        ],
        kingdom_parks_map: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Park Map' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' }
        ],
        kingdom_events: [
            { key: 'heading', type: 'text', label: 'Heading', placeholder: 'Upcoming Events' },
            { key: 'kicker', type: 'text', label: 'Kicker', placeholder: 'Small label above heading' },
            { key: 'limit', type: 'number', label: 'Max events shown', placeholder: '3' },
            { key: 'more_href', type: 'url', label: 'Where “All events” goes', placeholder: 'https://…' }
        ],
        member_bar: []  // pure info card; no knobs
    };

    function catalogEntry(type) {
        for (var i = 0; i < (catalog || []).length; i++) {
            if (catalog[i] && catalog[i].type === type) { return catalog[i]; }
        }
        return null;
    }

    /* Dynamic block types render an info card (icon + description) above any knobs.
     * The flag is authoritative on the server-supplied catalog entry
     * (Controller_Cms::_blockCatalogMeta tuple slot 2 -> `dynamic`), so there is no
     * second hand-maintained list here to drift out of sync with it. */
    function isDynamicType(type) {
        var ent = catalogEntry(type);
        return !!(ent && ent.dynamic);
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
                // '0' is a stored No (PHP's !empty() reads it that way); plain
                // JS truthiness would flip it to Yes and — with the old
                // unconditional write-back — silently overwrite the author's
                // prior choice just by opening the block. Coercion mirrors PHP's
                // !empty() (null/'' read as No). Only an ABSENT/null value is
                // seeded, so the model matches what the select shows.
                var cur = (obj[spec.key] === undefined) ? 1
                    : ((!obj[spec.key] || obj[spec.key] === '0') ? 0 : 1);
                boolOpts.forEach(function (o) {
                    var op = el('option'); op.value = o.value; op.textContent = o.label;
                    if (String(cur) === o.value) { op.selected = true; }
                    bsel.appendChild(op);
                });
                if (obj[spec.key] == null) { obj[spec.key] = cur; }
                bsel.addEventListener('change', function () { obj[spec.key] = Number(bsel.value); markDirty(); });
                node.appendChild(bsel);
                break;
            }

            case 'number':
                node = numberBound(obj, spec.key, spec.label, spec.placeholder);
                break;

            case 'tagpicker':
                // Self-contained (renders its own help/warning) → return directly.
                return tagPickerField(obj, spec.key, spec.label, spec.help);

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
                    (spec.of || []).forEach(function (sub) { blank[sub.key] = ''; });
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

            // 'url' is a LINK — a page on this site, or an address elsewhere.
            // It gets the chooser; 'text' (and the default) stay plain inputs.
            case 'url':
                node = linkBound(obj, spec.key, spec.label, spec.placeholder);
                break;

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
    function buildBlockBody(block, errorOwner) {
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
            ctaWrap.appendChild(el('div', 'cms-label', 'Optional button'));
            ctaWrap.appendChild(textBound(block.fields.cta, 'label', 'Button label'));
            ctaWrap.appendChild(linkBound(block.fields.cta, 'href', 'Where the button goes'));
            body.appendChild(ctaWrap);
            return body;
        }

        if (t === 'image') {
            if (!block.fields.image || typeof block.fields.image !== 'object') { block.fields.image = {}; }
            body.appendChild(fieldImage(block.fields, 'image', 'Image'));
            body.appendChild(fieldText(block, 'caption', 'Caption', { placeholder: 'Optional caption' }));
            body.appendChild(linkBound(block.fields, 'href', 'Where the image links to (optional)'));
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
                    // The icon picker is a full-width swatch grid, so the card's
                    // icon + link no longer share a .cms-grid2 row.
                    box.appendChild(iconBound(card, 'icon', 'Icon'));
                    box.appendChild(linkBound(card, 'href', 'Where the card goes'));
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
                { image: {}, persona_name: '', mundane_name: '', role: '', bio: '', mundane_id: 0, href: '', show_mundane: false },
                function (person) {
                    var box = el('div', null);
                    box.appendChild(imageBound(person, 'image', 'Photo'));
                    var personaField = textBound(person, 'persona_name', 'Amtgard name');
                    var mundaneField = textBound(person, 'mundane_name', 'Real name');
                    box.appendChild(personaLinkField(person, function () {
                        // After persona-link auto-fill, sync the two bound inputs.
                        var pi = personaField.querySelector('input');
                        var mi = mundaneField.querySelector('input');
                        if (pi) { pi.value = person.persona_name || ''; }
                        if (mi) { mi.value = person.mundane_name || ''; }
                    }));
                    box.appendChild(personaField);
                    box.appendChild(mundaneField);
                    // C21: real-name consent gate. Off by default — the public roster
                    // suppresses a person's mundane name unless this is explicitly
                    // checked (even when the block's presentation is "Real name leads").
                    box.appendChild(checkBound(person, 'show_mundane', 'Publish this person’s real name',
                        'Off by default for privacy. Only turn this on with the person’s consent — otherwise the public card shows their Amtgard name only.'));
                    box.appendChild(textBound(person, 'role', 'Role / title'));
                    box.appendChild(textBoundArea(person, 'bio', 'Bio'));
                    box.appendChild(linkBound(person, 'href', 'Where this person’s name links (used only if no persona is linked)'));
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
            body.appendChild(fieldText(block, 'title', 'Video title', { placeholder: 'What is this video? e.g. Kingdom Coronation 2026' }));
            body.appendChild(el('div', 'cms-help', 'Names the player for screen-reader users and browser tabs. A clear title (“Kingdom Coronation 2026”) is far more useful than the generic “YouTube video player”.'));
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
        if (isDynamicType(t)) {
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

        // ----- columns: visual 2/3-column splitter (enh #16) -----
        // Representable structures get the visual editor (which only ever emits a
        // valid array-of-arrays-of-blocks, so it can never trip the JSON autosave
        // block). A legacy/edge structure the splitter can't represent degrades to
        // the JSON editor for THIS instance so no data is lost.
        if (t === 'columns') {
            if (columnsRepresentable(block)) {
                body.appendChild(columnsEditor(block));
            } else {
                body.appendChild(el('div', 'cms-note',
                    '<i class="fas fa-info-circle"></i> This Columns block has a custom structure the visual editor can’t show '
                    + '(only 2- and 3-column layouts are visual). Edit it as JSON below — your content is preserved.'));
                body.appendChild(jsonField(block, 'Columns — advanced (custom structure)',
                    'Each column is a list of blocks. Parsed on save; invalid JSON keeps the last valid value.',
                    errorOwner));
            }
            return body;
        }

        // ----- LAST-RESORT JSON fallback (unknown / not-yet-shipped types) -----
        body.appendChild(jsonField(block, 'Fields (JSON)',
            'This block type has no friendly form yet — edit its fields as JSON. It is parsed on save; invalid JSON keeps the last valid value.',
            errorOwner));
        return body;
    }

    /* ---- toggle a block card's error state + quiet inline message ---- *
     * Finds the rendered card for this block and reflects block._jsonError
     * without a full rerender (keeps the textarea focus + caret intact). */
    function reflectBlockError(block) {
        if (!listEl) { return; }
        var row = rowForBlock(block);
        var card = row ? row.querySelector('.cms-block-card') : null;
        if (!card) { return; }
        card.classList.toggle('cms-block-error', !!block._jsonError);
        if (card._errMsg) { card._errMsg.style.display = block._jsonError ? '' : 'none'; }
    }

    /* ---- shared JSON editor field (columns-advanced + last-resort fallback) ----
     * C20: an invalid-JSON block sets block._jsonError, which the host uses to
     * BLOCK the whole page save. That used to be silent — the author got no cue
     * which block was at fault. We now (a) toast the moment JSON goes invalid,
     * naming the block, and (b) drive a loud inline banner via reflectBlockError.
     *
     * `errorOwner` is the object the error is RECORDED on. A nested columns-in-
     * columns child is not a member of `model`, so hasJsonError()/focusFirstError()
     * (and rowForBlock, which only knows top-level rows) would never see its
     * error — the nested call site passes the top-level columns block instead,
     * so the save gate and the inline banner both keep working. */
    function jsonField(block, label, help, errorOwner) {
        var owner = errorOwner || block;
        var wrap = el('div', 'cms-field');
        wrap.appendChild(el('label', 'cms-label', label));
        var ta = el('textarea', 'cms-textarea');
        ta.style.minHeight = '160px';
        ta.style.fontFamily = 'ui-monospace, Menlo, Consolas, monospace';
        ta.value = JSON.stringify(block.fields || {}, null, 2);
        var prevErr = !!owner._jsonError;
        ta.addEventListener('input', function () {
            try {
                var parsed = JSON.parse(ta.value);
                if (parsed && typeof parsed === 'object') {
                    block.fields = parsed;
                    ta.style.borderColor = '';
                    owner._jsonError = false;
                } else {
                    throw new Error('not an object');
                }
            } catch (err) {
                ta.style.borderColor = 'var(--ork-badge-red-text)';
                owner._jsonError = true;
            }
            reflectBlockError(owner);
            // Loud, once-per-transition: warn on valid→invalid; reassure on fix.
            if (owner._jsonError && !prevErr) {
                toast('The “' + labelFor(owner.type) + '” block has invalid JSON — fix it before saving.', 'error');
            } else if (!owner._jsonError && prevErr) {
                toast('JSON fixed — the “' + labelFor(owner.type) + '” block can save again.', 'ok');
            }
            prevErr = !!owner._jsonError;
            markDirty();
        });
        wrap.appendChild(ta);
        wrap.appendChild(el('div', 'cms-help', help));
        return wrap;
    }

    /* ================= columns: visual 2/3-column splitter (enh #16) =================
     * Replaces the raw-JSON textarea for the `columns` LAYOUT block with a visual
     * editor: choose 2 or 3 columns, and fill each with a mini stack of child blocks
     * that REUSE the same per-block card chrome (icon + label + summary + enable /
     * reorder / remove) and the same field forms (buildBlockBody) as the page list.
     *
     * Data shape — matched EXACTLY to frontdoor/blocks/columns.tpl:
     *   block.fields.columns = [ [child, …], [child, …] (, [child, …]) ]
     *   child = { type, enabled, source, fields }  (renderer shape; render_blocks.tpl
     *   walks each column's array IN ORDER and skips !enabled / unknown types).
     *
     * The editor mutates block.fields.columns IN PLACE and never rebuilds the
     * surrounding fields object, so any unmodelled sibling field is preserved. It
     * only ever writes a valid array-of-arrays-of-objects, so a columns block edited
     * visually can never set block._jsonError (never blocks autosave).
     *
     * Nesting is bounded: the child add-chooser hides 'columns' (addExcludeColumns),
     * and an existing columns-in-columns child is edited as JSON, not a recursive
     * visual editor. */

    // True when the visual splitter can safely represent this block (else → JSON).
    function columnsRepresentable(block) {
        var cols = (block.fields || {}).columns;
        if (cols === undefined || cols === null) { return true; } // new/blank → default 2
        if (!Array.isArray(cols)) { return false; }
        if (cols.length === 0) { return true; }                   // empty → default 2
        if (cols.length !== 2 && cols.length !== 3) { return false; }
        for (var i = 0; i < cols.length; i++) {
            if (!Array.isArray(cols[i])) { return false; }
            for (var j = 0; j < cols[i].length; j++) {
                var ch = cols[i][j];
                if (!ch || typeof ch !== 'object' || Array.isArray(ch)) { return false; }
                if (typeof ch.type !== 'string' || ch.type === '') { return false; }
            }
        }
        return true;
    }

    // Normalize one child block to the renderer shape. No server id — children live
    // inside the parent columns block's fields JSON, not their own rows. The fields
    // object is kept BY REFERENCE so in-place edits (buildBlockBody) reach serialize.
    function normColChild(c) {
        return {
            type:    String((c && c.type) || ''),
            enabled: !(c && (c.enabled === false || c.enabled === 0 || c.enabled === '0')),
            source:  (c && c.source === 'dynamic') ? 'dynamic' : 'authored',
            fields:  (c && c.fields && typeof c.fields === 'object' && !Array.isArray(c.fields)) ? c.fields : {}
        };
    }

    function askDeleteChild(child, onOk) {
        confirmDialog('Remove block',
            'Remove the “' + labelFor(child.type) + '” block from this column? You can re-add it later.',
            'Remove', function () { closeModal(confirmModal); onOk(); });
    }

    // A child block's field editor. Bounds nesting: a columns-in-columns child is
    // edited as JSON (not a recursive visual editor); everything else reuses the
    // exact same field forms as the page-level list.
    function childBlockBody(child, owner) {
        if (child.type === 'columns') {
            // The error is recorded on the TOP-LEVEL columns block: a nested child
            // is not in `model`, so hasJsonError()'s save gate would never see it.
            return jsonField(child, 'Nested columns — advanced (JSON)',
                'A columns block inside a column is edited as JSON to keep layouts from nesting without bound. Parsed on save; invalid JSON keeps the last valid value.',
                owner);
        }
        // Same reason as above: a non-'columns' child with no friendly form falls
        // through to the JSON fallback, whose error must also land on the owner.
        return buildBlockBody(child, owner);
    }

    // One child card — the SAME card chrome as a page-level block, bound to its slot
    // in the column array. `ops` mutates only the affected child's node (see
    // buildColumnPanel); `owner` is the top-level columns block this child lives in.
    function buildChildCard(colArr, child, ops, owner) {
        var card = el('div', 'cms-block-card cms-cols-childcard' + (child.enabled ? '' : ' cms-block-disabled'));
        card._child = child;

        var head = el('div', 'cms-block-head');
        head.appendChild(el('span', 'cms-block-icon', '<i class="fas ' + esc(iconFor(child.type)) + '"></i>'));
        head.appendChild(el('span', 'cms-block-type', esc(labelFor(child.type))));
        head.appendChild(el('span', 'cms-block-summary', esc(summarize(child))));

        var tools = el('div', 'cms-block-tools');
        var up = iconBtn('fa-arrow-up', 'Move up', false);
        var down = iconBtn('fa-arrow-down', 'Move down', false);
        card._upBtn = up;
        card._downBtn = down;
        up.addEventListener('click', function () { ops.move(child, -1); });
        down.addEventListener('click', function () { ops.move(child, 1); });

        var sw = el('label', 'cms-switch');
        var cb = el('input'); cb.type = 'checkbox'; cb.checked = child.enabled;
        cb.setAttribute('aria-label', child.enabled ? 'Block enabled, click to disable' : 'Block disabled, click to enable');
        cb.addEventListener('change', function () {
            child.enabled = cb.checked;
            card.classList.toggle('cms-block-disabled', !child.enabled);
            cb.setAttribute('aria-label', cb.checked ? 'Block enabled, click to disable' : 'Block disabled, click to enable');
            markDirty();
        });
        sw.appendChild(cb); sw.appendChild(el('span', 'cms-slider'));

        var del = iconBtn('fa-trash', 'Remove block', false, true);
        del.addEventListener('click', function () {
            askDeleteChild(child, function () { ops.remove(child); });
        });

        tools.appendChild(up); tools.appendChild(down); tools.appendChild(sw); tools.appendChild(del);
        head.appendChild(tools);
        card.appendChild(head);

        var body = el('div', 'cms-block-body');
        body.appendChild(childBlockBody(child, owner));
        card.appendChild(body);
        return card;
    }

    // One column panel: its own child list + an "Add block" that opens the shared
    // chooser routed to append into THIS column. The initial rebuild does NOT init
    // TinyMCE (the caller — renderList/insertRowAt or renderGrid — inits the batch);
    // later user-triggered rebuilds self-init their new editors.
    function buildColumnPanel(cols, ci, owner) {
        var panel = el('div', 'cms-cols-col');
        var colArr = cols[ci];
        var ready = false;

        panel.appendChild(el('div', 'cms-cols-col-head', 'Column ' + (ci + 1)));
        var listWrap = el('div', 'cms-cols-childlist');
        panel.appendChild(listWrap);

        var emptyNote = el('div', 'cms-cols-empty', 'No blocks in this column yet.');
        listWrap.appendChild(emptyNote);

        var add = el('button', 'cms-btn cms-btn-sm cms-cols-add', '<i class="fas fa-plus"></i> Add block');
        add.type = 'button';
        listWrap.appendChild(add);

        // Same surgical treatment the top-level list gets (see "C9: surgical DOM
        // helpers"): moving or removing ONE child must not rebuild its siblings.
        // A column can hold a rich_text child, and a rebuild would destroy and
        // re-init every sibling's TinyMCE — losing caret, scroll and undo history
        // in blocks the author never touched.
        function cardFor(child) {
            var nodes = listWrap.querySelectorAll('.cms-cols-childcard');
            for (var i = 0; i < nodes.length; i++) {
                if (nodes[i]._child === child) { return nodes[i]; }
            }
            return null;
        }
        function syncChildChrome() {
            var nodes = listWrap.querySelectorAll('.cms-cols-childcard');
            for (var i = 0; i < nodes.length; i++) {
                nodes[i]._upBtn.disabled = (i === 0);
                nodes[i]._downBtn.disabled = (i === nodes.length - 1);
            }
            emptyNote.style.display = nodes.length ? 'none' : '';
        }
        var childOps = {
            move: function (child, dir) {
                var i = colArr.indexOf(child);
                var j = i + dir;
                if (i < 0 || j < 0 || j >= colArr.length) { return; }
                var node = cardFor(child);
                swap(colArr, i, j);
                var otherNode = cardFor(colArr[i]);
                if (node && otherNode) {
                    listWrap.insertBefore(node, dir < 0 ? otherNode : otherNode.nextSibling);
                }
                syncChildChrome();
                markDirty();
            },
            remove: function (child) {
                var i = colArr.indexOf(child);
                if (i < 0) { return; }
                var node = cardFor(child);
                colArr.splice(i, 1);
                if (node) {
                    destroyTinyIn(node);
                    node.parentNode.removeChild(node);
                }
                syncChildChrome();
                markDirty();
            }
        };

        add.addEventListener('click', function () {
            openAddChooserForHandler(function (c) {
                var child = { type: c.type, enabled: true, source: c.dynamic ? 'dynamic' : 'authored', fields: {} };
                colArr.push(child);
                var card = buildChildCard(colArr, child, childOps, owner);
                listWrap.insertBefore(card, add);
                syncChildChrome();
                if (ready) {
                    card.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
                }
                warnTinyDegradedIfNeeded(card);
                markDirty();
            });
        });

        colArr.forEach(function (child) {
            listWrap.insertBefore(buildChildCard(colArr, child, childOps, owner), add);
        });
        syncChildChrome();
        ready = true;        // the caller inits the initial batch; later adds self-init
        return panel;
    }

    // The columns visual editor body (only called for representable blocks).
    function columnsEditor(block) {
        var wrap = el('div', 'cms-cols-editor');

        // Normalize IN PLACE (preserves any sibling fields on the block).
        if (!Array.isArray(block.fields.columns)) { block.fields.columns = []; }
        var cols = block.fields.columns;
        for (var i = 0; i < cols.length; i++) {
            cols[i] = (Array.isArray(cols[i]) ? cols[i] : []).map(normColChild);
        }
        while (cols.length < 2) { cols.push([]); }   // new/blank → 2 columns
        if (cols.length > 3) { cols.length = 3; }     // (representable-check already caps this)

        wrap.appendChild(el('div', 'cms-help',
            'Split this row into side-by-side columns, each holding its own stack of blocks. Columns stack vertically on narrow screens.'));

        var countRow = el('div', 'cms-cols-countrow');
        countRow.appendChild(el('span', 'cms-label', 'Columns'));
        var seg = el('div', 'cms-seg');
        [2, 3].forEach(function (n) {
            var b = el('button', 'cms-btn cms-btn-sm', String(n));
            b.type = 'button';
            b.setAttribute('data-n', String(n));
            b.setAttribute('data-tip', n + ' columns');
            b.addEventListener('click', function () { setCount(n); });
            seg.appendChild(b);
        });
        countRow.appendChild(seg);
        wrap.appendChild(countRow);

        var grid = el('div', 'cms-cols-grid');
        wrap.appendChild(grid);

        function syncChrome() {
            Array.prototype.forEach.call(seg.children, function (b) {
                b.classList.toggle('cms-seg-active', Number(b.getAttribute('data-n')) === cols.length);
            });
            grid.className = 'cms-cols-grid cms-cols-grid-' + cols.length;
        }

        function renderGrid(firstBuild) {
            destroyTinyIn(grid);
            grid.innerHTML = '';
            cols.forEach(function (colArr, ci) { grid.appendChild(buildColumnPanel(cols, ci, block)); });
            syncChrome();
            // On the very first build the outer machinery (renderList/insertRowAt)
            // inits every data-tiny in the row; a later count change inits here.
            if (!firstBuild) {
                grid.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
            }
        }

        function setCount(n) {
            if (n === cols.length || (n !== 2 && n !== 3)) { return; }
            if (n === 3) {
                cols.push([]);                  // 2 → 3: append a new empty column
            } else {
                var third = cols.pop();         // 3 → 2: merge column 3 into column 2 (lossless)
                cols[1] = cols[1].concat(third);
            }
            renderGrid(false);
            markDirty();
        }

        renderGrid(true);
        return wrap;
    }

    /* ---- icon for a block type (from the catalog) ---- */
    function iconFor(type) {
        var ent = catalogEntry(type);
        return (ent && ent.icon) ? ent.icon : 'fa-cube';
    }

    /* ---- a thin hover-reveal "+" inserter zone that opens the chooser ----
     * Anchored to a BLOCK (insert BEFORE it), not a fixed index, so it keeps
     * pointing at the right slot after surgical reorders. anchorBlock == null →
     * the trailing zone that appends at the end. */
    function inserterZone(anchorBlock) {
        var zone = el('div', 'cms-inserter');
        zone.setAttribute('data-tip', 'Insert a block here');
        var btn = el('button', 'cms-inserter-btn', '<i class="fas fa-plus"></i>');
        btn.type = 'button';
        btn.setAttribute('aria-label', 'Insert a block here');
        btn.addEventListener('click', function () {
            var at = (anchorBlock == null) ? model.length : model.indexOf(anchorBlock);
            openAddChooser(at < 0 ? model.length : at);
        });
        zone.appendChild(btn);
        return zone;
    }

    /* ================= surgical DOM helpers (C9) =================
     * Each block is rendered as a "row" = [insert-before zone, card] wrapped in a
     * display:contents div (adds no layout box). Reorder/insert/remove touch a
     * SINGLE row node so we never destroy+rebuild every card — which is what tore
     * down every open TinyMCE editor on any change. Full renderList() is reserved
     * for replaceModel()/seedFromPreset(). */
    function rowNodes() {
        return Array.prototype.slice.call(listEl.querySelectorAll('.cms-block-row'));
    }
    function trailingInserter() {
        return listEl.querySelector('.cms-inserter-trailing');
    }
    function rowForBlock(block) {
        var rows = rowNodes();
        for (var i = 0; i < rows.length; i++) {
            if (rows[i]._block === block) { return rows[i]; }
        }
        return null;
    }
    // Keep every card's up/down disabled state honest after a structural change.
    function refreshRowChrome() {
        var rows = rowNodes();
        rows.forEach(function (r, i) {
            var card = r.querySelector('.cms-block-card');
            if (!card) { return; }
            if (card._upBtn) { card._upBtn.disabled = (i === 0); }
            if (card._downBtn) { card._downBtn.disabled = (i === rows.length - 1); }
        });
        updateCollapseAllBtn();
    }
    // Reorder existing row nodes to match model order (moves nodes, no rebuild).
    function syncRowOrder() {
        var rows = rowNodes();
        var trailing = trailingInserter();
        model.forEach(function (block) {
            for (var i = 0; i < rows.length; i++) {
                if (rows[i]._block === block) { listEl.insertBefore(rows[i], trailing); break; }
            }
        });
    }
    // Build one row (insert-before zone + card) bound to a block.
    function buildRow(block) {
        var row = el('div', 'cms-block-row');
        row._block = block;
        row.appendChild(inserterZone(block));
        row.appendChild(buildCard(block));
        return row;
    }
    // Insert a freshly-built row for `block` (already spliced into model at `at`),
    // init only its own TinyMCE, and refresh chrome — no global teardown.
    function insertRowAt(block, at, scroll) {
        var rows = rowNodes();               // DOM rows BEFORE inserting the new one
        var trailing = trailingInserter();
        if (!trailing) {                     // list may have been empty
            trailing = inserterZone(null);
            trailing.classList.add('cms-inserter-trailing');
            listEl.appendChild(trailing);
        }
        var row = buildRow(block);
        var ref = (at < rows.length) ? rows[at] : trailing;
        listEl.insertBefore(row, ref);
        emptyEl.style.display = model.length ? 'none' : '';
        row.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
        refreshRowChrome();
        if (scroll) {
            var card = row.querySelector('.cms-block-card');
            if (card) { card.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        }
        return row;
    }
    // Move a block within the model, then move its single row node.
    function moveBlock(from, to) {
        if (from === to || from < 0 || to < 0 || from >= model.length || to >= model.length) { return; }
        var moved = model.splice(from, 1)[0];
        model.splice(to, 0, moved);
        syncRowOrder();
        refreshRowChrome();
        markDirty();
    }
    // Remove a block + its single row node (destroying only that row's editors).
    function removeBlock(block) {
        var i = model.indexOf(block);
        if (i < 0) { return; }
        model.splice(i, 1);
        var row = rowForBlock(block);
        if (row) { destroyTinyIn(row); row.remove(); }
        if (!model.length) {
            var trailing = trailingInserter();
            if (trailing) { trailing.remove(); }
        }
        emptyEl.style.display = model.length ? 'none' : '';
        refreshRowChrome();
        markDirty();
    }

    /* ---- build one block card (bound to the block object, not an index, so its
     * handlers survive surgical reorders) ---- */
    function buildCard(block) {
        // cms-block-collapsible marks a card whose HEAD is an expand control.
        // Columns child cards reuse .cms-block-card chrome but are always open,
        // so they must not pick up the pointer/hover affordance.
        var card = el('div', 'cms-block-card cms-block-collapsible' + (block.enabled ? '' : ' cms-block-disabled') + (block._jsonError ? ' cms-block-error' : ''));
        card._block = block;
        // draggable is enabled only while the drag handle is pressed (wireDrag),
        // so text selection inside field inputs never starts a card drag.
        card.setAttribute('draggable', 'false');

        var head = el('div', 'cms-block-head');

        var handle = el('span', 'cms-drag-handle', '<i class="fas fa-grip-vertical"></i>');
        handle.setAttribute('data-tip', 'Drag to reorder');
        head.appendChild(handle);

        // Blocks start COLLAPSED (see setExpanded) unless this block remembers
        // being open — a fresh page load is a list of one-line rows, not 800px
        // of form per block.
        var startOpen = (block._expanded === true);
        var collapseBtn = iconBtn(startOpen ? 'fa-chevron-down' : 'fa-chevron-right', 'Collapse / expand', false);
        collapseBtn.setAttribute('aria-expanded', startOpen ? 'true' : 'false');
        head.appendChild(collapseBtn);
        head.appendChild(el('span', 'cms-block-icon', '<i class="fas ' + esc(iconFor(block.type)) + '"></i>'));
        head.appendChild(el('span', 'cms-block-type', esc(labelFor(block.type))));
        // #100: author-facing header shows the friendly label + icon only — never
        // the raw machine block-type slug (dev jargon).
        var summaryEl = el('span', 'cms-block-summary', esc(summarize(block)));
        card._summaryEl = summaryEl;
        head.appendChild(summaryEl);

        var tools = el('div', 'cms-block-tools');
        var up = iconBtn('fa-arrow-up', 'Move up', false);
        var down = iconBtn('fa-arrow-down', 'Move down', false);
        card._upBtn = up;
        card._downBtn = down;
        up.addEventListener('click', function () {
            var i = model.indexOf(block);
            if (i > 0) { moveBlock(i, i - 1); }
        });
        down.addEventListener('click', function () {
            var i = model.indexOf(block);
            if (i > -1 && i < model.length - 1) { moveBlock(i, i + 1); }
        });

        var dup = iconBtn('fa-clone', 'Duplicate block', false);
        dup.addEventListener('click', function () { duplicateBlock(block); });

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
        del.addEventListener('click', function () { askDeleteBlock(block); });

        tools.appendChild(up);
        tools.appendChild(down);
        tools.appendChild(dup);
        tools.appendChild(sw);
        tools.appendChild(del);
        head.appendChild(tools);
        card.appendChild(head);

        // The body is built LAZILY, the first time the block is expanded. A
        // collapsed body used to be a fully-built form sitting behind
        // display:none — every input, every repeater and (worse) a live TinyMCE
        // instance per rich-text block, all constructed for content nobody had
        // asked to see. Building on demand also sidesteps initialising TinyMCE
        // inside a hidden container, which sizes its iframe to zero.
        var body = el('div', 'cms-block-body' + (startOpen ? '' : ' cms-collapsed'));
        card.appendChild(body);
        card._body = body;
        card._collapseBtn = collapseBtn;
        card._built = false;

        // @param {boolean} initEditors init this body's TinyMCE editors now.
        //   False when the CALLER inits the batch (renderList / insertRowAt),
        //   matching the same `ready` handshake buildColumnPanel uses.
        card._buildBody = function (initEditors) {
            if (card._built) { return; }
            card._built = true;
            // loud inline error message (shown only when this block blocks the save)
            var errMsg = el('div', 'cms-block-error-msg', '<i class="fas fa-exclamation-triangle"></i> <span>This block has invalid JSON and won’t be saved until you fix it.</span>');
            errMsg.style.display = block._jsonError ? '' : 'none';
            card._errMsg = errMsg;
            body.appendChild(errMsg);
            body.appendChild(buildBlockBody(block));
            if (initEditors) {
                body.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
            }
            // The body that just appeared is the first place a data-tiny
            // textarea can exist — warn here, not (only) from renderList.
            warnTinyDegradedIfNeeded(body);
        };
        if (startOpen) { card._buildBody(false); }

        collapseBtn.addEventListener('click', function () {
            setExpanded(card, body.classList.contains('cms-collapsed'));
        });
        // The whole header row is the expand affordance — the chevron is a 24px
        // target on a card that is otherwise inert, and an author who wants to
        // open a block aims at its name. Clicks on the tools (reorder, duplicate,
        // enable, delete) and on the drag handle keep their own behaviour.
        head.addEventListener('click', function (e) {
            if (e.target.closest('.cms-block-tools, .cms-drag-handle, .cms-icon-btn')) { return; }
            setExpanded(card, body.classList.contains('cms-collapsed'));
        });

        wireDrag(card, handle, block);
        return card;
    }

    /* ---- expand / collapse one card ----
     * The open/closed state is remembered ON THE BLOCK OBJECT, which outlives
     * every DOM rebuild in this editor (renderList/replaceModel re-render the
     * same objects), so a save — or a revision restore — never collapses the
     * block the author was working in. */
    function setExpanded(card, want) {
        if (!card || !card._body) { return; }
        want = !!want;
        if (want && card._buildBody) { card._buildBody(true); }
        card._body.classList.toggle('cms-collapsed', !want);
        if (card._block) { card._block._expanded = want; }
        if (card._collapseBtn) {
            var ic = card._collapseBtn.querySelector('i');
            if (ic) { ic.className = want ? 'fas fa-chevron-down' : 'fas fa-chevron-right'; }
            card._collapseBtn.setAttribute('aria-expanded', want ? 'true' : 'false');
        }
        // Collapsing hands navigation back to the summary line, so make sure it
        // reflects what was just typed rather than what was there on load.
        if (!want) { refreshSummary(card); }
        updateCollapseAllBtn();
    }

    /* Repaint one card's summary line from its (possibly just-edited) block. */
    function refreshSummary(card) {
        if (card && card._summaryEl && card._block) {
            card._summaryEl.textContent = summarize(card._block);
        }
    }
    /* Repaint every collapsed card's summary — cheap, and it keeps the one-line
     * view honest while the author edits a sibling block. */
    function refreshSummaries() {
        rowNodes().forEach(function (r) {
            var card = r.querySelector('.cms-block-card');
            if (card && card._body && card._body.classList.contains('cms-collapsed')) { refreshSummary(card); }
        });
    }

    /* ================= collapse-all / expand-all ================= */
    // The header button toggles every card at once; its label reflects the NEXT
    // action based on whether any card is currently expanded (so it stays honest
    // even after cards are collapsed one at a time).
    function anyBlockExpanded() {
        return rowNodes().some(function (r) {
            var card = r.querySelector('.cms-block-card');
            return card && card._body && !card._body.classList.contains('cms-collapsed');
        });
    }
    function setAllCollapsed(collapse) {
        rowNodes().forEach(function (r) {
            var card = r.querySelector('.cms-block-card');
            if (!card) { return; }
            setExpanded(card, !collapse);
        });
        updateCollapseAllBtn();
    }
    function updateCollapseAllBtn() {
        if (!collapseAllBtn) { return; }
        // Only worth showing once there's more than one block to act on.
        collapseAllBtn.style.display = (model.length > 1) ? '' : 'none';
        collapseAllBtn.innerHTML = anyBlockExpanded()
            ? '<i class="fas fa-angle-double-up"></i> Collapse all'
            : '<i class="fas fa-angle-double-down"></i> Expand all';
    }

    /* ---- render the whole block list (full rebuild — replaceModel/seed only) ---- */
    function renderList() {
        destroyTinyIn(listEl);
        listEl.innerHTML = '';
        emptyEl.style.display = model.length ? 'none' : '';

        model.forEach(function (block) { listEl.appendChild(buildRow(block)); });

        if (model.length) {
            var trailing = inserterZone(null);
            trailing.classList.add('cms-inserter-trailing');
            listEl.appendChild(trailing);
        }

        listEl.querySelectorAll('textarea[data-tiny]').forEach(function (ta) { initTiny(ta); });
        refreshRowChrome();
        warnTinyDegradedIfNeeded();
    }

    /* ================= drag-and-drop reorder =================
     * Mouse: native HTML5 DnD (well-tested, integrates with dragover highlighting).
     * Touch / pen: native DnD never fires, so a Pointer-Events fallback reorders by
     * hit-testing the card under the finger. We branch on pointerType at pointerdown
     * so the mouse path is unchanged and touch devices gain reorder for the first
     * time. (Nav-manager drag lives in Cms_nav.tpl — tracked as a separate follow-up.) */
    var dragFromBlock = null;

    // Touch/pen reorder: track the finger, highlight the card underneath, and move
    // the dragged block onto it on release (same splice semantics as the mouse drop).
    function startPointerDrag(card, handle, block, downEvt) {
        var pointerId = downEvt.pointerId;
        var lastOver = null;
        card.classList.add('cms-dragging');
        try { handle.setPointerCapture(pointerId); } catch (e) {}

        function clearOver() {
            if (lastOver) { lastOver.classList.remove('cms-drag-over'); lastOver = null; }
        }
        function onMove(ev) {
            if (ev.pointerId !== pointerId) { return; }
            ev.preventDefault();
            var under = document.elementFromPoint(ev.clientX, ev.clientY);
            var overCard = under ? under.closest('.cms-block-card') : null;
            if (!overCard || overCard === card || !listEl.contains(overCard)) { clearOver(); return; }
            if (overCard !== lastOver) { clearOver(); overCard.classList.add('cms-drag-over'); lastOver = overCard; }
        }
        function teardown() {
            handle.removeEventListener('pointermove', onMove);
            handle.removeEventListener('pointerup', onUp);
            handle.removeEventListener('pointercancel', onCancel);
            try { handle.releasePointerCapture(pointerId); } catch (e) {}
            card.classList.remove('cms-dragging');
        }
        function onUp() {
            var target = lastOver;
            teardown();
            clearOver();
            if (!target || target === card) { return; }
            var from = model.indexOf(block);
            var to   = model.indexOf(target._block);
            if (from < 0 || to < 0 || from === to) { return; }
            var moved = model.splice(from, 1)[0];
            var dest = (from < to) ? to - 1 : to;
            model.splice(dest, 0, moved);
            syncRowOrder();
            refreshRowChrome();
            markDirty();
        }
        function onCancel() { teardown(); clearOver(); }

        handle.addEventListener('pointermove', onMove);
        handle.addEventListener('pointerup', onUp);
        handle.addEventListener('pointercancel', onCancel);
    }

    function wireDrag(card, handle, block) {
        // touch-action:none lets the handle capture the drag gesture instead of the
        // browser scrolling the page out from under a touch reorder.
        handle.style.touchAction = 'none';
        // Only the handle initiates a drag (keeps text selection in field inputs).
        handle.addEventListener('pointerdown', function (e) {
            if (e.pointerType === 'mouse') {
                // Enable native HTML5 DnD for this mouse drag (handlers below).
                card.setAttribute('draggable', 'true');
                return;
            }
            // Touch / pen: run the Pointer-Events reorder fallback.
            e.preventDefault();
            startPointerDrag(card, handle, block, e);
        });
        handle.addEventListener('pointerup', function () { card.setAttribute('draggable', 'false'); });
        handle.addEventListener('pointercancel', function () { card.setAttribute('draggable', 'false'); });
        card.addEventListener('dragstart', function (e) {
            dragFromBlock = block;
            card.classList.add('cms-dragging');
            try { e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', String(model.indexOf(block))); } catch (err) {}
        });
        card.addEventListener('dragend', function () {
            card.classList.remove('cms-dragging');
            card.setAttribute('draggable', 'false');
            listEl.querySelectorAll('.cms-drag-over').forEach(function (n) { n.classList.remove('cms-drag-over'); });
            dragFromBlock = null;
        });
        card.addEventListener('dragover', function (e) {
            if (dragFromBlock === null) { return; }
            e.preventDefault();
            try { e.dataTransfer.dropEffect = 'move'; } catch (err) {}
            card.classList.add('cms-drag-over');
        });
        card.addEventListener('dragleave', function () { card.classList.remove('cms-drag-over'); });
        card.addEventListener('drop', function (e) {
            e.preventDefault();
            card.classList.remove('cms-drag-over');
            if (dragFromBlock === null || dragFromBlock === block) { dragFromBlock = null; return; }
            var from = model.indexOf(dragFromBlock);
            var target = model.indexOf(block);
            dragFromBlock = null;
            if (from < 0 || target < 0 || from === target) { return; }
            var moved = model.splice(from, 1)[0];
            var dest = (from < target) ? target - 1 : target;
            model.splice(dest, 0, moved);
            syncRowOrder();
            refreshRowChrome();
            markDirty();
        });
    }

    /* ---- duplicate a block (deep copy of its fields) right after it ---- */
    function duplicateBlock(block) {
        var i = model.indexOf(block);
        if (i < 0) { return; }
        var copy = {
            type:    block.type,
            enabled: block.enabled,
            source:  block.source,
            fields:  JSON.parse(JSON.stringify(block.fields || {})),
            // A duplicate exists to be edited — open it like a freshly added block.
            _expanded: true
        };
        model.splice(i + 1, 0, copy);
        insertRowAt(copy, i + 1, false);
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

    function askDeleteBlock(block) {
        var label = labelFor(block.type);
        confirmDialog('Remove block', 'Remove the "' + label + '" block? You can re-add it later.', 'Remove', function () {
            closeModal(confirmModal);
            removeBlock(block);
        });
    }

    /* ================= Add block ================= *
     * The chooser is searchable + grouped + icon'd, and can insert a new block
     * at a specific index (insertAt). insertAt === null → append at the end. */
    var addModal, addGroupsEl, addSearchEl, addNoMatchEl, addShowAllWrap, addShowAllBtn;
    var addInsertAt = null;      // index to splice at, or null to append
    // enh #16: when set, the chooser routes the picked catalog entry to this handler
    // (a columns child add) instead of inserting a new block into the page model.
    var addPickHandler = null;
    // enh #16: hide the 'columns' block from the chooser (prevents columns-in-columns).
    var addExcludeColumns = false;

    // Stable group order for the chooser sections.
    var GROUP_ORDER = ['Layout', 'Content', 'Media', 'Dynamic', 'Advanced'];

    function insertNewBlock(c) {
        var nb = {
            type: c.type,
            enabled: true,
            source: c.dynamic ? 'dynamic' : 'authored',
            fields: {},
            // An author who has just added a block wants to fill it in, so this
            // one opens even though the list defaults to collapsed.
            _expanded: true
        };
        var at = (addInsertAt === null || addInsertAt < 0 || addInsertAt > model.length)
            ? model.length : addInsertAt;
        model.splice(at, 0, nb);
        closeModal(addModal);
        // Surgical insert of just this card (keeps every other TinyMCE editor alive).
        insertRowAt(nb, at, true);
        markDirty();
    }

    function typeCard(c) {
        var cardBtn = el('button', 'cms-typecard' + (c.available ? '' : ' cms-typecard-disabled'));
        cardBtn.type = 'button';
        if (!c.available) { cardBtn.disabled = true; }
        var icoHtml = '<span class="cms-typecard-icon"><i class="fas ' + esc(c.icon || 'fa-cube') + '"></i></span>';
        var badge = c.available
            ? (c.dynamic ? '<span class="cms-typecard-badge cms-badge-dynamic">live</span>' : '')
            : '<span class="cms-typecard-badge cms-badge-soon">coming soon</span>';
        var descHtml = c.description
            ? '<span class="cms-typecard-desc">' + esc(c.description) + '</span>'
            : '';
        // #100: the add-block chooser card shows the friendly label + icon +
        // description only — never the raw machine block-type slug (dev jargon).
        cardBtn.innerHTML =
            icoHtml +
            '<span class="cms-typecard-text">' +
                '<strong>' + esc(c.label) + badge + '</strong>' +
                descHtml +
            '</span>';
        if (c.available) {
            cardBtn.addEventListener('click', function () {
                if (addPickHandler) {
                    // enh #16: route into the columns-child add flow, not the page model.
                    var h = addPickHandler; addPickHandler = null;
                    closeModal(addModal);
                    h(c);
                } else {
                    insertNewBlock(c);
                }
            });
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

        // All addable catalog entries (legacy/non-addable always excluded — the
        // server sets `addable`, including the per-scope gate).
        // enh #16: a columns child add also excludes 'columns' (no nested columns).
        var addable = (catalog || []).filter(function (c) {
            return c.addable !== false && !(addExcludeColumns && c.type === 'columns');
        });

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
            // Never collapsed while searching (matches must stay visible).
            var collapsed = !q && !!addGroupCollapsed[g];
            var sec = el('div', 'cms-typegroup' + (collapsed ? ' cms-typegroup-collapsed' : ''));
            var titleBtn = el('button', 'cms-typegroup-title');
            titleBtn.type = 'button';
            titleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            titleBtn.innerHTML =
                '<i class="fas fa-chevron-down cms-typegroup-caret"></i>' +
                '<span>' + esc(g) + '</span>' +
                '<span class="cms-typegroup-count">' + items.length + '</span>';
            var grid = el('div', 'cms-typegrid');
            items.forEach(function (c) { grid.appendChild(typeCard(c)); });
            titleBtn.addEventListener('click', function () {
                var nowCollapsed = !sec.classList.contains('cms-typegroup-collapsed');
                sec.classList.toggle('cms-typegroup-collapsed', nowCollapsed);
                titleBtn.setAttribute('aria-expanded', nowCollapsed ? 'false' : 'true');
                addGroupCollapsed[g] = nowCollapsed;
            });
            sec.appendChild(titleBtn);
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
        addPickHandler = null;      // page-level add: default insert behavior
        addExcludeColumns = false;  // columns allowed at the page level
        showAllBlocks = false; // always reopen in scoped view
        if (addSearchEl) { addSearchEl.value = ''; }
        renderAddChooser('');
        openModal(addModal);
        if (addSearchEl) { setTimeout(function () { addSearchEl.focus(); }, 30); }
    }

    // enh #16: open the same chooser for a columns child add. The picked catalog
    // entry is passed to `handler` (which appends it into a column) instead of the
    // page model, and 'columns' is hidden so a column can't itself hold columns.
    function openAddChooserForHandler(handler) {
        addInsertAt = null;
        addPickHandler = handler;
        addExcludeColumns = true;
        showAllBlocks = false;
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
    var mediaModal, mediaGrid, mediaSearch, mediaSearchBtn, uploadInput, uploadDrop, uploadAlt, uploadDecorative;
    var mediaCallback = null;
    // Lazy-load paging state. A large media library used to be fetched + rendered in
    // one shot; now the picker pulls one page at a time (medialist offset/limit) and
    // appends more as the author scrolls (IntersectionObserver) or clicks "Load more".
    var MEDIA_PAGE = 24;
    var mediaQuery = '', mediaOffset = 0, mediaHasMore = false, mediaLoading = false;
    var mediaMoreBtn = null, mediaMoreIO = null;

    function openMediaPicker(cb) {
        mediaCallback = cb;
        openModal(mediaModal);
        loadMedia('');
    }

    // Build one picker tile: click the image/caption to pick it; edit its alt inline
    // (writes through to the media row) without picking.
    function buildMediaTile(m) {
        var tile = el('div', 'cms-media-tile');
        // Make the whole tile a real, keyboard-operable control (not mouse-only).
        tile.setAttribute('role', 'button');
        tile.setAttribute('tabindex', '0');
        tile.setAttribute('aria-label', 'Use image: ' + (m.alt || m.filename || ('#' + (m.media_id || ''))));
        var img = el('img');
        img.alt = m.alt || '';
        var cap = el('div', 'cms-media-cap', esc(m.alt || m.filename || ('#' + (m.media_id || ''))));

        function pick() {
            if (mediaCallback) { mediaCallback(m); }
            closeModal(mediaModal);
        }
        img.addEventListener('click', pick);
        cap.addEventListener('click', pick);
        // Enter/Space activate the tile like a click — but only when the tile itself
        // has focus, so typing in the inline alt-editor input doesn't fire pick().
        tile.addEventListener('keydown', function (e) {
            if (e.target !== tile) { return; }
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                pick();
            }
        });
        // #95: a broken thumbnail swaps to the fa-image placeholder (sized to the
        // tile so it never overlaps the caption below), keeping the tile clickable.
        img.addEventListener('error', function () {
            var ph = el('div', 'cms-media-tile-fallback', '<i class="fas fa-image" aria-hidden="true"></i>');
            ph.addEventListener('click', pick);
            if (img.parentNode) { img.parentNode.replaceChild(ph, img); }
        });
        img.src = m.thumb || m.src;

        tile.appendChild(img);
        tile.appendChild(cap);
        // #05 + #17: inline alt editing in the picker. Editing here writes the
        // description back to the shared media row (CmsAjax/mediaupdate — CSRF- and
        // scope-guarded via post()), so it's reusable everywhere the image appears.
        if (m.media_id) { tile.appendChild(buildAltEditor(m, cap, img)); }
        return tile;
    }

    // Inline alt editor for a picker tile. The "decorative" tick INTENTIONALLY saves
    // an empty alt (assistive tech then skips the image) — the same teaching pattern
    // as the upload panel, but applied to an existing library image.
    function buildAltEditor(m, cap, img) {
        var box = el('div', 'cms-media-alt');
        // Interacting with the editor must not trigger the tile's "pick" click.
        box.addEventListener('click', function (e) { e.stopPropagation(); });

        var input = el('input', 'cms-input cms-media-alt-input');
        input.type = 'text';
        input.placeholder = 'Describe this image…';
        input.value = m.alt || '';

        var saveBtn = el('button', 'cms-btn cms-btn-sm cms-media-alt-save', 'Save');
        saveBtn.type = 'button';
        saveBtn.setAttribute('data-tip', 'Save this description to the media library');

        var decoLab = el('label', 'cms-check-inline cms-media-alt-deco');
        var deco = el('input'); deco.type = 'checkbox';
        decoLab.appendChild(deco);
        decoLab.appendChild(document.createTextNode(' Decorative (no alt text)'));

        deco.addEventListener('change', function () {
            input.disabled = deco.checked;
            if (deco.checked) { input.value = ''; }
        });

        function save() {
            var alt = deco.checked ? '' : input.value.trim();
            var prev = saveBtn.textContent;
            saveBtn.disabled = true;
            saveBtn.textContent = 'Saving…';
            // post() sends X-CSRF-Token (window.CMS_CSRF) + the active scope.
            post('mediaupdate', { media_id: m.media_id, alt: alt }).then(function (res) {
                saveBtn.disabled = false;
                saveBtn.textContent = prev;
                if (!res || !res.ok) { toast((res && res.error) || 'Could not save the description.', 'error'); return; }
                // Reflect the sanitized value the server echoed back.
                m.alt = (res.alt != null) ? String(res.alt) : alt;
                input.value = m.alt;
                if (img) { img.alt = m.alt; }
                if (cap) { cap.textContent = m.alt || m.filename || ('#' + (m.media_id || '')); }
                toast(deco.checked ? 'Marked decorative — empty alt saved.' : 'Description saved.', 'ok');
            }).catch(function () {
                saveBtn.disabled = false;
                saveBtn.textContent = prev;
                toast('Network error saving the description.', 'error');
            });
        }
        saveBtn.addEventListener('click', save);
        input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });

        var row = el('div', 'cms-media-alt-row');
        row.appendChild(input);
        row.appendChild(saveBtn);
        box.appendChild(row);
        box.appendChild(decoLab);
        return box;
    }

    // Append a page of tiles. `reset` clears the grid first (new search / reopen).
    function appendMediaTiles(items, reset) {
        if (reset) { mediaGrid.innerHTML = ''; }
        if (reset && (!items || !items.length)) {
            mediaGrid.appendChild(el('div', 'cms-media-empty', 'No media yet. Upload an image above.'));
            return;
        }
        (items || []).forEach(function (m) { mediaGrid.appendChild(buildMediaTile(m)); });
    }

    // Create (once) the "Load more" control + its IntersectionObserver, then reflect
    // the current paging state onto it.
    function syncMediaMore() {
        if (!mediaMoreBtn && mediaGrid && mediaGrid.parentNode) {
            mediaMoreBtn = el('button', 'cms-btn cms-btn-sm cms-btn-ghost cms-media-more', 'Load more images');
            mediaMoreBtn.type = 'button';
            mediaMoreBtn.style.display = 'none';
            mediaMoreBtn.addEventListener('click', function () { loadMediaPage(false); });
            mediaGrid.parentNode.insertBefore(mediaMoreBtn, mediaGrid.nextSibling);
            // Auto-load the next page when the button scrolls into view inside the
            // modal body. The manual click above is the fallback if IO is unavailable.
            if (typeof IntersectionObserver !== 'undefined') {
                mediaMoreIO = new IntersectionObserver(function (entries) {
                    if (entries[0] && entries[0].isIntersecting) { loadMediaPage(false); }
                }, { root: mediaGrid.parentNode, rootMargin: '150px' });
                mediaMoreIO.observe(mediaMoreBtn);
            }
        }
        if (!mediaMoreBtn) { return; }
        mediaMoreBtn.style.display = mediaHasMore ? '' : 'none';
        mediaMoreBtn.disabled = mediaLoading;
        mediaMoreBtn.textContent = mediaLoading ? 'Loading…' : 'Load more images';
    }

    // Fetch one page. `reset` starts over (offset 0, new/blank search).
    function loadMediaPage(reset) {
        if (mediaLoading) { return; }
        if (!reset && !mediaHasMore) { return; }
        if (reset) {
            mediaOffset = 0;
            mediaHasMore = false;
            if (mediaMoreBtn) { mediaMoreBtn.style.display = 'none'; }
            mediaGrid.innerHTML = '<div class="cms-media-empty">Loading…</div>';
        }
        mediaLoading = true;
        syncMediaMore();

        // AJAX already ends in '...?Route=CmsAjax/', so params must be joined with
        // '&' — a second '?' would corrupt the Route param (empties $_GET).
        var params = { limit: String(MEDIA_PAGE), offset: String(mediaOffset) };
        if (mediaQuery) { params.q = mediaQuery; }
        var url = AJAX + 'medialist&' + new URLSearchParams(params).toString()
            + (window.CMS_SCOPE ? '&scope=' + encodeURIComponent(window.CMS_SCOPE) : '');
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); })
            .then(function (res) {
                mediaLoading = false;
                if (!res || !res.ok) {
                    if (reset) { mediaGrid.innerHTML = '<div class="cms-media-empty">' + esc((res && res.error) || 'Could not load media.') + '</div>'; }
                    else { toast((res && res.error) || 'Could not load more media.', 'error'); }
                    syncMediaMore();
                    return;
                }
                var items = res.media || [];
                mediaHasMore = !!res.has_more;
                mediaOffset += items.length;
                appendMediaTiles(items, reset);
                syncMediaMore();
            })
            .catch(function () {
                mediaLoading = false;
                if (reset) { mediaGrid.innerHTML = '<div class="cms-media-empty">Network error.</div>'; }
                else { toast('Network error loading more media.', 'error'); }
                syncMediaMore();
            });
    }

    // Back-compat entry point: (re)load the picker from the top for query `q`.
    function loadMedia(q) {
        mediaQuery = (q == null) ? '' : String(q);
        loadMediaPage(true);
    }

    // C1: alt text authored at upload. A "decorative" tick INTENTIONALLY sends an
    // empty alt (assistive tech then skips the image) — distinct from simply
    // forgetting to describe it, which is why the choice is explicit.
    function uploadAltValue() {
        if (uploadDecorative && uploadDecorative.checked) { return ''; }
        return uploadAlt ? uploadAlt.value.trim() : '';
    }
    function resetUploadMeta() {
        if (uploadAlt) { uploadAlt.value = ''; }
        if (uploadDecorative) { uploadDecorative.checked = false; }
        if (uploadAlt) { uploadAlt.disabled = false; }
    }

    function doUpload(file) {
        if (!file) { return; }
        // The upload is base64'd into an x-www-form-urlencoded `data=` field, so
        // the POST body is ~1.4x the file. Anything above ~5MB blows past PHP's
        // 8M post_max_size, which drops $_POST entirely and surfaces as the
        // misleading "No image data was supplied." Gate on the REAL ceiling.
        if (file.size > 5 * 1024 * 1024) { toast('Image is larger than 5MB.', 'error'); return; }
        var alt = uploadAltValue();
        var reader = new FileReader();
        reader.onerror = function () { toast('Could not read file.', 'error'); loadMedia(''); };
        reader.onload = function () {
            mediaGrid.innerHTML = '<div class="cms-media-empty"><span class="cms-spin"></span> Uploading…</div>';
            post('mediaupload', { data: reader.result, filename: file.name, alt: alt }).then(function (res) {
                if (!res || !res.ok) { toast((res && res.error) || 'Upload failed.', 'error'); loadMedia(''); return; }
                toast('Image uploaded.', 'ok');
                resetUploadMeta();
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
        uploadAlt = document.getElementById('cmsUploadAlt');
        uploadDecorative = document.getElementById('cmsUploadDecorative');
        if (!mediaModal) { return; }

        // A decorative image needs no description — grey the alt field to teach why.
        if (uploadDecorative && uploadAlt) {
            uploadDecorative.addEventListener('change', function () {
                uploadAlt.disabled = uploadDecorative.checked;
                if (uploadDecorative.checked) { uploadAlt.value = ''; }
            });
        }

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
        tagCatalog = Array.isArray(opts.tags) ? opts.tags : [];
        blockAllow = (opts.blockAllow && typeof opts.blockAllow === 'object') ? opts.blockAllow : {};
        pageType  = (typeof opts.pageType === 'string') ? opts.pageType : '';
        if (typeof opts.onDirty === 'function') { onDirty = opts.onDirty; }
        if (opts.ajaxUrl) { AJAX = opts.ajaxUrl; }

        model = (Array.isArray(opts.blocks) ? opts.blocks : []).map(normBlock);

        listEl  = document.getElementById('cmsBlockList');
        emptyEl = document.getElementById('cmsBlockEmpty');
        // toast is delegated to CmsAdmin.toast, which resolves .cms-toast itself.

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

        collapseAllBtn = document.getElementById('cmsCollapseAll');
        if (collapseAllBtn) {
            collapseAllBtn.addEventListener('click', function () {
                // Any expanded → collapse them all; all already collapsed → expand all.
                setAllCollapsed(anyBlockExpanded());
            });
        }

        wireAddBlock();
        wireMediaPicker();
        renderList();
        observeTheme();   // C31: reskin open editors when the app theme flips
    }

    return {
        init: init,
        serialize: function () {
            syncTiny();
            return model.map(function (b, i) {
                return {
                    // C15: send the stable id (0 = new row) so the server upsert
                    // matches existing rows instead of recreating them.
                    id:      b.id || 0,
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
        // C20: jump the author to the first save-blocking (invalid-JSON) block and
        // name it — call this from the host's save handler when hasJsonError() is
        // true so the block is loud + recoverable instead of a silent failed save.
        focusFirstError: function () {
            for (var i = 0; i < model.length; i++) {
                if (!model[i]._jsonError) { continue; }
                var row = rowForBlock(model[i]);
                var card = row ? row.querySelector('.cms-block-card') : null;
                if (card) {
                    // Blocks default to collapsed — open it, or the author is sent
                    // to a card whose broken field is hidden.
                    setExpanded(card, true);
                    card.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    card.classList.add('cms-block-error-flash');
                    setTimeout(function (c) { return function () { c.classList.remove('cms-block-error-flash'); }; }(card), 1500);
                }
                toast('The “' + labelFor(model[i].type) + '” block has invalid JSON — fix it, then save again.', 'error');
                return true;
            }
            return false;
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
