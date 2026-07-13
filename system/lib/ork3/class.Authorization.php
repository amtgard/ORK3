<?php

define('AUTH_CREATE', 'create');
define('AUTH_EDIT', 'edit');

define('AUTH_ADMIN', 'admin');
define('AUTH_PARK', 'Park');
define('AUTH_KINGDOM', 'Kingdom');
define('AUTH_EVENT', 'Event');
define('AUTH_UNIT', 'Unit');

class Authorization extends Ork3
{
	const MAX_SESSIONS_PER_USER = 3;
	const TOKEN_LENGTH = 32; // md5 hex length — session tokens are md5(...) → 32 chars

	public function __construct()
	{
		parent::__construct();
		$this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
		$this->auth = new yapo($this->db, DB_PREFIX . 'authorization');
		$this->app = new yapo($this->db, DB_PREFIX . 'application');
		$this->app_auth = new yapo($this->db, DB_PREFIX . 'application_auth');
		$this->idp_auth = new yapo($this->db, DB_PREFIX . 'idp_auth');
	}

	/*
	 *	Public API Functions First
	 */

	public function SetApplicationAuthorization($request)
	{
		if (($requester_id = $this->IsAuthorized($request['Token'])) > 0) {
			$this->app_auth->clear();
			$this->app_auth->application_auth_id = $request['ApplicationAuthorizationId'];
			$this->app_auth->mundane_id = $requester_id;
			if ($this->app_auth->find()) {
				switch ($request['Approval']) {
					case 'approved':
					case 'rejected':
						$this->app_auth->approved = $request['Approval'];
				}
				$this->app_auth->save();
			} else {
				return InvalidParameter();
			}
		} else {
			return NoAuthorization();
		}
	}

