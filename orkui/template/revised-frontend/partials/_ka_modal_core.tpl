<?php
/* -----------------------------------------------------------------------
   Shared admin-console modal engine.

   Extracted VERBATIM from partials/_kingdom_admin_modals.tpl so the Park
   admin console can drive the same modals without a second, drifting copy of
   ~330 lines of focus-stack / dirty-tracking / save-or-discard logic.

   This file is GENERIC: it knows nothing about kingdoms, parks or any
   particular modal. Consumers register their own modals through
   window.kaRegisterModal() (see the Public API block at the bottom).

   Include it ONCE per page, AFTER the .ka-overlay / .ka-confirm-overlay
   markup (the script wires dialog semantics and backdrop clicks at parse
   time) and BEFORE any script that calls kaOpenModal / kaConfirm / etc.
   The guard below makes a second include a no-op rather than a double bind.

   Markup this engine expects, all optional except where noted:
     .ka-overlay            a modal; .ka-open on it means "showing"
     .ka-modal-box          the focus-trapped box inside an overlay
     .ka-modal-title        titles the dialog for aria-labelledby
     .ka-feedback           inline status strip (aria-live)
     #ka-confirm-overlay    REQUIRED for kaConfirm(), with #ka-confirm-title,
                            #ka-confirm-message, #ka-confirm-ok,
                            #ka-confirm-alt, #ka-confirm-cancel,
                            #ka-confirm-close
   ----------------------------------------------------------------------- */
