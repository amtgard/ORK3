<?php
/**
 * "Print a new scroll" — an officer composes one certificate by hand.
 *
 * Controls on the left, live preview on the right. Only order, rank and variant
 * need a round trip (they change which art layers are drawn); the text fields
 * write straight into the preview's DOM, so typing costs no fetch.
 *
 * The page wears the shared .rp-* tool-page shell from default/style/reports.css
 * — header, body, sidebar, filter cards — rather than a private layout. Two
 * things there are new and deliberately live in the SHARED sheet, not here:
 * .rp-sidebar-wide (220px fits filter chips, not a form) and the collapsible
 * filter card. Anything a second tool page would want belongs to reports.css.
 *
 * Form controls are .adm-input / .adm-select / .adm-textarea — the CRM's existing
 * control primitives, which this page's own .adm-* shell already shipped. The
 * repo has ~15 near-identical restatements of that rule scoped to individual
 * modules; this page adds no sixteenth.
 *
 * The sidebar runs in the order the scroll itself reads, with one exception the
 * dependency forces: the award comes FIRST, because it decides the rank range,
 * the variant list and whether a custom design block exists at all. Everything
 * after it is top-to-bottom on the page — issuer, recipient, rank phrase, reason,
 * date and signature — matching scroll/_text.tpl.
 */
$scope   = is_array($Scope ?? null) ? $Scope : [];
$choices = is_array($Awards ?? null) ? $Awards : [];
$css     = DIR_TEMPLATE . 'revised-frontend/style/scrollery.css';
$reports = DIR_TEMPLATE . 'default/style/reports.css';
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
 * Which borders the colour pair actually reaches.
 *
 * scroll/_border.tpl is the ONLY consumer of --primary-color / --secondary-color and
 * renders only where the knotwork is drawn. For the eight raster frames the palette
 * is inert, so the control has to say so rather than paint a swatch, cost a round
 * trip and change nothing.
 *
 * Scroll::BorderDrawsKnot() is the single source of truth — the same call
 * scroll/custom.tpl makes to decide whether to draw the knot at all, so the picker
 * and the renderer cannot disagree. The answer is stamped on the option below;
 * scrollery.js reads the stamp and never re-derives it.
 */

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

/*
 * The picker is sectioned like the grant and recommend ones, and reaches it the
 * same way: Model_Scrollery already stamped each choice with the section
 * Award::OptionGroupLabelFor() puts it in, and sorted by section then name, so
 * emitting the <optgroup>s is one walk with no grouping logic of its own.
 *
 * 'Custom' is not a section — those go out as bare <option>s after the groups,
 * exactly as Award::GetAwardOptionListHtml() emits them, because awInitPicker()
 * reads a top-level <option> as a standalone row.
 *
 * data-is-ladder is deliberately NOT set on any of these. It is the flag
 * awInitPicker() reads to decide whether to render an order's description under
 * its name; the composer's list is long and the officer is choosing art, not
 * reading up on the order, so the rows stay one line each.
 */
$grouped = ['sections' => [], 'standalone' => []];
foreach ($choices as $c) {
    if (($c['Group'] ?? 'Other') === 'Custom') {
        $grouped['standalone'][] = $c;
    } else {
        $grouped['sections'][$c['Group'] ?? 'Other'][] = $c;
    }
}

/*
 * Which choices have no name of their own, so the officer has to type one.
 *
 * Custom Award (94) and Custom Title (246) are the ORK's internal placeholders for
 * "this kingdom made something up". Most kingdoms leave the catalogue row named
 * literally "Custom Award", and Scroll::ResolveTitle() will not letter that phrase
 * onto a certificate — it returns '' and the scroll prints a blank line. Some
 * kingdoms did name the row (kingdom 1 holds "Order of the Hellrider", "Order of the
 * Awesome" and five more on base 94), and those already have a real title.
 *
 * ResolveTitle() itself is the test, deliberately — asked with no typed name it
 * answers '' for exactly the rows that have none, so nothing here restates
 * Scroll::CUSTOM_PLACEHOLDER_NAMES (which is private) or duplicates that precedence.
 * Reading a static off Scroll is what this page already does for the vocabulary, the
 * dark grounds and BorderDrawsKnot(); it is the renderer's own answer, not a second
 * opinion that can drift from it.
 *
 * The award/title split that comes back is wording only — which of the two labels
 * the field wears — and by construction the name is one of the two placeholders.
 */
$customKind = static function (array $c): string {
    if (!Scroll::IsCustomAwardId((int) $c['AwardId'])) {
        return '';
    }
    $resolved = Scroll::ResolveTitle([
        'AwardId'          => (int) $c['AwardId'],
        'KingdomAwardName' => (string) $c['Name'],
    ]);
    if ($resolved !== '') {
        return '';
    }

    return strcasecmp(trim((string) $c['Name']), 'Custom Title') === 0 ? 'title' : 'award';
};

/*
 * The paper tone behind each fixed order, for the colour-contrast hint: Scroll's own
 * measured numbers (Scroll::GroundLuminance), never re-measured here. A number is
 * emitted as a plain decimal — never through locale-aware float formatting.
 */
$groundLum    = Scroll::GroundLuminance();
$lumAttr      = static fn (float $v): string => number_format($v, 6, '.', '');
$awardLumAttr = static function (string $order, array $variants) use ($groundLum, $lumAttr): string {
    $entry = $groundLum['orders'][$order] ?? null;
    if ($entry === null) {
        return '';
    }
    if (!is_array($entry)) {
        return ' data-ground-lum="' . $lumAttr((float) $entry) . '"';
    }
    $byVariant = [];
    foreach ($entry as $v => $lum) {
        $byVariant[(string) $v] = round((float) $lum, 6);
    }
    $first = $variants[0] ?? array_key_first($entry);

    return ' data-ground-lum="' . $lumAttr((float) ($entry[$first] ?? reset($entry))) . '"'
        . ' data-variant-lum="' . scroll_e(json_encode($byVariant)) . '"';
};

