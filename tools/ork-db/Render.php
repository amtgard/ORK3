<?php

declare(strict_types=1);

namespace OrkDb;

final class Render
{
    private const DB_PREFIX = 'ork_';
    private const TEST_CANARY_MARKER = 'ORK3_TEST_CANARY_v1';
    private const MUNDANE_COLUMNS = [
        'mundane_id', 'given_name', 'surname', 'other_name', 'username', 'persona', 'email',
        'park_id', 'kingdom_id', 'token', 'modified', 'restricted', 'waivered', 'waiver_ext',
        'has_heraldry', 'has_image', 'company_id', 'token_expires', 'password_expires',
        'password_salt', 'xtoken', 'penalty_box', 'active',
    ];

    /** @var array<string, mixed> */
    private array $fingerprints;

    /** @var array<string, mixed> */
    private array $extractManifest;

    /** @var list<array{kingdom_id: int, park_id: int, name: string, abbreviation: string}> */
    private array $parks = [];

    /** @var list<array<string, mixed>> */
    private array $fakePlayers = [];

    /** @var list<array<string, mixed>> */
    private array $awards = [];

    /** @var list<array{event_id: int, kingdom_id: int, name: string}> */
    private array $events = [];

    private int $nextFakeMundaneId = 1000;
    private int $nextEventId = 80000;
    private int $nextKingdomAwardId = 1;
    private int $nextConfigurationId = 1;
    private int $nextOfficerId = 1;
    private int $nextAttendanceId = 1;
    private int $nextAwardsInstanceId = 1;

    public function __construct(
        private readonly string $toolRoot,
        private readonly string $repoRoot,
    ) {
        $this->fingerprints = Json5::decodeFile($toolRoot . '/manifests/fingerprints.json5');
        $this->extractManifest = Json5::decodeFile($toolRoot . '/manifests/extract-sources.json5');
    }

    /**
     * @param array{
     *   anchor_date?: string|null,
     *   seed?: int|null,
     *   output?: string|null,
     *   deterministic?: bool,
     *   persist_seed?: bool
     * } $options
     * @return array{output: string, park_count: int, kingdom_count: int, content_seed: int, anchor_date: string}
     */
    public function run(array $options = []): array
    {
        $anchorDate = $this->resolveAnchorDate($options['anchor_date'] ?? null);
        $contentSeed = $this->resolveContentSeed($options);
        $deterministic = (bool) ($options['deterministic'] ?? false);
        $output = $options['output'] ?? $this->toolRoot . '/rendered/sandbox.sql';

        mt_srand($contentSeed);
        $this->resetGenerationState();

        $sql = $this->compose($anchorDate, $contentSeed, $deterministic);
        $outputDir = dirname($output);
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Failed to create render output directory: {$outputDir}");
        }

        if (file_put_contents($output, $sql) === false) {
            throw new \RuntimeException("Failed to write rendered SQL: {$output}");
        }

