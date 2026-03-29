<?php
	if (strlen($Error ?? '') > 0) {
		echo '<div class="error-message">' . htmlspecialchars($Error) . '</div>';
		return;
	}
	$typeLabels = ['reeve' => "Reeve's Test", 'corpora' => 'Corpora Test'];
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>default/style/reports.css">

<style>
.qt-nav-link { display: flex; align-items: center; gap: 8px; padding: 7px 10px; background: #f7fafc; border: 1px solid #e2e8f0; border-radius: 5px; font-size: 13px; font-weight: 600; color: #2b6cb0; text-decoration: none; transition: background 0.15s; }
.qt-nav-link:hover { background: #ebf4ff; border-color: #bee3f8; color: #2c5282; }
</style>

<style>
.qt-config-card { background: #fff; border: 1px solid var(--rp-border); border-radius: 8px; padding: 20px 22px; margin-bottom: 20px; }
.qt-config-card h3 { margin: 0 0 6px; font-size: 1.05rem; color: #2d3748; }
.qt-config-card h3 i { margin-right: 6px; color: #2b6cb0; }
.qt-stat-row { display: flex; gap: 18px; margin-bottom: 16px; flex-wrap: wrap; }
.qt-mini-stat { font-size: 0.82rem; color: var(--rp-text-muted); }
.qt-mini-stat strong { color: #2b6cb0; font-size: 1rem; }
.qt-form-row { display: flex; align-items: center; gap: 10px; margin-bottom: 10px; flex-wrap: wrap; }
.qt-form-row label { font-size: 0.82rem; font-weight: 600; color: var(--rp-text-muted); min-width: 140px; text-transform: uppercase; letter-spacing: 0.04em; }
.qt-form-row input[type=number] { width: 80px; padding: 5px 8px; border: 1px solid var(--rp-border); border-radius: 4px; font-size: 0.9rem; }
.qt-save-row { display: flex; align-items: center; gap: 10px; margin-top: 14px; }
.qt-save-btn { padding: 7px 20px; background: #2b6cb0; color: #fff; border: none; border-radius: 4px; font-size: 0.88rem; font-weight: 600; cursor: pointer; }
.qt-save-btn:hover { background: #2c5282; }
.qt-saved-msg { font-size: 0.82rem; color: #276749; display: none; }
.qt-link-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; padding-top: 14px; border-top: 1px solid var(--rp-border); }
.qt-link-btn { display: inline-block; padding: 6px 14px; border-radius: 4px; font-size: 0.82rem; font-weight: 600; text-decoration: none; }
.qt-link-btn-primary { background: #2b6cb0; color: #fff; }
.qt-link-btn-primary:hover { background: #2c5282; }
.qt-link-btn-ghost { background: transparent; color: #2b6cb0; border: 1px solid #2b6cb0; }
.qt-link-btn-ghost:hover { background: #ebf4ff; }
.qt-tooltip-wrap { position:relative; display:inline-block; }
.qt-tooltip-icon { cursor:pointer; color:#718096; font-size:0.82rem; margin-left:4px; }
.qt-tooltip-box { display:none; position:absolute; left:50%; transform:translateX(-50%); bottom:calc(100% + 6px); width:280px; background:#2d3748; color:#fff; font-size:0.78rem; line-height:1.45; padding:8px 10px; border-radius:5px; z-index:100; pointer-events:none; }
.qt-tooltip-wrap:hover .qt-tooltip-box, .qt-tooltip-wrap:focus-within .qt-tooltip-box { display:block; }
.qt-share-row { display:flex; align-items:center; gap:8px; margin-top:12px; padding-top:12px; border-top:1px solid var(--rp-border); }
.qt-share-row label { font-size:0.85rem; color:#4a5568; font-weight:600; cursor:pointer; }
.qt-validity-toggle { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.qt-radio-opt { display: flex; align-items: center; gap: 4px; font-size: 0.85rem; font-weight: 600; color: #4a5568; cursor: pointer; text-transform: none; letter-spacing: 0; min-width: unset; }
.qt-validity-days, .qt-validity-until { padding: 5px 8px; border: 1px solid var(--rp-border); border-radius: 4px; font-size: 0.9rem; }
.qt-validity-days { width: 80px; }
.qt-validity-until { width: 150px; }
</style>

<div class="rp-root">

	<!-- Header -->
	<div class="rp-header">
		<div class="rp-header-left">
			<div class="rp-header-icon-title">
				<i class="fas fa-clipboard-list rp-header-icon"></i>
				<h1 class="rp-header-title">Configure Tests</h1>
			</div>
			<div class="rp-header-scope">
				<span class="rp-scope-chip">
					<i class="fas fa-chess-rook rp-scope-chip-label"></i>
					<?= htmlspecialchars($KingdomName) ?>
				</span>
			</div>
		</div>
	</div>

	<!-- Context strip -->
	<div class="rp-context">
		<i class="fas fa-info-circle rp-context-icon"></i>
		<span>Set passing requirements and manage question banks for each test type. Players take these tests from their profile page.</span>
	</div>

	<div class="rp-body">

		<!-- Sidebar -->
		<div class="rp-sidebar">
			<div class="rp-filter-card">
				<div class="rp-filter-card-header"><i class="fas fa-sitemap"></i> Navigation</div>
				<div class="rp-filter-card-body" style="display:flex;flex-direction:column;gap:8px;">
					<a class="qt-nav-link" href="<?= UIR ?>Kingdom/profile/<?= $KingdomId ?>">
						<i class="fas fa-chess-rook"></i> Kingdom Profile
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/reeve">
						<i class="fas fa-scroll"></i> Reeve's Test Questions
					</a>
					<a class="qt-nav-link" href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/corpora">
						<i class="fas fa-scroll"></i> Corpora Test Questions
					</a>
				</div>
			</div>
		</div><!-- /.rp-sidebar -->

		<!-- Main content -->
		<div class="rp-table-area">

			<div style="display:grid;grid-template-columns:1fr 1fr;gap:20px;align-items:start;">
			<?php foreach (['reeve' => $ReeveConfig, 'corpora' => $CorporaConfig] as $type => $cfg): ?>
			<?php
				$count = ($type === 'reeve') ? $ReeveCount : $CorporaCount;
				$label = $typeLabels[$type];
			?>
			<div class="qt-config-card">
				<h3><i class="fas fa-scroll"></i> <?= $label ?></h3>

				<div class="qt-stat-row">
					<div class="qt-mini-stat"><strong><?= $count ?></strong> active question<?= $count !== 1 ? 's' : '' ?></div>
				</div>

				<form class="qt-config-form" data-kingdom="<?= $KingdomId ?>" data-type="<?= $type ?>">
					<div class="qt-form-row">
						<label>Questions per test</label>
						<input type="number" name="QuestionCount" min="1" max="100" value="<?= (int)$cfg['QuestionCount'] ?>">
					</div>
					<div class="qt-form-row">
						<label>Pass % required</label>
						<input type="number" name="PassPercent" min="1" max="100" value="<?= (int)$cfg['PassPercent'] ?>">
					</div>
					<div class="qt-form-row qt-validity-row">
						<label>Validity</label>
						<span class="qt-validity-toggle">
							<label class="qt-radio-opt">
								<input type="radio" name="ValidityMode" value="days"
									<?= empty($cfg['ValidUntil']) ? 'checked' : '' ?>>
								Days from passing
							</label>
							<input type="number" name="ValidDays" min="1"
								value="<?= (int)$cfg['ValidDays'] ?>"
								class="qt-validity-days"
								<?= !empty($cfg['ValidUntil']) ? 'disabled style="display:none"' : '' ?>>
							<label class="qt-radio-opt">
								<input type="radio" name="ValidityMode" value="until"
									<?= !empty($cfg['ValidUntil']) ? 'checked' : '' ?>>
								Until date
							</label>
							<input type="date" name="ValidUntil"
								value="<?= htmlspecialchars($cfg['ValidUntil'] ?? '') ?>"
								class="qt-validity-until"
								<?= empty($cfg['ValidUntil']) ? 'disabled style="display:none"' : '' ?>>
						</span>
					</div>
					<div class="qt-form-row">
						<label>Max retakes</label>
						<input type="number" name="MaxRetakes" min="0" value="<?= (int)$cfg['MaxRetakes'] ?>">
						<span style="font-size:0.78rem;color:var(--rp-text-muted);">0 = unlimited</span>
					</div>
					<?php if ($type === 'reeve'): ?>
					<div class="qt-share-row">
						<input type="checkbox" name="ShareQuestions" id="qt-share-<?= $type ?>" value="1" <?= !empty($cfg['ShareQuestions']) ? 'checked' : '' ?>>
						<label for="qt-share-<?= $type ?>">Opt-in to share questions</label>
						<span class="qt-tooltip-wrap">
							<i class="fas fa-question-circle qt-tooltip-icon"></i>
							<span class="qt-tooltip-box">By checking this box and saving, you agree to share your active Reeve's questions with other kingdoms and you gain access to the library of questions from other kingdoms. You will still need to add any given question to your database.</span>
						</span>
					</div>
					<?php endif; ?>
					<div class="qt-form-row" style="align-items:flex-start;">
						<label style="padding-top:6px;">Instructions</label>
						<div style="flex:1;min-width:0;">
							<textarea name="Instructions" rows="4" style="width:100%;padding:5px 8px;border:1px solid var(--rp-border);border-radius:4px;font-size:0.9rem;font-family:inherit;resize:vertical;"
								placeholder="Optional instructions shown before the test begins."><?= htmlspecialchars($cfg['Instructions'] ?? '') ?></textarea>
							<div style="font-size:0.75rem;color:var(--rp-text-muted);margin-top:3px;">Shown as the first card when a player begins the test. Line breaks will be preserved.</div>
						</div>
					</div>
					<div class="qt-save-row">
						<button type="submit" class="qt-save-btn"><i class="fas fa-save"></i> Save Settings</button>
						<span class="qt-saved-msg"><i class="fas fa-check-circle"></i> Saved</span>
					</div>
				</form>

				<div class="qt-link-row">
					<a class="qt-link-btn qt-link-btn-primary" href="<?= UIR ?>QualTest/questions/<?= $KingdomId ?>/<?= $type ?>">
						<i class="fas fa-list"></i> View Questions
					</a>
					<a class="qt-link-btn qt-link-btn-ghost" href="<?= UIR ?>QualTest/question/create/<?= $KingdomId ?>/<?= $type ?>">
						<i class="fas fa-plus"></i> Add Question
					</a>
					<button class="qt-link-btn qt-reset-retakes-btn" style="background:#e9d8fd;color:#553c9a;border:none;cursor:pointer;"
					        data-kingdom="<?= $KingdomId ?>" data-type="<?= $type ?>">
						<i class="fas fa-undo-alt"></i> Reset Retakes
					</button>
				</div>
			</div>
			<?php endforeach; ?>
			</div>

		<!-- Test Managers widget -->
		<div class="qt-config-card" id="qt-managers-card">
			<h3><i class="fas fa-users-cog"></i> Test Managers</h3>
			<p style="font-size:0.82rem;color:var(--rp-text-muted);margin:0 0 12px;">
				Personas listed here can view and manage these tests without needing kingdom editor or officer rights.
			</p>

			<ul id="qt-manager-list" style="list-style:none;padding:0;margin:0 0 14px;">
				<?php if (empty($Managers)): ?>
				<li id="qt-no-managers" style="font-size:0.85rem;color:var(--rp-text-muted);">No managers added yet.</li>
				<?php else: ?>
				<?php foreach ($Managers as $mgr): ?>
				<li class="qt-manager-row" data-id="<?= (int)$mgr['MundaneId'] ?>" style="display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f0f0f0;">
					<span style="flex:1;font-size:0.88rem;">
						<a href="<?= UIR ?>Player/index/<?= (int)$mgr['MundaneId'] ?>" target="_blank"><?= htmlspecialchars($mgr['Name']) ?></a>
						<span style="color:var(--rp-text-muted);font-size:0.78rem;">&nbsp;#<?= (int)$mgr['MundaneId'] ?></span>
					</span>
					<button class="qt-rm-manager-btn" data-id="<?= (int)$mgr['MundaneId'] ?>" title="Remove" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:1rem;padding:0 4px;">
						<i class="fas fa-times-circle"></i>
					</button>
				</li>
				<?php endforeach; ?>
				<?php endif; ?>
			</ul>

			<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
				<div class="qt-manager-search-wrap" style="position:relative;">
					<input type="text" id="qt-manager-search" placeholder="Search player name&hellip;" autocomplete="off"
					       style="width:220px;padding:5px 8px;border:1px solid var(--rp-border);border-radius:4px;font-size:0.9rem;">
					<input type="hidden" id="qt-manager-id-input">
					<div id="qt-manager-ac-results" style="display:none;position:absolute;bottom:100%;left:0;right:0;background:#fff;border:1px solid #cbd5e0;border-radius:4px;box-shadow:0 -2px 8px rgba(0,0,0,0.12);z-index:200;max-height:200px;overflow-y:auto;"></div>
				</div>
				<button id="qt-add-manager-btn" class="qt-save-btn" style="white-space:nowrap;"><i class="fas fa-user-plus"></i> Add Manager</button>
				<span id="qt-manager-error" style="font-size:0.82rem;color:#e53e3e;display:none;"></span>
			</div>
		</div>

		</div><!-- /.rp-table-area -->

	</div><!-- /.rp-body -->

</div><!-- /.rp-root -->

<script>
(function() {
	document.querySelectorAll('.qt-config-form').forEach(function(form) {
		// Wire validity mode radio toggles
		form.querySelectorAll('input[name="ValidityMode"]').forEach(function(radio) {
			radio.addEventListener('change', function() {
				var daysInput  = form.querySelector('.qt-validity-days');
				var untilInput = form.querySelector('.qt-validity-until');
				if (radio.value === 'days') {
					daysInput.style.display  = '';  daysInput.disabled  = false;
					untilInput.style.display = 'none'; untilInput.disabled = true; untilInput.value = '';
				} else {
					untilInput.style.display = '';  untilInput.disabled  = false;
					daysInput.style.display  = 'none'; daysInput.disabled = true;
				}
			});
		});

		form.addEventListener('submit', function(e) {
			e.preventDefault();
			var fd = new FormData(form);
			fd.append('KingdomId', form.dataset.kingdom);
			fd.append('TestType',  form.dataset.type);
			// Clear the inactive validity field so the server sees exactly one
			var mode = form.querySelector('input[name="ValidityMode"]:checked');
			if (mode && mode.value === 'until') {
				fd.delete('ValidDays');
			} else {
				fd.delete('ValidUntil');
			}
			fetch('<?= UIR ?>QualTestAjax/saveconfig', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j.status === 0) {
						var msg = form.querySelector('.qt-saved-msg');
						if (msg) { msg.style.display = 'inline'; setTimeout(function() { msg.style.display = 'none'; }, 2500); }
					} else {
						alert(j.error || 'Error saving settings.');
					}
				});
		});
	});
})();

// ----- Reset Retakes -----
	document.querySelectorAll('.qt-reset-retakes-btn').forEach(function(btn) {
		btn.addEventListener('click', function() {
			if (!confirm('Reset retake counts for ALL players on this test? This cannot be undone.')) return;
			var fd = new FormData();
			fd.append('KingdomId', btn.dataset.kingdom);
			fd.append('TestType',  btn.dataset.type);
			fetch('<?= UIR ?>QualTestAjax/resetretakes', { method: 'POST', body: fd })
				.then(function(r) { return r.json(); })
				.then(function(j) {
					if (j.status === 0) {
						btn.textContent = '\u2713 Done';
						setTimeout(function() { btn.innerHTML = '<i class="fas fa-undo-alt"></i> Reset Retakes'; }, 2000);
					} else { alert(j.error || 'Error resetting retakes.'); }
				});
		});
	});

// ----- Test Managers -----
(function() {
	var kingdomId  = <?= (int)$KingdomId ?>;
	var ajaxBase   = '<?= UIR ?>QualTestAjax/';
	var searchBase = '<?= UIR ?>SearchAjax/universal';

	var searchInput = document.getElementById('qt-manager-search');
	var idInput     = document.getElementById('qt-manager-id-input');
	var acDrop      = document.getElementById('qt-manager-ac-results');
	var _acTimer;

	function escHtml(s) {
		return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
	}

	function showManagerError(msg) {
		var el = document.getElementById('qt-manager-error');
		el.textContent = msg;
		el.style.display = msg ? 'inline' : 'none';
	}

	// Autocomplete
	searchInput.addEventListener('input', function() {
		idInput.value = '';
		var q = searchInput.value.trim();
		if (q.length < 2) { acDrop.style.display = 'none'; return; }
		clearTimeout(_acTimer);
		_acTimer = setTimeout(function() {
			fetch(searchBase + '&q=' + encodeURIComponent(q) + '&kid=' + kingdomId)
				.then(function(r) { return r.json(); })
				.then(function(data) {
					var items = data.players || [];
					if (!items.length) { acDrop.style.display = 'none'; return; }
					acDrop.innerHTML = items.map(function(pl) {
						return '<div class="qt-ac-item" data-id="' + pl.id + '" data-name="' + escHtml(pl.name) + '"'
							+ ' style="padding:6px 10px;cursor:pointer;font-size:0.88rem;border-bottom:1px solid #f0f4f8;">'
							+ escHtml(pl.name)
							+ (pl.park ? '<div style="font-size:0.75rem;color:#718096;">' + escHtml(pl.park) + '</div>' : '')
							+ '</div>';
					}).join('');
					acDrop.style.display = 'block';
					acDrop.querySelectorAll('.qt-ac-item').forEach(function(item) {
						item.addEventListener('mousedown', function(e) {
							e.preventDefault();
							searchInput.value = item.dataset.name;
							idInput.value     = item.dataset.id;
							acDrop.style.display = 'none';
						});
					});
				}).catch(function(){});
		}, 220);
	});

	document.addEventListener('click', function(e) {
		if (!e.target.closest('.qt-manager-search-wrap')) acDrop.style.display = 'none';
	});

	// Add button
	function buildManagerRow(id, name) {
		var li = document.createElement('li');
		li.className = 'qt-manager-row';
		li.dataset.id = id;
		li.style.cssText = 'display:flex;align-items:center;gap:8px;padding:5px 0;border-bottom:1px solid #f0f0f0;';
		li.innerHTML =
			'<span style="flex:1;font-size:0.88rem;">' +
				'<a href="<?= UIR ?>Player/index/' + id + '" target="_blank">' + escHtml(name) + '</a>' +
				'<span style="color:var(--rp-text-muted);font-size:0.78rem;">&nbsp;#' + id + '</span>' +
			'</span>' +
			'<button class="qt-rm-manager-btn" data-id="' + id + '" title="Remove" style="background:none;border:none;cursor:pointer;color:#e53e3e;font-size:1rem;padding:0 4px;">' +
				'<i class="fas fa-times-circle"></i>' +
			'</button>';
		return li;
	}

	document.getElementById('qt-add-manager-btn').addEventListener('click', function() {
		showManagerError('');
		var mid = parseInt(idInput.value, 10);
		if (!mid || mid < 1) { showManagerError('Select a player from the list first.'); return; }

		var fd = new FormData();
		fd.append('KingdomId', kingdomId);
		fd.append('MundaneId', mid);
		fetch(ajaxBase + 'addmanager', { method: 'POST', body: fd })
			.then(function(r) { return r.json(); })
			.then(function(j) {
				if (j.status !== 0) { showManagerError(j.error || 'Error adding manager.'); return; }
				searchInput.value = '';
				idInput.value     = '';
				var noMsg = document.getElementById('qt-no-managers');
				if (noMsg) noMsg.remove();
				var existing = document.querySelector('#qt-manager-list [data-id="' + j.mundane_id + '"]');
				if (!existing) {
					document.getElementById('qt-manager-list').appendChild(buildManagerRow(j.mundane_id, j.name));
					attachRemoveHandlers();
				}
			});
	});

	function attachRemoveHandlers() {
		document.querySelectorAll('.qt-rm-manager-btn').forEach(function(btn) {
			btn.onclick = null;
			btn.addEventListener('click', function() {
				var mid = parseInt(btn.dataset.id, 10);
				var fd = new FormData();
				fd.append('KingdomId', kingdomId);
				fd.append('MundaneId', mid);
				fetch(ajaxBase + 'removemanager', { method: 'POST', body: fd })
					.then(function(r) { return r.json(); })
					.then(function(j) {
						if (j.status !== 0) { alert(j.error || 'Error removing manager.'); return; }
						var row = document.querySelector('#qt-manager-list [data-id="' + mid + '"]');
						if (row) row.remove();
						if (!document.querySelector('#qt-manager-list .qt-manager-row')) {
							var li = document.createElement('li');
							li.id = 'qt-no-managers';
							li.style.cssText = 'font-size:0.85rem;color:var(--rp-text-muted);';
							li.textContent = 'No managers added yet.';
							document.getElementById('qt-manager-list').appendChild(li);
						}
					});
			});
		});
	}

	attachRemoveHandlers();
})();
</script>
