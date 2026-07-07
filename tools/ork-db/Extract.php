<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;
use PDOException;

final class Extract
{
    private const DB_PREFIX = 'ork_';
    private const PROD_CANARY_MARKER = 'ORK3_PROD_CANARY_v1';

    /** @var array<string, mixed> */
    private array $manifest;

    /** @var (callable(): PDO)|null */
    private $connectionFactory;

    public function __construct(
        private readonly Wiring $wiring,
        private readonly string $toolRoot,
        $connectionFactory = null,
    ) {
        $this->manifest = Json5::decodeFile($toolRoot . '/manifests/extract-sources.json5');
        $this->connectionFactory = $connectionFactory;
    }

    /**
     * @param array{table?: string|null, players_only?: bool} $options
     * @return array{files: list<string>, warnings: list<string>, source: string}
     */
    public function run(array $options = []): array
    {
        $mirror = $this->wiring->mirror();
        $host = (string) $mirror['host'];
        $port = (int) $mirror['port'];
        $database = (string) $mirror['database'];

        $this->wiring->assertMirrorEndpoint($host, $port, $database);

        $pdo = $this->connectMirror();
        $this->assertMirrorInitialized($pdo);

        $outputDir = $this->toolRoot . '/extracted';
        if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
            throw new \RuntimeException("Failed to create extract output directory: {$outputDir}");
        }

        $warnings = [];
        $written = [];
        $playersOnly = (bool) ($options['players_only'] ?? false);
        $singleTable = isset($options['table']) ? (string) $options['table'] : null;

        if ($singleTable !== null && $singleTable !== '') {
            $written[] = $this->extractVerbatimTable($pdo, $singleTable, $outputDir);

            return ['files' => $written, 'warnings' => $warnings, 'source' => $this->wiring->mirrorTargetLabel()];
        }

        if ($playersOnly) {
            $written[] = $this->extractRealPlayers($pdo, $outputDir, $warnings);

            return ['files' => $written, 'warnings' => $warnings, 'source' => $this->wiring->mirrorTargetLabel()];
        }

        foreach ($this->fixedExtractTables() as $table) {
            $written[] = $this->extractVerbatimTable($pdo, (string) $table, $outputDir);
        }

        $written[] = $this->extractConfiguration($pdo, $outputDir);
        $written[] = $this->extractRealPlayers($pdo, $outputDir, $warnings);
        $written[] = $this->extractEvents($pdo, $outputDir);
        $kingdomAwardFile = $this->extractKingdomAwardClone($pdo, $outputDir);
        if ($kingdomAwardFile !== null) {
            $written[] = $kingdomAwardFile;
        }

        $manifestPath = $outputDir . '/manifest.json';
        $this->writeFile(
            $manifestPath,
            json_encode(
                [
                    'source' => $this->wiring->mirrorTargetLabel(),
                    'extracted_at' => gmdate('c'),
                    'files' => array_map(static fn (string $path): string => basename($path), $written),
                    'warnings' => $warnings,
                ],
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
            ) . "\n"
        );
        $written[] = $manifestPath;

