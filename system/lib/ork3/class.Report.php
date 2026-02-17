<?php

/*************************************************************************

Here be dragons.

The Report class.

I have no apologies for the following code.  It works well enough.

*************************************************************************/

class Report  extends Ork3 {

	public function __construct() {
		parent::__construct();
	}

	public function HeraldryReport($request) {
		// WithMissingHeraldries [No, Yes, Only]
		$response = array();

		// Unified handling for all heraldry types
		$table = strtolower($request['Type']);
		$$table = new yapo($this->db, DB_PREFIX . $table);
		
		if ($request['WithMissingHeraldries'] == 'No')
			$$table->has_heraldry = 1;
		if ($request['WithMissingHeraldries'] == 'Only')
			$$table->has_heraldry = 0;
		if (valid_id($request['KingdomId'])) {
			$$table->kingdom_id = $request['KingdomId'];
		}
		if (valid_id($request['ParkId'])) {
			$$table->park_id = $request['ParkId'];
		}
		
		if ($$table->find()) {
			$table_id = $table.'_id';
			do {
				if ($request['Type'] == 'Mundane' && $$table->suspended == 1) {
					continue;
				}
				if ($request['Type'] == 'Park' && $$table->active == 'Retired') {
					continue;
				}
				$last_signin = null;
				
				// Calculate LastSignin for Mundane types
				if ($request['Type'] == 'Mundane') {
					$sql = "SELECT MAX(att.date) as last_signin FROM " . DB_PREFIX . "attendance att WHERE att.mundane_id = '" . mysql_real_escape_string($$table->mundane_id) . "'";
					$r = $this->db->query($sql);
					if ($r && $r->size() > 0) {
						$r->next();
						$last_signin = $r->last_signin;
					}
				}
				
				$response[] = array(
					'HasHeraldry' => $$table->has_heraldry,
					'HeraldryUrl' => Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type' => ($request['Type'] == 'Mundane' ? 'Player' : $request['Type']), 'Id' => $$table->$table_id)),
					'Name' => ($request['Type'] == 'Mundane') ? $$table->persona : $$table->name,
					'Url' => UIR . ($request['Type'] == 'Mundane' ? 'Player' : $request['Type']) . '/index/' . $$table->$table_id,
					'LastSignin' => $last_signin
				);
			} while ($$table->next());
		}
		
		return $response;
	}

