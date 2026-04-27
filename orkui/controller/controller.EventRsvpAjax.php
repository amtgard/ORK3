<?php

class Controller_EventRsvpAjax extends Controller {

	private function requireLogin() {
		if (!isset($this->session->user_id)) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
	}

	private function counts($detailId) {
		global $DB;
		$DB->Clear();
		$rs = $DB->DataSet("
			SELECT
				SUM(CASE WHEN status = 'going'      THEN 1 ELSE 0 END) AS going_count,
				SUM(CASE WHEN status = 'interested' THEN 1 ELSE 0 END) AS interested_count
			FROM " . DB_PREFIX . "event_rsvp
			WHERE event_calendardetail_id = " . (int)$detailId);
		$g = 0; $i = 0;
		if ($rs && $rs->Next()) {
			$g = (int)$rs->going_count;
			$i = (int)$rs->interested_count;
		}
		return ['going' => $g, 'interested' => $i];
	}

	public function set($p = null) {
		header('Content-Type: application/json');
		$this->requireLogin();

		$detailId = (int)($_POST['EventCalendarDetailId'] ?? 0);
		$status   = (string)($_POST['Status'] ?? '');
		if ($detailId <= 0 || !in_array($status, ['going', 'interested'], true)) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters']);
			exit;
		}

		$uid = (int)$this->session->user_id;
		global $DB;
		$DB->Clear();
		$DB->Execute("
			INSERT INTO " . DB_PREFIX . "event_rsvp (event_calendardetail_id, mundane_id, status, modified)
			VALUES ({$detailId}, {$uid}, '" . mysql_real_escape_string($status) . "', NOW())
			ON DUPLICATE KEY UPDATE status = VALUES(status), modified = NOW()");

		$counts = $this->counts($detailId);
		echo json_encode([
			'status'           => 0,
			'my_status'        => $status,
			'going_count'      => $counts['going'],
			'interested_count' => $counts['interested'],
		]);
		exit;
	}

	public function withdraw($p = null) {
		header('Content-Type: application/json');
		$this->requireLogin();

		$detailId = (int)($_POST['EventCalendarDetailId'] ?? 0);
		if ($detailId <= 0) {
			echo json_encode(['status' => 1, 'error' => 'Invalid parameters']);
			exit;
		}

		$uid = (int)$this->session->user_id;
		global $DB;
		$DB->Clear();
		$DB->Execute("
			DELETE FROM " . DB_PREFIX . "event_rsvp
			WHERE event_calendardetail_id = {$detailId} AND mundane_id = {$uid}");

		$counts = $this->counts($detailId);
		echo json_encode([
			'status'           => 0,
			'my_status'        => '',
			'going_count'      => $counts['going'],
			'interested_count' => $counts['interested'],
		]);
		exit;
	}
}
