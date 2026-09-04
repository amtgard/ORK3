<?php
/**
 * "Reprint my scroll" — the player's own award history, filtered to the awards
 * that actually have certificate artwork.
 *
 * Phase 1 covers the 14 ladders plus the Flame. Everything else in the ORK's
 * catalogue — every Master, every title, every non-ladder and kingdom-specific
 * award — has no art, so it is not listed at all rather than shown with a dead
 * button.
 */
$awards    = is_array($Awards ?? null) ? $Awards : [];
$mundaneId = (int) ($MundaneId ?? 0);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
.sc-wrap        { max-width: 1100px; margin: 0 auto 80px; }
.sc-lede        { color: var(--ork-text-hint); font-size: 13px; margin: 0 0 18px; line-height: 1.5; }
.sc-order       { font-weight: 600; }
.sc-where       { color: var(--ork-text-hint); font-size: 12px; }
.sc-actions     { text-align: right; white-space: nowrap; }
.sc-empty       { padding: 34px 18px; text-align: center; color: var(--ork-text-hint); }
.sc-credit      { margin-top: 26px; font-size: 11px; color: var(--ork-text-hint); line-height: 1.6; }
/* .adm-table has no stacked-card mode; this table needs one on a phone. */
@media (max-width: 640px) {
    .sc-wrap .adm-table thead { display: none; }
    .sc-wrap .adm-table tr    { display: block; border-bottom: 1px solid var(--ork-border); padding: 10px 0; }
    .sc-wrap .adm-table td    { display: block; border: 0; padding: 2px 12px; }
    .sc-actions               { text-align: left; padding-top: 8px !important; }
}
</style>

<div class="adm-wrap sc-wrap">
    <div class="adm-page-header">
        <h1>My Scrolls</h1>
    </div>

    <div class="adm-card">
        <div class="adm-card-header">
            <span class="adm-card-title">Printable awards</span>
            <span class="adm-count"><?= count($awards) ?></span>
        </div>

        <?php if ($awards === []): ?>
            <div class="sc-empty">
                <p>None of your awards has certificate artwork yet.</p>
                <p><a href="<?= UIR ?>Player/profile/<?= $mundaneId ?>">Back to your profile</a></p>
            </div>
        <?php else: ?>
            <p class="sc-lede">
                Certificate artwork exists for the ladder orders and the Order of the Flame.
                Awards without artwork are not listed here.
            </p>
            <table class="adm-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Rank</th>
                        <th>Awarded</th>
                        <th>Given by</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($awards as $a): ?>
                    <tr>
                        <td>
                            <div class="sc-order"><?= htmlspecialchars((string) $a['Title'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (trim((string) ($a['IssuerName'] ?? '')) !== ''): ?>
                                <div class="sc-where">
                                    <?= htmlspecialchars(trim(($a['IssuerTitle'] ?? '') . ' ' . $a['IssuerName']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $a['Rank'] > 0 ? (int) $a['Rank'] : '&mdash;' ?></td>
                        <td><?= htmlspecialchars((string) ($a['PrettyDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($a['GivenBy'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="sc-actions">
                            <a class="adm-btn adm-btn-primary"
                               href="<?= htmlspecialchars((string) $a['PrintUrl'], ENT_QUOTES, 'UTF-8') ?>"
                               target="_blank" rel="noopener">
                                <i class="fa-solid fa-print"></i> Print
                            </a>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <p class="sc-credit">
        Certificate artwork, layer designs and flavour text by Valory Fox, from Arcanyst,
        used with the author's permission. Flavour text credited in Arcanyst to Kisa
        (Shelly Alexandra).
    </p>
</div>