	public function RegisterApplication($request)
	{
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0) {
			$this->app->clear();
			$this->app->name = $request['Name'];
			$this->app->description = $request['Description'];
			$this->app->url = $request['Url'];
			$this->app->mundane_id = $mundane_id;
			$this->app->appid = md5(microtime() . $mundane_id . $request['Name'] . rand());
			$this->app->app_salt = md5(microtime() . $mundane_id . $request['Name'] . rand());
			$this->app->appid_expires = time() + 60 * 60 * 24 * 365;
			$this->app->save();
			Authorization::SaltPassword($this->app->app_salt, $this->app->appid . trim($request['AppSecret']), $this->app->appid_expires);
			return Success($this->app->appid);
		} else {
			return InvalidParameter();
		}
	}

	public function GetApplicationRequests($request)
	{
		$response = array('Status' => NoAuthorization(), 'ApplicationRequests' => array());
		if (($requester_id = $this->IsAuthorized($request['Token'])) > 0) {
			$sql = "select appauth.*, app.*, m.*
						from " . DB_PREFIX . "application_auth appauth
							left join " . DB_PREFIX . "application app on app.application_id = appauth.application_id
								left join " . DB_PREFIX . "mundane m on app.mundane_id = m.mundane_id
						where
							mundane_id = $requester_id";
			$r = $this->db->query($sql);
			if ($r !== false && $r->size() > 0) {
				do {
					$response['ApplicationRequests'][] = array(
						'ApplicationAuthorizationId' => $r->application_auth_id,
						'ApplicationId' => $r->application_id,
						'MundaneId' => $r->mundane_id,
						'Approved' => $r->approved,
						'Name' => $r->name,
						'Url' => $r->url,
						'Persona' => $r->persona,
						'GivenName' => $r->given_name,
						'Surname' => $r->surname,
						'Email' => $r->email
					);
				} while ($r->next());
			}
			$response['Status'] = Success();
		}
		return $response;
	}

	public function RequestAuthorization($request)
	{
		if ($this->ApplicationIsAuthorized($request)) {
			$this->app_auth->clear();
			$this->app_auth->application_id = $this->app->application_id;
			$this->app_auth->mundane_id = $request['MundaneId'];
			$this->app_auth->approved = 'submitted';
			$this->app_auth->appauthkey = md5(microtime() . $this->app->application_id . rand());
			$this->app_auth->save();
			return Success($this->app_auth->appauthkey);
		} else {
			return NoAuthorization();
		}
	}

	public function ResetPassword($request)
	{
		$response = array();
		$this->mundane->clear();
		$this->mundane->like('username', trim($request['UserName']));
		$this->mundane->like('email', trim($request['Email']));
		if ($this->mundane->find()) {
			$password = substr(md5(microtime()), 2, 11);
			$this->mundane->password_expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 1);
			/* Only salt on password change or first password
			$this->mundane->password_salt = md5(rand().microtime());
			*/
			if (trimlen($this->mundane->password_salt) == 0) {
				mt_srand(microtime() . microtime());
				$this->mundane->password_salt = md5(mt_rand() . microtime());
			}
			Authorization::SaltPassword($this->mundane->password_salt, strtoupper(trim($request['UserName'])) . trim($password), $this->mundane->password_expires, 1);

			$this->mundane->save();
			$m = new Mail('smtp', AMAZON_SES_HOST, AMAZON_SES_USERNAME, AMAZON_SES_PASSWORD, 587);
			$m->setTo($this->mundane->email);
			$m->setFrom('ork3@amtgard.com');
			$m->setSubject('Reset ORK Password (Expires in 24 hours)');
			$m->setHtml('<h2>ORK Password Reset</h2>We have generated a temporary password that will <b>expire in 24 hours</b>. You will need to log in immediately and reset your password. <p>You can log in with the following link:<p><a rel="nofollow" href="' . UIR . 'Login/login&username=' . $request['UserName'] . '&password=' . $password . '">Click here to be logged in temporarily</a> OR login with this temporary password: ' . $password . '<p>Regards,<p>-ORK Team');
			$m->setSender('ork3@amtgard.com');
			$m->send();
			$response = Success();
			$this->log->Write('ResetPassword Email', 0, LOG_EDIT, array($response));
		} else {
			$response = InvalidParameter(null, "Login and username could not be found.");
		}
		return $response;
	}

	public function GetAuthorizations($request)
	{
		if (is_array($request)) {
			$r = $this->GetAuthorizations_h($request['MundaneId']);
			return array(
				'Status' => (($r === false) ? InvalidParameter(null, $request['MundaneId']) : Success()),
				'Authorizations' => $r
			);
		} else {
			return $this->GetAuthorizations_h($request);
		}
	}

	public function Authorize($request)
	{
		if (isset($_SESSION['is_authorized_mundane_id']))
			unset($_SESSION['is_authorized_mundane_id']);

		$response = array();
		if ($this->IsLocalCall() || true) {
			$response = $this->Authorize_h($request);
			$response['Status']['Detail'] = "Local Authorization Attempt: " . $response['Status']['Detail']; // . print_r($this->trace, true);
		} else {
			$response = $this->Authorize_app($request);
			$response['Status']['Detail'] = "Remote Authorization Attempt: " . $response['Status']['Detail']; // . print_r($this->trace, true);
		}
		return $response;
	}

	public function XSiteAuthorize($request)
	{
		if ($this->IsAuthorized($request)) {
			return $this->Authorize_h($request);
		} else {
			return array('Status' => NoAuthorization());
		}
	}

	public function AddAuthorization($request)
	{
		logtrace('AddAuthorization', $request);
		if (($requester_id = $this->IsAuthorized($request['Token'])) > 0) {
			$response = $this->add_authorization($requester_id, $request);
		} else {
			$response = BadToken();
		}
		return $response;
	}

	public function RemoveAuthorization($request)
	{
		logtrace('RemoveAuthorization', $request);
		$response = array();
		if (is_null($request['AuthorizationId']) || !$request['AuthorizationId'] > 0) {
			$response = ProcessingError("AuthorizationId is not set.");
			return $response;
		} else if (($requester_id = $this->IsAuthorized($request['Token'])) > 0) {
			$this->auth->clear();
			$this->auth->authorization_id = $request['AuthorizationId'];
			if ($this->auth->find()) {
				list($type, $id) = $this->DetermineAuthType();
				if ($this->HasAuthority($requester_id, $type, $id, AUTH_CREATE)) {
					// Any call to an Authorization may have side-effects in the Auth table
					$response = $this->remove_auth_h($request);
				} else if ($type == AUTH_UNIT) {
					$mundane = Ork3::$Lib->player->player_info($requester_id);

					if ($this->HasAuthority($requester_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) ||
						$this->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
						logtrace("RemoveAuthorization(): KPM Unit Bypass: ", $requester_id);
						$response = $this->remove_auth_h($request);
					}
				} else {
					$response = NoAuthorization();
				}
			} else {
				$response = ProcessingError();
			}
		} else {
			logtrace("RemoveAuthorization(): BadToken: ", $requester_id);
			$response = BadToken();
		}
		return $response;
	}

	/*
	 * Utility functions second
	 */

	public function ApplicationIsAuthorized($request)
	{
		$this->app->clear();
		$this->app->appid = $request['AppId'];
		if ($this->app->find()) {
			if (Authorization::KeyExists($this->app->app_salt, trim($request['AppId']) . trim($request['AppSecret']))) {
				return $this->app->application_id;
			}
		}
		return false;
	}

	public function Authorize_app($request)
	{
		$response = array();
		if (trimlen($request['Token']) == 0) {
			if (($app_id = $this->ApplicationIsAuthorized($request)) > 0) {
				$this->app_auth->clear();
				$this->app_auth->application_id = $app_id;
				$this->app_auth->appauthkey = $request['ApplicationAuthorizationKey'];
				$this->app_auth->approved = 'approved';
				if ($this->app_auth->find()) {
					$this->mundane->clear();
					$this->mundane->mundane_id = $this->app_auth->mundane_id;
					if ($this->mundane->find()) {
						if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
							$response['Status'] = NoAuthorization('Your access to the ORK has been restricted.');
						} else {
							$this->app_auth->token = md5(openssl_random_pseudo_bytes(16) . microtime());
							$this->app_auth->token_expires = date('c', time() + LOGIN_TIMEOUT);
							$this->app_auth->save();
							$response['Status'] = Success();
							$response['Token'] = $this->app_auth->token;
							$response['UserId'] = $this->app_auth->mundane_id;
							$response['Timeout'] = $this->app_auth->token_expires;
						}
					} else {
						$response['Status'] = ProcessingError();
					}
				} else {
					$response['Status'] = InvalidParameter();
				}
			} else {
				$response['Status'] = NoAuthorization();
			}
		} else {
			// find the token & refresh it
			$this->app_auth->clear();
			$this->app_auth->token = $request['Token'];
			if ($this->app_auth->find()) {
				$this->mundane->clear();
				$this->mundane->mundane_id = $this->app_auth->mundane_id;
				if ($this->mundane->find()) {
					if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
						$response['Status'] = NoAuthorization('Your access to the ORK has been restricted.');
					} else if (strtotime($this->mundane->token_expires) > time()) {
						$this->app_auth->token = md5(openssl_random_pseudo_bytes(16) . microtime());
						$this->app_auth->token_expires = date('c', time() + LOGIN_TIMEOUT);
						$this->app_auth->save();
						$response['Status'] = Success();
						$response['Token'] = $this->app_auth->token;
						$response['UserId'] = $this->app_auth->mundane_id;
						$response['Timeout'] = $this->app_auth->token_expires;
					} else {
						$response['Status'] = InvalidParameter(null, "Token has expired: " . strtotime($this->mundane->token_expires) . ' <= ' . time());
						$response['Status']['Detail'] = $request['Token'];
					}
				} else {
					$response['Status'] = ProcessingError();
				}
			} else {
				$response['Status'] = InvalidParameter(null, "Token could not be found.");
				$response['Status']['Detail'] = $request['Token'];
			}
		}
		return $response;
	}

	public function Authorize_h($request)
	{
		$response = array();
		$this->mundane->clear();

		if ($request['Token'] == null) {
			$this->mundane->like('username', trim($request['UserName']));
			if ($this->mundane->find()) {
				$mundane_id = $this->mundane->mundane_id;
				// Harmonizes old password style with new password style
				if (Authorization::KeyExists($this->mundane->password_salt, trim($request['Password']))) {
					Authorization::SaltPassword($this->mundane->password_salt, strtoupper(trim($request['UserName'])) . trim($request['Password']), $this->mundane->password_expires);
				}
				if (Authorization::KeyExists($this->mundane->password_salt, strtoupper(trim($request['UserName'])) . trim($request['Password']))) {
					if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
						$response['Status'] = NoAuthorization('Your access to the ORK has been restricted.');
					} else {
						$_sessionToken = $this->CreateSession($this->mundane->mundane_id);
						if ($_sessionToken === '') {
							$response['Status'] = ProcessingError("Could not establish a session. Please try again.");
						} else {
							$this->persistVestigialToken($_sessionToken);
							$response['Status'] = Success();
							$response['Token'] = $this->mundane->token;
							$response['UserId'] = $mundane_id;
							$response['Timeout'] = $this->mundane->token_expires;
							$response['PasswordExpires'] = $this->mundane->password_expires;
						}
					}
				} else {
					if (defined('UIR')) {
						$response['Status'] = InvalidParameter(null, "Login could not be found. <a href='" . UIR . "Login/forgotpassword'>Reset forgotten or expired password</a>");
					} else {
						$response['Status'] = InvalidParameter(null, "Login could not be found.");
					}
				}
			} else {
				if (defined('UIR')) {
					$response['Status'] = InvalidParameter(null, "Login and username could not be found. <a href='" . UIR . "Login/forgotpassword'>Reset forgotten or expired password</a>");
				} else {
					$response['Status'] = InvalidParameter(null, "Login and username could not be found.");
				}
			}
		} else {
			$this->mundane->clear();
			$this->mundane->token = $request['Token'];
			if ($this->mundane->find()) {
				$mundane_id = $this->mundane->mundane_id;
				if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
					$response['Status'] = NoAuthorization('Your access to the ORK has been restricted.');
				} else if (strtotime($this->mundane->token_expires) > time()) {
					$_rotated = $this->RotateSession($request['Token']);
					if ($_rotated === '') {
						$_rotated = $this->CreateSession($this->mundane->mundane_id);
					}
					if ($_rotated === '') {
						$response['Status'] = ProcessingError("Could not establish a session. Please try again.");
					} else {
						$this->persistVestigialToken($_rotated);
						$response['Status'] = Success();
						$response['Token'] = $this->mundane->token;
						$response['UserId'] = $mundane_id;
						$response['Timeout'] = $this->mundane->token_expires;
					}
				} else {
					$response['Status'] = InvalidParameter(null, "Token has expired: " . strtotime($this->mundane->token_expires) . ' <= ' . time());
					$response['Status']['Detail'] = $request['Token'];
				}
			} else {
				$response['Status'] = InvalidParameter(null, "Token could not be found.");
				$response['Status']['Detail'] = $request['Token'];
			}
		}
		return $response;
	}

	public function AuthorizeIdp()
	{
		$request = [
			'IdpUserId' => $_SESSION['Session_Vars']['IdpUserId'],
			'Email' => $_SESSION['Session_Vars']['Email'],
			'MundaneId' => $_SESSION['Session_Vars']['MundaneId'],
			'AccessToken' => $_SESSION['Session_Vars']['AccessToken'],
			'RefreshToken' => $_SESSION['Session_Vars']['RefreshToken'],
			'ExpiresAt' => $_SESSION['Session_Vars']['ExpiresAt'],
		];

		error_log("AuthorizeIdp: Request: " . print_r($request, true));
		$this->idp_auth->clear();
		$this->idp_auth->idp_user_id = $request['IdpUserId'];

		if ($this->idp_auth->find()) {
			return $this->idpAuthorize($request);
		}

		return $this->linkIdpAuthorization($request);
	}

	private function idpAuthorize($request)
	{
		error_log("AuthorizeIdp: Link found for IDP User ID: " . $request['IdpUserId']);
		// User is already linked
		$this->mundane->clear();
		$this->mundane->mundane_id = $this->idp_auth->mundane_id;
		if (!$this->mundane->find()) {
			error_log("AuthorizeIdp: Linked mundane user not found for ID: " . $this->idp_auth->mundane_id);
			return ['Status' => ProcessingError("Linked user not found.")];
		}

		if ($this->mundane->penalty_box == 1 || $this->mundane->suspended == 1) {
			return ['Status' => NoAuthorization('Your access to the ORK has been restricted.')];
		}

		$_idpToken = $this->CreateSession($this->mundane->mundane_id);
		if ($_idpToken === '') {
			return ['Status' => ProcessingError("Could not establish a session. Please try again.")];
		}
		$this->persistVestigialToken($_idpToken);
		error_log("AuthorizeIdp: Updated mundane token.");

		// Update tokens
		$this->idp_auth->access_token = $request['AccessToken'];
		$this->idp_auth->refresh_token = $request['RefreshToken'];
		$this->idp_auth->expires_at = date('Y-m-d H:i:s', $request['ExpiresAt']);
		$this->idp_auth->save();
		error_log("AuthorizeIdp: Updated IDP tokens.");

		return [
			'Status' => Success(),
			'Token' => $this->mundane->token,
			'UserId' => $this->mundane->mundane_id,
			'UserName' => $this->mundane->username,
			'Timeout' => $this->mundane->token_expires
		];
	}

	private function linkIdpAuthorization($request)
	{
		error_log("AuthorizeIdp: No link found. Checking for MundaneId or Email.");

		$this->mundane->clear();
		$found_mundane = false;

		// Try to find by MundaneId first if provided
		if (isset($request['MundaneId']) && $request['MundaneId'] > 0) {
			error_log("AuthorizeIdp: Trying to link by MundaneId: " . $request['MundaneId']);
			$this->mundane->mundane_id = $request['MundaneId'];
			if ($this->mundane->find()) {
				$found_mundane = true;
				error_log("AuthorizeIdp: User found by MundaneId.");
			}
		}

		if (!$found_mundane) {
			error_log("AuthorizeIdp: User not found by MundaneId or Email.");
			return ['Status' => NoAuthorization("User not found and could not be automatically linked.")];
		}

		return $this->createIdpLink($request);
	}

	private function createIdpLink($request)
	{
		error_log("AuthorizeIdp: Creating link for MundaneId: " . $this->mundane->mundane_id);
		// Link found
		$this->idp_auth->clear();
		$this->idp_auth->mundane_id = $this->mundane->mundane_id;
		$this->idp_auth->idp_user_id = $request['IdpUserId'];
		$this->idp_auth->access_token = $request['AccessToken'];
		$this->idp_auth->refresh_token = $request['RefreshToken'];
		$this->idp_auth->expires_at = date('Y-m-d H:i:s', $request['ExpiresAt']);
		$this->idp_auth->created_at = date('Y-m-d H:i:s');
		$this->idp_auth->save();
		error_log("AuthorizeIdp: IDP link created.");

		$_idpToken = $this->CreateSession($this->mundane->mundane_id);
		if ($_idpToken === '') {
			return ['Status' => ProcessingError("Could not establish a session. Please try again.")];
		}
		$this->persistVestigialToken($_idpToken);
		error_log("AuthorizeIdp: Mundane token updated.");

		return [
			'Status' => Success(),
			'Token' => $this->mundane->token,
			'UserId' => $this->mundane->mundane_id,
			'UserName' => $this->mundane->username,
			'Timeout' => $this->mundane->token_expires
		];
	}

	public static function KeyExists($salt, $password)
	{

		global $DB;
		$DB->query("delete from " . DB_PREFIX . "credential where expiration <= now()");
		$credential = new yapo($DB, DB_PREFIX . 'credential');
		$credential->clear();
		$key = Authorization::CryptStrip512(trim($salt) . mysql_real_escape_string(trim($password)), $salt);
		$credential->key = $key;
		//echo "<!-- $key -->";
		if ($credential->find()) {
			return true;
		}
		return false;
	}

	public static function SaltPassword($salt, $password, $timestamp, $reset = 0)
	{
		global $DB;

		if ($reset) {
			$resetrequest = 1;
		} else {
			$resetrequest = 0;
		}

		if (!is_numeric($timestamp)) {
			$timestamp = strtotime($timestamp);
		}
		if ($timestamp + 20 < time() + 60 * 60 * 24 * 365 || $timestamp - 20 > time() + 60 * 60 * 24 * 365) {
			$timestamp = time() + rand(-20 * 60 * 60 * 24, 20 * 60 * 60 * 24) + 60 * 60 * 24 * 365 * 2;
		}

		if ($resetrequest == 1)
			$sql = "insert into " . DB_PREFIX . "credential (`key`, `expiration`,`resetrequest`) values ('" . Authorization::CryptStrip512(trim($salt) . mysql_real_escape_string(trim($password)), $salt) . "', '" . (date("Y-m-d H:i:s", time() + 24 * 60 * 60)) . "', $resetrequest)";
		else
			$sql = "insert into " . DB_PREFIX . "credential (`key`, `expiration`,`resetrequest`) values ('" . Authorization::CryptStrip512(trim($salt) . mysql_real_escape_string(trim($password)), $salt) . "', '" . (date("Y-m-d H:i:s", $timestamp)) . "', $resetrequest)";
		$DB->query($sql);

		//$DB->query("insert into " . DB_PREFIX . "credential (`key`, `expiration`) values ('" .Authorization::CryptStrip512(rand().microtime(), $salt). "', '" .(date("Y-m-d H:i:s", $timestamp + rand(-60 * 60 * 24 * 182.5, 0))). "')");
		/*
		for ($i = 0; $i < 3; $i++) {
			$DB->query("insert into " . DB_PREFIX . "credential (`key`, `expiration`) values ('" .Authorization::CryptStrip512(rand().microtime(), $salt). "', '" .(date("Y-m-d H:i:s", $timestamp + rand(-60 * 60 * 24 * 182.5, 60 * 60 * 24 * 182.5))). "')");
		}
		*/
	}

	public static function CryptStrip512($string, $salt)
	{
		$salt = '$6$rounds=5000$' . $salt . '$';
		$c = substr(crypt($salt . $string, $salt), strlen($salt));
		return $c;
	}

	public function remove_auth_h($request)
	{
		logtrace('remove_auth_h', $request);
		$_auth_id = (int)$request['AuthorizationId'];
		if (!valid_id($_auth_id)) {
			return ProcessingError();
		}

		// Fresh raw SELECT for the audit snapshot. Reading via the shared
		// $this->auth yapo object here produced rows with mundane_id=0 in
		// prod (probably state pollution from RemoveAuthorization's own
		// find() + the HasAuthority chain that runs before this point —
		// next()/clear()/find() over the same yapo can leave the active
		// record set in an unexpected position). Going straight to the DB
		// for the audit data sidesteps all of that.
		global $DB;
		$DB->Clear();
		$_priorRs = $DB->DataSet("SELECT mundane_id, park_id, kingdom_id, event_id, unit_id, role FROM " . DB_PREFIX . "authorization WHERE authorization_id = " . $_auth_id . " LIMIT 1");
		if (!$_priorRs || !$_priorRs->Next()) {
			return ProcessingError();
		}
		$_prior = [
			'authorization_id' => $_auth_id,
			'mundane_id'       => (int)$_priorRs->mundane_id,
			'park_id'          => (int)$_priorRs->park_id,
			'kingdom_id'       => (int)$_priorRs->kingdom_id,
			'event_id'         => (int)$_priorRs->event_id,
			'unit_id'          => (int)$_priorRs->unit_id,
			'role'             => $_priorRs->role,
		];
		$DB->Clear();

		$this->auth->clear();
		$this->auth->authorization_id = $_auth_id;
		if ($this->auth->find()) {
			$_audit_req = $request;
			unset($_audit_req['Token']);
			$this->log->Write('Authorization', $requester_id, LOG_REMOVE, $request);
			// Anchor the audit to the affected player so the entry surfaces on
			// the grantee's audit history. Authority changes are security-relevant
			// — they should be discoverable from the player's profile audit.
			Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::RemoveAuthorization', $_audit_req, 'Player', $_prior['mundane_id'], $_prior, null);
			$this->auth->delete();
			$response = Success();
		} else {
			$response = ProcessingError();
		}
		return $response;
	}

	public function add_authorization($requester_id, $request)
	{
		logtrace('add_authorization', $request);
		$response = array();
		switch ($request['Role']) {
			case AUTH_CREATE:
				break;
			case AUTH_EDIT:
				break;
			case AUTH_ADMIN:
				break;
			default:
				$response = InvalidParameter(null, 'Unrecognized Role: $request[Role].');
				return $response;
		}
		if ($this->HasAuthority($requester_id, $request['Type'], $request['Id'], AUTH_CREATE)) {
			$this->log->Write('Authorization', $requester_id, LOG_ADD, $request);
			$response = $this->add_auth_h($request);
			return $response;
		} else if (AUTH_UNIT == $request['Type']) {
			$mundane = Ork3::$Lib->player->player_info($requester_id);

			if ($this->HasAuthority($requester_id, AUTH_KINGDOM, $mundane['KingdomId'], AUTH_EDIT) ||
				$this->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
				$this->log->Write('Authorization:KPM Unit Bypass', $requester_id, LOG_ADD, $request);
				$response = $this->add_auth_h($request);
				return $response;
			}
		} else {
			$response = NoAuthorization();
		}
		return $response;
	}

	public function add_auth_h($request)
	{
		logtrace('add_auth_h', $request);
		$this->auth->clear();
		$this->auth->mundane_id  = $request['MundaneId'];
		$this->auth->park_id     = 0;
		$this->auth->kingdom_id  = 0;
		$this->auth->event_id    = 0;
		$this->auth->unit_id     = 0;
		switch ($request['Type']) {
			case AUTH_PARK:
				$this->auth->park_id = $request['Id'];
				break;
			case AUTH_KINGDOM:
				$this->auth->kingdom_id = $request['Id'];
				break;
			case AUTH_EVENT:
				$this->auth->event_id = $request['Id'];
				break;
			case AUTH_UNIT:
				$this->auth->unit_id = $request['Id'];
				break;
			case AUTH_ADMIN:
				break;
			default:
				$response = InvalidParameter(null, "Unrecognized Type.");
				return $response;
		}
		$this->auth->role = $request['Role'];
		$this->auth->modified = date('Y-m-d H:i:s');
		$this->auth->save();
		return Success($this->auth->authorization_id);
	}

	public function GetAuthorizations_h($mundane_id)
	{
		if (strlen($mundane_id) == 0)
			false;
		$this->auth->clear();
		$this->auth->mundane_id = $mundane_id;
		$auths = array();
		if ($this->auth->find()) {
			do {
				list($type, $id) = $this->DetermineAuthType();
				$auths[] = array(
					'AuthorizationId' => $this->auth->authorization_id,
					'Type' => $type,
					'Id' => $id,
					'Role' => $this->auth->role,
					'Detail' => $details
				);
			} while ($this->auth->next());
		}
		return $auths;
	}

	private function DetermineAuthType()
	{
		$type = 'None';
		$id = 0;
		if ($this->auth->park_id > 0) {
			$type = AUTH_PARK;
			$id = $this->auth->park_id;
		}
		;
		if ($this->auth->kingdom_id > 0) {
			$type = AUTH_KINGDOM;
			$id = $this->auth->kingdom_id;
		}
		;
		if ($this->auth->event_id > 0) {
			$type = AUTH_EVENT;
			$id = $this->auth->event_id;
		}
		;
		if ($this->auth->unit_id > 0) {
			$type = AUTH_UNIT;
			$id = $this->auth->unit_id;
		}
		;
		if ($this->auth->role == AUTH_ADMIN) {
			$type = AUTH_ADMIN;
			$id = $this->auth->authorization_id;
		}
		return array($type, $id);
	}

	public function HasAuthority($mundane_id, $type, $id, $role, $visited = array())
	{
		logtrace("HasAuthority", array($mundane_id, $type, $id, $role));

		if (valid_id($mundane_id) && (valid_id($id) || $type == AUTH_ADMIN)) {
			;
		} else if ($type == AUTH_ADMIN && valid_id($mundane_id)) {
			;
		} else {
			return false;
			;
		}
		// Is Ork Admin? Only TRUE global-admin grants — those with no scope
		// attached (park_id / kingdom_id / unit_id / event_id all zero) —
		// may short-circuit here. Scoped admin grants must fall through to
		// the scope-specific check below; otherwise a park admin row would
		// silently confer system-wide authority on any HasAuthority call,
		// which is the bug that let multiple compromised park-officer
		// accounts edit players across other kingdoms.
		$this->auth->clear();
		$this->auth->mundane_id = $mundane_id;
		$this->auth->role = AUTH_ADMIN;
		if ($this->auth->find()) {
			do {
				if ($this->auth->park_id    == 0
				 && $this->auth->kingdom_id == 0
				 && $this->auth->unit_id    == 0
				 && $this->auth->event_id   == 0) {
					return true;
				}
			} while ($this->auth->next());
		}
		// Playing shenanigans
		if (0 == $id)
			return false;

		// Check for bans
		$this->mundane->clear();
		$this->mundane->mundane_id = $mundane_id;
		if (!$this->mundane->find()) {
			return false;
		} else if ($this->mundane->penalty_box == 1) {
			return false;
		}

		$this->auth->clear();
		$this->auth->mundane_id = $mundane_id;
		// Scope-specific lookup. AUTH_ADMIN is intentionally NOT a case here:
		// system-wide admin is handled by the all-zero-scope short-circuit
		// above, and a scoped AUTH_ADMIN request is not meaningful (callers
		// wanting kingdom-level authority should use AUTH_KINGDOM).
		switch ($type) {
			case AUTH_PARK:
				$this->auth->park_id = $id;
				break;
			case AUTH_KINGDOM:
				$this->auth->kingdom_id = $id;
				break;
			case AUTH_EVENT:
				$this->auth->event_id = $id;
				break;
			case AUTH_UNIT:
				$this->auth->unit_id = $id;
				break;
			default:
				return false;
		}
		if ($this->auth->find() && $id != 0) {
			$sufficient = false;
			do {
				switch ($this->auth->role) {
					case AUTH_EDIT:
						$sufficient |= (AUTH_EDIT == $role);
						break;
					case AUTH_CREATE:
						return true;
					case AUTH_ADMIN:
						return true;
				}
			} while ($this->auth->next());
			// Something matched, fly away my pretty!
			if ($sufficient)
				return true;
		}
		if ($type == AUTH_ADMIN)
			return false;
		// Upper-level authority check, we have to find the parents of
		// of the subject, and check their auths
		// !$sufficient is redundant, but I don't trust the next guy to hold the invariant
		if (!$sufficient) {
			switch ($type) {
				case AUTH_PARK:
					$park = new yapo($this->db, DB_PREFIX . 'park');
					$park->clear();
					$park->park_id = $id;
					if ($park->find()) {
						$id = $park->kingdom_id;
						if ($this->HasAuthority($mundane_id, AUTH_KINGDOM, $id, $role))
							return true;
					}
					break;
				case AUTH_EVENT:
					$event = new yapo($this->db, DB_PREFIX . 'event');
					$event->clear();
					$event->event_id = $id;
					if ($event->find()) {
						if ($this->HasAuthority($mundane_id, AUTH_KINGDOM, $event->kingdom_id, $role) || $this->HasAuthority($mundane_id, AUTH_PARK, $event->park_id, $role) || $event->mundane_id == $mundane_id)
							return true;
					}
					break;
				case AUTH_KINGDOM:
					// Principalities are sub-groups of their parent kingdom: parent-kingdom
					// officers hold the same authority over a principality (and its parks)
					// as over the kingdom itself. Walk up the parent_kingdom_id chain.
					// Guard against a corrupt/cyclic parent_kingdom_id (e.g. A->B->A or a
					// self-parent) with a visited-set of kingdom ids plus a hard depth cap,
					// so the recursion can never loop forever.
					$kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
					$kingdom->clear();
					$kingdom->kingdom_id = $id;
					$visited[] = (int) $id;
					if ($kingdom->find() && valid_id($kingdom->parent_kingdom_id)
						&& !in_array((int) $kingdom->parent_kingdom_id, $visited, true)
						&& count($visited) < 10
						&& $this->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom->parent_kingdom_id, $role, $visited))
						return true;
					break;
			}
		}
		return $sufficient;
	}

	public function IsAuthorized_h($token)
	{
		logtrace("IsAuthorized_h($token)", null);
		if (strlen($token) != self::TOKEN_LENGTH)
			return 0;
		$mundane_id = $this->ValidateSessionByToken($token);
		if ($mundane_id === 0) {
			if (isset($_SESSION['is_authorized_mundane_id']))
				unset($_SESSION['is_authorized_mundane_id']);
			return 0;
		}
		$this->mundane->clear();
		$this->mundane->mundane_id = $mundane_id;
		if (!$this->mundane->find() || $this->mundane->penalty_box == 1) {
			return 0;
		}
		logtrace("IsAuthorized(): authorized", null);
		$_SESSION['is_authorized_mundane_id'] = $mundane_id;
		return $mundane_id;
	}

	public function IsAuthorized_app($token)
	{
		logtrace("IsAuthorized_app($token)", null);
		if (strlen($token) == 32)
			return 0;
		$this->app_auth->clear();
		$this->app_auth->token = $token;
		if ($this->app_auth->find()) {
			$this->mundane->clear();
			$this->mundane->mundane_id = $this->app_auth->mundane_id;
			if ($this->mundane->find()) {
				if ($this->mundane->penalty_box == 1)
					return 0;
				logtrace("IsAuthorized(): authorized", null);
				return $this->app_auth->mundane_id;
			} else {
				return 0;
			}
		}
		return 0;
	}

	public function IsLocalCall()
	{
		$this->trace = debug_backtrace();
		//logtrace('IsLocalCall()', $this->trace);
		foreach ($this->trace as $k => $trace) {
			if (strpos($trace['file'], 'class.APIModel.php') && $trace['function'] == 'call_user_func_array') {
				return true;
			}
		}
		return false;
	}

	public function IsAuthorized($token) {
		logtrace("IsAuthorized($token)", null);
		if ($this->IsLocalCall() || true) {
			$response = $this->IsAuthorized_h($token);
		} else {
			$response = $this->IsAuthorized_app($token);
		}
		logtrace("Authorization():", $response);
		return $response;
	}

	/*
	 * Multi-device session store (ork_session). Authoritative for session
	 * validity; ork_mundane.token is retained only as a vestigial pointer.
	 */

	public function CreateSession($mundane_id)
	{
		global $DB;
		$mundane_id = (int)$mundane_id;
		$now = date('Y-m-d H:i:s');
		$exp = date('Y-m-d H:i:s', time() + LOGIN_TIMEOUT);

		// Insert the session and VERIFY it landed. YapoMysql runs under
		// ERRMODE_WARNING and swallows most INSERT errors, so an unverified
		// insert could return a token no validation will ever match — a
		// confusing self-evicting login loop. Read the row back; retry once
		// with a fresh token on failure (also covers the astronomically rare
		// UNIQUE(token) collision).
		for ($attempt = 0; $attempt < 2; $attempt++) {
			$token = md5(openssl_random_pseudo_bytes(16) . microtime());
			$DB->Clear();
			$DB->token = $token;
			$DB->Execute("INSERT INTO " . DB_PREFIX . "session (mundane_id, token, created, last_seen, expires) "
				. "VALUES (" . $mundane_id . ", :token, '" . $now . "', '" . $now . "', '" . $exp . "')");
			$DB->Clear();
			$DB->token = $token;
			$rs = $DB->DataSet("SELECT session_id FROM " . DB_PREFIX . "session WHERE token = :token LIMIT 1");
			if ($rs && $rs->Next()) {
				$this->evictLruSessions($mundane_id);
				return $token;
			}
		}
		logtrace("CreateSession(): FAILED to persist ork_session after retry", array('mundane_id' => $mundane_id));
		error_log("CreateSession(): FAILED to persist ork_session for mundane_id=" . $mundane_id);
		return '';
	}

	private function evictLruSessions($mundane_id)
	{
		global $DB;
		$mundane_id = (int)$mundane_id;
		$keep = (int)self::MAX_SESSIONS_PER_USER;
		// Delete this user's sessions that are NOT among the $keep most-recent
		// by last_seen. Derived-table wrapper is required: MariaDB forbids a
		// direct subselect on the table being deleted from.
		$DB->Clear();
		$DB->Execute(
			"DELETE FROM " . DB_PREFIX . "session "
			. "WHERE mundane_id = " . $mundane_id . " AND session_id NOT IN ("
			. "SELECT session_id FROM ("
			. "SELECT session_id FROM " . DB_PREFIX . "session "
			. "WHERE mundane_id = " . $mundane_id . " "
			. "ORDER BY last_seen DESC, session_id DESC LIMIT " . $keep
			. ") keep)"
		);
		logtrace("evictLruSessions(): enforced session cap", array('mundane_id' => $mundane_id, 'keep' => $keep));
	}

	// NOTE: not a pure read — on a session older than 60s this also slides
	// last_seen/expires forward (see the throttled UPDATE below).
	public function ValidateSessionByToken($token)
	{
		global $DB;
		if (strlen($token) != self::TOKEN_LENGTH) {
			return 0;
		}
		$DB->Clear();
		$DB->token = $token;
		$rs = $DB->DataSet("SELECT session_id, mundane_id, last_seen FROM " . DB_PREFIX . "session "
			. "WHERE token = :token AND expires > NOW() LIMIT 1");
		if (!$rs || !$rs->Next()) {
			return 0;
		}
		$session_id = (int)$rs->session_id;
		$mundane_id = (int)$rs->mundane_id;
		$last_seen  = strtotime($rs->last_seen);

		// Slide expiry, throttled to at most once per 60s per session so a
		// page that fires many API calls doesn't cause a write storm.
		if ($last_seen < time() - 60) {
			$now = date('Y-m-d H:i:s');
			$exp = date('Y-m-d H:i:s', time() + LOGIN_TIMEOUT);
			$DB->Clear();
			$DB->Execute("UPDATE " . DB_PREFIX . "session SET last_seen = '" . $now . "', expires = '" . $exp . "' "
				. "WHERE session_id = " . $session_id);
		}
		return $mundane_id;
	}

	public function RotateSession($old_token)
	{
		global $DB;
		if (strlen($old_token) != self::TOKEN_LENGTH) {
			return '';
		}
		$DB->Clear();
		$DB->token = $old_token;
		$rs = $DB->DataSet("SELECT session_id FROM " . DB_PREFIX . "session "
			. "WHERE token = :token AND expires > NOW() LIMIT 1");
		if (!$rs || !$rs->Next()) {
			return '';
		}
		$session_id = (int)$rs->session_id;
		$new_token = md5(openssl_random_pseudo_bytes(16) . microtime());
		$now = date('Y-m-d H:i:s');
		$exp = date('Y-m-d H:i:s', time() + LOGIN_TIMEOUT);
		$DB->Clear();
		$DB->new_token = $new_token;
		$DB->Execute("UPDATE " . DB_PREFIX . "session SET token = :new_token, last_seen = '" . $now . "', expires = '" . $exp . "' "
			. "WHERE session_id = " . $session_id);
		return $new_token;
	}

	public function DestroySession($request)
	{
		global $DB;
		$token = is_array($request) ? ($request['Token'] ?? '') : $request;
		if (strlen($token) != self::TOKEN_LENGTH) {
			return;
		}
		$DB->Clear();
		$DB->token = $token;
		$DB->Execute("DELETE FROM " . DB_PREFIX . "session WHERE token = :token");
		// Also clear the vestigial ork_mundane.token pointer if it matches this
		// token, so the legacy SOAP re-auth path (Authorize_h else-branch, which
		// finds the user by ork_mundane.token) can't revive a logged-out session.
		$DB->Clear();
		$DB->token = $token;
		$DB->Execute("UPDATE " . DB_PREFIX . "mundane SET token = '' WHERE token = :token");
	}

	// Writes the vestigial ork_mundane.token pointer (ork_session is authoritative).
	private function persistVestigialToken($token)
	{
		$this->mundane->token = $token;
		$this->mundane->token_expires = date('Y-m-d H:i:s', time() + LOGIN_TIMEOUT);
		$this->mundane->save();
	}
}


?>