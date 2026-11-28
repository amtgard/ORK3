<?php

/**
 * Circle of Knights Manager, the roster half: who a kingdom's knights are,
 * which of them are eligible to take part in the Circle, who may see and run
 * it, and the per-kingdom rules that decide eligibility.
 *
 * The candidate pipeline (watchlist through Knighted) lives in KnightCandidate.
 *
 * Eligibility is configurable per kingdom (ork_knight_circle_config; no row
 * means DEFAULTS). The rule evaluation itself, evaluateEligibility(), is pure:
 * it takes a bag of facts and a config and says Eligible plus the reasons,
 * so it is unit-tested without a database. The roster gathers the facts in ONE
 * query (aggregate subqueries for attendance, dues and holdings), reusing the
 * attendance and dues scoping of Report::GetVotingEligible: kingdom scope is by
 * PARK membership (attendance.kingdom_id is a point-in-time snapshot that goes
 * wrong when a park changes kingdom), plus kingdom-only events (park_id = 0).
 *
 * Authority. ORK admins can do everything. The kingdom's Guardian of the Circle
 * (GMoK) and its deputies (ork_knight_circle_admin) view and manage. Kingdom
 * CREATE may view and appoint the GMoK and deputies. Kingdom EDIT officers may
 * view. Resident knights may view (the pipeline hides their own candidacies;
 * that is KnightCandidate's job). Everyone else sees nothing.
 *
 * startup.php instantiates every class in this directory, so
 * Ork3::$Lib->knightcircle exists. What keeps these methods, which take a caller
 * uid and not a token, off the JSON gateway is the explicit class list in
 * orkservice/Json/index.php, where KnightCircle must never be added. Callers
 * reach it through Model_KnightCircle.
 *
 * Envelope: ['Status' => 0, ...] ok; 1 bad request; 3 not authorized.
 */
class KnightCircle
{
    /** Knighthood award_id => short name (mirrors Award::GetKnightAwardMap(); a unit test keeps them equal). */
    public const ORDERS = [
        17  => 'Flame',
        18  => 'Crown',
        19  => 'Serpent',
        20  => 'Sword',
        245 => 'Battle',
    ];

    /** Knighthood award_id => display name. */
    public const ORDER_NAMES = [
        17  => 'Knight of the Flame',
        18  => 'Knight of the Crown',
        19  => 'Knight of the Serpent',
        20  => 'Knight of the Sword',
        245 => 'Knight of Battle',
    ];

    /**
     * Ladder award_id => the knighthood that ladder leads to. Mirrors the private
     * Report::ladderKnightGroupMap (which is keyed by ladder NAME); a unit test
     * keeps the two in step.
     */
    public const LADDER_ORDER = [
        21  => 17,  // Rose    -> Flame
        22  => 17,  // Smith   -> Flame
        23  => 17,  // Lion    -> Flame
        24  => 19,  // Owl     -> Serpent
        25  => 19,  // Dragon  -> Serpent
        26  => 19,  // Garber  -> Serpent
        27  => 20,  // Warrior -> Sword
        239 => 18,  // Crown   -> Crown
        243 => 245, // Battle  -> Battle
    ];

    /** What a kingdom with no ork_knight_circle_config row gets. */
    public const DEFAULTS = [
        'min_signins'              => 6,
        'window_months'            => 6,
        'signin_mode'              => 'count',
        'signin_scope'             => 'kingdom',
        'online_mode'              => 'kingdom_setting',
        'require_dues'             => 1,
        'dues_scope'               => 'kingdom',
        'require_waiver'           => 0,
        'require_residence'        => 1,
        'include_principalities'   => 1,
        'exclude_suspended'        => 1,
        'min_knight_months'        => 0,
        'use_kingdom_voting_rules' => 0,
        'watchlist_min_rank'       => 9,
        'officers_see_pipeline'    => 1,
        'caveat'                   => '',
    ];

    private const BOOL_KEYS = [
        'require_dues', 'require_waiver', 'require_residence', 'include_principalities',
        'exclude_suspended', 'use_kingdom_voting_rules', 'officers_see_pipeline',
    ];

    private const ENUMS = [
        'signin_mode'  => ['count', 'days', 'weeks'],
        'signin_scope' => ['kingdom', 'anywhere'],
        'online_mode'  => ['kingdom_setting', 'include', 'exclude'],
        'dues_scope'   => ['kingdom', 'anywhere'],
    ];

    /** key => [min, max, label] */
    private const RANGES = [
        'min_signins'       => [0, 52, 'Minimum sign-ins'],
        'window_months'     => [1, 24, 'Window (months)'],
        'min_knight_months' => [0, 600, 'Minimum months as a knight'],
        'watchlist_min_rank' => [1, 12, 'Watchlist minimum ladder rank'],
    ];

