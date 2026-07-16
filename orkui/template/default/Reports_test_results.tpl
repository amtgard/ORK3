<?php
/* ── Pre-compute stats & scope ────────────────────────────── */
$rows       = is_array($results ?? null) ? $results : [];
$stats      = is_array($stats ?? null) ? $stats : [];
$test_label = $test_label ?? 'Test';
$test_icon  = $test_icon  ?? 'fa-check';
// Short qualification name for copy, e.g. "Corpora Test" -> "Corpora", "Reeve's Test" -> "Reeve's".
$qual_name  = preg_replace('/\s*Test$/', '', $test_label);
$now        = time();

$qualPct = ($stats['ActivePlayers'] ?? 0) > 0
	? round(($stats['ActiveQualified'] / $stats['ActivePlayers']) * 100, 1)
	: 0;

/* Scope chip */
$scope_label = isset($KingdomName) ? $KingdomName : '';
$scope_link  = '';
$scope_icon  = 'fa-chess-rook';
if (($ScopeType ?? '') === 'kingdom' && !empty($ScopeId)) {
	$scope_link = UIR . 'Kingdom/profile/' . (int)$ScopeId;
}
?>

<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/buttons/2.4.2/css/buttons.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedheader/3.4.0/css/fixedHeader.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.dataTables.min.css">
<link rel="stylesheet" href="https://cdn.datatables.net/fixedcolumns/4.3.0/css/fixedColumns.dataTables.min.css">
<link rel="stylesheet" href="<?=HTTP_TEMPLATE?>default/style/reports.css">

