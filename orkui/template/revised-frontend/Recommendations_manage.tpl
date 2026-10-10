<?php if (!empty($Error)) { ?>
  <div class="rm-wrap"><div class="rm-error"><?= htmlspecialchars($Error) ?></div></div>
<?php return; }
  $backUrl = $ParkId > 0
    ? UIR . 'Park/index/' . (int)$ParkId
    : UIR . 'Kingdom/index/' . (int)$KingdomId;
?>
<!-- The ~676-line inline <style> that used to live here is now
     default/style/recs-manager.css (cacheable, parsed once, and visible to the
     CSS boundary tooling). -->
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/recs-manager.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/recs-manager.css') ?>">

<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css?v=<?= filemtime(DIR_TEMPLATE . 'default/style/reports.css') ?>">
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/rank-pill.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/rank-pill.css') ?>">
<!-- flatpickr for the "create court" date field. Loaded here (same CDN pair as
     default/Court_detail.tpl) so the script is parsed before the inline init far
     below runs — that init used to be permanently dead because nothing on this
     page ever defined flatpickr. -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
<script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>

<div class="rp-root">
<div class="rm-wrap">
  <div class="rp-header">
    <div class="rp-header-left">
      <div class="rp-header-icon-title">
        <i class="fas fa-star rp-header-icon"></i>
        <h1 class="rp-header-title">Recommendations Manager</h1>
      </div>
      <div class="rp-header-scope">
        <a class="rp-scope-chip" href="<?= htmlspecialchars($backUrl) ?>">
          <i class="fas fa-<?= $ParkId > 0 ? 'map-marker-alt' : 'crown' ?>"></i>
          <span class="rp-scope-chip-label"><?= $ParkId > 0 ? 'Park' : 'Kingdom' ?>:</span> <?= htmlspecialchars($LocationName) ?>
        </a>
      </div>
    </div>
    <div class="rp-header-actions">
      <a class="rp-btn-ghost" href="<?= htmlspecialchars($backUrl) ?>"><i class="fas fa-arrow-left"></i> Back</a>
    </div>
  </div>
  <div class="rp-context">
    <i class="fas fa-info-circle rp-context-icon"></i>
    <span>Review, grant, dismiss, snooze, pass-down, and schedule award recommendations for <strong><?= htmlspecialchars($LocationName) ?></strong> &mdash; <?= (int)($Total ?? 0) ?> pending.</span>
  </div>

  <div class="rm-filterbar" id="rm-filterbar">
    <input type="search" id="rm-search" class="rm-search" placeholder="Search recipient&hellip;" autocomplete="off">
    <select id="rm-filter-elig" class="rm-fsel">
      <option value="open" selected>Open Recs</option>
      <option value="below">Below Rec&rsquo;d</option>
      <option value="nonladder">Non-Ladder</option>
      <option value="ator">At or Above Rec&rsquo;d</option>
      <option value="all">All</option>
      <option value="snoozed">Snoozed</option>
    </select>
    <select id="rm-filter-court" class="rm-fsel">
      <option value="all">Any court status</option>
      <option value="none">Not on a court</option>
      <option value="any">On any court</option>
      <?php foreach ($Courts as $c) {
          // Kingdom scope now lists subordinate park courts too, so a bare name is
          // ambiguous when two parks both run a "Midreign" — qualify with the park.
          $cLabel = $c['Name'] . (!empty($c['ParkName']) ? ' (' . $c['ParkName'] . ')' : '');
      ?>
        <option value="court:<?= (int)$c['CourtId'] ?>">On: <?= htmlspecialchars($cLabel) ?></option>
      <?php } ?>
    </select>
    <?php if ($ParkId === 0 && count($Parks)) { ?>
    <select id="rm-filter-park" class="rm-fsel">
      <option value="all">All parks</option>
      <?php foreach ($Parks as $pid => $p) { ?>
        <option value="<?= (int)$pid ?>"><?= htmlspecialchars($p['Name']) ?></option>
      <?php } ?>
    </select>
    <?php } ?>
    <label class="rm-fcheck"><input type="checkbox" id="rm-filter-passlocal"> Passed to local</label>
    <label class="rm-fcheck" data-tip="Dismissed recommendations are kept, not deleted. Turn this on to see them and undelete any that were retired by mistake."><input type="checkbox" id="rm-filter-dismissed"> Show dismissed</label>
    <div id="rm-chips" class="rm-chips"></div>
    <button type="button" id="rm-export" class="rm-fbtn" data-tip="Download the full current filtered list as a CSV file"><i class="fas fa-download"></i> Export CSV</button>
  </div>

  <div class="rm-gridwrap">
  <table class="rm-grid" id="rm-grid">
    <thead>
      <tr>
        <!-- data-tip renders via ::after, which a replaced element like <input>
             cannot generate — it has to live on the wrapping label. -->
        <th class="rm-col-sel"><label data-tip="Selects the rows loaded so far, not every row matching your filters"><input type="checkbox" id="rm-selall" aria-label="Select all loaded rows"></label></th>
        <th class="rm-col-recip rm-sortable" data-sort="recip">Recipient</th>
        <th class="rm-col-park">Park</th>
        <th class="rm-col-award rm-sortable" data-sort="award">Award</th>
        <th class="rm-col-rank rm-sortable" data-sort="rank">Rank</th>
        <th class="rm-col-rec rm-sortable" data-sort="date">Recommended</th>
        <th class="rm-col-reason">Reason</th>
        <th class="rm-col-supp rm-sortable" data-sort="supp">Support</th>
        <th class="rm-col-court">Court</th>
        <th class="rm-col-act">Actions</th>
      </tr>
    </thead>
    <tbody id="rm-tbody">
    <?php foreach ($Groups as $group) { include __DIR__ . '/_rm_row.tpl'; } ?>
    </tbody>
  </table>
  </div>
  <div id="rm-loading" class="rm-loading" style="display:none">Loading&hellip;</div>
  <div id="rm-sentinel" style="height:1px"></div>
  <div class="rm-foot">
    Showing <span id="rm-count"><?= count($Groups) ?></span> of <span id="rm-total"><?= (int)($Total ?? 0) ?></span>
    <span id="rm-loadnote" class="rm-loadnote"></span>
    &middot; <span id="rm-selcount">0</span> selected
  </div>

  <div class="rm-bulkbar" id="rm-bulkbar" hidden>
    <span id="rm-bulklabel">0 selected</span>
    <button type="button" class="rm-bulk rm-bulk-court">Add to Court</button>
    <button type="button" class="rm-bulk rm-bulk-snooze">Snooze</button>
    <?php if (($Context ?? '') === 'kingdom') { ?><button type="button" class="rm-bulk rm-bulk-passlocal" data-tip="For recommendations at a higher level than the park can provide, you are granting authority for that park to award at this level.">Pass down</button><?php } ?>
    <button type="button" class="rm-bulk rm-bulk-dismiss" data-tip="Already given out previously? No plans to award this? You can dismiss this rec.">Dismiss</button>
    <button type="button" class="rm-bulk rm-bulk-undelete"><i class="fas fa-trash-can-arrow-up"></i> Undelete</button>
    <button type="button" class="rm-bulk rm-bulk-clear">Clear</button>
  </div>

  <!-- Task 10: Add-to-Court modal -->
  <div class="rm-modal-overlay" id="rm-court-overlay" role="dialog" aria-modal="true" aria-labelledby="rm-court-title" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title" id="rm-court-title">Add to Court</h2>
      <div class="rm-modal-sub" id="rm-court-sub"></div>
      <div class="rm-modal-modes">
        <label><input type="radio" name="rm-court-mode" value="existing" checked> Existing court</label>
        <label><input type="radio" name="rm-court-mode" value="new"> Create new court</label>
      </div>
      <div id="rm-court-existing">
        <select id="rm-court-select" class="rm-fsel">
          <?php foreach ($Courts as $c) {
              // data-name is the name stored on the row badge; the visible label adds
              // the park (kingdom scope lists park courts), date and status.
              $cName  = $c['Name'] . (!empty($c['ParkName']) ? ' (' . $c['ParkName'] . ')' : '');
          ?>
            <option value="<?= (int)$c['CourtId'] ?>" data-name="<?= htmlspecialchars($cName) ?>"><?= htmlspecialchars($cName) ?><?= !empty($c['CourtDate']) ? ' &mdash; ' . htmlspecialchars(rmNiceDate($c['CourtDate'])) : '' ?> (<?= htmlspecialchars($c['Status']) ?>)</option>
          <?php } ?>
        </select>
        <?php if (!count($Courts)) { ?><div class="rm-empty">No courts yet &mdash; create one.</div><?php } ?>
      </div>
      <div id="rm-court-new" hidden>
        <input type="text" id="rm-court-name" class="rm-input" placeholder="Court name" maxlength="100">
        <input type="text" id="rm-court-date" class="rm-input" placeholder="Court date (optional)">
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-court-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-court-submit">Add</button>
      </div>
    </div>
  </div>

  <!-- Grant Award modal: pre-filled from the rec. The ⚡ button always opens this —
       we never insta-grant. When the rec is on a court plan, the officer also picks
       what happens to the planned court award. -->
  <div class="rm-modal-overlay" id="rm-grant-overlay" role="dialog" aria-modal="true" aria-labelledby="rm-grant-title" hidden>
    <div class="rm-modal">
      <h2 class="rm-modal-title" id="rm-grant-title">Grant Award</h2>
      <div class="rm-modal-sub" id="rm-grant-sub"></div>
      <div class="rm-form-error" id="rm-grant-error" hidden></div>
      <div class="rm-field" id="rm-grant-rank-wrap" hidden>
        <label>Rank <span class="rm-field-hint-inline">&mdash; green ranks are already held; grant a higher rank if earned</span></label>
        <div class="rm-rank-pills" id="rm-grant-rank-pills"></div>
        <input type="hidden" id="rm-grant-rank-val">
      </div>
      <div class="rm-field">
        <label for="rm-grant-date">Date</label>
        <input type="date" id="rm-grant-date" class="rm-input">
      </div>
      <div class="rm-field">
        <label for="rm-grant-givenby">Given by</label>
<?php if (!empty($PreloadOfficers)): ?>
        <div class="rm-officer-chips" id="rm-grant-officer-chips">
<?php foreach ($PreloadOfficers as $officer): ?>
          <button type="button" class="rm-officer-chip" data-id="<?= (int)$officer['MundaneId'] ?>" data-name="<?= htmlspecialchars($officer['Persona'], ENT_QUOTES) ?>"><?= htmlspecialchars($officer['Persona']) ?> <span>(<?= htmlspecialchars($officer['Role']) ?>)</span></button>
<?php endforeach; ?>
        </div>
