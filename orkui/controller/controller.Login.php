<?php

class Controller_Login extends Controller {


	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->data[ 'page_title' ] = 'Login';
	}
	
	public function index($action = null) {

	}
	
	public function logout($userid=null) {
		$this->session->location = null;
		$this->Login->logout($userid);
		header( 'Location: '.UIR );
	}
	
	public function login($location=null) {
		$this->template = 'Login_index.tpl';
		if (strlen(trim($this->session->location)) == 0) {
			$this->session->location = $location;
		}
		
		if ((strlen($this->request->username) > 0 && strlen($this->request->password)>0) && ($r = $this->Login->login($this->request->username, $this->request->password)) === true) {
			if ($this->session->location == null) {
				header( 'Location: '.UIR );
			} else {
				//$this->session->location = null;
				header( 'Location: '.UIR.$this->session->location);
			}
		} else {
			$this->data["error"] = $r['Status']['Error'];
			$this->data["detail"] = $r['Status']['Detail'];
		}
	}
	
	public function forgotpassword($recover=null) {
		if ($recover == 'recover') {
			if (($r = $this->Login->recover_password($_POST['username'], $_POST['email'])) === true) {
				$this->data["error"] = "Your new password has been sent to you.";
				$this->data["detail"] = "";
			} else {
				$this->data["error"] = $r['Error'];
				$this->data["detail"] = $r['Detail'];
			}
		}
	}
}