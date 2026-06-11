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
    top: 48px; /* below the app's 48px fixed top nav */
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
/* NOTE: no overflow here — an overflow context would scope the sticky thead to
   this wrapper (which never scrolls vertically) and break the frozen header on
   window scroll. The table is full-width; very narrow viewports scroll the page. */
.rm-gridwrap { }
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
    top: 94px; /* 48px fixed nav + 46px sticky filter bar */
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
    /* !important + fixed steel-blue bg: the app's global `a` link color
       otherwise wins over this class, rendering light-blue-on-light-blue. */
    color: #fff !important;
    background: #2c5f8b;
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

/* Toast (Task 8) */
.rm-toast {
    position: fixed;
    bottom: 18px;
    right: 18px;
    z-index: 9999;
    max-width: 320px;
    padding: 10px 14px;
    font-size: 13px;
    color: #fff;
    background: #2c3a4a;
    border: 1px solid rgba(255, 255, 255, 0.12);
    border-left: 4px solid var(--rm-accent, #2c5f8b);
    border-radius: 5px;
    box-shadow: 0 4px 14px rgba(0, 0, 0, 0.3);
    opacity: 1;
    transition: opacity 0.4s ease;
}
.rm-toast-err {
    background: #4a2c2c;
    border-left-color: var(--rm-danger, #b03030);
}
.rm-toast-out { opacity: 0; }
@media (prefers-color-scheme: dark) {
    .rm-toast { background: #2a2f37; }
    .rm-toast-err { background: #3a2424; }
}
html[data-theme="dark"] .rm-toast { background: #2a2f37; }
html[data-theme="dark"] .rm-toast-err { background: #3a2424; }

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

/* Add-to-Court modal (Task 10) */
.rm-modal-overlay {
    position: fixed;
    inset: 0;
    z-index: 9998;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 20px;
    background: rgba(0, 0, 0, 0.45);
}
.rm-modal-overlay[hidden] { display: none; }
.rm-modal {
    --rm-line: #d8d8d8;
    --rm-bg: #fff;
    --rm-bg2: #f6f6f6;
    --rm-fg: #222;
    --rm-muted: #777;
    --rm-accent: #2c5f8b;
    width: 100%;
    max-width: 440px;
    background: var(--rm-bg);
    color: var(--rm-fg);
    border: 1px solid var(--rm-line);
    border-radius: 8px;
    box-shadow: 0 10px 40px rgba(0, 0, 0, 0.35);
    padding: 18px 20px 16px;
    box-sizing: border-box;
}
@media (prefers-color-scheme: dark) {
    .rm-modal {
        --rm-line: #3a3f47;
        --rm-bg: #23262d;
        --rm-bg2: #1e2127;
        --rm-fg: #e6e6e6;
        --rm-muted: #9aa0a8;
        --rm-accent: #6fb0e6;
    }
}
html[data-theme="dark"] .rm-modal {
    --rm-line: #3a3f47;
    --rm-bg: #23262d;
    --rm-bg2: #1e2127;
    --rm-fg: #e6e6e6;
    --rm-muted: #9aa0a8;
    --rm-accent: #6fb0e6;
}
.rm-modal-title {
    background: transparent;
    border: none;
    padding: 0;
    border-radius: 0;
    text-shadow: none;
    margin: 0 0 4px;
    font-size: 18px;
    font-weight: 700;
    color: var(--rm-fg);
}
.rm-modal-sub { font-size: 13px; color: var(--rm-muted); margin-bottom: 12px; }
.rm-modal-modes {
    display: flex;
    gap: 16px;
    margin-bottom: 12px;
    font-size: 13px;
    color: var(--rm-fg);
}
.rm-modal-modes label { cursor: pointer; }
#rm-court-existing, #rm-court-new { margin-bottom: 12px; }
.rm-modal .rm-fsel, .rm-modal .rm-input {
    width: 100%;
    box-sizing: border-box;
    font-size: 13px;
    padding: 7px 9px;
    border: 1px solid var(--rm-line);
    border-radius: 4px;
    background: var(--rm-bg);
    color: var(--rm-fg);
}
.rm-modal .rm-input { margin-bottom: 8px; }
.rm-modal .rm-input:last-child { margin-bottom: 0; }
.rm-modal .rm-input::placeholder { color: var(--rm-muted); }
.rm-modal .rm-empty { font-size: 13px; }
.rm-modal-actions {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
    margin-top: 4px;
}
.rm-modal-actions-stack {
    flex-direction: column;
    align-items: stretch;
}
.rm-modal-actions-stack .rm-btn { text-align: center; }
.rm-btn {
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    padding: 7px 16px;
    border-radius: 4px;
    border: 1px solid var(--rm-line);
}
.rm-btn-ghost {
    background: transparent;
    color: var(--rm-fg);
}
.rm-btn-ghost:hover { background: var(--rm-bg2); border-color: var(--rm-accent); }
.rm-btn-primary {
    background: var(--rm-accent);
    border-color: var(--rm-accent);
    color: #fff;
}
.rm-btn-primary:hover { opacity: 0.92; }
.rm-btn-primary:disabled { opacity: 0.55; cursor: default; }
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

  <!-- Task 10: Add-to-Court modal -->
  <div class="rm-modal-overlay" id="rm-court-overlay" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title">Add to Court</h2>
      <div class="rm-modal-sub" id="rm-court-sub"></div>
      <div class="rm-modal-modes">
        <label><input type="radio" name="rm-court-mode" value="existing" checked> Existing court</label>
        <label><input type="radio" name="rm-court-mode" value="new"> Create new court</label>
      </div>
      <div id="rm-court-existing">
        <select id="rm-court-select" class="rm-fsel">
          <?php foreach ($Courts as $c) { ?>
            <option value="<?= (int)$c['CourtId'] ?>"><?= htmlspecialchars($c['Name']) ?><?= !empty($c['CourtDate']) ? ' &mdash; ' . htmlspecialchars($c['CourtDate']) : '' ?> (<?= htmlspecialchars($c['Status']) ?>)</option>
          <?php } ?>
        </select>
        <?php if (!count($Courts)) { ?><div class="rm-empty">No courts yet &mdash; create one.</div><?php } ?>
      </div>
      <div id="rm-court-new" hidden>
        <input type="text" id="rm-court-name" class="rm-input" placeholder="Court name" maxlength="100">
        <input type="text" id="rm-court-date" class="rm-input" placeholder="Court date (optional)">
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-court-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-court-submit">Add</button>
      </div>
    </div>
  </div>

  <!-- Grant Now when the rec is already on a court: let the officer decide what
       happens to the planned court award (avoids a silent double-grant). -->
  <div class="rm-modal-overlay" id="rm-grantcourt-overlay" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title">Already on a court</h2>
      <div class="rm-modal-sub" id="rm-grantcourt-sub"></div>
      <div class="rm-modal-actions rm-modal-actions-stack">
        <button type="button" class="rm-btn rm-btn-primary" id="rm-gc-remove">Grant &amp; Remove from Court</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-gc-leave">Grant &amp; Leave on Court</button>
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-gc-back">Go Back</button>
      </div>
    </div>
  </div>
</div>

<script>
window.RmConfig = {
  uir: '<?= UIR ?>',
  kingdomId: <?= (int)$KingdomId ?>,
  parkId: <?= (int)$ParkId ?>,
  context: '<?= $Context === 'park' ? 'park' : 'kingdom' ?>',
  userId: <?= (int)$Uid ?>
};
</script>

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

/* ---------- Task 8: snooze/dismiss (row + bulk), toast, config ---------- */
function rmToast(msg, isErr) {
    var t = document.createElement('div');
    t.className = 'rm-toast' + (isErr ? ' rm-toast-err' : '');
    t.textContent = msg;
    document.body.appendChild(t);
    setTimeout(function () { t.classList.add('rm-toast-out'); setTimeout(function () { t.remove(); }, 400); }, 2600);
}
// Build the snooze/dismiss endpoint base for the current scope.
function rmRecAjaxBase(action) {
    if (RmConfig.context === 'park')
        return RmConfig.uir + 'ParkAjax/park/' + RmConfig.parkId + '/' + action;
    return RmConfig.uir + 'KingdomAjax/kingdom/' + RmConfig.kingdomId + '/' + action;
}
function rmPost(url, fd) {
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) { return r.json(); });
}

// Remove a row (and any open detail row) then re-sync filters/counts.
function rmRemoveRow(tr) {
    var dr = tr.nextElementSibling;
    if (dr && dr.classList.contains('rm-detailrow')) dr.remove();
    tr.remove();
    rmApplyFilters();
}

// Per-row Snooze + Dismiss (third tbody click handler; early-returns on non-match).
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var sn = e.target.closest('.rm-act-snooze');
    if (sn) {
        var tr = sn.closest('tr');
        var snoozed = tr.getAttribute('data-snoozed') === '1';
        var action = snoozed ? 'unsnoozerecommendation' : 'snoozerecommendation';
        var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
        rmPost(rmRecAjaxBase(action), fd).then(function (j) {
            if (j.status === 0) {
                tr.setAttribute('data-snoozed', snoozed ? '0' : '1');
                sn.textContent = snoozed ? '💤' : '🔔';
                sn.setAttribute('data-tip', snoozed ? 'Snooze' : 'Unsnooze');
                rmApplyFilters();
                rmToast(snoozed ? 'Unsnoozed.' : 'Snoozed.');
            } else rmToast(j.error || 'Failed.', true);
        });
        return;
    }
    var ds = e.target.closest('.rm-act-dismiss');
    if (ds) {
        var tr2 = ds.closest('tr');
        tnConfirm({ title: 'Dismiss recommendation?', body: 'This removes the recommendation from the pending list.', confirmLabel: 'Dismiss', danger: true, onConfirm: function () {
            var fd2 = new FormData(); fd2.append('RecommendationsId', tr2.getAttribute('data-rec-id'));
            rmPost(rmRecAjaxBase('dismissrecommendation'), fd2).then(function (j) {
                if (j.status === 0) { rmRemoveRow(tr2); rmToast('Dismissed.'); }
                else rmToast(j.error || 'Failed.', true);
            });
        } });
    }
});

// Bulk: run fn sequentially over rows, tally results, toast at end.
function rmBulkSequential(rows, fn, doneMsg) {
    var ok = 0, fail = 0, i = 0;
    (function next() {
        if (i >= rows.length) { rmToast(doneMsg(ok, fail), fail > 0); rmApplyFilters(); return; }
        var tr = rows[i++];
        fn(tr).then(function (good) { good ? ok++ : fail++; next(); });
    })();
}
document.querySelector('.rm-bulk-snooze').addEventListener('click', function () {
    var rows = rmSelected().filter(function (tr) { return tr.getAttribute('data-snoozed') !== '1'; });
    rmBulkSequential(rows, function (tr) {
        var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
        return rmPost(rmRecAjaxBase('snoozerecommendation'), fd).then(function (j) {
            if (j.status === 0) {
                tr.setAttribute('data-snoozed', '1');
                var b = tr.querySelector('.rm-act-snooze');
                if (b) { b.textContent = '🔔'; b.setAttribute('data-tip', 'Unsnooze'); }
                tr.querySelector('.rm-rowsel').checked = false;
                return true;
            }
            return false;
        });
    }, function (ok, fail) { return 'Snoozed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
    rmUpdateSelCount();
});
document.querySelector('.rm-bulk-dismiss').addEventListener('click', function () {
    var rows = rmSelected();
    tnConfirm({ title: 'Dismiss ' + rows.length + ' recommendation(s)?', body: 'They will be removed from the pending list.', confirmLabel: 'Dismiss all', danger: true, onConfirm: function () {
        rmBulkSequential(rows, function (tr) {
            var fd = new FormData(); fd.append('RecommendationsId', tr.getAttribute('data-rec-id'));
            return rmPost(rmRecAjaxBase('dismissrecommendation'), fd).then(function (j) {
                if (j.status === 0) { rmRemoveRow(tr); return true; }
                return false;
            });
        }, function (ok, fail) { return 'Dismissed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; });
    } });
});

/* ---------- Task 9: Grant Now (instant) ---------- */
// Today's date as YYYY-MM-DD (the format add_player_award expects for Date).
function rmTodayYMD() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}
// Core grant: write the award, optionally run a court-reconciliation step, then
// soft-delete the rec and drop the row. `courtStep` (optional) returns a Promise
// that settles the linked court award(s) before we dismiss the rec.
function rmDoGrant(rec, tr, courtStep) {
    var fd = new FormData();
    fd.append('KingdomAwardId', rec.KingdomAwardId);
    fd.append('GivenById', RmConfig.userId);
    fd.append('Date', rmTodayYMD());
    fd.append('ParkId', RmConfig.parkId || '0');
    fd.append('KingdomId', RmConfig.kingdomId || '0');
    fd.append('EventId', '0');
    fd.append('Note', rec.Reason || '');
    if (rec.Rank) fd.append('Rank', rec.Rank);
    // Admin/player/{id}/addaward renders the full Admin page (HTML, not JSON),
    // so success is "the request reached the server with HTTP 200" (response.ok).
    // Only on a confirmed-OK grant do we touch the court awards + dismiss the rec.
    return fetch(RmConfig.uir + 'Admin/player/' + rec.MundaneId + '/addaward', {
        method: 'POST', body: fd, credentials: 'same-origin'
    }).then(function (r) {
        if (!r.ok) throw new Error('grant http ' + r.status);
        return courtStep ? courtStep() : null;
    }).then(function () {
        var fd2 = new FormData();
        fd2.append('RecommendationsId', rec.RecommendationsId);
        fd2.append('Granted', '1');
        return rmPost(rmRecAjaxBase('dismissrecommendation'), fd2);
    }).then(function () {
        rmRemoveRow(tr);
        rmToast('Granted.');
    }).catch(function () {
        rmToast('Grant failed.', true);
    });
}
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var g = e.target.closest('.rm-act-grant'); if (!g) return;
    var tr = g.closest('tr');
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    var courts = []; try { courts = JSON.parse(tr.getAttribute('data-courts') || '[]'); } catch (x) {}
    if (courts && courts.length) {
        // Already on a court → let the officer decide what happens to that planned award.
        rmOpenGrantCourtModal(rec, tr, courts);
        return;
    }
    tnConfirm({ title: 'Grant now?', body: 'Grant “' + (rec.Persona || '') + '” the award immediately and remove it from pending.', confirmLabel: 'Grant Now', onConfirm: function () {
        rmDoGrant(rec, tr, null);
    } });
});

/* ---------- Task 9b: Grant Now when already on a court ---------- */
var rmGrantCourtCtx = null;
function rmOpenGrantCourtModal(rec, tr, courts) {
    rmGrantCourtCtx = { rec: rec, tr: tr, courts: courts };
    var names = courts.map(function (c) {
        return c.Name + (c.CourtDate ? ' (' + c.CourtDate + ')' : '');
    }).join(', ');
    document.getElementById('rm-grantcourt-sub').textContent =
        '“' + (rec.Persona || '') + '” is already on ' +
        (courts.length === 1 ? 'court: ' : courts.length + ' courts: ') + names +
        '. Grant the award now and…';
    document.getElementById('rm-grantcourt-overlay').hidden = false;
}
function rmCloseGrantCourtModal() {
    document.getElementById('rm-grantcourt-overlay').hidden = true;
    rmGrantCourtCtx = null;
}
document.getElementById('rm-gc-back').addEventListener('click', rmCloseGrantCourtModal);
document.getElementById('rm-grantcourt-overlay').addEventListener('click', function (e) {
    if (e.target === this) rmCloseGrantCourtModal(); // click backdrop closes
});
// Grant & Remove from Court: delete the planned court award(s) entirely.
document.getElementById('rm-gc-remove').addEventListener('click', function () {
    if (!rmGrantCourtCtx) return;
    var ctx = rmGrantCourtCtx; rmCloseGrantCourtModal();
    rmDoGrant(ctx.rec, ctx.tr, function () {
        return Promise.all(ctx.courts.map(function (c) {
            var fd = new FormData(); fd.append('CourtAwardId', c.CourtAwardId);
            return rmPost(RmConfig.uir + 'CourtAjax/remove_award', fd);
        }));
    });
});
// Grant & Leave on Court: mark the court award(s) 'given' so the herald still sees
// it on the court order but it can't be re-granted (grant_award guards 'given').
document.getElementById('rm-gc-leave').addEventListener('click', function () {
    if (!rmGrantCourtCtx) return;
    var ctx = rmGrantCourtCtx; rmCloseGrantCourtModal();
    rmDoGrant(ctx.rec, ctx.tr, function () {
        return Promise.all(ctx.courts.map(function (c) {
            var fd = new FormData();
            fd.append('CourtAwardId', c.CourtAwardId);
            fd.append('Status', 'given');
            return rmPost(RmConfig.uir + 'CourtAjax/set_award_status', fd);
        }));
    });
});

/* ---------- Task 10: Add to Court modal (single + bulk) ---------- */
var rmCourtTargets = []; // array of rec payloads (each with ._tr) to add

function rmOpenCourtModal(targets) {
    rmCourtTargets = targets;
    document.getElementById('rm-court-sub').textContent = targets.length === 1
        ? 'Adding 1 recommendation.' : 'Adding ' + targets.length + ' recommendations.';
    document.getElementById('rm-court-overlay').hidden = false;
}
function rmCloseCourtModal() { document.getElementById('rm-court-overlay').hidden = true; }
document.getElementById('rm-court-cancel').addEventListener('click', rmCloseCourtModal);
document.getElementById('rm-court-overlay').addEventListener('click', function (e) {
    if (e.target === this) rmCloseCourtModal(); // click backdrop closes
});
document.querySelectorAll('input[name="rm-court-mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var isNew = document.querySelector('input[name="rm-court-mode"]:checked').value === 'new';
        document.getElementById('rm-court-new').hidden = !isNew;
        document.getElementById('rm-court-existing').hidden = isNew;
    });
});

