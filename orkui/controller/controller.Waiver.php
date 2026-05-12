<?php

class Controller_Waiver extends Controller {

	public function __construct($call = null, $id = null) {
		parent::__construct($call, $id);
		$this->load_model('Waiver');
		$this->load_model('Player');
		$this->load_model('Kingdom');
		$this->load_model('Park');
	}

	private function _currentMundaneId() {
		return isset($this->session->user_id) ? (int)$this->session->user_id : 0;
	}

	private function _go($path) {
		header('Location: ' . UIR . $path);
		exit;
	}

	private function _clean_int($v) {
		return (int)preg_replace('/[^0-9]/', '', (string)$v);
	}

	public function index($id = null) {
		$this->_go('Kingdom/index/' . $this->_clean_int($id));
	}

	// Kingdom admin: edit both kingdom + park templates for this kingdom
	// Route: Waiver/builder/{kingdom_id}[/{variant}]  — variant = 'a' (Trix, default) or 'b' (Markdown)
	public function builder($params = null) {
		$parts = explode('/', (string)($params ?? ''));
		$kingdom_id = $this->_clean_int($parts[0] ?? '');
		$variant    = in_array($parts[1] ?? '', ['a','b'], true) ? $parts[1] : 'a';
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0 || (!Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT))) {
			$this->_go('Kingdom/index/' . $kingdom_id);
			return;
		}
		$kk = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => 'kingdom', 'Variant' => $variant]);
		$pk = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => 'park',    'Variant' => $variant]);
		$this->data['_wv'] = [
			'kingdom_id'       => $kingdom_id,
			'variant'          => $variant,
			'kingdom_template' => (($kk['Status']['Status'] ?? 1) === 0) ? $kk['Template'] : null,
			'park_template'    => (($pk['Status']['Status'] ?? 1) === 0) ? $pk['Template'] : null,
			'token'            => $this->session->token,
		];
		$this->data['kingdom_info'] = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->template = $variant === 'b'
			? '../revised-frontend/Waiver_builder_b.tpl'
			: '../revised-frontend/Waiver_builder.tpl';
	}

	// Player: sign a kingdom/park waiver
	// Route: Waiver/sign/{scope}/{id} — dispatcher passes "scope/id" as a single $params string
	public function sign($params = null) {
		$parts = explode('/', (string)($params ?? ''));
		$scope = in_array($parts[0] ?? '', ['kingdom','park']) ? $parts[0] : 'kingdom';
		$id    = $this->_clean_int($parts[1] ?? '');
		$_uid  = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->_go('Login/login'); return; }

		// Resolve kingdom for template lookup
		if ($scope === 'park') {
			$park = $this->Park->get_park_details($id);
			$kingdom_id = (int)($park['ParkInfo']['KingdomId'] ?? 0);
		} else {
			$kingdom_id = $id;
		}
		$active = $this->Waiver->GetActiveTemplate(['KingdomId' => $kingdom_id, 'Scope' => $scope]);
		$template = (($active['Status']['Status'] ?? 1) === 0 && (int)($active['Template']['IsEnabled'] ?? 0) === 1)
			? $active['Template']
			: null;

		// Prefill from player profile (fetch_player returns the Player array or false)
		$player = $this->Player->fetch_player($_uid) ?: [];
		$this->data['_wv'] = [
			'scope'      => $scope,
			'entity_id'  => $id,
			'kingdom_id' => $kingdom_id,
			'template'   => $template,
			'prefill'    => [
				'MundaneFirst' => $player['GivenName']   ?? '',
				'MundaneLast'  => $player['Surname']     ?? '',
				'PersonaName'  => $player['Persona']     ?? '',
				'ParkId'       => (int)($player['ParkId']    ?? 0),
				'KingdomId'    => (int)($player['KingdomId'] ?? 0),
				'Address'      => trim(($player['Address']     ?? '') . ' ' . ($player['Address2'] ?? '')),
				'Phone'        => $player['Phone']       ?? '',
				'Email'        => $player['Email']       ?? '',
				'Dob'          => $player['DateOfBirth'] ?? '',
			],
			'token'      => $this->session->token,
		];
		$this->template = '../revised-frontend/Waiver_sign.tpl';
	}

	// Officer: review queue
	// Route: Waiver/queue/{scope}/{id} — dispatcher passes "scope/id" as a single $params string
	public function queue($params = null) {
		$parts = explode('/', (string)($params ?? ''));
		$scope = in_array($parts[0] ?? '', ['kingdom','park']) ? $parts[0] : 'kingdom';
		$id    = $this->_clean_int($parts[1] ?? '');
		$_uid  = $this->_currentMundaneId();
		$authType = ($scope === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
		if ($_uid <= 0 || (!Ork3::$Lib->authorization->HasAuthority($_uid, $authType, $id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT))) {
			$this->_go(($scope === 'park' ? 'Park' : 'Kingdom') . '/index/' . $id);
			return;
		}
		$filterIn = $_GET['filter'] ?? 'pending';
		$filter = in_array($filterIn, ['pending','verified','rejected','stale','all']) ? $filterIn : 'pending';
		$page   = max(1, (int)($_GET['page'] ?? 1));
		$r = $this->Waiver->GetQueue([
			'Token'    => $this->session->token,
			'Scope'    => $scope,
			'EntityId' => $id,
			'Filter'   => $filter,
			'Page'     => $page,
			'PageSize' => 10,
		]);
		$this->data['_wv'] = [
			'scope'      => $scope,
			'entity_id'  => $id,
			'filter'     => $filter,
			'page'       => $page,
			'signatures' => $r['Signatures'] ?? [],
			'total'      => (int)($r['Total'] ?? 0),
			'token'      => $this->session->token,
		];
		$this->template = '../revised-frontend/Waiver_queue.tpl';
	}

	public function review($signature_id = null) {
		$signature_id = $this->_clean_int($signature_id);
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->_go('Login/login'); return; }
		$r = $this->Waiver->GetSignature(['Token' => $this->session->token, 'SignatureId' => $signature_id]);
		if (($r['Status']['Status'] ?? 1) !== 0) { $this->_go('Player/index/' . $_uid); return; }
		$sig = $r['Signature'];
		$isOfficer = Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, (int)$sig['KingdomId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$sig['ParkId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT);
		$player = $this->Player->fetch_player($_uid) ?: [];

		$this->data['_wv'] = [
			'signature'       => $sig,
			'is_officer'      => $isOfficer,
			'is_signer'       => ((int)$sig['MundaneId'] === $_uid),
			'token'           => $this->session->token,
			'officer_prefill' => [
				'PrintedName' => trim(($player['GivenName'] ?? '') . ' ' . ($player['Surname'] ?? '')),
				'PersonaName' => $player['Persona'] ?? '',
			],
		];
		$this->template = '../revised-frontend/Waiver_review.tpl';
	}

	public function printable($signature_id = null) {
		$signature_id = $this->_clean_int($signature_id);
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0) { $this->_go('Login/login'); return; }
		$r = $this->Waiver->GetSignature(['Token' => $this->session->token, 'SignatureId' => $signature_id]);
		if (($r['Status']['Status'] ?? 1) !== 0) { $this->_go('Player/index/' . $_uid); return; }
		$this->data['_wv'] = ['signature' => $r['Signature']];
		$this->template = '../revised-frontend/Waiver_print.tpl';
	}

	// Kingdom admin: live preview of unsaved builder state.
	// Route: Waiver/preview/{kingdom_id}[/{variant}] (POST). Renders the waiver as a player would see it.
	public function preview($params = null) {
		$parts = explode('/', (string)($params ?? ''));
		$kingdom_id = $this->_clean_int($parts[0] ?? '');
		$variant    = in_array($parts[1] ?? '', ['a','b'], true) ? $parts[1] : 'a';
		$_uid = $this->_currentMundaneId();
		if ($_uid <= 0 || (!Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_ADMIN, 0, AUTH_EDIT))) {
			$this->_go('Kingdom/index/' . $kingdom_id);
			return;
		}
		$scope = in_array($_POST['Scope'] ?? '', ['kingdom','park']) ? $_POST['Scope'] : 'kingdom';
		$cf = $_POST['CustomFieldsJson'] ?? '[]';
		// Variant B: posted fields are markdown; render to sanitized HTML for preview.
		// Variant A: posted fields are already HTML; sanitize directly.
		if ($variant === 'b') {
			require_once(DIR_LIB . 'Parsedown.php');
			$pd = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true);
			$render = function($md) use ($pd) { return $this->Waiver->_sanitize_html($pd->text((string)$md)); };
			$headerHtml = $render($_POST['HeaderMarkdown'] ?? '');
			$bodyHtml   = $render($_POST['BodyMarkdown']   ?? '');
			$footerHtml = $render($_POST['FooterMarkdown'] ?? '');
			$minorHtml  = $render($_POST['MinorMarkdown']  ?? '');
		} else {
			$headerHtml = $this->Waiver->_sanitize_html((string)($_POST['HeaderHtml'] ?? ''));
			$bodyHtml   = $this->Waiver->_sanitize_html((string)($_POST['BodyHtml']   ?? ''));
			$footerHtml = $this->Waiver->_sanitize_html((string)($_POST['FooterHtml'] ?? ''));
			$minorHtml  = $this->Waiver->_sanitize_html((string)($_POST['MinorHtml']  ?? ''));
		}
		$template = [
			'HeaderHtml'               => $headerHtml,
			'BodyHtml'                 => $bodyHtml,
			'FooterHtml'               => $footerHtml,
			'MinorHtml'                => $minorHtml,
			'RequiresDob'              => (int)($_POST['RequiresDob']              ?? 0),
			'RequiresAddress'          => (int)($_POST['RequiresAddress']          ?? 0),
			'RequiresPhone'            => (int)($_POST['RequiresPhone']            ?? 0),
			'RequiresEmail'            => (int)($_POST['RequiresEmail']            ?? 0),
			'RequiresPreferredName'    => (int)($_POST['RequiresPreferredName']    ?? 0),
			'RequiresGender'           => (int)($_POST['RequiresGender']           ?? 0),
			'RequiresEmergencyContact' => (int)($_POST['RequiresEmergencyContact'] ?? 0),
			'RequiresWitness'          => (int)($_POST['RequiresWitness']          ?? 0),
			'MaxMinors'                => max(1, min(6, (int)($_POST['MaxMinors'] ?? 1))),
			'CustomFieldsJson'         => is_string($cf) ? $cf : '[]',
			'Scope'                    => $scope,
			'Version'                  => 0,
		];
		$kingdom_info = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
		$this->data['_wv'] = [
			'kingdom_id'   => $kingdom_id,
			'scope'        => $scope,
			'template'     => $template,
			'kingdom_info' => $kingdom_info,
		];
		$this->template = '../revised-frontend/Waiver_preview.tpl';
	}
}

?>
