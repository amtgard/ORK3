<?php
/* ── Pre-compute stats ────────────────────────────────────── */
$total_eligible          = 0;
$total_province_eligible = 0;
$total_active_knights    = 0;
$total_active            = 0;
$unique_parks            = [];

if (!empty($NotSupported)) {
	$report_title = $page_title ?? 'Voting Eligible Players';
} else {
	$Players             = $Players ?? [];
	$AttendanceRequired    = $AttendanceRequired    ?? 6;
	$MonthsWindow          = $MonthsWindow          ?? 6;
	$MinMembershipMonths   = $MinMembershipMonths   ?? 6;
	$ProvinceMode          = $ProvinceMode          ?? false;
	$AttendanceMode      = $AttendanceMode      ?? 'weeks';
	$WeekOffset          = $WeekOffset          ?? 0;
	$KingdomEventBonus       = $KingdomEventBonus       ?? false;
	$ActiveKnightThreshold   = $ActiveKnightThreshold   ?? 0;
	$ActiveMemberThreshold   = $ActiveMemberThreshold   ?? 0;
	$ExcludeOnline           = $ExcludeOnline           ?? false;
	$HomeParkOnly            = $HomeParkOnly            ?? false;
	$DaysWindow              = $DaysWindow              ?? 0;
	$MinAge                  = $MinAge                  ?? 0;
	$AllKingdoms             = $AllKingdoms             ?? false;
	$MaxCreditsPerEvent      = $MaxCreditsPerEvent      ?? 0;
	$MaxOutsideKingdomCredits= $MaxOutsideKingdomCredits?? 0;
	$MembershipMode          = $MembershipMode          ?? '';
	$MemberSinceLabel        = $MembershipMode === 'first_attendance' ? 'First Attendance' : 'Member Since';
	// Window label for column headers (e.g. "6mo" or "180d") and phrase for descriptions.
	$WindowLabel  = $DaysWindow > 0 ? $DaysWindow . 'd'      : $MonthsWindow . 'mo';
	$WindowPhrase = $DaysWindow > 0 ? $DaysWindow . ' days'  : $MonthsWindow . ' months';
	$AttendanceLabel     = $HomeParkOnly ? 'Park Sign-ins' : ($AttendanceMode === 'count' ? 'Sign-ins' : ($AttendanceMode === 'days' ? 'Days' : 'Weeks'));
	// Human-readable week period label (e.g. "Mon–Sun" or "Tue–Mon")
	$_days = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
	$_startDay = $_days[$WeekOffset % 7];
	$_endDay   = $_days[($WeekOffset + 6) % 7];
	$WeekPeriodLabel = $_startDay . '–' . $_endDay;
	// Attendance window date span
	$_fmt_span_date = function($ts, $weekOffset) {
		$d    = (int)date('j', $ts);
		if ($d % 10 == 1 && $d % 100 != 11)      $sfx = 'st';
		elseif ($d % 10 == 2 && $d % 100 != 12)  $sfx = 'nd';
		elseif ($d % 10 == 3 && $d % 100 != 13)  $sfx = 'rd';
		else                                       $sfx = 'th';
		$week = (int)date('W', $ts - $weekOffset * 86400);
		return date('D M ', $ts) . $d . $sfx . date(', Y', $ts) . ' Week ' . $week;
	};
	// DisplayStartDate: raw date for header (e.g. actual 180-day mark for DaysWindow).
	// StartDate: SQL-snapped date (may differ for DaysWindow+weeks — snapped to week start).
	$StartDate        = $StartDate        ?? '';
	$DisplayStartDate = $DisplayStartDate ?? $StartDate;
	$_display_ts = !empty($DisplayStartDate) ? strtotime($DisplayStartDate)
	             : ($DaysWindow > 0 ? strtotime("-{$DaysWindow} days") : strtotime('-' . $MonthsWindow . ' months'));
	$AttendanceSpan = 'Attendance from ' . $_fmt_span_date($_display_ts, $WeekOffset)
	                . ' to ' . $_fmt_span_date(time(), $WeekOffset);
	$total_active_members = 0;
	foreach ($Players as $p) {
		$total_active++;
		if (!empty($p['VotingEligible'])) $total_eligible++;
		if ($ProvinceMode && !empty($p['ProvinceEligible'])) $total_province_eligible++;
		if ($ActiveKnightThreshold > 0 && !empty($p['ActiveKnight'])) $total_active_knights++;
		if ($ActiveMemberThreshold > 0 && !empty($p['ActiveMember'])) $total_active_members++;
		if (!empty($p['ParkId'])) $unique_parks[$p['ParkId']] = true;
	}
	$report_title = $page_title ?? 'Voting Eligible Players';
}

