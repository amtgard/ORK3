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
        $templateId = (int) $this->pdo->query(
            'SELECT attendance_id FROM ' . DB_PREFIX . 'attendance ORDER BY attendance_id ASC LIMIT 1'
        )->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No attendance template row in seed data.');
        }

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

    public function cleanup(): void
    {
        if ($this->attendanceIds !== []) {
            $in = implode(',', array_map('intval', $this->attendanceIds));
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . "attendance WHERE attendance_id IN ({$in})");
            $this->attendanceIds = [];
        }

        if ($this->mundaneIds !== []) {
            $in = implode(',', array_map('intval', $this->mundaneIds));
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
