<?php

class Controller_CalendarItemAjax extends Controller {

	private function requireLogin() {
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
	}

	private function sendResult($r) {
		if (isset($r['Status']) && $r['Status'] == 0) {
			echo json_encode(['status' => 0, 'id' => (int)($r['Detail'] ?? 0)]);
		} else {
			echo json_encode([
				'status' => $r['Status'] ?? 1,
				'error'  => ($r['Error'] ?? 'Error') . (isset($r['Detail']) ? ': ' . $r['Detail'] : ''),
			]);
		}
		exit;
	}

	public function create($p = null) {
		header('Content-Type: application/json');
		$this->requireLogin();
		$this->load_model('CalendarItem');

		$r = $this->CalendarItem->create_calendar_item(
			$this->session->token,
			(int)($_POST['KingdomId']   ?? 0),
			(int)($_POST['ParkId']      ?? 0),
			trim($_POST['Name']         ?? ''),
			(string)($_POST['Description'] ?? ''),
			!empty($_POST['AllDay']) ? 1 : 0,
			(string)($_POST['EventStart']  ?? ''),
			(string)($_POST['EventEnd']    ?? '')
		);
		$this->sendResult($r);
	}

	public function update($p = null) {
		header('Content-Type: application/json');
		$this->requireLogin();
		$this->load_model('CalendarItem');

		$id = (int)($_POST['CalendarItemId'] ?? 0);
		$r  = $this->CalendarItem->update_calendar_item(
			$this->session->token,
			$id,
			trim($_POST['Name']         ?? ''),
			(string)($_POST['Description'] ?? ''),
			!empty($_POST['AllDay']) ? 1 : 0,
			(string)($_POST['EventStart']  ?? ''),
			(string)($_POST['EventEnd']    ?? '')
		);
		$this->sendResult($r);
	}

	public function delete($p = null) {
		header('Content-Type: application/json');
		$this->requireLogin();
		$this->load_model('CalendarItem');

		$id = (int)($_POST['CalendarItemId'] ?? 0);
		$r  = $this->CalendarItem->delete_calendar_item($this->session->token, $id);
		$this->sendResult($r);
	}

	public function get($p = null) {
		header('Content-Type: application/json');
		$this->load_model('CalendarItem');
		$id = (int)preg_replace('/[^0-9]/', '', $p ?? '');
		if (!$id && isset($_GET['id'])) $id = (int)$_GET['id'];

		$r = $this->CalendarItem->get_calendar_item($id);
		if (!isset($r['Status']) || $r['Status']['Status'] != 0) {
			echo json_encode(['status' => 1, 'error' => 'Not found']);
			exit;
		}

		// Determine edit permission for the caller (same check the class uses).
		$uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
		$canEdit = false;
		if ($uid > 0) {
			if ((int)$r['ParkId'] > 0) {
				$canEdit = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$r['ParkId'], AUTH_CREATE);
			} elseif ((int)$r['KingdomId'] > 0) {
				$canEdit = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$r['KingdomId'], AUTH_CREATE);
			}
		}

		echo json_encode([
			'status'         => 0,
			'CalendarItemId' => (int)$r['CalendarItemId'],
			'KingdomId'      => (int)$r['KingdomId'],
			'ParkId'         => (int)$r['ParkId'],
			'Name'           => $r['Name'],
			'Description'    => $r['Description'],
			'AllDay'         => (int)$r['AllDay'],
			'EventStart'     => $r['EventStart'],
			'EventEnd'       => $r['EventEnd'],
			'CanEdit'        => $canEdit,
		]);
		exit;
	}
}
