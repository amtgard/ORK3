<?php
	$error           = $error           ?? '';
	$link            = $link            ?? null;
	$scope_name      = $scope_name      ?? 'your group';
	$classes         = $classes         ?? [];
	$link_token      = $link_token      ?? '';
	$last_class_id   = (int)($last_class_id   ?? 0);
	$last_class_name = $last_class_name ?? '';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<style>
.si-wrap {
	max-width: 480px;
	margin: 32px auto;
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 4px 24px rgba(0,0,0,0.12);
	overflow: hidden;
}
.si-header {
	background: linear-gradient(135deg, #2b6cb0 0%, #1a4a80 100%);
	padding: 28px 32px 24px;
	color: #fff;
}
.si-header h2 {
	margin: 0 0 4px;
	font-size: 22px;
	font-weight: 700;
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
	text-shadow: 0 1px 3px rgba(0,0,0,0.2);
	color: #fff;
}
.si-header p {
	margin: 0;
	font-size: 13px;
	opacity: 0.85;
}
.si-body {
	padding: 28px 32px 32px;
}
.si-error {
	background: #fff5f5;
	border: 1px solid #fc8181;
	color: #c53030;
	border-radius: 6px;
	padding: 10px 14px;
	margin-bottom: 20px;
	font-size: 13px;
}
.si-meta {
	background: #ebf8ff;
	border: 1px solid #bee3f8;
	border-radius: 6px;
	padding: 10px 14px;
	margin-bottom: 24px;
	font-size: 12px;
	color: #2c5282;
}
.si-meta i { margin-right: 5px; opacity: 0.7; }
.si-btn-primary {
	width: 100%;
	padding: 14px;
	background: #3182ce;
	color: #fff;
	border: none;
	border-radius: 8px;
	font-size: 16px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s;
	display: block;
	touch-action: manipulation;
}
.si-btn-primary:hover:not(:disabled) { background: #2b6cb0; }
.si-btn-primary:disabled { background: #a0aec0; cursor: default; }
.si-change-toggle {
	display: block;
	width: 100%;
	margin-top: 12px;
	padding: 8px;
	background: none;
	border: none;
	color: #3182ce;
	font-size: 13px;
	cursor: pointer;
	text-align: center;
}
.si-change-toggle:hover { text-decoration: underline; }
.si-class-picker {
	margin-top: 16px;
	padding-top: 16px;
	border-top: 1px solid #e2e8f0;
}
.si-class-picker label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	margin-bottom: 6px;
	text-transform: uppercase;
	letter-spacing: 0.04em;
}
.si-class-picker select {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	font-size: 16px;
	background: #fff;
	color: #2d3748;
	margin-bottom: 12px;
}
.si-class-picker select:focus {
	outline: none;
	border-color: #3182ce;
	box-shadow: 0 0 0 3px rgba(49,130,206,0.15);
}
.si-invalid {
	text-align: center;
	padding: 40px 32px;
	color: #718096;
}
.si-invalid i { font-size: 40px; color: #fc8181; margin-bottom: 16px; display: block; }
@media (max-width: 540px) {
	.si-wrap { margin: 0; border-radius: 0; box-shadow: none; min-height: 100vh; }
	.si-header { padding: 20px 20px 16px; }
	.si-body { padding: 20px 20px 28px; }
	.si-btn-primary { font-size: 17px; padding: 15px; touch-action: manipulation; }
	.si-change-toggle { font-size: 14px; padding: 10px; touch-action: manipulation; }
	.si-class-picker select { font-size: 16px; padding: 12px; }
	.si-invalid { padding: 40px 20px; }
}
</style>

<div class="si-wrap">

<?php if ($error && !$link): ?>
	<!-- Link invalid / expired -->
	<div class="si-invalid">
		<i class="fas fa-link-slash"></i>
		<h3 style="margin:0 0 8px;color:#2d3748;background:transparent;border:none;padding:0;border-radius:0;text-shadow:none">Sign-in Link Unavailable</h3>
		<p><?= htmlspecialchars($error) ?></p>
		<a href="<?= UIR ?>" style="display:inline-block;margin-top:16px;color:#3182ce;font-size:13px">
			<i class="fas fa-home"></i> Return to ORK
		</a>
	</div>

<?php else: ?>
	<div class="si-header">
		<h2><i class="fas fa-clipboard-check" style="margin-right:8px;opacity:0.9"></i>Park Sign-in</h2>
		<p><?= htmlspecialchars($scope_name) ?></p>
	</div>
	<div class="si-body">

		<?php if ($error): ?>
			<div class="si-error"><i class="fas fa-exclamation-circle" style="margin-right:6px"></i><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>

		<div class="si-meta">
			<i class="fas fa-coins"></i><strong><?= (float)($link['Credits'] ?? 1) ?> credit<?= (float)($link['Credits'] ?? 1) != 1 ? 's' : '' ?></strong> will be recorded &nbsp;&middot;&nbsp;
			<i class="fas fa-clock"></i> Expires <?= htmlspecialchars(date('D M j, g:i a T', strtotime($link['ExpiresAt'] ?? 'now'))) ?>
		</div>

		<form method="post" action="<?= UIR ?>SignIn/index/<?= htmlspecialchars($link_token) ?>" id="si-form">
			<input type="hidden" name="ClassId" id="si-class-id-input" value="<?= $last_class_id ?>">

			<?php if ($last_class_id > 0): ?>
				<!-- Quick sign-in with last class -->
				<button type="submit" class="si-btn-primary" id="si-quick-btn"
					onclick="document.getElementById('si-class-id-input').value=<?= $last_class_id ?>">
					<i class="fas fa-check" style="margin-right:8px"></i>Sign In As <?= htmlspecialchars($last_class_name) ?>
				</button>
				<button type="button" class="si-change-toggle" id="si-change-toggle">
					<i class="fas fa-chevron-down" id="si-toggle-icon" style="margin-right:4px"></i>Choose a Different Class
				</button>
				<!-- Class picker (hidden by default) -->
				<div class="si-class-picker" id="si-class-picker" style="display:none">
					<label for="si-class-select">Choose a Different Class</label>
					<select id="si-class-select">
						<option value="">— select a class —</option>
						<?php foreach ($classes as $c): ?>
							<option value="<?= (int)$c['ClassId'] ?>"><?= htmlspecialchars($c['Name']) ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="si-btn-primary" id="si-alt-btn" disabled>
						<i class="fas fa-check" style="margin-right:6px"></i>Sign In
					</button>
				</div>
			<?php else: ?>
				<!-- No last class — show picker directly -->
				<div class="si-class-picker" id="si-class-picker" style="display:block">
					<label for="si-class-select">Select Your Class</label>
					<select id="si-class-select">
						<option value="">— select a class —</option>
						<?php foreach ($classes as $c): ?>
							<option value="<?= (int)$c['ClassId'] ?>"><?= htmlspecialchars($c['Name']) ?></option>
						<?php endforeach; ?>
					</select>
					<button type="submit" class="si-btn-primary" id="si-alt-btn" disabled>
						<i class="fas fa-check" style="margin-right:6px"></i>Sign In
					</button>
				</div>
			<?php endif; ?>
		</form>
	</div>
<?php endif; ?>

</div>

<script>

(function() {
	var pickerOpen = false;

	function setSubmitting(btn) {
		btn.disabled = true;
		btn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px"></i>Signing in&hellip;';
	}

	// Toggle class picker
	var toggle = document.getElementById('si-change-toggle');
	if (toggle) {
		toggle.addEventListener('click', function() {
			var picker = document.getElementById('si-class-picker');
			var icon   = document.getElementById('si-toggle-icon');
			pickerOpen = !pickerOpen;
			picker.style.display = pickerOpen ? 'block' : 'none';
			icon.className = pickerOpen ? 'fas fa-chevron-up' : 'fas fa-chevron-down';
		});
	}

	// Enable alt Sign In button when a class is selected
	var classSelect = document.getElementById('si-class-select');
	if (classSelect) {
		classSelect.addEventListener('change', function() {
			var altBtn = document.getElementById('si-alt-btn');
			if (altBtn) altBtn.disabled = !this.value;
			document.getElementById('si-class-id-input').value = this.value;
		});
	}

	// Spinner on submit
	var form = document.getElementById('si-form');
	if (form) {
		form.addEventListener('submit', function() {
			var quickBtn = document.getElementById('si-quick-btn');
			var altBtn   = document.getElementById('si-alt-btn');
			if (quickBtn && !quickBtn.disabled) setSubmitting(quickBtn);
			if (altBtn   && !altBtn.disabled)   setSubmitting(altBtn);
		});
	}
})();
</script>
