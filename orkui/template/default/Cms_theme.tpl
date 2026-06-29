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
$renderControl = function ($token, $meta, $idSuffix) use ($h, $val, $fonts, $ranges, $shadowOptions, $shadowLabels, $tokenLabels) {
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
                        <?= $renderControl($token, $meta, 'main') ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Typography group -->
            <section class="te-group">
                <h2 class="te-group-title"><i class="fas fa-font" aria-hidden="true"></i> Typography</h2>
                <div class="te-group-body">
                    <?php foreach ($grouped['type'] as $token => $meta): ?>
                        <?= $renderControl($token, $meta, 'main') ?>
                    <?php endforeach; ?>
                </div>
            </section>

            <!-- Shape & density group -->
            <section class="te-group">
                <h2 class="te-group-title"><i class="fas fa-vector-square" aria-hidden="true"></i> Shape &amp; Density</h2>
                <div class="te-group-body">
                    <?php foreach ($grouped['shape'] as $token => $meta): ?>
                        <?= $renderControl($token, $meta, 'main') ?>
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
                        <?= $renderControl($token, $meta, 'adv') ?>
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
            <div class="te-preview-note">Preview is live in the next task. Changes are not applied until you save.</div>
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
window.THEME_CATALOG   = <?= json_encode($catalog, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.THEME_FONTS     = <?= json_encode(array_values($fonts), JSON_HEX_TAG) ?>;
window.THEME_VALUES    = <?= json_encode($values, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
window.THEME_ACTIVE_ID = <?= (int)$activeId ?>;
</script>

<?php include __DIR__ . '/cms/_shell_bottom.tpl'; ?>
