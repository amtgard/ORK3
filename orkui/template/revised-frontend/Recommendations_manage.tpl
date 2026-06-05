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
</script>
