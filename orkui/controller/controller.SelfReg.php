<?php

class Controller_SelfReg extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
		$this->data['page_title'] = 'Self Registration';
	}

	public function form($token = null) {
		$this->template = '../revised-frontend/SelfReg_form.tpl';
		$token = preg_replace('/[^a-f0-9]/', '', (string)($token ?? ''));

		$this->load_model('Player');

		// Validate the token
		$link_result = $this->Player->validate_selfreg_link($token);
		if ($link_result['Status'] != 0) {
			$this->data['error'] = $link_result['Detail'] ?? 'This registration link is invalid or has expired.';
			$this->data['token'] = $token;
			return;
		}

		$link = $link_result['Detail'];

		// A5: Compute TTL seconds for client-side countdown timer
		$ttl_seconds = max(0, strtotime($link['expires_at']) - time());

		// Get park name for display
		$this->load_model('Park');
		$park_name = $this->Park->get_park_name($link['park_id']) ?: 'your group';

		// Handle form submission
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$persona   = trim($_POST['Persona']   ?? '');
			$givenName = trim($_POST['GivenName'] ?? '');
			$surname   = trim($_POST['Surname']   ?? '');
			$email     = trim($_POST['Email']      ?? '');
			$userName  = trim($_POST['UserName']   ?? '');
			$password  = $_POST['Password']        ?? '';
			$confirm   = $_POST['ConfirmPassword'] ?? '';

			// Client-side validations repeated server-side
			if (!strlen($persona)) {
				$this->data['error'] = 'Persona is required.';
			} elseif (!strlen($email)) {
				$this->data['error'] = 'Email is required.';
			} elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
				$this->data['error'] = 'Please enter a valid email address.';
			} elseif (strlen($userName) < 4) {
				$this->data['error'] = 'Username must be at least 4 characters.';
			} elseif (!strlen($password)) {
				$this->data['error'] = 'Password is required.';
			} elseif ($password !== $confirm) {
				$this->data['error'] = 'Passwords do not match.';
			} else {
				$r = $this->Player->self_register([
					'SelfRegToken' => $token,
					'Persona'      => $persona,
					'GivenName'    => $givenName,
					'Surname'      => $surname,
					'Email'        => $email,
					'UserName'     => $userName,
					'Password'     => $password,
				]);

				if ($r['Status'] == 0) {
					$detail = $r['Detail'];
					// Log the user in
					$this->session->user_id   = $detail['mundane_id'];
					$this->session->user_name = $detail['username'];
					$this->session->token     = $detail['token'];
					$this->session->timeout   = date('Y:m:d H:i:s', time() + LOGIN_TIMEOUT);

					// A9: Set park/kingdom context in session
					$this->session->park_id      = $detail['park_id'];
					$this->session->park_name    = $detail['park_name'];
					$this->session->kingdom_id   = $detail['kingdom_id'];
					$this->session->kingdom_name = $detail['kingdom_name'];

					header('Location: ' . UIR . 'Player/profile/' . (int)$detail['mundane_id']);
					exit;
				} else {
					$this->data['error'] = $r['Detail'] ?? $r['Error'] ?? 'Registration failed.';
				}
			}

			// Preserve form values on error
			$this->data['form'] = [
				'Persona'   => $persona,
				'GivenName' => $givenName,
				'Surname'   => $surname,
				'Email'     => $email,
				'UserName'  => $userName,
			];
		}

		$this->data['token']       = $token;
		$this->data['park_name']   = $park_name;
		$this->data['link']        = $link;
		$this->data['ttl_seconds'] = $ttl_seconds;
	}
}
