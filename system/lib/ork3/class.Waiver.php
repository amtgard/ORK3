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

}

?>
