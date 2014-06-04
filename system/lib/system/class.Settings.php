<?php

class Settings {
	var $theme = 'default';
	var $theme_template = 'default';
	var $language = 'en';
	var $container_template = 'default.tpl';
	
	function __construct() {
		logtrace('Settings', $this);
	}
}

?>