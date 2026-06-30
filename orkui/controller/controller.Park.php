<?php

class Controller_Park extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $id = preg_replace('/[^0-9]/', '', $id);

        if ($id != $this->session->park_id) {
            unset($this->session->kingdom_id);
            unset($this->session->kingdom_name);
            unset($this->session->park_name);
            unset($this->session->park_id);
        }

        $this->session->park_id = $id;

        if (!isset($this->session->kingdom_id)) {
            // Direct link
            $park_info = $this->Park->get_park_info($id);
            $this->session->park_name = $park_info[ 'ParkInfo' ][ 'ParkName' ];
            $this->session->kingdom_id = $park_info[ 'KingdomInfo' ][ 'KingdomId' ];
            $this->session->kingdom_name = $park_info[ 'KingdomInfo' ][ 'KingdomName' ];
        }
        $this->data[ 'kingdom_id' ] = $this->session->kingdom_id;
        $this->data[ 'park_id' ] = $this->session->park_id;
        $this->data[ 'kingdom_name' ] = $this->session->kingdom_name;

        if (isset($this->request->park_name)) {
            $this->session->park_name = $this->request->park_name;
        }
        $this->data[ 'park_name' ] = $this->session->park_name;
        $this->data[ 'page_title' ] = $this->session->park_name;

        $_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$id, AUTH_EDIT)) {
            $this->data[ 'menu' ][ 'admin' ] = [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' ];
            $this->data[ 'menulist' ][ 'admin' ] = [
                [ 'url' => UIR . 'Admin/park/' . $this->session->park_id, 'display' => 'Park' ],
                [ 'url' => UIR . 'Admin/kingdom/' . $this->session->kingdom_id, 'display' => 'Kingdom' ],
            ];
        }
        $this->data[ 'menu' ][ 'kingdom' ] = [ 'url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name ];
        $this->data[ 'menu' ][ 'park' ] = [ 'url' => UIR . 'Park/profile/' . $this->session->park_id, 'display' => $this->session->park_name ];
    }

    public function index($park_id = null)
    {
        $park_id = preg_replace('/[^0-9]/', '', $park_id);
        $this->load_model('Reports');
        $this->data[ 'event_summary' ] = $this->Park->get_park_events($park_id);
        $this->data[ 'park_days' ] = $this->Park->get_park_parkdays($park_id);
        $this->data[ 'park_info' ] = $this->Park->get_park_details($park_id);
        $this->data[ 'park_officers' ] = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
        // [TOURNAMENTS HIDDEN] $this->data['park_tournaments'] = [];
    }

    public function profile($park_id = null)
    {
        $this->template = '../revised-frontend/Parknew_index.tpl';
        $park_id = preg_replace('/[^0-9]/', '', $park_id);
        if (!valid_id($park_id)) {
            header('Location: ' . UIR);
            exit;
        }
        $this->load_model('Award');
        $this->load_model('Attendance');
        $this->load_model('Reports');
        $this->load_model('Pronoun');

        $this->data['kingdom_name'] = $this->session->kingdom_name;
        $this->data['menu']['kingdom'] = [
            'url'     => UIR . 'Kingdom/profile/' . $this->session->kingdom_id,
            'display' => $this->session->kingdom_name,
        ];
        $this->data['menu']['park'] = [
            'url'     => UIR . 'Park/profile/' . $this->session->park_id,
            'display' => $this->session->park_name,
        ];

        $this->data['park_days']        = $this->Park->get_park_parkdays($park_id);
        $this->data['park_info']        = $this->Park->get_park_details($park_id);
        if (empty($this->data['park_info']['ParkInfo']['ParkId'])) {
            header('Location: ' . UIR);
            exit;
        }
        $this->data['park_weather']     = Ork3::$Lib->weather->for_park($park_id);
        $this->data['park_officers']    = $this->Park->GetOfficers(['ParkId' => $park_id, 'Token' => $this->session->token]);
        $this->data['park_tournaments'] = $this->Reports->get_tournaments(null, null, $park_id);

        // Gate the "Voting Eligible" Players-nav link by whether this park's kingdom
        // has voting rules defined. Single source of truth lives in
        // Model_Reports::supported_voting_kingdom_ids() — don't hardcode the list here.
        $this->data['ShowVotingEligibleLink'] = in_array(
            (int)$this->session->kingdom_id,
            $this->Reports->supported_voting_kingdom_ids()
        );

        $this->data['AwardOptions']   = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
        $this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
        $this->data['CustomTitleAliasOptions'] = $this->Award->fetch_custom_title_alias_options();
        $preloadOfficers = [];
        foreach ($this->data['park_officers']['Officers'] ?? [] as $o) {
            if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0) {
                $preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
            }
        }
        $this->load_model('Kingdom');
        $kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
        if (is_array($kingdomOfficers)) {
            foreach ($kingdomOfficers as $o) {
                if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0) {
                    $preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => 'Kingdom ' . $o['OfficerRole']];
                }
            }
        }
        $this->data['PreloadOfficers'] = $preloadOfficers;

        $classesResult = $this->Attendance->get_classes();
        $this->data['Classes'] = array_map(function ($c) {
            return ['ClassId' => $c['ClassId'], 'ClassName' => $c['Name']];
        }, $classesResult['Classes'] ?? []);

        $recentResult = $this->Attendance->get_recent_attendees($park_id);
        $this->data['RecentAttendees'] = $recentResult['Attendees'] ?? [];

        global $DB;
        $pid = (int)$park_id;

        // Viewer identity for permission gates below ($pk_isAdmin guards draft-event
        // visibility; $pk_uid is also passed to CalendarItem::CanSee for officer-/
        // locals-only filtering). Without these initialized, every officer-only
        // calendar item was filtered out of the Park calendar — even from the
        // creator and ORK admins.
        $pk_uid     = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $pk_isAdmin = ($pk_uid > 0) ? Ork3::$Lib->authorization->HasAuthority($pk_uid, AUTH_ADMIN, 0, AUTH_CREATE) : false;

        // Drafts are hidden from the listing at the SQL level (mirrors the Kingdom
        // controller): admins see all; logged-in users see published + their own
        // drafts; logged-out users see only published. Required because some draft
        // events have mundane_id = 0, so the per-row creator check alone would leak
        // them to anonymous viewers (0 === 0).
        $pk_draftClause = $pk_isAdmin
            ? ''
            : ($pk_uid > 0 ? "AND (e.status = 'published' OR e.mundane_id = {$pk_uid})" : "AND e.status = 'published'");

        // Viewer's own RSVP status for each event-occurrence. Used by the row's
        // RSVP button to render "Going" / "Interested" instead of the generic
        // "RSVP" when the viewer has already RSVP'd. Anonymous viewer emits NULL
        // to skip the correlated subquery (matches Kingdom controller pattern).
        $pk_myRsvpSubq = $pk_uid > 0
            ? "(SELECT status FROM " . DB_PREFIX . "event_rsvp WHERE event_calendardetail_id = cd.event_calendardetail_id AND mundane_id = " . (int)$pk_uid . " LIMIT 1)"
            : "NULL";

        $evtSql = "
			SELECT e.event_id, e.name, e.status, e.mundane_id AS event_creator, p.name AS park_name,
			       cd.event_start, cd.event_end, cd.event_calendardetail_id AS next_detail_id, e.has_heraldry,
			       COALESCE(rsvp.rsvp_going, 0) AS rsvp_going,
			       COALESCE(rsvp.rsvp_interested, 0) AS rsvp_interested,
			       {$pk_myRsvpSubq} AS my_rsvp
			FROM ork_event e
			LEFT JOIN ork_park p ON p.park_id = e.park_id
			JOIN ork_event_calendardetail cd ON cd.event_id = e.event_id
			    AND cd.event_start >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			    AND cd.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			LEFT JOIN (
			    SELECT
			        event_calendardetail_id,
			        SUM(status = 'going') AS rsvp_going,
			        SUM(status = 'interested') AS rsvp_interested
			    FROM ork_event_rsvp
			    GROUP BY event_calendardetail_id
			) rsvp ON rsvp.event_calendardetail_id = cd.event_calendardetail_id
			WHERE (e.park_id = {$pid} OR cd.at_park_id = {$pid})
			{$pk_draftClause}
			ORDER BY cd.event_start, e.name";
        $DB->Clear();
        $evtResult    = $DB->DataSet($evtSql);
        $eventSummary = [];
        if ($evtResult) {
            do {
                $eid = (int)($evtResult->event_id ?? 0);
                if ($eid) {
                    $row_status = (string)($evtResult->status ?? 'published');
                    if ($row_status !== 'published' && !$pk_isAdmin && (int)$evtResult->event_creator !== $pk_uid) {
                        $canEditRow = ($pk_uid > 0) ? Ork3::$Lib->authorization->HasAuthority($pk_uid, AUTH_EVENT, $eid, AUTH_EDIT) : false;
                        if (!$canEditRow) {
                            continue;
                        }
                    }
                    $eventSummary[] = [
                        'EventId'      => $eid,
                        'Name'         => $evtResult->name,
                        'ParkName'     => $evtResult->park_name,
                        'NextDate'     => $evtResult->event_start,
                        'NextEndDate'  => $evtResult->event_end,
                        'NextDetailId' => (int)$evtResult->next_detail_id,
                        'HasHeraldry'  => (int)$evtResult->has_heraldry,
                        'RsvpGoing'      => (int)$evtResult->rsvp_going,
                    'RsvpInterested' => (int)$evtResult->rsvp_interested,
                    'MyRsvp'         => (string)($evtResult->my_rsvp ?? ''),
                    'Status'         => $row_status,
                    ];
                }
            } while ($evtResult->Next());
        }
        // Merge calendar items (park-scoped AND parent-kingdom-scoped) into the list.
        $kidForPark = (int)$this->session->kingdom_id;
        $ciSql = "
			SELECT ci.calendar_item_id, ci.name, ci.description, ci.all_day, ci.is_officer_only, ci.is_locals_only, ci.color,
			       ci.event_start, ci.event_end, ci.park_id, ci.kingdom_id,
			       p.name AS park_name, p.abbreviation AS park_abbr, k.abbreviation AS kingdom_abbr
			FROM " . DB_PREFIX . "calendar_item ci
			LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = ci.park_id
			LEFT JOIN " . DB_PREFIX . "kingdom k ON k.kingdom_id = ci.kingdom_id
			WHERE (ci.park_id = {$pid} OR (ci.park_id = 0 AND ci.kingdom_id = {$kidForPark}))
			  AND ci.event_end >= DATE_SUB(NOW(), INTERVAL 7 DAY)
			  AND ci.event_start <= DATE_ADD(NOW(), INTERVAL 12 MONTH)
			ORDER BY ci.event_start";
        $DB->Clear();
        $ciResult = $DB->DataSet($ciSql);
        while ($ciResult && $ciResult->Next()) {
            $ci_isOfficerOnly = (int)$ciResult->is_officer_only;
            $ci_isLocalsOnly  = (int)$ciResult->is_locals_only;
            if (!CalendarItem::CanSee($pk_uid, (int)$ciResult->kingdom_id, (int)$ciResult->park_id, $ci_isOfficerOnly, $ci_isLocalsOnly)) {
                continue;
            }
            $eventSummary[] = [
                'CalendarItemId' => (int)$ciResult->calendar_item_id,
                'Name'           => $ciResult->name,
                'ParkName'       => $ciResult->park_name,
                'ParkAbbr'       => $ciResult->park_abbr,
                'KingdomAbbr'    => $ciResult->kingdom_abbr,
                'NextDate'       => $ciResult->event_start,
                'NextEndDate'    => $ciResult->event_end,
                'AllDay'         => (int)$ciResult->all_day,
                'Description'    => $ciResult->description,
                'IsOfficerOnly'  => $ci_isOfficerOnly,
                'IsLocalsOnly'   => $ci_isLocalsOnly,
                'Color'          => $ciResult->color ?: '#64748b',
                'ColorText'      => CalendarItem::TextColorFor($ciResult->color ?: '#64748b'),
                '_IsCalendarItem' => true,
                '_IsKingdomLevel' => (int)$ciResult->park_id === 0,
            ];
        }
        usort($eventSummary, function ($a, $b) {
            return strcmp($a['NextDate'] ?? '', $b['NextDate'] ?? '');
        });

        // Resolve event coords (event Location JSON → at_park park lat/lng → host park lat/lng) and build map locations.
        $nowStamp        = time();
        $horizonStamp    = $nowStamp + (90 * 86400);
        $pkEventMapLocs  = [];
        $pkMapNoLocCount = 0;
        foreach ($eventSummary as &$_evt) {
            if (empty($_evt['EventId'])) {
                continue;
            }
            $startTs = strtotime($_evt['NextDate'] ?? '');
            if (!$startTs || $startTs > $horizonStamp) {
                continue;
            }

            $DB->Clear();
            $cdRow = $DB->DataSet("
				SELECT cd.location AS event_loc, cd.at_park_id, p.latitude AS at_park_lat, p.longitude AS at_park_lng
				FROM " . DB_PREFIX . "event_calendardetail cd
				LEFT JOIN " . DB_PREFIX . "park p ON p.park_id = cd.at_park_id
				WHERE cd.event_calendardetail_id = " . (int)$_evt['NextDetailId'] . " LIMIT 1");
            $lat = null;
            $lng = null;
            if ($cdRow && $cdRow->Next()) {
                $rawLoc = (string)($cdRow->event_loc ?? '');
                if ($rawLoc) {
                    $loc = @json_decode(stripslashes($rawLoc));
                    if ($loc) {
                        $pt = isset($loc->location) ? $loc->location
                            : (isset($loc->bounds->northeast) ? $loc->bounds->northeast : null);
                        if ($pt && is_numeric($pt->lat ?? null) && is_numeric($pt->lng ?? null)) {
                            $lat = (float)$pt->lat;
                            $lng = (float)$pt->lng;
                        }
                    }
                }
                if ($lat === null && is_numeric($cdRow->at_park_lat) && (float)$cdRow->at_park_lat != 0) {
                    $lat = (float)$cdRow->at_park_lat;
                    $lng = (float)$cdRow->at_park_lng;
                }
            }
            // Fall back to the host park coords (current park context).
            if ($lat === null) {
                $DB->Clear();
                $hostRow = $DB->DataSet("SELECT latitude, longitude FROM " . DB_PREFIX . "park WHERE park_id = {$pid} LIMIT 1");
                if ($hostRow && $hostRow->Next() && is_numeric($hostRow->latitude) && (float)$hostRow->latitude != 0) {
                    $lat = (float)$hostRow->latitude;
                    $lng = (float)$hostRow->longitude;
                }
            }


            if ($lat !== null && $startTs >= ($nowStamp - 86400)) {
                $pkEventMapLocs[] = [
                    'event_id'                => (int)$_evt['EventId'],
                    'event_calendardetail_id' => (int)($_evt['NextDetailId'] ?? 0),
                    'name'                    => $_evt['Name'],
                    'date'                    => date('Y-m-d', $startTs),
                    'date_label'              => date('M j, Y', $startTs),
                    'park_name'               => $_evt['ParkName'] ?? '',
                    'lat'                     => $lat,
                    'lng'                     => $lng,
                    'my_rsvp'                 => $_evt['MyRsvp'] ?? '',
                    'going'                   => (int)($_evt['RsvpGoing'] ?? 0),
                    'interested'              => (int)($_evt['RsvpInterested'] ?? 0),
                    'is_draft'                => (($_evt['Status'] ?? 'published') === 'draft'),
                ];
            } elseif ($lat === null && $startTs <= $horizonStamp && $startTs >= ($nowStamp - 86400)) {
                $pkMapNoLocCount++;
            }
        }
        unset($_evt);
        $this->data['event_summary']        = $eventSummary;
        $this->data['pkEventMapLocations']  = $pkEventMapLocs;
        $this->data['pkEventMapNoLocCount'] = $pkMapNoLocCount;

        $pkRosterCacheKey = Ork3::$Lib->ghettocache->key(['ParkId' => $pid]);
        $parkPlayers = Ork3::$Lib->ghettocache->get(__CLASS__ . '.park_players', $pkRosterCacheKey, 1200);
        if ($parkPlayers === false) {
            $parkPlayers = [];
            // last_signin = player's MOST RECENT sign-in anywhere — drives the year bucket so
            // home-park members who've drifted to other parks aren't shown as lost.
            // signin_count = overall 6-month count (matches the bucket's "anywhere" semantic).
            // last_signin_at_park drives the la JOIN for last class and the "here X" annotation
            // shown on the card to identify members who've drifted away.
            // LEFT JOIN sub (was INNER JOIN) so home-park members who never signed in are still listed.
            $rosterSql = "
			SELECT
				m.mundane_id,
				m.persona,
				m.has_image,
				m.has_heraldry,
				m.restricted,
				COALESCE(m.given_name, '') AS given_name,
				COALESCE(m.surname, '')    AS surname,
				COALESCE(sub.last_signin, '1970-01-01')         AS last_signin,
				COALESCE(sub.last_signin_at_park, '1970-01-01') AS last_signin_at_park,
				COUNT(DISTINCT a6.date) AS signin_count,
				c.name AS last_class,
				GROUP_CONCAT(DISTINCT o.role ORDER BY o.role SEPARATOR ', ') AS officer_roles
			FROM ork_mundane m
			LEFT JOIN (
				SELECT a.mundane_id,
					MAX(a.date) AS last_signin,
					MAX(CASE WHEN a.park_id = {$pid} THEN a.date END) AS last_signin_at_park
				FROM ork_attendance a
				INNER JOIN ork_mundane mm
					ON mm.mundane_id = a.mundane_id
				   AND mm.park_id = {$pid}
				   AND mm.suspended = 0 AND mm.active = 1
				GROUP BY a.mundane_id
			) sub ON sub.mundane_id = m.mundane_id
			LEFT JOIN ork_attendance a6 ON a6.mundane_id = m.mundane_id
				AND a6.date >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
			LEFT JOIN ork_attendance la ON la.mundane_id = m.mundane_id
				AND la.park_id = {$pid}
				AND la.date    = sub.last_signin_at_park
			LEFT JOIN ork_class c ON la.class_id = c.class_id
			LEFT JOIN ork_officer o ON o.mundane_id = m.mundane_id AND o.park_id = {$pid}
			WHERE m.park_id = {$pid}
			  AND m.suspended = 0
			  AND m.active = 1
			GROUP BY m.mundane_id
			ORDER BY m.persona";
            $DB->Clear();
            $rosterResult = $DB->DataSet($rosterSql);
            if ($rosterResult && $rosterResult->Size() > 0) {
                // do-while is buggy without a guard — YapoMysql::DataSet doesn't
                // auto-advance the cursor, so the first iteration sees an
                // unloaded row (all fields null). Skip it the same way the
                // event loop above does, otherwise a phantom mundane_id=0
                // "No Recorded Sign-ins" entry shows up at the bottom of every
                // park's roster.
                do {
                    $mid = (int)$rosterResult->mundane_id;
                    if ($mid <= 0) {
                        continue;
                    }
                    $mn = ((int)$rosterResult->restricted === 0) ? trim($rosterResult->given_name . ' ' . $rosterResult->surname) : '';
                    $parkPlayers[] = [
                        'MundaneId'        => $mid,
                        'Persona'          => $rosterResult->persona,
                        'MundaneName'      => $mn,
                        'HasImage'         => (int)$rosterResult->has_image > 0,
                        'HasHeraldry'      => (int)$rosterResult->has_heraldry > 0,
                        'SigninCount'      => (int)$rosterResult->signin_count,
                        'LastSignin'       => $rosterResult->last_signin,
                        'LastSigninAtPark' => $rosterResult->last_signin_at_park,
                        'LastClass'        => $rosterResult->last_class,
                        'OfficerRoles'     => $rosterResult->officer_roles,
                    ];
                } while ($rosterResult->Next());
            }
            Ork3::$Lib->ghettocache->cache(__CLASS__ . '.park_players', $pkRosterCacheKey, $parkPlayers);
        }
        $this->data['park_players'] = $parkPlayers;

        // Monthly average: average of distinct players per month over past year
        $monthlyAvgSql = "
			SELECT AVG(monthly_unique) AS avg_per_month FROM (
				SELECT COUNT(DISTINCT a.mundane_id) AS monthly_unique
				FROM ork_attendance a
				WHERE a.park_id = {$pid}
				  AND a.date > DATE_SUB(CURDATE(), INTERVAL 1 YEAR)
				  AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_month
			) sub";
        $DB->Clear();
        $maResult = $DB->DataSet($monthlyAvgSql);
        $this->data['MonthlyAvg'] = 0;
        if ($maResult && $maResult->Next()) {
            $_avg = (float)$maResult->avg_per_month;
            if ($_avg > 0) {
                $this->data['MonthlyAvg'] = round($_avg, 1);
            }
        }

        // Weekly average: same formula as Top Parks report — deduplicated player-weeks / week_count.
        // Uses the same 6-month window and >= boundary so the number matches the ranking report.
        $wkStart = date('Y-m-d', strtotime('-6 month'));
        $wkEnd   = date('Y-m-d');
        $wkCount = max(1, (int)ceil((strtotime($wkEnd) - strtotime($wkStart)) / (7 * 86400)));
        $escapedWkStart = mysql_real_escape_string($wkStart);
        $escapedWkEnd   = mysql_real_escape_string($wkEnd);
        $weeklyAvgSql = "
			SELECT COUNT(*) AS player_weeks FROM (
				SELECT a.mundane_id
				FROM ork_attendance a
				WHERE a.park_id = {$pid}
				  AND a.date >= '{$escapedWkStart}'
				  AND a.date <= '{$escapedWkEnd}'
				  AND a.mundane_id > 0
				GROUP BY a.date_year, a.date_week3, a.mundane_id
			) sub";
        $DB->Clear();
        $waResult = $DB->DataSet($weeklyAvgSql);
        $this->data['WeeklyAvg'] = 0;
        if ($waResult && $waResult->Next()) {
            $_wk = (int)$waResult->player_weeks;
            if ($_wk > 0) {
                $this->data['WeeklyAvg'] = round($_wk / $wkCount, 2);
            }
        }

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $this->data['IsLoggedIn']    = $uid > 0;
        $this->data['CurrentUserId'] = $uid;
        $this->data['IsOwnPark']     = $uid > 0 && (int)($this->session->park_id ?? 0) === (int)$park_id;
        $this->data['CanManagePark'] = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_EDIT);
        $this->data['CanAdminPark']  = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);
        // Park admins can merge two players who both belong to THIS park.
        // Cross-park or cross-kingdom merges still need higher rights and are
        // performed from the kingdom profile. The server-side MergePlayer
        // enforces this scope; the flag is just for showing the UI button.
        $this->data['CanMergePlayers'] = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);

        $knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
        $recsPublic = isset($knConfigs['AwardRecsPublic'])
            ? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
            : true;
        $this->data['AwardRecsPublic'] = $recsPublic;

        $this->data['AwardRecommendations'] = [];
        $canManagePark = $this->data['CanManagePark'] ?? false;
        if ($recsPublic || $canManagePark) {
            $this->data['ShowRecsTab'] = true;
            $recs = $this->Reports->recommended_awards(['KingdomId' => 0, 'ParkId' => $park_id, 'PlayerId' => 0, 'RequestedBy' => $uid]);
            $this->data['AwardRecommendations'] = is_array($recs) ? $recs : [];
        } elseif ($uid > 0) {
            $recs = $this->Reports->recommended_awards(['KingdomId' => 0, 'ParkId' => $park_id, 'PlayerId' => 0, 'RequestedBy' => $uid]);
            $allRecs = is_array($recs) ? $recs : [];
            $myRecs = array_values(array_filter($allRecs, function ($r) use ($uid) {
                return (int)$r['RecommendedById'] === $uid;
            }));
            $this->data['AwardRecommendations'] = $myRecs;
            $this->data['ShowRecsTab'] = !empty($myRecs);
        } else {
            $this->data['ShowRecsTab'] = false;
        }

        // "My Circles" filter: the viewer's peerage voting circle, as a set of award_ids.
        // Empty for non-peers (the button is then not rendered).
        $this->data['ViewerCircleAwardIds'] = $uid > 0 ? Ork3::$Lib->player->GetCircleAwardIds($uid) : array();
        $this->data['ViewerHasCircle']      = !empty($this->data['ViewerCircleAwardIds']);

        $this->data['PronounList']          = $this->Pronoun->fetch_pronoun_list();
        $this->data['PronounOptionsCreate'] = $this->Pronoun->fetch_pronoun_option_list(null);
    }
}
