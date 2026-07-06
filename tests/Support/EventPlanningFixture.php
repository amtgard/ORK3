<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for EventAjax / event planning characterization tests (T-04).
 */
final class EventPlanningFixture
{
    private const MARKER = 'T04EVPL';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    /** @var list<int> */
    private array $staffIds = [];

    /** @var list<int> */
    private array $scheduleIds = [];

    /** @var list<int> */
    private array $leadIds = [];

    /** @var list<int> */
    private array $rsvpIds = [];

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
            'SELECT kingdom_id FROM ' . DB_PREFIX . 'kingdom ORDER BY kingdom_id ASC LIMIT 1'
        )->fetchColumn();
    }

    public function firstParkId(): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();
    }

    public function firstClassId(): int
    {
        return (int) $this->pdo->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id ASC LIMIT 1'
        )->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithAuth(string $type, int $scopeId, string $role, string $suffix = 'grantor'): array
    {
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, token: $token);
        $this->insertAuthorization($mundaneId, $type, $scopeId, $role);

        return ['mundane_id' => $mundaneId, 'token' => $token];
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithoutAuth(string $suffix = 'noauth'): array
    {
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, token: $token);

        return ['mundane_id' => $mundaneId, 'token' => $token];
    }

    /**
     * @return array{event_id: int, detail_id: int, kingdom_id: int, park_id: int, mundane_id: int}
     */
    public function createPublishedEvent(string $suffix = 'pub', string $status = 'published'): array
    {
        $kingdomId = $this->firstKingdomId();
        $parkId = $this->firstParkId();
        $ownerId = $this->insertMundane($suffix . '-owner', $parkId, $kingdomId);
        $eventId = $this->insertEvent($kingdomId, $parkId, $ownerId, $suffix, $status);
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+7 days')),
            date('Y-m-d H:i:s', strtotime('+7 days +6 hours')),
        );

        return [
            'event_id' => $eventId,
            'detail_id' => $detailId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
            'mundane_id' => $ownerId,
        ];
    }

    /**
     * @return array{event_id: int, detail_id: int}
     */
    public function createPastPublishedEventAtPark(int $parkId, int $kingdomId, string $suffix = 'past'): array
    {
        $ownerId = $this->insertMundane($suffix . '-owner', $parkId, $kingdomId);
        $eventId = $this->insertEvent($kingdomId, $parkId, $ownerId, $suffix, 'published');
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('-60 days')),
            date('Y-m-d H:i:s', strtotime('-60 days +6 hours')),
        );

        return ['event_id' => $eventId, 'detail_id' => $detailId];
    }

    public function insertStaff(
        int $detailId,
        int $mundaneId,
        string $roleName = 'Staff',
        bool $canManage = false,
        bool $canAttendance = false,
        bool $canSchedule = false,
        bool $canFeast = false,
    ): int {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
             (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $detailId,
            $mundaneId,
            $roleName,
            $canManage ? 1 : 0,
            $canAttendance ? 1 : 0,
            $canSchedule ? 1 : 0,
            $canFeast ? 1 : 0,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->staffIds[] = $id;

        return $id;
    }

    public function updateStaff(int $staffId, string $roleName, bool $canManage): void
    {
        $this->pdo->prepare(
            'UPDATE ' . DB_PREFIX . 'event_staff SET role_name = ?, can_manage = ? WHERE event_staff_id = ?'
        )->execute([$roleName, $canManage ? 1 : 0, $staffId]);
    }

    public function insertSchedule(int $detailId, string $title, string $category = 'Tournament'): int
    {
        $start = date('Y-m-d H:i:s', strtotime('+7 days 10:00'));
        $end = date('Y-m-d H:i:s', strtotime('+7 days 11:00'));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_schedule
             (event_calendardetail_id, title, start_time, end_time, location, description, category)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([$detailId, $title, $start, $end, 'Main', self::MARKER, $category]);
        $id = (int) $this->pdo->lastInsertId();
        $this->scheduleIds[] = $id;

        return $id;
    }

    public function insertScheduleLead(int $scheduleId, int $mundaneId): void
    {
        $this->pdo->prepare(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'event_schedule_lead (event_schedule_id, mundane_id) VALUES (?, ?)'
        )->execute([$scheduleId, $mundaneId]);
    }

    public function insertRsvp(int $detailId, int $mundaneId, string $status): void
    {
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_rsvp (event_calendardetail_id, mundane_id, status)
             VALUES (?, ?, ?)'
        )->execute([$detailId, $mundaneId, $status]);
    }

    public function fetchEventStatus(int $eventId): ?string
    {
        $stmt = $this->pdo->prepare('SELECT status FROM ' . DB_PREFIX . 'event WHERE event_id = ?');
        $stmt->execute([$eventId]);
        $val = $stmt->fetchColumn();

        return $val === false ? null : (string) $val;
    }

    public function fetchStaffRow(int $staffId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT role_name, can_manage FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ?'
        );
        $stmt->execute([$staffId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function staffExists(int $staffId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ?'
        );
        $stmt->execute([$staffId]);

        return (bool) $stmt->fetchColumn();
    }

    public function scheduleExists(int $scheduleId, int $detailId): bool
    {
        $stmt = $this->pdo->prepare(
            'SELECT 1 FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ? AND event_calendardetail_id = ?'
        );
        $stmt->execute([$scheduleId, $detailId]);

        return (bool) $stmt->fetchColumn();
    }

    public function countScheduleLeads(int $scheduleId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ?'
        );
        $stmt->execute([$scheduleId]);

        return (int) $stmt->fetchColumn();
    }

    public function setEventHeraldry(int $eventId, int $hasHeraldry = 1): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'event SET has_heraldry = ? WHERE event_id = ?')
            ->execute([$hasHeraldry, $eventId]);
    }

    public function fetchEventHeraldry(int $eventId): int
    {
        $stmt = $this->pdo->prepare('SELECT has_heraldry FROM ' . DB_PREFIX . 'event WHERE event_id = ?');
        $stmt->execute([$eventId]);

        return (int) $stmt->fetchColumn();
    }

    public function createPlayer(string $suffix = 'player'): int
    {
        return $this->insertMundane($suffix);
    }

    public function trackEvent(int $eventId): void
    {
        if (!in_array($eventId, $this->eventIds, true)) {
            $this->eventIds[] = $eventId;
        }
    }

    public function trackDetail(int $detailId): void
    {
        if (!in_array($detailId, $this->detailIds, true)) {
            $this->detailIds[] = $detailId;
        }
    }

    public function createDetailOnEvent(int $eventId, string $start, string $end): int
    {
        return $this->insertDetail($eventId, $start, $end);
    }

    public function cleanup(): void
    {
        foreach ($this->attendanceIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $id);
        }

        foreach ($this->rsvpIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_rsvp_id = ' . (int) $id);
        }

        foreach ($this->scheduleIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_schedule_lead WHERE event_schedule_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_schedule WHERE event_schedule_id = ' . (int) $id);
        }

        foreach ($this->staffIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ' . (int) $id);
        }

        foreach ($this->detailIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_schedule WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $id);
        }

        foreach ($this->eventIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE event_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $id);
        }

        foreach ($this->authIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE authorization_id = ' . (int) $id);
        }

        foreach ($this->mundaneIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
        }
    }

    private function insertEvent(int $kingdomId, int $parkId, int $mundaneId, string $suffix, string $status): int
    {
        $name = self::MARKER . ' Event ' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event
             (kingdom_id, park_id, mundane_id, unit_id, name, status)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $stmt->execute([$kingdomId, $parkId, $mundaneId, $name, $status]);
        $id = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $id;

        return $id;
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

    private function insertMundane(
        string $suffix,
        int $parkId = 0,
        int $kingdomId = 0,
        ?string $token = null,
    ): int {
        $token ??= md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $persona = self::MARKER . ' ' . $suffix;

        $templateId = (int) $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        )->fetchColumn();

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
