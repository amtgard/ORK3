<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for reports/voting/awards characterization tests (T-10).
 */
final class ReportsFixture
{
    private const MARKER = 'T10RPT';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $attendanceIds = [];

    /** @var list<int> */
    private array $awardIds = [];

    /** @var list<int> */
    private array $duesIds = [];

    /** @var list<int> */
    private array $officerIds = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public static function create(): self
    {
        $pdo = new PDO(
            sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8',
                DB_HOSTNAME,
                DB_PORT,
                DB_DATABASE,
            ),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );

        return new self($pdo);
    }

    public function firstKingdomId(): int
    {
        return (int) $this->pdo->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE active = 'Active' ORDER BY kingdom_id ASC LIMIT 1"
        )->fetchColumn();
    }

    public function kingdomWithLadderAwards(): int
    {
        $kid = (int) $this->pdo->query(
            'SELECT ka.kingdom_id FROM ' . DB_PREFIX . 'kingdomaward ka
             INNER JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE a.is_ladder = 1 AND a.award_id != 31
             ORDER BY ka.kingdom_id ASC LIMIT 1'
        )->fetchColumn();

        return $kid > 0 ? $kid : $this->firstKingdomId();
    }

    public function parkIdInKingdom(int $kingdomId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE kingdom_id = ? AND active = 'Active' ORDER BY park_id ASC LIMIT 1"
        );
        $stmt->execute([$kingdomId]);

        return (int) $stmt->fetchColumn();
    }

    public function secondParkIdInKingdom(int $kingdomId, int $excludeParkId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE kingdom_id = ? AND active = 'Active' AND park_id != ? ORDER BY park_id ASC LIMIT 1"
        );
        $stmt->execute([$kingdomId, $excludeParkId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, park_id: int, kingdom_id: int}
     */
    public function createPlayer(int $parkId, string $suffix = 'player'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token, $username);

        return ['mundane_id' => $mundaneId, 'park_id' => $parkId, 'kingdom_id' => $kingdomId];
    }

    public function insertAttendance(int $mundaneId, int $parkId, int $kingdomId, string $date): int
    {
        $classId = (int) $this->pdo->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id ASC LIMIT 1'
        )->fetchColumn();
        if ($classId <= 0) {
            throw new RuntimeException('No class row in seed data.');
        }

        $timestamp = strtotime($date);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance
             (park_id, kingdom_id, mundane_id, persona, class_id, date, credits, note, flavor, by_whom_id,
              entered_at, entry_method, event_id, event_calendardetail_id, date_year, date_month, date_week3, date_week6)
             VALUES (?, ?, ?, ?, ?, ?, 1, \'\', \'\', ?, NOW(), \'manual\', 0, 0, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $parkId,
            $kingdomId,
            $mundaneId,
            self::MARKER . ' attendance',
            $classId,
            $date,
            $mundaneId,
            (int) date('Y', $timestamp),
            (int) date('n', $timestamp),
            (int) date('W', $timestamp),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->attendanceIds[] = $id;

        return $id;
    }

    /**
     * Prepare a player so voting eligibility base checks can pass.
     */
    public function makeVotingReady(int $mundaneId, string $memberSince = '2020-01-01'): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'mundane
             SET waivered = 1, active = 1, suspended = 0, park_member_since = ?
             WHERE mundane_id = ?'
        );
        $stmt->execute([$memberSince, $mundaneId]);
    }

    public function insertDues(int $mundaneId, int $parkId, int $kingdomId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'dues
             (mundane_id, kingdom_id, park_id, created_on, created_by, dues_from, terms, dues_until,
              dues_for_life, revoked)
             VALUES (?, ?, ?, CURDATE(), ?, CURDATE(), 6, DATE_ADD(CURDATE(), INTERVAL 1 YEAR), 0, 0)'
        );
        $stmt->execute([$mundaneId, $kingdomId, $parkId, $mundaneId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->duesIds[] = $id;

        return $id;
    }

    /**
     * @return array{kingdomaward_id: int, award_id: int, name: string}
     */
    public function firstLadderAward(int $kingdomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT ka.kingdomaward_id, ka.award_id, IFNULL(ka.name, a.name) AS name
             FROM ' . DB_PREFIX . 'kingdomaward ka
             INNER JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
             WHERE ka.kingdom_id = ? AND a.is_ladder = 1 AND a.award_id != 31
             ORDER BY ka.award_id ASC LIMIT 1'
        );
        $stmt->execute([$kingdomId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new RuntimeException('No ladder award for kingdom ' . $kingdomId);
        }

        return [
            'kingdomaward_id' => (int) $row['kingdomaward_id'],
            'award_id' => (int) $row['award_id'],
            'name' => (string) $row['name'],
        ];
    }

    public function insertLadderAward(int $mundaneId, int $parkId, int $kingdomId, int $kingdomAwardId, int $awardId, int $rank = 3): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'awards
             (kingdomaward_id, mundane_id, stripped_from, unit_id, park_id, kingdom_id, team_id, rank, date,
              given_by_id, note, at_park_id, at_kingdom_id, at_event_id, custom_name, alias_award_id,
              award_id, by_whom_id, entered_at, revoked)
             VALUES (?, ?, 0, 0, ?, ?, 0, ?, CURDATE(), ?, \'\', 0, 0, 0, \'\', 0, ?, ?, NOW(), 0)'
        );
        $stmt->execute([
            $kingdomAwardId,
            $mundaneId,
            $parkId,
            $kingdomId,
            $rank,
            $mundaneId,
            $awardId,
            $mundaneId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardIds[] = $id;

        return $id;
    }

    public function insertParkOfficer(int $kingdomId, int $parkId, int $mundaneId, string $role): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'officer
             (kingdom_id, park_id, mundane_id, role, system, authorization_id)
             VALUES (?, ?, ?, ?, 0, 0)'
        );
        $stmt->execute([$kingdomId, $parkId, $mundaneId, $role]);
        $id = (int) $this->pdo->lastInsertId();
        $this->officerIds[] = $id;

        return $id;
    }

    public function kingdomName(int $kingdomId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ?');
        $stmt->execute([$kingdomId]);

        return (string) $stmt->fetchColumn();
    }

    public function parkName(int $parkId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (string) $stmt->fetchColumn();
    }

    public function cleanup(): void
    {
        if ($this->officerIds !== []) {
            $in = implode(',', array_map('intval', $this->officerIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "officer WHERE officer_id IN ({$in})");
            $this->officerIds = [];
        }

        if ($this->awardIds !== []) {
            $in = implode(',', array_map('intval', $this->awardIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "awards WHERE awards_id IN ({$in})");
            $this->awardIds = [];
        }

        if ($this->duesIds !== []) {
            $in = implode(',', array_map('intval', $this->duesIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "dues WHERE dues_id IN ({$in})");
            $this->duesIds = [];
        }

        if ($this->attendanceIds !== []) {
            $in = implode(',', array_map('intval', $this->attendanceIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "attendance WHERE attendance_id IN ({$in})");
            $this->attendanceIds = [];
        }

        if ($this->mundaneIds !== []) {
            $in = implode(',', array_map('intval', $this->mundaneIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "dues WHERE mundane_id IN ({$in})");
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "awards WHERE mundane_id IN ({$in})");
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "officer WHERE mundane_id IN ({$in})");
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "mundane WHERE mundane_id IN ({$in})");
            $this->mundaneIds = [];
        }
    }

    private function kingdomIdForPark(int $parkId): int
    {
        $stmt = $this->pdo->prepare('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (int) $stmt->fetchColumn();
    }

    private function insertMundane(
        string $suffix,
        int $parkId,
        int $kingdomId,
        string $token,
        string $username,
    ): int {
        $persona = self::MARKER . ' ' . ucfirst($suffix);
        $templateId = (int) $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        )->fetchColumn();

        $clone = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane
             (given_name, surname, other_name, pronoun_id, pronoun_custom, username, persona, email,
              park_id, kingdom_id, token, restricted, waivered, waiver_ext, has_heraldry, has_banner,
              banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y, has_image, company_id,
              token_expires, password_expires, password_salt, xtoken, penalty_box, active, suspended,
              suspended_by_id, suspended_at, suspended_until, suspension, suspension_propagates,
              reeve_qualified, reeve_qualified_until, corpora_qualified, corpora_qualified_until,
              park_member_since, milestone_config, basic_fonts, dyslexia_fonts)
             SELECT ?, ?, other_name, pronoun_id, pronoun_custom, ?, ?, ?,
                    ?, ?, ?, restricted, waivered, waiver_ext, has_heraldry, has_banner,
                    banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y, has_image, company_id,
                    token_expires, password_expires, password_salt, xtoken, penalty_box, active, suspended,
                    suspended_by_id, suspended_at, suspended_until, suspension, suspension_propagates,
                    reeve_qualified, reeve_qualified_until, corpora_qualified, corpora_qualified_until,
                    park_member_since, milestone_config, basic_fonts, dyslexia_fonts
             FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?'
        );
        $clone->execute([
            'T10',
            $suffix,
            $username,
            $persona,
            $username . '@example.test',
            $parkId,
            $kingdomId,
            $token,
            $templateId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->mundaneIds[] = $id;

        return $id;
    }
}
