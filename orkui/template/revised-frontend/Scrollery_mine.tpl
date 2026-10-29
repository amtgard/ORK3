<?php
/**
 * "Reprint my scroll" — the player's own award history, filtered to the awards
 * Scrollery is allowed to print.
 *
 * The list is bounded by one person's award record, but that bound is not small:
 * the busiest player in the ORK holds 278 printable awards, 4,523 players hold
 * more than 25 and 1,045 more than 60. It used to arrive as one unsorted,
 * unfiltered, unpaginated table with a Print button per row.
 *
 * So the page now carries a DataTables filter, sort and paging, a checkbox per row with one
 * "Print selected", a Design column (the order's own artwork, or the composed
 * scroll) and — the thing that was silently missing — a VARIANT control for the
 * two orders that ship more than one plate. Scrollery/render has always read
 * &variant=; nothing on this page ever set it, so an Owl reprint could only ever
 * reach the first of its three.
 *
 * Filtering, sorting and paging all happen in the browser over the whole set.
 * The rows are already in memory and already authorised; putting any of that
 * behind a round trip would make the page slower and no more correct.
 */
$awards    = is_array($Awards ?? null) ? $Awards : [];
$mundaneId = (int) ($MundaneId ?? 0);
$maxRun    = max(1, (int) ($MaxRun ?? 200));
$chunk     = max(1, (int) ($PrintChunk ?? 25));

/* The variant column is drawn only when something on this page actually has one —
   Owl (parchment / cyanotype / transparency) and Battle (arcane / martial) are the
   only two orders that do, so for most players the column would be a row of dashes. */
$hasVariants = false;
foreach ($awards as $a) {
    if (!empty($a['Variants'])) {
        $hasVariants = true;
        break;
    }
}

/*
 * A Custom Award or Custom Title that nobody ever named resolves to an EMPTY title
 * — deliberately, because "Custom Award" is the ORK's internal placeholder and
 * Scroll::ResolveTitle() will not letter a placeholder onto a certificate. The
 * certificate is right to print a blank line there. A blank cell in a LIST is not
 * the same thing: it reads as a rendering fault, and under an A-to-Z sort it puts a
 * run of empty rows at the top. So the row says what it is, and sorts last.
 */
$displayTitle = static function (array $a): string {
    $t = trim((string) ($a['Title'] ?? ''));

    return $t !== '' ? $t : 'Unnamed award';
};

?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-list.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-list.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/scrollery-hand.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/scrollery-hand.css') ?>">
<style>
.sc-wrap        { max-width: 1100px; margin: 0 auto 60px; }
/* .rp-context supplies surface, border, size and colour. */
/* .rp-context is a flex row (icon + text); this one holds three paragraphs, which
   stack — the p margins below are the spacing. */
.sc-lede        { margin-bottom: 14px; border-radius: 0 0 8px 8px; flex-direction: column; gap: 0; }
.sc-lede p      { margin: 0 0 8px; text-align: left; }
.sc-lede p:last-child { margin-bottom: 0; }
.sc-order       { font-weight: 600; color: var(--rp-text); }
/* An award the ORK holds no name for. Muted and italic, so the row reads as a known
   gap in the record rather than as a cell that failed to render. */
.sc-unnamed     { font-weight: 500; font-style: italic; color: var(--rp-text-muted); }
.sc-where       { color: var(--rp-text-muted); font-size: 12px; }
/* Two controls (View, Print); nowrap keeps them on one line on a wide table and the
   flex row keeps the gap honest once the phone breakpoint left-aligns. */
.sc-actions     { text-align: right; white-space: nowrap; }
.sc-actions .adm-btn + .adm-btn { margin-left: 6px; }
.sc-credit      { margin-top: 22px; font-size: 11px; color: var(--rp-text-muted); line-height: 1.6;
                  text-align: left; }
/* The toolbar above the table: the hand and the reason lines every link carries.
   Filter, sort and paging are DataTables' own. */
.sc-tools       { display: flex; gap: 10px; align-items: flex-end; flex-wrap: wrap;
                  margin-bottom: 12px; }
.sc-tool        { display: flex; flex-direction: column; gap: 4px; }
.sc-tool label  { font-size: 10px; letter-spacing: .05em; text-transform: uppercase;
                  color: var(--rp-text-muted); font-weight: 700; }

