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

	$knightAwardIds = array(17, 18, 19, 20, 245);
	$isKnight = false;
	if (is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $a) {
			if (in_array((int)$a['AwardId'], $knightAwardIds)) {
				$isKnight = true;
				break;
			}
		}
	}
	$beltIconUrl = 'http://' . $_SERVER['HTTP_HOST'] . '/assets/images/belt.svg';
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
	background-color: transparent;
	border: none;
	text-shadow: 0 1px 3px rgba(0,0,0,0.4);
	padding: 0;
	display: flex;
	align-items: center;
	gap: 10px;
}
.pn-belt-icon {
	width: 20px;
	height: 27px;
	flex-shrink: 0;
	filter: brightness(0) invert(1);
	opacity: 0.9;
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
.pn-badge-gold   { background: #fefcbf; color: #744210; }

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
.pn-stat-card-link {
	cursor: pointer;
	transition: border-color 0.15s, box-shadow 0.15s, transform 0.12s;
}
.pn-stat-card-link:hover {
	border-color: #bee3f8;
	box-shadow: 0 3px 10px rgba(0,0,0,0.10);
	transform: translateY(-2px);
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
	padding: 0 0 8px 0;
	background-color: transparent;
	border: none;
	border-bottom: 1px solid #edf2f7;
	text-shadow: none;
	border-radius: 0;
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

/* Pagination */
.pn-pagination {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 10px 0 0 0;
	margin-top: 6px;
	border-top: 1px solid #edf2f7;
}
.pn-pagination-info {
	color: #a0aec0;
	font-size: 12px;
}
.pn-pagination-controls {
	display: flex;
	gap: 3px;
	align-items: center;
}
.pn-page-btn {
	min-width: 28px;
	height: 28px;
	border: 1px solid #e2e8f0;
	border-radius: 4px;
	background: #fff;
	color: #4a5568;
	font-size: 12px;
	font-weight: 600;
	cursor: pointer;
	padding: 0 6px;
	display: inline-flex;
	align-items: center;
	justify-content: center;
	transition: background 0.1s, border-color 0.1s;
	line-height: 1;
}
.pn-page-btn:hover:not(:disabled) { background: #edf2f7; border-color: #cbd5e0; }
.pn-page-btn.pn-page-active { background: #2c5282; color: #fff; border-color: #2c5282; }
.pn-page-btn:disabled { opacity: 0.4; cursor: default; }
.pn-page-ellipsis { color: #a0aec0; padding: 0 3px; font-size: 12px; line-height: 28px; }

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

/* Delete link + inline confirm */
.pn-delete-link {
	color: #e53e3e;
	text-decoration: none;
	font-size: 12px;
	font-weight: 600;
}
.pn-delete-link:hover { text-decoration: underline; }
.pn-delete-link.pn-hidden { display: none; }
.pn-delete-confirm {
	display: none;
	align-items: center;
	gap: 5px;
	font-size: 12px;
	color: #4a5568;
	white-space: nowrap;
}
.pn-delete-confirm.pn-active { display: inline-flex; }
.pn-delete-yes, .pn-delete-no {
	background: none;
	border: none;
	padding: 0;
	cursor: pointer;
	font-size: 12px;
	font-weight: 600;
	text-decoration: underline;
}
.pn-delete-yes { color: #e53e3e; }
.pn-delete-no  { color: #718096; }

/* Custom Modal */
.pn-overlay {
	position: fixed;
	top: 0; left: 0; right: 0; bottom: 0;
	background: rgba(0,0,0,0.5);
	z-index: 10000;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0;
	pointer-events: none;
	transition: opacity 0.2s ease;
}
.pn-overlay.pn-open {
	opacity: 1;
	pointer-events: auto;
}
.pn-modal-box {
	background: #fff;
	border-radius: 12px;
	width: 500px;
	max-width: calc(100vw - 40px);
	box-shadow: 0 25px 60px rgba(0,0,0,0.25);
	transform: translateY(-16px) scale(0.98);
	opacity: 0;
	transition: transform 0.2s ease, opacity 0.2s ease;
}
.pn-overlay.pn-open .pn-modal-box {
	transform: translateY(0) scale(1);
	opacity: 1;
}
.pn-modal-header {
	display: flex;
	align-items: center;
	justify-content: space-between;
	padding: 18px 20px;
	border-bottom: 1px solid #e2e8f0;
}
.pn-modal-title {
	margin: 0;
	font-size: 16px;
	font-weight: 700;
	color: #2d3748;
	background: none;
	border: none;
	text-shadow: none;
	padding: 0;
	border-radius: 0;
}
.pn-modal-close-btn {
	background: none;
	border: none;
	width: 30px;
	height: 30px;
	display: flex;
	align-items: center;
	justify-content: center;
	border-radius: 50%;
	cursor: pointer;
	color: #a0aec0;
	font-size: 20px;
	padding: 0;
	line-height: 1;
	transition: background 0.15s, color 0.15s;
}
.pn-modal-close-btn:hover { background: #f7fafc; color: #4a5568; }
.pn-modal-body { padding: 20px; }
.pn-modal-footer {
	padding: 14px 20px;
	border-top: 1px solid #e2e8f0;
	display: flex;
	justify-content: flex-end;
	gap: 10px;
}
.pn-btn-primary { background: #2c5282; color: #fff; }
.pn-btn-primary:hover { background: #2a4a7f; }
.pn-btn-secondary { background: #edf2f7; color: #4a5568; }
.pn-btn-secondary:hover { background: #e2e8f0; }
.pn-form-error {
	background: #fff5f5;
	border: 1px solid #fed7d7;
	border-radius: 6px;
	padding: 9px 12px;
	color: #c53030;
	font-size: 13px;
	margin-bottom: 14px;
	display: none;
}
.pn-char-count {
	display: block;
	text-align: right;
	font-size: 11px;
	color: #a0aec0;
	margin-top: 3px;
}
.pn-char-count.pn-char-warn { color: #e53e3e; }

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
			<img class="heraldry-img" src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
		</div>
		<div class="pn-hero-info">
			<h1 class="pn-persona">
				<?= htmlspecialchars($Player['Persona']) ?>
				<?php if ($isKnight): ?>
					<img class="pn-belt-icon" src="<?= $beltIconUrl ?>" alt="Knight" title="Belted Knight" />
				<?php endif; ?>
			</h1>
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
				<?php if (!empty($OfficerRoles)): ?>
					<?php foreach ($OfficerRoles as $office): ?>
						<span class="pn-badge pn-badge-gold"><i class="fas fa-crown"></i> <?= htmlspecialchars($office['entity_type']) ?> <?= htmlspecialchars($office['role']) ?></span>
					<?php endforeach; ?>
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
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('attendance')">
		<div class="pn-stat-icon"><i class="fas fa-calendar-check"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAttendance'] ?></div>
		<div class="pn-stat-label">Attendance</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('awards')">
		<div class="pn-stat-icon"><i class="fas fa-medal"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalAwards'] ?></div>
		<div class="pn-stat-label">Awards</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('titles')">
		<div class="pn-stat-icon"><i class="fas fa-crown"></i></div>
		<div class="pn-stat-number"><?= $Stats['TotalTitles'] ?></div>
		<div class="pn-stat-label">Titles</div>
	</div>
	<div class="pn-stat-card pn-stat-card-link" onclick="pnActivateTab('classes')">
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

		<!-- Heraldry -->
		<div class="pn-card">
			<h4><i class="fas fa-image"></i> Heraldry</h4>
			<div style="text-align: center;">
				<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain;" />
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
				<li data-tab="classes">
					<i class="fas fa-shield-alt"></i> Class Levels <span class="pn-tab-count">(<?= is_array($Details['Classes']) ? count($Details['Classes']) : 0 ?>)</span>
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
												<span class="pn-delete-cell">
												<a class="pn-delete-link pn-confirm-delete-rec" href="#"><i class="fas fa-trash-alt"></i> Delete</a>
												<span class="pn-delete-confirm">
													Delete?&nbsp;
													<button class="pn-delete-yes" data-href="<?= UIR ?>Playernew/index/<?= $rec['MundaneId'] ?>/deleterecommendation/<?= $rec['RecommendationsId'] ?>">Yes</button>
													&nbsp;<button class="pn-delete-no">No</button>
												</span>
											</span>
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
					<table class="pn-table" id="pn-history-table">
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

			<!-- Class Levels Tab -->
			<div class="pn-tab-panel" id="pn-tab-classes" style="display:none">
				<?php $classList = is_array($Details['Classes']) ? $Details['Classes'] : array(); ?>
				<?php if (count($classList) > 0): ?>
					<table class="pn-table pn-sortable" id="pn-classes-table">
						<thead>
							<tr>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Credits</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Level</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($classList as $detail): ?>
								<?php $totalCredits = $detail['Credits'] + (isset($Player_index) ? $Player_index['Class_' . $detail['ClassId']] : $detail['Reconciled']); ?>
								<tr>
									<td><?= $detail['ClassName'] ?></td>
									<td class="pn-col-numeric pn-credits"><?= $totalCredits ?></td>
									<td class="pn-col-numeric pn-level">-</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No class records</div>
				<?php endif; ?>
			</div>

		</div>
	</div>

</div>

<!-- =============================================
     Recommendation Modal
     ============================================= -->
<?php if ($LoggedIn): ?>
<div class="pn-overlay" id="pn-rec-overlay">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-award" style="margin-right:8px;color:#2c5282"></i>Recommend an Award</h3>
			<button class="pn-modal-close-btn" id="pn-modal-close-btn" type="button">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div class="pn-form-error" id="pn-rec-error"></div>
			<form id="pn-recommend-form" method="post" action="<?= UIR ?>Playernew/index/<?= $Player['MundaneId'] ?>/addrecommendation">
				<div class="pn-rec-field">
					<label for="pn-rec-award">Award <span style="color:#e53e3e">*</span></label>
					<select name="KingdomAwardId" id="pn-rec-award">
						<option value="">Select award...</option>
						<?= $AwardOptions ?>
					</select>
				</div>
				<div class="pn-rec-field">
					<label for="pn-rec-rank">Rank <span style="color:#a0aec0;font-weight:400;text-transform:none">(optional)</span></label>
					<select name="Rank" id="pn-rec-rank">
						<option value="">None</option>
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
					<label for="pn-rec-reason">Reason <span style="color:#e53e3e">*</span></label>
					<input type="text" name="Reason" id="pn-rec-reason" maxlength="400" placeholder="Why should this player receive this award?" />
					<span class="pn-char-count" id="pn-rec-char-count">400 characters remaining</span>
				</div>
			</form>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-rec-cancel" type="button">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-rec-submit" type="button"><i class="fas fa-paper-plane"></i> Submit Recommendation</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php
// Build KingdomAwardId => max rank held by this player (for ladder award pre-fill)
$playerAwardRanks = array();
if (is_array($Details['Awards'])) {
	foreach ($Details['Awards'] as $a) {
		$aid  = (int)$a['AwardId'];
		$rank = (int)$a['Rank'];
		if ($aid > 0 && $rank > 0) {
			if (!isset($playerAwardRanks[$aid]) || $rank > $playerAwardRanks[$aid]) {
				$playerAwardRanks[$aid] = $rank;
			}
		}
	}
}
?>

<!-- =============================================
     JavaScript
     ============================================= -->
<script type="text/javascript">
// ---- Pagination Helpers ----
function pnPageRange(current, total) {
	var pages = [];
	if (total <= 7) {
		for (var p = 1; p <= total; p++) pages.push(p);
	} else {
		pages.push(1);
		if (current > 3) pages.push(-1);
		var s = Math.max(2, current - 1);
		var e = Math.min(total - 1, current + 1);
		for (var p = s; p <= e; p++) pages.push(p);
		if (current < total - 2) pages.push(-1);
		pages.push(total);
	}
	return pages;
}

function pnPaginate($table, page) {
	var pageSize = 10;
	var $rows = $table.find('tbody tr');
	var total = $rows.length;
	if (total === 0) return;
	var totalPages = Math.max(1, Math.ceil(total / pageSize));
	page = Math.max(1, Math.min(page, totalPages));
	$table.data('pn-page', page);
	$rows.each(function(i) {
		$(this).toggle(i >= (page - 1) * pageSize && i < page * pageSize);
	});
	var $pg = $table.next('.pn-pagination');
	if ($pg.length === 0) $pg = $('<div class="pn-pagination"></div>').insertAfter($table);
	if (total <= pageSize) { $pg.empty().hide(); return; }
	$pg.show();
	var start = (page - 1) * pageSize + 1;
	var end = Math.min(page * pageSize, total);
	var html = '<span class="pn-pagination-info">Showing ' + start + '\u2013' + end + ' of ' + total + '</span>';
	html += '<div class="pn-pagination-controls">';
	html += '<button class="pn-page-btn pn-page-prev"' + (page === 1 ? ' disabled' : '') + '>&#8249;</button>';
	var range = pnPageRange(page, totalPages);
	for (var ri = 0; ri < range.length; ri++) {
		if (range[ri] === -1) {
			html += '<span class="pn-page-ellipsis">&hellip;</span>';
		} else {
			html += '<button class="pn-page-btn pn-page-num' + (range[ri] === page ? ' pn-page-active' : '') + '" data-page="' + range[ri] + '">' + range[ri] + '</button>';
		}
	}
	html += '<button class="pn-page-btn pn-page-next"' + (page === totalPages ? ' disabled' : '') + '>&#8250;</button>';
	html += '</div>';
	$pg.html(html);
}

function pnSortDesc($table, colIndex, sortType) {
	if (!$table.length) return;
	$table.find('thead th').removeClass('sort-asc sort-desc');
	$table.find('thead th').eq(colIndex).addClass('sort-desc');
	var $tbody = $table.find('tbody');
	var rows = $tbody.find('tr').get();
	rows.sort(function(a, b) {
		var aVal = $(a).find('td').eq(colIndex).text().trim();
		var bVal = $(b).find('td').eq(colIndex).text().trim();
		var cmp = 0;
		if (sortType === 'numeric') {
			cmp = (parseFloat(aVal) || 0) - (parseFloat(bVal) || 0);
		} else if (sortType === 'date') {
			cmp = (new Date(aVal).getTime() || 0) - (new Date(bVal).getTime() || 0);
		} else {
			cmp = aVal.localeCompare(bVal);
		}
		return -cmp;
	});
	$.each(rows, function(i, row) { $tbody.append(row); });
}

function pnActivateTab(tab) {
	$('.pn-tab-nav li').removeClass('pn-tab-active');
	$('.pn-tab-nav li[data-tab="' + tab + '"]').addClass('pn-tab-active');
	$('.pn-tab-panel').hide();
	$('#pn-tab-' + tab).show();
	$('html, body').animate({ scrollTop: $('.pn-tabs').offset().top - 20 }, 250);
}

$(document).ready(function() {

	// ---- Tab Switching ----
	$('.pn-tab-nav li').on('click', function() {
		pnActivateTab($(this).data('tab'));
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
			pnPaginate(table, 1);
		});
	});

	<?php if ($LoggedIn): ?>
	// ---- Custom Recommendation Modal ----
	function pnOpenModal() {
		$('#pn-rec-overlay').addClass('pn-open');
		$('body').css('overflow', 'hidden');
	}
	function pnCloseModal() {
		$('#pn-rec-overlay').removeClass('pn-open');
		$('body').css('overflow', '');
		$('#pn-rec-error').hide().text('');
	}

	$('#pn-recommend-btn').on('click', function(e) {
		e.preventDefault();
		pnOpenModal();
	});
	$('#pn-modal-close-btn, #pn-rec-cancel').on('click', function() {
		pnCloseModal();
	});
	// Close on backdrop click
	$('#pn-rec-overlay').on('click', function(e) {
		if (e.target === this) pnCloseModal();
	});
	// Escape key
	$(document).on('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && $('#pn-rec-overlay').hasClass('pn-open')) {
			pnCloseModal();
		}
	});

	// Submit with validation
	$('#pn-rec-submit').on('click', function() {
		var award  = $('#pn-rec-award').val();
		var reason = $.trim($('#pn-rec-reason').val());
		if (!award || !reason) {
			$('#pn-rec-error').text('Please select an award and provide a reason.').show();
			return;
		}
		$('#pn-rec-error').hide();
		$('#pn-rec-submit').prop('disabled', true).text('Submitting…');
		$('#pn-recommend-form').submit();
	});

	// Character counter
	$('#pn-rec-reason').on('input', function() {
		var remaining = 400 - $(this).val().length;
		$('#pn-rec-char-count')
			.text(remaining + ' character' + (remaining !== 1 ? 's' : '') + ' remaining')
			.toggleClass('pn-char-warn', remaining < 50);
	});


	// Auto-fill rank for ladder awards based on player's existing ranks
	var pnAwardRanks = <?= json_encode($playerAwardRanks) ?>;
	$('#pn-rec-award').on('change', function() {
		var $opt     = $(this).find('option:selected');
		var isLadder = $opt.data('is-ladder') == 1;
		var baseId   = parseInt($opt.data('award-id')) || 0;
		if (!$(this).val()) {
			$('#pn-rec-rank').val('');
		} else if (isLadder && baseId) {
			var currentRank = pnAwardRanks[baseId] || 0;
			$('#pn-rec-rank').val(String(Math.min(currentRank + 1, 6)));
		} else {
			$('#pn-rec-rank').val('');
		}
	});

	// ---- Inline Delete Confirmation ----
	$(document).on('click', '.pn-confirm-delete-rec', function(e) {
		e.preventDefault();
		var $cell = $(this).closest('.pn-delete-cell');
		$(this).addClass('pn-hidden');
		$cell.find('.pn-delete-confirm').addClass('pn-active');
	});
	$(document).on('click', '.pn-delete-yes', function() {
		window.location.href = $(this).data('href');
	});
	$(document).on('click', '.pn-delete-no', function() {
		var $cell = $(this).closest('.pn-delete-cell');
		$cell.find('.pn-delete-link').removeClass('pn-hidden');
		$cell.find('.pn-delete-confirm').removeClass('pn-active');
	});
	<?php endif; ?>

	// ---- Pagination: page button handlers ----
	$(document).on('click', '.pn-page-num', function() {
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, parseInt($(this).data('page')));
	});
	$(document).on('click', '.pn-page-prev', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, ($table.data('pn-page') || 1) - 1);
	});
	$(document).on('click', '.pn-page-next', function() {
		if ($(this).prop('disabled')) return;
		var $table = $(this).closest('.pn-pagination').prev('.pn-table');
		if ($table.length) pnPaginate($table, ($table.data('pn-page') || 1) + 1);
	});

	// ---- Default sort (date desc) + initial pagination ----
	<?php if (count($filteredAwards) > 0): ?>
	pnSortDesc($('#pn-awards-table'), 2, 'date');
	pnPaginate($('#pn-awards-table'), 1);
	<?php endif; ?>
	<?php if (count($filteredTitles) > 0): ?>
	pnSortDesc($('#pn-titles-table'), 2, 'date');
	pnPaginate($('#pn-titles-table'), 1);
	<?php endif; ?>
	<?php if (count($attendanceList) > 0): ?>
	pnSortDesc($('#pn-attendance-table'), 0, 'date');
	pnPaginate($('#pn-attendance-table'), 1);
	<?php endif; ?>
	<?php if (count($recList) > 0): ?>
	pnSortDesc($('#pn-rec-table'), 2, 'date');
	pnPaginate($('#pn-rec-table'), 1);
	<?php endif; ?>
	<?php if (count($notesList) > 0): ?>
	pnSortDesc($('#pn-history-table'), 2, 'date');
	pnPaginate($('#pn-history-table'), 1);
	<?php endif; ?>
	<?php if (count($classList) > 0): ?>
	pnSortDesc($('#pn-classes-table'), 2, 'numeric');
	pnPaginate($('#pn-classes-table'), 1);
	<?php endif; ?>


});
</script>
