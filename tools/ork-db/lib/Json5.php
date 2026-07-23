<?php

declare(strict_types=1);

namespace OrkDb;

final class Json5
{
    public static function decodeFile(string $path): array
    {
        if (!is_readable($path)) {
            throw new \RuntimeException("Manifest not readable: {$path}");
        }

        $text = file_get_contents($path);
        if ($text === false) {
            throw new \RuntimeException("Manifest not readable: {$path}");
        }

        return self::decode($text);
    }

    public static function decode(string $text): array
    {
        $stripped = preg_replace('#/\*.*?\*/#s', '', $text);
        if ($stripped === null) {
            throw new \RuntimeException('Failed to strip block comments from JSON5');
        }

        $stripped = preg_replace('#//[^\n\r]*#', '', $stripped);
        if ($stripped === null) {
            throw new \RuntimeException('Failed to strip line comments from JSON5');
        }

        $decoded = json_decode($stripped, true);
        if (!is_array($decoded)) {
            throw new \RuntimeException('Invalid JSON5: ' . json_last_error_msg());
        }

        return $decoded;
    }
}
