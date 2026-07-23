<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for player profile/AJAX characterization tests (T-09).
 */
final class PlayerProfileFixture
{
    private const MARKER = 'T09PLR';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $noteIds = [];

    /** @var list<int> */
    private array $awardIds = [];

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

    public function firstParkId(): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' ORDER BY park_id ASC LIMIT 1"
        )->fetchColumn();
    }

    public function secondParkId(int $excludeParkId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT park_id FROM ' . DB_PREFIX . "park WHERE active = 'Active' AND park_id != ? ORDER BY park_id ASC LIMIT 1"
        );
        $stmt->execute([$excludeParkId]);

        return (int) $stmt->fetchColumn();
    }

    public function kingdomIdForPark(int $parkId): int
    {
        $stmt = $this->pdo->prepare('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, park_id: int, kingdom_id: int, username: string, token: string}
     */
    public function createPlayer(int $parkId, string $suffix = 'player'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token, $username);

        return [
            'mundane_id' => $mundaneId,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
            'username' => $username,
            'token' => $token,
        ];
    }

    public function insertNote(int $mundaneId, string $body = 'Imported note'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane_note
             (mundane_id, note, description, given_by, date, date_complete)
             VALUES (?, ?, ?, ?, CURDATE(), CURDATE())'
        );
        $stmt->execute([$mundaneId, $body, $body, 'T09 fixture']);
        $id = (int) $this->pdo->lastInsertId();
        $this->noteIds[] = $id;

        return $id;
    }

    public function insertScopedAuth(int $mundaneId, int $parkId, int $kingdomId, string $role = 'admin'): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
             VALUES (?, ?, ?, 0, 0, ?, NOW())'
        );
        $stmt->execute([$mundaneId, $parkId, $kingdomId, $role]);
        $id = (int) $this->pdo->lastInsertId();
        $this->authIds[] = $id;

        return $id;
    }

    /**
     * Insert a revoked award row already stripped onto stripped_from.
     *
     * @return int awards_id
     */
    public function insertRevokedAward(
        int $strippedFromMundaneId,
        int $awardId,
        int $aliasAwardId = 0,
        int $kingdomAwardId = 0,
        int $rank = 1,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'awards
             (kingdomaward_id, mundane_id, stripped_from, unit_id, park_id, kingdom_id, team_id, rank, date,
              given_by_id, note, at_park_id, at_kingdom_id, at_event_id, custom_name, alias_award_id,
              award_id, by_whom_id, entered_at, revoked, revoked_at, revocation, revoked_by_id)
             VALUES (?, 0, ?, 0, 0, 0, 0, ?, CURDATE(), 0, \'\', 0, 0, 0, \'\', ?,
                     ?, 0, NOW(), 1, NOW(), \'C18 fixture revoke\', 0)'
        );
        $stmt->execute([
            $kingdomAwardId,
            $strippedFromMundaneId,
            $rank,
            $aliasAwardId > 0 ? $aliasAwardId : null,
            $awardId,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->awardIds[] = $id;

        return $id;
    }

    public function ladderAwardId(): int
    {
        return (int) $this->pdo->query(
            'SELECT award_id FROM ' . DB_PREFIX . "award
             WHERE IFNULL(is_title, 0) = 0 AND (officer_role = 'none' OR officer_role IS NULL)
             ORDER BY award_id ASC LIMIT 1"
        )->fetchColumn();
    }

    public function titleAliasAwardId(): int
    {
        return (int) $this->pdo->query(
            'SELECT award_id FROM ' . DB_PREFIX . 'award
             WHERE IFNULL(is_title, 0) = 1
             ORDER BY award_id ASC LIMIT 1'
        )->fetchColumn();
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

    /**
     * @return array<int, int>
     */
    public function fetchReconcileAwardMap(int $kingdomId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT kingdomaward_id, award_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdom_id = ? AND is_title = 0'
        );
        $stmt->execute([$kingdomId]);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['award_id']] = (int) $row['kingdomaward_id'];
        }

        return $map;
    }

    public function deleteMilestone(int $milestoneId): void
    {
        $this->pdo->exec(
            'DELETE FROM ' . DB_PREFIX . 'player_milestones WHERE milestone_id = ' . (int) $milestoneId,
        );
    }

    public function cleanup(): void
    {
        foreach ($this->noteIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane_note WHERE mundane_note_id = ' . (int) $id);
        }

        foreach ($this->awardIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int) $id);
        }

        foreach ($this->authIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ' . (int) $id);
        }

        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
        }
    }

    private function insertMundane(
        string $suffix,
        int $parkId,
        int $kingdomId,
        string $token,
        string $username,
    ): int {
        $persona = self::MARKER . ' ' . $suffix;
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
            'Test',
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
