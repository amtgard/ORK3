<?php

class Controller_PlayerAjax extends Controller
{
    // Generic username-availability probe. Used by the SelfReg form, the
    // Park/Kingdom "Create Player" modals, and profile edit so we can warn the
    // user up-front instead of silently mangling their chosen name with a
    // -xxxxx suffix at SelfRegister/CreatePlayer time. Requires a logged-in
    // session — the SelfReg public path has its own token-gated equivalent
    // in Controller_SelfReg::check_username().
    public function check_username($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $this->load_model('Player');
        $candidate = trim($_POST['UserName'] ?? '');
        echo json_encode(self::username_check_payload($candidate, $this->Player));
        exit;
    }

    // Shared helper — used by check_username() above AND by
    // Controller_SelfReg::check_username(). Same JSON contract so the JS
    // helper (initUsernameAvailabilityCheck in revised.js) works with both.
    public static function username_check_payload($candidate, $player = null)
    {
        $candidate = trim((string)$candidate);
        if (strlen($candidate) < 4) {
            return ['status' => 0, 'available' => false, 'reason' => 'too-short', 'username' => $candidate];
        }
        if ($player === null) {
            $player = new Model_Player();
        }
        $available = $player->check_username_available($candidate);
        return ['status' => 0, 'available' => $available, 'username' => $candidate];
    }

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

