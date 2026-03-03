<?php

class Controller_EventAjax extends Controller {

	public function create($p = null) {
		header('Content-Type: application/json');

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		$this->load_model('Event');
		$name       = trim($_POST['Name']       ?? '');
		$kingdom_id = (int)($_POST['KingdomId'] ?? 0);
		$park_id    = (int)($_POST['ParkId']    ?? 0);

		if (!strlen($name)) {
			echo json_encode(['status' => 1, 'error' => 'Event name is required.']);
			exit;
		}
		if (!valid_id($kingdom_id) && !valid_id($park_id)) {
			echo json_encode(['status' => 1, 'error' => 'A kingdom or park is required.']);
			exit;
		}

		$r = $this->Event->create_event(
			$this->session->token,
			$kingdom_id,
			$park_id,
			0,
			0,
			$name
		);

		if ($r['Status'] == 0) {
			echo json_encode(['status' => 0, 'eventId' => (int)($r['Detail'] ?? 0)]);
		} else {
			echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
		}
		exit;
	}
}
