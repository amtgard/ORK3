<?php
	if (strlen($Error ?? '') > 0) {
		echo '<div class="error-message">' . htmlspecialchars($Error) . '</div>';
		return;
	}
	$typeLabel  = ($TestType === 'corpora') ? 'Corpora Test' : "Reeve's Test";
	$activeQs   = array_values(array_filter($Questions, fn($q) => $q['Status'] === 'active'));
	$archivedQs = array_values(array_filter($Questions, fn($q) => $q['Status'] === 'archived'));
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
.qt-nav-link { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 5px; font-size: 13px; font-weight: 600; color: #2b6cb0; text-decoration: none; transition: background 0.15s; }
.qt-nav-link:hover { background: #ebf4ff; border-color: #bee3f8; color: #2c5282; }
</style>
<link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/jquery.dataTables.min.css">

<style>
.qt-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
.qt-badge-green { background: #c6f6d5; color: #276749; }
.qt-badge-gray  { background: #e2e8f0; color: #4a5568; }
.qt-badge-red   { background: #fed7d7; color: #9b2c2c; }
.rp-table-area .qt-action-btn {
	display: inline-block; padding: 4px 10px; border-radius: 4px; font-size: 0.8rem;
	font-weight: 600; cursor: pointer; border: none; text-decoration: none;
}
.qt-action-btn-edit   { background: #e2e8f0; color: #2d3748; }
.qt-action-btn-edit:hover { background: #cbd5e0; }
.qt-action-btn-archive { background: #fed7d7; color: #9b2c2c; border: none; }
.qt-action-btn-archive:hover { background: #feb2b2; }
.qt-action-btn-restore { background: #c6f6d5; color: #276749; border: none; }
.qt-action-btn-restore:hover { background: #9ae6b4; }
.qt-actions-cell { white-space: nowrap; display: flex; gap: 6px; align-items: center; }
.qt-action-btn-reset { background: #e9d8fd; color: #553c9a; border: none; }
.qt-action-btn-reset:hover { background: #d6bcfa; }
.qt-action-btn-dup { background: #bee3f8; color: #2c5282; border: none; }
.qt-action-btn-dup:hover { background: #90cdf4; }
.qt-correct-answer { font-size: 0.78rem; color: #276749; margin-top: 3px; font-style: italic; }
.qt-success-badge { display: inline-block; padding: 2px 8px; border-radius: 10px; font-size: 0.75rem; font-weight: 600; }
.qt-success-green  { background: #c6f6d5; color: #276749; }
.qt-success-yellow { background: #fefcbf; color: #744210; }
.qt-success-red    { background: #fed7d7; color: #9b2c2c; }
.qt-success-none   { background: #e2e8f0; color: #718096; }
.qt-flag-btn { background: none; border: none; cursor: pointer; color: #e53e3e; font-size: 1rem; padding: 0 2px; line-height: 1; }
.qt-flag-btn:hover { color: #9b2c2c; }
[data-tip] { position: relative; }
[data-tip]::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #2d3748; color: #fff; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; white-space: nowrap; pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 100; }
[data-tip]:hover::after { opacity: 1; }
.qt-lib-question { border:1px solid #e2e8f0; border-radius:6px; padding:12px 14px; margin-bottom:10px; }
.qt-lib-question-hdr { display:flex; justify-content:space-between; align-items:flex-start; gap:10px; }
.qt-lib-question-text { font-size:0.88rem; font-weight:600; color:#2d3748; flex:1; }
.qt-lib-kingdom { font-size:0.75rem; color:#718096; margin-top:2px; }
.qt-lib-answers { margin-top:8px; padding-left:14px; }
.qt-lib-answer { font-size:0.8rem; color:#4a5568; line-height:1.6; }
.qt-lib-answer.qt-lib-correct { color:#276749; font-weight:600; }
.qt-lib-add-btn { white-space:nowrap; padding:4px 12px; background:#2b6cb0; color:#fff; border:none; border-radius:4px; font-size:0.8rem; font-weight:600; cursor:pointer; }
.qt-lib-add-btn:hover { background:#2c5282; }
.qt-lib-add-btn:disabled { background:#a0aec0; cursor:default; }
#qt-library-search:focus { border-color:#2b6cb0; box-shadow:0 0 0 3px rgba(43,108,176,0.15); }
.qt-report-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.45); z-index:9000; align-items:center; justify-content:center; }
.qt-report-overlay.qt-open { display:flex; }
.qt-report-modal { background:#fff; border-radius:8px; padding:24px 26px; min-width:320px; max-width:440px; width:100%; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-report-modal h4 { margin:0 0 14px; font-size:1rem; color:#2d3748; }
.qt-report-modal h4 i { color:#e53e3e; margin-right:6px; }
.qt-report-reason-row { display:flex; justify-content:space-between; align-items:center; padding:6px 0; border-bottom:1px solid #f0f4f8; font-size:0.88rem; color:#4a5568; }
.qt-report-reason-row:last-of-type { border-bottom:none; }
.qt-report-count { font-weight:700; color:#e53e3e; min-width:24px; text-align:right; }
.qt-report-footer { display:flex; gap:8px; margin-top:16px; flex-wrap:wrap; }
/* Bulk checkbox + bar */
.qt-bulk-cb { accent-color:#2b6cb0; width:15px; height:15px; cursor:pointer; }
.qt-bulk-cb-th { width:36px; text-align:center; }
.qt-bulk-bar { display:none; position:fixed; bottom:20px; left:50%; transform:translateX(-50%); background:#2d3748; color:#fff; padding:10px 20px; border-radius:8px; z-index:9000; gap:14px; align-items:center; box-shadow:0 4px 16px rgba(0,0,0,0.25); font-size:0.88rem; }
.qt-bulk-bar.qt-bulk-bar-visible { display:flex; }
.qt-bulk-bar-count { font-weight:700; color:#90cdf4; }
.qt-bulk-bar-action { padding:5px 14px; border-radius:5px; border:none; font-size:0.82rem; font-weight:700; cursor:pointer; }
.qt-bulk-bar-archive { background:#fed7d7; color:#9b2c2c; }
.qt-bulk-bar-archive:hover { background:#feb2b2; }
.qt-bulk-bar-restore { background:#c6f6d5; color:#276749; }
.qt-bulk-bar-restore:hover { background:#9ae6b4; }
.qt-bulk-bar-deselect { color:#a0aec0; text-decoration:underline; cursor:pointer; background:none; border:none; font-size:0.82rem; }
/* Bulk import modal */
.qt-bulk-import-modal { background:#fff; border-radius:8px; padding:24px 26px; min-width:340px; max-width:680px; width:95%; max-height:90vh; box-shadow:0 4px 24px rgba(0,0,0,0.18); display:flex; flex-direction:column; }
.qt-bulk-import-modal h4 { margin:0 0 14px; font-size:1rem; color:#2d3748; }
.qt-bulk-import-instructions { background:#f7fafc; border:1px solid #e2e8f0; border-radius:6px; padding:10px 14px; font-size:0.78rem; color:#4a5568; font-family:monospace; white-space:pre-line; margin-bottom:12px; line-height:1.6; }
.qt-bulk-import-preview { overflow-y:auto; flex:1; margin:12px 0; }
.qt-bulk-import-preview-q { border:1px solid #e2e8f0; border-radius:6px; padding:10px 12px; margin-bottom:8px; }
.qt-bulk-import-preview-q-text { font-weight:600; font-size:0.88rem; color:#2d3748; margin-bottom:6px; }
.qt-bulk-import-preview-a { font-size:0.8rem; color:#4a5568; line-height:1.5; padding-left:12px; }
.qt-bulk-import-preview-a.qt-correct { color:#276749; font-weight:600; }
.qt-bulk-import-error { background:#fed7d7; border:1px solid #fc8181; color:#9b2c2c; padding:6px 10px; border-radius:4px; font-size:0.82rem; margin-bottom:6px; }
.qt-bulk-import-success { color:#276749; font-weight:600; font-size:0.88rem; margin-bottom:8px; }
/* Test preview modal */
.qt-preview-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,0.5); z-index:9100; align-items:center; justify-content:center; }
.qt-preview-overlay.qt-open { display:flex; }
.qt-preview-modal { background:#fff; border-radius:8px; padding:24px 26px; max-width:720px; width:95%; max-height:85vh; display:flex; flex-direction:column; box-shadow:0 4px 24px rgba(0,0,0,0.18); }
.qt-preview-info { background:#ebf8ff; border:1px solid #bee3f8; border-radius:6px; padding:8px 14px; font-size:0.85rem; color:#2b6cb0; font-weight:600; margin-bottom:14px; flex-shrink:0; }
.qt-preview-body { overflow-y:auto; flex:1; }
.qt-preview-q { background:#fff; border:1px solid #e2e8f0; border-radius:8px; padding:14px 16px; margin-bottom:12px; }
.qt-preview-q-text { font-weight:700; font-size:0.92rem; color:#2d3748; margin-bottom:10px; }
.qt-preview-answer { padding:5px 10px; border-radius:4px; font-size:0.85rem; color:#4a5568; margin-bottom:4px; }
.qt-preview-correct { background:#c6f6d5; color:#276749; font-weight:600; }
.qt-preview-btn { padding:6px 14px; border-radius:5px; font-size:0.85rem; font-weight:600; cursor:pointer; border:none; }
.qt-preview-btn-secondary { background:#e2e8f0; color:#2d3748; }
.qt-preview-btn-secondary:hover { background:#cbd5e0; }
.qt-preview-btn-draw { background:#2b6cb0; color:#fff; }
.qt-preview-btn-draw:hover { background:#2c5282; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-scroll rp-header-icon"></i>
				<h1 class="rp-header-title"><?= $typeLabel ?> Questions</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-chess-rook rp-scope-chip-label"></i>
					<?= htmlspecialchars($KingdomName) ?>
				</span>
			</div>
		</div>
		<div class="rp-header-actions">
			<a class="rp-btn-ghost" href="<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $TestType ?>">
				<i class="fas fa-plus"></i> Add Question
			</a>
			<button class="rp-btn-ghost" id="qt-bulkimport-btn">
				<i class="fas fa-file-import"></i> Bulk Import
			</button>
			<?php if ($TestType === 'reeve' && !empty($Config['ShareQuestions'])): ?>
			<button class="rp-btn-ghost" id="qt-library-btn" style="background:#ebf8ff;color:#2b6cb0;border-color:#bee3f8;">
				<i class="fas fa-globe"></i> Add from Library
			</button>
			<?php endif; ?>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>
			<?= count($activeQs) ?> active question<?= count($activeQs) !== 1 ? 's' : '' ?> &mdash;
			test draws <?= (int)$Config['QuestionCount'] ?> at random,
			requires <?= (int)$Config['PassPercent'] ?>% to pass,
			valid for <?= (int)$Config['ValidDays'] ?> days.
		</span>
	</div>

	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sitemap"></i> Navigation</div>
				<div class="rp-filter-card-body" style="display:flex;flex-direction:column;gap:8px;">
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/manage/<?= $KingdomId ?>">
						<i class="fas fa-arrow-left"></i> Configure Tests
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $TestType === 'reeve' ? 'corpora' : 'reeve' ?>">
						<i class="fas fa-exchange-alt"></i> Switch to <?= $TestType === 'reeve' ? 'Corpora Test' : "Reeve's Test" ?>
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>Kingdom/profile/<?= $KingdomId ?>">
						<i class="fas fa-chess-rook"></i> Kingdom Profile
					</a>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-cog"></i> Test Configuration</div>
				<div class="rp-filter-card-body" style="font-size:13px;line-height:1.7;color:var(--rp-text-body);">
					<div><strong><?= (int)$Config['QuestionCount'] ?></strong> questions per test</div>
					<div><strong><?= (int)$Config['PassPercent'] ?>%</strong> required to pass</div>
					<div><strong><?= (int)$Config['ValidDays'] ?></strong> days validity</div>
					<div style="margin-top:8px;">
						<a href="<?= UIR ?>QualTest/manage/<?= $KingdomId ?>" style="font-size:12px;color:#2b6cb0;">
							<i class="fas fa-edit"></i> Edit settings
						</a>
					</div>
					<div style="margin-top:8px;">
						<button id="qt-preview-btn" class="qt-preview-btn qt-preview-btn-secondary" style="font-size:12px;width:100%;text-align:left;">
							<i class="fas fa-eye"></i> Preview Test
						</button>
					</div>
				</div>
			</div>
		</div><!-- /.rp-sidebar -->

		<!-- Table area -->
		<div class="rp-table-area">

			<!-- Active questions -->
			<div class="rp-table-section-title">
				Active Questions
				<span style="font-weight:400;font-size:13px;color:var(--rp-text-muted);margin-left:8px;">(<?= count($activeQs) ?>)</span>
			</div>

			<?php if (count($activeQs) > 0): ?>
			<table id="qt-active-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb" id="qt-active-select-all" title="Select all on this page"></th>
						<th>Question</th>
						<th>Answers</th>
						<th>% Success</th>
						<th>Added</th>
						<th style="width:140px">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($activeQs as $q): ?>
					<tr id="qrow-<?= $q['QualQuestionId'] ?>">
						<td class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb qt-active-cb" data-id="<?= $q['QualQuestionId'] ?>"></td>
						<td><?= htmlspecialchars($q['QuestionText']) ?></td>
						<td>
							<?php if ($q['CorrectCount'] > 0): ?>
								<span class="qt-badge qt-badge-green"><?= $q['AnswerCount'] ?> answers</span>
								<div class="qt-correct-answer"><i class="fas fa-check" style="color:#276749"></i> <?= htmlspecialchars($q['CorrectText']) ?></div>
							<?php else: ?>
								<span class="qt-badge qt-badge-red"><?= $q['AnswerCount'] ?> &mdash; no correct!</span>
							<?php endif; ?>
						</td>
						<td><?php
							$ta = $q['TimesAnswered'];
							if ($ta > 0) {
								$pct = round($q['TimesCorrect'] / $ta * 100);
								$cls = $pct >= 81 ? 'qt-success-green' : ($pct >= 61 ? 'qt-success-yellow' : 'qt-success-red');
								echo '<span class="qt-success-badge ' . $cls . '">' . $pct . '%</span>';
								echo '<div style="font-size:0.72rem;color:var(--rp-text-muted);margin-top:2px;">' . $q['TimesCorrect'] . '/' . $ta . '</div>';
							} else {
								echo '<span class="qt-success-badge qt-success-none">—</span>';
							}
						?></td>
						<td><?= date('Y-m-d', strtotime($q['CreatedAt'])) ?></td>
						<td>
							<div class="qt-actions-cell">
								<a class="qt-action-btn qt-action-btn-edit"
								   href="<?= UIR ?>QualTest/question/edit/<?= $q['QualQuestionId'] ?>">
									<i class="fas fa-edit"></i> Edit
								</a>
								<button class="qt-action-btn qt-action-btn-archive qt-status-btn" data-tip="Archive"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>"
								        data-status="archived">
									<i class="fas fa-archive"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-reset qt-reset-btn" data-tip="Reset Stats"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-sync-alt"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-dup qt-dup-btn" data-tip="Duplicate"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-copy"></i>
								</button>
								<?php if ($q['ReportCount'] > 0): ?>
								<button class="qt-flag-btn qt-report-flag-btn" data-tip="<?= $q['ReportCount'] ?> report<?= $q['ReportCount'] !== 1 ? 's' : '' ?>"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-flag"></i>
								</button>
								<?php endif; ?>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			<?php else: ?>
			<div class="rp-empty-state">
				<i class="fas fa-scroll"></i>
				No active questions yet.
				<a href="<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $TestType ?>">Add the first one.</a>
			</div>
			<?php endif; ?>

			<?php if (count($archivedQs) > 0): ?>
			<!-- Archived questions (collapsible) -->
			<div class="rp-table-section-title" style="margin-top:32px;cursor:pointer;user-select:none;" id="qt-archived-toggle">
				<i class="fas fa-chevron-right" id="qt-archived-chevron" style="font-size:0.75em;margin-right:4px;transition:transform 0.2s;"></i>
				Archived Questions
				<span style="font-weight:400;font-size:13px;color:var(--rp-text-muted);margin-left:8px;">(<?= count($archivedQs) ?>)</span>
			</div>
			<div id="qt-archived-panel" style="display:none;">
			<table id="qt-archived-table" class="dataTable" style="width:100%">
				<thead>
					<tr>
						<th class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb" id="qt-archived-select-all" title="Select all on this page"></th>
						<th>Question</th>
						<th>Answers</th>
						<th>% Success</th>
						<th>Added</th>
						<th style="width:140px">Actions</th>
					</tr>
				</thead>
				<tbody>
				<?php foreach ($archivedQs as $q): ?>
					<tr id="qrow-<?= $q['QualQuestionId'] ?>">
						<td class="qt-bulk-cb-th"><input type="checkbox" class="qt-bulk-cb qt-archived-cb" data-id="<?= $q['QualQuestionId'] ?>"></td>
						<td style="color:var(--rp-text-muted)"><?= htmlspecialchars($q['QuestionText']) ?></td>
						<td>
							<span class="qt-badge qt-badge-gray"><?= $q['AnswerCount'] ?> answers</span>
							<?php if (!empty($q['CorrectText'])): ?>
							<div class="qt-correct-answer" style="color:var(--rp-text-muted)"><?= htmlspecialchars($q['CorrectText']) ?></div>
							<?php endif; ?>
						</td>
						<td><?php
							$ta = $q['TimesAnswered'];
							if ($ta > 0) {
								$pct = round($q['TimesCorrect'] / $ta * 100);
								$cls = $pct >= 81 ? 'qt-success-green' : ($pct >= 61 ? 'qt-success-yellow' : 'qt-success-red');
								echo '<span class="qt-success-badge ' . $cls . '">' . $pct . '%</span>';
								echo '<div style="font-size:0.72rem;color:var(--rp-text-muted);margin-top:2px;">' . $q['TimesCorrect'] . '/' . $ta . '</div>';
							} else {
								echo '<span class="qt-success-badge qt-success-none">—</span>';
							}
						?></td>
						<td><?= date('Y-m-d', strtotime($q['CreatedAt'])) ?></td>
						<td>
							<div class="qt-actions-cell">
								<a class="qt-action-btn qt-action-btn-edit"
								   href="<?= UIR ?>QualTest/question/edit/<?= $q['QualQuestionId'] ?>">
									<i class="fas fa-edit"></i> Edit
								</a>
								<button class="qt-action-btn qt-action-btn-restore qt-status-btn" data-tip="Restore"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>"
								        data-status="active">
									<i class="fas fa-check-circle"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-reset qt-reset-btn" data-tip="Reset Stats"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-sync-alt"></i>
								</button>
								<button class="qt-action-btn qt-action-btn-dup qt-dup-btn" data-tip="Duplicate"
								        data-id="<?= $q['QualQuestionId'] ?>"
								        data-kingdom="<?= $KingdomId ?>">
									<i class="fas fa-copy"></i>
								</button>
							</div>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
			</div><!-- /#qt-archived-panel -->
			<?php endif; ?>

		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<!-- Bulk action bar -->
<div class="qt-bulk-bar" id="qt-bulk-bar">
	<span class="qt-bulk-bar-count" id="qt-bulk-count">0 selected</span>
	<button class="qt-bulk-bar-action qt-bulk-bar-archive" id="qt-bulk-archive" style="display:none;">
		<i class="fas fa-archive"></i> Archive Selected
	</button>
	<button class="qt-bulk-bar-action qt-bulk-bar-restore" id="qt-bulk-restore" style="display:none;">
		<i class="fas fa-check-circle"></i> Restore Selected
	</button>
	<button class="qt-bulk-bar-deselect" id="qt-bulk-deselect">Deselect All</button>
</div>

<!-- Test Preview modal -->
<div class="qt-preview-overlay" id="qt-preview-overlay">
	<div class="qt-preview-modal">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-eye" style="color:#2b6cb0;margin-right:6px;"></i> Test Preview &mdash; <?= $typeLabel ?></h4>
			<button id="qt-preview-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div class="qt-preview-info" id="qt-preview-info"></div>
		<div class="qt-preview-body" id="qt-preview-body">
			<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading preview&hellip;</div>
		</div>
		<div style="display:flex;gap:8px;margin-top:14px;flex-shrink:0;">
			<button class="qt-preview-btn qt-preview-btn-draw" id="qt-preview-draw" style="display:none;"><i class="fas fa-random"></i> Draw Again</button>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-preview-close-footer">Close</button>
		</div>
	</div>
</div>

<!-- Bulk Import modal -->
<div class="qt-report-overlay" id="qt-bulkimport-overlay">
	<div class="qt-bulk-import-modal">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-file-import" style="color:#2b6cb0;margin-right:6px;"></i> Bulk Import Questions</h4>
			<button id="qt-bulkimport-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div class="qt-bulk-import-instructions">Paste questions separated by a blank line.
First line = question text.
Subsequent lines = answers (prefix with * for correct).
Letter prefixes like A) B) are optional and stripped.

Example:
What color is the sky?
A) Green
*B) Blue
C) Red

Who wrote Hamlet?
*A) Shakespeare
B) Dickens</div>
		<textarea id="qt-bulkimport-text" aria-label="Paste questions here" rows="8" placeholder="Paste your questions here..." style="width:100%;box-sizing:border-box;padding:8px 10px;border:1px solid #cbd5e0;border-radius:4px;font-size:0.88rem;font-family:inherit;resize:vertical;flex-shrink:0;"></textarea>
		<div class="qt-bulk-import-preview" id="qt-bulkimport-preview"></div>
		<div style="display:flex;gap:8px;margin-top:10px;flex-shrink:0;">
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-bulkimport-parse"><i class="fas fa-search"></i> Parse &amp; Preview</button>
			<button class="qt-preview-btn qt-preview-btn-draw" id="qt-bulkimport-submit" disabled><i class="fas fa-file-import"></i> Import Questions</button>
			<button class="qt-preview-btn qt-preview-btn-secondary" id="qt-bulkimport-cancel">Close</button>
		</div>
	</div>
</div>

<!-- Global Question Library modal -->
<?php if ($TestType === 'reeve' && !empty($Config['ShareQuestions'])): ?>
<div class="qt-report-overlay" id="qt-library-overlay">
	<div class="qt-report-modal" style="max-width:720px;width:95%;max-height:85vh;display:flex;flex-direction:column;">
		<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
			<h4 style="margin:0;"><i class="fas fa-globe" style="color:#2b6cb0;margin-right:6px;"></i> Global Question Library</h4>
			<button id="qt-library-close" aria-label="Close" style="background:none;border:none;font-size:1.4rem;cursor:pointer;color:#718096;line-height:1;">&times;</button>
		</div>
		<div id="qt-library-search-wrap" style="display:none;flex-shrink:0;margin-bottom:12px;">
			<input type="text" id="qt-library-search" placeholder="Filter by question text or kingdom&hellip;" autocomplete="off"
			       style="width:100%;box-sizing:border-box;padding:8px 12px;border:1px solid #cbd5e0;border-radius:6px;font-size:0.88rem;outline:none;">
		</div>
		<div id="qt-library-loading" style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading library&hellip;</div>
		<div id="qt-library-empty" style="display:none;text-align:center;padding:32px;color:#718096;">No questions available from other kingdoms yet.</div>
		<div id="qt-library-body" style="display:none;overflow-y:auto;flex:1;">
			<div id="qt-library-list"></div>
		</div>
	</div>
</div>
<?php endif; ?>

<!-- Report popup -->
<div class="qt-report-overlay" id="qt-report-overlay">
	<div class="qt-report-modal">
		<h4><i class="fas fa-flag"></i> Question Reports</h4>
		<div id="qt-report-loading" style="font-size:0.88rem;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading&hellip;</div>
		<div id="qt-report-body" style="display:none;">
			<div class="qt-report-reason-row"><span>Question is worded poorly</span><span class="qt-report-count" id="qr-wording">0</span></div>
			<div class="qt-report-reason-row"><span>My answer was correct</span><span class="qt-report-count" id="qr-correct">0</span></div>
			<div class="qt-report-reason-row"><span>Not updated for recent changes</span><span class="qt-report-count" id="qr-outdated">0</span></div>
			<div class="qt-report-reason-row"><span>Other</span><span class="qt-report-count" id="qr-other">0</span></div>
		</div>
		<div class="qt-report-footer">
			<button class="qt-action-btn" id="qt-report-close" style="background:#e2e8f0;color:#2d3748;">Close</button>
			<button class="qt-action-btn" id="qt-report-clear" style="display:none;background:#fff5f5;color:#c53030;border:1px solid #feb2b2;"><i class="fas fa-times"></i> Clear Flags</button>
			<button class="qt-action-btn qt-action-btn-archive" id="qt-report-archive" style="display:none;"><i class="fas fa-archive"></i> Archive Question</button>
			<a class="qt-action-btn qt-action-btn-edit" id="qt-report-edit" href="#" style="display:none;"><i class="fas fa-edit"></i> Edit Question</a>
		</div>
	</div>
</div>

<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script>
$(function() {
	var dtOpts = { pageLength: 25, order: [[4, 'desc']], columnDefs: [{ orderable: false, targets: [0, 5] }] };
	var activeTable = null, archivedTable = null;

	if ($('#qt-active-table tbody tr').length > 0) {
		activeTable = $('#qt-active-table').DataTable(dtOpts);
	}
	// Archived table init deferred until panel is first shown (DataTables + display:none = broken widths)
	var archivedInited = false;

	// ----- Archived panel toggle -----
	var archivedToggle  = document.getElementById('qt-archived-toggle');
	var archivedPanel   = document.getElementById('qt-archived-panel');
	var archivedChevron = document.getElementById('qt-archived-chevron');
	if (archivedToggle) {
		archivedToggle.addEventListener('click', function() {
			var open = archivedPanel.style.display === 'none';
			archivedPanel.style.display = open ? '' : 'none';
			archivedChevron.style.transform = open ? 'rotate(90deg)' : '';
			if (open && !archivedInited && $('#qt-archived-table tbody tr').length > 0) {
				archivedTable = $('#qt-archived-table').DataTable(dtOpts);
				archivedInited = true;
				// Bind checkbox events for the newly-inited table
				archivedTable.on('draw.dt', function() {
					var t = document.getElementById('qt-archived-table');
					rebindCheckboxes(t, archivedSelected, 'qt-archived-cb');
					rebindSelectAll('qt-archived-select-all', t, archivedSelected, 'qt-archived-cb');
				});
				archivedTable.draw();
			}
		});
	}

	// ----- Bulk checkbox selection -----
	var activeSelected   = new Set();
	var archivedSelected = new Set();
	var bulkBar      = document.getElementById('qt-bulk-bar');
	var bulkCount    = document.getElementById('qt-bulk-count');
	var bulkArchive  = document.getElementById('qt-bulk-archive');
	var bulkRestore  = document.getElementById('qt-bulk-restore');
	var bulkDeselect = document.getElementById('qt-bulk-deselect');

	function updateBulkBar() {
		var ac = activeSelected.size, ar = archivedSelected.size, total = ac + ar;
		if (total === 0) {
			bulkBar.classList.remove('qt-bulk-bar-visible');
			return;
		}
		bulkBar.classList.add('qt-bulk-bar-visible');
		bulkCount.textContent = total + ' selected';
		bulkArchive.style.display = ac > 0 ? '' : 'none';
		bulkRestore.style.display = ar > 0 ? '' : 'none';
	}

	function rebindCheckboxes(tableEl, selectedSet, cbClass) {
		if (!tableEl) return;
		tableEl.querySelectorAll('.' + cbClass).forEach(function(cb) {
			var id = parseInt(cb.dataset.id, 10);
			cb.checked = selectedSet.has(id);
			cb.onchange = function() {
				if (cb.checked) selectedSet.add(id); else selectedSet.delete(id);
				updateBulkBar();
			};
		});
	}

	function rebindSelectAll(headerId, tableEl, selectedSet, cbClass) {
		var sa = document.getElementById(headerId);
		if (!sa) return;
		sa.onchange = function() {
			var cbs = tableEl.querySelectorAll('.' + cbClass);
			cbs.forEach(function(cb) {
				var id = parseInt(cb.dataset.id, 10);
				cb.checked = sa.checked;
				if (sa.checked) selectedSet.add(id); else selectedSet.delete(id);
			});
			updateBulkBar();
		};
	}

	if (activeTable) {
		activeTable.on('draw.dt', function() {
			var t = document.getElementById('qt-active-table');
			rebindCheckboxes(t, activeSelected, 'qt-active-cb');
			rebindSelectAll('qt-active-select-all', t, activeSelected, 'qt-active-cb');
		});
		activeTable.draw();
	}
	// Archived table draw.dt binding is deferred — see toggle handler above

	bulkDeselect.addEventListener('click', function() {
		activeSelected.clear(); archivedSelected.clear();
		document.querySelectorAll('.qt-bulk-cb').forEach(function(cb) { cb.checked = false; });
		updateBulkBar();
	});

	function doBulkStatus(ids, status) {
		if (!ids.length) return;
		var label = status === 'archived' ? 'archive' : 'restore';
		if (!confirm(label.charAt(0).toUpperCase() + label.slice(1) + ' ' + ids.length + ' question(s)?')) return;
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fd.append('QuestionIds', JSON.stringify(ids));
		fd.append('Status', status);
		fetch('<?= UIR ?>QualTestAjax/bulkstatus', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status === 0) window.location.reload();
				else alert(j.error || 'Error updating status.');
			});
	}

	bulkArchive.addEventListener('click', function() { doBulkStatus([...activeSelected], 'archived'); });
	bulkRestore.addEventListener('click', function() { doBulkStatus([...archivedSelected], 'active'); });

	// ----- Single status toggle -----
	document.querySelectorAll('.qt-status-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			var newStatus = btn.dataset.status;
			var label = newStatus === 'archived' ? 'archive' : 'restore';
			if (!confirm('Are you sure you want to ' + label + ' this question?')) return;
			var fd = new FormData();
			fd.append('KingdomId',  btn.dataset.kingdom);
			fd.append('QuestionId', btn.dataset.id);
			fd.append('Status',     newStatus);
			fetch('<?= UIR ?>QualTestAjax/setstatus', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j.status === 0) { window.location.reload(); }
					else { alert(j.error || 'Error updating status.'); }
				});
		});
	});

	// ----- Reset stats -----
	document.querySelectorAll('.qt-reset-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			if (!confirm('Reset Success Rate for this Question?')) return;
			var row = btn.closest('tr');
			var fd = new FormData();
			fd.append('KingdomId',  btn.dataset.kingdom);
			fd.append('QuestionId', btn.dataset.id);
			fetch('<?= UIR ?>QualTestAjax/resetstats', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j.status !== 0) { alert(j.error || 'Error resetting stats.'); return; }
					var cells = row.querySelectorAll('td');
					if (cells[3]) cells[3].innerHTML = '<span class="qt-success-badge qt-success-none">\u2014</span>';
				});
		});
	});

	// ----- Duplicate -----
	document.querySelectorAll('.qt-dup-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			if (!confirm('Duplicate this question?')) return;
			btn.disabled = true;
			var fd = new FormData();
			fd.append('KingdomId',  btn.dataset.kingdom);
			fd.append('QuestionId', btn.dataset.id);
			fetch('<?= UIR ?>QualTestAjax/duplicatequestion', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j.status === 0) window.location = '<?= UIR ?>QualTest/question/edit/' + j.new_question_id;
					else { alert(j.error || 'Error duplicating question.'); btn.disabled = false; }
				});
		});
	});
});

// ----- Test Preview -----
(function() {
	var previewBtn    = document.getElementById('qt-preview-btn');
	if (!previewBtn) return;
	var overlay       = document.getElementById('qt-preview-overlay');
	var infoEl        = document.getElementById('qt-preview-info');
	var bodyEl        = document.getElementById('qt-preview-body');
	var drawBtn       = document.getElementById('qt-preview-draw');
	var closeBtn      = document.getElementById('qt-preview-close');
	var closeFooter   = document.getElementById('qt-preview-close-footer');

	function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
	var letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ';

	function fetchPreview() {
		bodyEl.innerHTML = '<div style="text-align:center;padding:32px;color:#718096;"><i class="fas fa-spinner fa-spin"></i> Loading preview&hellip;</div>';
		drawBtn.style.display = 'none';
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fd.append('TestType',  '<?= $TestType ?>');
		fetch('<?= UIR ?>QualTestAjax/previewtest', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(data) {
				if (data.status !== 0) { bodyEl.innerHTML = '<div style="color:#e53e3e;padding:16px;">' + escH(data.error || 'Error') + '</div>'; return; }
				infoEl.textContent = data.question_count + ' questions drawn \u2014 requires ' + data.pass_percent + '% to pass';
				var html = '';
				data.questions.forEach(function(q, qi) {
					html += '<div class="qt-preview-q"><div class="qt-preview-q-text">' + (qi+1) + '. ' + escH(q.QuestionText) + '</div>';
					q.Answers.forEach(function(a, ai) {
						var cls = a.IsCorrect ? ' qt-preview-correct' : '';
						html += '<div class="qt-preview-answer' + cls + '">' + letters[ai] + ') ' + escH(a.AnswerText);
						if (a.IsCorrect) html += ' <i class="fas fa-check"></i>';
						html += '</div>';
					});
					html += '</div>';
				});
				bodyEl.innerHTML = html;
				drawBtn.style.display = '';
			});
	}

	previewBtn.addEventListener('click', function() { overlay.classList.add('qt-open'); fetchPreview(); });
	drawBtn.addEventListener('click', fetchPreview);
	function closePreview() { overlay.classList.remove('qt-open'); }
	closeBtn.addEventListener('click', closePreview);
	closeFooter.addEventListener('click', closePreview);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closePreview(); });
})();

// ----- Bulk Import -----
(function() {
	var openBtn   = document.getElementById('qt-bulkimport-btn');
	if (!openBtn) return;
	var overlay   = document.getElementById('qt-bulkimport-overlay');
	var closeBtn  = document.getElementById('qt-bulkimport-close');
	var cancelBtn = document.getElementById('qt-bulkimport-cancel');
	var parseBtn  = document.getElementById('qt-bulkimport-parse');
	var submitBtn = document.getElementById('qt-bulkimport-submit');
	var textarea  = document.getElementById('qt-bulkimport-text');
	var previewEl = document.getElementById('qt-bulkimport-preview');
	var parsedQuestions = [];
	var importedCount = 0;

	function escH(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }

	function parseQuestions(raw) {
		var blocks = raw.split(/\n\s*\n/).map(function(b) { return b.trim(); }).filter(Boolean);
		var questions = [], errors = [];
		blocks.forEach(function(block, bi) {
			var lines = block.split('\n').map(function(l) { return l.trim(); }).filter(Boolean);
			if (lines.length < 2) { errors.push('Block ' + (bi+1) + ': needs question + at least 2 answers.'); return; }
			var qText = lines[0].replace(/^\*/, ''); // strip leading * from question text
			var answers = [], correctCount = 0;
			for (var i = 1; i < lines.length; i++) {
				var line = lines[i];
				var isCorrect = line.charAt(0) === '*';
				if (isCorrect) { line = line.substring(1).trim(); correctCount++; }
				line = line.replace(/^[A-Za-z]\)\s*/, '');
				if (line) answers.push({ AnswerText: line, IsCorrect: isCorrect ? 1 : 0 });
			}
			if (answers.length < 2) { errors.push('Block ' + (bi+1) + ': at least 2 answers required.'); return; }
			if (correctCount !== 1) { errors.push('Block ' + (bi+1) + ': exactly 1 correct answer required (found ' + correctCount + ').'); return; }
			questions.push({ QuestionText: qText, Answers: answers });
		});
		return { questions: questions, errors: errors };
	}

	parseBtn.addEventListener('click', function() {
		var result = parseQuestions(textarea.value);
		parsedQuestions = result.questions;
		var html = '';
		result.errors.forEach(function(e) { html += '<div class="qt-bulk-import-error">' + escH(e) + '</div>'; });
		if (parsedQuestions.length > 0) {
			if (parsedQuestions.length > 200) {
				html += '<div class="qt-bulk-import-error">Maximum 200 questions per batch. You have ' + parsedQuestions.length + '.</div>';
				parsedQuestions = parsedQuestions.slice(0, 200);
			}
			html += '<div class="qt-bulk-import-success">' + parsedQuestions.length + ' question(s) ready to import</div>';
			parsedQuestions.forEach(function(q, qi) {
				html += '<div class="qt-bulk-import-preview-q"><div class="qt-bulk-import-preview-q-text">' + (qi+1) + '. ' + escH(q.QuestionText) + '</div>';
				q.Answers.forEach(function(a) {
					html += '<div class="qt-bulk-import-preview-a' + (a.IsCorrect ? ' qt-correct' : '') + '">';
					html += (a.IsCorrect ? '<i class="fas fa-check" style="margin-right:4px;color:#276749;"></i>' : '&bull; ') + escH(a.AnswerText) + '</div>';
				});
				html += '</div>';
			});
			submitBtn.disabled = false;
			submitBtn.textContent = 'Import ' + parsedQuestions.length + ' Questions';
		} else {
			submitBtn.disabled = true;
			submitBtn.textContent = 'Import Questions';
		}
		previewEl.innerHTML = html;
	});

	submitBtn.addEventListener('click', function() {
		if (!parsedQuestions.length) return;
		submitBtn.disabled = true;
		submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fd.append('TestType',  '<?= $TestType ?>');
		fd.append('Questions', JSON.stringify(parsedQuestions));
		fetch('<?= UIR ?>QualTestAjax/bulkimport', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				var html = '';
				if (j.status !== 0) {
					html = '<div class="qt-bulk-import-error">' + escH(j.error || 'Import failed.') + '</div>';
				} else {
					importedCount += j.imported;
					html = '<div class="qt-bulk-import-success">' + j.imported + ' question(s) imported successfully!</div>';
					if (j.errors && j.errors.length) {
						j.errors.forEach(function(e) {
							html += '<div class="qt-bulk-import-error">Question ' + escH(String(e.index + 1)) + ': ' + escH(e.error) + '</div>';
						});
					}
				}
				previewEl.innerHTML = html;
				submitBtn.innerHTML = '<i class="fas fa-check"></i> Done';
				if (importedCount > 0) cancelBtn.innerHTML = '<i class="fas fa-sync-alt"></i> Close &amp; Reload';
			});
	});

	function resetModal() {
		textarea.value = '';
		previewEl.innerHTML = '';
		parsedQuestions = [];
		submitBtn.disabled = true;
		submitBtn.textContent = 'Import Questions';
	}

	openBtn.addEventListener('click', function() {
		resetModal();
		importedCount = 0;
		cancelBtn.innerHTML = 'Close';
		overlay.classList.add('qt-open');
	});

	function closeModal() {
		overlay.classList.remove('qt-open');
		if (importedCount > 0) window.location.reload();
	}
	closeBtn.addEventListener('click', closeModal);
	cancelBtn.addEventListener('click', closeModal);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closeModal(); });
})();

// ----- Global Question Library -----
(function() {
	var libBtn     = document.getElementById('qt-library-btn');
	if (!libBtn) return; // not opted in

	var overlay      = document.getElementById('qt-library-overlay');
	var closeBtn     = document.getElementById('qt-library-close');
	var loadingEl    = document.getElementById('qt-library-loading');
	var emptyEl      = document.getElementById('qt-library-empty');
	var bodyEl       = document.getElementById('qt-library-body');
	var listEl       = document.getElementById('qt-library-list');
	var searchEl     = document.getElementById('qt-library-search');
	var searchWrapEl = document.getElementById('qt-library-search-wrap');
	var loaded     = false;
	var allQuestions = [];

	function escH(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function renderList(questions) {
		if (!questions.length) { listEl.innerHTML = '<div style="text-align:center;padding:16px;color:#718096;">No matches.</div>'; return; }
		listEl.innerHTML = questions.map(function(q) {
			var answers = q.Answers.map(function(a) {
				return '<div class="qt-lib-answer' + (a.IsCorrect ? ' qt-lib-correct' : '') + '">'
					+ (a.IsCorrect ? '<i class="fas fa-check" style="margin-right:4px;"></i>' : '&bull; ')
					+ escH(a.AnswerText) + '</div>';
			}).join('');
			return '<div class="qt-lib-question" data-qid="' + q.QualQuestionId + '">'
				+ '<div class="qt-lib-question-hdr">'
					+ '<div><div class="qt-lib-question-text">' + escH(q.QuestionText) + '</div>'
					+ '<div class="qt-lib-kingdom"><i class="fas fa-chess-rook" style="margin-right:3px;"></i>' + escH(q.KingdomName) + '</div></div>'
					+ '<button class="qt-lib-add-btn" data-qid="' + q.QualQuestionId + '"><i class="fas fa-plus"></i> Add</button>'
				+ '</div>'
				+ '<div class="qt-lib-answers">' + answers + '</div>'
			+ '</div>';
		}).join('');
		listEl.querySelectorAll('.qt-lib-add-btn').forEach(function(btn) {
			btn.addEventListener('click', function() {
				btn.disabled = true;
				btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
				var fd = new FormData();
				fd.append('KingdomId',  '<?= (int)$KingdomId ?>');
				fd.append('QuestionId', btn.dataset.qid);
				fetch('<?= UIR ?>QualTestAjax/copyfromlibrary', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.status !== 0) { alert(j.error || 'Error adding question.'); btn.disabled = false; btn.innerHTML = '<i class="fas fa-plus"></i> Add'; return; }
						btn.innerHTML = '<i class="fas fa-check"></i> Added';
						btn.style.background = '#276749';
						addedCount++;
					});
			});
		});
	}

	libBtn.addEventListener('click', function() {
		overlay.classList.add('qt-open');
		if (loaded) return;
		var fd = new FormData();
		fd.append('KingdomId', '<?= (int)$KingdomId ?>');
		fetch('<?= UIR ?>QualTestAjax/getlibrary', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				loaded = true;
				loadingEl.style.display = 'none';
				if (j.status !== 0) { emptyEl.textContent = j.error || 'Error loading library.'; emptyEl.style.display = 'block'; return; }
				allQuestions = j.questions || [];
				if (!allQuestions.length) { emptyEl.style.display = 'block'; return; }
				searchWrapEl.style.display = 'block';
				bodyEl.style.display = 'block';
				renderList(allQuestions);
				searchEl.focus();
			});
	});

	searchEl.addEventListener('input', function() {
		var q = searchEl.value.trim().toLowerCase();
		var filtered = !q ? allQuestions : allQuestions.filter(function(item) {
			return item.QuestionText.toLowerCase().indexOf(q) !== -1
				|| item.KingdomName.toLowerCase().indexOf(q) !== -1;
		});
		renderList(filtered);
	});

	var addedCount = 0;
	function closeLibrary() {
		overlay.classList.remove('qt-open');
		if (addedCount > 0) { window.location.reload(); }
	}
	closeBtn.addEventListener('click', closeLibrary);
	overlay.addEventListener('click', function(e) { if (e.target === overlay) closeLibrary(); });
})();

// ----- Report flag popup -----
(function() {
	var overlay    = document.getElementById('qt-report-overlay');
	var loadingEl  = document.getElementById('qt-report-loading');
	var bodyEl     = document.getElementById('qt-report-body');
	var closeBtn   = document.getElementById('qt-report-close');
	var archiveBtn = document.getElementById('qt-report-archive');
	var editLink   = document.getElementById('qt-report-edit');
	var clearBtn   = document.getElementById('qt-report-clear');
	var currentQid = 0;
	var currentKid = 0;

	function openReportPopup(qid, kid) {
		currentQid = qid;
		currentKid = kid;
		loadingEl.style.display = 'block';
		bodyEl.style.display    = 'none';
		archiveBtn.style.display = 'inline-block';
		archiveBtn.dataset.id     = qid;
		archiveBtn.dataset.kingdom = kid;
		editLink.style.display   = 'inline-block';
		clearBtn.style.display   = 'inline-block';
		editLink.href = '<?= UIR ?>QualTest/question/edit/' + qid;
		overlay.classList.add('qt-open');

		var fd = new FormData();
		fd.append('KingdomId',  kid);
		fd.append('QuestionId', qid);
		fetch('<?= UIR ?>QualTestAjax/getreports', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				loadingEl.style.display = 'none';
				if (j.status !== 0) { bodyEl.innerHTML = '<span style="color:#e53e3e">Error loading reports.</span>'; bodyEl.style.display = 'block'; return; }
				document.getElementById('qr-wording').textContent  = j.counts.wording;
				document.getElementById('qr-correct').textContent  = j.counts.correct;
				document.getElementById('qr-outdated').textContent = j.counts.outdated;
				document.getElementById('qr-other').textContent    = j.counts.other;
				bodyEl.style.display = 'block';
			});
	}

	document.querySelectorAll('.qt-report-flag-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			openReportPopup(parseInt(btn.dataset.id, 10), parseInt(btn.dataset.kingdom, 10));
		});
	});

	closeBtn.addEventListener('click', function() { overlay.classList.remove('qt-open'); });
	overlay.addEventListener('click', function(e) { if (e.target === overlay) overlay.classList.remove('qt-open'); });

	clearBtn.addEventListener('click', function() {
		if (!confirm('Clear all flags for this question?')) return;
		clearBtn.disabled = true;
		var fd = new FormData();
		fd.append('KingdomId',  currentKid);
		fd.append('QuestionId', currentQid);
		fetch('<?= UIR ?>QualTestAjax/clearreports', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status !== 0) { alert(j.error || 'Error clearing flags.'); clearBtn.disabled = false; return; }
				window.location.reload();
			});
	});

	archiveBtn.addEventListener('click', function() {
		if (!confirm('Archive this question and clear its reports?')) return;
		var fd = new FormData();
		fd.append('KingdomId',  currentKid);
		fd.append('QuestionId', currentQid);
		fd.append('Status', 'archived');
		fetch('<?= UIR ?>QualTestAjax/setstatus', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status !== 0) { alert(j.error || 'Error archiving.'); return; }
				var fd2 = new FormData();
				fd2.append('KingdomId',  currentKid);
				fd2.append('QuestionId', currentQid);
				fetch('<?= UIR ?>QualTestAjax/clearreports', { method: 'POST', body: fd2 })
					.finally(function() { window.location.reload(); });
			});
	});
})();
</script>
