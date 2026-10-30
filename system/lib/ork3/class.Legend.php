<?php

/**
 * Legend: the In Memoriam status of a deceased player.
 *
 * A Legend is an ork_mundane row with is_legend = 1 and the year they passed
 * in legend_year. The record and its history stay; the page becomes a
 * memorial and the account is closed to edits by anyone but the people who may
 * manage the status: an officer of the player's kingdom (or of a kingdom above
 * it, for a principality player) or an ORK admin. This class holds the status
 * checks every guard shares, static so a guard in any layer can ask "is this
 * player a Legend, and may this caller edit them anyway?" in one call.
 *
 * It also does the conversion itself: preview(), convert(), undo() and
 * setYear(). Each returns ['Status' => 0, ...], or Status 1 for a bad request and
 * 3 for not authorized, with the reason in 'Error'.
 *
 * MyISAM: ork_application_auth and ork_participant_mundane can't roll back, so
 * convert() writes them last, after everything that can fail, app tokens first
 * because a lost app token costs only a re-authorization. Every other table it
 * touches is InnoDB.
 *
 * The Memory Wall (memories(), addMemory(), removeMemory() and friends) is its
 * own section below, after the conversion code.
 *
 * startup.php instantiates every class in this directory, so Ork3::$Lib->legend
 * exists. What keeps these methods, which take a caller uid and not a token, off
 * the JSON gateway is the explicit class list in orkservice/Json/index.php, where
 * Legend must never be added. Callers reach it through Model_Legend.
 */
class Legend
{
    /** The one refusal message every guard uses. */
    public const REFUSED = 'This player is a Legend and can no longer be changed this way.';

    /** What a Legend's account sees at login, with the right password and only then. */
    public const LOGIN_REFUSED = "Your access to the ORK has been restricted. If you believe this is a mistake, contact your kingdom's officers.";

    /** The longest memory, in characters. */
    public const MAX_MEMORY_CHARS = 2000;

    /** The most memories one author may post on one wall in any rolling 24 hours. */
    public const MAX_MEMORIES_PER_DAY = 3;

    /** Memories shown at a time; also the most one read returns. */
    public const MEMORIES_PER_PAGE = 25;

    /** The longest reason for removing a memory, in characters. */
    public const MAX_REMOVAL_REASON_CHARS = 255;

    /** Nothing in the ORK predates this. */
    public const MIN_YEAR = 1983;

    /** Item types deleted by primary key: type => [table, primary key]. */
    private const DELETED_BY_ID = [
        'rsvp'                 => ['event_rsvp', 'rsvp_id'],
        'event_staff'          => ['event_staff', 'event_staff_id'],
        'schedule_lead'        => ['event_schedule_lead', 'event_schedule_lead_id'],
        'qual_manager'         => ['qual_manager', 'qual_manager_id'],
        'bonus_credit_granter' => ['bonus_credit_granter', 'granter_id'],
    ];

    /** When an occurrence (`cd`) ends. A zero or backwards event_end falls back to its start. */
    private const EVENT_END_SQL = 'CASE WHEN cd.event_end >= cd.event_start THEN cd.event_end ELSE cd.event_start END';

    /** The columns a memory read shares; memoryFrom() is its FROM. */
    private const MEMORY_SQL_SELECT = 'SELECT lm.legend_memory_id, lm.author_id, lm.author_persona, m.persona, pk.abbreviation AS park_abbr, k.abbreviation AS kingdom_abbr, lm.body, lm.created';

    /** @var array<int,bool> per-request memo of isLegend() */
    private static $memo = [];

    /** Is this player a Legend? Memoised for the request; clearMemo() after a change. */
    public static function isLegend(int $mundaneId): bool
    {
        if ($mundaneId <= 0) {
            return false;
        }
        if (!array_key_exists($mundaneId, self::$memo)) {
            global $DB;
            $DB->Clear();
            $rs = $DB->DataSet('SELECT is_legend FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId);
            self::$memo[$mundaneId] = ($rs && $rs->Next()) ? ((int) $rs->is_legend === 1) : false;
        }
        return self::$memo[$mundaneId];
    }

    /** Forget every memoised answer; call after converting, undoing or in tests. */
    public static function clearMemo(): void
    {
        self::$memo = [];
    }

    /** A global ORK admin: an unscoped admin authorization row. */
    public static function isOrkAdmin(int $uid): bool
    {
        return $uid > 0 && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);
    }