/* Scope chip */
$scope_label = '';
$scope_link  = '';
$scope_icon  = 'fa-globe';
if (!empty($Players)) {
	$first = reset($Players);
	if (isset($this->__session->park_id) && !empty($this->__session->park_id)) {
		$scope_label = $first['ParkName'] ?? '';
		$scope_link  = UIR . 'Park/profile/' . (int)$this->__session->park_id;
		$scope_icon  = 'fa-tree';
	} elseif (isset($this->__session->kingdom_id) && !empty($this->__session->kingdom_id)) {
		$scope_label = $first['KingdomName'] ?? '';
		$scope_link  = UIR . 'Kingdom/profile/' . (int)$this->__session->kingdom_id;
		$scope_icon  = 'fa-chess-rook';
	}
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(__DIR__.'/style/reports.css')?>">

<style>
.rp-table-area table.dataTable tbody tr.rp-row-eligible > td { background-color: #f0fff4; }
.rp-table-area table.dataTable tbody tr.rp-row-eligible:hover > td { background-color: #e6ffed; }
.rp-table-area table.dataTable tbody tr.rp-row-active-member > td { background-color: #ebf8ff; }
.rp-table-area table.dataTable tbody tr.rp-row-active-member:hover > td { background-color: #dbeafe; }
.rp-check { color: #276749; font-weight: 600; }
.rp-cross { color: #c53030; font-weight: 600; }
.rp-warn  { color: #c05621; font-weight: 600; }
.rp-not-supported {
	background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px;
	padding: 40px 32px; text-align: center; color: #718096; margin: 24px 0;
}
.rp-not-supported i { font-size: 2rem; margin-bottom: 12px; display: block; color: #a0aec0; }
.rp-not-supported h3 { margin: 0 0 8px; color: #4a5568; font-size: 1.1rem; }
.rp-not-supported p  { margin: 0; font-size: 0.9rem; }
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-vote-yea rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($report_title)?></h1>
			</div>
<?php if ($scope_label) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=$scope_link?>">
					<i class="fas <?=$scope_icon?>"></i>
					<?=htmlspecialchars($scope_label)?>
				</a>
			</div>
<?php endif; ?>
		</div>
<?php if (empty($NotSupported)) : ?>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
<?php endif; ?>
	</div>

<?php if (!empty($NotSupported)) : ?>

	<div class="rp-not-supported">
		<i class="fas fa-info-circle"></i>
		<h3>Not Available for This Kingdom</h3>
		<p>Voting eligibility reporting has not been configured for this kingdom.</p>
	</div>

<?php else : ?>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>
			<span style="display:block;font-size:0.85em;opacity:0.75;margin-bottom:4px;"><?=$AttendanceSpan?></span>
			<?php if ($ProvinceMode): ?>
			Eligibility requires: signed waiver &bull; current dues<?=$MinMembershipMonths > 0 ? ' &bull; chapter membership for at least ' . $MinMembershipMonths . ' months' . ($MembershipMode === 'first_attendance' ? ' (using first attendance in Kingdom)' : '') : ''?>.
				<strong>Kingdom:</strong> <?=$AttendanceRequired?>+ sign-ins anywhere in the Kingdom in the last <?=$WindowPhrase?><?=$KingdomEventBonus ? ' (attending a Kingdom-sponsored event counts as +1, once)' : ''?>.
				<strong>Provincial:</strong> <?=$AttendanceRequired?>+ sign-ins at home park in the last <?=$WindowPhrase?>. Provincial eligibility implies Kingdom eligibility.
			<?php elseif ($HomeParkOnly): ?>
			Eligibility requires: signed waiver &bull; current dues &bull; <?=$AttendanceRequired?>+ sign-ins at home park in the last <?=$WindowPhrase?><?=$KingdomEventBonus ? ' (attending a Kingdom-sponsored event counts as +1, once)' : ''?><?=$MinMembershipMonths > 0 ? ' &bull; chapter membership for at least ' . $MinMembershipMonths . ' months' . ($MembershipMode === 'first_attendance' ? ' (using first attendance in Kingdom)' : '') : ''?>.
			<?php else: ?>
			<?php if ($ActiveMemberThreshold > 0): ?>
			Requires signed waiver &bull; current dues &bull; <?=$AttendanceRequired?>+ <?=$AttendanceMode === 'days' ? 'calendar days' : ($AttendanceMode === 'count' ? 'sign-ins' : $WeekPeriodLabel . ' attendance weeks')?> in the last <?=$WindowPhrase?> <?=$AllKingdoms ? 'at any Amtgard event' : 'anywhere in the Kingdom'?> = <strong>Contributing Member</strong>. <?=$ActiveMemberThreshold?>+ = <strong>Active Member</strong>.
			<?php elseif ($MaxOutsideKingdomCredits > 0): ?>
			Eligibility requires: signed waiver &bull; current dues &bull; <?=$AttendanceRequired?>+ attendance credits in the last <?=$WindowPhrase?> (at most <?=$MaxOutsideKingdomCredits?> credits from outside the Kingdom<?=$MaxCreditsPerEvent > 0 ? '; multi-credit events capped at ' . $MaxCreditsPerEvent . ' credits per event' : ''?>)<?=$MinMembershipMonths > 0 ? ' &bull; province membership for at least ' . $MinMembershipMonths . ' months' : ''?>.
			<?php else: ?>
			Eligibility requires: signed waiver &bull; current dues &bull; <?=$AttendanceRequired?>+ distinct <?=$AttendanceMode === 'days' ? 'calendar days' : ($AttendanceMode === 'count' ? 'sign-ins' : $WeekPeriodLabel . ' attendance weeks')?> in the last <?=$WindowPhrase?> (anywhere in the Kingdom)<?=$MinMembershipMonths > 0 ? ' &bull; chapter membership for at least ' . $MinMembershipMonths . ' months' . ($MembershipMode === 'first_attendance' ? ' (using first attendance in Kingdom)' : '') : ''?>.<?php if ($ExcludeOnline): ?> Events that include "Online" in the event name are not included in attendance.<?php endif; ?><?php if ($ActiveKnightThreshold > 0): ?> <strong>Active Knight</strong> additionally requires being a Knight with <?=$ActiveKnightThreshold?>+ total sign-ins in the same period.<?php endif; ?>			<?php endif; ?>
			<?php endif; ?>
		</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-vote-yea"></i></div>
			<div class="rp-stat-number"><?=$total_eligible?></div>
			<div class="rp-stat-label"><?=$ActiveMemberThreshold > 0 ? 'Contributing+' : ($ProvinceMode ? 'Kingdom Eligible' : 'Eligible Voters')?></div>
		</div>
<?php if ($ActiveMemberThreshold > 0) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-star"></i></div>
			<div class="rp-stat-number"><?=$total_active_members?></div>
			<div class="rp-stat-label">Active Members</div>
		</div>
<?php endif; ?>
<?php if ($ProvinceMode) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
			<div class="rp-stat-number"><?=$total_province_eligible?></div>
			<div class="rp-stat-label">Province Eligible</div>
		</div>
<?php endif; ?>
<?php if ($ActiveKnightThreshold > 0) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chess-knight"></i></div>
			<div class="rp-stat-number"><?=$total_active_knights?></div>
			<div class="rp-stat-label">Active Knights</div>
		</div>
<?php endif; ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-users"></i></div>
			<div class="rp-stat-number"><?=$total_active?></div>
			<div class="rp-stat-label">Active Players</div>
		</div>
<?php if (!isset($this->__session->park_id)) : ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-tree"></i></div>
			<div class="rp-stat-number"><?=count($unique_parks)?></div>
			<div class="rp-stat-label">Parks Represented</div>
		</div>
<?php endif; ?>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-percentage"></i></div>
			<div class="rp-stat-number"><?=($total_active > 0 ? round(100 * $total_eligible / $total_active) : 0)?>%</div>
			<div class="rp-stat-label">Eligibility Rate</div>
		</div>
	</div>

	<!-- ── Body: sidebar + table ──────────────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-filter"></i> Filters
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-filter-pills" id="ve-filter-pills">
						<span class="rp-filter-pill" data-filter="eligible">Eligible Only</span>
						<span class="rp-filter-pill" data-filter="not-eligible">Non-Eligible Only</span>
<?php if ($ActiveKnightThreshold > 0) : ?>
						<span class="rp-filter-pill" data-filter="active-knight">Active Knights Only</span>
<?php endif; ?>
					</div>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
<?php if (!isset($this->__session->park_id)) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Persona</span>
						<span class="rp-col-guide-desc">Links to the player's profile.</span>
					</div>
<?php if ($ProvinceMode) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom</span>
						<span class="rp-col-guide-desc">Eligible to vote in kingdom-wide matters. Requires <?=$AttendanceRequired?>+ sign-ins anywhere in the Kingdom in the last <?=$WindowPhrase?>, plus waiver, dues, and chapter membership age.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Province</span>
						<span class="rp-col-guide-desc">Eligible to vote in home-park (provincial) matters. Requires <?=$AttendanceRequired?>+ sign-ins at home park in the last <?=$WindowPhrase?>. Province eligibility implies Kingdom eligibility.</span>
					</div>
<?php else : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Eligible</span>
						<span class="rp-col-guide-desc">All criteria met.</span>
					</div>
<?php endif; ?>
<?php if ($ActiveKnightThreshold > 0) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Active Knight</span>
						<span class="rp-col-guide-desc">Voting eligible + <?=$ActiveKnightThreshold?>+ total sign-ins in the last <?=$WindowPhrase?>. Amber shows count when close but not yet there.</span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Waiver</span>
						<span class="rp-col-guide-desc">Whether a signed waiver is on file.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Dues</span>
						<span class="rp-col-guide-desc">Current dues status and expiry date.</span>
					</div>
<?php if ($ProvinceMode) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Kingdom Sign-ins (<?=$WindowLabel?>)</span>
						<span class="rp-col-guide-desc">Total sign-ins anywhere in the Kingdom in the last <?=$WindowPhrase?><?=$KingdomEventBonus ? '. Attending a Kingdom-sponsored event counts as +1 (once, regardless of how many events attended)' : ''?>. Needs <?=$AttendanceRequired?>+.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Home Park Sign-ins (<?=$WindowLabel?>)</span>
						<span class="rp-col-guide-desc">Sign-ins at the player's home park in the last <?=$WindowPhrase?>. Needs <?=$AttendanceRequired?>+ for Provincial eligibility.</span>
					</div>
<?php elseif ($HomeParkOnly) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name"><?=$AttendanceLabel?> (<?=$WindowLabel?>)</span>
						<span class="rp-col-guide-desc">Sign-ins at the player's home park in the last <?=$WindowPhrase?><?=$KingdomEventBonus ? '. Attending a Kingdom-sponsored event counts as +1 (once)' : ''?>. Needs <?=$AttendanceRequired?>+.</span>
					</div>
<?php else : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name"><?=$AttendanceLabel?> (<?=$WindowLabel?>)</span>
						<span class="rp-col-guide-desc"><?=$AttendanceMode === 'count' ? 'Total sign-ins' : ($AttendanceMode === 'days' ? 'Distinct calendar days attended' : 'Distinct ' . $WeekPeriodLabel . ' weeks attended')?> <?=$AllKingdoms ? 'at any Amtgard event' : 'anywhere in the Kingdom'?> in the last <?=$WindowPhrase?>. Needs <?=$AttendanceRequired?>+.</span>
					</div>
<?php if ($ExcludeOnline) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Online Excluded</span>
						<span class="rp-col-guide-desc">Sign-ins at events or parks with "Online" in the name. These were found but not counted toward eligibility.</span>
					</div>
<?php endif; ?>
<?php endif; ?>
<?php if ($HomeParkOnly && $KingdomEventBonus) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">KE Credit</span>
						<span class="rp-col-guide-desc">Whether the player attended at least one Kingdom-sponsored event in the period. Counts as +1 toward the <?=$AttendanceRequired?> required sign-ins.</span>
					</div>
<?php endif; ?>
<?php if ($MaxOutsideKingdomCredits > 0) : ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Outside Credits</span>
						<span class="rp-col-guide-desc">Attendance credits earned outside the Kingdom. At most <?=$MaxOutsideKingdomCredits?> of these count toward the <?=$AttendanceRequired?> required<?=$MaxCreditsPerEvent > 0 ? '. Events (multi-session) are capped at ' . $MaxCreditsPerEvent . ' credits each' : ''?>. </span>
					</div>
<?php endif; ?>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name"><?=$MemberSinceLabel?></span>
						<span class="rp-col-guide-desc"><?=$MembershipMode === 'first_attendance' ? 'Date of first attendance in the Kingdom.' : 'Date first registered.'?><?=$MinMembershipMonths > 0 ? ' Must be ' . $MinMembershipMonths . '+ months ago' . ($MembershipMode === 'first_attendance' ? ' (using first attendance in Kingdom).' : '.') : ''?></span>
					</div>
				</div>
			</div>

		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div id="ve-loading" style="text-align:center;padding:40px 0;">
				<i class="fas fa-spinner fa-spin fa-2x" style="color:#999;"></i>
			</div>
			<div id="ve-table-wrap" style="opacity:0;">
			<table id="ve-report-table" class="display" style="width:100%">
				<thead>
					<tr>
<?php if (!isset($this->__session->park_id)) : ?>
						<th>Park</th>
<?php endif; ?>
						<th>Persona</th>
<?php if ($ProvinceMode) : ?>
						<th>Kingdom</th>
						<th>Province</th>
<?php elseif ($ActiveMemberThreshold > 0) : ?>
						<th>Member Tier</th>
<?php else : ?>
						<th>Eligible</th>
<?php endif; ?>
<?php if ($ActiveKnightThreshold > 0) : ?>
						<th>Active Knight</th>
<?php endif; ?>
						<th>Waiver</th>
						<th>Dues</th>
<?php if ($ProvinceMode) : ?>
						<th>Kingdom Sign-ins (<?=$WindowLabel?>)</th>
						<th>Home Park Sign-ins (<?=$WindowLabel?>)</th>
<?php else : ?>
						<th><?=$AttendanceLabel?> (<?=$WindowLabel?>)</th>
<?php if ($ExcludeOnline) : ?>
						<th>Online Excluded</th>
<?php endif; ?>
<?php endif; ?>
<?php if ($HomeParkOnly && $KingdomEventBonus) : ?>
						<th>KE Credit</th>
<?php endif; ?>
<?php if ($MaxOutsideKingdomCredits > 0) : ?>
						<th>Outside Credits</th>
<?php endif; ?>
						<th><?=$MemberSinceLabel?></th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($Players as $p) :
	$rowClass   = !empty($p['Suspended']) ? 'rp-row-suspended'
	            : (!empty($p['ActiveMember']) ? 'rp-row-active-member'
	            : (!empty($p['VotingEligible']) ? 'rp-row-eligible' : ''));
	$waiverHtml = $p['Waivered']
		? '<span class="rp-check"><i class="fas fa-check"></i> Yes</span>'
		: '<span class="rp-cross"><i class="fas fa-times"></i> No</span>';

	if ($p['DuesPaid']) {
		$duesDisplay = $p['DuesUntil'] === '9999-12-31'
			? '<span class="rp-check"><i class="fas fa-check"></i> For Life</span>'
			: '<span class="rp-check"><i class="fas fa-check"></i> Until ' . htmlspecialchars($p['DuesUntil']) . '</span>';
	} else {
		$duesDisplay = '<span class="rp-cross"><i class="fas fa-times"></i> No</span>';
	}

	$attNum   = (int)$p['AttCount'];
	$attReq   = (int)$AttendanceRequired;
	if ($attNum >= $attReq) {
		$attHtml = '<span class="rp-check">' . $attNum . '</span>';
	} elseif ($attNum >= $attReq - 2) {
		$attHtml = '<span class="rp-warn">' . $attNum . '</span>';
	} else {
		$attHtml = '<span class="rp-cross">' . $attNum . '</span>';
	}

	if ($ProvinceMode) {
		$parkAttNum = (int)($p['ParkAttCount'] ?? 0);
		if ($parkAttNum >= $attReq) {
			$parkAttHtml = '<span class="rp-check">' . $parkAttNum . '</span>';
		} elseif ($parkAttNum >= $attReq - 2) {
			$parkAttHtml = '<span class="rp-warn">' . $parkAttNum . '</span>';
		} else {
			$parkAttHtml = '<span class="rp-cross">' . $parkAttNum . '</span>';
		}
	}

	$memberSince = $p['MemberSince'] ?? '';
	if (!$memberSince || $memberSince === '0000-00-00') {
		$memberHtml = '<span class="rp-check">Unknown</span>';
	} elseif (!$p['MembershipOk']) {
		$memberHtml = '<span class="rp-cross">' . htmlspecialchars($memberSince) . '</span>';
	} else {
		$memberHtml = htmlspecialchars($memberSince);
	}

	if ($ProvinceMode) {
		$kingdomEligibleHtml  = !empty($p['KingdomEligible'])
			? '<span class="rp-check"><i class="fas fa-check-circle"></i> Yes</span>'
			: '<span style="color:#a0aec0;"><i class="fas fa-times-circle"></i> No</span>';
		$provinceEligibleHtml = !empty($p['ProvinceEligible'])
			? '<span class="rp-check"><i class="fas fa-check-circle"></i> Yes</span>'
			: '<span style="color:#a0aec0;"><i class="fas fa-times-circle"></i> No</span>';
	} elseif ($ActiveMemberThreshold > 0) {
		if (!empty($p['ActiveMember'])) {
			$eligibleHtml = '<span class="rp-check" style="color:#2b6cb0;"><i class="fas fa-star"></i> Active</span>';
		} elseif (!empty($p['VotingEligible'])) {
			$eligibleHtml = '<span class="rp-check"><i class="fas fa-check-circle"></i> Contributing</span>';
		} else {
			$eligibleHtml = '<span style="color:#a0aec0;"><i class="fas fa-times-circle"></i> —</span>';
		}
	} else {
		$eligibleHtml = !empty($p['VotingEligible'])
			? '<span class="rp-check"><i class="fas fa-check-circle"></i> Yes</span>'
			: '<span style="color:#a0aec0;"><i class="fas fa-times-circle"></i> No</span>';
	}

	if ($ActiveKnightThreshold > 0) {
		$rawCount  = (int)($p['RawAttCount'] ?? 0);
		$akReq     = (int)$ActiveKnightThreshold;
		$isKnight  = !empty($p['IsKnight']);
		if (!empty($p['ActiveKnight'])) {
			$activeKnightHtml = '<span class="rp-check"><i class="fas fa-chess-knight"></i> Yes</span>';
		} elseif ($isKnight && !empty($p['VotingEligible']) && $rawCount >= $akReq - 2) {
			// Knight, voting eligible, but just short on raw sign-ins — show progress
			$activeKnightHtml = '<span class="rp-warn"><i class="fas fa-chess-knight"></i> ' . $rawCount . '/' . $akReq . '</span>';
		} elseif (!$isKnight) {
			$activeKnightHtml = '<span style="color:#a0aec0;">—</span>';
		} else {
			$activeKnightHtml = '<span style="color:#a0aec0;"><i class="fas fa-times-circle"></i> No</span>';
		}
	}
?>
				<tr class="<?=$rowClass?>"
				data-eligible="<?=!empty($p['VotingEligible'])?'1':'0'?>"
				data-waivered="<?=$p['Waivered']?'1':'0'?>"
				data-dues="<?=$p['DuesPaid']?'1':'0'?>"
				data-att="<?=(int)$p['AttCount']?>"
				data-suspended="<?=!empty($p['Suspended'])?'1':'0'?>"
				data-active-knight="<?=!empty($p['ActiveKnight'])?'1':'0'?>">
<?php if (!isset($this->__session->park_id)) : ?>
					<td><a href='<?=UIR.'Park/profile/'.$p['ParkId']?>'><?=htmlspecialchars($p['ParkName'])?></a></td>
<?php endif; ?>
					<td>
						<a href='<?=UIR.'Player/profile/'.$p['MundaneId']?>'><?=htmlspecialchars($p['Persona'])?></a>
<?php if (!empty($p['Suspended'])) :
	$_until = $p['SuspendedUntil'] ?? '';
	$_until_str = ($_until && $_until !== '0000-00-00') ? ' until ' . htmlspecialchars($_until) : '';
?>
						<span style="font-size:11px;font-weight:600;color:#c53030;margin-left:6px;"><i class="fas fa-ban"></i> Suspended<?=$_until_str?></span>
<?php endif; ?>
					</td>
<?php if ($ProvinceMode) : ?>
					<td><?=$kingdomEligibleHtml?></td>
					<td><?=$provinceEligibleHtml?></td>
<?php else : ?>
					<td><?=$eligibleHtml?></td>
<?php endif; ?>
<?php if ($ActiveKnightThreshold > 0) : ?>
					<td><?=$activeKnightHtml?></td>
<?php endif; ?>
					<td><?=$waiverHtml?></td>
					<td><?=$duesDisplay?></td>
<?php if ($ProvinceMode) : ?>
					<td><?=$attHtml?></td>
					<td><?=$parkAttHtml?></td>
<?php else : ?>
					<td><?=$attHtml?></td>
<?php if ($ExcludeOnline) : ?>
					<?php $_onEx = (int)($p['OnlineExcluded'] ?? 0); ?>
					<td><?= $_onEx > 0 ? '<span class="rp-warn">' . $_onEx . '</span>' : '<span style="opacity:0.4">—</span>' ?></td>
<?php endif; ?>
<?php endif; ?>
<?php if ($HomeParkOnly && $KingdomEventBonus) : ?>
					<td><?= !empty($p['KingdomEventCredit']) ? '<span class="rp-check"><i class="fas fa-check"></i> Yes</span>' : '<span style="opacity:0.4">—</span>' ?></td>
<?php endif; ?>
<?php if ($MaxOutsideKingdomCredits > 0) : ?>
					<?php $_outCr = (int)($p['OutsideCredits'] ?? 0); ?>
					<td><?= $_outCr ?> / <?=$MaxOutsideKingdomCredits?></td>
<?php endif; ?>
					<td><?=$memberHtml?></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
			</div><!-- /ve-table-wrap -->
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

<?php endif; ?>

</div><!-- /rp-root -->


<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>

<?php if (empty($NotSupported)) : ?>
<script>
$(function() {
	var activeFilter = null;

	$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
		if (settings.nTable.id !== 've-report-table') return true;
		if (!activeFilter) return true;
		var $tr = $(settings.aoData[dataIndex].nTr);
		if (activeFilter === 'eligible')      return $tr.data('eligible') == 1;
		if (activeFilter === 'not-eligible')  return $tr.data('eligible') == 0;
		if (activeFilter === 'active-knight') return $tr.data('active-knight') == 1;
		return true;
	});

	var table = $('#ve-report-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: '<?=addslashes($report_title)?>', exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 50,
		lengthMenu: [[25, 50, 100, -1], [25, 50, 100, 'All']],
		order: [[0, 'asc']],
		scrollX: true,
		initComplete: function() {
			$('#ve-loading').hide();
			$('#ve-table-wrap').css('opacity', 1);
		}
	});

	$('#ve-filter-pills .rp-filter-pill').on('click', function() {
		var filter = $(this).data('filter');
		if (activeFilter === filter) {
			activeFilter = null;
			$(this).removeClass('active');
		} else {
			activeFilter = filter;
			$('#ve-filter-pills .rp-filter-pill').removeClass('active');
			$(this).addClass('active');
		}
		table.draw();
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });
});
</script>
<?php endif; ?>
