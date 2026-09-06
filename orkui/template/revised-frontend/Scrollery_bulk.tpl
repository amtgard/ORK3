<?php
/**
 * "Print bulk scrolls" — an event's or a date range's awards in one pass.
 *
 * Awards without certificate artwork are listed separately rather than dropped
 * silently, so an officer is never handed fewer scrolls than they selected without
 * being told which and why.
 *
 * Wears the shared .rp-* tool-page shell, like the rest of the module: the search
 * is a sidebar filter card, the results are the table area. That is also the
 * better shape for the form — stacked in a 360px column the four filters read as
 * a list, where the old single flex row wrapped unpredictably.
 */
$scope     = is_array($Scope ?? null) ? $Scope : [];
$events    = is_array($Events ?? null) ? $Events : [];
$printable = is_array($Printable ?? null) ? $Printable : [];
$excluded  = is_array($Excluded ?? null) ? $Excluded : [];
$hasRun    = !empty($HasRun);
$error     = (string) ($Error ?? '');
$maxRun    = (int) ($MaxRun ?? 200);
$chunk     = max(1, (int) ($PrintChunk ?? 25));
$base      = UIR . 'Scrollery/bulk/' . ($ScopeType ?? 'park') . '/' . (int) ($ScopeId ?? 0);
$scopeWord = htmlspecialchars((string) ($ScopeType ?? 'park'), ENT_QUOTES, 'UTF-8');

/*
 * Paging. $Total is the TRUE printable count for the filter, not the size of this
 * page — an event with 488 printable awards says 488 even though 200 are listed.
 * $NextCursor is empty on the last page.
 *
 * $Truncated no longer means "the rest was discarded": it means "there is another
 * page", and the banner it drives had to change with it. Nothing is unreachable now.
 */
$total      = (int) ($Total ?? 0);
$shown      = count($printable);
$nextCursor = (string) ($NextCursor ?? '');
$hasMore    = $nextCursor !== '';

