<?php
/* -----------------------------------------------------------------------
   Create-event modal -- shared by the park PROFILE, the Park ADMIN CONSOLE and
   the KINGDOM ADMIN CONSOLE.

   The park profile has had the modern create-event workflow for a while:
   Amtgard Event vs Calendar Item, copy-from-a-past-event, date prefill, and
   two endpoints that already exist (EventAjax/create, CalendarItemAjax/*).
   The admin console's "Schedule an Event" tile was still a plain link to the
   legacy full-page Admin/createevent form. This partial is what closes that
   gap, on the same precedent as partials/_ka_modal_core.tpl: extract the
   thing both pages need, and let each page include it.

   WHY A NEUTRAL CONFIG. The script below used to live in revised.js behind
   `if (typeof PkConfig === 'undefined') return;`. Fourteen OTHER blocks in
   revised.js guard on that same name, across ~100 references, so simply
   defining PkConfig on the admin console to wake this one would also wake the
   officers panel, the awards panel, the recommendations panel, the attendance
   grid and the rest -- against park-PROFILE DOM that does not exist there.
   So the script is keyed off EvCreateConfig instead, which nothing but this
   partial ever sets. It activates exactly where it was deliberately included.

   INPUTS (set them before the include):
     $evParkId     int    park the modal creates for, 0 for kingdom scope
     $evKingdomId  int    the kingdom the event belongs to      (required)
     $evParkName   string unescaped park name                   (park scope)
     $evOrgName    string unescaped kingdom/principality name   (kingdom scope)
     $evCanCreate  bool   emit the MARKUP + flatpickr assets?   (default false)

   PARK SCOPE vs KINGDOM SCOPE. EventAjax::create takes either a KingdomId or a
   ParkId, and Event::CreateEvent authorizes `park.event.create` against whichever
   it was handed -- so $evParkId = 0 produces a legitimate kingdom-level event, the
   same thing the kingdom PROFILE's own kn-emod-* modal makes. Two things in the
   markup below are park-only and are gated on $_evParkId accordingly:

     * "Copy from past event" -- its script block already guards on
       EvCreateConfig.parkId (the source list is fetched park-scoped). Left
       ungated, the markup would render an expander whose onclick handler was
       never defined, i.e. a ReferenceError on the first click.
     * the "will be assigned to X" hint -- reads $_evScopeName, which is the park
       name at park scope and the kingdom/principality name at kingdom scope.

   WHY $evCanCreate GATES THE MARKUP ONLY, NEVER THE SCRIPT. The same script
   also owns the calendar-item view overlay, the events-list rows and the
   calendar-grid sync -- surfaces a NON-admin uses to read an item. On the park
   profile it ran for every viewer (PkConfig is emitted unconditionally there),
   so gating the whole partial on "can create" would silently take the
   calendar-item overlay away from everybody else. Markup gated, script always.

   The modal's CSS is the pk-emod-* / pk-cfe-* / ci-swatch block that already
   ships in revised.css -- which BOTH host pages load. Nothing new was added.
   ----------------------------------------------------------------------- */
$_evParkId    = (int)($evParkId    ?? 0);
$_evKingdomId = (int)($evKingdomId ?? 0);
$_evParkName  = (string)($evParkName ?? '');
$_evCanCreate = !empty($evCanCreate);
/* Who the new event gets assigned to, in the words the officer will read. */
$_evScopeName = $_evParkId > 0 ? $_evParkName : (string)($evOrgName ?? '');
?>
<script>
/* Read by the two blocks at the bottom of this partial and by nothing else. */
window.EvCreateConfig = {
	uir:       '<?= UIR ?>',
	parkId:    <?= $_evParkId ?>,
	kingdomId: <?= $_evKingdomId ?>,
	parkName:  <?= json_encode($_evParkName) ?>
};
</script>

<?php if ($_evCanCreate) : ?>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="pk-emod-overlay" id="pk-event-modal">
	<div class="pk-emod-box">
		<div class="pk-emod-header">
			<h3 id="pk-emod-title" class="kn-bare-heading"><i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event</h3>
			<button class="pk-emod-close" onclick="pkCloseEventModal()">&times;</button>
		</div>
		<div class="pk-emod-body">

			<div class="pk-emod-typesel">
				<label class="pk-emod-typeopt">
					<input type="radio" name="pk-emod-type" value="event" checked>
					<span><i class="fas fa-flag"></i> Amtgard Event</span>
				</label>
				<label class="pk-emod-typeopt">
					<input type="radio" name="pk-emod-type" value="calendar-item">
					<span><i class="fas fa-calendar-day"></i> Calendar Item</span>
				</label>
			</div>

			<div class="pk-emod-field">
				<label class="pk-emod-label">Name <span style="color:#e53e3e">*</span></label>
				<input type="text" class="pk-emod-input" id="pk-event-name" autocomplete="off" placeholder="e.g. Summer Dragonmaster">
			</div>
			<div id="pk-emod-date-row" style="display:none;font-size:12px;color:var(--ork-alert-info-text,#2b6cb0);margin-top:8px;padding:5px 8px;background:var(--ork-alert-info-bg,#ebf8ff);border-radius:5px;border-left:3px solid var(--ork-alert-info-border,#90cdf4)">
				<i class="fas fa-calendar-alt" style="margin-right:5px"></i><span id="pk-emod-date-text"></span>
			</div>
			<!-- Copy from past event (collapsible, event-mode only).
			     PARK SCOPE ONLY, and gated on exactly what its script block guards on
			     (EvCreateConfig.parkId): the source list is fetched park-scoped, so at
			     kingdom scope none of pkCfe* is ever defined and this markup would be an
			     expander whose onclick throws ReferenceError. -->
