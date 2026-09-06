<?php
/**
 * "Print a new scroll" — an officer composes one certificate by hand.
 *
 * Controls on the left, live preview on the right. Only order, rank and variant
 * need a round trip (they change which art layers are drawn); the text fields
 * write straight into the preview's DOM, and the heraldry sliders write CSS
 * custom properties, so neither costs a fetch.
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

$awardOption = static function (array $c) use ($customKind): string {
    $kind = $customKind($c);

    return '<option value="' . (int) $c['AwardId'] . '"'
        . ' data-order="' . scroll_e($c['Order']) . '"'
        . ' data-min="' . (int) $c['MinRank'] . '"'
        . ' data-max="' . (int) $c['MaxRank'] . '"'
        . ' data-variants="' . scroll_e(implode(',', $c['Variants'])) . '"'
        . ($kind !== '' ? ' data-custom-name="' . $kind . '"' : '')
        . ' data-name="' . scroll_e($c['Name']) . '">'
        . scroll_e($c['Name']) . '</option>';
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime($reports) ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery.css?v=<?= filemtime($css) ?>">
<style>
/* Page layout only. Every control is .adm-input / .adm-select / .adm-textarea and
   every container is .rp-*; what is left here is the preview stage, the slider
   grid and the handful of field-level affordances that are genuinely this page's. */
.sc-wrap        { max-width: 1500px; margin: 0 auto 60px; }
.sc-field       { margin-bottom: 11px; position: relative; }
.sc-field:last-child { margin-bottom: 0; }
/* .sc-field-label is the same slot for a group that has no labelable control to
   point a <label for> at — the rank pills. Identical type, different element. */
.sc-field > label,
.sc-field > .sc-field-label { display: block; font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
                  color: var(--rp-text-muted); font-weight: 700; margin-bottom: 4px; }
.sc-row         { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
/* The toggles are a sentence about the issuer line, so they sit with it. */
.sc-toggles     { display: flex; gap: 14px; align-items: center; font-size: 12px; margin: -2px 0 11px; }
.sc-toggles label { display: flex; gap: 5px; align-items: center; color: var(--rp-text-body); cursor: pointer; }
.sc-hint        { font-size: 11px; line-height: 1.45; color: var(--rp-text-muted); margin: 4px 0 0; text-align: left; }

/* Preview — the sticky stage in the main column. */
.sc-preview     { background: #4a4a4a; padding: 14px; border-radius: 6px; position: sticky; top: 12px; }
.sc-stage       { background: #fff; aspect-ratio: 8.5/11; overflow: hidden; }
.sc-stage.is-busy { opacity: .45; transition: opacity .12s; }
.sc-err         { color: #e53e3e; font-size: 12px; margin-top: 8px; }
.sc-err:empty   { display: none; }

/* Heraldry sliders. */
.sc-slider      { display: grid; grid-template-columns: 66px 1fr 22px; gap: 8px; align-items: center;
                  font-size: 11px; color: var(--rp-text-muted); margin-bottom: 6px; }
.sc-slider:last-child { margin-bottom: 0; }
.sc-slider input[type=range] { width: 100%; accent-color: var(--rp-accent); }
.sc-reset       { background: none; border: 0; color: var(--rp-text-hint); cursor: pointer;
                  padding: 2px; font-size: 10px; border-radius: 3px; line-height: 1; }
.sc-reset:hover { color: var(--rp-text); background: rgba(128,128,128,.15); }

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
                    <?= scroll_e(trim(($scope['Title'] ?? '') . ' ' . ($scope['Name'] ?? ''))) ?>
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

            <?php /* 2 — THE WORDING, top to bottom exactly as scroll/_text.tpl lays it
                     out: the issuing line, then the recipient, then the rank phrase
                     ("with her third"), then the reason, then the date and signature. */ ?>
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fa-solid fa-feather-pointed"></i> Wording</div>
                <div class="rp-filter-card-body">

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
                    <?php /* The dark-ground, band, numeral and palette explanations live
                             ONLY here, and were written with textContent and never
                             announced. polite, not assertive: it is an explanation of a
                             choice the officer just made, not an error. */ ?>
                    <p class="sc-note" id="sc-recipe-note" aria-live="polite"></p>
                </div>
            </div>

            <?php /* 4 — THE DEVICE. Last, and collapsed: the sliders only matter once
                     the officer has looked at the preview and decided the heraldry sits
                     wrong on this particular ground. */ ?>
            <div class="rp-filter-card">
                <button type="button" class="rp-filter-card-header" data-sc-collapse="sc-heraldry-body" aria-expanded="false" aria-controls="sc-heraldry-body">
                    <i class="fa-solid fa-shield"></i> Heraldry adjustments
                    <i class="fa-solid fa-chevron-down rp-filter-card-chevron" aria-hidden="true"></i>
                </button>
                <div class="rp-filter-card-body" id="sc-heraldry-body" hidden>
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
                    foreach ($sliders as [$key, $label, $min, $max, $dflt]): ?>
                        <div class="sc-slider">
                            <span><?= $label ?></span>
                            <input type="range" data-heraldry-control="<?= $key ?>"
                                   aria-label="<?= $label ?>"
                                   min="<?= $min ?>" max="<?= $max ?>" value="<?= $dflt ?>"
                                   data-default="<?= $dflt ?>">
                            <button type="button" class="sc-reset" data-reset="<?= $key ?>" data-tip="Reset">
                                <i class="fa-solid fa-rotate-left"></i>
                            </button>
                        </div>
                    <?php endforeach; ?>
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
            <div class="sc-preview">
                <p class="rp-sr-only" id="sc-summary" aria-live="polite"></p>
                <div class="sc-stage" id="sc-stage" role="img" aria-busy="false"
                     aria-label="Certificate preview">
                    <svg viewBox="0 0 2550 3300" id="sc-frame" data-scroll-frame
                         style="image-resolution:300dpi;aspect-ratio:8.5/11;width:100%;height:100%;position:relative;overflow:hidden;--heraldry-filter:brightness(1) contrast(1);--heraldry-transform:translateX(0%) translateY(0%) scale(1);">
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
    kingdomId:  <?= (int) ($scope['KingdomId'] ?? 0) ?>
};
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery.js') ?>"></script>
