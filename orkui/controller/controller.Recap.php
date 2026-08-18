<?php

/**
 *   /Recap                                  → global recap, latest completed week
 *   /Recap/index/YYYY-MM-DD                 → global recap, specific week
 *   /Recap/kingdom/{id}                     → kingdom-scoped recap, latest week
 *   /Recap/kingdom/{id}/YYYY-MM-DD          → kingdom-scoped recap, specific week
 *   /Recap/json                             → JSON: global, latest week
 *   /Recap/json/YYYY-MM-DD                  → JSON: global, specific week
 *   /Recap/json_kingdom/{id}                → JSON: kingdom-scoped, latest week
 *   /Recap/json_kingdom/{id}/YYYY-MM-DD     → JSON: kingdom-scoped, specific week
 *
 * Login required (HTML redirects; JSON returns 401). Global data is read from
 * ork_weekly_recap (written by bin/compute-weekly-recap.php each Monday). The
 * kingdom-scoped view computes lazily and caches via ghettocache, keyed on the
 * global recap's computed_at so a fresh cron run naturally orphans the cache.
 */

class Controller_Recap extends Controller {

	public function __construct($call = null, $method = null) {
		parent::__construct($call, $method);
		$this->load_model('Recap');

		// Login required for both the page and the JSON endpoint. For json,
		// respond with a 401 + JSON body instead of an HTML redirect so callers
		// (e.g. the planned WebXR dashboard) can detect auth failure cleanly.
		if (!isset($this->session->user_id)) {
			if ($this->method === 'json' || $this->method === 'json_kingdom') {
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
		$this->_render_page(0, $week_start);
	}

	public function kingdom($p = null) {
		list($kingdom_id, $week_start) = $this->_parse_kingdom_path($p);
		if ($kingdom_id <= 0) {
			header('Location: ' . UIR . 'Recap');
			exit;
		}
		$this->_render_page($kingdom_id, $week_start);
	}

	public function json($week_start = null) {
		$this->_render_json(0, $week_start);
	}

	public function json_kingdom($p = null) {
		list($kingdom_id, $week_start) = $this->_parse_kingdom_path($p);
		if ($kingdom_id <= 0) {
			header('Content-Type: application/json');
			http_response_code(400);
			echo json_encode(array('error' => 'invalid_kingdom_id'));
			exit;
		}
		$this->_render_json($kingdom_id, $week_start);
	}

	// Shared page render. $kingdom_id = 0 means global view.
	private function _render_page($kingdom_id, $week_start) {
		// Force the template so kingdom() doesn't try to resolve Recap_kingdom.tpl
		// based on the method name.
		$this->template = 'Recap_index.tpl';
		$week_start  = $this->_normalize_week_start($week_start);
		$kingdom_id  = (int)$kingdom_id;
		$recap       = $kingdom_id > 0
			? $this->Recap->get_for_kingdom($kingdom_id, $week_start)
			: $this->Recap->get($week_start);
		$weeks       = $this->Recap->recent_weeks(60);

		$idx       = array_search($week_start, $weeks, true);
		$prev_week = ($idx !== false && isset($weeks[$idx + 1])) ? $weeks[$idx + 1] : null;
		$next_week = ($idx !== false && $idx > 0)               ? $weeks[$idx - 1] : null;

		$prev_recap = null;
		if ($prev_week) {
			$prev_recap = $kingdom_id > 0
				? $this->Recap->get_for_kingdom($kingdom_id, $prev_week)
				: $this->Recap->get($prev_week);
		}

		$kingdom_name = '';
		if ($kingdom_id > 0) {
			$this->load_model('Kingdom');
			$kingdom_name = $this->Kingdom->get_kingdom_name($kingdom_id);
		}

		$this->data['page_title']    = $kingdom_id > 0
			? 'Amtgard Week in Review (' . $kingdom_name . ') — Week of ' . ($recap['WeekStart'] ?? $week_start)
			: 'Amtgard Week in Review — Week of ' . ($recap['WeekStart'] ?? $week_start);
		$this->data['recap']         = $recap;
		$this->data['week_start']    = $week_start;
		$this->data['prev_week']     = $prev_week;
		$this->data['next_week']     = $next_week;
		$this->data['recent_weeks']  = $weeks;
		$this->data['prev_recap']    = $prev_recap;
		$this->data['scope_kingdom_id']   = $kingdom_id;
		$this->data['scope_kingdom_name'] = $kingdom_name;
		$this->data['kingdom_list']  = $this->Recap->kingdom_list();
	}

	// Shared JSON render. $kingdom_id = 0 means global.
	private function _render_json($kingdom_id, $week_start) {
		$week_start = $this->_normalize_week_start($week_start);
		$recap = $kingdom_id > 0
			? $this->Recap->get_for_kingdom($kingdom_id, $week_start)
			: $this->Recap->get($week_start);
		header('Content-Type: application/json');
		header('Cache-Control: public, max-age=300');
		echo json_encode($recap ?? array(
			'WeekStart' => $week_start,
			'KingdomId' => $kingdom_id > 0 ? (int)$kingdom_id : null,
			'Status'    => 'not_computed',
		));
		exit;
	}

	// Parse "{id}" or "{id}/{week_start}" path tail.
	private function _parse_kingdom_path($p) {
		if (empty($p)) return array(0, null);
		$parts = explode('/', $p);
		$kid = (int)preg_replace('/[^0-9]/', '', $parts[0]);
		$ws  = isset($parts[1]) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $parts[1]) ? $parts[1] : null;
		return array($kid, $ws);
	}

	// Validate Y-m-d or default to last full week's Monday.
	private function _normalize_week_start($week_start) {
		if (!empty($week_start) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $week_start)) {
			return $week_start;
		}
		return date('Y-m-d', strtotime('-7 days', strtotime('monday this week')));
	}
}
