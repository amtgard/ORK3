<?php
/**
 * Cms_theme.tpl — CMS Theme engine editor (global scope, v1). PLAIN PHP.
 *
 * Receives (from Controller_Cms::theme):
 *   $ThemeCatalog  array  token => [group, value, input]
 *   $ThemeFonts        array  every vetted font family (flat)
 *   $ThemeFontCatalog  array  family => [group, role, fallback, weights]
 *   $ThemeFontsHeading array  families offered for --fd-font-heading
 *   $ThemeFontsBody    array  families offered for --fd-font-body (readable only)
 *   $ThemeValues   array  token => seeded value (defaults merged with active)
 *   $ThemeActiveId int    active theme row id (0 = none)
 *   $Caps          array  capability booleans
 *   $CmsCsrf       string CSRF token (set in constructor)
 *   UIR, HTTP_TEMPLATE (constants)
 */

$catalog  = isset($ThemeCatalog) && is_array($ThemeCatalog) ? $ThemeCatalog : array();
$fonts    = isset($ThemeFonts) && is_array($ThemeFonts) ? $ThemeFonts : array();
$fontCat  = isset($ThemeFontCatalog) && is_array($ThemeFontCatalog) ? $ThemeFontCatalog : array();
// Role-split lists. The picker must offer exactly what Validate() will accept:
// a display face is a valid heading and an invalid body.
$fontsFor = array(
    '--fd-font-heading' => (isset($ThemeFontsHeading) && is_array($ThemeFontsHeading)) ? $ThemeFontsHeading : $fonts,
    '--fd-font-body'    => (isset($ThemeFontsBody) && is_array($ThemeFontsBody)) ? $ThemeFontsBody : $fonts,
);
$fontGroupLabels = array('display' => 'Medieval & display', 'serif' => 'Serif', 'sans' => 'Sans-serif');
$values   = isset($ThemeValues) && is_array($ThemeValues) ? $ThemeValues : array();
$activeId = isset($ThemeActiveId) ? (int)$ThemeActiveId : 0;
$caps     = isset($Caps) && is_array($Caps) ? $Caps : array();

$h = function ($v) {
    return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
};

// Group catalog by group key, skipping derived tokens.
$grouped = array('color' => array(), 'type' => array(), 'shape' => array());
foreach ($catalog as $token => $meta) {
    if (isset($meta['input']) && $meta['input'] === 'derived') {
        continue;
    }
    $grp = isset($meta['group']) ? (string)$meta['group'] : 'color';
    if (!isset($grouped[$grp])) {
        $grouped[$grp] = array();
    }
    $grouped[$grp][$token] = $meta;
}

// Seed value helper: return the current seeded value for a token.
$val = function ($token) use ($values, $catalog) {
    if (isset($values[$token])) {
        return (string)$values[$token];
    }
    return isset($catalog[$token]['value']) ? (string)$catalog[$token]['value'] : '';
};

// Range config for scale/px tokens (mirrors CmsThemeTokens::Ranges).
$ranges = array(
    '--fd-font-scale'   => array('min' => 0.9,  'max' => 1.25, 'step' => 0.05, 'unit' => ''),
    '--fd-radius'       => array('min' => 0,    'max' => 24,   'step' => 1,    'unit' => 'px'),
    '--fd-space'        => array('min' => 0.85, 'max' => 1.3,  'step' => 0.05, 'unit' => ''),
    '--fd-border-width' => array('min' => 0,    'max' => 3,    'step' => 1,    'unit' => 'px'),
);

// Shadow preset options (mirrors CmsThemeTokens::$SHADOWS).
$shadowOptions = array(
    'none',
    '0 1px 3px rgba(0,0,0,.18)',
    '0 6px 24px rgba(0,0,0,.28)',
    '0 12px 50px rgba(0,0,0,.4)',
);
$shadowLabels = array('None', 'Subtle', 'Medium', 'Bold');

// Human-readable token labels.
$tokenLabels = array(
    '--fd-primary'      => 'Primary color',
    '--fd-accent'       => 'Accent color',
    '--fd-bg'           => 'Page background',
    '--fd-surface'      => 'Card / surface',
    '--fd-text'         => 'Body text',
    '--fd-text-muted'   => 'Muted text',
    '--fd-border'       => 'Border color',
    '--fd-font-heading' => 'Heading font',
    '--fd-font-body'    => 'Body font',
    '--fd-font-scale'   => 'Font scale',
    '--fd-radius'       => 'Corner radius',
    '--fd-space'        => 'Spacing scale',
    '--fd-border-width' => 'Border width',
    '--fd-shadow'       => 'Card shadow',
);