<?php endif; ?>
        <div class="rm-ac-wrap">
          <input type="text" id="rm-grant-givenby" class="rm-input" autocomplete="off" placeholder="Search a player&hellip;">
          <input type="hidden" id="rm-grant-givenby-id">
          <div class="rm-ac-results" id="rm-grant-givenby-results"></div>
        </div>
        <div class="rm-field-hint">Defaults to you. For an association (e.g. a Knight taking a Squire), set the granter here.</div>
      </div>
      <div class="rm-field">
        <label for="rm-grant-givenat">Given at <span class="rm-field-hint-inline">(optional)</span></label>
        <div class="rm-ac-wrap">
          <input type="text" id="rm-grant-givenat" class="rm-input" autocomplete="off" placeholder="Search park, kingdom, or event&hellip;">
          <div class="rm-ac-results" id="rm-grant-givenat-results"></div>
        </div>
        <input type="hidden" id="rm-grant-park-id">
        <input type="hidden" id="rm-grant-kingdom-id">
        <input type="hidden" id="rm-grant-event-id" value="0">
      </div>
      <div class="rm-field">
        <label for="rm-grant-note">Note</label>
        <textarea id="rm-grant-note" class="rm-input" rows="3"></textarea>
      </div>
      <div class="rm-field" id="rm-grant-court-wrap" hidden>
        <label id="rm-grant-court-label">This recommendation is on a court plan</label>
        <label class="rm-radio-row"><input type="radio" name="rm-grant-court" value="remove" checked> Grant &amp; remove from court</label>
        <label class="rm-radio-row"><input type="radio" name="rm-grant-court" value="leave"> Grant &amp; leave on court</label>
      </div>
      <div class="rm-modal-actions">
        <button type="button" class="rm-btn rm-btn-ghost" id="rm-grant-cancel">Cancel</button>
        <button type="button" class="rm-btn rm-btn-primary" id="rm-grant-submit">Grant Award</button>
      </div>
    </div>
  </div>
</div>
</div>

<script>
window.RmConfig = {
  uir: '<?= UIR ?>',
  kingdomId: <?= (int)$KingdomId ?>,
  parkId: <?= (int)$ParkId ?>,
  context: '<?= $Context === 'park' ? 'park' : 'kingdom' ?>',
  userId: <?= (int)$Uid ?>,
  userName: <?= json_encode((string)($UserName ?? '')) ?>,
  httpService: <?= json_encode((string)(defined('HTTP_SERVICE') ? HTTP_SERVICE : '')) ?>,
  locationName: <?= json_encode((string)($LocationName ?? '')) ?>,
  rowsUrl:    '<?= UIR ?>Recommendations/rows/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
  bulkUrl:    '<?= UIR ?>Recommendations/bulk/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
  exportUrl:  '<?= UIR ?>Recommendations/export/<?= $Context ?>/<?= $Context === 'park' ? (int)$ParkId : (int)$KingdomId ?>',
  total:      <?= (int)($Total ?? 0) ?>,
  hasMore:    <?= !empty($HasMore) ? 'true' : 'false' ?>,
  nextOffset: <?= (int)($NextOffset ?? 0) ?>
};
</script>

<script>
// HTML-escape helper for JS-built markup.
function rmEsc(s) {
    var d = document.createElement('div');
    d.textContent = (s == null) ? '' : String(s);
    return d.innerHTML;
}

// Confirm dialog for this page — now a thin alias over the shared helper.
//
// This template renders under default.theme, which loads script/orkui.js, and
// orkui.js is where the one Promise-returning, focus-trapping, focus-restoring
// orkConfirm(opts) now lives (same {title, body, confirmLabel, cancelLabel,
// danger, onConfirm} shape this page and the Court templates were each
// hand-rolling). Kept as a named function so every existing call site is
// unchanged.
// default.theme links orkui.js with no ?v=filemtime cache-bust (it busts
// stylesheets only), so a client can hold a bundle predating orkConfirm. Without
// a guard that is a TypeError inside the click handler and Dismiss/Grant-again
// become silently dead buttons — a failure mode the old self-contained rmConfirm
// did not have. Guarded: never auto-confirm (these are destructive), say why, and
// keep the same Promise<boolean> contract every call site awaits.
function rmConfirm(opts) {
    if (typeof window.orkConfirm === 'function') { return window.orkConfirm(opts); }
    rmToast('This action needs a confirmation dialog that failed to load. Reload the page (Ctrl+Shift+R) and try again.', true);
    if (opts && typeof opts.onCancel === 'function') { opts.onCancel(); }
    return Promise.resolve(false);
}
// Same reasoning for the focus trap: if it is missing, the modal must still open
// and still close on Escape. Fallback = Escape + focus only, no Tab wrapping.
function rmDialogTrap(el, opts) {
    if (typeof window.orkDialogTrap === 'function') { return window.orkDialogTrap(el, opts); }
    opts = opts || {};
    var prev = document.activeElement;
    function onKey(e) {
        if ((e.key === 'Escape' || e.key === 'Esc') && typeof opts.onEscape === 'function') { e.preventDefault(); opts.onEscape(); }
    }
    document.addEventListener('keydown', onKey, true);
    var first = opts.initialFocus || el.querySelector('button,input,select,textarea,a[href]');
    if (first && first.focus) { setTimeout(function () { first.focus(); }, 30); }
    var released = false;
    return function () {
        if (released) { return; }
        released = true;
        document.removeEventListener('keydown', onKey, true);
        if (prev && prev.focus && document.contains(prev)) { prev.focus(); }
    };
}

// Insert (or toggle off) an inline detail row directly after `tr`.
function rmInsertDetail(tr, html, cls) {
    var next = tr.nextElementSibling;
    if (next && next.classList.contains(cls)) { next.remove(); return; } // toggle off
    // If a different detail row is open, replace it.
    if (next && next.classList.contains('rm-detailrow')) { next.remove(); }
    var dr = document.createElement('tr');
    dr.className = 'rm-detailrow ' + cls;
    dr.innerHTML = '<td></td><td colspan="9">' + html + '</td>';
    tr.parentNode.insertBefore(dr, tr.nextSibling);
}

// Member expand: list every recommendation in the cluster (recommender + reason
// + that member's seconds), built from data-membersfull. Reuses rmInsertDetail's
// toggle-on/off behavior.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var btn = e.target.closest('.rm-expand-members'); if (!btn) return;
    var tr = btn.closest('tr');
    var members = []; try { members = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
    var html = '<ul class="rm-seclist">';
    members.forEach(function (m) {
        var who = m.By ? rmEsc(m.By) : '(unknown)';
        var when = m.Date ? ' <span class="rm-age">' + rmEsc(m.Date) + '</span>' : '';
        html += '<li><strong>' + who + '</strong>' + when +
                (m.Reason ? '<div class="rm-reason-full">' + rmEsc(m.Reason) + '</div>' : '');
        if (m.Seconds && m.Seconds.length) {
            html += '<ul class="rm-seclist">';
            m.Seconds.forEach(function (s) {
                html += '<li>↳ ' + rmEsc(s.Name || '') + (s.Notes ? ' — ' + rmEsc(s.Notes) : ' <em class="rm-empty">(no note)</em>') + '</li>';
            });
            html += '</ul>';
        }
        html += '</li>';
    });
    html += '</ul>';
    rmInsertDetail(tr, html, 'rm-detail-members');
});

/* ---------- Server-side filter/sort + infinite-scroll lazy batches ---------- */
var RM = { rows: function () { return Array.from(document.querySelectorAll('#rm-tbody .rm-row')); } };

var rmState = {
	search: '', elig: 'open', court: 'all', park: 'all', passlocal: false,
	sort: 'date', dir: 'desc',
	offset: RmConfig.nextOffset || 0, total: RmConfig.total || 0,
	hasMore: !!RmConfig.hasMore, loading: false, seen: {},
	// Live count of checked rows. Kept incrementally so rmUpdateSelCount() never has
	// to re-scan the (up to 500) loaded rows on a checkbox click.
	selCount: 0,
	selDismissed: 0,
	// True while a bulk batch is in flight, so a second click cannot fire a
	// duplicate batch at the endpoint that is already the slow part.
	bulking: false
};
function rmReadFilters() {
	rmState.search = (document.getElementById('rm-search').value || '').trim();
	rmState.elig   = document.getElementById('rm-filter-elig').value;
	rmState.court  = document.getElementById('rm-filter-court').value;
	var pk = document.getElementById('rm-filter-park'); rmState.park = pk ? pk.value : 'all';
	rmState.passlocal = document.getElementById('rm-filter-passlocal').checked;
	rmState.dismissed = document.getElementById('rm-filter-dismissed').checked;
}
function rmRowKey(tr) { return tr.getAttribute('data-rec-cluster') || tr.getAttribute('data-rec-id'); }
/* Empty / error state. A completed fetch that matched nothing used to leave the
   tbody literally empty under a "Showing 0 of 0" footer — the same screen a
   failed load produced once its 2.6s toast expired, so a load error read as
   "nothing to triage". These two states now say which one happened, and the
   error one stays on screen until it is retried. */
