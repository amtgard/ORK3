<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for the Legend (In Memoriam) status tests. Wraps
 * AttendanceFixture, which clones a template player and cleans up the mundane,
 * authorization and session rows it created; this adds the officers and the
 * Legend tables.
 */
final class LegendFixture
{
    /** The tables a conversion writes to, for checksums(). */
    private const WRITTEN_TABLES = [
        'mundane', 'legend_log', 'event_rsvp', 'event_staff', 'event_schedule_lead', 'officer', 'authorization',
        'participant_mundane', 'court', 'court_award', 'qual_manager', 'bonus_credit_granter', 'session',
        'application_auth', 'danger_audit',
    ];

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    /** @var list<int> */
    private array $unitIds = [];

    /** @var list<int> */
    private array $courtIds = [];

    /** @var list<int> */
    private array $tournamentIds = [];

    /** @var list<int> */
    private array $seatIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var array<int, array{mundane_id: int, authorization_id: int}> officer rows to put back, by officer_id */
    private array $borrowedSeats = [];

    public function __construct(
        private readonly AttendanceFixture $base,
        private readonly PDO $pdo,
    ) {
    }

    public static function create(): self
    {
        $base = AttendanceFixture::create();
        $r = new ReflectionProperty(AttendanceFixture::class, 'pdo');
        $r->setAccessible(true);

        return new self($base, $r->getValue($base));
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function firstParkId(): int
    {
        return $this->base->firstParkId();
    }

    public function kingdomIdForPark(int $parkId): int
    {
        return $this->base->kingdomIdForPark($parkId);
    }

    /** A park in a kingdom other than $kingdomId (0 when none). */
    public function parkOutsideKingdom(int $kingdomId): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND kingdom_id <> " . (int) $kingdomId
            . ' AND kingdom_id > 0 ORDER BY park_id LIMIT 1'
        )->fetchColumn();
    }

    /**
     * A plain player at a park. $legendYear > 0 makes them a Legend.
     *
     * @return array{mundane_id: int, park_id: int, kingdom_id: int}
     */
    public function addPlayer(int $parkId, string $suffix = 'player', int $legendYear = 0): array
    {
        $p = $this->base->createPlayer($parkId, $suffix);
        $this->mundaneIds[] = (int) $p['mundane_id'];
        if ($legendYear > 0) {
            $this->setLegend((int) $p['mundane_id'], $legendYear);
        }

        return [
            'mundane_id' => (int) $p['mundane_id'],
            'park_id' => (int) $p['park_id'],
            'kingdom_id' => (int) $p['kingdom_id'],
        ];
    }

    /** A player with EDIT (or $role) on a kingdom, homed at $homeParkId. Returns the mundane id. */
    public function addKingdomOfficer(int $kingdomId, int $homeParkId, string $suffix = 'kofficer', string $role = AUTH_EDIT): int
    {
        $uid = $this->addPlayer($homeParkId, $suffix)['mundane_id'];
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, 0, ?, 0, 0, ?)'
        )->execute([$uid, $kingdomId, $role]);

        return $uid;
    }

    /** A player with EDIT on one park only. Returns the mundane id. */
    public function addParkOfficer(int $parkId, string $suffix = 'pofficer'): int
    {
        return (int) $this->base->createParkOfficer($parkId, $suffix)['mundane_id'];
    }

    /** A global ORK admin (unscoped admin row). Returns the mundane id. */
    public function addOrkAdmin(int $homeParkId, string $suffix = 'admin'): int
    {
        $uid = $this->addPlayer($homeParkId, $suffix)['mundane_id'];
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, 0, 0, 0, 0, ?)'
        )->execute([$uid, AUTH_ADMIN]);

        return $uid;
    }

    /** A published event whose only occurrence starts in two days. */
    public function futureEvent(int $parkId, string $suffix = 'future'): int
    {
        $detailId = $this->base->createPublishedEvent($parkId, $suffix)['detail_id'];
        $this->detailIds[] = $detailId;

        return $detailId;
    }

    /** A published event whose only occurrence started a month ago. */
    public function pastEvent(int $parkId, string $suffix = 'past'): int
    {
        $detailId = $this->futureEvent($parkId, $suffix);
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'event_calendardetail
             SET event_start = NOW() - INTERVAL 30 DAY, event_end = NOW() - INTERVAL 29 DAY
             WHERE event_calendardetail_id = ?'
        )->execute([$detailId]);

        return $detailId;
    }

    /** A published event that started yesterday and ends tomorrow: under way now. */
    public function runningEvent(int $parkId, string $suffix = 'running'): int
    {
        $detailId = $this->futureEvent($parkId, $suffix);
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'event_calendardetail
             SET event_start = NOW() - INTERVAL 1 DAY, event_end = NOW() + INTERVAL 1 DAY
             WHERE event_calendardetail_id = ?'
        )->execute([$detailId]);

        return $detailId;
    }

    /** A future occurrence stored with no real end: legacy rows carry a zero date. */
    public function futureEventWithZeroEnd(int $parkId, string $suffix = 'zeroend'): int
    {
        $detailId = $this->futureEvent($parkId, $suffix);
        $this->pdo->exec("SET SESSION sql_mode = ''");
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . "event_calendardetail SET event_end = '0000-00-00 00:00:00' WHERE event_calendardetail_id = ?"
        )->execute([$detailId]);

        return $detailId;
    }

    public function eventIdForDetail(int $detailId): int
    {
        return $this->scalar('SELECT event_id FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . $detailId);
    }

    public function addRsvp(int $detailId, int $mundaneId): int
    {
        return $this->insert('event_rsvp', ['event_calendardetail_id' => $detailId, 'mundane_id' => $mundaneId, 'status' => 'going']);
    }

    public function addEventStaff(int $detailId, int $mundaneId): int
    {
        return $this->insert('event_staff', ['event_calendardetail_id' => $detailId, 'mundane_id' => $mundaneId, 'role_name' => 'Marshal']);
    }

    /** A schedule item on the occurrence, led by $mundaneId. Returns the lead row's id. */
    public function addScheduleLead(int $detailId, int $mundaneId): int
    {
        $scheduleId = $this->insert('event_schedule', [
            'event_calendardetail_id' => $detailId,
            'title' => 'Legend fixture item',
            'start_time' => date('Y-m-d H:i:s'),
            'end_time' => date('Y-m-d H:i:s'),
        ]);

        return $this->insert('event_schedule_lead', ['event_schedule_id' => $scheduleId, 'mundane_id' => $mundaneId]);
    }

    /**
     * Seat $mundaneId as an officer. Reuses the sandbox's own row for the seat
     * when there is one, because set_officer finds a seat by kingdom, park and
     * role, and puts it back in cleanup. $linked gives the seat its own
     * authorization row, as a real seat has; the sandbox's rows have none.
     *
     * @return array{officer_id: int, authorization_id: int}
     */
    public function addSeat(int $kingdomId, int $parkId, string $role, int $mundaneId, bool $linked = true): array
    {
        $authId = 0;
        if ($linked) {
            $authId = $this->addAuthorization($mundaneId, ['park_id' => $parkId, 'kingdom_id' => $parkId > 0 ? 0 : $kingdomId], AUTH_CREATE);
        }
        $stmt = $this->pdo->prepare(
            'SELECT officer_id, mundane_id, authorization_id FROM ' . DB_PREFIX . 'officer WHERE kingdom_id = ? AND park_id = ? AND role = ?'
        );
        $stmt->execute([$kingdomId, $parkId, $role]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $officerId = (int) $row['officer_id'];
            $this->borrowedSeats[$officerId] ??= ['mundane_id' => (int) $row['mundane_id'], 'authorization_id' => (int) $row['authorization_id']];
            $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'officer SET mundane_id = ?, authorization_id = ? WHERE officer_id = ?')
                ->execute([$mundaneId, $authId, $officerId]);
        } else {
            $officerId = $this->insert('officer', [
                'kingdom_id' => $kingdomId,
                'park_id' => $parkId,
                'mundane_id' => $mundaneId,
                'role' => $role,
                'authorization_id' => $authId,
            ]);
            $this->seatIds[] = $officerId;
        }

        return ['officer_id' => $officerId, 'authorization_id' => $authId];
    }

    /** @param array<string, int> $scope any of park_id, kingdom_id, event_id, unit_id */
    public function addAuthorization(int $mundaneId, array $scope, string $role = AUTH_EDIT): int
    {
        $id = $this->insert('authorization', $scope + [
            'mundane_id' => $mundaneId,
            'park_id' => 0,
            'kingdom_id' => 0,
            'event_id' => 0,
            'unit_id' => 0,
            'role' => $role,
        ]);
        $this->authIds[] = $id;

        return $id;
    }

    public function addUnit(string $name = 'Legend Fixture Company'): int
    {
        $id = $this->insert('unit', ['type' => 'Company', 'name' => $name, 'description' => '', 'history' => '', 'url' => '']);
        $this->unitIds[] = $id;

        return $id;
    }

    /** A unit member; with $manager they also hold the unit's authorization row. Returns the membership id. */
    public function addUnitMember(int $unitId, int $mundaneId, bool $manager = false): int
    {
        if ($manager) {
            $this->addAuthorization($mundaneId, ['unit_id' => $unitId], AUTH_CREATE);
        }

        return $this->insert('unit_mundane', ['unit_id' => $unitId, 'mundane_id' => $mundaneId, 'role' => 'member', 'title' => '']);
    }

    public function addDues(int $mundaneId, int $kingdomId, int $parkId): int
    {
        return $this->insert('dues', [
            'mundane_id' => $mundaneId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
            'created_by' => $mundaneId,
            'terms' => 1,
            'dues_from' => date('Y-m-d'),
            'dues_until' => date('Y-m-d', strtotime('+6 months')),
        ]);
    }

    public function addAttendance(int $parkId, int $kingdomId, int $mundaneId): int
    {
        return $this->base->insertParkAttendance($parkId, $kingdomId, $mundaneId, date('Y-m-d'), $this->base->firstClassId());
    }

    public function addCourt(int $kingdomId, int $parkId, string $status = 'draft', int $recorderId = 0): int
    {
        $id = $this->insert('court', ['kingdom_id' => $kingdomId, 'park_id' => $parkId, 'name' => 'Legend Fixture Court', 'status' => $status]
            + ($recorderId > 0 ? ['recorder_mundane_id' => $recorderId] : []));
        $this->courtIds[] = $id;

        return $id;
    }

    public function addCourtLine(int $courtId, int $recipientId, int $givenById = 0, string $status = 'planned'): int
    {
        return $this->insert('court_award', ['court_id' => $courtId, 'mundane_id' => $recipientId, 'status' => $status]
            + ($givenById > 0 ? ['given_by_mundane_id' => $givenById] : []));
    }

    public function addCourtArtisan(int $courtAwardId, int $mundaneId): int
    {
        return $this->insert('court_award_artisan', ['court_award_id' => $courtAwardId, 'mundane_id' => $mundaneId, 'contribution' => 'Scroll']);
    }

    public function addTournament(int $kingdomId, int $parkId, string $when): int
    {
        $id = $this->insert('tournament', [
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
            'event_calendardetail_id' => 0,
            'event_id' => 0,
            'name' => 'Legend Fixture Open',
            'description' => '',
            'url' => '',
            'date_time' => $when,
        ]);
        $this->tournamentIds[] = $id;

        return $id;
    }

    /** Enter a player. Returns the participant_mundane id. */
    public function addTournamentEntry(int $tournamentId, int $mundaneId): int
    {
        $participantId = $this->insert('participant', [
            'tournament_id' => $tournamentId,
            'bracket_id' => 0,
            'alias' => 'Legend Fixture Entrant',
            'unit_id' => 0,
            'park_id' => 0,
            'kingdom_id' => 0,
        ]);

        return $this->insert('participant_mundane', [
            'participant_id' => $participantId,
            'mundane_id' => $mundaneId,
            'tournament_id' => $tournamentId,
            'bracket_id' => 0,
        ]);
    }

    public function addQualManager(int $kingdomId, int $mundaneId): int
    {
        return $this->insert('qual_manager', ['kingdom_id' => $kingdomId, 'mundane_id' => $mundaneId]);
    }

    public function addBonusCreditGranter(int $kingdomId, int $mundaneId, int $addedBy): int
    {
        return $this->insert('bonus_credit_granter', [
            'kingdom_id' => $kingdomId,
            'mundane_id' => $mundaneId,
            'category_id' => 0,
            'added_by' => $addedBy,
            'added_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** A live login row. Returns the token. */
    public function addSession(int $mundaneId): string
    {
        $token = substr(md5('legend' . bin2hex(random_bytes(8))), 0, 32);
        $this->insert('session', [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'created' => date('Y-m-d H:i:s'),
            'last_seen' => date('Y-m-d H:i:s'),
            'expires' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET token = ? WHERE mundane_id = ?')->execute([$token, $mundaneId]);

        return $token;
    }

    /** An approved third-party app holding a live token for the player. Returns the row's id. */
    public function addAppToken(int $mundaneId): int
    {
        return $this->insert('application_auth', [
            'application_id' => 0,
            'mundane_id' => $mundaneId,
            'approved' => 'approved',
            'appauthkey' => md5('legendapp' . bin2hex(random_bytes(8))),
            'token' => md5('legendtoken' . bin2hex(random_bytes(8))),
            'token_expires' => date('Y-m-d H:i:s', strtotime('+1 day')),
        ]);
    }

    public function addMemory(int $legendId, int $authorId): int
    {
        return $this->insert('legend_memory', [
            'mundane_id' => $legendId,
            'author_id' => $authorId,
            'body' => 'He taught me to throw.',
            'created' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Rows in an un-prefixed table matching a trusted WHERE clause. */
    public function count(string $table, string $where): int
    {
        return $this->scalar('SELECT COUNT(*) FROM ' . DB_PREFIX . $table . ' WHERE ' . $where);
    }

    /** One column of one row, by a trusted WHERE clause. */
    public function value(string $table, string $column, string $where): mixed
    {
        $v = $this->pdo->query('SELECT `' . $column . '` FROM ' . DB_PREFIX . $table . ' WHERE ' . $where . ' LIMIT 1')->fetchColumn();

        return $v === false ? null : $v;
    }

    /**
     * Every row of the tables conversion writes, reduced to a checksum, so a
     * read-only call can be proved to have written nothing to any of them.
     *
     * @return array<string, string>
     */
    public function checksums(): array
    {
        $sums = [];
        foreach (self::WRITTEN_TABLES as $table) {
            $row = $this->pdo->query('CHECKSUM TABLE ' . DB_PREFIX . $table)->fetch(PDO::FETCH_ASSOC);
            $sums[$table] = (string) $row['Checksum'];
        }

        return $sums;
    }

    public function setLegend(int $mundaneId, int $year, bool $isLegend = true): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET is_legend = ?, legend_year = ? WHERE mundane_id = ?')
            ->execute([$isLegend ? 1 : 0, $year, $mundaneId]);
        Legend::clearMemo();
    }

    public function cleanup(): void
    {
        foreach ($this->borrowedSeats as $officerId => $seat) {
            $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'officer SET mundane_id = ?, authorization_id = ? WHERE officer_id = ?')
                ->execute([$seat['mundane_id'], $seat['authorization_id'], $officerId]);
        }
        foreach ($this->seatIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'officer WHERE officer_id = ' . (int) $id);
        }
        foreach ($this->authIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ' . (int) $id);
        }
        foreach ($this->detailIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id IN (SELECT event_schedule_id FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . (int) $id . ')');
            foreach (['event_schedule', 'event_rsvp', 'event_staff'] as $table) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . $table . ' WHERE event_calendardetail_id = ' . (int) $id);
            }
        }
        foreach ($this->courtIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'court_award_artisan WHERE court_award_id IN (SELECT court_award_id FROM ' . DB_PREFIX . 'court_award WHERE court_id = ' . (int) $id . ')');
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'court_award WHERE court_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'court WHERE court_id = ' . (int) $id);
        }
        foreach ($this->tournamentIds as $id) {
            foreach (['participant_mundane', 'participant', 'tournament'] as $table) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . $table . ' WHERE tournament_id = ' . (int) $id);
            }
        }
        foreach ($this->unitIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'unit_mundane WHERE unit_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'unit WHERE unit_id = ' . (int) $id);
        }
        foreach ($this->mundaneIds as $id) {
            foreach (['legend_log', 'legend_memory', 'dues', 'qual_manager', 'bonus_credit_granter', 'application_auth'] as $table) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . $table . ' WHERE mundane_id = ' . (int) $id);
            }
        }
        $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "danger_audit WHERE method_call LIKE 'Legend::%'");
        $this->base->cleanup();
        $this->mundaneIds = $this->detailIds = $this->unitIds = $this->courtIds = $this->tournamentIds = [];
        $this->seatIds = $this->authIds = $this->borrowedSeats = [];
        Legend::clearMemo();
    }

    /** @param array<string, int|string> $row */
    private function insert(string $table, array $row): int
    {
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . $table . ' (`' . implode('`, `', array_keys($row)) . '`) VALUES ('
            . implode(', ', array_fill(0, count($row), '?')) . ')'
        )->execute(array_values($row));

        return (int) $this->pdo->lastInsertId();
    }

    private function scalar(string $sql): int
    {
        return (int) $this->pdo->query($sql)->fetchColumn();
    }
}
