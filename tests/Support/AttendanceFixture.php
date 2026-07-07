<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for attendance/sign-in characterization tests (T-12).
 */
final class AttendanceFixture
{
    private const MARKER = 'T12ATT';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    /** @var list<int> */
    private array $attendanceIds = [];

    /** @var list<int> */
    private array $linkIds = [];

    /** @var list<int> */
    private array $reconciliationIds = [];

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

    public function firstClassId(): int
    {
        return (int) $this->pdo->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id ASC LIMIT 1'
        )->fetchColumn();
    }

    public function secondClassId(int $excludeClassId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT class_id FROM ' . DB_PREFIX . 'class WHERE class_id != ? ORDER BY class_id ASC LIMIT 1'
        );
        $stmt->execute([$excludeClassId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, token: string, park_id: int, kingdom_id: int}
     */
    public function createParkOfficer(int $parkId, string $suffix = 'officer'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token);
        $this->insertAuthorization($mundaneId, AUTH_PARK, $parkId, AUTH_EDIT);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
        ];
    }

    /**
     * @return array{mundane_id: int, token: string, park_id: int, kingdom_id: int, persona: string}
     */
    public function createPlayer(int $parkId, string $suffix = 'player', bool $active = true): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token, $active);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
            'persona' => self::MARKER . ' ' . $suffix,
        ];
    }

    public function setInactive(int $mundaneId): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET active = 0 WHERE mundane_id = ?')
            ->execute([$mundaneId]);
    }

    public function fetchActive(int $mundaneId): int
    {
        $stmt = $this->pdo->prepare('SELECT active FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?');
        $stmt->execute([$mundaneId]);

        return (int) $stmt->fetchColumn();
    }

    public function fetchPersona(int $mundaneId): string
    {
        $stmt = $this->pdo->prepare('SELECT persona FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?');
        $stmt->execute([$mundaneId]);

        return (string) $stmt->fetchColumn();
    }

    public function insertParkAttendance(
        int $parkId,
        int $kingdomId,
        int $mundaneId,
        string $date,
        int $classId,
        float $credits = 1.0,
    ): int {
        $ts = strtotime($date);
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance
             (park_id, kingdom_id, mundane_id, persona, class_id, date, credits, note, flavor, by_whom_id, entered_at,
              entry_method, event_id, event_calendardetail_id, date_year, date_month, date_week3, date_week6)
             VALUES (?, ?, ?, ?, ?, ?, ?, \'\', \'\', ?, NOW(), \'manual\', 0, 0, ?, ?, ?, 0)'
        );
        $stmt->execute([
            $parkId,
            $kingdomId,
            $mundaneId,
            self::MARKER . ' att',
            $classId,
            $date . ' 12:00:00',
            $credits,
            $mundaneId,
            (int) date('Y', $ts),
            (int) date('n', $ts),
            (int) date('W', $ts),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->attendanceIds[] = $id;

        return $id;
    }

    /**
     * @return array{event_id: int, detail_id: int, name: string, park_id: int, kingdom_id: int}
     */
    public function createPublishedEvent(int $parkId, string $suffix = 'ev'): array
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

        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+2 days')),
            date('Y-m-d H:i:s', strtotime('+2 days +6 hours')),
        );

        return [
            'event_id' => $eventId,
            'detail_id' => $detailId,
            'name' => $name,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
        ];
    }

    /**
     * @return array{token: string, link_id: int}
     */
    public function insertParkLink(int $parkId, int $kingdomId, int $byWhomId, ?string $expiresAt = null): array
    {
        $token = bin2hex(random_bytes(24));
        $expiresAt ??= gmdate('Y-m-d H:i:s', time() + 7200);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance_link
             (token, park_id, kingdom_id, event_id, event_calendardetail_id, by_whom_id, credits, expires_at, created_at)
             VALUES (?, ?, ?, NULL, NULL, ?, 1, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$token, $parkId, $kingdomId, $byWhomId, $expiresAt]);
        $linkId = (int) $this->pdo->lastInsertId();
        $this->linkIds[] = $linkId;

        return ['token' => $token, 'link_id' => $linkId];
    }

    /**
     * @return array{token: string, link_id: int}
     */
    public function insertEventLink(
        int $eventId,
        int $detailId,
        int $parkId,
        int $kingdomId,
        int $byWhomId,
        ?string $expiresAt = null,
    ): array {
        $token = bin2hex(random_bytes(24));
        $expiresAt ??= gmdate('Y-m-d H:i:s', time() + 86400);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'attendance_link
             (token, park_id, kingdom_id, event_id, event_calendardetail_id, by_whom_id, credits, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 1, ?, UTC_TIMESTAMP())'
        );
        $stmt->execute([$token, $parkId, $kingdomId, $eventId, $detailId, $byWhomId, $expiresAt]);
        $linkId = (int) $this->pdo->lastInsertId();
        $this->linkIds[] = $linkId;

        return ['token' => $token, 'link_id' => $linkId];
    }

    public function expireLink(int $linkId): void
    {
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'attendance_link SET expires_at = ? WHERE link_id = ?'
        )->execute([gmdate('Y-m-d H:i:s', time() - 3600), $linkId]);
    }

    public function revokeLink(int $linkId): void
    {
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'attendance_link SET revoked_at = UTC_TIMESTAMP() WHERE link_id = ?'
        )->execute([$linkId]);
    }

    public function fetchEventName(int $eventId): string
    {
        $stmt = $this->pdo->prepare('SELECT name FROM ' . DB_PREFIX . 'event WHERE event_id = ?');
        $stmt->execute([$eventId]);

        return (string) $stmt->fetchColumn();
    }

    public function insertReconciliation(int $mundaneId, int $classId, float $reconciled): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'class_reconciliation (mundane_id, class_id, reconciled)
             VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE reconciled = VALUES(reconciled)'
        );
        $stmt->execute([$mundaneId, $classId, $reconciled]);
        $this->reconciliationIds[] = (int) $this->pdo->lastInsertId();
    }

    public function trackAttendance(int $attendanceId): void
    {
        if (!in_array($attendanceId, $this->attendanceIds, true)) {
            $this->attendanceIds[] = $attendanceId;
        }
    }

    public function cleanup(): void
    {
        foreach ($this->attendanceIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $id);
        }

        foreach ($this->linkIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance_link WHERE link_id = ' . (int) $id);
        }

        foreach ($this->reconciliationIds as $id) {
            if ($id > 0) {
                $this->pdo->exec(
                    'DELETE FROM ' . DB_PREFIX . 'class_reconciliation WHERE class_reconciliation_id = ' . (int) $id
                );
            }
        }

        foreach ($this->detailIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $id);
        }

        foreach ($this->eventIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $id);
        }

        foreach ($this->authIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ' . (int) $id);
        }

        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'class_reconciliation WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
        }
    }

    private function insertDetail(int $eventId, string $start, string $end): int
    {
        $templateId = (int) $this->pdo->query(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail ORDER BY event_calendardetail_id ASC LIMIT 1'
        )->fetchColumn();

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
        $clone->execute([$eventId, $start, $end, self::MARKER . ' detail', $templateId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->detailIds[] = $id;

        return $id;
    }

    private function insertAuthorization(int $mundaneId, string $type, int $scopeId, string $role): void
    {
        $kingdomId = 0;
        $parkId = 0;
        $eventId = 0;

        match ($type) {
            AUTH_PARK => $parkId = $scopeId,
            AUTH_KINGDOM => $kingdomId = $scopeId,
            AUTH_EVENT => $eventId = $scopeId,
            default => null,
        };

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, ?, ?, ?, 0, ?)'
        );
        $stmt->execute([$mundaneId, $parkId, $kingdomId, $eventId, $role]);
        $this->authIds[] = (int) $this->pdo->lastInsertId();
    }

    private function insertMundane(
        string $suffix,
        int $parkId,
        int $kingdomId,
        string $token,
        bool $active = true,
    ): int {
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
                    token_expires, password_expires, password_salt, xtoken, penalty_box, ?, suspended,
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
            $active ? 1 : 0,
            $templateId,
        ]);

        $id = (int) $this->pdo->lastInsertId();
        $this->mundaneIds[] = $id;

        return $id;
    }
}