        if ($action === 'create') {
            $this->load_model('Player');
            $persona    = trim($_POST['Persona']    ?? '');
            $givenName  = trim($_POST['GivenName']  ?? '');
            $surname    = trim($_POST['Surname']    ?? '');
            $email      = trim($_POST['Email']      ?? '');
            $userName   = trim($_POST['UserName']   ?? '');
            $password   = $_POST['Password'] ?? '';
            $restricted    = (int)($_POST['Restricted']   ?? 0);
            $waivered      = (int)($_POST['Waivered']     ?? 0);
            $pronounId     = (int)($_POST['PronounId']    ?? 0);
            $pronounCustom = trim($_POST['PronounCustom'] ?? '');

            if (!strlen($persona)) {
                echo json_encode(['status' => 1, 'error' => 'Persona is required.']);
                exit;
            }
            if (!strlen($userName)) {
                echo json_encode(['status' => 1, 'error' => 'Username is required.']);
                exit;
            }
            if (strlen($userName) < 4) {
                echo json_encode(['status' => 1, 'error' => 'Username must be at least 4 characters.']);
                exit;
            }
            $request = [
                'Token'         => $this->session->token,
                'ParkId'        => $park_id,
                'GivenName'     => $givenName,
                'Surname'       => $surname,
                'OtherName'     => '',
                'UserName'      => $userName,
                'Persona'       => $persona,
                'Email'         => $email,
                'Password'      => $password,
                'Restricted'    => $restricted,
                'Waivered'      => $waivered,
                'HasImage'      => 0,
                'Image'         => '',
                'IsActive'      => 1,
                'PronounId'     => $pronounId > 0 ? $pronounId : null,
                'PronounCustom' => strlen($pronounCustom) ? $pronounCustom : null,
            ];

            if (!empty($_FILES['Waiver']['tmp_name']) && is_uploaded_file($_FILES['Waiver']['tmp_name'])) {
                $allowed = ['image/png', 'image/jpeg', 'image/gif', 'application/pdf'];
                if (in_array($_FILES['Waiver']['type'], $allowed)) {
                    $ext = pathinfo($_FILES['Waiver']['name'], PATHINFO_EXTENSION);
                    $request['Waiver']    = base64_encode(file_get_contents($_FILES['Waiver']['tmp_name']));
                    $request['WaiverExt'] = strtolower($ext);
                }
            }

            $r = $this->Player->create_player($request);
            if ($r['Status'] == 0) {
                echo json_encode(['status' => 0, 'mundaneId' => (int)($r['Detail'] ?? 0)]);
            } else {
                echo json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
            }

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function player($p = null)
    {
        header('Content-Type: application/json');
        $parts     = explode('/', $p ?? '');
        $player_id = (int)preg_replace('/[^0-9]/', '', $parts[0] ?? '');
        $action    = $parts[1] ?? '';

        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }

        if (!valid_id($player_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }

        $this->load_model('Player');

        if ($action === 'revokeaward') {
            $awards_id  = (int)($_POST['AwardsId']   ?? 0);
            $revocation = trim($_POST['Revocation'] ?? '');
            if (!valid_id($awards_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }
            if (!strlen($revocation)) {
                echo json_encode(['status' => 1, 'error' => 'Revocation reason is required.']);
                exit;
            }
            $r = $this->Player->revoke_player_award([
                'Token'       => $this->session->token,
                'AwardsId'    => $awards_id,
                'RecipientId' => $player_id,
                'Revocation'  => $revocation,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'reactivateaward') {
            $awards_id = (int)($_POST['AwardsId'] ?? 0);
            if (!valid_id($awards_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }
            $r = $this->Player->reactivate_player_award([
                'Token'       => $this->session->token,
                'AwardsId'    => $awards_id,
                'RecipientId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'addnote') {
            $note     = trim($_POST['Note']         ?? '');
            $desc     = trim($_POST['Description']  ?? '');
            $date     = trim($_POST['Date']         ?? '');
            $dateComp = trim($_POST['DateComplete'] ?? '');
            if (!strlen($note)) {
                echo json_encode(['status' => 1, 'error' => 'Note title is required.']);
                exit;
            }
            if (!strlen($date)) {
                echo json_encode(['status' => 1, 'error' => 'Date is required.']);
                exit;
            }
            $r = $this->Player->add_note([
                'Token'        => $this->session->token,
                'MundaneId'    => $player_id,
                'Note'         => $note,
                'Description'  => $desc,
                'Date'         => $date,
                'DateComplete' => $dateComp,
                'GivenBy'      => (int)$this->session->user_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'notesId' => (int)($r['Detail'] ?? 0)])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'deletenote') {
            $notes_id = (int)($_POST['NotesId'] ?? 0);
            if (!valid_id($notes_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid note ID.']);
                exit;
            }
            $r = $this->Player->remove_note([
                'Token'     => $this->session->token,
                'NotesId'   => $notes_id,
                'MundaneId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'editnote') {
            $notes_id = (int)($_POST['NotesId']    ?? 0);
            $note     = trim($_POST['Note']         ?? '');
            $desc     = trim($_POST['Description']  ?? '');
            $date     = trim($_POST['Date']         ?? '');
            $dateComp = trim($_POST['DateComplete'] ?? '');
            if (!valid_id($notes_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid note ID.']);
                exit;
            }
            if (!strlen($note)) {
                echo json_encode(['status' => 1, 'error' => 'Note title is required.']);
                exit;
            }
            if (!strlen($date)) {
                echo json_encode(['status' => 1, 'error' => 'Date is required.']);
                exit;
            }
            $r = $this->Player->edit_note([
                'Token'        => $this->session->token,
                'NotesId'      => $notes_id,
                'MundaneId'    => $player_id,
                'Note'         => $note,
                'Description'  => $desc,
                'Date'         => $date,
                'DateComplete' => $dateComp,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'clearnotes') {
            $r = $this->Player->clear_notes([
                'Token'     => $this->session->token,
                'MundaneId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'moveplayer') {
            $dest_park_id = (int)($_POST['ParkId'] ?? 0);
            if (!valid_id($dest_park_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid park ID.']);
                exit;
            }
            $r = $this->Player->move_player([
                'Token'     => $this->session->token,
                'MundaneId' => $player_id,
                'ParkId'    => $dest_park_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'deleteaward') {
            $awards_id = (int)($_POST['AwardsId'] ?? 0);
            if (!valid_id($awards_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }
            $r = $this->Player->delete_player_award([
                'Token'       => $this->session->token,
                'AwardsId'    => $awards_id,
                'RecipientId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'removeimage') {
            $r = $this->Player->remove_image([
                'Token'     => $this->session->token,
                'MundaneId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'removeheraldry') {
            $r = $this->Player->remove_heraldry([
                'Token'     => $this->session->token,
                'MundaneId' => $player_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'revokeallawards') {
            $revocation = trim($_POST['Revocation'] ?? '');
            if (!strlen($revocation)) {
                echo json_encode(['status' => 1, 'error' => 'Revocation reason is required.']);
                exit;
            }
            $r = $this->Player->revoke_all_awards([
                'Token'      => $this->session->token,
                'MundaneId'  => $player_id,
                'Revocation' => $revocation,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'reconcileaward') {
            $awards_id        = (int)($_POST['AwardsId']        ?? 0);
            $kingdom_award_id = (int)($_POST['KingdomAwardId'] ?? 0);
            $rank             = (int)($_POST['Rank']           ?? 0);
            $date             = trim($_POST['Date']            ?? '');
            $given_by_id      = (int)($_POST['GivenById']      ?? 0);
            $note             = trim($_POST['Note']            ?? '');
            $park_id          = (int)($_POST['ParkId']         ?? 0);
            $kingdom_id       = (int)($_POST['KingdomId']      ?? 0);
            $event_id         = (int)($_POST['EventId']        ?? 0);
            if (!valid_id($awards_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid award ID.']);
                exit;
            }
            if (!valid_id($kingdom_award_id)) {
                echo json_encode(['status' => 1, 'error' => 'A target award is required.']);
                exit;
            }
            $r = $this->Player->reconcile_player_award([
                'Token'          => $this->session->token,
                'AwardsId'       => $awards_id,
                'KingdomAwardId' => $kingdom_award_id,
                'Rank'           => $rank,
                'Date'           => $date,
                'GivenById'      => $given_by_id,
                'Note'           => $note,
                'ParkId'         => valid_id($park_id) ? $park_id : 0,
                'KingdomId'      => valid_id($kingdom_id) ? $kingdom_id : 0,
                'EventId'        => valid_id($event_id) ? $event_id : 0,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'updateclasses') {
            $reconcile_raw = $_POST['Reconciled'] ?? [];
            if (!is_array($reconcile_raw)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid reconciliation data.']);
                exit;
            }
            $reconcile = [];
            foreach ($reconcile_raw as $class_id => $qty) {
                $reconcile[] = ['ClassId' => (int)$class_id, 'Quantity' => (int)$qty];
            }
            $r = $this->Player->update_class_reconciliation([
                'Token'     => $this->session->token,
                'MundaneId' => $player_id,
                'ParkId'    => (int)($_POST['ParkId'] ?? 0),
                'Reconcile' => $reconcile,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } elseif ($action === 'awardranks') {
            $this->load_model('Player');
            $ranks = $this->Player->get_award_max_ranks((int)$player_id);
            echo json_encode($ranks);

        } elseif ($action === 'info') {
            $this->load_model('Player');
            $player = $this->Player->fetch_player($player_id);
            if ($player) {
                echo json_encode(['status' => 0, 'MundaneId' => $player_id, 'Persona' => $player['Persona']]);
            } else {
                echo json_encode(['status' => 1, 'error' => 'Player not found']);
            }
            exit;

        } elseif ($action === 'updateprofile') {
            // Own-profile customization: about, colors, name prefix/suffix, photo focus.
            // ORK admins may also edit any player's profile (e.g. to remove inappropriate content).
            $uid = (int)$this->session->user_id;
            $_isOrkAdmin = $uid > 0 && $this->Authorization->has_authority($uid, AUTH_ADMIN, null, null);
            if ($uid !== $player_id && !$_isOrkAdmin) {
                echo json_encode(['status' => 5, 'error' => 'You can only customize your own profile.']);
                exit;
            }
            $fields = [
                'Token'         => $this->session->token,
                'MundaneId'     => $player_id,
                'AboutPersona'  => isset($_POST['AboutPersona']) ? $_POST['AboutPersona'] : null,
                'AboutStory'    => isset($_POST['AboutStory']) ? $_POST['AboutStory'] : null,
                'ColorPrimary'  => (isset($_POST['ColorPrimary']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorPrimary'])) ? $_POST['ColorPrimary'] : null,
                'ColorAccent'   => (isset($_POST['ColorAccent']) && preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorAccent'])) ? $_POST['ColorAccent'] : null,
                'ColorSecondary' => isset($_POST['ColorSecondary']) ? (preg_match('/^#[0-9a-fA-F]{6}$/', $_POST['ColorSecondary']) ? $_POST['ColorSecondary'] : '') : null,
                'HeroGradient'  => isset($_POST['HeroGradient']) ? substr(trim((string)$_POST['HeroGradient']), 0, 32) : null,
                'HeroOverlay'   => isset($_POST['HeroOverlay']) ? $_POST['HeroOverlay'] : null,
                'NamePrefix'    => isset($_POST['NamePrefix']) ? trim($_POST['NamePrefix']) : null,
                'NameSuffix'    => isset($_POST['NameSuffix']) ? trim($_POST['NameSuffix']) : null,
                'SuffixComma'   => isset($_POST['SuffixComma']) ? (int)$_POST['SuffixComma'] : null,
                'Persona'       => isset($_POST['Persona']) ? trim($_POST['Persona']) : null,
                'PhotoFocusX'   => isset($_POST['PhotoFocusX']) ? (int)$_POST['PhotoFocusX'] : null,
                'PhotoFocusY'   => isset($_POST['PhotoFocusY']) ? (int)$_POST['PhotoFocusY'] : null,
                'PhotoFocusSize' => isset($_POST['PhotoFocusSize']) ? (int)$_POST['PhotoFocusSize'] : null,
                'ShowBeltline'  => isset($_POST['ShowBeltline']) ? (int)$_POST['ShowBeltline'] : null,
                'ShowFeastPrefs' => isset($_POST['ShowFeastPrefs']) ? (int)$_POST['ShowFeastPrefs'] : null,
                'PronunciationGuide' => isset($_POST['PronunciationGuide']) ? trim($_POST['PronunciationGuide']) : null,
                'ShowMundaneFirst' => isset($_POST['ShowMundaneFirst']) ? (int)$_POST['ShowMundaneFirst'] : null,
                'ShowMundaneLast'  => isset($_POST['ShowMundaneLast']) ? (int)$_POST['ShowMundaneLast'] : null,
                'ShowEmail'        => isset($_POST['ShowEmail']) ? (int)$_POST['ShowEmail'] : null,
                'MilestoneConfig'  => isset($_POST['MilestoneConfig']) ? $_POST['MilestoneConfig'] : null,
                'NameFont'         => (isset($_POST['NameFont']) && in_array($_POST['NameFont'], ['','Cinzel','Cinzel Decorative','IM Fell English','UnifrakturMaguntia','Metamorphous','Uncial Antiqua','Pirata One','Almendra','Pinyon Script','Great Vibes'])) ? $_POST['NameFont'] : null,
                'NameShadow'       => isset($_POST['NameShadow']) ? (int)$_POST['NameShadow'] : null,
                'BeltDisplay'      => (isset($_POST['BeltDisplay']) && in_array($_POST['BeltDisplay'], ['white','own','none'])) ? $_POST['BeltDisplay'] : null,
                // Administrative fields — UpdatePlayer gates these behind HasAuthority,
                // so non-officers sending them have no effect.
                'Active'           => isset($_POST['Active']) ? (int)$_POST['Active'] : null,
                'Waivered'         => isset($_POST['Waivered']) ? (int)$_POST['Waivered'] : null,
                'ParkMemberSince'  => isset($_POST['ParkMemberSince']) ? trim($_POST['ParkMemberSince']) : null,
            ];
            $r = $this->Player->update_player($fields);
            $_isProf = ($r['Status'] != 0 && ($r['Error'] ?? '') === ProfanityFilter::ERROR_MESSAGE);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : ($_isProf
                    ? json_encode(['status' => $r['Status'], 'error' => $r['Error'], 'field' => $r['Detail'] ?? ''])
                    : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]));

        } elseif ($action === 'addmilestone') {
            $description = trim($_POST['Description'] ?? '');
            $icon        = trim($_POST['Icon'] ?? 'fa-star');
            $msDate      = trim($_POST['MilestoneDate'] ?? '');
            if (!strlen($description)) {
                echo json_encode(['status' => 1, 'error' => 'Description is required.']);
                exit;
            }
            if (!strlen($msDate) || !strtotime($msDate)) {
                echo json_encode(['status' => 1, 'error' => 'A valid date is required.']);
                exit;
            }
            $r = $this->Player->add_custom_milestone([
                'Token'         => $this->session->token,
                'MundaneId'     => $player_id,
                'Icon'          => $icon,
                'Description'   => $description,
                'MilestoneDate' => $msDate,
            ]);
            $_isProf = ($r['Status'] != 0 && ($r['Error'] ?? '') === ProfanityFilter::ERROR_MESSAGE);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0, 'milestoneId' => (int)($r['Detail'] ?? 0)])
                : ($_isProf
                    ? json_encode(['status' => $r['Status'], 'error' => $r['Error'], 'field' => $r['Detail'] ?? ''])
                    : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]));

        } elseif ($action === 'updatemilestone') {
            $milestone_id = (int)($_POST['MilestoneId'] ?? 0);
            $description  = trim($_POST['Description'] ?? '');
            $icon         = trim($_POST['Icon'] ?? '');
            $msDate       = trim($_POST['MilestoneDate'] ?? '');
            if (!valid_id($milestone_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid milestone ID.']);
                exit;
            }
            $r = $this->Player->update_custom_milestone([
                'Token'         => $this->session->token,
                'MundaneId'     => $player_id,
                'MilestoneId'   => $milestone_id,
                'Icon'          => $icon,
                'Description'   => $description,
                'MilestoneDate' => $msDate,
            ]);
            $_isProf = ($r['Status'] != 0 && ($r['Error'] ?? '') === ProfanityFilter::ERROR_MESSAGE);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : ($_isProf
                    ? json_encode(['status' => $r['Status'], 'error' => $r['Error'], 'field' => $r['Detail'] ?? ''])
                    : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]));

        } elseif ($action === 'deletemilestone') {
            $milestone_id = (int)($_POST['MilestoneId'] ?? 0);
            if (!valid_id($milestone_id)) {
                echo json_encode(['status' => 1, 'error' => 'Invalid milestone ID.']);
                exit;
            }
            $r = $this->Player->delete_custom_milestone([
                'Token'         => $this->session->token,
                'MundaneId'     => $player_id,
                'MilestoneId'   => $milestone_id,
            ]);
            echo ($r['Status'] == 0)
                ? json_encode(['status' => 0])
                : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);

        } else {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
        }
        exit;
    }

    public function merge($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $uid     = (int)$this->session->user_id;
        $from_id = (int)($_POST['FromMundaneId'] ?? 0);
        $to_id   = (int)($_POST['ToMundaneId']   ?? 0);
        if (!valid_id($from_id) || !valid_id($to_id)) {
            echo json_encode(['status' => 1, 'error' => 'Both player IDs are required.']);
            exit;
        }
        if ($from_id === $to_id) {
            echo json_encode(['status' => 1, 'error' => 'Cannot merge a player with themselves.']);
            exit;
        }
        $this->load_model('Player');
        $r = $this->Player->merge_player([
            'Token'         => $this->session->token,
            'FromMundaneId' => $from_id,
            'ToMundaneId'   => $to_id,
        ]);
        echo ($r['Status'] == 0)
            ? json_encode(['status' => 0])
            : json_encode(['status' => $r['Status'], 'error' => rtrim(($r['Error'] ?? 'Error') . ': ' . ($r['Detail'] ?? ''), ': ')]);
        exit;
    }
    public function voting_eligible($p = null)
    {
        header('Content-Type: application/json');
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }
        $this->load_model('Reports');
        $report = new Report();
        $lookup = $report->GetVotingEligibleForPlayer(['MundaneId' => $mundane_id]);
        if (($lookup['Status']['Status'] ?? 1) != 0 && ($lookup['KingdomId'] ?? 0) <= 0) {
            echo json_encode(['status' => 1, 'error' => 'Player not found']);
            exit;
        }
        $kingdom_id = (int)($lookup['KingdomId'] ?? 0);
        if (!in_array($kingdom_id, $this->Reports->supported_voting_kingdom_ids(), true)) {
            echo json_encode(['status' => 0, 'eligible' => false]);
            exit;
        }
        $player = $lookup['Players'][0] ?? [];
        echo json_encode([
            'status'           => 0,
            'eligible'         => !empty($player['VotingEligible']),
            'province_mode'    => !empty($lookup['ProvinceMode']),
            'province_eligible' => !empty($player['ProvinceEligible']),
            'active_knight'    => !empty($player['ActiveKnight']),
            'active_member'    => $player['ActiveMember'] ?? null,
        ]);
        exit;
    }

    public function attendance($p = null)
    {
        header('Content-Type: application/json');
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }
        $this->load_model('Player');
        $attendance = $this->Player->fetch_player_attendance($mundane_id);
        $parkEditAuth = [];
        if (isset($this->session->user_id)) {
            $uid = (int)$this->session->user_id;
            $uniqueParkIds = array_unique(array_filter(array_column(
                array_filter($attendance, fn ($a) => (int)($a['EventId'] ?? 0) === 0),
                'ParkId'
            )));
            foreach ($uniqueParkIds as $pid) {
                if (valid_id($pid)) {
                    $parkEditAuth[(int)$pid] = (bool)$this->Authorization->has_authority($uid, AUTH_PARK, (int)$pid, AUTH_EDIT);
                }
            }
        }
        echo json_encode([
            'status'               => 0,
            'attendance'           => $attendance,
            'parkEditAuth'         => $parkEditAuth,
            'canEditAnyAttendance' => !empty(array_filter($parkEditAuth)),
            'total'                => count($attendance),
            'lastClass'            => !empty($attendance[0]['ClassName']) ? $attendance[0]['ClassName'] : '',
        ]);
        exit;
    }

    public function all_dues($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }
        $this->load_model('Player');
        $dues = $this->Player->get_dues($mundane_id, 0, false);
        echo json_encode(['status' => 0, 'dues' => is_array($dues) ? $dues : []]);
        exit;
    }

    public function notes($p = null)
    {
        header('Content-Type: application/json');
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }
        $this->load_model('Player');
        $notes = $this->Player->get_notes($mundane_id);
        echo json_encode(['status' => 0, 'notes' => is_array($notes) ? $notes : []]);
        exit;
    }

    public function recommendations($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid player ID']);
            exit;
        }
        $this->load_model('Reports');
        $recs = $this->Reports->recommended_awards([
            'PlayerId' => $mundane_id, 'KingdomId' => 0, 'ParkId' => 0,
            'IncludeKnights' => 1, 'IncludeMasters' => 1, 'IncludeLadder' => 1, 'LadderMinimum' => 0,
            'RequestedBy' => (int)$this->session->user_id,
        ]);
        echo json_encode(['status' => 0, 'recs' => is_array($recs) ? $recs : []]);
        exit;
    }

    public function save_my_email()
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $email = trim($_POST['email'] ?? '');
        if (!strlen($email)) {
            echo json_encode(['status' => 1, 'error' => 'Email address is required.']);
            exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status' => 1, 'error' => 'Please enter a valid email address.']);
            exit;
        }
        $this->load_model('Player');
        $r = $this->Player->save_own_email($email);
        echo json_encode(['status' => (int)($r['Status'] ?? 1), 'error' => $r['Error'] ?? '']);
        exit;
    }

    // ---- Recommendation seconds ----

    public function add_second($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $rec_id = (int)($p ?? 0);
        if (!valid_id($rec_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid recommendation']);
            exit;
        }
        $notes = isset($_POST['notes']) ? (string)$_POST['notes'] : '';
        $this->load_model('Player');
        $r = $this->Player->AddSecondToRecommendation([
            'Token' => $this->session->token,
            'RecommendationsId' => $rec_id,
            'Notes' => $notes,
        ]);
        $persona = (int)($r['Status'] ?? 1) === 0 ? (string)($r['SupporterPersona'] ?? '') : '';
        echo json_encode(['status' => (int)($r['Status'] ?? 1), 'error' => $r['Error'] ?? '', 'detail' => $r['Detail'] ?? '', 'supporter_persona' => $persona]);
        exit;
    }

    public function edit_second_notes($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $sid = (int)($p ?? 0);
        if (!valid_id($sid)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid second']);
            exit;
        }
        $notes = isset($_POST['notes']) ? (string)$_POST['notes'] : '';
        $this->load_model('Player');
        $r = $this->Player->EditSecondNotes([
            'Token' => $this->session->token,
            'RecommendationSecondsId' => $sid,
            'Notes' => $notes,
        ]);
        echo json_encode(['status' => (int)($r['Status'] ?? 1), 'error' => $r['Error'] ?? '', 'detail' => $r['Detail'] ?? '']);
        exit;
    }

    public function withdraw_second($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $sid = (int)($p ?? 0);
        if (!valid_id($sid)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid second']);
            exit;
        }
        $this->load_model('Player');
        $r = $this->Player->WithdrawSecond([
            'Token' => $this->session->token,
            'RecommendationSecondsId' => $sid,
        ]);
        echo json_encode(['status' => (int)($r['Status'] ?? 1), 'error' => $r['Error'] ?? '', 'detail' => $r['Detail'] ?? '']);
        exit;
    }

    public function edit_recommendation_reason($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $rec_id = (int)($p ?? 0);
        if (!valid_id($rec_id)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid recommendation']);
            exit;
        }
        $reason = isset($_POST['reason']) ? (string)$_POST['reason'] : '';
        $this->load_model('Player');
        $r = $this->Player->EditAwardRecommendationReason([
            'Token' => $this->session->token,
            'RecommendationsId' => $rec_id,
            'Reason' => $reason,
        ]);
        echo json_encode(['status' => (int)($r['Status'] ?? 1), 'error' => $r['Error'] ?? '', 'detail' => $r['Detail'] ?? '']);
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
        $mundane_id_target = (int)preg_replace('/[^0-9]/', '', $params[0] ?? '');
        $action  = $params[1] ?? '';

        if (!valid_id($mundane_id_target)) {
            echo json_encode(['status' => 1, 'error' => 'Invalid Player ID.']);
            exit;
        }

        $this->load_model('Banner');
        $this->Banner->handle_ajax(
            'Player',
            $action,
            $mundane_id_target,
            $this->session->token,
            $_POST,
            $_FILES,
        );
    }

    public function dietary_preferences($p = null)
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $mundane_id = (int)($p ?? 0);
        if (!valid_id($mundane_id) || (int)$mundane_id !== (int)$this->session->user_id) {
            echo json_encode(['status' => 1, 'error' => 'Access denied']);
            exit;
        }
        $this->load_model('Player');
        $prefs = $this->Player->GetDietaryPreferences($mundane_id);
        echo json_encode(['status' => 0, 'prefs' => $prefs ?: []]);
        exit;
    }

    public function save_dietary_preferences()
    {
        header('Content-Type: application/json');
        if (!isset($this->session->user_id)) {
            echo json_encode(['status' => 5, 'error' => 'Not logged in']);
            exit;
        }
        $mundane_id = (int)$this->session->user_id;
        $data = [
            'IsAnonymous'       => (int)!empty($_POST['IsAnonymous']),
            'NoRestrictions'    => (int)!empty($_POST['NoRestrictions']),
            'DietVegetarian'    => (int)!empty($_POST['DietVegetarian']),
            'DietVegan'         => (int)!empty($_POST['DietVegan']),
            'DietHalal'         => (int)!empty($_POST['DietHalal']),
            'DietKosher'        => (int)!empty($_POST['DietKosher']),
            'DietKeto'          => (int)!empty($_POST['DietKeto']),
            'DietPaleo'         => (int)!empty($_POST['DietPaleo']),
            'RestrictDairy'     => (int)!empty($_POST['RestrictDairy']),
            'RestrictEggs'      => (int)!empty($_POST['RestrictEggs']),
            'RestrictFish'      => (int)!empty($_POST['RestrictFish']),
            'RestrictHoney'     => (int)!empty($_POST['RestrictHoney']),
            'RestrictPoultry'   => (int)!empty($_POST['RestrictPoultry']),
            'RestrictBeef'      => (int)!empty($_POST['RestrictBeef']),
            'RestrictPork'      => (int)!empty($_POST['RestrictPork']),
            'RestrictShellfish' => (int)!empty($_POST['RestrictShellfish']),
            'AllergenMilk'      => max(0, min(2, (int)($_POST['AllergenMilk']      ?? 0))),
            'AllergenEggs'      => max(0, min(2, (int)($_POST['AllergenEggs']      ?? 0))),
            'AllergenFish'      => max(0, min(2, (int)($_POST['AllergenFish']      ?? 0))),
            'AllergenShellfish' => max(0, min(2, (int)($_POST['AllergenShellfish'] ?? 0))),
            'AllergenTreenuts'  => max(0, min(2, (int)($_POST['AllergenTreenuts']  ?? 0))),
            'AllergenPeanuts'   => max(0, min(2, (int)($_POST['AllergenPeanuts']   ?? 0))),
            'AllergenWheat'     => max(0, min(2, (int)($_POST['AllergenWheat']     ?? 0))),
            'AllergenSoy'       => max(0, min(2, (int)($_POST['AllergenSoy']       ?? 0))),
            'AllergenSesame'    => max(0, min(2, (int)($_POST['AllergenSesame']    ?? 0))),
            'AllergenGarlic'    => max(0, min(2, (int)($_POST['AllergenGarlic']    ?? 0))),
            'AllergenGluten'    => max(0, min(2, (int)($_POST['AllergenGluten']    ?? 0))),
            'AllergenOnion'     => max(0, min(2, (int)($_POST['AllergenOnion']     ?? 0))),
            'AllergenMushroom'  => max(0, min(2, (int)($_POST['AllergenMushroom']  ?? 0))),
            'AllergenCorn'      => max(0, min(2, (int)($_POST['AllergenCorn']      ?? 0))),
            'AllergenCoconut'   => max(0, min(2, (int)($_POST['AllergenCoconut']   ?? 0))),
            'AllergenCocoa'       => max(0, min(2, (int)($_POST['AllergenCocoa']       ?? 0))),
            'AllergenNightshades' => max(0, min(2, (int)($_POST['AllergenNightshades'] ?? 0))),
        ];
        $this->load_model('Player');
        $this->Player->SaveDietaryPreferences($mundane_id, $data);
        echo json_encode(['status' => 0]);
        exit;
    }

}
