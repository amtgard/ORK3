<?php

class Controller_Atlas extends Controller {


	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->Map = new APIModel('Map');
	}

	private function _canViewHeatmap() {
		$uid = (int)($this->session->user_id ?? 0);
		if ($uid <= 0) return false;
		if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) return true;
		global $DB;
		$DB->Clear();
		$r = $DB->DataSet("SELECT 1 FROM " . DB_PREFIX . "authorization WHERE mundane_id = {$uid} AND kingdom_id > 0 LIMIT 1");
		return $r && $r->Size() > 0;
	}

	public function index($action = null) {
		$this->data['page_title'] = "Amtgard Atlas";
		$this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => null));
		$this->data['ShowHeatmapBtn'] = $this->_canViewHeatmap();
	}

	public function map($kingdom_id = null) {
		$this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => $kingdom_id));
	}

	public function heatmap() {
		if (!$this->_canViewHeatmap()) {
			header('Location: ' . UIR . 'Atlas');
			exit;
		}
		$this->data['page_title'] = "Population Heatmap";
		$this->data['Parks'] = $this->Map->GetParkLocations(array('KingdomId' => null));
		$cacheKey = Ork3::$Lib->ghettocache->key(['heatmap_v1']);
		if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.heatmap', $cacheKey, 1800)) !== false) {
			$this->data['HeatmapWeights'] = $cached;
			return;
		}
		global $DB;
		$DB->Clear();
		$pResult = $DB->DataSet(
			"SELECT a.park_id, COUNT(DISTINCT a.mundane_id) AS cnt
			 FROM ork_attendance a
			 INNER JOIN ork_mundane m ON m.mundane_id = a.mundane_id AND m.suspended = 0 AND m.active = 1
			 WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND a.mundane_id > 0
			 GROUP BY a.park_id"
		);
		$participation = [];
		if ($pResult) {
			while ($pResult->Next()) {
				$participation[(int)$pResult->park_id] = (int)$pResult->cnt;
			}
		}
		$DB->Clear();
		$rResult = $DB->DataSet(
			"SELECT m.park_id, COUNT(DISTINCT m.mundane_id) AS cnt
			 FROM ork_mundane m
			 INNER JOIN ork_attendance a ON a.mundane_id = m.mundane_id
			     AND a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
			 WHERE m.suspended = 0 AND m.active = 1 AND m.mundane_id > 0
			 GROUP BY m.park_id"
		);
		$residents = [];
		if ($rResult) {
			while ($rResult->Next()) {
				$residents[(int)$rResult->park_id] = (int)$rResult->cnt;
			}
		}
		$allIds = array_unique(array_merge(array_keys($participation), array_keys($residents)));
		$weights = [];
		foreach ($allIds as $pid) {
			$weights[$pid] = ['p' => $participation[$pid] ?? 0, 'r' => $residents[$pid] ?? 0];
		}
		Ork3::$Lib->ghettocache->cache(__CLASS__ . '.heatmap', $cacheKey, $weights);
		$this->data['HeatmapWeights'] = $weights;
	}

}
