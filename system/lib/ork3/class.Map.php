<?php

class Map extends Ork3 {

	public function __construct() {
		parent::__construct();
        $this->park = new yapo($this->db, DB_PREFIX . 'park');
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
                    'KingdomColor' => $kingdoms['Kingdoms'][$this->park->kingdom_id]['KingdomColor']
                );
        } while ($this->park->next());
				return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, array('Parks'=>$locations));
	}
}

?>