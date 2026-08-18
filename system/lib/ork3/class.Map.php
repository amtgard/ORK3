<?php

class Map extends Ork3 {

	public function __construct() {
		parent::__construct();
        $this->park = new yapo($this->db, DB_PREFIX . 'park');
	}
	
  public function Geocode($request) {
    return $this->_geocode_thunk(Common::Geocode( $request['address'], $request['$city'], $request['$state'], $request['$postal_code'] ));
    
  }
  
  private function _geocode_thunk($geocode) {
 		$geocode[ 'Geocode' ] = json_decode( $geocode[ 'Geocode' ] );
    $geocode[ 'Location' ] = json_decode( $geocode[ 'Location' ] );
    return $geocode;
  }
  
	public function GetParkLocations($request) {
				$key = Ork3::$Lib->ghettocache->key($request); 
				if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false)
					return $cache;
		
        $this->park->clear();
        $this->park->active = 'Active';
        $locations = array();
        if (valid_id($request['KingdomId'])) {
            $this->park->kingdom_id = $request['KingdomId'];
        }
        $kingdoms = Ork3::$Lib->kingdom->GetKingdoms(array());
        if ($this->park->find()) do {
            $locations[] = array(
                    'Location' => $this->park->location,
                    'ParkId' => $this->park->park_id,
                    'Directions' => $this->park->directions,
                    'Description' => $this->park->description,
                    'HasHeraldry' => $this->park->has_heraldry,
                    'Name' => $this->park->name,
                    'KingdomId' => $this->park->kingdom_id,
                    'KingdomName' => $kingdoms['Kingdoms'][$this->park->kingdom_id]['KingdomName'],
                    'KingdomColor' => $kingdoms['Kingdoms'][$this->park->kingdom_id]['KingdomColor'],
                    'ParentKingdomId' => (int)($kingdoms['Kingdoms'][$this->park->kingdom_id]['ParentKingdomId'] ?? 0),
                    'City' => $this->park->city,
                    'Province' => $this->park->province,
                );
        } while ($this->park->next());
				return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, array('Parks'=>$locations));
	}

    /**
     * Atlas-wide (all kingdoms) per-park heatmap weights over the trailing 12 months.
     *   p = distinct active players who signed in AT the park (participation)
     *   r = distinct active players who call the park home and signed in anywhere (residents)
     *
     * @return array<int, array{p: int, r: int}>
     */
    public function GetAtlasHeatmapWeights()
    {
        $participation = array();
        $this->db->Clear();
        $pResult = $this->db->DataSet(
            'SELECT a.park_id, COUNT(DISTINCT a.mundane_id) AS cnt
			 FROM ' . DB_PREFIX . 'attendance a
			 INNER JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id AND m.suspended = 0 AND m.active = 1
			 WHERE a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) AND a.mundane_id > 0
			 GROUP BY a.park_id'
        );
        if ($pResult) {
            while ($pResult->Next()) {
                $participation[(int)$pResult->park_id] = (int)$pResult->cnt;
            }
        }

        $residents = array();
        $this->db->Clear();
        $rResult = $this->db->DataSet(
            'SELECT m.park_id, COUNT(DISTINCT m.mundane_id) AS cnt
			 FROM ' . DB_PREFIX . 'mundane m
			 INNER JOIN ' . DB_PREFIX . 'attendance a ON a.mundane_id = m.mundane_id
			     AND a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH)
			 WHERE m.suspended = 0 AND m.active = 1 AND m.mundane_id > 0
			 GROUP BY m.park_id'
        );
        if ($rResult) {
            while ($rResult->Next()) {
                $residents[(int)$rResult->park_id] = (int)$rResult->cnt;
            }
        }

        $allIds = array_unique(array_merge(array_keys($participation), array_keys($residents)));
        $weights = array();
        foreach ($allIds as $pid) {
            $weights[$pid] = array('p' => $participation[$pid] ?? 0, 'r' => $residents[$pid] ?? 0);
        }

        return $weights;
    }
}

?>