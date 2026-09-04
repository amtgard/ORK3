<?php
/**
 * "Print a new scroll" — an officer composes one certificate by hand.
 *
 * Controls on the left, live preview on the right. Only order, rank and variant
 * need a round trip (they change which art layers are drawn); the text fields
 * write straight into the preview's DOM, and the heraldry sliders write CSS
 * custom properties, so neither costs a fetch.
 */
$scope   = is_array($Scope ?? null) ? $Scope : [];
$choices = is_array($Awards ?? null) ? $Awards : [];
$css     = DIR_TEMPLATE . 'revised-frontend/style/scrollery.css';
$today   = date('Y-m-d');

/*
 * The custom scroll's composable vocabulary. Taken from the controller when it
 * passes one, and otherwise straight off Scroll — Recipes() is a memoised read of
 * a plain data file with no database behind it, and scroll/custom.tpl already makes
 * exactly this call when it renders, so the composer is not reaching anywhere the
 * renderer does not.
 */
$recipes = is_array($Recipes ?? null) ? $Recipes : Scroll::Recipes();
$default = Scroll::DEFAULT_RECIPE;

/*
 * Which grounds are dark, which are washes and which border is a band are Scroll's
 * to say — the renderer composites per ground tone off these same constants, and a
 * picker that re-derived any of it (by name, or by measuring the image) would be a
 * second opinion that can disagree. Read them; never restate them.
 *
 * They are surfaced as per-option notes rather than as styling, because what the
 * officer needs is the reason a choice will look different, not a decoration.
 */
$groundNotes = [];
foreach (Scroll::DARK_GROUNDS as $key) {
    $groundNotes[$key] = ucfirst($key) . ' is a dark ground: the border, the numeral and '
        . 'the heraldic device are lifted rather than multiplied so they stay legible on it.';
}
foreach (array_keys(Scroll::GROUND_WASHES) as $key) {
    $groundNotes[$key] = ucfirst($key) . ' is a soft-light wash laid over the parchment '
        . 'rather than a plate of its own, so the paper still reads through it.';
}
$borderNotes = [
    Scroll::BORDER_BAND => ucfirst(Scroll::BORDER_BAND) . ' is a decorative band centred '
        . 'over the knotwork, not a frame around the whole page.',
];

/*
 * Vocabulary keys are identifiers, not display text; ucfirst() is the rule and this
 * map is the handful that read badly under it. Renaming an entry in
 * scroll-recipes.php falls back to ucfirst() — a plainer label, never a broken one.
 */
