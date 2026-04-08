<?php
/* -----------------------------------------------
   Pre-process template data
   ----------------------------------------------- */
$kid          = (int)($kingdom_id ?? 0);
$kingdomName  = htmlspecialchars($KingdomInfo['KingdomName'] ?? $kingdom_name ?? '');
$entityLabel  = !empty($IsPrinz) ? 'Principality' : 'Kingdom';
$uir          = UIR;

// Heraldry
$hasHeraldry = !empty($kingdom_info['Info']['KingdomInfo']['HasHeraldry']);
$heraldryUrl = $hasHeraldry
	? ($kingdom_info['HeraldryUrl']['Url'] ?? (HTTP_KINGDOM_HERALDRY . '0000.jpg'))
	: HTTP_KINGDOM_HERALDRY . '0000.jpg';

// Trend stats
$_ts = is_array($TrendStats ?? null) ? $TrendStats : [];
function _ka_trend($cur, $prev, $fmt = 'number') {
	$val = $fmt === 'number' ? number_format($cur) : $cur;
	if ($prev == 0) return '<span class="ka-ts-val">' . $val . '</span>';
	$pct = round((($cur - $prev) / $prev) * 100);
	if ($cur > $prev) {
		$arrow = '<span class="ka-ts-trend ka-ts-up"><i class="fas fa-arrow-up"></i> ' . abs($pct) . '%</span>';
	} elseif ($cur < $prev) {
		$arrow = '<span class="ka-ts-trend ka-ts-down"><i class="fas fa-arrow-down"></i> ' . abs($pct) . '%</span>';
	} else {
		$arrow = '<span class="ka-ts-trend ka-ts-flat">&mdash;</span>';
	}
	return '<span class="ka-ts-val">' . $val . '</span>' . $arrow;
}

// Parks count
$parkSummaryList = is_array($park_summary['KingdomParkAveragesSummary'] ?? null) ? $park_summary['KingdomParkAveragesSummary'] : [];
$activeParkCount = $ActiveParkCount ?? count($parkSummaryList);
$activePlayers   = $ActivePlayers ?? 0;
$totalAttendance = $TotalAttendance ?? 0;
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<!-- =============================================
     KA STYLES (ka- prefix)
     ============================================= -->
