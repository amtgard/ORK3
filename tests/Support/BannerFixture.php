<?php

declare(strict_types=1);

/**
 * Ephemeral DB fixtures for hero banner characterization tests (T-03).
 */
final class BannerFixture
{
    private const MARKER = 'T03BANNER';

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
    private array $parkIdsToRestore = [];

    /** @var list<string> */
    private array $bannerFiles = [];

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
            throw new RuntimeException('No kingdom row available for banner fixtures.');
        }

        return $id;
    }

    public function firstParkId(): int
    {
        $id = (int) $this->pdo->query(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE active = \'Active\' ORDER BY park_id ASC LIMIT 1'
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException('No active park row available for banner fixtures.');
        }

        return $id;
    }

    public function firstUnitId(): int
    {
        $id = (int) $this->pdo->query(
            'SELECT unit_id FROM ' . DB_PREFIX . 'unit ORDER BY unit_id ASC LIMIT 1'
        )->fetchColumn();

        if ($id <= 0) {
            throw new RuntimeException('No unit row available for banner fixtures.');
        }

        return $id;
    }

    /**
     * @return array{mundane_id: int, token: string}
     */
    public function createGrantorWithAuth(string $type, int $scopeId, string $role, string $suffix = 'grantor'): array
    {
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, token: $token);
        $this->insertAuthorization($mundaneId, $type, $scopeId, $role);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
        ];
    }

    /**
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
    public function createPlayerWithGradient(string $suffix = 'player'): array
    {
        $kingdomId = $this->firstKingdomId();
        $parkId = $this->firstParkId();
        $token = md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $mundaneId = $this->insertMundane($suffix, $parkId, $kingdomId, $token);

        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'mundane_design (mundane_id, hero_gradient)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE hero_gradient = VALUES(hero_gradient)'
        )->execute([$mundaneId, '#ff0000']);

        return [
            'mundane_id' => $mundaneId,
            'token' => $token,
        ];
    }

    /**
     * @return array{event_id: int, detail_id: int, kingdom_id: int, park_id: int}
     */
    public function createEvent(string $suffix = 'event'): array
    {
        $kingdomId = $this->firstKingdomId();
        $parkId = $this->firstParkId();
        $ownerId = $this->insertMundane($suffix . '-owner', $parkId, $kingdomId);

        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event
             (kingdom_id, park_id, mundane_id, unit_id, name, status)
             VALUES (?, ?, ?, 0, ?, ?)'
        );
        $name = self::MARKER . '-' . $suffix . '-' . bin2hex(random_bytes(4));
        $stmt->execute([$kingdomId, $parkId, $ownerId, $name, 'published']);
        $eventId = (int) $this->pdo->lastInsertId();
        $this->eventIds[] = $eventId;

        $detailId = $this->insertDetail(
            $eventId,
            date('Y-m-d H:i:s', strtotime('+30 days')),
            date('Y-m-d H:i:s', strtotime('+30 days +6 hours')),
        );

        return [
            'event_id' => $eventId,
            'detail_id' => $detailId,
            'kingdom_id' => $kingdomId,
            'park_id' => $parkId,
        ];
    }

    public function insertEventStaff(int $detailId, int $mundaneId, bool $canManage = false): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'event_staff
             (event_calendardetail_id, mundane_id, role_name, can_manage, can_attendance, can_schedule, can_feast)
             VALUES (?, ?, ?, ?, 0, 0, 0)'
        );
        $stmt->execute([
            $detailId,
            $mundaneId,
            self::MARKER . '-staff',
            $canManage ? 1 : 0,
        ]);
        $staffId = (int) $this->pdo->lastInsertId();
        $this->staffIds[] = $staffId;

        return $staffId;
    }

    public function setParkRetired(int $parkId): void
    {
        $row = $this->pdo->prepare('SELECT active FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $row->execute([$parkId]);
        $active = $row->fetchColumn();
        if ($active !== false) {
            $this->parkIdsToRestore[$parkId] = (string) $active;
        }
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'park SET active = ? WHERE park_id = ?')
            ->execute(['Retired', $parkId]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchParkBanner(int $parkId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y
             FROM ' . DB_PREFIX . 'park WHERE park_id = ?'
        );
        $stmt->execute([$parkId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchPlayerBanner(int $mundaneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y
             FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ?'
        );
        $stmt->execute([$mundaneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function fetchPlayerGradient(int $mundaneId): ?string
    {
        $stmt = $this->pdo->prepare(
            'SELECT hero_gradient FROM ' . DB_PREFIX . 'mundane_design WHERE mundane_id = ?'
        );
        $stmt->execute([$mundaneId]);
        $val = $stmt->fetchColumn();

        return $val === false ? null : ($val === null ? null : (string) $val);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function fetchEventBanner(int $eventId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT has_banner, banner_show_logo, banner_vignette, banner_offset_x, banner_offset_y
             FROM ' . DB_PREFIX . 'event WHERE event_id = ?'
        );
        $stmt->execute([$eventId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    public function createTempJpeg(int $width = 16, int $height = 16): string
    {
        $path = tempnam(sys_get_temp_dir(), 't03banner');
        if ($path === false) {
            throw new RuntimeException('Could not create temp file for banner test image.');
        }
        $im = imagecreatetruecolor($width, $height);
        imagejpeg($im, $path, 90);
        imagedestroy($im);

        return $path;
    }

    public function trackBannerFile(string $path): void
    {
        $this->bannerFiles[] = $path;
    }

    public function parkIsActive(int $parkId): bool
    {
        $stmt = $this->pdo->prepare('SELECT active FROM ' . DB_PREFIX . 'park WHERE park_id = ?');
        $stmt->execute([$parkId]);
        $active = $stmt->fetchColumn();

        return $active !== false && trim((string) $active) === 'Active';
    }

    public function cleanup(): void
    {
        foreach ($this->bannerFiles as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }

        foreach ($this->staffIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_staff_id = ' . (int) $id);
        }

        foreach ($this->detailIds as $id) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_rsvp WHERE event_calendardetail_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'event_staff WHERE event_calendardetail_id = ' . (int) $id);
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
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane_design WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'authorization WHERE mundane_id = ' . (int) $id);
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $id);
        }

        foreach ($this->parkIdsToRestore as $parkId => $active) {
            $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'park SET active = ? WHERE park_id = ?')
                ->execute([$active, $parkId]);
        }
    }

    private function insertMundane(string $suffix, ?int $parkId = null, ?int $kingdomId = null, ?string $token = null): int
    {
        $token ??= md5(self::MARKER . $suffix . bin2hex(random_bytes(8)));
        $username = strtolower(self::MARKER . '_' . $suffix . '_' . substr($token, 0, 8));
        $persona = self::MARKER . ' ' . $suffix;

        $templateId = (int) $this->pdo->query(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'mundane ORDER BY mundane_id ASC LIMIT 1'
        )->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No template mundane row available for banner fixtures.');
        }

        if ($parkId === null || $kingdomId === null) {
            $scope = $this->pdo->query(
                'SELECT park_id, kingdom_id FROM ' . DB_PREFIX . 'park ORDER BY park_id ASC LIMIT 1'
            )->fetch(PDO::FETCH_ASSOC);
            $parkId ??= (int) $scope['park_id'];
            $kingdomId ??= (int) $scope['kingdom_id'];
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

    private function insertAuthorization(int $mundaneId, string $type, int $scopeId, string $role): int
    {
        $kingdomId = 0;
        $parkId = 0;
        $eventId = 0;
        $unitId = 0;

        match ($type) {
            AUTH_KINGDOM => $kingdomId = $scopeId,
            AUTH_PARK => $parkId = $scopeId,
            AUTH_EVENT => $eventId = $scopeId,
            AUTH_UNIT => $unitId = $scopeId,
            default => null,
        };

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

    private function insertDetail(int $eventId, string $start, string $end): int
    {
        $templateId = (int) $this->pdo->query(
            'SELECT event_calendardetail_id FROM ' . DB_PREFIX . 'event_calendardetail ORDER BY event_calendardetail_id ASC LIMIT 1'
        )->fetchColumn();
        if ($templateId <= 0) {
            throw new RuntimeException('No template event_calendardetail row available for banner fixtures.');
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
        $clone->execute([$eventId, $start, $end, self::MARKER . ' detail', $templateId]);
        $id = (int) $this->pdo->lastInsertId();
        $this->detailIds[] = $id;

        return $id;
    }
}
