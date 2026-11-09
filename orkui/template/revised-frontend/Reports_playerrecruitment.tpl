<?php
/* ── Player Recruitment Report ───────────────────────────────
   Controller: Controller_Reports::player_recruitment
   Data: rr_state ('no_scope' | 'no_access' | 'error' | 'ready'),
         rr_scope_type, rr_scope_id, rr_scope_name, rr_meta (source codes,
         award windows), rr_result (RecruitmentReport::Run response),
         rr_error, rr_filters, rr_kingdoms (no_scope only).
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
$rr_parks      = $rr_result['Parks'] ?? [];
$rr_scope_link = $rr_scope_type ? UIR . ($rr_is_park ? 'Park' : 'Kingdom') . '/profile/' . $rr_scope_id : '';
$rr_back_link  = $rr_scope_link !== '' ? $rr_scope_link . '&tab=admin' : UIR . 'Admin';
$rr_stats      = $rr_result['Stats'] ?? [];
$rr_n          = (int)($rr_stats['NewPlayers'] ?? 0);
$rr_json_flags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

$rr_pct = function ($v) {
	return $v === null ? '&mdash;' : rtrim(rtrim(number_format((float)$v, 1), '0'), '.') . '%';
};
$rr_date = function ($d) {
	return ($d && $d !== '0000-00-00') ? date('M j, Y', strtotime($d)) : '&mdash;';
};
$rr_ret = function ($r) use ($rr_pct) {
	// "42%" with the eligible n beneath, or a dash when nobody has reached the milestone yet.
	if (!is_array($r) || (int)$r['Eligible'] === 0) {
		return '<span class="prr-muted">&mdash;</span>';
	}
	return $rr_pct($r['Pct']) . '<span class="prr-n">n=' . number_format((int)$r['Eligible']) . '</span>';
};
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(__DIR__ . '/../default/style/reports.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/ork-datatables.css?v=<?= filemtime(__DIR__ . '/style/ork-datatables.css') ?>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr@4.6.13/dist/flatpickr.min.css">

<style>
/* Page-scoped. reports.css ships dark rules for .rp-form-* / .rp-btn-run but not the
   light ones on this branch, so they are defined here under .prr-root only. */
