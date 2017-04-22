<?php

class YapoAction {

	var $Core;
	function __construct(& $Core) {
		$this->Core = & $Core;
	}

	function GenerateSql($params) {
		if (is_array($params))
			extract($params);
	}
}

?>