<?php

/**
 * Legend: the In Memoriam status of a deceased player.
 *
 * A Legend is an ork_mundane row with is_legend = 1 and the year they passed
 * in legend_year. The record and its history stay; the page becomes a
 * memorial and the account is closed to edits by anyone but the people who may
 * manage the status: an officer of the player's kingdom (or of a kingdom above
 * it, for a principality player) or an ORK admin. This class holds the status
 * checks every guard shares; it is deliberately small and static-friendly so a
 * guard in any layer can ask "is this player a Legend, and may this caller
 * edit them anyway?" in one call.
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
 * Not registered on Ork3::$Lib on purpose: the JSON gateway exposes every
 * public method of a $Lib class, and these take a caller uid, not a token.
 * Callers reach it through Model_Legend.
 */
class Legend
{
    /** The one refusal message every guard uses. */
    public const REFUSED = 'This player is a Legend and can no longer be changed this way.';

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

    /** A form field as a year: its value if it is a whole four-digit number, else 0, which every year check refuses. */
    public static function parseYear($raw): int
    {
        return is_string($raw) && preg_match('/^\s*(\d{4})\s*$/', $raw, $m) ? (int) $m[1] : 0;
    }

    /**
     * Everything a conversion would clear, for the officer to confirm. Writes
     * nothing.
     *
     * @return array{Status: int, Error?: string, Items?: list<array{Type: string, Label: string, Id: int}>, Warnings?: list<string>}
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
        $warnings = [];
        foreach (self::seats($mundaneId) as $seat) {
            $warnings[] = self::seatLabel($seat) . ' will become vacant.';
        }
        foreach (self::unitsLosingLastManager($mundaneId) as $unitName) {
            $warnings[] = $unitName . ' will have no manager.';
        }
        return ['Status' => 0, 'Items' => self::clearable($mundaneId), 'Warnings' => $warnings];
    }

    /**
     * Mark a player a Legend. In one transaction: clear their future roles,
     * set the status, log what was cleared, and end every login they hold. The
     * clearing goes straight to the tables, ahead of the status change, so no
     * guard on a Legend can stop a conversion's own writes.
     *
     * @return array{Status: int, Error?: string, Cleared?: list<array{Type: string, Label: string, Id: int}>}
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

            // Seats first: set_officer finds the seat's authorization row, which the delete below would take away.
            self::vacateSeats($uid, $mundaneId);
            self::deleteItems($items, ...array_keys(self::DELETED_BY_ID));
            self::write('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . $mundaneId);
            self::clearCourtRoles($items);

            self::write('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 1, legend_year = ' . $year . ', active = 0 WHERE mundane_id = ' . $mundaneId);
            self::log($mundaneId, 'convert', $year, $priorActive, $items, $uid);
            self::audit('convert', $uid, $mundaneId, ['IsLegend' => 0, 'Active' => $priorActive], ['IsLegend' => 1, 'Active' => 0, 'LegendYear' => $year, 'ClearedCount' => count($items)]);

            // The MyISAM writes, last (see the class comment).
            self::purgeSessions($mundaneId);
            self::write(self::deleteSql('participant_mundane', 'participant_mundane_id', self::idsOf($items, 'tournament')));

            return ['Status' => 0, 'Cleared' => $items];
        });
    }

    /**
     * Remove Legend status. The year stays in the log only, the active flag
     * goes back to what it was, memories stay on the table (hidden while the
     * player is not a Legend), and nothing cleared returns: the caller gets the
     * last conversion's snapshot to restore by hand.
     *
     * @return array{Status: int, Error?: string, Cleared?: list<array{Type: string, Label: string, Id: int}>}
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
            $last = self::rows(
                'SELECT prior_active, cleared_json FROM ' . DB_PREFIX . 'legend_log
                 WHERE mundane_id = ' . $mundaneId . " AND action = 'convert'
                 ORDER BY legend_log_id DESC LIMIT 1"
            );
            // A Legend with no conversion on the log stays inactive: reactivating is one edit away, a wrong guess isn't.
            $priorActive = $last ? (int) $last[0]['prior_active'] : 0;
            $cleared     = $last ? (json_decode((string) $last[0]['cleared_json'], true) ?: []) : [];
            $year        = (int) $player['legend_year'];

            self::write('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = 0, legend_year = 0, active = ' . $priorActive . ' WHERE mundane_id = ' . $mundaneId);
            self::log($mundaneId, 'undo', $year, $priorActive, [], $uid);
            self::audit('undo', $uid, $mundaneId, ['IsLegend' => 1, 'Active' => (int) $player['active'], 'LegendYear' => $year], ['IsLegend' => 0, 'Active' => $priorActive]);

            return ['Status' => 0, 'Cleared' => $cleared];
        });
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
        return $refused;
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
     * to restore it by hand. A seat's own authorization row isn't listed: it
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
             WHERE r.mundane_id = {$mundaneId} AND cd.event_start > NOW()
             ORDER BY r.rsvp_id"
        ) as $r) {
            $add('rsvp', 'RSVP for ' . self::onDate((string) $r['name'], (string) $r['event_start']), $r['rsvp_id']);
        }
        foreach (self::rows(
            "SELECT s.event_staff_id, s.role_name, e.name, cd.event_start
             FROM {$p}event_staff s
             JOIN {$p}event_calendardetail cd ON cd.event_calendardetail_id = s.event_calendardetail_id
             LEFT JOIN {$p}event e ON e.event_id = cd.event_id
             WHERE s.mundane_id = {$mundaneId} AND cd.event_start > NOW()
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
             WHERE l.mundane_id = {$mundaneId} AND cd.event_start > NOW()
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
        // Open lines only: a 'given' line records who actually conferred the award.
        foreach (self::rows(
            "SELECT ca.court_award_id, c.name, c.court_date, m.persona
             FROM {$p}court_award ca
             JOIN {$p}court c ON c.court_id = ca.court_id
             LEFT JOIN {$p}mundane m ON m.mundane_id = ca.mundane_id
             WHERE ca.given_by_mundane_id = {$mundaneId} AND c.status <> 'complete'
               AND ca.status IN ('planned', 'announced', 'staged')
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

    /** @param list<array{Type: string, Label: string, Id: int}> $cleared */
    private static function log(int $mundaneId, string $action, int $year, int $priorActive, array $cleared, int $uid): void
    {
        global $DB;
        // Plain ASCII \u escapes: the connection is utf8 and the snapshot must survive it whole.
        $json = json_encode($cleared, JSON_INVALID_UTF8_SUBSTITUTE);
        if ($json === false) {
            throw new RuntimeException('snapshot would not encode');
        }
        $DB->Clear();
        $DB->cleared_json = $json;
        $ok = $DB->ExecuteChecked(
            'INSERT INTO ' . DB_PREFIX . "legend_log (mundane_id, action, legend_year, prior_active, cleared_json, actor_id, created)
             VALUES ({$mundaneId}, '{$action}', {$year}, {$priorActive}, :cleared_json, {$uid}, NOW())"
        );
        $DB->Clear();
        if (!$ok) {
            throw new RuntimeException('log write failed');
        }
    }

    private static function audit(string $action, int $uid, int $mundaneId, array $prior, array $post): void
    {
        Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . $action, ['by_whom_id' => $uid, 'MundaneId' => $mundaneId], 'Player', $mundaneId, $prior, $post);
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
