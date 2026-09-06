<?php
/**
 * Scrollery landing — the three doors.
 *
 * The officer doors are drawn only for a viewer who holds edit authority over
 * some park or kingdom. That is a display decision only: each door re-checks
 * authority against the specific park or kingdom it is given, and the render
 * route checks again against its own URL parameters.
 */
$mundaneId = (int) ($MundaneId ?? 0);
/*
 * The scopes the viewer may ACTUALLY issue for, named and ordered by the
 * controller's viewer_scopes() — which runs the same may_issue_for() gate the
 * doors themselves run, so a door can never lead to Scrollery_denied.tpl.
 *
 * This used to be guessed here from $this->__session->park_id, which is
 * Controller_Admin's "which park am I editing" scratch value: unset for every
 * normal login (so the officer doors appeared for nobody) and, once set, taken in
 * preference to the kingdom even when only the KINGDOM check had passed.
 */
$scopes    = is_array($OfficerScope ?? null) ? $OfficerScope : [];
$isOfficer = !empty($IsOfficer) && $scopes !== [];
$multi     = count($scopes) > 1;

/* One scope: the whole card is the link, as before. More than one: the card
   carries a chooser, because picking for the officer is what caused the bug. */
$doorPath = static function (string $route, array $scope): string {
    return UIR . 'Scrollery/' . $route . '/' . $scope['Type'] . '/' . (int) $scope['Id'];
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<style>
.sc-wrap      { max-width: 1100px; margin: 0 auto 60px; }
/* .rp-context supplies the surface, border, size and colour; this only adds the
   gap before the doors and the reading width the shell has no opinion about. */
.sc-lede      { margin-bottom: 16px; border-radius: 0 0 8px 8px; }
.sc-doors     { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 16px; }
/* The doors are .rp-filter-card so they carry the shell's own card surface,
   border and dark mode; only the link behaviour and inner rhythm are local. */
.sc-door      { display: flex; flex-direction: column; padding: 20px; text-decoration: none;
                color: inherit; margin-bottom: 0; transition: border-color .15s, box-shadow .15s; }
.sc-door:hover { border-color: var(--rp-accent); box-shadow: 0 3px 10px rgba(0,0,0,0.08); }
.sc-door h2   { font-size: 15px; margin: 0 0 6px; background: none; border: 0; padding: 0;
                color: var(--rp-text); }
.sc-door p    { font-size: 12.5px; color: var(--rp-text-muted); line-height: 1.5; margin: 0 0 16px;
                flex: 1; text-align: left; }
.sc-door-icon { font-size: 20px; color: var(--rp-accent); margin-bottom: 10px; }
/* The door is a flex COLUMN, so its default stretch would blow the button out to
   the full card width — it should read as a button, not a bar. */
.sc-door .adm-btn { align-self: flex-start; }
/* The scope chooser, drawn only for a viewer who holds more than one scope. It is
   a flex ROW inside the column, so the buttons stop stretching and wrap instead. */
.sc-door-scopes       { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; align-self: stretch; }
.sc-door-scopes .adm-btn { align-self: auto; }
.sc-door-scopes-label { font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
                        font-weight: 700; color: var(--rp-text-muted); width: 100%; }
.sc-credit    { margin-top: 26px; font-size: 11px; color: var(--rp-text-muted); line-height: 1.6;
                text-align: left; }
html[data-theme="dark"] .sc-door h2 { color: var(--ork-text); background: none;
                                      border: 0; padding: 0; border-radius: 0; }
</style>

<div class="rp-root sc-wrap">
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fa-solid fa-scroll rp-header-icon"></i>
                <h1 class="rp-header-title">Scrollery</h1>
            </div>
        </div>
    </div>

    <div class="rp-context sc-lede">
        Print award certificates straight from the record — the recipient, rank, date,
        giver and issuing park all come from the award itself, so nothing is typed twice.
        Artwork exists for the ladder orders and the Order of the Flame.
    </div>

    <div class="sc-doors">
        <a class="rp-filter-card sc-door" href="<?= UIR ?>Scrollery/mine">
            <div class="sc-door-icon"><i class="fa-solid fa-scroll"></i></div>
            <h2>Reprint my scroll</h2>
            <p>Print a certificate for any award on your own record.</p>
            <span class="adm-btn adm-btn-primary">My scrolls</span>
        </a>

        <?php if ($isOfficer): ?>
            <?php
            $officerDoors = [
                ['create', 'fa-feather-pointed', 'Print a new scroll',
                    'Print one certificate while granting an award.', 'New scroll'],
                ['bulk', 'fa-layer-group', 'Print bulk scrolls',
                    'Print every certificate for an event or a date range in one pass.', 'Bulk print'],
            ];
            foreach ($officerDoors as [$route, $icon, $heading, $blurb, $cta]):
                ?>
                <?php if (!$multi): ?>
                    <a class="rp-filter-card sc-door" href="<?= $doorPath($route, $scopes[0]) ?>">
                        <div class="sc-door-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                        <h2><?= $heading ?></h2>
                        <p><?= $blurb ?></p>
                        <span class="adm-btn adm-btn-ghost"><?= $cta ?></span>
                    </a>
                <?php else: ?>
                    <?php /* A chooser, not a guess: the viewer holds both, and which one a
                             certificate is issued under is a real decision — the issuing
                             body's name and heraldry print on it. */ ?>
                    <div class="rp-filter-card sc-door">
                        <div class="sc-door-icon"><i class="fa-solid <?= $icon ?>"></i></div>
                        <h2 id="sc-door-<?= $route ?>"><?= $heading ?></h2>
                        <p><?= $blurb ?></p>
                        <div class="sc-door-scopes" role="group" aria-labelledby="sc-door-<?= $route ?>">
                            <span class="sc-door-scopes-label">Issue for</span>
                            <?php foreach ($scopes as $s): ?>
                                <a class="adm-btn adm-btn-ghost" href="<?= $doorPath($route, $s) ?>">
                                    <i class="fa-solid <?= $s['Type'] === 'kingdom' ? 'fa-crown' : 'fa-shield-halved' ?>"></i>
                                    <?= htmlspecialchars($s['Label'], ENT_QUOTES, 'UTF-8') ?>
                                </a>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>

    <p class="sc-credit">
        Certificate artwork, layer designs and flavour text by Valory Fox, from
        <a href="https://github.com/ValoryFox/Arcanyst" target="_blank" rel="noopener">Arcanyst</a>,
        used with the author's permission to continue the work. Flavour text credited in
        Arcanyst to Kisa (Shelly Alexandra).
    </p>
</div>
