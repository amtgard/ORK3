<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for infrastructure characterization tests (T-13).
 */
final class InfrastructureFixture
{
    private const MARKER = 'T13INF';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<string> */
    private array $whatsNewVersions = [];

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

    public function kingdomIdForPark(int $parkId): int
    {
        $stmt = $this->pdo->prepare('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (int) $stmt->fetchColumn();
    }

    public function parentKingdomId(int $kingdomId): int
    {
        $stmt = $this->pdo->prepare('SELECT parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ?');
        $stmt->execute([$kingdomId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, token: string, park_id: int, kingdom_id: int}
     */
    public function createPlayer(int $parkId, string $suffix = 'player'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
        ];
    }

    public function rotateToken(int $mundaneId, string $newToken): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET token = ? WHERE mundane_id = ?')
            ->execute([$newToken, $mundaneId]);
    }

    public function setFontPreferences(int $mundaneId, int $basic, int $dyslexia): void
    {
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'mundane SET basic_fonts = ?, dyslexia_fonts = ? WHERE mundane_id = ?'
        )->execute([$basic, $dyslexia, $mundaneId]);
    }

    /**
     * @return array{font_basic: int, font_dyslexia: int}
     */
    public function fetchFontPreferences(int $mundaneId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT basic_fonts, dyslexia_fonts FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?'
        );
        $stmt->execute([$mundaneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return [
            'font_basic' => (int) ($row['basic_fonts'] ?? 0),
            'font_dyslexia' => (int) ($row['dyslexia_fonts'] ?? 0),
        ];
    }

    /**
     * @return array{event_id: int, name: string, kingdom_id: int}
     */
    public function createPublishedEvent(int $parkId, string $suffix = 'legacy'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $owner = $this->createPlayer($parkId, $suffix . '-owner');
        $name = self::MARKER . ' Event ' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event
             (kingdom_id, park_id, mundane_id, unit_id, name, status)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$kingdomId, $parkId, $owner['mundane_id'], $name, 'published']);
        $eventId = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $eventId;

        return ['event_id' => $eventId, 'name' => $name, 'kingdom_id' => $kingdomId];
    }

    public function dismissWhatsNew(int $mundaneId, string $version): void
    {
        $version = preg_replace('/[^a-zA-Z0-9_\-]/', '', $version);
        $this->pdo->prepare(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'whats_new_seen (mundane_id, version) VALUES (?, ?)'
        )->execute([$mundaneId, $version]);
        $this->whatsNewVersions[] = $version;
    }

    public function hasSeenWhatsNew(int $mundaneId, string $version): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . DB_PREFIX . 'whats_new_seen WHERE mundane_id = ? AND version = ? LIMIT 1'
        );
        $stmt->execute([$mundaneId, $version]);

        return (bool) $stmt->fetchColumn();
    }

    public function cleanup(): void
    {
        foreach ($this->whatsNewVersions as $version) {
            $this->pdo->exec(
                'DELETE FROM ' . DB_PREFIX . "whats_new_seen WHERE version = '" . addslashes($version) . "'"
            );
        }

        foreach ($this->eventIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $id);
        }

        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'whats_new_seen WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
        }
    }

    private function insertMundane(string $suffix, int $parkId, int $kingdomId, string $token): int
    {
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
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