<style>
/* Hero */
.ka-hero {
	position: relative;
	border-radius: 10px;
	overflow: hidden;
	margin-bottom: 20px;
	margin-top: 3px;
	min-height: 140px;
	background: linear-gradient(135deg, #1a365d 0%, #2d3748 60%, #1a202c 100%);
}
.ka-hero-bg {
	position: absolute;
	top: -10px; left: -10px; right: -10px; bottom: -10px;
	background-size: cover;
	background-position: center;
	opacity: 0.12;
	filter: blur(6px);
}
.ka-hero-content {
	position: relative;
	z-index: 1;
	display: flex;
	align-items: center;
	padding: 28px 32px;
	gap: 22px;
}
.ka-heraldry-frame {
	width: 72px; height: 72px;
	border-radius: 16px;
	overflow: hidden;
	border: 2px solid rgba(255,255,255,0.25);
	flex-shrink: 0;
	background: rgba(255,255,255,0.08);
}
.ka-heraldry-frame img {
	width: 100%; height: 100%;
	object-fit: cover;
	display: block;
	border: none; padding: 0; margin: 0;
	max-width: none;
}
.ka-hero-info { flex: 1; min-width: 0; }
.ka-hero-title {
	font-size: 24px; font-weight: 700; color: #fff; margin: 0 0 4px;
	background: transparent; border: none; padding: 0; border-radius: 0;
	text-shadow: 0 1px 3px rgba(0,0,0,0.4);
}
.ka-hero-sub { font-size: 13px; color: rgba(255,255,255,0.6); margin-bottom: 10px; }
.ka-hero-badges { display: flex; flex-wrap: wrap; gap: 6px; }
.ka-hero-badge {
	display: inline-flex; align-items: center; gap: 5px;
	background: rgba(255,255,255,0.12); border: 1px solid rgba(255,255,255,0.22);
	color: rgba(255,255,255,0.85); font-size: 11px; font-weight: 600;
	letter-spacing: 0.05em; text-transform: uppercase; border-radius: 20px; padding: 3px 10px;
}
.ka-hero-stats {
	display: flex; gap: 20px; flex-shrink: 0; align-items: center;
}
.ka-hero-stat { text-align: center; }
.ka-hero-stat-val {
	display: block; font-size: 28px; font-weight: 700; color: #fff; line-height: 1;
}
.ka-hero-stat-lbl {
	display: block; font-size: 11px; color: rgba(255,255,255,0.55); text-transform: uppercase;
	letter-spacing: 0.05em; margin-top: 3px;
}
.ka-hero-stat-div { width: 1px; height: 40px; background: rgba(255,255,255,0.2); }

/* Trend stats row — reuses cp-ts pattern with ka- prefix */
.ka-ts-row { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
.ka-ts-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 18px; display: flex; align-items: flex-start; gap: 14px; }
.ka-ts-icon { width: 38px; height: 38px; border-radius: 8px; background: #ebf4ff; color: #3182ce; display: flex; align-items: center; justify-content: center; font-size: 16px; flex-shrink: 0; }
.ka-ts-body { min-width: 0; }
.ka-ts-num { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.ka-ts-val { font-size: 22px; font-weight: 700; color: #1a202c; line-height: 1; }
.ka-ts-trend { font-size: 12px; font-weight: 600; padding: 2px 6px; border-radius: 4px; white-space: nowrap; }
.ka-ts-up   { background: #f0fff4; color: #276749; }
.ka-ts-down { background: #fff5f5; color: #c53030; }
.ka-ts-flat { color: #a0aec0; background: #f7fafc; }
.ka-ts-lbl { font-size: 12px; font-weight: 600; color: #4a5568; text-transform: uppercase; letter-spacing: 0.04em; margin-top: 5px; }
.ka-ts-sub { font-size: 11px; color: #a0aec0; margin-top: 2px; }
@media (max-width: 900px) { .ka-ts-row { grid-template-columns: repeat(2, 1fr); } }
@media (max-width: 500px) { .ka-ts-row { grid-template-columns: 1fr; } }

/* Layout */
.ka-layout { display: grid; grid-template-columns: 1fr 300px; gap: 24px; align-items: start; }
@media (max-width: 900px) { .ka-layout { grid-template-columns: 1fr; } }

/* Sections grid — two columns of sections, single column on narrow screens */
.ka-sections-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px 16px; }
@media (max-width: 700px) { .ka-sections-grid { grid-template-columns: 1fr; } }
.ka-section-title {
	font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.07em;
	color: #718096; margin-bottom: 10px; display: flex; align-items: center; gap: 8px;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.ka-section-title::after { content: ''; flex: 1; height: 1px; background: #e2e8f0; }
/* Tiles within a section — flex-wrap, max 3 per row */
.ka-action-tiles { display: flex; flex-wrap: wrap; gap: 10px; }
.ka-action-tiles > .ka-action-card { flex: 1 1 calc(50% - 5px); min-width: 130px; max-width: 100%; }
@media (min-width: 701px) { .ka-action-tiles > .ka-action-card { flex: 1 1 calc(33.333% - 7px); } }
.ka-action-card {
	display: flex; flex-direction: column; align-items: flex-start;
	padding: 14px 16px; background: #fff; border: 1.5px solid #e2e8f0;
	border-radius: 8px; text-decoration: none; color: inherit;
	transition: border-color 0.15s, box-shadow 0.15s, background 0.15s;
	cursor: pointer; font-family: inherit;
}
.ka-action-card:hover {
	border-color: #4299e1; box-shadow: 0 2px 8px rgba(66,153,225,0.15); background: #ebf8ff;
	text-decoration: none; color: inherit;
}
.ka-action-icon {
	width: 32px; height: 32px; border-radius: 8px; display: flex; align-items: center;
	justify-content: center; font-size: 15px; margin-bottom: 8px; flex-shrink: 0;
}
.ka-action-icon-blue   { background: #ebf8ff; color: #3182ce; }
.ka-action-icon-green  { background: #f0fff4; color: #276749; }
.ka-action-icon-orange { background: #fffaf0; color: #c05621; }
.ka-action-icon-purple { background: #faf5ff; color: #6b46c1; }
.ka-action-icon-red    { background: #fff5f5; color: #c53030; }
.ka-action-icon-gray   { background: #f7fafc; color: #4a5568; }
.ka-action-label { font-size: 13px; font-weight: 600; color: #2d3748; line-height: 1.3; width: 100%; text-align: left; }
.ka-action-desc { font-size: 11px; color: #718096; margin-top: 3px; line-height: 1.4; width: 100%; text-align: left; }

/* Sidebar cards */
.ka-card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; box-shadow: 0 1px 4px rgba(0,0,0,0.06); overflow: hidden; margin-bottom: 24px; }
.ka-card-header { padding: 14px 20px; border-bottom: 1px solid #e2e8f0; background: #f7fafc; display: flex; align-items: center; justify-content: space-between; }
.ka-card-title {
	font-size: 14px; font-weight: 700; color: #2d3748; display: flex; align-items: center; gap: 8px;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0;
}
.ka-report-list { list-style: none; padding: 0; margin: 0; }
.ka-report-list li { border-bottom: 1px solid #edf2f7; }
.ka-report-list li:last-child { border-bottom: none; }
.ka-report-list a {
	display: flex; align-items: center; gap: 10px; padding: 10px 18px;
	text-decoration: none; color: #2d3748; font-size: 13px; font-weight: 500;
	transition: background 0.12s;
}
.ka-report-list a:hover { background: #ebf8ff; color: #2b6cb0; text-decoration: none; }
.ka-report-list a i { width: 18px; text-align: center; color: #a0aec0; font-size: 13px; flex-shrink: 0; }
.ka-report-list a span { flex: 1; }
.ka-report-list-desc {
	display: block; font-size: 11px; color: #a0aec0; font-weight: 400; margin-top: 2px;
}
.ka-report-section-lbl {
	font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.06em;
	color: #a0aec0; padding: 8px 18px 4px; margin: 0;
	background: transparent; border: none; border-radius: 0; text-shadow: none;
}

/* Modal overlay */
.ka-overlay {
	display: none; position: fixed; inset: 0; z-index: 2000;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
	padding: 16px;
	overflow: hidden;
}
.ka-overlay.ka-open { display: flex; }
.ka-modal-box {
	background: #fff; border-radius: 10px; width: 520px; max-width: 100%;
	max-height: 80vh; height: auto; display: flex; flex-direction: column;
	box-shadow: 0 8px 32px rgba(0,0,0,0.22); overflow: hidden;
}
.ka-modal-header {
	padding: 16px 20px; border-bottom: 1px solid #e2e8f0;
	display: flex; align-items: center; justify-content: space-between; flex-shrink: 0;
}
.ka-modal-title {
	font-size: 16px; font-weight: 700; color: #1a202c; margin: 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}
.ka-modal-close { background: none; border: none; font-size: 20px; cursor: pointer; color: #a0aec0; line-height: 1; padding: 2px 6px; }
.ka-modal-close:hover { color: #2d3748; }
.ka-modal-body { padding: 20px; overflow-y: auto; flex: 1 1 auto; min-height: 0; }
.ka-modal-footer { padding: 14px 20px; border-top: 1px solid #e2e8f0; display: flex; align-items: center; justify-content: flex-end; gap: 10px; flex-shrink: 0; background: #f7fafc; border-radius: 0 0 10px 10px; }

/* Mobile: full-screen modals */
@media (max-width: 600px) {
	.ka-overlay { padding: 0; }
	.ka-modal-box { width: 100% !important; max-width: 100%; max-height: 100vh; border-radius: 0; }
	.ka-modal-footer { border-radius: 0; position: sticky; bottom: 0; }
}

/* Field styles */
.ka-field { margin-bottom: 14px; }
.ka-field label { display: block; font-size: 12px; font-weight: 600; color: #4a5568; margin-bottom: 5px; }
.ka-field input[type=text], .ka-field input[type=url], .ka-field input[type=email], .ka-field input[type=password],
.ka-field input[type=number], .ka-field select, .ka-field textarea {
	width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 6px;
	font-size: 13px; color: #2d3748; background: #fff; box-sizing: border-box;
}
.ka-field input:focus, .ka-field select:focus, .ka-field textarea:focus {
	outline: none; border-color: #90cdf4; box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
}
.ka-field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
@media (max-width: 480px) { .ka-field-row { grid-template-columns: 1fr; } }
.ka-field-ac { position: relative; }
.ka-field-ac .kn-ac-results { position: absolute; left: 0; right: 0; z-index: 9999; }
.ka-hint { font-size: 11px; color: #a0aec0; font-weight: 400; }

/* Feedback */
.ka-feedback { padding: 10px 14px; border-radius: 6px; font-size: 13px; font-weight: 500; margin-bottom: 14px; display: none; }
.ka-feedback-ok  { background: #c6f6d5; color: #276749; border: 1px solid #9ae6b4; }
.ka-feedback-err { background: #fed7d7; color: #9b2c2c; border: 1px solid #feb2b2; }

/* Admin tables (reuse kn-admin-table styles inline) */
.ka-admin-table { width: 100%; border-collapse: collapse; font-size: 12px; }
.ka-admin-table th { text-align: left; font-weight: 700; color: #4a5568; padding: 6px 4px; border-bottom: 1.5px solid #e2e8f0; font-size: 11px; text-transform: uppercase; letter-spacing: 0.04em; white-space: nowrap; }
.ka-admin-table td { padding: 5px 4px; border-bottom: 1px solid #f0f0f0; vertical-align: middle; }
.ka-admin-table input[type=text] { width: 100%; padding: 4px 6px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; box-sizing: border-box; }
.ka-admin-table input[type=number] { width: 56px; padding: 4px 4px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; text-align: center; box-sizing: border-box; }
.ka-admin-table select { padding: 4px 4px; border: 1px solid #e2e8f0; border-radius: 4px; font-size: 12px; }
.ka-admin-table .ka-tdel { background: none; border: none; color: #e53e3e; cursor: pointer; font-size: 12px; padding: 4px; opacity: 0.6; }
.ka-admin-table .ka-tdel:hover { opacity: 1; }
.ka-admin-table .ka-tsave { background: none; border: none; color: #3182ce; cursor: pointer; font-size: 12px; padding: 4px; opacity: 0.6; }
.ka-admin-table .ka-tsave:hover { opacity: 1; }
.ka-admin-table-wrap { overflow-x: auto; }
/* Award group headers */
.ka-award-group-hdr td {
	background: #f7fafc; font-size: 11px; font-weight: 700; text-transform: uppercase;
	letter-spacing: 0.05em; color: #4a5568; padding: 8px 6px !important;
	border-bottom: 1.5px solid #e2e8f0; cursor: pointer; user-select: none;
}
.ka-award-group-hdr td:hover { background: #edf2f7; }
.ka-award-group-chev {
	display: inline-block; transition: transform 0.15s; font-size: 10px; margin-right: 6px; color: #a0aec0;
}
.ka-award-group-hdr.ka-collapsed .ka-award-group-chev { transform: rotate(-90deg); }
.ka-award-group-count { font-weight: 400; color: #a0aec0; margin-left: 4px; font-size: 10px; text-transform: none; letter-spacing: 0; }
.ka-admin-park-retired { opacity: 0.45; }

/* Toggle switch */
.ka-toggle { position: relative; display: inline-block; cursor: pointer; }
.ka-toggle input { opacity: 0; position: absolute; }
.ka-toggle-track {
	display: inline-block; width: 34px; height: 18px; background: #cbd5e0;
	border-radius: 9px; position: relative; transition: background 0.2s; vertical-align: middle;
}
.ka-toggle-track::after {
	content: ''; position: absolute; left: 2px; top: 2px; width: 14px; height: 14px;
	background: #fff; border-radius: 50%; transition: transform 0.2s;
}
.ka-toggle input:checked + .ka-toggle-track { background: #48bb78; }
.ka-toggle input:checked + .ka-toggle-track::after { transform: translateX(16px); }

/* Save / action buttons */
.ka-save-btn {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 7px 16px; background: #3182ce; color: #fff; border: none;
	border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer;
	transition: opacity 0.15s;
}
.ka-save-btn:hover:not(:disabled) { opacity: 0.85; }
.ka-save-btn:disabled { opacity: 0.45; cursor: not-allowed; }

/* Ops row */
.ka-ops-row {
	display: flex; align-items: center; justify-content: space-between;
	gap: 16px; padding: 14px 0; border-bottom: 1px solid #edf2f7;
}
.ka-ops-row:last-child { border-bottom: none; }
.ka-ops-info { flex: 1; }
.ka-ops-info strong { font-size: 14px; color: #2d3748; }
.ka-ops-info p { font-size: 12px; color: #718096; margin: 3px 0 0; }
.ka-ops-btn {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 8px 16px; border: 1.5px solid #e2e8f0; border-radius: 6px;
	background: #fff; font-size: 12px; font-weight: 600; color: #4a5568;
	cursor: pointer; white-space: nowrap; transition: border-color 0.15s, background 0.15s;
}
.ka-ops-btn:hover { border-color: #cbd5e0; background: #f7fafc; }
.ka-ops-btn-danger { border-color: #fed7d7; color: #c53030; }
.ka-ops-btn-danger:hover { background: #fff5f5; border-color: #feb2b2; }
@media (max-width: 480px) {
	.ka-ops-row { flex-direction: column; align-items: stretch; }
	.ka-ops-btn { justify-content: center; }
	.ka-award-row-fields { flex-direction: column; }
	.ka-award-row-fields .ka-field { width: 100%; }
	.ka-award-row-fields input[style*="width:64px"] { width: 100% !important; }
}

/* Warning box */
.ka-warning { background: #fffbeb; border: 1px solid #f6e05e; border-radius: 6px; padding: 10px 14px; font-size: 13px; color: #744210; margin-bottom: 14px; display: flex; gap: 10px; align-items: flex-start; }
.ka-warning i { flex-shrink: 0; margin-top: 2px; }

/* Radio group */
.ka-radio-group { display: flex; gap: 16px; }
.ka-radio-group label { display: flex; align-items: center; gap: 6px; font-size: 13px; cursor: pointer; }

/* Alias dropdown */
.ka-alias-picker-wrap { position: relative; }
.ka-alias-trigger {
	display: flex; align-items: center; justify-content: space-between;
	width: 100%; padding: 7px 10px; border: 1.5px solid #e2e8f0; border-radius: 6px;
	font-size: 13px; color: #2d3748; background: #fff; cursor: pointer; text-align: left;
}
.ka-alias-trigger:hover { border-color: #cbd5e0; }
.ka-alias-dropdown {
	position: absolute; top: 100%; left: 0; right: 0; z-index: 99;
	background: #fff; border: 1px solid #e2e8f0; border-radius: 6px;
	box-shadow: 0 4px 16px rgba(0,0,0,0.1); max-height: 250px; overflow-y: auto;
}
.ka-alias-search { width: 100%; padding: 8px 10px; border: none; border-bottom: 1px solid #edf2f7; font-size: 13px; box-sizing: border-box; outline: none; }
.ka-alias-list { max-height: 200px; overflow-y: auto; }
.ka-alias-item { padding: 8px 12px; cursor: pointer; font-size: 13px; }
.ka-alias-item:hover { background: #ebf8ff; }
.ka-alias-empty { padding: 12px; color: #a0aec0; font-size: 12px; text-align: center; }
.ka-alias-hint { color: #a0aec0; font-size: 10px; margin-left: 4px; cursor: help; }

/* Award add buttons row */
.ka-award-add-btns { display: flex; gap: 8px; margin-top: 10px; flex-wrap: wrap; }
.ka-add-btn {
	display: inline-flex; align-items: center; gap: 5px;
	padding: 6px 12px; border: 1.5px dashed #cbd5e0; border-radius: 6px;
	background: transparent; font-size: 12px; font-weight: 600; color: #4a5568;
	cursor: pointer; transition: border-color 0.15s, background 0.15s;
}
.ka-add-btn:hover { border-color: #90cdf4; background: #ebf8ff; color: #2b6cb0; }
.ka-add-award-wrap { margin-top: 14px; padding: 14px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
.ka-add-award-title { font-size: 13px; font-weight: 700; color: #2d3748; margin-bottom: 8px; }
.ka-form-hint { font-size: 12px; color: #718096; margin: 0 0 10px; line-height: 1.5; }
.ka-award-row-fields { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
.ka-field-grow { flex: 1; min-width: 140px; }
.ka-field-center { text-align: center; }

/* Confirm overlay */
.ka-confirm-overlay {
	display: none; position: fixed; inset: 0; z-index: 3000;
	background: rgba(0,0,0,0.5); align-items: center; justify-content: center;
}
.ka-confirm-overlay.ka-open { display: flex; }
.ka-confirm-box { width: 400px; }
</style>

<!-- =============================================
     HERO
     ============================================= -->
<div class="ka-hero">
	<div class="ka-hero-bg" style="background-image: url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
	<div class="ka-hero-content">

		<div class="ka-heraldry-frame">
			<img src="<?= htmlspecialchars($heraldryUrl) ?>" alt="<?= $kingdomName ?>">
		</div>

		<div class="ka-hero-info">
			<h1 class="ka-hero-title"><?= $kingdomName ?></h1>
			<div class="ka-hero-sub"><?= $entityLabel ?> Administration</div>
			<div class="ka-hero-badges">
				<span class="ka-hero-badge"><i class="fas fa-shield-alt"></i> <?= $entityLabel ?></span>
				<?php if (!empty($IsOrkAdmin)): ?>
				<span class="ka-hero-badge"><i class="fas fa-star"></i> ORK Admin</span>
				<?php endif; ?>
			</div>
		</div>

		<div class="ka-hero-stats">
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($activeParkCount) ?></span>
				<span class="ka-hero-stat-lbl">Parks</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($activePlayers) ?></span>
				<span class="ka-hero-stat-lbl">Players (26wk)</span>
			</div>
			<div class="ka-hero-stat-div"></div>
			<div class="ka-hero-stat">
				<span class="ka-hero-stat-val"><?= number_format($totalAttendance) ?></span>
				<span class="ka-hero-stat-lbl">Attendance</span>
			</div>
		</div>

	</div>
</div>

<!-- =============================================
     TREND STATS ROW
     ============================================= -->
<div class="ka-ts-row">
	<div class="ka-ts-card">
		<div class="ka-ts-icon"><i class="fas fa-medal"></i></div>
		<div class="ka-ts-body">
			<div class="ka-ts-num"><?= _ka_trend($_ts['awards_cur'] ?? 0, $_ts['awards_prev'] ?? 0) ?></div>
			<div class="ka-ts-lbl">Awards This Year</div>
			<div class="ka-ts-sub">vs <?= number_format($_ts['awards_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
	<div class="ka-ts-card">
		<div class="ka-ts-icon"><i class="fas fa-calendar-check"></i></div>
		<div class="ka-ts-body">
			<div class="ka-ts-num"><?= _ka_trend($_ts['att_cur'] ?? 0, $_ts['att_prev'] ?? 0) ?></div>
			<div class="ka-ts-lbl">Attendance This Year</div>
			<div class="ka-ts-sub">vs <?= number_format($_ts['att_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
	<div class="ka-ts-card">
		<div class="ka-ts-icon"><i class="fas fa-users"></i></div>
		<div class="ka-ts-body">
			<div class="ka-ts-num"><?= _ka_trend($_ts['players_cur'] ?? 0, $_ts['players_prev'] ?? 0) ?></div>
			<div class="ka-ts-lbl">Active Players (1yr)</div>
			<div class="ka-ts-sub">vs <?= number_format($_ts['players_prev'] ?? 0) ?> in the prior year</div>
		</div>
	</div>
	<div class="ka-ts-card">
		<div class="ka-ts-icon"><i class="fas fa-star"></i></div>
		<div class="ka-ts-body">
			<div class="ka-ts-num"><?= _ka_trend($_ts['recs_cur'] ?? 0, $_ts['recs_prev'] ?? 0) ?></div>
			<div class="ka-ts-lbl">Recommendations This Year</div>
			<div class="ka-ts-sub">vs <?= number_format($_ts['recs_prev'] ?? 0) ?> by this time last year</div>
		</div>
	</div>
</div>

<!-- =============================================
     MAIN LAYOUT
     ============================================= -->
<div class="ka-layout">

	<!-- LEFT COLUMN — Actions -->
	<div class="ka-main">
		<div class="ka-sections-grid">

			<!-- Kingdom Settings -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-crown"></i> Kingdom Settings</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-details-overlay')">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-edit"></i></div>
						<div class="ka-action-label">Edit Details</div>
						<div class="ka-action-desc">Name, abbreviation, description, URL</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-config-overlay')">
						<div class="ka-action-icon ka-action-icon-gray"><i class="fas fa-sliders-h"></i></div>
						<div class="ka-action-label">Configuration</div>
						<div class="ka-action-desc">Recommendation visibility &amp; settings</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-heraldry-overlay')">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-image"></i></div>
						<div class="ka-action-label">Heraldry</div>
						<div class="ka-action-desc">Upload or change kingdom heraldry</div>
					</button>
				</div>
			</div>

			<!-- Parks & Titles -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-map-marker-alt"></i> Parks &amp; Titles</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-parktitles-overlay')">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-flag"></i></div>
						<div class="ka-action-label">Park Titles</div>
						<div class="ka-action-desc">Manage park type titles and requirements</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-editparks-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-map-marker-alt"></i></div>
						<div class="ka-action-label">Edit Parks</div>
						<div class="ka-action-desc">Names, titles, abbreviations, active status</div>
					</button>
					<?php if (!empty($CanAddPark)): ?>
					<a class="ka-action-card" href="<?= UIR ?>Admin/createpark/kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-plus-circle"></i></div>
						<div class="ka-action-label">Create Park</div>
						<div class="ka-action-desc">Add a new park to this <?= strtolower($entityLabel) ?></div>
					</a>
					<?php endif; ?>
					<button class="ka-action-card" onclick="kaOpenModal('ka-claimpark-overlay')">
						<div class="ka-action-icon ka-action-icon-orange"><i class="fas fa-hand-paper"></i></div>
						<div class="ka-action-label">Claim Park</div>
						<div class="ka-action-desc">Request a park transfer to this <?= strtolower($entityLabel) ?></div>
					</button>
				</div>
			</div>

			<!-- Awards -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-award"></i> Awards</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-awards-overlay')">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-medal"></i></div>
						<div class="ka-action-label">Manage Awards</div>
						<div class="ka-action-desc">Award aliases and kingdom-specific awards</div>
					</button>
				</div>
			</div>

			<!-- Players -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-user"></i> Players</div>
				<div class="ka-action-tiles">
					<button class="ka-action-card" onclick="kaOpenModal('ka-createplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-green"><i class="fas fa-user-plus"></i></div>
						<div class="ka-action-label">Create Player</div>
						<div class="ka-action-desc">Register a new player account</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-moveplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-exchange-alt"></i></div>
						<div class="ka-action-label">Move Player</div>
						<div class="ka-action-desc">Transfer a player to a different park</div>
					</button>
					<button class="ka-action-card" onclick="kaOpenModal('ka-mergeplayer-overlay')">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-compress-arrows-alt"></i></div>
						<div class="ka-action-label">Merge Players</div>
						<div class="ka-action-desc">Combine two duplicate player records</div>
					</button>
				</div>
			</div>

			<!-- Operations -->
			<div class="ka-section">
				<div class="ka-section-title"><i class="fas fa-tools"></i> Operations</div>
				<div class="ka-action-tiles">
					<a class="ka-action-card" href="<?= UIR ?>Admin/permissions/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-red"><i class="fas fa-shield-alt"></i></div>
						<div class="ka-action-label">Roles &amp; Permissions</div>
						<div class="ka-action-desc">Manage kingdom access and officers</div>
					</a>
					<a class="ka-action-card" href="<?= UIR ?>Admin/permissionsgrid/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-purple"><i class="fas fa-th"></i></div>
						<div class="ka-action-label">Permissions Grid</div>
						<div class="ka-action-desc">View officer capabilities matrix</div>
					</a>
					<a class="ka-action-card" href="<?= UIR ?>Admin/roles/Kingdom/<?= $kid ?>">
						<div class="ka-action-icon ka-action-icon-blue"><i class="fas fa-user-shield"></i></div>
						<div class="ka-action-label">RBAC Roles</div>
						<div class="ka-action-desc">Manage role assignments and custom roles</div>
					</a>
					<button class="ka-action-card" onclick="kaOpenModal('ka-ops-overlay')">
						<div class="ka-action-icon ka-action-icon-orange"><i class="fas fa-cogs"></i></div>
						<div class="ka-action-label">Operations</div>
						<div class="ka-action-desc">Reset waivers, active status</div>
					</button>
					<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
					<button class="ka-action-card" onclick="kaOpenModal('ka-prinz-overlay')">
						<div class="ka-action-icon ka-action-icon-orange"><i class="fas fa-crown"></i></div>
						<div class="ka-action-label">Principality Status</div>
						<div class="ka-action-desc">Change sponsor or promote to kingdom</div>
					</button>
					<?php endif; ?>
				</div>
			</div>

		</div>
	</div><!-- /.ka-main -->

	<!-- RIGHT COLUMN — Reports & Links -->
	<div class="ka-sidebar">

		<div class="ka-card">
			<div class="ka-card-header">
				<div class="ka-card-title"><i class="fas fa-chart-bar"></i> Reports</div>
			</div>
			<h6 class="ka-report-section-lbl">Activity</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/active/Kingdom&id=<?= $kid ?>"><i class="fas fa-users"></i><span>Active Players<span class="ka-report-list-desc">Players active in the last 6 months</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/park_attendance_explorer"><i class="fas fa-chart-line"></i><span>Park Attendance Explorer<span class="ka-report-list-desc">Interactive attendance analysis</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Peerage</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/knights/Kingdom&id=<?= $kid ?>"><i class="fas fa-chess-king"></i><span>Active Knights<span class="ka-report-list-desc">Currently active knights</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-graduation-cap"></i><span>Active Masters<span class="ka-report-list-desc">Currently active masters</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/knights_list/Kingdom&id=<?= $kid ?>"><i class="fas fa-list"></i><span>Knights List<span class="ka-report-list-desc">All knights past and present</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/masters_list/Kingdom&id=<?= $kid ?>"><i class="fas fa-list-alt"></i><span>Masters List<span class="ka-report-list-desc">All masters past and present</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/knights_and_masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-crown"></i><span>Knights &amp; Masters<span class="ka-report-list-desc">Combined directory</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/class_masters/Kingdom&id=<?= $kid ?>"><i class="fas fa-hat-wizard"></i><span>Class Masters / Paragons<span class="ka-report-list-desc">Top players by class level</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Awards</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/player_awards/Kingdom&id=<?= $kid ?>"><i class="fas fa-medal"></i><span>Kingdom Awards<span class="ka-report-list-desc">All player awards in kingdom</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/custom_awards/Kingdom&id=<?= $kid ?>"><i class="fas fa-award"></i><span>Custom Awards<span class="ka-report-list-desc">Kingdom-specific awards</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/player_award_recommendations/Kingdom&id=<?= $kid ?>"><i class="fas fa-star"></i><span>Award Recommendations<span class="ka-report-list-desc">Submitted recommendations</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/guilds/Kingdom&id=<?= $kid ?>"><i class="fas fa-users-cog"></i><span>Kingdom Guilds<span class="ka-report-list-desc">Guilds registered in kingdom</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Roster</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/roster/Kingdom&id=<?= $kid ?>"><i class="fas fa-address-book"></i><span>Full Roster<span class="ka-report-list-desc">All registered players</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/waivered/Kingdom&id=<?= $kid ?>"><i class="fas fa-file-signature"></i><span>Waivered Players<span class="ka-report-list-desc">Players with active waivers</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/unwaivered/Kingdom&id=<?= $kid ?>"><i class="fas fa-file"></i><span>Unwaivered Players<span class="ka-report-list-desc">Players without waivers</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/inactive/Kingdom&id=<?= $kid ?>"><i class="fas fa-user-slash"></i><span>Inactive Players<span class="ka-report-list-desc">Players not active recently</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kid ?>"><i class="fas fa-user-clock"></i><span>Suspended Players<span class="ka-report-list-desc">Active and past suspensions</span></span></a></li>
			</ul>
			<h6 class="ka-report-section-lbl">Dues &amp; Compliance</h6>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Reports/dues/Kingdom&id=<?= $kid ?>"><i class="fas fa-dollar-sign"></i><span>Dues Paid<span class="ka-report-list-desc">Players with dues on record</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/reeve/Kingdom&id=<?= $kid ?>"><i class="fas fa-gavel"></i><span>Reeve Qualified<span class="ka-report-list-desc">Players qualified to reeve</span></span></a></li>
				<li><a href="<?= UIR ?>Reports/corpora/Kingdom&id=<?= $kid ?>"><i class="fas fa-scroll"></i><span>Corpora Qualified<span class="ka-report-list-desc">Players meeting corpora requirements</span></span></a></li>
			</ul>
		</div>

		<div class="ka-card">
			<div class="ka-card-header">
				<div class="ka-card-title"><i class="fas fa-link"></i> Quick Links</div>
			</div>
			<ul class="ka-report-list">
				<li><a href="<?= UIR ?>Kingdom/profile/<?= $kid ?>"><i class="fas fa-arrow-left"></i><span>Back to Kingdom Profile<span class="ka-report-list-desc"><?= $kingdomName ?></span></span></a></li>
				<li><a href="<?= UIR ?>Reports/suspended/Kingdom&id=<?= $kid ?>"><i class="fas fa-user-clock"></i><span>Suspensions<span class="ka-report-list-desc">Manage player suspensions</span></span></a></li>
				<li><a href="<?= UIR ?>Attendance/kingdom/<?= $kid ?>"><i class="fas fa-clipboard-list"></i><span>Enter Attendance<span class="ka-report-list-desc">Record kingdom attendance</span></span></a></li>
			</ul>
		</div>

	</div><!-- /.ka-sidebar -->

</div><!-- /.ka-layout -->


<!-- =============================================
     MODALS
     ============================================= -->

<!-- ---- Edit Details ---- -->
<div class="ka-overlay" id="ka-details-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-edit" style="margin-right:8px;color:#2b6cb0"></i>Edit Kingdom Details</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-details-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-details-feedback"></div>
			<div class="ka-field">
				<label for="ka-details-name">Kingdom Name</label>
				<input type="text" id="ka-details-name" value="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Name'] ?? '') ?>">
			</div>
			<div class="ka-field">
				<label for="ka-details-abbr">Abbreviation <span class="ka-hint">(letters &amp; numbers only)</span></label>
				<input type="text" id="ka-details-abbr" value="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Abbreviation'] ?? '') ?>" maxlength="8">
				<div id="ka-details-abbr-warn" style="display:none;color:#c05621;font-size:12px;margin-top:4px"></div>
			</div>
			<div class="ka-field">
				<label for="ka-details-description">Description <span class="ka-hint">(optional &mdash; Markdown supported)</span></label>
				<textarea id="ka-details-description" rows="4" style="resize:vertical" data-original="<?= htmlspecialchars($AdminInfo['Description'] ?? '') ?>"><?= htmlspecialchars($AdminInfo['Description'] ?? '') ?></textarea>
			</div>
			<div class="ka-field">
				<label for="ka-details-url">Website URL <span class="ka-hint">(optional)</span></label>
				<input type="url" id="ka-details-url" value="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" data-original="<?= htmlspecialchars($AdminInfo['Url'] ?? '') ?>" placeholder="https://">
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-details-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-details-save"><i class="fas fa-save"></i> Save Details</button>
		</div>
	</div>
</div>

<!-- ---- Configuration ---- -->
<div class="ka-overlay" id="ka-config-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-sliders-h" style="margin-right:8px;color:#2b6cb0"></i>Configuration</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-config-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-config-feedback"></div>
			<div class="ka-field" style="display:flex;align-items:flex-start;justify-content:space-between;gap:16px;padding:10px 0;border-bottom:1px solid #e2e8f0;margin-bottom:12px">
				<div>
					<div style="font-size:13px;font-weight:600;color:#2d3748">Recommendation Visibility</div>
					<div style="font-size:12px;color:#718096;margin-top:3px">When Private, only the monarchy and submitters can see recommendations.</div>
				</div>
				<select id="ka-config-recs-public" style="font-size:13px;border:1.5px solid #e2e8f0;border-radius:6px;padding:5px 8px;flex-shrink:0">
					<option value="1" <?= !empty($AwardRecsPublic) ? 'selected' : '' ?>>Public</option>
					<option value="0" <?= empty($AwardRecsPublic) ? 'selected' : '' ?>>Private (monarchy and submitters only)</option>
				</select>
			</div>
			<div id="ka-config-rows">
				<!-- Built by JS from KaConfig.adminConfig -->
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-config-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-config-save"><i class="fas fa-save"></i> Save Configuration</button>
		</div>
	</div>
</div>

<!-- ---- Heraldry ---- -->
<div class="ka-overlay" id="ka-heraldry-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-image" style="margin-right:8px;color:#6b46c1"></i>Kingdom Heraldry</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-heraldry-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-heraldry-feedback"></div>
			<div style="text-align:center;margin-bottom:16px">
				<img id="ka-heraldry-preview" src="<?= htmlspecialchars($heraldryUrl) ?>" style="max-width:200px;max-height:200px;border-radius:8px;border:1px solid #e2e8f0">
			</div>
			<div class="ka-field">
				<label>Upload New Heraldry <span class="ka-hint">(PNG, JPG, or GIF)</span></label>
				<input type="file" id="ka-heraldry-file" accept="image/png,image/jpeg,image/gif">
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-heraldry-overlay')">Cancel</button>
			<?php if ($hasHeraldry): ?>
			<button class="adm-btn adm-btn-danger" id="ka-heraldry-remove"><i class="fas fa-trash"></i> Remove</button>
			<?php endif; ?>
			<button class="adm-btn adm-btn-primary" id="ka-heraldry-upload" disabled><i class="fas fa-upload"></i> Upload</button>
		</div>
	</div>
</div>

<!-- ---- Park Titles ---- -->
<div class="ka-overlay" id="ka-parktitles-overlay">
	<div class="ka-modal-box" style="width:700px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#2b6cb0"></i>Park Titles</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-parktitles-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-titles-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table" id="ka-titles-table">
					<thead>
						<tr>
							<th>Title</th>
							<th>Class</th>
							<th>Min Att.</th>
							<th>Cutoff</th>
							<th>Period</th>
							<th>Len.</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-titles-tbody">
						<!-- Built by JS -->
					</tbody>
					<tfoot>
						<tr>
							<td><input type="text" data-field="Title" placeholder="New title..."></td>
							<td><input type="number" data-field="Class" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumAttendance" value="0" min="0"></td>
							<td><input type="number" data-field="MinimumCutoff" value="0" min="0"></td>
							<td>
								<select data-field="Period">
									<option value="month">Month</option>
									<option value="week">Week</option>
								</select>
							</td>
							<td><input type="number" data-field="Length" value="1" min="1"></td>
							<td></td>
						</tr>
					</tfoot>
				</table>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-parktitles-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-titles-save"><i class="fas fa-save"></i> Save Park Titles</button>
		</div>
	</div>
</div>

<!-- ---- Edit Parks ---- -->
<div class="ka-overlay" id="ka-editparks-overlay">
	<div class="ka-modal-box" style="width:700px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-map-marker-alt" style="margin-right:8px;color:#276749"></i>Edit Parks</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-editparks-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-parks-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table">
					<thead>
						<tr>
							<th>Park Name</th>
							<th>Title</th>
							<th>Abbr</th>
							<th style="text-align:center">Active</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-parks-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-editparks-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-parks-save"><i class="fas fa-save"></i> Save Parks</button>
		</div>
	</div>
</div>

<!-- ---- Awards ---- -->
<div class="ka-overlay" id="ka-awards-overlay">
	<div class="ka-modal-box" style="width:760px;max-width:calc(100vw - 32px)">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-medal" style="margin-right:8px;color:#6b46c1"></i>Manage Awards</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-awards-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-awards-feedback"></div>
			<div class="ka-admin-table-wrap">
				<table class="ka-admin-table">
					<thead>
						<tr>
							<th>Award Name</th>
							<th>Reign</th>
							<th>Month</th>
							<th>Title?</th>
							<th>Class</th>
							<th></th>
						</tr>
					</thead>
					<tbody id="ka-awards-tbody">
						<!-- Built by JS -->
					</tbody>
				</table>
			</div>
			<!-- Add Award Alias form -->
			<div id="ka-add-award-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Award Alias</div>
				<p class="ka-form-hint">An award alias lets you create additional variations on existing system awards and titles.</p>
				<div class="ka-field">
					<label>System Award</label>
					<div class="ka-alias-picker-wrap">
						<input type="hidden" id="ka-new-award-id">
						<button type="button" class="ka-alias-trigger" id="ka-alias-trigger">
							<span class="ka-alias-label" id="ka-alias-label">Select a system award&hellip;</span>
							<i class="fas fa-chevron-down" style="font-size:11px;opacity:.5"></i>
						</button>
						<div class="ka-alias-dropdown" id="ka-alias-dropdown" style="display:none">
							<input type="text" class="ka-alias-search" id="ka-alias-search" placeholder="Search awards..." autocomplete="off">
							<div class="ka-alias-list" id="ka-alias-list"></div>
						</div>
					</div>
				</div>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Kingdom Name <span class="ka-hint">(your kingdom&rsquo;s name for this award)</span></label>
						<input type="text" id="ka-new-award-name" placeholder="e.g. Order of the Warrior">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-new-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-new-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-new-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-new-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-new-award-save"><i class="fas fa-plus"></i> Add Award Alias</button>
					<button class="adm-btn adm-btn-ghost" id="ka-new-award-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
			<!-- Add Kingdom-Specific Award form -->
			<div id="ka-add-custom-wrap" style="display:none" class="ka-add-award-wrap">
				<div class="ka-add-award-title">Add Kingdom-Specific Award</div>
				<p class="ka-form-hint">A kingdom-specific award allows you to add awards only given out in your kingdom.</p>
				<div class="ka-award-row-fields">
					<div class="ka-field ka-field-grow">
						<label>Award Name</label>
						<input type="text" id="ka-custom-name" placeholder="e.g. Kingdom Spotlight">
					</div>
					<div class="ka-field">
						<label>Reign Limit</label>
						<input type="number" id="ka-custom-reign" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field">
						<label>Month Limit</label>
						<input type="number" id="ka-custom-month" min="0" value="0" style="width:64px">
					</div>
					<div class="ka-field ka-field-center">
						<label>Title?</label>
						<input type="checkbox" id="ka-custom-istitle">
					</div>
					<div class="ka-field">
						<label>Title Class</label>
						<input type="number" id="ka-custom-tclass" min="0" value="0" style="width:64px" disabled>
					</div>
				</div>
				<div style="display:flex;gap:8px;margin-top:10px">
					<button class="ka-save-btn" id="ka-custom-save"><i class="fas fa-plus"></i> Add Award</button>
					<button class="adm-btn adm-btn-ghost" id="ka-custom-cancel" style="font-size:13px">Cancel</button>
				</div>
			</div>
			<div class="ka-award-add-btns" id="ka-award-add-btns">
				<button class="ka-add-btn" id="ka-awards-add-btn"><i class="fas fa-plus"></i> Add Award Alias</button>
				<button class="ka-add-btn" id="ka-custom-add-btn"><i class="fas fa-plus"></i> Add Kingdom-Specific Award</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-awards-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Create Player ---- -->
<div class="ka-overlay" id="ka-createplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-user-plus" style="margin-right:8px;color:#276749"></i>Create Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-createplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-cp-feedback"></div>
			<div class="ka-field">
				<label>Home Park <span style="color:#e53e3e">*</span></label>
				<select id="ka-cp-park">
					<option value="">-- select park --</option>
					<?php foreach ($park_edit_lookup ?? [] as $p): if ($p['Active'] !== 'Active') continue; ?>
					<option value="<?= (int)$p['ParkId'] ?>"><?= htmlspecialchars($p['Name']) ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Persona <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ka-cp-persona" placeholder="In-game name">
				</div>
				<div class="ka-field">
					<label>Email</label>
					<input type="email" id="ka-cp-email" placeholder="email@example.com">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Given Name</label>
					<input type="text" id="ka-cp-given" placeholder="First name">
				</div>
				<div class="ka-field">
					<label>Surname</label>
					<input type="text" id="ka-cp-surname" placeholder="Last name">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Username <span style="color:#e53e3e">*</span></label>
					<input type="text" id="ka-cp-username" autocomplete="new-password" placeholder="min. 4 characters">
				</div>
				<div class="ka-field">
					<label>Password</label>
					<input type="password" id="ka-cp-password" autocomplete="new-password" placeholder="optional">
				</div>
			</div>
			<div class="ka-field-row">
				<div class="ka-field">
					<label>Restricted</label>
					<div class="ka-radio-group">
						<label><input type="radio" name="ka-cp-restricted" value="0" checked> No</label>
						<label><input type="radio" name="ka-cp-restricted" value="1"> Yes</label>
					</div>
				</div>
				<div class="ka-field">
					<label>Waivered</label>
					<div class="ka-radio-group">
						<label><input type="radio" name="ka-cp-waivered" value="0" checked> No</label>
						<label><input type="radio" name="ka-cp-waivered" value="1"> Yes</label>
					</div>
				</div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-createplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-cp-submit"><i class="fas fa-user-plus"></i> Create Player</button>
		</div>
	</div>
</div>

<!-- ---- Move Player ---- -->
<div class="ka-overlay" id="ka-moveplayer-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-people-arrows" style="margin-right:8px;color:#2b6cb0"></i>Move Player</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-moveplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mp-feedback"></div>
			<div class="ka-field ka-field-ac">
				<label>Player <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-player-name" autocomplete="off" placeholder="Search all players...">
				<input type="hidden" id="ka-mp-player-id">
				<div class="kn-ac-results" id="ka-mp-player-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label>New Home Park <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mp-park-name" autocomplete="off" placeholder="Search all parks...">
				<input type="hidden" id="ka-mp-park-id">
				<div class="kn-ac-results" id="ka-mp-park-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-moveplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-mp-submit" disabled><i class="fas fa-arrow-right"></i> Move Player</button>
		</div>
	</div>
</div>

<!-- ---- Merge Players ---- -->
<div class="ka-overlay" id="ka-mergeplayer-overlay">
	<div class="ka-modal-box" style="width:560px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-compress-alt" style="margin-right:8px;color:#c53030"></i>Merge Players</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-mergeplayer-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-mgp-feedback"></div>
			<div class="ka-warning">
				<i class="fas fa-exclamation-triangle"></i>
				<div><strong>This action is permanent and cannot be undone.</strong><br>
				The <em>Remove</em> player's account will be deleted. All their awards, attendance, officer history, and unit memberships transfer to the <em>Keep</em> player.</div>
			</div>
			<div class="ka-field ka-field-ac">
				<label>Player to Keep <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mgp-keep-name" autocomplete="off" placeholder="Search for player to keep...">
				<input type="hidden" id="ka-mgp-keep-id">
				<div class="kn-ac-results" id="ka-mgp-keep-results"></div>
			</div>
			<div class="ka-field ka-field-ac" style="margin-top:12px">
				<label>Player to Remove &mdash; <span style="color:#c53030;font-size:12px"><i class="fas fa-skull-crossbones"></i> permanently deleted</span> <span style="color:#e53e3e">*</span></label>
				<input type="text" id="ka-mgp-remove-name" autocomplete="off" placeholder="Search for player to remove...">
				<input type="hidden" id="ka-mgp-remove-id">
				<div class="kn-ac-results" id="ka-mgp-remove-results"></div>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-mergeplayer-overlay')">Cancel</button>
			<button class="adm-btn adm-btn-danger" id="ka-mgp-submit" disabled><i class="fas fa-compress-alt"></i> Merge Players</button>
		</div>
	</div>
</div>

<!-- ---- Claim Park ---- -->
<div class="ka-overlay" id="ka-claimpark-overlay">
	<div class="ka-modal-box" style="width:460px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-flag" style="margin-right:8px;color:#276749"></i>Claim Park</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-claimpark-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body" style="padding:20px">
			<p style="font-size:14px;color:#2d3748;margin:0 0 10px">To claim a park, please submit documentation, including Althing results if possible, authorizing the move to:</p>
			<p style="font-size:15px;font-weight:600;margin:0 0 14px">
				<a href="mailto:Contracts@amtgard.com?subject=<?= rawurlencode('Park Claim Request - ' . ($AdminInfo['Name'] ?? '')) ?>&body=<?= rawurlencode("Kingdom: " . ($AdminInfo['Name'] ?? '') . "\nPark Name: \nAlthing Results: \nReason for Claim: ") ?>">Contracts@amtgard.com</a>
			</p>
			<p style="font-size:12px;color:#718096;margin:0">Include the park name, your kingdom, and any supporting documentation.</p>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-claimpark-overlay')">Close</button>
		</div>
	</div>
</div>

<!-- ---- Operations ---- -->
<div class="ka-overlay" id="ka-ops-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-cogs" style="margin-right:8px;color:#c05621"></i>Operations</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-ops-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-ops-feedback"></div>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Reset Waivers</strong>
					<p>Clears all waiver records for this <?= strtolower($entityLabel) ?>. This action cannot be undone.</p>
				</div>
				<button class="ka-ops-btn ka-ops-btn-danger" id="ka-ops-reset-waivers">
					<i class="fas fa-eraser"></i> Reset Waivers
				</button>
			</div>
			<?php if (!empty($IsOrkAdmin)):
				$isActive = ($AdminInfo['Active'] ?? 'Active') === 'Active'; ?>
			<div class="ka-ops-row">
				<div class="ka-ops-info">
					<strong>Active Status</strong>
					<p>This <?= strtolower($entityLabel) ?> is currently <strong id="ka-ops-status-label"><?= $isActive ? 'Active' : 'Inactive' ?></strong>.</p>
				</div>
				<button class="ka-ops-btn<?= $isActive ? ' ka-ops-btn-danger' : '' ?>"
					id="ka-ops-status-toggle" data-active="<?= $isActive ? '1' : '0' ?>">
					<?php if ($isActive): ?>
						<i class="fas fa-ban"></i> Mark Inactive
					<?php else: ?>
						<i class="fas fa-check-circle"></i> Restore to Active
					<?php endif; ?>
				</button>
			</div>
			<?php endif; ?>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-ops-overlay')">Done</button>
		</div>
	</div>
</div>

<!-- ---- Principality Status (ORK Admins only) ---- -->
<?php if (!empty($IsOrkAdmin) && !empty($AdminInfo['IsPrincipality'])): ?>
<div class="ka-overlay" id="ka-prinz-overlay">
	<div class="ka-modal-box" style="width:520px">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title"><i class="fas fa-crown" style="margin-right:8px;color:#c05621"></i>Principality Status</h3>
			<button class="ka-modal-close" onclick="kaCloseModal('ka-prinz-overlay')">&times;</button>
		</div>
		<div class="ka-modal-body">
			<div class="ka-feedback" id="ka-prinz-feedback"></div>
			<p style="margin:0 0 12px;font-size:13px;color:#4a5568">
				This is a <strong>Principality</strong> sponsored by
				<strong><?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?></strong>.
			</p>
			<div class="ka-field ka-field-ac">
				<label>Change Sponsor Kingdom</label>
				<input type="text" id="ka-prinz-parent-name" autocomplete="off"
					placeholder="Search kingdoms..."
					value="<?= htmlspecialchars($AdminInfo['ParentKingdomName'] ?? '') ?>">
				<input type="hidden" id="ka-prinz-parent-id"
					value="<?= (int)($AdminInfo['ParentKingdomId'] ?? 0) ?>">
				<div class="kn-ac-results" id="ka-prinz-parent-results"></div>
			</div>
			<div style="display:flex;gap:8px;margin-top:12px;flex-wrap:wrap">
				<button class="ka-save-btn" id="ka-prinz-sponsor-save">
					<i class="fas fa-save"></i> Save Sponsor
				</button>
				<button class="ka-save-btn" id="ka-prinz-promote"
					style="background:#c05621;border-color:#c05621">
					<i class="fas fa-crown"></i> Convert to Kingdom
				</button>
			</div>
		</div>
		<div class="ka-modal-footer">
			<button class="adm-btn adm-btn-ghost" onclick="kaCloseModal('ka-prinz-overlay')">Done</button>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- ---- Confirmation Dialog ---- -->
<div class="ka-confirm-overlay" id="ka-confirm-overlay">
	<div class="ka-modal-box ka-confirm-box">
		<div class="ka-modal-header">
			<h3 class="ka-modal-title" id="ka-confirm-title"><i class="fas fa-exclamation-triangle" style="margin-right:8px;color:#e53e3e"></i>Confirm</h3>
			<button class="ka-modal-close" id="ka-confirm-close">&times;</button>
		</div>
		<div class="ka-modal-body">
			<p id="ka-confirm-message" style="margin:0;font-size:14px;color:#2d3748;line-height:1.6"></p>
		</div>
		<div class="ka-modal-footer" style="justify-content:flex-end;gap:10px">
			<button class="adm-btn adm-btn-ghost" id="ka-confirm-cancel">Cancel</button>
			<button class="adm-btn adm-btn-primary" id="ka-confirm-ok">Confirm</button>
		</div>
	</div>
</div>

<!-- =============================================
     JAVASCRIPT
     ============================================= -->
<script>
var KaConfig = {
	uir:              '<?= UIR ?>',
	kingdomId:        <?= $kid ?>,
	kingdomName:      <?= json_encode($AdminInfo['Name'] ?? '', JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	canManage:        true,
	isOrkAdmin:       <?= !empty($IsOrkAdmin) ? 'true' : 'false' ?>,
	parkTitleOptions: <?= json_encode($ParkTitleId_options ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	parkEditLookup:   <?= json_encode(array_values($park_edit_lookup ?? []), JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminConfig:      <?= json_encode($AdminConfig ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminParkTitles:  <?= json_encode($AdminParkTitles ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminAwards:      <?= json_encode($AdminAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	systemAwards:     <?= json_encode($SystemAwards ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
	adminInfo:        <?= json_encode($AdminInfo ?? [], JSON_HEX_TAG | JSON_HEX_AMP) ?>,
};
</script>
<script>
(function() {
	if (!KaConfig.canManage) return;

	var UIR = KaConfig.uir;
	var BASE_URL = UIR + 'KingdomAjax/kingdom/' + KaConfig.kingdomId + '/';

	function gid(id) { return document.getElementById(id); }

	/* ── Modal helpers ────────────────────────────── */
	function kaOpenModal(id) {
		var el = gid(id);
		if (el) { el.classList.add('ka-open'); document.body.style.overflow = 'hidden'; }
	}
	function kaCloseModal(id) {
		var el = gid(id);
		if (!el) return;
		el.classList.remove('ka-open');
		document.body.style.overflow = '';
		el.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
	}
	window.kaOpenModal  = kaOpenModal;
	window.kaCloseModal = kaCloseModal;

	// Close on backdrop click
	document.querySelectorAll('.ka-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) kaCloseModal(ov.id); });
	});
	// Close on Escape
	document.addEventListener('keydown', function(e) {
		if (e.key === 'Escape') {
			document.querySelectorAll('.ka-overlay.ka-open').forEach(function(ov) { kaCloseModal(ov.id); });
			kaCloseConfirm();
		}
	});

	/* ── Feedback helper ──────────────────────────── */
	function kaFeedback(id, msg, ok) {
		var el = gid(id);
		if (!el) return;
		el.className = 'ka-feedback ' + (ok ? 'ka-feedback-ok' : 'ka-feedback-err');
		el.innerHTML = msg;
		el.style.display = 'block';
		if (ok) { clearTimeout(el._t); el._t = setTimeout(function() { el.style.display = 'none'; }, 5000); }
	}
	function kaClearFeedback(id) {
		var el = gid(id); if (el) { el.style.display = 'none'; el.innerHTML = ''; }
	}

	/* ── Confirm dialog ───────────────────────────── */
	var _kaConfirmCb = null;
	function kaConfirm(message, onConfirm, title) {
		var overlay = gid('ka-confirm-overlay');
		if (!overlay) { if (confirm(message)) onConfirm(); return; }
		gid('ka-confirm-message').textContent = message;
		if (title) gid('ka-confirm-title').childNodes[1].textContent = ' ' + title;
		_kaConfirmCb = onConfirm;
		overlay.classList.add('ka-open');
	}
	function kaCloseConfirm() {
		var overlay = gid('ka-confirm-overlay');
		if (overlay) overlay.classList.remove('ka-open');
		_kaConfirmCb = null;
	}
	gid('ka-confirm-ok') && gid('ka-confirm-ok').addEventListener('click', function() { var cb = _kaConfirmCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-cancel') && gid('ka-confirm-cancel').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-close') && gid('ka-confirm-close').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-overlay') && gid('ka-confirm-overlay').addEventListener('click', function(e) { if (e.target === this) kaCloseConfirm(); });

	/* ── Autocomplete helper (kn-ac-results pattern) ── */
	function kaAc(opts) {
		var input   = gid(opts.inputId);
		var hidden  = gid(opts.hiddenId);
		var results = gid(opts.resultsId);
		if (!input || !results) return;
		var timer = null, minLen = opts.minLen || 2;

		function acClose() { results.classList.remove('kn-ac-open'); results.innerHTML = ''; }
		function acOpen(items) {
			if (!items.length) {
				results.innerHTML = '<div class="kn-ac-item" style="color:#a0aec0;pointer-events:none">No results</div>';
				results.classList.add('kn-ac-open');
				return;
			}
			results.innerHTML = items.map(function(item) {
				return '<div class="kn-ac-item" tabindex="-1" data-id="' + item.id
					+ '" data-name="' + encodeURIComponent(item.label)
					+ (item.extra !== undefined ? '" data-extra="' + encodeURIComponent(item.extra) : '')
					+ '">' + item.html + '</div>';
			}).join('');
			// Fixed positioning for modals
			var modal = input.closest('.ka-overlay');
			if (modal) {
				var rect = input.getBoundingClientRect();
				results.style.position = 'fixed';
				results.style.left = rect.left + 'px';
				results.style.top = rect.bottom + 'px';
				results.style.width = rect.width + 'px';
			}
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
			clearTimeout(timer);
			if (opts.onClear) opts.onClear();
			if (term.length < minLen) { acClose(); return; }
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
		input.addEventListener('keydown', function(e) {
			var items = results.querySelectorAll('.kn-ac-item[data-id]');
			if (!items.length) return;
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (next && next.dataset.id) next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (prev && prev.dataset.id) prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && focused) {
				e.preventDefault(); selectItem(focused);
			} else if (e.key === 'Escape') {
				acClose();
			}
		});
	}

	/* ── Search helpers ───────────────────────────── */
	function kaEsc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	function kaSearchPlayers(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=player&q=' + encodeURIComponent(q) + '&inactive=1')
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.players || []).map(function(p) {
				var inactive = p.active === 0 ? ' <span style="color:#e53e3e;font-size:10px;font-weight:600">inactive</span>' : '';
				return { id: p.id, label: p.name, html: kaEsc(p.name) + ' <span style="color:#a0aec0;font-size:11px">(' + kaEsc(p.abbr) + ' &middot; ' + kaEsc(p.park) + ')</span>' + inactive };
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchParks(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=park&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.parks || []).map(function(p) {
				return { id: p.id, label: p.name, extra: p.kingdom || '', html: kaEsc(p.name) + (p.kingdom ? ' <span style="color:#a0aec0;font-size:11px">[' + kaEsc(p.kingdom) + ']</span>' : '') };
			}));
		}).catch(function(){cb([]);});
	}
	function kaSearchKingdoms(q, cb) {
		fetch(UIR + 'SearchAjax/universal&focus=kingdom&q=' + encodeURIComponent(q))
		.then(function(r){return r.json();}).then(function(d) {
			cb((d.kingdoms || []).map(function(k) {
				return { id: k.id, label: k.name, html: kaEsc(k.name) + ' <span style="color:#a0aec0;font-size:11px">(' + kaEsc(k.abbr) + ')</span>' };
			}));
		}).catch(function(){cb([]);});
	}

	/* ── POST helper ──────────────────────────────── */
	function kaPost(url, data, btn, feedbackId, onSuccess) {
		if (btn) btn.disabled = true;
		var fd = new FormData();
		Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
		fetch(url, { method: 'POST', body: fd })
		.then(function(r) { return r.json(); })
		.then(function(r) {
			if (btn) btn.disabled = false;
			if (r.status === 0) { onSuccess(r); }
			else { kaFeedback(feedbackId, r.error || 'An error occurred.', false); }
		})
		.catch(function() { if (btn) btn.disabled = false; kaFeedback(feedbackId, 'Request failed. Please try again.', false); });
	}

	/* ══════════════════════════════════════════════
	   EDIT DETAILS
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-details-save');
		if (!btn) return;

		// Abbreviation check
		var abbrTimer = null;
		var abbrInp = gid('ka-details-abbr');
		if (abbrInp) {
			abbrInp.addEventListener('input', function() {
				var warn = gid('ka-details-abbr-warn');
				clearTimeout(abbrTimer);
				var abbr = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
				if (!abbr) { if (warn) warn.style.display = 'none'; return; }
				abbrTimer = setTimeout(function() {
					var fd = new FormData();
					fd.append('Abbreviation', abbr);
					fd.append('ExcludeKingdomId', KaConfig.kingdomId);
					fetch(BASE_URL + 'checkabbr', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (!warn) return;
						if (r.taken) { warn.textContent = '"' + abbr + '" is already used by ' + r.name + '.'; warn.style.display = ''; }
						else { warn.style.display = 'none'; }
					});
				}, 400);
			});
		}

		btn.addEventListener('click', function() {
			kaClearFeedback('ka-details-feedback');
			var name = (gid('ka-details-name').value || '').trim();
			var abbr = (gid('ka-details-abbr').value || '').replace(/[^A-Za-z0-9]/g, '');
			if (!name) { kaFeedback('ka-details-feedback', 'Kingdom name is required.', false); return; }
			if (!abbr) { kaFeedback('ka-details-feedback', 'Abbreviation is required.', false); return; }
			var fd = new FormData();
			fd.append('Name', name);
			fd.append('Abbreviation', abbr);
			fd.append('Description', (gid('ka-details-description').value || '').trim());
			fd.append('Url', (gid('ka-details-url').value || '').trim());
			btn.disabled = true;
			fetch(BASE_URL + 'setdetails', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				btn.disabled = false;
				if (r && r.status === 0) {
					kaFeedback('ka-details-feedback', 'Details saved!', true);
					gid('ka-details-name').dataset.original = gid('ka-details-name').value;
					gid('ka-details-abbr').dataset.original = gid('ka-details-abbr').value;
					gid('ka-details-description').dataset.original = gid('ka-details-description').value;
					gid('ka-details-url').dataset.original = gid('ka-details-url').value;
				} else { kaFeedback('ka-details-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
			})
			.catch(function() { btn.disabled = false; kaFeedback('ka-details-feedback', 'Request failed.', false); });
		});
	})();

	/* ══════════════════════════════════════════════
	   CONFIGURATION
	   ══════════════════════════════════════════════ */
	(function() {
		// Build config rows
		var container = gid('ka-config-rows');
		if (container) {
			(KaConfig.adminConfig || []).forEach(function(cfg) {
				var row = document.createElement('div');
				row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid #edf2f7';
				var lbl = document.createElement('div');
				lbl.style.cssText = 'font-size:13px;font-weight:500;color:#2d3748';
				var keyLabels = { 'AwardRecsPublic': 'Award Recommendations Visibility' };
				lbl.textContent = keyLabels[cfg.Key] || cfg.Key;
				row.appendChild(lbl);

				var inputs = document.createElement('div');
				inputs.style.cssText = 'display:flex;gap:6px;align-items:center;flex-shrink:0';
				var val = cfg.Value;

				if (val !== null && typeof val === 'object' && !Array.isArray(val)) {
					Object.keys(val).forEach(function(subKey) {
						var sub = document.createElement('span');
						sub.style.cssText = 'font-size:11px;color:#a0aec0';
						sub.textContent = subKey + ':';
						inputs.appendChild(sub);
						var inp;
						var allowed = cfg.AllowedValues && cfg.AllowedValues[subKey];
						if (allowed && Array.isArray(allowed)) {
							inp = document.createElement('select');
							inp.style.cssText = 'padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
							allowed.forEach(function(opt) {
								var o = document.createElement('option');
								o.value = opt; o.textContent = opt;
								if (opt == val[subKey]) o.selected = true;
								inp.appendChild(o);
							});
						} else {
							inp = document.createElement('input');
							inp.type = (typeof val[subKey] === 'number') ? 'number' : 'text';
							inp.style.cssText = 'width:70px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
							inp.value = val[subKey];
						}
						inp.dataset.configId = cfg.ConfigurationId;
						inp.dataset.configSub = subKey;
						inp.className = 'ka-config-input';
						inputs.appendChild(inp);
					});
				} else {
					var inp = document.createElement('input');
					inp.type = (cfg.Type === 'number') ? 'number' : 'text';
					inp.style.cssText = 'width:80px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
					inp.value = (val !== null && val !== undefined) ? val : '';
					inp.dataset.configId = cfg.ConfigurationId;
					inp.className = 'ka-config-input';
					inputs.appendChild(inp);
				}
				row.appendChild(inputs);
				container.appendChild(row);
			});
		}

		var btn = gid('ka-config-save');
		if (!btn) return;
		btn.addEventListener('click', function() {
			kaClearFeedback('ka-config-feedback');
			var data = {};
			document.querySelectorAll('#ka-config-rows .ka-config-input').forEach(function(inp) {
				var cid = inp.dataset.configId;
				var sub = inp.dataset.configSub;
				if (!cid) return;
				var key = sub ? ('Config[' + cid + '][' + sub + ']') : ('Config[' + cid + ']');
				data[key] = inp.value;
			});
			var recsVal = gid('ka-config-recs-public') ? gid('ka-config-recs-public').value : null;
			btn.disabled = true;

			function saveRecs(cb) {
				if (recsVal === null) { cb(true, null); return; }
				var fd = new FormData();
				fd.append('Value', recsVal);
				fetch(BASE_URL + 'setrecsvisibility', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) { cb(r && r.status === 0, (r && r.error) ? r.error : 'Visibility save failed.'); })
				.catch(function() { cb(false, 'Visibility request failed.'); });
			}

			if (Object.keys(data).length) {
				var fd = new FormData();
				Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
				fetch(BASE_URL + 'setconfig', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					if (r && r.status === 0) {
						saveRecs(function(ok, err) {
							btn.disabled = false;
							if (ok) kaFeedback('ka-config-feedback', 'Configuration saved!', true);
							else kaFeedback('ka-config-feedback', err, false);
						});
					} else { btn.disabled = false; kaFeedback('ka-config-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-config-feedback', 'Request failed.', false); });
			} else {
				saveRecs(function(ok, err) {
					btn.disabled = false;
					if (ok) kaFeedback('ka-config-feedback', 'Configuration saved!', true);
					else kaFeedback('ka-config-feedback', err, false);
				});
			}
		});
	})();

	/* ══════════════════════════════════════════════
	   HERALDRY
	   ══════════════════════════════════════════════ */
	(function() {
		var fileInput = gid('ka-heraldry-file');
		var uploadBtn = gid('ka-heraldry-upload');
		var removeBtn = gid('ka-heraldry-remove');

		if (fileInput) {
			fileInput.addEventListener('change', function() {
				if (uploadBtn) uploadBtn.disabled = !this.files.length;
				if (this.files.length) {
					var reader = new FileReader();
					reader.onload = function(e) { gid('ka-heraldry-preview').src = e.target.result; };
					reader.readAsDataURL(this.files[0]);
				}
			});
		}
		if (uploadBtn) {
			uploadBtn.addEventListener('click', function() {
				if (!fileInput.files.length) return;
				var fd = new FormData();
				fd.append('Heraldry', fileInput.files[0]);
				uploadBtn.disabled = true;
				fetch(BASE_URL + 'setheraldry', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					uploadBtn.disabled = false;
					if (r && r.status === 0) {
						kaFeedback('ka-heraldry-feedback', 'Heraldry updated! Refreshing...', true);
						setTimeout(function() { location.reload(); }, 1000);
					} else { kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Upload failed.', false); }
				})
				.catch(function() { uploadBtn.disabled = false; kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
			});
		}
		if (removeBtn) {
			removeBtn.addEventListener('click', function() {
				kaConfirm('Remove the kingdom heraldry?', function() {
					removeBtn.disabled = true;
					fetch(BASE_URL + 'removeheraldry', { method: 'POST', body: new FormData() })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						removeBtn.disabled = false;
						if (r && r.status === 0) {
							kaFeedback('ka-heraldry-feedback', 'Heraldry removed. Refreshing...', true);
							setTimeout(function() { location.reload(); }, 1000);
						} else { kaFeedback('ka-heraldry-feedback', (r && r.error) ? r.error : 'Remove failed.', false); }
					})
					.catch(function() { removeBtn.disabled = false; kaFeedback('ka-heraldry-feedback', 'Request failed.', false); });
				}, 'Remove Heraldry');
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PARK TITLES
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-titles-tbody');
		if (!tbody) return;

		function makeTitleRow(pt) {
			var tr = document.createElement('tr');
			tr.dataset.titleId = pt.ParkTitleId;
			function makeCell(type, field, val) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = type;
				inp.className = type === 'number' ? '' : '';
				inp.style.cssText = type === 'number' ? 'width:56px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center' : 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
				inp.value = val;
				if (type === 'number') inp.min = '0';
				inp.dataset.field = field;
				td.appendChild(inp);
				return td;
			}
			var periodTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.style.cssText = 'padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			sel.dataset.field = 'Period';
			['month','week'].forEach(function(v) {
				var o = document.createElement('option');
				o.value = v; o.textContent = v.charAt(0).toUpperCase() + v.slice(1);
				if (v === pt.Period) o.selected = true;
				sel.appendChild(o);
			});
			periodTd.appendChild(sel);

			var delTd = document.createElement('td');
			var delBtn = document.createElement('button');
			delBtn.className = 'ka-tdel';
			delBtn.innerHTML = '<i class="fas fa-trash"></i>';
			delBtn.title = 'Delete';
			(function(row, titleName, titleId) {
				delBtn.addEventListener('click', function() {
					kaConfirm('Delete "' + titleName + '"?', function() {
						delBtn.disabled = true;
						kaPost(BASE_URL + 'deletetitle', { ParkTitleId: titleId }, null, 'ka-titles-feedback', function() {
							row.parentNode && row.parentNode.removeChild(row);
							kaFeedback('ka-titles-feedback', 'Title deleted.', true);
						});
					}, 'Delete Title');
				});
			})(tr, pt.Title, pt.ParkTitleId);
			delTd.appendChild(delBtn);

			tr.appendChild(makeCell('text', 'Title', pt.Title));
			tr.appendChild(makeCell('number', 'Class', pt.Class));
			tr.appendChild(makeCell('number', 'MinimumAttendance', pt.MinimumAttendance));
			tr.appendChild(makeCell('number', 'MinimumCutoff', pt.MinimumCutoff));
			tr.appendChild(periodTd);
			tr.appendChild(makeCell('number', 'Length', pt.Length));
			tr.appendChild(delTd);
			return tr;
		}

		// Build initial rows
		(KaConfig.adminParkTitles || []).forEach(function(pt) { tbody.appendChild(makeTitleRow(pt)); });

		var btn = gid('ka-titles-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-titles-feedback');
				var data = {};
				tbody.querySelectorAll('tr').forEach(function(row) {
					var id = row.dataset.titleId;
					row.querySelectorAll('[data-field]').forEach(function(inp) { data[inp.dataset.field + '[' + id + ']'] = inp.value; });
				});
				var newTitle = document.querySelector('#ka-titles-table tfoot [data-field="Title"]');
				if (newTitle && newTitle.value.trim()) {
					document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) { data[inp.dataset.field + '[New]'] = inp.value; });
				}
				if (!Object.keys(data).length) { kaFeedback('ka-titles-feedback', 'No data to save.', false); return; }
				btn.disabled = true;
				var fd = new FormData();
				Object.keys(data).forEach(function(k) { fd.append(k, data[k]); });
				fetch(BASE_URL + 'setparktitles', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					if (r && r.status === 0) {
						kaFeedback('ka-titles-feedback', 'Park titles saved!', true);
						document.querySelectorAll('#ka-titles-table tfoot [data-field]').forEach(function(inp) {
							inp.value = (inp.dataset.field === 'Length') ? '1' : (inp.type === 'number' ? '0' : '');
						});
					} else { kaFeedback('ka-titles-feedback', (r && r.error) ? r.error : 'Save failed.', false); }
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-titles-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   EDIT PARKS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-parks-tbody');
		if (!tbody) return;

		function makeParkRow(park) {
			var tr = document.createElement('tr');
			tr.dataset.parkId = park.ParkId;
			if (park.Active !== 'Active') tr.classList.add('ka-admin-park-retired');

			var nameTd = document.createElement('td');
			var nameInp = document.createElement('input');
			nameInp.type = 'text';
			nameInp.style.cssText = 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			nameInp.value = park.Name || '';
			nameInp.dataset.field = 'ParkName';
			nameTd.appendChild(nameInp);

			var titleTd = document.createElement('td');
			var sel = document.createElement('select');
			sel.style.cssText = 'padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			sel.dataset.field = 'ParkTitle';
			var opts = KaConfig.parkTitleOptions || {};
			Object.keys(opts).forEach(function(tid) {
				var o = document.createElement('option');
				o.value = tid; o.textContent = opts[tid];
				if (parseInt(tid) === park.ParkTitleId) o.selected = true;
				sel.appendChild(o);
			});
			titleTd.appendChild(sel);

			var abbrTd = document.createElement('td');
			var abbrInp = document.createElement('input');
			abbrInp.type = 'text';
			abbrInp.style.cssText = 'width:60px;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px';
			abbrInp.value = park.Abbreviation || '';
			abbrInp.maxLength = 3;
			abbrInp.dataset.field = 'Abbreviation';
			abbrTd.appendChild(abbrInp);

			var activeTd = document.createElement('td');
			activeTd.style.textAlign = 'center';
			var label = document.createElement('label');
			label.className = 'ka-toggle';
			var chk = document.createElement('input');
			chk.type = 'checkbox';
			chk.checked = (park.Active === 'Active');
			chk.dataset.field = 'Active';
			chk.addEventListener('change', function() { tr.classList.toggle('ka-admin-park-retired', !chk.checked); });
			var track = document.createElement('span');
			track.className = 'ka-toggle-track';
			label.appendChild(chk);
			label.appendChild(track);
			activeTd.appendChild(label);

			var viewTd = document.createElement('td');
			var viewA = document.createElement('a');
			viewA.href = UIR + 'Park/profile/' + park.ParkId;
			viewA.target = '_blank';
			viewA.title = 'View ' + (park.Name || '');
			viewA.innerHTML = '<i class="fas fa-external-link-alt" style="color:#a0aec0"></i>';
			viewTd.appendChild(viewA);

			tr.appendChild(nameTd);
			tr.appendChild(titleTd);
			tr.appendChild(abbrTd);
			tr.appendChild(activeTd);
			tr.appendChild(viewTd);
			return tr;
		}

		var parks = (KaConfig.parkEditLookup || []).slice();
		parks.sort(function(a, b) { return (a.Name || '').localeCompare(b.Name || ''); });
		parks.forEach(function(park) { tbody.appendChild(makeParkRow(park)); });

		var btn = gid('ka-parks-save');
		if (btn) {
			btn.addEventListener('click', function() {
				kaClearFeedback('ka-parks-feedback');
				var parks = [];
				tbody.querySelectorAll('tr').forEach(function(row) {
					var pid = parseInt(row.dataset.parkId, 10);
					if (!pid) return;
					var p = { ParkId: pid };
					row.querySelectorAll('[data-field]').forEach(function(inp) {
						p[inp.dataset.field] = (inp.type === 'checkbox') ? (inp.checked ? 'YES' : '') : inp.value;
					});
					parks.push(p);
				});
				if (!parks.length) { kaFeedback('ka-parks-feedback', 'No data to save.', false); return; }
				btn.disabled = true;
				var fd = new FormData();
				fd.append('ParksJson', JSON.stringify(parks));
				fetch(BASE_URL + 'updateparks', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(r) {
					btn.disabled = false;
					if (r && r.status === 0) kaFeedback('ka-parks-feedback', 'Parks saved!', true);
					else kaFeedback('ka-parks-feedback', (r && r.error) ? r.error : 'Save failed.', false);
				})
				.catch(function() { btn.disabled = false; kaFeedback('ka-parks-feedback', 'Request failed.', false); });
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   AWARDS
	   ══════════════════════════════════════════════ */
	(function() {
		var tbody = gid('ka-awards-tbody');
		if (!tbody) return;

		function makeAwardRow(aw) {
			var tr = document.createElement('tr');
			function ntd(isText, val) {
				var td = document.createElement('td');
				var inp = document.createElement('input');
				inp.type = isText ? 'text' : 'number';
				inp.style.cssText = isText ? 'width:100%;padding:4px 6px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px' : 'width:56px;padding:4px;border:1px solid #e2e8f0;border-radius:4px;font-size:12px;text-align:center';
				inp.value = val;
				if (!isText) inp.min = '0';
				td.appendChild(inp);
				return { td: td, inp: inp };
			}
			var nameCell = ntd(true, aw.KingdomAwardName);
			var sysName = aw.AwardName || '';
			if (sysName && sysName !== aw.KingdomAwardName) {
				var hint = document.createElement('span');
				hint.className = 'ka-alias-hint';
				hint.innerHTML = '<i class="fas fa-question-circle"></i>';
				hint.title = 'Alias for system award: ' + sysName;
				nameCell.td.appendChild(hint);
			}
			var reignCell = ntd(false, aw.ReignLimit);
			var monthCell = ntd(false, aw.MonthLimit);
			var titleTd = document.createElement('td');
			titleTd.style.textAlign = 'center';
			var titleCb = document.createElement('input');
			titleCb.type = 'checkbox';
			titleCb.checked = (aw.IsTitle === 1);
			titleTd.appendChild(titleCb);
			var classCell = ntd(false, aw.TitleClass);
			classCell.inp.disabled = !titleCb.checked;
			titleCb.addEventListener('change', function() { classCell.inp.disabled = !this.checked; });

			var actionsTd = document.createElement('td');
			actionsTd.style.whiteSpace = 'nowrap';
			var saveBtn = document.createElement('button');
			saveBtn.className = 'ka-tsave';
			saveBtn.innerHTML = '<i class="fas fa-save"></i>';
			saveBtn.title = 'Save';
			saveBtn.style.marginRight = '4px';
			(function(btn, nc, rc, mc, cb, cc, kawId) {
				btn.addEventListener('click', function() {
					kaClearFeedback('ka-awards-feedback');
					btn.disabled = true;
					kaPost(BASE_URL + 'setaward', {
						KingdomAwardId: kawId, KingdomAwardName: nc.value.trim(),
						ReignLimit: rc.value, MonthLimit: mc.value,
						IsTitle: cb.checked ? 1 : 0, TitleClass: cc.value
					}, null, 'ka-awards-feedback', function() {
						btn.disabled = false;
						kaFeedback('ka-awards-feedback', 'Award saved!', true);
					});
				});
			})(saveBtn, nameCell.inp, reignCell.inp, monthCell.inp, titleCb, classCell.inp, aw.KingdomAwardId);

			var delBtn = document.createElement('button');
			delBtn.className = 'ka-tdel';
			delBtn.innerHTML = '<i class="fas fa-trash"></i>';
			delBtn.title = 'Delete';
			(function(btn, row, kawId, awName) {
				btn.addEventListener('click', function() {
					kaConfirm('Delete award "' + awName + '"? This cannot be undone.', function() {
						btn.disabled = true;
						kaPost(BASE_URL + 'deleteaward', { KingdomAwardId: kawId }, null, 'ka-awards-feedback', function() {
							row.parentNode && row.parentNode.removeChild(row);
							kaFeedback('ka-awards-feedback', 'Award deleted.', true);
						});
					}, 'Delete Award');
				});
			})(delBtn, tr, aw.KingdomAwardId, aw.KingdomAwardName);

			actionsTd.appendChild(saveBtn);
			actionsTd.appendChild(delBtn);
			tr.appendChild(nameCell.td);
			tr.appendChild(reignCell.td);
			tr.appendChild(monthCell.td);
			tr.appendChild(titleTd);
			tr.appendChild(classCell.td);
			tr.appendChild(actionsTd);
			return tr;
		}

		// Group awards using same logic as model.Award.php
		function classifyAward(aw) {
			var sysName = aw.AwardName || aw.KingdomAwardName || '';
			if (aw.AwardId === 0) return 'Kingdom-Specific';
			if (sysName === 'Custom Award') return 'Kingdom-Specific';
			if (aw.IsLadder) return 'Ladder Awards';
			if (sysName === 'Defender' || sysName === 'Master') return 'Noble Titles';
			if (sysName === 'Weaponmaster') return 'Offices & Other';
			if (aw.Peerage === 'Knight') return 'Knighthoods';
			if (aw.Peerage === 'Paragon') return 'Paragons';
			if (aw.Peerage === 'Master' || (aw.IsTitle && aw.TitleClass === 10)) return 'Masterhoods';
			if (['Squire','Man-At-Arms','Page','Lords-Page'].indexOf(aw.Peerage) >= 0 || sysName === 'Apprentice') return 'Associate Titles';
			if ((aw.IsTitle && aw.TitleClass >= 30) || sysName === 'Esquire') return 'Noble Titles';
			return 'Offices & Other';
		}

		var groupOrder = ['Ladder Awards','Kingdom-Specific','Knighthoods','Masterhoods','Paragons','Noble Titles','Associate Titles','Offices & Other'];
		var groups = {};
		groupOrder.forEach(function(g) { groups[g] = []; });
		(KaConfig.adminAwards || []).forEach(function(aw) {
			var g = classifyAward(aw);
			if (!groups[g]) groups[g] = [];
			groups[g].push(aw);
		});

		groupOrder.forEach(function(groupName) {
			var items = groups[groupName];
			if (!items || !items.length) return;
			// Group header row
			var hdr = document.createElement('tr');
			hdr.className = 'ka-award-group-hdr';
			var hdrTd = document.createElement('td');
			hdrTd.colSpan = 6;
			hdrTd.innerHTML = '<i class="fas fa-chevron-down ka-award-group-chev"></i>' + groupName + '<span class="ka-award-group-count">(' + items.length + ')</span>';
			hdr.appendChild(hdrTd);
			tbody.appendChild(hdr);
			// Award rows for this group
			var rowEls = [];
			items.forEach(function(aw) {
				var row = makeAwardRow(aw);
				tbody.appendChild(row);
				rowEls.push(row);
			});
			// Toggle collapse
			hdr.addEventListener('click', function() {
				var collapsed = hdr.classList.toggle('ka-collapsed');
				rowEls.forEach(function(r) { r.style.display = collapsed ? 'none' : ''; });
			});
		});

		// Award alias / custom add forms
		var addBtn = gid('ka-awards-add-btn'), addWrap = gid('ka-add-award-wrap'), addCancel = gid('ka-new-award-cancel');
		var customBtn = gid('ka-custom-add-btn'), customWrap = gid('ka-add-custom-wrap'), customCancel = gid('ka-custom-cancel');
		var btnRow = gid('ka-award-add-btns');

		function showAliasForm() { if (addWrap) addWrap.style.display = ''; if (customWrap) customWrap.style.display = 'none'; if (btnRow) btnRow.style.display = 'none'; }
		function showCustomForm() { if (customWrap) customWrap.style.display = ''; if (addWrap) addWrap.style.display = 'none'; if (btnRow) btnRow.style.display = 'none'; }
		function showButtons() { if (addWrap) addWrap.style.display = 'none'; if (customWrap) customWrap.style.display = 'none'; if (btnRow) btnRow.style.display = ''; }

		if (addBtn) addBtn.addEventListener('click', showAliasForm);
		if (customBtn) customBtn.addEventListener('click', showCustomForm);
		if (addCancel) addCancel.addEventListener('click', showButtons);
		if (customCancel) customCancel.addEventListener('click', showButtons);

		// Title checkbox toggles
		var newIsTitleCb = gid('ka-new-istitle'), newTClassInp = gid('ka-new-tclass');
		if (newIsTitleCb && newTClassInp) newIsTitleCb.addEventListener('change', function() { newTClassInp.disabled = !this.checked; });
		var customIsTitleCb = gid('ka-custom-istitle'), customTClassInp = gid('ka-custom-tclass');
		if (customIsTitleCb && customTClassInp) customIsTitleCb.addEventListener('change', function() { customTClassInp.disabled = !this.checked; });

		// System award alias dropdown
		var trigger = gid('ka-alias-trigger'), dropdown = gid('ka-alias-dropdown'), searchInp = gid('ka-alias-search');
		var listEl = gid('ka-alias-list'), hiddenInp = gid('ka-new-award-id'), nameInp = gid('ka-new-award-name');
		var labelSpan = gid('ka-alias-label');
		var sysAwards = KaConfig.systemAwards || [];
		var aliasOpen = false;

		function buildAliasList(filter) {
			if (!listEl) return;
			listEl.innerHTML = '';
			var lc = (filter || '').toLowerCase(), count = 0;
			sysAwards.forEach(function(sa) {
				if (lc && sa.Name.toLowerCase().indexOf(lc) === -1) return;
				var div = document.createElement('div');
				div.className = 'ka-alias-item';
				div.textContent = sa.Name;
				div.addEventListener('click', function() { selectAlias(sa.AwardId, sa.Name); });
				listEl.appendChild(div);
				count++;
			});
			if (!count) { var empty = document.createElement('div'); empty.className = 'ka-alias-empty'; empty.textContent = 'No matching awards'; listEl.appendChild(empty); }
		}
		function selectAlias(id, name) {
			if (hiddenInp) hiddenInp.value = id;
			if (labelSpan) { labelSpan.textContent = name; }
			if (nameInp && !nameInp.value.trim()) nameInp.value = name;
			closeAlias();
		}
		function openAlias() { if (!dropdown || aliasOpen) return; aliasOpen = true; dropdown.style.display = ''; buildAliasList(''); if (searchInp) { searchInp.value = ''; searchInp.focus(); } }
		function closeAlias() { if (!dropdown) return; aliasOpen = false; dropdown.style.display = 'none'; }
		if (trigger) trigger.addEventListener('click', function(e) { e.preventDefault(); aliasOpen ? closeAlias() : openAlias(); });
		if (searchInp) { searchInp.addEventListener('input', function() { buildAliasList(this.value); }); searchInp.addEventListener('keydown', function(e) { if (e.key === 'Escape') closeAlias(); }); }
		document.addEventListener('click', function(e) { if (aliasOpen && trigger && dropdown && !trigger.contains(e.target) && !dropdown.contains(e.target)) closeAlias(); });

		// Save new alias
		var saveNewBtn = gid('ka-new-award-save');
		if (saveNewBtn) {
			saveNewBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var awardId = parseInt((hiddenInp ? hiddenInp.value : '0') || '0', 10);
				var name = (nameInp ? nameInp.value : '').trim();
				if (!awardId) { kaFeedback('ka-awards-feedback', 'Please select a system award.', false); return; }
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveNewBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: awardId, KingdomAwardName: name,
					ReignLimit: gid('ka-new-reign').value, MonthLimit: gid('ka-new-month').value,
					IsTitle: gid('ka-new-istitle').checked ? 1 : 0, TitleClass: gid('ka-new-tclass').value
				}, null, 'ka-awards-feedback', function() {
					saveNewBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Award alias created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}

		// Save custom award
		var saveCustomBtn = gid('ka-custom-save');
		if (saveCustomBtn) {
			saveCustomBtn.addEventListener('click', function() {
				kaClearFeedback('ka-awards-feedback');
				var name = (gid('ka-custom-name').value || '').trim();
				if (!name) { kaFeedback('ka-awards-feedback', 'Award name is required.', false); return; }
				saveCustomBtn.disabled = true;
				kaPost(BASE_URL + 'setaward', {
					KingdomAwardId: 0, AwardId: 0, KingdomAwardName: name,
					ReignLimit: gid('ka-custom-reign').value, MonthLimit: gid('ka-custom-month').value,
					IsTitle: gid('ka-custom-istitle').checked ? 1 : 0, TitleClass: gid('ka-custom-tclass').value
				}, null, 'ka-awards-feedback', function() {
					saveCustomBtn.disabled = false;
					kaFeedback('ka-awards-feedback', 'Kingdom-specific award created!', true);
					setTimeout(function() { location.reload(); }, 900);
				});
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   CREATE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		var btn = gid('ka-cp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var parkId   = gid('ka-cp-park').value;
			var persona  = gid('ka-cp-persona').value.trim();
			var username = gid('ka-cp-username').value.trim();
			var password = gid('ka-cp-password').value;
			if (!parkId)             { kaFeedback('ka-cp-feedback', 'Please select a home park.', false); return; }
			if (!persona)            { kaFeedback('ka-cp-feedback', 'Persona is required.', false); return; }
			if (!username)           { kaFeedback('ka-cp-feedback', 'Username is required.', false); return; }
			if (username.length < 4) { kaFeedback('ka-cp-feedback', 'Username must be at least 4 characters.', false); return; }
			var restricted = document.querySelector('input[name="ka-cp-restricted"]:checked');
			var waivered   = document.querySelector('input[name="ka-cp-waivered"]:checked');
			kaPost(UIR + 'PlayerAjax/park/' + parkId + '/create', {
				Persona: persona,
				GivenName: gid('ka-cp-given').value.trim(),
				Surname: gid('ka-cp-surname').value.trim(),
				Email: gid('ka-cp-email').value.trim(),
				UserName: username,
				Password: password,
				Restricted: restricted ? restricted.value : '0',
				Waivered: waivered ? waivered.value : '0',
			}, btn, 'ka-cp-feedback', function(r) {
				window.location.href = UIR + 'Player/profile/' + r.mundaneId;
			});
		});
	})();

	/* ══════════════════════════════════════════════
	   MOVE PLAYER
	   ══════════════════════════════════════════════ */
	(function() {
		function mpCheck() {
			var pid = gid('ka-mp-player-id').value;
			var pkid = gid('ka-mp-park-id').value;
			gid('ka-mp-submit').disabled = !(pid && pkid);
		}
		kaAc({ inputId:'ka-mp-player-name', hiddenId:'ka-mp-player-id', resultsId:'ka-mp-player-results',
			fetchFn: kaSearchPlayers, onSelect: mpCheck, onClear: mpCheck });
		kaAc({ inputId:'ka-mp-park-name', hiddenId:'ka-mp-park-id', resultsId:'ka-mp-park-results',
			fetchFn: kaSearchParks, onSelect: mpCheck, onClear: mpCheck });

		var btn = gid('ka-mp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var playerId = gid('ka-mp-player-id').value;
			var parkId   = gid('ka-mp-park-id').value;
			if (!playerId || !parkId) return;
			kaPost(UIR + 'PlayerAjax/player/' + playerId + '/moveplayer', { ParkId: parkId },
				btn, 'ka-mp-feedback', function() {
					kaFeedback('ka-mp-feedback',
						'Player moved successfully. <a href="' + UIR + 'Park/profile/' + parkId + '">View park</a> &middot; <a href="' + UIR + 'Player/profile/' + playerId + '">View player</a>', true);
					['ka-mp-player-name','ka-mp-park-name'].forEach(function(id){gid(id).value='';});
					['ka-mp-player-id','ka-mp-park-id'].forEach(function(id){gid(id).value='';});
					btn.disabled = true;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   MERGE PLAYERS
	   ══════════════════════════════════════════════ */
	(function() {
		function mgpCheck() {
			var keep   = gid('ka-mgp-keep-id').value;
			var remove = gid('ka-mgp-remove-id').value;
			gid('ka-mgp-submit').disabled = !(keep && remove);
		}
		kaAc({ inputId:'ka-mgp-keep-name', hiddenId:'ka-mgp-keep-id', resultsId:'ka-mgp-keep-results',
			fetchFn: kaSearchPlayers, onSelect: mgpCheck, onClear: mgpCheck });
		kaAc({ inputId:'ka-mgp-remove-name', hiddenId:'ka-mgp-remove-id', resultsId:'ka-mgp-remove-results',
			fetchFn: kaSearchPlayers, onSelect: mgpCheck, onClear: mgpCheck });

		var btn = gid('ka-mgp-submit');
		if (!btn) return;
		btn.addEventListener('click', function() {
			var keepId   = gid('ka-mgp-keep-id').value;
			var removeId = gid('ka-mgp-remove-id').value;
			if (!keepId || !removeId) return;
			if (keepId === removeId) { kaFeedback('ka-mgp-feedback', 'Cannot merge a player with themselves.', false); return; }
			kaPost(UIR + 'PlayerAjax/merge', { ToMundaneId: keepId, FromMundaneId: removeId },
				btn, 'ka-mgp-feedback', function() {
					window.location.href = UIR + 'Player/profile/' + keepId;
				});
		});
	})();

	/* ══════════════════════════════════════════════
	   OPERATIONS
	   ══════════════════════════════════════════════ */
	(function() {
		// Reset Waivers
		var resetBtn = gid('ka-ops-reset-waivers');
		if (resetBtn) {
			resetBtn.addEventListener('click', function() {
				kaConfirm('This will reset all waivers. This action cannot be undone.', function() {
					resetBtn.disabled = true;
					kaPost(BASE_URL + 'resetwaivers', {}, null, 'ka-ops-feedback', function(r) {
						resetBtn.disabled = false;
						kaFeedback('ka-ops-feedback', r.message || 'Waivers reset.', true);
					});
				}, 'Reset Waivers');
			});
		}

		// Active Status toggle
		var statusBtn = gid('ka-ops-status-toggle');
		if (statusBtn) {
			statusBtn.addEventListener('click', function() {
				var isActive = statusBtn.dataset.active === '1';
				var newActive = isActive ? 'Retired' : 'Active';
				var label = isActive ? 'mark this as inactive' : 'restore this to active';
				kaConfirm('Are you sure you want to ' + label + '?', function() {
					kaClearFeedback('ka-ops-feedback');
					var fd = new FormData();
					fd.append('Active', newActive);
					fetch(BASE_URL + 'setstatus', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(r) {
						if (r && r.status === 0) {
							statusBtn.dataset.active = newActive === 'Active' ? '1' : '0';
							gid('ka-ops-status-label').textContent = newActive === 'Active' ? 'Active' : 'Inactive';
							if (newActive === 'Active') {
								statusBtn.innerHTML = '<i class="fas fa-ban"></i> Mark Inactive';
								statusBtn.classList.add('ka-ops-btn-danger');
							} else {
								statusBtn.innerHTML = '<i class="fas fa-check-circle"></i> Restore to Active';
								statusBtn.classList.remove('ka-ops-btn-danger');
							}
							kaFeedback('ka-ops-feedback', newActive === 'Active' ? 'Restored to active.' : 'Marked inactive.', true);
						} else { kaFeedback('ka-ops-feedback', (r && r.error) ? r.error : 'Request failed.', false); }
					})
					.catch(function() { kaFeedback('ka-ops-feedback', 'Request failed.', false); });
				}, newActive === 'Active' ? 'Restore' : 'Mark Inactive');
			});
		}
	})();

	/* ══════════════════════════════════════════════
	   PRINCIPALITY STATUS
	   ══════════════════════════════════════════════ */
	(function() {
		if (!KaConfig.isOrkAdmin) return;

		kaAc({ inputId:'ka-prinz-parent-name', hiddenId:'ka-prinz-parent-id', resultsId:'ka-prinz-parent-results',
			fetchFn: kaSearchKingdoms });

		function doSetParent(newParentId, successMsg) {
			var fd = new FormData();
			fd.append('ParentKingdomId', newParentId);
			fetch(BASE_URL + 'setparent', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(r) {
				if (r && r.status === 0) kaFeedback('ka-prinz-feedback', successMsg, true);
				else kaFeedback('ka-prinz-feedback', (r && r.error) ? r.error : 'Save failed.', false);
			})
			.catch(function() { kaFeedback('ka-prinz-feedback', 'Request failed.', false); });
		}

		var sponsorBtn = gid('ka-prinz-sponsor-save');
		if (sponsorBtn) {
			sponsorBtn.addEventListener('click', function() {
				kaClearFeedback('ka-prinz-feedback');
				var newParentId = parseInt(gid('ka-prinz-parent-id').value || '0', 10);
				if (!newParentId) { kaFeedback('ka-prinz-feedback', 'Please select a sponsor kingdom.', false); return; }
				doSetParent(newParentId, 'Sponsor kingdom updated.');
			});
		}

		var promoteBtn = gid('ka-prinz-promote');
		if (promoteBtn) {
			promoteBtn.addEventListener('click', function() {
				kaConfirm('Remove this principality\'s sponsor and make it a full kingdom?', function() {
					kaClearFeedback('ka-prinz-feedback');
					doSetParent(0, 'Converted to kingdom. Reload to see updated status.');
				}, 'Convert to Kingdom');
			});
		}
	})();

})();
</script>
