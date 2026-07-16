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
	$correctIdxs = [];
	foreach ($answers as $i => $a) { if (!empty($a['IsCorrect'])) { $correctIdxs[] = $i; } }
	if (empty($correctIdxs)) { $correctIdxs = [0]; }
	$answerMode = ($q['AnswerMode'] ?? 'single') === 'multi' ? 'multi' : 'single';
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
/* Editing a question that is in the LIVE set changes the running test immediately. */
.qt-live-edit-warning { display:flex; align-items:flex-start; gap:10px; margin:0 0 18px; padding:12px 14px;
	background:#fffbeb; border:1px solid #fcd34d; border-left:4px solid #f59e0b; border-radius:6px;
	font-size:0.85rem; color:#78350f; line-height:1.55; }
.qt-live-edit-warning i { color:#f59e0b; margin-top:2px; flex-shrink:0; }
.qt-draft-target-note { display:flex; align-items:flex-start; gap:10px; margin:0 0 18px; padding:10px 14px;
	background:#faf5ff; border:1px solid #e9d8fd; border-radius:6px; font-size:0.85rem; color:#553c9a; line-height:1.5; }
.qt-draft-target-note i { margin-top:2px; flex-shrink:0; }
html[data-theme="dark"] .qt-live-edit-warning { background:#3b2f14; border-color:#a16207; color:#fde68a; }
html[data-theme="dark"] .qt-draft-target-note { background:#322659; border-color:#553c9a; color:#e9d8fd; }
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
.qt-quick-answers { display: flex; align-items: center; gap: 8px; margin-bottom: 10px; }
.qt-quick-label { font-size: 0.78rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.05em; color: var(--rp-text-muted); }
.qt-quick-pill { display: inline-block; padding: 4px 14px; border-radius: 16px; font-size: 0.8rem; font-weight: 600;
                 cursor: pointer; border: 1px solid #cbd5e0; background: #f7fafc; color: #4a5568; transition: all 0.15s; }
.qt-quick-pill:hover { background: #ebf4ff; border-color: #90cdf4; color: #2b6cb0; }
/* ── Tooltips (data-tip; replaces native title=) ── */
[data-tip] { position: relative; }
[data-tip]::after { content: attr(data-tip); position: absolute; bottom: calc(100% + 6px); left: 50%; transform: translateX(-50%); background: #2d3748; color: #fff; font-size: 0.72rem; font-weight: 600; padding: 3px 8px; border-radius: 4px; white-space: normal; width: max-content; max-width: 240px; pointer-events: none; opacity: 0; transition: opacity 0.1s; z-index: 100; }
[data-tip]:hover::after { opacity: 1; }

/* ── Dark mode ────────────────────────────────────────── */
html[data-theme="dark"] .qt-nav-link {
	background: var(--ork-bg-secondary, #2d3748);
	border-color: var(--ork-border, #4a5568);
	color: #63b3ed;
}
html[data-theme="dark"] .qt-nav-link:hover { background: #4a5568; border-color: #718096; color: #90cdf4; }
html[data-theme="dark"] .qt-form-card {
	background: var(--ork-card-bg, #2d3748);
	border-color: var(--ork-border, #4a5568);
}
html[data-theme="dark"] .qt-field label,
html[data-theme="dark"] .qt-answer-row label { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .qt-field input[type=text],
html[data-theme="dark"] .qt-field textarea {
	background: var(--ork-input-bg, #374151);
	border-color: var(--ork-input-border, #4a5568);
	color: var(--ork-text, #e2e8f0);
}
html[data-theme="dark"] .qt-field input[type=text]::placeholder,
html[data-theme="dark"] .qt-field textarea::placeholder { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-field textarea:focus,
html[data-theme="dark"] .qt-field input[type=text]:focus { border-color: #818cf8; }
html[data-theme="dark"] .qt-remove-btn { color: #fbd38d; }
html[data-theme="dark"] .qt-remove-btn:hover { color: #f6ad55; }
html[data-theme="dark"] .qt-add-answer-btn { color: #63b3ed; }
html[data-theme="dark"] .qt-submit-btn-ghost { color: #63b3ed; border-color: #63b3ed; }
html[data-theme="dark"] .qt-submit-btn-ghost:hover { background: #2a4365; color: #90cdf4; }
html[data-theme="dark"] .qt-cancel-btn { background: #4a5568; color: #e2e8f0; }
html[data-theme="dark"] .qt-cancel-btn:hover { background: #718096; }
html[data-theme="dark"] .qt-error-banner { background: #742a2a; border-color: #fc8181; color: #feb2b2; }
html[data-theme="dark"] .qt-quick-label { color: var(--ork-text-muted, #a0aec0); }
html[data-theme="dark"] .qt-quick-pill {
	background: var(--ork-bg-tertiary, #374151);
	border-color: var(--ork-border, #4a5568);
	color: var(--ork-text-secondary, #cbd5e0);
}
html[data-theme="dark"] .qt-quick-pill:hover { background: #2a4365; border-color: #63b3ed; color: #90cdf4; }
html[data-theme="dark"] label[for="qt-question-text"] span[style*="color:#e53e3e"],
html[data-theme="dark"] .qt-field span[style*="color:#e53e3e"] { color: #fc8181 !important; }
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

				<?php // THE load-bearing safety affordance. A question can be a member of both the
				      // live set and the draft, so an in-place edit changes BOTH — which is exactly
				      // right for correcting a bogus question, but dangerous if the admin believes
				      // they're safely working on the next version. ?>
				<?php if ($isEdit && !empty($EditingLiveQuestion)): ?>
				<div class="qt-live-edit-warning">
					<i class="fas fa-exclamation-triangle"></i>
					<span>
						<strong>This question is live.</strong> Editing it changes the <em>current</em> test immediately
						&mdash; players will see the new wording on their very next attempt (and in any draft it belongs to).
						That's what you want for a <strong>correction</strong> (a badly-worded or wrong question).
						If instead you're reworking it for a <strong>future version</strong>, add a new question to the draft
						and remove this one from the draft &mdash; that keeps the current test asking the old wording until you publish.
					</span>
				</div>
				<?php elseif (!$isEdit && !empty($TargetIsDraft)): ?>
				<div class="qt-draft-target-note">
					<i class="fas fa-pen"></i>
					<span>This question will be added to the draft version <strong><?= htmlspecialchars($TargetSetName) ?></strong>. It will not appear in the live test until you publish.</span>
				</div>
				<?php endif; ?>

				<form id="qt-question-form">
					<input type="hidden" name="KingdomId"  value="<?= $KingdomId ?>">
					<input type="hidden" name="TestType"   value="<?= $TestType ?>">
					<input type="hidden" name="SetId"      value="<?= (int)($TargetSetId ?? 0) ?>">
					<input type="hidden" name="QuestionId" value="<?= $isEdit ? $q['QualQuestionId'] : 0 ?>">

					<div class="qt-field">
						<label for="qt-question-text">Question Text <span style="color:#e53e3e">*</span></label>
						<textarea id="qt-question-text" name="QuestionText"><?= htmlspecialchars($q['QuestionText']) ?></textarea>
					</div>

					<div class="qt-field">
						<label>Answer Mode</label>
						<div style="display:flex;gap:14px;align-items:center;font-size:0.9rem;">
							<label style="display:inline-flex;align-items:center;gap:5px;font-weight:normal;text-transform:none;letter-spacing:normal;color:var(--rp-text);margin:0;">
								<input type="radio" name="AnswerMode" value="single" id="qt-mode-single" <?= $answerMode === 'single' ? 'checked' : '' ?>>
								Single correct answer
							</label>
							<label style="display:inline-flex;align-items:center;gap:5px;font-weight:normal;text-transform:none;letter-spacing:normal;color:var(--rp-text);margin:0;">
								<input type="radio" name="AnswerMode" value="multi" id="qt-mode-multi" <?= $answerMode === 'multi' ? 'checked' : '' ?>>
								Multiple correct (select all that apply)
							</label>
						</div>
					</div>

					<div class="qt-field">
						<label>Answer Choices <span style="color:#e53e3e">*</span></label>
						<?php if (!$isEdit): ?>
						<div class="qt-quick-answers">
							<span class="qt-quick-label">Quick Answers:</span>
							<button type="button" class="qt-quick-pill" data-answers="True,False">True / False</button>
							<button type="button" class="qt-quick-pill" data-answers="Yes,No">Yes / No</button>
						</div>
						<?php endif; ?>
						<div class="qt-field-hint" id="qt-correct-hint">Radio button = correct answer.</div>
						<ul class="qt-answers-list" id="qt-answers-list" data-correct='<?= htmlspecialchars(json_encode($correctIdxs), ENT_QUOTES) ?>'>
							<?php foreach ($answers as $i => $a): $checked = in_array($i, $correctIdxs, true); ?>
							<li class="qt-answer-row">
								<input type="<?= $answerMode === 'multi' ? 'checkbox' : 'radio' ?>" name="IsCorrect<?= $answerMode === 'multi' ? '[]' : '' ?>" value="<?= $i ?>"
								       <?= $checked ? 'checked' : '' ?> data-tip="Correct answer">
								<input type="text" name="AnswerText[]"
								       value="<?= htmlspecialchars($a['AnswerText']) ?>"
								       placeholder="Answer <?= $i + 1 ?>">
								<button type="button" class="qt-remove-btn" data-tip="Remove">&times;</button>
							</li>
							<?php endforeach; ?>
						</ul>
						<button type="button" class="qt-add-answer-btn" id="qt-add-answer">+ Add another answer</button>
					</div>

					<div class="qt-form-actions">
						<button type="submit" class="qt-submit-btn" data-action="return">
							<i class="fas fa-save"></i> <?= 'Save and Return' ?>
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

	// Which mode is currently active — read once and updated on toggle.
	function currentMode() {
		var m = document.querySelector('input[name=AnswerMode]:checked');
		return m && m.value === 'multi' ? 'multi' : 'single';
	}

	function reindex() {
		list.querySelectorAll('.qt-answer-row input[type=radio], .qt-answer-row input[type=checkbox]')
			.forEach(function(r, i) { r.value = i; });
		list.querySelectorAll('input[type=text]').forEach(function(t, i) { t.placeholder = 'Answer ' + (i + 1); });
	}

	// Swap every correct-answer input between radio (single) and checkbox
	// (multi). Preserves the currently-checked set so a mode toggle round-trip
	// isn't destructive.
	function retypeCorrectInputs(mode) {
		var checkedVals = [];
		list.querySelectorAll('.qt-answer-row input[type=radio], .qt-answer-row input[type=checkbox]')
			.forEach(function(cb) { if (cb.checked) checkedVals.push(cb.value); });

		// If switching to single but multiple were checked, keep only the first.
		if (mode === 'single' && checkedVals.length > 1) checkedVals = [checkedVals[0]];

		list.querySelectorAll('.qt-answer-row').forEach(function(row, i) {
			var old  = row.querySelector('input[type=radio], input[type=checkbox]');
			var next = document.createElement('input');
			next.type    = (mode === 'multi') ? 'checkbox' : 'radio';
			next.name    = (mode === 'multi') ? 'IsCorrect[]' : 'IsCorrect';
			next.value   = old.value;
			next.checked = checkedVals.indexOf(old.value) !== -1;
			next.setAttribute('data-tip', 'Correct answer');
			old.parentNode.replaceChild(next, old);
		});

		var hint = document.getElementById('qt-correct-hint');
		if (hint) hint.textContent = (mode === 'multi')
			? 'Check every answer that should count as correct — the player must pick all of them.'
			: 'Radio button = correct answer.';
	}

	document.querySelectorAll('input[name=AnswerMode]').forEach(function(r) {
		r.addEventListener('change', function() { retypeCorrectInputs(currentMode()); });
	});

	function attachRemove(btn) {
		btn.addEventListener('click', function() {
			if (list.querySelectorAll('li').length <= 2) { showErr('A question must have at least 2 answers.'); return; }
			btn.closest('li').remove();
			reindex();
		});
	}

	list.querySelectorAll('.qt-remove-btn').forEach(attachRemove);

	function correctInputHtml(idx) {
		var mode = currentMode();
		var t    = mode === 'multi' ? 'checkbox' : 'radio';
		var n    = mode === 'multi' ? 'IsCorrect[]' : 'IsCorrect';
		return '<input type="' + t + '" name="' + n + '" value="' + idx + '" data-tip="Correct answer">';
	}

	addBtn.addEventListener('click', function() {
		var idx = list.querySelectorAll('li').length;
		var li  = document.createElement('li');
		li.className = 'qt-answer-row';
		li.innerHTML = correctInputHtml(idx)
		             + '<input type="text" name="AnswerText[]" placeholder="Answer ' + (idx + 1) + '">'
		             + '<button type="button" class="qt-remove-btn" data-tip="Remove">&times;</button>';
		attachRemove(li.querySelector('.qt-remove-btn'));
		list.appendChild(li);
	});

	// Quick answer pills (True/False, Yes/No)
	document.querySelectorAll('.qt-quick-pill').forEach(function(pill) {
		pill.addEventListener('click', function() {
			var vals = pill.dataset.answers.split(',');
			list.innerHTML = '';
			vals.forEach(function(text, i) {
				var li = document.createElement('li');
				li.className = 'qt-answer-row';
				li.innerHTML = correctInputHtml(i)
				             + '<input type="text" name="AnswerText[]" value="' + text + '" placeholder="Answer ' + (i + 1) + '">'
				             + '<button type="button" class="qt-remove-btn" data-tip="Remove">&times;</button>';
				attachRemove(li.querySelector('.qt-remove-btn'));
				list.appendChild(li);
			});
		});
	});

	var submitAction = 'return';
	form.querySelectorAll('button[type=submit]').forEach(function(btn) {
		btn.addEventListener('click', function() { submitAction = btn.dataset.action || 'return'; });
	});

	form.addEventListener('submit', function(e) {
		e.preventDefault();
		errorBanner.style.display = 'none';

		var mode = currentMode();
		var questionText = form.querySelector('[name=QuestionText]').value.trim();
		var checkedNodes = form.querySelectorAll('.qt-answer-row input[type=radio]:checked, .qt-answer-row input[type=checkbox]:checked');
		var texts   = Array.from(form.querySelectorAll('[name="AnswerText[]"]')).filter(function(t) { return t.value.trim(); });

		if (!questionText) { showErr('Question text is required.'); return; }
		if (checkedNodes.length === 0) { showErr('Please mark at least one correct answer.'); return; }
		if (mode === 'multi' && checkedNodes.length < 2) { showErr('Multiple-correct questions need at least 2 correct answers. Switch back to Single, or check another answer.'); return; }
		if (texts.length < 2) { showErr('At least 2 answer choices are required.'); return; }

		var fd = new FormData(form);
		// Rewrite IsCorrect on the outgoing form data so single sends a scalar
		// and multi sends an array (server accepts either shape).
		fd.delete('IsCorrect');
		fd.delete('IsCorrect[]');
		if (mode === 'multi') {
			checkedNodes.forEach(function(n) { fd.append('IsCorrect[]', n.value); });
		} else {
			fd.set('IsCorrect', checkedNodes[0].value);
		}

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
