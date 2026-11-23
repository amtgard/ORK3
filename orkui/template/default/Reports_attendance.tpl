<?php
/* ── Pre-compute stats and chart data from attendance_periodical ── */
$_att_dates  = isset($attendance_periodical['Dates']) && is_array($attendance_periodical['Dates'])
	? $attendance_periodical['Dates'] : [];

$chart_dates     = [];
$chart_counts    = [];
$chart_in_person = [];
$chart_online    = [];
$chart_online_only = [];
$chart_is_event  = [];
foreach ($_att_dates as $_d) {
	$chart_dates[]  = valid_id($_d['EventId'])
		? (date('Y-m-d', strtotime($_d['EventStart'])) . ' – ' . date('Y-m-d', strtotime($_d['EventEnd'])))
		: date('Y-m-d', strtotime($_d['Date']));
	$chart_counts[]    = (int)$_d['DistinctPlayers'];
	$chart_in_person[] = (int)($_d['DistinctPlayersInPerson'] ?? 0);
	$chart_online[]    = (int)($_d['DistinctPlayersOnline'] ?? 0);
	$chart_online_only[] = (int)($_d['DistinctPlayersOnlineOnly'] ?? 0);
	$chart_is_event[]  = valid_id($_d['EventId']) ? true : false;
}

$chart_dates     = array_reverse($chart_dates);
$chart_counts    = array_reverse($chart_counts);
$chart_in_person = array_reverse($chart_in_person);
$chart_online    = array_reverse($chart_online);
$chart_online_only = array_reverse($chart_online_only);
$chart_is_event  = array_reverse($chart_is_event);

/* Total Credits comes from attendance_summary to match the table/CSV */
$_summary_dates = isset($attendance_summary['Dates']) && is_array($attendance_summary['Dates'])
	? $attendance_summary['Dates'] : [];
$_att_total   = array_sum(array_column($_summary_dates, 'Attendees'));
/* Total Weeks matches the chart's weekly-aggregated data points */
$_att_records = count($chart_counts);
$_att_avg     = $_att_records ? $_att_total / $_att_records : 0;

/* Distinct player stats from dedicated API call */
$_distinct_total  = isset($distinct_stats['TotalDistinctPlayers']) ? (int)$distinct_stats['TotalDistinctPlayers'] : 0;
$_distinct_avg_wk = isset($distinct_stats['AvgDistinctPerWeek']) ? (float)$distinct_stats['AvgDistinctPerWeek'] : 0;
$_distinct_total_ip  = (int)($distinct_stats['TotalDistinctPlayersInPerson'] ?? 0);
$_distinct_total_on  = (int)($distinct_stats['TotalDistinctPlayersOnline'] ?? 0);
$_distinct_avg_wk_ip = (float)($distinct_stats['AvgDistinctPerWeekInPerson'] ?? 0);
$_distinct_avg_wk_on = (float)($distinct_stats['AvgDistinctPerWeekOnline'] ?? 0);

/* ── Trend %: compare avg of recent 4 weeks vs prior 4 weeks ── */
$_trend_pct = null;
$_trend_dir = 'flat';
if ($_att_records >= 8) {
	$_recent4 = array_slice($chart_counts, -4);
	$_prior4  = array_slice($chart_counts, -8, 4);
	$_recent_avg = array_sum($_recent4) / 4;
	$_prior_avg  = array_sum($_prior4) / 4;
	if ($_prior_avg > 0) {
		$_trend_pct = (($_recent_avg - $_prior_avg) / $_prior_avg) * 100;
		$_trend_dir = $_trend_pct > 2 ? 'up' : ($_trend_pct < -2 ? 'down' : 'flat');
	}
} elseif ($_att_records >= 4) {
	$half = (int)floor($_att_records / 2);
	$_recent = array_slice($chart_counts, -$half);
	$_prior  = array_slice($chart_counts, 0, $half);
	$_recent_avg = array_sum($_recent) / count($_recent);
	$_prior_avg  = array_sum($_prior) / count($_prior);
	if ($_prior_avg > 0) {
		$_trend_pct = (($_recent_avg - $_prior_avg) / $_prior_avg) * 100;
		$_trend_dir = $_trend_pct > 2 ? 'up' : ($_trend_pct < -2 ? 'down' : 'flat');
	}
}

/* ── Peak week ── */
$_peak_count = 0;
$_peak_date  = '';
if ($_att_records > 0) {
	$_peak_idx   = array_keys($chart_counts, max($chart_counts))[0];
	$_peak_count = $chart_counts[$_peak_idx];
	$_peak_date  = $chart_dates[$_peak_idx];
}

