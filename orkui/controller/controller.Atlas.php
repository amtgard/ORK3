<?php

class Controller_Atlas extends Controller {


	public function __construct($call=null, $method=null) {
		parent::__construct($call, $method);
		$this->load_model('Map');
		$this->load_model('Authorization');
	}

	private function _canViewHeatmap() {
		$uid = (int)($this->session->user_id ?? 0);
		if ($uid <= 0) return false;
		if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) return true;
		return $this->Authorization->has_any_kingdom_authorization($uid);
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
		$weights = $this->Map->heatmap_weights();
		Ork3::$Lib->ghettocache->cache(__CLASS__ . '.heatmap', $cacheKey, $weights);
		$this->data['HeatmapWeights'] = $weights;
	}

}
