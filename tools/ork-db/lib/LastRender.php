<?php

declare(strict_types=1);

namespace OrkDb;

final class LastRender
{
    public const TIMEZONE = 'America/Chicago';

    public static function metadataPath(string $toolRoot): string
    {
        return rtrim($toolRoot, '/') . '/rendered/.last-render.json';
    }

    /**
     * @return array{anchor_date: string, rendered_at: string, content_seed: int}|null
     */
    public static function read(string $toolRoot): ?array
    {
        $path = self::metadataPath($toolRoot);
        if (!is_readable($path)) {
            return null;
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return null;
        }

        if (!isset($decoded['anchor_date'], $decoded['rendered_at'], $decoded['content_seed'])) {
            return null;
        }

        return [
            'anchor_date' => (string) $decoded['anchor_date'],
            'rendered_at' => (string) $decoded['rendered_at'],
            'content_seed' => (int) $decoded['content_seed'],
        ];
    }

    public static function write(string $toolRoot, string $anchorDate, int $contentSeed): void
    {
        $dir = dirname(self::metadataPath($toolRoot));
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException("Failed to create render metadata directory: {$dir}");
        }

        $payload = [
            'anchor_date' => $anchorDate,
            'rendered_at' => (new \DateTimeImmutable('now', new \DateTimeZone(self::TIMEZONE)))->format('c'),
            'content_seed' => $contentSeed,
        ];

        $encoded = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            throw new \RuntimeException('Failed to encode last-render metadata');
        }

        if (file_put_contents(self::metadataPath($toolRoot), $encoded . "\n") === false) {
            throw new \RuntimeException('Failed to write last-render metadata');
        }
    }

    public static function isStale(
        string $toolRoot,
        bool $forceRefresh = false,
        ?\DateTimeImmutable $clock = null,
    ): bool {
        if ($forceRefresh) {
            return true;
        }

        $metadata = self::read($toolRoot);
        if ($metadata === null) {
            return true;
        }

        $timezone = new \DateTimeZone(self::TIMEZONE);
        $today = ($clock ?? new \DateTimeImmutable('now', $timezone))->setTimezone($timezone)->format('Y-m-d');

        return $metadata['anchor_date'] < $today;
    }

    public static function parseAnchorDateFromSql(string $sqlPath): ?string
    {
        return self::parseSqlHeaderField($sqlPath, 'anchor_date');
    }

    public static function parseContentSeedFromSql(string $sqlPath): ?int
    {
        $value = self::parseSqlHeaderField($sqlPath, 'content_seed');

        return $value !== null ? (int) $value : null;
    }

    private static function parseSqlHeaderField(string $sqlPath, string $field): ?string
    {
        if (!is_readable($sqlPath)) {
            return null;
        }

        $handle = fopen($sqlPath, 'r');
        if ($handle === false) {
            return null;
        }

        $pattern = '/^-- ' . preg_quote($field, '/') . ':\s*(.+)$/';
        try {
            for ($line = 0; $line < 20 && ($row = fgets($handle)) !== false; $line++) {
                if (preg_match($pattern, rtrim($row), $matches) === 1) {
                    return trim($matches[1]);
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }
}
