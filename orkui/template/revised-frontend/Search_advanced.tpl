<?php
/* ── Advanced Player Search — full-page tool (revised-frontend) ──────────────
 * Plain-PHP .tpl (extract()+include). NO Smarty.
 * Data provided by controller.Search::advanced():
 *   $CanSeeRealName, $CanSeeBanned, $IsLoggedIn (0/1)
 *   $PrefillQ (string), $PrefillKingdomId (int), $PrefillParkId (int)
 * Reached from the OrkPlayerSearch dropdown footer and the header universal
 * search ("Advanced Player Search"), carrying the typed term (+ surface scope).
 * Layout: the shared .rp-* tool shell (default/style/reports.css); colours come
 * from --rp-* / --ork-* tokens, which already flip under html[data-theme="dark"].
 * ─────────────────────────────────────────────────────────────────────────── */

$_canSeeRealName = !empty($CanSeeRealName) ? 1 : 0;
$_canSeeBanned   = !empty($CanSeeBanned)   ? 1 : 0;
$_prefillQ       = (string)($PrefillQ ?? '');
$_prefillKId     = (int)($PrefillKingdomId ?? 0);
$_prefillPId     = (int)($PrefillParkId    ?? 0);
$_isLoggedIn     = !empty($IsLoggedIn) ? 1 : 0;
$_autoRun        = (trim($_prefillQ) !== '' || $_prefillKId > 0 || $_prefillPId > 0) ? 1 : 0;
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">

<style>
/* ── Advanced Search (as-*) — tokens only, so light + dark come from the shell ── */
.as-filter-body {
	display     : flex;
	flex-wrap   : wrap;
	gap         : 14px 18px;
	align-items : flex-end;
}
.as-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
.as-field-grow  { flex: 1 1 260px; }
.as-field-dates { flex: 0 1 320px; }
.as-field-label {
	font-size      : 11px;
	font-weight    : 700;
	text-transform : uppercase;
	letter-spacing : 0.04em;
	color          : var(--rp-text-muted);
}
.as-input, .as-select {
	width         : 100%;
	min-width     : 0;
	box-sizing    : border-box;
	border        : 1px solid var(--ork-input-border);
	border-radius : 6px;
	padding       : 8px 10px;
	font-size     : 13px;
	color         : var(--rp-text);
	background    : var(--ork-input-bg);
	outline       : none;
	transition    : border-color 0.15s;
}
.as-select { min-width: 170px; }
.as-input::placeholder { color: var(--rp-text-muted); opacity: 0.85; }
.as-input:focus, .as-select:focus { border-color: var(--rp-accent-mid); }
.as-select:disabled { opacity: 0.55; cursor: not-allowed; }
.as-hint { font-size: 11px; color: var(--rp-text-hint); }
.as-hint button {
	background : none;
	border     : none;
	padding    : 0;
	font       : inherit;
	color      : var(--rp-accent);
	cursor     : pointer;
}
.as-hint button:hover { text-decoration: underline; }

.as-toggles { display: flex; flex-wrap: wrap; gap: 14px; padding: 7px 0; }
.as-toggle {
	display     : flex;
	align-items : center;
	gap         : 6px;
	font-size   : 13px;
	color       : var(--rp-text-body);
	cursor      : pointer;
	user-select : none;
}
.as-toggle input { width: 15px; height: 15px; accent-color: var(--rp-accent); cursor: pointer; }
/* Officer-only filter shown to everyone else, greyed out with an instant data-tip. */
.as-toggle-locked { position: relative; color: var(--rp-text-hint); cursor: not-allowed; }
.as-toggle-locked input { cursor: not-allowed; opacity: 0.55; }
.as-toggle-locked .fa-lock { font-size: 11px; }
.as-toggle-locked::after {
	content        : attr(data-tip);
	position       : absolute;
	bottom         : calc(100% + 6px);
	left           : 0;
	white-space    : normal;
	width          : max-content;
	max-width      : 240px;
	background     : var(--rp-text);
	color          : var(--rp-bg-table, var(--ork-card-bg));
	font-size      : 11px;
	font-weight    : 500;
	line-height    : 1.35;
	text-align     : left;
	padding        : 6px 9px;
	border-radius  : 6px;
	box-shadow     : var(--ork-shadow-dropdown);
	opacity        : 0;
	visibility     : hidden;
	transition     : opacity 0.08s;
	z-index        : 60;
	pointer-events : none;
}
.as-toggle-locked:hover::after,
.as-toggle-locked:focus-visible::after { opacity: 1; visibility: visible; }
.as-sr-only {
	position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px;
	overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0;
}