$recipeLabels = [
    'knot'       => 'Knotwork',
    'starfield2' => 'Starfield II',
    'rosewash'   => 'Rose wash',
    'map'        => 'Cartography',
];
$recipeLabel = static function (string $key) use ($recipeLabels): string {
    return $recipeLabels[$key] ?? ucfirst($key);
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime($css) ?>">
<style>
.sc-wrap       { max-width: 1400px; margin: 0 auto 80px; }
.sc-cols       { display: grid; grid-template-columns: minmax(320px, 380px) 1fr; gap: 20px; align-items: start; }
.sc-field      { margin-bottom: 12px; position: relative; }
.sc-field label{ display: block; font-size: 11px; letter-spacing: .05em; text-transform: uppercase;
                 color: var(--ork-text-hint); margin-bottom: 4px; }
.sc-field input[type=text], .sc-field input[type=date], .sc-field select, .sc-field textarea {
                 width: 100%; box-sizing: border-box; }
.sc-row        { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.sc-toggles    { display: flex; gap: 16px; align-items: center; font-size: 12px; margin-bottom: 12px; }
.sc-toggles label { display: flex; gap: 5px; align-items: center; text-transform: none; letter-spacing: 0;
                 font-size: 12px; margin: 0; color: inherit; }
.sc-preview    { background: #4a4a4a; padding: 14px; border-radius: 6px; position: sticky; top: 12px; }
.sc-stage      { background: #fff; aspect-ratio: 8.5/11; overflow: hidden; }
.sc-stage.is-busy { opacity: .45; transition: opacity .12s; }
.sc-actions    { display: flex; gap: 8px; margin-top: 14px; }
.sc-err        { color: #e53e3e; font-size: 12px; min-height: 16px; margin-top: 6px; }
.sc-sliders    { margin-top: 14px; }
.sc-slider     { display: grid; grid-template-columns: 78px 1fr 24px; gap: 8px; align-items: center;
                 font-size: 11px; color: var(--ork-text-hint); margin-bottom: 5px; }
.sc-reset      { background: none; border: 0; color: var(--ork-text-hint); cursor: pointer;
                 padding: 2px; font-size: 10px; border-radius: 3px; }
.sc-reset:hover{ color: var(--ork-text); background: rgba(128,128,128,.15); }
/* The custom scroll's design block — only ever shown for the custom order. */
.sc-recipe     { border-top: 1px solid var(--ork-border); padding-top: 12px; margin-bottom: 4px; }
.sc-recipe-head{ font-size: 11px; letter-spacing: .05em; text-transform: uppercase;
                 color: var(--ork-text-muted); font-weight: 600; margin-bottom: 8px; }
.sc-swatch     { display: inline-flex; width: 34px; height: 11px; border-radius: 2px; overflow: hidden;
                 vertical-align: -1px; margin-left: 6px; border: 1px solid var(--ork-border); }
.sc-swatch i   { flex: 1 1 50%; }
/* text-align is not cosmetic here: orkui.css carries a global `p { text-align:
   justify }`, and justified 11px prose in a 380px column stretches to gaps. */
.sc-note       { font-size: 11px; line-height: 1.45; color: var(--ork-text-muted);
                 margin: 0 0 12px; text-align: left; }
.sc-note:empty { display: none; }
.sc-field.is-inert            { opacity: .55; }
.sc-field.is-inert select     { pointer-events: none; }
@media (max-width: 900px) { .sc-cols { grid-template-columns: 1fr; } .sc-preview { position: static; } }
</style>

<div class="adm-wrap sc-wrap">
    <div class="adm-page-header">
        <h1>Print a new scroll</h1>
        <div class="adm-breadcrumb">
            <?= htmlspecialchars(trim(($scope['Title'] ?? '') . ' ' . ($scope['Name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
        </div>
    </div>

    <div class="sc-cols">
        <div class="adm-card" style="padding:18px">
            <div class="sc-field">
                <label for="sc-award">Award</label>
                <select id="sc-award">
                    <?php foreach ($choices as $c): ?>
                        <option value="<?= (int) $c['AwardId'] ?>"
                                data-order="<?= htmlspecialchars($c['Order'], ENT_QUOTES, 'UTF-8') ?>"
                                data-min="<?= (int) $c['MinRank'] ?>"
                                data-max="<?= (int) $c['MaxRank'] ?>"
                                data-variants="<?= htmlspecialchars(implode(',', $c['Variants']), ENT_QUOTES, 'UTF-8') ?>"
                                data-name="<?= htmlspecialchars($c['Name'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($c['Name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="sc-row">
                <div class="sc-field">
                    <label for="sc-rank">Rank</label>
                    <select id="sc-rank"></select>
                </div>
                <div class="sc-field" id="sc-variant-field" style="display:none">
                    <label for="sc-variant">Variant</label>
                    <select id="sc-variant"></select>
                </div>
            </div>

            <?php /* Shown only when the chosen award resolves to the `custom` order —
                     every other order's art is fixed by its own partial, so these
                     controls would be inert lies anywhere else. The selected values
                     start on Scroll::DEFAULT_RECIPE, which is what every path that
                     prints WITHOUT a recipe (a reprint, a bulk run) already uses, so
                     the composer opens on the same certificate those produce. */ ?>
            <div id="sc-recipe" class="sc-recipe" style="display:none">
                <div class="sc-recipe-head">Custom scroll design</div>
                <div class="sc-row">
                    <div class="sc-field">
                        <label for="sc-ground">Ground</label>
                        <select id="sc-ground">
                            <?php foreach (array_keys($recipes['grounds']) as $key): ?>
                                <option value="<?= scroll_e($key) ?>"
                                        <?= isset($groundNotes[$key]) ? 'data-note="' . scroll_e($groundNotes[$key]) . '"' : '' ?>
                                        <?= $key === $default['ground'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field">
                        <label for="sc-border">Border</label>
                        <select id="sc-border">
                            <?php foreach (array_keys($recipes['borders']) as $key): ?>
                                <option value="<?= scroll_e($key) ?>"
                                        <?= isset($borderNotes[$key]) ? 'data-note="' . scroll_e($borderNotes[$key]) . '"' : '' ?>
                                        <?= $key === $default['border'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="sc-row">
                    <div class="sc-field">
                        <label for="sc-palette">Border colours<span class="sc-swatch" id="sc-palette-swatch" aria-hidden="true"><i></i><i></i></span></label>
                        <?php /* The pair travels as one value: Scroll::NormaliseRecipe()
                                 only accepts primary and secondary together, so one
                                 order's primary can never be mixed with another's. */ ?>
                        <select id="sc-palette">
                            <?php foreach ($recipes['palette'] as $name => $pair): ?>
                                <option value="<?= scroll_e($pair[0]) ?>|<?= scroll_e($pair[1]) ?>"
                                        <?= ($pair[0] === $default['primary'] && $pair[1] === $default['secondary']) ? 'selected' : '' ?>><?= scroll_e($name) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="sc-field" id="sc-rankset-field">
                        <label for="sc-rankset">Rank numerals</label>
                        <select id="sc-rankset">
                            <?php foreach (array_keys($recipes['rankSets']) as $key): ?>
                                <option value="<?= scroll_e($key) ?>"
                                        <?= $key === $default['rankSet'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <p class="sc-note" id="sc-recipe-note"></p>
            </div>

            <div class="sc-field">
                <label for="sc-player">Recipient</label>
                <input type="text" id="sc-player" autocomplete="off" placeholder="Search players…">
                <input type="hidden" id="sc-player-id">
                <div id="sc-player-ac" class="kn-ac-results"></div>
            </div>

            <?php /* Free text, not a picker. The certificate needs the POSSESSIVE word
                     ("with HER third"), which is not what a player's pronoun record
                     holds — and this ORK retired the pronoun picker for free text on
                     the profile anyway. Left blank the server infers it from the
                     chosen recipient's profile, exactly as a reprint does; typed, the
                     officer's correction wins. Per-print only: nothing is written
                     back to the profile. The datalist is suggestions, not a closed
                     set, and appears only if the controller passes any. */ ?>
            <div class="sc-field">
                <label for="sc-pronoun">Pronoun (possessive)</label>
                <input type="text" id="sc-pronoun" maxlength="20" autocomplete="off"
                       placeholder="From their profile"
                       <?= is_array($Pronouns ?? null) && $Pronouns !== [] ? 'list="sc-pronoun-list"' : '' ?>>
                <?php if (is_array($Pronouns ?? null) && $Pronouns !== []): ?>
                    <datalist id="sc-pronoun-list">
                        <?php foreach ($Pronouns as $p): ?>
                            <option value="<?= scroll_e($p) ?>"></option>
                        <?php endforeach; ?>
                    </datalist>
                <?php endif; ?>
            </div>

            <div class="sc-field">
                <label for="sc-reason">Reason</label>
                <textarea id="sc-reason" rows="2" placeholder="Left blank to hand-write"></textarea>
            </div>

            <div class="sc-row">
                <div class="sc-field">
                    <label for="sc-date">Date</label>
                    <input type="date" id="sc-date" value="<?= $today ?>">
                </div>
                <div class="sc-field">
                    <label for="sc-givenby">Given by</label>
                    <input type="text" id="sc-givenby" placeholder="Left blank to hand-sign">
                </div>
            </div>

            <div class="sc-row">
                <div class="sc-field">
                    <label for="sc-issuer-title">Issuer title</label>
                    <input type="text" id="sc-issuer-title" value="<?= htmlspecialchars((string) ($scope['Title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div class="sc-field">
                    <label for="sc-issuer-name">Issuer name</label>
                    <input type="text" id="sc-issuer-name" value="<?= htmlspecialchars((string) ($scope['Name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <div class="sc-toggles">
                <label><input type="checkbox" id="sc-use-the" <?= !empty($scope['UseThe']) ? 'checked' : '' ?>> “the”</label>
                <label><input type="checkbox" id="sc-use-of" <?= !empty($scope['UseOf']) ? 'checked' : '' ?>> “of”</label>
            </div>

            <div class="sc-sliders">
                <?php
                // value/default are in PERCENT for brightness, contrast and scale, and
                // in percent-of-box for the translates — the JS divides by 100. Reset
                // restores Arcanyst's SetupPanel defaults: 1x and no offset.
                $sliders = [
                    ['brightness', 'Brightness', 50, 150, 100],
                    ['contrast',   'Contrast',   50, 200, 100],
                    ['scale',      'Scale',      50, 150, 100],
                    ['x',          'Move X',    -50,  50,   0],
                    ['y',          'Move Y',    -50,  50,   0],
                ];
                foreach ($sliders as [$key, $label, $min, $max, $default]): ?>
                    <div class="sc-slider">
                        <span><?= $label ?></span>
                        <input type="range" data-heraldry-control="<?= $key ?>"
                               min="<?= $min ?>" max="<?= $max ?>" value="<?= $default ?>"
                               data-default="<?= $default ?>">
                        <button type="button" class="sc-reset" data-reset="<?= $key ?>" data-tip="Reset">
                            <i class="fa-solid fa-rotate-left"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <div class="sc-err" id="sc-err"></div>
        </div>

        <div>
            <?php /* The preview renders the same partials the print route does, so it needs
                     the same filter defs: url(#id) resolves against the DOCUMENT, not the
                     stylesheet. Without these the composer previewed a white heraldry plate
                     on a dark ground while print produced correct line art. */ ?>
            <?php scroll_partial('filters'); ?>
            <div class="sc-preview">
                <div class="sc-stage" id="sc-stage">
                    <svg viewBox="0 0 2550 3300" id="sc-frame" data-scroll-frame
                         style="image-resolution:300dpi;aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;--heraldry-filter:brightness(1) contrast(1);--heraldry-transform:translateX(0%) translateY(0%) scale(1);">
                        <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
                            <div id="sc-mount"></div>
                        </foreignObject>
                    </svg>
                </div>
            </div>
            <div class="sc-actions">
                <button type="button" class="adm-btn adm-btn-primary" id="sc-print">
                    <i class="fa-solid fa-print"></i> Print
                </button>
                <a class="adm-btn adm-btn-ghost" href="<?= UIR ?>Scrollery">Back</a>
            </div>
        </div>
    </div>
</div>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script>
window.ScConfig = {
    uir:        <?= json_encode(UIR) ?>,
    scopeType:  <?= json_encode((string) ($ScopeType ?? 'park')) ?>,
    scopeId:    <?= (int) ($ScopeId ?? 0) ?>,
    kingdomId:  <?= (int) ($scope['KingdomId'] ?? 0) ?>
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery.js') ?>"></script>