/* ── Monthly chart data ── */
$_monthly_dates     = [];
$_monthly_counts    = [];
$_monthly_in_person = [];
$_monthly_online    = [];
$_monthly_online_only = [];
$_month_names = ['','Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
foreach ((array)($monthly_chart_data ?? []) as $_m) {
	$_monthly_dates[]     = $_month_names[(int)$_m['Month']] . ' ' . $_m['Year'];
	$_monthly_counts[]    = (int)$_m['Count'];
	$_monthly_in_person[] = (int)($_m['InPerson'] ?? 0);
	$_monthly_online[]    = (int)($_m['Online'] ?? 0);
	$_monthly_online_only[] = (int)($_m['OnlineOnly'] ?? 0);
}
$_has_monthly = count($_monthly_counts) >= 2;
$_monthly_avg = $_has_monthly ? array_sum($_monthly_counts) / count($_monthly_counts) : 0;
$_monthly_avg_ip = $_has_monthly ? array_sum($_monthly_in_person) / count($_monthly_in_person) : 0;
$_monthly_avg_on = $_has_monthly ? array_sum($_monthly_online) / count($_monthly_online) : 0;

/* ── Tooltip text (pre-computed so no inline PHP inside data-tip attributes) ── */
$_tip_unique   = 'Count of distinct players who signed in at least once during this period. A player who signed in both in person and online counts once in each split.';
$_tip_avg_wk   = 'Average distinct players per week. Each week is counted once regardless of how many days it spans.';
$_tip_avg_mo   = 'Average distinct players per calendar month over this period. A player who attends multiple weeks in a month is counted once for that month.';
$_tip_trend    = ($_att_records >= 4)
	? 'Change in avg attendance: most recent ' . ($_att_records >= 8 ? '4' : (int)floor($_att_records/2)) . ' weeks vs the ' . ($_att_records >= 8 ? '4' : (int)floor($_att_records/2)) . ' weeks before that.'
	: 'Not enough data to calculate trend (need at least 4 weeks).';
$_tip_peak     = 'Highest single-week distinct player count during this period.';
$_tip_parks    = 'Number of distinct parks with at least one attendance record during this period.';
$_tip_weeks    = 'Number of weeks with at least one attendance record during this period.';
$_tip_col_ip   = 'Distinct players with at least one in-person sign-in.';
$_tip_col_on   = 'Distinct players with at least one sign-in marked Online.';
$_tip_col_tot  = 'All distinct players. A player who signed in both in person and online counts once in In-Person, once in Online and once here, so In-Person + Online can exceed Total.';
$_tip_col_sign = 'Total attendance sign-in records, in person and online.';
/* Column header with a tooltip icon (the CSV export strips .rp-col-tip). */
$_att_th = function ($label, $tip) {
	return htmlspecialchars($label) . ' <span class="rp-stat-tip-icon rp-col-tip" data-tip="' . htmlspecialchars($tip) . '" aria-label="' . htmlspecialchars($tip) . '">?</span>';
};
/* In-person / online sub-line under a stat card number. */
$_att_split = function ($ip, $on, $decimals = 0) {
	return '<div class="rp-stat-hint">' . number_format($ip, $decimals) . ' in-person &middot; ' . number_format($on, $decimals) . ' online</div>';
};

/* ── Unique parks (Kingdom scope only) ── */
$_unique_parks = 0;
if ($Type === 'Kingdom' && !empty($_summary_dates)) {
	$_park_ids = [];
	foreach ($_summary_dates as $_d) {
		if (!empty($_d['ParkId']) && (int)$_d['ParkId'] > 0) {
			$_park_ids[(int)$_d['ParkId']] = true;
		}
	}
	$_unique_parks = count($_park_ids);
}

/* ── Period label ── */
$_is_navigated = isset($AttendanceDates) && ($AttendanceFromDate !== date('Y-m-d'));
$_period_label = '';
if ($AttendancePeriod === 'All') {
	$_period_label = 'All Time';
} elseif ($AttendancePeriod === 'Weeks') {
	$_n = (int)$AttendanceNumPeriods;
	if ($_n === 1) {
		$_period_label = $_is_navigated
			? 'Week ending ' . date('M j, Y', strtotime($AttendanceFromDate))
			: 'Past Week';
	} else {
		$_period_label = $_is_navigated
			? $_n . ' Weeks ending ' . date('M j, Y', strtotime($AttendanceFromDate))
			: 'Past ' . $_n . ' Weeks';
	}
} elseif ($AttendancePeriod === 'Months') {
	$_n = (int)$AttendanceNumPeriods;
	$_months = ['January','February','March','April','May','June','July','August','September','October','November','December'];
	if ($_n === 1) {
		$_period_label = $_is_navigated
			? date('F Y', strtotime($AttendanceFromDate))
			: 'Past Month';
	} else {
		$_period_label = $_is_navigated
			? $_n . ' Months ending ' . date('M Y', strtotime($AttendanceFromDate))
			: 'Past ' . $_n . ' Months';
	}
} elseif ($AttendancePeriod === 'Week') {
	$_period_label = 'Today';
}

/* ── Previous / Next navigation ── */
$_nav_base   = UIR . 'Reports/attendance/' . $Type . '/' . $AttendanceId . '/' . $AttendancePeriod . '/' . $AttendanceNumPeriods . '/';
$_today_ts   = strtotime(date('Y-m-d'));
$_prev_url   = null;
$_next_url   = null;
if ($AttendancePeriod === 'Weeks') {
	$_prev_url = $_nav_base . date('Y-m-d', strtotime($AttendanceFromDate . ' -' . $AttendanceNumPeriods . ' weeks'));
	$_next_ts  = strtotime($AttendanceFromDate . ' +' . $AttendanceNumPeriods . ' weeks');
	if ($_next_ts <= $_today_ts) $_next_url = $_nav_base . date('Y-m-d', $_next_ts);
} elseif ($AttendancePeriod === 'Months') {
	$_prev_url = $_nav_base . date('Y-m-d', strtotime($AttendanceFromDate . ' -' . $AttendanceNumPeriods . ' months'));
	$_next_ts  = strtotime($AttendanceFromDate . ' +' . $AttendanceNumPeriods . ' months');
	if ($_next_ts <= $_today_ts) $_next_url = $_nav_base . date('Y-m-d', $_next_ts);
}


/* ── Scope chip ── */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
$scope_noun  = 'scope';
if ($Type === 'Park') {
	$_first = !empty($attendance_summary['Dates']) ? $attendance_summary['Dates'][0] : [];
	$scope_label = htmlspecialchars($_first['ParkName'] ?? 'Park');
	$scope_link  = UIR . 'Park/profile/' . (isset($_first['ParkId']) ? $_first['ParkId'] : '');
	$scope_icon  = 'fa-tree';
	$scope_noun  = 'park';
} elseif ($Type === 'Kingdom') {
	$_first = !empty($attendance_summary['Dates']) ? $attendance_summary['Dates'][0] : [];
	$scope_label = htmlspecialchars($_first['KingdomName'] ?? 'Kingdom');
	$scope_link  = UIR . 'Kingdom/profile/' . (isset($_first['KingdomId']) ? $_first['KingdomId'] : '');
	$scope_icon  = 'fa-chess-rook';
	$scope_noun  = 'kingdom';
} elseif ($Type === 'Event') {
	$scope_label = 'Event';
	$scope_icon  = 'fa-calendar-alt';
	$scope_noun  = 'event';
} else {
	$scope_label = 'All';
	$scope_noun  = 'global';
}

if (!is_array($attendance_summary['Dates'])) $attendance_summary['Dates'] = [];

/* ── Split summary rows into events, park days, and kingdom-level ── */
$_event_rows    = [];
$_parkday_rows  = [];
$_kingdom_rows  = [];
if ($Type !== 'Event') {
	foreach ($attendance_summary['Dates'] as $_row) {
		if (valid_id($_row['EventId'])) {
			$_event_rows[] = $_row;
		} elseif (valid_id($_row['ParkId'])) {
			$_parkday_rows[] = $_row;
		} elseif (valid_id($_row['KingdomId'])) {
			$_kingdom_rows[] = $_row;
		}
	}
} else {
	$_parkday_rows = $attendance_summary['Dates'];
}
?>
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">
<style>
/* Critical overrides — duplicated inline so stale reports.css can't break layout */
.rp-stat-card { position: relative; }
.rp-stat-tip  { position: absolute; top: 7px; right: 7px; }
.rp-stat-tip-icon {
	display: inline-flex; align-items: center; justify-content: center;
	width: 15px; height: 15px; border-radius: 50%;
	background: #e2e8f0; color: #718096;
	font-size: 9px; font-weight: 700; cursor: default; user-select: none;
}
.rp-charts-row.rp-charts-visible { display: flex; flex-direction: column; }
.rp-view-toggle { display: flex; flex-direction: row; align-items: center; gap: 6px; margin-bottom: 10px; }
.rp-view-btn {
	display: inline-block; width: auto; padding: 4px 14px;
	font-size: 12px; font-weight: 600; border: 1px solid var(--ork-input-border);
	border-radius: 20px; background: var(--ork-card-bg); color: #4a5568;
	cursor: pointer; white-space: nowrap;
}
.rp-view-btn-active { background: #4338ca; color: #fff; border-color: #4338ca; }
.rp-col-tip { margin-left: 3px; vertical-align: 1px; }
.rp-att-key-ip { color: #4338ca; }
.rp-att-key-on { color: #0369a1; }
html[data-theme="dark"] .rp-att-key-ip { color: #a5b4fc; }
html[data-theme="dark"] .rp-att-key-on { color: #7dd3fc; }
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-users rp-header-icon"></i>
				<h1 class="rp-header-title">Attendance</h1>
<?php if ($_period_label): ?>
				<span style="display:inline-flex;align-items:center;background:rgba(255,255,255,0.15);border:1px solid rgba(255,255,255,0.25);border-radius:20px;padding:3px 11px;font-size:0.78rem;font-weight:600;color:rgba(255,255,255,0.9);white-space:nowrap;"><?=htmlspecialchars($_period_label)?></span>
<?php endif; ?>
			</div>
<?php if ($scope_label): ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip-label">Scope:</span>
<?php if ($scope_link): ?>
				<a href="<?=$scope_link?>" class="rp-scope-chip">
					<i class="fas <?=$scope_icon?>"></i>
					<?=$scope_label?>
				</a>
<?php else: ?>
				<span class="rp-scope-chip">
					<i class="fas <?=$scope_icon?>"></i>
					<?=$scope_label?>
				</span>
<?php endif; ?>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
<?php if ($_prev_url): ?>
			<a class="rp-btn-ghost" href="<?=$_prev_url?>"><i class="fas fa-chevron-left"></i> Previous</a>
<?php endif; ?>
<?php if ($_next_url): ?>
			<a class="rp-btn-ghost" href="<?=$_next_url?>">Next <i class="fas fa-chevron-right"></i></a>
<?php endif; ?>
			<button class="rp-btn-ghost" id="rp-btn-csv"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost" onclick="window.print()"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Attendance over time<?php if ($scope_label && $scope_noun !== 'scope' && $scope_noun !== 'global'): ?> for this <?=$scope_noun?><?php endif; ?>. <?php if ($_att_records > 0): ?>Bars show distinct players per week, stacked in-person and online-only<?php $has_events = in_array(true, $chart_is_event); if ($has_events): ?> — <strong style="color:#d97706;">amber bars</strong> indicate weeks with events<?php endif; ?>. The line shows the 4-week rolling average.<?php endif; ?></span>
	</div>

	<!-- Stats row -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_unique)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=number_format($_distinct_total)?></div>
			<div class="rp-stat-label">Unique Players</div>
			<?=$_att_split($_distinct_total_ip, $_distinct_total_on)?>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_avg_wk)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-calendar-week"></i></div>
			<div class="rp-stat-number"><?=number_format($_distinct_avg_wk, 1)?></div>
			<div class="rp-stat-label">Avg / Week</div>
			<?=$_att_split($_distinct_avg_wk_ip, $_distinct_avg_wk_on, 1)?>
		</div>
<?php if ($_has_monthly): ?>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_avg_mo)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-calendar-alt"></i></div>
			<div class="rp-stat-number"><?=number_format($_monthly_avg, 1)?></div>
			<div class="rp-stat-label">Avg / Month</div>
			<?=$_att_split($_monthly_avg_ip, $_monthly_avg_on, 1)?>
		</div>
<?php endif; ?>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_trend)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
<?php if ($_trend_pct !== null): ?>
			<div class="rp-stat-number" style="color:<?=$_trend_dir === 'up' ? '#059669' : ($_trend_dir === 'down' ? '#dc2626' : 'var(--rp-accent)')?>;">
				<?=$_trend_dir === 'up' ? '<i class="fas fa-arrow-up" style="font-size:18px;"></i>' : ($_trend_dir === 'down' ? '<i class="fas fa-arrow-down" style="font-size:18px;"></i>' : '<i class="fas fa-minus" style="font-size:18px;"></i>')?> <?=number_format(abs($_trend_pct), 1)?>%
			</div>
<?php else: ?>
			<div class="rp-stat-number" style="font-size:16px;color:var(--rp-text-hint);">—</div>
<?php endif; ?>
			<div class="rp-stat-label">Trend</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_peak)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-trophy"></i></div>
			<div class="rp-stat-number"><?=$_peak_count > 0 ? number_format($_peak_count) : '—'?></div>
			<div class="rp-stat-label">Peak Week</div>
<?php if ($_peak_date): ?>
			<div style="font-size:10px;color:var(--rp-text-hint);margin-top:2px;"><?=$_peak_date?></div>
<?php endif; ?>
		</div>
<?php if ($Type === 'Kingdom'): ?>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_parks)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=number_format($_unique_parks)?></div>
			<div class="rp-stat-label">Active Parks</div>
		</div>