.as-dates { display: flex; gap: 8px; }
.as-dates .as-input { flex: 1 1 0; }

.as-btn-search {
	display       : inline-flex;
	align-items   : center;
	gap           : 7px;
	padding       : 9px 18px;
	background    : var(--rp-accent);
	color         : #fff;
	border        : none;
	border-radius : 6px;
	font-size     : 13px;
	font-weight   : 700;
	cursor        : pointer;
	transition    : background 0.15s;
}
.as-btn-search:hover    { background: var(--rp-accent-mid); }
.as-btn-search:disabled { opacity: 0.6; cursor: progress; }
html[data-theme="dark"] .as-btn-search { color: #1a202c; }

/* ── Result states ── */
.as-state {
	text-align : center;
	padding    : 48px 24px;
	color      : var(--rp-text-muted);
	font-size  : 14px;
}
.as-state i { font-size: 28px; display: block; margin-bottom: 12px; opacity: 0.5; }
.as-result-count { font-size: 12px; color: var(--rp-text-muted); margin-bottom: 10px; }
.as-result-count.as-trunc-note { color: var(--ork-badge-orange-text); }

/* ── Status pills (same states as the inline player-search badges) ── */
.as-pill {
	display       : inline-block;
	padding       : 2px 9px;
	border-radius : 20px;
	font-size     : 11px;
	font-weight   : 700;
	line-height   : 1.5;
	white-space   : nowrap;
}
.as-pill-active    { background: var(--ork-badge-green-bg);  color: var(--ork-badge-green-text); }
.as-pill-inactive  { background: var(--ork-badge-gray-bg);   color: var(--ork-badge-gray-text); }
.as-pill-suspended { background: var(--ork-badge-orange-bg); color: var(--ork-badge-orange-text); }
.as-pill-banned    { background: var(--ork-badge-red-bg);    color: var(--ork-badge-red-text); }

.as-muted { color: var(--rp-text-hint); }
.as-close { font-size: 10px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--rp-text-muted); white-space: nowrap; }
.as-link { color: var(--rp-accent); text-decoration: none; }
.as-link:hover { color: var(--rp-accent-mid); text-decoration: underline; }

/* ── Row actions ── */
.as-act-wrap { display: inline-flex; align-items: center; gap: 8px; }
.as-icon-btn {
	display         : inline-flex;
	align-items     : center;
	justify-content : center;
	min-width       : 30px;
	min-height      : 30px;
	box-sizing      : border-box;
	background      : transparent;
	border          : 1px solid var(--rp-border-mid);
	border-radius   : 5px;
	padding         : 4px 8px;
	font-size       : 12px;
	color           : var(--rp-text-body);
	cursor          : pointer;
	text-decoration : none;
	transition      : background 0.12s, border-color 0.12s, color 0.12s;
}
.as-icon-btn:hover { background: var(--rp-bg-light); border-color: var(--rp-border-strong); color: var(--rp-text); }
.as-copy-btn.as-copied {
	background   : var(--ork-badge-green-bg);
	border-color : var(--ork-badge-green-bg);
	color        : var(--ork-badge-green-text);
}

/* ── data-tip tooltips (instant, wrapping; never native title) ──
   Inverted from the page: dark chip in light mode, light chip in dark mode. */
.as-act-wrap [data-tip] { position: relative; }
.as-act-wrap [data-tip]::after {
	content        : attr(data-tip);
	position       : absolute;
	bottom         : calc(100% + 6px);
	right          : 0;                 /* Actions is the last column: open leftward, never clip */
	white-space    : normal;
	width          : max-content;
	max-width      : 220px;
	background     : var(--rp-text);
	color          : var(--rp-bg-table, var(--ork-card-bg));
	font-size      : 11px;
	font-weight    : 500;
	line-height    : 1.35;
	text-align     : left;
	padding        : 6px 9px;
	border-radius  : 6px;
	box-shadow     : var(--ork-shadow-dropdown);
	opacity        : 0;
	visibility     : hidden;
	transition     : opacity 0.08s;
	z-index        : 60;
	pointer-events : none;
}
.as-act-wrap [data-tip]:hover::after,
.as-act-wrap [data-tip]:focus-visible::after { opacity: 1; visibility: visible; }