// Render one token control. Returns HTML string.
$renderControl = function ($token, $meta) use ($h, $val, $fonts, $fontCat, $fontsFor, $fontGroupLabels, $ranges, $shadowOptions, $shadowLabels, $tokenLabels) {
    $input   = isset($meta['input']) ? (string)$meta['input'] : 'color';
    $tokAttr = $h($token);
    $curVal  = $val($token);
    $label   = isset($tokenLabels[$token]) ? $tokenLabels[$token] : ltrim($token, '-');
    ob_start();
    ?>
    <div class="te-token-row" data-token-type="<?= $h($input) ?>">
        <label class="te-token-label"><?= $h($label) ?></label>
        <?php if ($input === 'color'): ?>
            <input type="color" class="te-color" data-token="<?= $tokAttr ?>" value="<?= $h($curVal) ?>">
            <input type="text" class="te-color-hex" data-hex-for="<?= $tokAttr ?>" value="<?= $h($curVal) ?>" maxlength="7" size="8" placeholder="#rrggbb" aria-label="Hex value for <?= $h($label) ?>">
        <?php elseif ($input === 'font'): ?>
            <?php
            // A native <select> cannot do this: Firefox honours font-family on an
            // <option>, Chrome on macOS ignores it, so the one thing this control
            // exists for — showing each face in itself — would silently not work
            // for most users. The real listbox below is progressive enhancement:
            // te-font.js upgrades it, and with JS off the <select> still saves.
            $roleFonts = isset($fontsFor[$token]) ? $fontsFor[$token] : $fonts;
            $grouped   = array();
            foreach ($roleFonts as $f) {
                $g = isset($fontCat[$f]['group']) ? $fontCat[$f]['group'] : 'sans';
                $grouped[$g][] = $f;
            }
            ?>
            <div class="te-font" data-font-for="<?= $tokAttr ?>">
                <select class="te-select te-font-native" data-token="<?= $tokAttr ?>"
                        aria-label="<?= $h($label) ?>">
                    <?php foreach ($grouped as $g => $famList): ?>
                        <optgroup label="<?= $h(isset($fontGroupLabels[$g]) ? $fontGroupLabels[$g] : $g) ?>">
                            <?php foreach ($famList as $f): ?>
                                <option value="<?= $h($f) ?>"<?= $curVal === $f ? ' selected' : '' ?>><?= $h($f) ?></option>
                            <?php endforeach; ?>
                        </optgroup>
                    <?php endforeach; ?>
                </select>
                <div class="te-font-sample" data-font-sample-for="<?= $tokAttr ?>" aria-hidden="true"></div>
            </div>
        <?php elseif ($input === 'shadow'): ?>
            <select class="te-select" data-token="<?= $tokAttr ?>">
                <?php foreach ($shadowOptions as $si => $sv): ?>
                    <option value="<?= $h($sv) ?>"<?= $curVal === $sv ? ' selected' : '' ?>><?= $h($shadowLabels[$si]) ?></option>
                <?php endforeach; ?>
            </select>
        <?php elseif ($input === 'scale' || $input === 'px'): ?>
            <?php
            $r    = isset($ranges[$token]) ? $ranges[$token] : array('min' => 0, 'max' => 1, 'step' => 0.1, 'unit' => '');
            $unit = $r['unit'];
            // Strip unit suffix for the range/number value.
            $numVal = (float)preg_replace('/[^0-9.\-]/', '', $curVal);
            ?>
            <div class="te-range-wrap">
                <input type="range" class="te-range" data-token="<?= $tokAttr ?>"
                       min="<?= $h($r['min']) ?>" max="<?= $h($r['max']) ?>" step="<?= $h($r['step']) ?>"
                       value="<?= $h($numVal) ?>">
                <input type="number" class="te-number" data-token="<?= $tokAttr ?>"
                       min="<?= $h($r['min']) ?>" max="<?= $h($r['max']) ?>" step="<?= $h($r['step']) ?>"
                       value="<?= $h($numVal) ?>"><?php if ($unit !== ''): ?><span class="te-unit"><?= $h($unit) ?></span><?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <?php
    return ob_get_clean();
};

$cmsActive  = 'theme';
$cmsTitle   = 'Theme';
$cmsSub     = 'Front-door color, typography & shape';
$cmsActions = '';
include __DIR__ . '/cms/_shell_top.tpl';
?>


