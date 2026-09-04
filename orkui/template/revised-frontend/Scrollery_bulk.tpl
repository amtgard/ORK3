<?php
/**
 * "Print bulk scrolls" — an event's or a date range's awards in one pass.
 *
 * Awards without certificate artwork are listed separately rather than dropped
 * silently, so an officer is never handed fewer scrolls than they selected without
 * being told which and why.
 */
$scope     = is_array($Scope ?? null) ? $Scope : [];
$events    = is_array($Events ?? null) ? $Events : [];
$printable = is_array($Printable ?? null) ? $Printable : [];
$excluded  = is_array($Excluded ?? null) ? $Excluded : [];
$hasRun    = !empty($HasRun);
$error     = (string) ($Error ?? '');
$truncated = !empty($Truncated);
$maxRun    = (int) ($MaxRun ?? 200);
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
.sc-error    { border-left: 4px solid var(--ork-danger, #b3261e); }
.sc-warn     { color: var(--ork-danger, #b3261e); }
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
                <label for="sc-event-pick">Event</label>
                <?php if ($events !== []): ?>
                    <select id="sc-event-pick" name="event">
                        <option value="">All events in range</option>
                        <?php foreach ($events as $e): ?>
                            <option value="<?= (int) $e['EventId'] ?>"<?= (int) $e['EventId'] === (int) ($EventId ?? 0) ? ' selected' : '' ?>>
                                <?= htmlspecialchars((string) $e['Label'], ENT_QUOTES, 'UTF-8') ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                <?php else: ?>
                    <select id="sc-event-pick" name="event" disabled>
                        <option value="">No events with printable awards in this scope</option>
                    </select>
                <?php endif; ?>
            </div>
            <div class="sc-f">
                <label for="sc-event">Event id (fallback)</label>
                <input type="number" id="sc-event" name="event_id" min="0" value="<?= (int) ($EventId ?? 0) ?: '' ?>" placeholder="optional">
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
            Only awards granted by this <?= htmlspecialchars((string) ($ScopeType ?? 'park'), ENT_QUOTES, 'UTF-8') ?> are listed, whichever filters you use —
            at a shared event, another <?= htmlspecialchars((string) ($ScopeType ?? 'park'), ENT_QUOTES, 'UTF-8') ?>'s awards are not included.
            Pick from the list when the event you want appears there; the event id
            field below it is only a fallback, for one that doesn't (yet).
            An event and a date range combine: give both to narrow a large event.
            At most <?= $maxRun ?> awards are listed per search.
        </p>
    </div>

    <?php if ($error !== ''): ?>
        <div class="adm-alert sc-error" role="alert" style="margin-bottom:14px">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($truncated): ?>
        <div class="adm-alert" role="status" style="margin-bottom:14px">
            This selection was cut off at <?= $maxRun ?> awards — there are more that
            match. Set a From and To date to reach the rest a stretch at a time; the
            date range applies to an event id as well. If a single day already exceeds
            <?= $maxRun ?> awards, print what is listed and narrow by park instead.
        </div>
    <?php endif; ?>

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

    <?php if ($hasRun && $error === ''): ?>
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
    var MAX_RUN = <?= $maxRun ?>;
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
        count.textContent = n + ' selected' + (n > MAX_RUN ? ' — ' + MAX_RUN + ' max per print run' : '');
        count.classList.toggle('sc-warn', n > MAX_RUN);
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
        // One print run is capped server-side; say so here rather than let the
        // render route silently drop the tail.
        if (ids.length > MAX_RUN) {
            count.textContent = ids.length + ' selected — only ' + MAX_RUN +
                ' can be printed at once. Uncheck ' + (ids.length - MAX_RUN) + '.';
            count.classList.add('sc-warn');
            return;
        }
        count.classList.remove('sc-warn');
        // The render route re-checks permission for every id it is given, so a
        // hand-edited list cannot print someone else's award.
        window.open(<?= json_encode(UIR) ?> + 'Scrollery/render&awards=' + ids.join(',') + '&print=1',
                    '_blank', 'noopener');
    });

    sync();
}());
</script>
