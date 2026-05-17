<?php
	$error   = isset($error)   ? $error   : '';
	$notice  = isset($notice)  ? $notice  : '';
	$idp_email = isset($idp_email) ? $idp_email : '';
?>
<link rel="stylesheet" href="<?= HTTP_TEMPLATE ?>revised-frontend/style/revised.css?v=<?= filemtime(DIR_TEMPLATE . 'revised-frontend/style/revised.css') ?>">

<style>
.lc-card { max-width: 480px; margin: 60px auto; background: #fff; border-radius: 8px; box-shadow: 0 2px 12px rgba(0,0,0,0.08); padding: 32px; }
.lc-card h2 { background: transparent; border: none; padding: 0; border-radius: 0; text-shadow: none; margin: 0 0 8px 0; font-size: 22px; }
.lc-card .lc-sub { color: #666; margin-bottom: 24px; font-size: 14px; }
.lc-card label { display: block; font-weight: 600; margin: 12px 0 4px; }
.lc-card input[type=text], .lc-card input[type=password] { width: 100%; padding: 10px; border: 1px solid #ccc; border-radius: 4px; font-size: 15px; box-sizing: border-box; }
.lc-card .lc-primary { width: 100%; margin-top: 20px; padding: 12px; background: #b22222; color: #fff; border: none; border-radius: 4px; font-size: 16px; font-weight: 600; cursor: pointer; }
.lc-card .lc-primary:hover { background: #8b0000; }
.lc-card .lc-secondary { display: block; margin-top: 16px; text-align: center; color: #555; font-size: 13px; }
.lc-card .lc-footer { margin-top: 24px; padding-top: 16px; border-top: 1px solid #eee; color: #777; font-size: 13px; }
.lc-card .lc-error { background: #fee; color: #900; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
.lc-card .lc-notice { background: #eef7ee; color: #2a5a2a; padding: 10px 12px; border-radius: 4px; margin-bottom: 16px; font-size: 14px; }
.lc-tabs { display: flex; gap: 0; margin-bottom: 16px; border-bottom: 1px solid #ddd; }
.lc-tab { flex: 1; padding: 10px; text-align: center; cursor: pointer; color: #666; border-bottom: 2px solid transparent; }
.lc-tab.lc-active { color: #b22222; border-bottom-color: #b22222; font-weight: 600; }
.lc-pane { display: none; }
.lc-pane.lc-active { display: block; }
</style>

<div class="lc-card">
	<h2>Connect your ORK profile</h2>
	<div class="lc-sub">You're signed in as <b><?= htmlspecialchars($idp_email) ?></b> via Amtgard IDP. Connect your existing ORK profile to finish.</div>

	<?php if (strlen($error) > 0): ?><div class="lc-error"><?= htmlspecialchars($error) ?></div><?php endif; ?>
	<?php if (strlen($notice) > 0): ?><div class="lc-notice"><?= htmlspecialchars($notice) ?></div><?php endif; ?>

	<div class="lc-tabs">
		<div class="lc-tab lc-active" data-tab="pwd">Use my password</div>
		<div class="lc-tab" data-tab="email">Email me a link</div>
	</div>

	<div class="lc-pane lc-active" data-pane="pwd">
		<form method="post" action="<?= UIR ?>Login/claim_submit">
			<label for="lc-username">ORK username</label>
			<input type="text" id="lc-username" name="username" autocomplete="username" required>
			<label for="lc-password">ORK password</label>
			<input type="password" id="lc-password" name="password" autocomplete="current-password" required>
			<button type="submit" class="lc-primary">Connect ORK profile</button>
		</form>
	</div>

	<div class="lc-pane" data-pane="email">
		<form method="post" action="<?= UIR ?>Login/claim_request_magic_link">
			<label for="lc-username-email">ORK username</label>
			<input type="text" id="lc-username-email" name="username" autocomplete="username" required>
			<button type="submit" class="lc-primary">Email me a one-time link</button>
			<div class="lc-secondary">We'll send the link to the email address on file for that username.</div>
		</form>
	</div>

	<div class="lc-footer">
		Don't have an ORK profile yet? Ask your park's Prime Minister to create one for you, then come back here.
	</div>
</div>

<script>
(function() {
	var tabs = document.querySelectorAll('.lc-card .lc-tab');
	var panes = document.querySelectorAll('.lc-card .lc-pane');
	tabs.forEach(function(t) {
		t.addEventListener('click', function() {
			tabs.forEach(function(x) { x.classList.remove('lc-active'); });
			panes.forEach(function(x) { x.classList.remove('lc-active'); });
			t.classList.add('lc-active');
			var name = t.getAttribute('data-tab');
			document.querySelector('.lc-card .lc-pane[data-pane="' + name + '"]').classList.add('lc-active');
		});
	});
})();
</script>
