<form action='<?=UIR?>Login/forgotpassword/recover' method='POST'>
	<div class="login-box">
		<h3>Forgot Password</h3>
		<div>
			<div><span>Username:</span><input type="text" name="username" /></div>
			<div><span>Email:</span><input type="text" name="email" /></div>
			<div><span></span><input type='submit' value='Go' /></div>
		</div>
		<?php
			if (strlen($error) > 0) {
				echo "<div class='error-message'>$error<div class='error-detail'>$detail</div></div>";
			}
		?>
	</div>
</form>