<?php

/**
 * Read gates for the public service surfaces (orkservice/Json and the direct
 * JSONService endpoints).
 *
 * Domain methods are shared: the same Report::GetActivePlayers serves a gated page
 * in-process and an anonymous caller over HTTP, and pages must keep their data. So
 * the gate sits at the surface. JsonServer and JSONService call prepare_call_h()
 * before dispatch and filter_result_h() after it; in-process callers (APIModel,
 * orkui models) never pass through here and are unaffected.
 *
 * The rules restate the gates the site's own pages apply. When a page's gate
 * changes, change the rule here with it. Identity only ever comes from a validated
 * Token; request fields such as RequestedBy or CallerUserId are overwritten.
 *
 * This class is deliberately not whitelisted in orkservice/Json.
 */
class ApiGuard extends Ork3
{
    /** "class/method" (lower case: PHP method names are case-insensitive) => gate. */
    private const GATES = [
        'player/getnotes'                         => 'gate_player_private',
        'player/getnotescount'                    => 'gate_player_private',
        'player/getdues'                          => 'gate_player_dues',
        'player/getdietarypreferences'            => 'gate_dietary',
        'player/savedietarypreferences'           => 'gate_self_only',
        'player/getviewerpreferences'             => 'gate_self_only',
        'player/getwhatsnewseen'                  => 'gate_self_only',
        'player/dismisswhatsnew'                  => 'gate_self_only',
        'player/checkusernameavailable'           => 'gate_login',
        'player/getsecondsforrecommendations'     => 'gate_seconds_viewer',
        'event/getupcomingrsvps'                  => 'gate_player_private',
        'event/getrsvpstatus'                     => 'gate_player_private',
        'event/getuserrsvpstatusesbatch'          => 'gate_player_private',
        'report/playerawardrecommendations'       => 'gate_recommendations',
        'report/playerawardrecommendationspage'   => 'gate_recommendations_managed',
        'report/playerawardrecommendationscount'  => 'gate_recommendations_count',
        'report/deletedawardrecommendations'      => 'gate_recommendations_managed',
        'report/getduespaidlist'                  => 'gate_login',
        'report/parkattendancesinglepark'         => 'gate_login',
        'report/parkattendanceallparks'           => 'gate_login',
        'report/getnewplayerattendance'           => 'gate_login',
        'report/getnewplayerattendancebykingdom'  => 'gate_login',
        'report/getactiveplayers'                 => 'gate_login_and_scope',
        'report/getvotingeligible'                => 'gate_login_and_scope',
        'report/attendancesummary'                => 'gate_scope_or_login',
        'report/getdistinctplayerstats'           => 'gate_scope_or_login',
        'report/getmonthlychartdata'              => 'gate_scope_or_login',
        'searchservice/player'                    => 'gate_search_player',
        'searchservice/universalsearch'           => 'gate_search_universal',
        'searchservice/scopedplayersearch'        => 'gate_search_scoped',
    ];

    /** "class/method" => response filter. */
    private const FILTERS = [
        'player/getplayer'                        => 'filter_account_fields',
        'searchservice/player'                    => 'filter_account_fields',
        'kingdom/getofficers'                     => 'filter_kingdom_accounts',
        'kingdom/getkingdomauthorizations'        => 'filter_kingdom_accounts',
        'principality/getprincipalityofficers'    => 'filter_kingdom_accounts',
        'principality/getprincipalityauthorizations' => 'filter_kingdom_accounts',
        'park/getofficers'                        => 'filter_park_accounts',
        'park/getparkauthorizations'              => 'filter_park_accounts',
        'report/getauthorizations'                => 'filter_report_accounts',
        'report/kingdomofficerdirectory'          => 'filter_officer_directory',
        'report/getkingdomofficerdirectorymerged' => 'filter_officer_directory',
        'report/getvotingeligibleforplayer'       => 'filter_voting_badge',
        'report/playerawardrecommendations'       => 'filter_recommendations',
    ];

    /** The token is a positional argument on these; everything else takes $request['Token']. */
    private const TOKEN_ARGUMENT = [
        'searchservice/player' => 6,
    ];

    /** What the public profile's voting badge needs (PlayerAjax/voting_eligible). */
    private const VOTING_BADGE_FIELDS = [
        'MundaneId', 'KingdomEligible', 'VotingEligible', 'ProvinceEligible', 'ActiveKnight', 'ActiveMember',
    ];

    /** Set by gate_recommendations for filter_recommendations, within one request. */
    private $ownRecommendationsOnly = false;

    /** @var array<string,bool> "viewer:park" => AUTH_EDIT, per request */
    private $parkEditMemo = [];

