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
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<style>
.sc-wrap        { max-width: 1100px; margin: 0 auto 60px; }
/* .rp-context supplies surface, border, size and colour. */
.sc-lede        { margin-bottom: 14px; border-radius: 0 0 8px 8px; }
.sc-order       { font-weight: 600; color: var(--rp-text); }
.sc-where       { color: var(--rp-text-muted); font-size: 12px; }
/* Two controls now (View, Print); nowrap keeps them on one line on a wide table
   and the flex row keeps the gap honest once the phone breakpoint left-aligns. */
.sc-actions     { text-align: right; white-space: nowrap; }
.sc-actions .adm-btn + .adm-btn { margin-left: 6px; }
.sc-empty       { padding: 34px 18px; text-align: center; color: var(--rp-text-muted); }
.sc-credit      { margin-top: 22px; font-size: 11px; color: var(--rp-text-muted); line-height: 1.6;
                  text-align: left; }
.sc-table       { width: 100%; border-collapse: collapse; font-size: 13px; }
.sc-table th    { text-align: left; font-size: 11px; text-transform: uppercase; letter-spacing: .05em;
                  color: var(--rp-text-muted); padding: 8px 12px; border-bottom: 1px solid var(--rp-border); }
.sc-table td    { padding: 9px 12px; border-bottom: 1px solid var(--rp-border); vertical-align: top; }
.sc-table tr:last-child td { border-bottom: 0; }
html[data-theme="dark"] .sc-order { color: var(--ork-text); }
html[data-theme="dark"] .sc-table th,
html[data-theme="dark"] .sc-table td { border-bottom-color: #4a5568; }
/* The table needs a stacked-card mode on a phone; the shell has no opinion here. */
@media (max-width: 640px) {
    .sc-table thead { display: none; }
    .sc-table tr    { display: block; border-bottom: 1px solid var(--rp-border); padding: 10px 0; }
    .sc-table td    { display: block; border: 0; padding: 2px 12px; }
    .sc-actions     { text-align: left; padding-top: 8px !important; }
}
</style>

<div class="rp-root sc-wrap">
    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fa-solid fa-scroll rp-header-icon"></i>
                <h1 class="rp-header-title">My Scrolls</h1>
            </div>
            <div class="rp-header-scope">
                <span class="rp-scope-chip">
                    <i class="fa-solid fa-certificate"></i>
                    <?= count($awards) ?> printable award<?= count($awards) === 1 ? '' : 's' ?>
                </span>
            </div>
        </div>
        <div class="rp-header-actions">
            <a class="rp-btn-ghost" href="<?= UIR ?>Scrollery">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="rp-context sc-lede">
        Certificate artwork exists for the ladder orders and the Order of the Flame.
        Awards without artwork are not listed here.
    </div>

    <div class="rp-table-area">
        <?php if ($awards === []): ?>
            <div class="sc-empty">
                <p>None of your awards has certificate artwork yet.</p>
                <p><a href="<?= UIR ?>Player/profile/<?= $mundaneId ?>">Back to your profile</a></p>
            </div>
        <?php else: ?>
            <table class="sc-table">
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
                        <?php /* One row is one page, so the stake is small enough that Print
                                 keeps its single click — but View reaches the same URL
                                 WITHOUT print=1, so anyone who wants to check the heraldry,
                                 the pronoun or a blank reason line before committing paper
                                 can. Bulk, where the stake is 50-100 pages, has no direct
                                 print at all.

                                 A revoked award gets neither control. Model_Scrollery::
                                 printable_awards_for_player() already drops those rows, and
                                 the render route refuses them again by id — this is the
                                 third check, not the only one, and it is the one that keeps
                                 a dead button off the page if the list ever widens. */ ?>
                        <td class="sc-actions">
                            <?php if ((int) ($a['Revoked'] ?? 0) === 1): ?>
                                <span class="sc-where">Revoked</span>
                            <?php else: ?>
                                <a class="adm-btn adm-btn-ghost"
                                   href="<?= UIR ?>Scrollery/render&awards=<?= (int) $a['AwardsId'] ?>"
                                   target="_blank" rel="noopener">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                                <a class="adm-btn adm-btn-primary"
                                   href="<?= UIR ?>Scrollery/render&awards=<?= (int) $a['AwardsId'] ?>&print=1"
                                   target="_blank" rel="noopener">
                                    <i class="fa-solid fa-print"></i> Print
                                </a>
                            <?php endif; ?>
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
