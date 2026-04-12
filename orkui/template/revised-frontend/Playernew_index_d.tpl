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
	$canEditImages  = $isOwnProfile || $canEditAdmin;
	$canEditAccount = $isOwnProfile || $canEditAdmin;

	// Display privacy: monarchy/admin always see; others see if player opted in
	$isLoggedIn = isset($this->__session->user_id) && (int)$this->__session->user_id > 0;
	$canSeePrivate = $isOwnProfile || $canEditAdmin;
	$showFirstName = $canSeePrivate || ($isLoggedIn && (int)($Player['ShowMundaneFirst'] ?? 1));
	$showLastName  = $canSeePrivate || ($isLoggedIn && (int)($Player['ShowMundaneLast'] ?? 1));
	$showEmail     = $canSeePrivate || ($isLoggedIn && (int)($Player['ShowEmail'] ?? 1));

	// Check if player has any reconcilable historical awards
	$hasHistorical = false;
	if ($canManageAwards && is_array($Details['Awards'])) {
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

<?php
	$_pnHeroBg = $isSuspended ? '#9b2c2c' : '#2c5282';
	if (!$isSuspended && !empty($Player['ColorPrimary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $Player['ColorPrimary'])) $_pnHeroBg = $Player['ColorPrimary'];
	$_pnAccent = (!empty($Player['ColorAccent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $Player['ColorAccent'])) ? $Player['ColorAccent'] : '#4299e1';
	$_pnColorSecondary = (!empty($Player['ColorSecondary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $Player['ColorSecondary'])) ? $Player['ColorSecondary'] : '';
	$_pnOverlay = in_array($Player['HeroOverlay'] ?? 'med', ['low','med','high']) ? ($Player['HeroOverlay'] ?? 'med') : 'med';
	$_pnOverlayOpacity = ['low' => '0.06', 'med' => '0.12', 'high' => '0.22'][$_pnOverlay];
	$_pnHeroCss = !empty($_pnColorSecondary)
		? "background: linear-gradient(135deg, $_pnHeroBg, $_pnColorSecondary)"
		: "background-color: $_pnHeroBg";
	$_pnFocusX = (int)($Player['PhotoFocusX'] ?? 50);
	$_pnFocusY = (int)($Player['PhotoFocusY'] ?? 50);
	$_pnFocusSize = max(15, (int)($Player['PhotoFocusSize'] ?? 100));

?>
<style>:root { --pn-hero-bg: <?= $_pnHeroBg ?>; --pn-accent: <?= $_pnAccent ?>; --pn-overlay-opacity: <?= $_pnOverlayOpacity ?>; }</style>
<?php
$_pnNameFont = !empty($Player['NameFont']) ? $Player['NameFont'] : '';
$_pnFontAllowed = ['Cinzel','Cinzel Decorative','IM Fell English','UnifrakturMaguntia','Metamorphous','Uncial Antiqua','Pirata One','Almendra','Pinyon Script','Great Vibes'];
if (!in_array($_pnNameFont, $_pnFontAllowed)) $_pnNameFont = '';
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<?php if ($_pnNameFont): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=<?= str_replace(' ', '+', htmlspecialchars($_pnNameFont)) ?>&display=swap">
<style>#pn-hero-persona,.pn-hero-preview-name,#pn-name-preview{font-family:'<?= htmlspecialchars($_pnNameFont) ?>',serif!important}</style>
<?php endif; ?>
<?php if (!empty($isOwnProfile)): ?>
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel&family=Cinzel+Decorative&family=IM+Fell+English&family=UnifrakturMaguntia&family=Metamorphous&family=Uncial+Antiqua&family=Pirata+One&family=Almendra&family=Pinyon+Script&family=Great+Vibes&display=swap">
<?php endif; ?>
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
/* ===== About Tab ===== */
.pn-about-section{margin-bottom:24px}
.pn-about-heading{font-size:18px;font-weight:700;color:#2d3748;margin:0 0 12px;background:transparent;border:none;padding:0;border-radius:0;text-shadow:none}
.pn-about-content{font-size:14px;line-height:1.7;color:#4a5568}
.pn-about-content h1,.pn-about-content h2,.pn-about-content h3,.pn-about-content h4,.pn-about-content h5,.pn-about-content h6{background:transparent;border:none;padding:0;border-radius:0;text-shadow:none;color:#2d3748;margin:16px 0 8px}
.pn-about-content p{margin:0 0 12px}
.pn-about-content a{color:var(--pn-accent,#4299e1)}
.pn-about-content blockquote{border-left:3px solid var(--pn-accent,#4299e1);margin:12px 0;padding:8px 16px;color:#718096;background:#f7fafc;border-radius:0 4px 4px 0}
.pn-about-content code{background:#edf2f7;padding:2px 6px;border-radius:3px;font-size:13px}
.pn-about-content pre{background:#2d3748;color:#e2e8f0;padding:14px;border-radius:6px;overflow-x:auto;margin:12px 0}
.pn-about-content pre code{background:transparent;padding:0;color:inherit}
.pn-about-content img{max-width:100%;height:auto;border-radius:6px}
.pn-about-content ul,.pn-about-content ol{margin:8px 0;padding-left:24px}
.pn-hero-subline{font-size:13px;color:rgba(255,255,255,0.7);margin-bottom:4px;display:flex;align-items:center;flex-wrap:wrap;gap:0}
.pn-sub-pronunciation{font-style:italic;color:rgba(255,255,255,0.6);letter-spacing:.02em}
.pn-sub-pronouns{font-style:italic;color:rgba(255,255,255,0.6)}
.pn-sub-sep{margin:0 6px;color:rgba(255,255,255,0.4);font-size:10px}
.pn-tooltip-trigger{position:relative;display:inline-flex}
.pn-about-empty{text-align:center;padding:40px 20px;color:#a0aec0;font-size:14px}
.pn-about-layout{display:flex;gap:24px;align-items:flex-start}
.pn-about-main{flex:1;min-width:0}
.pn-about-sidebar{flex:0 0 240px}
.pn-belt-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:14px 16px;margin-bottom:12px}
.pn-belt-card-title{font-size:13px;font-weight:700;color:#2d3748;margin-bottom:10px;display:flex;align-items:center;gap:6px}
.pn-belt-card-title i{color:var(--pn-accent,#4299e1);font-size:12px}
.pn-belt-group{font-size:10px;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:#a0aec0;padding:8px 0 3px;margin-top:4px;border-top:1px solid #f7fafc}
.pn-belt-group:first-of-type{border-top:none;margin-top:0;padding-top:0}
.pn-belt-row{display:flex;align-items:baseline;justify-content:space-between;padding:4px 0;gap:8px}
.pn-belt-name{font-size:13px;font-weight:600;color:var(--pn-accent,#4299e1);text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pn-belt-name:hover{text-decoration:underline}
.pn-belt-title{font-size:11px;color:#718096;white-space:nowrap;flex-shrink:0}
@media(max-width:700px){.pn-about-layout{flex-direction:column}.pn-about-sidebar{flex:none;width:100%}}
/* ===== Milestones Timeline ===== */
.pn-timeline-section{margin-top:32px;padding-top:24px;border-top:1px solid #e2e8f0}
.pn-timeline-heading{font-size:18px;font-weight:700;color:#2d3748;margin:0 0 20px;background:transparent;border:none;padding:0;border-radius:0;text-shadow:none;display:flex;align-items:center;gap:8px}
.pn-timeline-heading i{color:var(--pn-accent,#4299e1);font-size:16px}
.pn-timeline{position:relative;padding:10px 0 10px;margin:0}
.pn-timeline::before{content:'';position:absolute;left:50%;top:0;bottom:0;width:2px;background:#e2e8f0;transform:translateX(-50%)}
.pn-tl-item{position:relative;display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:24px}
.pn-tl-item:last-child{margin-bottom:0}
.pn-tl-left{width:calc(50% - 24px);text-align:right;padding-right:16px}
.pn-tl-right{width:calc(50% - 24px);text-align:left;padding-left:16px}
.pn-tl-node{position:absolute;left:50%;top:4px;width:36px;height:36px;border-radius:50%;background:#fff;border:2px solid var(--pn-accent,#4299e1);display:flex;align-items:center;justify-content:center;transform:translateX(-50%);z-index:1;font-size:14px;color:var(--pn-accent,#4299e1)}
.pn-tl-date{font-size:12px;color:var(--pn-accent,#4299e1);font-weight:600;line-height:1.4;padding-top:6px}
.pn-tl-desc{font-size:13px;color:#2d3748;font-weight:500;line-height:1.4;padding-top:6px}
.pn-tl-item:nth-child(odd) .pn-tl-left{order:1}
.pn-tl-item:nth-child(odd) .pn-tl-right{order:3}
.pn-tl-item:nth-child(odd) .pn-tl-node{order:2}
.pn-tl-item:nth-child(even) .pn-tl-left{order:3;text-align:left;padding-left:16px;padding-right:0}
.pn-tl-item:nth-child(even) .pn-tl-right{order:1;text-align:right;padding-right:16px;padding-left:0}
.pn-tl-item:nth-child(even) .pn-tl-node{order:2}
.pn-tl-empty{text-align:center;padding:24px 16px;color:#a0aec0;font-size:13px}
@media(max-width:700px){
.pn-timeline::before{left:18px}
.pn-tl-item{flex-wrap:nowrap}
.pn-tl-node{position:relative;left:auto;top:auto;transform:none;flex-shrink:0;order:1!important;width:32px;height:32px;font-size:12px}
.pn-tl-left{display:none}
.pn-tl-right{order:2!important;width:auto;flex:1;text-align:left!important;padding-left:12px!important;padding-right:0!important}
.pn-tl-item:nth-child(even) .pn-tl-left{display:none}
.pn-tl-item:nth-child(even) .pn-tl-right{order:2!important;text-align:left!important;padding-left:12px!important;padding-right:0!important}
.pn-tl-date-mobile{display:block;font-size:11px;color:var(--pn-accent,#4299e1);margin-top:2px;opacity:.8}
}
@media(min-width:701px){.pn-tl-date-mobile{display:none}}
/* ===== Milestones Config (Design Modal) ===== */
.pn-ms-toggle-list{display:flex;flex-direction:column;gap:8px;margin-bottom:20px}
.pn-ms-toggle{display:flex;align-items:center;gap:10px;font-size:13px;color:#4a5568}
.pn-ms-toggle input[type=checkbox]{width:16px;height:16px;accent-color:var(--pn-accent,#4299e1)}
.pn-ms-toggle i{width:20px;text-align:center;color:#718096;font-size:14px}
.pn-ms-custom-list{margin-top:12px;display:flex;flex-direction:column;gap:8px}
.pn-ms-custom-row{display:flex;align-items:center;gap:8px;padding:8px 10px;background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:12px}
.pn-ms-custom-row i{color:var(--pn-accent,#4299e1);font-size:14px;width:20px;text-align:center;flex-shrink:0}
.pn-ms-custom-desc{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#2d3748;font-weight:500}
.pn-ms-custom-date{color:#718096;font-size:11px;flex-shrink:0}
.pn-ms-custom-actions{display:flex;gap:4px;flex-shrink:0}
.pn-ms-custom-actions button{background:none;border:none;cursor:pointer;font-size:12px;color:#718096;padding:2px 4px;border-radius:3px}
.pn-ms-custom-actions button:hover{background:#e2e8f0;color:#2d3748}
.pn-ms-add-form{margin-top:16px;padding:14px;background:#f7fafc;border:1px solid #e2e8f0;border-radius:8px}
.pn-ms-add-row{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.pn-ms-add-row .pn-ms-field{display:flex;flex-direction:column;gap:3px}
.pn-ms-add-row .pn-ms-field label{font-size:11px;font-weight:600;color:#718096;text-transform:uppercase;letter-spacing:.04em}
.pn-ms-add-row .pn-ms-field input,.pn-ms-add-row .pn-ms-field select{font-size:12px;padding:6px 8px;border:1px solid #cbd5e0;border-radius:4px;background:#fff}
.pn-ms-add-row .pn-ms-field input[type=text]{width:180px}
.pn-ms-add-row .pn-ms-field input[type=date]{width:130px}
.pn-ms-add-btn{padding:6px 12px;font-size:12px;font-weight:600;background:var(--pn-accent,#4299e1);color:#fff;border:none;border-radius:4px;cursor:pointer;white-space:nowrap}
.pn-ms-add-btn:hover{opacity:.9}
.pn-ms-error{color:#e53e3e;font-size:12px;margin-top:6px;display:none}
.pn-ms-icon-grid{display:flex;flex-wrap:wrap;gap:4px}
.pn-ms-icon-opt{width:32px;height:32px;display:flex;align-items:center;justify-content:center;border:2px solid #e2e8f0;border-radius:6px;cursor:pointer;font-size:14px;color:#718096;background:#fff;transition:all .15s}
.pn-ms-icon-opt:hover{border-color:#a0aec0;color:#4a5568;background:#f7fafc}
.pn-ms-icon-opt.pn-ms-icon-active{border-color:var(--pn-accent,#4299e1);color:var(--pn-accent,#4299e1);background:#ebf8ff}
/* ===== Compact Milestones Sidebar ===== */
.pn-cms-card{background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:12px 14px;margin-top:12px}
.pn-cms-title{font-size:13px;font-weight:700;color:#2d3748;margin-bottom:8px;display:flex;align-items:center;gap:6px;background:transparent;border:none;padding:0;border-radius:0;text-shadow:none}
.pn-cms-title i{color:var(--pn-accent,#4299e1);font-size:12px}
.pn-cms-item{display:flex;align-items:center;gap:7px;padding:4px 0;border-bottom:1px solid #f0f4f8;font-size:12px}
.pn-cms-item:last-child{border-bottom:none;padding-bottom:0}
.pn-cms-icon{color:var(--pn-accent,#4299e1);font-size:12px;flex-shrink:0;width:16px;display:inline-flex;align-items:center;justify-content:center}
.pn-cms-line{flex:1;min-width:0;color:#4a5568;line-height:1.4;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pn-cms-line strong{color:#718096;font-weight:500;margin-right:2px}
/* ===== Design My Profile Modal ===== */
.pn-design-tabs{display:flex;border-bottom:2px solid #e2e8f0;margin-bottom:18px;gap:0}
.pn-design-tab{padding:10px 18px;font-size:13px;font-weight:600;color:#718096;cursor:pointer;border-bottom:2px solid transparent;margin-bottom:-2px;background:none;border-top:none;border-left:none;border-right:none;white-space:nowrap}
.pn-design-tab:hover{color:#2d3748}
.pn-design-tab.pn-active{color:var(--pn-accent,#4299e1);border-bottom-color:var(--pn-accent,#4299e1)}
.pn-design-panel{display:none}
.pn-design-panel.pn-active{display:block}
.pn-design-field{margin-bottom:16px}
.pn-design-field label{display:block;font-size:12px;font-weight:600;color:#4a5568;margin-bottom:5px}
.pn-design-field textarea{width:100%;min-height:100px;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;font-size:14px;font-family:inherit;resize:vertical;box-sizing:border-box}
.pn-design-field textarea:focus{outline:none;border-color:var(--pn-accent,#4299e1);box-shadow:0 0 0 3px rgba(66,153,225,0.15)}
.pn-design-field input[type="text"],.pn-design-field select{width:100%;border:1px solid #e2e8f0;border-radius:6px;padding:8px 12px;font-size:14px;box-sizing:border-box}
.pn-design-field input[type="text"]:focus,.pn-design-field select:focus{outline:none;border-color:var(--pn-accent,#4299e1);box-shadow:0 0 0 3px rgba(66,153,225,0.15)}
.pn-design-hint{font-size:11px;color:#a0aec0;margin-top:4px}
.pn-design-preview-label{font-size:11px;font-weight:700;color:#718096;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.pn-color-presets{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
.pn-color-swatch{width:36px;height:36px;border-radius:50%;border:3px solid transparent;cursor:pointer;transition:border-color .15s,transform .15s}
.pn-color-swatch:hover{transform:scale(1.1)}
.pn-color-swatch.pn-selected{border-color:#2d3748;box-shadow:0 0 0 2px #fff,0 0 0 4px #2d3748}
.pn-color-row{display:flex;gap:16px;align-items:flex-start;margin-bottom:12px}
.pn-color-col{flex:1;min-width:0}
.pn-color-input-wrap{display:flex;align-items:center;gap:8px}
.pn-color-input-wrap input[type="color"]{width:40px;height:34px;border:1px solid #e2e8f0;border-radius:6px;padding:2px;cursor:pointer;background:#fff}
.pn-color-input-wrap input[type="text"]{width:80px;font-family:monospace;font-size:13px}
.pn-hero-preview{border-radius:8px;padding:16px 20px;color:#fff;margin:12px 0;position:relative;overflow:hidden;min-height:60px}
.pn-hero-preview-name{font-size:18px;font-weight:700;text-shadow:0 1px 3px rgba(0,0,0,0.4)}
.pn-hero-preview-sub{font-size:12px;opacity:0.7;margin-top:4px}
.pn-overlay-btn{padding:8px 20px;border:2px solid #e2e8f0;border-radius:6px;background:#fff;font-size:12px;font-weight:600;color:#4a5568;cursor:pointer;transition:all .15s}
.pn-overlay-btn:hover{border-color:#a0aec0}
.pn-overlay-btn.pn-active{border-color:var(--pn-accent,#4299e1);background:var(--pn-accent,#4299e1);color:#fff}
.pn-comma-toggle{width:32px;height:32px;border:2px solid #cbd5e0;border-radius:6px;background:#f7fafc;font-size:16px;font-weight:700;color:#a0aec0;cursor:pointer;transition:all .15s;font-family:inherit;line-height:1;display:flex;align-items:center;justify-content:center;padding:0}
.pn-comma-toggle:hover{border-color:#a0aec0;background:#edf2f7}
.pn-comma-toggle.pn-active{border-color:var(--pn-accent,#4299e1);background:var(--pn-accent,#4299e1);color:#fff}
.pn-name-parts{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap}
.pn-name-part{flex:1;min-width:120px}
.pn-name-core{flex:1.8;min-width:160px}
.pn-name-comma-sep{flex:0 0 auto;padding-bottom:4px;align-self:flex-end}
.pn-name-constructed{margin-top:12px;padding:10px 14px;background:#f7fafc;border:1px solid #e2e8f0;border-radius:6px;font-size:15px;font-weight:600;color:#2d3748}
.pn-focus-canvas-wrap{position:relative;display:inline-block;max-width:100%;margin:10px auto;text-align:center}
.pn-focus-canvas-wrap canvas{display:block;max-width:100%;cursor:move;border-radius:6px}
.pn-md-preview-toggle{display:flex;gap:0;margin-bottom:8px}
.pn-md-toggle-btn{padding:5px 12px;font-size:11px;font-weight:600;cursor:pointer;border:1px solid #e2e8f0;background:#fff;color:#718096}
.pn-md-toggle-btn:first-child{border-radius:4px 0 0 4px}
.pn-md-toggle-btn:last-child{border-radius:0 4px 4px 0;border-left:0}
.pn-md-toggle-btn.pn-active{background:var(--pn-accent,#4299e1);color:#fff;border-color:var(--pn-accent,#4299e1)}
.pn-md-preview{min-height:100px;border:1px solid #e2e8f0;border-radius:6px;padding:10px 12px;font-size:14px;line-height:1.7;color:#4a5568;background:#fafafa}
.pn-md-preview h1,.pn-md-preview h2,.pn-md-preview h3,.pn-md-preview h4,.pn-md-preview h5,.pn-md-preview h6{background:transparent;border:none;padding:0;border-radius:0;text-shadow:none}
/* ===== Welcome Panel ===== */
.pn-welcome-hero{display:flex;gap:14px;align-items:flex-start;background:linear-gradient(135deg,#ebf8ff,#faf5ff);border:1px solid #e2e8f0;border-radius:10px;padding:16px 18px;margin-bottom:16px}
.pn-welcome-icon{flex:0 0 auto;width:52px;height:52px;border-radius:12px;background:linear-gradient(135deg,#4299e1,#9f7aea);color:#fff;display:flex;align-items:center;justify-content:center;font-size:24px;box-shadow:0 4px 12px rgba(102,126,234,0.25)}
.pn-welcome-hero-text h4{margin:0 0 6px 0;font-size:17px;font-weight:700;color:#2d3748;background:transparent;border:none;padding:0;border-radius:0;text-shadow:none}
.pn-welcome-hero-text p{margin:0;font-size:13px;line-height:1.55;color:#4a5568}
.pn-welcome-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:12px;margin-bottom:16px}
.pn-welcome-card{border:1px solid #e2e8f0;border-radius:10px;padding:14px;background:#fff;display:flex;flex-direction:column;gap:10px;cursor:pointer;transition:border-color .15s,box-shadow .15s,transform .15s}
.pn-welcome-card:hover{border-color:var(--pn-accent,#4299e1);box-shadow:0 4px 14px rgba(66,153,225,0.15);transform:translateY(-1px)}
.pn-welcome-card-head{display:flex;align-items:center;gap:10px}
.pn-welcome-card-icon{width:34px;height:34px;border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:14px;flex-shrink:0}
.pn-wc-blue{background:linear-gradient(135deg,#4299e1,#2c5282)}
.pn-wc-purple{background:linear-gradient(135deg,#9f7aea,#553c9a)}
.pn-wc-gold{background:linear-gradient(135deg,#ecc94b,#975a16)}
.pn-wc-teal{background:linear-gradient(135deg,#38b2ac,#234e52)}
.pn-wc-rose{background:linear-gradient(135deg,#fc8181,#9b2c2c)}
.pn-welcome-card-title{font-size:14px;font-weight:700;color:#2d3748}
.pn-welcome-card-body{font-size:12px;line-height:1.5;color:#4a5568;flex:1}
.pn-welcome-mock{background:#f7fafc;border:1px solid #edf2f7;border-radius:6px;padding:10px;display:flex;align-items:center;justify-content:center;min-height:48px}
.pn-wm-about{flex-direction:column;align-items:stretch;gap:5px;padding:10px 12px}
.pn-wm-line{height:6px;border-radius:3px;background:#cbd5e0}
.pn-wm-line-h{height:8px;width:55%;background:#a0aec0}
.pn-wm-line-short{width:70%}
.pn-wm-colors{gap:6px}
.pn-wm-colors span{width:20px;height:20px;border-radius:50%;display:inline-block;border:2px solid #fff;box-shadow:0 0 0 1px rgba(0,0,0,0.08)}
.pn-wm-name{gap:6px;flex-wrap:wrap}
.pn-wm-pill{font-size:10px;font-weight:600;padding:3px 8px;border-radius:10px;background:#bee3f8;color:#2c5282}
.pn-wm-name-core{font-family:Georgia,serif;font-size:14px;font-weight:700;color:#2d3748}
.pn-wm-focus-frame{width:60px;height:40px;border-radius:4px;background:linear-gradient(135deg,#cbd5e0,#a0aec0);position:relative;overflow:hidden}
.pn-wm-focus-target{position:absolute;top:50%;left:50%;width:18px;height:18px;border:2px solid #fff;border-radius:50%;transform:translate(-50%,-50%);box-shadow:0 0 0 1px rgba(0,0,0,0.3)}
.pn-wm-milestones{position:relative;height:36px;padding:0}
.pn-wm-ms-line{position:absolute;left:6%;right:6%;top:50%;height:2px;background:#cbd5e0;transform:translateY(-50%)}
.pn-wm-ms-dot{position:absolute;top:50%;width:10px;height:10px;border-radius:50%;background:var(--pn-accent,#4299e1);transform:translate(-50%,-50%);box-shadow:0 0 0 2px #fff}
.pn-welcome-card-cta{margin-top:auto;align-self:flex-start;background:none;border:none;color:var(--pn-accent,#4299e1);font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.04em;cursor:pointer;padding:0;display:inline-flex;align-items:center;gap:5px}
.pn-welcome-card-cta i{font-size:10px;transition:transform .15s}
.pn-welcome-card:hover .pn-welcome-card-cta i{transform:translateX(3px)}
.pn-welcome-tips{background:#fffbeb;border:1px solid #fef3c7;border-left:4px solid #f6ad55;border-radius:8px;padding:12px 14px}
.pn-welcome-tips-title{font-size:12px;font-weight:700;color:#975a16;text-transform:uppercase;letter-spacing:.05em;margin-bottom:6px}
.pn-welcome-tips-title i{margin-right:5px}
.pn-welcome-tips ul{margin:0;padding-left:18px;font-size:12px;line-height:1.6;color:#744210}
.pn-welcome-tips li{margin-bottom:2px}
/* ===== Accent color applied to stat cards ===== */
.pn-stat-card{border-top:3px solid var(--pn-accent,#4299e1)}
.pn-tab-nav li.pn-tab-active{color:var(--pn-accent,#4299e1);border-bottom-color:var(--pn-accent,#4299e1)}
.pn-font-picker{display:grid;grid-template-columns:repeat(auto-fill,minmax(140px,1fr));gap:8px;margin-top:4px}
.pn-font-card{border:2px solid #e2e8f0;border-radius:8px;padding:10px 8px;cursor:pointer;text-align:center;transition:border-color .15s,box-shadow .15s;background:#fff;user-select:none}
.pn-font-card:hover{border-color:#a0aec0;background:#f7fafc}
.pn-font-card.pn-active{border-color:var(--pn-accent,#4299e1);box-shadow:0 0 0 2px rgba(66,153,225,0.2);background:#ebf8ff}
.pn-font-card-sample{font-size:16px;line-height:1.3;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding:0 4px}
.pn-font-card-label{font-size:10px;font-weight:600;color:#718096;text-transform:uppercase;letter-spacing:.04em}
@media(max-width:600px){
.pn-design-tabs{overflow-x:auto;-webkit-overflow-scrolling:touch}
.pn-name-parts{flex-direction:column;gap:8px}
.pn-color-row{flex-direction:column;gap:8px}
}
</style>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<!-- ===========================================================================
     A/B/C/D EXPERIMENT (April Fools): Variant D = "D&D Character Sheet"
     Parchment, deep red & gold, hand-lettered serifs, ability score boxes
     derived from real player data. Scoped to body.pn-variant-d.
     Removal: see Player::profile if-block + default.theme eyeball block.
     =========================================================================== -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;700;900&family=IM+Fell+English:ital,wght@0,400;0,700;1,400&family=Uncial+Antiqua&family=MedievalSharp&family=EB+Garamond:ital,wght@0,400;0,700;1,400&display=swap">
<style>
body.pn-variant-d{
  --dnd-parchment:#f4e4bc;
  --dnd-parchment-dark:#e8d4a0;
  --dnd-ink:#2b1810;
  --dnd-ink-soft:#5a3a1a;
  --dnd-red:#7a1c1c;
  --dnd-red-deep:#4a0e0e;
  --dnd-gold:#c9a14b;
  --dnd-gold-light:#e0bc5d;
  background:#1a0f08!important;
  background-image:
    radial-gradient(ellipse at 20% 10%,rgba(122,28,28,0.25),transparent 50%),
    radial-gradient(ellipse at 80% 90%,rgba(74,14,14,0.3),transparent 50%),
    url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='2'/></filter><rect width='200' height='200' fill='%231a0f08'/><rect width='200' height='200' filter='url(%23n)' opacity='0.4'/></svg>")!important;
  color:var(--dnd-ink)!important;
  font-family:"EB Garamond","IM Fell English",Georgia,serif!important;
  font-size:16px!important;line-height:1.5!important;
}
body.pn-variant-d #theme_container{background:transparent!important;padding:14px!important}
body.pn-variant-d *{box-sizing:border-box}
body.pn-variant-d a{color:var(--dnd-red)!important;text-decoration:underline;text-decoration-style:dotted;text-underline-offset:3px}
body.pn-variant-d a:hover{color:var(--dnd-red-deep)!important;background:rgba(201,161,75,0.2)}

/* parchment paper for all major containers */
body.pn-variant-d .pn-hero,
body.pn-variant-d .pn-stats-row,
body.pn-variant-d .pn-card,
body.pn-variant-d .pn-tabs,
body.pn-variant-d .pn-modal-box{
  background-color:var(--dnd-parchment)!important;
  background-image:
    radial-gradient(ellipse at 30% 20%,rgba(232,212,160,0.6),transparent 40%),
    radial-gradient(ellipse at 70% 80%,rgba(218,196,140,0.5),transparent 50%),
    url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='300' height='300'><filter id='p'><feTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' seed='5'/><feColorMatrix values='0 0 0 0 0.6  0 0 0 0 0.45  0 0 0 0 0.2  0 0 0 0.18 0'/></filter><rect width='300' height='300' filter='url(%23p)'/></svg>")!important;
  color:var(--dnd-ink)!important;
  border:none!important;border-radius:0!important;
  box-shadow:
    0 0 0 1px rgba(122,28,28,0.3),
    0 0 0 4px var(--dnd-parchment),
    0 0 0 5px var(--dnd-red),
    0 0 0 7px var(--dnd-gold),
    0 0 0 8px var(--dnd-red-deep),
    0 12px 32px rgba(0,0,0,0.6),
    inset 0 0 60px rgba(122,28,28,0.08),
    inset 0 0 0 1px rgba(74,14,14,0.2)!important;
}

/* ===== HEADERS ===== */
body.pn-variant-d h1,body.pn-variant-d h2,body.pn-variant-d h3,body.pn-variant-d h4,body.pn-variant-d h5,body.pn-variant-d h6{
  font-family:"Cinzel","IM Fell English",Georgia,serif!important;
  color:var(--dnd-red-deep)!important;background:none!important;border:none!important;
  text-shadow:none!important;padding:0!important;border-radius:0!important;font-weight:700;
}

/* ===== HERO → CHARACTER SHEET HEADER ===== */
body.pn-variant-d .pn-hero{
  position:relative;padding:24px 28px 18px 28px!important;margin:0 0 18px 0!important;
  min-height:auto!important;
}
body.pn-variant-d .pn-hero-bg{display:none!important}
body.pn-variant-d .pn-hero::before{
  content:"\271C  CHARACTER  SHEET  \271C";
  display:block;text-align:center;font-family:"Cinzel",serif;font-size:11px;
  font-weight:700;letter-spacing:0.4em;color:var(--dnd-red);margin-bottom:8px;
}
body.pn-variant-d .pn-hero-content{display:flex!important;align-items:flex-start!important;gap:24px!important;flex-wrap:wrap!important}
body.pn-variant-d .pn-avatar{
  width:140px!important;height:140px!important;border-radius:50%!important;
  border:4px solid var(--dnd-red)!important;background:var(--dnd-parchment-dark)!important;
  box-shadow:
    0 0 0 2px var(--dnd-gold),
    0 0 0 6px var(--dnd-red-deep),
    inset 0 0 20px rgba(74,14,14,0.4),
    0 8px 18px rgba(0,0,0,0.3)!important;
  padding:0!important;flex-shrink:0;
}
body.pn-variant-d .pn-avatar img{border-radius:50%!important;filter:sepia(0.4) contrast(1.1)}
body.pn-variant-d .pn-hero-info{flex:1;min-width:240px}
body.pn-variant-d .pn-persona,body.pn-variant-d #pn-hero-persona{
  font-family:"Uncial Antiqua","MedievalSharp","Cinzel",serif!important;
  font-size:46px!important;line-height:1.05!important;
  color:var(--dnd-red-deep)!important;
  background:none!important;-webkit-text-fill-color:var(--dnd-red-deep)!important;
  text-shadow:1px 1px 0 var(--dnd-gold-light),2px 2px 0 rgba(74,14,14,0.4)!important;
  font-weight:700!important;letter-spacing:0.01em!important;
  margin:2px 0 6px 0!important;text-transform:none;filter:none!important;
}
body.pn-variant-d .pn-hero-subline{
  font-family:"IM Fell English",serif!important;font-size:15px;
  font-style:italic;color:var(--dnd-ink-soft)!important;margin-top:2px;
}
body.pn-variant-d .pn-hero-subline span{color:var(--dnd-ink-soft)!important}
body.pn-variant-d .pn-sub-pronunciation{color:var(--dnd-red)!important}

body.pn-variant-d .pn-breadcrumb{font-family:"IM Fell English",serif;font-size:13px;color:var(--dnd-ink-soft)!important;margin-top:6px;font-style:italic}
body.pn-variant-d .pn-breadcrumb a{color:var(--dnd-red)!important;background:none!important;text-decoration:underline}
body.pn-variant-d .pn-breadcrumb .pn-sep i{display:none}
body.pn-variant-d .pn-breadcrumb .pn-sep::before{content:" \273D ";color:var(--dnd-gold)}

/* badges → sealed wax/proficiency tags */
body.pn-variant-d .pn-badges{margin-top:12px;gap:6px}
body.pn-variant-d .pn-badge{
  background:var(--dnd-parchment-dark)!important;
  border:1px solid var(--dnd-red)!important;
  border-radius:0!important;padding:3px 10px!important;
  font-family:"Cinzel",serif!important;font-size:10px!important;
  text-transform:uppercase!important;letter-spacing:0.1em!important;
  color:var(--dnd-red-deep)!important;font-weight:700!important;
  box-shadow:1px 1px 0 var(--dnd-gold);
}
body.pn-variant-d .pn-badge i{color:var(--dnd-red)!important}
body.pn-variant-d .pn-badge-green{background:#c8d8b8!important;border-color:#3a5a2a!important;color:#1a3a0a!important}
body.pn-variant-d .pn-badge-red{background:#e8c0b0!important;border-color:#7a1c1c!important;color:#4a0e0e!important}
body.pn-variant-d .pn-badge-blue{background:#b8c8d8!important;border-color:#1a3a5a!important;color:#0a1a3a!important}
body.pn-variant-d .pn-badge-yellow{background:#e8d8a0!important;border-color:#7a5a1a!important;color:#3a2a0a!important}
body.pn-variant-d .pn-badge-orange{background:#e8c898!important;border-color:#8a4a1a!important;color:#4a2a0a!important}
body.pn-variant-d .pn-badge-purple{background:#c8b8d8!important;border-color:#3a1a5a!important;color:#1a0a3a!important}
body.pn-variant-d .pn-badge-gold{background:var(--dnd-gold-light)!important;border-color:#7a5a1a!important;color:#3a2a0a!important}
body.pn-variant-d .pn-badge-gray{background:#d8d0c0!important;border-color:#5a4a3a!important;color:#2a1a0a!important}

body.pn-variant-d .pn-hero-actions{margin-top:14px;display:flex;gap:10px;flex-wrap:wrap}
body.pn-variant-d .pn-hero-actions .pn-btn{
  background:var(--dnd-red)!important;color:var(--dnd-gold-light)!important;
  border:2px solid var(--dnd-gold)!important;border-radius:0!important;
  font-family:"Cinzel",serif!important;font-size:11px!important;font-weight:700!important;
  padding:8px 16px!important;text-transform:uppercase;letter-spacing:0.12em;
  text-shadow:0 1px 0 var(--dnd-red-deep);
  box-shadow:0 3px 0 var(--dnd-red-deep),0 5px 8px rgba(0,0,0,0.3);
  transition:transform .15s,box-shadow .15s;
}
body.pn-variant-d .pn-hero-actions .pn-btn:hover{transform:translateY(-1px);box-shadow:0 4px 0 var(--dnd-red-deep),0 7px 10px rgba(0,0,0,0.35)}

/* ===== ABILITY SCORES (stat cards) ===== */
body.pn-variant-d .pn-stats-row{
  display:grid!important;grid-template-columns:repeat(6,1fr)!important;gap:10px!important;
  padding:14px 18px!important;margin:18px 0!important;
}
body.pn-variant-d .pn-stat-card{
  background:var(--dnd-parchment-dark)!important;
  border:3px solid var(--dnd-red-deep)!important;border-radius:0!important;
  padding:8px 6px 10px 6px!important;text-align:center;
  position:relative;overflow:visible;
  box-shadow:
    0 0 0 1px var(--dnd-gold),
    inset 0 0 12px rgba(74,14,14,0.15),
    2px 2px 0 var(--dnd-red-deep)!important;
  cursor:pointer;
}
body.pn-variant-d .pn-stat-card::before{display:none!important}
body.pn-variant-d .pn-stat-card:hover{transform:translate(-1px,-1px);box-shadow:0 0 0 1px var(--dnd-gold),inset 0 0 12px rgba(74,14,14,0.15),3px 3px 0 var(--dnd-red-deep)!important}
body.pn-variant-d .pn-stat-icon{display:none!important}
body.pn-variant-d .pn-stat-label{
  font-family:"Cinzel",serif!important;font-size:9px!important;
  text-transform:uppercase;letter-spacing:0.12em;color:var(--dnd-red-deep)!important;
  font-weight:700;margin:0 0 2px 0;line-height:1.2;
}
body.pn-variant-d .pn-stat-number{
  font-family:"Cinzel",serif!important;font-size:32px!important;
  color:var(--dnd-ink)!important;line-height:1!important;
  font-weight:900;text-shadow:1px 1px 0 var(--dnd-gold-light);
  margin:2px 0;
}
/* ability mod chip injected by JS */
body.pn-variant-d .pn-d-mod{
  display:inline-block;background:var(--dnd-parchment);
  border:2px solid var(--dnd-red-deep);border-radius:50%;
  width:34px;height:34px;line-height:30px;
  font-family:"Cinzel",serif;font-size:14px;font-weight:700;
  color:var(--dnd-red-deep);
  position:absolute;left:50%;bottom:-18px;transform:translateX(-50%);
  box-shadow:0 0 0 1px var(--dnd-gold),0 3px 6px rgba(0,0,0,0.3);
}

/* ===== SIDEBAR CARDS → manuscript boxes ===== */
body.pn-variant-d .pn-grid{gap:18px!important}
body.pn-variant-d .pn-sidebar{display:flex;flex-direction:column;gap:14px}
body.pn-variant-d .pn-card{padding:0!important}
body.pn-variant-d .pn-card h4{
  font-family:"Cinzel",serif!important;font-size:12px!important;font-weight:700!important;
  text-transform:uppercase;letter-spacing:0.16em;color:var(--dnd-red-deep)!important;
  background:linear-gradient(180deg,transparent,rgba(122,28,28,0.08))!important;
  border-bottom:2px double var(--dnd-red)!important;
  padding:10px 16px 8px 16px!important;margin:0 0 10px 0!important;
  text-align:center;display:flex;align-items:center;justify-content:center;gap:10px;
  text-shadow:1px 1px 0 var(--dnd-gold-light);
}
body.pn-variant-d .pn-card h4::before{content:"\2766";color:var(--dnd-gold);font-size:14px}
body.pn-variant-d .pn-card h4::after{content:"\2766";color:var(--dnd-gold);font-size:14px;margin-left:auto}
body.pn-variant-d .pn-card h4 i{color:var(--dnd-red)!important;background:none!important;border-radius:0!important;padding:0!important}
body.pn-variant-d .pn-card>*:not(h4){padding:0 16px 12px 16px}
body.pn-variant-d .pn-card .pn-cms-line,body.pn-variant-d .pn-card *:not(h4){color:var(--dnd-ink)!important;font-family:"EB Garamond",Georgia,serif;font-size:14px}
body.pn-variant-d .pn-card .pn-cms-line strong{color:var(--dnd-red-deep)!important;font-family:"Cinzel",serif;font-size:10px;text-transform:uppercase;letter-spacing:0.08em}
body.pn-variant-d .pn-card a{color:var(--dnd-red)!important}
body.pn-variant-d .pn-card-edit-btn{background:var(--dnd-parchment-dark)!important;border:1px solid var(--dnd-red)!important;color:var(--dnd-red-deep)!important;border-radius:0!important;padding:2px 8px!important;font-family:"Cinzel",serif!important;font-size:10px!important}

/* ===== TABS → Player's Handbook chapter dividers ===== */
body.pn-variant-d .pn-tabs{padding:0!important;overflow:visible!important}
body.pn-variant-d .pn-tab-nav{
  display:flex!important;flex-wrap:wrap!important;list-style:none!important;
  background:rgba(122,28,28,0.06)!important;
  border:none!important;border-bottom:2px double var(--dnd-red)!important;
  padding:10px 14px 0 14px!important;margin:0!important;gap:6px!important;
}
body.pn-variant-d .pn-tab-nav li.pn-tab-label{
  background:transparent!important;color:var(--dnd-ink-soft)!important;
  border:1px solid transparent!important;border-bottom:none!important;border-radius:0!important;
  font-family:"Cinzel",serif!important;font-size:11px!important;font-weight:700!important;
  text-transform:uppercase;letter-spacing:0.12em;
  padding:10px 16px 12px 16px!important;cursor:pointer;margin:0!important;
}
body.pn-variant-d .pn-tab-nav li.pn-tab-label:hover{color:var(--dnd-red-deep)!important;background:rgba(201,161,75,0.15)!important}
body.pn-variant-d .pn-tab-nav li.pn-tab-active{
  background:var(--dnd-parchment)!important;color:var(--dnd-red-deep)!important;
  border:1px solid var(--dnd-red)!important;border-bottom:1px solid var(--dnd-parchment)!important;
  margin-bottom:-2px!important;box-shadow:inset 0 -3px 0 var(--dnd-gold);
}
body.pn-variant-d .pn-tab-nav li.pn-tab-active::after{display:none!important}
body.pn-variant-d .pn-tab-count{
  background:var(--dnd-red)!important;color:var(--dnd-gold-light)!important;
  border:1px solid var(--dnd-gold)!important;border-radius:0!important;
  font-family:"Cinzel",serif!important;font-size:10px!important;padding:0 5px!important;font-weight:700;
}
body.pn-variant-d .pn-tab-active .pn-tab-count{background:var(--dnd-red-deep)!important;color:var(--dnd-gold-light)!important}
body.pn-variant-d .pn-tab-panel{padding:22px 28px!important;color:var(--dnd-ink)!important}
body.pn-variant-d .pn-tab-panel *{color:var(--dnd-ink)}
body.pn-variant-d .pn-tab-panel a{color:var(--dnd-red)!important}
body.pn-variant-d .pn-tab-panel h2,body.pn-variant-d .pn-tab-panel h3{
  font-family:"Cinzel",serif!important;color:var(--dnd-red-deep)!important;font-size:20px!important;
  text-transform:uppercase;letter-spacing:0.1em;
  border:none!important;border-bottom:2px double var(--dnd-red)!important;
  padding-bottom:8px;background:none!important;
  text-shadow:1px 1px 0 var(--dnd-gold-light);
  text-align:center;
}
body.pn-variant-d .pn-tab-panel p{font-family:"EB Garamond",Georgia,serif;font-size:16px;line-height:1.6}
body.pn-variant-d .pn-tab-panel p::first-letter{font-family:"Uncial Antiqua",serif;font-size:1.8em;color:var(--dnd-red-deep);float:left;line-height:0.9;padding:4px 6px 0 0}

/* tables - spell list / inventory */
body.pn-variant-d .pn-tab-panel table{
  font-family:"EB Garamond",Georgia,serif!important;font-size:15px!important;
  border:1px solid var(--dnd-red)!important;border-collapse:collapse;width:100%;
  background:var(--dnd-parchment-dark)!important;
}
body.pn-variant-d .pn-tab-panel table th{
  background:var(--dnd-red)!important;color:var(--dnd-gold-light)!important;
  font-family:"Cinzel",serif!important;font-size:11px!important;
  text-transform:uppercase;letter-spacing:0.1em;padding:6px 12px!important;
  border:1px solid var(--dnd-red-deep)!important;text-align:left;
}
body.pn-variant-d .pn-tab-panel table td{
  background:transparent!important;color:var(--dnd-ink)!important;
  border:1px solid rgba(122,28,28,0.3)!important;padding:6px 12px!important;
}
body.pn-variant-d .pn-tab-panel table tr:nth-child(even) td{background:rgba(232,212,160,0.5)!important}
body.pn-variant-d .pn-tab-panel table tr:hover td{background:rgba(201,161,75,0.25)!important}

/* timeline → quest log */
body.pn-variant-d .pn-timeline-heading{color:var(--dnd-red-deep)!important;font-family:"Cinzel",serif!important;font-size:18px!important;border-bottom:2px double var(--dnd-red)!important;text-align:center;text-transform:uppercase;letter-spacing:0.1em}
body.pn-variant-d .pn-tl-node{
  background:var(--dnd-parchment)!important;border:2px solid var(--dnd-red)!important;
  box-shadow:0 0 0 1px var(--dnd-gold),inset 0 0 4px rgba(74,14,14,0.3)!important;
  color:var(--dnd-red-deep)!important;
}
body.pn-variant-d .pn-tl-date{font-family:"Cinzel",serif!important;font-size:11px!important;color:var(--dnd-red-deep)!important;font-weight:700;text-transform:uppercase;letter-spacing:0.08em}
body.pn-variant-d .pn-tl-desc{font-family:"EB Garamond",Georgia,serif!important;color:var(--dnd-ink)!important;font-size:15px;font-style:italic}

/* ===== INJECTED D&D ELEMENTS ===== */
.pn-d-vitals{
  display:grid;grid-template-columns:repeat(4,1fr);gap:14px;
  background-color:var(--dnd-parchment);
  background-image:radial-gradient(ellipse at 50% 50%,rgba(232,212,160,0.7),transparent),
    url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='200' height='200'><filter id='p'><feTurbulence type='fractalNoise' baseFrequency='0.65' numOctaves='3' seed='2'/><feColorMatrix values='0 0 0 0 0.6  0 0 0 0 0.45  0 0 0 0 0.2  0 0 0 0.18 0'/></filter><rect width='200' height='200' filter='url(%23p)'/></svg>");
  padding:14px 18px;margin:0 0 18px 0;
  box-shadow:
    0 0 0 1px rgba(122,28,28,0.3),
    0 0 0 4px var(--dnd-parchment),
    0 0 0 5px var(--dnd-red),
    0 0 0 7px var(--dnd-gold),
    0 0 0 8px var(--dnd-red-deep),
    0 12px 28px rgba(0,0,0,0.5);
}
.pn-d-vital-cell{
  background:var(--dnd-parchment-dark);border:2px solid var(--dnd-red-deep);
  text-align:center;padding:8px 6px;position:relative;
  box-shadow:0 0 0 1px var(--dnd-gold),inset 0 0 10px rgba(74,14,14,0.15);
}
.pn-d-vital-label{
  font-family:"Cinzel",serif;font-size:9px;font-weight:700;
  text-transform:uppercase;letter-spacing:0.12em;color:var(--dnd-red-deep);
  margin-bottom:4px;
}
.pn-d-vital-value{
  font-family:"Cinzel",serif;font-size:30px;font-weight:900;color:var(--dnd-ink);
  line-height:1;text-shadow:1px 1px 0 var(--dnd-gold-light);
}
.pn-d-vital-sub{font-family:"IM Fell English",serif;font-size:11px;color:var(--dnd-ink-soft);margin-top:4px;font-style:italic}

.pn-d-charinfo{
  display:grid;grid-template-columns:repeat(4,1fr);gap:0;
  background:var(--dnd-parchment-dark);
  border:1px solid var(--dnd-red-deep);
  margin:10px 0 16px 0;
}
.pn-d-charinfo>div{padding:6px 12px;border-right:1px solid rgba(122,28,28,0.3);text-align:center}
.pn-d-charinfo>div:last-child{border-right:none}
.pn-d-charinfo-label{font-family:"Cinzel",serif;font-size:9px;font-weight:700;text-transform:uppercase;letter-spacing:0.12em;color:var(--dnd-red-deep);display:block}
.pn-d-charinfo-value{font-family:"IM Fell English",serif;font-size:16px;color:var(--dnd-ink);font-weight:700}

.pn-d-divider{
  display:block;width:100%;height:24px;margin:14px 0;text-align:center;
  background:url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='600' height='24' viewBox='0 0 600 24'><line x1='0' y1='12' x2='240' y2='12' stroke='%23c9a14b' stroke-width='1.5'/><line x1='360' y1='12' x2='600' y2='12' stroke='%23c9a14b' stroke-width='1.5'/><text x='280' y='17' font-family='Georgia' font-size='18' fill='%237a1c1c'>%E2%9D%96</text><text x='305' y='17' font-family='Georgia' font-size='14' fill='%23c9a14b'>%E2%9C%9A</text><text x='330' y='17' font-family='Georgia' font-size='18' fill='%237a1c1c'>%E2%9D%96</text></svg>") center/auto 100% no-repeat;
}

.pn-d-quote{
  font-family:"IM Fell English",serif;font-style:italic;
  text-align:center;color:var(--dnd-ink-soft);font-size:15px;
  margin:0 0 12px 0;padding:0 30px;line-height:1.5;
}
.pn-d-quote::before{content:"\201C";font-size:32px;color:var(--dnd-red);vertical-align:-8px;margin-right:4px;font-family:Georgia,serif}
.pn-d-quote::after{content:"\201D";font-size:32px;color:var(--dnd-red);vertical-align:-12px;margin-left:4px;font-family:Georgia,serif}

/* modal polish */
body.pn-variant-d .pn-modal-box{padding:0!important;overflow:hidden}
body.pn-variant-d .pn-modal-title{font-family:"Cinzel",serif!important;color:var(--dnd-red-deep)!important;font-size:18px!important;text-transform:uppercase;letter-spacing:0.1em}
body.pn-variant-d .pn-design-tab{font-family:"Cinzel",serif!important;background:transparent!important;color:var(--dnd-ink-soft)!important;border:none!important;border-bottom:2px solid transparent!important;border-radius:0!important;font-size:11px!important;text-transform:uppercase;letter-spacing:0.1em}
body.pn-variant-d .pn-design-tab.pn-active{background:transparent!important;color:var(--dnd-red-deep)!important;border-bottom-color:var(--dnd-red)!important}

@media(max-width:760px){
  body.pn-variant-d .pn-stats-row{grid-template-columns:repeat(3,1fr)!important}
  body.pn-variant-d .pn-d-vitals{grid-template-columns:repeat(2,1fr)}
  body.pn-variant-d .pn-d-charinfo{grid-template-columns:repeat(2,1fr)}
  body.pn-variant-d .pn-persona,body.pn-variant-d #pn-hero-persona{font-size:32px!important}
}
</style>

<script>
(function(){
  document.body.classList.add('pn-variant-d');
  document.addEventListener('DOMContentLoaded', function(){
    var persona = (document.querySelector('.pn-persona')?.innerText || 'Adventurer').trim();
    var stats = {
      att: parseInt(<?= json_encode((string)($Stats['TotalAttendance'] ?? '0')) ?>,10)||0,
      awd: parseInt(<?= json_encode((string)($Stats['TotalAwards'] ?? '0')) ?>,10)||0,
      tit: parseInt(<?= json_encode((string)($Stats['TotalTitles'] ?? '0')) ?>,10)||0
    };

    // Derive D&D ability scores from real player data
    function clamp(n,lo,hi){return Math.max(lo,Math.min(hi,n))}
    function abilityFrom(n,base){return clamp(Math.round(8 + base + Math.log(1+n)*2.5), 8, 20)}
    function mod(s){var m=Math.floor((s-10)/2);return (m>=0?'+':'')+m}
    var abilities = {
      STR: abilityFrom(stats.att, 2),
      DEX: abilityFrom(stats.tit, 1),
      CON: abilityFrom(stats.att, 1),
      INT: abilityFrom(stats.tit, 0),
      WIS: abilityFrom(stats.awd, 1),
      CHA: abilityFrom(stats.awd, 2)
    };
    // HP / AC / Init / Speed
    var level = clamp(Math.floor(Math.log(1+stats.att+stats.awd*2)*2)+1, 1, 20);
    var hp = 10 + level*6 + Math.floor((abilities.CON-10)/2)*level;
    var ac = 10 + Math.floor((abilities.DEX-10)/2) + 2;
    var init = mod(abilities.DEX);
    var prof = '+' + clamp(Math.floor((level-1)/4)+2, 2, 6);

    // ----- Override stat-card label/numbers to show abilities -----
    var statCards = document.querySelectorAll('.pn-stat-card');
    var abilityList = ['STR','DEX','CON','INT','WIS','CHA'];
    var abilityFull = {STR:'Strength',DEX:'Dexterity',CON:'Constitution',INT:'Intelligence',WIS:'Wisdom',CHA:'Charisma'};
    // We have 4 cards by default — inject 2 more to make 6
    var statsRow = document.querySelector('.pn-stats-row');
    if (statsRow) {
      while (statsRow.children.length < 6) {
        var clone = statsRow.children[0].cloneNode(true);
        statsRow.appendChild(clone);
      }
      Array.from(statsRow.children).forEach(function(card,i){
        var key = abilityList[i];
        if (!key) return;
        var lab = card.querySelector('.pn-stat-label');
        var num = card.querySelector('.pn-stat-number');
        if (lab) lab.textContent = abilityFull[key];
        if (num) num.textContent = abilities[key];
        // remove icon
        var icon = card.querySelector('.pn-stat-icon'); if (icon) icon.remove();
        // append modifier chip
        if (!card.querySelector('.pn-d-mod')) {
          var chip = document.createElement('div');
          chip.className = 'pn-d-mod';
          chip.textContent = mod(abilities[key]);
          card.style.position='relative';
          card.style.marginBottom='22px';
          card.appendChild(chip);
        }
      });
    }

    // ----- Insert character info bar BEFORE the hero -----
    var infoBar = document.createElement('div');
    infoBar.className = 'pn-d-charinfo';
    var raceName = 'Human';
    var bgName = 'Realmwalker';
    var alignName = 'Chaotic Good';
    var classLevel = 'Fighter ' + level;
    infoBar.innerHTML =
      '<div><span class="pn-d-charinfo-label">Class &amp; Level</span><span class="pn-d-charinfo-value">' + classLevel + '</span></div>' +
      '<div><span class="pn-d-charinfo-label">Race</span><span class="pn-d-charinfo-value">' + raceName + '</span></div>' +
      '<div><span class="pn-d-charinfo-label">Background</span><span class="pn-d-charinfo-value">' + bgName + '</span></div>' +
      '<div><span class="pn-d-charinfo-label">Alignment</span><span class="pn-d-charinfo-value">' + alignName + '</span></div>';

    var hero = document.querySelector('.pn-hero');
    if (hero && hero.parentNode) hero.parentNode.insertBefore(infoBar, hero.nextSibling);

    // ----- Vitals row (HP / AC / Init / Speed / Prof / Insp) AFTER charinfo -----
    var vitals = document.createElement('div');
    vitals.className = 'pn-d-vitals';
    vitals.innerHTML =
      '<div class="pn-d-vital-cell"><div class="pn-d-vital-label">Hit Points</div><div class="pn-d-vital-value">' + hp + '</div><div class="pn-d-vital-sub">Max ' + hp + '</div></div>' +
      '<div class="pn-d-vital-cell"><div class="pn-d-vital-label">Armor Class</div><div class="pn-d-vital-value">' + ac + '</div><div class="pn-d-vital-sub">Padded + DEX</div></div>' +
      '<div class="pn-d-vital-cell"><div class="pn-d-vital-label">Initiative</div><div class="pn-d-vital-value">' + init + '</div><div class="pn-d-vital-sub">DEX modifier</div></div>' +
      '<div class="pn-d-vital-cell"><div class="pn-d-vital-label">Proficiency</div><div class="pn-d-vital-value">' + prof + '</div><div class="pn-d-vital-sub">Level ' + level + '</div></div>';
    if (infoBar.parentNode) infoBar.parentNode.insertBefore(vitals, infoBar.nextSibling);

    // Decorative divider before stats row
    var statsRowEl = document.querySelector('.pn-stats-row');
    if (statsRowEl) {
      var div1 = document.createElement('div'); div1.className='pn-d-divider';
      statsRowEl.parentNode.insertBefore(div1, statsRowEl);
      // Quote above stats row
      var quote = document.createElement('div'); quote.className='pn-d-quote';
      quote.textContent = 'Hark! Behold the chronicles of ' + persona + ', whose deeds are tallied below.';
      statsRowEl.parentNode.insertBefore(quote, statsRowEl);
    }

    // Decorative divider before main grid
    var grid = document.querySelector('.pn-layout');
    if (grid) {
      var div2 = document.createElement('div'); div2.className='pn-d-divider';
      grid.parentNode.insertBefore(div2, grid);
    }
  });
})();
</script>
<!-- ===========================================================================
     END VARIANT D (D&D Character Sheet) BLOCK
     =========================================================================== -->

<!-- =============================================
     ZONE 1: Profile Hero Header
     ============================================= -->
<div class="pn-hero" style="<?= $_pnHeroCss ?>">
	<div class="pn-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="pn-hero-content">
		<?php if ($canEditImages): ?>
		<div class="pn-avatar pn-editable-img">
			<img class="heraldry-img" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" data-focus-x="<?= $_pnFocusX ?>" data-focus-y="<?= $_pnFocusY ?>" data-focus-size="<?= $_pnFocusSize ?>" />
			<button class="pn-img-edit-btn" onclick="pnOpenImgModal('photo')" title="Update player photo"><i class="fas fa-camera"></i></button>
		</div>
		<?php else: ?>
		<div class="pn-avatar">
			<img class="heraldry-img" src="<?= htmlspecialchars($imageUrl) ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" data-focus-x="<?= $_pnFocusX ?>" data-focus-y="<?= $_pnFocusY ?>" data-focus-size="<?= $_pnFocusSize ?>" />
		</div>
		<?php endif; ?>
		<div class="pn-hero-info">
			<?php
				$_pnDisplayName = '';
				if (!empty($Player['NamePrefix'])) $_pnDisplayName .= htmlspecialchars($Player['NamePrefix']) . ' ';
				$_pnDisplayName .= htmlspecialchars($Player['Persona']);
				if (!empty($Player['NameSuffix'])) {
					$_pnDisplayName .= ((int)($Player['SuffixComma'] ?? 0) ? ', ' : ' ') . htmlspecialchars($Player['NameSuffix']);
				}
			?>
			<h1 class="pn-persona" id="pn-hero-persona">
				<?= $_pnDisplayName ?>
				<?php if ($isKnight): ?>
					<img class="pn-belt-icon" src="<?= $beltIconUrl ?>" alt="Knight" title="Belted Knight" />
				<?php endif; ?>
			</h1>
			<?php
				$_heroSubParts = [];
				if (!empty($Player['PronunciationGuide'])) $_heroSubParts[] = '<span class="pn-sub-pronunciation">(' . htmlspecialchars($Player['PronunciationGuide']) . ')</span>';
				$_heroRealParts = [];
				if ($showFirstName && strlen($Player['GivenName']) > 0) $_heroRealParts[] = $Player['GivenName'];
				if ($showLastName && strlen($Player['Surname']) > 0) $_heroRealParts[] = $Player['Surname'];
				if (!empty($_heroRealParts)) $_heroSubParts[] = '<span class="pn-sub-name">' . htmlspecialchars(implode(' ', $_heroRealParts)) . '</span>';
				if (!empty($pronounDisplay)) $_heroSubParts[] = '<span class="pn-sub-pronouns">' . htmlspecialchars($pronounDisplay) . '</span>';
			?>
			<?php if (!empty($_heroSubParts)): ?>
				<div class="pn-hero-subline"><?= implode(' <span class="pn-sub-sep">&bull;</span> ', $_heroSubParts) ?></div>
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
				<?php else: ?>
					<span class="pn-badge pn-badge-yellow"><i class="fas fa-exclamation-circle"></i> Needs Waiver</span>
				<?php endif; ?>
				<?php if ($Player['Restricted'] == 1): ?>
					<span class="pn-badge pn-badge-orange"><i class="fas fa-exclamation-triangle"></i> Restricted</span>
				<?php endif; ?>
				<?php if ($_duesForLife || (!empty($Player['DuesThrough']) && strtotime($Player['DuesThrough']) >= time())): ?>
					<span class="pn-badge pn-badge-green"><i class="fas fa-receipt"></i> Dues Paid</span>
				<?php elseif (!empty($Player['LastDuesThrough'])): ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> Dues Expired</span>
				<?php else: ?>
					<span class="pn-badge pn-badge-gray"><i class="fas fa-receipt"></i> No Dues on File</span>
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
					Suspended <?= htmlspecialchars($Player['SuspendedAt'] ?? '') ?> &mdash; Until <?php $_until = $Player['SuspendedUntil'] ?? ''; echo ($_until && $_until !== '0000-00-00') ? htmlspecialchars($_until) : 'Indefinite'; ?>
					<?php if (!empty($Player['Suspension'])): ?>
						&mdash; <?= htmlspecialchars($Player['Suspension']) ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<div class="pn-hero-actions">
			<?php if ($isOwnProfile): ?>
				<button class="pn-btn pn-btn-white" id="pn-design-btn" onclick="pnOpenDesignModal()"><i class="fas fa-palette"></i> Design My Profile</button>
			<?php endif; ?>
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
			<?php if ($showFirstName && strlen($Player['GivenName']) > 0): ?>
			<div class="pn-detail-row">
				<span class="pn-detail-label">Given Name</span>
				<span class="pn-detail-value"><?= htmlspecialchars($Player['GivenName']) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($showLastName && strlen($Player['Surname']) > 0): ?>
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
				<span class="pn-detail-value"><?= htmlspecialchars($Player['ParkMemberSince'] ?? '') ?></span>
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
				<?php
					$_hasAboutPersona = !empty(trim($Player['AboutPersona'] ?? ''));
					$_hasAboutStory   = !empty(trim($Player['AboutStory'] ?? ''));
					$_showBeltline    = (int)($Player['ShowBeltline'] ?? 1);
					$_hasBeltline     = $_showBeltline && (!empty($BeltlinePeers) || !empty($BeltlineAssociates));
					$_msConfig = json_decode($Player['MilestoneConfig'] ?? '', true);
					if (!is_array($_msConfig)) $_msConfig = [];
					$_msCompact = !empty($_msConfig['compact_milestones']);
					$_hasMilestones = false;
					$_visibleMilestones = [];
					if (is_array($Milestones)) {
						foreach ($Milestones as $_ms) {
							$_msType = $_ms['type'];
							if (!isset($_msConfig[$_msType]) || $_msConfig[$_msType]) {
								$_hasMilestones = true;
								$_visibleMilestones[] = $_ms;
							}
						}
					}
					$_showSidebar = $_hasBeltline || ($_msCompact && !empty($_visibleMilestones));
					$_showAboutTab    = $_hasAboutPersona || $_hasAboutStory || $_hasBeltline || $_hasMilestones || $isOwnProfile;
				?>
				<?php if ($_showAboutTab): ?>
				<li data-tab="about">
					<i class="fas fa-scroll"></i><span class="pn-tab-label"> About</span>
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
				$_allRecs  = array_values(array_filter(is_array($AwardRecommendations) ? $AwardRecommendations : [], function($r) { return empty($r['AlreadyHas']); }));
				$_myRecs   = array_values(array_filter($_allRecs, function($r) { return (int)$this->__session->user_id === (int)$r['RecommendedById']; }));
				$_recList  = $ShowRecsTab ? $_allRecs : $_myRecs;
				$_showRecs = $ShowRecsTab || count($_myRecs) > 0;
			?>
			<?php if ($_showRecs): ?><li data-tab="recommendations">
					<i class="fas fa-star"></i><span class="pn-tab-label"> Recommendations</span> <span class="pn-tab-count">(<?= count($_recList) ?>)</span>
				</li><?php endif; ?>
				<?php if ($canEditAdmin || ($LoggedIn && $isOwnProfile && is_array($Notes) && count($Notes) > 0)): ?>
				<li data-tab="history">
					<i class="fas fa-sticky-note"></i><span class="pn-tab-label"> Notes</span> <span class="pn-tab-count">(<?= is_array($Notes) ? count($Notes) : 0 ?>)</span>
				</li>
				<?php endif; ?>
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
								No recent sign-ins. Check out the next events and park days in your
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

			<!-- About Tab -->
				<?php if ($_showAboutTab): ?>
				<div class="pn-tab-panel" id="pn-tab-about" style="display:none">
					<div class="pn-about-layout">
						<div class="pn-about-main">
							<?php if ($_hasAboutPersona): ?>
							<div class="pn-about-section">
								<h3 class="pn-about-heading">About <?= htmlspecialchars($Player['Persona']) ?></h3>
								<div class="pn-about-content" id="pn-about-persona-rendered"></div>
							</div>
							<?php endif; ?>
							<?php if ($_hasAboutStory): ?>
							<div class="pn-about-section">
								<h3 class="pn-about-heading">My Story</h3>
								<div class="pn-about-content" id="pn-about-story-rendered"></div>
							</div>
							<?php endif; ?>
							<?php if (!$_hasAboutPersona && !$_hasAboutStory && $isOwnProfile && !$_hasBeltline && !$_hasMilestones): ?>
							<div class="pn-about-empty">
								<i class="fas fa-scroll" style="font-size:28px;color:#cbd5e0;margin-bottom:10px"></i>
								<p>Your About section is empty. Click <strong>Design My Profile</strong> above to add a bio and tell your story!</p>
							</div>
							<?php endif; ?>

							<?php if (!$_msCompact): ?>
							<?php if (!empty($_visibleMilestones)): ?>
							<div class="pn-timeline-section">
								<h3 class="pn-timeline-heading"><i class="fas fa-stream"></i> My Milestones</h3>
								<div class="pn-timeline">
									<?php foreach ($_visibleMilestones as $_idx => $_ms): ?>
									<div class="pn-tl-item">
																				<div class="pn-tl-left">
											<div class="pn-tl-date"><?= date('M j, Y', strtotime($_ms['date'])) ?></div>
										</div>
										<div class="pn-tl-node"><i class="fas <?= htmlspecialchars($_ms['icon']) ?>"></i></div>
										<div class="pn-tl-right">
											<div class="pn-tl-desc"><?= htmlspecialchars($_ms['description']) ?></div>
											<span class="pn-tl-date-mobile"><?= date('M j, Y', strtotime($_ms['date'])) ?></span>
										</div>
									</div>
									<?php endforeach; ?>
								</div>
							</div>
							<?php elseif ($isOwnProfile && empty($_hasAboutPersona) && empty($_hasAboutStory) && empty($_hasBeltline)): ?>
							<div class="pn-timeline-section">
								<h3 class="pn-timeline-heading"><i class="fas fa-stream"></i> My Milestones</h3>
								<div class="pn-tl-empty">
									<i class="fas fa-stream" style="font-size:24px;color:#cbd5e0;margin-bottom:8px;display:block"></i>
									No milestones to display yet. As you play, milestones will appear here automatically!
								</div>
							</div>
							<?php endif; ?>
							<?php endif; // !$_msCompact ?>
						</div>
						<?php if ($_showSidebar): ?>
						<div class="pn-about-sidebar">
							<?php if ($_hasBeltline): ?>
							<?php if (!empty($BeltlinePeers)): ?>
							<div class="pn-belt-card">
								<div class="pn-belt-card-title"><i class="fas fa-shield-alt"></i> My Peer<?= count($BeltlinePeers) > 1 ? 's' : '' ?></div>
								<?php
								$_blCurPeerage = null;
								$_blPeerLabels = ['Squire' => 'Squire to', 'Man-At-Arms' => 'Person-at-Arms to', 'Lords-Page' => "Lord's Page to", 'Page' => 'Page to'];
								?>
								<?php foreach ($BeltlinePeers as $_bp): ?>
								<?php if ($_bp['Peerage'] !== $_blCurPeerage): ?>
								<div class="pn-belt-group"><?= htmlspecialchars($_blPeerLabels[$_bp['Peerage']] ?? $_bp['Peerage']) ?></div>
								<?php $_blCurPeerage = $_bp['Peerage']; endif; ?>
								<div class="pn-belt-row">
									<a href="<?= UIR ?>Player/profile/<?= (int)$_bp['PeerId'] ?>" class="pn-belt-name"><?= htmlspecialchars($_bp['Persona']) ?></a>
									<span class="pn-belt-title"><?= htmlspecialchars($_bp['TitleName']) ?></span>
								</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
							<?php if (!empty($BeltlineAssociates)): ?>
							<div class="pn-belt-card">
								<div class="pn-belt-card-title"><i class="fas fa-user-friends"></i> My Associate<?= count($BeltlineAssociates) > 1 ? 's' : '' ?></div>
								<?php
								$_blaCurPeerage = null;
								$_blAssocLabels = ['Squire' => 'Squires', 'Man-At-Arms' => 'People-at-Arms', 'Lords-Page' => "Lords-Pages", 'Page' => 'Pages'];
								?>
								<?php foreach ($BeltlineAssociates as $_ba): ?>
								<?php if ($_ba['Peerage'] !== $_blaCurPeerage): ?>
								<div class="pn-belt-group"><?= htmlspecialchars($_blAssocLabels[$_ba['Peerage']] ?? $_ba['Peerage']) ?></div>
								<?php $_blaCurPeerage = $_ba['Peerage']; endif; ?>
								<div class="pn-belt-row">
									<a href="<?= UIR ?>Player/profile/<?= (int)$_ba['RecipientId'] ?>" class="pn-belt-name"><?= htmlspecialchars($_ba['Persona']) ?></a>
									<span class="pn-belt-title"><?= htmlspecialchars($_ba['TitleName']) ?></span>
								</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
							<?php endif; // $_hasBeltline ?>
							<?php if ($_msCompact && !empty($_visibleMilestones)): ?>
							<div class="pn-cms-card">
								<div class="pn-cms-title"><i class="fas fa-stream"></i> My Milestones</div>
								<?php foreach ($_visibleMilestones as $_cms): ?>
								<div class="pn-cms-item">
									<i class="fas <?= htmlspecialchars($_cms['icon']) ?> pn-cms-icon"></i>
									<div class="pn-cms-line"><strong><?= date('m/y', strtotime($_cms['date'])) ?></strong> &ndash; <?= htmlspecialchars($_cms['description']) ?></div>
								</div>
								<?php endforeach; ?>
							</div>
							<?php endif; ?>
						</div>
						<?php endif; // $_showSidebar ?>
					</div>
				</div>
				<?php endif; ?>

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
				<button class="pn-btn pn-btn-sm" style="background:#c53030;color:#fff;margin-left:8px" onclick="pnOpenRevokeAllModal()"><i class="fas fa-ban"></i> Revoke All</button>
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
							$pnLadderProgress[$aid] = ['Name' => $displayName, 'Short' => $shortName, 'Rank' => $rank, 'Count' => 1, 'HasMaster' => $hasMaster];
						} else {
							$pnLadderProgress[$aid]['Count']++;
							if ($rank > $pnLadderProgress[$aid]['Rank']) {
								$pnLadderProgress[$aid]['Rank'] = $rank;
							}
						}
					}
					// Use max(highest_rank, total_entries) to account for unreconciled historical awards
					// Cap at maxRank per award (10 for most, 12 for Zodiac)
					// Mark as approximate when count exceeds highest actual rank
					foreach ($pnLadderProgress as $_lpAid => &$lp) {
						$_lpMax = ($_lpAid === 30) ? 12 : 10;
						$lp['Approx'] = $lp['Count'] > $lp['Rank'];
						$lp['Rank'] = min($_lpMax, max($lp['Rank'], $lp['Count']));
					}
					unset($lp);
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
												echo (trimlen($detail['ParkName']) > 0) ? htmlspecialchars($detail['ParkName']) . ', ' . htmlspecialchars($detail['KingdomName']) : htmlspecialchars($detail['KingdomName']);
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
									<td><a href="<?= UIR ?>Kingdom/profile/<?= $detail['KingdomId'] ?>"><?= htmlspecialchars($detail['KingdomName']) ?></a></td>
									<td><a href="<?= UIR ?>Park/profile/<?= $detail['ParkId'] ?>"><?= htmlspecialchars($detail['ParkName']) ?></a></td>
									<td><a href="<?= UIR ?>Event/detail/<?= $detail['EventId'] ?>/<?= $detail['EventCalendarDetailId'] ?>"><?= htmlspecialchars($detail['EventName']) ?></a></td>
									<td><?= trimlen($detail['Flavor']) > 0 ? htmlspecialchars($detail['Flavor']) : htmlspecialchars($detail['ClassName']) ?></td>
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
									<td><?= htmlspecialchars($rec['AwardName']) ?></td>
									<td class="pn-col-numeric"><?= valid_id($rec['Rank']) ? (int)$rec['Rank'] : '' ?></td>
									<td class="pn-col-nowrap"><?= htmlspecialchars($rec['DateRecommended']) ?></td>
									<td><a href="<?= UIR ?>Player/profile/<?= $rec['RecommendedById'] ?>"><?= htmlspecialchars($rec['RecommendedByName']) ?></a></td>
									<td><?= htmlspecialchars($rec['Reason']) ?></td>
									<?php if ($this->__session->user_id): ?>
										<td class="pk-rec-actions">
											<?php if ($canManageAwards && valid_id($rec['KingdomAwardId'] ?? 0)): ?>
												<button class="pk-btn pk-btn-primary pn-rec-grant-btn"
													data-rec="<?= htmlspecialchars(json_encode(['KingdomAwardId' => (int)($rec['KingdomAwardId'] ?? 0), 'Rank' => (int)($rec['Rank'] ?? 0), 'Reason' => $rec['Reason'] ?? '', 'AwardName' => $rec['AwardName'] ?? '']), ENT_QUOTES) ?>">
													<i class="fas fa-medal"></i> Grant
												</button>
											<?php endif; ?>
											<?php if ($can_delete_recommendation || $this->__session->user_id == $rec['RecommendedById'] || $this->__session->user_id == $rec['MundaneId']): ?>
												<button class="pk-rec-dismiss-btn pn-rec-dismiss-btn"
													data-href="<?= UIR ?>Player/profile/<?= $rec['MundaneId'] ?>/deleterecommendation/<?= $rec['RecommendationsId'] ?>">
													<i class="fas fa-times"></i> Delete
												</button>
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
				<?php if ($isOwnProfile): ?>
				<div class="pn-notes-infobox">
					<i class="fas fa-info-circle pn-notes-infobox-icon"></i>
					<div>This tab contains historically imported notes about your profile from previous versions of the ORK. If these notes are still relevant, such as containing a title or award not listed in the other tabs, reach out to your local Monarch or Prime Minister to reconcile those notes. Once all notes have been reconciled, <a href="#" id="pn-clear-notes-link">click here to close out your notes tab</a>. This cannot be undone.</div>
				</div>
				<?php endif; ?>
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
									<td><?= htmlspecialchars($note['Note'] ?? '') ?></td>
									<td><?= htmlspecialchars($note['Description'] ?? '') ?></td>
									<td class="pn-col-nowrap"><?= htmlspecialchars($note['Date'] ?? '') . (strtotime($note['DateComplete'] ?? '') > 0 ? (' - ' . htmlspecialchars($note['DateComplete'])) : '') ?></td>
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
				<small>JPG, GIF, PNG &middot; Max 1&nbsp;MB (larger images auto-resized)</small>
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
     Design My Profile Modal
     ============================================= -->
<?php if ($isOwnProfile): ?>
<div class="pn-overlay" id="pn-design-overlay">
	<div class="pn-modal-box" style="width:720px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-palette" style="margin-right:8px;color:#2c5282"></i>Design My Profile</h3>
			<button class="pn-modal-close-btn" id="pn-design-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-design-tabs">
			<button class="pn-design-tab pn-active" data-panel="welcome"><i class="fas fa-hand-sparkles"></i> Welcome</button>
			<button class="pn-design-tab" data-panel="about"><i class="fas fa-scroll"></i> About</button>
			<button class="pn-design-tab" data-panel="colors"><i class="fas fa-palette"></i> Colors</button>
			<button class="pn-design-tab" data-panel="name"><i class="fas fa-signature"></i> Name</button>
			<button class="pn-design-tab" data-panel="focus"><i class="fas fa-crosshairs"></i> Photo Focus</button>
			<button class="pn-design-tab" data-panel="milestones"><i class="fas fa-stream"></i> Milestones</button>
		</div>
		<div class="pn-acct-modal-body" style="max-height:60vh;overflow-y:auto">
			<div class="pn-form-error" id="pn-design-error"></div>

			<!-- Welcome Panel -->
			<div class="pn-design-panel pn-active" id="pn-design-welcome">
				<div class="pn-welcome-hero">
					<div class="pn-welcome-icon"><i class="fas fa-palette"></i></div>
					<div class="pn-welcome-hero-text">
						<h4>Welcome to your Profile Customizer!</h4>
						<p>This is your space to make your profile feel like <em>you</em>. Tell your story, pick your colors, build your name, and frame your favorite photo &mdash; all from one place. Click any tab below to get started, or read on for a quick tour.</p>
					</div>
				</div>
				<div class="pn-welcome-grid">
					<div class="pn-welcome-card" data-go="about">
						<div class="pn-welcome-card-head">
							<div class="pn-welcome-card-icon pn-wc-blue"><i class="fas fa-scroll"></i></div>
							<div class="pn-welcome-card-title">About</div>
						</div>
						<div class="pn-welcome-card-body">
							Write your bio and persona story. Both fields support <strong>Markdown</strong> for headings, lists, and links.
						</div>
						<div class="pn-welcome-mock pn-wm-about">
							<div class="pn-wm-line pn-wm-line-h"></div>
							<div class="pn-wm-line"></div>
							<div class="pn-wm-line"></div>
							<div class="pn-wm-line pn-wm-line-short"></div>
						</div>
						<button class="pn-welcome-card-cta" type="button">Open About <i class="fas fa-arrow-right"></i></button>
					</div>
					<div class="pn-welcome-card" data-go="colors">
						<div class="pn-welcome-card-head">
							<div class="pn-welcome-card-icon pn-wc-purple"><i class="fas fa-palette"></i></div>
							<div class="pn-welcome-card-title">Colors</div>
						</div>
						<div class="pn-welcome-card-body">
							Pick a preset, build a gradient, or set custom hex colors for your hero, tabs, and stat cards.
						</div>
						<div class="pn-welcome-mock pn-wm-colors">
							<span style="background:#2c5282"></span>
							<span style="background:#276749"></span>
							<span style="background:#9b2c2c"></span>
							<span style="background:#553c9a"></span>
							<span style="background:#975a16"></span>
							<span style="background:linear-gradient(135deg,#1a365d,#553c9a)"></span>
						</div>
						<button class="pn-welcome-card-cta" type="button">Open Colors <i class="fas fa-arrow-right"></i></button>
					</div>
					<div class="pn-welcome-card" data-go="name">
						<div class="pn-welcome-card-head">
							<div class="pn-welcome-card-icon pn-wc-gold"><i class="fas fa-signature"></i></div>
							<div class="pn-welcome-card-title">Name</div>
						</div>
						<div class="pn-welcome-card-body">
							Add a prefix or suffix from your earned titles, set a pronunciation guide, and pick a decorative font.
						</div>
						<div class="pn-welcome-mock pn-wm-name">
							<span class="pn-wm-pill">Syr</span>
							<span class="pn-wm-name-core">Avery</span>
							<span class="pn-wm-pill">the Bold</span>
						</div>
						<button class="pn-welcome-card-cta" type="button">Open Name <i class="fas fa-arrow-right"></i></button>
					</div>
					<div class="pn-welcome-card" data-go="focus">
						<div class="pn-welcome-card-head">
							<div class="pn-welcome-card-icon pn-wc-teal"><i class="fas fa-crosshairs"></i></div>
							<div class="pn-welcome-card-title">Photo Focus</div>
						</div>
						<div class="pn-welcome-card-body">
							Frame your photo so the most important part &mdash; usually your face &mdash; stays centered everywhere it appears.
						</div>
						<div class="pn-welcome-mock pn-wm-focus">
							<div class="pn-wm-focus-frame">
								<div class="pn-wm-focus-target"></div>
							</div>
						</div>
						<button class="pn-welcome-card-cta" type="button">Open Photo Focus <i class="fas fa-arrow-right"></i></button>
					</div>
					<div class="pn-welcome-card" data-go="milestones">
						<div class="pn-welcome-card-head">
							<div class="pn-welcome-card-icon pn-wc-rose"><i class="fas fa-stream"></i></div>
							<div class="pn-welcome-card-title">Milestones</div>
						</div>
						<div class="pn-welcome-card-body">
							Curate which awards, titles, and events show up on your <em>My Milestones</em> timeline on the About tab.
						</div>
						<div class="pn-welcome-mock pn-wm-milestones">
							<div class="pn-wm-ms-line"></div>
							<div class="pn-wm-ms-dot" style="left:10%"></div>
							<div class="pn-wm-ms-dot" style="left:35%"></div>
							<div class="pn-wm-ms-dot" style="left:60%"></div>
							<div class="pn-wm-ms-dot" style="left:85%"></div>
						</div>
						<button class="pn-welcome-card-cta" type="button">Open Milestones <i class="fas fa-arrow-right"></i></button>
					</div>
				</div>
				<div class="pn-welcome-tips">
					<div class="pn-welcome-tips-title"><i class="fas fa-lightbulb"></i> Quick tips</div>
					<ul>
						<li>Changes save when you click <strong>Save</strong> at the bottom &mdash; nothing is permanent until then.</li>
						<li>You can come back any time by clicking <strong>Design My Profile</strong> on your profile.</li>
						<li>Not sure where to start? Try <strong>Colors</strong> first &mdash; the visual change is instant and fun.</li>
					</ul>
				</div>
			</div>

			<!-- About Panel -->
			<div class="pn-design-panel" id="pn-design-about">
				<div class="pn-design-field">
					<label>About <?= htmlspecialchars($Player['Persona']) ?></label>
					<div class="pn-md-preview-toggle">
						<button class="pn-md-toggle-btn pn-active" data-target="edit" data-field="persona">Write</button>
						<button class="pn-md-toggle-btn" data-target="preview" data-field="persona">Preview</button>
					</div>
					<textarea id="pn-design-about-persona" placeholder="Ex. Hi there! I'm an archer in the Northern Kingdom who loves brewing mead and singing bardic songs. You can find me in the Barony of..."><?= htmlspecialchars($Player['AboutPersona'] ?? '') ?></textarea>
					<div class="pn-md-preview" id="pn-design-about-persona-preview" style="display:none"></div>
					<div class="pn-design-hint">Supports <strong>Markdown</strong>: **bold**, *italic*, [links](url), ## headings, lists, etc.</div>
				</div>
				<div class="pn-design-field">
					<label>My Story</label>
					<div class="pn-md-preview-toggle">
						<button class="pn-md-toggle-btn pn-active" data-target="edit" data-field="story">Write</button>
						<button class="pn-md-toggle-btn" data-target="preview" data-field="story">Preview</button>
					</div>
					<textarea id="pn-design-about-story" placeholder="Ex. Feywild the Brewer has been traveling the realms looking for the Amulet of Fireballs. After his village was destroyed in a rock giant stampede..."><?= htmlspecialchars($Player['AboutStory'] ?? '') ?></textarea>
					<div class="pn-md-preview" id="pn-design-about-story-preview" style="display:none"></div>
					<div class="pn-design-hint">Supports <strong>Markdown</strong>: **bold**, *italic*, [links](url), ## headings, lists, etc.</div>
				</div>
				<div class="pn-design-field" style="margin-top:16px;padding-top:16px;border-top:1px solid #e2e8f0">
					<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;color:#4a5568">
						<input type="checkbox" id="pn-design-show-beltline" <?= ((int)($Player['ShowBeltline'] ?? 1)) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--pn-accent,#4299e1)" />
						Show My Beltline
					</label>
					<div class="pn-design-hint" style="margin-top:4px">Display your peerage relationships (peers and associates) on your About tab. Others can see who you're belted to and who you've belted.</div>
				</div>
			</div>

			<!-- Colors Panel -->
			<div class="pn-design-panel" id="pn-design-colors">
				<div class="pn-design-preview-label">Preview</div>
				<div class="pn-hero-preview" id="pn-color-hero-preview">
					<div class="pn-hero-preview-name"><?= htmlspecialchars($Player['Persona']) ?></div>
					<div class="pn-hero-preview-sub">Your profile will look like this</div>
				</div>
				<div class="pn-design-preview-label" style="margin-top:14px">Presets</div>
				<div class="pn-color-presets" id="pn-color-presets">
					<div class="pn-color-swatch" data-primary="#2c5282" data-accent="#4299e1" style="background:#2c5282" title="Default Blue"></div>
					<div class="pn-color-swatch" data-primary="#276749" data-accent="#48bb78" style="background:#276749" title="Forest Green"></div>
					<div class="pn-color-swatch" data-primary="#9b2c2c" data-accent="#fc8181" style="background:#9b2c2c" title="Crimson Red"></div>
					<div class="pn-color-swatch" data-primary="#553c9a" data-accent="#9f7aea" style="background:#553c9a" title="Royal Purple"></div>
					<div class="pn-color-swatch" data-primary="#975a16" data-accent="#ecc94b" style="background:#975a16" title="Gold"></div>
					<div class="pn-color-swatch" data-primary="#2d3748" data-accent="#a0aec0" style="background:#2d3748" title="Dark Gray"></div>
					<div class="pn-color-swatch" data-primary="#285e61" data-accent="#38b2ac" style="background:#285e61" title="Teal"></div>
					<div class="pn-color-swatch" data-primary="#744210" data-accent="#ed8936" style="background:#744210" title="Burnt Orange"></div>
				</div>
				<div class="pn-design-preview-label" style="margin-top:14px">Gradient Presets</div>
				<div class="pn-color-presets" id="pn-gradient-presets">
					<div class="pn-color-swatch" data-primary="#1a365d" data-accent="#4299e1" data-secondary="#553c9a" style="background:linear-gradient(135deg,#1a365d,#553c9a)" title="Midnight Royal"></div>
					<div class="pn-color-swatch" data-primary="#1a4731" data-accent="#48bb78" data-secondary="#2c5282" style="background:linear-gradient(135deg,#1a4731,#2c5282)" title="Forest Ocean"></div>
					<div class="pn-color-swatch" data-primary="#742a2a" data-accent="#fc8181" data-secondary="#975a16" style="background:linear-gradient(135deg,#742a2a,#975a16)" title="Ember"></div>
					<div class="pn-color-swatch" data-primary="#44337a" data-accent="#d6bcfa" data-secondary="#97266d" style="background:linear-gradient(135deg,#44337a,#97266d)" title="Mystic"></div>
					<div class="pn-color-swatch" data-primary="#234e52" data-accent="#38b2ac" data-secondary="#276749" style="background:linear-gradient(135deg,#234e52,#276749)" title="Deep Forest"></div>
					<div class="pn-color-swatch" data-primary="#1a202c" data-accent="#a0aec0" data-secondary="#2d3748" style="background:linear-gradient(135deg,#1a202c,#2d3748)" title="Charcoal"></div>
					<div class="pn-color-swatch" data-primary="#2c5282" data-accent="#4299e1" data-secondary="#285e61" style="background:linear-gradient(135deg,#2c5282,#285e61)" title="Ocean Teal"></div>
					<div class="pn-color-swatch" data-primary="#744210" data-accent="#ecc94b" data-secondary="#9b2c2c" style="background:linear-gradient(135deg,#744210,#9b2c2c)" title="Autumn"></div>
				</div>
				<div class="pn-design-preview-label" style="margin-top:14px">Custom Colors</div>
				<div class="pn-color-row">
					<div class="pn-color-col">
						<label style="font-size:11px;font-weight:600;color:#4a5568;margin-bottom:4px;display:block">Primary (Hero Background)</label>
						<div class="pn-color-input-wrap">
							<input type="color" id="pn-color-primary" value="<?= htmlspecialchars($Player['ColorPrimary'] ?? '#2c5282') ?>" />
							<input type="text" id="pn-color-primary-hex" value="<?= htmlspecialchars($Player['ColorPrimary'] ?? '#2c5282') ?>" maxlength="7" />
						</div>
					</div>
					<div class="pn-color-col">
						<label style="font-size:11px;font-weight:600;color:#4a5568;margin-bottom:4px;display:block">Accent (Tabs, Links, Stat Cards)</label>
						<div class="pn-color-input-wrap">
							<input type="color" id="pn-color-accent" value="<?= htmlspecialchars($Player['ColorAccent'] ?? '#4299e1') ?>" />
							<input type="text" id="pn-color-accent-hex" value="<?= htmlspecialchars($Player['ColorAccent'] ?? '#4299e1') ?>" maxlength="7" />
						</div>
					</div>
				</div>
				<div class="pn-design-preview-label" style="margin-top:14px">Gradient (Optional)</div>
				<div class="pn-color-row">
					<div class="pn-color-col">
						<label style="font-size:11px;font-weight:600;color:#4a5568;margin-bottom:4px;display:block">Secondary Color</label>
						<div class="pn-color-input-wrap">
							<input type="color" id="pn-color-secondary" value="<?= htmlspecialchars($Player['ColorSecondary'] ?? '#2c5282') ?>" />
							<input type="text" id="pn-color-secondary-hex" value="<?= htmlspecialchars($Player['ColorSecondary'] ?? '') ?>" maxlength="7" placeholder="None" />
						</div>
					</div>
					<div class="pn-color-col" style="display:flex;flex-direction:column;justify-content:flex-end">
						<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-size:12px;font-weight:600;color:#4a5568">
							<input type="checkbox" id="pn-gradient-enabled" <?= !empty($Player['ColorSecondary']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--pn-accent,#4299e1)" />
							Enable gradient
						</label>
					</div>
				</div>
				<div class="pn-design-preview-label" style="margin-top:14px">Heraldry Overlay Strength</div>
				<div class="pn-design-hint" style="margin-bottom:8px">Controls how much your heraldry shows through the hero background.</div>
				<div class="pn-overlay-options" style="display:flex;gap:8px">
					<button class="pn-overlay-btn<?= $_pnOverlay === 'low' ? ' pn-active' : '' ?>" data-overlay="low">Low</button>
					<button class="pn-overlay-btn<?= $_pnOverlay === 'med' ? ' pn-active' : '' ?>" data-overlay="med">Medium</button>
					<button class="pn-overlay-btn<?= $_pnOverlay === 'high' ? ' pn-active' : '' ?>" data-overlay="high">High</button>
				</div>
				<input type="hidden" id="pn-hero-overlay" value="<?= $_pnOverlay ?>" />
				<button class="pn-btn pn-btn-ghost pn-btn-sm" id="pn-color-reset" style="margin-top:12px"><i class="fas fa-undo"></i> Reset to Default</button>
			</div>

			<!-- Name Panel -->
			<div class="pn-design-panel" id="pn-design-name">
				<div class="pn-design-hint" style="margin-bottom:14px">Add titles or positions you've earned to your display name.</div>
				<div class="pn-name-parts">
					<div class="pn-name-part">
						<div class="pn-design-field">
							<label>Prefix</label>
							<select id="pn-name-prefix-select">
								<option value="">None</option>
								<?php if (!empty($PlayerTitles)): foreach ($PlayerTitles as $_pt): ?>
								<option value="<?= htmlspecialchars($_pt['TitleName']) ?>"<?= ($Player['NamePrefix'] ?? '') === $_pt['TitleName'] ? ' selected' : '' ?>><?= htmlspecialchars($_pt['TitleName']) ?></option>
								<?php endforeach; endif; ?>
								<option value="__custom__"<?= (!empty($Player['NamePrefix']) && !in_array($Player['NamePrefix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? ' selected' : '' ?>>Other...</option>
							</select>
							<input type="text" id="pn-name-prefix-custom" placeholder="Syr, Lady, Archon, Captain, etc..." style="margin-top:6px;<?= (!empty($Player['NamePrefix']) && !in_array($Player['NamePrefix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? '' : 'display:none;' ?>" value="<?= htmlspecialchars((!empty($Player['NamePrefix']) && !in_array($Player['NamePrefix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? $Player['NamePrefix'] : '') ?>" />
						</div>
					</div>
					<div class="pn-name-core">
						<div class="pn-design-field">
							<label>Core Name</label>
							<input type="text" id="pn-name-core" value="<?= htmlspecialchars($Player['Persona']) ?>" />
						</div>
					</div>
					<div class="pn-name-comma-sep">
						<button type="button" id="pn-suffix-comma-toggle" class="pn-comma-toggle<?= (int)($Player['SuffixComma'] ?? 0) ? ' pn-active' : '' ?>" title="Add comma before suffix">,</button>
					</div>
					<div class="pn-name-part">
						<div class="pn-design-field">
							<label>Suffix</label>
							<select id="pn-name-suffix-select">
								<option value="">None</option>
								<?php if (!empty($PlayerTitles)): foreach ($PlayerTitles as $_pt): ?>
								<option value="<?= htmlspecialchars($_pt['TitleName']) ?>"<?= ($Player['NameSuffix'] ?? '') === $_pt['TitleName'] ? ' selected' : '' ?>><?= htmlspecialchars($_pt['TitleName']) ?></option>
								<?php endforeach; endif; ?>
								<option value="__custom__"<?= (!empty($Player['NameSuffix']) && !in_array($Player['NameSuffix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? ' selected' : '' ?>>Other...</option>
							</select>
							<input type="text" id="pn-name-suffix-custom" placeholder="the Overpowered, the Realmstrider, Esquire" style="margin-top:6px;<?= (!empty($Player['NameSuffix']) && !in_array($Player['NameSuffix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? '' : 'display:none;' ?>" value="<?= htmlspecialchars((!empty($Player['NameSuffix']) && !in_array($Player['NameSuffix'], array_column($PlayerTitles ?? [], 'TitleName'))) ? $Player['NameSuffix'] : '') ?>" />
						</div>
					</div>
				</div>
				<div class="pn-name-constructed" id="pn-name-preview">
					<?php
						$_npv = '';
						if (!empty($Player['NamePrefix'])) $_npv .= htmlspecialchars($Player['NamePrefix']) . ' ';
						$_npv .= htmlspecialchars($Player['Persona']);
						if (!empty($Player['NameSuffix'])) {
							$_npv .= ((int)($Player['SuffixComma'] ?? 0) ? ', ' : ' ') . htmlspecialchars($Player['NameSuffix']);
						}
						echo $_npv;
					?>
				</div>
				<div class="pn-design-field" style="margin-top:16px">
					<label>Pronunciation Guide</label>
					<input type="text" id="pn-design-pronunciation" placeholder="Ex. veh-ree-lah nigh-born" value="<?= htmlspecialchars($Player['PronunciationGuide'] ?? '') ?>" maxlength="200" />
					<div class="pn-design-hint">Help others pronounce your persona name correctly. Shown in parentheses under your name.</div>
				</div>
				<div class="pn-design-field" style="margin-top:16px">
					<label>Name Font</label>
					<div class="pn-design-hint" style="margin-bottom:8px">Choose a decorative font for your persona name in the hero header.</div>
					<div class="pn-font-picker" id="pn-font-picker"></div>
				</div>
				<div style="margin-top:18px;padding-top:16px;border-top:1px solid #e2e8f0">
					<div style="font-size:13px;font-weight:700;color:#2d3748;margin-bottom:12px">Persona Display Controls</div>
					<div class="pn-design-field" style="margin-bottom:10px">
						<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;color:#4a5568">
							<input type="checkbox" id="pn-design-show-first" <?= ((int)($Player['ShowMundaneFirst'] ?? 1)) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--pn-accent,#4299e1)" />
							Show Mundane First Name
							<span class="pn-tooltip-trigger" title="Monarchy and administrators can always see your real name. Set this to yes to show it to any logged-in user."><i class="fas fa-question-circle" style="color:#a0aec0;font-size:13px;cursor:help"></i></span>
						</label>
					</div>
					<div class="pn-design-field" style="margin-bottom:10px">
						<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;color:#4a5568">
							<input type="checkbox" id="pn-design-show-last" <?= ((int)($Player['ShowMundaneLast'] ?? 1)) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--pn-accent,#4299e1)" />
							Show Mundane Last Name
							<span class="pn-tooltip-trigger" title="Monarchy and administrators can always see your real name. Set this to yes to show it to any logged-in user."><i class="fas fa-question-circle" style="color:#a0aec0;font-size:13px;cursor:help"></i></span>
						</label>
					</div>
					<div class="pn-design-field">
						<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;color:#4a5568">
							<input type="checkbox" id="pn-design-show-email" <?= ((int)($Player['ShowEmail'] ?? 1)) ? 'checked' : '' ?> style="width:18px;height:18px;accent-color:var(--pn-accent,#4299e1)" />
							Show Email Address
							<span class="pn-tooltip-trigger" title="Monarchy and administrators can always see your email address. Set this to yes to show it to any logged-in user."><i class="fas fa-question-circle" style="color:#a0aec0;font-size:13px;cursor:help"></i></span>
						</label>
					</div>
				</div>
			</div>

			<!-- Photo Focus Panel -->
			<div class="pn-design-panel" id="pn-design-focus">
				<?php if ($Player['HasImage'] > 0): ?>
				<div class="pn-design-hint" style="margin-bottom:10px">Move and resize the circle to set the focus area for your profile photo thumbnail.</div>
				<div class="pn-focus-canvas-wrap" style="text-align:center">
					<canvas id="pn-focus-canvas"></canvas>
				</div>
				<input type="hidden" id="pn-focus-x" value="<?= $_pnFocusX ?>" />
				<input type="hidden" id="pn-focus-y" value="<?= $_pnFocusY ?>" />
				<input type="hidden" id="pn-focus-size" value="<?= (int)($Player['PhotoFocusSize'] ?? 100) ?>" />
				<?php else: ?>
				<div class="pn-about-empty">
					<i class="fas fa-camera" style="font-size:28px;color:#cbd5e0;margin-bottom:10px"></i>
					<p>Upload a player photo first, then come back here to set the focus area.</p>
				</div>
				<?php endif; ?>
			</div>

			<!-- Milestones Panel -->
			<div class="pn-design-panel" id="pn-design-milestones">
				<div style="margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid #e2e8f0">
					<label style="display:flex;align-items:center;gap:10px;cursor:pointer;font-size:13px;font-weight:600;color:#4a5568">
						<input type="checkbox" id="pn-ms-compact" style="width:18px;height:18px;accent-color:var(--pn-accent,#4299e1)" />
						Compact Milestones
					</label>
					<div class="pn-design-hint" style="margin-top:4px">Move your milestones to a compact sidebar list (icon + mm/yy &ndash; title) instead of the large timeline at the bottom of About.</div>
				</div>
				<div class="pn-design-hint" style="margin-bottom:14px">Choose which milestone types appear on your timeline. All types are shown by default.</div>
				<div class="pn-ms-toggle-list" id="pn-ms-toggles">
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="first_signin" checked /><i class="fas fa-door-open"></i> First Sign-In</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="level6" checked /><i class="fas fa-hat-wizard"></i> Reached Level 6</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="master" checked /><i class="fas fa-star"></i> Earned Master</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="paragon" checked /><i class="fas fa-gem"></i> Earned Paragon</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="knight" checked /><i class="fas fa-shield-alt"></i> Earned Knight</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="title" checked /><i class="fas fa-crown"></i> Earned Title</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="became_associate" checked /><i class="fas fa-handshake"></i> Became Associate</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="took_associate" checked /><i class="fas fa-hand-holding-heart"></i> Took Associate</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="officer" checked /><i class="fas fa-landmark"></i> Served as Officer</label>
					<label class="pn-ms-toggle"><input type="checkbox" data-ms-type="custom" checked /><i class="fas fa-pen"></i> Custom Milestones</label>
				</div>
				<div style="border-top:1px solid #e2e8f0;padding-top:16px;margin-top:8px">
					<div style="font-size:13px;font-weight:700;color:#2d3748;margin-bottom:10px">Custom Milestones</div>
					<div class="pn-ms-custom-list" id="pn-ms-custom-list"></div>
					<div class="pn-ms-add-form" id="pn-ms-add-form">
						<div class="pn-ms-add-row">
							<div class="pn-ms-field">
								<label>Description</label>
								<input type="text" id="pn-ms-add-desc" maxlength="500" placeholder="What happened?" />
							</div>
							<div class="pn-ms-field">
								<label>Date</label>
								<input type="date" id="pn-ms-add-date" />
							</div>
							<div class="pn-ms-field" style="flex-basis:100%">
								<label>Icon</label>
								<div class="pn-ms-icon-grid" id="pn-ms-icon-grid">
									<div class="pn-ms-icon-opt pn-ms-icon-active" data-icon="fa-star" title="Star"><i class="fas fa-star"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-trophy" title="Trophy"><i class="fas fa-trophy"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-heart" title="Heart"><i class="fas fa-heart"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-flag" title="Flag"><i class="fas fa-flag"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-bolt" title="Bolt"><i class="fas fa-bolt"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-fire" title="Fire"><i class="fas fa-fire"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-book" title="Book"><i class="fas fa-book"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-users" title="Group"><i class="fas fa-users"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-map-marker-alt" title="Map Pin"><i class="fas fa-map-marker-alt"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-campground" title="Camp"><i class="fas fa-campground"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-scroll" title="Scroll"><i class="fas fa-scroll"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-hammer" title="Hammer"><i class="fas fa-hammer"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-dragon" title="Dragon"><i class="fas fa-dragon"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-dice-d20" title="D20"><i class="fas fa-dice-d20"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-skull-crossbones" title="Skull"><i class="fas fa-skull-crossbones"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-fist-raised" title="Fist"><i class="fas fa-fist-raised"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-music" title="Music"><i class="fas fa-music"></i></div>
									<div class="pn-ms-icon-opt" data-icon="fa-paint-brush" title="Paint"><i class="fas fa-paint-brush"></i></div>
								</div>
							</div>
							<button class="pn-ms-add-btn" id="pn-ms-add-btn"><i class="fas fa-plus"></i> Add</button>
						</div>
						<div class="pn-ms-error" id="pn-ms-add-error"></div>
					</div>
				</div>
			</div>
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-design-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-design-save"><i class="fas fa-save"></i> Save Changes</button>
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
					<div id="pn-rec-award-desc" class="pn-rec-award-desc" style="display:none"></div>
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
	isOwnProfile:   <?= !empty($isOwnProfile) ? 'true' : 'false' ?>,
	aboutPersona:   <?= json_encode($Player['AboutPersona'] ?? '') ?>,
	aboutStory:     <?= json_encode($Player['AboutStory'] ?? '') ?>,
	colorPrimary:   <?= json_encode($Player['ColorPrimary'] ?? '') ?>,
	colorAccent:    <?= json_encode($Player['ColorAccent'] ?? '') ?>,
	namePrefix:     <?= json_encode($Player['NamePrefix'] ?? '') ?>,
	nameSuffix:     <?= json_encode($Player['NameSuffix'] ?? '') ?>,
	photoFocusX:    <?= (int)($Player['PhotoFocusX'] ?? 50) ?>,
	photoFocusY:    <?= (int)($Player['PhotoFocusY'] ?? 50) ?>,
	photoFocusSize: <?= (int)($Player['PhotoFocusSize'] ?? 100) ?>,
	hasImage:       <?= ($Player['HasImage'] > 0) ? 'true' : 'false' ?>,
	imageUrl:       <?= json_encode($imageUrl) ?>,
	playerTitles:   <?= json_encode($PlayerTitles ?? []) ?>,
	milestoneConfig: <?= json_encode(json_decode($Player['MilestoneConfig'] ?? '{}', true) ?: new stdClass()) ?>,
	customMilestones: <?= json_encode($CustomMilestones ?? []) ?>,
	nameFont:        <?= json_encode($Player['NameFont'] ?? '') ?>,
};
// Use the viewed player's kingdom for nav search prioritization if the user has no home kingdom
if (typeof nsKid !== 'undefined' && nsKid === 0 && PnConfig.kingdomId) nsKid = PnConfig.kingdomId;
</script>
<script src="https://cdn.jsdelivr.net/npm/marked@12/marked.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/dompurify@3/dist/purify.min.js"></script>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script>
// ---- Markdown rendering for About tab ----
(function() {
	var personaEl = document.getElementById('pn-about-persona-rendered');
	var storyEl   = document.getElementById('pn-about-story-rendered');
	function renderMd(raw) {
		if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
			return DOMPurify.sanitize(marked.parse(raw || ''));
		}
		return (raw || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/\n/g,'<br>');
	}
	if (personaEl && PnConfig.aboutPersona) personaEl.innerHTML = renderMd(PnConfig.aboutPersona);
	if (storyEl && PnConfig.aboutStory) storyEl.innerHTML = renderMd(PnConfig.aboutStory);
})();

// ---- Photo Focus (precise pixel positioning) ----
(function() {
	var imgs = document.querySelectorAll('.pn-avatar img[data-focus-size]');
	function applyFocus(img) {
		var fx = parseFloat(img.dataset.focusX);
		var fy = parseFloat(img.dataset.focusY);
		var fs = parseFloat(img.dataset.focusSize);
		if (isNaN(fx) || isNaN(fy) || isNaN(fs)) return;
		var box = img.parentElement;
		var cw = box.offsetWidth || 110, ch = box.offsetHeight || 110;
		// Zoom: fs=100 → 1x (fills container), fs=50 → 2x, etc.
		// Using object-fit:cover preserves aspect ratio and handles EXIF rotation correctly.
		var zoom = 100 / Math.max(15, fs);
		var ew = cw * zoom, eh = ch * zoom;
		// Focal point within the (zoomed) element
		var ox = (fx / 100) * ew - cw / 2;
		var oy = (fy / 100) * eh - ch / 2;
		ox = Math.max(0, Math.min(ew - cw, ox));
		oy = Math.max(0, Math.min(eh - ch, oy));
		img.style.position = 'absolute';
		img.style.width = ew + 'px';
		img.style.height = eh + 'px';
		img.style.left = (-ox) + 'px';
		img.style.top = (-oy) + 'px';
		img.style.objectFit = 'cover';
		img.style.objectPosition = fx + '% ' + fy + '%';
		img.style.maxWidth = 'none';
	}
	imgs.forEach(function(img) {
		applyFocus(img);
		if (!img.complete) img.addEventListener('load', function() { applyFocus(img); });
	});
})();

// ---- Design My Profile Modal ----
(function() {
	if (!PnConfig.isOwnProfile) return;
	function gid(id) { return document.getElementById(id); }

	// Open/Close
	window.pnOpenDesignModal = function() {
		gid('pn-design-error').style.display = 'none';
		gid('pn-design-error').textContent = '';
		gid('pn-design-overlay').classList.add('pn-open');
		document.body.style.overflow = 'hidden';
		initFocusTool();
	};
	function closeDesign() {
		gid('pn-design-overlay').classList.remove('pn-open');
		document.body.style.overflow = '';
	}
	gid('pn-design-close-btn').addEventListener('click', closeDesign);
	gid('pn-design-cancel').addEventListener('click', closeDesign);
	gid('pn-design-overlay').addEventListener('click', function(e) { if (e.target === this) closeDesign(); });
	document.addEventListener('keydown', function(e) {
		if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-design-overlay').classList.contains('pn-open')) closeDesign();
	});

	// Tab switching
	function pnSwitchDesignPanel(name) {
		document.querySelectorAll('.pn-design-tab').forEach(function(t) { t.classList.remove('pn-active'); });
		document.querySelectorAll('.pn-design-panel').forEach(function(p) { p.classList.remove('pn-active'); });
		var tabBtn = document.querySelector('.pn-design-tab[data-panel="' + name + '"]');
		if (tabBtn) tabBtn.classList.add('pn-active');
		var panel = gid('pn-design-' + name);
		if (panel) panel.classList.add('pn-active');
		if (name === 'focus') initFocusTool();
	}
	document.querySelectorAll('.pn-design-tab').forEach(function(tab) {
		tab.addEventListener('click', function() { pnSwitchDesignPanel(tab.dataset.panel); });
	});
	// Welcome card jump links
	document.querySelectorAll('.pn-welcome-card').forEach(function(card) {
		card.addEventListener('click', function() {
			var target = card.dataset.go;
			if (target) pnSwitchDesignPanel(target);
		});
	});

	// Markdown preview toggles
	document.querySelectorAll('.pn-md-toggle-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var field = btn.dataset.field;
			var target = btn.dataset.target;
			var textareaId = 'pn-design-about-' + field;
			var previewId  = textareaId + '-preview';
			var textarea = gid(textareaId);
			var preview  = gid(previewId);
			btn.parentElement.querySelectorAll('.pn-md-toggle-btn').forEach(function(b) { b.classList.remove('pn-active'); });
			btn.classList.add('pn-active');
			if (target === 'preview') {
				textarea.style.display = 'none';
				preview.style.display = '';
				var raw = textarea.value;
				if (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined') {
					preview.innerHTML = DOMPurify.sanitize(marked.parse(raw || ''));
				} else {
					preview.textContent = raw;
				}
			} else {
				textarea.style.display = '';
				preview.style.display = 'none';
			}
		});
	});

	// Color presets
	var allSwatches = document.querySelectorAll('.pn-color-swatch');
	allSwatches.forEach(function(sw) {
		sw.addEventListener('click', function() {
			allSwatches.forEach(function(s) { s.classList.remove('pn-selected'); });
			sw.classList.add('pn-selected');
			gid('pn-color-primary').value = sw.dataset.primary;
			gid('pn-color-primary-hex').value = sw.dataset.primary;
			gid('pn-color-accent').value = sw.dataset.accent;
			gid('pn-color-accent-hex').value = sw.dataset.accent;
			if (sw.dataset.secondary) {
				gid('pn-color-secondary').value = sw.dataset.secondary;
				gid('pn-color-secondary-hex').value = sw.dataset.secondary;
				gid('pn-gradient-enabled').checked = true;
			} else {
				gid('pn-color-secondary-hex').value = '';
				gid('pn-gradient-enabled').checked = false;
			}
			updateColorPreview();
		});
	});

	// Color picker sync
	gid('pn-color-primary').addEventListener('input', function() { gid('pn-color-primary-hex').value = this.value; syncPresetSwatch(); updateColorPreview(); });
	gid('pn-color-accent').addEventListener('input', function() { gid('pn-color-accent-hex').value = this.value; syncPresetSwatch(); updateColorPreview(); });
	gid('pn-color-primary-hex').addEventListener('input', function() {
		if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid('pn-color-primary').value = this.value; syncPresetSwatch(); updateColorPreview(); }
	});
	gid('pn-color-accent-hex').addEventListener('input', function() {
		if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid('pn-color-accent').value = this.value; syncPresetSwatch(); updateColorPreview(); }
	});
	gid('pn-color-reset').addEventListener('click', function() {
		gid('pn-color-primary').value = '#2c5282'; gid('pn-color-primary-hex').value = '#2c5282';
		gid('pn-color-accent').value = '#4299e1'; gid('pn-color-accent-hex').value = '#4299e1';
		gid('pn-color-secondary-hex').value = ''; gid('pn-color-secondary').value = '#2c5282';
		gid('pn-gradient-enabled').checked = false;
		gid('pn-hero-overlay').value = 'med';
		document.querySelectorAll('.pn-overlay-btn').forEach(function(b) { b.classList.remove('pn-active'); });
		document.querySelector('.pn-overlay-btn[data-overlay="med"]').classList.add('pn-active');
		syncPresetSwatch(); updateColorPreview();
	});
	// Secondary color / gradient toggle
	gid('pn-color-secondary').addEventListener('input', function() { gid('pn-color-secondary-hex').value = this.value; updateColorPreview(); });
	gid('pn-color-secondary-hex').addEventListener('input', function() {
		if (/^#[0-9a-f]{6}$/i.test(this.value)) { gid('pn-color-secondary').value = this.value; updateColorPreview(); }
	});
	gid('pn-gradient-enabled').addEventListener('change', function() {
		if (this.checked && !gid('pn-color-secondary-hex').value) {
			gid('pn-color-secondary-hex').value = gid('pn-color-accent').value;
			gid('pn-color-secondary').value = gid('pn-color-accent').value;
		}
		updateColorPreview();
	});
	// Overlay strength buttons
	document.querySelectorAll('.pn-overlay-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			document.querySelectorAll('.pn-overlay-btn').forEach(function(b) { b.classList.remove('pn-active'); });
			btn.classList.add('pn-active');
			gid('pn-hero-overlay').value = btn.dataset.overlay;
		});
	});
	function syncPresetSwatch() {
		var p = gid('pn-color-primary').value.toLowerCase();
		var a = gid('pn-color-accent').value.toLowerCase();
		var s = (gid('pn-gradient-enabled').checked ? gid('pn-color-secondary').value : '').toLowerCase();
		allSwatches.forEach(function(sw) {
			var matchBase = sw.dataset.primary.toLowerCase() === p && sw.dataset.accent.toLowerCase() === a;
			var swSec = (sw.dataset.secondary || '').toLowerCase();
			sw.classList.toggle('pn-selected', matchBase && swSec === s);
		});
	}
	function updateColorPreview() {
		var preview = gid('pn-color-hero-preview');
		if (!preview) return;
		var primary = gid('pn-color-primary').value;
		var gradientOn = gid('pn-gradient-enabled').checked;
		var secondary = gid('pn-color-secondary').value;
		if (gradientOn && secondary) {
			preview.style.background = 'linear-gradient(135deg, ' + primary + ', ' + secondary + ')';
		} else {
			preview.style.background = primary;
		}
	}
	updateColorPreview();
	syncPresetSwatch();

	// Name builder
	var prefixSel = gid('pn-name-prefix-select');
	var suffixSel = gid('pn-name-suffix-select');
	var prefixCustom = gid('pn-name-prefix-custom');
	var suffixCustom = gid('pn-name-suffix-custom');
	var coreInput = gid('pn-name-core');
	function updateNamePreview() {
		var prefix = '';
		if (prefixSel.value === '__custom__') { prefix = prefixCustom.value.trim(); }
		else if (prefixSel.value) { prefix = prefixSel.value; }
		var suffix = '';
		if (suffixSel.value === '__custom__') { suffix = suffixCustom.value.trim(); }
		else if (suffixSel.value) { suffix = suffixSel.value; }
		var core = coreInput.value.trim() || PnConfig.playerPersona;
		var useComma = gid('pn-suffix-comma-toggle').classList.contains('pn-active');
		var full = '';
		if (prefix) full += prefix + ' ';
		full += core;
		if (suffix) full += (useComma ? ', ' : ' ') + suffix;
		gid('pn-name-preview').textContent = full;
	}
	gid('pn-suffix-comma-toggle').addEventListener('click', function() {
		this.classList.toggle('pn-active');
		updateNamePreview();
	});
	prefixSel.addEventListener('change', function() {
		prefixCustom.style.display = this.value === '__custom__' ? '' : 'none';
		if (this.value !== '__custom__') prefixCustom.value = '';
		updateNamePreview();
	});
	suffixSel.addEventListener('change', function() {
		suffixCustom.style.display = this.value === '__custom__' ? '' : 'none';
		if (this.value !== '__custom__') suffixCustom.value = '';
		updateNamePreview();
	});
	prefixCustom.addEventListener('input', updateNamePreview);
	suffixCustom.addEventListener('input', updateNamePreview);
	coreInput.addEventListener('input', updateNamePreview);

	// Font picker
	var PN_FONTS = [
		{key:'',label:'Default',family:'inherit'},
		{key:'Cinzel',label:'Cinzel',family:'Cinzel'},
		{key:'Cinzel Decorative',label:'Cinzel Deco',family:"'Cinzel Decorative'"},
		{key:'IM Fell English',label:'IM Fell English',family:"'IM Fell English'"},
		{key:'UnifrakturMaguntia',label:'Unifraktur',family:'UnifrakturMaguntia'},
		{key:'Metamorphous',label:'Metamorphous',family:'Metamorphous'},
		{key:'Uncial Antiqua',label:'Uncial Antiqua',family:"'Uncial Antiqua'"},
		{key:'Pirata One',label:'Pirata One',family:"'Pirata One'"},
		{key:'Almendra',label:'Almendra',family:'Almendra'},
		{key:'Pinyon Script',label:'Pinyon Script',family:"'Pinyon Script'"},
		{key:'Great Vibes',label:'Great Vibes',family:"'Great Vibes'"},
	];
	var pnSelectedFont = PnConfig.nameFont || '';
	var pnLoadedFonts = {};
	function pnLoadFont(key) {
		if (!key || pnLoadedFonts[key]) return;
		var link = document.createElement('link');
		link.rel = 'stylesheet';
		link.href = 'https://fonts.googleapis.com/css2?family=' + key.replace(/ /g, '+') + '&display=swap';
		document.head.appendChild(link);
		pnLoadedFonts[key] = true;
	}
	function pnApplyFont(key) {
		var f = null;
		for (var _i = 0; _i < PN_FONTS.length; _i++) { if (PN_FONTS[_i].key === key) { f = PN_FONTS[_i]; break; } }
		if (!f) f = PN_FONTS[0];
		var fam = f.family;
		var preview = gid('pn-name-preview');
		var heroPreview = document.querySelector('.pn-hero-preview-name');
		var heroName = gid('pn-hero-persona');
		if (preview) preview.style.fontFamily = fam;
		if (heroPreview) heroPreview.style.fontFamily = fam;
		if (heroName) heroName.style.fontFamily = fam;
	}
	function pnRenderFontPicker() {
		var container = gid('pn-font-picker');
		if (!container) return;
		var sample = (PnConfig.namePrefix ? PnConfig.namePrefix + ' ' : '') + PnConfig.playerPersona;
		var html = '';
		for (var _j = 0; _j < PN_FONTS.length; _j++) {
			var _f = PN_FONTS[_j];
			var _active = _f.key === pnSelectedFont;
			html += '<div class="pn-font-card' + (_active ? ' pn-active' : '') + '" data-font-key="' + escHtml(_f.key) + '">';
			html += '<div class="pn-font-card-sample" style="font-family:' + _f.family + '">' + escHtml(sample) + '</div>';
			html += '<div class="pn-font-card-label">' + escHtml(_f.label) + '</div>';
			html += '</div>';
		}
		container.innerHTML = html;
		for (var _k = 1; _k < PN_FONTS.length; _k++) pnLoadFont(PN_FONTS[_k].key);
		container.addEventListener('click', function(e) {
			var card = e.target.closest('.pn-font-card');
			if (!card) return;
			pnSelectedFont = card.getAttribute('data-font-key');
			var cards = container.querySelectorAll('.pn-font-card');
			for (var _m = 0; _m < cards.length; _m++) cards[_m].classList.toggle('pn-active', cards[_m] === card);
			if (pnSelectedFont) pnLoadFont(pnSelectedFont);
			pnApplyFont(pnSelectedFont);
		});
	}
	pnRenderFontPicker();
	if (pnSelectedFont) { pnLoadFont(pnSelectedFont); pnApplyFont(pnSelectedFont); }
	// Re-render after all fonts land — fonts.ready resolves too early (before downloads finish).
	// fonts.load() per family triggers downloads and resolves only when each is paint-ready.
	if (document.fonts && document.fonts.load) {
		Promise.all([
			document.fonts.load('16px Cinzel'),
			document.fonts.load('16px "Cinzel Decorative"'),
			document.fonts.load('16px "IM Fell English"'),
			document.fonts.load('16px UnifrakturMaguntia'),
			document.fonts.load('16px Metamorphous'),
			document.fonts.load('16px "Uncial Antiqua"'),
			document.fonts.load('16px "Pirata One"'),
			document.fonts.load('16px Almendra'),
			document.fonts.load("16px 'Pinyon Script'"),
			document.fonts.load("16px 'Great Vibes'"),
		]).then(function() { pnRenderFontPicker(); });
	}

	// Photo focus tool
	var focusImg = null, focusCanvas = null, focusCtx = null;
	var focusCircle = { x: PnConfig.photoFocusX, y: PnConfig.photoFocusY, size: PnConfig.photoFocusSize };
	var focusScale = 1, focusImgW = 0, focusImgH = 0;
	var focusInited = false;

	function initFocusTool() {
		if (focusInited || !PnConfig.hasImage) return;
		focusCanvas = gid('pn-focus-canvas');
		if (!focusCanvas) return;
		focusCtx = focusCanvas.getContext('2d');
		var img = new Image();
		img.crossOrigin = 'anonymous';
		img.onload = function() {
			focusImg = img;
			focusImgW = img.width; focusImgH = img.height;
			var maxW = Math.min(400, window.innerWidth - 120);
			var maxH = Math.min(320, window.innerHeight - 340);
			focusScale = Math.min(maxW / img.width, maxH / img.height, 1);
			focusCanvas.width = Math.round(img.width * focusScale);
			focusCanvas.height = Math.round(img.height * focusScale);
			drawFocus();
			bindFocusEvents();
			focusInited = true;
		};
		img.src = PnConfig.imageUrl;
	}

	function drawFocus() {
		if (!focusImg || !focusCtx) return;
		var cw = focusCanvas.width, ch = focusCanvas.height;
		focusCtx.clearRect(0, 0, cw, ch);
		focusCtx.drawImage(focusImg, 0, 0, cw, ch);
		// Dim everything
		focusCtx.fillStyle = 'rgba(0,0,0,0.55)';
		focusCtx.fillRect(0, 0, cw, ch);
		// Cut out circle
		var cx = focusCircle.x / 100 * cw;
		var cy = focusCircle.y / 100 * ch;
		var radius = focusCircle.size / 100 * Math.min(cw, ch) / 2;
		radius = Math.max(20, Math.min(radius, Math.min(cw, ch) / 2));
		focusCtx.save();
		focusCtx.beginPath();
		focusCtx.arc(cx, cy, radius, 0, Math.PI * 2);
		focusCtx.clip();
		focusCtx.drawImage(focusImg, 0, 0, cw, ch);
		focusCtx.restore();
		// Circle border
		focusCtx.beginPath();
		focusCtx.arc(cx, cy, radius, 0, Math.PI * 2);
		focusCtx.strokeStyle = 'rgba(255,255,255,0.9)';
		focusCtx.lineWidth = 2;
		focusCtx.stroke();
		// Resize handle at bottom-right of circle
		var hx = cx + radius * 0.707, hy = cy + radius * 0.707;
		focusCtx.fillStyle = '#fff';
		focusCtx.beginPath();
		focusCtx.arc(hx, hy, 6, 0, Math.PI * 2);
		focusCtx.fill();
		focusCtx.strokeStyle = 'rgba(0,0,0,0.3)';
		focusCtx.lineWidth = 1;
		focusCtx.stroke();
	}

	function bindFocusEvents() {
		var dragging = null;
		function getPos(e) {
			var r = focusCanvas.getBoundingClientRect();
			var src = e.touches ? e.touches[0] : e;
			return { x: src.clientX - r.left, y: src.clientY - r.top };
		}
		function onDown(e) {
			e.preventDefault();
			var pos = getPos(e);
			var cw = focusCanvas.width, ch = focusCanvas.height;
			var cx = focusCircle.x / 100 * cw, cy = focusCircle.y / 100 * ch;
			var radius = focusCircle.size / 100 * Math.min(cw, ch) / 2;
			// Check resize handle
			var hx = cx + radius * 0.707, hy = cy + radius * 0.707;
			if (Math.hypot(pos.x - hx, pos.y - hy) < 12) {
				dragging = { type: 'resize', startSize: focusCircle.size, startDist: Math.hypot(pos.x - cx, pos.y - cy) };
				return;
			}
			// Check if inside circle (move)
			if (Math.hypot(pos.x - cx, pos.y - cy) <= radius) {
				dragging = { type: 'move', ox: focusCircle.x, oy: focusCircle.y, sx: pos.x, sy: pos.y };
			}
		}
		function onMove(e) {
			if (!dragging) return;
			var pos = getPos(e);
			var cw = focusCanvas.width, ch = focusCanvas.height;
			if (dragging.type === 'move') {
				var dx = (pos.x - dragging.sx) / cw * 100;
				var dy = (pos.y - dragging.sy) / ch * 100;
				focusCircle.x = Math.max(0, Math.min(100, dragging.ox + dx));
				focusCircle.y = Math.max(0, Math.min(100, dragging.oy + dy));
			} else if (dragging.type === 'resize') {
				var cx = focusCircle.x / 100 * cw, cy = focusCircle.y / 100 * ch;
				var dist = Math.hypot(pos.x - cx, pos.y - cy);
				var newSize = dragging.startSize * (dist / dragging.startDist);
				focusCircle.size = Math.max(15, Math.min(100, newSize));
			}
			gid('pn-focus-x').value = Math.round(focusCircle.x);
			gid('pn-focus-y').value = Math.round(focusCircle.y);
			gid('pn-focus-size').value = Math.round(focusCircle.size);
			drawFocus();
		}
		function onUp() { dragging = null; }
		focusCanvas.addEventListener('mousedown', onDown);
		focusCanvas.addEventListener('touchstart', onDown, { passive: false });
		window.addEventListener('mousemove', onMove);
		window.addEventListener('touchmove', onMove, { passive: false });
		window.addEventListener('mouseup', onUp);
		window.addEventListener('touchend', onUp);
	}

	// Save
	gid('pn-design-save').addEventListener('click', function() {
		var btn = this;
		btn.disabled = true;
		btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		var errEl = gid('pn-design-error');
		errEl.style.display = 'none';

		var prefix = '';
		if (prefixSel.value === '__custom__') prefix = prefixCustom.value.trim();
		else if (prefixSel.value) prefix = prefixSel.value;
		var suffix = '';
		if (suffixSel.value === '__custom__') suffix = suffixCustom.value.trim();
		else if (suffixSel.value) suffix = suffixSel.value;
		var coreName = coreInput.value.trim();
		if (!coreName) {
			errEl.textContent = 'Core name is required.';
			errEl.style.display = '';
			btn.disabled = false;
			btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
			return;
		}
		var fd = new FormData();
		fd.append('AboutPersona', gid('pn-design-about-persona').value);
		fd.append('AboutStory', gid('pn-design-about-story').value);
		fd.append('ColorPrimary', gid('pn-color-primary').value);
		fd.append('ColorAccent', gid('pn-color-accent').value);
		fd.append('ColorSecondary', gid('pn-gradient-enabled').checked ? gid('pn-color-secondary').value : '');
		fd.append('HeroOverlay', gid('pn-hero-overlay').value);
		fd.append('NamePrefix', prefix);
		fd.append('NameSuffix', suffix);
		fd.append('SuffixComma', gid('pn-suffix-comma-toggle').classList.contains('pn-active') ? 1 : 0);
		fd.append('Persona', coreName);
		fd.append('PhotoFocusX', gid('pn-focus-x') ? gid('pn-focus-x').value : PnConfig.photoFocusX);
		fd.append('PhotoFocusY', gid('pn-focus-y') ? gid('pn-focus-y').value : PnConfig.photoFocusY);
		fd.append('PhotoFocusSize', gid('pn-focus-size') ? gid('pn-focus-size').value : PnConfig.photoFocusSize);
		fd.append('ShowBeltline', gid('pn-design-show-beltline').checked ? 1 : 0);
		fd.append('PronunciationGuide', gid('pn-design-pronunciation').value);
		fd.append('ShowMundaneFirst', gid('pn-design-show-first').checked ? 1 : 0);
		fd.append('ShowMundaneLast', gid('pn-design-show-last').checked ? 1 : 0);
		fd.append('ShowEmail', gid('pn-design-show-email').checked ? 1 : 0);

		// Milestone config
		var msConfig = {};
		var msToggles = document.querySelectorAll('#pn-ms-toggles input[data-ms-type]');
		for (var i = 0; i < msToggles.length; i++) {
			msConfig[msToggles[i].getAttribute('data-ms-type')] = msToggles[i].checked ? 1 : 0;
		}
		var compactEl = gid('pn-ms-compact');
		msConfig['compact_milestones'] = (compactEl && compactEl.checked) ? 1 : 0;
		fd.append('MilestoneConfig', JSON.stringify(msConfig));
			fd.append('NameFont', pnSelectedFont || '');

		fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/updateprofile', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					window.location.reload();
				} else {
					errEl.textContent = (result && result.error) ? result.error : 'Save failed.';
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				}
			})
			.catch(function(err) {
				errEl.textContent = 'Request failed: ' + err.message;
				errEl.style.display = '';
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
			});
	});
})();

// ---- Milestones Config (Design My Profile) ----
(function() {
	if (!PnConfig.isOwnProfile) return;

	// Init toggles from saved config
	var cfg = PnConfig.milestoneConfig || {};
	var compactToggle = document.getElementById('pn-ms-compact');
	if (compactToggle) compactToggle.checked = !!cfg['compact_milestones'];
	var toggles = document.querySelectorAll('#pn-ms-toggles input[data-ms-type]');
	for (var i = 0; i < toggles.length; i++) {
		var msType = toggles[i].getAttribute('data-ms-type');
		// Default ON if not in config
		if (typeof cfg[msType] !== 'undefined' && !cfg[msType]) {
			toggles[i].checked = false;
		}
	}

	// Render custom milestones list
	var customList = document.getElementById('pn-ms-custom-list');
	var customData = PnConfig.customMilestones || [];
	function renderCustomList() {
		if (!customList) return;
		if (customData.length === 0) {
			customList.innerHTML = '<div style="font-size:12px;color:#a0aec0;padding:8px 0">No custom milestones yet.</div>';
			return;
		}
		var html = '';
		for (var i = 0; i < customData.length; i++) {
			var m = customData[i];
			var dateStr = m.MilestoneDate || '';
			if (dateStr && dateStr !== '0000-00-00') {
				var d = new Date(dateStr + 'T00:00:00');
				dateStr = d.toLocaleDateString('en-US', {month:'short',day:'numeric',year:'numeric'});
			}
			html += '<div class="pn-ms-custom-row" data-ms-id="' + m.MilestoneId + '">'
				+ '<i class="fas ' + (m.Icon || 'fa-star').replace(/[^a-z0-9-]/g,'') + '"></i>'
				+ '<span class="pn-ms-custom-desc">' + (m.Description || '').replace(/</g,'&lt;').replace(/>/g,'&gt;') + '</span>'
				+ '<span class="pn-ms-custom-date">' + dateStr + '</span>'
				+ '<span class="pn-ms-custom-actions">'
				+ '<button title="Delete" onclick="pnDeleteMilestone(' + m.MilestoneId + ')"><i class="fas fa-trash-alt"></i></button>'
				+ '</span></div>';
		}
		customList.innerHTML = html;
	}
	renderCustomList();

	// Icon grid selection
	var iconGrid = document.getElementById('pn-ms-icon-grid');
	var selectedIcon = 'fa-star';
	if (iconGrid) {
		iconGrid.addEventListener('click', function(e) {
			var opt = e.target.closest('.pn-ms-icon-opt');
			if (!opt) return;
			var prev = iconGrid.querySelector('.pn-ms-icon-active');
			if (prev) prev.classList.remove('pn-ms-icon-active');
			opt.classList.add('pn-ms-icon-active');
			selectedIcon = opt.getAttribute('data-icon');
		});
	}

	// Add custom milestone
	var addBtn = document.getElementById('pn-ms-add-btn');
	var addErr = document.getElementById('pn-ms-add-error');
	if (addBtn) {
		addBtn.addEventListener('click', function() {
			var desc = document.getElementById('pn-ms-add-desc').value.trim();
			var dt   = document.getElementById('pn-ms-add-date').value;
			var icon = selectedIcon;
			addErr.style.display = 'none';
			if (!desc) { addErr.textContent = 'Description is required.'; addErr.style.display = ''; return; }
			if (!dt)   { addErr.textContent = 'Date is required.'; addErr.style.display = ''; return; }
			addBtn.disabled = true;
			addBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
			var fd = new FormData();
			fd.append('Description', desc);
			fd.append('MilestoneDate', dt);
			fd.append('Icon', icon);
			fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/addmilestone', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(result) {
					if (result && result.status === 0) {
						customData.push({ MilestoneId: result.milestoneId, Icon: icon, Description: desc, MilestoneDate: dt });
						renderCustomList();
						document.getElementById('pn-ms-add-desc').value = '';
						document.getElementById('pn-ms-add-date').value = '';
						var prevIcon = iconGrid.querySelector('.pn-ms-icon-active');
						if (prevIcon) prevIcon.classList.remove('pn-ms-icon-active');
						var defIcon = iconGrid.querySelector('[data-icon="fa-star"]');
						if (defIcon) defIcon.classList.add('pn-ms-icon-active');
						selectedIcon = 'fa-star';
					} else {
						addErr.textContent = (result && result.error) || 'Failed to add milestone.';
						addErr.style.display = '';
					}
					addBtn.disabled = false;
					addBtn.innerHTML = '<i class="fas fa-plus"></i> Add';
				})
				.catch(function(e) {
					addErr.textContent = 'Request failed.';
					addErr.style.display = '';
					addBtn.disabled = false;
					addBtn.innerHTML = '<i class="fas fa-plus"></i> Add';
				});
		});
	}

	// Delete custom milestone
	window.pnDeleteMilestone = function(msId) {
		if (!confirm('Delete this custom milestone?')) return;
		var fd = new FormData();
		fd.append('MilestoneId', msId);
		fetch(PnConfig.uir + 'PlayerAjax/player/' + PnConfig.playerId + '/deletemilestone', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(result) {
				if (result && result.status === 0) {
					customData = customData.filter(function(m) { return m.MilestoneId !== msId; });
					renderCustomList();
				} else {
					alert((result && result.error) || 'Failed to delete milestone.');
				}
			})
			.catch(function() { alert('Request failed.'); });
	};
})();

pnSortDesc($('#pn-awards-table'), 2, 'date', 1, 'numeric');     pnPaginate($('#pn-awards-table'), 1);
pnSortDesc($('#pn-titles-table'), 2, 'date', 1, 'numeric');     pnPaginate($('#pn-titles-table'), 1);
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

<!-- Clear Notes Confirm Modal -->
<div class="pn-overlay" id="pn-clearnotes-overlay" style="display:none">
	<div class="pn-modal-box" style="width:440px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#c05621"></i>Close Out Notes Tab</h3>
			<button class="pn-modal-close-btn" id="pn-clearnotes-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div id="pn-clearnotes-feedback" style="display:none"></div>
			<p style="margin:0 0 12px;font-size:14px;color:var(--ork-text)">This will permanently delete all notes on your profile and remove the Notes tab. This cannot be undone.</p>
			<p style="margin:0;font-size:13px;color:var(--ork-text-muted)">Make sure you have reconciled any relevant information with your Monarch or Prime Minister before continuing.</p>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-clearnotes-cancel">Cancel</button>
			<button class="pn-btn" id="pn-clearnotes-confirm" style="background:#c05621;color:#fff"><i class="fas fa-trash"></i> Delete All Notes</button>
		</div>
	</div>
</div>

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