/* ── DataTable ──
   The responsive DataTable collapses columns instead of scrolling, so the card
   can drop reports.css's overflow-x:auto — which would otherwise clip the
   Actions tooltips above the first row. */
.rp-table-area { overflow: visible; }
#as-table.dataTable thead th,
#as-table.dataTable tbody td { font-size: 13px; }
#as-table.dataTable tbody tr:nth-child(odd)  > td { background: var(--rp-bg-light); }
#as-table.dataTable tbody tr:nth-child(even) > td { background: var(--rp-bg-table, var(--ork-card-bg)); }
#as-table.dataTable tbody tr:hover > td           { background: var(--rp-bg-tertiary); }
#as-table.dataTable > tbody > tr.child ul.dtr-details { width: 100%; }
#as-table.dataTable > tbody > tr.child span.dtr-title { color: var(--rp-text); }

@media (max-width: 600px) {
	.as-field-grow, .as-field-dates, .as-field-select { flex: 1 1 100%; }
	.as-btn-search { width: 100%; justify-content: center; }
	.as-field-submit { flex: 1 1 100%; }
}
</style>

<div class="rp-root">

	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-search-plus rp-header-icon"></i>
				<h1 class="rp-header-title">Advanced Player Search</h1>
			</div>
<?php if ($_canSeeRealName) : ?>
			<div class="rp-header-scope">
				<span class="rp-scope-chip"><i class="fas fa-user-shield"></i> <span class="rp-scope-chip-label">Officer view: real names<?= $_canSeeBanned ? ' and banned players' : '' ?> shown</span></span>
			</div>
<?php endif; ?>
		</div>
	</div>

	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Search players by persona or name across every kingdom and park. Enter a name, or pick a kingdom, then narrow with the filters. A number on its own looks up that player ID.</span>
	</div>

	<div class="rp-filter-card">
		<div class="rp-filter-card-header"><i class="fas fa-filter"></i> Filters</div>
		<div class="rp-filter-card-body as-filter-body">

			<div class="as-field as-field-grow">
				<label class="as-field-label" for="as-q">Search</label>
				<input type="search" id="as-q" class="as-input" autocomplete="off" enterkeyhint="search"
					placeholder="Persona, name, or player ID"
					value="<?= htmlspecialchars($_prefillQ) ?>">
			</div>

			<div class="as-field">
				<span class="as-field-label">Include</span>
				<div class="as-toggles">
					<label class="as-toggle"><input type="checkbox" id="as-active"   checked> Active</label>
					<label class="as-toggle"><input type="checkbox" id="as-inactive" checked> Inactive</label>
<?php if ($_canSeeBanned) : ?>
					<label class="as-toggle"><input type="checkbox" id="as-banned"   checked> Banned</label>
<?php else : ?>
					<?php /* Shown but locked, so non-officers know banned players exist and are left out. */ ?>
					<label class="as-toggle as-toggle-locked" tabindex="0" data-tip="Officers only: banned and suspended players are hidden from your results.">
						<input type="checkbox" id="as-banned" disabled aria-describedby="as-banned-note"> Banned <i class="fas fa-lock" aria-hidden="true"></i>
						<span id="as-banned-note" class="as-sr-only">Officers only. Banned and suspended players are not shown.</span>
					</label>
