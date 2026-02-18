<?php
	$passwordExpired = strtotime($Player['PasswordExpires']) - time() <= 0;
	$passwordExpiring = $passwordExpired ? 'Expired' : date('Y-m-j', strtotime($Player['PasswordExpires']));

	$can_delete_recommendation = false;
	if($this->__session->user_id) {
		if (isset($this->__session->park_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $this->__session->park_id, AUTH_EDIT)) {
				$can_delete_recommendation = true;
			}
		} else if (isset($this->__session->kingdom_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_KINGDOM, $this->__session->kingdom_id, AUTH_EDIT)) {
				$can_delete_recommendation = true;
			}
		}
	}

	$isSuspended = ($Player['Suspended'] == 1);
	$isActive = ($Player['Active'] == 1 && !$isSuspended);
	$pronounDisplay = (!empty($Player['PronounCustomText'])) ? $Player['PronounCustomText'] : $Player['PronounText'];
	$heraldryUrl = $Player['HasHeraldry'] > 0 ? $Player['Heraldry'] : HTTP_PLAYER_HERALDRY . '000000.jpg';
	$imageUrl = $Player['HasImage'] > 0 ? $Player['Image'] : HTTP_PLAYER_HERALDRY . '000000.jpg';
?>

<style type="text/css">
/* ========================================
   CRM-Style Player Profile
   All classes prefixed with pn- to avoid collisions
   ======================================== */

