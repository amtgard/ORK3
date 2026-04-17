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

}

?>
