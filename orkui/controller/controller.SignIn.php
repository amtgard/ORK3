<?php

class Controller_SignIn extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
		$this->data['page_title'] = 'Sign In';
	}

	public function index($p = null) {
		$link_token = preg_replace('/[^a-f0-9]/', '', (string)($p ?? ''));

		// Require login — redirect back here after
		if (!isset($this->session->user_id) || !(int)$this->session->user_id) {
			$this->session->location = 'SignIn/index/' . $link_token;
			header('Location: ' . UIR . 'Login/login');
			exit;
		}

		$this->load_model('Attendance');

		// Validate the link
		$link_result = $this->Attendance->get_attendance_link_info($link_token);
		if ($link_result['Status'] != 0) {
			$this->data['error']      = $link_result['Detail'] ?? 'This sign-in link is invalid or has expired.';
			$this->data['link_token'] = $link_token;
			$this->template = 'SignIn_index.tpl';
			return;
		}

		$link = $link_result['Detail'];

		// Resolve scope name
		$scope_name = 'your group';
		if (valid_id($link['EventId'] ?? 0)) {
			global $DB;
			$DB->Clear();
			$row = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int)$link['EventId'] . ' LIMIT 1');
			if ($row && $row->Next()) $scope_name = $row->name ?: $scope_name;
		} elseif (valid_id($link['ParkId'])) {
			$this->load_model('Park');
			$scope_name = $this->Park->get_park_name($link['ParkId']) ?: $scope_name;
		} elseif (valid_id($link['KingdomId'])) {
			$this->load_model('Kingdom');
			$scope_name = $this->Kingdom->get_kingdom_name($link['KingdomId']) ?: $scope_name;
		}

		// Get available classes
		$classes_result = $this->Attendance->get_classes();
		$classes = array_filter($classes_result['Classes'] ?? [], function($c) {
			return (int)($c['Active'] ?? 1) === 1;
		});

		// Handle submission
		if ($_SERVER['REQUEST_METHOD'] === 'POST') {
			$class_id = (int)($_POST['ClassId'] ?? 0);
			$r = $this->Attendance->use_attendance_link(
				$this->session->token,
				$link_token,
				$class_id
			);
			if ($r['Status'] == 0) {
				header('Location: ' . UIR . 'Player/profile/' . (int)$this->session->user_id);
				exit;
			} else {
				$this->data['error'] = $r['Detail'] ?? $r['Error'] ?? 'Could not record attendance.';
			}
		}

		// Get player's last class.
		// YapoMysql::DataSet() does NOT pre-call Next() — must call it manually to advance to the first row.
		$last_class_id   = 0;
		$last_class_name = '';
		global $DB;
		$DB->Clear();
		$last_row = $DB->DataSet('SELECT class_id FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . (int)$this->session->user_id . ' ORDER BY date DESC, attendance_id DESC LIMIT 1');
		if ($last_row && $last_row->Next() && (int)$last_row->class_id > 0) {
			$last_class_id = (int)$last_row->class_id;
			foreach (array_values($classes) as $c) {
				if ((int)$c['ClassId'] === $last_class_id) { $last_class_name = $c['Name']; break; }
			}
		}

		$this->data['link']            = $link;
		$this->data['scope_name']      = $scope_name;
		$this->data['link_token']      = $link_token;
		$this->data['classes']         = array_values($classes);
		$this->data['last_class_id']   = $last_class_id;
		$this->data['last_class_name'] = $last_class_name;
		$this->template = 'SignIn_index.tpl';
	}
}
