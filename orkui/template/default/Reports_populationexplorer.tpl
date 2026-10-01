<?php
/* ── Population Explorer ─────────────────────────────────────
   Controller: Controller_Reports::population_explorer
   Data: pe_scope_type, pe_scope_id, pe_scope_name, pe_registry,
         pe_initial, pe_link_error, pe_link_param, pe_no_scope, pe_bad_scope, pe_load_error
   The builder itself lives in script/populationexplorer.js and
   reads only window.PE. */
$pe_scope_type = $pe_scope_type ?? null;
$pe_scope_id   = (int)($pe_scope_id ?? 0);
$pe_scope_name = (string)($pe_scope_name ?? '');
$pe_registry   = is_array($pe_registry ?? null) ? $pe_registry : [];
$pe_initial    = $pe_initial ?? null;
$pe_link_error = $pe_link_error ?? null;
$pe_no_scope   = !empty($pe_no_scope);
$pe_bad_scope  = !empty($pe_bad_scope);
$pe_load_error = !empty($pe_load_error);
$pe_link_param = (string)($pe_link_param ?? 'pe');
$pe_ready      = !$pe_no_scope && !$pe_bad_scope && !$pe_load_error && $pe_scope_type !== null && !empty($pe_registry);

$pe_is_park    = ($pe_scope_type === 'Park');
// Officers of the scope also get the restricted Suspended / Banned filters (PublicRegistry).
$pe_officer    = !empty($pe_registry['officer']);
$pe_scope_link = $pe_scope_type ? UIR . ($pe_is_park ? 'Park' : 'Kingdom') . '/profile/' . $pe_scope_id : '';
$pe_scope_icon = $pe_is_park ? 'fa-tree' : 'fa-chess-rook';

// Option names (and ladder criterion labels) come straight from the DB; strip
// magic-quotes-era escapes for display.
foreach ((array)($pe_registry['criteria'] ?? []) as $_id => $_def) {
	if (is_array($_def) && isset($_def['label']) && is_string($_def['label'])) {
		$pe_registry['criteria'][$_id]['label'] = stripslashes($_def['label']);
	}
}
if (isset($pe_registry['options']) && is_array($pe_registry['options'])) {
	foreach (['class', 'award', 'park', 'kingdom'] as $_k) {
		foreach ((array)($pe_registry['options'][$_k] ?? []) as $_i => $_o) {
			if (isset($_o[1]) && is_string($_o[1])) {
				$pe_registry['options'][$_k][$_i][1] = stripslashes($_o[1]);
			}
		}
	}
	foreach ((array)($pe_registry['options']['order'] ?? []) as $_pe => $_list) {
		foreach ((array)$_list as $_i => $_o) {
			if (isset($_o[1]) && is_string($_o[1])) {
				$pe_registry['options']['order'][$_pe][$_i][1] = stripslashes($_o[1]);
			}
		}
	}
}

$pe_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
$pe_page_url   = UIR . 'Reports/population_explorer&' . ($pe_is_park ? 'ParkId' : 'KingdomId') . '=' . $pe_scope_id;
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__ . '/style/reports.css')?>">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/populationexplorer.css?v=<?=filemtime(__DIR__ . '/style/populationexplorer.css')?>">

<div class="rp-root pe-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-users-viewfinder rp-header-icon"></i>
				<h1 class="rp-header-title">Population Explorer</h1>
			</div>
<?php if ($pe_ready && $pe_scope_name !== '') : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=htmlspecialchars($pe_scope_link)?>">
					<i class="fas <?=$pe_scope_icon?>"></i>
					<?=htmlspecialchars($pe_scope_name)?>
				</a>
			</div>
<?php endif; ?>
		</div>
<?php if ($pe_ready) : ?>
		<div class="rp-header-actions">
			<button type="button" class="rp-btn-ghost" id="pe-copy-link" data-tip="Copy a link that reopens this exact filter and column set"><i class="fas fa-link"></i> Copy link</button>
			<button type="button" class="rp-btn-ghost" id="pe-export" aria-disabled="true" data-tip="Run the report first, then export the results to Excel"><i class="fas fa-file-excel"></i> Export</button>
		</div>
<?php endif; ?>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Build a filter from rules joined by AND / OR, choose the columns you want, then Run. Groups nest, so you can mix &ldquo;all of&rdquo; and &ldquo;any of&rdquo;. Results are limited to players in this <?=$pe_is_park ? 'park' : 'kingdom'?> and capped at 5,000 rows; Copy link shares the exact query.<?php if ($pe_ready && !$pe_officer) : ?> The Suspended and Banned filters are only offered to officers of this <?=$pe_is_park ? 'park' : 'kingdom'?>.<?php endif; ?></span>
	</div>

<?php if ($pe_bad_scope) : ?>
	<div class="rp-empty-state pe-empty-page">
		<i class="fas fa-circle-question"></i>
		<h3 class="pe-empty-title">That <?=$pe_is_park ? 'park' : 'kingdom'?> could not be found</h3>
		<p>Open Population Explorer from a kingdom or park&rsquo;s Reports tab to choose who to explore.</p>
	</div>
<?php elseif ($pe_load_error) : ?>
	<div class="rp-empty-state pe-empty-page" role="alert">
		<i class="fas fa-circle-exclamation"></i>
		<h3 class="pe-empty-title">The report options could not be loaded</h3>
		<p>Something went wrong reading the class, award and park lists. Reload the page to try again.</p>
	</div>
