<?php
	require_once(DIR_LIB . 'Parsedown.php');
	function ev_markdown(string $text): string {
		$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($text);
		return preg_replace('/<img[^>]*>/i', '', $html);
	}

	// ---- Normalize data ----
	$info      = $EventInfo   ?? [];
	$cd        = $EventDetail ?? [];
	$eventId   = (int)($event_id  ?? 0);
	$detailId  = (int)($detail_id ?? 0);
	$loggedIn  = $LoggedIn ?? false;

	$eventName   = htmlspecialchars($info['Name'] ?? 'Event');
	$hasHeraldry = !empty($info['HasHeraldry']);
	$heraldryUrl = $hasHeraldry
		? HTTP_EVENT_HERALDRY . Common::resolve_image_ext(DIR_EVENT_HERALDRY, sprintf('%05d', $eventId))
		: HTTP_EVENT_HERALDRY . '00000.jpg';

	$kingdomId   = (int)($info['KingdomId'] ?? 0);
	$kingdomName = htmlspecialchars($info['KingdomName'] ?? '');
	$parkId      = (int)($info['ParkId']    ?? 0);
	$parkName    = htmlspecialchars($info['ParkName']    ?? '');
	$atParkId    = (int)($cd['AtParkId']   ?? 0);
	$atParkName  = htmlspecialchars($AtParkName         ?? '');
	$unitId      = (int)($info['UnitId']    ?? 0);
	$unitName    = htmlspecialchars($info['Unit']        ?? '');
	$mundaneId   = (int)($info['MundaneId'] ?? 0);
	$persona     = htmlspecialchars($info['Persona']     ?? '');

	$isUpcoming    = $IsUpcoming     ?? false;
	$isOngoing     = $IsOngoing      ?? false;
	$attendeeCount = $AttendanceCount ?? 0;
	$mapLink       = $MapLink        ?? '';

	$eventStart  = $cd['EventStart']  ?? null;
	$eventEnd    = $cd['EventEnd']    ?? null;
	$price       = (float)($cd['Price'] ?? 0);
	$eventFees   = $EventFees ?? [];
	$description = $cd['Description'] ?? '';
	$hasDescription = !empty(trim($description));
	$websiteUrl  = $cd['Url']     ?? '';
	$websiteName = $cd['UrlName'] ?? '';
	$mapUrlName  = $cd['MapUrlName'] ?? '';
	$mapUrl      = $cd['MapUrl']     ?? '';
	$address    = $cd['Address']    ?? '';
	$city       = $cd['City']       ?? '';
	$province   = $cd['Province']   ?? '';
	$postalCode = $cd['PostalCode'] ?? '';
	$country    = $cd['Country']    ?? '';
	// If $address already contains a fully-formatted address (≥2 commas, e.g. from a map-picker
	// autocomplete), trust it and don't re-append city/province/country, which would duplicate.
	if (substr_count($address, ',') >= 2) {
		$locationDisplay = $address;
	} else {
		$locationDisplay = implode(', ', array_filter([$address, $city, $province, $country]));
	}
	$mapQueryAddress = implode(', ', array_filter([$address, $city, $province, $postalCode, $country]));

	$eventType = $cd['EventType'] ?? '';
	$externalLinks = $ExternalLinks ?? [];

	// Park address fallback (used when event has no address)
	$atParkAddress    = trim($AtParkAddress    ?? '');
	$atParkCity       = trim($AtParkCity       ?? '');
	$atParkProvince   = trim($AtParkProvince   ?? '');
	$atParkPostalCode = trim($AtParkPostalCode ?? '');
	$locationFallback = (!$locationDisplay && ($atParkCity || $atParkProvince))
		? implode(', ', array_filter([$atParkCity, $atParkProvince])) : '';

	// Park map link fallback: parse park location JSON when event has no map link
	if (!$mapLink && $locationFallback) {
		$_parkLoc = @json_decode(stripslashes((string)($AtParkLocation ?? '')));
		if ($_parkLoc) {
			$_parkPt = isset($_parkLoc->location) ? $_parkLoc->location
				: (isset($_parkLoc->bounds->northeast) ? $_parkLoc->bounds->northeast : null);
			if ($_parkPt && is_numeric($_parkPt->lat ?? null))
				$mapLink = 'https://maps.google.com/maps?q=@' . $_parkPt->lat . ',' . $_parkPt->lng;
		}
	}

	// Duration
	$durationLabel = '';
	if ( $eventStart && $eventEnd ) {
		$startTs = strtotime($eventStart);
		$endTs   = strtotime($eventEnd);
		if ( $endTs > $startTs ) {
			$days = (int)ceil(($endTs - $startTs) / 86400);
			$durationLabel = $days . ' day' . ($days == 1 ? '' : 's');
		}
	}

	// [TOURNAMENTS HIDDEN]
	$tournaments  = [];
	$tourneyCount = 0;
	$attendanceList = $AttendanceReport['Attendance'] ?? [];
	$checkedInIds   = array_flip(array_column($attendanceList, 'MundaneId'));
	$attendanceForm = $Attendance_event ?? [];

	$defaultKingdomName = $DefaultKingdomName       ?? '';
	$defaultKingdomId   = $DefaultKingdomId         ?? 0;
	$defaultParkName    = $DefaultParkName          ?? '';
	$defaultParkId      = $DefaultParkId            ?? 0;
	$defaultCredits     = $DefaultAttendanceCredits ?? 1;

	// Detect attendance-date mismatch: event is in the future but attendance dates are all in the past
	$attDateMismatch = false;
	if (!empty($attendanceList) && $eventStart && strtotime($eventStart) > time()) {
		$allPast = true;
		foreach ($attendanceList as $_ar) {
			if (!empty($_ar['Date']) && strtotime($_ar['Date']) >= strtotime(date('Y-m-d'))) {
				$allPast = false;
				break;
			}
		}
		$attDateMismatch = $allPast;
	}

	$reconciled    = isset($_GET['reconciled']);
	$rsvpCounts    = is_array($RsvpCount ?? null) ? $RsvpCount : ['going' => 0, 'interested' => 0, 'total' => (int)($RsvpCount ?? 0)];
	$rsvpCount     = $rsvpCounts['total'];
	$userAttending = $UserAttending ?? false; // false or 'going' or 'interested'
	$rsvpList      = $RsvpList ?? [];
	$scheduleList  = $ScheduleList ?? [];
	$scheduleCount = count($scheduleList);
	$mealList  = $MealList ?? [];
	$mealCount = count($mealList);
	$canManage           = $CanManageEvent ?? false;
	$canManageAttendance = $CanManageAttendance ?? false;
	$canManageSchedule   = $CanManageSchedule ?? false;
	$canManageFeast      = $CanManageFeast ?? false;
	$canManageStaff = $canManage || $canManageAttendance;
	$canDelete           = ($attendeeCount === 0 && $rsvpCount === 0);

	// Date badge label
	$startLabel = $eventStart ? date('M j, Y', strtotime($eventStart)) : '';
	$endLabel   = $eventEnd   ? date('M j, Y', strtotime($eventEnd))   : '';
	$dateBadgeText = $startLabel;
	if ( $endLabel && $endLabel !== $startLabel ) $dateBadgeText .= ' – ' . $endLabel;

	// Past-event check (date only, ignoring time)
	$_refDateStr  = $eventEnd ?: $eventStart;
	$isPastEvent  = $_refDateStr && (strtotime(date('Y-m-d', strtotime($_refDateStr))) < strtotime(date('Y-m-d')));
	// 24-hour check-in window
	$checkinOpenTs    = $eventStart ? strtotime($eventStart) - 86400 : 0;
	$checkinOpen      = !$isUpcoming || !$checkinOpenTs || time() >= $checkinOpenTs;
	$checkinOpenLabel = $checkinOpenTs ? date('D, M j, Y \\a\\t g:i A T', $checkinOpenTs) : '';
