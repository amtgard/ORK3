<?php

class Controller_AttendanceAjax extends AJAXController {

	public function __construct($call=null, $id=null) {
		parent::__construct($call, $id);
		$this->load_model('Attendance');
	}

	public function park($p) {
		header('Content-Type: application/json');
		$params = explode('/', $p);
		$park_id = $params[0];
		$action  = isset($params[1]) ? $params[1] : null;

		if (!isset($this->session->user_id)) {
			$this->data = ['status' => 5, 'error' => 'Not logged in'];
			return;
		}

		if ($action === 'add') {
			$r = $this->Attendance->add_attendance(
				$this->session->token,
				$_POST['AttendanceDate'] ?? '',
				$park_id,
				null,
				$_POST['MundaneId'] ?? 0,
				$_POST['ClassId']   ?? 0,
				$_POST['Credits']   ?? 1
			);
			if ($r['Status'] == 0) {
				$this->data = ['status' => 0, 'error' => '', 'attendanceId' => intval($r['Detail'])];
			} else {
				$this->data = ['status' => intval($r['Status']), 'error' => $r['Error']];
			}
		} else {
			$this->data = ['status' => 1, 'error' => 'Invalid action'];
		}
	}

}

?>
