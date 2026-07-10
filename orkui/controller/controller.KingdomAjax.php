<?php

class Controller_KingdomAjax extends Controller
{
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

        if ($action === 'setofficers') {
            $this->load_model('Kingdom');

            // Collect officer assignments: any POST key ending in "Id" with a valid int value
            $officers = [];
            foreach ($_POST as $key => $val) {
                if (preg_match('/^(.+)Id$/', $key, $m) && valid_id((int)$val)) {
                    $role = str_replace('_', ' ', $m[1]);
                    $officers[$role] = ['MundaneId' => (int)$val, 'Role' => $role];
                }
            }

            if (empty($officers)) {
                echo json_encode(['status' => 1, 'error' => 'No officer assignments provided.']);
                exit;
            }

            $results = $this->Kingdom->set_officers($this->session->token, $kingdom_id, $officers);
            $errors  = [];
            foreach ($results as $r) {
                if (isset($r['Status']) && $r['Status'] != 0) {
                    $errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
                }
            }

            if ($errors) {
                echo json_encode(['status' => 1, 'error' => implode('; ', $errors)]);
            } else {
                echo json_encode(['status' => 0]);
            }

        } elseif ($action === 'vacateofficer') {
            $this->load_model('Kingdom');
            $role = trim($_POST['Role'] ?? '');

            if (!strlen($role)) {
                echo json_encode(['status' => 1, 'error' => 'Role is required.']);
                exit;
            }

            $r = $this->Kingdom->vacate_officer($kingdom_id, $role, $this->session->token);
            if (!isset($r['Status']) || $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'setstatus') {
            if (!$this->Authorization->has_authority((int)$this->session->user_id, AUTH_ADMIN, 0, AUTH_ADMIN)) {
                echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
                exit;
            }
            $this->load_model('Kingdom');
            $active = trim($_POST['Active'] ?? '') === 'Active' ? 'Active' : 'Retired';
            $r = $active === 'Active'
                ? $this->Kingdom->RestoreKingdom(['Token' => $this->session->token, 'KingdomId' => $kingdom_id])
                : $this->Kingdom->RetireKingdom(['Token'  => $this->session->token, 'KingdomId' => $kingdom_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'active' => $active])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'setdetails') {
            $this->load_model('Kingdom');
            $name = trim($_POST['Name'] ?? '');
            $abbr = preg_replace('/[^A-Za-z0-9]/', '', trim($_POST['Abbreviation'] ?? ''));

            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Kingdom name is required.']);
                exit;
            }
            if (!strlen($abbr)) {
                echo json_encode(['status' => 1, 'error' => 'Abbreviation is required.']);
                exit;
            }

            $request = [
                'Token'        => $this->session->token,
                'KingdomId'    => $kingdom_id,
                'Name'         => $name,
                'Abbreviation' => $abbr,
                'Description'  => trim($_POST['Description'] ?? ''),
                'Url'          => trim($_POST['Url'] ?? ''),
            ];

            if (!empty($_FILES['Heraldry']['tmp_name']) && is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
                $allowed = ['image/png', 'image/jpeg', 'image/gif'];
                if (in_array($_FILES['Heraldry']['type'], $allowed)) {
                    $request['Heraldry']         = base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name']));
                    $request['HeraldryMimeType'] = $_FILES['Heraldry']['type'];
                }
            }

            $r = $this->Kingdom->set_kingdom_details($request);
            echo $r['Status'] == 0
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'setconfig') {
            $this->load_model('Kingdom');
            $configs = $_POST['Config'] ?? [];

            if (!is_array($configs) || empty($configs)) {
                echo json_encode(['status' => 1, 'error' => 'No configuration data provided.']);
                exit;
            }

            $configList = [];
            foreach ($configs as $configId => $value) {
                $configList[] = [
                    'Action'          => CFG_EDIT,
                    'ConfigurationId' => (int)$configId,
                    'Key'             => null,
                    'Value'           => (is_string($value) && trim($value) === '') ? null : $value,
                ];
            }

            $r = $this->Kingdom->set_kingdom_details([
                'Token'                => $this->session->token,
                'KingdomId'            => $kingdom_id,
                'KingdomConfiguration' => $configList,
            ]);
            echo $r['Status'] == 0
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'setparktitles') {
            $this->load_model('Kingdom');
            $titles  = $_POST['Title']             ?? [];
            $classes = $_POST['Class']             ?? [];
            $minAtts = $_POST['MinimumAttendance'] ?? [];
            $minCuts = $_POST['MinimumCutoff']     ?? [];
            $periods = $_POST['Period']            ?? [];
            $lengths = $_POST['Length']            ?? [];

            $edits = [];
            foreach ($titles as $id => $title) {
                $title = trim($title);
                if ($id === 'New' && !strlen($title)) {
                    continue;
                }
                $edits[] = [
                    'Action'            => ($id === 'New') ? CFG_ADD : CFG_EDIT,
                    'ParkTitleId'       => ($id === 'New') ? 0 : (int)$id,
                    'Title'             => $title,
                    'Class'             => (int)($classes[$id] ?? 0),
                    'MinimumAttendance' => (int)($minAtts[$id] ?? 0),
                    'MinimumCutoff'     => (int)($minCuts[$id] ?? 0),
                    'Period'            => $periods[$id]         ?? 'month',
                    'PeriodLength'      => (int)($lengths[$id]  ?? 1),
                ];
            }

            if (empty($edits)) {
                echo json_encode(['status' => 1, 'error' => 'No park title data provided.']);
                exit;
            }

            $r = $this->Kingdom->set_kingdom_parktitles([
                'Token'      => $this->session->token,
                'KingdomId'  => $kingdom_id,
                'ParkTitles' => $edits,
            ]);
            echo $r['Status'] == 0
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'deletetitle') {
            $this->load_model('Kingdom');
            $titleId = (int)($_POST['ParkTitleId'] ?? 0);

            if (!valid_id($titleId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid park title ID.']);
                exit;
            }

            $r = $this->Kingdom->set_kingdom_parktitles([
                'Token'      => $this->session->token,
                'KingdomId'  => $kingdom_id,
                'ParkTitles' => [['Action' => CFG_REMOVE, 'ParkTitleId' => $titleId]],
            ]);
            echo $r['Status'] == 0
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'setaward') {
            $this->load_model('Kingdom');
            $kawId   = (int)($_POST['KingdomAwardId']  ?? 0);
            $name    = trim($_POST['KingdomAwardName'] ?? '');
            $reign   = (int)($_POST['ReignLimit']      ?? 0);
            $month   = (int)($_POST['MonthLimit']      ?? 0);
            $isTitle = (int)($_POST['IsTitle']         ?? 0);
            $tClass  = (int)($_POST['TitleClass']      ?? 0);

            if (!strlen($name)) {
                echo json_encode(['status' => 1, 'error' => 'Award name is required.']);
                exit;
            }

            if ($kawId > 0) {
                $r = $this->Kingdom->EditAward([
                    'Token'          => $this->session->token,
                    'KingdomId'      => $kingdom_id,
                    'KingdomAwardId' => $kawId,
                    'Name'           => $name,
                    'ReignLimit'     => $reign,
                    'MonthLimit'     => $month,
                    'IsTitle'        => $isTitle,
                    'TitleClass'     => $tClass,
                ]);
            } else {
                $awardId = (int)($_POST['AwardId'] ?? 0);
                $r = $this->Kingdom->CreateAward([
                    'Token'      => $this->session->token,
                    'KingdomId'  => $kingdom_id,
                    'AwardId'    => $awardId,
                    'Name'       => $name,
                    'ReignLimit' => $reign,
                    'MonthLimit' => $month,
                    'IsTitle'    => $isTitle,
                    'TitleClass' => $tClass,
                ]);
            }

            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'updateparks') {
            $this->load_model('Kingdom');
            $parks = json_decode($_POST['ParksJson'] ?? '[]', true);

            if (!is_array($parks) || empty($parks)) {
                echo json_encode(['status' => 1, 'error' => 'No park data provided.']);
                exit;
            }

            $request = [];
            foreach ($parks as $park) {
                $park_id = (int)($park['ParkId'] ?? 0);
                if (!valid_id($park_id)) {
                    continue;
                }
                $request[] = [
                    'ParkId'      => $park_id,
                    'ParkName'    => trim($park['ParkName']    ?? ''),
                    'ParkTitleId' => (int)($park['ParkTitle']  ?? 0),
                    'Abbreviation' => strtoupper(trim($park['Abbreviation'] ?? '')),
                    'Active'      => !empty($park['Active']) ? 'Active' : 'Retired',
                ];
            }

            if (empty($request)) {
                echo json_encode(['status' => 1, 'error' => 'No valid parks to update.']);
                exit;
            }

            $results = $this->Kingdom->update_parks($this->session->token, $request);
            $errors  = [];
            foreach ((array)$results as $r) {
                if (isset($r['Status']) && $r['Status'] == 5) {
                    echo json_encode(['status' => 5, 'error' => 'Session expired.']);
                    exit;
                }
                if (isset($r['Status']) && $r['Status'] != 0) {
                    $errors[] = ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '');
                }
            }

            if ($errors) {
                echo json_encode(['status' => 1, 'error' => implode('; ', $errors)]);
            } else {
                echo json_encode(['status' => 0]);
            }

        } elseif ($action === 'resetwaivers') {
            $this->load_model('Player');
            $r = $this->Player->reset_waivers([
                'Token'     => $this->session->token,
                'KingdomId' => $kingdom_id,
            ]);
            if ($r['Status'] == 5) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
            } elseif ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            } else {
                echo json_encode(['status' => 0, 'message' => $r['Detail'] ?? 'Waivers reset.']);
            }

        } elseif ($action === 'deleteaward') {
            $this->load_model('Kingdom');
            $kawId = (int)($_POST['KingdomAwardId'] ?? 0);

            if (!valid_id($kawId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }

            $this->Kingdom->RemoveAward([
                'Token'          => $this->session->token,
                'KingdomId'      => $kingdom_id,
                'KingdomAwardId' => $kawId,
            ]);
            echo json_encode(['status' => 0]);

        } elseif ($action === 'setheraldry') {
            $this->load_model('Kingdom');
            if (empty($_FILES['Heraldry']['tmp_name']) || !is_uploaded_file($_FILES['Heraldry']['tmp_name'])) {
                echo json_encode(['status' => 1, 'error' => 'No image file received.']);
                exit;
            }
            $allowed = ['image/png', 'image/jpeg', 'image/gif'];
            if (!in_array($_FILES['Heraldry']['type'], $allowed)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid image type. Use PNG, JPG, or GIF.']);
                exit;
            }
            $r = $this->Kingdom->set_kingdom_heraldry([
                'Token'            => $this->session->token,
                'KingdomId'        => $kingdom_id,
                'Heraldry'         => base64_encode(file_get_contents($_FILES['Heraldry']['tmp_name'])),
                'HeraldryMimeType' => $_FILES['Heraldry']['type'],
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'removeheraldry') {
            $this->load_model('Kingdom');
            $r = $this->Kingdom->remove_kingdom_heraldry([
                'Token'     => $this->session->token,
                'KingdomId' => $kingdom_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'moveplayer') {
            $uid = (int)$this->session->user_id;
            $this->load_model('Player');
            $this->load_model('KingdomProfile');
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
            $ctx = (new KingdomProfile())->GetPlayerSuspensionContext($mundane_id);
            $player_kingdom_id = (int)($ctx['kingdom_id'] ?? 0);
            $dest_kingdom_id = (new KingdomProfile())->GetParkKingdomId($dest_park_id);
            if (!$this->KingdomProfile->authorize_move_player($uid, $player_kingdom_id, $dest_kingdom_id)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized to move this player.']);
                exit;
            }
            $r = $this->Player->move_player(['Token' => $this->session->token, 'MundaneId' => $mundane_id, 'ParkId' => $dest_park_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'parkId' => $dest_park_id])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'checkparkabbr') {
            $park_id = (int)($_POST['ParkId'] ?? 0);
            if (!valid_id($park_id)) {
                echo json_encode(['status' => 1, 'error' => 'Missing park ID.']);
                exit;
            }
            $this->load_model('AdminDashboard');
            $abbrCheck = $this->AdminDashboard->park_abbr_check($park_id, $kingdom_id);
            if (($abbrCheck['status'] ?? 1) !== 0) {
                echo json_encode(['status' => 1, 'error' => $abbrCheck['error'] ?? 'Park not found.']);
                exit;
            }
            echo json_encode([
                'status' => 0,
                'abbr' => $abbrCheck['abbr'],
                'taken' => $abbrCheck['taken'],
                'conflictName' => $abbrCheck['conflictName'],
            ]);
            exit;

        } elseif ($action === 'claimpark') {
            $this->load_model('Park');
            $park_id         = (int)($_POST['ParkId']        ?? 0);
            $dest_kingdom_id = (int)($_POST['DestKingdomId'] ?? $kingdom_id);
            if (!valid_id($park_id)) {
                echo json_encode(['status' => 1, 'error' => 'Select a park.']);
                exit;
            }
            if (!valid_id($dest_kingdom_id)) {
                echo json_encode(['status' => 1, 'error' => 'Destination kingdom is required.']);
                exit;
            }
            $new_abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
            $r = $this->Park->TransferPark(['Token' => $this->session->token, 'ParkId' => $park_id, 'KingdomId' => $dest_kingdom_id, 'Abbreviation' => $new_abbr]);
            if ($r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'addrecommendation') {
            if (!isset($this->session->user_id)) {
                echo json_encode(['status' => 1, 'error' => 'You must be logged in to submit a recommendation.']);
                exit;
            }
            $this->load_model('Player');
            $mundane_id = (int)($_POST['MundaneId']       ?? 0);
            $award_id   = (int)($_POST['KingdomAwardId']  ?? 0);
            $rank       = (int)($_POST['Rank']            ?? 0);
            $reason     = trim($_POST['Reason']           ?? '');
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
                'GivenById'      => $this->session->user_id,
                'Reason'         => $reason,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

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
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'deletedrecommendations') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $this->load_model('Reports');
            $recs = $this->Reports->deleted_recommended_awards(['KingdomId' => $kingdom_id, 'ParkId' => 0, 'PlayerId' => 0]);
            echo json_encode(['status' => 0, 'recommendations' => is_array($recs) ? array_values($recs) : []]);

        } elseif ($action === 'restorerecommendation') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
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
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'geteventtemplates') {
            $this->load_model('Event');
            $templates = $this->Event->get_event_templates_for_kingdom($kingdom_id);
            echo json_encode(['status' => 0, 'templates' => $templates]);

        } elseif ($action === 'createtournament') {
            $this->load_model('Tournament');
            $name   = trim($_POST['Name']        ?? '');
            $when   = trim($_POST['When']        ?? '');
            $desc   = trim($_POST['Description'] ?? '');
            $url    = trim($_POST['Url']         ?? '');
            $pid    = (int)($_POST['ParkId']                ?? 0);
            $ecd_id = (int)($_POST['EventCalendarDetailId'] ?? 0);

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
                'ParkId'                => $pid,
                'EventCalendarDetailId' => $ecd_id,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0, 'tournamentId' => (int)($r['Detail'] ?? 0)])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'deletetournament') {
            $this->load_model('Tournament');
            $tournament_id = (int)($_POST['TournamentId'] ?? 0);
            if (!valid_id($tournament_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid tournament ID.']);
                exit;
            }
            $r = $this->Tournament->delete_tournament([
                'Token'        => $this->session->token,
                'TournamentId' => $tournament_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'setrecsvisibility') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_EDIT)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $value = (int)($_POST['Value'] ?? 1) ? true : false;
            $this->load_model('KingdomProfile');
            $this->KingdomProfile->set_award_recs_public((int)$kingdom_id, $value);
            echo json_encode(['status' => 0]);

        } elseif ($action === 'addauth') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $mid  = (int)($_POST['MundaneId'] ?? 0);
            // Scoped grants only accept create / edit. The legacy 'admin' role at
            // kingdom scope is no longer granted from the UI — system-wide admin
            // is managed on its own page and only ever issued unscoped.
            $role = in_array($_POST['Role'] ?? '', ['create','edit']) ? $_POST['Role'] : 'create';
            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }
            $this->load_model('Authorization');
            $r = $this->Authorization->add_auth([
                'Token'     => $this->session->token,
                'MundaneId' => $mid,
                'Type'      => AUTH_KINGDOM,
                'Id'        => $kingdom_id,
                'Role'      => $role,
            ]);
            if ($r['Status'] != 0) {
                echo json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . (isset($r['Detail']) && $r['Detail'] !== '' ? ': ' . $r['Detail'] : '')]);
                exit;
            }
            $authId = (int)($r['Detail'] ?? 0);
            $this->load_model('Player');
            $persona = $this->Player->get_persona($mid);
            (new Dangeraudit())->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_KINGDOM, 'Id' => $kingdom_id, 'Role' => $role], 'Player', $mid, null, [
                'authorization_id' => $authId,
                'mundane_id'       => $mid,
                'park_id'          => 0,
                'kingdom_id'       => (int)$kingdom_id,
                'event_id'         => 0,
                'unit_id'          => 0,
                'role'             => $role,
            ]);
            echo json_encode(['status' => 0, 'authId' => $authId, 'persona' => $persona]);

        } elseif ($action === 'removeauth') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdom_id, AUTH_CREATE)) {
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
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'getparks') {
            // Always return family parks (kingdom + child principalities) for dropdowns.
            $this->load_model('Kingdom');
            $r = $this->Kingdom->get_family_parks($kingdom_id);
            $parks = [];
            foreach ($r['Parks'] ?? [] as $park) {
                $parks[] = ['ParkId' => $park['ParkId'], 'Name' => $park['Name']];
            }
            // Sort alphabetically by name so the dropdowns are easy to scan.
            usort($parks, function ($a, $b) {
                return strcasecmp($a['Name'], $b['Name']);
            });
            echo json_encode(['status' => 0, 'parks' => $parks]);
        } elseif ($action === 'parktitles') {
            $this->load_model('Kingdom');
            $result = $this->Kingdom->get_kingdom_park_titles($kingdom_id);
            $titles = [];
            foreach ($result['ParkTitles'] ?? [] as $pt) {
                $titles[] = ['ParkTitleId' => (int)$pt['ParkTitleId'], 'Title' => $pt['Title']];
            }
            echo json_encode(['status' => 0, 'titles' => $titles]);

        } elseif ($action === 'setparent') {
            $uid = (int)($this->session->user_id ?? 0);
            if (!$uid || !$this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
                echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
                exit;
            }
            $this->load_model('Kingdom');
            $parentId = (int)($_POST['ParentKingdomId'] ?? 0);
            $r = $this->Kingdom->set_kingdom_parent([
                'Token'           => $this->session->token,
                'KingdomId'       => $kingdom_id,
                'ParentKingdomId' => $parentId,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'checkabbr') {
            $abbr      = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
            $excludeId = (int)($_POST['ExcludeKingdomId'] ?? 0);
            if (!strlen($abbr)) {
                echo json_encode(['status' => 0, 'taken' => false]);
                exit;
            }
            $this->load_model('KingdomProfile');
            $conflictName = (new KingdomProfile())->GetKingdomAbbreviationConflict($abbr, $excludeId);
            echo $conflictName !== null
                ? json_encode(['status' => 0, 'taken' => true, 'name' => $conflictName])
                : json_encode(['status' => 0, 'taken' => false]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function calendar($p = null)
    {
        header('Content-Type: application/json');
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');

        if (!valid_id($kingdom_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
            exit;
        }

        $start = preg_replace('/[^0-9\-]/', '', substr($_GET['start'] ?? '', 0, 10));
        $end   = preg_replace('/[^0-9\-]/', '', substr($_GET['end']   ?? '', 0, 10));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid date range']);
            exit;
        }

        $kn_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        $kn_isAdmin = ($kn_uid > 0) ? $this->Authorization->has_authority($kn_uid, AUTH_ADMIN, 0, AUTH_CREATE) : false;
        $this->load_model('KingdomProfile');
        $events = $this->KingdomProfile->calendar_feed((int)$kingdom_id, $start, $end, $kn_uid, $kn_isAdmin);

        echo json_encode(['status' => 0, 'events' => $events]);
        exit;
    }

    public function playersearch($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode([]);
            exit;
        }

        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $p ?? '');
        $scope_check = trim($_GET['scope'] ?? 'own');
        // kingdom_id=0 is valid for scope=all (global search with no kingdom context)
        if (!valid_id($kingdom_id) && $scope_check !== 'all') {
            echo json_encode([]);
            exit;
        }

        $q                = trim($_GET['q']               ?? '');
        $scope            = trim($_GET['scope']           ?? 'own'); // 'own' | 'exclude'
        $park_id          = (int)($_GET['park_id']        ?? 0);
        $include_inactive  = !empty($_GET['include_inactive']);
        $include_suspended = !empty($_GET['include_suspended']);
        if (strlen($q) < 2) {
            echo json_encode([]);
            exit;
        }

        $scopeKey = 'kingdom_own';
        if ($scope === 'exclude') {
            $scopeKey = 'kingdom_exclude';
        } elseif ($scope === 'all') {
            $scopeKey = 'kingdom_all';
        }

        $this->load_model('Search');
        $results = $this->Search->scoped_player_search([
            'Query'            => $q,
            'Scope'            => $scopeKey,
            'KingdomId'        => $kingdom_id,
            'ScopeParkId'      => $park_id,
            'IncludeInactive'  => $include_inactive,
            'IncludeSuspended' => $include_suspended,
            'Limit'            => 15,
            'Format'           => 'kingdom',
        ]);

        echo json_encode($results);
        exit;
    }

    /* Active kingdoms (sorted by name) for the Move Player cascade dropdowns,
       shared by the Kingdom/Park/Player/Admin Move Player modals. */
    public function getkingdoms($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode([]);
            exit;
        }
        $this->load_model('Kingdom');
        $r = $this->Kingdom->get_kingdoms_response();
        $kingdoms = [];
        foreach ($r['Kingdoms'] ?? [] as $k) {
            $kingdoms[] = ['KingdomId' => (int)$k['KingdomId'], 'KingdomName' => $k['KingdomName'], 'Abbreviation' => $k['Abbreviation']];
        }
        usort($kingdoms, function ($a, $b) {
            return strcasecmp($a['KingdomName'] ?? '', $b['KingdomName'] ?? '');
        });
        echo json_encode(['status' => 0, 'kingdoms' => $kingdoms]);
        exit;
    }

    public function suspendplayer($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $uid = (int)$this->session->user_id;
        $mid = (int)($_POST['MundaneId'] ?? 0);
        if (!$mid) {
            echo json_encode(['status' => 1, 'error' => 'Select a player.']);
            exit;
        }

        // Determine the player's kingdom so we can check auth
        $this->load_model('KingdomProfile');
        $context = $this->KingdomProfile->suspension_context($mid);
        if ($context['kingdom_id'] <= 0) {
            echo json_encode(['status' => 1, 'error' => 'Player not found.']);
            exit;
        }
        $player_kingdom_id        = (int)$context['kingdom_id'];
        $existing_suspended_by_id = (int)($context['suspended_by_id'] ?? 0);
        $is_currently_suspended   = (bool)$context['suspended'];

        $isAdmin = $this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
        $isKingdomEditor = valid_id($player_kingdom_id)
            && $this->Authorization->has_authority($uid, AUTH_KINGDOM, $player_kingdom_id, AUTH_EDIT);
        if (!$isAdmin && !$isKingdomEditor) {
            echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
            exit;
        }

        $suspended  = (int)($_POST['Suspended']  ?? 1);
        $byId       = (int)($_POST['SuspendedById'] ?? 0);
        $at         = trim($_POST['SuspendedAt']    ?? '');
        $until      = trim($_POST['SuspendedUntil'] ?? '');
        $reason     = trim($_POST['Suspension']    ?? '');
        $propagates = (int)($_POST['SuspensionPropagates'] ?? 0);
        // New suspension → use current user; edit → preserve existing suspendator (or null if never recorded)
        $resolvedById = $byId ?: ($is_currently_suspended ? ($existing_suspended_by_id ?: null) : $uid);
        $this->load_model('Player');
        $r = $this->Player->suspend_player([
            'Token'                => $this->session->token,
            'MundaneId'            => $mid,
            'Suspended'            => (bool)$suspended,
            'SuspendedById'        => $resolvedById,
            'SuspendedAt'          => $at,
            'SuspendedUntil'       => $until,
            'Suspension'           => $reason,
            'SuspensionPropagates' => $propagates,
        ]);
        echo ($r === null || (isset($r['Status']) && $r['Status'] == 0))
            ? json_encode(['status' => 0])
            : json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);
        exit;
    }

    public function banner($p = null)
    {
        header('Content-Type: application/json');

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $params   = explode('/', $p ?? '');
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $action   = $params[1] ?? '';

        if (!valid_id($kingdom_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Kingdom ID.']);
            exit;
        }

        $this->load_model('Banner');
        $this->Banner->handle_ajax(
            'Kingdom',
            $action,
            $kingdom_id,
            $this->session->token,
            $_POST,
            $_FILES,
        );
    }

}
