<?php
$kcCss = DIR_TEMPLATE . 'default/style/knightcircle.css';
$kcJs  = DIR_TEMPLATE . 'default/script/knightcircle.js';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/knightcircle.css?v=<?= file_exists($kcCss) ? filemtime($kcCss) : 0 ?>">
<?php if (!empty($Error)) { ?>
<div class="rp-root kc-root"><div class="kc-wrap"><div class="kc-error"><?= htmlspecialchars($Error) ?></div>
  <?php if (!empty($KingdomId) && valid_id((int)$KingdomId)) { ?><p><a class="kc-link" href="<?= htmlspecialchars(UIR . 'Kingdom/profile/' . (int)$KingdomId) ?>">Back to the kingdom</a></p><?php } ?>
</div></div>
<?php return; } ?>
<?php
$kid     = (int)$KingdomId;
$access  = $Access;
$canManage = !empty($access['CanManage']);
$tab     = $Tab;
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<div class="rp-root kc-root" id="kc-root">
<div class="kc-wrap">
  <div class="rp-header">
    <div class="rp-header-left">
      <div class="rp-header-icon-title">
        <i class="fas fa-shield-alt rp-header-icon"></i>
        <h1 class="rp-header-title">Circle of Knights</h1>
      </div>
      <div class="rp-header-scope">
        <a class="rp-scope-chip" href="<?= htmlspecialchars(UIR . 'Kingdom/profile/' . $kid) ?>">
          <i class="fas fa-crown"></i>
          <span class="rp-scope-chip-label">Kingdom:</span> <?= htmlspecialchars($KingdomName) ?>
        </a>
        <?php if (!$canManage) { ?><span class="rp-scope-chip kc-viewonly" data-tip="You can look at the Circle but not change it. Only the Guardian of the Circle, deputies and ORK admins can make changes."><i class="fas fa-eye"></i> View only</span><?php } ?>
      </div>
    </div>
    <div class="rp-header-actions">
      <a class="rp-btn-ghost" href="<?= htmlspecialchars(UIR . 'Kingdom/profile/' . $kid) ?>"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>

  <div class="kc-tabs" role="tablist">
    <button type="button" class="kc-tab<?= $tab === 'roster' ? ' is-active' : '' ?>" role="tab" data-tab="roster" aria-selected="<?= $tab === 'roster' ? 'true' : 'false' ?>"><i class="fas fa-users"></i> Roster</button>
    <button type="button" class="kc-tab<?= $tab === 'pipeline' ? ' is-active' : '' ?>" role="tab" data-tab="pipeline" aria-selected="<?= $tab === 'pipeline' ? 'true' : 'false' ?>"><i class="fas fa-stream"></i> Candidates</button>
    <button type="button" class="kc-tab<?= $tab === 'settings' ? ' is-active' : '' ?>" role="tab" data-tab="settings" aria-selected="<?= $tab === 'settings' ? 'true' : 'false' ?>"><i class="fas fa-cog"></i> Settings</button>
  </div>

  <div id="kc-toast" class="kc-toast" role="status" aria-live="polite" hidden></div>

  <section class="kc-pane<?= $tab === 'roster' ? ' is-active' : '' ?>" id="kc-pane-roster" data-pane="roster">
    <div class="kc-rules" id="kc-rules"></div>
    <div class="kc-bar">
      <div class="kc-pills" id="kc-order-pills" role="group" aria-label="Order"></div>
      <label class="kc-check" data-tip="Show only knights who meet every rule listed above."><input type="checkbox" id="kc-f-eligible"> Eligible only</label>
      <label class="kc-check" data-tip="Hide knights who live in another kingdom but have signed in here."><input type="checkbox" id="kc-f-resident"> Residents only</label>
      <button type="button" class="kc-btn" id="kc-csv" data-tip="Download the list as shown, as a CSV file."><i class="fas fa-download"></i> Export CSV</button>
    </div>
    <div class="kc-tiles" id="kc-tiles"></div>
    <div id="kc-roster-msg" class="kc-msg" hidden></div>
    <div class="kc-tablewrap">
      <table class="kc-table display" id="kc-roster-table" style="width:100%">
        <thead><tr>
          <th>Knight</th><th>Order</th><th>Park</th><th>Sign-ins</th><th>Dues</th><th>Waiver</th><th>Knight for</th><th>Status</th>
        </tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </section>

  <section class="kc-pane<?= $tab === 'pipeline' ? ' is-active' : '' ?>" id="kc-pane-pipeline" data-pane="pipeline">
    <div class="kc-bar">
      <div class="kc-pills" id="kc-board-pills" role="group" aria-label="Order"></div>
      <label class="kc-check" data-tip="Dismissed candidates are kept, not deleted. Turn this on to see them and bring one back to the Watchlist."><input type="checkbox" id="kc-show-dismissed"> Show dismissed</label>
      <button type="button" class="kc-btn" id="kc-refresh" data-tip="Reload the board. Anyone who has since been granted a Master or a knighthood moves along automatically."><i class="fas fa-sync-alt"></i> Refresh</button>
      <?php if ($canManage) { ?><button type="button" class="kc-btn kc-btn-primary" id="kc-add-cand"><i class="fas fa-plus"></i> Add candidate</button><?php } ?>
    </div>
    <div id="kc-board-msg" class="kc-msg" hidden></div>
    <div id="kc-suggest" class="kc-suggest" hidden></div>
    <div class="kc-path" id="kc-path"></div>
    <div class="kc-board" id="kc-board"></div>
  </section>

  <section class="kc-pane<?= $tab === 'settings' ? ' is-active' : '' ?>" id="kc-pane-settings" data-pane="settings">
    <div class="kc-card">
      <h2 class="kc-card-title">Eligibility rules</h2>
      <?php if (!$canManage) { ?><p class="kc-hint">Only the Circle's officers can change these. They decide who counts as eligible on the Roster.</p><?php } ?>
      <form id="kc-config-form" autocomplete="off"></form>
    </div>
    <div class="kc-card">
      <h2 class="kc-card-title">Circle officers</h2>
      <p class="kc-hint">The Guardian of the Circle (GMoK) and deputies run the Circle: they see everything and move candidates.</p>
      <div id="kc-officers"></div>
    </div>
  </section>

  <aside class="kc-panel" id="kc-panel" aria-hidden="true" hidden></aside>
  <div class="kc-overlay" id="kc-overlay" hidden></div>
</div>
</div>

<script>window.KC_CFG = <?= json_encode($KcCfg, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;</script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/knightcircle.js?v=<?= file_exists($kcJs) ? filemtime($kcJs) : 0 ?>"></script>
