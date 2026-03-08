<?php

class Controller_AttendanceAjax extends Controller {

	public function park($p = null) {
		header('Content-Type: application/json');
		$parts   = explode('/', $p ?? '');
		$park_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action  = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($park_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid park ID']);
			exit;
		}

		if ($action === 'add') {
			$this->load_model('Attendance');
			$r = $this->Attendance->add_attendance(
				$this->session->token,
				$_POST['AttendanceDate'] ?? date('Y-m-d'),
				$park_id,
				null,
				(int)($_POST['MundaneId'] ?? 0),
				(int)($_POST['ClassId']   ?? 0),
				(float)($_POST['Credits'] ?? 1)
			);
			if ($r['Status'] == 0) {
				echo json_encode(['status' => 0, 'attendanceId' => (int)($r['Detail'] ?? 0)]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}
		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}
}