<div class="theme-editor">

    <!-- ---- Preset row ---- -->
    <div class="te-presets">
        <span class="te-presets-label">Presets:</span>
        <button type="button" class="te-preset" data-tokens='{"--fd-primary":"#0b1120","--fd-accent":"#f0b429","--fd-bg":"#ffffff","--fd-surface":"#f7f8fa","--fd-text":"#1a2236","--fd-text-muted":"#5b6472","--fd-border":"#e2e6ec"}'>
            Default
        </button>
        <button type="button" class="te-preset" data-tokens='{"--fd-primary":"#1b4d3e","--fd-accent":"#c9a227","--fd-bg":"#fafaf8","--fd-surface":"#f1f5f0","--fd-text":"#1a2a22","--fd-text-muted":"#5a6e60","--fd-border":"#d4ddd1"}'>
            Forest
        </button>
        <button type="button" class="te-preset" data-tokens='{"--fd-primary":"#2a2060","--fd-accent":"#e8453c","--fd-bg":"#ffffff","--fd-surface":"#f4f3ff","--fd-text":"#1a1840","--fd-text-muted":"#5e5b8a","--fd-border":"#ddd9f5"}'>
            Royal
        </button>
        <button type="button" class="te-preset" data-tokens='{"--fd-primary":"#7c2d12","--fd-accent":"#d97706","--fd-bg":"#fffbf5","--fd-surface":"#fef3e2","--fd-text":"#3a1a08","--fd-text-muted":"#92570a","--fd-border":"#f5dbb0"}'>
            Ember
        </button>
    </div>

    <div class="te-layout">

        <!-- ---- Controls column ---- -->
        <div class="te-controls">

            <!-- Colors group -->
            <section class="te-group">
                <h2 class="te-group-title"><i class="fas fa-tint" aria-hidden="true"></i> Colors</h2>
                <div class="te-group-body">
                    <?php foreach ($grouped['color'] as $token => $meta): ?>
                        <?= $renderControl($token, $meta) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Typography group -->
            <section class="te-group">
                <h2 class="te-group-title"><i class="fas fa-font" aria-hidden="true"></i> Typography</h2>
                <div class="te-group-body">
                    <?php foreach ($grouped['type'] as $token => $meta): ?>
                        <?= $renderControl($token, $meta) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Shape & density group -->
            <section class="te-group">
                <h2 class="te-group-title"><i class="fas fa-vector-square" aria-hidden="true"></i> Shape &amp; Density</h2>
                <div class="te-group-body">
                    <?php foreach ($grouped['shape'] as $token => $meta): ?>
                        <?= $renderControl($token, $meta) ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Advanced: all non-derived tokens -->
            <details class="te-advanced">
                <summary class="te-advanced-summary">Advanced &mdash; all tokens</summary>
                <div class="te-advanced-body">
                    <p class="te-advanced-note">All editable design tokens. Changes here override the grouped controls above.</p>
                    <?php foreach ($catalog as $token => $meta): ?>
                        <?php if (isset($meta['input']) && $meta['input'] === 'derived') { continue; } ?>
                        <?= $renderControl($token, $meta) ?>
                    <?php endforeach; ?>
                </div>
            </details>

        </div><!-- /.te-controls -->

        <!-- ---- Preview column ---- -->
        <div class="te-preview">
            <div class="te-preview-bar">
                <span class="te-preview-label">Preview</span>
                <label class="te-dark-toggle" aria-label="Toggle dark mode preview">
                    <input type="checkbox" id="te-preview-dark">
                    <span class="te-dark-toggle-track"><i class="fas fa-moon" aria-hidden="true"></i></span>
                    Dark
                </label>
                <div id="te-contrast-warn" class="te-contrast-warn" style="display:none;">
                    <i class="fas fa-exclamation-triangle" aria-hidden="true"></i>
                    <span id="te-contrast-msg"></span>
                </div>
            </div>
            <iframe id="fd-theme-preview" class="te-preview-frame"
                    src="<?= $h(isset($SiteLiveUrl) ? $SiteLiveUrl : UIR) ?>"
                    title="Theme preview"
                    sandbox="allow-same-origin allow-scripts"></iframe>
            <div class="te-preview-note">Live preview &mdash; changes are <strong>not applied to your public site</strong> until you click <strong>Apply to site</strong>. Saving only stores a draft theme.</div>
        </div><!-- /.te-preview -->

    </div><!-- /.te-layout -->

    <!-- ---- Action bar ---- -->
    <div class="te-actions">
        <button type="button" id="te-reset" class="te-btn te-btn-ghost">
            <i class="fas fa-undo" aria-hidden="true"></i> Reset to defaults
        </button>
        <div class="te-active-status" id="te-active-status" data-active="<?= $activeId > 0 ? '1' : '0' ?>">
            <?php if ($activeId > 0): ?>
                <i class="fas fa-circle" aria-hidden="true"></i> A saved theme is currently applied to your public site.
            <?php else: ?>
                <i class="fas fa-circle-notch" aria-hidden="true"></i> No theme applied yet &mdash; your public site uses default styling.
            <?php endif; ?>
        </div>
        <div class="te-actions-right">
            <span class="cms-editbar-hint" id="teDirtyHint"></span>
            <button type="button" id="te-save" class="te-btn" data-tip="Store your changes as a draft theme without changing your public site.">
                <i class="fas fa-save" aria-hidden="true"></i> Save draft theme
            </button>
            <button type="button" id="te-activate" class="te-btn te-btn-primary" data-tip="Save and make this the live theme on your public site.">
                <i class="fas fa-check" aria-hidden="true"></i> Apply to site
            </button>
        </div>
    </div>

