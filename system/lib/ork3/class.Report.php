<?php

/*************************************************************************

Here be dragons.

The Report class.

I have no apologies for the following code.  It works well enough.

*************************************************************************/

class Report extends Ork3
{
    public function __construct()
    {
        parent::__construct();
    }

    public function HeraldryReport($request)
    {
        // WithMissingHeraldries [No, Yes, Only]
        $response = array();

        // Unified handling for all heraldry types
        $table = strtolower($request['Type']);
        $$table = new yapo($this->db, DB_PREFIX . $table);

        if ($request['WithMissingHeraldries'] == 'No') {
            $$table->has_heraldry = 1;
        }
        if ($request['WithMissingHeraldries'] == 'Only') {
            $$table->has_heraldry = 0;
        }
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

    public function TournamentReport($request)
    {

        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 1800)) !== false) {
            return $cache;
        }

        if (valid_id($request['KingdomId'])) {
            $where .= " and t.kingdom_id = $request[KingdomId] or e.kingdom_id = $request[KingdomId]";
        }
        if (valid_id($request['ParkId'])) {
            $where .= " and t.park_id = $request[ParkId] or e.park_id = $request[ParkId]";
        }
        if (valid_id($request['EventId'])) {
            $where .= " and e.event_id = $request[EventId]";
        }
        if (valid_id($request['EventCalendarDetailId'])) {
            $where .= " and d.event_calendardetail_id = $request[EventCalendarDetailId]";
        }

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

        if (valid_id($request['Limit'])) {
            $limit = " limit " . mysql_real_escape_string($request['Limit']);
        }

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
					group by t.tournament_id
					order by t.date_time
					$limit";

        $r = $this->db->query($sql);
        $response = array();
        if ($r !== false) {
            $response['Tournaments'] = array();
            if ($r->size() > 0) {
                while ($r->next()) {
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

    public function ClassMasters($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false) {
            return $cache;
        }

        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location_clause = " and m.kingdom_id IN ($kidList)";
        } else {
            // Kingdom-wide view: sort kingdom, then park, then persona.
            $order = "k.name, p.name, m.persona, ";
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

    public function CrownQualed($kingdom_id)
    {
        $key = Ork3::$Lib->ghettocache->key(array('KingdomId' => $kingdom_id));
        if (!valid_id($kingdom_id)) {
            return false;
        }
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false) {
            return $cache;
        }

        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id)));

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
                    where crown_points > 0 and m.kingdom_id IN ($kidList) and peerage = 'None'
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
                    where crown_points > 0 and m.kingdom_id IN ($kidList) and peerage = 'Kingdom-Level-Award'
                    group by m.mundane_id, a.award_id) kterms
                  group by mundane_id, peerage) kingdom_terms
                on m.mundane_id = kingdom_terms.mundane_id
              where m.kingdom_id IN ($kidList) and (ducal_terms.mundane_id is not null or kingdom_terms.mundane_id is not null)
                and (kingdom_points >= 4 or (kingdom_points + ducal_points) >= 6 or ducal_points >= 6)
                order by m.mundane_id";
        logtrace("CrownQualedPlayerAwards", $sql);
        $r = $this->db->query($sql);
        $response = array();
        if ($r !== false && $r->size() > 0) {
            $response['Awards'] = array();
            while ($r->next()) {
                $name = array();
                if ($r->kingdom_points > 0) {
                    $name[] = $r->kingdom_points . ' Kingdom Points';
                }
                if ($r->ducal_points > 0 || $r->kingdom_points) {
                    $name[] = ($r->ducal_points + $r->kingdom_points) . ' Ducal Points';
                }
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

    public function PlayerAwards($request)
    {

        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false) {
            return $cache;
        }

        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location_clause = " and m.kingdom_id IN ($kidList)";
        } else {
            $order = "k.name, ";
        }
        if (valid_id($request['ParkId'])) {
            $location_clause = " and m.park_id = $request[ParkId]";
        }
        // Custom Titles aliased to a peerage award (e.g. Knight of the Sword) need
        // to surface in these reports as if they were the alias target. Use
        // COALESCE(alias.col, a.col) anywhere we read peerage / is_ladder / is_title.
        if (valid_id($request['IncludeKnights'])) {
            $knights_clause = "or COALESCE(alias.peerage, a.peerage) = 'Knight'";
        }
        if (valid_id($request['IncludeMasters'])) {
            $masters_clause = "or COALESCE(alias.peerage, a.peerage) = 'Master'";
        }
        if (valid_id($request['IncludeLadder']) && is_numeric($request['LadderMinimum'])) {
            $ladder_clause = " or (COALESCE(alias.is_ladder, a.is_ladder) = 1 and ma.rank >= $request[LadderMinimum])";
        }
        if (valid_id($request['IncludeTitles'])) {
            $title_clause =  "or COALESCE(alias.is_title, a.is_title) = 1";
        }
        if (is_array($request['Awards'])) {
            $awards_clause = 'and in (' . implode(',', $request['Awards']) . ')';
        }
        $sql = "select
              distinct p.park_id, p.name as park_name,
              k.kingdom_id, k.name as kingdom_name, k.parent_kingdom_id,
              COALESCE(alias.peerage, a.peerage) as peerage,
              COALESCE(NULLIF(ma.custom_name, ''), ka.name, alias.name, a.name) as award_name,
              m.persona, ma.date, m.mundane_id, ma.rank,
              bwm.mundane_id as by_whom_id, bwm.persona as by_whom_persona,
              ma.awards_id
					from " . DB_PREFIX . "awards ma
						left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = ma.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
						left join " . DB_PREFIX . "award alias on alias.award_id = ma.alias_award_id
								left join " . DB_PREFIX . "mundane m on m.mundane_id = ma.mundane_id
								left join " . DB_PREFIX . "mundane bwm on bwm.mundane_id = ma.by_whom_id
									left join " . DB_PREFIX . "park p on p.park_id = m.park_id
									left join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
					where (0 $knights_clause $masters_clause $ladder_clause $title_clause) and m.active = 1 $location_clause $awards_clause
					order by $order COALESCE(alias.peerage, a.peerage), COALESCE(alias.name, a.name), m.persona
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
                        'Peerage' => $r->peerage,
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

    public function CustomAwards($request)
    {

        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 60)) !== false) {
            return $cache;
        }

        $location_clause = '';
        $order = '';
        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location_clause = " and m.kingdom_id IN (" . $kidList . ")";
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

    public function PlayerAwardRecommendations($request)
    {

        // Cache keyed to the player being viewed — shared across all viewers.
        // Viewer-specific flags (ViewerCanSecond, ViewerCanEditReason, IsMine) are
        // computed after the cache hit so one bust clears the data for everyone.
        $viewer_id = (int)($request['RequestedBy'] ?? 0);
        $key = Ork3::$Lib->ghettocache->key([
            'KingdomId' => (int)($request['KingdomId'] ?? 0),
            'ParkId'    => (int)($request['ParkId']    ?? 0),
            'PlayerId'  => (int)($request['PlayerId']  ?? 0),
        ]);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $this->applyViewerFlags($cache, $viewer_id);
        }

        if (valid_id($request['KingdomId'])) {
            // Roll up principalities when the kingdom's IncludePrincipalityInStatistics
            // flag is on. Originally pulled back because the inlined rendering was
            // blocking DOMContentLoaded, but the tab is now lazy-loaded so the row
            // count no longer affects initial paint.
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location_clause = " AND m.kingdom_id IN ($kidList)";
        }
        if (valid_id($request['ParkId'])) {
            $location_clause = " AND m.park_id = $request[ParkId]";
        }
        if (valid_id($request['PlayerId'])) {
            $location_clause = " AND recs.mundane_id = $request[PlayerId]";
        }

        $sql = "select
			a.peerage, ifnull(ka.name, a.name) as award_name,
			a.is_ladder as a_is_ladder,
			a.is_title  as a_is_title,
			m.persona,
			recs.date_recommended,
			m.mundane_id,
			m.park_id,
			m.kingdom_id,
			p.name as park_name,
			k.name as kingdom_name,
			recs.rank,
			rbi.mundane_id as recommended_by_id, rbi.persona as recommended_by_persona,
			recs.recommendations_id,
			recs.award_id,
			recs.reason,
			recs.mask_giver,
			recs.deleted_at,
			recs.deleted_by,
			ka.award_id as ka_award_id,
			ka.kingdomaward_id as ka_kaward_id,
			(SELECT COUNT(suboa.awards_id) FROM " . DB_PREFIX . "awards suboa WHERE suboa.mundane_id = recs.mundane_id AND suboa.kingdomaward_id = ka.kingdomaward_id AND suboa.rank >= COALESCE(recs.rank, 0)) as kacount,
			(SELECT COUNT(suboa2.awards_id) FROM " . DB_PREFIX . "awards suboa2 WHERE suboa2.mundane_id = recs.mundane_id AND suboa2.award_id = recs.award_id AND suboa2.rank >= COALESCE(recs.rank, 0)) as awcount,
			COALESCE(
				(SELECT MAX(subr.rank) FROM " . DB_PREFIX . "awards subr WHERE subr.mundane_id = recs.mundane_id AND subr.kingdomaward_id = ka.kingdomaward_id),
				(SELECT MAX(subr2.rank) FROM " . DB_PREFIX . "awards subr2 WHERE subr2.mundane_id = recs.mundane_id AND subr2.award_id = recs.award_id)
			) as player_ka_rank,
			COALESCE(
				(SELECT subr.date FROM " . DB_PREFIX . "awards subr WHERE subr.mundane_id = recs.mundane_id AND subr.kingdomaward_id = ka.kingdomaward_id ORDER BY subr.rank DESC, subr.date DESC LIMIT 1),
				(SELECT subr2.date FROM " . DB_PREFIX . "awards subr2 WHERE subr2.mundane_id = recs.mundane_id AND subr2.award_id = recs.award_id ORDER BY subr2.rank DESC, subr2.date DESC LIMIT 1)
			) as player_ka_date
			FROM " . DB_PREFIX . "recommendations recs			
			LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = recs.kingdomaward_id
			LEFT JOIN " . DB_PREFIX . "award a on a.award_id = ka.award_id
			LEFT join " . DB_PREFIX . "mundane m on m.mundane_id = recs.mundane_id
			LEFT join " . DB_PREFIX . "mundane rbi on rbi.mundane_id = recs.recommended_by_id
			LEFT join " . DB_PREFIX . "park p on p.park_id = m.park_id
			LEFT join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
			WHERE (recs.deleted_by IS NULL OR recs.deleted_by = 0) $location_clause
			order by m.persona, a.name, recs.rank, m.persona";
        $r = $this->db->query($sql);
        $response = array();
        if ($r !== false && $r->size() > 0) {
            // First pass: collect raw rows + rec_ids/meta (for seconds wiring below)
            // plus the set of (mundane_id, ladder_award_id) we'll need Master-peerage lookups for.
            $rawRows = array();
            $rec_ids = array();
            $rec_meta_by_id = array();  // for ViewerCanSecond eligibility check
            $ladderMap = Award::GetLadderMasterMap();
            $masterIdSet = array();
            foreach ($ladderMap as $lInfo) {
                foreach ((array)$lInfo['MasterAwardIds'] as $mAid) {
                    $masterIdSet[(int)$mAid] = true;
                }
            }
            $needMundaneIds = array();
            while ($r->next()) {
                $row = (object)[
                    'recommendations_id' => $r->recommendations_id,
                    'mundane_id'         => (int)$r->mundane_id,
                    'persona'            => $r->persona,
                    'date_recommended'   => $r->date_recommended,
                    'rank'               => $r->rank,
                    'award_name'         => $r->award_name,
                    'reason'             => $r->reason,
                    'recommended_by_persona' => $r->recommended_by_persona,
                    'recommended_by_id'      => $r->recommended_by_id,
                    'mask_giver'         => (int)$r->mask_giver,
                    'ka_kaward_id'       => (int)$r->ka_kaward_id,
                    'ka_award_id'        => (int)$r->ka_award_id,
                    'recs_award_id'      => (int)$r->award_id,
                    'park_id'            => $r->park_id,
                    'kingdom_id'         => $r->kingdom_id,
                    'park_name'          => $r->park_name,
                    'kingdom_name'       => $r->kingdom_name,
                    'kacount'            => (int)$r->kacount,
                    'awcount'            => (int)$r->awcount,
                    'player_ka_rank'     => (int)$r->player_ka_rank,
                    'player_ka_date'     => $r->player_ka_date,
                    'a_is_ladder'        => (int)$r->a_is_ladder,
                    'a_is_title'         => (int)$r->a_is_title,
                ];
                $recAwardId = $row->ka_award_id ?: $row->recs_award_id;
                if (isset($ladderMap[$recAwardId])) {
                    $needMundaneIds[$row->mundane_id] = true;
                }
                $rid = (int)$row->recommendations_id;
                $rec_ids[] = $rid;
                $rec_meta_by_id[$rid] = array(
                    'mundane_id' => $row->mundane_id,
                    'recommended_by_id' => (int)$row->recommended_by_id,
                    'kingdomaward_id' => $row->ka_kaward_id,
                    'rank' => (int)$row->rank,
                );
                $rawRows[] = $row;
            }

            // Second pass: batch-fetch Master-peerage holdings for any mundanes whose recs target a ladder.
            $heldMasters = array(); // mundane_id => [master_award_id => true]
            if (!empty($needMundaneIds) && !empty($masterIdSet)) {
                $midCsv = implode(',', array_map('intval', array_keys($needMundaneIds)));
                $maCsv  = implode(',', array_map('intval', array_keys($masterIdSet)));
                $mRes = $this->db->query(
                    "SELECT mundane_id, award_id FROM " . DB_PREFIX . "awards
					 WHERE mundane_id IN ({$midCsv}) AND award_id IN ({$maCsv})"
                );
                if ($mRes !== false && $mRes->size() > 0) {
                    while ($mRes->next()) {
                        $heldMasters[(int)$mRes->mundane_id][(int)$mRes->award_id] = true;
                    }
                }
            }

            // Final pass: build response, flipping AlreadyHas when a Master peerage covers a ladder rec.
            // Custom awards (base Award with is_ladder=0 AND is_title=0) can legitimately be held many
            // times, so they must never be filtered out as "already has".
            $response['AwardRecommendations'] = array();
            foreach ($rawRows as $row) {
                $recAwardId = $row->ka_award_id ?: $row->recs_award_id;
                $isCustom   = ($row->a_is_ladder === 0 && $row->a_is_title === 0);
                $alreadyHas = $isCustom ? false : ($row->kacount > 0 || $row->awcount > 0);
                $coveredByMaster = false;
                if (!$isCustom && !$alreadyHas && isset($ladderMap[$recAwardId])) {
                    foreach ((array)$ladderMap[$recAwardId]['MasterAwardIds'] as $mAid) {
                        if (!empty($heldMasters[$row->mundane_id][(int)$mAid])) {
                            $alreadyHas = true;
                            $coveredByMaster = true;
                            break;
                        }
                    }
                }
                $response['AwardRecommendations'][] = array(
                    'RecommendationsId' => $row->recommendations_id,
                    'MundaneId' => $row->mundane_id,
                    'Persona' => $row->persona,
                    'DateRecommended' => $row->date_recommended,
                    'Rank' => $row->rank,
                    'AwardName' => $row->award_name,
                    'Reason' => $row->reason,
                    'RecommendedByName' => $row->recommended_by_persona,
                    'RecommendedById' => $row->recommended_by_id,
                    'MaskGiver' => $row->mask_giver,
                    'KingdomAwardId' => $row->ka_kaward_id,
                    'AwardId' => $recAwardId,
                    'ParkId' => $row->park_id,
                    'KingdomId' => $row->kingdom_id,
                    'ParkName' => $row->park_name,
                    'KingdomName' => $row->kingdom_name,
                    'AlreadyHas' => $alreadyHas,
                    'CoveredByMaster' => $coveredByMaster,
                    'CurrentRank' => $alreadyHas ? ($row->player_ka_rank ?: null) : null,
                    'CurrentRankDate' => $alreadyHas ? $row->player_ka_date : null,
                    'Seconds' => array(),
                    'SecondsCount' => 0,
                    'ViewerCanSecond' => false,
                    'ViewerCanEditReason' => false,
                );
            }

            // Batch-fetch all active seconds (IsMine = false; applyViewerFlags sets it per-viewer).
            $seconds_by_rec = Ork3::$Lib->player->GetSecondsForRecommendations($rec_ids, 0);

            // Attach seconds; viewer flags are left false and filled in by applyViewerFlags.
            foreach ($response['AwardRecommendations'] as &$rec) {
                $rid = (int)$rec['RecommendationsId'];
                $rec['Seconds']      = isset($seconds_by_rec[$rid]) ? $seconds_by_rec[$rid] : array();
                $rec['SecondsCount'] = count($rec['Seconds']);
            }
            unset($rec);

            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter();
        }
        $cached = Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
        return $this->applyViewerFlags($cached, $viewer_id);
    }

    // Compute viewer-specific flags on top of a cached (viewer-agnostic) recommendations
    // response. IsMine and ViewerCanEditReason need no DB queries. ViewerCanSecond needs
    // two lightweight IN-list lookups.
    private function applyViewerFlags(array $response, int $viewer_id): array
    {
        if ($viewer_id <= 0 || empty($response['AwardRecommendations'])) {
            return $response;
        }

        $rec_ids  = array_map(function ($r) {
            return (int)$r['RecommendationsId'];
        }, $response['AwardRecommendations']);
        $rid_list = implode(',', $rec_ids);

        // Viewer's own active primary recs on the same (mundane, award, rank) slots.
        $viewer_own_keys = array();
        $this->db->Clear();
        $or = $this->db->query(
            "SELECT DISTINCT CONCAT(r2.mundane_id,'|',r2.kingdomaward_id,'|',COALESCE(r2.rank,0)) AS k
			 FROM " . DB_PREFIX . "recommendations r2
			 WHERE r2.recommended_by_id = $viewer_id AND r2.deleted_at IS NULL
			   AND (r2.mundane_id, r2.kingdomaward_id, COALESCE(r2.rank,0)) IN (
				   SELECT r3.mundane_id, r3.kingdomaward_id, COALESCE(r3.rank,0)
				   FROM " . DB_PREFIX . "recommendations r3
				   WHERE r3.recommendations_id IN ($rid_list)
			   )"
        );
        if ($or !== false && $or->size() > 0) {
            while ($or->next()) {
                $viewer_own_keys[$or->k] = true;
            }
        }

        // Recs the viewer has already seconded.
        $viewer_seconded = array();
        $this->db->Clear();
        $sr = $this->db->query(
            "SELECT recommendations_id FROM " . DB_PREFIX . "recommendation_seconds
			 WHERE supporter_mundane_id = $viewer_id AND deleted_at IS NULL
			   AND recommendations_id IN ($rid_list)"
        );
        if ($sr !== false && $sr->size() > 0) {
            while ($sr->next()) {
                $viewer_seconded[(int)$sr->recommendations_id] = true;
            }
        }

        foreach ($response['AwardRecommendations'] as &$rec) {
            $rid    = (int)$rec['RecommendationsId'];
            $ownKey = $rec['MundaneId'] . '|' . $rec['KingdomAwardId'] . '|' . (int)($rec['Rank'] ?? 0);
            $rec['ViewerCanEditReason'] = ($viewer_id === (int)$rec['RecommendedById']);
            $rec['ViewerCanSecond'] = (
                $viewer_id !== (int)$rec['MundaneId']
                && $viewer_id !== (int)$rec['RecommendedById']
                && empty($viewer_own_keys[$ownKey])
                && empty($viewer_seconded[$rid])
            );
            foreach ($rec['Seconds'] as &$second) {
                $second['IsMine'] = ((int)$second['SupporterMundaneId'] === $viewer_id);
            }
            unset($second);
        }
        unset($rec);
        return $response;
    }

    // Lightweight "how many active recs for this kingdom does this viewer see"
    // query. Skips the heavy joins / per-row subqueries the full recommendations
    // report runs, so it's cheap enough to call on every kingdom profile load
    // just to render the "Recommendations (N)" tab badge.
    public function PlayerAwardRecommendationsCount($request)
    {
        $kid = (int)($request['KingdomId'] ?? 0);
        if ($kid <= 0) {
            return 0;
        }
        // Match PlayerAwardRecommendations: roll up principalities when the parent
        // kingdom's IncludePrincipalityInStatistics flag is on, so the badge stays
        // in sync with what the lazy-loaded panel actually shows.
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kid)));
        $key = Ork3::$Lib->ghettocache->key([
            'KingdomIds'    => $kidList,
            'RecommendedBy' => (int)($request['RecommendedBy'] ?? 0),
        ]);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return (int)$cache;
        }

        $where = "m.kingdom_id IN ($kidList) AND (recs.deleted_by IS NULL OR recs.deleted_by = 0)";
        if (!empty($request['RecommendedBy'])) {
            $rb = (int)$request['RecommendedBy'];
            $where .= " AND recs.recommended_by_id = $rb";
        }
        $sql = "SELECT COUNT(*) AS n
				FROM " . DB_PREFIX . "recommendations recs
				JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = recs.mundane_id
				WHERE $where";
        $r = $this->db->query($sql);
        $n = 0;
        if ($r !== false && $r->size() > 0 && $r->next()) {
            $n = (int)$r->n;
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $n);
    }

    public function DeletedAwardRecommendations($request)
    {
        $key = Ork3::$Lib->ghettocache->key([
            'KingdomId' => (int)($request['KingdomId'] ?? 0),
            'ParkId'    => (int)($request['ParkId']    ?? 0),
            'PlayerId'  => (int)($request['PlayerId']  ?? 0),
            'Deleted'   => 1,
        ]);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }

        $location_clause = '';
        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location_clause = " AND m.kingdom_id IN (" . $kidList . ")";
        }
        if (valid_id($request['ParkId'])) {
            $location_clause = " AND m.park_id = " . (int)$request['ParkId'];
        }
        if (valid_id($request['PlayerId'])) {
            $location_clause = " AND recs.mundane_id = " . (int)$request['PlayerId'];
        }

        $sql = "select
			a.peerage, ifnull(ka.name, a.name) as award_name,
			m.persona,
			recs.date_recommended,
			m.mundane_id,
			m.park_id,
			m.kingdom_id,
			p.name as park_name,
			k.name as kingdom_name,
			recs.rank,
			rbi.mundane_id as recommended_by_id, rbi.persona as recommended_by_persona,
			recs.recommendations_id,
			recs.award_id,
			recs.reason,
			recs.mask_giver,
			recs.deleted_at,
			recs.deleted_by,
			dm.persona as deleted_by_persona,
			ka.award_id as ka_award_id,
			ka.kingdomaward_id as ka_kaward_id
			FROM " . DB_PREFIX . "recommendations recs
			LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = recs.kingdomaward_id
			LEFT JOIN " . DB_PREFIX . "award a on a.award_id = ka.award_id
			LEFT join " . DB_PREFIX . "mundane m on m.mundane_id = recs.mundane_id
			LEFT join " . DB_PREFIX . "mundane rbi on rbi.mundane_id = recs.recommended_by_id
			LEFT join " . DB_PREFIX . "mundane dm on dm.mundane_id = recs.deleted_by
			LEFT join " . DB_PREFIX . "park p on p.park_id = m.park_id
			LEFT join " . DB_PREFIX . "kingdom k on k.kingdom_id = m.kingdom_id
			WHERE recs.deleted_at IS NOT NULL $location_clause
			order by recs.deleted_at DESC";
        $this->db->Clear();
        $r = $this->db->query($sql);
        $response = ['AwardRecommendations' => []];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['AwardRecommendations'][] = [
                    'RecommendationsId' => (int)$r->recommendations_id,
                    'MundaneId'         => (int)$r->mundane_id,
                    'Persona'           => $r->persona,
                    'DateRecommended'   => $r->date_recommended,
                    'Rank'              => (int)$r->rank,
                    'AwardName'         => $r->award_name,
                    'Reason'            => $r->reason,
                    'RecommendedByName' => $r->recommended_by_persona,
                    'RecommendedById'   => (int)$r->recommended_by_id,
                    'MaskGiver'         => (int)$r->mask_giver,
                    'KingdomAwardId'    => (int)$r->ka_kaward_id,
                    'AwardId'           => (int)($r->ka_award_id ?: $r->award_id),
                    'ParkId'            => (int)$r->park_id,
                    'KingdomId'         => (int)$r->kingdom_id,
                    'ParkName'          => $r->park_name,
                    'KingdomName'       => $r->kingdom_name,
                    'DeletedAt'         => $r->deleted_at,
                    'DeletedById'       => (int)$r->deleted_by,
                    'DeletedByName'     => $r->deleted_by_persona,
                ];
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = Success();
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function Guilds($request)
    {
        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $where = "and k.kingdom_id IN ($kidList)";
        }
        if (valid_id($request['ParkId'])) {
            $where = "and p.park_id = '$request[ParkId]'";
        }
        if (valid_id($request['MundaneId'])) {
            $where = "and m.mundane_id = '$request[MundaneId]'";
        }

        if ($request['PerWeeks'] == 1) {
            $per_period = date("Y-m-d", strtotime("-$request[Periods] week"));
        }
        if ($request['PerMonths'] == 1) {
            $per_period = date("Y-m-d", strtotime("-$request[Periods] month"));
        }

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

    public function UnitSummary($request)
    {
        $cache_key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cache_key, 300)) !== false) {
            return $cache;
        }
        $has_mundane = valid_id($request['MundaneId']);
        if ($has_mundane) {
            $mid = (int)$request['MundaneId'];
        }
        $kidList = valid_id($request['KingdomId'])
            ? implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])))
            : '';
        if (valid_id($request['KingdomId'])) {
            $kingdom = " and m.kingdom_id IN ($kidList)";
        }
        if (valid_id($request['ParkId'])) {
            $park = " and m.park_id = '$request[ParkId]'";
        }
        if (valid_id($request['EventId'])) {
            $event = " and e.event_id = '$request[EventId]'";
        }
        if (valid_id($request['IncludeCompanies'])) {
            $companies = " or u.type = 'Company' ";
        }
        if (valid_id($request['IncludeHouseHolds'])) {
            $households = " or u.type = 'Household' ";
        }
        if (valid_id($request['IncludeEvents'])) {
            $events = " or u.type = 'Event' ";
        }
        if (valid_id($request['ActiveOnly'])) {
            $active_only = " and um.active = 'Active' ";
        }
        // Hide retired units from listings unless explicitly requested.
        $unit_active = valid_id($request['IncludeRetired']) ? '' : " and u.active = 'Active' ";
        if (valid_id($request['KingdomId'])) {
            $activity_scope = " and a.kingdom_id IN ($kidList)";
        } elseif (valid_id($request['ParkId'])) {
            $activity_scope = " and a.park_id = '$request[ParkId]'";
        }

        $name_where = '';
        if (!empty($request['Name'])) {
            $safeName = str_replace(["'", '%', '_', '\\'], ["\'\'", '\\%', '\\_', '\\\\'], $request['Name']);
            $name_where = " and u.name like '%{$safeName}%'";
        }
        $limit_clause = isset($request['Limit']) && is_numeric($request['Limit']) ? 'LIMIT ' . (int)$request['Limit'] : '';
        $allowed_order = ['u.name', 'active_member_count DESC', 'total_member_count DESC'];
        $order_by = (isset($request['OrderBy']) && in_array($request['OrderBy'], $allowed_order))
            ? $request['OrderBy'] : 'u.name';

        // When MundaneId is given (player profile path), fold the player + active
        // filters into the ON clause so the planner enters via ix_um_mundane and
        // only touches that player's unit_mundane rows. Without this, MariaDB
        // full-scans ork_unit (~3.5k rows) before filtering.
        if ($has_mundane) {
            $um_join = "inner join " . DB_PREFIX . "unit_mundane um on u.unit_id = um.unit_id and um.mundane_id = '$mid'" . ($active_only ?? '');
            $active_only_where = '';
        } else {
            $um_join = "left join " . DB_PREFIX . "unit_mundane um on u.unit_id = um.unit_id";
            $active_only_where = $active_only ?? '';
        }

        // Lightweight skips the four per-row attendance/unit_mundane correlated
        // subqueries (TotalMemberCount, LastActivityDate, ActiveMemberCount,
        // LeaderNames) for callers that only render Name/Type/UnitId/UnitMundaneId
        // — Player profile + Admin player. The Unit List and Search pages still
        // need the full projection.
        $lightweight = !empty($request['Lightweight']);
        if ($lightweight) {
            $total_member_count_expr  = 'null';
            $last_activity_date_expr  = 'null';
            $active_member_count_expr = 'null';
            $leader_names_expr        = 'null';
        } else {
            $total_member_count_expr  = "(select count(*) from " . DB_PREFIX . "unit_mundane um2 where um2.unit_id = u.unit_id)";
            $last_activity_date_expr  = "(select max(a.date) from " . DB_PREFIX . "attendance a join " . DB_PREFIX . "unit_mundane um3 on um3.mundane_id = a.mundane_id where um3.unit_id = u.unit_id $activity_scope)";
            $active_member_count_expr = "(select count(distinct um4.mundane_id) from " . DB_PREFIX . "unit_mundane um4 join " . DB_PREFIX . "attendance a4 on a4.mundane_id = um4.mundane_id where um4.unit_id = u.unit_id and a4.date >= date_sub(curdate(), interval 1 year))";
            $leader_names_expr        = "(select group_concat(m5.persona order by m5.persona separator ', ') from " . DB_PREFIX . "unit_mundane um5 join " . DB_PREFIX . "mundane m5 on m5.mundane_id = um5.mundane_id where um5.unit_id = u.unit_id and um5.role in ('captain','lord') and um5.active = 'Active')";
        }

        $top_by_size = !empty($request['TopBySize']);
        if ($top_by_size) {
            // Fast default list (no name filter): cheaply pick the top-N units by
            // roster size for the scope, then attach only the inexpensive detail
            // columns. The attendance subqueries (last_activity_date /
            // active_member_count) are deliberately omitted here — they are what made
            // the unscoped default take ~15s — so this path returns in ~180ms.
            // Activity detail is computed only on the typed-search path below.
            $inner_scope_join  = '';
            $inner_scope_where = '';
            if (valid_id($request['KingdomId'])) {
                $inner_scope_join  = "join " . DB_PREFIX . "mundane m on m.mundane_id = um.mundane_id";
                $inner_scope_where = " and m.kingdom_id IN ($kidList)";
            } elseif (valid_id($request['ParkId'])) {
                $inner_scope_join  = "join " . DB_PREFIX . "mundane m on m.mundane_id = um.mundane_id";
                $inner_scope_where = " and m.park_id = '" . (int)$request['ParkId'] . "'";
            }
            $top_n = (isset($request['Limit']) && is_numeric($request['Limit'])) ? (int)$request['Limit'] : 100;
            // Retired units are excluded unless explicitly requested.
            $inner_unit_active = valid_id($request['IncludeRetired']) ? '' : " and u.active = 'Active'";
            $sql = "select u.unit_id, u.type, u.name, u.has_heraldry, u.url, u.active as unit_active, top.sz as member_count,
						$total_member_count_expr as total_member_count,
						null as last_activity_date,
						null as active_member_count,
						$leader_names_expr as leader_names,
						null as unit_mundane_id, '' as persona
					from (
						select u.unit_id, count(um.mundane_id) sz
							from " . DB_PREFIX . "unit u
								join " . DB_PREFIX . "unit_mundane um on um.unit_id = u.unit_id
								$inner_scope_join
							where (0 $companies $households $events) $inner_scope_where $inner_unit_active
							group by u.unit_id
							order by sz desc
							limit $top_n
					) top
					join " . DB_PREFIX . "unit u on u.unit_id = top.unit_id
					order by top.sz desc";
        } else {
            $sql = "select u.*, m.*, u.has_heraldry, u.active as unit_active, count(um.mundane_id) as member_count,
					$total_member_count_expr as total_member_count,
					$last_activity_date_expr as last_activity_date,
					$active_member_count_expr as active_member_count,
					$leader_names_expr as leader_names,
					um.unit_mundane_id
					from " . DB_PREFIX . "unit u
						$um_join
							left join " . DB_PREFIX . "mundane m on m.mundane_id = um.mundane_id
						left join " . DB_PREFIX . "event e on e.unit_id = u.unit_id
					where 1 and (1 $kingdom $park $event_id $active_only_where $name_where $unit_active) and (0 $companies $households $events)
					group by u.unit_id
				order by $order_by $limit_clause";
        }
        $r = $this->db->query($sql);
        logtrace("Unit Summary", array($request, $sql));
        $response = array( 'Status' => Success(), 'Units' => array());
        if ($r === false) {
            $response['Status'] = InvalidParameter();
        } elseif ($r->size() > 0) {
            while ($r->next()) {
                $response['Units'][] = array(
                    'UnitId'           => $r->unit_id,
                    'Type'             => $r->type,
                    'Name'             => $r->name,
                    'HasHeraldry'      => (int)$r->has_heraldry,
                    'Persona'          => $r->persona,
                    'MemberCount'       => $r->member_count,
                    'TotalMemberCount'  => $r->total_member_count,
                    'LastActivityDate'   => $r->last_activity_date,
                    'ActiveMemberCount'  => (int)$r->active_member_count,
                    'LeaderNames'        => $r->leader_names ?? '',
                    'Url'                => $r->url ?? '',
                    'UnitMundaneId'      => $r->unit_mundane_id,
                    'Active'             => $r->unit_active,
                );
            }
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cache_key, $response);
    }

    public function AttendanceSummary($request)
    {
        if (valid_id($request['EventId'])) {
            $where = "where ssa.event_id = '" . mysql_real_escape_string($request['EventId']) . "'";
        }
        if (valid_id($request['KingdomId'])) {
            $where = "where ssa.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        }
        if (valid_id($request['ParkId'])) {
            $where = "where ssa.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        }
        if (valid_id($request['PrincipalityId'])) {
            $where = "where ssa.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['PrincipalityId']))) . ")";
        }
        if ($request['NativePopulace'] && (valid_id($request['KingdomId']) || valid_id($request['ParkId']))) {
            $where .= " and m.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        }
        if ($request['Waivered']) {
            $where = (strlen($where) > 0) ? " and m.waivered = 1" : "where m.waivered = 1";
        }
        /*
        if (strlen($where) == 0) {
            $response['Status'] = InvalidParameter();
            return $response;
        }
        */
        $report_to = (!empty($request['ReportFromDate']) && $request['ReportFromDate'] !== date('Y-m-d'))
            ? $request['ReportFromDate'] : date('Y-m-d');
        if ($request['PerWeeks'] == 1) {
            $per_period = date("Y-m-d", strtotime("$report_to -$request[Periods] week"));
        }
        if ($request['PerMonths'] == 1) {
            $per_period = date("Y-m-d", strtotime("$report_to -$request[Periods] month"));
        }
        if (!isset($per_period)) {
            $per_period = date("Y-m-d", strtotime("$report_to -$request[Periods] day"));
        }
        switch ($request['ByPeriod']) {
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
                $group_period = 'a.date, a.event_calendardetail_id';
                break;
        }


        $sql = "select max(a.date) as `date`, count(a.mundane_id) as attendees, count(DISTINCT a.mundane_id) as distinct_players, a.event_start, a.event_end, a.event_id, a.event_calendardetail_id, a.event_id, e.name as event_name,
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
						a.date > '$per_period' and a.date <= '$report_to'
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
                        'DistinctPlayers' => $r->distinct_players,
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

    public function AttendanceForEvent($request)
    {
        if (valid_id($request['UnitId'])) {
            $unit_clause = 	"LEFT JOIN " . DB_PREFIX . "unit_mundane um on um.mundane_id = a.mundane_id
								LEFT JOIN " . DB_PREFIX . "unit u on u.unit_id = um.unit_id
							";
            $unit_phrase = "u.name as unit_name, ";
        }

        $sql = "select a.*, k.name as kingdom_name, p.park_id, p.name as park_name, k.abbreviation as k_abbr, p.abbreviation as p_abbr, k.parent_kingdom_id, m.persona, $unit_phrase c.name as class_name
					from " . DB_PREFIX . "attendance a
						LEFT JOIN " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id
							LEFT JOIN " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
							LEFT JOIN " . DB_PREFIX . "park p on m.park_id = p.park_id
						LEFT JOIN " . DB_PREFIX . "class c on a.class_id = c.class_id
						$unit_clause
					where a.event_id = '" . mysql_real_escape_string($request['EventId']) . "' and a.event_calendardetail_id = '" . mysql_real_escape_string($request['EventCalendarDetailId']) . "'
				";
        if (valid_id($request['KingdomId'])) {
            $sql .= " and a.kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
        }
        if (valid_id($request['ParkId'])) {
            $sql .= " and a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        }
        if (valid_id($request['UnitId'])) {
            $sql .= " and a.unit_id = '" . mysql_real_escape_string($request['UnitId']) . "'";
        }
        if (valid_id($request['MundandeId'])) {
            $sql .= " and a.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'";
        }
        if (valid_id($request['ClassId'])) {
            $sql .= " and a.class_id = '" . mysql_real_escape_string($request['ClassId']) . "'";
        }

        logtrace('AttendanceForEvent', array($request, $sql));

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
                        'KAbbr' => $r->k_abbr,
                        'PAbbr' => $r->p_abbr,
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

    public function AttendanceForDate($request)
    {
        if (valid_id($request['UnitId'])) {
            $unit_clause = 	"LEFT JOIN " . DB_PREFIX . "unit_mundane um on um.mundane_id = a.mundane_id
								LEFT JOIN " . DB_PREFIX . "unit u on u.unit_id = um.unit_id
							";
            $unit_phrase = "u.name as unit_name, ";
        }

        $sql = "select a.*, a.persona as attendance_persona,
					k.name as kingdom_name, k.parent_kingdom_id, mk.kingdom_id as from_kingdom_id, mk.name as from_kingdom_name, mk.parent_kingdom_id as from_parent_kingdom_id,
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
        if (valid_id($request['KingdomId'])) {
            $sql .= " and a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        }
        if (valid_id($request['ParkId'])) {
            $sql .= " and a.park_id = $request[ParkId]";
        }
        if (valid_id($request['UnitId'])) {
            $sql .= " and a.unit_id = $request[UnitId]";
        }
        if (valid_id($request['MundandeId'])) {
            $sql .= " and a.mundane_id = $request[MundaneId]";
        }
        if (valid_id($request['ClassId'])) {
            $sql .= " and a.class_id = $request[ClassId]";
        }

        logtrace('AttendanceForDate', array($request, $sql));

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
                        'Flavor' => $r->class_id == 6 ? $r->flavor : '',
                    );
            }
            $response['Status'] = Success($sql);
        } else {
            $response['Status'] = InvalidParameter();
        }
        return $response;
    }

    public function GeneralLedger($request)
    {

    }

    public function GetAuthorizations($request)
    {
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
					".(count($restrict_clause) > 0 ? "where" : "")." ".implode(' AND ', $restrict_clause)."
					order by ".implode(',', $order_by);

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
                                'GivenName' => ($restricted_access && $r->restricted == 0) ? $r->given_name : "",
                                'Surname' => ($restricted_access && $r->restricted == 0) ? $r->surname : "",
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

    public function GetPlayerRoster($request)
    {
        $select_list = array();
        $order_by = "k.name, p.name";
        $restrict_clause = array();
        if (true == $request['Suspended']) {
            /* Borrowed from Player class to clear the suspensions past their suspended_until date before running the report */
            $sql = "update " . DB_PREFIX . "mundane set suspended = 0, suspended_by_id = null, suspended_at = null, suspended_until = null, suspension = null, suspension_propagates = 1 where suspended_until < curdate() and suspended_until is not null and suspended_until != '0000-00-00'";
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
                $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['Id'])));
                $restrict_clause[] = "k.kingdom_id IN ($kidList)";
                $dues_restrict_clause = "and (a.kingdom_id IN ($kidList) or a.park_id = '" . mysql_real_escape_string($request['Id']) . "')";
                $order_by = "k.name, p.name";
                break;
            case AUTH_EVENT:
                $join_clause = 'left join ' . DB_PREFIX . "unit_mundane um on m.mundane_id = um.mundane_id and um.unit_id = '" . mysql_real_escape_string($request['Id']) . "'";
                $select_list = array('um.role', 'um.title', 'um.active');
                $order_by = "um.unit_id";
                $restrict_clause[] = "e.event_id = '" . mysql_real_escape_string($request['Id']) . "'";
                break;
            case AUTH_UNIT:
                $join_clause = 'left join ' . DB_PREFIX . "unit_mundane um on m.mundane_id = um.mundane_id";
                $select_list = array('um.role', 'um.title', 'um.active', 'um.role as unit_role', 'um.title as unit_title', 'um.unit_mundane_id');
                $order_by = "um.unit_id";
                $restrict_clause[] = " um.unit_id = '" . mysql_real_escape_string($request['Id']) . "' and " . (valid_id($request['IncludeRetiredUnitMembers']) ? "" : "um.active = 'Active'");
                break;
        }
        $select_list = array_merge(
            $select_list,
            array(
                'm.mundane_id','m.persona','m.park_id','m.kingdom_id','m.restricted','m.waivered','m.given_name', 'm.surname', 'm.other_name',
                'm.suspended', 'm.suspended_at', 'm.suspended_until', 'm.suspension', 'm.suspension_propagates', 'suspended_by.persona suspendator', 'suspended_by.mundane_id suspended_by_id',
                'p.name as park_name','k.name as kingdom_name','m.penalty_box')
        );
        if (true == $request['Active']) {
            $restrict_clause[] = ' m.active = 1 ';
        }
        if (true == $request['InActive']) {
            $restrict_clause[] = ' m.active = 0 ';
        }
        if (true == $request['Waivered']) {
            $restrict_clause[] = ' m.waivered = 1';
        }
        if (true == $request['UnWaivered']) {
            $restrict_clause[] = ' m.waivered = 0';
        }
        if (true == $request['Banned']) {
            $restrict_clause[] = ' m.penalty_box = 1';
        }
        if (true == $request['Suspended']) {
            $restrict_clause[] = ' m.suspended = 1';
        }
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
        $sql = 'SELECT ' . implode(',', $select_list) . "
					FROM " . DB_PREFIX . "mundane m
						LEFT JOIN " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
						LEFT JOIN " . DB_PREFIX . "park p on m.park_id = p.park_id
						left join " . DB_PREFIX . "mundane suspended_by on m.suspended_by_id = suspended_by.mundane_id
						left join " . DB_PREFIX . "attendance att on att.mundane_id = m.mundane_id
						$duespaid_clause
						$join_clause
					".(count($restrict_clause) ? "where" : "")."
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
                                'GivenName' => $restricted_access && $r->restricted == 0 ? $r->given_name : "",
                                'Surname' => $restricted_access && $r->restricted == 0 ? $r->surname : "",
                                'OtherName' => $restricted_access && $r->restricted == 0 ? $r->other_name : "",
                                'Persona' => $r->persona,
                                'Suspended' => $r->suspended,
                                'SuspendedAt' => $r->suspended_at,
                                'SuspendedUntil' => $r->suspended_until,
                                'Suspendator' => $r->suspendator,
                                'SuspendatorId' => $r->suspended_by_id,
                                'Suspension' => $r->suspension,
                            'SuspensionPropagates' => $r->suspension_propagates,
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
                                'Displayable' => $restricted_access || $r->restricted == 0
                            );
                }
            }
        } else {
            $response['Status'] = InvalidParameter('Problem with request.');
        }

        return $response;
    }

    public function GetKingdomParkAverages($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false) {
            return $cache;
        }

        if (strlen($request['ReportFromDate']) == 0) {
            $request['ReportFromDate'] = 'curdate()';
        }
        if (strlen($request['AverageWeeks']) == 0 && strlen($request['AverageMonths']) == 0) {
            $request['AverageWeeks'] = 26;
        }
        if (strlen($request['KingdomId']) == 0) {
            $request['KingdomId'] = '0';
        }
        if ($request['NativePopulace']) {
            $native_populace .= "m.park_id = a.park_id and";
        }
        if ($request['Waivered']) {
            $waivered_peeps = "m.waivered = 1 and";
        }

        if (strlen($request['AverageWeeks']) > 0) {
            $per_period = date("Y-m-d", strtotime("-$request[AverageWeeks] week"));
        } else {
            $per_period = date("Y-m-d", strtotime("-$request[AverageMonths] month"));
        }

        $escaped_kingdom_id = mysql_real_escape_string($request['KingdomId']);
        $kidList = (int)$request['KingdomId']; // park-LIST display stays parent-only; principalities shown in their own sections + aggregates roll up elsewhere

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
										a.kingdom_id IN ($kidList)
										and date >= '$per_period'
										and a.mundane_id > 0
									group by date_year, date_week3, mundane_id, a.park_id) mundanesbyweek
								on p.park_id = mundanesbyweek.park_id
					where p.kingdom_id IN ($kidList) and p.active = 'Active'
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

    public function GetKingdomParkMonthlyAverages($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false) {
            return $cache;
        }

        if (strlen($request['KingdomId']) == 0) {
            $request['KingdomId'] = '0';
        }
        $monthly_period = date("Y-m-d", strtotime("-1 year"));
        $escaped_kingdom_id = mysql_real_escape_string($request['KingdomId']);
        $kidList = (int)$request['KingdomId']; // park-LIST display stays parent-only; principalities shown in their own sections + aggregates roll up elsewhere

        // AVG(distinct players per month) per park — matches the Park page hero stat formula.
        // Divides by the number of months with actual attendance, not a fixed 12,
        // so parks with seasonal gaps report their active-period average.
        $sql = "SELECT AVG(monthly_unique) AS monthly_avg, park_id
					FROM (
						SELECT a.date_year, a.date_month, a.park_id,
						       COUNT(DISTINCT a.mundane_id) AS monthly_unique
						FROM " . DB_PREFIX . "attendance a
						WHERE a.kingdom_id IN ($kidList)
						  AND a.date > '$monthly_period'
						  AND a.mundane_id > 0
						GROUP BY a.date_year, a.date_month, a.park_id
					) sub
					GROUP BY park_id";
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
                $summary[] = array( 'ParkId' => $r->park_id, 'MonthlyAvg' => round((float)$r->monthly_avg, 2) );
            }
            $response['KingdomParkMonthlySummary'] = $summary;
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function GetTopParksByAttendance($request = null)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 600)) !== false) {
            return $cache;
        }

        if (strlen($request['Limit'] ?? '') == 0) {
            $request['Limit'] = 25;
        }
        if (strlen($request['StartDate'] ?? '') == 0) {
            $request['StartDate'] = date("Y-m-d", strtotime("-12 month"));
        }
        if (strlen($request['EndDate'] ?? '') == 0) {
            $request['EndDate'] = date("Y-m-d");
        }

        $escaped_start = mysql_real_escape_string($request['StartDate']);
        $escaped_end = mysql_real_escape_string($request['EndDate']);
        $escaped_limit = intval($request['Limit']);
        $native_populace = $request['NativePopulace'] ? "m.park_id = a.park_id and" : "";
        $waivered = $request['Waivered'] ? "m.waivered = 1 and" : "";
        $mundane_join = (!empty($native_populace) || !empty($waivered))
            ? "left join " . DB_PREFIX . "mundane m on a.mundane_id = m.mundane_id"
            : "";

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
									$mundane_join
								where
									$native_populace
									$waivered
									date >= '$escaped_start'
									and date <= '$escaped_end'
									and a.mundane_id > 0
								group by date_year, date_week3, mundane_id, a.park_id) mundanesbyweek
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

    public function GetActiveKingdomsSummary($request = null)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 3600)) !== false) {
            return $cache;
        }

        if (strlen($request['KingdomAverageWeeks'] ?? '') == 0) {
            $request['KingdomAverageWeeks'] = 26;
        }
        if (strlen($request['ParkAttendanceWithin'] ?? '') == 0) {
            $request['ParkAttendanceWithin'] = 4;
        }
        if (strlen($request['ReportFromDate'] ?? '') == 0) {
            $request['ReportFromDate'] = 'curdate()';
        }
        $wk_start = date("Y-m-d", strtotime("-6 month"));
        $mo_start = date("Y-m-d", strtotime("-1 year"));
        $sql = "SELECT k.name, k.kingdom_id, k.parent_kingdom_id, pcount.park_count, ifnull(attendance_count,0) attendance, ifnull(monthly_attendance_count,0) monthly, ifnull(avg_monthly_att.avg_monthly,0) avg_monthly, ifnull(activeparks.parkcount,0) active_parks
					FROM `" . DB_PREFIX . "kingdom` k
					left join
						(select count(*) as park_count, pcnt.kingdom_id from `" . DB_PREFIX . "park` pcnt where pcnt.active = 'Active' group by pcnt.kingdom_id) pcount on pcount.kingdom_id = k.kingdom_id
					left join
						(select
								count(mundanesbyweek.mundane_id) attendance_count, mundanesbyweek.kingdom_id
							from
								(select
										a.mundane_id, a.date_year, a.date_week3 as week, p.kingdom_id
									from " . DB_PREFIX . "attendance a
									inner join " . DB_PREFIX . "park p on p.park_id = a.park_id
									where a.date >= '$wk_start' and a.mundane_id > 0 group by a.date_year, a.date_week3, a.mundane_id, p.kingdom_id)
									mundanesbyweek group by kingdom_id) total_attendance on total_attendance.kingdom_id = k.kingdom_id
					left join
						(select
								count(mundanesbymonth.mundane_id) monthly_attendance_count, mundanesbymonth.kingdom_id
							from
								(select
										mundane_id, date_month as month, kingdom_id
									from " . DB_PREFIX . "attendance
									where date > '$mo_start' and mundane_id > 0 group by date_month, mundane_id, kingdom_id)
									mundanesbymonth group by kingdom_id) monthly_attendance on monthly_attendance.kingdom_id = k.kingdom_id
					left join
						(select kingdom_id, AVG(monthly_unique) AS avg_monthly
							from (
								select a.date_year, a.date_month, p2.kingdom_id, COUNT(DISTINCT a.mundane_id) AS monthly_unique
								from " . DB_PREFIX . "attendance a
								inner join " . DB_PREFIX . "park p2 on p2.park_id = a.park_id
								where a.date >= DATE_SUB(CURDATE(), INTERVAL 12 MONTH) and a.mundane_id > 0
								group by a.date_year, a.date_month, p2.kingdom_id
							) mo_sub
							group by kingdom_id) avg_monthly_att on avg_monthly_att.kingdom_id = k.kingdom_id
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
                                    'IsPrincipality' => $r->parent_kingdom_id > 0 ? 1 : 0, 'KingdomId' => $r->kingdom_id,
                                    'ParkCount' => $r->park_count, 'Attendance' => $r->attendance, 'Monthly' => $r->monthly, 'MonthlyAvg' => round((float)$r->avg_monthly, 1), 'Participation' => $r->active_parks );
        }
        $response = array(
            'Status' => Success(),
            'ActiveKingdomsSummaryList' => $report
        );
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function GetDistinctPlayerStats($request)
    {
        $where = '';
        if (valid_id($request['EventId'])) {
            $where = "AND a.event_id = '" . mysql_real_escape_string($request['EventId']) . "'";
        }
        if (valid_id($request['KingdomId'])) {
            $where = "AND a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        }
        if (valid_id($request['ParkId'])) {
            $where = "AND a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        }
        if (valid_id($request['PrincipalityId'])) {
            $where = "AND a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['PrincipalityId']))) . ")";
        }

        $report_to = (!empty($request['ReportFromDate']) && $request['ReportFromDate'] !== date('Y-m-d'))
            ? $request['ReportFromDate'] : date('Y-m-d');
        if ($request['PerWeeks'] == 1) {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} week"));
        }
        if ($request['PerMonths'] == 1) {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} month"));
        }
        if (!isset($per_period)) {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} day"));
        }

        $sql_total = "SELECT COUNT(DISTINCT a.mundane_id) AS total_distinct
			FROM " . DB_PREFIX . "attendance a
			WHERE a.date > '$per_period' AND a.date <= '$report_to' AND a.mundane_id > 0 $where";

        $sql_avg = "SELECT AVG(weekly_unique) AS avg_per_week FROM (
			SELECT COUNT(DISTINCT a.mundane_id) AS weekly_unique
			FROM " . DB_PREFIX . "attendance a
			WHERE a.date > '$per_period' AND a.date <= '$report_to' AND a.mundane_id > 0 $where
			GROUP BY a.date_year, a.date_week3
		) sub";

        $total = 0;
        $avg = 0;

        $r = $this->db->query($sql_total);
        if ($r && $r->next()) {
            $total = (int)$r->total_distinct;
        }

        $r = $this->db->query($sql_avg);
        if ($r && $r->next()) {
            $avg = round((float)$r->avg_per_week, 1);
        }

        return array(
            'Status' => Success(),
            'TotalDistinctPlayers' => $total,
            'AvgDistinctPerWeek' => $avg
        );
    }

    public function GetMonthlyChartData($request)
    {
        $where = '';
        if (valid_id($request['KingdomId'])) {
            $where = "AND a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        }
        if (valid_id($request['ParkId'])) {
            $where = "AND a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        }
        if (valid_id($request['PrincipalityId'])) {
            $where = "AND a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['PrincipalityId']))) . ")";
        }

        $report_to = (!empty($request['ReportFromDate']) && $request['ReportFromDate'] !== date('Y-m-d'))
            ? $request['ReportFromDate'] : date('Y-m-d');
        if ($request['PerWeeks'] == 1) {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} week"));
        } elseif ($request['PerMonths'] == 1) {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} month"));
        } else {
            $per_period = date('Y-m-d', strtotime("$report_to -{$request['Periods']} day"));
        }

        $sql = "SELECT a.date_year, a.date_month, COUNT(DISTINCT a.mundane_id) AS monthly_unique
			FROM " . DB_PREFIX . "attendance a
			WHERE a.date > '$per_period' AND a.date <= '$report_to' AND a.mundane_id > 0 $where
			GROUP BY a.date_year, a.date_month
			ORDER BY a.date_year, a.date_month";

        $r = $this->db->query($sql);
        $data = [];
        if ($r) {
            while ($r->next()) {
                $data[] = [
                    'Year'  => (int)$r->date_year,
                    'Month' => (int)$r->date_month,
                    'Count' => (int)$r->monthly_unique,
                ];
            }
        }
        return $data;
    }

    public function GetDistinctActivePlayerCount($weeks = 26)
    {
        $cacheKey = Ork3::$Lib->ghettocache->key(['weeks' => $weeks]);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cacheKey, 1200)) !== false) {
            return $cache;
        }
        $since = date('Y-m-d', strtotime("-{$weeks} week"));
        $sql = "SELECT COUNT(DISTINCT mundane_id) AS player_count FROM `" . DB_PREFIX . "attendance` WHERE date > '{$since}' AND mundane_id > 0";
        $r = $this->db->query($sql);
        $count = 0;
        if ($r && $r->next()) {
            $count = (int)$r->player_count;
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cacheKey, $count);
    }

    public function GetActivePlayers($request)
    {
        if (strlen($request['MinimumWeeklyAttendance']) == 0) {
            $request['MinimumWeeklyAttendance'] = 0;
        }
        if (strlen($request['MinimumDailyAttendance']) == 0) {
            $request['MinimumDailyAttendance'] = 6;
        }
        if (strlen($request['MonthlyCreditMaximum']) == 0) {
            $request['MonthlyCreditMaximum'] = 6;
        }
        if (strlen($request['MinimumCredits']) == 0) {
            $request['MinimumCredits'] = 9;
        }
        if (strlen($request['PerWeeks']) == 0 && strlen($request['PerMonths']) == 0) {
            $request['PerMonths'] = 6;
        }
        if (strlen($request['ReportFromDate']) == 0) {
            $request['ReportFromDate'] = 'curdate()';
        }

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
        } elseif (strlen($request['KingdomId']) > 0 && $request['KingdomId'] > 0) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $location = " and m.kingdom_id IN ($kidList)";
            if (valid_id($request['ByKingdom'])) {
                $park_list = Ork3::$Lib->Kingdom->GetParks($request);
                $parks = array();
                foreach ($park_list['Parks'] as $p => $park) {
                    $parks[] = $p['ParkId'];
                }
                $park_comparator = " and a.park_id in (" . implode($parks) . ") ";
            }
        } else {
            $park_comparator = "";
        }
        // Same scope, but on a.* (attendance row) instead of m.* (player home).
        // This lets the 4 inner subqueries enter via idx_attendance_kingdom_date_*
        // instead of full-scanning the date range and post-filtering by player kingdom.
        // Semantic match to the GetKingdomPark{,Monthly}Averages rewrite (06714a9f):
        // "active in this kingdom" = attendance recorded in this kingdom.
        if (valid_id($request['ParkId'])) {
            $activity_location = " and a.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        } elseif (strlen($request['KingdomId']) > 0 && $request['KingdomId'] > 0) {
            $activity_location = " and a.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        } else {
            $activity_location = "";
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
            // Resolve the effective peerage via Custom-Title alias substitution
            // so an aliased Custom Title (e.g. "Sir Bob aka Knight of the Sword")
            // is counted as that peerage.
            $peerage = "
                    left join
                        (select distinct awards.mundane_id, COALESCE(alias.peerage, award.peerage) as peerage
                            from " . DB_PREFIX . "awards awards
                                left join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = awards.kingdomaward_id
                                    left join " . DB_PREFIX . "award award on ka.award_id = award.award_id
                                left join " . DB_PREFIX . "award alias on alias.award_id = awards.alias_award_id
                                left join " . DB_PREFIX . "mundane m on awards.mundane_id = m.mundane_id
                            where COALESCE(alias.peerage, award.peerage) = '" . mysql_real_escape_string($request['Peerage']) . "' and awards.mundane_id > 0 $location
                            group by awards.mundane_id
                        ) peers on attendance_summary.mundane_id = peers.mundane_id
            ";
            $peerage_clause = "and peers.peerage = '" . mysql_real_escape_string($request['Peerage']) . "'";
            $peer_field = 'peers.peerage, ';
        }
        if ($request['Waivered']) {
            $waiver_clause = ' and m.waivered = 1';
        } elseif ($request['UnWaivered']) {
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
												m.suspended = 0 and date > '$per_period' $park_comparator $activity_location $waiver_clause
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
														m.suspended = 0 and date > '$per_period' $activity_location $waiver_clause $park_comparator
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
																		m.suspended = 0 and date > '$per_period' $activity_location $waiver_clause $park_comparator
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
                                  $activity_location
                                  $park_comparator
                                group by a.date_year, a.date_week3, a.mundane_id) local_park_week_count
                            group by local_park_week_count.mundane_id) park_local_attendance on main_summary.mundane_id = park_local_attendance.mundane_id
					";
        // For last join, need to limit monthly credits to monthly credit maximum per kingdom config
        logtrace('Report: GetActivePlayers', array($request,$sql));
        $r = $this->db->query($sql);
        $report = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
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
        }

        $response = array(
            'Status' => Success(),
            'ActivePlayerSummary' => $report
        );

        return $response;
    }

    public function GetReeveQualified($request)
    {
        $where = '';
        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $where = "and k.kingdom_id IN ($kidList)";
        }
        if (valid_id($request['ParkId'])) {
            $where = "and p.park_id = '$request[ParkId]'";
        }

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

    public function GetCorporaQualified($request)
    {
        $where = '';
        if (valid_id($request['KingdomId'])) {
            $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
            $where = "and k.kingdom_id IN ($kidList)";
        }
        if (valid_id($request['ParkId'])) {
            $where = "and p.park_id = '$request[ParkId]'";
        }

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

    public function GetDuesPaidList($request)
    {
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
        $sql .= (!$restrict_access) ? ' m.surname, m.given_name,' : 'NULL as surname, NULL as given_name,';

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

    private function _periodExpr($period, $alias = 'a')
    {
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

    public function ParkAttendanceAllParks($request)
    {
        $cache_key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cache_key, 300)) !== false) {
            return $cache;
        }

        $kingdom_id  = mysql_real_escape_string($request['KingdomId']);
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
        $start_date  = mysql_real_escape_string($request['StartDate']);
        $end_date    = mysql_real_escape_string($request['EndDate']);
        $period_expr = $this->_periodExpr($request['Period']);
        $local_only  = !empty($request['LocalPlayersOnly']);
        $local_clause = $local_only ? " AND m.park_id = a.park_id" : '';

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
				WHERE a.kingdom_id IN ($kidList)
					AND a.date >= '$start_date'
					AND a.date <= '$end_date'
					AND a.park_id > 0
					AND p.active = 'Active'
					$local_clause
				GROUP BY p.park_id, period_label
				ORDER BY p.name, period_label";

        $r = $this->db->query($sql);
        $response = array('Status' => Success(), 'Attendance' => array());
        $kingdom_unique_players = 0;
        $kingdom_unique_members = 0;
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

        // Kingdom-wide unique counts — dedicated query to avoid double-counting cross-park visitors
        $sql_kw = "SELECT
					COUNT(DISTINCT a2.mundane_id) as kingdom_unique_players,
					COUNT(DISTINCT CASE WHEN m2.park_id = a2.park_id THEN a2.mundane_id END) as kingdom_unique_members
				FROM " . DB_PREFIX . "attendance a2
					INNER JOIN " . DB_PREFIX . "park p2 ON a2.park_id = p2.park_id
					LEFT JOIN " . DB_PREFIX . "mundane m2 ON a2.mundane_id = m2.mundane_id
				WHERE a2.kingdom_id IN ($kidList)
					AND a2.date >= '$start_date'
					AND a2.date <= '$end_date'
					AND a2.park_id > 0
					AND p2.active = 'Active'"
                    . ($local_only ? " AND m2.park_id = a2.park_id" : '');

        $r_kw = $this->db->query($sql_kw);
        if ($r_kw !== false) {
            $r_kw->next(); // DataSet() pre-fetches, but guard against edge cases where it doesn't
            $kingdom_unique_players = (int)($r_kw->kingdom_unique_players ?? 0);
            $kingdom_unique_members = (int)($r_kw->kingdom_unique_members ?? 0);
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
					WHERE a.kingdom_id IN ($kidList)
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

        // Kingdom-wide unique counts from dedicated query
        $response['Summary'] = array(
            'UniquePlayers' => $kingdom_unique_players,
            'UniqueMembers' => $kingdom_unique_members,
        );

        logtrace("Report->ParkAttendanceAllParks()", array($this->db->lastSql, $request));
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cache_key, $response);
    }

    public function GetNewPlayerAttendance($request)
    {
        $cache_key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cache_key, 300)) !== false) {
            return $cache;
        }

        $kingdom_id     = intval($request['KingdomId']);
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
        $park_id        = intval($request['ParkId']);
        $start_date     = mysql_real_escape_string($request['StartDate']);
        $end_date       = mysql_real_escape_string($request['EndDate']);
        $include_detail = !empty($request['IncludePlayerDetails']);

        // Scope for the detail query's inner subquery (uses fp/pk aliases).
        if ($park_id > 0) {
            $scope_where = "AND fp.park_id = $park_id";
        } else {
            $scope_where = "AND pk.kingdom_id IN ($kidList)";
        }

        // Park scope applied to the outer parks table in the summary query.
        if ($park_id > 0) {
            $park_scope_where = "AND p.park_id = $park_id";
        } else {
            $park_scope_where = "AND p.kingdom_id IN ($kidList)";
        }

        // Visit counts: only count visits at parks within the target kingdom during the range.
        if ($park_id > 0) {
            $visit_scope = "AND a_range.park_id = $park_id";
        } else {
            $visit_scope = "AND a_range.kingdom_id IN ($kidList)";
        }

        // Summary query: all active parks with new player stats; parks with no new players show zeros.
        $sql_summary = "SELECT
				p.park_id,
				p.name AS park_name,
				COUNT(DISTINCT np.mundane_id) AS new_players,
				SUM(CASE WHEN vc.visit_count >= 2 THEN 1 ELSE 0 END) AS returning_players,
				COALESCE(SUM(vc.visit_count), 0) AS new_player_visits
			FROM " . DB_PREFIX . "park p
			LEFT JOIN (
				-- New players: first sign-in is in range AND no earlier sign-in exists.
				-- NOT EXISTS uses the mundane_id index on a_pre; avoids a full-table MIN() scan.
				SELECT a_in.mundane_id, MIN(a_in.park_id) AS first_park_id
				FROM " . DB_PREFIX . "attendance a_in
				WHERE a_in.mundane_id > 0
				  AND a_in.park_id > 0
				  AND a_in.date >= '$start_date'
				  AND a_in.date <= '$end_date'
				  AND NOT EXISTS (
				      SELECT 1 FROM " . DB_PREFIX . "attendance a_pre
				      WHERE a_pre.mundane_id = a_in.mundane_id
				        AND a_pre.date < '$start_date'
				      LIMIT 1
				  )
				GROUP BY a_in.mundane_id
			) np ON np.first_park_id = p.park_id
			LEFT JOIN (
				-- Count visits in range per new player (within kingdom/park scope).
				SELECT a_range.mundane_id, COUNT(*) AS visit_count
				FROM " . DB_PREFIX . "attendance a_range
				WHERE a_range.date >= '$start_date'
				  AND a_range.date <= '$end_date'
				  AND a_range.mundane_id > 0
				  $visit_scope
				GROUP BY a_range.mundane_id
			) vc ON vc.mundane_id = np.mundane_id
			WHERE p.active = 'Active'
			  AND p.name IS NOT NULL
			  AND p.name != ''
			  $park_scope_where
			GROUP BY p.park_id, p.name
			ORDER BY p.name";

        $r = $this->db->query($sql_summary);
        $response = array(
            'Status'        => Success(),
            'Summary'       => array(),
            'PlayerDetails' => array()
        );

        if ($r !== false && $r->size() > 0) {
            do {
                if (empty($r->park_name)) {
                    continue;
                }
                $new   = intval($r->new_players);
                $ret   = intval($r->returning_players);
                $visits = intval($r->new_player_visits);
                $response['Summary'][] = array(
                    'ParkId'                => $r->park_id,
                    'ParkName'              => $r->park_name,
                    'NewPlayers'            => $new,
                    'ReturningPlayers'      => $ret,
                    'ReturnPct'             => $new > 0 ? round(($ret / $new) * 100, 1) : 0,
                    'NewPlayerVisits'       => $visits,
                    'AvgVisitsPerNewPlayer' => $new > 0 ? round($visits / $new, 2) : 0
                );
            } while ($r->next());
        }

        if ($include_detail) {
            $sql_detail = "SELECT
					p.park_id,
					p.name AS park_name,
					np.mundane_id,
					m.persona,
					np.first_signin_date,
					vc.visit_count AS visits_in_period,
					ls.last_signin_date
				FROM (
					SELECT a_in.mundane_id, MIN(a_in.date) AS first_signin_date, MIN(a_in.park_id) AS first_park_id
					FROM " . DB_PREFIX . "attendance a_in
					INNER JOIN " . DB_PREFIX . "park fp ON fp.park_id = a_in.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom pk ON pk.kingdom_id = fp.kingdom_id
					WHERE a_in.mundane_id > 0
					  AND a_in.park_id > 0
					  AND a_in.date >= '$start_date'
					  AND a_in.date <= '$end_date'
					  AND NOT EXISTS (
					      SELECT 1 FROM " . DB_PREFIX . "attendance a_pre
					      WHERE a_pre.mundane_id = a_in.mundane_id
					        AND a_pre.date < '$start_date'
					      LIMIT 1
					  )
					  $scope_where
					GROUP BY a_in.mundane_id
				) np
				INNER JOIN " . DB_PREFIX . "park p ON p.park_id = np.first_park_id
				INNER JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = np.mundane_id
				INNER JOIN (
					SELECT a_range.mundane_id, COUNT(*) AS visit_count
					FROM " . DB_PREFIX . "attendance a_range
					WHERE a_range.date >= '$start_date'
					  AND a_range.date <= '$end_date'
					  AND a_range.mundane_id > 0
					  $visit_scope
					GROUP BY a_range.mundane_id
				) vc ON vc.mundane_id = np.mundane_id
				INNER JOIN (
					SELECT mundane_id, MAX(date) AS last_signin_date
					FROM " . DB_PREFIX . "attendance
					WHERE mundane_id > 0
					  AND date >= '$start_date'
					GROUP BY mundane_id
				) ls ON ls.mundane_id = np.mundane_id
				ORDER BY p.name, m.persona";

            $rd = $this->db->query($sql_detail);
            if ($rd !== false && $rd->size() > 0) {
                do {
                    if (empty($rd->park_name) || empty($rd->persona)) {
                        continue;
                    }
                    $response['PlayerDetails'][] = array(
                        'ParkId'          => $rd->park_id,
                        'ParkName'        => $rd->park_name,
                        'MundaneId'       => $rd->mundane_id,
                        'Persona'         => $rd->persona,
                        'FirstSignInDate' => $rd->first_signin_date,
                        'VisitsInPeriod'  => $rd->visits_in_period,
                        'LastSignInDate'  => $rd->last_signin_date
                    );
                } while ($rd->next());
            }
        }

        logtrace("Report->GetNewPlayerAttendance()", array($this->db->lastSql, $request));
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cache_key, $response);
    }

    public function GetNewPlayerAttendanceByKingdom($request)
    {
        $cache_key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $cache_key, 300)) !== false) {
            return $cache;
        }

        $start_date = mysql_real_escape_string($request['StartDate']);
        $end_date   = mysql_real_escape_string($request['EndDate']);

        $sql = "SELECT
				k.kingdom_id,
				k.name AS kingdom_name,
				COUNT(DISTINCT np.mundane_id) AS new_players,
				SUM(CASE WHEN vc.visit_count >= 2 THEN 1 ELSE 0 END) AS returning_players,
				COALESCE(SUM(vc.visit_count), 0) AS new_player_visits
			FROM " . DB_PREFIX . "kingdom k
			LEFT JOIN (
				-- New players: first sign-in is in range AND no earlier sign-in exists.
				-- NOT EXISTS uses the mundane_id index on a_pre; avoids a full-table MIN() scan.
				SELECT a_in.mundane_id, p.kingdom_id AS first_kingdom_id
				FROM " . DB_PREFIX . "attendance a_in
				INNER JOIN " . DB_PREFIX . "park p ON p.park_id = a_in.park_id
				WHERE a_in.mundane_id > 0
				  AND a_in.park_id > 0
				  AND a_in.date >= '$start_date'
				  AND a_in.date <= '$end_date'
				  AND NOT EXISTS (
				      SELECT 1 FROM " . DB_PREFIX . "attendance a_pre
				      WHERE a_pre.mundane_id = a_in.mundane_id
				        AND a_pre.date < '$start_date'
				      LIMIT 1
				  )
				GROUP BY a_in.mundane_id
			) np ON np.first_kingdom_id = k.kingdom_id
			LEFT JOIN (
				-- Count visits in range per new player per kingdom.
				SELECT a_range.mundane_id, a_range.kingdom_id, COUNT(*) AS visit_count
				FROM " . DB_PREFIX . "attendance a_range
				WHERE a_range.date >= '$start_date'
				  AND a_range.date <= '$end_date'
				  AND a_range.mundane_id > 0
				GROUP BY a_range.mundane_id, a_range.kingdom_id
			) vc ON vc.mundane_id = np.mundane_id AND vc.kingdom_id = k.kingdom_id
			WHERE k.active = 'Active'
			  AND k.name IS NOT NULL
			  AND k.name != ''
			GROUP BY k.kingdom_id, k.name
			ORDER BY k.name";

        $r = $this->db->query($sql);
        $response = array(
            'Status'  => Success(),
            'Summary' => array()
        );

        if ($r !== false && $r->size() > 0) {
            do {
                if (empty($r->kingdom_name)) {
                    continue;
                }
                $new    = intval($r->new_players);
                $ret    = intval($r->returning_players);
                $visits = intval($r->new_player_visits);
                $response['Summary'][] = array(
                    'KingdomId'             => $r->kingdom_id,
                    'KingdomName'           => $r->kingdom_name,
                    'NewPlayers'            => $new,
                    'ReturningPlayers'      => $ret,
                    'ReturnPct'             => $new > 0 ? round(($ret / $new) * 100, 1) : 0,
                    'NewPlayerVisits'       => $visits,
                    'AvgVisitsPerNewPlayer' => $new > 0 ? round($visits / $new, 2) : 0
                );
            } while ($r->next());
        }

        logtrace("Report->GetNewPlayerAttendanceByKingdom()", array($this->db->lastSql, $request));
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $cache_key, $response);
    }

    public function ParkAttendanceSinglePark($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }

        $park_id    = mysql_real_escape_string($request['ParkId']);
        $kingdom_id = mysql_real_escape_string($request['KingdomId']);
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId'])));
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
					AND a2.kingdom_id IN ($kidList)
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
					COUNT(DISTINCT a.attendance_id) as signin_count,
					MAX(d.dues_until) as dues_until,
					MAX(d.dues_for_life) as dues_for_life
				FROM " . DB_PREFIX . "attendance a
					LEFT JOIN " . DB_PREFIX . "mundane m ON a.mundane_id = m.mundane_id
					LEFT JOIN " . DB_PREFIX . "dues d ON d.mundane_id = a.mundane_id
						AND d.kingdom_id = a.kingdom_id
						AND d.revoked != 1
						AND (d.dues_for_life = 1 OR d.dues_until >= CURDATE())
				WHERE a.park_id = '$park_id'
					AND a.kingdom_id IN ($kidList)
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
                } elseif ($r->dues_until && $r->dues_until >= date('Y-m-d')) {
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

    public function GetParkDistanceMatrix($request)
    {
        $kingdom_id = intval($request['KingdomId']);

        $sql = "SELECT
					p1.park_id  AS row_id,
					p1.name     AS row_name,
					p1.city     AS row_city,
					p1.province AS row_province,
					p2.park_id  AS col_id,
					ROUND(ST_Distance_Sphere(POINT(p1.longitude, p1.latitude), POINT(p2.longitude, p2.latitude)) / 1609.344, 1) AS miles
				FROM " . DB_PREFIX . "park p1
				CROSS JOIN " . DB_PREFIX . "park p2
				WHERE p1.kingdom_id = '$kingdom_id'
					AND p2.kingdom_id = '$kingdom_id'
					AND p1.active = 'Active'
					AND p2.active = 'Active'
					AND p1.latitude  IS NOT NULL AND p1.latitude  != 0
					AND p1.longitude IS NOT NULL AND p1.longitude != 0
					AND p2.latitude  IS NOT NULL AND p2.latitude  != 0
					AND p2.longitude IS NOT NULL AND p2.longitude != 0
				ORDER BY p1.name ASC, p2.name ASC";

        $r = $this->db->query($sql);

        $parks  = array();
        $matrix = array();

        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $row_id = $r->row_id;
                $col_id = $r->col_id;
                if (!isset($parks[$row_id])) {
                    $parks[$row_id] = array(
                        'Name'     => $r->row_name,
                        'City'     => $r->row_city,
                        'Province' => $r->row_province,
                    );
                }
                if ($row_id !== $col_id) {
                    $matrix[$row_id][$col_id] = floatval($r->miles);
                }
            }
        }

        return array('Parks' => $parks, 'Matrix' => $matrix);
    }

    public function GetClosestParks($request)
    {
        $park_id = intval($request['ParkId']);

        $origin_sql = "SELECT latitude, longitude, name FROM " . DB_PREFIX . "park WHERE park_id = '$park_id'";
        $origin = $this->db->query($origin_sql);
        if ($origin === false || $origin->size() == 0) {
            return array('Parks' => array(), 'OriginPark' => null);
        }

        $origin->next();
        $lat = floatval($origin->latitude);
        $lng = floatval($origin->longitude);
        $origin_name = $origin->name;

        if ($lat == 0 && $lng == 0) {
            return array('Parks' => array(), 'OriginPark' => $origin_name);
        }

        $sql = "SELECT
					p.park_id,
					p.name AS park_name,
					k.kingdom_id,
					k.name AS kingdom_name,
					p.city,
					p.province,
					ROUND(ST_Distance_Sphere(POINT(p.longitude, p.latitude), POINT('$lng', '$lat')) / 1609.344, 1) AS miles
				FROM " . DB_PREFIX . "park p
				INNER JOIN " . DB_PREFIX . "kingdom k ON p.kingdom_id = k.kingdom_id
				WHERE p.park_id != '$park_id'
					AND p.active = 'Active'
					AND p.latitude IS NOT NULL
					AND p.longitude IS NOT NULL
					AND p.latitude != 0
					AND p.longitude != 0
				ORDER BY miles ASC
				LIMIT 25";

        $r = $this->db->query($sql);
        $response = array('Parks' => array(), 'OriginPark' => $origin_name);
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['Parks'][] = array(
                    'ParkId'      => $r->park_id,
                    'ParkName'    => $r->park_name,
                    'KingdomId'   => $r->kingdom_id,
                    'KingdomName' => $r->kingdom_name,
                    'City'        => $r->city,
                    'Province'    => $r->province,
                    'Miles'       => $r->miles,
                );
            }
        }
        return $response;
    }

    public function RecentParkAttendees($request)
    {
        $park_id = intval($request['ParkId']);
        if (!valid_id($park_id)) {
            return ['Status' => InvalidParameter(), 'Attendees' => []];
        }
        $sql = "SELECT a.mundane_id, m.persona,
					MAX(a.date) AS last_signin,
					SUBSTRING_INDEX(GROUP_CONCAT(a.class_id ORDER BY a.date DESC, a.attendance_id DESC SEPARATOR ','), ',', 1) AS class_id,
					SUBSTRING_INDEX(GROUP_CONCAT(c.name    ORDER BY a.date DESC, a.attendance_id DESC SEPARATOR ','), ',', 1) AS class_name
				FROM ork_attendance a
				JOIN ork_mundane m  ON m.mundane_id = a.mundane_id
				LEFT JOIN ork_class c  ON c.class_id = a.class_id
				WHERE a.park_id = $park_id
				  AND a.date >= DATE_SUB(NOW(), INTERVAL 90 DAY)
				  AND a.mundane_id > 0
				  AND m.mundane_id IS NOT NULL
				GROUP BY a.mundane_id, m.persona
				ORDER BY m.persona";
        $r = $this->db->query($sql);
        $attendees = [];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $attendees[] = [
                    'MundaneId'  => (int)$r->mundane_id,
                    'Persona'    => $r->persona,
                    'ClassId'    => (int)$r->class_id,
                    'ClassName'  => $r->class_name,
                    'LastSignIn' => $r->last_signin,
                ];
            }
        }
        return ['Status' => Success(), 'Attendees' => $attendees];
    }

    public function KingdomOfficerDirectory($request)
    {
        $kingdom_id = valid_id($request['KingdomId']) ? (int)$request['KingdomId'] : null;

        if ($kingdom_id) {
            // Park-scoped: officers for each park within a kingdom
            $sql = "SELECT
						p.park_id    AS entity_id,
						p.name       AS entity_name,
						MAX(CASE WHEN o.role = 'Monarch'        THEN m.persona    END) AS monarch_persona,
						MAX(CASE WHEN o.role = 'Monarch'        THEN m.mundane_id END) AS monarch_id,
						MAX(CASE WHEN o.role = 'Regent'         THEN m.persona    END) AS regent_persona,
						MAX(CASE WHEN o.role = 'Regent'         THEN m.mundane_id END) AS regent_id,
						MAX(CASE WHEN o.role = 'Prime Minister' THEN m.persona    END) AS pm_persona,
						MAX(CASE WHEN o.role = 'Prime Minister' THEN m.mundane_id END) AS pm_id,
						MAX(CASE WHEN o.role = 'Champion'       THEN m.persona    END) AS champion_persona,
						MAX(CASE WHEN o.role = 'Champion'       THEN m.mundane_id END) AS champion_id,
						MAX(CASE WHEN o.role = 'GMR'            THEN m.persona    END) AS gmr_persona,
						MAX(CASE WHEN o.role = 'GMR'            THEN m.mundane_id END) AS gmr_id,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.given_name  END) AS monarch_given,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.surname     END) AS monarch_surname,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.email       END) AS monarch_email,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.given_name  END) AS regent_given,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.surname     END) AS regent_surname,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.email       END) AS regent_email,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.given_name  END) AS pm_given,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.surname     END) AS pm_surname,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.email       END) AS pm_email,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.given_name  END) AS champion_given,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.surname     END) AS champion_surname,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.email       END) AS champion_email,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.given_name  END) AS gmr_given,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.surname     END) AS gmr_surname,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.email       END) AS gmr_email
					FROM " . DB_PREFIX . "park p
						LEFT JOIN " . DB_PREFIX . "officer o ON o.park_id = p.park_id
						LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = o.mundane_id
					WHERE p.kingdom_id = '$kingdom_id'
						AND p.active = 'Active'
					GROUP BY p.park_id, p.name
					ORDER BY p.name";
            $mode = 'parks';
        } else {
            // Top-level: kingdom-seat officers for all active kingdoms
            $sql = "SELECT
						k.kingdom_id AS entity_id,
						k.name       AS entity_name,
						MAX(CASE WHEN o.role = 'Monarch'        THEN m.persona    END) AS monarch_persona,
						MAX(CASE WHEN o.role = 'Monarch'        THEN m.mundane_id END) AS monarch_id,
						MAX(CASE WHEN o.role = 'Regent'         THEN m.persona    END) AS regent_persona,
						MAX(CASE WHEN o.role = 'Regent'         THEN m.mundane_id END) AS regent_id,
						MAX(CASE WHEN o.role = 'Prime Minister' THEN m.persona    END) AS pm_persona,
						MAX(CASE WHEN o.role = 'Prime Minister' THEN m.mundane_id END) AS pm_id,
						MAX(CASE WHEN o.role = 'Champion'       THEN m.persona    END) AS champion_persona,
						MAX(CASE WHEN o.role = 'Champion'       THEN m.mundane_id END) AS champion_id,
						MAX(CASE WHEN o.role = 'GMR'            THEN m.persona    END) AS gmr_persona,
						MAX(CASE WHEN o.role = 'GMR'            THEN m.mundane_id END) AS gmr_id,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.given_name  END) AS monarch_given,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.surname     END) AS monarch_surname,
					MAX(CASE WHEN o.role = 'Monarch'        THEN m.email       END) AS monarch_email,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.given_name  END) AS regent_given,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.surname     END) AS regent_surname,
					MAX(CASE WHEN o.role = 'Regent'         THEN m.email       END) AS regent_email,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.given_name  END) AS pm_given,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.surname     END) AS pm_surname,
					MAX(CASE WHEN o.role = 'Prime Minister' THEN m.email       END) AS pm_email,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.given_name  END) AS champion_given,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.surname     END) AS champion_surname,
					MAX(CASE WHEN o.role = 'Champion'       THEN m.email       END) AS champion_email,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.given_name  END) AS gmr_given,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.surname     END) AS gmr_surname,
					MAX(CASE WHEN o.role = 'GMR'            THEN m.email       END) AS gmr_email
					FROM " . DB_PREFIX . "kingdom k
						LEFT JOIN " . DB_PREFIX . "officer o ON o.kingdom_id = k.kingdom_id AND o.park_id = 0
						LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = o.mundane_id
					WHERE k.parent_kingdom_id = 0
						AND k.active = 'Active'
						AND k.name != 'The Freeholds of Amtgard'
					GROUP BY k.kingdom_id, k.name
					ORDER BY k.name";
            $mode = 'kingdoms';
        }

        $r = $this->db->query($sql);
        $response = ['Status' => Success(), 'Mode' => $mode, 'Kingdoms' => []];
        if ($r === false) {
            $response['Status'] = InvalidParameter();
        } elseif ($r->size() > 0) {
            while ($r->next()) {
                $response['Kingdoms'][] = [
                    'KingdomId'      => $r->entity_id,
                    'KingdomName'    => $r->entity_name,
                    'MonarchPersona'  => $r->monarch_persona,
                    'MonarchId'       => $r->monarch_id,
                    'MonarchGiven'    => $r->monarch_given,
                    'MonarchSurname'  => $r->monarch_surname,
                    'MonarchEmail'    => $r->monarch_email,
                    'RegentPersona'   => $r->regent_persona,
                    'RegentId'        => $r->regent_id,
                    'RegentGiven'     => $r->regent_given,
                    'RegentSurname'   => $r->regent_surname,
                    'RegentEmail'     => $r->regent_email,
                    'PMPersona'       => $r->pm_persona,
                    'PMId'            => $r->pm_id,
                    'PMGiven'         => $r->pm_given,
                    'PMSurname'       => $r->pm_surname,
                    'PMEmail'         => $r->pm_email,
                    'ChampionPersona' => $r->champion_persona,
                    'ChampionId'      => $r->champion_id,
                    'ChampionGiven'   => $r->champion_given,
                    'ChampionSurname' => $r->champion_surname,
                    'ChampionEmail'   => $r->champion_email,
                    'GMRPersona'      => $r->gmr_persona,
                    'GMRId'           => $r->gmr_id,
                    'GMRGiven'        => $r->gmr_given,
                    'GMRSurname'      => $r->gmr_surname,
                    'GMREmail'        => $r->gmr_email,
                ];
            }
        }
        return $response;
    }
    public function EventAttendanceReport($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }

        if (valid_id($request['KingdomId'])) {
            $where = 'AND e.kingdom_id = ' . (int)$request['KingdomId'];
        } elseif (valid_id($request['ParkId'])) {
            $where = 'AND e.park_id = ' . (int)$request['ParkId'];
        } else {
            return array('Status' => InvalidParameter(), 'Events' => array());
        }

        $sql = "SELECT
					e.event_id,
					e.name AS event_name,
					e.has_heraldry,
					p.park_id,
					p.name AS park_name,
					p.abbreviation AS park_abbr,
					cd.event_calendardetail_id,
					cd.event_start,
					cd.event_end,
					cd.price,
					cd.city,
					cd.province,
					(SELECT COUNT(*) FROM " . DB_PREFIX . "attendance a
						WHERE a.event_calendardetail_id = cd.event_calendardetail_id) AS attendance_count,
					(SELECT COUNT(*) FROM " . DB_PREFIX . "event_rsvp r
						WHERE r.event_calendardetail_id = cd.event_calendardetail_id) AS rsvp_count
				FROM " . DB_PREFIX . "event e
				LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
				JOIN " . DB_PREFIX . "event_calendardetail cd ON cd.event_id = e.event_id
				WHERE 1 $where
				ORDER BY cd.event_start DESC
				LIMIT 1000";

        $r = $this->db->query($sql);
        $response = array();
        if ($r !== false) {
            $response['Events'] = array();
            if ($r->size() > 0) {
                while ($r->next()) {
                    $response['Events'][] = array(
                        'EventId'         => (int)$r->event_id,
                        'EventName'       => $r->event_name,
                        'HasHeraldry'     => (int)$r->has_heraldry,
                        'ParkId'          => (int)$r->park_id,
                        'ParkName'        => $r->park_name,
                        'ParkAbbr'        => $r->park_abbr,
                        'DetailId'        => (int)$r->event_calendardetail_id,
                        'EventStart'      => $r->event_start,
                        'EventEnd'        => $r->event_end,
                        'Price'           => $r->price,
                        'City'            => $r->city,
                        'Province'        => $r->province,
                        'AttendanceCount' => (int)$r->attendance_count,
                        'RsvpCount'       => (int)$r->rsvp_count,
                    );
                }
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = InvalidParameter();
            $response['Events'] = array();
        }
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function BeltlineData($request)
    {
        $kingdom_id = intval($request['KingdomId']);
        if (!valid_id($kingdom_id)) {
            return array('Status' => InvalidParameter('KingdomId required'), 'Relationships' => array(), 'Knights' => array());
        }

        $sql = "SELECT
				ma.mundane_id AS recipient_id,
				m.persona AS recipient_persona,
				ma.given_by_id AS giver_id,
				giver.persona AS giver_persona,
				COALESCE(NULLIF(ma.custom_name,''), ka.name, a.name) AS title_name,
				COALESCE(alias.peerage, a.peerage) AS peerage,
				ma.date
			FROM " . DB_PREFIX . "awards ma
				JOIN " . DB_PREFIX . "award a ON a.award_id = ma.award_id
				LEFT JOIN " . DB_PREFIX . "award alias ON alias.award_id = ma.alias_award_id
				LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
				JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = ma.mundane_id
				LEFT JOIN " . DB_PREFIX . "mundane giver ON giver.mundane_id = ma.given_by_id
			WHERE (COALESCE(alias.peerage, a.peerage) IN ('Squire', 'Man-At-Arms', 'Page', 'Lords-Page')
					OR LOWER(COALESCE(NULLIF(ma.custom_name,''), ka.name, a.name)) LIKE '%woman%at%arms%')
				AND (ma.revoked = 0 OR ma.revoked IS NULL)
			ORDER BY m.persona";

        logtrace('BeltlineData', $sql);
        $r = $this->db->query($sql);
        $relationships = array();
        if ($r !== false && $r->size() > 0) {
            do {
                $relationships[] = array(
                    'RecipientId'      => $r->recipient_id,
                    'RecipientPersona' => $r->recipient_persona,
                    'GiverId'          => $r->giver_id,
                    'GiverPersona'     => $r->giver_persona,
                    'TitleName'        => $r->title_name,
                    'Peerage'          => $r->peerage,
                    'Date'             => $r->date,
                );
            } while ($r->next());
        }

        // Knights for the dropdown — kingdom-scoped so the selector stays useful.
        $kidList = implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id)));
        $knights_sql = "SELECT DISTINCT
				m.mundane_id,
				m.persona
			FROM " . DB_PREFIX . "awards ma
				JOIN " . DB_PREFIX . "award a ON a.award_id = ma.award_id
				LEFT JOIN " . DB_PREFIX . "award alias ON alias.award_id = ma.alias_award_id
				JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = ma.mundane_id
			WHERE COALESCE(alias.peerage, a.peerage) = 'Knight'
				AND m.kingdom_id IN ($kidList)
				AND (ma.revoked = 0 OR ma.revoked IS NULL)
			ORDER BY m.persona";

        logtrace('BeltlineData knights', $knights_sql);
        $kr = $this->db->query($knights_sql);
        $knights = array();
        if ($kr !== false && $kr->size() > 0) {
            do {
                $knights[] = array(
                    'MundaneId' => $kr->mundane_id,
                    'Persona'   => $kr->persona,
                );
            } while ($kr->next());
        }

        // All knight awards globally — IDs for the crown icon + type names for display.
        $all_knights_sql = "SELECT ma.mundane_id, COALESCE(NULLIF(ma.custom_name,''), ka.name, a.name) AS knight_name
			FROM " . DB_PREFIX . "awards ma
				JOIN " . DB_PREFIX . "award a ON a.award_id = ma.award_id
				LEFT JOIN " . DB_PREFIX . "award alias ON alias.award_id = ma.alias_award_id
				LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
			WHERE COALESCE(alias.peerage, a.peerage) = 'Knight'
				AND (ma.revoked = 0 OR ma.revoked IS NULL)";

        $akr = $this->db->query($all_knights_sql);
        $all_knight_ids = array();
        $knight_types   = array(); // mundane_id => [type, ...]
        if ($akr !== false && $akr->size() > 0) {
            do {
                $mid  = (int)$akr->mundane_id;
                $name = $akr->knight_name;
                // Strip common "Knight of (the) " prefixes to get just the type
                $type = preg_replace('/^knight(?:hood)? of (?:the )?/i', '', $name);
                if (strcasecmp($type, $name) === 0) {
                    $type = $name;
                } // no prefix matched, use as-is
                $all_knight_ids[] = $mid;
                if (!in_array($type, $knight_types[$mid] ?? array())) {
                    $knight_types[$mid][] = $type;
                }
            } while ($akr->next());
            $all_knight_ids = array_unique($all_knight_ids);
        }

        return array(
            'Status'        => Success(),
            'Relationships' => $relationships,
            'Knights'       => $knights,
            'AllKnightIds'  => array_values($all_knight_ids),
            'KnightTypes'   => $knight_types,
        );
    }


    public function GetPlayerStatusReconciliation($request)
    {
        $key = Ork3::$Lib->ghettocache->key($request);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 120)) !== false) {
            return $cache;
        }

        $location = '';
        if (valid_id($request['ParkId'])) {
            $location = " and m.park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
        } elseif (valid_id($request['KingdomId'])) {
            $location = " and m.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']))) . ")";
        } else {
            return array('Status' => InvalidParameter());
        }

        // Inactive players WITH a sign-in in the past 6 months
        $sql_inactive_with_attendance = "
			SELECT m.mundane_id, m.persona, m.given_name, m.surname,
				p.park_id, p.name AS park_name,
				k.kingdom_id, k.name AS kingdom_name,
				MAX(a.date) AS last_signin,
				COUNT(DISTINCT a.date) AS signin_count_6mo
			FROM " . DB_PREFIX . "mundane m
				INNER JOIN " . DB_PREFIX . "attendance a ON a.mundane_id = m.mundane_id
					AND a.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
				LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
				LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
			WHERE m.active = 0 AND m.suspended = 0 AND m.persona IS NOT NULL AND m.persona != '' $location
			GROUP BY m.mundane_id
			ORDER BY p.name, m.persona";

        $this->db->Clear();
        $r1 = $this->db->query($sql_inactive_with_attendance);
        $inactive_with_attendance = array();
        if ($r1 !== false && $r1->size() > 0) {
            while ($r1->next()) {
                $inactive_with_attendance[] = array(
                    'MundaneId'     => $r1->mundane_id,
                    'Persona'       => $r1->persona,
                    'GivenName'     => $r1->given_name,
                    'Surname'       => $r1->surname,
                    'ParkId'        => $r1->park_id,
                    'ParkName'      => $r1->park_name,
                    'KingdomId'     => $r1->kingdom_id,
                    'KingdomName'   => $r1->kingdom_name,
                    'LastSignIn'    => $r1->last_signin,
                    'SignInCount'   => $r1->signin_count_6mo,
                );
            }
        }

        // Active players with NO sign-in in the past 24 months
        $sql_active_no_attendance = "
			SELECT m.mundane_id, m.persona, m.given_name, m.surname,
				p.park_id, p.name AS park_name,
				k.kingdom_id, k.name AS kingdom_name,
				m.park_member_since,
				(SELECT MAX(a2.date) FROM " . DB_PREFIX . "attendance a2 WHERE a2.mundane_id = m.mundane_id) AS last_signin
			FROM " . DB_PREFIX . "mundane m
				LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
				LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
			WHERE m.active = 1 AND m.suspended = 0 AND m.persona IS NOT NULL AND m.persona != '' $location
				AND (m.park_member_since IS NULL OR m.park_member_since < DATE_SUB(CURDATE(), INTERVAL 24 MONTH))
				AND NOT EXISTS (
					SELECT 1 FROM " . DB_PREFIX . "attendance a
					WHERE a.mundane_id = m.mundane_id
						AND a.date >= DATE_SUB(CURDATE(), INTERVAL 24 MONTH)
				)
			ORDER BY p.name, m.persona";

        $this->db->Clear();
        $r2 = $this->db->query($sql_active_no_attendance);
        $active_no_attendance = array();
        if ($r2 !== false && $r2->size() > 0) {
            while ($r2->next()) {
                $active_no_attendance[] = array(
                    'MundaneId'     => $r2->mundane_id,
                    'Persona'       => $r2->persona,
                    'GivenName'     => $r2->given_name,
                    'Surname'       => $r2->surname,
                    'ParkId'        => $r2->park_id,
                    'ParkName'      => $r2->park_name,
                    'KingdomId'     => $r2->kingdom_id,
                    'KingdomName'   => $r2->kingdom_name,
                    'LastSignIn'    => $r2->last_signin,
                    'ParkMemberSince' => $r2->park_member_since,
                );
            }
        }

        $response = array(
            'Status' => Success(),
            'InactiveWithAttendance' => $inactive_with_attendance,
            'ActiveNoAttendance' => $active_no_attendance,
        );
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $response);
    }

    public function SetPlayerActiveStatus($request)
    {
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($requester_id)) {
            return NoAuthorization();
        }

        $mundane_id = (int)$request['MundaneId'];
        $active = (int)$request['Active'];
        if (!$mundane_id || !\in_array($active, [0, 1])) {
            return InvalidParameter();
        }

        $mundane = new yapo($this->db, DB_PREFIX . 'mundane');
        $mundane->mundane_id = $mundane_id;
        if (!$mundane->find()) {
            return InvalidParameter('Player not found.');
        }

        // Auth check: requester must have AUTH_PARK + AUTH_CREATE on the player's park
        if (!Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane->park_id, AUTH_CREATE)
            && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_KINGDOM, $mundane->kingdom_id, AUTH_EDIT)
            && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            return NoAuthorization();
        }

        $mundane->active = $active;
        $mundane->save();
        return Success();
    }

    public function GetVotingEligible($request)
    {
        $kingdom_id     = (int)($request['KingdomId'] ?? 0);
        $park_id        = valid_id($request['ParkId'] ?? 0) ? (int)$request['ParkId'] : 0;
        $mundane_id     = valid_id($request['MundaneId'] ?? 0) ? (int)$request['MundaneId'] : 0;
        $att_req        = isset($request['AttendanceRequired']) ? (int)$request['AttendanceRequired'] : 6;
        $months_win     = isset($request['MonthsWindow']) ? (int)$request['MonthsWindow'] : 6;
        $min_mem_mo     = isset($request['MinMembershipMonths']) ? (int)$request['MinMembershipMonths'] : 6;
        $att_mode           = $request['AttendanceMode'] ?? 'weeks';
        if (!in_array($att_mode, ['count', 'days', 'weeks'])) {
            $att_mode = 'weeks';
        }
        $week_offset             = isset($request['WeekOffset']) ? (int)$request['WeekOffset'] : 0;
        $province_mode           = !empty($request['ProvinceMode']);
        $kingdom_evt_bonus       = !empty($request['KingdomEventBonus']);
        $active_knight_threshold  = isset($request['ActiveKnightThreshold']) ? (int)$request['ActiveKnightThreshold'] : 0;
        $active_member_threshold  = isset($request['ActiveMemberThreshold']) ? (int)$request['ActiveMemberThreshold'] : 0;
        $exclude_online           = !empty($request['ExcludeOnline']);
        $all_kingdoms             = !empty($request['AllKingdoms']); // count attendance from any kingdom
        $days_window             = isset($request['DaysWindow']) ? (int)$request['DaysWindow'] : 0;
        $min_age                 = isset($request['MinAge']) ? (int)$request['MinAge'] : 0;
        $max_credits_per_event   = isset($request['MaxCreditsPerEvent']) ? (int)$request['MaxCreditsPerEvent'] : 0;
        $max_outside_kingdom_creds = isset($request['MaxOutsideKingdomCredits']) ? (int)$request['MaxOutsideKingdomCredits'] : 0;
        $week_snap               = !empty($request['WeekSnap']); // snap window start to week boundary even in non-weeks modes
        $membership_mode         = $request['MembershipMode'] ?? 'park_member_since'; // 'first_attendance' uses MIN(attendance.date) within the kingdom
        $show_event_count        = !empty($request['ShowEventCount']); // emit a separate count of event-based sign-ins (informational only, not excluded)
        $exclude_events          = !empty($request['ExcludeEvents']);  // count only non-event (park-day) sign-ins
        $waiver_age_months       = isset($request['WaiverAgeMonths']) ? (int)$request['WaiverAgeMonths'] : 0; // months waiver must be on file — cannot auto-verify, shown as caveat only

        // Compute start date.
        // display_start_date: shown in the report header (raw date for DaysWindow, snapped for MonthsWindow).
        // start_date: used in SQL — always snapped to week start when att_mode=weeks so the full
        //   starting week is included (e.g. 180d back lands on Sat → SQL starts Mon of that week).
        if (!empty($request['StartDate'])) {
            $start_date = $display_start_date = mysql_real_escape_string($request['StartDate']);
        } else {
            $_raw_ts         = $days_window > 0 ? strtotime("-{$days_window} days")
                                                : strtotime("-{$months_win} months");
            $display_start_date = date('Y-m-d', $_raw_ts);
            // Snap SQL start to the first day of the Amtgard week when counting weeks,
            // or when WeekSnap=true (kingdoms that count sign-ins/days but align their
            // window to the week boundary for consistency with the Amtgard calendar).
            if ($att_mode === 'weeks' || $week_snap) {
                $_week_start_iso = $week_offset + 1;                    // 1=Mon, 2=Tue, 7=Sun …
                $_dow            = (int)date('N', $_raw_ts);             // 1=Mon … 7=Sun
                $_days_back      = (($_dow - $_week_start_iso) + 7) % 7;
                $start_date      = date('Y-m-d', $_raw_ts - $_days_back * 86400);
                // Always show the snapped week-start so the "Attendance from" date
                // matches exactly what the SQL counts from.
                $display_start_date = $start_date;
            } else {
                $start_date = $display_start_date;
            }
        }

        // If only ParkId given, derive kingdom from the park record
        if (!$kingdom_id && $park_id) {
            $pr = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "park WHERE park_id = $park_id LIMIT 1");
            if ($pr && $pr->size() > 0) {
                $pr->next();
                $kingdom_id = (int)$pr->kingdom_id;
            }
        }
        if (!$kingdom_id) {
            return ['Players' => [], 'AttendanceRequired' => $att_req, 'MonthsWindow' => $months_win, 'MinMembershipMonths' => $min_mem_mo, 'ProvinceMode' => $province_mode];
        }

        $park_clause    = $park_id ? " AND m.park_id    = $park_id" : '';
        $mundane_clause = $mundane_id ? " AND m.mundane_id = $mundane_id" : '';

        // Single-player calls (Player profile voting badge) only need attendance for one mundane_id.
        // Without this push-down, every LEFT JOIN subquery aggregates over the whole kingdom's
        // attendance and then joins to find one row — turning a single-player check into a
        // kingdom-wide report.
        $single_att_clause = $mundane_id ? " AND a.mundane_id = $mundane_id" : '';

        // Online exclusion: LEFT JOIN event + park inside attendance subqueries, filter out
        // rows where the event name or park name contains 'Online' (case-insensitive).
        // ExcludeEvents: restrict attendance to park-day sign-ins only (event_id = 0 or NULL).
        $events_clause = $exclude_events ? "AND (a.event_id = 0 OR a.event_id IS NULL)" : '';

        $online_join   = '';
        $online_clause = '';
        if ($exclude_online) {
            $online_join   = "LEFT JOIN " . DB_PREFIX . "event   _oe ON _oe.event_id = a.event_id AND a.event_id != 0
					LEFT JOIN " . DB_PREFIX . "park    _op ON _op.park_id  = a.park_id";
            // NULL-safe: a.park_id = 0 (kingdom events) yields NULL park name — allow those through.
            // Only exclude when we can positively confirm 'Online' is in the name.
            $online_clause = "AND (_oe.name IS NULL OR _oe.name NOT LIKE '%Online%')
					AND (_op.name IS NULL OR _op.name NOT LIKE '%Online%')";
        }

        $home_park_only = !empty($request['HomeParkOnly']);

        // HomeParkOnly: attendance subquery counts only sign-ins at the player's home park.
        // Requires joining ork_mundane inside the subquery to access each player's park_id.
        // Compatible with KingdomEventBonus (kingdom events count as +1 regardless of home park).
        $home_park_join = $home_park_only
            ? "JOIN " . DB_PREFIX . "mundane mp ON mp.mundane_id = a.mundane_id"
            : '';

        // Attendance subquery expression.
        // KingdomEventBonus: kingdom-sponsored events (ork_event.park_id = 0) count as at most +1.
        // Extra columns to select alongside att_count in the attendance subquery (comma-prefixed).
        $att_extra_cols = '';

        if ($home_park_only && $kingdom_evt_bonus) {
            // Home-park sign-ins + capped kingdom event bonus.
            // Also emit a separate kingdom_evt_credit column (0 or 1) for display in the report.
            $att_expr = "COUNT(CASE WHEN a.park_id = mp.park_id THEN 1 END)"
                      . " + SIGN(SUM(CASE WHEN kve.event_id IS NOT NULL AND kve.park_id = 0 THEN 1 ELSE 0 END))";
            $att_extra_cols = ", SIGN(SUM(CASE WHEN kve.event_id IS NOT NULL AND kve.park_id = 0 THEN 1 ELSE 0 END)) AS kingdom_evt_credit";
        } elseif ($home_park_only) {
            $att_expr = "COUNT(CASE WHEN a.park_id = mp.park_id THEN 1 END)";
        } elseif ($kingdom_evt_bonus) {
            // Regular sign-ins (parkday or park-hosted event) + kingdom event bonus (capped at 1).
            // SIGN() returns 1 if any kingdom events were attended, 0 otherwise.
            $att_expr = "COUNT(CASE WHEN a.event_id = 0 OR kve.event_id IS NULL OR kve.park_id != 0 THEN 1 END)"
                      . " + SIGN(SUM(CASE WHEN kve.event_id IS NOT NULL AND kve.park_id = 0 THEN 1 ELSE 0 END))";
        } elseif ($att_mode === 'count') {
            $att_expr = "COUNT(*)";
        } elseif ($att_mode === 'days') {
            $att_expr = "COUNT(DISTINCT a.date)";
        } elseif ($week_offset > 0) {
            // Non-Monday week start: shift date back by offset days so the custom start day
            // aligns with Monday, then use Monday-start ISO yearweek (mode 3).
            // e.g. offset=1 → Tuesday-start; YEARWEEK handles year boundaries correctly.
            $att_expr = "COUNT(DISTINCT YEARWEEK(DATE_SUB(a.date, INTERVAL $week_offset DAY), 3))";
        } else {
            // Monday-start: use pre-computed columns (fastest)
            $att_expr = "COUNT(DISTINCT CONCAT(a.date_year, '-', a.date_week3))";
        }

        // Pre-build the att LEFT JOIN clause. Two paths:
        // 1. Outside-credit-cap (e.g. Tal Dagore): nested UNION ALL subquery tracks in/out kingdom
        //    credits separately, applies per-event cap and outside-kingdom credit ceiling.
        // 2. Standard path: single-level GROUP BY with att_expr.
        // Both produce the same att alias with att_count; path 1 also emits outside_credits_raw.
        //
        // Kingdom scope is computed by PARK membership, not by attendance.kingdom_id.
        // attendance.kingdom_id is a point-in-time snapshot set when the row was
        // inserted; if a park later transfers between kingdoms (most notably when a
        // principality splits off from its parent), historical rows keep the old
        // kingdom_id and are silently misattributed. park.kingdom_id is the stable
        // truth, so we filter via park membership + a fallback branch for kingdom-only
        // events (which have no park_id by definition).
        $_park_subq        = "SELECT park_id FROM " . DB_PREFIX . "park WHERE kingdom_id = $kingdom_id";
        $_in_scope_clause  = "(a.park_id IN ($_park_subq) OR (a.kingdom_id = $kingdom_id AND a.park_id = 0))";
        $att_select_extra = '';
        if ($max_outside_kingdom_creds > 0) {
            $_per_evt = $max_credits_per_event > 0 ? "LEAST(COUNT(*), $max_credits_per_event)" : "COUNT(*)";
            // In-kingdom classification is by PARK membership (see $_in_scope_clause
            // comment above). The boolean returns 1 for in-kingdom rows, 0 otherwise,
            // which is exactly what the credit-multiplier arithmetic below needs.
            $att_join_clause = "LEFT JOIN (
					SELECT mundane_id,
					       SUM(in_credits) + LEAST(SUM(out_credits), $max_outside_kingdom_creds) AS att_count,
					       SUM(out_credits) AS outside_credits_raw
					FROM (
					    SELECT a.mundane_id,
					           $_per_evt * ($_in_scope_clause)    AS in_credits,
					           $_per_evt * (NOT $_in_scope_clause) AS out_credits
					    FROM " . DB_PREFIX . "attendance a
					    WHERE a.event_id IS NOT NULL AND a.event_id != 0
					      AND a.date >= '$start_date'
					      $single_att_clause
					    GROUP BY a.mundane_id, a.event_id, a.kingdom_id
					    UNION ALL
					    SELECT a.mundane_id,
					           ($_in_scope_clause)    AS in_credits,
					           (NOT $_in_scope_clause) AS out_credits
					    FROM " . DB_PREFIX . "attendance a
					    WHERE (a.event_id = 0 OR a.event_id IS NULL)
					      AND a.date >= '$start_date'
					      $single_att_clause
					) att_inner
					GROUP BY mundane_id
				) att ON att.mundane_id = m.mundane_id";
            $att_select_extra = ', COALESCE(att.outside_credits_raw, 0) AS outside_credits_raw';
        } else {
            // Standard path — resolve inline PHP expressions into variables first
            // so the att_join_clause string contains only PHP variable interpolations.
            $_kve_join_sql  = $kingdom_evt_bonus
                ? "LEFT JOIN " . DB_PREFIX . "event kve ON kve.event_id = a.event_id AND a.event_id != 0"
                : '';
            $_att_where_kw  = $all_kingdoms ? "1=1" : $_in_scope_clause;
            $att_join_clause = "LEFT JOIN (
					SELECT a.mundane_id, $att_expr AS att_count $att_extra_cols
					FROM " . DB_PREFIX . "attendance a
					$home_park_join
					$_kve_join_sql
					$online_join
					WHERE $_att_where_kw
					  AND a.date >= '$start_date'
					  $single_att_clause
					  $online_clause
					  $events_clause
					GROUP BY a.mundane_id
				) att ON att.mundane_id = m.mundane_id";
        }

        // Park-level attendance subquery (province mode only)
        $province_join   = '';
        $province_select = '';
        if ($province_mode) {
            $province_join = "
				LEFT JOIN (
					SELECT a.mundane_id, COUNT(*) AS park_att_count
					FROM " . DB_PREFIX . "attendance a
					JOIN " . DB_PREFIX . "mundane mp ON mp.mundane_id = a.mundane_id AND mp.park_id = a.park_id
					$online_join
					WHERE " . ($all_kingdoms ? "1=1" : "a.kingdom_id = $kingdom_id") . "
					  AND a.date >= '$start_date'
					  $single_att_clause
					  $online_clause
					GROUP BY a.mundane_id
				) patt ON patt.mundane_id = m.mundane_id
			";
            $province_select = ", COALESCE(patt.park_att_count, 0) AS park_att_count";
        }

        // Online-excluded count: separate subquery counting sign-ins that were filtered out
        // due to ExcludeOnline, so the report can show how many were excluded per player.
        $online_count_join   = '';
        $online_count_select = '';
        if ($exclude_online) {
            $online_count_join = "
				LEFT JOIN (
					SELECT a.mundane_id, COUNT(*) AS online_excluded_count
					FROM " . DB_PREFIX . "attendance a
					LEFT JOIN " . DB_PREFIX . "event _oe ON _oe.event_id = a.event_id AND a.event_id != 0
					LEFT JOIN " . DB_PREFIX . "park  _op ON _op.park_id  = a.park_id
					WHERE a.kingdom_id = $kingdom_id
					  AND a.date >= '$start_date'
					  $single_att_clause
					  AND (_oe.name LIKE '%Online%' OR _op.name LIKE '%Online%')
					GROUP BY a.mundane_id
				) oatt ON oatt.mundane_id = m.mundane_id
			";
            $online_count_select = ', COALESCE(oatt.online_excluded_count, 0) AS online_excluded_count';
        }

        // Event count: count sign-ins tied to an ork_event (event_id != 0) within the window.
        // Informational only — does not affect eligibility or the main att_count.
        $event_count_join   = '';
        $event_count_select = '';
        if ($show_event_count) {
            $event_count_join = "
				LEFT JOIN (
					SELECT a.mundane_id, COUNT(*) AS event_att_count
					FROM " . DB_PREFIX . "attendance a
					WHERE a.kingdom_id = $kingdom_id
					  AND a.date >= '$start_date'
					  $single_att_clause
					  AND a.event_id IS NOT NULL AND a.event_id != 0
					GROUP BY a.mundane_id
				) evatt ON evatt.mundane_id = m.mundane_id
			";
            $event_count_select = ', COALESCE(evatt.event_att_count, 0) AS event_att_count';
        }

        // Active Knight: secondary raw sign-in count (always COUNT(*)) used for kingdoms
        // that have a higher-tier eligibility based on total attendances.
        $knight_join   = '';
        $knight_select = '';
        if ($active_knight_threshold > 0) {
            $knight_join = "
				LEFT JOIN (
					SELECT a.mundane_id, COUNT(*) AS raw_att_count
					FROM " . DB_PREFIX . "attendance a
					$online_join
					WHERE " . ($all_kingdoms ? "1=1" : "a.kingdom_id = $kingdom_id") . "
					  AND a.date >= '$start_date'
					  $single_att_clause
					  $online_clause
					GROUP BY a.mundane_id
				) katt ON katt.mundane_id = m.mundane_id
			";
            $knight_select = "
				, COALESCE(katt.raw_att_count, 0) AS raw_att_count
				, CASE WHEN EXISTS (
					SELECT 1 FROM " . DB_PREFIX . "awards ma
					JOIN " . DB_PREFIX . "award aw ON aw.award_id = ma.award_id
					LEFT JOIN " . DB_PREFIX . "award alias ON alias.award_id = ma.alias_award_id
					WHERE ma.mundane_id = m.mundane_id
					  AND COALESCE(alias.peerage, aw.peerage) = 'Knight'
					  AND (ma.revoked = 0 OR ma.revoked IS NULL)
				) THEN 1 ELSE 0 END AS is_knight
			";
        }

        $sql = "
			SELECT
				m.mundane_id, m.persona, m.waivered, m.park_member_since,
				m.suspended, m.suspended_until,
				p.park_id, p.name AS park_name,
				k.kingdom_id, k.name AS kingdom_name,
				COALESCE(att.att_count, 0) AS att_count
				$att_select_extra
				$online_count_select
				$event_count_select
				" . ($home_park_only && $kingdom_evt_bonus ? ", COALESCE(att.kingdom_evt_credit, 0) AS kingdom_evt_credit" : "") . "
				" . ($membership_mode === 'first_attendance' ? ", (SELECT MIN(a.date) FROM " . DB_PREFIX . "attendance a WHERE a.mundane_id = m.mundane_id AND a.kingdom_id = $kingdom_id) AS first_att_date" : "") . "
				$province_select
				$knight_select,
				CASE WHEN EXISTS (
					SELECT 1 FROM " . DB_PREFIX . "dues d
					WHERE d.mundane_id = m.mundane_id
					  AND d.park_id IN ($_park_subq)
					  AND d.revoked != 1
					  AND (d.dues_until >= CURDATE() OR d.dues_for_life = 1)
				) THEN 1 ELSE 0 END AS dues_paid,
				(SELECT CASE WHEN MAX(d.dues_for_life) = 1 THEN '9999-12-31'
				             ELSE MAX(d.dues_until) END
				 FROM " . DB_PREFIX . "dues d
				 WHERE d.mundane_id = m.mundane_id
				   AND d.park_id IN ($_park_subq)
				   AND d.revoked != 1
				   AND (d.dues_until >= CURDATE() OR d.dues_for_life = 1)
				) AS dues_until
			FROM " . DB_PREFIX . "mundane m
				JOIN " . DB_PREFIX . "park    p ON p.park_id    = m.park_id
				JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
				$att_join_clause
				$province_join
				$knight_join
				$online_count_join
				$event_count_join
			WHERE m.kingdom_id = $kingdom_id
			  AND m.active = 1
			  AND p.active = 'Active'
			  $park_clause
			  $mundane_clause
			ORDER BY p.name, m.persona
		";

        $r = $this->db->query($sql);
        $response = ['Players' => [], 'AttendanceRequired' => $att_req, 'MonthsWindow' => $months_win,
                     'MinMembershipMonths' => $min_mem_mo, 'ProvinceMode' => $province_mode,
                     'AttendanceMode' => $att_mode, 'WeekOffset' => $week_offset,
                     'KingdomEventBonus' => $kingdom_evt_bonus,
                     'ActiveKnightThreshold' => $active_knight_threshold,
                     'ActiveMemberThreshold' => $active_member_threshold,
                     'ExcludeOnline' => $exclude_online,
                     'HomeParkOnly' => $home_park_only,
                     'DaysWindow' => $days_window,
                     'MinAge' => $min_age,
                     'StartDate' => $start_date,
                     'DisplayStartDate' => $display_start_date,
                     'AllKingdoms' => $all_kingdoms,
                     'MaxCreditsPerEvent' => $max_credits_per_event,
                     'MaxOutsideKingdomCredits' => $max_outside_kingdom_creds,
                     'MembershipMode' => $membership_mode,
                     'ShowEventCount' => $show_event_count,
                     'ExcludeEvents' => $exclude_events,
                     'WaiverAgeMonths' => $waiver_age_months];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $_member_date    = $membership_mode === 'first_attendance' ? $r->first_att_date : $r->park_member_since;
                $membership_ok   = empty($_member_date) || $_member_date === '0000-00-00'
                    || strtotime($_member_date) <= strtotime("-{$min_mem_mo} months");
                $suspended       = (int)$r->suspended === 1;
                $att_count       = (int)$r->att_count;
                $base_ok         = !$suspended && $r->waivered == 1 && $r->dues_paid == 1 && $membership_ok;
                $kingdom_eligible = $base_ok && $att_count >= $att_req;

                $row = [
                    'MundaneId'       => (int)$r->mundane_id,
                    'Persona'         => $r->persona,
                    'ParkId'          => (int)$r->park_id,
                    'ParkName'        => $r->park_name,
                    'KingdomId'       => (int)$r->kingdom_id,
                    'KingdomName'     => $r->kingdom_name,
                    'Waivered'        => (int)$r->waivered,
                    'DuesPaid'        => (int)$r->dues_paid,
                    'DuesUntil'       => $r->dues_until,
                    'AttCount'        => $att_count,
                    'MemberSince'     => $membership_mode === 'first_attendance' ? $r->first_att_date : $r->park_member_since,
                    'MembershipOk'    => $membership_ok,
                    'Suspended'       => $suspended,
                    'SuspendedUntil'  => $r->suspended_until,
                    'KingdomEligible'    => $kingdom_eligible,
                    'VotingEligible'     => $kingdom_eligible,
                    'KingdomEventCredit' => $home_park_only && $kingdom_evt_bonus ? (int)$r->kingdom_evt_credit : null,
                    'OutsideCredits'     => $max_outside_kingdom_creds > 0 ? (int)$r->outside_credits_raw : null,
                    'ActiveMember'       => $active_member_threshold > 0 ? ($base_ok && $att_count >= $active_member_threshold) : null,
                    'ActiveKnight'       => false,
                    'OnlineExcluded'     => $exclude_online ? (int)$r->online_excluded_count : null,
                    'EventCount'         => $show_event_count ? (int)$r->event_att_count : null,
                ];
                if ($active_knight_threshold > 0) {
                    $raw_att_count        = (int)$r->raw_att_count;
                    $is_knight            = (int)$r->is_knight === 1;
                    $row['RawAttCount']   = $raw_att_count;
                    $row['IsKnight']      = $is_knight;
                    $row['ActiveKnight']  = $is_knight && $kingdom_eligible && $raw_att_count >= $active_knight_threshold;
                }

                if ($province_mode) {
                    $park_att_count      = (int)$r->park_att_count;
                    $province_eligible   = $base_ok && $park_att_count >= $att_req;
                    $row['ParkAttCount']       = $park_att_count;
                    $row['ProvinceEligible']   = $province_eligible;
                    // Province implies kingdom (park attendance is a subset of kingdom attendance),
                    // so VotingEligible stays kingdom_eligible — the broader right.
                }

                $response['Players'][] = $row;
            }
        }
        return $response;
    }

    public function GetInactiveKingdoms($request)
    {
        $sql = "
			SELECT k.kingdom_id, k.name AS kingdom_name, k.active, k.modified,
			       k.parent_kingdom_id,
			       pk.name AS parent_kingdom_name,
			       (SELECT MAX(a.date) FROM " . DB_PREFIX . "attendance a
			        JOIN " . DB_PREFIX . "park p2 ON p2.park_id = a.park_id
			        WHERE p2.kingdom_id = k.kingdom_id) AS last_attendance
			FROM " . DB_PREFIX . "kingdom k
			LEFT JOIN " . DB_PREFIX . "kingdom pk ON pk.kingdom_id = k.parent_kingdom_id
			WHERE k.active != 'Active'
			  AND k.kingdom_id > 0
			  AND k.name != ''
			ORDER BY k.name
		";

        $r = $this->db->query($sql);
        $response = ['Kingdoms' => []];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['Kingdoms'][] = [
                    'KingdomId'         => $r->kingdom_id,
                    'KingdomName'       => $r->kingdom_name,
                    'Active'            => $r->active,
                    'Modified'          => $r->modified,
                    'ParentKingdomId'   => $r->parent_kingdom_id,
                    'ParentKingdomName' => $r->parent_kingdom_name,
                    'LastAttendance'    => $r->last_attendance,
                    'Type'              => $r->parent_kingdom_id > 0 ? 'Principality' : 'Kingdom',
                ];
            }
        }
        return $response;
    }

    public function GetInactiveParks($request)
    {
        $kingdom_id = valid_id($request['KingdomId'] ?? 0) ? (int)$request['KingdomId'] : 0;
        $kingdom_clause = $kingdom_id ? " AND p.kingdom_id IN (" . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ")" : '';

        $sql = "
			SELECT p.park_id, p.name AS park_name, p.active, p.modified,
			       k.kingdom_id, k.name AS kingdom_name,
			       pt.title AS park_type,
			       (SELECT MAX(a.date) FROM " . DB_PREFIX . "attendance a WHERE a.park_id = p.park_id) AS last_attendance
			FROM " . DB_PREFIX . "park p
			JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = p.kingdom_id
			LEFT JOIN " . DB_PREFIX . "parktitle pt ON pt.parktitle_id = p.parktitle_id
			WHERE p.active != 'Active'
			  AND p.park_id > 0
			  AND p.name NOT LIKE '%No Park%'
			  AND p.name != ''
			$kingdom_clause
			ORDER BY k.name, p.name
		";

        $r = $this->db->query($sql);
        $response = ['Parks' => []];
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $response['Parks'][] = [
                    'ParkId'         => $r->park_id,
                    'ParkName'       => $r->park_name,
                    'Active'         => $r->active,
                    'Modified'       => $r->modified,
                    'KingdomId'      => $r->kingdom_id,
                    'KingdomName'    => $r->kingdom_name,
                    'ParkType'       => $r->park_type,
                    'LastAttendance' => $r->last_attendance,
                ];
            }
        }
        return $response;
    }

    /* ============================ Amtgard Week in Review ============================ */

    // Compute the full weekly recap payload for a given week. Server-local Mon-Sun
    // window. Defaults to the most recently completed week. Called once by the cron;
    // page reads come from ork_weekly_recap via ReadWeeklyRecap().
    public function GetWeeklyRecap($request = null)
    {
        $win = $this->_WeeklyRecapWindow($request['WeekStart'] ?? null);
        return array(
            'WeekStart'        => $win['WeekStart'],
            'WeekEnd'          => $win['WeekEnd'],
            'Knightings'       => $this->_RecapPeerages($win, array('Knight')),
            'Masterhoods'      => $this->_RecapPeerages($win, array('Master')),
            'Paragons'         => $this->_RecapPeerages($win, array('Paragon')),
            'TopEvents'        => $this->_RecapTopEvents($win, 3),
            'TopParks'         => $this->_RecapTopParks($win, 3),
            'NewPlayers'       => $this->_RecapNewPlayers($win),
            'ReturningPlayers' => $this->_RecapReturningPlayers($win, 90),
            'MilestoneEvents'  => $this->_RecapMilestoneEvents($win, 25),
            'PlatformStats'    => $this->_RecapCloudflareStats($win),
        );
    }

    // Read a previously-computed recap from ork_weekly_recap. Returns the decoded
    // payload (plus ComputedAt) or null if no row exists for that week.
    public function ReadWeeklyRecap($request = null)
    {
        $win = $this->_WeeklyRecapWindow($request['WeekStart'] ?? null);
        $sql = "SELECT computed_at, payload_json
				FROM " . DB_PREFIX . "weekly_recap
				WHERE week_start = :ws";
        $r = $this->db->query($sql, array(':ws' => $win['WeekStart']));
        if (!$r || $r->size() == 0 || !$r->next()) {
            return null;
        }
        $payload = json_decode($r->payload_json, true);
        if (!is_array($payload)) {
            return null;
        }
        $payload['ComputedAt'] = $r->computed_at;
        return $payload;
    }

    // Fetches NA-only Cloudflare traffic totals for the week. Returns null on any
    // failure (missing credentials, HTTP error, malformed response, timeout) so the
    // rest of the recap still ships. CF retains ~30-90 days of analytics depending
    // on plan tier — historical backfills past that horizon will get null here.
    //
    // Credentials: prefers PHP constants CF_API_TOKEN / CF_ZONE_ID (the established
    // pattern in config.php, matching SENDGRID_API_KEY etc.); falls back to env
    // vars of the same name when the constants are empty/missing — so dev can
    // pass them via docker `-e` without editing the tracked config.dev.php.
    private function _RecapCloudflareStats($win)
    {
        $token = (defined('CF_API_TOKEN') && CF_API_TOKEN !== '') ? CF_API_TOKEN : getenv('CF_API_TOKEN');
        $zone  = (defined('CF_ZONE_ID')   && CF_ZONE_ID   !== '') ? CF_ZONE_ID : getenv('CF_ZONE_ID');
        if (empty($token) || empty($zone)) {
            return null;
        }

        // CF wants UTC ISO 8601. Convert the server-local window.
        $since = gmdate('Y-m-d\TH:i:s\Z', strtotime($win['StartDt']));
        $until = gmdate('Y-m-d\TH:i:s\Z', strtotime($win['EndDt']));

        $gql = 'query($zone:String!,$since:Time!,$until:Time!) {
			viewer { zones(filter:{zoneTag:$zone}) {
				totals: httpRequestsAdaptiveGroups(limit:1, filter:{datetime_geq:$since, datetime_leq:$until, clientCountryName_in:["US","CA"]}) {
					count sum { edgeResponseBytes }
				}
				byCountry: httpRequestsAdaptiveGroups(limit:2, filter:{datetime_geq:$since, datetime_leq:$until, clientCountryName_in:["US","CA"]}, orderBy:[count_DESC]) {
					count dimensions { clientCountryName }
				}
				cacheHit: httpRequestsAdaptiveGroups(limit:1, filter:{datetime_geq:$since, datetime_leq:$until, clientCountryName_in:["US","CA"], cacheStatus:"hit"}) {
					count sum { edgeResponseBytes }
				}
			} }
		}';
        $json = $this->_cfGraphQL($token, $gql, array(
            'zone' => $zone, 'since' => $since, 'until' => $until,
        ));
        $zones = $json['data']['viewer']['zones'][0] ?? null;
        if (!is_array($zones) || empty($zones['totals'][0])) {
            return null;
        }

        $totals = $zones['totals'][0];
        $cache  = $zones['cacheHit'][0] ?? array('count' => 0, 'sum' => array('edgeResponseBytes' => 0));
        $by_country = array();
        foreach (($zones['byCountry'] ?? array()) as $row) {
            $by_country[$row['dimensions']['clientCountryName']] = (int)$row['count'];
        }
        $total_bytes  = (int)$totals['sum']['edgeResponseBytes'];
        $cached_bytes = (int)($cache['sum']['edgeResponseBytes'] ?? 0);
        return array(
            'Requests'             => (int)$totals['count'],
            'Bytes'                => $total_bytes,
            'CacheHits'            => (int)$cache['count'],
            'CachedBytes'          => $cached_bytes,
            'OriginBytes'          => max(0, $total_bytes - $cached_bytes),
            'RequestsUS'           => (int)($by_country['US'] ?? 0),
            'RequestsCA'           => (int)($by_country['CA'] ?? 0),
            'BlockedOrChallenged'  => $this->_cfFirewallTotal($token, $zone, $win),
        );
    }

    // CF GraphQL POST. Returns decoded array or null on any failure.
    private function _cfGraphQL($token, $query, $variables)
    {
        $ch = curl_init('https://api.cloudflare.com/client/v4/graphql');
        curl_setopt_array($ch, array(
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(array('query' => $query, 'variables' => $variables)),
            CURLOPT_HTTPHEADER     => array(
                'Authorization: Bearer ' . $token,
                'Content-Type: application/json',
            ),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 5,
        ));
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($resp === false || $http !== 200) {
            return null;
        }
        return json_decode($resp, true);
    }

    // Count of firewall events that actually stopped traffic: outright blocks plus
    // challenges (managed/JS/classic). Excludes "skip", "allow", "log", and the
    // *_solved/*_bypassed actions where the request ultimately got through.
    //
    // firewallEventsAdaptiveGroups has a 3-day max range per query on Pro plans,
    // so we chunk the weekly window into three calls and sum.
    private function _cfFirewallTotal($token, $zone, $win)
    {
        $start_ts = strtotime($win['StartDt']);
        $end_ts   = strtotime($win['EndDt']);
        if ($end_ts <= $start_ts) {
            return null;
        }
        $chunk_s  = (int)ceil(($end_ts - $start_ts) / 3);

        $gql = 'query($zone:String!,$since:Time!,$until:Time!) {
			viewer { zones(filter:{zoneTag:$zone}) {
				fw: firewallEventsAdaptiveGroups(limit:1, filter:{datetime_geq:$since, datetime_leq:$until, action_in:["block","managed_challenge","challenge","js_challenge"]}) {
					count
				}
			} }
		}';

        $total = 0;
        for ($i = 0; $i < 3; $i++) {
            $c_start = $start_ts + $i * $chunk_s;
            $c_end   = min($end_ts, $c_start + $chunk_s);
            $json = $this->_cfGraphQL($token, $gql, array(
                'zone'  => $zone,
                'since' => gmdate('Y-m-d\TH:i:s\Z', $c_start),
                'until' => gmdate('Y-m-d\TH:i:s\Z', $c_end),
            ));
            if (!is_array($json) || !isset($json['data']['viewer']['zones'][0]['fw'][0])) {
                return null;
            }
            $total += (int)$json['data']['viewer']['zones'][0]['fw'][0]['count'];
        }
        return $total;
    }

    // Kingdom-scoped recap. Anchors on the global recap row (so we share its
    // PlatformStats / ComputedAt and only produce kingdom recaps for weeks the
    // cron has already computed). Cache key includes the global ComputedAt so a
    // fresh cron run on Monday naturally orphans every prior kingdom cache —
    // no explicit invalidation needed.
    public function GetWeeklyRecapForKingdom($request = null)
    {
        $kingdom_id = intval($request['KingdomId'] ?? 0);
        if ($kingdom_id <= 0) {
            return null;
        }

        $global = $this->ReadWeeklyRecap($request);
        if (!is_array($global) || empty($global['WeekStart'])) {
            return null;
        }
        $week_start  = $global['WeekStart'];
        $computed_at = $global['ComputedAt'] ?? '';

        $call = __CLASS__ . '.' . __FUNCTION__;
        $key  = 'k' . $kingdom_id . '.w' . $week_start . '.c' . str_replace(array(' ', ':', '-'), '', $computed_at);
        $cached = Ork3::$Lib->ghettocache->get($call, $key, 86400);
        if ($cached !== false) {
            return $cached;
        }

        $win = $this->_WeeklyRecapWindow($week_start);
        $payload = array(
            'WeekStart'        => $win['WeekStart'],
            'WeekEnd'          => $win['WeekEnd'],
            'KingdomId'        => $kingdom_id,
            'Knightings'       => $this->_RecapPeerages($win, array('Knight'), $kingdom_id),
            'Masterhoods'      => $this->_RecapPeerages($win, array('Master'), $kingdom_id),
            'Paragons'         => $this->_RecapPeerages($win, array('Paragon'), $kingdom_id),
            'TopEvents'        => $this->_RecapTopEvents($win, 3, $kingdom_id),
            'TopParks'         => $this->_RecapTopParks($win, 3, $kingdom_id),
            'NewPlayers'       => $this->_RecapNewPlayers($win, $kingdom_id),
            'ReturningPlayers' => $this->_RecapReturningPlayers($win, 90, $kingdom_id),
            'MilestoneEvents'  => $this->_RecapMilestoneEvents($win, 25, $kingdom_id),
            // PlatformStats stays global — CF doesn't tell us per-kingdom traffic.
            'PlatformStats'    => $global['PlatformStats'] ?? null,
            'ComputedAt'       => $computed_at,
        );
        return Ork3::$Lib->ghettocache->cache($call, $key, $payload);
    }

    // Active kingdoms for the dropdown picker, sorted by name. Tiny query, cheap
    // to re-run per page load — wraps in ghettocache (1 hour) anyway because the
    // list barely changes.
    public function ListRecapKingdoms($request = null)
    {
        $call = __CLASS__ . '.' . __FUNCTION__;
        $key  = 'all';
        $cached = Ork3::$Lib->ghettocache->get($call, $key, 3600);
        if ($cached !== false) {
            return $cached;
        }

        $sql = "SELECT kingdom_id, name FROM " . DB_PREFIX . "kingdom
				WHERE active = 'Active' AND kingdom_id > 0
				ORDER BY name";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array('KingdomId' => (int)$r->kingdom_id, 'Name' => $r->name);
            }
        }
        return Ork3::$Lib->ghettocache->cache($call, $key, $out);
    }

    // Returns the most recent week_starts present in ork_weekly_recap, newest first.
    // Used by the page to render prev/next and an archive picker.
    public function ListRecapWeeks($limit = 26)
    {
        $lim = max(1, intval($limit));
        $sql = "SELECT week_start FROM " . DB_PREFIX . "weekly_recap
				ORDER BY week_start DESC LIMIT $lim";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = $r->week_start;
            }
        }
        return $out;
    }

    // Persist a computed recap. Upserts on week_start so the cron is rerun-safe.
    // Uses PDO parameter binding because the polyfill mysql_real_escape_string()
    // in startup.php is a no-op and the JSON payload contains user-content strings.
    public function StoreWeeklyRecap($payload)
    {
        $sql = "INSERT INTO " . DB_PREFIX . "weekly_recap (week_start, computed_at, payload_json)
				VALUES (:ws, NOW(), :json)
				ON DUPLICATE KEY UPDATE computed_at = NOW(), payload_json = VALUES(payload_json)";
        return $this->db->query($sql, array(
            ':ws'   => $payload['WeekStart'],
            ':json' => json_encode($payload),
        ));
    }

    private function _WeeklyRecapWindow($week_start = null)
    {
        if (empty($week_start)) {
            // "monday this week" returns today if today is Monday, else the most recent past Monday.
            $this_monday = strtotime('monday this week');
            $week_start  = date('Y-m-d', strtotime('-7 days', $this_monday));
        }
        $week_end = date('Y-m-d', strtotime($week_start . ' +6 days'));
        return array(
            'WeekStart' => $week_start,
            'WeekEnd'   => $week_end,
            'StartDt'   => $week_start . ' 00:00:00',
            'EndDt'     => $week_end   . ' 23:59:59',
        );
    }

    private function _RecapPeerages($win, $peerages, $kingdom_id = null)
    {
        $list  = "'" . implode("','", array_map('mysql_real_escape_string', $peerages)) . "'";
        $start = mysql_real_escape_string($win['WeekStart']);
        $end   = mysql_real_escape_string($win['WeekEnd']);
        $kid_clause = $kingdom_id ? ' AND m.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT ma.awards_id, ma.date, ma.mundane_id, m.persona,
					   p.park_id, p.name AS park_name,
					   k.kingdom_id, k.name AS kingdom_name,
					   COALESCE(alias.peerage, a.peerage) AS peerage,
					   COALESCE(NULLIF(ma.custom_name, ''), ka.name, alias.name, a.name) AS award_name
				FROM " . DB_PREFIX . "awards ma
					JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = ma.mundane_id
					LEFT JOIN " . DB_PREFIX . "kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
					LEFT JOIN " . DB_PREFIX . "award a ON a.award_id = ka.award_id
					LEFT JOIN " . DB_PREFIX . "award alias ON alias.award_id = ma.alias_award_id
					LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = m.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = m.kingdom_id
				WHERE COALESCE(alias.peerage, a.peerage) IN ($list)
				  AND ma.revoked = 0
				  AND ma.date >= '$start' AND ma.date <= '$end'
				  $kid_clause
				ORDER BY ma.date, m.persona";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array(
                    'AwardsId'    => $r->awards_id,
                    'Date'        => $r->date,
                    'MundaneId'   => $r->mundane_id,
                    'Persona'     => $r->persona,
                    'AwardName'   => $r->award_name,
                    'Peerage'     => $r->peerage,
                    'ParkId'      => $r->park_id,
                    'ParkName'    => $r->park_name,
                    'KingdomId'   => $r->kingdom_id,
                    'KingdomName' => $r->kingdom_name,
                );
            }
        }
        return $out;
    }

    // Single-occurrence (calendardetail-scoped). A 3-day event contributes up to 3
    // separate occurrences if multiple days fall in the window.
    private function _RecapTopEvents($win, $limit = 3, $kingdom_id = null)
    {
        $start = mysql_real_escape_string($win['StartDt']);
        $end   = mysql_real_escape_string($win['EndDt']);
        $limit = intval($limit);
        $kid_clause = $kingdom_id ? ' AND e.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT e.event_id, e.name AS event_name,
					   e.park_id, p.name AS park_name,
					   e.kingdom_id, k.name AS kingdom_name,
					   d.event_calendardetail_id, d.event_start,
					   COUNT(DISTINCT a.mundane_id) AS attendance
				FROM " . DB_PREFIX . "event e
					JOIN " . DB_PREFIX . "event_calendardetail d
					  ON d.event_id = e.event_id AND d.current = 1
					LEFT JOIN " . DB_PREFIX . "attendance a
					  ON a.event_calendardetail_id = d.event_calendardetail_id
					 AND a.mundane_id > 0
					LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id
				WHERE d.event_start >= '$start' AND d.event_start <= '$end'
				  $kid_clause
				GROUP BY e.event_id, d.event_calendardetail_id
				HAVING attendance > 0
				ORDER BY attendance DESC, d.event_start ASC
				LIMIT $limit";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array(
                    'EventId'               => $r->event_id,
                    'EventName'             => $r->event_name,
                    'EventStart'            => $r->event_start,
                    'EventCalendarDetailId' => $r->event_calendardetail_id,
                    'ParkId'                => $r->park_id,
                    'ParkName'              => $r->park_name,
                    'KingdomId'             => $r->kingdom_id,
                    'KingdomName'           => $r->kingdom_name,
                    'Attendance'            => intval($r->attendance),
                );
            }
        }
        return $out;
    }

    private function _RecapTopParks($win, $limit = 3, $kingdom_id = null)
    {
        $start = mysql_real_escape_string($win['WeekStart']);
        $end   = mysql_real_escape_string($win['WeekEnd']);
        $limit = intval($limit);
        $kid_clause = $kingdom_id ? ' AND a.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT p.park_id, p.name AS park_name, p.has_heraldry,
					   k.kingdom_id, k.name AS kingdom_name,
					   COUNT(DISTINCT a.mundane_id) AS attendance
				FROM " . DB_PREFIX . "attendance a
					JOIN " . DB_PREFIX . "park p ON p.park_id = a.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a.kingdom_id
				WHERE a.date >= '$start' AND a.date <= '$end'
				  AND a.mundane_id > 0
				  AND a.park_id > 0
				  $kid_clause
				GROUP BY p.park_id
				ORDER BY attendance DESC, p.name ASC
				LIMIT $limit";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array(
                    'ParkId'      => $r->park_id,
                    'ParkName'    => $r->park_name,
                    'HasHeraldry' => $r->has_heraldry,
                    'KingdomId'   => $r->kingdom_id,
                    'KingdomName' => $r->kingdom_name,
                    'Attendance'  => intval($r->attendance),
                );
            }
        }
        return $out;
    }

    // New player = first ever attendance falls in the window. NOT EXISTS subquery
    // mirrors the index-friendly pattern in GetNewPlayerAttendance().
    private function _RecapNewPlayers($win, $kingdom_id = null)
    {
        $start = mysql_real_escape_string($win['WeekStart']);
        $end   = mysql_real_escape_string($win['WeekEnd']);
        // Kingdom filter applies to the in-window attendance (where they first
        // signed in). The prior-history NOT EXISTS subquery stays global — we
        // want "truly new players who started here", not "new to this kingdom".
        $kid_clause = $kingdom_id ? ' AND a_in.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT m.mundane_id, m.persona,
					   p.park_id, p.name AS park_name,
					   k.kingdom_id, k.name AS kingdom_name,
					   MIN(a_in.date) AS first_date
				FROM " . DB_PREFIX . "attendance a_in
					JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a_in.mundane_id
					LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = a_in.park_id
					LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a_in.kingdom_id
				WHERE a_in.mundane_id > 0
				  AND a_in.date >= '$start' AND a_in.date <= '$end'
				  $kid_clause
				  AND NOT EXISTS (
					  SELECT 1 FROM " . DB_PREFIX . "attendance a_pre
					  WHERE a_pre.mundane_id = a_in.mundane_id
						AND a_pre.date < '$start'
					  LIMIT 1
				  )
				GROUP BY m.mundane_id
				ORDER BY first_date ASC, m.persona ASC";
        $r = $this->db->query($sql);
        $players = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $players[] = array(
                    'MundaneId'   => $r->mundane_id,
                    'Persona'     => $r->persona,
                    'FirstDate'   => $r->first_date,
                    'ParkId'      => $r->park_id,
                    'ParkName'    => $r->park_name,
                    'KingdomId'   => $r->kingdom_id,
                    'KingdomName' => $r->kingdom_name,
                );
            }
        }
        return array('Count' => count($players), 'Players' => $players);
    }

    // Returning = attended this week + previous attendance was >= $gap_days before week start.
    private function _RecapReturningPlayers($win, $gap_days = 90, $kingdom_id = null)
    {
        $start = mysql_real_escape_string($win['WeekStart']);
        $end   = mysql_real_escape_string($win['WeekEnd']);
        $gap   = intval($gap_days);
        // Kingdom filter applies to the in-window attendance. The "last prior
        // attendance" subquery stays global so the gap reflects their TRUE last
        // sign-in anywhere, not their last at this kingdom specifically.
        $kid_clause = $kingdom_id ? ' AND a_in.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT X.* FROM (
				  SELECT m.mundane_id, m.persona,
						 MIN(a_in.date) AS return_date,
						 (SELECT MAX(a_pre.date)
							FROM " . DB_PREFIX . "attendance a_pre
							WHERE a_pre.mundane_id = m.mundane_id
							  AND a_pre.date < '$start') AS last_prior_date,
						 p.park_id, p.name AS park_name,
						 k.kingdom_id, k.name AS kingdom_name
				  FROM " . DB_PREFIX . "attendance a_in
					  JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = a_in.mundane_id
					  LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = a_in.park_id
					  LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = a_in.kingdom_id
				  WHERE a_in.date >= '$start' AND a_in.date <= '$end'
					AND a_in.mundane_id > 0
					$kid_clause
				  GROUP BY m.mundane_id
				) X
				WHERE X.last_prior_date IS NOT NULL
				  AND DATEDIFF(X.return_date, X.last_prior_date) >= $gap
				ORDER BY DATEDIFF(X.return_date, X.last_prior_date) DESC, X.persona ASC";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $days = (int) round((strtotime($r->return_date) - strtotime($r->last_prior_date)) / 86400);
                $out[] = array(
                    'MundaneId'     => $r->mundane_id,
                    'Persona'       => $r->persona,
                    'ReturnDate'    => $r->return_date,
                    'LastPriorDate' => $r->last_prior_date,
                    'DaysAway'      => $days,
                    'ParkId'        => $r->park_id,
                    'ParkName'      => $r->park_name,
                    'KingdomId'     => $r->kingdom_id,
                    'KingdomName'   => $r->kingdom_name,
                );
            }
        }
        return $out;
    }

    // For each event occurrence in the window, count how many of the park's
    // occurrences started on or before this one. Surface ones where that count is
    // a multiple of $multiple. Counts occurrences (calendardetails), not distinct
    // events, so a recurring fighter practice contributes one per week.
    //
    // Subquery excludes zero/placeholder event_start dates (some legacy rows have
    // '0000-00-00') and tie-breaks on event_calendardetail_id so simultaneous
    // occurrences get distinct numbers — otherwise some milestones get skipped.
    private function _RecapMilestoneEvents($win, $multiple = 25, $kingdom_id = null)
    {
        $start = mysql_real_escape_string($win['StartDt']);
        $end   = mysql_real_escape_string($win['EndDt']);
        $mul   = intval($multiple);
        if ($mul <= 0) {
            $mul = 25;
        }
        $kid_clause = $kingdom_id ? ' AND e.kingdom_id IN (' . implode(',', array_map('intval', Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdom_id))) . ')' : '';
        $sql = "SELECT * FROM (
				  SELECT e.event_id, e.name AS event_name,
						 e.park_id, p.name AS park_name,
						 e.kingdom_id, k.name AS kingdom_name,
						 d.event_calendardetail_id, d.event_start,
						 (SELECT COUNT(*) FROM " . DB_PREFIX . "event_calendardetail d2
						  JOIN " . DB_PREFIX . "event e2 ON e2.event_id = d2.event_id
						  WHERE e2.park_id = e.park_id
							AND d2.current = 1
							AND d2.event_start > '2000-01-01'
							AND (d2.event_start < d.event_start
							     OR (d2.event_start = d.event_start
							         AND d2.event_calendardetail_id <= d.event_calendardetail_id))
						 ) AS event_number
				  FROM " . DB_PREFIX . "event e
					  JOIN " . DB_PREFIX . "event_calendardetail d
						ON d.event_id = e.event_id AND d.current = 1
					  LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = e.park_id
					  LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = e.kingdom_id
				  WHERE d.event_start >= '$start' AND d.event_start <= '$end'
					AND e.park_id > 0
					$kid_clause
				) M
				WHERE M.event_number > 0
				  AND M.event_number % $mul = 0
				ORDER BY M.event_number DESC, M.event_start ASC";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array(
                    'EventId'               => $r->event_id,
                    'EventCalendarDetailId' => $r->event_calendardetail_id,
                    'EventName'             => $r->event_name,
                    'EventStart'            => $r->event_start,
                    'EventNumber'           => intval($r->event_number),
                    'ParkId'                => $r->park_id,
                    'ParkName'              => $r->park_name,
                    'KingdomId'             => $r->kingdom_id,
                    'KingdomName'           => $r->kingdom_name,
                );
            }
        }
        return $out;
    }


    /**
     * ReleaseFeatureUtilization
     *
     * Returns adoption / utilization metrics for recent ORK3 feature releases,
     * shaped as a generic releases[] -> features[] -> { kpis[], charts[] } tree
     * so the template renders entirely from data (future features = data-only).
     *
     * @return array See the Reports_release_utilization data contract.
     */
    public function ReleaseFeatureUtilization()
    {
        $p = DB_PREFIX;

        // --- denominators -----------------------------------------------------
        // Active player definition: distinct players with >=1 attendance record
        // in the rolling 2 years (CURDATE() - 2 years .. now).
        $activePlayers = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}attendance` WHERE `date` >= DATE_SUB(CURDATE(), INTERVAL 2 YEAR)"
        );
        $playersWithDesign = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design`"
        );
        $activeRecommendations = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}recommendations` WHERE deleted_at IS NULL"
        );

        // player-scoped pct helper (null when denom is 0).
        $denom = $activePlayers;
        $pct = function ($value) use ($denom) {
            if ($denom <= 0) {
                return null;
            }
            return round(($value / $denom) * 100, 1);
        };

        // ====================================================================
        // RELEASE 3.5.4 — Walker  (Qualification Tests)
        // ====================================================================
        // Qualification tests are opt-in per kingdom, so adoption here is
        // KINGDOM-scoped, not player-scoped — $denom/$pct (active players) must
        // not be used for it. The denominator is active kingdoms, which includes
        // principalities and excludes Retired, deliberately matching the rule the
        // migration itself seeds the config switches with.
        $activeKingdoms = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}kingdom` WHERE active = 'Active'"
        );
        $kPct = function ($value) use ($activeKingdoms) {
            if ($activeKingdoms <= 0) {
                return null;
            }
            return round(($value / $activeKingdoms) * 100, 1);
        };

        // --- qualification test adoption -------------------------------------
        // The on/off switches live in ork_configuration rows, not a kingdom column,
        // and the value is JSON round-tripped: the stored literal is '"1"' / '"0"'
        // (a JSON-encoded string, quotes included), so compare against '"1"'. Each
        // count joins ork_kingdom so the numerator can never exceed the active-
        // kingdom denominator if a kingdom retires while its config row lingers.
        $qualReeveKingdoms = $this->_rfuScalar(
            "SELECT COUNT(*) AS c
			   FROM `{$p}configuration` cfg
			   JOIN `{$p}kingdom` k ON k.kingdom_id = cfg.id AND k.active = 'Active'
			  WHERE cfg.type = 'Kingdom' AND cfg.`key` = 'QualTestReeveEnabled' AND cfg.value = '\"1\"'"
        );
        $qualCorporaKingdoms = $this->_rfuScalar(
            "SELECT COUNT(*) AS c
			   FROM `{$p}configuration` cfg
			   JOIN `{$p}kingdom` k ON k.kingdom_id = cfg.id AND k.active = 'Active'
			  WHERE cfg.type = 'Kingdom' AND cfg.`key` = 'QualTestCorporaEnabled' AND cfg.value = '\"1\"'"
        );
        $qualEitherKingdoms = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT cfg.id) AS c
			   FROM `{$p}configuration` cfg
			   JOIN `{$p}kingdom` k ON k.kingdom_id = cfg.id AND k.active = 'Active'
			  WHERE cfg.type = 'Kingdom'
			    AND cfg.`key` IN ('QualTestReeveEnabled', 'QualTestCorporaEnabled')
			    AND cfg.value = '\"1\"'"
        );
        // "Takeable" is the honest companion to "enabled": a kingdom can flip the
        // switch on and still have nothing a player could sit. It counts only where
        // the test is enabled AND a published version exists AND that version holds
        // at least as many ACTIVE questions as the kingdom's configured
        // question_count. The app serves config defaults in-memory without writing
        // an ork_qual_config row, so a missing row means the default of 10.
        $qualTakeableKingdoms = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT k.kingdom_id) AS c
			   FROM `{$p}kingdom` k
			   JOIN `{$p}configuration` cfg
			     ON cfg.type = 'Kingdom' AND cfg.id = k.kingdom_id AND cfg.value = '\"1\"'
			    AND cfg.`key` IN ('QualTestReeveEnabled', 'QualTestCorporaEnabled')
			   JOIN `{$p}qual_question_set` qs
			     ON qs.kingdom_id = k.kingdom_id AND qs.status = 'published'
			    AND qs.test_type = CASE cfg.`key`
			                           WHEN 'QualTestReeveEnabled' THEN 'reeve'
			                           ELSE 'corpora'
			                       END
			   LEFT JOIN `{$p}qual_config` qc
			     ON qc.kingdom_id = k.kingdom_id AND qc.test_type = qs.test_type
			  WHERE k.active = 'Active'
			    AND (
			          SELECT COUNT(*) FROM `{$p}qual_set_question` sq
			          JOIN `{$p}qual_question` q
			            ON q.qual_question_id = sq.qual_question_id AND q.status = 'active'
			          WHERE sq.qual_question_set_id = qs.qual_question_set_id
			        ) >= COALESCE(qc.question_count, 10)"
        );
        $qualAdoptChart = array(
            'id'         => 'rfu-qual-adoption',
            'type'       => 'bar',
            'title'      => 'Kingdoms with each test enabled',
            'categories' => array("Reeve's Test", 'Corpora Test'),
            'series'     => array(
                array('name' => 'Enabled kingdoms', 'data' => array($qualReeveKingdoms, $qualCorporaKingdoms)),
            ),
        );
        $featQualAdoption = array(
            'key'         => 'qual_adoption',
            'title'       => 'Qualification Tests',
            'description' => "Kingdoms opt in to the Reeve's and Corpora qualification tests, then build and publish the version their players sit.",
            'kpis' => array(
                $this->_rfuKpi("Kingdoms with the Reeve's Test enabled", $qualReeveKingdoms, $activeKingdoms, $kPct($qualReeveKingdoms), "active kingdoms with QualTestReeveEnabled turned on", null, null, 'of active kingdoms'),
                $this->_rfuKpi('Kingdoms with the Corpora Test enabled', $qualCorporaKingdoms, $activeKingdoms, $kPct($qualCorporaKingdoms), 'active kingdoms with QualTestCorporaEnabled turned on', null, null, 'of active kingdoms'),
                $this->_rfuKpi('Kingdoms with either test enabled', $qualEitherKingdoms, $activeKingdoms, $kPct($qualEitherKingdoms), 'active kingdoms running at least one of the two tests', null, null, 'of active kingdoms'),
                $this->_rfuKpi('Kingdoms with a takeable test', $qualTakeableKingdoms, $activeKingdoms, $kPct($qualTakeableKingdoms), 'enabled AND a published version with enough active questions — players can actually sit it', null, null, 'of active kingdoms'),
            ),
            'charts' => array($qualAdoptChart),
            'links' => array(
                $this->_rfuQualKingdomTile("Kingdoms with the Reeve's Test enabled", 'QualTestReeveEnabled'),
                $this->_rfuQualKingdomTile('Kingdoms with the Corpora Test enabled', 'QualTestCorporaEnabled'),
            ),
        );

        // --- question banks & versions ---------------------------------------
        $qualQuestions = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question`");
        $qualQActive   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question` WHERE status = 'active'");
        $qualQArchived = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question` WHERE status = 'archived'");
        $qualImported  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question` WHERE source_question_id IS NOT NULL");
        $qualSets      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question_set`");
        $qualSetsLive  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_question_set` WHERE status = 'published'");
        $qualFlagged   = $this->_rfuScalar("SELECT COUNT(DISTINCT qual_question_id) AS c FROM `{$p}qual_report`");
        $qualSetBreak  = $this->_rfuBreakdown(
            "SELECT CASE status
						WHEN 'draft'     THEN 'Draft'
						WHEN 'published' THEN 'Published'
						ELSE 'Retired'
					END AS k, COUNT(*) AS c
			   FROM `{$p}qual_question_set`
			  GROUP BY k ORDER BY c DESC, k ASC"
        );
        $qualFlagBreak = $this->_rfuBreakdown(
            "SELECT CASE reason
						WHEN 'wording'  THEN 'Unclear wording'
						WHEN 'correct'  THEN 'Incorrect answer'
						WHEN 'outdated' THEN 'Outdated'
						ELSE 'Other'
					END AS k, COUNT(*) AS c
			   FROM `{$p}qual_report`
			  GROUP BY k ORDER BY c DESC, k ASC"
        );
        $featQualBank = array(
            'key'         => 'qual_bank',
            'title'       => 'Question Banks & Versions',
            'description' => 'Managers write or import questions, gather them into named versions, and publish one version at a time as the live test.',
            'kpis' => array(
                $this->_rfuKpi('Questions created', $qualQuestions, null, null, 'rows in qual question'),
                $this->_rfuKpi('Active questions', $qualQActive, null, null, 'questions available to be drawn into a test'),
                $this->_rfuKpi('Archived questions', $qualQArchived, null, null, 'questions retired from the bank'),
                $this->_rfuKpi('Imported from the shared library', $qualImported, null, null, 'questions copied from another kingdom rather than written locally'),
                $this->_rfuKpi('Test versions created', $qualSets, null, null, 'rows in qual question set'),
                $this->_rfuKpi('Published (live) versions', $qualSetsLive, null, null, "versions with status 'published' — one per kingdom and test type"),
                $this->_rfuKpi('Questions flagged', $qualFlagged, null, null, 'distinct questions with an OPEN flag — flags are deleted once a manager resolves them, so this is not a lifetime total'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-qual-sets', 'bar', 'Test versions by status', $qualSetBreak, 'Versions'),
                $this->_rfuChartFromBreakdown('rfu-qual-flags', 'bar', 'Open question flags by reason', $qualFlagBreak, 'Flags'),
            ),
        );

        // --- taking the test --------------------------------------------------
        // ork_qual_attempt logs EVERY submission, pass or fail, so pass rate is
        // per attempt (SUM(passed)/COUNT(*)). ork_qual_result is pass-only current
        // standing (one row per player+kingdom+type) and must not be used for it.
        $qualAttempts        = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt`");
        $qualAttemptsReeve   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt` WHERE test_type = 'reeve'");
        $qualAttemptsCorpora = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt` WHERE test_type = 'corpora'");
        $qualPassed          = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt` WHERE passed = 1");
        $qualPassedReeve     = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt` WHERE passed = 1 AND test_type = 'reeve'");
        $qualPassedCorpora   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_attempt` WHERE passed = 1 AND test_type = 'corpora'");
        $qualTakers          = $this->_rfuScalar("SELECT COUNT(DISTINCT player_id) AS c FROM `{$p}qual_attempt`");
        $qualCurrent         = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}qual_result` WHERE expires_at > NOW()");
        $qualRate        = ($qualAttempts > 0) ? round(100.0 * $qualPassed / $qualAttempts, 1) : null;
        $qualRateReeve   = ($qualAttemptsReeve > 0) ? round(100.0 * $qualPassedReeve / $qualAttemptsReeve, 1) : null;
        $qualRateCorpora = ($qualAttemptsCorpora > 0) ? round(100.0 * $qualPassedCorpora / $qualAttemptsCorpora, 1) : null;
        $qualOutcomeChart = array(
            'id'         => 'rfu-qual-outcomes',
            'type'       => 'column',
            'title'      => 'Attempts by test type: passed vs failed',
            'categories' => array("Reeve's Test", 'Corpora Test'),
            'series'     => array(
                array('name' => 'Passed', 'data' => array($qualPassedReeve, $qualPassedCorpora)),
                array('name' => 'Failed', 'data' => array($qualAttemptsReeve - $qualPassedReeve, $qualAttemptsCorpora - $qualPassedCorpora)),
            ),
        );
        $featQualTaking = array(
            'key'         => 'qual_taking',
            'title'       => 'Taking the Test',
            'description' => 'Players sit their kingdom\'s published test and earn a dated qualification; every submission is logged, pass or fail.',
            'kpis' => array(
                $this->_rfuKpi('Tests taken', $qualAttempts, null, null, 'total submissions logged, pass or fail'),
                $this->_rfuKpi("Reeve's Test attempts", $qualAttemptsReeve, null, null, "submissions of the Reeve's Test"),
                $this->_rfuKpi('Corpora Test attempts', $qualAttemptsCorpora, null, null, 'submissions of the Corpora Test'),
                $this->_rfuKpi('Pass rate', $qualRate, null, null, 'share of all attempts that passed — retakes count as separate attempts', null, null, null, '%', 1),
                $this->_rfuKpi("Reeve's Test pass rate", $qualRateReeve, null, null, "share of Reeve's Test attempts that passed — retakes count separately", null, null, null, '%', 1),
                $this->_rfuKpi('Corpora Test pass rate', $qualRateCorpora, null, null, 'share of Corpora Test attempts that passed — retakes count separately', null, null, null, '%', 1),
                $this->_rfuKpi('Players who have taken a test', $qualTakers, null, null, 'distinct players with at least one attempt'),
                $this->_rfuKpi('Players currently qualified', $qualCurrent, null, null, 'standing qualifications that have not yet expired'),
            ),
            'charts' => array($qualOutcomeChart),
        );

        $release354 = array(
            'version' => '3.5.4',
            'name'    => 'Walker',
            'date'    => '2026-07-15',
            'blurb'   => "Qualification Tests: kingdoms build a Reeve's and Corpora question bank, publish a versioned test, and players sit it for a dated qualification.",
            'features' => array(
                $featQualAdoption,
                $featQualBank,
                $featQualTaking,
            ),
        );

        // ====================================================================
        // RELEASE 3.5.3 — Rose  (Event Planning Expansion)
        // ====================================================================
        // Every Rose table/column below is new this release, so these are pure
        // post-launch adoption counts. A before/after activity-impact pass (like
        // Dragon's) is intentionally deferred: Rose ships 2026-07-01, so an
        // "after" window of ~0 days would be noise, not signal. Add it once the
        // release has accrued a meaningful post-launch window.

        // --- Event schedule & activity leads --------------------------------
        $schedItems      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_schedule`");
        $schedEvents     = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_schedule`");
        $schedAvg        = ($schedEvents > 0) ? round($schedItems / $schedEvents, 1) : 0;
        $schedSecondary  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_schedule` WHERE secondary_category IS NOT NULL AND secondary_category <> ''");
        $leadItems       = $this->_rfuScalar("SELECT COUNT(DISTINCT event_schedule_id) AS c FROM `{$p}event_schedule_lead`");
        $leadPeople      = $this->_rfuScalar("SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}event_schedule_lead`");
        $schedCatBreak   = $this->_rfuBreakdown(
            "SELECT category AS k, COUNT(*) AS c FROM `{$p}event_schedule`
			 WHERE category IS NOT NULL AND category <> ''
			 GROUP BY category ORDER BY c DESC, category ASC"
        );
        $featSchedule = array(
            'key'         => 'event_schedule',
            'title'       => 'Event Schedule & Activity Leads',
            'description' => 'Organizers build a per-occurrence agenda — activities with categories, locations, and the members leading each one.',
            'kpis' => array(
                $this->_rfuKpi('Events with a schedule', $schedEvents, null, null, 'distinct event occurrences with >=1 schedule item'),
                $this->_rfuKpi('Schedule items', $schedItems, null, null, 'rows in event schedule'),
                $this->_rfuKpi('Avg items per planned event', $schedAvg, null, null, 'schedule items / event that has a schedule', decimals: 1),
                $this->_rfuKpi('Items with a secondary tag', $schedSecondary, null, null, 'schedule items using a secondary category'),
                $this->_rfuKpi('Items with an activity lead', $leadItems, null, null, 'distinct schedule items naming a lead'),
                $this->_rfuKpi('Distinct activity leads', $leadPeople, null, null, 'distinct members leading an activity'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-sched-cat', 'bar', 'Schedule items by category', $schedCatBreak, 'Items'),
            ),
            'links' => array(
                $this->_rfuEventLinkTile('Example Events with a Schedule', 'event_schedule'),
            ),
        );

        // --- event staff & delegated capabilities ---------------------------
        $staffTotal  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_staff`");
        $staffEvents = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_staff`");
        $staffPeople = $this->_rfuScalar("SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}event_staff`");
        $capManage   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_staff` WHERE can_manage = 1");
        $capAttend   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_staff` WHERE can_attendance = 1");
        $capSchedule = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_staff` WHERE can_schedule = 1");
        $capFeast    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_staff` WHERE can_feast = 1");
        $capChart = array(
            'id'         => 'rfu-staff-caps',
            'type'       => 'bar',
            'title'      => 'Delegated capabilities in use',
            'categories' => array('Manage', 'Attendance', 'Schedule', 'Feast'),
            'series'     => array(array('name' => 'Staff with capability', 'data' => array($capManage, $capAttend, $capSchedule, $capFeast))),
        );
        $featStaff = array(
            'key'         => 'event_staff',
            'title'       => 'Event Staff & Delegated Capabilities',
            'description' => 'Officers appoint event staff and grant granular powers — manage, attendance, schedule, feast — without handing over full park-officer rights.',
            'kpis' => array(
                $this->_rfuKpi('Events with staff', $staffEvents, null, null, 'distinct event occurrences with >=1 staffer'),
                $this->_rfuKpi('Staff assignments', $staffTotal, null, null, 'rows in event staff'),
                $this->_rfuKpi('Distinct staffers', $staffPeople, null, null, 'distinct members given an event-staff role'),
                $this->_rfuKpi('Can manage', $capManage, null, null, 'staffers granted full event management'),
                $this->_rfuKpi('Can take attendance', $capAttend, null, null, 'staffers granted attendance / sign-in'),
                $this->_rfuKpi('Can edit schedule', $capSchedule, null, null, 'staffers granted schedule editing'),
                $this->_rfuKpi('Can edit feast', $capFeast, null, null, 'staffers granted feast editing'),
            ),
            'charts' => array($capChart),
            'links' => array(
                $this->_rfuEventLinkTile('Example Events with Staff', 'event_staff'),
            ),
        );

        // --- admission, fees & ticket links ---------------------------------
        // Ticket links are stored as ork_event_links rows with the ticket icon.
        $feeTiers     = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_fees`");
        $feeEvents    = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_fees`");
        $feePaid      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_fees` WHERE cost > 0");
        $feeFree      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_fees` WHERE cost = 0");
        $ticketEvents = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_links` WHERE icon = 'fas fa-ticket-alt'");
        // admission_type is free text ('Bronze', 'Mammoth', 'general admission', ...),
        // so grouping by it says nothing useful. Bucket the numeric cost instead and
        // keep the buckets in natural price order (ORDER BY MIN(cost)), not by count.
        // Buckets with no rows simply don't come back from GROUP BY — that's fine.
        $feeCostBreak = $this->_rfuBreakdown(
            "SELECT CASE
						WHEN cost <= 0  THEN 'Free'
						WHEN cost <= 10 THEN '1-10'
						WHEN cost <= 20 THEN '11-20'
						WHEN cost <= 30 THEN '21-30'
						WHEN cost <= 40 THEN '31-40'
						WHEN cost <= 50 THEN '41-50'
						WHEN cost <= 60 THEN '51-60'
						ELSE '60+'
					END AS k, COUNT(*) AS c
			   FROM `{$p}event_fees`
			  GROUP BY k ORDER BY MIN(cost)"
        );
        $featFees = array(
            'key'         => 'event_fees',
            'title'       => 'Admission, Fees & Ticket Links',
            'description' => 'Events publish priced admission tiers and a ticket-sales link so attendees know costs up front.',
            'kpis' => array(
                $this->_rfuKpi('Events with fee tiers', $feeEvents, null, null, 'distinct events listing admission tiers'),
                $this->_rfuKpi('Total fee tiers', $feeTiers, null, null, 'rows in event fees'),
                $this->_rfuKpi('Paid tiers', $feePaid, null, null, 'fee tiers with a cost above 0'),
                $this->_rfuKpi('Free tiers', $feeFree, null, null, 'fee tiers listed at no cost'),
                $this->_rfuKpi('Events with a ticket link', $ticketEvents, null, null, 'events linking out to ticket sales'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-fee-cost', 'bar', 'Admission tiers by cost', $feeCostBreak, 'Tiers'),
            ),
            'links' => array(
                $this->_rfuEventLinkTile('Example Events with Admission & Fees', 'event_fees'),
            ),
        );

        // --- feast planning & dietary needs ---------------------------------
        // Post-unify, feast rows live in ork_event_schedule (category 'Feast and Food').
        $feastItems    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_schedule` WHERE category = 'Feast and Food'");
        $feastEvents   = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_schedule` WHERE category = 'Feast and Food'");
        $feastMenu     = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_schedule` WHERE category = 'Feast and Food' AND menu IS NOT NULL AND menu <> ''");
        $feastDietInfo = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_schedule` WHERE category = 'Feast and Food' AND ((dietary IS NOT NULL AND dietary <> '') OR (allergens IS NOT NULL AND allergens <> ''))");
        $dietPlayers   = $this->_rfuScalar("SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}mundane_dietary`");
        // Aggregate (anonymized) dietary-need counts across the most common flags.
        $this->db->Clear();
        $dietBreak = array();
        $dietRow = $this->db->query(
            "SELECT
				SUM(diet_vegetarian)                  AS vegetarian,
				SUM(diet_vegan)                        AS vegan,
				SUM(diet_halal + diet_kosher)          AS faith_based,
				SUM(restrict_dairy + allergen_milk)    AS dairy,
				SUM(allergen_wheat)                    AS gluten_wheat,
				SUM(allergen_peanuts + allergen_treenuts) AS nuts,
				SUM(restrict_shellfish + allergen_shellfish) AS shellfish
			 FROM `{$p}mundane_dietary`"
        );
        if ($dietRow !== false && $dietRow->next()) {
            $dietBreak = array(
                array('k' => 'Vegetarian',       'c' => (int)$dietRow->vegetarian),
                array('k' => 'Vegan',            'c' => (int)$dietRow->vegan),
                array('k' => 'Halal / Kosher',   'c' => (int)$dietRow->faith_based),
                array('k' => 'Dairy-free',       'c' => (int)$dietRow->dairy),
                array('k' => 'Gluten / wheat',   'c' => (int)$dietRow->gluten_wheat),
                array('k' => 'Tree nut / peanut', 'c' => (int)$dietRow->nuts),
                array('k' => 'Shellfish',        'c' => (int)$dietRow->shellfish),
            );
            // This breakdown is hand-built rather than GROUP BY'd, so it needs the
            // same ordering the other bar charts get from SQL `ORDER BY c DESC` —
            // biggest first — to render consistently with them.
            usort($dietBreak, function ($a, $b) {
                return $b['c'] - $a['c'];
            });
        }
        $featFeast = array(
            'key'         => 'feast',
            'title'       => 'Feast Planning & Dietary Needs',
            'description' => 'Feast is part of the event schedule — menus, costs, dietary notes and allergens together — and players can record dietary preferences for planners.',
            'kpis' => array(
                $this->_rfuKpi('Events with a feast', $feastEvents, null, null, "events with a 'Feast and Food' schedule item"),
                $this->_rfuKpi('Feast items', $feastItems, null, null, 'feast / food schedule rows'),
                $this->_rfuKpi('Feast items with a menu', $feastMenu, null, null, 'feast items that list a menu'),
                $this->_rfuKpi('Feast items noting diet / allergens', $feastDietInfo, null, null, 'feast items recording dietary or allergen info'),
                $this->_rfuKpi('Players with dietary preferences', $dietPlayers, $denom, $pct($dietPlayers), 'players who saved dietary preferences'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-diet', 'bar', 'Dietary needs across players (aggregate)', $dietBreak, 'Players'),
            ),
            'links' => array(
                $this->_rfuEventLinkTile(
                    'Example Events with a Feast',
                    'event_schedule',
                    "src.category = 'Feast and Food'"
                ),
            ),
        );

        // --- day-of sign-in & self-registration -----------------------------
        $signinLinks    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}attendance_link`");
        $signinCreators = $this->_rfuScalar("SELECT COUNT(DISTINCT by_whom_id) AS c FROM `{$p}attendance_link`");
        $signinEventTied = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}attendance_link` WHERE event_id > 0");
        $selfregLinks   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}selfreg_link`");
        $selfregUsed    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}selfreg_link` WHERE used_by IS NOT NULL");
        $selfregConv    = ($selfregLinks > 0) ? round(100.0 * $selfregUsed / $selfregLinks, 1) : null;
        // Attendance entry-method metrics are scoped to the last 30 days. The
        // entry_method column is unindexed on a 3.5M-row table, so an all-time
        // count is a full table scan (x3 here); the `date` index turns each into
        // a ~1.4k-row range scan. It is also more meaningful: sign-in links and
        // self-reg launched with 3.5.3, so an all-time entry-method mix is ~100%
        // legacy 'manual' — a recent window shows how players are checking in now.
        $attnSignin     = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}attendance` WHERE `date` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND entry_method = 'signin_link'");
        $attnSelfreg    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}attendance` WHERE `date` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND entry_method = 'self_reg'");
        $entryBreak     = $this->_rfuBreakdown(
            "SELECT entry_method AS k, COUNT(*) AS c FROM `{$p}attendance`
			 WHERE `date` >= DATE_SUB(CURDATE(), INTERVAL 30 DAY) AND entry_method IS NOT NULL AND entry_method <> ''
			 GROUP BY entry_method ORDER BY c DESC, entry_method ASC"
        );
        $featSignin = array(
            'key'         => 'signin_selfreg',
            'title'       => 'Day-of Sign-In & Self-Registration',
            'description' => 'QR sign-in links let players check themselves in, and self-registration links let brand-new players create an account on the spot.',
            'kpis' => array(
                $this->_rfuKpi('Sign-in links created', $signinLinks, null, null, 'rows in attendance link'),
                $this->_rfuKpi('Officers issuing links', $signinCreators, null, null, 'distinct members who created a sign-in link'),
                $this->_rfuKpi('Links tied to an event', $signinEventTied, null, null, 'sign-in links scoped to a specific event'),
                $this->_rfuKpi('Check-ins via sign-in link (30d)', $attnSignin, null, null, "attendance in the last 30 days with entry_method 'signin_link'"),
                $this->_rfuKpi('Self-reg links created', $selfregLinks, null, null, 'rows in self-registration link'),
                $this->_rfuKpi('Self-reg links redeemed', $selfregUsed, $selfregLinks, $selfregConv, 'self-reg links that created a new player', null, null, 'of self-reg links were used'),
                $this->_rfuKpi('Self-reg check-ins recorded (30d)', $attnSelfreg, null, null, "attendance in the last 30 days with entry_method 'self_reg'"),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-entry-method', 'pie', 'Attendance by entry method (last 30 days)', $entryBreak, 'Sign-ins'),
            ),
        );

        // --- custom hero banners (all profile types) ------------------------
        $bannerPlayers  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}mundane` WHERE has_banner = 1");
        $bannerParks    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}park` WHERE has_banner = 1");
        $bannerKingdoms = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}kingdom` WHERE has_banner = 1");
        $bannerUnits    = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}unit` WHERE has_banner = 1");
        $bannerEvents   = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event` WHERE has_banner = 1");
        // ork_mundane records no "banner added" date, so there is no recency to sort
        // by — sample randomly from the players who turned one on.
        $this->db->Clear();
        $bannerRows = array();
        $bannerR = $this->db->query(
            "SELECT mundane_id, persona FROM `{$p}mundane`
			  WHERE has_banner = 1 AND persona IS NOT NULL AND persona <> ''
			  ORDER BY RAND() LIMIT 3"
        );
        if ($bannerR !== false) {
            while ($bannerR->next()) {
                $bannerRows[] = array(
                    'label' => $bannerR->persona,
                    'route' => 'Player/profile/' . (int)$bannerR->mundane_id,
                );
            }
        }
        $bannerChart = array(
            'id'         => 'rfu-banners',
            'type'       => 'bar',
            'title'      => 'Custom banners by profile type',
            'categories' => array('Players', 'Parks', 'Kingdoms', 'Units', 'Events'),
            'series'     => array(array('name' => 'With a custom banner', 'data' => array($bannerPlayers, $bannerParks, $bannerKingdoms, $bannerUnits, $bannerEvents))),
        );
        $featBanners = array(
            'key'         => 'hero_banners',
            'title'       => 'Custom Hero Banners',
            'description' => 'Players, parks, kingdoms, units and events can upload a framed hero banner image for their profile masthead.',
            'kpis' => array(
                $this->_rfuKpi('Players with a banner', $bannerPlayers, $denom, $pct($bannerPlayers), 'players with has_banner on'),
                $this->_rfuKpi('Parks with a banner', $bannerParks, null, null, 'parks with has_banner on'),
                $this->_rfuKpi('Kingdoms with a banner', $bannerKingdoms, null, null, 'kingdoms with has_banner on'),
                $this->_rfuKpi('Units with a banner', $bannerUnits, null, null, 'units with has_banner on'),
                $this->_rfuKpi('Events with a banner', $bannerEvents, null, null, 'events with has_banner on'),
            ),
            'charts' => array($bannerChart),
            'links' => array(
                $this->_rfuLinkTile('Example Players with a Hero Banner', $bannerRows),
            ),
        );

        // --- calendar items & smarter calendar ------------------------------
        $calItems       = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}calendar_item`");
        $calCreators    = $this->_rfuScalar("SELECT COUNT(DISTINCT created_by) AS c FROM `{$p}calendar_item` WHERE created_by > 0");
        $calKingdomWide = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}calendar_item` WHERE park_id = 0 AND kingdom_id > 0");
        $calOfficerOnly = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}calendar_item` WHERE is_officer_only = 1");
        $calLocalsOnly  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}calendar_item` WHERE is_locals_only = 1");
        $calAllDay      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}calendar_item` WHERE all_day = 1");
        // Calendar items have no deep-link route of their own (they render as a JS
        // overlay), so each links to its owning park — or its kingdom when the item
        // is kingdom-wide (park_id = 0) — and names that owner in the subtitle.
        $this->db->Clear();
        $calRows = array();
        $calR = $this->db->query(
            "SELECT ci.calendar_item_id, ci.name, ci.park_id, ci.kingdom_id,
					pk.name AS park_name, kd.name AS kingdom_name
			   FROM `{$p}calendar_item` ci
			   LEFT JOIN `{$p}park` pk ON pk.park_id = ci.park_id
			   LEFT JOIN `{$p}kingdom` kd ON kd.kingdom_id = ci.kingdom_id
			  WHERE ci.name IS NOT NULL AND ci.name <> ''
			  ORDER BY RAND() LIMIT 3"
        );
        if ($calR !== false) {
            while ($calR->next()) {
                $isPark = ((int)$calR->park_id > 0);
                $calRows[] = array(
                    'label' => $calR->name,
                    'route' => $isPark
                        ? 'Park/index/' . (int)$calR->park_id
                        : 'Kingdom/index/' . (int)$calR->kingdom_id,
                    'sub'   => $isPark ? $calR->park_name : $calR->kingdom_name,
                );
            }
        }
        // Flags can co-occur, so show them as independent bars (not a partition).
        $calFlagsChart = array(
            'id'         => 'rfu-cal-flags',
            'type'       => 'bar',
            'title'      => 'Calendar item options in use',
            'categories' => array('Kingdom-wide', 'Officer-only', 'Locals-only', 'All-day'),
            'series'     => array(array('name' => 'Items', 'data' => array($calKingdomWide, $calOfficerOnly, $calLocalsOnly, $calAllDay))),
        );
        $featCalendar = array(
            'key'         => 'calendar_items',
            'title'       => 'Calendar Items & Smarter Calendar',
            'description' => 'Kingdoms and parks add standalone calendar items — kingdom-wide entries plus officer-only or locals-only visibility.',
            'kpis' => array(
                $this->_rfuKpi('Calendar items', $calItems, null, null, 'rows in calendar item'),
                $this->_rfuKpi('Distinct creators', $calCreators, null, null, 'members who created a calendar item'),
                $this->_rfuKpi('Kingdom-wide items', $calKingdomWide, null, null, 'items scoped to a whole kingdom'),
                $this->_rfuKpi('Officer-only items', $calOfficerOnly, null, null, 'items visible to officers only'),
                $this->_rfuKpi('Locals-only items', $calLocalsOnly, null, null, 'items visible to local members only'),
            ),
            'charts' => array($calFlagsChart),
            'links' => array(
                $this->_rfuLinkTile('Example Calendar Items', $calRows),
            ),
        );

        // --- event types ----------------------------------------------------
        $typedOcc  = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_calendardetail` WHERE event_type IS NOT NULL AND event_type <> ''");
        $typeBreak = $this->_rfuBreakdown(
            "SELECT event_type AS k, COUNT(*) AS c FROM `{$p}event_calendardetail`
			 WHERE event_type IS NOT NULL AND event_type <> ''
			 GROUP BY event_type ORDER BY c DESC, event_type ASC LIMIT 15"
        );
        $featEventType = array(
            'key'         => 'event_type',
            'title'       => 'Event Types',
            'description' => 'Occurrences can be typed (Coronation, Midreign, Warmaster, etc.), driving the icons and labels that distinguish them on the Kingdom events tab.',
            'kpis' => array(
                $this->_rfuKpi('Typed occurrences', $typedOcc, null, null, 'event occurrences with an event type set'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-event-type', 'bar', 'Occurrences by event type', $typeBreak, 'Occurrences'),
            ),
        );

        // --- event external links -------------------------------------------
        $linkRows      = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}event_links`");
        $linkEvents    = $this->_rfuScalar("SELECT COUNT(DISTINCT event_calendardetail_id) AS c FROM `{$p}event_links`");
        $linkIconBreak = $this->_rfuBreakdown(
            "SELECT icon AS k, COUNT(*) AS c FROM `{$p}event_links`
			 WHERE icon IS NOT NULL AND icon <> ''
			 GROUP BY icon ORDER BY c DESC, icon ASC"
        );
        // Map raw FontAwesome classes to friendly link-type labels for the chart.
        $linkIconLabels = array(
            'fab fa-facebook'  => 'Facebook',
            'fab fa-discord'   => 'Discord',
            'fas fa-globe'     => 'Website',
            'far fa-clipboard' => 'Form / doc',
            'fas fa-link'      => 'Generic link',
            'fas fa-ticket-alt' => 'Ticket sales',
        );
        foreach ($linkIconBreak as &$linkRow) {
            if (isset($linkIconLabels[$linkRow['k']])) {
                $linkRow['k'] = $linkIconLabels[$linkRow['k']];
            }
        }
        unset($linkRow);
        $featLinks = array(
            'key'         => 'event_links',
            'title'       => 'Event External Links',
            'description' => 'Events attach external links — registration, rules, Discord, ticket sales and more.',
            'kpis' => array(
                $this->_rfuKpi('Events with links', $linkEvents, null, null, 'distinct events with >=1 external link'),
                $this->_rfuKpi('Total links', $linkRows, null, null, 'rows in event links'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-link-types', 'pie', 'External links by type', $linkIconBreak, 'Links'),
            ),
        );

        // --- flexible park-day recurrence -----------------------------------
        $pdEveryX     = $this->_rfuScalar("SELECT COUNT(*) AS c FROM `{$p}parkday` WHERE recurrence = 'every-x-weeks'");
        $pdRecurBreak = $this->_rfuBreakdown(
            "SELECT recurrence AS k, COUNT(*) AS c FROM `{$p}parkday`
			 WHERE recurrence IS NOT NULL AND recurrence <> ''
			 GROUP BY recurrence ORDER BY c DESC, recurrence ASC"
        );
        $featParkday = array(
            'key'         => 'parkday_recurrence',
            'title'       => 'Flexible Park-Day Recurrence',
            'description' => "Park days can recur on an 'every X weeks' cadence in addition to weekly, monthly and week-of-month.",
            'kpis' => array(
                $this->_rfuKpi("Park days on 'every X weeks'", $pdEveryX, null, null, 'parkday rows using the every-x-weeks cadence'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-parkday-recur', 'pie', 'Park-day recurrence modes', $pdRecurBreak, 'Park days'),
            ),
        );

        $release353 = array(
            'version' => '3.5.3',
            'name'    => 'Rose',
            'date'    => '2026-07-01',
            'blurb'   => 'Event Planning Expansion: schedule, staff, fees and feast on the event page, day-of QR sign-in and self-registration, custom hero banners, and a smarter calendar.',
            'features' => array(
                $featSchedule,
                $featStaff,
                $featFees,
                $featFeast,
                $featSignin,
                $featBanners,
                $featCalendar,
                $featEventType,
                $featLinks,
                $featParkday,
            ),
        );

        // ====================================================================
        // RELEASE 3.5.2 — Mask
        // ====================================================================

        // --- nameplate KPIs (all from ork_mundane_design) --------------------
        $dColorPrimary = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE color_primary IS NOT NULL AND color_primary <> ''"
        );
        $dPrefix = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE name_prefix IS NOT NULL AND name_prefix <> ''"
        );
        $dSuffix = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE name_suffix IS NOT NULL AND name_suffix <> ''"
        );
        $dGradient = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE hero_gradient IS NOT NULL AND hero_gradient <> ''"
        );
        $dFont = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE name_font IS NOT NULL AND name_font <> ''"
        );
        $dPronunciation = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design` WHERE pronunciation_guide IS NOT NULL AND pronunciation_guide <> ''"
        );
        $dAbout = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane_design`
			 WHERE (about_persona IS NOT NULL AND about_persona <> '')
			    OR (about_story IS NOT NULL AND about_story <> '')"
        );

        // nameplate breakdowns
        $fontBreak = $this->_rfuBreakdown(
            "SELECT name_font AS k, COUNT(*) AS c FROM `{$p}mundane_design`
			 WHERE name_font IS NOT NULL AND name_font <> ''
			 GROUP BY name_font ORDER BY c DESC, name_font ASC"
        );
        $beltBreak = $this->_rfuBreakdown(
            "SELECT belt_display AS k, COUNT(*) AS c FROM `{$p}mundane_design`
			 WHERE belt_display IS NOT NULL AND belt_display <> ''
			 GROUP BY belt_display ORDER BY c DESC, belt_display ASC"
        );
        $gradientBreak = $this->_rfuBreakdown(
            "SELECT hero_gradient AS k, COUNT(*) AS c FROM `{$p}mundane_design`
			 WHERE hero_gradient IS NOT NULL AND hero_gradient <> ''
			 GROUP BY hero_gradient ORDER BY c DESC, hero_gradient ASC"
        );

        $featNameplate = array(
            'key'         => 'nameplate',
            'title'       => 'Nameplate & Profile Story',
            'description' => 'Players personalize their profile masthead with custom colors, prefixes/suffixes, fonts, pronunciation guides and a written persona.',
            'kpis' => array(
                $this->_rfuKpi('Custom nameplate color', $dColorPrimary, $denom, $pct($dColorPrimary), 'players with color_primary set'),
                $this->_rfuKpi('Name prefix', $dPrefix, $denom, $pct($dPrefix), 'players with name_prefix set'),
                $this->_rfuKpi('Name suffix', $dSuffix, $denom, $pct($dSuffix), 'players with name_suffix set'),
                $this->_rfuKpi('Pride gradient', $dGradient, $denom, $pct($dGradient), 'players with hero_gradient set'),
                $this->_rfuKpi('Custom font', $dFont, $denom, $pct($dFont), 'players with name_font set'),
                $this->_rfuKpi('Pronunciation guide', $dPronunciation, $denom, $pct($dPronunciation), 'players with pronunciation_guide set'),
                $this->_rfuKpi('Custom About text', $dAbout, $denom, $pct($dAbout), 'players with about_persona or about_story set'),
                $this->_rfuKpi('Any design row', $playersWithDesign, $denom, $pct($playersWithDesign), 'players with a profile design record'),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-font', 'bar', 'Nameplate font adoption', $fontBreak, 'Players'),
                $this->_rfuChartFromBreakdown('rfu-belt', 'pie', 'Belt display choice', $beltBreak, 'Players'),
                $this->_rfuChartFromBreakdown('rfu-gradient', 'bar', 'Pride gradient adoption', $gradientBreak, 'Players'),
            ),
        );

        // --- accessibility fonts (ork_mundane) -------------------------------
        $basicFonts = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane` WHERE basic_fonts = 1"
        );
        $dyslexiaFonts = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane` WHERE dyslexia_fonts = 1"
        );
        $featViewerFonts = array(
            'key'         => 'viewer_fonts',
            'title'       => 'Accessibility Fonts',
            'description' => 'Readers can swap profile typography for simpler or dyslexia-friendly typefaces.',
            'kpis' => array(
                $this->_rfuKpi('Basic fonts enabled', $basicFonts, $denom, $pct($basicFonts), 'players with basic_fonts on'),
                $this->_rfuKpi('Dyslexia fonts enabled', $dyslexiaFonts, $denom, $pct($dyslexiaFonts), 'players with dyslexia_fonts on'),
            ),
            'charts' => array(),
        );

        // --- personal milestones (ork_player_milestones) ---------------------
        $milestoneTotal = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}player_milestones`"
        );
        $milestoneUsers = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}player_milestones`"
        );
        $milestoneAvg = ($milestoneUsers > 0) ? round($milestoneTotal / $milestoneUsers, 1) : 0;
        $featMilestones = array(
            'key'         => 'milestones',
            'title'       => 'Personal Milestones',
            'description' => 'Players pin dated, captioned milestones to their profile timeline.',
            'kpis' => array(
                $this->_rfuKpi('Total milestones', $milestoneTotal, null, null, 'rows in player milestones'),
                $this->_rfuKpi('Players using milestones', $milestoneUsers, $denom, $pct($milestoneUsers), 'distinct players with a milestone'),
                $this->_rfuKpi('Avg per adopting player', $milestoneAvg, null, null, 'milestones / adopting player'),
            ),
            'charts' => array(),
        );

        // --- recommendation seconds (ork_recommendation_seconds) -------------
        $secTotal = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}recommendation_seconds` WHERE deleted_at IS NULL"
        );
        $secRecs = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT recommendations_id) AS c FROM `{$p}recommendation_seconds` WHERE deleted_at IS NULL"
        );
        $secSupporters = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT supporter_mundane_id) AS c FROM `{$p}recommendation_seconds` WHERE deleted_at IS NULL"
        );
        $secNotes = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}recommendation_seconds` WHERE deleted_at IS NULL AND notes IS NOT NULL AND notes <> ''"
        );
        $secPctOfRecs = ($activeRecommendations > 0)
            ? round(($secRecs / $activeRecommendations) * 100, 1)
            : null;
        $featRecSeconds = array(
            'key'         => 'rec_seconds',
            'title'       => 'Recommendation Seconds',
            'description' => 'Members add weight to an award recommendation by seconding it, optionally with a note.',
            'kpis' => array(
                $this->_rfuKpi('Active seconds', $secTotal, null, null, 'non-deleted seconds'),
                $this->_rfuKpi('Recommendations seconded', $secRecs, null, null, 'distinct recommendations with a second'),
                $this->_rfuKpi('Unique seconders', $secSupporters, null, null, 'distinct supporting members'),
                $this->_rfuKpi('Seconds with a note', $secNotes, null, null, 'seconds carrying a written note'),
                $this->_rfuKpi('Recs with >=1 second', $secRecs, $activeRecommendations, $secPctOfRecs, 'share of active recommendations seconded'),
            ),
            'charts' => array(),
        );

        // --- recommendation activity: before vs after Mask -------------------
        // ork_recommendations.date_recommended (active rows only). Window math per
        // recon (see _rfuImpact); Mask released 2026-05-13.
        $maskDate  = '2026-05-13';
        $recImpact = $this->_rfuImpact('recommendations', 'date_recommended', $maskDate, 'deleted_at IS NULL');

        $featRecActivity = array(
            'key'         => 'rec_activity',
            'title'       => 'Recommendation Activity (before vs after)',
            'description' => 'Monthly award-recommendation submission rate in the 180 days before the 3.5.2 release versus the period since launch (normalized per month).',
            'kpis' => array(
                $this->_rfuKpi(
                    'Award recs / month',
                    round($recImpact['afterPerMonth'], 1),
                    null,
                    null,
                    'avg award recommendations per month since 3.5.2 (was ' . $recImpact['beforePerMonth'] . '/mo)',
                    $recImpact['delta'],
                    $recImpact['deltaDir']
                ),
            ),
            'charts' => array(
                array(
                    'id'         => 'rfu-impact-mask',
                    'type'       => 'column',
                    'title'      => 'Award recommendations / month: before vs after 3.5.2',
                    'categories' => array('Award recommendations'),
                    'series'     => array(
                        array(
                            'name' => 'Avg/mo before (180d)',
                            'data' => array(round($recImpact['beforePerMonth'], 1)),
                        ),
                        array(
                            'name' => 'Avg/mo after',
                            'data' => array(round($recImpact['afterPerMonth'], 1)),
                        ),
                    ),
                ),
            ),
        );

        $release352 = array(
            'version' => '3.5.2',
            'name'    => 'Mask',
            'date'    => '2026-05-13',
            'blurb'   => 'Profile storytelling: custom nameplates, milestones, and recommendation seconds.',
            'features' => array($featNameplate, $featViewerFonts, $featMilestones, $featRecSeconds, $featRecActivity),
        );

        // ====================================================================
        // RELEASE 3.5.1 — Owl
        // ====================================================================

        // --- event RSVP (ork_event_rsvp) -------------------------------------
        $rsvpTotal = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}event_rsvp`"
        );
        $rsvpUsers = $this->_rfuScalar(
            "SELECT COUNT(DISTINCT mundane_id) AS c FROM `{$p}event_rsvp`"
        );
        $rsvpBreak = $this->_rfuBreakdown(
            "SELECT status AS k, COUNT(*) AS c FROM `{$p}event_rsvp`
			 WHERE status IS NOT NULL AND status <> ''
			 GROUP BY status ORDER BY c DESC, status ASC"
        );
        $rsvpGoing = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}event_rsvp` WHERE status = 'going'"
        );
        $rsvpInterested = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}event_rsvp` WHERE status = 'interested'"
        );

        // --- RSVP show rate: 'going' RSVPs to PAST events (event_start <= now)
        // that have a matching attendance sign-in at the SAME event
        // (event_id - any instance of the event counts). Join/filter mirror the recon ground-truth.
        $nowStr = date('Y-m-d H:i:s');
        $nowStr = preg_replace('/[^0-9: -]/', '', $nowStr);
        $this->db->Clear();
        $showGoingPast = 0;
        $showAttended  = 0;
        $showRow = $this->db->query(
            "SELECT COUNT(DISTINCT rsvp.rsvp_id) AS going_past,
			        COUNT(DISTINCT CASE WHEN att.attendance_id IS NOT NULL THEN rsvp.rsvp_id END) AS going_attended
			 FROM `{$p}event_rsvp` rsvp
			 JOIN `{$p}event_calendardetail` cd
			   ON rsvp.event_calendardetail_id = cd.event_calendardetail_id
			 LEFT JOIN `{$p}attendance` att
			   ON att.mundane_id = rsvp.mundane_id
			  AND att.event_id = cd.event_id
			 WHERE rsvp.status = 'going'
			   AND cd.event_start <= '{$nowStr}'"
        );
        if ($showRow !== false && $showRow->next()) {
            $showGoingPast = (int)$showRow->going_past;
            $showAttended  = (int)$showRow->going_attended;
        }
        $showNoShow   = max(0, $showGoingPast - $showAttended);
        $showRatePct  = ($showGoingPast > 0)
            ? round(100.0 * $showAttended / $showGoingPast, 2)
            : null;

        $featEventRsvp = array(
            'key'         => 'event_rsvp',
            'title'       => 'Event RSVP',
            'description' => 'Players signal attendance on event pages as going or interested.',
            'kpis' => array(
                $this->_rfuKpi('Total RSVPs', $rsvpTotal, null, null, 'rows in event RSVP'),
                $this->_rfuKpi('Unique RSVPers', $rsvpUsers, $denom, $pct($rsvpUsers), 'distinct players who RSVPed'),
                $this->_rfuKpi("RSVP'd Going", $rsvpGoing, null, null, "RSVPs with status 'going'"),
                $this->_rfuKpi("RSVP'd Interested", $rsvpInterested, null, null, "RSVPs with status 'interested'"),
                $this->_rfuKpi(
                    'RSVP show rate',
                    $showAttended,
                    $showGoingPast,
                    $showRatePct,
                    "'going' RSVPs to past events where the player has any attendance/sign-in at that event (matched on player + event_id)",
                    null,
                    null,
                    "of 'going' RSVPs showed up"
                ),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-rsvp', 'pie', 'RSVP status', $rsvpBreak, 'RSVPs'),
                array(
                    'id'         => 'rfu-rsvp-show',
                    'type'       => 'pie',
                    'title'      => "Attended vs no-show ('going' RSVPs)",
                    'categories' => array('Attended', 'No-show'),
                    'data'       => array($showAttended, $showNoShow),
                ),
            ),
        );

        $release351 = array(
            'version' => '3.5.1',
            'name'    => 'Owl',
            'date'    => '2026-04-18',
            'blurb'   => 'A supercharged Event RSVP tab.',
            'features' => array($featEventRsvp),
        );

        // ====================================================================
        // RELEASE 3.5.0 — Dragon
        // ====================================================================

        // --- heraldry adoption -----------------------------------------------
        $playerHeraldry = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}mundane` WHERE has_heraldry = 1"
        );
        $eventHeraldry = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}event` WHERE has_heraldry = 1"
        );
        $eventBanner = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}event` WHERE has_banner = 1"
        );
        $featHeraldry = array(
            'key'         => 'heraldry',
            'title'       => 'Heraldry Adoption',
            'description' => 'Players and events display custom heraldry and banners.',
            'kpis' => array(
                $this->_rfuKpi('Players with heraldry', $playerHeraldry, $denom, $pct($playerHeraldry), 'players with has_heraldry on'),
                $this->_rfuKpi('Events with heraldry', $eventHeraldry, null, null, 'events with has_heraldry on'),
                $this->_rfuKpi('Events with a banner', $eventBanner, null, null, 'events with has_banner on'),
            ),
            'charts' => array(),
        );

        // --- weekly recap ----------------------------------------------------
        $recapWeeks = $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}weekly_recap`"
        );
        $featWeeklyRecap = array(
            'key'         => 'weekly_recap',
            'title'       => 'Weekly Recap',
            'description' => 'Auto-generated weekly digests of kingdom activity.',
            'kpis' => array(
                $this->_rfuKpi('Weeks computed', $recapWeeks, null, null, 'rows in weekly recap'),
            ),
            'charts' => array(),
        );

        // --- feature awareness (What's New) ----------------------------------
        $awarenessBreak = $this->_rfuBreakdown(
            "SELECT version AS k, COUNT(DISTINCT mundane_id) AS c FROM `{$p}whats_new_seen`
			 WHERE version IS NOT NULL AND version <> ''
			 GROUP BY version ORDER BY version DESC"
        );
        // latest version = max version string; its distinct-player count.
        $latestVersion = '';
        $latestSeen = 0;
        foreach ($awarenessBreak as $row) {
            if ($latestVersion === '' || strcmp($row['k'], $latestVersion) > 0) {
                $latestVersion = $row['k'];
                $latestSeen = (int)$row['c'];
            }
        }
        $featAwareness = array(
            'key'         => 'awareness',
            'title'       => 'Feature Awareness (What\'s New)',
            'description' => 'Players are shown a What\'s New modal each release; this tracks who has seen each version.',
            'kpis' => array(
                $this->_rfuKpi(
                    'Saw latest (' . ($latestVersion !== '' ? $latestVersion : 'n/a') . ')',
                    $latestSeen,
                    $denom,
                    $pct($latestSeen),
                    'distinct players who saw the newest What\'s New'
                ),
            ),
            'charts' => array(
                $this->_rfuChartFromBreakdown('rfu-awareness', 'bar', 'Players who viewed each What\'s-New version', $awarenessBreak, 'Players'),
            ),
        );

        // --- activity impact: before vs after the Dragon redesign ------------
        // Events use ork_event_calendardetail.event_start (the scheduled date);
        // RSVPs use ork_event_rsvp.modified. Window math per recon (see _rfuImpact).
        $dragonDate = '2026-04-02';
        $evtImpact  = $this->_rfuImpact('event_calendardetail', 'event_start', $dragonDate);
        $rsvpImpact = $this->_rfuImpact('event_rsvp', 'modified', $dragonDate);

        $featActivityImpact = array(
            'key'         => 'activity_impact',
            'title'       => 'Activity Impact (before vs after redesign)',
            'description' => 'Monthly event-creation and RSVP rates in the 180 days before the 3.5.0 redesign versus the period since launch (normalized per month).',
            'kpis' => array(
                $this->_rfuKpi(
                    'Events scheduled / month',
                    round($evtImpact['afterPerMonth'], 1),
                    null,
                    null,
                    'avg events scheduled per month since 3.5.0 (was ' . $evtImpact['beforePerMonth'] . '/mo)',
                    $evtImpact['delta'],
                    $evtImpact['deltaDir']
                ),
                $this->_rfuKpi(
                    'Event RSVPs / month',
                    round($rsvpImpact['afterPerMonth'], 1),
                    null,
                    null,
                    'avg RSVPs per month since 3.5.0 (was ' . $rsvpImpact['beforePerMonth'] . '/mo)',
                    $rsvpImpact['delta'],
                    $rsvpImpact['deltaDir']
                ),
            ),
            'charts' => array(
                array(
                    'id'         => 'rfu-impact-dragon',
                    'type'       => 'column',
                    'title'      => 'Monthly activity: before vs after 3.5.0',
                    'categories' => array('Events scheduled', 'Event RSVPs'),
                    'series'     => array(
                        array(
                            'name' => 'Avg/mo before (180d)',
                            'data' => array(
                                round($evtImpact['beforePerMonth'], 1),
                                round($rsvpImpact['beforePerMonth'], 1),
                            ),
                        ),
                        array(
                            'name' => 'Avg/mo after',
                            'data' => array(
                                round($evtImpact['afterPerMonth'], 1),
                                round($rsvpImpact['afterPerMonth'], 1),
                            ),
                        ),
                    ),
                ),
            ),
        );

        $release350 = array(
            'version' => '3.5.0',
            'name'    => 'Dragon',
            'date'    => '2026-04-02',
            'blurb'   => 'The big redesign: new profiles, events, tournaments — plus org-level adoption signals.',
            'features' => array(
                $featHeraldry,
                $featWeeklyRecap,
                $featAwareness,
                $featActivityImpact,
            ),
        );

        return array(
            'generated_at' => date('Y-m-d H:i:s'),
            'totals' => array(
                'active_players'         => (int)$activePlayers,
                'players_with_design'    => (int)$playersWithDesign,
                'active_recommendations' => (int)$activeRecommendations,
            ),
            'releases' => array($release354, $release353, $release352, $release351, $release350),
        );
    }

    /**
     * Run a single-column scalar COUNT-style query and return it as an int.
     * The query MUST alias its scalar as `c`. Matches the file's $this->db
     * query/next idiom; Clear() first to drop stale PDO bindings.
     */
    private function _rfuScalar($sql)
    {
        $this->db->Clear();
        $r = $this->db->query($sql);
        if ($r !== false && $r->next()) {
            return (int)$r->c;
        }
        return 0;
    }

    /**
     * Count rows of $table where its date column falls inclusively within
     * [$start, $end] (Y-m-d strings), with an optional extra WHERE clause.
     * Used for release before/after window math.
     */
    private function _rfuWindowCount($table, $dateCol, $start, $end, $extraWhere = '')
    {
        $p = DB_PREFIX;
        // $start/$end are code-generated Y-m-d strings (date()/strtotime), never
        // user input, so direct inlining matches this file's date-range idiom.
        $start = preg_replace('/[^0-9-]/', '', $start);
        $end   = preg_replace('/[^0-9-]/', '', $end);
        $where = "`{$dateCol}` >= '{$start} 00:00:00'"
            . " AND `{$dateCol}` <= '{$end} 23:59:59'";
        if ($extraWhere !== '') {
            $where .= ' AND ' . $extraWhere;
        }
        return $this->_rfuScalar(
            "SELECT COUNT(*) AS c FROM `{$p}{$table}` WHERE {$where}"
        );
    }

    /**
     * Compute a before/after per-month impact bundle for a release, matching the
     * recon windowDefinition exactly:
     *   BEFORE = [release - 180 days, release - 1 day]  -> 180 / 30.44 months
     *   AFTER  = [release, today]                       -> inclusive days / 30.44
     * Per-month rate = count / months. Delta% = round((after-before)/before*100).
     * Divide-by-zero guarded (delta=null, deltaDir='flat' when before rate is 0).
     *
     * @return array {beforeCount, afterCount, beforePerMonth, afterPerMonth,
     *                deltaPct(int|null), delta(string|null), deltaDir}
     */
    private function _rfuImpact($table, $dateCol, $releaseDate, $extraWhere = '')
    {
        $today  = date('Y-m-d');
        $relTs  = strtotime($releaseDate);
        $beforeStart = date('Y-m-d', strtotime('-180 days', $relTs));
        $beforeEnd   = date('Y-m-d', strtotime('-1 day', $relTs));

        $beforeCount = $this->_rfuWindowCount($table, $dateCol, $beforeStart, $beforeEnd, $extraWhere);
        $afterCount  = $this->_rfuWindowCount($table, $dateCol, $releaseDate, $today, $extraWhere);

        // Inclusive day spans -> month equivalents (30.44 days/month, per recon).
        $beforeMonths = 180 / 30.44;
        $afterDays    = (int)round((strtotime($today) - $relTs) / 86400) + 1; // inclusive
        $afterMonths  = $afterDays / 30.44;

        $beforePerMonth = ($beforeMonths > 0) ? round($beforeCount / $beforeMonths, 1) : 0.0;
        $afterPerMonth  = ($afterMonths > 0) ? round($afterCount / $afterMonths, 1) : 0.0;

        if ($beforePerMonth > 0) {
            $deltaPct = (int)round((($afterPerMonth - $beforePerMonth) / $beforePerMonth) * 100);
            $delta    = ($deltaPct >= 0 ? '+' : '') . $deltaPct . '%';
            if ($afterPerMonth > $beforePerMonth) {
                $deltaDir = 'up';
            } elseif ($afterPerMonth < $beforePerMonth) {
                $deltaDir = 'down';
            } else {
                $deltaDir = 'flat';
            }
        } else {
            $deltaPct = null;
            $delta    = null;
            $deltaDir = 'flat';
        }

        return array(
            'beforeCount'    => (int)$beforeCount,
            'afterCount'     => (int)$afterCount,
            'beforePerMonth' => $beforePerMonth,
            'afterPerMonth'  => $afterPerMonth,
            'deltaPct'       => $deltaPct,
            'delta'          => $delta,
            'deltaDir'       => $deltaDir,
        );
    }

    /**
     * Run a "SELECT <label> AS k, COUNT(*) AS c ... GROUP BY ..." query and
     * return an ordered array of ['k' => label, 'c' => int count] rows.
     */
    private function _rfuBreakdown($sql)
    {
        $this->db->Clear();
        $out = array();
        $r = $this->db->query($sql);
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $out[] = array('k' => (string)$r->k, 'c' => (int)$r->c);
            }
        }
        return $out;
    }

    /**
     * Build one KPI tile entry for the data contract.
     *
     * @param string|null $delta    Preformatted signed percent string (e.g. '+37%'); null when N/A.
     * @param string|null $deltaDir 'up' | 'down' | 'flat' — drives the colored delta pill.
     */
    private function _rfuKpi($label, $value, $denom, $pct, $hint, $delta = null, $deltaDir = null, $pctLabel = null, $suffix = null, $decimals = 0)
    {
        $kpi = array(
            'label'    => $label,
            'value'    => $value,
            'denom'    => ($denom === null) ? null : (int)$denom,
            'pct'      => ($pct === null) ? null : $pct,
            'hint'     => ($hint === null) ? null : $hint,
            'delta'    => ($delta === null) ? null : (string)$delta,
            'deltaDir' => ($deltaDir === null) ? 'flat' : $deltaDir,
        );
        if ($pctLabel !== null && $pctLabel !== '') {
            $kpi['pctLabel'] = (string)$pctLabel;
        }
        if ($suffix !== null && $suffix !== '') {
            $kpi['suffix'] = (string)$suffix;
        }
        // Rates and averages carry a decimal place; plain counts render whole.
        if ((int)$decimals > 0) {
            $kpi['decimals'] = (int)$decimals;
        }
        return $kpi;
    }

    /**
     * Format a raw DB datetime as a human-readable date. The project never
     * surfaces raw ISO timestamps, so link-tile subtitles go through here.
     * Returns null for empty / zero / unparseable values so the tile simply
     * omits the subtitle rather than printing a bogus date.
     */
    private function _rfuNiceDate($raw)
    {
        if ($raw === null || $raw === '' || strncmp((string)$raw, '0000-00-00', 10) === 0) {
            return null;
        }
        $ts = strtotime((string)$raw);
        return ($ts === false) ? null : date('F j, Y', $ts);
    }

    /**
     * Build one link-tile entry for the data contract. Always emits a valid tile
     * (empty items[] when there is nothing to link) so the template can render a
     * graceful empty state, mirroring _rfuChartFromBreakdown's contract.
     *
     * @param array $rows Each ['label' => string, 'route' => string, 'sub' => string|null].
     *                    Rows with a blank label are skipped.
     */
    private function _rfuLinkTile($title, $rows)
    {
        $items = array();
        foreach ($rows as $row) {
            $label = isset($row['label']) ? trim((string)$row['label']) : '';
            if ($label === '') {
                continue;
            }
            $item = array(
                'label' => $label,
                'route' => (string)$row['route'],
            );
            if (isset($row['sub']) && $row['sub'] !== null && $row['sub'] !== '') {
                $item['sub'] = (string)$row['sub'];
            }
            $items[] = $item;
        }
        return array(
            'title' => $title,
            'items' => $items,
        );
    }

    /**
     * Build a "3 random example events" link tile for a Rose event feature.
     * Every Rose event table keys on event_calendardetail_id, so the route needs
     * both ids: join up to the occurrence for event_id and to the event for its
     * name. $table / $extraWhere are code-supplied constants, never user input.
     *
     * @param string $table      Event child table, minus the DB prefix.
     * @param string $extraWhere Optional additional filter on that table (alias `src`).
     */
    private function _rfuEventLinkTile($title, $table, $extraWhere = '')
    {
        $p = DB_PREFIX;
        $where = "e.name IS NOT NULL AND e.name <> ''";
        if ($extraWhere !== '') {
            $where .= ' AND ' . $extraWhere;
        }
        $this->db->Clear();
        $rows = array();
        $r = $this->db->query(
            "SELECT DISTINCT e.name AS label, cd.event_id AS eid,
					cd.event_calendardetail_id AS cdid, cd.event_start AS starts
			   FROM `{$p}{$table}` src
			   JOIN `{$p}event_calendardetail` cd ON cd.event_calendardetail_id = src.event_calendardetail_id
			   JOIN `{$p}event` e ON e.event_id = cd.event_id
			  WHERE {$where}
			  ORDER BY RAND() LIMIT 3"
        );
        if ($r !== false) {
            while ($r->next()) {
                $rows[] = array(
                    'label' => $r->label,
                    'route' => 'Event/detail/' . (int)$r->eid . '/' . (int)$r->cdid,
                    'sub'   => $this->_rfuNiceDate($r->starts),
                );
            }
        }
        return $this->_rfuLinkTile($title, $rows);
    }

    /**
     * Build the full list of active kingdoms that have a qualification-test switch
     * turned on, as a link tile. The switch lives in ork_configuration and its
     * value is JSON round-tripped, so the stored literal is '"1"' (quotes included).
     * $key is a code-supplied constant, never user input, but it is whitelisted
     * anyway since it is inlined into the SQL.
     *
     * @param string $key 'QualTestReeveEnabled' | 'QualTestCorporaEnabled'
     */
    private function _rfuQualKingdomTile($title, $key)
    {
        $p = DB_PREFIX;
        $allowed = array('QualTestReeveEnabled', 'QualTestCorporaEnabled');
        if (!in_array($key, $allowed, true)) {
            return $this->_rfuLinkTile($title, array());
        }
        $this->db->Clear();
        $rows = array();
        $r = $this->db->query(
            "SELECT k.kingdom_id, k.name
			   FROM `{$p}configuration` cfg
			   JOIN `{$p}kingdom` k ON k.kingdom_id = cfg.id AND k.active = 'Active'
			  WHERE cfg.type = 'Kingdom' AND cfg.`key` = '{$key}' AND cfg.value = '\"1\"'
			  ORDER BY k.name ASC"
        );
        if ($r !== false) {
            while ($r->next()) {
                $rows[] = array(
                    'label' => $r->name,
                    'route' => 'Kingdom/index/' . (int)$r->kingdom_id,
                );
            }
        }
        return $this->_rfuLinkTile($title, $rows);
    }

    /**
     * Convert a breakdown array into a chart entry. Always emits a valid chart
     * (empty categories/data when the breakdown is empty) so the template can
     * render a graceful "No data yet" state.
     *
     * @param string $type 'column' | 'bar' | 'pie'
     */
    private function _rfuChartFromBreakdown($id, $type, $title, $breakdown, $seriesName = 'Count')
    {
        $categories = array();
        $data = array();
        foreach ($breakdown as $row) {
            $categories[] = $row['k'];
            $data[] = (int)$row['c'];
        }
        $chart = array(
            'id'         => $id,
            'type'       => $type,
            'title'      => $title,
            'categories' => $categories,
        );
        if ($type === 'pie') {
            $chart['data'] = $data;
        } else {
            $chart['series'] = array(
                array('name' => $seriesName, 'data' => $data),
            );
        }
        return $chart;
    }

}
