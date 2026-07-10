<?php

class Controller_Search extends Controller
{
    public function __construct($call = null, $id = null)
    {
        parent::__construct($call, $id);
        $this->data['no_index'] = true;
        header('X-Robots-Tag: noindex, nofollow');
        $_uid = isset($this->session->user_id) ? (int)$this->session->user_id : 0;
        if ($_uid > 0 && valid_id($this->session->park_id) && Ork3::$Lib->authorization->HasAuthority($_uid, AUTH_PARK, (int)$this->session->park_id, AUTH_EDIT)) {
            $this->data['menu']['admin'] = array( 'url' => UIR.'Admin/park/'.$this->session->park_id, 'display' => 'Admin Panel <i class="fas fa-cog"></i>', 'no-crumb' => 'no-crumb' );
        }
        // Expose the auth token so the search-results JS can pass it to the SOAP service
        // (admins use it to bypass the restricted-name gate in Search/Player).
        $this->data['Token'] = $this->session->token ?? '';
    }

    public function index($id = null)
    {

    }

    public function park($id = null)
    {
        $this->template = 'Search_index.tpl';
        $this->data['ParkId'] = $id;
    }

    public function kingdom($id = null)
    {
        $this->template = 'Search_index.tpl';
        $this->data['KingdomId'] = $id;
    }

    public function unit()
    {
        header('X-Robots-Tag: noindex, nofollow');
        if (isset($this->request->KingdomId)) {
            $this->data['KingdomId'] = $this->request->KingdomId;
            $this->load_model('Kingdom');
            $this->data['ScopeLabel'] = $this->Kingdom->get_kingdom_name((int)$this->request->KingdomId) ?: null;
        }
        if (isset($this->request->ParkId)) {
            $this->data['ParkId'] = $this->request->ParkId;
            $this->load_model('Park');
            $this->data['ScopeLabel'] = $this->Park->get_park_name((int)$this->request->ParkId) ?: null;
        }
    }

    public function unitsearch()
    {
        header('Content-Type: application/json');
        $this->load_model('Unit');
        $name       = trim($_GET['q'] ?? '');
        $kingdom_id = valid_id($_GET['KingdomId'] ?? 0) ? (int)$_GET['KingdomId'] : null;
        $park_id    = valid_id($_GET['ParkId']    ?? 0) ? (int)$_GET['ParkId'] : null;
        $is_default = strlen($name) === 0;
        // Retired/deactivated units are hidden unless the "Include Inactive/Retired"
        // toggle is on (sent as &include=1).
        $include_retired = !empty($_GET['include']) ? 1 : 0;
        if ($is_default) {
            // No search term → ready list of the top 100 units by roster size for the
            // scope. Two-phase + no attendance subqueries → ~180ms, so this can load
            // on page open without a minimum-character gate. Activity columns are
            // computed only once the user searches by name (below).
            $result = $this->Unit->get_unit_list([
                'KingdomId'         => $kingdom_id,
                'ParkId'            => $park_id,
                'IncludeCompanies'  => 1,
                'IncludeHouseHolds' => 1,
                'IncludeEvents'     => 1,
                'IncludeRetired'    => $include_retired,
                'TopBySize'         => 1,
                'Limit'             => 100,
            ]);
        } else {
            $result = $this->Unit->get_unit_list([
                'Name'              => $name,
                'KingdomId'         => $kingdom_id,
                'ParkId'            => $park_id,
                'IncludeCompanies'  => 1,
                'IncludeHouseHolds' => 1,
                'IncludeEvents'     => 1,
                'IncludeRetired'    => $include_retired,
                'Limit'             => 25,
                'OrderBy'           => 'u.name',
            ]);
        }
        echo json_encode($result['Units'] ?? []);
        exit;
    }

    // Lazy companion to the default unit list: given the unit_ids it just rendered,
    // return { unit_id: active_member_count } (members with a sign-in in the last 12
    // months). Bounded to the shown units (<=25) so it stays ~1s, and the front-end
    // fires it after the list is already interactive.
    public function unitactivity()
    {
        header('Content-Type: application/json');
        $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', $_GET['ids'] ?? '')))));
        if (empty($ids)) {
            echo json_encode(new stdClass());
            exit;
        }
        $ids = array_slice($ids, 0, 25);
        $cache_key = Ork3::$Lib->ghettocache->key($ids);
        if (($cache = Ork3::$Lib->ghettocache->get(__CLASS__ . '.unitactivity', $cache_key, 300)) !== false) {
            echo json_encode($cache, JSON_FORCE_OBJECT);
            exit;
        }
        $out = Ork3::$Lib->searchservice->GetUnitActivityCounts($ids);
        Ork3::$Lib->ghettocache->cache(__CLASS__ . '.unitactivity', $cache_key, $out);
        echo json_encode($out, JSON_FORCE_OBJECT);
        exit;
    }

    public function event()
    {
        if (isset($this->request->KingdomId)) {
            $this->data['KingdomId'] = $this->request->KingdomId;
        }
        if (isset($this->request->ParkId)) {
            $this->data['ParkId'] = $this->request->ParkId;
        }
    }

    public function tournament()
    {

    }
}
