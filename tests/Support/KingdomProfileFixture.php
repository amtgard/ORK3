<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for kingdom profile/AJAX characterization tests (T-06).
 */
final class KingdomProfileFixture
{
    private const MARKER = 'T06KNG';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $authIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

    /** @var list<int> */
    private array $officerIds = [];

    /** @var list<int> */
    private array $staffIds = [];

    /** @var list<int> */
    private array $configIds = [];

    /** @var list<int> */
    private array $attendanceIds = [];

    /** @var list<int> */
    private array $attendanceKingdomIds = [];

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

    public function parkIdInKingdom(int $kingdomId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ? AND active = \'Active\' ORDER BY park_id ASC LIMIT 1'
        );
        $stmt->execute([$kingdomId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, token: string, park_id: int, kingdom_id: int}
     */
    public function createPlayer(string $suffix = 'player', ?int $kingdomId = null, ?int $parkId = null): array
    {
        $kingdomId ??= $this->firstKingdomId();
        $parkId ??= $this->parkIdInKingdom($kingdomId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
        ];
    }

    public function createRecentAttendance(int $mundaneId, int $parkId, int $kingdomId): int
    {
        $classId = (int) $this->pdo->query(
            'SELECT class_id FROM ' . DB_PREFIX . 'class ORDER BY class_id ASC LIMIT 1'
        )->fetchColumn();
        if ($classId <= 0) {
            throw new RuntimeException('No class row in seed data.');
        }

        $date = date('Y-m-d', strtotime('-7 days'));
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
            $date . ' 12:00:00',
            $mundaneId,
            (int) date('Y', $timestamp),
            (int) date('n', $timestamp),
            (int) date('W', $timestamp),
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $this->attendanceIds[] = $id;
        $this->attendanceKingdomIds[] = $kingdomId;
        (new Report())->bustKingdomParkAverageCaches($kingdomId);

        return $id;
    }

    /**
     * @return array{event_id: int, detail_id: int, kingdom_id: int, park_id: int, mundane_id: int}
     */
    public function createPublishedEvent(string $suffix = 'pub', string $status = 'published', ?int $kingdomId = null): array
    {
        $kingdomId ??= $this->firstKingdomId();
        $parkId = $this->parkIdInKingdom($kingdomId);
        $owner = $this->createPlayer($suffix . '-owner', $kingdomId, $parkId);
        $eventId = $this->insertEvent($kingdomId, $parkId, $owner['mundane_id'], $suffix, $status);
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+14 days')),
            date('Y-m-d H:i:s', strtotime('+14 days +6 hours')),
        );

        return [
            'event_id' => $eventId,
            'detail_id' => $detailId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
            'mundane_id' => $owner['mundane_id'],
        ];
    }

    public function insertOfficer(int $kingdomId, int $mundaneId, string $role): int
    {
        $this->insertAuthorization($mundaneId, $kingdomId, AUTH_EDIT);
        $authId = end($this->authIds);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'officer (kingdom_id, park_id, mundane_id, role, authorization_id)
             VALUES (?, 0, ?, ?, ?)'
        );
        $stmt->execute([$kingdomId, $mundaneId, $role, $authId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->officerIds[] = $id;

        return $id;
    }

    public function insertStaff(int $detailId, int $mundaneId, bool $canSchedule = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
             (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
             VALUES (?, ?, ?, 0, 0, ?, 0)'
        );
        $stmt->execute([$detailId, $mundaneId, 'Staff', $canSchedule ? 1 : 0]);
        $id = (int) $this->pdo->lastInsertId();
        $this->staffIds[] = $id;

        return $id;
    }

    public function insertAuthorization(int $mundaneId, int $kingdomId, string $role): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role)
             VALUES (?, 0, ?, 0, 0, ?)'
        );
        $stmt->execute([$mundaneId, $kingdomId, $role]);
        $this->authIds[] = (int) $this->pdo->lastInsertId();
    }

    public function setAwardRecsPublic(int $kingdomId, bool $public): void
    {
        $value = json_encode($public ? '1' : '0');
        $stmt = $this->pdo->prepare(
            'SELECT configuration_id FROM ' . DB_PREFIX . "configuration
             WHERE type = 'Kingdom' AND id = ? AND `key` = 'AwardRecsPublic' LIMIT 1"
        );
        $stmt->execute([$kingdomId]);
        $existing = $stmt->fetchColumn();
        if ($existing) {
            $this->pdo->prepare(
                'UPDATE ' . DB_PREFIX . 'configuration SET value = ?, modified = NOW() WHERE configuration_id = ?'
            )->execute([$value, (int) $existing]);
            $this->configIds[] = (int) $existing;
        } else {
            $this->pdo->prepare(
                'INSERT INTO ' . DB_PREFIX . "configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
                 VALUES ('Kingdom', 'fixed', ?, 'AwardRecsPublic', ?, 1, 'null', NOW())"
            )->execute([$kingdomId, $value]);
            $this->configIds[] = (int) $this->pdo->lastInsertId();
        }
    }

    public function fetchAwardRecsPublic(int $kingdomId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT value FROM ' . DB_PREFIX . "configuration
             WHERE type = 'Kingdom' AND id = ? AND `key` = 'AwardRecsPublic' LIMIT 1"
        );
        $stmt->execute([$kingdomId]);
        $val = $stmt->fetchColumn();

        return $val === false ? null : (string) $val;
    }

    public function cleanup(): void
    {
        foreach ($this->attendanceIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'attendance WHERE attendance_id = ' . (int) $id);
        }

        foreach (array_unique($this->attendanceKingdomIds) as $kingdomId) {
            (new Report())->bustKingdomParkAverageCaches((int) $kingdomId);
        }

        foreach ($this->detailIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_calendardetail WHERE event_calendardetail_id = ' . (int) $id);
        }

        foreach ($this->staffIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ' . (int) $id);
        }

        foreach ($this->eventIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event WHERE event_id = ' . (int) $id);
        }

        foreach ($this->officerIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'officer WHERE officer_id = ' . (int) $id);
        }

        foreach ($this->configIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'configuration WHERE configuration_id = ' . (int) $id);
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