// flatpickr is not loaded on this page; if it ever is, give the date a human-readable display.
if (typeof flatpickr !== 'undefined') {
    flatpickr('#rm-court-date', { altInput: true, altFormat: 'F j, Y', dateFormat: 'Y-m-d' });
}

// Per-row opener.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var c = e.target.closest('.rm-act-court'); if (!c) return;
    var tr = c.closest('tr');
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    rec._tr = tr;
    rmOpenCourtModal([rec]);
});
// Bulk opener.
document.querySelector('.rm-bulk-court').addEventListener('click', function () {
    var targets = rmSelected().map(function (tr) {
        var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
        rec._tr = tr; return rec;
    });
    if (targets.length) rmOpenCourtModal(targets);
});

// Refresh the Court cell badge link after a rec is added to a court.
function rmUpdateCourtBadge(tr, courts) {
    var td = tr.querySelector('.rm-col-court');
    if (!courts.length) { td.innerHTML = '<span class="rm-empty">&mdash;</span>'; return; }
    var more = courts.length > 1 ? ' <span class="rm-courtmore">+' + (courts.length - 1) + '</span>' : '';
    td.innerHTML = '<a class="rm-courtbadge" href="' + RmConfig.uir + 'Court/detail/' + courts[0].CourtId + '">' + rmEsc(courts[0].Name) + more + '</a>';
}

