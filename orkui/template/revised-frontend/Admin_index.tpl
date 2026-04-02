<?php
/* -----------------------------------------------
   Pre-process template data
   ----------------------------------------------- */
$kingdomList = is_array($ActiveKingdomSummary['ActiveKingdomsSummaryList'] ?? null)
	? $ActiveKingdomSummary['ActiveKingdomsSummaryList']
	: [];

$kingdoms       = array_values(array_filter($kingdomList, fn($k) => !$k['IsPrincipality']));
$principalities = array_filter($kingdomList, fn($k) => $k['IsPrincipality']);

$totalParks      = array_sum(array_column($kingdomList, 'ParkCount'));
$totalKingdoms   = count($kingdoms);
$totalAttendance = array_sum(array_column($kingdomList, 'Attendance'));
$uir             = UIR;
$prevWeekly  = is_array($PrevWeekly  ?? null) ? $PrevWeekly  : [];
$prevMonthly = is_array($PrevMonthly ?? null) ? $PrevMonthly : [];

function cpTrend(int $cur, ?int $prev): string {
	if ($prev === null) return '';
	if ($cur > $prev) return ' <span class="kn-trend kn-trend-up" title="Up from ' . number_format($prev) . ' prev period">&#9650;</span>';
	if ($cur < $prev) return ' <span class="kn-trend kn-trend-dn" title="Down from ' . number_format($prev) . ' prev period">&#9660;</span>';
	return '';
}
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<!-- =============================================
     HERO
     ============================================= -->
<div class="cp-hero">
	<div class="cp-hero-content">
		<div class="cp-hero-icon">
			<i class="fas fa-shield-alt"></i>
		</div>

		<div class="cp-hero-info">
			<h1 class="cp-hero-title">ORK Administration</h1>
			<div class="cp-hero-sub">System control panel &mdash; global operations &amp; reporting</div>
			<div class="cp-hero-badges">
				<span class="cp-hero-badge"><i class="fas fa-circle" style="font-size:7px;color:#68d391"></i> System Online</span>
				<span class="cp-hero-badge"><i class="fas fa-database"></i> ORK v3</span>
				<a class="cp-hero-badge" href="https://github.com/amtgard/ORK3" target="_blank" style="text-decoration:none">
					<i class="fab fa-github"></i> Source
				</a>
			</div>
		</div>

		<div class="cp-hero-stats">
			<div class="cp-hero-stat">
				<span class="cp-hero-stat-val"><?= count($kingdoms) ?></span>
				<span class="cp-hero-stat-lbl">Kingdoms</span>
			</div>
			<div class="cp-hero-stat-div"></div>
			<div class="cp-hero-stat">
				<span class="cp-hero-stat-val"><?= number_format($totalParks) ?></span>
				<span class="cp-hero-stat-lbl">Active Parks</span>
			</div>
			<div class="cp-hero-stat-div"></div>
			<div class="cp-hero-stat">
				<span class="cp-hero-stat-val"><?= number_format($TotalActivePlayers) ?></span>
				<span class="cp-hero-stat-lbl">Players (26wk)</span>
			</div>
		</div>
	</div>
</div>

<!-- =============================================
     TREND STATS ROW
     ============================================= -->
