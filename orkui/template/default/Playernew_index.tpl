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

	// Auth helpers
	$isOwnProfile  = isset($this->__session->user_id) && (int)$this->__session->user_id === (int)$Player['MundaneId'];
	$canEditAdmin  = isset($this->__session->user_id) && Ork3::$Lib->authorization->HasAuthority($this->__session->user_id, AUTH_PARK, $Player['ParkId'], AUTH_EDIT);
	$canEditImages  = $isOwnProfile || $canEditAdmin;
	$canEditAccount = $isOwnProfile || $canEditAdmin;
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
	max-width: none;
	object-fit: cover;
	object-position: center center;
	display: block;
	border: none;
	border-radius: 0;
	padding: 0;
	margin: 0;
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
.pn-dues-modal-current {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 10px 12px;
	margin-bottom: 18px;
}
.pn-dues-modal-current-title {
	font-weight: 600;
	color: #718096;
	font-size: 0.8em;
	text-transform: uppercase;
	letter-spacing: 0.05em;
	margin-bottom: 7px;
}
.pn-dues-modal-table {
	width: 100%;
	border-collapse: collapse;
}
.pn-dues-modal-table th,
.pn-dues-modal-table td {
	text-align: left;
	padding: 3px 6px;
	font-size: 0.88em;
}
.pn-dues-modal-table th { color: #718096; font-weight: 600; border-bottom: 1px solid #e2e8f0; }
.pn-dues-modal-table td { color: #4a5568; }
.pn-dues-modal-empty { color: #a0aec0; font-size: 0.88em; font-style: italic; }
.pn-dues-until-preview {
	margin-top: 5px;
	font-size: 0.88em;
	color: #2c5282;
	min-height: 1.3em;
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
.pn-unit-row {
	display: flex;
	align-items: center;
	padding: 4px 0;
}
.pn-unit-quit-cell {
	margin-left: auto;
	flex-shrink: 0;
	opacity: 0;
	transition: opacity 0.15s;
}
.pn-unit-row:hover .pn-unit-quit-cell,
.pn-unit-row:has(.pn-delete-confirm.pn-active) .pn-unit-quit-cell {
	opacity: 1;
}
.pn-unit-row:has(.pn-delete-confirm.pn-active) .pn-unit-type {
	display: none;
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

/* Ladder award progress widgets */
.pn-ladder-grid {
	display: grid;
	grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
	gap: 8px 12px;
	margin-bottom: 16px;
}
.pn-ladder-item {
	background: #f7fafc;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	padding: 7px 10px;
}
.pn-ladder-header {
	display: flex;
	justify-content: space-between;
	align-items: baseline;
	margin-bottom: 5px;
}
.pn-ladder-name {
	font-size: 11px;
	font-weight: 600;
	color: #4a5568;
	white-space: nowrap;
	overflow: hidden;
	text-overflow: ellipsis;
	max-width: 75%;
}
.pn-ladder-rank {
	font-size: 11px;
	color: #718096;
	white-space: nowrap;
	flex-shrink: 0;
}
.pn-ladder-rank strong { color: #2d3748; }
.pn-ladder-bar-track {
	height: 6px;
	background: #e2e8f0;
	border-radius: 3px;
	overflow: hidden;
}
.pn-ladder-bar-fill {
	height: 100%;
	border-radius: 3px;
	background: linear-gradient(90deg, #48bb78, #276749);
	transition: width 0.4s ease;
}
.pn-ladder-bar-fill.pn-ladder-max { background: linear-gradient(90deg, #f6ad55, #c05621); }
.pn-ladder-master, .pn-paragon-badge {
	display: inline-flex;
	align-items: center;
	gap: 2px;
	font-size: 10px;
	font-weight: 700;
	border-radius: 3px;
	padding: 0px 4px;
	line-height: 16px;
	flex-shrink: 0;
}
.pn-ladder-master {
	color: #744210;
	background: #fefcbf;
	border: 1px solid #f6e05e;
}
.pn-paragon-badge {
	color: #553c9a;
	background: #e9d8fd;
	border: 1px solid #d6bcfa;
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
.pn-btn-primary:hover:not(:disabled) { background: #2a4a7f; }
.pn-btn-secondary { background: #edf2f7; color: #4a5568; }
.pn-btn-secondary:hover:not(:disabled) { background: #e2e8f0; }
.pn-btn:disabled, .pn-btn[disabled] { opacity: 0.4; cursor: not-allowed; }
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

/* ---- Card header inline edit button ---- */
.pn-card-edit-btn {
	float: right;
	background: none;
	border: none;
	cursor: pointer;
	color: #a0aec0;
	padding: 0 2px;
	font-size: 13px;
	line-height: 1;
	transition: color 0.15s;
	margin-top: 1px;
}
.pn-card-edit-btn:hover { color: #4299e1; }

/* ---- Account modal fields ---- */
.pn-acct-field {
	margin-bottom: 14px;
}
.pn-acct-field label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	margin-bottom: 4px;
}
.pn-acct-field input[type="text"],
.pn-acct-field input[type="email"],
.pn-acct-field input[type="password"],
.pn-acct-field select {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	font-size: 14px;
	color: #2d3748;
	box-sizing: border-box;
	background: #fff;
	transition: border-color 0.15s;
}
.pn-acct-field input:focus,
.pn-acct-field select:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 2px rgba(49,130,206,0.12);
}
.pn-acct-radio-group {
	display: flex;
	gap: 16px;
	align-items: center;
	padding-top: 2px;
}
.pn-acct-radio-group label {
	display: flex;
	align-items: center;
	gap: 5px;
	font-size: 14px;
	font-weight: 400;
	color: #4a5568;
	cursor: pointer;
	margin-bottom: 0;
}
.pn-acct-section-title {
	font-size: 11px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.06em;
	color: #a0aec0;
	padding: 6px 0 10px;
	border-top: 1px solid #edf2f7;
	margin-top: 4px;
}
.pn-acct-two-col {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 12px;
}
.pn-acct-hint {
	font-size: 11px;
	color: #a0aec0;
	margin-top: 3px;
}
.pn-acct-modal-body {
	padding: 20px;
	max-height: calc(80vh - 120px);
	overflow-y: auto;
}

/* ---- Editable image — corner edit button ---- */
.pn-editable-img {
	position: relative;
	display: inline-block;
}
.pn-img-edit-btn {
	position: absolute;
	bottom: 6px;
	right: 6px;
	width: 26px;
	height: 26px;
	background: rgba(0,0,0,0.62);
	border-radius: 50%;
	border: none;
	display: flex;
	align-items: center;
	justify-content: center;
	opacity: 0;
	transition: opacity 0.18s, background 0.15s;
	cursor: pointer;
	z-index: 2;
	padding: 0;
	line-height: 1;
}
.pn-editable-img:hover .pn-img-edit-btn { opacity: 1; }
.pn-img-edit-btn:hover { background: rgba(44,82,130,0.9); }
.pn-img-edit-btn i { color: #fff; font-size: 12px; pointer-events: none; }

/* ---- Image upload modal ---- */
.pn-img-modal-box { width: 560px; max-width: calc(100vw - 40px); }
.pn-upload-area {
	border: 2px dashed #cbd5e0;
	border-radius: 8px;
	padding: 28px 16px;
	text-align: center;
	color: #718096;
	cursor: pointer;
	transition: border-color 0.2s, background 0.2s;
	margin-bottom: 10px;
	display: block;
}
.pn-upload-area:hover { border-color: #4299e1; background: #ebf8ff; color: #2b6cb0; }
.pn-upload-area .pn-upload-icon { font-size: 28px; margin-bottom: 6px; display: block; }
.pn-upload-area small { font-size: 12px; color: #a0aec0; display: block; margin-top: 4px; }
.pn-crop-wrap {
	position: relative;
	display: flex;
	justify-content: center;
	margin-bottom: 12px;
	background: #f7fafc;
	border-radius: 6px;
	overflow: hidden;
}
#pn-crop-canvas {
	display: block;
	max-width: 100%;
	cursor: crosshair;
	touch-action: none;
}
.pn-img-step-actions {
	display: flex;
	gap: 10px;
	margin-top: 4px;
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

/* ---- Add Award / Add Title Modal ---- */
.pn-award-type-row {
	display: flex;
	gap: 8px;
	margin-bottom: 16px;
}
.pn-award-type-btn {
	flex: 1;
	padding: 8px 0;
	border: 2px solid #e2e8f0;
	border-radius: 8px;
	background: #fff;
	font-size: 13px;
	font-weight: 600;
	color: #4a5568;
	cursor: pointer;
	transition: all 0.15s;
	text-align: center;
}
.pn-award-type-btn.pn-active {
	border-color: #2c5282;
	background: #ebf4ff;
	color: #2c5282;
}
.pn-award-type-btn:hover:not(.pn-active) {
	border-color: #cbd5e0;
	background: #f7fafc;
}
/* Rank pills */
.pn-rank-pills-wrap {
	display: flex;
	flex-wrap: wrap;
	gap: 6px;
	margin-top: 4px;
}
.pn-rank-pill {
	width: 36px;
	height: 36px;
	border: 2px solid #e2e8f0;
	border-radius: 50%;
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 13px;
	font-weight: 700;
	cursor: pointer;
	background: #fff;
	color: #4a5568;
	transition: all 0.12s;
	user-select: none;
}
.pn-rank-pill:hover { border-color: #90cdf4; color: #2b6cb0; background: #ebf4ff; }
.pn-rank-pill.pn-rank-held { background: #ebf4ff; border-color: #90cdf4; color: #2b6cb0; }
.pn-rank-pill.pn-rank-suggested { border-color: #68d391; }
.pn-rank-pill.pn-rank-selected { background: #2c5282; border-color: #2c5282; color: #fff; }
/* Officer / giver quick chips */
.pn-officer-chips {
	display: flex;
	flex-wrap: wrap;
	gap: 5px;
	margin-bottom: 6px;
}
.pn-officer-chip {
	padding: 4px 10px;
	background: #edf2f7;
	border: 1px solid #e2e8f0;
	border-radius: 14px;
	font-size: 12px;
	color: #2d3748;
	cursor: pointer;
	transition: background 0.12s, border-color 0.12s;
	line-height: 1.3;
}
.pn-officer-chip span { color: #a0aec0; }
.pn-officer-chip:hover { background: #ebf4ff; border-color: #90cdf4; color: #2b6cb0; }
.pn-officer-chip.pn-selected { background: #ebf4ff; border-color: #90cdf4; color: #2b6cb0; font-weight: 600; }
/* Inline autocomplete results */
.pn-ac-results {
	margin-top: 4px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	background: #fff;
	max-height: 140px;
	overflow-y: auto;
	display: none;
}
.pn-ac-results.pn-ac-open { display: block; }
.pn-ac-item {
	padding: 8px 12px;
	font-size: 13px;
	cursor: pointer;
	color: #2d3748;
	border-bottom: 1px solid #f7fafc;
}
.pn-ac-item:last-child { border-bottom: none; }
.pn-ac-item:hover { background: #ebf4ff; color: #2b6cb0; }
.pn-ac-no-results { padding: 8px 12px; font-size: 13px; color: #a0aec0; font-style: italic; }
/* Tab toolbar (Add Award / Add Title button) */
.pn-tab-toolbar {
	display: flex;
	justify-content: flex-end;
	margin-bottom: 12px;
}
.pn-btn-sm { font-size: 12px; padding: 5px 13px; }
/* Award ladder badge */
.pn-badge-ladder {
	display: inline-flex;
	align-items: center;
	gap: 4px;
	padding: 1px 8px;
	border-radius: 12px;
	font-size: 11px;
	font-weight: 700;
	background: #fefcbf;
	color: #744210;
}
.pn-award-info-line { margin-top: 4px; min-height: 18px; font-size: 12px; }
/* acct-field textarea style */
.pn-acct-field textarea {
	width: 100%;
	padding: 8px 10px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	font-size: 14px;
	color: #2d3748;
	box-sizing: border-box;
	background: #fff;
	resize: vertical;
	font-family: inherit;
	transition: border-color 0.15s;
}
.pn-acct-field textarea:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 2px rgba(49,130,206,0.12);
}

.pn-award-success { background:#f0fff4; border:1px solid #9ae6b4; border-radius:6px; padding:8px 12px; margin-bottom:12px; color:#276749; font-size:13px; }
.pn-btn-ghost { background:transparent; border:1px solid transparent; color:#4a5568; padding:7px 14px; border-radius:6px; cursor:pointer; font-size:13px; }
.pn-btn-ghost:hover { background:#f7fafc; border-color:#e2e8f0; }
</style>

<!-- =============================================
     ZONE 1: Profile Hero Header
     ============================================= -->
<div class="pn-hero">
	<div class="pn-hero-bg" style="background-image: url('<?= $heraldryUrl ?>')"></div>
	<div class="pn-hero-content">
		<?php if ($canEditImages): ?>
		<div class="pn-avatar pn-editable-img">
			<img class="heraldry-img" src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
			<button class="pn-img-edit-btn" onclick="pnOpenImgModal('photo')" title="Update player photo"><i class="fas fa-pencil-alt"></i></button>
		</div>
		<?php else: ?>
		<div class="pn-avatar">
			<img class="heraldry-img" src="<?= $imageUrl ?>" alt="<?= htmlspecialchars($Player['Persona']) ?>" />
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
				<?php if ($IsOrkAdmin): ?>
					<span class="pn-badge pn-badge-purple"><i class="fas fa-cog"></i> ORK Administrator</span>
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
			<h4><i class="fas fa-user"></i> Player Details<?php if ($canEditAccount): ?><button class="pn-card-edit-btn" onclick="pnOpenAccountModal()" title="Edit account details"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
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
				<?php if ($canEditImages): ?>
				<div class="pn-editable-img" style="border-radius:4px;max-width:100%;">
					<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain; display: block;" />
					<button class="pn-img-edit-btn" onclick="pnOpenImgModal('heraldry')" title="Update heraldry"><i class="fas fa-pencil-alt"></i></button>
				</div>
				<?php else: ?>
				<img class="heraldry-img" src="<?= $heraldryUrl ?>" alt="Heraldry" style="max-width: 100%; max-height: 160px; border-radius: 4px; object-fit: contain;" />
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
			<h4><i class="fas fa-receipt"></i> Dues<?php if ($canEditAdmin): ?><button class="pn-card-edit-btn" onclick="pnOpenDuesModal()" title="Add dues entry"><i class="fas fa-pencil-alt"></i></button><?php endif; ?></h4>
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
					<div class="pn-unit-row">
						<a class="pn-unit-link" href="<?= UIR ?>Unit/index/<?= $unit['UnitId'] ?>"><?= htmlspecialchars($unit['Name']) ?></a>
						<span class="pn-unit-type"><?= ucfirst($unit['Type']) ?></span>
						<?php if ($canEditAdmin || $isOwnProfile): ?>
						<span class="pn-delete-cell pn-unit-quit-cell">
							<a class="pn-delete-link pn-confirm-quit-unit" href="#" title="Leave unit">&times;</a>
							<span class="pn-delete-confirm">
								Leave?&nbsp;
								<button class="pn-delete-yes" data-href="<?= UIR ?>Playernew/index/<?= (int)$Player['MundaneId'] ?>/quitunit/<?= $unit['UnitMundaneId'] ?>">Yes</button>
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
				<?php if ($canEditAdmin): ?>
				<div class="pn-tab-toolbar">
					<button class="pn-btn pn-btn-primary pn-btn-sm" onclick="pnOpenAwardModal('awards')"><i class="fas fa-plus"></i> Add Award</button>
				</div>
				<?php endif; ?>
				<?php
					$awardsList = is_array($Details['Awards']) ? $Details['Awards'] : array();
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
					<div class="pn-ladder-grid">
						<?php foreach ($pnLadderProgress as $lp): ?>
							<?php $pct = min(100, round($lp['Rank'] / 10 * 100)); ?>
							<div class="pn-ladder-item" title="<?= htmlspecialchars($lp['Name']) ?>">
								<div class="pn-ladder-header">
									<span class="pn-ladder-name"><?= htmlspecialchars($lp['Short']) ?></span>
									<span style="display:flex;align-items:center;gap:4px;flex-shrink:0">
										<?php if ($lp['HasMaster']): ?>
											<span class="pn-ladder-master" title="Master title earned"><i class="fas fa-star"></i> M</span>
										<?php endif; ?>
										<span class="pn-ladder-rank"><strong><?= $lp['Rank'] ?></strong> / 10</span>
									</span>
								</div>
								<div class="pn-ladder-bar-track">
									<div class="pn-ladder-bar-fill<?= $lp['Rank'] >= 10 ? ' pn-ladder-max' : '' ?>"
									     style="width:<?= $pct ?>%"></div>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if (count($filteredAwards) === 0): ?>
					<div class="pn-empty">No awards recorded</div>
				<?php else: ?>
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
									<?php $displayName = trimlen($detail['CustomAwardName']) > 0 ? $detail['CustomAwardName'] : $detail['KingdomAwardName']; ?>
									<?= $displayName ?>
									<?php if ($displayName != $detail['Name']): ?><span class="pn-award-base">[<?= $detail['Name'] ?>]</span><?php endif; ?>
								</td>
								<td class="pn-col-numeric"><?= valid_id($detail['Rank']) ? $detail['Rank'] : '' ?></td>
								<td class="pn-col-nowrap"><?= strtotime($detail['Date']) > 0 ? $detail['Date'] : '' ?></td>
								<td class="pn-col-nowrap"><a href="<?= UIR ?>Player/index/<?= $detail['GivenById'] ?>"><?= substr($detail['GivenBy'], 0, 30) ?></a></td>
								<td><?php if (valid_id($detail['EventId'])) echo $detail['EventName']; else echo (trimlen($detail['ParkName']) > 0) ? $detail['ParkName'] . ', ' . $detail['KingdomName'] : $detail['KingdomName']; ?></td>
								<td><?= $detail['Note'] ?></td>
								<td><a href="<?= UIR ?>Player/index/<?= $detail['EnteredById'] ?>"><?= $detail['EnteredBy'] ?></a></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
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
				<?php
					$classList = is_array($Details['Classes']) ? $Details['Classes'] : array();
					// class_id → Paragon award_id
					$pnClassToParagon = [
						1  => 37,  // Anti-Paladin → Paragon Anti-Paladin
						2  => 38,  // Archer       → Paragon Archer
						3  => 39,  // Assassin     → Paragon Assassin
						4  => 40,  // Barbarian    → Paragon Barbarian
						5  => 41,  // Bard         → Paragon Bard
						6  => 241, // Color        → Paragon Color
						7  => 42,  // Druid        → Paragon Druid
						8  => 43,  // Healer       → Paragon Healer
						9  => 44,  // Monk         → Paragon Monk
						10 => 45,  // Monster      → Paragon Monster
						11 => 46,  // Paladin      → Paragon Paladin
						12 => 47,  // Peasant      → Paragon Peasant
						14 => 242, // Reeve        → Paragon Reeve
						15 => 49,  // Scout        → Paragon Scout
						16 => 50,  // Warrior      → Paragon Warrior
						17 => 51,  // Wizard       → Paragon Wizard
					];
					// $pnHeldAwardIds is built in the Awards tab block above
					$pnHeldAwardIds = isset($pnHeldAwardIds) ? $pnHeldAwardIds : [];
				?>
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
				<select id="pn-acct-pronouns" name="PronounId">
					<option value="">Choose&hellip;</option>
					<?= $PronounOptions ?>
				</select>
				<input type="hidden" name="PronounCustom" value="<?= htmlspecialchars($Player['PronounCustom'] ?? '') ?>" />
				<div class="pn-acct-hint">For custom pronouns, use the <a href="<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>" style="color:#3182ce">Admin Panel</a></div>
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

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Reeve Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="ReeveQualified" value="1" <?= $Player['ReeveQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="ReeveQualified" value="0" <?= $Player['ReeveQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-reeve-until">Reeve Until</label>
					<input type="text" id="pn-acct-reeve-until" name="ReeveQualifiedUntil" value="<?= htmlspecialchars($Player['ReeveQualifiedUntil'] ?? '') ?>" placeholder="YYYY-MM-DD" />
				</div>
			</div>

			<div class="pn-acct-two-col">
				<div class="pn-acct-field">
					<label>Corpora Qualified</label>
					<div class="pn-acct-radio-group">
						<label><input type="radio" name="CorporaQualified" value="1" <?= $Player['CorporaQualified'] == 1 ? 'checked' : '' ?> /> Yes</label>
						<label><input type="radio" name="CorporaQualified" value="0" <?= $Player['CorporaQualified'] != 1 ? 'checked' : '' ?> /> No</label>
					</div>
				</div>
				<div class="pn-acct-field">
					<label for="pn-acct-corpora-until">Corpora Until</label>
					<input type="text" id="pn-acct-corpora-until" name="CorporaQualifiedUntil" value="<?= htmlspecialchars($Player['CorporaQualifiedUntil'] ?? '') ?>" placeholder="YYYY-MM-DD" />
				</div>
			</div>

			<div class="pn-acct-field">
				<label for="pn-acct-member-since">Park Member Since</label>
				<input type="text" id="pn-acct-member-since" name="ParkMemberSince" value="<?= htmlspecialchars($Player['ParkMemberSince'] ?? '') ?>" placeholder="YYYY-MM-DD" />
			</div>
			<?php endif; ?>
		</div>

		<div class="pn-modal-footer">
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
	<div class="pn-modal-box" style="width:460px;max-width:calc(100vw - 40px);">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-receipt" style="margin-right:8px;color:#2c5282"></i>Add Dues Entry</h3>
			<button class="pn-modal-close-btn" id="pn-dues-close-btn" aria-label="Close">&times;</button>
		</div>

		<div class="pn-acct-modal-body">
			<div class="pn-form-error" id="pn-dues-error"></div>

			<!-- Current dues -->
			<div class="pn-dues-modal-current">
				<div class="pn-dues-modal-current-title"><i class="fas fa-history" style="margin-right:5px"></i>Current Active Dues</div>
				<?php if (is_array($Dues) && count($Dues) > 0): ?>
				<table class="pn-dues-modal-table">
					<thead><tr><th>Park</th><th>Paid Through</th><th>Lifetime</th></tr></thead>
					<tbody>
					<?php foreach ($Dues as $d): ?>
						<tr>
							<td><?= htmlspecialchars($d['ParkName']) ?></td>
							<td><?= $d['DuesForLife'] == 1 ? '<span class="pn-dues-life">Lifetime</span>' : htmlspecialchars($d['DuesUntil']) ?></td>
							<td><?= $d['DuesForLife'] == 1 ? 'Yes' : 'No' ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
				<?php else: ?>
				<div class="pn-dues-modal-empty">No active dues on record</div>
				<?php endif; ?>
			</div>

			<div class="pn-acct-field">
				<label for="pn-dues-from">Date Paid <span style="color:#e53e3e">*</span></label>
				<input type="date" id="pn-dues-from" name="DuesFrom" value="<?= date('Y-m-d') ?>" />
			</div>

			<div class="pn-acct-field" id="pn-dues-months-row">
				<label for="pn-dues-months">Months</label>
				<input type="number" id="pn-dues-months" name="Months" value="6" min="1" max="120" style="width:100px" />
				<div class="pn-dues-until-preview" id="pn-dues-until-preview"></div>
			</div>

			<div class="pn-acct-field">
				<label>Dues For Life</label>
				<div class="pn-acct-radio-group">
					<label><input type="radio" name="DuesForLife" value="1" /> Yes</label>
					<label><input type="radio" name="DuesForLife" value="0" checked /> No</label>
				</div>
			</div>

			<input type="hidden" name="MundaneId" value="<?= (int)$Player['MundaneId'] ?>" />
			<input type="hidden" name="ParkId"    value="<?= (int)$Player['ParkId'] ?>" />
			<input type="hidden" name="KingdomId" value="<?= (int)$KingdomId ?>" />
		</div>

		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="pn-dues-cancel">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="pn-dues-save"><i class="fas fa-save"></i> Add Dues</button>
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
					<input type="date" id="pn-qual-reeve-until" name="ReeveQualifiedUntil" value="<?= htmlspecialchars($Player['ReeveQualifiedUntil'] ?? '') ?>" />
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
					<input type="date" id="pn-qual-corpora-until" name="CorporaQualifiedUntil" value="<?= htmlspecialchars($Player['CorporaQualifiedUntil'] ?? '') ?>" />
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
				<label>Rank <span style="color:#a0aec0;font-weight:400;font-size:11px">— click to select; blue = already held, green border = suggested next</span></label>
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
				<button class="pn-btn pn-btn-secondary" id="pn-award-save-same" disabled>
					<i class="fas fa-plus"></i> Add + Same Player
				</button>
				<button class="pn-btn pn-btn-primary" id="pn-award-save-new" disabled>
					<i class="fas fa-plus"></i> Add + New Player
				</button>
			</div>
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
	$(document).on('click', '.pn-confirm-quit-unit', function(e) {
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

	<?php if ($canEditImages): ?>
	// ---- Image Upload Modal ----
	(function() {
		var UPLOAD_URL = '<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>/update';
		var imgType  = null;  // 'photo' | 'heraldry'
		var origImg  = null;  // HTMLImageElement (resized if needed)
		var cropBox  = null;  // {x,y,w,h} in image pixels
		var dispScale = 1;    // display scale factor
		var cropBound = null; // bound event listener refs for cleanup

		function gid(id) { return document.getElementById(id); }

		function showStep(s) {
			['pn-img-step-select','pn-img-step-crop','pn-img-step-uploading','pn-img-step-success'].forEach(function(id) {
				gid(id).style.display = (id === s) ? '' : 'none';
			});
		}

		function showError(msg) {
			var el = gid('pn-img-error');
			el.textContent = msg;
			el.style.display = '';
		}

		window.pnOpenImgModal = function(type) {
			imgType = type;
			var isPhoto = type === 'photo';
			gid('pn-img-modal-title').innerHTML =
				'<i class="fas fa-' + (isPhoto ? 'user-circle' : 'image') + '" style="margin-right:8px;color:#2c5282"></i>' +
				'Update ' + (isPhoto ? 'Player Photo' : 'Heraldry');
			gid('pn-img-file-input').value = '';
			gid('pn-img-resize-notice').textContent = '';
			var errEl = gid('pn-img-error'); errEl.style.display = 'none'; errEl.textContent = '';
			showStep('pn-img-step-select');
			gid('pn-img-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};

		window.pnCloseImgModal = function() {
			gid('pn-img-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-img-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseImgModal();
		});
		gid('pn-img-close-btn').addEventListener('click', pnCloseImgModal);
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-img-overlay').classList.contains('pn-open'))
				pnCloseImgModal();
		});
		gid('pn-img-back-btn').addEventListener('click', function() {
			gid('pn-img-file-input').value = '';
			gid('pn-img-resize-notice').textContent = '';
			showStep('pn-img-step-select');
		});
		gid('pn-img-upload-btn').addEventListener('click', doUploadCropped);

		// File input change — validate, auto-resize, then show cropper
		gid('pn-img-file-input').addEventListener('change', function() {
			var file = this.files && this.files[0];
			if (!file) return;
			var ext = file.name.split('.').pop().toLowerCase();
			if (['jpg','jpeg','gif','png'].indexOf(ext) < 0) {
				showError('Invalid file type. Please use JPG, GIF, or PNG.');
				this.value = '';
				return;
			}
			gid('pn-img-error').style.display = 'none';

			function loadIntoModal(blob) {
				var url = URL.createObjectURL(blob);
				var img = new Image();
				img.onload = function() {
					URL.revokeObjectURL(url);
					origImg = img;
					initCrop();
					showStep('pn-img-step-crop');
				};
				img.onerror = function() {
					URL.revokeObjectURL(url);
					showError('Could not load image. Please try a different file.');
				};
				img.src = url;
			}

			if (file.size > 348836) {
				var isPng = (file.type === 'image/png');
				gid('pn-img-resize-notice').textContent = 'Resizing\u2026';
				resizeImageToLimit(file, 348836, function(blob) {
					gid('pn-img-resize-notice').textContent = 'Auto-resized to ' + Math.round(blob.size / 1024) + '\u00a0KB';
					loadIntoModal(blob);
				}, function(errMsg) {
					showError(errMsg);
				}, isPng);
			} else {
				loadIntoModal(file);
			}
		});

		// ---- Crop tool ----
		function initCrop() {
			var canvas = gid('pn-crop-canvas');
			var img = origImg;
			var maxW = Math.min(500, window.innerWidth - 100) - 40;
			var maxH = Math.min(380, window.innerHeight - 260);
			var scale = Math.min(maxW / img.width, maxH / img.height, 1);
			canvas.width  = Math.round(img.width  * scale);
			canvas.height = Math.round(img.height * scale);
			dispScale = scale;

			// Initial crop: ~98% of image so corner handles are visible at edges
			if (imgType === 'photo') {
				var sz = Math.round(Math.min(img.width, img.height) * 0.98);
				cropBox = { x: Math.round((img.width - sz) / 2), y: Math.round((img.height - sz) / 2), w: sz, h: sz };
			} else {
				var inX = Math.round(img.width  * 0.01);
				var inY = Math.round(img.height * 0.01);
				cropBox = { x: inX, y: inY, w: img.width - inX * 2, h: img.height - inY * 2 };
			}
			drawCrop();
			bindCropEvents(canvas);
		}

		function drawCrop() {
			var canvas = gid('pn-crop-canvas');
			var ctx = canvas.getContext('2d');
			var sc = dispScale, cb = cropBox;
			var cx = Math.round(cb.x * sc), cy = Math.round(cb.y * sc);
			var cw = Math.round(cb.w * sc), ch = Math.round(cb.h * sc);

			ctx.clearRect(0, 0, canvas.width, canvas.height);
			ctx.drawImage(origImg, 0, 0, canvas.width, canvas.height);

			// Dim outside crop
			ctx.fillStyle = 'rgba(0,0,0,0.52)';
			ctx.fillRect(0, 0, canvas.width, cy);
			ctx.fillRect(0, cy + ch, canvas.width, canvas.height - cy - ch);
			ctx.fillRect(0, cy, cx, ch);
			ctx.fillRect(cx + cw, cy, canvas.width - cx - cw, ch);

			// Crop border
			ctx.strokeStyle = 'rgba(255,255,255,0.9)';
			ctx.lineWidth = 1.5;
			ctx.strokeRect(cx + 0.5, cy + 0.5, cw - 1, ch - 1);

			// Rule-of-thirds
			ctx.strokeStyle = 'rgba(255,255,255,0.3)';
			ctx.lineWidth = 1;
			ctx.beginPath();
			ctx.moveTo(cx + cw/3, cy); ctx.lineTo(cx + cw/3, cy + ch);
			ctx.moveTo(cx + 2*cw/3, cy); ctx.lineTo(cx + 2*cw/3, cy + ch);
			ctx.moveTo(cx, cy + ch/3); ctx.lineTo(cx + cw, cy + ch/3);
			ctx.moveTo(cx, cy + 2*ch/3); ctx.lineTo(cx + cw, cy + 2*ch/3);
			ctx.stroke();

			// Corner handles
			var hs = 8;
			ctx.fillStyle = '#fff';
			ctx.strokeStyle = 'rgba(0,0,0,0.25)';
			ctx.lineWidth = 1;
			[[cx, cy], [cx + cw, cy], [cx, cy + ch], [cx + cw, cy + ch]].forEach(function(pt) {
				ctx.fillRect(pt[0] - hs/2, pt[1] - hs/2, hs, hs);
				ctx.strokeRect(pt[0] - hs/2, pt[1] - hs/2, hs, hs);
			});
		}

		function getCanvasPos(canvas, e) {
			var rect = canvas.getBoundingClientRect();
			var src = e.touches ? e.touches[0] : e;
			return {
				x: (src.clientX - rect.left) * (canvas.width  / rect.width),
				y: (src.clientY - rect.top)  * (canvas.height / rect.height)
			};
		}

		function hitHandle(mx, my) {
			var sc = dispScale, cb = cropBox, hs = 12;
			var cx = cb.x * sc, cy = cb.y * sc, cw = cb.w * sc, ch = cb.h * sc;
			var corners = [
				{ name: 'nw', x: cx,      y: cy      },
				{ name: 'ne', x: cx + cw, y: cy      },
				{ name: 'sw', x: cx,      y: cy + ch },
				{ name: 'se', x: cx + cw, y: cy + ch }
			];
			for (var i = 0; i < corners.length; i++) {
				if (Math.abs(mx - corners[i].x) <= hs && Math.abs(my - corners[i].y) <= hs)
					return corners[i].name;
			}
			if (mx >= cx && mx <= cx + cw && my >= cy && my <= cy + ch) return 'move';
			return null;
		}

		function bindCropEvents(canvas) {
			if (cropBound) {
				canvas.removeEventListener('mousedown',  cropBound.down);
				canvas.removeEventListener('touchstart', cropBound.down);
				window.removeEventListener('mousemove',  cropBound.move);
				window.removeEventListener('touchmove',  cropBound.move);
				window.removeEventListener('mouseup',    cropBound.up);
				window.removeEventListener('touchend',   cropBound.up);
			}
			var ds = null;

			function onDown(e) {
				e.preventDefault();
				var pos = getCanvasPos(canvas, e);
				var hit = hitHandle(pos.x, pos.y);
				if (hit) ds = { handle: hit, startMX: pos.x, startMY: pos.y, startCrop: { x: cropBox.x, y: cropBox.y, w: cropBox.w, h: cropBox.h } };
			}

			function onMove(e) {
				if (!ds) return;
				e.preventDefault();
				var pos = getCanvasPos(canvas, e);
				var dx = (pos.x - ds.startMX) / dispScale;
				var dy = (pos.y - ds.startMY) / dispScale;
				var s = ds.startCrop, img = origImg, MIN = 20;
				var lockAspect = (imgType === 'photo');

				if (ds.handle === 'move') {
					cropBox.x = Math.max(0, Math.min(img.width  - s.w, s.x + dx));
					cropBox.y = Math.max(0, Math.min(img.height - s.h, s.y + dy));
				} else {
					var nx = s.x, ny = s.y, nw = s.w, nh = s.h;
					if (ds.handle === 'se') {
						nw = Math.max(MIN, s.w + dx); nh = lockAspect ? nw : Math.max(MIN, s.h + dy);
					} else if (ds.handle === 'sw') {
						nw = Math.max(MIN, s.w - dx); nh = lockAspect ? nw : Math.max(MIN, s.h + dy); nx = s.x + s.w - nw;
					} else if (ds.handle === 'ne') {
						nw = Math.max(MIN, s.w + dx); nh = lockAspect ? nw : Math.max(MIN, s.h - dy); ny = s.y + s.h - nh;
					} else { // nw
						nw = Math.max(MIN, s.w - dx); nh = lockAspect ? nw : Math.max(MIN, s.h - dy); nx = s.x + s.w - nw; ny = s.y + s.h - nh;
					}
					nx = Math.max(0, nx); ny = Math.max(0, ny);
					nw = Math.min(nw, img.width  - nx); nh = Math.min(nh, img.height - ny);
					cropBox.x = nx; cropBox.y = ny; cropBox.w = nw; cropBox.h = nh;
				}
				drawCrop();
			}

			function onUp() { ds = null; }

			cropBound = { down: onDown, move: onMove, up: onUp };
			canvas.addEventListener('mousedown',  onDown);
			canvas.addEventListener('touchstart', onDown, { passive: false });
			window.addEventListener('mousemove',  onMove);
			window.addEventListener('touchmove',  onMove, { passive: false });
			window.addEventListener('mouseup',    onUp);
			window.addEventListener('touchend',   onUp);
		}

		// ---- Upload ----
		function doUploadCropped() {
			var cb = cropBox;
			var outCanvas = document.createElement('canvas');
			outCanvas.width  = Math.round(cb.w);
			outCanvas.height = Math.round(cb.h);
			outCanvas.getContext('2d').drawImage(origImg, cb.x, cb.y, cb.w, cb.h, 0, 0, cb.w, cb.h);
			outCanvas.toBlob(function(blob) {
				if (blob.size > 348836) {
					resizeImageToLimit(blob, 348836, doUpload, function(err) {
						showStep('pn-img-step-select');
						showError(err);
					}, false);
				} else {
					doUpload(blob);
				}
			}, 'image/jpeg', 0.88);
		}

		function doUpload(blob) {
			showStep('pn-img-step-uploading');
			var fd = new FormData();
			fd.append('Update', 'Update Media');
			fd.append(imgType === 'photo' ? 'PlayerImage' : 'Heraldry', blob, 'image.jpg');
			fetch(UPLOAD_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					showStep('pn-img-step-success');
					setTimeout(function() { window.location.reload(); }, 1400);
				})
				.catch(function(err) {
					showStep('pn-img-step-select');
					showError('Upload failed: ' + err.message);
				});
		}
	})();
	<?php endif; ?>

	<?php if ($canEditAccount): ?>
	// ---- Update Account Modal ----
	(function() {
		var SAVE_URL = '<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>/update';

		function gid(id) { return document.getElementById(id); }

		window.pnOpenAccountModal = function() {
			gid('pn-acct-error').style.display = 'none';
			gid('pn-acct-error').textContent = '';
			gid('pn-acct-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseAccountModal = function() {
			gid('pn-acct-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-acct-close-btn').addEventListener('click', pnCloseAccountModal);
		gid('pn-acct-cancel').addEventListener('click', pnCloseAccountModal);
		gid('pn-acct-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseAccountModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-acct-overlay').classList.contains('pn-open'))
				pnCloseAccountModal();
		});

		gid('pn-acct-save').addEventListener('click', function() {
			var persona   = gid('pn-acct-persona').value.trim();
			var username  = gid('pn-acct-username').value.trim();
			var password  = gid('pn-acct-password').value;
			var password2 = gid('pn-acct-password2').value;
			var errEl = gid('pn-acct-error');

			// Client-side validation
			if (!persona) {
				errEl.textContent = 'Persona is required.';
				errEl.style.display = '';
				gid('pn-acct-persona').focus();
				return;
			}
			if (!username) {
				errEl.textContent = 'Username is required.';
				errEl.style.display = '';
				gid('pn-acct-username').focus();
				return;
			}
			if (password !== password2) {
				errEl.textContent = 'Passwords do not match.';
				errEl.style.display = '';
				gid('pn-acct-password').focus();
				return;
			}
			errEl.style.display = 'none';

			// Collect all fields in the modal body
			var fd = new FormData();
			fd.append('Update', 'Update Details');
			var modal = gid('pn-acct-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				if (el.type === 'checkbox') {
					if (el.checked) fd.append(el.name, el.value);
					// unchecked checkboxes send nothing — controller treats missing as 0
					return;
				}
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-acct-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(SAVE_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					// Reload to reflect changes
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				});
		});
	})();
	<?php endif; ?>

	<?php if ($canEditAdmin): ?>
	// ---- Add Dues Modal ----
	(function() {
		var DUES_URL = '<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>/adddues';

		function gid(id) { return document.getElementById(id); }

		// Add N months to a YYYY-MM-DD string, returns YYYY-MM-DD
		function addMonths(dateStr, n) {
			var p = dateStr.split('-');
			if (p.length !== 3) return '';
			var d = new Date(parseInt(p[0], 10), parseInt(p[1], 10) - 1 + n, parseInt(p[2], 10));
			return d.getFullYear() + '-' +
				String(d.getMonth() + 1).padStart(2, '0') + '-' +
				String(d.getDate()).padStart(2, '0');
		}

		function isForLife() {
			var checked = document.querySelector('#pn-dues-overlay input[name="DuesForLife"]:checked');
			return checked && checked.value === '1';
		}

		function updateDuesPreview() {
			var el = gid('pn-dues-until-preview');
			if (!el) return;
			if (isForLife()) {
				el.innerHTML = '<i class="fas fa-infinity" style="margin-right:4px"></i>Paid for life';
				return;
			}
			var from   = gid('pn-dues-from').value;
			var months = parseInt(gid('pn-dues-months').value, 10);
			if (!from || isNaN(months) || months < 1) { el.textContent = ''; return; }
			var until = addMonths(from, months);
			el.innerHTML = 'Paid through: <strong>' + until + '</strong>';
		}

		function syncMonthsRow() {
			gid('pn-dues-months-row').style.display = isForLife() ? 'none' : '';
			updateDuesPreview();
		}

		window.pnOpenDuesModal = function() {
			gid('pn-dues-error').style.display = 'none';
			gid('pn-dues-error').textContent = '';
			// Reset to defaults
			var today = new Date();
			gid('pn-dues-from').value = today.getFullYear() + '-' +
				String(today.getMonth() + 1).padStart(2, '0') + '-' +
				String(today.getDate()).padStart(2, '0');
			gid('pn-dues-months').value = '6';
			document.querySelectorAll('#pn-dues-overlay input[name="DuesForLife"]').forEach(function(r) {
				r.checked = (r.value === '0');
			});
			syncMonthsRow();
			gid('pn-dues-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseDuesModal = function() {
			gid('pn-dues-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-dues-close-btn').addEventListener('click', pnCloseDuesModal);
		gid('pn-dues-cancel').addEventListener('click', pnCloseDuesModal);
		gid('pn-dues-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseDuesModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-dues-overlay').classList.contains('pn-open'))
				pnCloseDuesModal();
		});

		// Live preview wiring
		gid('pn-dues-from').addEventListener('input', updateDuesPreview);
		gid('pn-dues-months').addEventListener('input', updateDuesPreview);
		document.querySelectorAll('#pn-dues-overlay input[name="DuesForLife"]').forEach(function(r) {
			r.addEventListener('change', syncMonthsRow);
		});

		gid('pn-dues-save').addEventListener('click', function() {
			var duesFrom = gid('pn-dues-from').value.trim();
			var errEl    = gid('pn-dues-error');

			if (!duesFrom) {
				errEl.textContent = 'Date Paid is required.';
				errEl.style.display = '';
				gid('pn-dues-from').focus();
				return;
			}
			errEl.style.display = 'none';

			var fd = new FormData();
			var modal = gid('pn-dues-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				// Skip Months when Dues For Life is selected
				if (el.name === 'Months' && isForLife()) return;
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-dues-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(DUES_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Add Dues';
				});
		});
	})();
	<?php endif; ?>

	<?php if ($canEditAdmin): ?>
	// ---- Edit Qualifications Modal ----
	(function() {
		var SAVE_URL = '<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>/update';

		function gid(id) { return document.getElementById(id); }

		function syncUntilRow(radioName, rowId) {
			var checked = document.querySelector('#pn-qual-overlay input[name="' + radioName + '"]:checked');
			var row = gid(rowId);
			var qualified = checked && checked.value === '1';
			row.style.opacity = qualified ? '' : '0.35';
			row.style.pointerEvents = qualified ? '' : 'none';
		}

		function syncAll() {
			syncUntilRow('ReeveQualified',   'pn-qual-reeve-until-row');
			syncUntilRow('CorporaQualified', 'pn-qual-corpora-until-row');
		}

		window.pnOpenQualModal = function() {
			gid('pn-qual-error').style.display = 'none';
			gid('pn-qual-error').textContent = '';
			syncAll();
			gid('pn-qual-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseQualModal = function() {
			gid('pn-qual-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-qual-close-btn').addEventListener('click', pnCloseQualModal);
		gid('pn-qual-cancel').addEventListener('click', pnCloseQualModal);
		gid('pn-qual-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseQualModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-qual-overlay').classList.contains('pn-open'))
				pnCloseQualModal();
		});

		document.querySelectorAll('#pn-qual-overlay input[name="ReeveQualified"]').forEach(function(r) {
			r.addEventListener('change', function() { syncUntilRow('ReeveQualified', 'pn-qual-reeve-until-row'); });
		});
		document.querySelectorAll('#pn-qual-overlay input[name="CorporaQualified"]').forEach(function(r) {
			r.addEventListener('change', function() { syncUntilRow('CorporaQualified', 'pn-qual-corpora-until-row'); });
		});

		gid('pn-qual-save').addEventListener('click', function() {
			var errEl = gid('pn-qual-error');
			errEl.style.display = 'none';

			var fd = new FormData();
			var modal = gid('pn-qual-overlay');
			modal.querySelectorAll('input[name], select[name], textarea[name]').forEach(function(el) {
				if (el.type === 'radio' && !el.checked) return;
				fd.append(el.name, el.value);
			});

			var btn = gid('pn-qual-save');
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving\u2026';

			fetch(SAVE_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					window.location.reload();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
					btn.disabled = false;
					btn.innerHTML = '<i class="fas fa-save"></i> Save Changes';
				});
		});
	})();
	<?php endif; ?>

	<?php if ($canEditAdmin): ?>
	// ---- Add Award / Add Title Modal ----
	(function() {
		var AWARD_URL     = '<?= UIR ?>Admin/player/<?= (int)$Player['MundaneId'] ?>/addaward';
		var SEARCH_URL    = '<?= HTTP_SERVICE ?>Search/SearchService.php';
		var KINGDOM_ID    = <?= (int)($KingdomId ?? 0) ?>;
		// Player's held award ranks: canonical AwardId => max rank
		var playerRanks   = <?= json_encode($playerAwardRanks) ?>;
		// Award option lists as HTML strings for swapping
		var awardOptHTML   = <?= json_encode('<option value="">Select award...</option>' . ($AwardOptions ?? '')) ?>;
		var officerOptHTML = <?= json_encode('<option value="">Select title...</option>' . ($OfficerOptions ?? '')) ?>;

		var currentType = 'awards';

		function gid(id) { return document.getElementById(id); }

		// ---- Award Type Toggle ----
		function setAwardType(type) {
			currentType = type;
			var isOfficer = (type === 'officers');
			gid('pn-award-type-awards').classList.toggle('pn-active', !isOfficer);
			gid('pn-award-type-officers').classList.toggle('pn-active', isOfficer);
			gid('pn-award-modal-title').innerHTML = isOfficer
				? '<i class="fas fa-crown" style="margin-right:8px;color:#553c9a"></i>Add Officer Title'
				: '<i class="fas fa-trophy" style="margin-right:8px;color:#2c5282"></i>Add Award';
			gid('pn-award-save-label').textContent = isOfficer ? 'Add Title' : 'Add Award';
			gid('pn-award-select').innerHTML = isOfficer ? officerOptHTML : awardOptHTML;
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-custom-row').style.display  = 'none';
			gid('pn-award-info-line').innerHTML       = '';
			gid('pn-award-rank-val').value            = '';
			checkRequired();
		}
		gid('pn-award-type-awards').addEventListener('click',   function() { setAwardType('awards'); });
		gid('pn-award-type-officers').addEventListener('click', function() { setAwardType('officers'); });

		// ---- Award Select Change ----
		gid('pn-award-select').addEventListener('change', function() {
			var opt      = this.options[this.selectedIndex];
			var isLadder = (opt.getAttribute('data-is-ladder') == '1');
			var awardId  = parseInt(opt.getAttribute('data-award-id')) || 0;
			var isCustom = (opt.text.indexOf('Custom Award') !== -1);

			gid('pn-award-custom-row').style.display  = isCustom ? '' : 'none';
			gid('pn-award-info-line').innerHTML        = isLadder
				? '<span class="pn-badge-ladder"><i class="fas fa-chart-line"></i> Ladder Award</span>'
				: '';

			if (isLadder && this.value) {
				gid('pn-award-rank-row').style.display = '';
				buildRankPills(awardId);
			} else {
				gid('pn-award-rank-row').style.display = 'none';
				gid('pn-award-rank-val').value = '';
			}
			checkRequired();
		});

		// ---- Rank Pills ----
		function buildRankPills(awardId) {
			var held      = playerRanks[awardId] || 0;
			var suggested = Math.min(held + 1, 10);
			var html = '';
			for (var i = 1; i <= 10; i++) {
				var cls = 'pn-rank-pill';
				if (i <= held)       cls += ' pn-rank-held';
				if (i === suggested) cls += ' pn-rank-suggested';
				html += '<div class="' + cls + '" data-rank="' + i + '">' + i + '</div>';
			}
			var pills = gid('pn-rank-pills');
			pills.innerHTML = html;
			selectRankPill(suggested, pills);
		}
		function selectRankPill(rank, container) {
			var c = container || gid('pn-rank-pills');
			c.querySelectorAll('.pn-rank-pill').forEach(function(p) { p.classList.remove('pn-rank-selected'); });
			var target = c.querySelector('[data-rank="' + rank + '"]');
			if (target) {
				target.classList.add('pn-rank-selected');
				gid('pn-award-rank-val').value = rank;
			}
		}
		gid('pn-rank-pills').addEventListener('click', function(e) {
			var pill = e.target.closest ? e.target.closest('.pn-rank-pill') : (e.target.classList.contains('pn-rank-pill') ? e.target : null);
			if (!pill) return;
			selectRankPill(parseInt(pill.dataset.rank));
		});

		// ---- Given By: Officer quick chips ----
		document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(chip) {
			chip.addEventListener('click', function() {
				document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
				this.classList.add('pn-selected');
				gid('pn-award-givenby-text').value = this.dataset.name;
				gid('pn-award-givenby-id').value   = this.dataset.id;
				gid('pn-award-givenby-results').classList.remove('pn-ac-open');
				checkRequired();
			});
		});

		// ---- Given By: search autocomplete ----
		var givenByTimer;
		gid('pn-award-givenby-text').addEventListener('input', function() {
			clearTimeout(givenByTimer);
			gid('pn-award-givenby-id').value = '';
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			checkRequired();
			var term = this.value.trim();
			if (term.length < 2) { gid('pn-award-givenby-results').classList.remove('pn-ac-open'); return; }
			givenByTimer = setTimeout(function() {
				var url = SEARCH_URL + '?Action=Search%2FPlayer&type=all&search=' + encodeURIComponent(term) + '&kingdom_id=' + KINGDOM_ID + '&limit=6';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					var results = gid('pn-award-givenby-results');
					if (!data || !data.length) {
						results.innerHTML = '<div class="pn-ac-no-results">No players found</div>';
					} else {
						results.innerHTML = data.map(function(p) {
							return '<div class="pn-ac-item" data-id="' + p.MundaneId + '" data-name="' + encodeURIComponent(p.Persona) + '">'
								+ p.Persona
								+ ' <span style="color:#a0aec0;font-size:11px">(' + (p.KAbbr || '') + ':' + (p.PAbbr || '') + ')</span>'
								+ '</div>';
						}).join('');
					}
					results.classList.add('pn-ac-open');
				}).catch(function() {});
			}, 250);
		});
		gid('pn-award-givenby-results').addEventListener('click', function(e) {
			var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
			if (!item) return;
			gid('pn-award-givenby-text').value = decodeURIComponent(item.dataset.name);
			gid('pn-award-givenby-id').value   = item.dataset.id;
			this.classList.remove('pn-ac-open');
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			checkRequired();
		});

		// ---- Given At: location autocomplete ----
		var givenAtTimer;
		gid('pn-award-givenat-text').addEventListener('input', function() {
			clearTimeout(givenAtTimer);
			gid('pn-award-park-id').value    = '0';
			gid('pn-award-kingdom-id').value = '0';
			gid('pn-award-event-id').value   = '0';
			var term = this.value.trim();
			if (term.length < 2) { gid('pn-award-givenat-results').classList.remove('pn-ac-open'); return; }
			givenAtTimer = setTimeout(function() {
				var url = SEARCH_URL + '?Action=Search%2FLocation&type=all&name=' + encodeURIComponent(term) + '&limit=8';
				fetch(url).then(function(r) { return r.json(); }).then(function(data) {
					var results = gid('pn-award-givenat-results');
					if (!data || !data.length) {
						results.innerHTML = '<div class="pn-ac-no-results">No locations found</div>';
					} else {
						results.innerHTML = data.map(function(loc) {
							return '<div class="pn-ac-item"'
								+ ' data-park="' + (parseInt(loc.ParkId) || 0) + '"'
								+ ' data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '"'
								+ ' data-event="' + (parseInt(loc.EventId) || 0) + '"'
								+ ' data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">'
								+ (loc.LocationName || '') + '</div>';
						}).join('');
					}
					results.classList.add('pn-ac-open');
				}).catch(function() {});
			}, 250);
		});
		gid('pn-award-givenat-results').addEventListener('click', function(e) {
			var item = e.target.closest ? e.target.closest('.pn-ac-item') : (e.target.classList.contains('pn-ac-item') ? e.target : null);
			if (!item) return;
			gid('pn-award-givenat-text').value   = decodeURIComponent(item.dataset.name);
			gid('pn-award-park-id').value         = item.dataset.park    || '0';
			gid('pn-award-kingdom-id').value      = item.dataset.kingdom || '0';
			gid('pn-award-event-id').value         = item.dataset.event   || '0';
			this.classList.remove('pn-ac-open');
		});

		// Close dropdowns when clicking elsewhere inside the overlay
		gid('pn-award-overlay').addEventListener('click', function(e) {
			var givenByInput   = gid('pn-award-givenby-text');
			var givenByResults = gid('pn-award-givenby-results');
			var givenAtInput   = gid('pn-award-givenat-text');
			var givenAtResults = gid('pn-award-givenat-results');
			if (e.target !== givenByInput && !givenByResults.contains(e.target))
				givenByResults.classList.remove('pn-ac-open');
			if (e.target !== givenAtInput && !givenAtResults.contains(e.target))
				givenAtResults.classList.remove('pn-ac-open');
		});

		// ---- Note char counter ----
		gid('pn-award-note').addEventListener('input', function() {
			var rem = 400 - this.value.length;
			var el  = gid('pn-award-char-count');
			el.textContent = rem + ' character' + (rem !== 1 ? 's' : '') + ' remaining';
			el.classList.toggle('pn-char-warn', rem < 50);
		});

		// ---- Required field check ----
		function checkRequired() {
			var ok = !!gid('pn-award-select').value && !!gid('pn-award-givenby-id').value && !!gid('pn-award-date').value;
			gid('pn-award-save-new').disabled  = !ok;
			gid('pn-award-save-same').disabled = !ok;
		}
		gid('pn-award-select').addEventListener('change', checkRequired);
		gid('pn-award-date').addEventListener('change',   checkRequired);
		gid('pn-award-date').addEventListener('input',    checkRequired);

		// ---- Open / Close ----
		window.pnOpenAwardModal = function(type) {
			// Reset
			gid('pn-award-error').style.display   = 'none';
			gid('pn-award-error').textContent      = '';
			gid('pn-award-success').style.display  = 'none';
			gid('pn-award-note').value            = '';
			gid('pn-award-char-count').textContent = '400 characters remaining';
			gid('pn-award-char-count').classList.remove('pn-char-warn');
			gid('pn-award-givenby-text').value    = '';
			gid('pn-award-givenby-id').value      = '';
			gid('pn-award-givenby-results').classList.remove('pn-ac-open');
			gid('pn-award-givenat-text').value    = <?= json_encode($this->__session->park_name ?? '') ?>;
			gid('pn-award-park-id').value         = '<?= (int)$Player['ParkId'] ?>';
			gid('pn-award-kingdom-id').value      = '0';
			gid('pn-award-event-id').value        = '0';
			gid('pn-award-givenat-results').classList.remove('pn-ac-open');
			gid('pn-award-custom-name').value     = '';
			gid('pn-award-custom-row').style.display = 'none';
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-rank-val').value           = '';
			gid('pn-award-info-line').innerHTML      = '';
			document.querySelectorAll('#pn-award-officer-chips .pn-officer-chip').forEach(function(c) { c.classList.remove('pn-selected'); });
			// Default date = today
			var today = new Date();
			gid('pn-award-date').value = today.getFullYear() + '-'
				+ String(today.getMonth() + 1).padStart(2, '0') + '-'
				+ String(today.getDate()).padStart(2, '0');
			// Set type and render
			setAwardType(type || 'awards');
			checkRequired();
			gid('pn-award-overlay').classList.add('pn-open');
			document.body.style.overflow = 'hidden';
		};
		window.pnCloseAwardModal = function() {
			gid('pn-award-overlay').classList.remove('pn-open');
			document.body.style.overflow = '';
		};

		gid('pn-award-close-btn').addEventListener('click', pnCloseAwardModal);
		gid('pn-award-cancel').addEventListener('click',    pnCloseAwardModal);
		gid('pn-award-overlay').addEventListener('click', function(e) {
			if (e.target === this) pnCloseAwardModal();
		});
		document.addEventListener('keydown', function(e) {
			if ((e.key === 'Escape' || e.keyCode === 27) && gid('pn-award-overlay').classList.contains('pn-open'))
				pnCloseAwardModal();
		});

		// ---- Save helpers ----
		var pnSuccessTimer = null;
		function pnShowSuccess() {
			var el = gid('pn-award-success');
			el.style.display = '';
			clearTimeout(pnSuccessTimer);
			pnSuccessTimer = setTimeout(function() { el.style.display = 'none'; }, 3000);
		}
		function pnClearAward() {
			gid('pn-award-select').value             = '';
			gid('pn-award-rank-val').value           = '';
			gid('pn-award-rank-row').style.display   = 'none';
			gid('pn-award-rank-pills').innerHTML     = '';
			gid('pn-award-note').value               = '';
			gid('pn-award-char-count').textContent   = '400 characters remaining';
			gid('pn-award-char-count').classList.remove('pn-char-warn');
			gid('pn-award-info-line').innerHTML      = '';
			gid('pn-award-custom-name').value        = '';
			gid('pn-award-custom-row').style.display = 'none';
			checkRequired();
			gid('pn-award-select').focus();
		}
		function pnDoSave(onSuccess) {
			var errEl   = gid('pn-award-error');
			var awardId = gid('pn-award-select').value;
			var giverId = gid('pn-award-givenby-id').value;
			var date    = gid('pn-award-date').value;

			errEl.style.display = 'none';
			if (!awardId) { errEl.textContent = 'Please select an award.';            errEl.style.display = ''; return; }
			if (!giverId) { errEl.textContent = 'Please select who gave this award.'; errEl.style.display = ''; return; }
			if (!date)    { errEl.textContent = 'Please enter the award date.';       errEl.style.display = ''; return; }

			var fd = new FormData();
			fd.append('KingdomAwardId', awardId);
			fd.append('GivenById',      giverId);
			fd.append('Date',           date);
			fd.append('ParkId',         gid('pn-award-park-id').value    || '0');
			fd.append('KingdomId',      gid('pn-award-kingdom-id').value || '0');
			fd.append('EventId',        gid('pn-award-event-id').value   || '0');
			fd.append('Note',           gid('pn-award-note').value       || '');
			var rank = gid('pn-award-rank-val').value;
			if (rank) fd.append('Rank', rank);
			var customName = gid('pn-award-custom-name').value.trim();
			if (customName) fd.append('AwardName', customName);

			var btnNew  = gid('pn-award-save-new');
			var btnSame = gid('pn-award-save-same');
			btnNew.disabled = btnSame.disabled = true;
			btnNew.innerHTML  = '<i class="fas fa-spinner fa-spin"></i>';
			btnSame.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

			fetch(AWARD_URL, { method: 'POST', body: fd })
				.then(function(resp) {
					if (!resp.ok) throw new Error('Server returned ' + resp.status);
					onSuccess();
				})
				.catch(function(err) {
					errEl.textContent = 'Save failed: ' + err.message;
					errEl.style.display = '';
				})
				.finally(function() {
					btnNew.innerHTML  = '<i class="fas fa-plus"></i> Add + New Player';
					btnSame.innerHTML = '<i class="fas fa-plus"></i> Add + Same Player';
					checkRequired();
				});
		}
		// "Add + New Player" — clear award/rank/note, keep date/giver/location
		gid('pn-award-save-new').addEventListener('click', function() {
			pnDoSave(function() { pnShowSuccess(); pnClearAward(); });
		});
		// "Add + Same Player" — clear award/rank/note, keep player+date+giver+location
		gid('pn-award-save-same').addEventListener('click', function() {
			pnDoSave(function() { pnShowSuccess(); pnClearAward(); });
		});
	})();
	<?php endif; ?>

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
	// Classes table: click-to-sort without pagination
	$('#pn-classes-table thead th').on('click', function() {
		var $th    = $(this);
		var $table = $('#pn-classes-table');
		var col    = $th.index();
		var stype  = $th.data('sorttype') || 'text';
		var isAsc  = !$th.hasClass('sort-asc');
		$table.find('thead th').removeClass('sort-asc sort-desc');
		$th.addClass(isAsc ? 'sort-asc' : 'sort-desc');
		var $tbody = $table.find('tbody');
		var rows   = $tbody.find('tr').get();
		rows.sort(function(a, b) {
			var av = $(a).find('td').eq(col).text().trim();
			var bv = $(b).find('td').eq(col).text().trim();
			var cmp = stype === 'numeric'
				? (parseFloat(av) || 0) - (parseFloat(bv) || 0)
				: av.localeCompare(bv);
			return isAsc ? cmp : -cmp;
		});
		$.each(rows, function(i, row) { $tbody.append(row); });
	});
	<?php endif; ?>


});
</script>
