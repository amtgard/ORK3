<?php
/**
 * Cms_theme.tpl — CMS Theme engine editor (global scope, v1). PLAIN PHP.
 *
 * Receives (from Controller_Cms::theme):
 *   $ThemeCatalog  array  token => [group, value, input]
 *   $ThemeFonts    array  vetted font family names
 *   $ThemeValues   array  token => seeded value (defaults merged with active)
 *   $ThemeActiveId int    active theme row id (0 = none)
 *   $Caps          array  capability booleans
 *   $CmsCsrf       string CSRF token (set in constructor)
 *   UIR, HTTP_TEMPLATE (constants)
 */

$catalog  = isset($ThemeCatalog) && is_array($ThemeCatalog) ? $ThemeCatalog : array();
$fonts    = isset($ThemeFonts) && is_array($ThemeFonts) ? $ThemeFonts : array();
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
$renderControl = function ($token, $meta) use ($h, $val, $fonts, $ranges, $shadowOptions, $shadowLabels, $tokenLabels) {
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
            <select class="te-select" data-token="<?= $tokAttr ?>">
                <?php foreach ($fonts as $f): ?>
                    <option value="<?= $h($f) ?>"<?= $curVal === $f ? ' selected' : '' ?>><?= $h($f) ?></option>
                <?php endforeach; ?>
            </select>
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

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/cms-admin.css?v=<?= filemtime(__DIR__ . '/style/cms-admin.css') ?>">

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
                    src="<?= $h(UIR) ?>"
                    title="Theme preview"
                    sandbox="allow-same-origin allow-scripts"></iframe>
            <div class="te-preview-note">Live preview &mdash; changes are not applied to your site until you Save.</div>
        </div><!-- /.te-preview -->

    </div><!-- /.te-layout -->

    <!-- ---- Action bar ---- -->
    <div class="te-actions">
        <button type="button" id="te-reset" class="te-btn te-btn-ghost">
            <i class="fas fa-undo" aria-hidden="true"></i> Reset to defaults
        </button>
        <div class="te-actions-right">
            <button type="button" id="te-save" class="te-btn">
                <i class="fas fa-save" aria-hidden="true"></i> Save
            </button>
            <button type="button" id="te-activate" class="te-btn te-btn-primary">
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

    var AJAX = <?= json_encode(UIR) ?> + 'CmsAjax/';
    var CSRF = window.CMS_CSRF || '';
    var savedThemeId = window.THEME_ACTIVE_ID || 0;

    /* ---- Toast ---- */
    var toastEl = document.getElementById('teToast');
    var toastTimer = null;
    function toast(msg, kind) {
        if (!toastEl) { return; }
        toastEl.textContent = msg;
        toastEl.className = 'cms-toast cms-show' + (kind ? ' cms-toast-' + kind : '');
        clearTimeout(toastTimer);
        toastTimer = setTimeout(function () { toastEl.className = 'cms-toast'; }, 3200);
    }

    /* ---- POST helper ---- */
    function post(endpoint, params) {
        var body = new URLSearchParams();
        Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(AJAX + endpoint, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-CSRF-Token': CSRF },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { if (!r.ok) { throw new Error('HTTP ' + r.status); } return r.json(); });
    }

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

    function openModal(el)  { if (el) { el.classList.add('cms-open'); } }
    function closeModal(el) { if (el) { el.classList.remove('cms-open'); } }

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
            toast('Theme saved.', 'ok');
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
                    toast('Theme applied to your site.', 'ok');
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
</script>
