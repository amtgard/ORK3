<?php
/* ── Player Recruitment Report ───────────────────────────────
   Controller: Controller_Reports::player_recruitment
   Data: rr_state ('no_scope' | 'no_access' | 'error' | 'ready'),
         rr_scope_type, rr_scope_id, rr_scope_name, rr_meta (source codes,
         award windows), rr_result (RecruitmentReport::Run response),
         rr_error, rr_filters, rr_kingdoms (no_scope only).
   Shared form controls and the [data-tip] tooltip (.rp-tips) live in reports.css.
   Plain PHP template. Charts: Apache ECharts (Apache-2.0), pinned on cdnjs. */
$rr_state      = (string)($rr_state ?? 'no_scope');
$rr_scope_type = $rr_scope_type ?? null;
$rr_scope_id   = (int)($rr_scope_id ?? 0);
$rr_scope_name = (string)($rr_scope_name ?? '');
$rr_meta       = is_array($rr_meta ?? null) ? $rr_meta : ['Codes' => [], 'NoneKey' => 'none', 'NoneLabel' => 'Not answered', 'AwardWindows' => [30], 'DefaultWindow' => 30];
$rr_result     = is_array($rr_result ?? null) ? $rr_result : null;
$rr_is_park    = ($rr_scope_type === 'Park');
$rr_f          = $rr_result['Filters'] ?? ($rr_filters ?? []);
$rr_start      = (string)($rr_f['StartDate'] ?? date('Y-m-d', strtotime('-12 months')));
$rr_end        = (string)($rr_f['EndDate'] ?? date('Y-m-d'));
$rr_fpark      = (int)($rr_f['FilterParkId'] ?? 0);
$rr_source     = (string)($rr_f['Source'] ?? '');
$rr_window     = (int)($rr_f['AwardWindow'] ?? ($rr_meta['DefaultWindow'] ?? 30));
$rr_answers    = ($rr_f['Answers'] ?? 'all') === 'registration' ? 'registration' : 'all';
$rr_parks      = $rr_result['Parks'] ?? [];
$rr_scope_link = $rr_scope_type ? UIR . ($rr_is_park ? 'Park' : 'Kingdom') . '/profile/' . $rr_scope_id : '';
$rr_back_link  = $rr_scope_link !== '' ? $rr_scope_link . '&tab=reports' : UIR . 'Admin';
$rr_stats      = $rr_result['Stats'] ?? [];
$rr_n          = (int)($rr_stats['NewPlayers'] ?? 0);
$rr_rules      = $rr_result['Rules'] ?? [];
$rr_min_n      = (int)($rr_rules['MinN'] ?? 10);
$rr_max_years  = (int)($rr_rules['MaxYears'] ?? 5);
$rr_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

