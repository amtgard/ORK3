<?php
	$passwordExpired  = strtotime($Player['PasswordExpires']) - time() <= 0;
	$passwordSoonSecs = strtotime($Player['PasswordExpires']) - time();
	$passwordSoon     = !$passwordExpired && $passwordSoonSecs <= (14 * 86400);
	$passwordExpiring = $passwordExpired ? 'Expired' : date('Y-m-j', strtotime($Player['PasswordExpires']));
	$recError = isset($_GET['rec_error']) ? htmlspecialchars(urldecode($_GET['rec_error'])) : '';

	$can_delete_recommendation = false;
	if($this->__session->user_id) {
		if (isset($this->__session->park_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $this->__session->park_id, AUTH_CREATE)) {
				$can_delete_recommendation = true;
			}
		}
		if (!$can_delete_recommendation && isset($this->__session->kingdom_id)) {
			if (Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_KINGDOM, $this->__session->kingdom_id, AUTH_CREATE)) {
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
	$beltIconUrl = '//' . $_SERVER['HTTP_HOST'] . '/assets/images/belt.svg';

	// Auth helpers
	$isOwnProfile  = isset($this->__session->user_id) && (int)$this->__session->user_id === (int)$Player['MundaneId'];
	$canEditAdmin  = isset($this->__session->user_id) && Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT);
	$canManageAwards = isset($this->__session->user_id) && Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_CREATE);
	$canEditNotes  = $canEditAdmin; // AddNote/RemoveNote require AUTH_EDIT, same as canEditAdmin
	$canEditImages  = $isOwnProfile || $canEditAdmin;
	$canEditAccount = $isOwnProfile || $canEditAdmin;

	// Check if player has any reconcilable historical awards (ladder only — matches reconcile page filter)
	$hasHistorical = false;
	if ($canManageAwards && is_array($Details['Awards'])) {
		foreach ($Details['Awards'] as $_ha) {
			if (in_array($_ha['OfficerRole'], ['none', null]) && $_ha['IsTitle'] != 1 && (int)($_ha['IsLadder'] ?? 0) === 1) {
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
			if (in_array($_ha['OfficerRole'], ['none', null]) && $_ha['IsTitle'] != 1 && (int)($_ha['IsLadder'] ?? 0) === 1) {
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

	// Dues for Life flag (needed for badge + alerts)
	$_duesForLife = is_array($Dues) && count(array_filter($Dues, function($d) { return $d['DuesForLife'] == 1; })) > 0;

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
		// First credit date (oldest non-zero attendance)
		$_maFirstDate = null;
		foreach ($_maDash_att as $_fa) {
			if (!empty($_fa['Date']) && $_fa['Date'] !== '0000-00-00' && $_fa['Date'] !== '1970-01-01') {
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
			function($r) { return empty($r['AlreadyHas']); }
		));
		// Alerts
		$_maAlerts = [];
		$_duesThrough = $Player['DuesThrough'] ?? '';
		if (!empty($_duesThrough) && $_duesThrough !== '0000-00-00' && strtotime($_duesThrough) < time() && !$_duesForLife)
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
.pna-tenure-info-btn{display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#ebf4ff;color:#2b6cb0;font-size:10px;cursor:help;border:1px solid #bee3f8;position:relative;z-index:10;flex-shrink:0;margin-top:8px;vertical-align:middle}
.pna-tenure-info-btn .pna-tenure-info-text{display:none;position:fixed;width:260px;background:#2d3748;color:#fff;font-size:12px;font-weight:400;line-height:1.5;padding:8px 10px;border-radius:6px;pointer-events:none;z-index:9999;white-space:normal;box-shadow:0 4px 12px rgba(0,0,0,.3)}
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
.pna-feed-rank{display:inline-block;background:#e9d8fd;color:#553c9a;border-radius:10px;font-size:10px;font-weight:700;padding:1px 6px;margin-left:4px;vertical-align:middle}
.pna-feed-more{font-size:11px;color:#718096;padding-top:6px;text-align:center}
.pna-congrats-banner{background:linear-gradient(90deg,#fffff0,#fefcbf);border:1px solid #f6e05e;border-radius:6px;padding:9px 13px;font-size:12.5px;font-weight:600;color:#744210;margin-bottom:10px;display:flex;align-items:center;gap:8px}
.pna-welcome-banner{background:linear-gradient(135deg,#1a3d2b,#276749);border-radius:10px;padding:20px 24px;margin-bottom:18px;color:#fff;display:flex;align-items:flex-start;gap:16px}
.pna-welcome-banner-icon{font-size:32px;flex-shrink:0;line-height:1}
.pna-welcome-banner-body{flex:1;min-width:0}
.pna-welcome-banner-title{font-size:18px;font-weight:800;margin-bottom:4px;letter-spacing:-.01em}
.pna-welcome-banner-sub{font-size:13px;opacity:.85;line-height:1.5}
.pna-welcome-banner-tips{margin-top:12px;display:flex;flex-wrap:wrap;gap:8px}
.pna-welcome-tip{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.2);border-radius:6px;padding:5px 10px;font-size:12px;display:flex;align-items:center;gap:5px}
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
.pn-givenby-warn{display:inline-flex;align-items:center;gap:4px;cursor:default;position:relative}
.pn-givenby-warn .pn-tip-icon{color:#e53e3e;font-size:11px;font-weight:700;font-style:normal;border:1px solid #e53e3e;border-radius:50%;width:14px;height:14px;display:inline-flex;align-items:center;justify-content:center;line-height:1;flex-shrink:0}
.pn-givenby-warn .pn-tip-box{display:none;position:absolute;bottom:calc(100% + 6px);left:0;background:#2d3748;color:#fff;font-size:12px;line-height:1.4;padding:7px 10px;border-radius:5px;width:260px;white-space:normal;z-index:200;pointer-events:none;box-shadow:0 2px 8px rgba(0,0,0,.3)}
.pn-givenby-warn:hover .pn-tip-box{display:block}

/* ===================================================================
   DARK MODE OVERRIDES — Playernew profile
   Activated by: html[data-theme="dark"]
   =================================================================== */

/* Required field indicator */
.required-indicator { color: #e53e3e; }

/* Inline danger buttons — light default, dark override below */
.btn-danger-confirm { background: #c53030; color: #fff; border: none; cursor: pointer; }

/* ============================================================
   html[data-theme="dark"] overrides
   ============================================================ */
html[data-theme="dark"] .pn-hero { background-color: var(--ork-bg-secondary); }
html[data-theme="dark"] .pn-avatar { border-color: rgba(255,255,255,0.2); }
html[data-theme="dark"] .pn-stat-card { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-stat-number { color: #90cdf4; }
html[data-theme="dark"] .pn-stat-icon { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-stat-label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-card { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .pn-card h4 { color: var(--ork-text); background: transparent; border: none; border-bottom: 1px solid var(--ork-border); padding: 0 0 8px 0; border-radius: 0; text-shadow: none; }
html[data-theme="dark"] .pn-detail-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-detail-value { color: var(--ork-text); }
html[data-theme="dark"] .pn-detail-row { border-color: var(--ork-border); }
html[data-theme="dark"] .pn-tab-nav { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-tab-nav li { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-tab-nav li.pn-tab-active { background: var(--ork-card-bg); color: var(--ork-text); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-tab-nav li:hover:not(.pn-tab-active) { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .pn-tab-count { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-mini-table { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-mini-table th { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-mini-table td { color: var(--ork-text); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-mini-table tbody tr:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pn-badge-green { background: var(--ork-badge-green-bg, #1c4532); color: var(--ork-badge-green-text, #9ae6b4); }
html[data-theme="dark"] .pn-badge-red { background: var(--ork-badge-red-bg, #742a2a); color: var(--ork-badge-red-text, #feb2b2); }
html[data-theme="dark"] .pn-badge-gray { background: #374151; color: #a0aec0; }
html[data-theme="dark"] .pn-badge-blue { background: #1a365d; color: #90cdf4; }
html[data-theme="dark"] .pn-badge-yellow { background: #744210; color: #fbd38d; }
html[data-theme="dark"] .pn-badge-orange { background: #7b341e; color: #fbd38d; }
html[data-theme="dark"] .pn-badge-gold { background: #744210; color: #fbd38d; }
html[data-theme="dark"] .pn-badge-purple { background: #44337a; color: #d6bcfa; }
html[data-theme="dark"] .pn-modal-box { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); }
html[data-theme="dark"] .pn-modal-header { border-color: var(--ork-border); background: var(--ork-bg-secondary); }
html[data-theme="dark"] .pn-modal-title { color: var(--ork-text); }
html[data-theme="dark"] .pn-modal-body { background: var(--ork-card-bg); color: var(--ork-text); }
html[data-theme="dark"] .pn-modal-footer { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-modal-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-modal-close-btn:hover { color: var(--ork-text); background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pn-overlay { background: rgba(0,0,0,0.7); }
html[data-theme="dark"] .pn-acct-field label { color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-acct-field input[type="text"],
html[data-theme="dark"] .pn-acct-field input[type="date"],
html[data-theme="dark"] .pn-acct-field input[type="number"],
html[data-theme="dark"] .pn-acct-field input[type="url"],
html[data-theme="dark"] .pn-acct-field input[type="password"],
html[data-theme="dark"] .pn-acct-field select,
html[data-theme="dark"] .pn-acct-field textarea { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .pna-card { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pna-card-title { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-tenure-years { color: var(--ork-link); }
html[data-theme="dark"] .pna-tenure-label,
html[data-theme="dark"] .pna-tenure-since { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-class-name { color: var(--ork-text); }
html[data-theme="dark"] .pna-class-level { color: #68d391; }
html[data-theme="dark"] .pna-bar-wrap { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pna-bar { background: linear-gradient(90deg,#48bb78,#38a169) !important; }
html[data-theme="dark"] .pna-bar-max { background: linear-gradient(90deg,#f6ad55,#c05621) !important; }
html[data-theme="dark"] .pna-class-credits { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-officer-title { color: var(--ork-text); }
html[data-theme="dark"] .pna-officer-row { border-color: var(--ork-border); }
html[data-theme="dark"] .pna-feed-row { border-color: var(--ork-border); }
html[data-theme="dark"] .pna-feed-date { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-feed-label { color: var(--ork-text); }
html[data-theme="dark"] .pna-feed-label a { color: var(--ork-link); }
html[data-theme="dark"] .pna-feed-sub { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-ev-col-hdr { color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pna-assoc-group { color: var(--ork-text-muted); border-color: var(--ork-border); }
html[data-theme="dark"] .pna-alert-warning { background: var(--ork-alert-warning-bg, #744210); border-color: var(--ork-alert-warning-border, #975a16); color: var(--ork-alert-warning-text, #fbd38d); }
html[data-theme="dark"] .pna-alert-danger { background: var(--ork-alert-danger-bg, #742a2a); border-color: var(--ork-alert-danger-border, #9b2c2c); color: var(--ork-alert-danger-text, #feb2b2); }
html[data-theme="dark"] .pna-alert-info { background: var(--ork-alert-info-bg, #1a365d); border-color: var(--ork-alert-info-border, #2a4365); color: var(--ork-alert-info-text, #90cdf4); }
html[data-theme="dark"] .pna-spark-off,
html[data-theme="dark"] .pna-spark-swatch-off { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }
html[data-theme="dark"] .pna-spark-legend { color: var(--ork-text-muted); }
html[data-theme="dark"] .pna-congrats-banner { background: linear-gradient(90deg,#3d3300,#4a3c00); border-color: #975a16; color: #fbd38d; }
html[data-theme="dark"] .pna-anni-banner { color: #fbd38d; }
html[data-theme="dark"] .pna-tenure-info-btn { background: #1a365d; color: #90cdf4; border-color: #2a4365; }
html[data-theme="dark"] .pn-ladder-item { background: var(--ork-bg-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-ladder-name { color: var(--ork-text); }
html[data-theme="dark"] .pn-ladder-rank { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-ladder-bar-track { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pn-table { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-table th { background: var(--ork-bg-secondary); color: var(--ork-text-secondary); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-table td { color: var(--ork-text); border-color: var(--ork-border); }
html[data-theme="dark"] .pn-table tbody tr:hover { background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .pn-empty { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-unit-link { color: var(--ork-link); }
html[data-theme="dark"] .pn-unit-type { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-unit-row { border-color: var(--ork-border); }
html[data-theme="dark"] .pn-suspended-detail { background: #742a2a; color: #feb2b2; border-color: #9b2c2c; }
html[data-theme="dark"] .pn-revoke-all-warning { background: #742a2a; border-color: #9b2c2c; color: #feb2b2; }
html[data-theme="dark"] .pn-move-warning { background: #744210; border-color: #975a16; color: #fbd38d; }
html[data-theme="dark"] .pn-mp-player-locked { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-mp-toggle { background: var(--ork-bg-secondary); }
html[data-theme="dark"] .pn-mp-toggle-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-mp-toggle-btn.pn-mp-active { background: var(--ork-card-bg); color: var(--ork-link); }
html[data-theme="dark"] .btn-danger-confirm { background: #fc8181; color: #1a202c; }
html[data-theme="dark"] .pn-char-count { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-revoke-award-name { color: var(--ork-text); }
html[data-theme="dark"] .pn-form-error { background: var(--ork-alert-danger-bg, #742a2a); color: var(--ork-alert-danger-text, #feb2b2); }
html[data-theme="dark"] .pn-tab-toolbar { border-color: var(--ork-border); }
html[data-theme="dark"] .required-indicator { color: #feb2b2; }
html[data-theme="dark"] .pn-ac-results { background: var(--ork-card-bg); border-color: var(--ork-border); box-shadow: 0 4px 12px rgba(0,0,0,0.4); }
html[data-theme="dark"] .pn-ac-item { color: var(--ork-text); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .pn-ac-item:hover,
html[data-theme="dark"] .pn-ac-item:focus,
html[data-theme="dark"] .pn-ac-item.pn-ac-focused { background: var(--ork-bg-tertiary); color: var(--ork-link-bright); }
html[data-theme="dark"] .pn-page-btn { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-page-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .pn-page-btn.pn-page-active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .pn-award-type-btn { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-award-type-btn:hover { background: var(--ork-bg-tertiary); color: var(--ork-text); }
html[data-theme="dark"] .pn-award-type-btn.pn-active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .pn-officer-chip { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .pn-officer-chip span { color: var(--ork-text-muted); }
html[data-theme="dark"] .pn-officer-chip:hover { background: var(--ork-bg-tertiary); border-color: var(--ork-link); color: var(--ork-link); }
html[data-theme="dark"] .pn-officer-chip.pn-selected { background: var(--ork-bg-tertiary); border-color: var(--ork-link); color: var(--ork-link); font-weight: 600; }
html[data-theme="dark"] .pn-active-tab-label { background: var(--ork-card-bg); color: var(--ork-text); }
html[data-theme="dark"] .pn-persona { color: #fff !important; background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: 0 1px 3px rgba(0,0,0,0.4) !important; }

/* ============================================================
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
				<?php if (!empty($Player['IsNewPlayer'])): ?>
					<span class="pn-badge pn-badge-blue"><i class="fas fa-star"></i> New Player</span>
				<?php endif; ?>
				<?php if ($isSuspended): ?>
					<span class="pn-badge pn-badge-red"><i class="fas fa-ban"></i> Suspended</span>
				<?php endif; ?>
				<?php if ($Player['Waivered'] == 1): ?>
					<span class="pn-badge pn-badge-blue"><i class="fas fa-file-signature"></i> Waivered</span>
				<?php elseif ($isOwnProfile && valid_id($Player['KingdomId'] ?? 0)): ?>
					<a href="<?= UIR ?>Waiver/sign/kingdom/<?= (int)$Player['KingdomId'] ?>" class="pn-badge pn-badge-yellow pn-badge-link" style="text-decoration:none;"><i class="fas fa-exclamation-circle"></i> Needs Waiver &rarr;</a>
				<?php else: ?>
					<span class="pn-badge pn-badge-yellow"><i class="fas fa-exclamation-circle"></i> Needs Waiver</span>
				<?php endif; ?>
				<?php if ($_duesForLife || (!empty($Player['DuesThrough']) && strtotime($Player['DuesThrough']) >= time())): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-receipt"></i> Dues Paid</span>
				<?php elseif (!empty($Player['LastDuesThrough'])): ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> Dues Expired</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> No Dues on File</span>
				<?php endif; ?>
				<span id="pn-voting-badge" style="display:none;" class="pn-badge pn-badge-blue"><i class="fas fa-vote-yea"></i> Voting Eligible<span id="pn-voting-badge-sub" class="pn-badge-sub" style="display:none;"></span></span>
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
					Suspended <?= htmlspecialchars($Player['SuspendedAt'] ?? '') ?> &mdash; Until <?php $_until = $Player['SuspendedUntil'] ?? ''; echo ($_until && $_until !== '0000-00-00') ? htmlspecialchars($_until) : 'Indefinite'; ?>
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
		<div class="pn-stat-number" id="pn-att-stat-count">…</div>
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
		<div class="pn-stat-number pn-stat-text" id="pn-att-last-class">…</div>
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
			<div class="pn-detail-row"<?= ($passwordExpired || $passwordSoon) ? ' style="background:var(--ork-alert-warning-bg,#fffbe6);border-left:3px solid var(--ork-alert-warning-border,#f6ad55);padding-left:6px;margin-left:-6px;"' : '' ?>>
				<span class="pn-detail-label">Password Expires</span>
				<span class="pn-detail-value" style="<?= $passwordExpired ? 'color:#c53030;font-weight:600;' : ($passwordSoon ? 'color:#b7791f;font-weight:600;' : '') ?>"><?= $passwordExpiring ?><?= $passwordSoon ? ' <i class="fas fa-exclamation-triangle" style="margin-left:5px;font-size:12px;" title="Expires within 2 weeks"></i>' : '' ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Park Member Since</span>
				<span class="pn-detail-value"><?= (!empty($Player['ParkMemberSince']) && $Player['ParkMemberSince'] !== '0000-00-00') ? htmlspecialchars($Player['ParkMemberSince']) : 'N/A' ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Player Since</span>
				<span class="pn-detail-value"><?= $Player['PlayerSinceDate'] ? htmlspecialchars($Player['PlayerSinceDate']) : 'N/A' ?></span>
			</div>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Last Sign-In</span>
				<span class="pn-detail-value"><?= $Player['LastSignInDate'] ? htmlspecialchars($Player['LastSignInDate']) : 'N/A' ?></span>
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
								<td><?= htmlspecialchars($d['ParkName']) ?></td>
								<td>
									<?php if ($d['DuesForLife'] == 1): ?>
										<span class="pn-dues-life">Lifetime</span>
									<?php else: ?>
										<?= htmlspecialchars($d['DuesUntil']) ?>
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

		<!-- Digital Waivers (own profile only) -->
		<?php $_wvSide = $this->data['_wv_sidebar'] ?? ['is_own' => false, 'items' => []]; ?>
		<?php if (!empty($_wvSide['is_own']) && !empty($_wvSide['items'])): ?>
		<div class="pn-card">
			<h4 style="background: transparent !important; border: none !important; padding: 0 !important; border-radius: 0 !important; text-shadow: none !important;"><i class="fas fa-file-signature"></i> Digital Waivers</h4>
			<?php foreach ($_wvSide['items'] as $it): ?>
			<div class="pn-detail-row">
				<span class="pn-detail-label"><?= htmlspecialchars(ucfirst($it['scope'])) ?> waiver (v<?= (int)$it['version'] ?>)</span>
				<span class="pn-detail-value"><a href="<?= UIR ?>Waiver/sign/<?= htmlspecialchars($it['scope']) ?>/<?= (int)$it['entity_id'] ?>">Sign / View</a></span>
			</div>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>

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
						<span class="pn-unit-type"><?= htmlspecialchars(ucfirst($unit['Type'] ?? '')) ?></span>
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
					<i class="fas fa-calendar-check"></i><span class="pn-tab-label"> Attendance</span> <span class="pn-tab-count" id="pn-att-tab-count"></span>
				</li>
				<?php $_showRecs = $ShowRecsTab || !empty($ShowRecsTabLoggedIn); ?>
			<?php if ($_showRecs): ?><li data-tab="recommendations">
					<i class="fas fa-star"></i><span class="pn-tab-label"> Recommendations</span> <span class="pn-tab-count" id="pn-recs-tab-count"></span>
				</li><?php endif; ?>
				<li data-tab="history">
					<i class="fas fa-sticky-note"></i><span class="pn-tab-label"> Notes</span> <span class="pn-tab-count" id="pn-notes-tab-count"></span>
				</li>
				<li data-tab="classes">
					<i class="fas fa-shield-alt"></i><span class="pn-tab-label"> Class Levels</span>
				</li>
			</ul>
			<div class="pn-active-tab-label" id="pn-active-tab-label"><?= $isOwnProfile ? 'My Amtgard' : 'Awards' ?></div>

			<!-- My Amtgard Tab (own profile default) -->
			<?php if ($isOwnProfile): ?>
			<div class="pn-tab-panel" id="pn-tab-myamtgard">

				<?php if (!empty($Player['IsNewPlayer'])): ?>
				<div class="pna-welcome-banner">
					<div class="pna-welcome-banner-icon">⚔️</div>
					<div class="pna-welcome-banner-body">
						<div class="pna-welcome-banner-title">Welcome to Amtgard, <?= htmlspecialchars($Player['Persona']) ?>!</div>
						<div class="pna-welcome-banner-sub">We're glad you've joined <?= htmlspecialchars($Player['ParkName'] ?? 'your park') ?>. This page is your personal dashboard — track your attendance, class progress, awards, and more as you play.</div>
						<div class="pna-welcome-banner-tips">
							<span class="pna-welcome-tip"><i class="fas fa-clipboard-list"></i> Attend park days to earn credits</span>
							<span class="pna-welcome-tip"><i class="fas fa-shield-alt"></i> Credits advance your class levels</span>
							<span class="pna-welcome-tip"><i class="fas fa-camera"></i> Upload a Player Photo</span>
							<span class="pna-welcome-tip"><i class="fas fa-calendar-alt"></i> Navigate to your Kingdom to check for Events</span>
						</div>
					</div>
				</div>
				<?php endif; ?>

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
						<?php $_maFirstDate = (!empty($Player['PlayerSinceDate']) && $Player['PlayerSinceDate'] !== '0000-00-00' && $Player['PlayerSinceDate'] !== '1970-01-01') ? $Player['PlayerSinceDate'] : null; ?>
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
							<div class="pna-tenure-info-btn" tabindex="0" role="button" aria-label="Tenure info"><i class="fas fa-info"></i><div class="pna-tenure-info-text">This is based on your first Amtgard credit date. If it is incorrect, reach out to your PM to set your Amtgard Birth Date correctly.</div></div>
						</div>
						<?php endif; ?>

						<!-- Class Progress (populated by attendance AJAX) -->
						<div id="pna-class-progress-body"></div>

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

						<!-- Recent Sign-ins (populated by attendance AJAX) -->
						<div id="pna-recent-att-body"></div>

						<!-- Recent Awards (100 days) -->
						<?php
						$_ma60awd = date('Y-m-d', strtotime('-100 days'));
						$_maRecAwd = array_values(array_filter($_maDash_awd, function($a) use ($_ma60awd) {
							return !$a['IsTitle'] && !empty($a['Date']) && $a['Date'] >= $_ma60awd;
						}));
						usort($_maRecAwd, function($a, $b) {
							$nameA = trimlen($a['CustomAwardName'] ?? '') > 0 ? $a['CustomAwardName'] : (trimlen($a['KingdomAwardName'] ?? '') > 0 ? $a['KingdomAwardName'] : ($a['Name'] ?? ''));
							$nameB = trimlen($b['CustomAwardName'] ?? '') > 0 ? $b['CustomAwardName'] : (trimlen($b['KingdomAwardName'] ?? '') > 0 ? $b['KingdomAwardName'] : ($b['Name'] ?? ''));
							$nameCmp = strcmp($nameA, $nameB);
							if ($nameCmp !== 0) return $nameCmp;
							return (int)($b['Rank'] ?? 0) - (int)($a['Rank'] ?? 0);
						});
						$_maRecAwd = array_slice($_maRecAwd, 0, 5);
						?>
						<?php if (!empty($_maRecAwd)): ?>
						<div class="pna-card">
							<div class="pna-card-title"><i class="fas fa-medal"></i> Recent Awards <a class="pna-card-more" href="#" onclick="pnActivateTab('awards');return false;">All <?= $Stats['TotalAwards'] ?> &rarr;</a></div>
							<div class="pna-congrats-banner"><i class="fas fa-trophy"></i> Congratulations on your recent awards!</div>
							<?php foreach ($_maRecAwd as $_aw): ?>
							<div class="pna-feed-row">
								<span class="pna-feed-date"><?= date('M j, Y', strtotime($_aw['Date'])) ?></span>
								<?php $_awName = trimlen($_aw['CustomAwardName'] ?? '') > 0 ? $_aw['CustomAwardName'] : (trimlen($_aw['KingdomAwardName'] ?? '') > 0 ? $_aw['KingdomAwardName'] : ($_aw['Name'] ?? '—')); ?>
								<span class="pna-feed-label"><?= htmlspecialchars($_awName) ?><?php if (valid_id($_aw['Rank'] ?? 0)): ?> <span class="pna-feed-rank"><?= (int)$_aw['Rank'] ?></span><?php endif; ?></span>
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
				<?php if ($canManageAwards): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('awards')"><i class="fas fa-plus"></i> Add Award</button>
					<?php if ($hasHistorical): ?>
				<a href="<?= UIR ?>Player/reconcile/<?= (int)$Player['MundaneId'] ?>"
				   class="pn-btn pn-btn-sm" style="background:#6b46c1;color:#fff;margin-left:8px">
					<i class="fas fa-history"></i> Reconcile Historical Awards
				</a>
				<?php endif; ?>
				<?php if (!empty($awardsList)): ?>
				<button class="pn-btn pn-btn-sm btn-danger-confirm" style="margin-left:8px" onclick="pnOpenRevokeAllModal()"><i class="fas fa-ban"></i> Revoke All</button>
				<?php endif; ?>
				</div>
				<?php elseif ($isOwnProfile && $hasHistoricalTip): ?>
				<div class="pn-tab-toolbar">
					<a href="<?= UIR ?>Player/reconcile/<?= (int)$Player['MundaneId'] ?>"
					   class="pn-btn pn-btn-sm" style="background:#2b6cb0;color:#fff">
						<i class="fas fa-history"></i> View Historical Awards
					</a>
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
						27  => [12],      // Order of the Warrior    → Warlord
						28  => [7],       // Order of the Jovius     → Master Jovius
						29  => [9],       // Order of the Mask       → Master Mask
						30  => [8],       // Order of the Zodiac     → Master Zodiac
						32  => [10],      // Order of the Hydra      → Master Hydra
						33  => [11],      // Order of the Griffin    → Master Griffin
						239 => [240],     // Order of the Crown      → Master Crown
						243 => [244],     // Order of Battle         → Battlemaster
					];
					$pnOrderNames = [
						21  => ['Order of the Rose',    'Rose'],
						22  => ['Order of the Smith',   'Smith'],
						23  => ['Order of the Lion',    'Lion'],
						24  => ['Order of the Owl',     'Owl'],
						25  => ['Order of the Dragon',  'Dragon'],
						26  => ['Order of the Garber',  'Garber'],
						27  => ['Order of the Warrior', 'Warrior'],
						28  => ['Order of the Jovius',  'Jovius'],
						29  => ['Order of the Mask',    'Mask'],
						30  => ['Order of the Zodiac',  'Zodiac'],
						32  => ['Order of the Hydra',   'Hydra'],
						33  => ['Order of the Griffin', 'Griffin'],
						239 => ['Order of the Crown',   'Crown'],
						243 => ['Order of Battle',      'Battle'],
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
						if ($aid <= 0 || $aid === 31) continue; // 31 = Walker of the Middle
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
						if (!isset($pnLadderProgress[$aid])) {
							$pnLadderProgress[$aid] = ['Name' => $displayName, 'Short' => $shortName, 'Rank' => $rank,
								'RankSet' => $rank > 0 ? [$rank => true] : [], 'UnrankedCount' => $rank === 0 ? 1 : 0, 'HasMaster' => $hasMaster];
						} else {
							if ($rank > $pnLadderProgress[$aid]['Rank']) {
								$pnLadderProgress[$aid]['Rank'] = $rank;
							}
							if ($rank > 0) {
								$pnLadderProgress[$aid]['RankSet'][$rank] = true;
							} else {
								$pnLadderProgress[$aid]['UnrankedCount']++;
							}
						}
					}
					// Use max(highest_rank, effective_count) to account for unreconciled historical awards.
					// Effective count = distinct ranked entries + unranked entries (deduplicates duplicate ranks).
					// Cap at maxRank per award (10 for most, 12 for Zodiac)
					// Mark as approximate when effective count exceeds highest actual rank
					foreach ($pnLadderProgress as $_lpAid => &$lp) {
						$_lpMax = ($_lpAid === 30) ? 12 : 10;
						$_effectiveCount = count($lp['RankSet']) + $lp['UnrankedCount'];
						$lp['Approx'] = $_effectiveCount > $lp['Rank'];
						$lp['Rank'] = min($_lpMax, max($lp['Rank'], $_effectiveCount));
					}
					unset($lp);
					// Add a complete tile for any masterhood held with no corresponding ladder progress
					foreach ($pnOrderToMaster as $orderId => $masterIds) {
						if (isset($pnLadderProgress[$orderId])) continue;
						$hasMaster = false;
						foreach ($masterIds as $masterId) {
							if (isset($pnHeldAwardIds[$masterId])) { $hasMaster = true; break; }
						}
						if (!$hasMaster) continue;
						$maxRank = ($orderId === 30) ? 12 : 10;
						$name  = $pnOrderNames[$orderId][0] ?? 'Unknown Order';
						$short = $pnOrderNames[$orderId][1] ?? $name;
						$pnLadderProgress[$orderId] = ['Name' => $name, 'Short' => $short, 'Rank' => $maxRank, 'Count' => 0, 'HasMaster' => true, 'Approx' => false];
					}
					uasort($pnLadderProgress, function($a, $b) { return strcmp($a['Name'], $b['Name']); });
				?>
				<?php if (!empty($pnLadderProgress)): ?>
					<div style="display:flex;align-items:flex-start;gap:8px;margin-bottom:16px;">
						<div class="pn-ladder-grid" style="flex:1;min-width:0;margin-bottom:0">
							<?php foreach ($pnLadderProgress as $aid => $lp): ?>
								<?php $maxRank = ($aid === 30) ? 12 : 10; ?>
								<?php $pct = min(100, round($lp['Rank'] / $maxRank * 100)); ?>
								<div class="pn-ladder-item" title="<?= htmlspecialchars($lp['Name'] . ($lp['Approx'] ? ' (level approximated from historical data)' : '')) ?>" data-ladname="<?= htmlspecialchars($lp['Name']) ?>" style="cursor:pointer">
									<div class="pn-ladder-header">
										<span class="pn-ladder-name"><?= htmlspecialchars($lp['Short']) ?></span>
										<span style="display:flex;align-items:center;gap:4px;flex-shrink:0">
											<?php if ($lp['HasMaster']): ?>
												<span class="pn-ladder-master" title="Master title earned"><i class="fas fa-star"></i> M</span>
											<?php endif; ?>
											<span class="pn-ladder-rank"><?php if ($lp['Approx']): ?><span style="color:#b7791f">~</span><?php endif; ?><strong><?= $lp['Rank'] ?></strong> / <?= $maxRank ?></span>
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
				<div class="pn-table-toolbar">
					<?php if (count($filteredAwards) > 10): ?>
					<div class="pn-pagesize-bar" style="margin-bottom:0">
						<label for="pn-awards-pagesize">Show</label>
						<select id="pn-awards-pagesize" class="pn-pagesize-select" onchange="pnSetPageSize('pn-awards-table', this.value)">
							<option value="10">10</option>
							<option value="25">25</option>
							<option value="50">50</option>
							<option value="100">100</option>
							<option value="all">All</option>
						</select>
						<span>per page</span>
					</div>
					<?php endif; ?>
					<div class="pn-award-search-bar" style="margin-bottom:0">
						<i class="fas fa-search pn-award-search-icon"></i>
						<input type="text" id="pn-award-search" placeholder="Search awards…" class="pn-award-search-input" autocomplete="off" oninput="pnAwardSearch(this.value)" />
					</div>
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
							<?php if ($canManageAwards): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($filteredAwards as $detail): ?>
							<tr>
								<td class="pn-col-nowrap">
									<?php $displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName']; ?>
									<?= htmlspecialchars($displayName) ?>
									<?php if (trimlen($detail['Name'] ?? '') > 0 && $displayName != $detail['Name']): ?><span class="pn-award-base">[<?= htmlspecialchars($detail['Name']) ?>]</span><?php endif; ?>
								</td>
								<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/profile/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
								<td><?php if (valid_id($detail['EventId'])) echo $detail['EventName']; elseif (trimlen($detail['ParkName']) > 0) echo $detail['ParkName'] . (trimlen($detail['KingdomName']) > 0 ? ', ' . $detail['KingdomName'] : ''); else echo $detail['KingdomName']; ?></td>
								<td><?= $detail['Note'] ?></td>
								<td><a href="<?= UIR ?>Player/profile/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
								<?php if ($canManageAwards): ?>
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
				<?php if ($canManageAwards && !empty($RevokedAwards)): ?>
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
								<th class="pn-nosort"></th>
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
								<td class="pn-col-nowrap">
									<button class="pn-btn pn-btn-sm pn-reactivate-btn" data-awards-id="<?= (int)$rev['AwardsId'] ?>" title="Reactivate this award"><i class="fas fa-undo"></i> Reactivate</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<!-- Titles Tab -->
			<div class="pn-tab-panel" id="pn-tab-titles" style="display:none">
				<?php if ($canManageAwards): ?>
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
					<?php if (count($filteredTitles) > 10): ?>
					<div class="pn-pagesize-bar">
						<label for="pn-titles-pagesize">Show</label>
						<select id="pn-titles-pagesize" class="pn-pagesize-select" onchange="pnSetPageSize('pn-titles-table', this.value)">
							<option value="10">10</option>
							<option value="25">25</option>
							<option value="50">50</option>
							<option value="100">100</option>
						</select>
						<span>per page</span>
					</div>
					<?php endif; ?>
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
								<?php if ($canManageAwards): ?><th style="width:52px;min-width:52px"></th><?php endif; ?>
							</tr>
						</thead>
						<tbody>
							<?php foreach ($filteredTitles as $detail): ?>
									<tr>
									<td class="pn-col-nowrap">
										<?php $displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName']; ?>
										<?= htmlspecialchars($displayName) ?>
										<?php
											if (trimlen($detail['Name'] ?? '') > 0 && $displayName != $detail['Name']): ?>
												<span class="pn-award-base">[<?= htmlspecialchars($detail['Name']) ?>]</span>
										<?php endif; ?>
									</td>
									<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
									<td class="pn-col-nowrap">
										<?php
											$_isPeerageTitleRow = in_array($detail['Peerage'] ?? '', ['Squire', 'Man-At-Arms', 'Lords-Page', 'Page']);
											$_givenByMissing = $_isPeerageTitleRow && !trimlen($detail['GivenBy']);
										?>
										<?php if (!$_givenByMissing): ?>
											<a href="<?= UIR ?>Player/profile/<?= $detail['GivenById'] ?>"><?= htmlspecialchars(substr($detail['GivenBy'], 0, 30)) ?></a>
										<?php else: ?>
											<span class="pn-givenby-warn">
												<i>(Unknown)</i>
												<span class="pn-tip-icon">?</span>
												<span class="pn-tip-box">It looks like the persona record isn&rsquo;t set on this title, so the ORK doesn&rsquo;t know who gave you this. Ask your park monarchy to correct it with the proper Given By individual.</span>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php
											if (valid_id($detail['EventId'])) {
												echo htmlspecialchars($detail['EventName']);
											} else {
												echo (trimlen($detail['ParkName']) > 0) ? htmlspecialchars($detail['ParkName']) . (trimlen($detail['KingdomName']) > 0 ? ', ' . htmlspecialchars($detail['KingdomName']) : '') : htmlspecialchars($detail['KingdomName']);
											}
										?>
									</td>
									<td><?= $detail['Note'] ?></td>
									<td><a href="<?= UIR ?>Player/profile/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
									<?php if ($canManageAwards): ?>
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
				<?php if ($canManageAwards && !empty($RevokedTitles)): ?>
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
								<th class="pn-nosort"></th>
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
								<td class="pn-col-nowrap">
									<button class="pn-btn pn-btn-sm pn-reactivate-btn" data-awards-id="<?= (int)$rev['AwardsId'] ?>" title="Reactivate this title"><i class="fas fa-undo"></i> Reactivate</button>
								</td>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<?php endif; ?>
			</div>

			<!-- Attendance Tab (populated by attendance AJAX) -->
			<div class="pn-tab-panel" id="pn-tab-attendance" style="display:none">
				<?php if ($canEditAdmin): ?>
				<div style="display:flex;justify-content:flex-end;margin-bottom:12px">
					<button class="pn-btn pn-btn-primary" onclick="pnOpenPlayerAttModal()"><i class="fas fa-plus"></i> Add Attendance</button>
				</div>
				<?php endif; ?>
				<div id="pn-attendance-body"><div class="pn-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div>
			</div>
			<?php $canEditAnyAttendance = $canEditAdmin; ?>

			<!-- Recommendations Tab -->
			<?php if ($_showRecs): ?><div class="pn-tab-panel" id="pn-tab-recommendations" style="display:none">
				<?php if ($this->__session->user_id): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenModal()"><i class="fas fa-plus"></i> Recommend an Award</button>
				</div>
				<?php endif; ?>
				<div id="pn-recs-body"><div class="pn-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div>
			</div><?php endif; ?>

			<!-- Notes Tab -->
			<div class="pn-tab-panel" id="pn-tab-history" style="display:none">
				<?php if ($canEditAdmin): ?>
				<div class="pn-notes-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAddNoteModal()"><i class="fas fa-plus"></i> Add Note</button>
				</div>
				<?php endif; ?>
				<div id="pn-notes-body"><div class="pn-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div>
			</div>

			<!-- Class Levels Tab -->
			<div class="pn-tab-panel" id="pn-tab-classes" style="display:none">
				<?php
					$classList = is_array($Details['Classes']) ? $Details['Classes'] : array();
					// class_id → Paragon award_id
					// $pnClassToParagon and $pnHeldAwardIds are pre-computed in the template preamble
				?>
				<?php if ($canManageAwards): ?>
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
				<div id="pn-img-remove-confirm" style="display:none;margin-top:10px;padding:10px;background:#fff5f5;border:1px solid #fed7d7;border-radius:6px;font-size:13px;color:#c53030;text-align:left">
					<span id="pn-img-remove-confirm-text">Remove this image?</span>
					<div style="margin-top:8px;display:flex;gap:8px">
						<button type="button" class="pn-btn pn-btn-ghost pn-btn-sm" onclick="document.getElementById('pn-img-remove-confirm').style.display='none'">Cancel</button>
						<button type="button" class="pn-btn pn-btn-sm" id="pn-img-remove-confirm-btn" style="background:#e53e3e;color:#fff">Yes, Remove</button>
					</div>
				</div>
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
				<label for="pn-acct-persona">Persona <span class="required-indicator">*</span></label>
				<input type="text" id="pn-acct-persona" name="Persona" value="<?= htmlspecialchars($Player['Persona']) ?>" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-email">Email</label>
				<input type="email" id="pn-acct-email" name="Email" value="<?= htmlspecialchars($Player['Email'] ?? '') ?>" />
				<div id="pn-acct-email-warn" style="display:none;color:#e53e3e;font-size:0.82rem;margin-top:4px;">Double check the format of your email address.</div>
				<div id="pn-acct-email-suggestion" class="esc-suggestion" role="alert">
					<i class="fas fa-magic"></i>
					<span class="esc-suggestion-text">Did you mean <strong></strong>?</span>
					<button type="button" class="esc-suggestion-use">Use it</button>
					<button type="button" class="esc-suggestion-dismiss" aria-label="Dismiss">&times;</button>
				</div>
			</div>
			<div class="pn-acct-field">
				<label for="pn-acct-username">Username <span class="required-indicator">*</span></label>
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

			<div class="pn-acct-field">
				<label>
					<input type="checkbox" name="Restricted" value="Restricted" <?= $Player['Restricted'] == 1 ? 'checked' : '' ?> style="margin-right:6px" />
					Restrict Mundane Name Visibility
				</label>
				<small style="display:block;color:var(--ork-text-muted);margin-top:4px;padding-left:22px">Hides your real name from searches and public displays.</small>
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
				<div id="pn-dues-history-body"><div class="pn-dues-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div>
			</div>

			<div class="pn-acct-field">
				<label for="pn-dues-from">Date Paid <span class="required-indicator">*</span></label>
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
			<div id="pn-dues-history-modal-body"><div class="pn-dues-modal-empty"><i class="fas fa-spinner fa-spin"></i> Loading…</div></div>
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
<?php if ($canManageAwards): ?>
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
				<button type="button" class="pn-award-type-btn" id="pn-award-type-achievements">
					<i class="fas fa-star" style="margin-right:5px"></i>Achievement Titles
				</button>
				<button type="button" class="pn-award-type-btn" id="pn-award-type-associations">
					<i class="fas fa-handshake" style="margin-right:5px"></i>Associations
				</button>
			</div>

			<!-- Award Select -->
			<div class="pn-acct-field">
				<label for="pn-award-select" id="pn-award-select-label">Award <span class="required-indicator">*</span></label>
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
				<label>Rank <span id="pn-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; light blue = already held, green border = suggested; dark blue = selected</span></label>
				<div class="pn-rank-pills-wrap" id="pn-rank-pills"></div>
				<input type="hidden" name="Rank" id="pn-award-rank-val" value="" />
			</div>

			<!-- Date -->
			<div class="pn-acct-field">
				<label for="pn-award-date">Date <span class="required-indicator">*</span></label>
				<input type="date" name="Date" id="pn-award-date" />
			</div>

			<!-- Given By -->
			<div class="pn-acct-field">
				<label>Given By <span class="required-indicator">*</span></label>
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
				<div id="pn-award-givenby-note" style="display:none;margin-top:6px;padding:8px 12px;background:#ebf8ff;border:1px solid #bee3f8;border-radius:6px;color:#2b6cb0;font-size:12px;line-height:1.5;"><i class="fas fa-info-circle" style="margin-right:5px"></i>This should reflect the person granting the association. For example, if a Knight is taking a Squire, enter the Knight's name here.</div>
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
				<input type="hidden" name="KingdomId" id="pn-award-kingdom-id" value="<?= (int)($KingdomId ?? 0) ?>" />
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
<?php if ($canManageAwards): ?>
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
						<label>Target Award <span class="required-indicator">*</span></label>
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
				<label for="pn-edit-award-date">Date <span class="required-indicator">*</span></label>
				<input type="date" id="pn-edit-award-date" />
			</div>

			<div class="pn-acct-field">
				<label>Given By <span class="required-indicator">*</span></label>
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
					<label for="pn-rec-award">Award <span class="required-indicator">*</span></label>
					<select name="KingdomAwardId" id="pn-rec-award">
						<option value="">Select award...</option>
						<?= $AwardOptions ?>
					</select>
					<div id="pn-rec-award-desc" class="pn-rec-award-desc" style="display:none"></div>
				</div>
				<div class="pn-rec-field" id="pn-rec-rank-row" style="display:none">
					<label>Rank <span id="pn-rec-rank-hint" style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; light blue = already held, green border = suggested; dark blue = selected</span></label>
					<div class="pn-rank-pills-wrap" id="pn-rec-rank-pills"></div>
					<input type="hidden" name="Rank" id="pn-rec-rank-val" value="" />
				</div>
				<div class="pn-form-error" id="pn-rec-warn" style="margin-top:4px"></div>
				<div class="pn-rec-field">
					<label for="pn-rec-reason">Reason <span class="required-indicator">*</span></label>
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
// and the set of KingdomAwardIds the player already holds (for title duplicate detection)
$playerAwardRanks        = array();
$playerHeldKingdomAwardIds = array();
if (is_array($Details['Awards'])) {
	foreach ($Details['Awards'] as $a) {
		$aid  = (int)$a['AwardId'];
		$rank = (int)$a['Rank'];
		if ($aid > 0 && $rank > 0) {
			if (!isset($playerAwardRanks[$aid]) || $rank > $playerAwardRanks[$aid]) {
				$playerAwardRanks[$aid] = $rank;
			}
		}
		$kaid = (int)($a['KingdomAwardId'] ?? 0);
		if ($kaid > 0) {
			$playerHeldKingdomAwardIds[$kaid] = true;
		}
	}
}
$playerHeldKingdomAwardIds = array_keys($playerHeldKingdomAwardIds);
?>

<!-- =============================================
     JavaScript
     ============================================= -->
<script>
(function() {
    var btn = document.querySelector('.pna-tenure-info-btn');
    if (!btn) return;
    var tip = btn.querySelector('.pna-tenure-info-text');
    if (!tip) return;
    function show() {
        var r = btn.getBoundingClientRect();
        tip.style.display = 'block';
        var left = r.left + r.width / 2 - 130;
        if (left < 8) left = 8;
        if (left + 260 > window.innerWidth - 8) left = window.innerWidth - 268;
        var top = r.top - tip.offsetHeight - 8;
        if (top < 8) top = r.bottom + 8;
        tip.style.left = left + 'px';
        tip.style.top  = top + 'px';
    }
    function hide() { tip.style.display = 'none'; }
    btn.addEventListener('mouseenter', show);
    btn.addEventListener('focus',      show);
    btn.addEventListener('mouseleave', hide);
    btn.addEventListener('blur',       hide);
})();

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
	canManageAwards:<?= !empty($canManageAwards) ? 'true' : 'false' ?>,
	classList:      <?= json_encode(array_values(array_map(function($c) { return ['ClassId' => (int)$c['ClassId'], 'ClassName' => $c['ClassName'], 'Credits' => (float)($c['Credits'] ?? 0), 'Reconciled' => (int)($c['Reconciled'] ?? 0)]; }, $classList ?? []))) ?>,
	awardRanks:     <?= json_encode($playerAwardRanks) ?>,
	heldKingdomAwardIds: <?= json_encode($playerHeldKingdomAwardIds) ?>,
	awardOptHTML:   <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>,
	officerOptHTML: <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>,
	preloadOfficers:<?= json_encode($PreloadOfficers ?? []) ?>,
	playerParkName:   <?= json_encode($Player['Park'] ?? $Player['ParkName'] ?? '') ?>,
	playerPersona:    <?= json_encode($Player['Persona'] ?? '') ?>,
	duesPeriodType:   <?= json_encode($_duesPeriodType) ?>,
	duesPeriod:       <?= (int)$_duesPeriod ?>,
	canCreateUnit:    <?= (!empty($canEditAdmin) || !empty($isOwnProfile)) && !empty($LoggedIn) ? 'true' : 'false' ?>,
	lastClassId:      <?= $_lastClassId ?>,
	attendanceDates:  [],  // populated async by PlayerAjax/attendance
	canEditAnyAttendance: <?= !empty($canEditAdmin) ? 'true' : 'false' ?>,
	isOwnProfile:     <?= !empty($isOwnProfile) ? 'true' : 'false' ?>,
	kingdomUrl:       <?= json_encode(UIR . 'Kingdom/profile/' . (int)($KingdomId ?? 0)) ?>,
	classToParagon:   <?= json_encode($pnClassToParagon) ?>,
	heldAwardIds:     <?= json_encode(array_keys($pnHeldAwardIds)) ?>,
	canDeleteRec:   <?= !empty($can_delete_recommendation) ? 'true' : 'false' ?>,
	showRecsTab:    <?= !empty($ShowRecsTab) ? 'true' : 'false' ?>,
	loggedInUserId: <?= isset($this->__session->user_id) ? (int)$this->__session->user_id : 0 ?>,
};
// Use the viewed player's kingdom for nav search prioritization if the user has no home kingdom
if (typeof nsKid !== 'undefined' && nsKid === 0 && PnConfig.kingdomId) nsKid = PnConfig.kingdomId;
</script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/email-spell-checker.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script>
pnSortDesc($('#pn-awards-table'), 2, 'date', 1, 'numeric');     pnPaginate($('#pn-awards-table'), 1);
pnSortDesc($('#pn-titles-table'), 2, 'date', 1, 'numeric');     pnPaginate($('#pn-titles-table'), 1);
pnSortDesc($('#pn-history-table'), 2, 'date');    pnPaginate($('#pn-history-table'), 1);
// 26-week sparkline (called on load and again after attendance AJAX)
function pnRenderSparkline() {
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
}
pnRenderSparkline();
</script>

<?php if ($canManageAwards): ?>
<!-- Revoke Award Modal -->
<div class="pn-overlay" id="pn-award-revoke-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-ban" style="margin-right:8px;color:#b7791f"></i>Revoke Award</h3>
			<button class="pn-modal-close-btn" id="pn-revoke-award-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div style="background:#fff5f5;border:1px solid #fc8181;color:#9b2c2c;border-radius:6px;padding:10px 12px;margin-bottom:14px;font-size:12px;line-height:1.5;">
				<i class="fas fa-exclamation-triangle" style="margin-right:6px;color:#e53e3e"></i>
				<strong>Revoke Award</strong> is designed for situations where an award is being intentionally stripped from a player, not for deleting erroneous awards. Use the delete (<i class="fas fa-trash"></i>) function for that purpose.
			</div>
			<div id="pn-revoke-award-feedback" style="display:none"></div>
			<div class="pn-revoke-award-name" id="pn-revoke-award-name"></div>
			<div class="pn-acct-field">
				<label for="pn-revoke-reason">Revocation Reason <span class="required-indicator">*</span></label>
				<textarea id="pn-revoke-reason" rows="3" maxlength="300" placeholder="Why is this award being revoked?"></textarea>
				<span class="pn-char-count" id="pn-revoke-char-count">300 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-revoke-award-cancel">Cancel</button>
			<button class="pn-btn btn-danger-confirm" id="pn-revoke-award-save"><i class="fas fa-ban"></i> Revoke Award</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($canEditNotes): ?>
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
				<label for="pn-note-title">Note Title <span class="required-indicator">*</span></label>
				<input type="text" id="pn-note-title" maxlength="200" placeholder="e.g. Promotion, Warning, Waypoint Import" />
			</div>
			<div class="pn-acct-field">
				<label for="pn-note-desc">Description</label>
				<textarea id="pn-note-desc" rows="3" maxlength="1000" placeholder="Optional additional details..."></textarea>
			</div>
			<div class="pn-addnote-date-row">
				<div class="pn-acct-field" style="flex:1">
					<label for="pn-note-date">Date <span class="required-indicator">*</span></label>
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
			<button class="pn-btn pn-btn-primary" id="pn-addnote-save" disabled><i class="fas fa-save"></i> Add Note</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Player Add Attendance Modal -->
<style>
#pn-player-att-overlay .pn-modal-body { overflow:visible; }
#pn-player-att-overlay .pn-acct-field { position:relative; }
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
				<label id="pn-moveplayer-park-label">New Home Park <span class="required-indicator">*</span></label>
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
			<button class="pn-btn btn-danger-confirm" id="pn-move-submit" disabled><i class="fas fa-arrows-alt"></i> Move Player</button>
		</div>
	</div>
</div>
<?php endif; ?>

<?php if ($canManageAwards): ?>
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
				<label for="pn-revoke-all-reason">Revocation Reason <span class="required-indicator">*</span></label>
				<textarea id="pn-revoke-all-reason" rows="3" maxlength="300" placeholder="Why are all awards being revoked?"></textarea>
				<span class="pn-char-count" id="pn-revoke-all-char-count">300 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-revoke-all-cancel">Cancel</button>
			<button class="pn-btn btn-danger-confirm" id="pn-revoke-all-save" disabled><i class="fas fa-ban"></i> Revoke All Awards</button>
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
		<div style="background:var(--ork-bg-secondary,#ebf8ff);border-bottom:1px solid var(--ork-border,#bee3f8);padding:10px 16px;display:flex;align-items:flex-start;gap:8px;font-size:12px;color:var(--ork-text-secondary,#2c5282);line-height:1.5;">
			<i class="fas fa-info-circle" style="margin-top:2px;flex-shrink:0;color:#3182ce;"></i>
			<span>This creates a <strong>brand new</strong> Company or Household with you as the manager. To join an existing unit, ask its manager to add you.</span>
		</div>
		<form method="post" action="<?= UIR ?>Unit/create/<?= (int)$Player['MundaneId'] ?>" id="pn-unit-create-form">
			<input type="hidden" name="Action" value="create">
			<div class="pn-acct-modal-body">
				<div class="pn-acct-field">
					<label>Name <span class="required-indicator">*</span></label>
					<input type="text" name="Name" required placeholder="Enter a name…" autocomplete="off" id="pn-unit-create-name">
				</div>
				<div class="pn-acct-field">
					<label>Type</label>
					<select name="Type" id="pn-unit-create-type">
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
				<button type="button" class="pn-btn pn-btn-primary" id="pn-unit-create-submit-btn"><i class="fas fa-plus"></i> Create</button>
			</div>
		</form>
	</div>
</div>

<!-- Unit Create Confirmation Dialog -->
<div class="pn-overlay" id="pn-unit-confirm-overlay">
	<div class="pn-modal-box" style="width:420px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-shield-alt" style="margin-right:8px;color:#2c5282"></i>Confirm Creation</h3>
		</div>
		<div class="pn-modal-body" style="padding:20px;">
			<p style="margin:0 0 8px;font-size:14px;color:var(--ork-text,#2d3748);">
				You are about to create a new <strong id="pn-unit-confirm-type"></strong> named <strong id="pn-unit-confirm-name"></strong>.
			</p>
			<p style="margin:0;font-size:13px;color:var(--ork-text-muted,#718096);">
				You will become its manager. Other players must be added by a manager — they cannot join on their own.
			</p>
		</div>
		<div class="pn-modal-footer">
			<button type="button" class="pn-btn pn-btn-secondary" id="pn-unit-confirm-back">Go Back</button>
			<button type="button" class="pn-btn pn-btn-primary" id="pn-unit-confirm-yes"><i class="fas fa-check"></i> Yes, Create It</button>
		</div>
	</div>
</div>
<?php endif; ?>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
	// Voting eligibility badge — loaded async so it doesn't block page render
	if (PnConfig.playerId) {
		$.getJSON(PnConfig.uir + 'PlayerAjax/voting_eligible/' + PnConfig.playerId, function(r) {
			if (r.status === 0 && r.eligible) {
				var sub = '';
				if (r.province_mode)     sub = r.province_eligible ? 'Province &amp; Kingdom' : 'Kingdom';
				else if (r.active_knight) sub = 'Active Knight';
				else if (r.active_member === false) sub = 'Contributing';
				var $sub = $('#pn-voting-badge-sub');
				if (sub) { $sub.html(sub).show(); }
				$('#pn-voting-badge').show();
			}
		});
	}

	// ---- Attendance lazy loading (fires immediately on page load) ----
	(function() {
		if (!PnConfig.playerId) return;
		$.getJSON(PnConfig.uir + 'PlayerAjax/attendance/' + PnConfig.playerId, function(r) {
			if (r.status !== 0) return;
			var att = r.attendance || [];
			var total = r.total || 0;

			// Update PnConfig for use by other modals/JS
			PnConfig.attendanceDates = att.map(function(a) { return a.Date || ''; }).filter(Boolean);
			PnConfig.canEditAnyAttendance = !!r.canEditAnyAttendance;
			PnConfig.lastClassId = att.length && att[0].ClassId ? parseInt(att[0].ClassId) : 0;

			// Update stat card and tab count
			var statEl = document.getElementById('pn-att-stat-count');
			if (statEl) statEl.textContent = total;
			var tabEl = document.getElementById('pn-att-tab-count');
			if (tabEl) tabEl.textContent = '(' + total + ')';
			var lastEl = document.getElementById('pn-att-last-class');
			if (lastEl) lastEl.textContent = r.lastClass || '—';

			// Re-render sparkline now that dates are populated
			if (typeof pnRenderSparkline === 'function') pnRenderSparkline();

			// ---- Attendance tab ----
			var body = document.getElementById('pn-attendance-body');
			if (body) {
				var canEditAny = !!r.canEditAnyAttendance;
				var parkAuth   = r.parkEditAuth || {};
				var esc = function(s) { return $('<div>').text(s || '').html(); };
				var uir = PnConfig.uir;
				if (!att.length) {
					body.innerHTML = '<div class="pn-empty">No attendance records</div>';
				} else {
					var html = '<div class="pn-pagesize-bar"><label for="pn-attendance-pagesize">Show</label>'
						+ '<select id="pn-attendance-pagesize" class="pn-pagesize-select" onchange="pnSetPageSize(\'pn-attendance-table\', this.value)">'
						+ '<option value="10">10</option><option value="25">25</option><option value="50">50</option><option value="100">100</option>'
						+ '</select><span>per page</span></div>'
						+ '<table class="pn-table pn-sortable" id="pn-attendance-table"><thead><tr>'
						+ '<th data-sorttype="date">Date</th><th data-sorttype="text">Kingdom</th>'
						+ '<th data-sorttype="text">Park</th><th data-sorttype="text">Event</th>'
						+ '<th data-sorttype="text">Class</th><th data-sorttype="numeric">Credits</th>'
						+ (canEditAny ? '<th style="width:52px;min-width:52px"></th>' : '')
						+ '</tr></thead><tbody>';
					att.forEach(function(d) {
						var pid = parseInt(d.ParkId) || 0;
						var eid = parseInt(d.EventId) || 0;
						var ecid = parseInt(d.EventCalendarDetailId) || 0;
						var dateLink = pid > 0
							? '<a href="' + uir + 'Attendance/park/' + pid + '&AttendanceDate=' + esc(d.Date) + '">' + esc(d.Date) + '</a>'
							: '<a href="' + uir + 'Event/detail/' + eid + '/' + ecid + '">' + esc(d.Date) + '</a>';
						var classLabel = (d.Flavor && d.Flavor.trim()) ? esc(d.Flavor) : esc(d.ClassName);
						var canEditThis = !!(parkAuth[pid] && eid === 0);
						html += '<tr>'
							+ '<td class="pn-col-nowrap">' + dateLink + '</td>'
							+ '<td><a href="' + uir + 'Kingdom/profile/' + esc(d.KingdomId) + '">' + esc(d.KingdomName) + '</a></td>'
							+ '<td><a href="' + uir + 'Park/profile/' + pid + '">' + esc(d.ParkName) + '</a></td>'
							+ '<td>' + (eid > 0 ? '<a href="' + uir + 'Event/detail/' + eid + '/' + ecid + '">' + esc(d.EventName) + '</a>' : '') + '</td>'
							+ '<td>' + classLabel + '</td>'
							+ '<td class="pn-col-numeric">' + esc(d.Credits) + '</td>';
						if (canEditAny) {
							html += '<td class="pn-award-actions-cell">';
							if (canEditThis) {
								html += '<button class="pn-award-action-btn pn-award-edit-btn pn-att-edit-btn"'
									+ ' data-att-id="' + parseInt(d.AttendanceId) + '"'
									+ ' data-date="' + esc(d.Date) + '"'
									+ ' data-credits="' + parseFloat(d.Credits) + '"'
									+ ' data-class-id="' + parseInt(d.ClassId) + '"'
									+ ' data-mundane-id="' + parseInt(d.MundaneId) + '"'
									+ ' title="Edit attendance"><i class="fas fa-pencil-alt"></i></button>'
									+ ' <button class="pn-award-action-btn pn-award-del-btn pn-att-del-btn"'
									+ ' data-att-id="' + parseInt(d.AttendanceId) + '"'
									+ ' data-mundane-id="' + parseInt(d.MundaneId) + '"'
									+ ' title="Delete attendance"><i class="fas fa-trash"></i></button>';
							}
							html += '</td>';
						}
						html += '</tr>';
					});
					body.innerHTML = html + '</tbody></table>';
					pnSortDesc($('#pn-attendance-table'), 0, 'date');
					pnPaginate($('#pn-attendance-table'), 1);
				}
			}

			// ---- My Amtgard sections (own profile only) ----
			if (!PnConfig.isOwnProfile) return;

			// Class Progress
			var cpBody = document.getElementById('pna-class-progress-body');
			if (cpBody) {
				var recentClassIds = [], seen = {};
				att.forEach(function(a) {
					var cid = parseInt(a.ClassId) || 0;
					if (cid > 0 && !seen[cid]) { seen[cid] = true; recentClassIds.push(cid); }
				});
				recentClassIds = recentClassIds.slice(0, 3);
				var classList = PnConfig.classList || [];
				var classMap = {};
				classList.forEach(function(c) { classMap[parseInt(c.ClassId)] = c; });
				var classToParagon = PnConfig.classToParagon || {};
				var heldAwardIds = {};
				(PnConfig.heldAwardIds || []).forEach(function(id) { heldAwardIds[parseInt(id)] = true; });
				var maClasses = recentClassIds.map(function(cid) { return classMap[cid]; }).filter(function(c) {
					return c && ((parseInt(c.Credits||0) + parseInt(c.Reconciled||0)) > 0);
				});
				if (!maClasses.length) { cpBody.innerHTML = ''; return; }
				var maHtml = '<div class="pna-card"><div class="pna-card-title"><i class="fas fa-shield-alt"></i> Class Progress <a class="pna-card-more" href="#" onclick="pnActivateTab(\'classes\');return false;">All &rarr;</a></div><div style="font-size:11px;color:#a0aec0;margin-bottom:6px;">Your recent classes&hellip;</div>';
				var thresholds = [0,5,12,21,34,53];
				maClasses.forEach(function(mc) {
					var total = parseInt(mc.Credits||0) + parseInt(mc.Reconciled||0);
					var lvl = total>=53?6:total>=34?5:total>=21?4:total>=12?3:total>=5?2:1;
					var pct = total>=53?100:Math.round((total/thresholds[lvl])*100);
					var isMax = total >= 53;
					var next = thresholds[lvl] || 53;
					var parId = classToParagon[parseInt(mc.ClassId)] || 0;
					var hasPar = parId > 0 && !!heldAwardIds[parId];
					maHtml += '<div class="pna-class-row">'
						+ '<div class="pna-class-header">'
						+ '<span class="pna-class-name">' + $('<div>').text(mc.ClassName||'').html() + (hasPar ? ' <span class="pna-paragon-dot" title="Paragon"><i class="fas fa-crown"></i></span>' : '') + '</span>'
						+ '<span class="pna-class-level">L' + lvl + (isMax ? ' <i class="fas fa-star" style="color:#dd6b20" title="Max level"></i>' : '') + '</span>'
						+ '</div>'
						+ '<div class="pna-bar-wrap"><div class="pna-bar' + (isMax?' pna-bar-max':'') + '" style="width:' + pct + '%"></div></div>'
						+ '<div class="pna-class-credits">' + total + ' cr' + (!isMax ? ' &middot; ' + next + ' for L' + (lvl+1) : '') + '</div>'
						+ '</div>';
				});
				cpBody.innerHTML = maHtml + '</div>';
			}

			// Recent Sign-ins
			var raBody = document.getElementById('pna-recent-att-body');
			if (raBody) {
				var cutoff = new Date(); cutoff.setDate(cutoff.getDate() - 60);
				var cutStr = cutoff.getFullYear() + '-' + String(cutoff.getMonth()+1).padStart(2,'0') + '-' + String(cutoff.getDate()).padStart(2,'0');
				var recAtt = att.filter(function(a) { return a.Date && a.Date >= cutStr; }).slice(0, 5);
				var months2 = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
				var raHtml = '<div class="pna-card"><div class="pna-card-title"><i class="fas fa-calendar-check"></i> Recent Sign-ins <a class="pna-card-more" href="#" onclick="pnActivateTab(\'attendance\');return false;">All ' + total + ' &rarr;</a></div>';
				if (recAtt.length) {
					recAtt.forEach(function(ra) {
						var d2 = new Date(ra.Date + 'T00:00:00');
						var lbl = months2[d2.getMonth()] + ' ' + d2.getDate();
						raHtml += '<div class="pna-feed-row">'
							+ '<span class="pna-feed-date">' + lbl + '</span>'
							+ '<span class="pna-feed-label">' + $('<div>').text(ra.ClassName||'—').html() + '</span>'
							+ (ra.ParkName ? '<span class="pna-feed-sub">' + $('<div>').text(ra.ParkName).html() + '</span>' : '')
							+ '</div>';
					});
				} else {
					raHtml += '<div style="font-size:12px;color:#718096;line-height:1.5;">No recent sign-ins. Check out the next events and park days in your <a href="' + PnConfig.kingdomUrl + '" style="color:#4299e1;">kingdom</a>.</div>';
				}
				raBody.innerHTML = raHtml + '</div>';
			}
		});
	})();

	// ---- Dues history lazy loading ----
	function pnRenderDuesHtml(dues, isAdmin) {
		if (!dues || !dues.length) return '<div class="pn-dues-modal-empty">No dues records on file</div>';
		var html = '<table class="pn-dues-modal-table"><thead><tr><th>Park</th><th>From</th><th>Paid Through</th><th>Status</th>'
			+ (isAdmin ? '<th></th>' : '') + '</tr></thead><tbody>';
		dues.forEach(function(d) {
			var status;
			if (d.DuesForLife == 1) status = '<span class="pn-dues-life">Lifetime</span>';
			else if (d.Revoked) status = '<span style="color:#e53e3e">Revoked</span>';
			else if (d.DuesUntil && new Date(d.DuesUntil) < new Date()) status = '<span style="color:#999">Expired</span>';
			else status = '<span style="color:#38a169">Active</span>';
			var esc = function(s) { return $('<div>').text(s || '').html(); };
			html += '<tr>'
				+ '<td>' + esc(d.ParkName) + '</td>'
				+ '<td>' + esc(d.DuesFrom || '—') + '</td>'
				+ '<td>' + (d.DuesForLife == 1 ? '—' : esc(d.DuesUntil)) + '</td>'
				+ '<td>' + status + '</td>'
				+ (isAdmin ? '<td>' + (!d.Revoked ? '<button class="pn-dues-revoke-btn" data-dues-id="' + parseInt(d.DuesId) + '">Revoke</button>' : '') + '</td>' : '')
				+ '</tr>';
		});
		return html + '</tbody></table>';
	}
	var pnDuesCache = null;
	function pnLoadDuesInto(elId, isAdmin) {
		var el = document.getElementById(elId);
		if (!el) return;
		if (pnDuesCache !== null) { el.innerHTML = pnRenderDuesHtml(pnDuesCache, isAdmin); return; }
		$.getJSON(PnConfig.uir + 'PlayerAjax/all_dues/' + PnConfig.playerId, function(r) {
			pnDuesCache = (r.status === 0) ? (r.dues || []) : [];
			el.innerHTML = pnRenderDuesHtml(pnDuesCache, isAdmin);
		}).fail(function() { el.innerHTML = '<div class="pn-dues-modal-empty">Unable to load dues history.</div>'; });
	}
	if (typeof pnOpenDuesModal === 'function') {
		var _origDues = pnOpenDuesModal;
		pnOpenDuesModal = function() { _origDues(); pnLoadDuesInto('pn-dues-history-body', true); };
	}
	if (typeof pnOpenDuesHistoryModal === 'function') {
		var _origDuesH = pnOpenDuesHistoryModal;
		pnOpenDuesHistoryModal = function() { _origDuesH(); pnLoadDuesInto('pn-dues-history-modal-body', false); };
	}

	// ---- Notes lazy loading ----
	var pnNotesLoaded = false;
	function pnLoadNotes() {
		if (pnNotesLoaded) return;
		pnNotesLoaded = true;
		var body = document.getElementById('pn-notes-body');
		if (!body) return;
		$.getJSON(PnConfig.uir + 'PlayerAjax/notes/' + PnConfig.playerId, function(r) {
			var notes = (r.status === 0) ? (r.notes || []) : [];
			var countEl = document.getElementById('pn-notes-tab-count');
			if (countEl) countEl.textContent = '(' + notes.length + ')';
			if (!notes.length) { body.innerHTML = '<div class="pn-empty" id="pn-history-empty">No notes</div>'; return; }
			var esc = function(s) { return $('<div>').text(s || '').html(); };
			var html = '<table class="pn-table" id="pn-history-table"><thead><tr><th>Note</th><th>Description</th><th>Date</th>'
				+ (PnConfig.canEditAdmin ? '<th style="width:60px"></th>' : '') + '</tr></thead><tbody>';
			notes.forEach(function(n) {
				var nid = parseInt(n.NoteId) || 0;
				var dt = esc(n.Date || '');
				var dc = (n.DateComplete && n.DateComplete !== '0000-00-00') ? (' - ' + esc(n.DateComplete)) : '';
				html += '<tr data-notes-id="' + nid + '">'
					+ '<td>' + esc(n.Note) + '</td>'
					+ '<td>' + esc(n.Description) + '</td>'
					+ '<td class="pn-col-nowrap">' + dt + dc + '</td>';
				if (PnConfig.canEditAdmin) {
					html += '<td class="pn-award-actions-cell">'
						+ '<button class="pn-award-action-btn pn-award-edit-btn pn-note-edit-btn"'
						+ ' data-notes-id="' + nid + '"'
						+ ' data-note="' + esc(n.Note).replace(/"/g, '&quot;') + '"'
						+ ' data-desc="' + esc(n.Description).replace(/"/g, '&quot;') + '"'
						+ ' data-date="' + esc(n.Date) + '"'
						+ ' data-date-complete="' + esc(n.DateComplete) + '"'
						+ ' title="Edit note"><i class="fas fa-pencil-alt"></i></button> '
						+ '<button class="pn-award-action-btn pn-award-del-btn pn-note-del-btn" data-notes-id="' + nid + '" title="Delete note"><i class="fas fa-trash"></i></button>'
						+ '</td>';
				}
				html += '</tr>';
			});
			body.innerHTML = html + '</tbody></table>';
		}).fail(function() { body.innerHTML = '<div class="pn-empty">Unable to load notes.</div>'; });
	}

	// ---- Recommendations lazy loading ----
	var pnRecsLoaded = false;
	function pnLoadRecs() {
		if (pnRecsLoaded) return;
		pnRecsLoaded = true;
		var body = document.getElementById('pn-recs-body');
		if (!body) return;
		$.getJSON(PnConfig.uir + 'PlayerAjax/recommendations/' + PnConfig.playerId, function(r) {
			if (r.status === 5) { body.innerHTML = '<div class="pn-empty">Log in to see recommendations.</div>'; return; }
			var allRecs = (r.status === 0 && r.recs) ? r.recs.filter(function(x) { return !x.AlreadyHas; }) : [];
			var myRecs  = allRecs.filter(function(x) { return x.RecommendedById == PnConfig.loggedInUserId; });
			var recList = PnConfig.showRecsTab ? allRecs : myRecs;
			var countEl = document.getElementById('pn-recs-tab-count');
			if (countEl) countEl.textContent = '(' + recList.length + ')';
			if (!recList.length) { body.innerHTML = '<div class="pn-empty">There are no open award recommendations for <?= htmlspecialchars($Player['Persona'] ?? 'this player') ?>.</div>'; return; }
			var hasActions = PnConfig.loggedInUserId > 0;
			var esc = function(s) { return $('<div>').text(s || '').html(); };
			var html = '<table class="pn-table display" id="pn-rec-table"><thead><tr>'
				+ '<th>Award</th><th>Rank</th><th>Date</th><th>Sent By</th><th>Reason</th>'
				+ (hasActions ? '<th style="white-space:nowrap;width:1%">Actions</th>' : '')
				+ '</tr></thead><tbody>';
			recList.forEach(function(rec) {
				var kaid  = parseInt(rec.KingdomAwardId) || 0;
				var recId = parseInt(rec.RecommendationsId) || 0;
				var mid   = parseInt(rec.MundaneId) || 0;
				var rank  = rec.Rank && parseInt(rec.Rank) > 0 ? parseInt(rec.Rank) : '';
				html += '<tr>'
					+ '<td>' + esc(rec.AwardName) + '</td>'
					+ '<td class="pn-col-numeric">' + rank + '</td>'
					+ '<td class="pn-col-nowrap">' + esc(rec.DateRecommended) + '</td>'
					+ '<td><a href="' + PnConfig.uir + 'Player/profile/' + parseInt(rec.RecommendedById) + '">' + esc(rec.RecommendedByName) + '</a></td>'
					+ '<td>' + esc(rec.Reason) + '</td>';
				if (hasActions) {
					var actions = '';
					if (PnConfig.canManageAwards && kaid > 0) {
						var rd = JSON.stringify({KingdomAwardId: kaid, Rank: parseInt(rec.Rank)||0, Reason: rec.Reason||'', AwardName: rec.AwardName||''});
						actions += '<button class="pk-btn pk-btn-primary pn-rec-grant-btn" data-rec="' + rd.replace(/"/g, '&quot;') + '"><i class="fas fa-medal"></i> Grant</button> ';
					}
					var canDel = PnConfig.canDeleteRec || rec.RecommendedById == PnConfig.loggedInUserId || mid == PnConfig.loggedInUserId;
					if (canDel) actions += '<button class="pk-rec-dismiss-btn pn-rec-dismiss-btn" data-href="' + PnConfig.uir + 'Player/profile/' + mid + '/deleterecommendation/' + recId + '"><i class="fas fa-times"></i> Delete</button>';
					html += '<td class="pk-rec-actions">' + actions + '</td>';
				}
				html += '</tr>';
			});
			body.innerHTML = html + '</tbody></table>';
			if ($.fn.DataTable) {
				$('#pn-rec-table').DataTable({
					order: [[2, 'desc']],
					columnDefs: [{ targets: [2], type: 'date' }].concat(hasActions ? [{ targets: [-1], orderable: false, searchable: false }] : []),
					pageLength: 25
				});
			}
		}).fail(function() { body.innerHTML = '<div class="pn-empty">Unable to load recommendations.</div>'; });
	}

	// Hook tab clicks to trigger lazy loading
	$(document).on('click', '.pn-tab-nav li', function() {
		var tab = $(this).data('tab');
		if (tab === 'history')         pnLoadNotes();
		if (tab === 'recommendations') pnLoadRecs();
	});
});
initEmailSpellCheck('pn-acct-email', 'pn-acct-email-suggestion');
</script>