.prr-root .rp-param-form { display: flex; flex-direction: column; gap: 10px; }
.prr-root .rp-form-group { display: flex; flex-direction: column; gap: 3px; }
.prr-root .rp-form-group label { font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); }
.prr-root .rp-form-input, .prr-root .rp-form-select { width: 100%; border: 1px solid var(--rp-border-mid); border-radius: 5px; padding: 6px 8px; font-size: 13px; color: var(--rp-text); background: var(--ork-input-bg, #fff); box-sizing: border-box; min-height: 34px; }
.prr-root .rp-form-input:focus, .prr-root .rp-form-select:focus { outline: none; border-color: var(--rp-accent-mid); }
.prr-root .rp-btn-run { width: 100%; padding: 9px 0; background: #4338ca; color: #fff; border: none; border-radius: 6px; font-size: 13px; font-weight: 700; cursor: pointer; transition: background 0.15s; }
.prr-root .rp-btn-run:hover { background: #3730a3; }
.prr-root .rp-btn-run[disabled] { opacity: 0.7; cursor: progress; }
html[data-theme="dark"] .prr-root .rp-form-select { background: var(--ork-input-bg, #374151); border-color: var(--ork-input-border, #4a5568); color: var(--ork-text, #e2e8f0); }

.prr-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 16px; }
.prr-main .rp-chart-card { min-width: 0; }
.prr-root .rp-chart-card-title { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.prr-root h2.rp-chart-card-title { background: none; border: 0; padding: 0; }
.prr-sub { font-size: 12px; color: var(--rp-text-muted); margin: -4px 0 10px; line-height: 1.45; }
.prr-chart { width: 100%; height: 320px; }
.prr-chart-sources { height: 340px; }
.prr-sankey-scroll { overflow-x: auto; overscroll-behavior-x: contain; }
.prr-sankey-scroll .prr-chart { min-width: 640px; height: 420px; }
.prr-toolbar { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin-bottom: 10px; }
.prr-toolbar label { font-size: 12px; font-weight: 600; color: var(--rp-text-body); }
.prr-toolbar select { max-width: 100%; }

.prr-table-wrap { overflow-x: auto; }
.prr-table { width: 100%; border-collapse: collapse; font-size: 13px; color: var(--rp-text-body); }
.prr-table th { text-align: left; font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); padding: 8px 10px; border-bottom: 2px solid var(--rp-border); white-space: nowrap; background: none; }
.prr-table td { padding: 8px 10px; border-bottom: 1px solid var(--rp-divider); vertical-align: top; }
.prr-table td.prr-num, .prr-table th.prr-num { text-align: right; white-space: nowrap; }
.prr-table tfoot td { font-weight: 700; border-top: 2px solid var(--rp-border); border-bottom: 0; color: var(--rp-text); }
.prr-table tr.prr-zero td { color: var(--rp-text-hint); }
.prr-table td:first-child { min-width: 170px; }
.prr-swatch { display: inline-block; width: 10px; height: 10px; border-radius: 3px; margin-right: 7px; vertical-align: -1px; }
.prr-n { display: block; font-size: 10px; color: var(--rp-text-hint); font-weight: 400; }
.prr-muted { color: var(--rp-text-hint); }
.prr-caption { font-size: 12px; color: var(--rp-text-muted); font-style: italic; margin: 10px 0 0; }

.prr-state { text-align: center; padding: 48px 20px; color: var(--rp-text-muted); }
.prr-state i { font-size: 30px; opacity: 0.45; display: block; margin-bottom: 12px; }
.prr-state h2 { font-size: 18px; color: var(--rp-text); margin: 0 0 8px; background: none; border: 0; padding: 0; }
.prr-state p { margin: 0 auto; max-width: 460px; font-size: 14px; line-height: 1.5; }
.prr-state form { margin: 18px auto 0; max-width: 320px; display: flex; gap: 8px; }
.prr-state form .rp-btn-run { width: auto; padding: 8px 16px; }

.prr-root .rp-stat-card[data-tip] { cursor: help; }
.prr-root [data-tip] { position: relative; }
.prr-root [data-tip]:hover::after, .prr-root [data-tip]:focus-visible::after {
	content: attr(data-tip); position: absolute; top: calc(100% + 6px); left: 50%; transform: translateX(-50%);
	width: max-content; max-width: 240px; white-space: normal; line-height: 1.35; text-align: left;
	background: #1a202c; color: #fff; font-size: 11px; font-weight: 400; text-transform: none; letter-spacing: 0;
	padding: 6px 8px; border-radius: 5px; box-shadow: 0 2px 8px rgba(0,0,0,0.25); z-index: 60; pointer-events: none;
}
.prr-root .rp-stat-card:last-child[data-tip]:hover::after { left: auto; right: 0; transform: none; }

.prr-about dt { font-weight: 700; margin-top: 8px; color: var(--rp-text); }
.prr-about dd { margin: 2px 0 0; color: var(--rp-text-muted); }

.prr-detail.rp-table-area { flex: none; }
.prr-detail table.dataTable td.prr-num { text-align: right; }
.prr-yes { color: #0f7a3d; font-weight: 600; }
html[data-theme="dark"] .prr-yes { color: #68d391; }
html[data-theme="dark"] .prr-table td { border-bottom-color: #3a4556; }
html[data-theme="dark"] .prr-root [data-tip]:hover::after { background: #0f141c; border: 1px solid #4a5568; }

@media (max-width: 900px) {
	.prr-main { width: 100%; }
}
@media (max-width: 600px) {
	.prr-chart { height: 280px; }
	.prr-chart-sources { height: 380px; }
	.prr-root .rp-form-input, .prr-root .rp-form-select { font-size: 16px; min-height: 44px; }
	.prr-root .rp-btn-run { min-height: 44px; }
	.prr-root [data-tip]:hover::after { max-width: 200px; }
	.prr-state form { flex-direction: column; }
}
</style>

<div class="rp-root prr-root">

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
		<p>This report runs for one kingdom or park. Open it from a kingdom or park&rsquo;s Admin Tasks tab, or choose a kingdom here.</p>
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
		<div class="rp-stat-card" tabindex="0" data-tip="Share of these new players with a &ldquo;How did you find us?&rdquo; answer on file.">
			<div class="rp-stat-icon"><i class="fas fa-comment-dots"></i></div>
			<div class="rp-stat-number"><?= $rr_pct($rr_stats['AnsweredPct'] ?? null) ?></div>
			<div class="rp-stat-label">Source answered</div>
			<div class="rp-stat-hint"><?= number_format((int)($rr_stats['Answered'] ?? 0)) ?> of <?= number_format($rr_n) ?></div>
		</div>
		<div class="rp-stat-card" tabindex="0" data-tip="Signed in on at least two different days, anywhere in the ORK. Only players whose first sign-in was 30 or more days ago are counted; newer one-timers are &ldquo;not back yet&rdquo;.">
			<div class="rp-stat-icon"><i class="fas fa-redo-alt"></i></div>
			<div class="rp-stat-number"><?= $rr_pct($rr_stats['BackPct'] ?? null) ?></div>
			<div class="rp-stat-label">Came back</div>
			<div class="rp-stat-hint"><?= number_format((int)($rr_stats['Back'] ?? 0)) ?> of <?= number_format((int)($rr_stats['BackElig'] ?? 0)) ?> eligible</div>
		</div>
<?php $d90 = $rr_stats['Day90'] ?? ['Eligible' => 0, 'Pct' => null, 'TooRecent' => 0]; ?>
		<div class="rp-stat-card" tabindex="0" data-tip="Signed in 90 or more days after their first sign-in. Only players whose 90 days have passed are counted; <?= number_format((int)$d90['TooRecent']) ?> are too recent to tell.">
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
							<dd>First-ever ORK sign-in falls in the date range, with no sign-in anywhere before it. Credited to the park New Player Attendance credits them to, so the two reports agree.</dd>
							<dt>Came back</dt>
							<dd>Signed in on two or more different days, anywhere. A player with one sign-in less than 30 days ago is &ldquo;not back yet&rdquo;, not &ldquo;never came back&rdquo;, and is left out of the came-back percentage until 30 days have passed.</dd>
							<dt>Still playing at day N</dt>
							<dd>Has any sign-in N or more days after the first one.</dd>
							<dt>Too recent</dt>
							<dd>Day N hasn&rsquo;t arrived yet. These players are left out of that day&rsquo;s percentage instead of being counted as dropped.</dd>
							<dt>Dues paid</dt>
							<dd>Any dues payment recorded on or after the first sign-in.</dd>
							<dt>Early award</dt>
							<dd>Any award (not revoked) dated within the chosen window after the first sign-in.</dd>
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
				<div class="prr-table-wrap">
					<table class="prr-table">
						<thead><tr>
							<th>Source</th><th class="prr-num">New players</th><th class="prr-num">% of total</th><th class="prr-num">Came back</th>
							<th class="prr-num">Day 30</th><th class="prr-num">Day 90</th><th class="prr-num">Day 180</th><th class="prr-num">Dues paid</th>
						</tr></thead>
						<tbody>
<?php foreach ($sources as $i => $s) : ?>
							<tr class="<?= $s['N'] === 0 ? 'prr-zero' : '' ?>">
								<td><span class="prr-swatch" data-src="<?= htmlspecialchars($s['Key']) ?>"></span><?= htmlspecialchars($s['Label']) ?></td>
								<td class="prr-num"><?= number_format($s['N']) ?></td>
								<td class="prr-num"><?= $rr_pct($s['PctOfTotal']) ?></td>
<?php if ($s['N'] === 0) : ?>
								<td class="prr-num">&mdash;</td><td class="prr-num">&mdash;</td><td class="prr-num">&mdash;</td><td class="prr-num">&mdash;</td><td class="prr-num">&mdash;</td>
<?php else : ?>
								<td class="prr-num"><?= $s['BackElig'] > 0 ? $rr_pct($s['BackPct']) . '<span class="prr-n">n=' . number_format($s['BackElig']) . '</span>' : '<span class="prr-muted">&mdash;</span>' ?></td>
								<td class="prr-num"><?= $rr_ret($s['Retention'][30]) ?></td>
								<td class="prr-num"><?= $rr_ret($s['Retention'][90]) ?></td>
								<td class="prr-num"><?= $rr_ret($s['Retention'][180]) ?></td>
								<td class="prr-num"><?= $rr_pct($s['DuesPct']) ?></td>
<?php endif; ?>
							</tr>
<?php endforeach; ?>
						</tbody>
						<tfoot><tr>
							<td>All new players</td>
							<td class="prr-num"><?= number_format($overall['N']) ?></td>
							<td class="prr-num">100%</td>
							<td class="prr-num"><?= $overall['BackElig'] > 0 ? $rr_pct($overall['BackPct']) . '<span class="prr-n">n=' . number_format($overall['BackElig']) . '</span>' : '&mdash;' ?></td>
							<td class="prr-num"><?= $rr_ret($overall['Retention'][30]) ?></td>
							<td class="prr-num"><?= $rr_ret($overall['Retention'][90]) ?></td>
							<td class="prr-num"><?= $rr_ret($overall['Retention'][180]) ?></td>
							<td class="prr-num"><?= $rr_pct($overall['DuesPct']) ?></td>
						</tr></tfoot>
					</table>
				</div>
				<p class="prr-caption">Came back and day columns count only players whose day has arrived (n shown). Small groups swing a lot; read percentages next to their n.</p>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-monthly">
				<h2 class="rp-chart-card-title" id="prr-h-monthly"><i class="fas fa-chart-column"></i> New players by month</h2>
				<div id="prr-chart-monthly" class="prr-chart" role="img" aria-label="New players per month, stacked by recruitment source"></div>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-flow">
				<h2 class="rp-chart-card-title" id="prr-h-flow"><i class="fas fa-water"></i> Retention flow</h2>
				<p class="prr-sub">Each band is a group of players. &ldquo;Not back yet&rdquo; and &ldquo;too recent&rdquo; players haven&rsquo;t had the time yet; they are not drop-offs.</p>
				<div class="prr-toolbar">
					<label for="prr-flow-source">Show</label>
					<select id="prr-flow-source" class="rp-form-select" style="width:auto;">
						<option value="all">All new players (<?= number_format($rr_n) ?>)</option>
<?php foreach ($sources as $s) : if ($s['N'] === 0) continue; ?>
						<option value="<?= htmlspecialchars($s['Key']) ?>"><?= htmlspecialchars($s['Label']) ?> (<?= number_format($s['N']) ?>)</option>
<?php endforeach; ?>
					</select>
				</div>
				<div class="prr-sankey-scroll">
					<div id="prr-chart-sankey" class="prr-chart" role="img" aria-label="Retention flow from first sign-in through day 180"></div>
				</div>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-funnel">
				<h2 class="rp-chart-card-title" id="prr-h-funnel"><i class="fas fa-filter"></i> Return funnel</h2>
				<p class="prr-sub">How many new players reached their 2nd, 3rd, 5th, 10th and 25th day of play, all time.</p>
				<div class="prr-table-wrap">
					<table class="prr-table">
						<thead><tr><th>Reached</th><th class="prr-num">Players</th><th class="prr-num">% of new players</th></tr></thead>
						<tbody>
							<tr><td>1st sign-in</td><td class="prr-num"><?= number_format($rr_n) ?></td><td class="prr-num">100%</td></tr>
<?php foreach ($rr_result['Funnel'] as $f) : ?>
<?php $ord = [2 => '2nd', 3 => '3rd', 5 => '5th', 10 => '10th', 25 => '25th'][$f['Reached']] ?? ($f['Reached'] . 'th'); ?>
							<tr><td><?= $ord ?> sign-in</td><td class="prr-num"><?= number_format($f['N']) ?></td><td class="prr-num"><?= $rr_pct($f['Pct']) ?></td></tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			</section>

			<section class="rp-chart-card" aria-labelledby="prr-h-award">
				<h2 class="rp-chart-card-title" id="prr-h-award"><i class="fas fa-award"></i> Early awards and retention</h2>
				<p class="prr-sub">New players who received any award in their first <?= (int)$ea['Window'] ?> days, against those who didn&rsquo;t.<?php if ((int)$ea['TooRecent'] > 0) : ?> <?= number_format((int)$ea['TooRecent']) ?> players are left out because their first <?= (int)$ea['Window'] ?> days aren&rsquo;t over yet.<?php endif; ?></p>
				<div id="prr-chart-award" class="prr-chart" role="img" aria-label="Retention at day 30, 60, 90 and 180 for players with and without an early award"></div>
				<div class="prr-table-wrap">
					<table class="prr-table">
						<thead><tr><th>Group</th><th class="prr-num">Players</th><th class="prr-num">Day 30</th><th class="prr-num">Day 60</th><th class="prr-num">Day 90</th><th class="prr-num">Day 180</th></tr></thead>
						<tbody>
<?php foreach (['With' => 'Award in first ' . (int)$ea['Window'] . ' days', 'Without' => 'No early award'] as $gk => $gl) : $g = $ea[$gk]; ?>
							<tr>
								<td><?= htmlspecialchars($gl) ?></td>
								<td class="prr-num"><?= number_format($g['N']) ?></td>
<?php foreach ([30, 60, 90, 180] as $m) : ?>
								<td class="prr-num"><?= $rr_ret($g['Retention'][$m]) ?></td>
<?php endforeach; ?>
							</tr>
<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p class="prr-caption">This shows correlation, not proof of cause: players who were going to stay anyway may also be the ones who get noticed early.</p>
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
	if (typeof flatpickr !== 'function') return;
	['#prr-start', '#prr-end'].forEach(function (sel) {
		if (document.querySelector(sel)) {
			flatpickr(sel, { dateFormat: 'Y-m-d', altInput: true, altFormat: 'M j, Y', altInputClass: 'rp-form-input', maxDate: 'today' });
		}
	});
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
	'sources'  => array_map(function ($s) { return ['key' => $s['Key'], 'label' => $s['Label'], 'n' => $s['N']]; }, $rr_result['Sources']),
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
	function shortLabel(l) { return l.length > 30 ? l.replace(/\s*\(.*\)$/, '').replace(/,? etc\.$/, '') : l; }
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
		return {
			animation: false,
			grid: { left: 8, right: 40, top: 8, bottom: 8, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'item', formatter: function (p) { return esc(p.name) + '<br><b>' + p.value + '</b> new players'; } }),
			xAxis: { type: 'value', minInterval: 1, axisLabel: { color: t.muted }, splitLine: { lineStyle: { color: t.grid } } },
			yAxis: { type: 'category', data: rows.map(function (s) { return s.label; }), axisTick: { show: false },
				axisLine: { lineStyle: { color: t.grid } },
				axisLabel: { color: t.text, width: window.innerWidth < 600 ? 140 : 230, overflow: 'truncate', formatter: shortLabel } },
			series: [{ type: 'bar', barMaxWidth: 18,
				data: rows.map(function (s) { return { value: s.n, name: s.label, itemStyle: { color: sourceColor(s.key, t), borderRadius: [0, 4, 4, 0] } }; }),
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
			return { name: s.label, type: 'bar', stack: 'src', barMaxWidth: 28, data: m.Series[s.key],
				itemStyle: { color: sourceColor(s.key, t), borderColor: t.surface, borderWidth: 1 }, emphasis: { focus: 'series' } };
		});
		return {
			animation: false,
			legend: { type: 'scroll', bottom: 0, textStyle: { color: t.text, fontSize: 11 }, pageTextStyle: { color: t.muted }, formatter: shortLabel },
			grid: { left: 8, right: 12, top: 12, bottom: 44, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'axis', axisPointer: { type: 'shadow' },
				formatter: function (ps) {
					var tot = 0, lines = ps.filter(function (p) { return p.value > 0; }).map(function (p) { tot += p.value; return p.marker + esc(shortLabel(p.seriesName)) + ': <b>' + p.value + '</b>'; });
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
		if (/too recent|Not back yet/.test(name)) return t.dark ? '#c98500' : '#eda100';
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
		var a = D.award, ms = [30, 60, 90, 180];
		function pts(g) { return ms.map(function (m) { var r = g.Retention[m]; return { value: r.Pct, n: r.Eligible, playing: r.Playing }; }); }
		var cols = t.dark ? CAT.dark : CAT.light;
		return {
			animation: false,
			legend: { bottom: 0, textStyle: { color: t.text, fontSize: 11 } },
			grid: { left: 8, right: 12, top: 16, bottom: 36, containLabel: true },
			tooltip: Object.assign(tooltipBase(t), { trigger: 'axis', axisPointer: { type: 'shadow' }, formatter: function (ps) {
				return '<b>' + esc(ps[0].axisValue) + '</b><br>' + ps.map(function (p) {
					return p.marker + esc(p.seriesName) + ': ' + (p.value == null ? 'no one eligible yet' : '<b>' + p.value + '%</b> (' + p.data.playing + ' of ' + p.data.n + ')');
				}).join('<br>');
			} }),
			xAxis: { type: 'category', data: ms.map(function (m) { return 'Day ' + m; }), axisLabel: { color: t.muted }, axisLine: { lineStyle: { color: t.grid } }, axisTick: { show: false } },
			yAxis: { type: 'value', max: 100, axisLabel: { color: t.muted, formatter: '{value}%' }, splitLine: { lineStyle: { color: t.grid } } },
			series: [
				{ name: 'Award in first ' + a.Window + ' days (' + a.With.N + ')', type: 'bar', barMaxWidth: 26, barGap: '15%', data: pts(a.With), itemStyle: { color: cols[0], borderRadius: [4, 4, 0, 0] }, label: { show: true, position: 'top', color: t.muted, fontSize: 11, formatter: function (p) { return p.value == null ? '' : p.value + '%'; } } },
				{ name: 'No early award (' + a.Without.N + ')', type: 'bar', barMaxWidth: 26, data: pts(a.Without), itemStyle: { color: cols[1], borderRadius: [4, 4, 0, 0] }, label: { show: true, position: 'top', color: t.muted, fontSize: 11, formatter: function (p) { return p.value == null ? '' : p.value + '%'; } } }
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

	// Detail table.
	if (!window.jQuery || !jQuery.fn.DataTable) return;
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
			{ data: 'Source', render: function (v, type, row) { return type === 'display' ? (row.SourceKey === 'none' ? '<span class="prr-muted">' + esc(v) + '</span>' : esc(v)) : v; } },
			{ data: 'SignIns', className: 'prr-num' },
			dateCol('LastDate'),
			dateCol('FirstAward'),
			{ data: 'DuesPaid', render: function (v, type) { return type === 'display' ? (v ? '<span class="prr-yes">Yes</span>' : '<span class="prr-muted">No</span>') : (v ? 1 : 0); } }
		]
	});
})();
</script>
<?php endif; ?>