// Submit: resolve a court id (create new, or use selected existing), then add_award per target.
document.getElementById('rm-court-submit').addEventListener('click', function () {
    var mode = document.querySelector('input[name="rm-court-mode"]:checked').value;
    var btn = this; btn.disabled = true;
    function withCourtId(cb) {
        if (mode === 'new') {
            var name = (document.getElementById('rm-court-name').value || '').trim();
            if (!name) { rmToast('Enter a court name.', true); btn.disabled = false; return; }
            var fd = new FormData();
            fd.append('KingdomId', RmConfig.kingdomId);
            fd.append('ParkId', RmConfig.parkId || '0');
            fd.append('Name', name);
            fd.append('CourtDate', (document.getElementById('rm-court-date').value || '').trim());
            fd.append('EventCalendarDetailId', '0');
            rmPost(RmConfig.uir + 'CourtAjax/create_court', fd).then(function (j) {
                if (j.status === 0 && j.court_id) cb(j.court_id, j.name);
                else { rmToast(j.error || 'Could not create court.', true); btn.disabled = false; }
            }).catch(function () { rmToast('Could not create court.', true); btn.disabled = false; });
        } else {
            var sel = document.getElementById('rm-court-select');
            if (!sel || !sel.value) { rmToast('Pick a court.', true); btn.disabled = false; return; }
            cb(parseInt(sel.value, 10), sel.selectedOptions[0].text);
        }
    }
    withCourtId(function (courtId, courtName) {
        var ok = 0, skip = 0, fail = 0, i = 0;
        (function next() {
            if (i >= rmCourtTargets.length) {
                rmToast('Added ' + ok + (skip ? ', ' + skip + ' already on court' : '') + (fail ? ', ' + fail + ' failed' : '') + '.', fail > 0);
                btn.disabled = false; rmCloseCourtModal(); rmApplyFilters(); return;
            }
            var rec = rmCourtTargets[i++];
            // Skip if this rec is already on the chosen court.
            var existing = []; try { existing = JSON.parse(rec._tr.getAttribute('data-courts') || '[]'); } catch (x) {}
            if (existing.some(function (cc) { return cc.CourtId === courtId; })) { skip++; next(); return; }
            var fd = new FormData();
            fd.append('CourtId', courtId);
            fd.append('MundaneId', rec.MundaneId);
            fd.append('KingdomAwardId', rec.KingdomAwardId);
            fd.append('Rank', rec.Rank || 0);
            fd.append('RecommendationsId', rec.RecommendationsId);
            rmPost(RmConfig.uir + 'CourtAjax/add_award', fd).then(function (j) {
                if (j.status === 0) {
                    ok++;
                    existing.push({ CourtId: courtId, Name: courtName, CourtDate: '', Status: 'draft' });
                    rec._tr.setAttribute('data-courts', JSON.stringify(existing));
                    rmUpdateCourtBadge(rec._tr, existing);
                    var box = rec._tr.querySelector('.rm-rowsel'); if (box) box.checked = false;
                } else fail++;
                next();
            }).catch(function () { fail++; next(); });
        })();
    });
});

// Show the reason expander only when the reason is actually truncated.
function rmSyncReasonExpanders() {
    document.querySelectorAll('#rm-tbody .rm-reason-trunc').forEach(function (el) {
        var btn = el.parentNode.querySelector('.rm-expand-reason');
        if (!btn) return;
        btn.style.display = (el.scrollWidth - el.clientWidth > 1) ? '' : 'none';
    });
}

// Initial: default sort (oldest first) + sync counts/chips + expander visibility.
rmSort('date');
rmApplyFilters();
rmSyncReasonExpanders();
var rmReasonResizeT;
window.addEventListener('resize', function () {
    clearTimeout(rmReasonResizeT);
    rmReasonResizeT = setTimeout(rmSyncReasonExpanders, 150);
});
</script>
