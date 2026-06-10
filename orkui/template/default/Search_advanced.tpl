<?php
/* ── Advanced Player Search — full-page UI ─────────────────────
 * Plain-PHP .tpl (extract()+include). NO Smarty.
 * Data provided by controller.Search::advanced():
 *   $CanSeeRealName, $CanSeeBanned, $IsLoggedIn (0/1)
 *   $PrefillQ (string), $PrefillKingdomId (int), $PrefillParkId (int)
 * Globals: UIR, HTTP_TEMPLATE, DIR_TEMPLATE
 * ──────────────────────────────────────────────────────────── */

$_canSeeRealName = !empty($CanSeeRealName) ? 1 : 0;
$_canSeeBanned   = !empty($CanSeeBanned)   ? 1 : 0;
$_prefillQ       = $PrefillQ         ?? '';
$_prefillKId     = (int)($PrefillKingdomId ?? 0);
$_prefillPId     = (int)($PrefillParkId    ?? 0);
$_autoRun        = (trimlen($_prefillQ) > 0 || $_prefillKId > 0 || $_prefillPId > 0) ? 1 : 0;
?>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css?v=<?=filemtime(DIR_TEMPLATE.'default/style/reports.css')?>">

<style>
/* Kill the duplicate scrollbar: the theme's `html, body { height:100%; overflow-x:hidden }`
   forces overflow-y to compute as `auto`, turning the fixed-height body into its own scroll
   container on top of the window — two scrollbars on a tall page. Let the body grow with its
   content so only the window scrolls. (Page-scoped: applies only while this page is rendered.) */
html, body { height: auto !important; min-height: 100%; }

