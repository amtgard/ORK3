<?php

/**
 * Live attendance dashboard.
 *
 *   /Live              → HTML page (auth-required)
 *   /Live/stats        → JSON: rolling-24h per-park / per-event counts
 *   /Live/recent       → JSON: last ~50 sign-ins for the ticker
 *
 * Both JSON endpoints are session-gated (no auth → 5/Not logged in) so bots
 * can't scrape the aggregated view. Server-side GhettoCache (~30s for stats,
 * ~10s for recent) keeps origin load bounded regardless of viewer count.
 */
class Controller_Live extends Controller {

	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		// Strip standard breadcrumbs — this page is its own thing
		unset($this->data['menu']['kingdom'], $this->data['menu']['park']);
		$this->data['menu']['live'] = array('url' => UIR . 'Live', 'display' => 'Live <i class="fas fa-circle" style="color:#48bb78;font-size:8px;vertical-align:1px;"></i>');
		$this->data['no_index'] = true;
	}

	public function index($action = null) {
		if (!isset($this->session->user_id)) {
			header('Location: ' . UIR . 'Login/login/Live');
			exit;
		}
		$this->template = '../revised-frontend/Live_index.tpl';
		$this->data['page_title'] = 'Live Attendance';
	}

	public function stats() {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(array('status' => 5, 'error' => 'Not logged in'));
			exit;
		}
		$data = Ork3::$Lib->live->stats();
		echo json_encode(array('status' => 0) + $data);
		exit;
	}

	public function recent() {
		header('Content-Type: application/json');
		if (!isset($this->session->user_id)) {
			echo json_encode(array('status' => 5, 'error' => 'Not logged in'));
			exit;
		}
		$data = Ork3::$Lib->live->recent();
		echo json_encode(array('status' => 0) + $data);
		exit;
	}
}
