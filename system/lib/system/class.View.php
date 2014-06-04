<?php

class View {

	var $__template;
	var $__controller;
	var $__request;
	var $__action;
	var $__settings;
	var $__session;

	function __construct($template, $controller, $request=null, $action=null) {
		$this->__template = $template;
		$this->__controller = $controller;
		$this->__request = $request;
		$this->__action = $action;
		
		global $Settings, $Session;
		$this->__settings = $Settings;
		$this->__session = $Session;
		logtrace('View',$this);
	}
	
	function view($data, $__kingdom=null) {
		/**********************************************
		 * Language
		 * .../language/<theme>/<language>/<controller>.tpl
		 * .../language/<theme>/<language>/<controller>_<request>.tpl
		 * .../language/<theme>/<language>/<controller>_<request>_<action>.tpl
		 *
		 *********************************************/
		$_ = array();
		if (file_exists(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'.lang')) {
			include_once(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'.lang');
			logtrace('View: Language',DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'.lang');
			if (!is_null($this->__request) && strlen($this->__request) > 0 && file_exists(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'.lang')) {
				include_once(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'.lang');
				logtrace('View: Language',DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'.lang');
				if (!is_null($this->__action) && strlen($this->__action) > 0 && file_exists(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.lang')) {
					include_once(DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.lang');
					logtrace('View: Language',DIR_LANGUAGE.$this->__settings->theme.'/'.$this->__settings->language.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.lang');
				}
			}
		}
		extract($_);
		
		/**********************************************
		 * Request/Action View
		 * .../template/<theme>/<kingdom>/<template>
		 * .../template/<theme>/<template>
		 * .../template/<theme>/<kingdom>/<controller>_<request>_<action>
		 * .../template/<theme>/<kingdom>/<controller>_<request>
		 * .../template/<theme>/<kingdom>/<controller>
		 * .../template/<theme>/<controller>_<request>_<action>
		 * .../template/<theme>/<controller>_<request>
		 * .../template/<theme>/default.tpl
		 *
		 *********************************************/
		 
		extract($data);
		ob_start();
			if (!is_null($this->__template) && strlen($this->__template) > 0 && !is_null($__kingdom) && file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__template)) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__template;
			} else if (!is_null($this->__template) && strlen($this->__template) > 0 && file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__template)) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__template;
			} else if (!is_null($__kingdom) && strlen($__kingdom) > 0 && !is_null($__kingdom) && file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.tpl')) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.tpl';
			} else if (!is_null($__kingdom) && strlen($__kingdom) > 0 && !is_null($__kingdom) && file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'_'.$this->__request.'.tpl')) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'_'.$this->__request.'.tpl';
			} else if (file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.tpl')) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'_'.$this->__request.'_'.$this->__action.'.tpl';
			} else if (file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'_'.$this->__request.'.tpl')) {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'_'.$this->__request.'.tpl';
			} else {
				$template = DIR_TEMPLATE.$this->__settings->theme.'/default.tpl';
			}
		include_once($template);
		logtrace('View: Template',$template);
		$TEMPLATE_CONTENTS = ob_get_contents();
		ob_end_clean();
				
		/**********************************************
		 * Controller-level theme
		 * .../template/<theme>/<kingdom>/<controller>
		 * .../template/<theme>/<controller>
		 * .../template/<theme>/Controller.tpl
		 *
		 *********************************************/
		ob_start();
			if (file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'.tpl') && !is_null($__kingdom)) {
				$controller_template = DIR_TEMPLATE.$this->__settings->theme.'/'.$__kingdom.'/'.$this->__controller.'.tpl';
			} else if (file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'.tpl')) {
				$controller_template = DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__controller.'.tpl';
			} else {
				$controller_template = DIR_TEMPLATE.$this->__settings->theme.'/Controller.tpl';
			}
		require_once($controller_template);
		logtrace('View: Controller Template',$controller_template);
		$CONTROLLER_CONTENTS .= ob_get_contents();
		ob_end_clean();
		
		/**********************************************
		 * Application-level theme
		 * .../template/<theme>/<theme_template>
		 * Bail out ...
		 *
		 *********************************************/
		ob_start();
			if (!is_null($this->__settings->theme_template) && strlen($this->__settings->theme_template) > 0 && file_exists(DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__settings->theme_template.'.theme')) {
				$theme = DIR_TEMPLATE.$this->__settings->theme.'/'.$this->__settings->theme_template.'.theme';
				logtrace('View: Theme, Include Theme',$theme);
				include_once($theme);
			} else {
				logtrace('View: Theme, No Theme',$theme);
				$THEME_CONTENTS = $CONTROLLER_CONTENTS;
			}
		logtrace('View: Theme',$theme);
		$THEME_CONTENTS .= ob_get_contents();
		ob_end_clean();
		
		return $THEME_CONTENTS;
	}
}

?>