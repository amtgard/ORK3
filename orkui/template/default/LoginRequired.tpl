<?php
	// Generic logged-out face of a login-gated page; see Controller::require_login().
	// $preview: kicker, heading, lines[], image, button. The link-preview card
	// itself is the og block in the theme head; this body is for the person.
	$preview = is_array($preview ?? null) ? $preview : [];
	$_lrKicker  = (string)($preview['kicker'] ?? '');
	$_lrHeading = (string)($preview['heading'] ?? 'Login required');
	$_lrLines   = is_array($preview['lines'] ?? null) ? $preview['lines'] : [];
	$_lrImage   = (string)($preview['image'] ?? '');
	$_lrButton  = (string)($preview['button'] ?? 'Log in to continue');
?>
<style>
.lr-wrap { max-width: 480px; margin: 32px auto; background: #fff; border-radius: 12px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); overflow: hidden; font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; }
.lr-head { display: flex; align-items: center; gap: 16px; padding: 24px 24px 16px; }
.lr-head img { width: 72px; height: 72px; object-fit: contain; border-radius: 8px; flex: none; background: #f7fafc; }
.lr-kicker { font-size: 12px; letter-spacing: 0.06em; text-transform: uppercase; color: #718096; margin: 0 0 4px; }
.lr-heading { font-size: 22px; font-weight: 700; color: #1a202c; margin: 0; line-height: 1.2; }
.lr-body { padding: 0 24px 28px; }
.lr-line { margin: 0 0 8px; color: #4a5568; font-size: 15px; line-height: 1.4; }
.lr-btn { display: block; text-align: center; margin-top: 20px; padding: 14px; border-radius: 8px; background: #3182ce; color: #fff !important; font-weight: 600; font-size: 16px; text-decoration: none; }
.lr-btn:hover { background: #2b6cb0; }
@media (max-width: 520px) { .lr-wrap { margin: 0; border-radius: 0; box-shadow: none; min-height: 100vh; } }
html[data-theme="dark"] .lr-wrap { background: var(--ork-card-bg, #2d3748); }
html[data-theme="dark"] .lr-heading { color: var(--ork-text, #e2e8f0); }
html[data-theme="dark"] .lr-line { color: var(--ork-text-secondary, #cbd5e0); }
html[data-theme="dark"] .lr-head img { background: var(--ork-bg-tertiary, #4a5568); }
</style>
<div class="lr-wrap">
	<div class="lr-head">
		<?php if ($_lrImage !== '') : ?>
			<img src="<?= htmlspecialchars($_lrImage) ?>" alt="">
		<?php endif; ?>
		<div>
			<?php if ($_lrKicker !== '') : ?>
				<p class="lr-kicker"><?= htmlspecialchars($_lrKicker) ?></p>
			<?php endif; ?>
			<h1 class="lr-heading"><?= htmlspecialchars($_lrHeading) ?></h1>
		</div>
	</div>
	<div class="lr-body">
		<?php foreach ($_lrLines as $_lrLine) : ?>
			<p class="lr-line"><?= htmlspecialchars($_lrLine) ?></p>
		<?php endforeach; ?>
		<a class="lr-btn" href="<?= UIR ?>Login/login"><i class="fas fa-sign-in-alt"></i> <?= htmlspecialchars($_lrButton) ?></a>
	</div>
</div>