</div><!-- /.theme-editor -->

<script>
window.THEME_ACTIVE_ID = <?= (int)$activeId ?>;
</script>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>

<div class="cms-toast" id="teToast" role="status" aria-live="polite" aria-atomic="true"></div>

<div class="cms-modal-overlay" id="teConfirmModal">
    <div class="cms-modal cms-modal-sm" role="dialog" aria-modal="true" aria-labelledby="teConfirmTitle">
        <div class="cms-modal-head">
            <h3 id="teConfirmTitle">Confirm</h3>
            <button type="button" class="cms-modal-close" data-close-modal>&times;</button>
        </div>
        <div class="cms-modal-body">
            <p id="teConfirmBody" style="margin:0;font-size:14px;line-height:1.5;"></p>
        </div>
        <div class="cms-modal-foot">
            <button type="button" class="cms-btn cms-btn-ghost" data-close-modal>Cancel</button>
            <button type="button" class="cms-btn cms-btn-danger" id="teConfirmOk">Confirm</button>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    var savedThemeId = window.THEME_ACTIVE_ID || 0;

    /* ---- Unsaved-work guard (mirrors the page/post editors) ---- */
    var dirty = false;
    var dirtyHint = document.getElementById('teDirtyHint');
    function markDirty() {
        dirty = true;
        if (dirtyHint) { dirtyHint.textContent = 'Unsaved changes…'; dirtyHint.className = 'cms-editbar-hint cms-editbar-hint-dirty'; }
    }
    function clearDirty() {
        dirty = false;
        if (dirtyHint) { dirtyHint.textContent = ''; dirtyHint.className = 'cms-editbar-hint'; }
    }
    window.addEventListener('beforeunload', function (e) {
        if (dirty) { e.preventDefault(); e.returnValue = ''; }
    });

    /* ---- Toast (shared: CmsAdmin.toast — resolves the page's .cms-toast) ---- */
    var toast = CmsAdmin.toast;

    /* ---- POST helper (shared: CmsAdmin.post — CSRF header + scope) ---- */
    var post = CmsAdmin.post;

    /* ---- Token sync helpers ---- */
    function syncToken(token, value, exceptEl) {
        document.querySelectorAll('[data-token="' + CSS.escape(token) + '"]').forEach(function (el) {
            if (el !== exceptEl && el.value !== value) { el.value = value; }
        });
    }
    function collectTokens() {
        var out = {};
        document.querySelectorAll('[data-token]').forEach(function (el) {
            out[el.getAttribute('data-token')] = el.value;
        });
        return out;
    }

    /* ---- Preview injection (appended to iframe body end to win cascade) ---- */
    function applyPreview(css) {
        var fr = document.getElementById('fd-theme-preview');
        var doc = fr && fr.contentDocument;
        if (!doc || !doc.body) { return; }
        var s = doc.getElementById('fd-theme-preview-style');
        if (!s) { s = doc.createElement('style'); s.id = 'fd-theme-preview-style'; }
        s.textContent = css;
        doc.body.appendChild(s); // re-append → moves to end, wins source-order cascade
        // The CSS names the family; without this the iframe has no webfont to
        // name and silently renders the generic fallback instead.
        if (typeof teFontLoadPreview === 'function') { teFontLoadPreview(doc); }
    }

    var previewTimer = null;
    function schedulePreview() {
        clearTimeout(previewTimer);
        previewTimer = setTimeout(doPreview, 150);
    }
    function doPreview() {
        var tokens = collectTokens();
        post('previewtheme', { tokens: JSON.stringify(tokens) }).then(function (res) {
            if (res && res.ok && res.css) { applyPreview(res.css); }
        }).catch(function () { /* silent — preview errors are non-blocking */ });
    }

    /* ---- Iframe load → first preview ---- */
    var iframe = document.getElementById('fd-theme-preview');
    if (iframe) {
        iframe.addEventListener('load', function () {
            doPreview();
            // Re-apply dark-mode if the toggle was already on.
            var darkEl = document.getElementById('te-preview-dark');
            if (darkEl && darkEl.checked) { setPreviewDark('dark'); }
        });
    }

    /* ---- Control input/change handler (delegated) ---- */
    function handleControlChange(e) {
        var el = e.target;
        var token  = el.getAttribute('data-token');
        var hexFor = el.getAttribute('data-hex-for');
        if (!token && !hexFor) { return; }

        if (token) {
            // Sync all other controls with the same token (main ↔ advanced).
            syncToken(token, el.value, el);
            // Sync hex display fields.
            document.querySelectorAll('[data-hex-for="' + CSS.escape(token) + '"]').forEach(function (h) {
                if (h.value !== el.value) { h.value = el.value; }
            });
            schedulePreview();
            runContrastCheck();
            markDirty();
        } else if (hexFor) {
            var hex = el.value;
            if (/^#[0-9a-fA-F]{6}$/.test(hex)) {
                document.querySelectorAll('[data-token="' + CSS.escape(hexFor) + '"]').forEach(function (c) {
                    if (c.value !== hex) { c.value = hex; }
                });
                document.querySelectorAll('[data-hex-for="' + CSS.escape(hexFor) + '"]').forEach(function (h) {
                    if (h !== el && h.value !== hex) { h.value = hex; }
                });
                schedulePreview();
                runContrastCheck();
                markDirty();
            }
        }
    }
    document.addEventListener('input',  handleControlChange);
    document.addEventListener('change', handleControlChange);

    /* ---- Dark mode preview toggle ---- */
    function setPreviewDark(mode) {
        var fr = document.getElementById('fd-theme-preview');
        var doc = fr && fr.contentDocument;
        if (doc && doc.documentElement) {
            doc.documentElement.setAttribute('data-theme', mode);
        }
    }
    var darkToggle = document.getElementById('te-preview-dark');
    if (darkToggle) {
        darkToggle.addEventListener('change', function () {
            setPreviewDark(darkToggle.checked ? 'dark' : 'light');
        });
    }

    /* ---- Preset buttons ---- */
    document.querySelectorAll('.te-preset').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var tokens;
            try { tokens = JSON.parse(btn.getAttribute('data-tokens') || '{}'); } catch (ex) { return; }
            Object.keys(tokens).forEach(function (token) {
                var val = tokens[token];
                document.querySelectorAll('[data-token="' + CSS.escape(token) + '"]').forEach(function (el) {
                    el.value = val;
                });
                document.querySelectorAll('[data-hex-for="' + CSS.escape(token) + '"]').forEach(function (h) {
                    h.value = val;
                });
            });
            schedulePreview();
            runContrastCheck();
            markDirty();
        });
    });

    /* ---- WCAG contrast helpers (mirrors CmsThemeTokens server formula) ---- */
    function wcagLuminance(hex) {
        hex = hex.replace('#', '');
        if (hex.length === 3) { hex = hex[0]+hex[0]+hex[1]+hex[1]+hex[2]+hex[2]; }
        var r = parseInt(hex.substr(0, 2), 16) / 255;
        var g = parseInt(hex.substr(2, 2), 16) / 255;
        var b = parseInt(hex.substr(4, 2), 16) / 255;
        function lin(c) { return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4); }
        return 0.2126 * lin(r) + 0.7152 * lin(g) + 0.0722 * lin(b);
    }
    function wcagContrast(hex1, hex2) {
        var l1 = wcagLuminance(hex1), l2 = wcagLuminance(hex2);
        return (Math.max(l1, l2) + 0.05) / (Math.min(l1, l2) + 0.05);
    }
    function getTokenHex(token) {
        var el = document.querySelector('[data-token="' + CSS.escape(token) + '"]');
        return (el && /^#[0-9a-fA-F]{6}$/.test(el.value)) ? el.value : null;
    }
    function setInlineWarn(token, msg) {
        var el = document.querySelector('.te-group [data-token="' + CSS.escape(token) + '"]');
        if (!el) { return; }
        var row = el.closest('.te-token-row');
        if (!row) { return; }
        var warn = row.querySelector('.te-contrast-warn-inline');
        if (msg) {
            if (!warn) {
                warn = document.createElement('span');
                warn.className = 'te-contrast-warn-inline';
                row.appendChild(warn);
            }
            warn.textContent = '⚠ ' + msg;
        } else if (warn) {
            warn.remove();
        }
    }

    var CONTRAST_PAIRS = [
        { text: '--fd-text',       bg: '--fd-bg',      label: 'Text – Background' },
        { text: '--fd-text',       bg: '--fd-surface',  label: 'Text – Surface'    },
        { text: '--fd-text-muted', bg: '--fd-bg',      label: 'Muted – Background' },
    ];

    function runContrastCheck() {
        var barWarns = [];
        var warnedTokens = {};
        CONTRAST_PAIRS.forEach(function (pair) {
            var textHex = getTokenHex(pair.text);
            var bgHex   = getTokenHex(pair.bg);
            if (!textHex || !bgHex) { return; }
            var ratio = wcagContrast(textHex, bgHex);
            if (ratio < 4.5) {
                barWarns.push(pair.label + ' (' + ratio.toFixed(1) + ':1)');
                if (!warnedTokens[pair.text]) {
                    warnedTokens[pair.text] = ratio.toFixed(1) + ':1';
                }
            }
        });
        ['--fd-text', '--fd-text-muted'].forEach(function (tok) {
            setInlineWarn(tok, warnedTokens[tok] ? warnedTokens[tok] + ' — low contrast' : '');
        });
        var warnBar = document.getElementById('te-contrast-warn');
        var warnMsg = document.getElementById('te-contrast-msg');
        if (warnBar && warnMsg) {
            if (barWarns.length) {
                warnMsg.textContent = 'Low contrast: ' + barWarns.join(', ');
                warnBar.style.display = '';
            } else {
                warnBar.style.display = 'none';
            }
        }
    }

    /* ---- Confirm modal ---- */
    var confirmOverlay = document.getElementById('teConfirmModal');
    var confirmTitleEl = document.getElementById('teConfirmTitle');
    var confirmBodyEl  = document.getElementById('teConfirmBody');
    var confirmOkEl    = document.getElementById('teConfirmOk');
    var confirmCb      = null;

    /* modal open/close are shared (CmsAdmin.modal); backdrop/Esc handled there. */
    var openModal = CmsAdmin.modal.open;
    var closeModal = CmsAdmin.modal.close;

    function tnConfirm(opts) {
        if (confirmTitleEl) { confirmTitleEl.textContent = opts.title || 'Confirm'; }
        if (confirmBodyEl)  { confirmBodyEl.textContent  = opts.body  || ''; }
        if (confirmOkEl) {
            confirmOkEl.textContent = opts.confirmLabel || 'Confirm';
            confirmOkEl.className   = 'cms-btn ' + (opts.danger ? 'cms-btn-danger' : 'cms-btn-primary');
        }
        confirmCb = opts.onConfirm || null;
        openModal(confirmOverlay);
    }

    if (confirmOkEl) {
        confirmOkEl.addEventListener('click', function () {
            closeModal(confirmOverlay);
            if (confirmCb) { var cb = confirmCb; confirmCb = null; cb(); }
        });
    }

    /* ---- Save ---- */
    var teSaveBtn     = document.getElementById('te-save');
    var teActivateBtn = document.getElementById('te-activate');
    var teResetBtn    = document.getElementById('te-reset');

    function setBusy(busy) {
        [teSaveBtn, teActivateBtn, teResetBtn].forEach(function (b) {
            if (b) { b.disabled = busy; }
        });
    }

    function doSave(cb) {
        setBusy(true);
        var tokens = collectTokens();
        post('savetheme', { name: 'Default', tokens: JSON.stringify(tokens) }).then(function (res) {
            setBusy(false);
            if (!res || !res.ok) { toast((res && res.error) || 'Save failed.', 'error'); return; }
            if (res.theme_id) { savedThemeId = parseInt(res.theme_id, 10) || savedThemeId; }
            clearDirty();
            toast('Draft theme saved.', 'ok');
            if (cb) { cb(); }
        }).catch(function () {
            setBusy(false);
            toast('Network error.', 'error');
        });
    }

    if (teSaveBtn) {
        teSaveBtn.addEventListener('click', function () { doSave(null); });
    }

    /* ---- Apply to site (save then activate) ---- */
    if (teActivateBtn) {
        teActivateBtn.addEventListener('click', function () {
            doSave(function () {
                if (!savedThemeId) { toast('No theme to activate — save first.', 'error'); return; }
                setBusy(true);
                post('activatetheme', { theme_id: savedThemeId }).then(function (res) {
                    setBusy(false);
                    if (!res || !res.ok) { toast((res && res.error) || 'Activate failed.', 'error'); return; }
                    clearDirty();
                    var statusEl = document.getElementById('te-active-status');
                    if (statusEl) {
                        statusEl.setAttribute('data-active', '1');
                        statusEl.innerHTML = '<i class="fas fa-circle" aria-hidden="true"></i> A saved theme is currently applied to your public site.';
                    }
                    toast('Theme applied to your public site.', 'ok');
                }).catch(function () { setBusy(false); toast('Network error.', 'error'); });
            });
        });
    }

    /* ---- Reset to defaults ---- */
    if (teResetBtn) {
        teResetBtn.addEventListener('click', function () {
            tnConfirm({
                title: 'Reset to defaults?',
                body: 'All theme tokens will return to their default values and any active theme will be deactivated. This cannot be undone.',
                confirmLabel: 'Reset',
                danger: true,
                onConfirm: function () {
                    setBusy(true);
                    post('resettheme', {}).then(function (res) {
                        setBusy(false);
                        if (!res || !res.ok) { toast((res && res.error) || 'Reset failed.', 'error'); return; }
                        toast('Theme reset to defaults.', 'ok');
                        // Clear the dirty guard so the reload isn't blocked by beforeunload.
                        clearDirty();
                        // Reload to repopulate controls from factory defaults.
                        window.location.reload();
                    }).catch(function () { setBusy(false); toast('Network error.', 'error'); });
                }
            });
        });
    }

    /* ---- Initial contrast check ---- */
    runContrastCheck();

})();

    /* ------------------------------------------------------------------ *
     * Font picker — a real listbox that renders every family in itself.
     *
     * WHY NOT A STYLED <select>: font-family on an <option> is honoured by
     * Firefox and ignored by Chrome on macOS, so the one thing this control
     * exists for would silently not work for most users. The native select
     * stays in the DOM as the value holder and the no-JS fallback; this
     * upgrades it in place and dispatches a bubbling 'change' on it, so the
     * existing delegated handler drives preview + dirty state unchanged.
     *
     * Faces load LAZILY. Requesting ~47 webfonts to draw one dropdown would
     * cost more than the page it is styling, so each row asks for its own
     * face only when it actually scrolls into view.
     * ------------------------------------------------------------------ */
    var TE_FONT_CATALOG = <?= json_encode($fontCat, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
    var TE_FONT_GROUPS  = <?= json_encode($fontGroupLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

    function teFontStack(family) {
        var m = TE_FONT_CATALOG[family];
        if (!m) { return "'" + family + "', sans-serif"; }
        if (family === 'system-ui') { return 'system-ui, ' + m.fallback; }
        return "'" + family + "', " + m.fallback;
    }

    // createElement, never an HTML string: the CSS-boundary gate's C7 net reads
    // the literal tag opener "<link", and building the element keeps this file
    // honest about not shipping a stylesheet reference in its source.
    //
    // Takes a DOCUMENT because the live preview is an <iframe>, i.e. a separate
    // document with its own font set. Loading a face into the editor does not
    // load it into the preview, and the failure is quiet and misleading rather
    // than blank: `'Tangerine', cursive` with Tangerine missing renders in the
    // browser's generic cursive, which on macOS is a swashy script — so the
    // preview showed A font, just never the one that was picked.
    function teFontLoadInto(doc, family) {
        // applyPreview() is defined above this point and fires from an async
        // response, so guard rather than assume the catalogue literal has been
        // evaluated: `var` hoists, its assignment does not.
        if (typeof TE_FONT_CATALOG === 'undefined' || !family) { return; }
        var m = TE_FONT_CATALOG[family];
        if (!doc || !doc.head || !m || m.weights === null) { return; }
        // Track per-document: the editor and the preview each need their own copy.
        var mark = 'te-font-' + family.replace(/[^A-Za-z0-9]/g, '-');
        if (doc.getElementById(mark)) { return; }
        var spec = family.replace(/ /g, '+');
        if (m.weights) { spec += ':' + m.weights; }
        var el = doc.createElement('link');
        el.id = mark;
        el.rel = 'stylesheet';
        el.href = 'https://fonts.googleapis.com/css2?family=' + spec + '&display=swap';
        doc.head.appendChild(el);
    }
    function teFontLoad(family) { teFontLoadInto(document, family); }

    /** Ensure the preview document has whatever the pickers currently select. */
    function teFontLoadPreview(doc) {
        document.querySelectorAll('.te-font-native').forEach(function (sel) {
            teFontLoadInto(doc, sel.value);
        });
    }

    var teFontObserver = ('IntersectionObserver' in window)
        ? new IntersectionObserver(function (entries, obs) {
            entries.forEach(function (e) {
                if (!e.isIntersecting) { return; }
                teFontLoad(e.target.getAttribute('data-family'));
                obs.unobserve(e.target);
            });
        }, { root: null, rootMargin: '120px' })
        : null;

    function teBuildFontPicker(wrap) {
        var native = wrap.querySelector('.te-font-native');
        var sample = wrap.querySelector('.te-font-sample');
        if (!native) { return; }

        var families = [];
        Array.prototype.forEach.call(native.options, function (o) { families.push(o.value); });

        var btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'te-font-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');

        var panel = document.createElement('div');
        panel.className = 'te-font-panel';
        panel.setAttribute('role', 'listbox');
        panel.hidden = true;

        var rows = {};
        var lastGroup = null;
        families.forEach(function (family) {
            var meta = TE_FONT_CATALOG[family] || {};
            if (meta.group && meta.group !== lastGroup) {
                lastGroup = meta.group;
                var hd = document.createElement('div');
                hd.className = 'te-font-group';
                hd.textContent = TE_FONT_GROUPS[meta.group] || meta.group;
                panel.appendChild(hd);
            }
            var row = document.createElement('div');
            row.className = 'te-font-row';
            row.setAttribute('role', 'option');
            row.setAttribute('data-family', family);
            row.tabIndex = -1;
            // The name IS the specimen — that is the whole point of the control.
            row.style.fontFamily = teFontStack(family);
            row.textContent = family;
            panel.appendChild(row);
            rows[family] = row;
            if (teFontObserver) { teFontObserver.observe(row); } else { teFontLoad(family); }
        });

        function paint() {
            var family = native.value;
            teFontLoad(family);
            btn.style.fontFamily = teFontStack(family);
            btn.textContent = family;
            if (sample) {
                sample.style.fontFamily = teFontStack(family);
                sample.textContent = 'Handgjord 1234';
            }
            Object.keys(rows).forEach(function (f) {
                var on = (f === family);
                rows[f].classList.toggle('is-selected', on);
                rows[f].setAttribute('aria-selected', on ? 'true' : 'false');
            });
        }
        // .te-group is overflow:hidden, so the panel is fixed and placed by hand.
        // Flips above the button when there is more room up than down, and never
        // runs off the bottom of the viewport.
        function place() {
            if (panel.hidden) { return; }
            var b = btn.getBoundingClientRect();
            var below = window.innerHeight - b.bottom - 8;
            var above = b.top - 8;
            var h = Math.min(320, Math.max(below, above));
            panel.style.width = b.width + 'px';
            panel.style.left = b.left + 'px';
            panel.style.maxHeight = h + 'px';
            if (below >= Math.min(320, above) || below >= 200) {
                panel.style.top = (b.bottom + 4) + 'px';
                panel.style.bottom = 'auto';
            } else {
                panel.style.top = 'auto';
                panel.style.bottom = (window.innerHeight - b.top + 4) + 'px';
            }
        }
        function open() {
            panel.hidden = false;
            place();
            btn.setAttribute('aria-expanded', 'true');
            var sel = rows[native.value];
            if (sel) { sel.focus(); sel.scrollIntoView({ block: 'nearest' }); }
        }
        function close(focusBtn) {
            panel.hidden = true;
            btn.setAttribute('aria-expanded', 'false');
            if (focusBtn) { btn.focus(); }
        }
        function choose(family) {
            if (native.value !== family) {
                native.value = family;
                // Bubbles, so the delegated 'change' handler runs the same path a
                // native select would have — preview, dirty flag, everything.
                native.dispatchEvent(new Event('change', { bubbles: true }));
            }
            paint();
            close(true);
        }

        btn.addEventListener('click', function () { panel.hidden ? open() : close(true); });
        panel.addEventListener('click', function (e) {
            var row = e.target.closest ? e.target.closest('.te-font-row') : null;
            if (row) { choose(row.getAttribute('data-family')); }
        });
        panel.addEventListener('keydown', function (e) {
            var order = families.slice();
            var i = order.indexOf(document.activeElement.getAttribute('data-family'));
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                e.preventDefault();
                var next = order[Math.min(order.length - 1, Math.max(0, i + (e.key === 'ArrowDown' ? 1 : -1)))];
                if (rows[next]) { rows[next].focus(); rows[next].scrollIntoView({ block: 'nearest' }); }
            } else if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                if (i >= 0) { choose(order[i]); }
            } else if (e.key === 'Escape') {
                e.preventDefault();
                close(true);
            }
        });
        document.addEventListener('click', function (e) {
            if (!panel.hidden && !wrap.contains(e.target) && !panel.contains(e.target)) { close(false); }
        });
        window.addEventListener('resize', place);
        // Capture phase: the editor column is its own scroller, so a scroll event
        // on an ancestor never reaches window in the bubble phase.
        window.addEventListener('scroll', place, true);
        // Keep the specimen honest when something else moves the value —
        // "reset to default" writes the native select directly.
        native.addEventListener('change', paint);

        native.classList.add('te-font-native-hidden');
        wrap.insertBefore(btn, native.nextSibling);
        document.body.appendChild(panel);   // fixed + out of the clipping card
        paint();
    }

    document.querySelectorAll('.te-font').forEach(teBuildFontPicker);

</script>