$rr_pct = function ($v) {
	return $v === null ? '&mdash;' : rtrim(rtrim(number_format((float)$v, 1), '0'), '.') . '%';
};
$rr_date = function ($d) {
	return ($d && $d !== '0000-00-00') ? date('M j, Y', strtotime($d)) : '&mdash;';
};
$rr_rate = function ($pct, $n) use ($rr_pct, $rr_min_n) {
	// "42%" with the eligible n beneath; muted with "too few" under the minimum n;
	// a dash when nobody is eligible yet.
	$n = (int)$n;
	if ($n === 0 || $pct === null) {
		return '<span class="prr-muted">&mdash;</span>';
	}
	if ($n < $rr_min_n) {
		return '<span class="prr-few">' . $rr_pct($pct) . '</span><span class="prr-n">n=' . number_format($n) . ', too few</span>';
	}
	return $rr_pct($pct) . '<span class="prr-n">n=' . number_format($n) . '</span>';
};
$rr_ret = function ($r) use ($rr_rate) {
	return is_array($r) ? $rr_rate($r['Pct'], $r['Eligible']) : '<span class="prr-muted">&mdash;</span>';
};
// DataTables sort key: the percentage, or '' (sorted last either way) when there is none.
$rr_ord = function ($pct) {
	return $pct === null ? '' : (string)(float)$pct;
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(__DIR__ . '/../default/style/reports.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/ork-datatables.css?v=<?= filemtime(__DIR__ . '/style/ork-datatables.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

<style>
/* Page-scoped layout. Form controls and the tooltip come from reports.css. */
.prr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.prr-main .rp-chart-card { min-width: 0; }
.prr-root .rp-chart-card-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.prr-root h2.rp-chart-card-title { background: none; border: 0; padding: 0; }
.prr-sub { font-size: 12px; color: var(--rp-text-muted); margin: -4px 0 10px; line-height: 1.45; }
.prr-chart { width: 100%; height: 320px; }
.prr-chart-sources { height: 340px; }
.prr-sankey-scroll { overflow-x: auto; overscroll-behavior-x: contain; }
.prr-sankey-scroll .prr-chart { min-width: 640px; height: 420px; }
.prr-sankey-wrap { position: relative; }
.prr-scroll-hint { display: none; font-size: 11px; color: var(--rp-text-muted); text-align: right; margin: 0 0 4px; }
.prr-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.prr-toolbar label { font-size: 12px; font-weight: 600; color: var(--rp-text-body); }
.prr-toolbar select { max-width: 100%; }

.prr-dt.rp-table-area { flex: none; border: 0; padding: 0; box-shadow: none; border-radius: 0; --rp-bg-table: transparent; }
.prr-dt table.dataTable { font-size: 13px; }
.prr-dt table.dataTable th.prr-num, .prr-dt table.dataTable td.prr-num { text-align: right; white-space: nowrap; }
.prr-dt table.dataTable tbody td:first-child { min-width: 150px; }
.prr-dt table.dataTable tfoot td { font-weight: 700; color: var(--rp-text); font-size: 13px; }
.prr-dt table.dataTable tbody tr.prr-zero td { color: var(--rp-text-hint); }
.prr-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 7px; vertical-align: -1px; }
.prr-n { display: block; font-size: 10px; color: var(--rp-text-hint); font-weight: 400; }
.prr-muted { color: var(--rp-text-hint); }
.prr-few { color: var(--rp-text-hint); font-style: italic; }
.prr-later { display: inline-block; margin-left: 6px; font-size: 10px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; color: var(--rp-text-muted); border: 1px solid var(--rp-border-mid); border-radius: 3px; padding: 0 4px; vertical-align: 1px; }
.prr-caption { font-size: 12px; color: var(--rp-text-muted); font-style: italic; margin: 10px 0 0; }

.prr-state { text-align: center; padding: 48px 20px; color: var(--rp-text-muted); }
.prr-state i { font-size: 30px; opacity: 0.45; display: block; margin-bottom: 12px; }
.prr-state h2 { font-size: 18px; color: var(--rp-text); margin: 0 0 8px; background: none; border: 0; padding: 0; }
.prr-state p { margin: 0 auto; max-width: 460px; font-size: 14px; line-height: 1.5; }
.prr-state form { margin: 18px auto 0; max-width: 320px; display: flex; gap: 8px; }
.prr-state form .rp-btn-run { width: auto; padding: 8px 16px; }

.prr-root .rp-stat-card[data-tip] { cursor: help; }

.prr-about dt { font-weight: 700; margin-top: 8px; color: var(--rp-text); }
.prr-about dd { margin: 2px 0 0; color: var(--rp-text-muted); }

.prr-detail.rp-table-area { flex: none; }
.prr-detail table.dataTable td.prr-num { text-align: right; }
.prr-yes { color: #0f7a3d; font-weight: 600; }
html[data-theme="dark"] .prr-yes { color: #68d391; }
html[data-theme="dark"] .prr-later { border-color: #4a5568; }

@media (max-width: 900px) {
	.prr-main { width: 100%; }
}
@media (max-width: 600px) {
	.prr-chart { height: 280px; }
	.prr-chart-sources { height: 340px; }
	.prr-state form { flex-direction: column; }
	.prr-scroll-hint { display: block; }
	/* Fade the right edge so it reads as "more this way". */
	.prr-sankey-wrap::after { content: ''; position: absolute; top: 0; right: 0; bottom: 0; width: 28px; pointer-events: none;
		background: linear-gradient(to right, transparent, var(--ork-card-bg, #fff)); }
}
</style>

<div class="rp-root rp-tips prr-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-seedling rp-header-icon"></i>
				<h1 class="rp-header-title">Player Recruitment</h1>
			</div>
<?php if ($rr_scope_name !== '' && $rr_state !== 'no_access') : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?= htmlspecialchars($rr_scope_link) ?>">
					<i class="fas <?= $rr_is_park ? 'fa-tree' : 'fa-chess-rook' ?>"></i>
					<span class="rp-scope-chip-label"><?= $rr_is_park ? 'Park' : 'Kingdom' ?>:</span> <?= htmlspecialchars($rr_scope_name) ?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<a class="rp-btn-ghost" href="<?= htmlspecialchars($rr_back_link) ?>"><i class="fas fa-arrow-left"></i> Back</a>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Where new players heard about Amtgard (&ldquo;How did you find us?&rdquo;) and how each group stuck around: return visits, retention at 30&ndash;180 days, dues, and early awards.</span>
	</div>

<?php if ($rr_state === 'no_scope') : ?>
	<div class="rp-chart-card prr-state">
		<i class="fas fa-chess-rook"></i>
		<h2>Pick a kingdom</h2>
		<p>This report runs for one kingdom or park. Open it from a kingdom or park&rsquo;s Reports tab, or choose a kingdom here.</p>
<?php if (!empty($rr_kingdoms)) : ?>
		<form method="get" action="<?= UIR ?>Reports/player_recruitment">
			<input type="hidden" name="Route" value="Reports/player_recruitment">
			<select name="KingdomId" class="rp-form-select" aria-label="Kingdom">
<?php foreach ($rr_kingdoms as $k) : ?>
				<option value="<?= (int)$k['KingdomId'] ?>"><?= htmlspecialchars(stripslashes((string)$k['KingdomName'])) ?></option>
<?php endforeach; ?>
			</select>
			<button type="submit" class="rp-btn-run">Open</button>
		</form>
<?php endif; ?>
	</div>
<?php elseif ($rr_state === 'no_access') : ?>
	<div class="rp-chart-card prr-state">
		<i class="fas fa-lock"></i>
		<h2>Officers only</h2>
		<p>The Player Recruitment report is open to officers of this <?= $rr_is_park ? 'park (or its kingdom)' : 'kingdom' ?> and to ORK admins, because it lists individual new players. If you think you should have access, ask your Prime Minister.</p>
	</div>
<?php else : ?>

<?php if ($rr_state === 'ready') : ?>
	<div class="rp-stats-row">
		<div class="rp-stat-card" tabindex="0" data-tip="Players whose first-ever ORK sign-in falls in the date range, at a park in this scope. Same count as the New Player Attendance report.">
			<div class="rp-stat-icon"><i class="fas fa-user-plus"></i></div>
			<div class="rp-stat-number"><?= number_format($rr_n) ?></div>
			<div class="rp-stat-label">New players</div>
		</div>
		<div class="rp-stat-card" tabindex="0" data-tip="Share of these new players with a &ldquo;How did you find us?&rdquo; answer on file, split by when it was given: at registration, or added later from the Account modal.">
			<div class="rp-stat-icon"><i class="fas fa-comment-dots"></i></div>
			<div class="rp-stat-number"><?= $rr_pct($rr_stats['AnsweredPct'] ?? null) ?></div>
			<div class="rp-stat-label">Source answered</div>
			<div class="rp-stat-hint"><?= number_format((int)($rr_stats['Answered'] ?? 0)) ?> of <?= number_format($rr_n) ?>: <?= number_format((int)($rr_stats['AnsweredReg'] ?? 0)) ?> at registration, <?= number_format((int)($rr_stats['AnsweredLater'] ?? 0)) ?> added later</div>
		</div>
		<div class="rp-stat-card" tabindex="0" data-tip="Signed in again on a different day within 30 days of the first sign-in, anywhere in the ORK. Only players whose first sign-in was 30 or more days ago are counted; newer players are &ldquo;not back yet&rdquo;.">
			<div class="rp-stat-icon"><i class="fas fa-redo-alt"></i></div>
			<div class="rp-stat-number"><?= $rr_pct($rr_stats['BackPct'] ?? null) ?></div>
			<div class="rp-stat-label">Came back</div>
			<div class="rp-stat-hint"><?= number_format((int)($rr_stats['Back'] ?? 0)) ?> of <?= number_format((int)($rr_stats['BackElig'] ?? 0)) ?> eligible</div>
		</div>
<?php $d90 = $rr_stats['Day90'] ?? ['Eligible' => 0, 'Pct' => null, 'TooRecent' => 0]; ?>
		<div class="rp-stat-card rp-tip-right" tabindex="0" data-tip="Signed in at least once between day 90 and day 120 after the first sign-in. Only players whose day 120 has passed are counted; <?= number_format((int)$d90['TooRecent']) ?> are too recent to tell.">
			<div class="rp-stat-icon"><i class="fas fa-calendar-check"></i></div>
			<div class="rp-stat-number"><?= $rr_pct($d90['Pct']) ?></div>
			<div class="rp-stat-label">Still playing at day 90</div>
			<div class="rp-stat-hint">of <?= number_format((int)$d90['Eligible']) ?> eligible</div>
		</div>
	</div>
<?php endif; ?>

	<div class="rp-body">
		<aside class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sliders-h"></i> Filters</div>
				<div class="rp-filter-card-body">
					<form method="get" action="<?= UIR ?>Reports/player_recruitment" class="rp-param-form" id="prr-form">
						<input type="hidden" name="Route" value="Reports/player_recruitment">
						<input type="hidden" name="<?= $rr_is_park ? 'ParkId' : 'KingdomId' ?>" value="<?= $rr_scope_id ?>">
						<div class="rp-form-group">
							<label for="prr-start">First sign-in from</label>
							<input type="text" id="prr-start" name="StartDate" class="rp-form-input" value="<?= htmlspecialchars($rr_start) ?>">
						</div>
						<div class="rp-form-group">
							<label for="prr-end">Through</label>
							<input type="text" id="prr-end" name="EndDate" class="rp-form-input" value="<?= htmlspecialchars($rr_end) ?>">
						</div>
<?php if (!$rr_is_park) : ?>
						<div class="rp-form-group">
							<label for="prr-park">Park</label>
							<select id="prr-park" name="FilterParkId" class="rp-form-select">
								<option value="0">All parks</option>
<?php foreach ($rr_parks as $p) : ?>
								<option value="<?= (int)$p['ParkId'] ?>"<?= $rr_fpark === (int)$p['ParkId'] ? ' selected' : '' ?>><?= htmlspecialchars($p['Name']) ?></option>
<?php endforeach; ?>
							</select>
						</div>
<?php endif; ?>
						<div class="rp-form-group">
							<label for="prr-source">How they found us</label>
							<select id="prr-source" name="Source" class="rp-form-select">
								<option value="">All sources</option>
<?php foreach ($rr_meta['Codes'] as $code => $label) : ?>
								<option value="<?= htmlspecialchars($code) ?>"<?= $rr_source === $code ? ' selected' : '' ?>><?= htmlspecialchars($label) ?></option>
<?php endforeach; ?>
								<option value="<?= htmlspecialchars($rr_meta['NoneKey']) ?>"<?= $rr_source === $rr_meta['NoneKey'] ? ' selected' : '' ?>><?= htmlspecialchars($rr_meta['NoneLabel']) ?></option>
							</select>
						</div>
						<div class="rp-form-group">
							<label for="prr-answers">Answers</label>
							<select id="prr-answers" name="Answers" class="rp-form-select">
								<option value="all"<?= $rr_answers === 'all' ? ' selected' : '' ?>>All answers</option>
								<option value="registration"<?= $rr_answers === 'registration' ? ' selected' : '' ?>>At registration only</option>
							</select>
						</div>
						<div class="rp-form-group">
							<label for="prr-window">Early award window</label>
							<select id="prr-window" name="AwardWindow" class="rp-form-select">
<?php foreach ($rr_meta['AwardWindows'] as $w) : ?>
								<option value="<?= (int)$w ?>"<?= $rr_window === (int)$w ? ' selected' : '' ?>>First <?= (int)$w ?> days</option>
<?php endforeach; ?>
							</select>
						</div>
						<button type="submit" class="rp-btn-run" id="prr-run"><i class="fas fa-play"></i> Run report</button>
					</form>
				</div>
			</div>

			<div class="rp-filter-card">
				<details>
					<summary class="rp-filter-card-header" style="cursor:pointer;"><i class="fas fa-book-open"></i> How this is counted</summary>
					<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;">
						<dl class="prr-about" style="margin:0;">
							<dt>New player</dt>
							<dd>First-ever ORK sign-in falls in the date range, with no sign-in anywhere before it. Credited to the park New Player Attendance credits them to, so the two reports agree. Ranges can cover up to <?= $rr_max_years ?> years.</dd>
							<dt>Came back</dt>
							<dd>Signed in again, on a different day and anywhere in the ORK, within 30 days of the first sign-in. Players whose first sign-in was less than 30 days ago are &ldquo;not back yet&rdquo; and left out of the percentage.</dd>
							<dt>Still playing at day N</dt>
							<dd>At least one sign-in between day N and day N+30 after the first one (for day 90: days 90&ndash;119). One late visit a year on doesn&rsquo;t count as playing at every milestone in between.</dd>
							<dt>Too recent</dt>
							<dd>Day N+30 hasn&rsquo;t arrived yet, so it&rsquo;s too early to tell. These players are left out of that day&rsquo;s percentage instead of being counted as dropped.</dd>
							<dt>Return funnel</dt>
							<dd>Reached their 2nd, 3rd, 5th&hellip; day of play within 180 days of the first. Only players whose first 180 days are over are counted.</dd>
							<dt>Dues paid</dt>
							<dd>A dues payment recorded within 90 days of the first sign-in. Only players whose first 90 days are over are counted. (The player list shows any payment since.)</dd>
							<dt>Early award</dt>
							<dd>Any award (not revoked) dated within the chosen window after the first sign-in.</dd>
							<dt>Too few</dt>
							<dd>Percentages from fewer than <?= $rr_min_n ?> players are greyed out: one or two people swing them too far to compare.</dd>
							<dt>Answers</dt>
							<dd>&ldquo;At registration only&rdquo; counts an answer only if it was given when the player signed up; answers added later count as Not answered.</dd>
						</dl>
					</div>
				</details>
			</div>
		</aside>

		<div class="prr-main">
<?php if ($rr_state === 'error') : ?>
			<div class="rp-chart-card prr-state">
				<i class="fas fa-triangle-exclamation"></i>
				<h2>The report didn&rsquo;t run</h2>
				<p><?= htmlspecialchars((string)($rr_error ?? '')) ?></p>
			</div>
<?php elseif ($rr_n === 0) : ?>
			<div class="rp-chart-card prr-state">
				<i class="fas fa-user-slash"></i>
				<h2>No new players</h2>
				<p>Nobody had their first-ever sign-in in this scope between <?= $rr_date($rr_start) ?> and <?= $rr_date($rr_end) ?><?= $rr_source !== '' || $rr_fpark ? ' with these filters' : '' ?>. Try a wider date range.</p>
			</div>
<?php else : ?>
<?php
	$sources = $rr_result['Sources'];
	$overall = $rr_result['Overall'];
	$ea = $rr_result['EarlyAward'];
?>
			<section class="rp-chart-card" aria-labelledby="prr-h-sources">
				<h2 class="rp-chart-card-title" id="prr-h-sources"><i class="fas fa-compass"></i> Where they came from</h2>
				<div id="prr-chart-sources" class="prr-chart prr-chart-sources" role="img" aria-label="New players by recruitment source; the table below has the same numbers"></div>
				<div class="rp-table-area prr-dt">
					<table id="prr-sources-table" class="display" style="width:100%">
						<thead><tr>
							<th>Source</th><th class="prr-num">New players</th><th class="prr-num">% of total</th><th class="prr-num">Came back</th>
							<th class="prr-num">Day 30</th><th class="prr-num">Day 90</th><th class="prr-num">Day 180</th><th class="prr-num">Dues paid</th>
						</tr></thead>
						<tbody>
<?php foreach ($sources as $s) : ?>
							<tr class="<?= $s['N'] === 0 ? 'prr-zero' : '' ?>">
								<td><span class="prr-swatch" data-src="<?= htmlspecialchars($s['Key']) ?>"></span><?= htmlspecialchars($s['Label']) ?></td>
								<td class="prr-num" data-order="<?= (int)$s['N'] ?>"><?= number_format($s['N']) ?></td>
								<td class="prr-num" data-order="<?= $rr_ord($s['PctOfTotal']) ?>"><?= $rr_pct($s['PctOfTotal']) ?></td>
								<td class="prr-num" data-order="<?= $rr_ord($s['BackPct']) ?>"><?= $rr_rate($s['BackPct'], $s['BackElig']) ?></td>
<?php foreach ([30, 90, 180] as $m) : ?>
								<td class="prr-num" data-order="<?= $rr_ord($s['Retention'][$m]['Pct']) ?>"><?= $rr_ret($s['Retention'][$m]) ?></td>
<?php endforeach; ?>
								<td class="prr-num" data-order="<?= $rr_ord($s['DuesPct']) ?>"><?= $rr_rate($s['DuesPct'], $s['DuesElig']) ?></td>
							</tr>
<?php endforeach; ?>
						</tbody>
						<tfoot><tr>
							<td>All new players</td>
							<td class="prr-num"><?= number_format($overall['N']) ?></td>
							<td class="prr-num">100%</td>
							<td class="prr-num"><?= $rr_rate($overall['BackPct'], $overall['BackElig']) ?></td>
<?php foreach ([30, 90, 180] as $m) : ?>
							<td class="prr-num"><?= $rr_ret($overall['Retention'][$m]) ?></td>
<?php endforeach; ?>
							<td class="prr-num"><?= $rr_rate($overall['DuesPct'], $overall['DuesElig']) ?></td>
						</tr></tfoot>
					</table>
				</div>
				<p class="prr-caption">Each percentage counts only players whose window is over (n shown); greyed values come from fewer than <?= $rr_min_n ?> players.</p>
				<p class="prr-caption"><?php if ($rr_answers === 'registration') : ?>Showing answers given at registration only; answers added later count as Not answered here.<?php else : ?>Answers added later from the Account modal mostly come from players who stuck around, so they make a source&rsquo;s retention look better than it is. For the fairest comparison between sources, set Answers to &ldquo;At registration only&rdquo;.<?php endif; ?></p>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-monthly">
				<h2 class="rp-chart-card-title" id="prr-h-monthly"><i class="fas fa-chart-column"></i> New players by month</h2>
				<div id="prr-chart-monthly" class="prr-chart" role="img" aria-label="New players per month, stacked by recruitment source"></div>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-flow">
				<h2 class="rp-chart-card-title" id="prr-h-flow"><i class="fas fa-water"></i> Retention flow</h2>
				<p class="prr-sub">Each band is a group of players, and each stage follows only the players still playing at the one before (a sign-in between day N and N+30), so stage counts run lower than the standalone day-N percentages. &ldquo;Not back yet&rdquo; and &ldquo;too recent&rdquo; players haven&rsquo;t had the time yet; they are not drop-offs.</p>
				<div class="prr-toolbar">
					<label for="prr-flow-source">Show</label>
					<select id="prr-flow-source" class="rp-form-select" style="width:auto;">
						<option value="all">All new players (<?= number_format($rr_n) ?>)</option>
<?php foreach ($sources as $s) : if ($s['N'] === 0) continue; ?>
						<option value="<?= htmlspecialchars($s['Key']) ?>"><?= htmlspecialchars($s['Label']) ?> (<?= number_format($s['N']) ?><?= $s['N'] < $rr_min_n ? ', too few to compare' : '' ?>)</option>
<?php endforeach; ?>
					</select>
				</div>
				<p class="prr-scroll-hint" aria-hidden="true">Scroll sideways for later stages <i class="fas fa-arrow-right"></i></p>
				<div class="prr-sankey-wrap">
					<div class="prr-sankey-scroll">
						<div id="prr-chart-sankey" class="prr-chart" role="img" aria-label="Retention flow from first sign-in through day 180"></div>
					</div>
				</div>
			</section>

<?php $fn = $rr_result['Funnel']; ?>
			<section class="rp-chart-card" aria-labelledby="prr-h-funnel">
				<h2 class="rp-chart-card-title" id="prr-h-funnel"><i class="fas fa-filter"></i> Return funnel</h2>
				<p class="prr-sub">How many new players reached their 2nd, 3rd, 5th, 10th and 25th day of play within <?= (int)$fn['Days'] ?> days of the first. Counts only the <?= number_format((int)$fn['Eligible']) ?> players whose first <?= (int)$fn['Days'] ?> days are over<?= (int)$fn['TooRecent'] > 0 ? '; ' . number_format((int)$fn['TooRecent']) . ' are too recent' : '' ?>.</p>
<?php if ((int)$fn['Eligible'] === 0) : ?>
				<p class="prr-caption">Nobody in this range is <?= (int)$fn['Days'] ?> days past their first sign-in yet. Pick an earlier start date to see the funnel.</p>
<?php else : ?>
				<div class="rp-table-area prr-dt">
					<table id="prr-funnel-table" class="display prr-static" style="width:100%">
						<thead><tr><th>Reached</th><th class="prr-num">Players</th><th class="prr-num">% of eligible</th></tr></thead>
						<tbody>
							<tr><td>1st sign-in</td><td class="prr-num"><?= number_format((int)$fn['Eligible']) ?></td><td class="prr-num">100%</td></tr>
<?php foreach ($fn['Steps'] as $f) : ?>
<?php $ord = [2 => '2nd', 3 => '3rd', 5 => '5th', 10 => '10th', 25 => '25th'][$f['Reached']] ?? ($f['Reached'] . 'th'); ?>
							<tr><td><?= $ord ?> sign-in</td><td class="prr-num"><?= number_format($f['N']) ?></td><td class="prr-num"><?= $rr_rate($f['Pct'], $fn['Eligible']) ?></td></tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
<?php endif; ?>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-award">
				<h2 class="rp-chart-card-title" id="prr-h-award"><i class="fas fa-award"></i> Early awards and retention</h2>
				<p class="prr-sub">New players who received any award in their first <?= (int)$ea['Window'] ?> days, against those who didn&rsquo;t. Both groups only include players who still signed in on or after day <?= (int)$ea['Window'] ?>, and only later milestones are compared.<?php if ((int)$ea['TooRecent'] > 0) : ?> <?= number_format((int)$ea['TooRecent']) ?> players are left out because their first <?= (int)$ea['Window'] ?> days aren&rsquo;t over yet<?php if ((int)$ea['Inactive'] > 0) : ?>, and <?= number_format((int)$ea['Inactive']) ?> because they stopped before day <?= (int)$ea['Window'] ?><?php endif; ?>.<?php elseif ((int)$ea['Inactive'] > 0) : ?> <?= number_format((int)$ea['Inactive']) ?> players are left out because they stopped before day <?= (int)$ea['Window'] ?>.<?php endif; ?></p>
				<div id="prr-chart-award" class="prr-chart" role="img" aria-label="Retention at day 30, 60, 90 and 180 for players with and without an early award"></div>
				<div class="rp-table-area prr-dt">
					<table id="prr-award-table" class="display prr-static" style="width:100%">
						<thead><tr><th>Group</th><th class="prr-num">Players</th><th class="prr-num">Day 30</th><th class="prr-num">Day 60</th><th class="prr-num">Day 90</th><th class="prr-num">Day 180</th></tr></thead>
						<tbody>
<?php foreach (['With' => 'Award in first ' . (int)$ea['Window'] . ' days', 'Without' => 'No early award'] as $gk => $gl) : $g = $ea[$gk]; ?>
							<tr>
								<td><?= htmlspecialchars($gl) ?></td>
								<td class="prr-num"><?= number_format($g['N']) ?></td>
<?php foreach ([30, 60, 90, 180] as $m) : ?>
								<td class="prr-num"><?= !empty($ea['Comparable'][$m]) ? $rr_ret($g['Retention'][$m]) : '<span class="prr-muted rp-tip-right" tabindex="0" data-tip="Day ' . $m . ' falls inside the ' . (int)$ea['Window'] . '-day award window, so the groups can&rsquo;t be compared fairly here.">in window</span>' ?></td>
<?php endforeach; ?>
							</tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="prr-caption">Someone awarded on day 20 was by definition still around on day 20, so milestones inside the award window would compare people who stayed with people who may not have; those are greyed out. Even the later milestones show correlation, not proof of cause: players who were going to stay anyway may also be the ones who get noticed early.</p>
			</section>

			<section class="rp-table-area prr-detail" aria-labelledby="prr-h-detail">
				<h2 class="rp-chart-card-title" id="prr-h-detail"><i class="fas fa-list"></i> New players</h2>
				<table id="prr-detail-table" class="display" style="width:100%">
					<thead><tr>
						<th>Persona</th><th>Park</th><th>First sign-in</th><th>How they found us</th>
						<th>Sign-ins</th><th>Last sign-in</th><th>First award</th><th>Dues paid</th>
					</tr></thead>
				</table>
			</section>
<?php endif; ?>
		</div>
	</div>
<?php endif; ?>
</div>

<script src="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.js"></script>
<script>
(function () {
	var form0 = document.getElementById('prr-start');
	if (typeof flatpickr === 'function' && form0) {
		// The range may cover at most MAX_YEARS (the server enforces it too): each
		// picker's bounds follow the other's value.
		var MAX_YEARS = <?= (int)$rr_max_years ?>;
		var today = new Date(); today.setHours(0, 0, 0, 0);
		function addYears(d, n) { var x = new Date(d.getTime()); x.setFullYear(x.getFullYear() + n); return x; }
		var fpS, fpE;
		function sync() {
			if (!fpS || !fpE) return;
			var s = fpS.selectedDates[0], e = fpE.selectedDates[0];
			fpS.set('maxDate', e || today);
			fpS.set('minDate', addYears(e || today, -MAX_YEARS));
			fpE.set('minDate', s || null);
			var cap = s ? addYears(s, MAX_YEARS) : today;
			fpE.set('maxDate', cap < today ? cap : today);
		}
		var opts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'M j, Y', altInputClass: 'rp-form-input', maxDate: 'today', onChange: function () { sync(); } };
		fpS = flatpickr('#prr-start', opts);
		fpE = flatpickr('#prr-end', opts);
		sync();
	}
	var form = document.getElementById('prr-form');
	if (form) {
		form.addEventListener('submit', function () {
			var b = document.getElementById('prr-run');
			if (b) { b.disabled = true; b.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Running&hellip;'; }
		});
	}
})();
</script>

<?php if ($rr_state === 'ready' && $rr_n > 0) : ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/echarts/5.6.0/echarts.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
window.PrrData = <?= json_encode([
	'sources'  => array_map(function ($s) { return ['key' => $s['Key'], 'label' => $s['Label'], 'short' => $s['Short'], 'n' => $s['N']]; }, $rr_result['Sources']),
	'minN'     => $rr_min_n,
	'monthly'  => $rr_result['Monthly'],
	'sankey'   => $rr_result['Sankey'],
	'award'    => $rr_result['EarlyAward'],
	'players'  => $rr_result['Players'],
	'playerUrl' => UIR . 'Player/profile/',
], $rr_json_flags) ?>;
(function () {
	var D = window.PrrData;
	if (!D || typeof echarts === 'undefined') return;

	// Categorical slots in fixed order (validated palette), one per source code; the
	// "not answered" bucket is a neutral gray so it never reads as a ninth source.
	var CAT = {
		light: ['#2a78d6', '#eb6834', '#1baf7a', '#eda100', '#e87ba4', '#008300', '#6250d6', '#e34948'],
		dark:  ['#3987e5', '#d95926', '#199e70', '#c98500', '#d55181', '#008300', '#9085e9', '#e66767']
	};
	function isDark() { return document.documentElement.getAttribute('data-theme') === 'dark'; }
	function theme() {
		var d = isDark();
		return {
			dark: d,
			text: d ? '#e2e8f0' : '#2d3748',
			muted: d ? '#a0aec0' : '#718096',
			grid: d ? '#3a4556' : '#e2e8f0',
			surface: d ? '#2d3748' : '#ffffff',
			tipBg: d ? '#111827' : '#ffffff',
			none: d ? '#6b7280' : '#a0aec0'
		};
	}
	function sourceColor(key, t) {
		var idx = D.sources.findIndex(function (s) { return s.key === key; });
		if (key === 'none' || idx < 0) return t.none;
		return (t.dark ? CAT.dark : CAT.light)[idx % 8];
	}
	function sourceBy(key) { return D.sources.find(function (s) { return s.key === key; }) || { label: key, short: key }; }
	function tooltipBase(t) {
		return { backgroundColor: t.tipBg, borderColor: t.grid, textStyle: { color: t.text, fontSize: 12 }, confine: true };
	}
	function esc(s) { return String(s).replace(/[&<>"']/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]; }); }

	var charts = {};
	function mount(id, build) {
		var el = document.getElementById(id);
		if (!el) return;
		if (charts[id]) charts[id].dispose();
		var c = echarts.init(el, null, { renderer: 'svg' });
		c.setOption(build(theme()));
		charts[id] = c;
	}

	function buildSources(t) {
		var rows = D.sources.slice().reverse(); // top of the chart = first source
		// Short labels on the axis (full ones don't fit on a phone); full label in the tooltip.
		return {
			animation: false,
			grid: { left: 8, right: 40, top: 8, bottom: 8, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'item', formatter: function (p) { return esc(p.data.full) + '<br><b>' + p.value + '</b> new players'; } }),
			xAxis: { type: 'value', minInterval: 1, axisLabel: { color: t.muted }, splitLine: { lineStyle: { color: t.grid } } },
			yAxis: { type: 'category', data: rows.map(function (s) { return s.short; }), axisTick: { show: false },
				axisLine: { lineStyle: { color: t.grid } },
				axisLabel: { color: t.text } },
			series: [{ type: 'bar', barMaxWidth: 18,
				data: rows.map(function (s) { return { value: s.n, name: s.short, full: s.label, itemStyle: { color: sourceColor(s.key, t), borderRadius: [0, 4, 4, 0] } }; }),
				label: { show: true, position: 'right', color: t.muted, fontSize: 11 } }]
		};
	}

	function fmtMonth(ym) {
		var p = ym.split('-');
		return new Date(+p[0], +p[1] - 1, 1).toLocaleDateString(undefined, { month: 'short', year: 'numeric' });
	}
	function buildMonthly(t) {
		var m = D.monthly;
		var series = D.sources.filter(function (s) { return s.n > 0; }).map(function (s) {
			return { name: s.short, type: 'bar', stack: 'src', barMaxWidth: 28, data: m.Series[s.key],
				itemStyle: { color: sourceColor(s.key, t), borderColor: t.surface, borderWidth: 1 }, emphasis: { focus: 'series' } };
		});
		return {
			animation: false,
			legend: { type: 'scroll', bottom: 0, textStyle: { color: t.text, fontSize: 11 }, pageTextStyle: { color: t.muted } },
			grid: { left: 8, right: 12, top: 12, bottom: 44, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'axis', axisPointer: { type: 'shadow' },
				formatter: function (ps) {
					var tot = 0, lines = ps.filter(function (p) { return p.value > 0; }).map(function (p) { tot += p.value; return p.marker + esc(p.seriesName) + ': <b>' + p.value + '</b>'; });
					return '<b>' + esc(fmtMonth(ps[0].axisValue)) + '</b> &middot; ' + tot + ' new<br>' + lines.join('<br>');
				} }),
			xAxis: { type: 'category', data: m.Months, axisLabel: { color: t.muted, formatter: fmtMonth, hideOverlap: true }, axisLine: { lineStyle: { color: t.grid } }, axisTick: { show: false } },
			yAxis: { type: 'value', minInterval: 1, axisLabel: { color: t.muted }, splitLine: { lineStyle: { color: t.grid } } },
			series: series
		};
	}

	function nodeColor(name, t) {
		if (name === 'New players' || name === 'Came back') return t.dark ? '#3987e5' : '#2a78d6';
		if (/still playing/.test(name)) return t.dark ? '#199e70' : '#1baf7a';
		if (/too recent|not back yet/i.test(name)) return t.dark ? '#c98500' : '#eda100';
		return t.none; // lapsed / never came back
	}
	function buildSankey(t) {
		var key = (document.getElementById('prr-flow-source') || {}).value || 'all';
		var links = D.sankey[key] || [];
		var names = [];
		links.forEach(function (l) { [l.source, l.target].forEach(function (n) { if (names.indexOf(n) < 0) names.push(n); }); });
		var totals = {};
		links.forEach(function (l) { if (l.source === 'New players') totals.root = (totals.root || 0) + l.value; });
		return {
			animation: false,
			tooltip: Object.assign(tooltipBase(t), { trigger: 'item', formatter: function (p) {
				if (p.dataType === 'edge') return esc(p.data.source) + ' &rarr; ' + esc(p.data.target) + '<br><b>' + p.data.value + '</b> players';
				return esc(p.name) + '<br><b>' + p.value + '</b> players' + (totals.root ? ' (' + Math.round(p.value * 1000 / totals.root) / 10 + '% of new)' : '');
			} }),
			series: [{
				type: 'sankey', nodeAlign: 'left', left: 8, right: 150, top: 10, bottom: 10, nodeGap: 12, nodeWidth: 14, layoutIterations: 0,
				draggable: false, emphasis: { focus: 'adjacency' },
				data: names.map(function (n) { return { name: n, itemStyle: { color: nodeColor(n, t), borderWidth: 0 } }; }),
				links: links,
				lineStyle: { color: 'gradient', opacity: t.dark ? 0.35 : 0.3, curveness: 0.5 },
				label: { color: t.text, fontSize: 11, formatter: function (p) { return p.name + '  ' + p.value; } }
			}]
		};
	}

	function buildAward(t) {
		var a = D.award, ms = [30, 60, 90, 180], minN = D.minN;
		var cols = t.dark ? CAT.dark : CAT.light;
		// Milestones inside the award window aren't compared (landmark analysis); groups
		// under minN draw greyed and hatched with "n=X, too few".
		function pts(g) {
			return ms.map(function (m) {
				var r = g.Retention[m];
				if (!a.Comparable[m]) return { value: null, inWindow: true };
				var few = r.Eligible > 0 && r.Eligible < minN;
				var d = { value: r.Pct, n: r.Eligible, playing: r.Playing, few: few };
				if (few) d.itemStyle = { color: t.none, opacity: 0.55, decal: { symbol: 'rect', symbolSize: 1, rotation: Math.PI / 4, dashArrayX: [1, 0], dashArrayY: [2, 4], color: t.dark ? 'rgba(0,0,0,0.45)' : 'rgba(255,255,255,0.7)' } };
				return d;
			});
		}
		function lbl(p) {
			if (p.value == null) return '';
			return p.data.few ? 'n=' + p.data.n + ', too few' : p.value + '%';
		}
		function series(name, g, color) {
			return { name: name, type: 'bar', barMaxWidth: 26, barGap: '15%', data: pts(g),
				itemStyle: { color: color, borderRadius: [4, 4, 0, 0] },
				label: { show: true, position: 'top', color: t.muted, fontSize: 11, formatter: lbl } };
		}
		return {
			animation: false,
			legend: { bottom: 0, textStyle: { color: t.text, fontSize: 11 } },
			grid: { left: 8, right: 12, top: 22, bottom: 36, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: function (ps) {
				var m = ms[ps[0].dataIndex];
				if (!a.Comparable[m]) return '<b>Day ' + m + '</b><br>Inside the ' + a.Window + '-day award window: not compared.';
				return '<b>Day ' + m + '</b><br>' + ps.map(function (p) {
					if (p.value == null) return p.marker + esc(p.seriesName) + ': no one eligible yet';
					return p.marker + esc(p.seriesName) + ': <b>' + p.value + '%</b> (' + p.data.playing + ' of ' + p.data.n + ')' + (p.data.few ? ', too few to compare' : '');
				}).join('<br>');
			} }),
			xAxis: { type: 'category', data: ms.map(function (m) { return a.Comparable[m] ? 'Day ' + m : 'Day ' + m + '\n(in window)'; }),
				axisLabel: { color: t.muted, formatter: function (v) { return v; } }, axisLine: { lineStyle: { color: t.grid } }, axisTick: { show: false } },
			yAxis: { type: 'value', max: 100, axisLabel: { color: t.muted, formatter: '{value}%' }, splitLine: { lineStyle: { color: t.grid } } },
			series: [
				series('Award in first ' + a.Window + ' days (' + a.With.N + ')', a.With, cols[0]),
				series('No early award (' + a.Without.N + ')', a.Without, cols[1])
			]
		};
	}

	function renderAll() {
		mount('prr-chart-sources', buildSources);
		mount('prr-chart-monthly', buildMonthly);
		mount('prr-chart-sankey', buildSankey);
		mount('prr-chart-award', buildAward);
		var t = theme();
		document.querySelectorAll('.prr-swatch[data-src]').forEach(function (el) { el.style.background = sourceColor(el.getAttribute('data-src'), t); });
	}
	renderAll();

	var sel = document.getElementById('prr-flow-source');
	if (sel) sel.addEventListener('change', function () { mount('prr-chart-sankey', buildSankey); });

	// Re-theme when the dark-mode toggle flips data-theme on <html>.
	new MutationObserver(function (muts) {
		if (muts.some(function (m) { return m.attributeName === 'data-theme'; })) renderAll();
	}).observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

	var rt;
	function resizeAll() { Object.keys(charts).forEach(function (k) { charts[k].resize(); }); }
	window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(resizeAll, 120); });
	if (window.ResizeObserver) {
		var ro = new ResizeObserver(function () { clearTimeout(rt); rt = setTimeout(resizeAll, 120); });
		Object.keys(charts).forEach(function (k) { ro.observe(document.getElementById(k)); });
	}

	if (!window.jQuery || !jQuery.fn.DataTable) return;

	// Percent columns sort by data-order; '' (no one eligible) sorts last in both directions.
	function numOrNaN(v) { return v === '' || v == null ? NaN : parseFloat(v); }
	jQuery.fn.dataTable.ext.type.order['prrnum-asc'] = function (a, b) {
		a = numOrNaN(a); b = numOrNaN(b);
		if (isNaN(a)) return isNaN(b) ? 0 : 1;
		if (isNaN(b)) return -1;
		return a - b;
	};
	jQuery.fn.dataTable.ext.type.order['prrnum-desc'] = function (a, b) {
		a = numOrNaN(a); b = numOrNaN(b);
		if (isNaN(a)) return isNaN(b) ? 0 : 1;
		if (isNaN(b)) return -1;
		return b - a;
	};
	jQuery('#prr-sources-table').DataTable({
		paging: false, searching: false, info: false, autoWidth: false,
		order: [],
		columnDefs: [{ targets: 'prr-num', type: 'prrnum', orderSequence: ['desc', 'asc'] }]
	});
	jQuery('table.prr-static').DataTable({ paging: false, searching: false, info: false, ordering: false, autoWidth: false });

	// Detail table.
	function human(d) {
		if (!d || d === '0000-00-00') return '';
		var p = d.split('-');
		return new Date(+p[0], +p[1] - 1, +p[2]).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
	}
	function dateCol(field) {
		return { data: field, render: function (v, type) { return type === 'display' ? (v ? human(v) : '<span class="prr-muted">&mdash;</span>') : (v || ''); } };
	}
	jQuery('#prr-detail-table').DataTable({
		data: D.players,
		deferRender: true,
		pageLength: 25,
		order: [[2, 'desc']],
		autoWidth: false,
		language: { search: '', searchPlaceholder: 'Search players…', emptyTable: 'No new players.' },
		columns: [
			{ data: 'Persona', render: function (v, type, row) {
				var name = v || ('Player #' + row.MundaneId);
				return type === 'display' ? '<a href="' + D.playerUrl + row.MundaneId + '">' + esc(name) + '</a>' : name;
			} },
			{ data: 'ParkName', render: function (v, type) { return type === 'display' ? esc(v) : v; } },
			dateCol('FirstDate'),
			{ data: 'Source', render: function (v, type, row) {
			if (type !== 'display') return v;
			if (row.SourceKey === 'none') return '<span class="prr-muted">' + esc(v) + '</span>';
			return esc(v) + (row.Later ? '<span class="prr-later" data-tip="Answered later from the Account modal, not at registration.">later</span>' : '');
		} },
			{ data: 'SignIns', className: 'prr-num' },
			dateCol('LastDate'),
			dateCol('FirstAward'),
			{ data: 'DuesPaid', render: function (v, type) { return type === 'display' ? (v ? '<span class="prr-yes">Yes</span>' : '<span class="prr-muted">No</span>') : (v ? 1 : 0); } }
		]
	});
})();
</script>
<?php endif; ?>
