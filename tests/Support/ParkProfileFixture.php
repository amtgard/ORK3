<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for park profile/AJAX characterization tests (T-07).
 */
final class ParkProfileFixture
{
    private const MARKER = 'T07PRK';

    /** @var list<int> */
    private array $mundaneIds = [];

    /** @var list<int> */
    private array $eventIds = [];

    /** @var list<int> */
    private array $detailIds = [];

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

    public function firstParkId(): int
    {
        return (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE active = \'Active\' ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();
    }

    public function kingdomIdForPark(int $parkId): int
    {
        $stmt = $this->pdo->prepare('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array{mundane_id: int, park_id: int, kingdom_id: int}
     */
    public function createPlayer(int $parkId, string $suffix = 'player'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token);

        return ['mundane_id' => $mundaneId, 'park_id' => $parkId, 'kingdom_id' => $kingdomId];
    }

    /**
     * @return array{event_id: int, detail_id: int, park_id: int, kingdom_id: int, mundane_id: int}
     */
    public function createPublishedEvent(int $parkId, string $suffix = 'pub', string $status = 'published'): array
    {
        $kingdomId = $this->kingdomIdForPark($parkId);
        $owner = $this->createPlayer($parkId, $suffix . '-owner');
        $eventId = $this->insertEvent($kingdomId, $parkId, $owner['mundane_id'], $suffix, $status);
        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+14 days')),
            date('Y-m-d H:i:s', strtotime('+14 days +6 hours')),
        );

        return [
            'event_id' => $eventId,
            'detail_id' => $detailId,
            'park_id' => $parkId,
            'kingdom_id' => $kingdomId,
            'mundane_id' => $owner['mundane_id'],
        ];
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

    public function fetchParkAbbreviation(int $parkId): string
    {
        $stmt = $this->pdo->prepare('SELECT abbreviation FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);

        return (string) $stmt->fetchColumn();
    }

    public function cleanup(): void
    {
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
