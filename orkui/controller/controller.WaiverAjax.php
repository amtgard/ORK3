<?php

class Controller_WaiverAjax extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		header('Content-Type: application/json');
		$this->load_model('Waiver');
	}

	private function requireLogin() {
		if (!isset($this->session->user_id) || (int)$this->session->user_id <= 0) {
			echo json_encode(['status' => 5, 'error' => 'Not logged in']);
			exit;
		}
		return (int)$this->session->user_id;
	}

	private function respond($r, $extra = []) {
		$code = $r['Status']['Status'] ?? 1;
		$payload = ['status' => (int)$code];
		if ($code !== 0) {
			$payload['error'] = $r['Status']['Error']
				?? $r['Error']
				?? ($r['Status']['Message'] ?? 'Error');
		}
		foreach ($extra as $k => $v) {
			if ($v !== null) $payload[$k] = $v;
		}
		if ($code === 0) {
			foreach (['TemplateId','Version','SignatureId','Html','Template','Signature','Signatures','Total','Page','PageSize'] as $k) {
				if (isset($r[$k])) $payload[$k] = $r[$k];
			}
		}
		echo json_encode($payload);
		exit;
	}

	public function saveTemplate() {
		$this->requireLogin();
		$r = $this->Waiver->SaveTemplate([
			'Token'          => $this->session->token,
			'KingdomId'      => (int)($_POST['KingdomId'] ?? 0),
			'Scope'          => $_POST['Scope'] ?? '',
			'HeaderMarkdown' => $_POST['HeaderMarkdown'] ?? '',
			'BodyMarkdown'   => $_POST['BodyMarkdown']   ?? '',
			'FooterMarkdown' => $_POST['FooterMarkdown'] ?? '',
			'MinorMarkdown'  => $_POST['MinorMarkdown']  ?? '',
			'IsEnabled'      => (int)($_POST['IsEnabled'] ?? 0),
		]);
		$this->respond($r);
	}

	public function setEnabled() {
		$this->requireLogin();
		$r = $this->Waiver->SetTemplateEnabled([
			'Token'      => $this->session->token,
			'TemplateId' => (int)($_POST['TemplateId'] ?? 0),
			'IsEnabled'  => (int)($_POST['IsEnabled']  ?? 0),
		]);
		$this->respond($r);
	}

	public function previewMarkdown() {
		$this->requireLogin();
		$r = $this->Waiver->PreviewMarkdown([
			'Token'    => $this->session->token,
			'Markdown' => $_POST['Markdown'] ?? '',
		]);
		$this->respond($r);
	}

	public function submitSignature() {
		$this->requireLogin();
		$r = $this->Waiver->SubmitSignature([
			'Token'                => $this->session->token,
			'TemplateId'           => (int)($_POST['TemplateId'] ?? 0),
			'MundaneFirst'         => $_POST['MundaneFirst'] ?? '',
			'MundaneLast'          => $_POST['MundaneLast']  ?? '',
			'PersonaName'          => $_POST['PersonaName']  ?? '',
			'ParkId'               => (int)($_POST['ParkId']    ?? 0),
			'KingdomId'            => (int)($_POST['KingdomId'] ?? 0),
			'SignatureType'        => $_POST['SignatureType'] ?? '',
			'SignatureData'        => $_POST['SignatureData'] ?? '',
			'IsMinor'              => (int)($_POST['IsMinor'] ?? 0),
			'MinorRepFirst'        => $_POST['MinorRepFirst'] ?? '',
			'MinorRepLast'         => $_POST['MinorRepLast']  ?? '',
			'MinorRepRelationship' => $_POST['MinorRepRelationship'] ?? '',
		]);
		$this->respond($r);
	}

	public function verifySignature() {
		$this->requireLogin();
		$r = $this->Waiver->VerifySignature([
			'Token'         => $this->session->token,
			'SignatureId'   => (int)($_POST['SignatureId'] ?? 0),
			'Action'        => $_POST['Action'] ?? '',
			'PrintedName'   => $_POST['PrintedName'] ?? '',
			'PersonaName'   => $_POST['PersonaName'] ?? '',
			'OfficeTitle'   => $_POST['OfficeTitle'] ?? '',
			'SignatureType' => $_POST['SignatureType'] ?? '',
			'SignatureData' => $_POST['SignatureData'] ?? '',
			'Notes'         => $_POST['Notes'] ?? '',
		]);
		$this->respond($r);
	}

	public function getQueue() {
		$this->requireLogin();
		$r = $this->Waiver->GetQueue([
			'Token'    => $this->session->token,
			'Scope'    => $_GET['scope']     ?? $_POST['Scope']    ?? '',
			'EntityId' => (int)($_GET['entity_id'] ?? $_POST['EntityId'] ?? 0),
			'Filter'   => $_GET['filter']    ?? $_POST['Filter']   ?? 'pending',
			'Page'     => (int)($_GET['page']      ?? $_POST['Page']     ?? 1),
			'PageSize' => (int)($_GET['page_size'] ?? $_POST['PageSize'] ?? 10),
		]);
		$this->respond($r);
	}
}

?>
