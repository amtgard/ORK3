<?php

declare(strict_types=1);

namespace OrkDb;

use PDO;

final class SchemaIntrospection
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
    ) {
    }

    /** @return list<string> */
    public function tableNames(): array
    {
        $statement = $this->pdo->query('SHOW TABLES');
        if ($statement === false) {
            throw new \RuntimeException('Failed to list tables');
        }

        $tables = [];
        while ($row = $statement->fetch(PDO::FETCH_NUM)) {
            $name = (string) $row[0];
            if ($this->isIgnoredTable($name)) {
                continue;
            }
            $tables[] = $name;
        }

        sort($tables, SORT_STRING);

        return $tables;
    }

    /** @return array<string, string> table => normalized CREATE TABLE */
    public function createTableMap(): array
    {
        $map = [];
        foreach ($this->tableNames() as $table) {
            $map[$table] = $this->createTableStatement($table);
        }

        return $map;
    }

    public function fingerprint(): string
    {
        $parts = [];
        foreach ($this->createTableMap() as $table => $ddl) {
            $parts[] = $table . "\n" . $ddl;
        }

        return 'sha256:' . hash('sha256', implode("\n\n", $parts));
    }

    public function createTableStatement(string $table): string
    {
        $quoted = str_replace('`', '``', $table);
        $statement = $this->pdo->query("SHOW CREATE TABLE `{$quoted}`");
        if ($statement === false) {
            throw new \RuntimeException("Failed to read DDL for {$table}");
        }

        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) {
            throw new \RuntimeException("Missing DDL row for {$table}");
        }

        $ddl = (string) ($row['Create Table'] ?? $row['Create View'] ?? '');
        if ($ddl === '') {
            throw new \RuntimeException("Empty DDL for {$table}");
        }

        return self::normalizeCreateTable($ddl);
    }

    public static function normalizeCreateTable(string $ddl): string
    {
        $ddl = str_replace("\r\n", "\n", $ddl);
        $ddl = preg_replace('/ AUTO_INCREMENT=\d+/', '', $ddl) ?? $ddl;
        $ddl = preg_replace('/\/\*![0-9]+ /', '/* ', $ddl) ?? $ddl;
        $ddl = preg_replace("/ NOT NULL DEFAULT ''/", ' NOT NULL', $ddl) ?? $ddl;
        $ddl = preg_replace("/ NOT NULL DEFAULT '1970-01-01 00:00:00'/", ' NOT NULL', $ddl) ?? $ddl;
        $ddl = preg_replace('/ NOT NULL DEFAULT 0(?=[,\n])/', ' NOT NULL', $ddl) ?? $ddl;
        $ddl = preg_replace('/\s+ENGINE=\w+/', ' ENGINE=InnoDB', $ddl) ?? $ddl;

        return trim($ddl);
    }

    public static function hashFileContents(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Failed to read file: {$path}");
        }

        return 'sha256:' . hash('sha256', $contents);
    }

    private function isIgnoredTable(string $name): bool
    {
        if (str_starts_with($name, '_ork_canary_')) {
            return true;
        }

        if ($name === 'bak_awards') {
            return true;
        }

        return str_ends_with($name, '_myisam');
    }
}