        return [
            'output' => $output,
            'park_count' => count($this->parks),
            'kingdom_count' => 5,
            'content_seed' => $contentSeed,
            'anchor_date' => $anchorDate,
        ];
    }

    public function expectedParkCountForSeed(int $seed): int
    {
        mt_srand($seed);
        $total = 0;
        for ($i = 0; $i < 5; $i++) {
            $total += mt_rand(
                (int) ($this->fingerprints['parks_per_kingdom_range'][0] ?? 2),
                (int) ($this->fingerprints['parks_per_kingdom_range'][1] ?? 6)
            );
        }

        return $total;
    }

    private function resetGenerationState(): void
    {
        $this->parks = [];
        $this->fakePlayers = [];
        $this->awards = [];
        $this->events = [];
        $this->nextFakeMundaneId = 1000;
        $this->nextEventId = 80000;
        $this->nextKingdomAwardId = 1;
        $this->nextConfigurationId = 1;
        $this->nextOfficerId = 1;
        $this->nextAttendanceId = 1;
        $this->nextAwardsInstanceId = 1;
    }

    private function resolveAnchorDate(?string $anchorDate): string
    {
        if ($anchorDate !== null && $anchorDate !== '') {
            $parsed = \DateTimeImmutable::createFromFormat('Y-m-d', $anchorDate);
            if ($parsed === false) {
                throw new ValidationException("Invalid anchor date '{$anchorDate}' — expected YYYY-MM-DD");
            }

            return $parsed->format('Y-m-d');
        }

        return (new \DateTimeImmutable('today'))->format('Y-m-d');
    }

    /** @param array{seed?: int|null, persist_seed?: bool} $options */
    private function resolveContentSeed(array $options): int
    {
        if (isset($options['seed'])) {
            $seed = (int) $options['seed'];
            if (!empty($options['persist_seed'])) {
                $this->fingerprints['content_seed'] = $seed;
                $this->fingerprints['park_count_by_seed'][(string) $seed] = $this->expectedParkCountForSeed($seed);
                $this->persistFingerprints();
            }

            return $seed;
        }

        return (int) ($this->fingerprints['content_seed'] ?? $this->fingerprints['render_seed_default'] ?? 42);
    }

    private function persistFingerprints(): void
    {
        $path = $this->toolRoot . '/manifests/fingerprints.json5';
        $encoded = json_encode($this->fingerprints, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode fingerprints manifest');
        }

        if (file_put_contents($path, $encoded . "\n") === false) {
            throw new \RuntimeException("Failed to persist fingerprints: {$path}");
        }
    }

    private function compose(string $anchorDate, int $contentSeed, bool $deterministic): string
    {
        $windowStart = (new \DateTimeImmutable($anchorDate))->modify('-3 years')->format('Y-m-d');
        $generatedAt = $deterministic ? $anchorDate . 'T00:00:00Z' : gmdate('c');

        $sections = [
            $this->sectionHeader($anchorDate, $contentSeed, $generatedAt),
            $this->sectionBoilerplateStart(),
            $this->sectionSchema(),
            $this->sectionCatalogs(),
            $this->sectionKingdoms($deterministic),
            $this->sectionParks($deterministic),
            $this->sectionRealPlayers($deterministic),
            $this->sectionFakePlayers($deterministic),
            $this->sectionCredentialsAndAuth($deterministic),
            $this->sectionOfficers($deterministic),
            $this->sectionMajorEvents($anchorDate, $windowStart, $deterministic),
            $this->sectionAttendance($anchorDate, $windowStart, $deterministic),
            $this->sectionKingdomAwards($deterministic),
            $this->sectionConfiguration($deterministic),
            $this->sectionCanary($deterministic),
            "SET FOREIGN_KEY_CHECKS=1;\n",
        ];

        return implode("\n", array_filter($sections, static fn (string $section): bool => $section !== ''));
    }

    private function sectionHeader(string $anchorDate, int $contentSeed, string $generatedAt): string
    {
        return implode("\n", [
            '-- ORK3 Test Database Render',
            '-- anchor_date: ' . $anchorDate,
            '-- content_seed: ' . $contentSeed,
            '-- generated_at: ' . $generatedAt,
            '-- DO NOT APPLY ON PRODUCTION — use bin/ork-db apply on local workstation only',
            '',
        ]);
    }

    private function sectionBoilerplateStart(): string
    {
        $database = 'ork_test';

        return implode("\n", [
            'SET FOREIGN_KEY_CHECKS=0;',
            "DROP DATABASE IF EXISTS {$database};",
            "CREATE DATABASE {$database};",
            "USE {$database};",
            '',
        ]);
    }

    private function sectionSchema(): string
    {
        $schemaPath = $this->repoRoot . '/ork.sql';
        if (!is_readable($schemaPath)) {
            throw new ValidationException('Schema file not readable: ' . $schemaPath);
        }

        $schema = file_get_contents($schemaPath);
        if ($schema === false) {
            throw new ValidationException('Failed to read schema file: ' . $schemaPath);
        }

        $supplementsPath = $this->toolRoot . '/templates/schema/supplements.sql';
        $supplements = is_readable($supplementsPath) ? (string) file_get_contents($supplementsPath) : '';

        return "-- Section: schema\n" . $schema . "\n" . $supplements . "\n";
    }

    private function sectionCatalogs(): string
    {
        $lines = ["-- Section: catalogs\n"];
        $extractDir = $this->toolRoot . '/extracted';

        foreach ($this->fixedExtractTables() as $table) {
            $path = $extractDir . '/' . $table . '.sql';
            if (!is_readable($path)) {
                throw new ValidationException(
                    "Missing extract for catalog '{$table}'. Run bin/ork-db extract first."
                );
            }
            $lines[] = '-- catalog: ' . $table;
            $lines[] = $this->stripSqlComments((string) file_get_contents($path));
            $lines[] = '';
        }

        foreach ($this->fixedEmbeddedTables() as $table) {
            $path = $this->toolRoot . '/templates/catalogs/' . $table . '.sql';
            if (!is_readable($path)) {
                throw new ValidationException("Missing embedded catalog: {$path}");
            }
            $lines[] = '-- catalog: ' . $table . ' (embedded)';
            $lines[] = trim((string) file_get_contents($path));
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function sectionKingdoms(bool $deterministic): string
    {
        $kingdoms = Json5::decodeFile($this->toolRoot . '/templates/stable/kingdoms.json5')['kingdoms'] ?? [];
        $lines = ["-- Section: kingdoms\n"];

        foreach ($kingdoms as $kingdom) {
            $displayName = trim((string) $kingdom['moniker'] . ' ' . (string) $kingdom['name']);
            $lines[] = $this->buildInsert(self::DB_PREFIX . 'kingdom', [
                'kingdom_id' => (int) $kingdom['id'],
                'name' => $displayName,
                'abbreviation' => (string) $kingdom['abbreviation'],
                'has_heraldry' => 0,
                'parent_kingdom_id' => (int) $kingdom['parent_kingdom_id'],
                'description' => (string) ($kingdom['description'] ?? ''),
                'url' => null,
                'modified' => $this->timestampValue($deterministic),
                'active' => 'Active',
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionParks(bool $deterministic): string
    {
        $names = Json5::decodeFile($this->toolRoot . '/templates/stable/park_names.json5')['names'] ?? [];
        if ($names === []) {
            throw new ValidationException('Park name pool is empty');
        }

        $kingdoms = Json5::decodeFile($this->toolRoot . '/templates/stable/kingdoms.json5')['kingdoms'] ?? [];
        $range = $this->fingerprints['parks_per_kingdom_range'] ?? [2, 6];
        $min = (int) $range[0];
        $max = (int) $range[1];
        $lines = ["-- Section: parks\n"];
        $nameIndex = 0;

        foreach ($kingdoms as $kingdom) {
            $kingdomId = (int) $kingdom['id'];
            $parkCount = mt_rand($min, $max);
            for ($seq = 1; $seq <= $parkCount; $seq++) {
                $parkId = $kingdomId * 1000 + $seq;
                $name = (string) $names[$nameIndex % count($names)];
                $nameIndex++;
                $abbreviation = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $name) ?? 'PRK', 0, 3));
                if ($abbreviation === '') {
                    $abbreviation = 'PRK';
                }

                $this->parks[] = [
                    'kingdom_id' => $kingdomId,
                    'park_id' => $parkId,
                    'name' => $name,
                    'abbreviation' => $abbreviation,
                ];

                $lines[] = $this->buildInsert(self::DB_PREFIX . 'park', [
                    'park_id' => $parkId,
                    'kingdom_id' => $kingdomId,
                    'name' => $name,
                    'abbreviation' => $abbreviation,
                    'has_heraldry' => 0,
                    'url' => '',
                    'parktitle_id' => 1,
                    'active' => 'Active',
                    'address' => '1 Test Lane',
                    'city' => 'Testville',
                    'province' => 'TS',
                    'postal_code' => '00000',
                    'google_geocode' => '',
                    'latitude' => 0.0,
                    'longitude' => 0.0,
                    'location' => '',
                    'map_url' => '',
                    'description' => 'Generated test park',
                    'directions' => '',
                    'modified' => $this->timestampValue($deterministic),
                ]);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionRealPlayers(bool $deterministic): string
    {
        $path = $this->toolRoot . '/extracted/mundane_real.json';
        if (!is_readable($path)) {
            throw new ValidationException('Missing real player extract. Run bin/ork-db extract first.');
        }

        $bundle = json_decode((string) file_get_contents($path), true);
        if (!is_array($bundle)) {
            throw new ValidationException('Invalid mundane_real.json extract');
        }

        $rules = Json5::decodeFile($this->toolRoot . '/templates/hybrid/real_players.json5');
        $ruleMap = [];
        foreach ($rules['players'] ?? [] as $rule) {
            $ruleMap[(string) $rule['key']] = $rule;
        }

        $lines = ["-- Section: real players\n"];
        foreach ($bundle['players'] ?? [] as $player) {
            $key = (string) ($player['key'] ?? '');
            $mundane = $player['mundane'] ?? null;
            if (!is_array($mundane)) {
                continue;
            }

            $rule = $ruleMap[$key] ?? ['assign_park' => 'seed', 'keep_global_admin' => false];
            if (!empty($rule['keep_global_admin'])) {
                $mundane['park_id'] = 0;
                $mundane['kingdom_id'] = 0;
            } elseif (($rule['assign_park'] ?? null) === 'seed' && $this->parks !== []) {
                $park = $this->parks[mt_rand(0, count($this->parks) - 1)];
                $mundane['park_id'] = $park['park_id'];
                $mundane['kingdom_id'] = $park['kingdom_id'];
            }

            $mundane['modified'] = $this->timestampValue($deterministic);
            $lines[] = $this->buildInsert(
                self::DB_PREFIX . 'mundane',
                $this->filterMundaneRow($mundane)
            );
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionFakePlayers(bool $deterministic): string
    {
        $pool = Json5::decodeFile($this->toolRoot . '/templates/stable/fake_players.json5');
        $personas = $pool['personas'] ?? [];
        if ($personas === []) {
            throw new ValidationException('Fake player persona pool is empty');
        }

        $lines = ["-- Section: fake players\n"];
        $personaIndex = 0;

        foreach ($this->parks as $park) {
            $targetCount = mt_rand(5, 25);
            for ($slot = 0; $slot < $targetCount; $slot++) {
                $persona = (string) $personas[$personaIndex % count($personas)];
                $personaIndex++;
                $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $persona) ?? 'player');
                $username = 'test-' . $slug . '-' . $this->nextFakeMundaneId;

                $player = [
                    'mundane_id' => $this->nextFakeMundaneId,
                    'given_name' => explode(' ', $persona)[0] ?? 'Test',
                    'surname' => explode(' ', $persona)[1] ?? 'Player',
                    'other_name' => '',
                    'username' => $username,
                    'persona' => $persona,
                    'email' => $slug . '@test.ork.local',
                    'park_id' => $park['park_id'],
                    'kingdom_id' => $park['kingdom_id'],
                    'token' => md5('token-' . $this->nextFakeMundaneId),
                    'modified' => $this->timestampValue($deterministic),
                    'restricted' => 0,
                    'waivered' => 1,
                    'waiver_ext' => '',
                    'has_heraldry' => 0,
                    'has_image' => 0,
                    'company_id' => 0,
                    'token_expires' => '2030-01-01 00:00:00',
                    'password_expires' => '2030-01-01 00:00:00',
                    'password_salt' => (string) ($pool['test_password_salt'] ?? 'test-db-player-salt'),
                    'xtoken' => '',
                    'penalty_box' => 0,
                    'active' => 1,
                ];
                $this->fakePlayers[] = $player;
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'mundane', $player);
                $this->nextFakeMundaneId++;
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionCredentialsAndAuth(bool $deterministic): string
    {
        $lines = ["-- Section: credentials and authorization\n"];

        foreach ($this->fakePlayers as $player) {
            $lines[] = $this->buildInsert(self::DB_PREFIX . 'credential', [
                'key' => $player['username'],
                'expiration' => '2030-01-01 00:00:00',
                'resetrequest' => 0,
            ]);
        }

        $realPath = $this->toolRoot . '/extracted/mundane_real.json';
        if (is_readable($realPath)) {
            $bundle = json_decode((string) file_get_contents($realPath), true);
            foreach ($bundle['players'] ?? [] as $player) {
                $key = (string) ($player['key'] ?? '');
                $mundane = $player['mundane'] ?? null;
                if (!is_array($mundane)) {
                    continue;
                }

                if (is_array($player['credential'] ?? null)) {
                    $lines[] = $this->buildInsert(self::DB_PREFIX . 'credential', $player['credential']);
                }

                foreach ($player['authorization'] ?? [] as $auth) {
                    if (!is_array($auth)) {
                        continue;
                    }
                    if ($key === 'admin') {
                        $auth['park_id'] = 0;
                        $auth['kingdom_id'] = 0;
                    }
                    $auth['modified'] = $this->timestampValue($deterministic);
                    $lines[] = $this->buildInsert(self::DB_PREFIX . 'authorization', $auth);
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionOfficers(bool $deterministic): string
    {
        $roles = ['Monarch', 'Regent', 'Prime Minister', 'Champion'];
        $lines = ["-- Section: officers\n"];
        $playerIndex = 0;

        foreach (Json5::decodeFile($this->toolRoot . '/templates/stable/kingdoms.json5')['kingdoms'] ?? [] as $kingdom) {
            $kingdomId = (int) $kingdom['id'];
            $kingdomPlayers = array_values(array_filter(
                $this->fakePlayers,
                static fn (array $player): bool => (int) $player['kingdom_id'] === $kingdomId
            ));
            if ($kingdomPlayers === []) {
                continue;
            }

            foreach ($roles as $role) {
                $player = $kingdomPlayers[$playerIndex % count($kingdomPlayers)];
                $playerIndex++;
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'officer', [
                    'officer_id' => $this->nextOfficerId++,
                    'kingdom_id' => $kingdomId,
                    'park_id' => 0,
                    'mundane_id' => (int) $player['mundane_id'],
                    'role' => $role,
                    'system' => 0,
                    'authorization_id' => 0,
                    'modified' => $this->timestampValue($deterministic),
                ]);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionMajorEvents(string $anchorDate, string $windowStart, bool $deterministic): string
    {
        $templates = Json5::decodeFile($this->toolRoot . '/templates/shifting/major_events.json5')['events'] ?? [];
        $extracted = $this->loadExtractedEvents();
        $lines = ["-- Section: major events\n"];
        $detailId = 1;
        $anchor = new \DateTimeImmutable($anchorDate);

        foreach ($templates as $template) {
            $name = (string) $template['name'];
            $sample = $this->findExtractedEventSample($extracted, $name);
            $occurrences = (int) ($template['occurrences_per_window'] ?? 1);
            $spacingMonths = (int) ($template['spacing_months'] ?? 12);
            $offsetDays = (int) ($template['offset_from_anchor_days'] ?? -30);

            for ($i = 0; $i < $occurrences; $i++) {
                $eventId = $this->nextEventId++;
                $this->events[] = ['event_id' => $eventId, 'kingdom_id' => 9001, 'name' => $name];

                $lines[] = $this->buildInsert(self::DB_PREFIX . 'event', [
                    'event_id' => $eventId,
                    'kingdom_id' => 9001,
                    'park_id' => $this->parks[0]['park_id'] ?? 9001001,
                    'mundane_id' => 0,
                    'unit_id' => 0,
                    'name' => $name,
                    'has_heraldry' => (int) ($sample['event']['has_heraldry'] ?? 0),
                    'modified' => $this->timestampValue($deterministic),
                ]);

                $start = $anchor->modify(($offsetDays - ($i * $spacingMonths * 30)) . ' days');
                if ($start < new \DateTimeImmutable($windowStart)) {
                    $start = new \DateTimeImmutable($windowStart);
                }
                $end = $start->modify('+3 days');

                $detail = $sample['calendardetails'][0] ?? [];
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'event_calendardetail', [
                    'event_calendardetail_id' => $detailId++,
                    'event_id' => $eventId,
                    'current' => 1,
                    'price' => (float) ($detail['price'] ?? 25.0),
                    'event_start' => $start->format('Y-m-d 10:00:00'),
                    'event_end' => $end->format('Y-m-d 18:00:00'),
                    'description' => (string) ($detail['description'] ?? $name . ' test occurrence'),
                    'url' => (string) ($detail['url'] ?? ''),
                    'url_name' => (string) ($detail['url_name'] ?? $name),
                    'address' => (string) ($detail['address'] ?? '1 Event Road'),
                    'province' => (string) ($detail['province'] ?? 'TS'),
                    'postal_code' => (string) ($detail['postal_code'] ?? '00000'),
                    'city' => (string) ($detail['city'] ?? 'Testville'),
                    'country' => (string) ($detail['country'] ?? 'USA'),
                    'map_url' => (string) ($detail['map_url'] ?? ''),
                    'map_url_name' => (string) ($detail['map_url_name'] ?? ''),
                    'google_geocode' => (string) ($detail['google_geocode'] ?? ''),
                    'location' => (string) ($detail['location'] ?? ''),
                    'modified' => $this->timestampValue($deterministic),
                ]);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionAttendance(string $anchorDate, string $windowStart, bool $deterministic): string
    {
        $classIds = $this->loadClassIds();
        if ($classIds === []) {
            $classIds = [1];
        }

        $lines = ["-- Section: attendance\n"];
        $anchor = new \DateTimeImmutable($anchorDate);
        $window = new \DateTimeImmutable($windowStart);

        foreach ($this->fakePlayers as $player) {
            $activeMonths = mt_rand(2, 12);
            for ($month = 0; $month < $activeMonths; $month++) {
                $date = $anchor->modify('-' . ($month * 30 + mt_rand(0, 20)) . ' days');
                if ($date < $window) {
                    continue;
                }

                $lines[] = $this->buildInsert(self::DB_PREFIX . 'attendance', [
                    'attendance_id' => $this->nextAttendanceId++,
                    'mundane_id' => (int) $player['mundane_id'],
                    'class_id' => $classIds[mt_rand(0, count($classIds) - 1)],
                    'date' => $date->format('Y-m-d'),
                    'park_id' => (int) $player['park_id'],
                    'kingdom_id' => (int) $player['kingdom_id'],
                    'event_id' => 0,
                    'event_calendardetail_id' => 0,
                    'credits' => 1.0,
                    'persona' => (string) $player['persona'],
                    'flavor' => '',
                    'note' => '',
                ]);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionKingdomAwards(bool $deterministic): string
    {
        $config = Json5::decodeFile($this->toolRoot . '/templates/stable/kingdom_awards.json5');
        $awardRows = $this->loadAwardRows();
        $lines = ["-- Section: kingdom awards\n"];

        foreach ($config['kingdom_ids'] ?? [9001, 9002, 9003, 9004, 9005] as $kingdomId) {
            $kingdomId = (int) $kingdomId;
            foreach ($awardRows as $award) {
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'kingdomaward', [
                    'kingdomaward_id' => $this->nextKingdomAwardId++,
                    'is_title' => (int) ($award['is_title'] ?? 0),
                    'title_class' => (int) ($award['title_class'] ?? 0),
                    'kingdom_id' => $kingdomId,
                    'award_id' => (int) $award['award_id'],
                    'name' => (string) $award['name'],
                    'reign_limit' => 0,
                    'month_limit' => 0,
                ]);
                $this->awards[] = [
                    'kingdomaward_id' => $this->nextKingdomAwardId - 1,
                    'kingdom_id' => $kingdomId,
                    'award_id' => (int) $award['award_id'],
                ];
            }

            $extraRange = $config['extras_per_kingdom'] ?? ['min' => 2, 'max' => 8];
            $extraCount = mt_rand((int) $extraRange['min'], (int) $extraRange['max']);
            $pool = $config['extra_name_pool'] ?? [];
            $usedExtraNames = [];
            for ($i = 0; $extraCount > 0 && $i < $extraCount * 3; $i++) {
                if (count($usedExtraNames) >= $extraCount || $pool === []) {
                    break;
                }

                $name = (string) $pool[mt_rand(0, count($pool) - 1)];
                if (isset($usedExtraNames[$name])) {
                    continue;
                }
                $usedExtraNames[$name] = true;

                $award = $awardRows[$i % count($awardRows)];
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'kingdomaward', [
                    'kingdomaward_id' => $this->nextKingdomAwardId++,
                    'is_title' => 0,
                    'title_class' => 0,
                    'kingdom_id' => $kingdomId,
                    'award_id' => (int) $award['award_id'],
                    'name' => $name,
                    'reign_limit' => 0,
                    'month_limit' => 0,
                ]);
            }
        }

        foreach ($this->fakePlayers as $player) {
            if (mt_rand(1, 100) > 10 || $this->awards === []) {
                continue;
            }
            $award = $this->awards[mt_rand(0, count($this->awards) - 1)];
            if ((int) $award['kingdom_id'] !== (int) $player['kingdom_id']) {
                continue;
            }
            $monthsAgo = mt_rand(1, 24);
            $date = (new \DateTimeImmutable('today'))->modify('-' . $monthsAgo . ' months');
            $lines[] = $this->buildInsert(self::DB_PREFIX . 'awards', [
                'awards_id' => $this->nextAwardsInstanceId++,
                'kingdomaward_id' => (int) $award['kingdomaward_id'],
                'mundane_id' => (int) $player['mundane_id'],
                'unit_id' => 0,
                'park_id' => (int) $player['park_id'],
                'kingdom_id' => (int) $player['kingdom_id'],
                'team_id' => 0,
                'rank' => 0,
                'date' => $date->format('Y-m-d'),
                'given_by_id' => 1,
                'note' => '',
                'at_park_id' => (int) $player['park_id'],
                'at_kingdom_id' => (int) $player['kingdom_id'],
                'at_event_id' => 0,
                'custom_name' => '',
                'award_id' => (int) $award['award_id'],
            ]);
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionConfiguration(bool $deterministic): string
    {
        $path = $this->toolRoot . '/extracted/configuration.sql';
        if (!is_readable($path)) {
            throw new ValidationException('Missing configuration extract. Run bin/ork-db extract first.');
        }

        $sampleKingdomId = (int) (($this->extractManifest['configuration_sample']['kingdom_ids'][0] ?? 1));
        $includeParkKeys = (bool) ($this->extractManifest['configuration_sample']['include_park_keys'] ?? true);
        $lines = ["-- Section: configuration\n"];

        $kingdomTemplates = [];
        $parkTemplates = [];
        foreach ($this->parseInsertLines((string) file_get_contents($path)) as $row) {
            if (($row['type'] ?? '') === 'Kingdom' && (int) ($row['id'] ?? 0) === $sampleKingdomId) {
                $key = (string) ($row['key'] ?? '');
                if ($key !== 'AccountPointers') {
                    $kingdomTemplates[$key] = $row;
                }
            }
            if ($includeParkKeys && ($row['type'] ?? '') === 'Park' && (int) ($row['id'] ?? 0) === 1) {
                $key = (string) ($row['key'] ?? '');
                if ($key !== 'AccountPointers') {
                    $parkTemplates[$key] = $row;
                }
            }
        }

        foreach (Json5::decodeFile($this->toolRoot . '/templates/stable/kingdoms.json5')['kingdoms'] ?? [] as $kingdom) {
            $kingdomId = (int) $kingdom['id'];
            foreach ($kingdomTemplates as $template) {
                $row = $template;
                $row['configuration_id'] = $this->nextConfigurationId++;
                $row['type'] = 'Kingdom';
                $row['id'] = $kingdomId;
                $row['modified'] = $this->timestampValue($deterministic);
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'configuration', $row);
            }
        }

        foreach ($this->parks as $park) {
            foreach ($parkTemplates as $template) {
                $row = $template;
                $row['configuration_id'] = $this->nextConfigurationId++;
                $row['type'] = 'Park';
                $row['id'] = (int) $park['park_id'];
                $row['modified'] = $this->timestampValue($deterministic);
                $lines[] = $this->buildInsert(self::DB_PREFIX . 'configuration', $row);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function sectionCanary(bool $deterministic): string
    {
        return implode("\n", [
            '-- Section: canary',
            'CREATE TABLE IF NOT EXISTS _ork_canary_test (',
            '  id INT PRIMARY KEY,',
            '  marker VARCHAR(64) NOT NULL,',
            '  created_at DATETIME NOT NULL',
            ');',
            $this->buildInsert('_ork_canary_test', [
                'id' => 1,
                'marker' => self::TEST_CANARY_MARKER,
                'created_at' => $this->timestampValue($deterministic),
            ]),
            '',
        ]);
    }

    /** @return list<string> */
    private function fixedExtractTables(): array
    {
        return array_map('strval', $this->extractManifest['fixed_extract'] ?? []);
    }

    /** @return list<string> */
    private function fixedEmbeddedTables(): array
    {
        return array_map('strval', $this->extractManifest['fixed_embedded'] ?? []);
    }

    /** @return list<array<string, mixed>> */
    private function loadExtractedEvents(): array
    {
        $path = $this->toolRoot . '/extracted/events.json';
        if (!is_readable($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? ($decoded['events'] ?? []) : [];
    }

    /**
     * @param list<array<string, mixed>> $events
     * @return array{event: array<string, mixed>, calendardetails: list<array<string, mixed>>}
     */
    private function findExtractedEventSample(array $events, string $name): array
    {
        foreach ($events as $event) {
            $eventName = (string) ($event['event']['name'] ?? '');
            if (stripos($eventName, $name) !== false) {
                return [
                    'event' => $event['event'] ?? [],
                    'calendardetails' => $event['calendardetails'] ?? [],
                ];
            }
        }

        return ['event' => [], 'calendardetails' => []];
    }

    /** @return list<int> */
    private function loadClassIds(): array
    {
        $path = $this->toolRoot . '/extracted/class.sql';
        if (!is_readable($path)) {
            return [];
        }

        $ids = [];
        foreach ($this->parseInsertLines((string) file_get_contents($path)) as $row) {
            if (isset($row['class_id'])) {
                $ids[] = (int) $row['class_id'];
            }
        }

        return $ids;
    }

    /** @return list<array{award_id: int, name: string, is_title: int, title_class: int}> */
    private function loadAwardRows(): array
    {
        $path = $this->toolRoot . '/extracted/award.sql';
        if (!is_readable($path)) {
            throw new ValidationException('Missing award extract. Run bin/ork-db extract first.');
        }

        $rows = [];
        foreach ($this->parseInsertLines((string) file_get_contents($path)) as $row) {
            if (!isset($row['award_id'], $row['name'])) {
                continue;
            }
            $rows[] = [
                'award_id' => (int) $row['award_id'],
                'name' => (string) $row['name'],
                'is_title' => (int) ($row['is_title'] ?? 0),
                'title_class' => (int) ($row['title_class'] ?? 0),
            ];
        }

        if ($rows === []) {
            throw new ValidationException('Award extract contains no rows');
        }

        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseInsertLines(string $sql): array
    {
        $rows = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $line = trim($line);
            if (!str_starts_with($line, 'INSERT INTO')) {
                continue;
            }

            if (!preg_match('/INSERT INTO `([^`]+)` \(([^)]+)\) VALUES \((.+)\);$/', $line, $matches)) {
                continue;
            }

            $columns = array_map(
                static fn (string $column): string => trim($column, " `\t"),
                explode(',', $matches[2])
            );
            $values = $this->parseSqlValues($matches[3]);
            if (count($columns) !== count($values)) {
                continue;
            }

            $rows[] = array_combine($columns, $values);
        }

        return $rows;
    }

    /** @return list<scalar|null> */
    private function parseSqlValues(string $valueList): array
    {
        $values = [];
        $current = '';
        $inString = false;
        $escaped = false;
        $depth = 0;

        for ($i = 0, $length = strlen($valueList); $i < $length; $i++) {
            $char = $valueList[$i];
            if ($escaped) {
                $current .= $char;
                $escaped = false;
                continue;
            }

            if ($char === '\\') {
                $current .= $char;
                $escaped = true;
                continue;
            }

            if ($char === "'" && $depth === 0) {
                $inString = !$inString;
                $current .= $char;
                continue;
            }

            if (!$inString) {
                if ($char === '(') {
                    $depth++;
                } elseif ($char === ')') {
                    $depth--;
                } elseif ($char === ',' && $depth === 0) {
                    $values[] = $this->decodeSqlValue(trim($current));
                    $current = '';
                    continue;
                }
            }

            $current .= $char;
        }

        if ($current !== '') {
            $values[] = $this->decodeSqlValue(trim($current));
        }

        return $values;
    }

    private function decodeSqlValue(string $value): string|int|float|null
    {
        if (strtoupper($value) === 'NULL') {
            return null;
        }

        if (preg_match("/^'(.*)'$/s", $value, $matches)) {
            return str_replace(["\\'", '\\"', '\\\\'], ["'", '"', '\\'], $matches[1]);
        }

        if (is_numeric($value)) {
            return str_contains($value, '.') ? (float) $value : (int) $value;
        }

        return $value;
    }

    /** @param array<string, mixed> $row */
    private function filterMundaneRow(array $row): array
    {
        $filtered = [];
        foreach (self::MUNDANE_COLUMNS as $column) {
            if (array_key_exists($column, $row)) {
                $filtered[$column] = $row[$column];
            }
        }

        return $filtered;
    }

    /** @param array<string, mixed> $row */
    private function buildInsert(string $table, array $row): string
    {
        $columns = array_keys($row);
        $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $values = [];
        foreach ($columns as $column) {
            $values[] = $this->sqlLiteral($row[$column]);
        }

        return 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], (string) $value) . "'";
    }

    private function timestampValue(bool $deterministic): string
    {
        return $deterministic ? '2026-01-01 00:00:00' : gmdate('Y-m-d H:i:s');
    }

    private function stripSqlComments(string $sql): string
    {
        $lines = [];
        foreach (preg_split('/\R/', $sql) ?: [] as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '--')) {
                continue;
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }
}
