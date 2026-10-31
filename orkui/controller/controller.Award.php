<?php

class Controller_Award extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);

        $this->load_model('Player');
        $this->load_model('Park');
        $this->load_model('Kingdom');
        $params = explode('/', $id);
        $id = (int) $params[0];

        switch ($call) {
            case 'park':
                $park_info = $this->Park->get_park_info($id);
                $this->park_info = $park_info;
                $this->session->park_name = $park_info['ParkInfo']['ParkName'];
                $this->session->park_id = $park_info['ParkInfo']['ParkId'];
                $this->data['menu']['park'] = array('url' => UIR . 'Park/index/' . $this->session->park_id, 'display' => $this->session->park_name);
                $id = $park_info['KingdomInfo']['KingdomId'];
                // no break
            case 'kingdom':
                $kingdom_info = $this->Kingdom->get_kingdom_details($id);
                $this->kingdom_info = $kingdom_info;
                $this->session->kingdom_id = $kingdom_info['KingdomInfo']['KingdomId'];
                $this->session->kingdom_name = $kingdom_info['KingdomInfo']['KingdomName'];
                $this->data['menu']['kingdom'] = array('url' => UIR . 'Kingdom/profile/' . $this->session->kingdom_id, 'display' => $this->session->kingdom_name);
                $this->data['KingdomId'] = $id;
        }
        $this->data['Call'] = $call;
    }

    public function index($action = null)
    {
    }

    public function park($id)
    {
        $this->handle_award_route($id, 'park');
    }

    public function kingdom($id)
    {
        $this->handle_award_route($id, 'kingdom');
    }

    private function handle_award_route($id, $type)
    {
        $params = explode('/', $id);
        $id = (int) $params[0];
        $action = null;

        if (count($params) > 1) {
            $action = $params[1];
        }

        if (strlen($action) > 0) {
            $this->handle_action($action, "Login/login/Award/$type/$id");
        }

        $this->set_template();
        $this->set_award_data($id);
    }

    private function handle_action($action, $route)
    {
        $this->request->save('Award_addawards', true);
        if (!isset($this->session->user_id)) {
            header('Location: ' . UIR . $route);
            exit;
        } else {
            if ($action == 'addaward' && $this->is_request_valid()) {
                $r = $this->add_award();

                if ($r['Status'] == 0) {
                    $this->handle_success((int) ($r['AwardsId'] ?? 0));
                } elseif ($r['Status'] == 5) {
                    header('Location: ' . UIR . $route);
                    exit;
                } else {
                    $this->data['Error'] = $r['Error'] . ':<p>' . $r['Detail'];
                }
            }
        }
    }
    private function is_request_valid()
    {
        if (!valid_id($this->request->Award_addawards->MundaneId)) {
            $this->data['Error'] = 'You must choose a recipient. Award not added!';
            return false;
        }
        if (!valid_id($this->request->Award_addawards->AwardId)) {
            $this->data['Error'] = 'You must choose an award. Award not added!';
            return false;
        }
        if (!valid_id($this->request->Award_addawards->GivenById)) {
            $this->data['Error'] = 'Who gave this award? Award not added!';
            return false;
        }
        // "Where given" is required. Player::AddAward enforces it for every grant
        // path; this pre-check only gives the form a friendlier message. Every real
        // Search/Location result carries a KingdomId, so a park OR a kingdom is
        // always available once a location has actually been chosen.
        if (!valid_id($this->request->Award_addawards->ParkId)
            && !valid_id($this->request->Award_addawards->KingdomId)) {
            $this->data['Error'] = 'Where was this award given? Choose a location from the list. Award not added!';
            return false;
        }
        return true;
    }
    private function add_award()
    {
        $isOfficer = $this->request->Award_addawards->awardtype === 'officers';
        return $this->Player->add_player_award(array(
            'Token' => $this->session->token,
            'RecipientId' => $this->request->Award_addawards->MundaneId,
            'KingdomAwardId' => $this->request->Award_addawards->AwardId,
            'Rank' => $isOfficer ? null : $this->request->Award_addawards->Rank,
            'Date' => $this->request->Award_addawards->Date,
            'GivenById' => $this->request->Award_addawards->GivenById,
            'Note' => $this->request->Award_addawards->Note,
            'CustomName' => $this->request->Award_addawards->AwardName,
            'AliasAwardId' => $this->request->Award_addawards->AliasAwardId ?? 0,
            'ParkId' => valid_id($this->request->Award_addawards->ParkId) ? $this->request->Award_addawards->ParkId : 0,
            'KingdomId' => valid_id($this->request->Award_addawards->KingdomId) ? $this->request->Award_addawards->KingdomId : 0,
            'EventId' => valid_id($this->request->Award_addawards->EventId) ? $this->request->Award_addawards->EventId : 0
        ));
    }
    // $awardsId is accepted but unused: Award_addawards.tpl renders $Message alone and
    // has no print affordance to hang a new award's id on. It used to be spent on
    // $this->data['PrintAwardsId'], a key that appeared nowhere else in the repo, and
    // computing it ran Model_Scrollery::award_is_printable() -- which materialises the
    // recipient's whole award list -- on every single award grant in the ORK. The
    // parameter stays so item 28 can wire a real print offer here without re-plumbing
    // handle_action().
    private function handle_success(int $awardsId = 0)
    {
        $this->data['Message'] = 'Award recorded for ' . $this->request->Award_addawards->GivenTo;
        $this->request->clear('Player_index');
        unset($_REQUEST['MundaneId']);
        unset($_REQUEST['AwardId']);
        unset($_REQUEST['Rank']);
        unset($_REQUEST['Note']);
        unset($_REQUEST['GivenTo']);
        $this->request->save('Award_addawards', true);
    }
    private function set_template()
    {
        $this->template = 'Award_addawards.tpl';
    }
    private function set_award_data($id)
    {
        if ($this->request->exists('Award_addawards')) {
            $this->data['Award_addawards'] = $this->request->Award_addawards->Request;
        }
        $this->data['AwardOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Awards');
        $this->data['OfficerOptions'] = $this->Award->fetch_award_option_list($this->session->kingdom_id, 'Officers');
        $this->data['Id'] = $id;

        // Preload Kingdom and Park Monarch/Regent for GivenBy autocomplete
        $preloadOfficers = array();
        $kingdomOfficers = $this->Kingdom->get_officers($this->session->kingdom_id, $this->session->token);
        if (is_array($kingdomOfficers)) {
            foreach ($kingdomOfficers as $officer) {
                if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
                    $preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Kingdom ' . $officer['OfficerRole']);
                }
            }
        }
        if (valid_id($this->session->park_id)) {
            $parkOfficers = $this->Park->get_officers($this->session->park_id, $this->session->token);
            if (is_array($parkOfficers)) {
                foreach ($parkOfficers as $officer) {
                    if (in_array($officer['OfficerRole'], array('Monarch', 'Regent')) && $officer['MundaneId'] > 0) {
                        $preloadOfficers[] = array('MundaneId' => $officer['MundaneId'], 'Persona' => $officer['Persona'], 'Role' => 'Park ' . $officer['OfficerRole']);
                    }
                }
            }
        }
        $this->data['PreloadOfficers'] = $preloadOfficers;
        // "Where given" is one decision, not three: the re-shown request wins as a whole,
        // or the officer's own scope does as a whole. Resolving the name and the ids
        // separately let the box read "The Celestial Kingdom" while still submitting a
        // park id left over from the last grant.
        $saved = $this->data['Award_addawards'] ?? array();
        $savedScope = (valid_id($saved['ParkId'] ?? 0) || valid_id($saved['KingdomId'] ?? 0))
            && strlen(trim((string)($saved['GivenAt'] ?? ''))) > 0;
        if ($savedScope) {
            $this->data['GivenAtScope'] = array(
                'ParkId' => (int)($saved['ParkId'] ?? 0),
                'KingdomId' => (int)($saved['KingdomId'] ?? 0),
                'Name' => (string)$saved['GivenAt'],
            );
        } else {
            $default = $this->default_given_at_scope();
            $this->data['GivenAtScope'] = array(
                'ParkId' => (int)($default['ParkId'] ?? 0),
                'KingdomId' => (int)($default['KingdomId'] ?? 0),
                'Name' => (string)($default['Name'] ?? ''),
            );
        }
    }

    /**
     * "Where given" defaults to the granting officer's own scope, and is otherwise
     * blank -- is_request_valid() then refuses the submit rather than writing 0/0.
     *
     * The scope is the park or kingdom this grant page belongs to (Award/park/<id> or
     * Award/kingdom/<id>, resolved into the session by the constructor), and it is
     * offered only when the granting user actually holds authority over it. That is
     * the same AUTH_EDIT check the neighbouring surfaces already run -- Park's
     * CanManagePark, Controller_Scrollery::may_issue_for() -- rather than a fourth
     * rule of its own. Park wins over kingdom when both hold: the narrower scope is
     * the more useful attribution.
     *
     * Only Award/park/<id> looks at session->park_id. On Award/kingdom/<id> the
     * constructor never sets it, so it would still hold whatever park page was
     * visited last -- defaulting a kingdom-level grant to an unrelated park is worse
     * than defaulting it to the kingdom actually being granted from. On BOTH routes
     * the constructor overwrites the session values unconditionally from the route,
     * so what is read below is this request's context and never a leftover.
     *
     * Failing that, a global ORK admin falls back to their own persona's residency --
     * see admin_home_kingdom_scope(). Blank, and therefore required, is what is left:
     * a non-admin with no authority over the scope this page is for.
     *
     * @return array{ParkId: int, KingdomId: int, Name: string}
     */
    private function default_given_at_scope(): array
    {
        $blank = ['ParkId' => 0, 'KingdomId' => 0, 'Name' => ''];
        $uid = (int) ($this->session->user_id ?? 0);
        if ($uid <= 0) {
            return $blank;
        }

        $parkId    = ($this->data['Call'] ?? '') === 'park' ? (int) ($this->session->park_id ?? 0) : 0;
        $kingdomId = (int) ($this->session->kingdom_id ?? 0);

        // Model_Authorization::has_authority() is the safe seam here: the lib's
        // HasAuthority() returns NULL on denial, and the model's ": bool" return
        // normalises it.
        if ($parkId > 0 && $this->Authorization->has_authority($uid, AUTH_PARK, $parkId, AUTH_EDIT)) {
            return [
                'ParkId'    => $parkId,
                'KingdomId' => $kingdomId,
                'Name'      => (string) ($this->session->park_name ?? ''),
            ];
        }
        if ($kingdomId > 0 && $this->Authorization->has_authority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT)) {
            return [
                'ParkId'    => 0,
                'KingdomId' => $kingdomId,
                'Name'      => (string) ($this->session->kingdom_name ?? ''),
            ];
        }

        // No park and no kingdom came out of the route -- the id did not resolve, so
        // this admin is on the grant form outside the click path through a kingdom or
        // a park. HasAuthority() short-circuits true for a global admin against every
        // scope, so the two branches above cannot tell one apart; only a direct
        // AUTH_ADMIN probe with an all-zero scope can, which is what the base
        // Controller and Controller_Admin already use. A scoped admin grant fails it,
        // so this branch is unreachable for anyone else.
        if ($parkId <= 0 && $kingdomId <= 0
            && $this->Authorization->has_authority($uid, AUTH_ADMIN, 0, AUTH_ADMIN)) {
            return $this->admin_home_kingdom_scope($uid);
        }

        return $blank;
    }

    /**
     * A global ORK admin's fallback: the kingdom of their own persona's residency.
     *
     * Kingdom, deliberately, and not their home park even where they have one -- an
     * admin acting outside a click path is acting kingdom-wide, and the narrower
     * scope would be a guess about which park they meant.
     *
     * Read from the admin's own ork_mundane row via Model_Player, NOT from
     * session->park_id / kingdom_id: those are Controller_Admin's scratch state
     * (written at its :435, cleared on logout) and the Scrollery landing page had to
     * have the same dependency taken out of it for the same reason. mundane.kingdom_id
     * is the primary because it is the better populated of the two -- 183,424 of
     * 183,425 rows carry it against 183,423 with a park, they agree in all but one
     * row, and mundane 1 ("admin") has kingdom_id 1 with NO park at all, which a
     * park-to-kingdom derivation alone would resolve to nothing. The park hop stays as
     * the fallback for the inverse row.
     *
     * Returns blank rather than a bare id if the kingdom cannot be named: the form
     * resolves the name and the ids as one decision, and a hidden id under an empty
     * "Given At" box is the id/name disagreement that whole arrangement exists to
     * prevent. Blank is then refused by is_request_valid(), which is the safe way to
     * fail. This is only ever a default -- the field stays editable, and a submitted
     * blank is still refused server-side.
     *
     * @return array{ParkId: int, KingdomId: int, Name: string}
     */
    private function admin_home_kingdom_scope(int $uid): array
    {
        $blank = ['ParkId' => 0, 'KingdomId' => 0, 'Name' => ''];

        $me = $this->Player->fetch_player($uid);
        if (!is_array($me)) {
            return $blank;
        }

        $kingdomId = (int) ($me['KingdomId'] ?? 0);
        if ($kingdomId <= 0 && (int) ($me['ParkId'] ?? 0) > 0) {
            $parkInfo  = $this->Park->get_park_info((int) $me['ParkId']);
            $kingdomId = (int) ($parkInfo['KingdomInfo']['KingdomId'] ?? 0);
        }
        if ($kingdomId <= 0) {
            return $blank;
        }

        $details = $this->Kingdom->get_kingdom_details($kingdomId);
        $name    = (string) ($details['KingdomInfo']['KingdomName'] ?? '');

        return $name === '' ? $blank : ['ParkId' => 0, 'KingdomId' => $kingdomId, 'Name' => $name];
    }
}
