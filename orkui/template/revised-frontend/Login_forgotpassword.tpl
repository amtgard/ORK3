<?php
	$error  = isset($error)  ? $error  : '';
	$detail = isset($detail) ? $detail : '';
	$isSuccess = (strlen($error) > 0 && stripos($error, 'sent') !== false);
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<style>
/* ===========================
   Forgot Password Page (fp-)
   =========================== */
.fp-wrap {
	max-width: 420px;
	margin: 40px auto;
	background: #fff;
	border-radius: 12px;
	box-shadow: 0 8px 32px rgba(0,0,0,0.12);
	padding: 44px 40px 36px;
}
.fp-logo-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 28px;
}
.fp-logo-icon {
	font-size: 26px;
	color: #2c5282;
}
.fp-logo-text {
	font-size: 18px;
	font-weight: 700;
	color: #1a202c;
}
.fp-logo-text span { color: #2c5282; }
.fp-heading {
	font-size: 21px;
	font-weight: 700;
	color: #1a202c;
	margin: 0 0 6px 0;
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
}
.fp-subheading {
	font-size: 13px;
	color: #718096;
	margin: 0 0 24px 0;
	line-height: 1.5;
}
.fp-field {
	margin-bottom: 0;
}
.fp-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}
.fp-input {
	width: 100%;
	padding: 10px 12px;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	font-size: 14px;
	color: #2d3748;
	background: #f7fafc;
	transition: border-color 0.15s, box-shadow 0.15s;
	box-sizing: border-box;
}
.fp-input:focus {
	outline: none;
	border-color: #4299e1;
	box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
	background: #fff;
}
.fp-divider {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 14px 0;
	color: #a0aec0;
	font-size: 12px;
}
.fp-divider::before,
.fp-divider::after {
	content: '';
	flex: 1;
	height: 1px;
	background: #e2e8f0;
}
.fp-btn {
	width: 100%;
	padding: 11px;
	background: #2c5282;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s;
	margin-top: 20px;
}
.fp-btn:hover { background: #2a4a7f; }
.fp-back {
	display: block;
	margin-top: 16px;
	text-align: center;
	font-size: 13px;
	color: #3182ce;
	text-decoration: none;
}
.fp-back:hover { text-decoration: underline; }
.fp-message {
	margin-top: 16px;
	padding: 10px 14px;
	border-radius: 6px;
	font-size: 13px;
	line-height: 1.5;
}
.fp-message-success {
	background: #f0fff4;
	border: 1px solid #9ae6b4;
	color: #276749;
}
.fp-message-error {
	background: #fff5f5;
	border: 1px solid #fed7d7;
	color: #c53030;
}
.fp-message-detail {
	font-weight: 600;
	margin-top: 4px;
}
</style>

<div class="fp-wrap">
	<div class="fp-logo-row">
		<i class="fas fa-shield-alt fp-logo-icon"></i>
		<div class="fp-logo-text">Amtgard <span>Online Record Keeper</span></div>
	</div>

	<h2 class="fp-heading">Reset your password</h2>
	<p class="fp-subheading">Enter both your username and email address and we'll send you a temporary password. If you cannot remember your username and/or email, reach out to your Park or Kingdom Prime Minister.</p>

	<form action="<?= UIR ?>Login/forgotpassword/recover" method="POST">
		<div class="fp-field">
			<label class="fp-label" for="fp-username">Username</label>
			<input class="fp-input" type="text" id="fp-username" name="username" autocomplete="username" autofocus />
		</div>

		<div class="fp-divider">and</div>

		<div class="fp-field">
			<label class="fp-label" for="fp-email">Email address</label>
			<input class="fp-input" type="email" id="fp-email" name="email" autocomplete="email" />
		</div>

		<button type="submit" class="fp-btn">
			<i class="fas fa-paper-plane" style="margin-right:7px"></i> Send Reset Email
		</button>
	</form>

	<?php if (strlen($error) > 0): ?>
		<div class="fp-message <?= $isSuccess ? 'fp-message-success' : 'fp-message-error' ?>">
			<?= htmlspecialchars($error) ?>
			<?php if (strlen($detail) > 0): ?>
				<div class="fp-message-detail"><?= htmlspecialchars($detail) ?></div>
			<?php endif; ?>
		</div>
	<?php endif; ?>

	<a href="<?= UIR ?>Login" class="fp-back">
		<i class="fas fa-arrow-left" style="margin-right:5px;font-size:11px"></i> Back to sign in
	</a>
</div>