function rmClearStateRow() {
    var s = document.getElementById('rm-staterow');
    if (s) s.remove();
}
// `reset` = this came from a full re-query (a filter/search/sort change). On that
// path the tbody is only emptied inside the SUCCESS branch, so a failed reset
// leaves every stale row in place and an appended error row lands past the last
// one — measured 17,170px below the fold on a 362-row list. Prepend it instead,
// so the officer sees "couldn't load" above rows that no longer match the filters.
function rmShowStateRow(kind, reset) {
    rmClearStateRow();
    var tr = document.createElement('tr');
    tr.id = 'rm-staterow';
    tr.className = 'rm-staterow' + (kind === 'error' ? ' rm-staterow-err' : '');
    if (kind === 'error') {
        tr.innerHTML = '<td colspan="10"><strong>Couldn’t load recommendations.</strong>'
            + (reset
                ? 'The rows below are still the previous view — they do not match the filters you just set.'
                : 'The list below may be incomplete.')
            + '<div><button type="button" class="rm-btn rm-btn-primary" id="rm-state-retry">Retry</button></div></td>';
    } else {
        tr.innerHTML = '<td colspan="10"><strong>No recommendations match these filters.</strong>'
            + 'Nothing is hidden by an error — the search and filters above simply matched no honors.'
            + '<div><button type="button" class="rm-btn rm-btn-ghost" id="rm-state-clear">Clear filters</button></div></td>';
    }
    var tb = document.getElementById('rm-tbody');
    if (kind === 'error' && reset && tb.firstChild) { tb.insertBefore(tr, tb.firstChild); }
    else { tb.appendChild(tr); }
    // An append-error (infinite scroll) lands where the officer already is; a
    // prepended one may still be off-screen if they scrolled. Either way, put it
    // in view without yanking the page around.
    if (kind === 'error' && tr.scrollIntoView) { tr.scrollIntoView({ block: 'nearest' }); }
    var retry = document.getElementById('rm-state-retry');
    if (retry) retry.addEventListener('click', function () { rmClearStateRow(); rmFetch(true); });
    var clear = document.getElementById('rm-state-clear');
    if (clear) clear.addEventListener('click', rmClearFilters);
}
// Reset every filter input to its default and re-query.
function rmClearFilters() {
    var s = document.getElementById('rm-search'); if (s) s.value = '';
    var el = document.getElementById('rm-filter-elig'); if (el) el.value = 'open';
    var ct = document.getElementById('rm-filter-court'); if (ct) ct.value = 'all';
    var pk = document.getElementById('rm-filter-park'); if (pk) pk.value = 'all';
    var plc = document.getElementById('rm-filter-passlocal'); if (plc) plc.checked = false;
    var dis = document.getElementById('rm-filter-dismissed'); if (dis) dis.checked = false;
    rmReadFilters();
    rmFetch(true);
}
function rmIndexSeen() { rmState.seen = {}; RM.rows().forEach(function (tr) { rmState.seen[rmRowKey(tr)] = true; }); }
function rmFetch(reset) {
	if (rmState.loading) return;
	rmState.loading = true;
	if (reset) { rmState.offset = 0; }
	var q = new URLSearchParams({
		search: rmState.search, elig: rmState.elig, court: rmState.court,
		park: rmState.park, passlocal: rmState.passlocal ? '1' : '',
		dismissed: rmState.dismissed ? '1' : '', sort: rmState.sort,
		dir: rmState.dir, offset: String(rmState.offset)
	});
	var tbody = document.getElementById('rm-tbody');
	rmClearStateRow();
	document.getElementById('rm-loading').style.display = '';
	fetch(RmConfig.rowsUrl + (RmConfig.rowsUrl.indexOf('?') >= 0 ? '&' : '?') + q.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
		.then(function (r) { if (!r.ok) throw new Error('rows http ' + r.status); return r.json(); })
		.then(function (d) {
			// A 200 with an error field (or missing paging data) is not a valid page — surface it.
			if (!d || d.error || typeof d.total === 'undefined') { throw new Error(d && d.error ? d.error : 'bad page'); }
			if (reset) {
				// The old rows (and therefore the old selection) are gone.
				tbody.innerHTML = ''; rmState.seen = {}; rmState.selCount = 0; rmState.selDismissed = 0;
				var sa = document.getElementById('rm-selall'); if (sa) sa.checked = false;
			}
			var tmp = document.createElement('tbody'); tmp.innerHTML = d.html;
			Array.prototype.slice.call(tmp.children).forEach(function (tr) {
				if (!tr.classList || !tr.classList.contains('rm-row')) { tbody.appendChild(tr); return; }
				var k = rmRowKey(tr);
				if (k && rmState.seen[k]) return;
				if (k) rmState.seen[k] = true;
				tbody.appendChild(tr);
			});
			rmState.offset  = d.offset;
			rmState.total   = d.total;
			rmState.hasMore = d.hasMore;
			document.getElementById('rm-count').textContent = RM.rows().length;
			document.getElementById('rm-total').textContent = d.total;
			if (typeof rmUpdateSelCount === 'function') rmUpdateSelCount();
			if (typeof rmSyncReasonExpanders === 'function') rmSyncReasonExpanders();
			if (!RM.rows().length) rmShowStateRow('empty');
		})
		.catch(function () {
			// Persistent, not just a toast that self-dismisses in 2.6s.
			rmShowStateRow('error', reset);
			if (typeof rmToast === 'function') rmToast('Failed to load.', true);
		})
		.finally(function () {
			rmState.loading = false;
			document.getElementById('rm-loading').style.display = 'none';
		});
}
// serverGone: did the row leave the SERVER's result set, or only this client-side
// bucket? Dismiss soft-deletes, and the paging query filters on deleted_by, so the
// cluster really is gone — total shrinks, and rmState.offset (a CLUSTER offset into
// that set) must shrink with it or the next infinite-scroll page starts N clusters
// too far in and silently skips N recommendations. Snooze changes nothing the base
// query filters on: the row leaves the current eligibility bucket but the server
// still counts it, so neither counter may move.
function rmAfterRowRemoved(serverGone) {
	document.getElementById('rm-count').textContent = RM.rows().length;
	if (serverGone) {
		if (rmState.total > 0) { rmState.total -= 1; document.getElementById('rm-total').textContent = rmState.total; }
		if (rmState.offset > 0) rmState.offset -= 1;
	}
	if (typeof rmUpdateSelCount === 'function') rmUpdateSelCount();
	if (!RM.rows().length) rmShowStateRow('empty');
}
rmIndexSeen();
// The server-rendered first page can itself be empty (a scope with nothing pending).
if (!RM.rows().length) rmShowStateRow('empty');
// filter inputs (debounced search) -> reset fetch
var rmDeb;
['rm-search', 'rm-filter-elig', 'rm-filter-court', 'rm-filter-park', 'rm-filter-dismissed'].forEach(function (idv) {
	var el = document.getElementById(idv); if (!el) return;
	el.addEventListener('input', function () { rmReadFilters(); clearTimeout(rmDeb); rmDeb = setTimeout(function () { rmFetch(true); }, 250); });
	el.addEventListener('change', function () { rmReadFilters(); clearTimeout(rmDeb); rmFetch(true); });
});
var rmPl = document.getElementById('rm-filter-passlocal'); if (rmPl) rmPl.addEventListener('change', function () { rmReadFilters(); rmFetch(true); });
// sort headers -> set sort + reset fetch
document.querySelectorAll('.rm-sortable').forEach(function (th) {
	th.addEventListener('click', function () {
		var key = th.getAttribute('data-sort');
		if (rmState.sort === key) rmState.dir = (rmState.dir === 'asc') ? 'desc' : 'asc';
		else { rmState.sort = key; rmState.dir = (key === 'date') ? 'desc' : 'asc'; }
		document.querySelectorAll('.rm-sortable').forEach(function (t) { t.classList.remove('rm-sort-asc', 'rm-sort-desc'); });
		th.classList.add(rmState.dir === 'asc' ? 'rm-sort-asc' : 'rm-sort-desc');
		rmFetch(true);
	});
});
// export current filtered set as CSV (server streams the FULL set, not just loaded batches)
var rmExportBtn = document.getElementById('rm-export');
if (rmExportBtn) rmExportBtn.addEventListener('click', function () {
	rmReadFilters();
	var q = new URLSearchParams({
		search: rmState.search, elig: rmState.elig, court: rmState.court,
		park: rmState.park, passlocal: rmState.passlocal ? '1' : '',
		dismissed: rmState.dismissed ? '1' : '',
		sort: rmState.sort, dir: rmState.dir
	});
	// brief disabled state — a large scope can take a moment to assemble server-side
	var label = rmExportBtn.innerHTML;
	rmExportBtn.disabled = true;
	rmExportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Exporting…';
	setTimeout(function () { rmExportBtn.disabled = false; rmExportBtn.innerHTML = label; }, 4000);
	window.location = RmConfig.exportUrl + (RmConfig.exportUrl.indexOf('?') >= 0 ? '&' : '?') + q.toString();
});
// infinite scroll
if ('IntersectionObserver' in window) {
	var rmObs = new IntersectionObserver(function (entries) {
		if (entries[0].isIntersecting && rmState.hasMore && !rmState.loading) rmFetch(false);
	}, { rootMargin: '400px' });
	var rmSent = document.getElementById('rm-sentinel'); if (rmSent) rmObs.observe(rmSent);
}

/* ---------- Task 7: selection + bulk bar ---------- */
var rmLastIdx = null;
// All rows currently loaded in the DOM. (Filtering is server-side now, so there is
// no client-side hidden state to skip — every loaded row is a visible row.)
function rmLoadedRows() { return RM.rows(); }
// Still used by the bulk handlers, which run once per action — never on the
// per-click path (that reads the incrementally maintained rmState.selCount).
function rmSelected() { return RM.rows().filter(function (tr) { return tr.querySelector('.rm-rowsel').checked; }); }
// Single choke point for changing a row's checked state so rmState.selCount stays
// exact. Every place that checks/unchecks a row programmatically must go through it.
function rmSetRowChecked(tr, on) {
    var cb = tr && tr.querySelector('.rm-rowsel');
    if (!cb || cb.checked === !!on) return;
    cb.checked = !!on;
    rmState.selCount += on ? 1 : -1;
    if (rmState.selCount < 0) rmState.selCount = 0;
    // A selection can mix live and dismissed rows, and the two support different
    // verbs. Count the dismissed half here rather than rescanning the table on
    // every click — same reason selCount is incremental.
    if (tr.getAttribute('data-dismissed') === '1') {
        rmState.selDismissed += on ? 1 : -1;
        if (rmState.selDismissed < 0) rmState.selDismissed = 0;
    }
}
function rmSelectedLive() {
    return rmSelected().filter(function (tr) { return tr.getAttribute('data-dismissed') !== '1'; });
}
function rmSelectedDismissed() {
    return rmSelected().filter(function (tr) { return tr.getAttribute('data-dismissed') === '1'; });
}
function rmUpdateSelCount() {
    var n = rmState.selCount;
    document.getElementById('rm-selcount').textContent = n;
    var bar = document.getElementById('rm-bulkbar');
    bar.hidden = n === 0;

    // Say plainly that selection covers loaded rows only while more remain.
    var partial = !!rmState.hasMore;
    document.getElementById('rm-bulklabel').textContent =
        partial ? n + ' selected (loaded rows)' : n + ' selected';

    // Undelete applies only to dismissed rows, every other verb only to live ones.
    // Disable rather than hide, so the bar does not reflow as the selection changes.
    var nDismissed = rmState.selDismissed;
    var nLive      = Math.max(0, n - nDismissed);
    document.querySelectorAll('#rm-bulkbar .rm-bulk').forEach(function (b) {
        if (b.classList.contains('rm-bulk-clear')) return;
        b.disabled = b.classList.contains('rm-bulk-undelete') ? nDismissed === 0 : nLive === 0;
    });

    var note = document.getElementById('rm-loadnote');
    if (note) {
        note.textContent = partial
            ? '— select-all covers loaded rows only'
            : '';
    }
}
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var cb = e.target.closest('.rm-rowsel'); if (!cb) return;
    var vis = rmLoadedRows();
    var row = cb.closest('tr');
    var idx = vis.indexOf(row);
    // The browser already flipped this checkbox before the click bubbled here, so
    // count it directly rather than re-scanning every loaded row.
    rmState.selCount += cb.checked ? 1 : -1;
    if (rmState.selCount < 0) rmState.selCount = 0;
    if (row.getAttribute('data-dismissed') === '1') {
        rmState.selDismissed += cb.checked ? 1 : -1;
        if (rmState.selDismissed < 0) rmState.selDismissed = 0;
    }
    if (e.shiftKey && rmLastIdx !== null) {
        var lo = Math.min(idx, rmLastIdx), hi = Math.max(idx, rmLastIdx);
        for (var i = lo; i <= hi; i++) { if (vis[i] !== row) rmSetRowChecked(vis[i], cb.checked); }
    }
    rmLastIdx = idx;
    rmUpdateSelCount();
});
document.getElementById('rm-selall').addEventListener('change', function () {
    var on = this.checked;
    rmLoadedRows().forEach(function (tr) { rmSetRowChecked(tr, on); });
    rmUpdateSelCount();
});
document.querySelector('.rm-bulk-clear').addEventListener('click', function () {
    RM.rows().forEach(function (tr) { tr.querySelector('.rm-rowsel').checked = false; });
    rmState.selCount = 0;
    rmState.selDismissed = 0;
    document.getElementById('rm-selall').checked = false;
    rmUpdateSelCount();
});
// Reflect the server-rendered first page's hasMore state in the footer immediately,
// before any selection or scroll-triggered fetch happens.
rmUpdateSelCount();

