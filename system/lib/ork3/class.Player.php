<?php

class Player extends Ork3
{
    public function __construct()
    {
        parent::__construct();
        $this->mundane = new yapo($this->db, DB_PREFIX . 'mundane');
        $this->notes = new yapo($this->db, DB_PREFIX . 'mundane_note');
        $this->dues = new yapo($this->db, DB_PREFIX . 'dues');
        $this->pronoun = new yapo($this->db, DB_PREFIX . 'pronoun');
        $this->selfreg_link = new yapo($this->db, DB_PREFIX . 'selfreg_link');
        $this->load_model('Kingdom');
        $this->load_model('Park');
        $this->load_model('Pronoun');
    }

    private $_customTitleAwardId = null;
    public function getCustomTitleAwardId()
    {
        if ($this->_customTitleAwardId !== null) {
            return $this->_customTitleAwardId;
        }
        $r = $this->db->query("SELECT award_id FROM " . DB_PREFIX . "award WHERE name = 'Custom Title' AND officer_role='none' LIMIT 1");
        $this->_customTitleAwardId = 0;
        if ($r && $r->size() > 0) {
            $r->next();
            $this->_customTitleAwardId = (int)$r->award_id;
        }
        return $this->_customTitleAwardId;
    }

    public function AddOneShotFaceImage($request)
    {
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (valid_id($requester_id) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE) || $requester_id == $request['MundaneId']) {
            //try {
            $json_call = array(
                "jsonrpc" => "2.0",
                "method" => "store",
                "params" => array(
                    BEHOLD_KEY,
                    $request['MundaneId'],
                    $request['Base64FaceImage']
                  ),
                "id" => 1
              );
            $ch = curl_init('https://behold.amtgard.com/');
            curl_setopt($ch, CURLOPT_POST, 1);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, 5);
            curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_call));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
            $response = curl_exec($ch);
            curl_close($ch);
            $result = json_decode($response);
            return $result->result;
        } else {
            error_log('ORK_DEBUG No Authorization found.: ' . json_encode(null));
            return NoAuthorization();
        }

    }

    public function LookupByFaces($request)
    {
        $json_call = array(
            "jsonrpc" => "2.0",
            "method" => "lookup",
            "params" => array(
                $request['Base64Selfie']
              ),
            "id" => 1
          );
        $ch = curl_init('https://behold.amtgard.com/');
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 20);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($json_call));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-type: application/json']);
        $response = curl_exec($ch);
        curl_close($ch);
        $result = json_decode($response);

        $facedetails = array();

        $found = array();

        foreach ($result->result->hits as $k => $face) {
            if (!is_null($face)) {
                $found[] =  $face[0];
            }
        }

        $playersfound = $this->hydrated_players($found);

        foreach ($result->result->hits as $k => $face) {
            $player = is_null($face) ? array('id' => 0) : $playersfound[$face[0]];
            $facedetails[] = [ $player, $result->result->locations[$k] ];
        }

        return $facedetails;
    }

    public function GetNotes($request)
    {
        if (valid_id($request['MundaneId'])) {
            $this->notes->clear();
            $this->notes->mundane_id = $request['MundaneId'];
            $notes = array();
            if ($this->notes->find()) {
                do {
                    $notes[] = array(
                            'NoteId' => $this->notes->mundane_note_id,
                            'Note' => $this->notes->note,
                            'Description' => $this->notes->description,
                            'GivenBy' => $this->notes->given_by,
                            'Date' => $this->notes->date,
                            'DateComplete' => $this->notes->date_complete,
                        );
                } while ($this->notes->next());
            }
        }
        return $notes;
    }

    public function AddNote($request)
    {
        $thePlayer = $this->player_info($request['MundaneId']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)
                    || $mundane_id == $request['MundaneId'])) {
            $this->notes->clear();
            $this->notes->mundane_id = $request['MundaneId'];
            $this->notes->note = $request['Note'];
            $this->notes->description = $request['Description'];
            $this->notes->given_by = $request['GivenBy'];
            $this->notes->date = date('Y-m-d', strtotime($request['Date']));
            $this->notes->date_complete = date('Y-m-d', strtotime($request['DateComplete']));
            $this->notes->save();
            return Success($this->notes->mundane_note_id);
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveNote($request)
    {
        $note = new stdClass();

        logtrace("RemoveNote", $request);
        if (valid_id($request['NotesId'])) {
            $this->notes->clear();
            $this->notes->mundane_note_id = $request['NotesId'];
            $this->notes->mundane_id = $request['MundaneId'];
            if ($this->notes->find()) {
                $thePlayer = $this->player_info($this->notes->mundane_id);

                if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                        && (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)
                            || $mundane_id == $request['MundaneId'])) {

                    $note->mundane_note_id = $this->notes->mundane_note_id;
                    $note->mundane_id = $this->notes->mundane_id;
                    $note->note = $this->notes->note;
                    $note->description = $this->notes->description;
                    $note->given_by = $this->notes->given_by;
                    $note->date = $this->notes->date;
                    $note->date_complete = $this->notes->date_complete;

                    Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $note->mundane_id, $note);

                    $this->notes->delete();
                    return Success();
                }
                return NoAuthorization();
            }
            return InvalidParameter('Cannot find Note.');
        }
        return InvalidParameter('A note must be selected.');
    }

    public function EditNote($request)
    {
        if (!valid_id($request['NotesId'])) {
            return InvalidParameter('A note must be selected.');
        }
        $this->notes->clear();
        $this->notes->mundane_note_id = $request['NotesId'];
        $this->notes->mundane_id      = $request['MundaneId'];
        if (!$this->notes->find()) {
            return InvalidParameter('Cannot find Note.');
        }
        $thePlayer = $this->player_info($this->notes->mundane_id);
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
            && (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)
                || $mundane_id == $request['MundaneId'])) {
            $this->notes->note         = $request['Note'];
            $this->notes->description  = $request['Description'];
            $this->notes->date         = date('Y-m-d', strtotime($request['Date']));
            $this->notes->date_complete = ($request['DateComplete'] ? date('Y-m-d', strtotime($request['DateComplete'])) : '');
            $this->notes->save();
            return Success($this->notes->mundane_note_id);
        }
        return NoAuthorization();
    }

    public function ClearNotes($request)
    {
        if (!valid_id($request['MundaneId'])) {
            return InvalidParameter('Invalid player ID.');
        }
        $uid = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if ($uid <= 0) {
            return NoAuthorization();
        }
        $thePlayer = $this->player_info($request['MundaneId']);
        $isOwn  = $uid === (int)$request['MundaneId'];
        $isAdmin = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT);
        if (!$isOwn && !$isAdmin) {
            return NoAuthorization();
        }
        $this->db->query('DELETE FROM ' . DB_PREFIX . 'mundane_note WHERE mundane_id = ' . intval($request['MundaneId']));
        return Success();
    }

    public function SetPlayerReconciledCredits($request)
    {

        $thePlayer = $this->player_info($request['MundaneId']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $thePlayer['ParkId'], AUTH_EDIT)) {
            $reconciled = new yapo($this->db, DB_PREFIX . 'class_reconciliation');
            foreach ($request['Reconcile'] as $k => $values) {
                $reconciled->clear();
                $reconciled->class_id = $values['ClassId'];
                $reconciled->mundane_id = $request['MundaneId'];
                if (!$reconciled->find()) {
                    $reconciled->clear();
                    $reconciled->class_id = $values['ClassId'];
                    $reconciled->mundane_id = $request['MundaneId'];
                };
                if ($reconciled->mundane_id == $request['MundaneId'] && $reconciled->class_id == $values['ClassId']) {
                    $reconciled->reconciled = $values['Quantity'];
                    $reconciled->save();
                } else {
                    return InvalidParameter('Problem with request.');
                }
            }
            $ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$request['MundaneId']]);
            Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $ck);
            return Success();
        } else {
            return NoAuthorization();
        }
    }

    public function GetPlayer($request)
    {
        $fetchprivate = true;
        $this->mundane->clear();
        $this->mundane->mundane_id = $request['MundaneId'];
        $response = array();
        if (valid_id($request['MundaneId']) && $this->mundane->find()) {
            if ((($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                    && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT)) ||
                    $mundane_id == $request['MundaneId']) {
                $fetchprivate = false;
            }
            $heraldry = Ork3::$Lib->heraldry->GetHeraldryUrl(array('Type' => 'Player', 'Id' => $this->mundane->mundane_id));
            $response['Status'] = Success();
            // Moving Dues response here to stuff the old DuesThrough response until mORK updates go out
            $dues = $this->GetDues(['MundaneId' => $this->mundane->mundane_id, 'ExcludeRevoked' => 1, 'Active' => 1]);
            // Sort the dues by date and use the furthest out DuesUntil
            usort($dues, function ($a, $b) {
                return strtotime($a['DuesUntil']) - strtotime($b['DuesUntil']);
            });
            $old_dues_through = (!empty($dues)) ? $dues[sizeof($dues) - 1]['DuesUntil'] : '';
            // Also fetch all non-revoked dues (including expired) to find the most recent expiry date
            $all_dues = $this->GetDues(['MundaneId' => $this->mundane->mundane_id, 'ExcludeRevoked' => 1]);
            usort($all_dues, function ($a, $b) {
                return strtotime($a['DuesUntil']) - strtotime($b['DuesUntil']);
            });
            $last_dues_through = (!empty($all_dues)) ? $all_dues[sizeof($all_dues) - 1]['DuesUntil'] : '';
            // Determine if player is new: fewer than 4 total credits AND at least one credit within the last 14 days.
            // Skip the attendance query for the 99.9%+ of players who joined more than 14 days ago.
            $mid = (int)$this->mundane->mundane_id;
            $park_member_since = $this->mundane->park_member_since;
            $is_new_player = false;
            if (!empty($park_member_since) && $park_member_since !== '0000-00-00' && strtotime($park_member_since) >= strtotime('-14 days')) {
                $att_row = $this->db->query(
                    "SELECT SUM(credits) AS total_credits, SUM(CASE WHEN date >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) THEN credits ELSE 0 END) AS recent_credits" .
                    " FROM " . DB_PREFIX . "attendance WHERE mundane_id = $mid AND credits > 0"
                );
                $att_row->next();
                $total_credits  = (float)($att_row->total_credits  ?? 0);
                $recent_credits = (float)($att_row->recent_credits ?? 0);
                $is_new_player  = $total_credits < 4 && ($total_credits === 0.0 || $recent_credits > 0);
            }
            $this->pronoun->clear();
            $this->pronoun->pronoun_id = $this->mundane->pronoun_id;
            $this->pronoun->find();
            // Paired design-preferences row (always present after the 2026-04-23 migration).
            $design = new yapo($this->db, DB_PREFIX . 'mundane_design');
            $design->clear();
            $design->mundane_id = $this->mundane->mundane_id;
            $design->find();
            // Per-field public exposure: when fetchprivate is true (viewer is a
            // non-admin, non-self logged-in user), honor the player's design-
            // modal opt-ins — but only if they aren't Restricted.
            $_isLoggedIn   = $mundane_id > 0;
            $_isRestricted = (int)$this->mundane->restricted === 1;
            $_exposeFirst  = !$fetchprivate || ($_isLoggedIn && !$_isRestricted && (int)$design->show_mundane_first === 1);
            $_exposeLast   = !$fetchprivate || ($_isLoggedIn && !$_isRestricted && (int)$design->show_mundane_last  === 1);
            $_exposeEmail  = !$fetchprivate || ($_isLoggedIn && !$_isRestricted && (int)$design->show_email         === 1);
            $subject = $this->pronoun->subject;
            $pronoun_custom = $this->mundane->pronoun_custom;
            $pronountext = isset($subject) ? $this->pronoun->subject . '[' . $this->pronoun->object . ']' : '';
            $pronouncustomArr = (isset($pronoun_custom) && json_decode($this->mundane->pronoun_custom)) ? $this->Pronoun->fetch_custom_pronoun_display($this->mundane->pronoun_custom) : false;
            //$pronouncustomtext = json_encode($pronouncustomArr);
            $pronouncustomtext = (isset($pronouncustomArr) && $pronouncustomArr) ? implode('/', $pronouncustomArr['subjective']) . ' [' . implode('/', $pronouncustomArr['objective']) . ' ' . implode('/', $pronouncustomArr['possessive']) . ' ' . implode('/', $pronouncustomArr['possessivepronoun']) . ' ' . implode('/', $pronouncustomArr['reflexive']) . ']' : '';

            $response['Player'] = array(
                    'MundaneId' => $this->mundane->mundane_id,
                    'GivenName' => $_exposeFirst ? $this->mundane->given_name : "",
                    'Surname'   => $_exposeLast ? $this->mundane->surname : "",
                    'OtherName' => $fetchprivate ? "" : $this->mundane->other_name,
                    'UserName' => $this->mundane->username,
                    'PronounId' => $this->mundane->pronoun_id,
                    'PronounCustom' => $this->mundane->pronoun_custom,
                    'PronounText' => $pronountext,
                    'PronounCustomText' => $pronouncustomtext,
                    'Persona' => $this->mundane->persona,
                    'Suspended' => $this->mundane->suspended,
                    'SuspendedAt' => $this->mundane->suspended_at,
                    'SuspendedUntil' => $this->mundane->suspended_until,
                    'Suspension' => $this->mundane->suspension,
                    'Email' => $_exposeEmail ? $this->mundane->email : "",
                    'ParkId' => $this->mundane->park_id,
                    'KingdomId' => $this->mundane->kingdom_id,
                    'Restricted' => $this->mundane->restricted,
                    'Waivered' => $this->mundane->waivered,
                    'Waiver' => $fetchprivate ? "" : (HTTP_WAIVERS . sprintf('%06d.' . $this->mundane->waiver_ext, $this->mundane->mundane_id)),
                    'WaiverExt' => $this->mundane->waiver_ext,
                    'ReeveQualified' => $this->mundane->reeve_qualified,
                    'ReeveQualifiedUntil' => $this->mundane->reeve_qualified_until,
                    'CorporaQualified' => $this->mundane->corpora_qualified,
                    'CorporaQualifiedUntil' => $this->mundane->corpora_qualified_until,
                    'DuesThrough' => $old_dues_through, //Ork3::$Lib->treasury->dues_through($this->mundane->mundane_id, $this->mundane->kingdom_id, $this->mundane->park_id, 0),
                'LastDuesThrough' => $last_dues_through,
                    'HasHeraldry' => $this->mundane->has_heraldry,
                    'Heraldry' => $heraldry['Url'] . '?' . strtotime($this->mundane->modified),
                    'HasImage' => $this->mundane->has_image,
                    'Image' => $this->resolve_player_image_url($this->mundane->mundane_id, $this->mundane->modified),
                    'PenaltyBox' => $this->mundane->penalty_box,
                    'Active' => $this->mundane->active,
                    'PasswordExpires' => $this->mundane->password_expires,
                    //'ParkMemberSince' => date('d/m/Y', strtotime($this->mundane->park_member_since))
                    'ParkMemberSince' => $this->mundane->park_member_since,
                    'IsNewPlayer' => $is_new_player,
                    'DuesPaidList' => $dues,
                    'AboutPersona' => $design->about_persona,
                    'AboutStory' => $design->about_story,
                    'ColorPrimary' => $design->color_primary,
                    'ColorAccent' => $design->color_accent,
                        'ColorSecondary' => $design->color_secondary,
                        'HeroGradient' => $design->hero_gradient,
                        'HeroOverlay' => $design->hero_overlay,
                    'NamePrefix' => $design->name_prefix,
                    'NameSuffix' => $design->name_suffix,
                        'SuffixComma' => (int)$design->suffix_comma,
                    'PhotoFocusX' => $design->photo_focus_x,
                    'PhotoFocusY' => $design->photo_focus_y,
                    'PhotoFocusSize' => $design->photo_focus_size,
                        'ShowBeltline' => (int)$design->show_beltline,
                        'ShowFeastPrefs' => (int)$design->show_feast_prefs,
                        'PronunciationGuide' => $design->pronunciation_guide,
                        'ShowMundaneFirst' => (int)$design->show_mundane_first,
                        'ShowMundaneLast' => (int)$design->show_mundane_last,
                        'ShowEmail' => (int)$design->show_email,
                        'MilestoneConfig' => $design->milestone_config,
                        'NameFont'   => $design->name_font,
                        'NameShadow' => (int)$design->name_shadow,
                            'BeltDisplay' => $design->belt_display,
                        'BasicFonts' => (int)$this->mundane->basic_fonts,
                        'DyslexiaFonts' => (int)$this->mundane->dyslexia_fonts,
                );
            $unit = Ork3::$Lib->report->UnitSummary(array( 'MundaneId' => $this->mundane->mundane_id, 'IncludeCompanies' => 1, 'ActiveOnly' => 1 ));
            if ($unit['Status']['Status'] != 0) {
                $response['Player']['Company'] = "";
            } else {
                $response['Player']['Company'] = $unit['Units'];
            }
            // Hydrate banner fields via raw DataSet (avoids Yapo schema-cache misses)
            global $DB;
            $DB->Clear();
            $_bn = $DB->DataSet('SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y FROM ork_mundane WHERE mundane_id = ' . (int)$this->mundane->mundane_id);
            if ($_bn && $_bn->Next()) {
                $response['Player']['HasBanner']      = (int)$_bn->has_banner;
                $response['Player']['BannerShowLogo'] = (int)$_bn->banner_show_logo;
                $response['Player']['BannerVignette'] = (int)$_bn->banner_vignette;
                $response['Player']['BannerOffsetX']  = (int)$_bn->banner_offset_x;
                $response['Player']['BannerOffsetY']  = (int)$_bn->banner_offset_y;
            }
        } else {
            $response['Status'] = InvalidParameter();
        }
        return $response;
    }

    public function AttendanceForPlayer($request)
    {
        $sql = "select 
              a.*, c.name as class_name,
                ifnull(p.name, ep.name) as park_name,
                ifnull(k.name, ek.name) as kingdom_name,
                e.name as event_name, e.park_id as event_park_id, e.kingdom_id as event_kingdom_id,
                ep.name as event_park_name, ek.name as event_kingdom_name,
                bwm.persona as by_whom_persona
					from " . DB_PREFIX . "attendance a
						left join " . DB_PREFIX . "park p on a.park_id = p.park_id
						left join " . DB_PREFIX . "kingdom k on a.kingdom_id = k.kingdom_id
						left join " . DB_PREFIX . "class c on a.class_id = c.class_id
						left join " . DB_PREFIX . "event e on a.event_id = e.event_id
							left join " . DB_PREFIX . "park ep on e.park_id = ep.park_id
							left join " . DB_PREFIX . "kingdom ek on e.kingdom_id = ek.kingdom_id
						left join " . DB_PREFIX . "mundane bwm on bwm.mundane_id = a.by_whom_id
          where a.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'";
        $date_start = $request['date_start'];
        if (!is_null($date_start) && strtotime($date_start)) {
            $when = date("Y-m-d", strtotime($date_start));
            $sql .= " and a.date >= '$when' ";
        }
        if ($request['order'] && ($request['order'] == 'asc' || $request['order'] == 'desc')) {
            $order = $request['order'];
        } else {
            $order = 'desc';
        }
        $sql .= " order by a.date " . $order;
        $limit = $request['limit'];
        $r = $this->db->query($sql);
        $response = array();
        $response['Attendance'] = array();
        if ($r === false) {
            $response['Status'] = InvalidParameter(null, 'Problem processing request.');
        } elseif ($r->size() > 0) {
            while ($r->next()) {
                $response['Attendance'][] = array(
                        'AttendanceId' => $r->attendance_id,
                        'EnteredById' => $r->by_whom_id,
                        'EnteredBy'   => $r->by_whom_persona,
                        'EnteredAt'   => $r->entered_at,
                        'EntryMethod' => $r->entry_method,
                        'MundaneId' => $r->mundane_id,
                        'ClassId' => $r->class_id,
                        'Date' => $r->date,
                        'ParkId' => $r->park_id,
                        'KingdomId' => $r->kingdom_id,
                        'EventId' => $r->event_id,
                        'EventCalendarDetailId' => $r->event_calendardetail_id,
                        'EventParkId' => $r->event_park_id,
                        'EventKingdomId' => $r->event_kingdom_id,
                        'EventParkName' => $r->event_park_name,
                        'EventKingdomName' => $r->event_kingdom_name,
                        'Credits' => $r->credits,
                        'Flavor' => $r->flavor,
                        'ClassName' => $r->class_name,
                        'ParkName' => $r->park_name,
                        'KingdomName' => $r->kingdom_name,
                        'EventName' => $r->event_name
                    );
                if (is_numeric($limit)) {
                    $limit--;
                    if ($limit == 0) {
                        break;
                    }
                }
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = Success();
        }
        return $response;
    }

    public function AwardsForPlayer($request)
    {
        if (valid_id($request['AwardsId'])) {
            $player_award = "or awards.awards_id = '" . mysql_real_escape_string($request['AwardsId']) . "'";
        }
        $sql = "select distinct awards.*, a.*,
						GREATEST(IFNULL(a.is_title,0), IFNULL(ka.is_title,0), IFNULL(alias.is_title,0)) as is_title,
						COALESCE(alias.title_class, a.title_class, ka.title_class) as title_class,
						COALESCE(alias.peerage, a.peerage) as peerage,
						COALESCE(alias.officer_role, a.officer_role) as officer_role,
						COALESCE(alias.is_ladder, a.is_ladder) as is_ladder,
						alias.award_id as alias_award_id_resolved,
						alias.name as alias_award_name,
						alias.peerage as alias_peerage,
						ka.name as kingdom_awardname, p.name as park_name, k.name as kingdom_name, e.name as event_name, m.persona, bwm.persona as entered_by_persona, bwm.mundane_id as entered_by_id
					from " . DB_PREFIX . "awards awards
						left join " . DB_PREFIX . "kingdomaward ka on awards.kingdomaward_id = ka.kingdomaward_id
							left join " . DB_PREFIX . "award a on a.award_id = ka.award_id
						left join " . DB_PREFIX . "award alias on alias.award_id = awards.alias_award_id
						left join " . DB_PREFIX . "park p on p.park_id = awards.at_park_id
						left join " . DB_PREFIX . "kingdom k on k.kingdom_id = awards.at_kingdom_id
						left join " . DB_PREFIX . "event e on e.event_id = awards.at_event_id
						left join " . DB_PREFIX . "mundane m on m.mundane_id = awards.given_by_id
						left join " . DB_PREFIX . "mundane bwm on bwm.mundane_id = awards.by_whom_id
					where awards.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' $player_award
					order by
						COALESCE(alias.is_ladder, a.is_ladder, 0), GREATEST(IFNULL(a.is_title,0), IFNULL(ka.is_title,0), IFNULL(alias.is_title,0)), COALESCE(alias.title_class, a.title_class, ka.title_class, 0), a.name, awards.rank, awards.date";

        $r = $this->db->query($sql);
        $response = array();
        $response['Awards'] = array();
        if ($r === false) {
            $response['Status'] = InvalidParameter(null, 'Problem processing request.');
        } elseif ($r->size() > 0) {
            while ($r->next()) {
                $response['Awards'][] = array(
                        'AwardsId' => $r->awards_id,
                        'AwardId' => $r->award_id,
                        'KingdomAwardId' => $r->kingdomaward_id,
                        'MundaneId' => $r->mundane_id,
                        'Rank' => $r->rank,
                        'Date' => $r->date,
                        'GivenById' => $r->given_by_id,
                        'Note' => $r->note,
                        // "Where given" comes from at_park_id / at_kingdom_id / at_event_id.
                        // The bare park_id/kingdom_id columns store the recipient's home park
                        // at grant time and should NOT round-trip back through the edit modal.
                        'ParkId' => $r->at_park_id,
                        'KingdomId' => $r->at_kingdom_id,
                        'EventId' => $r->at_event_id,
                        'Name' => $r->name,
                        'KingdomAwardName' => $r->kingdom_awardname,
                        'CustomAwardName' => $r->custom_name,
                        'IsLadder' => $r->is_ladder,
                        'IsTitle' => $r->is_title,
                        'TitleClass' => $r->title_class,
                        'OfficerRole' => $r->officer_role,
                        'Peerage' => $r->peerage,
                        'AliasAwardId' => (int)($r->alias_award_id_resolved ?? 0),
                        'AliasAwardName' => $r->alias_award_name,
                        'AliasPeerage' => $r->alias_peerage,
                        'ParkName' => $r->park_name,
                        'KingdomName' => $r->kingdom_name,
                        'EventName' => $r->event_name,
                        'GivenBy' => $r->persona,
                        'EnteredById' => $r->entered_by_id,
                        'EnteredBy' => $r->entered_by_persona,
                    );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = Success();
        }
        return $response;
    }

    // Peerage voting "circles" the given player belongs to, expressed as a set of
    // award_ids. Knighthoods vote as ONE group: holding any knighthood (award_id in
    // 17,18,19,20,245) puts all five in the circle. Paragons vote in SEPARATE
    // per-type circles: each Paragon held adds only that exact award_id. Masters are
    // intentionally excluded. Returns [] when the player holds no knighthood/paragon.
    public function GetCircleAwardIds($mundane_id)
    {
        $mundane_id = (int)$mundane_id;
        if ($mundane_id <= 0) {
            return array();
        }
        $knightSet = array(17, 18, 19, 20, 245);
        $sql = "select distinct a.award_id, a.peerage
				from " . DB_PREFIX . "awards aw
				join " . DB_PREFIX . "kingdomaward ka on ka.kingdomaward_id = aw.kingdomaward_id
				join " . DB_PREFIX . "award a on a.award_id = ka.award_id
				where aw.mundane_id = " . $mundane_id . "
				  and (a.peerage = 'Paragon' or a.award_id in (" . implode(',', $knightSet) . "))";
        $r = $this->db->query($sql);
        $set = array();
        $hasKnight = false;
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $aid = (int)$r->award_id;
                if (in_array($aid, $knightSet, true)) {
                    $hasKnight = true;
                } elseif ($r->peerage === 'Paragon') {
                    $set[$aid] = true;
                }
            }
        }
        if ($hasKnight) {
            foreach ($knightSet as $k) {
                $set[$k] = true;
            }
        }
        return array_values(array_map('intval', array_keys($set)));
    }

    public function GetPlayerClasses($request)
    {
        // Cold-cache the dedupe-by-date subquery costs ~185ms for the busiest player
        // in the DB (1500+ attendance rows). Memcache to convert most loads to ~1ms.
        // Bust in Attendance::Add/Set/RemoveAttendance and SetPlayerReconciledCredits.
        $ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)($request['MundaneId'] ?? 0)]);
        if (($cached = Ork3::$Lib->ghettocache->get('Player.GetPlayerClasses', $ck, 300)) !== false) {
            return $cached;
        }
        /*
            This does not prevent double-counting for someone who signs as different classes in the same week

            -- It does now, which is going to piss some people off
            -- Class double-counting can be added back in by changing
                    "group by ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id"
                    to
                    group by ssa.class_id, ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id


            -- 2015-06-22
                Now it really does prevent double-counting, by using a subquery to gather the "first" entry on each date rather
                than relying on the innodb code to randomly group by date into whatever class (over-counting some classes, under-counting others)
        ONE PER WEEK

        $sql = "select c.class_id, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
                    from " . DB_PREFIX . "class c
                        left join
                            (select ssa.class_id, count(ssa.attendance_id) as attendances, max(ssa.credits) as credits, ssa.date_week6 as week
                                from " . DB_PREFIX . "attendance ssa
                                where
                                    ssa.mundane_id = $request[MundaneId]
                                group by ssa.date_year, ssa.date_week6) a on a.class_id = c.class_id
                        left join " . DB_PREFIX . "class_reconciliation cr on cr.class_id = c.class_id and cr.mundane_id = $request[MundaneId]
                    group by c.class_id
                ";
        */
        $sql = "select c.class_id, c.active, c.name as class_name, count(a.week) as weeks, sum(a.attendances) as attendances, sum(a.credits) as credits, cr.class_reconciliation_id, cr.reconciled
					from " . DB_PREFIX . "class c
						left join
							(select ssa.class_id, count(ssa.attendance_id) as attendances, sum(ssa.credits) as credits, ssa.date_week6 as week
								from
								(select min(killdupe.attendance_id) as attendance_id from " . DB_PREFIX . "attendance killdupe where killdupe.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "' group by killdupe.date) kd
								left join " . DB_PREFIX . "attendance ssa on ssa.attendance_id = kd.attendance_id
								where
									ssa.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
								group by ssa.class_id, ssa.date) a on a.class_id = c.class_id
						left join " . DB_PREFIX . "class_reconciliation cr on cr.class_id = c.class_id and cr.mundane_id = '" . mysql_real_escape_string($request['MundaneId']) . "'
                    where c.active = 1
					group by c.class_id
				";
        //echo $sql;
        $r = $this->db->query($sql);
        $response = array();
        $response['Classes'] = array();
        if ($r === false) {
            $response['Status'] = InvalidParameter();
        } elseif ($r->size() > 0) {
            while ($r->next()) {
                $response['Classes'][$r->class_id] = array(
                        'ClassReconciliationId' => $r->class_reconciliation_id,
                        'Reconciled' => $r->reconciled,
                        'ClassId' => $r->class_id,
                        'ClassName' => $r->class_name,
                        'Weeks' => $r->weeks,
                        'Attendances' => $r->attendances,
                        'Credits' => $r->credits
                    );
            }
            $response['Status'] = Success();
        } else {
            $response['Status'] = Success();
        }
        return Ork3::$Lib->ghettocache->cache('Player.GetPlayerClasses', $ck, $response);
    }

    public function unique_username($username, $calls = 0)
    {
        if ($calls == 0) {
            return false;
        }
        $srcname = $username;
        $found = false;
        while (!$found && $calls > 0) {
            $this->mundane->clear();
            $this->mundane->username = $username;
            if ($this->mundane->find()) {
                $username = $srcname . '-' . substr(md5(microtime()), 0, 5);
            } else {
                $found = true;
            }
            $calls--;
        }
        return $username;
    }

    public function CreatePlayer($request)
    {
        if (strlen($request['UserName']) < 4) {
            return InvalidParameter('UserNames must be at least 4 characters long.');
        }

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_CREATE)) {
            $park = new yapo($this->db, DB_PREFIX . 'park');
            $park->clear();
            $park->park_id = $request['ParkId'];
            if ($park->find()) {
                error_log('ORK_DEBUG Player->CreatePlayer: ' . json_encode($request));
                $username = $this->unique_username(trim($request['UserName']), 4);
                if ($username === false) {
                    return InvalidParameter('No UserName could be generated for this player.  Please try again.');
                }
                $request['UserName'] = $username;
                $this->mundane->clear();
                $this->mundane->given_name = $request['GivenName'];
                $this->mundane->surname = $request['Surname'];
                $this->mundane->other_name = $request['OtherName'];
                $this->mundane->username = trim($request['UserName']);
                $this->mundane->persona = trim($request['Persona']);
                $this->mundane->email = $request['Email'];
                $this->mundane->park_id = $request['ParkId'];
                $this->mundane->kingdom_id = $park->kingdom_id;
                $this->mundane->modified = date('Y-m-d H:i:s', time());
                $this->mundane->restricted = $request['Restricted'] ? 1 : 0;
                $this->mundane->waivered = $request['Waivered'] ? 1 : 0;
                $this->mundane->has_image = $request['HasImage'] ? 1 : 0;
                if (!empty($request['PronounId'])) {
                    $this->mundane->pronoun_id     = (int)$request['PronounId'];
                }
                if (!empty($request['PronounCustom'])) {
                    $this->mundane->pronoun_custom = $request['PronounCustom'];
                }
                $this->mundane->penalty_box = 0;
                $this->mundane->active = $request['IsActive'];
                $this->mundane->password_expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 365);
                $this->mundane->password_salt = md5(rand().microtime());
                $this->mundane->park_member_since = date('Y-m-d');
                $this->mundane->token                = md5(uniqid(rand(), true));
                $this->mundane->xtoken               = md5(uniqid(rand(), true));
                $this->mundane->waiver_ext           = '';
                $this->mundane->reeve_qualified_until = '0000-00-00';
                $this->mundane->save();
                $new_mundane_id = (int)$this->mundane->mundane_id;

                // Paired design-preferences row (one per mundane, all schema defaults at creation).
                $design = new yapo($this->db, DB_PREFIX . 'mundane_design');
                $design->clear();
                $design->mundane_id = $new_mundane_id;
                $design->save();

                Authorization::SaltPassword($this->mundane->password_salt, strtoupper(trim($this->mundane->username)) . trim($request['Password']), $this->mundane->password_expires);

                if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::supported_mime_types($request['WaiverMimeType']) && !Common::is_pdf_mime_type($request['WaiverMimeType'])) {
                    $waiver = @imagecreatefromstring(base64_decode($request['Waiver']));
                    if ($waiver !== false) {
                        $base = DIR_WAIVERS . sprintf("%06d", $this->mundane->mundane_id);
                        $use_png = Common::gd_has_transparency($waiver);

                        if (file_exists($base . '.jpg')) {
                            unlink($base . '.jpg');
                        }
                        if (file_exists($base . '.png')) {
                            unlink($base . '.png');
                        }

                        if ($use_png) {
                            imagealphablending($waiver, false);
                            imagesavealpha($waiver, true);
                            imagepng($waiver, $base . '.png');
                            $this->mundane->waiver_ext = 'png';
                        } else {
                            imagejpeg($waiver, $base . '.jpg');
                            $this->mundane->waiver_ext = 'jpg';
                        }
                        $this->mundane->waivered = 1;
                    } else {
                        $this->mundane->saivered = 0;
                    }
                } elseif ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::is_pdf_mime_type($request['WaiverMimeType'])) {
                    $waiver = @base64_decode($request['Waiver']);
                    if ($waiver !== false) {
                        file_put_contents(DIR_WAIVERS.(sprintf("%06d", $this->mundane->mundane_id)).'.pdf', $waiver, LOCK_EX);
                        $this->mundane->waivered = 1;
                        $this->mundane->waiver_ext = 'pdf';
                    }
                }
                if ($request['HasImage'] && strlen($request['Image']) > 0 && strlen($request['Image']) < 1365334 && Common::supported_mime_types($request['ImageMimeType']) && !Common::is_pdf_mime_type($request['ImageMimeType'])) {
                    $playerimage = @imagecreatefromstring(base64_decode($request['Image']));
                    if ($playerimage !== false) {
                        $base = DIR_PLAYER_IMAGE . sprintf("%06d", $this->mundane->mundane_id);
                        $use_png = Common::gd_has_transparency($playerimage);

                        if (file_exists($base . '.jpg')) {
                            unlink($base . '.jpg');
                        }
                        if (file_exists($base . '.png')) {
                            unlink($base . '.png');
                        }

                        if ($use_png) {
                            imagealphablending($playerimage, false);
                            imagesavealpha($playerimage, true);
                            imagepng($playerimage, $base . '.png');
                        } else {
                            imagejpeg($playerimage, $base . '.jpg');
                        }
                        $this->mundane->has_image = 1;
                    } else {
                        $this->mundane->has_image = 0;
                    }
                } else {
                    $this->mundane->has_image = 0;
                }
                $this->mundane->save();
                if (strlen($request['Heraldry'])) {
                    $request['MundaneId'] = $new_mundane_id;
                    Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
                }

                // Audit: record who created which player. Captures officer/admin
                // signup-fuzz attempts that previously had no audit trail.
                $post_player = $this->GetPlayer(['MundaneId' => $new_mundane_id]);
                $_audit_req = $request;
                $_audit_req['PasswordChanged'] = trimlen($request['Password'] ?? '') > 0 ? 1 : 0;
                unset($_audit_req['Password']);
                list($_audit_req, , $_audit_post) =
                    $this->audit_redact_profile($_audit_req, [], $post_player['Player'] ?? []);
                $_audit_req['AdminEdit']   = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT) ? 1 : 0;
                $_audit_req['OfficerEdit'] = (!$_audit_req['AdminEdit'] && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) ? 1 : 0;
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $_audit_req, 'Player', $new_mundane_id, null, $_audit_post);

                return Success($new_mundane_id);
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function CreateSelfRegLink($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($mundane_id)) {
            return NoAuthorization();
        }
        if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_CREATE)) {
            return NoAuthorization();
        }

        // A13: Reuse unexpired unused token for same park
        global $DB;
        $DB->Clear();
        $park_id = (int)$request['ParkId'];
        $existing = $DB->DataSet("SELECT token, expires_at FROM " . DB_PREFIX . "selfreg_link WHERE park_id = {$park_id} AND used_by IS NULL AND expires_at > NOW() ORDER BY created_at DESC LIMIT 1");
        if ($existing && $existing->Next()) {
            $seconds_remaining = max(0, strtotime($existing->expires_at) - time());
            return Success([
                'token'             => $existing->token,
                'expires_at'        => $existing->expires_at,
                'seconds_remaining' => $seconds_remaining,
            ]);
        }

        $token      = bin2hex(random_bytes(24));
        $expires_at = date('Y-m-d H:i:s', time() + 15 * 60);

        $this->selfreg_link->clear();
        $this->selfreg_link->token      = $token;
        $this->selfreg_link->park_id    = $park_id;
        $this->selfreg_link->created_by = $mundane_id;
        $this->selfreg_link->created_at = date('Y-m-d H:i:s');
        $this->selfreg_link->expires_at = $expires_at;
        $this->selfreg_link->save();

        // A12: Verify selfreg_id after save
        if (!$this->selfreg_link->selfreg_id) {
            return InvalidParameter('Could not create self-registration link.');
        }

        return Success([
            'token'             => $token,
            'expires_at'        => $expires_at,
            'seconds_remaining' => 15 * 60,
        ]);
    }

    public function ValidateSelfRegLink($request)
    {
        $token = preg_replace('/[^a-f0-9]/', '', (string)($request['SelfRegToken'] ?? ''));
        if (strlen($token) !== 48) {
            return InvalidParameter('Invalid registration link.');
        }

        $this->selfreg_link->clear();
        $this->selfreg_link->token = $token;
        if (!$this->selfreg_link->find()) {
            return InvalidParameter('Link not found.');
        }

        // A11: Loose NULL check for used_by (yapo may return '' for DB NULL)
        if (!empty($this->selfreg_link->used_by) && (int)$this->selfreg_link->used_by > 0) {
            return InvalidParameter('This registration link has already been used.');
        }

        if (strtotime($this->selfreg_link->expires_at) <= time()) {
            return InvalidParameter('This registration link has expired.');
        }

        return Success([
            'selfreg_id' => (int)$this->selfreg_link->selfreg_id,
            'park_id'    => (int)$this->selfreg_link->park_id,
            'expires_at' => $this->selfreg_link->expires_at,
        ]);
    }

    public function SelfRegister($request)
    {
        // A8: Transactional locking — do NOT delegate to ValidateSelfRegLink
        $token = preg_replace('/[^a-f0-9]/', '', (string)($request['SelfRegToken'] ?? ''));

        // B10: Build a sanitized audit payload (no password, capture IP and
        // the token + email actually attempted) used by both success and
        // failure paths below. selfreg is a public, unauthenticated surface
        // so every attempt — good or bad — should leave a trail.
        $_selfreg_audit = [
            'SelfRegToken' => $token,
            'Email'        => trim($request['Email'] ?? ''),
            'Persona'      => trim($request['Persona'] ?? ''),
            'UserName'     => trim($request['UserName'] ?? ''),
            'RemoteAddr'   => $_SERVER['REMOTE_ADDR'] ?? '',
        ];
        $_selfreg_fail = function ($reason) use ($_selfreg_audit) {
            $payload = $_selfreg_audit;
            $payload['Result'] = 'failure';
            $payload['Reason'] = $reason;
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::SelfRegister", $payload, 'Player', 0, null);
            return InvalidParameter($reason);
        };

        if (strlen($token) !== 48) {
            return $_selfreg_fail('Invalid registration link.');
        }

        if (strlen(trim($request['Persona'] ?? '')) < 1) {
            return $_selfreg_fail('Persona is required.');
        }
        if (strlen(trim($request['Email'] ?? '')) < 1) {
            return $_selfreg_fail('Email is required.');
        }
        if (!filter_var(trim($request['Email']), FILTER_VALIDATE_EMAIL)) {
            return $_selfreg_fail('Please enter a valid email address.');
        }
        if (strlen(trim($request['UserName'] ?? '')) < 4) {
            return $_selfreg_fail('Username must be at least 4 characters.');
        }
        // Defense in depth: controller.SelfReg.php also enforces this; service layer
        // enforces it again so direct orkservice POSTs cannot bypass the minimum.
        if (strlen($request['Password'] ?? '') < 8) {
            return $_selfreg_fail('Password must be at least 8 characters.');
        }

        global $DB;

        // A8: START TRANSACTION with FOR UPDATE locking
        $DB->Clear();
        $DB->Execute('START TRANSACTION');

        $DB->Clear();
        $DB->token = $token;
        $row = $DB->DataSet("SELECT selfreg_id, park_id, used_by, expires_at FROM " . DB_PREFIX . "selfreg_link WHERE token = :token FOR UPDATE");
        if (!$row || !$row->Next()) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('Link not found.');
        }

        $selfreg_id = (int)$row->selfreg_id;
        $park_id    = (int)$row->park_id;

        // A11: Loose NULL check
        if (!empty($row->used_by) && (int)$row->used_by > 0) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('This registration link has already been used.');
        }
        if (strtotime($row->expires_at) <= time()) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('This registration link has expired.');
        }

        // A14: Duplicate email check
        $email = trim($request['Email']);
        $DB->Clear();
        $DB->email = $email;
        $emailCheck = $DB->DataSet("SELECT mundane_id, persona FROM " . DB_PREFIX . "mundane WHERE email = :email LIMIT 1");
        if ($emailCheck && $emailCheck->Next()) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('An account with this email already exists. Please sign in instead, or use a different email address.');
        }

        // Look up park for kingdom_id
        $park = new yapo($this->db, DB_PREFIX . 'park');
        $park->clear();
        $park->park_id = $park_id;
        if (!$park->find()) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('Park not found.');
        }
        $kingdom_id = (int)$park->kingdom_id;
        $park_name  = $park->name;

        // Username uniqueness
        $username = $this->unique_username(trim($request['UserName']), 4);
        if ($username === false) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('No username could be generated. Please try again.');
        }

        // Create mundane record (mirrors CreatePlayer lines 536-560)
        $this->mundane->clear();
        $this->mundane->given_name        = trim($request['GivenName'] ?? '');
        $this->mundane->surname           = trim($request['Surname'] ?? '');
        $this->mundane->other_name        = '';
        $this->mundane->username          = $username;
        $this->mundane->persona           = trim($request['Persona']);
        $this->mundane->email             = $email;
        $this->mundane->park_id           = $park_id;
        $this->mundane->kingdom_id        = $kingdom_id;
        $this->mundane->modified          = date('Y-m-d H:i:s', time());
        $this->mundane->restricted        = 0;
        $this->mundane->waivered          = 0;
        $this->mundane->has_image         = 0;
        $this->mundane->penalty_box       = 0;
        $this->mundane->active            = 1;
        $this->mundane->password_expires  = date('Y-m-d H:i:s', time() + 60 * 60 * 24 * 365);
        $this->mundane->password_salt     = md5(rand() . microtime());
        $this->mundane->park_member_since = date('Y-m-d');
        $this->mundane->token             = md5(uniqid(rand(), true));
        $this->mundane->xtoken            = md5(uniqid(rand(), true));
        $this->mundane->waiver_ext        = '';
        $this->mundane->reeve_qualified_until = '0000-00-00';
        $this->mundane->save();

        $new_mundane_id = (int)$this->mundane->mundane_id;
        if (!$new_mundane_id) {
            $DB->Clear();
            $DB->Execute('ROLLBACK');
            return $_selfreg_fail('Could not create account. Please try again.');
        }

        // Hash password
        Authorization::SaltPassword($this->mundane->password_salt, strtoupper($username) . trim($request['Password']), $this->mundane->password_expires);

        // Mark token as used (A8: with AND used_by IS NULL for safety)
        $now = date('Y-m-d H:i:s');
        $DB->Clear();
        $DB->Execute("UPDATE " . DB_PREFIX . "selfreg_link SET used_by = {$new_mundane_id}, used_at = '{$now}' WHERE selfreg_id = {$selfreg_id} AND used_by IS NULL");

        // Add Color attendance credit for date of registration
        $today = date('Y-m-d');
        $DB->Clear();
        // Self-registration on first-ever signup: the new player gets one
        // attendance credit (Peasant class) as a welcome. entry_method tags
        // the row so reports don't confusingly render "Augustus entered
        // Augustus's first credit" — instead they show "Self-registration".
        $DB->Execute("INSERT INTO " . DB_PREFIX . "attendance (mundane_id, class_id, date, date_year, date_month, date_week3, date_week6, park_id, kingdom_id, event_id, event_calendardetail_id, credits, persona, flavor, note, by_whom_id, entered_at, entry_method) VALUES (" . $new_mundane_id . ", 6, '" . $today . "', YEAR('" . $today . "'), MONTH('" . $today . "'), WEEK('" . $today . "', 3), WEEK('" . $today . "', 6), " . $park_id . ", " . $kingdom_id . ", 0, 0, 1.00, '" . addslashes(trim($request['Persona'])) . "', '', 'Self-registration', " . $new_mundane_id . ", '" . $now . "', 'self_reg')");

        // COMMIT transaction
        $DB->Clear();
        $DB->Execute('COMMIT');

        // Auto-login: generate session token
        $this->mundane->token = md5(openssl_random_pseudo_bytes(16) . microtime());
        $this->mundane->token_expires = date('Y:m:d H:i:s', time() + LOGIN_TIMEOUT);
        $this->mundane->save();

        // A9: Look up kingdom name for session context
        $kingdom_name = '';
        $DB->Clear();
        $knRow = $DB->DataSet("SELECT name FROM " . DB_PREFIX . "kingdom WHERE kingdom_id = {$kingdom_id} LIMIT 1");
        if ($knRow && $knRow->Next()) {
            $kingdom_name = $knRow->name;
        }

        // B10: success audit — capture token, IP, and resulting mundane_id.
        $_selfreg_success = $_selfreg_audit;
        $_selfreg_success['Result']    = 'success';
        $_selfreg_success['ParkId']    = $park_id;
        $_selfreg_success['KingdomId'] = $kingdom_id;
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::SelfRegister", $_selfreg_success, 'Player', $new_mundane_id, null);

        return Success([
            'mundane_id'   => $new_mundane_id,
            'token'        => $this->mundane->token,
            'username'     => $this->mundane->username,
            'park_id'      => $park_id,
            'park_name'    => $park_name,
            'kingdom_id'   => $kingdom_id,
            'kingdom_name' => $kingdom_name,
        ]);
    }


    public function hydrated_players($ids)
    {
        $sql = "select k.name as kingdom, k.kingdom_id, p.name as park, p.park_id, m.mundane_id, m.persona 
              from " . DB_PREFIX . "mundane m
                left join " . DB_PREFIX . "park p on m.park_id = p.park_id
                left join " . DB_PREFIX . "kingdom k on m.kingdom_id = k.kingdom_id
              where m.mundane_id in (" . implode(",", $ids) . ")";

        $r = $this->db->query($sql);

        $response = array();
        if ($r !== false && $r->size() > 0) {
            $response = array();
            while ($r->next()) {
                $response[$r->mundane_id] = array(
                    'KingdomId' => $r->kingdom_id,
                    'Kingdom' => $r->kingdom,
                    'ParkId' => $r->park_id,
                    'Park' => $r->park,
                    'MundaneId' => $r->mundane_id,
                    'Persona' => $r->persona,
                    'id' => $r->mundane_id
                );
            }
        }
        return $response;
    }

    public function player_info($id)
    {
        if (strlen($id) == 32) {
            $id = Ork3::$Lib->authorization->IsAuthorized($id);
        }
        $this->mundane->clear();
        $this->mundane->mundane_id = $id;
        if (!$this->mundane->find()) {
            return false;
        } else {
            return array(
                'id' => $this->mundane->mundane_id, 'park_id' => $this->mundane->park_id, 'kingdom_id' => $this->mundane->kingdom_id,
                'MundaneId' => $this->mundane->mundane_id, 'ParkId' => $this->mundane->park_id, 'KingdomId' => $this->mundane->kingdom_id,
                'Surname' => $this->mundane->surname, 'GivenName' => $this->mundane->given_name, 'PasswordExpires' => $this->mundane->password_expires,
        'Persona' => $this->mundane->persona
                );
        }
    }

    // Bust caches affected by a change to this player's recommendation data:
    //   - Report.PlayerAwardRecommendations under the three scopes (player,
    //     kingdom, park) that could hold this player's row.
    //   - Model_Player.fetch_player_details — recommendation/second changes
    //     show up on the player's awards tab and the 60-min cache there
    //     needs invalidating.
    // Pass kingdom_id/park_id when the caller already knows them (e.g.
    // merge/delete after the row is gone); otherwise we look them up.
    private function bust_player_award_recs_cache($mundane_id, $kingdom_id = null, $park_id = null)
    {
        if (!valid_id($mundane_id)) {
            return;
        }
        if ($kingdom_id === null || $park_id === null) {
            $info = $this->player_info($mundane_id);
            if (!$info) {
                return;
            }
            if ($kingdom_id === null) {
                $kingdom_id = (int)($info['KingdomId'] ?? 0);
            }
            if ($park_id    === null) {
                $park_id    = (int)($info['ParkId']    ?? 0);
            }
        }
        $kid = (int)$kingdom_id;
        $pid = (int)$park_id;
        $mid = (int)$mundane_id;
        $keys = [['KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $mid]];
        if ($kid > 0) {
            $keys[] = ['KingdomId' => $kid, 'ParkId' => 0,    'PlayerId' => 0];
        }
        if ($pid > 0) {
            $keys[] = ['KingdomId' => 0,    'ParkId' => $pid, 'PlayerId' => 0];
        }
        foreach ($keys as $kd) {
            Ork3::$Lib->ghettocache->bust(
                'Report.PlayerAwardRecommendations',
                Ork3::$Lib->ghettocache->key($kd)
            );
        }
        Ork3::$Lib->ghettocache->bust(
            'Model_Player.fetch_player_details',
            Ork3::$Lib->ghettocache->key(['MundaneId' => $mid])
        );
    }

    public function MergePlayer($request)
    {

        if ((($fromMundane = $this->player_info($request['FromMundaneId'])) === false)
                || (($toMundane = $this->player_info($request['ToMundaneId'])) === false)
                || $request['FromMundaneId'] == $request['ToMundaneId']) {
            return InvalidParameter();
        }

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (
            (($toMundane['KingdomId'] != $fromMundane['KingdomId'])
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT))
            || (($toMundane['ParkId'] != $fromMundane['ParkId'] && $toMundane['KingdomId'] == $fromMundane['KingdomId'])
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $toMundane['KingdomId'], AUTH_EDIT))
            || (($toMundane['ParkId'] == $fromMundane['ParkId'])
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $toMundane['ParkId'], AUTH_EDIT))) {

            $from_player = $this->GetPlayer(array('MundaneId' => $request['FromMundaneId']));
            $to_player = $this->GetPlayer(array('MundaneId' => $request['ToMundaneId']));

            if ($from_player['Status']['Status'] != 0 || $to_player['Status']['Status'] != 0) {
                return InvalidParameter("One of the players could not be found.");
            }

            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['FromMundaneId'], $from_player['Player'], $to_player['Player']);

            $sql = "DELETE FROM
						" . DB_PREFIX . "attendance
					WHERE
						mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'
						AND date in (SELECT date FROM
									(select distinct date from " . DB_PREFIX . "attendance
										WHERE
											mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "') as d)";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."attendance set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."authorization set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."event set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "delete from " . DB_PREFIX ."mundane where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."officer set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."awards set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."awards set given_by_id = '" . mysql_real_escape_string($toMundane['id']) . "' where given_by_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."split set src_mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where src_mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."transaction set recorded_by = '" . mysql_real_escape_string($toMundane['id']) . "' where recorded_by = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."unit set owner_id = '" . mysql_real_escape_string($toMundane['id']) . "' where owner_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."unit_mundane set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "update " . DB_PREFIX ."mundane_note set mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' where mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "DELETE FROM " . DB_PREFIX . "event_rsvp
					WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'
					AND event_calendardetail_id IN (
						SELECT event_calendardetail_id FROM (
							SELECT event_calendardetail_id FROM " . DB_PREFIX . "event_rsvp
							WHERE mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "'
						) AS existing
					)";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "event_rsvp SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // class_reconciliation: unique key on (class_id, mundane_id) — deduplicate first
            $sql = "DELETE FROM " . DB_PREFIX . "class_reconciliation
					WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'
					AND class_id IN (
						SELECT class_id FROM (
							SELECT class_id FROM " . DB_PREFIX . "class_reconciliation
							WHERE mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "'
						) AS existing
					)";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "class_reconciliation SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // whats_new_seen: unique key on (mundane_id, version) — deduplicate first
            $sql = "DELETE FROM " . DB_PREFIX . "whats_new_seen
					WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'
					AND version IN (
						SELECT version FROM (
							SELECT version FROM " . DB_PREFIX . "whats_new_seen
							WHERE mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "'
						) AS existing
					)";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "whats_new_seen SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // Simple transfers
            $sql = "UPDATE " . DB_PREFIX . "recommendations SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "recommendations SET recommended_by_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE recommended_by_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // recommendation_seconds: unique key on (recommendations_id, supporter_mundane_id) — soft-delete from-rows that would collide before remapping.
            $sql = "UPDATE " . DB_PREFIX . "recommendation_seconds fr
				JOIN " . DB_PREFIX . "recommendation_seconds toR
					ON toR.recommendations_id = fr.recommendations_id
					AND toR.supporter_mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "'
				SET fr.deleted_at = NOW(), fr.deleted_by = '" . mysql_real_escape_string($toMundane['id']) . "'
				WHERE fr.supporter_mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "' AND fr.deleted_at IS NULL";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "recommendation_seconds SET supporter_mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE supporter_mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "' AND deleted_at IS NULL";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "dues SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "bracket_officiant SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "participant_mundane SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "game SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            $sql = "UPDATE " . DB_PREFIX . "application SET mundane_id = '" . mysql_real_escape_string($toMundane['id']) . "' WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // idp_auth: delete FROM player's IDP link — TO player keeps their login
            $sql = "DELETE FROM " . DB_PREFIX . "idp_auth WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // mundane_design: TO player keeps its own design; drop the FROM player's orphaned row.
            $sql = "DELETE FROM " . DB_PREFIX . "mundane_design WHERE mundane_id = '" . mysql_real_escape_string($fromMundane['id']) . "'";
            $this->db->query($sql);
            // Bust the merged-into player's class cache; the source row is gone.
            $_ck = Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$toMundane['id']]);
            Ork3::$Lib->ghettocache->bust('Player.GetPlayerClasses', $_ck);
            // Recommendations were re-pointed to the destination — bust both
            // players' Report.PlayerAwardRecommendations cache scopes.
            $this->bust_player_award_recs_cache((int)$fromMundane['id'], (int)$fromMundane['kingdom_id'], (int)$fromMundane['park_id']);
            $this->bust_player_award_recs_cache((int)$toMundane['id'], (int)$toMundane['kingdom_id'], (int)$toMundane['park_id']);
            return Success();
        } else {
            return NoAuthorization();
        }

    }

    public function MovePlayer($request)
    {

        $player = $this->GetPlayer(array('MundaneId' => $request['MundaneId']));

        $this->mundane->clear();
        $this->mundane->mundane_id = $request['MundaneId'];
        $park = new yapo($this->db, DB_PREFIX . 'park');
        $park->clear();
        $park->park_id = $request['ParkId'];
        if (!$this->mundane->find() || !$park->find()) {
            return InvalidParameter();
        }

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $park->park_id, AUTH_EDIT)		// New Kingdom
                    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT))) { // Current Kingdom

            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $player['Player']);

            // Capture the old kingdom/park before the save so we can bust both
            // the source and destination Report.PlayerAwardRecommendations caches.
            $_oldKid = (int)$this->mundane->kingdom_id;
            $_oldPid = (int)$this->mundane->park_id;

            $this->mundane->park_id = $request['ParkId'];
            $this->mundane->kingdom_id = $park->kingdom_id;
            $this->mundane->park_member_since = date('Y-m-d');
            $this->mundane->waivered = $request['Waivered'] ? 1 : 0;
            $this->mundane->save();
            $this->bust_player_award_recs_cache((int)$request['MundaneId'], $_oldKid, $_oldPid);
            $this->bust_player_award_recs_cache((int)$request['MundaneId'], (int)$park->kingdom_id, (int)$park->park_id);
            error_log('ORK_DEBUG MovePlayer(): Success: ' . json_encode($request));
            return Success();
        } else {
            return NoAuthorization();
        }
    }

    public function _ClearSuspensions()
    {
        $sql = "update " . DB_PREFIX . "mundane set suspended = 0, suspended_by_id = null, suspended_at = null, suspended_until = null, suspension = null, suspension_propagates = 1 where suspended_until < curdate() and suspended_until is not null and suspended_until != '0000-00-00'";
        $this->db->query($sql);
    }

    public function SetPlayerSuspension($request)
    {
        $this->mundane->clear();
        $this->mundane->mundane_id = $request['MundaneId'];
        if (!$this->mundane->find()) {
            return InvalidParameter();
        }

        $this->_ClearSuspensions();

        if ($request['MundaneId'] == 1) {
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], null);
            return InvalidParameter('No thanks. This has been logged.');
        }

        if (!isset($request['Suspended'])) {
            return InvalidParameter('You must choose a suspension state: ' . print_r($request, 1));
        }

        $prior_suspension = [
            'Suspended'            => (int)$this->mundane->suspended,
            'SuspendedById'        => $this->mundane->suspended_by_id,
            'SuspendedUntil'       => $this->mundane->suspended_until,
            'Suspension'           => $this->mundane->suspension,
            'SuspensionPropagates' => (int)$this->mundane->suspension_propagates,
        ];

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, $this->mundane->kingdom_id, AUTH_EDIT)
                    || Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_ADMIN))) {
            $this->mundane->suspended = $request['Suspended'];
            if (!$request['Suspended']) {
                $mid_safe = (int)$this->mundane->mundane_id;
                $this->db->query("UPDATE " . DB_PREFIX . "mundane SET suspended = 0, suspended_by_id = NULL, suspended_at = NULL, suspended_until = NULL, suspension = NULL, suspension_propagates = 1 WHERE mundane_id = {$mid_safe}");
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $prior_suspension);
                return;
            } else {
                $this->mundane->suspended_by_id = $request['SuspendedById'];
                $this->mundane->suspended_at = $request['SuspendedAt'];
                if (isset($request['SuspendedUntil'])) {
                    $this->mundane->suspended_until = $request['SuspendedUntil'];
                }
                if (isset($request['Suspension'])) {
                    $this->mundane->suspension = $request['Suspension'];
                }
                $this->mundane->suspension_propagates = isset($request['SuspensionPropagates']) ? (int)(bool)$request['SuspensionPropagates'] : 1;
            }
            $this->mundane->save();
            $post_suspension = [
                'Suspended'            => (int)$this->mundane->suspended,
                'SuspendedById'        => $this->mundane->suspended_by_id,
                'SuspendedUntil'       => $this->mundane->suspended_until,
                'Suspension'           => $this->mundane->suspension,
                'SuspensionPropagates' => (int)$this->mundane->suspension_propagates,
            ];
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['MundaneId'], $prior_suspension, $post_suspension);
        } else {
            return NoAuthorization();
        }
    }

    public function load_model($name)
    {
        if (file_exists(DIR_MODEL . 'model.' . $name . '.php')) {
            require_once(DIR_MODEL . 'model.' . $name . '.php');
            $model_name = 'Model_' . $name;
            $this->$name = new $model_name();
        }
    }

    // Trim large/blob fields out of the audit payload so danger_audit rows stay
    // queryable. Body content lives in ork_mundane_design — the audit only needs
    // to record that it changed, by whom, for whom.
    private function audit_redact_profile($request, $prior, $post)
    {
        $LARGE_TEXT = ['AboutPersona', 'AboutStory', 'MilestoneConfig'];
        $BLOB       = ['Image', 'Waiver', 'Heraldry'];

        foreach ($LARGE_TEXT as $f) {
            if (array_key_exists($f, $request) && !is_null($request[$f])) {
                $request[$f] = ['changed' => true, 'len' => strlen((string)$request[$f])];
            }
            if (isset($prior[$f])) {
                $prior[$f] = ['len' => strlen((string)$prior[$f])];
            }
            if (isset($post[$f])) {
                $post[$f]  = ['len' => strlen((string)$post[$f])];
            }
        }
        foreach ($BLOB as $f) {
            if (!empty($request[$f])) {
                $mime = $request[$f.'MimeType'] ?? null;
                $request[$f] = ['uploaded' => true, 'bytes' => strlen((string)$request[$f]), 'mime' => $mime];
            }
        }
        return [$request, $prior, $post];
    }

    // Decide whether an UpdatePlayer call is worth an audit row. File uploads
    // and password changes always count. Otherwise, walk the prior→post diff
    // and only audit when something non-cosmetic changed. Tweaking colours,
    // photo focus, name prefix/suffix, etc. should not produce an audit entry.
    private function audit_should_log($request, $prior, $post)
    {
        if (!empty($request['PasswordChanged'])) {
            return true;
        }
        // Raw request: blob fields are still base64 strings here (redaction
        // runs only on the path that actually writes the audit row).
        foreach (['Image', 'Waiver', 'Heraldry'] as $f) {
            if (!empty($request[$f])) {
                return true;
            }
        }

        // Cosmetic profile-design fields — changes here are not audit-worthy.
        // Name-builder fields, pronunciation guide, and privacy toggles are
        // intentionally NOT cosmetic: they affect identity presentation and
        // what's exposed publicly, so changes there should land in the log.
        $cosmetic = [
            'AboutPersona' => 1, 'AboutStory' => 1, 'MilestoneConfig' => 1,
            'ColorPrimary' => 1, 'ColorAccent' => 1, 'ColorSecondary' => 1, 'HeroGradient' => 1, 'HeroOverlay' => 1,
            'NameFont' => 1,
            'PhotoFocusX' => 1, 'PhotoFocusY' => 1, 'PhotoFocusSize' => 1,
            'ShowBeltline' => 1, 'ShowFeastPrefs' => 1, 'BeltDisplay' => 1,
            'BasicFonts' => 1, 'DyslexiaFonts' => 1,
        ];
        // Fields whose value always shifts even on no-op saves — ignore.
        $ignore = [
            'Heraldry' => 1, 'Image' => 1, // URLs carry ?modified cache-buster
            'Waiver' => 1,
            'DuesThrough' => 1, 'LastDuesThrough' => 1, 'DuesPaidList' => 1, // dues path has its own audits
        ];

        $keys = array_unique(array_merge(array_keys((array)$prior), array_keys((array)$post)));
        foreach ($keys as $k) {
            if (isset($ignore[$k]) || isset($cosmetic[$k])) {
                continue;
            }
            $a = $prior[$k] ?? null;
            $b = $post[$k]  ?? null;
            if ($a != $b) {
                return true;
            }
        }
        return false;
    }

    public function UpdatePlayer($request)
    {
        logtrace("UpdatePlayer()", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

        if ($request['RemoveDues'] === "Revoke Dues") {
            // No way to reliably gap revoke for the Dues transition. mORK will need to update their code
            return NoAuthorization('Outdated Request Method.');

            $this->load_model('Treasury');
            return $this->Treasury->RemoveLastDuesPaid(array(
                'MundaneId' => $request['MundaneId'],
                'Token' => $request['Token']
            ));
        }

        if (trimlen($request['UserName']) > 0) {
            $this->mundane->clear();
            $this->mundane->username = $request['UserName'];
            if ($this->mundane->find()) {
                if ($this->mundane->mundane_id != $request['MundaneId']) {
                    return InvalidParameter('This username is already in use.');
                }
            }
        }

        $notices = '';
        if (valid_id($requester_id) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
            || $requester_id == $request['MundaneId']) {

            if (Ork3::$Lib->authorization->HasAuthority($request['MundaneId'], AUTH_ADMIN, 0, AUTH_EDIT)
                && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
                die("You have attempted an illegal operation.  Only an Admin may update an Admin. Your attempt has been logged.");
            }

            $player = $this->GetPlayer(array('MundaneId' => $request['MundaneId']));

            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                error_log('ORK_DEBUG Updating player: ' . json_encode($request));

                $this->mundane->modified = date('Y-m-d H:i:s', time());
                $this->mundane->given_name = is_null($request['GivenName']) ? $this->mundane->given_name : $request['GivenName'];
                $this->mundane->surname = is_null($request['Surname']) ? $this->mundane->surname : $request['Surname'];
                $this->mundane->other_name = is_null($request['OtherName']) ? $this->mundane->other_name : $request['OtherName'];
                $this->mundane->username = is_null($request['UserName']) ? $this->mundane->username : $request['UserName'];
                // Profanity check on persona (display name) before save.
                if (!is_null($request['Persona']) && trim($request['Persona']) !== '') {
                    require_once(__DIR__ . '/class.ProfanityFilter.php');
                    $pf = new ProfanityFilter();
                    if ($pf->containsProfanity($request['Persona'])) {
                        return InvalidParameter('Persona', ProfanityFilter::ERROR_MESSAGE);
                    }
                }
                $this->mundane->persona = is_null($request['Persona']) ? $this->mundane->persona : trim($request['Persona']);
                $this->mundane->pronoun_id = is_null($request['PronounId']) ? $this->mundane->pronoun_id : $request['PronounId'];
                $this->mundane->pronoun_custom = is_null($request['PronounCustom']) ? $this->mundane->pronoun_custom : $request['PronounCustom'];

                // Profile customization fields — own profile or ORK admin.
                // Stored in ork_mundane_design (paired 1:1 row) since 2026-04-23.
                $design = null;
                $_canEditDesign = ($requester_id == $request['MundaneId'])
                    || Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, null, null);
                if ($_canEditDesign) {
                    $design = new yapo($this->db, DB_PREFIX . 'mundane_design');
                    $design->clear();
                    $design->mundane_id = $this->mundane->mundane_id;
                    $_designExisted = $design->find() > 0;

                    // Snapshot current values so we can preserve them when the request
                    // omits a field. After clear(), $design->X would throw because the
                    // record_set is gone — capture before clearing.
                    $_cur = [];
                    if ($_designExisted) {
                        foreach (['about_persona','about_story','color_primary','color_accent','color_secondary',
                                  'hero_gradient','hero_overlay','name_prefix','name_suffix','suffix_comma',
                                  'photo_focus_x','photo_focus_y','photo_focus_size',
                                  'show_beltline','show_feast_prefs','belt_display','pronunciation_guide',
                                  'show_mundane_first','show_mundane_last','show_email',
                                  'milestone_config','name_font','name_shadow'] as $_f) {
                            $_cur[$_f] = $design->{$_f};
                        }
                    }

                    // Yapo's save() chooses INSERT vs UPDATE off HasActiveRecord (= record_set
                    // is non-null). A 0-row find() leaves record_set non-null but empty, which
                    // drops save() into the UPDATE branch and silently matches zero rows.
                    // Re-clear and re-set on the !existed path forces the INSERT branch.
                    if (!$_designExisted) {
                        $design->clear();
                        $design->mundane_id = $this->mundane->mundane_id;
                    }

                    $_pick = function ($req_val, $col) use ($_designExisted, $_cur) {
                        if (!is_null($req_val)) {
                            return $req_val;
                        }
                        return $_designExisted ? $_cur[$col] : null;
                    };

                    // Profanity check on free-text profile fields before save.
                    require_once(__DIR__ . '/class.ProfanityFilter.php');
                    $pf = new ProfanityFilter();
                    if (!is_null($request['AboutPersona']) && $pf->containsProfanity($request['AboutPersona'])) {
                        return InvalidParameter('AboutPersona', ProfanityFilter::ERROR_MESSAGE);
                    }
                    if (!is_null($request['AboutStory']) && $pf->containsProfanity($request['AboutStory'])) {
                        return InvalidParameter('AboutStory', ProfanityFilter::ERROR_MESSAGE);
                    }
                    if (!is_null($request['NamePrefix']) && $pf->containsProfanity($request['NamePrefix'])) {
                        return InvalidParameter('NamePrefix', ProfanityFilter::ERROR_MESSAGE);
                    }
                    if (!is_null($request['NameSuffix']) && $pf->containsProfanity($request['NameSuffix'])) {
                        return InvalidParameter('NameSuffix', ProfanityFilter::ERROR_MESSAGE);
                    }
                    if (!is_null($request['PronunciationGuide']) && $pf->containsProfanity($request['PronunciationGuide'])) {
                        return InvalidParameter('PronunciationGuide', ProfanityFilter::ERROR_MESSAGE);
                    }

                    $design->about_persona = $_pick($request['AboutPersona'], 'about_persona');
                    $design->about_story = $_pick($request['AboutStory'], 'about_story');
                    $design->color_primary = $_pick($request['ColorPrimary'], 'color_primary');
                    $design->color_accent = $_pick($request['ColorAccent'], 'color_accent');
                    $design->color_secondary = $_pick($request['ColorSecondary'], 'color_secondary');
                    // HeroGradient is an Amtpride preset key validated against the keys of
                    // system/lib/ork3/pride_gradients.php. Anything else is coerced to '' (NOT
                    // null): yapo's update_base() filters SET fields with isset(), which is false
                    // for null, so assigning null would silently omit the column and leave a stale
                    // pride key in the DB. '' is a valid "no flag" sentinel the template treats as empty.
                    if (array_key_exists('HeroGradient', $request)) {
                        static $_prideKeys = null;
                        if ($_prideKeys === null) {
                            $_prideKeys = array_keys(require __DIR__ . '/pride_gradients.php');
                        }
                        $design->hero_gradient = (is_string($request['HeroGradient']) && in_array($request['HeroGradient'], $_prideKeys, true)) ? $request['HeroGradient'] : '';
                    } else {
                        $design->hero_gradient = $_designExisted ? $_cur['hero_gradient'] : '';
                    }
                    $validOverlays = ['low','med','high','vignette'];
                    $design->hero_overlay = (isset($request['HeroOverlay']) && in_array($request['HeroOverlay'], $validOverlays)) ? $request['HeroOverlay'] : ($_designExisted ? $_cur['hero_overlay'] : 'med');
                    $design->name_prefix = $_pick($request['NamePrefix'], 'name_prefix');
                    $design->name_suffix = $_pick($request['NameSuffix'], 'name_suffix');
                    $design->suffix_comma = is_null($request['SuffixComma']) ? ($_designExisted ? (int)$_cur['suffix_comma'] : 0) : (int)$request['SuffixComma'];
                    $design->photo_focus_x = is_null($request['PhotoFocusX']) ? ($_designExisted ? (int)$_cur['photo_focus_x'] : 50) : (int)$request['PhotoFocusX'];
                    $design->photo_focus_y = is_null($request['PhotoFocusY']) ? ($_designExisted ? (int)$_cur['photo_focus_y'] : 50) : (int)$request['PhotoFocusY'];
                    $design->photo_focus_size = is_null($request['PhotoFocusSize']) ? ($_designExisted ? (int)$_cur['photo_focus_size'] : 100) : (int)$request['PhotoFocusSize'];
                    $design->show_beltline = is_null($request['ShowBeltline']) ? ($_designExisted ? (int)$_cur['show_beltline'] : 1) : (int)$request['ShowBeltline'];
                    // ShowFeastPrefs defaults to 0 (opt-in) since feast prefs carry allergen info.
                    $design->show_feast_prefs = is_null($request['ShowFeastPrefs']) ? ($_designExisted ? (int)$_cur['show_feast_prefs'] : 0) : (int)$request['ShowFeastPrefs'];
                    $design->pronunciation_guide = $_pick($request['PronunciationGuide'], 'pronunciation_guide');
                    $design->show_mundane_first = is_null($request['ShowMundaneFirst']) ? ($_designExisted ? (int)$_cur['show_mundane_first'] : 0) : (int)$request['ShowMundaneFirst'];
                    $design->show_mundane_last = is_null($request['ShowMundaneLast']) ? ($_designExisted ? (int)$_cur['show_mundane_last'] : 0) : (int)$request['ShowMundaneLast'];
                    $design->show_email = is_null($request['ShowEmail']) ? ($_designExisted ? (int)$_cur['show_email'] : 0) : (int)$request['ShowEmail'];
                    $design->milestone_config = $_pick($request['MilestoneConfig'], 'milestone_config');
                    $design->name_font   = $_pick($request['NameFont'], 'name_font');
                    $design->name_shadow = is_null($request['NameShadow'] ?? null) ? ($_designExisted ? (int)$_cur['name_shadow'] : 0) : (int)$request['NameShadow'];
                    $validBeltDisplays = ['white','own','none'];
                    $design->belt_display = (isset($request['BeltDisplay']) && in_array($request['BeltDisplay'], $validBeltDisplays)) ? $request['BeltDisplay'] : ($_designExisted ? $_cur['belt_display'] : 'white');
                }

                // reeve or corpora qual changes
                // TODO: add error messaging
                if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_KINGDOM, $this->mundane->kingdom_id, AUTH_EDIT) || Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT) || Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $this->mundane->park_id, AUTH_EDIT)) {
                    $this->mundane->reeve_qualified = is_null($request['ReeveQualified']) ? $this->mundane->reeve_qualified : $request['ReeveQualified'];
                    $this->mundane->reeve_qualified_until = is_null($request['ReeveQualifiedUntil']) ? $this->mundane->reeve_qualified_until : ($request['ReeveQualifiedUntil'] === '0000-00-00' ? null : $request['ReeveQualifiedUntil']);
                    $this->mundane->corpora_qualified = is_null($request['CorporaQualified']) ? $this->mundane->corpora_qualified : $request['CorporaQualified'];
                    $this->mundane->corpora_qualified_until = is_null($request['CorporaQualifiedUntil']) ? $this->mundane->corpora_qualified_until : ($request['CorporaQualifiedUntil'] === '0000-00-00' ? null : $request['CorporaQualifiedUntil']);
                }

                $this->mundane->save();
                if (array_key_exists('Waivered', $request) && !is_null($request['Waivered'])) {
                    $this->set_waiver($request);
                }
                $this->mundane->save();
                $this->set_image($request);
                if ($design !== null) {
                    $design->save();
                }
                $this->mundane->save();
                logtrace("Mundane DB 1", $this->mundane);
                $this->mundane->email = is_null($request['Email']) ? $this->mundane->email : $request['Email'];
                if (trimlen($request['Password']) > 0) {
                    logtrace("Update password", $request['Password']);
                    $this->mundane->password_expires = date("Y-m-d H:i:s", time() + 60 * 60 * 24 * 365 * 2);
                    $salt = md5(rand().microtime().$this->mundane->email);
                    $this->mundane->password_salt = $salt;

                    Authorization::SaltPassword($salt, strtoupper(trim($this->mundane->username)) . trim($request['Password']), $this->mundane->password_expires);
                } else {
                    logtrace("No password update", $request['Password']);
                }
                logtrace("Mundane DB 2", $this->mundane);
                $this->mundane->restricted = is_null($request['Restricted']) ? $this->mundane->restricted : ($request['Restricted'] ? 1 : 0);
                $this->mundane->basic_fonts = is_null($request['BasicFonts']) ? $this->mundane->basic_fonts : ($request['BasicFonts'] ? 1 : 0);
                $this->mundane->dyslexia_fonts = is_null($request['DyslexiaFonts']) ? $this->mundane->dyslexia_fonts : ($request['DyslexiaFonts'] ? 1 : 0);

                if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
                    $this->mundane->active = is_null($request['Active']) ? $this->mundane->active : ($request['Active'] ? 1 : 0);
                }
                if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
                    $pms = $request['ParkMemberSince'];
                    $this->mundane->park_member_since = is_null($pms) ? $this->mundane->park_member_since : (($pms === '' || $pms === '0000-00-00') ? null : $pms);
                }
                if (strlen($request['Heraldry'])) {
                    Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
                }
                if ($request['DuesDate']) {
                    // Add dues to new system as well until mORK is updated
                    $dues = $this->AddDues([ 'Token' => $request['Token'], 'ParkId' => $mundane['ParkId'], 'MundaneId' => $mundane['MundaneId'], 'KingdomId' => $mundane['KingdomId'], 'DuesFrom' => $request['DuesDate'], 'Terms' => $request['DuesSemesters'] ]);

                    $this->load_model('Treasury');
                    $duespaid = $this->Treasury->DuesPaidToPark(array(
                        'MundaneId' => $request['MundaneId'],
                        'Token' => $request['Token'],
                        'TransactionDate' => $request['DuesDate'],
                        'Semesters' => $request['DuesSemesters']
                    ));
                    if ($duespaid['Status'] > 0) {
                        return InvalidParameter();
                    }
                }
                logtrace("Player Updated", array($request, $this->mundane->lastSql()));
                $this->mundane->save();
                $post_player = $this->GetPlayer(['MundaneId' => $request['MundaneId']]);
                $_audit_req = $request;
                $_audit_req['PasswordChanged'] = trimlen($request['Password'] ?? '') > 0 ? 1 : 0;
                unset($_audit_req['Password']);
                if ($this->audit_should_log($_audit_req, $player['Player'], $post_player['Player'])) {
                    list($_audit_req, $_audit_prior, $_audit_post) =
                        $this->audit_redact_profile($_audit_req, $player['Player'], $post_player['Player']);
                    $_audit_req['SelfEdit']    = ($requester_id == $request['MundaneId']) ? 1 : 0;
                    $_audit_req['AdminEdit']   = (!$_audit_req['SelfEdit'] && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) ? 1 : 0;
                    $_audit_req['OfficerEdit'] = (!$_audit_req['SelfEdit'] && !$_audit_req['AdminEdit'] && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) ? 1 : 0;
                    Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $_audit_req, 'Player', $request['MundaneId'], $_audit_prior, $_audit_post);
                }
                return Success($notices);
            } else {
                error_log('ORK_DEBUG No Player found.: ' . json_encode(null));
                return InvalidParameter();
            }
        } else {
            error_log('ORK_DEBUG No Authorization found.: ' . json_encode(null));
            return NoAuthorization();
        }
    }

    public function RemoveHeraldry($request)
    {
        logtrace("RemoveHeraldry", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $r = Ork3::$Lib->heraldry->RemovePlayerHeraldry($request);
        if (is_array($r) && (int)($r['Status'] ?? 0) === 0) {
            $this->audit_media_remove(__FUNCTION__, $request, 'Heraldry', $requester_id, $mundane);
        }
        return $r;
    }

    public function SetHeraldry($request)
    {
        logtrace("SetHeraldry", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
                || $requester_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $r = Ork3::$Lib->heraldry->SetPlayerHeraldry($request);
                $this->audit_media_upload(__FUNCTION__, $request, 'Heraldry', $requester_id, $mundane);
                return $r;
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    private function media_fetch($prefix, $request)
    {
        logtrace("media_fetch", $request);
        $url = $prefix . 'Url';
        $media = $prefix;
        $mime = $prefix . 'MimeType';
        if (strlen($request[$url]) > 0 && Common::url_exists($request[$url])) {
            $mime_type = Common::exif_to_mime(@exif_imagetype($request[$url]), $request[$url]);
            if (Common::supported_mime_types($mime_type) && Ork3::$Lib->heraldry->url_file_size($request[$url]) < 1365334) {
                $request[$media] = base64_encode(file_get_contents($request[$url]));
                $request[$mime] = $mime_type;
            }
        }
        return $request;
    }

    public function set_image($request)
    {
        logtrace("set_image", $request);
        $request = $this->media_fetch('Image', $request);
        if (strlen($request['Image']) > 0 && strlen($request['Image']) < 1365334 && Common::supported_mime_types($request['ImageMimeType']) && !Common::is_pdf_mime_type($request['ImageMimeType'])) {
            $playerimage = imagecreatefromstring(base64_decode($request['Image']));
            if ($playerimage !== false) {
                $base = DIR_PLAYER_IMAGE . sprintf("%06d", $this->mundane->mundane_id);
                $use_png = Common::gd_has_transparency($playerimage);

                if (file_exists($base . '.jpg')) {
                    unlink($base . '.jpg');
                }
                if (file_exists($base . '.png')) {
                    unlink($base . '.png');
                }

                if ($use_png) {
                    imagealphablending($playerimage, false);
                    imagesavealpha($playerimage, true);
                    imagepng($playerimage, $base . '.png');
                } else {
                    imagejpeg($playerimage, $base . '.jpg');
                }
                $this->mundane->has_image = 1;
            } else {
                $notices .= "Image could not be decoded.";
            }
        } else {
            $notices .= 'Images must be jpeg, gifs, or pngs, and may be no larger than 1MB.<br />';
        }
        logtrace("set_image() complete", array($request, $notices));
        return Success($notices);
    }

    private function resolve_player_image_url($mundane_id, $modified)
    {
        $name = sprintf('%06d', $mundane_id);
        $ext  = file_exists(DIR_PLAYER_IMAGE . $name . '.png') ? 'png' : 'jpg';
        $file = DIR_PLAYER_IMAGE . $name . '.' . $ext;
        // Trust filemtime() over the DB `modified` column: the timestamp
        // always advances on save, whereas a same-second re-upload or
        // no-op UPDATE can leave `modified` untouched and browsers happily
        // keep the old cached image.
        $v = file_exists($file) ? filemtime($file) : strtotime($modified);
        return HTTP_PLAYER_IMAGE . $name . '.' . $ext . '?' . $v;
    }

    public function set_waiver($request)
    {
        logtrace("set_waiver()", $request);
        $mundane = $this->player_info($request['MundaneId']);

        $notices = '';
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
            $request = $this->media_fetch('Waiver', $request);
            if ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::supported_mime_types($request['WaiverMimeType']) && !Common::is_pdf_mime_type($request['WaiverMimeType'])) {
                logtrace("set_waiver() - image", $request);
                $waiver = @imagecreatefromstring(base64_decode($request['Waiver']));
                if ($waiver !== false) {
                    $base = DIR_WAIVERS . sprintf("%06d", $request['MundaneId']);
                    $use_png = Common::gd_has_transparency($waiver);

                    if (file_exists($base . '.jpg')) {
                        unlink($base . '.jpg');
                    }
                    if (file_exists($base . '.png')) {
                        unlink($base . '.png');
                    }

                    if ($use_png) {
                        imagealphablending($waiver, false);
                        imagesavealpha($waiver, true);
                        imagepng($waiver, $base . '.png');
                        $this->mundane->waiver_ext = 'png';
                    } else {
                        imagejpeg($waiver, $base . '.jpg');
                        $this->mundane->waiver_ext = 'jpg';
                    }
                    $this->mundane->waivered = 1;
                } else {
                    $notices .= 'There was an error uploading or decoding your image.<br />';
                    return InvalidParameter($notices);
                }
            } elseif ($request['Waivered'] && strlen($request['Waiver']) > 0 && strlen($request['Waiver']) < 465000 && Common::is_pdf_mime_type($request['WaiverMimeType'])) {
                logtrace("set_waiver() - pdf", $request);
                $waiver = @base64_decode($request['Waiver']);
                if ($waiver !== false) {
                    if (file_exists(DIR_WAIVERS.(sprintf("%06d", $request['MundaneId'])).'.pdf')) {
                        unlink(DIR_WAIVERS.(sprintf("%06d", $request['MundaneId'])).'.pdf');
                    }
                    file_put_contents(DIR_WAIVERS.(sprintf("%06d", $this->mundane->mundane_id)).'.pdf', $waiver, LOCK_EX);
                    $this->mundane->waivered = 1;
                    $this->mundane->waiver_ext = 'pdf';
                } else {
                    $notices .= 'There was an error decoding your image.<br />';
                    return InvalidParameter($notices);
                }
            } elseif ($request['Waivered']) {
                logtrace("set_waiver() - force waivered", $request);
                $this->mundane->waivered = 1;
                $notices .= 'Waivers must be jpeg, gifs, pngs, or pdfs, and may be no larger than 340KB.<br />';
            } else {
                logtrace("set_waiver() - force waivered (false)", $request);
                $this->mundane->waivered = 0;
            }
        } else {
            logtrace("set_waiver no auth;", 0);
            return NoAuthorization($notices);
        }
        return Success($notices);
    }

    public function SetRestriction($request)
    {
        $mundane = $this->player_info($request['MundaneId']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $this->mundane->restricted = $request['Restricted'] ? 1 : 0;
                $this->mundane->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function RemoveImage($request)
    {
        logtrace("RemoveImage", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
                || $requester_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $path = DIR_PLAYER_IMAGE . sprintf('%06d', $request['MundaneId']) . '.jpg';
                if (file_exists($path)) {
                    unlink($path);
                }
                $this->mundane->has_image = 0;
                $this->mundane->save();
                $this->reset_photo_focus($request['MundaneId']);
                $this->audit_media_remove(__FUNCTION__, $request, 'Image', $requester_id, $mundane);
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function SetImage($request)
    {
        logtrace("SetImage", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)
                || $requester_id == $request['MundaneId']) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $r = $this->set_image($request);
                $this->mundane->save();
                $this->reset_photo_focus($request['MundaneId']);
                $this->audit_media_upload(__FUNCTION__, $request, 'Image', $requester_id, $mundane);
                return $r;
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function SetWaiver($request)
    {
        logtrace("SetWaiver", $request);
        $mundane = $this->player_info($request['MundaneId']);
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);

        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $r = $this->set_waiver($request);
                $this->mundane->save();
                $this->audit_media_upload(__FUNCTION__, $request, 'Waiver', $requester_id, $mundane);
                return $r;
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    // Tiny audit helper for the file-upload entry points (SetImage, SetWaiver,
    // and similar) that don't go through UpdatePlayer's diff-based audit path.
    private function audit_media_upload($fn, $request, $kind, $requester_id, $mundane)
    {
        $bytes = isset($request[$kind]) ? strlen((string)$request[$kind]) : 0;
        if ($bytes <= 0) {
            return;
        } // upload didn't carry payload — nothing to record
        $payload = [
            'MundaneId'  => $request['MundaneId'],
            $kind        => ['uploaded' => true, 'bytes' => $bytes, 'mime' => $request[$kind.'MimeType'] ?? null],
            'SelfEdit'   => ($requester_id == $request['MundaneId']) ? 1 : 0,
            'AdminEdit'  => ($requester_id != $request['MundaneId'] && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) ? 1 : 0,
            'OfficerEdit' => ($requester_id != $request['MundaneId'] && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) ? 1 : 0,
        ];
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . $fn, $payload, 'Player', $request['MundaneId'], null, null);
    }

    // Photo focus values (x/y/size) are pixel-percentages of the player's photo,
    // so they're meaningless once the photo is removed or replaced. Reset to
    // schema defaults whenever the underlying image changes. UPDATE matching
    // 0 rows is harmless when no design row exists yet.
    private function reset_photo_focus($mundane_id)
    {
        $id = (int)$mundane_id;
        if ($id <= 0) {
            return;
        }
        // Clear any leftover bound params from a prior Yapo save — without this,
        // PDO would try to bind them to this placeholder-free UPDATE and fail
        // silently (ERRMODE_WARNING), leaving the row unchanged.
        $this->db->Clear();
        $this->db->Execute("UPDATE " . DB_PREFIX . "mundane_design SET photo_focus_x = 50, photo_focus_y = 50, photo_focus_size = 100 WHERE mundane_id = $id");
    }

    // Audit helper for AddSecondToRecommendation / WithdrawSecond. The
    // requester is the currently-authorized session, the entity is the
    // recipient of the parent recommendation, and the payload carries the
    // rec/second ids plus actor flags so admin actions are easy to filter.
    private function audit_second_change($fn, $payload, $entity_id, $requester_id)
    {
        $requester_id = (int)$requester_id;
        $supporter_id = (int)($payload['SupporterMundaneId'] ?? $requester_id);
        $payload['SelfAction']    = ($requester_id === $supporter_id) ? 1 : 0;
        $payload['AdminAction']   = 0;
        $payload['OfficerAction'] = 0;
        if (!$payload['SelfAction'] && (int)$entity_id > 0) {
            if (Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
                $payload['AdminAction'] = 1;
            } else {
                $info = $this->player_info((int)$entity_id);
                if (!empty($info['ParkId']) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, (int)$info['ParkId'], AUTH_CREATE)) {
                    $payload['OfficerAction'] = 1;
                }
            }
        }
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . $fn, $payload, 'Player', (int)$entity_id, null, null);
    }

    // Companion to audit_media_upload for the Remove* entry points.
    private function audit_media_remove($fn, $request, $kind, $requester_id, $mundane)
    {
        $payload = [
            'MundaneId'  => $request['MundaneId'],
            $kind        => ['removed' => true],
            'SelfEdit'   => ($requester_id == $request['MundaneId']) ? 1 : 0,
            'AdminEdit'  => ($requester_id != $request['MundaneId'] && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT)) ? 1 : 0,
            'OfficerEdit' => ($requester_id != $request['MundaneId'] && !Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_ADMIN, 0, AUTH_EDIT) && Ork3::$Lib->authorization->HasAuthority($requester_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) ? 1 : 0,
        ];
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . $fn, $payload, 'Player', $request['MundaneId'], null, null);
    }

    public function SetBan($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) > 0
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_EDIT)) {
            $this->mundane->clear();
            $this->mundane->mundane_id = $request['MundaneId'];
            if ($this->mundane->find()) {
                $this->mundane->penalty_box = $request['Banned'] ? 1 : 0;
                $this->mundane->save();
                return Success();
            } else {
                return InvalidParameter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function ResetWaivers($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        $isGlobalAdmin  = Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_ADMIN, 0, AUTH_ADMIN);
        $isParkOfficer  = valid_id($request['ParkId']    ?? 0) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, (int)$request['ParkId'], AUTH_EDIT);
        $isKingdomOfficer = valid_id($request['KingdomId'] ?? 0) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_KINGDOM, (int)$request['KingdomId'], AUTH_EDIT);
        if (!$isGlobalAdmin && !$isParkOfficer && !$isKingdomOfficer) {
            return NoAuthorization();
        }

        if (valid_id($request['KingdomId'])) {
            $sql = "UPDATE " . DB_PREFIX . "mundane SET waivered = 0 WHERE kingdom_id = '" . mysql_real_escape_string($request['KingdomId']) . "'";
            $this->db->query($sql);
            return Success('Waivers have been reset for all players in the kingdom.');
        } elseif (valid_id($request['ParkId'])) {
            $sql = "UPDATE " . DB_PREFIX . "mundane SET waivered = 0 WHERE park_id = '" . mysql_real_escape_string($request['ParkId']) . "'";
            $this->db->query($sql);
            return Success('Waivers have been reset for all players in the park.');
        }

        return InvalidParameter('Either KingdomId or ParkId must be specified.');
    }

    public function AddAward($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        logtrace("AddAward()", $request);

        $this->mundane->clear();
        $this->mundane->mundane_id = $request['RecipientId'];
        if (!$this->mundane->find()) {
            return InvalidParameter();
        }
        $recipient = array( 'KingdomId' => $this->mundane->kingdom_id, 'ParkId' => $this->mundane->park_id );

        if (valid_id($request['AwardId'])) {
            list($request['KingdomAwardId'], $request['AwardId']) = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } elseif (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
        } else {
            return InvalidParameter();
        }

        // Guard against an unresolved kingdomaward (LookupAward/LookupKingdomAward
        // returned a zero/invalid id) — saving here would create an orphaned grant.
        if (!valid_id($request['KingdomAwardId'])) {
            return InvalidParameter();
        }

        if (valid_id($mundane_id)
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipient['ParkId'], AUTH_CREATE)) {
            if (valid_id($request['ParkId'])) {
                $Park = new Park();
                $park_info = $Park->GetParkShortInfo($request);
                if ($park_info['Status']['Status'] != 0) {
                    return InvalidParameter();
                }
            }
            if (valid_id($request['GivenById'])) {
                $given_by = $this->GetPlayer(array('MundaneId' => $request['GivenById']));
            }

            logtrace("GivenBy", $given_by);
            $awards = new yapo($this->db, DB_PREFIX . 'awards');
            $awards->clear();
            $awards->kingdomaward_id = $request['KingdomAwardId'];
            $awards->award_id = $request['AwardId'];
            $awards->custom_name = $request['CustomName'] ?? '';
            $awards->alias_award_id = (!empty($request['AliasAwardId']) && (int)$request['AliasAwardId'] > 0) ? (int)$request['AliasAwardId'] : null;
            $awards->mundane_id = $request['RecipientId'];
            $awards->rank = $request['Rank'];
            $awards->date = $request['Date'];
            $awards->given_by_id = $request['GivenById'];
            $awards->at_park_id = valid_id($request['ParkId']) ? $request['ParkId'] : 0;
            $awards->at_kingdom_id = valid_id($request['KingdomId']) ? $request['KingdomId'] : 0;
            $awards->at_event_id = valid_id($request['EventId']) ? $request['EventId'] : 0;
            $awards->note = $request['Note'];
            $awards->by_whom_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
            $awards->entered_at = date("Y-m-d H:i:s");
            // If no event, then go Park!
            if (valid_id($request['GivenById'])) {
                $awards->park_id = valid_id($given_by['Player']['ParkId']) ? $given_by['Player']['ParkId'] : 0;
                // If no event and valid parkid, go Park! Otherwise, go Kingdom.  Unless it's an event.  Then go ... ZERO!
                $awards->kingdom_id = valid_id($given_by['Player']['KingdomId']) ? $given_by['Player']['KingdomId'] : 0;
            }
            // Events are awesome.

            if (!empty($awards->alias_award_id)) {
                $ctid = $this->getCustomTitleAwardId();
                $aid = (int)$awards->alias_award_id;
                $chk = $this->db->query("SELECT award_id, is_title, peerage, officer_role FROM " . DB_PREFIX . "award WHERE award_id = " . $aid . " LIMIT 1");
                $bad = true;
                if ($chk && $chk->size() > 0) {
                    $chk->next();
                    if ((int)$chk->award_id !== $ctid && $chk->officer_role === 'none'
                        && ((int)$chk->is_title === 1 || !in_array($chk->peerage, array('', 'None'), true))) {
                        $bad = false;
                    }
                }
                if ($bad) {
                    return InvalidParameter();
                }
            }

            $awards->save();

            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $request['RecipientId'], $this->get_award($awards));

            return Success('');
        } else {
            return NoAuthorization();
        }
    }

    private function revoke_award(& $awards, $revocation, $revoker_id)
    {
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

        $awards->stripped_from = $awards->mundane_id;
        $awards->mundane_id = 0;
        $awards->revoked = 1;
        $awards->revoked_at = date("Y-m-d H:i:s");
        $awards->revocation = $revocation;
        $awards->revoked_by_id = $revoker_id;

        $awards->save();
    }

    public function RevokeAllAwards($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->mundane_id = $request['MundaneId'];
        if ($awards->find() && valid_id($mundane_id)) {
            $mundane = $this->player_info($awards->mundane_id);
            if (valid_id($request['MundaneId'])
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {

                // Collect all IDs first: save() calls Clear()+Find() after each save,
                // replacing the result set, so next() would exit the loop after one iteration.
                $award_ids = [];
                do {
                    $award_ids[] = $awards->awards_id;
                } while ($awards->next());

                foreach ($award_ids as $aid) {
                    $awards->clear();
                    $awards->awards_id = $aid;
                    if ($awards->find()) {
                        $this->revoke_award($awards, $request["Revocation"], $mundane_id);
                    }
                }

                return Success(count($award_ids));
            } else {
                return NoAuthorization();
            }
        } else {
            return InvalidParameter();
        }
    }

    public function RevokeAward($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->awards_id = $request['AwardsId'];
        if (valid_id($request['AwardsId']) && $awards->find() && $mundane_id > 0) {
            $mundane = $this->player_info($awards->mundane_id);
            if (valid_id($mundane_id)
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {

                $this->revoke_award($awards, $request["Revocation"], $mundane_id);

                return Success($awards->awards_id);
            } else {
                return NoAuthorization();
            }
        } else {
            return InvalidParameter();
        }
    }

    public function ReactivateAward($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->awards_id = $request['AwardsId'];
        if (valid_id($request['AwardsId']) && $awards->find() && $mundane_id > 0) {
            // Must be a currently revoked award with a valid original recipient on file.
            if ((int)$awards->revoked !== 1 || !valid_id($awards->stripped_from)) {
                return InvalidParameter('That award is not currently revoked.');
            }
            $recipient = $this->player_info($awards->stripped_from);
            if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipient['ParkId'], AUTH_CREATE)) {
                return NoAuthorization();
            }

            Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->stripped_from, $this->get_award($awards));

            $awards->mundane_id    = $awards->stripped_from;
            $awards->stripped_from = 0;
            $awards->revoked       = 0;
            $awards->revoked_at    = null;
            $awards->revocation    = null;
            $awards->revoked_by_id = 0;
            $awards->save();

            return Success($awards->awards_id);
        } else {
            return InvalidParameter();
        }
    }

    public function UpdateAward($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->awards_id = $request['AwardsId'];
        if (valid_id($request['AwardsId']) && $awards->find()) {
            $mundane = $this->player_info($awards->mundane_id);
            if (valid_id($mundane_id)
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {
                if (valid_id($request['ParkId'])) {
                    $Park = new Park();
                    $info = $Park->GetParkShortInfo(array( 'ParkId' => $request['ParkId'] ));
                    if ($info['Status']['Status'] != 0) {
                        return InvalidParameter();
                    }
                }

                // Snapshot prior state for audit before any writes; post-state is
                // captured after the UPDATE so the audit log can render a real diff.
                $_audit_prior   = $this->get_award($awards);
                $_audit_mundane = $awards->mundane_id;

                $set_rank       = intval($request['Rank']);
                $set_date       = $request['Date'] ? date('Y-m-d', strtotime($request['Date'])) : $awards->date;
                $set_given_by_id = intval($request['GivenById']);
                $set_note       = addslashes($request['Note']);
                $set_at_park_id    = !valid_id($request['EventId']) ? intval($request['ParkId']) : 0;
                $set_at_kingdom_id = !valid_id($request['EventId']) ? (valid_id($request['ParkId']) ? intval($info['ParkInfo']['KingdomId']) : intval($request['KingdomId'])) : 0;
                $set_at_event_id   = valid_id($request['EventId']) ? intval($request['EventId']) : 0;
                $set_awards_id  = intval($request['AwardsId']);

                // Custom Title reclassification: allow switching between Custom Award and Custom Title sentinels,
                // updating custom_name, alias_award_id, and kingdomaward_id in the same write.
                $extra_sql = '';
                $ctid = $this->getCustomTitleAwardId();
                if (array_key_exists('AwardId', $request) && valid_id($request['AwardId'])) {
                    $req_award_id = (int)$request['AwardId'];
                    if ($req_award_id === 94 || $req_award_id === $ctid) {
                        $extra_sql .= ', award_id=' . $req_award_id;
                        // Also rewrite kingdomaward_id so AwardsForPlayer's ka->a join yields the
                        // correct base award (is_title flag, peerage, etc). Find the matching
                        // kingdomaward row in the same kingdom as the existing row.
                        $curKaKingdomId = 0;
                        if (valid_id($awards->kingdomaward_id)) {
                            $kq = $this->db->query("SELECT kingdom_id FROM " . DB_PREFIX . "kingdomaward WHERE kingdomaward_id = " . (int)$awards->kingdomaward_id . " LIMIT 1");
                            if ($kq && $kq->size() > 0) {
                                $kq->next();
                                $curKaKingdomId = (int)$kq->kingdom_id;
                            }
                        }
                        if ($curKaKingdomId > 0) {
                            $targetName = ($req_award_id === $ctid) ? 'Custom Title' : 'Custom Award';
                            $tq = $this->db->query("SELECT kingdomaward_id FROM " . DB_PREFIX . "kingdomaward WHERE kingdom_id = " . $curKaKingdomId . " AND award_id = " . $req_award_id . " AND name = '" . addslashes($targetName) . "' LIMIT 1");
                            if ($tq && $tq->size() > 0) {
                                $tq->next();
                                $extra_sql .= ', kingdomaward_id=' . (int)$tq->kingdomaward_id;
                            }
                        }
                    }
                }
                if (array_key_exists('CustomName', $request)) {
                    $extra_sql .= ", custom_name='" . addslashes($request['CustomName']) . "'";
                }
                if (array_key_exists('AliasAwardId', $request)) {
                    $new_alias = (!empty($request['AliasAwardId']) && (int)$request['AliasAwardId'] > 0) ? (int)$request['AliasAwardId'] : 0;
                    if ($new_alias > 0) {
                        // Validate alias target
                        $chk = $this->db->query("SELECT award_id, is_title, peerage, officer_role FROM " . DB_PREFIX . "award WHERE award_id = " . $new_alias . " LIMIT 1");
                        $bad = true;
                        if ($chk && $chk->size() > 0) {
                            $chk->next();
                            if ((int)$chk->award_id !== $ctid && $chk->officer_role === 'none'
                                && ((int)$chk->is_title === 1 || !in_array($chk->peerage, array('', 'None'), true))) {
                                $bad = false;
                            }
                        }
                        if ($bad) {
                            return InvalidParameter();
                        }
                        $extra_sql .= ', alias_award_id=' . $new_alias;
                    } else {
                        $extra_sql .= ', alias_award_id=NULL';
                    }
                }

                $sql = 'UPDATE ' . DB_PREFIX . 'awards SET rank=' . $set_rank . ', date=\'' . addslashes($set_date) . '\', given_by_id=' . $set_given_by_id . ', note=\'' . $set_note . '\', at_park_id=' . $set_at_park_id . ', at_kingdom_id=' . $set_at_kingdom_id . ', at_event_id=' . $set_at_event_id . $extra_sql . ' WHERE awards_id=' . $set_awards_id;
                $this->db->query($sql);

                // Re-fetch post-state and audit prior + post so the audit-log diff renderer
                // can show what actually changed (not just intent from the request).
                $_audit_after = $_audit_prior;
                $awards->clear();
                $awards->awards_id = $set_awards_id;
                if ($awards->find()) {
                    $_audit_after = $this->get_award($awards);
                }
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $_audit_mundane, $_audit_prior, $_audit_after);

                Ork3::$Lib->ghettocache->bust(
                    'Model_Player.fetch_player_details',
                    Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$_audit_mundane])
                );

                return Success($set_awards_id);
            } else {
                return InvalidParamter();
            }
        } else {
            return NoAuthorization();
        }
    }

    public function ReconcileAward($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->awards_id = $request['AwardsId'];
        $found = valid_id($request['AwardsId']) && $awards->find();
        if ($found) {
            $mundane = $this->player_info($awards->mundane_id);
            $hasAuth = valid_id($mundane_id) && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT);
            if ($hasAuth) {

                // Validate park and compute new location values for comparison
                $info = null;
                if (valid_id($request['ParkId'])) {
                    $Park = new Park();
                    $info = $Park->GetParkShortInfo(array( 'ParkId' => $request['ParkId'] ));
                    if ($info['Status']['Status'] != 0) {
                        return InvalidParameter();
                    }
                }

                $new_kingdomaward_id = valid_id($request['KingdomAwardId']) ? $request['KingdomAwardId'] : $awards->kingdomaward_id;
                $new_at_park_id = valid_id($request['ParkId']) ? $request['ParkId'] : 0;
                $new_at_kingdom_id = valid_id($request['EventId']) ? 0 : (valid_id($request['ParkId']) ? $info['ParkInfo']['KingdomId'] : (valid_id($request['KingdomId']) ? $request['KingdomId'] : 0));
                $new_at_event_id = valid_id($request['EventId']) ? $request['EventId'] : 0;
                $new_custom_name = isset($request['CustomName']) ? $request['CustomName'] : (valid_id($request['KingdomAwardId']) ? '' : $awards->custom_name);

                // Skip save and audit if nothing actually changed
                $no_op = ($new_kingdomaward_id == $awards->kingdomaward_id
                    && intval($request['Rank']) == intval($awards->rank)
                    && $request['GivenById'] == $awards->given_by_id
                    && $request['Note'] == $awards->note
                    && $new_custom_name == $awards->custom_name
                    && $new_at_park_id == $awards->at_park_id
                    && $new_at_kingdom_id == $awards->at_kingdom_id
                    && $new_at_event_id == $awards->at_event_id
                    && intval($awards->by_whom_id) > 0);
                if ($no_op) {
                    return Success(false);
                }

                $set_kingdomaward_id = valid_id($request['KingdomAwardId']) ? intval($request['KingdomAwardId']) : intval($awards->kingdomaward_id);
                $set_award_id = intval($awards->award_id);
                $set_custom_name = isset($request['CustomName']) ? $request['CustomName'] : '';
                if (valid_id($request['KingdomAwardId'])) {
                    list($kingdom_id, $set_award_id) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
                }
                $set_rank = intval($request['Rank']);
                $set_date = valid_id($request['Date']) ? date('Y-m-d', strtotime($request['Date'])) : $awards->date;
                $set_given_by_id = intval($request['GivenById']);
                $set_note = $request['Note'];
                $set_awards_id = intval($request['AwardsId']);

                // Snapshot prior state before the write so the audit-log diff renderer
                // has a real before/after pair for ReconcileAward (shares Player::UpdateAward's
                // diff case in Admin_auditlog.tpl).
                $_audit_prior   = $this->get_award($awards);
                $_audit_mundane = $awards->mundane_id;

                $sql = 'UPDATE ' . DB_PREFIX . 'awards SET kingdomaward_id=' . intval($set_kingdomaward_id) . ', award_id=' . intval($set_award_id) . ', custom_name=\'' . addslashes($set_custom_name) . '\', rank=' . intval($set_rank) . ', date=\'' . addslashes($set_date) . '\', given_by_id=' . intval($set_given_by_id) . ', at_park_id=' . intval($new_at_park_id) . ', at_kingdom_id=' . intval($new_at_kingdom_id) . ', at_event_id=' . intval($new_at_event_id) . ', note=\'' . addslashes($set_note) . '\', by_whom_id=' . intval($mundane_id) . ' WHERE awards_id=' . intval($set_awards_id);
                $this->db->query($sql);

                // Re-fetch post-state for the audit diff.
                $_audit_after = $_audit_prior;
                $awards->clear();
                $awards->awards_id = $set_awards_id;
                if ($awards->find()) {
                    $_audit_after = $this->get_award($awards);
                }
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $_audit_mundane, $_audit_prior, $_audit_after);

                Ork3::$Lib->ghettocache->bust(
                    'Model_Player.fetch_player_details',
                    Ork3::$Lib->ghettocache->key(['MundaneId' => (int)$_audit_mundane])
                );

                return Success($set_awards_id);
            } else {
                return NoAuthorization();
            }
        } else {
            return InvalidParameter();
        }
    }

    private function get_award(& $awards)
    {
        $award = new stdClass();
        $award->awards_id = $awards->awards_id;
        $award->kingdomaward_id = $awards->kingdomaward_id;
        $award->mundane_id = $awards->mundane_id;
        $award->unit_id = $awards->unit_id;
        $award->park_id = $awards->park_id;
        $award->kingdom_id = $awards->kingdom_id;
        $award->team_id = $awards->team_id;
        $award->rank = $awards->rank;
        $award->date = $awards->date;
        $award->given_by_id = $awards->given_by_id;
        $award->note = $awards->note;
        $award->at_park_id = $awards->at_park_id;
        $award->at_kingdom_id = $awards->at_kingdom_id;
        $award->at_event_id = $awards->at_event_id;
        $award->custom_name = $awards->custom_name;
        $award->alias_award_id = $awards->alias_award_id;
        $award->award_id = $awards->award_id;
        return $award;
    }

    public function RemoveAward($request)
    {
        logtrace("RemoveAward()", $request);
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $awards = new yapo($this->db, DB_PREFIX . 'awards');
        $awards->clear();
        $awards->awards_id = $request['AwardsId'];
        if (valid_id($request['AwardsId']) && $awards->find()) {
            $mundane = $this->player_info($awards->mundane_id);
            if (valid_id($mundane_id)
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_CREATE)) {

                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', $awards->mundane_id, $this->get_award($awards));

                $awards->delete();
            } else {
                return NoAuthorization();
            }
        } else {
            return InvalidParameter();
        }
    }

    public function AddDues($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $dues = new yapo($this->db, DB_PREFIX . 'dues');
        $dues->clear();

        if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $request['ParkId'], AUTH_EDIT)) {
            $dues->mundane_id = $request['MundaneId'];
            $dues->created_by = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
            $dues->created_on = date('Y-m-d');
            $dues->park_id = $request['ParkId'];
            $dues->kingdom_id = $request['KingdomId'];
            $dues->dues_from = date('Y-m-d', strtotime($request['DuesFrom']));
            if (!empty($request['Months'])) {
                $n    = max(1, (int)$request['Months']);
                $unit = ($request['DuesPeriodType'] === 'week') ? 'weeks' : 'months';
                $dues->dues_until = date('Y-m-d', strtotime($request['DuesFrom'] . ' + ' . $n . ' ' . $unit));
                $dues->terms = $n;
            } else {
                $dues->dues_until = $this->determine_dues_until($request['KingdomId'], $request['DuesFrom'], $request['Terms']);
                $dues->terms = $request['Terms'];
            }
            $dues->dues_for_life = $request['DuesForLife'];
            $dues->save();

            return Success($dues->dues_id);
        } else {
            return NoAuthorization();
        }
    }

    private function determine_dues_until($kingdom_id, $dues_from = null, $terms = null)
    {
        $kconfig = Common::get_configs($kingdom_id);
        $dues_config = $kconfig['DuesPeriod'];
        $n = (int)$dues_config['Value']->Period * (int)$terms;
        $dues_until = date('Y-m-d', strtotime($dues_from . ' + ' . $n . ' ' . $dues_config['Value']->Type));
        return $dues_until;
    }

    public function GetDues($request)
    {
        // $request['MundaneId'] $request['ExcludeRevoked'] $request['Active']
        if (valid_id($request['MundaneId'])) {
            $this->dues->clear();
            $this->dues->mundane_id = $request['MundaneId'];
            $sql = "select * from ork_dues where mundane_id = $request[MundaneId]";

            if (!empty($request['ExcludeRevoked'])) {
                $this->dues->revoked = 0;
                $sql .= " and revoked = 0";
            }
            if (!empty($request['Active'])) {
                // ... wtf
                //$this->dues->dues_until_conjunction = ' AND ( `dues_for_life` = 1 OR ';
                //$this->dues->dues_until_term = "> '" . date('Y-m-d') . "') " . ' AND "" = ' ;
                $sql .= " and (dues_for_life = 1 or dues_until > '" . date('Y-m-d') . "')";
            }

            $this->db->clear();
            $this->db->mundane_id = $request['MundaneId'];
            $this->db->dues_until = date('Y-m-d');
            $dues = $this->db->query($sql);

            $duesReport = array();
            $now = time();
            if ($dues->size() > 0) {
                while ($dues->next()) {
                    if (!empty($request['Active']) && $now > strtotime($dues->dues_until) && $dues->dues_for_life == 0) {
                        continue;
                    }
                    $duesReport[] = array(
                            'DuesId' => $dues->dues_id,
                            'KingdomId' => $dues->kingdom_id,
                            'KingdomName' => $this->Kingdom->get_kingdom_name($dues->kingdom_id),
                            'ParkId' => $dues->kingdom_id,
                            'ParkName' => $this->Park->get_park_name($dues->park_id),
                            'DuesUntil' => $dues->dues_until,
                            'DuesFrom' => $dues->dues_from,
                            'DuesForLife' => $dues->dues_for_life,
                            'Revoked' => $dues->revoked
                        );
                }
            }
        }
        return $duesReport;
    }

    // TODO:
    public function RevokeDues($request)
    {
        $mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        $dues = new yapo($this->db, DB_PREFIX . 'dues');
        $dues->clear();
        $dues->dues_id = $request['DuesId'];
        if (valid_id($request['DuesId']) && $dues->find()) {
            $mundane = $this->player_info($dues->mundane_id);
            if (valid_id($mundane_id)
                && Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $mundane['ParkId'], AUTH_EDIT)) {
                $prior_state = [
                    'dues_id'       => (int)$dues->dues_id,
                    'mundane_id'    => (int)$dues->mundane_id,
                    'kingdom_id'    => (int)$dues->kingdom_id,
                    'park_id'       => (int)$dues->park_id,
                    'dues_from'     => $dues->dues_from,
                    'dues_until'    => $dues->dues_until,
                    'dues_for_life' => (int)$dues->dues_for_life,
                ];
                $dues->revoked = 1;
                $dues->revoked_on = date('Y-m-d');
                $dues->revoked_by = $mundane_id;
                $dues->save();
                Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', (int)$dues->mundane_id, $prior_state);
                return Success($dues->dues_id);
            } else {
                return NoAuthorization();
            }
        } else {
            return InvalidParamter();
        }
    }

    public function AddAwardRecommendation($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        $this->mundane->clear();
        $this->mundane->mundane_id = $request['MundaneId'];
        if (!$this->mundane->find()) {
            return InvalidParameter();
        }
        $recipient = array( 'KingdomId' => $this->mundane->kingdom_id, 'ParkId' => $this->mundane->park_id );

        if (valid_id($request['AwardId'])) {
            list($request['KingdomAwardId'], $request['AwardId']) = Ork3::$Lib->award->LookupAward(array('KingdomId' => $recipient['KingdomId'], 'AwardId' => $request['AwardId']));
        } elseif (valid_id($request['KingdomAwardId'])) {
            list($kingdom_id, $request['AwardId']) = Ork3::$Lib->award->LookupKingdomAward(array('KingdomAwardId' => $request['KingdomAwardId']));
        } else {
            return InvalidParameter();
        }

        // Block recommendations for a ladder the player has already topped out on
        // (either via the Master-peerage companion award or by holding the ladder's max rank).
        // Also block direct recommendations for a Master peerage the player already holds.
        $ladderMap  = Award::GetLadderMasterMap();
        $recAwardId = (int)$request['AwardId'];
        $persona    = $this->mundane->persona;
        $mid        = (int)$request['MundaneId'];

        // Build reverse map: master_award_id => ['MasterName' => ...]
        $masterNameById = [];
        foreach ($ladderMap as $lid => $lInfo) {
            foreach ((array)$lInfo['MasterAwardIds'] as $mAid) {
                $masterNameById[(int)$mAid] = $lInfo['MasterName'];
            }
        }

        // Case A: recommending a Master peerage the player already holds (any kingdomaward)
        if ($recAwardId > 0 && isset($masterNameById[$recAwardId])) {
            $this->db->clear();
            $held = $this->db->query(
                "select a.awards_id from " . DB_PREFIX . "awards a
				 where a.mundane_id = {$mid} and a.award_id = {$recAwardId} limit 1"
            );
            if ($held !== false && $held->size() > 0) {
                return InvalidParameter($persona . ' has already achieved the rank of ' . $masterNameById[$recAwardId] . '. Maybe consider recommending for a different award?');
            }
        }

        // Case B: recommending a ladder where the player has topped out or holds the Master peerage
        if (isset($ladderMap[$recAwardId])) {
            $info = $ladderMap[$recAwardId];

            $masterIdsCsv = implode(',', array_map('intval', $info['MasterAwardIds']));
            $this->db->clear();
            $masterHeld   = $this->db->query(
                "select a.awards_id from " . DB_PREFIX . "awards a
				 where a.mundane_id = {$mid} and a.award_id in ({$masterIdsCsv}) limit 1"
            );
            if ($masterHeld !== false && $masterHeld->size() > 0) {
                return InvalidParameter($persona . ' has already achieved the rank of ' . $info['MasterName'] . '. Maybe consider recommending for a different award?');
            }

            $maxRank   = (int)$info['MaxRank'];
            $this->db->clear();
            $topResult = $this->db->query(
                "select max(a.rank) as max_rank from " . DB_PREFIX . "awards a
				 where a.mundane_id = {$mid} and a.award_id = {$recAwardId}"
            );
            if ($topResult !== false && $topResult->size() > 0 && $topResult->next() && (int)$topResult->max_rank >= $maxRank) {
                return InvalidParameter($persona . ' has already reached the top rank of ' . $info['LadderName'] . '. Maybe consider recommending for a different award?');
            }
        }

        // Custom awards (is_ladder = 0 AND is_title = 0) and the "Custom Title"
        // sentinel allow unlimited duplicates and unlimited recommendations —
        // their real name is free-text entered at grant time and is never stored
        // on the recommendation row, so the dedup check (which keys on the shared
        // kingdomaward_id) would wrongly block genuinely different customs.
        $isCustomAward = false;
        $isCustomTitle = false;
        $this->db->clear();
        $awardMeta = $this->db->query("SELECT name, is_ladder, is_title FROM " . DB_PREFIX . "award WHERE award_id = " . (int)$request['AwardId'] . " LIMIT 1");
        if ($awardMeta && $awardMeta->next()) {
            $isCustomAward = ((int)$awardMeta->is_ladder === 0 && (int)$awardMeta->is_title === 0);
            $isCustomTitle = ((int)$awardMeta->is_title === 1 && $awardMeta->name === 'Custom Title');
        }

        // Check for existing award rank (ladder awards only — custom awards and
        // titles have rank = 0 and may legitimately be held multiple times).
        $check_rank = 0;
        if (trimlen($request['Rank']) > 0) {
            $check_rank = $request['Rank'];
        }
        if ($check_rank > 0) {
            $existingAward = new yapo($this->db, DB_PREFIX . 'awards');
            $existingAward->clear();
            $existingAward->kingdomaward_id = $request['KingdomAwardId'];
            $existingAward->mundane_id = $request['MundaneId'];
            $existingAward->rank = $check_rank;
            $existingAward->find();
            if ($existingAward->awards_id) {
                return InvalidParameter('They already have that award.');
            }
        }

        // Check for duplicate recommendations from the same user
        // (skipped for custom awards and custom titles — see note above).
        if (!$isCustomAward && !$isCustomTitle) {
            $dupeRec = new yapo($this->db, DB_PREFIX . 'recommendations');
            $dupeRec->clear();
            $dupeRec->kingdomaward_id = $request['KingdomAwardId'];
            $dupeRec->mundane_id = $request['MundaneId'];
            $dupeRec->recommended_by_id = $mundane_id;
            if (trimlen($request['Rank']) > 0) {
                $dupeRec->rank = $request['Rank'];
            } else {
                $dupeRec->rank = 0;
            }
            if ($dupeRec->find()) {
                do {
                    if (!$dupeRec->deleted_at) {
                        return InvalidParameter('You already recommended that award and level.');
                    }
                } while ($dupeRec->next());
            }
        }

        if (valid_id($mundane_id)) {
            $awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
            $awardRec->clear();
            $awardRec->kingdomaward_id = $request['KingdomAwardId'];
            $awardRec->award_id = $request['AwardId'];
            $awardRec->mundane_id = $request['MundaneId'];
            $awardRec->rank = $check_rank;
            $awardRec->date_recommended = date('Y-m-d');
            $awardRec->recommended_by_id = $mundane_id;
            $awardRec->reason = $request['Reason'];
            $awardRec->mask_giver = !empty($request['Anonymous']) ? 1 : 0;
            $awardRec->save();
            $this->bust_player_award_recs_cache($request['MundaneId']);
            return Success('Recommendation Added!');
        } else {
            return NoAuthorization();
        }
    }

	public function SnoozeAwardRecommendation($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		$rec_id = (int)($request['RecommendationsId'] ?? 0);
		if (!$rec_id) return InvalidParameter();

		$awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
		$awardRec->clear();
		$awardRec->recommendations_id = $rec_id;
		if (!$awardRec->find()) return InvalidParameter('Recommendation not found.');

		// Auth: must be park admin for recipient's park
		$recipientInfo = $this->player_info($awardRec->mundane_id);
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_EDIT))
			return NoAuthorization();

		// Resolve current monarch and regent for the recipient's park
		$pid = (int)$recipientInfo['ParkId'];
		$sql = "SELECT
			COALESCE(MAX(CASE WHEN role='Monarch' THEN mundane_id END), 0) AS monarch_id,
			COALESCE(MAX(CASE WHEN role='Regent'  THEN mundane_id END), 0) AS regent_id
			FROM " . DB_PREFIX . "officer WHERE park_id = {$pid}";
		$r = $this->db->query($sql);
		$monarch_id = 0; $regent_id = 0;
		if ($r && $r->size() > 0 && $r->next()) {
			$monarch_id = (int)$r->monarch_id;
			$regent_id  = (int)$r->regent_id;
		}

		$awardRec->snoozed_by_id      = $mundane_id;
		$awardRec->snoozed_monarch_id = $monarch_id;
		$awardRec->snoozed_regent_id  = $regent_id;
		$awardRec->save();
		return Success('Recommendation snoozed.');
	}

	public function UnsnoozeAwardRecommendation($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

		$rec_id = (int)($request['RecommendationsId'] ?? 0);
		if (!$rec_id) return InvalidParameter();

		$awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
		$awardRec->clear();
		$awardRec->recommendations_id = $rec_id;
		if (!$awardRec->find()) return InvalidParameter('Recommendation not found.');

		$recipientInfo = $this->player_info($awardRec->mundane_id);
		if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_EDIT))
			return NoAuthorization();

		// yapo's save() skips null-valued fields (isset() guard in YapoSave), so
		// assigning null above never cleared these columns and unsnooze silently
		// no-op'd. Clear them with a direct UPDATE instead.
		$this->db->query(
			"UPDATE " . DB_PREFIX . "recommendations
			 SET snoozed_by_id = NULL, snoozed_monarch_id = NULL, snoozed_regent_id = NULL
			 WHERE recommendations_id = " . (int)$rec_id
		);
		return Success('Recommendation unsnoozed.');
	}

	public function DeleteAwardRecommendation($request) {
		if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0)
			return NoAuthorization();

        if (valid_id($request['RequestedBy'])) {
            $can_delete_recommendation = false;
            $awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
            $awardRec->clear();
            $awardRec->recommendations_id = $request['RecommendationsId'];

			if (valid_id($request['RecommendationsId']) && $awardRec->find()) {
				$recipientInfo = $this->player_info($awardRec->mundane_id);
				if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_CREATE)) {
					$can_delete_recommendation = true;
				}
				if ($can_delete_recommendation || $request['RequestedBy'] == $awardRec->recommended_by_id || $request['RequestedBy'] == $awardRec->mundane_id) {
					$prior_rec = [
						'recommendations_id' => (int)$awardRec->recommendations_id,
						'mundane_id'         => (int)$awardRec->mundane_id,
						'kingdomaward_id'    => (int)$awardRec->kingdomaward_id,
						'award_id'           => (int)$awardRec->award_id,
						'rank'               => (int)$awardRec->rank,
						'recommended_by_id'  => (int)$awardRec->recommended_by_id,
						'date_recommended'   => $awardRec->date_recommended,
						'reason'             => $awardRec->reason,
					];
					$cascade_at = date('Y-m-d H:i:s');
					// Granted-from-Manager: notify advocates BEFORE the rec/seconds soft-delete.
					if (!empty($request['Granted'])) {
						try {
							Ork3::$Lib->notification->notifyRecommendationGranted(
								(int)$awardRec->recommendations_id, (int)$request['RequestedBy']);
						} catch (\Throwable $e) { /* best-effort */ }
					}
					$awardRec->deleted_by = $request['RequestedBy'];
					$awardRec->deleted_at = $cascade_at;
					$awardRec->save();
					Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', (int)$awardRec->mundane_id, $prior_rec);
					// Cascade soft-delete to any active seconds on this recommendation.
					$this->db->Clear();
					$this->db->query("UPDATE " . DB_PREFIX . "recommendation_seconds SET deleted_at = '" . $cascade_at . "', deleted_by = " . (int)$request['RequestedBy'] . " WHERE recommendations_id = " . (int)$awardRec->recommendations_id . " AND deleted_at IS NULL");
					$this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
					return Success('Recommendation Removed!');
				} else {
					return InvalidParameter('Only the giver, recipient, or Admin may delete a recommendation.');
				}
			} else {
				return InvalidParameter('There was a problem with the request.');
			}
		} else {
			return NoAuthorization();
		}
	}

    public function RestoreAwardRecommendation($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (!valid_id($request['RecommendationsId'])) {
            return InvalidParameter('There was a problem with the request.');
        }

        $awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
        $awardRec->clear();
        $awardRec->recommendations_id = $request['RecommendationsId'];
        if (!$awardRec->find()) {
            return InvalidParameter('There was a problem with the request.');
        }

        $recipientInfo = $this->player_info($awardRec->mundane_id);
        if (!Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_CREATE)) {
            return NoAuthorization();
        }

        // Truthy check, not empty()/isset(): the Yapo wrapper class has no __isset,
        // so isset() and empty() report false even when __get returns a valid value.
        // Direct truthy evaluation goes through __get and reads the real column value.
        if (!$awardRec->deleted_at) {
            return Success('Already Active');
        }

        $prior_rec = [
            'recommendations_id' => (int)$awardRec->recommendations_id,
            'mundane_id'         => (int)$awardRec->mundane_id,
            'kingdomaward_id'    => (int)$awardRec->kingdomaward_id,
            'award_id'           => (int)$awardRec->award_id,
            'rank'               => (int)$awardRec->rank,
            'recommended_by_id'  => (int)$awardRec->recommended_by_id,
            'date_recommended'   => $awardRec->date_recommended,
            'reason'             => $awardRec->reason,
            'deleted_at'         => $awardRec->deleted_at,
            'deleted_by'         => (int)$awardRec->deleted_by,
        ];
        $priorDeletedAt = $awardRec->deleted_at;
        $priorDeletedBy = (int)$awardRec->deleted_by;

        // Yapo's null-binding doesn't reliably emit SQL NULL on TIMESTAMP columns; use a raw UPDATE.
        $this->db->Clear();
        $this->db->Execute(
            "UPDATE " . DB_PREFIX . "recommendations SET deleted_at = NULL, deleted_by = NULL WHERE recommendations_id = " . (int)$awardRec->recommendations_id
        );

        Ork3::$Lib->dangeraudit->audit(__CLASS__ . "::" . __FUNCTION__, $request, 'Player', (int)$awardRec->mundane_id, $prior_rec);

        // Cascade restore only the seconds that were soft-deleted in the same operation
        // (matched by deleted_at + deleted_by). Seconds individually withdrawn before or
        // after the parent's deletion stay deleted.
        $this->db->Clear();
        $this->db->Execute(
            "UPDATE " . DB_PREFIX . "recommendation_seconds SET deleted_at = NULL, deleted_by = NULL
			 WHERE recommendations_id = " . (int)$awardRec->recommendations_id . "
			   AND deleted_at = '" . addslashes($priorDeletedAt) . "'
			   AND deleted_by = " . $priorDeletedBy
        );

        $this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
        return Success('Recommendation Restored!');
    }


    /**
     * Add a "second" (supporting endorsement) to an existing award recommendation.
     * Eligibility:
     *   - Parent rec must exist and not be soft-deleted.
     *   - Supporter must not be the recipient.
     *   - Supporter must not be the originator.
     *   - Supporter must not have their own active primary rec for the same award/rank/player.
     * If the supporter previously seconded then withdrew, the row is resurrected with new notes.
     */
    public function AddSecondToRecommendation($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (!valid_id($request['RecommendationsId'])) {
            return InvalidParameter('Invalid recommendation.');
        }

        $awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
        $awardRec->clear();
        $awardRec->recommendations_id = $request['RecommendationsId'];
        if (!$awardRec->find() || $awardRec->deleted_at) {
            return InvalidParameter('Recommendation not found.');
        }

        if ((int)$awardRec->mundane_id === (int)$mundane_id) {
            return InvalidParameter('You cannot second a recommendation for yourself.');
        }
        if ((int)$awardRec->recommended_by_id === (int)$mundane_id) {
            return InvalidParameter('You are the original recommender — edit your reason instead of seconding.');
        }

        // Check for own active primary rec on the same award/rank/player.
        $ownRec = new yapo($this->db, DB_PREFIX . 'recommendations');
        $ownRec->clear();
        $ownRec->kingdomaward_id = $awardRec->kingdomaward_id;
        $ownRec->mundane_id = $awardRec->mundane_id;
        $ownRec->rank = $awardRec->rank;
        $ownRec->recommended_by_id = $mundane_id;
        if ($ownRec->find()) {
            do {
                if (!$ownRec->deleted_at) {
                    return InvalidParameter('You already have your own recommendation for this award and rank.');
                }
            } while ($ownRec->next());
        }

        $notes = isset($request['Notes']) ? substr(trim($request['Notes']), 0, 400) : '';

        // Resurrect any prior soft-deleted second by this supporter on this rec.
        $existing = new yapo($this->db, DB_PREFIX . 'recommendation_seconds');
        $existing->clear();
        $existing->recommendations_id = $request['RecommendationsId'];
        $existing->supporter_mundane_id = $mundane_id;
        if ($existing->find()) {
            if (!$existing->deleted_at) {
                return InvalidParameter('You have already seconded this recommendation.');
            }
            // Yapo's null-binding doesn't reliably write SQL NULL to TIMESTAMP
            // columns through PDO::execute(array). Use raw SQL to clear
            // deleted_at / deleted_by alongside the notes/updated_at update.
            $_existingId = (int)$existing->recommendation_seconds_id;
            $this->db->Clear();
            $this->db->notes = $notes;
            $this->db->updated_at = date('Y-m-d H:i:s');
            $this->db->Execute("UPDATE " . DB_PREFIX . "recommendation_seconds
				SET notes = :notes, updated_at = :updated_at, deleted_at = NULL, deleted_by = NULL
				WHERE recommendation_seconds_id = $_existingId");
            $this->db->Clear();
            $this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
            $this->audit_second_change(__FUNCTION__, [
                'RecommendationsId'        => (int)$request['RecommendationsId'],
                'RecommendationSecondsId'  => $_existingId,
                'NotesLen'                 => strlen($notes),
                'Resurrected'              => 1,
                'AwardId'                  => (int)$awardRec->award_id,
                'KingdomAwardId'           => (int)$awardRec->kingdomaward_id,
                'Rank'                     => (int)$awardRec->rank,
            ], (int)$awardRec->mundane_id, $mundane_id);
            return Success($_existingId);
        }

        $second = new yapo($this->db, DB_PREFIX . 'recommendation_seconds');
        $second->clear();
        $second->recommendations_id = $request['RecommendationsId'];
        $second->supporter_mundane_id = $mundane_id;
        $second->notes = $notes;
        $second->save();
        $this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
        $this->audit_second_change(__FUNCTION__, [
            'RecommendationsId'        => (int)$request['RecommendationsId'],
            'RecommendationSecondsId'  => (int)$second->recommendation_seconds_id,
            'NotesLen'                 => strlen($notes),
            'Resurrected'              => 0,
            'AwardId'                  => (int)$awardRec->award_id,
            'KingdomAwardId'           => (int)$awardRec->kingdomaward_id,
            'Rank'                     => (int)$awardRec->rank,
        ], (int)$awardRec->mundane_id, $mundane_id);
        return Success($second->recommendation_seconds_id);
    }

    /**
     * Edit the notes on an existing second. Only the supporter may edit their own notes.
     */
    public function EditSecondNotes($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (!valid_id($request['RecommendationSecondsId'])) {
            return InvalidParameter('Invalid second.');
        }

        $second = new yapo($this->db, DB_PREFIX . 'recommendation_seconds');
        $second->clear();
        $second->recommendation_seconds_id = $request['RecommendationSecondsId'];
        if (!$second->find() || $second->deleted_at) {
            return InvalidParameter('Second not found.');
        }
        if ((int)$second->supporter_mundane_id !== (int)$mundane_id) {
            return InvalidParameter('Only the supporter may edit their own notes.');
        }

        $second->notes = isset($request['Notes']) ? substr(trim($request['Notes']), 0, 400) : '';
        $second->updated_at = date('Y-m-d H:i:s');
        $second->save();
        // Look up the parent recommendation to find the recipient whose
        // caches need busting.
        $parent = new yapo($this->db, DB_PREFIX . 'recommendations');
        $parent->clear();
        $parent->recommendations_id = $second->recommendations_id;
        if ($parent->find()) {
            $this->bust_player_award_recs_cache((int)$parent->mundane_id);
        }
        return Success($second->recommendation_seconds_id);
    }

    /**
     * Withdraw (soft-delete) a second. Allowed by the supporter or by anyone with park-level
     * recommendation-delete authority on the recipient.
     */
    public function WithdrawSecond($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (!valid_id($request['RecommendationSecondsId'])) {
            return InvalidParameter('Invalid second.');
        }

        $second = new yapo($this->db, DB_PREFIX . 'recommendation_seconds');
        $second->clear();
        $second->recommendation_seconds_id = $request['RecommendationSecondsId'];
        if (!$second->find() || $second->deleted_at) {
            return InvalidParameter('Second not found.');
        }

        // Load parent rec up-front — needed both for the admin-authority check
        // and for the audit row (recipient's mundane_id).
        $parent = new yapo($this->db, DB_PREFIX . 'recommendations');
        $parent->clear();
        $parent->recommendations_id = $second->recommendations_id;
        $parent_found = $parent->find();

        $can_withdraw = ((int)$second->supporter_mundane_id === (int)$mundane_id);
        if (!$can_withdraw && $parent_found) {
            $recipientInfo = $this->player_info($parent->mundane_id);
            if (Ork3::$Lib->authorization->HasAuthority($mundane_id, AUTH_PARK, $recipientInfo['ParkId'], AUTH_CREATE)) {
                $can_withdraw = true;
            }
        }
        if (!$can_withdraw) {
            return InvalidParameter('Only the supporter or an admin may withdraw a second.');
        }

        $supporter_id = (int)$second->supporter_mundane_id;
        $rec_id       = (int)$second->recommendations_id;
        $second_id    = (int)$second->recommendation_seconds_id;
        $second->deleted_at = date('Y-m-d H:i:s');
        $second->deleted_by = $mundane_id;
        $second->save();
        if ($parent_found) {
            $this->bust_player_award_recs_cache((int)$parent->mundane_id);
        }
        $this->audit_second_change(__FUNCTION__, [
            'RecommendationsId'        => $rec_id,
            'RecommendationSecondsId'  => $second_id,
            'SupporterMundaneId'       => $supporter_id,
            'AwardId'                  => $parent_found ? (int)$parent->award_id : 0,
            'KingdomAwardId'           => $parent_found ? (int)$parent->kingdomaward_id : 0,
            'Rank'                     => $parent_found ? (int)$parent->rank : 0,
        ], $parent_found ? (int)$parent->mundane_id : 0, $mundane_id);
        return Success('Second withdrawn.');
    }

    /**
     * Allow the originator of a recommendation to edit their own reason text.
     * Recipients and admins are intentionally NOT allowed here — admins delete and re-create
     * via existing tools.
     */
    public function EditAwardRecommendationReason($request)
    {
        if (($mundane_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'])) == 0) {
            return NoAuthorization();
        }

        if (!valid_id($request['RecommendationsId'])) {
            return InvalidParameter('Invalid recommendation.');
        }

        $awardRec = new yapo($this->db, DB_PREFIX . 'recommendations');
        $awardRec->clear();
        $awardRec->recommendations_id = $request['RecommendationsId'];
        if (!$awardRec->find() || $awardRec->deleted_at) {
            return InvalidParameter('Recommendation not found.');
        }
        if ((int)$awardRec->recommended_by_id !== (int)$mundane_id) {
            return InvalidParameter('Only the original recommender may edit the reason.');
        }

        $awardRec->reason = isset($request['Reason']) ? substr(trim($request['Reason']), 0, 400) : '';
        $awardRec->save();
        $this->bust_player_award_recs_cache((int)$awardRec->mundane_id);
        return Success('Reason updated.');
    }

    /**
     * For a list of recommendation IDs, return active seconds grouped by recommendations_id.
     * Each entry includes the supporter's mundane_id, persona, notes, created_at, and updated_at.
     * The viewer_id is used to compute the IsMine flag and (caller) for masking decisions.
     */
    public function GetSecondsForRecommendations($recommendation_ids, $viewer_id)
    {
        if (!is_array($recommendation_ids) || count($recommendation_ids) === 0) {
            return array();
        }
        $ids = array();
        foreach ($recommendation_ids as $rid) {
            $rid = (int)$rid;
            if ($rid > 0) {
                $ids[] = $rid;
            }
        }
        if (count($ids) === 0) {
            return array();
        }
        $idList = implode(',', $ids);
        $viewer_id = (int)$viewer_id;
        $this->db->Clear();
        $sql = "SELECT s.recommendation_seconds_id, s.recommendations_id, s.supporter_mundane_id, s.notes, s.created_at, s.updated_at,
			m.persona AS supporter_persona
			FROM " . DB_PREFIX . "recommendation_seconds s
			LEFT JOIN " . DB_PREFIX . "mundane m ON m.mundane_id = s.supporter_mundane_id
			WHERE s.recommendations_id IN ($idList) AND s.deleted_at IS NULL
			ORDER BY s.recommendations_id, s.created_at ASC";
        $r = $this->db->query($sql);
        $out = array();
        if ($r !== false && $r->size() > 0) {
            while ($r->next()) {
                $rid = (int)$r->recommendations_id;
                if (!isset($out[$rid])) {
                    $out[$rid] = array();
                }
                $out[$rid][] = array(
                    'RecommendationSecondsId' => (int)$r->recommendation_seconds_id,
                    'SupporterMundaneId' => (int)$r->supporter_mundane_id,
                    'SupporterName' => $r->supporter_persona,
                    'Notes' => $r->notes,
                    'CreatedAt' => $r->created_at,
                    'UpdatedAt' => $r->updated_at,
                    'IsMine' => ((int)$r->supporter_mundane_id === $viewer_id),
                );
            }
        }
        return $out;
    }

    public function GetCustomMilestones($mundane_id)
    {
        $milestones = new yapo($this->db, DB_PREFIX . 'player_milestones');
        $milestones->clear();
        $milestones->mundane_id = (int)$mundane_id;
        $results = array();
        if ($milestones->find()) {
            do {
                $results[] = array(
                    'MilestoneId' => $milestones->milestone_id,
                    'MundaneId' => $milestones->mundane_id,
                    'Icon' => $milestones->icon,
                    'Description' => $milestones->description,
                    'MilestoneDate' => $milestones->milestone_date,
                );
            } while ($milestones->next());
        }
        return $results;
    }

    public function AddCustomMilestone($request)
    {
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($requester_id) || (int)$requester_id !== (int)$request['MundaneId']) {
            return NoAuthorization();
        }
        require_once(__DIR__ . '/class.ProfanityFilter.php');
        $pf = new ProfanityFilter();
        if ($pf->containsProfanity(trim($request['Description'] ?? ''))) {
            return InvalidParameter('Description', ProfanityFilter::ERROR_MESSAGE);
        }
        $milestones = new yapo($this->db, DB_PREFIX . 'player_milestones');
        $milestones->clear();
        $milestones->mundane_id = (int)$request['MundaneId'];
        $icon = trim($request['Icon']) ?: 'fa-star';
        if (!preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
            $icon = 'fa-star';
        }
        $milestones->icon = $icon;
        $milestones->description = trim($request['Description']);
        $milestones->milestone_date = date('Y-m-d', strtotime($request['MilestoneDate']));
        $milestones->save();
        return Success($milestones->milestone_id);
    }

    public function UpdateCustomMilestone($request)
    {
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($requester_id) || (int)$requester_id !== (int)$request['MundaneId']) {
            return NoAuthorization();
        }
        require_once(__DIR__ . '/class.ProfanityFilter.php');
        $pf = new ProfanityFilter();
        $desc = trim($request['Description'] ?? '');
        if ($desc !== '' && $pf->containsProfanity($desc)) {
            return InvalidParameter('Description', ProfanityFilter::ERROR_MESSAGE);
        }
        $milestones = new yapo($this->db, DB_PREFIX . 'player_milestones');
        $milestones->clear();
        $milestones->milestone_id = (int)$request['MilestoneId'];
        $milestones->mundane_id = (int)$request['MundaneId'];
        if (!$milestones->find()) {
            return InvalidParameter('Milestone not found.');
        }
        $icon = trim($request['Icon']);
        if ($icon && !preg_match('/^fa-[a-z0-9-]+$/', $icon)) {
            $icon = '';
        }
        $milestones->icon = $icon ?: $milestones->icon;
        $milestones->description = trim($request['Description']) ?: $milestones->description;
        $milestones->milestone_date = !empty($request['MilestoneDate']) ? date('Y-m-d', strtotime($request['MilestoneDate'])) : $milestones->milestone_date;
        $milestones->save();
        return Success($milestones->milestone_id);
    }

    public function DeleteCustomMilestone($request)
    {
        $requester_id = Ork3::$Lib->authorization->IsAuthorized($request['Token']);
        if (!valid_id($requester_id) || (int)$requester_id !== (int)$request['MundaneId']) {
            return NoAuthorization();
        }
        $milestones = new yapo($this->db, DB_PREFIX . 'player_milestones');
        $milestones->clear();
        $milestones->milestone_id = (int)$request['MilestoneId'];
        $milestones->mundane_id = (int)$request['MundaneId'];
        if (!$milestones->find()) {
            return InvalidParameter('Milestone not found.');
        }
        $milestones->delete();
        return Success();
    }

    public function get_latest_attendance_date($mundane_id)
    {
        $key = Ork3::$Lib->ghettocache->key(array((int)$mundane_id));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }
        $sql = "select max(date) as latest_date from " . DB_PREFIX . "attendance where mundane_id = " . (int)$mundane_id;
        $r = $this->db->query($sql);
        if ($r === false || $r->size() == 0) {
            return null;
        }
        $r->next();
        $date = $r->latest_date;
        $out = $date ? date('Y-m-d', strtotime($date)) : null;
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $out);
    }

    // Earliest valid attendance credit date — floors at 1988-01-01 (Amtgard's
    // founding year) to filter out corrupt/zero dates from legacy imports.
    public function get_earliest_attendance_date($mundane_id)
    {
        $key = Ork3::$Lib->ghettocache->key(array((int)$mundane_id));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }
        $sql = "select min(date) as earliest_date from " . DB_PREFIX . "attendance
		        where mundane_id = " . (int)$mundane_id . "
		          and date is not null
		          and date >= '1988-01-01'";
        $r = $this->db->query($sql);
        if ($r === false || $r->size() == 0) {
            return null;
        }
        $r->next();
        $date = $r->earliest_date;
        $out = $date ? date('Y-m-d', strtotime($date)) : null;
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $out);
    }

    public function GetDietaryPreferences($mundane_id)
    {
        $mundane_id = (int)$mundane_id;
        if (!$mundane_id) {
            return null;
        }
        $sql = "SELECT * FROM " . DB_PREFIX . "mundane_dietary WHERE mundane_id = $mundane_id LIMIT 1";
        $r = $this->db->query($sql);
        if ($r === false || $r->size() == 0) {
            return $this->_dietary_defaults($mundane_id);
        }
        $r->next();
        return $this->_dietary_row($r);
    }

    public function SaveDietaryPreferences($mundane_id, $data)
    {
        $mundane_id = (int)$mundane_id;
        if (!$mundane_id) {
            return false;
        }
        $b = function ($k) use ($data) {
            return (int)!empty($data[$k]);
        };
        $a = function ($k) use ($data) {
            return max(0, min(2, (int)($data[$k] ?? 0)));
        };
        $sql = "INSERT INTO " . DB_PREFIX . "mundane_dietary
			(`mundane_id`, `is_anonymous`, `no_restrictions`,
			 `diet_vegetarian`, `diet_vegan`, `diet_halal`, `diet_kosher`, `diet_keto`, `diet_paleo`,
			 `restrict_dairy`, `restrict_eggs`, `restrict_fish`, `restrict_honey`, `restrict_poultry`, `restrict_beef`, `restrict_pork`, `restrict_shellfish`,
			 `allergen_milk`, `allergen_eggs`, `allergen_fish`, `allergen_shellfish`, `allergen_treenuts`, `allergen_peanuts`,
			 `allergen_wheat`, `allergen_soy`, `allergen_sesame`, `allergen_garlic`, `allergen_gluten`, `allergen_onion`, `allergen_mushroom`,
			 `allergen_corn`, `allergen_coconut`, `allergen_cocoa`, `allergen_nightshades`)
			VALUES
			($mundane_id, {$b('IsAnonymous')}, {$b('NoRestrictions')},
			 {$b('DietVegetarian')}, {$b('DietVegan')}, {$b('DietHalal')}, {$b('DietKosher')}, {$b('DietKeto')}, {$b('DietPaleo')},
			 {$b('RestrictDairy')}, {$b('RestrictEggs')}, {$b('RestrictFish')}, {$b('RestrictHoney')}, {$b('RestrictPoultry')}, {$b('RestrictBeef')}, {$b('RestrictPork')}, {$b('RestrictShellfish')},
			 {$a('AllergenMilk')}, {$a('AllergenEggs')}, {$a('AllergenFish')}, {$a('AllergenShellfish')}, {$a('AllergenTreenuts')}, {$a('AllergenPeanuts')},
			 {$a('AllergenWheat')}, {$a('AllergenSoy')}, {$a('AllergenSesame')}, {$a('AllergenGarlic')}, {$a('AllergenGluten')}, {$a('AllergenOnion')}, {$a('AllergenMushroom')},
			 {$a('AllergenCorn')}, {$a('AllergenCoconut')}, {$a('AllergenCocoa')}, {$a('AllergenNightshades')})
			ON DUPLICATE KEY UPDATE
			 `is_anonymous`       = {$b('IsAnonymous')},
			 `no_restrictions`    = {$b('NoRestrictions')},
			 `diet_vegetarian`    = {$b('DietVegetarian')},  `diet_vegan`    = {$b('DietVegan')},
			 `diet_halal`         = {$b('DietHalal')},       `diet_kosher`   = {$b('DietKosher')},
			 `diet_keto`          = {$b('DietKeto')},        `diet_paleo`    = {$b('DietPaleo')},
			 `restrict_dairy`     = {$b('RestrictDairy')},   `restrict_eggs` = {$b('RestrictEggs')},
			 `restrict_fish`      = {$b('RestrictFish')},    `restrict_honey`= {$b('RestrictHoney')},
			 `restrict_poultry`   = {$b('RestrictPoultry')}, `restrict_beef`     = {$b('RestrictBeef')}, `restrict_pork` = {$b('RestrictPork')},
			 `restrict_shellfish` = {$b('RestrictShellfish')},
			 `allergen_milk`      = {$a('AllergenMilk')},    `allergen_eggs` = {$a('AllergenEggs')},
			 `allergen_fish`      = {$a('AllergenFish')},    `allergen_shellfish`= {$a('AllergenShellfish')},
			 `allergen_treenuts`  = {$a('AllergenTreenuts')},`allergen_peanuts`  = {$a('AllergenPeanuts')},
			 `allergen_wheat`     = {$a('AllergenWheat')},   `allergen_soy`  = {$a('AllergenSoy')},
			 `allergen_sesame`    = {$a('AllergenSesame')},  `allergen_garlic`   = {$a('AllergenGarlic')},
			 `allergen_gluten`    = {$a('AllergenGluten')},  `allergen_onion`    = {$a('AllergenOnion')},   `allergen_mushroom` = {$a('AllergenMushroom')},
			 `allergen_corn`        = {$a('AllergenCorn')},    `allergen_coconut`    = {$a('AllergenCoconut')},
			 `allergen_cocoa`       = {$a('AllergenCocoa')},   `allergen_nightshades`= {$a('AllergenNightshades')}";
        $this->db->Clear();
        $this->db->Execute($sql);
        return true;
    }

    private function _dietary_defaults($mundane_id)
    {
        return [
            'MundaneId' => (int)$mundane_id, 'IsAnonymous' => 1, 'NoRestrictions' => 0,
            'DietVegetarian' => 0, 'DietVegan' => 0, 'DietHalal' => 0, 'DietKosher' => 0, 'DietKeto' => 0, 'DietPaleo' => 0,
            'RestrictDairy' => 0, 'RestrictEggs' => 0, 'RestrictFish' => 0, 'RestrictHoney' => 0,
            'RestrictPoultry' => 0, 'RestrictBeef' => 0, 'RestrictPork' => 0, 'RestrictShellfish' => 0,
            'AllergenMilk' => 0, 'AllergenEggs' => 0, 'AllergenFish' => 0, 'AllergenShellfish' => 0,
            'AllergenTreenuts' => 0, 'AllergenPeanuts' => 0, 'AllergenWheat' => 0, 'AllergenSoy' => 0,
            'AllergenSesame' => 0, 'AllergenGarlic' => 0, 'AllergenGluten' => 0, 'AllergenOnion' => 0, 'AllergenMushroom' => 0,
            'AllergenCorn' => 0, 'AllergenCoconut' => 0, 'AllergenCocoa' => 0, 'AllergenNightshades' => 0,
        ];
    }

    private function _dietary_row($r)
    {
        return [
            'MundaneId' => (int)$r->mundane_id, 'IsAnonymous' => (int)$r->is_anonymous, 'NoRestrictions' => (int)$r->no_restrictions,
            'DietVegetarian' => (int)$r->diet_vegetarian, 'DietVegan'    => (int)$r->diet_vegan,
            'DietHalal'      => (int)$r->diet_halal,      'DietKosher'   => (int)$r->diet_kosher,
            'DietKeto'       => (int)$r->diet_keto,        'DietPaleo'   => (int)$r->diet_paleo,
            'RestrictDairy'     => (int)$r->restrict_dairy,    'RestrictEggs'     => (int)$r->restrict_eggs,
            'RestrictFish'      => (int)$r->restrict_fish,     'RestrictHoney'    => (int)$r->restrict_honey,
            'RestrictPoultry'   => (int)$r->restrict_poultry,  'RestrictBeef'     => (int)$r->restrict_beef,     'RestrictPork' => (int)$r->restrict_pork,
            'RestrictShellfish' => (int)$r->restrict_shellfish,
            'AllergenMilk'      => (int)$r->allergen_milk,     'AllergenEggs'     => (int)$r->allergen_eggs,
            'AllergenFish'      => (int)$r->allergen_fish,     'AllergenShellfish' => (int)$r->allergen_shellfish,
            'AllergenTreenuts'  => (int)$r->allergen_treenuts, 'AllergenPeanuts'  => (int)$r->allergen_peanuts,
            'AllergenWheat'     => (int)$r->allergen_wheat,    'AllergenSoy'      => (int)$r->allergen_soy,
            'AllergenSesame'    => (int)$r->allergen_sesame,   'AllergenGarlic'   => (int)$r->allergen_garlic,
            'AllergenGluten'    => (int)$r->allergen_gluten,   'AllergenOnion'    => (int)$r->allergen_onion,    'AllergenMushroom' => (int)$r->allergen_mushroom,
            'AllergenCorn'        => (int)$r->allergen_corn,        'AllergenCoconut'     => (int)$r->allergen_coconut,
            'AllergenCocoa'       => (int)$r->allergen_cocoa,       'AllergenNightshades' => (int)$r->allergen_nightshades,
        ];
    }

    // Earliest valid attendance credit date at a specific park — used as a
    // fallback for "Park Member Since" when the mundane record lacks one.
    public function get_earliest_park_attendance_date($mundane_id, $park_id)
    {
        if (!(int)$mundane_id || !(int)$park_id) {
            return null;
        }
        $key = Ork3::$Lib->ghettocache->key(array((int)$mundane_id, (int)$park_id));
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.' . __FUNCTION__, $key, 300)) !== false) {
            return $cache;
        }
        $sql = "select min(date) as earliest_date from " . DB_PREFIX . "attendance
		        where mundane_id = " . (int)$mundane_id . "
		          and park_id = " . (int)$park_id . "
		          and date is not null
		          and date >= '1988-01-01'";
        $r = $this->db->query($sql);
        if ($r === false || $r->size() == 0) {
            return null;
        }
        $r->next();
        $date = $r->earliest_date;
        $out = $date ? date('Y-m-d', strtotime($date)) : null;
        return Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $out);
    }

}
