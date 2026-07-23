<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for RSVP characterization tests (T-01).
 *
 * Creates kingdom-scoped event occurrences and test players; tears down in
 * reverse FK order. Uses existing kingdom/park/unit seed rows.
 */
final class EventRsvpFixture
{
    private const MARKER = 'T01RSVP';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var array<int, string> */
    private array $mundaneTokens = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $staffIds = [];

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

    /**
     * @return array{mundane_id: int, detail_id: int, event_id: int, kingdom_id: int, park_id: int, token: string}
     */
    public function createFutureOccurrence(string $suffix = 'future'): array
    {
        $kingdomId = 1;
        $parkId = 1;
        $mundaneId = $this->insertMundane($suffix);
        $eventId = $this->insertEvent($kingdomId, $parkId, $mundaneId, $suffix);
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+30 days')),
            date('Y-m-d H:i:s', strtotime('+30 days +6 hours')),
        );

        return [
            'mundane_id' => $mundaneId,
            'detail_id' => $detailId,
            'event_id' => $eventId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
            'token' => $this->mundaneTokens[$mundaneId] ?? '',
        ];
    }

    public function tokenFor(int $mundaneId): string
    {
        return $this->mundaneTokens[$mundaneId] ?? '';
    }

    /**
     * @return array{mundane_id: int, detail_id: int, event_id: int, token: string}
     */
    public function createPastOccurrence(string $suffix = 'past'): array
    {
        $kingdomId = 1;
        $parkId = 1;
        $mundaneId = $this->insertMundane($suffix);
        $eventId = $this->insertEvent($kingdomId, $parkId, $mundaneId, $suffix);
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('-30 days')),
            date('Y-m-d H:i:s', strtotime('-30 days +6 hours')),
        );

        return [
            'mundane_id' => $mundaneId,
            'detail_id' => $detailId,
            'event_id' => $eventId,
            'token' => $this->mundaneTokens[$mundaneId] ?? '',
        ];
    }

    public function insertSecondPlayer(int $parkId, int $kingdomId, string $suffix = 'player2'): int
    {
        return $this->insertMundane($suffix, $parkId, $kingdomId);
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithAuth(string $type, int $scopeId, string $role, string $suffix = 'grantor'): array
    {
        $mundaneId = $this->insertMundane($suffix);
        $this->insertAuthorization($mundaneId, $type, $scopeId, $role);

        return [
            'mundane_id' => $mundaneId,
            'token' => $this->mundaneTokens[$mundaneId] ?? '',
        ];
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithoutAuth(string $suffix = 'noauth'): array
    {
        $mundaneId = $this->insertMundane($suffix);

        return [
            'mundane_id' => $mundaneId,
            'token' => $this->mundaneTokens[$mundaneId] ?? '',
        ];
    }

    public function insertAttendanceStaff(int $detailId, int $mundaneId): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
             (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
             VALUES (?, ?, ?, 0, 1, 0, 0)'
        );
        $stmt->execute([$detailId, $mundaneId, 'Attendance']);
        $id = (int) $this->pdo->lastInsertId();
        $this->staffIds[] = $id;

        return $id;
    }

    public function insertRsvp(int $detailId, int $mundaneId, string $status): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_rsvp (event_calendardetail_id, mundane_id, status)
             VALUES (?, ?, ?)'
        );
        $stmt->execute([$detailId, $mundaneId, $status]);
    }

    public function cleanup(): void
    {
        foreach (array_reverse($this->staffIds) as $staffId) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ?'
            );
            $stmt->execute([$staffId]);
        }

        if ($this->detailIds !== []) {
            $placeholders = implode(',', array_fill(0, count($this->detailIds), '?'));
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id IN (' . $placeholders . ')'
            );
            $stmt->execute($this->detailIds);
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id IN (' . $placeholders . ')'
            );
            $stmt->execute($this->detailIds);
        }

        foreach (array_reverse($this->detailIds) as $detailId) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ?'
            );
            $stmt->execute([$detailId]);
        }

        foreach (array_reverse($this->eventIds) as $eventId) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'authorization WHERE event_id = ?'
            );
            $stmt->execute([$eventId]);
            $stmt = $this->pdo->prepare('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ?');
            $stmt->execute([$eventId]);
        }

        foreach (array_reverse($this->authIds) as $authId) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ?'
            );
            $stmt->execute([$authId]);
        }

        foreach (array_reverse($this->mundaneIds) as $mundaneId) {
            $stmt = $this->pdo->prepare(
                'DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ?'
            );
            $stmt->execute([$mundaneId]);
            $stmt = $this->pdo->prepare('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?');
            $stmt->execute([$mundaneId]);
        }

        $this->staffIds = [];
        $this->detailIds = [];
        $this->eventIds = [];
        $this->authIds = [];
        $this->mundaneIds = [];
        $this->mundaneTokens = [];
    }

    private function insertAuthorization(int $mundaneId, string $type, int $scopeId, string $role): void
    {
        $kingdomId = 0;
        $parkId = 0;
        $eventId = 0;
        $unitId = 0;

        match ($type) {
            AUTH_KINGDOM => $kingdomId = $scopeId,
            AUTH_PARK => $parkId = $scopeId,
            AUTH_EVENT => $eventId = $scopeId,
            default => null,
        };

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$mundaneId, $parkId, $kingdomId, $eventId, $unitId, $role]);
        $this->authIds[] = (int) $this->pdo->lastInsertId();
    }

    private function insertMundane(string $suffix, int $parkId = 1, int $kingdomId = 1): int
    {
        $token = bin2hex(random_bytes(16));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $persona = self::MARKER . ' ' . $suffix;

        // Clone an existing row so NOT NULL columns added by migrations stay satisfied.
        $stmt = $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        );
        $templateId = (int) $stmt->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No template mundane row available for RSVP fixtures.');
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
        $this->mundaneTokens[$id] = $token;

        return $id;
    }

    private function insertEvent(int $kingdomId, int $parkId, int $mundaneId, string $suffix): int
    {
        $name = self::MARKER . ' Event ' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event
             (kingdom_id, park_id, mundane_id, unit_id, name, status)
             VALUES (?, ?, ?, 1, ?, ?)'
        );
        $stmt->execute([$kingdomId, $parkId, $mundaneId, $name, 'published']);

        $id = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $id;

        return $id;
    }

    private function insertDetail(int $eventId, string $start, string $end): int
    {
        $stmt = $this->pdo->query(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail ORDER BY event_calendardetail_id ASC LIMIT 1'
        );
        $templateId = (int) $stmt->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No template event_calendardetail row available for RSVP fixtures.');
        }

        $clone = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_calendardetail
             (event_id, at_park_id, current, price, event_start, event_end, description, url, url_name,
              address, province, postal_code, city, country, map_url, map_url_name, google_geocode, location,
              latitude, longitude, event_type)
             SELECT ?, at_park_id, current, price, ?, ?, ?, url, url_name,
                    address, province, postal_code, city, country, map_url, map_url_name, google_geocode, location,
                    latitude, longitude, event_type
             FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ?'
        );
        $clone->execute([
            $eventId,
            $start,
            $end,
            self::MARKER . ' occurrence',
            $templateId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->detailIds[] = $id;

        return $id;
    }
}
