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
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/awardexplorer.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/awardexplorer.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">

<!-- rp-root is not decoration: reports.css scopes the whole --rp-* palette to it,
     so without it the shared header's background resolves to nothing and the
     white title lands on the page background. -->
<div class="ae-page rp-root">

	<!-- The shared report header (reports.css), as the other 48 report templates
	     use. A bespoke one here looked out of place next to its own siblings. -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-award rp-header-icon" aria-hidden="true"></i>
				<h1 class="rp-header-title">Award Explorer</h1>
			</div>
<?php if ($ae_scope['Type'] !== null): ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?= UIR ?><?= $ae_scope['Type'] === 'Park' ? 'Park' : 'Kingdom' ?>/profile/<?= (int)$ae_scope['Id'] ?>">
					<i class="fas <?= $ae_scope['Type'] === 'Park' ? 'fa-tree' : 'fa-chess-rook' ?>" aria-hidden="true"></i>
					<?= htmlspecialchars($ae_scope['Name']) ?>
				</a>
			</div>
<?php endif; ?>
		</div>
<?php if ($ae_ready): ?>
		<div class="rp-header-actions">
			<button type="button" class="rp-btn-ghost" id="ae-help-open" aria-haspopup="dialog" aria-controls="ae-help" aria-expanded="false">
				<i class="fas fa-circle-question" aria-hidden="true"></i> Help
			</button>
		</div>
<?php endif; ?>
	</div>

	<div class="ae-intro">
		<i class="fas fa-circle-info" aria-hidden="true"></i>
		<span>One row per award ever given, newest first. Build a filter from rules joined by
		AND&nbsp;/&nbsp;OR, choose your columns, then Run. Copy link shares the exact search.</span>
	</div>

<?php if ($ae_scope['Type'] !== null): ?>
	<p class="ae-scope">
		Filters start on <strong><?= htmlspecialchars($ae_scope['Name']) ?></strong> &mdash;
		awards held by players there now. Press <strong>Run</strong> to see them, or
		remove the <?= strtolower(htmlspecialchars($ae_scope['Type'])) ?> filter below
		to search everywhere.
	</p>
<?php endif; ?>

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
	<!-- What this search says, in words. The builder explains the filter to
	     whoever assembled it; this explains it to whoever was sent the link. -->
	<p class="ae-summary" id="ae-summary" hidden></p>
	<div class="ae-results" id="ae-results" aria-live="polite">
		<p class="ae-empty">Choose your filters and press <strong>Run</strong>.</p>
	</div>

<?php endif; ?>
</div>


<?php if ($ae_ready): ?>
<!-- Help guide. Prose lives here; the criteria reference at the end is generated
     from the registry by awardexplorer-help.js so it cannot drift from the builder. -->