<style>
.rp-pass-badge, .rp-fail-badge {
	display: inline-block;
	font-size: 0.75rem;
	font-weight: 600;
	padding: 2px 8px;
	border-radius: 4px;
	margin-left: 6px;
	vertical-align: middle;
}
.rp-pass-badge {
	background: #c6f6d5;
	color: #276749;
}
.rp-fail-badge {
	background: #fed7d7;
	color: #9b2c2c;
}
.rp-flag-badge {
	display: inline-block;
	font-size: 0.72rem;
	font-weight: 600;
	padding: 1px 6px;
	border-radius: 4px;
	background: #fefcbf;
	color: #975a16;
}
.rp-expired-row td {
	color: #a0aec0 !important;
}
.rp-expired-row td a {
	color: #a0aec0 !important;
}
html[data-theme="dark"] .rp-expired-row td,
html[data-theme="dark"] .rp-expired-row td a {
	color: var(--ork-text-muted, #a0aec0) !important;
}

/* History drill-in */
.rp-history-btn {
	display: inline-flex; align-items: center; gap: 6px;
	padding: 5px 12px; font-size: 0.82rem; font-weight: 600;
	color: #2b6cb0; background: transparent;
	border: 1px solid #bee3f8; border-radius: 6px; cursor: pointer;
	white-space: nowrap;
}
.rp-history-btn:hover { background: #ebf8ff; }
.rp-history-child { padding: 10px 8px; }
.rp-rev-empty { color: #718096; font-size: 0.86rem; padding: 6px 2px; }
.rp-history-list { display: flex; flex-direction: column; gap: 8px; }
.rp-history-row { border: 1px solid #e2e8f0; border-radius: 8px; overflow: hidden; }
.rp-history-row.pass { border-left: 3px solid #48bb78; }
.rp-history-row.fail { border-left: 3px solid #f56565; }
.rp-attempt-toggle {
	display: flex; align-items: center; gap: 14px; width: 100%;
	padding: 9px 13px; background: #fff; border: 0; cursor: pointer;
	font: inherit; text-align: left;
}
.rp-attempt-toggle:hover { background: #f7fafc; }
.rp-att-badge { font-weight: 700; font-size: 0.85rem; }
.rp-history-row.pass .rp-att-badge { color: #276749; }
.rp-history-row.fail .rp-att-badge { color: #9b2c2c; }
.rp-att-score { font-weight: 600; color: #2d3748; }
.rp-att-when { margin-left: auto; font-size: 0.8rem; color: #718096; }
.rp-attempt-detail { padding: 8px 13px 12px; background: #fafbfc; border-top: 1px solid #edf2f7; }
.rp-rev-q { border: 1px solid #e2e8f0; border-radius: 7px; padding: 9px 11px; margin-bottom: 8px; background: #fff; }
.rp-rev-q.ok  .rp-rev-qh { color: #276749; }
.rp-rev-q.bad .rp-rev-qh { color: #9b2c2c; }
.rp-rev-qh { font-weight: 600; margin-bottom: 6px; font-size: 0.9rem; }
.rp-rev-opt { padding: 4px 8px; border-radius: 5px; font-size: 0.84rem; color: #4a5568; margin: 2px 0; }
.rp-rev-opt.correct { background: #f0fff4; color: #276749; }
.rp-rev-opt.wrong   { background: #fff5f5; color: #9b2c2c; }
.rp-rev-opt em { font-style: normal; font-size: 0.72rem; text-transform: uppercase; letter-spacing: .03em; opacity: .75; }
html[data-theme="dark"] .rp-attempt-toggle,
html[data-theme="dark"] .rp-rev-q { background: #2d3748; }
html[data-theme="dark"] .rp-attempt-detail { background: #252d3a; }
html[data-theme="dark"] .rp-att-score { color: #e2e8f0; }
/* Attempt-row meta on the navy row: the #718096 timestamp and the light-mode
   green/red pass-fail badges are too dim — lift to readable brights. */
html[data-theme="dark"] .rp-att-when { color: #a0aec0; }
html[data-theme="dark"] .rp-history-row.pass .rp-att-badge { color: #68d391; }
html[data-theme="dark"] .rp-history-row.fail .rp-att-badge { color: #fc8181; }
/* Dark: clearly-filled pills with near-white text — state stays obvious via the
   fill + left accent, and the answer/label text keeps high contrast. (A faint
   tint or a pale-on-saturated combo both read poorly here.) */
html[data-theme="dark"] .rp-rev-opt.correct { background: #24503c; color: #eafff4; border-left: 3px solid #48bb78; }
html[data-theme="dark"] .rp-rev-opt.wrong   { background: #532a2e; color: #ffe9e9; border-left: 3px solid #f56565; }
/* The global .75 opacity on the (their pick)/(correct) labels washes them out on
   the fills; make them full-opacity and a light neutral so they stay legible. */
html[data-theme="dark"] .rp-rev-opt em { opacity: 1; color: #cbd5e0; }
/* Un-picked options (neither correct nor their pick): the base #4a5568 is
   near-invisible on the navy card — lift to a readable muted grey. */
html[data-theme="dark"] .rp-rev-opt { color: #a0aec0; }
/* Question headers: the light-mode green/red are too dark on the navy card. */
html[data-theme="dark"] .rp-rev-q.ok  .rp-rev-qh { color: #68d391; }
html[data-theme="dark"] .rp-rev-q.bad .rp-rev-qh { color: #fc8181; }
/* Pass/Fail filter pills */
.rp-filter-bar { display: flex; align-items: center; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; }
.rp-filter-label { font-size: 0.82rem; font-weight: 600; color: #718096; }
.rp-filter-pill { padding: 5px 14px; font-size: 0.84rem; font-weight: 600; color: #4a5568; background: #fff; border: 1px solid #cbd5e0; border-radius: 999px; cursor: pointer; }
.rp-filter-pill:hover { background: #f7fafc; }
.rp-filter-pill.rp-filter-active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
html[data-theme="dark"] .rp-filter-label { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .rp-filter-pill { background: #2d3748; border-color: #4a5568; color: #cbd5e0; }
html[data-theme="dark"] .rp-filter-pill:hover { background: #374151; }
html[data-theme="dark"] .rp-filter-pill.rp-filter-active { background: #2b6cb0; border-color: #2b6cb0; color: #fff; }
/* Info tooltip ("i") on stat labels */
.rp-info { position: relative; display: inline-block; margin-left: 4px; color: #a0aec0; cursor: help; font-size: 0.82em; vertical-align: middle; }
.rp-info:hover, .rp-info:focus { color: #2b6cb0; outline: none; }
.rp-info-bubble {
	display: none; position: absolute; left: 50%; transform: translateX(-50%);
	bottom: calc(100% + 8px); width: 240px; padding: 9px 11px;
	background: #2d3748; color: #f7fafc; border-radius: 6px;
	font-size: 0.75rem; font-weight: 400; line-height: 1.45; text-align: left;
	box-shadow: 0 4px 14px rgba(0,0,0,0.22); z-index: 50; white-space: normal;
}
.rp-info-bubble::after {
	content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
	border: 6px solid transparent; border-top-color: #2d3748;
}
.rp-info:hover .rp-info-bubble, .rp-info:focus .rp-info-bubble { display: block; }
</style>

<?php if (!empty($Error)): ?>
<div class="rp-root">
	<div style="max-width:560px;margin:60px auto;padding:32px 28px;text-align:center;background:#fff;border:1px solid #e2e8f0;border-radius:10px;">
		<i class="fas fa-lock" style="font-size:2.4rem;color:#a0aec0;margin-bottom:14px;"></i>
		<h1 style="font-size:1.3rem;color:#2d3748;margin:0 0 8px;"><?= htmlspecialchars($page_title ?? 'Access Denied') ?></h1>
		<p style="color:#718096;margin:0;"><?= htmlspecialchars($Error) ?></p>
	</div>
</div>
<?php else: ?>
<div class="rp-root">

	<!-- ── Header ─────────────────────────────────────────── -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas <?=htmlspecialchars($test_icon)?> rp-header-icon"></i>
				<h1 class="rp-header-title"><?=htmlspecialchars($test_label)?> Results</h1>
			</div>
<?php if ($scope_label) : ?>
			<div class="rp-header-scope">
				<a class="rp-scope-chip" href="<?=$scope_link?>">
					<i class="fas <?=$scope_icon?>"></i>
					<?=htmlspecialchars($scope_label)?>
				</a>
			</div>
<?php endif; ?>
		</div>
		<div class="rp-header-actions">
			<button class="rp-btn-ghost rp-btn-export"><i class="fas fa-download"></i> Export CSV</button>
			<button class="rp-btn-ghost rp-btn-print"><i class="fas fa-print"></i> Print</button>
		</div>
	</div>

	<!-- ── Context strip ──────────────────────────────────── -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Everyone who has attempted the <?=$test_label?> within <?=$scope_label ? htmlspecialchars($scope_label) : 'this kingdom'?> &mdash; one row per player showing their most recent attempt. Use <strong>History</strong> for a player's full attempt log.</span>
	</div>

	<!-- ── Stats row ──────────────────────────────────────── -->
	<div class="rp-stats-row">
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-user-check"></i></div>
			<div class="rp-stat-number"><?=$qualPct?>%</div>
			<div class="rp-stat-label">Active Qualified<span class="rp-info" tabindex="0" role="note" aria-label="What counts as an active player?"><i class="fas fa-info-circle"></i><span class="rp-info-bubble">An <strong>active player</strong> is a member whose home park is in this kingdom and who has signed in to a chapter at least once in the last 6 months. This tile shows how many of them currently hold a valid (unexpired) <?=htmlspecialchars($qual_name)?> qualification.</span></span></div>
			<div class="rp-stat-hint"><?=$stats['ActiveQualified'] ?? 0?> of <?=$stats['ActivePlayers'] ?? 0?> active players</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-chart-line"></i></div>
			<div class="rp-stat-number"><?=$stats['PassRate6Mo'] ?? 0?>%</div>
			<div class="rp-stat-label">Pass Rate (6 mo)</div>
			<div class="rp-stat-hint"><?=$stats['PassRate6MoTotal'] ?? 0?> attempts</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-question-circle"></i></div>
			<div class="rp-stat-number"><?=$stats['ActiveQuestions'] ?? 0?></div>
			<div class="rp-stat-label">Active Questions</div>
		</div>
		<div class="rp-stat-card">
			<div class="rp-stat-icon"><i class="fas fa-flag"></i></div>
			<div class="rp-stat-number"><?=$stats['FlaggedQuestions'] ?? 0?></div>
			<div class="rp-stat-label">Flagged Questions</div>
		</div>
	</div>

	<!-- ── Charts placeholder ─────────────────────────────── -->
	<div class="rp-charts-row" id="rp-charts-row"></div>

	<!-- ── Body: sidebar + table ──────────────────────────── -->
	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header">
					<i class="fas fa-table"></i> Column Guide
				</div>
				<div class="rp-filter-card-body">
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Date</span>
						<span class="rp-col-guide-desc">When the player's most recent attempt was taken.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Player</span>
						<span class="rp-col-guide-desc">Player's persona name; links to their profile.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Park</span>
						<span class="rp-col-guide-desc">The player's home park.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Score</span>
						<span class="rp-col-guide-desc">Percentage score with pass/fail indicator.</span>
					</div>
					<div class="rp-col-guide-item">
						<span class="rp-col-guide-name">Qualified Until</span>
						<span class="rp-col-guide-desc">Current qualification's expiry (blank if never passed). Grayed rows are expired.</span>
					</div>
				</div>
			</div>
		</div><!-- /rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">
			<div class="rp-filter-bar">
				<span class="rp-filter-label">Show:</span>
				<button type="button" class="rp-filter-pill rp-filter-active" data-filter="all">All</button>
				<button type="button" class="rp-filter-pill" data-filter="pass">Passed</button>
				<button type="button" class="rp-filter-pill" data-filter="fail">Failed</button>
			</div>
			<table id="test-results-table" class="display" style="width:100%">
				<thead>
					<tr>
						<th>Date</th>
						<th>Player</th>
						<th>Park</th>
						<th>Score</th>
						<th>Qualified Until</th>
						<th data-dt-order="disable">History</th>
					</tr>
				</thead>
				<tbody>
<?php foreach ($rows as $row) :
	// Score badge uses the attempt's authoritative pass flag. "Qualified Until"
	// reflects a current passing qualification (ExpiresAt from qual_result), which
	// is independent of whether the LATEST attempt passed — and is null/blank for
	// players who have never passed.
	$passed   = !empty($row['Passed']);
	$hasQual  = !empty($row['ExpiresAt']) && $row['ExpiresAt'] !== '0000-00-00 00:00:00';
	$expired  = $hasQual && strtotime($row['ExpiresAt']) < $now;
?>
				<tr class="<?=$expired ? 'rp-expired-row' : ''?>" data-passed="<?=$passed ? '1' : '0'?>">
					<td data-order="<?=htmlspecialchars($row['PassedAt'])?>"><?=date('M j, Y', strtotime($row['PassedAt']))?></td>
					<td><a href="<?=UIR?>Player/profile/<?=$row['MundaneId']?>"><?=htmlspecialchars($row['Persona'] ?: 'No Persona')?></a></td>
					<td><?php if ($row['ParkId']): ?><a href="<?=UIR?>Park/profile/<?=$row['ParkId']?>"><?=htmlspecialchars($row['ParkName'])?></a><?php else: ?>—<?php endif; ?></td>
					<td data-order="<?=htmlspecialchars($row['ScorePercent'])?>"><?=$row['ScorePercent']?>%<?php if ($passed): ?><span class="rp-pass-badge">Pass</span><?php else: ?><span class="rp-fail-badge">Fail</span><?php endif; ?></td>
					<td data-order="<?=htmlspecialchars($row['ExpiresAt'] ?? '')?>"><?=$hasQual ? date('M j, Y', strtotime($row['ExpiresAt'])) : '—'?></td>
					<td><button type="button" class="rp-history-btn" data-mundane-id="<?=(int)$row['MundaneId']?>" data-persona="<?=htmlspecialchars($row['Persona'] ?: 'Player', ENT_QUOTES)?>"><i class="fas fa-history"></i> View</button></td>
				</tr>
<?php endforeach; ?>
				</tbody>
			</table>
		</div><!-- /rp-table-area -->

	</div><!-- /rp-body -->

</div><!-- /rp-root -->


<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.4.2/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/fixedheader/3.4.0/js/dataTables.fixedHeader.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/fixedcolumns/4.3.0/js/dataTables.fixedColumns.min.js"></script>

<script>
$(function() {
	var table = $('#test-results-table').DataTable({
		dom: 'lfrtip',
		buttons: [
			{ extend: 'csv',   filename: <?=json_encode($test_label . ' Results')?>, exportOptions: { columns: ':visible' } },
			{ extend: 'print', exportOptions: { columns: ':visible' } }
		],
		columnDefs: [
			{ targets: [0], type: 'date' },
			{ targets: [3], className: 'dt-right' },
			{ targets: [4], type: 'date', className: 'dt-right' },
			{ targets: [0], responsivePriority: 1 }
		],
		pageLength: 25,
		order: [[0, 'desc']],
		fixedHeader : { headerOffset: 48 },
		responsive  : true,
		scrollX     : true,
		fixedColumns: { left: 1 }
	});

	$('.rp-btn-export').on('click', function() { table.button(0).trigger(); });
	$('.rp-btn-print' ).on('click', function() { table.button(1).trigger(); });

	// ── Pass / Fail filter pills (custom DataTables search on the row flag) ──
	var passFilter = 'all';
	$.fn.dataTable.ext.search.push(function(settings, data, dataIndex) {
		if (settings.nTable.id !== 'test-results-table') return true; // don't touch other tables
		if (passFilter === 'all') return true;
		var tr = settings.aoData[dataIndex].nTr;
		var passed = tr && tr.getAttribute('data-passed') === '1';
		return passFilter === 'pass' ? passed : !passed;
	});
	$('.rp-filter-pill').on('click', function() {
		$('.rp-filter-pill').removeClass('rp-filter-active');
		$(this).addClass('rp-filter-active');
		passFilter = $(this).data('filter');
		table.draw();
	});

	// ── Per-player history drill-in (pass + fail, reviewable for all time) ──
	var BASE_URL   = <?=json_encode(UIR)?>;
	var KINGDOM_ID = <?=(int)$ScopeId?>;
	var TEST_TYPE  = <?=json_encode($TestType)?>;

	function esc(s) {
		return String(s == null ? '' : s)
			.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
			.replace(/"/g, '&quot;').replace(/'/g, '&#39;');
	}

	function renderReview(attempt) {
		if (!attempt || !attempt.Questions || !attempt.Questions.length) {
			return '<div class="rp-rev-empty">No answer detail was recorded for this attempt.</div>';
		}
		var html = '';
		attempt.Questions.forEach(function(q, i) {
			html += '<div class="rp-rev-q ' + (q.Correct ? 'ok' : 'bad') + '">';
			var archived = (q.Archived ? ' <span style="font-size:0.66rem;font-weight:700;text-transform:uppercase;letter-spacing:.03em;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;margin-left:4px;white-space:nowrap;">Archived</span>' : '') + (q.NotInLiveSet ? ' <span style="font-size:0.64rem;font-weight:600;text-transform:uppercase;letter-spacing:.03em;color:#64748b;background:#f1f5f9;border:1px solid #e2e8f0;padding:0 6px;border-radius:4px;margin-left:4px;white-space:nowrap;">Not in current test</span>' : '');
			html += '<div class="rp-rev-qh">' + (q.Correct ? '✓' : '✗') + ' ' + (i + 1) + '. ' + esc(q.QuestionText) + archived + '</div>';
			(q.Options || []).forEach(function(o) {
				var cls = o.IsCorrect ? 'correct' : (o.WasSelected ? 'wrong' : '');
				var tag = o.WasSelected ? ' <em>(their pick)</em>' : (o.IsCorrect ? ' <em>(correct)</em>' : '');
				html += '<div class="rp-rev-opt ' + cls + '">' + esc(o.AnswerText) + tag + '</div>';
			});
			html += '</div>';
		});
		return html;
	}

	function post(endpoint, params, cb) {
		var fd = new FormData();
		Object.keys(params).forEach(function(k) { fd.append(k, params[k]); });
		fetch(BASE_URL + endpoint, { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) { cb(j); })
			.catch(function() { cb(null); });
	}

	// Toggle the DataTables child row holding a player's attempt list.
	$('#test-results-table tbody').on('click', '.rp-history-btn', function() {
		var btn = $(this);
		var tr  = btn.closest('tr');
		var trow = table.row(tr);
		if (trow.child.isShown()) {
			trow.child.hide();
			tr.removeClass('rp-child-open');
			btn.html('<i class="fas fa-history"></i> View');
			return;
		}
		var mundaneId = btn.data('mundane-id');
		var persona   = btn.data('persona');
		var holder = $('<div class="rp-history-child"><div class="rp-rev-empty">Loading history…</div></div>');
		trow.child(holder).show();
		tr.addClass('rp-child-open');
		btn.html('<i class="fas fa-chevron-up"></i> Hide');

		post('QualTestAjax/attempts', { PlayerId: mundaneId, KingdomId: KINGDOM_ID, TestType: TEST_TYPE }, function(j) {
			if (!j || j.status !== 0) {
				holder.html('<div class="rp-rev-empty">Could not load history.</div>');
				return;
			}
			if (!j.attempts.length) {
				holder.html('<div class="rp-rev-empty">No recorded attempts for ' + esc(persona) + '.</div>');
				return;
			}
			var list = $('<div class="rp-history-list"></div>');
			j.attempts.forEach(function(a) {
				var when = a.TakenAt ? new Date(a.TakenAt.replace(' ', 'T')).toLocaleString() : '';
				var item = $(
					'<div class="rp-history-row ' + (a.Passed ? 'pass' : 'fail') + '">' +
						'<button type="button" class="rp-attempt-toggle" data-attempt-id="' + a.QualAttemptId + '">' +
							'<span class="rp-att-badge">' + (a.Passed ? '✓ Passed' : '✗ Failed') + '</span>' +
							'<span class="rp-att-score">' + a.ScorePercent + '%</span>' +
							'<span class="rp-att-when">' + esc(when) + (a.RulesVersion ? ' · ' + esc(a.RulesVersion) : '') + '</span>' +
						'</button>' +
						'<div class="rp-attempt-detail" style="display:none;"></div>' +
					'</div>'
				);
				list.append(item);
			});
			holder.empty().append(list);
		});
	});

	// Expand a single attempt to its full answer review.
	$('#test-results-table tbody').on('click', '.rp-attempt-toggle', function() {
		var btn    = $(this);
		var detail = btn.next('.rp-attempt-detail');
		if (detail.is(':visible')) { detail.hide(); return; }
		if (!detail.data('loaded')) {
			detail.html('<div class="rp-rev-empty">Loading…</div>').show();
			post('QualTestAjax/attemptdetail', { AttemptId: btn.data('attempt-id') }, function(j) {
				detail.data('loaded', true);
				detail.html(renderReview(j && j.status === 0 ? j.attempt : null));
			});
		} else {
			detail.show();
		}
	});
});
</script>
<?php endif; /* !empty($Error) */ ?>
