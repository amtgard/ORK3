<?php
	// Auth gate
	$_rcUid    = isset($this->__session->user_id) ? (int)$this->__session->user_id : 0;
	$_rcParkId = (int)($Player['ParkId'] ?? 0);
	$canEditAdmin = $_rcUid > 0 && Ork3::$Lib->authorization->HasAuthority($_rcUid, AUTH_PARK, $_rcParkId, AUTH_EDIT);
	if (!$canEditAdmin) {
		header('Location: ' . UIR . 'Player/profile/' . (int)($Player['MundaneId'] ?? 0));
		exit;
	}

	// ── Partition awards ────────────────────────────────────────────────────
	$allAwards          = is_array($Details['Awards']) ? $Details['Awards'] : [];
	$historicalAwards   = [];
	$realRanksByAwardId = [];

	foreach ($allAwards as $a) {
		$isAward = in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1;
		if (!$isAward) continue;

		$isHistorical = (int)(int)$a['GivenById'] === 0 && (int)($a['EnteredById'] ?? 0) === 0;

		if ($isHistorical) {
			$historicalAwards[] = $a;
		} else {
			$aid  = (int)$a['AwardId'];
			$rank = (int)$a['Rank'];
			if ($aid > 0) {
				if (!isset($realRanksByAwardId[$aid])) $realRanksByAwardId[$aid] = [];
				if ($rank > 0) $realRanksByAwardId[$aid][] = $rank;
			}
		}
	}

	// Keep only ladder awards — non-ladder (Custom Award etc.) are not reconcilable
	$historicalAwards = array_values(array_filter($historicalAwards, function($a) {
		return (int)($a['IsLadder'] ?? 0) === 1;
	}));

	// Sort: AwardId ASC, date ASC (missing last)
	usort($historicalAwards, function($a, $b) {
		if ((int)$a['AwardId'] !== (int)$b['AwardId'])
			return (int)$a['AwardId'] - (int)$b['AwardId'];
		$da = ($ts = strtotime($a['Date'] ?? '')) > 0 ? $ts : PHP_INT_MAX;
		$db = ($ts = strtotime($b['Date'] ?? '')) > 0 ? $ts : PHP_INT_MAX;
		return $da - $db;
	});

	// ── Smart rank suggestions ───────────────────────────────────────────────
	$rankSuggestions = [];
	$groupState      = [];
	foreach ($historicalAwards as $a) {
		$aid      = (int)$a['AwardId'];
		$awardsId = (int)$a['AwardsId'];
		$isLadder = (int)($a['IsLadder'] ?? 0);
		if (!$isLadder) { $rankSuggestions[$awardsId] = 0; continue; }
		if (!isset($groupState[$aid])) {
			$real = [];
			foreach ($realRanksByAwardId[$aid] ?? [] as $r) { if ($r > 0) $real[$r] = true; }
			$groupState[$aid] = ['realRanks' => $real, 'usedRanks' => []];
		}
		$existing = (int)$a['Rank'];
		if ($existing > 0 && !isset($groupState[$aid]['realRanks'][$existing]) && !isset($groupState[$aid]['usedRanks'][$existing])) {
			$rankSuggestions[$awardsId] = $existing;
			$groupState[$aid]['usedRanks'][$existing] = true;
		} else {
			$c = 1;
			while (isset($groupState[$aid]['realRanks'][$c]) || isset($groupState[$aid]['usedRanks'][$c])) $c++;
			$rankSuggestions[$awardsId] = $c;
			$groupState[$aid]['usedRanks'][$c] = true;
		}
	}

	$awardTypeCount = count(array_unique(array_column($historicalAwards, 'AwardId')));
	$totalCount     = count($historicalAwards);
	$playerId       = (int)($Player['MundaneId'] ?? 0);
	$persona        = htmlspecialchars($Player['Persona'] ?? 'Player');
	$heraldryUrl    = ($Player['HasHeraldry'] ?? 0) > 0
		? ($Player['Heraldry'] ?? (HTTP_PLAYER_HERALDRY . '000000.jpg'))
		: HTTP_PLAYER_HERALDRY . '000000.jpg';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">
