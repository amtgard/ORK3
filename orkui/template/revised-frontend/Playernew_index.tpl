<?php
	$passwordExpired  = strtotime($Player['PasswordExpires']) - time() <= 0;
	$passwordSoonSecs = strtotime($Player['PasswordExpires']) - time();
	$passwordSoon     = !$passwordExpired && $passwordSoonSecs <= (14 * 86400);
	$passwordExpiring = $passwordExpired ? 'Expired' : date('Y-m-j', strtotime($Player['PasswordExpires']));
	$recError = isset($_GET['rec_error']) ? htmlspecialchars(urldecode($_GET['rec_error'])) : '';

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

	// Auth helpers
	$isOwnProfile  = isset($this->__session->user_id) && (int)$this->__session->user_id === (int)$Player['MundaneId'];
	$canEditAdmin  = isset($this->__session->user_id) && Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT);
	$canEditImages  = $isOwnProfile || $canEditAdmin;
	$canEditAccount = $isOwnProfile || $canEditAdmin;

	// Check if player has any reconcilable historical awards
	$hasHistorical = false;
	if ($canEditAdmin && is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $_ha) {
			if (in_array($_ha['OfficerRole'], ['none', null]) && $_ha['IsTitle'] != 1) {
				if ((int)$_ha['GivenById'] === 0 && (int)($_ha['EnteredById'] ?? 0) === 0) {
					$hasHistorical = true;
					break;
				}
			}
		}
	}

	// Same check, visible to anyone viewing the profile
	$hasHistoricalTip = false;
	if (is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $_ha) {
			if (in_array($_ha['OfficerRole'], ['none', null]) && $_ha['IsTitle'] != 1) {
				if ((int)$_ha['GivenById'] === 0 && (int)($_ha['EnteredById'] ?? 0) === 0) {
					$hasHistoricalTip = true;
					break;
				}
			}
		}
	}

	// Kingdom dues period config
	$_kconfig = Common::get_configs((int)($KingdomId ?? 0));
	$_duesPeriodType = (isset($_kconfig['DuesPeriod']['Value']->Type) && $_kconfig['DuesPeriod']['Value']->Type !== '-')
		? $_kconfig['DuesPeriod']['Value']->Type
		: 'month';
	$_duesPeriod = (!empty($_kconfig['DuesPeriod']['Value']->Period)) ? (int)$_kconfig['DuesPeriod']['Value']->Period : 6;

	// Last class used (for attendance modal default)
	$_lastClassId = 0;
	foreach (is_array($Details['Attendance']) ? $Details['Attendance'] : [] as $_att) {
		if (!empty($_att['ClassId'])) { $_lastClassId = (int)$_att['ClassId']; break; }
	}

	// Class → Paragon award map (used by My Amtgard + Class Levels tabs)
	$pnClassToParagon = [
		1=>37, 2=>38, 3=>39, 4=>40, 5=>41, 6=>241, 7=>42, 8=>43,
		9=>44, 10=>45, 11=>46, 12=>47, 14=>242, 15=>49, 16=>50, 17=>51,
	];
	$pnHeldAwardIds = [];
	if (is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $_pa) {
			$_aid = (int)($_pa['AwardId'] ?? 0);
			if ($_aid > 0) $pnHeldAwardIds[$_aid] = true;
		}
	}

	// My Amtgard dashboard pre-computation (own profile only)
	if ($isOwnProfile) {
		$_maDash_att = is_array($Details['Attendance']) ? $Details['Attendance'] : [];
		$_maDash_awd = is_array($Details['Awards'])     ? $Details['Awards']     : [];
		$_maDash_cls = is_array($Details['Classes'])    ? $Details['Classes']    : [];
		usort($_maDash_att, function($a, $b) { return strtotime($b['Date']) - strtotime($a['Date']); });
		usort($_maDash_awd, function($a, $b) { return strtotime($b['Date']) - strtotime($a['Date']); });
		// First credit date (oldest attendance)
		$_maFirstDate = null;
		foreach ($_maDash_att as $_fa) {
			if (!empty($_fa['Date']) && $_fa['Date'] !== '1970-01-01') {
				if ($_maFirstDate === null || strtotime($_fa['Date']) < strtotime($_maFirstDate))
					$_maFirstDate = $_fa['Date'];
			}
		}
		// 3 most recently signed-in classes (by last attendance date)
		$_maRecentClassIds = [];
		foreach ($_maDash_att as $_fa) {
			$_fcid = (int)($_fa['ClassId'] ?? 0);
			if ($_fcid > 0 && !isset($_maRecentClassIds[$_fcid])) {
				$_maRecentClassIds[$_fcid] = true;
				if (count($_maRecentClassIds) >= 3) break;
			}
		}
		$_maClassMap = [];
		foreach ($_maDash_cls as $_mc) { $_maClassMap[(int)$_mc['ClassId']] = $_mc; }
		$_maClasses = [];
		foreach (array_keys($_maRecentClassIds) as $_rcid) {
			if (isset($_maClassMap[$_rcid]) && ((int)($_maClassMap[$_rcid]['Credits'] ?? 0) + (int)($_maClassMap[$_rcid]['Reconciled'] ?? 0)) > 0)
				$_maClasses[] = $_maClassMap[$_rcid];
		}
		// Open recs
		$_maOpenRecs = array_values(array_filter(
			is_array($AwardRecommendations) ? $AwardRecommendations : [],
			function($r) { return empty($r['Awarded']); }
		));
		// Alerts
		$_maAlerts = [];
		$_duesThrough = $Player['DuesThrough'] ?? '';
		if (!empty($_duesThrough) && $_duesThrough !== '0000-00-00' && strtotime($_duesThrough) < time())
			$_maAlerts[] = ['type'=>'warning','icon'=>'fa-exclamation-circle','msg'=>'Your dues have lapsed.'];
		if (empty($Player['Waivered']))
			$_maAlerts[] = ['type'=>'info','icon'=>'fa-file-signature','msg'=>'No waiver on file at your park.'];
		if ($passwordExpired)
			$_maAlerts[] = ['type'=>'danger','icon'=>'fa-key','msg'=>'Your password has expired.'];
		elseif ($passwordSoon) {
			$_daysLeft = max(1, ceil($passwordSoonSecs / 86400));
			$_maAlerts[] = ['type'=>'warning','icon'=>'fa-key','msg'=>"Your password expires in {$_daysLeft} day" . ($_daysLeft===1?'':'s') . "."];
		}
		// Level helpers
		function _ma_level($credits) {
			if ($credits >= 53) return 6;
			if ($credits >= 34) return 5;
			if ($credits >= 21) return 4;
			if ($credits >= 12) return 3;
			if ($credits >= 5)  return 2;
			return 1;
		}
		function _ma_progress($credits) {
			$t = [0,5,12,21,34,53];
			if ($credits >= 53) return 100;
			for ($i = count($t)-1; $i >= 0; $i--)
				if ($credits >= $t[$i]) return round(($credits-$t[$i])/($t[$i+1]-$t[$i])*100);
			return 0;
		}
	}
?>

