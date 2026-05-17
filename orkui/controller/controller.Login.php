<?php

class Controller_Login extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
        $this->load_model('AmtgardIdp');
		$this->data['page_title'] = 'Login';
	}

	public function index($action = null) {
		$this->template = '../revised-frontend/Login_index.tpl';
		if (!empty($_GET['return'])) {
			$_ret = trim($_GET['return']);
			if ($_ret !== '' && strncasecmp($_ret, 'Login', 5) !== 0) {
				$this->session->location = $_ret;
			}
		}
		if (($_GET['msg'] ?? '') === 'session_replaced') {
			$this->data['session_message'] = 'You were logged in from another device or browser. Please log in again.';
		}
		if (($_GET['msg'] ?? '') === 'link_failed') {
			$this->data['session_message'] = 'Your IDP link expired before we could finish. Please try again.';
		}
		$this->populateWelcomeBack();
	}

	/**
	 * Set both the autoredirect-opt-in cookie AND a non-HttpOnly remembered
	 * persona cookie so the Welcome Back interstitial on the Login page can
	 * greet the user by name. Both cookies last one year; user can clear via
	 * the "Not you?" link on the interstitial, which routes through logout().
	 */
	/**
	 * Populate $this->data with the Welcome Back interstitial state. The
	 * template renders the welcome panel when ShowWelcomeBack is true and
	 * the legacy form when false. Driven entirely by client-set cookies;
	 * server holds no remembered-user state.
	 */
	private function populateWelcomeBack()
	{
		$auto = ($_COOKIE['ork_idp_autoredirect'] ?? '') === '1';
		$persona = (string)($_COOKIE['ork_idp_last_persona'] ?? '');
		$this->data['ShowWelcomeBack'] = $auto;
		$this->data['WelcomePersona']  = $persona;
	}

	private function setIdpAutoredirectCookies($mundaneId)
	{
		$mundaneId = (int)$mundaneId;
		$display = '';
		if ($mundaneId > 0) {
			global $DB;
			$DB->Clear();
			$DB->mundane_id = $mundaneId;
			$rs = $DB->DataSet('SELECT persona, username FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = :mundane_id LIMIT 1');
			if ($rs && $rs->Next()) {
				$persona = trim((string)$rs->persona);
				$userName = trim((string)$rs->username);
				$display = $persona !== '' ? $persona : $userName;
			}
			$DB->Clear();
		}
		$exp = time() + 60 * 60 * 24 * 365;
		setcookie('ork_idp_autoredirect', '1', $exp, '/');
		setcookie('ork_idp_last_persona', $display, $exp, '/');
	}

	public function logout($userid = null){
		$this->session->location = null;
		$this->Login->logout($userid);

		// Clear the autoredirect cookie so the next visit lands on the legacy
		// form (or any escape hatch the user wants) — without this, an ORK
		// logout would be undone on the next page load by the inline JS
		// auto-jumping back to /Login/login_oauth.
		setcookie('ork_idp_autoredirect', '0', time() - 3600, '/');
		setcookie('ork_idp_last_persona', '', time() - 3600, '/');

		// OIDC RP-initiated logout: send the user through the IDP's
		// /auth/logout to clear the IDP session too, then bounce back to
		// ORK's login page. The IDP validates post_logout_redirect_uri
		// against its configured ORK_BASE_URL so this isn't an open redirect.
		$returnTo = HTTP_UI_REMOTE . 'index.php?Route=Login';
		$idpLogoutUrl = IDP_BASE_URL . '/auth/logout?post_logout_redirect_uri=' . urlencode($returnTo);
		header('Location: ' . $idpLogoutUrl);
	}

	public function login($location = null) {
		$this->template = '../revised-frontend/Login_index.tpl';
		if (($_GET['msg'] ?? '') === 'session_replaced') {
			$this->data['session_message'] = 'You were logged in from another device or browser. Please log in again.';
		}
		$this->populateWelcomeBack();
		if (!empty($_GET['return'])) {
			$_ret = trim($_GET['return']);
			if ($_ret !== '' && strncasecmp($_ret, 'Login', 5) !== 0) {
				$this->session->location = $_ret;
			}
		}
		if (strlen(trim($this->session->location)) == 0) {
			$this->session->location = $location;
		}

		if ((strlen($this->request->username) > 0 && strlen($this->request->password) > 0) && ($r = $this->Login->login($this->request->username, $this->request->password)) === true) {
			if ($this->session->location == null) {
				$uid = (int)$this->session->user_id;
				header('Location: ' . UIR . ($uid > 0 ? 'Player/profile/' . $uid : ''));
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
		$this->template = '../revised-frontend/Login_forgotpassword.tpl';
		if ($recover == 'recover') {
			if (($r = $this->Login->recover_password($_POST['username'], $_POST['email'])) === true) {
				$this->data["error"] = "A new password has been emailed to you. The new password will expire in 24 hours. Please log in and change your password immediately.";
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
			$this->data['error'] = 'IDP did not return an authorization code.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$token_data = $this->AmtgardIdp->exchangeAuthCodeForAccessToken($_GET['code'], $this->session->code_verifier);

		if (isset($token_data['error'])) {
			error_log("Amtgard IDP OAuth callback: Token exchange failed");
			$this->data['error'] = "Couldn't exchange the IDP authorization code. Try again or use legacy login.";
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$user_data = $this->AmtgardIdp->fetchUserInfo($token_data['access_token']);

		if (isset($user_data['error'])) {
			error_log("Amtgard IDP OAuth callback: Failed to get user info: " . $user_data['response']);
			$this->data['error'] = "Couldn't reach Amtgard IDP. Try again or use legacy login.";
			$this->data['detail'] = $user_data['response'];
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		// Stash the IDP context in the session for AuthorizeIdp + the claim flow.
		$this->session->IdpUserId    = $user_data['id'];
		$this->session->Email        = $user_data['email'] ?? '';
		$this->session->MundaneId    = isset($user_data['ork_profile']['mundane_id']) ? $user_data['ork_profile']['mundane_id'] : null;
		$this->session->AccessToken  = $token_data['access_token'];
		$this->session->RefreshToken = $token_data['refresh_token'] ?? null;
		$this->session->ExpiresAt    = time() + ($token_data['expires_in'] ?? 3600);

		$result = $this->Login->Authorization->AuthorizeIdp();

		// Auto-link / existing-link: log the user in and go to dashboard.
		if (isset($result['IdpResult']) && $result['IdpResult'] === Authorization::IDP_RESULT_LOGGED_IN
			&& isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id  = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token    = $result['Token'];
			$this->session->timeout  = $result['Timeout'];
			// Power-user opt-in: came in via the IDP button. Remember persona too.
			$this->setIdpAutoredirectCookies($result['UserId']);
			if (!empty($this->session->location)) {
				$_dest = $this->session->location;
				header('Location: ' . UIR . $_dest);
			} else {
				$uid = (int)$this->session->user_id;
				header('Location: ' . UIR . ($uid > 0 ? 'Player/profile/' . $uid : ''));
			}
			return;
		}

		// Needs a manual claim — redirect to the claim form.
		if (isset($result['IdpResult']) && $result['IdpResult'] === Authorization::IDP_RESULT_NEEDS_CLAIM) {
			header('Location: ' . UIR . 'Login/claim_profile');
			return;
		}

		// Fallthrough: treat as failure.
		$this->data['error'] = $result['Status']['Error'] ?? 'Authentication failed';
		$this->data['detail'] = $result['Status']['Detail'] ?? '';
		$this->template = '../revised-frontend/Login_index.tpl';
	}

	public function claim_profile()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}
		$this->data['idp_email'] = $this->session->Email;
		// If auto-link saw multiple ORK profiles sharing the IDP email, explain the situation.
		if (isset($this->session->IdpEmailMatchCount) && $this->session->IdpEmailMatchCount > 1) {
			$this->data['notice'] = 'Multiple ORK profiles share that email — please sign in below to confirm which one is yours.';
		}
		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_submit()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$username = trim($_POST['username'] ?? '');
		$password = $_POST['password'] ?? '';
		if (strlen($username) === 0 || strlen($password) === 0) {
			$this->data['idp_email'] = $this->session->Email;
			$this->data['error'] = 'Enter both your ORK username and password.';
			$this->template = '../revised-frontend/Login_claim.tpl';
			return;
		}

		$claim = [
			'IdpUserId'    => $this->session->IdpUserId,
			'Email'        => $this->session->Email,
			'AccessToken'  => $this->session->AccessToken,
			'RefreshToken' => $this->session->RefreshToken,
			'ExpiresAt'    => $this->session->ExpiresAt,
		];

		$result = $this->Login->Authorization->verifyClaimCredentials($username, $password, $claim);

		if (isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id   = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token     = $result['Token'];
			$this->session->timeout   = $result['Timeout'];
			$this->setIdpAutoredirectCookies($result['UserId']);
			if (!empty($this->session->location)) {
				$_dest = $this->session->location;
				header('Location: ' . UIR . $_dest);
			} else {
				$uid = (int)$this->session->user_id;
				header('Location: ' . UIR . ($uid > 0 ? 'Player/profile/' . $uid : ''));
			}
			return;
		}

		$this->data['idp_email'] = $this->session->Email;
		$this->data['error'] = $result['Status']['Error'] ?? 'Username or password incorrect';
		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_request_magic_link()
	{
		if (!isset($this->session->IdpUserId) || strlen($this->session->IdpUserId) === 0) {
			$this->data['error'] = 'Session expired — please start over.';
			$this->template = '../revised-frontend/Login_index.tpl';
			return;
		}

		$username = trim($_POST['username'] ?? '');
		if (strlen($username) === 0) {
			$this->data['idp_email'] = $this->session->Email;
			$this->data['error'] = 'Enter your ORK username so we know where to send the link.';
			$this->template = '../revised-frontend/Login_claim.tpl';
			return;
		}

		$claim = [
			'IdpUserId' => $this->session->IdpUserId,
			'Email'     => $this->session->Email,
		];

		$issued = $this->Login->Authorization->issueClaimMagicLink($username, $claim);

		// Always show the same banner (no info disclosure on whether the username exists).
		$this->data['idp_email'] = $this->session->Email;
		$this->data['notice']    = 'If that username has an ORK account, we just emailed a one-time link to the address on file. Open it in this same browser to finish linking.';

		if ($issued !== false) {
			$link = UIR . 'Login/claim_magic_link?token=' . $issued['token'];
			$m = new Mail('smtp', AMAZON_SES_HOST, AMAZON_SES_USERNAME, AMAZON_SES_PASSWORD, 587);
			$m->setTo($issued['email']);
			$m->setFrom('ork3@amtgard.com');
			$m->setSender('ork3@amtgard.com');
			$m->setSubject('Connect your Amtgard ORK profile (link expires in 24 hours)');
			$m->setHtml(
				'<h2>Connect your ORK profile</h2>' .
				'You requested a one-time link to connect your ORK profile <b>' . htmlspecialchars($issued['username']) . '</b> ' .
				'to your Amtgard IDP account (' . htmlspecialchars($claim['Email']) . ').' .
				'<p><a href="' . $link . '">Click here to finish linking</a> — this link expires in 24 hours and works only once.' .
				'<p>If you did not request this, you can safely ignore this email.' .
				'<p>Regards,<br>-ORK Team'
			);
			$m->send();
		}

		$this->template = '../revised-frontend/Login_claim.tpl';
	}

	public function claim_magic_link()
	{
		$token = $_GET['token'] ?? '';
		$result = $this->Login->Authorization->consumeMagicLink($token);

		if (isset($result['Status']['Status']) && $result['Status']['Status'] === 0) {
			$this->session->user_id   = $result['UserId'];
			$this->session->user_name = $result['UserName'];
			$this->session->token     = $result['Token'];
			$this->session->timeout   = $result['Timeout'];
			$this->setIdpAutoredirectCookies($result['UserId']);
			if (!empty($this->session->location)) {
				$_dest = $this->session->location;
				header('Location: ' . UIR . $_dest);
			} else {
				$uid = (int)$this->session->user_id;
				header('Location: ' . UIR . ($uid > 0 ? 'Player/profile/' . $uid : ''));
			}
			return;
		}

		$this->data['error'] = $result['Status']['Error'] ?? "That link isn't valid.";
		$this->template = '../revised-frontend/Login_index.tpl';
	}

	/**
	 * POST target for the ORK→IDP onboarding banner.
	 * Mints a short-lived signed JWT and redirects to the IDP's /auth/connect
	 * page with the user's email prefilled. The JWT carries the mundane_id as
	 * `sub` and the IDP writes the link using that claim after the user logs in
	 * or registers.
	 */
	/**
	 * Generate (once per session) and return a random CSRF token used by the
	 * IDP-nudge POST forms. Stored on $this->session->csrf_token. Constant-time
	 * comparison via hash_equals().
	 */
	private function csrfToken()
	{
		if (!isset($this->session->csrf_token) || strlen((string)$this->session->csrf_token) < 32) {
			$this->session->csrf_token = bin2hex(random_bytes(16));
		}
		return $this->session->csrf_token;
	}

	private function csrfCheck()
	{
		$expected = isset($this->session->csrf_token) ? (string)$this->session->csrf_token : '';
		$got      = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
		return $expected !== '' && strlen($got) === strlen($expected) && hash_equals($expected, $got);
	}

	public function start_idp_connect()
	{
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . 'Login');
			return;
		}
		// CSRF: silent-reject (no info leak) by redirecting to UIR.
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->csrfCheck()) {
			header('Location: ' . UIR);
			return;
		}
		$uid = (int)$this->session->user_id;
		global $DB;
		$DB->Clear();
		$DB->mundane_id = $uid;
		$rs = $DB->DataSet("SELECT email FROM " . DB_PREFIX . "mundane WHERE mundane_id = :mundane_id LIMIT 1");
		$email = ($rs && $rs->Size() > 0 && $rs->Next()) ? (string)$rs->email : '';
		$DB->Clear();

		$jwt = Ork3::$Lib->idphandoff->mintLinkToken($uid, $email);
		$url = IDP_BASE_URL . '/auth/connect?email=' . urlencode($email) . '&link_token=' . urlencode($jwt);
		header('Location: ' . $url);
	}

	/**
	 * POST target for the banner's "Not now" button. Sets a 30-day suppression
	 * cookie and redirects back to the dashboard. Validates referer host
	 * against ORK's own host to avoid open-redirect.
	 */
	/**
	 * Endpoint the IDP redirects to after a successful /auth/connect.  Verifies
	 * a signed completion token (iss=idp, aud=ork) and writes the matching
	 * ork_idp_auth row so the dashboard banner clears and future Sign-in-with-
	 * Amtgard round-trips find the link via the existing AuthorizeIdp path.
	 */
	public function idp_link_complete()
	{
		$jwt = $_GET['t'] ?? '';
		// F1: peek (verify signature/claims, do NOT consume jti) so an expired
		// session or mundane_id mismatch doesn't permanently burn the token.
		$peek = Ork3::$Lib->idphandoff->verifyCompletionToken($jwt);
		if (!$peek) {
			header('Location: ' . UIR . 'Login?msg=link_failed');
			return;
		}
		// H4: completion token must reference the currently-logged-in user.
		// Otherwise a leaked token could attach an attacker's IDP user to a
		// victim's session (or vice versa). Checked BEFORE consuming jti so a
		// rejected attempt leaves the token usable by the legit owner.
		if (!isset($this->session->user_id) || (int)$peek['mundane_id'] !== (int)$this->session->user_id) {
			header('Location: ' . UIR . 'Login?msg=link_failed');
			return;
		}
		// Identity confirmed — now atomically verify + consume jti to prevent replay.
		// If a concurrent request consumed it between peek and consume, this returns
		// null and we treat that as "already used" (effectively link_failed).
		$claims = Ork3::$Lib->idphandoff->verifyAndConsumeCompletionToken($jwt);
		if (!$claims) {
			header('Location: ' . UIR . 'Login?msg=link_failed');
			return;
		}
		global $DB;
		$DB->Clear();
		$DB->idp_user_id = $claims['idp_user_id'];
		$DB->mundane_id  = $claims['mundane_id'];
		$existing = $DB->DataSet('SELECT authorization_id FROM ' . DB_PREFIX . 'idp_auth WHERE idp_user_id = :idp_user_id LIMIT 1');
		$DB->Clear();
		if (!$existing || $existing->Size() === 0) {
			$DB->Clear();
			$DB->idp_user_id          = $claims['idp_user_id'];
			$DB->mundane_id           = $claims['mundane_id'];
			$DB->idp_mirror_status    = 'synced';
			$DB->idp_mirror_last_attempt = date('Y-m-d H:i:s');
			$DB->created_at           = date('Y-m-d H:i:s');
			$DB->Execute('INSERT INTO ' . DB_PREFIX . 'idp_auth (idp_user_id, mundane_id, idp_mirror_status, idp_mirror_last_attempt, created_at) VALUES (:idp_user_id, :mundane_id, :idp_mirror_status, :idp_mirror_last_attempt, :created_at)');
			$DB->Clear();
		}
		// F6: session fixation defense — regenerate session id at auth state change.
		// $this->session is a wrapper; the underlying PHP session is still active,
		// so the bare php call works here.
		if (session_status() === PHP_SESSION_ACTIVE) {
			session_regenerate_id(true);
		}
		$this->setIdpAutoredirectCookies($claims['mundane_id']);
		if (!empty($this->session->location)) {
			$_dest = $this->session->location;
			header('Location: ' . UIR . $_dest);
		} else {
			$uid = (int)$this->session->user_id;
			header('Location: ' . UIR . ($uid > 0 ? 'Player/profile/' . $uid : ''));
		}
	}

	public function nudge_dismiss()
	{
		// CSRF: silent-reject to UIR on missing/bad token.
		if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !$this->csrfCheck()) {
			header('Location: ' . UIR);
			return;
		}
		$expires = time() + (30 * 24 * 60 * 60);
		$secure  = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
		setcookie('ork_idp_nudge_dismissed_until', (string)$expires, [
			'expires'  => $expires,
			'path'     => '/',
			'httponly' => true,
			'secure'   => $secure,
			'samesite' => 'Lax',
		]);
		$ref = $_SERVER['HTTP_REFERER'] ?? '';
		$host = parse_url($ref, PHP_URL_HOST);
		$ownHost = parse_url(HTTP_UI_REMOTE, PHP_URL_HOST);
		if ($ref && $host === $ownHost) {
			header('Location: ' . $ref);
		} else {
			header('Location: ' . UIR);
		}
	}
}