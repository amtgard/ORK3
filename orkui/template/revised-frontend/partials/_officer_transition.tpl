<?php
/* =====================================================================
   Officer Transition wizard — rendered INTO the Manage Officers modal body.
   ---------------------------------------------------------------------
   Not a second overlay. otOpen() hides #mo-cards and shows #ot-root inside
   the SAME modal, so there is one overlay and one scroll context, and the
   autocomplete positioner the host partial defines at :521 still applies.

   Steps: 1 close the outgoing term · 2 the incoming officer · 3 review.
   'appoint' mode skips step 1 entirely — the office is vacant, so there is
   no outgoing term, and TransitionOfficer's ordering check is already
   conditioned on there being an outgoing holder.

   No include contract of its own: it reads window.MoConfig (set by the host
   partial's own <script>, further down the page) and window.moPost /
   moShowNotice / moFindPosition / moOccupancyOf / moEsc / moBase /
   moSearchUrl (bridged from that same script — see the comment beside
   window.moRefresh there). Those are only ever referenced from inside a
   click/load handler here, never at the top of this file, so it does not
   matter that this markup — and this <script> tag — sits EARLIER in the
   document than the host's own <script> tag: by the time a user can click
   anything, both have already run.
   ===================================================================== */
?>
<div id="ot-root" style="display:none">
	<button type="button" class="ot-back" id="ot-back" onclick="otClose()">
		<i class="fas fa-chevron-left" aria-hidden="true"></i> Back to officers
	</button>
	<h3 class="ot-title" id="ot-title"></h3>
	<ol class="ot-steps" id="ot-steps" aria-label="Progress"></ol>
	<div class="ka-feedback ka-feedback-err" id="ot-error" style="display:none"></div>

	<section class="ot-step" id="ot-step-1">
		<p class="ot-lede">
			<strong id="ot-outgoing-name"></strong> has served as
			<span id="ot-outgoing-office"></span><span id="ot-outgoing-since"></span>.
		</p>
		<div class="ot-hint" id="ot-unknown-start" style="display:none">
			<i class="fas fa-info-circle" aria-hidden="true"></i>
			<span>The ORK has no start date on file for this term. You can supply one now, or leave it blank.</span>
		</div>
		<div class="ka-field-row">
			<div class="ka-field">
				<label for="ot-out-start">Took office <span class="ka-hint">(optional)</span></label>
				<input type="text" id="ot-out-start" placeholder="unknown" autocomplete="off" />
			</div>
			<div class="ka-field">
				<label for="ot-out-end">Term ended <span class="ka-hint">(optional &mdash; defaults to today)</span></label>
				<input type="text" id="ot-out-end" autocomplete="off" />
			</div>
		</div>
	</section>

	<section class="ot-step" id="ot-step-2">
		<div class="ka-field ka-field-ac">
			<label for="ot-in-player">Incoming officer <span class="ka-req" aria-hidden="true">*</span></label>
			<input type="text" id="ot-in-player" placeholder="Search by persona..." autocomplete="off"
			       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ot-in-results" />
			<input type="hidden" id="ot-in-id" value="" />
			<div class="kn-ac-results" id="ot-in-results" style="position:fixed" role="listbox"></div>
		</div>
		<div class="ka-field">
			<label for="ot-in-start">Takes office <span class="ka-hint">(optional &mdash; defaults to today)</span></label>
			<input type="text" id="ot-in-start" autocomplete="off" />
		</div>
		<div class="ka-field">
			<label for="ot-in-note">Note <span class="ka-hint">(optional)</span></label>
			<textarea id="ot-in-note" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
		</div>
	</section>

	<section class="ot-step" id="ot-step-3">
		<ul class="ot-review" id="ot-review"></ul>
	</section>

	<div class="ot-actions">
		<button type="button" class="kn-btn" id="ot-cancel" onclick="otClose()">Cancel</button>
		<button type="button" class="kn-btn" id="ot-prev" onclick="otPrev()">&larr; Back</button>
		<button type="button" class="kn-btn kn-btn-primary" id="ot-next" onclick="otNext()">Next &rarr;</button>
		<button type="button" class="kn-btn kn-btn-primary" id="ot-commit" onclick="otCommit()">Confirm Transition</button>
	</div>
</div>

