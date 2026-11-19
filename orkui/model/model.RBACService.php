<?php

/***
 * Model_RBACService
 *
 * Thin passthrough to the RBAC DB layer (system/lib/ork3/class.RBACService.php).
 * The base Model constructor auto-wires $this->RBACService = new APIModel('RBACService'),
 * and Model::__call() forwards any unhandled method to it — so this model is a pure
 * passthrough per the architecture-layers rule (no DB logic here; presentation
 * transforms only if/when a controller needs reshaped data).
 ***/

class Model_RBACService extends Model {

	function __construct() {
		parent::__construct();
	}

}

?>
