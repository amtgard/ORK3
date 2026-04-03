<?php

class Controller_AttendanceAjax extends Controller {

	public function park($p = null) {
		header('Content-Type: application/json');
		$parts   = explode('/', $p ?? '');
		$park_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
		$action  = $parts[1] ?? '';

		if (!valid_id($park_id)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid park ID']);
			exit;
		}

		if ($action === 'add') {
			$this->load_model('Attendance');
			$mundaneId = (int)($_POST['MundaneId'] ?? 0);
			$r = $this->Attendance->add_attendance(
				$this->session->token,
				$_POST['AttendanceDate'] ?? date('Y-m-d'),
				$park_id,
				null,
				$mundaneId,
				(int)($_POST['ClassId']   ?? 0),
				(float)($_POST['Credits'] ?? 1)
			);
			if ($r['Status'] == 0) {
				// Auto-reactivate the player's profile if marked inactive. Adding
				// attendance for an inactive player implicitly reactivates them.
				$reactivated = 0;
				if ($mundaneId > 0) {
					global $DB;
					$DB->Clear();
					$chk = $DB->DataSet("SELECT active FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . $mundaneId . " LIMIT 1");
					if ($chk && $chk->Size() > 0 && $chk->Next() && (int)$chk->active === 0) {
						$DB->Clear();
						$DB->Execute("UPDATE " . DB_PREFIX . "mundane SET active = 1 WHERE mundane_id = " . $mundaneId);
						$reactivated = 1;
					}
				}
				if ($mundaneId) {
					$this->load_model('Player');
					$key = Ork3::$Lib->ghettocache->key(['MundaneId' => $mundaneId]);
					Ork3::$Lib->ghettocache->bust('Model_Player.fetch_player_details', $key);
				}
				echo json_encode(['status' => 0, 'attendanceId' => (int)($r['Detail'] ?? 0), 'reactivated' => $reactivated]);
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
			if ($r['Status'] == 0) {
				$editorId      = (int)$this->session->user_id;
				$editorPersona = '';
				global $DB;
				$DB->Clear();
				$row = $DB->DataSet("SELECT persona FROM " . DB_PREFIX . "mundane WHERE mundane_id = " . $editorId . " LIMIT 1");
				if ($row && $row->Size() > 0 && $row->Next()) {
					$editorPersona = $row->persona;
				}
				echo json_encode(['status' => 0, 'editor_id' => $editorId, 'editor_persona' => $editorPersona]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} elseif ($action === 'delete') {
			$mundaneId = (int)($_POST['MundaneId'] ?? 0);
			$r = $this->Attendance->delete_attendance($this->session->token, $attendance_id, $mundaneId);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function link($p = null) {
		header('Content-Type: application/json');
		$parts  = explode('/', $p ?? '');
		// Format: {scope}/{id}/create  where scope = park|kingdom
		$scope  = $parts[0] ?? '';
		$id     = (int)($parts[1] ?? 0);
		$action = $parts[2] ?? '';

		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}

		// Format: {scope}/{id}/create|list  or  delete/{link_id}
		// $scope = park|kingdom|delete, $id = entity_id or link_id, $action = create|list
		$this->load_model('Attendance');

		if ($scope === 'delete' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			// delete/{link_id}
			if (!valid_id($id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid link ID']); exit;
			}
			$r = $this->Attendance->delete_attendance_link($this->session->token, $id);
			echo ($r['Status'] == 0)
				? json_encode(['status' => 0])
				: json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

		} elseif ($action === 'list') {
			if ($scope !== 'park' && $scope !== 'kingdom') {
				echo json_encode(['status' => 1, 'error' => 'Invalid scope']); exit;
			}
			if (!valid_id($id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid ID']); exit;
			}
			$r = $this->Attendance->get_attendance_links($this->session->token, $scope, $id);
			if ($r['Status'] == 0) {
				$links = array_map(function($lnk) {
					$lnk['Url'] = HTTP_UI_REMOTE . 'index.php?Route=SignIn/index/' . $lnk['Token'];
					return $lnk;
				}, $r['Detail'] ?? []);
				echo json_encode(['status' => 0, 'links' => $links]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}

		} elseif ($action === 'create' && $_SERVER['REQUEST_METHOD'] === 'POST') {
			if ($scope !== 'park' && $scope !== 'kingdom') {
				echo json_encode(['status' => 1, 'error' => 'Invalid scope']);
				exit;
			}
			if (!valid_id($id)) {
				echo json_encode(['status' => 1, 'error' => 'Invalid ID']);
				exit;
			}
			$hours   = min(96, max(1, (int)($_POST['Hours']   ?? 3)));
			$credits = (float)($_POST['Credits'] ?? 1.0);

			$args = ['Token' => $this->session->token, 'Hours' => $hours, 'Credits' => $credits];
			if ($scope === 'park') {
				$args['ParkId'] = $id;
			} else {
				$args['KingdomId'] = $id;
			}

			$r = $this->Attendance->create_attendance_link($args);
			if ($r['Status'] == 0) {
				$token   = $r['Detail'];
				$url     = HTTP_UI_REMOTE . 'index.php?Route=SignIn/index/' . $token;
				$expires = date('D, M j g:i a T', time() + $hours * 3600);
				echo json_encode(['status' => 0, 'url' => $url, 'token' => $token, 'expires' => 'Expires ' . $expires]);
			} else {
				echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
			}
		} else {
			echo json_encode(['status' => 1, 'error' => 'Unknown action']);
		}
		exit;
	}

	public function myclass($p = null) {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		$this->load_model('Attendance');
		$class_id = (int)$this->Attendance->get_player_last_class((int)$this->session->user_id);
		echo json_encode(['status' => 0, 'class_id' => $class_id]);
		exit;
	}

}