/* The design cell. Deliberately typographic rather than a coloured pill: it is a
   statement of fact about the certificate, not a status that needs an alarm. */
.sc-design      { color: var(--rp-text-muted); font-size: 12px; white-space: nowrap; }
.sc-design i    { margin-right: 4px; opacity: .75; }
.sc-variant     { font-size: 12px; padding: 3px 6px; max-width: 150px; }

html[data-theme="dark"] .sc-order { color: var(--ork-text); }
/* The unnamed row carries BOTH classes, and the dark override above is 0,2,0 against
   .sc-unnamed's 0,1,0 — so in dark mode the muted italic came back at full text
   colour and stopped reading as a gap. Confirmed by computed style, not by eye. */
html[data-theme="dark"] .sc-order.sc-unnamed { color: var(--rp-text-muted); }
/* The stacked-card mode itself is in scrollery-list.css. reports.css's
   `.rp-table-area table.dataTable tbody td` (0,2,3) restates padding and a bottom
   rule on every cell, which would rule each stacked line; matched here so the card
   stays one card. */
@media (max-width: 600px) {
    .rp-table-area table.dataTable.sc-table tbody td { border: 0; padding: 2px 10px; }
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

    <?php /* The old copy said certificate artwork existed "for the ladder orders and
             the Order of the Flame" and that awards without artwork were "not listed
             here". Both are now wrong: the Flame IS one of the ladders, the two custom
             entries print on a composed scroll, and most of what is missing from this
             list is missing by POLICY rather than for want of art. Saying "no artwork"
             about a knighthood invites the reader to wait for artwork that is never
             coming.

             The naming rule is stated here rather than left to be discovered.
             ork_awards stores no persona and the ORK has no persona-history table, so
             a reprint necessarily carries the name the recipient goes by today. That
             is the right behaviour — a keepsake should say who you are — and it is the
             player-facing door, so it is said out loud. */ ?>
    <div class="rp-context sc-lede">
        <p>
            These are the awards on your record that Scrollery can print: the
            <strong>ladder orders</strong> — Battle, Crown, Dragon, Flame, Garber, Griffin,
            Hydra, Jovius, Lion, Mask, Owl, Rose, Smith, Warrior, Zodiac and archery — and a
            kingdom's own <strong>Custom Award</strong> or <strong>Custom Title</strong>,
            which print on a composed scroll rather than art of their own.
        </p>
        <p>
            Knighthoods, masterhoods, paragons, associate titles and noble titles are
            deliberately not printed here — they are left to a scribe — and officer
            positions are jobs rather than honours. If one of those is missing below, that
            is the reason, not a fault.
        </p>
        <p>
            <strong>Certificates print your current persona name.</strong> An award record
            keeps no persona of its own, so reprinting a grant from 2009 letters the name
            you go by today.
        </p>
    </div>

    <div class="rp-table-area">
        <?php if ($awards === []): ?>
            <div class="sc-empty">
                <p>None of your awards is one Scrollery prints.</p>
                <p><a href="<?= UIR ?>Player/profile/<?= $mundaneId ?>">Back to your profile</a></p>
            </div>
        <?php else: ?>
            <div class="sc-tools">
                <?php /* The hand. Here rather than only on the print page because this is
                         the list an officer works through, and the choice has to be made
                         BEFORE the links are followed — every View and Print href below is
                         rewritten to carry it. Same list and same remembered value as the
                         picker on the print page; see script/scrollery-hand.js. */ ?>
                <div class="sc-tool">
                    <label for="sc-face">Hand</label>
                    <select id="sc-face" class="adm-select">
                        <?php foreach (Scroll::Hands() as $handKey => $handDef): ?>
                        <option value="<?= scroll_e($handKey) ?>" data-face-family="<?= scroll_e($handDef['family']) ?>"<?= $handKey === Scroll::DEFAULT_HAND ? ' selected' : '' ?>><?= scroll_e($handDef['label']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php /* The ruled lines behind the citation, carried onto every View and
                         Print link below. Works for any award on this list — the rules
                         are in the layer all seventeen orders share. */ ?>
                <div class="sc-tool">
                    <label class="sc-check" for="sc-lines">Reason lines</label>
                    <input type="checkbox" id="sc-lines" checked>
                </div>
            </div>

            <table class="sc-table" id="sc-table">
                <thead>
                    <tr>
                        <?php /* Unchecked. "Print all of them" is not a safe default for
                                 an action that commits card stock, and one player here
                                 can hold 278. */ ?>
                        <th class="sc-check"><input type="checkbox" id="sc-all" aria-label="Select all matching"></th>
                        <th>Order</th>
                        <th>Rank</th>
                        <th>Awarded</th>
                        <th>Given by</th>
                        <th>Design</th>
                        <?php if ($hasVariants): ?><th>Version</th><?php endif; ?>
                        <th></th>
                    </tr>
                </thead>
                <tbody id="sc-rows">
                <?php foreach ($awards as $a): ?>
                    <?php
                    $awardsId = (int) $a['AwardsId'];
                    $revoked  = (int) ($a['Revoked'] ?? 0) === 1;
                    $variants = is_array($a['Variants'] ?? null) ? $a['Variants'] : [];
                    $rowUrl   = UIR . 'Scrollery/render&awards=' . $awardsId;
                    $named    = trim((string) ($a['Title'] ?? '')) !== '';
                    $label    = $displayTitle($a);
                    ?>
                    <tr data-sc-row data-id="<?= $awardsId ?>">
                        <td class="sc-check">
                            <?php if (!$revoked): ?>
                                <input type="checkbox" class="sc-pick" value="<?= $awardsId ?>"
                                       aria-label="Print <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
                            <?php endif; ?>
                        </td>
                        <?php /* data-order is DataTables' sort key. An award the record
                                 holds no name for has no place in an alphabet, so its key
                                 sorts after every name in A-to-Z. Dates are ISO from the
                                 database, so the raw value sorts as a date. */ ?>
                        <?php /* data-search keeps the filter on readable text: the
                                 string-typed column would otherwise search its markup,
                                 so "order" or "where" matched every row. */ ?>
                        <td data-order="<?= htmlspecialchars(($named ? '' : '~') . strtolower($label), ENT_QUOTES, 'UTF-8') ?>"
                            data-search="<?= htmlspecialchars(trim($label . ' ' . trim(($a['IssuerTitle'] ?? '') . ' ' . ($a['IssuerName'] ?? ''))), ENT_QUOTES, 'UTF-8') ?>">
                            <div class="sc-order<?= $named ? '' : ' sc-unnamed' ?>">
                                <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                            <?php if (!$named): ?>
                                <div class="sc-where">
                                    The record holds no name for this one, so the certificate
                                    prints a blank line to complete by hand.
                                </div>
                            <?php endif; ?>
                            <?php if (trim((string) ($a['IssuerName'] ?? '')) !== ''): ?>
                                <div class="sc-where">
                                    <?= htmlspecialchars(trim(($a['IssuerTitle'] ?? '') . ' ' . $a['IssuerName']), ENT_QUOTES, 'UTF-8') ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td data-order="<?= (int) $a['Rank'] ?>"><?= (int) $a['Rank'] > 0 ? (int) $a['Rank'] : '&mdash;' ?></td>
                        <td data-order="<?= htmlspecialchars((string) ($a['Date'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) ($a['PrettyDate'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td><?= htmlspecialchars((string) ($a['GivenBy'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <?php /* The two things this module prints, named apart. An order's
                                 own plates are fixed art; the composed scroll is a ground,
                                 a border and a numeral, and looks nothing like them. */ ?>
                        <td class="sc-design">
                            <?php if (!empty($a['HasOrderArt'])): ?>
                                <i class="fa-solid fa-palette" aria-hidden="true"></i> Order artwork
                            <?php else: ?>
                                <i class="fa-solid fa-scroll" aria-hidden="true"></i> Composed scroll
                            <?php endif; ?>
                        </td>
                        <?php if ($hasVariants): ?>
                            <td>
                                <?php if ($variants !== [] && !$revoked): ?>
                                    <?php /* The control this page never had. Scrollery/render
                                             reads &variant= and always has; without this the
                                             row could only ever reach $variants[0], so an Owl
                                             reprint was locked to parchment and a Battle
                                             reprint to arcane. */ ?>
                                    <label class="rp-sr-only" for="sc-variant-<?= $awardsId ?>">
                                        Version of <?= htmlspecialchars((string) $a['Title'], ENT_QUOTES, 'UTF-8') ?>
                                    </label>
                                    <select class="adm-select sc-variant" id="sc-variant-<?= $awardsId ?>"
                                            data-sc-variant>
                                        <?php foreach ($variants as $v): ?>
                                            <option value="<?= htmlspecialchars($v, ENT_QUOTES, 'UTF-8') ?>">
                                                <?= htmlspecialchars(ucfirst($v), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php else: ?>
                                    <span class="sc-where">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        <?php endif; ?>
                        <?php /* One row is one page, so the stake is small enough that Print
                                 keeps its single click — but View reaches the same URL
                                 WITHOUT print=1, so anyone who wants to check the heraldry,
                                 the pronoun or a blank reason line before committing paper
                                 can.

                                 A revoked award gets neither control. Model_Scrollery::
                                 printable_awards_for_player() already drops those rows, and
                                 the render route refuses them again by id — this is the
                                 third check, not the only one, and it is the one that keeps
                                 a dead button off the page if the list ever widens. */ ?>
                        <td class="sc-actions">
                            <?php if ($revoked): ?>
                                <span class="sc-where">Revoked</span>
                            <?php else: ?>
                                <a class="adm-btn adm-btn-ghost" data-sc-view
                                   href="<?= $rowUrl ?>" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-eye"></i> View
                                </a>
                                <a class="adm-btn adm-btn-primary" data-sc-print
                                   href="<?= $rowUrl ?>&print=1" target="_blank" rel="noopener">
                                    <i class="fa-solid fa-print"></i> Print
                                </a>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>

            <div class="sc-bar">
                <button type="button" class="adm-btn adm-btn-primary" id="sc-print-bulk" disabled>
                    <i class="fa-solid fa-print"></i> <span id="sc-print-label">Print selected</span>
                </button>
                <button type="button" class="adm-btn adm-btn-ghost" id="sc-select-all">Select all matching</button>
                <button type="button" class="adm-btn adm-btn-ghost" id="sc-select-none">Select none</button>
                <?php /* role="alert" because past the print cap it is the ONLY feedback the
                         button gives — it otherwise appears to do nothing at all. */ ?>
                <span class="sc-count" id="sc-selected-count" role="alert"></span>
            </div>
            <div class="sc-bar sc-batches" id="sc-batches" role="status"></div>

            <p class="sc-hint">
                Tick a row, then shift-click another to select everything between them.
                Printing opens a preview first &mdash; nothing reaches the printer until you
                choose Print there. Runs of more than <?= $chunk ?> open in batches of
                <?= $chunk ?>, one tab each: a full page of certificates is a large image and
                browsers stop rendering them past about that many.
            </p>
        <?php endif; ?>
    </div>

    <p class="sc-credit">
        Certificates derive from the Arcanyst Scroll Generator, originally created, along
        with its initial artwork, by Valory Fox (aka Ash). Flavour text credited in
        Arcanyst to Kisa (Shelly Alexandra).
    </p>
</div>

<?php if ($awards !== []): ?>
<?php /* revised.js carries the CRM's own themed confirm (window.orkConfirm) and its
         .ork-confirm-* styles are already in revised.css, which this page links.
         Native confirm() is not an option — it freezes the browser automation. */ ?>
<?php /* The ten display faces, so each <option> in the hand picker draws in the face
         it names. This page does not load scrollery.css and should not — see
         Scroll::HandSpecimenStyles(), which generates these from the same 'hands'
         data the picker reads.

         Inside a <template> so the rules are INERT until scrollery-hand.js adopts
         them on first contact with the control. A plain <style> here would declare
         the families, the options' own font-family would reference them, and Chrome
         would fetch all ten on page load — measured, ~290 KB on a page that is a
         list of awards and has no other use for them. */ ?>
<template id="sc-face-fonts"><style><?= Scroll::HandSpecimenStyles(HTTP_TEMPLATE . 'revised-frontend/style/fonts/') ?></style></template>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/scrollery-hand.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/scrollery-hand.js') ?>"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
(function () {
    'use strict';

    var UIR_SC   = <?= json_encode(UIR) ?>,
        MAX_RUN  = <?= $maxRun ?>,
        // The browser's own limit, not the server's. One certificate is a 2550x3300
        // plate that decodes to ~33.7 MB of RGBA, so a 50-certificate tab touches
        // 500-840 MB of bitmap and the print rasteriser gives up.
        CHUNK    = <?= $chunk ?>,
        // Above this many pages, "Print" stops being a click and starts being a
        // decision about a stack of card stock, so it asks first.
        CONFIRM_OVER = 20;

    var tbody   = document.getElementById('sc-rows'),
        all     = document.getElementById('sc-all'),
        btn     = document.getElementById('sc-print-bulk'),
        label   = document.getElementById('sc-print-label'),
        selAll  = document.getElementById('sc-select-all'),
        selNone = document.getElementById('sc-select-none'),
        batchBar = document.getElementById('sc-batches'),
        count   = document.getElementById('sc-selected-count');

    if (!tbody || !btn) { return; }

    // Captured before DataTables pages the table: it detaches the rows that are not
    // on the current page, and every row still needs its links kept in step.
    var rows    = Array.prototype.slice.call(tbody.querySelectorAll('[data-sc-row]'));

    function plural(n) { return n === 1 ? 'certificate' : 'certificates'; }

    /* ---- the row's own variant, and the two links that carry it --------- */

    /*
     * &variant= is the parameter Controller_Scrollery::render() has always read and
     * this page has never sent. Rewriting the row's own two hrefs is what makes the
     * choice reach the certificate; Scroll::BuildScroll() ignores an unknown value
     * and falls back to variants[0], so a stale option can only ever produce today's
     * behaviour rather than an error.
     */
    function variantOf(row) {
        var sel = row.querySelector('[data-sc-variant]');
        return sel ? sel.value : '';
    }

    /* The hand rides on every outgoing link rather than being read by the print page
       from storage, so that a link copied out of this table prints what the officer
       was looking at when they copied it. Hand.decorate() is a no-op when the module
       did not load. */
    function withHand(url) {
        var out = window.ScrolleryHand ? window.ScrolleryHand.decorate(url, window.ScrolleryHand.get()) : url;
        if (window.ScrolleryLines) { out = window.ScrolleryLines.decorate(out, window.ScrolleryLines.get()); }
        return out;
    }

    function syncRowLinks(row) {
        var v    = variantOf(row),
            base = withHand(UIR_SC + 'Scrollery/render&awards=' + row.dataset.id
                 + (v ? '&variant=' + encodeURIComponent(v) : '')),
            view  = row.querySelector('[data-sc-view]'),
            print = row.querySelector('[data-sc-print]');
        if (view)  { view.href  = base; }
        if (print) { print.href = base + '&print=1'; }
    }

    rows.forEach(function (row) {
        var sel = row.querySelector('[data-sc-variant]');
        if (sel) { sel.addEventListener('change', function () { syncRowLinks(row); }); }
    });

    /*
     * The hand picker. Nothing on THIS page is set in a certificate face — it is a
     * table — so unlike the print page and the composer there is nothing to
     * re-letter. All it does is rewrite where the links go, which is why every row
     * has to be re-synced when it changes rather than only the one being edited.
     */
    (function () {
        var faceEl = document.getElementById('sc-face');
        if (!faceEl || !window.ScrolleryHand) { return; }

        window.ScrolleryHand.init(<?= json_encode(array_keys(Scroll::Hands())) ?>, <?= json_encode(Scroll::DEFAULT_HAND) ?>);
        /* Same re-sync as the hand: every row's href is rebuilt, because both settings
           are carried by the link rather than read by the page it opens. */
        if (window.ScrolleryLines) {
            window.ScrolleryLines.bind(document.getElementById('sc-lines'), function () {
                rows.forEach(syncRowLinks);
            });
        }

        window.ScrolleryHand.bind(faceEl, function (face) {
            rows.forEach(syncRowLinks);

            /* The "open the rest one at a time" links, if a long run left some on the
               page, were built with the previous hand. batchUrl() reads the hand at
               call time, so anything built AFTER this is already right; these are the
               ones already in the DOM, and they are re-decorated in place rather than
               re-rendered because nothing here still knows what the batches were. */
            if (batchBar) {
                Array.prototype.forEach.call(batchBar.querySelectorAll('a[href]'), function (a) {
                    a.href = window.ScrolleryHand.decorate(a.href, face);
                });
            }
        });

        /* The rows were rendered server-side with no face on them, so bring them into
           line with the remembered hand before anything is clicked. */
        rows.forEach(syncRowLinks);
    }());

    /* ---- filter, sort, paging: DataTables ------------------------------ */

    /* Only the table's filter/sort/paging needs the CDN. If it did not load, the
       variant links, the hand and Print selected still work over the plain rows. */
    var hasDT   = !!(window.jQuery && jQuery.fn.DataTable),
        lastCol = hasDT ? jQuery('#sc-table thead th').length - 1 : 0,
        table   = !hasDT ? null : jQuery('#sc-table').DataTable({
            dom: 'lfrtip',
            autoWidth: false,
            pageLength: 50,
            // Newest first, as the record is read.
            order: [[3, 'desc']],
            // Order, Rank, Awarded and Given by sort; the checkbox, the design, the
            // version picker and the actions do not.
            columnDefs: [{ targets: [0, 5, lastCol].concat(<?= $hasVariants ? '[6]' : '[]' ?>), orderable: false }],
            language: {
                search: 'Filter:',
                searchPlaceholder: 'Order, year, who gave it',
                zeroRecords: 'Nothing on your record matches that filter.'
            }
        });

    /* Every row the filter keeps, across all pages, in the table's current order.
       "Select all matching" and shift-click work over this, so a selection never
       reaches through a filtered-out row. Without DataTables, every row matches. */
    function visibleBoxes() {
        if (!table) {
            return rows.map(function (r) { return r.querySelector('.sc-pick'); }).filter(Boolean);
        }
        return table.rows({ search: 'applied', order: 'applied' }).nodes().toArray().map(function (r) {
            return r.querySelector('.sc-pick');
        }).filter(Boolean);
    }

    function picks() {
        return visibleBoxes().filter(function (c) { return c.checked; });
    }

    // A filtered-out row's checkbox is still checked, and printing it would be a
    // surprise. Clear the ones the filter just took away.
    if (table) {
        table.on('search.dt', function () {
            var kept = visibleBoxes();
            rows.forEach(function (r) {
                var box = r.querySelector('.sc-pick');
                if (box && box.checked && kept.indexOf(box) === -1) { box.checked = false; }
            });
            sync();
        });
    }

    function sync() {
        var chosen = picks(),
            n      = chosen.length,
            boxes  = visibleBoxes();

        if (label) { label.textContent = n === 0 ? 'Print selected' : 'Print ' + n + ' ' + plural(n); }
        count.textContent = n > MAX_RUN
            ? n + ' selected — ' + MAX_RUN + ' max per print run'
            : (n === 0 ? '' : n + ' of ' + boxes.length + ' matching selected');
        count.classList.toggle('sc-warn', n > MAX_RUN);
        btn.disabled = n === 0;
        if (all) {
            all.checked       = n > 0 && n === boxes.length;
            all.indeterminate = n > 0 && n < boxes.length;
        }
    }

    function setAll(state) {
        visibleBoxes().forEach(function (c) { c.checked = state; });
        sync();
    }

    if (all)     { all.addEventListener('change', function () { setAll(all.checked); }); }
    if (selAll)  { selAll.addEventListener('click',  function () { setAll(true); }); }
    if (selNone) { selNone.addEventListener('click', function () { setAll(false); }); }

    // Shift-click selects the range between the last box clicked and this one, the
    // way every other checklist does — computed over what is VISIBLE, so a range does
    // not silently reach through a filtered-out row. `click`, not `change`: only a
    // click carries shiftKey, and a keyboard toggle falls through to the plain path.
    var lastId = null;
    tbody.addEventListener('click', function (e) {
        var box = e.target.closest ? e.target.closest('.sc-pick') : null;
        if (!box) { return; }
        var boxes = visibleBoxes(),
            i     = boxes.indexOf(box),
            last  = lastId === null ? -1 : boxes.map(function (b) { return b.value; }).indexOf(lastId);
        if (e.shiftKey && last > -1 && last !== i) {
            var from = Math.min(last, i), to = Math.max(last, i);
            for (var j = from; j <= to; j++) { boxes[j].checked = box.checked; }
        }
        lastId = box.value;
        sync();
    });
    tbody.addEventListener('change', function (e) {
        if (e.target && e.target.classList && e.target.classList.contains('sc-pick')) { sync(); }
    });

    /* ---- the run ------------------------------------------------------- */

    /*
     * render() takes ONE &variant= for the whole URL, because a variant names a plate
     * within an order rather than a property of an award row. A selection whose rows
     * disagree therefore cannot carry all of them.
     *
     * `lossy` is narrower than "they disagree", deliberately. Scroll::BuildScroll()
     * accepts a variant only if it is in the chosen order's OWN list and otherwise
     * falls back to that order's first plate — so a value one order does not recognise
     * costs the others nothing, and a row still sitting on its own first option has
     * nothing to lose either way. Warning on a plain "select all", where nobody chose
     * anything, would be crying wolf about a run that prints exactly what the page
     * shows. The warning fires only when a DELIBERATE choice is about to be dropped.
     */
    function variantState(chosen) {
        var seen = [], dirty = false;
        chosen.forEach(function (box) {
            var sel = box.closest('tr').querySelector('[data-sc-variant]');
            if (!sel) { return; }
            if (seen.indexOf(sel.value) === -1) { seen.push(sel.value); }
            if (sel.options.length && sel.value !== sel.options[0].value) { dirty = true; }
        });

        return { variant: seen.length === 1 ? seen[0] : '', lossy: seen.length > 1 && dirty };
    }

    function batchUrl(ids, variant) {
        return withHand(UIR_SC + 'Scrollery/render&awards=' + ids.join(',')
             + (variant ? '&variant=' + encodeURIComponent(variant) : ''));
    }

    function renderBatches(batches, variant) {
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
            a.href = batchUrl(b, variant);
            a.textContent = 'Batch ' + (i + 2) + ' — ' + b.length + ' ' + plural(b.length);
            batchBar.appendChild(a);
        });
    }

    function openRun(ids, variant) {
        var batches = [];
        for (var i = 0; i < ids.length; i += CHUNK) { batches.push(ids.slice(i, i + CHUNK)); }

        // The first batch opens on the click that asked for it. The rest do NOT open in
        // the same loop: every browser blocks all but the first popup from one gesture,
        // so a long run would silently produce one tab and swallow the others. They are
        // offered as links instead — one gesture each, nothing blocked.
        window.open(batchUrl(batches[0], variant), '_blank', 'noopener');
        renderBatches(batches, variant);
    }

    btn.addEventListener('click', function () {
        var chosen = picks(),
            ids    = chosen.map(function (c) { return c.value; });
        if (!ids.length) { return; }

        if (ids.length > MAX_RUN) {
            count.textContent = ids.length + ' selected — only ' + MAX_RUN +
                ' can be printed at once. Uncheck ' + (ids.length - MAX_RUN) + '.';
            count.classList.add('sc-warn');
            return;
        }
        count.classList.remove('sc-warn');

        var state   = variantState(chosen),
            variant = state.variant,
            n       = Math.ceil(ids.length / CHUNK),
            note    = state.lossy
                ? ' You have picked different versions in this selection, and one run can only'
                + ' carry one — so each prints its own default instead. Print them separately'
                + ' to keep the versions you chose.'
                : '';

        if ((ids.length > CONFIRM_OVER || state.lossy) && typeof window.orkConfirm === 'function') {
            window.orkConfirm(
                'This opens a preview of ' + ids.length + ' ' + plural(ids.length) +
                ' — one page each' +
                (n > 1 ? ', in ' + n + ' batches of up to ' + CHUNK + '. The first opens now and '
                       + 'the rest appear as links to open one at a time.'
                       : '. Nothing prints until you choose Print on that page.') + note,
                function () { openRun(ids, variant); },
                { title: 'Print ' + ids.length + ' ' + plural(ids.length) + '?',
                  okLabel: 'Open preview', danger: false }
            );
            return;
        }
        openRun(ids, variant);
    });

    rows.forEach(syncRowLinks);
    sync();
}());
</script>
<?php endif; ?>
