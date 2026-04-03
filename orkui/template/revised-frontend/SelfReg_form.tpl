<?php
	$error       = $error       ?? '';
	$token       = $token       ?? '';
	$park_name   = $park_name   ?? 'a park';
	$link        = $link        ?? null;
	$form        = $form        ?? [];
	$ttl_seconds = $ttl_seconds ?? 0;
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<style>
/* ===========================
   Self-Registration Form (sr-)
   =========================== */
.sr-wrap {
	max-width: 520px;
	margin: 32px auto;
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 4px 24px rgba(0,0,0,0.12);
	overflow: hidden;
}
.sr-header {
	background: linear-gradient(135deg, #276749 0%, #1a4a30 100%);
	padding: 28px 32px 24px;
	color: #fff;
}
.sr-header h2 {
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
.sr-header p {
	margin: 0;
	font-size: 13px;
	opacity: 0.85;
}
.sr-body {
	padding: 28px 32px 32px;
}
.sr-error {
	background: #fff5f5;
	border: 1px solid #fc8181;
	color: #c53030;
	border-radius: 6px;
	padding: 10px 14px;
	margin-bottom: 20px;
	font-size: 13px;
}
.sr-field {
	margin-bottom: 16px;
}
.sr-field label {
	display: block;
	font-size: 11px;
	font-weight: 700;
	color: #718096;
	text-transform: uppercase;
	letter-spacing: 0.04em;
	margin-bottom: 4px;
}
.sr-field label .sr-req {
	color: #c53030;
}
.sr-field input[type=text],
.sr-field input[type=email],
.sr-field input[type=password] {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid #e2e8f0;
	border-radius: 6px;
	font-size: 16px;
	box-sizing: border-box;
	transition: border-color 0.15s;
}
.sr-field input:focus {
	border-color: #276749;
	outline: none;
	box-shadow: 0 0 0 3px rgba(39,103,73,0.1);
}
.sr-field-hint {
	font-size: 11px;
	color: #a0aec0;
	margin-top: 4px;
}
.sr-field-row {
	display: flex;
	gap: 12px;
}
.sr-field-row .sr-field {
	flex: 1;
}
.sr-btn-primary {
	width: 100%;
	padding: 14px;
	background: #276749;
	color: #fff;
	border: none;
	border-radius: 8px;
	font-size: 16px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s;
	display: block;
	touch-action: manipulation;
	margin-top: 8px;
}
.sr-btn-primary:hover:not(:disabled) { background: #22543d; }
.sr-btn-primary:disabled { background: #a0aec0; cursor: default; }
.sr-expired-wrap {
	text-align: center;
	padding: 40px 20px;
}
.sr-expired-wrap i {
	font-size: 48px;
	color: #c53030;
	margin-bottom: 16px;
}
.sr-expired-wrap h3 {
	font-size: 18px;
	color: #2d3748;
	margin: 12px 0 8px;
	background: transparent;
	border: none;
	padding: 0;
	border-radius: 0;
	text-shadow: none;
}
.sr-expired-wrap p {
	color: #718096;
	font-size: 14px;
}
/* A5: Client-side countdown timer */
.sr-timer {
	text-align: center;
	padding: 8px 14px;
	margin-bottom: 16px;
	font-size: 14px;
	font-weight: 600;
	color: #2d3748;
	background: #edf2f7;
	border-radius: 6px;
}
.sr-timer.sr-timer-warning {
	background: #fffbeb;
	color: #c05621;
}
.sr-timer.sr-timer-expired {
	background: #fff5f5;
	color: #c53030;
}
/* A15: Inline password mismatch error */
.sr-inline-error {
	background: #fff5f5;
	border: 1px solid #fc8181;
	color: #c53030;
	border-radius: 6px;
	padding: 8px 12px;
	margin-top: 8px;
	margin-bottom: 8px;
	font-size: 13px;
	display: none;
}
/* A16: Full-bleed mobile pattern */
@media (max-width: 560px) {
	.sr-wrap { margin: 0; border-radius: 0; box-shadow: none; min-height: 100vh; }
	.sr-header { padding: 20px 20px 16px; }
	.sr-body { padding: 20px; }
	.sr-field-row { flex-direction: column; gap: 0; }
}
</style>

<?php if ($error && !$link): ?>
<!-- Token invalid/expired: error page -->
<div class="sr-wrap">
	<div class="sr-header">
		<h2><i class="fas fa-user-plus" style="margin-right:8px"></i>Self Registration</h2>
	</div>
	<div class="sr-body">
		<div class="sr-expired-wrap">
			<i class="fas fa-exclamation-circle"></i>
			<h3>Registration Link Unavailable</h3>
			<p><?= htmlspecialchars($error) ?></p>
			<p style="margin-top:16px;font-size:13px;color:#a0aec0;">Please ask the park officer to generate a new QR code.</p>
		</div>
	</div>
</div>

<?php else: ?>
<!-- Registration form -->
<div class="sr-wrap">
	<div class="sr-header">
		<h2><i class="fas fa-user-plus" style="margin-right:8px"></i>Join <?= htmlspecialchars($park_name) ?></h2>
		<p>Create your ORK account and register with this park</p>
	</div>
	<div class="sr-body">
		<!-- A5: Client-side countdown timer -->
		<div class="sr-timer" id="sr-timer" aria-live="polite">Time remaining: <span id="sr-timer-value">--:--</span></div>

		<?php if ($error): ?>
		<div class="sr-error" role="alert"><?= htmlspecialchars($error) ?></div>
		<?php endif; ?>

		<form method="POST" action="<?= UIR ?>SelfReg/form/<?= htmlspecialchars($token) ?>" id="sr-form">
			<div class="sr-field">
				<label>Persona <span class="sr-req">*</span></label>
				<input type="text" name="Persona" id="sr-persona" placeholder="Your in-game name"
				       value="<?= htmlspecialchars($form['Persona'] ?? '') ?>" required>
				<div class="sr-field-hint">It's okay if you haven't chosen an Amtgard persona name yet. Put your first name here as well.</div>
			</div>
			<div class="sr-field-row">
				<div class="sr-field">
					<label>First Name</label>
					<input type="text" name="GivenName" id="sr-given" placeholder="Given name"
					       value="<?= htmlspecialchars($form['GivenName'] ?? '') ?>">
				</div>
				<div class="sr-field">
					<label>Last Name</label>
					<input type="text" name="Surname" id="sr-surname" placeholder="Surname"
					       value="<?= htmlspecialchars($form['Surname'] ?? '') ?>">
				</div>
			</div>
			<div class="sr-field">
				<label>Email <span class="sr-req">*</span></label>
				<input type="email" name="Email" id="sr-email" placeholder="email@example.com"
				       value="<?= htmlspecialchars($form['Email'] ?? '') ?>" required>
			</div>
			<div class="sr-field">
				<label>Username <span class="sr-req">*</span></label>
				<input type="text" name="UserName" id="sr-username" placeholder="min. 4 characters"
				       value="<?= htmlspecialchars($form['UserName'] ?? '') ?>" required minlength="4"
				       autocomplete="new-password">
				<div class="sr-field-hint" id="sr-username-hint">Default is your email address, but you can customize this if you'd like.</div>
			</div>
			<div class="sr-field-row">
				<div class="sr-field">
					<label>Password <span class="sr-req">*</span></label>
					<input type="password" name="Password" id="sr-password" placeholder="password"
					       required autocomplete="new-password">
				</div>
				<div class="sr-field">
					<label>Confirm Password <span class="sr-req">*</span></label>
					<input type="password" name="ConfirmPassword" id="sr-confirm" placeholder="confirm"
					       required autocomplete="new-password">
				</div>
			</div>
			<!-- A15: Inline password mismatch error -->
			<div class="sr-inline-error" id="sr-pw-error" role="alert">Passwords do not match.</div>
			<button type="submit" class="sr-btn-primary" id="sr-submit-btn">
				<i class="fas fa-user-plus"></i> Create Account &amp; Join
			</button>
		</form>
	</div>
</div>

<script>
(function() {
	var emailEl    = document.getElementById('sr-email');
	var usernameEl = document.getElementById('sr-username');
	var formEl     = document.getElementById('sr-form');
	var userEdited = false;

	// Track if user has manually edited the username field
	if (usernameEl) {
		usernameEl.addEventListener('input', function() {
			userEdited = true;
		});
	}

	// Auto-fill username from email
	if (emailEl && usernameEl) {
		emailEl.addEventListener('input', function() {
			if (!userEdited || usernameEl.value === '' || usernameEl.value === emailEl.value.replace(/.$/, '')) {
				usernameEl.value = emailEl.value;
				userEdited = false;
			}
		});
		emailEl.addEventListener('blur', function() {
			if (!userEdited && usernameEl.value === '') {
				usernameEl.value = emailEl.value;
			}
		});
	}

	// A15: Client-side password match validation (inline error, not alert)
	if (formEl) {
		formEl.addEventListener('submit', function(e) {
			var pw     = document.getElementById('sr-password').value;
			var cpw    = document.getElementById('sr-confirm').value;
			var errEl  = document.getElementById('sr-pw-error');
			if (pw !== cpw) {
				e.preventDefault();
				if (errEl) errEl.style.display = 'block';
				return false;
			}
			if (errEl) errEl.style.display = 'none';
			// Disable button to prevent double-submit
			document.getElementById('sr-submit-btn').disabled = true;
		});
	}

	// A5: Client-side countdown timer
	var expiresAt = Date.now() + <?= (int)($ttl_seconds ?? 900) ?> * 1000;
	var timerEl   = document.getElementById('sr-timer');
	var timerVal  = document.getElementById('sr-timer-value');
	var submitBtn = document.getElementById('sr-submit-btn');

	function updateTimer() {
		if (!timerEl || !timerVal) return;
		var remaining = Math.max(0, Math.floor((expiresAt - Date.now()) / 1000));
		if (remaining <= 0) {
			timerVal.textContent = 'Expired';
			timerEl.className = 'sr-timer sr-timer-expired';
			if (submitBtn) submitBtn.disabled = true;
			clearInterval(timerInterval);
			return;
		}
		var min = Math.floor(remaining / 60);
		var sec = remaining % 60;
		timerVal.textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
		if (remaining < 120) {
			timerEl.className = 'sr-timer sr-timer-warning';
		} else {
			timerEl.className = 'sr-timer';
		}
	}

	updateTimer();
	var timerInterval = setInterval(updateTimer, 1000);
})();
</script>
<?php endif; ?>
