<?php
/* =====================================================================
   Correct the Rolls tab — rendered INTO the Manage Officers modal body.
   ---------------------------------------------------------------------
   Lists, edits, adds, and deletes ork_officerhistory rows directly,
   grouped by office. This is the authoritative-record maintenance tool
   for cleaning up entries that predate accurate data entry, or fixing a
   mistake without running it back through the Transition wizard.

   Lives inside #mo-tabpanel-rolls, one of the two panels the host
   partial's tab bar (.mo-tabs / .mo-tab, which stay in the host because
   the Positions tab button uses them too) switches between via
   crShowTab(). window.crOpen()/window.crShowTab() are the tab's public
   entry points; window.crRefresh() reloads the list after an external
   mutation.

   No include contract of its own: it reads window.MoConfig (set by the
   host partial's own <script>, further down the page) and window.moPost /
   moShowNotice / moShowConfirm / moCloseConfirm / moEsc / moEscAttr /
   moSearchUrl / moGetOfficeList (bridged from that same script — see the
   comment beside window.moRefresh there). UIR itself is not bridged by
   name; crUIR() below reads it straight off window.MoConfig.uir on every
   call, the same well-known global the host's own local UIR reads once.
   All of the above -- including crUIR() -- are only ever referenced from
   inside a click/load handler here, never evaluated at the top of this
   file, so it does not matter that this markup — and this <script> tag —
   sits EARLIER in the document than the host's own <script> tag (window.
   MoConfig is not assigned until that script runs): by the time a user
   can click anything, both have already run. A plain `var UIR = ...`
   captured once at THIS file's parse time, before that, would freeze on
   an empty string forever -- this was tried and caught in manual testing.
   ===================================================================== */
?>
<div class="mo-tabpanel" id="mo-tabpanel-rolls" role="tabpanel" aria-labelledby="mo-tabbtn-rolls" hidden>
	<div class="cr-intro">
		<p class="cr-lede">Add, correct, or remove officer history terms directly. This is the authoritative record behind this kingdom&rsquo;s public History tab.</p>
	</div>

	<div class="cr-add-card">
		<h4 class="cr-add-title"><i class="fas fa-plus" aria-hidden="true"></i> Add a Term</h4>
		<div class="ka-feedback ka-feedback-err" id="cr-add-error" style="display:none"></div>
		<div class="ka-field-row">
			<div class="ka-field">
				<label for="cr-add-pos">Office <span class="ka-req" aria-hidden="true">*</span></label>
				<select id="cr-add-pos"><option value="">Select an office...</option></select>
			</div>
			<div class="ka-field ka-field-ac">
				<label for="cr-add-player">Officer <span class="ka-req" aria-hidden="true">*</span></label>
				<input type="text" id="cr-add-player" placeholder="Search by persona..." autocomplete="off"
				       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="cr-add-results" />
				<input type="hidden" id="cr-add-id" value="" />
				<div class="kn-ac-results" id="cr-add-results" style="position:fixed" role="listbox"></div>
			</div>
		</div>
		<div class="ka-field-row">
			<div class="ka-field">
				<label for="cr-add-start">Term Start <span class="ka-hint">(optional)</span></label>
				<input type="text" id="cr-add-start" placeholder="unknown" autocomplete="off" />
			</div>
			<div class="ka-field">
				<label for="cr-add-end">Term End <span class="ka-hint">(optional &mdash; leave blank if this term is still open)</span></label>
				<input type="text" id="cr-add-end" autocomplete="off" />
			</div>
		</div>
		<div class="ka-field">
			<label for="cr-add-note">Note <span class="ka-hint">(optional)</span></label>
			<textarea id="cr-add-note" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
		</div>
		<div class="cr-add-actions">
			<button type="button" class="kn-btn kn-btn-primary" id="cr-add-btn" onclick="crAdd()">
				<i class="fas fa-plus" aria-hidden="true"></i> Add Term
			</button>
		</div>
	</div>

	<div id="cr-loading" style="text-align:center;padding:24px;color:var(--ork-text-secondary,#a0aec0)">
		<i class="fas fa-spinner fa-spin"></i> Loading officer history...
	</div>
	<div id="cr-error" class="mo-loaderr" style="display:none"></div>
	<div class="cr-empty" id="cr-empty" style="display:none">
		No officer history has been recorded here yet.
	</div>
	<div class="cr-list" id="cr-list" style="display:none"></div>