/* ---------- Task 8: snooze/dismiss (row + bulk), toast, config ---------- */
// The live region is created ONCE and lives in the DOM empty: a role="status"
// node inserted at the same moment as its text is not reliably announced, so
// every toast is appended into this pre-existing region instead.
function rmToastRegion() {
    var r = document.getElementById('rm-toast-region');
    if (!r) {
        r = document.createElement('div');
        r.id = 'rm-toast-region';
        r.setAttribute('role', 'status');
        r.setAttribute('aria-live', 'polite');
        r.setAttribute('aria-atomic', 'false');
        document.body.appendChild(r);
    }
    return r;
}
rmToastRegion();
function rmToast(msg, isErr) {
    var t = document.createElement('div');
    t.className = 'rm-toast' + (isErr ? ' rm-toast-err' : '');
    t.textContent = msg;
    rmToastRegion().appendChild(t);
    setTimeout(function () { t.classList.add('rm-toast-out'); setTimeout(function () { t.remove(); }, 400); }, 2600);
}
// Build the snooze/dismiss endpoint base for the current scope.
function rmRecAjaxBase(action) {
    if (RmConfig.context === 'park')
        return RmConfig.uir + 'ParkAjax/park/' + RmConfig.parkId + '/' + action;
    return RmConfig.uir + 'KingdomAjax/kingdom/' + RmConfig.kingdomId + '/' + action;
}
function rmPost(url, fd) {
    return fetch(url, { method: 'POST', body: fd, credentials: 'same-origin' }).then(function (r) {
        // Match rmFetch: a 403/500 returns an HTML error page, and parsing that as
        // JSON throws a SyntaxError that reads like a network blip in the catch.
        if (!r.ok) throw new Error('post http ' + r.status);
        return r.json();
    });
}
// These rec AJAX endpoints all echo {status:0} on success, {status:N,error} on failure.
function rmJsonOk(j) { return !!j && j.status === 0; }
// How many of a cluster's member writes actually landed. Partial success is real —
// the successful writes are already committed server-side — so the UI must report
// what happened instead of a flat "Failed."
// First server-supplied error in a results array, for toasts that would otherwise
// collapse a specific refusal into a bare "Failed.".
function rmFirstError(results) {
    if (!Array.isArray(results)) return '';
    for (var i = 0; i < results.length; i++) {
        var e = results[i] && results[i].error;
        if (typeof e === 'string' && e.trim() !== '') return e.replace(/^\s*:\s*/, '').trim();
    }
    return '';
}
function rmCountOk(results) {
    var n = 0;
    (results || []).forEach(function (j) { if (rmJsonOk(j)) n++; });
    return n;
}
// Does the active eligibility filter hide a row given its snoozed state?
// ('snoozed' shows only snoozed; 'all' shows both; every other bucket excludes snoozed.)
function rmEligHides(elig, isSnoozed) {
    if (elig === 'all') return false;
    if (elig === 'snoozed') return !isSnoozed;
    return !!isSnoozed;
}
// Add or remove the Award-cell "passed to local" badge (idempotent).
function rmSetPasslocalBadge(tr, passed) {
    var awardCell = tr.querySelector('.rm-col-award');
    if (!awardCell) return;
    var existing = awardCell.querySelector('.rm-badge-passlocal');
    if (passed && !existing) {
        var b = document.createElement('span');
        b.className = 'rm-badge rm-badge-passlocal';
        b.setAttribute('data-tip', 'Passed to the local park to award.');
        b.innerHTML = '<i class="fas fa-arrow-down"></i> passed to local';
        awardCell.appendChild(b);
    } else if (!passed && existing) {
        existing.remove();
    }
}

// Remove a row (and any open detail row) then re-sync filters/counts.
// serverGone defaults to true (the dismiss/grant case). Pass false when the row is
// only leaving the current filter bucket, e.g. a snooze under the 'open' filter.
function rmRemoveRow(tr, serverGone) {
    rmSetRowChecked(tr, false); // a removed row must not keep counting as selected
    var dr = tr.nextElementSibling;
    if (dr && dr.classList.contains('rm-detailrow')) dr.remove();
    tr.remove();
    rmAfterRowRemoved(serverGone !== false);
}

// Read the member rec ids for a group row.
function rmMemberIds(tr) {
    var ids = []; try { ids = JSON.parse(tr.getAttribute('data-members') || '[]'); } catch (x) {}
    return ids;
}
function rmRecOf(tr) {
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    return rec;
}
// "4 recommendations" / "1 recommendation" — a row is a CLUSTER of filings, and
// every write on it acts on all of them, so the copy must count filings.
function rmRecCount(n) { return n + ' recommendation' + (n === 1 ? '' : 's'); }
// "Sir Aldric (Order of the Flame, rank 3)" for confirm copy.
function rmRecLabel(tr) {
    var rec = rmRecOf(tr);
    var who = rec.Persona || 'this recipient';
    var what = rec.AwardName || 'this award';
    var rank = (parseInt(rec.Rank, 10) || 0);
    return who + ' (' + what + (rank > 0 ? ', rank ' + rank : '') + ')';
}
// Both dismiss paths say the same thing about what dismissal is.
var RM_DISMISS_KEPT = 'Dismissed recommendations are kept, not deleted — turn on “Show dismissed” to see them and undelete any retired by mistake.';
// The first reason in the cluster (the row no longer duplicates it in data-rec).
function rmFirstReason(tr) {
    var m = []; try { m = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
    return (m[0] && m[0].Reason) ? m[0].Reason : '';
}

// Repaint a snooze button for its new state. Both the icon AND the rich tooltip
// have to move: writing textContent on the button itself would destroy the
// .rm-snooze-ico / .rm-snooze-tip children the CSS hover tip is built from, and
// leaving the tip alone makes it describe the action the button no longer does.
// Mirrors the server-side markup in _rm_row.tpl.
function rmPaintSnooze(btn, nowSnoozed) {
    if (!btn) return;
    // The accessible name has to move with the icon and the tooltip: the tooltip
    // span is aria-hidden, so aria-label is the ONLY name this button has.
    btn.setAttribute('aria-label', nowSnoozed ? 'Unsnooze this recommendation' : 'Snooze until the next monarchy');
    var ico = btn.querySelector('.rm-snooze-ico');
    if (ico) ico.innerHTML = nowSnoozed ? '&#128276;' : '&#128164;';
    var tip = btn.querySelector('.rm-snooze-tip');
    if (tip) {
        tip.innerHTML = nowSnoozed
            ? '<strong>Unsnooze</strong>Restore this recommendation to the active list.'
            : '<strong>Snooze to Next Monarchy</strong>Temporarily dismiss this recommendation until either the Monarch or Regent officer at this level changes.';
    }
}

// Per-row Snooze + Dismiss (third tbody click handler; early-returns on non-match).
// Both loop every member rec id in the cluster.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var sn = e.target.closest('.rm-act-snooze');
    if (sn) {
        var tr = sn.closest('tr');
        var snoozed = tr.getAttribute('data-snoozed') === '1';
        var action = snoozed ? 'unsnoozerecommendation' : 'snoozerecommendation';
        var ids = rmMemberIds(tr);
        if (!ids.length) { rmToast('No recommendations found.', true); return; }
        Promise.all(ids.map(function (id) {
            var fd = new FormData(); fd.append('RecommendationsId', id);
            return rmPost(rmRecAjaxBase(action), fd);
        })).then(function (results) {
            var okN = rmCountOk(results), failN = results.length - okN;
            if (failN > 0) {
                // Some member recs may already have flipped server-side. Leave the row
                // as it is (its state is now mixed) and say exactly what landed.
                // Surface the server's reason when it gave one: a refusal like "no
                // Monarch or Regent is recorded for this scope" is actionable, and a
                // bare "Failed." reads as a dead button.
                rmToast(okN
                    ? (snoozed ? 'Unsnoozed ' : 'Snoozed ') + okN + ', ' + failN + ' failed.'
                    : (rmFirstError(results) || 'Failed.'), true);
                return;
            }
            var nowSnoozed = !snoozed;
            tr.setAttribute('data-snoozed', nowSnoozed ? '1' : '0');
            rmPaintSnooze(sn, nowSnoozed);
            // Refetch only if the toggle moves the row out of the current bucket;
            // otherwise leave it in place (cheap — no 500-row re-render).
            if (rmEligHides(rmState.elig, nowSnoozed)) rmFetch(true);
            rmToast(nowSnoozed ? 'Snoozed.' : 'Unsnoozed.');
        }).catch(function () { rmToast('Failed.', true); });
        return;
    }
    var ds = e.target.closest('.rm-act-dismiss');
    if (ds) {
        var tr2 = ds.closest('tr');
        // Count what actually gets retired: the row is a cluster and this acts on
        // every member filing, so confirming "the recommendation(s)" understated a
        // 4-recommender row by 3.
        var dsIds = rmMemberIds(tr2);
        if (!dsIds.length) { rmToast('No recommendations found.', true); return; }
        rmConfirm({
            title: 'Dismiss ' + rmRecCount(dsIds.length) + ' for ' + rmRecLabel(tr2) + '?',
            body: (dsIds.length === 1 ? 'This filing leaves' : 'All ' + dsIds.length + ' filings for this honor leave')
                + ' the pending list. ' + RM_DISMISS_KEPT,
            confirmLabel: 'Dismiss ' + rmRecCount(dsIds.length),
            danger: true, onConfirm: function () {
            var ids = dsIds;
            Promise.all(ids.map(function (id) {
                var fd = new FormData(); fd.append('RecommendationsId', id);
                return rmPost(rmRecAjaxBase('dismissrecommendation'), fd);
            })).then(function (results) {
                var okN = rmCountOk(results), failN = results.length - okN;
                if (failN > 0) {
                    // Keep the row: the ids that failed are still pending, so removing it
                    // would hide live recommendations behind a "Dismissed" that isn't true.
                    rmToast(okN ? 'Dismissed ' + rmRecCount(okN) + ', ' + failN + ' failed.' : 'Failed.', true);
                    return;
                }
                rmRemoveRow(tr2); rmToast('Dismissed ' + rmRecCount(okN) + '.');
            }).catch(function () { rmToast('Failed.', true); });
        } });
    }
});

// Per-row Undelete (rendered only on a dismissed row, in place of every other
// action). Goes through the same batch endpoint as the bulk verb so there is one
// undelete path, and reports a partial cluster restore honestly.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var ud = e.target.closest('.rm-act-undelete'); if (!ud) return;
    var tr = ud.closest('tr');
    var ids = rmMemberIds(tr);
    if (!ids.length) { rmToast('No recommendations found.', true); return; }
    ud.disabled = true;
    rmBulkRequestChunked('undelete', ids, 0).then(function (map) {
        var okN = ids.filter(function (id) { return map[String(id)] === true; }).length;
        if (okN !== ids.length) {
            ud.disabled = false;
            rmToast(okN ? 'Undeleted ' + okN + ', ' + (ids.length - okN) + ' failed.' : 'Failed.', true);
            return;
        }
        // serverGone=false: with "Show dismissed" on, the row was already part of the
        // current result set — it changes category, it does not leave the set.
        rmRemoveRow(tr, false);
        rmToast('Undeleted.');
    }).catch(function () {
        ud.disabled = false;
        rmToast('Undelete was interrupted — refresh to confirm what landed.', true);
    });
});

