<?php

/***
 * Model_OfficerPosition
 *
 * Thin passthrough to the OfficerPosition DB layer (system/lib/ork3/class.OfficerPosition.php).
 * The base Model constructor auto-wires $this->OfficerPosition = new APIModel('OfficerPosition'),
 * and Model::__call() forwards any unhandled method to it — so this model is a pure
 * passthrough per the architecture-layers rule (no DB logic here; presentation
 * transforms only if/when a controller needs reshaped data).
 ***/

class Model_OfficerPosition extends Model {

	function __construct() {
		parent::__construct();
	}

}

?>
