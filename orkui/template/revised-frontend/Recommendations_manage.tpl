<?php if (!empty($Error)) { ?>
  <div class="rm-wrap"><div class="rm-error"><?= htmlspecialchars($Error) ?></div></div>
<?php return; }
  $backUrl = $ParkId > 0
    ? UIR . 'Park/index/' . (int)$ParkId
    : UIR . 'Kingdom/index/' . (int)$KingdomId;
?>
<style>
.rm-wrap {
    --rm-line: #d8d8d8;
    --rm-bg: #fff;
    --rm-bg2: #f6f6f6;
    --rm-fg: #222;
    --rm-muted: #777;
    --rm-accent: #2c5f8b;
    --rm-danger: #b03030;
    max-width: 100%;
    margin: 0 auto;
    padding: 0 12px 80px;
    box-sizing: border-box;
    color: var(--rm-fg);
}
@media (prefers-color-scheme: dark) {
    .rm-wrap {
        --rm-line: #3a3f47;
        --rm-bg: #1e2127;
        --rm-bg2: #23262d;
        --rm-fg: #e6e6e6;
        --rm-muted: #9aa0a8;
        --rm-accent: #6fb0e6;
        --rm-danger: #e07070;
    }
}
html[data-theme="dark"] .rm-wrap {
    --rm-line: #3a3f47;
    --rm-bg: #1e2127;
    --rm-bg2: #23262d;
    --rm-fg: #e6e6e6;
    --rm-muted: #9aa0a8;
    --rm-accent: #6fb0e6;
    --rm-danger: #e07070;
}

/* Hero */
.rm-hero { padding: 14px 4px 10px; }
.rm-back {
    display: inline-block;
    font-size: 13px;
    color: var(--rm-accent);
    text-decoration: none;
    margin-bottom: 6px;
}
.rm-back:hover { text-decoration: underline; }
.rm-title {
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 0;
    text-shadow: none;
    margin: 0;
    font-size: 24px;
    font-weight: 700;
    color: var(--rm-fg);
}
.rm-sub { font-size: 13px; color: var(--rm-muted); margin-top: 2px; }

