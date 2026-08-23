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
        if ($_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_PARK, (int)$id, AUTH_EDIT)) {
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
        $_park_officers = $this->Park->get_officers($park_id, $this->session->token);
        $this->data[ 'park_officers' ] = array('Officers' => is_array($_park_officers) ? $_park_officers : array());
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

        // Link-preview card (text-only; image policy pending): park name plus
        // where it is — the question anyone tapping a shared park link has.
        $_ogPi = $this->data['park_info']['ParkInfo'];
        $_ogLoc = trim(implode(', ', array_filter(array(
            (string)($_ogPi['City'] ?? ''),
            (string)($_ogPi['Province'] ?? ''),
        ))));
        $og = array(
            'title'       => (string)($this->session->park_name ?: 'Amtgard Park'),
            'url'         => UIR . 'Park/profile/' . (int)$park_id,
            'description' => 'Amtgard park' . ($_ogLoc !== '' ? ' in ' . $_ogLoc : '')
                . ($this->session->kingdom_name ? ' — ' . $this->session->kingdom_name : '') . '.',
        );
        // Park heraldry over the site logo when it exists (Ken's call).
        if (!empty($_ogPi['HasHeraldry']) && !empty($this->data['park_info']['Heraldry']['Url'])) {
            $og['image'] = (string)$this->data['park_info']['Heraldry']['Url'];
            $og['image:width'] = '';
            $og['image:height'] = '';
        }
        $this->data['og'] = $og;

        // schema.org Event markup for the park's next concrete park days —
        // Google's guidelines want individual occurrences, not "every
        // Saturday", so each recurrence rule contributes its next two dates.
        // This is the "larp near me" surface: a weekly free park day is the
        // most recruit-friendly event Amtgard runs.
        $_pdLabels = array(
            'park-day'         => 'Amtgard Park Day',
            'fighter-practice' => 'Amtgard Fighter Practice',
            'arts-day'         => 'Amtgard Arts & Sciences Day',
            'other'            => 'Amtgard Gathering',
        );
        $_pdEvents = array();
        foreach (($this->data['park_days']['ParkDays'] ?? array()) as $_pd) {
            if (!empty($_pd['Online'])) {
                continue; // in-person occurrences only, matching the weather page
            }
            // Alternate-location park days carry their own address; default to
            // the park's.
            $_pdAlt = !empty($_pd['AlternateLocation']) && trim((string)($_pd['Address'] ?? '')) !== '';
            foreach (ork_parkday_next_occurrences($_pd) as $_pdDate) {
                $_pdTime = trim((string)($_pd['Time'] ?? ''));
                $_pdTimed = ($_pdTime !== '' && $_pdTime !== '00:00:00');
                $_pdLd = ork_event_jsonld(array(
                    'name'        => trim(($this->session->park_name ?: 'Amtgard') . ' ' . ($_pdLabels[$_pd['Purpose'] ?? ''] ?? 'Amtgard Park Day')),
                    'start'       => $_pdDate . ($_pdTimed ? ' ' . $_pdTime : ''),
                    'all_day'     => !$_pdTimed,
                    'description' => (string)($_pd['Description'] ?? ''),
                    'image'       => (string)($og['image'] ?? ''),
                    'venue'       => (string)($this->session->park_name ?: ''),
                    'street'      => $_pdAlt ? (string)$_pd['Address'] : (string)($_ogPi['Address'] ?? ''),
                    'city'        => $_pdAlt ? (string)($_pd['City'] ?? '') : (string)($_ogPi['City'] ?? ''),
                    'province'    => $_pdAlt ? (string)($_pd['Province'] ?? '') : (string)($_ogPi['Province'] ?? ''),
                    'postal'      => $_pdAlt ? (string)($_pd['PostalCode'] ?? '') : (string)($_ogPi['PostalCode'] ?? ''),
                    'organizer'   => (string)($this->session->kingdom_name ?: ''),
                    'organizer_url' => valid_id($this->session->kingdom_id) ? UIR . 'Kingdom/profile/' . (int)$this->session->kingdom_id : '',
                    'url'         => $og['url'],
                ));
                if ($_pdLd !== array()) {
                    $_pdEvents[] = $_pdLd;
                }
                if (count($_pdEvents) >= 6) {
                    break 2;
                }
            }
        }
        if ($_pdEvents !== array()) {
            $this->data['jsonld'] = $_pdEvents;
        }

        $this->load_model('Weather');
        $this->data['park_weather']     = $this->Weather->for_park($park_id);
        $_park_officers = $this->Park->get_officers($park_id, $this->session->token);
        $this->data['park_officers']    = array('Officers' => is_array($_park_officers) ? $_park_officers : array());
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

        $pid = (int)$park_id;
        $pk_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $pk_isAdmin = ($pk_uid > 0) ? $this->Authorization->has_authority($pk_uid, AUTH_ADMIN, 0, AUTH_CREATE) : false;

        $this->load_model('ParkProfile');
        $eventBundle = $this->ParkProfile->profile_event_bundle($pid, (int)$this->session->kingdom_id, $pk_uid, $pk_isAdmin);
        $this->data['event_summary']        = $eventBundle['event_summary'];
        $this->data['pkEventMapLocations']  = $eventBundle['pkEventMapLocations'];
        $this->data['pkEventMapNoLocCount'] = $eventBundle['pkEventMapNoLocCount'];
        $this->data['park_players']         = $this->ParkProfile->players_roster($pid);

        $averages = $this->ParkProfile->attendance_averages($pid);
        $this->data['MonthlyAvg'] = $averages['MonthlyAvg'];
        $this->data['WeeklyAvg']  = $averages['WeeklyAvg'];

        $uid = $pk_uid;
        $this->data['IsLoggedIn']    = $uid > 0;
        $this->data['CurrentUserId'] = $uid;
        $this->data['IsOwnPark']     = $uid > 0 && (int)($this->session->park_id ?? 0) === (int)$park_id;
        $this->data['CanManagePark'] = $uid > 0
            && $this->Authorization->has_authority($uid, AUTH_PARK, (int)$park_id, AUTH_EDIT);
        $this->data['CanAdminPark']  = $uid > 0
            && $this->Authorization->has_authority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);
        // Park admins can merge two players who both belong to THIS park.
        // Cross-park or cross-kingdom merges still need higher rights and are
        // performed from the kingdom profile. The server-side MergePlayer
        // enforces this scope; the flag is just for showing the UI button.
        $this->data['CanMergePlayers'] = $uid > 0
            && $this->Authorization->has_authority($uid, AUTH_PARK, (int)$park_id, AUTH_CREATE);

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
        $this->load_model('Player');
        $this->data['ViewerCircleAwardIds'] = $uid > 0 ? $this->Player->get_circle_award_ids($uid) : array();
        $this->data['ViewerHasCircle']      = !empty($this->data['ViewerCircleAwardIds']);

        $this->data['PronounList']          = $this->Pronoun->fetch_pronoun_list();
        $this->data['PronounOptionsCreate'] = $this->Pronoun->fetch_pronoun_option_list(null);
    }
}
