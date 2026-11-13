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
        // Bound the result set in SQL; the loop below emits at most $limit + 1 rows.
        $unit->limit(max((int)$limit, 0) + 1, 0);
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
            if (strlen($name) > 3) {
                return $r;
            }
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
					where e.name like '%" . $this->likeEscape($name) . "%' and date(cd.event_start) <= date('" . $this->likeEscape($date) . "') and date(cd.event_end) >= date('" . $this->likeEscape($date) . "') limit 4)
				union
				(select 
						k.name as kingdom_name, k.kingdom_id, 
						'' as park_name, 0 as park_id, 
						'' as event_name, 0 as event_id,
						0 as event_calendardetail_id
					from " . DB_PREFIX . "kingdom k
					where k.name like '%" . $this->likeEscape($name) . "%' limit 4)
				union
				(select 
						k.name as kingdom_name, p.kingdom_id, 
						p.name as park_name, p.park_id, 
						'' as event_name, 0 as event_id,
						0 as event_calendardetail_id
					from " . DB_PREFIX . "park p
						left join " . DB_PREFIX . "kingdom k on p.kingdom_id = k.kingdom_id
					where p.name like '%" . $this->likeEscape($name) . "%' limit 4)
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
                    'Location' => $eventdetail->location,
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


        $sql .= " e.name like '%" . $this->likeEscape($name) . "%' ";
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
        // Bound the result set in SQL (the fetch loop below stops at $limit rows anyway).
        if (!is_null($limit) && (int)$limit > 0) {
            $sql .= " limit " . (int)$limit;
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

        $key = Ork3::$Lib->ghettocache->key(array(substr($name, 0, 3), $limit));
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
            if (strlen($name) > 3) {
                return $r;
            }
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
        if (strlen($name) != 2) {
            return $r;
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
        // IsAuthorized_h caches the first authorized mundane_id in $_SESSION for the request;
        // clear it so an explicit Token is evaluated for this call (not a prior actor).
        // IsAuthorized/HasAuthority also leave bound parameters on the shared DB handle;
        // clear them so the next raw query in this function doesn't try to bind them.
        if (!empty($token)) {
            unset($_SESSION['is_authorized_mundane_id']);
        }
        $_caps        = $this->viewerCaps($token);
        $is_ork_admin = $_caps['is_admin'];
        // Real-name and username matching is for officers only; everyone else matches
        // persona (and an exact numeric player id) only.
        $is_officer   = $_caps['is_officer'];
        $this->db->clear();
        // Restricted gate fragments for the WHERE clause — empty wrapper for admins so the
        // gate effectively disappears.
        $_rg_open  = $is_ork_admin ? '(' : '(m.restricted = 0 AND (';
        $_rg_close = $is_ork_admin ? ')' : '))';

        $searchtokens = preg_split("/[\s,-]+/", $search ?? '');
        $opt = array("1");
        $limit = min(valid_id($limit) ? $limit : 15, 100);
        $_search_type = strtoupper($type);
        if (!$is_officer && in_array($_search_type, ['MUNDANE', 'USER'], true)) {
            // Real-name / username lookups are officer-only: match nothing.
            $_search_type = 'NONE';
        }
        switch ($_search_type) {
            case 'NONE':
                $s = "0";
                break;
            case 'PERSONA':
                // Every typed word must appear in the persona, in any order.
                $s = $this->nameMatchStrict($this->nameTokens((string)$search), false, false) ?: '0';
                $order = "order by m.active DESC, persona,surname,given_name";
                $opt[] = "length(`persona`) > 0";
                break;
            case 'MUNDANE':
                if (count($searchtokens) > 0) {
                    $s = implode(' or ', array_map(function ($t) use ($_rg_open, $_rg_close) {
                        return $_rg_open . "`given_name` like '%" . $this->likeEscape($t) . "%' or `surname` like '%" . $this->likeEscape($t) . "%'" . $_rg_close;
                    }, $searchtokens));
                }
                $order = "order by m.active DESC, surname,given_name";
                $opt[] = "(length(`surname`) > 0 or length(`given_name`) > 0)";
                break;
            case 'USER':
                if (count($searchtokens) > 0) {
                    $s = implode(' or ', array_map(function ($t) {
                        return "`username` like '%" . $this->likeEscape($t) . "%'";
                    }, $searchtokens));
                }
                $order = "order by m.active DESC, username,surname,given_name";
                $opt[] = "length(`username`) > 0";
                break;
            default:
                $_id_match = ctype_digit((string)$search) ? " or m.`mundane_id` = " . (int)$search : '';
                // Every typed word must appear, in any order (see nameMatchStrict). Persona for
                // everyone; username, other_name and real names for officers only (restricted
                // real names: admins only). Replaces the old whole-phrase LIKE + first-word
                // fulltext MATCH, which missed "Tobias Ard"-style queries.
                $s = "(" . ($this->nameMatchStrict($this->nameTokens((string)$search), $is_officer, $is_ork_admin, ['other_name']) ?: '0') . $_id_match . ")";
                break;
        }
        if ($persona_required === true) {
            $opt[] = "length(`persona`) > 0";
        }
        if (is_numeric($kingdom_id) && $kingdom_id > 0) {
            $opt[] = "m.kingdom_id =" . (int)$kingdom_id;
        }
        if (is_numeric($park_id) && $park_id > 0) {
            $opt[] = "m.park_id =" . (int)$park_id;
        }
        if (is_numeric($waivered) && $waivered > 0) {
            $opt[] = "waivered =".($waivered ? 1 : 0);
        }
        $opt[] = "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))";
        // Banned (suspended / penalty box) players are visible only within the viewer's
        // grant-derived banned scope, exactly as in RankedPlayers.
        if ($_caps['banned_kids'] !== 'all') {
            $opt[] = empty($_caps['banned_kids'])
                ? "(m.suspended = 0 AND m.penalty_box = 0)"
                : "((m.suspended = 0 AND m.penalty_box = 0) OR m.kingdom_id IN (" . implode(',', $_caps['banned_kids']) . "))";
        }
        $order = $order ?? 'order by m.active DESC, persona';
        // Relevance ranking: float exact and prefix persona matches to the top so a short,
        // common token (e.g. "Silent") surfaces its exact match before the row limit truncates
        // the alphabetical tail. Every search type's $order begins with "order by m.active DESC,".
        // Escape for a SQL string literal: backslash FIRST, then the single quote (MariaDB
        // treats backslash as an escape inside literals). The LIKE literal also escapes the
        // wildcards. Spliced with plain string concatenation, never as a regex replacement,
        // so `$n` / `\n` in the search text are not interpreted.
        $_rel      = str_replace(['\\', "'"], ['\\\\', "''"], (string)$search);
        $_rel_like = $this->likeEscape($search);
        $_order_prefix = 'order by m.active DESC,';
        if (stripos($order, $_order_prefix) === 0) {
            $order = "order by m.active DESC, (`persona` = '" . $_rel . "') DESC, (`persona` like '" . $_rel_like . "%') DESC,"
                . substr($order, strlen($_order_prefix));
        }
        $sql = "select 
						`mundane_id`, m.`active`, `given_name`, `surname`, `other_name`, concat(`given_name`,' ',`surname`) as `mundane`, `username`, `persona`, p.park_id, k.kingdom_id, 
						`restricted`, `suspended`, `suspended_at`, `suspended_until`, `waivered`, `company_id`, `penalty_box`, k.name as kingdom_name, p.name as park_name, p.abbreviation as p_abbr, k.abbreviation as k_abbr,
						(m.email IS NOT NULL AND m.email <> '') as has_email
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
                        'UserName' => $is_officer ? $q->username : '',
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
                        'SuspendedAt' => $is_officer ? $q->suspended_at : null,
                        'SuspendedUntil' => $is_officer ? $q->suspended_until : null,
                        // Whether a mailbox exists, never the address itself. The IDP
                        // needs this to avoid offering "Email Code" for a record that
                        // can never receive one -- 74% of active players have no email
                        // on file, and the claim flow returns Success() either way, so
                        // without this they are told it worked and nothing arrives.
                        'HasEmail' => (int)$q->has_email
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

    private function likeEscape($s)
    {
        // Escape backslash FIRST (so the backslashes we add are not re-escaped), then the single
        // quote and the LIKE wildcards. NOTE: mysql_real_escape_string is a no-op in this codebase
        // (startup.php) — never rely on it. This is the canonical literal/LIKE escaper.
        return str_replace(['\\', "'", '%', '_'], ['\\\\', "''", '\\%', '\\_'], (string)$s);
    }

    /* ── Player name matching ──────────────────────────────────────────────────────────────
     * A query is split into words (letters/digits only). A player matches when EVERY word
     * appears somewhere in the name, in any order, so "Tobias Ard", "ard tob" and "mal" all
     * find "Tobias Malcom Ardente". Because words are letters/digits only they can never
     * carry quotes, backslashes or LIKE/REGEXP metacharacters, so they are safe to inline.
     * Persona is matched for everyone; username + real names for officers only, and a
     * restricted player's real name for global admins only (same rule as before).
     *
     * Typo tolerance (fuzzy fallback): each word of 4+ characters may also match with ONE
     * edit — a wrong, missing, extra, or swapped pair of letters (Damerau distance 1) —
     * expressed as LIKE patterns so it reuses the same WHERE/scoping. It only runs when the
     * strict search comes up short, and its rows are ranked below every strict match.
     * ──────────────────────────────────────────────────────────────────────────────────── */

    /** Lower-cased letter/digit words of a search string; deduped, at most 6. */
    private function nameTokens(string $s): array
    {
        $parts = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s, 'UTF-8'), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        return array_slice(array_values(array_unique($parts)), 0, 6);
    }

    /** One LIKE body (already safe) tested against every column this viewer may match on. */
    private function nameColumnsLike(string $body, bool $is_officer, bool $is_admin, array $extraOfficerCols = []): string
    {
        $sql = "m.persona LIKE '{$body}'";
        if ($is_officer) {
            $real = "m.given_name LIKE '{$body}' OR m.surname LIKE '{$body}'";
            $sql .= " OR m.username LIKE '{$body}' OR " . ($is_admin ? $real : "(m.restricted = 0 AND ({$real}))");
            foreach ($extraOfficerCols as $col) {
                $sql .= " OR m.{$col} LIKE '{$body}'";
            }
        }
        return "({$sql})";
    }

    /** Strict match: every word is a substring of some name column. '' when there are no words. */
    private function nameMatchStrict(array $tokens, bool $is_officer, bool $is_admin, array $extraOfficerCols = []): string
    {
        $parts = [];
        foreach ($tokens as $t) {
            $parts[] = $this->nameColumnsLike('%' . $t . '%', $is_officer, $is_admin, $extraOfficerCols);
        }
        return $parts ? '(' . implode(' AND ', $parts) . ')' : '';
    }

    /**
     * Match quality on the persona (lower is better): 0 exact, 1 persona starts with the
     * query, 2 every word starts a word of the persona, 3 anything else (mid-word hit, or a
     * real-name/username hit). Used after tier/ring so the right person surfaces first.
     */
    private function nameRankSql(string $search, array $tokens): string
    {
        $phrase = trim($search);
        $eq     = str_replace(['\\', "'"], ['\\\\', "''"], $phrase);
        $pre    = $this->likeEscape($phrase);
        $starts = [];
        foreach ($tokens as $t) {
            $starts[] = "m.persona REGEXP '(^|[^[:alnum:]])" . $t . "'";
        }
        $allStart = $starts ? implode(' AND ', $starts) : '0';
        return "CASE WHEN m.persona = '{$eq}' THEN 0 WHEN m.persona LIKE '{$pre}%' THEN 1 WHEN {$allStart} THEN 2 ELSE 3 END";
    }

    /** All Damerau-distance-1 variants of a word as LIKE bodies ('_' = any one character). */
    private function typoVariants(string $t): array
    {
        $chars = preg_split('//u', $t, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($chars);
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $sub = $chars;
            $sub[$i] = '_';
            $out[] = implode('', $sub);   // wrong letter
            $del = $chars;
            array_splice($del, $i, 1);
            $out[] = implode('', $del); // extra letter typed
        }
        for ($i = 0; $i <= $n; $i++) {
            $ins = $chars;
            array_splice($ins, $i, 0, ['_']);
            $out[] = implode('', $ins); // letter missed
        }
        for ($i = 0; $i < $n - 1; $i++) {
            $sw = $chars;
            [$sw[$i], $sw[$i + 1]] = [$sw[$i + 1], $sw[$i]];
            $out[] = implode('', $sw); // swapped pair
        }
        // A variant must keep at least 3 real letters, or it matches nearly everything.
        $out = array_filter($out, fn ($v) => mb_strlen(str_replace('_', '', $v)) >= 3 && $v !== $t);
        return array_values(array_unique($out));
    }

    /**
     * Fuzzy match: every word matches strictly OR (words of 4+ chars) within one edit; the
     * strict set is excluded so these rows are only the extra "close matches". '' when no
     * word is long enough to fuzz.
     */
    private function nameMatchFuzzy(array $tokens, bool $is_officer, bool $is_admin, array $extraOfficerCols = []): string
    {
        $parts = [];
        $anyFuzz = false;
        foreach ($tokens as $t) {
            $alts = [$this->nameColumnsLike('%' . $t . '%', $is_officer, $is_admin, $extraOfficerCols)];
            $n = mb_strlen($t);
            if ($n >= 4) {
                $anyFuzz = true;
                // Typos are tolerated on the persona only (real names still match exactly for
                // officers) — 5x fewer patterns. Cheap prefilter first: any one-edit variant
                // leaves the word's first or last floor((n-1)/2) letters intact, so rows with
                // neither half skip the ~30 variant LIKEs (measured 5-10x faster, same rows).
                $h  = intdiv($n - 1, 2);
                $h1 = mb_substr($t, 0, $h);
                $h2 = mb_substr($t, $n - $h);
                $variants = array_map(fn ($v) => "m.persona LIKE '%{$v}%'", $this->typoVariants($t));
                $alts[] = "((m.persona LIKE '%{$h1}%' OR m.persona LIKE '%{$h2}%') AND (" . implode(' OR ', $variants) . "))";
            }
            $parts[] = '(' . implode(' OR ', $alts) . ')';
        }
        if (!$anyFuzz) {
            return '';
        }
        $strict = $this->nameMatchStrict($tokens, $is_officer, $is_admin, $extraOfficerCols);
        // COALESCE: a NULL real-name column makes the strict test NULL (not false), and a bare
        // NOT would then drop the row from the fuzzy set too.
        return '(' . implode(' AND ', $parts) . ' AND COALESCE(' . $strict . ', 0) = 0)';
    }

    /** Optimal-string-alignment (Damerau) distance between two short lower-case strings. */
    private static function osaDistance(string $a, string $b): int
    {
        $a = preg_split('//u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('//u', $b, -1, PREG_SPLIT_NO_EMPTY);
        $n = count($a);
        $m = count($b);
        $d = [];
        for ($i = 0; $i <= $n; $i++) {
            $d[$i][0] = $i;
        }
        for ($j = 0; $j <= $m; $j++) {
            $d[0][$j] = $j;
        }
        for ($i = 1; $i <= $n; $i++) {
            for ($j = 1; $j <= $m; $j++) {
                $cost = ($a[$i - 1] === $b[$j - 1]) ? 0 : 1;
                $d[$i][$j] = min($d[$i - 1][$j] + 1, $d[$i][$j - 1] + 1, $d[$i - 1][$j - 1] + $cost);
                if ($i > 1 && $j > 1 && $a[$i - 1] === $b[$j - 2] && $a[$i - 2] === $b[$j - 1]) {
                    $d[$i][$j] = min($d[$i][$j], $d[$i - 2][$j - 2] + 1);
                }
            }
        }
        return $d[$n][$m];
    }

    /**
     * How close a fuzzy row is to the query (lower is better): for each query word, the
     * smallest distance to any word of the given name texts — comparing against the word's
     * same-length prefix too, so a half-typed word ("tobai") scores as close to "tobias".
     */
    private function typoScore(array $tokens, array $texts): int
    {
        $words = [];
        foreach ($texts as $txt) {
            foreach ($this->nameTokens((string)$txt) as $w) {
                $words[] = $w;
            }
        }
        $total = 0;
        foreach ($tokens as $t) {
            $best = 9;
            $tl = mb_strlen($t);
            foreach ($words as $w) {
                if (mb_strpos($w, $t) !== false) {
                    $best = 0;
                    break;
                }
                $best = min($best, self::osaDistance($t, $w), self::osaDistance($t, mb_substr($w, 0, $tl)) + 1);
            }
            $total += $best;
        }
        return $total;
    }

    /** @var array<string, array{0:int,1:int,2:string}> per-request memo of resolveAbbrevPrefix() */
    private static $abbrevMemo = [];

    private function resolveAbbrevPrefix($q)
    {
        if (isset(self::$abbrevMemo[$q])) {
            return self::$abbrevMemo[$q];
        }
        $filterKid = 0;
        $filterPid = 0;
        $search = $q;
        if (preg_match('/^([a-z0-9]{2,3}):([a-z0-9]{2,3}|\*)?\s+(.+)$/i', $q, $m)) {
            $kAbbr = $this->likeEscape($m[1]);
            $this->db->clear();
            $rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdom WHERE abbreviation = '{$kAbbr}' LIMIT 1");
            if ($rs !== false && $rs->size() > 0) {
                $rs->Next();
                $filterKid = (int)$rs->kingdom_id;
            }
            if ($filterKid > 0 && !empty($m[2]) && $m[2] !== '*') {
                $pAbbr = $this->likeEscape($m[2]);
                $this->db->clear();
                $rs = $this->db->query("SELECT park_id FROM " . DB_PREFIX . "park WHERE abbreviation = '{$pAbbr}' AND kingdom_id = {$filterKid} LIMIT 1");
                if ($rs !== false && $rs->size() > 0) {
                    $rs->Next();
                    $filterPid = (int)$rs->park_id;
                }
            }
            $search = trim($m[3]);
        }
        return self::$abbrevMemo[$q] = [$filterKid, $filterPid, $search];
    }

    /** @var array<string, array> per-request memo of viewerCaps(), keyed by token */
    private static $viewerCapsMemo = [];

    /**
     * The one definition of what a viewer may see in player search, derived ONLY from the
     * session's grants (never from client parameters).
     *   is_officer  — global admin, or a create/edit/admin grant scoped to a PARK or KINGDOM
     *                 (event and unit grants do not count).
     *   banned_kids — 'all' for global admins and kingdom-level officers; for park-only officers
     *                 the union of GetFamilyKingdomIds() of their parks' kingdoms; [] otherwise.
     * Memoised per request, and cached 60s per uid so revoked grants lapse quickly.
     *
     * @return array{uid:int, is_admin:bool, is_officer:bool, banned_kids:string|list<int>}
     */
    private function viewerCaps($token): array
    {
        $none = ['uid' => 0, 'is_admin' => false, 'is_officer' => false, 'banned_kids' => []];
        if (empty($token) || !is_string($token)) {
            return $none;
        }
        if (isset(self::$viewerCapsMemo[$token])) {
            return self::$viewerCapsMemo[$token];
        }
        $uid = (int)Ork3::$Lib->authorization->IsAuthorized($token);
        if ($uid <= 0) {
            $this->db->clear();
            return self::$viewerCapsMemo[$token] = $none;
        }
        $cacheKey = Ork3::$Lib->ghettocache->key([$uid]);
        $cached   = Ork3::$Lib->ghettocache->get(__CLASS__ . '.viewerCaps', $cacheKey, 60);
        if (is_array($cached) && isset($cached['uid']) && (int)$cached['uid'] === $uid) {
            $this->db->clear();
            return self::$viewerCapsMemo[$token] = $cached;
        }

        $caps = ['uid' => $uid, 'is_admin' => false, 'is_officer' => false, 'banned_kids' => []];
        if (Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, null, null)) {
            $caps['is_admin']    = true;
            $caps['is_officer']  = true;
            $caps['banned_kids'] = 'all';
        } else {
            // Park/kingdom grants only. A row with a kingdom_id is a kingdom grant (matching
            // Authorization::DetermineAuthType, where kingdom overrides park).
            $this->db->clear();
            $rs = $this->db->query("SELECT a.park_id, a.kingdom_id, p.kingdom_id AS park_kingdom_id
                FROM " . DB_PREFIX . "authorization a
                LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id
                WHERE a.mundane_id = {$uid}
                  AND a.role IN ('create', 'edit', 'admin')
                  AND a.event_id = 0 AND a.unit_id = 0
                  AND (a.park_id > 0 OR a.kingdom_id > 0)");
            $kids = [];
            $all  = false;
            if ($rs !== false && $rs->size() > 0) {
                while ($rs->Next()) {
                    $caps['is_officer'] = true;
                    if ((int)$rs->kingdom_id > 0) {
                        $all = true;
                        break;
                    }
                    if ((int)$rs->park_kingdom_id > 0) {
                        $kids[(int)$rs->park_kingdom_id] = true;
                    }
                }
            }
            if ($all) {
                $caps['banned_kids'] = 'all';
            } else {
                $fam = [];
                foreach (array_keys($kids) as $kid) {
                    foreach ((array)Ork3::$Lib->kingdom->GetFamilyKingdomIds($kid) as $fk) {
                        if ((int)$fk > 0) {
                            $fam[(int)$fk] = (int)$fk;
                        }
                    }
                }
                $caps['banned_kids'] = array_values($fam);
            }
        }
        $this->db->clear();
        self::$viewerCapsMemo[$token] = $caps;
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.viewerCaps', $cacheKey, $caps);
    }

    /**
     * viewerCaps() for the UI layer (Controller_Search::advanced via Model_Search), so the page
     * flags use the same officer definition as the server. The underscore keeps it off the JSON
     * service's public-method surface.
     *
     * @return array{uid:int, is_admin:bool, is_officer:bool, banned_kids:string|list<int>}
     */
    public function viewer_caps($token): array
    {
        return $this->viewerCaps($token);
    }

    /**
     * The one ranked player search behind every OrkPlayerSearch surface.
     *
     * Canonical call: RankedPlayers(array $opts) where $opts may contain:
     *   q                  (string, required, >=2 chars)
     *   parkId, kingdomId  (int)    ring centre — results rank park(0)->kingdom(1)->elsewhere(2)
     *   restrictTo         ('park'|'kingdom') hard-limit active/inactive results to the centre
     *   restrictKingdomIds (int[])  hard-limit to this set of kingdoms (family/principality)
     *   excludeKingdomId   (int)    omit members of this kingdom (move-INTO-kingdom context)
     *   excludeParkId      (int)    omit members of this park    (move-INTO-park context)
     *   excludeIds         (int[])  omit specific mundane ids    (already-added / opposite merge field)
     *   limit              (int, default 15, clamped 1..101 — 101 leaves room for a caller's
     *                      +1 "has more" probe), offset (int, default 0, capped 2000) — paging
     *   token              (string) session token — gates banned visibility + real-name matching
     *   bannedScope        ('kingdom'|'all') may only NARROW the banned scope; 'all' is honoured
     *                      only for viewers already entitled to Amtgard-wide banned visibility
     *
     * Tiers (always, every surface): 0 active, 1 inactive (last resort), 2 banned. Banned
     * (suspended OR penalty_box) visibility is derived ONLY from the viewer's grants (viewerCaps):
     * global admins and kingdom-level officers see Amtgard-wide, park-only officers see their
     * parks' kingdom families, everyone else sees none. A park surface additionally narrows the
     * banned tier to the park kingdom's family.
     *
     * Legacy positional signature ($q, $parkId, $kingdomId, $restrictTo, $includeInactive,
     * $includeSuspended, $limit, $token, $excludeKingdomId, $excludeParkId, $restrictKingdomIds)
     * is still accepted (SOAP Search/Players + older callers); the include_* flags are ignored
     * because inactive is now always a tier and banned is auth-gated.
     */
    public function RankedPlayers($opts = [], $parkId = null, $kingdomId = null, $restrictTo = null, $includeInactive = null, $includeSuspended = null, $limit = null, $token = null, $excludeKingdomId = null, $excludeParkId = null, $restrictKingdomIds = null)
    {
        if (is_array($opts)) {
            $o = $opts;
        } else {
            $o = [
                'q'                  => $opts,
                'parkId'             => $parkId,
                'kingdomId'          => $kingdomId,
                'restrictTo'         => $restrictTo,
                'limit'              => $limit,
                'token'              => $token,
                'excludeKingdomId'   => $excludeKingdomId,
                'excludeParkId'      => $excludeParkId,
                'restrictKingdomIds' => $restrictKingdomIds,
            ];
        }

        $q = trim((string)($o['q'] ?? ''));
        // A pure-numeric query is a direct database-id lookup (paste the id copied from Advanced
        // Search). Allowed even at length 1 — otherwise require the usual 2-char minimum.
        $numeric_id = ($q !== '' && ctype_digit($q)) ? (int)$q : 0;
        if ($numeric_id <= 0 && strlen($q) < 2) {
            return [];
        }

        $park_id            = (int)($o['parkId']           ?? 0);
        $kingdom_id         = (int)($o['kingdomId']        ?? 0);
        $exclude_kingdom_id = (int)($o['excludeKingdomId'] ?? 0);
        $exclude_park_id    = (int)($o['excludeParkId']    ?? 0);
        $restrict_to        = in_array(($o['restrictTo'] ?? ''), ['park', 'kingdom'], true) ? $o['restrictTo'] : '';
        $limit              = min(max((int)($o['limit'] ?? 15), 1), 101);
        $offset             = min(max((int)($o['offset'] ?? 0), 0), 2000);
        $token              = $o['token'] ?? null;
        $banned_scope_req   = in_array(($o['bannedScope'] ?? ''), ['kingdom', 'all'], true) ? $o['bannedScope'] : '';

        $restrict_kids = [];
        if (!empty($o['restrictKingdomIds']) && is_array($o['restrictKingdomIds'])) {
            foreach ($o['restrictKingdomIds'] as $kid) {
                if ((int)$kid > 0) {
                    $restrict_kids[] = (int)$kid;
                }
            }
        }
        $exclude_ids = [];
        if (!empty($o['excludeIds']) && is_array($o['excludeIds'])) {
            foreach ($o['excludeIds'] as $mid) {
                if ((int)$mid > 0) {
                    $exclude_ids[] = (int)$mid;
                }
            }
        }

        $this->db->clear();
        list($filterKid, $filterPid, $search) = $this->resolveAbbrevPrefix($q);
        $term = $this->likeEscape($search);

        // Derive the ring-centre kingdom from the park whenever a park id is supplied; a
        // mismatching client kingdomId is ignored.
        if ($park_id > 0) {
            $kingdom_id = 0;
            $rs = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = {$park_id} LIMIT 1");
            if ($rs !== false && $rs->size() > 0) {
                $rs->Next();
                $kingdom_id = (int)$rs->kingdom_id;
            }
        }

        // Authorisation comes only from the viewer's grants (see viewerCaps).
        $caps       = $this->viewerCaps($token);
        $is_admin   = $caps['is_admin'];
        $is_officer = $caps['is_officer'];
        $banned_cap = $caps['banned_kids'];   // 'all' | int[]
        if ($banned_scope_req === 'all' && $banned_cap !== 'all') {
            $banned_scope_req = '';
        }

        // The client may only NARROW the banned cap: bannedScope 'kingdom', or a plain park
        // surface (no hard restriction), intersects it with the surface kingdom's family.
        $narrow_to_family = ($banned_scope_req === 'kingdom')
            || ($banned_scope_req === '' && $park_id > 0 && empty($restrict_kids) && $restrict_to === '' && $filterPid === 0 && $filterKid === 0);
        if ($narrow_to_family && $kingdom_id > 0 && ($banned_cap === 'all' || !empty($banned_cap))) {
            $fam = array_map('intval', (array)Ork3::$Lib->kingdom->GetFamilyKingdomIds($kingdom_id));
            $banned_cap = ($banned_cap === 'all') ? $fam : array_values(array_intersect($banned_cap, $fam));
        }
        $can_see_banned     = ($banned_cap === 'all') || !empty($banned_cap);
        $banned_family_kids = ($banned_cap === 'all') ? [] : $banned_cap;

        $tier = "CASE WHEN (m.suspended = 1 OR m.penalty_box = 1) THEN 2 WHEN m.active = 0 THEN 1 ELSE 0 END";
        if ($park_id > 0) {
            $ring = "CASE WHEN m.park_id = {$park_id} THEN 0 WHEN m.kingdom_id = {$kingdom_id} THEN 1 ELSE 2 END";
        } elseif ($kingdom_id > 0) {
            $ring = "CASE WHEN m.kingdom_id = {$kingdom_id} THEN 0 ELSE 1 END";
        } else {
            $ring = "0";
        }

        $where = ["LENGTH(m.persona) > 0"];
        $where[] = "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))";

        // Name match (see nameMatchStrict): every typed word must appear, in any order. Persona
        // for everyone; username + real names for officers (restricted real names: admins only).
        $tokens     = $this->nameTokens($search);
        $name_match = $this->nameMatchStrict($tokens, $is_officer, $is_admin);
        if ($name_match === '') {
            // No letters/digits left (e.g. a bare "KD:PK" prefix): the prefix filter alone scopes it.
            $name_match = ($filterKid > 0 || $filterPid > 0) ? '1' : '0';
        }
        if ($numeric_id > 0) {
            // A digits-only query matches the exact player id (ranked first) OR the usual name
            // match, so numeric personas stay findable. Hard scope/exclude filters still apply.
            $where[] = strlen($q) >= 2
                ? "(m.mundane_id = {$numeric_id} OR {$name_match})"
                : "m.mundane_id = {$numeric_id}";
        } else {
            $where[] = "__NAME__";
        }
        if ($filterPid > 0) {
            $where[] = "m.park_id = {$filterPid}";
        } elseif ($filterKid > 0) {
            $where[] = "m.kingdom_id = {$filterKid}";
        } elseif (!empty($restrict_kids)) {
            $where[] = "m.kingdom_id IN (" . implode(',', $restrict_kids) . ")";
        } elseif ($restrict_to === 'park' && $park_id > 0) {
            $where[] = "m.park_id = {$park_id}";
        } elseif ($restrict_to === 'kingdom' && $kingdom_id > 0) {
            // Kingdom scope ALWAYS includes child principalities (family), matching the documented
            // playersearch rule — so a "move within kingdom" / kingdom-merge surface still finds
            // principality members. (Centralised here so callers just pass restrictTo:'kingdom'.)
            $fam = array_filter(array_map('intval', (array)Ork3::$Lib->kingdom->GetFamilyKingdomIds($kingdom_id)));
            $where[] = "m.kingdom_id IN (" . implode(',', $fam ?: [$kingdom_id]) . ")";
        }
        if ($exclude_kingdom_id > 0) {
            $where[] = "m.kingdom_id != {$exclude_kingdom_id}";
        }
        if ($exclude_park_id > 0) {
            $where[] = "m.park_id != {$exclude_park_id}";
        }
        if (!empty($exclude_ids)) {
            $where[] = "m.mundane_id NOT IN (" . implode(',', $exclude_ids) . ")";
        }

        // Tier visibility: active + inactive always; banned only when authorised, capped to its scope.
        if ($can_see_banned) {
            $banned_ok = "(m.suspended = 1 OR m.penalty_box = 1)";
            if (!empty($banned_family_kids)) {
                $banned_ok .= " AND m.kingdom_id IN (" . implode(',', $banned_family_kids) . ")";
            }
            $where[] = "((m.suspended = 0 AND m.penalty_box = 0) OR ({$banned_ok}))";
        } else {
            $where[] = "(m.suspended = 0 AND m.penalty_box = 0)";
        }

        $match_rank = $tokens ? $this->nameRankSql($search, $tokens) : '3';
        $select = function (string $nameSql, string $order, int $lim, int $off) use ($where, $ring, $tier, $match_rank): string {
            $w = str_replace('__NAME__', $nameSql, implode(' AND ', $where));
            return "SELECT m.mundane_id, m.persona, m.active, m.suspended, m.penalty_box,
                           m.given_name, m.surname, m.username, m.restricted,
                           k.kingdom_id, k.name AS kingdom_name, k.abbreviation AS k_abbr,
                           p.park_id, p.name AS park_name, p.abbreviation AS p_abbr,
                           ({$ring}) AS ring,
                           ({$tier}) AS tier,
                           ({$match_rank}) AS match_rank
                    FROM " . DB_PREFIX . "mundane m
                    LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
                    LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
                    WHERE {$w}
                    ORDER BY {$order}
                    LIMIT {$lim} OFFSET {$off}";
        };
        $shape = function ($rs, int $fuzzy) use ($is_officer, $is_admin): array {
            $banned = ((int)$rs->suspended === 1 || (int)$rs->penalty_box === 1) ? 1 : 0;
            $row = [
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
                'PenaltyBox'  => (int)$rs->penalty_box,
                'Banned'      => $banned,
                'Ring'        => (int)$rs->ring,
                'Tier'        => (int)$rs->tier,
                'Fuzzy'       => $fuzzy,
            ];
            if ($is_officer) {
                // Officer detail line: real name (hidden for restricted players unless admin) + username.
                $show_real = ((int)$rs->restricted !== 1) || $is_admin;
                $row['GivenName']      = $show_real ? (string)$rs->given_name : '';
                $row['Surname']        = $show_real ? (string)$rs->surname : '';
                $row['RealNameHidden'] = $show_real ? 0 : 1;
                $row['UserName']       = (string)$rs->username;
            }
            return $row;
        };

        $order = ($numeric_id > 0 ? "(m.mundane_id = {$numeric_id}) DESC, " : '')
               . "tier ASC, ring ASC, match_rank ASC, m.persona ASC, m.mundane_id ASC";
        $this->db->clear();
        $rs = $this->db->query($select($name_match, $order, $limit, $offset));
        $out = [];
        if ($rs !== false && $rs->size() > 0) {
            while ($rs->Next()) {
                $out[] = $shape($rs, 0);
            }
        }

        // Typo tolerance: when the strict search finds fewer than 5 people, add "close matches"
        // below them. Capped at $limit - 1 in total so it never fakes a "has more" probe row
        // (callers ask for limit+1), and never on later pages, so paging stays stable.
        $room = $limit - 1 - count($out);
        if ($offset === 0 && $numeric_id <= 0 && count($out) < 5 && $room > 0 && !empty($o['fuzzy'] ?? true)) {
            $fuzzy_match = $this->nameMatchFuzzy($tokens, $is_officer, $is_admin);
            if ($fuzzy_match !== '') {
                $this->db->clear();
                $rs = $this->db->query($select($fuzzy_match, "tier ASC, ring ASC, m.persona ASC, m.mundane_id ASC", 200, 0));
                $cands = [];
                if ($rs !== false && $rs->size() > 0) {
                    while ($rs->Next()) {
                        $texts = [$rs->persona];
                        if ($is_officer) {
                            $texts[] = $rs->username;
                            if ((int)$rs->restricted !== 1 || $is_admin) {
                                $texts[] = $rs->given_name;
                                $texts[] = $rs->surname;
                            }
                        }
                        $row = $shape($rs, 1);
                        $row['_score'] = $this->typoScore($tokens, $texts);
                        $cands[] = $row;
                    }
                }
                usort($cands, fn ($a, $b) => [$a['_score'], $a['Tier'], $a['Ring'], strtolower((string)$a['Persona']), $a['MundaneId']]
                                         <=> [$b['_score'], $b['Tier'], $b['Ring'], strtolower((string)$b['Persona']), $b['MundaneId']]);
                foreach (array_slice($cands, 0, $room) as $row) {
                    unset($row['_score']);
                    $out[] = $row;
                }
            }
        }
        return $out;
    }

    /**
     * Rich, filterable player search behind the Advanced Search page. Returns a paged envelope
     * { rows, hasMore, offset, canSeeRealName, canSeeBanned, needFilter } with home + last-attendance
     * columns. Reuses the same tier/auth model as RankedPlayers.
     *
     * $opts: q, kingdomId, parkId, includeActive(bool=true), includeInactive(bool=true),
     *        includeBanned(bool — honoured only for officers), lastAttendanceFrom/To ('YYYY-MM-DD'),
     *        limit(=50, max 200), offset, token.
     * Real names: officers see given/surname; for players flagged restricted, only global admins do.
     * Banned: within the viewer's grant-derived banned scope (viewerCaps). A pure-numeric q is a
     * direct mundane_id lookup.
     * Requires at least one of {q/id, kingdomId, parkId} or returns needFilter (avoids a full scan).
     */
    public function AdvancedPlayers($opts = [])
    {
        $o = is_array($opts) ? $opts : [];
        $q          = trim((string)($o['q'] ?? ''));
        $numeric_id = ($q !== '' && ctype_digit($q)) ? (int)$q : 0;
        $kingdom_id = (int)($o['kingdomId'] ?? 0);
        $park_id    = (int)($o['parkId'] ?? 0);
        $inc_active   = array_key_exists('includeActive', $o) ? !empty($o['includeActive']) : true;
        $inc_inactive = array_key_exists('includeInactive', $o) ? !empty($o['includeInactive']) : true;
        $inc_banned_q = !empty($o['includeBanned']);
        $limit  = min(max((int)($o['limit'] ?? 50), 1), 1000);  // higher cap: page loads one batch into a client DataTable
        $offset = min(max((int)($o['offset'] ?? 0), 0), 2000);
        $token  = $o['token'] ?? null;

        // Authorisation: officer = global admin or a park/kingdom create/edit/admin grant (viewerCaps).
        $caps       = $this->viewerCaps($token);
        $is_admin   = $caps['is_admin'];
        $is_officer = $caps['is_officer'];
        $banned_cap = $caps['banned_kids'];         // 'all' | int[] — grant-derived banned scope
        $can_see_banned   = ($banned_cap === 'all') || !empty($banned_cap);
        $can_see_realname = $is_officer;            // restricted players gated to global admins in output
        $inc_banned = $inc_banned_q && $can_see_banned;

        $meta = [
            'canSeeRealName' => $can_see_realname ? 1 : 0,
            'canSeeBanned'   => $can_see_banned ? 1 : 0,
        ];

        // Require a narrowing filter so we never run the per-row last-attendance subquery over the
        // whole mundane table. (Date range alone is not enough — it can't pre-narrow.)
        if ($numeric_id <= 0 && strlen($q) < 2 && $kingdom_id <= 0 && $park_id <= 0) {
            return array_merge(['rows' => [], 'hasMore' => false, 'offset' => $offset, 'needFilter' => 1], $meta);
        }

        $this->db->clear();
        $where = [
            "LENGTH(m.persona) > 0",
            "(m.kingdom_id != 15 AND (p.kingdom_id IS NULL OR p.kingdom_id != 15))",
        ];

        if ($numeric_id > 0) {
            $where[] = "m.mundane_id = {$numeric_id}";
        } elseif (strlen($q) >= 2) {
            // Every typed word must appear, in any order (see nameMatchStrict). Username +
            // real names for officers only (restricted real names: admins only).
            $tokens = $this->nameTokens($q);
            $where[] = $tokens ? '__NAME__' : '0';
        }

        if ($numeric_id <= 0) {
            if ($park_id > 0) {
                $where[] = "m.park_id = {$park_id}";
            } elseif ($kingdom_id > 0) {
                $fam = array_filter(array_map('intval', (array)Ork3::$Lib->kingdom->GetFamilyKingdomIds($kingdom_id)));
                $where[] = "m.kingdom_id IN (" . implode(',', $fam ?: [$kingdom_id]) . ")";
            }
        }

        // Status tiers from the include toggles.
        $tiers = [];
        if ($inc_active) {
            $tiers[] = "(m.suspended = 0 AND m.penalty_box = 0 AND m.active = 1)";
        }
        if ($inc_inactive) {
            $tiers[] = "(m.suspended = 0 AND m.penalty_box = 0 AND m.active = 0)";
        }
        if ($inc_banned) {
            $tiers[] = ($banned_cap === 'all')
                ? "(m.suspended = 1 OR m.penalty_box = 1)"
                : "((m.suspended = 1 OR m.penalty_box = 1) AND m.kingdom_id IN (" . implode(',', $banned_cap) . "))";
        }
        if (empty($tiers)) {
            return array_merge(['rows' => [], 'hasMore' => false, 'offset' => $offset], $meta);
        }
        $where[] = "(" . implode(' OR ', $tiers) . ")";

        // Last-attendance date range filters the player's MOST RECENT attendance date.
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['lastAttendanceFrom'] ?? ''))) {
            $where[] = "la.date >= '" . $o['lastAttendanceFrom'] . "'";
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($o['lastAttendanceTo'] ?? ''))) {
            $where[] = "la.date <= '" . $o['lastAttendanceTo'] . "'";
        }

        $latest = "(SELECT a.attendance_id FROM " . DB_PREFIX . "attendance a "
                . "WHERE a.mundane_id = m.mundane_id ORDER BY a.date DESC, a.attendance_id DESC LIMIT 1)";
        $tier = "CASE WHEN (m.suspended = 1 OR m.penalty_box = 1) THEN 2 WHEN m.active = 0 THEN 1 ELSE 0 END";

        $tokens     = $tokens ?? [];
        $match_rank = $tokens ? $this->nameRankSql($q, $tokens) : '3';
        $strict     = $tokens ? $this->nameMatchStrict($tokens, $is_officer, $is_admin) : '1';
        $sqlFor = function (string $nameSql, string $order, int $lim, int $off) use ($where, $latest, $tier, $match_rank): string {
            return "SELECT m.mundane_id, m.persona, m.given_name, m.surname, m.restricted,
                       m.active, m.suspended, m.penalty_box,
                       k.kingdom_id, k.name AS kingdom_name, k.abbreviation AS k_abbr,
                       p.park_id, p.name AS park_name, p.abbreviation AS p_abbr,
                       la.date AS last_att_date, lak.name AS last_att_kingdom, lap.name AS last_att_park,
                       ({$tier}) AS tier, ({$match_rank}) AS match_rank
                FROM " . DB_PREFIX . "mundane m
                LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
                LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
                LEFT JOIN " . DB_PREFIX . "attendance la ON la.attendance_id = {$latest}
                LEFT JOIN " . DB_PREFIX . "kingdom lak ON lak.kingdom_id = la.kingdom_id
                LEFT JOIN " . DB_PREFIX . "park lap ON lap.park_id = la.park_id
                WHERE " . str_replace('__NAME__', $nameSql, implode(' AND ', $where)) . "
                ORDER BY {$order}
                LIMIT {$lim} OFFSET {$off}";
        };
        $rows = [];
        $collect = function ($rs, int $fuzzy) use (&$rows, $can_see_realname, $is_admin, $is_officer, $tokens) {
            while ($rs->Next()) {
                $banned     = ((int)$rs->suspended === 1 || (int)$rs->penalty_box === 1) ? 1 : 0;
                $restricted = ((int)$rs->restricted === 1);
                $show_real  = $can_see_realname && (!$restricted || $is_admin);
                $rows[] = [
                    'MundaneId'      => (int)$rs->mundane_id,
                    'Persona'        => $rs->persona,
                    'GivenName'      => $show_real ? $rs->given_name : '',
                    'Surname'        => $show_real ? $rs->surname : '',
                    'RealNameHidden' => ($can_see_realname && $restricted && !$is_admin) ? 1 : 0,
                    'KingdomId'      => (int)$rs->kingdom_id,
                    'KingdomName'    => $rs->kingdom_name,
                    'KAbbr'          => $rs->k_abbr,
                    'ParkId'         => (int)$rs->park_id,
                    'ParkName'       => $rs->park_name,
                    'PAbbr'          => $rs->p_abbr,
                    'LastAttDate'    => $rs->last_att_date,
                    'LastAttKingdom' => $rs->last_att_kingdom,
                    'LastAttPark'    => $rs->last_att_park,
                    'Active'         => (int)$rs->active,
                    'Suspended'      => (int)$rs->suspended,
                    'PenaltyBox'     => (int)$rs->penalty_box,
                    'Banned'         => $banned,
                    'Tier'           => (int)$rs->tier,
                    'Fuzzy'          => $fuzzy,
                ];
                if ($fuzzy) {
                    $texts = [$rs->persona];
                    if ($is_officer && ((int)$rs->restricted !== 1 || $is_admin)) {
                        $texts[] = $rs->given_name;
                        $texts[] = $rs->surname;
                    }
                    $rows[count($rows) - 1]['_score'] = $this->typoScore($tokens, $texts);
                }
            }
        };
        $this->db->clear();
        $rs = $this->db->query($sqlFor($strict, "tier ASC, match_rank ASC, m.persona ASC, m.mundane_id ASC", $limit + 1, $offset));
        if ($rs !== false && $rs->size() > 0) {
            $collect($rs, 0);
        }
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        // Typo tolerance (same rule as RankedPlayers): first page, strict found fewer than 5.
        if (!$hasMore && $offset === 0 && $numeric_id <= 0 && $tokens && count($rows) < 5) {
            $fuzzy = $this->nameMatchFuzzy($tokens, $is_officer, $is_admin);
            if ($fuzzy !== '') {
                $strictCount = count($rows);
                $this->db->clear();
                $rs = $this->db->query($sqlFor($fuzzy, "tier ASC, m.persona ASC, m.mundane_id ASC", 200, 0));
                if ($rs !== false && $rs->size() > 0) {
                    $collect($rs, 1);
                }
                $close = array_slice($rows, $strictCount);
                usort($close, fn ($a, $b) => [$a['_score'], $a['Tier'], strtolower((string)$a['Persona'])]
                                         <=> [$b['_score'], $b['Tier'], strtolower((string)$b['Persona'])]);
                $close = array_map(function ($r) {
                    unset($r['_score']);
                    return $r;
                }, array_slice($close, 0, max(0, $limit - $strictCount)));
                $rows = array_merge(array_slice($rows, 0, $strictCount), $close);
            }
        }
        return array_merge(['rows' => $rows, 'hasMore' => $hasMore, 'offset' => $offset], $meta);
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
        $defaults = ['player' => 4, 'park' => 3, 'kingdom' => 2, 'unit' => 3];
        // Focus is one type ('park') or a comma list ('park,kingdom'). One type gets the full
        // budget of 10; several keep their default budgets. Unlisted types get 0 (not queried).
        $types = array_values(array_intersect(array_map('trim', explode(',', strtolower($focus))), array_keys($defaults)));
        if (empty($types)) {
            return $defaults;
        }
        $out = [];
        foreach ($defaults as $type => $budget) {
            $out[$type] = in_array($type, $types, true) ? (count($types) === 1 ? 10 : $budget) : 0;
        }

        return $out;
    }

    /**
     * @param array{Query?: string, Kid?: int, Pid?: int, IncludeInactive?: bool, Focus?: string, CallerUserId?: int, Token?: string} $request
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
        // RankedPlayers resolves the banned-tier auth gate from a session token, not a user id.
        $token           = $request['Token'] ?? null;

        // Same memoised resolver RankedPlayers uses, so the prefix is looked up once per request.
        list($filterKid, $filterPid, $searchQ) = $this->resolveAbbrevPrefix($q);

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
        $parks = [];
        $rs    = false;
        if ($parkBudget > 0) {
            $this->db->clear();
            $rs = $this->db->query("
				SELECT p.park_id, p.name, k.abbreviation AS k_abbr, k.name AS k_name, k.kingdom_id
				FROM " . DB_PREFIX . "park p
				LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
				WHERE {$parkWhere}
				ORDER BY {$parkOrder}
				LIMIT {$parkBudget}");
        }
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
        // Unused category budget tops up players only when players were requested at all.
        $topUp = ($budgets['player'] > 0);
        if ($topUp) {
            $playerBudget += $parkBudget - count($parks);
        }

        $kingdoms = [];
        if ($filterKid === 0 && $kingdomBudget > 0) {
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
        if ($topUp) {
            $playerBudget += $kingdomBudget - count($kingdoms);
        }

        $unitWhere = "active = 'Active' AND (" . $fold('name') . " LIKE '%{$term}%')";
        $units = [];
        $rs    = false;
        if ($unitBudget > 0) {
            $this->db->clear();
            $rs = $this->db->query("
				SELECT unit_id, name, type
				FROM " . DB_PREFIX . "unit
				WHERE {$unitWhere}
				ORDER BY name
				LIMIT {$unitBudget}");
        }
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
        if ($topUp) {
            $playerBudget += $unitBudget - count($units);
        }

        // Players — delegate to RankedPlayers for the standardized concentric-ring ranking, the
        // active/inactive/banned tier model and the officer-only real-name matching. $q is passed
        // as-is; the "KD:PK term" prefix resolves from the per-request memo. bannedScope 'all' only
        // lifts the park-surface narrowing, and only for viewers whose grants already entitle them
        // to Amtgard-wide banned visibility. Skip the query entirely when there is no budget.
        $players = [];
        if ($playerBudget > 0) {
            $rows = $this->RankedPlayers([
                'q'           => $q,
                'parkId'      => ($pid > 0 ? $pid : null),
                'kingdomId'   => ($kid > 0 ? $kid : null),
                'limit'       => $playerBudget,
                'bannedScope' => 'all',
                'token'       => $token,
            ]);
            foreach ($rows as $row) {
                $players[] = [
                    'type'   => 'player',
                    'id'     => $row['MundaneId'],
                    'name'   => $row['Persona'],
                    'abbr'   => $row['KAbbr'] ?? '',
                    'park'   => $row['ParkName'] ?? '',
                    'active' => $row['Active'],
                    'banned' => $row['Banned'] ?? 0,
                    'fuzzy'  => $row['Fuzzy'] ?? 0,
                    // Officer-only detail (RankedPlayers adds these keys only for officers).
                    'realname' => trim(($row['GivenName'] ?? '') . ' ' . ($row['Surname'] ?? '')),
                    'username' => $row['UserName'] ?? '',
                ];
            }
        }

        return ['players' => $players, 'parks' => $parks, 'kingdoms' => $kingdoms, 'units' => $units];
    }
}