    /**
     * May $uid manage this player's Legend status (and edit a Legend)? EDIT on
     * the player's kingdom, which a parent kingdom's officer holds for a
     * principality, or an ORK admin; as in Player::SetPlayerSuspension. A park
     * officer does not qualify unless also a kingdom officer.
     */
    public static function mayManage(int $uid, int $mundaneId): bool
    {
        if ($uid <= 0 || $mundaneId <= 0) {
            return false;
        }
        if (self::isOrkAdmin($uid)) {
            return true;
        }
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId);
        if (!$rs || !$rs->Next()) {
            return false;
        }
        $kingdomId = (int) $rs->kingdom_id;
        return valid_id($kingdomId)
            && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $kingdomId, AUTH_EDIT);
    }

    /** Must a guard refuse $uid's edit of this player? True for a Legend unless $uid may manage them. */
    public static function blocksEdit(int $uid, int $mundaneId): bool
    {
        return self::isLegend($mundaneId) && !self::mayManage($uid, $mundaneId);
    }

    /** May $uid move this player between parks? A Legend moves only by an ORK admin, whatever park authority $uid holds. */
    public static function mayMove(int $uid, int $mundaneId): bool
    {
        return !self::isLegend($mundaneId) || self::isOrkAdmin($uid);
    }

    /** May $uid edit this player from the park-level admin surfaces? Park EDIT, less a Legend's gate. */
    public static function canEditAdmin(int $uid, int $mundaneId, int $parkId): bool
    {
        return $uid > 0
            && (bool) Ork3::$Lib->authorization->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_EDIT)
            && !self::blocksEdit($uid, $mundaneId);
    }

    /**
     * What $uid gets on this player's profile. CanViewAdmin is the park-level
     * read access an officer has on anyone, Legend or not; CanEditAdmin and
     * CanEditAwards take a Legend's gate into account, so on a living player the
     * first two match. A park officer may still grant a new award to a Legend,
     * which is why the award row controls are a separate flag from the grant.
     *
     * MarkedBy and MarkedOn name who converted this Legend, for those who may
     * manage them; empty for anyone else, or when the log has no conversion.
     *
     * @return array{IsLegend: bool, CanManageLegend: bool, MarkedBy: string, MarkedOn: string, CanViewAdmin: bool, CanEditAdmin: bool, CanEditAwards: bool, Memories: list<array<string, mixed>>, MemoryCount: int, RemovedMemories: list<array<string, mixed>>, MinYear: int, MaxMemoryChars: int}
     */
    public static function profileView(int $uid, int $mundaneId, int $parkId): array
    {
        $auth         = Ork3::$Lib->authorization;
        $blocked      = self::blocksEdit($uid, $mundaneId);
        $viewAdmin    = $uid > 0 && (bool) $auth->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_EDIT);
        $manageAwards = $uid > 0 && (bool) $auth->HasAuthority($uid, AUTH_PARK, $parkId, AUTH_CREATE);
        // refuseConversion's fixed refusals: nobody marks themselves, and player 1 never. A role
        // outside the officer's authority is not one of them: it is refused when the modal opens.
        $canManage    = $mundaneId !== 1 && $uid !== $mundaneId && self::mayManage($uid, $mundaneId);
        $marked       = $canManage && self::isLegend($mundaneId) ? self::lastConversion($mundaneId) : null;

        return [
            'IsLegend'        => self::isLegend($mundaneId),
            'CanManageLegend' => $canManage,
            'MarkedBy'        => (string) ($marked['persona'] ?? ''),
            'MarkedOn'        => substr((string) ($marked['created'] ?? ''), 0, 10),
            'CanViewAdmin'    => $viewAdmin,
            'CanEditAdmin'    => self::canEditAdmin($uid, $mundaneId, $parkId),
            'CanEditAwards'   => $manageAwards && !$blocked,
            'Memories'        => self::memories($mundaneId),
            'MemoryCount'     => self::memoryCount($mundaneId),
            'RemovedMemories' => $canManage && self::isLegend($mundaneId) ? self::removedMemories($uid, $mundaneId)['Memories'] : [],
            'MinYear'         => self::MIN_YEAR,
            'MaxMemoryChars'  => self::MAX_MEMORY_CHARS,
        ];
    }

    /** A form field as a year: its value if it is a whole four-digit number, else 0, which every year check refuses. */
    public static function parseYear($raw): int
    {
        return is_string($raw) && preg_match('/^\s*(\d{4})\s*$/', $raw, $m) ? (int) $m[1] : 0;
    }

    /**
     * Everything a conversion would clear, for the officer to confirm. Writes
     * nothing.
     *
     * @return array{Status: int, Error?: string, Items?: list<array{Type: string, Label: string, Id: int}>, Warnings?: list<string>, Player?: array{Persona: string, ParkName: string, KingdomName: string, LastAttendance: string, LatestAward: string, Suspended: bool}}
     */
    public static function preview(int $uid, int $mundaneId): array
    {
        $refused = self::refuseConversion($uid, $mundaneId);
        if ($refused !== null) {
            return $refused;
        }
        if (self::isLegend($mundaneId)) {
            return self::refuse(1, self::REFUSED);
        }
        $player   = self::previewPlayer($mundaneId);
        $warnings = self::vacancyWarnings($mundaneId);
        if ($player['LastAttendance'] >= date('Y-m-d', strtotime('-90 days'))) {
            $warnings[] = 'Attended on ' . date('F j, Y', strtotime($player['LastAttendance'])) . ', within the last 90 days.';
        }
        if ($player['Suspended']) {
            $warnings[] = 'This player is currently suspended or banned.';
        }
        return ['Status' => 0, 'Items' => self::clearable($mundaneId), 'Warnings' => $warnings, 'Player' => $player];
    }

    /**
     * Who the officer is about to mark, and the last signs of them in the record.
     * Dates are Y-m-d, or '' when there is none; a date still to come is no sign.
     *
     * @return array{Persona: string, ParkName: string, KingdomName: string, LastAttendance: string, LatestAward: string, Suspended: bool}
     */
    private static function previewPlayer(int $mundaneId): array
    {
        $p = DB_PREFIX;
        $r = self::rows(
            "SELECT m.persona, m.suspended, m.penalty_box, pk.name AS park_name, k.name AS kingdom_name,
                    (SELECT MAX(a.date) FROM {$p}attendance a
                      WHERE a.mundane_id = m.mundane_id AND a.date > '0000-00-00' AND a.date <= CURDATE()) AS last_attendance,
                    (SELECT MAX(aw.date) FROM {$p}awards aw
                      WHERE aw.mundane_id = m.mundane_id AND aw.date > '0000-00-00' AND aw.date <= CURDATE()
                        AND (aw.revoked = 0 OR aw.revoked IS NULL)) AS latest_award
             FROM {$p}mundane m
             LEFT JOIN {$p}park pk ON pk.park_id = m.park_id
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = m.kingdom_id
             WHERE m.mundane_id = {$mundaneId}"
        )[0];
        return [
            'Persona'        => (string) $r['persona'],
            'ParkName'       => (string) $r['park_name'],
            'KingdomName'    => (string) $r['kingdom_name'],
            'LastAttendance' => (string) $r['last_attendance'],
            'LatestAward'    => (string) $r['latest_award'],
            'Suspended'      => (int) $r['suspended'] === 1 || (int) $r['penalty_box'] === 1,
        ];
    }

    /**
     * What a conversion leaves for someone to fill: the officer's to-do list.
     * Reads the seats and unit rows, so ask before they are cleared.
     *
     * @return list<string>
     */
    private static function vacancyWarnings(int $mundaneId): array
    {
        $warnings = [];
        foreach (self::seats($mundaneId) as $seat) {
            $warnings[] = self::seatLabel($seat) . ' will become vacant.';
        }
        foreach (self::unitsLosingLastManager($mundaneId) as $unitName) {
            $warnings[] = $unitName . ' will have no manager.';
        }
        return $warnings;
    }

    /**
     * Mark a player a Legend. In one transaction: clear their future roles,
     * set the status, log what was cleared, and end every login they hold. The
     * clearing goes straight to the tables, ahead of the status change, so no
     * guard on a Legend can stop a conversion's own writes.
     *
     * @return array{Status: int, Error?: string, Cleared?: list<array{Type: string, Label: string, Id: int}>, Warnings?: list<string>}
     */
    public static function convert(int $uid, int $mundaneId, int $year): array
    {
        $refused = self::refuseConversion($uid, $mundaneId) ?? self::refuseYear($year);
        if ($refused !== null) {
            return $refused;
        }
        return self::transaction($mundaneId, function (array $player) use ($uid, $mundaneId, $year): array {
            if ((int) $player['is_legend'] === 1) {
                return self::refuse(1, self::REFUSED);
            }
            $priorActive = (int) $player['active'];
            $items       = self::clearable($mundaneId);
            $warnings    = self::vacancyWarnings($mundaneId);

            // Seats first: set_officer finds the seat's authorization row, which the delete below would take away.
            self::vacateSeats($uid, $mundaneId);
            self::deleteItems($items, ...array_keys(self::DELETED_BY_ID));
            self::write('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . $mundaneId);
            self::clearCourtRoles($items);

            self::write('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 1, legend_year = ' . $year . ', active = 0 WHERE mundane_id = ' . $mundaneId);
            self::log($mundaneId, 'convert', $year, $priorActive, $items, $uid, $warnings);
            self::audit('convert', $uid, $mundaneId, ['IsLegend' => 0, 'Active' => $priorActive], ['IsLegend' => 1, 'Active' => 0, 'LegendYear' => $year, 'ClearedCount' => count($items)]);

            // The MyISAM writes, last (see the class comment).
            self::purgeSessions($mundaneId);
            self::write(self::deleteSql('participant_mundane', 'participant_mundane_id', self::idsOf($items, 'tournament')));

            return ['Status' => 0, 'Cleared' => $items, 'Warnings' => $warnings];
        });
    }

    /**
     * Remove Legend status. The year stays in the log only, the active flag
     * goes back to what it was, memories stay on the table (hidden while the
     * player is not a Legend), and nothing cleared returns: the caller gets the
     * last conversion's snapshot to restore by hand.
     *
     * @return array{Status: int, Error?: string, Cleared?: list<array{Type: string, Label: string, Id: int}>, Warnings?: list<string>, ClearedOn?: string}
     */
    public static function undo(int $uid, int $mundaneId): array
    {
        $refused = self::refuseManagement($uid, $mundaneId);
        if ($refused !== null) {
            return $refused;
        }
        return self::transaction($mundaneId, function (array $player) use ($uid, $mundaneId): array {
            if ((int) $player['is_legend'] !== 1) {
                return self::refuse(1, 'This player is not a Legend.');
            }
            $last = self::lastConversion($mundaneId);
            // A Legend with no conversion on the log stays inactive: reactivating is one edit away, a wrong guess isn't.
            $priorActive = $last ? (int) $last['prior_active'] : 0;
            $snapshot    = self::lastSnapshot($mundaneId);
            $year        = (int) $player['legend_year'];

            self::write('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 0, legend_year = 0, active = ' . $priorActive . ' WHERE mundane_id = ' . $mundaneId);
            self::log($mundaneId, 'undo', $year, $priorActive, [], $uid);
            self::audit('undo', $uid, $mundaneId, ['IsLegend' => 1, 'Active' => (int) $player['active'], 'LegendYear' => $year], ['IsLegend' => 0, 'Active' => $priorActive]);

            return ['Status' => 0] + $snapshot;
        });
    }

    /**
     * What the last conversion cleared, for the officer to read before undoing.
     * Writes nothing.
     *
     * @return array{Status: int, Error?: string, Cleared?: list<array{Type: string, Label: string, Id: int}>, Warnings?: list<string>, ClearedOn?: string}
     */
    public static function cleared(int $uid, int $mundaneId): array
    {
        $refused = self::refuseManagement($uid, $mundaneId);
        if ($refused !== null) {
            return $refused;
        }
        if (!self::isLegend($mundaneId)) {
            return self::refuse(1, 'This player is not a Legend.');
        }
        return ['Status' => 0] + self::lastSnapshot($mundaneId);
    }

    /**
     * This player's own latest conversion on the log. A merge re-homes a source
     * Legend's rows here with a JSON object in cleared_json; those are not theirs.
     *
     * @return array{prior_active: mixed, created: mixed, persona: mixed}|null
     */
    private static function lastConversion(int $mundaneId): ?array
    {
        $p = DB_PREFIX;
        $rows = self::rows(
            "SELECT ll.prior_active, ll.created, m.persona
             FROM {$p}legend_log ll
             LEFT JOIN {$p}mundane m ON m.mundane_id = ll.actor_id
             WHERE ll.mundane_id = {$mundaneId} AND ll.action = 'convert' AND ll.cleared_json NOT LIKE '{%'
             ORDER BY ll.legend_log_id DESC LIMIT 1"
        );
        return $rows[0] ?? null;
    }

    /**
     * The restoration list: the newest of this player's own conversions that
     * cleared anything. Back-dating a record means undoing and converting again,
     * and that second conversion finds nothing left to clear, so it must not hide
     * the list from the first.
     *
     * @return array{Cleared: list<array{Type: string, Label: string, Id: int}>, Warnings: list<string>, ClearedOn: string}
     */
    private static function lastSnapshot(int $mundaneId): array
    {
        $rows = self::rows(
            'SELECT cleared_json, warnings_json, created FROM ' . DB_PREFIX . 'legend_log
             WHERE mundane_id = ' . $mundaneId . " AND action = 'convert' AND cleared_json NOT LIKE '{%' AND cleared_json <> '[]'
             ORDER BY legend_log_id DESC LIMIT 1"
        );
        return [
            'Cleared'   => json_decode((string) ($rows[0]['cleared_json'] ?? ''), true) ?: [],
            'Warnings'  => json_decode((string) ($rows[0]['warnings_json'] ?? ''), true) ?: [],
            'ClearedOn' => substr((string) ($rows[0]['created'] ?? ''), 0, 10),
        ];
    }

    /**
     * Correct a Legend's year.
     *
     * @return array{Status: int, Error?: string}
     */
    public static function setYear(int $uid, int $mundaneId, int $year): array
    {
        $refused = self::refuseManagement($uid, $mundaneId) ?? self::refuseYear($year);
        if ($refused !== null) {
            return $refused;
        }
        return self::transaction($mundaneId, function (array $player) use ($uid, $mundaneId, $year): array {
            if ((int) $player['is_legend'] !== 1) {
                return self::refuse(1, 'This player is not a Legend.');
            }
            self::write('UPDATE ' . DB_PREFIX . 'mundane SET legend_year = ' . $year . ' WHERE mundane_id = ' . $mundaneId);
            self::log($mundaneId, 'year', $year, (int) $player['active'], [], $uid);
            self::audit('setYear', $uid, $mundaneId, ['LegendYear' => (int) $player['legend_year']], ['LegendYear' => $year]);

            return ['Status' => 0];
        });
    }

    /**
     * End every login the player holds, whoever asks: their ork_session rows,
     * the vestigial ork_mundane.token and third-party app tokens. An app's
     * approval stays, so it can ask for a new token if the status is ever undone.
     * Authorization::DestroySessionsForUser can't do this; it only works with
     * the user's own token. Throws if a write fails.
     */
    public static function purgeSessions(int $mundaneId): void
    {
        self::write('DELETE FROM ' . DB_PREFIX . 'session WHERE mundane_id = ' . $mundaneId);
        self::write('UPDATE ' . DB_PREFIX . "mundane SET token = '' WHERE mundane_id = " . $mundaneId);
        self::write('UPDATE ' . DB_PREFIX . "application_auth SET token = '', token_expires = NOW() WHERE mundane_id = " . $mundaneId);
    }

    /**
     * Ends a Legend's browser session on a controller that skips the per-request
     * token check. Drops the signed-in user so the request proceeds logged out.
     * Returns true if it did.
     */
    public static function endSessionIfLegend(object $session): bool
    {
        $uid = isset($session->user_id) ? (int) $session->user_id : 0;
        if (!self::isLegend($uid)) {
            return false;
        }
        unset($session->user_id, $session->token, $_SESSION['is_authorized_mundane_id']);

        return true;
    }

    // =======================================================================
    // Memory Wall: notes left on a Legend's profile by friends and family.
    // Bodies are stored and returned raw; escaping is the UI's job.
    // =======================================================================

    /**
     * One page of a Legend's visible memories, newest first. Empty for anyone who
     * is not a Legend, so undoing hides the wall without deleting it. The limit
     * and offset come from a public route: a limit outside 1 to MEMORIES_PER_PAGE
     * means a full page.
     *
     * @return list<array{MemoryId: int, AuthorId: int, AuthorPersona: string, KingdomAbbr: string, ParkAbbr: string, AuthorHome: string, AuthorMemoryCount: int, Body: string, Created: string, CreatedOn: string}>
     */
    public static function memories(int $mundaneId, int $limit = self::MEMORIES_PER_PAGE, int $offset = 0): array
    {
        if (!self::isLegend($mundaneId)) {
            return [];
        }
        $limit  = $limit > 0 && $limit < self::MEMORIES_PER_PAGE ? $limit : self::MEMORIES_PER_PAGE;
        $offset = max(0, $offset);
        $p = DB_PREFIX;
        return array_map([self::class, 'memoryShape'], self::asUtf8mb4(fn (): array => self::rows(
            self::MEMORY_SQL_SELECT . ",
                    (SELECT COUNT(*) FROM {$p}legend_memory same
                      WHERE same.mundane_id = lm.mundane_id AND same.author_id = lm.author_id AND same.removed_by_id = 0) AS author_memory_count
             " . self::memoryFrom() . "
             WHERE lm.mundane_id = {$mundaneId} AND lm.removed_by_id = 0
             ORDER BY lm.created DESC, lm.legend_memory_id DESC
             LIMIT {$limit} OFFSET {$offset}"
        )));
    }

    /** How many visible memories a Legend has: 0 for anyone who is not a Legend. */
    public static function memoryCount(int $mundaneId): int
    {
        if (!self::isLegend($mundaneId)) {
            return 0;
        }
        return (int) (self::rows('SELECT COUNT(*) AS n FROM ' . DB_PREFIX . "legend_memory WHERE mundane_id = {$mundaneId} AND removed_by_id = 0")[0]['n'] ?? 0);
    }

    /**
     * What has been taken off a wall, newest removal first, for those who may
     * manage the Legend. There is no restoring: this is the record.
     *
     * @return array{Status: int, Error?: string, Memories?: list<array<string, mixed>>}
     */
    public static function removedMemories(int $uid, int $mundaneId): array
    {
        if ($uid <= 0) {
            return self::refuse(5, 'Not logged in');
        }
        $refused = self::refuseManagement($uid, $mundaneId);
        if ($refused !== null) {
            return $refused;
        }
        $p = DB_PREFIX;
        $rows = self::asUtf8mb4(fn (): array => self::rows(
            self::MEMORY_SQL_SELECT . ", lm.removed_at, lm.removed_reason, rb.persona AS removed_by
             " . self::memoryFrom() . "
             LEFT JOIN {$p}mundane rb ON rb.mundane_id = lm.removed_by_id
             WHERE lm.mundane_id = {$mundaneId} AND lm.removed_by_id <> 0
             ORDER BY lm.removed_at DESC, lm.legend_memory_id DESC"
        ));
        $out = [];
        foreach ($rows as $r) {
            $out[] = self::memoryShape($r) + [
                'RemovedBy'     => (string) $r['removed_by'],
                'RemovedOn'     => self::longDate((string) $r['removed_at']),
                'RemovedReason' => (string) $r['removed_reason'],
            ];
        }
        return ['Status' => 0, 'Memories' => $out];
    }

    /** The FROM a memory read shares: the byline's author and their current home. */
    private static function memoryFrom(): string
    {
        $p = DB_PREFIX;
        return "FROM {$p}legend_memory lm
             LEFT JOIN {$p}mundane m ON m.mundane_id = lm.author_id
             LEFT JOIN {$p}park pk ON pk.park_id = m.park_id
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = m.kingdom_id";
    }

    /**
     * A memory row as the wall shows it, the server's page and revised.js alike.
     * The byline is the persona as it stood when posted, or the live one for a
     * memory from before that was kept. The home is always current.
     *
     * @param array<string, mixed> $r
     * @return array<string, mixed>
     */
    private static function memoryShape(array $r): array
    {
        $kingdom = (string) $r['kingdom_abbr'];
        $park    = (string) $r['park_abbr'];
        return [
            'MemoryId'          => (int) $r['legend_memory_id'],
            'AuthorId'          => (int) $r['author_id'],
            'AuthorPersona'     => (string) $r['author_persona'] !== '' ? (string) $r['author_persona'] : (string) $r['persona'],
            'KingdomAbbr'       => $kingdom,
            'ParkAbbr'          => $park,
            'AuthorHome'        => $kingdom !== '' && $park !== '' ? $kingdom . ':' . $park : '',
            'AuthorMemoryCount' => (int) ($r['author_memory_count'] ?? 0),
            'Body'              => (string) $r['body'],
            'Created'           => (string) $r['created'],
            'CreatedOn'         => self::longDate((string) $r['created']),
        ];
    }

    /** A stored DATETIME as "November 14, 2026": its date part as stored, no timezone conversion. */
    private static function longDate(string $when): string
    {
        return date('F j, Y', strtotime(substr($when, 0, 10) . ' 12:00:00'));
    }

    /**
     * Leave a memory on a Legend's wall. The author must be a signed-in player
     * who is not suspended, in the penalty box or a Legend. The limits are
     * checked under the wall's row lock, so a burst of posts can't slip past them.
     *
     * @return array{Status: int, Error?: string, MemoryId?: int}
     */
    public static function addMemory(int $uid, int $mundaneId, string $body): array
    {
        if ($uid <= 0) {
            return self::refuse(5, 'Not logged in');
        }
        $author = self::rows('SELECT persona, suspended, penalty_box, is_legend FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $uid);
        if (!$author || (int) $author[0]['suspended'] !== 0 || (int) $author[0]['penalty_box'] !== 0 || (int) $author[0]['is_legend'] !== 0) {
            return self::refuse(3, 'Your account cannot leave memories right now.');
        }
        if (!self::isLegend($mundaneId)) {
            return self::refuse(1, 'Memories can only be left for a Legend.');
        }
        $body = trim($body);
        if ($body === '' || !mb_check_encoding($body, 'UTF-8')) {
            return self::refuse(1, 'Please write a memory.');
        }
        if (mb_strlen($body, 'UTF-8') > self::MAX_MEMORY_CHARS) {
            return self::refuse(1, 'A memory can be at most ' . self::MAX_MEMORY_CHARS . ' characters.');
        }
        // The weapon is an ordinary thing to remember someone by. Only the checked copy loses it.
        $word = (new ProfanityFilter())->firstMatch(preg_replace('/\bbastard[ \-]+swords?\b/iu', ' ', $body));
        if ($word !== null) {
            return self::refuse(1, 'Our filter stopped the word "' . $word . '". Please reword it; your text has been kept.');
        }
        $persona = (string) $author[0]['persona'];
        return self::transaction($mundaneId, function (array $player) use ($mundaneId, $uid, $body, $persona): array {
            if ((int) $player['is_legend'] !== 1) {
                return self::refuse(1, 'Memories can only be left for a Legend.');
            }
            return self::asUtf8mb4(function () use ($mundaneId, $uid, $body, $persona): array {
                // On the database's clock, the one `created` is written with. PHP's is on another zone.
                $earlier = self::rows(
                    'SELECT body, created > NOW() - INTERVAL 24 HOUR AS recent FROM ' . DB_PREFIX . "legend_memory
                     WHERE mundane_id = {$mundaneId} AND author_id = {$uid}"
                );
                if (array_sum(array_column($earlier, 'recent')) >= self::MAX_MEMORIES_PER_DAY) {
                    return self::refuse(1, 'Thank you for sharing so much. You can post up to ' . self::MAX_MEMORIES_PER_DAY . ' memories here in a day, so please come back tomorrow; your text has been kept.');
                }
                // Compared here, not in SQL: the column's collation treats some different texts as equal.
                if (in_array($body, array_column($earlier, 'body'), true)) {
                    return self::refuse(1, 'You have already shared this memory here.');
                }
                global $DB;
                $DB->Clear();
                $DB->body = $body;
                $DB->author_persona = $persona;
                $ok = $DB->ExecuteChecked(
                    'INSERT INTO ' . DB_PREFIX . "legend_memory (mundane_id, author_id, author_persona, body, created)
                     VALUES ({$mundaneId}, {$uid}, :author_persona, :body, NOW())"
                );
                $DB->Clear();
                if (!$ok) {
                    throw new RuntimeException('memory write failed');
                }
                return ['Status' => 0, 'MemoryId' => (int) (self::rows('SELECT LAST_INSERT_ID() AS id')[0]['id'] ?? 0)];
            });
        });
    }

    /**
     * Take a memory off the wall (soft: the row stays, and nothing in the UI
     * restores it). Only those who may manage the Legend's status, and only while
     * the player is a Legend; an author may not remove their own. The removal and
     * its audit row are one transaction.
     *
     * @return array{Status: int, Error?: string}
     */
    public static function removeMemory(int $uid, int $memoryId, string $reason): array
    {
        if ($uid <= 0) {
            return self::refuse(5, 'Not logged in');
        }
        $row = $memoryId > 0
            ? self::rows('SELECT mundane_id, removed_by_id FROM ' . DB_PREFIX . 'legend_memory WHERE legend_memory_id = ' . $memoryId)
            : [];
        if (!$row || (int) $row[0]['removed_by_id'] !== 0) {
            return self::refuse(1, 'Memory not found.');
        }
        if (!self::mayManage($uid, (int) $row[0]['mundane_id'])) {
            return self::refuse(3, 'You do not have authority over this player.');
        }
        $reason  = trim($reason);
        $refused = self::refuseRemovalReason($reason);
        if ($refused !== null) {
            return $refused;
        }
        $mundaneId = (int) $row[0]['mundane_id'];
        return self::transaction($mundaneId, function (array $player) use ($uid, $mundaneId, $memoryId, $reason): array {
            if ((int) $player['is_legend'] !== 1) {
                return self::refuse(1, 'This player is not a Legend.');
            }
            $memory = self::asUtf8mb4(fn (): array => self::rows(
                'SELECT author_id, body FROM ' . DB_PREFIX . "legend_memory WHERE legend_memory_id = {$memoryId} AND removed_by_id = 0 FOR UPDATE"
            ));
            if (!$memory) {
                return self::refuse(1, 'Memory not found.');
            }
            self::stampRemoved([$memoryId], $uid, $reason);
            self::audit(
                'removeMemory',
                $uid,
                $mundaneId,
                ['MemoryId' => $memoryId, 'AuthorId' => (int) $memory[0]['author_id'], 'LegendId' => $mundaneId, 'Body' => (string) $memory[0]['body'], 'Reason' => $reason],
                ['RemovedById' => $uid]
            );
            return ['Status' => 0];
        });
    }

    /**
     * Remove everything one author still has on one wall, on the same terms as
     * removeMemory(), with one audit row for the lot.
     *
     * @return array{Status: int, Error?: string, Removed?: int}
     */
    public static function removeAuthorMemories(int $uid, int $mundaneId, int $authorId, string $reason): array
    {
        if ($uid <= 0) {
            return self::refuse(5, 'Not logged in');
        }
        $refused = self::refuseManagement($uid, $mundaneId);
        if ($refused !== null) {
            return $refused;
        }
        $reason  = trim($reason);
        $refused = self::refuseRemovalReason($reason);
        if ($refused !== null) {
            return $refused;
        }
        return self::transaction($mundaneId, function (array $player) use ($uid, $mundaneId, $authorId, $reason): array {
            if ((int) $player['is_legend'] !== 1) {
                return self::refuse(1, 'This player is not a Legend.');
            }
            $ids = array_map('intval', array_column(self::rows(
                'SELECT legend_memory_id FROM ' . DB_PREFIX . "legend_memory
                 WHERE mundane_id = {$mundaneId} AND author_id = {$authorId} AND removed_by_id = 0 FOR UPDATE"
            ), 'legend_memory_id'));
            if (!$ids) {
                return self::refuse(1, 'Memory not found.');
            }
            self::stampRemoved($ids, $uid, $reason);
            self::audit(
                'removeAuthorMemories',
                $uid,
                $mundaneId,
                ['MemoryIds' => $ids, 'AuthorId' => $authorId, 'LegendId' => $mundaneId, 'Reason' => $reason],
                ['RemovedById' => $uid, 'RemovedCount' => count($ids)]
            );
            return ['Status' => 0, 'Removed' => count($ids)];
        });
    }

    /** Why this removal reason can't be used, or null when it can. Plain text, trimmed already; no filter. */
    private static function refuseRemovalReason(string $reason): ?array
    {
        if ($reason === '' || !mb_check_encoding($reason, 'UTF-8')) {
            return self::refuse(1, 'Please give a reason for the removal.');
        }
        if (mb_strlen($reason, 'UTF-8') > self::MAX_REMOVAL_REASON_CHARS) {
            return self::refuse(1, 'A reason can be at most ' . self::MAX_REMOVAL_REASON_CHARS . ' characters.');
        }
        return null;
    }

    /**
     * Mark memories removed. Throws if the write fails.
     *
     * @param list<int> $memoryIds
     */
    private static function stampRemoved(array $memoryIds, int $uid, string $reason): void
    {
        self::asUtf8mb4(function () use ($memoryIds, $uid, $reason): void {
            global $DB;
            $DB->Clear();
            $DB->removed_reason = $reason;
            $ok = $DB->ExecuteChecked(
                'UPDATE ' . DB_PREFIX . "legend_memory SET removed_by_id = {$uid}, removed_at = NOW(), removed_reason = :removed_reason
                 WHERE legend_memory_id IN (" . implode(',', $memoryIds) . ') AND removed_by_id = 0'
            );
            $DB->Clear();
            if (!$ok) {
                throw new RuntimeException('memory removal failed');
            }
        });
    }

    /**
     * Run $work with the connection speaking utf8mb4, then put the charset back
     * exactly as it was, even when $work throws. The app's connection is
     * `utf8` (three bytes a character), which turns an emoji into question
     * marks; the memory column is utf8mb4. Only the memory read and write use
     * this, so nothing else on the shared connection changes.
     *
     * @template T
     * @param callable(): T $work
     * @return T
     */
    private static function asUtf8mb4(callable $work)
    {
        global $DB;
        $prior = (string) (self::rows('SELECT @@character_set_client AS cs')[0]['cs'] ?? '');
        if (!preg_match('/^[a-z0-9_]+$/', $prior)) {
            return $work();
        }
        $prior = $prior === 'utf8mb3' ? 'utf8' : $prior;
        $DB->Clear();
        $DB->Execute("SET NAMES 'utf8mb4'");
        try {
            return $work();
        } finally {
            $DB->Clear();
            $DB->Execute("SET NAMES '{$prior}'");
            $DB->Clear();
        }
    }

    /** @return array{Status: int, Error: string} */
    private static function refuse(int $status, string $error): array
    {
        return ['Status' => $status, 'Error' => $error];
    }

    /** Why this year can't be used, or null when it can. */
    private static function refuseYear(int $year): ?array
    {
        $now = (int) date('Y');
        if ($year < self::MIN_YEAR || $year > $now) {
            return self::refuse(1, 'Enter a whole year from ' . self::MIN_YEAR . ' through ' . $now . '.');
        }
        return null;
    }

    /** Why $uid can't manage this player's status, or null when they can. */
    private static function refuseManagement(int $uid, int $mundaneId): ?array
    {
        if ($mundaneId <= 0 || !self::rows('SELECT 1 FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundaneId)) {
            return self::refuse(1, 'Player not found.');
        }
        if (!self::mayManage($uid, $mundaneId)) {
            return self::refuse(3, 'You do not have authority over this player.');
        }
        return null;
    }

    /** Why $uid can't convert this player, or null when they can. A Legend is checked under the row lock, not here. */
    private static function refuseConversion(int $uid, int $mundaneId): ?array
    {
        if ($mundaneId === 1) {
            return self::refuse(1, 'This player cannot be marked as a Legend.');
        }
        $refused = self::refuseManagement($uid, $mundaneId);
        if ($refused === null && $uid === $mundaneId) {
            return self::refuse(1, 'You cannot mark yourself as a Legend.');
        }
        if ($refused === null && !self::isOrkAdmin($uid) && self::holdsRoleBeyond($uid, $mundaneId)) {
            return self::refuse(3, 'This player holds a role outside your authority. Please ask an ORK admin to mark them as a Legend.');
        }
        return $refused;
    }

    /**
     * Would converting this player take away something $uid has no authority
     * over? Conversion vacates every seat and deletes every authorization row, and
     * Move Player can bring anyone into a kingdom first. Unit and event rows don't
     * count: a unit has no kingdom, so they would stop an officer converting any
     * unit captain.
     */
    private static function holdsRoleBeyond(int $uid, int $mundaneId): bool
    {
        if (self::isOrkAdmin($mundaneId)) {
            return true;
        }
        $auth   = Ork3::$Lib->authorization;
        $scoped = self::rows(
            'SELECT kingdom_id, park_id FROM ' . DB_PREFIX . 'authorization
             WHERE mundane_id = ' . $mundaneId . ' AND event_id = 0 AND unit_id = 0 AND (kingdom_id > 0 OR park_id > 0)'
        );
        foreach (array_merge(self::seats($mundaneId), $scoped) as $held) {
            $allowed = (int) $held['park_id'] > 0
                ? $auth->HasAuthority($uid, AUTH_PARK, (int) $held['park_id'], AUTH_EDIT)
                : $auth->HasAuthority($uid, AUTH_KINGDOM, (int) $held['kingdom_id'], AUTH_EDIT);
            if (!$allowed) {
                return true;
            }
        }
        return false;
    }

    /**
     * Run $work in a transaction holding the player's row, so a double click
     * can't convert twice and overwrite prior_active. $work gets the locked row;
     * anything but Status 0 rolls back. A failure anywhere rolls back too.
     *
     * @param callable(array<string, mixed>): array $work
     */
    private static function transaction(int $mundaneId, callable $work): array
    {
        global $DB;
        $DB->BeginTrans();
        try {
            $player = self::rows('SELECT is_legend, legend_year, active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundaneId . ' FOR UPDATE');
            $result = $player ? $work($player[0]) : self::refuse(1, 'Player not found.');
            if ($result['Status'] !== 0) {
                $DB->RollbackTrans();
                return $result;
            }
            if (!$DB->CommitTrans()) {
                throw new RuntimeException('commit failed');
            }
        } catch (Throwable $e) {
            $DB->RollbackTrans();
            error_log('Legend: rolled back for player ' . $mundaneId . ': ' . $e->getMessage());
            return self::refuse(1, 'That could not be saved, so nothing was changed. Please try again.');
        }
        self::clearMemo();
        Ork3::$Lib->player->bustPlayerProfileCaches($mundaneId);
        Ork3::$Lib->player->bustRosterCachesForPlayer($mundaneId);
        return $result;
    }

    /**
     * A SELECT as rows. A failed read throws rather than reading as "nothing to
     * clear".
     *
     * @return list<array<string, mixed>>
     */
    private static function rows(string $sql): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet($sql);
        if (!$rs || !in_array($rs->__ERROR[0][1], ['00000', null], true)) {
            throw new RuntimeException('read failed: ' . $sql);
        }
        $rows = [];
        while ($rs->Next()) {
            $rows[] = $rs->CurrentFieldSet();
        }
        return $rows;
    }

    /** Run a write, throwing if it fails. Execute() would swallow the failure. */
    private static function write(string $sql): void
    {
        global $DB;
        $DB->Clear();
        if (!$DB->ExecuteChecked($sql)) {
            throw new RuntimeException('write failed: ' . $sql);
        }
    }

    /** @param list<int> $ids */
    private static function deleteSql(string $table, string $primaryKey, array $ids): string
    {
        return 'DELETE FROM ' . DB_PREFIX . $table . ' WHERE ' . $primaryKey . ' IN (' . implode(',', $ids ?: [0]) . ')';
    }

    /**
     * @param list<array{Type: string, Label: string, Id: int}> $items
     * @return list<int>
     */
    private static function idsOf(array $items, string $type): array
    {
        return array_map('intval', array_column(array_filter($items, fn ($item) => $item['Type'] === $type), 'Id'));
    }

    private static function deleteItems(array $items, string ...$types): void
    {
        foreach ($types as $type) {
            [$table, $primaryKey] = self::DELETED_BY_ID[$type];
            self::write(self::deleteSql($table, $primaryKey, self::idsOf($items, $type)));
        }
    }

    /**
     * What a conversion clears for this player, each item labelled well enough
     * to restore it by hand. Event roles go until the event has ended, so an
     * event under way doesn't keep offering them for check-in. A seat's own authorization row isn't listed: it
     * stays with the seat for the next officer. A Champion's or GMR's isn't part
     * of the seat (set_officer leaves it alone), so that one is listed and goes.
     *
     * @return list<array{Type: string, Label: string, Id: int}>
     */
    private static function clearable(int $mundaneId): array
    {
        $items = [];
        $add = function (string $type, string $label, $id) use (&$items): void {
            $items[] = ['Type' => $type, 'Label' => $label, 'Id' => (int) $id];
        };
        $p = DB_PREFIX;

        foreach (self::rows(
            "SELECT r.rsvp_id, e.name, cd.event_start
             FROM {$p}event_rsvp r
             JOIN {$p}event_calendardetail cd ON cd.event_calendardetail_id = r.event_calendardetail_id
             LEFT JOIN {$p}event e ON e.event_id = cd.event_id
             WHERE r.mundane_id = {$mundaneId} AND " . self::EVENT_END_SQL . " > NOW()
             ORDER BY r.rsvp_id"
        ) as $r) {
            $add('rsvp', 'RSVP for ' . self::onDate((string) $r['name'], (string) $r['event_start']), $r['rsvp_id']);
        }
        foreach (self::rows(
            "SELECT s.event_staff_id, s.role_name, e.name, cd.event_start
             FROM {$p}event_staff s
             JOIN {$p}event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id
             LEFT JOIN {$p}event e ON e.event_id = cd.event_id
             WHERE s.mundane_id = {$mundaneId} AND " . self::EVENT_END_SQL . " > NOW()
             ORDER BY s.event_staff_id"
        ) as $r) {
            $role = trim((string) $r['role_name']) !== '' ? trim((string) $r['role_name']) : 'Staff';
            $add('event_staff', $role . ' at ' . self::onDate((string) $r['name'], (string) $r['event_start']), $r['event_staff_id']);
        }
        foreach (self::rows(
            "SELECT l.event_schedule_lead_id, sc.title, e.name, cd.event_start
             FROM {$p}event_schedule_lead l
             JOIN {$p}event_schedule sc ON sc.event_schedule_id = l.event_schedule_id
             JOIN {$p}event_calendardetail cd ON cd.event_calendardetail_id = sc.event_calendardetail_id
             LEFT JOIN {$p}event e ON e.event_id = cd.event_id
             WHERE l.mundane_id = {$mundaneId} AND " . self::EVENT_END_SQL . " > NOW()
             ORDER BY l.event_schedule_lead_id"
        ) as $r) {
            $add('schedule_lead', 'Lead of ' . $r['title'] . ' at ' . self::onDate((string) $r['name'], (string) $r['event_start']), $r['event_schedule_lead_id']);
        }
        foreach (self::seats($mundaneId) as $seat) {
            $add('officer', self::seatLabel($seat), $seat['officer_id']);
        }
        foreach (self::rows(
            "SELECT a.authorization_id, a.role, a.park_id, a.kingdom_id, a.event_id, a.unit_id,
                    k.name AS kingdom_name, pk.name AS park_name, e.name AS event_name, u.name AS unit_name
             FROM {$p}authorization a
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = a.kingdom_id
             LEFT JOIN {$p}park pk ON pk.park_id = a.park_id
             LEFT JOIN {$p}event e ON e.event_id = a.event_id
             LEFT JOIN {$p}unit u ON u.unit_id = a.unit_id
             LEFT JOIN {$p}officer o ON o.authorization_id = a.authorization_id AND o.mundane_id = a.mundane_id
                  AND o.role NOT IN ('Champion', 'GMR')
             WHERE a.mundane_id = {$mundaneId} AND o.officer_id IS NULL
             ORDER BY a.authorization_id"
        ) as $r) {
            $add('authorization', self::authorizationLabel($r), $r['authorization_id']);
        }
        foreach (self::rows(
            "SELECT pm.participant_mundane_id, t.name, t.date_time
             FROM {$p}participant_mundane pm
             JOIN {$p}participant pt ON pt.participant_id = pm.participant_id
             JOIN {$p}tournament t ON t.tournament_id = pt.tournament_id
             WHERE pm.mundane_id = {$mundaneId} AND t.date_time > NOW()
             ORDER BY pm.participant_mundane_id"
        ) as $r) {
            $add('tournament', 'Entry in ' . self::onDate((string) $r['name'], (string) $r['date_time']), $r['participant_mundane_id']);
        }
        // "Not finalized" is the test class.Court.php uses: status, not finalized_at.
        foreach (self::rows(
            "SELECT court_id, name, court_date
             FROM {$p}court
             WHERE recorder_mundane_id = {$mundaneId} AND status <> 'complete'
             ORDER BY court_id"
        ) as $r) {
            $add('court_recorder', 'Recorder of ' . self::onDate((string) $r['name'], (string) $r['court_date']), $r['court_id']);
        }
        // Open lines only: a 'given' line records who actually conferred the award, and so
        // does a 'staged' line unless its court is still to come.
        foreach (self::rows(
            "SELECT ca.court_award_id, c.name, c.court_date, m.persona
             FROM {$p}court_award ca
             JOIN {$p}court c ON c.court_id = ca.court_id
             LEFT JOIN {$p}mundane m ON m.mundane_id = ca.mundane_id
             WHERE ca.given_by_mundane_id = {$mundaneId} AND c.status <> 'complete'
               AND (ca.status IN ('planned', 'announced') OR (ca.status = 'staged' AND c.court_date > CURDATE()))
             ORDER BY ca.court_award_id"
        ) as $r) {
            $add('court_given_by', 'Giving an award to ' . $r['persona'] . ' at ' . self::onDate((string) $r['name'], (string) $r['court_date']), $r['court_award_id']);
        }
        foreach (self::rows(
            "SELECT q.qual_manager_id, k.name
             FROM {$p}qual_manager q
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = q.kingdom_id
             WHERE q.mundane_id = {$mundaneId}
             ORDER BY q.qual_manager_id"
        ) as $r) {
            $add('qual_manager', 'Qualification test manager for ' . $r['name'], $r['qual_manager_id']);
        }
        foreach (self::rows(
            "SELECT g.granter_id, k.name, c.name AS category_name
             FROM {$p}bonus_credit_granter g
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = g.kingdom_id
             LEFT JOIN {$p}bonus_credit_category c ON c.category_id = g.category_id
             WHERE g.mundane_id = {$mundaneId}
             ORDER BY g.granter_id"
        ) as $r) {
            $credit = trim((string) $r['category_name']) !== '' ? trim((string) $r['category_name']) : 'all credits';
            $add('bonus_credit_granter', 'Bonus credit granter for ' . $r['name'] . ' (' . $credit . ')', $r['granter_id']);
        }
        return $items;
    }

    /** "Name (date)", or just the name when there is no date. */
    private static function onDate(string $name, string $when): string
    {
        $day = substr($when, 0, 10);
        return $day !== '' ? $name . ' (' . $day . ')' : $name;
    }

    /** @param array<string, mixed> $r an authorization row with its scope's names */
    private static function authorizationLabel(array $r): string
    {
        foreach ([
            'unit_id'    => ['Unit', 'unit_name'],
            'event_id'   => ['Event', 'event_name'],
            'park_id'    => ['Park', 'park_name'],
            'kingdom_id' => ['Kingdom', 'kingdom_name'],
        ] as $column => [$scope, $name]) {
            if ((int) $r[$column] > 0) {
                return $scope . ' permission on ' . $r[$name] . ' (' . $r['role'] . ')';
            }
        }
        return $r['role'] === AUTH_ADMIN ? 'ORK admin' : 'ORK-wide permission (' . $r['role'] . ')';
    }

    /**
     * Every officer seat the player holds, with each row's own kingdom and park.
     *
     * @return list<array<string, mixed>>
     */
    private static function seats(int $mundaneId): array
    {
        $p = DB_PREFIX;
        return self::rows(
            "SELECT o.officer_id, o.kingdom_id, o.park_id, o.role, o.system, k.name AS kingdom_name, pk.name AS park_name
             FROM {$p}officer o
             LEFT JOIN {$p}kingdom k ON k.kingdom_id = o.kingdom_id
             LEFT JOIN {$p}park pk ON pk.park_id = o.park_id
             WHERE o.mundane_id = {$mundaneId}
             ORDER BY o.officer_id"
        );
    }

    /** @param array<string, mixed> $seat a row from seats() */
    private static function seatLabel(array $seat): string
    {
        return $seat['role'] . ' of ' . ((int) $seat['park_id'] > 0 ? $seat['park_name'] : $seat['kingdom_name']);
    }

    /**
     * Vacate the player's seats as Kingdom and Park::VacateOfficer do. set_officer
     * skips a seat without a live authorization row and says nothing, so the
     * direct write after it makes sure the seat is empty either way.
     */
    private static function vacateSeats(int $uid, int $mundaneId): void
    {
        $common = new Common();
        foreach (self::seats($mundaneId) as $seat) {
            $kingdomId = (int) $seat['kingdom_id'];
            $parkId    = (int) $seat['park_id'];
            $role      = (string) $seat['role'];
            $common->set_officer($kingdomId, $parkId, 0, $role, (int) $seat['system']);
            self::write('UPDATE ' . DB_PREFIX . 'officer SET mundane_id = 0 WHERE officer_id = ' . (int) $seat['officer_id'] . ' AND mundane_id = ' . $mundaneId);
            Ork3::$Lib->dangeraudit->audit(
                __CLASS__ . '::convert',
                ['by_whom_id' => $uid, 'MundaneId' => $mundaneId, 'KingdomId' => $kingdomId, 'ParkId' => $parkId, 'Role' => $role],
                $parkId > 0 ? 'Park' : 'Kingdom',
                $parkId > 0 ? $parkId : $kingdomId,
                ['MundaneId' => $mundaneId, 'Role' => $role],
                ['KingdomId' => $kingdomId, 'ParkId' => $parkId, 'Role' => $role]
            );
        }
    }

    /** Clear the recorder and giver seats named in $items. The award lines themselves stay. */
    private static function clearCourtRoles(array $items): void
    {
        $courts = self::idsOf($items, 'court_recorder');
        if ($courts) {
            self::write('UPDATE ' . DB_PREFIX . 'court SET recorder_mundane_id = NULL WHERE court_id IN (' . implode(',', $courts) . ')');
        }
        $lines = self::idsOf($items, 'court_given_by');
        if ($lines) {
            self::write('UPDATE ' . DB_PREFIX . 'court_award SET given_by_mundane_id = NULL, row_version = row_version + 1 WHERE court_award_id IN (' . implode(',', $lines) . ')');
        }
    }

    /**
     * @param list<array{Type: string, Label: string, Id: int}> $cleared
     * @param list<string> $warnings
     */
    private static function log(int $mundaneId, string $action, int $year, int $priorActive, array $cleared, int $uid, array $warnings = []): void
    {
        global $DB;
        // Plain ASCII \u escapes: the connection is utf8 and the snapshot must survive it whole.
        $json         = json_encode($cleared, JSON_INVALID_UTF8_SUBSTITUTE);
        $warningsJson = json_encode($warnings, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false || $warningsJson === false) {
            throw new RuntimeException('snapshot would not encode');
        }
        $DB->Clear();
        $DB->cleared_json = $json;
        $DB->warnings_json = $warningsJson;
        $ok = $DB->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . "legend_log (mundane_id, action, legend_year, prior_active, cleared_json, warnings_json, actor_id, created)
             VALUES ({$mundaneId}, '{$action}', {$year}, {$priorActive}, :cleared_json, :warnings_json, {$uid}, NOW())"
        );
        $DB->Clear();
        if (!$ok) {
            throw new RuntimeException('log write failed');
        }
    }

    /** Write the audit row, throwing if it did not land: a Yapo save reports no failure, and an unaudited change must roll back. */
    private static function audit(string $action, int $uid, int $mundaneId, array $prior, array $post): void
    {
        $call   = __CLASS__ . '::' . $action;
        $latest = "SELECT COALESCE(MAX(danger_audit_id), 0) AS id FROM " . DB_PREFIX . "danger_audit WHERE method_call = '{$call}'";
        $before = (int) self::rows($latest)[0]['id'];
        Ork3::$Lib->dangeraudit->audit($call, ['by_whom_id' => $uid, 'MundaneId' => $mundaneId], 'Player', $mundaneId, $prior, $post);
        if ((int) self::rows($latest)[0]['id'] <= $before) {
            throw new RuntimeException('audit write failed: ' . $call);
        }
    }

    /**
     * The units this player is the last manager of. A Legend can't manage, so
     * another Legend doesn't count.
     *
     * @return list<string> unit names
     */
    private static function unitsLosingLastManager(int $mundaneId): array
    {
        $p = DB_PREFIX;
        return array_column(self::rows(
            "SELECT DISTINCT u.name
             FROM {$p}authorization a
             JOIN {$p}unit u ON u.unit_id = a.unit_id
             WHERE a.mundane_id = {$mundaneId} AND a.unit_id > 0
               AND NOT EXISTS (
                   SELECT 1 FROM {$p}authorization other
                   JOIN {$p}mundane m ON m.mundane_id = other.mundane_id
                   WHERE other.unit_id = a.unit_id AND other.mundane_id <> {$mundaneId} AND m.is_legend = 0
               )
             ORDER BY u.name"
        ), 'name');
    }
}