<style>
/* ============ Officer Transition wizard ============ */
.ot-back {
	background: none; border: none; padding: 0; margin-bottom: 12px; cursor: pointer;
	font-size: 13px; font-weight: 600; color: var(--ork-blue-link);
	display: inline-flex; align-items: center; gap: 6px;
}
.ot-back:hover, .ot-back:focus-visible { text-decoration: underline; }
.ot-back:focus-visible { outline: 2px solid var(--ork-blue-link); outline-offset: 2px; border-radius: 3px; }

/* Global h1-h6 in orkui.css get a gray pill box -- reset it for a modal heading. */
.ot-title {
	font-size: 16px; font-weight: 700; color: var(--ork-text); margin: 0 0 14px 0;
	background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none;
}

.ot-steps { display: flex; align-items: center; gap: 8px; list-style: none; margin: 0 0 18px 0; padding: 0; flex-wrap: wrap; }
.ot-step-pill {
	font-size: 12px; font-weight: 600; color: var(--ork-text-muted); background: var(--ork-bg-tertiary);
	border: 1px solid var(--ork-border); border-radius: 999px; padding: 4px 12px; white-space: nowrap;
}
.ot-step-pill.ot-step-current { background: var(--ork-badge-blue-bg); color: var(--ork-badge-blue-text); border-color: var(--ork-badge-blue-bg); }
.ot-step-pill.ot-step-done { color: var(--ork-text-secondary); }

.ot-step { margin-bottom: 4px; }

.ot-lede { font-size: 14px; color: var(--ork-text); line-height: 1.5; margin: 0 0 12px 0; }

.ot-hint {
	display: flex; align-items: flex-start; gap: 8px; margin-bottom: 14px;
	padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.45;
	background: var(--ork-alert-info-bg); color: var(--ork-alert-info-text);
	border: 1px solid var(--ork-alert-info-border);
}
.ot-hint i { margin-top: 2px; flex-shrink: 0; }

.ot-review { margin: 0; padding-left: 20px; font-size: 14px; color: var(--ork-text); line-height: 1.6; }
.ot-review li { margin: 4px 0; }

.ot-actions { display: flex; align-items: center; gap: 10px; margin-top: 20px; flex-wrap: wrap; }
#ot-cancel { margin-right: auto; }

@media (max-width: 768px) {
	.ot-actions { flex-wrap: wrap; }
	.ot-actions .kn-btn { flex: 1 1 auto; }
	#ot-cancel { margin-right: 0; order: 3; }
	#ot-prev { order: 1; }
	#ot-next, #ot-commit { order: 2; }
}
</style>