<?php if ($_evParkId > 0) : ?>
			<div class="pk-cfe-wrap pk-emod-event-only" id="pk-cfe-wrap" style="margin-top:14px">
				<button type="button" class="pk-cfe-toggle" id="pk-cfe-toggle" onclick="pkCfeToggleExpander()" aria-expanded="false">
					<i class="fas fa-clone" style="margin-right:6px;color:#2b6cb0"></i>
					Copy from past event <span style="color:#a0aec0;font-weight:400">(optional)</span>
					<i class="fas fa-chevron-down pk-cfe-chev" id="pk-cfe-chev" style="margin-left:auto"></i>
				</button>
				<div class="pk-cfe-body" id="pk-cfe-body" style="display:none">
					<div class="pk-cfe-field" id="pk-cfe-picker-wrap">
						<label class="pk-emod-label">Source event <span style="color:#a0aec0;font-weight:400;text-transform:none;letter-spacing:0">(park-level)</span></label>
						<div class="kn-ac-wrap">
							<input type="text" class="pk-emod-input" id="pk-cfe-search" autocomplete="off" placeholder="Search past events…">
							<div class="kn-ac-results" id="pk-cfe-results"></div>
						</div>
						<input type="hidden" id="pk-cfe-source-id" value="">
						<input type="hidden" id="pk-cfe-source-start" value="">
						<input type="hidden" id="pk-cfe-source-end" value="">
					</div>
					<div class="pk-cfe-chip" id="pk-cfe-chip" style="display:none">
						<i class="fas fa-bookmark" style="margin-right:6px;color:#2b6cb0"></i>
						<span id="pk-cfe-chip-label"></span>
						<button type="button" class="pk-cfe-chip-clear" onclick="pkCfeClear()" aria-label="Clear source">&times;</button>
					</div>
					<div class="pk-cfe-detail" id="pk-cfe-detail" style="display:none">
						<div class="pk-emod-row" style="display:flex;gap:10px;margin-top:12px">
							<div class="pk-emod-field" style="flex:1">
								<label class="pk-emod-label">Start <span style="color:#e53e3e">*</span></label>
								<input type="text" class="pk-emod-input" id="pk-cfe-start" autocomplete="off" placeholder="Select start…">
							</div>
							<div class="pk-emod-field" style="flex:1">
								<label class="pk-emod-label">End <span style="color:#e53e3e">*</span></label>
								<input type="text" class="pk-emod-input" id="pk-cfe-end" autocomplete="off" placeholder="Select end…">
							</div>
						</div>
						<div class="pk-cfe-modules" style="margin-top:12px">
							<div class="pk-cfe-mod-title">What to copy</div>
							<label class="pk-cfe-mod-row pk-cfe-mod-all">
								<input type="checkbox" id="pk-cfe-mod-all" checked onchange="pkCfeToggleAll(this)">
								<span><strong>Select all</strong></span>
							</label>
							<label class="pk-cfe-mod-row">
								<input type="checkbox" class="pk-cfe-mod" id="pk-cfe-mod-details" checked onchange="pkCfeSyncAll()">
								<span>Event Details <span class="pk-cfe-mod-hint">description, address, fees, links</span></span>
							</label>
							<label class="pk-cfe-mod-row">
								<input type="checkbox" class="pk-cfe-mod" id="pk-cfe-mod-schedule" checked onchange="pkCfeSyncAll()">
								<span>Schedule</span>
							</label>
							<label class="pk-cfe-mod-row">
								<input type="checkbox" class="pk-cfe-mod" id="pk-cfe-mod-staff" checked onchange="pkCfeSyncAll()">
								<span>Staff <span class="pk-cfe-mod-hint">banned/deactivated people are skipped</span></span>
							</label>
							<label class="pk-cfe-mod-row">
								<input type="checkbox" class="pk-cfe-mod" id="pk-cfe-mod-feast" checked onchange="pkCfeSyncAll()">
								<span>Feast</span>
							</label>
							<label class="pk-cfe-mod-row">
								<input type="checkbox" class="pk-cfe-mod" id="pk-cfe-mod-banner" onchange="pkCfeSyncAll()">
								<span>Banner <span class="pk-cfe-mod-hint">image + framing config</span></span>
							</label>
						</div>
					</div>
				</div>
			</div>
<?php endif; ?>

						<p class="pk-emod-hint pk-emod-event-only" style="margin-top:8px">This event will be assigned to <strong><?= htmlspecialchars($_evScopeName) ?></strong>. You'll set dates and details on the next page.</p>

			<!-- Calendar-item-only fields -->
			<div class="pk-emod-ci-only" style="display:none">
				<div class="pk-emod-field" style="margin-top:12px">
					<label class="pk-emod-check-label">
						<input type="checkbox" id="pk-ci-allday"> All day
					</label>
				</div>
				<div class="pk-emod-field" style="margin-top:6px">
					<label class="pk-emod-check-label" data-tip="Officer-only items are visible only to ORK admins and people serving as Monarch / Regent / PM / Champion of this kingdom or park.">
						<input type="checkbox" id="pk-ci-officer-only"> <i class="fas fa-shield-alt" style="margin:0 4px 0 2px;color:#805ad5"></i>Only Display to Officers
					</label>
				</div>
				<div class="pk-emod-field" style="margin-top:6px">
					<label class="pk-emod-check-label" data-tip="Locals-only items are visible only to ORK admins and to logged-in players whose home park (or kingdom, for kingdom-level items) matches.">
						<input type="checkbox" id="pk-ci-locals-only"> <i class="fas fa-map-marker-alt" style="margin:0 4px 0 2px;color:#0d9488"></i>Only Display to Local Park/Kingdom Players
					</label>
				</div>
				<div style="display:flex;gap:10px;margin-top:8px">
					<div class="pk-emod-field" style="flex:1">
						<label class="pk-emod-label">Start <span style="color:#e53e3e">*</span></label>
						<input type="text" class="pk-emod-input" id="pk-ci-start" autocomplete="off" placeholder="Select start…">
					</div>
					<div class="pk-emod-field" style="flex:1">
						<label class="pk-emod-label">End <span style="color:#e53e3e">*</span></label>
						<input type="text" class="pk-emod-input" id="pk-ci-end" autocomplete="off" placeholder="Select end…">
					</div>
				</div>
				<div class="pk-emod-field" style="margin-top:10px">
					<label class="pk-emod-label">Color</label>
					<div class="ci-swatches" id="pk-ci-swatches">
						<button type="button" class="ci-swatch" data-color="#64748b" style="background:#64748b" data-tip="Slate"></button>
						<button type="button" class="ci-swatch" data-color="#3b82f6" style="background:#3b82f6" data-tip="Blue"></button>
						<button type="button" class="ci-swatch" data-color="#8b5cf6" style="background:#8b5cf6" data-tip="Purple"></button>
						<button type="button" class="ci-swatch" data-color="#06b6d4" style="background:#06b6d4" data-tip="Cyan"></button>
						<button type="button" class="ci-swatch" data-color="#22a06b" style="background:#22a06b" data-tip="Green"></button>
						<button type="button" class="ci-swatch" data-color="#eab308" style="background:#eab308" data-tip="Amber"></button>
						<button type="button" class="ci-swatch" data-color="#f97316" style="background:#f97316" data-tip="Orange"></button>
						<button type="button" class="ci-swatch" data-color="#e11d48" style="background:#e11d48" data-tip="Rose"></button>
					</div>
					<input type="hidden" id="pk-ci-color" value="#64748b">
				</div>
				<div class="pk-emod-field" style="margin-top:10px">
					<label class="pk-emod-label">Description</label>
					<textarea class="pk-emod-input" id="pk-ci-description" rows="3" placeholder="Optional details…"></textarea>
				</div>
				<div class="pk-emod-ci-note">
					<i class="fas fa-info-circle" style="margin-right:6px"></i>
					Calendar Items are lightweight. They do <strong>not</strong> support RSVPs, sign-ins, schedules, attendance, heraldry, pricing, or event authorization lists. Use an Amtgard Event for those.
				</div>
			</div>

			<div class="pk-emod-feedback" id="pk-emod-feedback" style="display:none"></div>
		</div>
		<div class="pk-emod-footer">
			<button class="pk-emod-btn-cancel" onclick="pkCloseEventModal()">Cancel</button>
			<button class="pk-emod-btn-cancel pk-emod-draft-btn" id="pk-emod-draft-btn" onclick="pkCreateEvent('draft')" disabled style="display:none;font-size:12px;">
				<i class="fas fa-eye-slash"></i> Save as Draft
			</button>
			<button class="pk-emod-btn-go" id="pk-emod-go-btn" onclick="pkCreateEvent()" disabled>
				<span id="pk-emod-go-label">Create Event</span> <i class="fas fa-arrow-right"></i>
			</button>
		</div>
	</div>
