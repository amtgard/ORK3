<form action='<?=UIR?>Login/login' method='POST'>
	<div class="login-box">
		<h3>Login</h3>
		<div>
			<div><span>Username:</span><input type="text" name="username" /></div>
			<div><span>Password:</span><input type="password" name="password" value='<?=$_GET['pw'] ?>' /></div>
			<div><span></span><input type='submit' value='Log In' /></div>

		</div>
		<a href='<?=UIR?>Login/forgotpassword'>I forgot my password.</a><br><br>
		<div style="width: 100%; text-align: center;"><button type='button' onclick="window.location='<?=UIR?>Login/login_oauth'" style="background-color: white; border: 1px solid #ccc; padding: 10px; border-radius: 4px; display: flex; align-items: center; justify-content: center; cursor: pointer; width: 100%; white-space: nowrap;">Login with Amtgard<img src="<?=HTTP_ASSETS?>images/amtgard_idp_favicon.png" style="height: 32px; margin-left: 10px;" /></button></div>
		<?php
			if (strlen($error) > 0) {
				echo "<div class='error-message'>$error<div class='error-detail' style='line-height: 2em; font-weight: bold;'>$detail</div></div>";
			}
		?>
	</div>
</form>