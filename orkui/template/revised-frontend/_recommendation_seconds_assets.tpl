<?php
// Recommendation Seconds — shared assets (CSS, modals, JS handlers).
// Included by: Playernew_index.tpl, Kingdomnew_index.tpl, Parknew_index.tpl.
//
// Contract: parent template must, BEFORE this include, set window.OrkRsCfg to
// { uir: <UIR>, userId: <session user id>, reload: function() { ... } }. The
// reload callback fires after a successful add/edit/withdraw/edit-reason so the
// host page refreshes its rec rendering. Default fallback is a full page reload.
//
// All elements use the `rs-` prefix to avoid collisions with host-page namespaces.
?>
<?php if (isset($this->__session->user_id)): ?>
<style>
/* ---- Recommendation Seconds: action buttons + lists (rs- prefix, host-agnostic) ---- */
.rs-action-btn,.rs-edit-reason-btn,.rs-second-edit,.rs-second-withdraw{position:relative;display:inline-flex;align-items:center;justify-content:center;height:24px;min-width:24px;padding:0 6px;border:1px solid #cbd5e0;border-radius:4px;background:#fff;color:#4a5568;font-size:11px;cursor:pointer;transition:all .12s;margin:0 2px;line-height:1}
.rs-action-btn:hover{border-color:#48bb78;color:#2f855a;background:#f0fff4}
.rs-edit-reason-btn:hover,.rs-second-edit:hover{border-color:#4299e1;color:#2c5282;background:#ebf8ff}
.rs-second-withdraw:hover{border-color:#fc8181;color:#c53030;background:#fff5f5}
.rs-seconds-badge{position:relative;display:inline-flex;align-items:center;gap:4px;padding:2px 8px;border-radius:10px;background:#c6f6d5;color:#22543d;font-size:11px;font-weight:700;margin-right:6px;cursor:default}
.rs-edit-reason-btn{height:20px;min-width:20px;padding:0 4px;font-size:10px;margin-left:6px;vertical-align:1px}
.rs-seconds{margin-top:8px;padding-top:8px;border-top:1px dashed #e2e8f0;display:flex;flex-direction:column;gap:4px}
.rs-second{font-size:12px;color:#4a5568;display:flex;align-items:center;gap:6px;flex-wrap:wrap;position:relative}
.rs-second .rs-supporter{font-weight:600;color:#2d3748}
.rs-second .rs-notes{color:#718096;font-style:italic}
.rs-second .rs-notes-empty{color:#a0aec0;font-style:italic;font-size:11px}

/* ---- Instant CSS-only tooltip (host-agnostic, edge-aware) ---- */
/* Default: center over element, growing upward. */
[data-rstip]:hover::after{
  content:attr(data-rstip);position:absolute;bottom:calc(100% + 6px);left:50%;transform:translateX(-50%);
  background:#1a202c;color:#fff;padding:5px 9px;border-radius:4px;font-size:11px;font-weight:500;
  z-index:10000;pointer-events:none;box-shadow:0 2px 6px rgba(0,0,0,.25);
  max-width:260px;width:max-content;white-space:normal;text-align:center;line-height:1.35;
}
[data-rstip]:hover::before{
  content:'';position:absolute;bottom:calc(100% + 1px);left:50%;transform:translateX(-50%);
  border:5px solid transparent;border-top-color:#1a202c;z-index:10000;pointer-events:none;
}
/* Edge-anchored variant (apply class on parent cell or button itself when at the right edge of the
   viewport, e.g. an action column on the right side of a table) — tooltip's RIGHT edge aligns to
   the button's RIGHT edge, so it grows leftward and never overflows. */
.rs-tip-right [data-rstip]:hover::after,
[data-rstip].rs-tip-right:hover::after{
  left:auto;right:0;transform:none;
}
.rs-tip-right [data-rstip]:hover::before,
[data-rstip].rs-tip-right:hover::before{
  left:auto;right:8px;transform:none;
}

/* ---- Modals (rs-overlay-* IDs) — leverage the host's .pn-overlay/.pn-modal-box styles ---- */
.rs-textarea{width:100%;resize:vertical;font-family:inherit;font-size:13px;padding:8px;border:1px solid #cbd5e0;border-radius:4px;box-sizing:border-box}
.rs-modal-context{margin:0 0 12px 0;color:#4a5568;font-size:13px}

/* ---- Dark mode ---- */
html[data-theme="dark"] .rs-action-btn,
html[data-theme="dark"] .rs-edit-reason-btn,
html[data-theme="dark"] .rs-second-edit,
html[data-theme="dark"] .rs-second-withdraw { background: var(--ork-card-bg); border-color: var(--ork-border); color: var(--ork-text-secondary); }
html[data-theme="dark"] .rs-action-btn:hover { background: rgba(72,187,120,.15); border-color: #48bb78; color: #9ae6b4; }
html[data-theme="dark"] .rs-edit-reason-btn:hover,
html[data-theme="dark"] .rs-second-edit:hover { background: rgba(66,153,225,.15); border-color: #4299e1; color: #90cdf4; }
html[data-theme="dark"] .rs-second-withdraw:hover { background: rgba(252,129,129,.15); border-color: #fc8181; color: #feb2b2; }
html[data-theme="dark"] .rs-seconds-badge { background: rgba(72,187,120,.2); color: #9ae6b4; }
html[data-theme="dark"] .rs-seconds { border-top-color: var(--ork-border); }
html[data-theme="dark"] .rs-second { color: var(--ork-text-secondary); }
html[data-theme="dark"] .rs-second .rs-supporter { color: var(--ork-text); }
html[data-theme="dark"] .rs-second .rs-notes { color: var(--ork-text-muted); }
html[data-theme="dark"] .rs-modal-context { color: var(--ork-text-secondary); }
html[data-theme="dark"] .rs-textarea { background: var(--ork-card-bg); color: var(--ork-text); border-color: var(--ork-border); }
</style>

<!-- ============================================
     Second Recommendation Modal (also Edit Notes)
     ============================================ -->
<div class="pn-overlay" id="rs-second-overlay">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-thumbs-up pn-modal-title-icon"></i><span id="rs-second-title">Second this Recommendation</span></h3>
			<button class="pn-modal-close-btn" id="rs-second-close-btn" type="button">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div class="pn-form-error" id="rs-second-error" style="display:none"></div>
			<p class="rs-modal-context" id="rs-second-context"></p>
			<div class="pn-rec-field">
				<label for="rs-second-notes">Supporting Notes <span style="color:#a0aec0;font-weight:400;font-size:11px">(optional)</span></label>
				<textarea id="rs-second-notes" maxlength="400" rows="4" placeholder="Add anything you'd like the awarding officer to know about why you support this recommendation." class="rs-textarea"></textarea>
				<span class="pn-char-count" id="rs-second-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="rs-second-cancel" type="button">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="rs-second-submit" type="button"><i class="fas fa-paper-plane"></i> <span id="rs-second-submit-label">Add Second</span></button>
		</div>
	</div>
</div>

<!-- ============================================
     Edit Recommendation Reason Modal (originator)
     ============================================ -->
<div class="pn-overlay" id="rs-edit-reason-overlay">
	<div class="pn-modal-box">
		<div class="pn-modal-header">
			<h3 class="pn-modal-title"><i class="fas fa-pen pn-modal-title-icon"></i>Edit Your Recommendation Reason</h3>
			<button class="pn-modal-close-btn" id="rs-edit-reason-close-btn" type="button">&times;</button>
		</div>
		<div class="pn-modal-body">
			<div class="pn-form-error" id="rs-edit-reason-error" style="display:none"></div>
			<p class="rs-modal-context" id="rs-edit-reason-context"></p>
			<div class="pn-rec-field">
				<label for="rs-edit-reason-text">Reason <span class="required-indicator">*</span></label>
				<textarea id="rs-edit-reason-text" maxlength="400" rows="4" placeholder="Why should this player receive this award?" class="rs-textarea"></textarea>
				<span class="pn-char-count" id="rs-edit-reason-char-count">400 characters remaining</span>
			</div>
		</div>
		<div class="pn-modal-footer">
			<button class="pn-btn pn-btn-secondary" id="rs-edit-reason-cancel" type="button">Cancel</button>
			<button class="pn-btn pn-btn-primary" id="rs-edit-reason-submit" type="button"><i class="fas fa-save"></i> Save Changes</button>
		</div>
	</div>
</div>

<script>
/* Recommendation Seconds — shared handlers. Idempotent: safe if included twice. */
(function() {
	if (window.__rsSecondsBound) return;
	window.__rsSecondsBound = true;

	var Cfg = window.OrkRsCfg || { uir: '/orkui/', userId: 0, reload: function() { location.reload(); } };
	if (!Cfg.userId) return;  // anonymous viewers don't get the modals; UI is hidden anyway

	function gid(id) { return document.getElementById(id); }
	function reload() { try { (Cfg.reload || function() { location.reload(); })(); } catch (e) { location.reload(); } }

	// ---- Second / Edit Notes modal ----
	var secOverlay = gid('rs-second-overlay');
	var secNotes   = gid('rs-second-notes');
	var secCount   = gid('rs-second-char-count');
	var secErr     = gid('rs-second-error');
	var secCtx     = gid('rs-second-context');
	var secTitle   = gid('rs-second-title');
	var secSubLbl  = gid('rs-second-submit-label');
	var secMode    = { kind: 'add', recId: 0, secondId: 0 };

	function openSecondModal(opts) {
		if (!secOverlay) return;
		secMode = opts;
		secErr.style.display = 'none';
		secNotes.value = opts.notes || '';
		secCount.textContent = Math.max(0, 400 - secNotes.value.length) + ' characters remaining';
		if (opts.kind === 'add') {
			secTitle.textContent = 'Second this Recommendation';
			secSubLbl.textContent = 'Add Second';
			secCtx.textContent = "You're seconding the recommendation of " + (opts.award || 'this award') + (opts.recipient ? ' for ' + opts.recipient : '') + '. Notes are optional but help the awarding officer.';
		} else {
			secTitle.textContent = 'Edit Your Notes';
			secSubLbl.textContent = 'Save Changes';
			secCtx.textContent = 'Update the notes attached to your second.';
		}
		secOverlay.classList.add('pn-open');
		setTimeout(function() { secNotes.focus(); }, 50);
	}
	function closeSecondModal() { if (secOverlay) secOverlay.classList.remove('pn-open'); }

	if (secNotes) {
		secNotes.addEventListener('input', function() {
			secCount.textContent = Math.max(0, 400 - secNotes.value.length) + ' characters remaining';
		});
	}
	var secCloseBtn = gid('rs-second-close-btn'); if (secCloseBtn) secCloseBtn.addEventListener('click', closeSecondModal);
	var secCancel   = gid('rs-second-cancel');    if (secCancel)   secCancel.addEventListener('click', closeSecondModal);
	if (secOverlay) secOverlay.addEventListener('click', function(e) { if (e.target === secOverlay) closeSecondModal(); });

	var secSubmit = gid('rs-second-submit');
	if (secSubmit) secSubmit.addEventListener('click', function() {
		var notes = secNotes.value || '';
		var url, payload = { notes: notes };
		if (secMode.kind === 'add') url = Cfg.uir + 'PlayerAjax/add_second/' + parseInt(secMode.recId);
		else                        url = Cfg.uir + 'PlayerAjax/edit_second_notes/' + parseInt(secMode.secondId);
		secSubmit.disabled = true;
		jQuery.post(url, payload, function(r) {
			secSubmit.disabled = false;
			if (r.status === 0) { closeSecondModal(); reload(); }
			else { secErr.textContent = (r.error || 'Error') + (r.detail ? ': ' + r.detail : ''); secErr.style.display = 'block'; }
		}, 'json').fail(function() {
			secSubmit.disabled = false;
			secErr.textContent = 'Network error — please try again.';
			secErr.style.display = 'block';
		});
	});

	// ---- Edit Reason modal ----
	var erOverlay  = gid('rs-edit-reason-overlay');
	var erText     = gid('rs-edit-reason-text');
	var erCount    = gid('rs-edit-reason-char-count');
	var erErr      = gid('rs-edit-reason-error');
	var erCtx      = gid('rs-edit-reason-context');
	var erRecId    = 0;

	function openEditReason(recId, currentReason, awardName) {
		erRecId = recId;
		erErr.style.display = 'none';
		erText.value = currentReason || '';
		erCount.textContent = Math.max(0, 400 - erText.value.length) + ' characters remaining';
		erCtx.textContent = 'Editing your reason for ' + (awardName || 'this recommendation') + '.';
		erOverlay.classList.add('pn-open');
		setTimeout(function() { erText.focus(); }, 50);
	}
	function closeEditReason() { if (erOverlay) erOverlay.classList.remove('pn-open'); }

	if (erText) {
		erText.addEventListener('input', function() {
			erCount.textContent = Math.max(0, 400 - erText.value.length) + ' characters remaining';
		});
	}
	var erCloseBtn = gid('rs-edit-reason-close-btn'); if (erCloseBtn) erCloseBtn.addEventListener('click', closeEditReason);
	var erCancel   = gid('rs-edit-reason-cancel');    if (erCancel)   erCancel.addEventListener('click', closeEditReason);
	if (erOverlay) erOverlay.addEventListener('click', function(e) { if (e.target === erOverlay) closeEditReason(); });

	var erSubmit = gid('rs-edit-reason-submit');
	if (erSubmit) erSubmit.addEventListener('click', function() {
		var reason = (erText.value || '').trim();
		if (!reason.length) { erErr.textContent = 'Reason cannot be empty.'; erErr.style.display = 'block'; return; }
		erSubmit.disabled = true;
		jQuery.post(Cfg.uir + 'PlayerAjax/edit_recommendation_reason/' + parseInt(erRecId), { reason: reason }, function(r) {
			erSubmit.disabled = false;
			if (r.status === 0) { closeEditReason(); reload(); }
			else { erErr.textContent = (r.error || 'Error') + (r.detail ? ': ' + r.detail : ''); erErr.style.display = 'block'; }
		}, 'json').fail(function() {
			erSubmit.disabled = false;
			erErr.textContent = 'Network error — please try again.';
			erErr.style.display = 'block';
		});
	});

	// ---- Delegated click handlers (work for any host page using rs- classes) ----
	jQuery(document).on('click', '.rs-action-btn', function(e) {
		e.preventDefault();
		var btn = e.currentTarget;
		openSecondModal({
			kind: 'add',
			recId:     parseInt(btn.getAttribute('data-rec'))       || 0,
			award:     btn.getAttribute('data-award')                || '',
			recipient: btn.getAttribute('data-recipient')            || '',
			notes:     ''
		});
	});

	jQuery(document).on('click', '.rs-second-edit', function(e) {
		e.preventDefault();
		var btn = e.currentTarget;
		openSecondModal({
			kind: 'edit',
			secondId: parseInt(btn.getAttribute('data-sid')) || 0,
			notes:    btn.getAttribute('data-notes') || ''
		});
	});

	jQuery(document).on('click', '.rs-second-withdraw', function(e) {
		e.preventDefault();
		var btn = e.currentTarget;
		var sid = parseInt(btn.getAttribute('data-sid')) || 0;
		if (!sid) return;
		if (!confirm('Withdraw your second?')) return;
		btn.disabled = true;
		jQuery.post(Cfg.uir + 'PlayerAjax/withdraw_second/' + sid, {}, function(r) {
			btn.disabled = false;
			if (r.status === 0) { reload(); }
			else { alert((r.error || 'Error') + (r.detail ? ': ' + r.detail : '')); }
		}, 'json').fail(function() {
			btn.disabled = false;
			alert('Network error — please try again.');
		});
	});

	jQuery(document).on('click', '.rs-edit-reason-btn', function(e) {
		e.preventDefault();
		var btn = e.currentTarget;
		openEditReason(
			parseInt(btn.getAttribute('data-rec')) || 0,
			btn.getAttribute('data-reason') || '',
			btn.getAttribute('data-award')  || ''
		);
	});

	// Expose for hosts that want to refresh manually
	window.OrkRs = { openSecondModal: openSecondModal, openEditReason: openEditReason };
})();
</script>
<?php endif; ?>