</div>

<?php endif; ?>

<script>
(function() {
    /* Keyed off EvCreateConfig, NOT PkConfig: this block is shared with the Park
       admin console, and defining PkConfig there would wake fourteen OTHER
       PkConfig-guarded blocks in revised.js against park-PROFILE DOM that does
       not exist on that page. partials/_event_create_modal.tpl is the only
       thing that sets EvCreateConfig, so this activates exactly where it was
       deliberately included. */
    if (typeof EvCreateConfig === 'undefined' || !EvCreateConfig) return;

    var CREATE_URL    = EvCreateConfig.uir + 'EventAjax/create';
    var CI_CREATE_URL = EvCreateConfig.uir + 'CalendarItemAjax/create';
    var CI_UPDATE_URL = EvCreateConfig.uir + 'CalendarItemAjax/update';
    var CI_DELETE_URL = EvCreateConfig.uir + 'CalendarItemAjax/delete';
    var CI_GET_URL    = EvCreateConfig.uir + 'CalendarItemAjax/get/';

    var pkCiEditingId = 0;
    var pkCiFlatStart = null, pkCiFlatEnd = null;
    var pkCiCurrent   = null;

    /* The five call sites below used to raise a native dialog. That is banned
       house-wide -- it freezes the automation harness. The park profile ships
       window.orkAlert (revised.js); the Park admin console does not load that
       file, and every one of those call sites sits behind the calendar-item
       overlay, which the console has no markup for -- so the console branch is
       unreachable and logs rather than blocking. */
    function pkEvAlert(msg) {
        if (window.orkAlert) { window.orkAlert(msg); return; }
        try { console.error('[pkEvent]', msg); } catch (e) {}
    }

    function pkEvFeedback(msg) {
        var el = document.getElementById('pk-emod-feedback');
        el.textContent = msg; el.style.display = '';
    }

    function pkCiRebuildPickers(startVal, endVal, allDay) {
        if (pkCiFlatStart) { pkCiFlatStart.destroy(); pkCiFlatStart = null; }
        if (pkCiFlatEnd)   { pkCiFlatEnd.destroy();   pkCiFlatEnd   = null; }
        var fmt  = allDay ? 'Y-m-d' : 'Y-m-d H:i';
        var opts = { enableTime: !allDay, dateFormat: fmt, altInput: true, altFormat: allDay ? 'F j, Y' : 'F j, Y h:i K', minuteIncrement: 15, time_24hr: false };
        var _prevStart = null;
        pkCiFlatStart = flatpickr('#pk-ci-start', Object.assign({}, opts, {
            onReady: function(sel) { _prevStart = sel[0] || null; },
            onChange: function(sel) {
                if (!sel[0] || !pkCiFlatEnd) return;
                var endDate = pkCiFlatEnd.selectedDates[0];
                if (endDate && _prevStart) {
                    var offset = endDate.getTime() - _prevStart.getTime();
                    pkCiFlatEnd.setDate(new Date(sel[0].getTime() + offset), true);
                } else if (!endDate) {
                    pkCiFlatEnd.setDate(new Date(sel[0].getTime() + (allDay ? 0 : 60 * 60 * 1000)), true);
                }
                _prevStart = sel[0];
            }
        }));
        pkCiFlatEnd = flatpickr('#pk-ci-end', opts);
        if (startVal) pkCiFlatStart.setDate(startVal, true);
        if (endVal)   pkCiFlatEnd.setDate(endVal, true);
        _prevStart = pkCiFlatStart.selectedDates[0] || null;
    }

    function pkCiResetForm(presetDate) {
        document.getElementById('pk-ci-description').value = '';
        document.getElementById('pk-ci-allday').checked = false;
        var off = document.getElementById('pk-ci-officer-only'); if (off) off.checked = false;
        var loc = document.getElementById('pk-ci-locals-only');  if (loc) loc.checked = false;
        pkCiSetColor('#64748b');
        pkCiRebuildPickers(presetDate || '', '', false);
    }

    // Select a palette swatch and stash the chosen hex in the hidden input.
    function pkCiSetColor(color) {
        var input = document.getElementById('pk-ci-color');
        if (input) input.value = color || '#64748b';
        var box = document.getElementById('pk-ci-swatches');
        if (!box) return;
        box.querySelectorAll('.ci-swatch').forEach(function(b) {
            b.classList.toggle('selected', b.getAttribute('data-color') === (color || '#64748b'));
        });
    }

    function pkGetModalType() {
        var r = document.querySelector('input[name="pk-emod-type"]:checked');
        return r ? r.value : 'event';
    }

    function pkApplyModalType() {
        var t = pkGetModalType();
        var isCi = (t === 'calendar-item');
        document.querySelectorAll('.pk-emod-event-only').forEach(function(el) { el.style.display = isCi ? 'none' : ''; });
        document.querySelectorAll('.pk-emod-ci-only').forEach(function(el) { el.style.display = isCi ? '' : 'none'; });
        var dbtn = document.getElementById('pk-emod-draft-btn');
        if (dbtn) dbtn.style.display = isCi ? 'none' : '';
        document.getElementById('pk-emod-go-label').textContent = isCi ? (pkCiEditingId > 0 ? 'Save Calendar Item' : 'Create Calendar Item') : 'Create Event';
        var title = document.getElementById('pk-emod-title');
        title.innerHTML = isCi
            ? '<i class="fas fa-calendar-day" style="margin-right:8px;color:#64748b"></i>' + (pkCiEditingId > 0 ? 'Edit Calendar Item' : 'New Calendar Item')
            : '<i class="fas fa-calendar-plus" style="margin-right:8px;color:#276749"></i>Create New Event';
        pkUpdateGoBtn();
    }

    function pkUpdateGoBtn() {
        var t = pkGetModalType();
        var ok;
        if (t === 'calendar-item') {
            ok = !!document.getElementById('pk-event-name').value.trim()
              && !!document.getElementById('pk-ci-start').value
              && !!document.getElementById('pk-ci-end').value;
        } else {
            ok = !!document.getElementById('pk-event-name').value.trim();
        }
        document.getElementById('pk-emod-go-btn').disabled = !ok;
        var dbtn = document.getElementById('pk-emod-draft-btn');
        if (dbtn) dbtn.disabled = !ok;
    }

    window.pkOpenEventModal = function(dateStr) {
        var modal = document.getElementById('pk-event-modal');
        modal.dataset.presetDate = dateStr || '';
        pkCiEditingId = 0;
        document.querySelectorAll('input[name="pk-emod-type"]').forEach(function(r) { r.disabled = false; r.checked = (r.value === 'event'); });
        document.getElementById('pk-event-name').value = '';
        document.getElementById('pk-emod-feedback').style.display = 'none';
        var dateRow  = document.getElementById('pk-emod-date-row');
        var dateText = document.getElementById('pk-emod-date-text');
        if (dateRow && dateText) {
            if (dateStr) {
                var d = new Date(dateStr + 'T00:00:00');
                dateText.textContent = d.toLocaleDateString(undefined, { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' });
                dateRow.style.display = '';
            } else {
                dateRow.style.display = 'none';
            }
        }
        pkCiResetForm(dateStr || '');
        pkApplyModalType();
        modal.classList.add('pk-emod-open');
        document.body.style.overflow = 'hidden';
        setTimeout(function() { document.getElementById('pk-event-name').focus(); }, 50);
    };

    window.pkCloseEventModal = function() {
        document.getElementById('pk-event-modal').classList.remove('pk-emod-open');
        document.body.style.overflow = '';
    };

    window.pkCreateEvent = function(statusOverride) {
        if (pkGetModalType() === 'calendar-item') return pkSubmitCalendarItem();
        var name = document.getElementById('pk-event-name').value.trim();
        if (!name) return;
        var btn = document.getElementById('pk-emod-go-btn');
        var dbtn = document.getElementById('pk-emod-draft-btn');
        btn.disabled = true; if (dbtn) dbtn.disabled = true;
        var status = (statusOverride === 'draft') ? 'draft' : 'published';
        $.post(CREATE_URL, { Name: name, KingdomId: EvCreateConfig.kingdomId, ParkId: EvCreateConfig.parkId, Status: status },
            function(r) {
                if (r && r.status === 0) {
                    var presetDate = document.getElementById('pk-event-modal').dataset.presetDate || '';
                    var url = EvCreateConfig.uir + 'Event/create/' + r.eventId + '/' + EvCreateConfig.parkId;
                    if (presetDate) url += '&date=' + encodeURIComponent(presetDate);
                    window.location.href = url;
                } else {
                    pkEvFeedback((r && r.error) ? r.error : 'Failed to create event.');
                    btn.disabled = false; if (dbtn) dbtn.disabled = false;
                }
            }, 'json'
        ).fail(function() { pkEvFeedback('Request failed. Please try again.'); btn.disabled = false; if (dbtn) dbtn.disabled = false; });
    };

    // Reload while preserving the Events tab (reuses the ?tab= auto-activation on load).
    function pkReloadToEventsTab() {
        // A host page with no park-profile tab strip -- the Park admin console --
        // has no events list or calendar grid to re-render, and ?tab=events means
        // nothing on its route. Reload it plainly. Unreachable on the profile,
        // whose .pk-tab-nav is unconditional markup.
        if (!document.querySelector('.pk-tab-nav')) { window.location.reload(); return; }
        try {
            var u = new URL(window.location.href);
            u.searchParams.set('tab', 'events');
            window.location.href = u.toString();
        } catch (e) {
            window.location.reload();
        }
    }

    function pkCiEsc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    function pkBumpEventsTabCount(delta) {
        var cnt = document.querySelector('.pk-tab-nav li[data-pktab="events"] .pk-tab-count');
        if (!cnt) return;
        var m = cnt.textContent.match(/\d+/);
        var n = (m ? parseInt(m[0], 10) : 0) + delta;
        cnt.textContent = '(' + (n < 0 ? 0 : n) + ')';
    }

    function pkPad2(n) { return String(n).length < 2 ? '0' + n : String(n); }

    // Keep the static calendar-grid array in sync (the Park grid reads pkCalEvents directly,
    // so unlike the Kingdom page there is no server re-fetch to lean on).
    function pkGridAddCalendarItem(id, name, startVal, endVal, allDay, color) {
        if (typeof pkCalEvents === 'undefined' || !pkCalEvents) return;
        color = color || '#64748b';
        var sIso = allDay ? String(startVal).substring(0, 10) : String(startVal).replace(' ', 'T');
        var eIso;
        if (allDay) {
            var ed = new Date(String(endVal).substring(0, 10) + 'T00:00:00');
            if (!isNaN(ed.getTime())) { ed.setDate(ed.getDate() + 1); eIso = ed.getFullYear() + '-' + pkPad2(ed.getMonth() + 1) + '-' + pkPad2(ed.getDate()); }
        } else {
            eIso = String(endVal).replace(' ', 'T');
        }
        var ev = { title: name, start: sIso, color: color, textColor: orkCiTextColor(color), type: 'calendar-item', allDay: !!allDay, extendedProps: { calendarItemId: id } };
        if (eIso) ev.end = eIso;
        pkCalEvents.push(ev);
        if (pkCalendar) pkCalendar.refetchEvents();
    }

    function pkGridRemoveCalendarItem(id) {
        if (typeof pkCalEvents === 'undefined' || !pkCalEvents) return;
        for (var i = pkCalEvents.length - 1; i >= 0; i--) {
            var xp = pkCalEvents[i] && pkCalEvents[i].extendedProps;
            if (xp && String(xp.calendarItemId) === String(id)) pkCalEvents.splice(i, 1);
        }
        if (pkCalendar) pkCalendar.refetchEvents();
    }

    // Insert a new calendar item into the Park events list in place (no reload). Returns false
    // if the list is currently empty (no table rendered) so the caller can fall back to a reload.
    function pkCiClassName(officerOnly, localsOnly) {
        var cls = [];
        if (officerOnly) cls.push('pk-officer-only');
        if (localsOnly)  cls.push('pk-locals-only');
        return cls.join(' ');
    }

    // Build the cells for a park calendar-item row (shared by insert + edit).
    function pkCiRowCellsHtml(name, startVal, officerOnly, localsOnly, color) {
        var sd = String(startVal || '').substring(0, 10);
        var dateTxt = '';
        if (sd && sd !== '0000-00-00') {
            var d = new Date(sd + 'T00:00:00');
            if (!isNaN(d.getTime())) dateTxt = d.toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
        }
        var offPill = officerOnly ? ' <span class="pk-officer-pill" data-tip="Officer-only — hidden from non-officers"><i class="fas fa-shield-alt"></i></span>' : '';
        var locPill = localsOnly ? ' <span class="pk-locals-pill" data-tip="Locals-only — hidden from out-of-area players"><i class="fas fa-map-marker-alt"></i></span>' : '';
        color = color || '#64748b';
        var pillStyle = ' style="background:' + color + ';border-color:' + color + ';color:' + orkCiTextColor(color) + '"';
        return '<td><span class="pk-ci-pill"' + pillStyle + '><i class="fas fa-calendar-day"></i> Calendar Item</span>' + offPill + locPill + ' ' + pkCiEsc(name) + '</td>' +
            '<td class="pk-date-col" data-sortval="' + pkCiEsc(sd) + '">' + dateTxt + '</td>' +
            '<td class="pk-date-col" colspan="2" style="text-align:right;color:#a0aec0;padding-right:8px;">—</td>';
    }

    function pkInsertCalendarItemRow(id, name, startVal, officerOnly, localsOnly, color) {
        var table = document.getElementById('pk-events-table');
        if (!table) return false;
        var tbody = table.querySelector('tbody');
        if (!tbody) return false;

        var tr = document.createElement('tr');
        tr.className = pkCiClassName(officerOnly, localsOnly);
        tr.setAttribute('data-type', 'calendar-item');
        tr.setAttribute('onclick', 'pkShowCalendarItemOverlay(' + id + ')');
        tr.innerHTML = pkCiRowCellsHtml(name, startVal, officerOnly, localsOnly, color);

        if (typeof pkFilters !== 'undefined' && pkFilters['calendar-item'] === false) tr.style.display = 'none';

        tbody.insertBefore(tr, tbody.firstChild);
        pkBumpEventsTabCount(1);
        if (window.jQuery) pkPaginate(jQuery('#pk-events-table'), 1);
        return true;
    }

    // Update an existing park calendar-item row in place. Returns false if not found.
    function pkUpdateCalendarItemRow(id, name, startVal, officerOnly, localsOnly, color) {
        var row = document.querySelector('#pk-events-table tr[onclick="pkShowCalendarItemOverlay(' + id + ')"]');
        if (!row) return false;
        row.className = pkCiClassName(officerOnly, localsOnly);
        row.innerHTML = pkCiRowCellsHtml(name, startVal, officerOnly, localsOnly, color);
        if (typeof pkFilters !== 'undefined' && pkFilters['calendar-item'] === false) row.style.display = 'none';
        return true;
    }

    function pkSubmitCalendarItem() {
        var name   = document.getElementById('pk-event-name').value.trim();
        var allDay = document.getElementById('pk-ci-allday').checked ? 1 : 0;
        var start  = document.getElementById('pk-ci-start').value;
        var end    = document.getElementById('pk-ci-end').value;
        var desc   = document.getElementById('pk-ci-description').value;
        if (!name || !start || !end) return;

        var btn = document.getElementById('pk-emod-go-btn');
        btn.disabled = true;

        var officerOnly = document.getElementById('pk-ci-officer-only');
        var localsOnly  = document.getElementById('pk-ci-locals-only');
        var colorEl     = document.getElementById('pk-ci-color');
        var color       = (colorEl && colorEl.value) || '#64748b';
        var payload = {
            Name: name, Description: desc, AllDay: allDay,
            EventStart: start, EventEnd: end,
            KingdomId: EvCreateConfig.kingdomId, ParkId: EvCreateConfig.parkId,
            IsOfficerOnly: (officerOnly && officerOnly.checked) ? 1 : 0,
            IsLocalsOnly:  (localsOnly  && localsOnly.checked)  ? 1 : 0,
            Color: color
        };
        var url = CI_CREATE_URL;
        var wasEditing = (pkCiEditingId > 0);
        if (wasEditing) { payload.CalendarItemId = pkCiEditingId; url = CI_UPDATE_URL; }

        $.post(url, payload, function(r) {
            if (r && r.status === 0) {
                pkCloseEventModal();
                if (wasEditing) {
                    // Replace the grid entry (remove old, add updated) and update the list row
                    // in place. Reload only if the row isn't present in the DOM.
                    pkGridRemoveCalendarItem(payload.CalendarItemId);
                    pkGridAddCalendarItem(payload.CalendarItemId, name, start, end, allDay == 1, color);
                    if (!pkUpdateCalendarItemRow(payload.CalendarItemId, name, start, payload.IsOfficerOnly == 1, payload.IsLocalsOnly == 1, color)) {
                        pkReloadToEventsTab();
                    }
                } else {
                    // New item: update the grid array + list row in place so repeated entry
                    // never reloads. If the list was empty (no table yet), fall back to reload.
                    pkGridAddCalendarItem(parseInt(r.id) || 0, name, start, end, allDay == 1, color);
                    if (!pkInsertCalendarItemRow(parseInt(r.id) || 0, name, start, payload.IsOfficerOnly == 1, payload.IsLocalsOnly == 1, color)) {
                        pkReloadToEventsTab();
                    }
                }
            } else {
                pkEvFeedback((r && r.error) ? r.error : 'Failed to save calendar item.');
                btn.disabled = false;
            }
        }, 'json').fail(function() { pkEvFeedback('Request failed. Please try again.'); btn.disabled = false; });
    }

    // ---- View / edit / delete overlay ----
    window.pkShowCalendarItemOverlay = function(id) {
        $.getJSON(CI_GET_URL + id, function(r) {
            if (!r || r.status !== 0) { pkEvAlert((r && r.error) || 'Calendar item not found.'); return; }
            pkCiCurrent = r;
            document.getElementById('pk-ci-view-name').textContent = r.Name || '';
            document.getElementById('pk-ci-view-when').textContent = pkCiFormatWhen(r);
            document.getElementById('pk-ci-view-scope').innerHTML = (r.ParkId > 0 ? 'Park-level calendar item' : 'Kingdom-level calendar item')
                + (r.IsOfficerOnly == 1 ? ' &middot; <span style="color:#805ad5"><i class="fas fa-shield-alt"></i> Officer-only</span>' : '')
                + (r.IsLocalsOnly  == 1 ? ' &middot; <span style="color:#0d9488"><i class="fas fa-map-marker-alt"></i> Locals-only</span>' : '');
            var descEl = document.getElementById('pk-ci-view-desc');
            descEl.textContent = r.Description || '';
            descEl.style.display = r.Description ? '' : 'none';
            document.getElementById('pk-ci-edit-btn').style.display   = r.CanEdit ? '' : 'none';
            document.getElementById('pk-ci-delete-btn').style.display = r.CanEdit ? '' : 'none';
            document.getElementById('pk-ci-overlay').classList.add('pk-ci-open');
            document.body.style.overflow = 'hidden';
        }).fail(function() { pkEvAlert('Failed to load calendar item.'); });
    };

    window.pkCloseCalendarItemOverlay = function() {
        document.getElementById('pk-ci-overlay').classList.remove('pk-ci-open');
        document.body.style.overflow = '';
    };

    function pkCiFormatWhen(r) {
        var s = r.EventStart || '';
        var e = r.EventEnd   || s;
        var sd = s.substring(0, 10), ed = e.substring(0, 10);
        var sdObj = new Date(sd + 'T00:00:00');
        var edObj = new Date(ed + 'T00:00:00');
        var fmt = { weekday: 'short', year: 'numeric', month: 'long', day: 'numeric' };
        if (r.AllDay == 1 || r.AllDay === true) {
            return (sd === ed)
                ? 'All day · ' + sdObj.toLocaleDateString(undefined, fmt)
                : 'All day · ' + sdObj.toLocaleDateString(undefined, fmt) + ' → ' + edObj.toLocaleDateString(undefined, fmt);
        }
        var tfmt = { hour: 'numeric', minute: '2-digit' };
        var startStr = new Date(s.replace(' ', 'T')).toLocaleString(undefined, Object.assign({}, fmt, tfmt));
        var endStr   = new Date(e.replace(' ', 'T')).toLocaleString(undefined, Object.assign({}, fmt, tfmt));
        return (sd === ed)
            ? startStr + ' → ' + new Date(e.replace(' ', 'T')).toLocaleTimeString(undefined, tfmt)
            : startStr + ' → ' + endStr;
    }

    window.pkEditCalendarItem = function() {
        if (!pkCiCurrent) return;
        pkCloseCalendarItemOverlay();
        var modal = document.getElementById('pk-event-modal');
        if (!modal) { pkEvAlert('You do not have permission to edit this item.'); return; }
        modal.dataset.presetDate = '';
        pkCiEditingId = pkCiCurrent.CalendarItemId;
        document.querySelectorAll('input[name="pk-emod-type"]').forEach(function(r) {
            r.checked = (r.value === 'calendar-item');
            r.disabled = true;
        });
        document.getElementById('pk-event-name').value = pkCiCurrent.Name || '';
        document.getElementById('pk-ci-description').value = pkCiCurrent.Description || '';
        document.getElementById('pk-ci-allday').checked = (pkCiCurrent.AllDay == 1);
        var off = document.getElementById('pk-ci-officer-only'); if (off) off.checked = (pkCiCurrent.IsOfficerOnly == 1);
        var loc = document.getElementById('pk-ci-locals-only');  if (loc) loc.checked = (pkCiCurrent.IsLocalsOnly  == 1);
        document.getElementById('pk-emod-feedback').style.display = 'none';
        document.getElementById('pk-emod-date-row').style.display = 'none';
        var sVal = (pkCiCurrent.AllDay == 1) ? pkCiCurrent.EventStart.substring(0, 10) : pkCiCurrent.EventStart.substring(0, 16).replace('T', ' ');
        var eVal = (pkCiCurrent.AllDay == 1) ? pkCiCurrent.EventEnd.substring(0, 10)   : pkCiCurrent.EventEnd.substring(0, 16).replace('T', ' ');
        pkCiSetColor(pkCiCurrent.Color || '#64748b');
        pkCiRebuildPickers(sVal, eVal, (pkCiCurrent.AllDay == 1));
        pkApplyModalType();
        modal.classList.add('pk-emod-open');
        document.body.style.overflow = 'hidden';
    };

    window.pkDeleteCalendarItem = function() {
        if (!pkCiCurrent) return;
        var delId = pkCiCurrent.CalendarItemId;
        orkConfirm('Delete this calendar item? This cannot be undone.', function() {
            $.post(CI_DELETE_URL, { CalendarItemId: delId }, function(r) {
                if (r && r.status === 0) {
                    pkCloseCalendarItemOverlay();
                    pkGridRemoveCalendarItem(delId);
                    // Remove the list row in place; fall back to a tab-preserving reload if not found.
                    var row = document.querySelector('#pk-events-table tr[onclick="pkShowCalendarItemOverlay(' + delId + ')"]');
                    if (row) {
                        var tbody = row.parentNode;
                        tbody.removeChild(row);
                        pkBumpEventsTabCount(-1);
                        if (tbody.children.length === 0) {
                            pkReloadToEventsTab(); // list now empty — reload to render the empty state
                            return;
                        }
                        if (window.jQuery) pkPaginate(jQuery('#pk-events-table'), 1);
                    } else {
                        pkReloadToEventsTab();
                    }
                } else {
                    pkEvAlert((r && r.error) || 'Failed to delete calendar item.');
                }
            }, 'json').fail(function() { pkEvAlert('Request failed.'); });
        }, { title: 'Delete calendar item', okLabel: 'Delete', danger: true });
    };

    $(document).ready(function() {
        $('#pk-event-name, #pk-ci-start, #pk-ci-end').on('input change', function() { pkUpdateGoBtn(); });
        $('#pk-ci-swatches').on('click', '.ci-swatch', function() { pkCiSetColor(this.getAttribute('data-color')); });
        $('#pk-event-name').on('keydown', function(e) {
            if (e.key === 'Enter' && !document.getElementById('pk-emod-go-btn').disabled) pkCreateEvent();
        });
        $(document).on('change', 'input[name="pk-emod-type"]', pkApplyModalType);
        $('#pk-ci-allday').on('change', function() {
            var allDay = this.checked;
            var curS = document.getElementById('pk-ci-start').value;
            var curE = document.getElementById('pk-ci-end').value;
            pkCiRebuildPickers(curS ? curS.substring(0, 10) : '', curE ? curE.substring(0, 10) : '', allDay);
            pkUpdateGoBtn();
        });

        var pkEvtOverlay = document.getElementById('pk-event-modal');
        if (pkEvtOverlay) {
            pkEvtOverlay.addEventListener('click', function(e) { if (e.target === this) pkCloseEventModal(); });
        }
        var pkCiOverlayEl = document.getElementById('pk-ci-overlay');
        if (pkCiOverlayEl) {
            pkCiOverlayEl.addEventListener('click', function(e) { if (e.target === this) pkCloseCalendarItemOverlay(); });
        }
        document.addEventListener('keydown', function(e) {
            if (e.key !== 'Escape') return;
            var m = document.getElementById('pk-event-modal');
            if (m && m.classList.contains('pk-emod-open')) { pkCloseEventModal(); return; }
            var o = document.getElementById('pk-ci-overlay');
            if (o && o.classList.contains('pk-ci-open')) { pkCloseCalendarItemOverlay(); }
        });
    });
})();
/* ============================================================================
   Park — Copy From Past Event (pk-cfe-*)
   Park-scope mirror of the Kingdom block in revised.js. Sources are scoped to
   EvCreateConfig.parkId; the request omits KingdomId so the backend uses pure
   park scope.

   This MUST stay below the create-modal block above: it wraps window.pkCreateEvent
   and keeps the original in _origPkCreateEvent. Swap the two and the wrapper
   captures undefined -- copy-from-event then silently stops working.
   ============================================================================ */
(function() {
    if (typeof EvCreateConfig === 'undefined' || !EvCreateConfig || !EvCreateConfig.parkId) return;

    var SRC_URL = EvCreateConfig.uir + 'EventAjax/copy_source_list';
    var GO_URL  = EvCreateConfig.uir + 'EventAjax/create_with_copy';
    var CFE_DEBOUNCE_MS = 200;
    var DELTA_MS_DEFAULT = 0;
    var pickerStart = null;
    var pickerEnd   = null;
    var debounceTimer = null;
    var lastQuery = null;

    function gid(id) { return document.getElementById(id); }
    function fireInput(el) {
        if (!el) return;
        try { el.dispatchEvent(new Event('input',  { bubbles: true })); } catch(e) {}
        try { el.dispatchEvent(new Event('change', { bubbles: true })); } catch(e) {}
    }

    var _pkCfeReposBound = false;
    function pkCfePositionResults() {
        var input = gid('pk-cfe-search');
        var box   = gid('pk-cfe-results');
        if (!input || !box) return;
        box.style.position = 'fixed';
        box.style.zIndex   = '10000';
        /* tnPositionAcFixed is a revised.js export, and this is the call this block
           has always made -- the park profile still takes that first branch, so its
           dropdown places identically. The Park admin console does not load
           revised.js; it already ships tnFixedAcPosition (partials/_park_admin_modals.tpl,
           the house helper named in the kn-ac rule) which anchors the same
           kn-ac-results dropdown. Falling back to it beats duplicating either one.
           Without this the console threw a ReferenceError mid-render, which the
           caller's .catch() swallowed into a permanent "No matching past events". */
        var place = window.tnPositionAcFixed || window.tnFixedAcPosition;
        if (place) { place(input, box); }
    }
    function pkCfeBindReposition() {
        if (_pkCfeReposBound) return;
        _pkCfeReposBound = true;
        var handler = function() {
            var box = gid('pk-cfe-results');
            if (box && box.classList.contains('kn-ac-open')) pkCfePositionResults();
        };
        window.addEventListener('scroll', handler, true);
        window.addEventListener('resize', handler);
    }

    window.pkCfeToggleExpander = function() {
        var body = gid('pk-cfe-body');
        var btn  = gid('pk-cfe-toggle');
        if (!body || !btn) return;
        var open = body.style.display !== 'none';
        body.style.display = open ? 'none' : '';
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        if (!open) {
            setTimeout(function() { var s = gid('pk-cfe-search'); if (s) s.focus(); }, 50);
        }
    };

    function fmtDate(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleDateString(undefined, { year: 'numeric', month: 'short', day: 'numeric' });
    }

    function renderResults(rows) {
        var box = gid('pk-cfe-results');
        var input = gid('pk-cfe-search');
        if (!box || !input) return;
        box.innerHTML = '';
        if (!rows || rows.length === 0) {
            box.innerHTML = '<div class="kn-ac-empty">No matching past events</div>';
            pkCfePositionResults();
            pkCfeBindReposition();
            box.classList.add('kn-ac-open');
            return;
        }
        rows.forEach(function(r) {
            var row = document.createElement('div');
            row.className = 'kn-ac-row';
            var occ = r.occurrenceCount > 1 ? (' · ' + r.occurrenceCount + ' occurrences') : '';
            row.innerHTML = '<div class="kn-ac-row-title"></div><div class="kn-ac-row-meta"></div>';
            row.querySelector('.kn-ac-row-title').textContent = r.name;
            row.querySelector('.kn-ac-row-meta').textContent  = fmtDate(r.lastStart) + occ;
            row.addEventListener('mousedown', function(e) { e.preventDefault(); pkCfePick(r); });
            box.appendChild(row);
        });
        pkCfePositionResults();
        pkCfeBindReposition();
        box.classList.add('kn-ac-open');
    }

    function runSearch(q) {
        var params = '&ParkId=' + encodeURIComponent(EvCreateConfig.parkId) + '&Query=' + encodeURIComponent(q);
        fetch(SRC_URL + params, { credentials: 'same-origin' })
            .then(function(r) { return r.ok ? r.json() : null; })
            .then(function(d) {
                if (!d || d.status !== 0) { renderResults([]); return; }
                var rows = d.results || [];
                if (q === '') rows = rows.slice(0, 3);
                renderResults(rows);
            })
            .catch(function() { renderResults([]); });
    }

    function onSearchInput() {
        var input = gid('pk-cfe-search');
        var q = input ? input.value.trim() : '';
        if (debounceTimer) clearTimeout(debounceTimer);
        if (q === lastQuery) return;
        lastQuery = q;
        debounceTimer = setTimeout(function() { runSearch(q); }, CFE_DEBOUNCE_MS);
    }

    window.pkCfePick = function(srcRow) {
        gid('pk-cfe-source-id').value    = srcRow.eventId;
        gid('pk-cfe-source-start').value = srcRow.lastStart || '';
        gid('pk-cfe-source-end').value   = srcRow.lastEnd   || '';
        gid('pk-cfe-chip-label').textContent = srcRow.name + ' · ' + fmtDate(srcRow.lastStart);
        gid('pk-cfe-chip').style.display = '';
        gid('pk-cfe-picker-wrap').style.display = 'none';
        gid('pk-cfe-detail').style.display = '';
        gid('pk-cfe-results').classList.remove('kn-ac-open');

        var nameEl = gid('pk-event-name');
        if (nameEl && nameEl.value.trim() === '') {
            var yr = new Date().getFullYear();
            nameEl.value = srcRow.name + ' ' + yr;
            fireInput(nameEl);
        }

        var sStart = srcRow.lastStart ? new Date(srcRow.lastStart.replace(' ', 'T')) : null;
        var sEnd   = srcRow.lastEnd   ? new Date(srcRow.lastEnd.replace(' ', 'T'))   : null;
        DELTA_MS_DEFAULT = (sStart && sEnd && !isNaN(sStart) && !isNaN(sEnd)) ? (sEnd.getTime() - sStart.getTime()) : 0;
        initPickers();
    };

    window.pkCfeClear = function() {
        gid('pk-cfe-source-id').value    = '';
        gid('pk-cfe-source-start').value = '';
        gid('pk-cfe-source-end').value   = '';
        gid('pk-cfe-chip').style.display = 'none';
        gid('pk-cfe-picker-wrap').style.display = '';
        gid('pk-cfe-detail').style.display = 'none';
        var s = gid('pk-cfe-search'); if (s) { s.value = ''; lastQuery = null; }
    };

    function initPickers() {
        if (typeof flatpickr !== 'function') return;
        var startEl = gid('pk-cfe-start');
        var endEl   = gid('pk-cfe-end');
        if (!startEl || !endEl) return;
        if (startEl._flatpickr) startEl._flatpickr.destroy();
        if (endEl._flatpickr)   endEl._flatpickr.destroy();
        var opts = {
            enableTime: true, dateFormat: 'Y-m-d H:i',
            altInput: true,  altFormat: 'F j, Y  h:i K',
            minuteIncrement: 5, time_24hr: false, allowInput: false
        };
        pickerEnd = flatpickr(endEl, opts);
        pickerStart = flatpickr(startEl, Object.assign({}, opts, {
            onChange: function(selDates) {
                if (!selDates[0]) return;
                var d = new Date(selDates[0].getTime() + DELTA_MS_DEFAULT);
                pickerEnd.setDate(d, true);
            }
        }));
    }

    window.pkCfeToggleAll = function(masterCb) {
        document.querySelectorAll('.pk-cfe-mod').forEach(function(cb) { cb.checked = masterCb.checked; });
    };
    window.pkCfeSyncAll = function() {
        var all = gid('pk-cfe-mod-all');
        if (!all) return;
        var boxes = Array.from(document.querySelectorAll('.pk-cfe-mod'));
        var checked = boxes.filter(function(cb) { return cb.checked; }).length;
        all.checked = (checked === boxes.length);
        all.indeterminate = (checked > 0 && checked < boxes.length);
    };

    function pkCfeFeedback(msg) {
        var el = document.getElementById('pk-emod-feedback');
        if (el) { el.textContent = msg; el.style.display = ''; }
        try { console.log('[pkCfe]', msg); } catch(e) {}
    }

    var _origPkCreateEvent = window.pkCreateEvent;
    window.pkCreateEvent = function(statusOverride) {
        var srcEl = gid('pk-cfe-source-id');
        var srcId = srcEl ? parseInt(srcEl.value || '0', 10) : 0;
        try { console.log('[pkCfe] create click — srcId:', srcId, 'statusOverride:', statusOverride); } catch(e) {}
        if (!srcId) { if (_origPkCreateEvent) return _origPkCreateEvent(statusOverride); return; }

        var name  = ((gid('pk-event-name') || {}).value || '').trim();
        var start = (gid('pk-cfe-start') || {}).value || '';
        var end   = (gid('pk-cfe-end')   || {}).value || '';
        try { console.log('[pkCfe] fields — name:', name, 'start:', start, 'end:', end); } catch(e) {}
        if (!name)  { pkCfeFeedback('Event name is required.'); return; }
        if (!start || !end) { pkCfeFeedback('Start and end times are required.'); return; }

        var btn  = gid('pk-emod-go-btn');
        var dbtn = gid('pk-emod-draft-btn');
        if (btn)  btn.disabled  = true;
        if (dbtn) dbtn.disabled = true;

        var status = (statusOverride === 'draft') ? 'draft' : 'published';
        var modules = {
            details:  gid('pk-cfe-mod-details').checked,
            schedule: gid('pk-cfe-mod-schedule').checked,
            staff:    gid('pk-cfe-mod-staff').checked,
            feast:    gid('pk-cfe-mod-feast').checked,
            banner:   gid('pk-cfe-mod-banner').checked
        };

        $.post(GO_URL, {
            Name: name, KingdomId: EvCreateConfig.kingdomId, ParkId: EvCreateConfig.parkId,
            SourceEventId: srcId, NewStart: start, NewEnd: end,
            Modules: JSON.stringify(modules), Status: status
        }, function(r) {
            if (r && r.status === 0) {
                if (r.warnings && r.warnings.length) { try { console.log('Copy completed with warnings:', r.warnings); } catch(e) {} }
                window.location.href = r.url;
            } else {
                pkCfeFeedback((r && r.error) ? r.error : 'Failed to copy event.');
                if (btn)  btn.disabled  = false;
                if (dbtn) dbtn.disabled = false;
            }
        }, 'json').fail(function(xhr) {
            try { console.error('[pkCfe] POST failed:', xhr && xhr.status, xhr && xhr.responseText); } catch(e) {}
            pkCfeFeedback('Request failed. Please try again.');
            if (btn)  btn.disabled  = false;
            if (dbtn) dbtn.disabled = false;
        });
    };

    document.addEventListener('DOMContentLoaded', function() {
        var s = gid('pk-cfe-search');
        if (s) {
            s.addEventListener('input', onSearchInput);
            s.addEventListener('focus', function() { if (lastQuery === null) runSearch(''); });
            s.addEventListener('blur',  function() { setTimeout(function() { var b = gid('pk-cfe-results'); if (b) b.classList.remove('kn-ac-open'); }, 150); });
        }
    });

    function pkCfeIsInsidePicker(picker, target) {
        if (!picker) return false;
        if (picker.altInput && picker.altInput.contains(target)) return true;
        if (picker.input    && picker.input.contains(target))    return true;
        if (picker.calendarContainer && picker.calendarContainer.contains(target)) return true;
        return false;
    }
    document.addEventListener('mousedown', function(e) {
        if (pickerStart && pickerStart.isOpen && !pkCfeIsInsidePicker(pickerStart, e.target)) pickerStart.close();
        if (pickerEnd   && pickerEnd.isOpen   && !pkCfeIsInsidePicker(pickerEnd,   e.target)) pickerEnd.close();
    }, true);
})();
</script>
