<?php

class Controller_Player extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);

        $this->load_model('Park');
        $this->load_model('Pronoun');
        $this->load_model('Award');
        $this->load_model('Reports');
        $params = explode('/', $id);
        $id = (int) $params[0];

        $this->data['Player'] = $this->Player->fetch_player($id);

        $park_info = $this->Park->get_park_info($this->data['Player']['ParkId']);
        if (!empty($park_info['ParkInfo']['ParkId'])) {
            $this->session->park_name = $park_info['ParkInfo']['ParkName'];
            $this->session->park_id = $park_info['ParkInfo']['ParkId'];
            $this->session->kingdom_id = $park_info['KingdomInfo']['KingdomId'];
            $this->session->kingdom_name = $park_info['KingdomInfo']['KingdomName'];
        }
        $_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if ($_uid > 0 && $this->Authorization->has_authority($_uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
            $this->data['menu']['admin'] = array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
        }
        $this->data['menulist']['admin'] = array(
                array( 'url' => UIR.'Admin/player/'.$id, 'display' => 'Player' ),
                array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Park' ),
                array( 'url' => UIR.'Admin/kingdom/'.$this->session->kingdom_id, 'display' => 'Kingdom' )
            );
        if (valid_id($this->session->kingdom_id)) {
            $this->data['menu']['kingdom'] = array( 'url' => UIR.'Kingdom/profile/'.$this->session->kingdom_id, 'display' => $this->session->kingdom_name );
            $this->data['menu']['park'] = array( 'url' => UIR.'Park/profile/'.$this->session->park_id, 'display' => $this->session->park_name );
        } else {
            unset($this->data['menu']['kingdom']);
            unset($this->data['menu']['park']);
        }
        $this->data['menu']['player'] = array( 'url' => UIR."Player/$call/$id", 'display' => $this->data['Player']['Persona'] );
        $this->data['page_title'] = $this->data['Player']['Persona'];

    }

    public function index($id = null)
    {
        if (!valid_id($id)) {
            header('Location: ' . UIR);
            exit;
        }

        $this->load_model('Unit');
        $this->load_model('Event');

        $params = explode('/', $id);
        $id = $params[0];
        $action = '';
        $roastbeef = '';
        if (count($params) > 1) {
            $action = $params[1];
        }
        if (count($params) > 2) {
            $roastbeef = $params[2];
        }

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if ($uid > 0 && $uid === (int)$id && isset($this->request->cancel_rsvp_detail_id)) {
            $this->Event->toggle_rsvp((int)$this->request->cancel_rsvp_detail_id, $uid);
            header('Location: ' . UIR . 'Player/profile/' . $id);
            return;
        }

        if (strlen($action) > 0) {
            $this->request->save('Player_index', true);
            $r = array('Status' => 0);
            if (!isset($this->session->user_id)) {
                header('Location: '.UIR."Login/login/Player/profile/$id");
            } else {
                switch ($action) {
                    case 'updateclasses':
                        $class_update = array();
                        if (is_array($this->request->Reconciled)) {
                            foreach ($this->request->Reconciled as $class_id => $qty) {
                                $class_update[] = array( 'ClassId' => $class_id, 'Quantity' => $qty );
                            }
                            $this->Player->update_class_reconciliation(array( 'Token' => $this->session->token, 'MundaneId' => $id, 'Reconcile' => $class_update ));
                        }
                        break;
                    case 'update':
                        $pi_imdata = '';
                        if ($_FILES['Heraldry']['size'] > 0 && Common::supported_mime_types($_FILES['Heraldry']['type'])) {
                            if (move_uploaded_file($_FILES['Heraldry']['tmp_name'], DIR_TMP . sprintf("h_%06d", $id))) {
                                $h_im = file_get_contents(DIR_TMP . sprintf("h_%06d", $id));
                                $h_imdata = base64_encode($h_im);
                            }
                        }
                        if ($_FILES['Waiver']['size'] > 0 && Common::supported_mime_types($_FILES['Waiver']['type'])) {
                            if (move_uploaded_file($_FILES['Waiver']['tmp_name'], DIR_TMP . sprintf("w_%06d", $id))) {
                                $w_im = file_get_contents(DIR_TMP . sprintf("w_%06d", $id));
                                $w_imdata = base64_encode($w_im);
                            }
                        }
                        $r = $this->Player->update_player(array(
                                'MundaneId' => $id,
                                'GiveName' =>  $this->request->Player_index->GivenName,
                                'Surname' =>  $this->request->Player_index->Surname,
                                'Persona' =>  $this->request->Player_index->Persona,
                                'PronounId' =>  $this->request->Player_index->PronounId,
                                'UserName' =>  $this->request->Player_index->UserName,
                                'Password' =>  $this->request->Player_index->Password == $this->request->Player_index->PasswordAgain ? $this->request->Player_index->Password : null,
                                'Email' =>  $this->request->Player_index->Email,
                                'Restricted' =>  $this->request->Player_index->Restricted == 'Restricted' ? 1 : 0,
                                'Active' =>  $this->request->Player_index->Active == 'Active' ? 1 : 0,
                                'HasImage' => strlen($pi_imdata),
                                'Image' => strlen($pi_imdata) > 0 ? $pi_imdata : null,
                                'ImageMimeType' => strlen($pi_imdata) > 0 ? $_FILES['PlayerImage']['type'] : '',
                                'Heraldry' => strlen($h_imdata) > 0 ? $h_imdata : null,
                                'HeraldryMimeType' => strlen($h_imdata) > 0 ? $_FILES['Heraldry']['type'] : '',
                                'Waivered' => strlen($w_imdata),
                                'Waiver' => strlen($w_imdata) > 0 ? $w_imdata : null,
                                'WaiverMimeType' => strlen($w_imdata) > 0 ? $_FILES['Waiver']['type'] : '',
                                'Token' => $this->session->token
                            ));
                        if ($this->request->Player_index->Password != $this->request->Player_index->PasswordAgain) {
                            $this->data['Error'] = 'Passwords do not match.';
                        }
                        break;
                    case 'addaward':
                        $r = $this->Player->add_player_award(array(
                                'Token' => $this->session->token,
                                'RecipientId' => $id,
                                'AwardId' => $this->request->Player_index->AwardId,
                                'CustomName' => $this->request->Player_index->CustomName ?? '',
                                'AliasAwardId' => $this->request->Player_index->AliasAwardId ?? 0,
                                'Rank' => $this->request->Player_index->Rank,
                                'Date' => $this->request->Player_index->Date,
                                'GivenById' => $this->request->Player_index->MundaneId,
                                'Note' => $this->request->Player_index->Note,
                                'ParkId' => valid_id($this->request->Player_index->ParkId) ? $this->request->Player_index->ParkId : 0,
                                'KingdomId' => valid_id($this->request->Player_index->KingdomId) ? $this->request->Player_index->KingdomId : 0,
                                'EventId' => valid_id($this->request->Player_index->EventId) ? $this->request->Player_index->EventId : 0
                            ));
                        break;
                    case 'deleteaward':
                        $r = $this->Player->delete_player_award(array(
                                'Token' => $this->session->token,
                                'AwardsId' => $roastbeef,
                                'RecipientId' => $id
                            ));
                        break;
                    case 'updateaward':
                        $r = $this->Player->update_player_award(array(
                                'Token' => $this->session->token,
                                'AwardsId' => $roastbeef,
                                'RecipientId' => $id,
                                'AwardId' => $this->request->Player_index->AwardId,
                                'CustomName' => $this->request->Player_index->CustomName ?? '',
                                'AliasAwardId' => $this->request->Player_index->AliasAwardId ?? 0,
                                'Rank' => $this->request->Player_index->Rank,
                                'Date' => $this->request->Player_index->Date,
                                'GivenById' => $this->request->Player_index->MundaneId,
                                'Note' => $this->request->Player_index->Note,
                                'ParkId' => valid_id($this->request->Player_index->ParkId) ? $this->request->Player_index->ParkId : 0,
                                'KingdomId' => valid_id($this->request->Player_index->KingdomId) ? $this->request->Player_index->KingdomId : 0,
                                'EventId' => valid_id($this->request->Player_index->EventId) ? $this->request->Player_index->EventId : 0
                            ));
                        break;
                    case 'addrecommendation':
                        $r = $this->Player->add_player_recommendation(array(
                                'Token' => $this->session->token,
                                'MundaneId' => $id,
                                'KingdomAwardId' => $this->request->Player_index->KingdomAwardId,
                                'Rank' => $this->request->Player_index->Rank,
                                'GivenById' => $this->request->Player_index->MundaneId,
                                'Reason' => $this->request->Player_index->Reason
                            ));
                        break;
                    case 'deleterecommendation':
                        $r = $this->Player->delete_player_recommendation(array(
                                'Token' => $this->session->token,
                                'RecommendationsId' => $roastbeef,
                                'RequestedBy' => $this->session->user_id
                            ));
                        break;
                }
                if ($r['Status'] == 0) {
                    if ($r['Detail']) {
                        $this->data['Message'] = $r['Detail'];
                    } else {
                        $this->data['Message'] = 'Player has been updated.';
                    }
                    $this->request->clear('Player_index');
                } elseif ($r['Status'] == 5) {
                    header('Location: '.UIR."Login/login/Player/profile/$id");
                } else {
                    $this->data['Error'] = trim($r['Detail']) === '' ? $r['Error'] : ($r['Error'].':<p>'.$r['Detail']);
                }
            }
        }

        if ($this->request->exists('Player_index')) {
            $this->data['Player_index'] = $this->request->Player_index->Request;
        }
        $this->data['LoggedIn'] = isset($this->session->user_id);
        $this->data['KingdomId'] = $this->session->kingdom_id;
        $this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
        $this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
        $this->data['Player'] = $this->Player->fetch_player($id);
        if (empty($this->data['Player']['MundaneId'])) {
            header('Location: ' . UIR);
            exit;
        }
        $this->data['Player']['LastSignInDate']   = $this->Player->get_latest_attendance_date($id);
        $this->data['Player']['PlayerSinceDate']  = $this->Player->get_earliest_attendance_date($id);
        // Fallback Park Member Since to earliest attendance at the member park
        // when the mundane record has no stored date (legacy imports, older accounts).
        $_pms = $this->data['Player']['ParkMemberSince'] ?? null;
        if (empty($_pms) || $_pms === '0000-00-00') {
            $_memberParkId = (int)($this->data['Player']['ParkId'] ?? 0);
            if ($_memberParkId > 0) {
                $_fallback = $this->Player->get_earliest_park_attendance_date($id, $_memberParkId);
                if (!empty($_fallback)) {
                    $this->data['Player']['ParkMemberSince'] = $_fallback;
                }
            }
        }
        $this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
        $this->data['PronounList']    = $this->Pronoun->fetch_pronoun_list();
        $this->data['Details'] = $this->Player->fetch_player_details($id);
        $this->data['Notes'] = $this->Player->get_notes($id);
        $this->data['Dues'] = $this->Player->get_dues($id, 1, true);
        $this->data['AllDues'] = $this->Player->get_dues($id, 0, false);
        $this->data['Units'] = $this->Unit->get_unit_list(array( 'MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' => 1, 'IncludeEvents' => 1, 'ActiveOnly' => 1, 'Lightweight' => 1 ));
        $this->data['menu']['player'] = array( 'url' => UIR."Player/profile/$id", 'display' => $this->data['Player']['Persona'] );
        $canEdit    = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_PARK, (int)($this->data['Player']['ParkId'] ?? 0), AUTH_EDIT);
        $this->data['canDeleteRecommendation'] = false;
        if ($uid > 0) {
            if (isset($this->session->park_id) && $this->Authorization->has_authority($uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
                $this->data['canDeleteRecommendation'] = true;
            } elseif (isset($this->session->kingdom_id) && $this->Authorization->has_authority($uid, AUTH_KINGDOM, (int)$this->session->kingdom_id, AUTH_EDIT)) {
                $this->data['canDeleteRecommendation'] = true;
            }
        }
        if ($canEdit) {
            $this->data['menu']['admin'] = array( 'url' => UIR."Admin/player/$id", 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
        }
        $knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
        $recsPublic = isset($knConfigs['AwardRecsPublic']) ? (bool)(int)$knConfigs['AwardRecsPublic']['Value'] : true;
        $this->data['ShowRecsTab']          = $recsPublic || $canEdit;
        $this->data['AwardRecommendations'] = [];
        if ($this->data['ShowRecsTab'] || $uid > 0) {
            $recs = $this->Reports->recommended_awards(array('PlayerId' => $id, 'KingdomId' => 0, 'ParkId' => 0, 'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0));
            $this->data['AwardRecommendations'] = is_array($recs) ? $recs : [];
        }

        // Preload Kingdom and Park Monarch/Regent for GivenBy autocomplete
        $this->load_model('Kingdom');
        $preloadOfficers = array();
        $kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
        if (is_array($kingdomOfficers)) {
            foreach ($kingdomOfficers as $officer) {
                if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
                    $preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']);
                }
            }
        }
        $parkId = $this->data['Player']['ParkId'];
        if (valid_id($parkId)) {
            $parkOfficers = $this->Park->get_officers($parkId, $this->session->token);
            if (is_array($parkOfficers)) {
                foreach ($parkOfficers as $officer) {
                    if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
                        $preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']);
                    }
                }
            }
        }
        $this->data['PreloadOfficers'] = $preloadOfficers;

        $this->data['UpcomingRsvps'] = $this->Event->get_upcoming_rsvps((int)$id);
        $this->data['IsOwnProfile'] = $uid === (int)$id;

    }

    public function profile($id = null)
    {
        $this->template = '../revised-frontend/Playernew_index.tpl';

        $params    = explode('/', $id ?? '');
        $id        = (int) $params[0];

        if (!$id) {
            header('Location: ' . UIR);
            exit;
        }

        $this->load_model('Unit');
        $this->load_model('Kingdom');
        $this->load_model('Event');
        $action    = $params[1] ?? '';
        $roastbeef = $params[2] ?? '';

        // Missing row → bail rather than render a mostly-blank profile with
        // sub-fields synthesized from queries against a nonexistent id. Same
        // guard as index() at the top of this file.
        $this->data['Player'] = $this->Player->fetch_player($id);
        if (empty($this->data['Player']['MundaneId'])) {
            header('Location: ' . UIR);
            exit;
        }

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;

        if ($uid > 0 && $uid === (int)$id && isset($this->request->cancel_rsvp_detail_id)) {
            $this->Event->toggle_rsvp((int)$this->request->cancel_rsvp_detail_id, $uid);
            header('Location: ' . UIR . 'Player/profile/' . $id);
            return;
        }

        $this->data['menu']['kingdom'] = ['url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name];
        $this->data['menu']['park']    = ['url' => UIR . 'Park/profile/'    . $this->session->park_id,    'display' => $this->session->park_name];

        if (strlen($action) > 0) {
            $this->request->save('Player_profile', true);
            $r = ['Status' => 0];
            if (!isset($this->session->user_id)) {
                header('Location: ' . UIR . "Login/login/Player/profile/$id");
                exit;
            } else {
                switch ($action) {
                    case 'addrecommendation':
                        $r = $this->Player->add_player_recommendation([
                            'Token'          => $this->session->token,
                            'MundaneId'      => $id,
                            'KingdomAwardId' => $this->request->Player_profile->KingdomAwardId,
                            'Rank'           => $this->request->Player_profile->Rank,
                            'Reason'         => $this->request->Player_profile->Reason,
                        ]);
                        $this->request->clear('Player_profile');
                        if ($r['Status'] == 0) {
                            header('Location: ' . UIR . "Player/profile/{$id}");
                        } elseif ($r['Status'] == 5) {
                            header('Location: ' . UIR . "Login/login/Player/profile/$id");
                        } else {
                            $msg = urlencode(!empty($r['Detail']) ? $r['Detail'] : $r['Error']);
                            header('Location: ' . UIR . "Player/profile/{$id}&rec_error={$msg}");
                        }
                        exit;
                    case 'deleterecommendation':
                        $r = $this->Player->delete_player_recommendation([
                            'Token'             => $this->session->token,
                            'RecommendationsId' => $roastbeef,
                            'RequestedBy'       => $this->session->user_id,
                        ]);
                        $this->request->clear('Player_profile');
                        if ($r['Status'] == 5) {
                            header('Location: ' . UIR . "Login/login/Player/profile/$id");
                        } else {
                            header('Location: ' . UIR . "Player/profile/{$id}");
                        }
                        exit;
                    case 'quitunit':
                        $r = $this->Unit->retire_unit_member([
                            'UnitMundaneId' => $roastbeef,
                            'UnitId'        => 0,
                            'Token'         => $this->session->token,
                        ]);
                        break;
                }
                if ($r['Status'] == 0) {
                    $this->data['Message'] = $r['Detail'] ?: 'Updated successfully.';
                    $this->request->clear('Player_profile');
                } elseif ($r['Status'] == 5) {
                    header('Location: ' . UIR . "Login/login/Player/profile/$id");
                    exit;
                } else {
                    $this->data['Error'] = $r['Error'] . ': ' . $r['Detail'];
                }
            }
        }

        $this->data['LoggedIn']      = isset($this->session->user_id);
        $this->data['KingdomId']     = $this->session->kingdom_id;
        $this->data['AwardOptions']  = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
        $this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
        $this->data['Player']['LastSignInDate']  = $this->Player->get_latest_attendance_date($id);
        $this->data['Player']['PlayerSinceDate'] = $this->Player->get_earliest_attendance_date($id);

        // Custom Title alias dropdown data
        $this->data['CustomAwardId'] = 94;
        $this->data['CustomTitleAwardId'] = $this->Player->get_custom_title_award_id();
        $this->data['CustomTitleAliasOptions'] = $this->Award->fetch_custom_title_alias_options();
        $this->data['PronounOptions'] = $this->Pronoun->fetch_pronoun_option_list($this->data['Player']['PronounId']);
        $this->data['PronounList']    = $this->Pronoun->fetch_pronoun_list();
        $this->data['Details']       = $this->Player->fetch_player_details($id);
        $this->data['Notes']         = [];  // loaded via AJAX on Notes tab click
        // Count-only check so the Notes tab visibility (and the infobox copy)
        // can be accurate on initial PHP render without fetching note bodies.
        $this->data['HasNotes']      = $this->Player->has_notes($id);
        $this->data['Dues']          = $this->Player->get_dues($id, 1, true);
        $this->data['AllDues']       = [];  // loaded via AJAX when dues modal opens
        $this->data['Units']         = $this->Unit->get_unit_list(['MundaneId' => $id, 'IncludeCompanies' => 1, 'IncludeHouseHolds' => 1, 'IncludeEvents' => 1, 'ActiveOnly' => 1, 'Lightweight' => 1]);
        $canEdit    = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_PARK, (int)($this->data['Player']['ParkId'] ?? 0), AUTH_EDIT);
        $playerParkId = (int)($this->data['Player']['ParkId'] ?? 0);
        $playerKingdomId = (int)($this->data['Player']['KingdomId'] ?? 0);
        $this->data['canEditAdmin'] = $canEdit;
        $this->data['canDeleteRecommendation'] = false;
        if ($uid > 0) {
            if (isset($this->session->park_id) && $this->Authorization->has_authority($uid, AUTH_PARK, (int)$this->session->park_id, AUTH_CREATE)) {
                $this->data['canDeleteRecommendation'] = true;
            } elseif (isset($this->session->kingdom_id) && $this->Authorization->has_authority($uid, AUTH_KINGDOM, (int)$this->session->kingdom_id, AUTH_CREATE)) {
                $this->data['canDeleteRecommendation'] = true;
            }
        }
        $this->data['pnCanManageBanner'] = ($uid === (int)$id)
            || $canEdit
            || ($playerKingdomId > 0 && $uid > 0 && $this->Authorization->has_authority($uid, AUTH_KINGDOM, $playerKingdomId, AUTH_EDIT))
            || ($uid > 0 && $this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN));
        $this->data['canManageAwards'] = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_PARK, $playerParkId, AUTH_CREATE);
        $knConfigs  = Common::get_configs($this->session->kingdom_id, CFG_KINGDOM);
        $recsPublic = isset($knConfigs['AwardRecsPublic']) ? (bool)(int)$knConfigs['AwardRecsPublic']['Value'] : true;
        $this->data['ShowRecsTab']          = $recsPublic || $canEdit;
        $this->data['ShowRecsTabLoggedIn']  = $uid > 0;
        $this->data['AwardRecommendations'] = [];  // loaded via AJAX on Recommendations tab click

        // Voting eligibility badge loaded via AJAX after page render (PlayerAjax/voting_eligible)

        $this->data['OfficerRoles'] = $this->Player->get_officer_roles($id);

        $this->data['RevokedAwards'] = [];
        $this->data['RevokedTitles'] = [];
        if ($canEdit) {
            $revoked = $this->Player->get_revoked_awards($id);
            $this->data['RevokedAwards'] = $revoked['RevokedAwards'] ?? [];
            $this->data['RevokedTitles'] = $revoked['RevokedTitles'] ?? [];
        }

        $displayGrants = $this->Player->get_display_grants($id);
        $this->data['IsOrkAdmin']       = $displayGrants['IsOrkAdmin'];
        $this->data['ViewerIsOrkAdmin'] = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_ADMIN, null, null);
        $this->data['AdminGrants'] = $displayGrants['AdminGrants'];

        // Attendance loaded async — counts start at 0 and are updated via AJAX
        $this->data['Stats'] = [
            'TotalAttendance'   => 0,
            'TotalAwards'       => 0,
            'TotalTitles'       => 0,
            'HighestClassLevel' => 0,
            'LastPlayedClass'   => '',
        ];
        if (is_array($this->data['Details']['Awards'])) {
            foreach ($this->data['Details']['Awards'] as $a) {
                if (in_array($a['OfficerRole'], ['none', null]) && $a['IsTitle'] != 1) {
                    $this->data['Stats']['TotalAwards']++;
                } else {
                    $this->data['Stats']['TotalTitles']++;
                }
            }
        }
        if (is_array($this->data['Details']['Classes'])) {
            foreach ($this->data['Details']['Classes'] as $c) {
                $credits = $c['Credits'] + $c['Reconciled'];
                if ($credits >= 53) {
                    $lvl = 6;
                } elseif ($credits >= 34) {
                    $lvl = 5;
                } elseif ($credits >= 21) {
                    $lvl = 4;
                } elseif ($credits >= 12) {
                    $lvl = 3;
                } elseif ($credits >= 5) {
                    $lvl = 2;
                } else {
                    $lvl = 1;
                }
                if ($lvl > $this->data['Stats']['HighestClassLevel']) {
                    $this->data['Stats']['HighestClassLevel'] = $lvl;
                }
            }
        }

        $preloadOfficers = [];
        $kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
        if (is_array($kingdomOfficers)) {
            foreach ($kingdomOfficers as $officer) {
                if (in_array($officer['OfficerRole'], ['Monarch', 'Regent']) && $officer['MundaneId'] > 0) {
                    $preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']];
                }
            }
        }
        $parkId = $this->data['Player']['ParkId'];
        if (valid_id($parkId)) {
            $parkOfficers = $this->Park->get_officers($parkId, $this->session->token);
            if (is_array($parkOfficers)) {
                foreach ($parkOfficers as $officer) {
                    if (in_array($officer['OfficerRole'], ['Monarch', 'Regent']) && $officer['MundaneId'] > 0) {
                        $preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']];
                    }
                }
            }
        }
        $this->data['PreloadOfficers'] = $preloadOfficers;

        $this->data['UpcomingRsvps']   = $this->Event->get_upcoming_rsvps((int)$id);
        $this->data['KingdomEvents']   = ($uid === (int)$id) ? $this->Event->get_kingdom_upcoming_events((int)$this->session->kingdom_id, (int)$id) : [];
        $this->data['IsOwnProfile']    = $uid === (int)$id;
        $this->data['Player']['ParkName'] = $this->session->park_name;


        // Beltline peers, associates, and title list (domain aggregate)
        $beltline = $this->Player->get_beltline_for_player($id, $uid);
        $this->data['BeltlinePeers'] = $beltline['Peers'];
        $this->data['BeltlineAssociates'] = $beltline['Associates'];
        if ($uid === (int)$id) {
            $this->data['MyAssociates'] = $beltline['MyAssociates'];
        }

        // Feast preferences for the About-tab "Feast Preferences" card.
        // Always loaded — the template gates visibility on Show My Feast
        // Preferences + presence of meaningful data. Cheap single-row read.
        $this->data['FeastPrefs'] = $this->Player->GetDietaryPreferences((int)$id);

        // Player titles for the design modal's prefix/suffix dropdowns.
        $this->data['PlayerTitles'] = $beltline['Titles'];

        // ===== Milestones Timeline Data =====
        $__milestones = [];
        $__awards = is_array($this->data['Details']['Awards']) ? $this->data['Details']['Awards'] : [];
        $__classes = is_array($this->data['Details']['Classes']) ? $this->data['Details']['Classes'] : [];

        // 1. First Sign-In — use PlayerSinceDate (already computed via MIN(date)
        // query at controller line ~358); no full-attendance scan needed.
        $__earliestDate = $this->data['Player']['PlayerSinceDate'] ?? null;
        if ($__earliestDate && $__earliestDate !== '0000-00-00' && $__earliestDate !== '1970-01-01') {
            $__milestones[] = ['type' => 'first_signin', 'date' => $__earliestDate, 'icon' => 'fa-door-open', 'description' => 'First sign-in at Amtgard'];
        }

        // 2. Reached Level 6 in Class — computed client-side once attendance loads
        // (see PlayerAjax/attendance handler in Playernew_index.tpl). Server-side
        // generation removed so we don't have to fetch full attendance during
        // page render.

        // 3-6: Awards-based milestones
        $__knightIds  = [17, 18, 19, 20, 245];
        $__knightNames = [17 => 'Flame', 18 => 'Crown', 19 => 'Serpent', 20 => 'Sword', 245 => 'Battle'];
        $__masterIds  = [1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 240, 244]; // mirrors $pnOrderToMaster values in Playernew_index.tpl
        foreach ($__awards as $__aw) {
            $__aid = (int)($__aw['AwardId'] ?? 0);
            $__awDate = $__aw['Date'] ?? '';
            // Prefer the player-specific custom_name when present (Custom Title /
            // Custom Award rows). Otherwise fall back to the kingdomaward name,
            // then the underlying award name.
            $__awName = !empty($__aw['CustomAwardName']) ? $__aw['CustomAwardName'] : (!empty($__aw['KingdomAwardName']) ? $__aw['KingdomAwardName'] : ($__aw['Name'] ?? ''));
            $__officerRole = $__aw['OfficerRole'] ?? 'none';
            $__isTitle = (int)($__aw['IsTitle'] ?? 0);
            $__aliasPeerage = $__aw['AliasPeerage'] ?? '';

            if (empty($__awDate) || $__awDate === '0000-00-00') {
                continue;
            }

            // Knight
            if (in_array($__aid, $__knightIds)) {
                $__knLabel = isset($__knightNames[$__aid]) ? 'Knight of the ' . $__knightNames[$__aid] : 'Knighted';
                $__milestones[] = ['type' => 'knight', 'date' => $__awDate, 'icon' => 'fa-shield-alt', 'description' => 'Earned ' . $__knLabel];
            }

            // Master title — only when the player actually holds the formal Master award,
            // not merely because they reached rank 10 of the corresponding Order.
            if (in_array($__aid, $__masterIds)) {
                $__milestones[] = ['type' => 'master', 'date' => $__awDate, 'icon' => 'fa-star', 'description' => 'Earned ' . $__awName];
            }

            // Paragon (class-specific paragon awards)
            $__paragonIds = [37,38,39,40,41,241,42,43,44,45,46,47,242,49,50,51];
            if (in_array($__aid, $__paragonIds)) {
                $__milestones[] = ['type' => 'paragon', 'date' => $__awDate, 'icon' => 'fa-gem', 'description' => 'Earned ' . $__awName];
            }

            // Title (IsTitle=1 and OfficerRole is none, exclude paragons/knights already handled above).
            // For Custom Titles aliased to a beltline peerage (Page/Squire/etc.),
            // suppress this — the 'became_associate' milestone already covers it.
            if ($__isTitle === 1 && in_array($__officerRole, ['none', null]) && !in_array($__aid, $__paragonIds) && !in_array($__aid, $__knightIds)
                && !in_array($__aliasPeerage, ['Page', 'Lords-Page', 'Squire', 'Man-At-Arms'])) {
                $__milestones[] = ['type' => 'title', 'date' => $__awDate, 'icon' => 'fa-crown', 'description' => 'Earned the title ' . $__awName];
            }

            // Served as Officer (OfficerRole is not none)
            if (!in_array($__officerRole, ['none', null, ''])) {
                $__milestones[] = ['type' => 'officer', 'date' => $__awDate, 'icon' => 'fa-landmark', 'description' => 'Served as ' . $__awName];
            }
        }

        // 7. Became Associate (peerage awards given TO this player - from BeltlinePeers data)
        $__blPeerLabels = ['Squire' => 'Squire', 'Man-At-Arms' => 'Person-at-Arms', 'Lords-Page' => "Lord's Page", 'Page' => 'Page'];
        if (!empty($this->data['BeltlinePeers'])) {
            foreach ($this->data['BeltlinePeers'] as $__bp) {
                $__peerDate = $__bp['Date'] ?? '';
                if (empty($__peerDate) || $__peerDate === '0000-00-00') {
                    continue;
                }
                $__peerLabel = $__blPeerLabels[$__bp['Peerage']] ?? $__bp['Peerage'];
                $__milestones[] = ['type' => 'became_associate', 'date' => $__peerDate, 'icon' => 'fa-handshake', 'description' => 'Became ' . $__peerLabel . ' to ' . $__bp['Persona']];
            }
        }

        // 8. Took Associate (peerage awards given BY this player - from BeltlineAssociates data)
        if (!empty($this->data['BeltlineAssociates'])) {
            foreach ($this->data['BeltlineAssociates'] as $__ba) {
                $__assocDate = $__ba['Date'] ?? '';
                if (empty($__assocDate) || $__assocDate === '0000-00-00') {
                    continue;
                }
                $__assocLabel = $__blPeerLabels[$__ba['Peerage']] ?? $__ba['Peerage'];
                $__milestones[] = ['type' => 'took_associate', 'date' => $__assocDate, 'icon' => 'fa-hand-holding-heart', 'description' => 'Took ' . $__ba['Persona'] . ' as ' . $__assocLabel];
            }
        }

        // 9. Custom milestones from DB
        $__customMs = $this->Player->get_custom_milestones((int)$id);
        if (is_array($__customMs)) {
            foreach ($__customMs as $__cm) {
                $__milestones[] = [
                    'type' => 'custom',
                    'date' => $__cm['MilestoneDate'],
                    'icon' => $__cm['Icon'],
                    'description' => $__cm['Description'],
                    'milestoneId' => (int)$__cm['MilestoneId'],
                ];
            }
        }

        // Cross-type dedup:
        // 1. Remove 'title' milestones for peerage terms (already covered by 'became_associate')
        // 2. Remove 'title' milestones for "Master X" that duplicate an existing 'master' milestone
        $__masterMsNames = [];
        foreach ($__milestones as $__m) {
            if ($__m['type'] === 'master') {
                $__masterMsNames[] = strtolower(preg_replace('/^Earned (?:Master )?/', '', $__m['description']));
            }
        }
        $__peerageTerms = ['squire', 'man-at-arms', 'person-at-arms', "lord's page", 'page'];
        $__milestones = array_values(array_filter($__milestones, function ($m) use ($__masterMsNames, $__peerageTerms) {
            if ($m['type'] !== 'title') {
                return true;
            }
            $__tn = strtolower(preg_replace('/^Earned the title /', '', $m['description']));
            if (in_array($__tn, $__peerageTerms)) {
                return false;
            }
            if (substr($__tn, 0, 7) === 'master ') {
                $__kw = substr($__tn, 7);
                foreach ($__masterMsNames as $__mn) {
                    if (strpos($__mn, $__kw) !== false) {
                        return false;
                    }
                }
            }
            return true;
        }));

        // Deduplicate milestones with same description + date
        $__seen = [];
        $__milestones = array_filter($__milestones, function ($m) use (&$__seen) {
            $key = $m['date'] . '|' . $m['description'];
            if (isset($__seen[$key])) {
                return false;
            }
            $__seen[$key] = true;
            return true;
        });

        // Sort chronologically ascending
        usort($__milestones, function ($a, $b) {
            return strtotime($a['date']) - strtotime($b['date']);
        });

        $this->data['Milestones'] = $__milestones;
        $this->data['CustomMilestones'] = is_array($__customMs) ? $__customMs : [];
        $this->data['MilestoneConfig'] = $this->data['Player']['MilestoneConfig'] ?? '';

        // Collapse the Peers/Associates *display* lists to one row per
        // counterparty, keeping the highest-precedence peerage (the SQL
        // already orders Squire→Page so the first row wins). Run this AFTER
        // milestones are built so the historical "Became Page → Became
        // Squire" progression still surfaces in the timeline.
        $__dedupeByKey = function (array $rows, string $key): array {
            $__seen = [];
            return array_values(array_filter($rows, function ($r) use (&$__seen, $key) {
                if (isset($__seen[$r[$key]])) {
                    return false;
                }
                $__seen[$r[$key]] = true;
                return true;
            }));
        };
        if (!empty($this->data['BeltlinePeers'])) {
            $this->data['BeltlinePeers'] = $__dedupeByKey($this->data['BeltlinePeers'], 'PeerId');
        }
        if (!empty($this->data['BeltlineAssociates'])) {
            $this->data['BeltlineAssociates'] = $__dedupeByKey($this->data['BeltlineAssociates'], 'RecipientId');
        }

        // Qualification test results — use the viewed player's home kingdom, not the session context
        $playerKingdomId    = (int)($this->data['Player']['KingdomId'] ?? $this->session->kingdom_id);
        $playerKnConfigs    = Common::get_configs($playerKingdomId, CFG_KINGDOM);
        $qualReeveEnabled   = isset($playerKnConfigs['QualTestReeveEnabled'])
            ? (bool)(int)$playerKnConfigs['QualTestReeveEnabled']['Value']
            : false;
        $qualCorporaEnabled = isset($playerKnConfigs['QualTestCorporaEnabled'])
            ? (bool)(int)$playerKnConfigs['QualTestCorporaEnabled']['Value']
            : false;

        $this->data['QualTestReeveEnabled']   = $qualReeveEnabled;
        $this->data['QualTestCorporaEnabled'] = $qualCorporaEnabled;
        $this->data['QualKingdomId']          = $playerKingdomId;
        $this->data['QualPlayerId']           = (int)$id;

        // The kingdom switch says the kingdom PARTICIPATES; it does not say a test exists yet.
        // Offering "Take Test" off the switch alone meant a player could accept and immediately
        // be told "Not enough active questions available" — inviting them to do something that
        // cannot be done. A test is takeable only if it is ALSO published with enough questions.
        $this->data['QualTakeable'] = [
            'reeve'   => $qualReeveEnabled   && Ork3::$Lib->qualtest->hasTakeableVersion($playerKingdomId, 'reeve'),
            'corpora' => $qualCorporaEnabled && Ork3::$Lib->qualtest->hasTakeableVersion($playerKingdomId, 'corpora'),
        ];

        if ($qualReeveEnabled || $qualCorporaEnabled) {
            $this->data['QualResults']   = Ork3::$Lib->qualtest->getPlayerResults((int)$id, $playerKingdomId);
            $this->data['QualCanManage'] = $canEdit || Ork3::$Lib->qualtest->canManage($uid, $playerKingdomId);
            $this->data['QualConfigs']   = [
                'reeve'   => $qualReeveEnabled ? Ork3::$Lib->qualtest->getConfig($playerKingdomId, 'reeve') : null,
                'corpora' => $qualCorporaEnabled ? Ork3::$Lib->qualtest->getConfig($playerKingdomId, 'corpora') : null,
            ];
        } else {
            $this->data['QualResults']   = [];
            $this->data['QualCanManage'] = false;
            $this->data['QualConfigs']   = ['reeve' => null, 'corpora' => null];
        }
    }


    public function reconcile($id = null)
    {
        $this->template = '../revised-frontend/Playernew_reconcile.tpl';

        $uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $id  = (int)$id;

        if (!$uid) {
            header('Location: ' . UIR . "Login/login/Player/reconcile/$id");
            exit;
        }

        $this->data['Player']  = $this->Player->fetch_player($id);
        $this->data['Details'] = $this->Player->fetch_player_details($id);
        $this->data['KingdomId'] = $this->session->kingdom_id;
        $this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');

        $playerParkId = (int)($this->data['Player']['ParkId'] ?? 0);
        $canEditAdmin = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_PARK, $playerParkId, AUTH_EDIT);
        $isOwnProfile = $uid === $id;
        if (!$canEditAdmin && !$isOwnProfile) {
            header('Location: ' . UIR . "Player/profile/$id");
            exit;
        }
        $this->data['canEditAdmin'] = $canEditAdmin;

        $this->load_model('Kingdom');
        $preloadOfficers = [];
        $kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
        if (is_array($kingdomOfficers)) {
            foreach ($kingdomOfficers as $officer) {
                if (in_array($officer['OfficerRole'], ['Monarch', 'Regent', 'Prime Minister']) && $officer['MundaneId'] > 0) {
                    $preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']];
                }
            }
        }
        if ($playerParkId > 0) {
            $parkOfficers = $this->Park->get_officers($playerParkId, $this->session->token);
            if (is_array($parkOfficers)) {
                foreach ($parkOfficers as $officer) {
                    if (in_array($officer['OfficerRole'], ['Monarch', 'Regent', 'Prime Minister']) && $officer['MundaneId'] > 0) {
                        $preloadOfficers[] = ['MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']];
                    }
                }
            }
        }
        $this->data['PreloadOfficers'] = $preloadOfficers;

        // AwardId → KingdomAwardId map for current kingdom (pre-match historical award dropdowns)
        $this->data['AwardIdToKingdomAwardId'] = $this->Player->get_reconcile_award_map((int)$this->session->kingdom_id);
    }

}