</div>

<style>
/* ============ Correct the Rolls tab ============ */
.cr-intro { margin-bottom:14px; }
.cr-lede { font-size:13px; color:#718096; line-height:1.5; margin:0; }
html[data-theme="dark"] .cr-lede { color:var(--ork-text-secondary); }

.cr-add-card { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:18px; }
html[data-theme="dark"] .cr-add-card { background:var(--ork-card-bg); border-color:var(--ork-border); }
.cr-add-title {
	font-size:14px; font-weight:700; color:#2d3748; margin:0 0 12px 0;
	display:flex; align-items:center; gap:8px;
	background:transparent; border:none; padding:0; border-radius:0; text-shadow:none;
}
html[data-theme="dark"] .cr-add-title { color:var(--ork-text); }
.cr-add-actions { display:flex; justify-content:flex-end; margin-top:6px; }

.cr-empty { text-align:center; padding:28px 20px; color:#a0aec0; font-size:13px; border:1px dashed #e2e8f0; border-radius:8px; }
html[data-theme="dark"] .cr-empty { color:var(--ork-text-muted); border-color:var(--ork-border); }

.cr-list { display:flex; flex-direction:column; gap:10px; }

.cr-panel { background:#fff; border:1px solid #e2e8f0; border-radius:8px; overflow:hidden; }
html[data-theme="dark"] .cr-panel { background:var(--ork-card-bg); border-color:var(--ork-border); }
.cr-summary {
	list-style:none; cursor:pointer; padding:10px 14px; display:flex; align-items:center; gap:8px;
	font-size:13px; font-weight:700; color:#2d3748; background:#f7fafc;
}
.cr-summary::-webkit-details-marker { display:none; }
html[data-theme="dark"] .cr-summary { color:var(--ork-text); background:var(--ork-bg-tertiary); }
.cr-summary-title { flex:1; min-width:0; overflow-wrap:anywhere; }
.cr-summary-count { font-size:11px; font-weight:600; color:#a0aec0; white-space:nowrap; }
html[data-theme="dark"] .cr-summary-count { color:var(--ork-text-muted); }
.cr-panel-body { display:flex; flex-direction:column; gap:8px; padding:10px 14px; }

.cr-row { display:flex; align-items:flex-start; gap:10px; flex-wrap:wrap; padding:8px 0; border-top:1px solid #edf2f7; }
.cr-panel-body .cr-row:first-child { border-top:none; padding-top:0; }
html[data-theme="dark"] .cr-row { border-top-color:var(--ork-border); }
.cr-row-main { flex:1 1 240px; min-width:0; display:flex; flex-direction:column; gap:3px; }
.cr-row-persona { font-size:13px; font-weight:600; color:#2d3748; }
html[data-theme="dark"] .cr-row-persona { color:var(--ork-text); }
.cr-persona-link { color:#2b6cb0; text-decoration:none; }
.cr-persona-link:hover { text-decoration:underline; }
html[data-theme="dark"] .cr-persona-link { color:hsl(210,80%,65%); }
.cr-row-term { font-size:12px; color:#718096; }
html[data-theme="dark"] .cr-row-term { color:var(--ork-text-secondary); }
.cr-row-note { font-size:12px; color:#a0aec0; font-style:italic; overflow-wrap:anywhere; }
html[data-theme="dark"] .cr-row-note { color:var(--ork-text-muted); }
.cr-row-actions { flex:0 1 auto; margin-left:auto; }

.cr-row-editing { flex-direction:column; align-items:stretch; }
.cr-edit-actions { display:flex; justify-content:flex-end; gap:8px; margin-top:6px; }

@media (max-width:768px) {
	.cr-row-actions { margin-left:0; width:100%; justify-content:flex-start; }
}
</style>

<script>
(function () {
	// ============================================================
	// Correct the Rolls tab
	// ------------------------------------------------------------
	// Reads reuse the existing officerhistory endpoint; addhistory/edithistory/
	// deletehistory are this tab's writes. Every history row already carries its
	// own DisplayLabel/Classification snapshot from GetOfficerHistory, so
	// grouping the LIST needs no cross-reference against moData at all -- only
	// the Add form's office picker does.
	// ============================================================
	// UIR is not bridged from the host by name -- crUIR() derives it fresh,
	// on every call, from window.MoConfig.uir, the same well-known global the
	// host's own "var UIR = MoConfig.uir" reads at IIFE-invocation time. This
	// file's <script> tag executes EARLIER in the document than the host's
	// (window.MoConfig is not assigned until the host's script runs, further
	// down the page), so unlike the host, a plain `var UIR = ...` captured
	// once here at parse time would permanently freeze on '' -- silently
	// building relative, wrong URLs (still 200-looking network calls resolved
	// against the CURRENT page instead of UIR's real target -- easy to miss).
	// crUIR() reads the global lazily instead, exactly like moData is only
	// ever reached through window.moGetOfficeList(): every call site here
	// only ever runs from a click/load handler, well after the host's script
	// (and its window.MoConfig assignment) has already executed.
	function crUIR() { return (window.MoConfig && window.MoConfig.uir) || ''; }

	var crRows = [];
	var crLoaded = false;
	var crEditingId = 0;
	var crAddStartFp = null, crAddEndFp = null;
	var crEditStartFp = null, crEditEndFp = null;

	// Flatpickr's altInput REPLACES the visible field; the real Y-m-d stays on
	// the ORIGINAL input. Identical in shape to _officer_transition.tpl's otRaw.
	function crRaw(id) {
		var el = document.getElementById(id);
		return el && el.value ? el.value : '';
	}

	function crUrl() {
		var uir = crUIR();
		return (window.MoConfig && MoConfig.parkId)
			? (uir + 'ParkAjax/park/' + MoConfig.parkId + '/officerhistory')
			: (uir + 'KingdomAjax/kingdom/' + MoConfig.kingdomId + '/officerhistory');
	}

	// Guards the zero date ('0000-00-00') and anything non-ISO rather than
	// letting it reach the client as a literal string.
	function crIsoOrEmpty(v) {
		var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(v || ''));
		if (!m || m[1] === '0000') return '';
		return m[1] + '-' + m[2] + '-' + m[3];
	}
	function crFmtDate(v) {
		var iso = crIsoOrEmpty(v);
		if (!iso) return '';
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
		var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
			'August', 'September', 'October', 'November', 'December'];
		return months[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
	}
	function crIsOpenTerm(row) { return crIsoOrEmpty(row.EndDate) === ''; }

	// The SNAPSHOT of what the office was called at the time wins over the
	// registry's current name -- same rule the public History tab uses.
	function crLabelFor(row) {
		var label = String(row.DisplayLabel || '').trim();
		if (label) return label;
		var role = String(row.Role || '').trim();
		if (!role) return 'Unrecorded office';
		return role.replace(/_/g, ' ').replace(/\b[a-z]/g, function(c) { return c.toUpperCase(); });
	}
	// Classification is 'crown' | 'supporting' | null (unknown -- a retired
	// position, or a row that predates the registry). Null sorts AFTER
	// supporting, never with it.
	function crClassRank(c) {
		c = String(c || '').toLowerCase();
		if (c === 'crown') return 0;
		if (c === 'supporting') return 1;
		return 2;
	}
	function crGroupKey(row) {
		var pid = parseInt(row.PositionId, 10) || 0;
		return pid > 0 ? ('p' + pid) : ('r:' + String(row.Role || '').trim().toLowerCase());
	}
	function crFindRow(hid) {
		for (var i = 0; i < crRows.length; i++) {
			if (parseInt(crRows[i].OfficerHistoryId, 10) === hid) return crRows[i];
		}
		return null;
	}

	function crFormError(msg) {
		var el = document.getElementById('cr-add-error');
		if (!el) return;
		el.textContent = msg;
		el.style.display = 'block';
	}
	function crClearFormError() {
		var el = document.getElementById('cr-add-error');
		if (el) el.style.display = 'none';
	}

	// The Add form's office picker -- the only place this tab needs the position
	// list. window.moGetOfficeList() wraps the host's moData the same way
	// window.moFindPosition/window.moOccupancyOf already do, rather than
	// exposing moData itself across the file boundary.
	window.crPopulateOfficeSelect = function () {
		var sel = document.getElementById('cr-add-pos');
		if (!sel) return;
		var current = sel.value;
		var all = window.moGetOfficeList();
		var opts = '<option value="">Select an office...</option>';
		all.slice().sort(function(a, b) {
			var ac = (a.Classification === 'crown') ? 0 : 1;
			var bc = (b.Classification === 'crown') ? 0 : 1;
			if (ac !== bc) return ac - bc;
			return String(a.DisplayTitle || a.Title || '').localeCompare(String(b.DisplayTitle || b.Title || ''));
		}).forEach(function(pos) {
			var pid = parseInt(pos.PositionId, 10);
			var label = pos.DisplayTitle || pos.Title || ('#' + pid);
			if (pos.RetiredAt) label += ' (retired)';
			opts += '<option value="' + pid + '">' + window.moEsc(label) + '</option>';
		});
		sel.innerHTML = opts;
		if (current) sel.value = current;
	};

	// ---------- Load + render ----------
	function crLoad() {
		var loadingEl = document.getElementById('cr-loading');
		var listEl    = document.getElementById('cr-list');
		var emptyEl   = document.getElementById('cr-empty');
		var errorEl   = document.getElementById('cr-error');
		if (!loadingEl) return;
		loadingEl.style.display = '';
		listEl.style.display  = 'none';
		emptyEl.style.display = 'none';
		errorEl.style.display = 'none';
		$.getJSON(crUrl(), function(resp) {
			loadingEl.style.display = 'none';
			if (!resp || resp.status !== 0) {
				errorEl.textContent = (resp && resp.error) ? resp.error : 'Failed to load officer history.';
				errorEl.style.display = '';
				return;
			}
			crRows = resp.history || [];
			crEditingId = 0;
			crRenderList();
		}).fail(function() {
			loadingEl.style.display = 'none';
			errorEl.textContent = 'Network error loading officer history.';
			errorEl.style.display = '';
		});
	}
	// Public refresh, per this partial's include contract.
	window.crRefresh = crLoad;

	function crRenderList() {
		var listEl  = document.getElementById('cr-list');
		var emptyEl = document.getElementById('cr-empty');
		if (!listEl) return;
		if (!crRows.length) {
			listEl.style.display = 'none';
			listEl.innerHTML = '';
			emptyEl.style.display = '';
			return;
		}
		emptyEl.style.display = 'none';
		listEl.style.display  = '';

		var groups = {};
		crRows.forEach(function(row) {
			var key = crGroupKey(row);
			if (!groups[key]) groups[key] = { label: crLabelFor(row), rank: crClassRank(row.Classification), rows: [] };
			groups[key].rows.push(row);
		});
		var keys = Object.keys(groups).sort(function(a, b) {
			if (groups[a].rank !== groups[b].rank) return groups[a].rank - groups[b].rank;
			return groups[a].label.localeCompare(groups[b].label);
		});

		var html = '';
		keys.forEach(function(key) {
			var g = groups[key];
			// Newest term first: start date descending, then id descending so two
			// terms starting the same day still have a stable order.
			g.rows.sort(function(a, b) {
				var sa = crIsoOrEmpty(a.StartDate), sb = crIsoOrEmpty(b.StartDate);
				if (sa !== sb) return sa < sb ? 1 : -1;
				return (parseInt(b.OfficerHistoryId, 10) || 0) - (parseInt(a.OfficerHistoryId, 10) || 0);
			});
			html += '<details class="cr-panel" open>' +
				'<summary class="cr-summary">' +
					(g.rank === 0 ? '<i class="fas fa-crown mo-crown-glyph" aria-hidden="true"></i>' : '') +
					'<span class="cr-summary-title">' + window.moEsc(g.label) + '</span>' +
					'<span class="cr-summary-count">' + g.rows.length + (g.rows.length === 1 ? ' term' : ' terms') + '</span>' +
				'</summary>' +
				'<div class="cr-panel-body">';
			g.rows.forEach(function(row) {
				var hid = parseInt(row.OfficerHistoryId, 10);
				html += (crEditingId === hid) ? crEditRowHtml(row) : crRowHtml(row);
			});
			html += '</div></details>';
		});
		listEl.innerHTML = html;

		if (crEditingId) crInitEditFp();
	}

	function crRowHtml(row) {
		var hid = parseInt(row.OfficerHistoryId, 10);
		var mid = parseInt(row.MundaneId, 10) || 0;
		var personaHtml = mid > 0
			? '<a href="' + crUIR() + 'Player/profile/' + mid + '" class="cr-persona-link">' + window.moEsc(row.Persona || 'Unknown') + '</a>'
			: '<span class="mo-vacant">(Vacant)</span>';
		var start = crFmtDate(row.StartDate);
		var end   = crFmtDate(row.EndDate);
		var termHtml = crIsOpenTerm(row)
			? ((start ? window.moEsc(start) : 'Unknown start') + ' &ndash; present')
			: ((start ? window.moEsc(start) : 'Unknown start') + ' &ndash; ' + (end ? window.moEsc(end) : 'unknown end'));
		var noteHtml = row.Notes ? ('<div class="cr-row-note">' + window.moEsc(row.Notes) + '</div>') : '';
		return '<div class="cr-row" data-hid="' + hid + '">' +
			'<div class="cr-row-main">' +
				'<div class="cr-row-persona">' + personaHtml + '</div>' +
				'<div class="cr-row-term">' + termHtml + '</div>' +
				noteHtml +
			'</div>' +
			'<div class="mo-actions cr-row-actions">' +
				'<button type="button" class="mo-act-btn" onclick="crEdit(' + hid + ')"><i class="fas fa-pencil-alt" aria-hidden="true"></i> Edit</button>' +
				'<button type="button" class="mo-act-btn mo-act-danger" onclick="crDelete(' + hid + ')"><i class="fas fa-trash-alt" aria-hidden="true"></i> Delete</button>' +
			'</div>' +
		'</div>';
	}

	function crEditRowHtml(row) {
		var hid = parseInt(row.OfficerHistoryId, 10);
		return '<div class="cr-row cr-row-editing" data-hid="' + hid +
			'" data-orig-start="' + window.moEscAttr(crIsoOrEmpty(row.StartDate)) +
			'" data-orig-end="' + window.moEscAttr(crIsoOrEmpty(row.EndDate)) + '">' +
			'<div class="ka-feedback ka-feedback-err" id="cr-edit-error" style="display:none"></div>' +
			'<div class="ka-field-row">' +
				'<div class="ka-field">' +
					'<label for="cr-edit-start">Term Start <span class="ka-hint">(optional)</span></label>' +
					'<input type="text" id="cr-edit-start" placeholder="unknown" autocomplete="off" />' +
				'</div>' +
				'<div class="ka-field">' +
					'<label for="cr-edit-end">Term End <span class="ka-hint">(optional &mdash; leave blank if open)</span></label>' +
					'<input type="text" id="cr-edit-end" autocomplete="off" />' +
				'</div>' +
			'</div>' +
			'<div class="ka-field">' +
				'<label for="cr-edit-note">Note <span class="ka-hint">(optional)</span></label>' +
				'<textarea id="cr-edit-note" rows="2" maxlength="500">' + window.moEsc(row.Notes || '') + '</textarea>' +
			'</div>' +
			'<div class="cr-edit-actions">' +
				'<button type="button" class="kn-btn" onclick="crCancelEdit()">Cancel</button>' +
				'<button type="button" class="kn-btn kn-btn-primary" id="cr-edit-save-btn" onclick="crSaveEdit(' + hid + ')">' +
					'<i class="fas fa-save" aria-hidden="true"></i> Save</button>' +
			'</div>' +
		'</div>';
	}

	// Fresh flatpickr instances every time the edit row enters the DOM: unlike
	// the static sub-modals, this row is destroyed and rebuilt by crRenderList's
	// innerHTML on every render, so a prior instance's target node is already
	// gone and must not be reused -- and disabling it (were that ever needed)
	// would have to hit fp.altInput too, per the wizard's lesson.
	function crInitEditFp() {
		if (crEditStartFp) { try { crEditStartFp.destroy(); } catch (e) {} crEditStartFp = null; }
		if (crEditEndFp)   { try { crEditEndFp.destroy(); }   catch (e) {} crEditEndFp = null; }
		if (typeof flatpickr === 'undefined') return;
		var row = document.querySelector('.cr-row-editing');
		if (!row) return;
		var opts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' };
		crEditStartFp = flatpickr('#cr-edit-start', opts);
		// NO maxDate here, unlike the Add form and the transition wizard's
		// otOutEndFp: this field must be able to DISPLAY whatever is already on
		// file, including a bad legacy end date that predates that rule. Capping
		// it at 'today' made flatpickr silently blank a future-dated value on
		// setDate() below instead of showing it -- a real data-loss-on-display
		// bug, not just a missed restriction. The server still rejects a future
		// end date on save with its own message.
		crEditEndFp = flatpickr('#cr-edit-end', opts);
		var origStart = row.getAttribute('data-orig-start') || '';
		var origEnd   = row.getAttribute('data-orig-end') || '';
		if (origStart) crEditStartFp.setDate(origStart, true); else crEditStartFp.clear();
		if (origEnd)   crEditEndFp.setDate(origEnd, true);     else crEditEndFp.clear();
	}

	window.crEdit = function(hid) {
		// Only one row editable at a time -- opening a second Edit simply
		// re-renders with the new target, discarding any unsaved first edit.
		crEditingId = parseInt(hid, 10) || 0;
		crRenderList();
	};
	window.crCancelEdit = function() {
		crEditingId = 0;
		crRenderList();
	};

	// edithistory uses array_key_exists semantics server-side: a key PRESENT
	// (even empty) CLEARS that date, a key ABSENT leaves it unchanged. Only the
	// fields the user actually changed are sent, so a note-only edit can never
	// wipe a date it never showed changing.
	window.crSaveEdit = function(hid) {
		var row = crFindRow(hid);
		if (!row) return;
		var origStart = crIsoOrEmpty(row.StartDate);
		var origEnd   = crIsoOrEmpty(row.EndDate);
		var origNote  = row.Notes || '';

		var newStart = crRaw('cr-edit-start');
		var newEnd   = crRaw('cr-edit-end');
		var newNote  = document.getElementById('cr-edit-note').value;

		var data = { OfficerHistoryId: hid };
		if (newStart !== origStart) data.StartDate = newStart;
		if (newEnd   !== origEnd)   data.EndDate   = newEnd;
		if (newNote  !== origNote)  data.Note      = newNote;

		var btn = document.getElementById('cr-edit-save-btn');
		if (!btn || btn.disabled) return;
		var original = btn.innerHTML;
		// Busy is set BEFORE the network call only inside this try/catch, and
		// onErr always restores it -- a synchronous throw here would otherwise
		// strand the button on "Saving..." with no POST ever issued.
		try {
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
			window.moPost('edithistory', data, function() {
				crEditingId = 0;
				crLoad();
			}, function() {
				btn.disabled = false;
				btn.innerHTML = original;
			});
		} catch (e) {
			btn.disabled = false;
			btn.innerHTML = original;
			window.moShowNotice('Error', 'Could not save this term. Please try again.');
		}
	};

	window.crDelete = function(hid) {
		window.moShowConfirm('Delete This Term?',
			'This removes the record permanently. The audit log keeps a copy.',
			'Delete',
			function() {
				window.moPost('deletehistory', { OfficerHistoryId: hid }, function() {
					window.moCloseConfirm();
					if (crEditingId === hid) crEditingId = 0;
					crLoad();
				});
			});
	};

	// ---------- Add a Term ----------
	function initCrAddFp() {
		if (typeof flatpickr === 'undefined') return;
		var opts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' };
		if (!crAddStartFp) crAddStartFp = flatpickr('#cr-add-start', opts);
		if (!crAddEndFp)   crAddEndFp   = flatpickr('#cr-add-end', Object.assign({}, opts, { maxDate: 'today' }));
	}
	function crResetAddForm() {
		document.getElementById('cr-add-pos').value = '';
		document.getElementById('cr-add-player').value = '';
		document.getElementById('cr-add-id').value = '';
		document.getElementById('cr-add-note').value = '';
		if (crAddStartFp) crAddStartFp.clear(); else document.getElementById('cr-add-start').value = '';
		if (crAddEndFp)   crAddEndFp.clear();   else document.getElementById('cr-add-end').value = '';
	}

	window.crAdd = function() {
		var posId = parseInt(document.getElementById('cr-add-pos').value || 0, 10);
		var mid   = parseInt(document.getElementById('cr-add-id').value || 0, 10);
		if (!posId) { crFormError('Please select an office.'); return; }
		if (!mid)   { crFormError('Please pick a player from the search results.'); return; }
		crClearFormError();

		// PositionId's kingdom already comes from base()'s URL; ParkId is the
		// only scope this needs to add, because AddHistoryTerm is creating a row
		// that does not exist yet for the domain to authorize against by its own
		// kingdom/park (contrast edit/delete, which send neither).
		var d = {
			PositionId: posId,
			MundaneId:  mid,
			StartDate:  crRaw('cr-add-start'),
			EndDate:    crRaw('cr-add-end'),
			Note:       document.getElementById('cr-add-note').value
		};
		if (window.MoConfig && MoConfig.parkId) { d.ParkId = MoConfig.parkId; }

		var btn = document.getElementById('cr-add-btn');
		if (btn.disabled) return;
		var original = btn.innerHTML;
		try {
			btn.disabled = true;
			btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Adding...';
			window.moPost('addhistory', d, function() {
				btn.disabled = false;
				btn.innerHTML = original;
				crResetAddForm();
				crLoad();
			}, function() {
				btn.disabled = false;
				btn.innerHTML = original;
			});
		} catch (e) {
			btn.disabled = false;
			btn.innerHTML = original;
			window.moShowNotice('Error', 'Could not add this term. Please try again.');
		}
	};

	// ---------- Add-form player autocomplete (identical wiring to the occupant
	// search above: same kn-ac-results dropdown, same tnFixedAcPosition() call
	// before opening) ----------
	(function() {
		var input   = document.getElementById('cr-add-player');
		var hidden  = document.getElementById('cr-add-id');
		var results = document.getElementById('cr-add-results');
		if (!input) return;
		var debounce;
		function crAcSelect(el) {
			if (!el) return;
			input.value  = el.getAttribute('data-persona') || '';
			hidden.value = el.getAttribute('data-id') || '';
			crAcClose();
		}
		function crAcOpen() {
			if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
			results.classList.add('kn-ac-open');
			input.setAttribute('aria-expanded', 'true');
		}
		function crAcClose() {
			results.innerHTML = '';
			results.classList.remove('kn-ac-open');
			input.setAttribute('aria-expanded', 'false');
		}
		input.addEventListener('input', function() {
			clearTimeout(debounce);
			hidden.value = '';
			var q = input.value.trim();
			if (q.length < 2) { crAcClose(); return; }
			debounce = setTimeout(function() {
				$.getJSON(window.moSearchUrl(q), function(data) {
					results.innerHTML = '';
					if (!data || data.length === 0) {
						results.innerHTML = '<div class="kn-ac-item kn-ac-empty">No results</div>';
						crAcOpen();
						return;
					}
					for (var i = 0; i < data.length; i++) {
						var d = data[i];
						var el = document.createElement('div');
						el.className = 'kn-ac-item';
						el.setAttribute('data-id', d.MundaneId);
						el.setAttribute('data-persona', d.Persona || '');
						el.innerHTML = window.moEsc(d.Persona) + ' <span style="color:#a0aec0;font-size:11px">(' + window.moEsc((d.KAbbr || '') + ':' + (d.PAbbr || '')) + ')</span>';
						el.addEventListener('click', (function(node) {
							return function() { crAcSelect(node); };
						})(el));
						results.appendChild(el);
					}
					crAcOpen();
				});
			}, 250);
		});
		input.addEventListener('keydown', function(e) {
			var acOpen = results.classList.contains('kn-ac-open');
			if (e.key === 'Escape') {
				if (acOpen) { e.preventDefault(); e.stopPropagation(); crAcClose(); }
				return;
			}
			var items = results.querySelectorAll('.kn-ac-item[data-id]');
			if (!items.length) {
				if (e.key === 'Enter' && acOpen) e.preventDefault();
				return;
			}
			var focused = results.querySelector('.kn-ac-focused');
			if (e.key === 'ArrowDown') {
				e.preventDefault();
				var next = focused ? (focused.nextElementSibling || items[0]) : items[0];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (next && next.getAttribute('data-id')) next.classList.add('kn-ac-focused');
			} else if (e.key === 'ArrowUp') {
				e.preventDefault();
				var prev = focused ? (focused.previousElementSibling || items[items.length - 1]) : items[items.length - 1];
				if (focused) focused.classList.remove('kn-ac-focused');
				if (prev && prev.getAttribute('data-id')) prev.classList.add('kn-ac-focused');
			} else if (e.key === 'Enter' && acOpen) {
				e.preventDefault();
				if (focused) crAcSelect(focused);
			}
		});
		document.addEventListener('click', function(e) {
			if (!results.contains(e.target) && e.target !== input) crAcClose();
		});
	})();

	// ---------- Tabs ----------
	window.crOpen = function() { crShowTab('rolls'); };
	window.crShowTab = function(tab) {
		var isRolls = (tab === 'rolls');
		document.getElementById('mo-tabbtn-positions').classList.toggle('mo-tab-active', !isRolls);
		document.getElementById('mo-tabbtn-positions').setAttribute('aria-selected', String(!isRolls));
		document.getElementById('mo-tabbtn-rolls').classList.toggle('mo-tab-active', isRolls);
		document.getElementById('mo-tabbtn-rolls').setAttribute('aria-selected', String(isRolls));
		document.getElementById('mo-tabpanel-positions').hidden = isRolls;
		document.getElementById('mo-tabpanel-rolls').hidden = !isRolls;
		if (isRolls) {
			crPopulateOfficeSelect();
			initCrAddFp();
			// Lazy-load on first activation, not on modal open -- same rule the
			// public History tab uses.
			if (!crLoaded) { crLoaded = true; crLoad(); }
		}
	};
})();
</script>