<?php else: ?>
		<div class="rp-stat-card">
			<div class="rp-stat-tip"><span class="rp-stat-tip-icon" data-tip="<?=htmlspecialchars($_tip_weeks)?>">?</span></div>
			<div class="rp-stat-icon"><i class="fas fa-list-ol"></i></div>
			<div class="rp-stat-number"><?=number_format($_att_records)?></div>
			<div class="rp-stat-label">Total Weeks</div>
		</div>
<?php endif; ?>
	</div>

	<!-- Charts row -->
<?php if ($_att_records > 0): ?>
	<div class="rp-charts-row rp-charts-visible" id="rp-charts-row">
		<?php if ($_has_monthly): ?>
		<div class="rp-view-toggle">
			<button class="rp-view-btn rp-view-btn-active" id="att-view-wk" onclick="attSetView('weekly')">Weekly</button>
			<button class="rp-view-btn" id="att-view-mo" onclick="attSetView('monthly')">Monthly</button>
		</div>
		<?php endif; ?>
		<div id="attendance-chart" style="width:100%;height:370px;"></div>
	</div>
<?php endif; ?>

	<!-- Body -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<!-- About This Chart -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-info-circle"></i> Reading the Chart</div>
				<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;color:var(--rp-text-body);">
					<p style="margin:0 0 8px;">Each <strong class="rp-att-key-ip">indigo bar</strong> shows distinct players for one week who signed in at least once in person.</p>
					<p style="margin:0 0 8px;">The <strong class="rp-att-key-on">sky-blue segment</strong> stacked on top adds players who signed in only online that week, so the full bar is every distinct player.</p>
					<p style="margin:0 0 8px;"><strong style="color:#d97706;">Amber bars</strong> highlight weeks that include an event — useful for seeing how events impact turnout.</p>
					<p style="margin:0;">The <strong style="color:#059669;">green line</strong> is a 4-week rolling average that smooths out week-to-week noise so you can spot the real trend.</p>
				</div>
			</div>

			<!-- Column guide -->
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-columns"></i> Column Guide</div>
				<div class="rp-filter-card-body">
