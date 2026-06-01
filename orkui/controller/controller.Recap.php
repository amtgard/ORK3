<?php

/**
 *   /Recap                         → page for the most recently completed week
 *   /Recap/index/YYYY-MM-DD        → page for the week starting on that Monday
 *   /Recap/json                    → JSON payload for the most recently completed week
 *   /Recap/json/YYYY-MM-DD         → JSON payload for that week
 *
 * Public — no login required. Data is read from ork_weekly_recap, written by
 * bin/compute-weekly-recap.php each Monday morning.
 */

class Controller_Recap extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
		$this->load_model('Recap');

		// Login required for both the page and the JSON endpoint. For json,
		// respond with a 401 + JSON body instead of an HTML redirect so callers
		// (e.g. the planned WebXR dashboard) can detect auth failure cleanly.
		if (!isset($this->session->user_id)) {
			if ($this->method === 'json') {
				header('Content-Type: application/json');
				http_response_code(401);
				echo json_encode(array('error' => 'login_required'));
				exit;
			}
			header('Location: ' . UIR . 'Login');
			exit;
		}
	}

	public function index($week_start = null) {
		$week_start = $this->_normalize_week_start($week_start);
		$recap      = $this->Recap->get($week_start);
		$weeks      = $this->Recap->recent_weeks(60);

		// Prev/next are the immediate neighbours in the available-weeks list.
		$idx       = array_search($week_start, $weeks, true);
		$prev_week = ($idx !== false && isset($weeks[$idx + 1])) ? $weeks[$idx + 1] : null;
		$next_week = ($idx !== false && $idx > 0)               ? $weeks[$idx - 1] : null;

		// Prior-week payload is loaded purely so the template can render WoW
		// deltas on PlatformStats. One indexed PK read; cheap.
		$prev_recap = $prev_week ? $this->Recap->get($prev_week) : null;

		$this->data['page_title']   = 'Amtgard Week in Review — Week of ' . ($recap['WeekStart'] ?? $week_start);
		$this->data['recap']        = $recap;
		$this->data['week_start']   = $week_start;
		$this->data['prev_week']    = $prev_week;
		$this->data['next_week']    = $next_week;
		$this->data['recent_weeks'] = $weeks;
		$this->data['prev_recap']   = $prev_recap;
	}

	public function json($week_start = null) {
		$week_start = $this->_normalize_week_start($week_start);
		$recap      = $this->Recap->get($week_start);
		header('Content-Type: application/json');
		header('Cache-Control: public, max-age=300');
		echo json_encode($recap ?? array('WeekStart' => $week_start, 'Status' => 'not_computed'));
		exit;
	}

	// Validate Y-m-d or default to last full week's Monday.
	private function _normalize_week_start($week_start) {
		if (!empty($week_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
			return $week_start;
		}
		return date('Y-m-d', strtotime('-7 days', strtotime('monday this week')));
	}
}