/* Filter bar */
.rm-filterbar {
    position: sticky;
    top: 0;
    z-index: 5;
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
    align-items: center;
    padding: 8px 4px;
    background: var(--rm-bg);
    border-bottom: 1px solid var(--rm-line);
}
.rm-search, .rm-fsel {
    font-size: 13px;
    padding: 5px 8px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-search { min-width: 200px; }
.rm-search::placeholder { color: var(--rm-muted); }
.rm-chips { display: flex; flex-wrap: wrap; gap: 6px; }
.rm-chip {
    cursor: pointer;
    font-size: 12px;
    padding: 3px 8px;
    border-radius: 12px;
    background: var(--rm-bg2);
    border: 1px solid var(--rm-line);
    color: var(--rm-fg);
}
.rm-chip:hover { border-color: var(--rm-accent); }

/* Grid */
.rm-gridwrap { overflow-x: auto; }
.rm-grid {
    border-collapse: collapse;
    width: 100%;
    font-size: 13px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-grid th, .rm-grid td {
    border: 1px solid var(--rm-line);
    padding: 4px 8px;
    text-align: left;
    vertical-align: top;
}
.rm-grid thead th {
    position: sticky;
    top: 41px;
    z-index: 4;
    background: var(--rm-bg2);
    font-weight: 700;
    white-space: nowrap;
}
.rm-row:nth-child(even) { background: var(--rm-bg2); }
.rm-row:hover { background: rgba(127, 127, 127, 0.10); }

/* Sticky recipient column */
.rm-col-recip { position: sticky; left: 0; z-index: 1; background: var(--rm-bg); }
.rm-row:nth-child(even) .rm-col-recip { background: var(--rm-bg2); }
thead .rm-col-recip { z-index: 6; background: var(--rm-bg2); }

/* tabular numbers */
.rm-date, .rm-age, .rm-rank, .rm-supp-chip { font-variant-numeric: tabular-nums; }

.rm-col-sel { width: 28px; text-align: center; }
thead .rm-col-sel { text-align: center; }
.rm-col-act { white-space: nowrap; }

.rm-sortable { cursor: pointer; user-select: none; }
.rm-sort-asc::after { content: " \25B2"; font-size: 9px; }
.rm-sort-desc::after { content: " \25BC"; font-size: 9px; }

/* Recipient cell */
.rm-col-recip a { color: var(--rm-accent); text-decoration: none; font-weight: 600; }
.rm-col-recip a:hover { text-decoration: underline; }
.rm-park {
    display: inline-block;
    margin-left: 4px;
    font-size: 11px;
    color: var(--rm-muted);
    border: 1px solid var(--rm-line);
    border-radius: 3px;
    padding: 0 4px;
}

/* Award cell */
.rm-rank {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    color: var(--rm-muted);
}
.rm-rank.rm-nonladder { font-style: italic; }
.rm-badge {
    display: inline-block;
    margin-left: 6px;
    font-size: 11px;
    padding: 0 5px;
    border-radius: 3px;
    border: 1px solid var(--rm-line);
}
.rm-badge-has { color: #8a6d00; background: rgba(240, 200, 0, 0.14); border-color: rgba(240, 200, 0, 0.4); }
.rm-badge-below { color: var(--rm-danger); background: rgba(176, 48, 48, 0.10); border-color: rgba(176, 48, 48, 0.35); }
html[data-theme="dark"] .rm-badge-has { color: #e0c860; }

/* Recommended cell */
.rm-by { display: block; }
.rm-date { display: inline-block; color: var(--rm-muted); }
.rm-age { display: inline-block; margin-left: 6px; color: var(--rm-muted); font-size: 11px; }

/* Reason + support */
.rm-empty { color: var(--rm-muted); }
.rm-reason-trunc {
    display: inline-block;
    max-width: 320px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    vertical-align: bottom;
}
.rm-expand-reason, .rm-supp-chip {
    cursor: pointer;
    font-size: 12px;
    padding: 1px 6px;
    margin-left: 4px;
    border: 1px solid var(--rm-line);
    border-radius: 3px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-expand-reason:hover, .rm-supp-chip:hover { border-color: var(--rm-accent); }

/* Court badge */
.rm-courtbadge {
    display: inline-block;
    font-size: 12px;
    color: #fff;
    background: var(--rm-accent);
    border-radius: 3px;
    padding: 1px 6px;
    text-decoration: none;
}
.rm-courtbadge:hover { opacity: 0.9; }
.rm-courtmore { font-weight: 700; }

/* Action buttons */
.rm-act {
    cursor: pointer;
    font-size: 14px;
    line-height: 1;
    padding: 3px 5px;
    margin: 0 1px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-act:hover { border-color: var(--rm-accent); background: var(--rm-bg2); }
.rm-act-dismiss:hover { border-color: var(--rm-danger); }

/* Footer */
.rm-foot {
    padding: 8px 4px;
    font-size: 12px;
    color: var(--rm-muted);
    border-top: 1px solid var(--rm-line);
}

/* Detail rows (Task 5) */
.rm-detailrow td { background: var(--rm-bg2); }
.rm-detailrow .rm-col-recip { background: var(--rm-bg2); }
.rm-seclist {
    margin: 0;
    padding: 4px 0 4px 8px;
    list-style: none;
    font-size: 13px;
}
.rm-seclist li { padding: 1px 0; }
.rm-reason-full {
    white-space: pre-wrap;
    font-size: 13px;
    padding: 2px 0;
}

/* data-tip tooltips (no native title) */
[data-tip] { position: relative; }
[data-tip]:hover::after {
    content: attr(data-tip);
    position: absolute;
    left: 50%;
    bottom: calc(100% + 4px);
    transform: translateX(-50%);
    white-space: nowrap;
    background: #222;
    color: #fff;
    font-size: 11px;
    padding: 3px 6px;
    border-radius: 4px;
    z-index: 50;
    pointer-events: none;
}
html[data-theme="dark"] [data-tip]:hover::after { background: #000; }

/* Bulk action bar (Task 7) */
.rm-bulkbar {
    position: sticky;
    bottom: 0;
    z-index: 6;
    display: flex;
    gap: 8px;
    align-items: center;
    flex-wrap: wrap;
    padding: 8px 10px;
    margin-top: 6px;
    background: var(--rm-bg2);
    border: 1px solid var(--rm-line);
    border-radius: 6px;
    box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.12);
}
.rm-bulkbar[hidden] { display: none; }
#rm-bulklabel { font-size: 13px; font-weight: 600; color: var(--rm-fg); margin-right: 4px; }
.rm-bulk {
    cursor: pointer;
    font-size: 13px;
    padding: 5px 10px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-bulk:hover { border-color: var(--rm-accent); background: var(--rm-bg2); }
.rm-bulk-dismiss:hover { border-color: var(--rm-danger); color: var(--rm-danger); }

.rm-error {
    padding: 16px;
    margin: 24px auto;
    max-width: 480px;
    border: 1px solid var(--rm-danger, #b03030);
    border-radius: 6px;
    color: #b03030;
    background: rgba(176, 48, 48, 0.08);
    text-align: center;
}
</style>

<div class="rm-wrap">
  <div class="rm-hero">
    <a class="rm-back" href="<?= htmlspecialchars($backUrl) ?>">&larr; Back to <?= htmlspecialchars($LocationName) ?></a>
    <h1 class="rm-title">Recommendations Manager</h1>
    <div class="rm-sub"><?= htmlspecialchars($LocationName) ?> &middot; <?= count($Recommendations) ?> pending</div>
  </div>

  <div class="rm-filterbar" id="rm-filterbar">
    <input type="search" id="rm-search" class="rm-search" placeholder="Search recipient&hellip;" autocomplete="off">
    <select id="rm-filter-elig" class="rm-fsel">
      <option value="all">All eligibility</option>
      <option value="below">Below recommended</option>
      <option value="ator">At/above recommended</option>
      <option value="nonladder">Non-ladder</option>
      <option value="snoozed">Snoozed</option>
    </select>
    <select id="rm-filter-court" class="rm-fsel">
      <option value="all">Any court status</option>
      <option value="none">Not on a court</option>
      <option value="any">On any court</option>
      <?php foreach ($Courts as $c) { ?>
        <option value="court:<?= (int)$c['CourtId'] ?>">On: <?= htmlspecialchars($c['Name']) ?></option>
      <?php } ?>
    </select>
    <?php if ($ParkId === 0 && count($Parks)) { ?>
    <select id="rm-filter-park" class="rm-fsel">
      <option value="all">All parks</option>
      <?php foreach ($Parks as $pid => $p) { ?>
        <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['Name']) ?></option>
      <?php } ?>
    </select>
    <?php } ?>
    <div id="rm-chips" class="rm-chips"></div>
  </div>

  <div class="rm-gridwrap">
  <table class="rm-grid" id="rm-grid">
    <thead>
      <tr>
        <th class="rm-col-sel"><input type="checkbox" id="rm-selall"></th>
        <th class="rm-col-recip rm-sortable" data-sort="recip">Recipient</th>
        <th class="rm-col-award rm-sortable" data-sort="award">Award</th>
        <th class="rm-col-rec rm-sortable" data-sort="date">Recommended</th>
        <th class="rm-col-reason">Reason</th>
        <th class="rm-col-supp rm-sortable" data-sort="supp">Support</th>
        <th class="rm-col-court">Court</th>
        <th class="rm-col-act">Actions</th>
      </tr>
    </thead>
    <tbody id="rm-tbody">
    <?php foreach ($Recommendations as $rec) {
        $rid     = (int)$rec['RecommendationsId'];
        $isLad   = ((int)($rec['Rank'] ?? 0)) > 0;
        $cur     = isset($rec['CurrentRank']) ? (int)$rec['CurrentRank'] : null;
        $elig    = !$isLad ? 'nonladder' : (($cur !== null && $cur < (int)$rec['Rank']) ? 'below' : 'ator');
        $snoozed = !empty($rec['IsSnoozed']) ? 1 : 0;
        $courts  = $CourtMap[$rid] ?? [];
        $seconds = $rec['Seconds'] ?? [];
        $pid     = (int)($rec['ParkId'] ?? 0);
        $abbrev  = $Parks[$pid]['Abbrev'] ?? '';
        $courtJson = htmlspecialchars(json_encode($courts), ENT_QUOTES);
        $secondsJson = htmlspecialchars(json_encode(array_map(function ($s) {
            return ['Name' => $s['SupporterName'] ?? '', 'Notes' => $s['Notes'] ?? ''];
        }, $seconds)), ENT_QUOTES);
        // Payload for Grant Now / Add to Court (Tasks 8-9)
        $recPayload = htmlspecialchars(json_encode([
            'RecommendationsId' => $rid,
            'MundaneId'         => (int)$rec['MundaneId'],
            'Persona'           => $rec['Persona'] ?? '',
            'KingdomAwardId'    => (int)$rec['KingdomAwardId'],
            'Rank'              => (int)($rec['Rank'] ?? 0),
            'Reason'            => $rec['Reason'] ?? '',
        ]), ENT_QUOTES);
    ?>
      <tr class="rm-row" data-rec-id="<?= $rid ?>" data-elig="<?= $elig ?>" data-snoozed="<?= $snoozed ?>"
          data-park="<?= $pid ?>" data-courts='<?= $courtJson ?>'
          data-recip="<?= htmlspecialchars(strtolower($rec['Persona'] ?? ''), ENT_QUOTES) ?>"
          data-award="<?= htmlspecialchars(strtolower($rec['AwardName'] ?? ''), ENT_QUOTES) ?>"
          data-date="<?= htmlspecialchars($rec['DateRecommended'] ?? '', ENT_QUOTES) ?>"
          data-supp="<?= (int)($rec['SecondsCount'] ?? count($seconds)) ?>"
          data-rec='<?= $recPayload ?>'
          data-seconds='<?= $secondsJson ?>'>
        <td class="rm-col-sel"><input type="checkbox" class="rm-rowsel"></td>
        <td class="rm-col-recip">
          <a href="<?= UIR ?>Playernew/index/<?= (int)$rec['MundaneId'] ?>"><?= htmlspecialchars($rec['Persona'] ?? '') ?></a>
          <?php if ($abbrev) { ?><span class="rm-park"><?= htmlspecialchars($abbrev) ?></span><?php } ?>
        </td>
        <td class="rm-col-award">
          <?= htmlspecialchars($rec['AwardName'] ?? '') ?>
          <?php if ($isLad) { ?><span class="rm-rank">Rank <?= (int)$rec['Rank'] ?></span><?php } else { ?><span class="rm-rank rm-nonladder">non-ladder</span><?php } ?>
          <?php if (!empty($rec['AlreadyHas'])) { ?><span class="rm-badge rm-badge-has">already has</span><?php } ?>
          <?php if ($elig === 'below') { ?><span class="rm-badge rm-badge-below">below rec.</span><?php } ?>
        </td>
        <td class="rm-col-rec">
          <span class="rm-by"><?= htmlspecialchars($rec['RecommendedByName'] ?? (!empty($rec['IsAnonymous']) ? 'Anonymous' : '')) ?></span>
          <span class="rm-date"><?= htmlspecialchars($rec['DateRecommended'] ?? '') ?></span>
          <span class="rm-age"><?= (int)($rec['AgeDays'] ?? 0) ?>d</span>
        </td>
        <td class="rm-col-reason">
          <?php $reason = trim($rec['Reason'] ?? ''); if ($reason === '') { ?>
            <span class="rm-empty">&mdash;</span>
          <?php } else { ?>
            <span class="rm-reason-trunc"><?= htmlspecialchars($reason) ?></span>
            <button type="button" class="rm-expand-reason" data-tip="Show full reason">&#9656;</button>
          <?php } ?>
        </td>
        <td class="rm-col-supp">
          <?php $sc = (int)($rec['SecondsCount'] ?? count($seconds)); if ($sc > 0) { ?>
            <button type="button" class="rm-supp-chip" data-tip="Show seconds">+<?= $sc ?> &#9656;</button>
          <?php } else { ?><span class="rm-empty">0</span><?php } ?>
        </td>
        <td class="rm-col-court">
          <?php if (count($courts)) { $c0 = $courts[0]; ?>
            <a class="rm-courtbadge" href="<?= UIR ?>Court/detail/<?= (int)$c0['CourtId'] ?>"><?= htmlspecialchars($c0['Name']) ?><?php if (count($courts) > 1) { ?> <span class="rm-courtmore">+<?= count($courts) - 1 ?></span><?php } ?></a>
          <?php } else { ?><span class="rm-empty">&mdash;</span><?php } ?>
        </td>
        <td class="rm-col-act">
          <button type="button" class="rm-act rm-act-grant"  data-tip="Grant now">&#9889;</button>
          <button type="button" class="rm-act rm-act-court"  data-tip="Add to court">&#65291;</button>
          <button type="button" class="rm-act rm-act-snooze" data-tip="<?= $snoozed ? 'Unsnooze' : 'Snooze' ?>"><?= $snoozed ? '&#128276;' : '&#128164;' ?></button>
          <button type="button" class="rm-act rm-act-dismiss" data-tip="Dismiss">&#10005;</button>
        </td>
      </tr>
    <?php } ?>
    </tbody>
  </table>
  </div>
  <div class="rm-foot"><span id="rm-count"><?= count($Recommendations) ?></span> shown &middot; <span id="rm-selcount">0</span> selected</div>

  <div class="rm-bulkbar" id="rm-bulkbar" hidden>
    <span id="rm-bulklabel">0 selected</span>
    <button type="button" class="rm-bulk rm-bulk-court">Add to Court</button>
    <button type="button" class="rm-bulk rm-bulk-snooze">Snooze</button>
    <button type="button" class="rm-bulk rm-bulk-dismiss">Dismiss</button>
    <button type="button" class="rm-bulk rm-bulk-clear">Clear</button>
  </div>
</div>

<script>
// HTML-escape helper for JS-built markup.
function rmEsc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null) ? '' : String(s);
    return d.innerHTML;
}

// Insert (or toggle off) an inline detail row directly after `tr`.
function rmInsertDetail(tr, html, cls) {
    var next = tr.nextElementSibling;
    if (next && next.classList.contains(cls)) { next.remove(); return; } // toggle off
    // If a different detail row is open, replace it.
    if (next && next.classList.contains('rm-detailrow')) { next.remove(); }
    var dr = document.createElement('tr');
    dr.className = 'rm-detailrow ' + cls;
    dr.innerHTML = '<td></td><td colspan="7">' + html + '</td>';
    tr.parentNode.insertBefore(dr, tr.nextSibling);
}

document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var chip = e.target.closest('.rm-supp-chip');
    if (chip) {
        var tr = chip.closest('tr');
        var secs = [];
        try { secs = JSON.parse(tr.getAttribute('data-seconds') || '[]'); } catch (x) {}
        var html = '<ul class="rm-seclist">' + secs.map(function (s) {
            var note = s.Notes ? rmEsc(s.Notes) : '<em class="rm-empty">(no note)</em>';
            return '<li>↳ ' + rmEsc(s.Name) + ' — ' + note + '</li>';
        }).join('') + '</ul>';
        rmInsertDetail(tr, html, 'rm-detail-supp');
        return;
    }
    var rex = e.target.closest('.rm-expand-reason');
    if (rex) {
        var tr2 = rex.closest('tr');
        var full = tr2.querySelector('.rm-reason-trunc');
        rmInsertDetail(tr2, '<div class="rm-reason-full">' + rmEsc(full ? full.textContent : '') + '</div>', 'rm-detail-reason');
    }
});

/* ---------- Task 6: filtering, sorting, chips, counts ---------- */
var RM = { rows: function () { return Array.from(document.querySelectorAll('#rm-tbody .rm-row')); } };

function rmApplyFilters() {
    var q       = (document.getElementById('rm-search').value || '').trim().toLowerCase();
    var elig    = document.getElementById('rm-filter-elig').value;
    var court   = document.getElementById('rm-filter-court').value;
    var parkSel = document.getElementById('rm-filter-park');
    var park    = parkSel ? parkSel.value : 'all';
    var shown = 0;
    RM.rows().forEach(function (tr) {
        var ok = true;
        if (q && tr.getAttribute('data-recip').indexOf(q) === -1) ok = false;
        if (ok && elig !== 'all') {
            if (elig === 'snoozed') ok = tr.getAttribute('data-snoozed') === '1';
            else ok = tr.getAttribute('data-elig') === elig;
        }
        if (ok && court !== 'all') {
            var courts = []; try { courts = JSON.parse(tr.getAttribute('data-courts') || '[]'); } catch (x) {}
            if (court === 'none') ok = courts.length === 0;
            else if (court === 'any') ok = courts.length > 0;
            else if (court.indexOf('court:') === 0) {
                var cid = parseInt(court.slice(6), 10);
                ok = courts.some(function (c) { return c.CourtId === cid; });
            }
        }
        if (ok && park !== 'all') ok = tr.getAttribute('data-park') === park;
        // hide any open detail row belonging to a now-hidden parent
        tr.style.display = ok ? '' : 'none';
        var dr = tr.nextElementSibling;
        if (dr && dr.classList.contains('rm-detailrow')) dr.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    document.getElementById('rm-count').textContent = shown;
    rmRenderChips(q, elig, court, park);
    rmUpdateSelCount();
}

function rmRenderChips(q, elig, court, park) {
    var chips = [];
    if (q) chips.push(['search', '“' + q + '”']);
    if (elig !== 'all') chips.push(['elig', document.getElementById('rm-filter-elig').selectedOptions[0].text]);
    if (court !== 'all') chips.push(['court', document.getElementById('rm-filter-court').selectedOptions[0].text]);
    var ps = document.getElementById('rm-filter-park');
    if (ps && park !== 'all') chips.push(['park', ps.selectedOptions[0].text]);
    document.getElementById('rm-chips').innerHTML = chips.map(function (c) {
        return '<span class="rm-chip" data-clear="' + c[0] + '">' + rmEsc(c[1]) + ' ✕</span>';
    }).join('');
}

['rm-search', 'rm-filter-elig', 'rm-filter-court', 'rm-filter-park'].forEach(function (idv) {
    var el = document.getElementById(idv); if (el) el.addEventListener('input', rmApplyFilters);
});
document.getElementById('rm-chips').addEventListener('click', function (e) {
    var chip = e.target.closest('.rm-chip'); if (!chip) return;
    var k = chip.getAttribute('data-clear');
    if (k === 'search') document.getElementById('rm-search').value = '';
    if (k === 'elig')   document.getElementById('rm-filter-elig').value = 'all';
    if (k === 'court')  document.getElementById('rm-filter-court').value = 'all';
    if (k === 'park' && document.getElementById('rm-filter-park')) document.getElementById('rm-filter-park').value = 'all';
    rmApplyFilters();
});

var rmSortState = { key: 'date', dir: 1 };
function rmSort(key) {
    rmSortState.dir = (rmSortState.key === key) ? -rmSortState.dir : 1;
    rmSortState.key = key;
    var tbody = document.getElementById('rm-tbody');
    var rows = RM.rows();
    rows.sort(function (a, b) {
        var va, vb;
        if (key === 'supp') { va = +a.getAttribute('data-supp'); vb = +b.getAttribute('data-supp'); }
        else if (key === 'date') { va = a.getAttribute('data-date'); vb = b.getAttribute('data-date'); }
        else { va = a.getAttribute('data-' + key); vb = b.getAttribute('data-' + key); }
        if (va < vb) return -1 * rmSortState.dir;
        if (va > vb) return  1 * rmSortState.dir;
        return 0;
    });
    rows.forEach(function (tr) {
        var dr = tr.nextElementSibling && tr.nextElementSibling.classList.contains('rm-detailrow') ? tr.nextElementSibling : null;
        tbody.appendChild(tr); if (dr) tbody.appendChild(dr);
    });
    document.querySelectorAll('.rm-sortable').forEach(function (th) { th.classList.remove('rm-sort-asc', 'rm-sort-desc'); });
    var thEl = document.querySelector('.rm-sortable[data-sort="' + key + '"]');
    if (thEl) thEl.classList.add(rmSortState.dir === 1 ? 'rm-sort-asc' : 'rm-sort-desc');
}
document.querySelectorAll('.rm-sortable').forEach(function (th) {
    th.addEventListener('click', function () { rmSort(th.getAttribute('data-sort')); });
});

/* ---------- Task 7: selection + bulk bar ---------- */
var rmLastIdx = null;
function rmVisibleRows() { return RM.rows().filter(function (tr) { return tr.style.display !== 'none'; }); }
function rmSelected() { return RM.rows().filter(function (tr) { return tr.querySelector('.rm-rowsel').checked; }); }
function rmUpdateSelCount() {
    var n = rmSelected().length;
    document.getElementById('rm-selcount').textContent = n;
    var bar = document.getElementById('rm-bulkbar');
    bar.hidden = n === 0;
    document.getElementById('rm-bulklabel').textContent = n + ' selected';
}
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var cb = e.target.closest('.rm-rowsel'); if (!cb) return;
    var vis = rmVisibleRows();
    var idx = vis.indexOf(cb.closest('tr'));
    if (e.shiftKey && rmLastIdx !== null) {
        var lo = Math.min(idx, rmLastIdx), hi = Math.max(idx, rmLastIdx);
        for (var i = lo; i <= hi; i++) vis[i].querySelector('.rm-rowsel').checked = cb.checked;
    }
    rmLastIdx = idx;
    rmUpdateSelCount();
});
document.getElementById('rm-selall').addEventListener('change', function () {
    rmVisibleRows().forEach(function (tr) { tr.querySelector('.rm-rowsel').checked = this.checked; }, this);
    rmUpdateSelCount();
});
document.querySelector('.rm-bulk-clear').addEventListener('click', function () {
    RM.rows().forEach(function (tr) { tr.querySelector('.rm-rowsel').checked = false; });
    document.getElementById('rm-selall').checked = false;
    rmUpdateSelCount();
});

// Initial: default sort (oldest first) + sync counts/chips.
rmSort('date');
rmApplyFilters();
</script>
