<?php

class SearchService extends Ork3 {

	public function __construct() {
		parent::__construct();
		$this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
	}
	
	public function Unit($name, $limit=15) {
		// Cache only for very short terms — past 3 chars, the truncated key would
		// alias longer searches to the shorter prefix's results.
		$key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $limit));
		if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false)
			return $cache;
		
   		$limit = min($limit, 50);
		$unit = new yapo($this->db, DB_PREFIX . 'unit');
		$unit->clear();
		$unit->active = 'Active';
		$unit->like('name', "%$name%");
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
			return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
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
		$key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $date)); 
		if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false)
			return $cache;
		
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
		if ($d !== false && $d->size()) {
			$r = array();
			while ($d->next()) {
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
			}
		} else {
			return null;
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
	}
	
	public function CalendarDetail($event_calendardetail_id) {
		$key = Ork3::$Lib->ghettocache->key(func_get_args()); 
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false)
			return $cache;
		
		$eventdetail = new yapo($this->db, DB_PREFIX . "event_calendardetail");
		$eventdetail->clear();
		$eventdetail->event_calendardetail_id = $event_calendardetail_id;
		if ($eventdetail->find()) {
			$detail = array(
					'EventId' => $eventdetail->event_id,
					'AtParkId' => $eventdetail->at_park_id,
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
			return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $detail);
		} else {
			return null;
		}
	}
	
	public function Event($name = null, $kingdom_id = null, $park_id = null, $mundane_id = null, $unit_id = null, $limit = 10, $event_id = null, $date_order = null, $date_start = null, $current = 1, $multi = 0) {
		// Cache key must reflect the FULL search term — historically this truncated
		// the name to the first 4 chars, so "iron" and "ironclad" collided and the
		// longer search would return the shorter search's results.
		$key = Ork3::$Lib->ghettocache->key(func_get_args());
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false)
			return $cache;

		$limit = min($limit, 50);

		// $current=0 → past mode: pick most recent past occurrence per event (regardless of whether upcoming also exists)
		// $current!=0 → pick nearest upcoming occurrence (or most recent past if none upcoming)
		$multiMode = ($multi == 1);

		$pastOnly = ($current === 0 || $current === '0');
		if ($multiMode) {
			$cdJoin = "cd.event_id = e.event_id and cd.event_start < now()";
		} elseif ($pastOnly) {
			$cdJoin = "cd.event_calendardetail_id = (
							select ecd.event_calendardetail_id from " . DB_PREFIX . "event_calendardetail ecd
							where ecd.event_id = e.event_id
							  and (ecd.event_start is null or ecd.event_start < date_sub(now(), interval 7 day))
							order by ecd.event_start desc
							limit 1
						)";
		} else {
			$cdJoin = "cd.event_calendardetail_id = (
							select ecd.event_calendardetail_id from " . DB_PREFIX . "event_calendardetail ecd
							where ecd.event_id = e.event_id
							order by (ecd.event_start >= date_sub(now(), interval 7 day)) desc,
							         if(ecd.event_start >= date_sub(now(), interval 7 day), ecd.event_start, null) asc,
							         ecd.event_start desc
							limit 1
						)";
		}

		$sql = "select e.*, IF(e.kingdom_id > 0, k.name, pk.name) as kingdom_name, IF(e.kingdom_id > 0, e.kingdom_id, p.kingdom_id) as resolved_kingdom_id, p.name as park_name, m.persona, cd.event_start, cd.event_calendardetail_id as next_detail_id, u.name as unit_name, substring(cd.description, 1, 100) as short_description,
					(SELECT COUNT(*) FROM " . DB_PREFIX . "event_rsvp r WHERE r.event_calendardetail_id = cd.event_calendardetail_id AND r.status = 'going') AS rsvp_going,
					(SELECT COUNT(*) FROM " . DB_PREFIX . "event_rsvp r WHERE r.event_calendardetail_id = cd.event_calendardetail_id AND r.status = 'interested') AS rsvp_interested
					from " . DB_PREFIX . "event e
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = e.kingdom_id
						left join " . DB_PREFIX . "park p on p.park_id = e.park_id
						left join " . DB_PREFIX . "mundane m on m.mundane_id = e.mundane_id
						left join " . DB_PREFIX . "kingdom pk on pk.kingdom_id = p.kingdom_id
						left join " . DB_PREFIX . "event_calendardetail cd on " . $cdJoin . "
						left join " . DB_PREFIX . "unit u on e.unit_id = u.unit_id
				where ";


		$sql .= " e.name like '%" . mysql_real_escape_string($name) . "%' ";
		$sql .= " and e.kingdom_id != 15 and (p.kingdom_id is null or p.kingdom_id != 15) ";
		if (valid_id($kingdom_id)) $sql .= " and e.kingdom_id = $kingdom_id ";
		if (is_numeric($park_id)) $sql .= " and e.park_id = $park_id ";
		if (valid_id($mundane_id)) $sql .= " and e.mundane_id = $mundane_id ";
		if (valid_id($unit_id)) $sql .= " and e.unit_id = $unit_id ";
		if (valid_id($event_id)) $sql .= " and e.event_id = $event_id ";
		if (!valid_id($event_id)) $sql .= " and cd.event_calendardetail_id is not null ";
		if ($date_order != null) {
			$when = "date_add(now(), interval - 7 day)";
			if (!is_null($date_start) && strtotime($date_start))
        $when = "'".date("Y-m-d", strtotime($date_start))."'";
			$sql .= " and cd.event_start is not null and cd.event_start > $when order by cd.event_start, COALESCE(k.name, pk.name), p.name, e.name";
		} else {
			$sql .= " order by COALESCE(k.name, pk.name), p.name, e.name";
		}
		$d = $this->db->query($sql);
		$i = 0;
		$r = array();
		if ($d !== false && $d->Size() > 0) {
			while ($d->next()) {
				$r[] = array(
						'EventId' => $d->event_id,
						'Name' => $d->name,
						'KingdomId' => $d->resolved_kingdom_id,
						'KingdomName' => $d->kingdom_name,
						'ParkId' => $d->park_id,
						'ParkName' => $d->park_name,
						'Persona' => $d->persona,
						'UnitName' => $d->unit_name,
						'NextDate' => $d->event_start,
						'NextDetailId' => $d->next_detail_id,
						'ShortDescription' => $d->short_description,
						'HasHeraldry' => $d->has_heraldry,
						'RsvpGoing' => (int)$d->rsvp_going,
						'RsvpInterested' => (int)$d->rsvp_interested,
						'RsvpTotal' => (int)$d->rsvp_going + (int)$d->rsvp_interested
					);
				if (!is_null($limit)) {
    				$limit--;
					if ($limit == 0) break;
				}
			}
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
	}
	
	public function Kingdom($name, $limit = null) {
		
		$key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $kingdom_id, $limit));
		// Only serve cache for short prefixes to avoid the truncation collision.
		if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		$kingdom = new yapo($this->db, DB_PREFIX . 'kingdom');
		$kingdom->clear();
		$kingdom->like('name', "%$name%");
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
			return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
		} else {
			return array();
		}
	}
	
	public function Park($name, $kingdom_id = null, $limit = null, $exclude_kingdom_id = null) {

		$key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 2), $kingdom_id, $limit, $exclude_kingdom_id));
		if (strlen($name) == 2 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		$safeName = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $name);
		$lim      = is_numeric($limit) ? (int)$limit : 20;
		$kWhere   = is_numeric($kingdom_id) ? 'AND p.kingdom_id = ' . (int)$kingdom_id : '';
		if (is_numeric($exclude_kingdom_id)) $kWhere .= ' AND p.kingdom_id != ' . (int)$exclude_kingdom_id;
		$sql = "SELECT p.park_id, p.kingdom_id, p.name, p.active,
		               k.name AS kingdom_name
		          FROM " . DB_PREFIX . "park p
		     LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
		         WHERE p.name LIKE '%{$safeName}%' {$kWhere}
		      ORDER BY p.name
		         LIMIT {$lim}";

		$d = $this->db->query($sql);
		if (!$d || !$d->size()) return array();
		$r = array();
		while ($d->next()) {
			$r[] = array(
				'ParkId'      => (int)$d->park_id,
				'KingdomId'   => (int)$d->kingdom_id,
				'Name'        => $d->name,
				'KingdomName' => $d->kingdom_name,
				'Active'      => $d->active,
			);
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
	}
	public function magic_search($term, $kingdom_id, $park_id) {
		preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|[\*]{1})?:?\s+(.+)$/i', $term, $matches);

		$k_id = isset($matches[1]) ? Ork3::$Lib->kingdom->GetKingdomByAbbreviation(array('Abbreviation'=>$matches[1])) : null;
		$p_id = isset($matches[2]) ? Ork3::$Lib->park->GetParkInKingdomByAbbreviation(array('Abbreviation'=>$matches[2]), $k_id) : null;

		$abbrev_match = isset($matches[3]) ? (trimlen($matches[3])==0?$term:$matches[3]) : $term;

		// If the prefix explicitly redirected to a different kingdom, the
		// caller's park_id no longer makes sense — Felfrost-in-Nine-Blades
		// AND-ed with kingdom=GP returns zero rows. Clear the caller's park
		// scope so the redirect wins cleanly. If the user wanted to keep a
		// park scope they would have written "KK:PP foo".
		$overrode_kingdom = !is_null($k_id);
		$overrode_park    = !is_null($p_id);
		if ($overrode_kingdom && !$overrode_park) $park_id = null;

		return array(
			$abbrev_match,
			$overrode_kingdom ? $k_id : $kingdom_id,
			$overrode_park    ? $p_id : $park_id );
	}
  
	public function Player($type, $search, $limit=15, $kingdom_id = null, $park_id = null, $waivered = null, $token = null, $persona_required = true) {
    	list($search, $kingdom_id, $park_id) = $this->magic_search($search, $kingdom_id, $park_id);

		// ORK admins may search by mundane info regardless of a player's restricted flag.
		// IsAuthorized/HasAuthority run yapo internally which leaves bound parameters on the
		// shared DB handle; clear them so the next raw query in this function doesn't try
		// to bind them.
		$is_ork_admin = false;
		if (!empty($token)) {
			$_caller_uid = Ork3::$Lib->authorization->IsAuthorized($token);
			if ($_caller_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_caller_uid, AUTH_ADMIN, null, null)) {
				$is_ork_admin = true;
			}
		}
		$this->db->clear();
		// Restricted gate fragments for the WHERE clause — empty wrapper for admins so the
		// gate effectively disappears.
		$_rg_open  = $is_ork_admin ? '(' : '(m.restricted = 0 AND (';
		$_rg_close = $is_ork_admin ? ')' : '))';

		$searchtokens = preg_split("/[\s,-]+/", $search ?? '');
    	$opt = array("1");
        $limit = min(valid_id($limit)?$limit:15, 100);
		switch (strtoupper($type)) {
			case 'PERSONA':
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) { return "`persona` like '%" . mysql_real_escape_string($t) . "%'"; }, $searchtokens));
			    	$order = "order by m.active DESC, persona,surname,given_name";
                    $opt[] = "length(`persona`) > 0";
				break;
			case 'MUNDANE':
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) use ($_rg_open, $_rg_close) { return $_rg_open . "`given_name` like '%" . mysql_real_escape_string($t) . "%' or `surname` like '%" . mysql_real_escape_string($t) . "%'" . $_rg_close; }, $searchtokens));
				    $order = "order by m.active DESC, surname,given_name";
                    $opt[] = "(length(`surname`) > 0 or length(`given_name`) > 0)";
				break;
			case 'USER':
				if (count($searchtokens) > 0)
					$s = implode(' or ', array_map(function($t) { return "`username` like '%" . mysql_real_escape_string($t) . "%'"; }, $searchtokens));
			    	$order = "order by m.active DESC, username,surname,given_name";
                    $opt[] = "length(`username`) > 0";
				break;
			default:
				$zztop = $searchtokens[0] . '*';
				// MATCH-AGAINST is the fast path (fulltext index), but it tokenizes
				// on underscores — so e.g. `lord_kismet_shenchu` is one token and
				// `kis*` won't prefix-match it. OR with the LIKE clauses so
				// substring hits succeed even when fulltext misses.
				$s = "(match(`given_name`, `surname`, `other_name`, `username`, `persona`) against ('" . mysql_real_escape_string($zztop) . "' in boolean mode)
				or `username` like '%" . mysql_real_escape_string($search) . "%'
				or `other_name` like '%" . mysql_real_escape_string($search) . "%' or `persona` like '%" . mysql_real_escape_string($search) . "%'
				or " . $_rg_open . "`given_name` like '%" . mysql_real_escape_string($search) . "%' or `surname` like '%" . mysql_real_escape_string($search) . "%' or concat(`given_name`,' ',`surname`) like '%" . mysql_real_escape_string($search) . "%'" . $_rg_close . ")";
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
		$opt[] = "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))";
		$order = $order ?? 'order by m.active DESC, persona';
		// Relevance ranking: float exact and prefix persona matches to the top so a short,
		// common token (e.g. "Silent") surfaces its exact match before the row limit truncates
		// the alphabetical tail. Every search type's $order begins with "order by m.active DESC,".
		// Escape for a SQL string literal by doubling single quotes. NOTE: the file-wide
		// use of mysql_real_escape_string elsewhere is a no-op polyfill (pre-existing,
		// separate issue) — this one new line does its own proper escaping.
		$_rel = str_replace("'", "''", (string)$search);
		$order = preg_replace('/^order by m\.active DESC,/i',
			"order by m.active DESC, (`persona` = '{$_rel}') DESC, (`persona` like '{$_rel}%') DESC,",
			$order);
		$sql = "select 
						`mundane_id`, m.`active`, `given_name`, `surname`, `other_name`, concat(`given_name`,' ',`surname`) as `mundane`, `username`, `persona`, p.park_id, k.kingdom_id, 
						`restricted`, `suspended`, `suspended_at`, `suspended_until`, `waivered`, `company_id`, `penalty_box`, k.name as kingdom_name, p.name as park_name, p.abbreviation as p_abbr, k.abbreviation as k_abbr
					from " . DB_PREFIX . "mundane m
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
						left join " . DB_PREFIX . "park p on p.park_id = m.park_id
					where ($s) and (".implode(' and ', $opt).") $order
					limit $limit";
		$i = 0;
		$this->db->clear();
		$q = $this->db->query($sql);
		if ($q !== false && $q->size() > 0) {
			$r = array();
			while ($q->next()) {
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
						'Active' => (int)$q->active,
						'KAbbr' => $q->k_abbr,
						'PAbbr' => $q->p_abbr,
						'Suspended' => $q->suspended,
						'SuspendedAt' => $q->suspended_at,
						'SuspendedUntil' => $q->suspended_until
					);
				if (is_numeric($limit)) {
					if ($limit == 0) break;
					$limit--;
				}
			}
			return $r;
		} else {
			return array();
		}
	}

	private function resolveAbbrevPrefix($q) {
		$filterKid = 0; $filterPid = 0; $search = $q;
		if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
			$kAbbr = mysql_real_escape_string($m[1]);
			$this->db->clear();
			$rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
			if ($rs !== false && $rs->size() > 0) { $rs->Next(); $filterKid = (int)$rs->kingdom_id; }
			if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
				$pAbbr = mysql_real_escape_string($m[2]);
				$this->db->clear();
				$rs = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
				if ($rs !== false && $rs->size() > 0) { $rs->Next(); $filterPid = (int)$rs->park_id; }
			}
			$search = trim($m[3]);
		}
		return [$filterKid, $filterPid, $search];
	}

	public function RankedPlayers($q, $parkId = null, $kingdomId = null, $restrictTo = null, $includeInactive = null, $includeSuspended = null, $limit = null, $token = null, $excludeKingdomId = null, $excludeParkId = null) {
		$q = trim($q ?? '');
		if (strlen($q) < 2) return [];
		$park_id            = (int)($parkId          ?? 0);
		$kingdom_id         = (int)($kingdomId       ?? 0);
		$exclude_kingdom_id = (int)($excludeKingdomId ?? 0);
		$exclude_park_id    = (int)($excludeParkId    ?? 0);
		$restrict_to        = in_array(($restrictTo ?? ''), ['park','kingdom'], true) ? $restrictTo : '';
		$include_inactive   = !empty($includeInactive);
		$include_suspended  = !empty($includeSuspended);
		$limit              = min(max((int)($limit ?? 15), 1), 100);
		$is_admin = false;
		if (!empty($token)) {
			$uid = Ork3::$Lib->authorization->IsAuthorized($token);
			if ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, null, null)) $is_admin = true;
		}
		$this->db->clear();
		list($filterKid, $filterPid, $search) = $this->resolveAbbrevPrefix($q);
		$term = mysql_real_escape_string($search);
		if ($park_id > 0 && $kingdom_id <= 0) {
			$rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = {$park_id} LIMIT 1");
			if ($rs !== false && $rs->size() > 0) { $rs->Next(); $kingdom_id = (int)$rs->kingdom_id; }
		}
		if ($park_id > 0)        { $ring = "CASE WHEN m.park_id = {$park_id} THEN 0 WHEN m.kingdom_id = {$kingdom_id} THEN 1 ELSE 2 END"; }
		elseif ($kingdom_id > 0) { $ring = "CASE WHEN m.kingdom_id = {$kingdom_id} THEN 0 ELSE 1 END"; }
		else                     { $ring = "0"; }
		$where = ["LENGTH(m.persona) > 0"];
		$where[] = $include_suspended ? "1" : "m.suspended = 0";
		$where[] = $include_inactive  ? "1" : "m.active = 1";
		$where[] = "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))";
		$mundane_clause = $is_admin
			? "OR m.given_name LIKE '%{$term}%' OR m.surname LIKE '%{$term}%'"
			: "OR (m.restricted = 0 AND (m.given_name LIKE '%{$term}%' OR m.surname LIKE '%{$term}%'))";
		$where[] = "(m.persona LIKE '%{$term}%' OR m.username LIKE '%{$term}%' {$mundane_clause})";
		if     ($filterPid > 0)                                { $where[] = "m.park_id = {$filterPid}"; }
		elseif ($filterKid > 0)                                { $where[] = "m.kingdom_id = {$filterKid}"; }
		elseif ($restrict_to === 'park'    && $park_id > 0)    { $where[] = "m.park_id = {$park_id}"; }
		elseif ($restrict_to === 'kingdom' && $kingdom_id > 0) { $where[] = "m.kingdom_id = {$kingdom_id}"; }
		if ($exclude_kingdom_id > 0) { $where[] = "m.kingdom_id != {$exclude_kingdom_id}"; }
		if ($exclude_park_id    > 0) { $where[] = "m.park_id    != {$exclude_park_id}"; }
		$sql = "SELECT m.mundane_id, m.persona, m.active, m.suspended,
		               k.kingdom_id, k.name AS kingdom_name, k.abbreviation AS k_abbr,
		               p.park_id, p.name AS park_name, p.abbreviation AS p_abbr,
		               ({$ring}) AS ring
		        FROM " . DB_PREFIX . "mundane m
		        LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
		        LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
		        WHERE " . implode(' AND ', $where) . "
		        ORDER BY m.suspended ASC, m.active DESC, ring ASC, m.persona ASC
		        LIMIT {$limit}";
		$this->db->clear();
		$rs = $this->db->query($sql);
		$out = [];
		if ($rs !== false && $rs->size() > 0) {
			while ($rs->Next()) {
				$out[] = [
					'MundaneId'   => (int)$rs->mundane_id,
					'Persona'     => $rs->persona,
					'KingdomId'   => (int)$rs->kingdom_id,
					'ParkId'      => (int)$rs->park_id,
					'KAbbr'       => $rs->k_abbr,
					'PAbbr'       => $rs->p_abbr,
					'KingdomName' => $rs->kingdom_name,
					'ParkName'    => $rs->park_name,
					'Active'      => (int)$rs->active,
					'Suspended'   => (int)$rs->suspended,
					'Ring'        => (int)$rs->ring,
				];
			}
		}
		return $out;
	}
}

?>