// Per-row Pass-to-local toggle (kingdom scope only; button only rendered there).
// Loops every member rec id in the cluster, mirroring the snooze handler.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var pl = e.target.closest('.rm-act-passlocal'); if (!pl) return;
    var tr = pl.closest('tr');
    var passed = tr.getAttribute('data-passlocal') === '1';
    var ids = rmMemberIds(tr);
    if (!ids.length) { rmToast('No recommendations found.', true); return; }
    Promise.all(ids.map(function (id) {
        var fd = new FormData(); fd.append('RecommendationsId', id); fd.append('Passed', passed ? '0' : '1');
        return rmPost(rmRecAjaxBase('passtolocalrecommendation'), fd);
    })).then(function (results) {
        var okN = rmCountOk(results), failN = results.length - okN;
        if (failN > 0) {
            // Partial: some ids already moved. Leave the toggle alone and report honestly.
            rmToast(okN ? 'Updated ' + okN + ', ' + failN + ' failed.' : 'Update failed.', true);
            return;
        }
        var nowPassed = !passed;
        tr.setAttribute('data-passlocal', nowPassed ? '1' : '0');
        pl.classList.toggle('rm-act-active', nowPassed);
        // aria-label is this button's only accessible name (its tooltip span is
        // aria-hidden), so it has to describe the action it now performs.
        pl.setAttribute('aria-label', nowPassed ? 'Remove the pass to the local park' : 'Pass to the local park');
        rmSetPasslocalBadge(tr, nowPassed);
        // Only refetch if the "Passed to local" filter is active and this row just
        // dropped out of it; otherwise the in-place badge/state update is enough.
        if (rmState.passlocal && !nowPassed) rmFetch(true);
        rmToast(nowPassed ? 'Passed to local.' : 'Pass-to-local removed.');
    }).catch(function () { rmToast('Update failed.', true); });
});

// Bulk: ONE request for the whole action.
//
// This used to fan out N x M POSTs — one per member rec id of every selected row —
// so dismissing 40 clustered rows meant hundreds of round trips. Recommendations/bulk
// takes every id at once and answers with a per-id results array, which is also what
// lets us give each row an individual verdict instead of an all-or-nothing one.
//
// Contract: POST RmConfig.bulkUrl with Action (dismiss|snooze|unsnooze|passlocal),
// Ids (comma-separated) and, for passlocal only, Passed (0|1). Response:
// { status:0, ok:N, failed:N, results:[{id, ok, error}, ...] }; 403 on a scope failure.
function rmBulkRequest(action, ids, passed) {
    var fd = new FormData();
    fd.append('Action', action);
    fd.append('Ids', ids.join(','));
    if (action === 'passlocal') fd.append('Passed', passed ? '1' : '0');
    return rmPost(RmConfig.bulkUrl, fd).then(function (j) {
        if (!rmJsonOk(j) || !Array.isArray(j.results)) throw new Error('bulk failed');
        var map = {};
        j.results.forEach(function (r) { map[String(r.id)] = !!r.ok; });
        // Carried on the map (non-numeric key, so it cannot collide with an id) so a
        // uniform server refusal survives to the toast instead of becoming "N failed".
        map._firstError = rmFirstError(j.results);
        return map;
    });
}
// Ids per request. The endpoint caps a batch at 1000 ids and does one synchronous
// model write per id, so a select-all over clustered rows could both blow past the cap
// (ids over it come back with no result and would be misreported as failures) and run
// long enough to hit max_execution_time. Chunks are issued SEQUENTIALLY on purpose:
// the point is to bound the server work in flight, which parallel chunks would defeat.
var RM_BULK_CHUNK = 150;
// Run the id list as sequential rmBulkRequest calls and merge the per-id maps.
function rmBulkRequestChunked(action, ids, passed) {
    var map = {}, i = 0;
    function step() {
        if (i >= ids.length) return Promise.resolve(map);
        var slice = ids.slice(i, i + RM_BULK_CHUNK);
        i += RM_BULK_CHUNK;
        return rmBulkRequest(action, slice, passed).then(function (part) {
            for (var k in part) {
                if (!Object.prototype.hasOwnProperty.call(part, k)) continue;
                // Keep the FIRST reason seen: a later clean chunk must not blank it.
                if (k === '_firstError') {
                    if (!map._firstError && part._firstError) map._firstError = part._firstError;
                    continue;
                }
                map[k] = part[k];
            }
            return step();
        });
    }
    return step();
}
// Enable/disable the bulk bar buttons while a batch is running.
// Clear stays live: abandoning a selection mid-batch is always safe, and locking the
// only escape hatch during a multi-second request is the wrong trade.
function rmBulkBusy(on) {
    rmState.bulking = !!on;
    document.querySelectorAll('#rm-bulkbar .rm-bulk').forEach(function (b) {
        if (b.classList.contains('rm-bulk-clear')) return;
        b.disabled = !!on;
    });
}
// Settle each selected row from the per-id results: a row whose member ids ALL
// succeeded is applied in place (removed / repainted) and deselected; a row with any
// failure stays put and stays selected so the officer can retry just those. The toast
// reports both tallies — it never claims total failure when some writes landed.
//
// opts.apply(tr) performs the in-place DOM mutation — removing the row itself when the
// change pushes it out of the current eligibility bucket. Nothing here refetches: the
// whole point of mutating in place is to keep every infinitely-scrolled page the
// officer has already loaded, which the old rmFetch(true) threw away on every action.
function rmBulkRun(action, rows, opts) {
    if (rmState.bulking) return; // a batch is already running
    var ids = [];
    var rowIds = rows.map(function (tr) { var m = rmMemberIds(tr); ids = ids.concat(m); return m; });
    if (!rows.length || !ids.length) { rmToast('No recommendations found.', true); return; }
    rmBulkBusy(true);
    rmBulkRequestChunked(action, ids, opts.passed).then(function (map) {
        var okRows = 0, failRows = 0;
        rows.forEach(function (tr, n) {
            var mine = rowIds[n];
            var allOk = mine.length > 0 && mine.every(function (id) { return map[String(id)] === true; });
            if (!allOk) { failRows++; return; }
            okRows++;
            rmSetRowChecked(tr, false);
            opts.apply(tr);
        });
        rmUpdateSelCount();
        // When NOTHING landed the tally alone is useless ("0 done, 12 failed"), and a
        // uniform server refusal is exactly the case an officer can act on — so lead
        // with the server's reason and keep the tally as context.
        var reason = (okRows === 0 && failRows > 0) ? map._firstError : '';
        rmToast(reason ? reason + ' (' + failRows + ' not changed.)' : opts.msg(okRows, failRows), failRows > 0);
        rmBulkBusy(false);
    // A throw here means one chunk failed (403, transport, timeout, malformed body).
    // Earlier chunks — and possibly part of this one — are already committed, so we
    // must NOT claim nothing happened. Resync from the server and say the outcome of
    // the interrupted batch is unknown.
    }).catch(function () {
        rmBulkBusy(false);
        rmToast('Bulk update was interrupted — some changes may have been saved. Refresh to confirm what landed.', true);
        // rmFetch early-returns while another fetch is in flight, so this resync is
        // best-effort; the toast tells the officer to refresh either way rather than
        // promising a refresh that may never run.
        rmFetch(true);
    });
}
document.querySelector('.rm-bulk-snooze').addEventListener('click', function () {
    var rows = rmSelectedLive().filter(function (tr) { return tr.getAttribute('data-snoozed') !== '1'; });
    rmBulkRun('snooze', rows, {
        apply: function (tr) {
            // A snoozed row leaves every bucket but 'all'/'snoozed'. Drop it in place
            // rather than refetching, which would discard the loaded pages and the
            // still-selected failed rows.
            // serverGone=false: snooze does not remove the cluster from the paging
            // query's result set, so total and offset must not move.
            if (rmEligHides(rmState.elig, true)) { rmRemoveRow(tr, false); return; }
            tr.setAttribute('data-snoozed', '1');
            rmPaintSnooze(tr.querySelector('.rm-act-snooze'), true);
        },
        msg: function (ok, fail) { return 'Snoozed ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; }
    });
});
// Bulk Pass down (kingdom scope only; button absent in park scope).
(function () {
    var btn = document.querySelector('.rm-bulk-passlocal'); if (!btn) return;
    btn.addEventListener('click', function () {
        var rows = rmSelectedLive().filter(function (tr) { return tr.getAttribute('data-passlocal') !== '1'; });
        rmBulkRun('passlocal', rows, {
            passed: true,
            apply: function (tr) {
                tr.setAttribute('data-passlocal', '1');
                var pl = tr.querySelector('.rm-act-passlocal');
                if (pl) { pl.classList.add('rm-act-active'); pl.setAttribute('aria-label', 'Remove the pass to the local park'); }
                rmSetPasslocalBadge(tr, true);
                // A newly-passed row still satisfies the "Passed to local" filter, so
                // it stays right where it is.
            },
            msg: function (ok, fail) { return 'Passed ' + ok + (fail ? ', ' + fail + ' failed' : '') + ' to local.'; }
        });
    });
})();
document.querySelector('.rm-bulk-dismiss').addEventListener('click', function () {
    var rows = rmSelectedLive();
    // Selected rows are clusters; the write goes to every member filing. Ten
    // rows averaging four recommenders retires forty people's recommendations,
    // so confirm (and later report) in filings, with the honor count as context.
    var memberTotal = rows.reduce(function (n, tr) { return n + rmMemberIds(tr).length; }, 0);
    if (!memberTotal) { rmToast('No recommendations found.', true); return; }
    rmConfirm({
        title: 'Dismiss ' + rmRecCount(memberTotal) + ' across ' + rows.length + ' honor' + (rows.length === 1 ? '' : 's') + '?',
        body: 'Every filing on the selected ' + (rows.length === 1 ? 'row' : 'rows') + ' leaves the pending list. ' + RM_DISMISS_KEPT,
        confirmLabel: 'Dismiss ' + rmRecCount(memberTotal),
        danger: true, onConfirm: function () {
        // apply() runs once per row that fully succeeded, before msg() is called,
        // so this is the exact filing count that landed — not an assumption that
        // the successful rows were the first N.
        var doneFilings = 0;
        rmBulkRun('dismiss', rows, {
            // rmRemoveRow keeps the "Showing X of Y" counters in step for each row.
            apply: function (tr) { doneFilings += rmMemberIds(tr).length; rmRemoveRow(tr); },
            // ok/fail are ROW tallies; report the filings those rows carried.
            msg: function (ok, fail) {
                return 'Dismissed ' + rmRecCount(doneFilings) + ' across ' + ok + ' honor' + (ok === 1 ? '' : 's')
                    + (fail ? ', ' + fail + ' honor' + (fail === 1 ? '' : 's') + ' failed' : '') + '.';
            }
        });
    } });
});

/* ---------- Task 9: Grant Award (modal — never insta-grants) ---------- */
// Today's date as YYYY-MM-DD (the format add_player_award expects).
function rmTodayYMD() {
    var d = new Date();
    function p(n) { return (n < 10 ? '0' : '') + n; }
    return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate());
}