<?php endif; ?>
				</div>
			</div>

			<div class="as-field as-field-select">
				<label class="as-field-label" for="as-kingdom">Kingdom</label>
				<select id="as-kingdom" class="as-select">
					<option value="0">All Kingdoms</option>
				</select>
			</div>

			<div class="as-field as-field-select">
				<label class="as-field-label" for="as-park">Park</label>
				<select id="as-park" class="as-select" disabled>
					<option value="0">All Parks</option>
				</select>
			</div>

			<div class="as-field as-field-dates">
				<label class="as-field-label" for="as-from">Last Attendance</label>
				<div class="as-dates">
					<input type="text" id="as-from" class="as-input" placeholder="From">
					<input type="text" id="as-to"   class="as-input" placeholder="To">
				</div>
				<span class="as-hint">Leave blank for any date. A date range leaves out players with no attendance. <button type="button" id="as-dates-clear" hidden>Clear dates</button></span>
			</div>

			<div class="as-field as-field-submit">
				<button type="button" class="as-btn-search" id="as-search-btn">
					<i class="fas fa-search"></i> Search
				</button>
			</div>

		</div>
	</div>

	<div class="rp-table-area">
		<div id="as-result-count" class="as-result-count" role="status" aria-live="polite" hidden></div>

		<div id="as-searching" class="as-state" hidden>
			<i class="fas fa-spinner fa-spin"></i>
			Searching&hellip;
		</div>

		<div id="as-prompt" class="as-state">
			<i class="fas fa-keyboard"></i>
			Enter a name or ID, or choose a kingdom, to begin.
		</div>

		<div id="as-empty" class="as-state" hidden>
			<i class="fas fa-user-slash"></i>
			<span id="as-empty-text">No players found. Try a broader search or fewer filters.</span>
		</div>

		<div id="as-table-wrap" hidden>
			<table id="as-table" class="display responsive" style="width:100%">
				<thead>
					<tr>
						<th class="as-col-persona">Persona</th>
<?php if ($_canSeeRealName) : ?>
						<th class="as-col-realname">Real Name</th>
<?php endif; ?>
						<th class="as-col-kingdom">Home Kingdom</th>
						<th class="as-col-park">Home Park</th>
						<th class="as-col-lastatt">Last Attended At</th>
						<th class="as-col-date">Last Attended</th>
						<th class="as-col-status">Status</th>
						<th class="as-col-actions">Actions</th>
					</tr>
				</thead>
				<tbody id="as-tbody"></tbody>
			</table>
		</div>
	</div>

</div><!-- /rp-root -->

