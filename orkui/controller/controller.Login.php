<?php

class Controller_Login extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
        $this->load_model('AmtgardIdp');
		$this->data['page_title'] = 'Login';
	}

	public function index($action = null) {

	}

	public function logout($userid = null){
		$this->session->location = null;
		$this->Login->logout($userid);
		header('Location: ' . UIR);
	}

	public function login($location = null) {
		$this->template = 'Login_index.tpl';
		if (strlen(trim($this->session->location)) == 0) {
			$this->session->location = $location;
		}

		if ((strlen($this->request->username) > 0 && strlen($this->request->password) > 0) && ($r = $this->Login->login($this->request->username, $this->request->password)) === true) {
			if ($this->session->location == null) {
				header('Location: ' . UIR);
			} else {
				//$this->session->location = null;
				header('Location: ' . UIR . $this->session->location);
			}
		} else {
			$this->data["error"] = $r['Status']['Error'];
			$this->data["detail"] = $r['Status']['Detail'];
		}
	}

	public function forgotpassword($recover = null) {
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

	private function base64UrlEncode($data)
	{
		return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
	}

	public function login_oauth()
	{
		$code_verifier = $this->base64UrlEncode(random_bytes(32));
		$code_challenge = $this->base64UrlEncode(hash('sha256', $code_verifier, true));

		$this->session->code_verifier = $code_verifier;

		$query = http_build_query([
			'client_id' => IDP_CLIENT_ID,
			'redirect_uri' => UIR . 'Login/oauth_callback',
			'response_type' => 'code',
			'scope' => 'profile email',
			'code_challenge' => $code_challenge,
			'code_challenge_method' => 'S256',
		]);
		header('Location: ' . IDP_BASE_URL . '/oauth/authorize?' . $query);
		exit;
	}

	public function oauth_callback()
	{
		if (!isset($_GET['code'])) {
			$this->data['error'] = 'No authorization returned from Amtgard IDP';
			$this->template = 'Login_index.tpl';
			return;
		}

		$token_data = $this->AmtgardIdp->exchangeAuthCodeForAccessToken($_GET['code'], $this->session->code_verifier);
		$user_data = $this->AmtgardIdp->fetchUserInfo($token_data['access_token']);

		if (isset($user_data['error'])) {
			error_log("Amtgard IDP OAuth callback: Failed to get user info: " . $user_data['response']);
			$this->data['error'] = 'Failed to get user info';
			$this->data['detail'] = $user_data['response'];
			$this->template = 'Login_index.tpl';
			return;
		}

		error_log("Amtgard IDP OAuth callback: User Data: " . print_r($user_data, true));

		$result = $this->authorizeUser($user_data, $token_data);

		error_log("Amtgard IDP OAuth callback: AuthorizeIdp Result: " . print_r($result, true));

		if ($result['Status']['Status'] === 0) {
			$this->session->user_id = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token = $result['Token'];
			$this->session->timeout = $result['Timeout'];
			header('Location: ' . UIR);
		} else {
			$this->data['error'] = $result['Status']['Error'];
			$this->data['detail'] = $result['Status']['Detail'];
			$this->template = 'Login_index.tpl';
		}
	}

	private function authorizeUser($userData, $tokenData)
	{
		$mundane_id = null;
		if (isset($userData['ork_profile']) && isset($userData['ork_profile']['mundane_id'])) {
			$mundane_id = $userData['ork_profile']['mundane_id'];
		}

		$this->session->IdpUserId = $userData['id'];
		$this->session->Email = $userData['email'];
		$this->session->MundaneId = $mundane_id;
		$this->session->AccessToken = $tokenData['access_token'];
		$this->session->RefreshToken = $tokenData['refresh_token'] ?? null;
		$this->session->ExpiresAt = time() + ($tokenData['expires_in'] ?? 3600);

		return $this->Login->Authorization->AuthorizeIdp();
	}
}