<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for search characterization tests (T-11).
 */
final class SearchFixture
{
    private const MARKER = 'T11SRC';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $attendanceIds = [];

    /** @var list<int> */
    private array $unitIds = [];

    /** @var list<int> */
    private array $unitMundaneIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    public function __construct(
        private readonly PDO $pdo,
    ) {
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

    public function kingdomAbbreviation(int $kingdomId): string
    {
        $stmt = $this->pdo->prepare('SELECT abbreviation FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ?');
        $stmt->execute([$kingdomId]);

        return (string) $stmt->fetchColumn();
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

    public function parkAbbreviation(int $parkId): string
    {
        $stmt = $this->pdo->prepare('SELECT abbreviation FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (string) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, park_id: int, kingdom_id: int, token: string, username: string, persona: string}
     */
    public function createPlayer(int $parkId, string $suffix = 'player', ?string $persona = null): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $persona ??= self::MARKER . ' ' . ucfirst($suffix);
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token, $username, $persona);

        return [
            'mundane_id' => $mundaneId,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
            'token' => $token,
            'username' => $username,
            'persona' => $persona,
        ];
    }

    public function insertGlobalAdmin(int $mundaneId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
             VALUES (?, 0, 0, 0, 0, ?, NOW())'
        );
        $stmt->execute([$mundaneId, 'admin']);
        $id = (int) $this->pdo->lastInsertId();
        $this->authIds[] = $id;

        return $id;
    }

    public function setRestricted(int $mundaneId, bool $restricted = true): void
    {
        $stmt = $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET restricted = ? WHERE mundane_id = ?');
        $stmt->execute([$restricted ? 1 : 0, $mundaneId]);
    }

    /**
     * @return array{unit_id: int, name: string}
     */
    public function createUnit(string $suffix = 'unit'): array
    {
        $name = self::MARKER . ' ' . ucfirst($suffix);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "unit (name, type, active, has_heraldry, url, description, history)
             VALUES (?, 'Company', 'Active', 0, '', '', '')"
        );
        $stmt->execute([$name]);
        $unitId = (int) $this->pdo->lastInsertId();
        $this->unitIds[] = $unitId;

        return ['unit_id' => $unitId, 'name' => $name];
    }

    public function addPlayerToUnit(int $unitId, int $mundaneId): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "unit_mundane (unit_id, mundane_id, role, title, active)
             VALUES (?, ?, 'member', '', 'Active')"
        );
        $stmt->execute([$unitId, $mundaneId]);
        $this->unitMundaneIds[] = (int) $this->pdo->lastInsertId();
    }

    public function insertUnitAttendance(int $mundaneId, int $parkId, int $kingdomId): int
    {
        $templateId = (int) $this->pdo->query(
            'SELECT attendance_id FROM ' . DB_PREFIX . 'attendance ORDER BY attendance_id ASC LIMIT 1'
        )->fetchColumn();
        $date = date('Y-m-d', strtotime('-30 days'));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance
             (mundane_id, class_id, date, date_year, date_month, date_week3, date_week6,
              park_id, kingdom_id, event_id, event_calendardetail_id, credits, persona,
              flavor, note, by_whom_id, entry_method, entered_at)
             SELECT ?, class_id, ?, YEAR(?), MONTH(?), date_week3, date_week6,
                    ?, ?, event_id, event_calendardetail_id, credits, persona,
                    flavor, note, by_whom_id, entry_method, NOW()
             FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ?'
        );
        $stmt->execute([$mundaneId, $date, $date, $date, $parkId, $kingdomId, $templateId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->attendanceIds[] = $id;

        return $id;
    }

    /**
     * @return array{event_id: int, park_id: int, kingdom_id: int}
     */
    public function createEvent(int $parkId, string $suffix = 'evt'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $name = self::MARKER . ' Event ' . ucfirst($suffix);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . "event (name, park_id, kingdom_id, mundane_id, unit_id, status)
             VALUES (?, ?, ?, 1, 0, 'published')"
        );
        $stmt->execute([$name, $parkId, $kingdomId]);
        $eventId = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $eventId;

        return ['event_id' => $eventId, 'park_id' => $parkId, 'kingdom_id' => $kingdomId];
    }

    public function cleanup(): void
    {
        foreach ($this->attendanceIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $id);
        }
        foreach ($this->unitMundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'unit_mundane WHERE unit_mundane_id = ' . (int) $id);
        }
        foreach ($this->authIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ' . (int) $id);
        }
        foreach ($this->eventIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $id);
        }
        foreach ($this->unitIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'unit_mundane WHERE unit_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'unit WHERE unit_id = ' . (int) $id);
        }
        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
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
        string $persona,
    ): int {
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
            'T11',
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
