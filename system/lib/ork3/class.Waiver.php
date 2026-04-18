<?php

class Waiver extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->template  = new yapo($this->db, DB_PREFIX . 'waiver_template');
		$this->signature = new yapo($this->db, DB_PREFIX . 'waiver_signature');
		$this->mundane   = new yapo($this->db, DB_PREFIX . 'mundane');
		$this->kingdom   = new yapo($this->db, DB_PREFIX . 'kingdom');
		$this->park      = new yapo($this->db, DB_PREFIX . 'park');
	}

	public function SaveTemplate($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		$scope      = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		if ($kingdom_id <= 0) return ['Status' => InvalidParameter('KingdomId required')];
		if ($scope === null)  return ['Status' => InvalidParameter('Scope invalid')];
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => NoAuthorization()];
		}

		// Find the currently active template for (kingdom, scope), if any
		$this->db->Clear();
		$this->db->kingdom_id = $kingdom_id;
		$this->db->scope      = $scope;
		$prev = $this->db->DataSet(
			"SELECT waiver_template_id, version FROM " . DB_PREFIX . "waiver_template
			 WHERE kingdom_id = :kingdom_id AND scope = :scope AND is_active = 1
			 ORDER BY version DESC LIMIT 1"
		);
		$prevId = 0; $prevVersion = 0;
		if ($prev && $prev->Next()) {
			$prevId      = (int)$prev->waiver_template_id;
			$prevVersion = (int)$prev->version;
		}
		$nextVersion = $prevVersion > 0 ? $prevVersion + 1 : 1;

		if ($prevId > 0) {
			$this->db->Clear();
			$this->db->waiver_template_id = $prevId;
			$this->db->Execute("UPDATE " . DB_PREFIX . "waiver_template SET is_active = 0 WHERE waiver_template_id = :waiver_template_id");
		}

		$this->template->clear();
		$this->template->kingdom_id            = $kingdom_id;
		$this->template->scope                 = $scope;
		$this->template->version               = $nextVersion;
		$this->template->is_active             = 1;
		$this->template->is_enabled            = ((int)($request['IsEnabled'] ?? 0)) ? 1 : 0;
		$this->template->header_markdown       = (string)($request['HeaderMarkdown'] ?? '');
		$this->template->body_markdown         = (string)($request['BodyMarkdown']   ?? '');
		$this->template->footer_markdown       = (string)($request['FooterMarkdown'] ?? '');
		$this->template->minor_markdown        = (string)($request['MinorMarkdown']  ?? '');
		$this->template->created_by_mundane_id = $mundane_id;
		$this->template->created_at            = date('Y-m-d H:i:s');
		$this->template->save();

		$newId = (int)$this->template->waiver_template_id;
		if ($newId <= 0) return ['Status' => ProcessingError('Template save failed')];

		return [
			'Status'     => Success(),
			'TemplateId' => $newId,
			'Version'    => $nextVersion,
		];
	}

	private function _shape_template($rs) {
		if (!$rs) return null;
		return [
			'TemplateId'      => (int)$rs->waiver_template_id,
			'KingdomId'       => (int)$rs->kingdom_id,
			'Scope'           => $rs->scope,
			'Version'         => (int)$rs->version,
			'IsActive'        => (int)$rs->is_active,
			'IsEnabled'       => (int)$rs->is_enabled,
			'HeaderMarkdown'  => $rs->header_markdown,
			'BodyMarkdown'    => $rs->body_markdown,
			'FooterMarkdown'  => $rs->footer_markdown,
			'MinorMarkdown'   => $rs->minor_markdown,
			'CreatedAt'       => $rs->created_at,
		];
	}

	public function GetActiveTemplate($request) {
		$kingdom_id = (int)($request['KingdomId'] ?? 0);
		$scope      = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		if ($kingdom_id <= 0 || $scope === null) return ['Status' => InvalidParameter()];

		$this->db->Clear();
		$this->db->kingdom_id = $kingdom_id;
		$this->db->scope      = $scope;
		$rs = $this->db->DataSet(
			"SELECT * FROM " . DB_PREFIX . "waiver_template
			 WHERE kingdom_id = :kingdom_id AND scope = :scope AND is_active = 1 LIMIT 1"
		);
		if (!$rs || !$rs->Next()) return ['Status' => ProcessingError('Template not found')];
		return ['Status' => Success(), 'Template' => $this->_shape_template($rs)];
	}

	public function GetTemplate($request) {
		$tid = (int)($request['TemplateId'] ?? 0);
		if ($tid <= 0) return ['Status' => InvalidParameter('TemplateId required')];

		$this->db->Clear();
		$this->db->waiver_template_id = $tid;
		$rs = $this->db->DataSet(
			"SELECT * FROM " . DB_PREFIX . "waiver_template WHERE waiver_template_id = :waiver_template_id LIMIT 1"
		);
		if (!$rs || !$rs->Next()) return ['Status' => ProcessingError('Template not found')];
		return ['Status' => Success(), 'Template' => $this->_shape_template($rs)];
	}

	public function SetTemplateEnabled($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		$tid = (int)($request['TemplateId'] ?? 0);
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		if ($tid <= 0)        return ['Status' => InvalidParameter('TemplateId required')];

		$t = $this->GetTemplate(['TemplateId' => $tid]);
		if (($t['Status']['Status'] ?? 1) !== 0) return $t;
		$kingdom_id = (int)$t['Template']['KingdomId'];

		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => NoAuthorization()];
		}

		$this->db->Clear();
		$this->db->is_enabled         = ((int)($request['IsEnabled'] ?? 0)) ? 1 : 0;
		$this->db->waiver_template_id = $tid;
		$this->db->Execute("UPDATE " . DB_PREFIX . "waiver_template SET is_enabled = :is_enabled WHERE waiver_template_id = :waiver_template_id");

		return ['Status' => Success()];
	}

	public function SubmitSignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];

		$tid = (int)($request['TemplateId'] ?? 0);
		$t = $this->GetTemplate(['TemplateId' => $tid]);
		if (($t['Status']['Status'] ?? 1) !== 0) return $t;
		if ((int)$t['Template']['IsActive'] !== 1 || (int)$t['Template']['IsEnabled'] !== 1) {
			return ['Status' => InvalidParameter('Template not currently accepting signatures')];
		}

		$sigType = in_array($request['SignatureType'] ?? '', ['drawn','typed']) ? $request['SignatureType'] : null;
		if ($sigType === null) return ['Status' => InvalidParameter('SignatureType invalid')];
		$sigData = (string)($request['SignatureData'] ?? '');
		if ($sigData === '' || strlen($sigData) > 262144) return ['Status' => InvalidParameter('Signature empty or too large')];

		$isMinor = ((int)($request['IsMinor'] ?? 0)) ? 1 : 0;
		if ($isMinor) {
			if (trim((string)($request['MinorRepFirst']        ?? '')) === '') return ['Status' => InvalidParameter('Minor rep first name required')];
			if (trim((string)($request['MinorRepLast']         ?? '')) === '') return ['Status' => InvalidParameter('Minor rep last name required')];
			if (trim((string)($request['MinorRepRelationship'] ?? '')) === '') return ['Status' => InvalidParameter('Relationship required')];
		}

		$this->signature->clear();
		$this->signature->waiver_template_id     = $tid;
		$this->signature->mundane_id             = $mundane_id;
		$this->signature->mundane_first_snapshot = substr(trim((string)($request['MundaneFirst'] ?? '')), 0, 64);
		$this->signature->mundane_last_snapshot  = substr(trim((string)($request['MundaneLast']  ?? '')), 0, 64);
		$this->signature->persona_name_snapshot  = substr(trim((string)($request['PersonaName']  ?? '')), 0, 128);
		$this->signature->park_id_snapshot       = (int)($request['ParkId']    ?? 0);
		$this->signature->kingdom_id_snapshot    = (int)($request['KingdomId'] ?? 0);
		$this->signature->signature_type         = $sigType;
		$this->signature->signature_data         = $sigData;
		$this->signature->signed_at              = date('Y-m-d H:i:s');
		$this->signature->is_minor               = $isMinor;
		$this->signature->minor_rep_first        = substr(trim((string)($request['MinorRepFirst']        ?? '')), 0, 64);
		$this->signature->minor_rep_last         = substr(trim((string)($request['MinorRepLast']         ?? '')), 0, 64);
		$this->signature->minor_rep_relationship = substr(trim((string)($request['MinorRepRelationship'] ?? '')), 0, 64);
		$this->signature->verification_status    = 'pending';
		$this->signature->verifier_notes         = '';
		$this->signature->save();

		$newId = (int)$this->signature->waiver_signature_id;
		if ($newId <= 0) return ['Status' => ProcessingError('Signature save failed')];

		return ['Status' => Success(), 'SignatureId' => $newId];
	}

	private function _shape_signature($rs) {
		if (!$rs) return null;
		return [
			'SignatureId'           => (int)$rs->waiver_signature_id,
			'TemplateId'            => (int)$rs->waiver_template_id,
			'MundaneId'             => (int)$rs->mundane_id,
			'MundaneFirst'          => $rs->mundane_first_snapshot,
			'MundaneLast'           => $rs->mundane_last_snapshot,
			'PersonaName'           => $rs->persona_name_snapshot,
			'ParkId'                => (int)$rs->park_id_snapshot,
			'KingdomId'             => (int)$rs->kingdom_id_snapshot,
			'SignatureType'         => $rs->signature_type,
			'SignatureData'         => $rs->signature_data,
			'SignedAt'              => $rs->signed_at,
			'IsMinor'               => (int)$rs->is_minor,
			'MinorRepFirst'         => $rs->minor_rep_first,
			'MinorRepLast'          => $rs->minor_rep_last,
			'MinorRepRelationship'  => $rs->minor_rep_relationship,
			'VerificationStatus'    => $rs->verification_status,
			'VerifiedByMundaneId'   => (int)$rs->verified_by_mundane_id,
			'VerifiedAt'            => $rs->verified_at,
			'VerifierPrintedName'   => $rs->verifier_printed_name,
			'VerifierPersonaName'   => $rs->verifier_persona_name,
			'VerifierOfficeTitle'   => $rs->verifier_office_title,
			'VerifierSignatureType' => $rs->verifier_signature_type,
			'VerifierSignatureData' => $rs->verifier_signature_data,
			'VerifierNotes'         => $rs->verifier_notes,
		];
	}

	public function GetSignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		$sid = (int)($request['SignatureId'] ?? 0);
		if ($sid <= 0) return ['Status' => InvalidParameter('SignatureId required')];

		$this->db->Clear();
		$this->db->waiver_signature_id = $sid;
		$rs = $this->db->DataSet("SELECT * FROM " . DB_PREFIX . "waiver_signature WHERE waiver_signature_id = :waiver_signature_id LIMIT 1");
		if (!$rs || !$rs->Next()) return ['Status' => ProcessingError('Signature not found')];
		$sig = $this->_shape_signature($rs);

		$authorized = ($sig['MundaneId'] === $mundane_id)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $sig['KingdomId'], AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK,    $sig['ParkId'],    AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT);
		if (!$authorized) return ['Status' => NoAuthorization()];

		$t = $this->GetTemplate(['TemplateId' => $sig['TemplateId']]);
		$sig['Template'] = (($t['Status']['Status'] ?? 1) === 0) ? $t['Template'] : null;

		return ['Status' => Success(), 'Signature' => $sig];
	}

	public function GetQueue($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		$scope     = in_array($request['Scope'] ?? '', ['kingdom','park']) ? $request['Scope'] : null;
		$entity_id = (int)($request['EntityId'] ?? 0);
		if ($scope === null || $entity_id <= 0) return ['Status' => InvalidParameter()];

		$authType = ($scope === 'kingdom') ? AUTH_KINGDOM : AUTH_PARK;
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, $authType, $entity_id, AUTH_EDIT)
			&& !Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
			return ['Status' => NoAuthorization()];
		}

		$filterIn = $request['Filter'] ?? 'pending';
		$filter   = in_array($filterIn, ['pending','verified','rejected','stale','all']) ? $filterIn : 'pending';

		// Clamp pagination (Page >= 1, PageSize in [1, 100])
		$page     = max(1, (int)($request['Page'] ?? 1));
		$pageSize = max(1, min(100, (int)($request['PageSize'] ?? 10)));
		$offset   = ($page - 1) * $pageSize;

		$scopeCol  = ($scope === 'kingdom') ? 's.kingdom_id_snapshot' : 's.park_id_snapshot';
		$scopeName = ($scope === 'kingdom') ? 'kingdom' : 'park';

		switch ($filter) {
			case 'pending':   $statusClause = "AND s.verification_status = 'pending' AND t.is_active = 1"; break;
			case 'verified':  $statusClause = "AND s.verification_status = 'verified'"; break;
			case 'rejected':  $statusClause = "AND s.verification_status IN ('rejected','superseded')"; break;
			case 'stale':     $statusClause = "AND s.verification_status = 'pending' AND t.is_active = 0"; break;
			case 'all':       $statusClause = ''; break;
			default:          $statusClause = '';
		}

		$fromWhere = "FROM " . DB_PREFIX . "waiver_signature s
		              JOIN " . DB_PREFIX . "waiver_template t ON t.waiver_template_id = s.waiver_template_id
		              WHERE $scopeCol = :entity_id AND t.scope = '$scopeName' $statusClause";

		$this->db->Clear();
		$this->db->entity_id = $entity_id;
		$rsc = $this->db->DataSet("SELECT COUNT(*) AS c $fromWhere");
		$total = 0;
		if ($rsc && $rsc->Next()) $total = (int)$rsc->c;

		// LIMIT / OFFSET are integer-cast values we built ourselves, safe to interpolate
		$this->db->Clear();
		$this->db->entity_id = $entity_id;
		$rs = $this->db->DataSet("SELECT s.* $fromWhere ORDER BY s.signed_at DESC LIMIT $pageSize OFFSET $offset");
		$out = [];
		if ($rs) { while ($rs->Next()) { $out[] = $this->_shape_signature($rs); } }

		return [
			'Status'     => Success(),
			'Signatures' => $out,
			'Total'      => $total,
			'Page'       => $page,
			'PageSize'   => $pageSize,
		];
	}

	public function VerifySignature($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		$sid = (int)($request['SignatureId'] ?? 0);
		if ($sid <= 0) return ['Status' => InvalidParameter('SignatureId required')];
		$action = in_array($request['Action'] ?? '', ['verified','rejected','superseded']) ? $request['Action'] : null;
		if ($action === null) return ['Status' => InvalidParameter('Action invalid')];

		$cur = $this->GetSignature(['Token' => $request['Token'], 'SignatureId' => $sid]);
		if (($cur['Status']['Status'] ?? 1) !== 0) return $cur;

		$kid = (int)$cur['Signature']['KingdomId'];
		$pid = (int)$cur['Signature']['ParkId'];
		$authorized = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $kid, AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $pid, AUTH_EDIT)
			|| Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT);
		if (!$authorized) return ['Status' => NoAuthorization()];

		if ($action === 'rejected' && trim((string)($request['Notes'] ?? '')) === '') {
			return ['Status' => InvalidParameter('Notes required when rejecting')];
		}
		$sigType = in_array($request['SignatureType'] ?? '', ['drawn','typed']) ? $request['SignatureType'] : null;
		$sigData = (string)($request['SignatureData'] ?? '');
		if ($action !== 'superseded' && ($sigType === null || $sigData === '')) {
			return ['Status' => InvalidParameter('Verifier signature required')];
		}

		$this->signature->clear();
		$this->signature->waiver_signature_id = $sid;
		if (!$this->signature->find()) return ['Status' => ProcessingError('Signature not found')];

		$this->signature->verification_status     = $action;
		$this->signature->verified_by_mundane_id  = $mundane_id;
		$this->signature->verified_at             = date('Y-m-d H:i:s');
		$this->signature->verifier_printed_name   = substr(trim((string)($request['PrintedName'] ?? '')), 0, 128);
		$this->signature->verifier_persona_name   = substr(trim((string)($request['PersonaName'] ?? '')), 0, 128);
		$this->signature->verifier_office_title   = substr(trim((string)($request['OfficeTitle'] ?? '')), 0, 128);
		$this->signature->verifier_signature_type = $sigType;
		$this->signature->verifier_signature_data = $sigData;
		$this->signature->verifier_notes          = (string)($request['Notes'] ?? '');
		$this->signature->save();

		return ['Status' => Success()];
	}

}

?>
