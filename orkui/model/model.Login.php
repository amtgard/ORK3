<?php

class Model_Login extends Model {

	function __construct() {
		parent::__construct();
		$this->Authorization = new APIModel('Authorization');
	}
	
	function logout($userid) {
		unset($this->session->user_id);
		unset($this->session->user_name);
		unset($this->session->token);
		unset($this->session->timeout);
	}
	
	function login($username, $password) {
		$r = $this->Authorization->Authorize(array( 'UserName' => $username, 'Password' => $password, 'Token' => null ));
		if ($r['Status']['Status'] != 0) {
			return $r;
		}

		if (trimlen($r['Token']) == 0) {
			$r['Status']['Status'] = 1001;
			$r['Status']['Error'] = 'Login successful but no token was generated.';
			return $r;
		}

		$this->session->user_id = $r['UserId'];
		$this->session->user_name = $username;
		$this->session->token = $r['Token'];
		$this->session->timeout = $r['Timeout'];
		return true;
	}
	
	function recover_password($username, $email) {
		$r = $this->Authorization->ResetPassword(array( 'UserName' => $username, 'Email' => $email ));
		if ($r['Status'] != 0) {
			return $r;
		} else {
			return true;
		}
	}
}

?>