        return ['files' => $written, 'warnings' => $warnings, 'source' => $this->wiring->mirrorTargetLabel()];
    }

    public function connectMirror(): PDO
    {
        if ($this->connectionFactory !== null) {
            return ($this->connectionFactory)();
        }

        $credentials = $this->wiring->credentials();

        return new PDO(
            $this->wiring->mirrorDsn(),
            $credentials['user'],
            $credentials['password'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]
        );
    }

    private function assertMirrorInitialized(PDO $pdo): void
    {
        if (!$this->tableExists($pdo, '_ork_canary_prod')) {
            throw new ValidationException(
                'Mirror prod canary missing — mirror may be uninitialized. '
                . 'Apply db-migrations/2026-07-07-add-prod-canary.sql to local ork first.'
            );
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM _ork_canary_prod WHERE id = 1 AND marker = :marker'
        );
        $stmt->execute(['marker' => self::PROD_CANARY_MARKER]);
        if ((int) $stmt->fetchColumn() !== 1) {
            throw new ValidationException(
                'Mirror prod canary invalid — refusing extract from uninitialized mirror.'
            );
        }
    }

    private function extractVerbatimTable(PDO $pdo, string $table, string $outputDir): string
    {
        $allowed = $this->fixedExtractTables();
        if (!in_array($table, $allowed, true)) {
            throw new ValidationException(
                "Unknown extract table '{$table}'. Allowed: " . implode(', ', $allowed)
            );
        }

        $fullTable = self::DB_PREFIX . $table;
        if (!$this->tableExists($pdo, $fullTable)) {
            throw new ValidationException("Mirror table missing: {$fullTable}");
        }

        $sql = $this->buildTableDump($pdo, $fullTable);
        $path = $outputDir . '/' . $table . '.sql';
        $this->writeFile($path, $sql);

        return $path;
    }

    private function extractConfiguration(PDO $pdo, string $outputDir): string
    {
        $fullTable = self::DB_PREFIX . 'configuration';
        if (!$this->tableExists($pdo, $fullTable)) {
            throw new ValidationException('Mirror table missing: ' . $fullTable);
        }

        $keys = $this->manifest['configuration_keys'] ?? ['*'];
        $sql = $this->buildTableDump(
            $pdo,
            $fullTable,
            $keys === ['*'] ? null : $keys
        );
        $path = $outputDir . '/configuration.sql';
        $this->writeFile($path, $sql);

        return $path;
    }

    /**
     * @param list<string> $warnings
     */
    private function extractRealPlayers(PDO $pdo, string $outputDir, array &$warnings): string
    {
        $mundaneReal = $this->manifest['mundane_real'] ?? [];
        $players = [];

        foreach ($mundaneReal['by_username'] ?? [] as $username) {
            $row = $this->fetchMundaneByUsername($pdo, (string) $username);
            if ($row === null) {
                $warnings[] = "Real player username not found in mirror: {$username}";

                continue;
            }

            $players[] = $this->buildPlayerBundle($pdo, (string) $username, $row);
        }

        foreach ($mundaneReal['by_mundane_id'] ?? [] as $key => $mundaneId) {
            if ($mundaneId === null) {
                $warnings[] = "Real player mundane_id not configured: {$key}";

                continue;
            }

            $row = $this->fetchMundaneById($pdo, (int) $mundaneId);
            if ($row === null) {
                $warnings[] = "Real player mundane_id not found in mirror: {$key} ({$mundaneId})";

                continue;
            }

            $players[] = $this->buildPlayerBundle($pdo, (string) $key, $row);
        }

        $path = $outputDir . '/mundane_real.json';
        $this->writeFile(
            $path,
            json_encode(['players' => $players], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $path;
    }

    /** @return array<string, mixed>|null */
    private function fetchMundaneByUsername(PDO $pdo, string $username): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::DB_PREFIX . 'mundane WHERE username = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return array<string, mixed>|null */
    private function fetchMundaneById(PDO $pdo, int $mundaneId): ?array
    {
        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::DB_PREFIX . 'mundane WHERE mundane_id = :mundane_id LIMIT 1'
        );
        $stmt->execute(['mundane_id' => $mundaneId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @param array<string, mixed> $mundane */
    private function buildPlayerBundle(PDO $pdo, string $key, array $mundane): array
    {
        $mundaneId = (int) $mundane['mundane_id'];
        $username = (string) $mundane['username'];

        return [
            'key' => $key,
            'mundane' => $mundane,
            'credential' => $this->fetchCredential($pdo, $username),
            'authorization' => $this->fetchAuthorization($pdo, $mundaneId),
            'mundane_design' => $this->fetchMundaneDesign($pdo, $mundaneId),
        ];
    }

    /** @return array<string, mixed>|null */
    private function fetchCredential(PDO $pdo, string $username): ?array
    {
        if (!$this->tableExists($pdo, self::DB_PREFIX . 'credential')) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::DB_PREFIX . 'credential WHERE `key` = :username LIMIT 1'
        );
        $stmt->execute(['username' => $username]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    /** @return list<array<string, mixed>> */
    private function fetchAuthorization(PDO $pdo, int $mundaneId): array
    {
        if (!$this->tableExists($pdo, self::DB_PREFIX . 'authorization')) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::DB_PREFIX . 'authorization WHERE mundane_id = :mundane_id'
        );
        $stmt->execute(['mundane_id' => $mundaneId]);

        return $stmt->fetchAll();
    }

    /** @return array<string, mixed>|null */
    private function fetchMundaneDesign(PDO $pdo, int $mundaneId): ?array
    {
        if (!$this->tableExists($pdo, self::DB_PREFIX . 'mundane_design')) {
            return null;
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . self::DB_PREFIX . 'mundane_design WHERE mundane_id = :mundane_id LIMIT 1'
        );
        $stmt->execute(['mundane_id' => $mundaneId]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function extractEvents(PDO $pdo, string $outputDir): string
    {
        $patterns = array_map('strval', $this->manifest['events_by_name_like'] ?? []);
        $events = [];

        if (!$this->tableExists($pdo, self::DB_PREFIX . 'event')) {
            $path = $outputDir . '/events.json';
            $this->writeFile($path, json_encode(['events' => []], JSON_PRETTY_PRINT) . "\n");

            return $path;
        }

        foreach ($patterns as $pattern) {
            $stmt = $pdo->prepare(
                'SELECT * FROM ' . self::DB_PREFIX . 'event WHERE name LIKE :pattern ORDER BY event_id'
            );
            $stmt->execute(['pattern' => $pattern]);
            foreach ($stmt->fetchAll() as $event) {
                $eventId = (int) $event['event_id'];
                $events[] = [
                    'match_pattern' => $pattern,
                    'event' => $event,
                    'calendardetails' => $this->fetchCalendarDetails($pdo, $eventId),
                ];
            }
        }

        $path = $outputDir . '/events.json';
        $this->writeFile(
            $path,
            json_encode(['events' => $events], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n"
        );

        return $path;
    }

    /** @return list<array<string, mixed>> */
    private function fetchCalendarDetails(PDO $pdo, int $eventId): array
    {
        if (!$this->tableExists($pdo, self::DB_PREFIX . 'event_calendardetail')) {
            return [];
        }

        $stmt = $pdo->prepare(
            'SELECT event_calendardetail_id, event_id, current, price, description, url, url_name, '
            . 'address, province, postal_code, city, country, map_url, map_url_name, google_geocode, location '
            . 'FROM ' . self::DB_PREFIX . 'event_calendardetail WHERE event_id = :event_id ORDER BY event_calendardetail_id'
        );
        $stmt->execute(['event_id' => $eventId]);

        return $stmt->fetchAll();
    }

    private function extractKingdomAwardClone(PDO $pdo, string $outputDir): ?string
    {
        $kingdomId = $this->manifest['kingdomaward_clone_source_kingdom_id'] ?? null;
        if ($kingdomId === null) {
            return null;
        }

        $fullTable = self::DB_PREFIX . 'kingdomaward';
        if (!$this->tableExists($pdo, $fullTable)) {
            throw new ValidationException('Mirror table missing: ' . $fullTable);
        }

        $stmt = $pdo->prepare(
            'SELECT * FROM ' . $fullTable . ' WHERE kingdom_id = :kingdom_id ORDER BY kingdomaward_id'
        );
        $stmt->execute(['kingdom_id' => (int) $kingdomId]);
        $rows = $stmt->fetchAll();

        $columns = $rows !== [] ? array_keys($rows[0]) : $this->getColumns($pdo, $fullTable);
        $sql = $this->renderInsertStatements($pdo, $fullTable, $columns, $rows, 'kingdomaward clone source');
        $path = $outputDir . '/kingdomaward.sql';
        $this->writeFile($path, $sql);

        return $path;
    }

    /**
     * @param list<string>|null $configurationKeys
     */
    private function buildTableDump(PDO $pdo, string $fullTable, ?array $configurationKeys = null): string
    {
        $columns = $this->getColumns($pdo, $fullTable);
        $query = 'SELECT * FROM `' . $fullTable . '`';
        $params = [];

        if ($configurationKeys !== null && $configurationKeys !== []) {
            $placeholders = implode(',', array_fill(0, count($configurationKeys), '?'));
            $query .= " WHERE `key` IN ({$placeholders})";
            $params = $configurationKeys;
        }

        $query .= ' ORDER BY 1';
        $stmt = $pdo->prepare($query);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        return $this->renderInsertStatements($pdo, $fullTable, $columns, $rows, $this->wiring->mirrorTargetLabel());
    }

    /**
     * @param list<string> $columns
     * @param list<array<string, mixed>> $rows
     */
    private function renderInsertStatements(
        PDO $pdo,
        string $fullTable,
        array $columns,
        array $rows,
        string $sourceLabel,
    ): string {
        $lines = [
            '-- ORK3 Test Database Extract',
            '-- source: ' . $sourceLabel,
            '-- table: ' . $fullTable,
            '-- rows: ' . count($rows),
            '',
        ];

        foreach ($rows as $row) {
            $lines[] = $this->buildInsert($pdo, $fullTable, $columns, $row);
        }

        return implode("\n", $lines) . "\n";
    }

    /** @param list<string> $columns */
    private function buildInsert(PDO $pdo, string $table, array $columns, array $row): string
    {
        $columnSql = implode(', ', array_map(static fn (string $column): string => '`' . $column . '`', $columns));
        $values = [];
        foreach ($columns as $column) {
            $values[] = $this->sqlLiteral($pdo, $row[$column] ?? null);
        }

        return 'INSERT INTO `' . $table . '` (' . $columnSql . ') VALUES (' . implode(', ', $values) . ');';
    }

    private function sqlLiteral(PDO $pdo, mixed $value): string
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

        return $pdo->quote((string) $value);
    }

    /** @return list<string> */
    private function getColumns(PDO $pdo, string $fullTable): array
    {
        $stmt = $pdo->query('SELECT * FROM `' . $fullTable . '` LIMIT 0');
        if ($stmt === false) {
            throw new ValidationException('Failed to describe table: ' . $fullTable);
        }

        $count = $stmt->columnCount();
        $columns = [];
        for ($i = 0; $i < $count; $i++) {
            $meta = $stmt->getColumnMeta($i);
            if ($meta !== false && isset($meta['name'])) {
                $columns[] = (string) $meta['name'];
            }
        }

        if ($columns === []) {
            throw new ValidationException('No columns found for table: ' . $fullTable);
        }

        return $columns;
    }

    private function tableExists(PDO $pdo, string $table): bool
    {
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = :table"
            );
            $stmt->execute(['table' => $table]);

            return (int) $stmt->fetchColumn() > 0;
        }

        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.tables
             WHERE table_schema = DATABASE() AND table_name = :table'
        );
        $stmt->execute(['table' => $table]);

        return (int) $stmt->fetchColumn() > 0;
    }

    /** @return list<string> */
    private function fixedExtractTables(): array
    {
        $tables = $this->manifest['fixed_extract'] ?? $this->manifest['tables_verbatim'] ?? [];

        return array_map('strval', $tables);
    }

    private function writeFile(string $path, string $contents): void
    {
        if (file_put_contents($path, $contents) === false) {
            throw new \RuntimeException("Failed to write extract file: {$path}");
        }
    }
}