$awardOption = static function (array $c) use ($customKind, $awardLumAttr): string {
    $kind = $customKind($c);

    return '<option value="' . (int) $c['AwardId'] . '"'
        . ' data-order="' . scroll_e($c['Order']) . '"'
        . ' data-min="' . (int) $c['MinRank'] . '"'
        . ' data-max="' . (int) $c['MaxRank'] . '"'
        . ' data-variants="' . scroll_e(implode(',', $c['Variants'])) . '"'
        . $awardLumAttr((string) $c['Order'], (array) $c['Variants'])
        . ($kind !== '' ? ' data-custom-name="' . $kind . '"' : '')
        . ' data-name="' . scroll_e($c['Name']) . '">'
        . scroll_e(ScrollText::RepairMojibake((string) $c['Name'])) . '</option>';
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime($reports) ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime($css) ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-signature.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-signature.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-hand.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-hand.css') ?>">
<style>
/* Page layout only. Every control is .adm-input / .adm-select / .adm-textarea and
   every container is .rp-*; what is left here is the preview stage and the
   handful of field-level affordances that are genuinely this page's. */
.sc-wrap        { max-width: 1500px; margin: 0 auto 60px; }
.sc-field       { margin-bottom: 11px; position: relative; }
.sc-field:last-child { margin-bottom: 0; }
/* .sc-field-label is the same slot for a group that has no labelable control to
   point a <label for> at — the rank pills. Identical type, different element. */
.sc-field > label,
.sc-field > .sc-field-label { display: block; font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
                  color: var(--rp-text-muted); font-weight: 700; margin-bottom: 4px; }
.sc-row         { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 10px; }
/* The toggles are a sentence about the issuer line, so they sit with it. */
.sc-toggles     { display: flex; gap: 14px; align-items: center; font-size: 12px; margin: -2px 0 11px; }
.sc-toggles label { display: flex; gap: 5px; align-items: center; color: var(--rp-text-body); cursor: pointer; }
/* A grid track defaults to min-width:auto, so a control with a wide intrinsic width (a
   select showing a long option, the hand listbox's 12rem trigger) props its column open
   and squeezes its neighbour. minmax(0,1fr) lets both tracks shrink to the panel, and
   the controls fill the track they are given. */
.sc-field .adm-select { width: 100%; min-width: 0; box-sizing: border-box; }
.sc-field .sc-hand { display: block; }
.sc-field .sc-hand-trigger { min-width: 0; }
.sc-hint        { font-size: 11px; line-height: 1.45; color: var(--rp-text-muted); margin: 4px 0 0; text-align: left; }

/* Preview — the sticky stage in the main column. */
.sc-preview     { background: #4a4a4a; padding: 14px; border-radius: 6px; position: sticky; top: 12px; }
.sc-stage       { background: #fff; aspect-ratio: 8.5/11; overflow: hidden; }
.sc-stage.is-busy { opacity: .45; transition: opacity .12s; }
.sc-err         { color: #e53e3e; font-size: 12px; margin-top: 8px; }
.sc-err:empty   { display: none; }

/* Rank pills. The pill itself is the shared .sc-rank-pill scheme in revised.css —
   identical to the grant and recommend surfaces. Only the legend is local. */
.sc-rank-pill   { border: 2px solid #e2e8f0; }
.sc-rank-legend { display: flex; flex-wrap: wrap; gap: 4px 12px; font-size: 10.5px;
                  color: var(--rp-text-muted); margin-top: 7px; }
/* An author `display: flex` outranks the UA sheet's `[hidden] { display: none }`,
   so the legend needs to say so itself or it shows with no recipient chosen. */
.sc-rank-legend[hidden] { display: none; }
.sc-rank-legend span { display: inline-flex; align-items: center; gap: 4px; }
/* The key carries a GLYPH as well as a colour — held/forward was previously a green
   dot against a blue dot, which is state in colour alone (WCAG 1.4.1). The marks
   are the same ✓ and → the pills themselves watermark. */
.sc-rank-legend i.sc-key { width: 13px; height: 13px; border-radius: 50%; flex: 0 0 auto;
                  display: inline-flex; align-items: center; justify-content: center;
                  font-style: normal; font-size: 8px; line-height: 1; font-weight: 700; }
.sc-key-held    { background: linear-gradient(135deg, #a7ecbd, #7fd99e); border: 1px solid #48bb78;
                  color: #1c4532; }
.sc-key-fwd     { background: linear-gradient(135deg, #9fd6f7, #6cb8ef); border: 1px solid #3182ce;
                  color: #1a365d; }

/* Border-colour pair swatch. */
.sc-swatch      { display: inline-flex; width: 30px; height: 10px; border-radius: 2px; overflow: hidden;
                  vertical-align: -1px; margin-left: 6px; border: 1px solid var(--rp-border-mid); }
.sc-swatch i    { flex: 1 1 50%; }
/* An author `display` outranks the UA sheet's `[hidden] { display: none }`, exactly
   as for .sc-rank-legend above, so the swatch has to say so itself — otherwise it
   keeps painting a colour pair the chosen border never reads. */
.sc-swatch[hidden] { display: none; }

/* text-align is not cosmetic here: orkui.css carries a global `p { text-align:
   justify }`, and justified 11px prose in a narrow column stretches to gaps. */
.sc-note        { font-size: 11px; line-height: 1.45; color: var(--rp-text-muted);
                  margin: 10px 0 0; text-align: left; }
.sc-note:empty  { display: none; }
.sc-field.is-inert        { opacity: .55; }
.sc-field.is-inert select { pointer-events: none; }

/* The picker trigger is a button, so it needs the field's own full width. */
.sc-field .aw-picker-trigger { width: 100%; }

/* Customize Design. The heraldry bar is a segmented row that wraps rather than
   squeezing: three equal buttons, each at least wide enough for "Persona". */
.sc-seg         { display: flex; flex-wrap: wrap; gap: 4px; }
.sc-seg-btn     { flex: 1 1 0; min-width: 72px; padding: 6px 8px; font: inherit; font-size: 12px;
                  font-weight: 600; line-height: 1.2; color: var(--rp-text-body);
                  background: var(--ork-input-bg); border: 1.5px solid var(--ork-input-border);
                  border-radius: 6px; cursor: pointer; }
.sc-seg-btn:hover:not([aria-pressed="true"]):not([aria-disabled="true"]) {
                  background: var(--rp-bg-light); border-color: var(--rp-border-strong); }
.sc-seg-btn:focus-visible { outline: 2px solid var(--rp-accent); outline-offset: 1px; }
.sc-seg-btn[aria-pressed="true"] { background: var(--rp-accent); border-color: var(--rp-accent); color: #fff; }
/* aria-disabled, not [disabled] — see the template note: the tip must still hover.
   Muted by colour, NOT opacity: opacity would fade the ::after tip bubble with it.
   Pressed-and-disabled is the scope default with no arms on file (nothing draws,
   as ever): an outline in the accent, so it still reads as the chosen one. */
.sc-seg-btn[aria-disabled="true"] { color: var(--rp-text-hint); border-style: dashed; cursor: not-allowed; }
.sc-seg-btn[aria-pressed="true"][aria-disabled="true"] { background: transparent; color: var(--rp-accent); }
/* The generic data-tip bubble is centred and refuses to wrap (min-width:max-content),
   which pushes it off a 220px sidebar's edge. These wrap, and sit inside the card:
   left-anchored from the left of the bar, right-anchored at its right end.
   !important for the same reason as .ev-meal-card-header's: the generic rule's
   five :not()s outweigh any sane selector. */
#sc-design [data-tip]:hover::after {
                  left: 0 !important; right: auto !important; transform: none !important;
                  min-width: 0 !important; width: max-content; max-width: 220px !important;
                  white-space: normal !important; text-transform: none; letter-spacing: normal;
                  font-weight: 400; }
#sc-design .sc-seg-btn:last-child[data-tip]:hover::after { left: auto !important; right: 0 !important; }

/* The three checkboxes. A direct-child <label> of .sc-field is the 10px uppercase
   field caption above; these are sentences, so they restate the type. */
.sc-field > label.sc-opt,
.sc-opt         { display: flex; gap: 7px; align-items: center; font-size: 13px; font-weight: 600;
                  letter-spacing: normal; text-transform: none; color: var(--rp-text-body);
                  margin: 0; cursor: pointer; }
.sc-design-sub .sc-opt { margin-top: 10px; font-weight: 500; }
.sc-opt input[type=checkbox] { margin: 0; accent-color: var(--rp-accent); width: 15px; height: 15px; flex: 0 0 auto; }
/* An author display outranks the UA [hidden] rule, as for .sc-rank-legend. */
.sc-opt[hidden], .sc-field > label.sc-opt[hidden], .sc-design-sub[hidden] { display: none; }
/* The incompatible state: the same words, muted, with the reason on hover. */
.sc-opt-na      { display: inline-flex; color: var(--rp-text-hint); cursor: help; font-weight: 600; }
.sc-opt-na i    { font-size: 12px; }
.sc-opt-na:focus-visible { outline: 2px solid var(--rp-accent); outline-offset: 2px; border-radius: 3px; }
.sc-design-sub  { margin: 8px 0 0 22px; }
.sc-design-sub > .sc-field { margin-bottom: 0; }

/* A colour control: the native swatch picker beside the hex box it stays in step with. */
.sc-color-pair  { display: flex; gap: 6px; align-items: center; min-width: 0; }
.sc-color-pair input[type=color] { flex: 0 0 34px; width: 34px; height: 32px; padding: 2px;
                  border: 1.5px solid var(--ork-input-border); border-radius: 6px;
                  background: var(--ork-input-bg); cursor: pointer; }
.sc-color-pair .sc-hex { flex: 1 1 auto; min-width: 0; font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
                  text-transform: uppercase; }
.sc-hex[aria-invalid="true"] { border-color: #e53e3e; }
.sc-hex-err     { color: #c53030; }
html[data-theme="dark"] .sc-seg-btn[aria-pressed="true"] { background: #4c51bf; border-color: #818cf8; color: #fff; }
html[data-theme="dark"] .sc-hex-err { color: #feb2b2; }
html[data-theme="dark"] .sc-hex[aria-invalid="true"] { border-color: #fc8181; }

html[data-theme="dark"] .sc-rank-pill { border-color: var(--ork-border); }
html[data-theme="dark"] .sc-key-held  { background: linear-gradient(135deg, #2f6b4f, #1d4733); color: #c6f6d5; }
html[data-theme="dark"] .sc-key-fwd   { background: linear-gradient(135deg, #345083, #23375a); color: #bee3f8; }

/* Below the two-column break the sidebar is full width, so the preview stops
   being sticky (it would pin a full-height page) and the paired rows unstack
   only where there is genuinely room for two controls. */
@media (max-width: 1000px) {
    .rp-body      { flex-direction: column; }
    .rp-sidebar,
    .rp-sidebar-wide { width: 100%; }
    .sc-preview   { position: static; }
}
@media (max-width: 420px) {
    .sc-row { grid-template-columns: 1fr; }
}
</style>

<div class="rp-root sc-wrap">

    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fa-solid fa-scroll rp-header-icon"></i>
                <h1 class="rp-header-title">Print a new scroll</h1>
            </div>
            <?php /* The chip becomes a SWITCHER for an officer who holds more than one
                     scope — otherwise the only way from a park's composer to their
                     kingdom's is to edit the URL. Each target is one the controller has
                     already run may_issue_for() over, so no chip here can lead to the
                     denial page. .rp-scope-chip already styles an anchor, hover and dark
                     mode included, so no new CSS. */ ?>
            <div class="rp-header-scope">
                <span class="rp-scope-chip">
                    <i class="fa-solid <?= ($ScopeType ?? 'park') === 'kingdom' ? 'fa-crown' : 'fa-shield-halved' ?>"></i>
                    <?= scroll_e((string) ($scope['Display'] ?? trim(($scope['Title'] ?? '') . ' ' . ($scope['Name'] ?? '')))) ?>
                </span>
                <?php foreach (is_array($OtherScopes ?? null) ? $OtherScopes : [] as $s): ?>
                    <a class="rp-scope-chip" href="<?= UIR ?>Scrollery/create/<?= $s['Type'] ?>/<?= (int) $s['Id'] ?>">
                        <span class="rp-scope-chip-label">Switch to</span>
                        <i class="fa-solid <?= $s['Type'] === 'kingdom' ? 'fa-crown' : 'fa-shield-halved' ?>"></i>
                        <?= scroll_e($s['Label']) ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="rp-header-actions">
            <button type="button" class="rp-btn-ghost" id="sc-print">
                <i class="fa-solid fa-print"></i> Print
            </button>
            <a class="rp-btn-ghost" href="<?= UIR ?>Scrollery">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="rp-body">
        <div class="rp-sidebar rp-sidebar-wide">

            <?php /* 1 — THE AWARD. First because it decides everything below it: the
                     rank range, the variant list, and whether this is a custom scroll
                     with a design block at all. */ ?>
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fa-solid fa-award"></i> Award</div>
                <div class="rp-filter-card-body">
                    <div class="sc-field">
                        <label for="sc-award">Which award</label>
                        <select id="sc-award" class="adm-select">
                            <?php foreach ($grouped['sections'] as $label => $items): ?>
                                <optgroup label="<?= scroll_e($label) ?>">
                                    <?php foreach ($items as $c): ?><?= $awardOption($c) ?><?php endforeach; ?>
                                </optgroup>
                            <?php endforeach; ?>
                            <?php foreach ($grouped['standalone'] as $c): ?><?= $awardOption($c) ?><?php endforeach; ?>
                        </select>
                    </div>
                    <?php /* The typed name for the entries that have none of their own —
                             see $customKind above. Hidden for every other choice, exactly
                             as the variant field below is: the award it would rename
                             already has a name. scrollery.js swaps the label, placeholder
                             and hint between the award and title wordings, and marks it
                             required while it is showing.

                             maxlength is Scroll::CUSTOM_NAME_MAX_LENGTH itself rather than
                             a number typed here, because the cap belongs to the domain —
                             Scroll::SanitiseCustomName() truncates to it inside
                             BuildScroll(), and a maxlength that disagreed would silently
                             cut at a different point than the certificate does. Read, not
                             restated, exactly as the recipe vocabulary above is. */ ?>
                    <div class="sc-field" id="sc-customname-field" style="display:none">
                        <label for="sc-customname" id="sc-customname-label">Award name</label>
                        <input type="text" id="sc-customname" class="adm-input" autocomplete="off"
                               maxlength="<?= (int) Scroll::CUSTOM_NAME_MAX_LENGTH ?>"
                               aria-describedby="sc-customname-hint">
                        <p class="sc-hint" id="sc-customname-hint"></p>
                    </div>
                    <?php /* The interstitial line between the recipient's name and the title.
                             A ranked award builds one from its ordinal ("with her third");
                             the two custom entries have no ordinal, so without this the name
                             ran straight into a bare title as two unrelated lines.

                             The two defaults come from Scroll's own constants rather than
                             being restated here or in the JS — same rule as the maxlength
                             above. Clearing the box is a real choice and prints no phrase. */ ?>
                    <div class="sc-field" id="sc-withphrase-field" style="display:none">
                        <label for="sc-withphrase">With phrase</label>
                        <input type="text" id="sc-withphrase" class="adm-input" autocomplete="off"
                               maxlength="<?= (int) Scroll::CUSTOM_NAME_MAX_LENGTH ?>"
                               data-default-award="<?= scroll_e(Scroll::DEFAULT_AWARD_PHRASE) ?>"
                               data-default-title="<?= scroll_e(Scroll::DEFAULT_TITLE_PHRASE) ?>"
                               aria-describedby="sc-withphrase-hint">
                        <p class="sc-hint" id="sc-withphrase-hint">Printed between the recipient&rsquo;s
                            name and the award name. Clear it to print no line at all.</p>
                    </div>
                    <div class="sc-field" id="sc-variant-field" style="display:none">
                        <label for="sc-variant">Variant</label>
                        <select id="sc-variant" class="adm-select"></select>
                    </div>
                </div>
            </div>

            <?php /* 1b — CUSTOMIZE DESIGN. Right after the award because two of its three
                     controls answer to it: whether Custom Border can be offered at all
                     depends on the award's order, rank, variant and (for the custom
                     order) ground, and scrollery.js re-asks every time one changes.
                     Collapsed: a plain certificate is still the common case.

                     Every control here is OFF or at Scroll::DEFAULT_DESIGN when the page
                     opens, which is exactly the certificate a design-less request (a
                     reprint, a bulk run) produces. The colours shown are corrected
                     client-side from the officer's remembered pair, if any.

                     The heraldry buttons start disabled-or-not from the scope's own
                     arms; Persona starts disabled and is decided by each preview
                     response, because only the server knows whether the recipient has
                     arms. aria-disabled rather than `disabled`, deliberately: a disabled
                     button takes no pointer events, and its data-tip is the only place
                     the officer learns WHY it is off. The tips themselves are written by
                     scrollery.js (paintHeraldryBar), which owns that wording in one place
                     because Persona's changes with the recipient. */ ?>
            <?php
            $designDefaults = is_array($DesignDefaults ?? null) ? $DesignDefaults : Scroll::DEFAULT_DESIGN;
            $heraldryHave   = is_array($HeraldryAvailable ?? null) ? $HeraldryAvailable : [];
            $scopeDefault   = ($ScopeType ?? 'park') === 'kingdom' ? 'kingdom' : 'park';
            $colorControl = static function (string $id, string $label, string $value): string {
                $v = scroll_e($value);

                return '<div class="sc-field sc-color">'
                    . '<label for="' . $id . '-hex">' . scroll_e($label) . '</label>'
                    . '<div class="sc-color-pair">'
                    . '<input type="color" id="' . $id . '" value="' . $v . '" aria-label="' . scroll_e($label) . ' picker">'
                    . '<input type="text" id="' . $id . '-hex" class="adm-input sc-hex" value="' . $v . '"'
                    . ' maxlength="7" spellcheck="false" autocomplete="off" inputmode="text"'
                    . ' aria-describedby="' . $id . '-err">'
                    . '</div>'
                    . '<p class="sc-hint sc-contrast-hint" id="' . $id . '-contrast" role="status" hidden>'
                    . 'Low contrast on this background — this colour may be hard to see.</p>'
                    . '<p class="sc-hint sc-hex-err" id="' . $id . '-err" hidden>Use six hex digits, like #1E4FA3. '
                    . 'The last valid colour is still applied.</p>'
                    . '</div>';
            };
            ?>
            <div class="rp-filter-card" id="sc-design">
                <button type="button" class="rp-filter-card-header" data-sc-collapse="sc-design-body" aria-expanded="false" aria-controls="sc-design-body">
                    <i class="fa-solid fa-wand-magic-sparkles"></i> Customize Design
                    <i class="fa-solid fa-chevron-down rp-filter-card-chevron" aria-hidden="true"></i>
                </button>
                <div class="rp-filter-card-body" id="sc-design-body" hidden>
                    <div class="sc-field">
                        <span class="sc-field-label" id="sc-heraldry-source-label">Heraldry</span>
                        <div class="sc-seg" role="group" aria-labelledby="sc-heraldry-source-label">
                            <?php foreach (['kingdom' => 'Kingdom', 'park' => 'Park', 'persona' => 'Persona'] as $src => $srcLabel):
                                $on = $src !== 'persona' && !empty($heraldryHave[$src]); ?>
                                <button type="button" class="sc-seg-btn" data-heraldry-source="<?= $src ?>"
                                        aria-pressed="<?= $src === $scopeDefault ? 'true' : 'false' ?>"
                                        aria-disabled="<?= $on ? 'false' : 'true' ?>"><?= $srcLabel ?></button>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div class="sc-field" id="sc-custom-border-field">
                        <label class="sc-opt" id="sc-custom-border-opt">
                            <input type="checkbox" id="sc-custom-border"<?= !empty($designDefaults['customBorder']) ? ' checked' : '' ?>>
                            <span>Custom Border</span>
                        </label>
                        <span class="sc-opt sc-opt-na" id="sc-custom-border-na" tabindex="0" hidden
                              data-tip="This award style is not compatible with custom borders.">
                            <span>Custom Border</span>
                            <i class="fa-solid fa-circle-info" aria-hidden="true"></i>
                        </span>
                        <div class="sc-design-sub" id="sc-custom-border-colors" hidden>
                            <div class="sc-row">
                                <?= $colorControl('sc-color1', 'Color 1', (string) $designDefaults['color1']) ?>
                                <?= $colorControl('sc-color2', 'Color 2', (string) $designDefaults['color2']) ?>
                            </div>
                            <p class="sc-hint">Color 1 is the band that is blue on the Jovious scroll, Color 2 the pink one.</p>
                            <label class="sc-opt">
                                <input type="checkbox" id="sc-match-style"<?= !empty($designDefaults['matchStyle']) ? ' checked' : '' ?>>
                                <span>Match scroll style?</span>
                            </label>
                            <p class="sc-hint">Blends the knot into the paper the way the original border was, so the colours shift with it.</p>
                            <p class="sc-hint" id="sc-match-fallback-hint" role="status" hidden>Very dark colours can't blend on this background — printed in exact colours.</p>
                        </div>
                    </div>

                    <div class="sc-field">
                        <label class="sc-opt">
                            <input type="checkbox" id="sc-custom-ribbon"<?= !empty($designDefaults['customRibbon']) ? ' checked' : '' ?>>
                            <span>Custom Ribbon</span>
                        </label>
                        <div class="sc-design-sub" id="sc-custom-ribbon-colors" hidden>
                            <?= $colorControl('sc-ribbon-color', 'Ribbon Color', (string) $designDefaults['ribbonColor']) ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php /* 2 — THE WORDING, top to bottom exactly as scroll/_text.tpl lays it
                     out: the issuing line, then the recipient, then the rank phrase
                     ("with her third"), then the reason, then the date and signature. */ ?>
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fa-solid fa-feather-pointed"></i> Wording</div>
                <div class="rp-filter-card-body">

                    <?php /* The hand. In Wording rather than in Scroll design, and that is
                             the whole point: "Scroll design" is display:none for every order
                             but the two custom entries, so a picker in there would be
                             invisible on fifteen of the seventeen. The hand applies to all
                             of them.

                             It is also not a recipe key. The grounds and borders below are
                             the composed ARTWORK and travel with the award; a hand is how one
                             officer likes their certificates lettered, changes nothing about
                             what the award is, and is remembered per browser. Same control,
                             same list and same store as the one on the print page — see
                             script/scrollery-hand.js. */ ?>
                    <?php /* Hand and Heading each have a row of their own. They used to share
                             one, and the hand's listbox trigger (min-width 12rem, its label
                             a display face) took most of the 330px panel, leaving Heading
                             about 100px: the select showed "Au" and its hint broke one word
                             per line. Heading now also carries a longer label, "Automatic
                             (Classic)", so it could not have lived there anyway. */ ?>
                    <div class="sc-field">
                        <label for="sc-face">Hand (lettering)</label>
                        <select id="sc-face" class="adm-select">
                            <?php foreach (Scroll::Hands() as $handKey => $handDef): ?>
                            <option value="<?= scroll_e($handKey) ?>" data-face-family="<?= scroll_e($handDef['family']) ?>"<?= $handKey === Scroll::DEFAULT_HAND ? ' selected' : '' ?>><?= scroll_e($handDef['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sc-hint">Applies to this print and is remembered for the next one. It is not stored on the award.</p>
                    </div>

                    <?php /* The heading, beside the hand for the same reason: it is how this
                             one print is set, not part of what the award is. It picks the
                             composition of the issuing block (Automatic lets the typeset
                             engine choose; Classic and Centered force one). The same markup
                             and classes as the hand, so it takes the same light and dark
                             styling.

                             Deliberately NOT remembered: a forced heading is a rescue for a
                             name that sets badly, and carrying it to the next certificate
                             would force it on a name that sets fine. It goes on #sc-stage as
                             data-sc-heading (scrollery.js) and on the print URL as heading=. */ ?>
                    <div class="sc-field">
                        <label for="sc-heading">Heading</label>
                        <select id="sc-heading" class="adm-select">
                            <?php foreach (Scroll::Headings() as $headingKey => $headingLabel): ?>
                            <option value="<?= scroll_e($headingKey) ?>"<?= $headingKey === Scroll::DEFAULT_HEADING ? ' selected' : '' ?>><?= scroll_e($headingLabel) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sc-hint">Automatic sets the classic heading, and switches to centred when a name or place won&rsquo;t fit. Applies to this print only.</p>
                    </div>

                    <div class="sc-row">
                        <div class="sc-field">
                            <label for="sc-issuer-title">Issuer title</label>
                            <input type="text" id="sc-issuer-title" class="adm-input"
                                   value="<?= scroll_e((string) ($scope['Title'] ?? '')) ?>">
                        </div>
                        <div class="sc-field">
                            <label for="sc-issuer-name">Issuer name</label>
                            <input type="text" id="sc-issuer-name" class="adm-input"
                                   value="<?= scroll_e((string) ($scope['Name'] ?? '')) ?>">
                        </div>
                    </div>
                    <div class="sc-toggles">
                        <label><input type="checkbox" id="sc-use-the" <?= !empty($scope['UseThe']) ? 'checked' : '' ?>> &ldquo;the&rdquo;</label>
                        <label><input type="checkbox" id="sc-use-of" <?= !empty($scope['UseOf']) ? 'checked' : '' ?>> &ldquo;of&rdquo;</label>
                    </div>

                    <div class="sc-field">
                        <label for="sc-player">Recipient</label>
                        <input type="text" id="sc-player" class="adm-input" autocomplete="off" placeholder="Search players…">
                        <input type="hidden" id="sc-player-id">
                        <div id="sc-player-ac" class="kn-ac-results"></div>
                    </div>

                    <?php /* The rank pills. A hidden input carries the value so the rest
                             of scrollery.js keeps reading rankEl.value and listening for
                             'change' exactly as it did when this was a <select>.

                             Painted against the recipient's own held rank once one is
                             chosen — the same green/blue scheme as the grant and
                             recommend modals, from the same tnRankPaint(). */ ?>
                    <?php /* `for` cannot point at a <div> — it is only valid on a labelable
                             element, so the browser dropped it and the pill group had no
                             accessible name at all. A role="group" named by the same text
                             through aria-labelledby is the shape that actually works, and
                             aria-describedby carries the "click the selected pill again to
                             clear" affordance that until now existed only as a visual hint. */ ?>
                    <div class="sc-field" id="sc-rank-field">
                        <span class="sc-field-label" id="sc-rank-label">Rank</span>
                        <div class="sc-rank-pills-wrap" id="sc-rank-pills" role="group"
                             aria-labelledby="sc-rank-label" aria-describedby="sc-rank-hint"></div>
                        <input type="hidden" id="sc-rank" value="0">
                        <div class="sc-rank-legend" id="sc-rank-legend" hidden>
                            <?php /* A shape as well as a colour: the two keys were a green dot
                                     and a blue dot, which is the state carried in colour alone
                                     that WCAG 1.4.1 is about. The glyphs are the same ✓ and →
                                     the pills themselves already watermark. */ ?>
                            <span><i class="sc-key sc-key-held" aria-hidden="true">&check;</i> Already earned</span>
                            <span><i class="sc-key sc-key-fwd" aria-hidden="true">&rarr;</i> Being awarded</span>
                        </div>
                        <p class="sc-hint" id="sc-rank-hint"></p>
                    </div>

                    <?php /* Free text, not a picker. The certificate needs the POSSESSIVE
                             word ("with HER third"), which is not what a player's pronoun
                             record holds — and this ORK retired the pronoun picker for free
                             text on the profile anyway. Left blank the server infers it
                             from the chosen recipient's profile, exactly as a reprint does;
                             typed, the officer's correction wins. Per-print only: nothing
                             is written back to the profile. The datalist is suggestions,
                             not a closed set, and appears only if the controller passes
                             any. */ ?>
                    <div class="sc-field" id="sc-pronoun-field">
                        <label for="sc-pronoun">Pronoun (possessive)</label>
                        <input type="text" id="sc-pronoun" class="adm-input" maxlength="20" autocomplete="off"
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
                        <textarea id="sc-reason" class="adm-textarea" rows="2" placeholder="Left blank to hand-write"></textarea>
                    </div>

                    <?php /* The ruled writing lines behind the citation. Next to the
                             Reason box because that is what it rules, and offered on
                             EVERY award rather than only a composed one — the six rules
                             live in the text layer all seventeen orders share. */ ?>
                    <div class="sc-field">
                        <label class="sc-check">
                            <input type="checkbox" id="sc-lines" checked>
                            <span>Reason lines</span>
                        </label>
                        <p class="sc-hint">The ruled lines behind the citation, for finishing a certificate by hand. Per print, and remembered — not stored on the award.</p>
                    </div>

                    <div class="sc-row">
                        <div class="sc-field">
                            <label for="sc-date">Date</label>
                            <input type="date" id="sc-date" class="adm-input" value="<?= $today ?>">
                        </div>
                        <div class="sc-field">
                            <label for="sc-givenby">Given by</label>
                            <input type="text" id="sc-givenby" class="adm-input" placeholder="Left blank to hand-sign">
                        </div>
                    </div>

                    <?php /* The signature hand, directly under the Given by box it letters. Same
                             control, list and store pattern as the Hand above (script/
                             scrollery-hand.js): each option is drawn in its own face, the choice
                             is remembered per browser and goes on the print URL as sig=, and it
                             is NOT stored on the award. "Match the lettering" is the default and
                             changes nothing. It applies even when Given by is empty — the
                             certificate has the line either way, and it costs nothing. */ ?>
                    <div class="sc-field">
                        <label for="sc-sig">Signature Hand</label>
                        <select id="sc-sig" class="adm-select">
                            <?php foreach (Scroll::SignatureHands() as $sigKey => $sigDef): ?>
                            <option value="<?= scroll_e($sigKey) ?>"<?= $sigDef['family'] !== '' ? ' data-face-family="' . scroll_e($sigDef['family']) . '"' : '' ?><?= $sigKey === Scroll::DEFAULT_SIGNATURE_HAND ? ' selected' : '' ?>><?= scroll_e($sigDef['label']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="sc-hint">How the signed name is lettered. Remembered for your next print.</p>
                    </div>

                </div>
            </div>

            <?php /* 3 — THE ART. Shown only when the chosen award resolves to the
                     `custom` order — every other order's art is fixed by its own
                     partial, so these controls would be inert lies anywhere else. The
                     selected values start on Scroll::DEFAULT_RECIPE, which is what every
                     path that prints WITHOUT a recipe (a reprint, a bulk run) already
                     uses, so the composer opens on the same certificate those produce.

                     Collapsed by default: the default recipe is the common case, and an
                     officer printing a normal scroll should not have to scroll past four
                     art pickers to reach the date. */ ?>
            <div class="rp-filter-card" id="sc-recipe" style="display:none">
                <button type="button" class="rp-filter-card-header" data-sc-collapse="sc-recipe-body" aria-expanded="false" aria-controls="sc-recipe-body">
                    <i class="fa-solid fa-palette"></i> Scroll design
                    <i class="fa-solid fa-chevron-down rp-filter-card-chevron" aria-hidden="true"></i>
                </button>
                <div class="rp-filter-card-body" id="sc-recipe-body" hidden>
                    <div class="sc-row">
                        <div class="sc-field">
                            <label for="sc-ground">Ground</label>
                            <select id="sc-ground" class="adm-select">
                                <?php foreach (array_keys($recipes['grounds']) as $key): ?>
                                    <?php /* data-own-border: this plate already has a frame painted into
                                             it, so no border layer may be drawn over it. Stamped from
                                             Scroll::GroundDrawsOwnBorder() rather than listed again in the
                                             JS — the same rule data-knot follows on the border options. */ ?>
                                    <option value="<?= scroll_e($key) ?>"
                                            data-own-border="<?= Scroll::GroundDrawsOwnBorder($key) ? '1' : '0' ?>"
                                            data-ground-lum="<?= $lumAttr((float) ($groundLum['grounds'][$key] ?? 1.0)) ?>"
                                            <?= isset($groundNotes[$key]) ? 'data-note="' . scroll_e($groundNotes[$key]) . '"' : '' ?>
                                            <?= $key === $default['ground'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sc-field" id="sc-border-field">
                            <label for="sc-border">Border</label>
                            <select id="sc-border" class="adm-select">
                                <?php foreach (array_keys($recipes['borders']) as $key): ?>
                                    <option value="<?= scroll_e($key) ?>"
                                            data-knot="<?= Scroll::BorderDrawsKnot($key) ? '1' : '0' ?>"
                                            <?= isset($borderNotes[$key]) ? 'data-note="' . scroll_e($borderNotes[$key]) . '"' : '' ?>
                                            <?= $key === $default['border'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="sc-row">
                        <div class="sc-field" id="sc-palette-field">
                            <label for="sc-palette">Border colours<span class="sc-swatch" id="sc-palette-swatch" aria-hidden="true"><i></i><i></i></span></label>
                            <?php /* The pair travels as one value: Scroll::NormaliseRecipe()
                                     only accepts primary and secondary together, so one
                                     order's primary can never be mixed with another's. */ ?>
                            <select id="sc-palette" class="adm-select">
                                <?php foreach ($recipes['palette'] as $name => $pair): ?>
                                    <option value="<?= scroll_e($pair[0]) ?>|<?= scroll_e($pair[1]) ?>"
                                            <?= ($pair[0] === $default['primary'] && $pair[1] === $default['secondary']) ? 'selected' : '' ?>><?= scroll_e($name) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="sc-field" id="sc-rankset-field">
                            <label for="sc-rankset">Rank numerals</label>
                            <select id="sc-rankset" class="adm-select">
                                <?php foreach (array_keys($recipes['rankSets']) as $key): ?>
                                    <option value="<?= scroll_e($key) ?>"
                                            <?= $key === $default['rankSet'] ? 'selected' : '' ?>><?= scroll_e($recipeLabel($key)) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <?php /* Shown while Customize Design's Custom Border is on and honoured:
                             the knot replaces whatever these two controls would draw, so
                             scrollery.js disables them and says why in one line. */ ?>
                    <p class="sc-hint" id="sc-recipe-cb-hint" hidden>Custom Border is drawing this scroll&rsquo;s border.</p>
                    <?php /* The dark-ground, band, numeral and palette explanations live
                             ONLY here, and were written with textContent and never
                             announced. polite, not assertive: it is an explanation of a
                             choice the officer just made, not an error. */ ?>
                    <p class="sc-note" id="sc-recipe-note" aria-live="polite"></p>
                </div>
            </div>

            <div class="sc-err" id="sc-err" role="alert"></div>
        </div>

        <div class="rp-table-area">
            <?php /* The preview renders the same partials the print route does, so it needs
                     the same filter defs: url(#id) resolves against the DOCUMENT, not the
                     stylesheet. Without these the composer previewed a white heraldry plate
                     on a dark ground while print produced correct line art. */ ?>
            <?php scroll_partial('filters'); ?>
            <?php /* The preview is a picture that changes silently: reload() replaces the
                     whole mount with server-rendered markup and the only busy signal was
                     opacity. aria-busy is toggled with the class, the stage is named, and
                     #sc-summary is the composed sentence — the module's entire non-visual
                     channel. It is filled from the SAME option labels the selects show, so
                     it can never describe a different certificate than the one drawn. */ ?>
            <?php /* data-scroll-face on the stage, not on <body>: scrollery.css switches
                     hands off this attribute on ANY ancestor (section 1a-ii), and the
                     stage is the only part of this page set in a certificate hand — the
                     sidebar and the chrome are the CRM's own type and must stay that way.

                     Emitted as the default and then corrected client-side from the
                     officer's remembered choice, so the composer previews in the hand
                     their last print used rather than always in Chancery. The render
                     page is where the choice is MADE; this only honours it. */ ?>
            <div class="sc-preview">
                <p class="rp-sr-only" id="sc-summary" aria-live="polite"></p>
                <div class="sc-stage" id="sc-stage" role="img" aria-busy="false"
                     data-scroll-face="chancery" data-sc-heading="<?= scroll_e(Scroll::DEFAULT_HEADING) ?>"
                     aria-label="Certificate preview">
                    <svg viewBox="0 0 2550 3300" id="sc-frame" data-scroll-frame
                         style="image-resolution:300dpi;aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;">
                        <foreignObject x="0" y="0" width="2550" height="3300" style="overflow:visible;">
                            <div id="sc-mount"></div>
                        </foreignObject>
                    </svg>
                </div>
            </div>
        </div>
    </div>
</div>

<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<?php /* The issuer text model, before everything that reads it: scrollery.js calls
         ScrolleryText.issuerLines() out of applyText(), and it must split a typed
         "The Kingdom of the Wetlands" the way print does. */ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-textmodel.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-textmodel.js') ?>"></script>
<?php /* The Custom Border compatibility mirror, before scrollery.js, which asks it
         whether the checkbox is offered. */ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-customborder.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-customborder.js') ?>"></script>
<?php /* The typeset engine, which sets the top of the certificate. The autofit below
         calls it and has no fallback, so it comes first. */ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-typeset.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-typeset.js') ?>"></script>
<?php /* The measured autofit, the same one Scrollery_render.tpl loads. It must be
         parsed BEFORE scrollery.js, which calls ScrolleryAutofit.fitSoon() out of
         applyText() and reload() — without it the preview keeps the server's first
         guess, which on this page is computed for an EMPTY recipient and so is the
         wrong size for every name actually typed. No defer/async: it does no DOM
         work at parse time (its own first pass is inside a promise on
         document.fonts.ready), and it has to have defined the global by the time
         scrollery.js runs. */ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-autofit.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-autofit.js') ?>"></script>
<script>
window.ScConfig = {
    uir:        <?= json_encode(UIR) ?>,
    scopeType:  <?= json_encode((string) ($ScopeType ?? 'park')) ?>,
    scopeId:    <?= (int) ($ScopeId ?? 0) ?>,
    kingdomId:  <?= (int) ($scope['KingdomId'] ?? 0) ?>,
    /* The Customize Design card's server-side truths: the rule data
       Scroll::CustomBorderRule() reads (scrollery.js mirrors that method over it),
       the default design, and which of the scope's own arms exist. */
    customBorderRules: <?= json_encode(is_array($CustomBorderRules ?? null) ? $CustomBorderRules : Scroll::CustomBorderRules(), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    /* Whether match-style draws the knot screened, per order/variant and per custom
       ground: Scroll::PassesScreen() already answered, so the contrast hint never
       re-derives it. Below matchScreenMinLuminance a colour vanishes on such a knot
       (Scroll::MATCH_SCREEN_MIN_LUMINANCE, the server's MatchFallback line). */
    matchScreens: <?= json_encode(is_array($MatchScreens ?? null) ? $MatchScreens : Scroll::MatchScreens(), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    matchScreenMinLuminance: <?= json_encode(Scroll::MATCH_SCREEN_MIN_LUMINANCE) ?>,
    designDefaults:    <?= json_encode(is_array($DesignDefaults ?? null) ? $DesignDefaults : Scroll::DEFAULT_DESIGN, JSON_HEX_TAG | JSON_HEX_AMP) ?>,
    heraldryAvailable: <?= json_encode(is_array($HeraldryAvailable ?? null) ? $HeraldryAvailable : new stdClass()) ?>
};
</script>

<?php /*
    Date fields.

    Flatpickr with altInput, as on Eventcreate/Eventnew/Kingdomnew/Parknew: the
    officer reads "September 7, 2026" while the hidden real input keeps the Y-m-d
    the route parses. A native <input type="date"> renders as `mm/dd/yyyy` on a US
    locale and `dd/mm/yyyy` elsewhere, which on a form about award DATES is exactly
    the field you do not want ambiguous.

    No time component here, so altFormat is date-only rather than the module's usual
    'F j, Y  h:i K' — an award is granted on a day, not at an hour.

    Guarded on `window.flatpickr`: the CDN is third party, and a date field that
    falls back to the native picker is a cosmetic loss, not a broken form.
*/ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-hand.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-hand.js') ?>"></script>
<script>
/*
 * The hand picker on the composer.
 *
 * Runs BEFORE scrollery.js so the stage carries the right attribute for its first
 * fit — otherwise the composer opens in Chancery and silently re-letters a moment
 * later, which reads as a flicker rather than as a choice.
 *
 * The attribute goes on #sc-stage, not on <body>: scrollery.css switches hands off
 * [data-scroll-face] on ANY ancestor (section 1a-ii), and the stage is the only part
 * of this page set in a certificate hand. The sidebar and the chrome are the CRM's
 * own type and must stay that way.
 */
(function () {
    var stage = document.getElementById('sc-stage');
    var sel   = document.getElementById('sc-face');
    if (!stage || !window.ScrolleryHand) { return; }

    var Hand = window.ScrolleryHand;
    Hand.init(<?= json_encode(array_keys(Scroll::Hands())) ?>, <?= json_encode(Scroll::DEFAULT_HAND) ?>);

    /* Set the attribute directly for the opening render — Hand.apply() would also
       ask ScrolleryAutofit to re-fit, and on first paint there is nothing mounted in
       the stage yet for it to measure. */
    stage.setAttribute('data-scroll-face', Hand.get());

    Hand.bind(sel, function (face) {
        Hand.apply(stage, face, stage);
    });

    /* The signature hand, on the same stage and for the same reason as the hand: the
       attribute is set directly for the opening render (nothing is mounted yet to
       measure), then swapped in place and re-fitted on a change. */
    var sigSel = document.getElementById('sc-sig');
    if (sigSel && Hand.sig) {
        Hand.sig.init(<?= json_encode(array_keys(Scroll::SignatureHands())) ?>, <?= json_encode(Scroll::DEFAULT_SIGNATURE_HAND) ?>);
        stage.setAttribute('data-scroll-sig', Hand.sig.get());
        Hand.sig.bind(sigSel, function (key) {
            Hand.sig.apply(stage, key, stage, sigSel);
        });
    }

    /* The reason lines, on the preview stage. No re-fit: the rules are out of flow, so
       hiding them changes nothing the autofit measures. */
    var Lines = window.ScrolleryLines;
    if (Lines) {
        Lines.apply(stage, Lines.get());
        Lines.bind(document.getElementById('sc-lines'), function (on) {
            Lines.apply(stage, on);
        });
    }
}());
</script>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
(function () {
    if (!window.flatpickr) { return; }
    var opts = {
        dateFormat: 'Y-m-d',
        altInput:   true,
        altFormat:  'F j, Y',
        allowInput: true
    };
    ['#sc-date'].forEach(function (sel) {
        if (document.querySelector(sel)) { flatpickr(sel, opts); }
    });
}());
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery.js') ?>"></script>
