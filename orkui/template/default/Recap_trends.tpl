<?php
/* Amtgard Platform Trends — public, read-only charts over the accumulated
 * weekly recap data plus the all-history attendance curve.
 *
 * $trend_series   : rows of WeekStart/Visitors/Requests/Blocked/CacheHits/Bytes
 *                   (nulls where a source wasn't available — charts show gaps).
 * $players_series : rows of WeekStart/Players across all recorded history.
 * $signin_series  : rows of Day/Browsers/InApp/Apps/Total — anonymous daily
 *                   sign-in counts (tally starts 2026-08-22).
 */

$_ts  = is_array($trend_series)   ? $trend_series   : array();
$_ps  = is_array($players_series) ? $players_series : array();
$_ss  = is_array($signin_series)  ? $signin_series  : array();

// Highcharts datetime series: [ms, value|null]. INTERIOR gaps stay gaps — the
// honest rendering for "we don't trust / don't have data for this week" — but
// leading/trailing all-null stretches are trimmed so a source that only exists
// for part of the archive doesn't pad its chart with empty axis.
$_trim = function ($points) {
	$first = null; $last = null;
	foreach ($points as $i => $p) {
		if ($p[1] !== null) { if ($first === null) $first = $i; $last = $i; }
	}
	return $first === null ? array() : array_slice($points, $first, $last - $first + 1);
};

$_visitors = array();
$_requests = array();
$_blocked  = array();
$_requests_global = array();
$_blocked_global  = array();
foreach ($_ts as $_row) {
	$_ms = strtotime($_row['WeekStart']);
	if ($_ms === false) continue;
	$_ms *= 1000;
	$_visitors[]        = array($_ms, $_row['Visitors']);
	$_requests[]        = array($_ms, $_row['Requests']);
	$_blocked[]         = array($_ms, $_row['Blocked']);
	$_requests_global[] = array($_ms, $_row['RequestsGlobal'] ?? null);
	$_blocked_global[]  = array($_ms, $_row['BlockedGlobal']  ?? null);
}
$_visitors = $_trim($_visitors);
$_requests = $_trim($_requests);
$_blocked  = $_trim($_blocked);
// RequestsGlobal/BlockedGlobal only exist on weeks computed since 2026-08-27 —
// trimming leading nulls means this chart naturally starts there instead of
// padding out a year of empty axis.
$_requests_global = $_trim($_requests_global);
$_blocked_global  = $_trim($_blocked_global);

$_players = array();
foreach ($_ps as $_row) {
	// Guard against unparseable week buckets from garbage historical dates.
	$_ms = strtotime((string)$_row['WeekStart']);
	if ($_ms === false || $_ms < strtotime('2004-12-01')) continue;
	$_players[] = array($_ms * 1000, $_row['Players']);
}

$_si_browsers = array();
$_si_inapp    = array();
$_si_apps     = array();
foreach ($_ss as $_row) {
	$_ms = strtotime((string)$_row['Day']);
	if ($_ms === false) continue;
	$_ms *= 1000;
	$_si_browsers[] = array($_ms, $_row['Browsers']);
	$_si_inapp[]    = array($_ms, $_row['InApp']);
	$_si_apps[]     = array($_ms, $_row['Apps']);
}

