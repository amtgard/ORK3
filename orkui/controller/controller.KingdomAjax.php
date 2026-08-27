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
                    $errors[] = rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ');
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
            // Kingdom::VacateOfficer is the one entry point here that still returns a
            // bare array() on its success path -- it only ever fills $response on the
            // two denial branches. So an EMPTY array is its documented success, but a
            // NON-empty array with no Status is a malformed response and must not be
            // reported as a save. Sibling actions no longer tolerate a missing Status
            // at all; this one cannot until the domain returns Success() like the rest.
            if (is_array($r) && count($r) === 0) {
                echo json_encode(['status' => 0]);
            } elseif (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The office could not be vacated.'),
                ]);
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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'setconfig') {
            // THE single write path for kingdom configuration.
            //
            // The Configuration modal used to save twice from one button: setconfig,
            // then setrecsvisibility. Both wrote AwardRecsPublic and the second always
            // won, so the value the officer picked in the select was silently replaced
            // by whatever the separate visibility control held. Everything now goes
            // through here; setrecsvisibility survives only for its other caller.
            //
            // Permission: 'kingdom.config.edit'. Changing dues, attendance minimums or
            // a feature flag is not the same act as renaming the kingdom, and this
            // endpoint used to inherit whatever SetKingdomDetails happened to enforce.
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'kingdom.config.edit', 'kingdom', $kingdom_id, AUTH_EDIT)) {
                echo json_encode(['status' => 5, 'error' => 'You do not have permission to change this kingdom\'s configuration.']);
                exit;
            }

            $this->load_model('Kingdom');
            $configs = $_POST['Config'] ?? [];

            if (!is_array($configs) || empty($configs)) {
                echo json_encode(['status' => 1, 'error' => 'No configuration data provided.']);
                exit;
            }

            // Stored rows for this kingdom, keyed by config key. Two jobs: it supplies
            // the ConfigurationId every edit needs (Common::update_config finds the row
            // by primary key), and it lets an older caller that posts Config[<id>] be
            // resolved back to a key so it can still be validated by name.
            $storedConfigs = [];
            $kingdomDetails = $this->Kingdom->get_kingdom_details($kingdom_id);
            if (is_array($kingdomDetails['KingdomConfiguration'] ?? null)) {
                $storedConfigs = $kingdomDetails['KingdomConfiguration'];
            }
            $configKeyById = [];
            foreach ($storedConfigs as $storedKey => $storedRow) {
                $storedId = (int)($storedRow['ConfigurationId'] ?? 0);
                if ($storedId > 0) {
                    $configKeyById[$storedId] = $storedKey;
                }
            }

            // Validate EVERYTHING before writing ANYTHING. A key that is not in the
            // registry is refused outright rather than skipped, and one bad value
            // aborts the whole save -- a half-applied configuration is worse than a
            // rejected one, because nothing on screen tells the officer which half won.
            $configList = [];
            $configErrors = [];
            foreach ($configs as $submittedKey => $value) {
                $key = (string)$submittedKey;
                if (ctype_digit($key)) {
                    $key = $configKeyById[(int)$key] ?? '';
                }

                if ($key === '' || !ConfigRegistry::Exists($key)) {
                    $configErrors[] = 'One of the submitted settings is not one this kingdom can change.';
                    continue;
                }

                $check = ConfigRegistry::Validate($key, $value);
                if (empty($check['valid'])) {
                    $configErrors[] = $this->ka_plain_text($check['error'] ?? (ConfigRegistry::Label($key) . ' is not a valid value.'));
                    continue;
                }

                $definition = ConfigRegistry::Get($key);
                $existingId = (int)($storedConfigs[$key]['ConfigurationId'] ?? 0);

                if ($existingId > 0) {
                    $configList[] = [
                        'Action'          => CFG_EDIT,
                        'ConfigurationId' => $existingId,
                        'Key'             => $key,
                        // Store the normalized value, never the raw submission. The
                        // registry returns '' rather than null for a cleared optional
                        // value on purpose: yapo drops nulls from an UPDATE, so a null
                        // would leave the previous value in place.
                        'Value'           => $check['value'],
                    ];
                } else {
                    // A registry key with no row yet (backfilled keys on an older
                    // kingdom). Without this the edit finds nothing and saves nothing,
                    // silently. var_type mirrors what Kingdom::CreateKingdom seeds.
                    $varType = 'fixed';
                    if (($definition['control'] ?? '') === ConfigRegistry::CONTROL_NUMBER) {
                        $varType = 'number';
                    } elseif (($definition['control'] ?? '') === ConfigRegistry::CONTROL_COLOR) {
                        $varType = 'color';
                    }
                    $configList[] = [
                        'Action'        => CFG_ADD,
                        'Key'           => $key,
                        'Type'          => $varType,
                        'Value'         => $check['value'],
                        'UserSetting'   => 1,
                        'AllowedValues' => null,
                    ];
                }
            }

            if ($configErrors) {
                echo json_encode(['status' => 1, 'error' => implode(' ', array_unique($configErrors))]);
                exit;
            }

            if (empty($configList)) {
                echo json_encode(['status' => 1, 'error' => 'No configuration data provided.']);
                exit;
            }

            $r = $this->Kingdom->set_kingdom_details([
                'Token'                => $this->session->token,
                'KingdomId'            => $kingdom_id,
                // Empty rather than absent: SetKingdomDetails reads both unconditionally
                // and treats a zero-length value as "leave the stored one alone".
                'Name'                 => '',
                'Abbreviation'         => '',
                'KingdomConfiguration' => $configList,
            ]);
            echo (isset($r['Status']) && $r['Status'] == 0)
                ? json_encode(['status' => 0, 'saved' => count($configList)])
                : json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The configuration could not be saved.'),
                ]);

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'setaward') {
            $this->load_model('Kingdom');
            $kawId    = (int)($_POST['KingdomAwardId']  ?? 0);
            $name     = trim($_POST['KingdomAwardName'] ?? '');
            $reign    = (int)($_POST['ReignLimit']      ?? 0);
            $month    = (int)($_POST['MonthLimit']      ?? 0);
            $isTitle  = (int)($_POST['IsTitle']         ?? 0);
            $tClass   = (int)($_POST['TitleClass']      ?? 0);
            $isLadder = (int)($_POST['IsLadder']        ?? 0);
            $maxLevel = (int)($_POST['MaxLevel']        ?? 0);

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
                    'IsLadder'       => $isLadder,
                    'MaxLevel'       => $maxLevel,
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
                    'IsLadder'   => $isLadder,
                    'MaxLevel'   => $maxLevel,
                ]);
            }

            // A response with no Status is a FAILURE, not a pass. CreateAward used to
            // fall off the end and return null; the old `!isset($r['Status'])` clause
            // read that as success, so a create that never ran still reported "Award
            // saved." and the modal drew a row for an award that does not exist.
            echo (isset($r['Status']) && $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The award could not be saved.'),
                ]);

        } elseif ($action === 'updateparks') {
            // Two different operations arrive in one POST from the Edit Parks grid:
            //
            //   name / abbreviation / title  -> SetParkDetails  ('park.details.edit')
            //   the Active toggle            -> RetirePark / RestorePark
            //                                   ('kingdom.park.retire', danger-audited)
            //
            // They are split here because they are not the same permission. Sending
            // Active through SetParkDetails let anyone who could rename a park also
            // retire one, through a plain LOG_EDIT and with no danger-audit row; the
            // domain now ignores Active on a details edit for exactly that reason.
            $this->load_model('Kingdom');
            $this->load_model('Park');
            $parks = json_decode($_POST['ParksJson'] ?? '[]', true);

            if (!is_array($parks) || empty($parks)) {
                echo json_encode(['status' => 1, 'error' => 'No park data provided.']);
                exit;
            }

            // Stored state, read once. Whether the Active toggle changed is decided
            // against the database, never against anything the browser posted, so an
            // officer who may rename a park but not retire one is not denied for a
            // checkbox they never touched.
            $storedParks = [];
            $rawParks = $this->Kingdom->get_parks($kingdom_id);
            foreach (($rawParks['Parks'] ?? []) as $storedPark) {
                $storedParks[(int)$storedPark['ParkId']] = [
                    'Name'   => $this->ka_plain_text($storedPark['Name'] ?? ''),
                    'Active' => (($storedPark['Active'] ?? '') === 'Active') ? 'Active' : 'Retired',
                ];
            }

            $request      = [];   // rows for SetParkDetails, index-aligned with $order
            $order        = [];   // park id for each $request row
            $wantedActive = [];   // park id => requested Active state, only when submitted
            $parkResults  = [];   // park id => per-park outcome
            // Stays true only while every single failure is a NoAuthorization and
            // nothing at all succeeded -- the shape an expired token makes.
            $noAuthOnly   = true;

            foreach ($parks as $park) {
                $park_id = (int)($park['ParkId'] ?? 0);
                if (!valid_id($park_id)) {
                    continue;
                }

                $submittedName = $this->ka_plain_text($park['ParkName'] ?? '');

                // This endpoint is scoped to one kingdom, and the grid is drawn from
                // that kingdom's parks. A park id from anywhere else is refused by
                // name here instead of being handed to a per-park permission check.
                if (!isset($storedParks[$park_id])) {
                    $noAuthOnly = false;
                    $parkResults[$park_id] = [
                        'parkId'   => $park_id,
                        'name'     => $submittedName !== '' ? $submittedName : ('Park #' . $park_id),
                        'ok'       => false,
                        'messages' => ['That park is not one of this kingdom\'s parks.'],
                    ];
                    continue;
                }

                $order[]   = $park_id;
                $request[] = [
                    'ParkId'       => $park_id,
                    'ParkName'     => trim($park['ParkName'] ?? ''),
                    'ParkTitleId'  => (int)($park['ParkTitle'] ?? 0),
                    'Abbreviation' => strtoupper(trim($park['Abbreviation'] ?? '')),
                    // Deliberately the STORED value, not the submitted one.
                    // SetParkDetails ignores Active, and passing what is already on
                    // the row keeps this request a no-op for that column whichever
                    // build of the domain answers it. The real change is below.
                    'Active'       => $storedParks[$park_id]['Active'],
                ];

                // Only treat Active as a requested change when the caller actually
                // sent the field. An absent key must never read as "retire this park".
                if (array_key_exists('Active', $park)) {
                    $wantedActive[$park_id] = !empty($park['Active']) ? 'Active' : 'Retired';
                }

                $parkResults[$park_id] = [
                    'parkId'   => $park_id,
                    'name'     => $submittedName !== '' ? $submittedName : $storedParks[$park_id]['Name'],
                    'ok'       => true,
                    'messages' => [],
                    'active'   => $storedParks[$park_id]['Active'],
                ];
            }

            if (empty($request) && empty($parkResults)) {
                echo json_encode(['status' => 1, 'error' => 'No valid parks to update.']);
                exit;
            }

            // ---- name / abbreviation / title -----------------------------------
            if ($request) {
                $saveResults = (array)$this->Kingdom->update_parks($this->session->token, $request);
                foreach ($order as $i => $park_id) {
                    $r = $saveResults[$i] ?? null;
                    if (isset($r['Status']) && $r['Status'] == 0) {
                        $noAuthOnly = false;
                        continue;
                    }
                    if ((int)($r['Status'] ?? 1) !== 5) {
                        $noAuthOnly = false;
                    }
                    $parkResults[$park_id]['ok'] = false;
                    $parkResults[$park_id]['messages'][] = $this->ka_status_message($r, 'This park could not be saved.');
                }
            }

            // ---- the Active toggle ---------------------------------------------
            foreach ($wantedActive as $park_id => $active) {
                if ($active === $storedParks[$park_id]['Active']) {
                    continue;
                }
                $sr = ($active === 'Active')
                    ? $this->Park->RestorePark(['Token' => $this->session->token, 'ParkId' => $park_id])
                    : $this->Park->RetirePark(['Token' => $this->session->token, 'ParkId' => $park_id]);

                if (isset($sr['Status']) && $sr['Status'] == 0) {
                    $noAuthOnly = false;
                    $parkResults[$park_id]['active'] = $active;
                    // RetirePark/RestorePark name the park in Detail, so this reads
                    // as "Dragonspine (DSP) has been retired." on the row itself.
                    $parkResults[$park_id]['messages'][] = $this->ka_status_message($sr, 'Status updated.');
                    continue;
                }
                if ((int)($sr['Status'] ?? 1) !== 5) {
                    $noAuthOnly = false;
                }
                $parkResults[$park_id]['ok'] = false;
                $parkResults[$park_id]['messages'][] = $this->ka_status_message($sr, 'The status of this park could not be changed.');
            }

            // ---- per-park result -------------------------------------------------
            // The whole batch used to collapse into one implode('; ') string with no
            // park named in it, so an officer saving twelve parks was told "Error"
            // and could not tell which row failed while the other eleven saved.
            $results = [];
            $failed  = [];
            foreach ($parkResults as $row) {
                $row['message'] = $row['messages']
                    ? implode(' ', $row['messages'])
                    : ($row['ok'] ? 'Saved.' : 'This park could not be saved.');
                unset($row['messages']);
                $results[] = $row;
                if (!$row['ok']) {
                    $failed[] = $row['name'] . ': ' . $row['message'];
                }
            }

            if (!$failed) {
                echo json_encode(['status' => 0, 'results' => $results]);
            } elseif ($noAuthOnly) {
                // Every attempt came back NoAuthorization and nothing landed -- that
                // is the shape an expired session makes, so keep the old signal the
                // modal uses to send the officer back to a login.
                echo json_encode(['status' => 5, 'error' => 'Your session has expired, or you are not authorized to edit these parks.', 'results' => $results]);
            } else {
                echo json_encode(['status' => 1, 'error' => implode(' ', $failed), 'results' => $results]);
            }

        } elseif ($action === 'resetwaivers') {
            $this->load_model('Kingdom');
            $this->load_model('Player');

            // How many players are about to be cleared, read BEFORE the reset runs --
            // afterwards the answer is always zero. 'WaiveredMembers' is scoped to
            // exactly what the UPDATE touches (kingdom_id + waivered = 1, no active
            // filter), so it is the true count and not an approximation.
            $dash = $this->Kingdom->get_admin_dashboard($kingdom_id);
            $waiverCount = (int)($dash['Queue']['WaiveredMembers'] ?? 0);

            $r = $this->Player->reset_waivers([
                'Token'     => $this->session->token,
                'KingdomId' => $kingdom_id,
            ]);
            if (!isset($r['Status'])) {
                echo json_encode(['status' => 1, 'error' => $this->ka_status_message($r, 'Waivers could not be reset.')]);
            } elseif ($r['Status'] == 5) {
                echo json_encode(['status' => 5, 'error' => 'You do not have permission to reset waivers for this kingdom.']);
            } elseif ($r['Status'] != 0) {
                echo json_encode(['status' => (int)$r['Status'], 'error' => $this->ka_status_message($r, 'Waivers could not be reset.')]);
            } else {
                // Prefer a count the domain reports, if it ever starts reporting one.
                $cleared = isset($r['Count']) ? (int)$r['Count'] : $waiverCount;
                echo json_encode([
                    'status'  => 0,
                    'count'   => $cleared,
                    'message' => $cleared === 1
                        ? 'Waiver reset for 1 player.'
                        : 'Waivers reset for ' . number_format($cleared) . ' players.',
                ]);
            }

        } elseif ($action === 'deleteaward') {
            $this->load_model('Kingdom');
            $kawId = (int)($_POST['KingdomAwardId'] ?? 0);

            if (!valid_id($kawId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }

            // The return value used to be discarded and status 0 echoed
            // unconditionally, so a *denied* delete still reported "Award
            // deleted." and the JS removed the row from the table while the award
            // was still in the database. setaward next to it handled status
            // correctly; only this branch swallowed it.
            $r = $this->Kingdom->RemoveAward([
                'Token'          => $this->session->token,
                'KingdomId'      => $kingdom_id,
                'KingdomAwardId' => $kawId,
            ]);
            // Same truthiness rule as setaward: no Status means the call failed.
            // RemoveAward is a soft delete and reports 'AwardingCount' -- how many
            // grants still reference the definition -- so the modal can say what the
            // retire actually affected instead of just vanishing the row.
            echo (isset($r['Status']) && $r['Status'] == 0)
                ? json_encode(['status' => 0, 'awardingCount' => (int)($r['AwardingCount'] ?? 0)])
                : json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The award could not be retired.'),
                ]);

        } elseif ($action === 'restoreaward') {
            // Counterpart to deleteaward. RemoveAward is a soft delete (it sets
            // ork_kingdomaward.disabled), so the retire has to be reversible from
            // the same modal -- otherwise the soft delete is just a delete with
            // extra steps. Same shape, same ownership rule, same truthiness test.
            $this->load_model('Kingdom');
            $kawId = (int)($_POST['KingdomAwardId'] ?? 0);

            if (!valid_id($kawId)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }

            $r = $this->Kingdom->RestoreAward([
                'Token'          => $this->session->token,
                'KingdomId'      => $kingdom_id,
                'KingdomAwardId' => $kawId,
            ]);
            echo (isset($r['Status']) && $r['Status'] == 0)
                ? json_encode(['status' => 0, 'awardingCount' => (int)($r['AwardingCount'] ?? 0)])
                : json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The award could not be re-enabled.'),
                ]);

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'removeheraldry') {
            $this->load_model('Kingdom');
            $r = $this->Kingdom->remove_kingdom_heraldry([
                'Token'     => $this->session->token,
                'KingdomId' => $kingdom_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

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
            $ctx = $this->KingdomProfile->suspension_context($mundane_id);
            $player_kingdom_id = (int)($ctx['kingdom_id'] ?? 0);
            $dest_kingdom_id = $this->KingdomProfile->park_kingdom_id($dest_park_id);
            if (!$this->KingdomProfile->authorize_move_player($uid, $player_kingdom_id, $dest_kingdom_id)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized to move this player.']);
                exit;
            }
            $r = $this->Player->move_player(['Token' => $this->session->token, 'MundaneId' => $mundane_id, 'ParkId' => $dest_park_id]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'parkId' => $dest_park_id])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

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
                echo json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
            }

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

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
            // Tournament::CreateTournament returns a status array on every path, and the
            // new tournament id rides in Detail. Treating a missing Status as success
            // handed the JS `tournamentId: 0` and it redirected to a tournament that
            // was never created.
            echo (isset($r['Status']) && $r['Status'] == 0)
                ? json_encode(['status' => 0, 'tournamentId' => (int)($r['Detail'] ?? 0)])
                : json_encode([
                    'status' => (int)($r['Status'] ?? 1),
                    'error'  => $this->ka_status_message($r, 'The tournament could not be created.'),
                ]);

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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'setrecsvisibility') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'kingdom.config.edit', 'kingdom', $kingdom_id, AUTH_EDIT)) {
                echo json_encode(['status' => 5, 'error' => 'Not authorized.']);
                exit;
            }
            $value = (int)($_POST['Value'] ?? 1) ? true : false;
            $this->load_model('KingdomProfile');
            $this->KingdomProfile->set_award_recs_public((int)$kingdom_id, $value);
            echo json_encode(['status' => 0]);

        } elseif ($action === 'addauth') {
            $uid = (int)$this->session->user_id;
            if (!$this->Authorization->has_permission_or_authority($uid, 'kingdom.auth.manage', 'kingdom', $kingdom_id, AUTH_CREATE)) {
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
            $this->Authorization->audit('Authorization::AddAuthorization', ['MundaneId' => $mid, 'Type' => AUTH_KINGDOM, 'Id' => $kingdom_id, 'Role' => $role], 'Player', $mid, null, [
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
            if (!$this->Authorization->has_permission_or_authority($uid, 'kingdom.auth.manage', 'kingdom', $kingdom_id, AUTH_CREATE)) {
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
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'checkabbr') {
            $abbr      = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($_POST['Abbreviation'] ?? '')));
            $excludeId = (int)($_POST['ExcludeKingdomId'] ?? 0);
            if (!strlen($abbr)) {
                echo json_encode(['status' => 0, 'taken' => false]);
                exit;
            }
            $this->load_model('KingdomProfile');
            $conflictName = $this->KingdomProfile->abbreviation_conflict($abbr, $excludeId);
            echo $conflictName !== null
                ? json_encode(['status' => 0, 'taken' => true, 'name' => $conflictName])
                : json_encode(['status' => 0, 'taken' => false]);

        } elseif ($action === 'officerhistory') {
            $this->load_model('Kingdom');
            $role = trim($_GET['Role'] ?? '');
            $r = $this->Kingdom->get_officer_history($kingdom_id, strlen($role) > 0 ? $role : null);
            echo json_encode([
                'status'  => 0,
                'history' => $r['History'] ?? [],
            ]);

        } elseif ($action === 'addofficerhistory') {
            $this->load_model('Kingdom');
            $mid   = (int)($_POST['MundaneId'] ?? 0);
            $role  = trim($_POST['Role']       ?? '');
            $start = trim($_POST['StartDate']  ?? '');
            $end   = trim($_POST['EndDate']    ?? '');
            $notes = trim($_POST['Notes']      ?? '');

            if (!$mid) {
                echo json_encode(['status' => 1, 'error' => 'Please select a player.']);
                exit;
            }
            if (!strlen($role)) {
                echo json_encode(['status' => 1, 'error' => 'Role is required.']);
                exit;
            }
            if (!strlen($start)) {
                echo json_encode(['status' => 1, 'error' => 'Start date is required.']);
                exit;
            }

            $r = $this->Kingdom->add_officer_history([
                'Token'     => $this->session->token,
                'KingdomId' => $kingdom_id,
                'MundaneId' => $mid,
                'Role'      => $role,
                'StartDate' => $start,
                'EndDate'   => $end,
                'Notes'     => $notes,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'editofficerhistory') {
            $this->load_model('Kingdom');
            $ohid  = (int)($_POST['OfficerHistoryId'] ?? 0);
            $role  = trim($_POST['Role']       ?? '');
            $start = trim($_POST['StartDate']  ?? '');
            $end   = trim($_POST['EndDate']    ?? '');
            $notes = trim($_POST['Notes']      ?? '');

            if (!$ohid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid history record.']);
                exit;
            }
            if (!strlen($role)) {
                echo json_encode(['status' => 1, 'error' => 'Role is required.']);
                exit;
            }
            if (!strlen($start)) {
                echo json_encode(['status' => 1, 'error' => 'Start date is required.']);
                exit;
            }

            $r = $this->Kingdom->edit_officer_history([
                'Token'            => $this->session->token,
                'KingdomId'        => $kingdom_id,
                'OfficerHistoryId' => $ohid,
                'Role'             => $role,
                'StartDate'        => $start,
                'EndDate'          => $end,
                'Notes'            => $notes,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } elseif ($action === 'deleteofficerhistory') {
            $this->load_model('Kingdom');
            $ohid = (int)($_POST['OfficerHistoryId'] ?? 0);

            if (!$ohid) {
                echo json_encode(['status' => 1, 'error' => 'Invalid history record.']);
                exit;
            }

            $r = $this->Kingdom->delete_officer_history([
                'Token'            => $this->session->token,
                'KingdomId'        => $kingdom_id,
                'OfficerHistoryId' => $ohid,
            ]);
            echo (!isset($r['Status']) || $r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => ($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? '')]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function rbac($p = null)
    {
        header('Content-Type: application/json');
        $parts      = explode('/', $p ?? '');
        $kingdom_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action     = $parts[1] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        $uid = (int)$this->session->user_id;

        if (!valid_id($kingdom_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid kingdom ID']);
            exit;
        }

        // All RBAC actions require kingdom.auth.manage or admin
        if (
            !$this->Authorization->has_permission_or_authority($uid, 'kingdom.auth.manage', 'kingdom', $kingdom_id, AUTH_CREATE)
            && !$this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)
        ) {
            echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
            exit;
        }

        $this->load_model('RBACService');

        if ($action === 'getroles') {
            $roles = $this->RBACService->GetAvailableRoles($kingdom_id);
            echo json_encode(['status' => 0, 'roles' => $roles]);

        } elseif ($action === 'getassignments') {
            $assignments = $this->RBACService->GetKingdomRoleAssignments($kingdom_id, true);
            echo json_encode(['status' => 0, 'assignments' => $assignments]);

        } elseif ($action === 'grantrole') {
            $target_id  = (int)($_POST['MundaneId'] ?? 0);
            $role_id    = (int)($_POST['RoleId'] ?? 0);
            $scope_type = trim($_POST['ScopeType'] ?? 'kingdom');
            $scope_id   = (int)($_POST['ScopeId'] ?? $kingdom_id);
            // A kingdom-scoped grant may only target the kingdom this request was authorized
            // for. GrantRole's escalation check already blocks granting permissions you lack
            // at the target scope, but that is the last line, not the first -- pin the scope
            // here so a POSTed ScopeId cannot aim the grant at another kingdom at all.
            if ($scope_type === 'kingdom') {
                $scope_id = $kingdom_id;
            }

            if (!valid_id($target_id) || !valid_id($role_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player or role.']);
                exit;
            }

            $r = $this->RBACService->GrantRole($uid, $target_id, $role_id, $scope_type, $scope_id);
            if (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? '') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'revokerole') {
            $user_role_id = (int)($_POST['UserRoleId'] ?? 0);

            if (!valid_id($user_role_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid assignment.']);
                exit;
            }

            $r = $this->RBACService->RevokeRole($uid, $user_role_id);
            if (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? '') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'createrole') {
            $name         = trim($_POST['Name'] ?? '');
            $display_name = trim($_POST['DisplayName'] ?? '');
            $description  = trim($_POST['Description'] ?? '');
            $scope_type   = trim($_POST['ScopeType'] ?? 'kingdom');
            $perm_keys    = isset($_POST['Permissions']) ? (is_array($_POST['Permissions']) ? $_POST['Permissions'] : json_decode($_POST['Permissions'], true)) : [];

            if (!strlen($name) || !strlen($display_name)) {
                echo json_encode(['status' => 1, 'error' => 'Name and display name are required.']);
                exit;
            }

            $r = $this->RBACService->CreateRole($uid, $kingdom_id, $name, $display_name, $description, $scope_type, $perm_keys ?: []);
            if (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0, 'role_id' => $r['Detail'] ?? 0]);
            } else {
                echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? '') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'editrole') {
            $role_id      = (int)($_POST['RoleId'] ?? 0);
            $display_name = isset($_POST['DisplayName']) ? trim($_POST['DisplayName']) : null;
            $description  = isset($_POST['Description']) ? trim($_POST['Description']) : null;
            $perm_keys    = isset($_POST['Permissions']) ? (is_array($_POST['Permissions']) ? $_POST['Permissions'] : json_decode($_POST['Permissions'], true)) : [];

            if (!valid_id($role_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid role.']);
                exit;
            }

            $r = $this->RBACService->EditRole($uid, $role_id, $perm_keys ?: [], $display_name, $description, $kingdom_id);
            if (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? '') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'deleterole') {
            $role_id = (int)($_POST['RoleId'] ?? 0);

            if (!valid_id($role_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid role.']);
                exit;
            }

            $r = $this->RBACService->DeleteRole($uid, $role_id, $kingdom_id);
            if (isset($r['Status']) && $r['Status'] == 0) {
                echo json_encode(['status' => 0]);
            } else {
                echo json_encode(['status' => $r['Status'] ?? 1, 'error' => ($r['Error'] ?? '') . ': ' . ($r['Detail'] ?? '')]);
            }

        } elseif ($action === 'geteffectivepermissions') {
            $target_id  = (int)($_GET['MundaneId'] ?? 0);
            $scope_type = trim($_GET['ScopeType'] ?? 'kingdom');
            $scope_id   = (int)($_GET['ScopeId'] ?? $kingdom_id);

            if (!valid_id($target_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid player.']);
                exit;
            }

            $perms = $this->RBACService->GetEffectivePermissions($target_id, $scope_type, $scope_id);
            echo json_encode(['status' => 0, 'permissions' => $perms]);

        } elseif ($action === 'getrolepermissions') {
            $role_id = (int)($_GET['RoleId'] ?? 0);
            if (!valid_id($role_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid role.']);
                exit;
            }
            $perms = $this->RBACService->GetRolePermissions($role_id);
            echo json_encode(['status' => 0, 'permissions' => $perms]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action: ' . $action]);
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
            && $this->Authorization->has_permission_or_authority($uid, 'player.edit', 'kingdom', $player_kingdom_id, AUTH_EDIT);
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
            : json_encode(['status' => $r['Status'] ?? 1, 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
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

    /**
     * Turn a service status array into a message an officer can act on.
     *
     * Two hazards this exists for:
     *
     *  - Several domain paths call NoAuthorization(null, $mundane_id), which puts a
     *    bare database id in 'Error'. Shown to a user that is noise at best and a
     *    leaked internal identifier at worst, so any purely numeric part is dropped.
     *  - A response can carry neither Error nor Detail (or no response at all), and
     *    the caller still has to say something truthful. That is what $fallback is.
     *
     * @param  mixed  $r         Status array from a domain call, or anything else.
     * @param  string $fallback  Used when nothing printable survives.
     * @return string
     */
    private function ka_status_message($r, $fallback = 'The change could not be saved.')
    {
        $parts = [];
        if (is_array($r)) {
            foreach (['Error', 'Detail'] as $field) {
                if (!isset($r[$field]) || !is_scalar($r[$field])) {
                    continue;
                }
                $piece = $this->ka_plain_text($r[$field]);
                // A numeric-only Error/Detail is an id, not a message.
                if ($piece === '' || preg_match('/^[0-9]+$/', $piece)) {
                    continue;
                }
                $parts[] = $piece;
            }
        }
        return $parts ? implode(': ', $parts) : $fallback;
    }

    /**
     * Flatten officer-entered text (park names, award names, domain details) that is
     * about to be returned as JSON.
     *
     * The kingdom admin modal renders these through innerHTML, and park names are
     * free text an officer typed. Angle brackets are removed rather than escaped:
     * this text is never markup, and stripping keeps it readable whether the
     * consumer uses innerHTML or textContent, where entities would show verbatim.
     *
     * @param  mixed $value
     * @return string
     */
    private function ka_plain_text($value)
    {
        return trim(str_replace(['<', '>'], '', strip_tags((string)$value)));
    }

}