    public function __construct()
    {
        parent::__construct();
    }

    /**
     * May this call run? Returns null to proceed, or the response to send instead.
     * May rewrite $args: a gate can narrow a request rather than refuse it.
     *
     * @return array<string,mixed>|null
     */
    public function prepare_call_h(string $class, string $method, array &$args): ?array
    {
        $call = strtolower($class . '/' . $method);
        if (!isset(self::GATES[$call])) {
            return null;
        }
        $viewer = $this->viewer_for_h($call, $args);
        $denied = $this->{self::GATES[$call]}($args, $viewer);
        $this->clear_bindings();

        return $denied;
    }

    /** Remove from a response whatever this viewer may not see. */
    public function filter_result_h(string $class, string $method, array $args, $result)
    {
        $call = strtolower($class . '/' . $method);
        if (!isset(self::FILTERS[$call]) || !is_array($result)) {
            return $result;
        }
        $viewer = $this->viewer_for_h($call, $args);
        $result = $this->{self::FILTERS[$call]}($result, $args, $viewer);
        $this->clear_bindings();

        return $result;
    }

    /** True for the player themselves, an officer over their home park, or an ORK admin. */
    public function can_view_player_h(int $viewer, int $mundaneId): bool
    {
        if (!valid_id($viewer) || !valid_id($mundaneId)) {
            return false;
        }
        if ($viewer === $mundaneId) {
            return true;
        }
        $this->db->Clear();
        $row = $this->db->DataSet('SELECT park_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundaneId . ' LIMIT 1');
        $parkId = ($row && $row->Next()) ? (int)$row->park_id : 0;
        $allowed = $this->is_admin($viewer) || $this->is_park_officer($viewer, $parkId);
        $this->clear_bindings();

        return $allowed;
    }

    /**
     * The player search as a logged-in browser reaches it (SearchAjax/player), through
     * the same gate and filter as the service endpoints. The session cookie does not
     * reach /orkservice/, so a browser cannot identify itself there.
     *
     * @return array<int,array<string,mixed>>
     */
    public function search_players_h(string $type, string $search, int $limit, $kingdomId, $parkId, $waivered, string $token): array
    {
        $args = [$type, $search, $limit, $kingdomId, $parkId, $waivered, $token];
        $this->prepare_call_h('SearchService', 'Player', $args);
        $result = (new SearchService())->Player(...$args);

        return $this->filter_result_h('SearchService', 'Player', $args, $result);
    }

    private function viewer_for_h(string $call, array $args): int
    {
        if (isset(self::TOKEN_ARGUMENT[$call])) {
            $token = $args[self::TOKEN_ARGUMENT[$call]] ?? null;
        } elseif (is_array($args[0] ?? null) && isset($args[0]['Token'])) {
            $token = $args[0]['Token'];
        } else {
            $token = $_REQUEST['Token'] ?? null;
        }
        if (!is_string($token) || $token === '') {
            return 0;
        }

        return (int)Ork3::$Lib->authorization->IsAuthorized($token);
    }

    /** HasAuthority and IsAuthorized leave bound parameters on the shared handle. */
    private function clear_bindings(): void
    {
        global $DB;
        $DB->Clear();
    }

    private function is_admin(int $viewer): bool
    {
        return $viewer > 0 && Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_ADMIN, 0, AUTH_ADMIN);
    }

    private function is_park_officer(int $viewer, int $parkId): bool
    {
        if ($viewer <= 0 || $parkId <= 0) {
            return false;
        }
        $key = $viewer . ':' . $parkId;
        if (!isset($this->parkEditMemo[$key])) {
            $this->parkEditMemo[$key] = (bool)Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_PARK, $parkId, AUTH_EDIT);
        }

