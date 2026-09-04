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
$isOfficer = !empty($IsOfficer);
$parkId    = isset($this->__session->park_id) ? (int) $this->__session->park_id : 0;
$kingdomId = isset($this->__session->kingdom_id) ? (int) $this->__session->kingdom_id : 0;
$officerScope = $parkId > 0 ? 'park/' . $parkId : ($kingdomId > 0 ? 'kingdom/' . $kingdomId : '');
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
.sc-wrap      { max-width: 1100px; margin: 0 auto 80px; }
.sc-lede      { color: var(--ork-text-hint); font-size: 13px; margin: 0 0 22px; line-height: 1.6; max-width: 70ch; }
.sc-doors     { display: grid; grid-template-columns: repeat(auto-fit, minmax(270px, 1fr)); gap: 16px; }
.sc-door      { display: flex; flex-direction: column; padding: 20px; text-decoration: none; color: inherit; }
.sc-door h2   { font-size: 15px; margin: 0 0 6px; background: none; border: 0; padding: 0; }
.sc-door p    { font-size: 12.5px; color: var(--ork-text-hint); line-height: 1.5; margin: 0 0 16px; flex: 1; }
.sc-door-icon { font-size: 20px; color: var(--ork-accent, #b8860b); margin-bottom: 10px; }
.sc-credit    { margin-top: 30px; font-size: 11px; color: var(--ork-text-hint); line-height: 1.6; }
</style>

<div class="adm-wrap sc-wrap">
    <div class="adm-page-header">
        <h1>Scrollery</h1>
    </div>

    <p class="sc-lede">
        Print award certificates straight from the record — the recipient, rank, date,
        giver and issuing park all come from the award itself, so nothing is typed twice.
        Artwork exists for the ladder orders and the Order of the Flame.
    </p>

    <div class="sc-doors">
        <a class="adm-card sc-door" href="<?= UIR ?>Scrollery/mine">
            <div class="sc-door-icon"><i class="fa-solid fa-scroll"></i></div>
            <h2>Reprint my scroll</h2>
            <p>Print a certificate for any award on your own record.</p>
            <span class="adm-btn adm-btn-primary">My scrolls</span>
        </a>

        <?php if ($isOfficer && $officerScope !== ''): ?>
            <a class="adm-card sc-door" href="<?= UIR ?>Scrollery/create/<?= $officerScope ?>">
                <div class="sc-door-icon"><i class="fa-solid fa-feather-pointed"></i></div>
                <h2>Print a new scroll</h2>
                <p>Print one certificate while granting an award.</p>
                <span class="adm-btn adm-btn-ghost">New scroll</span>
            </a>

            <a class="adm-card sc-door" href="<?= UIR ?>Scrollery/bulk/<?= $officerScope ?>">
                <div class="sc-door-icon"><i class="fa-solid fa-layer-group"></i></div>
                <h2>Print bulk scrolls</h2>
                <p>Print every certificate for an event or a date range in one pass.</p>
                <span class="adm-btn adm-btn-ghost">Bulk print</span>
            </a>
        <?php endif; ?>
    </div>

    <p class="sc-credit">
        Certificate artwork, layer designs and flavour text by Valory Fox, from
        <a href="https://github.com/ValoryFox/Arcanyst" target="_blank" rel="noopener">Arcanyst</a>,
        used with the author's permission to continue the work. Flavour text credited in
        Arcanyst to Kisa (Shelly Alexandra).
    </p>
</div>