/* ── Advanced Search page-specific (as-*) ───────────────────── */
.as-filter-bar {
	background      : var(--rp-card-bg, #fff);
	border          : 1px solid var(--rp-border, #e2e8f0);
	border-radius   : 8px;
	padding         : 16px;
	margin          : 16px 0;
	box-shadow      : 0 1px 3px rgba(0,0,0,0.04);
	display         : flex;
	flex-wrap       : wrap;
	gap             : 14px;
	align-items     : flex-end;
}
.as-field { display: flex; flex-direction: column; gap: 5px; }
.as-field-grow { flex: 1 1 260px; min-width: 200px; }
.as-field-label {
	font-size      : 11px;
	font-weight    : 700;
	text-transform : uppercase;
	letter-spacing : 0.04em;
	color          : var(--rp-text-muted, #718096);
}
.as-input, .as-select {
	border        : 1px solid var(--rp-border, #cbd5e0);
	border-radius : 6px;
	padding       : 8px 10px;
	font-size     : 13px;
	color         : var(--rp-text, #2d3748);
	background    : #fff;
	outline       : none;
	transition    : border-color 0.15s;
	box-sizing    : border-box;
}
.as-input { min-width: 240px; width: 100%; }
.as-input:focus, .as-select:focus { border-color: var(--rp-accent-mid, #6366f1); }
.as-select:disabled { opacity: 0.55; cursor: not-allowed; }
.as-hint { font-size: 11px; color: var(--rp-text-hint, #a0aec0); margin-top: 2px; }

.as-toggles { display: flex; flex-wrap: wrap; gap: 14px; }
.as-toggle {
	display     : flex;
	align-items : center;
	gap         : 6px;
	font-size   : 13px;
	color       : var(--rp-text-body, #4a5568);
	cursor      : pointer;
	user-select : none;
}
.as-toggle input { width: 15px; height: 15px; accent-color: var(--rp-accent, #4338ca); cursor: pointer; }

.as-dates { display: flex; gap: 10px; }

.as-btn-search {
	display        : inline-flex;
	align-items    : center;
	gap            : 7px;
	padding        : 9px 18px;
	background     : var(--rp-accent, #4338ca);
	color          : #fff;
	border         : none;
	border-radius  : 6px;
	font-size      : 13px;
	font-weight    : 700;
	cursor         : pointer;
	transition     : background 0.15s;
}
.as-btn-search:hover { background: var(--rp-accent-mid, #6366f1); }

/* ── Results states ─────────────────────────────────────────── */
.as-state {
	text-align : center;
	padding    : 48px 24px;
	color      : var(--rp-text-muted, #718096);
	font-size  : 14px;
}
.as-state i { font-size: 28px; display: block; margin-bottom: 12px; opacity: 0.5; }

/* ── Status pills (match inline player-search component) ────── */
.as-pill {
	display       : inline-block;
	padding       : 2px 9px;
	border-radius : 20px;
	font-size     : 11px;
	font-weight   : 700;
	line-height   : 1.5;
	white-space   : nowrap;
}
.as-pill-active    { background: #c6f6d5; color: #22543d; }
.as-pill-inactive  { background: #e2e8f0; color: #4a5568; }
.as-pill-suspended { background: #feebc8; color: #7b341e; }
.as-pill-banned    { background: #fed7d7; color: #822727; }

.as-muted { color: var(--rp-text-hint, #a0aec0); }

/* ── Action cell ────────────────────────────────────────────── */
.as-act-wrap { display: inline-flex; align-items: center; gap: 10px; }
.as-act-link {
	color           : var(--rp-accent, #4338ca);
	text-decoration : none;
	font-size       : 13px;
}
.as-act-link:hover { color: var(--rp-accent-mid, #6366f1); text-decoration: underline; }
/* Shared icon button (View + Copy share one look). */
.as-icon-btn {
	display         : inline-flex;
	align-items     : center;
	justify-content : center;
	background      : transparent;
	border          : 1px solid var(--rp-border, #cbd5e0);
	border-radius   : 5px;
	padding         : 4px 8px;
	font-size       : 12px;
	color           : var(--rp-text-body, #4a5568);
	cursor          : pointer;
	text-decoration : none;
	position        : relative;
	transition      : background 0.12s, border-color 0.12s, color 0.12s;
}
.as-icon-btn:hover { background: var(--rp-bg-light, #f7fafc); border-color: #a0aec0; color: var(--rp-text, #2d3748); }
.as-copy-btn.as-copied { background: #c6f6d5; border-color: #9ae6b4; color: #22543d; }

/* ── data-tip CSS tooltip (no native title) ─────────────────── */
[data-tip] { position: relative; }
[data-tip]::after {
	content        : attr(data-tip);
	position       : absolute;
	bottom         : 140%;
	left           : 50%;
	transform      : translateX(-50%);
	white-space    : normal;
	width          : max-content;
	max-width      : 220px;
	background     : #1a2035;
	color          : #f1f5f9;
	font-size      : 11px;
	font-weight    : 500;
	line-height    : 1.4;
	text-align     : center;
	padding        : 7px 9px;
	border-radius  : 6px;
	box-shadow     : 0 4px 14px rgba(0,0,0,0.35);
	opacity        : 0;
	visibility     : hidden;
	transition     : opacity 0.12s;
	z-index        : 60;
	pointer-events : none;
}
[data-tip]:hover::after { opacity: 1; visibility: visible; }


.as-result-count { font-size: 12px; color: var(--rp-text-muted, #718096); margin-bottom: 10px; }

/* ── Dark mode overrides ────────────────────────────────────── */
html[data-theme="dark"] .as-filter-bar {
	background: #2d3748;
	border-color: #4a5568;
}
html[data-theme="dark"] .as-field-label { color: #a0aec0; }
html[data-theme="dark"] .as-input,
html[data-theme="dark"] .as-select {
	background: #374151;
	border-color: #4a5568;
	color: #e2e8f0;
}
html[data-theme="dark"] .as-input:focus,
html[data-theme="dark"] .as-select:focus { border-color: #818cf8; }
html[data-theme="dark"] .as-hint { color: #718096; }
html[data-theme="dark"] .as-toggle { color: #cbd5e0; }
html[data-theme="dark"] .as-btn-search { background: #6366f1; }
html[data-theme="dark"] .as-btn-search:hover { background: #818cf8; }
html[data-theme="dark"] .as-state { color: #a0aec0; }
html[data-theme="dark"] .as-muted { color: #718096; }
html[data-theme="dark"] .as-result-count { color: #a0aec0; }

html[data-theme="dark"] .as-pill-active    { background: #1a3a26; color: #68d391; }
html[data-theme="dark"] .as-pill-inactive  { background: #374151; color: #a0aec0; }
html[data-theme="dark"] .as-pill-suspended { background: #3d3515; color: #fbd38d; }
html[data-theme="dark"] .as-pill-banned    { background: #3b1515; color: #feb2b2; }

html[data-theme="dark"] .as-icon-btn {
	border-color: #4a5568;
	color: #cbd5e0;
}
html[data-theme="dark"] .as-icon-btn:hover { background: #4a5568; border-color: #718096; color: #e2e8f0; }
html[data-theme="dark"] .as-copy-btn.as-copied { background: #1a3a26; border-color: #38a169; color: #68d391; }

html[data-theme="dark"] [data-tip]::after { background: #e2e8f0; color: #1a202c; box-shadow: 0 4px 14px rgba(0,0,0,0.5); }


/* ── DataTable: uniform font, zebra, responsive width, no inner scrollbars ───
   Override reports.css's overflow-x:auto on .rp-table-area so the responsive
   DataTable fits the page (no horizontal scrollbar on desktop; columns collapse
   on mobile instead) and so the copy-button tooltip is never clipped. */
.rp-table-area { overflow: visible; }
/* One uniform text size across the whole table (matches the persona/name link). */
#as-table.dataTable thead th,
#as-table.dataTable tbody td { font-size: 13px; }
/* Alternating row shading (zebra), light + dark. */
#as-table.dataTable tbody tr:nth-child(odd)  > td { background: #f7fafc; }
#as-table.dataTable tbody tr:nth-child(even) > td { background: #ffffff; }
#as-table.dataTable tbody tr:hover > td { background: #ebf2fb; }
html[data-theme="dark"] #as-table.dataTable tbody tr:nth-child(odd)  > td { background: #2a3340; }
html[data-theme="dark"] #as-table.dataTable tbody tr:nth-child(even) > td { background: #2d3748; }
html[data-theme="dark"] #as-table.dataTable tbody tr:hover > td { background: #3b4960; }
/* DataTables responsive child-row (collapsed columns on mobile) readability. */
#as-table.dataTable > tbody > tr.child ul.dtr-details { width: 100%; }
html[data-theme="dark"] #as-table.dataTable > tbody > tr.child span.dtr-title { color: #e2e8f0; }

/* Copy-button tooltip is in the last column at the right edge — anchor it to the
   right so it opens leftward and never clips off-screen. */
/* Action-cell tooltips (View + Copy) anchor to the right so they never clip at the edge. */
.as-act-wrap [data-tip]::after { left: auto; right: 0; transform: none; }

/* The results count line doubles as a truncation notice. */
.as-trunc-note { color: #b7791f; }
html[data-theme="dark"] .as-trunc-note { color: #f6ad55; }
</style>

<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-search rp-header-icon"></i>
				<h1 class="rp-header-title">Advanced Player Search</h1>
			</div>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Search players by persona or name across kingdoms and parks. Enter a name or ID, or pick a kingdom, then refine with the filters. A pure number searches by player ID.</span>
	</div>

	<!-- ── Filter bar ─────────────────────────────────────── -->
	<div class="as-filter-bar">

		<div class="as-field as-field-grow">
			<label class="as-field-label" for="as-q">Search</label>
			<input type="text" id="as-q" class="as-input" autocomplete="off"
				placeholder="Persona or name… (a pure number searches by player ID)"
				value="<?=htmlspecialchars($_prefillQ)?>">
		</div>

		<div class="as-field">
			<label class="as-field-label">Include</label>
			<div class="as-toggles">
				<label class="as-toggle"><input type="checkbox" id="as-active"   checked> Active</label>
				<label class="as-toggle"><input type="checkbox" id="as-inactive" checked> Inactive</label>
<?php if ($_canSeeBanned) : ?>
				<label class="as-toggle"><input type="checkbox" id="as-banned"   checked> Banned</label>
<?php endif; ?>
			</div>
		</div>

		<div class="as-field">
			<label class="as-field-label" for="as-kingdom">Kingdom</label>
			<select id="as-kingdom" class="as-select" data-prefill="<?=$_prefillKId?>">
				<option value="0">All Kingdoms</option>
			</select>
		</div>

		<div class="as-field">
			<label class="as-field-label" for="as-park">Park</label>
			<select id="as-park" class="as-select" data-prefill="<?=$_prefillPId?>" disabled>
				<option value="0">All Parks</option>
			</select>
		</div>

		<div class="as-field">
			<label class="as-field-label">Last Attendance</label>
			<div class="as-dates">
				<input type="date" id="as-from" class="as-input" style="min-width:0;width:auto">
				<input type="date" id="as-to"   class="as-input" style="min-width:0;width:auto">
			</div>
			<span class="as-hint">From / To (blank = all dates). A date filter excludes players with no attendance.</span>
		</div>

		<div class="as-field">
			<button type="button" class="as-btn-search" id="as-search-btn">
				<i class="fas fa-search"></i> Search
			</button>
		</div>

	</div>

	<!-- ── Results ────────────────────────────────────────── -->
	<div class="rp-table-area">
		<div id="as-result-count" class="as-result-count" style="display:none"></div>

		<div id="as-searching" class="as-state" style="display:none">
			<i class="fas fa-spinner fa-spin"></i>
			Searching&hellip;
		</div>

		<div id="as-prompt" class="as-state">
			<i class="fas fa-keyboard"></i>
			Enter a name or ID, or choose a kingdom, to begin.
		</div>

		<div id="as-empty" class="as-state" style="display:none">
			<i class="fas fa-user-slash"></i>
			No players found. Try broadening your search or filters.
		</div>

		<div id="as-table-wrap" style="display:none">
			<table id="as-table" class="display responsive" style="width:100%">
				<thead>
					<tr>
						<th class="as-col-persona">Persona</th>
<?php if ($_canSeeRealName) : ?>
						<th class="as-col-realname">Real Name</th>
<?php endif; ?>
						<th class="as-col-kingdom">Home Kingdom</th>
						<th class="as-col-park">Home Park</th>
						<th class="as-col-lastatt">Last Attendance</th>
						<th class="as-col-date">Last Att Date</th>
						<th class="as-col-status">Status</th>
						<th class="as-col-actions">Actions</th>
					</tr>
				</thead>
				<tbody id="as-tbody"></tbody>
			</table>
		</div>
	</div>

</div><!-- /rp-root -->

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script>
(function() {
	var UIR        = '<?=UIR?>';
	var ADV_URL    = UIR + 'SearchAjax/advanced';
	var KINGDOMS_URL = UIR + 'KingdomAjax/getkingdoms';
	var FETCH_MAX  = 500;   // one batch loaded into the client DataTable; refine filters past this
	var CAN_REALNAME = <?=$_canSeeRealName?>;

	var PREFILL_K = <?=$_prefillKId?>;
	var PREFILL_P = <?=$_prefillPId?>;
	var AUTO_RUN  = <?=$_autoRun?>;

	var _busy      = false;
	var _dt        = null;   // DataTable instance

	// Strip the org "type" prefix from a kingdom name, keeping the distinctive part:
	// "The Kingdom of the Wetlands" -> "Wetlands", "The Empire of the Iron Mountains" -> "Iron Mountains",
	// "The Kingdom of Crystal Groves" -> "Crystal Groves".
	function shortOrg(name) {
		if (!name) return '';
		var s = String(name).trim();
		s = s.replace(/^the\s+/i, '');
		s = s.replace(/^(kingdom|empire|principality|duchy|barony|shire|province|canton|freehold|domain)\s+of\s+(the\s+)?/i, '');
		return s.trim();
	}

	// ── DOM refs ──
	var elQ        = document.getElementById('as-q');
	var elKingdom  = document.getElementById('as-kingdom');
	var elPark     = document.getElementById('as-park');
	var elFrom     = document.getElementById('as-from');
	var elTo       = document.getElementById('as-to');
	var elActive   = document.getElementById('as-active');
	var elInactive = document.getElementById('as-inactive');
	var elBanned   = document.getElementById('as-banned'); // may be null
	var elSearchBtn = document.getElementById('as-search-btn');

	var elCount     = document.getElementById('as-result-count');
	var elSearching = document.getElementById('as-searching');
	var elPrompt    = document.getElementById('as-prompt');
	var elEmpty     = document.getElementById('as-empty');
	var elTableWrap = document.getElementById('as-table-wrap');
	var elTbody     = document.getElementById('as-tbody');

	// ── Escape helper (never inject raw row text) ──
	function esc(s) {
		if (s === null || s === undefined) return '';
		return String(s)
			.replace(/&/g, '&amp;')
			.replace(/</g, '&lt;')
			.replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;')
			.replace(/'/g, '&#39;');
	}

	// ── State helpers ──
	function hideAllStates() {
		elSearching.style.display = 'none';
		elPrompt.style.display    = 'none';
		elEmpty.style.display     = 'none';
	}
	function showEmpty() {
		hideAllStates();
		elEmpty.style.display = 'block';
		elTableWrap.style.display = 'none';
		elCount.style.display     = 'none';
	}

	// ── Build the AJAX URL (UIR ends in '?Route=' — append params with &). One batch
	//    of up to FETCH_MAX rows is loaded; the client DataTable paginates them. ──
	function buildUrl() {
		var p = [];
		p.push('q=' + encodeURIComponent(elQ.value.trim()));
		p.push('kingdomId=' + (parseInt(elKingdom.value, 10) || 0));
		p.push('parkId=' + (parseInt(elPark.value, 10) || 0));
		p.push('includeActive='   + (elActive.checked   ? 1 : 0));
		p.push('includeInactive=' + (elInactive.checked ? 1 : 0));
		p.push('includeBanned='   + (elBanned && elBanned.checked ? 1 : 0));
		if (elFrom.value) p.push('from=' + encodeURIComponent(elFrom.value));
		if (elTo.value)   p.push('to='   + encodeURIComponent(elTo.value));
		p.push('limit=' + FETCH_MAX);
		p.push('offset=0');
		return ADV_URL + '&' + p.join('&');
	}

	// ── Status pill ──
	function statusPill(row) {
		if (row.PenaltyBox || row.Banned) return '<span class="as-pill as-pill-banned">Banned</span>';
		if (row.Suspended)                return '<span class="as-pill as-pill-suspended">Suspended</span>';
		if (Number(row.Active) === 0)     return '<span class="as-pill as-pill-inactive">Inactive</span>';
		return '<span class="as-pill as-pill-active">Active</span>';
	}

	// ── Render a single row ──
	function renderRow(row) {
		var tr = document.createElement('tr');
		var cells = '';

		// Persona (links to profile)
		var persona = (row.Persona && String(row.Persona).trim().length) ? esc(row.Persona) : '<i class="as-muted">No Persona</i>';
		cells += '<td><a class="as-act-link" href="' + esc(UIR + 'Player/profile/' + row.MundaneId) + '" target="_blank">' + persona + '</a></td>';

		// Real Name (only if allowed)
		if (CAN_REALNAME) {
			var rn;
			if (Number(row.RealNameHidden) === 1) {
				rn = '<span class="as-muted">(restricted)</span>';
			} else {
				var full = ((row.GivenName || '') + ' ' + (row.Surname || '')).trim();
				rn = full.length ? esc(full) : '<span class="as-muted">&mdash;</span>';
			}
			cells += '<td>' + rn + '</td>';
		}

		// Home Kingdom (shortened — drop the "Kingdom of the…" prefix)
		var kingdom = (row.KingdomName && String(row.KingdomName).trim().length)
			? esc(shortOrg(row.KingdomName)) : '<span class="as-muted">&mdash;</span>';
		cells += '<td>' + kingdom + '</td>';

		// Home Park
		var park = (row.ParkName && String(row.ParkName).trim().length)
			? esc(row.ParkName) : '<span class="as-muted">&mdash;</span>';
		cells += '<td>' + park + '</td>';

		// Last Attendance (Kingdom : Park)
		var attK = shortOrg((row.LastAttKingdom || '').toString().trim());
		var attP = (row.LastAttPark || '').toString().trim();
		var lastAtt;
		if (attK || attP) {
			lastAtt = esc(attK || '?') + ' : ' + esc(attP || '?');
		} else {
			lastAtt = '<span class="as-muted">&mdash;</span>';
		}
		cells += '<td>' + lastAtt + '</td>';

		// Last Att Date
		var attDate = (row.LastAttDate && String(row.LastAttDate).trim().length)
			? esc(row.LastAttDate) : '<span class="as-muted">&mdash;</span>';
		cells += '<td>' + attDate + '</td>';

		// Status
		cells += '<td>' + statusPill(row) + '</td>';

		// Actions
		var mid = esc(row.MundaneId);
		cells += '<td><span class="as-act-wrap">' +
			'<a class="as-icon-btn as-view-btn" href="' + esc(UIR + 'Player/profile/' + row.MundaneId) + '" ' +
				'target="_blank" rel="noopener" data-tip="Open in New Tab">' +
				'<i class="fas fa-external-link-alt"></i></a>' +
			'<button type="button" class="as-icon-btn as-copy-btn" data-mid="' + mid + '" ' +
				'data-tip="Copy Player ID - Paste this into your search!">' +
				'<i class="fas fa-copy"></i></button>' +
		'</span></td>';

		tr.innerHTML = cells;
		return tr;
	}

	// ── Copy-to-clipboard (with execCommand fallback) ──
	function copyId(btn) {
		var id = btn.getAttribute('data-mid');
		function flash() {
			btn.classList.add('as-copied');
			btn.setAttribute('data-tip', 'Copied!');
			var orig = '<i class="fas fa-copy"></i>';
			btn.innerHTML = '<i class="fas fa-check"></i>';
			setTimeout(function() {
				btn.classList.remove('as-copied');
				btn.setAttribute('data-tip', 'Copy Player ID - Paste this into your search!');
				btn.innerHTML = orig;
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
		ta.focus();
		ta.select();
		try { document.execCommand('copy'); } catch (e) {}
		document.body.removeChild(ta);
	}

	elTbody.addEventListener('click', function(e) {
		var btn = e.target.closest ? e.target.closest('.as-copy-btn') : null;
		if (btn) { e.preventDefault(); copyId(btn); }
	});

	// ── (Re)build the client DataTable from the loaded rows ──
	function initDataTable() {
		if (_dt) { _dt.destroy(); _dt = null; }
		_dt = $('#as-table').DataTable({
			dom        : 'lfrtip',          // length • filter • table • info • pagination (Reports look)
			responsive : true,              // collapse columns on narrow screens — no horizontal scrollbar
			pageLength : 25,
			order      : [],                // keep the server's tier ordering (active → inactive → banned)
			columnDefs : [
				{ targets: '.as-col-persona', responsivePriority: 1 },
				{ targets: '.as-col-status',  responsivePriority: 2 },
				{ targets: '.as-col-actions', responsivePriority: 3, orderable: false, searchable: false },
				{ targets: '.as-col-date',    responsivePriority: 4 }
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

	// ── Run a search (single batch → client DataTable) ──
	function doSearch() {
		if (_busy) return;
		_busy = true;
		elSearchBtn.disabled = true;

		hideAllStates();
		elSearching.style.display = 'block';
		elTableWrap.style.display = 'none';
		elCount.style.display     = 'none';

		fetch(buildUrl(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				_busy = false;
				elSearchBtn.disabled = false;
				if (_dt) { _dt.destroy(); _dt = null; }
				elTbody.innerHTML = '';

				if (data && data.needFilter) {
					hideAllStates();
					elPrompt.style.display = 'block';
					elTableWrap.style.display = 'none';
					elCount.style.display     = 'none';
					return;
				}

				var rows = (data && data.rows) ? data.rows : [];
				if (rows.length === 0) { showEmpty(); return; }

				rows.forEach(function(row) { elTbody.appendChild(renderRow(row)); });
				hideAllStates();
				elTableWrap.style.display = 'block';
				initDataTable();

				// Count line (+ truncation notice when the batch cap was hit).
				var n = rows.length;
				var txt = n + ' player' + (n === 1 ? '' : 's') + ' found';
				if (data && data.hasMore) {
					txt += ' — showing the first ' + n + '; refine your filters to narrow the results.';
					elCount.className = 'as-result-count as-trunc-note';
				} else {
					elCount.className = 'as-result-count';
				}
				elCount.textContent = txt;
				elCount.style.display = 'block';
			})
			.catch(function() {
				_busy = false;
				elSearchBtn.disabled = false;
				showEmpty();
			});
	}

	// ── Wire controls ──
	elSearchBtn.addEventListener('click', function() { doSearch(); });
	elQ.addEventListener('keydown', function(e) { if (e.key === 'Enter') { e.preventDefault(); doSearch(); } });

	// Filter changes re-run a fresh search
	[elActive, elInactive, elFrom, elTo].forEach(function(el) {
		if (el) el.addEventListener('change', function() { doSearch(); });
	});
	if (elBanned) elBanned.addEventListener('change', function() { doSearch(); });

	// ── Park cascade ──
	function loadParks(kingdomId, preselectParkId, cb) {
		elPark.innerHTML = '<option value="0">All Parks</option>';
		if (!kingdomId) {
			elPark.disabled = true;
			if (cb) cb();
			return;
		}
		elPark.disabled = true;
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
				if (preselectParkId) elPark.value = String(preselectParkId);
				if (cb) cb();
			})
			.catch(function() { elPark.disabled = false; if (cb) cb(); });
	}

	elKingdom.addEventListener('change', function() {
		var kid = parseInt(elKingdom.value, 10) || 0;
		loadParks(kid, 0, function() { doSearch(); });
	});
	elPark.addEventListener('change', function() { doSearch(); });

	// ── Populate kingdoms on load ──
	function loadKingdoms(cb) {
		fetch(KINGDOMS_URL)
			.then(function(r) { return r.json(); })
			.then(function(d) {
				var list = (d && d.kingdoms) ? d.kingdoms : [];
				list.forEach(function(k) {
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
			loadParks(PREFILL_K, PREFILL_P, function() {
				if (AUTO_RUN) doSearch();
			});
		} else if (AUTO_RUN) {
			doSearch();
		}
	});
})();
</script>