?>

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
.ev-export-bar { display: flex; justify-content: flex-end; gap: 6px; margin-bottom: 10px; }
.ev-checkin-locked { display:flex; align-items:flex-start; gap:10px; background:#fffbeb; border:1px solid #f6e05e; border-radius:7px; padding:11px 14px; margin-bottom:14px; font-size:13px; color:#744210; line-height:1.45; }
.ev-checkin-locked i { color:#d69e2e; margin-top:1px; flex-shrink:0; }
.ev-icon-btn { background: #fff; border: 1px solid #e2e8f0; border-radius: 5px; padding: 5px 9px; font-size: 13px; color: #4a5568; cursor: pointer; transition: background .15s, border-color .15s; line-height: 1; }
.ev-icon-btn:hover { background: #edf2f7; border-color: #cbd5e0; }
.ev-modal-btn-delete {
	background: #fff0f0; border: 1px solid #fc8181; color: #c53030;
	padding: 8px 14px; border-radius: 5px; font-size: 13px; font-weight: 600;
	cursor: pointer; transition: background .15s, border-color .15s;
}
.ev-modal-btn-delete:hover:not(:disabled) { background: #fed7d7; border-color: #e53e3e; }
.ev-modal-btn-delete-disabled { opacity: .45; cursor: not-allowed; }
.ev-del-detail-wrap { position: relative; display: inline-block; }
.ev-del-detail-tooltip {
	display: none; position: fixed; background: #1a202c; color: #fff; font-size: 12px;
	padding: 6px 11px; border-radius: 4px; white-space: nowrap;
	pointer-events: none; z-index: 9999; box-shadow: 0 2px 8px rgba(0,0,0,.25);
	transform: translateX(0) translateY(calc(-100% - 8px));
}
.ev-del-detail-tooltip::after {
	content: ''; position: absolute; top: 100%; left: 14px;
	border: 5px solid transparent; border-top-color: #1a202c;
}
.ev-del-detail-wrap:hover .ev-del-detail-tooltip { display: block; }
@keyframes ev-credits-pulse {
	0%   { box-shadow: 0 0 0 0 rgba(66,153,225,.7); border-color: #4299e1; }
	60%  { box-shadow: 0 0 0 6px rgba(66,153,225,0); border-color: #4299e1; }
	100% { box-shadow: 0 0 0 0 rgba(66,153,225,0); border-color: #e2e8f0; }
}
.ev-credits-pulse { animation: ev-credits-pulse 1s ease-out; }
/* Sign-in link modal */
#ev-signin-link-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9050; align-items:center; justify-content:center; }
#ev-signin-link-overlay.ev-open { display:flex; }
.ev-signin-link-modal { background:#fff; border-radius:12px; box-shadow:0 8px 32px rgba(0,0,0,0.22); width:min(520px, calc(100vw - 32px)); max-height:calc(100vh - 40px); overflow:auto; }
.ev-signin-link-modal-header { display:flex; align-items:center; justify-content:space-between; padding:14px 18px; border-bottom:1px solid #e2e8f0; background:#f7fafc; font-size:15px; font-weight:700; color:#2d3748; }
.ev-signin-link-close { background:none; border:none; font-size:22px; color:#718096; cursor:pointer; padding:0 4px; line-height:1; }
.ev-signin-link-modal-body { padding:18px 22px 22px; }
.ev-signin-link-blurb { margin:0 0 14px; font-size:13px; color:#4a5568; line-height:1.5; }
.ev-signin-link-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; }
.ev-signin-link-field { display:flex; flex-direction:column; }
.ev-signin-link-field label { font-size:11px; font-weight:700; color:#718096; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.ev-signin-link-field input { padding:6px 9px; border:1px solid #cbd5e0; border-radius:5px; font-size:13px; width:120px; }
.ev-signin-link-hint { margin-top:8px; font-size:11px; color:#718096; }
.ev-signin-link-url-row { display:flex; gap:6px; align-items:center; margin-top:12px; }
.ev-signin-link-url-row input { flex:1; min-width:0; font-size:12px; padding:6px 8px; border:1px solid #cbd5e0; border-radius:4px; background:#fff; }
#ev-signin-link-expires { margin-top:6px; font-size:11px; color:#718096; }
#ev-signin-links-wrap { margin-top:14px; border-top:1px solid #e2e8f0; padding-top:10px; }
#ev-signin-links-toggle { background:none; border:none; padding:0; cursor:pointer; font-size:12px; color:#4a5568; display:flex; align-items:center; gap:6px; }
#ev-signin-links-chevron { font-size:10px; transition:transform .15s; }
#ev-signin-links-loading, #ev-signin-links-empty { font-size:12px; color:#a0aec0; padding:4px 0; }
#ev-signin-links-table { width:100%; border-collapse:collapse; font-size:12px; margin-top:6px; }
#ev-signin-links-table th { color:#718096; text-align:left; padding:4px 6px; font-weight:600; }
/* QR modal */
#ev-qr-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.55); z-index:9100; align-items:center; justify-content:center; }
#ev-qr-overlay .ev-qr-box { background:#fff; border-radius:12px; padding:28px 28px 20px; box-shadow:0 8px 32px rgba(0,0,0,0.22); max-width:320px; width:calc(100vw - 40px); text-align:center; }
#ev-qr-img { width:220px; height:220px; border:1px solid #e2e8f0; border-radius:6px; display:block; margin:0 auto 14px; }
.ev-rsvp-th-tooltip { position:relative; display:inline-block; cursor:default; }
.ev-rsvp-th-tooltip .ev-rsvp-th-tip {
	display:none; position:fixed; background:#1a202c; color:#fff; font-size:12px;
	padding:7px 11px; border-radius:4px; white-space:normal; max-width:280px; line-height:1.45;
	pointer-events:none; z-index:9999; box-shadow:0 2px 8px rgba(0,0,0,.25);
	transform:translateX(-50%) translateY(calc(-100% - 8px));
}
.ev-rsvp-th-tooltip:hover .ev-rsvp-th-tip { display:block; }
/* Heraldry edit overlay */
.ev-heraldry-edit-wrap { position: relative; display: inline-block; cursor: pointer; }
.ev-heraldry-edit-overlay {
	position: absolute; inset: 0; background: rgba(0,0,0,0); border-radius: 6px;
	display: flex; align-items: center; justify-content: center; transition: background .2s;
}
.ev-heraldry-edit-wrap:hover .ev-heraldry-edit-overlay { background: rgba(0,0,0,0.45); }
.ev-heraldry-edit-icon { color: #fff; font-size: 22px; opacity: 0; transition: opacity .2s; }
.ev-heraldry-edit-wrap:hover .ev-heraldry-edit-icon { opacity: 1; }
/* Image upload modal */
.ev-img-overlay {
	display: none; position: fixed; inset: 0; background: rgba(0,0,0,.55);
	z-index: 1500; align-items: center; justify-content: center;
}
.ev-img-overlay.ev-open { display: flex; }
.ev-img-modal {
	background: #fff; border-radius: 10px; width: min(520px, 96vw);
	box-shadow: 0 8px 32px rgba(0,0,0,.22); overflow: hidden;
}
.ev-img-modal-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 14px 18px; border-bottom: 1px solid #e2e8f0; background: #f7fafc;
}
.ev-img-modal-title { font-size: 15px; font-weight: 700; color: #2d3748; margin: 0; }
.ev-img-close-btn { background: none; border: none; font-size: 20px; color: #718096; cursor: pointer; padding: 0 4px; }
.ev-img-modal-body { padding: 20px 22px; }
.ev-upload-area {
	display: flex; flex-direction: column; align-items: center; gap: 8px;
	border: 2px dashed #cbd5e0; border-radius: 8px; padding: 28px 20px;
	cursor: pointer; color: #4a5568; font-size: 14px; text-align: center;
	transition: border-color .15s, background .15s;
}
.ev-upload-area:hover { border-color: #4299e1; background: #ebf8ff; }
.ev-upload-icon { font-size: 32px; color: #a0aec0; }
.ev-upload-area small { font-size: 12px; color: #a0aec0; }
.ev-img-step-actions { display: flex; justify-content: flex-end; gap: 10px; margin-top: 14px; }
.ev-crop-wrap { overflow: auto; max-height: 360px; display: flex; justify-content: center; }
.ev-img-form-error { background: #fff5f5; border: 1px solid #feb2b2; color: #c53030; padding: 8px 12px; border-radius: 5px; font-size: 13px; margin-top: 8px; }
.ev-fp-title { background: #2b6cb0; color: #fff; font-size: 12px; font-weight: 700; padding: 6px 12px; text-align: center; letter-spacing: .04em; }
/* ── Edit Attendance Modal ─────────────────────── */
.att-edit-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:9990; align-items:center; justify-content:center; }
.att-edit-overlay.att-edit-open { display:flex; }
.att-edit-modal { background:#fff; border-radius:10px; box-shadow:0 20px 60px rgba(0,0,0,.3); width:360px; max-width:96vw; }
.att-edit-modal-header { background:#1e1b4b; color:#fff; padding:14px 18px; border-radius:10px 10px 0 0; display:flex; align-items:center; justify-content:space-between; }
.att-edit-modal-title { font-size:.9rem; font-weight:700; display:flex; align-items:center; gap:8px; }
.att-edit-modal-close { background:none; border:none; color:rgba(255,255,255,.7); font-size:1.2rem; cursor:pointer; padding:0 2px; line-height:1; }
.att-edit-modal-close:hover { color:#fff; }
.att-edit-modal-body { padding:18px 18px 10px; }
.att-edit-field { margin-bottom:14px; }
.att-edit-label { display:block; font-size:.72rem; font-weight:600; color:#6b7280; text-transform:uppercase; letter-spacing:.04em; margin-bottom:4px; }
.att-edit-input, .att-edit-select { width:100%; padding:7px 10px; border:1px solid #d1d5db; border-radius:6px; font-size:.87rem; color:#111827; background:#fff; box-sizing:border-box; }
.att-edit-input:focus, .att-edit-select:focus { outline:none; border-color:#6366f1; box-shadow:0 0 0 2px rgba(99,102,241,.15); }
.att-edit-row { display:flex; gap:12px; }
.att-edit-row .att-edit-field { flex:1; }
.att-edit-row .att-edit-field.att-edit-field-sm { flex:0 0 90px; }
.att-edit-feedback { background:#fef2f2; border:1px solid #fca5a5; color:#991b1b; border-radius:6px; padding:8px 12px; font-size:.82rem; margin-bottom:12px; display:none; }
.att-edit-modal-footer { padding:12px 18px; border-top:1px solid #f3f4f6; display:flex; justify-content:flex-end; gap:8px; }
.att-edit-btn-cancel { padding:7px 16px; background:#f3f4f6; color:#374151; border:1px solid #d1d5db; border-radius:6px; font-size:.85rem; cursor:pointer; }
.att-edit-btn-save { padding:7px 16px; background:#4338ca; color:#fff; border:none; border-radius:6px; font-size:.85rem; font-weight:600; cursor:pointer; }
.att-edit-btn-save:hover:not(:disabled) { background:#3730a3; }
.att-edit-btn-save:disabled { opacity:.5; cursor:not-allowed; }
/* =====================================================
   DARK MODE — Eventnew components
   ===================================================== */
html[data-theme="dark"] .ev-checkin-locked { background: #744210; border-color: #975a16; color: #fbd38d; }
html[data-theme="dark"] .ev-checkin-locked i { color: #f6ad55; }
html[data-theme="dark"] .ev-icon-btn { background: var(--ork-bg-secondary); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .ev-icon-btn:hover { background: var(--ork-bg-tertiary); border-color: var(--ork-border); }
html[data-theme="dark"] .ev-modal-btn-delete { background: #742a2a; border-color: #9b2c2c; color: #feb2b2; }
html[data-theme="dark"] .ev-modal-btn-delete:hover:not(:disabled) { background: #9b2c2c; }
html[data-theme="dark"] .ev-img-modal { background: var(--ork-card-bg); }
html[data-theme="dark"] .ev-img-modal-header { background: var(--ork-bg-secondary); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .ev-img-modal-title { color: var(--ork-text); }
html[data-theme="dark"] .ev-img-close-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-upload-area { border-color: var(--ork-border); color: var(--ork-text-secondary); background: var(--ork-bg-secondary); }
html[data-theme="dark"] .ev-upload-area:hover { border-color: var(--ork-link); background: var(--ork-bg-tertiary); }
html[data-theme="dark"] .ev-upload-icon { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-img-form-error { background: #742a2a; border-color: #9b2c2c; color: #feb2b2; }
html[data-theme="dark"] .ev-fp-title { background: #1a365d; color: #90cdf4; }
html[data-theme="dark"] .att-edit-modal { background: var(--ork-card-bg); }
html[data-theme="dark"] .att-edit-label { color: var(--ork-text-muted); }
html[data-theme="dark"] .att-edit-input, html[data-theme="dark"] .att-edit-select { background: var(--ork-input-bg); border-color: var(--ork-input-border); color: var(--ork-text); }
html[data-theme="dark"] .att-edit-feedback { background: #742a2a; border-color: #9b2c2c; color: #feb2b2; }
html[data-theme="dark"] .att-edit-modal-footer { border-top-color: var(--ork-border); }
html[data-theme="dark"] .att-edit-btn-cancel { background: var(--ork-bg-secondary); color: var(--ork-text); border-color: var(--ork-border); }
/* RSVP table: sign-in credits input + waivered tooltip (PR #454) */
html[data-theme="dark"] #ev-rsvp-credits {
	background: var(--ork-input-bg) !important;
	border-color: var(--ork-input-border) !important;
	color: var(--ork-text) !important;
}
html[data-theme="dark"] #ev-rsvp-table thead th label { color: var(--ork-text-muted) !important; }
html[data-theme="dark"] .ev-rsvp-th-tip { background: var(--ork-text, #e2e8f0); color: var(--ork-bg, #1a202c); }

/* =====================================================
   DARK MODE — Schedule tab (list + grid)
   Category palette: darkened, hue-tinted bgs w/ lighter text
   ===================================================== */
html[data-theme="dark"] .ev-sched-day-header {
	color: var(--ork-text); border-bottom-color: var(--ork-border);
	background: transparent; border-top: none; border-left: none; border-right: none;
	padding: 3px 0 5px; border-radius: 0; text-shadow: none;
}
html[data-theme="dark"] .ev-grid-day { background: var(--ork-card-bg); border-color: var(--ork-border); }
html[data-theme="dark"] .ev-grid-day-header { background: var(--ork-bg-secondary); color: var(--ork-text); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .ev-grid-header-row { background: var(--ork-card-bg); }
html[data-theme="dark"] .ev-grid-time-col-head { background: var(--ork-card-bg); border-bottom-color: var(--ork-border); }
html[data-theme="dark"] .ev-grid-time-col { background: var(--ork-bg-secondary); border-right-color: var(--ork-border); }
html[data-theme="dark"] .ev-grid-time-hour { border-top-color: var(--ork-border); }
html[data-theme="dark"] .ev-grid-time-half { border-top-color: rgba(255,255,255,0.05); }
html[data-theme="dark"] .ev-grid-time-lbl { background: var(--ork-bg-secondary); color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-grid-col { border-left-color: rgba(255,255,255,0.06); }
html[data-theme="dark"] .ev-grid-block { color: var(--ork-text); border-color: rgba(0,0,0,0.4); box-shadow: 0 1px 2px rgba(0,0,0,0.4); }
html[data-theme="dark"] .ev-grid-block:hover { box-shadow: 0 3px 8px rgba(0,0,0,0.55); }
html[data-theme="dark"] .ev-grid-block-title { color: var(--ork-text); }
html[data-theme="dark"] .ev-grid-block-time { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-grid-block-loc { color: var(--ork-text-secondary); }
html[data-theme="dark"] .ev-grid-block-loc i { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-grid-lead-chip { background: rgba(255,255,255,0.08); border-color: rgba(255,255,255,0.12); color: var(--ork-text-secondary); }
html[data-theme="dark"] .ev-grid-cat-count { background: rgba(255,255,255,0.12); border-color: rgba(255,255,255,0.14); color: var(--ork-text); }
html[data-theme="dark"] .ev-grid-now-dot { box-shadow: 0 0 0 2px var(--ork-bg-secondary); }
html[data-theme="dark"] .ev-grid-popover { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text); box-shadow: 0 8px 24px rgba(0,0,0,0.55); }
html[data-theme="dark"] .ev-grid-popover h5 { color: var(--ork-text); }
html[data-theme="dark"] .ev-grid-popover .ev-gp-row { color: var(--ork-text-secondary); }
html[data-theme="dark"] .ev-grid-popover .ev-gp-row i { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-grid-popover-flip:before { border-top-color: var(--ork-card-bg); }
/* Inactive filter pill */
html[data-theme="dark"] .ev-sched-pill-inactive { background: var(--ork-bg-secondary) !important; border-color: var(--ork-border) !important; color: var(--ork-text-muted) !important; }
html[data-theme="dark"] .ev-sched-pill-inactive i { color: var(--ork-text-muted) !important; }

/* Per-category dark palettes — row bg (list), cat-head bg (grid), block bg (grid), col hour-line tint */
/* Administrative */
html[data-theme="dark"] .ev-sched-table tr[data-category="Administrative"] { background: #2a3136 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Administrative"] { background: #2a3136 !important; border-bottom-color: #90a4ae !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Administrative"] .ev-grid-cat-label { color: #cfd8dc !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Administrative"] > i { color: #90a4ae !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Administrative"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(144,164,174,0.12) 55px, rgba(144,164,174,0.12) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Administrative"] { background: #2a3136 !important; border-left-color: #90a4ae !important; }

/* Tournament */
html[data-theme="dark"] .ev-sched-table tr[data-category="Tournament"] { background: #3a2f15 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Tournament"] { background: #3a2f15 !important; border-bottom-color: #ffd54f !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Tournament"] .ev-grid-cat-label { color: #ffe082 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Tournament"] > i { color: #ffd54f !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Tournament"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(255,213,79,0.10) 55px, rgba(255,213,79,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Tournament"] { background: #3a2f15 !important; border-left-color: #ffd54f !important; }

/* Battlegame */
html[data-theme="dark"] .ev-sched-table tr[data-category="Battlegame"] { background: #3d1e1a !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Battlegame"] { background: #3d1e1a !important; border-bottom-color: #ff9a93 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Battlegame"] .ev-grid-cat-label { color: #ffb4ae !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Battlegame"] > i { color: #ff9a93 !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Battlegame"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(255,154,147,0.10) 55px, rgba(255,154,147,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Battlegame"] { background: #3d1e1a !important; border-left-color: #ff9a93 !important; }

/* Arts and Sciences */
html[data-theme="dark"] .ev-sched-table tr[data-category="Arts and Sciences"] { background: #2d1935 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Arts and Sciences"] { background: #2d1935 !important; border-bottom-color: #ce93d8 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Arts and Sciences"] .ev-grid-cat-label { color: #e1bee7 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Arts and Sciences"] > i { color: #ce93d8 !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Arts and Sciences"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(206,147,216,0.10) 55px, rgba(206,147,216,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Arts and Sciences"] { background: #2d1935 !important; border-left-color: #ce93d8 !important; }

/* Class */
html[data-theme="dark"] .ev-sched-table tr[data-category="Class"] { background: #0f2540 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Class"] { background: #0f2540 !important; border-bottom-color: #90caf9 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Class"] .ev-grid-cat-label { color: #bbdefb !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Class"] > i { color: #90caf9 !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Class"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(144,202,249,0.10) 55px, rgba(144,202,249,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Class"] { background: #0f2540 !important; border-left-color: #90caf9 !important; }

/* Feast and Food */
html[data-theme="dark"] .ev-sched-table tr[data-category="Feast and Food"] { background: #3a2410 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Feast and Food"] { background: #3a2410 !important; border-bottom-color: #ffb74d !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Feast and Food"] .ev-grid-cat-label { color: #ffcc80 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Feast and Food"] > i { color: #ffb74d !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Feast and Food"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(255,183,77,0.10) 55px, rgba(255,183,77,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Feast and Food"] { background: #3a2410 !important; border-left-color: #ffb74d !important; }

/* Court */
html[data-theme="dark"] .ev-sched-table tr[data-category="Court"] { background: #2a201d !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Court"] { background: #2a201d !important; border-bottom-color: #bcaaa4 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Court"] .ev-grid-cat-label { color: #d7ccc8 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Court"] > i { color: #bcaaa4 !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Court"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(188,170,164,0.10) 55px, rgba(188,170,164,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Court"] { background: #2a201d !important; border-left-color: #bcaaa4 !important; }

/* Meeting */
html[data-theme="dark"] .ev-sched-table tr[data-category="Meeting"] { background: #143024 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Meeting"] { background: #143024 !important; border-bottom-color: #81c784 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Meeting"] .ev-grid-cat-label { color: #a5d6a7 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Meeting"] > i { color: #81c784 !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Meeting"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(129,199,132,0.10) 55px, rgba(129,199,132,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Meeting"] { background: #143024 !important; border-left-color: #81c784 !important; }

/* Other */
html[data-theme="dark"] .ev-sched-table tr[data-category="Other"] { background: #2a2a2a !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Other"] { background: #2a2a2a !important; border-bottom-color: #bdbdbd !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Other"] .ev-grid-cat-label { color: #e0e0e0 !important; }
html[data-theme="dark"] .ev-grid-cat-head[data-category="Other"] > i { color: #bdbdbd !important; }
html[data-theme="dark"] .ev-grid-col[data-category="Other"] { background: repeating-linear-gradient(to bottom, transparent 0, transparent 55px, rgba(189,189,189,0.10) 55px, rgba(189,189,189,0.10) 56px) !important; }
html[data-theme="dark"] .ev-grid-block[data-category="Other"] { background: #2a2a2a !important; border-left-color: #bdbdbd !important; }

/* =====================================================
   Flatpickr time picker — obvious up/down carat buttons
   stacked above and below hour & minute inputs using a
   flex column so they don't overlap the input.
   Hour step = 1, minute step = 5 (set via JS opts).
   ===================================================== */
.flatpickr-time { overflow: visible !important; height: auto !important; max-height: none !important; align-items: center !important; min-height: 80px !important; padding: 4px 0 !important; }
.flatpickr-time .numInputWrapper {
	position: relative !important;
	overflow: visible !important;
	display: inline-flex !important;
	flex-direction: column !important;
	align-items: stretch !important;
	justify-content: center !important;
	padding: 0 !important;
	margin: 0 2px !important;
	height: auto !important;
	width: 72px !important;
	gap: 3px !important;
}
.flatpickr-time input.flatpickr-hour,
.flatpickr-time input.flatpickr-minute {
	order: 2 !important;
	font-size: 15px !important;
	font-weight: 700 !important;
	height: 28px !important;
	line-height: 28px !important;
	width: 100% !important;
	text-align: center !important;
	margin: 0 !important;
}
.flatpickr-time .numInputWrapper span.arrowUp,
.flatpickr-time .numInputWrapper span.arrowDown {
	position: static !important;
	display: block !important;
	width: 100% !important;
	height: 18px !important;
	opacity: 1 !important;
	background: #ebf8ff !important;
	border: 1px solid #90cdf4 !important;
	border-radius: 3px !important;
	margin: 0 !important;
	padding: 0 !important;
	cursor: pointer !important;
	transition: background .12s, border-color .12s !important;
	flex-shrink: 0 !important;
}
.flatpickr-time .numInputWrapper span.arrowUp   { order: 1 !important; }
.flatpickr-time .numInputWrapper span.arrowDown { order: 3 !important; }
.flatpickr-time .numInputWrapper span.arrowUp:hover,
.flatpickr-time .numInputWrapper span.arrowDown:hover { background: #bee3f8 !important; border-color: #4299e1 !important; }
.flatpickr-time .numInputWrapper span.arrowUp:after,
.flatpickr-time .numInputWrapper span.arrowDown:after {
	content: '' !important;
	position: absolute !important;
	left: 50% !important;
	transform: translateX(-50%) !important;
	width: 0 !important;
	height: 0 !important;
	border-style: solid !important;
	margin: 0 !important;
	opacity: 1 !important;
	top: auto !important;
	bottom: auto !important;
}
.flatpickr-time .numInputWrapper span.arrowUp:after {
	top: 4px !important;
	border-width: 0 6px 8px 6px !important;
	border-color: transparent transparent #2b6cb0 transparent !important;
}
.flatpickr-time .numInputWrapper span.arrowDown:after {
	bottom: 4px !important;
	border-width: 8px 6px 0 6px !important;
	border-color: #2b6cb0 transparent transparent transparent !important;
}
.flatpickr-time .flatpickr-am-pm { align-self: center !important; }
.flatpickr-time .flatpickr-time-separator { align-self: center !important; line-height: 1 !important; }
/* Dark mode */
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowUp,
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowDown {
	background: #1a365d !important;
	border-color: #2c5282 !important;
}
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowUp:hover,
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowDown:hover {
	background: #2c5282 !important;
	border-color: #4299e1 !important;
}
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowUp:after { border-bottom-color: #90cdf4 !important; }
html[data-theme="dark"] .flatpickr-time .numInputWrapper span.arrowDown:after { border-top-color: #90cdf4 !important; }


.ev-sched-pill {
	display: inline-flex; align-items: center; padding: 4px 11px;
	border-radius: 20px; font-size: 12px; font-weight: 600; cursor: pointer;
	transition: opacity .15s, background .15s, border-color .15s;
	white-space: nowrap; border-width: 1px; border-style: solid;
}
.ev-sched-pill-inactive {
	background: #fff !important; border-color: #ddd !important;
	color: #bbb !important; opacity: 0.6;
}
.ev-sched-pill-inactive i { color: #ccc !important; }
.ev-sched-day-header {
	font-weight: 700; font-size: 15px; color: #2d3748;
	margin: 18px 0 6px; padding-bottom: 5px;
	border-bottom: 2px solid #e2e8f0;
}
.ev-sched-day-section:first-child .ev-sched-day-header { margin-top: 4px; }
.ev-sched-day-section + .ev-sched-day-section { margin-top: 10px; }
.ev-sched-table { table-layout: fixed; width: 100%; }
.ev-meal-card {
	border: 1px solid #f7d9c4; border-radius: 8px; background: #fff8f5;
	margin-bottom: 12px; overflow: hidden;
}
.ev-meal-card-header {
	display: flex; align-items: center; justify-content: space-between;
	padding: 10px 14px; background: #fff3ec; border-bottom: 1px solid #f7d9c4;
}
.ev-meal-title { font-weight: 700; font-size: 15px; color: #2d3748; }
.ev-meal-cost { font-size: 14px; color: #4a5568; }
.ev-meal-menu {
	padding: 10px 14px; font-size: 14px; color: #4a5568;
	white-space: pre-line; line-height: 1.6;
}
.ev-meal-footer {
	padding: 8px 14px 10px; display: flex; flex-wrap: wrap; gap: 6px;
	border-top: 1px solid #f7d9c4;
}
.ev-meal-tag {
	display: inline-flex; align-items: center; gap: 4px;
	font-size: 11px; font-weight: 600; padding: 3px 8px; border-radius: 20px;
	white-space: nowrap;
}
.ev-meal-tag-dietary { background: #c6f6d5; color: #276749; }
.ev-meal-tag-allergen { background: #feebc8; color: #7b341e; }
.ev-meal-cb-group { display: flex; flex-wrap: wrap; gap: 6px 16px; margin-top: 4px; }
.ev-meal-cb-group label { display: flex; align-items: center; gap: 5px; font-size: 13px; font-weight: 400; cursor: pointer; }
.ev-meal-cb-group input[type=checkbox] { margin: 0; cursor: pointer; }
.ev-edit-btn { background: none; border: none; cursor: pointer; color: #718096; font-size: 15px; padding: 0; line-height: 1; }
.ev-edit-btn:hover { color: #2b6cb0; }
</style>

<?php // ---- HERO ---- ?>
<div class="ev-hero" id="ev-hero">
	<div class="ev-hero-bg"
		<?php if ($heraldryUrl): ?>
			style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"
		<?php endif; ?>
	></div>
	<div class="ev-hero-content">

		<div class="ev-heraldry-frame<?= $canManage ? ' ev-heraldry-edit-wrap' : '' ?>"<?= $canManage ? ' onclick="evOpenImgModal()" title="Change heraldry"' : '' ?>>
			<img id="ev-heraldry-img"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="<?= $eventName ?> heraldry"
				crossorigin="anonymous">
			<?php if ($canManage): ?>
			<div class="ev-heraldry-edit-overlay"><i class="fas fa-camera ev-heraldry-edit-icon"></i></div>
			<?php endif; ?>
		</div>

		<div class="ev-hero-info">
			<h1 class="ev-event-name"><?= $eventName ?></h1>
			<div class="ev-badges">
				<?php if ($dateBadgeText): ?>
				<span class="ev-badge <?= $isUpcoming ? 'ev-badge-green' : 'ev-badge-gray' ?>">
					<i class="fas fa-calendar-alt"></i> <?= htmlspecialchars($dateBadgeText) ?>
				</span>
				<?php endif; ?>
				<?php if (!$isOngoing): ?>
				<span class="ev-badge <?= $isUpcoming ? 'ev-badge-green' : 'ev-badge-gray' ?>">
					<?= $isUpcoming ? '<i class="fas fa-clock"></i> Upcoming' : '<i class="fas fa-history"></i> Past' ?>
				</span>
				<?php endif; ?>
				<span class="ev-badge ev-badge-blue">
					<i class="fas fa-<?= $parkId > 0 ? 'tree' : 'crown' ?>"></i>
					<?= $parkId > 0 ? 'Park Event' : 'Kingdom Event' ?>
				</span>
				<?php
				$_etIcons = ['Coronation'=>'fa-crown','Midreign'=>'fa-star-half-alt','Endreign'=>'fa-star','Crown Qualifications'=>'fa-trophy','Meeting'=>'fa-users','Althing'=>'fa-landmark','Interkingdom Event'=>'fa-globe','Weaponmaster'=>'fa-fist-raised','Warmaster'=>'fa-shield-alt','Dragonmaster'=>'fa-dragon','Other'=>'fa-calendar'];
				$_etIcon = $_etIcons[$eventType ?? ''] ?? 'fa-calendar';
				?>
				<?php if (!empty($eventType)): ?>
				<span class="ev-badge ev-badge-purple">
					<i class="fas <?= $_etIcon ?>"></i> <?= htmlspecialchars($eventType) ?>
				</span>
				<?php endif; ?>
			</div>
			<div class="ev-owner-inline">
				<i class="fas fa-layer-group" style="font-size:10px;opacity:0.6;margin-right:4px"></i>
				<?= $eventName ?>
				<?php if ($kingdomId): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Kingdom/profile/<?= $kingdomId ?>"><?= $kingdomName ?></a>
				<?php endif; ?>
				<?php
					$breadcrumbParkId   = $atParkId   ?: $parkId;
					$breadcrumbParkName = $atParkName; // controller fetches name for atParkId ?: parkId
				?>
				<?php if ($breadcrumbParkId && $breadcrumbParkName): ?>
					<span class="ev-owner-sep">›</span>
					<a href="<?= UIR ?>Park/profile/<?= $breadcrumbParkId ?>"><?= $breadcrumbParkName ?></a>
				<?php endif; ?>
			</div>
		</div>

		<div class="ev-hero-actions">
			<a class="ev-btn ev-btn-white"
				href="<?= UIR ?>Reports/event_attendance/Kingdom/<?= $kingdomId ?>&filter=<?= urlencode($info['Name'] ?? '') ?>">
				<i class="fas fa-list-alt"></i> Attendance Report
			</a>
			<?php if ($CanManageEvent ?? false): ?>
			<button class="ev-btn ev-btn-outline" type="button" onclick="evOpenEditModal()">
				<i class="fas fa-pencil-alt"></i> Edit Details
			</button>
			<?php endif; ?>
			<?php if ($loggedIn && !$isPastEvent): ?>
			<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/rsvp" style="margin:0;display:inline-flex;gap:6px">
				<button type="submit" name="status" value="going"
					class="ev-btn <?= $userAttending === 'going' ? 'ev-btn-primary' : 'ev-btn-outline' ?>">
					<i class="fas fa-check-circle"></i> <?= $userAttending === 'going' ? 'Going ✓' : 'Going' ?>
				</button>
				<button type="submit" name="status" value="interested"
					class="ev-btn <?= $userAttending === 'interested' ? 'ev-btn-secondary' : 'ev-btn-outline' ?>">
					<i class="fas fa-star"></i> <?= $userAttending === 'interested' ? 'Interested ✓' : 'Interested' ?>
				</button>
			</form>
			<?php endif; ?>

		</div>

	</div>
</div>

<?php // ---- STATS ROW ---- ?>
<div class="ev-stats-row">
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-calendar-alt"></i></div>
		<div class="ev-stat-value" style="font-size:15px;padding-top:3px">
			<?= $startLabel ?: '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Date</div>
	</div>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-clock"></i></div>
		<div class="ev-stat-value" style="font-size:15px;padding-top:3px">
			<?= $eventStart ? date('g:i A', strtotime($eventStart)) : '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Starts At</div>
	</div>
	<?php $hasMapTab = (bool)($locationDisplay ?: $locationFallback); ?>
	<?php if (!$locationDisplay && $mapUrl): ?>
	<a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" class="ev-stat-card ev-stat-card-link" style="cursor:pointer;text-decoration:none;color:inherit">
		<div class="ev-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="ev-stat-value" style="font-size:14px;padding-top:3px;color:#4a90d9"><?= htmlspecialchars($mapUrlName ?: 'View Map') ?></div>
		<div class="ev-stat-label">Location</div>
	</a>
	<?php else: ?>
	<div class="ev-stat-card<?= $hasMapTab ? ' ev-stat-card-link' : '' ?>"<?= $hasMapTab ? ' onclick="evShowTab(document.querySelector(\'[data-tab=ev-tab-map]\'),\'ev-tab-map\')" title="View map"' : '' ?> style="<?= $hasMapTab ? 'cursor:pointer' : '' ?>">
		<div class="ev-stat-icon"><i class="fas fa-map-marker-alt"></i></div>
		<div class="ev-stat-value" style="font-size:14px;padding-top:3px">
			<?php $_dispLoc = $locationDisplay ?: $locationFallback; ?>
			<?= $_dispLoc ? htmlspecialchars($_dispLoc) : '<span style="color:#a0aec0">TBD</span>' ?>
		</div>
		<div class="ev-stat-label">Location</div>
	</div>
	<?php endif; ?>
	<div class="ev-stat-card">
		<div class="ev-stat-icon"><i class="fas fa-users"></i></div>
		<div class="ev-stat-value"><?= !$isPastEvent ? $rsvpCount : $attendeeCount ?></div>
		<div class="ev-stat-label"><?= !$isPastEvent ? 'RSVPs' : 'Attendees' ?></div>
	</div>
</div>

<?php // ---- LAYOUT ---- ?>
<div class="ev-layout">

	<?php // ---- SIDEBAR ---- ?>
	<div class="ev-sidebar">

		<?php if ($canManage): ?>
		<div class="ev-heraldry-edit-wrap" onclick="evOpenImgModal()" title="Change heraldry">
			<img class="ev-heraldry-large"
				src="<?= htmlspecialchars($heraldryUrl) ?>"
				onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
				alt="">
			<div class="ev-heraldry-edit-overlay"><i class="fas fa-camera ev-heraldry-edit-icon"></i></div>
		</div>
		<?php else: ?>
		<img class="ev-heraldry-large"
			src="<?= htmlspecialchars($heraldryUrl) ?>"
			onerror="this.src='<?= HTTP_EVENT_HERALDRY ?>00000.jpg'"
			alt="">
		<?php endif; ?>

		<?php // Event Dates card ?>
		<div class="ev-card">
			<h4><i class="fas fa-calendar" style="margin-right:5px"></i>Event Dates</h4>
			<?php if ($eventStart): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Start</span>
				<span class="ev-detail-value"><?= date('M j, Y', strtotime($eventStart)) ?></span>
			</div>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Time</span>
				<span class="ev-detail-value"><?= date('g:i A', strtotime($eventStart)) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($eventEnd): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">End</span>
				<span class="ev-detail-value"><?= date('M j, Y', strtotime($eventEnd)) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($durationLabel): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Duration</span>
				<span class="ev-detail-value"><?= $durationLabel ?></span>
			</div>
			<?php endif; ?>
		</div>

		<?php
			$_showAddress  = $address  ?: $atParkAddress;
			$_showCity     = $city     ?: $atParkCity;
			$_showProvince = $province ?: $atParkProvince;
			$_fromPark     = !($address || $city || $province) && ($atParkCity || $atParkProvince || $atParkAddress);
		?>
		<?php if ($locationDisplay || $locationFallback || $mapLink): ?>
		<?php // Location card ?>
		<div class="ev-card">
			<h4><i class="fas fa-map-marker-alt" style="margin-right:5px"></i>Location<?php if ($_fromPark): ?> <span style="font-size:11px;font-weight:400;color:#718096;margin-left:4px">(park address)</span><?php endif; ?></h4>
			<?php if ($_showAddress): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Address</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showAddress) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($_showCity): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">City</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showCity) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($_showProvince): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Region</span>
				<span class="ev-detail-value"><?= htmlspecialchars($_showProvince) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($postalCode): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Postal Code</span>
				<span class="ev-detail-value"><?= htmlspecialchars($postalCode) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($country): ?>
			<div class="ev-detail-row">
				<span class="ev-detail-label">Country</span>
				<span class="ev-detail-value"><?= htmlspecialchars($country) ?></span>
			</div>
			<?php endif; ?>
			<?php if ($mapLink): ?>
			<a href="<?= htmlspecialchars($mapLink) ?>" target="_blank" class="ev-map-btn">
				<i class="fas fa-map"></i> Google Maps
			</a>
			<?php endif; ?>
			<?php if ($mapUrl && $mapUrlName): ?>
			<a href="<?= htmlspecialchars($mapUrl) ?>" target="_blank" class="ev-map-btn" style="margin-top:6px;background:#f0fff4;border-color:#9ae6b4;">
				<i class="fas fa-map-signs"></i> <?= htmlspecialchars($mapUrlName) ?>
			</a>
			<?php endif; ?>
		</div>
		<?php endif; ?>


	</div><!-- /.ev-sidebar -->

	<?php // ---- MAIN CONTENT ---- ?>
	<div class="ev-main">

		<?php if (!empty($Error)): ?>
		<div class="ev-error"><i class="fas fa-exclamation-triangle" style="margin-right:6px"></i><?= htmlspecialchars($Error ?? '') ?></div>
		<?php endif; ?>

		<div class="ev-tabs">

			<ul class="ev-tab-nav" id="ev-tab-nav">
				<li class="ev-tab-active" data-tab="ev-tab-details" onclick="evShowTab(this,'ev-tab-details')">
					<i class="fas fa-align-left"></i><span class="ev-tab-label"> Details</span>
				</li>
				<li data-tab="ev-tab-schedule" onclick="evShowTab(this,'ev-tab-schedule')">
					<i class="fas fa-clock"></i><span class="ev-tab-label"> Schedule</span>
					<span class="ev-tab-count"><?= $scheduleCount ?></span>
				</li>
				<li data-tab="ev-tab-feast" onclick="evShowTab(this,'ev-tab-feast')">
					<i class="fas fa-utensils"></i><span class="ev-tab-label"> Feast</span>
					<span class="ev-tab-count"><?= $mealCount ?></span>
				</li>
				<li data-tab="ev-tab-attendance" onclick="evShowTab(this,'ev-tab-attendance')">
					<i class="fas fa-clipboard-list"></i><span class="ev-tab-label"> Attendance</span>
					<span class="ev-tab-count">(<?= $attendeeCount ?>)</span>
				</li>
				<?php /* [TOURNAMENTS HIDDEN] tab */ ?>
				<li data-tab="ev-tab-rsvp" onclick="evShowTab(this,'ev-tab-rsvp')">
					<i class="fas fa-calendar-check"></i><span class="ev-tab-label"> RSVPs</span>
					<span class="ev-tab-count">(<?= $rsvpCount ?>)</span>
				</li>
				<li data-tab="ev-tab-staff" onclick="evShowTab(this,'ev-tab-staff')">
					<i class="fas fa-id-badge"></i><span class="ev-tab-label"> Staff</span>
					<span class="ev-tab-count"><?= count($StaffList ?? []) ?></span>
				</li>
				<?php if ($hasMapTab): ?>
				<li data-tab="ev-tab-map" onclick="evShowTab(this,'ev-tab-map')">
					<i class="fas fa-map-marked-alt"></i><span class="ev-tab-label"> Map</span>
				</li>
				<?php endif; ?>
				<?php if ($canManage): ?>
				<li data-tab="ev-tab-admin" onclick="evShowTab(this,'ev-tab-admin')">
					<i class="fas fa-cog"></i><span class="ev-tab-label"> Admin Tasks</span>
				</li>
				<?php endif; ?>
			</ul>

			<?php // ---- Details Tab ---- ?>
			<div class="ev-tab-panel ev-tab-visible" id="ev-tab-details">
				<div style="display:flex;gap:20px;align-items:flex-start">
					<div style="flex:1;min-width:0">
						<?php if ($hasDescription): ?>
							<div class="ev-description kn-description-body"><?= ev_markdown(rawurldecode($description)) ?></div>
						<?php else: ?>
							<div class="ev-empty">
								<i class="fas fa-file-alt" style="margin-right:6px"></i>No description provided
							</div>
						<?php endif; ?>
					</div>
					<?php $_hasFees = !empty($eventFees); $_hasLinks = !empty($externalLinks); ?>
					<?php if ($_hasFees || $_hasLinks): ?>
					<div style="flex:0 0 240px">
						<?php if ($_hasFees): ?>
						<div class="ev-card" style="margin-bottom:<?= $_hasLinks ? '14px' : '0' ?>">
							<h4><i class="fas fa-ticket-alt" style="margin-right:5px"></i>Admission &amp; Fees</h4>
							<?php foreach ($eventFees as $fee): ?>
							<div class="ev-detail-row">
								<span class="ev-detail-label"><?= htmlspecialchars($fee['AdmissionType']) ?></span>
								<span class="ev-detail-value"><?= (float)$fee['Cost'] == 0 ? '<span style="color:#276749">Free</span>' : '$' . number_format((float)$fee['Cost'], 2) ?></span>
							</div>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
						<?php if ($_hasLinks): ?>
						<div class="ev-card" style="margin-bottom:0">
							<h4><i class="fas fa-link" style="margin-right:5px"></i>Links</h4>
							<?php foreach ($externalLinks as $_el): ?>
								<?php if (trim($_el['Url']) && trim($_el['Title'])): ?>
								<a href="<?= htmlspecialchars($_el['Url']) ?>" target="_blank" class="ev-map-btn" style="margin-top:6px">
									<i class="<?= htmlspecialchars($_el['Icon']) ?>"></i> <?= htmlspecialchars($_el['Title']) ?>
								</a>
								<?php endif; ?>
							<?php endforeach; ?>
						</div>
						<?php endif; ?>
					</div>
					<?php endif; ?>
				</div>
			</div><!-- /.ev-tab-panel (details) -->

			<?php // ---- Schedule Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-schedule">

				<?php
			$evSchedCategories = [
				'Administrative'    => ['icon' => 'fa-clipboard-list', 'color' => '#546e7a', 'bg' => '#eceff1'],
				'Tournament'        => ['icon' => 'fa-trophy',          'color' => '#b8860b', 'bg' => '#fffde7'],
				'Battlegame'        => ['icon' => 'fa-shield-alt',      'color' => '#c0392b', 'bg' => '#fdecea'],
				'Arts and Sciences' => ['icon' => 'fa-palette',         'color' => '#7b1fa2', 'bg' => '#f3e5f5'],
				'Class'             => ['icon' => 'fa-graduation-cap',  'color' => '#1565c0', 'bg' => '#e3f2fd'],
				'Feast and Food'    => ['icon' => 'fa-utensils',        'color' => '#e65100', 'bg' => '#fff3e0'],
				'Court'             => ['icon' => 'fa-crown',           'color' => '#4e342e', 'bg' => '#efebe9'],
				'Meeting'           => ['icon' => 'fa-users',           'color' => '#276749', 'bg' => '#f0fff4'],
				'Other'             => ['icon' => 'fa-star',            'color' => '#757575', 'bg' => '#fafafa'],
			];
			$evGridTodayKey = date('Ymd');
			?>
			<div class="ev-grid-view-toolbar">
				<div class="ev-grid-view-toggle" aria-label="Schedule view">
					<button type="button" class="ev-grid-view-btn" data-ev-view="list" aria-pressed="true"><i class="fas fa-list-ul"></i> List</button>
					<button type="button" class="ev-grid-view-btn" data-ev-view="grid" aria-pressed="false"><i class="fas fa-th"></i> Grid</button>
				</div>
				<?php if ($canManageSchedule): ?>
				<button type="button" class="ev-submit-btn" onclick="evOpenScheduleModal()">
					<i class="fas fa-plus"></i> Add Schedule Item
				</button>
				<?php endif; ?>
			</div>
			<div id="ev-sched-filters" style="display:none;flex-wrap:wrap;gap:6px;margin-bottom:14px"></div>
			<div id="ev-schedule-container">
			<?php
			$scheduleByDay = [];
			foreach ($scheduleList as $item) {
				$dayKey = date('Ymd', strtotime($item['StartTime']));
				$scheduleByDay[$dayKey][] = $item;
			}
			foreach ($scheduleByDay as $dayKey => $dayItems):
				$dayTs = strtotime($dayItems[0]['StartTime']);
			?>
			<div class="ev-sched-day-section" data-date="<?= date('Y-m-d', $dayTs) ?>">
				<div class="ev-sched-day-header"><?= date('l, F j, Y', $dayTs) ?></div>
				<table class="ev-table ev-sched-table" id="ev-schedule-table-<?= $dayKey ?>">
					<colgroup>
						<col style="width:90px">
						<col style="width:90px">
						<col style="width:22%">
						<col style="width:15%">
						<col style="width:18%">
						<col>
						<?php if ($canManageSchedule): ?><col style="width:56px"><?php endif; ?>
					</colgroup>
					<thead>
						<tr>
							<th>Start</th>
							<th>End</th>
							<th>Title</th>
							<th>Location</th>
							<th>Lead(s)</th>
							<th>Description</th>
							<?php if ($canManageSchedule): ?><th class="ev-del-cell"></th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="ev-schedule-tbody-<?= $dayKey ?>">
						<?php foreach ($dayItems as $item): ?>
						<?php
							$evCat        = $item['Category'] ?? 'Other';
							$evCatCfg     = $evSchedCategories[$evCat] ?? $evSchedCategories['Other'];
							$evSecCat     = $item['SecondaryCategory'] ?? '';
							$evSecCatCfg  = $evSecCat ? ($evSchedCategories[$evSecCat] ?? $evSchedCategories['Other']) : null;
						?>
						<tr id="ev-schedule-row-<?= (int)$item['EventScheduleId'] ?>" data-title="<?= htmlspecialchars($item['Title'], ENT_QUOTES) ?>" data-start="<?= date('Y-m-d\TH:i', strtotime($item['StartTime'])) ?>" data-end="<?= date('Y-m-d\TH:i', strtotime($item['EndTime'])) ?>" data-location="<?= htmlspecialchars($item['Location'], ENT_QUOTES) ?>" data-description="<?= htmlspecialchars($item['Description'], ENT_QUOTES) ?>" data-category="<?= htmlspecialchars($evCat, ENT_QUOTES) ?>" data-secondary-category="<?= htmlspecialchars($evSecCat, ENT_QUOTES) ?>" data-leads="<?= htmlspecialchars(json_encode($item['Leads'] ?? []), ENT_QUOTES) ?>" data-menu="<?= htmlspecialchars($item['Menu'] ?? '', ENT_QUOTES) ?>" data-cost="<?= htmlspecialchars((string)($item['Cost'] ?? ''), ENT_QUOTES) ?>" data-dietary="<?= htmlspecialchars($item['Dietary'] ?? '', ENT_QUOTES) ?>" data-allergens="<?= htmlspecialchars($item['Allergens'] ?? '', ENT_QUOTES) ?>" style="background:<?= $evCatCfg['bg'] ?>">
							<td style="white-space:nowrap"><?= date('g:ia', strtotime($item['StartTime'])) ?></td>
							<td style="white-space:nowrap"><?= date('g:ia', strtotime($item['EndTime'])) ?></td>
							<td style="white-space:nowrap"><i class="fas fa-fw <?= $evCatCfg['icon'] ?>" style="color:<?= $evCatCfg['color'] ?>" title="<?= htmlspecialchars($evCat) ?>"></i><?php if ($evSecCatCfg): ?><i class="fas fa-fw <?= $evSecCatCfg['icon'] ?>" style="color:<?= $evSecCatCfg['color'] ?>;margin-right:4px" title="<?= htmlspecialchars($evSecCat) ?>"></i><?php else: ?><span style="display:inline-block;width:1.25em;margin-right:4px"></span><?php endif; ?><?= htmlspecialchars($item['Title']) ?><?php if (($evCat === 'Feast and Food' || $evSecCat === 'Feast and Food') && !empty($item['Menu'])): ?> <i class="fas fa-scroll" style="color:#e65100;font-size:10px;margin-left:4px;vertical-align:middle" title="Has menu"></i><?php endif; ?></td>
							<td><?= htmlspecialchars($item['Location']) ?></td>
							<td><?php foreach ($item['Leads'] ?? [] as $li => $lead) { if ($li > 0) echo ', '; echo '<a href="' . UIR . 'Playernew/index/' . (int)$lead['MundaneId'] . '">' . htmlspecialchars($lead['Persona']) . '</a>'; } ?></td>
							<td><?= htmlspecialchars($item['Description']) ?></td>
							<?php if ($canManageSchedule): ?>
							<td class="ev-del-cell">
								<button class="ev-edit-link" title="Edit" onclick="evOpenScheduleEditModal(<?= (int)$item['EventScheduleId'] ?>, this)" style="background:none;border:none;cursor:pointer;color:#666;font-size:13px;padding:0 5px 0 0">
									<i class="fas fa-pencil-alt"></i>
								</button>
								<button class="ev-del-link" title="Remove"
									onclick="evRemoveSchedule(this, <?= (int)$item['EventScheduleId'] ?>)"
									style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:16px;padding:0">
									&times;
								</button>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
			<?php endforeach; ?>
			</div><!-- /#ev-schedule-container -->

			<div id="ev-schedule-grid-container" style="display:none">
			<?php
			// Build per-day grid data using same $scheduleByDay bucketing.
			foreach ($scheduleByDay as $dayKey => $dayItems):
				$dayTs = strtotime($dayItems[0]['StartTime']);

				// Collect min/max and per-category buckets
				$catBuckets = [];
				$minStart = PHP_INT_MAX;
				$maxEnd   = 0;
				foreach ($dayItems as $it) {
					$s = strtotime($it['StartTime']);
					$e = strtotime($it['EndTime']);
					if ($e < $s) $e = $s;
					if ($s < $minStart) $minStart = $s;
					if ($e > $maxEnd)   $maxEnd   = $e;
					$cat = $it['Category'] ?? 'Other';
					if (!isset($evSchedCategories[$cat])) $cat = 'Other';
					$catBuckets[$cat][] = ['item' => $it, 'start' => $s, 'end' => $e];
				}
				if ($minStart === PHP_INT_MAX) continue;

				// Snap to half-hour grid, pad ±30min
				$gridStart = (int) floor($minStart / 1800) * 1800 - 1800;
				$gridEnd   = (int) ceil ($maxEnd   / 1800) * 1800 + 1800;
				$totalSlots = max(1, (int)(($gridEnd - $gridStart) / 1800));

				// Only categories with items (preserve palette order)
				$activeCats = [];
				foreach ($evSchedCategories as $cName => $cCfg) {
					if (!empty($catBuckets[$cName])) $activeCats[] = $cName;
				}
				if (empty($activeCats)) continue;

				// Overlap lane assignment per category
				$laneMap = []; // [scheduleId => ['lane'=>i,'lanes'=>n]]
				foreach ($activeCats as $cName) {
					$items = $catBuckets[$cName];
					usort($items, function($a,$b){ return $a['start'] <=> $b['start']; });
					$laneEnds = [];
					$assigns = [];
					foreach ($items as $entry) {
						$placed = false;
						foreach ($laneEnds as $li => $laneEnd) {
							if ($laneEnd <= $entry['start']) {
								$laneEnds[$li] = $entry['end'];
								$assigns[] = ['entry' => $entry, 'lane' => $li];
								$placed = true;
								break;
							}
						}
						if (!$placed) {
							$laneEnds[] = $entry['end'];
							$assigns[]  = ['entry' => $entry, 'lane' => count($laneEnds) - 1];
						}
					}
					$total = max(1, count($laneEnds));
					foreach ($assigns as $a) {
						$sid = (int)$a['entry']['item']['EventScheduleId'];
						$laneMap[$cName][$sid] = ['lane' => $a['lane'], 'lanes' => $total];
					}
					$catBuckets[$cName] = $items; // sorted
				}

				$nCols = count($activeCats);
				$bodyHeight = $totalSlots * 28;
			?>
				<div class="ev-grid-day" data-date="<?= date('Y-m-d', $dayTs) ?>" data-day-key="<?= $dayKey ?>" data-grid-start="<?= $gridStart ?>" data-grid-end="<?= $gridEnd ?>">
					<div class="ev-grid-day-header"><?= date('l, F j, Y', $dayTs) ?></div>
					<div class="ev-grid-scroller">
						<div class="ev-grid-inner" style="--ev-grid-cols: <?= $nCols ?>;">
							<div class="ev-grid-header-row">
								<div class="ev-grid-time-col-head"></div>
								<?php foreach ($activeCats as $cName):
									$cCfg = $evSchedCategories[$cName];
									$cCount = count($catBuckets[$cName]);
								?>
								<div class="ev-grid-cat-head" data-category="<?= htmlspecialchars($cName, ENT_QUOTES) ?>" style="background:<?= $cCfg['bg'] ?>;border-bottom-color:<?= $cCfg['color'] ?>">
									<i class="fas fa-fw <?= $cCfg['icon'] ?>" style="color:<?= $cCfg['color'] ?>"></i>
									<span class="ev-grid-cat-label" style="color:<?= $cCfg['color'] ?>"><?= htmlspecialchars($cName) ?></span>
									<span class="ev-grid-cat-count"><?= (int)$cCount ?></span>
								</div>
								<?php endforeach; ?>
							</div>
							<div class="ev-grid-body-row" style="height:<?= $bodyHeight ?>px">
								<div class="ev-grid-time-col">
									<?php for ($s = 0; $s < $totalSlots; $s++):
										$tt = $gridStart + $s * 1800;
										$isHour = (date('i', $tt) === '00');
									?>
									<div class="ev-grid-time-slot<?= $isHour ? ' ev-grid-time-hour' : ' ev-grid-time-half' ?>" style="top:<?= $s * 28 ?>px">
										<?php if ($isHour): ?><span class="ev-grid-time-lbl"><?= date('ga', $tt) ?></span><?php endif; ?>
									</div>
									<?php endfor; ?>
									<div class="ev-grid-now-dot" style="display:none"></div>
								</div>
								<?php foreach ($activeCats as $cName):
									$cCfg = $evSchedCategories[$cName];
								?>
								<div class="ev-grid-col" data-category="<?= htmlspecialchars($cName, ENT_QUOTES) ?>" style="background:repeating-linear-gradient(to bottom, transparent 0, transparent 55px, #f4f6f8 55px, #f4f6f8 56px)">
									<?php foreach ($catBuckets[$cName] as $entry):
										$it  = $entry['item'];
										$sid = (int)$it['EventScheduleId'];
										$startM = ($entry['start'] - $gridStart) / 60;
										$durM   = max(30, ($entry['end'] - $entry['start']) / 60);
										$topPx  = ($startM / 30) * 28;
										$hPx    = max(28, ($durM / 30) * 28);
										$lane   = $laneMap[$cName][$sid]['lane']  ?? 0;
										$lanes  = $laneMap[$cName][$sid]['lanes'] ?? 1;
										$leftPct  = ($lanes > 1) ? ($lane / $lanes) * 100 : 0;
										$widthPct = ($lanes > 1) ? (100 / $lanes) : 100;
										$secCat    = $it['SecondaryCategory'] ?? '';
										$secCfg    = $secCat ? ($evSchedCategories[$secCat] ?? null) : null;
										$hasMenu   = ($cName === 'Feast and Food' || $secCat === 'Feast and Food') && !empty($it['Menu']);
										$compact   = ($hPx < 42);
										$blockLabel = $it['Title'] . ' at ' . date('g:ia', $entry['start']);
									?>
									<div class="ev-grid-block<?= $compact ? ' ev-grid-block-compact' : '' ?>"
										data-schedule-id="<?= $sid ?>"
										data-category="<?= htmlspecialchars($cName, ENT_QUOTES) ?>"
										tabindex="0" role="button" aria-label="<?= htmlspecialchars($blockLabel, ENT_QUOTES) ?>"
										style="top:<?= (int)round($topPx) ?>px;height:<?= (int)round($hPx) ?>px;left:calc(<?= $leftPct ?>% + 2px);width:calc(<?= $widthPct ?>% - 4px);background:<?= $cCfg['bg'] ?>;border-left-color:<?= $cCfg['color'] ?>;<?= $secCfg ? 'box-shadow: inset -4px 0 0 '. $secCfg['color'] .', 0 1px 2px rgba(0,0,0,0.08);' : '' ?>"
										onclick="evGridBlockClick(<?= $sid ?>, event)">
										<div class="ev-grid-block-title">
											<?= htmlspecialchars($it['Title']) ?>
											<?php if ($hasMenu): ?><i class="fas fa-scroll" style="color:#e65100;font-size:9px;margin-left:3px" title="Has menu"></i><?php endif; ?>
										</div>
										<div class="ev-grid-block-time"><?= date('g:ia', $entry['start']) ?> – <?= date('g:ia', $entry['end']) ?></div>
										<?php if (!empty($it['Location'])): ?>
										<div class="ev-grid-block-loc"><i class="fas fa-map-marker-alt"></i> <?= htmlspecialchars($it['Location']) ?></div>
										<?php endif; ?>
										<?php if (!empty($it['Leads'])): ?>
										<div class="ev-grid-block-leads">
											<?php foreach (array_slice($it['Leads'], 0, 3) as $_ld): ?>
												<span class="ev-grid-lead-chip"><?= htmlspecialchars($_ld['Persona']) ?></span>
											<?php endforeach; ?>
										</div>
										<?php endif; ?>
									</div>
									<?php endforeach; ?>
								</div>
								<?php endforeach; ?>
								<div class="ev-grid-now-line" style="display:none"></div>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
			</div><!-- /#ev-schedule-grid-container -->
			<div class="ev-empty" id="ev-schedule-empty"<?= empty($scheduleList) ? '' : ' style="display:none"' ?>>
				<i class="fas fa-clock" style="margin-right:6px"></i>No schedule items yet
			</div>

			</div><!-- /.ev-tab-panel (schedule) -->

			<?php // ---- Feast Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-feast">

				<?php if ($canManageFeast || $canManageSchedule): ?>
				<div style="margin-bottom:14px">
					<button type="button" class="ev-submit-btn" style="float:right" onclick="evOpenFeastScheduleModal()">
						<i class="fas fa-plus"></i> Add Feast Item
					</button>
				</div>
				<?php endif; ?>

				<div id="ev-meal-list">
				<?php if (!empty($mealList)): ?>
				<?php foreach ($mealList as $meal): ?>
				<?php
					$_mTimeHtml = '';
					if (!empty($meal['StartTime'])) {
						$_mTimeHtml = date('g:ia', strtotime($meal['StartTime']));
						if (!empty($meal['EndTime'])) $_mTimeHtml .= ' – ' . date('g:ia', strtotime($meal['EndTime']));
					}
				?>
				<div class="ev-meal-card" id="ev-meal-card-<?= (int)$meal['EventScheduleId'] ?>" data-schedule-id="<?= (int)$meal['EventScheduleId'] ?>">
					<div class="ev-meal-card-header">
						<span class="ev-meal-title"><i class="fas fa-utensils" style="color:#e65100;margin-right:7px"></i><?= htmlspecialchars($meal['Title']) ?></span>
						<span style="display:flex;align-items:center;gap:10px">
							<?php if ($meal['Cost'] !== null): ?>
							<span class="ev-meal-cost"><?= (float)$meal['Cost'] == 0 ? '<span style="color:#276749;font-weight:600">Free</span>' : '<span style="font-weight:600">$' . number_format((float)$meal['Cost'], 2) . '</span>' ?></span>
							<?php endif; ?>
							<?php if ($canManageFeast || $canManageSchedule): ?>
							<button class="ev-edit-btn" title="Edit feast item"
								onclick='evOpenFeastEditModal(<?= htmlspecialchars(json_encode(["EventScheduleId"=>(int)$meal["EventScheduleId"],"Title"=>$meal["Title"],"StartTime"=>$meal["StartTime"]??"","EndTime"=>$meal["EndTime"]??"","Location"=>$meal["Location"]??"","Description"=>$meal["Description"]??"","Category"=>$meal["Category"]??"","SecondaryCategory"=>$meal["SecondaryCategory"]??"","Leads"=>$meal["Leads"]??[],"Menu"=>$meal["Menu"]??"","Cost"=>$meal["Cost"],"Dietary"=>$meal["Dietary"]??"","Allergens"=>$meal["Allergens"]??""]), ENT_QUOTES) ?>)'>
								<i class="fas fa-pencil-alt"></i></button>
							<button class="ev-del-link" title="Remove feast item" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:18px;padding:0;line-height:1"
								onclick="evRemoveFeastCard(this, <?= (int)$meal['EventScheduleId'] ?>)">&times;</button>
							<?php endif; ?>
						</span>
					</div>
					<?php if ($_mTimeHtml || !empty($meal['Location'])): ?>
					<div style="padding:5px 14px 0;font-size:12px;color:#718096">
						<?php if ($_mTimeHtml): ?><i class="fas fa-clock" style="margin-right:4px"></i><?= htmlspecialchars($_mTimeHtml) ?><?php endif; ?>
						<?php if (!empty($meal['Location'])): ?><?php if ($_mTimeHtml): ?> &nbsp;&middot;&nbsp; <?php endif; ?><i class="fas fa-map-marker-alt" style="margin-right:4px"></i><?= htmlspecialchars($meal['Location']) ?><?php endif; ?>
					</div>
					<?php endif; ?>
					<?php if (!empty(trim($meal['Menu'] ?? ''))): ?>
					<div class="ev-meal-menu"><?= htmlspecialchars($meal['Menu']) ?></div>
					<?php endif; ?>
					<?php
						$_mDietary   = array_filter(array_map('trim', explode(',', $meal['Dietary']   ?? '')));
						$_mAllergens = array_filter(array_map('trim', explode(',', $meal['Allergens'] ?? '')));
					?>
					<?php if (!empty($_mDietary) || !empty($_mAllergens)): ?>
					<div class="ev-meal-footer">
						<?php foreach ($_mDietary as $_tag): ?>
						<span class="ev-meal-tag ev-meal-tag-dietary"><i class="fas fa-leaf"></i><?= htmlspecialchars($_tag) ?></span>
						<?php endforeach; ?>
						<?php foreach ($_mAllergens as $_tag): ?>
						<span class="ev-meal-tag ev-meal-tag-allergen"><i class="fas fa-exclamation-triangle"></i><?= htmlspecialchars($_tag) ?></span>
						<?php endforeach; ?>
					</div>
					<?php endif; ?>
				</div>
				<?php endforeach; ?>
				<?php endif; ?>
				</div><!-- /#ev-meal-list -->

				<div class="ev-empty" id="ev-meal-empty"<?= empty($mealList) ? '' : ' style="display:none"' ?>>
					<i class="fas fa-utensils" style="margin-right:6px"></i>No meals added yet
				</div>

			</div><!-- /.ev-tab-panel (feast) -->

			<div class="ev-tab-panel" id="ev-tab-attendance">

				<?php if ($reconciled): ?>
				<div style="background:#f0fff4;border:1px solid #68d391;border-radius:6px;padding:12px 16px;margin-bottom:14px;color:#276749;font-size:13px;line-height:1.5;">
					<i class="fas fa-check-circle" style="margin-right:6px;"></i>
					<strong>Reconciled.</strong> Past attendance has been moved to a new occurrence dated to match those records.
				</div>
				<?php endif; ?>
				<?php if ($attDateMismatch && $canManage): ?>
				<div style="background:#fffbeb;border:1px solid #f6ad55;border-radius:6px;padding:12px 16px;margin-bottom:14px;color:#7b341e;font-size:13px;line-height:1.5;">
					<strong><i class="fas fa-exclamation-triangle" style="margin-right:6px;"></i>Data mismatch detected:</strong>
					This future event has <?= count($attendanceList) ?> attendance record<?= count($attendanceList) != 1 ? 's' : '' ?> dated in the past —
					likely because this occurrence's date was edited forward after attendance was entered.
					<?php if ($canManageAttendance): ?>
					<br><br>
					<strong>Reconcile</strong> will create a new past occurrence for those dates and move the attendance there, leaving this future occurrence clean.
					<form id="ev-reconcile-form" method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/reconcile"
						  style="display:inline-block;margin-top:8px;">
						<button type="button" style="background:#c05621;color:#fff;border:none;border-radius:4px;padding:7px 16px;font-size:13px;font-weight:600;cursor:pointer;"
						        onclick="pnConfirm({title:'Reconcile Attendance?',message:'Move <?= count($attendanceList) ?> attendance record(s) to a new past occurrence? This cannot be undone.',confirmText:'Reconcile',danger:true},function(){document.getElementById('ev-reconcile-form').submit();});">
							<i class="fas fa-tools" style="margin-right:5px;"></i>Reconcile Attendance
						</button>
					</form>
					<?php endif; ?>
				</div>
				<?php endif; ?>
				<div class="ev-export-bar">
					<?php if ($canManageAttendance && $checkinOpen): ?>
					<button type="button" class="ev-icon-btn" id="ev-signin-link-open-btn" title="Sign-in Link" onclick="evOpenSigninLinkModal()" style="display:inline-flex;align-items:center;gap:6px"><i class="fas fa-qrcode"></i> Sign-In Link</button>
					<?php endif; ?>
					<button class="ev-icon-btn" title="Export CSV" onclick="evExportAttendanceCsv()"><i class="fas fa-download"></i></button>
					<button class="ev-icon-btn" title="Print" onclick="evPrintAttendance()"><i class="fas fa-print"></i></button>
				</div>
				<?php if ($canManageAttendance): ?>
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php else: ?>
				<div class="ev-att-form">
					<h4><i class="fas fa-plus-circle" style="margin-right:6px;color:#276749"></i>Add Attendance</h4>
					<form method="post" id="ev-attendance-form" action="<?= UIR ?>EventAjax/add_attendance/<?= $eventId ?>/<?= $detailId ?>" onsubmit="evHandleAttendanceSubmit(this); return false;">
						<div class="ev-form-row">
							<div class="ev-form-field">
								<label>Player</label>
								<input type="text" id="ev-PlayerName" name="PlayerName" style="width:200px"
									value="<?= htmlspecialchars($attendanceForm['PlayerName'] ?? '') ?>"
									autocomplete="off" placeholder="Search players…">
								<input type="hidden" id="ev-MundaneId" name="MundaneId"
									value="<?= (int)($attendanceForm['MundaneId'] ?? 0) ?>">
							</div>
							<div class="ev-form-field">
								<label>Class</label>
								<select id="ev-ClassId" name="ClassId" style="width:120px">
									<option value="">— select —</option>
									<?php foreach ($Classes ?? [] as $class): ?>
									<option value="<?= (int)$class['ClassId'] ?>"
										<?= ($attendanceForm['ClassId'] ?? '') == $class['ClassId'] ? 'selected' : '' ?>>
										<?= htmlspecialchars($class['Name']) ?>
									</option>
									<?php endforeach; ?>
								</select>
							</div>
							<div class="ev-form-field">
								<label>Credits</label>
								<input type="text" id="ev-Credits" name="Credits" style="width:55px" oninput="evSyncCredits(this.value)"
									value="<?= (float)($attendanceForm['Credits'] ?? $defaultCredits) ?>">
							</div>
							<div class="ev-form-field" style="justify-content:flex-end">
								<label>&nbsp;</label>
								<button type="submit" class="ev-submit-btn">
									<i class="fas fa-plus"></i> Add
								</button>
							</div>
						</div>
						<input type="hidden" id="ev-AttendanceDate" name="AttendanceDate"
							value="<?= $eventStart ? date('Y-m-d', strtotime($eventStart)) : date('Y-m-d') ?>">
					</form>
				</div>
				<?php endif; // $checkinOpen ?>
				<?php endif; // $canManageAttendance — re-opened below ?>

				<?php if (count($attendanceList) > 0): ?>
				<table class="display" id="ev-attendance-table" style="width:100%">
					<thead>
						<tr>
							<th>Player</th>
							<th>Kingdom</th>
							<th>Park</th>
							<th>Class</th>
							<th>Credits</th>
							<?php if ($canManageAttendance): ?>
							<th class="ev-del-cell"></th>
							<?php endif; ?>
						</tr>
					</thead>
					<tbody>
						<?php foreach ($attendanceList as $att): ?>
						<tr data-att-id="<?= (int)$att['AttendanceId'] ?>" data-mundane-id="<?= (int)$att['MundaneId'] ?>" data-att-class="<?= (int)$att['ClassId'] ?>" data-att-date="<?= htmlspecialchars($att['Date'] ?? '') ?>">
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$att['MundaneId'] ?>"><?= htmlspecialchars($att['Persona']) ?></a></td>
							<td><?php if (!empty($att['KingdomId'])): ?><a href="<?= UIR ?>Kingdom/profile/<?= (int)$att['KingdomId'] ?>"><?= htmlspecialchars($att['KingdomName']) ?></a><?php else: ?><?= htmlspecialchars($att['KingdomName'] ?? '') ?><?php endif; ?></td>
							<td><?php if (!empty($att['ParkId'])): ?><a href="<?= UIR ?>Park/profile/<?= (int)$att['ParkId'] ?>"><?= htmlspecialchars($att['ParkName']) ?></a><?php else: ?><?= htmlspecialchars($att['ParkName'] ?? '') ?><?php endif; ?></td>
							<td class="ev-class-cell"><?= htmlspecialchars($att['ClassName']) ?></td>
							<td class="ev-credits-cell"><?= htmlspecialchars($att['Credits']) ?></td>
							<?php if ($canManageAttendance): ?>
							<td class="ev-del-cell">
								<button class="ev-icon-btn" title="Edit class &amp; credits" style="color:#9ca3af;border:none;background:none;padding:2px 4px;font-size:0.8rem;" onclick="evOpenAttEdit(this)"><i class="fas fa-pencil-alt"></i></button>
								<a class="ev-del-link" title="Remove" href="#"
									data-del-url="<?= UIR ?>AttendanceAjax/attendance/<?= (int)$att['AttendanceId'] ?>/delete"
									onclick="evConfirmAttDelete(event, this)">×</a>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="ev-empty">
					<i class="fas fa-clipboard" style="margin-right:6px"></i>No attendance recorded yet
				</div>
				<?php endif; ?>

			</div><!-- /.ev-tab-panel -->

			<?php /* [TOURNAMENTS HIDDEN] tab panel */ ?>

			<?php // ---- RSVPs Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-rsvp">
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php endif; ?>
				<div style="display:flex;align-items:center;justify-content:space-between;margin:0 0 12px">
					<p class="ev-rsvp-summary" style="font-size:.95em;color:#4a5568;margin:0">
						<i class="fas fa-check-circle ev-rsvp-going-icon" style="margin-right:4px;color:#276749"></i>
						<strong><?= $rsvpCounts['going'] ?></strong> Going
						&nbsp;&nbsp;
						<i class="fas fa-star ev-rsvp-interested-icon" style="margin-right:4px;color:#b7791f"></i>
						<strong><?= $rsvpCounts['interested'] ?></strong> Interested
					</p>
					<div style="display:flex;gap:6px">
						<button class="ev-icon-btn" title="Export CSV" onclick="evExportRsvpCsv()"><i class="fas fa-download"></i></button>
						<button class="ev-icon-btn" title="Print" onclick="evPrintRsvp()"><i class="fas fa-print"></i></button>
					</div>
				</div>
				<?php if ($loggedIn && count($rsvpList) > 0): ?>
					<div style="margin-bottom:10px;position:relative;">
						<i class="fas fa-search" style="position:absolute;left:9px;top:50%;transform:translateY(-50%);color:#a0aec0;font-size:12px;pointer-events:none"></i>
						<input type="text" id="ev-rsvp-search" placeholder="Filter by name…" oninput="evFilterRsvp(this.value)"
							style="width:100%;box-sizing:border-box;padding:6px 28px 6px 28px;border:1px solid #e2e8f0;border-radius:5px;font-size:13px;color:#2d3748;">
						<button id="ev-rsvp-clear" onclick="evClearRsvpSearch()" title="Clear search"
							style="display:none;position:absolute;right:7px;top:50%;transform:translateY(-50%);background:none;border:none;color:#a0aec0;font-size:14px;cursor:pointer;padding:0;line-height:1;">&times;</button>
					</div>
					<table class="ev-table" id="ev-rsvp-table">
						<thead>
							<tr>
									<th>Player</th>
									<th>Kingdom</th>
									<th>Park</th>
									<th>Status</th>
									<th style="white-space:nowrap">Waivered?
									<span class="ev-rsvp-th-tooltip" style="font-size:11px;color:#a0aec0;margin-left:2px">(?)<span class="ev-rsvp-th-tip">This column indicates that the player's profile is marked as having a waiver. Please follow your kingdom, park, and/or event policy for confirming or completing waivers at check-in if necessary.</span></span>
								</th>
									<?php if ($canManageAttendance): ?>
									<th style="text-align:right;white-space:nowrap">
										<label style="display:block;font-size:10px;font-weight:600;color:#718096;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px">Sign-in Credits</label>
										<input type="number" id="ev-rsvp-credits" value="1" min="0.25" step="0.25"
											style="width:55px;padding:3px 5px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center"
											oninput="evSyncCredits(this.value)" title="Credits to assign on quick check-in">
									</th>
									<?php endif; ?>
								</tr>
						</thead>
						<tbody>
							<?php foreach ($rsvpList as $attendee): ?>
							<tr>
								<td><a href="<?= UIR ?>Player/profile/<?= $attendee['MundaneId'] ?>"><?= htmlspecialchars($attendee['Persona']) ?></a></td>
								<td style="white-space:nowrap"><?php if (!empty($attendee['KingdomId']) && !empty($attendee['KingdomAbbr'])): ?><a href="<?= UIR ?>Kingdom/profile/<?= (int)$attendee['KingdomId'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attendee['KingdomAbbr']) ?></a><?php else: ?><?= htmlspecialchars($attendee['KingdomAbbr'] ?? '') ?><?php endif; ?></td>
								<td style="white-space:nowrap"><?php if (!empty($attendee['ParkId']) && !empty($attendee['ParkAbbr'])): ?><a href="<?= UIR ?>Park/profile/<?= (int)$attendee['ParkId'] ?>" target="_blank" rel="noopener"><?= htmlspecialchars($attendee['ParkAbbr']) ?></a><?php else: ?><?= htmlspecialchars($attendee['ParkAbbr'] ?? '') ?><?php endif; ?></td>
								<td style="white-space:nowrap">
									<?php if ($attendee['Status'] === 'going'): ?>
										<i class="fas fa-check-circle ev-rsvp-going-icon" style="color:#276749;margin-right:4px"></i>Going
									<?php else: ?>
										<i class="fas fa-star ev-rsvp-interested-icon" style="color:#b7791f;margin-right:4px"></i>Interested
									<?php endif; ?>
								</td>
								<td style="text-align:center;white-space:nowrap">
									<?php if (!empty($attendee['Waivered'])): ?>
										<i class="fas fa-check-circle" style="color:#276749" title="Waivered"></i>
									<?php else: ?>
										<i class="fas fa-circle" style="color:#cbd5e0" title="Not waivered"></i>
									<?php endif; ?>
								</td>
								<?php if ($canManageAttendance): ?>
								<td style="text-align:right;white-space:nowrap">
									<?php if (!isset($checkedInIds[$attendee['MundaneId']]) && $checkinOpen && !empty($attendee['LastClassId'])): ?>
									<button class="ev-checkin-btn ev-checkin-as-btn" type="button"
										data-mundane="<?= (int)$attendee['MundaneId'] ?>"
										onclick="evQuickCheckin(this, <?= (int)$attendee['MundaneId'] ?>, <?= (int)$attendee['LastClassId'] ?>)">
										<i class="fas fa-user-check"></i> <span class="ev-checkin-label">Check-in </span>as <?= htmlspecialchars($attendee['LastClassName'] ?? '') ?>
									</button>
									<?php endif; ?>
									<button class="ev-checkin-btn<?= isset($checkedInIds[$attendee['MundaneId']]) ? ' ev-checkin-done' : '' ?>" type="button"
										data-mundane="<?= (int)$attendee['MundaneId'] ?>"
										data-persona="<?= htmlspecialchars($attendee['Persona'], ENT_QUOTES) ?>"
										<?php if (!isset($checkedInIds[$attendee['MundaneId']]) && $checkinOpen): ?>
										onclick="evOpenCheckinModal(<?= (int)$attendee['MundaneId'] ?>, <?= htmlspecialchars(json_encode($attendee['Persona']), ENT_QUOTES) ?>)"
										<?php else: ?>disabled<?php endif; ?>>
										<i class="fas fa-user-check"></i> <?= isset($checkedInIds[$attendee['MundaneId']]) ? 'Checked In' : '<span class="ev-checkin-label">Check-in </span>as...' ?>
									</button>
									<button class="ev-rsvp-del-btn" type="button"
										onclick="evDeleteRsvp(this, <?= (int)$attendee['MundaneId'] ?>)" title="Remove RSVP">
										<i class="fas fa-times"></i>
									</button>
								</td>
								<?php endif; ?>
							</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				<?php elseif ($loggedIn): ?>
				<div class="ev-empty">
					<i class="fas fa-calendar-check" style="margin-right:6px"></i><?php echo $isPastEvent ? 'No RSVPs' : 'No RSVPs yet' ?>
				</div>
				<?php elseif ($rsvpCount === 0): ?>
				<div class="ev-empty">
					<i class="fas fa-calendar-check" style="margin-right:6px"></i><?php echo $isPastEvent ? 'No RSVPs' : 'No RSVPs yet' ?>
				</div>
				<?php endif; ?>
			</div><!-- /.ev-tab-panel -->

			<?php // ---- Staff Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-staff">

				<?php if ($canManageStaff): ?>
				<div style="margin-bottom:14px">
					<button type="button" class="ev-submit-btn" style="float:right" onclick="evOpenStaffModal()">
						<i class="fas fa-plus"></i> Add Staff
					</button>
				</div>
				<?php endif; ?>

				<?php if (!empty($StaffList)): ?>
				<table class="ev-table" id="ev-staff-table">
					<thead>
						<tr>
							<th>Player</th>
							<th>Role</th>
							<th>Can Manage</th>
							<th>Attendance</th>
							<th>Schedule</th>
							<th>Feast</th>
							<?php if ($canManageStaff): ?><th class="ev-del-cell">&times;</th><?php endif; ?>
						</tr>
					</thead>
					<tbody id="ev-staff-tbody">
						<?php foreach ($StaffList as $staff): ?>
						<tr id="ev-staff-row-<?= (int)$staff['EventStaffId'] ?>">
							<td><a href="<?= UIR ?>Player/profile/<?= (int)$staff['MundaneId'] ?>"><?= htmlspecialchars($staff['Persona']) ?></a></td>
							<td><?= htmlspecialchars($staff['RoleName']) ?></td>
							<td><?= $staff['CanManage'] ? '<i class="fas fa-check" style="color:#276749"></i>' : '<i class="fas fa-times" style="color:#a0aec0"></i>' ?></td>
							<td><?= $staff['CanAttendance'] ? '<i class="fas fa-check" style="color:#276749"></i>' : '<i class="fas fa-times" style="color:#a0aec0"></i>' ?></td>
							<td><?= $staff['CanSchedule'] ? '<i class="fas fa-check" style="color:#276749"></i>' : '<i class="fas fa-times" style="color:#a0aec0"></i>' ?></td>
							<td><?= $staff['CanFeast'] ? '<i class="fas fa-check" style="color:#276749"></i>' : '<i class="fas fa-times" style="color:#a0aec0"></i>' ?></td>
							<?php if ($canManageStaff): ?>
							<td class="ev-del-cell">
								<button class="ev-del-link" title="Remove"
									onclick="evRemoveStaff(this, <?= (int)$staff['EventStaffId'] ?>)"
									style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:16px;padding:0">
									&times;
								</button>
							</td>
							<?php endif; ?>
						</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="ev-empty" id="ev-staff-empty">
					<i class="fas fa-id-badge" style="margin-right:6px"></i>No staff assigned yet
				</div>
				<?php endif; ?>

			</div><!-- /.ev-tab-panel (staff) -->

			<?php if ($hasMapTab): ?>
			<?php
				$mapOpenUrl  = $mapLink ?: null;
				$mapQuery    = urlencode($mapQueryAddress);
				if ($mapLink && strpos($mapLink, 'q=@') !== false) {
					// lat/lng link — strip @ for embed (Google Maps embed doesn't accept @)
					$mapEmbedUrl = str_replace('?q=@', '?q=', $mapLink) . '&output=embed&z=14';
				} else {
					$mapEmbedUrl = 'https://maps.google.com/maps?q=' . $mapQuery . '&output=embed';
				}
				if (!$mapOpenUrl) $mapOpenUrl = 'https://maps.google.com/maps?q=' . $mapQuery;
			?>
			<?php // ---- Map Tab ---- ?>
			<div class="ev-tab-panel" id="ev-tab-map">
				<div style="margin-bottom:10px;display:flex;justify-content:flex-end">
					<a href="<?= htmlspecialchars($mapOpenUrl) ?>" target="_blank" class="pk-btn pk-btn-secondary" style="font-size:13px;padding:6px 14px;text-decoration:none">
						<i class="fas fa-external-link-alt" style="margin-right:6px"></i>Open in Maps
					</a>
				</div>
				<div style="width:100%;border-radius:8px;overflow:hidden;border:1px solid #e2e8f0">
					<iframe
						src="<?= htmlspecialchars($mapEmbedUrl) ?>"
						width="100%"
						height="400"
						style="border:0;display:block"
						allowfullscreen=""
						loading="lazy"
						referrerpolicy="no-referrer-when-downgrade"
					></iframe>
				</div>
			</div><!-- /.ev-tab-panel -->
			<?php endif; ?>

			<?php if ($canManage): ?>
			<div class="ev-tab-panel" id="ev-tab-admin">
				<?php if (!$checkinOpen): ?>
				<div class="ev-checkin-locked"><i class="fas fa-clock"></i> Sign-ins for this event can be processed starting on <?= htmlspecialchars($checkinOpenLabel) ?>.</div>
				<?php endif; ?>
				<ul style="margin:0;padding:0;list-style:none;display:flex;flex-wrap:wrap;gap:8px">
					<li>
						<a href="<?= UIR ?>Admin/permissions/Event/<?= $eventId ?>/<?= $detailId ?>" class="ev-admin-link-btn">
							<i class="fas fa-key"></i> Roles &amp; Permissions
						</a>
					</li>
				</ul>
			</div><!-- /.ev-tab-panel -->
			<?php endif; ?>

		</div><!-- /.ev-tabs -->
	</div><!-- /.ev-main -->

</div><!-- /.ev-layout -->

<?php if ($CanManageEvent ?? false): ?>
<div class="ev-modal-overlay" id="ev-edit-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-pencil-alt" style="margin-right:8px"></i>Edit Event Details</h3>
			<button class="ev-modal-close" type="button" onclick="evCloseEditModal()">&times;</button>
		</div>
		<form method="post" id="ev-edit-form" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/edit">
			<div class="ev-modal-body">

				<?php if ($isPastEvent): ?>
				<div class="ev-modal-warning-box">
					<i class="fas fa-exclamation-triangle" style="margin-right:6px;flex-shrink:0;margin-top:2px"></i>
					<span><strong>Stop!</strong> You are editing a past event. This can impact data for the event including attendance credit assignment. Use caution before proceeding.</span>
				</div>
				<?php endif; ?>

				<div class="ev-modal-section">
					<h4>Event Name &amp; Type</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field" style="flex:2">
							<label>Name</label>
							<input type="text" name="EventName" value="<?= htmlspecialchars($info['Name'] ?? '') ?>" required>
						</div>
						<div class="ev-modal-field" style="flex:1">
							<label>Event Type</label>
							<select name="EventType">
								<option value="">-- None --</option>
								<option value="Coronation"<?= ($eventType === 'Coronation') ? ' selected' : '' ?>>Coronation</option>
								<option value="Midreign"<?= ($eventType === 'Midreign') ? ' selected' : '' ?>>Midreign</option>
								<option value="Endreign"<?= ($eventType === 'Endreign') ? ' selected' : '' ?>>Endreign</option>
								<option value="Crown Qualifications"<?= ($eventType === 'Crown Qualifications') ? ' selected' : '' ?>>Crown Qualifications</option>
								<option value="Meeting"<?= ($eventType === 'Meeting') ? ' selected' : '' ?>>Meeting</option>
								<option value="Althing"<?= ($eventType === 'Althing') ? ' selected' : '' ?>>Althing</option>
								<option value="Interkingdom Event"<?= ($eventType === 'Interkingdom Event') ? ' selected' : '' ?>>Interkingdom Event</option>
								<option value="Weaponmaster"<?= ($eventType === 'Weaponmaster') ? ' selected' : '' ?>>Weaponmaster</option>
								<option value="Warmaster"<?= ($eventType === 'Warmaster') ? ' selected' : '' ?>>Warmaster</option>
								<option value="Dragonmaster"<?= ($eventType === 'Dragonmaster') ? ' selected' : '' ?>>Dragonmaster</option>
								<option value="Other"<?= ($eventType === 'Other') ? ' selected' : '' ?>>Other</option>
							</select>
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Dates</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>Start Date &amp; Time</label>
							<input type="text" name="StartDate" id="ev-fp-start" autocomplete="off"
								value="<?php $sTs = $eventStart ? strtotime($eventStart) : 0; echo ($sTs > 0) ? date('Y-m-d\TH:i', $sTs) : ''; ?>">
						</div>
						<div class="ev-modal-field">
							<label>End Date &amp; Time</label>
							<input type="text" name="EndDate" id="ev-fp-end" autocomplete="off"
								value="<?php $eTs = $eventEnd ? strtotime($eventEnd) : 0; echo ($eTs > 0) ? date('Y-m-d\TH:i', $eTs) : ''; ?>">
						</div>
					</div>
				</div>

				<div class="ev-modal-section" id="ev-fees-section">
					<h4>Admission &amp; Fees</h4>
					<div id="ev-fees-list" style="margin-bottom:8px"></div>
					<button type="button" onclick="evFeesAdd()" style="background:#ebf8ff;border:1px solid #90cdf4;color:#2b6cb0;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer">
						<i class="fas fa-plus"></i> Add Fee
					</button>
					<input type="hidden" name="Fees" id="ev-fees-json">
				</div>

				<div class="ev-modal-section">
					<h4>Description</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label style="display:flex;align-items:center;gap:6px;">
								Description <span class="kn-admin-hint-inline">(optional — Markdown supported)</span>
								<button type="button" class="kn-md-help-btn" onclick="document.getElementById('ev-md-help-overlay').classList.add('kn-open')" title="Markdown help">?</button>
							</label>
							<textarea name="Description" rows="5"><?= htmlspecialchars(rawurldecode($description)) ?></textarea>
						</div>
					</div>
				</div>

				<div class="ev-modal-section" id="ev-links-section">
					<h4>External Links</h4>
					<div id="ev-links-list" style="margin-bottom:8px"></div>
					<button type="button" onclick="evLinksAdd()" style="background:#ebf8ff;border:1px solid #90cdf4;color:#2b6cb0;border-radius:4px;padding:4px 10px;font-size:12px;cursor:pointer">
						<i class="fas fa-plus"></i> Add Link
					</button>
					<input type="hidden" name="ExternalLinks" id="ev-links-json">
				</div>

				<div class="ev-modal-section">
					<h4>Location</h4>
					<?php if ($parkId > 0 || $atParkId > 0): ?>
					<div class="ev-modal-info-box"><i class="fas fa-info-circle"></i> If no address is provided, the park address will be used.</div>
					<?php endif; ?>
					<div class="ev-modal-row">
						<div class="ev-modal-field ev-field-full">
							<label>Address</label>
							<input type="text" name="Address"
								value="<?= htmlspecialchars($cd['Address'] ?? '') ?>">
						</div>
					</div>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>City</label>
							<input type="text" name="City" value="<?= htmlspecialchars($city) ?>">
						</div>
						<div class="ev-modal-field">
							<label>Province / State</label>
							<input type="text" name="Province" value="<?= htmlspecialchars($province) ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Postal Code</label>
							<input type="text" name="PostalCode"
								value="<?= htmlspecialchars($cd['PostalCode'] ?? '') ?>">
						</div>
						<div class="ev-modal-field" style="max-width:120px">
							<label>Country</label>
							<input type="text" name="Country" value="<?= htmlspecialchars($country) ?>">
						</div>
					</div>
				</div>

				<div class="ev-modal-section">
					<h4>Map Link</h4>
					<div class="ev-modal-row">
						<div class="ev-modal-field">
							<label>Map URL</label>
							<input type="text" name="MapUrl"
								value="<?= htmlspecialchars($mapUrl) ?>" placeholder="https://maps.google.com/…">
						</div>
						<div class="ev-modal-field">
							<label>Map Link Text</label>
							<input type="text" name="MapUrlName"
								value="<?= htmlspecialchars($mapUrlName) ?>" placeholder="Campsite Map">
						</div>
					</div>
				</div>

			</div><!-- /.ev-modal-body -->
		</form>
		<div class="ev-modal-footer" style="justify-content:space-between;align-items:center;display:flex">
			<div>
<?php if ($canDelete): ?>
				<form method="post" action="<?= UIR ?>Event/detail/<?= $eventId ?>/<?= $detailId ?>/deletedetail" style="margin:0" onsubmit="evConfirmDeleteOccurrence(event, this)">
					<button type="submit" class="ev-modal-btn-delete">
						<i class="fas fa-trash-alt" style="margin-right:5px"></i>Delete Occurrence
					</button>
				</form>
<?php else: ?>
				<span class="ev-del-detail-wrap" onmouseenter="evPositionDelTooltip(this)" onmouseleave="">
					<button type="button" class="ev-modal-btn-delete ev-modal-btn-delete-disabled" disabled>
						<i class="fas fa-trash-alt" style="margin-right:5px"></i>Delete Occurrence
					</button>
					<span class="ev-del-detail-tooltip">You cannot delete an event that has associated attendance or RSVP data.</span>
				</span>
<?php endif; ?>
			</div>
			<div style="display:flex;gap:8px">
				<button type="button" class="ev-modal-btn-cancel" onclick="evCloseEditModal()">Cancel</button>
				<button type="submit" form="ev-edit-form" class="ev-modal-btn-save" id="ev-edit-save-btn" disabled>
					<i class="fas fa-save" style="margin-right:5px"></i>Save Changes
				</button>
			</div>
		</div>
	</div>
</div><!-- /.ev-edit-modal -->

<?php endif; ?>

<?php if ($canManageAttendance): ?>
<div class="ev-modal-overlay" id="ev-checkin-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-user-check" style="margin-right:8px"></i>Check In <span id="ev-checkin-name"></span></h3>
			<button class="ev-modal-close" type="button" onclick="evCloseCheckinModal()">&times;</button>
		</div>
		<form id="ev-checkin-form"
			action="<?= UIR ?>EventAjax/add_attendance/<?= $eventId ?>/<?= $detailId ?>"
			onsubmit="evHandleCheckinSubmit(this); return false;">
			<input type="hidden" name="MundaneId" id="ev-checkin-mundane-id">
			<input type="hidden" name="AttendanceDate" value="<?= date('Y-m-d') ?>">
			<div class="ev-modal-body">
				<div class="ev-modal-row">
					<div class="ev-modal-field">
						<label>Class</label>
						<select name="ClassId">
							<?php foreach ($Classes ?? [] as $class): ?>
							<option value="<?= (int)$class['ClassId'] ?>"><?= htmlspecialchars($class['Name']) ?></option>
							<?php endforeach; ?>
						</select>
					</div>
					<div class="ev-modal-field" style="max-width:100px">
						<label>Credits</label>
						<input type="number" name="Credits" value="1" min="0.25" step="0.25" oninput="evSyncCredits(this.value)">
					</div>
				</div>
			</div>
			<div class="ev-modal-footer">
				<button type="button" class="ev-modal-btn-cancel" onclick="evCloseCheckinModal()">Cancel</button>
				<button type="submit" class="ev-modal-btn-save">
					<i class="fas fa-user-check" style="margin-right:5px"></i>Check In
				</button>
			</div>
		</form>
	</div>
</div><!-- /.ev-checkin-modal -->
<?php endif; ?>

<!-- Markdown Help Modal -->
<div id="ev-md-help-overlay" onclick="if(event.target===this)this.classList.remove('kn-open')">
	<div class="kn-modal-box" style="width:420px;max-width:calc(100vw - 40px)">
		<div class="kn-modal-header">
			<h3 class="kn-modal-title"><i class="fas fa-hashtag" style="margin-right:8px;color:#2b6cb0"></i>Markdown Reference</h3>
			<button class="kn-modal-close-btn" onclick="document.getElementById('ev-md-help-overlay').classList.remove('kn-open')">&times;</button>
		</div>
		<div class="kn-modal-body" style="padding:16px 20px">
			<table class="kn-md-help-table">
				<thead><tr><th>You type</th><th>Result</th></tr></thead>
				<tbody>
					<tr><td><code>**bold**</code></td><td><strong>bold</strong></td></tr>
					<tr><td><code>*italic*</code></td><td><em>italic</em></td></tr>
					<tr><td><code>~~strikethrough~~</code></td><td><s>strikethrough</s></td></tr>
					<tr><td><code>[link](https://...)</code></td><td><a href="#">link</a></td></tr>
					<tr><td><code>`inline code`</code></td><td><code>inline code</code></td></tr>
					<tr><td><code>- item</code></td><td>• Bullet list</td></tr>
					<tr><td><code>1. item</code></td><td>1. Numbered list</td></tr>
					<tr><td><code># Heading</code></td><td><strong>Large heading</strong></td></tr>
					<tr><td><code>## Heading</code></td><td><strong>Smaller heading</strong></td></tr>
					<tr><td><code>&gt; quote</code></td><td><em>Blockquote</em></td></tr>
					<tr><td>Blank line</td><td>New paragraph</td></tr>
					<tr><td>Single newline</td><td>Line break</td></tr>
				</tbody>
			</table>
		</div>
	</div>
</div>

<?php if ($canManageAttendance): ?>
<!-- ── Edit Attendance Modal ──────────────────────────── -->
<div class="att-edit-overlay" id="ev-att-edit-overlay">
	<div class="att-edit-modal">
		<div class="att-edit-modal-header">
			<div class="att-edit-modal-title">
				<i class="fas fa-pencil-alt"></i> Edit Attendance
			</div>
			<button class="att-edit-modal-close" id="ev-att-edit-close" title="Close">&times;</button>
		</div>
		<div class="att-edit-modal-body">
			<div class="att-edit-feedback" id="ev-att-edit-feedback" style="display:none"></div>
			<input type="hidden" id="ev-att-edit-id">
			<input type="hidden" id="ev-att-edit-date">
			<input type="hidden" id="ev-att-edit-mundane">
			<div class="att-edit-row">
				<div class="att-edit-field">
					<label class="att-edit-label">Class</label>
					<select class="att-edit-select" id="ev-att-edit-class">
						<?php foreach ($Classes ?? [] as $class): ?>
						<option value="<?= (int)$class['ClassId'] ?>"><?= htmlspecialchars($class['Name']) ?></option>
						<?php endforeach; ?>
					</select>
				</div>
				<div class="att-edit-field att-edit-field-sm">
					<label class="att-edit-label">Credits</label>
					<input class="att-edit-input" type="number" id="ev-att-edit-credits" min="0.5" max="4" step="0.5">
				</div>
			</div>
		</div>
		<div class="att-edit-modal-footer">
			<button class="att-edit-btn-cancel" id="ev-att-edit-cancel">Cancel</button>
			<button class="att-edit-btn-save" id="ev-att-edit-save">Save</button>
		</div>
	</div>
</div>
<?php endif; ?>

<script>
function evPositionDelTooltip(wrap) {
	var btn = wrap.querySelector('button');
	var tip = wrap.querySelector('.ev-del-detail-tooltip');
	if (!btn || !tip) return;
	var r = btn.getBoundingClientRect();
	tip.style.left = r.left + 'px';
	tip.style.top  = (r.top + window.scrollY) + 'px';
}

var _evSavedCredits = parseFloat(localStorage.getItem('ev_credits_default')) || null;
if (_evSavedCredits) { var _evCr = document.getElementById('ev-Credits'); if (_evCr) _evCr.value = _evSavedCredits; }
var _evRsvpCr = document.getElementById('ev-rsvp-credits'); if (_evRsvpCr && _evSavedCredits) _evRsvpCr.value = _evSavedCredits;
</script>
<?php if ($canManageAttendance && $checkinOpen): ?>
<!-- Sign-in Link Modal -->
<div id="ev-signin-link-overlay" onclick="if(event.target===this)evCloseSigninLinkModal()">
	<div class="ev-signin-link-modal">
		<div class="ev-signin-link-modal-header">
			<span><i class="fas fa-qrcode" style="margin-right:8px;color:#2b6cb0"></i>Event Sign-In Link</span>
			<button type="button" onclick="evCloseSigninLinkModal()" class="ev-signin-link-close">&times;</button>
		</div>
		<div class="ev-signin-link-modal-body">
			<p class="ev-signin-link-blurb">Generate a shareable URL and QR code for players to sign themselves in to this event. The link expires 24 hours after the event ends.</p>
			<div class="ev-signin-link-row">
				<div class="ev-signin-link-field">
					<label>Credits per sign-in <span style="color:#e53e3e">*</span></label>
					<input type="number" id="ev-signin-credits" min="0.5" max="10" step="0.5" placeholder="" required>
				</div>
				<div class="ev-signin-link-field" style="justify-content:flex-end">
					<button type="button" class="ev-submit-btn" id="ev-signin-gen-btn" disabled>
						<i class="fas fa-link"></i> Generate
					</button>
				</div>
			</div>
			<div id="ev-signin-link-result" style="display:none">
				<div class="ev-signin-link-url-row">
					<input type="text" id="ev-signin-link-url" readonly>
					<button type="button" class="ev-icon-btn" id="ev-signin-copy-btn" title="Copy">
						<i class="fas fa-copy"></i> Copy
					</button>
					<button type="button" class="ev-icon-btn" id="ev-signin-qr-btn" title="QR Code">
						<i class="fas fa-qrcode"></i> QR
					</button>
				</div>
				<div id="ev-signin-link-expires"></div>
			</div>
			<div id="ev-signin-links-wrap">
				<button type="button" id="ev-signin-links-toggle">
					<i class="fas fa-chevron-right" id="ev-signin-links-chevron"></i>
					<span>Active Links</span> <span id="ev-signin-links-count"></span>
				</button>
				<div id="ev-signin-links-body" style="display:none">
					<div id="ev-signin-links-loading">Loading&hellip;</div>
					<div id="ev-signin-links-empty" style="display:none">No active links.</div>
					<table id="ev-signin-links-table" style="display:none">
						<thead><tr><th>Expires</th><th>Cr.</th><th></th></tr></thead>
						<tbody id="ev-signin-links-tbody"></tbody>
					</table>
				</div>
			</div>
		</div>
	</div>
</div>
<!-- Sign-in QR Code Modal -->
<div id="ev-qr-overlay" onclick="if(event.target===this)evCloseQrModal()">
	<div class="ev-qr-box">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px">
			<span style="font-weight:700;font-size:15px;color:#2d3748"><i class="fas fa-qrcode" style="margin-right:8px;color:#2b6cb0"></i>Scan to Sign In</span>
			<button type="button" onclick="evCloseQrModal()" style="background:none;border:none;font-size:20px;cursor:pointer;color:#a0aec0;line-height:1">&times;</button>
		</div>
		<img id="ev-qr-img" src="" alt="QR Code">
		<div id="ev-qr-expires" style="font-size:11px;color:#718096;margin-bottom:14px"></div>
		<a id="ev-qr-download" href="" download="signin-qr.png" class="ev-icon-btn" style="display:inline-flex;align-items:center;gap:6px;text-decoration:none;font-size:13px">
			<i class="fas fa-download"></i> Download PNG
		</a>
	</div>
</div>
<?php endif; ?>
<script>
function evCloseQrModal() { if (typeof orkCloseQrModal === 'function') orkCloseQrModal('ev-qr-overlay'); }
</script>
<script>
var EvConfig = {
	uir:        '<?= UIR ?>',
	httpService:'<?= HTTP_SERVICE ?>',
	canManage:         <?= !empty($canManage) ? 'true' : 'false' ?>,
	canManageSchedule: <?= !empty($canManageSchedule) ? 'true' : 'false' ?>,
	evGridTodayKey: '<?= $evGridTodayKey ?? date('Ymd') ?>',
	canManageFeast:    <?= !empty($canManageFeast) ? 'true' : 'false' ?>,
	canManageStaff:    <?= !empty($canManageStaff) ? 'true' : 'false' ?>,
	canManageAttendance: <?= !empty($canManageAttendance) ? 'true' : 'false' ?>,
	checkinOpen:       <?= !empty($checkinOpen) ? 'true' : 'false' ?>,
	kingdomId:  <?= $kingdomId ?>,
	eventId:    <?= $eventId ?>,
	detailId:   <?= $detailId ?>,
	eventName:  <?= json_encode($info['Name'] ?? 'Event') ?>,
	eventDate:  <?= json_encode($eventStart ? date('Y-m-d', strtotime($eventStart)) : '') ?>,
	eventStart: '<?= $eventStart ? date('Y-m-d\TH:i', strtotime($eventStart)) : '' ?>',
	eventEnd:   '<?= $eventEnd   ? date('Y-m-d\TH:i', strtotime($eventEnd))   : '' ?>',
	staffList:  <?= json_encode(array_map(function($s) { return ['MundaneId' => (int)$s['MundaneId'], 'Persona' => $s['Persona']]; }, $StaffList ?? [])) ?>,
	hasFees:    true,
	fees:       <?= json_encode(array_map(function($f) { return ['AdmissionType' => $f['AdmissionType'], 'Cost' => (float)$f['Cost']]; }, $eventFees)) ?>,
	hasLinks:   true,
	links:      <?= json_encode(array_map(function($l) { return ['Title' => $l['Title'], 'Url' => $l['Url'], 'Icon' => $l['Icon']]; }, $ExternalLinks ?? [])) ?>,
	linksListId:'ev-links-list',
};
</script>
<?php if ($canManageStaff): ?>
<!-- Staff Modal -->
<div class="ev-modal-overlay" id="ev-staff-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-id-badge" style="margin-right:8px"></i>Add Staff Member</h3>
			<button class="ev-modal-close" type="button" onclick="evCloseStaffModal()">&times;</button>
		</div>
		<div class="ev-modal-body">
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full">
					<label>Role</label>
					<input type="text" id="ev-staff-role" placeholder="Autocrat, Gate Staff, etc..." autocomplete="off" style="width:100%">
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full" style="position:relative">
					<label>Player</label>
					<input type="text" id="ev-staff-player-name" placeholder="Search players..." autocomplete="off" style="width:100%">
					<input type="hidden" id="ev-staff-player-id">
					<div id="ev-staff-ac" class="ev-ac-results" style="display:none"></div>
				</div>
			</div>
			<div class="ev-modal-row" style="margin-top:12px">
				<div class="ev-modal-field">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal">
						<input type="checkbox" id="ev-staff-can-manage" style="width:auto;margin:0">
						Can manage event details
					</label>
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal">
						<input type="checkbox" id="ev-staff-can-attendance" style="width:auto;margin:0">
						Can manage attendance
					</label>
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal">
						<input type="checkbox" id="ev-staff-can-schedule" style="width:auto;margin:0">
						Can manage schedule
					</label>
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal">
						<input type="checkbox" id="ev-staff-can-feast" style="width:auto;margin:0">
						Can manage feast
					</label>
				</div>
			</div>
			<div id="ev-staff-error" class="ev-img-form-error" style="display:none;margin-top:10px"></div>
		</div><!-- /.ev-modal-body -->
		<div class="ev-modal-footer">
			<button type="button" class="ev-modal-btn-cancel" onclick="evCloseStaffModal()">Cancel</button>
			<button type="button" class="ev-modal-btn-save" id="ev-staff-save-btn" onclick="evSubmitStaff()">
				<i class="fas fa-plus" style="margin-right:5px"></i>Add Staff
			</button>
		</div>
	</div>
</div><!-- /.ev-staff-modal -->
<?php endif; ?>

<?php if ($canManage): ?>
<!-- Event Heraldry Upload Modal -->
<div class="ev-img-overlay" id="ev-img-overlay">
	<div class="ev-img-modal">
		<div class="ev-img-modal-header">
			<span class="ev-img-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#2c5282"></i>Update Event Heraldry</span>
			<button class="ev-img-close-btn" id="ev-img-close-btn" aria-label="Close">&times;</button>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-select">
			<label class="ev-upload-area" for="ev-img-file-input">
				<i class="fas fa-cloud-upload-alt ev-upload-icon"></i>
				Click to choose an image
				<small>JPG, GIF, PNG &middot; Max 340&nbsp;KB (larger images auto-resized)</small>
			</label>
			<input type="file" id="ev-img-file-input" accept=".jpg,.jpeg,.gif,.png,image/jpeg,image/gif,image/png" style="display:none;" />
			<div id="ev-img-resize-notice" style="font-size:12px;color:#888;min-height:16px;margin-top:6px;"></div>
			<div class="ev-img-form-error" id="ev-img-error" style="display:none;"></div>
			<div style="text-align:center;margin-top:10px">
				<button class="ev-btn ev-btn-outline" id="ev-img-remove-btn" type="button" style="font-size:12px;padding:4px 14px;border-color:#feb2b2;color:#e53e3e;"><i class="fas fa-trash"></i> Remove Heraldry</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-crop" style="display:none;">
			<p style="margin:0 0 10px;font-size:13px;color:#718096;">Drag inside the crop box to reposition it, or drag the corner handles to resize.</p>
			<div class="ev-crop-wrap"><canvas id="ev-img-canvas"></canvas></div>
			<div class="ev-img-step-actions">
				<button class="ev-btn ev-btn-outline" id="ev-img-back-btn"><i class="fas fa-arrow-left"></i> Choose Different</button>
				<button class="ev-btn ev-btn-white" id="ev-img-upload-btn"><i class="fas fa-upload"></i> Upload</button>
			</div>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-uploading" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-spinner fa-spin" style="font-size:32px;color:#4299e1;"></i>
			<p style="margin-top:12px;color:#718096;">Uploading&hellip;</p>
		</div>
		<div class="ev-img-modal-body" id="ev-img-step-success" style="display:none;text-align:center;padding:40px 20px;">
			<i class="fas fa-check-circle" style="font-size:32px;color:#48bb78;"></i>
			<p style="margin-top:12px;color:#48bb78;font-weight:600;">Updated! Refreshing&hellip;</p>
		</div>
	</div>
</div>
<?php endif; ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<style>
html[data-theme="dark"] #ev-attendance-table_wrapper .dataTables_paginate .paginate_button,
html[data-theme="dark"] #ev-attendance-table_wrapper .dataTables_paginate .paginate_button:hover {
  background-color: #2d3748 !important; background-image: none !important;
  color: #cbd5e0 !important; border-color: #4a5568 !important;
}
html[data-theme="dark"] #ev-attendance-table_wrapper .dataTables_paginate .paginate_button.current,
html[data-theme="dark"] #ev-attendance-table_wrapper .dataTables_paginate .paginate_button.current:hover {
  background-color: #2b6cb0 !important; background-image: none !important;
  color: #fff !important; border-color: #2b6cb0 !important;
}
</style>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<?php if ($canManageSchedule): ?>
<!-- Schedule Modal -->
<div class="ev-modal-overlay" id="ev-schedule-modal">
	<div class="ev-modal">
		<div class="ev-modal-header">
			<h3><i class="fas fa-clock" style="margin-right:8px"></i><span id="ev-sched-modal-title">Add Schedule Item</span></h3>
			<button class="ev-modal-close" type="button" onclick="evCloseScheduleModal()">&times;</button>
		</div>
		<div class="ev-modal-body">
			<div class="ev-modal-row">
				<div class="ev-modal-field">
					<label>Primary Category <span style="cursor:help;color:#a0aec0;font-size:11px;border-bottom:1px dotted #a0aec0" title="The primary category will determine schedule color coding.">(?)</span></label>
					<select id="ev-sched-category" style="width:100%">
						<option value="Administrative">Administrative</option>
						<option value="Tournament">Tournament</option>
						<option value="Battlegame">Battlegame</option>
						<option value="Arts and Sciences">Arts and Sciences</option>
						<option value="Class">Class</option>
						<option value="Feast and Food">Feast and Food</option>
						<option value="Court">Court</option>
						<option value="Meeting">Meeting</option>
						<option value="Other">Other</option>
					</select>
				</div>
				<div class="ev-modal-field">
					<label>Secondary Category <span style="font-size:11px;font-weight:400;color:#a0aec0">(optional)</span></label>
					<select id="ev-sched-secondary-category" style="width:100%">
						<option value="">— None —</option>
						<option value="Administrative">Administrative</option>
						<option value="Tournament">Tournament</option>
						<option value="Battlegame">Battlegame</option>
						<option value="Arts and Sciences">Arts and Sciences</option>
						<option value="Class">Class</option>
						<option value="Feast and Food">Feast and Food</option>
						<option value="Court">Court</option>
						<option value="Meeting">Meeting</option>
						<option value="Other">Other</option>
					</select>
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full">
					<label>Title <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ev-sched-title" placeholder="Evening Feast, Battlegame, etc." autocomplete="off" style="width:100%">
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field">
					<label>Start Time <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ev-sched-start" autocomplete="off" style="width:100%">
				</div>
				<div class="ev-modal-field">
					<label>End Time <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ev-sched-end" autocomplete="off" style="width:100%">
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full">
					<label>Location</label>
					<input type="text" id="ev-sched-location" placeholder="Main field, Feast hall, etc." autocomplete="off" style="width:100%">
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full">
					<label>Item Lead(s)</label>
					<div id="ev-sched-leads-list" style="display:flex;flex-wrap:wrap;gap:6px;min-height:26px;margin-bottom:8px;align-items:center"></div>
					<div style="position:relative">
						<input type="text" id="ev-sched-lead-input" placeholder="Search players to add as lead..." autocomplete="off" style="width:100%">
						<div id="ev-sched-lead-ac" class="kn-ac-results" style="display:none"></div>
					</div>
				</div>
			</div>
			<div class="ev-modal-row" id="ev-sched-staff-quickadd-row" style="display:none">
				<div class="ev-modal-field ev-field-full">
					<button type="button" onclick="evToggleStaffQuickAdd()" style="background:none;border:none;cursor:pointer;color:#4a5568;font-size:12px;padding:0;display:flex;align-items:center;gap:5px;margin-bottom:6px">
						<i id="ev-sched-staff-qa-chevron" class="fas fa-chevron-right" style="font-size:10px;transition:transform .15s"></i>
						<span>Add from Event Staff</span>
					</button>
					<div id="ev-sched-staff-qa-list" style="display:none;border:1px solid #e2e8f0;border-radius:6px;overflow:hidden;max-height:160px;overflow-y:auto"></div>
				</div>
			</div>
			<div class="ev-modal-row">
				<div class="ev-modal-field ev-field-full">
					<label>Description</label>
					<textarea id="ev-sched-description" rows="3" placeholder="Optional details..." style="width:100%;resize:vertical"></textarea>
				</div>
			</div>
			<!-- Meal details panel (shown when category or secondary category is Feast and Food) -->
			<div id="ev-sched-meal-panel" style="display:none;border-top:1px solid #f7d9c4;margin-top:8px;padding-top:10px">
				<div style="font-size:12px;font-weight:700;color:#e65100;text-transform:uppercase;letter-spacing:.05em;margin-bottom:8px"><i class="fas fa-utensils" style="margin-right:5px"></i>Meal Details</div>
				<div class="ev-modal-row">
					<div class="ev-modal-field" style="max-width:160px">
						<label>Cost <span style="font-size:11px;color:#718096;font-weight:400">(optional)</span></label>
						<input type="number" id="ev-sched-meal-cost" min="0" step="0.01" placeholder="0.00" style="width:100%">
					</div>
				</div>
				<div class="ev-modal-row">
					<div class="ev-modal-field ev-field-full">
						<label>Menu <span style="font-size:11px;color:#718096;font-weight:400">(optional)</span></label>
						<textarea id="ev-sched-meal-menu" rows="3" placeholder="List dishes, courses, dietary notes..." style="width:100%;resize:vertical"></textarea>
					</div>
				</div>
				<div class="ev-modal-row">
					<div class="ev-modal-field ev-field-full">
						<label style="margin-bottom:6px;display:block">Dietary <span style="font-size:11px;color:#718096;font-weight:400">(optional)</span></label>
						<div class="ev-meal-cb-group">
							<label><input type="checkbox" class="ev-sched-dietary-cb" value="Vegetarian Option"> Vegetarian Option</label>
							<label><input type="checkbox" class="ev-sched-dietary-cb" value="Vegan Option"> Vegan Option</label>
							<label><input type="checkbox" class="ev-sched-dietary-cb" value="Kosher"> Kosher</label>
							<label><input type="checkbox" class="ev-sched-dietary-cb" value="Halal"> Halal</label>
						</div>
					</div>
				</div>
				<div class="ev-modal-row">
					<div class="ev-modal-field ev-field-full">
						<label style="margin-bottom:6px;display:block">Contains Allergens <span style="font-size:11px;color:#718096;font-weight:400">(optional)</span></label>
						<div class="ev-meal-cb-group">
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Dairy"> Dairy</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Eggs"> Eggs</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Fish"> Fish</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Shellfish"> Shellfish</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Tree Nuts"> Tree Nuts</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Peanuts"> Peanuts</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Wheat"> Wheat</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Soy"> Soy</label>
							<label><input type="checkbox" class="ev-sched-allergen-cb" value="Sesame"> Sesame</label>
						</div>
					</div>
				</div>
			</div><!-- /#ev-sched-meal-panel -->
			<div class="ev-modal-error" id="ev-sched-error" style="display:none"></div>
		</div>
		<div class="ev-modal-footer">
			<button class="ev-btn ev-btn-outline" type="button" onclick="evCloseScheduleModal()" style="margin-right:auto">Close</button>
			<input type="hidden" id="ev-sched-mode" value="add">
			<input type="hidden" id="ev-sched-id" value="">
			<button class="ev-submit-btn ev-sched-save-any ev-sched-save-secondary" type="button" id="ev-sched-save-similar-btn" onclick="evSubmitSchedule('similar')">
				<i class="fas fa-copy"></i> <span>Save and Create Similar</span>
			</button>
			<button class="ev-submit-btn ev-sched-save-any ev-sched-save-secondary" type="button" id="ev-sched-save-new-btn" onclick="evSubmitSchedule('new')">
				<i class="fas fa-plus"></i> <span>Save and Create New</span>
			</button>
			<button class="ev-submit-btn ev-sched-save-any" type="button" id="ev-sched-save-btn" onclick="evSubmitSchedule('close')">
				<i class="fas fa-save"></i> <span id="ev-sched-save-label">Save and Close</span>
			</button>
		</div>
	</div>
</div>
<?php endif; ?>
<script src="<?= HTTP_TEMPLATE ?>revised-frontend/script/revised.js?v=<?= filemtime(__DIR__ . '/script/revised.js') ?>"></script>
<script>
(function() {
    var hash = location.hash.replace('#', '');
    if (hash) {
        var li = document.querySelector('[data-tab="' + hash + '"]');
        if (li && typeof evShowTab === 'function') evShowTab(li, hash);
    }
})();
(function() {
	var _evAttDt = null;
	function initEvAttDt() {
		if (_evAttDt || !$.fn || !$.fn.DataTable) return;
		if (!document.getElementById('ev-attendance-table')) return;
		_evAttDt = $('#ev-attendance-table').DataTable({
			dom: 'lfrtip',
			order: [[0, 'asc']],
			autoWidth: false,
			columnDefs: [
<?php if ($canManageAttendance): ?>
				{ targets: [-1], orderable: false, searchable: false }
<?php endif; ?>
			],
			pageLength: 25
		});
		window._evAttDt = _evAttDt;
	}
	var _origEvShowTab = window.evShowTab;
	window.evShowTab = function(li, tabId) {
		if (typeof _origEvShowTab === 'function') _origEvShowTab(li, tabId);
		if (tabId === 'ev-tab-attendance') {
			setTimeout(function() { initEvAttDt(); }, 0);
		}
		if (tabId === 'ev-tab-rsvp') {
			evPulseRsvpCredits();
		}
	};
	// Init now if the attendance tab is already visible on page load
	$(function() {
		if (document.querySelector('#ev-tab-attendance.ev-tab-visible')) initEvAttDt();
	});
	window.evInitAttDt = initEvAttDt;
})();
(function() {
	window.evPulseRsvpCredits = function() {
		var el = document.getElementById('ev-rsvp-credits');
		if (!el) return;
		var count = 0;
		function pulse() {
			if (count >= 3) return;
			count++;
			el.classList.remove('ev-credits-pulse');
			void el.offsetWidth; // reflow to restart animation
			el.classList.add('ev-credits-pulse');
			el.addEventListener('animationend', function onEnd() {
				el.removeEventListener('animationend', onEnd);
				setTimeout(pulse, 200);
			});
		}
		pulse();
	};
	$(function() {
		if (document.querySelector('#ev-tab-rsvp.ev-tab-visible')) evPulseRsvpCredits();
	});
	// Position the fixed waivered tooltip on mouseenter so it tracks the (?) span
	document.addEventListener('mouseenter', function(e) {
		var wrap = e.target.closest && e.target.closest('.ev-rsvp-th-tooltip');
		if (!wrap) return;
		var tip = wrap.querySelector('.ev-rsvp-th-tip');
		if (!tip) return;
		var r = wrap.getBoundingClientRect();
		tip.style.left = (r.left + r.width / 2) + 'px';
		tip.style.top  = (r.top - 8) + 'px';
	}, true);
})();
<?php if ($canManage && ($CalendarDetailCount ?? 1) > 1): ?>
(function() {
	var form = document.getElementById('ev-edit-form');
	if (!form) return;
	var originalName = <?= json_encode($info['Name'] ?? '') ?>;
	var detailCount  = <?= (int)($CalendarDetailCount ?? 1) ?>;
	form.addEventListener('submit', function(e) {
		var newName = (form.querySelector('[name="EventName"]') || {}).value || '';
		if (newName && newName !== originalName) {
			e.preventDefault();
			pnConfirm({
				title: 'Rename Event?',
				message: 'This event has ' + detailCount + ' scheduled dates. Renaming it will update the name for all ' + detailCount + ' occurrences.',
				confirmText: 'Rename',
				danger: true
			}, function() { form.submit(); });
		}
	});
})();
<?php endif; ?>

function evConfirmAttDelete(e, link) {
	e.preventDefault();
	var url = link.dataset.delUrl;
	if (!url) return;
	pnConfirm({ title: 'Remove Attendance?', message: 'Remove this attendance record? This cannot be undone.', confirmText: 'Remove', danger: true }, function() {
		link.textContent = '…';
		fetch(url, { method: 'POST' })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status === 0) {
					var row = link.closest('tr');
					var mundaneId = row ? row.dataset.mundaneId : null;
					if (row) {
						if (window._evAttDt) { window._evAttDt.row(row).remove().draw(false); }
						else { row.remove(); }
					}
					if (mundaneId) {
						var rsvpBtn = document.querySelector('.ev-checkin-btn[data-mundane="' + mundaneId + '"]');
						if (rsvpBtn) {
							rsvpBtn.classList.remove('ev-checkin-done');
							rsvpBtn.disabled = false;
							var persona = rsvpBtn.dataset.persona || '';
							rsvpBtn.setAttribute('onclick', 'evOpenCheckinModal(' + mundaneId + ', ' + JSON.stringify(persona) + ')');
							rsvpBtn.innerHTML = '<i class="fas fa-user-check"></i> Check In';
						}
					}
					var tabCount = document.querySelector('[data-tab="ev-tab-attendance"] .ev-tab-count');
					if (tabCount) { tabCount.textContent = '(' + Math.max(0, (parseInt(tabCount.textContent.replace(/[^0-9]/g, '')) || 0) - 1) + ')'; }
				} else {
					link.textContent = '×';
					alert(data.error || 'Could not remove attendance.');
				}
			})
			.catch(function() { link.textContent = '×'; alert('Request failed.'); });
	});
}
function evExportCsv(filename, headers, rows) {
	var lines = [headers.map(function(h) { return '"' + h.replace(/"/g,'""') + '"'; }).join(',')];
	rows.forEach(function(row) { lines.push(row.map(function(v) { return '"' + String(v).replace(/"/g,'""') + '"'; }).join(',')); });
	var a = document.createElement('a');
	a.href = URL.createObjectURL(new Blob([lines.join('\n')], { type: 'text/csv' }));
	a.download = filename;
	a.click();
}
function evSyncCredits(val) {
	var n = parseFloat(val);
	if (!(n > 0)) return;
	var modal = document.querySelector('#ev-checkin-form [name="Credits"]');
	var form  = document.getElementById('ev-Credits');
	var rsvp  = document.getElementById('ev-rsvp-credits');
	if (modal && modal !== document.activeElement) modal.value = n;
	if (form  && form  !== document.activeElement) form.value  = n;
	if (rsvp  && rsvp  !== document.activeElement) rsvp.value  = n;
	if (typeof evSaveCredits === 'function') evSaveCredits(n);
}
function evFilterRsvp(q) {
	q = q.toLowerCase();
	document.querySelectorAll('#ev-rsvp-table tbody tr').forEach(function(tr) {
		tr.style.display = tr.textContent.toLowerCase().includes(q) ? '' : 'none';
	});
	var clr = document.getElementById('ev-rsvp-clear');
	if (clr) clr.style.display = q ? '' : 'none';
}
function evClearRsvpSearch() {
	var inp = document.getElementById('ev-rsvp-search');
	if (inp) { inp.value = ''; inp.focus(); }
	evFilterRsvp('');
}
function evPrintSection(contentHtml, title) {
	var w = window.open('', '_blank', 'width=800,height=600');
	w.document.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>' + title + '</title><style>' +
		'body{font-family:Arial,sans-serif;font-size:13px;color:#1a202c;padding:20px}' +
		'h2{margin:0 0 4px;font-size:16px}' +
		'.ev-print-sub{font-size:12px;color:#718096;margin:0 0 14px}' +
		'table{border-collapse:collapse;width:100%}' +
		'th,td{border:1px solid #e2e8f0;padding:6px 10px;text-align:left;font-size:12px}' +
		'th{background:#f7fafc;font-weight:700}' +
		'tr:nth-child(even) td{background:#f7fafc}' +
		'a{color:inherit;text-decoration:none}' +
		'@media print{body{padding:0}}' +
	'</style></head><body>' + contentHtml + '</body></html>');
	w.document.close();
	setTimeout(function() { w.print(); }, 250);
}
function evPrintAttendance() {
	var tbl = document.querySelector('#ev-attendance-table');
	var tblHtml = '<p>No attendance recorded.</p>';
	if (tbl) {
		var clone = tbl.cloneNode(true);
		clone.querySelectorAll('tr').forEach(function(tr) {
			var last = tr.lastElementChild;
			if (last) last.remove();
		});
		tblHtml = clone.outerHTML;
	}
	var sub = EvConfig.eventDate || '';
	var header = '<h2>' + (EvConfig.eventName || 'Event') + ' — Attendance</h2>' + (sub ? '<p class="ev-print-sub">' + sub + '</p>' : '');
	evPrintSection(header + tblHtml, 'Attendance');
}
function evPrintRsvp() {
	var tbl = document.querySelector('#ev-rsvp-table');
	var going = document.querySelector('#ev-tab-rsvp .fa-check-circle')?.parentElement?.textContent?.trim() || '';
	var sub = (EvConfig.eventDate || '') + (going ? '  ·  ' + going : '');
	var header = '<h2>' + (EvConfig.eventName || 'Event') + ' — RSVPs</h2>' + (sub ? '<p class="ev-print-sub">' + sub + '</p>' : '');
	evPrintSection(header + (tbl ? tbl.outerHTML : '<p>No RSVPs.</p>'), 'RSVPs');
}
function evCsvSlug() {
	var name = (EvConfig.eventName || 'event').toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '');
	var date = EvConfig.eventDate || '';
	return (date ? date + '-' : '') + name;
}
function evExportAttendanceCsv() {
	var rows = [];
	document.querySelectorAll('#ev-attendance-table tbody tr').forEach(function(tr) {
		var c = tr.querySelectorAll('td');
		rows.push([c[0]?c[0].textContent.trim():'', c[1]?c[1].textContent.trim():'', c[2]?c[2].textContent.trim():'', c[3]?c[3].textContent.trim():'', c[4]?c[4].textContent.trim():'']);
	});
	evExportCsv(evCsvSlug() + '-attendance.csv', ['Player','Kingdom','Park','Class','Credits'], rows);
}
function evExportRsvpCsv() {
	var rows = [];
	document.querySelectorAll('#ev-rsvp-table tbody tr').forEach(function(tr) {
		if (tr.style.display === 'none') return;
		var c = tr.querySelectorAll('td');
		rows.push([c[0]?c[0].textContent.trim():'', c[1]?c[1].textContent.trim():'']);
	});
	evExportCsv(evCsvSlug() + '-rsvps.csv', ['Player','Status'], rows);
}
function evConfirmDeleteOccurrence(e, form) {
	e.preventDefault();
	pnConfirm({ title: 'Delete Occurrence?', message: 'Delete this event occurrence? This cannot be undone.', confirmText: 'Delete', danger: true }, function() {
		form.submit();
	});
}

// Flatpickr for event edit modal date fields
function fpAddTitle(label, calEl) {
	var title = document.createElement('div');
	title.className = 'ev-fp-title';
	title.textContent = label;
	calEl.insertBefore(title, calEl.firstChild);
}
if (document.getElementById('ev-fp-start')) {
var _fpOpts = {
	enableTime: true,
	dateFormat: 'Y-m-d\\TH:i',
	altInput: true,
	altFormat: 'F j, Y  h:i K',
	minuteIncrement: 10,
	time_24hr: false
};
var _prevStartDate = null;
var _fpStart = flatpickr('#ev-fp-start', Object.assign({}, _fpOpts, {
	onReady: function(sel, str, fp) {
		fpAddTitle('Start Date & Time', fp.calendarContainer);
		_prevStartDate = sel[0] || null;
	},
	onChange: function(sel) {
		if (!sel[0]) return;
		var endDates = _fpEnd.selectedDates;
		if (endDates[0] && _prevStartDate) {
			var offset = endDates[0].getTime() - _prevStartDate.getTime();
			_fpEnd.setDate(new Date(sel[0].getTime() + offset), true);
		} else if (!endDates[0]) {
			_fpEnd.setDate(new Date(sel[0].getTime() + 60 * 60 * 1000), true);
		}
		_prevStartDate = sel[0];
	}
}));
var _fpEnd = flatpickr('#ev-fp-end', Object.assign({}, _fpOpts, {
	onReady: function(sel, str, fp) { fpAddTitle('End Date & Time', fp.calendarContainer); }
}));
}
<?php if ($canManageAttendance): ?>
// ── Edit Attendance Modal ──────────────────────────────────
(function() {
	var lastCredits = 1;
	var overlay = document.getElementById('ev-att-edit-overlay');
	if (!overlay) return;

	window.evOpenAttEdit = function(btn) {
		var tr = btn.closest('tr');
		document.getElementById('ev-att-edit-id').value      = tr.dataset.attId || '';
		document.getElementById('ev-att-edit-date').value    = tr.dataset.attDate || '';
		document.getElementById('ev-att-edit-mundane').value = tr.dataset.mundaneId || '';
		var sel = document.getElementById('ev-att-edit-class');
		sel.value = tr.dataset.attClass || '';
		sel.style.borderColor = '';
		var cred = document.getElementById('ev-att-edit-credits');
		var credCell = tr.querySelector('.ev-credits-cell');
		cred.value = credCell ? credCell.textContent.trim() : lastCredits;
		cred.style.borderColor = '';
		document.getElementById('ev-att-edit-feedback').style.display = 'none';
		document.getElementById('ev-att-edit-save').disabled = false;
		document.getElementById('ev-att-edit-save').textContent = 'Save';
		overlay.classList.add('att-edit-open');
		document.body.style.overflow = 'hidden';
	};

	function closeModal() {
		overlay.classList.remove('att-edit-open');
		document.body.style.overflow = '';
	}

	document.getElementById('ev-att-edit-close').addEventListener('click', closeModal);
	document.getElementById('ev-att-edit-cancel').addEventListener('click', closeModal);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape' && overlay.classList.contains('att-edit-open')) closeModal();
	});

	document.getElementById('ev-att-edit-save').addEventListener('click', function() {
		var sel     = document.getElementById('ev-att-edit-class');
		var credEl  = document.getElementById('ev-att-edit-credits');
		var newClassId = parseInt(sel.value, 10);
		var newCredits = parseFloat(credEl.value);
		if (!newClassId) { sel.style.borderColor = '#ef4444'; return; }
		if (isNaN(newCredits) || newCredits < 0) { credEl.style.borderColor = '#ef4444'; return; }
		var attId     = document.getElementById('ev-att-edit-id').value;
		var date      = document.getElementById('ev-att-edit-date').value;
		var mundaneId = document.getElementById('ev-att-edit-mundane').value;
		var saveBtn   = document.getElementById('ev-att-edit-save');
		saveBtn.disabled = true;
		saveBtn.textContent = '…';
		$.post('<?= UIR ?>AttendanceAjax/attendance/' + attId + '/edit', {
			Date: date, Credits: newCredits, ClassId: newClassId, MundaneId: mundaneId
		}, function(r) {
			if (r.status === 0) {
				var newClassName = sel.options[sel.selectedIndex].text;
				var tr = document.querySelector('#ev-attendance-table tr[data-att-id="' + attId + '"]');
				if (tr) {
					tr.dataset.attClass = newClassId;
					var classCell = tr.querySelector('.ev-class-cell');
					var credCell  = tr.querySelector('.ev-credits-cell');
					if (classCell) classCell.textContent = newClassName;
					if (credCell)  credCell.textContent  = newCredits;
				}
				lastCredits = newCredits;
				closeModal();
			} else {
				saveBtn.disabled = false;
				saveBtn.textContent = 'Save';
				var fb = document.getElementById('ev-att-edit-feedback');
				fb.textContent = r.error || 'Failed to save.';
				fb.style.display = '';
			}
		}, 'json').fail(function() {
			saveBtn.disabled = false;
			saveBtn.textContent = 'Save';
			var fb = document.getElementById('ev-att-edit-feedback');
			fb.textContent = 'Request failed.';
			fb.style.display = '';
		});
	});
})();
<?php endif; ?>


// ── Feast / Schedule integration helpers ─────────────────────────────────────
(function() {
	if (!EvConfig.canManageFeast && !EvConfig.canManageSchedule) return;

	// Show/hide the meal-details panel based on category selects
	function evSchedToggleMealPanel() {
		var cat    = document.getElementById('ev-sched-category');
		var secCat = document.getElementById('ev-sched-secondary-category');
		var panel  = document.getElementById('ev-sched-meal-panel');
		if (!panel) return;
		var isFeast = (cat && cat.value === 'Feast and Food') || (secCat && secCat.value === 'Feast and Food');
		panel.style.display = isFeast ? '' : 'none';
	}

	// Wire category change handlers
	window.addEventListener('DOMContentLoaded', function() {
		var cat    = document.getElementById('ev-sched-category');
		var secCat = document.getElementById('ev-sched-secondary-category');
		if (cat)    cat.addEventListener('change',    evSchedToggleMealPanel);
		if (secCat) secCat.addEventListener('change', evSchedToggleMealPanel);
	});

	// Also wire immediately in case DOM is already ready
	(function tryWire() {
		var cat = document.getElementById('ev-sched-category');
		if (cat) {
			cat.addEventListener('change', evSchedToggleMealPanel);
			var secCat = document.getElementById('ev-sched-secondary-category');
			if (secCat) secCat.addEventListener('change', evSchedToggleMealPanel);
		}
	})();

	// Clear meal panel fields
	function evSchedClearMealFields() {
		var cost = document.getElementById('ev-sched-meal-cost');
		var menu = document.getElementById('ev-sched-meal-menu');
		if (cost) cost.value = '';
		if (menu) menu.value = '';
		document.querySelectorAll('.ev-sched-dietary-cb, .ev-sched-allergen-cb').forEach(function(cb) { cb.checked = false; });
	}

	// Populate meal panel from a meal data object
	function evSchedPopulateMealFields(meal) {
		var cost = document.getElementById('ev-sched-meal-cost');
		var menu = document.getElementById('ev-sched-meal-menu');
		if (cost) cost.value = (meal.Cost !== null && meal.Cost !== undefined) ? meal.Cost : '';
		if (menu) menu.value = meal.Menu || '';
		var dietary   = (meal.Dietary   || '').split(',').map(function(s){return s.trim();});
		var allergens = (meal.Allergens || '').split(',').map(function(s){return s.trim();});
		document.querySelectorAll('.ev-sched-dietary-cb').forEach(function(cb)  { cb.checked = dietary.indexOf(cb.value)   >= 0; });
		document.querySelectorAll('.ev-sched-allergen-cb').forEach(function(cb) { cb.checked = allergens.indexOf(cb.value) >= 0; });
	}

	// Open schedule modal pre-set to Feast and Food category (Add mode)
	window.evOpenFeastScheduleModal = function() {
		if (typeof evOpenScheduleModal === 'function') evOpenScheduleModal();
		// Pre-set category after modal opens
		setTimeout(function() {
			var cat = document.getElementById('ev-sched-category');
			if (cat) { cat.value = 'Feast and Food'; evSchedToggleMealPanel(); }
			evSchedClearMealFields();
		}, 20);
	};

	// Open schedule modal in edit mode from a feast card (no table row available)
	window.evOpenFeastEditModal = function(meal) {
		// Build a synthetic row-like object and call evOpenScheduleModal in edit mode
		var modal = document.getElementById('ev-schedule-modal');
		if (!modal) return;
		var modeEl = document.getElementById('ev-sched-mode');
		var idEl   = document.getElementById('ev-sched-id');
		var titleEl = document.getElementById('ev-sched-title');
		var catEl   = document.getElementById('ev-sched-category');
		var secCatEl = document.getElementById('ev-sched-secondary-category');
		var locEl   = document.getElementById('ev-sched-location');
		var descEl  = document.getElementById('ev-sched-description');
		var startEl = document.getElementById('ev-sched-start');
		var endEl   = document.getElementById('ev-sched-end');
		var errEl   = document.getElementById('ev-sched-error');
		var titleLbl = document.getElementById('ev-sched-modal-title');
		var saveLbl  = document.getElementById('ev-sched-save-label');
		if (!modeEl || !idEl || !titleEl || !catEl || !startEl || !endEl) return;
		modeEl.value  = 'edit';
		idEl.value    = meal.EventScheduleId;
		if (titleLbl) titleLbl.textContent = 'Edit Schedule Item';
		if (saveLbl)  saveLbl.textContent  = 'Save Changes';
		titleEl.value  = meal.Title || '';
		catEl.value    = meal.Category || 'Feast and Food';
		if (secCatEl) secCatEl.value = meal.SecondaryCategory || '';
		if (locEl)  locEl.value  = meal.Location || '';
		if (descEl) descEl.value = meal.Description || '';
		if (errEl)  { errEl.style.display = 'none'; errEl.textContent = ''; }
		// Leads
		try { window.evSchedLeads = meal.Leads || []; } catch(e) { window.evSchedLeads = []; }
		if (typeof evRenderSchedLeads === 'function') evRenderSchedLeads();
		// Collapse staff quick-add
		var qaList = document.getElementById('ev-sched-staff-qa-list');
		var qaChevron = document.getElementById('ev-sched-staff-qa-chevron');
		if (qaList) qaList.style.display = 'none';
		if (qaChevron) qaChevron.style.transform = '';
		if (typeof evRefreshStaffQuickAdd === 'function') evRefreshStaffQuickAdd();
		// Times
		if (EvConfig.eventStart) { startEl.min = EvConfig.eventStart; endEl.min = EvConfig.eventStart; }
		if (EvConfig.eventEnd)   { startEl.max = EvConfig.eventEnd;   endEl.max = EvConfig.eventEnd; }
		if (meal.StartTime) startEl.value = meal.StartTime.replace(' ', 'T').substring(0, 16);
		if (meal.EndTime)   endEl.value   = meal.EndTime.replace(' ', 'T').substring(0, 16);
		// Meal fields
		evSchedToggleMealPanel();
		evSchedPopulateMealFields(meal);
		modal.style.display = 'flex';
		document.body.style.overflow = 'hidden';
		setTimeout(function() { titleEl.focus(); }, 50);
	};

	// Patch evSubmitSchedule to include meal fields on submit.
	// Strategy: temporarily override FormData.prototype.append so that when the
	// original function calls fd.append('Leads', ...) we tack on Menu/Cost/Dietary/
	// Allergens right after. Restored in a finally block so we never leave the
	// prototype mutated even if the inner function throws.
	var _origEvSubmitSchedule = window.evSubmitSchedule;
	window.evSubmitSchedule = function(postAction) {
		var panel = document.getElementById('ev-sched-meal-panel');
		var hasMealPanel = panel && panel.style.display !== 'none';
		var _origAppend = FormData.prototype.append;
		if (hasMealPanel) {
			var cost = document.getElementById('ev-sched-meal-cost');
			var menu = document.getElementById('ev-sched-meal-menu');
			var dietary   = Array.from(document.querySelectorAll('.ev-sched-dietary-cb:checked')).map(function(c){return c.value;}).join(',');
			var allergens = Array.from(document.querySelectorAll('.ev-sched-allergen-cb:checked')).map(function(c){return c.value;}).join(',');
			var extra = {
				Cost:      cost ? cost.value.trim() : '',
				Menu:      menu ? menu.value.trim() : '',
				Dietary:   dietary,
				Allergens: allergens
			};
			var injected = false;
			FormData.prototype.append = function(key, val) {
				_origAppend.call(this, key, val);
				if (!injected && key === 'Leads') {
					injected = true;
					_origAppend.call(this, 'Cost',      extra.Cost);
					_origAppend.call(this, 'Menu',      extra.Menu);
					_origAppend.call(this, 'Dietary',   extra.Dietary);
					_origAppend.call(this, 'Allergens', extra.Allergens);
				}
			};
		}
		try {
			_origEvSubmitSchedule(postAction);
		} finally {
			FormData.prototype.append = _origAppend;
		}
	};

	// Remove a feast card and hit remove_schedule endpoint
	window.evRemoveFeastCard = function(btn, scheduleId) {
		pnConfirm({ title: 'Remove Feast Item?', message: 'Remove this feast item from the event?', confirmText: 'Remove', danger: true }, function() {
			btn.disabled = true;
			var fd = new FormData();
			fd.append('ScheduleId', scheduleId);
			fetch(EvConfig.uir + 'EventAjax/remove_schedule/' + EvConfig.eventId + '/' + EvConfig.detailId, {
				method: 'POST', body: fd,
				headers: { 'X-Requested-With': 'XMLHttpRequest' }
			})
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status !== 0) { btn.disabled = false; return; }
				var card = document.getElementById('ev-meal-card-' + scheduleId);
				if (card) card.remove();
				var tab = document.querySelector('[data-tab="ev-tab-feast"] .ev-tab-count');
				if (tab) tab.textContent = Math.max(0, parseInt(tab.textContent || '0') - 1);
				var list = document.getElementById('ev-meal-list');
				if (list && !list.querySelector('.ev-meal-card')) {
					var empty = document.getElementById('ev-meal-empty');
					if (empty) empty.style.display = '';
				}
				// Also remove the corresponding schedule row if visible
				var schedRow = document.getElementById('ev-schedule-row-' + scheduleId);
				if (schedRow) {
					var daySection = schedRow.closest('.ev-sched-day-section');
					schedRow.remove();
					if (daySection) {
						var tbody = daySection.querySelector('tbody');
						if (tbody && tbody.querySelectorAll('tr').length === 0) daySection.remove();
					}
					var schTab = document.querySelector('[data-tab="ev-tab-schedule"] .ev-tab-count');
					if (schTab) schTab.textContent = Math.max(0, parseInt(schTab.textContent || '0') - 1);
				}
			})
			.catch(function() { btn.disabled = false; });
		});
	};

	// Also patch evOpenScheduleModal to reset meal fields on open (add mode)
	var _origEvOpenScheduleModal = window.evOpenScheduleModal;
	window.evOpenScheduleModal = function() {
		if (_origEvOpenScheduleModal) _origEvOpenScheduleModal();
		evSchedClearMealFields();
		evSchedToggleMealPanel();
	};

	// Patch evOpenScheduleEditModal to also populate meal fields from row data-attrs
	var _origEvOpenScheduleEditModal = window.evOpenScheduleEditModal;
	window.evOpenScheduleEditModal = function(scheduleId, btn) {
		if (_origEvOpenScheduleEditModal) _origEvOpenScheduleEditModal(scheduleId, btn);
		var row = btn ? btn.closest('tr') : null;
		if (row) {
			evSchedToggleMealPanel();
			evSchedPopulateMealFields({
				Menu:      row.getAttribute('data-menu')      || '',
				Cost:      row.getAttribute('data-cost')      || null,
				Dietary:   row.getAttribute('data-dietary')   || '',
				Allergens: row.getAttribute('data-allergens') || ''
			});
		} else {
			evSchedClearMealFields();
			evSchedToggleMealPanel();
		}
	};

	// Flatpickr on schedule modal start/end — 5-minute increments, visible up/down carats.
	// Wrappers sync the picker display with values the base open-modal code assigns to .value.
	if (EvConfig.canManageSchedule && typeof flatpickr === 'function' && document.getElementById('ev-sched-start')) {
		var _schedFpOpts = {
			enableTime:      true,
			dateFormat:      'Y-m-d\\TH:i',
			altInput:        true,
			altFormat:       'F j, Y  h:i K',
			minuteIncrement: 5,
			time_24hr:       false,
			allowInput:      false,
			onReady: function(sel, str, fp) { fp.calendarContainer.classList.add('ev-sched-fp'); }
		};
		if (EvConfig.eventStart) _schedFpOpts.minDate = EvConfig.eventStart;
		if (EvConfig.eventEnd)   _schedFpOpts.maxDate = EvConfig.eventEnd;

		var _schedFpEnd = flatpickr('#ev-sched-end', _schedFpOpts);
		var _schedFpStart = flatpickr('#ev-sched-start', Object.assign({}, _schedFpOpts, {
			onChange: function(selectedDates, dateStr, fp) {
				if (!selectedDates[0] || !_schedFpEnd) return;
				// Auto-set end = start + 1hr (matches the sibling DOM change listener in revised.js).
				var t = new Date(selectedDates[0].getTime() + 60 * 60 * 1000);
				_schedFpEnd.setDate(t, false);
			}
		}));

		// Snap a Date up to the next 5-minute mark (e.g. 06:32 → 06:35).
		// Ceiling (not rounding) so the snapped date stays at-or-after minDate.
		function _snap5(d) {
			if (!d) return d;
			var ms = 5 * 60 * 1000;
			return new Date(Math.ceil(d.getTime() / ms) * ms);
		}

		// Sync picker display after the base open-modal functions assign .value directly,
		// and round any initial value to a 5-minute increment so the displayed time stays
		// aligned with the ±5 minute carat stepping.
		function _schedFpSyncFromValue() {
			var s = document.getElementById('ev-sched-start');
			var e = document.getElementById('ev-sched-end');
			if (s && s._flatpickr) {
				s._flatpickr.setDate(s.value || null, false);
				var sd = s._flatpickr.selectedDates[0];
				if (sd) s._flatpickr.setDate(_snap5(sd), false);
			}
			if (e && e._flatpickr) {
				e._flatpickr.setDate(e.value || null, false);
				var ed = e._flatpickr.selectedDates[0];
				if (ed) e._flatpickr.setDate(_snap5(ed), false);
			}
		}
		var _origOpenForFp     = window.evOpenScheduleModal;
		var _origEditOpenForFp = window.evOpenScheduleEditModal;
		window.evOpenScheduleModal = function() {
			if (_origOpenForFp) _origOpenForFp();
			_schedFpSyncFromValue();
		};
		window.evOpenScheduleEditModal = function(id, btn) {
			if (_origEditOpenForFp) _origEditOpenForFp(id, btn);
			_schedFpSyncFromValue();
		};
	}
})();
</script>
<style>
/* ==================================================
   Schedule Grid View (ev-grid-*)
   ================================================== */
.ev-grid-view-toolbar {
	display: flex;
	justify-content: flex-end;
	align-items: center;
	gap: 10px;
	margin-bottom: 14px;
}
.ev-grid-view-toggle {
	display: inline-flex; background: #fff; border: 1px solid #e2e8f0;
	border-radius: 999px; padding: 3px; gap: 2px;
	box-shadow: 0 1px 2px rgba(0,0,0,0.04);
}
.ev-grid-view-btn {
	background: transparent; border: none; padding: 6px 14px;
	font-size: 12px; font-weight: 600; color: #718096; cursor: pointer;
	border-radius: 999px; display: inline-flex; align-items: center; gap: 6px;
	transition: background .15s, color .15s;
}
.ev-grid-view-btn:hover { color: #2d3748; }
.ev-grid-view-btn.ev-grid-view-active {
	background: #2d3748; color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.15);
}
.ev-grid-view-btn i { font-size: 11px; }

/* Dark mode toggle */
html[data-theme="dark"] .ev-grid-view-toggle {
	background: var(--ork-bg-secondary);
	border-color: var(--ork-border);
	box-shadow: 0 1px 2px rgba(0,0,0,0.35);
}
html[data-theme="dark"] .ev-grid-view-btn { color: var(--ork-text-muted); }
html[data-theme="dark"] .ev-grid-view-btn:hover { color: var(--ork-text); }
html[data-theme="dark"] .ev-grid-view-btn.ev-grid-view-active {
	background: #4299e1; color: #fff; box-shadow: 0 1px 3px rgba(0,0,0,0.45);
}

.ev-grid-day { margin-bottom:24px; background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; box-shadow:0 1px 3px rgba(0,0,0,0.04); }
.ev-grid-day-header {
	padding:10px 14px; font-size:13px; font-weight:700; color:#2d3748;
	background:#f7fafc; border-bottom:1px solid #e2e8f0;
	text-transform:uppercase; letter-spacing:.04em;
}
.ev-grid-scroller { overflow-x:auto; overflow-y:hidden; }
.ev-grid-inner { display:block; min-width:100%; }

.ev-grid-header-row {
	display:grid;
	grid-template-columns: 80px repeat(var(--ev-grid-cols, 1), minmax(160px, 1fr));
	z-index:2;
	background:#fff;
}
.ev-grid-time-col-head { border-bottom:2px solid #e2e8f0; background:#fff; }
.ev-grid-cat-head {
	padding:8px 10px; font-size:11px; font-weight:700;
	text-transform:uppercase; letter-spacing:.05em;
	border-bottom:2px solid #cbd5e0; border-left:1px solid #edf2f7;
	display:flex; align-items:center; gap:6px; white-space:nowrap; overflow:hidden;
}
.ev-grid-cat-label { flex:1; overflow:hidden; text-overflow:ellipsis; }
.ev-grid-cat-count {
	background:rgba(255,255,255,0.7); border:1px solid rgba(0,0,0,0.08);
	color:#4a5568; font-size:10px; padding:1px 6px; border-radius:10px;
}
.ev-grid-body-row {
	display:grid;
	grid-template-columns: 80px repeat(var(--ev-grid-cols, 1), minmax(160px, 1fr));
	position:relative;
}
.ev-grid-time-col { position:relative; background:#fafbfc; border-right:1px solid #e2e8f0; }
.ev-grid-time-slot {
	position:absolute; left:0; right:0; height:28px;
	border-top:1px solid transparent;
	pointer-events:none;
}
.ev-grid-time-hour { border-top-color:#e2e8f0; }
.ev-grid-time-half { border-top-color:#f1f3f5; }
.ev-grid-time-lbl {
	position:absolute; top:-7px; right:8px;
	font-size:10px; font-weight:700; color:#718096;
	font-variant:small-caps; letter-spacing:.03em;
	background:#fafbfc; padding:0 4px;
}
.ev-grid-col { position:relative; border-left:1px solid #edf2f7; }
.ev-grid-block {
	position:absolute;
	border:1px solid rgba(0,0,0,0.06); border-left:4px solid #999;
	border-radius:4px; padding:5px 7px 4px; overflow:hidden;
	box-shadow:0 1px 2px rgba(0,0,0,0.08);
	cursor:pointer; transition: transform .1s, box-shadow .15s;
	font-size:11px; line-height:1.3; color:#2d3748;
}
.ev-grid-block:hover { box-shadow:0 3px 8px rgba(0,0,0,0.16); transform:translateY(-1px); z-index:3; }
.ev-grid-block-title {
	font-weight:700; font-size:11px; color:#1a202c;
	white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ev-grid-block-time {
	font-size:10px; color:#718096; margin-top:1px;
	white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ev-grid-block-loc {
	font-size:10px; color:#4a5568; margin-top:2px;
	white-space:nowrap; overflow:hidden; text-overflow:ellipsis;
}
.ev-grid-block-loc i { font-size:9px; margin-right:2px; color:#a0aec0; }
.ev-grid-block-leads { margin-top:3px; display:flex; flex-wrap:wrap; gap:3px; }
.ev-grid-lead-chip {
	background:rgba(255,255,255,0.65); border:1px solid rgba(0,0,0,0.07);
	color:#4a5568; font-size:9px; padding:1px 5px; border-radius:8px;
	white-space:nowrap;
}
.ev-grid-block-compact .ev-grid-block-time,
.ev-grid-block-compact .ev-grid-block-loc,
.ev-grid-block-compact .ev-grid-block-leads { display:none; }
.ev-grid-block-compact .ev-grid-block-title { font-size:10px; line-height:1.25; }
.ev-grid-block:focus-visible { outline:2px solid #4299e1; outline-offset:1px; }
.ev-grid-popover-flip:before { top:auto; bottom:-6px; border-bottom:none; border-top:6px solid #fff; }

.ev-grid-now-line {
	position:absolute; left:80px; right:0; height:2px;
	background:#e53e3e; z-index:4; pointer-events:none;
	box-shadow:0 0 4px rgba(229,62,62,0.5);
}
.ev-grid-now-dot {
	position:absolute; width:10px; height:10px; border-radius:50%;
	background:#e53e3e; left:66px; margin-top:-5px; z-index:5;
	box-shadow:0 0 0 2px #fafbfc;
}

/* Popover for non-editors */
.ev-grid-popover {
	position:absolute; z-index:9500; background:#fff;
	border:1px solid #e2e8f0; border-radius:8px;
	box-shadow:0 8px 24px rgba(0,0,0,0.18);
	padding:12px 14px; min-width:220px; max-width:320px;
	font-size:12px; color:#2d3748;
}
.ev-grid-popover h5 { margin:0 0 6px; font-size:13px; color:#1a202c; font-weight:700;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none; }
.ev-grid-popover .ev-gp-row { margin-top:4px; color:#4a5568; font-size:11px; }
.ev-grid-popover .ev-gp-row i { width:12px; color:#a0aec0; margin-right:4px; }

@media (max-width: 700px) {
	#ev-schedule-grid-container { display:none !important; }
	#ev-schedule-container      { display:block !important; }
	.ev-grid-view-toolbar       { display:none !important; }
}
</style>
<script>
(function() {
	var listEl = document.getElementById('ev-schedule-container');
	var gridEl = document.getElementById('ev-schedule-grid-container');
	if (!listEl || !gridEl) return;

	var STORAGE_KEY = 'ev-sched-view-mode';
	var btns = document.querySelectorAll('.ev-grid-view-btn');
	var isMobile = function() { return window.matchMedia('(max-width: 700px)').matches; };

	function applyMode(mode, opts) {
		opts = opts || {};
		if (isMobile()) mode = 'list';
		if (mode !== 'grid') mode = 'list';
		if (mode === 'grid') {
			listEl.style.display = 'none';
			gridEl.style.display = '';
			updateNowLines();
		} else {
			listEl.style.display = '';
			gridEl.style.display = 'none';
		}
		btns.forEach(function(b) {
			var active = b.getAttribute('data-ev-view') === mode;
			b.classList.toggle('ev-grid-view-active', active);
			b.setAttribute('aria-pressed', active ? 'true' : 'false');
		});
		if (opts.persist === true) {
			try { localStorage.setItem(STORAGE_KEY, mode); } catch(e) {}
		}
	}

	btns.forEach(function(b) {
		b.addEventListener('click', function() { applyMode(b.getAttribute('data-ev-view'), {persist:true}); });
	});

	var initial = 'list';
	try { initial = localStorage.getItem(STORAGE_KEY) || 'list'; } catch(e) {}
	applyMode(initial, {persist:false});
	window.addEventListener('resize', function() {
		// Re-evaluate when crossing mobile breakpoint
		applyMode(localStorage.getItem(STORAGE_KEY) || 'list', {persist:false});
	});

	// "Now" indicator
	function updateNowLines() {
		var todayKey = (window.EvConfig && EvConfig.evGridTodayKey) || '';
		var nowSec = Math.floor(Date.now() / 1000);
		document.querySelectorAll('.ev-grid-day').forEach(function(day) {
			var dk    = day.getAttribute('data-day-key');
			var gs    = parseInt(day.getAttribute('data-grid-start'), 10);
			var ge    = parseInt(day.getAttribute('data-grid-end'), 10);
			var line  = day.querySelector('.ev-grid-now-line');
			var dot   = day.querySelector('.ev-grid-now-dot');
			if (!line || !dot) return;
			if (dk !== todayKey || nowSec < gs || nowSec > ge) {
				line.style.display = 'none';
				dot.style.display  = 'none';
				return;
			}
			var offsetMin = (nowSec - gs) / 60;
			var px = (offsetMin / 30) * 28;
			line.style.top = px + 'px';
			line.style.display = '';
			dot.style.top  = px + 'px';
			dot.style.display = '';
		});
	}
	var evGridNowTimer = null;
	function startNowTimer() {
		if (evGridNowTimer !== null) return;
		if (!document.querySelector('.ev-grid-day .ev-grid-now-line')) return;
		updateNowLines();
		evGridNowTimer = setInterval(updateNowLines, 60000);
	}
	function stopNowTimer() {
		if (evGridNowTimer !== null) { clearInterval(evGridNowTimer); evGridNowTimer = null; }
	}
	updateNowLines();
	startNowTimer();
	document.addEventListener('visibilitychange', function() {
		if (document.hidden) { stopNowTimer(); }
		else { startNowTimer(); }
	});
	window.addEventListener('pagehide', stopNowTimer);

	// Click handler — editors go through existing list row's edit button so the
	// external revised.js closest('tr') lookup + meal-field patch both work.
	// Non-editors get a lightweight popover with details (no alert/confirm).
	var openPopover = null;
	function closePopover() {
		if (openPopover && openPopover.parentNode) openPopover.parentNode.removeChild(openPopover);
		openPopover = null;
	}
	document.addEventListener('click', function(e) {
		if (openPopover && !openPopover.contains(e.target) && !e.target.closest('.ev-grid-block')) {
			closePopover();
		}
	});
	document.addEventListener('keydown', function(e) { if (e.key === 'Escape') closePopover(); });
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Enter' && e.key !== ' ') return;
		var blk = e.target && e.target.closest && e.target.closest('.ev-grid-block');
		if (!blk) return;
		if (e.key === ' ') e.preventDefault();
		var sid = parseInt(blk.getAttribute('data-schedule-id'), 10);
		if (!sid) return;
		window.evGridBlockClick(sid, { stopPropagation: function(){}, currentTarget: blk });
	});
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Enter' && e.key !== ' ') return;
		var blk = e.target && e.target.closest && e.target.closest('.ev-grid-block');
		if (!blk) return;
		if (e.key === ' ') e.preventDefault();
		var sid = parseInt(blk.getAttribute('data-schedule-id'), 10);
		if (!sid) return;
		window.evGridBlockClick(sid, { stopPropagation: function(){}, currentTarget: blk });
	});

	window.evGridBlockClick = function(scheduleId, evt) {
		if (evt) { evt.stopPropagation(); }
		closePopover();
		if (window.EvConfig && EvConfig.canManageSchedule) {
			var row = document.getElementById('ev-schedule-row-' + scheduleId);
			if (!row) return;
			var editBtn = row.querySelector('.ev-edit-link');
			if (editBtn) editBtn.click();
			return;
		}
		// Non-editor popover
		var row = document.getElementById('ev-schedule-row-' + scheduleId);
		if (!row) return;
		var title    = row.getAttribute('data-title')       || '';
		var start    = row.getAttribute('data-start')       || '';
		var end      = row.getAttribute('data-end')         || '';
		var loc      = row.getAttribute('data-location')    || '';
		var desc     = row.getAttribute('data-description') || '';
		function fmt(s) { if (!s) return ''; var d = new Date(s); if (isNaN(d.getTime())) return s; return d.toLocaleTimeString([], {hour:'numeric', minute:'2-digit'}); }
		var timeStr = (start || end) ? (fmt(start) + (end ? ' – ' + fmt(end) : '')) : '';

		var pop = document.createElement('div');
		pop.className = 'ev-grid-popover';
		var safe = function(s) { var d = document.createElement('div'); d.textContent = s; return d.innerHTML; };
		var html = '<h5>' + safe(title) + '</h5>';
		if (timeStr) html += '<div class="ev-gp-row"><i class="fas fa-clock"></i>' + safe(timeStr) + '</div>';
		if (loc)     html += '<div class="ev-gp-row"><i class="fas fa-map-marker-alt"></i>' + safe(loc) + '</div>';
		if (desc)    html += '<div class="ev-gp-row" style="margin-top:8px;line-height:1.4">' + safe(desc) + '</div>';
		pop.innerHTML = html;
		document.body.appendChild(pop);

		// Position near the clicked block
		var target = evt && evt.currentTarget ? evt.currentTarget : null;
		var r = target ? target.getBoundingClientRect() : { left: 20, top: 60, right: 220, bottom: 100 };
		var pr = pop.getBoundingClientRect();
		var left = r.left + window.scrollX;
		var top  = r.bottom + window.scrollY + 6;
		if (left + pr.width > window.scrollX + document.documentElement.clientWidth - 10) {
			left = window.scrollX + document.documentElement.clientWidth - pr.width - 10;
		}
		if (top + pr.height > window.scrollY + window.innerHeight - 10) {
			top = r.top + window.scrollY - pr.height - 6;
			pop.classList.add('ev-grid-popover-flip');
		}
		pop.style.left = left + 'px';
		pop.style.top  = top  + 'px';
		openPopover = pop;
	};
})();
</script>