/* Hero Header */
.pn-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 20px;
	min-height: 160px;
	background-color: <?= $isSuspended ? '#9b2c2c' : '#2c5282' ?>;
}
.pn-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.12;
	filter: blur(6px);
}
.pn-hero-content {
	position: relative;
	display: flex;
	align-items: center;
	padding: 24px 30px;
	gap: 24px;
	z-index: 1;
}
.pn-avatar {
	width: 110px;
	height: 110px;
	border-radius: 50%;
	overflow: hidden;
	border: 4px solid rgba(255,255,255,0.85);
	flex-shrink: 0;
	background-color: #cbd5e0;
}
.pn-avatar img {
	width: 100%;
	height: 100%;
	object-fit: cover;
}
.pn-hero-info {
	flex: 1;
	min-width: 0;
}
.pn-persona {
	color: #fff;
	font-size: 26px;
	margin: 0 0 2px 0;
	font-weight: 700;
	line-height: 1.2;
}
.pn-real-name {
	color: rgba(255,255,255,0.7);
	font-size: 14px;
	margin-bottom: 4px;
}
.pn-pronouns {
	color: rgba(255,255,255,0.6);
	font-size: 13px;
	font-style: italic;
	margin-bottom: 6px;
}
.pn-breadcrumb {
	color: rgba(255,255,255,0.75);
	font-size: 13px;
	margin-bottom: 10px;
}
.pn-breadcrumb a {
	color: rgba(255,255,255,0.9);
	text-decoration: underline;
}
.pn-breadcrumb .pn-sep {
	margin: 0 5px;
	opacity: 0.5;
}
.pn-badges {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
}
.pn-badge {
	display: inline-block;
	padding: 3px 10px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 600;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	line-height: 1.4;
}
.pn-badge-green  { background: #c6f6d5; color: #276749; }
.pn-badge-red    { background: #fed7d7; color: #9b2c2c; }
.pn-badge-orange { background: #feebc8; color: #9c4221; }
.pn-badge-blue   { background: #bee3f8; color: #2a4365; }
.pn-badge-gray   { background: #e2e8f0; color: #4a5568; }
.pn-badge-purple { background: #e9d8fd; color: #553c9a; }

.pn-suspended-detail {
	color: rgba(255,255,255,0.8);
	font-size: 12px;
	margin-top: 6px;
	line-height: 1.5;
}

.pn-hero-actions {
	flex-shrink: 0;
	display: flex;
	flex-direction: column;
	gap: 8px;
	align-items: flex-end;
}
.pn-btn {
	display: inline-block;
	padding: 8px 16px;
	border-radius: 6px;
	font-size: 13px;
	font-weight: 600;
	text-decoration: none;
	cursor: pointer;
	border: none;
	white-space: nowrap;
	transition: opacity 0.15s;
}
.pn-btn:hover { opacity: 0.85; }
.pn-btn-white {
	background: #fff;
	color: #2c5282;
}
.pn-btn-outline {
	background: rgba(255,255,255,0.15);
	color: #fff;
	border: 1px solid rgba(255,255,255,0.4);
}

/* Stats Row */
.pn-stats-row {
	display: flex;
	gap: 14px;
	margin-bottom: 20px;
}
.pn-stat-card {
	flex: 1;
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 14px 16px;
	text-align: center;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.pn-stat-icon {
	font-size: 16px;
	color: #a0aec0;
	margin-bottom: 4px;
}
.pn-stat-number {
	font-size: 26px;
	font-weight: 700;
	color: #2c5282;
	line-height: 1.2;
}
.pn-stat-label {
	font-size: 11px;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-top: 2px;
}

/* Two-Column Layout */
.pn-layout {
	display: flex;
	gap: 20px;
	align-items: flex-start;
}
.pn-sidebar {
	width: 300px;
	flex-shrink: 0;
}
.pn-main {
	flex: 1;
	min-width: 0;
}

/* Cards */
.pn-card {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	padding: 16px 18px;
	margin-bottom: 14px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.pn-card h4 {
	margin: 0 0 10px 0;
	font-size: 12px;
	font-weight: 700;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.6px;
	padding-bottom: 8px;
	border-bottom: 1px solid #edf2f7;
}
.pn-detail-row {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	padding: 5px 0;
	border-bottom: 1px dotted #f0f0f0;
	font-size: 13px;
}
.pn-detail-row:last-child { border-bottom: none; }
.pn-detail-label {
	color: #718096;
	flex-shrink: 0;
	margin-right: 12px;
}
.pn-detail-value {
	color: #2d3748;
	font-weight: 500;
	text-align: right;
	word-break: break-word;
}

/* Sidebar mini-table */
.pn-mini-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 12px;
}
.pn-mini-table th {
	text-align: left;
	color: #a0aec0;
	font-weight: 600;
	font-size: 10px;
	text-transform: uppercase;
	letter-spacing: 0.4px;
	padding: 4px 6px;
	border-bottom: 1px solid #edf2f7;
}
.pn-mini-table td {
	padding: 5px 6px;
	color: #4a5568;
	border-bottom: 1px solid #f7fafc;
}
.pn-mini-table tr:last-child td { border-bottom: none; }

.pn-dues-life {
	background: #f0fff4;
	border: 1px dashed #68d391;
	border-radius: 4px;
	padding: 1px 6px;
	font-size: 11px;
	color: #276749;
	font-weight: 600;
}

.pn-unit-link {
	color: #3182ce;
	text-decoration: none;
	font-weight: 500;
}
.pn-unit-link:hover { text-decoration: underline; }
.pn-unit-type {
	color: #a0aec0;
	font-size: 11px;
	margin-left: 4px;
}

/* Tabs */
.pn-tabs {
	background: #fff;
	border: 1px solid #e2e8f0;
	border-radius: 8px;
	box-shadow: 0 1px 3px rgba(0,0,0,0.05);
	overflow: hidden;
}
.pn-tab-nav {
	list-style: none;
	margin: 0;
	padding: 0;
	display: flex;
	border-bottom: 2px solid #e2e8f0;
	background: #f7fafc;
	flex-wrap: nowrap;
	overflow-x: auto;
}
.pn-tab-nav li {
	padding: 12px 18px;
	cursor: pointer;
	font-size: 13px;
	font-weight: 600;
	color: #718096;
	border-bottom: 2px solid transparent;
	margin-bottom: -2px;
	transition: color 0.15s, border-color 0.15s;
	white-space: nowrap;
	flex-shrink: 0;
}
.pn-tab-nav li:hover {
	color: #2c5282;
	background: #edf2f7;
}
.pn-tab-nav li.pn-tab-active {
	color: #2c5282;
	border-bottom-color: #2c5282;
	background: #fff;
}
.pn-tab-count {
	color: #a0aec0;
	font-weight: 400;
	font-size: 12px;
	margin-left: 3px;
}
.pn-tab-panel {
	padding: 16px 18px;
}

/* Tab Tables */
.pn-table {
	width: 100%;
	border-collapse: collapse;
	font-size: 13px;
}
.pn-table thead th {
	text-align: left;
	background-color: #f7fafc;
	color: #4a5568;
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.3px;
	padding: 8px 10px;
	border-bottom: 1px solid #e2e8f0;
	cursor: pointer;
	user-select: none;
	-webkit-user-select: none;
	position: relative;
	padding-right: 20px;
	white-space: nowrap;
}
.pn-table thead th:hover {
	background-color: #edf2f7;
}
.pn-table thead th.sort-asc::after {
	content: ' \25B2';
	position: absolute;
	right: 5px;
	color: #2c5282;
	font-size: 0.75em;
}
.pn-table thead th.sort-desc::after {
	content: ' \25BC';
	position: absolute;
	right: 5px;
	color: #2c5282;
	font-size: 0.75em;
}
.pn-table tbody td {
	padding: 7px 10px;
	border-bottom: 1px solid #f0f4f8;
	color: #4a5568;
	vertical-align: top;
}
.pn-table tbody tr:hover {
	background-color: #f7fafc;
}
.pn-table tbody tr:last-child td {
	border-bottom: none;
}
.pn-table a {
	color: #3182ce;
	text-decoration: none;
}
.pn-table a:hover {
	text-decoration: underline;
}
.pn-table .pn-col-numeric {
	text-align: right;
}
.pn-table .pn-col-nowrap {
	white-space: nowrap;
}
.pn-table .pn-award-base {
	color: #a0aec0;
	font-size: 11px;
}

/* Empty state */
.pn-empty {
	text-align: center;
	color: #a0aec0;
	padding: 30px 10px;
	font-size: 13px;
	font-style: italic;
}

/* Recommendation form in modal */
.pn-rec-field {
	margin-bottom: 12px;
}
.pn-rec-field label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.3px;
	margin-bottom: 4px;
}
.pn-rec-field select,
.pn-rec-field input[type="text"] {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	font-size: 13px;
	color: #2d3748;
	box-sizing: border-box;
}
.pn-rec-field select:focus,
.pn-rec-field input[type="text"]:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 2px rgba(49,130,206,0.15);
}

/* Delete link */
.pn-delete-link {
	color: #e53e3e;
	text-decoration: none;
	font-size: 12px;
	font-weight: 600;
}
.pn-delete-link:hover {
	text-decoration: underline;
}

/* Responsive */
@media (max-width: 768px) {
	.pn-layout {
		flex-direction: column;
	}
	.pn-sidebar {
		width: 100%;
	}
}

@media (max-width: 425px) {
	.pn-hero-content {
		flex-direction: column;
		text-align: center;
		padding: 16px;
		gap: 12px;
	}
	.pn-avatar {
		width: 80px;
		height: 80px;
	}
	.pn-persona { font-size: 22px; }
	.pn-badges { justify-content: center; }
	.pn-hero-actions {
		align-items: center;
		flex-direction: row;
	}

	.pn-stats-row {
		flex-wrap: wrap;
	}
	.pn-stat-card {
		flex: 1 1 45%;
		min-width: 0;
	}

	.pn-tab-nav {
		-webkit-overflow-scrolling: touch;
	}
	.pn-tab-nav li {
		padding: 10px 14px;
		font-size: 12px;
	}
	.pn-tab-panel {
		padding: 12px 10px;
		overflow-x: auto;
	}
}
</style>

<!-- =============================================
     ZONE 1: Profile Hero Header
     ============================================= -->
<div class="pn-hero">
	<div class="pn-hero-bg" style="background-image: url('<?= $heraldryUrl ?>')"></div>
	<div class="pn-hero-content">
		<div class="pn-avatar">
			<img src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
		</div>
		<div class="pn-hero-info">
			<h1 class="pn-persona"><?= htmlspecialchars($Player['Persona']) ?></h1>
			<?php if (strlen($Player['GivenName']) > 0 || strlen($Player['Surname']) > 0): ?>
				<div class="pn-real-name"><?= htmlspecialchars(trim($Player['GivenName'] . ' ' . $Player['Surname'])) ?></div>
			<?php endif; ?>
			<?php if (!empty($pronounDisplay)): ?>
				<div class="pn-pronouns"><?= htmlspecialchars($pronounDisplay) ?></div>
			<?php endif; ?>
			<div class="pn-breadcrumb">
				<?php if (valid_id($this->__session->kingdom_id)): ?>
					<a href="<?= UIR ?>Kingdom/index/<?= $this->__session->kingdom_id ?>"><?= htmlspecialchars($this->__session->kingdom_name) ?></a>
					<span class="pn-sep"><i class="fas fa-chevron-right" style="font-size:10px"></i></span>
					<a href="<?= UIR ?>Park/index/<?= $this->__session->park_id ?>"><?= htmlspecialchars($this->__session->park_name) ?></a>
				<?php endif; ?>
			</div>
			<div class="pn-badges">
				<?php if ($isActive): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-check-circle"></i> Active</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-minus-circle"></i> Inactive</span>
				<?php endif; ?>
				<?php if ($isSuspended): ?>
					<span class="pn-badge pn-badge-red"><i class="fas fa-ban"></i> Suspended</span>
				<?php endif; ?>
				<?php if ($Player['Waivered'] == 1): ?>
					<span class="pn-badge pn-badge-blue"><i class="fas fa-file-signature"></i> Waivered</span>
				<?php endif; ?>
				<?php if ($Player['Restricted'] == 1): ?>
					<span class="pn-badge pn-badge-orange"><i class="fas fa-exclamation-triangle"></i> Restricted</span>
				<?php endif; ?>
				<?php if ($Player['DuesThrough'] != 0): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-receipt"></i> Dues Paid</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> Dues Lapsed</span>
				<?php endif; ?>
			</div>
			<?php if ($isSuspended): ?>
				<div class="pn-suspended-detail">
					<i class="fas fa-info-circle"></i>
					Suspended <?= $Player['SuspendedAt'] ?> &mdash; Until <?= $Player['SuspendedUntil'] ?>
					<?php if (!empty($Player['Suspension'])): ?>
						&mdash; <?= htmlspecialchars($Player['Suspension']) ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="pn-hero-actions">
			<?php if ($LoggedIn): ?>
				<button class="pn-btn pn-btn-white" id="pn-recommend-btn"><i class="fas fa-award"></i> Recommend Award</button>
				<a class="pn-btn pn-btn-outline" href="<?= UIR ?>Admin/player/<?= $Player['MundaneId'] ?>"><i class="fas fa-cog"></i> Admin Panel</a>
			<?php endif; ?>
		</div>
	</div>
</div>

<?php if (strlen($Error) > 0): ?>
	<div class='error-message' style="margin-bottom: 14px;"><?= $Error ?></div>
<?php endif; ?>
<?php if (strlen($Message) > 0): ?>
	<div class='success-message' style="margin-bottom: 14px;"><?= $Message ?></div>
<?php endif; ?>

<!-- =============================================
     ZONE 2: Dashboard Stats
     ============================================= -->
<div class="pn-stats-row">
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-calendar-check"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAttendance'] ?></div>
		<div class="pn-stat-label">Attendance</div>
	</div>
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-medal"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAwards'] ?></div>
		<div class="pn-stat-label">Awards</div>
	</div>
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-crown"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalTitles'] ?></div>
		<div class="pn-stat-label">Titles</div>
	</div>
	<div class="pn-stat-card">
		<div class="pn-stat-icon"><i class="fas fa-shield-alt"></i></div>
		<div class="pn-stat-number"><?= $Stats['HighestClassLevel'] ?></div>
		<div class="pn-stat-label">Highest Class</div>
	</div>
</div>

<!-- =============================================
     ZONE 3: Sidebar + Main Content
     ============================================= -->
<div class="pn-layout">

	<!-- ========== SIDEBAR ========== -->
	<div class="pn-sidebar">

		<!-- Player Details -->
		<div class="pn-card">
			<h4><i class="fas fa-user"></i> Player Details</h4>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Given Name</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['GivenName']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Surname</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Surname']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Persona</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Persona']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Username</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['UserName']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Password Expires</span>
				<span class="pn-detail-value"><?= $passwordExpiring ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Member Since</span>
				<span class="pn-detail-value"><?= $Player['ParkMemberSince'] ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Last Sign-In</span>
				<span class="pn-detail-value"><?= ($Player['LastSignInDate'] ? $Player['LastSignInDate'] : 'N/A') ?></span>
			</div>
		</div>

		<!-- Qualifications -->
		<div class="pn-card">
			<h4><i class="fas fa-certificate"></i> Qualifications</h4>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Reeve</span>
				<span class="pn-detail-value">
					<?php if ($Player['ReeveQualified'] != 0): ?>
						<span class="pn-badge pn-badge-green">Until <?= $Player['ReeveQualifiedUntil'] ?></span>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Corpora</span>
				<span class="pn-detail-value">
					<?php if ($Player['CorporaQualified'] != 0): ?>
						<span class="pn-badge pn-badge-green">Until <?= $Player['CorporaQualifiedUntil'] ?></span>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<!-- Dues -->
		<div class="pn-card">
			<h4><i class="fas fa-receipt"></i> Dues</h4>
			<?php if (is_array($Dues) && count($Dues) > 0): ?>
				<table class="pn-mini-table">
					<thead>
						<tr>
							<th>Park</th>
							<th>Paid Until</th>
							<th>Lifetime</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($Dues as $d): ?>
							<tr>
								<td><?= $d['ParkName'] ?></td>
								<td>
									<?php if ($d['DuesForLife'] == 1): ?>
										<span class="pn-dues-life">Lifetime</span>
									<?php else: ?>
										<?= $d['DuesUntil'] ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ($d['DuesForLife'] == 1): ?>
										<span class="pn-dues-life">Yes</span>
									<?php else: ?>
										No
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="pn-empty">No dues records</div>
			<?php endif; ?>
		</div>

		<!-- Companies & Households -->
		<div class="pn-card">
			<h4><i class="fas fa-users"></i> Companies &amp; Households</h4>
			<?php
				$unitList = (is_array($Units['Units'])) ? $Units['Units'] : array();
			?>
			<?php if (count($unitList) > 0): ?>
				<?php foreach ($unitList as $unit): ?>
					<div style="padding: 4px 0;">
						<a class="pn-unit-link" href="<?= UIR ?>Unit/index/<?= $unit['UnitId'] ?>"><?= htmlspecialchars($unit['Name']) ?></a>
						<span class="pn-unit-type"><?= ucfirst($unit['Type']) ?></span>
					</div>
				<?php endforeach; ?>
			<?php else: ?>
				<div class="pn-empty">No memberships</div>
			<?php endif; ?>
		</div>

		<!-- Classes -->
		<div class="pn-card">
			<h4><i class="fas fa-shield-alt"></i> Classes</h4>
			<?php
				$classList = is_array($Details['Classes']) ? $Details['Classes'] : array();
			?>
			<?php if (count($classList) > 0): ?>
				<table class="pn-mini-table" id="pn-classes-table">
					<thead>
						<tr>
							<th>Class</th>
							<th style="text-align:right">Credits</th>
							<th style="text-align:right">Level</th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($classList as $detail): ?>
							<?php $totalCredits = $detail['Credits'] + (isset($Player_index) ? $Player_index['Class_' . $detail['ClassId']] : $detail['Reconciled']); ?>
							<tr>
								<td><?= $detail['ClassName'] ?></td>
								<td style="text-align:right" class="pn-credits"><?= $totalCredits ?></td>
								<td style="text-align:right" class="pn-level">-</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else: ?>
				<div class="pn-empty">No class records</div>
			<?php endif; ?>
		</div>

	</div>

	<!-- ========== MAIN CONTENT (Tabbed) ========== -->
	<div class="pn-main">
		<div class="pn-tabs">
			<ul class="pn-tab-nav">
				<li class="pn-tab-active" data-tab="awards">
					<i class="fas fa-medal"></i> Awards <span class="pn-tab-count">(<?= $Stats['TotalAwards'] ?>)</span>
				</li>
				<li data-tab="titles">
					<i class="fas fa-crown"></i> Titles <span class="pn-tab-count">(<?= $Stats['TotalTitles'] ?>)</span>
				</li>
				<li data-tab="attendance">
					<i class="fas fa-calendar-check"></i> Attendance <span class="pn-tab-count">(<?= $Stats['TotalAttendance'] ?>)</span>
				</li>
				<li data-tab="recommendations">
					<i class="fas fa-star"></i> Recommendations <span class="pn-tab-count">(<?= is_array($AwardRecommendations) ? count($AwardRecommendations) : 0 ?>)</span>
				</li>
				<li data-tab="history">
					<i class="fas fa-history"></i> Historical <span class="pn-tab-count">(<?= is_array($Notes) ? count($Notes) : 0 ?>)</span>
				</li>
			</ul>

			<!-- Awards Tab -->
			<div class="pn-tab-panel" id="pn-tab-awards">
				<?php
					$awardsList = is_array($Details['Awards']) ? $Details['Awards'] : array();
					$filteredAwards = array();
					foreach ($awardsList as $a) {
						if (in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1) {
							$filteredAwards[] = $a;
						}
					}
				?>
				<?php if (count($filteredAwards) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-awards-table">
						<thead>
							<tr>
								<th data-sorttype="text">Award</th>
								<th data-sorttype="numeric">Rank</th>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Given By</th>
								<th data-sorttype="text">Given At</th>
								<th data-sorttype="text">Note</th>
								<th data-sorttype="text">Entered By</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($filteredAwards as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<?= trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'] ?>
										<?php
											$displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'];
											if ($displayName != $detail['Name']): ?>
												<span class="pn-award-base">[<?= $detail['Name'] ?>]</span>
										<?php endif; ?>
									</td>
									<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
									<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/index/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
									<td>
										<?php
											if (valid_id($detail['EventId'])) {
												echo $detail['EventName'];
											} else {
												echo (trimlen($detail['ParkName']) > 0) ? $detail['ParkName'] . ', ' . $detail['KingdomName'] : $detail['KingdomName'];
											}
										?>
									</td>
									<td><?= $detail['Note'] ?></td>
									<td><a href="<?= UIR ?>Player/index/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No awards recorded</div>
				<?php endif; ?>
			</div>

			<!-- Titles Tab -->
			<div class="pn-tab-panel" id="pn-tab-titles" style="display:none">
				<?php
					$filteredTitles = array();
					foreach ($awardsList as $a) {
						if (!in_array($a['OfficerRole'], ['none', null]) || $a['IsTitle'] == 1) {
							$filteredTitles[] = $a;
						}
					}
				?>
				<?php if (count($filteredTitles) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-titles-table">
						<thead>
							<tr>
								<th data-sorttype="text">Title</th>
								<th data-sorttype="numeric">Rank</th>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Given By</th>
								<th data-sorttype="text">Given At</th>
								<th data-sorttype="text">Note</th>
								<th data-sorttype="text">Entered By</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($filteredTitles as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<?= trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'] ?>
										<?php
											$displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName'];
											if ($displayName != $detail['Name']): ?>
												<span class="pn-award-base">[<?= $detail['Name'] ?>]</span>
										<?php endif; ?>
									</td>
									<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
									<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/index/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
									<td>
										<?php
											if (valid_id($detail['EventId'])) {
												echo $detail['EventName'];
											} else {
												echo (trimlen($detail['ParkName']) > 0) ? $detail['ParkName'] . ', ' . $detail['KingdomName'] : $detail['KingdomName'];
											}
										?>
									</td>
									<td><?= $detail['Note'] ?></td>
									<td><a href="<?= UIR ?>Player/index/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No titles recorded</div>
				<?php endif; ?>
			</div>

			<!-- Attendance Tab -->
			<div class="pn-tab-panel" id="pn-tab-attendance" style="display:none">
				<?php $attendanceList = is_array($Details['Attendance']) ? $Details['Attendance'] : array(); ?>
				<?php if (count($attendanceList) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-attendance-table">
						<thead>
							<tr>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Kingdom</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric">Credits</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($attendanceList as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<a href="<?= UIR ?>Attendance/<?= $detail['ParkId'] > 0 ? 'park' : 'event' ?>/<?= (($detail['ParkId'] > 0) ? ($detail['ParkId'] . '&AttendanceDate=' . $detail['Date']) : ($detail['EventId'] . '/' . $detail['EventCalendarDetailId'])) ?>"><?= $detail['Date'] ?></a>
									</td>
									<td><a href="<?= UIR ?>Kingdom/index/<?= $detail['KingdomId'] ?>"><?= $detail['KingdomName'] ?></a></td>
									<td><a href="<?= UIR ?>Park/index/<?= $detail['ParkId'] ?>"><?= $detail['ParkName'] ?></a></td>
									<td><a href="<?= UIR ?>Attendance/event/<?= $detail['EventId'] ?>/<?= $detail['EventCalendarDetailId'] ?>"><?= $detail['EventName'] ?></a></td>
									<td><?= trimlen($detail['Flavor']) > 0 ? $detail['Flavor'] : $detail['ClassName'] ?></td>
									<td class="pn-col-numeric"><?= $detail['Credits'] ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No attendance records</div>
				<?php endif; ?>
			</div>

			<!-- Recommendations Tab -->
			<div class="pn-tab-panel" id="pn-tab-recommendations" style="display:none">
				<?php $recList = is_array($AwardRecommendations) ? $AwardRecommendations : array(); ?>
				<?php if (count($recList) > 0): ?>
					<table class="pn-table" id="pn-rec-table">
						<thead>
							<tr>
								<th>Award</th>
								<th>Rank</th>
								<th>Date</th>
								<th>Sent By</th>
								<th>Reason</th>
								<?php if ($this->__session->user_id): ?>
									<th>Actions</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($recList as $rec): ?>
								<tr>
									<td><?= $rec['AwardName'] ?></td>
									<td class="pn-col-numeric"><?= valid_id($rec['Rank']) ? $rec['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= $rec['DateRecommended'] ?></td>
									<td><a href="<?= UIR ?>Player/index/<?= $rec['RecommendedById'] ?>"><?= $rec['RecommendedByName'] ?></a></td>
									<td><?= htmlspecialchars($rec['Reason']) ?></td>
									<?php if ($this->__session->user_id): ?>
										<td>
											<?php if ($can_delete_recommendation || $this->__session->user_id == $rec['RecommendedById'] || $this->__session->user_id == $rec['MundaneId']): ?>
												<a class="pn-delete-link pn-confirm-delete-rec" href="<?= UIR ?>Player/index/<?= $rec['MundaneId'] ?>/deleterecommendation/<?= $rec['RecommendationsId'] ?>"><i class="fas fa-trash-alt"></i> Delete</a>
											<?php endif; ?>
										</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No recommendations</div>
				<?php endif; ?>
			</div>

			<!-- Historical Imports Tab -->
			<div class="pn-tab-panel" id="pn-tab-history" style="display:none">
				<?php $notesList = is_array($Notes) ? $Notes : array(); ?>
				<?php if (count($notesList) > 0): ?>
					<table class="pn-table">
						<thead>
							<tr>
								<th>Note</th>
								<th>Description</th>
								<th>Date</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($notesList as $note): ?>
								<tr>
									<td><?= $note['Note'] ?></td>
									<td><?= $note['Description'] ?></td>
									<td class="pn-col-nowrap"><?= $note['Date'] . (strtotime($note['DateComplete']) > 0 ? (' - ' . $note['DateComplete']) : '') ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No historical imports</div>
				<?php endif; ?>
			</div>

		</div>
	</div>

</div>

<!-- =============================================
     Recommendation Modal (hidden)
     ============================================= -->
<?php if ($LoggedIn): ?>
<div id="pn-recommend-dialog" style="display:none" title="Recommend an Award">
	<form id="pn-recommend-form" method="post" action="<?= UIR ?>Player/index/<?= $Player['MundaneId'] ?>/addrecommendation">
		<div class="pn-rec-field">
			<label for="pn-rec-award">Award</label>
			<select name="KingdomAwardId" id="pn-rec-award">
				<option value="">Select Award...</option>
				<?= $AwardOptions ?>
			</select>
		</div>
		<div class="pn-rec-field">
			<label for="pn-rec-rank">Rank</label>
			<select name="Rank" id="pn-rec-rank">
				<option value="">Select...</option>
				<option value="1">1st</option>
				<option value="2">2nd</option>
				<option value="3">3rd</option>
				<option value="4">4th</option>
				<option value="5">5th</option>
				<option value="6">6th</option>
				<option value="7">7th</option>
				<option value="8">8th</option>
				<option value="9">9th</option>
				<option value="10">10th</option>
			</select>
		</div>
		<div class="pn-rec-field">
			<label for="pn-rec-reason">Reason</label>
			<input type="text" name="Reason" id="pn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
		</div>
	</form>
</div>

<div id="pn-delete-rec-dialog" style="display:none" title="Confirm Deletion">
	Are you sure you want to delete this recommendation?
</div>
<?php endif; ?>

<!-- =============================================
     JavaScript
     ============================================= -->
<script type="text/javascript">
$(document).ready(function() {

	// ---- Tab Switching ----
	$('.pn-tab-nav li').on('click', function() {
		var tabId = $(this).data('tab');
		$('.pn-tab-nav li').removeClass('pn-tab-active');
		$(this).addClass('pn-tab-active');
		$('.pn-tab-panel').hide();
		$('#pn-tab-' + tabId).show();
	});

	// ---- Class Level Calculation ----
	$('#pn-classes-table tbody tr').each(function() {
		var credits = Number($(this).find('.pn-credits').text());
		var level = 1;
		if (credits >= 53) level = 6;
		else if (credits >= 34) level = 5;
		else if (credits >= 21) level = 4;
		else if (credits >= 12) level = 3;
		else if (credits >= 5) level = 2;
		$(this).find('.pn-level').text(level);
	});

	// ---- Sortable Tables ----
	$('.pn-sortable').each(function() {
		var table = $(this);
		table.find('thead th').on('click', function() {
			var columnIndex = $(this).index();
			var sortType = $(this).data('sorttype') || 'text';
			var isAscending = !$(this).hasClass('sort-asc');

			table.find('thead th').removeClass('sort-asc sort-desc');
			$(this).addClass(isAscending ? 'sort-asc' : 'sort-desc');

			var tbody = table.find('tbody');
			var rows = tbody.find('tr').get();

			rows.sort(function(a, b) {
				var aText = $(a).find('td').eq(columnIndex).text().trim();
				var bText = $(b).find('td').eq(columnIndex).text().trim();
				var cmp = 0;

				if (sortType === 'numeric') {
					cmp = (parseFloat(aText) || 0) - (parseFloat(bText) || 0);
				} else if (sortType === 'date') {
					cmp = (new Date(aText).getTime() || 0) - (new Date(bText).getTime() || 0);
				} else {
					cmp = aText.localeCompare(bText);
				}
				return isAscending ? cmp : -cmp;
			});

			$.each(rows, function(i, row) {
				tbody.append(row);
			});
		});
	});

	<?php if ($LoggedIn): ?>
	// ---- Recommendation Modal ----
	$('#pn-recommend-btn').on('click', function(e) {
		e.preventDefault();
		$('#pn-recommend-dialog').dialog({
			modal: true,
			width: 460,
			buttons: {
				'Cancel': function() {
					$(this).dialog('close');
				},
				'Submit': function() {
					var form = $('#pn-recommend-form');
					if (form.find('select[name=KingdomAwardId]').val() && form.find('input[name=Reason]').val()) {
						form.submit();
					} else {
						alert('Select an award and provide a reason.');
					}
				}
			}
		});
	});

	// Character counter on reason field
	$('#pn-rec-reason').simpleTxtCounter({
		maxLength: 400,
		countElem: '<span style="margin-left:5px;font-size:11px;color:#a0aec0;"></span>'
	});

	// ---- Delete Recommendation Confirmation ----
	$('.pn-confirm-delete-rec').on('click', function(e) {
		e.preventDefault();
		var targetUrl = $(this).attr('href');
		$('#pn-delete-rec-dialog').dialog({
			modal: true,
			width: 400,
			buttons: {
				'Cancel': function() { $(this).dialog('close'); },
				'Delete': function() { window.location.href = targetUrl; $(this).dialog('close'); }
			}
		});
	});
	<?php endif; ?>

});
</script>
