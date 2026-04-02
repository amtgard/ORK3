<?php
	$error  = isset($error)  ? $error  : '';
	$detail = isset($detail) ? $detail : '';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<style>
/* ===========================
   Login Page (lg-)
   =========================== */
.lg-wrap {
	display: flex;
	align-items: stretch;
	gap: 0;
	min-height: 560px;
	border-radius: 12px;
	overflow: hidden;
	box-shadow: 0 8px 32px rgba(0,0,0,0.18);
	margin: 24px auto;
	max-width: 920px;
}

/* Left panel — form */
.lg-form-panel {
	flex: 0 0 380px;
	background: #fff;
	padding: 44px 40px 36px;
	display: flex;
	flex-direction: column;
}
.lg-logo-row {
	display: flex;
	align-items: center;
	gap: 12px;
	margin-bottom: 28px;
}
.lg-logo-sword {
	font-size: 28px;
	color: #2c5282;
}
.lg-logo-text {
	font-size: 20px;
	font-weight: 700;
	color: #1a202c;
	letter-spacing: 0.3px;
}
.lg-logo-text span {
	color: #2c5282;
}
.lg-heading {
	font-size: 22px;
	font-weight: 700;
	color: #1a202c;
	margin: 0 0 4px 0;
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
}
.lg-subheading {
	font-size: 13px;
	color: #718096;
	margin: 0 0 24px 0;
}
.lg-field {
	margin-bottom: 16px;
}
.lg-label {
	display: block;
	font-size: 12px;
	font-weight: 600;
	color: #4a5568;
	text-transform: uppercase;
	letter-spacing: 0.5px;
	margin-bottom: 6px;
}
.lg-input {
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
.lg-input:focus {
	outline: none;
	border-color: #4299e1;
	box-shadow: 0 0 0 3px rgba(66,153,225,0.15);
	background: #fff;
}
.lg-btn-primary {
	width: 100%;
	padding: 11px;
	background: #2c5282;
	color: #fff;
	border: none;
	border-radius: 6px;
	font-size: 15px;
	font-weight: 600;
	cursor: pointer;
	transition: background 0.15s, transform 0.1s;
	margin-top: 4px;
}
.lg-btn-primary:hover {
	background: #2a4a7f;
}
.lg-btn-primary:active {
	transform: scale(0.98);
}
.lg-divider {
	display: flex;
	align-items: center;
	gap: 10px;
	margin: 18px 0;
	color: #a0aec0;
	font-size: 12px;
}
.lg-divider::before,
.lg-divider::after {
	content: '';
	flex: 1;
	height: 1px;
	background: #e2e8f0;
}
.lg-btn-oauth {
	width: 100%;
	padding: 10px 12px;
	background: #fff;
	border: 1px solid #cbd5e0;
	border-radius: 6px;
	font-size: 14px;
	font-weight: 500;
	color: #2d3748;
	cursor: pointer;
	display: flex;
	align-items: center;
	justify-content: center;
	gap: 10px;
	transition: background 0.15s, border-color 0.15s;
}
.lg-btn-oauth:hover {
	background: #f7fafc;
	border-color: #a0aec0;
}
.lg-btn-oauth img {
	height: 22px;
	width: auto;
}
.lg-links {
	margin-top: 16px;
	font-size: 13px;
	color: #718096;
	display: flex;
	flex-direction: column;
	gap: 6px;
}
.lg-links a {
	color: #3182ce;
	text-decoration: none;
}
.lg-links a:hover {
	text-decoration: underline;
}
.lg-no-account {
	margin-top: 20px;
	padding: 12px 14px;
	background: #ebf8ff;
	border: 1px solid #bee3f8;
	border-radius: 8px;
	font-size: 13px;
	color: #2b6cb0;
	line-height: 1.5;
}
.lg-no-account i {
	margin-right: 6px;
	opacity: 0.75;
}
.lg-error {
	margin-top: 14px;
	padding: 10px 12px;
	background: #fff5f5;
	border: 1px solid #fed7d7;
	border-radius: 6px;
	font-size: 13px;
	color: #c53030;
}
.lg-error-detail {
	font-weight: 600;
	margin-top: 4px;
}

/* Right panel — features */
.lg-features-panel {
	flex: 1;
	background: linear-gradient(145deg, #1e3a5f 0%, #2c5282 60%, #2b4c7e 100%);
	padding: 44px 40px;
	display: flex;
	flex-direction: column;
	color: #fff;
	position: relative;
	overflow: hidden;
}
.lg-features-panel::before {
	content: '';
	position: absolute;
	top: -60px; right: -60px;
	width: 280px; height: 280px;
	border-radius: 50%;
	background: rgba(255,255,255,0.04);
}
.lg-features-panel::after {
	content: '';
	position: absolute;
	bottom: -80px; left: -40px;
	width: 220px; height: 220px;
	border-radius: 50%;
	background: rgba(255,255,255,0.04);
}
.lg-features-heading {
	font-size: 20px;
	font-weight: 700;
	margin: 0 0 6px 0;
	color: #fff;
	background: transparent !important;
	border: none !important;
	padding: 0 !important;
	border-radius: 0 !important;
	text-shadow: none !important;
	position: relative;
	z-index: 1;
}
.lg-features-sub {
	font-size: 13px;
	color: rgba(255,255,255,0.65);
	margin: 0 0 28px 0;
	position: relative;
	z-index: 1;
}
.lg-feature-list {
	list-style: none;
	margin: 0 0 28px 0;
	padding: 0;
	display: flex;
	flex-direction: column;
	gap: 16px;
	position: relative;
	z-index: 1;
}
.lg-feature-item {
	display: flex;
	align-items: flex-start;
	gap: 14px;
}
.lg-feature-icon {
	flex-shrink: 0;
	width: 36px;
	height: 36px;
	border-radius: 8px;
	background: rgba(255,255,255,0.12);
	display: flex;
	align-items: center;
	justify-content: center;
	font-size: 16px;
	color: #90cdf4;
}
.lg-feature-text strong {
	display: block;
	font-size: 14px;
	font-weight: 600;
	color: #fff;
	margin-bottom: 2px;
}
.lg-feature-text span {
	font-size: 12px;
	color: rgba(255,255,255,0.6);
	line-height: 1.4;
}
.lg-benefits-heading {
	font-size: 13px;
	font-weight: 700;
	text-transform: uppercase;
	letter-spacing: 0.8px;
	color: rgba(255,255,255,0.5);
	margin: 0 0 12px 0;
	position: relative;
	z-index: 1;
}
.lg-benefit-tags {
	display: grid;
	grid-template-columns: 1fr 1fr;
	gap: 8px;
	position: relative;
	z-index: 1;
}
.lg-benefit-tag {
	padding: 7px 11px;
	border-radius: 8px;
	background: rgba(255,255,255,0.1);
	border: 1px solid rgba(255,255,255,0.18);
	font-size: 12px;
	color: rgba(255,255,255,0.85);
	display: flex;
	align-items: center;
	gap: 7px;
}
.lg-benefit-tag i {
	font-size: 11px;
	opacity: 0.75;
}

/* Responsive */
@media (max-width: 700px) {
	.lg-wrap {
		flex-direction: column;
		max-width: 100%;
		border-radius: 8px;
	}
	.lg-form-panel {
		flex: unset;
		padding: 32px 24px 28px;
	}
	.lg-features-panel {
		padding: 28px 24px;
	}
}
</style>

<div class="lg-wrap">

	<!-- =============================================
	     LEFT: Login Form
	     ============================================= -->
	<div class="lg-form-panel">
		<div class="lg-logo-row">
			<i class="fas fa-shield-alt lg-logo-sword"></i>
			<div class="lg-logo-text">Amtgard <span>Online Record Keeper</span></div>
		</div>

		<h2 class="lg-heading">Welcome back</h2>
		<p class="lg-subheading">Sign in to access your records and community</p>

		<form action="<?= UIR ?>Login/login" method="POST">
			<div class="lg-field">
				<label class="lg-label" for="lg-username">Username</label>
				<input class="lg-input" type="text" id="lg-username" name="username" autocomplete="username" autofocus />
			</div>
			<div class="lg-field">
				<label class="lg-label" for="lg-password">Password</label>
				<input class="lg-input" type="password" id="lg-password" name="password" autocomplete="current-password" value="<?= htmlspecialchars($_GET['pw'] ?? '') ?>" />
			</div>
			<button type="submit" class="lg-btn-primary">
				<i class="fas fa-sign-in-alt" style="margin-right:7px"></i> Sign In
			</button>
		</form>

		<div class="lg-divider">or</div>

		<button type="button" class="lg-btn-oauth" onclick="window.location='<?= UIR ?>Login/login_oauth'">
			<img src="<?= HTTP_ASSETS ?>images/amtgard_idp_favicon.png" alt="Amtgard IDP" />
			Sign in with Amtgard
		</button>

		<div class="lg-links">
			<a href="<?= UIR ?>Login/forgotpassword"><i class="fas fa-key" style="margin-right:4px;opacity:0.6"></i>Forgot your password?</a>
		</div>

		<?php if (strlen($error) > 0): ?>
			<div class="lg-error">
				Login failed. If you cannot remember your password, use the <strong>Forgot your password?</strong> link above.
			</div>
		<?php endif; ?>

		<div class="lg-no-account">
			<i class="fas fa-info-circle"></i>
			<strong>Don't have an account?</strong> Your local park's officers can create one for you as you start participating.
			Reach out to your park officers to get started.
		</div>
	</div>

	<!-- =============================================
	     RIGHT: Features & Benefits
	     ============================================= -->
	<div class="lg-features-panel">
		<h3 class="lg-features-heading">The Online Record Keeper</h3>
		<p class="lg-features-sub">Everything you need to track your Amtgard journey</p>

		<ul class="lg-feature-list">
			<li class="lg-feature-item">
				<div class="lg-feature-icon"><i class="fas fa-calendar-check"></i></div>
				<div class="lg-feature-text">
					<strong>Attendance Tracking</strong>
					<span>Every battle game counts. Your attendance record is maintained for dues and class credit purposes.</span>
				</div>
			</li>
			<li class="lg-feature-item">
				<div class="lg-feature-icon"><i class="fas fa-chess-king"></i></div>
				<div class="lg-feature-text">
					<strong>Class Levels &amp; Progression</strong>
					<span>Track your character class levels across Warrior, Wizard, Healer, Scout, and all other classes.</span>
				</div>
			</li>
			<li class="lg-feature-item">
				<div class="lg-feature-icon"><i class="fas fa-medal"></i></div>
				<div class="lg-feature-text">
					<strong>Awards &amp; Titles</strong>
					<span>Celebrate your wins! View your full award history and titles earned across all your parks.</span>
				</div>
			</li>
			<li class="lg-feature-item">
				<div class="lg-feature-icon"><i class="fas fa-map-marked-alt"></i></div>
				<div class="lg-feature-text">
					<strong>Parks &amp; Kingdoms</strong>
					<span>Find parks near you, see upcoming events, and explore the broader Amtgard community.</span>
				</div>
			</li>
			<li class="lg-feature-item">
				<div class="lg-feature-icon"><i class="fas fa-scroll"></i></div>
				<div class="lg-feature-text">
					<strong>Officer Rosters &amp; History</strong>
					<span>See who holds office, track officer terms, and browse your kingdom's leadership history.</span>
				</div>
			</li>
		</ul>

		<p class="lg-benefits-heading">When you log in you can</p>
		<div class="lg-benefit-tags">
			<span class="lg-benefit-tag"><i class="fas fa-star"></i> Recommend awards</span>
			<span class="lg-benefit-tag"><i class="fas fa-user-edit"></i> Edit your profile</span>
			<span class="lg-benefit-tag"><i class="fas fa-users"></i> Manage your park</span>
			<span class="lg-benefit-tag"><i class="fas fa-trophy"></i> Log attendance</span>
			<span class="lg-benefit-tag"><i class="fas fa-calendar-plus"></i> Create events</span>
			<span class="lg-benefit-tag"><i class="fas fa-shield-alt"></i> View more reports</span>
		</div>
	</div>

</div>