if (defined('KA_MODAL_CORE_RENDERED')) {
    return;
}
define('KA_MODAL_CORE_RENDERED', true);
?>
<script>
(function () {
	function gid(id) { return document.getElementById(id); }

	function kaEsc(s) { return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	/* ── Modal helpers ────────────────────────────── */

	/* Open overlays, bottom → top. Escape and the Tab trap act on the LAST entry only,
	   so dismissing a confirm no longer takes the modal that raised it with it. */
	var _kaStack  = [];
	var _kaOpener = {};   // overlay id → element that opened it, for focus restore
	var _kaClean  = {};   // overlay id → field snapshot taken when it opened

	/* Modals that must never reopen carrying an abandoned selection. Registered by
	   each console through kaRegisterModal(id, {resetOnOpen: true, ...}); the value is
	   the list of submit buttons to re-disable on open (they are enabled only once a
	   pick is made), which may legitimately be empty. */
	var KA_RESET_ON_OPEN = {};

	/* Per-overlay hooks run on open, after reset-to-defaults and before the clean
	   snapshot is taken, so anything a hook changes counts as "not dirty". Used by
	   Create Player to re-sync the password-mode disclosure, which a programmatic
	   reset cannot do on its own (assigning .checked fires no change event). */
	var _kaOnOpen = {};
	function kaOnOpen(id, fn) { (_kaOnOpen[id] = _kaOnOpen[id] || []).push(fn); }

	/* Modal id -> function(done) that saves that modal's pending edits and calls
	   done(ok) when finished. A modal with no entry here simply gets the
	   two-button discard prompt: offering "Save Changes" for a modal we cannot
	   actually save would be a lie. Populated through kaRegisterModal(), by each
	   modal's own IIFE, once it has built whatever it needs to find its own dirty state. */
	var KA_MODAL_SAVE = {};

	/* Transient UI filters -- typing in them is not "work", so they stay out of the
	   dirty check (a search box that clears itself on every open must never make a
	   modal look dirty). Registered by each console via kaIgnoreDirtyFields(). */
	var KA_NODIRTY_IDS = [];

	var KA_FOCUSABLE = 'a[href], button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

	function kaBoxOf(ov)   { return ov.querySelector('.ka-modal-box') || ov; }
	function kaPush(id)    { kaPop(id); _kaStack.push(id); }
	function kaPop(id)     { var i = _kaStack.indexOf(id); if (i >= 0) _kaStack.splice(i, 1); }
	function kaTop()       { return _kaStack.length ? gid(_kaStack[_kaStack.length - 1]) : null; }

	/* ── Dirty guard ──────────────────────────────── */
	function kaFields(ov) {
		return Array.prototype.slice.call(ov.querySelectorAll('input, select, textarea'))
			.filter(function(f) { return KA_NODIRTY_IDS.indexOf(f.id) === -1; });
	}
	function kaValueOf(f) {
		if (f.type === 'checkbox' || f.type === 'radio') return f.checked ? '1' : '0';
		return f.value == null ? '' : String(f.value);
	}
	function kaBaselineOf(f) {
		// Edit Details stamps data-original on its four fields and refreshes it after a
		// successful save — trust that over the live value when it is present.
		if (f.dataset && typeof f.dataset.original === 'string') return f.dataset.original;
		return kaValueOf(f);
	}
	function kaSnapshot(ov, useBaseline) {
		return kaFields(ov).map(useBaseline ? kaBaselineOf : kaValueOf).join('\u0001');
	}
	function kaIsDirty(ov) {
		return (ov.id in _kaClean) && kaSnapshot(ov, false) !== _kaClean[ov.id];
	}
	function kaMarkClean(ov) { if (ov && (ov.id in _kaClean)) _kaClean[ov.id] = kaSnapshot(ov, false); }

	/* ── Reset on open ────────────────────────────── */
	function kaResetFields(ov) {
		kaFields(ov).forEach(function(f) {
			if (f.type === 'checkbox' || f.type === 'radio') { f.checked = f.defaultChecked; return; }
			if (f.tagName === 'SELECT') {
				var idx = 0;
				for (var i = 0; i < f.options.length; i++) { if (f.options[i].defaultSelected) { idx = i; break; } }
				f.selectedIndex = idx;
				return;
			}
			if (f.type === 'file') { try { f.value = ''; } catch (e) {} return; }
			f.value = (typeof f.defaultValue === 'string') ? f.defaultValue : '';
		});
		// Autocomplete dropdowns keep their results — and their fixed position — between opens.
		ov.querySelectorAll('.kn-ac-results').forEach(function(r) {
			r.classList.remove('kn-ac-open');
			r.innerHTML = '';
			r.style.position = ''; r.style.top = ''; r.style.left = ''; r.style.width = ''; r.style.zIndex = '';
		});
		(KA_RESET_ON_OPEN[ov.id] || []).forEach(function(bid) { var b = gid(bid); if (b) b.disabled = true; });
		ov.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
	}

	/* ── Focus management ─────────────────────────── */
	function kaFocusables(ov) {
		return Array.prototype.slice.call(kaBoxOf(ov).querySelectorAll(KA_FOCUSABLE))
			.filter(function(f) { return f.type !== 'hidden' && f.offsetParent !== null; });
	}
	function kaFocusFirst(ov, preferId) {
		var preferred = preferId ? gid(preferId) : null;
		var items = kaFocusables(ov);
		var field = items.filter(function(f) { return f.tagName === 'INPUT' || f.tagName === 'SELECT' || f.tagName === 'TEXTAREA'; })[0];
		var target = preferred || field || items[0] || kaBoxOf(ov);
		if (target === kaBoxOf(ov)) target.setAttribute('tabindex', '-1');
		try { target.focus(); } catch (e) {}
	}
	function kaRestoreFocus(id) {
		var opener = _kaOpener[id];
		delete _kaOpener[id];
		if (!opener || opener === document.body || typeof opener.focus !== 'function') return;
		if (!document.contains(opener)) return;
		try { opener.focus(); } catch (e) {}
	}

	/* Safari does not focus a <button> on click, so document.activeElement is not a
	   reliable record of what opened a modal. Remember the last clicked control too. */
	var _kaLastClick = null;
	document.addEventListener('mousedown', function(e) {
		_kaLastClick = e.target && e.target.closest ? e.target.closest('button, a[href], [role="button"]') : null;
	}, true);
	function kaOpenerNow() {
		var a = document.activeElement;
		return (a && a !== document.body) ? a : _kaLastClick;
	}

	function kaOpenModal(id, opener) {
		var el = gid(id);
		if (!el) return;
		_kaOpener[id] = opener || kaOpenerNow();
		if (KA_RESET_ON_OPEN[id]) kaResetFields(el);
		(_kaOnOpen[id] || []).forEach(function(fn) { try { fn(el); } catch (e) {} });
		_kaClean[id] = kaSnapshot(el, true);
		el.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		kaPush(id);
		kaFocusFirst(el);
	}
	function kaCloseModal(id, force) {
		var el = gid(id);
		if (!el) return;
		if (!force && el.classList.contains('ka-open') && kaIsDirty(el)) {
			var saver = KA_MODAL_SAVE[id];
			if (saver) {
				// Three buttons. Discard Changes is the destructive middle action
				// (the alt slot, always red); Save Changes is the safe default on
				// OK (green) and reuses this modal's own save path -- if it fails,
				// the modal stays open (see the saver itself) so the officer never
				// sees "closed" when their edits did not actually land.
				kaConfirm('Discard your changes?', function() {
					saver(function(ok) { if (ok) kaCloseModal(id, true); });
				}, 'Unsaved Changes', {
					cancelLabel: 'Go Back',
					okLabel: 'Save Changes',
					altLabel: 'Discard Changes',
					onAlt: function() { kaCloseModal(id, true); }
				});
			} else {
				// No registered save action for this modal -- two buttons, same as
				// every other confirm in this file.
				kaConfirm('Discard your changes?', function() { kaCloseModal(id, true); }, 'Unsaved Changes', {
					cancelLabel: 'Go Back',
					okLabel: 'Discard Changes',
					danger: true
				});
			}
			return;
		}
		el.classList.remove('ka-open');
		kaPop(id);
		delete _kaClean[id];
		document.body.style.overflow = _kaStack.length ? 'hidden' : '';
		el.querySelectorAll('.ka-feedback').forEach(function(f) { f.style.display = 'none'; f.innerHTML = ''; });
		kaRestoreFocus(id);
	}
	window.kaOpenModal  = kaOpenModal;
	window.kaCloseModal = kaCloseModal;

	/* Dialog semantics, wired from script so the markup stays untouched. */
	document.querySelectorAll('.ka-overlay, .ka-confirm-overlay').forEach(function(ov) {
		ov.setAttribute('role', ov.classList.contains('ka-confirm-overlay') ? 'alertdialog' : 'dialog');
		ov.setAttribute('aria-modal', 'true');
		var title = ov.querySelector('.ka-modal-title');
		if (title) {
			if (!title.id) title.id = ov.id + '-title';
			ov.setAttribute('aria-labelledby', title.id);
		}
	});
	document.querySelectorAll('.ka-feedback').forEach(function(f) {
		f.setAttribute('aria-live', 'polite');
		f.setAttribute('aria-atomic', 'true');
	});

	// Close on backdrop click
	document.querySelectorAll('.ka-overlay').forEach(function(ov) {
		ov.addEventListener('click', function(e) { if (e.target === ov) kaCloseModal(ov.id); });
	});
	// Close on Escape — topmost overlay only
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Escape') return;
		var ov = kaTop();
		if (!ov) return;
		// An open autocomplete is "topmost" too — its own handler dismisses it.
		if (ov.querySelector('.kn-ac-results.kn-ac-open')) return;
		if (ov.id === 'ka-confirm-overlay') kaCloseConfirm();
		else kaCloseModal(ov.id);
	});
	// Keep Tab inside the topmost overlay
	document.addEventListener('keydown', function(e) {
		if (e.key !== 'Tab') return;
		var ov = kaTop();
		if (!ov) return;
		var items = kaFocusables(ov);
		if (!items.length) return;
		var first = items[0], last = items[items.length - 1];
		if (!kaBoxOf(ov).contains(document.activeElement)) {
			e.preventDefault();
			(e.shiftKey ? last : first).focus();
		} else if (e.shiftKey && document.activeElement === first) {
			e.preventDefault(); last.focus();
		} else if (!e.shiftKey && document.activeElement === last) {
			e.preventDefault(); first.focus();
		}
	});

	/* ── Feedback helper ──────────────────────────── */
	function kaFeedback(id, msg, ok) {
		var el = gid(id);
		if (!el) return;
		el.className = 'ka-feedback ' + (ok ? 'ka-feedback-ok' : 'ka-feedback-err');
		// Announce to screen readers: successes politely, errors as alerts.
		el.setAttribute('role', ok ? 'status' : 'alert');
		el.setAttribute('aria-live', ok ? 'polite' : 'assertive');
		el.setAttribute('aria-atomic', 'true');
		// Show first, then write — a live region that is display:none when its text
		// changes is not reliably announced.
		el.style.display = 'block';
		el.innerHTML = msg;
		if (ok) {
			clearTimeout(el._t);
			el._t = setTimeout(function() { el.style.display = 'none'; }, 5000);
			// A successful save is the new clean baseline. Deferred one tick because
			// several callers clear their fields immediately after this returns.
			var ov = el.closest('.ka-overlay');
			if (ov) setTimeout(function() { kaMarkClean(ov); }, 0);
		}
	}
	function kaClearFeedback(id) {
		var el = gid(id); if (el) { el.style.display = 'none'; el.innerHTML = ''; }
	}

	/* ── Confirm dialog ───────────────────────────── */
	var _kaConfirmCb    = null;
	var _kaConfirmAltCb = null;
	/* kaConfirm(message, onConfirm, title, opts)
	     opts.danger      — .ka-confirm-danger on the overlay: red rule + red OK button.
	                        The default OK is the same navy primary as "Save Details",
	                        which is the wrong signal in front of a permanent delete.
	     opts.html        — treat `message` as markup. Only ever pass strings BUILT IN
	                        THIS FILE; never a server or user string.
	     opts.okLabel     — replaces "Confirm" on the OK button (escaped).
	     opts.okIcon      — FontAwesome classes for an icon before that label.
	     opts.cancelLabel — replaces "Cancel" on the Cancel button (escaped).
	     opts.altLabel    — shows a THIRD button (#ka-confirm-alt) with this label
	                        (escaped), between Cancel and OK. Absent = two buttons,
	                        exactly today's behaviour.
	     opts.onAlt       — callback fired when the alt button is clicked, the same
	                        way onConfirm fires for OK.
	   Title, labels and danger/alt state are reset on EVERY call: they are shared
	   controls, and a previous caller's values used to survive into the next,
	   unrelated confirm. The alt button reset is the important one — kaConfirm
	   reuses this one overlay for every caller in this file, so a three-button
	   prompt (Save Changes) must never leak its extra button into the next
	   ordinary two-button confirm (e.g. Retire Award). */
	function kaConfirm(message, onConfirm, title, opts) {
		var overlay = gid('ka-confirm-overlay');
		if (!overlay) return;
		opts = opts || {};
		var body = gid('ka-confirm-message');
		if (opts.html) { body.innerHTML = message; } else { body.textContent = message; }
		gid('ka-confirm-title').childNodes[1].textContent = ' ' + (title || 'Confirm');
		var cancelBtn = gid('ka-confirm-cancel');
		if (cancelBtn) cancelBtn.textContent = opts.cancelLabel || 'Cancel';
		var okBtn = gid('ka-confirm-ok');
		if (okBtn) {
			okBtn.innerHTML = (opts.okIcon ? '<i class="' + kaEsc(opts.okIcon) + '" aria-hidden="true"></i> ' : '')
				+ kaEsc(opts.okLabel || 'Confirm');
		}
		// Reset the third button to hidden UNCONDITIONALLY, before deciding whether
		// this call wants it. That order is the whole fix for the leak above.
		var altBtn = gid('ka-confirm-alt');
		_kaConfirmAltCb = null;
		if (altBtn) {
			altBtn.innerHTML = '';
			altBtn.style.display = 'none';
			if (opts.altLabel) {
				altBtn.innerHTML = kaEsc(opts.altLabel);
				altBtn.style.display = '';
				_kaConfirmAltCb = opts.onAlt || null;
			}
		}
		overlay.classList.toggle('ka-confirm-danger', !!opts.danger);
		overlay.classList.toggle('ka-confirm-triple', !!opts.altLabel);
		_kaConfirmCb = onConfirm;
		_kaOpener['ka-confirm-overlay'] = kaOpenerNow();
		overlay.classList.add('ka-open');
		document.body.style.overflow = 'hidden';
		kaPush('ka-confirm-overlay');
		// Land on Cancel/Go Back: the confirm usually guards something destructive,
		// and when a third button is present the destructive action (Discard
		// Changes) sits in the MIDDLE of the row, not on OK -- a stray Enter must
		// still land on the safe choice.
		kaFocusFirst(overlay, 'ka-confirm-cancel');
	}
	function kaCloseConfirm() {
		var overlay = gid('ka-confirm-overlay');
		if (overlay) overlay.classList.remove('ka-open');
		_kaConfirmCb    = null;
		_kaConfirmAltCb = null;
		kaPop('ka-confirm-overlay');
		document.body.style.overflow = _kaStack.length ? 'hidden' : '';
		kaRestoreFocus('ka-confirm-overlay');
	}
	gid('ka-confirm-ok') && gid('ka-confirm-ok').addEventListener('click', function() { var cb = _kaConfirmCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-alt') && gid('ka-confirm-alt').addEventListener('click', function() { var cb = _kaConfirmAltCb; kaCloseConfirm(); if (cb) cb(); });
	gid('ka-confirm-cancel') && gid('ka-confirm-cancel').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-close') && gid('ka-confirm-close').addEventListener('click', kaCloseConfirm);
	gid('ka-confirm-overlay') && gid('ka-confirm-overlay').addEventListener('click', function(e) { if (e.target === this) kaCloseConfirm(); });

	/* ══════════════════════════════════════════════
	   PUBLIC API
	   ══════════════════════════════════════════════ */

	/* kaRegisterModal(id, opts) -- replaces the hardcoded registries this engine
	   used to carry.
	     opts.resetOnOpen  truthy = reset this modal's fields to their defaults on
	                       every open. Pass an array of button ids (or set
	                       opts.resetButtons) to also re-disable those buttons,
	                       which is how a submit button that is only enabled once a
	                       pick is made gets put back. An EMPTY list is meaningful:
	                       reset the fields, disable nothing.
	     opts.resetButtons button ids to re-disable on open, when resetOnOpen is
	                       simply `true`.
	     opts.save         function(done) that saves this modal's pending edits and
	                       calls done(ok). Its presence is what turns the
	                       unsaved-changes prompt from two buttons into three.
	     opts.onOpen       function(overlay) run on open, after reset-to-defaults
	                       and before the clean snapshot, so whatever it changes
	                       counts as "not dirty".
	   Calling it more than once for the same id is expected: each call only touches
	   the options it actually carries, and onOpen hooks accumulate in call order.
	   That is what lets a modal declare resetOnOpen up front and register its save
	   hook later, from inside its own IIFE. */
	function kaRegisterModal(id, opts) {
		opts = opts || {};
		if (opts.resetOnOpen) {
			KA_RESET_ON_OPEN[id] = Array.isArray(opts.resetOnOpen)
				? opts.resetOnOpen
				: (opts.resetButtons || []);
		}
		if (opts.save)   KA_MODAL_SAVE[id] = opts.save;
		if (opts.onOpen) kaOnOpen(id, opts.onOpen);
	}

	/* Field ids the dirty check must ignore -- transient UI filters, not work. */
	function kaIgnoreDirtyFields(ids) {
		(Array.isArray(ids) ? ids : [ids]).forEach(function (fid) {
			if (KA_NODIRTY_IDS.indexOf(fid) === -1) KA_NODIRTY_IDS.push(fid);
		});
	}

	window.kaRegisterModal      = kaRegisterModal;
	window.kaIgnoreDirtyFields  = kaIgnoreDirtyFields;
	window.kaConfirm            = kaConfirm;
	window.kaCloseConfirm       = kaCloseConfirm;
	window.kaFeedback           = kaFeedback;
	window.kaClearFeedback      = kaClearFeedback;
	window.kaMarkClean          = kaMarkClean;
	/* window.kaOpenModal / window.kaCloseModal are exported where they are defined,
	   above -- the console markup calls them straight from inline onclick. */
})();
</script>
