<?php

class Controller_Kingdom extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $id = preg_replace('/[^0-9]/', '', $id);

        if ($id != $this->session->kingdom_id) {
            unset($this->session->kingdom_id);
            unset($this->session->kingdom_name);
            unset($this->session->park_name);
            unset($this->session->park_id);
        }

        $this->data['kingdom_id'] = $id;
        $this->session->kingdom_id = $id;

        if (!isset($this->session->kingdom_name)) {
            $this->session->kingdom_name = $this->Kingdom->get_kingdom_name($id);
        }
        $this->data['kingdom_name'] = $this->session->kingdom_name;
        $this->data[ 'page_title' ] = $this->session->kingdom_name;

        unset($this->session->park_id);
        unset($this->session->park_name);
        $_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if ($_uid > 0 && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_KINGDOM, (int)$id, AUTH_EDIT)) {
            $this->data['menu']['admin'] = array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
            $this->data['menulist']['admin'] = array(
                    array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
                );
        }
        $this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
        unset($this->data['menu']['park']);
    }

    public function index($kingdom_id = null)
    {
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $this->load_model('Reports');
        $this->data['park_summary'] = $this->Kingdom->get_park_summary($kingdom_id);
        $this->data['principalities'] = $this->Kingdom->get_principalities($kingdom_id);
        $this->data['event_summary'] = $this->Kingdom->get_kingdom_events($kingdom_id);
        $this->data['kingdom_info'] = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
        $this->data['kingdom_officers'] = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
        $this->data['IsPrinz'] = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];
        // [TOURNAMENTS HIDDEN] $this->data['kingdom_tournaments'] = [];
    }

    public function park_monthly_json($kingdom_id = null)
    {
        session_write_close(); // release session lock so navigation is not blocked
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $summary = $this->Report->GetKingdomParkMonthlyAverages(['KingdomId' => $kingdom_id]);
        $result = array();
        foreach ((array)($summary['KingdomParkMonthlySummary'] ?? []) as $park) {
            $result[$park['ParkId']] = $park['MonthlyCount'];
        }
        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }

    public function park_averages_json($kingdom_id = null)
    {
        session_write_close(); // release session lock so navigation is not blocked
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $kid     = (int)$kingdom_id;
        $uid     = (int)($this->session->user_id ?? 0);
        $isAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kid, AUTH_EDIT);
        $this->load_model('KingdomProfile');
        $result = $this->KingdomProfile->extended_park_averages($kid, $isAdmin);
        header('Content-Type: application/json');
        echo json_encode($result);
        exit();
    }

    public function events_more($kingdom_id = null)
    {
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $window = isset($_GET['window']) ? (int)$_GET['window'] : 1;
        $this->load_model('KingdomProfile');
        $payload = $this->KingdomProfile->paginated_events((int)$kingdom_id, $window);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }

    // Lazy-loaded body of the kingdom profile Recommendations tab. Called by JS
    // the first time the tab is activated; returns raw HTML for the tab's inner
    // container (NOT a full page). Auth+visibility rules mirror profile().
    public function recommendations_panel($kingdom_id = null)
    {
        session_write_close();
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', (string)$kingdom_id);
        if ($kingdom_id <= 0) {
            http_response_code(400);
            exit;
        }
        $this->load_model('Reports');

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $isOrkAdmin = $uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
        $canManageKingdom = $isOrkAdmin
            || ($uid > 0 && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE));

        $knConfigs  = Common::get_configs($kingdom_id, CFG_KINGDOM);
        $recsPublic = isset($knConfigs['AwardRecsPublic'])
            ? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
            : true;

        $AwardRecommendations = [];
        if ($recsPublic || $canManageKingdom) {
            $recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0, 'RequestedBy' => $uid]);
            $AwardRecommendations = is_array($recs) ? $recs : [];
        } elseif ($uid > 0) {
            $recs = $this->Reports->recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0, 'RequestedBy' => $uid]);
            $allRecs = is_array($recs) ? $recs : [];
            $AwardRecommendations = array_values(array_filter($allRecs, function ($r) use ($uid) {
                return (int)$r['RecommendedById'] === $uid;
            }));
        } else {
            http_response_code(403);
            exit;
        }

        // Variables the partial template expects in scope:
        $IsLoggedIn       = $uid > 0;
        $CanManageKingdom = $canManageKingdom;
        $kingdom_name     = $this->Kingdom->get_kingdom_name($kingdom_id);

        // "My Circles" filter: the viewer's peerage voting circle, as a set of award_ids.
        // Empty for non-peers (the button is then not rendered).
        $ViewerCircleAwardIds = $uid > 0 ? Ork3::$Lib->player->GetCircleAwardIds($uid) : array();
        $ViewerHasCircle      = !empty($ViewerCircleAwardIds);

        header('Content-Type: text/html; charset=utf-8');
        header('X-Recs-Count: ' . count($AwardRecommendations)); // JS uses this for the tab badge
        include DIR_TEMPLATE . 'revised-frontend/Kingdomnew_recommendations_panel.tpl';
        exit();
    }

    public function players_json($kingdom_id = null)
    {
        session_write_close(); // release session lock so navigation is not blocked
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $this->load_model('KingdomProfile');
        $payload = $this->KingdomProfile->players_roster((int)$kingdom_id);
        header('Content-Type: application/json');
        echo json_encode($payload);
        exit();
    }

    public function map($kingdom_id = null)
    {
        if (valid_id($kingdom_id)) {
            $kingdom_details = $this->Kingdom->GetKingdomDetails(array('KingdomId' => $kingdom_id));
            $this->data[ 'page_title' ] = $kingdom_details['KingdomInfo']['KingdomName'] . " Map";

            $all_parks = $this->Kingdom->GetParks(array('KingdomId' => $kingdom_id));
            $all_parks['Parks'] = array_filter(
                $all_parks['Parks'],
                function ($park) {
                    return $park['Active'] == 'Active';
                }
            );
            $this->data['Parks'] = $all_parks;
        }
    }

    public function profile($kingdom_id = null)
    {
        $this->template = '../revised-frontend/Kingdomnew_index.tpl';
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        if (!valid_id($kingdom_id)) {
            header('Location: ' . UIR);
            exit;
        }
        $this->load_model('Award');
        $this->load_model('Reports');
        $this->load_model('Pronoun');
        $this->load_model('Recap');
        $this->data['week_recap'] = $this->Recap->get();

        $this->data['menu']['kingdom'] = [
            'url'     => UIR . 'Kingdom/profile/' . $kingdom_id,
            'display' => $this->session->kingdom_name,
        ];

        $this->data['park_summary']        = $this->Kingdom->get_park_summary($kingdom_id);
        $this->data['principalities']      = $this->Kingdom->get_principalities($kingdom_id);
        $this->data['kingdom_info']        = $this->Kingdom->get_kingdom_shortinfo($kingdom_id);
        if (empty($this->data['kingdom_info']['Info']['KingdomInfo']['KingdomId'])) {
            header('Location: ' . UIR);
            exit;
        }
        $this->data['kingdom_officers']    = $this->Kingdom->GetOfficers(['KingdomId' => $kingdom_id, 'Token' => $this->session->token]);
        $this->data['IsPrinz']             = $this->data['kingdom_info']['Info']['KingdomInfo']['IsPrincipality'];

        $parentKingdomId = (int)($this->data['kingdom_info']['Info']['KingdomInfo']['ParentKingdomId'] ?? 0);
        $this->data['ParentKingdomId']   = $parentKingdomId;
        $this->data['ParentKingdomName'] = $parentKingdomId > 0 ? $this->Kingdom->get_kingdom_name($parentKingdomId) : '';

        $this->data['AwardOptions']        = $this->Award->fetch_award_option_list($kingdom_id, 'Awards');
        $this->data['OfficerOptions']      = $this->Award->fetch_award_option_list($kingdom_id, 'Officers');
        $this->data['CustomTitleAliasOptions'] = $this->Award->fetch_custom_title_alias_options();
        $preloadOfficers = [];
        foreach ($this->data['kingdom_officers']['Officers'] ?? [] as $o) {
            if (in_array($o['OfficerRole'], ['Monarch', 'Regent']) && (int)$o['MundaneId'] > 0) {
                $preloadOfficers[] = ['MundaneId' => $o['MundaneId'], 'Persona' => $o['Persona'], 'Role' => $o['OfficerRole']];
            }
        }
        $this->data['PreloadOfficers']     = $preloadOfficers;
        // [TOURNAMENTS HIDDEN] $this->data['kingdom_tournaments'] = [];

        $rawParks = $this->Kingdom->GetParks(['KingdomId' => $kingdom_id]);
        $this->data['map_parks'] = is_array($rawParks['Parks'])
            ? array_values(array_filter($rawParks['Parks'], function ($p) {
                return $p['Active'] == 'Active';
            }))
            : [];

        // Whether this kingdom has active child principalities (and is not itself one).
        // Drives the admin 'Include Principality in Statistics' toggle visibility.
        $this->data['HasChildPrincipalities'] = (empty($this->data['IsPrinz'])
            && is_array($this->data['principalities']['Principalities'] ?? null)
            && count($this->data['principalities']['Principalities']) > 0);

        // Child-principality parks for the Parks tab (tile/list) and the kingdom map.
        // Only when this kingdom is NOT itself a principality; both keys always set.
        $this->data['principality_parks'] = [];
        $this->data['prinz_map_parks']    = [];
        if (empty($this->data['IsPrinz']) && is_array($this->data['principalities']['Principalities'] ?? null)) {
            foreach ($this->data['principalities']['Principalities'] as $pr) {
                $prId = (int)($pr['KingdomId'] ?? 0);
                if ($prId <= 0) {
                    continue;
                }
                $prName = $pr['Name'] ?? '';

                $prSummary = $this->Kingdom->get_park_summary($prId);
                $prParks   = is_array($prSummary['KingdomParkAveragesSummary'] ?? null)
                    ? $prSummary['KingdomParkAveragesSummary']
                    : [];
                if (!empty($prParks)) {
                    $this->data['principality_parks'][] = [
                        'KingdomId' => $prId,
                        'Name'      => $prName,
                        'parks'     => $prParks,
                    ];
                }

                $prRawParks = $this->Kingdom->GetParks(['KingdomId' => $prId]);
                $prMapParks = is_array($prRawParks['Parks'] ?? null)
                    ? array_values(array_filter($prRawParks['Parks'], function ($p) {
                        return $p['Active'] == 'Active';
                    }))
                    : [];
                if (!empty($prMapParks)) {
                    $this->data['prinz_map_parks'][] = [
                        'KingdomId' => $prId,
                        'Name'      => $prName,
                        'parks'     => $prMapParks,
                    ];
                }
            }
        }

        // Hero/tab 'Parks (N)' count: roll up family park count when the stats flag is on
        // (main parks + principality parks already gathered above). Falls back to the
        // kingdom's own park count otherwise. Does NOT merge principality parks into the tiles.
        $ownParkCount = is_array($this->data['park_summary']['KingdomParkAveragesSummary'] ?? null)
            ? count($this->data['park_summary']['KingdomParkAveragesSummary'])
            : 0;
        if (!empty($this->data['HasChildPrincipalities']) && $this->Kingdom->StatsIncludesPrincipalities($kingdom_id)) {
            $prinzParkCount = 0;
            foreach ($this->data['principality_parks'] as $prGroup) {
                $prinzParkCount += is_array($prGroup['parks'] ?? null) ? count($prGroup['parks']) : 0;
            }
            $this->data['StatsParkCount'] = $ownParkCount + $prinzParkCount;
        } else {
            $this->data['StatsParkCount'] = $ownParkCount;
        }

        $this->data['park_edit_lookup'] = [];
        if (is_array($rawParks['Parks'])) {
            foreach ($rawParks['Parks'] as $p) {
                $this->data['park_edit_lookup'][(int)$p['ParkId']] = [
                    'ParkId'       => (int)$p['ParkId'],
                    'Name'         => $p['Name'],
                    'Abbreviation' => $p['Abbreviation'] ?? '',
                    'ParkTitleId'  => (int)($p['ParkTitleId'] ?? 0),
                    'Active'       => $p['Active'],
                ];
            }
        }

        $this->load_model('KingdomProfile');
        $kid = (int)$kingdom_id;
        $kn_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $kn_isAdmin = ($kn_uid > 0) ? Ork3::$Lib->authorization->HasAuthority($kn_uid, AUTH_ADMIN, 0, AUTH_CREATE) : false;
        $eventBundle = $this->KingdomProfile->profile_event_bundle($kid, $kn_uid, $kn_isAdmin);
        $this->data['event_summary']        = $eventBundle['event_summary'];
        $this->data['knEventMapLocations']  = $eventBundle['knEventMapLocations'];
        $this->data['knEventMapNoLocCount'] = $eventBundle['knEventMapNoLocCount'];
        $this->data['HasMoreEvents']        = $eventBundle['HasMoreEvents'];
        $this->data['kingdom_park_days']    = $this->KingdomProfile->park_days($kid);

        $uid = $kn_uid;
        $this->data['IsLoggedIn']       = $uid > 0;

        // Pin the logged-in user's home park to the first slot in the parks list
        $this->data['UserParkId'] = $uid > 0 ? $this->KingdomProfile->user_home_park_id($uid) : 0;
        $this->data['CanEditKingdom']   = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_EDIT);
        $this->data['CanManageKingdom'] = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
        $this->data['CanAddPark'] = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, (int)$kingdom_id, AUTH_CREATE);
        $this->data['IsOrkAdmin'] = $uid > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);

        // Park-level officers (within this kingdom) need the calendar-item edit
        // modal rendered too so they can edit park-level calendar items via the
        // kingdom calendar view. Without this, clicking Edit closes the view
        // overlay and nothing opens (getElementById('kn-event-modal') is null).
        // Create buttons elsewhere on the page stay gated by CanManageKingdom.
        $this->data['CanManageAnyParkInKingdom'] = false;
        if ($uid > 0 && !$this->data['CanManageKingdom']) {
            $this->data['CanManageAnyParkInKingdom'] = $this->KingdomProfile->has_park_create_auth($uid, (int)$kingdom_id);
        }

        // Qualification Tests module: gate the Tests management UI.
        $this->data['CanManageTests'] = $uid > 0 && Ork3::$Lib->qualtest->canManage($uid, (int)$kingdom_id);

        // Kingdom-level configs are read in two places below (QualTest toggles
        // and AwardRecsPublic). Fetch once here — before the qual-tests branch
        // was merged with master this was assigned lower down, which left the
        // QualTest reads reaching for an undefined variable and always
        // resolving to false.
        $knConfigs  = Common::get_configs($kingdom_id, CFG_KINGDOM);

        // Qualification Tests config toggles (per-kingdom enable of reeve/corpora tests).
        $this->data['QualTestReeveEnabled'] = isset($knConfigs['QualTestReeveEnabled'])
            ? (bool)(int)$knConfigs['QualTestReeveEnabled']['Value']
            : false;
        $this->data['QualTestCorporaEnabled'] = isset($knConfigs['QualTestCorporaEnabled'])
            ? (bool)(int)$knConfigs['QualTestCorporaEnabled']['Value']
            : false;

        // Gate the "Voting Eligible" Players-nav link by whether this kingdom has
        // voting rules defined. Single source of truth lives in
        // Model_Reports::supported_voting_kingdom_ids() — don't hardcode the list here.
        $this->data['ShowVotingEligibleLink'] = in_array(
            (int)$kingdom_id,
            $this->Reports->supported_voting_kingdom_ids()
        );

        $recsPublic = isset($knConfigs['AwardRecsPublic'])
            ? (bool)(int)$knConfigs['AwardRecsPublic']['Value']
            : true;
        $this->data['AwardRecsPublic'] = $recsPublic;

        // Recommendations tab visibility is a permissions decision; the rows themselves
        // are lazy-loaded by JS calling Controller_Kingdom::recommendations_panel().
        // Inlining the rows here was rendering thousands of <tr>s and stalling the
        // browser's DOMContentLoaded for 1+ second on busy kingdoms.
        $this->data['AwardRecommendations'] = [];
        $this->data['AwardRecommendationsCount'] = 0;
        $canManageKingdom = $this->data['CanManageKingdom'] ?? false;
        if ($recsPublic || $canManageKingdom) {
            $this->data['ShowRecsTab'] = true;
            $this->data['AwardRecommendationsCount'] = $this->Reports->recommended_awards_count(['KingdomId' => $kingdom_id]);
        } elseif ($uid > 0) {
            // Logged-in non-admin on a private-recs kingdom — tab is shown only if
            // the user has their own recs. Cheap COUNT query, no row hydration.
            $n = $this->Reports->recommended_awards_count(['KingdomId' => $kingdom_id, 'RecommendedBy' => $uid]);
            $this->data['AwardRecommendationsCount'] = $n;
            $this->data['ShowRecsTab'] = $n > 0;
        } else {
            $this->data['ShowRecsTab'] = false;
        }

        $this->data['PlayerCount'] = $this->KingdomProfile->player_count((int)$kingdom_id);

        $this->data['ParkTitleId_options'] = [];
        $this->data['AdminInfo']           = [];
        $this->data['AdminConfig']         = [];
        $this->data['AdminParkTitles']     = [];
        $this->data['AdminAwards']         = [];
        if ($this->data['CanManageKingdom']) {
            $kd = $this->Kingdom->get_kingdom_details($kingdom_id);
            foreach ($kd['ParkTitles'] ?? [] as $pt) {
                $this->data['ParkTitleId_options'][$pt['ParkTitleId']] = $pt['Title'];
            }

            $parentKingdomId   = (int)($kd['KingdomInfo']['ParentKingdomId'] ?? 0);
            $parentKingdomName = '';
            if ($parentKingdomId > 0) {
                $parentKingdomName = $this->Kingdom->get_kingdom_name($parentKingdomId);
            }
            $this->data['AdminInfo'] = [
                'Name'             => $kd['KingdomInfo']['KingdomName']  ?? '',
                'Abbreviation'     => $kd['KingdomInfo']['Abbreviation'] ?? '',
                'Description'      => $kd['KingdomInfo']['Description']  ?? '',
                'Url'              => $kd['KingdomInfo']['Url']          ?? '',
                'IsPrincipality'   => !empty($kd['KingdomInfo']['IsPrincipality']),
                'ParentKingdomId'  => $parentKingdomId,
                'ParentKingdomName' => $parentKingdomName,
                'Active'           => $kd['KingdomInfo']['Active'] ?? 'Active',
            ];

            $adminConfig = [];
            $hasChildPrinz = !empty($this->data['HasChildPrincipalities']);
            foreach ($kd['KingdomConfiguration'] ?? [] as $cfg) {
                if (empty($cfg['UserSetting'])) {
                    continue;
                }
                // Only surface the principality-stats toggle for kingdoms that have principalities.
                if (($cfg['Key'] ?? '') === 'IncludePrincipalityInStatistics' && !$hasChildPrinz) {
                    continue;
                }
                $adminConfig[] = $cfg;
            }
            $this->data['AdminConfig']     = $adminConfig;
            $this->data['AdminParkTitles'] = array_values($kd['ParkTitles'] ?? []);

            $rawAwards   = $kd['Awards']['Awards'] ?? [];
            $adminAwards = [];
            foreach ($rawAwards as $kawId => $aw) {
                $adminAwards[] = [
                    'KingdomAwardId'   => (int)$kawId,
                    'KingdomAwardName' => $aw['KingdomAwardName']  ?? '',
                    'AwardId'          => (int)($aw['AwardId']     ?? 0),
                    'AwardName'        => $aw['AwardName']         ?? '',
                    'IsLadder'         => (int)($aw['IsLadder']    ?? 0),
                    'ReignLimit'       => (int)($aw['ReignLimit']  ?? 0),
                    'MonthLimit'       => (int)($aw['MonthLimit']  ?? 0),
                    'IsTitle'          => (int)($aw['IsTitle']     ?? 0),
                    'TitleClass'       => (int)($aw['TitleClass']  ?? 0),
                ];
            }
            $this->data['AdminAwards'] = $adminAwards;

            // System awards list for Add Award Alias dropdown
            $sysAwardResult = $this->Award->GetAwardList(['IsLadder' => null, 'IsTitle' => null, 'OfficerRole' => 'Awards']);
            $sysAwards = [];
            if (($sysAwardResult['Status']['Status'] ?? 1) == 0) {
                foreach ($sysAwardResult['Awards'] as $sa) {
                    $sysAwards[] = ['AwardId' => (int)$sa['AwardId'], 'Name' => $sa['AwardName'] ?? $sa['KingdomAwardName']];
                }
                usort($sysAwards, function ($a, $b) {
                    return strcasecmp($a['Name'], $b['Name']);
                });
            }
            $this->data['SystemAwards'] = $sysAwards;
        }

        $this->data['PronounList']          = $this->Pronoun->fetch_pronoun_list();
        $this->data['PronounOptionsCreate'] = $this->Pronoun->fetch_pronoun_option_list(null);
        $this->data['IcsUrl'] = UIR . 'Kingdom/ics/' . $kingdom_id;
    }

    // ------------------------------------------------------------------ ICS Feed
    public function ics($kingdom_id = null)
    {
        $kingdom_id = preg_replace('/[^0-9]/', '', $kingdom_id);
        $kid = (int)$kingdom_id;
        $knName = $this->Kingdom->get_kingdom_name($kid);
        if (empty($knName)) {
            $knName = 'Kingdom';
        }
        $this->load_model('KingdomProfile');
        $icsBody = $this->KingdomProfile->export_ics($kid, $knName);
        $safeName = preg_replace('/[^a-z0-9]/i', '-', $knName);
        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '-events.ics"');
        header('Cache-Control: no-cache, must-revalidate');
        echo $icsBody;
        exit();
    }

}
