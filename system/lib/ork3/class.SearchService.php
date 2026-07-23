<?php

class SearchService extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
    }

    public function Unit($name, $limit = 15)
    {
        // Cache only for very short terms — past 3 chars, the truncated key would
        // alias longer searches to the shorter prefix's results.
        $key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $limit));
        if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false) {
            return $cache;
        }

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
            } while ($unit->next() && $limit-- > 0);
            return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
        }
        return array();
    }

    public function PlayerAward($awards_id)
    {
        $award = Ork3::$Lib->player->AwardsForPlayer(array( 'MundaneId' => 0, 'AwardsId' => $awards_id ));
        if ($award['Status']['Status'] == 0) {
            return $award['Awards'][0];
        } else {
            return null;
        }
    }

    public function Location($name, $date)
    {
        $key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $date));
        if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false) {
            return $cache;
        }

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
                        'LocationName' => (($d->kingdom_id > 0) ? "{$d->kingdom_name}" : "") . (($d->park_id > 0) ? ": {$d->park_name}" : "") . (($d->event_id > 0) ? ": {$d->event_name}" : ""),
                        'ShortName' => ($d->event_id > 0 ? $d->event_name : ($d->park_id > 0 ? $d->park_name : $d->kingdom_name)),
                        'Type' => ($d->event_id > 0 ? "Event" : ($d->park_id > 0 ? "Park" : "Kingdom"))
                    );
            }
        } else {
            return null;
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
    }

    public function CalendarDetail($event_calendardetail_id)
    {
        $key = Ork3::$Lib->ghettocache->key(func_get_args());
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 30)) !== false) {
            return $cache;
        }

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
                    'MapUrlName' => $eventdetail->map_url_name,
                'EventType'  => $eventdetail->event_type
                );
            return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $detail);
        } else {
            return null;
        }
    }

    public function Event($name = null, $kingdom_id = null, $park_id = null, $mundane_id = null, $unit_id = null, $limit = 10, $event_id = null, $date_order = null, $date_start = null, $current = 1, $multi = 0, $include_drafts = false)
    {
        // Cache key must reflect the FULL search term — historically this truncated
        // the name to the first 4 chars, so "iron" and "ironclad" collided and the
        // longer search would return the shorter search's results.
        $key = Ork3::$Lib->ghettocache->key(func_get_args());
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }

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

        $sql = "select e.*, IF(e.kingdom_id > 0, k.name, pk.name) as kingdom_name, IF(e.kingdom_id > 0, e.kingdom_id, p.kingdom_id) as resolved_kingdom_id, p.name as park_name, m.persona, cd.event_start, cd.event_calendardetail_id as next_detail_id, u.name as unit_name, substring(cd.description, 1, 100) as short_description, cd.event_type as event_type,
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
        // Filter out draft events by default. Admin callers may opt in via $include_drafts=true.
        if (!$include_drafts) {
            $sql .= " and (e.status is null or e.status = 'published') ";
        }
        if (valid_id($kingdom_id)) {
            $sql .= " and e.kingdom_id = $kingdom_id ";
        }
        if (is_numeric($park_id)) {
            $sql .= " and e.park_id = $park_id ";
        }
        if (valid_id($mundane_id)) {
            $sql .= " and e.mundane_id = $mundane_id ";
        }
        if (valid_id($unit_id)) {
            $sql .= " and e.unit_id = $unit_id ";
        }
        if (valid_id($event_id)) {
            $sql .= " and e.event_id = $event_id ";
        }
        if (!valid_id($event_id)) {
            $sql .= " and cd.event_calendardetail_id is not null ";
        }
        if ($date_order != null) {
            $when = "date_add(now(), interval - 7 day)";
            if (!is_null($date_start) && strtotime($date_start)) {
                $when = "'".date("Y-m-d", strtotime($date_start))."'";
            }
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
                        'HasBanner'      => isset($d->has_banner) ? (int)$d->has_banner : 0,
                        'BannerShowLogo' => isset($d->banner_show_logo) ? (int)$d->banner_show_logo : 1,
                        'BannerVignette' => isset($d->banner_vignette) ? (int)$d->banner_vignette : 1,
                        'BannerOffsetX'  => isset($d->banner_offset_x) ? (int)$d->banner_offset_x : 50,
                        'BannerOffsetY'  => isset($d->banner_offset_y) ? (int)$d->banner_offset_y : 50,
                        'RsvpGoing' => (int)$d->rsvp_going,
                        'RsvpInterested' => (int)$d->rsvp_interested,
                        'RsvpTotal' => (int)$d->rsvp_going + (int)$d->rsvp_interested,
                        'EventType' => $d->event_type ?? ''
                    );
                if (!is_null($limit)) {
                    $limit--;
                    if ($limit == 0) {
                        break;
                    }
                }
            }
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
    }

    public function Kingdom($name, $limit = null)
    {

        $key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $kingdom_id, $limit));
        // Only serve cache for short prefixes to avoid the truncation collision.
        if (strlen($name) <= 3 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false) {
            return $cache;
        }

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
                    if ($limit == 0) {
                        break;
                    }
                    $limit--;
                }
            } while ($kingdom->next());
            return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $r);
        } else {
            return array();
        }
    }

    public function Park($name, $kingdom_id = null, $limit = null, $exclude_kingdom_id = null)
    {

        $key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 2), $kingdom_id, $limit, $exclude_kingdom_id));
        if (strlen($name) == 2 && ($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false) {
            return $cache;
        }

        $safeName = str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $name);
        $lim      = is_numeric($limit) ? (int)$limit : 20;
        $kWhere   = is_numeric($kingdom_id) ? 'AND p.kingdom_id = ' . (int)$kingdom_id : '';
        if (is_numeric($exclude_kingdom_id)) {
            $kWhere .= ' AND p.kingdom_id != ' . (int)$exclude_kingdom_id;
        }
        $sql = "SELECT p.park_id, p.kingdom_id, p.name, p.active,
		               k.name AS kingdom_name
		          FROM " . DB_PREFIX . "park p
		     LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
		         WHERE p.name LIKE '%{$safeName}%' {$kWhere}
		      ORDER BY p.name
		         LIMIT {$lim}";

        $d = $this->db->query($sql);
        if (!$d || !$d->size()) {
            return array();
        }
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
    public function magic_search($term, $kingdom_id, $park_id)
    {
        preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|[\*]{1})?:?\s+(.+)$/i', $term, $matches);

        $k_id = isset($matches[1]) ? Ork3::$Lib->kingdom->GetKingdomByAbbreviation(array('Abbreviation' => $matches[1])) : null;
        $p_id = isset($matches[2]) ? Ork3::$Lib->park->GetParkInKingdomByAbbreviation(array('Abbreviation' => $matches[2]), $k_id) : null;

        $abbrev_match = isset($matches[3]) ? (trimlen($matches[3]) == 0 ? $term : $matches[3]) : $term;

        // If the prefix explicitly redirected to a different kingdom, the
        // caller's park_id no longer makes sense — Felfrost-in-Nine-Blades
        // AND-ed with kingdom=GP returns zero rows. Clear the caller's park
        // scope so the redirect wins cleanly. If the user wanted to keep a
        // park scope they would have written "KK:PP foo".
        $overrode_kingdom = !is_null($k_id);
        $overrode_park    = !is_null($p_id);
        if ($overrode_kingdom && !$overrode_park) {
            $park_id = null;
        }

        return array(
            $abbrev_match,
            $overrode_kingdom ? $k_id : $kingdom_id,
            $overrode_park ? $p_id : $park_id );
    }

    public function Player($type, $search, $limit = 15, $kingdom_id = null, $park_id = null, $waivered = null, $token = null, $persona_required = true)
    {
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
        $limit = min(valid_id($limit) ? $limit : 15, 100);
        switch (strtoupper($type)) {
            case 'PERSONA':
                if (count($searchtokens) > 0) {
                    $s = implode(' or ', array_map(function ($t) {
                        return "`persona` like '%" . mysql_real_escape_string($t) . "%'";
                    }, $searchtokens));
                }
                $order = "order by m.active DESC, persona,surname,given_name";
                $opt[] = "length(`persona`) > 0";
                break;
            case 'MUNDANE':
                if (count($searchtokens) > 0) {
                    $s = implode(' or ', array_map(function ($t) use ($_rg_open, $_rg_close) {
                        return $_rg_open . "`given_name` like '%" . mysql_real_escape_string($t) . "%' or `surname` like '%" . mysql_real_escape_string($t) . "%'" . $_rg_close;
                    }, $searchtokens));
                }
                $order = "order by m.active DESC, surname,given_name";
                $opt[] = "(length(`surname`) > 0 or length(`given_name`) > 0)";
                break;
            case 'USER':
                if (count($searchtokens) > 0) {
                    $s = implode(' or ', array_map(function ($t) {
                        return "`username` like '%" . mysql_real_escape_string($t) . "%'";
                    }, $searchtokens));
                }
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
            $opt[] = "waivered =".($waivered ? 1 : 0);
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
        $order = preg_replace(
            '/^order by m\.active DESC,/i',
            "order by m.active DESC, (`persona` = '{$_rel}') DESC, (`persona` like '{$_rel}%') DESC,",
            $order
        );
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
                    if ($limit == 0) {
                        break;
                    }
                    $limit--;
                }
            }
            return $r;
        } else {
            return array();
        }
    }

    /** @return array<string, string> */
    public static function PunctFolds(): array
    {
        return [
            "\u{2019}" => "'", "\u{2018}" => "'",
            "\u{201C}" => '"', "\u{201D}" => '"',
            "\u{2014}" => '-', "\u{2013}" => '-',
            "\u{00A0}" => ' ', "\u{02DC}" => '~',
        ];
    }

    public static function EscapeLike(string $term): string
    {
        return str_replace(["'", '%', '_', '\\'], ["''", '\\%', '\\_', '\\\\'], $term);
    }

    public function FoldPunctText(string $text): string
    {
        return strtr($text, self::PunctFolds());
    }

    public function SqlFoldColumn(string $col): string
    {
        foreach (self::PunctFolds() as $from => $to) {
            $fromLit = "'" . str_replace("'", "''", $from) . "'";
            $toLit   = "'" . str_replace("'", "''", $to) . "'";
            $col     = "REPLACE({$col}, {$fromLit}, {$toLit})";
        }

        return $col;
    }

    /**
     * @param list<int> $unitIds
     * @return array<int, int>
     */
    public function GetUnitActivityCounts(array $unitIds): array
    {
        $unitIds = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
        if ($unitIds === []) {
            return [];
        }
        $unitIds = array_slice($unitIds, 0, 25);
        $cacheKey = Ork3::$Lib->ghettocache->key($unitIds);
        if (($cached = Ork3::$Lib->ghettocache->get(__CLASS__ . '.GetUnitActivityCounts', $cacheKey, 300)) !== false) {
            return $cached;
        }

        $in      = implode(',', $unitIds);
        $sql     = "SELECT um.unit_id, COUNT(DISTINCT um.mundane_id) AS active_count
				FROM " . DB_PREFIX . "unit_mundane um
				JOIN " . DB_PREFIX . "attendance a ON a.mundane_id = um.mundane_id
				  AND a.date >= DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				WHERE um.unit_id IN ({$in})
				GROUP BY um.unit_id";
        $this->db->clear();
        $d = $this->db->query($sql);
        $out = [];
        if ($d !== false && $d->size() > 0) {
            while ($d->next()) {
                $out[(int)$d->unit_id] = (int)$d->active_count;
            }
        }

        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.GetUnitActivityCounts', $cacheKey, $out);
    }

    /**
     * @return array{player: int, park: int, kingdom: int, unit: int}
     */
    public function UniversalBudgets(string $focus = ''): array
    {
        $focus = trim($focus);

        return [
            'player'  => $focus === 'player' ? 10 : ($focus ? 0 : 4),
            'park'    => $focus === 'park' ? 10 : ($focus ? 0 : 3),
            'kingdom' => $focus === 'kingdom' ? 10 : ($focus ? 0 : 2),
            'unit'    => $focus === 'unit' ? 10 : ($focus ? 0 : 3),
        ];
    }

    /**
     * @param array{Query?: string, Kid?: int, Pid?: int, IncludeInactive?: bool, Focus?: string, CallerUserId?: int} $request
     * @return array{players: list<array<string, mixed>>, parks: list<array<string, mixed>>, kingdoms: list<array<string, mixed>>, units: list<array<string, mixed>>}
     */
    public function UniversalSearch(array $request): array
    {
        $q = trim($request['Query'] ?? '');
        if (strlen($q) < 2) {
            return ['players' => [], 'parks' => [], 'kingdoms' => [], 'units' => []];
        }

        $kid             = (int)($request['Kid'] ?? 0);
        $pid             = (int)($request['Pid'] ?? 0);
        $includeInactive = !empty($request['IncludeInactive']);
        $focus           = trim($request['Focus'] ?? '');
        $callerUserId    = (int)($request['CallerUserId'] ?? 0);

        $filterKid = 0;
        $filterPid = 0;
        $searchQ   = $q;
        if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
            $kAbbr = self::EscapeLike($m[1]);
            $rs    = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
            if ($rs !== false && $rs->size() > 0 && $rs->next()) {
                $filterKid = (int)$rs->kingdom_id;
            }
            if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
                $pAbbr = self::EscapeLike($m[2]);
                $rs    = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
                if ($rs !== false && $rs->size() > 0 && $rs->next()) {
                    $filterPid = (int)$rs->park_id;
                }
            }
            $searchQ = trim($m[3]);
        }

        $searchQ = $this->FoldPunctText($searchQ);
        $term    = self::EscapeLike($searchQ);
        $fold    = fn (string $col): string => $this->SqlFoldColumn($col);

        $budgets       = $this->UniversalBudgets($focus);
        $playerBudget  = $budgets['player'];
        $parkBudget    = $budgets['park'];
        $kingdomBudget = $budgets['kingdom'];
        $unitBudget    = $budgets['unit'];

        $parkWhere = "p.active = 'Active' AND (" . $fold('p.name') . " LIKE '%{$term}%' OR p.abbreviation LIKE '%{$term}%')";
        if ($filterPid > 0) {
            $parkWhere .= " AND p.park_id = {$filterPid}";
        } elseif ($filterKid > 0) {
            $parkWhere .= " AND p.kingdom_id = {$filterKid}";
        }
        $parkOrder = valid_id($pid)
            ? "CASE WHEN p.park_id = {$pid} THEN 0 WHEN p.kingdom_id = {$kid} THEN 1 ELSE 2 END, p.name"
            : (valid_id($kid) ? "CASE WHEN p.kingdom_id = {$kid} THEN 0 ELSE 1 END, p.name" : 'p.name');
        $this->db->clear();
        $rs = $this->db->query("
			SELECT p.park_id, p.name, k.abbreviation AS k_abbr, k.name AS k_name, k.kingdom_id
			FROM " . DB_PREFIX . "park p
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
			WHERE {$parkWhere}
			ORDER BY {$parkOrder}
			LIMIT {$parkBudget}");
        $parks = [];
        if ($rs !== false && $rs->size() > 0) {
            while ($rs->next()) {
                $parks[] = [
                    'type'       => 'park',
                    'id'         => (int)$rs->park_id,
                    'name'       => $rs->name,
                    'abbr'       => $rs->k_abbr ?? '',
                    'kingdom'    => $rs->k_name ?? '',
                    'kingdom_id' => (int)$rs->kingdom_id,
                ];
            }
        }
        $playerBudget += $parkBudget - count($parks);

        $kingdoms = [];
        if ($filterKid === 0) {
            $kingdomWhere = $fold('k.name') . " LIKE '%{$term}%' OR k.abbreviation LIKE '%{$term}%'";
            $this->db->clear();
            $rs = $this->db->query("
				SELECT k.kingdom_id, k.name, k.abbreviation
				FROM " . DB_PREFIX . "kingdom k
				WHERE {$kingdomWhere}
				ORDER BY k.name
				LIMIT {$kingdomBudget}");
            if ($rs !== false && $rs->size() > 0) {
                while ($rs->next()) {
                    $kingdoms[] = [
                        'type' => 'kingdom',
                        'id'   => (int)$rs->kingdom_id,
                        'name' => $rs->name,
                        'abbr' => $rs->abbreviation ?? '',
                    ];
                }
            }
        }
        $playerBudget += $kingdomBudget - count($kingdoms);

        $unitWhere = "active = 'Active' AND (" . $fold('name') . " LIKE '%{$term}%')";
        $this->db->clear();
        $rs = $this->db->query("
			SELECT unit_id, name, type
			FROM " . DB_PREFIX . "unit
			WHERE {$unitWhere}
			ORDER BY name
			LIMIT {$unitBudget}");
        $units = [];
        if ($rs !== false && $rs->size() > 0) {
            while ($rs->next()) {
                $units[] = [
                    'type'     => 'unit',
                    'id'       => (int)$rs->unit_id,
                    'name'     => $rs->name,
                    'unitType' => $rs->type ?? '',
                ];
            }
        }
        $playerBudget += $unitBudget - count($units);

        $activeClause    = $includeInactive ? '1' : 'm.active = 1';
        $suspendedClause = $includeInactive ? '1' : 'm.suspended = 0';
        $isOrkAdmin      = $callerUserId > 0 && Ork3::$Lib->authorization->HasAuthority($callerUserId, AUTH_ADMIN, null, null);
        $this->db->clear();
        $mundaneClause = $isOrkAdmin
            ? 'OR ' . $fold('m.given_name') . " LIKE '%{$term}%' OR " . $fold('m.surname') . " LIKE '%{$term}%'"
            : 'OR (m.restricted = 0 AND (' . $fold('m.given_name') . " LIKE '%{$term}%' OR " . $fold('m.surname') . " LIKE '%{$term}%'))";
        $playerWhere = "{$suspendedClause} AND {$activeClause} AND LENGTH(m.persona) > 0
			  AND (" . $fold('m.persona') . " LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%'
			    {$mundaneClause})";
        if ($filterPid > 0) {
            $playerWhere .= " AND m.park_id = {$filterPid}";
        } elseif ($filterKid > 0) {
            $playerWhere .= " AND m.kingdom_id = {$filterKid}";
        }
        $playerOrder = valid_id($pid)
            ? "m.active DESC, CASE WHEN m.park_id = {$pid} THEN 0 WHEN m.kingdom_id = {$kid} THEN 1 ELSE 2 END, m.persona"
            : (valid_id($kid) ? "m.active DESC, CASE WHEN m.kingdom_id = {$kid} THEN 0 ELSE 1 END, m.persona" : 'm.active DESC, m.persona');
        $this->db->clear();
        $rs = $this->db->query("
			SELECT m.mundane_id, m.persona, m.active, k.abbreviation AS k_abbr, p.name AS park_name
			FROM " . DB_PREFIX . "mundane m
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
			WHERE {$playerWhere}
			ORDER BY {$playerOrder}
			LIMIT {$playerBudget}");
        $players = [];
        if ($rs !== false && $rs->size() > 0) {
            while ($rs->next()) {
                $players[] = [
                    'type'   => 'player',
                    'id'     => (int)$rs->mundane_id,
                    'name'   => $rs->persona,
                    'abbr'   => $rs->k_abbr ?? '',
                    'park'   => $rs->park_name ?? '',
                    'active' => (int)$rs->active,
                ];
            }
        }

        return ['players' => $players, 'parks' => $parks, 'kingdoms' => $kingdoms, 'units' => $units];
    }

    /**
     * @param array{
     *   Query?: string,
     *   Scope?: string,
     *   KingdomId?: int,
     *   ParkId?: int,
     *   EventId?: int,
     *   ScopeParkId?: int,
     *   IncludeInactive?: bool,
     *   IncludeSuspended?: bool,
     *   Prioritize?: bool,
     *   Limit?: int,
     *   Format?: string
     * } $request
     * @return list<array<string, mixed>>
     */
    public function ScopedPlayerSearch(array $request): array
    {
        $q = trim($request['Query'] ?? '');
        if (strlen($q) < 2) {
            return [];
        }

        $scope            = trim($request['Scope'] ?? 'global');
        $kingdomId        = (int)($request['KingdomId'] ?? 0);
        $parkId           = (int)($request['ParkId'] ?? 0);
        $eventId          = (int)($request['EventId'] ?? 0);
        $scopeParkId      = (int)($request['ScopeParkId'] ?? 0);
        $includeInactive  = !empty($request['IncludeInactive']);
        $includeSuspended = !empty($request['IncludeSuspended']);
        $prioritize       = !empty($request['Prioritize']);
        $limit            = min(max((int)($request['Limit'] ?? 15), 1), 100);
        $format           = trim($request['Format'] ?? 'kingdom');

        $filterKid = 0;
        $filterPid = 0;
        $searchQ   = $q;
        if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
            $kAbbr = self::EscapeLike($m[1]);
            $rs    = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
            if ($rs !== false && $rs->size() > 0 && $rs->next()) {
                $filterKid = (int)$rs->kingdom_id;
            }
            if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
                $pAbbr = self::EscapeLike($m[2]);
                $rs    = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
                if ($rs !== false && $rs->size() > 0 && $rs->next()) {
                    $filterPid = (int)$rs->park_id;
                }
            }
            $searchQ = trim($m[3]);
        }
        $term = self::EscapeLike($searchQ);

        $kingdomClause = '';
        $parkClause    = '';
        $orderClause   = 'm.persona';

        if ($filterPid > 0) {
            $parkClause = "AND m.park_id = {$filterPid}";
        } elseif ($filterKid > 0) {
            $kingdomClause = "AND m.kingdom_id = {$filterKid}";
        } elseif ($scope === 'global') {
            $limit = min($limit, 20);
        } elseif ($scope === 'kingdom_exclude') {
            $kingdomClause = "AND m.kingdom_id != {$kingdomId}";
            $parkClause    = valid_id($scopeParkId) ? "AND m.park_id = {$scopeParkId}" : '';
            $orderClause   = "m.suspended ASC, m.active DESC, CASE WHEN m.kingdom_id = {$kingdomId} THEN 0 ELSE 1 END, m.persona";
        } elseif ($scope === 'kingdom_all') {
            $orderClause = "m.suspended ASC, m.active DESC, CASE WHEN m.kingdom_id = {$kingdomId} THEN 0 ELSE 1 END, m.persona";
        } elseif ($scope === 'kingdom_own') {
            $familyIds     = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetFamilyKingdomIds($kingdomId)));
            $kingdomClause = "AND m.kingdom_id IN ({$familyIds})";
            $parkClause    = valid_id($scopeParkId) ? "AND m.park_id = {$scopeParkId}" : '';
            $orderClause   = "m.suspended ASC, m.active DESC, CASE WHEN m.kingdom_id = {$kingdomId} THEN 0 ELSE 1 END, m.persona";
        } elseif ($scope === 'park_own') {
            $parkClause  = "AND m.park_id = {$parkId}";
            $orderClause = $this->parkOrderClause($parkId, $prioritize);
        } elseif ($scope === 'park_exclude') {
            $parkClause  = "AND m.park_id != {$parkId}";
            $orderClause = $this->parkOrderClause($parkId, $prioritize);
        } elseif ($scope === 'park_all') {
            $orderClause = $this->parkOrderClause($parkId, $prioritize);
        } elseif ($scope === 'event_prioritized') {
            $includeInactive  = false;
            $includeSuspended = false;
            $limit            = min($limit, 15);
            $this->db->clear();
            $evRow = $this->db->query('SELECT park_id, kingdom_id FROM ' . DB_PREFIX . "event WHERE event_id = {$eventId} LIMIT 1");
            $evParkId    = 0;
            $evKingdomId = 0;
            if ($evRow !== false && $evRow->size() > 0 && $evRow->next()) {
                $evParkId    = (int)$evRow->park_id;
                $evKingdomId = (int)$evRow->kingdom_id;
            }
            $orderClause = "CASE
			   WHEN m.park_id = {$evParkId} AND {$evParkId} > 0 THEN 0
			   WHEN m.kingdom_id = {$evKingdomId} AND {$evKingdomId} > 0 THEN 1
			   ELSE 2 END, m.persona";
        }

        $suspendedSql = $includeSuspended ? '' : 'AND m.suspended = 0';
        $activeSql    = $includeInactive ? '' : 'AND m.active = 1';

        $sql = "
			SELECT m.mundane_id, m.persona, m.park_id, m.kingdom_id,
			       k.name AS kingdom_name, p.name AS park_name,
			       p.abbreviation AS p_abbr, k.abbreviation AS k_abbr,
			       m.suspended, m.active
			FROM " . DB_PREFIX . "mundane m
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
			LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
			WHERE LENGTH(m.persona) > 0
			  {$suspendedSql}
			  {$activeSql}
			  {$kingdomClause}
			  {$parkClause}
			  AND (m.persona LIKE '%{$term}%'
			    OR m.given_name LIKE '%{$term}%'
			    OR m.surname LIKE '%{$term}%'
			    OR m.username LIKE '%{$term}%')
			ORDER BY {$orderClause}
			LIMIT {$limit}";

        $this->db->clear();
        $d = $this->db->query($sql);
        if ($d === false || $d->size() === 0) {
            return [];
        }

        $results = [];
        while ($d->next()) {
            $results[] = $this->formatScopedPlayerRow($d, $format);
        }

        return $results;
    }

    private function parkOrderClause(int $parkId, bool $prioritize): string
    {
        $order = $prioritize
            ? "CASE WHEN m.park_id = {$parkId} THEN 0 WHEN m.kingdom_id = (SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = {$parkId} LIMIT 1) THEN 1 ELSE 2 END,"
            : '';

        return 'm.suspended ASC, m.active DESC, ' . $order . ' m.persona';
    }

    /**
     * @param object $row
     * @return array<string, mixed>
     */
    private function formatScopedPlayerRow($row, string $format): array
    {
        if ($format === 'admin') {
            return [
                'MundaneId' => (int)$row->mundane_id,
                'Persona'   => $row->persona,
                'PAbbr'     => $row->p_abbr,
                'KAbbr'     => $row->k_abbr,
            ];
        }
        if ($format === 'event') {
            return [
                'MundaneId'   => (int)$row->mundane_id,
                'Persona'     => $row->persona,
                'KingdomId'   => (int)$row->kingdom_id,
                'ParkId'      => (int)$row->park_id,
                'KingdomName' => $row->kingdom_name,
                'ParkName'    => $row->park_name,
                'KAbbr'       => $row->k_abbr,
                'PAbbr'       => $row->p_abbr,
                'Suspended'   => (int)$row->suspended,
            ];
        }

        return [
            'MundaneId'   => (int)$row->mundane_id,
            'Persona'     => $row->persona,
            'KingdomId'   => (int)$row->kingdom_id,
            'ParkId'      => (int)$row->park_id,
            'KingdomName' => $row->kingdom_name,
            'ParkName'    => $row->park_name,
            'KAbbr'       => $row->k_abbr,
            'PAbbr'       => $row->p_abbr,
            'Suspended'   => (int)$row->suspended,
            'Active'      => (int)$row->active,
        ];
    }
}