<div class="ae-help-overlay" id="ae-help" hidden>
	<div class="ae-help-box" role="dialog" aria-modal="true" aria-labelledby="ae-help-title">
		<div class="ae-help-head">
			<h2 id="ae-help-title"><i class="fas fa-circle-question" aria-hidden="true"></i> Award Explorer guide</h2>
			<button type="button" class="ae-help-close" id="ae-help-close" aria-label="Close">&times;</button>
		</div>
		<div class="ae-help-body" id="ae-help-body" tabindex="0">

			<nav class="ae-help-toc" aria-label="Contents">
				<ol>
					<li><a href="#ae-h-what">What this report does</a></li>
					<li><a href="#ae-h-start">Quick start</a></li>
					<li><a href="#ae-h-picks">Quick picks</a></li>
					<li><a href="#ae-h-window">The date window</a></li>
					<li><a href="#ae-h-rules">Rules and groups</a></li>
					<li><a href="#ae-h-left">What is left out unless you ask</a></li>
					<li><a href="#ae-h-results">Results, columns and export</a></li>
					<li><a href="#ae-h-share">Share links and starting over</a></li>
					<li><a href="#ae-h-ref">Criteria reference</a></li>
				</ol>
			</nav>

			<h3 class="ae-help-h" id="ae-h-what">What this report does</h3>
			<p>It lists <strong>award grants</strong> &mdash; one row for every award ever given. A player
			who holds five awards appears five times. That is the difference from Population Explorer,
			which lists <em>players</em>, one row each.</p>
			<p>Award records are public, but the report needs you signed in.</p>

			<h3 class="ae-help-h" id="ae-h-start">Quick start</h3>
			<ol>
				<li>Press a <strong>Quick pick</strong>, or add rules yourself under <strong>Filters</strong>.</li>
				<li>Tick the <strong>Columns</strong> you want.</li>
				<li>Press <strong>Run</strong>.</li>
			</ol>
			<p>Opening the page does not run anything. Arriving from a kingdom or park page starts you
			filtered to it, but still waits for Run.</p>

			<h3 class="ae-help-h" id="ae-h-picks">Quick picks</h3>
			<p>Knights, Masterhoods, Paragons, Ladder awards, Titles and Kingdom-level each replace your
			rules and run, keeping whichever kingdom or park you are in. Whatever they set is an ordinary
			rule you can then change. The peerage ones also clear the date window, since knightings are
			rare enough that twelve months usually shows nothing.</p>

			<h3 class="ae-help-h" id="ae-h-window">The date window</h3>
			<p>New searches start on the <strong>last 12 months</strong>, so an older award will not appear
			until you widen it. The chips write an ordinary date rule into Filters that you can edit or
			delete; <strong>Everything</strong> removes it.</p>

			<h3 class="ae-help-h" id="ae-h-rules">Rules and groups</h3>
			<p>Rules join with the <strong>AND</strong>/<strong>OR</strong> button between them. For
			&ldquo;this kingdom, and either of two awards&rdquo;, you have two options:</p>
			<ul>
				<li>Set the award rule&rsquo;s operator to <strong>is any of</strong> and pick both &mdash; simplest.</li>
				<li>Or press <strong>Add group</strong>, which nests a bracketed OR inside the AND.</li>
			</ul>
			<p>Both give the same answer. Groups earn their place when the alternatives are on
			<em>different</em> criteria.</p>

			<h3 class="ae-help-h" id="ae-h-left">What is left out unless you ask</h3>
			<ul>
				<li><strong>Revoked awards</strong> &mdash; honours later withdrawn.</li>
				<li><strong>Awards stripped from a player</strong> &mdash; where the recipient was removed from the record.</li>
			</ul>
			<p>Both have checkboxes under Filters. They are applied outside your rules, so an OR group
			cannot pull them back in &mdash; which is why they are not offered as rules.</p>
			<p>Awards with <strong>no recorded date</strong> are never matched by a date rule. Use
			&ldquo;Date is unknown&rdquo; to find them.</p>

			<h3 class="ae-help-h" id="ae-h-results">Results, columns and export</h3>
			<p>Newest first, 25 to a page. The line above the table says the whole search in plain
			English, including the filters that are not rules.</p>
			<p>Columns always appear in a fixed order, whatever order you tick them. <strong>CSV</strong>
			exports every row of the result, not just the page you are looking at.</p>
			<p>Very large results are capped; the status line says so when that happens, and the answer
			is to narrow the filter.</p>

			<h3 class="ae-help-h" id="ae-h-share">Share links and starting over</h3>
			<p><strong>Copy link</strong> captures the filters, columns and date rule exactly. Whoever
			opens it sees the same rows you did, and the report says so at the top with a
			<strong>Start over</strong> button that clears everything back to a blank report.</p>

			<h3 class="ae-help-h" id="ae-h-ref">Criteria reference</h3>
			<p>Every filter available, with what it matches.</p>
			<dl id="ae-help-ref"></dl>

		</div>
	</div>
</div>
<?php endif; ?>

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
<script src="<?= HTTP_TEMPLATE ?>default/script/awardexplorer-help.js?v=<?= filemtime(DIR_TEMPLATE . 'default/script/awardexplorer-help.js') ?>"></script>
<?php endif; ?>
