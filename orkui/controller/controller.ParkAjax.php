<?php

class Controller_ParkAjax extends Controller
{
    public function park($p = null)
    {
        header('Content-Type: application/json');
        $parts   = explode('/', $p ?? '');
        $park_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action  = $parts[1] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        if (!valid_id($park_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid park ID']);
            exit;
        }

        $this->load_model('Park');

        if ($action === 'addparkday') {
            $recurrence = trim($_POST['Recurrence'] ?? '');
            $time       = trim($_POST['Time']       ?? '');
            if (!strlen($recurrence)) {
                echo json_encode(['status' => 1, 'error' => 'Recurrence is required.']);
                exit;
            }
            if (!strlen($time)) {
                echo json_encode(['status' => 1, 'error' => 'Time is required.']);
                exit;
            }
            if ($recurrence === 'every-x-weeks' && !strlen(trim($_POST['StartDate'] ?? ''))) {
                echo json_encode(['status' => 1, 'error' => 'A start date is required for the "every X weeks" cadence.']);
                exit;
            }
            $online = (($_POST['Online'] ?? '0') === '1') ? 1 : 0;
            $altLoc = (!$online && (($_POST['AlternateLocation'] ?? '0') === '1')) ? 1 : 0;
            $r = $this->Park->add_park_day([
                'Token'             => $this->session->token,
                'ParkId'            => $park_id,
                'Recurrence'        => $recurrence,
                'WeekDay'           => trim($_POST['WeekDay']     ?? ''),
                'WeekOfMonth'       => (int)($_POST['WeekOfMonth'] ?? 0),
                'MonthDay'          => (int)($_POST['MonthDay']    ?? 0),
                'StartDate'         => trim($_POST['StartDate']   ?? ''),
                'WeekInterval'      => (int)($_POST['WeekInterval'] ?? 0),
                'Time'              => $time,
                'Purpose'           => trim($_POST['Purpose']     ?? 'other'),
                'Description'       => trim($_POST['Description'] ?? ''),
                'Online'            => $online,
                'AlternateLocation' => $altLoc,
                'Address'           => trim($_POST['Address']     ?? ''),
                'City'              => trim($_POST['City']        ?? ''),
                'Province'          => trim($_POST['Province']    ?? ''),
                'PostalCode'        => trim($_POST['PostalCode']  ?? ''),
                'MapUrl'            => trim($_POST['MapUrl']      ?? ''),
                'LocationUrl'       => trim($_POST['LocationUrl'] ?? ''),
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'editparkday') {
            $parkDayId  = (int)($_POST['ParkDayId'] ?? 0);
            $recurrence = trim($_POST['Recurrence'] ?? '');
            $time       = trim($_POST['Time']       ?? '');
            if (!valid_id($parkDayId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid park day ID.']);
                exit;
            }
            if (!strlen($recurrence)) {
                echo json_encode(['status' => 1, 'error' => 'Recurrence is required.']);
                exit;
            }
            if (!strlen($time)) {
                echo json_encode(['status' => 1, 'error' => 'Time is required.']);
                exit;
            }
            if ($recurrence === 'every-x-weeks' && !strlen(trim($_POST['StartDate'] ?? ''))) {
                echo json_encode(['status' => 1, 'error' => 'A start date is required for the "every X weeks" cadence.']);
                exit;
            }
            $online = (($_POST['Online'] ?? '0') === '1') ? 1 : 0;
            $altLoc = (!$online && (($_POST['AlternateLocation'] ?? '0') === '1')) ? 1 : 0;
            $r = $this->Park->edit_park_day([
                'Token'             => $this->session->token,
                'ParkDayId'         => $parkDayId,
                'Recurrence'        => $recurrence,
                'WeekDay'           => trim($_POST['WeekDay']     ?? ''),
                'WeekOfMonth'       => (int)($_POST['WeekOfMonth'] ?? 0),
                'MonthDay'          => (int)($_POST['MonthDay']    ?? 0),
                'StartDate'         => trim($_POST['StartDate']   ?? ''),
                'WeekInterval'      => (int)($_POST['WeekInterval'] ?? 0),
                'Time'              => $time,
                'Purpose'           => trim($_POST['Purpose']     ?? 'other'),
                'Description'       => trim($_POST['Description'] ?? ''),
                'Online'            => $online,
                'AlternateLocation' => $altLoc,
                'Address'           => trim($_POST['Address']     ?? ''),
                'City'              => trim($_POST['City']        ?? ''),
                'Province'          => trim($_POST['Province']    ?? ''),
                'PostalCode'        => trim($_POST['PostalCode']  ?? ''),
                'MapUrl'            => trim($_POST['MapUrl']      ?? ''),
                'LocationUrl'       => trim($_POST['LocationUrl'] ?? ''),
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'deleteparkday') {
            $parkDayId = (int)($_POST['ParkDayId'] ?? 0);
            if (!valid_id($parkDayId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid park day ID.']);
                exit;
            }
            $r = $this->Park->delete_park_day([
                'Token'     => $this->session->token,
                'ParkDayId' => $parkDayId,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'setdetails') {
            $r = $this->Park->set_park_details([
                'Token'       => $this->session->token,
                'ParkId'      => $park_id,
                'Url'         => trim($_POST['Url']         ?? ''),
                'Address'     => trim($_POST['Address']     ?? ''),
                'City'        => trim($_POST['City']        ?? ''),
                'Province'    => trim($_POST['Province']    ?? ''),
                'PostalCode'  => trim($_POST['PostalCode']  ?? ''),
                'MapUrl'      => trim($_POST['MapUrl']      ?? ''),
                'Description' => trim($_POST['Description'] ?? ''),
                'Directions'  => trim($_POST['Directions']  ?? ''),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'playersearch') {
            $q                = trim($_GET['q']               ?? '');
            $scope            = trim($_GET['scope']           ?? 'own'); // 'own' | 'exclude' | 'all'
            $prioritize       = !empty($_GET['prioritize']);
            $include_inactive  = !empty($_GET['include_inactive']);
            $include_suspended = !empty($_GET['include_suspended']);
            if (strlen($q) < 2) {
                echo json_encode([]);
                exit;
            }

            $scopeKey = 'park_own';
            if ($scope === 'exclude') {
                $scopeKey = 'park_exclude';
            } elseif ($scope === 'all') {
                $scopeKey = 'park_all';
            }

            $this->load_model('Search');
            $results = $this->Search->scoped_player_search([
                'Query'            => $q,
                'Scope'            => $scopeKey,
                'ParkId'           => (int)$park_id,
                'IncludeInactive'  => $include_inactive,
                'IncludeSuspended' => $include_suspended,
                'Prioritize'       => $prioritize,
                'Limit'            => 15,
                'Format'           => 'kingdom',
            ]);

            echo json_encode($results);

        } elseif ($action === 'setheraldry') {
            if (empty($_FILES['Heraldry']['tmp_name']) || !is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'No image file received.']);
                exit;
            }
            $allowed = ['image/png', 'image/jpeg', 'image/gif'];
            if (!in_array($_FILES['Heraldry']['type'], $allowed)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid image type. Use PNG, JPG, or GIF.']);
                exit;
            }
            $heraldryData = base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name']));
            $r = $this->Park->SetParkDetails([
                'Token'            => $this->session->token,
                'ParkId'           => $park_id,
                'Heraldry'         => $heraldryData,
                'HeraldryMimeType' => $_FILES['Heraldry']['type'],
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'removeheraldry') {
            $r = $this->Park->RemoveParkHeraldry([
                'Token'  => $this->session->token,
                'ParkId' => $park_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'resetwaivers') {
            $this->load_model('Player');
            $r = $this->Player->reset_waivers([
                'Token'  => $this->session->token,
                'ParkId' => $park_id,
            ]);
            if ($r['Status'] == 5) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
            } elseif ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
            } else {
                echo json_encode(['status' => 0, 'message' => $r['Detail'] ?? 'Waivers reset.']);
            }

        } elseif ($action === 'moveplayer') {
            $this->load_model('Player');
            $mundane_id   = (int)($_POST['MundaneId']  ?? 0);
            $dest_park_id = (int)($_POST['DestParkId'] ?? 0);
            if (!valid_id($mundane_id)) {
                echo json_encode(['status' => 1, 'error' => 'Select a player.']);
                exit;
            }
            if (!valid_id($dest_park_id)) {
                echo json_encode(['status' => 1, 'error' => 'Select a destination park.']);
                exit;
            }
            $r = $this->Player->move_player(['Token' => $this->session->token, 'MundaneId' => $mundane_id, 'ParkId' => $dest_park_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'addrecommendation') {
            if (!isset($this->session->user_id)) {
                echo json_encode(['status' => 1, 'error' => 'You must be logged in to submit a recommendation.']);
                exit;
            }
            $this->load_model('Player');
            $mundane_id   = (int)($_POST['MundaneId']       ?? 0);
            $award_id     = (int)($_POST['KingdomAwardId']  ?? 0);
            $rank         = (int)($_POST['Rank']            ?? 0);
            $zodiacMonth  = (int)($_POST['ZodiacMonth']     ?? 0);
            $reason       = trim($_POST['Reason']           ?? '');
            if (!valid_id($mundane_id)) {
                echo json_encode(['status' => 1, 'error' => 'Please select a player.']);
                exit;
            }
            if (!valid_id($award_id)) {
                echo json_encode(['status' => 1, 'error' => 'Please select an award.']);
                exit;
            }
            if (!$reason) {
                echo json_encode(['status' => 1, 'error' => 'Please enter a reason.']);
                exit;
            }
            $r = $this->Player->add_player_recommendation([
                'Token'          => $this->session->token,
                'MundaneId'      => $mundane_id,
                'KingdomAwardId' => $award_id,
                'Rank'           => $rank > 0 ? $rank : null,
                'ZodiacMonth'    => $zodiacMonth,
                'GivenById'      => $this->session->user_id,
                'Reason'         => $reason,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'dismissrecommendation') {
            $this->load_model('Player');
            $rec_id = (int)($_POST['RecommendationsId'] ?? 0);
            if (!valid_id($rec_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid recommendation.']);
                exit;
            }
            $r = $this->Player->delete_player_recommendation([
                'Token'             => $this->session->token,
                'RecommendationsId' => $rec_id,
                'RequestedBy'       => $this->session->user_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'deletedrecommendations') {
            // Same permission as the delete action a few branches up: reviewing and
            // restoring what was deleted is the same capability as deleting it.
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'player.recommendation.manage', 'park', $park_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $this->load_model('Reports');
            $recs = $this->Reports->deleted_recommended_awards(['ParkId' => $park_id, 'KingdomId' => 0, 'PlayerId' => 0]);
            echo json_encode(['status' => 0, 'recommendations' => is_array($recs) ? array_values($recs) : []]);

        } elseif ($action === 'restorerecommendation') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'player.recommendation.manage', 'park', $park_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $this->load_model('Player');
            $rec_id = (int)($_POST['RecommendationsId'] ?? 0);
            if (!valid_id($rec_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid recommendation.']);
                exit;
            }
            $r = $this->Player->restore_player_recommendation([
                'Token'             => $this->session->token,
                'RecommendationsId' => $rec_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'addauth') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'park.auth.manage', 'park', $park_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $mid  = (int)($_POST['MundaneId'] ?? 0);
            // Scoped grants only accept create / edit. The legacy 'admin' role at
            // park scope is no longer granted from the UI — system-wide admin is
            // managed on its own page and only ever issued unscoped.
            $role = in_array($_POST['Role'] ?? '', ['create','edit']) ? $_POST['Role'] : 'create';
            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }
            $this->load_model('Authorization');
            $r = $this->Authorization->add_auth([
                'Token'     => $this->session->token,
                'MundaneId' => $mid,
                'Type'      => AUTH_PARK,
                'Id'        => $park_id,
                'Role'      => $role,
            ]);
            if ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . (isset($r['Detail']) && $r['Detail'] !== '' ? ': ' . $r['Detail'] : '')]);
                exit;
            }
            $authId = (int)($r['Detail'] ?? 0);
            $this->load_model('Player');
            $persona = $this->Player->get_persona($mid);
            $this->Authorization->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_PARK, 'Id' => $park_id, 'Role' => $role], 'Player', $mid, null, [
                'authorization_id' => $authId,
                'mundane_id'       => $mid,
                'park_id'          => (int)$park_id,
                'kingdom_id'       => 0,
                'event_id'         => 0,
                'unit_id'          => 0,
                'role'             => $role,
            ]);
            echo json_encode(['status' => 0, 'authId' => $authId, 'persona' => $persona]);

        } elseif ($action === 'removeauth') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'park.auth.manage', 'park', $park_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $this->load_model('Authorization');
            $r = $this->Authorization->del_auth([
                'Token'           => $this->session->token,
                'AuthorizationId' => (int)($_POST['AuthorizationId'] ?? 0),
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'createtournament') {
            $this->load_model('Tournament');
            $name       = trim($_POST['Name']        ?? '');
            $when       = trim($_POST['When']        ?? '');
            $desc       = trim($_POST['Description'] ?? '');
            $url        = trim($_POST['Url']         ?? '');
            $kingdom_id = (int)($_POST['KingdomId']  ?? 0);
            $ecd_id     = (int)($_POST['EventCalendarDetailId'] ?? 0);

            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Tournament name is required.']);
                exit;
            }
            if (!strlen($when)) {
                echo json_encode(['status' => 1, 'error' => 'Tournament date is required.']);
                exit;
            }

            $r = $this->Tournament->create_tournament([
                'Token'                 => $this->session->token,
                'Name'                  => $name,
                'Description'           => $desc,
                'Url'                   => $url,
                'When'                  => $when,
                'KingdomId'             => $kingdom_id,
                'ParkId'                => $park_id,
                'EventCalendarDetailId' => $ecd_id,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0, 'tournamentId' => (int)($r['Detail'] ?? 0)])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'officerhistory') {
            $role = trim($_GET['Role'] ?? '');
            $r = $this->Park->get_officer_history($park_id, strlen($role) > 0 ? $role : null);
            echo json_encode([
                'status'  => 0,
                'history' => $r['History'] ?? [],
            ]);

        } elseif ($action === 'selfreg_link') {
            $this->load_model('Player');
            $r = $this->Player->create_selfreg_link([
                'Token'  => $this->session->token,
                'ParkId' => $park_id,
            ]);
            if ($r['Status'] == 0) {
                $detail = $r['Detail'];
                echo json_encode([
                    'status'            => 0,
                    'token'             => $detail['token'],
                    'expires_at'        => $detail['expires_at'],
                    'seconds_remaining' => $detail['seconds_remaining'],
                ]);
            } else {
                echo json_encode([
                    'status' => $r['Status'],
                    'error'  => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': '),
                ]);
            }

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function kingdom($p = null)
    {
        header('Content-Type: application/json');
        $parts      = explode('/', $p ?? '');
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action     = $parts[1] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        if (!valid_id($kingdom_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
            exit;
        }

        if ($action === 'create') {
            $uid = (int)$this->session->user_id;
            // Park creation is GLOBAL ADMIN ONLY -- parks sign a contract with
            // Amtgard International before creation, so this gate must mirror
            // Park::CreatePark and Controller_Kingdom::$CanAddPark exactly.
            if (!$this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Park creation is restricted to ORK administrators.']);
                exit;
            }
            $this->load_model('Park');
            $name    = trim($_POST['Name'] ?? '');
            $abbr    = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));
            $titleId = (int)($_POST['ParkTitleId'] ?? 0);

            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Park must have a name.']);
                exit;
            }
            if (!strlen($abbr)) {
                echo json_encode(['status' => 1, 'error' => 'Park must have an abbreviation.']);
                exit;
            }
            if (!valid_id($titleId)) {
                echo json_encode(['status' => 1, 'error' => 'Parks must have a title.']);
                exit;
            }

            $r = $this->Park->create_park([
                'Token'        => $this->session->token,
                'Name'         => $name,
                'Abbreviation' => $abbr,
                'KingdomId'    => $kingdom_id,
                'ParkTitleId'  => $titleId,
            ]);

            if ($r['Status'] == 0) {
                echo json_encode(['status' => 0, 'parkId' => (int)($r['Detail'] ?? 0)]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
            }
        } elseif ($action === 'editpark') {
            // Two different operations arrive in one POST from the Edit Park modal,
            // exactly as they do in KingdomAjax::updateparks:
            //
            //   name / abbreviation / title  -> SetParkDetails  ('park.details.edit')
            //   the Active toggle            -> RetirePark / RestorePark
            //                                   ('kingdom.park.retire', danger-audited)
            //
            // They are gated separately because they are not the same permission.
            // Sending Active through SetParkDetails let anyone who could rename a park
            // also retire one, through a plain LOG_EDIT and with no danger-audit row.
            $uid = (int)$this->session->user_id;
            $this->load_model('Park');
            $park_id = (int)($_POST['ParkId'] ?? 0);
            $name    = trim($_POST['Name'] ?? '');
            $abbr    = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));
            $titleId = (int)($_POST['ParkTitleId'] ?? 0);
            // Only treat Active as a requested change when the caller actually
            // sent the field. An absent key must never read as "retire this park".
            $active  = array_key_exists('Active', $_POST)
                ? (($_POST['Active'] === 'Active') ? 'Active' : 'Retired')
                : null;

            if (!valid_id($park_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid park ID.']);
                exit;
            }
            // Verify the park belongs to this kingdom
            $this->load_model('ParkProfile');
            if (!$this->ParkProfile->park_belongs_to_kingdom($park_id, $kingdom_id)) {
                echo json_encode(['status' => 1, 'error' => 'Park does not belong to this kingdom.']);
                exit;
            }
            // Editing a park's details is 'park.details.edit', the key SetParkDetails
            // itself accepts. It is NOT 'kingdom.auth.manage', which is the
            // authorization-row key and has nothing to say about park details.
            //
            // BOTH clauses are required, and they are NOT redundant -- they guard two
            // different gates inside the domain. Park::SetParkDetails has an outer gate at
            // class.Park.php:1143 (park scope), but the only fields this endpoint writes --
            // name, abbreviation, parktitle_id -- sit behind a SECOND, kingdom-scoped gate
            // at class.Park.php:1164. A park-scope-only holder passes the first and fails
            // the second, so SetParkDetails returns success having written none of the
            // three, and this endpoint reported {"status":0} -> "Park updated!" with
            // nothing saved. Verified live: a park-scope-only user renamed nothing and was
            // told it worked. Neither the RBAC cascade nor the legacy walk rescues this --
            // both resolve park -> kingdom, never kingdom -> park, so a park grant cannot
            // satisfy a kingdom-scope check by any route.
            if (!$this->Authorization->has_permission_or_authority($uid, 'park.details.edit', 'park', $park_id, AUTH_EDIT)
                || !$this->Authorization->has_permission_or_authority($uid, 'park.details.edit', 'kingdom', $kingdom_id, AUTH_EDIT)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized to edit this park.']);
                exit;
            }
            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Park must have a name.']);
                exit;
            }
            if (!strlen($abbr)) {
                echo json_encode(['status' => 1, 'error' => 'Park must have an abbreviation.']);
                exit;
            }
            if (!valid_id($titleId)) {
                echo json_encode(['status' => 1, 'error' => 'Parks must have a title.']);
                exit;
            }

            // Stored state, read once. Whether the Active toggle actually changed is
            // decided against the database, never against what the browser posted, so
            // an officer who may rename a park but not retire one is not denied for a
            // checkbox they never moved.
            $storedInfo   = (array)$this->Park->get_park_info($park_id);
            $storedActive = (($storedInfo['ParkInfo']['Active'] ?? '') === 'Active') ? 'Active' : 'Retired';

            $r = $this->Park->set_park_details([
                'Token'        => $this->session->token,
                'ParkId'       => $park_id,
                'Name'         => $name,
                'Abbreviation' => $abbr,
                'ParkTitleId'  => $titleId,
                // Deliberately the STORED value, not the submitted one. SetParkDetails
                // ignores Active; passing what is already on the row keeps this request
                // a no-op for that column. The real change is below.
                'Active'       => $storedActive,
            ]);

            if ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
                exit;
            }

            // The Active toggle: its own permission ('kingdom.park.retire'), its own
            // LOG_RETIRE / LOG_RESTORE entry and its own danger-audit row, all written
            // inside RetirePark / RestorePark.
            if ($active !== null && $active !== $storedActive) {
                $sr = ($active === 'Active')
                    ? $this->Park->RestorePark(['Token' => $this->session->token, 'ParkId' => $park_id])
                    : $this->Park->RetirePark(['Token' => $this->session->token, 'ParkId' => $park_id]);

                if (!isset($sr['Status']) || $sr['Status'] != 0) {
                    // The details saved; only the status change was refused, and the
                    // response has to say so rather than report a clean success.
                    echo json_encode([
                        'status' => (int)($sr['Status'] ?? 1),
                        'error'  => rtrim(($sr['Error'] ?? 'Error') . ': ' . ($sr['Detail'] ?? ''), ': ')
                            . ' The park\'s other details were saved.',
                    ]);
                    exit;
                }
            }

            echo json_encode(['status' => 0]);
        } elseif ($action === 'checkabbr') {
            $abbr      = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
            $excludeId = (int)($_POST['ExcludeParkId'] ?? 0);
            if (!strlen($abbr)) {
                echo json_encode(['status' => 0, 'taken' => false]);
                exit;
            }
            $this->load_model('ParkProfile');
            $taken = $this->ParkProfile->abbreviation_taken((int)$kingdom_id, $abbr, $excludeId);
            echo json_encode(['status' => 0, 'taken' => $taken]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }


    public function banner($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $params  = explode('/', $p ?? '');
        $park_id = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $action  = $params[1] ?? '';

        if (!valid_id($park_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Park ID.']);
            exit;
        }

        $this->load_model('Banner');
        $this->Banner->handle_ajax(
            'Park',
            $action,
            $park_id,
            $this->session->token,
            $_POST,
            $_FILES,
        );
    }

}