<style>
/* ── Reconcile page layout ─────────────────────────────────────── */
.rc-wrap { max-width: 1200px; margin: 0 auto 80px; }

/* ── Reconcile-specific table/row styles ───────────────────────── */
.rc-group-hdr td { background: #ebf8ff; color: #2b6cb0; font-weight: 600; font-size: 12px;
                   padding: 7px 14px; border-top: 2px solid #bee3f8; }
.rc-real-badge  { display: inline-flex; align-items: center; gap: 4px; background: #fff;
                  border: 1px solid #90cdf4; border-radius: 10px; padding: 1px 8px;
                  font-size: 11px; color: #2b6cb0; margin-left: 8px; font-weight: 400; }
.rc-no-badge    { color: #a0aec0; border-color: #e2e8f0; background: transparent; }

/* Inline inputs */
.rc-table input[type="text"],
.rc-table input[type="number"],
.rc-table input[type="date"],
.rc-table select {
	padding: 5px 7px; border: 1.5px solid #e2e8f0; border-radius: 5px;
	font-size: 12px; background: #fff; color: #2d3748; width: 100%;
	box-sizing: border-box; transition: border-color .15s;
}
.rc-table input[type="text"]:focus,
.rc-table input[type="number"]:focus,
.rc-table input[type="date"]:focus,
.rc-table select:focus { border-color: #90cdf4; outline: none; box-shadow: 0 0 0 3px rgba(66,153,225,.15); }
.rc-table input[type="number"] { width: 62px; }
.rc-table input[type="date"]   { width: 148px; }
.rc-table select               { min-width: 160px; }

/* Autocomplete */
.rc-search-wrap   { position: relative; min-width: 130px; }
.rc-ac-results    { position: absolute; left: 0; right: 0; top: 100%; z-index: 200;
                    background: #fff; border: 1px solid #e2e8f0; border-top: none;
                    border-radius: 0 0 6px 6px; max-height: 180px; overflow-y: auto;
                    box-shadow: 0 4px 12px rgba(0,0,0,.08); display: none; }
.rc-ac-item       { padding: 6px 10px; cursor: pointer; font-size: 12px; color: #2d3748; }
.rc-ac-item:hover { background: #ebf8ff; }
.rc-ac-item-sub   { font-size: 11px; color: #a0aec0; }

/* Row states */
.rc-row-done td        { opacity: .55; }
.rc-row-done input,
.rc-row-done select    { pointer-events: none; }
.rc-status-done        { color: #38a169; font-weight: 600; font-size: 12px; white-space: nowrap; }
.rc-status-skip        { color: #a0aec0; font-size: 12px; white-space: nowrap; }
.rc-row-error td       { background: #fff5f5 !important; }
.rc-row-errmsg         { color: #c53030; font-size: 11px; margin-top: 2px; }

/* Spinner */
.rc-spinner { display: inline-block; width: 12px; height: 12px; vertical-align: middle;
              border: 2px solid rgba(255,255,255,.35); border-top-color: #fff;
              border-radius: 50%; animation: rc-spin .6s linear infinite; }
@keyframes rc-spin { to { transform: rotate(360deg); } }
</style>

<div class="rc-wrap">

	<!-- ── Banner hero (ec- pattern) ── -->
	<div class="ec-banner" style="border-radius:10px;margin-bottom:0">
		<div class="ec-banner-bg" style="background-image:url('<?= htmlspecialchars($heraldryUrl) ?>')"></div>
		<div class="ec-banner-heraldry">
			<img src="<?= htmlspecialchars($heraldryUrl) ?>"
			     onerror="this.src='<?= HTTP_PLAYER_HERALDRY ?>000000.jpg'"
			     alt="<?= $persona ?>" crossorigin="anonymous">
		</div>
		<div class="ec-banner-info">
			<div class="ec-banner-label"><i class="fas fa-history" style="margin-right:4px"></i>Reconcile Historical Awards</div>
			<h1 class="ec-banner-name"><?= $persona ?></h1>
			<div class="ec-banner-crumb">
				<a href="<?= UIR ?>Player/profile/<?= $playerId ?>">← Back to <?= $persona ?>'s profile</a>
				<span>›</span>
				<span><?= $totalCount ?> award<?= $totalCount !== 1 ? 's' : '' ?> across <?= $awardTypeCount ?> type<?= $awardTypeCount !== 1 ? 's' : '' ?></span>
			</div>
		</div>
	</div>

	<!-- ── Content card ── -->
	<div class="adm-card" style="border-radius:0 0 10px 10px;border-top:none">

		<?php if (empty($historicalAwards)): ?>
			<div class="pn-empty" style="padding:60px 20px">
				<i class="fas fa-check-circle" style="font-size:40px;color:#68d391;display:block;margin-bottom:10px"></i>
				No historical awards found — nothing to reconcile.
			</div>
		<?php else: ?>

		<div class="adm-card-header">
			<div class="adm-card-title">
				<i class="fas fa-table"></i>
				Historical Awards
				<span class="adm-count" id="rc-pending-count"><?= $totalCount ?> pending</span>
			</div>
			<button class="adm-btn adm-btn-primary" id="rc-reconcile-all">
				<i class="fas fa-check-double"></i> Update All Pending
			</button>
		</div>

		<div class="adm-table-wrap rc-table-wrap">
		<table class="adm-table rc-table" id="rc-table">
			<thead>
				<tr>
					<th class="adm-th-center" style="width:28px"></th>
					<th style="min-width:120px">Target Award</th>
					<th style="width:64px">Rank</th>
					<th style="width:120px">Date</th>
					<th style="width:130px">Given By</th>
					<th style="width:120px">Location</th>
					<th>Note</th>
					<th style="width:145px"></th>
				</tr>
			</thead>
			<tbody>
			<?php
				$prevAwardId = null;
				foreach ($historicalAwards as $a):
					$aid      = (int)$a['AwardId'];
					$awardsId = (int)$a['AwardsId'];
					$isLadder = (int)($a['IsLadder'] ?? 0);
					$sugRank  = $rankSuggestions[$awardsId] ?? 0;
					$maxRank  = ($aid === 30) ? 12 : 10;

					$legacyTs   = strtotime($a['Date'] ?? '');
					$legacyDate = ($legacyTs > 0) ? date('Y-m-d', $legacyTs) : '';
					$givenById   = (int)$a['GivenById'];
					$givenByName = htmlspecialchars($a['GivenBy'] ?? '');
					$existParkId  = (int)$a['ParkId'];
					$existKingId  = (int)$a['KingdomId'];
					$existLocName = '';
					if (trimlen($a['EventName']   ?? '') > 0) $existLocName = $a['EventName'];
					elseif (trimlen($a['ParkName']    ?? '') > 0) $existLocName = $a['ParkName'];
					elseif (trimlen($a['KingdomName'] ?? '') > 0) $existLocName = $a['KingdomName'];

					if ($aid !== $prevAwardId):
						$prevAwardId = $aid;
						$realRanks   = $realRanksByAwardId[$aid] ?? [];
						sort($realRanks);
			?>
				<tr class="rc-group-hdr">
					<td colspan="8">
						<i class="fas fa-medal" style="margin-right:5px;opacity:.65"></i><?= htmlspecialchars($a['Name']) ?>
						<?php if (!empty($realRanks)): ?>
							<span class="rc-real-badge">
								<i class="fas fa-check" style="color:#38a169"></i>
								Real awards held: Rank <?= implode(', ', $realRanks) ?>
							</span>
						<?php else: ?>
							<span class="rc-real-badge rc-no-badge">No real awards yet</span>
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>

				<tr class="rc-award-row" data-awards-id="<?= $awardsId ?>" data-is-ladder="<?= $isLadder ?>">
					<td class="adm-td-center">
						<span class="rc-row-status" title="Pending">
							<i class="fas fa-clock" style="color:#e2e8f0"></i>
						</span>
					</td>

					<td>
						<?php
						$_preselect = (int)$a['KingdomAwardId'] > 0
							? (int)$a['KingdomAwardId']
							: ($AwardIdToKingdomAwardId[$aid] ?? 0);
						?>
						<select class="rc-field-award" required>
							<option value=""><?= $isLadder ? 'Select order…' : 'Select award…' ?></option>
							<?= $AwardOptions ?? '' ?>
						</select>
						<?php if ($_preselect > 0): ?>
						<script>(function(){ var s=document.currentScript.previousElementSibling; if(s) s.value='<?= $_preselect ?>'; })();</script>
						<?php endif; ?>
					</td>

					<td style="width:64px">
						<?php if ($isLadder): ?>
							<input type="number" class="rc-field-rank" min="1" max="<?= $maxRank ?>"
							       value="<?= $sugRank > 0 ? $sugRank : '' ?>" title="Rank (max <?= $maxRank ?>)">
						<?php else: ?>
							<span style="color:#a0aec0">—</span>
							<input type="hidden" class="rc-field-rank" value="0">
						<?php endif; ?>
					</td>

					<td style="width:130px">
						<input type="date" class="rc-field-date" value="<?= htmlspecialchars($legacyDate) ?>">
					</td>

					<td>
						<div class="rc-search-wrap">
								<input type="text" class="rc-field-givenby-text" placeholder="Search persona…"
							       autocomplete="off" value="<?= $givenByName ?>">
							<input type="hidden" class="rc-field-givenby-id" value="<?= $givenById > 0 ? $givenById : '' ?>">
							<div class="rc-ac-results rc-givenby-results"></div>
						</div>
					</td>

					<td>
						<div class="rc-search-wrap">
							<input type="text" class="rc-field-loc-text" placeholder="Park or kingdom…"
							       autocomplete="off" value="<?= htmlspecialchars($existLocName) ?>">
							<input type="hidden" class="rc-field-loc-park"    value="<?= $existParkId ?>">
							<input type="hidden" class="rc-field-loc-kingdom" value="<?= $existKingId ?>">
							<input type="hidden" class="rc-field-loc-event"   value="0">
							<div class="rc-ac-results rc-loc-results"></div>
						</div>
					</td>

					<td>
						<input type="text" class="rc-field-note" maxlength="400" placeholder="Optional…"
						       value="<?= htmlspecialchars($a['Note'] ?? '') ?>">
					</td>

					<td style="white-space:nowrap">
						<button type="button" class="adm-btn adm-btn-primary rc-do-reconcile" style="padding:5px 12px;font-size:12px">
							<i class="fas fa-check"></i> Update
						</button>
						<button type="button" class="adm-btn adm-btn-ghost rc-do-skip" style="padding:5px 10px;font-size:12px;margin-left:3px">
							Skip
						</button>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		</div>

		<?php endif; ?>
	</div><!-- adm-card -->

</div><!-- rc-wrap -->

<script>
var RcConfig = {
	uir:       '<?= UIR ?>',
	playerId:  <?= $playerId ?>,
	kingdomId: <?= (int)($KingdomId ?? 0) ?>
};
</script>
<script>
(function() {
	'use strict';

	// ── Autocomplete ────────────────────────────────────────────────────────
	var _t = {};
	function acSearch(q, cb) {
		clearTimeout(_t[q]);
		_t[q] = setTimeout(function() {
			fetch(RcConfig.uir + 'SearchAjax/universal&q=' + encodeURIComponent(q) + '&kid=' + RcConfig.kingdomId + '&inactive=1')
				.then(function(r){ return r.json(); }).then(cb).catch(function(){});
		}, 220);
	}

	document.addEventListener('click', function(e) {
		if (!e.target.closest('.rc-search-wrap'))
			document.querySelectorAll('.rc-ac-results').forEach(function(d){ d.style.display='none'; });
	});

	// ── Wire a row ──────────────────────────────────────────────────────────
	function wireRow(row) {
		// Given By
		var gbText = row.querySelector('.rc-field-givenby-text');
		var gbId   = row.querySelector('.rc-field-givenby-id');
		var gbDrop = row.querySelector('.rc-givenby-results');
		if (gbText) {
			gbText.addEventListener('input', function() {
				gbId.value = '';
				var q = gbText.value.trim();
				if (q.length < 2) { gbDrop.style.display = 'none'; return; }
				acSearch(q, function(data) {
					var items = data.players || [];
					if (!items.length) { gbDrop.style.display = 'none'; return; }
					gbDrop.innerHTML = items.map(function(p) {
						return '<div class="rc-ac-item" data-id="' + p.id + '" data-name="' + escH(p.name) + '">'
							+ escH(p.name)
							+ (p.park ? '<div class="rc-ac-item-sub">' + escH(p.park) + '</div>' : '')
							+ '</div>';
					}).join('');
					gbDrop.style.display = 'block';
					gbDrop.querySelectorAll('.rc-ac-item').forEach(function(item) {
						item.addEventListener('mousedown', function(e) {
							e.preventDefault();
							gbText.value = item.dataset.name;
							gbId.value   = item.dataset.id;
							gbDrop.style.display = 'none';
						});
					});
				});
			});
		}

		// Location
		var locText    = row.querySelector('.rc-field-loc-text');
		var locPark    = row.querySelector('.rc-field-loc-park');
		var locKingdom = row.querySelector('.rc-field-loc-kingdom');
		var locEvent   = row.querySelector('.rc-field-loc-event');
		var locDrop    = row.querySelector('.rc-loc-results');
		if (locText) {
			locText.addEventListener('input', function() {
				locPark.value = 0; locKingdom.value = 0; locEvent.value = 0;
				var q = locText.value.trim();
				if (q.length < 2) { locDrop.style.display = 'none'; return; }
				acSearch(q, function(data) {
					var items = [];
					(data.parks    || []).forEach(function(p){ items.push({id:p.id, name:p.name, sub:p.abbr||'', type:'park'}); });
					(data.kingdoms || []).forEach(function(k){ items.push({id:k.id, name:k.name, sub:'Kingdom',     type:'kingdom'}); });
					if (!items.length) { locDrop.style.display = 'none'; return; }
					locDrop.innerHTML = items.map(function(it) {
						return '<div class="rc-ac-item" data-id="' + it.id + '" data-type="' + it.type + '" data-name="' + escH(it.name) + '">'
							+ escH(it.name) + (it.sub ? '<div class="rc-ac-item-sub">' + escH(it.sub) + '</div>' : '') + '</div>';
					}).join('');
					locDrop.style.display = 'block';
					locDrop.querySelectorAll('.rc-ac-item').forEach(function(item) {
						item.addEventListener('mousedown', function(e) {
							e.preventDefault();
							locText.value = item.dataset.name;
							locPark.value = 0; locKingdom.value = 0; locEvent.value = 0;
							if (item.dataset.type === 'park')    locPark.value    = item.dataset.id;
							if (item.dataset.type === 'kingdom') locKingdom.value = item.dataset.id;
							locDrop.style.display = 'none';
						});
					});
				});
			});
		}

		row.querySelector('.rc-do-reconcile').addEventListener('click', function() { doReconcile(row); });
		row.querySelector('.rc-do-skip').addEventListener('click', function() { markSkipped(row); });
	}

	// ── Submit one row ──────────────────────────────────────────────────────
	function doReconcile(row) {
		var kid = parseInt(row.querySelector('.rc-field-award').value || '0', 10);
		if (!kid) { flashError(row, 'Please select a target award.'); return; }
		var gid = parseInt(row.querySelector('.rc-field-givenby-id').value || '0', 10);

		var btn = row.querySelector('.rc-do-reconcile');
		btn.disabled = true;
		btn.innerHTML = '<span class="rc-spinner"></span> Saving…';

		fetch(RcConfig.uir + 'PlayerAjax/player/' + RcConfig.playerId + '/reconcileaward', {
			method: 'POST',
			body: buildFd(row, kid, gid)
		})
		.then(function(r){ return r.json(); })
		.then(function(d) {
			if (d.status === 0) { markDone(row); }
			else {
				btn.disabled = false;
				btn.innerHTML = '<i class="fas fa-check"></i> Update';
				flashError(row, d.error || 'Server error.');
			}
		})
		.catch(function() {
			btn.disabled = false;
			btn.innerHTML = '<i class="fas fa-check"></i> Update';
			flashError(row, 'Network error.');
		});
	}

	function buildFd(row, kid, gid) {
		var fd = new FormData();
		fd.append('AwardsId',       row.dataset.awardsId);
		fd.append('KingdomAwardId', kid || parseInt(row.querySelector('.rc-field-award').value||'0',10));
		fd.append('Rank',           parseInt(row.querySelector('.rc-field-rank').value||'0',10));
		fd.append('Date',           row.querySelector('.rc-field-date').value);
		fd.append('GivenById',      gid || parseInt(row.querySelector('.rc-field-givenby-id').value||'0',10));
		fd.append('Note',           row.querySelector('.rc-field-note').value);
		fd.append('ParkId',         parseInt(row.querySelector('.rc-field-loc-park').value||'0',10));
		fd.append('KingdomId',      parseInt(row.querySelector('.rc-field-loc-kingdom').value||'0',10));
		fd.append('EventId',        parseInt(row.querySelector('.rc-field-loc-event').value||'0',10));
		return fd;
	}

	function markDone(row) {
		row.classList.add('rc-row-done');
		row.querySelector('.rc-row-status').innerHTML = '<i class="fas fa-check-circle" style="color:#38a169"></i>';
		row.querySelector('.rc-do-reconcile').closest('td').innerHTML =
			'<span class="rc-status-done"><i class="fas fa-check-circle"></i> Reconciled</span>';
		clearRowError(row); updatePendingCount();
	}

	function markSkipped(row) {
		row.classList.add('rc-row-done');
		row.querySelector('.rc-row-status').innerHTML = '<i class="fas fa-minus-circle" style="color:#a0aec0"></i>';
		row.querySelector('.rc-do-reconcile').closest('td').innerHTML =
			'<span class="rc-status-skip"><i class="fas fa-minus-circle"></i> Skipped</span>';
		clearRowError(row); updatePendingCount();
	}

	function flashError(row, msg) {
		clearRowError(row); row.classList.add('rc-row-error');
		var el = document.createElement('div'); el.className = 'rc-row-errmsg'; el.textContent = msg;
		row.querySelector('td:first-child').appendChild(el);
	}
	function clearRowError(row) {
		row.classList.remove('rc-row-error');
		var old = row.querySelector('.rc-row-errmsg'); if (old) old.remove();
	}
	function updatePendingCount() {
		var n  = document.querySelectorAll('.rc-award-row:not(.rc-row-done)').length;
		var el = document.getElementById('rc-pending-count');
		if (el) el.textContent = n + ' pending';
	}

	// ── Reconcile All ───────────────────────────────────────────────────────
	var allBtn = document.getElementById('rc-reconcile-all');
	if (allBtn) allBtn.addEventListener('click', function() {
		var pending = Array.from(document.querySelectorAll('.rc-award-row:not(.rc-row-done)'));
		if (!pending.length) return;
		allBtn.disabled = true;
		allBtn.innerHTML = '<span class="rc-spinner"></span> Reconciling…';
		var i = 0;
		function next() {
			if (i >= pending.length) {
				allBtn.disabled = false;
				allBtn.innerHTML = '<i class="fas fa-check-double"></i> Reconcile All Pending';
				return;
			}
			var row = pending[i++];
			if (row.classList.contains('rc-row-done')) { next(); return; }
			var kid = parseInt(row.querySelector('.rc-field-award').value||'0',10);
			var gid = parseInt(row.querySelector('.rc-field-givenby-id').value||'0',10);
			if (!kid || !gid) { next(); return; }
			fetch(RcConfig.uir + 'PlayerAjax/player/' + RcConfig.playerId + '/reconcileaward', {
				method: 'POST', body: buildFd(row, kid, gid)
			})
			.then(function(r){ return r.json(); })
			.then(function(d){ if (d.status===0) markDone(row); else flashError(row, d.error||'Error'); next(); })
			.catch(function(){ flashError(row,'Network error.'); next(); });
		}
		next();
	});

	function escH(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	document.querySelectorAll('.rc-award-row').forEach(wireRow);
})();
</script>
