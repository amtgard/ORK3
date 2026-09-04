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

            <div class="sc-field">
                <label for="sc-player">Recipient</label>
                <input type="text" id="sc-player" autocomplete="off" placeholder="Search players…">
                <input type="hidden" id="sc-player-id">
                <div id="sc-player-ac" class="kn-ac-results"></div>
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