<script>
(function () {
	if (!document.getElementById('ot-root')) return;

	var otState = null;
	var otOutStartFp = null, otOutEndFp = null, otInStartFp = null;

	// Flatpickr's altInput swaps in a display field; the real Y-m-d lives on the
	// original input. Returns '' when the field is empty, which every date on this
	// form is allowed to be. (Task 6 uses the identical helper named crRaw.)
	function otRaw(id) {
		var el = document.getElementById(id);
		return el && el.value ? el.value : '';
	}

	function otHumanize(iso) {
		if (!iso) return '';
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso);
		if (!m) return iso;
		var months = ['January', 'February', 'March', 'April', 'May', 'June', 'July',
			'August', 'September', 'October', 'November', 'December'];
		return months[parseInt(m[2], 10) - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
	}

	function otFindPosition(pid) {
		return (typeof window.moFindPosition === 'function') ? window.moFindPosition(pid) : null;
	}

	function otShowError(msg) {
		var el = document.getElementById('ot-error');
		el.textContent = msg;
		el.style.display = 'block';
	}
	function otClearError() {
		var el = document.getElementById('ot-error');
		el.style.display = 'none';
	}

	function initOtFp() {
		if (typeof flatpickr === 'undefined') return;
		var opts = { dateFormat: 'Y-m-d', altInput: true, altFormat: 'F j, Y' };
		if (!otOutStartFp) otOutStartFp = flatpickr('#ot-out-start', opts);
		// The server (class.OfficerPosition.php) always rejects a future term-end date --
		// don't let the picker offer dates it will only bounce back.
		if (!otOutEndFp)   otOutEndFp   = flatpickr('#ot-out-end', Object.assign({}, opts, { maxDate: 'today' }));
		if (!otInStartFp)  otInStartFp  = flatpickr('#ot-in-start', opts);
	}

	// ---------- Open / close ----------
	window.otOpen = function (positionId, mode) {
		var pos = otFindPosition(positionId);
		if (!pos) {
			window.moShowNotice('Not Found', 'That office is no longer listed. Refresh and try again.');
			return;
		}
		otState = { pos: pos, mode: mode, step: (mode === 'appoint') ? 2 : 1 };
		initOtFp();
		otResetForm();
		otClearError();
		document.getElementById('mo-cards').style.display = 'none';
		document.getElementById('ot-root').style.display  = '';
		otRender();
	};

	window.otClose = function () {
		document.getElementById('ot-root').style.display  = 'none';
		document.getElementById('mo-cards').style.display = '';
		otAcClose();
		otState = null;
		window.moRefresh();
		// otCommit()'s success path routes through here. Correct the Rolls lists the
		// same history rows a transition writes -- without this, a user who has that
		// tab open and switches back to it after a transition sees stale data. Guarded:
		// crRefresh may not exist in every host this wizard is dropped into.
		if (typeof window.crRefresh === 'function') { try { window.crRefresh(); } catch (e) {} }
	};

	function otResetForm() {
		// A fresh open must never inherit a stuck busy-state from a prior attempt
		// in the same page (e.g. a network failure) -- otherwise the wizard would
		// silently open with the commit button permanently disabled.
		var commitBtn = document.getElementById('ot-commit');
		commitBtn.disabled = false;
		commitBtn.innerHTML = 'Confirm Transition';

		var occs = window.moOccupancyOf(otState.pos) || [];
		var occ  = occs[0] || null;

		// Step 1 -- outgoing officer. Never guess a start date: if none is on file,
		// leave the field blank and show the hint instead.
		document.getElementById('ot-outgoing-name').textContent   = occ ? (occ.Persona || 'The current officer') : '';
		document.getElementById('ot-outgoing-office').textContent = otState.pos.DisplayTitle || otState.pos.Title || 'this office';
		var hasStart = !!(occ && occ.TermStartRaw);
		document.getElementById('ot-outgoing-since').textContent = hasStart ? (' since ' + occ.TermStart) : '';
		document.getElementById('ot-unknown-start').style.display = hasStart ? 'none' : '';
		var outStartEl = document.getElementById('ot-out-start');
		if (otOutStartFp) {
			if (hasStart) { otOutStartFp.setDate(occ.TermStartRaw, true); } else { otOutStartFp.clear(); }
		} else {
			outStartEl.value = hasStart ? occ.TermStartRaw : '';
		}
		// Already on file -- a transition never overwrites an existing start date
		// server-side, so editing this field would silently do nothing. Disabling it
		// says so instead of inviting an edit that cannot take effect.
		//
		// Flatpickr copies `disabled` onto its altInput ONCE, at construction time
		// (initOtFp(), which runs before this ever executes) -- it never re-reads the
		// original input afterward. Disabling only outStartEl here would leave the
		// VISIBLE altInput enabled: still clickable, still typeable, still opening the
		// calendar. Sync both the original input and the altInput/clickOpens setting
		// so the field the user actually sees and touches is genuinely inert.
		outStartEl.disabled = hasStart;
		if (otOutStartFp) {
			otOutStartFp.altInput.disabled = hasStart;
			otOutStartFp.set('clickOpens', !hasStart);
		}
		if (otOutEndFp) { otOutEndFp.clear(); } else { document.getElementById('ot-out-end').value = ''; }

		// Step 2 -- incoming officer.
		document.getElementById('ot-in-player').value = '';
		document.getElementById('ot-in-id').value = '';
		document.getElementById('ot-in-note').value = '';
		if (otInStartFp) { otInStartFp.setDate(new Date(), true); } else { document.getElementById('ot-in-start').value = ''; }
		otAcClose();
	}

	// ---------- Render ----------
	function otRender() {
		var s = otState;
		if (!s) return;
		var officeName = s.pos.DisplayTitle || s.pos.Title || 'this office';

		document.getElementById('ot-title').textContent =
			(s.mode === 'appoint' ? 'Appoint — ' : 'Transition — ') + officeName;

		var labels    = (s.mode === 'appoint') ? ['Incoming officer', 'Review'] : ['Outgoing term', 'Incoming officer', 'Review'];
		var firstStep = (s.mode === 'appoint') ? 2 : 1;
		var stepsHtml = '';
		labels.forEach(function (label, i) {
			var num = firstStep + i;
			var cls = 'ot-step-pill';
			if (num === s.step) cls += ' ot-step-current';
			else if (num < s.step) cls += ' ot-step-done';
			stepsHtml += '<li class="' + cls + '">' + (num - firstStep + 1) + '. ' + label + '</li>';
		});
		document.getElementById('ot-steps').innerHTML = stepsHtml;

		document.getElementById('ot-step-1').style.display = (s.step === 1) ? '' : 'none';
		document.getElementById('ot-step-2').style.display = (s.step === 2) ? '' : 'none';
		document.getElementById('ot-step-3').style.display = (s.step === 3) ? '' : 'none';

		document.getElementById('ot-prev').style.display   = (s.step > firstStep) ? '' : 'none';
		document.getElementById('ot-next').style.display   = (s.step < 3) ? '' : 'none';
		var commitBtn = document.getElementById('ot-commit');
		commitBtn.style.display = (s.step === 3) ? '' : 'none';
		// otRender() only ever runs between saves (a successful commit closes the
		// wizard; a failed one is handled by otCommit's own onErr), so it is always
		// safe to set the label here without clobbering an in-flight spinner.
		commitBtn.textContent = (s.mode === 'appoint') ? 'Confirm Appointment' : 'Confirm Transition';

		if (s.step === 3) otRenderReview();
	}

	function otRenderReview() {
		var s = otState;
		var occs = window.moOccupancyOf(s.pos) || [];
		var occ  = occs[0] || null;
		var officeName = s.pos.DisplayTitle || s.pos.Title || 'this office';
		var inName  = document.getElementById('ot-in-player').value || 'The selected member';
		// otHumanize() returns its input VERBATIM when it does not match the Y-m-d shape
		// it expects -- e.g. when flatpickr failed to load (initOtFp() bails silently)
		// and these became plain text inputs a user can type anything into. Every value
		// reaching this innerHTML must go through moEsc() same as its siblings.
		var inStart = window.moEsc(otHumanize(otRaw('ot-in-start')) || 'today');
		var lines = [];

		if (s.mode === 'transition' && occ) {
			var outEnd = window.moEsc(otHumanize(otRaw('ot-out-end')) || 'today');
			lines.push('<li>' + window.moEsc(occ.Persona || 'The current officer') +
				'&rsquo;s term as <strong>' + window.moEsc(officeName) + '</strong> ends ' + outEnd + '.</li>');
			var outStart = otRaw('ot-out-start');
			if (outStart && !occ.TermStartRaw) {
				lines.push('<li>Their term start will be recorded as ' + window.moEsc(otHumanize(outStart)) + '.</li>');
			}
		}
		lines.push('<li><strong>' + window.moEsc(inName) + '</strong> becomes <strong>' +
			window.moEsc(officeName) + '</strong>, effective ' + inStart + '.</li>');
		var note = document.getElementById('ot-in-note').value.trim();
		if (note) lines.push('<li>Note: ' + window.moEsc(note) + '</li>');

		document.getElementById('ot-review').innerHTML = lines.join('');
	}

	// ---------- Step navigation ----------
	function otValidateStep(step) {
		if (step === 2 && !document.getElementById('ot-in-id').value) {
			return 'Please pick a player from the search results.';
		}
		return null;
	}

	window.otNext = function () {
		var err = otValidateStep(otState.step);
		if (err) { otShowError(err); return; }
		otClearError();
		otState.step++;
		otRender();
	};

	window.otPrev = function () {
		var firstStep = (otState.mode === 'appoint') ? 2 : 1;
		if (otState.step <= firstStep) return;
		otClearError();
		otState.step--;
		otRender();
	};

	// ---------- Commit ----------
	window.otCommit = function () {
		var s = otState;
		if (!s || s.step !== 3) return;   // only meaningful on the review step
		var btn = document.getElementById('ot-commit');
		if (btn.disabled) return;         // already in flight -- ignore a duplicate/double click

		var d = {
			PositionId: s.pos.PositionId,
			MundaneId:  document.getElementById('ot-in-id').value,
			TermStart:  otRaw('ot-in-start'),
			Note:       document.getElementById('ot-in-note').value
		};
		if (s.mode === 'transition') {
			d.OutgoingEndDate   = otRaw('ot-out-end');
			d.OutgoingStartDate = otRaw('ot-out-start');
		}
		// transition creates a new incumbent's OfficerHistory row, which has no
		// existing PositionId/OfficerHistoryId scope for the domain to authorize
		// against yet -- so ParkId must travel explicitly, same as addhistory in
		// _correct_the_rolls.tpl. The kingdom console never sets MoConfig.parkId,
		// so this stays a no-op there.
		if (window.MoConfig && window.MoConfig.parkId) { d.ParkId = window.MoConfig.parkId; }

		var original = btn.innerHTML;
		btn.disabled = true;
		btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
		// A synchronous throw inside moPost() (e.g. $ unresolvable, base() throwing, the
		// bridge never having been assigned because the host IIFE died) would otherwise
		// strand the button on "Saving..." forever with no POST ever issued -- indistin-
		// guishable from a hung network request. Restore the button on that path too.
		try {
			window.moPost('transition', d, function () {
				otClose();
			}, function () {
				btn.disabled = false;
				btn.innerHTML = original;
			});
		} catch (e) {
			btn.disabled = false;
			btn.innerHTML = original;
			window.moShowNotice('Error', 'Could not submit the transition. Please try again.');
		}
	};

	// ---------- Incoming officer autocomplete (copies the host's occupant search
	// wiring in shape: same searchUrl(), same kn-ac-results dropdown, same
	// tnFixedAcPosition() call before opening) ----------
	(function () {
		var input   = document.getElementById('ot-in-player');
		var hidden  = document.getElementById('ot-in-id');
		var results = document.getElementById('ot-in-results');
		if (!input) return;
		var debounce;

		function otAcSelect(el) {
			if (!el) return;
			input.value  = el.getAttribute('data-persona') || '';
			hidden.value = el.getAttribute('data-id') || '';
			otAcClose();
		}
		function otAcOpen() {
			if (typeof tnFixedAcPosition === 'function') tnFixedAcPosition(input, results);
			results.classList.add('kn-ac-open');
			input.setAttribute('aria-expanded', 'true');
		}
		input.addEventListener('input', function () {
			clearTimeout(debounce);
			hidden.value = '';
			var q = input.value.trim();
			if (q.length < 2) { otAcClose(); return; }
			debounce = setTimeout(function () {
				$.getJSON(window.moSearchUrl(q), function (data) {
					results.innerHTML = '';
					if (!data || data.length === 0) {
						results.innerHTML = '<div class="kn-ac-item kn-ac-empty">No results</div>';
						otAcOpen();
						return;
					}
					for (var i = 0; i < data.length; i++) {
						var d = data[i];
						var el = document.createElement('div');
						el.className = 'kn-ac-item';
						el.setAttribute('data-id', d.MundaneId);
						el.setAttribute('data-persona', d.Persona || '');
						el.innerHTML = window.moEsc(d.Persona) +
							' <span style="color:#a0aec0;font-size:11px">(' + window.moEsc((d.KAbbr || '') + ':' + (d.PAbbr || '')) + ')</span>';
						el.addEventListener('click', (function (node) {
							return function () { otAcSelect(node); };
						})(el));
						results.appendChild(el);
					}
					otAcOpen();
				});
			}, 250);
		});
		input.addEventListener('keydown', function (e) {
			var acOpen = results.classList.contains('kn-ac-open');
			// Escape is handled BEFORE the items.length early-return below, and is
			// gated on acOpen (the dropdown's visibility) rather than on whether it
			// has any selectable items. items is `.kn-ac-item[data-id]`, which is
			// EMPTY both when the dropdown is closed AND when it is open showing the
			// "No results" placeholder (that div carries no data-id) -- gating on
			// items.length let a no-results Escape fall through the early return
			// and bubble to the host modal's document-level Escape handler
			// (_kingdom_admin_modals.tpl), closing the ENTIRE Manage Officers modal
			// out from under the wizard. When the dropdown is not open, Escape is
			// left alone so it can still bubble and close the host modal as usual.
			if (e.key === 'Escape') {
				if (acOpen) {
					e.preventDefault();
					e.stopPropagation();
					otAcClose();
				}
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
				if (focused) otAcSelect(focused);
			}
		});
		document.addEventListener('click', function (e) {
			if (!results.contains(e.target) && e.target !== input) otAcClose();
		});
	})();

	function otAcClose() {
		var input   = document.getElementById('ot-in-player');
		var results = document.getElementById('ot-in-results');
		if (results) { results.innerHTML = ''; results.classList.remove('kn-ac-open'); }
		if (input) input.setAttribute('aria-expanded', 'false');
	}
})();
</script>
