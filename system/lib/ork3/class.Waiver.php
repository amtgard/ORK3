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

		$cfRaw = (string)($request['CustomFieldsJson'] ?? '[]');
		$cfErr = $this->_validate_custom_fields_json($cfRaw);
		if ($cfErr !== null) return ['Status' => InvalidParameter($cfErr)];

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
		$maxMinors = max(1, min(6, (int)($request['MaxMinors'] ?? 1)));

		$this->template->kingdom_id                = $kingdom_id;
		$this->template->scope                     = $scope;
		$this->template->version                   = $nextVersion;
		$this->template->is_active                 = 1;
		$this->template->is_enabled                = ((int)($request['IsEnabled'] ?? 0)) ? 1 : 0;
		$this->template->header_markdown           = (string)($request['HeaderMarkdown'] ?? '');
		$this->template->body_markdown             = (string)($request['BodyMarkdown']   ?? '');
		$this->template->footer_markdown           = (string)($request['FooterMarkdown'] ?? '');
		$this->template->minor_markdown            = (string)($request['MinorMarkdown']  ?? '');
		$this->template->requires_dob              = ((int)($request['RequiresDob']              ?? 0)) ? 1 : 0;
		$this->template->requires_address          = ((int)($request['RequiresAddress']          ?? 0)) ? 1 : 0;
		$this->template->requires_phone            = ((int)($request['RequiresPhone']            ?? 0)) ? 1 : 0;
		$this->template->requires_email            = ((int)($request['RequiresEmail']            ?? 0)) ? 1 : 0;
		$this->template->requires_preferred_name   = ((int)($request['RequiresPreferredName']    ?? 0)) ? 1 : 0;
		$this->template->requires_gender           = ((int)($request['RequiresGender']           ?? 0)) ? 1 : 0;
		$this->template->requires_emergency_contact= ((int)($request['RequiresEmergencyContact'] ?? 0)) ? 1 : 0;
		$this->template->requires_witness          = ((int)($request['RequiresWitness']          ?? 0)) ? 1 : 0;
		$this->template->max_minors                = $maxMinors;
		$this->template->custom_fields_json        = $cfRaw;
		$this->template->created_by_mundane_id     = $mundane_id;
		$this->template->created_at                = date('Y-m-d H:i:s');
		$this->template->save();

		$newId = (int)$this->template->waiver_template_id;
		if ($newId <= 0) return ['Status' => ProcessingError('Template save failed')];

		return [
			'Status'     => Success(),
			'TemplateId' => $newId,
			'Version'    => $nextVersion,
		];
	}

	private function _validate_custom_fields_json($raw) {
		if ($raw === '' || $raw === null) return null;
		$arr = json_decode($raw, true);
		if (!is_array($arr) || (count($arr) > 0 && !array_is_list($arr))) return 'CustomFieldsJson not valid JSON array';
		if (count($arr) > 50)  return 'CustomFieldsJson exceeds 50 fields';
		$seen = [];
		$allowed = ['text','textarea','checkbox','initial','radio','select','date'];
		foreach ($arr as $i => $f) {
			if (!is_array($f)) return "CustomFieldsJson entry $i not an object";
			$id = (string)($f['id'] ?? '');
			if (!preg_match('/^[a-z0-9_]{1,32}$/', $id)) return "CustomFieldsJson entry $i has invalid id";
			if (isset($seen[$id])) return "CustomFieldsJson entry $i has duplicate id '$id'";
			$seen[$id] = 1;
			$type = (string)($f['type'] ?? '');
			if (!in_array($type, $allowed, true)) return "CustomFieldsJson entry $i type '$type' not allowed";
			if (($type === 'radio' || $type === 'select')) {
				$opts = $f['options'] ?? null;
				if (!is_array($opts) || count($opts) < 1) return "CustomFieldsJson entry $i requires options";
			}
			$label = (string)($f['label'] ?? '');
			if ($label === '' || strlen($label) > 512) return "CustomFieldsJson entry $i has invalid label";
		}
		return null;
	}

	private function _shape_template($rs) {
		if (!$rs) return null;
		return [
			'TemplateId'                => (int)$rs->waiver_template_id,
			'KingdomId'                 => (int)$rs->kingdom_id,
			'Scope'                     => $rs->scope,
			'Version'                   => (int)$rs->version,
			'IsActive'                  => (int)$rs->is_active,
			'IsEnabled'                 => (int)$rs->is_enabled,
			'HeaderMarkdown'            => $rs->header_markdown,
			'BodyMarkdown'              => $rs->body_markdown,
			'FooterMarkdown'            => $rs->footer_markdown,
			'MinorMarkdown'             => $rs->minor_markdown,
			'RequiresDob'               => (int)($rs->requires_dob ?? 0),
			'RequiresAddress'           => (int)($rs->requires_address ?? 0),
			'RequiresPhone'             => (int)($rs->requires_phone ?? 0),
			'RequiresEmail'             => (int)($rs->requires_email ?? 0),
			'RequiresPreferredName'     => (int)($rs->requires_preferred_name ?? 0),
			'RequiresGender'            => (int)($rs->requires_gender ?? 0),
			'RequiresEmergencyContact'  => (int)($rs->requires_emergency_contact ?? 0),
			'RequiresWitness'           => (int)($rs->requires_witness ?? 0),
			'MaxMinors'                 => (int)($rs->max_minors ?? 1),
			'CustomFieldsJson'          => (string)($rs->custom_fields_json ?? '[]'),
			'CreatedAt'                 => $rs->created_at,
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

		// Enforce template demographic requirements
		$reqMap = [
			'RequiresDob' => ['Dob', 'Date of birth'],
			'RequiresAddress' => ['Address', 'Address'],
			'RequiresPhone' => ['Phone', 'Phone number'],
			'RequiresEmail' => ['Email', 'Email'],
			'RequiresPreferredName' => ['PreferredName', 'Preferred name'],
			'RequiresGender' => ['Gender', 'Gender'],
		];
		foreach ($reqMap as $flag => $pair) {
			if ((int)($t['Template'][$flag] ?? 0) === 1) {
				if (trim((string)($request[$pair[0]] ?? '')) === '') {
					return ['Status' => InvalidParameter($pair[1] . ' is required')];
				}
			}
		}
		if ((int)($t['Template']['RequiresEmergencyContact'] ?? 0) === 1) {
			foreach ([['EmergencyContactName', 'Emergency contact name'],
			          ['EmergencyContactPhone', 'Emergency contact phone'],
			          ['EmergencyContactRelationship', 'Emergency contact relationship']] as $pair) {
				if (trim((string)($request[$pair[0]] ?? '')) === '') {
					return ['Status' => InvalidParameter($pair[1] . ' is required')];
				}
			}
		}
		if ((int)($t['Template']['RequiresWitness'] ?? 0) === 1) {
			if (trim((string)($request['WitnessPrintedName'] ?? '')) === '')
				return ['Status' => InvalidParameter('Witness printed name is required')];
			if (!in_array($request['WitnessSignatureType'] ?? '', ['drawn','typed'], true))
				return ['Status' => InvalidParameter('Witness signature type is required')];
			if (trim((string)($request['WitnessSignatureData'] ?? '')) === '')
				return ['Status' => InvalidParameter('Witness signature is required')];
		}
		$cfTplRaw = (string)($t['Template']['CustomFieldsJson'] ?? '[]');
		$cfTpl = json_decode($cfTplRaw, true) ?: [];
		$cfResp = json_decode((string)($request['CustomResponsesJson'] ?? '{}'), true);
		$cfResp = is_array($cfResp) ? $cfResp : [];
		foreach ($cfTpl as $f) {
			if (empty($f['required'])) continue;
			$id = (string)($f['id'] ?? '');
			$v  = $cfResp[$id] ?? null;
			if ($v === null || $v === '' || $v === false) {
				return ['Status' => InvalidParameter('Custom field "' . ($f['label'] ?? $id) . '" is required')];
			}
		}

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
		$this->signature->preferred_name_snapshot       = substr(trim((string)($request['PreferredName'] ?? '')), 0, 64);
		$dob = (string)($request['Dob'] ?? '');
		$this->signature->dob_snapshot                  = ($dob !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $dob)) ? $dob : null;
		$this->signature->gender_snapshot               = substr(trim((string)($request['Gender']  ?? '')), 0, 32);
		$this->signature->address_snapshot              = substr(trim((string)($request['Address'] ?? '')), 0, 255);
		$this->signature->phone_snapshot                = substr(trim((string)($request['Phone']   ?? '')), 0, 32);
		$this->signature->email_snapshot                = substr(trim((string)($request['Email']   ?? '')), 0, 128);
		$this->signature->emergency_contact_name        = substr(trim((string)($request['EmergencyContactName']         ?? '')), 0, 128);
		$this->signature->emergency_contact_phone       = substr(trim((string)($request['EmergencyContactPhone']        ?? '')), 0, 32);
		$this->signature->emergency_contact_relationship= substr(trim((string)($request['EmergencyContactRelationship'] ?? '')), 0, 64);
		$witType = in_array($request['WitnessSignatureType'] ?? '', ['drawn','typed']) ? $request['WitnessSignatureType'] : null;
		$this->signature->witness_printed_name          = substr(trim((string)($request['WitnessPrintedName'] ?? '')), 0, 128);
		$this->signature->witness_signature_type        = $witType;
		$this->signature->witness_signature_data        = ($witType === null) ? null : (string)($request['WitnessSignatureData'] ?? '');
		$crRaw = (string)($request['CustomResponsesJson'] ?? '{}');
		$crDecoded = json_decode($crRaw, true);
		$this->signature->custom_responses_json         = is_array($crDecoded) ? json_encode($crDecoded) : '{}';
		$this->signature->verification_status           = 'pending';
		$this->signature->verifier_notes                = '';
		$this->signature->save();

		$newId = (int)$this->signature->waiver_signature_id;
		if ($newId <= 0) return ['Status' => ProcessingError('Signature save failed')];

		// Minors roster (up to template->max_minors). Additive: prior rows for this signature are replaced.
		$minors = $request['Minors'] ?? null;
		if (is_array($minors) && count($minors) > 0) {
			$tpl = $this->GetTemplate(['TemplateId' => $tid]);
			$maxMinors = (int)($tpl['Template']['MaxMinors'] ?? 1);
			$this->db->Clear();
			$this->db->waiver_signature_id = $newId;
			$this->db->Execute("DELETE FROM " . DB_PREFIX . "waiver_signature_minor WHERE waiver_signature_id = :waiver_signature_id");
			$minorOrm = new yapo($this->db, DB_PREFIX . 'waiver_signature_minor');
			$seq = 0;
			foreach ($minors as $m) {
				if ($seq >= $maxMinors) break;
				$minorOrm->clear();
				$minorOrm->waiver_signature_id = $newId;
				$minorOrm->seq                 = $seq;
				$minorOrm->legal_first         = substr(trim((string)($m['LegalFirst']    ?? '')), 0, 64);
				$minorOrm->legal_last          = substr(trim((string)($m['LegalLast']     ?? '')), 0, 64);
				$minorOrm->preferred_name      = substr(trim((string)($m['PreferredName'] ?? '')), 0, 64);
				$minorOrm->persona_name        = substr(trim((string)($m['PersonaName']   ?? '')), 0, 128);
				$mdob = (string)($m['Dob'] ?? '');
				$minorOrm->dob                 = ($mdob !== '' && preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $mdob)) ? $mdob : null;
				$minorOrm->save();
				$seq++;
			}
		}

		// Supersede any prior pending/verified signature by this same player for this template.
		$this->db->Clear();
		$this->db->mundane_id            = $mundane_id;
		$this->db->waiver_template_id    = $tid;
		$this->db->waiver_signature_id   = $newId;
		$this->db->Execute(
			"UPDATE " . DB_PREFIX . "waiver_signature
			 SET verification_status = 'superseded'
			 WHERE mundane_id = :mundane_id
			   AND waiver_template_id = :waiver_template_id
			   AND waiver_signature_id <> :waiver_signature_id
			   AND verification_status IN ('pending','verified')"
		);

		return ['Status' => Success(), 'SignatureId' => $newId];
	}

	private function _shape_signature($rs) {
		if (!$rs) return null;
		return [
			'SignatureId'                 => (int)$rs->waiver_signature_id,
			'TemplateId'                  => (int)$rs->waiver_template_id,
			'MundaneId'                   => (int)$rs->mundane_id,
			'MundaneFirst'                => $rs->mundane_first_snapshot,
			'MundaneLast'                 => $rs->mundane_last_snapshot,
			'PersonaName'                 => $rs->persona_name_snapshot,
			'ParkId'                      => (int)$rs->park_id_snapshot,
			'KingdomId'                   => (int)$rs->kingdom_id_snapshot,
			'SignatureType'               => $rs->signature_type,
			'SignatureData'               => $rs->signature_data,
			'SignedAt'                    => $rs->signed_at,
			'IsMinor'                     => (int)$rs->is_minor,
			'MinorRepFirst'               => $rs->minor_rep_first,
			'MinorRepLast'                => $rs->minor_rep_last,
			'MinorRepRelationship'        => $rs->minor_rep_relationship,
			'PreferredName'               => $rs->preferred_name_snapshot ?? '',
			'Dob'                         => $rs->dob_snapshot ?? null,
			'Gender'                      => $rs->gender_snapshot ?? '',
			'Address'                     => $rs->address_snapshot ?? '',
			'Phone'                       => $rs->phone_snapshot ?? '',
			'Email'                       => $rs->email_snapshot ?? '',
			'EmergencyContactName'        => $rs->emergency_contact_name ?? '',
			'EmergencyContactPhone'       => $rs->emergency_contact_phone ?? '',
			'EmergencyContactRelationship'=> $rs->emergency_contact_relationship ?? '',
			'WitnessPrintedName'          => $rs->witness_printed_name ?? '',
			'WitnessSignatureType'        => $rs->witness_signature_type ?? null,
			'WitnessSignatureData'        => $rs->witness_signature_data ?? null,
			'CustomResponsesJson'         => $rs->custom_responses_json ?? '{}',
			'VerificationStatus'          => $rs->verification_status,
			'VerifiedByMundaneId'         => (int)$rs->verified_by_mundane_id,
			'VerifiedAt'                  => $rs->verified_at,
			'VerifierPrintedName'         => $rs->verifier_printed_name,
			'VerifierPersonaName'         => $rs->verifier_persona_name,
			'VerifierOfficeTitle'         => $rs->verifier_office_title,
			'VerifierSignatureType'       => $rs->verifier_signature_type,
			'VerifierSignatureData'       => $rs->verifier_signature_data,
			'VerifierNotes'               => $rs->verifier_notes,
			'VerifierIdType'              => $rs->verifier_id_type ?? '',
			'VerifierIdNumberLast4'       => $rs->verifier_id_number_last4 ?? '',
			'VerifierAgeBracket'          => $rs->verifier_age_bracket ?? '',
			'VerifierScannedPaper'        => (int)($rs->verifier_scanned_paper ?? 0),
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

		// Minors roster (child table)
		$this->db->Clear();
		$this->db->waiver_signature_id = $sid;
		$mrs = $this->db->DataSet("SELECT * FROM " . DB_PREFIX . "waiver_signature_minor WHERE waiver_signature_id = :waiver_signature_id ORDER BY seq ASC");
		$minors = [];
		if ($mrs) {
			while ($mrs->Next()) {
				$minors[] = [
					'LegalFirst'    => $mrs->legal_first,
					'LegalLast'     => $mrs->legal_last,
					'PreferredName' => $mrs->preferred_name,
					'PersonaName'   => $mrs->persona_name,
					'Dob'           => $mrs->dob,
				];
			}
		}
		$sig['Minors'] = $minors;

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
		$idNumRaw = preg_replace('/[^0-9]/', '', (string)($request['IdNumber'] ?? ''));
		$last4    = ($idNumRaw === '') ? (string)($request['IdNumberLast4'] ?? '') : substr($idNumRaw, -4);
		$last4    = substr(preg_replace('/[^0-9]/', '', $last4), 0, 4);
		$ageIn    = (string)($request['AgeBracket'] ?? '');
		$ageOk    = in_array($ageIn, ['', '18+', '14+', 'under14'], true) ? $ageIn : '';
		$this->signature->verifier_id_type         = substr(trim((string)($request['IdType'] ?? '')), 0, 32);
		$this->signature->verifier_id_number_last4 = $last4;
		$this->signature->verifier_age_bracket     = $ageOk;
		$this->signature->verifier_scanned_paper   = ((int)($request['ScannedPaper'] ?? 0)) ? 1 : 0;
		$this->signature->save();

		return ['Status' => Success()];
	}

	public function PreviewMarkdown($request) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '');
		if ($mundane_id <= 0) return ['Status' => NoAuthorization()];
		$md = (string)($request['Markdown'] ?? '');
		if (strlen($md) > 65536) return ['Status' => InvalidParameter('Too large')];
		require_once(DIR_LIB . 'Parsedown.php');
		$html = (new Parsedown())->setSafeMode(true)->setBreaksEnabled(true)->text($md);
		return ['Status' => Success(), 'Html' => $html];
	}

}

?>