	public function TournamentReport($request) {

		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 1800)) !== false)
			return $cache;

		if (valid_id($request['KingdomId'])) $where .= " and t.kingdom_id = $request[KingdomId] or e.kingdom_id = $request[KingdomId]";
		if (valid_id($request['ParkId'])) $where .= " and t.park_id = $request[ParkId] or e.park_id = $request[ParkId]";
		if (valid_id($request['EventId'])) $where .= " and e.event_id = $request[EventId]";
		if (valid_id($request['EventCalendarDetailId'])) $where .= " and d.event_calendardetail_id = $request[EventCalendarDetailId]";

		if (valid_id($request['ParticipantMundaneId'])) {
			$where .= " and pm.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'";
		}
		if (valid_id($request['ParticipantUnitId'])) {
			$where .= " and p.unit_id = '" . mysql_real_escape_string($request['UnitId']) . "'";
		}
		if (valid_id($request['ParticipantParkId'])) {
			$where .= " and p.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
		}
		if (valid_id($request['ParticipantKingdomId'])) {
			$where .= " and p.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
		}
		if (valid_id($request['ParticipantTeamId'])) {
			$where .= " and p.team_id = '" . mysql_real_escape_string($request['TeamId']) . "'";
		}
		if (valid_id($request['ParticipantAlias'])) {
			$where .= " and p.alias like '" . mysql_real_escape_string($request['Alias']) . "'";
		}

		if (valid_id($request['Limit'])) $limit = " limit " . mysql_real_escape_string($request['Limit']);

		$sql = "select t.*, k.name as kingdom_name, k.parent_kingdom_id, park.name as park_name, e.name as event_name, d.event_start
					from " . DB_PREFIX . "tournament t
						left join " . DB_PREFIX . "event_calendardetail d on d.event_calendardetail_id = t.event_calendardetail_id
							left join " . DB_PREFIX . "event e on d.event_id = e.event_id
						left join " . DB_PREFIX . "participant p on p.tournament_id = t.tournament_id
							left join " . DB_PREFIX . "participant_mundane pm on pm.participant_id = p.participant_id
						left join " . DB_PREFIX . "kingdom k on t.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "park park on t.park_id = park.park_id
					where
						1 $where
					order by t.date_time
					$limit";

		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false) {
			$response['Tournaments'] = array();
			if ($r->size() > 0) {
				while($r->next()) {
					$response['Tournaments'][] = array(
							'TournamentId' => $r->tournament_id,
							'KingdomId' => $r->kingdom_id,
							'KingdomName' => $r->kingdom_name,
							'ParentKingdomId' => $r->parent_kingdom_id,
							'ParkId' => $r->park_id,
							'ParkName' => $r->park_name,
							'EventCalendarDetailId' => $r->event_calendardetail_id,
							'EventName' => $r->event_name,
							'Name' => $r->name,
							'Description' => $r->description,
							'Url' => $r->url,
							'DateTime' => $r->date_time
						);
				}
			}
			$response['Status'] = Success();
		} else {
      logtrace("Tournaments", $sql);
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function ClassMasters($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
			return $cache;

		if (valid_id($request['KingdomId'])) {
			$location_clause = " and m.kingdom_id = $request[KingdomId]";
		} else {
			$order = "k.name, ";
		}

		if (valid_id($request['ParkId'])) {
			$location_clause = " and m.park_id = $request[ParkId]";
		}
                $masters_clause = "or a.award_id IN (select aw.award_id from " . DB_PREFIX . "award aw where aw.peerage = 'Paragon')";
		$attendance = "(SELECT max(att.date) FROM " . DB_PREFIX . "attendance att WHERE att.mundane_id = m.mundane_id) as last_attended";

		$sql = "select distinct p.park_id, p.name as park_name, k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id, a.peerage, ifnull(ka.name, a.name) as award_name, m.persona, ma.date, m.mundane_id, ma.rank, $attendance
					from " . DB_PREFIX . "awards ma
						left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = ma.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
								left join " . DB_PREFIX . "mundane m on m.mundane_id = ma.mundane_id
									left join " . DB_PREFIX . "park p on p.park_id = m.park_id
									left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
					where (0 $masters_clause) and m.active = 1 $location_clause
					order by $order a.peerage, a.name, m.persona
			";
      logtrace("ClassMasters", $sql);
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			while ($r->next()) {
				$response['Awards'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'Date' => $r->date,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'Rank' => $r->rank,
						'AwardName' => $r->award_name,
						'LastAttended' => $r->last_attended
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

  public function CrownQualed($kingdom_id) {
		$key = Ork3::$Lib->ghettocache->key(array('KingdomId' => $kingdom_id));
    if (!valid_id($kingdom_id))
      return false;
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false && false)
			return $cache;

    $sql = "select m.mundane_id, m.persona, ducal_terms.ducal_points, kingdom_terms.kingdom_points from
              ork_mundane m
              left join 
                (select mundane_id, sum(tour_points) as ducal_points from
                  (SELECT 
                      m.mundane_id, 
                        if(crown_limit > 0, least(count(*), crown_limit), count(*)) terms, count(*) tours, 
                        crown_points, crown_limit, peerage,
                        if(crown_limit > 0, least(count(*), crown_limit), count(*)) * crown_points tour_points
                    FROM `ork_awards` awards
                      left join ork_kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id
                      left join ork_mundane m on awards.mundane_id = m.mundane_id
                      left join ork_award a on ka.award_id = a.award_id
                    where crown_points > 0 and m.kingdom_id = $kingdom_id and peerage = 'None'
                    group by m.mundane_id, a.award_id) dterms
                  group by mundane_id, peerage) ducal_terms
                on m.mundane_id = ducal_terms.mundane_id
              left join
                (select mundane_id, sum(tour_points) as kingdom_points from
                  (SELECT  
                      m.mundane_id, 
                        if(crown_limit > 0, least(count(*), crown_limit), count(*)) terms, count(*) tours, 
                        crown_points, crown_limit, peerage,
                        if(crown_limit > 0, least(count(*), crown_limit), count(*)) * crown_points tour_points 
                    FROM `ork_awards` awards
                      left join ork_kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id
                      left join ork_mundane m on awards.mundane_id = m.mundane_id
                      left join ork_award a on ka.award_id = a.award_id
                    where crown_points > 0 and m.kingdom_id = $kingdom_id and peerage = 'Kingdom-Level-Award'
                    group by m.mundane_id, a.award_id) kterms
                  group by mundane_id, peerage) kingdom_terms
                on m.mundane_id = kingdom_terms.mundane_id
              where m.kingdom_id = $kingdom_id and (ducal_terms.mundane_id is not null or kingdom_terms.mundane_id is not null)
                and (kingdom_points >= 4 or (kingdom_points + ducal_points) >= 6 or ducal_points >= 6)
                order by m.mundane_id";
    logtrace("CrownQualedPlayerAwards", $sql);
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			while ($r->next()) {
				$name = array();
				if ($r->kingdom_points > 0)
				$name[] = $r->kingdom_points . ' Kingdom Points';
				if ($r->ducal_points > 0 || $r->kingdom_points)
				$name[] = ($r->ducal_points + $r->kingdom_points) . ' Ducal Points';
				$response['Awards'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'KingdomId' => $kingdom_id,
						'DucalPoints' => $r->ducal_points,
						'KingdomPoints' => $r->kingdom_points,
						'AwardName' => implode(', ', $name)
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
  }
  
	public function PlayerAwards($request) {

		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
			return $cache;

		if (valid_id($request['KingdomId'])) {
			$location_clause = " and m.kingdom_id = $request[KingdomId]";
		} else {
			$order = "k.name, ";
		}
		if (valid_id($request['ParkId'])) {
			$location_clause = " and m.park_id = $request[ParkId]";
		}
		if (valid_id($request['IncludeKnights'])) {
			$knights_clause = "or a.peerage = 'Knight'";
		}
		if (valid_id($request['IncludeMasters'])) {
			$masters_clause = "or a.peerage = 'Master'";
		}
		if (valid_id($request['IncludeLadder']) && is_numeric($request['LadderMinimum'])) {
			$ladder_clause = " or (a.is_ladder = 1 and ma.rank >= $request[LadderMinimum])";
		}
		if (valid_id($request['IncludeTitles'])) {
			$title_clause =  "or a.is_title = 1";
		}
		if (is_array($request['Awards'])) {
			$awards_clause = 'and in (' . implode(',',$request['Awards']) . ')';
		}
		$sql = "select 
              distinct p.park_id, p.name as park_name, 
              k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id, 
              a.peerage, ifnull(ka.name, a.name) as award_name, 
              m.persona, ma.date, m.mundane_id, ma.rank, 
              bwm.mundane_id as by_whom_id, bwm.persona as by_whom_persona,
              ma.awards_id
					from " . DB_PREFIX . "awards ma
						left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = ma.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
								left join " . DB_PREFIX . "mundane m on m.mundane_id = ma.mundane_id
								left join " . DB_PREFIX . "mundane bwm on bwm.mundane_id = ma.by_whom_id
									left join " . DB_PREFIX . "park p on p.park_id = m.park_id
									left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
					where (0 $knights_clause $masters_clause $ladder_clause $title_clause) and m.active = 1 $location_clause $awards_clause
					order by $order a.peerage, a.name, m.persona
			";

        logtrace("PlayerAwards", $sql);
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			while ($r->next()) {
				$response['Awards'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'Date' => $r->date,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingdom_id,
						'ParkName' => $r->park_name,
						'KingdomName' => $r->kingdom_name,
						'Rank' => $r->rank,
						'AwardName' => $r->award_name,
						'EnteredBy' => $r->by_whom_persona,
						'EnteredById' => $r->by_whom_id
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function CustomAwards($request) {

		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
			return $cache;

		$location_clause = '';
		$order = '';
		if (valid_id($request['KingdomId'])) {
			$location_clause = " and m.kingdom_id = " . intval($request['KingdomId']);
		} else {
			$order = "k.name, ";
		}
		if (valid_id($request['ParkId'])) {
			$location_clause = " and m.park_id = " . intval($request['ParkId']);
		}

		$past_year = date("Y-m-d", strtotime("-1 year"));

		$sql = "select
				distinct p.park_id, p.name as park_name,
				k.kingdom_id, k.name as kingdom_name,
				m.persona, m.mundane_id,
				ma.custom_name, ma.date, ma.note,
				gbm.mundane_id as given_by_id, gbm.persona as given_by_persona
			from " . DB_PREFIX . "awards ma
				left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = ma.kingdomaward_id
				left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
				left join " . DB_PREFIX . "mundane m on m.mundane_id = ma.mundane_id
				left join " . DB_PREFIX . "mundane gbm on gbm.mundane_id = ma.given_by_id
				left join " . DB_PREFIX . "park p on p.park_id = m.park_id
				left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
			where ma.custom_name is not null
				and ma.custom_name != ''
				and (ma.revoked = 0 or ma.revoked is null)
				and (a.is_ladder = 0 or a.is_ladder is null)
				and m.active = 1
				$location_clause
				and exists (
					select 1 from " . DB_PREFIX . "attendance att
					where att.mundane_id = m.mundane_id
						and att.date > '$past_year'
				)
			order by $order p.name, m.persona, ma.date
		";

		logtrace("CustomAwards", $sql);
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Awards'] = array();
			while ($r->next()) {
				$response['Awards'][] = array(
					'MundaneId' => $r->mundane_id,
					'Persona' => $r->persona,
					'CustomAwardName' => $r->custom_name,
					'Date' => $r->date,
					'GivenById' => $r->given_by_id,
					'GivenBy' => $r->given_by_persona,
					'Note' => $r->note,
					'ParkId' => $r->park_id,
					'KingdomId' => $r->kingdom_id,
					'ParkName' => $r->park_name,
					'KingdomName' => $r->kingdom_name
				);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function PlayerAwardRecommendations($request) {

		$key = Ork3::$Lib->ghettocache->key($request);

		// Removing the cache to ensure the feature is working correctly and users are not confused when
		// recommendations are not deleted or added as expected.
		// if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false)
		// 	return $cache;

		if (valid_id($request['KingdomId'])) {
			$location_clause = " AND m.kingdom_id = $request[KingdomId]";
		}
		if (valid_id($request['ParkId'])) {
			$location_clause = " AND m.park_id = $request[ParkId]";
		}
		if (valid_id($request['PlayerId'])) {
			$location_clause = " AND recs.mundane_id = $request[PlayerId]";
		}

		$sql = "select
			a.peerage, ifnull(ka.name, a.name) as award_name, 
			m.persona, 
			recs.date_recommended, 
			m.mundane_id, 
			recs.rank, 
			rbi.mundane_id as recommended_by_id, rbi.persona as recommended_by_persona,
			recs.recommendations_id,
			recs.award_id,
			recs.reason,
			recs.deleted_at,
			recs.deleted_by,
			ka.award_id as ka_award_id,
			ka.kingdomaward_id as ka_kaward_id,
			(SELECT COUNT(suboa.awards_id) FROM " . DB_PREFIX . "awards suboa WHERE suboa.mundane_id = recs.mundane_id AND suboa.kingdomaward_id = ka.kingdomaward_id AND suboa.rank >= recs.rank) as kacount,
			(SELECT COUNT(suboa2.awards_id) FROM " . DB_PREFIX . "awards suboa2 WHERE suboa2.mundane_id = recs.mundane_id AND suboa2.award_id = recs.award_id AND suboa2.rank >= recs.rank) as awcount
			FROM " . DB_PREFIX . "recommendations recs			
			LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = recs.kingdomaward_id
			LEFT JOIN " . DB_PREFIX . "award a on a.award_id = ka.award_id
			LEFT join " . DB_PREFIX . "mundane m on m.mundane_id = recs.mundane_id
			LEFT join " . DB_PREFIX . "mundane rbi on rbi.mundane_id = recs.recommended_by_id
			WHERE (recs.deleted_by IS NULL OR recs.deleted_by = 0) $location_clause
			HAVING (kacount = 0 AND awcount = 0)
			order by m.persona, a.name, recs.rank, m.persona";
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['AwardRecommendations'] = array();
			while ($r->next()) {
				$response['AwardRecommendations'][] = array(
						'RecommendationsId' => $r->recommendations_id,
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'DateRecommended' => $r->date_recommended,
						'Rank' => $r->rank,
						'AwardName' => $r->award_name,
						'Reason' => $r->reason,
						'RecommendedByName' => $r->recommended_by_persona,
						'RecommendedById' => $r->recommended_by_id
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function Guilds($request) {
		if (valid_id($request['KingdomId'])) $where = "and k.kingdom_id = '$request[KingdomId]'";
		if (valid_id($request['ParkId'])) $where = "and p.park_id = '$request[ParkId]'";
		if (valid_id($request['MundaneId'])) $where = "and m.mundane_id = '$request[MundaneId]'";

		if ($request['PerWeeks'] == 1)
			$per_period = date("Y-m-d", strtotime("-$request[Periods] week"));
		if ($request['PerMonths'] == 1)
			$per_period = date("Y-m-d", strtotime("-$request[Periods] month"));

		$sql = "select c.class_id, c.name as class_name, count(a.attendance_id) as attendance_count, m.persona, m.mundane_id, k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id, p.park_id, p.name as park_name
					from " . DB_PREFIX . "class c
						left join " . DB_PREFIX . "attendance a on a.class_id = c.class_id
							left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
								left join " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
								left join " . DB_PREFIX . "park p on m.park_id = p.park_id
					where
						m.suspended = 0 and a.date > '$per_period' $where
					group by a.mundane_id, a.class_id
						having count(a.attendance_id) >= '" . mysql_real_escape_string($request['MinimumAttendanceRequirement']) . "'
					order by m.kingdom_id, c.class_id, m.park_id, m.persona";
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Guilds'] = array();
			while ($r->next()) {
				$response['Guilds'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'ClassId' => $r->class_id,
						'ClassName' => $r->class_name,
						'AttendanceCount' => $r->attendance_count,
						'ParkId' => $r->park_id,
						'ParkName' => $r->park_name,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'KingdomName' => $r->kingdom_name
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function UnitSummary($request) {
		if (valid_id($request['KingdomId'])) $kingdom = " and m.kingdom_id = '$request[KingdomId]'";
		if (valid_id($request['ParkId'])) $park = " and m.park_id = '$request[ParkId]'";
		if (valid_id($request['MundaneId'])) $mundane = " and um.mundane_id = '$request[MundaneId]'";
		if (valid_id($request['EventId'])) $event = " and e.event_id = '$request[EventId]'";
		if (valid_id($request['IncludeCompanies'])) $companies = " or u.type = 'Company' ";
		if (valid_id($request['IncludeHouseHolds'])) $households = " or u.type = 'Household' ";
		if (valid_id($request['IncludeEvents'])) $events = " or u.type = 'Event' ";
		if (valid_id($request['ActiveOnly'])) $active_only = " and um.active = 'Active' ";

		$sql = "select distinct u.*, m.*, count(um.mundane_id) as member_count, um.unit_mundane_id
					from " . DB_PREFIX . "unit u
						left join " . DB_PREFIX . "unit_mundane um on u.unit_id = um.unit_id
							left join " . DB_PREFIX . "mundane m on m.mundane_id = um.mundane_id
						left join " . DB_PREFIX . "event e on e.unit_id = u.unit_id
					where 1 and (1 $kingdom $park $mundane $event_id $active_only) and (0 $companies $households $events)
					group by u.unit_id
				order by u.name";
		$r = $this->db->query($sql);
		logtrace("Unit Summary", array($request, $sql));
		$response = array( 'Status' => Success(), 'Units' => array());
		if ($r === false) {
			$response['Status'] = InvalidParameter();
		} else if ($r->size() > 0) {
			while ($r->next()) {
				$response['Units'][] = array(
					'UnitId' => $r->unit_id,
					'Type' => $r->type,
					'Name' => $r->name,
					'Persona' => $r->persona,
					'MemberCount' => $r->member_count,
					'UnitMundaneId' => $r->unit_mundane_id
				);
			}
		}
		return $response;
	}

	public function AttendanceSummary($request) {
		if (valid_id($request['EventId'])) $where = "where ssa.event_id = '" . mysql_real_escape_string($request['EventId']) . "'";
		if (valid_id($request['KingdomId'])) $where = "where ssa.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
		if (valid_id($request['ParkId'])) $where = "where ssa.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
    	if (valid_id($request['PrincipalityId'])) $where = "where ssa.kingdom_id = '" . mysql_real_escape_string($request['PrincipalityId']) . "'";
		if ($request['NativePopulace'] && (valid_id($request['KingdomId']) || valid_id($request['ParkId']))) $where .= " and m.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
		if ($request['Waivered']) $where = (strlen($where)>0)?" and m.waivered = 1":"where m.waivered = 1";
		/*
		if (strlen($where) == 0) {
			$response['Status'] = InvalidParameter();
			return $response;
		}
		*/
		if ($request['PerWeeks'] == 1)
			$per_period = date("Y-m-d", strtotime("-$request[Periods] week"));
		if ($request['PerMonths'] == 1)
			$per_period = date("Y-m-d", strtotime("-$request[Periods] month"));
		switch($request['ByPeriod']) {
		    case 'week':
	                $by_period = 'ssa.date_year, ssa.date_week3';
	                $group_period = 'a.date_year, a.date_week3';
	            break;
		    case 'month':
		            $by_period = 'ssa.date_year, ssa.date_month';
	                $group_period = 'a.date_year, a.date_week3';
		        break;
		    case 'date':
		    default:
    		        $by_period = 'ssa.date';
                    $group_period = 'a.date';
                break;
        }


		$sql = "select max(a.date) as `date`, count(a.mundane_id) as attendees, a.event_start, a.event_end, a.event_id, a.event_calendardetail_id, a.event_id, e.name as event_name,
					ifnull(a.park_id, ep.park_id) as park_id, ifnull(p.name, ep.name) as park_name, year(a.date) as year, week(a.date, 3) as week,
					ifnull(k.kingdom_id, ek.kingdom_id) as kingdom_id, ifnull(k.name, ek.name) as kingdom_name, ifnull(k.parent_kingdom_id, ek.parent_kingdom_id) as parent_kingdom_id
					from
						(select max(ssa.date) as `date`, max(ssa.date_year) as date_year, max(ssa.date_month) as date_month, max(date_week3) as date_week3,
              ssa.mundane_id, ssd.event_start, ssd.event_end, ssa.park_id, ssd.event_calendardetail_id, ssd.event_id
							from " . DB_PREFIX . "attendance ssa
								left join " . DB_PREFIX . "event_calendardetail ssd on ssa.event_calendardetail_id = ssd.event_calendardetail_id
								left join " . DB_PREFIX . "park p on ssa.park_id = p.park_id
								left join " . DB_PREFIX . "mundane m on ssa.mundane_id = m.mundane_id
							$where
							group by ssa.park_id, $by_period, ssd.event_start, ssd.event_end, mundane_id) a
						left join " . DB_PREFIX . "park p on a.park_id = p.park_id
							left join " . DB_PREFIX . "kingdom k on p.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "event e on a.event_id = e.event_id
							left join " . DB_PREFIX . "park ep on e.park_id = ep.park_id
							left join " . DB_PREFIX . "kingdom ek on e.kingdom_id = ek.kingdom_id
					where
						a.date > '$per_period' and a.date <= now()
					group by $group_period
					order by a.date desc, kingdom_name asc, park_name asc, event_name asc";

    $r = $this->db->query($sql);
		if ($r !== false && $r->size() > 0) {
			$response = array( 'Status' => Success(), 'Dates' => array());
			while ($r->next()) {
				$response['Dates'][] = array(
						'Date' => $r->date,
						'Year' => $r->year,
						'Week' => $r->week,
						'Attendees' => $r->attendees,
						'EventCalendarDetailId' => $r->event_calendardetail_id,
						'EventId' => $r->event_id,
						'ParkId' => $r->park_id,
						'ParkName' => $r->park_name,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'KingdomName' => $r->kingdom_name,
						'EventStart' => $r->event_start,
						'EventEnd' => $r->event_end,
						'EventName' => $r->event_name
					);
			}
		} else {
			$response['Status'] = InvalidParameter('A parameter was set incorrectly: ' . $sql . "\n" . print_r($request, true));
		}
		logtrace("Report->AttendanceSummary()", array($this->db->lastSql, $request));
		return $response;
	}

	public function AttendanceForEvent($request) {
		if (valid_id($request['UnitId'])) {
			$unit_clause = 	"LEFT JOIN " . DB_PREFIX . "unit_mundane um on um.mundane_id = a.mundane_id
								LEFT JOIN " . DB_PREFIX . "unit u on u.unit_id = um.unit_id
							";
			$unit_phrase = "u.name as unit_name, ";
		}

		$sql = "select a.*, k.name as kingdom_name, p.park_id, p.name as park_name, k.parent_kingdom_id, m.persona, $unit_phrase c.name as class_name
					from " . DB_PREFIX . "attendance a
						LEFT JOIN " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
							LEFT JOIN " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
							LEFT JOIN " . DB_PREFIX . "park p on m.park_id = p.park_id
						LEFT JOIN " . DB_PREFIX . "class c on a.class_id = c.class_id
						$unit_clause
					where a.event_id = '" . mysql_real_escape_string($request['EventId']) . "' and a.event_calendardetail_id = '" . mysql_real_escape_string($request['EventCalendarDetailId']) . "'
				";
		if (valid_id($request['KingdomId'])) $sql .= " and a.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
		if (valid_id($request['ParkId'])) $sql .= " and a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
		if (valid_id($request['UnitId'])) $sql .= " and a.unit_id = '" . mysql_real_escape_string($request['UnitId']) . "'";
		if (valid_id($request['MundandeId'])) $sql .= " and a.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'";
		if (valid_id($request['ClassId'])) $sql .= " and a.class_id = '" . mysql_real_escape_string($request['ClassId']) . "'";

		logtrace('AttendanceForEvent',array($request, $sql));

		$r = $this->db->query($sql);

		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Attendance'] = array();
			while ($r->next()) {
				$response['Attendance'][] = array(
						'AttendanceId' => $r->attendance_id,
						'EnteredAt' => $r->entered_at,
						'EnteredById' => $r->by_whom_id,
						'MundaneId' => $r->mundane_id,
						'ClassId' => $r->class_id,
						'Date' => $r->date,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'EventId' => $r->event_id,
						'EventCalendarDetailId' => $r->event_calendardetail_id,
						'Credits' => $r->credits,
						'KingdomName' => $r->kingdom_name,
						'ParkName' => $r->park_name,
						'UnitName' => $r->unit_name,
						'Persona' => $r->persona,
						'ClassName' => $r->class_name,
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function AttendanceForDate($request) {
		if (valid_id($request['UnitId'])) {
			$unit_clause = 	"LEFT JOIN " . DB_PREFIX . "unit_mundane um on um.mundane_id = a.mundane_id
								LEFT JOIN " . DB_PREFIX . "unit u on u.unit_id = um.unit_id
							";
			$unit_phrase = "u.name as unit_name, ";
		}

		$sql = "select a.*, a.persona as attendance_persona,
					k.name as kingdom_name, k.parent_kingdom_id, mk.name as from_kingdom_name, mk.parent_kingdom_id as from_parent_kingdom_id,
					p.name as park_name, p.park_id as park_id, mp.name as from_park_name, mp.park_id as from_park_id,
					m.persona, bwm.mundane_id as by_whom_id, bwm.persona as by_whom_persona,
					$unit_phrase c.name as class_name, e.event_id, d.event_calendardetail_id, e.name as event_name, d.event_start, d.event_end
					from " . DB_PREFIX . "attendance a
						LEFT JOIN " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
						LEFT JOIN " . DB_PREFIX . "mundane bwm on a.by_whom_id = bwm.mundane_id
							LEFT JOIN " . DB_PREFIX . "kingdom mk on m.kingdom_id = mk.kingdom_id
							LEFT JOIN " . DB_PREFIX . "park mp on m.park_id = mp.park_id
						LEFT JOIN " . DB_PREFIX . "kingdom k on a.kingdom_id = k.kingdom_id
						LEFT JOIN " . DB_PREFIX . "park p on a.park_id = p.park_id
						LEFT JOIN " . DB_PREFIX . "class c on a.class_id = c.class_id
						LEFT JOIN " . DB_PREFIX . "event e on a.event_id = e.event_id
						LEFT JOIN " . DB_PREFIX . "event_calendardetail d on a.event_calendardetail_id = d.event_calendardetail_id
						$unit_clause
					where a.date = '" . mysql_real_escape_string($request['Date']) . "'
				";
		if (valid_id($request['KingdomId'])) $sql .= " and a.kingdom_id = $request[KingdomId]";
		if (valid_id($request['ParkId'])) $sql .= " and a.park_id = $request[ParkId]";
		if (valid_id($request['UnitId'])) $sql .= " and a.unit_id = $request[UnitId]";
		if (valid_id($request['MundandeId'])) $sql .= " and a.mundane_id = $request[MundaneId]";
		if (valid_id($request['ClassId'])) $sql .= " and a.class_id = $request[ClassId]";

		logtrace('AttendanceForDate',array($request, $sql));

		$sql .= " order by kingdom_name, park_name, m.persona";

        $r = $this->db->query($sql);

		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['Attendance'] = array();
			while ($r->next()) {
				$response['Attendance'][] = array(
						'AttendanceId' => $r->attendance_id,
						'MundaneId' => $r->mundane_id,
						'ClassId' => $r->class_id,
						'Date' => $r->date,
						'ParkId' => $r->park_id,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'FromParkId' => $r->from_park_id,
						'FromKingdomId' => $r->from_kingdom_id,
						'FromParentKingdomId' => $r->from_parent_kingodm_id,
						'EventId' => $r->event_id,
						'EventCalendarDetailId' => $r->event_calendardetail_id,
						'EventName' => $r->event_name,
						'EventStart' => $r->event_start,
						'EventEnd' => $r->event_end,
						'Credits' => $r->credits,
						'KingdomName' => $r->kingdom_name,
						'ParkName' => $r->park_name,
						'FromKingdomName' => $r->from_kingdom_name,
						'FromParkName' => $r->from_park_name,
						'UnitName' => $r->unit_name,
						'EnteredBy' => $r->by_whom_persona,
						'EnteredById' => $r->by_whom_id,
						'EnteredAt' => $r->entered_at,
						'Persona' => $r->persona,
						'ClassName' => $r->class_name,
        				'AttendancePersona' => $r->attendance_persona,
            			'Note' => $r->note,
            			'Flavor' => $r->class_id==6?$r->flavor:'',
					);
			}
			$response['Status'] = Success($sql);
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function GeneralLedger($request) {

	}

	public function GetAuthorizations($request) {
        logtrace("GetAuthorizations", $request);
		$restrict_clause = array();
		switch ($request['Type']) {
			case AUTH_PARK:
				$restrict_clause[] = "a.park_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$order_by[] = "p.name";
				break;
			case AUTH_KINGDOM:
				$restrict_clause[] = "a.kingdom_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$order_by[] = "k.name";
				break;
			case AUTH_EVENT:
				$restrict_clause[] = "a.event_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$order_by[] = "e.name";
				break;
			case AUTH_UNIT:
				$restrict_clause[] = "a.unit_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$order_by[] = "u.name";
				break;
			default:
				$order_by[] = "k.name, p.name, u.name, e.name";
                $request['Type'] = AUTH_ADMIN;
                break;
		}
		switch ($request['Officers']) {
			case 'Officers':
				$restrict_clause[] = "o.officer_id is not null";
				break;
			case 'NonOfficers':
				$restrict_clause[] = "o.officer_id is null";
				break;
			case 'Both':
				$order_by[] = "o.role";
				break;
		}
		$sql = "select a.*, p.name as park_name, k.name as kingdom_name, k.parent_kingdom_id, e.name as event_name, u.name as unit_name, m.username, m.given_name, m.surname, m.persona, m.restricted, o.role as officer_role, o.officer_id
					from ".DB_PREFIX."authorization a
						left join ".DB_PREFIX."officer o on o.authorization_id = a.authorization_id
						left join ".DB_PREFIX."mundane m on a.mundane_id = m.mundane_id
						left join ".DB_PREFIX."park p on a.park_id = p.park_id
						left join ".DB_PREFIX."kingdom k on a.kingdom_id = k.kingdom_id
						left join ".DB_PREFIX."event e on a.event_id = e.event_id
						left join ".DB_PREFIX."unit u on a.unit_id = u.unit_id
					".(count($restrict_clause)>0?"where":"")." ".implode(' AND ', $restrict_clause)."
					order by ".implode(',',$order_by);

		logtrace('GetAuthorizations()', $sql);
		$r = $this->db->query($sql);

		if (strlen($request['Token']) > 0
				&& ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, $request['Type'], $request['Id'], AUTH_EDIT)) {
			$restricted_access = true;
		} else {
			$restricted_access = false;
		}

    	logtrace('GetAuthorizations()', $restricted_access);

		if ($r !== false) {
			$response['Status'] = Success();
			$response['Authorizations'] = array();
			if ($r->size() > 0) {
				while ($r->next()) {
					$response['Authorizations'][] = array(
								'AuthorizationId' => $r->authorization_id,
								'MundaneId' => $r->mundane_id,
								'ParkId' => $r->park_id,
								'KingdomId' => $r->kingdom_id,
								'EventId' => $r->event_id,
								'UnitId' => $r->unit_id,
								'Role' => $r->role,
								'ParkName' => $r->park_name,
								'KingdomName' => $r->kingdom_name,
								'ParentKingdomId' => $r->parent_kingodm_id,
								'EventName' => $r->event_name,
								'UnitName' => $r->unit_name,
								'Restricted' => $r->restricted,
								'UserName' => $r->username,
								'GivenName' => ($restricted_access&&$r->restricted==0)?$r->given_name:"",
								'Surname' => ($restricted_access&&$r->restricted==0)?$r->surname:"",
								'Persona' => $r->persona,
								'OfficerId' => $r->officer_id,
								'OfficerRole' => $r->officer_role
							);
				}
			}
		} else {
			$response['Status'] = InvalidParameter('Problem processing request.');
		}

		return $response;

	}

	public function GetPlayerRoster($request) {
		$select_list = array();
		$order_by = "k.name, p.name";
		$restrict_clause = array();
		if (true == $request['Suspended']) {
			/* Borrowed from Player class to clear the suspensions past their suspended_until date before running the report */
			$sql = "update " . DB_PREFIX . "mundane set suspended = 0, suspended_by_id = null, suspended_at = null, suspended_until = null, suspension = null where suspended_until < curdate() and suspended_until is not null and suspended_until != '0000-00-00'";
			$this->db->query($sql);
		}
		switch ($request['Type']) {
			case AUTH_PARK:
				    $kdid  = Ork3::$Lib->park->GetParkKingdomId($request['Id']);
				$restrict_clause[] = "m.park_id = '" . mysql_real_escape_string($request['Id']) . "'";
				    if (!empty($kdid)) {
					  $restrict_clause[] = "m.kingdom_id = '" . mysql_real_escape_string($kdid) . "'";
					    $dues_restrict_clause = "and (a.kingdom_id = '" . mysql_real_escape_string($kdid) . "' AND a.park_id = '" . mysql_real_escape_string($request['Id']) . "')";
				    } else {
					    $dues_restrict_clause = "and (a.kingdom_id = '" . mysql_real_escape_string($request['Id']) . "' OR a.park_id = '" . mysql_real_escape_string($request['Id']) . "')";
				    }
				$order_by = "p.name";
				break;
			case AUTH_KINGDOM:
				$restrict_clause[] = "k.kingdom_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$dues_restrict_clause = "and (a.kingdom_id = '" . mysql_real_escape_string($request['Id']) . "' or a.park_id = '" . mysql_real_escape_string($request['Id']) . "')";
				$order_by = "k.name, p.name";
				break;
			case AUTH_EVENT:
				$join_clause = 'left join ' . DB_PREFIX . "unit_mundane um on m.mundane_id = um.mundane_id and um.unit_id = '" . mysql_real_escape_string($request['Id']) . "'";
				$select_list = array ('um.role', 'um.title', 'um.active');
				$order_by = "um.unit_id";
				$restrict_clause[] = "e.event_id = '" . mysql_real_escape_string($request['Id']) . "'";
				break;
			case AUTH_UNIT:
				$join_clause = 'left join ' . DB_PREFIX . "unit_mundane um on m.mundane_id = um.mundane_id";
				$select_list = array ('um.role', 'um.title', 'um.active', 'um.role as unit_role', 'um.title as unit_title', 'um.unit_mundane_id');
				$order_by = "um.unit_id";
				$restrict_clause[] = " um.unit_id = '" . mysql_real_escape_string($request['Id']) . "' and " . (valid_id($request['IncludeRetiredUnitMembers'])?"":"um.active = 'Active'");
				break;
		}
		$select_list = array_merge($select_list,
			array(
				'm.mundane_id','m.persona','m.park_id','m.kingdom_id','m.restricted','m.waivered','m.given_name', 'm.surname', 'm.other_name',
				'm.suspended', 'm.suspended_at', 'm.suspended_until', 'm.suspension', 'suspended_by.persona suspendator',
				'p.name as park_name','k.name as kingdom_name','m.penalty_box'));
			if (true == $request['Active']) $restrict_clause[] = ' m.active = 1 ';
			if (true == $request['InActive']) $restrict_clause[] = ' m.active = 0 ';
			if (true == $request['Waivered']) $restrict_clause[] = ' m.waivered = 1';
			if (true == $request['UnWaivered']) $restrict_clause[] = ' m.waivered = 0';
			if (true == $request['Banned']) $restrict_clause[] = ' m.penalty_box = 1';
			if (true == $request['Suspended']) $restrict_clause[] = ' m.suspended = 1';
			if (true == $request['DuesPaid'] && (AUTH_PARK == $request['Type'] || AUTH_KINGDOM == $request['Type'])) {
				$duespaid_clause = 'INNER JOIN
									(select dues_through, case split_id when null then 0 else 1 end as split_id, src_mundane_id
										from ' . DB_PREFIX . 'split s
										INNER join ' . DB_PREFIX . 'account a on s.account_id = a.account_id
											'.$dues_restrict_clause.'
											and s.is_dues = 1
										where s.dues_through > curdate())
									dues on m.mundane_id = dues.src_mundane_id';
			$select_list[] = 'split_id as duespaid';
			$select_list[] = 'dues_through as duesthrough';
			$order_by = 'duespaid desc,'.$order_by;
		}
		$select_list[] = 'k.parent_kingdom_id';
		$select_list[] = 'MAX(att.date) as last_sign_in';
		$select_list = array_merge($select_list, array());
		if (strlen($request['Token']) > 0
				&& ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
				&& Ork3::$Lib->authorization->HasAuthority($mundane_id, $request['Type'], $request['Id'], AUTH_EDIT)) {
			$restricted_access = true;
		} else {
			$restricted_access = false;
		}
		$sql = 'SELECT ' . implode(',',$select_list) . "
					FROM " . DB_PREFIX . "mundane m
						LEFT JOIN " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
						LEFT JOIN " . DB_PREFIX . "park p on m.park_id = p.park_id
						left join " . DB_PREFIX . "mundane suspended_by on m.suspended_by_id = suspended_by.mundane_id
						left join " . DB_PREFIX . "attendance att on att.mundane_id = m.mundane_id
						$duespaid_clause
						$join_clause
					".(count($restrict_clause)?"where":"")."
						".implode(' and ', $restrict_clause)."
					GROUP BY m.mundane_id
					ORDER BY $order_by, m.persona, m.surname, m.given_name
		";
		logtrace('GetPlayerRoster()', array($sql, $restrict_clause));
		$r = $this->db->query($sql);

		if ($r !== false) {
			$response['Status'] = Success();
			$response['Roster'] = array();
			if ($r->size() > 0) {
				while ($r->next()) {
					$response['Roster'][] = array(
								'MundaneId' => $r->mundane_id,
								'GivenName' => $restricted_access&&$r->restricted==0?$r->given_name:"",
								'Surname' => $restricted_access&&$r->restricted==0?$r->surname:"",
								'OtherName' => $restricted_access&&$r->restricted==0?$r->other_name:"",
								'Persona' => $r->persona,
								'Suspended' => $r->suspended,
								'SuspendedAt' => $r->suspended_at,
								'SuspendedUntil' => $r->suspended_until,
								'Suspendator' => $r->suspendator,
								'Suspension' => $r->suspension,
								'ParkId' => $r->park_id,
								'KingdomId' => $r->kingdom_id,
								'ParentKingdomId' => $r->parent_kingdom_id,
								'ParkName' => $r->park_name,
								'KingdomName' => $r->kingdom_name,
								'Restricted' => $r->restricted,
								'Waivered' => $r->waivered,
								'DuesPaid' => $r->duespaid,
								'DuesThrough' => $r->duesthrough,
								'UnitMundaneId' => $r->unit_mundane_id,
								'UnitRole' => $r->unit_role,
								'UnitTitle' => $r->unit_title,
								'PenaltyBox' => $r->penalty_box,
								'LastSignIn' => $r->last_sign_in,
								'Displayable' => $restricted_access||$r->restricted==0
							);
				}
			}
		} else {
			$response['Status'] = InvalidParameter('Problem with request.');
		}

		return $response;
	}

	public function GetKingdomParkAverages($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		if (strlen($request['ReportFromDate']) == 0) $request['ReportFromDate'] = 'curdate()';
		if (strlen($request['AverageWeeks']) == 0 && strlen($request['AverageMonths']) == 0) $request['AverageWeeks'] = 26;
		if (strlen($request['KingdomId']) == 0) $request['KingdomId'] = '0';
		if ($request['NativePopulace']) $native_populace .= "m.park_id = a.park_id and";
		if ($request['Waivered']) $waivered_peeps = "m.waivered = 1 and";

		if (strlen($request['AverageWeeks']) > 0) {
			$per_period = date("Y-m-d", strtotime("-$request[AverageWeeks] week"));
		} else {
			$per_period = date("Y-m-d", strtotime("-$request[AverageMonths] month"));
		}

		$escaped_kingdom_id = mysql_real_escape_string($request['KingdomId']);

		// Only join mundane table when NativePopulace or Waivered filters are active
		$mundane_join = (!empty($native_populace) || !empty($waivered_peeps))
			? "left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id"
			: "";

		$sql = "select
						count(mundanesbyweek.mundane_id) attendance_count, p.park_id, p.name, p.has_heraldry,
						pt.title, p.parktitle_id
					from
						" . DB_PREFIX . "park p
							left join " . DB_PREFIX . "parktitle pt on pt.parktitle_id = p.parktitle_id
							left join
								(select
										a.mundane_id, a.date_week3 as week, a.park_id
									from " . DB_PREFIX . "attendance a
										$mundane_join
									where
										$native_populace
										$waivered_peeps
										date > '$per_period'
										and a.kingdom_id = '$escaped_kingdom_id'
										and a.mundane_id > 0
									group by date_year, date_week3, mundane_id) mundanesbyweek
								on p.park_id = mundanesbyweek.park_id
					where p.kingdom_id = '$escaped_kingdom_id' and p.active = 'Active'
					group by park_id
					order by name";
		logtrace('Report: GetKingdomParkAverages', array($request,$sql));
		$r = $this->db->query($sql);
		$response = array(
			'Status' => Success(),
			'KingdomParkAveragesSummary' => ''
		);
		if ($r === false) {
			$response['Status'] = InvalidParameter();
		} else {
			$report = array();
			while ($r->next()) {
				$report[] = array( 'AttendanceCount' => $r->attendance_count, 'ParkId' => $r->park_id, 'ParkName' => $r->name, 'Title' => $r->title, 'ParkTitleId' => $r->parktitle_id, 'HasHeraldry' => $r->has_heraldry );
			}
			$response['KingdomParkAveragesSummary'] = $report;
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function GetKingdomParkMonthlyAverages($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		if (strlen($request['KingdomId']) == 0) $request['KingdomId'] = '0';
		$monthly_period = date("Y-m-d", strtotime("-1 year"));
		$escaped_kingdom_id = mysql_real_escape_string($request['KingdomId']);

		// Group by date_year AND date_month so the same calendar month in two
		// different years (e.g. Feb 2025 vs Feb 2026) counts as two distinct months.
		$sql = "select
						count(mundanesbymonth.mundane_id) monthly_count, mundanesbymonth.park_id
					from
						(select
								a.mundane_id, a.date_year, a.date_month, a.park_id
							from " . DB_PREFIX . "attendance a
							where
								date > '$monthly_period'
								and a.kingdom_id = '$escaped_kingdom_id'
								and a.mundane_id > 0
							group by a.date_year, a.date_month, a.mundane_id, a.park_id) mundanesbymonth
					group by mundanesbymonth.park_id";
		logtrace('Report: GetKingdomParkMonthlyAverages', array($request, $sql));
		$r = $this->db->query($sql);
		$response = array(
			'Status' => Success(),
			'KingdomParkMonthlySummary' => array()
		);
		if ($r === false) {
			$response['Status'] = InvalidParameter();
		} else {
			$summary = array();
			while ($r->next()) {
				$summary[] = array( 'ParkId' => $r->park_id, 'MonthlyCount' => (int)$r->monthly_count );
			}
			$response['KingdomParkMonthlySummary'] = $summary;
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function GetTopParksByAttendance($request=null) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		if (strlen($request['Limit'] ?? '') == 0) $request['Limit'] = 25;
		if (strlen($request['StartDate'] ?? '') == 0) $request['StartDate'] = date("Y-m-d", strtotime("-12 month"));
		if (strlen($request['EndDate'] ?? '') == 0) $request['EndDate'] = date("Y-m-d");

		$escaped_start = mysql_real_escape_string($request['StartDate']);
		$escaped_end = mysql_real_escape_string($request['EndDate']);
		$escaped_limit = intval($request['Limit']);
		$native_populace = $request['NativePopulace'] ? "m.park_id = a.park_id and" : "";

		$sql = "select
					count(mundanesbyweek.mundane_id) attendance_count,
					p.park_id, p.name, p.has_heraldry,
					k.name kingdom_name, k.kingdom_id,
					pt.title, p.parktitle_id
				from
					" . DB_PREFIX . "park p
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = p.kingdom_id
						left join " . DB_PREFIX . "parktitle pt on pt.parktitle_id = p.parktitle_id
						left join
							(select
									a.mundane_id, a.date_week3 as week, a.park_id
								from " . DB_PREFIX . "attendance a
									left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
								where
									$native_populace
									date >= '$escaped_start'
									and date <= '$escaped_end'
									and a.mundane_id > 0
								group by date_year, date_week3, mundane_id) mundanesbyweek
							on p.park_id = mundanesbyweek.park_id
				where p.active = 'Active'
					and k.active = 'Active'
				group by p.park_id
				order by attendance_count desc
				limit $escaped_limit";
		logtrace('Report: GetTopParksByAttendance', array($request, $sql));
		$r = $this->db->query($sql);
		$response = array(
			'Status' => Success(),
			'TopParksSummary' => ''
		);
		if ($r === false) {
			$response['Status'] = InvalidParameter();
		} else {
			$report = array();
			while ($r->next()) {
				$report[] = array(
					'AttendanceCount' => $r->attendance_count,
					'ParkId' => $r->park_id,
					'ParkName' => $r->name,
					'HasHeraldry' => $r->has_heraldry,
					'KingdomId' => $r->kingdom_id,
					'KingdomName' => $r->kingdom_name,
					'Title' => $r->title,
					'ParkTitleId' => $r->parktitle_id
				);
			}
			$response['TopParksSummary'] = $report;
		}
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function GetActiveKingdomsSummary($request=null) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false)
			return $cache;

		if (strlen($request['KingdomAverageWeeks'] ?? '') == 0) $request['KingdomAverageWeeks'] = 26;
		if (strlen($request['ParkAttendanceWithin'] ?? '') == 0) $request['ParkAttendanceWithin'] = 4;
		if (strlen($request['ReportFromDate'] ?? '') == 0) $request['ReportFromDate'] = 'curdate()';
		$sql = "SELECT k.name, k.kingdom_id, k.parent_kingdom_id, pcount.park_count, ifnull(attendance_count,0) attendance, ifnull(monthly_attendance_count,0) monthly, ifnull(activeparks.parkcount,0) active_parks
					FROM `" . DB_PREFIX . "kingdom` k
					left join
						(select count(*) as park_count, pcnt.kingdom_id from `" . DB_PREFIX . "park` pcnt where pcnt.active = 'Active' group by pcnt.kingdom_id) pcount on pcount.kingdom_id = k.kingdom_id
					left join
						(select
								count(mundanesbyweek.mundane_id) attendance_count, mundanesbyweek.kingdom_id
							from
								(select
										mundane_id, date_week3 as week, kingdom_id
									from " . DB_PREFIX . "attendance
									where date > '" . date("Y-m-d", strtotime("-$request[KingdomAverageWeeks] week")) . "' group by date_week3, mundane_id)
									mundanesbyweek group by kingdom_id) total_attendance on total_attendance.kingdom_id = k.kingdom_id
					left join
						(select
								count(mundanesbymonth.mundane_id) monthly_attendance_count, mundanesbymonth.kingdom_id
							from
								(select
										mundane_id, date_month as month, kingdom_id
									from " . DB_PREFIX . "attendance
									where date > '" . date("Y-m-d", strtotime("-1 year")) . "' group by date_month, mundane_id)
									mundanesbymonth group by kingdom_id) monthly_attendance on monthly_attendance.kingdom_id = k.kingdom_id
					left join
						(select
								count(*) parkcount, kingdom_id
							from
								(select
										mundanesbyweek.kingdom_id
									from
										(select
												kingdom_id, park_id
											from " . DB_PREFIX . "attendance
											where date > '" . date("Y-m-d", strtotime("-$request[ParkAttendanceWithin] week")) . "' group by date_week3, mundane_id) mundanesbyweek
									group by kingdom_id, park_id) parkcount
							group by kingdom_id) activeparks on activeparks.kingdom_id = k.kingdom_id
					where active = 'Active'
                    order by k.name";
		logtrace('Report: GetActiveKingdomsSummary', array($request, $sql));
		$r = $this->db->query($sql);
		$report = array();
		while ($r->next()) {
			$report[] = array( 'KingdomName' => $r->name, 'ParentKingdomId' => $r->parent_kingdom_id,
									'IsPrincipality' => $r->parent_kingdom_id>0?1:0, 'KingdomId' => $r->kingdom_id,
									'ParkCount' => $r->park_count, 'Attendance' => $r->attendance, 'Monthly' => $r->monthly, 'Participation' => $r->active_parks );
		}
		$response = array(
			'Status' => Success(),
			'ActiveKingdomsSummaryList' => $report
		);
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}

	public function GetActivePlayers($request) {
		if (strlen($request['MinimumWeeklyAttendance']) == 0) $request['MinimumWeeklyAttendance'] = 0;
    	if (strlen($request['MinimumDailyAttendance']) == 0) $request['MinimumDailyAttendance'] = 6;
        if (strlen($request['MonthlyCreditMaximum']) == 0) $request['MonthlyCreditMaximum'] = 6;
		if (strlen($request['MinimumCredits']) == 0) $request['MinimumCredits'] = 9;
		if (strlen($request['PerWeeks']) == 0 && strlen($request['PerMonths']) == 0) $request['PerMonths'] = 6;
		if (strlen($request['ReportFromDate']) == 0) $request['ReportFromDate'] = 'curdate()';

		if (strlen($request['PerWeeks']) > 0) {
			$per_period = date("Y-m-d", strtotime("-$request[PerWeeks] week"));
		} else {
			$per_period = date("Y-m-d", strtotime("-$request[PerMonths] month"));
		}

    $park_id = valid_id($request['ParkId']) ? $request['ParkId'] : 0;
    
		if (valid_id($request['ParkId'])) {
			$location = " and m.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
    		if (valid_id($request['ByLocalPark'])) {
    		    $park_comparator = " and a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "' ";
    		}
		} else if (strlen($request['KingdomId']) > 0 && $request['KingdomId'] > 0) {
			$location = " and m.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
    		if (valid_id($request['ByKingdom'])) {
    		    $park_list = Ork3::$Lib->Kingdom->GetParks($request);
    		    $parks = array();
    		    foreach ($park_list['Parks'] as $p => $park)
    		        $parks[] = $p['ParkId'];
    		    $park_comparator = " and a.park_id in (" . implode($parks) . ") ";
    		}
		} else {
		    $park_comparator = "";
		}
		$select_dues_paid = '';
		if ($request['KingdomId'] > 0 || $request['ParkId'] > 0) {
            if ($request['DuesPaid']) {
				// Check for non-revoked active or dues for life
				$select_dues_paid = ', (SELECT COUNT(dues_id) FROM '  . DB_PREFIX . 'dues d WHERE d.mundane_id = attendance_summary.mundane_id AND d.kingdom_id = kingdom.kingdom_id AND d.revoked != 1 AND (d.dues_until >= CAST(CURRENT_TIMESTAMP AS DATE) OR d.dues_for_life = 1)) as duespaid';
				$duespaid_order = 'duespaid desc, ';
			}
		}
        if (trimlen($request['Peerage']) > 0) {
            $peerage = "
                    left join
                        (select distinct awards.mundane_id, award.peerage
                            from " . DB_PREFIX . "awards awards
                                left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                                    left join " . DB_PREFIX . "award award on ka.award_id = award.award_id
                                left join " . DB_PREFIX . "mundane m on awards.mundane_id = m.mundane_id
                            where award.peerage = '" . mysql_real_escape_string($request['Peerage']) . "' and awards.mundane_id > 0 $location
                            group by awards.mundane_id
                        ) peers on attendance_summary.mundane_id = peers.mundane_id
            ";
            $peerage_clause = "and peers.peerage = '" . mysql_real_escape_string($request['Peerage']) . "'";
            $peer_field = 'peers.peerage, ';
        }
		if ($request['Waivered']) {
			$waiver_clause = ' and m.waivered = 1';
		} else if ($request['UnWaivered']) {
			$waiver_clause = ' and m.waivered = 0';
		}
		$sql = "
                select main_summary.*, total_monthly_credits, local_park_weeks, credit_counts.daily_credits, credit_counts.rop_limited_credits 
                    from
                        (select
        						$peer_field count(week) as weeks_attended, sum(weekly_attendance) as park_days_attended, sum(daily_attendance) as days_attended, sum(credits_earned) total_credits, attendance_summary.mundane_id,
        							mundane.persona, kingdom.kingdom_id, park.park_id, kingdom.name kingdom_name, kingdom.parent_kingdom_id, park.name park_name, attendance_summary.waivered $select_dues_paid
        					from
        						(select
        								a.park_id > 0 as weekly_attendance, count(a.park_id > 0) as daily_attendance, a.mundane_id,
                                        a.date_week3 as week, a.date_year as year, a.kingdom_id, a.park_id, max(credits) as credits_earned, m.waivered
        							from " . DB_PREFIX . "attendance a
        								left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
        							where
												m.suspended = 0 and date > '$per_period' $park_comparator $location $waiver_clause
        							group by date_week3, date_year, mundane_id) attendance_summary
        					left join " . DB_PREFIX . "mundane mundane on mundane.mundane_id = attendance_summary.mundane_id
        						left join " . DB_PREFIX . "kingdom kingdom on kingdom.kingdom_id = mundane.kingdom_id
        						left join " . DB_PREFIX . "park park on park.park_id = mundane.park_id
        					
                            $peerage
        					group by mundane_id
        					having
        						weeks_attended >= '" . mysql_real_escape_string($request['MinimumWeeklyAttendance']) . "'
                                and days_attended >= '" . mysql_real_escape_string($request['MinimumDailyAttendance']) . "'
                                and total_credits >= '" . mysql_real_escape_string($request['MinimumCredits']) . "'
                                $peerage_clause
        					order by $duespaid_order kingdom_name, park_name, persona) main_summary
                        left join
                            (select mundane_id, sum(monthly_credits) as total_monthly_credits
                                from
                                    (select
                							least(sum(credits), " . mysql_real_escape_string($request['MonthlyCreditMaximum']) . ") as monthly_credits, a.mundane_id
            							from ork_attendance a
            								left join ork_mundane m on a.mundane_id = m.mundane_id
            							where
														m.suspended = 0 and date > '$per_period' $location $waiver_clause $park_comparator
            							group by date_month, date_year, mundane_id) monthly_list
                                group by monthly_list.mundane_id) monthly_summary on main_summary.mundane_id = monthly_summary.mundane_id
                        left join
                            (select mundane_id, sum(daily_credits) as daily_credits, sum(rop_limited_credits) as rop_limited_credits
                                from
                                    (select least(" . mysql_real_escape_string($request['MonthlyCreditMaximum']) . ", sum(daily_credits)) as daily_credits, least(" . mysql_real_escape_string($request['MonthlyCreditMaximum']) . ", sum(rop_credits)) rop_limited_credits, mundane_id
                                        from
                                            (select
                        							max(credits) as daily_credits, 1 as rop_credits, a.mundane_id, a.date, a.date_month
                    							from ork_attendance a
                    								left join ork_mundane m on a.mundane_id = m.mundane_id
                    							where
																		m.suspended = 0 and date > '$per_period' $location $waiver_clause $park_comparator
                    							group by date, date_year, mundane_id) credit_list_source
                					    group by mundane_id, date_month) credit_list
                                group by credit_list.mundane_id) credit_counts on main_summary.mundane_id = credit_counts.mundane_id
                        left join
                          (select
										          count(local_park_week_count.attendance_id) as local_park_weeks, local_park_week_count.mundane_id
									          from 
                              (select max(a.attendance_id) as attendance_id, a.mundane_id as mundane_id 
                                from ork_attendance a
                                  left join ork_mundane m on a.mundane_id = m.mundane_id
                                where
                                  m.park_id = a.park_id
    										          and date > '$per_period'
                                  and m.mundane_id > 0
                                  $location
                                  $park_comparator
                                group by a.date_year, a.date_week3, a.mundane_id) local_park_week_count
                            group by local_park_week_count.mundane_id) park_local_attendance on main_summary.mundane_id = park_local_attendance.mundane_id
					";
					// For last join, need to limit monthly credits to monthly credit maximum per kingdom config
		logtrace('Report: GetActivePlayers', array($request,$sql));
		$r = $this->db->query($sql);
		$report = array();
		if ($r !== false && $r->size() > 0) while ($r->next()) {
			$report[] = array(
					'KingdomName' => $r->kingdom_name,
					'KingdomId' => $r->kingdom_id,
					'ParentKingdomId' => $r->parent_kingodm_id,
					'ParkName' => $r->park_name,
					'ParkId' => $r->park_id,
					'Persona' => $r->persona,
					'MundaneId' => $r->mundane_id,
					'TotalCredits' => $r->total_credits,
    				'TotalMonthlyCredits' => $r->total_monthly_credits,
					'WeeksAttended' => $r->weeks_attended,
    				'LocalParkWeeksAttended' => $r->local_park_weeks,
    				'ParkDaysAttended' => $r->park_days_attended,
        			'DaysAttended' => $r->days_attended,
        			'DailyCredits' => $r->daily_credits,
        			'RopLimitedCredits' => $r->rop_limited_credits,
					'DuesPaid' => $r->duespaid,
					'Waivered' => $r->waivered
				);
		}

		$response = array(
			'Status' => Success(),
			'ActivePlayerSummary' => $report
		);

		return $response;
	}

	public function GetReeveQualified($request) {
		if (valid_id($request['KingdomId'])) $where = "and k.kingdom_id = '$request[KingdomId]'";
		if (valid_id($request['ParkId'])) $where = "and p.park_id = '$request[ParkId]'";

		$sql = "select m.persona, m.mundane_id, m.reeve_qualified_until, k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id, p.park_id, p.name as park_name
					from " . DB_PREFIX . "mundane m
						left join " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "park p on m.park_id = p.park_id
					where
						m.suspended = 0 
						and m.reeve_qualified = 1
						and m.reeve_qualified_until >= CAST(CURRENT_TIMESTAMP AS DATE) 
						$where
					order by m.kingdom_id, m.park_id, m.persona";
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['ReeveQualified'] = array();
			while ($r->next()) {
				$response['ReeveQualified'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'ReeveQualifiedUntil' => $r->reeve_qualified_until,
						'ParkId' => $r->park_id,
						'ParkName' => $r->park_name,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'KingdomName' => $r->kingdom_name
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function GetCorporaQualified($request) {
		if (valid_id($request['KingdomId'])) $where = "and k.kingdom_id = '$request[KingdomId]'";
		if (valid_id($request['ParkId'])) $where = "and p.park_id = '$request[ParkId]'";

		$sql = "select m.persona, m.mundane_id, m.corpora_qualified_until, k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id, p.park_id, p.name as park_name
					from " . DB_PREFIX . "mundane m
						left join " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "park p on m.park_id = p.park_id
					where
						m.suspended = 0 
						and m.corpora_qualified = 1
						and m.corpora_qualified_until >= CAST(CURRENT_TIMESTAMP AS DATE) 
						$where
					order by m.kingdom_id, m.park_id, m.persona";
		$r = $this->db->query($sql);
		$response = array();
		if ($r !== false && $r->size() > 0) {
			$response['CorporaQualified'] = array();
			while ($r->next()) {
				$response['CorporaQualified'][] = array(
						'MundaneId' => $r->mundane_id,
						'Persona' => $r->persona,
						'CorporaQualifiedUntil' => $r->corpora_qualified_until,
						'ParkId' => $r->park_id,
						'ParkName' => $r->park_name,
						'KingdomId' => $r->kingdom_id,
						'ParentKingdomId' => $r->parent_kingodm_id,
						'KingdomName' => $r->kingdom_name
					);
			}
			$response['Status'] = Success();
		} else {
			$response['Status'] = InvalidParameter();
		}
		return $response;
	}

	public function GetDuesPaidList($request) {
		$response = array();
		$where = '';
		if (!empty($request['Type']) && valid_id($request['Id'])) {
			switch ($request['Type']) {
				case 'Kingdom':
					$where = ' AND d.kingdom_id = ' . mysql_real_escape_string($request['Id']);
					break;
				case 'Park':
					$where = ' AND d.park_id = ' . mysql_real_escape_string($request['Id']);
					break;
			}
		} else {
			// Only process park and kingdom reqeusts.
			 return [];
		}

		if (strlen($request['Token']) > 0
			&& ($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
			&& Ork3::$Lib->authorization->HasAuthority($mundane_id, $request['Type'], $request['Id'], AUTH_EDIT)) {
			// Unrestrict data when we have an authorized player
			$restrict_access = false;
		} else {
			$restrict_access = true;
		}

		$sql = "select 
				d.dues_id, 
				d.mundane_id, 
				d.kingdom_id, 
				d.park_id, 
				d.created_on, 
				d.created_by, 
				d.dues_from, 
				d.terms, 
				MAX(d.dues_until) as dues_until, 
				d.dues_closed_from, 
				d.dues_for_life, 
				d.revoked, 
				d.revoked_on, 
				d.revoked_by, 
				d.import_transaction_id, 
				m.persona,";
		$sql .= (!$restrict_access) ? ' m.surname, m.given_name,':'NULL as surname, NULL as given_name,';

		$sql .= "m.suspended, 
				m.waivered,
				k.name as kingdom_name, 
				p.name as park_name
			from " . DB_PREFIX . "dues d
			left join " . DB_PREFIX . "mundane m on d.mundane_id = m.mundane_id
			left join " . DB_PREFIX . "kingdom k on d.kingdom_id = k.kingdom_id
			left join " . DB_PREFIX . "park p on d.park_id = p.park_id
			where 
				d.revoked = 0
				AND (d.dues_until >= CAST(CURRENT_TIMESTAMP AS DATE) OR d.dues_for_life = 1)";
			$sql .= $where;
			$sql .= "  group by d.mundane_id order by m.kingdom_id ASC, m.park_id ASC, m.persona ASC, d.dues_until DESC";

		$r = $this->db->query($sql);
		$response = array();
		$kingdom = new Model_Kingdom();
		$park = new Model_Park();
		if ($r !== false && $r->size() > 0) {
			while ($r->next()) {
				$response['DuesPaidList'][] = array(
						'DuesId' => $r->dues_id,
						'KingdomId' => $r->kingdom_id,
						'KingdomName' => $kingdom->get_kingdom_name($r->kingdom_id),
						'Persona' => $r->persona,
						'GivenName' => $r->given_name,
						'Surname' => $r->surname,
						'MundaneId' => $r->mundane_id,
						'Waivered' => $r->waivered,
						'ParkId' => $r->park_id,
						'ParkName' => $park->get_park_name($r->park_id),
						'DuesUntil' => $r->dues_until,
						'DuesFrom' => $r->dues_from,
						'DuesForLife' => $r->dues_for_life,
						'Revoked' => $r->revoked
					);
			}
			$response['Status'] = Success();
			$response['RestrictAccess'] = $restrict_access; 
		}
        return $response;
	}

	/* ------------------------------------------------------------------ */
	/*  Park Attendance Explorer helpers & methods                        */
	/* ------------------------------------------------------------------ */

	private function _periodExpr($period, $alias = 'a') {
		switch ($period) {
			case 'Weekly':
				return "CONCAT({$alias}.date_year, '-W', LPAD({$alias}.date_week3, 2, '0'))";
			case 'Monthly':
				return "CONCAT({$alias}.date_year, '-', LPAD({$alias}.date_month, 2, '0'))";
			case 'Quarterly':
				return "CONCAT({$alias}.date_year, '-Q', CEIL({$alias}.date_month / 3))";
			case 'Annually':
				return "CAST({$alias}.date_year AS CHAR)";
			default:
				return "CONCAT({$alias}.date_year, '-', LPAD({$alias}.date_month, 2, '0'))";
		}
	}

	public function ParkAttendanceAllParks($request) {
		$cache_key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cache_key, 300)) !== false)
			return $cache;

		$kingdom_id = mysql_real_escape_string($request['KingdomId']);
		$start_date = mysql_real_escape_string($request['StartDate']);
		$end_date   = mysql_real_escape_string($request['EndDate']);
		$period_expr = $this->_periodExpr($request['Period']);

		// Main query: per-park per-period aggregates
		$sql = "SELECT
					p.park_id,
					p.name as park_name,
					$period_expr as period_label,
					COUNT(*) as total_signins,
					COUNT(DISTINCT a.mundane_id) as unique_players,
					COUNT(DISTINCT CASE WHEN m.park_id = a.park_id THEN a.mundane_id END) as unique_members,
					COUNT(DISTINCT CONCAT(a.date_year, '-', a.date_week3)) as weeks_in_period,
					COUNT(DISTINCT CONCAT(a.date_year, '-', a.date_month)) as months_in_period
				FROM " . DB_PREFIX . "attendance a
					INNER JOIN " . DB_PREFIX . "park p ON a.park_id = p.park_id
					LEFT JOIN " . DB_PREFIX . "mundane m ON a.mundane_id = m.mundane_id
				WHERE a.kingdom_id = '$kingdom_id'
					AND a.date >= '$start_date'
					AND a.date <= '$end_date'
					AND a.park_id > 0
					AND p.active = 'Active'
				GROUP BY p.park_id, period_label
				ORDER BY p.name, period_label";

		$r = $this->db->query($sql);
		$response = array('Status' => Success(), 'Attendance' => array());
		if ($r !== false && $r->size() > 0) {
			do {
				$response['Attendance'][] = array(
					'ParkId' => $r->park_id,
					'ParkName' => $r->park_name,
					'PeriodLabel' => $r->period_label,
					'TotalSignins' => $r->total_signins,
					'UniquePlayers' => $r->unique_players,
					'UniqueMembers' => $r->unique_members,
					'WeeksInPeriod' => $r->weeks_in_period,
					'MonthsInPeriod' => $r->months_in_period
				);
			} while ($r->next());
		}

		// Second query: count of park members with 2+/3+/4+ sign-ins per park per period
		$sql2 = "SELECT
					sub.park_id,
					sub.period_label,
					SUM(CASE WHEN sub.cnt >= 2 THEN 1 ELSE 0 END) as members_2plus,
					SUM(CASE WHEN sub.cnt >= 3 THEN 1 ELSE 0 END) as members_3plus,
					SUM(CASE WHEN sub.cnt >= 4 THEN 1 ELSE 0 END) as members_4plus
				FROM (
					SELECT a.park_id, $period_expr as period_label, a.mundane_id, COUNT(*) as cnt
					FROM " . DB_PREFIX . "attendance a
						INNER JOIN " . DB_PREFIX . "park p ON a.park_id = p.park_id
						INNER JOIN " . DB_PREFIX . "mundane m ON a.mundane_id = m.mundane_id
							AND m.park_id = a.park_id
					WHERE a.kingdom_id = '$kingdom_id'
						AND a.date >= '$start_date'
						AND a.date <= '$end_date'
						AND a.park_id > 0
						AND p.active = 'Active'
					GROUP BY a.park_id, period_label, a.mundane_id
				) sub
				GROUP BY sub.park_id, sub.period_label";

		$r2 = $this->db->query($sql2);
		$membercounts = array();
		if ($r2 !== false && $r2->size() > 0) {
			do {
				$membercounts[$r2->park_id . '|' . $r2->period_label] = array(
					'Members2Plus' => $r2->members_2plus,
					'Members3Plus' => $r2->members_3plus,
					'Members4Plus' => $r2->members_4plus
				);
			} while ($r2->next());
		}

		// Attach member counts to each row
		foreach ($response['Attendance'] as &$row) {
			$lookup = $row['ParkId'] . '|' . $row['PeriodLabel'];
			$row['Members2Plus'] = isset($membercounts[$lookup]) ? $membercounts[$lookup]['Members2Plus'] : 0;
			$row['Members3Plus'] = isset($membercounts[$lookup]) ? $membercounts[$lookup]['Members3Plus'] : 0;
			$row['Members4Plus'] = isset($membercounts[$lookup]) ? $membercounts[$lookup]['Members4Plus'] : 0;
		}
		unset($row);

		logtrace("Report->ParkAttendanceAllParks()", array($this->db->lastSql, $request));
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cache_key, $response);
	}

	public function ParkAttendanceSinglePark($request) {
		$key = Ork3::$Lib->ghettocache->key($request);
		if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false)
			return $cache;

		$park_id    = mysql_real_escape_string($request['ParkId']);
		$kingdom_id = mysql_real_escape_string($request['KingdomId']);
		$start_date = mysql_real_escape_string($request['StartDate']);
		$end_date   = mysql_real_escape_string($request['EndDate']);
		$min_signins = intval($request['MinimumSignIns']);
		$period_expr = $this->_periodExpr($request['Period']);

		$local_only = !empty($request['LocalPlayersOnly']);
		$local_filter = $local_only ? "AND m.park_id = '$park_id'" : '';

		$min_filter = '';
		if ($min_signins > 0) {
			$min_filter = "AND a.mundane_id IN (
				SELECT a2.mundane_id
				FROM " . DB_PREFIX . "attendance a2" .
				($local_only ? " INNER JOIN " . DB_PREFIX . "mundane m2 ON a2.mundane_id = m2.mundane_id AND m2.park_id = '$park_id'" : "") . "
				WHERE a2.park_id = '$park_id'
					AND a2.kingdom_id = '$kingdom_id'
					AND a2.date >= '$start_date'
					AND a2.date <= '$end_date'
					AND a2.mundane_id > 0
				GROUP BY a2.mundane_id
				HAVING COUNT(*) >= $min_signins
			)";
		}

		$sql = "SELECT
					a.mundane_id,
					m.persona,
					m.waivered,
					$period_expr as period_label,
					COUNT(*) as signin_count,
					MAX(d.dues_until) as dues_until,
					MAX(d.dues_for_life) as dues_for_life
				FROM " . DB_PREFIX . "attendance a
					LEFT JOIN " . DB_PREFIX . "mundane m ON a.mundane_id = m.mundane_id
					LEFT JOIN " . DB_PREFIX . "dues d ON d.mundane_id = a.mundane_id
						AND d.kingdom_id = a.kingdom_id
						AND d.revoked != 1
						AND (d.dues_for_life = 1 OR d.dues_until >= CURDATE())
				WHERE a.park_id = '$park_id'
					AND a.kingdom_id = '$kingdom_id'
					AND a.date >= '$start_date'
					AND a.date <= '$end_date'
					AND a.mundane_id > 0
					$local_filter
					$min_filter
				GROUP BY a.mundane_id, period_label
				ORDER BY m.persona, period_label";

		$r = $this->db->query($sql);
		$response = array('Status' => Success(), 'Attendance' => array());
		if ($r !== false && $r->size() > 0) {
			do {
				// Determine dues paid status
				$dues_paid = null;
				if ($r->dues_for_life == 1) {
					$dues_paid = 'Life';
				} else if ($r->dues_until && $r->dues_until >= date('Y-m-d')) {
					$dues_paid = $r->dues_until;
				}

				$response['Attendance'][] = array(
					'MundaneId' => $r->mundane_id,
					'Persona' => $r->persona,
					'Waivered' => $r->waivered,
					'DuesPaid' => $dues_paid,
					'PeriodLabel' => $r->period_label,
					'SignInCount' => $r->signin_count
				);
			} while ($r->next());
		}

		logtrace("Report->ParkAttendanceSinglePark()", array($this->db->lastSql, $request));
		return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
	}
}

?>
