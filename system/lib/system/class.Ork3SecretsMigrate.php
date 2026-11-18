<?php

declare(strict_types=1);

/**
 * Strategy: extract vault inject keys from legacy config.php defines and optional .env.
 */
final class Ork3SecretsMigrate
{
    public const PROFILE_NAME = 'ork-web';

    /**
     * @var list<string>
     */
    public const INJECT_KEYS = [
        'DB_PASSWORD',
        'IDP_CLIENT_SECRET',
        'IDP_API_KEY',
        'SENDGRID_API_KEY',
        'GOOGLE_MAPS_API_KEY',
        'GOOGLE_MAPS_ACCESS_API_KEY',
        'BEHOLD_KEY',
        'CF_API_TOKEN',
        'CARTO_API_KEY',
        'AMAZON_SES_USERNAME',
        'AMAZON_SES_PASSWORD',
    ];

    /**
     * @return array<string, string> inject key => value (empty string when unset)
     */
    public function collectInjectValues(string $configPhpPath, ?string $devEnvPath = null): array
    {
        $values = $this->extractDefinesFromConfig($configPhpPath);
        if ($devEnvPath !== null && $devEnvPath !== '') {
            $values = $this->mergeEnvOverrides($values, $devEnvPath);
        }

        return $this->filterInjectKeys($values);
    }

    /**
     * @param array<string, string> $values
     */
    public function writeProfileEnv(string $outputPath, array $values): void
    {
        $lines = [];
        foreach (self::INJECT_KEYS as $key) {
            $lines[] = $key . '=' . ($values[$key] ?? '');
        }

        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        file_put_contents($outputPath, implode("\n", $lines) . "\n");
    }

    /**
     * @return array<string, string>
     */
    private function extractDefinesFromConfig(string $configPhpPath): array
    {
        if (!is_readable($configPhpPath)) {
            return [];
        }

        $raw = file_get_contents($configPhpPath);
        $content = $raw === false ? '' : $raw;
        if ($content === '') {
            return [];
        }

        $found = [];
        foreach (self::INJECT_KEYS as $key) {
            $value = $this->parseDefineValue($content, $key);
            if ($value !== null) {
                $found[$key] = $value;
            }
        }

        return $found;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function mergeEnvOverrides(array $values, string $devEnvPath): array
    {
        if (!is_readable($devEnvPath)) {
            return $values;
        }

        foreach ($this->parseEnvFile($devEnvPath) as $key => $value) {
            if (!in_array($key, self::INJECT_KEYS, true)) {
                continue;
            }
            if ($value !== '') {
                $values[$key] = $value;
            }
        }

        return $values;
    }

    /**
     * @param array<string, string> $values
     *
     * @return array<string, string>
     */
    private function filterInjectKeys(array $values): array
    {
        $out = [];
        foreach (self::INJECT_KEYS as $key) {
            $out[$key] = $values[$key] ?? '';
        }

        return $out;
    }

    private function parseDefineValue(string $content, string $key): ?string
    {
        $pattern = '/define\s*\(\s*[\'"]' . preg_quote($key, '/') . '[\'"]\s*,\s*/';
        if (!preg_match($pattern, $content, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }

        $pos = $m[0][1] + strlen($m[0][0]);
        $slice = substr($content, $pos);
        if ($slice === false || $slice === '') {
            return null;
        }

        $quote = $slice[0];
        if ($quote !== "'" && $quote !== '"') {
            return null;
        }

        return $this->readQuotedPhpString($slice, $quote);
    }

    private function readQuotedPhpString(string $slice, string $quote): ?string
    {
        $len = strlen($slice);
        $out = '';
        for ($i = 1; $i < $len; ++$i) {
            $ch = $slice[$i];
            if ($ch === '\\' && $i + 1 < $len) {
                $next = $slice[$i + 1];
                if ($quote === "'" && ($next === "'" || $next === '\\')) {
                    $out .= $next;
                    ++$i;
                    continue;
                }
                if ($quote === '"') {
                    $out .= $this->interpretEscape($next);
                    ++$i;
                    continue;
                }
            }
            if ($ch === $quote) {
                return $out;
            }
            $out .= $ch;
        }

        return null;
    }

    private function interpretEscape(string $char): string
    {
        return match ($char) {
            'n' => "\n",
            'r' => "\r",
            't' => "\t",
            '\\' => '\\',
            '"' => '"',
            default => $char,
        };
    }

    /**
     * @return array<string, string>
     */
    private function parseEnvFile(string $path): array
    {
        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            return [];
        }

        $out = [];
        foreach ($lines as $line) {
            $parsed = $this->parseEnvLine($line);
            if ($parsed !== null) {
                $out[$parsed['key']] = $parsed['value'];
            }
        }

        return $out;
    }

    /**
     * @return array{key: string, value: string}|null
     */
    private function parseEnvLine(string $line): ?array
    {
        $trimmed = ltrim($line);
        if ($trimmed === '' || str_starts_with($trimmed, '#')) {
            return null;
        }

        if (str_starts_with($trimmed, 'export ')) {
            $trimmed = ltrim(substr($trimmed, 7));
        }

        if (!preg_match('/^([A-Za-z_][A-Za-z0-9_]*)=(.*)$/', $trimmed, $m)) {
            return null;
        }

        return [
            'key' => $m[1],
            'value' => $this->stripEnvQuotes($m[2]),
        ];
    }

    private function stripEnvQuotes(string $raw): string
    {
        if ($raw === '') {
            return '';
        }

        $first = $raw[0];
        $last = $raw[strlen($raw) - 1];
        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            $inner = substr($raw, 1, -1);

            return $first === '"' ? $this->expandEnvDoubleQuotes($inner) : $inner;
        }

        return $raw;
    }

    private function expandEnvDoubleQuotes(string $inner): string
    {
        return (string) preg_replace_callback(
            '/\\\\([\\\\n"])/',
            static fn (array $m): string => match ($m[1]) {
                'n' => "\n",
                '\\' => '\\',
                '"' => '"',
                default => $m[1],
            },
            $inner
        );
    }
}