// Core grant: write the award via the JSON grantaward endpoint, then resolve the
// whole cluster + drop the row.
//
// Court lines are reconciled SERVER-side inside grantaward (S1 cross-path reconcile),
// driven by the CourtAction we send here. The client must NOT also call
// CourtAjax/remove_award or set_award_status afterwards: the reconcile has already
// moved those lines out of the open set, so the guards on those endpoints (which
// correctly refuse to touch a 'given' row) would reject the follow-up call and abort
// the chain before the cluster is ever resolved.
//
// opts = { date, givenById, note, courtAction }. Resolves on full success; rejects
// with an Error carrying `.granted` (did the award itself land?) so the caller can
// tell a real grant failure from a post-grant resolve failure.
function rmDoGrant(rec, tr, opts) {
    opts = opts || {};
    var granted = false;
    // Rebuildable: a confirmed duplicate is re-POSTed with ConfirmDuplicate=1, and a
    // FormData that has already been sent cannot be reused safely.
    function rmGrantForm(confirmDuplicate) {
        var fd = new FormData();
        fd.append('KingdomAwardId', rec.KingdomAwardId);
        fd.append('GivenById', opts.givenById || RmConfig.userId);
        fd.append('Date', opts.date || rmTodayYMD());
        fd.append('ParkId', opts.parkId != null ? opts.parkId : (RmConfig.parkId || '0'));
        fd.append('KingdomId', opts.kingdomId != null ? opts.kingdomId : (RmConfig.kingdomId || '0'));
        fd.append('EventId', opts.eventId != null ? opts.eventId : '0');
        fd.append('Note', opts.note != null ? opts.note : (rec.Reason || ''));
        fd.append('Rank', opts.rank != null ? opts.rank : (rec.Rank || 0));
        // Thread the granted recommendation id so the server can reconcile the matching
        // court line and a later court finalize can't double-grant it.
        var recId = (opts.recommendationsId != null) ? opts.recommendationsId : rec.RepRecId;
        if (recId) { fd.append('RecommendationsId', recId); }
        // 'remove' cancels the court line, 'leave' marks it given. Server-side only.
        fd.append('CourtAction', opts.courtAction === 'remove' ? 'remove' : 'leave');
        if (confirmDuplicate) { fd.append('ConfirmDuplicate', '1'); }
        return fd;
    }
    var grantUrl = RmConfig.uir + 'PlayerAjax/player/' + rec.MundaneId + '/grantaward';
    // PlayerAjax::grantaward runs a ledger duplicate probe (ledgerDuplicateAwardDate)
    // BEFORE the write. As merged the server settled on ADVISORY-ONLY: the grant always
    // goes through and the hit comes back on the normal status:0 payload as
    // {duplicateDate:'Y-m-d', duplicateWarning:'…'} — there is no ConfirmDuplicate flag
    // server-side today. That branch is handled after rmJsonOk below (toast, no block).
    // The blocking shape is kept as a defensive second contract: if the probe is ever
    // moved to {status:'duplicate'} / {duplicate:true} + ConfirmDuplicate=1, rmJsonOk()
    // (status===0 only) would read it as a hard failure and the officer would be told
    // "already recorded" with no way to say yes. Recognise either flag alone, since the
    // server could settle on one.
    function rmIsDuplicate(j) { return !!j && (j.status === 'duplicate' || j.duplicate === true); }
    return rmPost(grantUrl, rmGrantForm(false))
        .then(function (j) {
            if (!rmIsDuplicate(j)) { return j; }
            var when = j.duplicateDate ? rmNiceDateJS(j.duplicateDate) : '';
            return rmConfirm({
                title: 'Already recorded',
                body: 'This honor is already recorded' + (when ? ' on ' + when : '') + '. Grant it again?',
                confirmLabel: 'Grant again',
                cancelLabel: 'Cancel'
            }).then(function (ok) {
                if (!ok) { var c = new Error('Cancelled — nothing was granted.'); c.granted = false; c.cancelled = true; throw c; }
                return rmPost(grantUrl, rmGrantForm(true));
            });
        })
        .then(function (j) {
            if (!rmJsonOk(j)) { var e = new Error(j && j.error ? j.error : 'Could not grant the award.'); e.granted = false; throw e; }
            granted = true;
            // Advisory shape: if the server instead lets the grant through and reports
            // the duplicate as a warning, surface it rather than blocking anything.
            if (j.duplicateWarning) { rmToast(j.duplicateWarning, true); }
            else if (j.duplicate && j.duplicateDate) { rmToast('Note: this honor was already recorded on ' + rmNiceDateJS(j.duplicateDate) + '.', true); }
            var fd2 = new FormData();
            fd2.append('MundaneId', rec.MundaneId);
            fd2.append('KingdomAwardId', rec.KingdomAwardId);
            fd2.append('Rank', rec.Rank || 0);
            return rmPost(rmRecAjaxBase('resolverecommendationcluster'), fd2);
        }).then(function (j) {
            if (!rmJsonOk(j)) { var e = new Error('resolve cluster failed'); e.granted = true; throw e; }
            rmRemoveRow(tr);
            rmToast('Granted.');
        }).catch(function (err) {
            err.granted = (typeof err.granted === 'boolean') ? err.granted : granted;
            throw err;
        });
}

