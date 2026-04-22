<?php

class Dangeraudit extends Ork3 {
	
	public function __construct() {
		parent::__construct();
   		$this->audit = new yapo($this->db, DB_PREFIX . 'danger_audit');
	}
	
	public function audit($call, $parameters, $entity, $entity_id, $prior_state = null, $post_state = null) {
		$mundane_id = Ork3::$Lib->authorization->IsAuthorized($_SESSION['is_authorized_mundane_id']);
		$this->audit->clear();
		$this->audit->method_call = $call;
		$this->audit->parameters = json_encode($parameters);
		$this->audit->entity = $entity;
		$this->audit->entity_id = $entity_id;
		$this->audit->prior_state = json_encode($prior_state);
		$this->audit->post_state = json_encode($post_state);
		$this->audit->by_whom_id = $mundane_id;
		$this->audit->modified_at = date('Y-m-d H:i:s');
		$this->audit->save();
		// Yapo does not reliably persist entity_id (int column with DEFAULT 0).
		// Patch it directly after insert using the last-inserted PK.
		$eid = (int)$entity_id;
		if ($eid > 0) {
			$pk = (int)$this->audit->{$this->audit->primarykey()};
			if ($pk > 0) {
				$this->db->Execute("UPDATE " . DB_PREFIX . "danger_audit SET entity_id = $eid WHERE danger_audit_id = $pk");
			}
		}
	}
	
}