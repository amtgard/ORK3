<?php

class SearchService extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
	}
	
	public function Unit($name, $limit=15) {
        $limit = min($limit, 50);
		$unit = new yapo($this->db, DB_PREFIX . 'unit');
		$unit->clear();
		$unit->name = "%$name%";
		$unit->name_term = "like";
		if ($unit->find()) {
			$r = array();
			do {
				$r[] = array(
						'UnitId' => $unit->unit_id,
						'Type' => $unit->type,
						'Name' => $unit->name,
						'HasHeraldry' => $unit->has_heraldry,
						'Url' => $unit->url
					);
			} while ($unit->next() && $limit --> 0);
			return $r;
		}
		return array();
	}
	
	public function PlayerAward($awards_id) {
		$award = Ork3::$Lib->player->AwardsForPlayer(array( 'MundaneId' => 0, 'AwardsId' => $awards_id ));
		if ($award['Status']['Status'] == 0) {
			return $award['Awards'][0];
		} else {
			return null;
		}
	}
	
	public function Location($name, $date) {
		$limit = max(0, min($limit, 20));
		$sql = "(select 
						k.name as kingdom_name, e.kingdom_id, 
						p.name as park_name, e.park_id, 
						e.name as event_name, e.event_id, 
						cd.event_calendardetail_id 
					from " . DB_PREFIX . "event e
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = e.kingdom_id
						left join " . DB_PREFIX . "park p on p.park_id = e.park_id
						left join " . DB_PREFIX . "mundane m on m.mundane_id = e.mundane_id
						left join " . DB_PREFIX . "event_calendardetail cd on e.event_id = cd.event_id
					where e.name like '%" . mysql_real_escape_string($name) . "%' and date(cd.event_start) <= date('" . mysql_real_escape_string($date) . "') and date(cd.event_end) >= date('" . mysql_real_escape_string($date) . "') limit 4)
				union
				(select 
						k.name as kingdom_name, k.kingdom_id, 
						'' as park_name, 0 as park_id, 
						'' as event_name, 0 as event_id,
						0 as event_calendardetail_id
					from " . DB_PREFIX . "kingdom k
					where k.name like '%" . mysql_real_escape_string($name) . "%' limit 4)
				union
				(select 
						k.name as kingdom_name, p.kingdom_id, 
						p.name as park_name, p.park_id, 
						'' as event_name, 0 as event_id,
						0 as event_calendardetail_id
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "kingdom k on p.kingdom_id = k.kingdom_id
					where p.name like '%" . mysql_real_escape_string($name) . "%' limit 4)
				";
		$d = $this->db->query($sql);
		if ($d !== false && !$d->isEmpty()) {
			$r = array();
			do {
				$r[] = array(
						'KingdomId' => $d->kingdom_id,
						'KingdomName' => $d->kingdom_name,
						'ParkId' => $d->park_id,
						'ParkName' => $d->park_name,
						'EventId' => $d->event_id,
						'EventName' => $d->event_name,
						'EventCalendarDetailId' => $d->event_calendardetail_id,
						'LocationName' => (($d->kingdom_id > 0)?"{$d->kingdom_name}":"") . (($d->park_id > 0)?": {$d->park_name}":"") . (($d->event_id > 0)?": {$d->event_name}":""),
						'ShortName' => ($d->event_id > 0?$d->event_name:($d->park_id > 0?$d->park_name:$d->kingdom_name)),
						'Type' => ($d->event_id > 0?"Event":($d->park_id > 0?"Park":"Kingdom"))
					);
			} while ($d->next());
		} else {
			return null;
		}
		return $r;
	}
	
	public function CalendarDetail($event_calendardetail_id) {
		$eventdetail = new yapo($this->db, DB_PREFIX . "event_calendardetail");
		$eventdetail->clear();
		$eventdetail->event_calendardetail_id = $event_calendardetail_id;
		if ($eventdetail->find()) {
			$detail = array(
					'EventId' => $eventdetail->event_id,
					'Current' => $eventdetail->current,
					'Price' => $eventdetail->price,
					'EventStart' => $eventdetail->event_start,
					'EventEnd' => $eventdetail->event_end,
					'Description' => str_replace("%B7", "", rawurlencode($eventdetail->description)),
					'Url' => $eventdetail->url,
					'UrlName' => $eventdetail->url_name,
					'Address' => $eventdetail->address,
					'Province' => $eventdetail->province,
					'PostalCode' => $eventdetail->postal_code,
					'City' => $eventdetail->city,
					'Country' => $eventdetail->country,
					'MapUrl' => $eventdetail->map_url,
					'MapUrlName' => $eventdetail->map_url_name
				);
            return $detail;
		} else {
			return null;
		}
	}
	
	public function Event($name = null, $kingdom_id = null, $park_id = null, $mundane_id = null, $unit_id = null, $limit = 10, $event_id = null, $date_order = null, $date_start = null) {
        $limit = min($limit, 50);
		$sql = "select e.*, k.name as kingdom_name, p.name as park_name, m.persona, cd.event_start, u.name as unit_name, substring(cd.description, 1, 100) as short_description
					from " . DB_PREFIX . "event e
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = e.kingdom_id
						left join " . DB_PREFIX . "park p on p.park_id = e.park_id
						left join " . DB_PREFIX . "mundane m on m.mundane_id = e.mundane_id
						left join " . DB_PREFIX . "event_calendardetail cd on e.event_id = cd.event_id
						left join " . DB_PREFIX . "unit u on e.unit_id = u.unit_id
				where ";
	
		$sql .= " e.name like '%" . mysql_real_escape_string($name) . "%' " ;
		if (valid_id($kingdom_id)) $sql .= " and e.kingdom_id = $kingdom_id ";
		if (valid_id($park_id)) $sql .= " and e.park_id = $park_id ";
		if (valid_id($mundane_id)) $sql .= " and e.mundane_id = $mundane_id ";
		if (valid_id($unit_id)) $sql .= " and e.unit_id = $unit_id ";
		if (valid_id($event_id)) $sql .= " and e.event_id = $event_id ";
		if ($date_order != null) {
			$when = "date_add(now(), interval - 7 day)";
			if (!is_null($date_start) && strtotime($date_start))
				$when = date("Y-m-d", strtotime($date_start));
			$sql .= " and cd.event_start is not null and cd.event_start > $when order by cd.event_start, kingdom_name, park_name, e.name";
		} else {
			$sql .= " order by kingdom_name, park_name, e.name";
		}
		$d = $this->db->query($sql);
		$i = 0;
		$r = array();
		if ($d !== false && !$d->isEmpty()) {
			do {
				$r[$i++] = array(
						'EventId' => $d->event_id,
						'Name' => $d->name,
						'KingdomName' => $d->kingdom_name,
						'ParkName' => $d->park_name,
						'Persona' => $d->persona,
						'UnitName' => $d->unit_name,
						'NextDate' => $d->event_start,
						'ShortDescription' => $d->short_description
					);
				if (!is_null($limit)) {
    				$limit--;
					if ($limit == 0) break;
				}
			} while ($d->next());
		}
		return $r;
	}
	
	public function Kingdom($name, $limit = null) {
		$kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
		$kingdom->clear();
		$kingdom->name = "%$name%";
		$kingdom->name_term = 'like';
		$i = 0;
		if ($kingdom->find(array('name'))) {
			$r = array();
			do {
				$r[$i++] = array(
						'KingdomId' => $kingdom->kingdom_id,
						'Name' => $kingdom->name
					);
				if (!is_null($limit)) {
					if ($limit == 0) break;
					$limit--;
				}
			} while ($kingdom->next());
			return $r;
		} else {
			return array();
		}
	}
	
	public function Park($name, $kingdom_id = null, $limit = null) {
		$park = new yapo($this->db, DB_PREFIX . 'park');
		$park->clear();
		$park->name = "%$name%";
		$park->name_term = 'like';
		if(is_numeric($kingdom_id)) $park->kingdom_id = $kingdom_id;
		$i = 0;
		if ($park->find(array('name'))) {
			$r = array();
			do {
				$r[$i++] = array(
						'ParkId' => $park->park_id,
						'KingdomId' => $park->kingdom_id,
						'Name' => $park->name,
						'Active' => $park->active
					);
				if (is_numeric($limit)) {
					if ($limit == 0) break;
					$limit--;
				}
			} while ($park->next());
			return $r;
		} else {
			return array();
		}
	}
	
	public function Player($type, $search, $limit=15, $kingdom_id = null, $park_id = null, $waivered = null, $persona_required = true) {
		$searchtokens = preg_split("/[\s,-]+/", $search);
    	$opt = array("1");
        $limit = min(valid_id($limit)?$limit:15, 50);
		switch (strtoupper($type)) {
			case 'PERSONA': 
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) { return "`persona` like '%" . mysql_real_escape_string($t) . "%'"; }, $searchtokens));
			    	$order = "order by persona,surname,given_name";
                    $opt[] = "length(`persona`) > 0";
				break;
			case 'MUNDANE':
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) { return "`given_name` like '%" . mysql_real_escape_string($t) . "%' or `surname` like '%" . mysql_real_escape_string($t) . "%'"; }, $searchtokens));
				    $order = "order by surname,given_name";
                    $opt[] = "(length(`surname`) > 0 or length(`given_name`) > 0)";
				break;
			case 'USER':
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) { return "`username` like '%" . mysql_real_escape_string($t) . "%'"; }, $searchtokens));
			    	$order = "order by username,surname,given_name";
                    $opt[] = "length(`username`) > 0";
				break;
			default:
				$zztop = implode('* ', $searchtokens) . '*';
				$s = "match(`given_name`, `surname`, `other_name`, `username`, `persona`) against ('" . mysql_real_escape_string($zztop) . "' in boolean mode)";
			break;
		}
        if ($persona_required === true) {
            $opt[] = "length(`persona`) > 0";
        }
		if (is_numeric($kingdom_id) && $kingdom_id > 0) {
			$opt[] = "m.kingdom_id =" . mysql_real_escape_string($kingdom_id);
		}
		if (is_numeric($park_id) && $park_id > 0) {
			$opt[] = "m.park_id =" . mysql_real_escape_string($park_id);
		}
		if (is_numeric($waivered) && $waivered > 0) {
			$opt[] = "waivered =".($waivered?1:0);
		}
		$sql = "select 
						$parameters
						`mundane_id`, `given_name`, `surname`, `other_name`, concat(`given_name`,' ',`surname`) as `mundane`, `username`, `persona`, p.park_id, k.kingdom_id, 
						`restricted`, `waivered`, `company_id`, `penalty_box`, k.name as kingdom_name, p.name as park_name, p.abbreviation as p_abbr, k.abbreviation as k_abbr
					from " . DB_PREFIX . "mundane m
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
						left join " . DB_PREFIX . "park p on p.park_id = m.park_id
					where ($s) and (".implode(' and ', $opt).") $order
					limit $limit";
		$i = 0;
		$q = $this->db->query($sql);
		if ($q !== false && $q->size() > 0) {
			$r = array();
			do {
				$r[$i++] = array(
						'MundaneId' => $q->mundane_id,
						'GivenName' => '',//$q->restricted==1?"Restricted":$q->given_name,
						'Surname' => '',//$q->restricted==1?"Restricted":$q->surname,
						'Mundane' => '',//$q->mundane,
						'UserName' => $q->username,
						'Persona' => $q->persona,
						'Restricted' => $q->restricted,
						'KingdomId' => $q->kingdom_id,
						'ParkId' => $q->park_id,
						'KingdomName' => $q->kingdom_name,
						'ParkName' => $q->park_name,
						'Waivered' => $q->waivered,
						'PenaltyBox' => $q->penalty_box,
						'KAbbr' => $q->k_abbr,
						'PAbbr' => $q->p_abbr
					);
				if (is_numeric($limit)) {
					if ($limit == 0) break;
					$limit--;
				}
			} while ($q->next());
			return $r;
		} else {
			return array();
		}
	}
}

?>