/* ----- Grant Award modal ----- */
var rmGrantCtx = null; // { rec, tr, courts }
var rmGrantRelease = null; // focus-trap release fn while the Grant modal is open
function rmGid(id) { return document.getElementById(id); }
// Y-m-d -> "March 4, 2026". Parsed by parts, never `new Date('2026-03-04')`,
// which is read as UTC and slips a day in western timezones.
function rmNiceDateJS(ymd) {
    var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(ymd || ''));
    if (!m || m[1] === '0000') return String(ymd || '');
    var names = ['January','February','March','April','May','June','July','August','September','October','November','December'];
    var mo = parseInt(m[2], 10);
    if (mo < 1 || mo > 12) return String(ymd);
    return names[mo - 1] + ' ' + parseInt(m[3], 10) + ', ' + m[1];
}
// The Grant date field is a flatpickr with altInput — the officer reads
// "March 4, 2026" while the posted value stays Y-m-d for add_player_award (the
// same init the Add-to-Court date field already had). If the CDN is unreachable
// the markup's own type=date is left alone, so the field still picks a date.
var rmGrantFp = null;
(function () {
    var el = document.getElementById('rm-grant-date');
    if (!el || typeof flatpickr === 'undefined') return;
    rmGrantFp = flatpickr(el, { altInput: true, altFormat: 'F j, Y', dateFormat: 'Y-m-d' });
    // altInput does not inherit the id the <label for> points at.
    if (rmGrantFp.altInput) rmGrantFp.altInput.setAttribute('aria-label', 'Date');
})();
function rmSetGrantDate(ymd) {
    if (rmGrantFp) { rmGrantFp.setDate(ymd, false); return; }
    rmGid('rm-grant-date').value = ymd;
}
function rmGrantErr(msg) {
    var box = rmGid('rm-grant-error');
    if (!msg) { box.hidden = true; box.textContent = ''; return; }
    box.textContent = msg; box.hidden = false;
}
function rmOpenGrantModal(rec, tr, courts) {
    rmGrantCtx = { rec: rec, tr: tr, courts: (courts && courts.length) ? courts : [] };
    var rankTxt = rec.Rank ? (' — Rank ' + rec.Rank) : '';
    // data-rec carries IsRetired so the signal reaches the MOMENT OF THE ACTION, not
    // just the row badge the officer scrolled past. Memorial honors are legitimate —
    // this states the fact, it does not block.
    rmGid('rm-grant-sub').textContent = 'Grant ' + (rec.AwardName || 'this award') + rankTxt + ' to “' + (rec.Persona || '') + '”.'
        + (rec.IsRetired ? ' This recipient is retired or deceased in the ORK — confirm this is an intended memorial honor.' : '');
    rmGrantErr('');
    rmSetGrantDate(rmTodayYMD());
    rmGid('rm-grant-givenby').value = RmConfig.userName || '';
    rmGid('rm-grant-givenby-id').value = RmConfig.userId || '';
    rmGid('rm-grant-note').value = rec.Reason || '';
    rmBuildRankPills(rec);
    var chipsEl = rmGid('rm-grant-officer-chips');
    if (chipsEl) chipsEl.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
    rmGid('rm-grant-givenat').value = RmConfig.locationName || '';
    rmGid('rm-grant-park-id').value = RmConfig.parkId || '0';
    rmGid('rm-grant-kingdom-id').value = RmConfig.kingdomId || '0';
    rmGid('rm-grant-event-id').value = '0';
    var gaRes = rmGid('rm-grant-givenat-results'); if (gaRes) { gaRes.classList.remove('rm-ac-open'); gaRes.innerHTML = ''; }
    var courtWrap = rmGid('rm-grant-court-wrap');
    if (rmGrantCtx.courts.length) {
        // rmNiceDateJS, not the raw Y-m-d: this label sat directly above a court
        // <select> rendering the very same date as "March 4, 2026".
        var names = rmGrantCtx.courts.map(function (c) { return c.Name + (c.CourtDate ? ' (' + rmNiceDateJS(c.CourtDate) + ')' : ''); }).join(', ');
        rmGid('rm-grant-court-label').textContent = 'Already on ' + (rmGrantCtx.courts.length === 1 ? 'court: ' : rmGrantCtx.courts.length + ' courts: ') + names;
        var rm = document.querySelector('input[name="rm-grant-court"][value="remove"]'); if (rm) rm.checked = true;
        courtWrap.hidden = false;
    } else {
        courtWrap.hidden = true;
    }
    var results = rmGid('rm-grant-givenby-results'); if (results) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; }
    rmGid('rm-grant-submit').disabled = false;
    rmGid('rm-grant-overlay').hidden = false;
    // Escape, Tab wrapping and focus restore for this overlay. Without it, Tab
    // past Submit walked into the 362-row table behind the modal and closing
    // dropped focus on <body> at the top of the list.
    rmGrantRelease = rmDialogTrap(rmGid('rm-grant-overlay'), {
        // Escape closes an open autocomplete dropdown first; only a second
        // Escape (with no dropdown open) closes the modal itself.
        onEscape: function () {
            var open = rmGid('rm-grant-overlay').querySelectorAll('.rm-ac-results.rm-ac-open');
            if (open.length) { Array.prototype.forEach.call(open, function (n) { n.classList.remove('rm-ac-open'); }); return; }
            rmCloseGrantModal();
        },
        // With flatpickr's altInput the real <input> is hidden and cannot take focus.
        initialFocus: (rmGrantFp && rmGrantFp.altInput) ? rmGrantFp.altInput : rmGid('rm-grant-date')
    });
}
function rmCloseGrantModal() {
    rmGid('rm-grant-overlay').hidden = true;
    var results = rmGid('rm-grant-givenby-results'); if (results) results.classList.remove('rm-ac-open');
    if (rmGrantRelease) { rmGrantRelease(); rmGrantRelease = null; } // restores focus to the invoking button
    rmGrantCtx = null;
}
// The row's lightning-bolt always opens the modal (pre-filled). Never insta-grant.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var g = e.target.closest('.rm-act-grant'); if (!g) return;
    var tr = g.closest('tr');
    var rec = rmRecOf(tr);
    // The reason is no longer duplicated into data-rec (it is already in
    // data-membersfull on every row); read it at click time instead.
    rec.Reason = rmFirstReason(tr);
    var courts = []; try { courts = JSON.parse(tr.getAttribute('data-courts') || '[]'); } catch (x) {}
    rmOpenGrantModal(rec, tr, courts);
});
rmGid('rm-grant-cancel').addEventListener('click', rmCloseGrantModal);
rmGid('rm-grant-overlay').addEventListener('click', function (e) { if (e.target === this) rmCloseGrantModal(); });
rmGid('rm-grant-submit').addEventListener('click', function () {
    if (!rmGrantCtx) return;
    var ctx = rmGrantCtx;
    var date = rmGid('rm-grant-date').value;
    var givenById = rmGid('rm-grant-givenby-id').value;
    var note = rmGid('rm-grant-note').value;
    if (!date) { rmGrantErr('Please choose a date.'); return; }
    if (!givenById) { rmGrantErr('Please choose who granted this award (pick from the search).'); return; }
    rmGrantErr('');
    var submitBtn = rmGid('rm-grant-submit');
    submitBtn.disabled = true;
    // The court lines are reconciled server-side by grantaward; we only forward the
    // officer's choice. Issuing our own CourtAjax calls here would hit the given-row
    // guards and abort the chain before the recommendation is resolved.
    var courtAction = ctx.courts.length
        ? ((document.querySelector('input[name="rm-grant-court"]:checked') || {}).value || 'remove')
        : 'leave';
    var rankVal = rmGid('rm-grant-rank-val').value;
    rmDoGrant(ctx.rec, ctx.tr, {
        date: date, givenById: givenById, note: note, courtAction: courtAction,
        rank: (rankVal !== '' ? rankVal : (ctx.rec.Rank || 0)),
        parkId: rmGid('rm-grant-park-id').value || '0',
        kingdomId: rmGid('rm-grant-kingdom-id').value || '0',
        eventId: rmGid('rm-grant-event-id').value || '0'
    })
        .then(function () { rmCloseGrantModal(); })
        .catch(function (err) {
            if (err && err.cancelled) {
                // Declined the duplicate confirmation: nothing was written, the modal
                // stays open with the officer's inputs so they can change the date.
                submitBtn.disabled = false;
                rmGrantErr('');
                return;
            }
            if (err && err.granted) {
                // The award row landed. Player::AddAward has no duplicate guard, so a
                // retry would write a SECOND permanent award — retire the row here
                // rather than leaving a live Grant button on an already-granted rec.
                rmRemoveRow(ctx.tr);
                rmCloseGrantModal();
                rmToast('Granted — but the recommendation could not be cleared. Refresh to confirm; do not grant it again.', true);
            } else {
                submitBtn.disabled = false;
                rmGrantErr((err && err.message) ? err.message : 'Grant failed.');
            }
        });
});
/* Rank pills — replicate the award modal's rank selector (green = already held). */
function rmRankPaint(wrap, held, selected) {
    held = parseInt(held, 10) || 0; selected = parseInt(selected, 10) || 0;
    wrap.querySelectorAll('.rm-rank-pill').forEach(function (pill) {
        var r = parseInt(pill.dataset.rank, 10);
        pill.classList.remove('rm-rank-held', 'rm-rank-forward', 'rm-rank-selected');
        if (r <= held) pill.classList.add('rm-rank-held');
        else if (r <= selected) pill.classList.add('rm-rank-forward');
        if (r === selected) pill.classList.add('rm-rank-selected');
    });
}
function rmBuildRankPills(rec) {
    var wrap = rmGid('rm-grant-rank-pills'), row = rmGid('rm-grant-rank-wrap'), input = rmGid('rm-grant-rank-val');
    wrap.innerHTML = ''; input.value = '';
    var recRank = parseInt(rec.Rank, 10) || 0;
    if (recRank <= 0) { row.hidden = true; return; } // non-ladder award → no rank selector
    row.hidden = false;
    var maxRank = /zodiac/i.test(rec.AwardName || '') ? 12 : 10;
    var held = parseInt(rec.HeldRank, 10) || 0;
    var selected = Math.min(Math.max(recRank, 1), maxRank);
    wrap.dataset.held = held;
    for (var r = 1; r <= maxRank; r++) {
        var pill = document.createElement('div');
        pill.className = 'rm-rank-pill'; pill.dataset.rank = r;
        pill.innerHTML = '<span class="rm-rank-num">' + r + '</span>';
        wrap.appendChild(pill);
    }
    rmRankPaint(wrap, held, selected);
    input.value = selected;
}
rmGid('rm-grant-rank-pills').addEventListener('click', function (e) {
    var pill = e.target.closest ? e.target.closest('.rm-rank-pill') : null;
    if (!pill) return;
    rmGid('rm-grant-rank-val').value = pill.dataset.rank;
    rmRankPaint(this, this.dataset.held, pill.dataset.rank);
});
// Officer quick-pick chips → fill Given By.
(function () {
    var chipsEl = rmGid('rm-grant-officer-chips');
    if (!chipsEl) return;
    chipsEl.addEventListener('click', function (e) {
        var chip = e.target.closest ? e.target.closest('.rm-officer-chip') : null;
        if (!chip) return;
        chipsEl.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
        chip.classList.add('rm-selected');
        rmGid('rm-grant-givenby').value = chip.getAttribute('data-name');
        rmGid('rm-grant-givenby-id').value = chip.getAttribute('data-id');
        var res = rmGid('rm-grant-givenby-results'); if (res) res.classList.remove('rm-ac-open');
    });
})();
// Given At: location search (park / kingdom / event) → sets ParkId/KingdomId/EventId.
(function () {
    var input = rmGid('rm-grant-givenat'), results = rmGid('rm-grant-givenat-results');
    if (!input || !results || !RmConfig.httpService) return;
    var timer = null;
    input.addEventListener('input', function () {
        rmGid('rm-grant-park-id').value = '0'; rmGid('rm-grant-kingdom-id').value = '0'; rmGid('rm-grant-event-id').value = '0';
        var term = input.value.trim(); clearTimeout(timer);
        if (term.length < 2) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            var today = new Date().toISOString().slice(0, 10);
            var url = RmConfig.httpService + 'Search/SearchService.php?Action=Search%2FLocation&name=' + encodeURIComponent(term) + '&date=' + today + '&limit=8';
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.length) { results.innerHTML = '<div class="rm-ac-none">No locations found</div>'; results.classList.add('rm-ac-open'); return; }
                results.innerHTML = data.map(function (loc) {
                    return '<div class="rm-ac-item" data-park="' + (parseInt(loc.ParkId) || 0) + '" data-kingdom="' + (parseInt(loc.KingdomId) || 0) + '" data-event="' + (parseInt(loc.EventId) || 0) + '" data-name="' + encodeURIComponent(loc.ShortName || loc.LocationName || '') + '">' + rmEsc(loc.LocationName || '') + '</div>';
                }).join('');
                results.classList.add('rm-ac-open');
            }).catch(function () { results.classList.remove('rm-ac-open'); });
        }, 220);
    });
    results.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.rm-ac-item') : null;
        if (!item) return;
        input.value = decodeURIComponent(item.getAttribute('data-name'));
        rmGid('rm-grant-park-id').value = item.getAttribute('data-park') || '0';
        rmGid('rm-grant-kingdom-id').value = item.getAttribute('data-kingdom') || '0';
        rmGid('rm-grant-event-id').value = item.getAttribute('data-event') || '0';
        results.classList.remove('rm-ac-open');
    });
    document.addEventListener('click', function (e) { if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('rm-ac-open'); });
})();
// Given-By player search: global giver scope (intentional), custom dropdown (not jQuery UI).
(function () {
    var input = rmGid('rm-grant-givenby');
    var hidden = rmGid('rm-grant-givenby-id');
    var results = rmGid('rm-grant-givenby-results');
    if (!input || !results) return;
    var timer = null;
    input.addEventListener('input', function () {
        hidden.value = ''; // typing invalidates the previous pick until one is reselected
        var _chips = rmGid('rm-grant-officer-chips'); if (_chips) _chips.querySelectorAll('.rm-officer-chip').forEach(function (c) { c.classList.remove('rm-selected'); });
        var term = input.value.trim();
        clearTimeout(timer);
        if (term.length < 2) { results.classList.remove('rm-ac-open'); results.innerHTML = ''; return; }
        timer = setTimeout(function () {
            var url = RmConfig.uir + 'KingdomAjax/playersearch/' + (RmConfig.kingdomId || 0) + '&scope=all&include_inactive=1&include_suspended=1&q=' + encodeURIComponent(term);
            fetch(url, { credentials: 'same-origin' }).then(function (r) { return r.json(); }).then(function (data) {
                if (!data || !data.length) { results.innerHTML = '<div class="rm-ac-none">No players found</div>'; results.classList.add('rm-ac-open'); return; }
                results.innerHTML = data.map(function (pl) {
                    return '<div class="rm-ac-item" tabindex="-1" data-id="' + pl.MundaneId + '" data-name="' + encodeURIComponent(pl.Persona || '') + '">' +
                        rmEsc(pl.Persona || '') +
                        ' <span style="color:var(--rm-muted);font-size:11px">(' + rmEsc(pl.KAbbr || '') + ':' + rmEsc(pl.PAbbr || '') + ')</span>' +
                        (pl.Suspended ? ' <span style="color:var(--rm-danger);font-size:10px;font-weight:600">(Banned)</span>' : '') +
                        '</div>';
                }).join('');
                results.classList.add('rm-ac-open');
            }).catch(function () { results.classList.remove('rm-ac-open'); });
        }, 220);
    });
    results.addEventListener('click', function (e) {
        var item = e.target.closest ? e.target.closest('.rm-ac-item') : null;
        if (!item) return;
        input.value = decodeURIComponent(item.getAttribute('data-name'));
        hidden.value = item.getAttribute('data-id');
        results.classList.remove('rm-ac-open');
    });
    document.addEventListener('click', function (e) {
        if (!input.contains(e.target) && !results.contains(e.target)) results.classList.remove('rm-ac-open');
    });
    // Keyboard navigation. This used to be `if (typeof acKeyNav === 'function')`,
    // but acKeyNav is defined only in revised.js and this page renders under
    // default.theme (which loads orkui.js) — so the guard was always false and
    // arrow/Enter navigation never worked for anyone. Local handler instead.
    function rmAcItems() { return Array.prototype.slice.call(results.querySelectorAll('.rm-ac-item')); }
    function rmAcMove(delta) {
        var items = rmAcItems();
        if (!items.length) return;
        var cur = items.findIndex(function (n) { return n.classList.contains('rm-ac-active'); });
        var next = cur < 0 ? (delta > 0 ? 0 : items.length - 1) : (cur + delta + items.length) % items.length;
        items.forEach(function (n) { n.classList.remove('rm-ac-active'); });
        items[next].classList.add('rm-ac-active');
        if (items[next].scrollIntoView) items[next].scrollIntoView({ block: 'nearest' });
    }
    input.addEventListener('keydown', function (e) {
        var open = results.classList.contains('rm-ac-open');
        if (e.key === 'ArrowDown') { if (open) { e.preventDefault(); rmAcMove(1); } return; }
        if (e.key === 'ArrowUp')   { if (open) { e.preventDefault(); rmAcMove(-1); } return; }
        // Escape is handled by the modal's shared trap, which closes an open
        // dropdown first and the modal only when none is open.
        if (e.key === 'Enter' && open) {
            var act = results.querySelector('.rm-ac-item.rm-ac-active');
            if (act) { e.preventDefault(); act.click(); }
        }
    });
    // Typing rebuilds the list, so the old highlight must not survive it.
    input.addEventListener('input', function () {
        rmAcItems().forEach(function (n) { n.classList.remove('rm-ac-active'); });
    });
})();