<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script>
(function() {
	var UIR          = '<?= UIR ?>';
	var ADV_URL      = UIR + 'SearchAjax/advanced';
	var KINGDOMS_URL = UIR + 'KingdomAjax/getkingdoms';
	var FETCH_MAX    = 500;   // one batch into the client DataTable; refine filters past this
	var CAN_REALNAME = <?= $_canSeeRealName ?>;
	var PREFILL_K    = <?= $_prefillKId ?>;
	var PREFILL_P    = <?= $_prefillPId ?>;
	var AUTO_RUN     = <?= $_autoRun ?>;
	var LOGGED_IN    = <?= $_isLoggedIn ?>;
	var COPY_TIP     = 'Copy player ID (paste it into any player search)';

	var _dt    = null;   // DataTable instance
	var _ctrl  = null;   // AbortController for the in-flight search
	var _seq   = 0;      // ignore responses that a newer search has superseded

	function $id(id) { return document.getElementById(id); }
	var elQ = $id('as-q'), elKingdom = $id('as-kingdom'), elPark = $id('as-park');
	var elFrom = $id('as-from'), elTo = $id('as-to'), elDatesClear = $id('as-dates-clear');
	var elActive = $id('as-active'), elInactive = $id('as-inactive'), elBanned = $id('as-banned'); // banned may be null
	var elSearchBtn = $id('as-search-btn');
	var elCount = $id('as-result-count'), elSearching = $id('as-searching'), elPrompt = $id('as-prompt');
	var elEmpty = $id('as-empty'), elEmptyText = $id('as-empty-text');
	var elTableWrap = $id('as-table-wrap'), elTbody = $id('as-tbody');

	function esc(s) {
		if (s === null || s === undefined) return '';
		return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}
	var DASH = '<span class="as-muted">&mdash;</span>';

	// "The Kingdom of the Wetlands" -> "Wetlands", "The Empire of the Iron Mountains" -> "Iron Mountains".
	function shortOrg(name) {
		if (!name) return '';
		return String(name).trim()
			.replace(/^the\s+/i, '')
			.replace(/^(kingdom|empire|principality|grand\s+duchy|duchy|barony|shire|province|canton|freehold|domain)\s+of\s+(the\s+)?/i, '')
			.trim();
	}

	// "2024-05-03" (optionally with a time) -> "May 3, 2024". Built in UTC so the day never shifts.
	function humanDate(iso) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(iso || ''));
		if (!m) return '';
		var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3]));
		return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC' });
	}

	// ── View states ──
	function show(state) {
		elSearching.hidden = state !== 'searching';
		elPrompt.hidden    = state !== 'prompt';
		elEmpty.hidden     = state !== 'empty';
		elTableWrap.hidden = state !== 'table';
		if (state !== 'table') elCount.hidden = true;
	}

	// ── AJAX URL (UIR ends in '?Route=', so params join with &) ──
	function buildUrl() {
		var p = [
			'q=' + encodeURIComponent(elQ.value.trim()),
			'kingdomId=' + (parseInt(elKingdom.value, 10) || 0),
			'parkId=' + (parseInt(elPark.value, 10) || 0),
			'includeActive='   + (elActive.checked   ? 1 : 0),
			'includeInactive=' + (elInactive.checked ? 1 : 0),
			'includeBanned='   + (elBanned && !elBanned.disabled && elBanned.checked ? 1 : 0),
			'limit=' + FETCH_MAX,
			'offset=0'
		];
		if (elFrom.value) p.push('from=' + encodeURIComponent(elFrom.value));
		if (elTo.value)   p.push('to='   + encodeURIComponent(elTo.value));
		return ADV_URL + '&' + p.join('&');
	}

	function statusPill(row) {
		if (row.PenaltyBox || row.Banned) return '<span class="as-pill as-pill-banned">Banned</span>';
		if (row.Suspended)                return '<span class="as-pill as-pill-suspended">Suspended</span>';
		if (Number(row.Active) === 0)     return '<span class="as-pill as-pill-inactive">Inactive</span>';
		return '<span class="as-pill as-pill-active">Active</span>';
	}

	function renderRow(row) {
		var tr  = document.createElement('tr');
		var mid = parseInt(row.MundaneId, 10) || 0;
		var profileUrl = UIR + 'Player/profile/' + mid;
		var cells = '';

		var persona = (row.Persona && String(row.Persona).trim()) ? esc(row.Persona) : '<i class="as-muted">No Persona</i>';
		// Typo-tolerant hits (no exact match was found) are labelled so they read as suggestions.
		var close = row.Fuzzy ? ' <span class="as-close">close match</span>' : '';
		cells += '<td><a class="as-link" href="' + esc(profileUrl) + '">' + persona + '</a>' + close + '</td>';

		if (CAN_REALNAME) {
			var rn;
			if (Number(row.RealNameHidden) === 1) {
				rn = '<span class="as-muted">(restricted)</span>';
			} else {
				var full = ((row.GivenName || '') + ' ' + (row.Surname || '')).trim();
				rn = full ? esc(full) : DASH;
			}
			cells += '<td>' + rn + '</td>';
		}

		var kid = parseInt(row.KingdomId, 10) || 0;
		var kName = shortOrg(row.KingdomName);
		cells += '<td>' + (kName
			? (kid ? '<a class="as-link" href="' + esc(UIR + 'Kingdom/profile/' + kid) + '">' + esc(kName) + '</a>' : esc(kName))
			: DASH) + '</td>';

		var pid = parseInt(row.ParkId, 10) || 0;
		var pName = (row.ParkName || '').toString().trim();
		cells += '<td>' + (pName
			? (pid ? '<a class="as-link" href="' + esc(UIR + 'Park/profile/' + pid) + '">' + esc(pName) + '</a>' : esc(pName))
			: DASH) + '</td>';

		var attK = shortOrg((row.LastAttKingdom || '').toString().trim());
		var attP = (row.LastAttPark || '').toString().trim();
		var lastAt = DASH;
		if (attP && attK) lastAt = esc(attP) + ' <span class="as-muted">(' + esc(attK) + ')</span>';
		else if (attP || attK) lastAt = esc(attP || attK);
		cells += '<td>' + lastAt + '</td>';

		// Human-readable date; data-order keeps the ISO value so the column sorts chronologically.
		var iso = (row.LastAttDate || '').toString().trim();
		var nice = humanDate(iso);
		cells += '<td data-order="' + esc(iso) + '">' + (nice ? esc(nice) : DASH) + '</td>';

		cells += '<td>' + statusPill(row) + '</td>';

		cells += '<td><span class="as-act-wrap">'
			+ '<a class="as-icon-btn" href="' + esc(profileUrl) + '" target="_blank" rel="noopener"'
			+ ' data-tip="Open profile in a new tab" aria-label="Open profile in a new tab"><i class="fas fa-external-link-alt"></i></a>'
			+ '<button type="button" class="as-icon-btn as-copy-btn" data-mid="' + mid + '"'
			+ ' data-tip="' + esc(COPY_TIP) + '" aria-label="Copy player ID"><i class="fas fa-copy"></i></button>'
			+ '</span></td>';

		tr.innerHTML = cells;
		return tr;
	}

	// ── Copy player ID ──
	function copyId(btn) {
		var id = btn.getAttribute('data-mid');
		function flash() {
			btn.classList.add('as-copied');
			btn.setAttribute('data-tip', 'Copied!');
			btn.innerHTML = '<i class="fas fa-check"></i>';
			setTimeout(function() {
				btn.classList.remove('as-copied');
				btn.setAttribute('data-tip', COPY_TIP);
				btn.innerHTML = '<i class="fas fa-copy"></i>';
			}, 1400);
		}
		if (navigator.clipboard && navigator.clipboard.writeText) {
			navigator.clipboard.writeText(id).then(flash, function() { fallbackCopy(id); flash(); });
		} else {
			fallbackCopy(id);
			flash();
		}
	}
	function fallbackCopy(text) {
		var ta = document.createElement('textarea');
		ta.value = text;
		ta.style.position = 'fixed';
		ta.style.opacity  = '0';
		document.body.appendChild(ta);
		ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}
	// Delegated on the wrap: DataTables responsive moves collapsed cells into child rows.
	elTableWrap.addEventListener('click', function(e) {
		var btn = e.target.closest('.as-copy-btn');
		if (btn) { e.preventDefault(); copyId(btn); }
	});

	function initDataTable() {
		_dt = $('#as-table').DataTable({
			dom        : 'lfrtip',
			responsive : true,              // collapse columns on narrow screens, never scroll sideways
			pageLength : 25,
			order      : [],                // keep the server order (active, then inactive, then banned)
			columnDefs : [
				{ targets: 'as-col-persona', responsivePriority: 1 },
				{ targets: 'as-col-status',  responsivePriority: 2 },
				{ targets: 'as-col-actions', responsivePriority: 3, orderable: false, searchable: false },
				{ targets: 'as-col-date',    responsivePriority: 4 }
			],
			language: {
				search       : 'Filter:',
				lengthMenu   : 'Show _MENU_ rows',
				info         : 'Showing _START_–_END_ of _TOTAL_ players',
				infoEmpty    : 'No players',
				infoFiltered : ' (filtered from _MAX_)',
				zeroRecords  : 'No matching players',
				paginate     : { first: '«', previous: '‹', next: '›', last: '»' }
			}
		});
	}
	function resetTable() {
		if (_dt) { _dt.destroy(); _dt = null; }
		elTbody.innerHTML = '';
	}

	// ── Run a search. A newer search cancels the one in flight. ──
	function doSearch() {
		if (!LOGGED_IN) {
			// SearchAjax/advanced returns nothing without a session: say so instead of "No players found".
			elEmptyText.innerHTML = 'Log in to search players. <a class="as-link" href="' + esc(UIR + 'Login') + '">Log in</a>';
			show('empty');
			return;
		}
		if (_ctrl) _ctrl.abort();
		_ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
		var mySeq = ++_seq;

		elSearchBtn.disabled = true;
		show('searching');

		fetch(buildUrl(), { headers: { 'X-Requested-With': 'XMLHttpRequest' }, signal: _ctrl ? _ctrl.signal : undefined })
			.then(function(r) {
				if (!r.ok) throw new Error('HTTP ' + r.status);
				return r.json();
			})
			.then(function(data) {
				if (mySeq !== _seq) return;
				elSearchBtn.disabled = false;
				resetTable();

				if (data && data.needFilter) { show('prompt'); return; }

				var rows = (data && data.rows) ? data.rows : [];
				if (!rows.length) {
					elEmptyText.textContent = 'No players found. Try a broader search or fewer filters.';
					show('empty');
					return;
				}

				var frag = document.createDocumentFragment();
				rows.forEach(function(row) { frag.appendChild(renderRow(row)); });
				elTbody.appendChild(frag);
				show('table');
				initDataTable();

				var n = rows.length;
				var txt = n + ' player' + (n === 1 ? '' : 's') + ' found';
				if (data.hasMore) txt += '. Showing the first ' + n + '; add filters to narrow the results.';
				elCount.className   = 'as-result-count' + (data.hasMore ? ' as-trunc-note' : '');
				elCount.textContent = txt;
				elCount.hidden      = false;
			})
			.catch(function(err) {
				if (err && err.name === 'AbortError') return;
				if (mySeq !== _seq) return;
				elSearchBtn.disabled = false;
				resetTable();
				elEmptyText.textContent = 'Search failed. Check your connection and try again.';
				show('empty');
			});
	}

	// ── Controls ──
	elSearchBtn.addEventListener('click', doSearch);
	elQ.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });
	[elActive, elInactive, elBanned].forEach(function(el) { if (el) el.addEventListener('change', doSearch); });

	// Last-attendance range: flatpickr with a human-readable altInput; the real
	// inputs keep Y-m-d for the request.
	function syncDatesClear() { elDatesClear.hidden = !(elFrom.value || elTo.value); }
	var fpOpts = {
		altInput   : true,
		altFormat  : 'M j, Y',
		dateFormat : 'Y-m-d',
		allowInput : true,
		onChange   : function() { syncDatesClear(); doSearch(); }
	};
	var fpFrom = flatpickr(elFrom, fpOpts);
	var fpTo   = flatpickr(elTo, fpOpts);
	elDatesClear.addEventListener('click', function() {
		fpFrom.clear(false);
		fpTo.clear(false);
		syncDatesClear();
		doSearch();
	});

	// ── Kingdom → park cascade ──
	function loadParks(kingdomId, preselectParkId, cb) {
		elPark.innerHTML = '<option value="0">All Parks</option>';
		elPark.disabled = true;
		if (!kingdomId) { if (cb) cb(); return; }
		fetch(UIR + 'KingdomAjax/kingdom/' + kingdomId + '/getparks')
			.then(function(r) { return r.json(); })
			.then(function(d) {
				(d && d.parks ? d.parks : []).forEach(function(p) {
					var opt = document.createElement('option');
					opt.value = p.ParkId;
					opt.textContent = p.Name;
					elPark.appendChild(opt);
				});
				elPark.disabled = false;
				if (preselectParkId) {
					elPark.value = String(preselectParkId);
					// Park not in this kingdom's list (inactive/filtered): stay kingdom-scoped
					// on "All Parks" rather than leaving the select blank.
					if (elPark.value !== String(preselectParkId)) elPark.value = '0';
				}
				if (cb) cb();
			})
			.catch(function() { elPark.disabled = false; if (cb) cb(); });
	}
	elKingdom.addEventListener('change', function() {
		loadParks(parseInt(elKingdom.value, 10) || 0, 0, doSearch);
	});
	elPark.addEventListener('change', doSearch);

	function loadKingdoms(cb) {
		fetch(KINGDOMS_URL)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				(d && d.kingdoms ? d.kingdoms : []).forEach(function(k) {
					var opt = document.createElement('option');
					opt.value = k.KingdomId;
					opt.textContent = k.Abbreviation ? (k.KingdomName + ' (' + k.Abbreviation + ')') : k.KingdomName;
					elKingdom.appendChild(opt);
				});
				if (cb) cb();
			})
			.catch(function() { if (cb) cb(); });
	}

	// ── Init ──
	loadKingdoms(function() {
		if (PREFILL_K) {
			elKingdom.value = String(PREFILL_K);
			loadParks(PREFILL_K, PREFILL_P, function() { if (AUTO_RUN) doSearch(); });
		} else if (AUTO_RUN) {
			doSearch();
		} else {
			elQ.focus();
		}
	});
})();
</script>