    private const CACHE_CALL = 'KnightCircle.roster';
    private const CACHE_TTL = 300;
    private const CAVEAT_MAX = 1000;

    private $db;

    public function __construct()
    {
        global $DB;
        $this->db = $DB;
    }

    // -----------------------------------------------------------------------
    // Authority
    // -----------------------------------------------------------------------

    /** A global ORK admin: an unscoped admin authorization row. */
    public function isOrkAdmin(int $uid): bool
    {
        return $uid > 0 && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
    }

    /** May $uid appoint the GMoK and deputies? Kingdom CREATE (ADMIN passes, EDIT does not), or an ORK admin. */
    public function canAppoint(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        return (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_CREATE)
            || $this->isOrkAdmin($uid);
    }

    /** The kingdom's GMoK or one of its deputies, or an ORK admin. */
    public function isCircleAdmin(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        if ($this->isOrkAdmin($uid)) {
            return true;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT circle_admin_id FROM ' . DB_PREFIX . 'knight_circle_admin
             WHERE kingdom_id = ' . (int) $kingdomId . ' AND mundane_id = ' . (int) $uid . ' LIMIT 1'
        );
        return (bool) ($rs && $rs->Next());
    }

    /** Is $uid an active, non-Legend player whose home is this kingdom and who holds a live knighthood? */
    public function isResidentKnight(int $uid, int $kingdomId): bool
    {
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT m.mundane_id FROM ' . DB_PREFIX . 'mundane m
             WHERE m.mundane_id = ' . (int) $uid . ' AND m.kingdom_id = ' . (int) $kingdomId . '
               AND m.active = 1 AND m.is_legend = 0
               AND EXISTS (' . $this->holdingsSql((int) $uid) . ') LIMIT 1'
        );
        return (bool) ($rs && $rs->Next());
    }

    /** What may $uid do in this kingdom's Circle? */
    public function access(int $uid, int $kingdomId): array
    {
        $none = [
            'CanView' => false, 'CanManage' => false, 'CanAppoint' => false,
            'IsKnight' => false, 'IsOfficer' => false, 'IsOrkAdmin' => false, 'IsCircleAdmin' => false,
        ];
        if ($uid <= 0 || !valid_id($kingdomId)) {
            return $none;
        }
        $orkAdmin    = $this->isOrkAdmin($uid);
        $circleAdmin = $orkAdmin || $this->isCircleAdmin($uid, $kingdomId);
        $canAppoint  = $orkAdmin || $this->canAppoint($uid, $kingdomId);
        $officer     = $orkAdmin
            || (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT);
        $knight      = $this->isResidentKnight($uid, $kingdomId);
        return [
            'CanView'       => $circleAdmin || $canAppoint || $officer || $knight,
            'CanManage'     => $circleAdmin,
            'CanAppoint'    => $canAppoint,
            'IsKnight'      => $knight,
            'IsOfficer'     => $officer,
            'IsOrkAdmin'    => $orkAdmin,
            'IsCircleAdmin' => $circleAdmin,
        ];
    }

    // -----------------------------------------------------------------------
    // Order maps
    // -----------------------------------------------------------------------

    /**
     * Master award_id => the knighthood it leads to, derived from LADDER_ORDER and
     * Award::GetLadderMasterMap(): 1,2,3 => 17; 4,5,6 => 19; 12 => 20; 240 => 18; 244 => 245.
     *
     * @return array<int,int>
     */
    public static function masterOrderMap(): array
    {
        $map    = [];
        $ladder = Award::GetLadderMasterMap();
        foreach (self::LADDER_ORDER as $ladderId => $orderId) {
            foreach ((array) ($ladder[$ladderId]['MasterAwardIds'] ?? []) as $masterId) {
                $map[(int) $masterId] = (int) $orderId;
            }
        }
        return $map;
    }

    /**
     * The kingdoms a Circle covers: the kingdom, plus its principalities when asked
     * (and when the kingdom's stats include them).
     *
     * @return int[]
     */
    public function kingdomFamily(int $kingdomId, bool $includePrincipalities = true): array
    {
        if (!$includePrincipalities) {
            return [$kingdomId];
        }
        $ids = array_values(array_unique(array_map('intval', (array) Ork3::$Lib->kingdom->GetStatsKingdomIds($kingdomId))));
        return $ids ?: [$kingdomId];
    }

    // -----------------------------------------------------------------------
    // Config
    // -----------------------------------------------------------------------

    /** The kingdom's rules: DEFAULTS overlaid with its stored row. Plain keys of DEFAULTS, ints for flags. */
    public function config(int $kingdomId): array
    {
        $config = self::DEFAULTS;
        if (!valid_id($kingdomId)) {
            return $config;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT * FROM ' . DB_PREFIX . 'knight_circle_config WHERE kingdom_id = ' . (int) $kingdomId . ' LIMIT 1'
        );
        if ($rs && $rs->Next()) {
            foreach (self::DEFAULTS as $key => $default) {
                $v = $rs->$key;
                if ($v !== null) {
                    $config[$key] = is_int($default) ? (int) $v : (string) $v;
                }
            }
        }
        return $config;
    }

    /**
     * Validate and normalise config input over $base (default DEFAULTS). Pure.
     * Unknown keys are ignored. Enums are whitelisted, numbers range-checked,
     * flags coerced, the caveat trimmed and capped.
     *
     * @return array{ok: bool, config: array, error: string}
     */
    public static function normalizeConfig(array $input, ?array $base = null): array
    {
        $config = array_merge(self::DEFAULTS, $base ?? []);
        $config = array_intersect_key($config, self::DEFAULTS);

        foreach (self::ENUMS as $key => $allowed) {
            if (array_key_exists($key, $input)) {
                $v = is_scalar($input[$key]) ? strtolower(trim((string) $input[$key])) : '';
                if (!in_array($v, $allowed, true)) {
                    return ['ok' => false, 'config' => $config, 'error' => 'Invalid value for ' . $key . '.'];
                }
                $config[$key] = $v;
            }
        }
        foreach (self::RANGES as $key => [$min, $max, $label]) {
            if (array_key_exists($key, $input)) {
                $raw = $input[$key];
                if (!is_scalar($raw) || !preg_match('/^\s*-?\d+\s*$/', (string) $raw)) {
                    return ['ok' => false, 'config' => $config, 'error' => $label . ' must be a whole number.'];
                }
                $n = (int) trim((string) $raw);
                if ($n < $min || $n > $max) {
                    return ['ok' => false, 'config' => $config, 'error' => $label . ' must be between ' . $min . ' and ' . $max . '.'];
                }
                $config[$key] = $n;
            }
        }
        foreach (self::BOOL_KEYS as $key) {
            if (array_key_exists($key, $input)) {
                $config[$key] = in_array(strtolower(trim((string) (is_scalar($input[$key]) ? $input[$key] : ''))), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
            }
        }
        if (array_key_exists('caveat', $input)) {
            $caveat = is_scalar($input['caveat']) ? trim((string) $input['caveat']) : '';
            $caveat = function_exists('mb_substr') ? mb_substr($caveat, 0, self::CAVEAT_MAX) : substr($caveat, 0, self::CAVEAT_MAX);
            $config['caveat'] = $caveat;
        }
        return ['ok' => true, 'config' => $config, 'error' => ''];
    }

    /** Save a kingdom's rules. Circle admin (GMoK, deputy) or ORK admin. Audited; busts the roster cache. */
    public function saveConfig(int $uid, int $kingdomId, array $fields): array
    {
        if (!$this->kingdomExists($kingdomId)) {
            return $this->fail('That Kingdom does not exist.');
        }
        if (!$this->isCircleAdmin($uid, $kingdomId)) {
            return $this->denied('Only the Circle of Knights officers can change its settings.');
        }
        $prior = $this->config($kingdomId);
        $norm  = self::normalizeConfig($fields, $prior);
        if (!$norm['ok']) {
            return $this->fail($norm['error']);
        }
        $c = $norm['config'];
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'knight_circle_config
               (kingdom_id, min_signins, window_months, signin_mode, signin_scope, online_mode, require_dues, dues_scope,
                require_waiver, require_residence, include_principalities, exclude_suspended, min_knight_months,
                use_kingdom_voting_rules, watchlist_min_rank, officers_see_pipeline, caveat, updated_by, updated_at)
             VALUES (' . (int) $kingdomId . ', ' . (int) $c['min_signins'] . ', ' . (int) $c['window_months'] . ",
                '" . $this->esc($c['signin_mode']) . "', '" . $this->esc($c['signin_scope']) . "', '" . $this->esc($c['online_mode']) . "',
                " . (int) $c['require_dues'] . ", '" . $this->esc($c['dues_scope']) . "', " . (int) $c['require_waiver'] . ',
                ' . (int) $c['require_residence'] . ', ' . (int) $c['include_principalities'] . ', ' . (int) $c['exclude_suspended'] . ',
                ' . (int) $c['min_knight_months'] . ', ' . (int) $c['use_kingdom_voting_rules'] . ', ' . (int) $c['watchlist_min_rank'] . ',
                ' . (int) $c['officers_see_pipeline'] . ", '" . $this->esc($c['caveat']) . "', " . (int) $uid . ', NOW())
             ON DUPLICATE KEY UPDATE
                min_signins = VALUES(min_signins), window_months = VALUES(window_months), signin_mode = VALUES(signin_mode),
                signin_scope = VALUES(signin_scope), online_mode = VALUES(online_mode), require_dues = VALUES(require_dues),
                dues_scope = VALUES(dues_scope), require_waiver = VALUES(require_waiver),
                require_residence = VALUES(require_residence), include_principalities = VALUES(include_principalities),
                exclude_suspended = VALUES(exclude_suspended), min_knight_months = VALUES(min_knight_months),
                use_kingdom_voting_rules = VALUES(use_kingdom_voting_rules), watchlist_min_rank = VALUES(watchlist_min_rank),
                officers_see_pipeline = VALUES(officers_see_pipeline), caveat = VALUES(caveat),
                updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)'
        );
        if ($ok === false) {
            return $this->fail('Could not save the settings.');
        }
        $this->bustRoster($kingdomId);
        $this->audit('saveConfig', ['KingdomId' => $kingdomId, 'by_whom_id' => $uid], 'Kingdom', $kingdomId, $prior, $c);
        return ['Status' => 0, 'Config' => $c];
    }

    // -----------------------------------------------------------------------
    // Circle officers (GMoK and deputies)
    // -----------------------------------------------------------------------

    /**
     * The kingdom's GMoK and deputies. Anyone who may view the Circle.
     * Rows: CircleAdminId, MundaneId, Persona, Role, AddedBy, AddedAt. GMoK first.
     */
    public function admins(int $uid, int $kingdomId): array
    {
        if (!valid_id($kingdomId)) {
            return $this->fail('That Kingdom does not exist.');
        }
        if (!$this->access($uid, $kingdomId)['CanView']) {
            return $this->denied('You cannot see this Kingdom\'s Circle of Knights.');
        }
        return ['Status' => 0, 'Admins' => $this->adminRows($kingdomId)];
    }

    /** Name (or replace) the kingdom's Guardian of the Circle. Kingdom CREATE or ORK admin. Target must hold a knighthood unless the caller is an ORK admin. */
    public function setGmok(int $uid, int $kingdomId, int $mundaneId): array
    {
        $gate = $this->appointGate($uid, $kingdomId, $mundaneId);
        if ($gate !== null) {
            return $gate;
        }
        $player = $this->activePlayer($mundaneId);
        if ($player === null) {
            return $this->fail('That player does not exist or is not active.');
        }
        if (!$this->isOrkAdmin($uid) && !$this->holdsKnighthood($mundaneId)) {
            return $this->fail('The Guardian of the Circle must hold a knighthood.');
        }
        $prior = $this->adminRows($kingdomId);
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . "knight_circle_admin WHERE kingdom_id = " . (int) $kingdomId
            . " AND role = 'gmok' AND mundane_id <> " . (int) $mundaneId
        );
        if ($ok === false) {
            return $this->fail('Could not replace the Guardian of the Circle.');
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . 'knight_circle_admin (kingdom_id, mundane_id, role, added_by, added_at)
             VALUES (' . (int) $kingdomId . ', ' . (int) $mundaneId . ", 'gmok', " . (int) $uid . ", NOW())
             ON DUPLICATE KEY UPDATE role = 'gmok'"
        );
        if ($ok === false) {
            return $this->fail('Could not name the Guardian of the Circle.');
        }
        $post = $this->adminRows($kingdomId);
        $this->audit('setGmok', ['KingdomId' => $kingdomId, 'MundaneId' => $mundaneId, 'by_whom_id' => $uid], 'Kingdom', $kingdomId, $prior, $post);
        return ['Status' => 0, 'Admins' => $post];
    }

    /** Add a deputy. Kingdom CREATE or ORK admin. A GMoK stays a GMoK; an existing deputy is a no-op. */
    public function addDeputy(int $uid, int $kingdomId, int $mundaneId): array
    {
        $gate = $this->appointGate($uid, $kingdomId, $mundaneId);
        if ($gate !== null) {
            return $gate;
        }
        if ($this->activePlayer($mundaneId) === null) {
            return $this->fail('That player does not exist or is not active.');
        }
        $prior = $this->adminRows($kingdomId);
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'knight_circle_admin (kingdom_id, mundane_id, role, added_by, added_at)
             VALUES (' . (int) $kingdomId . ', ' . (int) $mundaneId . ", 'deputy', " . (int) $uid . ', NOW())'
        );
        if ($ok === false) {
            return $this->fail('Could not add the deputy.');
        }
        $post = $this->adminRows($kingdomId);
        if ($post !== $prior) {
            $this->audit('addDeputy', ['KingdomId' => $kingdomId, 'MundaneId' => $mundaneId, 'by_whom_id' => $uid], 'Kingdom', $kingdomId, $prior, $post);
        }
        return ['Status' => 0, 'Admins' => $post];
    }

    /** Remove the GMoK or a deputy by row id. Kingdom CREATE or ORK admin. Idempotent. */
    public function removeAdmin(int $uid, int $kingdomId, int $circleAdminId): array
    {
        if (!valid_id($kingdomId)) {
            return $this->fail('That Kingdom does not exist.');
        }
        if (!$this->canAppoint($uid, $kingdomId)) {
            return $this->denied('Only Kingdom officers with CREATE authority can remove Circle officers.');
        }
        $prior = $this->adminRows($kingdomId);
        $found = false;
        foreach ($prior as $row) {
            if ($row['CircleAdminId'] === $circleAdminId) {
                $found = true;
            }
        }
        if (!$found) {
            return ['Status' => 0, 'Admins' => $prior];
        }
        $this->db->Clear();
        $ok = $this->db->ExecuteChecked(
            'DELETE FROM ' . DB_PREFIX . 'knight_circle_admin WHERE circle_admin_id = ' . (int) $circleAdminId
            . ' AND kingdom_id = ' . (int) $kingdomId
        );
        if ($ok === false) {
            return $this->fail('Could not remove the Circle officer.');
        }
        $post = $this->adminRows($kingdomId);
        $this->audit('removeAdmin', ['KingdomId' => $kingdomId, 'CircleAdminId' => $circleAdminId, 'by_whom_id' => $uid], 'Kingdom', $kingdomId, $prior, $post);
        return ['Status' => 0, 'Admins' => $post];
    }

    // -----------------------------------------------------------------------
    // Roster
    // -----------------------------------------------------------------------

    /**
     * The Circle's roster. Anyone who may view it.
     *
     * Knights: active, non-Legend holders of a live knighthood whose home kingdom is
     * in the Circle's family (Resident true), plus non-resident knights with in-scope
     * sign-ins in the window (Resident false). Each row:
     *   MundaneId, Persona, ParkId, ParkName, HomeKingdomId, Resident, Orders [{AwardId, Name}],
     *   SignIns, DuesPaid, DuesUntil, Waivered, Suspended, KnightMonths (null when no dated
     *   knighthood), Eligible, Reasons [string].
     * $orderAwardId (one of ORDERS) keeps only holders of that order. Summary: Total and
     * Eligible of what is returned; ByOrder (all five orders, over the whole roster) with
     * Name, Count, Eligible.
     */
    public function roster(int $uid, int $kingdomId, int $orderAwardId = 0): array
    {
        if (!valid_id($kingdomId) || !$this->kingdomExists($kingdomId)) {
            return $this->fail('That Kingdom does not exist.');
        }
        if (!$this->access($uid, $kingdomId)['CanView']) {
            return $this->denied('You cannot see this Kingdom\'s Circle of Knights.');
        }
        if ($orderAwardId !== 0 && !isset(self::ORDERS[$orderAwardId])) {
            return $this->fail('That is not one of the knighthood orders.');
        }
        $config = $this->config($kingdomId);
        $full   = $this->rosterData($kingdomId, $config);

        $knights = [];
        foreach ($full['Knights'] as $k) {
            if ($orderAwardId === 0 || in_array($orderAwardId, array_column($k['Orders'], 'AwardId'), true)) {
                $knights[] = $k;
            }
        }
        $eligible = 0;
        foreach ($knights as $k) {
            $eligible += $k['Eligible'] ? 1 : 0;
        }
        $byOrder = [];
        foreach (self::ORDERS as $id => $short) {
            $byOrder[$id] = ['Name' => self::ORDER_NAMES[$id], 'Short' => $short, 'Count' => 0, 'Eligible' => 0];
        }
        foreach ($full['Knights'] as $k) {
            foreach ($k['Orders'] as $o) {
                $byOrder[$o['AwardId']]['Count']++;
                $byOrder[$o['AwardId']]['Eligible'] += $k['Eligible'] ? 1 : 0;
            }
        }
        return [
            'Status'      => 0,
            'Knights'     => $knights,
            'Summary'     => ['Total' => count($knights), 'Eligible' => $eligible, 'ByOrder' => $byOrder],
            'Config'      => $config,
            'WindowStart' => $full['WindowStart'],
        ];
    }

    /**
     * Pure eligibility rules. $facts: SignIns (int), DuesPaid, Waivered, Resident, Suspended
     * (bools), KnightMonths (int|null; null = no dated knighthood, so the minimum is not
     * held against them), VotingOk (bool|null; null = no kingdom voting rules apply).
     * $config: the normalised config.
     *
     * @return array{Eligible: bool, Reasons: string[]}
     */
    public static function evaluateEligibility(array $facts, array $config): array
    {
        $config  = array_merge(self::DEFAULTS, $config);
        $reasons = [];

        $signins = (int) ($facts['SignIns'] ?? 0);
        $min     = (int) $config['min_signins'];
        if ($signins < $min) {
            $unit = ['count' => 'sign-in', 'days' => 'day', 'weeks' => 'week'][$config['signin_mode']] ?? 'sign-in';
            $reasons[] = 'Only ' . $signins . ' of ' . $min . ' required ' . $unit . ($min === 1 ? '' : 's')
                . ' in the last ' . (int) $config['window_months'] . ' month' . ((int) $config['window_months'] === 1 ? '' : 's') . '.';
        }
        if (!empty($config['require_dues']) && empty($facts['DuesPaid'])) {
            $reasons[] = 'Dues are not paid.';
        }
        if (!empty($config['require_waiver']) && empty($facts['Waivered'])) {
            $reasons[] = 'No waiver on file.';
        }
        if (!empty($config['require_residence']) && empty($facts['Resident'])) {
            $reasons[] = 'Not a resident of this Kingdom.';
        }
        if (!empty($config['exclude_suspended']) && !empty($facts['Suspended'])) {
            $reasons[] = 'Currently suspended.';
        }
        $minMonths = (int) $config['min_knight_months'];
        if ($minMonths > 0 && isset($facts['KnightMonths']) && $facts['KnightMonths'] !== null && (int) $facts['KnightMonths'] < $minMonths) {
            $reasons[] = 'Knighted only ' . (int) $facts['KnightMonths'] . ' of ' . $minMonths . ' required months ago.';
        }
        if (!empty($config['use_kingdom_voting_rules']) && array_key_exists('VotingOk', $facts) && $facts['VotingOk'] === false) {
            $reasons[] = 'Not eligible to vote under the Kingdom\'s voting rules.';
        }
        return ['Eligible' => $reasons === [], 'Reasons' => $reasons];
    }

    /** Drop the cached roster (settings changed, or something that feeds it did). */
    public function bustRoster(int $kingdomId): void
    {
        Ork3::$Lib->ghettocache->bust(self::CACHE_CALL, $this->cacheKey($kingdomId));
    }

    // -----------------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------------

    private function cacheKey(int $kingdomId): string
    {
        return Ork3::$Lib->ghettocache->key(['KingdomId' => $kingdomId]);
    }

    /** The whole (unfiltered) roster for a kingdom under $config, cached. */
    private function rosterData(int $kingdomId, array $config): array
    {
        $key = $this->cacheKey($kingdomId);
        if (($cached = Ork3::$Lib->ghettocache->get(self::CACHE_CALL, $key, self::CACHE_TTL)) !== false && is_array($cached)) {
            return $cached;
        }
        return Ork3::$Lib->ghettocache->cache(self::CACHE_CALL, $key, $this->buildRoster($kingdomId, $config));
    }

    private function windowStart(array $config): string
    {
        $ts = strtotime('-' . (int) $config['window_months'] . ' months');
        if ($config['signin_mode'] === 'weeks') {
            // Snap back to the Monday that starts that week so the whole first week counts.
            $ts -= ((int) date('N', $ts) - 1) * 86400;
        }
        return date('Y-m-d', $ts);
    }

    /** SQL for a knight's live holdings. $mundaneId > 0 restricts it to one player (used in EXISTS). */
    private function holdingsSql(int $mundaneId = 0): string
    {
        return 'SELECT ma.mundane_id, COALESCE(alias.award_id, ka.award_id) AS order_id,
                       MIN(CASE WHEN ma.date >= \'1900-01-01\' THEN ma.date END) AS since
                FROM ' . DB_PREFIX . 'awards ma
                JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
                LEFT JOIN ' . DB_PREFIX . 'award alias ON alias.award_id = ma.alias_award_id
                WHERE COALESCE(alias.award_id, ka.award_id) IN (' . implode(',', array_keys(self::ORDERS)) . ')
                  AND (ma.revoked = 0 OR ma.revoked IS NULL)'
            . ($mundaneId > 0 ? ' AND ma.mundane_id = ' . (int) $mundaneId : '')
            . ' GROUP BY ma.mundane_id, COALESCE(alias.award_id, ka.award_id)';
    }

    private function buildRoster(int $kingdomId, array $config): array
    {
        $family = $this->kingdomFamily($kingdomId, (bool) $config['include_principalities']);
        $fam    = implode(',', array_map('intval', $family));
        $start  = $this->windowStart($config);

        $famParks = 'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id IN (' . $fam . ')';
        $inScope  = '(a.park_id IN (' . $famParks . ') OR (a.park_id = 0 AND a.kingdom_id IN (' . $fam . ')))';

        // Online attendance, as GetVotingEligible does it: the kingdom's own CountOnlineAttendance
        // setting decides unless the Circle overrides it.
        $excludeOnline = $config['online_mode'] === 'exclude'
            || ($config['online_mode'] === 'kingdom_setting' && !Ork3::$Lib->kingdom->CountsOnlineAttendance($kingdomId));
        $onlineClause = $excludeOnline ? ' AND a.online = 0' : '';

        $expr = ['count' => 'COUNT(*)', 'days' => 'COUNT(DISTINCT a.date)',
                 'weeks' => "COUNT(DISTINCT CONCAT(a.date_year, '-', a.date_week3))"][$config['signin_mode']];
        $attScope = $config['signin_scope'] === 'anywhere' ? '1 = 1' : $inScope;

        $duesPark = $config['dues_scope'] === 'anywhere' ? '' : ' AND d.park_id IN (' . $famParks . ')';
        $duesLive = 'd.mundane_id = m.mundane_id' . $duesPark . ' AND d.revoked != 1 AND (d.dues_until >= CURDATE() OR d.dues_for_life = 1)';

        $sql = 'SELECT m.mundane_id, m.persona, m.park_id, p.name AS park_name, m.kingdom_id AS home_kingdom_id,
                       m.waivered, m.suspended, h.orders, h.since,
                       TIMESTAMPDIFF(MONTH, h.since, CURDATE()) AS knight_months,
                       COALESCE(att.cnt, 0) AS signins, COALESCE(att.in_fam, 0) AS in_fam,
                       CASE WHEN EXISTS (SELECT 1 FROM ' . DB_PREFIX . 'dues d WHERE ' . $duesLive . ') THEN 1 ELSE 0 END AS dues_paid,
                       (SELECT CASE WHEN MAX(d.dues_for_life) = 1 THEN \'9999-12-31\' ELSE MAX(d.dues_until) END
                          FROM ' . DB_PREFIX . 'dues d WHERE ' . $duesLive . ') AS dues_until
                FROM ' . DB_PREFIX . 'mundane m
                JOIN (SELECT x.mundane_id, GROUP_CONCAT(x.order_id ORDER BY x.order_id) AS orders, MIN(x.since) AS since
                        FROM (' . $this->holdingsSql() . ') x GROUP BY x.mundane_id) h ON h.mundane_id = m.mundane_id
                LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
                LEFT JOIN (SELECT a.mundane_id, ' . $expr . ' AS cnt, MAX(CASE WHEN ' . $inScope . ' THEN 1 ELSE 0 END) AS in_fam
                             FROM ' . DB_PREFIX . 'attendance a
                             JOIN (SELECT DISTINCT y.mundane_id FROM (' . $this->holdingsSql() . ') y) hk ON hk.mundane_id = a.mundane_id
                            WHERE ' . $attScope . ' AND a.date >= \'' . $this->esc($start) . '\'' . $onlineClause . '
                            GROUP BY a.mundane_id) att ON att.mundane_id = m.mundane_id
                WHERE m.active = 1 AND m.is_legend = 0
                  AND (m.kingdom_id IN (' . $fam . ') OR COALESCE(att.in_fam, 0) = 1)
                ORDER BY m.persona, m.mundane_id';

        $knights = [];
        $this->db->Clear();
        $rs = $this->db->DataSet($sql);
        if ($rs) {
            while ($rs->Next()) {
                $orders = [];
                foreach (array_filter(explode(',', (string) $rs->orders), 'strlen') as $oid) {
                    $oid = (int) $oid;
                    if (isset(self::ORDER_NAMES[$oid])) {
                        $orders[] = ['AwardId' => $oid, 'Name' => self::ORDER_NAMES[$oid]];
                    }
                }
                $knights[(int) $rs->mundane_id] = [
                    'MundaneId'     => (int) $rs->mundane_id,
                    'Persona'       => (string) $rs->persona,
                    'ParkId'        => (int) $rs->park_id,
                    'ParkName'      => (string) $rs->park_name,
                    'HomeKingdomId' => (int) $rs->home_kingdom_id,
                    'Resident'      => in_array((int) $rs->home_kingdom_id, $family, true),
                    'Orders'        => $orders,
                    'SignIns'       => (int) $rs->signins,
                    'DuesPaid'      => (int) $rs->dues_paid === 1,
                    'DuesUntil'     => $rs->dues_until ? (string) $rs->dues_until : null,
                    'Waivered'      => (int) $rs->waivered === 1,
                    'Suspended'     => (int) $rs->suspended === 1,
                    'KnightMonths'  => ($rs->since && $rs->knight_months !== null) ? (int) $rs->knight_months : null,
                    'KnightSince'   => $rs->since ? (string) $rs->since : null,
                ];
            }
        }

        // Optional AND with the kingdom's own voting rules (only for kingdoms that have stored rules).
        $votingOk = [];
        if (!empty($config['use_kingdom_voting_rules'])) {
            foreach ($family as $k) {
                $rules = VotingRules::rulesForKingdom((int) $k);
                if ($rules === null) {
                    continue;
                }
                $res = Ork3::$Lib->report->GetVotingEligible(['KingdomId' => (int) $k]);
                $threshold = (int) ($rules['ActiveKnightThreshold'] ?? 0);
                foreach ((array) ($res['Players'] ?? []) as $pl) {
                    $ok = $threshold > 0 ? !empty($pl['ActiveKnight']) : !empty($pl['VotingEligible']);
                    $votingOk[(int) $pl['MundaneId']] = $ok;
                }
            }
        }

        $out = [];
        foreach ($knights as $id => $k) {
            $facts = [
                'SignIns' => $k['SignIns'], 'DuesPaid' => $k['DuesPaid'], 'Waivered' => $k['Waivered'],
                'Resident' => $k['Resident'], 'Suspended' => $k['Suspended'], 'KnightMonths' => $k['KnightMonths'],
                'VotingOk' => ($k['Resident'] && array_key_exists($id, $votingOk)) ? $votingOk[$id] : null,
            ];
            $eval = self::evaluateEligibility($facts, $config);
            $k['Eligible'] = $eval['Eligible'];
            $k['Reasons']  = $eval['Reasons'];
            $out[] = $k;
        }
        return ['Knights' => $out, 'WindowStart' => $start];
    }

    private function appointGate(int $uid, int $kingdomId, int $mundaneId): ?array
    {
        if (!valid_id($kingdomId) || !$this->kingdomExists($kingdomId)) {
            return $this->fail('That Kingdom does not exist.');
        }
        if (!$this->canAppoint($uid, $kingdomId)) {
            return $this->denied('Only Kingdom officers with CREATE authority, or an ORK admin, can appoint Circle officers.');
        }
        if (!valid_id($mundaneId)) {
            return $this->fail('Choose a player.');
        }
        if (Legend::isLegend($mundaneId)) {
            return $this->denied(Legend::REFUSED);
        }
        return null;
    }

    /** @return array{MundaneId:int, Persona:string}|null */
    private function activePlayer(int $mundaneId): ?array
    {
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT mundane_id, persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' AND active = 1 AND is_legend = 0 LIMIT 1'
        );
        return ($rs && $rs->Next()) ? ['MundaneId' => (int) $rs->mundane_id, 'Persona' => (string) $rs->persona] : null;
    }

    private function holdsKnighthood(int $mundaneId): bool
    {
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT 1 AS ok FROM (' . $this->holdingsSql($mundaneId) . ') z LIMIT 1');
        return (bool) ($rs && $rs->Next());
    }

    private function kingdomExists(int $kingdomId): bool
    {
        if (!valid_id($kingdomId)) {
            return false;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int) $kingdomId . ' LIMIT 1');
        return (bool) ($rs && $rs->Next());
    }

    /** @return array<int,array{CircleAdminId:int,MundaneId:int,Persona:string,Role:string,AddedBy:int,AddedAt:string}> */
    private function adminRows(int $kingdomId): array
    {
        $rows = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.circle_admin_id, a.mundane_id, m.persona, a.role, a.added_by, a.added_at
             FROM ' . DB_PREFIX . 'knight_circle_admin a
             LEFT JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = a.mundane_id
             WHERE a.kingdom_id = ' . (int) $kingdomId . "
             ORDER BY (a.role = 'gmok') DESC, m.persona, a.circle_admin_id"
        );
        if ($rs) {
            while ($rs->Next()) {
                $rows[] = [
                    'CircleAdminId' => (int) $rs->circle_admin_id,
                    'MundaneId'     => (int) $rs->mundane_id,
                    'Persona'       => (string) $rs->persona,
                    'Role'          => (string) $rs->role,
                    'AddedBy'       => (int) $rs->added_by,
                    'AddedAt'       => (string) $rs->added_at,
                ];
            }
        }
        return $rows;
    }

    /** The danger audit, as BonusCredit does it: a failed audit must not undo or break the write. */
    private function audit(string $action, array $params, string $entity, int $entityId, $prior, $post): void
    {
        if (!isset(Ork3::$Lib->dangeraudit)) {
            return;
        }
        try {
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . $action, $params, $entity, $entityId, $prior, $post);
        } catch (Throwable $e) {
            // The write already happened.
        }
    }

    private function esc($v)
    {
        return str_replace(["'", '\\'], ["''", '\\\\'], (string) $v);
    }

    private function fail(string $error): array
    {
        return ['Status' => 1, 'Error' => $error];
    }

    private function denied(string $error): array
    {
        return ['Status' => 3, 'Error' => $error];
    }
}
