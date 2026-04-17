<?php

class Controller_WaiverAjax extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		header('Content-Type: application/json');
	}

	private function requireLogin() {
		if (!isset($this->session->user_id) || (int)$this->session->user_id <= 0) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		return (int)$this->session->user_id;
	}

	public function saveTemplate()      { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function previewMarkdown()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function submitSignature()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function verifySignature()   { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
	public function setEnabled()        { $this->requireLogin(); echo json_encode(['status' => 9, 'error' => 'not implemented']); exit; }
}

?>