/* ---------- Task 10: Add to Court modal (single + bulk) ---------- */
var rmCourtTargets = []; // array of rec payloads (each with ._tr) to add

var rmCourtRelease = null; // focus-trap release fn while the Add-to-Court modal is open
function rmOpenCourtModal(targets) {
    rmCourtTargets = targets;
    // Same retired signal as the Grant modal: staging a memorial honor on a live
    // court agenda is legitimate, but the officer must be told at the moment of it.
    var retired = targets.filter(function (t) { return t && t.IsRetired; });
    var retTxt = !retired.length ? ''
        : (retired.length === 1
            ? ' “' + (retired[0].Persona || 'One recipient') + '” is retired or deceased in the ORK — confirm this is an intended memorial honor.'
            : ' ' + retired.length + ' of these recipients are retired or deceased in the ORK — confirm these are intended memorial honors.');
    document.getElementById('rm-court-sub').textContent = (targets.length === 1
        ? 'Adding 1 recommendation.' : 'Adding ' + targets.length + ' recommendations.') + retTxt;
    document.getElementById('rm-court-overlay').hidden = false;
    // Same shared trap as the Grant modal: Escape, Tab wrapping, focus restore.
    rmCourtRelease = rmDialogTrap(document.getElementById('rm-court-overlay'), { onEscape: rmCloseCourtModal });
}
function rmCloseCourtModal() {
    document.getElementById('rm-court-overlay').hidden = true;
    if (rmCourtRelease) { rmCourtRelease(); rmCourtRelease = null; }
}
document.getElementById('rm-court-cancel').addEventListener('click', rmCloseCourtModal);
document.getElementById('rm-court-overlay').addEventListener('click', function (e) {
    if (e.target === this) rmCloseCourtModal(); // click backdrop closes
});
document.querySelectorAll('input[name="rm-court-mode"]').forEach(function (r) {
    r.addEventListener('change', function () {
        var isNew = document.querySelector('input[name="rm-court-mode"]:checked').value === 'new';
        document.getElementById('rm-court-new').hidden = !isNew;
        document.getElementById('rm-court-existing').hidden = isNew;
    });
});

// flatpickr is loaded at the top of this template, so this init is live: the officer
// sees a readable date ("March 3, 2026") while the posted value stays Y-m-d, which is
// what CourtAjax/create_court expects for CourtDate. The guard stays as a safety net
// in case the CDN is unreachable.
if (typeof flatpickr !== 'undefined') {
    flatpickr('#rm-court-date', { altInput: true, altFormat: 'F j, Y', dateFormat: 'Y-m-d' });
}

// Per-row opener. One court award per group, keyed on the group's representative rec.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var c = e.target.closest('.rm-act-court'); if (!c) return;
    var tr = c.closest('tr');
    var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
    rec.RecommendationsId = rec.RepRecId;
    rec._tr = tr;
    rmOpenCourtModal([rec]);
});
// Bulk Undelete — only ever runs on the dismissed half of the selection, so a
// mixed selection restores what it can and leaves live rows alone.
document.querySelector('.rm-bulk-undelete').addEventListener('click', function () {
    var rows = rmSelectedDismissed();
    rmBulkRun('undelete', rows, {
        // The row rejoins the live list. serverGone=false: with "Show dismissed" on
        // it was already inside the current result set, so total must not move.
        apply: function (tr) { rmRemoveRow(tr, false); },
        msg: function (ok, fail) { return 'Undeleted ' + ok + (fail ? ', ' + fail + ' failed' : '') + '.'; }
    });
});
// Bulk opener.
document.querySelector('.rm-bulk-court').addEventListener('click', function () {
    var targets = rmSelectedLive().map(function (tr) {
        var rec = {}; try { rec = JSON.parse(tr.getAttribute('data-rec') || '{}'); } catch (x) {}
        rec.RecommendationsId = rec.RepRecId;
        rec._tr = tr; return rec;
    });
    if (targets.length) rmOpenCourtModal(targets);
});

// Mobile card overflow toggle (spec 0.8) — reveals the snooze/pass-down/dismiss
// actions plus their hover-only explanations, which have no hover on a phone.
document.getElementById('rm-tbody').addEventListener('click', function (e) {
    var more = e.target.closest('.rm-act-more');
    if (!more) return;
    var cell = more.closest('.rm-col-act');
    var open = cell.classList.toggle('rm-act-open');
    more.setAttribute('aria-expanded', open ? 'true' : 'false');
    var help = cell.querySelector('.rm-act-help');
    if (help) help.hidden = !open;
});

// Refresh the Court cell badge link after a rec is added to a court.
function rmUpdateCourtBadge(tr, courts) {
    var td = tr.querySelector('.rm-col-court');
    if (!courts.length) { td.innerHTML = '<span class="rm-empty">&mdash;</span>'; return; }
    var more = courts.length > 1 ? ' <span class="rm-courtmore">+' + (courts.length - 1) + '</span>' : '';
    td.innerHTML = '<a class="rm-courtbadge" href="' + RmConfig.uir + 'Court/detail/' + courts[0].CourtId + '">' + rmEsc(courts[0].Name) + more + '</a>';
}

// Submit: resolve a court id (create new, or use selected existing), then add_award per target.
document.getElementById('rm-court-submit').addEventListener('click', function () {
    var mode = document.querySelector('input[name="rm-court-mode"]:checked').value;
    var btn = this; btn.disabled = true;
    function withCourtId(cb) {
        if (mode === 'new') {
            var name = (document.getElementById('rm-court-name').value || '').trim();
            if (!name) { rmToast('Enter a court name.', true); btn.disabled = false; return; }
            var fd = new FormData();
            fd.append('KingdomId', RmConfig.kingdomId);
            fd.append('ParkId', RmConfig.parkId || '0');
            fd.append('Name', name);
            fd.append('CourtDate', (document.getElementById('rm-court-date').value || '').trim());
            fd.append('EventCalendarDetailId', '0');
            rmPost(RmConfig.uir + 'CourtAjax/create_court', fd).then(function (j) {
                if (j.status === 0 && j.court_id) cb(j.court_id, j.name);
                else { rmToast(j.error || 'Could not create court.', true); btn.disabled = false; }
            }).catch(function () { rmToast('Could not create court.', true); btn.disabled = false; });
        } else {
            var sel = document.getElementById('rm-court-select');
            if (!sel || !sel.value) { rmToast('Pick a court.', true); btn.disabled = false; return; }
            // data-name carries the bare court name; .text is decorated with the
            // date and status and would land in the badge and the Grant modal.
            var opt = sel.selectedOptions[0];
            cb(parseInt(sel.value, 10), opt.dataset.name || opt.text);
        }
    }
    withCourtId(function (courtId, courtName) {
        var ok = 0, skip = 0, fail = 0, i = 0;
        (function next() {
            if (i >= rmCourtTargets.length) {
                rmToast('Added ' + ok + (skip ? ', ' + skip + ' already on court' : '') + (fail ? ', ' + fail + ' failed' : '') + '.', fail > 0);
                btn.disabled = false; rmCloseCourtModal(); rmFetch(true); return;
            }
            var rec = rmCourtTargets[i++];
            // Skip if this rec is already on the chosen court.
            var existing = []; try { existing = JSON.parse(rec._tr.getAttribute('data-courts') || '[]'); } catch (x) {}
            if (existing.some(function (cc) { return cc.CourtId === courtId; })) { skip++; next(); return; }
            var fd = new FormData();
            fd.append('CourtId', courtId);
            fd.append('MundaneId', rec.MundaneId);
            fd.append('KingdomAwardId', rec.KingdomAwardId);
            fd.append('Rank', rec.Rank || 0);
            fd.append('RecommendationsId', rec.RecommendationsId);
            rmPost(RmConfig.uir + 'CourtAjax/add_award', fd).then(function (j) {
                if (j.status === 0) {
                    ok++;
                    existing.push({ CourtId: courtId, CourtAwardId: (j.award && j.award.CourtAwardId) || 0, Name: courtName, CourtDate: '', Status: 'draft' });
                    rec._tr.setAttribute('data-courts', JSON.stringify(existing));
                    rmUpdateCourtBadge(rec._tr, existing);
                    rmSetRowChecked(rec._tr, false); // must go through the choke point or selCount drifts
                } else fail++;
                next();
            }).catch(function () { fail++; next(); });
        })();
    });
});

// Show the reason-cell expander when the reason is truncated OR the cluster has
// more than one member (so "show all recommendations" is always reachable).
function rmSyncReasonExpanders() {
    document.querySelectorAll('#rm-tbody .rm-reason-trunc').forEach(function (el) {
        var btn = el.parentNode.querySelector('.rm-expand-members');
        if (!btn) return;
        var tr = el.closest('tr');
        var members = []; try { members = JSON.parse(tr.getAttribute('data-membersfull') || '[]'); } catch (x) {}
        var truncated = (el.scrollWidth - el.clientWidth > 1);
        btn.style.display = (truncated || members.length > 1) ? '' : 'none';
    });
}

// Initial: the server already rendered the first batch sorted by date desc — just
// reflect that in the sort-header indicator and seed the filter state from the inputs.
(function () {
    var th = document.querySelector('.rm-sortable[data-sort="date"]');
    if (th) th.classList.add('rm-sort-desc');
})();
// Pre-apply the "Passed to local" filter when arrived via ?passlocal=1
// (e.g. from the park profile "Delegated by the Kingdom" section's Manage link).
(function () {
    var params = new URLSearchParams(window.location.search);
    var pl = document.getElementById('rm-filter-passlocal');
    rmReadFilters();
    if (params.get('passlocal') === '1' && pl) {
        pl.checked = true;
        rmReadFilters();
        rmFetch(true); // re-query server-side with the pass-to-local filter applied
    }
})();
rmSyncReasonExpanders();
var rmReasonResizeT;
window.addEventListener('resize', function () {
    clearTimeout(rmReasonResizeT);
    rmReasonResizeT = setTimeout(rmSyncReasonExpanders, 150);
});
</script>