<?php if ($Type === 'All'): ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day date.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">Kingdom where attendance was recorded.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">Park chapter where attendance was recorded.</span>
					</div>
<?php else: ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">The park day date.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">In-Person</span>
						<span class="rp-col-guide-desc">Distinct players with at least one in-person sign-in.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Online</span>
						<span class="rp-col-guide-desc">Distinct players with at least one sign-in marked Online.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Total</span>
						<span class="rp-col-guide-desc">All distinct players who signed in during this period. A player with both kinds counts once in each split and once here.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Sign-ins</span>
						<span class="rp-col-guide-desc">Total attendance sign-in records for this period.</span>
					</div>
				</div>
			</div>

		</div><!-- /.rp-sidebar -->

		<!-- Tables column -->
		<div style="flex:1;min-width:0;max-width:100%;display:flex;flex-direction:column;gap:16px;">

<?php if (!empty($_event_rows)): ?>
		<!-- Events in period -->
		<div class="rp-table-area">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
				<i class="fas fa-calendar-alt" style="color:var(--rp-accent);font-size:14px;"></i>
				<span style="font-size:13px;font-weight:700;color:var(--rp-text);">Events in Period</span>
				<span style="font-size:12px;color:var(--rp-text-muted);">(<?=count($_event_rows)?>)</span>
			</div>
			<table id="events-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Event</th>
						<th class="dt-right"><?=$_att_th('In-Person', $_tip_col_ip)?></th>
						<th class="dt-right"><?=$_att_th('Online', $_tip_col_on)?></th>
						<th class="dt-right"><?=$_att_th('Total', $_tip_col_tot)?></th>
						<th class="dt-right"><?=$_att_th('Sign-ins', $_tip_col_sign)?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_event_rows as $ev): ?>
					<tr>
						<td>
							<?=date('Y-m-d', strtotime($ev['EventStart']))?> &mdash; <?=date('m-d', strtotime($ev['EventEnd']))?>
							&mdash;
							<a href="<?=UIR?>Event/detail/<?=$ev['EventId']?>/<?=$ev['EventCalendarDetailId']?>#ev-tab-attendance"><?=htmlspecialchars($ev['EventName'])?></a><?php if (!empty($ev['ParkName'])): ?>,
							<a href="<?=UIR?>Park/profile/<?=$ev['ParkId']?>" style="color:var(--rp-text-muted);"><?=htmlspecialchars($ev['ParkName'])?></a><?php endif; ?>
						</td>
						<td class="dt-right"><?=(int)($ev['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($ev['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$ev['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$ev['Attendees']?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>

<?php if (!empty($_kingdom_rows)): ?>
		<!-- Kingdom-level attendance -->
		<div class="rp-table-area">
			<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;">
				<i class="fas fa-chess-rook" style="color:var(--rp-accent);font-size:14px;"></i>
				<span style="font-size:13px;font-weight:700;color:var(--rp-text);">Kingdom-Level Attendance</span>
				<span style="font-size:12px;color:var(--rp-text-muted);">(<?=count($_kingdom_rows)?>)</span>
			</div>
			<p style="font-size:12px;color:var(--rp-text-muted);margin:0 0 10px;line-height:1.5;">These attendance records were entered at the kingdom level without a park or event. They may represent event credits or bulk entries that were not linked to a specific park.</p>
			<table id="kingdom-att-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
						<th class="dt-right"><?=$_att_th('In-Person', $_tip_col_ip)?></th>
						<th class="dt-right"><?=$_att_th('Online', $_tip_col_on)?></th>
						<th class="dt-right"><?=$_att_th('Total', $_tip_col_tot)?></th>
						<th class="dt-right"><?=$_att_th('Sign-ins', $_tip_col_sign)?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($_kingdom_rows as $kr): ?>
					<tr>
						<td><?=date('Y-m-d', strtotime($kr['Date']))?></td>
						<td class="dt-right"><?=(int)($kr['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($kr['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$kr['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$kr['Attendees']?></td>
					</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div>
<?php endif; ?>

		<!-- Attendance table -->
		<div class="rp-table-area">
			<table id="attendance-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
<?php if ($Type === 'All'): ?>
						<th>Kingdom</th>
						<th>Park</th>
<?php endif; ?>
						<th class="dt-right"><?=$_att_th('In-Person', $_tip_col_ip)?></th>
						<th class="dt-right"><?=$_att_th('Online', $_tip_col_on)?></th>
						<th class="dt-right"><?=$_att_th('Total', $_tip_col_tot)?></th>
						<th class="dt-right"><?=$_att_th('Sign-ins', $_tip_col_sign)?></th>
					</tr>
				</thead>
				<tbody>
<?php if ($Type === 'Park'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/park/<?=$date['ParkId']?>&AttendanceDate=<?=$date['Date']?>"><?=date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Event'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Event/detail/<?=$date['EventId']?>/<?=$date['EventCalendarDetailId']?>#ev-tab-attendance"><?=date('Y-m-d', strtotime($date['EventStart']))?></a></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php elseif ($Type === 'Kingdom'): ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td><a href="<?=UIR?>Attendance/kingdom/<?=$date['KingdomId']?>&AttendanceDate=<?=$date['Date']?>"><?=date('Y-m-d', strtotime($date['Date']))?></a></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php else: ?>
<?php foreach ($_parkday_rows as $date): ?>
					<tr>
						<td>
							<a href="<?=UIR?>Attendance/park/<?=$date['ParkId']?>&AttendanceDate=<?=date('Y-m-d', strtotime($date['Date']))?>">
								<?=date('Y-m-d', strtotime($date['Date']))?>
							</a>
						</td>
						<td><a href="<?=UIR?>Kingdom/profile/<?=$date['KingdomId']?>"><?=htmlspecialchars($date['KingdomName'])?></a></td>
						<td><a href="<?=UIR?>Park/profile/<?=$date['ParkId']?>"><?=htmlspecialchars($date['ParkName'])?></a></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersInPerson'] ?? 0)?></td>
						<td class="dt-right"><?=(int)($date['DistinctPlayersOnline'] ?? 0)?></td>
						<td class="dt-right"><?=(int)$date['DistinctPlayers']?></td>
						<td class="dt-right"><?=(int)$date['Attendees']?></td>
					</tr>
<?php endforeach; ?>
<?php endif; ?>
				</tbody>
			</table>
		</div><!-- /.rp-table-area -->

		</div><!-- /.tables-column -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
(function () {
	/* ── DataTable ── */
	var dtCols = [
<?php if ($Type === 'All'): ?>
		{ targets: [0], type: 'html' },
		{ targets: [1, 2], type: 'string' },
		{ targets: [3, 4, 5, 6], type: 'num', className: 'dt-right' }
<?php else: ?>
		{ targets: [0], type: 'html' },
		{ targets: [1, 2, 3, 4], type: 'num', className: 'dt-right' }
<?php endif; ?>
	];

	var dtOpts = {
		dom          : 'lfrtip',
		fixedHeader  : { headerOffset: 48 },
		columnDefs   : dtCols,
		order        : [[0, 'desc']],
		scrollX      : true
	};
<?php if ($Type === 'All'): ?>
	dtOpts.fixedColumns = { left: 1 };
<?php endif; ?>
	$('#attendance-table').DataTable(dtOpts);

	/* ── Events table ── */
	if ($('#events-table').length) {
		$('#events-table').DataTable({
			dom          : 'rt',
			paging       : false,
			info         : false,
			searching    : false,
			scrollX      : true,
			columnDefs   : [
				{ targets: [0], type: 'string' },
				{ targets: [1, 2, 3, 4], type: 'num', className: 'dt-right' }
			],
			order        : [[0, 'asc']]
		});
	}

	/* ── Kingdom-level table ── */
	if ($('#kingdom-att-table').length) {
		$('#kingdom-att-table').DataTable({
			dom          : 'rt',
			paging       : false,
			info         : false,
			searching    : false,
			scrollX      : true,
			columnDefs   : [
				{ targets: [0], type: 'html' },
				{ targets: [1, 2, 3, 4], type: 'num', className: 'dt-right' }
			],
			order        : [[0, 'desc']]
		});
	}

	/* ── Export CSV via DataTables button ── */
	$('#rp-btn-csv').on('click', function () {
		var dt = $('#attendance-table').DataTable();
		var csv = [];
		var headers = [];
		$('#attendance-table thead th').each(function () {
			var $th = $(this).clone();
			$th.find('.rp-col-tip').remove();
			headers.push($th.text().trim());
		});
		csv.push(headers.join(','));
		dt.rows({ search: 'applied' }).every(function () {
			var row = this.data();
			var cells = [];
			$.each(row, function (i, v) {
				var text = $('<div>').html(v).text().replace(/"/g, '""');
				cells.push('"' + text + '"');
			});
			csv.push(cells.join(','));
		});
		var blob = new Blob([csv.join('\n')], { type: 'text/csv' });
		var a = document.createElement('a');
		a.href = URL.createObjectURL(blob);
		a.download = 'attendance.csv';
		a.click();
	});

<?php if ($_att_records > 0): ?>
	/* ── Chart data ── */
	var chartCounts  = <?=json_encode($chart_counts)?>;
	var chartDates   = <?=json_encode($chart_dates)?>;
	var chartIsEvent = <?=json_encode($chart_is_event)?>;
	var chartInPerson = <?=json_encode($chart_in_person)?>;
	var chartOnline   = <?=json_encode($chart_online)?>;
	var chartOnlineOnly = <?=json_encode($chart_online_only)?>;
	var monthlyDates  = <?=json_encode($_monthly_dates)?>;
	var monthlyCounts = <?=json_encode($_monthly_counts)?>;
	var monthlyInPerson = <?=json_encode($_monthly_in_person)?>;
	var monthlyOnline   = <?=json_encode($_monthly_online)?>;
	var monthlyOnlineOnly = <?=json_encode($_monthly_online_only)?>;
	var attChart = null;
	var ATT_ONLINE_COLOR = '#0ea5e9';

	/* Stacked columns: in-person players (park days vs event weeks) plus the
	   online-only remainder, so the full bar is every distinct player. A player
	   with both kinds sits in the in-person part only. */
	var regularData = [];
	var eventData   = [];
	var onlineOnlyData = chartOnlineOnly;
	for (var i = 0; i < chartCounts.length; i++) {
		if (chartIsEvent[i]) {
			regularData.push(null);
			eventData.push(chartInPerson[i]);
		} else {
			regularData.push(chartInPerson[i]);
			eventData.push(null);
		}
	}

	/* Shared tooltip body for one bar, labelled like the legend: the In-Person and
	   Online only segments, the Total (bar height), and, when it differs from the
	   segment, how many players had any online sign-in (the tables' Online column). */
	function attSplitTip(ip, on, onlineOnly, tot) {
		var s = '<br/><span style="color:#4338ca">\u25A0</span> In-Person: <b>' + Highcharts.numberFormat(ip, 0) + '</b>'
			+ '<br/><span style="color:' + ATT_ONLINE_COLOR + '">\u25A0</span> Online only: <b>' + Highcharts.numberFormat(onlineOnly, 0) + '</b>'
			+ '<br/>Total: <b>' + Highcharts.numberFormat(tot, 0) + '</b>';
		if (on !== onlineOnly) {
			s += '<br/><span style="font-style:italic;">Any online sign-in: ' + Highcharts.numberFormat(on, 0) + '</span>';
		}
		return s;
	}
	function attLegendStyle() {
		return { fontSize: '12px', fontWeight: '600', color: attIsDark() ? '#cbd5e0' : '#4a5568' };
	}

	/* 4-week rolling average */
	var WINDOW = 4;
	var maData = [];
	for (var i = 0; i < chartCounts.length; i++) {
		if (i < WINDOW - 1) {
			maData.push(null);
		} else {
			var sum = 0;
			for (var j = i - WINDOW + 1; j <= i; j++) sum += chartCounts[j];
			maData.push(Math.round((sum / WINDOW) * 10) / 10);
		}
	}

	function attIsDark() {
		var a = document.documentElement.getAttribute('data-theme');
		return a === 'dark' || (!a && window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
	}
	function attTooltipTheme() {
		var dk = attIsDark();
		return { backgroundColor: dk ? '#1a2035' : '#fff', borderColor: dk ? '#818cf8' : '#ccc', style: { color: dk ? '#f1f5f9' : '#333' } };
	}

	function attBuildWeeklyChart() {
		return new Highcharts.Chart({
		chart  : { renderTo: 'attendance-chart', backgroundColor: 'transparent', style: { fontFamily: 'inherit' } },
		title  : { text: 'Distinct Players per Week' },
		xAxis  : {
			categories   : chartDates,
			tickInterval : Math.max(1, Math.ceil(chartDates.length / 12)),
			labels       : { rotation: -45, style: { fontSize: '11px' } }
		},
		yAxis  : {
			title : { text: 'Distinct Players' },
			min   : 0
		},
		tooltip: Object.assign({ shared: true,
			formatter: function () {
				var i = this.points[0].point.x;
				var s = '<b>' + this.x + '</b>';
				if (chartIsEvent[i]) {
					s += ' <span style="color:#d97706;font-style:italic;">(event week)</span>';
				}
				s += attSplitTip(chartInPerson[i], chartOnline[i], chartOnlineOnly[i], chartCounts[i]);
				this.points.forEach(function (pt) {
					if (pt.series.type !== 'column') {
						s += '<br/><span style="color:' + pt.color + '">\u2014</span> '
							+ pt.series.name + ': <b>' + Highcharts.numberFormat(pt.y, 1) + '</b>';
					}
				});
				return s;
			}
		}, attTooltipTheme()),
		plotOptions: {
			column: {
				stacking    : 'normal',
				borderRadius: 3,
				borderWidth : 0,
				groupPadding: 0,
				pointPadding: 0.05
			}
		},
		series : [
			{
				name : 'Online only',
				type : 'column',
				legendIndex: 2,
				data : onlineOnlyData,
				color: ATT_ONLINE_COLOR
			},
			{
				name : 'In-Person: Park Days',
				type : 'column',
				legendIndex: 0,
				data : regularData,
				color: '#4338ca',
				_isEvent: false
			},
			{
				name : 'In-Person: Event Weeks',
				type : 'column',
				legendIndex: 1,
				data : eventData,
				color: '#d97706',
				_isEvent: true
			},
			{
				name      : '4-Week Avg',
				legendIndex: 3,
				type      : 'spline',
				data      : maData,
				color     : '#059669',
				lineWidth : 2.5,
				marker    : { enabled: false },
				dashStyle : 'ShortDash',
				zIndex    : 5,
				connectNulls: false
			}
		],
		legend : {
			enabled     : true,
			align       : 'center',
			verticalAlign: 'bottom',
			itemStyle   : attLegendStyle(),
			symbolRadius: 3
		},
		credits: { enabled: false }
	});
	}

	function attBuildMonthlyChart() {
		var ma3 = monthlyCounts.map(function(_, i) {
			if (i < 2) return null;
			return Math.round((monthlyCounts[i] + monthlyCounts[i-1] + monthlyCounts[i-2]) / 3 * 10) / 10;
		});
		return new Highcharts.Chart({
			chart  : { renderTo: 'attendance-chart', style: { fontFamily: 'inherit' } },
			title  : { text: 'Distinct Players per Month' },
			xAxis  : { categories: monthlyDates, labels: { style: { fontSize: '11px' } } },
			yAxis  : { title: { text: 'Distinct Players' }, min: 0 },
			tooltip: Object.assign({ shared: true,
				formatter: function() {
					var i = this.points[0].point.x;
					var s = '<b>' + this.x + '</b>' + attSplitTip(monthlyInPerson[i], monthlyOnline[i], monthlyOnlineOnly[i], monthlyCounts[i]);
					this.points.forEach(function(pt) {
						if (pt.series.type !== 'column') {
							s += '<br/><span style="color:' + pt.color + '">\u2014</span> ' + pt.series.name + ': <b>' + Highcharts.numberFormat(pt.y, 1) + '</b>';
						}
					});
					return s;
				}
			}, attTooltipTheme()),
			plotOptions: { column: { stacking: 'normal', borderRadius: 3, borderWidth: 0, groupPadding: 0, pointPadding: 0.05 } },
			series: [
				{ name: 'Online only', legendIndex: 1, type: 'column', data: monthlyOnlineOnly, color: ATT_ONLINE_COLOR },
				{ name: 'In-Person', legendIndex: 0, type: 'column', data: monthlyInPerson, color: '#4338ca' },
				{ name: '3-Month Avg', legendIndex: 2, type: 'spline', data: ma3, color: '#059669', lineWidth: 2.5, marker: { enabled: false }, dashStyle: 'ShortDash', zIndex: 5, connectNulls: false }
			],
			legend : { enabled: true, align: 'center', verticalAlign: 'bottom', itemStyle: attLegendStyle(), symbolRadius: 3 },
			credits: { enabled: false }
		});
	}

	window.attSetView = function attSetView(view) {
		if (attChart) { attChart.destroy(); attChart = null; }
		document.getElementById('att-view-wk').classList.toggle('rp-view-btn-active', view === 'weekly');
		document.getElementById('att-view-mo').classList.toggle('rp-view-btn-active', view === 'monthly');
		attChart = (view === 'monthly') ? attBuildMonthlyChart() : attBuildWeeklyChart();
	}

	attChart = attBuildWeeklyChart();
	var _attView = 'weekly';
	var _origAttSetView = window.attSetView;
	window.attSetView = function(view) { _attView = view; _origAttSetView(view); };
	new MutationObserver(function() {
		if (attChart) { attChart.destroy(); }
		attChart = (_attView === 'monthly') ? attBuildMonthlyChart() : attBuildWeeklyChart();
	}).observe(document.documentElement, { attributeFilter: ['data-theme'] });
<?php endif; ?>
}());

/* ── Stat card info tooltips (JS-driven so no CSS dependency for hiding) ── */
(function() {
	var bub = document.createElement('div');
	bub.className = 'rp-stat-tip-text';
	bub.style.cssText = 'display:none;position:fixed;z-index:9999;pointer-events:none;';
	document.body.appendChild(bub);
	/* Delegated: DataTables moves/clones the table headers that carry .rp-col-tip icons. */
	document.addEventListener('mouseover', function(e) {
		var el = e.target.closest ? e.target.closest('.rp-stat-tip-icon[data-tip]') : null;
		if (!el) return;
		bub.textContent = el.dataset.tip;
		var r = el.getBoundingClientRect();
		bub.style.display = 'block';
		bub.style.top  = (r.bottom + 6) + 'px';
		bub.style.left = Math.max(4, Math.min(r.left, window.innerWidth - 214)) + 'px';
	});
	/* Header tip icons sit inside sortable <th>s: keep clicks/keys on them from sorting
	   (capture phase, so it runs before DataTables' header handlers). */
	['click', 'keydown'].forEach(function(type) {
		document.addEventListener(type, function(e) {
			if (e.target.closest && e.target.closest('.rp-col-tip')) e.stopPropagation();
		}, true);
	});
	document.addEventListener('mouseout', function(e) {
		var el = e.target.closest ? e.target.closest('.rp-stat-tip-icon[data-tip]') : null;
		if (el && !el.contains(e.relatedTarget)) bub.style.display = 'none';
	});
}());
</script>
