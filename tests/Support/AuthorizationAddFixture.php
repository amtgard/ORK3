<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for Authorization add characterization tests (T-02).
 *
 * Creates grantors with scoped auth rows, grantee players, and optional events;
 * tears down in reverse FK order.
 */
final class AuthorizationAddFixture
{
    private const MARKER = 'T02AUTH';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $eventIds = [];

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
        $id = (int) $this->pdo->query(
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom ORDER BY kingdom_id ASC LIMIT 1'
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException('No kingdom row available for authorization fixtures.');
        }

        return $id;
    }

    public function firstParkId(): int
    {
        $id = (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException('No park row available for authorization fixtures.');
        }

        return $id;
    }

    public function createGrantee(string $suffix = 'grantee'): int
    {
        return $this->insertMundane($suffix);
    }

    /**
     * Authenticated user with no authorization rows (for NoAuthorization cases).
     *
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithoutAuth(string $suffix = 'noauth'): array
    {
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, token: $token);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
        ];
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithAuth(string $type, int $scopeId, string $role, string $suffix = 'grantor'): array
    {
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, token: $token);
        $authId = $this->insertAuthorization($mundaneId, $type, $scopeId, $role);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'authorization_id' => $authId,
        ];
    }

    /**
     * @return array{event_id: int, kingdom_id: int, park_id: int}
     */
    public function createEvent(string $suffix = 'event'): array
    {
        $kingdomId = $this->firstKingdomId();
        $parkId = $this->firstParkId();
        $ownerId = $this->insertMundane($suffix . '-owner', $parkId, $kingdomId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event
             (kingdom_id, park_id, mundane_id, unit_id, name, status)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([
            $kingdomId,
            $parkId,
            $ownerId,
            self::MARKER . ' Event ' . $suffix,
            'published',
        ]);

        $eventId = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $eventId;

        return [
            'event_id' => $eventId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
        ];
    }

    public function fetchAuthorization(int $authorizationId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT authorization_id, mundane_id, kingdom_id, park_id, event_id, unit_id, role
             FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ?'
        );
        $stmt->execute([$authorizationId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $row;
    }

    public function trackAuthorizationId(int $authorizationId): void
    {
        if ($authorizationId > 0) {
            $this->authIds[] = $authorizationId;
        }
    }

    public function cleanup(): void
    {
        if ($this->authIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->authIds), '?'));
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id IN (' . $placeholders . ')'
            );
            $stmt->execute($this->authIds);
        }

        foreach (array_reverse($this->eventIds) as $eventId) {
            $stmt = $this->pdo->prepare('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ?');
            $stmt->execute([$eventId]);
        }

        foreach (array_reverse($this->mundaneIds) as $mundaneId) {
            $stmt = $this->pdo->prepare('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?');
            $stmt->execute([$mundaneId]);
        }

        $this->authIds = [];
        $this->eventIds = [];
        $this->mundaneIds = [];
    }

    private function insertAuthorization(int $mundaneId, string $type, int $scopeId, string $role): int
    {
        $kingdomId = 0;
        $parkId = 0;
        $eventId = 0;
        $unitId = 0;

        switch ($type) {
            case AUTH_ADMIN:
                break;
            case AUTH_KINGDOM:
                $kingdomId = $scopeId;
                break;
            case AUTH_PARK:
                $parkId = $scopeId;
                break;
            case AUTH_EVENT:
                $eventId = $scopeId;
                break;
            case AUTH_UNIT:
                $unitId = $scopeId;
                break;
            default:
                throw new InvalidArgumentException("Unsupported auth type: {$type}");
        }

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$mundaneId, $parkId, $kingdomId, $eventId, $unitId, $role]);

        $id = (int) $this->pdo->lastInsertId();
        $this->authIds[] = $id;

        return $id;
    }

    private function insertMundane(
        string $suffix,
        int $parkId = 0,
        int $kingdomId = 0,
        ?string $token = null,
    ): int {
        $token ??= md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $persona = self::MARKER . ' ' . $suffix;

        $stmt = $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        );
        $templateId = (int) $stmt->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No template mundane row available for authorization fixtures.');
        }

        if ($parkId <= 0 || $kingdomId <= 0) {
            $scope = $this->pdo->query(
                'SELECT park_id, kingdom_id FROM ' . DB_PREFIX . 'park ORDER BY park_id ASC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            $parkId = $parkId > 0 ? $parkId : (int) $scope['park_id'];
            $kingdomId = $kingdomId > 0 ? $kingdomId : (int) $scope['kingdom_id'];
        }

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