        return $this->parkEditMemo[$key];
    }

    private function target_player(array $args): int
    {
        $first = $args[0] ?? 0;

        return (int)(is_array($first) ? ($first['MundaneId'] ?? 0) : $first);
    }

    private function denied(): array
    {
        return ['Status' => NoAuthorization()];
    }

    private function gate_login(array &$args, int $viewer): ?array
    {
        return $viewer > 0 ? null : $this->denied();
    }

    private function gate_self_only(array &$args, int $viewer): ?array
    {
        return ($viewer > 0 && $viewer === $this->target_player($args)) ? null : $this->denied();
    }

    private function gate_player_private(array &$args, int $viewer): ?array
    {
        return $this->can_view_player_h($viewer, $this->target_player($args)) ? null : $this->denied();
    }

    /**
     * The public profile lists a player's current dues, so anyone may read those.
     * Expired and revoked history is for the player and their officers.
     */
    private function gate_player_dues(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }
        if (!$this->can_view_player_h($viewer, $this->target_player($args))) {
            $args[0]['ExcludeRevoked'] = 1;
            $args[0]['Active'] = 1;
        }

        return null;
    }

    /** A player's feast card is opt-in (Show My Feast Preferences), and never anonymous here. */
    private function gate_dietary(array &$args, int $viewer): ?array
    {
        $mundaneId = $this->target_player($args);
        if ($viewer <= 0) {
            return $this->denied();
        }
        if ($this->can_view_player_h($viewer, $mundaneId)) {
            return null;
        }
        $this->db->Clear();
        $row = $this->db->DataSet('SELECT show_feast_prefs FROM ' . DB_PREFIX . 'mundane_design WHERE mundane_id = ' . $mundaneId . ' LIMIT 1');

        return ($row && $row->Next() && (int)$row->show_feast_prefs === 1) ? null : $this->denied();
    }

    private function gate_seconds_viewer(array &$args, int $viewer): ?array
    {
        $args[1] = $viewer;

        return null;
    }

    private function has_report_scope(array $args): bool
    {
        $request = is_array($args[0] ?? null) ? $args[0] : [];
        foreach (['KingdomId', 'ParkId', 'PrincipalityId', 'EventId'] as $key) {
            if (valid_id($request[$key] ?? 0)) {
                return true;
            }
        }

        return false;
    }

    private function gate_login_and_scope(array &$args, int $viewer): ?array
    {
        if ($viewer <= 0) {
            return $this->denied();
        }

        return ($this->has_report_scope($args) || $this->is_admin($viewer)) ? null : $this->denied();
    }

    private function gate_scope_or_login(array &$args, int $viewer): ?array
    {
        return ($viewer > 0 || $this->has_report_scope($args)) ? null : $this->denied();
    }

    /** Anonymous search must not reveal whose username or real name a term matches. */
    private function gate_search_player(array &$args, int $viewer): ?array
    {
        if ($viewer <= 0) {
            $args[0] = 'PERSONA';
        }

        return null;
    }

    private function gate_search_universal(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }
        $args[0]['CallerUserId'] = $viewer;
        $args[0]['PersonaOnly'] = $viewer <= 0;

        return null;
    }

    private function gate_search_scoped(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }
        $args[0]['PersonaOnly'] = $viewer <= 0;

        return null;
    }

    /** The kingdom a recommendations request reaches, resolved as the report resolves it. */
    private function recommendations_kingdom(array $request): int
    {
        $sql = '';
        if (valid_id($request['PlayerId'] ?? 0)) {
            $sql = 'SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int)$request['PlayerId'];
        } elseif (valid_id($request['ParkId'] ?? 0)) {
            $sql = 'SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . (int)$request['ParkId'];
        }
        if ($sql === '') {
            return (int)($request['KingdomId'] ?? 0);
        }
        $this->db->Clear();
        $row = $this->db->DataSet($sql . ' LIMIT 1');

        return ($row && $row->Next()) ? (int)$row->kingdom_id : 0;
    }

    private function can_manage_recommendations(int $viewer, int $kingdomId): bool
    {
        if ($viewer <= 0) {
            return false;
        }

        return $this->is_admin($viewer)
            || ($kingdomId > 0 && Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_KINGDOM, $kingdomId, AUTH_CREATE));
    }

    private function recommendations_are_public(int $kingdomId): bool
    {
        $configs = Common::get_configs($kingdomId, CFG_KINGDOM);

        return isset($configs['AwardRecsPublic']) ? (bool)(int)$configs['AwardRecsPublic']['Value'] : true;
    }

    /**
     * Mirrors Controller_Kingdom::recommendations_panel: a kingdom with AwardRecsPublic=0
     * shows its list to its managers, and to anyone else only the recs they wrote.
     * Monarchy Only rows are dropped inside the report, from the validated Token.
     */
    private function gate_recommendations(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }
        $kingdomId = $this->recommendations_kingdom($args[0]);
        $canManage = $this->can_manage_recommendations($viewer, $kingdomId);
        if ($kingdomId <= 0 && !$canManage) {
            return $this->denied();
        }
        $args[0]['RequestedBy'] = $viewer;
        if (!$canManage) {
            unset($args[0]['IncludeDismissed'], $args[0]['SkipCache']);
        }
        if ($canManage || $this->recommendations_are_public($kingdomId)) {
            return null;
        }
        if ($viewer <= 0) {
            return $this->denied();
        }
        $this->ownRecommendationsOnly = true;

        return null;
    }

    private function gate_recommendations_managed(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }

        return $this->can_manage_recommendations($viewer, $this->recommendations_kingdom($args[0])) ? null : $this->denied();
    }

    private function gate_recommendations_count(array &$args, int $viewer): ?array
    {
        if (!is_array($args[0] ?? null)) {
            return $this->denied();
        }
        $kingdomId = (int)($args[0]['KingdomId'] ?? 0);
        if ($this->can_manage_recommendations($viewer, $kingdomId) || $this->recommendations_are_public($kingdomId)) {
            return null;
        }
        if ($viewer <= 0) {
            return $this->denied();
        }
        $args[0]['RecommendedBy'] = $viewer;

        return null;
    }

    private function filter_recommendations(array $result, array $args, int $viewer): array
    {
        if ($this->ownRecommendationsOnly && isset($result['AwardRecommendations'])) {
            $result['AwardRecommendations'] = array_values(array_filter(
                $result['AwardRecommendations'],
                static function ($row) {
                    // Not RecommendedById: anonymous recs have it masked, even for their author.
                    return !empty($row['ViewerCanEditReason']);
                }
            ));
            $this->ownRecommendationsOnly = false;
        }

        return $result;
    }

    /** Usernames and password dates: for the player, their officers, and scope-level officers. */
    private function blank_account_fields(array $node, int $viewer, bool $scopeAuthorized): array
    {
        if (array_key_exists('UserName', $node) || array_key_exists('PasswordExpires', $node)) {
            $allowed = $scopeAuthorized
                || ($viewer > 0 && (int)($node['MundaneId'] ?? 0) === $viewer)
                || $this->is_park_officer($viewer, (int)($node['ParkId'] ?? 0));
            if (!$allowed) {
                foreach (['UserName', 'PasswordExpires'] as $field) {
                    if (array_key_exists($field, $node)) {
                        $node[$field] = '';
                    }
                }
            }
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->blank_account_fields($value, $viewer, $scopeAuthorized);
            }
        }

        return $node;
    }

    private function filter_account_fields(array $result, array $args, int $viewer): array
    {
        return $this->blank_account_fields($result, $viewer, $this->is_admin($viewer));
    }

    private function filter_kingdom_accounts(array $result, array $args, int $viewer): array
    {
        $request = is_array($args[0] ?? null) ? $args[0] : [];
        $kingdomId = (int)($request['KingdomId'] ?? ($request['PrincipalityId'] ?? 0));
        $authorized = $this->is_admin($viewer)
            || ($viewer > 0 && $kingdomId > 0 && Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_KINGDOM, $kingdomId, AUTH_EDIT));

        return $this->blank_account_fields($result, $viewer, $authorized);
    }

    private function filter_park_accounts(array $result, array $args, int $viewer): array
    {
        $request = is_array($args[0] ?? null) ? $args[0] : [];
        $authorized = $this->is_admin($viewer) || $this->is_park_officer($viewer, (int)($request['ParkId'] ?? 0));

        return $this->blank_account_fields($result, $viewer, $authorized);
    }

    private function filter_report_accounts(array $result, array $args, int $viewer): array
    {
        $request = is_array($args[0] ?? null) ? $args[0] : [];
        $type = (string)($request['Type'] ?? '');
        $id = (int)($request['Id'] ?? 0);
        $authorized = $this->is_admin($viewer)
            || ($viewer > 0 && $id > 0 && in_array($type, [AUTH_PARK, AUTH_KINGDOM, AUTH_EVENT, AUTH_UNIT], true)
                && Ork3::$Lib->authorization->HasAuthority($viewer, $type, $id, AUTH_EDIT));

        return $this->blank_account_fields($result, $viewer, $authorized);
    }

    /** Officers' personas are public; the directory shows real names and email to admins only. */
    private function filter_officer_directory(array $result, array $args, int $viewer): array
    {
        return $this->is_admin($viewer) ? $result : $this->blank_directory_contacts($result);
    }

    private function blank_directory_contacts(array $node): array
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $node[$key] = $this->blank_directory_contacts($value);
            } elseif (preg_match('/(Given|Surname|Email)$/', (string)$key)) {
                $node[$key] = '';
            }
        }

        return $node;
    }

    /** Anyone may see the profile's voting badge; the row behind it is for those who may see the player. */
    private function filter_voting_badge(array $result, array $args, int $viewer): array
    {
        if ($this->can_view_player_h($viewer, $this->target_player($args)) || !is_array($result['Players'] ?? null)) {
            return $result;
        }
        $badge = array_flip(self::VOTING_BADGE_FIELDS);
        foreach ($result['Players'] as $key => $row) {
            $result['Players'][$key] = is_array($row) ? array_intersect_key($row, $badge) : $row;
        }

        return $result;
    }
}