<style>:root { --pn-hero-bg: <?= $isSuspended ? '#9b2c2c' : '#2c5282' ?>; }</style>
<style>
/* ===== My Amtgard Dashboard ===== */
.pna-alerts{display:flex;flex-direction:column;gap:6px;margin-bottom:14px}
.pna-alert{display:flex;align-items:flex-start;gap:9px;padding:9px 13px;border-radius:6px;font-size:12.5px;line-height:1.4}
.pna-alert i{flex-shrink:0;margin-top:2px}
.pna-alert-warning{background:#fffbeb;border:1px solid #f6e05e;color:#744210}
.pna-alert-danger{background:#fff5f5;border:1px solid #fc8181;color:#742a2a}
.pna-alert-info{background:#ebf8ff;border:1px solid #90cdf4;color:#2a4365}
.pna-layout{display:flex;gap:16px;align-items:flex-start}
.pna-sidebar{flex:0 0 260px;display:flex;flex-direction:column;gap:12px}
.pna-feed{flex:1;display:flex;flex-direction:column;gap:12px;min-width:0}
.pna-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px}
.pna-card-title{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#718096;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.pna-card-title a.pna-card-more{margin-left:auto;font-weight:600;font-size:11px;color:#4299e1;text-decoration:none;text-transform:none;letter-spacing:0}
.pna-card-title a.pna-card-more:hover{text-decoration:underline}
.pna-tenure{text-align:center;padding:6px 0 2px}
.pna-tenure-years{font-size:44px;font-weight:800;color:#2c5282;line-height:1}
.pna-tenure-label{font-size:13px;color:#718096;margin-top:2px}
.pna-tenure-since{font-size:11px;color:#a0aec0;margin-top:6px}
@keyframes pna-card-glow{0%,100%{box-shadow:0 0 10px 3px #f687b360,0 1px 3px rgba(0,0,0,.07)}25%{box-shadow:0 0 10px 3px #63b3ed60,0 1px 3px rgba(0,0,0,.07)}50%{box-shadow:0 0 10px 3px #68d39160,0 1px 3px rgba(0,0,0,.07)}75%{box-shadow:0 0 10px 3px #f6ad5560,0 1px 3px rgba(0,0,0,.07)}}
.pna-card-anni{animation:pna-card-glow 3s ease infinite}
.pna-anni-banner{font-size:12px;font-weight:700;color:#744210;text-align:center;margin-bottom:8px;letter-spacing:.02em}
.pna-class-row{margin-bottom:10px}
.pna-class-row:last-child{margin-bottom:0}
.pna-class-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:4px}
.pna-class-name{font-size:12px;font-weight:600;color:#2d3748}
.pna-class-level{font-size:11px;font-weight:700;color:#276749}
.pna-bar-wrap{height:6px;background:#edf2f7;border-radius:4px;overflow:hidden}
.pna-bar{height:100%;background:linear-gradient(90deg,#48bb78,#276749);border-radius:4px;transition:width .4s ease}
.pna-bar-max{background:linear-gradient(90deg,#f6ad55,#dd6b20)}
.pna-class-credits{font-size:10px;color:#a0aec0;margin-top:3px}
.pna-paragon-dot{color:#b7791f;font-size:10px;margin-left:3px}
.pna-officer-row{display:flex;flex-direction:column;padding:6px 0;border-bottom:1px solid #f7fafc}
.pna-officer-row:last-child{border-bottom:none}
.pna-officer-title{font-size:12px;font-weight:600;color:#2d3748}
.pna-officer-entity{font-size:11px;color:#4299e1;text-decoration:none}
.pna-officer-entity:hover{text-decoration:underline}
.pna-assoc-group{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a0aec0;padding:8px 0 3px;margin-top:4px;border-top:1px solid #edf2f7}.pna-assoc-group:first-child{border-top:none;margin-top:0;padding-top:2px}.pna-feed-row{display:flex;align-items:baseline;gap:8px;padding:5px 0;border-bottom:1px solid #f7fafc;font-size:12.5px}
.pna-feed-row:last-child{border-bottom:none}
.pna-feed-date{flex-shrink:0;color:#a0aec0;font-size:11px;min-width:46px}
.pna-feed-label{flex:1;color:#2d3748;font-weight:500;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pna-feed-label a{color:#2d3748;text-decoration:none}
.pna-feed-label a:hover{text-decoration:underline}
.pna-feed-sub{flex-shrink:0;color:#718096;font-size:11px}
.pna-feed-more{font-size:11px;color:#718096;padding-top:6px;text-align:center}
.pna-congrats-banner{background:linear-gradient(90deg,#fffff0,#fefcbf);border:1px solid #f6e05e;border-radius:6px;padding:9px 13px;font-size:12.5px;font-weight:600;color:#744210;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.pna-sparkline{display:flex;gap:3px;align-items:flex-end;height:34px;margin-bottom:2px}
.pna-spark-week{flex:1;border-radius:2px;min-width:0}
.pna-spark-legend{display:flex;align-items:center;gap:8px;margin-top:7px;font-size:11px;color:#718096;flex-wrap:wrap}
.pna-spark-swatch{width:12px;height:12px;display:inline-block;border-radius:2px;vertical-align:middle}
.pna-spark-on{background:#48bb78}
.pna-spark-off{background:#edf2f7;border:1px solid #e2e8f0}
.pna-spark-swatch-on{background:#48bb78}
.pna-spark-swatch-off{background:#edf2f7;border:1px solid #cbd5e0}
.pna-ev-cols{display:flex;gap:10px}
.pna-ev-col{flex:1;min-width:0}
.pna-ev-col-hdr{font-size:11px;font-weight:700;color:#4a5568;text-transform:uppercase;letter-spacing:.04em;margin-bottom:6px;padding-bottom:4px;border-bottom:1px solid #e2e8f0}
.pna-spark-months{display:flex;gap:3px;margin-top:2px}
.pna-spark-month-lbl{flex:1;font-size:9px;color:#a0aec0;text-align:left;white-space:nowrap;overflow:hidden;min-width:0}
@media(max-width:700px){
.pna-layout{flex-direction:column;align-items:stretch}
.pna-sidebar{flex:none;width:100%}
.pna-ev-cols{flex-direction:column}
.pna-ev-col+.pna-ev-col{margin-top:12px;padding-top:12px;border-top:1px solid #e2e8f0}
.pna-card{padding:12px 13px}
.pna-tenure-years{font-size:36px}
}
@media(max-width:420px){
.pna-feed-sub{display:none}
.pna-spark-month-lbl{font-size:8px}
.pna-card{padding:10px 11px}
.pna-tenure-years{font-size:30px}
.pna-congrats-banner{font-size:11.5px;padding:7px 10px}
}
</style>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- =============================================
     ZONE 1: Profile Hero Header
     ============================================= -->
<div class="pn-hero">
	<div class="pn-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="pn-hero-content">
		<?php if ($canEditImages): ?>
		<div class="pn-avatar pn-editable-img">
			<img class="heraldry-img" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
			<button class="pn-img-edit-btn" onclick="pnOpenImgModal('photo')" title="Update player photo"><i class="fas fa-camera"></i></button>
		</div>
		<?php else: ?>
		<div class="pn-avatar">
			<img class="heraldry-img" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
		</div>
		<?php endif; ?>
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
					<a href="<?= UIR ?>Kingdom/profile/<?= $this->__session->kingdom_id ?>"><?= htmlspecialchars($this->__session->kingdom_name) ?></a>
					<span class="pn-sep"><i class="fas fa-chevron-right" style="font-size:10px"></i></span>
					<a href="<?= UIR ?>Park/profile/<?= $this->__session->park_id ?>"><?= htmlspecialchars($this->__session->park_name) ?></a>
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
				<?php else: ?>
					<span class="pn-badge pn-badge-yellow"><i class="fas fa-exclamation-circle"></i> Needs Waiver</span>
				<?php endif; ?>
				<?php if ($Player['Restricted'] == 1): ?>
					<span class="pn-badge pn-badge-orange"><i class="fas fa-exclamation-triangle"></i> Restricted</span>
				<?php endif; ?>
				<?php if (!empty($Player['DuesThrough'])): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-receipt"></i> Dues Paid</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> Dues Lapsed</span>
				<?php endif; ?>
				<?php if (!empty($OfficerRoles)): ?>
					<?php foreach ($OfficerRoles as $office): ?>
						<span class="pn-badge pn-badge-gold"><i class="fas fa-crown"></i> <?= htmlspecialchars($office['entity_type']) ?> <?= htmlspecialchars($office['role']) ?></span>
					<?php endforeach; ?>
				<?php endif; ?>
				<?php if ($IsOrkAdmin): ?>
					<span class="pn-badge pn-badge-purple"><i class="fas fa-cog"></i> ORK Administrator</span>
				<?php endif; ?>
			</div>
			<?php if ($isSuspended): ?>
				<div class="pn-suspended-detail">
					<i class="fas fa-info-circle"></i>
					Suspended <?= $Player['SuspendedAt'] ?> &mdash; Until <?php $_until = $Player['SuspendedUntil'] ?? ''; echo ($_until && $_until !== '0000-00-00') ? htmlspecialchars($_until) : 'Indefinite'; ?>
					<?php if (!empty($Player['Suspension'])): ?>
						&mdash; <?= htmlspecialchars($Player['Suspension']) ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="pn-hero-actions">
			<?php if ($LoggedIn): ?>
				<button class="pn-btn pn-btn-white" id="pn-recommend-btn"><i class="fas fa-award"></i> Recommend Award</button>

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
		<div class="pn-stat-number pn-stat-text"><?= htmlspecialchars($Stats['LastPlayedClass'] ?: '—') ?></div>
		<div class="pn-stat-label">Last Played</div>
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
			<h4><i class="fas fa-user"></i> Player Details<?php if ($canEditAccount): ?><button class="pn-card-edit-btn" onclick="pnOpenAccountModal()" title="Edit account details"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
			<?php if ($canEditAccount): ?>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Given Name</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['GivenName']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Surname</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Surname']) ?></span>
			</div>
			<?php endif; ?>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Persona</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['Persona']) ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Username</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['UserName']) ?></span>
			</div>
			<div class="pn-detail-row"<?= ($passwordExpired || $passwordSoon) ? ' style="background:#fffbe6;border-left:3px solid #f6ad55;padding-left:6px;margin-left:-6px;"' : '' ?>>
				<span class="pn-detail-label">Password Expires</span>
				<span class="pn-detail-value" style="<?= $passwordExpired ? 'color:#c53030;font-weight:600;' : ($passwordSoon ? 'color:#b7791f;font-weight:600;' : '') ?>"><?= $passwordExpiring ?><?= $passwordSoon ? ' <i class="fas fa-exclamation-triangle" style="margin-left:5px;font-size:12px;" title="Expires within 2 weeks"></i>' : '' ?></span>
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
				<?php if ($canEditImages): ?>
				<div class="pn-editable-img" style="border-radius:4px;max-width:100%;">
					<img class="heraldry-img" src="<?= htmlspecialchars($heraldryUrl) ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain; display: block;" />
					<button class="pn-img-edit-btn" onclick="pnOpenImgModal('heraldry')" title="Update heraldry"><i class="fas fa-camera"></i></button>
				</div>
				<?php else: ?>
				<img class="heraldry-img" src="<?= htmlspecialchars($heraldryUrl) ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain;" />
				<?php endif; ?>
			</div>
		</div>

		<!-- Qualifications -->
		<div class="pn-card">
			<h4><i class="fas fa-certificate"></i> Qualifications<?php if ($canEditAdmin): ?><button class="pn-card-edit-btn" onclick="pnOpenQualModal()" title="Edit qualifications"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Reeve</span>
				<span class="pn-detail-value">
					<?php if ($Player['ReeveQualified'] != 0): ?>
						<?php
							$reeveUntil = (!empty($Player['ReeveQualifiedUntil']) && $Player['ReeveQualifiedUntil'] !== '0000-00-00') ? $Player['ReeveQualifiedUntil'] : '';
							$reeveExpired = $reeveUntil && strtotime($reeveUntil) < time();
						?>
						<?php if (!$reeveUntil): ?>
							<span class="pn-badge pn-badge-green">No end date</span>
						<?php else: ?>
							<span class="pn-badge <?= $reeveExpired ? 'pn-badge-red' : 'pn-badge-green' ?>"><?= $reeveExpired ? 'Expired' : 'Until' ?> <?= $reeveUntil ?></span>
						<?php endif; ?>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Corpora</span>
				<span class="pn-detail-value">
					<?php if ($Player['CorporaQualified'] != 0): ?>
						<?php
							$corporaUntil = (!empty($Player['CorporaQualifiedUntil']) && $Player['CorporaQualifiedUntil'] !== '0000-00-00') ? $Player['CorporaQualifiedUntil'] : '';
							$corporaExpired = $corporaUntil && strtotime($corporaUntil) < time();
						?>
						<?php if (!$corporaUntil): ?>
							<span class="pn-badge pn-badge-green">No end date</span>
						<?php else: ?>
							<span class="pn-badge <?= $corporaExpired ? 'pn-badge-red' : 'pn-badge-green' ?>"><?= $corporaExpired ? 'Expired' : 'Until' ?> <?= $corporaUntil ?></span>
						<?php endif; ?>
					<?php else: ?>
						<span class="pn-badge pn-badge-gray">No</span>
					<?php endif; ?>
				</span>
			</div>
		</div>

		<!-- Dues -->
		<div class="pn-card">
			<h4><i class="fas fa-receipt"></i> Dues<?php if ($canEditAdmin): ?><button class="pn-card-edit-btn" onclick="pnOpenDuesModal()" title="Add dues entry"><i class="fas fa-pencil-alt"></i></button><?php elseif (isset($this->__session->user_id)): ?><button class="pn-card-edit-btn" onclick="pnOpenDuesHistoryModal()" title="View dues history"><i class="fas fa-history"></i></button><?php endif; ?></h4>
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

		<!-- Event RSVPs -->
		<?php if (!empty($UpcomingRsvps)): ?>
		<div class="pn-card">
			<h4><i class="fas fa-calendar-check"></i> Event RSVPs</h4>
			<table class="pn-mini-table">
				<thead>
					<tr>
						<th>Event</th>
						<th>Date</th>
						<?php if (!empty($IsOwnProfile)): ?><th></th><?php endif; ?>
					</tr>
				</thead>
				<tbody>
					<?php foreach ($UpcomingRsvps as $rsvp): ?>
					<tr>
						<td><a href="<?= UIR ?>Event/detail/<?= $rsvp['EventId'] ?>/<?= $rsvp['EventCalendarDetailId'] ?>"><?= htmlspecialchars($rsvp['EventName']) ?></a></td>
						<td><?= date('Y-m-d', strtotime($rsvp['EventStart'])) ?></td>
						<?php if (!empty($IsOwnProfile)): ?>
						<td>
							<form method="post" action="<?= UIR ?>Player/profile/<?= $Player['MundaneId'] ?>" style="margin:0">
								<input type="hidden" name="cancel_rsvp_detail_id" value="<?= $rsvp['EventCalendarDetailId'] ?>">
								<button type="submit" class="pn-btn pn-btn-sm pn-btn-danger">Cancel RSVP</button>
							</form>
						</td>
						<?php endif; ?>
					</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
		</div>
		<?php endif; ?>

		<!-- Companies & Households -->
		<div class="pn-card">
			<h4 style="display:flex;align-items:center;justify-content:space-between;">
				<span><i class="fas fa-users"></i> Companies &amp; Households</span>
				<?php if ($canEditAdmin || $isOwnProfile): ?>
				<button class="pn-card-edit-btn" id="pn-unit-create-btn" title="Create new unit" onclick="pnOpenUnitCreateModal()">
					<i class="fas fa-plus"></i>
				</button>
				<?php endif; ?>
			</h4>
			<?php
				$unitList = (is_array($Units['Units'])) ? $Units['Units'] : array();
			?>
			<?php if (count($unitList) > 0): ?>
				<?php foreach ($unitList as $unit): ?>
					<div class="pn-unit-row">
						<a class="pn-unit-link" href="<?= UIR ?>Unit/index/<?= $unit['UnitId'] ?>&from_player=<?= (int)$Player['MundaneId'] ?>"><?= htmlspecialchars($unit['Name'] ?? '') ?: '(Unnamed)' ?></a>
						<span class="pn-unit-type"><?= ucfirst($unit['Type']) ?></span>
						<?php if ($canEditAdmin || $isOwnProfile): ?>
						<span class="pn-delete-cell pn-unit-quit-cell">
							<a class="pn-delete-link pn-confirm-quit-unit" href="#" title="Leave unit">&times;</a>
							<span class="pn-delete-confirm">
								Leave?&nbsp;
								<button class="pn-delete-yes" data-href="<?= UIR ?>Player/profile/<?= (int)$Player['MundaneId'] ?>/quitunit/<?= $unit['UnitMundaneId'] ?>">Yes</button>
								&nbsp;<button class="pn-delete-no">No</button>
							</span>
						</span>
						<?php endif; ?>
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
				<?php if ($isOwnProfile): ?>
				<li class="pn-tab-active" data-tab="myamtgard">
					<i class="fas fa-home"></i><span class="pn-tab-label"> My Amtgard</span>
				</li>
				<?php endif; ?>
				<li<?= $isOwnProfile ? '' : ' class="pn-tab-active"' ?> data-tab="awards">
					<i class="fas fa-medal"></i><span class="pn-tab-label"> Awards</span> <span class="pn-tab-count">(<?= $Stats['TotalAwards'] ?>)</span>
				</li>
				<li data-tab="titles">
					<i class="fas fa-crown"></i><span class="pn-tab-label"> Titles</span> <span class="pn-tab-count">(<?= $Stats['TotalTitles'] ?>)</span>
				</li>
				<li data-tab="attendance">
					<i class="fas fa-calendar-check"></i><span class="pn-tab-label"> Attendance</span> <span class="pn-tab-count">(<?= $Stats['TotalAttendance'] ?>)</span>
				</li>
				<?php
				$_allRecs  = is_array($AwardRecommendations) ? $AwardRecommendations : [];
				$_myRecs   = array_values(array_filter($_allRecs, function($r) { return $this->__session->user_id == $r['RecommendedById']; }));
				$_recList  = $ShowRecsTab ? $_allRecs : $_myRecs;
				$_showRecs = $ShowRecsTab || count($_myRecs) > 0;
			?>
			<?php if ($_showRecs): ?><li data-tab="recommendations">
					<i class="fas fa-star"></i><span class="pn-tab-label"> Recommendations</span> <span class="pn-tab-count">(<?= count($_recList) ?>)</span>
				</li><?php endif; ?>
				<li data-tab="history">
					<i class="fas fa-sticky-note"></i><span class="pn-tab-label"> Notes</span> <span class="pn-tab-count">(<?= is_array($Notes) ? count($Notes) : 0 ?>)</span>
				</li>
				<li data-tab="classes">
					<i class="fas fa-shield-alt"></i><span class="pn-tab-label"> Class Levels</span>
				</li>
			</ul>
			<div class="pn-active-tab-label" id="pn-active-tab-label"><?= $isOwnProfile ? 'My Amtgard' : 'Awards' ?></div>

			<!-- My Amtgard Tab (own profile default) -->
			<?php if ($isOwnProfile): ?>
			<div class="pn-tab-panel" id="pn-tab-myamtgard">
				<?php
				// Alerts strip
				?>
				<?php if (!empty($_maAlerts)): ?>
				<div class="pna-alerts">
					<?php foreach ($_maAlerts as $_al): ?>
					<div class="pna-alert pna-alert-<?= $_al['type'] ?>">
						<i class="fas <?= $_al['icon'] ?>"></i><span><?= $_al['msg'] ?></span>
					</div>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>

				<div class="pna-layout">

					<!-- Sidebar -->
					<div class="pna-sidebar">

						<!-- Tenure -->
						<?php if ($_maFirstDate): ?>
						<?php
							$_maYears = (int)floor((time() - strtotime($_maFirstDate)) / (365.25 * 86400));
							// Days since last anniversary (show glow for 14 days AFTER)
							$_maAnnivMonth  = (int)date('n', strtotime($_maFirstDate));
							$_maAnnivDay    = (int)date('j', strtotime($_maFirstDate));
							$_maLastAnniv   = mktime(0,0,0, $_maAnnivMonth, $_maAnnivDay, (int)date('Y'));
							if ($_maLastAnniv > strtotime('today')) $_maLastAnniv = mktime(0,0,0, $_maAnnivMonth, $_maAnnivDay, (int)date('Y')-1);
							$_maDaysSince   = (int)floor((strtotime('today') - $_maLastAnniv) / 86400);
							$_maIsAnnivWeek = $_maDaysSince <= 14 && $_maYears >= 1;
							$_maCardCls     = $_maIsAnnivWeek ? ' pna-card-anni' : '';
						?>
						<div class="pna-card<?= $_maCardCls ?>">
							<div class="pna-card-title"><i class="fas fa-birthday-cake"></i> Amtgard Tenure</div>
							<?php if ($_maIsAnnivWeek): ?>
							<div class="pna-anni-banner">🎂 Happy Amt-iversary! 🎂</div>
							<?php endif; ?>
							<div class="pna-tenure">
								<div class="pna-tenure-years"><?= $_maYears >= 1 ? $_maYears : '&lt;1' ?></div>
								<div class="pna-tenure-label">year<?= $_maYears !== 1 ? 's' : '' ?></div>
								<div class="pna-tenure-since">First credit <?= date('M j, Y', strtotime($_maFirstDate)) ?></div>
							</div>
						</div>
						<?php endif; ?>

						<!-- Class Progress -->
						<?php if (!empty($_maClasses)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-shield-alt"></i> Class Progress <a class="pna-card-more" href="#" onclick="pnActivateTab('classes');return false;">All &rarr;</a></div>
							<div style="font-size:11px;color:#a0aec0;margin-bottom:6px;">Your recent classes&hellip;</div>
							<?php foreach ($_maClasses as $_mc):
								$_mcTotal = (int)($_mc['Credits'] ?? 0) + (int)($_mc['Reconciled'] ?? 0);
								$_mcLvl   = _ma_level($_mcTotal);
								$_mcPct   = _ma_progress($_mcTotal);
								$_mcMax   = $_mcTotal >= 53;
								$_mcNext  = [0,5,12,21,34,53][$_mcLvl] ?? 53;
								$_mcPar   = $pnClassToParagon[$_mc['ClassId']] ?? null;
								$_mcHasPar = $_mcPar && isset($pnHeldAwardIds[$_mcPar]);
							?>
							<div class="pna-class-row">
								<div class="pna-class-header">
									<span class="pna-class-name"><?= htmlspecialchars($_mc['ClassName']) ?><?= $_mcHasPar ? ' <span class="pna-paragon-dot" title="Paragon"><i class="fas fa-crown"></i></span>' : '' ?></span>
									<span class="pna-class-level">L<?= $_mcLvl ?><?= $_mcMax ? ' <i class="fas fa-star" style="color:#dd6b20" title="Max level"></i>' : '' ?></span>
								</div>
								<div class="pna-bar-wrap"><div class="pna-bar<?= $_mcMax ? ' pna-bar-max' : '' ?>" style="width:<?= $_mcPct ?>%"></div></div>
								<div class="pna-class-credits"><?= $_mcTotal ?> cr<?= !$_mcMax ? ' &middot; ' . $_mcNext . ' for L' . ($_mcLvl+1) : '' ?></div>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<!-- Officer Roles -->
						<?php if (!empty($OfficerRoles)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-crown"></i> Current Offices</div>
							<?php foreach ($OfficerRoles as $_or): ?>
							<div class="pna-officer-row">
								<span class="pna-officer-title"><?= htmlspecialchars($_or['entity_type'] . ' ' . $_or['role']) ?></span>
								<span class="pna-officer-entity"><?= htmlspecialchars($_or['entity_name'] ?? '') ?></span>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

					</div><!-- /.pna-sidebar -->

					<!-- Feed -->
					<div class="pna-feed">

						<!-- 26-week sparkline -->
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-chart-bar"></i> 26-Week Attendance</div>
							<div class="pna-sparkline" id="pna-sparkline"></div>
							<div class="pna-spark-months" id="pna-spark-months"></div>
							<div class="pna-spark-legend">
								<span class="pna-spark-swatch pna-spark-swatch-on"></span> Attended
								&nbsp;<span class="pna-spark-swatch pna-spark-swatch-off"></span> Not signed in
							</div>
						</div>

						<!-- Recent Sign-ins (60 days) -->
						<?php
						$_ma60 = date('Y-m-d', strtotime('-60 days'));
						$_maRecAtt = array_slice(array_values(array_filter($_maDash_att, function($a) use ($_ma60) {
							return !empty($a['Date']) && $a['Date'] >= $_ma60;
						})), 0, 5);
						?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-calendar-check"></i> Recent Sign-ins <a class="pna-card-more" href="#" onclick="pnActivateTab('attendance');return false;">All <?= $Stats['TotalAttendance'] ?> &rarr;</a></div>
							<?php if (!empty($_maRecAtt)): ?>
							<?php foreach ($_maRecAtt as $_ra): ?>
							<div class="pna-feed-row">
								<span class="pna-feed-date"><?= date('M j', strtotime($_ra['Date'])) ?></span>
								<span class="pna-feed-label"><?= htmlspecialchars($_ra['ClassName'] ?? '—') ?></span>
								<?php if (!empty($_ra['ParkName'])): ?><span class="pna-feed-sub"><?= htmlspecialchars($_ra['ParkName']) ?></span><?php endif; ?>
							</div>
							<?php endforeach; ?>
							<?php else: ?>
							<div style="font-size:12px;color:#718096;line-height:1.5;">
								We've missed you! Check out the next events and park days in your
								<a href="<?= UIR ?>Kingdom/profile/<?= (int)($KingdomId ?? $this->__session->kingdom_id) ?>" style="color:#4299e1;">kingdom</a>.
							</div>
							<?php endif; ?>
						</div>

						<!-- Recent Awards (60 days) -->
						<?php
						$_ma60awd = date('Y-m-d', strtotime('-60 days'));
						$_maRecAwd = array_slice(array_values(array_filter($_maDash_awd, function($a) use ($_ma60awd) {
							return !$a['IsTitle'] && !empty($a['Date']) && $a['Date'] >= $_ma60awd;
						})), 0, 5);
						?>
						<?php if (!empty($_maRecAwd)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-medal"></i> Recent Awards <a class="pna-card-more" href="#" onclick="pnActivateTab('awards');return false;">All <?= $Stats['TotalAwards'] ?> &rarr;</a></div>
							<div class="pna-congrats-banner"><i class="fas fa-trophy"></i> Congratulations on your recent awards!</div>
							<?php foreach ($_maRecAwd as $_aw): ?>
							<div class="pna-feed-row">
								<span class="pna-feed-date"><?= date('M j, Y', strtotime($_aw['Date'])) ?></span>
								<?php $_awName = trimlen($_aw['CustomAwardName'] ?? '') > 0 ? $_aw['CustomAwardName'] : (trimlen($_aw['KingdomAwardName'] ?? '') > 0 ? $_aw['KingdomAwardName'] : ($_aw['Name'] ?? '—')); ?>
								<span class="pna-feed-label"><?= htmlspecialchars($_awName) ?></span>
								<?php if (!empty($_aw['GivenBy'])): ?><span class="pna-feed-sub">by <?= htmlspecialchars($_aw['GivenBy']) ?></span><?php endif; ?>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

						<!-- Upcoming Events: two-column -->
						<?php if (!empty($UpcomingRsvps) || !empty($KingdomEvents)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-ticket-alt"></i> Upcoming Events</div>
							<div class="pna-ev-cols">
								<div class="pna-ev-col">
									<div class="pna-ev-col-hdr">My RSVPs</div>
									<?php if (!empty($UpcomingRsvps)): ?>
									<?php foreach (array_slice($UpcomingRsvps, 0, 4) as $_rv): ?>
									<div class="pna-feed-row">
										<span class="pna-feed-date"><?= date('M j', strtotime($_rv['EventStart'])) ?></span>
										<span class="pna-feed-label"><a href="<?= UIR ?>Event/detail/<?= $_rv['EventId'] ?>/<?= $_rv['EventCalendarDetailId'] ?>"><?= htmlspecialchars($_rv['EventName']) ?></a></span>
									</div>
									<?php endforeach; ?>
									<?php else: ?>
									<div style="font-size:11px;color:#a0aec0;">No upcoming RSVPs.</div>
									<?php endif; ?>
								</div>
								<div class="pna-ev-col">
									<div class="pna-ev-col-hdr">Events to Check Out</div>
									<?php if (!empty($KingdomEvents)): ?>
									<?php foreach (array_slice($KingdomEvents, 0, 4) as $_ke): ?>
									<div class="pna-feed-row">
										<span class="pna-feed-date"><?= date('M j', strtotime($_ke['EventStart'])) ?></span>
										<span class="pna-feed-label"><a href="<?= UIR ?>Event/detail/<?= $_ke['EventId'] ?>/<?= $_ke['EventCalendarDetailId'] ?>"><?= htmlspecialchars($_ke['EventName']) ?></a></span>
									</div>
									<?php endforeach; ?>
									<?php else: ?>
									<div style="font-size:11px;color:#a0aec0;">No other upcoming events.</div>
									<?php endif; ?>
								</div>
							</div>
						</div>
						<?php endif; ?>


						<!-- My Associates -->
						<?php if (!empty($MyAssociates)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-user-friends"></i> My Associates</div>
							<?php
							$_maCurPeerage = null;
							$_maPeerageLabels = ['Squire' => 'Squires', 'Man-At-Arms' => 'Men/Women-at-Arms', 'Lords-Page' => 'Lords-Pages', 'Page' => 'Pages'];
							?>
							<?php foreach ($MyAssociates as $_as): ?>
							<?php if ($_as['Peerage'] !== $_maCurPeerage): ?>
							<div class="pna-assoc-group"><?= htmlspecialchars($_maPeerageLabels[$_as['Peerage']] ?? $_as['Peerage']) ?></div>
							<?php $_maCurPeerage = $_as['Peerage']; endif; ?>
							<div class="pna-feed-row">
								<span class="pna-feed-label"><a href="<?= UIR ?>Player/profile/<?= (int)$_as['RecipientId'] ?>"><?= htmlspecialchars($_as['Persona']) ?></a></span>
								<span class="pna-feed-sub"><?= htmlspecialchars($_as['TitleName']) ?></span>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>

					</div><!-- /.pna-feed -->
				</div><!-- /.pna-layout -->
			</div><!-- /#pn-tab-myamtgard -->
			<?php endif; // isOwnProfile ?>

			<!-- Awards Tab -->
			<div class="pn-tab-panel" id="pn-tab-awards"<?= $isOwnProfile ? ' style="display:none"' : '' ?>>
				<?php
					$awardsList = is_array($Details['Awards']) ? $Details['Awards'] : array();
				?>
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('awards')"><i class="fas fa-plus"></i> Add Award</button>
					<?php if ($hasHistorical): ?>
				<a href="<?= UIR ?>Player/reconcile/<?= (int)$Player['MundaneId'] ?>"
				   class="pn-btn pn-btn-sm" style="background:#6b46c1;color:#fff;margin-left:8px">
					<i class="fas fa-history"></i> Reconcile Historical Awards
				</a>
				<?php endif; ?>
				<?php if (!empty($awardsList)): ?>
				<button class="pn-btn pn-btn-sm" style="background:#c53030;color:#fff;margin-left:8px" onclick="pnOpenRevokeAllModal()"><i class="fas fa-ban"></i> Revoke All</button>
				<?php endif; ?>
				</div>
				<?php endif; ?>
				<?php
					$filteredAwards = array();
					foreach ($awardsList as $a) {
						if (in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1) {
							$filteredAwards[] = $a;
						}
					}

					// Build ladder progress: AwardId -> {Name, Short, MaxRank, HasMaster}
					// Static map: Order award_id => Master award_id(s)
					$pnOrderToMaster = [
						21  => [1],       // Order of the Rose      → Master Rose
						22  => [2],       // Order of the Smith      → Master Smith
						23  => [3],       // Order of the Lion       → Master Lion
						24  => [4],       // Order of the Owl        → Master Owl
						25  => [5],       // Order of the Dragon     → Master Dragon
						26  => [6],       // Order of the Garber     → Master Garber
						27  => [36, 12],  // Order of the Warrior    → Weaponmaster / Warlord
						28  => [7],       // Order of the Jovius     → Master Jovius
						29  => [9],       // Order of the Mask       → Master Mask
						30  => [8],       // Order of the Zodiac     → Master Zodiac
						32  => [10],      // Order of the Hydra      → Master Hydra
						33  => [11],      // Order of the Griffin    → Master Griffin
						239 => [240],     // Order of the Crown      → Master Crown
						243 => [244],     // Order of Battle         → Battlemaster
					];
					// Index all award_ids the player holds (including titles)
					$pnHeldAwardIds = [];
					foreach ($awardsList as $a) {
						$aid = (int)$a['AwardId'];
						if ($aid > 0) $pnHeldAwardIds[$aid] = true;
					}
					$pnLadderProgress = [];
					foreach ($awardsList as $a) {
						if ((int)$a['IsLadder'] !== 1) continue;
						$aid  = (int)$a['AwardId'];
						$rank = (int)$a['Rank'];
						if ($aid <= 0 || $rank <= 0) continue;
						$displayName = trimlen($a['CustomAwardName']) > 0 ? $a['CustomAwardName']
							: (trimlen($a['KingdomAwardName']) > 0 ? $a['KingdomAwardName'] : $a['Name']);
						// Strip "Order of the " / "Order of " prefix to save space
						$shortName = preg_replace('/^Order of (the )?/i', '', $displayName);
						// Check if player holds the corresponding Master title
						$hasMaster = false;
						if (isset($pnOrderToMaster[$aid])) {
							foreach ($pnOrderToMaster[$aid] as $masterId) {
								if (isset($pnHeldAwardIds[$masterId])) { $hasMaster = true; break; }
							}
						}
						if (!isset($pnLadderProgress[$aid]) || $rank > $pnLadderProgress[$aid]['Rank']) {
							$pnLadderProgress[$aid] = ['Name' => $displayName, 'Short' => $shortName, 'Rank' => $rank, 'HasMaster' => $hasMaster];
						}
					}
					uasort($pnLadderProgress, function($a, $b) { return strcmp($a['Name'], $b['Name']); });
				?>
				<?php if (!empty($pnLadderProgress)): ?>
					<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:16px;">
						<div class="pn-ladder-grid" style="flex:1;min-width:0;margin-bottom:0">
							<?php foreach ($pnLadderProgress as $aid => $lp): ?>
								<?php $maxRank = ($aid === 30) ? 12 : 10; ?>
								<?php $pct = min(100, round($lp['Rank'] / $maxRank * 100)); ?>
								<div class="pn-ladder-item" title="<?= htmlspecialchars($lp['Name']) ?>" data-ladname="<?= htmlspecialchars($lp['Name']) ?>" style="cursor:pointer">
									<div class="pn-ladder-header">
										<span class="pn-ladder-name"><?= htmlspecialchars($lp['Short']) ?></span>
										<span style="display:flex;align-items:center;gap:4px;flex-shrink:0">
											<?php if ($lp['HasMaster']): ?>
												<span class="pn-ladder-master" title="Master title earned"><i class="fas fa-star"></i> M</span>
											<?php endif; ?>
											<span class="pn-ladder-rank"><strong><?= $lp['Rank'] ?></strong> / <?= $maxRank ?></span>
										</span>
									</div>
									<div class="pn-ladder-bar-track">
										<div class="pn-ladder-bar-fill<?= $lp['Rank'] >= $maxRank ? ' pn-ladder-max' : '' ?>"
										     style="width:<?= $pct ?>%"></div>
									</div>
								</div>
							<?php endforeach; ?>
						</div>
						<?php if ($hasHistoricalTip): ?>
						<div class="pn-hist-tip-btn" tabindex="0" role="button" aria-label="Historical awards info">
							<i class="fas fa-exclamation-triangle"></i>
							<div class="pn-hist-tip-text"><?php if ($isOwnProfile): ?>Should these numbers look different? You have historically imported awards that need to be reconciled! Contact your Monarch or Prime Minister and ask them to use the Reconcile Historical Awards tool on your legacy awards.<?php else: ?>This player has historically imported awards that may not be fully reconciled. Progress bars may not reflect their complete award history.<?php endif; ?></div>
						</div>
						<?php endif; ?>
					</div>
				<?php endif; ?>
				<?php if (count($filteredAwards) === 0): ?>
					<div class="pn-empty">No awards recorded</div>
				<?php else: ?>
				<div class="pn-award-search-bar">
					<i class="fas fa-search pn-award-search-icon"></i>
					<input type="text" id="pn-award-search" placeholder="Search awards…" class="pn-award-search-input" autocomplete="off" oninput="pnAwardSearch(this.value)" />
				</div>
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
							<?php if ($canEditAdmin): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($filteredAwards as $detail): ?>
							<tr>
								<td class="pn-col-nowrap">
									<?php $displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName']; ?>
									<?= $displayName ?>
									<?php if ($displayName != $detail['Name']): ?><span class="pn-award-base">[<?= $detail['Name'] ?>]</span><?php endif; ?>
								</td>
								<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/profile/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
								<td><?php if (valid_id($detail['EventId'])) echo $detail['EventName']; elseif (trimlen($detail['ParkName']) > 0) echo $detail['ParkName'] . (trimlen($detail['KingdomName']) > 0 ? ', ' . $detail['KingdomName'] : ''); else echo $detail['KingdomName']; ?></td>
								<td><?= $detail['Note'] ?></td>
								<td><a href="<?= UIR ?>Player/profile/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								<?php if ($canEditAdmin): ?>
								<td class="pn-award-actions-cell">
									<?php $awardData = json_encode([
										'AwardsId'      => (int)$detail['AwardsId'],
										'displayName'   => ($detail['CustomAwardName'] !== '' ? $detail['CustomAwardName'] : $detail['KingdomAwardName']),
										'Name'          => $detail['Name'],
										'IsLadder'      => (int)$detail['IsLadder'],
										'IsHistorical'  => (int)($detail['IsHistorical'] ?? 0),
										'KingdomAwardId'=> (int)$detail['KingdomAwardId'],
										'Rank'          => (int)$detail['Rank'],
										'Date'       => $detail['Date'],
										'GivenBy'    => $detail['GivenBy'],
										'GivenById'  => (int)$detail['GivenById'],
										'Note'       => $detail['Note'],
										'ParkId'     => (int)$detail['ParkId'],
										'ParkName'   => $detail['ParkName'],
										'KingdomId'  => (int)$detail['KingdomId'],
										'KingdomName'=> $detail['KingdomName'],
										'EventId'    => (int)$detail['EventId'],
										'EventName'  => $detail['EventName'],
									], JSON_HEX_QUOT | JSON_HEX_APOS); ?>
									<button class="pn-award-action-btn pn-award-edit-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        data-award="<?= htmlspecialchars($awardData, ENT_QUOTES) ?>"
									        title="Edit award"><i class="fas fa-pencil-alt"></i></button>
									<button class="pn-award-action-btn pn-award-del-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        title="Delete award"><i class="fas fa-trash"></i></button>
									<button class="pn-award-action-btn pn-award-revoke-btn"
									        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
									        data-award="<?= htmlspecialchars($awardData, ENT_QUOTES) ?>"
									        title="Revoke award"><i class="fas fa-ban"></i></button>
								</td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<div id="pn-award-search-empty" class="pn-empty" style="display:none">No awards match your search</div>
				<?php endif; ?>
				<?php if ($canEditAdmin && !empty($RevokedAwards)): ?>
				<div class="pn-revoked-section">
					<h4 class="pn-revoked-heading"><i class="fas fa-ban"></i> Revoked Awards</h4>
					<table class="pn-table pn-sortable" id="pn-revoked-awards-table">
						<thead>
							<tr>
								<th data-sorttype="text">Award</th>
								<th data-sorttype="numeric">Rank</th>
								<th data-sorttype="date">Date Given</th>
								<th data-sorttype="date">Revoked On</th>
								<th data-sorttype="text">Revoked By</th>
								<th data-sorttype="text">Reason</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($RevokedAwards as $rev): ?>
							<tr>
								<td class="pn-col-nowrap"><?= htmlspecialchars($rev['AwardName'] ?? '') ?></td>
								<td class="pn-col-numeric"><?= valid_id($rev['Rank']) ? (int)$rev['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($rev['Date']) > 0 ? $rev['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><?= ($rev['RevokedAt'] && $rev['RevokedAt'] !== '0000-00-00') ? $rev['RevokedAt'] : '' ?></td>
								<td class="pn-col-nowrap"><?= htmlspecialchars($rev['RevokedBy'] ?? '') ?></td>
								<td><?= htmlspecialchars($rev['Revocation'] ?? '') ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<!-- Titles Tab -->
			<div class="pn-tab-panel" id="pn-tab-titles" style="display:none">
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('officers')"><i class="fas fa-plus"></i> Add Title</button>
				</div>
				<?php endif; ?>
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
								<?php if ($canEditAdmin): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
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
									<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/profile/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
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
									<td><a href="<?= UIR ?>Player/profile/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
									<?php if ($canEditAdmin): ?>
									<td class="pn-award-actions-cell">
										<?php $titleData = json_encode([
											'AwardsId'       => (int)$detail['AwardsId'],
											'displayName'    => ($detail['CustomAwardName'] !== '' ? $detail['CustomAwardName'] : $detail['KingdomAwardName']),
											'Name'           => $detail['Name'],
											'IsLadder'       => (int)$detail['IsLadder'],
											'IsTitle'        => 1,
											'IsHistorical'   => (int)($detail['IsHistorical'] ?? 0),
											'KingdomAwardId' => (int)$detail['KingdomAwardId'],
											'Rank'           => (int)$detail['Rank'],
											'Date'           => $detail['Date'],
											'GivenBy'        => $detail['GivenBy'],
											'GivenById'      => (int)$detail['GivenById'],
											'Note'           => $detail['Note'],
											'ParkId'         => (int)$detail['ParkId'],
											'ParkName'       => $detail['ParkName'],
											'KingdomId'      => (int)$detail['KingdomId'],
											'KingdomName'    => $detail['KingdomName'],
											'EventId'        => (int)$detail['EventId'],
											'EventName'      => $detail['EventName'],
										], JSON_HEX_QUOT | JSON_HEX_APOS); ?>
										<button class="pn-award-action-btn pn-award-edit-btn"
										        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
										        data-award="<?= htmlspecialchars($titleData, ENT_QUOTES) ?>"
										        title="Edit title"><i class="fas fa-pencil-alt"></i></button>
										<button class="pn-award-action-btn pn-award-del-btn"
										        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
										        title="Delete title"><i class="fas fa-trash"></i></button>
										<button class="pn-award-action-btn pn-award-revoke-btn"
										        data-awards-id="<?= (int)$detail['AwardsId'] ?>"
										        data-award="<?= htmlspecialchars($titleData, ENT_QUOTES) ?>"
										        title="Revoke title"><i class="fas fa-ban"></i></button>
									</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No titles recorded</div>
				<?php endif; ?>
				<?php if ($canEditAdmin && !empty($RevokedTitles)): ?>
				<div class="pn-revoked-section">
					<h4 class="pn-revoked-heading"><i class="fas fa-ban"></i> Revoked Titles</h4>
					<table class="pn-table pn-sortable" id="pn-revoked-titles-table">
						<thead>
							<tr>
								<th data-sorttype="text">Title</th>
								<th data-sorttype="numeric">Rank</th>
								<th data-sorttype="date">Date Given</th>
								<th data-sorttype="date">Revoked On</th>
								<th data-sorttype="text">Revoked By</th>
								<th data-sorttype="text">Reason</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($RevokedTitles as $rev): ?>
							<tr>
								<td class="pn-col-nowrap"><?= htmlspecialchars($rev['AwardName'] ?? '') ?></td>
								<td class="pn-col-numeric"><?= valid_id($rev['Rank']) ? (int)$rev['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($rev['Date']) > 0 ? $rev['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><?= ($rev['RevokedAt'] && $rev['RevokedAt'] !== '0000-00-00') ? $rev['RevokedAt'] : '' ?></td>
								<td class="pn-col-nowrap"><?= htmlspecialchars($rev['RevokedBy'] ?? '') ?></td>
								<td><?= htmlspecialchars($rev['Revocation'] ?? '') ?></td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<!-- Attendance Tab -->
			<div class="pn-tab-panel" id="pn-tab-attendance" style="display:none">
				<?php $attendanceList = is_array($Details['Attendance']) ? $Details['Attendance'] : array(); ?>
				<?php if ($canEditAdmin): ?>
				<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
					<button class="pn-btn pn-btn-primary" onclick="pnOpenPlayerAttModal()"><i class="fas fa-plus"></i> Add Attendance</button>
				</div>
				<?php endif; ?>
				<?php if (count($attendanceList) > 0): ?>
					<div class="pn-pagesize-bar">
						<label for="pn-attendance-pagesize">Show</label>
						<select id="pn-attendance-pagesize" class="pn-pagesize-select" onchange="pnSetPageSize('pn-attendance-table', this.value)">
							<option value="10">10</option>
							<option value="25">25</option>
							<option value="50">50</option>
							<option value="100">100</option>
						</select>
						<span>per page</span>
					</div>
					<table class="pn-table pn-sortable" id="pn-attendance-table">
						<thead>
							<tr>
								<th data-sorttype="date">Date</th>
								<th data-sorttype="text">Kingdom</th>
								<th data-sorttype="text">Park</th>
								<th data-sorttype="text">Event</th>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric">Credits</th>
								<?php if ($canEditAdmin): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($attendanceList as $detail): ?>
								<tr>
									<td class="pn-col-nowrap">
										<?php if ($detail['ParkId'] > 0): ?>
											<a href="<?= UIR ?>Attendance/park/<?= $detail['ParkId'] ?>&AttendanceDate=<?= $detail['Date'] ?>"><?= $detail['Date'] ?></a>
										<?php else: ?>
											<a href="<?= UIR ?>Event/detail/<?= $detail['EventId'] ?>/<?= $detail['EventCalendarDetailId'] ?>"><?= $detail['Date'] ?></a>
										<?php endif; ?>
									</td>
									<td><a href="<?= UIR ?>Kingdom/profile/<?= $detail['KingdomId'] ?>"><?= $detail['KingdomName'] ?></a></td>
									<td><a href="<?= UIR ?>Park/profile/<?= $detail['ParkId'] ?>"><?= $detail['ParkName'] ?></a></td>
									<td><a href="<?= UIR ?>Event/detail/<?= $detail['EventId'] ?>/<?= $detail['EventCalendarDetailId'] ?>"><?= $detail['EventName'] ?></a></td>
									<td><?= trimlen($detail['Flavor']) > 0 ? $detail['Flavor'] : $detail['ClassName'] ?></td>
									<td class="pn-col-numeric"><?= $detail['Credits'] ?></td>
									<?php if ($canEditAdmin): ?>
									<td class="pn-award-actions-cell">
										<?php if ((int)$detail['EventId'] === 0): ?>
										<button class="pn-award-action-btn pn-award-edit-btn pn-att-edit-btn"
										        data-att-id="<?= (int)$detail['AttendanceId'] ?>"
										        data-date="<?= htmlspecialchars($detail['Date']) ?>"
										        data-credits="<?= (float)$detail['Credits'] ?>"
										        data-class-id="<?= (int)$detail['ClassId'] ?>"
										        data-mundane-id="<?= (int)$detail['MundaneId'] ?>"
										        title="Edit attendance"><i class="fas fa-pencil-alt"></i></button>
										<button class="pn-award-action-btn pn-award-del-btn pn-att-del-btn"
										        data-att-id="<?= (int)$detail['AttendanceId'] ?>"
										        title="Delete attendance"><i class="fas fa-trash"></i></button>
										<?php endif; ?>
									</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty">No attendance records</div>
				<?php endif; ?>
			</div>

			<!-- Recommendations Tab -->
			<?php if ($_showRecs): ?><div class="pn-tab-panel" id="pn-tab-recommendations" style="display:none">
				<?php if ($this->__session->user_id): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenModal()"><i class="fas fa-plus"></i> Recommend an Award</button>
				</div>
				<?php endif; ?>
				<?php if (count($_recList) > 0): ?>
					<table class="pn-table display" id="pn-rec-table">
						<thead>
							<tr>
								<th>Award</th>
								<th>Rank</th>
								<th>Date</th>
								<th>Sent By</th>
								<th>Reason</th>
								<?php if ($this->__session->user_id): ?>
									<th style="white-space:nowrap;width:1%">Actions</th>
								<?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($_recList as $rec): ?>
								<tr>
									<td><?= $rec['AwardName'] ?></td>
									<td class="pn-col-numeric"><?= valid_id($rec['Rank']) ? $rec['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= $rec['DateRecommended'] ?></td>
									<td><a href="<?= UIR ?>Player/profile/<?= $rec['RecommendedById'] ?>"><?= $rec['RecommendedByName'] ?></a></td>
									<td><?= htmlspecialchars($rec['Reason']) ?></td>
									<?php if ($this->__session->user_id): ?>
										<td style="white-space:nowrap">
											<?php if ($canEditAdmin && valid_id($rec['KingdomAwardId'] ?? 0)): ?>
												<a class="pn-rec-give-link" href="#"
													data-rec="<?= htmlspecialchars(json_encode(['KingdomAwardId' => (int)($rec['KingdomAwardId'] ?? 0), 'Rank' => (int)($rec['Rank'] ?? 0), 'Reason' => $rec['Reason'] ?? '', 'AwardName' => $rec['AwardName'] ?? '']), ENT_QUOTES) ?>"
												><i class="fas fa-plus"></i> Give</a>
											<?php endif; ?>
											<?php if ($can_delete_recommendation || $this->__session->user_id == $rec['RecommendedById'] || $this->__session->user_id == $rec['MundaneId']): ?>
												<span class="pn-delete-cell">
												<a class="pn-delete-link pn-confirm-delete-rec" href="#"><i class="fas fa-trash-alt"></i> Delete</a>
												<span class="pn-delete-confirm">
													Delete?&nbsp;
													<button class="pn-delete-yes" data-href="<?= UIR ?>Player/profile/<?= $rec['MundaneId'] ?>/deleterecommendation/<?= $rec['RecommendationsId'] ?>">Yes</button>
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
					<div class="pn-empty">There are no open award recommendations for <?= htmlspecialchars($Player["Persona"]) ?>.</div>
				<?php endif; ?>
			</div><?php endif; ?>

			<!-- Notes Tab -->
			<div class="pn-tab-panel" id="pn-tab-history" style="display:none">
				<?php $notesList = is_array($Notes) ? $Notes : array(); ?>
				<?php if ($canEditAdmin): ?>
				<div class="pn-notes-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAddNoteModal()"><i class="fas fa-plus"></i> Add Note</button>
				</div>
				<?php endif; ?>
				<?php if (count($notesList) > 0): ?>
					<table class="pn-table" id="pn-history-table">
						<thead>
							<tr>
								<th>Note</th>
								<th>Description</th>
								<th>Date</th>
								<?php if ($canEditAdmin): ?><th style="width:60px"></th><?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($notesList as $note): ?>
								<tr data-notes-id="<?= (int)($note['NoteId'] ?? 0) ?>">
									<td><?= $note['Note'] ?></td>
									<td><?= $note['Description'] ?></td>
									<td class="pn-col-nowrap"><?= $note['Date'] . (strtotime($note['DateComplete']) > 0 ? (' - ' . $note['DateComplete']) : '') ?></td>
									<?php if ($canEditAdmin): ?>
									<td class="pn-award-actions-cell">
										<button class="pn-award-action-btn pn-award-edit-btn pn-note-edit-btn"
											data-notes-id="<?= (int)($note['NoteId'] ?? 0) ?>"
											data-note="<?= htmlspecialchars($note['Note'] ?? '', ENT_QUOTES) ?>"
											data-desc="<?= htmlspecialchars($note['Description'] ?? '', ENT_QUOTES) ?>"
											data-date="<?= htmlspecialchars($note['Date'] ?? '', ENT_QUOTES) ?>"
											data-date-complete="<?= htmlspecialchars($note['DateComplete'] ?? '', ENT_QUOTES) ?>"
											title="Edit note"><i class="fas fa-pencil-alt"></i></button>
										<button class="pn-award-action-btn pn-award-del-btn pn-note-del-btn" data-notes-id="<?= (int)($note['NoteId'] ?? 0) ?>" title="Delete note"><i class="fas fa-trash"></i></button>
									</td>
									<?php endif; ?>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php else: ?>
					<div class="pn-empty" id="pn-history-empty">No notes</div>
				<?php endif; ?>
			</div>

			<!-- Class Levels Tab -->
			<div class="pn-tab-panel" id="pn-tab-classes" style="display:none">
				<?php
					$classList = is_array($Details['Classes']) ? $Details['Classes'] : array();
					// class_id → Paragon award_id
					// $pnClassToParagon and $pnHeldAwardIds are pre-computed in the template preamble
				?>
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-sm pn-btn-secondary" onclick="pnOpenReconcileModal()"><i class="fas fa-sliders-h"></i> Edit Reconciliation</button>
				</div>
				<?php endif; ?>
				<?php if (count($classList) > 0): ?>
					<table class="pn-table" id="pn-classes-table">
						<thead>
							<tr>
								<th data-sorttype="text">Class</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Credits</th>
								<th data-sorttype="numeric" class="pn-col-numeric">Level</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($classList as $detail): ?>
								<?php
									$totalCredits = $detail['Credits'] + (isset($Player_index) ? $Player_index['Class_' . $detail['ClassId']] : $detail['Reconciled']);
									$paragonAwardId = $pnClassToParagon[$detail['ClassId']] ?? null;
									$hasParagon = $paragonAwardId && isset($pnHeldAwardIds[$paragonAwardId]);
								?>
								<tr>
									<td>
										<?= htmlspecialchars($detail['ClassName']) ?>
										<?php if ($hasParagon): ?>
											<span class="pn-paragon-badge" title="Paragon title earned"><i class="fas fa-crown"></i> Paragon</span>
										<?php endif; ?>
									</td>
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
     Image Upload Modal
     ============================================= -->
<?php if ($canEditImages): ?>
<div class="pn-overlay" id="pn-img-overlay">
	<div class="pn-modal-box pn-img-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title" id="pn-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Image</h3>
			<button class="pn-modal-close-btn" id="pn-img-close-btn" aria-label="Close">&times;</button>
		</div>

		<!-- Step: file select -->
		<div class="pn-modal-body" id="pn-img-step-select">
			<label class="pn-upload-area" for="pn-img-file-input">
				<i class="fas fa-cloud-upload-alt pn-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Max 340&nbsp;KB (larger images auto-resized)</small>
			</label>
			<input type="file" id="pn-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none;" />
			<div id="pn-img-resize-notice" style="font-size:12px;color:#888;min-height:16px;"></div>
			<div class="pn-form-error" id="pn-img-error"></div>
			<div style="text-align:center;margin-top:10px">
				<button class="pn-btn" id="pn-img-remove-btn" type="button" style="background:transparent;color:#e53e3e;border:1px solid #feb2b2;font-size:12px;padding:4px 14px;"><i class="fas fa-trash"></i> <span id="pn-img-remove-label">Remove Image</span></button>
			</div>
		</div>

		<!-- Step: crop -->
		<div class="pn-modal-body" id="pn-img-step-crop" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#718096;">Drag inside the crop box to reposition it, or drag the corner handles to resize.</p>
			<div class="pn-crop-wrap">
				<canvas id="pn-crop-canvas"></canvas>
			</div>
			<div class="pn-img-step-actions">
				<button class="pn-btn pn-btn-secondary" id="pn-img-back-btn"><i class="fas fa-arrow-left"></i> Choose Different</button>
				<button class="pn-btn pn-btn-primary" id="pn-img-upload-btn"><i class="fas fa-upload"></i> Upload</button>
			</div>
		</div>

		<!-- Step: uploading -->
		<div class="pn-modal-body" id="pn-img-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#718096;">Uploading&hellip;</p>
		</div>

		<!-- Step: success -->
		<div class="pn-modal-body" id="pn-img-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Image updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Update Account Modal
     ============================================= -->
<?php if ($canEditAccount): ?>
<div class="pn-overlay" id="pn-acct-overlay">
	<div class="pn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-user-edit" style="margin-right:8px;color:#2c5282"></i>Update Account</h3>
			<button class="pn-modal-close-btn" id="pn-acct-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-acct-error"></div>

			<!-- Basic profile (own + admin) -->
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label for="pn-acct-givenname">Given Name</label>
					<input type="text" id="pn-acct-givenname" name="GivenName" value="<?= htmlspecialchars($Player['GivenName']) ?>" />
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-surname">Surname</label>
					<input type="text" id="pn-acct-surname" name="Surname" value="<?= htmlspecialchars($Player['Surname']) ?>" />
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-persona">Persona <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-acct-persona" name="Persona" value="<?= htmlspecialchars($Player['Persona']) ?>" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-email">Email</label>
				<input type="email" id="pn-acct-email" name="Email" value="<?= htmlspecialchars($Player['Email'] ?? '') ?>" />
				<div id="pn-acct-email-warn" style="display:none;color:#e53e3e;font-size:0.82rem;margin-top:4px;">Double check the format of your email address.</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-username">Username <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-acct-username" name="UserName" value="<?= htmlspecialchars($Player['UserName']) ?>" />
			</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label for="pn-acct-password">New Password</label>
					<input type="password" id="pn-acct-password" name="Password" autocomplete="new-password" />
					<div class="pn-acct-hint">Leave blank to keep current</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-password2">Confirm Password</label>
					<input type="password" id="pn-acct-password2" name="PasswordAgain" autocomplete="new-password" />
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-pronouns">Pronouns</label>
				<div class="pronoun-row">
					<select id="pn-acct-pronouns" name="PronounId">
						<option value="">None / unspecified</option>
						<?= $PronounOptions ?>
					</select>
					<button type="button" class="pronoun-custom-btn" id="pn-pronoun-custom-btn"><i class="fas fa-sliders-h"></i> Custom&hellip;</button>
				</div>
				<input type="hidden" name="PronounCustom" id="pn-pronoun-custom-val" value="<?= htmlspecialchars($Player['PronounCustom'] ?? '') ?>" />
				<div class="pronoun-picker-panel" id="pn-pronoun-picker" style="display:none">
					<div class="pronoun-picker-preview" id="pn-pronoun-preview"></div>
					<div class="pronoun-picker-grid">
						<div class="pronoun-picker-col">
							<label>Subjective</label>
							<select multiple id="pn-p-subject" size="4">
								<?php if (!empty($PronounList['subjective'])): foreach ($PronounList['subjective'] as $p): ?><option value="<?= (int)$p['value'] ?>"><?= htmlspecialchars($p['display']) ?></option><?php endforeach; endif; ?>
							</select>
						</div>
						<div class="pronoun-picker-col">
							<label>Objective</label>
							<select multiple id="pn-p-object" size="4">
								<?php if (!empty($PronounList['objective'])): foreach ($PronounList['objective'] as $p): ?><option value="<?= (int)$p['value'] ?>"><?= htmlspecialchars($p['display']) ?></option><?php endforeach; endif; ?>
							</select>
						</div>
						<div class="pronoun-picker-col">
							<label>Possessive</label>
							<select multiple id="pn-p-possessive" size="4">
								<?php if (!empty($PronounList['possessive'])): foreach ($PronounList['possessive'] as $p): ?><option value="<?= (int)$p['value'] ?>"><?= htmlspecialchars($p['display']) ?></option><?php endforeach; endif; ?>
							</select>
						</div>
						<div class="pronoun-picker-col">
							<label>Poss.&nbsp;Pronoun</label>
							<select multiple id="pn-p-possessivepronoun" size="4">
								<?php if (!empty($PronounList['possessivepronoun'])): foreach ($PronounList['possessivepronoun'] as $p): ?><option value="<?= (int)$p['value'] ?>"><?= htmlspecialchars($p['display']) ?></option><?php endforeach; endif; ?>
							</select>
						</div>
						<div class="pronoun-picker-col">
							<label>Reflexive</label>
							<select multiple id="pn-p-reflexive" size="4">
								<?php if (!empty($PronounList['reflexive'])): foreach ($PronounList['reflexive'] as $p): ?><option value="<?= (int)$p['value'] ?>"><?= htmlspecialchars($p['display']) ?></option><?php endforeach; endif; ?>
							</select>
						</div>
					</div>
					<div class="pronoun-picker-actions">
						<button type="button" class="pronoun-clear-btn" id="pn-pronoun-clear">Clear</button>
						<button type="button" class="pronoun-apply-btn" id="pn-pronoun-apply">Apply</button>
					</div>
				</div>
			</div>

			<?php if ($canEditAdmin): ?>
			<!-- Admin-only fields -->
			<div class="pn-acct-section-title"><i class="fas fa-shield-alt" style="margin-right:5px"></i>Administrative</div>

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Status</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="Active" value="Active" <?= $Player['Active'] == 1 ? 'checked' : '' ?> /> Visible</label>
						<label><input type="radio" name="Active" value="Inactive" <?= $Player['Active'] != 1 ? 'checked' : '' ?> /> Retired</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label>Waiver</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="Waivered" value="Waivered" <?= $Player['Waivered'] == 1 ? 'checked' : '' ?> /> Waivered</label>
						<label><input type="radio" name="Waivered" value="Lawsuit Bait" <?= $Player['Waivered'] != 1 ? 'checked' : '' ?> /> No Waiver</label>
					</div>
				</div>
			</div>

			<div class="pn-acct-field">
				<label>
					<input type="checkbox" name="Restricted" value="Restricted" <?= $Player['Restricted'] == 1 ? 'checked' : '' ?> style="margin-right:6px" />
					Restricted Account
				</label>
			</div>

			<div class="pn-acct-field">
				<label for="pn-acct-member-since">Park Member Since</label>
				<input type="date" id="pn-acct-member-since" name="ParkMemberSince" value="<?= htmlspecialchars(($Player['ParkMemberSince'] ?? '') === '0000-00-00' ? '' : ($Player['ParkMemberSince'] ?? '')) ?>" />
			</div>
			<?php endif; ?>
		</div>

		<div class="pn-modal-footer">
			<?php if ($canEditAdmin): ?><button class="pn-btn pn-btn-ghost" id="pn-acct-move-player-btn" style="margin-right:auto;color:#c53030;border-color:#feb2b2;"><i class="fas fa-arrows-alt"></i> Move Player</button><?php endif; ?>
			<button class="pn-btn pn-btn-secondary" id="pn-acct-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-acct-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Add Dues Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-dues-overlay">
	<div class="pn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-receipt" style="margin-right:8px;color:#2c5282"></i>Add Dues Entry</h3>
			<button class="pn-modal-close-btn" id="pn-dues-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-dues-error"></div>

			<!-- All dues history -->
			<div class="pn-dues-modal-current">
				<div class="pn-dues-modal-current-title"><i class="fas fa-history" style="margin-right:5px"></i>Dues History</div>
				<?php if (is_array($AllDues) && count($AllDues) > 0): ?>
				<table class="pn-dues-modal-table">
					<thead><tr><th>Park</th><th>From</th><th>Paid Through</th><th>Status</th><?php if ($canEditAdmin): ?><th></th><?php endif; ?></tr></thead>
					<tbody>
					<?php foreach ($AllDues as $d):
						if ($d['DuesForLife'] == 1) {
							$status = '<span class="pn-dues-life">Lifetime</span>';
						} elseif (!empty($d['Revoked'])) {
							$status = '<span style="color:#e53e3e">Revoked</span>';
						} elseif (!empty($d['DuesUntil']) && strtotime($d['DuesUntil']) < time()) {
							$status = '<span style="color:#999">Expired</span>';
						} else {
							$status = '<span style="color:#38a169">Active</span>';
						}
					?>
						<tr>
							<td><?= htmlspecialchars($d['ParkName']) ?></td>
							<td><?= htmlspecialchars($d['DuesFrom'] ?? '—') ?></td>
							<td><?= $d['DuesForLife'] == 1 ? '—' : htmlspecialchars($d['DuesUntil']) ?></td>
							<td><?= $status ?></td>
							<?php if ($canEditAdmin): ?><td><?php if (empty($d['Revoked'])): ?><button class="pn-dues-revoke-btn" data-dues-id="<?= (int)$d['DuesId'] ?>">Revoke</button><?php endif; ?></td><?php endif; ?>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="pn-dues-modal-empty">No dues records on file</div>
				<?php endif; ?>
			</div>

			<div class="pn-acct-field">
				<label for="pn-dues-from">Date Paid <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pn-dues-from" name="DuesFrom" value="<?= date('Y-m-d') ?>" />
			</div>

			<div class="pn-acct-field" id="pn-dues-months-row">
				<label for="pn-dues-months" id="pn-dues-months-label"><?= $_duesPeriodType === 'week' ? 'Weeks' : 'Months' ?></label>
				<input type="number" id="pn-dues-months" name="Months" value="<?= (int)$_duesPeriod ?>" min="1" max="520" style="width:100px" />
				<div class="pn-dues-until-preview" id="pn-dues-until-preview"></div>
			</div>

			<div class="pn-acct-field">
				<label>Dues For Life</label>
				<div class="pn-acct-radio-group">
					<label><input type="radio" name="DuesForLife" value="1" /> Yes</label>
					<label><input type="radio" name="DuesForLife" value="0" checked /> No</label>
				</div>
			</div>

			<input type="hidden" name="MundaneId"      value="<?= (int)$Player['MundaneId'] ?>" />
			<input type="hidden" name="ParkId"         value="<?= (int)$Player['ParkId'] ?>" />
			<input type="hidden" name="KingdomId"      value="<?= (int)$KingdomId ?>" />
			<input type="hidden" name="DuesPeriodType" value="<?= htmlspecialchars($_duesPeriodType) ?>" />
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-dues-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-dues-save"><i class="fas fa-save"></i> Add Dues</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Dues History Modal (read-only, logged-in users)
     ============================================= -->
<?php if (isset($this->__session->user_id) && !$canEditAdmin): ?>
<div class="pn-overlay" id="pn-dues-history-overlay">
	<div class="pn-modal-box" style="width:560px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-receipt" style="margin-right:8px;color:#2c5282"></i>Dues History</h3>
			<button class="pn-modal-close-btn" id="pn-dues-history-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-acct-modal-body">
			<?php if (is_array($AllDues) && count($AllDues) > 0): ?>
			<table class="pn-dues-modal-table">
				<thead><tr><th>Park</th><th>From</th><th>Paid Through</th><th>Status</th></tr></thead>
				<tbody>
				<?php foreach ($AllDues as $d):
					if ($d['DuesForLife'] == 1) {
						$status = '<span class="pn-dues-life">Lifetime</span>';
					} elseif (!empty($d['Revoked'])) {
						$status = '<span style="color:#e53e3e">Revoked</span>';
					} elseif (!empty($d['DuesUntil']) && strtotime($d['DuesUntil']) < time()) {
						$status = '<span style="color:#999">Expired</span>';
					} else {
						$status = '<span style="color:#38a169">Active</span>';
					}
				?>
					<tr>
						<td><?= htmlspecialchars($d['ParkName']) ?></td>
						<td><?= htmlspecialchars($d['DuesFrom'] ?? '—') ?></td>
						<td><?= $d['DuesForLife'] == 1 ? '—' : htmlspecialchars($d['DuesUntil']) ?></td>
						<td><?= $status ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else: ?>
			<div class="pn-dues-modal-empty">No dues records on file</div>
			<?php endif; ?>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-dues-history-cancel">Close</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Qualifications Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-qual-overlay">
	<div class="pn-modal-box" style="width:480px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-certificate" style="margin-right:8px;color:#2c5282"></i>Edit Qualifications</h3>
			<button class="pn-modal-close-btn" id="pn-qual-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-qual-error"></div>

			<div class="pn-acct-section-title"><i class="fas fa-gavel" style="margin-right:5px"></i>Reeve Certification</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Reeve Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="ReeveQualified" value="1" <?= $Player['ReeveQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="ReeveQualified" value="0" <?= $Player['ReeveQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field pn-qual-until-row" id="pn-qual-reeve-until-row">
					<label for="pn-qual-reeve-until">Qualified Until</label>
					<input type="date" id="pn-qual-reeve-until" name="ReeveQualifiedUntil" value="<?= htmlspecialchars(($Player['ReeveQualifiedUntil'] ?? '') === '0000-00-00' ? '' : ($Player['ReeveQualifiedUntil'] ?? '')) ?>" />
				</div>
			</div>

			<div class="pn-acct-section-title" style="margin-top:14px"><i class="fas fa-book" style="margin-right:5px"></i>Corpora Certification</div>
			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Corpora Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="CorporaQualified" value="1" <?= $Player['CorporaQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="CorporaQualified" value="0" <?= $Player['CorporaQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field pn-qual-until-row" id="pn-qual-corpora-until-row">
					<label for="pn-qual-corpora-until">Qualified Until</label>
					<input type="date" id="pn-qual-corpora-until" name="CorporaQualifiedUntil" value="<?= htmlspecialchars(($Player['CorporaQualifiedUntil'] ?? '') === '0000-00-00' ? '' : ($Player['CorporaQualifiedUntil'] ?? '')) ?>" />
				</div>
			</div>

			<!-- Passthrough: preserve all non-qual player fields so Update Details doesn't overwrite them -->
			<input type="hidden" name="Update" value="Update Details" />
			<input type="hidden" name="GivenName"      value="<?= htmlspecialchars($Player['GivenName'] ?? '') ?>" />
			<input type="hidden" name="Surname"        value="<?= htmlspecialchars($Player['Surname'] ?? '') ?>" />
			<input type="hidden" name="Persona"        value="<?= htmlspecialchars($Player['Persona'] ?? '') ?>" />
			<input type="hidden" name="PronounId"      value="<?= (int)($Player['PronounId'] ?? 0) ?>" />
			<input type="hidden" name="PronounCustom"  value="<?= htmlspecialchars($Player['PronounCustom'] ?? '') ?>" />
			<input type="hidden" name="UserName"       value="<?= htmlspecialchars($Player['UserName'] ?? '') ?>" />
			<input type="hidden" name="Email"          value="<?= htmlspecialchars($Player['Email'] ?? '') ?>" />
			<input type="hidden" name="Password"       value="" />
			<input type="hidden" name="PasswordAgain"  value="" />
			<input type="hidden" name="Active"         value="<?= $Player['Active'] == 1 ? 'Active' : 'Inactive' ?>" />
			<input type="hidden" name="Restricted"     value="<?= $Player['Restricted'] == 1 ? 'Restricted' : '' ?>" />
			<input type="hidden" name="ParkMemberSince" value="<?= htmlspecialchars($Player['ParkMemberSince'] ?? '') ?>" />
			<input type="hidden" name="Waivered"       value="<?= $Player['Waivered'] == 1 ? 'Waivered' : 'Lawsuit Bait' ?>" />
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-qual-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-qual-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Add Award / Add Title Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-award-overlay">
	<div class="pn-modal-box" style="width:540px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title" id="pn-award-modal-title"><i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award</h3>
			<button class="pn-modal-close-btn" id="pn-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-acct-modal-body">
			<div class="pn-award-success" id="pn-award-success" style="display:none">
				<i class="fas fa-check-circle"></i> <span id="pn-award-success-msg">Award saved!</span>
			</div>
			<div class="pn-form-error" id="pn-award-error"></div>

			<!-- Award Type Toggle -->
			<div class="pn-award-type-row">
				<button type="button" class="pn-award-type-btn pn-active" id="pn-award-type-awards">
					<i class="fas fa-medal" style="margin-right:5px"></i>Awards
				</button>
				<button type="button" class="pn-award-type-btn" id="pn-award-type-officers">
					<i class="fas fa-crown" style="margin-right:5px"></i>Officer Titles
				</button>
			</div>

			<!-- Award Select -->
			<div class="pn-acct-field">
				<label for="pn-award-select">Award <span style="color:#e53e3e">*</span></label>
				<select id="pn-award-select" name="KingdomAwardId">
					<option value="">Select award…</option>
					<?= $AwardOptions ?>
				</select>
				<div class="pn-award-info-line" id="pn-award-info-line"></div>
			</div>

			<!-- Custom Award Name (only for "Custom Award") -->
			<div class="pn-acct-field" id="pn-award-custom-row" style="display:none">
				<label for="pn-award-custom-name">Custom Award Name</label>
				<input type="text" name="AwardName" id="pn-award-custom-name" maxlength="64" placeholder="Enter custom award name…" />
			</div>

			<!-- Rank Picker (only for ladder awards) -->
			<div class="pn-acct-field" id="pn-award-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; light blue = already held, green border = suggested; dark blue = selected</span></label>
				<div class="pn-rank-pills-wrap" id="pn-rank-pills"></div>
				<input type="hidden" name="Rank" id="pn-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="pn-acct-field">
				<label for="pn-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" name="Date" id="pn-award-date" />
			</div>

			<!-- Given By -->
			<div class="pn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="pn-officer-chips" id="pn-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="pn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="pn-award-givenby-text" placeholder="Or search by persona…" autocomplete="off" />
				<input type="hidden" name="GivenById" id="pn-award-givenby-id" value="" />
				<div class="pn-ac-results" id="pn-award-givenby-results"></div>
			</div>

			<!-- Given At -->
			<div class="pn-acct-field">
				<label for="pn-award-givenat-text">Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pn-award-givenat-text"
				       placeholder="Search park, kingdom, or event…"
				       autocomplete="off"
				       value="<?= htmlspecialchars($this->__session->park_name ?? '') ?>" />
				<div class="pn-ac-results" id="pn-award-givenat-results"></div>
				<input type="hidden" name="ParkId" id="pn-award-park-id" value="<?= (int)$Player['ParkId'] ?>" />
				<input type="hidden" name="KingdomId" id="pn-award-kingdom-id" value="0" />
				<input type="hidden" name="EventId" id="pn-award-event-id" value="0" />
			</div>

			<!-- Note -->
			<div class="pn-acct-field">
				<label for="pn-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea name="Note" id="pn-award-note" rows="3" maxlength="400"
				          placeholder="What was this award given for?"></textarea>
				<span class="pn-char-count" id="pn-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer" style="display:flex;align-items:center;justify-content:space-between">
			<button class="pn-btn pn-btn-ghost" id="pn-award-cancel">Close</button>
			<div style="display:flex;gap:8px">
				<button class="pn-btn pn-btn-primary" id="pn-award-save-same" disabled>
					<i class="fas fa-plus"></i> Add Award
				</button>
			</div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- =============================================
     Award Edit Modal
     ============================================= -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-award-edit-overlay">
	<div class="pn-modal-box" style="width:520px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#2c5282"></i>Edit Award</h3>
			<button class="pn-modal-close-btn" id="pn-edit-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-edit-award-feedback" style="display:none"></div>

			<!-- ── Historical award reconcile banner (shown only for legacy records) ── -->
			<div id="pn-edit-reconcile-banner" style="display:none;margin-bottom:16px;padding:12px 14px;background:#fffbeb;border:1px solid #f6e05e;border-radius:6px;">
				<label style="display:flex;align-items:flex-start;gap:10px;cursor:pointer;margin:0;font-weight:600;color:#744210;">
					<input type="checkbox" id="pn-edit-reconcile-check" style="margin-top:3px;flex-shrink:0;">
					<span id="pn-edit-reconcile-label">Convert legacy record to current award system</span>
				</label>
				<div id="pn-edit-reconcile-fields" style="display:none;margin-top:14px;border-top:1px solid #f6e05e;padding-top:12px;">
					<div class="pn-acct-field">
						<label>Target Award <span style="color:#e53e3e">*</span></label>
						<select id="pn-edit-reconcile-award">
							<option value="">— select award —</option>
							<?= $AwardOptions ?>
						</select>
					</div>
					<div class="pn-acct-field" id="pn-edit-reconcile-rank-row" style="display:none;">
						<label>Rank <span style="font-weight:400;color:#a0aec0;font-size:11px">— click to select</span></label>
						<div class="pn-rank-pills-wrap" id="pn-edit-reconcile-rank-pills"></div>
						<input type="hidden" id="pn-edit-reconcile-rank-val" value="">
					</div>
					<div style="font-size:11px;color:#975a16;margin-top:4px;">
						Reconciliation links this legacy import to a real award record. The date, note, and location you enter below will be saved.
					</div>
				</div>
			</div>

			<div class="pn-acct-field">
				<label>Award</label>
				<div class="pn-edit-award-name-display" id="pn-edit-award-name"></div>
			</div>

			<div class="pn-acct-field" id="pn-edit-rank-row" style="display:none">
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select</span></label>
				<div class="pn-rank-pills-wrap" id="pn-edit-rank-pills"></div>
				<input type="hidden" id="pn-edit-rank-val" value="" />
			</div>

			<div class="pn-acct-field">
				<label for="pn-edit-award-date">Date <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pn-edit-award-date" />
			</div>

			<div class="pn-acct-field">
				<label>Given By <span style="color:#e53e3e">*</span></label>
				<?php if (!empty($PreloadOfficers)): ?>
				<div class="pn-officer-chips" id="pn-edit-award-officer-chips">
					<?php foreach ($PreloadOfficers as $officer): ?>
					<button type="button" class="pn-officer-chip"
					        data-id="<?= (int)$officer['MundaneId'] ?>"
					        data-name="<?= htmlspecialchars($officer['Persona']) ?>">
						<?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span>
					</button>
					<?php endforeach; ?>
				</div>
				<?php endif; ?>
				<input type="text" id="pn-edit-givenby-text" placeholder="Or search by persona…" autocomplete="off" />
				<input type="hidden" id="pn-edit-givenby-id" value="" />
				<div class="pn-ac-results" id="pn-edit-givenby-results"></div>
			</div>

			<div class="pn-acct-field">
				<label>Given At <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<input type="text" id="pn-edit-givenat-text" placeholder="Search park, kingdom, or event…" autocomplete="off" />
				<input type="hidden" id="pn-edit-park-id"    value="" />
				<input type="hidden" id="pn-edit-kingdom-id" value="" />
				<input type="hidden" id="pn-edit-event-id"   value="" />
				<div class="pn-ac-results" id="pn-edit-givenat-results"></div>
			</div>

			<div class="pn-acct-field">
				<label for="pn-edit-award-note">Note <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="pn-edit-award-note" rows="3" maxlength="400" placeholder="What was this award given for?"></textarea>
				<span class="pn-char-count" id="pn-edit-award-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-ghost" id="pn-edit-award-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-edit-award-save"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>
<?php endif; ?>

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
			<div class="pn-form-error" id="pn-rec-error"><?= $recError ?></div>
			<form id="pn-recommend-form" method="post" action="<?= UIR ?>Player/profile/<?= $Player['MundaneId'] ?>/addrecommendation">
				<div class="pn-rec-field">
					<label for="pn-rec-award">Award <span style="color:#e53e3e">*</span></label>
					<select name="KingdomAwardId" id="pn-rec-award">
						<option value="">Select award...</option>
						<?= $AwardOptions ?>
					</select>
				</div>
				<div class="pn-rec-field" id="pn-rec-rank-row" style="display:none">
					<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; light blue = already held, green border = suggested; dark blue = selected</span></label>
					<div class="pn-rank-pills-wrap" id="pn-rec-rank-pills"></div>
					<input type="hidden" name="Rank" id="pn-rec-rank-val" value="" />
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
<script>
var PnConfig = {
	uir:            '<?= UIR ?>',
	httpService:    '<?= HTTP_SERVICE ?>',
	playerId:       <?= (int)($Player['MundaneId'] ?? 0) ?>,
	parkId:         <?= (int)($Player['ParkId'] ?? 0) ?>,
	parkName:       <?= json_encode($this->__session->park_name ?? '') ?>,
	kingdomId:      <?= (int)($KingdomId ?? 0) ?>,
	recError:       <?= !empty($recError) ? 'true' : 'false' ?>,
	canEditImages:  <?= !empty($canEditImages)  ? 'true' : 'false' ?>,
	canEditAccount: <?= !empty($canEditAccount) ? 'true' : 'false' ?>,
	canEditAdmin:   <?= !empty($canEditAdmin)   ? 'true' : 'false' ?>,
	classList:      <?= json_encode(array_values(array_map(function($c) { return ['ClassId' => (int)$c['ClassId'], 'ClassName' => $c['ClassName'], 'Credits' => (float)($c['Credits'] ?? 0), 'Reconciled' => (int)($c['Reconciled'] ?? 0)]; }, $classList ?? []))) ?>,
	awardRanks:     <?= json_encode($playerAwardRanks) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	playerParkName:   <?= json_encode($Player['Park'] ?? $Player['ParkName'] ?? '') ?>,
	playerPersona:    <?= json_encode($Player['Persona'] ?? '') ?>,
	duesPeriodType:   <?= json_encode($_duesPeriodType) ?>,
	duesPeriod:       <?= (int)$_duesPeriod ?>,
	canCreateUnit:    <?= (!empty($canEditAdmin) || !empty($isOwnProfile)) && !empty($LoggedIn) ? 'true' : 'false' ?>,
	lastClassId:      <?= $_lastClassId ?>,
	attendanceDates:  <?= json_encode(array_values(array_unique(array_filter(array_map(function($a) { return $a['Date'] ?? ''; }, is_array($Details['Attendance']) ? $Details['Attendance'] : []))))) ?>,
};
// Use the viewed player's kingdom for nav search prioritization if the user has no home kingdom
if (typeof nsKid !== 'undefined' && nsKid === 0 && PnConfig.kingdomId) nsKid = PnConfig.kingdomId;
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script>
pnSortDesc($('#pn-awards-table'), 2, 'date');     pnPaginate($('#pn-awards-table'), 1);
pnSortDesc($('#pn-titles-table'), 2, 'date');     pnPaginate($('#pn-titles-table'), 1);
pnSortDesc($('#pn-attendance-table'), 0, 'date'); pnPaginate($('#pn-attendance-table'), 1);
pnSortDesc($('#pn-history-table'), 2, 'date');    pnPaginate($('#pn-history-table'), 1);
// 26-week sparkline
(function() {
	var el = document.getElementById('pna-sparkline');
	if (!el) return;
	var dates = (typeof PnConfig !== 'undefined' && PnConfig.attendanceDates) ? PnConfig.attendanceDates : [];
	var attended = {};
	dates.forEach(function(d) { attended[d] = true; });
	var now = new Date(); now.setHours(0,0,0,0);
	var months = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
	var html = '', mhtml = '', prevMonth = -1;
	for (var w = 25; w >= 0; w--) {
		var wkStart = new Date(now); wkStart.setDate(wkStart.getDate() - (w * 7) - wkStart.getDay());
		var wkEnd   = new Date(wkStart); wkEnd.setDate(wkEnd.getDate() + 6);
		var hit = false;
		for (var d2 = new Date(wkStart); d2 <= wkEnd; d2.setDate(d2.getDate() + 1)) {
			var ds = d2.getFullYear() + '-' + String(d2.getMonth()+1).padStart(2,'0') + '-' + String(d2.getDate()).padStart(2,'0');
			if (attended[ds]) { hit = true; break; }
		}
		var ht = hit ? 34 : 10;
		var cls = hit ? 'pna-spark-on' : 'pna-spark-off';
		var label = 'Week of ' + wkStart.toLocaleDateString('en-US',{month:'short',day:'numeric'});
		html += '<div class="pna-spark-week ' + cls + '" title="' + label + '" style="height:' + ht + 'px"></div>';
		var wkMonth = wkStart.getMonth();
		var lbl = (wkMonth !== prevMonth) ? months[wkMonth] : '';
		mhtml += '<div class="pna-spark-month-lbl">' + lbl + '</div>';
		prevMonth = wkMonth;
	}
	el.innerHTML = html;
	var mel = document.getElementById('pna-spark-months');
	if (mel) mel.innerHTML = mhtml;
})();
</script>

<?php if ($canEditAdmin): ?>
<!-- Revoke Award Modal -->
<div class="pn-overlay" id="pn-award-revoke-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-ban" style="margin-right:8px;color:#b7791f"></i>Revoke Award</h3>
			<button class="pn-modal-close-btn" id="pn-revoke-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-revoke-award-feedback" style="display:none"></div>
			<div class="pn-revoke-award-name" id="pn-revoke-award-name"></div>
			<div class="pn-acct-field">
				<label for="pn-revoke-reason">Revocation Reason <span style="color:#e53e3e">*</span></label>
				<textarea id="pn-revoke-reason" rows="3" maxlength="300" placeholder="Why is this award being revoked?"></textarea>
				<span class="pn-char-count" id="pn-revoke-char-count">300 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-revoke-award-cancel">Cancel</button>
			<button class="pn-btn" id="pn-revoke-award-save" style="background:#c53030;color:#fff;"><i class="fas fa-ban"></i> Revoke Award</button>
		</div>
	</div>
</div>

<!-- Add Note Modal -->
<div class="pn-overlay" id="pn-addnote-overlay">
	<div class="pn-modal-box" style="width:480px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-sticky-note" style="margin-right:8px;color:#2c5282"></i><span id="pn-addnote-modal-title">Add Note</span></h3>
			<button class="pn-modal-close-btn" id="pn-addnote-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-addnote-feedback" style="display:none"></div>
			<div class="pn-acct-field">
				<label for="pn-note-title">Note Title <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-note-title" maxlength="200" placeholder="e.g. Promotion, Warning, Waypoint Import" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-note-desc">Description</label>
				<textarea id="pn-note-desc" rows="3" maxlength="1000" placeholder="Optional additional details..."></textarea>
			</div>
			<div class="pn-addnote-date-row">
				<div class="pn-acct-field" style="flex:1">
					<label for="pn-note-date">Date <span style="color:#e53e3e">*</span></label>
					<input type="date" id="pn-note-date" />
				</div>
				<div class="pn-acct-field" style="flex:1">
					<label for="pn-note-date-complete">Date Complete</label>
					<input type="date" id="pn-note-date-complete" />
				</div>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-addnote-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-addnote-save"><i class="fas fa-save"></i> Add Note</button>
		</div>
	</div>
</div>

<!-- Player Add Attendance Modal -->
<style>
#pn-player-att-overlay .pn-modal-body { overflow:visible; }
#pn-player-att-overlay .pn-acct-field { position:relative; }
#pn-player-att-overlay .pn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
</style>
<div class="pn-overlay" id="pn-player-att-overlay">
	<div class="pn-modal-box" style="width:440px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-plus-circle" style="margin-right:8px;color:#276749"></i>Add Attendance</h3>
			<button class="pn-modal-close-btn" id="pn-player-att-close" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-player-att-feedback" style="display:none"></div>
			<div class="pn-acct-field">
				<label>Player</label>
				<div class="pn-mp-player-locked"><?= htmlspecialchars($Player['Persona'] ?? '') ?></div>
			</div>
			<div class="pn-acct-field" style="position:relative">
				<label>Park</label>
				<input type="text" id="pn-player-att-park-name" autocomplete="off" placeholder="Search for a park…" value="<?= htmlspecialchars($Player['ParkName'] ?? '') ?>">
				<input type="hidden" id="pn-player-att-park-id" value="<?= (int)($Player['ParkId'] ?? 0) ?>">
				<div class="pn-ac-results" id="pn-player-att-park-results"></div>
			</div>
			<div style="display:flex;gap:12px">
				<div class="pn-acct-field" style="flex:1">
					<label>Date</label>
					<input type="date" id="pn-player-att-date" style="width:100%">
				<div id="pn-player-att-date-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"><i class="fas fa-exclamation-triangle"></i> Player already has attendance record on this date.</div>
				</div>
				<div class="pn-acct-field" style="flex:0 0 90px">
					<label>Credits</label>
					<input type="number" id="pn-player-att-credits" value="1" min="0.5" max="4" step="0.5" style="width:100%">
				</div>
			</div>
			<div class="pn-acct-field">
				<label>Class</label>
				<select id="pn-player-att-class" style="width:100%"></select>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-player-att-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-player-att-submit"><i class="fas fa-plus"></i> Add Attendance</button>
		</div>
	</div>
</div>

<!-- Edit Attendance Modal -->
<?php if ($canEditAdmin): ?>
<div class="pn-overlay" id="pn-att-edit-overlay">
	<div class="pn-modal-box" style="max-width:400px">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pencil-alt" style="margin-right:8px;color:#2c5282"></i>Edit Attendance</h3>
			<button class="pn-modal-close-btn" id="pn-att-edit-close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div class="pn-form-error" id="pn-att-edit-feedback" style="display:none"></div>
			<input type="hidden" id="pn-att-edit-id">
			<input type="hidden" id="pn-att-edit-mundane-id">
			<div class="pn-acct-field" style="margin-bottom:12px">
				<label>Date</label>
				<input type="date" id="pn-att-edit-date" style="width:100%">
			</div>
			<div style="display:flex;gap:12px;margin-bottom:12px">
				<div class="pn-acct-field" style="flex:1">
					<label>Class</label>
					<select id="pn-att-edit-class" style="width:100%"></select>
				</div>
				<div class="pn-acct-field" style="flex:0 0 90px">
					<label>Credits</label>
					<input type="number" id="pn-att-edit-credits" value="1" min="0.5" max="4" step="0.5" style="width:100%">
				</div>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-att-edit-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-att-edit-submit"><i class="fas fa-save"></i> Save</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Move Player Modal -->
<?php if ($canEditAdmin): ?>
<style>
.pn-mp-toggle { display:flex; background:#edf2f7; border-radius:6px; padding:3px; gap:3px; margin-bottom:14px; }
.pn-mp-toggle-btn { flex:1; padding:6px 8px; border:none; border-radius:4px; font-size:11px; font-weight:600; cursor:pointer; background:transparent; color:#718096; white-space:nowrap; }
.pn-mp-toggle-btn.pn-mp-active { background:#fff; color:#2b6cb0; box-shadow:0 1px 3px rgba(0,0,0,0.1); }
#pn-moveplayer-overlay .pn-modal-body { overflow:visible; }
#pn-moveplayer-overlay .pn-acct-field { position:relative; }
#pn-moveplayer-overlay .pn-ac-results { position:absolute; left:0; right:0; z-index:9999; }
.pn-mp-player-locked { background:#f7fafc; border:1px solid #e2e8f0; border-radius:4px; padding:8px 12px; color:#4a5568; font-size:0.95rem; }
</style>
<div class="pn-overlay" id="pn-moveplayer-overlay">
	<div class="pn-modal-box" style="width:500px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-arrows-alt" style="margin-right:8px;color:#2c5282"></i>Move Player</h3>
			<button class="pn-modal-close-btn" id="pn-moveplayer-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-moveplayer-feedback" style="display:none"></div>
			<div class="pn-mp-toggle">
				<button class="pn-mp-toggle-btn pn-mp-active" id="pn-mp-btn-within">Transfer Within Kingdom</button>
				<button class="pn-mp-toggle-btn" id="pn-mp-btn-out">Transfer Out of Kingdom</button>
			</div>
			<div class="pn-acct-field">
				<label>Player</label>
				<div class="pn-mp-player-locked" id="pn-mp-player-display"><?= htmlspecialchars($Player['Persona'] ?? '') ?></div>
			</div>
			<div class="pn-move-current-park" style="margin:10px 0 4px">
				<strong>Current park:</strong> <span id="pn-move-current-park-name"></span>
			</div>
			<div class="pn-acct-field">
				<label id="pn-moveplayer-park-label">New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="pn-moveplayer-park-name" placeholder="Search for a park…" autocomplete="off" />
				<input type="hidden" id="pn-moveplayer-park-id" value="" />
				<div class="pn-ac-results" id="pn-moveplayer-park-results"></div>
			</div>
			<div class="pn-move-warning">
				<i class="fas fa-exclamation-triangle"></i>
				This will change the player&rsquo;s home park and reset their Park Member Since date.
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-move-cancel">Cancel</button>
			<button class="pn-btn" id="pn-move-submit" disabled style="background:#c53030;color:#fff;"><i class="fas fa-arrows-alt"></i> Move Player</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Revoke All Awards Modal -->
<div class="pn-overlay" id="pn-revoke-all-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-ban" style="margin-right:8px;color:#c53030"></i>Revoke All Awards</h3>
			<button class="pn-modal-close-btn" id="pn-revoke-all-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-revoke-all-feedback" style="display:none"></div>
			<div class="pn-revoke-all-warning">
				<i class="fas fa-exclamation-triangle pn-revoke-all-warn-icon"></i>
				<div>
					<strong>This cannot be undone.</strong><br>
					All awards for this player will be permanently revoked.
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-revoke-all-reason">Revocation Reason <span style="color:#e53e3e">*</span></label>
				<textarea id="pn-revoke-all-reason" rows="3" maxlength="300" placeholder="Why are all awards being revoked?"></textarea>
				<span class="pn-char-count" id="pn-revoke-all-char-count">300 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-revoke-all-cancel">Cancel</button>
			<button class="pn-btn" id="pn-revoke-all-save" style="background:#c53030;color:#fff;" disabled><i class="fas fa-ban"></i> Revoke All Awards</button>
		</div>
	</div>
</div>

<!-- Class Reconciliation Modal -->
<div class="pn-overlay" id="pn-reconcile-overlay">
	<div class="pn-modal-box" style="width:500px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-sliders-h" style="margin-right:8px;color:#2c5282"></i>Edit Class Reconciliation</h3>
			<button class="pn-modal-close-btn" id="pn-reconcile-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body" style="padding:0">
			<div id="pn-reconcile-feedback" style="display:none;padding:8px 16px;margin:0"></div>
			<table class="pn-table" id="pn-reconcile-table" style="margin:0">
				<thead>
					<tr>
						<th>Class</th>
						<th class="pn-col-numeric">Base Credits</th>
						<th class="pn-col-numeric">Adjustment</th>
						<th class="pn-col-numeric">Total</th>
					</tr>
				</thead>
				<tbody id="pn-reconcile-tbody"></tbody>
			</table>
			<p style="font-size:11px;color:#a0aec0;padding:8px 16px;margin:0">Adjustment adds or subtracts from attendance-based credits.</p>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-reconcile-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-reconcile-save"><i class="fas fa-save"></i> Save</button>
		</div>
	</div>
</div>

<?php endif; ?>

<!-- Create Unit Modal -->
<?php if ($canEditAdmin || $isOwnProfile): ?>
<div class="pn-overlay" id="pn-unit-create-overlay">
	<div class="pn-modal-box" style="width:480px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-shield-alt" style="margin-right:8px;color:#2c5282"></i>Create Company or Household</h3>
			<button class="pn-modal-close-btn" id="pn-unit-create-close-btn" aria-label="Close" onclick="pnCloseUnitCreateModal()">&times;</button>
		</div>
		<form method="post" action="<?= UIR ?>Unit/create/<?= (int)$Player['MundaneId'] ?>">
			<input type="hidden" name="Action" value="create">
			<div class="pn-acct-modal-body">
				<div class="pn-acct-field">
					<label>Name <span style="color:#e53e3e">*</span></label>
					<input type="text" name="Name" required placeholder="Enter a name…" autocomplete="off">
				</div>
				<div class="pn-acct-field">
					<label>Type</label>
					<select name="Type">
						<option value="Household">Household</option>
						<option value="Company">Company</option>
					</select>
				</div>
				<div class="pn-acct-field">
					<label>Website URL <span style="font-weight:400;color:#a0aec0;">(optional)</span></label>
					<input type="url" name="Url" placeholder="https://…">
				</div>
			</div>
			<div class="pn-modal-footer">
				<button type="button" class="pn-btn pn-btn-secondary" id="pn-unit-create-cancel" onclick="pnCloseUnitCreateModal()">Cancel</button>
				<button type="submit" class="pn-btn pn-btn-primary"><i class="fas fa-plus"></i> Create</button>
			</div>
		</form>
	</div>
</div>
<?php endif; ?>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
	if ($('#pn-rec-table').length) {
		$('#pn-rec-table').DataTable({
			order: [[2, 'desc']],
			columnDefs: [
				{ targets: [2], type: 'date' },
				<?php if ($this->__session->user_id): ?>
				{ targets: [-1], orderable: false, searchable: false },
				<?php endif; ?>
			],
			pageLength: 25
		});
	}
});
</script>