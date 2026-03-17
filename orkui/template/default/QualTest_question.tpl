<?php
	if (strlen($Error ?? '') > 0) {
		echo '<div class="error-message">' . htmlspecialchars($Error) . '</div>';
		return;
	}
	$typeLabel  = ($TestType === 'corpora') ? 'Corpora Test' : "Reeve's Test";
	$isEdit     = ($Action === 'edit' && $Question !== null);
	$q          = $Question ?? ['QuestionText' => '', 'Answers' => []];
	$answers    = $q['Answers'];
	while (count($answers) < 4) { $answers[] = ['QualAnswerId' => 0, 'AnswerText' => '', 'IsCorrect' => false]; }
	$correctIdx = 0;
	foreach ($answers as $i => $a) { if (!empty($a['IsCorrect'])) { $correctIdx = $i; break; } }
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
.qt-nav-link { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 5px; font-size: 13px; font-weight: 600; color: #2b6cb0; text-decoration: none; transition: background 0.15s; }
.qt-nav-link:hover { background: #ebf4ff; border-color: #bee3f8; color: #2c5282; }
</style>

<style>
.qt-form-card { background: #fff; border: 1px solid var(--rp-border); border-radius: 8px; padding: 22px 24px; }
.qt-field { margin-bottom: 18px; }
.qt-field label { display: block; font-size: 0.78rem; font-weight: 700; text-transform: uppercase;
                  letter-spacing: 0.05em; color: var(--rp-text-muted); margin-bottom: 6px; }
.qt-field textarea, .qt-field input[type=text] {
	width: 100%; padding: 8px 10px; border: 1px solid var(--rp-border); border-radius: 4px;
	font-size: 0.9rem; font-family: inherit; box-sizing: border-box; color: var(--rp-text);
}
.qt-field textarea { min-height: 80px; resize: vertical; }
.qt-field textarea:focus, .qt-field input[type=text]:focus { outline: none; border-color: #6366f1; }
.qt-answers-list { list-style: none; padding: 0; margin: 0; }
.qt-answer-row { display: flex; align-items: center; gap: 8px; margin-bottom: 8px; }
.qt-answer-row input[type=radio] { width: 16px; height: 16px; accent-color: #2b6cb0; flex-shrink: 0; }
.qt-answer-row input[type=text] { flex: 1; padding: 7px 10px; border: 1px solid var(--rp-border);
                                   border-radius: 4px; font-size: 0.9rem; }
.qt-remove-btn { background: none; border: none; color: #c05621; cursor: pointer; font-size: 1rem; padding: 2px 4px; }
.qt-remove-btn:hover { color: #9c4221; }
.qt-add-answer-btn { background: none; border: none; color: #2b6cb0; cursor: pointer; font-size: 0.82rem;
                     text-decoration: underline; padding: 0; margin-top: 4px; }
.qt-field-hint { font-size: 0.75rem; color: var(--rp-text-muted); margin-top: 4px; }
.qt-form-actions { display: flex; gap: 10px; margin-top: 22px; padding-top: 18px; border-top: 1px solid var(--rp-border); }
.qt-submit-btn { padding: 8px 22px; background: #2b6cb0; color: #fff; border: none; border-radius: 4px;
                 font-size: 0.9rem; font-weight: 600; cursor: pointer; }
.qt-submit-btn:hover { background: #2c5282; }
.qt-submit-btn-ghost { background: transparent; color: #2b6cb0; border: 1px solid #2b6cb0; }
.qt-submit-btn-ghost:hover { background: #ebf4ff; }
.qt-cancel-btn { display: inline-block; padding: 8px 18px; background: #e2e8f0; color: #2d3748;
                 border-radius: 4px; font-size: 0.9rem; font-weight: 600; text-decoration: none; }
.qt-cancel-btn:hover { background: #cbd5e0; }
.qt-error-banner { background: #fed7d7; border: 1px solid #fc8181; color: #9b2c2c; padding: 10px 14px;
                   border-radius: 4px; font-size: 0.88rem; margin-bottom: 16px; display: none; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-<?= $isEdit ? 'edit' : 'plus-circle' ?> rp-header-icon"></i>
				<h1 class="rp-header-title"><?= $isEdit ? 'Edit Question' : 'New Question' ?></h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-scroll rp-scope-chip-label"></i>
					<?= $typeLabel ?>
				</span>
				<span class="rp-scope-chip">
					<i class="fas fa-chess-rook rp-scope-chip-label"></i>
					<?= htmlspecialchars($KingdomName) ?>
				</span>
			</div>
		</div>
	</div>

	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sitemap"></i> Navigation</div>
				<div class="rp-filter-card-body" style="display:flex;flex-direction:column;gap:8px;">
					<a class="qt-nav-link"
					   href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $TestType ?>">
						<i class="fas fa-arrow-left"></i> Back to Questions
					</a>
					<a class="qt-nav-link"
					   href="<?= UIR ?>QualTest/manage/<?= $KingdomId ?>">
						<i class="fas fa-cog"></i> Configure Tests
					</a>
					<a class="qt-nav-link"
					   href="<?= UIR ?>Kingdom/profile/<?= $KingdomId ?>">
						<i class="fas fa-chess-rook"></i> Kingdom Profile
					</a>
				</div>
			</div>

			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-lightbulb"></i> Tips</div>
				<div class="rp-filter-card-body" style="font-size:12px;line-height:1.55;color:var(--rp-text-body);">
					<p style="margin:0 0 8px;">Select the radio button next to the correct answer.</p>
					<p style="margin:0 0 8px;">A question must have at least 2 answers with exactly one marked correct.</p>
					<p style="margin:0;">Click &times; to remove an answer choice.</p>
				</div>
			</div>
		</div><!-- /.rp-sidebar -->

		<!-- Form area -->
		<div class="rp-table-area">
			<div class="qt-form-card">

				<div class="qt-error-banner" id="qt-error-banner"></div>

				<form id="qt-question-form">
					<input type="hidden" name="KingdomId"  value="<?= $KingdomId ?>">
					<input type="hidden" name="TestType"   value="<?= $TestType ?>">
					<input type="hidden" name="QuestionId" value="<?= $isEdit ? $q['QualQuestionId'] : 0 ?>">

					<div class="qt-field">
						<label for="qt-question-text">Question Text <span style="color:#e53e3e">*</span></label>
						<textarea id="qt-question-text" name="QuestionText"><?= htmlspecialchars($q['QuestionText']) ?></textarea>
					</div>

					<div class="qt-field">
						<label>Answer Choices <span style="color:#e53e3e">*</span></label>
						<div class="qt-field-hint">Radio button = correct answer.</div>
						<ul class="qt-answers-list" id="qt-answers-list">
							<?php foreach ($answers as $i => $a): ?>
							<li class="qt-answer-row">
								<input type="radio" name="IsCorrect" value="<?= $i ?>"
								       <?= ($i === $correctIdx) ? 'checked' : '' ?> title="Correct answer">
								<input type="text" name="AnswerText[]"
								       value="<?= htmlspecialchars($a['AnswerText']) ?>"
								       placeholder="Answer <?= $i + 1 ?>">
								<button type="button" class="qt-remove-btn" title="Remove">&times;</button>
							</li>
							<?php endforeach; ?>
						</ul>
						<button type="button" class="qt-add-answer-btn" id="qt-add-answer">+ Add another answer</button>
					</div>

					<div class="qt-form-actions">
						<button type="submit" class="qt-submit-btn" data-action="return">
							<i class="fas fa-save"></i> <?= $isEdit ? 'Save and Return' : 'Save and Return' ?>
						</button>
						<?php if (!$isEdit): ?>
						<button type="submit" class="qt-submit-btn qt-submit-btn-ghost" data-action="another">
							<i class="fas fa-plus"></i> Save and Add Another
						</button>
						<?php endif; ?>
						<a class="qt-cancel-btn"
						   href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $TestType ?>">Cancel</a>
					</div>
				</form>

			</div>
		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script>
(function() {
	var list      = document.getElementById('qt-answers-list');
	var addBtn    = document.getElementById('qt-add-answer');
	var form      = document.getElementById('qt-question-form');
	var errorBanner = document.getElementById('qt-error-banner');

	function reindex() {
		list.querySelectorAll('input[type=radio]').forEach(function(r, i) { r.value = i; });
		list.querySelectorAll('input[type=text]').forEach(function(t, i) { t.placeholder = 'Answer ' + (i + 1); });
	}

	function attachRemove(btn) {
		btn.addEventListener('click', function() {
			if (list.querySelectorAll('li').length <= 2) { showErr('A question must have at least 2 answers.'); return; }
			btn.closest('li').remove();
			reindex();
		});
	}

	list.querySelectorAll('.qt-remove-btn').forEach(attachRemove);

	addBtn.addEventListener('click', function() {
		var idx = list.querySelectorAll('li').length;
		var li  = document.createElement('li');
		li.className = 'qt-answer-row';
		li.innerHTML = '<input type="radio" name="IsCorrect" value="' + idx + '" title="Correct answer">'
		             + '<input type="text" name="AnswerText[]" placeholder="Answer ' + (idx + 1) + '">'
		             + '<button type="button" class="qt-remove-btn" title="Remove">&times;</button>';
		attachRemove(li.querySelector('.qt-remove-btn'));
		list.appendChild(li);
	});

	var submitAction = 'return';
	form.querySelectorAll('button[type=submit]').forEach(function(btn) {
		btn.addEventListener('click', function() { submitAction = btn.dataset.action || 'return'; });
	});

	form.addEventListener('submit', function(e) {
		e.preventDefault();
		errorBanner.style.display = 'none';

		var questionText = form.querySelector('[name=QuestionText]').value.trim();
		var checked = form.querySelector('[name=IsCorrect]:checked');
		var texts   = Array.from(form.querySelectorAll('[name="AnswerText[]"]')).filter(function(t) { return t.value.trim(); });

		if (!questionText) { showErr('Question text is required.'); return; }
		if (!checked)      { showErr('Please select the correct answer.'); return; }
		if (texts.length < 2) { showErr('At least 2 answer choices are required.'); return; }

		var fd = new FormData(form);
		fd.set('IsCorrect', checked.value);

		fetch('<?= UIR ?>QualTestAjax/savequestion', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status === 0) {
					if (submitAction === 'another') {
					window.location = '<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $TestType ?>';
				} else {
					window.location = '<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $TestType ?>';
				}
				} else {
					showErr(j.error || 'Error saving question.');
				}
			});
	});

	function showErr(msg) {
		errorBanner.textContent = msg;
		errorBanner.style.display = 'block';
		window.scrollTo(0, 0);
	}
})();
</script>