<?php
$_ts = is_array($TrendStats ?? null) ? $TrendStats : [];
function _cp_trend($cur, $prev, $fmt = 'number') {
	$val = $fmt === 'number' ? number_format($cur) : $cur;
	if ($prev == 0) return '<span class="cp-ts-val">' . $val . '</span>';
	$pct = round((($cur - $prev) / $prev) * 100);
	if ($cur > $prev) {
		$arrow = '<span class="cp-ts-trend cp-ts-up"><i class="fas fa-arrow-up"></i> ' . abs($pct) . '%</span>';
	} elseif ($cur < $prev) {
		$arrow = '<span class="cp-ts-trend cp-ts-down"><i class="fas fa-arrow-down"></i> ' . abs($pct) . '%</span>';
	} else {
		$arrow = '<span class="cp-ts-trend cp-ts-flat">—</span>';
	}
	return '<span class="cp-ts-val">' . $val . '</span>' . $arrow;
}
?>
<div class="cp-ts-row">
	<div class="cp-ts-card">
		<div class="cp-ts-icon"><i class="fas fa-medal"></i></div>
		<div class="cp-ts-body">
			<div class="cp-ts-num"><?= _cp_trend($_ts['awards_cur'] ?? 0, $_ts['awards_prev'] ?? 0) ?></div>
			<div class="cp-ts-lbl">Awards This Year</div>
			<div class="cp-ts-sub">vs <?= number_format($_ts['awards_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
	<div class="cp-ts-card">
		<div class="cp-ts-icon"><i class="fas fa-calendar-check"></i></div>
		<div class="cp-ts-body">
			<div class="cp-ts-num"><?= _cp_trend($_ts['att_cur'] ?? 0, $_ts['att_prev'] ?? 0) ?></div>
			<div class="cp-ts-lbl">Attendance This Year</div>
			<div class="cp-ts-sub">vs <?= number_format($_ts['att_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
	<div class="cp-ts-card">
		<div class="cp-ts-icon"><i class="fas fa-users"></i></div>
		<div class="cp-ts-body">
			<div class="cp-ts-num"><?= _cp_trend($_ts['players_cur'] ?? 0, $_ts['players_prev'] ?? 0) ?></div>
			<div class="cp-ts-lbl">Active Players (1yr)</div>
			<div class="cp-ts-sub">vs <?= number_format($_ts['players_prev'] ?? 0) ?> in the prior year</div>
		</div>
	</div>
	<div class="cp-ts-card">
		<div class="cp-ts-icon"><i class="fas fa-star"></i></div>
		<div class="cp-ts-body">
			<div class="cp-ts-num"><?= _cp_trend($_ts['recs_cur'] ?? 0, $_ts['recs_prev'] ?? 0) ?></div>
			<div class="cp-ts-lbl">Recommendations This Year</div>
			<div class="cp-ts-sub">vs <?= number_format($_ts['recs_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
</div>

<!-- =============================================
     MAIN LAYOUT
     ============================================= -->
<div class="cp-layout">

	<!-- ==========================================
	     LEFT COLUMN — Actions
	     ========================================== -->
	<div class="cp-main">

		<!-- Kingdoms -->
		<div class="cp-section">
			<div class="cp-section-title"><i class="fas fa-crown"></i> Kingdoms</div>
			<div class="cp-action-grid">
				<button class="cp-action-card" onclick="cpOpenModal('cp-createkingdom-overlay')">
					<div class="cp-action-icon cp-action-icon-blue"><i class="fas fa-plus-circle"></i></div>
					<div class="cp-action-label">Create Kingdom</div>
					<div class="cp-action-desc">Add a new kingdom or principality</div>
				</button>
				<button class="cp-action-card" onclick="cpOpenModal('cp-createpark-overlay')">
					<div class="cp-action-icon cp-action-icon-green"><i class="fas fa-tree"></i></div>
					<div class="cp-action-label">Create Park</div>
					<div class="cp-action-desc">Add a new park to any kingdom</div>
				</button>
			</div>
		</div>

		<!-- Players -->
		<div class="cp-section">
			<div class="cp-section-title"><i class="fas fa-user"></i> Players</div>
			<div class="cp-action-grid">
				<button class="cp-action-card" onclick="cpOpenModal('cp-createplayer-overlay')">
					<div class="cp-action-icon cp-action-icon-green"><i class="fas fa-user-plus"></i></div>
					<div class="cp-action-label">Create Player</div>
					<div class="cp-action-desc">Register a new player account</div>
				</button>
				<button class="cp-action-card" onclick="cpOpenModal('cp-moveplayer-overlay')">
					<div class="cp-action-icon cp-action-icon-blue"><i class="fas fa-exchange-alt"></i></div>
					<div class="cp-action-label">Move Player</div>
					<div class="cp-action-desc">Transfer a player to a different park</div>
				</button>
				<button class="cp-action-card" onclick="cpOpenModal('cp-mergeplayer-overlay')">
					<div class="cp-action-icon cp-action-icon-purple"><i class="fas fa-compress-arrows-alt"></i></div>
					<div class="cp-action-label">Merge Players</div>
					<div class="cp-action-desc">Combine two duplicate player records</div>
				</button>
				</div>
		</div>

		<!-- Parks -->
		<div class="cp-section">
			<div class="cp-section-title"><i class="fas fa-map-marker-alt"></i> Parks</div>
			<div class="cp-action-grid">
				<button class="cp-action-card" onclick="cpOpenTransferPark()">
					<div class="cp-action-icon cp-action-icon-blue"><i class="fas fa-map-signs"></i></div>
					<div class="cp-action-label">Transfer Park</div>
					<div class="cp-action-desc">Move a park to a different kingdom</div>
				</button>
				<button class="cp-action-card" onclick="cpOpenModal('cp-mergepark-overlay')">
					<div class="cp-action-icon cp-action-icon-purple"><i class="fas fa-object-group"></i></div>
					<div class="cp-action-label">Migrate Park Members</div>
					<div class="cp-action-desc">Move all members from one park to another</div>
				</button>
			</div>
		</div>

		<!-- Units & Auth -->
		<div class="cp-section">
			<div class="cp-section-title"><i class="fas fa-users-cog"></i> Units &amp; Authorization</div>
			<div class="cp-action-grid">
				<button class="cp-action-card" onclick="cpOpenModal('cp-mergeunit-overlay')">
					<div class="cp-action-icon cp-action-icon-purple"><i class="fas fa-layer-group"></i></div>
					<div class="cp-action-label">Merge Units</div>
					<div class="cp-action-desc">Combine two duplicate unit records</div>
				</button>
				<a class="cp-action-card" href="<?= UIR ?>Admin/permissions">
					<div class="cp-action-icon cp-action-icon-red"><i class="fas fa-shield-alt"></i></div>
					<div class="cp-action-label">Global Permissions</div>
					<div class="cp-action-desc">Manage system admins &amp; kingdom access</div>
				</a>
				</div>
		</div>

		<!-- Kingdom Overview Table -->
		<div class="cp-overview-wrap">
			<div class="cp-overview-header">
				<div class="cp-overview-title">
					<i class="fas fa-table"></i> Kingdom Overview
					<span class="adm-count"><?= count($kingdomList) ?></span>
				</div>
				<div class="cp-overview-search">
					<input type="text" id="cp-kd-search" placeholder="Filter kingdoms…" oninput="cpFilterTable(this.value)">
				</div>
			</div>
			<div style="overflow-x:auto">
				<table class="cp-kd-table" id="cp-kd-table">
					<thead>
						<tr>
							<th onclick="cpSort(0)" id="cp-th-0" class="cp-sort-asc">Kingdom</th>
							<th onclick="cpSort(1)" id="cp-th-1" class="cp-th-num">Parks</th>
							<th onclick="cpSort(2)" id="cp-th-2" class="cp-th-num">Players <span style="font-weight:400;text-transform:none;letter-spacing:0">(26wk)</span></th>
							<th onclick="cpSort(3)" id="cp-th-3" class="cp-th-num">Monthly <span style="font-weight:400;text-transform:none;letter-spacing:0">(1yr)</span></th>
							<th onclick="cpSort(4)" id="cp-th-4" class="cp-th-num">Active Parks <span style="font-weight:400;text-transform:none;letter-spacing:0">(4wk)</span></th>
						</tr>
					</thead>
					<tbody id="cp-kd-tbody">
					<?php
					$sorted = $kingdoms;
					usort($sorted, fn($a,$b) => strcmp($a['KingdomName'], $b['KingdomName']));
										foreach ($sorted as $k):
						$pid = (int)$k['KingdomId'];
						$prinzs = array_filter($principalities, fn($p) => (int)$p['ParentKingdomId'] === $pid);
						usort($prinzs, fn($a,$b) => strcmp($a['KingdomName'], $b['KingdomName']));
						$allRows = array_merge([$k], $prinzs);
						foreach ($allRows as $row):
							$isPrinz  = (bool)$row['IsPrincipality'];
					?>
					<tr data-name="<?= htmlspecialchars(strtolower($row['KingdomName'])) ?>">
						<td>
							<a href="<?= UIR ?>Kingdom/profile/<?= (int)$row['KingdomId'] ?>" style="color:#3182ce;text-decoration:none;font-weight:<?= $isPrinz ? '400' : '600' ?>">
								<?= $isPrinz ? '&ensp;↳ ' : '' ?><?= htmlspecialchars($row['KingdomName']) ?>
							</a>
							<?php if ($isPrinz): ?><span class="cp-kd-principality">Principality</span><?php endif; ?>
						</td>
						<td class="cp-td-num"><?= number_format((int)$row['ParkCount']) ?></td>
						<td class="cp-td-num"><?= number_format((int)$row['Attendance']) . cpTrend((int)$row['Attendance'], $prevWeekly[(int)$row['KingdomId']] ?? null) ?></td>
						<td class="cp-td-num"><?= number_format((int)$row['Monthly']) . cpTrend((int)$row['Monthly'], $prevMonthly[(int)$row['KingdomId']] ?? null) ?></td>
						<td class="cp-td-num"><?= number_format((int)$row['Participation']) ?></td>
					</tr>
					<?php endforeach; endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>

	</div><!-- /.cp-main -->

	<!-- ==========================================
	     RIGHT COLUMN — Reports & Links
	     ========================================== -->
	<div class="cp-sidebar">

		<div class="adm-card">
			<div class="adm-card-header">
				<div class="adm-card-title"><i class="fas fa-chart-bar"></i> Reports</div>
			</div>
			<ul class="cp-report-list">
				<li><a href="<?= UIR ?>Reports/kingdom_officer_directory"><i class="fas fa-crown"></i><span>Kingdom Officer Directory<span class="cp-report-list-desc">All kingdom officers by role</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/suspended"><i class="fas fa-user-clock"></i><span>Suspended Players<span class="cp-report-list-desc">Active and past suspensions</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/knights_and_masters"><i class="fas fa-chess-king"></i><span>Knights &amp; Masters<span class="cp-report-list-desc">All knighted and master-level players</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/player_awards&Ladder=8"><i class="fas fa-medal"></i><span>Kingdom-level Awards<span class="cp-report-list-desc">Players with kingdom-tier recognition</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/class_masters"><i class="fas fa-graduation-cap"></i><span>Class Masters / Paragons<span class="cp-report-list-desc">Top players by class level</span></span></a></li>
				<li><a href="<?= UIR ?>Unit/unitlist"><i class="fas fa-users"></i><span>Companies &amp; Households<span class="cp-report-list-desc">All registered units</span></span></a></li>
				<li><a href="<?= UIR ?>Admin/topparks"><i class="fas fa-trophy"></i><span>Top Parks by Attendance<span class="cp-report-list-desc">Ranked attendance report</span></span></a></li>
				<li><a href="<?= UIR ?>Admin/new_player_attendance"><i class="fas fa-star"></i><span>New Player Attendance<span class="cp-report-list-desc">First-time attendees by kingdom</span></span></a></li>
			</ul>
		</div>

		<div class="adm-card">
			<div class="adm-card-header">
				<div class="adm-card-title"><i class="fas fa-link"></i> Quick Links</div>
			</div>
			<ul class="cp-report-list">
				<li><a href="https://github.com/amtgard/ORK3" target="_blank"><i class="fab fa-github"></i><span>Source Code<span class="cp-report-list-desc">ORK3 on GitHub</span></span></a></li>
				<li><a href="<?= UIR ?>Admin/permissions"><i class="fas fa-shield-alt"></i><span>Global Permissions<span class="cp-report-list-desc">System admins &amp; kingdom access</span></span></a></li>
			</ul>
		</div>

	</div><!-- /.cp-sidebar -->

</div><!-- /.cp-layout -->


<!-- =============================================
     MODALS
     ============================================= -->
<style>
/* Shared modal overlay */
.cp-overlay {
	display: none; position: fixed; inset: 0; z-index: 2000;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
.cp-overlay.cp-open { display: flex; }
.cp-modal-box {
	background: #fff; border-radius: 10px; width: 520px; max-width: calc(100vw - 32px);
	max-height: calc(100vh - 48px); display: flex; flex-direction: column;
	box-shadow: 0 8px 32px rgba(0,0,0,0.22);
}
.cp-modal-header {
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
	display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.cp-modal-title {
	font-size: 16px; font-weight: 700; color: #1a202c; margin: 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.cp-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #a0aec0; line-height: 1; padding: 2px 6px; }
.cp-modal-close:hover { color: #2d3748; }
.cp-modal-body { padding: 20px; overflow-y: auto; flex: 1; }
.cp-modal-footer { padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-shrink: 0; background: #f7fafc; border-radius: 0 0 10px 10px; }
/* Field rows */
.cp-field { margin-bottom: 14px; }
.cp-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 5px; }
.cp-field input[type=text], .cp-field input[type=email], .cp-field input[type=password], .cp-field input[type=date], .cp-field select, .cp-field textarea {
	width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 6px;
	font-size: 13px; color: #2d3748; background: #fff; box-sizing: border-box;
}
.cp-field input:focus, .cp-field select:focus, .cp-field textarea:focus {
	outline: none; border-color: #90cdf4; box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
}
.cp-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
.cp-field-ac { position: relative; }
.cp-field-ac .kn-ac-results { position: absolute; left: 0; right: 0; z-index: 9999; }
/* Feedback */
.cp-feedback { padding: 10px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 14px; display: none; }
.cp-feedback-ok  { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
.cp-feedback-err { background: #fed7d7; color: #9b2c2c; border: 1px solid #feb2b2; }
/* Warning box */
.cp-warning { background: #fffbeb; border: 1px solid #f6e05e; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #744210; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-start; }
.cp-warning i { flex-shrink: 0; margin-top: 2px; }
/* Radio group */
.cp-radio-group { display: flex; gap: 16px; }
.cp-radio-group label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }
/* inline autocomplete dropdown (kn-ac-results already in revised.css) */
</style>

<!-- ---- Create Player ---- -->
<div class="cp-overlay" id="cp-createplayer-overlay">
	<div class="cp-modal-box" style="width:560px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-createplayer-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-cp-feedback"></div>
			<div class="cp-field cp-field-ac">
				<label>Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-cp-park-name" autocomplete="off" placeholder="Search parks…">
				<input type="hidden" id="cp-cp-park-id">
				<div class="kn-ac-results" id="cp-cp-park-results"></div>
			</div>
			<div class="cp-field-row">
				<div class="cp-field">
					<label>Persona <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-cp-persona" placeholder="In-game name">
				</div>
				<div class="cp-field">
					<label>Email</label>
					<input type="email" id="cp-cp-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="cp-field-row">
				<div class="cp-field">
					<label>Given Name</label>
					<input type="text" id="cp-cp-given" placeholder="Mundane first name">
				</div>
				<div class="cp-field">
					<label>Surname</label>
					<input type="text" id="cp-cp-surname" placeholder="Mundane surname">
				</div>
			</div>
			<div class="cp-field-row">
				<div class="cp-field">
					<label>Username <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-cp-username" autocomplete="new-password" placeholder="min. 4 characters">
				</div>
				<div class="cp-field">
					<label>Password <span style="color:#e53e3e">*</span></label>
					<input type="password" id="cp-cp-password" autocomplete="new-password" placeholder="password">
				</div>
			</div>
			<div class="cp-field-row">
				<div class="cp-field">
					<label>Restricted</label>
					<div class="cp-radio-group">
						<label><input type="radio" name="cp-cp-restricted" value="0" checked> No</label>
						<label><input type="radio" name="cp-cp-restricted" value="1"> Yes</label>
					</div>
				</div>
				<div class="cp-field">
					<label>Waivered</label>
					<div class="cp-radio-group">
						<label><input type="radio" name="cp-cp-waivered" value="0" checked> No</label>
						<label><input type="radio" name="cp-cp-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-createplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="cp-cp-submit"><i class="fas fa-user-plus"></i> Create Player</button>
		</div>
	</div>
</div>

<!-- ---- Move Player ---- -->
<div class="cp-overlay" id="cp-moveplayer-overlay">
	<div class="cp-modal-box">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-moveplayer-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-mp-feedback"></div>
			<div class="cp-field cp-field-ac">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mp-player-name" autocomplete="off" placeholder="Search all players…">
				<input type="hidden" id="cp-mp-player-id">
				<div class="kn-ac-results" id="cp-mp-player-results"></div>
			</div>
			<div class="cp-field cp-field-ac" style="margin-top:12px">
				<label>New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mp-park-name" autocomplete="off" placeholder="Search all parks…">
				<input type="hidden" id="cp-mp-park-id">
				<div class="kn-ac-results" id="cp-mp-park-results"></div>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-moveplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="cp-mp-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- ---- Merge Players ---- -->
<div class="cp-overlay" id="cp-mergeplayer-overlay">
	<div class="cp-modal-box" style="width:560px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-mergeplayer-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-mgp-feedback"></div>
			<div class="cp-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div><strong>This action is permanent and cannot be undone.</strong><br>
				The <em>Remove</em> player's account will be deleted. All their awards, attendance, officer history, and unit memberships transfer to the <em>Keep</em> player.</div>
			</div>
			<div class="cp-field cp-field-ac">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mgp-keep-name" autocomplete="off" placeholder="Search for player to keep…">
				<input type="hidden" id="cp-mgp-keep-id">
				<div class="kn-ac-results" id="cp-mgp-keep-results"></div>
			</div>
			<div class="cp-field cp-field-ac" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mgp-remove-name" autocomplete="off" placeholder="Search for player to remove…">
				<input type="hidden" id="cp-mgp-remove-id">
				<div class="kn-ac-results" id="cp-mgp-remove-results"></div>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-mergeplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="cp-mgp-submit" disabled><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- ---- Transfer Park ---- -->
<div class="cp-overlay" id="cp-transferpark-overlay">
	<div class="cp-modal-box">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-map-signs" style="margin-right:8px;color:#2b6cb0"></i>Transfer Park</h3>
			<button class="cp-modal-close" id="cp-tp-close-btn">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-tp-feedback"></div>
			<!-- Step 1: Search -->
			<div id="cp-tp-search-panel">
				<div class="cp-field cp-field-ac">
					<label>Park to Transfer <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-tp-park-name" autocomplete="off" placeholder="Search all parks…">
					<input type="hidden" id="cp-tp-park-id">
					<input type="hidden" id="cp-tp-source-kingdom">
					<div class="kn-ac-results" id="cp-tp-park-results"></div>
				</div>
				<div class="cp-field cp-field-ac" style="margin-top:12px">
					<label>Destination Kingdom <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-tp-kingdom-name" autocomplete="off" placeholder="Search kingdoms…">
					<input type="hidden" id="cp-tp-kingdom-id">
					<div class="kn-ac-results" id="cp-tp-kingdom-results"></div>
				</div>
			</div>
			<!-- Step 2: Confirm -->
			<div id="cp-tp-confirm-panel" style="display:none">
				<p style="font-size:14px;color:#2d3748;margin:0 0 16px">Confirm the following transfer:</p>
				<table style="width:100%;font-size:13px;border-collapse:collapse">
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">Park</td>
						<td style="padding:6px 0;font-weight:600" id="cp-tp-confirm-park"></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">From</td>
						<td style="padding:6px 0" id="cp-tp-confirm-from"></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">To</td>
						<td style="padding:6px 0;font-weight:600" id="cp-tp-confirm-to"></td>
					</tr>
					<tr>
						<td style="padding:6px 10px 6px 0;color:#718096;white-space:nowrap">Abbreviation</td>
						<td style="padding:6px 0;font-family:monospace" id="cp-tp-confirm-abbr"></td>
					</tr>
				</table>
				<div id="cp-tp-abbr-warning" class="cp-warning" style="display:none;margin-top:12px">
					<i class="fas fa-exclamation-triangle"></i>
					<div>The abbreviation <strong id="cp-tp-abbr-conflict-abbr"></strong> is already used by <strong id="cp-tp-abbr-conflict-name"></strong> in the destination kingdom. Enter a new abbreviation for this park.</div>
				</div>
				<div id="cp-tp-abbr-field" style="display:none;margin-top:12px">
					<label style="display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:4px">New Abbreviation <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-tp-new-abbr" maxlength="3" autocomplete="off" style="width:80px;padding:6px 8px;border:1px solid #cbd5e0;border-radius:4px;font-size:13px;text-transform:uppercase" placeholder="e.g. ABC">
				</div>
				<p style="font-size:12px;color:#e53e3e;margin:16px 0 0">This will move all players in the park to the new kingdom.</p>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" id="cp-tp-cancel-btn">Cancel</button>
			<button class="adm-btn adm-btn-ghost" id="cp-tp-back-btn" style="display:none"><i class="fas fa-arrow-left"></i> Back</button>
			<button class="adm-btn adm-btn-primary" id="cp-tp-submit" disabled><i class="fas fa-arrow-right"></i> Review Transfer</button>
		</div>
	</div>
</div>

<!-- ---- Migrate Park Members (Merge Park) ---- -->
<div class="cp-overlay" id="cp-mergepark-overlay">
	<div class="cp-modal-box" style="width:560px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-object-group" style="margin-right:8px;color:#6b46c1"></i>Migrate Park Members</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-mergepark-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-mkp-feedback"></div>
			<div class="cp-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div>This moves <strong>all members</strong> of the source park to the destination park and deletes all officer roles and park-level permission grants for the source park. This cannot be undone.</div>
			</div>
			<div class="cp-field cp-field-ac">
				<label>Source Park (will be emptied) <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mkp-from-name" autocomplete="off" placeholder="Park to move members from…">
				<input type="hidden" id="cp-mkp-from-id">
				<div class="kn-ac-results" id="cp-mkp-from-results"></div>
			</div>
			<div class="cp-field cp-field-ac" style="margin-top:12px">
				<label>Destination Park (receives all members) <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mkp-to-name" autocomplete="off" placeholder="Park to move members to…">
				<input type="hidden" id="cp-mkp-to-id">
				<div class="kn-ac-results" id="cp-mkp-to-results"></div>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-mergepark-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="cp-mkp-submit" disabled><i class="fas fa-object-group"></i> Migrate Members</button>
		</div>
	</div>
</div>

<!-- ---- Merge Units ---- -->
<div class="cp-overlay" id="cp-mergeunit-overlay">
	<div class="cp-modal-box" style="width:560px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-layer-group" style="margin-right:8px;color:#6b46c1"></i>Merge Units</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-mergeunit-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-mu-feedback"></div>
			<div class="cp-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div>The source unit will be <strong>permanently deleted</strong> after all members are transferred to the destination unit.</div>
			</div>
			<div class="cp-field cp-field-ac">
				<label>Unit to Merge (will be deleted) <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mu-from-name" autocomplete="off" placeholder="Search units…">
				<input type="hidden" id="cp-mu-from-id">
				<div class="kn-ac-results" id="cp-mu-from-results"></div>
			</div>
			<div class="cp-field cp-field-ac" style="margin-top:12px">
				<label>Unit to Keep (receives all members) <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-mu-to-name" autocomplete="off" placeholder="Search units…">
				<input type="hidden" id="cp-mu-to-id">
				<div class="kn-ac-results" id="cp-mu-to-results"></div>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-mergeunit-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="cp-mu-submit" disabled><i class="fas fa-layer-group"></i> Merge Units</button>
		</div>
	</div>
</div>


<!-- ---- Create Kingdom ---- -->
<div class="cp-overlay" id="cp-createkingdom-overlay">
	<div class="cp-modal-box" style="width:560px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#2b6cb0"></i>Create Kingdom</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-createkingdom-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body" style="overflow:visible">
			<div class="cp-feedback" id="cp-crkn-feedback"></div>
			<div class="cp-field-row">
				<div class="cp-field">
					<label for="cp-crkn-name">Name <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-crkn-name" placeholder="e.g. Iron Mountains" maxlength="100" autocomplete="off">
				</div>
				<div class="cp-field" style="max-width:120px">
					<label for="cp-crkn-abbr">Abbreviation <span style="color:#e53e3e">*</span></label>
					<input type="text" id="cp-crkn-abbr" placeholder="e.g. IM" maxlength="3" autocomplete="off" style="text-transform:uppercase">
					<div id="cp-crkn-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
				</div>
			</div>
			<div class="cp-field" style="margin-top:12px">
				<label><input type="checkbox" id="cp-crkn-is-prinz"> &nbsp;This is a Principality</label>
			</div>
			<div class="cp-field cp-field-ac" id="cp-crkn-prinz-row" style="display:none;margin-top:12px">
				<label>Parent Kingdom <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-crkn-parent-name" autocomplete="off" placeholder="Search kingdoms…">
				<input type="hidden" id="cp-crkn-parent-id">
				<div class="kn-ac-results" id="cp-crkn-parent-results"></div>
			</div>
			<details style="margin-top:16px">
				<summary style="cursor:pointer;font-weight:600;color:#4a5568;font-size:13px">Advanced Settings</summary>
				<div style="margin-top:12px">
					<div class="cp-field-row">
						<div class="cp-field">
							<label for="cp-crkn-att-type">Attendance Period Type</label>
							<select id="cp-crkn-att-type">
								<option value="week" selected>Week</option>
								<option value="month">Month</option>
							</select>
						</div>
						<div class="cp-field">
							<label for="cp-crkn-att-period">Attendance Period</label>
							<input type="number" id="cp-crkn-att-period" value="26" min="1" max="52">
						</div>
					</div>
					<div class="cp-field-row" style="margin-top:8px">
						<div class="cp-field">
							<label for="cp-crkn-att-weekly-min">Weekly Min</label>
							<input type="number" id="cp-crkn-att-weekly-min" value="2" min="0">
						</div>
						<div class="cp-field">
							<label for="cp-crkn-att-daily-min">Daily Min</label>
							<input type="number" id="cp-crkn-att-daily-min" value="6" min="0">
						</div>
						<div class="cp-field">
							<label for="cp-crkn-att-credit-min">Credit Min</label>
							<input type="number" id="cp-crkn-att-credit-min" value="9" min="0">
						</div>
						<div class="cp-field">
							<label for="cp-crkn-monthly-credit-max">Monthly Credit Max</label>
							<input type="number" id="cp-crkn-monthly-credit-max" value="4" min="0">
						</div>
					</div>
					<div class="cp-field-row" style="margin-top:8px">
						<div class="cp-field">
							<label for="cp-crkn-dues-type">Dues Period Type</label>
							<select id="cp-crkn-dues-type">
								<option value="month" selected>Month</option>
								<option value="week">Week</option>
							</select>
						</div>
						<div class="cp-field">
							<label for="cp-crkn-dues-period">Dues Period</label>
							<input type="number" id="cp-crkn-dues-period" value="6" min="1">
						</div>
						<div class="cp-field">
							<label for="cp-crkn-dues-amount">Dues Amount</label>
							<input type="number" id="cp-crkn-dues-amount" value="6" min="0" step="0.01">
						</div>
						<div class="cp-field">
							<label for="cp-crkn-dues-take">Kingdom Take</label>
							<input type="number" id="cp-crkn-dues-take" value="1" min="0" step="0.01">
						</div>
					</div>
				</div>
			</details>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-createkingdom-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="cp-crkn-submit" disabled><i class="fas fa-plus"></i> Create Kingdom</button>
		</div>
	</div>
</div>

<!-- ---- Create Park ---- -->
<div class="cp-overlay" id="cp-createpark-overlay">
	<div class="cp-modal-box" style="width:480px">
		<div class="cp-modal-header">
			<h3 class="cp-modal-title"><i class="fas fa-tree" style="margin-right:8px;color:#276749"></i>Create Park</h3>
			<button class="cp-modal-close" onclick="cpCloseModal('cp-createpark-overlay')">&times;</button>
		</div>
		<div class="cp-modal-body">
			<div class="cp-feedback" id="cp-crpk-feedback"></div>
			<div class="cp-field cp-field-ac">
				<label>Kingdom <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-crpk-kingdom-name" autocomplete="off" placeholder="Search kingdoms…">
				<input type="hidden" id="cp-crpk-kingdom-id">
				<div class="kn-ac-results" id="cp-crpk-kingdom-results"></div>
			</div>
			<div class="cp-field" style="margin-top:12px">
				<label for="cp-crpk-name">Park Name <span style="color:#e53e3e">*</span></label>
				<input type="text" id="cp-crpk-name" placeholder="e.g. Eternal Darkness" maxlength="128" autocomplete="off">
			</div>
			<div class="cp-field" style="margin-top:12px">
				<label for="cp-crpk-abbr">Abbreviation <span style="color:#e53e3e">*</span> <span style="color:#a0aec0;font-size:11px">(up to 4 alphanumeric characters)</span></label>
				<input type="text" id="cp-crpk-abbr" placeholder="e.g. ED" maxlength="4" autocomplete="off">
				<div id="cp-crpk-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
			</div>
			<div class="cp-field" style="margin-top:12px">
				<label for="cp-crpk-type">Park Type <span style="color:#e53e3e">*</span></label>
				<select id="cp-crpk-type" disabled>
					<option value="">— select kingdom first —</option>
				</select>
			</div>
		</div>
		<div class="cp-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="cpCloseModal('cp-createpark-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="cp-crpk-submit" disabled><i class="fas fa-plus"></i> Create Park</button>
		</div>
	</div>
</div>

<script>
(function() {
	var UIR = '<?= $uir ?>';

	/* --------------------------------------------------
	   Modal helpers
	   -------------------------------------------------- */
	function cpOpenModal(id) {
		var el = document.getElementById(id);
		if (el) el.classList.add('cp-open');
	}
	function cpCloseModal(id) {
		var el = document.getElementById(id);
		if (!el) return;
		el.classList.remove('cp-open');
		// Reset feedback
		el.querySelectorAll('.cp-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
	}
	window.cpOpenModal   = cpOpenModal;
	window.cpCloseModal  = cpCloseModal;

	// Close on overlay backdrop click
	document.querySelectorAll('.cp-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) {
			if (e.target === ov) cpCloseModal(ov.id);
		});
	});
	// Close on Escape
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			document.querySelectorAll('.cp-overlay.cp-open').forEach(function(ov) {
				cpCloseModal(ov.id);
			});
		}
	});

	/* --------------------------------------------------
	   Feedback helper
	   -------------------------------------------------- */
	function cpShowFeedback(id, msg, ok) {
		var el = document.getElementById(id);
		if (!el) return;
		el.className = 'cp-feedback ' + (ok ? 'cp-feedback-ok' : 'cp-feedback-err');
		el.innerHTML = msg;
		el.style.display = 'block';
	}

	/* --------------------------------------------------
	   Autocomplete helper (kn-ac-results pattern)
	   -------------------------------------------------- */
	function cpAc(opts) {
		// opts: { inputId, hiddenId, resultsId, fetchFn, onSelect(id,name,extra), onClear, minLen }
		// item shape: { id, label, html, extra? }
		var input   = document.getElementById(opts.inputId);
		var hidden  = document.getElementById(opts.hiddenId);
		var results = document.getElementById(opts.resultsId);
		var timer   = null;
		var minLen  = opts.minLen || 2;

		function acClose() { results.classList.remove('kn-ac-open'); results.innerHTML = ''; }
		function acOpen(items) {
			if (!items.length) { acClose(); return; }
			results.innerHTML = items.map(function(item) {
				return '<div class="kn-ac-item" tabindex="-1" data-id="' + item.id
					+ '" data-name="' + encodeURIComponent(item.label)
					+ (item.extra !== undefined ? '" data-extra="' + encodeURIComponent(item.extra) : '')
					+ '">' + item.html + '</div>';
			}).join('');
			results.classList.add('kn-ac-open');
		}
		function selectItem(item) {
			input.value  = decodeURIComponent(item.dataset.name);
			hidden.value = item.dataset.id;
			acClose();
			if (opts.onSelect) opts.onSelect(item.dataset.id, input.value, item.dataset.extra ? decodeURIComponent(item.dataset.extra) : '');
		}
		input.addEventListener('input', function() {
			var term = this.value.trim();
			hidden.value = '';
			if (opts.onClear) opts.onClear();
			if (term.length < minLen) { acClose(); return; }
			clearTimeout(timer);
			timer = setTimeout(function() {
				opts.fetchFn(term, function(items) { acOpen(items); });
			}, 220);
		});
		results.addEventListener('click', function(e) {
			var item = e.target.closest('.kn-ac-item[data-id]');
			if (!item) return;
			selectItem(item);
		});
		document.addEventListener('click', function(e) {
			if (!e.target.closest('#' + opts.inputId + ', #' + opts.resultsId)) acClose();
		});
		// Keyboard nav
		input.addEventListener('keydown', function(e) {
			var items = results.querySelectorAll('.kn-ac-item');
			if (!items.length) return;
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && focused) {
				e.preventDefault(); selectItem(focused);
			} else if (e.key === 'Escape') {
				acClose();
			}
		});
	}

	/* --------------------------------------------------
	   SearchAjax helpers
	   -------------------------------------------------- */
	function cpEsc(s) {
		return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}
	function cpSearchParks(q, cb) {
		var url = UIR + 'SearchAjax/universal&focus=park&q=' + encodeURIComponent(q);
		fetch(url).then(function(r){return r.json();}).then(function(d) {
			cb((d.parks || []).map(function(p) {
				return {
					id:    p.id,
					label: p.name,
					extra: p.kingdom || '',
					html:  cpEsc(p.name)
					     + (p.kingdom ? ' <span style="color:#a0aec0;font-size:11px">[' + cpEsc(p.kingdom) + ']</span>' : '')
				};
			}));
		}).catch(function(){cb([]);});
	}
	function cpSearchKingdoms(q, cb) {
		var url = UIR + 'SearchAjax/universal&focus=kingdom&q=' + encodeURIComponent(q);
		fetch(url).then(function(r){return r.json();}).then(function(d) {
			cb((d.kingdoms || []).map(function(k) {
				return { id: k.id, label: k.name, html: cpEsc(k.name) + ' <span style="color:#a0aec0;font-size:11px">(' + cpEsc(k.abbr) + ')</span>' };
			}));
		}).catch(function(){cb([]);});
	}
	function cpSearchUnits(q, cb) {
		var url = UIR + 'SearchAjax/universal&focus=unit&q=' + encodeURIComponent(q);
		fetch(url).then(function(r){return r.json();}).then(function(d) {
			cb((d.units || []).map(function(u) {
				return { id: u.id, label: u.name, html: cpEsc(u.name) + ' <span style="color:#a0aec0;font-size:11px">(' + cpEsc(u.unitType || '') + ')</span>' };
			}));
		}).catch(function(){cb([]);});
	}
	function cpSearchPlayersGlobal(q, cb, includeInactive) {
		var url = UIR + 'SearchAjax/universal&focus=player&q=' + encodeURIComponent(q) + (includeInactive ? '&inactive=1' : '');
		fetch(url).then(function(r){return r.json();}).then(function(d) {
			cb((d.players || []).map(function(p) {
				return {
					id: p.id, label: p.name,
					html: cpEsc(p.name) + ' <span style="color:#a0aec0;font-size:11px">(' + cpEsc(p.abbr) + ' · ' + cpEsc(p.park) + ')</span>',
				};
			}));
		}).catch(function(){cb([]);});
	}

	/* --------------------------------------------------
	   POST helper
	   -------------------------------------------------- */
	function cpPost(url, data, btn, feedbackId, onSuccess) {
		if (btn) btn.disabled = true;
		var fd = new FormData();
		Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
		fetch(url, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r.status === 0) {
					if (btn) btn.disabled = false;
					onSuccess(r);
				} else {
					if (btn) btn.disabled = false;
					cpShowFeedback(feedbackId, r.error || 'An error occurred.', false);
				}
			})
			.catch(function() {
				if (btn) btn.disabled = false;
				cpShowFeedback(feedbackId, 'Request failed. Please try again.', false);
			});
	}

	/* ==================================================
	   CREATE PLAYER
	   ================================================== */
	cpAc({ inputId:'cp-cp-park-name', hiddenId:'cp-cp-park-id', resultsId:'cp-cp-park-results',
		fetchFn: cpSearchParks,
		onClear: function() { document.getElementById('cp-cp-park-id').value = ''; } });
	document.getElementById('cp-cp-submit').addEventListener('click', function() {
		var parkId   = document.getElementById('cp-cp-park-id').value;
		var persona  = document.getElementById('cp-cp-persona').value.trim();
		var username = document.getElementById('cp-cp-username').value.trim();
		var password = document.getElementById('cp-cp-password').value;
		if (!parkId)             { cpShowFeedback('cp-cp-feedback', 'Please select a home park.', false); return; }
		if (!persona)            { cpShowFeedback('cp-cp-feedback', 'Persona is required.', false); return; }
		if (!username)           { cpShowFeedback('cp-cp-feedback', 'Username is required.', false); return; }
		if (username.length < 4) { cpShowFeedback('cp-cp-feedback', 'Username must be at least 4 characters.', false); return; }
		if (!password)           { cpShowFeedback('cp-cp-feedback', 'Password is required.', false); return; }
		var restricted = document.querySelector('input[name="cp-cp-restricted"]:checked');
		var waivered   = document.querySelector('input[name="cp-cp-waivered"]:checked');
		var btn = this;
		cpPost(UIR + 'PlayerAjax/park/' + parkId + '/create', {
			Persona:    persona,
			GivenName:  document.getElementById('cp-cp-given').value.trim(),
			Surname:    document.getElementById('cp-cp-surname').value.trim(),
			Email:      document.getElementById('cp-cp-email').value.trim(),
			UserName:   username,
			Password:   password,
			Restricted: restricted ? restricted.value : '0',
			Waivered:   waivered   ? waivered.value   : '0',
		}, btn, 'cp-cp-feedback', function(r) {
			window.location.href = UIR + 'Player/profile/' + r.mundaneId;
		});
	});

	/* ==================================================
	   MOVE PLAYER
	   ================================================== */
	function cpMpCheck() {
		var pid = document.getElementById('cp-mp-player-id').value;
		var pkid = document.getElementById('cp-mp-park-id').value;
		document.getElementById('cp-mp-submit').disabled = !(pid && pkid);
	}
	cpAc({ inputId:'cp-mp-player-name', hiddenId:'cp-mp-player-id', resultsId:'cp-mp-player-results',
		fetchFn: cpSearchPlayersGlobal, onSelect: cpMpCheck, onClear: cpMpCheck });
	cpAc({ inputId:'cp-mp-park-name', hiddenId:'cp-mp-park-id', resultsId:'cp-mp-park-results',
		fetchFn: cpSearchParks, onSelect: cpMpCheck, onClear: cpMpCheck });
	document.getElementById('cp-mp-submit').addEventListener('click', function() {
		var playerId = document.getElementById('cp-mp-player-id').value;
		var parkId   = document.getElementById('cp-mp-park-id').value;
		if (!playerId || !parkId) return;
		var btn = this;
		cpPost(UIR + 'PlayerAjax/player/' + playerId + '/moveplayer', { ParkId: parkId },
			btn, 'cp-mp-feedback', function() {
				cpShowFeedback('cp-mp-feedback',
					'Player moved successfully. ' +
					'<a href="' + UIR + 'Park/profile/' + parkId + '">View new home park</a> · ' +
					'<a href="' + UIR + 'Player/profile/' + playerId + '">View player</a>', true);
				// reset
				['cp-mp-player-name','cp-mp-park-name'].forEach(function(id){document.getElementById(id).value='';});
				['cp-mp-player-id','cp-mp-park-id'].forEach(function(id){document.getElementById(id).value='';});
				btn.disabled = true;
			});
	});

	/* ==================================================
	   MERGE PLAYERS
	   ================================================== */
	function cpMgpCheck() {
		var keep   = document.getElementById('cp-mgp-keep-id').value;
		var remove = document.getElementById('cp-mgp-remove-id').value;
		document.getElementById('cp-mgp-submit').disabled = !(keep && remove);
	}
	cpAc({ inputId:'cp-mgp-keep-name',   hiddenId:'cp-mgp-keep-id',   resultsId:'cp-mgp-keep-results',   fetchFn:cpSearchPlayersGlobal, onSelect:cpMgpCheck, onClear:cpMgpCheck });
	cpAc({ inputId:'cp-mgp-remove-name', hiddenId:'cp-mgp-remove-id', resultsId:'cp-mgp-remove-results', fetchFn:cpSearchPlayersGlobal, onSelect:cpMgpCheck, onClear:cpMgpCheck });
	document.getElementById('cp-mgp-submit').addEventListener('click', function() {
		var keepId   = document.getElementById('cp-mgp-keep-id').value;
		var removeId = document.getElementById('cp-mgp-remove-id').value;
		if (!keepId || !removeId) return;
		if (keepId === removeId) { cpShowFeedback('cp-mgp-feedback', 'Cannot merge a player with themselves.', false); return; }
		var btn = this;
		cpPost(UIR + 'PlayerAjax/merge', { ToMundaneId: keepId, FromMundaneId: removeId },
			btn, 'cp-mgp-feedback', function() {
				window.location.href = UIR + 'Player/profile/' + keepId;
			});
	});

	/* ==================================================
	   TRANSFER PARK
	   ================================================== */
	(function() {
		var confirming = false;
		var tpAbbrData = null;

		function tpReset(clearFields) {
			confirming = false;
			tpAbbrData = null;
			document.getElementById('cp-tp-search-panel').style.display  = '';
			document.getElementById('cp-tp-confirm-panel').style.display = 'none';
			document.getElementById('cp-tp-back-btn').style.display      = 'none';
			document.getElementById('cp-tp-cancel-btn').style.display    = '';
			var btn = document.getElementById('cp-tp-submit');
			btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
			if (clearFields) {
				['cp-tp-park-name','cp-tp-kingdom-name'].forEach(function(id) { document.getElementById(id).value = ''; });
				['cp-tp-park-id','cp-tp-kingdom-id','cp-tp-source-kingdom'].forEach(function(id) { document.getElementById(id).value = ''; });
				btn.disabled = true;
			} else {
				tpCheck();
			}
		}

		function tpShowConfirm(abbr, taken, conflictName) {
			var parkName    = document.getElementById('cp-tp-park-name').value;
			var fromKingdom = document.getElementById('cp-tp-source-kingdom').value || '(unknown)';
			var toKingdom   = document.getElementById('cp-tp-kingdom-name').value;
			document.getElementById('cp-tp-confirm-park').textContent = parkName;
			document.getElementById('cp-tp-confirm-from').textContent = fromKingdom;
			document.getElementById('cp-tp-confirm-to').textContent   = toKingdom;
			document.getElementById('cp-tp-confirm-abbr').textContent = abbr;
			var warnEl  = document.getElementById('cp-tp-abbr-warning');
			var fieldEl = document.getElementById('cp-tp-abbr-field');
			if (taken) {
				document.getElementById('cp-tp-abbr-conflict-abbr').textContent = abbr;
				document.getElementById('cp-tp-abbr-conflict-name').textContent = conflictName;
				document.getElementById('cp-tp-new-abbr').value = abbr;
				warnEl.style.display  = '';
				fieldEl.style.display = '';
			} else {
				warnEl.style.display  = 'none';
				fieldEl.style.display = 'none';
			}
			document.getElementById('cp-tp-search-panel').style.display  = 'none';
			document.getElementById('cp-tp-confirm-panel').style.display = '';
			document.getElementById('cp-tp-back-btn').style.display      = '';
			document.getElementById('cp-tp-cancel-btn').style.display    = 'none';
			var btn = document.getElementById('cp-tp-submit');
			btn.innerHTML = '<i class="fas fa-map-signs"></i> Confirm Transfer';
			btn.disabled  = false;
		}

		function tpCheck() {
			var pk = document.getElementById('cp-tp-park-id').value;
			var kd = document.getElementById('cp-tp-kingdom-id').value;
			document.getElementById('cp-tp-submit').disabled = !(pk && kd);
		}

		cpAc({
			inputId:   'cp-tp-park-name',
			hiddenId:  'cp-tp-park-id',
			resultsId: 'cp-tp-park-results',
			fetchFn:   cpSearchParks,
			onSelect:  function(id, name, extra) {
				document.getElementById('cp-tp-source-kingdom').value = extra || '';
				tpCheck();
			},
			onClear: tpCheck
		});
		cpAc({ inputId:'cp-tp-kingdom-name', hiddenId:'cp-tp-kingdom-id', resultsId:'cp-tp-kingdom-results', fetchFn:cpSearchKingdoms, onSelect:tpCheck, onClear:tpCheck });

		document.getElementById('cp-tp-close-btn').addEventListener('click',  function() { cpCloseModal('cp-transferpark-overlay'); });
		document.getElementById('cp-tp-cancel-btn').addEventListener('click', function() { cpCloseModal('cp-transferpark-overlay'); });
		document.getElementById('cp-tp-back-btn').addEventListener('click',   function() { tpReset(); });

		window.cpOpenTransferPark = function() { tpReset(true); cpOpenModal('cp-transferpark-overlay'); setTimeout(function() { document.getElementById('cp-tp-park-name').focus(); }, 50); };

		document.getElementById('cp-tp-submit').addEventListener('click', function() {
			var parkId    = document.getElementById('cp-tp-park-id').value;
			var kingdomId = document.getElementById('cp-tp-kingdom-id').value;
			if (!parkId || !kingdomId) return;
			if (!confirming) {
				var btn = this;
				btn.disabled  = true;
				btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Checking…';
				var fd = new FormData();
				fd.append('ParkId', parkId);
				fd.append('KingdomId', kingdomId);
				fetch(UIR + 'Admin/ajax/checkparkabbr', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(d) {
						if (d.status !== 0) {
							btn.disabled  = false;
							btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
							cpShowFeedback('cp-tp-feedback', d.error || 'Error checking abbreviation.', false);
							return;
						}
						tpAbbrData = { abbr: d.abbr, taken: d.taken, conflictName: d.conflictName };
						confirming = true;
						tpShowConfirm(d.abbr, d.taken, d.conflictName);
					})
					.catch(function() {
						btn.disabled  = false;
						btn.innerHTML = '<i class="fas fa-arrow-right"></i> Review Transfer';
						cpShowFeedback('cp-tp-feedback', 'Error checking abbreviation. Please try again.', false);
					});
				return;
			}
			if (tpAbbrData && tpAbbrData.taken) {
				var newAbbr = document.getElementById('cp-tp-new-abbr').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
				if (newAbbr.length < 2 || newAbbr.length > 3) {
					cpShowFeedback('cp-tp-feedback', 'Please enter a 2–3 character abbreviation.', false);
					return;
				}
			}
			var postData = { ParkId: parkId, KingdomId: kingdomId };
			if (tpAbbrData && tpAbbrData.taken) {
				postData.Abbreviation = document.getElementById('cp-tp-new-abbr').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
			}
			var btn = this;
			cpPost(UIR + 'Admin/ajax/transferpark', postData,
				btn, 'cp-tp-feedback', function() {
					cpShowFeedback('cp-tp-feedback',
						'Park transferred. <a href="' + UIR + 'Park/profile/' + parkId + '">View park</a> · ' +
						'<a href="' + UIR + 'Kingdom/profile/' + kingdomId + '">View kingdom</a>', true);
					tpReset(true);
				});
		});
	})();

	/* ==================================================
	   MIGRATE PARK MEMBERS (merge park)
	   ================================================== */
	function cpMkpCheck() {
		var from = document.getElementById('cp-mkp-from-id').value;
		var to   = document.getElementById('cp-mkp-to-id').value;
		document.getElementById('cp-mkp-submit').disabled = !(from && to);
	}
	cpAc({ inputId:'cp-mkp-from-name', hiddenId:'cp-mkp-from-id', resultsId:'cp-mkp-from-results', fetchFn:cpSearchParks, onSelect:cpMkpCheck, onClear:cpMkpCheck });
	cpAc({ inputId:'cp-mkp-to-name',   hiddenId:'cp-mkp-to-id',   resultsId:'cp-mkp-to-results',   fetchFn:cpSearchParks, onSelect:cpMkpCheck, onClear:cpMkpCheck });
	document.getElementById('cp-mkp-submit').addEventListener('click', function() {
		var fromId = document.getElementById('cp-mkp-from-id').value;
		var toId   = document.getElementById('cp-mkp-to-id').value;
		if (!fromId || !toId) return;
		if (fromId === toId) { cpShowFeedback('cp-mkp-feedback', 'Cannot merge a park into itself.', false); return; }
		var btn = this;
		cpPost(UIR + 'Admin/ajax/mergepark', { FromParkId: fromId, ToParkId: toId },
			btn, 'cp-mkp-feedback', function() {
				cpShowFeedback('cp-mkp-feedback',
					'Members migrated successfully. <a href="' + UIR + 'Park/profile/' + toId + '">View destination park</a>', true);
				['cp-mkp-from-name','cp-mkp-to-name'].forEach(function(id){document.getElementById(id).value='';});
				['cp-mkp-from-id','cp-mkp-to-id'].forEach(function(id){document.getElementById(id).value='';});
				btn.disabled = true;
			});
	});

	/* ==================================================
	   MERGE UNITS
	   ================================================== */
	function cpMuCheck() {
		var from = document.getElementById('cp-mu-from-id').value;
		var to   = document.getElementById('cp-mu-to-id').value;
		document.getElementById('cp-mu-submit').disabled = !(from && to);
	}
	cpAc({ inputId:'cp-mu-from-name', hiddenId:'cp-mu-from-id', resultsId:'cp-mu-from-results', fetchFn:cpSearchUnits, onSelect:cpMuCheck, onClear:cpMuCheck });
	cpAc({ inputId:'cp-mu-to-name',   hiddenId:'cp-mu-to-id',   resultsId:'cp-mu-to-results',   fetchFn:cpSearchUnits, onSelect:cpMuCheck, onClear:cpMuCheck });
	document.getElementById('cp-mu-submit').addEventListener('click', function() {
		var fromId = document.getElementById('cp-mu-from-id').value;
		var toId   = document.getElementById('cp-mu-to-id').value;
		if (!fromId || !toId) return;
		if (fromId === toId) { cpShowFeedback('cp-mu-feedback', 'Cannot merge a unit into itself.', false); return; }
		var btn = this;
		cpPost(UIR + 'Admin/ajax/mergeunit', { FromUnitId: fromId, ToUnitId: toId },
			btn, 'cp-mu-feedback', function() {
				cpShowFeedback('cp-mu-feedback', 'Units merged successfully.', true);
				['cp-mu-from-name','cp-mu-to-name'].forEach(function(id){document.getElementById(id).value='';});
				['cp-mu-from-id','cp-mu-to-id'].forEach(function(id){document.getElementById(id).value='';});
				btn.disabled = true;
			});
	});

	/* --------------------------------------------------
	   Kingdom overview table — sort + filter
	   -------------------------------------------------- */
	var sortCol = 0, sortAsc = true;
	function cpSort(col) {
		if (sortCol === col) { sortAsc = !sortAsc; } else { sortCol = col; sortAsc = col === 0; }
		document.querySelectorAll('.cp-kd-table thead th').forEach(function(th, i) {
			th.classList.remove('cp-sort-asc', 'cp-sort-desc');
			if (i === sortCol) th.classList.add(sortAsc ? 'cp-sort-asc' : 'cp-sort-desc');
		});
		var tbody = document.getElementById('cp-kd-tbody');
		var rows  = Array.from(tbody.querySelectorAll('tr'));
		rows.sort(function(a, b) {
			var av = a.children[sortCol].innerText.trim().replace(/,/g,'');
			var bv = b.children[sortCol].innerText.trim().replace(/,/g,'');
			var ai = parseFloat(av), bi = parseFloat(bv);
			var cmp = isNaN(ai) || isNaN(bi) ? av.localeCompare(bv) : ai - bi;
			return sortAsc ? cmp : -cmp;
		});
		rows.forEach(function(r) { tbody.appendChild(r); });
	}
	function cpFilterTable(q) {
		q = q.toLowerCase().trim();
		document.querySelectorAll('#cp-kd-tbody tr').forEach(function(r) {
			r.style.display = (!q || r.dataset.name.indexOf(q) !== -1) ? '' : 'none';
		});
	}

	/* ==================================================
	   CREATE PARK
	   ================================================== */
	(function() {
		var kingdomId = '';

		cpAc({
			inputId: 'cp-crpk-kingdom-name',
			hiddenId: 'cp-crpk-kingdom-id',
			resultsId: 'cp-crpk-kingdom-results',
			fetchFn: cpSearchKingdoms,
			onSelect: function(id) {
				kingdomId = id;
				document.getElementById('cp-crpk-abbr').dispatchEvent(new Event('input'));
				var sel = document.getElementById('cp-crpk-type');
				sel.innerHTML = '<option value="">Loading…</option>';
				sel.disabled = true;
				fetch(UIR + 'KingdomAjax/kingdom/' + id + '/parktitles')
					.then(function(r) { return r.json(); })
					.then(function(d) {
						if (d.status === 0 && d.titles && d.titles.length) {
							sel.innerHTML = '<option value="">— select type —</option>' +
								d.titles.map(function(pt) {
									return '<option value="' + pt.ParkTitleId + '">' + pt.Title.replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</option>';
								}).join('');
							sel.disabled = false;
						} else {
							sel.innerHTML = '<option value="">No titles found</option>';
						}
						cpCrpkCheckReady();
					})
					.catch(function() {
						sel.innerHTML = '<option value="">Failed to load</option>';
					});
				cpCrpkCheckReady();
			},
			onClear: function() {
				kingdomId = '';
				var sel = document.getElementById('cp-crpk-type');
				sel.innerHTML = '<option value="">— select kingdom first —</option>';
				sel.disabled = true;
				var warn = document.getElementById('cp-crpk-abbr-warn');
				if (warn) warn.style.display = 'none';
				cpCrpkCheckReady();
			}
		});

		function cpCrpkCheckReady() {
			var ok = kingdomId &&
				document.getElementById('cp-crpk-name').value.trim() &&
				document.getElementById('cp-crpk-abbr').value.trim() &&
				document.getElementById('cp-crpk-type').value;
			document.getElementById('cp-crpk-submit').disabled = !ok;
		}

		['cp-crpk-name', 'cp-crpk-abbr', 'cp-crpk-type'].forEach(function(id) {
			document.getElementById(id).addEventListener('input', cpCrpkCheckReady);
		});

		var crpkAbbrTimer = null;
		document.getElementById('cp-crpk-abbr').addEventListener('input', function() {
			var warn = document.getElementById('cp-crpk-abbr-warn');
			clearTimeout(crpkAbbrTimer);
			var abbr = this.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
			if (!abbr || !kingdomId) { if (warn) warn.style.display = 'none'; return; }
			crpkAbbrTimer = setTimeout(function() {
				var fd = new FormData();
				fd.append('Abbreviation', abbr);
				fetch(UIR + 'ParkAjax/kingdom/' + kingdomId + '/checkabbr', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (warn) {
							warn.style.display = r.taken ? '' : 'none';
							if (r.taken) warn.textContent = '\u26a0\ufe0f "' + abbr + '" is already used by another park in this kingdom.';
						}
					});
			}, 400);
		});

		document.getElementById('cp-crpk-submit').addEventListener('click', function() {
			var kId    = kingdomId;
			var name   = document.getElementById('cp-crpk-name').value.trim();
			var abbr   = document.getElementById('cp-crpk-abbr').value.trim().replace(/[^A-Za-z0-9]/g, '');
			var typeId = document.getElementById('cp-crpk-type').value;
			if (!kId)   { cpShowFeedback('cp-crpk-feedback', 'Please select a kingdom.', false); return; }
			if (!name)  { cpShowFeedback('cp-crpk-feedback', 'Park must have a name.', false); return; }
			if (!abbr)  { cpShowFeedback('cp-crpk-feedback', 'Park must have an abbreviation.', false); return; }
			if (!typeId){ cpShowFeedback('cp-crpk-feedback', 'Please select a park type.', false); return; }
			var btn = this;
			cpPost(UIR + 'ParkAjax/kingdom/' + kId + '/create',
				{ Name: name, Abbreviation: abbr, ParkTitleId: typeId },
				btn, 'cp-crpk-feedback', function(r) {
					window.location.href = UIR + 'Park/profile/' + r.parkId;
				});
		});
	})();

	/* ==================================================
	   CREATE KINGDOM
	   ================================================== */
	(function() {
		var parentId = '';
		document.getElementById('cp-crkn-is-prinz').addEventListener('change', function() {
			var row = document.getElementById('cp-crkn-prinz-row');
			row.style.display = this.checked ? '' : 'none';
			if (!this.checked) {
				document.getElementById('cp-crkn-parent-name').value = '';
				document.getElementById('cp-crkn-parent-id').value = '';
				parentId = '';
			}
			cpCrknCheckReady();
		});
		function cpCrknCheckReady() {
			var name    = document.getElementById('cp-crkn-name').value.trim();
			var abbr    = document.getElementById('cp-crkn-abbr').value.trim();
			var isPrinz = document.getElementById('cp-crkn-is-prinz').checked;
			var ok = name && abbr && (!isPrinz || parentId);
			document.getElementById('cp-crkn-submit').disabled = !ok;
		}

		cpAc({
			inputId: 'cp-crkn-parent-name',
			hiddenId: 'cp-crkn-parent-id',
			resultsId: 'cp-crkn-parent-results',
			fetchFn: cpSearchKingdoms,
			onSelect: function(id) { parentId = id; cpCrknCheckReady(); },
			onClear:  function()   { parentId = ''; cpCrknCheckReady(); }
		});

		document.getElementById('cp-crkn-name').addEventListener('input', cpCrknCheckReady);
		document.getElementById('cp-crkn-abbr').addEventListener('input', cpCrknCheckReady);
		document.getElementById('cp-crkn-is-prinz').addEventListener('change', cpCrknCheckReady);

		var abbrTimer = null;
		document.getElementById('cp-crkn-abbr').addEventListener('input', function() {
			var warn = document.getElementById('cp-crkn-abbr-warn');
			clearTimeout(abbrTimer);
			var abbr = this.value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
			if (!abbr) { if (warn) warn.style.display = 'none'; return; }
			abbrTimer = setTimeout(function() {
				cpPost(UIR + 'Admin/ajax/checkabbr', { Abbreviation: abbr }, null, null, function(r) {
					if (!warn) return;
					if (r.taken) {
						warn.textContent = '\u26a0\ufe0f "' + abbr + '" is already used by ' + r.name + '.';
						warn.style.display = '';
					} else {
						warn.style.display = 'none';
					}
				});
			}, 400);
		});

		document.getElementById('cp-crkn-submit').addEventListener('click', function() {
			var name   = document.getElementById('cp-crkn-name').value.trim();
			var abbr   = document.getElementById('cp-crkn-abbr').value.trim().toUpperCase().replace(/[^A-Z0-9]/g, '');
			var isPrinz = document.getElementById('cp-crkn-is-prinz').checked;
			if (!name) { cpShowFeedback('cp-crkn-feedback', 'Kingdom must have a name.', false); return; }
			if (!abbr) { cpShowFeedback('cp-crkn-feedback', 'Kingdom must have an abbreviation.', false); return; }
			if (isPrinz && !parentId) { cpShowFeedback('cp-crkn-feedback', 'Please select a parent kingdom.', false); return; }
			var btn = this;
			cpPost(UIR + 'Admin/ajax/createkingdom', {
				Name:                   name,
				Abbreviation:           abbr,
				ParentKingdomId:        isPrinz ? parentId : 0,
				AttendancePeriodType:   document.getElementById('cp-crkn-att-type').value,
				AttendancePeriod:       document.getElementById('cp-crkn-att-period').value,
				AttendanceWeeklyMinimum:  document.getElementById('cp-crkn-att-weekly-min').value,
				AttendanceDailyMinimum:   document.getElementById('cp-crkn-att-daily-min').value,
				AttendanceCreditMinimum:  document.getElementById('cp-crkn-att-credit-min').value,
				MonthlyCreditMaximum:     document.getElementById('cp-crkn-monthly-credit-max').value,
				DuesPeriodType:   document.getElementById('cp-crkn-dues-type').value,
				DuesPeriod:       document.getElementById('cp-crkn-dues-period').value,
				DuesAmount:       document.getElementById('cp-crkn-dues-amount').value,
				KingdomDuesTake:  document.getElementById('cp-crkn-dues-take').value,
			}, btn, 'cp-crkn-feedback', function(r) {
				window.location.href = UIR + 'Kingdom/profile/' + r.kingdomId;
			});
		});
	})();

	window.cpSort        = cpSort;
	window.cpFilterTable = cpFilterTable;
})();
</script>
