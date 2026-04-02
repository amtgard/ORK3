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
		} elseif ($action === 'getday') {
			$this->load_model('Attendance');
			$date = $_GET['date'] ?? date('Y-m-d');
			if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid date']);
				exit;
			}
			$r = $this->Attendance->get_attendance_for_date($park_id, $date);
			$seen    = [];
			$entries = [];
			foreach ($r['Attendance'] ?? [] as $att) {
				$mid = (int)$att['MundaneId'];
				$aid = (int)($att['AttendanceId'] ?? $att['attendance_id'] ?? 0);
				if (isset($seen[$mid]) && $seen[$mid] >= $aid) continue;
				$seen[$mid] = $aid;
				$entries[$mid] = [
					'AttendanceId' => $aid,
					'MundaneId'   => $mid,
					'Persona'     => (string)($att['Persona'] ?? $att['AttendancePersona'] ?? ''),
					'ClassId'     => (int)($att['ClassId'] ?? 0),
					'Credits'     => (float)($att['Credits'] ?? 1),
				];
			}
			echo json_encode(['status' => 0, 'entries' => array_values($entries)]);
		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function attendance($p = null) {
		header('Content-Type: application/json');
		$parts         = explode('/', $p ?? '');
		$attendance_id = (int)($parts[0] ?? 0);
		$action        = $parts[1] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		if (!valid_id($attendance_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid attendance ID']);
			exit;
		}

		$this->load_model('Attendance');

		if ($action === 'edit') {
			$date      = trim($_POST['Date']      ?? '');
			$credits   = (float)($_POST['Credits'] ?? 1);
			$classId   = (int)($_POST['ClassId']   ?? 0);
			$mundaneId = (int)($_POST['MundaneId'] ?? 0);
			if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid date']); exit;
			}
			if (!valid_id($classId)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid class']); exit;
			}
			$r = $this->Attendance->update_attendance($this->session->token, $attendance_id, $date, $credits, $classId, $mundaneId);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'delete') {
			$r = $this->Attendance->delete_attendance($this->session->token, $attendance_id);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}
}
