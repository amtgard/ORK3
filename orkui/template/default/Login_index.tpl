<form action='<?=UIR?>Login/login' method='POST'>
	<div class="login-box">
		<h3>Login</h3>
		<div>
			<div><span>Username:</span><input type="text" name="username" /></div>
			<div><span>Password:</span><input type="password" name="password" /></div>
			<div><span></span><input type='submit' value='Log In' /></div>
		</div>
		<a href='<?=UIR?>Login/forgotpassword'>I forgot my password.</a>
		<?php
			if (strlen($error) > 0) {
				echo "<div class='error-message'>$error<div class='error-detail'>$detail</div></div>";
			}
		?>
	</div>
</form>