/* The filter, minus the cursor — what a "next page" link has to carry forward. */
$pageQuery = static function (string $cursor) use ($base, $EventId, $From, $To): string {
    $qs = [];
    if ((int) ($EventId ?? 0) > 0) { $qs['event'] = (int) $EventId; }
    if ((string) ($From ?? '') !== '') { $qs['from'] = (string) $From; }
    if ((string) ($To ?? '') !== '') { $qs['to'] = (string) $To; }
    if ($cursor !== '') { $qs['cursor'] = $cursor; }

    // "&param=", never "?param=" — the route already carries its own query string.
    return $base . ($qs === [] ? '' : '&' . http_build_query($qs));
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<style>
.sc-wrap     { max-width: 1300px; margin: 0 auto 60px; }
/* The filters stack in the sidebar; each is a labelled .adm-input / .adm-select. */
.sc-f        { margin-bottom: 11px; }
.sc-f label  { display: block; font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
               color: var(--rp-text-muted); font-weight: 700; margin-bottom: 4px; }
.sc-f-row    { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.sc-submit   { display: flex; gap: 8px; margin-top: 14px; }

.sc-table    { width: 100%; border-collapse: collapse; font-size: 13px; }
.sc-table th { text-align: left; font-size: 11px; letter-spacing: .06em; text-transform: uppercase;
               color: var(--rp-text-muted); padding: 8px 10px; border-bottom: 2px solid var(--rp-border); }
.sc-table td { padding: 8px 10px; border-bottom: 1px solid var(--rp-border); }
.sc-table tr:last-child td { border-bottom: 0; }
.sc-check    { width: 34px; text-align: center; }
.sc-bar      { display: flex; gap: 10px; align-items: center; flex-wrap: wrap; margin-top: 14px;
               padding-top: 12px; border-top: 1px solid var(--rp-border); }
.sc-count    { color: var(--rp-text-muted); font-size: 12px; }
/* The batch links only exist once a run needs more than one tab; an empty bar would
   otherwise draw its own top rule across the page. */
.sc-batches:empty { display: none; }
.sc-empty    { padding: 30px 16px; text-align: center; color: var(--rp-text-muted); }
.sc-warn     { color: #c05621; font-weight: 600; }

/* reports.css carries no alert; these are the two tones this page needs. */
.sc-alert    { border: 1px solid var(--rp-border); border-left: 4px solid var(--rp-accent);
               background: var(--rp-bg-light); border-radius: 6px; padding: 10px 14px;
               font-size: 12.5px; line-height: 1.55; color: var(--rp-text-body);
               margin-bottom: 12px; text-align: left; }
.sc-alert-error { border-left-color: #c53030; }

.sc-hint     { font-size: 11px; line-height: 1.5; color: var(--rp-text-muted);
               margin: 12px 0 0; text-align: left; }

html[data-theme="dark"] .sc-table th,
html[data-theme="dark"] .sc-table td { border-bottom-color: #4a5568; }
html[data-theme="dark"] .sc-alert    { background: #2d3748; border-color: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .sc-warn     { color: #f6ad55; }

@media (max-width: 1000px) {
    .rp-body { flex-direction: column; }
    .rp-sidebar, .rp-sidebar-wide { width: 100%; }
}
/* Stacked-card mode on a phone — the shell has no opinion about this table. */
@media (max-width: 640px) {
    .sc-table thead { display: none; }
    .sc-table tr    { display: block; border-bottom: 1px solid var(--rp-border); padding: 8px 0; }
    .sc-table td    { display: block; border: 0; padding: 2px 10px; }
    .sc-table td.sc-check { display: inline-block; width: auto; }
}
</style>

<div class="rp-root sc-wrap">

    <div class="rp-header">
        <div class="rp-header-left">
            <div class="rp-header-icon-title">
                <i class="fa-solid fa-layer-group rp-header-icon"></i>
                <h1 class="rp-header-title">Print bulk scrolls</h1>
            </div>
            <?php /* Scope switcher — see the note on the same block in Scrollery_create.tpl.
                     Every target has already been through the controller's may_issue_for(). */ ?>
            <div class="rp-header-scope">
                <span class="rp-scope-chip">
                    <i class="fa-solid <?= ($ScopeType ?? 'park') === 'kingdom' ? 'fa-crown' : 'fa-shield-halved' ?>"></i>
                    <?= htmlspecialchars(trim(($scope['Title'] ?? '') . ' ' . ($scope['Name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>
                </span>
                <?php foreach (is_array($OtherScopes ?? null) ? $OtherScopes : [] as $s): ?>
                    <a class="rp-scope-chip" href="<?= UIR ?>Scrollery/bulk/<?= $s['Type'] ?>/<?= (int) $s['Id'] ?>">
                        <span class="rp-scope-chip-label">Switch to</span>
                        <i class="fa-solid <?= $s['Type'] === 'kingdom' ? 'fa-crown' : 'fa-shield-halved' ?>"></i>
                        <?= htmlspecialchars($s['Label'], ENT_QUOTES, 'UTF-8') ?>
                    </a>
                <?php endforeach; ?>
            </div>
        </div>
        <div class="rp-header-actions">
            <a class="rp-btn-ghost" href="<?= UIR ?>Scrollery">
                <i class="fa-solid fa-arrow-left"></i> Back
            </a>
        </div>
    </div>

    <div class="rp-body">
        <div class="rp-sidebar rp-sidebar-wide">
            <div class="rp-filter-card">
                <div class="rp-filter-card-header"><i class="fa-solid fa-filter"></i> Find awards</div>
                <div class="rp-filter-card-body">
                    <form method="get" action="<?= HTTP_UI ?>index.php">
                        <input type="hidden" name="Route" value="Scrollery/bulk/<?= ($ScopeType ?? 'park') ?>/<?= (int) ($ScopeId ?? 0) ?>">

                        <div class="sc-f">
                            <label for="sc-event-pick">Event</label>
                            <?php if ($events !== []): ?>
                                <select id="sc-event-pick" name="event" class="adm-select">
                                    <option value="">All events in range</option>
                                    <?php foreach ($events as $e): ?>
                                        <option value="<?= (int) $e['EventId'] ?>"<?= (int) $e['EventId'] === (int) ($EventId ?? 0) ? ' selected' : '' ?>>
                                            <?= htmlspecialchars((string) $e['Label'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php else: ?>
                                <select id="sc-event-pick" name="event" class="adm-select" disabled>
                                    <option value="">No events with printable awards in this scope</option>
                                </select>
                            <?php endif; ?>
                        </div>

                        <div class="sc-f-row">
                            <div class="sc-f">
                                <label for="sc-from">From</label>
                                <input type="date" id="sc-from" name="from" class="adm-input"
                                       value="<?= htmlspecialchars((string) ($From ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                            <div class="sc-f">
                                <label for="sc-to">To</label>
                                <input type="date" id="sc-to" name="to" class="adm-input"
                                       value="<?= htmlspecialchars((string) ($To ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            </div>
                        </div>

                        <div class="sc-f">
                            <label for="sc-event">Event id (fallback)</label>
                            <input type="number" id="sc-event" name="event_id" min="0" class="adm-input"
                                   value="<?= (int) ($EventId ?? 0) ?: '' ?>" placeholder="Only if the event isn't listed above">
                        </div>

                        <div class="sc-submit">
                            <button type="submit" class="adm-btn adm-btn-primary">
                                <i class="fa-solid fa-magnifying-glass"></i> Find awards
                            </button>
                        </div>

                        <p class="sc-hint">
                            Only awards granted by this <?= $scopeWord ?> are listed, whichever
                            filters you use — at a shared event, another <?= $scopeWord ?>'s
                            awards are not included. An event and a date range combine: give
                            both to narrow a large event. Results come <?= $maxRun ?> at a
                            time with a link to the next page, so a large court is reachable
                            in full.
                        </p>
                    </form>
                </div>
            </div>
        </div>

        <div class="rp-table-area">

            <?php if ($error !== ''): ?>
                <div class="sc-alert sc-alert-error" role="alert">
                    <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
                </div>
            <?php endif; ?>

            <?php /* The old banner here said the selection had been "cut off" and told the
                     officer to narrow by date — advice that could not reach row 201 of a
                     488-award court, because the query was capped with no offset. It
                     pages now, so this is a pager and not an apology. */ ?>
            <?php $onLaterPage = (string) ($Cursor ?? '') !== ''; ?>
            <?php if ($hasRun && $error === '' && ($total > $shown || $onLaterPage)): ?>
                <div class="sc-alert" role="status">
                    <?php if ($hasMore): ?>
                        Showing <strong><?= $shown ?></strong> of <strong><?= $total ?></strong>
                        printable award<?= $total === 1 ? '' : 's' ?>.
                        <a href="<?= htmlspecialchars($pageQuery($nextCursor), ENT_QUOTES, 'UTF-8') ?>">
                            Next <?= min($maxRun, max(0, $total - $shown)) ?> &rarr;
                        </a>
                        Each page prints on its own; nothing in this selection is out of reach.
                    <?php else: ?>
                        The last <strong><?= $shown ?></strong> of <strong><?= $total ?></strong>
                        printable award<?= $total === 1 ? '' : 's' ?>.
                        <a href="<?= htmlspecialchars($pageQuery(''), ENT_QUOTES, 'UTF-8') ?>">
                            &larr; Back to the first page
                        </a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <?php /* Kept for the rare row the WHERE cannot reject, but it is now
                     near-always empty: officer positions and the excluded classes are
                     filtered in SQL rather than in PHP. The wording no longer claims
                     "no certificate artwork" as the reason, because for most of what
                     used to land here the reason is policy, not missing art. */ ?>
            <?php if ($hasRun && $excluded !== []): ?>
                <div class="sc-alert">
                    <strong><?= count($excluded) ?></strong>
                    award<?= count($excluded) === 1 ? '' : 's' ?> in this selection
                    <?= count($excluded) === 1 ? 'is' : 'are' ?> not listed below &mdash;
                    Scrollery either has no certificate for
                    <?= count($excluded) === 1 ? 'it' : 'them' ?> or deliberately does not
                    print <?= count($excluded) === 1 ? 'it' : 'them' ?>:
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

            <?php if (!$hasRun): ?>
                <div class="sc-empty">
                    Pick an event or a date range on the left, then choose
                    <strong>Find awards</strong>.
                </div>
            <?php elseif ($error === ''): ?>
                <?php if ($printable === []): ?>
                    <div class="sc-empty">No awards with certificate artwork matched that selection.</div>
                <?php else: ?>
                    <table class="sc-table">
                        <thead>
                            <tr>
                                <?php /* Unchecked. A date-range search over a busy weekend
                                         legitimately returns 50-100 rows, and "print all of
                                         them" is not a safe default for an action that
                                         commits card stock. */ ?>
                                <th class="sc-check"><input type="checkbox" id="sc-all" aria-label="Select all"></th>
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
                                    <input type="checkbox" class="sc-pick" value="<?= (int) $r['AwardsId'] ?>"
                                           aria-label="Print <?= htmlspecialchars((string) $r['Recipient'], ENT_QUOTES, 'UTF-8') ?>">
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

                    <?php /* The button says the real number, so "print" is never a leap in
                             the dark; the count is role="alert" because with more than
                             MAX_RUN selected it is the ONLY feedback the button gives — it
                             otherwise appears to do nothing at all. */ ?>
                    <div class="sc-bar">
                        <button type="button" class="adm-btn adm-btn-primary" id="sc-print-bulk">
                            <i class="fa-solid fa-print"></i> <span id="sc-print-label">Print selected</span>
                        </button>
                        <?php /* "all" is all on THIS PAGE — the only rows that have a
                                 checkbox. The banner above carries the true total, so the
                                 two together never overstate what one click does. */ ?>
                        <button type="button" class="adm-btn adm-btn-ghost" id="sc-select-all">
                            Select all <?= $shown ?> on this page
                        </button>
                        <button type="button" class="adm-btn adm-btn-ghost" id="sc-select-none">
                            Select none
                        </button>
                        <span class="sc-count" id="sc-selected-count" role="alert"></span>
                    </div>
                    <?php /* Filled by the print button when a run needs more than one
                             batch. role="status" because it appears after the officer's
                             own click and tells them where the rest of the run went. */ ?>
                    <div class="sc-bar sc-batches" id="sc-batches" role="status"></div>
                    <p class="sc-hint">
                        Tick a row, then shift-click another to select everything between them.
                        Printing opens a preview first &mdash; nothing reaches the printer until
                        you choose Print there. Runs of more than <?= $chunk ?> open in
                        batches of <?= $chunk ?>, one tab each: a full page of certificates is
                        a large image and browsers stop rendering them past about that many.
                    </p>
                <?php endif; ?>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php /* revised.js carries the CRM's own themed confirm (window.orkConfirm) and its
         .ork-confirm-* styles are already in revised.css, which this page links.
         Native confirm() is not an option — it freezes the browser automation. */ ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script>
(function () {
    'use strict';
    var MAX_RUN = <?= $maxRun ?>;
    // The browser's own limit, not the server's. One certificate is a 2550x3300
    // plate that decodes to ~33.7 MB of RGBA, so a 50-certificate tab touches
    // 500-840 MB of bitmap and the print rasteriser gives up. Runs are opened in
    // windows of this size.
    var CHUNK = <?= $chunk ?>;
    // Above this many pages, "Print" stops being a click and starts being a
    // decision about a ream of card stock, so it asks first.
    var CONFIRM_OVER = 20;
    // The true count for the filter, which is not the number of rows on this page.
    var TOTAL = <?= $total ?>;
    var all     = document.getElementById('sc-all'),
        btn     = document.getElementById('sc-print-bulk'),
        label   = document.getElementById('sc-print-label'),
        selAll  = document.getElementById('sc-select-all'),
        selNone = document.getElementById('sc-select-none'),
        batchBar = document.getElementById('sc-batches'),
        count   = document.getElementById('sc-selected-count');
    if (!btn) { return; }

    var boxes = Array.prototype.slice.call(document.querySelectorAll('.sc-pick'));

    function picks() {
        return boxes.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
    }

    function plural(n) { return n === 1 ? 'certificate' : 'certificates'; }

    function sync() {
        var n = picks().length;
        // The button reads the real number rather than "Print selected", so the
        // size of what is about to happen is on the control itself.
        if (label) { label.textContent = n === 0 ? 'Print selected' : 'Print ' + n + ' ' + plural(n); }
        // Both numbers when they differ: how many are on this page, and how many the
        // filter actually matches. Reporting only the page would understate the run.
        var scope = boxes.length + ' on this page'
                  + (TOTAL > boxes.length ? ' of ' + TOTAL + ' matching' : '');
        count.textContent = n > MAX_RUN
            ? n + ' selected — ' + MAX_RUN + ' max per print run'
            : (n === 0 ? '' : n + ' of ' + scope + ' selected');
        count.classList.toggle('sc-warn', n > MAX_RUN);
        btn.disabled = n === 0;
        if (all) {
            all.checked       = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
    }

    function setAll(state) {
        boxes.forEach(function (c) { c.checked = state; });
        sync();
    }

    if (all)     { all.addEventListener('change', function () { setAll(all.checked); }); }
    if (selAll)  { selAll.addEventListener('click',  function () { setAll(true); }); }
    if (selNone) { selNone.addEventListener('click', function () { setAll(false); }); }

    // Shift-click selects the range between the last box clicked and this one, the
    // way every other checklist does. `click`, not `change`: only a click carries
    // shiftKey, and a keyboard toggle correctly falls through to the plain path.
    var lastIndex = -1;
    boxes.forEach(function (c, i) {
        c.addEventListener('click', function (e) {
            if (e.shiftKey && lastIndex > -1 && lastIndex !== i) {
                var from = Math.min(lastIndex, i), to = Math.max(lastIndex, i);
                for (var j = from; j <= to; j++) { boxes[j].checked = c.checked; }
            }
            lastIndex = i;
            sync();
        });
        c.addEventListener('change', sync);
    });

    /*
     * Open the run, in windows of CHUNK.
     *
     * The server will accept MAX_RUN in one URL, but the browser will not survive it
     * — each certificate is a full 2550x3300 plate and the print rasteriser runs out
     * of decoded bitmap somewhere well below 50. Chunking is per decision item 26.
     *
     * No &print=1 on any of them: the render route is the PREVIEW. Nothing reaches
     * the printer until the officer chooses Print on each tab, which is what makes a
     * long run recoverable when the heraldry turns out to be a placeholder.
     *
     * The render route re-checks permission for every id it is given, so a
     * hand-edited list cannot print someone else's award.
     */
    function batchUrl(ids) {
        return <?= json_encode(UIR) ?> + 'Scrollery/render&awards=' + ids.join(',');
    }

    function openRun(ids) {
        var batches = [];
        for (var i = 0; i < ids.length; i += CHUNK) { batches.push(ids.slice(i, i + CHUNK)); }

        // The first batch opens on the click that asked for it. The rest do NOT get
        // opened in the same loop: every browser blocks all but the first popup from
        // one gesture, so a 100-certificate run would silently produce one tab and
        // three swallowed ones. They are offered as links instead — one gesture each,
        // nothing blocked, and the officer can work through them at printer speed.
        window.open(batchUrl(batches[0]), '_blank', 'noopener');
        renderBatches(batches);
    }

    function renderBatches(batches) {
        if (!batchBar) { return; }
        batchBar.innerHTML = '';
        if (batches.length < 2) { return; }

        var intro = document.createElement('span');
        intro.className = 'sc-count';
        intro.textContent = 'Opened batch 1 of ' + batches.length + ' (' + batches[0].length +
            ' ' + plural(batches[0].length) + '). Open the rest one at a time:';
        batchBar.appendChild(intro);

        batches.slice(1).forEach(function (b, i) {
            var a = document.createElement('a');
            a.className = 'adm-btn adm-btn-ghost';
            a.target = '_blank';
            a.rel = 'noopener';
            a.href = batchUrl(b);
            a.textContent = 'Batch ' + (i + 2) + ' — ' + b.length + ' ' + plural(b.length);
            batchBar.appendChild(a);
        });
    }

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

        if (ids.length > CONFIRM_OVER && typeof window.orkConfirm === 'function') {
            var n = Math.ceil(ids.length / CHUNK);
            window.orkConfirm(
                'This opens a preview of ' + ids.length + ' ' + plural(ids.length) +
                ' — one page each' +
                (n > 1 ? ', in ' + n + ' batches of up to ' + CHUNK + '. The first opens now and '
                       + 'the rest appear as links to open one at a time.'
                       : '. Nothing prints until you choose Print on that page.'),
                function () { openRun(ids); },
                { title: 'Print ' + ids.length + ' ' + plural(ids.length) + '?',
                  okLabel: 'Open preview', danger: false }
            );
            return;
        }
        openRun(ids);
    });

    sync();
}());
</script>
