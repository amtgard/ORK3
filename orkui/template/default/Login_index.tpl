<div style="width: 100%; text-align: center; margin-bottom: 16px;">
	<a href='<?=UIR?>Login/login_oauth' style="display: inline-flex; align-items: center; justify-content: center; background-color: white; border: 1px solid #ccc; padding: 10px 20px; border-radius: 4px; text-decoration: none; color: inherit; font-size: 1em; cursor: pointer; width: 100%; box-sizing: border-box; white-space: nowrap;">Sign in with Amtgard<img src="<?=HTTP_ASSETS?>images/amtgard_idp_favicon.png" style="height: 32px; margin-left: 10px;" /></a>
</div>
<details>
	<summary style="cursor: pointer; margin-bottom: 8px;">Use legacy ORK login</summary>
	<form action='<?=UIR?>Login/login' method='POST'>
		<div class="login-box">
			<h3>Login</h3>
			<div>
				<div><span>Username:</span><input type="text" name="username" /></div>
				<div><span>Password:</span><input type="password" name="password" value='<?= htmlspecialchars($_GET['pw'] ?? '') ?>' /></div>
				<div><span></span><input type='submit' value='Log In' /></div>

			</div>
			<a href='<?=UIR?>Login/forgotpassword'>I forgot my password.</a><br><br>
			<a href="#" id="lc-different-account" style="font-size: 0.875em;">Sign in with a different Amtgard account</a>
			<?php
				if (strlen($error) > 0) {
					echo "<div class='error-message'>$error<div class='error-detail' style='line-height: 2em; font-weight: bold;'>$detail</div></div>";
				}
			?>
		</div>
	</form>
</details>
<script>
(function() {
	if (document.cookie.indexOf('ork_idp_autoredirect=1') !== -1) {
		window.location = '<?= UIR ?>Login/login_oauth';
		return;
	}
	var diff = document.getElementById('lc-different-account');
	if (diff) {
		diff.addEventListener('click', function(e) {
			e.preventDefault();
			document.cookie = 'ork_idp_autoredirect=0; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/';
			location.reload();
		});
	}
})();
</script>
