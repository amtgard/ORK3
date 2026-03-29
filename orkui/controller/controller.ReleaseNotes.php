<?php

class Controller_ReleaseNotes extends Controller {

	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->data['menu']['releasenotes'] = array('url' => UIR . 'ReleaseNotes', 'display' => 'Release Notes');
		$this->data['no_index'] = true;
	}

	public function index($action = null) {
		require_once(DIR_UI . 'whats_new_content.php');

		$this->template = 'ReleaseNotes_index.tpl';
		$this->data['ork_version'] = ORK_VERSION;
		$this->data['releases'] = [
			[
				'version' => '3.5.0',
				'date' => '2026-03-22',
				'items' => $WHATS_NEW_ITEMS,
			],
			// Future releases: add new entries at the TOP of this array.
			// Move previous $WHATS_NEW_ITEMS here with their version/date,
			// then update $WHATS_NEW_ITEMS with the new release content.
		];
	}

}