$_fmt = function ($n) {
	if ($n === null) return '—';
	if ($n >= 1000000) return round($n / 1000000, 1) . 'M';
	if ($n >= 1000)    return round($n / 1000, 1) . 'K';
	return (string)$n;
};
?>
<style>
.recap-root { max-width: 900px; margin: 1.5em auto 3em; padding: 0 1em;
	font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Helvetica, Arial, sans-serif;
	color: #222; line-height: 1.5; }
.recap-root h1, .recap-root h2 { background: transparent; border: none;
	text-shadow: none; padding: 0; border-radius: 0; }
.recap-hero { text-align: center; padding: 1.5em 0 1.8em; margin-bottom: 1.5em;
	border-bottom: 2px solid #f0e5d0; }
.recap-hero-eyebrow { color: #c89b3f; font-size: 0.78em; letter-spacing: 0.22em;
	text-transform: uppercase; font-weight: 700; margin-bottom: 0.4em; }
.recap-hero-eyebrow .fas { margin-right: 0.4em; }
.recap-hero h1 { font-size: 1.65em; margin: 0; font-weight: 600; color: #2a2a2a; }
.recap-hero-sub { color: #999; font-size: 0.82em; margin-top: 0.7em; font-style: italic; }
.recap-section { background: #fafaf7; border: 1px solid #ececec;
	border-radius: 10px; padding: 1.1em 1.4em 1em; margin-bottom: 1em; }
.recap-section h2 { font-size: 1.08em; margin: 0 0 0.6em 0; color: #2a2a2a;
	font-weight: 600; display: flex; align-items: center; gap: 0.55em; }
.recap-section h2 .recap-section-icon { width: 1.6em; height: 1.6em; display: inline-flex;
	align-items: center; justify-content: center; border-radius: 50%; background: #f0e5d0;
	color: #a07d2a; font-size: 0.78em; flex-shrink: 0; }
.recap-digest { margin: 0.2em 0 0.6em; color: #3a3a3a; line-height: 1.55; }
.recap-muted { color: #888; font-size: 0.9em; }
.trends-chart { width: 100%; height: 280px; }
.recap-section details { margin-top: 0.7em; padding-top: 0.4em; border-top: 1px dashed #e0d9c2; }
.recap-section details summary { cursor: pointer; color: #888; font-size: 0.88em;
	padding: 0.2em 0; user-select: none; list-style: none; }
.recap-section details summary::-webkit-details-marker { display: none; }
.recap-section details summary::before { content: '▸ '; color: #c89b3f; font-size: 0.9em; }
.recap-section details[open] summary::before { content: '▾ '; }
.trends-subhead { margin: 1.2em 0 0.2em; font-size: 1em; color: #555; }
.trends-table { width: 100%; border-collapse: collapse; font-size: 0.85em; margin-top: 0.5em; }
.trends-table th, .trends-table td { text-align: right; padding: 3px 8px; border-bottom: 1px solid #eee9dc; }
.trends-table th:first-child, .trends-table td:first-child { text-align: left; }
.trends-table th { color: #888; font-weight: 600; }
.recap-foot { text-align: center; color: #aaa; font-size: 0.8em; margin-top: 1.5em; }
.recap-foot a { color: #1a4c8c; text-decoration: none; }
.recap-foot a:hover { text-decoration: underline; }

html[data-theme="dark"] .recap-root { color: #cbd5e0; }
html[data-theme="dark"] .recap-hero { border-bottom-color: #3a3325; }
html[data-theme="dark"] .recap-hero h1 { color: #e2e8f0; }
html[data-theme="dark"] .recap-section { background: #1e2533; border-color: #2d3748; }
html[data-theme="dark"] .recap-section h2 { color: #e2e8f0; }
html[data-theme="dark"] .recap-section h2 .recap-section-icon { background: #3a3325; color: #c89b3f; }
html[data-theme="dark"] .recap-digest { color: #cbd5e0; }
html[data-theme="dark"] .recap-section details { border-top-color: #3a4356; }
html[data-theme="dark"] .trends-table th, html[data-theme="dark"] .trends-table td { border-bottom-color: #2d3748; }
</style>

<div class="recap-root">
	<div class="recap-hero">
		<div class="recap-hero-eyebrow"><i class="fas fa-chart-line"></i>Amtgard ORK</div>
		<h1>Platform Trends</h1>
		<div class="recap-hero-sub">
			How many people use the ORK and play the game, week over week.
			Gaps mean a source wasn't available (or wasn't trusted) that week.
		</div>
	</div>

	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-users"></i></span> People visiting the ORK</h2>
		<p class="recap-digest recap-muted">
			Unique weekly visitors measured by Google Analytics — real browsers only; bots are excluded.
		</p>
		<div id="trends-visitors" class="trends-chart"></div>
		<details><summary>Data table</summary>
			<table class="trends-table"><tr><th>Week</th><th>Visitors</th></tr>
<?php foreach (array_reverse($_ts) as $_row) : if ($_row['Visitors'] === null) continue; ?>
				<tr><td><?=htmlspecialchars($_row['WeekStart'])?></td><td><?=number_format($_row['Visitors'])?></td></tr>
<?php endforeach; ?>
			</table>
		</details>
	</section>

<?php if ($_ss !== array()) : ?>
	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-sign-in-alt"></i></span> Signing in</h2>
		<p class="recap-digest recap-muted">
			Successful sign-ins per day, by kind of app — browsers, links opened inside
			apps like Discord, and community-built tools (mORK, jsork). Counted anonymously:
			the ORK keeps daily totals only, never who signed in.
		</p>
		<div id="trends-signins" class="trends-chart"></div>
		<details><summary>Data table (recent 30 days)</summary>
			<table class="trends-table"><tr><th>Day</th><th>Browsers &amp; other</th><th>In-app</th><th>Community apps</th><th>Total</th></tr>
<?php foreach (array_slice(array_reverse($_ss), 0, 30) as $_row) : ?>
				<tr><td><?=htmlspecialchars($_row['Day'])?></td><td><?=number_format($_row['Browsers'])?></td><td><?=number_format($_row['InApp'])?></td><td><?=number_format($_row['Apps'])?></td><td><?=number_format($_row['Total'])?></td></tr>
<?php endforeach; ?>
			</table>
		</details>
<?php $_av = isset($app_versions) && is_array($app_versions) ? $app_versions : array(); ?>
<?php if ($_av !== array()) : ?>
		<h3 class="trends-subhead">Community app versions right now</h3>
		<p class="recap-digest recap-muted">
			Currently active sessions per app version — how each community app's
			latest release is rolling out. Counts only, never who.
		</p>
		<table class="trends-table"><tr><th>App version</th><th>Sessions</th><th>Players</th></tr>
<?php foreach ($_av as $_row) : ?>
			<tr><td><?=htmlspecialchars($_row['Client'])?></td><td><?=number_format($_row['Sessions'])?></td><td><?=number_format($_row['Players'])?></td></tr>
<?php endforeach; ?>
		</table>
<?php endif; ?>
	</section>
<?php endif; ?>

	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-fist-raised"></i></span> Players on the field</h2>
		<p class="recap-digest recap-muted">
			Distinct players credited with attendance each week, across all recorded ORK history.
		</p>
		<div id="trends-players" class="trends-chart"></div>
		<details><summary>Data table (recent year)</summary>
			<table class="trends-table"><tr><th>Week</th><th>Players</th></tr>
<?php foreach (array_slice(array_reverse($_ps), 0, 52) as $_row) : ?>
				<tr><td><?=htmlspecialchars($_row['WeekStart'])?></td><td><?=number_format($_row['Players'])?></td></tr>
<?php endforeach; ?>
			</table>
		</details>
	</section>

	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-globe-americas"></i></span> ORK traffic and the bot wall (US + Canada)</h2>
		<p class="recap-digest recap-muted">
			Weekly requests Cloudflare delivered to ork.amtgard.com from US and Canadian
			clients, and the requests it blocked or challenged as malicious before they
			reached the site. Same scope on both lines.
		</p>
		<div id="trends-requests" class="trends-chart"></div>
		<details><summary>Data table</summary>
			<table class="trends-table"><tr><th>Week</th><th>Delivered</th><th>Blocked</th></tr>
<?php foreach (array_reverse($_ts) as $_row) : if ($_row['Requests'] === null) continue; ?>
				<tr><td><?=htmlspecialchars($_row['WeekStart'])?></td><td><?=$_fmt($_row['Requests'])?></td><td><?=$_fmt($_row['Blocked'])?></td></tr>
<?php endforeach; ?>
			</table>
		</details>
	</section>

	<section class="recap-section">
		<h2><span class="recap-section-icon"><i class="fas fa-globe"></i></span> All of amtgard.com, worldwide</h2>
		<p class="recap-digest recap-muted">
			The same measurement across every site sharing our Cloudflare zone — ORK, wiki,
			and everything else — with no country restriction. A different, larger scope
			than the chart above; shown on its own axis since the two aren't the same size.
			Starts 2026-08-27, when this breakdown was introduced.
		</p>
		<div id="trends-requests-global" class="trends-chart"></div>
		<details><summary>Data table</summary>
			<table class="trends-table"><tr><th>Week</th><th>Delivered</th><th>Blocked</th></tr>
<?php foreach (array_reverse($_ts) as $_row) : if (($_row['RequestsGlobal'] ?? null) === null) continue; ?>
				<tr><td><?=htmlspecialchars($_row['WeekStart'])?></td><td><?=$_fmt($_row['RequestsGlobal'])?></td><td><?=$_fmt($_row['BlockedGlobal'])?></td></tr>
<?php endforeach; ?>
			</table>
		</details>
	</section>

	<div class="recap-foot">
		Built from the <a href="<?=UIR?>Recap">Week in Review</a> archive · updates daily
	</div>
</div>

<script>
jQuery(document).ready(function() {
	if (typeof Highcharts === 'undefined') return;

	var VISITORS = <?=json_encode($_visitors)?>;
	var PLAYERS  = <?=json_encode($_players)?>;
	var REQUESTS = <?=json_encode($_requests)?>;
	var BLOCKED  = <?=json_encode($_blocked)?>;
	var REQUESTS_GLOBAL = <?=json_encode($_requests_global)?>;
	var BLOCKED_GLOBAL  = <?=json_encode($_blocked_global)?>;
	var SI_BROWSERS = <?=json_encode($_si_browsers)?>;
	var SI_INAPP    = <?=json_encode($_si_inapp)?>;
	var SI_APPS     = <?=json_encode($_si_apps)?>;

	function palette() {
		var dk = document.documentElement.getAttribute('data-theme') === 'dark';
		return {
			dk:      dk,
			blue:    dk ? '#3987e5' : '#2a78d6',
			orange:  dk ? '#d95926' : '#eb6834',
			green:   dk ? '#2f9e63' : '#1f8a52',
			text:    dk ? '#cbd5e0' : '#333',
			muted:   dk ? '#a0aec0' : '#666',
			grid:    dk ? '#2d3748' : '#e6e6e6',
			line:    dk ? '#4a5568' : '#ccd6eb',
			tipBg:   dk ? '#1a2035' : '#fff',
			tipText: dk ? '#f1f5f9' : '#333'
		};
	}

	function baseOptions(renderTo, p) {
		return {
			chart: { renderTo: renderTo, type: 'line', backgroundColor: 'transparent',
				style: { fontFamily: 'inherit' }, zoomType: 'x' },
			title: { text: null },
			credits: { enabled: false },
			xAxis: { type: 'datetime',
				labels: { style: { fontSize: '11px', color: p.muted } },
				lineColor: p.line, tickColor: p.line,
				crosshair: { color: p.grid } },
			yAxis: { title: { text: null }, min: 0,
				labels: { style: { color: p.muted } }, gridLineColor: p.grid },
			tooltip: { shared: true, xDateFormat: 'Week of %b %e, %Y',
				backgroundColor: p.tipBg, borderColor: p.line,
				style: { color: p.tipText, fontWeight: 'normal' } },
			plotOptions: { line: { lineWidth: 2, marker: { enabled: false, radius: 4,
				states: { hover: { enabled: true } } } } }
		};
	}

	var charts = [];
	function build() {
		while (charts.length) { charts.pop().destroy(); }
		var p = palette();

		var o1 = baseOptions('trends-visitors', p);
		o1.legend = { enabled: false };
		o1.series = [{ name: 'Visitors', data: VISITORS, color: p.blue }];
		charts.push(new Highcharts.Chart(o1));

		if (document.getElementById('trends-signins')) {
			var oS = baseOptions('trends-signins', p);
			// Daily series, unlike the weekly charts — say the actual day.
			oS.tooltip.xDateFormat = '%A, %b %e, %Y';
			oS.legend = { enabled: true, itemStyle: { color: p.text, fontWeight: 'normal' },
				itemHoverStyle: { color: p.text } };
			oS.series = [
				{ name: 'Browsers & other', data: SI_BROWSERS, color: p.blue },
				{ name: 'In-app links', data: SI_INAPP, color: p.green },
				{ name: 'Community apps', data: SI_APPS, color: p.orange }
			];
			charts.push(new Highcharts.Chart(oS));
		}

		var o2 = baseOptions('trends-players', p);
		o2.legend = { enabled: false };
		o2.series = [{ name: 'Players', data: PLAYERS, color: p.blue }];
		charts.push(new Highcharts.Chart(o2));

		var o3 = baseOptions('trends-requests', p);
		o3.legend = { enabled: true, itemStyle: { color: p.text, fontWeight: 'normal' },
			itemHoverStyle: { color: p.text } };
		o3.series = [
			{ name: 'Delivered', data: REQUESTS, color: p.blue },
			{ name: 'Blocked or challenged', data: BLOCKED, color: p.orange }
		];
		charts.push(new Highcharts.Chart(o3));

		var o4 = baseOptions('trends-requests-global', p);
		o4.legend = { enabled: true, itemStyle: { color: p.text, fontWeight: 'normal' },
			itemHoverStyle: { color: p.text } };
		o4.series = [
			{ name: 'Delivered', data: REQUESTS_GLOBAL, color: p.blue },
			{ name: 'Blocked or challenged', data: BLOCKED_GLOBAL, color: p.orange }
		];
		charts.push(new Highcharts.Chart(o4));
	}

	build();
	new MutationObserver(build).observe(document.documentElement, { attributeFilter: ['data-theme'] });
});
</script>
