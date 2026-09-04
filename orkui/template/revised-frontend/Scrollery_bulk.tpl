<?php
/**
 * "Print bulk scrolls" — an event's or a date range's awards in one pass.
 *
 * Awards without certificate artwork are listed separately rather than dropped
 * silently, so an officer is never handed fewer scrolls than they selected without
 * being told which and why.
 */
$scope     = is_array($Scope ?? null) ? $Scope : [];
$printable = is_array($Printable ?? null) ? $Printable : [];
$excluded  = is_array($Excluded ?? null) ? $Excluded : [];
$hasRun    = !empty($HasRun);
$base      = UIR . 'Scrollery/bulk/' . ($ScopeType ?? 'park') . '/' . (int) ($ScopeId ?? 0);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
.sc-wrap     { max-width: 1200px; margin: 0 auto 80px; }
.sc-filters  { display: flex; gap: 12px; align-items: flex-end; flex-wrap: wrap; }
.sc-filters .sc-f label { display: block; font-size: 11px; letter-spacing: .05em;
               text-transform: uppercase; color: var(--ork-text-hint); margin-bottom: 4px; }
.sc-table    { width: 100%; border-collapse: collapse; margin-top: 4px; }
.sc-table th { text-align: left; font-size: 11px; letter-spacing: .06em; text-transform: uppercase;
               color: var(--ork-text-hint); padding: 8px 10px; border-bottom: 2px solid var(--ork-border); }
.sc-table td { padding: 8px 10px; border-bottom: 1px solid var(--ork-border); }
.sc-table tr:last-child td { border-bottom: 0; }
.sc-check    { width: 34px; text-align: center; }
.sc-bar      { display: flex; gap: 10px; align-items: center; margin-top: 16px; }
.sc-count    { color: var(--ork-text-hint); font-size: 12px; }
.sc-empty    { padding: 30px 16px; text-align: center; color: var(--ork-text-hint); }
</style>

<div class="adm-wrap sc-wrap">
    <div class="adm-page-header">
        <h1>Print bulk scrolls</h1>
        <div class="adm-breadcrumb"><?= htmlspecialchars(trim(($scope['Title'] ?? '') . ' ' . ($scope['Name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></div>
    </div>

    <div class="adm-card" style="padding:18px;margin-bottom:16px">
        <form method="get" action="<?= HTTP_UI ?>index.php" class="sc-filters">
            <input type="hidden" name="Route" value="Scrollery/bulk/<?= ($ScopeType ?? 'park') ?>/<?= (int) ($ScopeId ?? 0) ?>">
            <div class="sc-f">
                <label for="sc-event">Event id</label>
                <input type="number" id="sc-event" name="event" min="0" value="<?= (int) ($EventId ?? 0) ?: '' ?>" placeholder="optional">
            </div>
            <div class="sc-f">
                <label for="sc-from">From</label>
                <input type="date" id="sc-from" name="from" value="<?= htmlspecialchars((string) ($From ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div class="sc-f">
                <label for="sc-to">To</label>
                <input type="date" id="sc-to" name="to" value="<?= htmlspecialchars((string) ($To ?? ''), ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <button type="submit" class="adm-btn adm-btn-primary">Find awards</button>
            <a class="adm-btn adm-btn-ghost" href="<?= UIR ?>Scrollery">Back</a>
        </form>
        <p class="sc-count" style="margin:12px 0 0">
            An event id wins over the date range. Both search this <?= htmlspecialchars((string) ($ScopeType ?? 'park'), ENT_QUOTES, 'UTF-8') ?> only.
        </p>
    </div>

    <?php if ($hasRun && $excluded !== []): ?>
        <div class="adm-alert" style="margin-bottom:14px">
            <strong><?= count($excluded) ?></strong>
            award<?= count($excluded) === 1 ? '' : 's' ?> in this selection
            <?= count($excluded) === 1 ? 'has' : 'have' ?> no certificate artwork and
            <?= count($excluded) === 1 ? 'is' : 'are' ?> not listed below:
            <?php
            $names = array_values(array_unique(array_map(
                static fn ($r) => (string) $r['Title'],
                $excluded
            )));
            sort($names);
            echo htmlspecialchars(implode(', ', array_slice($names, 0, 12)), ENT_QUOTES, 'UTF-8');
            if (count($names) > 12) {
                echo ' and ' . (count($names) - 12) . ' more';
            }
            ?>
        </div>
    <?php endif; ?>

    <?php if ($hasRun): ?>
    <div class="adm-card">
        <div class="adm-card-header">
            <span class="adm-card-title">Printable awards</span>
            <span class="adm-count"><?= count($printable) ?></span>
        </div>

        <?php if ($printable === []): ?>
            <div class="sc-empty">No awards with certificate artwork matched that selection.</div>
        <?php else: ?>
            <table class="sc-table">
                <thead>
                    <tr>
                        <th class="sc-check"><input type="checkbox" id="sc-all" checked></th>
                        <th>Recipient</th>
                        <th>Order</th>
                        <th>Rank</th>
                        <th>Awarded</th>
                        <th>Given by</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($printable as $r): ?>
                    <tr>
                        <td class="sc-check">
                            <input type="checkbox" class="sc-pick" value="<?= (int) $r['AwardsId'] ?>" checked>
                        </td>
                        <td><?= htmlspecialchars((string) $r['Recipient'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $r['Title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= (int) $r['Rank'] > 0 ? (int) $r['Rank'] : '&mdash;' ?></td>
                        <td><?= htmlspecialchars((string) $r['PrettyDate'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) $r['GivenBy'], ENT_QUOTES, 'UTF-8') ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sc-bar" style="padding:0 10px 14px">
                <button type="button" class="adm-btn adm-btn-primary" id="sc-print-bulk">
                    <i class="fa-solid fa-print"></i> Print selected
                </button>
                <span class="sc-count" id="sc-selected-count"></span>
            </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>

<script>
(function () {
    'use strict';
    var all   = document.getElementById('sc-all'),
        btn   = document.getElementById('sc-print-bulk'),
        count = document.getElementById('sc-selected-count');
    if (!btn) { return; }

    function picks() {
        return Array.prototype.slice.call(document.querySelectorAll('.sc-pick:checked'))
            .map(function (c) { return c.value; });
    }
    function sync() {
        var n = picks().length;
        count.textContent = n + (n === 1 ? ' selected' : ' selected');
        btn.disabled = n === 0;
    }

    if (all) {
        all.addEventListener('change', function () {
            document.querySelectorAll('.sc-pick').forEach(function (c) { c.checked = all.checked; });
            sync();
        });
    }
    document.querySelectorAll('.sc-pick').forEach(function (c) { c.addEventListener('change', sync); });

    btn.addEventListener('click', function () {
        var ids = picks();
        if (!ids.length) { return; }
        // The render route re-checks permission for every id it is given, so a
        // hand-edited list cannot print someone else's award.
        window.open(<?= json_encode(UIR) ?> + 'Scrollery/render&awards=' + ids.join(',') + '&print=1',
                    '_blank', 'noopener');
    });

    sync();
}());
</script>