<?php elseif (!$pe_ready) : ?>
	<div class="rp-empty-state pe-empty-page">
		<i class="fas fa-users-viewfinder"></i>
		<h3 class="pe-empty-title">No kingdom or park selected</h3>
		<p>Open Population Explorer from a kingdom or park&rsquo;s Reports tab to choose who to explore.</p>
	</div>
<?php else : ?>

<?php if ($pe_link_error) : ?>
	<div class="pe-banner pe-banner-warn" role="alert">
		<i class="fas fa-triangle-exclamation"></i>
		<span><?=htmlspecialchars(rtrim((string)$pe_link_error, '. ') . '.')?> The builder has been reset.</span>
	</div>
<?php endif; ?>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number" id="pe-stat-results">&mdash;</div>
			<div class="rp-stat-label">Results</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percent"></i></div>
			<div class="rp-stat-number" id="pe-stat-pct">&mdash;</div>
			<div class="rp-stat-label">Of <?=$pe_is_park ? 'park' : 'kingdom'?> players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-stopwatch"></i></div>
			<div class="rp-stat-number" id="pe-stat-time">&mdash;</div>
			<div class="rp-stat-label">Run time</div>
		</div>
	</div>

	<div class="rp-main pe-main">

		<!-- Filters -->
		<section class="rp-filter-card pe-card" id="pe-filters-card">
			<div class="rp-filter-card-header pe-card-head">
				<button type="button" class="pe-card-toggle" id="pe-filters-toggle" aria-expanded="true" aria-controls="pe-filters-body">
					<i class="fas fa-filter" aria-hidden="true"></i>
					<span class="pe-card-title">Filters</span>
					<span class="pe-card-summary" id="pe-filters-summary"></span>
					<i class="fas fa-chevron-down pe-card-chevron" aria-hidden="true"></i>
				</button>
			</div>
			<div class="rp-filter-card-body" id="pe-filters-body">
				<div id="pe-builder" class="pe-builder"></div>
				<div class="pe-run-bar">
					<button type="button" class="pe-btn-run" id="pe-run"><i class="fas fa-play"></i> <span>Run</span></button>
					<button type="button" class="pe-btn-link" id="pe-clear">Clear all rules</button>
					<span class="pe-dirty-hint" id="pe-dirty" hidden><i class="fas fa-circle-info" aria-hidden="true"></i> Filters changed since last run</span>
				</div>
			</div>
		</section>

		<!-- Columns -->
		<section class="rp-filter-card pe-card" id="pe-columns-card">
			<div class="rp-filter-card-header pe-card-head">
				<button type="button" class="pe-card-toggle" id="pe-columns-toggle" aria-expanded="true" aria-controls="pe-columns-body">
					<i class="fas fa-table-columns" aria-hidden="true"></i>
					<span class="pe-card-title">Columns</span>
					<span class="pe-card-summary" id="pe-columns-summary"></span>
					<i class="fas fa-chevron-down pe-card-chevron" aria-hidden="true"></i>
				</button>
				<button type="button" class="pe-btn-link pe-card-header-link" id="pe-columns-reset">Reset</button>
			</div>
			<div class="rp-filter-card-body" id="pe-columns-body">
				<div id="pe-columns" class="pe-columns"></div>
			</div>
		</section>

		<!-- Results -->
		<section class="rp-filter-card pe-card" id="pe-results-card">
			<div class="rp-filter-card-header"><i class="fas fa-list"></i> Results</div>
			<div class="rp-filter-card-body pe-results-body">
				<div id="pe-results-msg"></div>
				<p class="pe-stale-note" id="pe-stale" hidden><i class="fas fa-clock-rotate-left" aria-hidden="true"></i> The table below is from your last successful run.</p>
				<div class="rp-table-area pe-table-area" id="pe-table-area" hidden></div>
				<div class="rp-empty-state pe-results-empty" id="pe-results-idle">
					<i class="fas fa-users-viewfinder"></i>
					<p>Build a filter and press <strong>Run</strong> to see matching players.</p>
				</div>
			</div>
		</section>

	</div><!-- /rp-main -->

	<div class="pe-toast" id="pe-toast" role="status" aria-live="polite" hidden></div>
	<!-- Persistent live region: run results and errors are announced here. -->
	<div class="pe-sr-only" id="pe-live" role="status" aria-live="polite" aria-atomic="true"></div>

<?php endif; ?>
</div><!-- /rp-root -->

<?php if ($pe_ready) : ?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script>
window.PE = <?=json_encode([
	'registry' => $pe_registry,
	'scope'    => ['type' => $pe_scope_type, 'id' => $pe_scope_id, 'name' => $pe_scope_name],
	'initial'  => $pe_initial,
	'urls'     => [
		'run'    => UIR . 'Reports/population_explorer_json',
		'export' => UIR . 'Reports/population_explorer_export',
		// Share links carry the filter in `pe`, never `q` (analytics logs `q` as a site search).
		'share'  => $pe_page_url . '&' . $pe_link_param . '=',
		'player' => UIR . 'Player/profile/',
	],
], $pe_json_flags)?>;
</script>
<script src="<?=HTTP_TEMPLATE?>default/script/populationexplorer.js?v=<?=filemtime(__DIR__ . '/script/populationexplorer.js')?>"></script>
<?php endif; ?>
