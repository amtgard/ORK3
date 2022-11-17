<?php

class Pronoun  extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->pronoun = new yapo($this->db, DB_PREFIX . 'pronoun');
	}

	public function GetPronounList() {
		$sql = "select pronoun_id, subject, object, possessive, possessivepronoun, reflexive
					from " . DB_PREFIX . "pronoun p 
					where 1
					order by p.subject asc";
		$r = $this->db->query($sql);

		$response = array();
        $response['Pronouns'] = array();
		if ($r !== false && $r->size() > 0) {
			do {
				$response['Pronouns'][] = array(
					'PronounId' => $r->pronoun_id,
					'Subject' => $r->subject,
					'Object' => $r->object,
					'Possessive' => $r->possessive,
					'PossessivePronoun' => $r->possessivepronoun,
					'Reflexive' => $r->reflexive,
				);
			} while ($r->next());
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter(NULL, 'Problem processing your request.');
		}
		return $response;
	}
}

?>
