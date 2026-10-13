<?php
/* ── Award Explorer ──────────────────────────────────────────
   Controller: Controller_Reports::award_explorer
   Data: ae_registry, ae_initial, ae_link_error, ae_link_param,
         ae_default_months, ae_logged_out, ae_load_error
   The builder lives in script/awardexplorer.js and reads only window.AE. */
$ae_registry      = is_array($ae_registry ?? null) ? $ae_registry : [];
$ae_initial       = $ae_initial ?? null;
$ae_link_error    = $ae_link_error ?? null;
$ae_link_param    = (string)($ae_link_param ?? 'ae');
$ae_default_months = (int)($ae_default_months ?? 12);
$ae_logged_out    = !empty($ae_logged_out);
$ae_scope         = is_array($ae_scope ?? null) ? $ae_scope : ['Type' => null, 'Id' => 0, 'Name' => ''];
$ae_load_error    = !empty($ae_load_error);
$ae_ready         = !$ae_logged_out && !$ae_load_error && !empty($ae_registry);

// Labels come straight from the DB; strip magic-quotes-era escapes for display.
foreach ((array)($ae_registry['criteria'] ?? []) as $_id => $_def) {
	if (is_array($_def) && isset($_def['label']) && is_string($_def['label'])) {
		$ae_registry['criteria'][$_id]['label'] = stripslashes($_def['label']);
	}
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/awardexplorer.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/awardexplorer.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<div class="ae-page">

	<div class="ae-head">
		<h1><i class="fas fa-award" aria-hidden="true"></i> Award Explorer</h1>
		<p class="ae-sub">Every award the ORK has recorded, newest first.</p>
<?php if ($ae_scope['Type'] !== null): ?>
		<p class="ae-scope">
			Filters start on <strong><?= htmlspecialchars($ae_scope['Name']) ?></strong> &mdash;
			awards held by players there now. Press <strong>Run</strong> to see them, or
			remove the <?= strtolower(htmlspecialchars($ae_scope['Type'])) ?> filter below
			to search everywhere.
		</p>
<?php endif; ?>
	</div>

<?php if ($ae_logged_out): ?>
	<div class="ae-notice ae-notice-info">
		<strong>Please log in.</strong>
		Award records are public, but this report is only available to signed-in members.
		<a href="<?= UIR ?>Login&amp;return=<?= urlencode('Reports/award_explorer') ?>">Log in</a> to use it.
	</div>
<?php elseif ($ae_load_error): ?>
	<div class="ae-notice ae-notice-error">
		The award list could not be read just now. Please try again in a moment.
	</div>
<?php else: ?>

<?php if ($ae_link_error): ?>
	<div class="ae-notice ae-notice-warn"><?= htmlspecialchars($ae_link_error) ?></div>
<?php endif; ?>

<?php if ($ae_initial !== null): ?>
	<!-- Opened from a shared link: say so, and offer the way back to a blank
	     report. Without this the only route is editing the URL, because the
	     share parameter re-applies on every refresh. -->
	<div class="ae-notice ae-notice-info" id="ae-shared-note">
		<i class="fas fa-link" aria-hidden="true"></i>
		You are looking at a <strong>shared filter</strong> — someone else's filters, columns and date range.
		<button type="button" class="ae-linkbtn" id="ae-start-over">Start over with a blank report</button>
	</div>
<?php endif; ?>

	<!-- ── Search + window ─────────────────────────────────── -->
	<div class="ae-card">
		<div class="ae-searchrow">
			<label class="ae-srch">
				<span class="ae-vh">Search awards</span>
				<i class="fas fa-search" aria-hidden="true"></i>
				<input type="search" id="ae-search" placeholder="Search by recipient or award name…" autocomplete="off" maxlength="64">
			</label>
			<div class="ae-chips" role="group" aria-label="Date window">
				<button type="button" class="ae-chip ae-chip-on" data-months="<?= $ae_default_months ?>">Last <?= $ae_default_months ?> months</button>
				<button type="button" class="ae-chip" data-months="60">Last 5 years</button>
				<button type="button" class="ae-chip" data-months="0">Everything</button>
			</div>
		</div>
		<p class="ae-hint" id="ae-window-hint"></p>
	</div>

	<!-- ── Quick picks ─────────────────────────────────────── -->
	<div class="ae-card">
		<p class="ae-quicklabel">Quick picks</p>
		<div class="ae-quick" role="group" aria-label="Common searches">
			<button type="button" class="ae-quickbtn" data-preset="knights"><i class="fas fa-chess-knight" aria-hidden="true"></i> Knights</button>
			<button type="button" class="ae-quickbtn" data-preset="masters"><i class="fas fa-hat-wizard" aria-hidden="true"></i> Masterhoods</button>
			<button type="button" class="ae-quickbtn" data-preset="paragons"><i class="fas fa-star" aria-hidden="true"></i> Paragons</button>
			<button type="button" class="ae-quickbtn" data-preset="ladders"><i class="fas fa-chart-line" aria-hidden="true"></i> Ladder awards</button>
			<button type="button" class="ae-quickbtn" data-preset="titles"><i class="fas fa-scroll" aria-hidden="true"></i> Titles</button>
			<button type="button" class="ae-quickbtn" data-preset="kingdomlevel"><i class="fas fa-crown" aria-hidden="true"></i> Kingdom-level</button>
		</div>
		<p class="ae-hint">A quick pick keeps your kingdom or park and replaces the rest, then runs. Everything it sets is an ordinary rule you can then change.</p>
	</div>

	<!-- ── Filters ─────────────────────────────────────────── -->
	<details class="ae-card ae-fold" id="ae-filters-card">
		<summary><i class="fas fa-filter" aria-hidden="true"></i> Filters <span class="ae-count" id="ae-filter-count"></span></summary>
		<div class="ae-foldbody">
			<!-- Add rule / Add group belong to a GROUP, so they are rendered per
			     group by renderGroup(); only Clear is global. -->
			<div id="ae-tree" class="ae-tree"></div>
			<div class="ae-treeactions">
				<button type="button" class="ae-btn ae-btn-quiet" id="ae-clear">Clear filters</button>
			</div>
			<label class="ae-check"><input type="checkbox" id="ae-include-revoked"> Include revoked awards</label>
			<label class="ae-check"><input type="checkbox" id="ae-include-stripped"> Include awards stripped from a player</label>
		</div>
	</details>

	<!-- ── Columns ─────────────────────────────────────────── -->
	<details class="ae-card ae-fold" id="ae-columns-card">
		<summary><i class="fas fa-table-columns" aria-hidden="true"></i> Columns <span class="ae-count" id="ae-column-count"></span></summary>
		<div class="ae-foldbody">
			<div id="ae-columns" class="ae-cols"></div>
		</div>
	</details>

	<!-- ── Actions ─────────────────────────────────────────── -->
	<div class="ae-actions">
		<button type="button" class="ae-btn ae-btn-primary" id="ae-run"><i class="fas fa-play" aria-hidden="true"></i> Run</button>
		<button type="button" class="ae-btn" id="ae-share"><i class="fas fa-link" aria-hidden="true"></i> Copy link</button>
		<button type="button" class="ae-btn" id="ae-csv"><i class="fas fa-file-csv" aria-hidden="true"></i> CSV</button>
		<span class="ae-status" id="ae-status" role="status" aria-live="polite"></span>
	</div>

	<div class="ae-notice ae-notice-error" id="ae-error" hidden></div>

	<!-- ── Results ─────────────────────────────────────────── -->
	<div class="ae-results" id="ae-results" aria-live="polite">
		<p class="ae-empty">Choose your filters and press <strong>Run</strong>.</p>
	</div>

<?php endif; ?>
</div>

<?php if ($ae_ready): ?>
<script>
window.AE = {
	uir:       <?= json_encode(UIR) ?>,
	registry:  <?= json_encode($ae_registry) ?>,
	initial:   <?= json_encode($ae_initial) ?>,
	linkParam: <?= json_encode($ae_link_param) ?>,
	defaultMonths: <?= (int)$ae_default_months ?>,
	scope:     <?= json_encode($ae_scope) ?>
};
</script>
<!-- awInitPicker: the searchable award chooser used by Add Award and Recommend an
     Award. Every page section in revised.js guards on its own config
     (`if (typeof PnConfig === 'undefined') return;`), so loading it here brings the
     shared widgets and nothing else. -->
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/script/revised.js') ?>"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>default/script/awardexplorer.js?v=<?= filemtime(DIR_TEMPLATE . 'default/script/awardexplorer.js') ?>"></script>
<?php endif; ?>
