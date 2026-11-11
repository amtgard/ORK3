<?php

declare(strict_types=1);

/**
 * Parser for shell-style KEY=VALUE env files (dotenv subset, no Composer).
 */
class EnvFile
{
    /** @var list<array{level: string, event: string, detail?: string}> */
    private array $trace = [];

    public function loadFromFile(string $path, bool $fillUnsetOnly = true): void
    {
        if (!is_file($path)) {
            $this->recordEvent('env_file_missing');

            return;
        }

        if (!is_readable($path)) {
            $this->recordEvent('env_file_unreadable');

            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];

        foreach ($lines as $line) {
            $this->applyLine($line, $fillUnsetOnly);
        }
    }

    /**
     * @return list<array{level: string, event: string, detail?: string}>
     */
    public function getTrace(): array
    {
        return $this->trace;
    }

    private function applyLine(string $line, bool $fillUnsetOnly): void
    {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            return;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, 7));
        }

        $equalsAt = strpos($line, '=');
        if ($equalsAt === false) {
            return;
        }

        $key = substr($line, 0, $equalsAt);
        if (!$this->isValidKey($key)) {
            return;
        }

        $rawValue = substr($line, $equalsAt + 1);
        $value = $this->parseValue($rawValue);

        if ($fillUnsetOnly && $this->isKeyAlreadySet($key)) {
            $this->recordKeyEvent('env_key_kept', $key);

            return;
        }

        $this->assignKey($key, $value);
        $this->recordKeyEvent('env_key_set', $key);
    }

    private function isValidKey(string $key): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key);
    }

    private function parseValue(string $rawValue): string
    {
        if ($rawValue === '') {
            return '';
        }

        $first = $rawValue[0];
        if ($first === "'") {
            return $this->parseSingleQuotedValue($rawValue);
        }

        if ($first === '"') {
            return $this->parseDoubleQuotedValue($rawValue);
        }

        return $rawValue;
    }

    private function parseSingleQuotedValue(string $rawValue): string
    {
        $length = strlen($rawValue);
        if ($length >= 2 && $rawValue[$length - 1] === "'") {
            return substr($rawValue, 1, -1);
        }

        return substr($rawValue, 1);
    }

    private function parseDoubleQuotedValue(string $rawValue): string
    {
        $length = strlen($rawValue);
        $result = '';
        $escaped = false;

        for ($i = 1; $i < $length; $i++) {
            $ch = $rawValue[$i];

            if ($escaped) {
                if ($ch === 'n') {
                    $result .= "\n";
                } elseif ($ch === '"' || $ch === '\\') {
                    $result .= $ch;
                } else {
                    $result .= '\\' . $ch;
                }
                $escaped = false;
                continue;
            }

            if ($ch === '\\') {
                $escaped = true;
                continue;
            }

            if ($ch === '"') {
                return $result;
            }

            $result .= $ch;
        }

        return $result;
    }

    private function isKeyAlreadySet(string $key): bool
    {
        if (array_key_exists($key, $_ENV)) {
            return true;
        }

        return getenv($key) !== false;
    }

    private function assignKey(string $key, string $value): void
    {
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }

    private function recordEvent(string $event): void
    {
        $this->trace[] = [
            'level' => 'debug',
            'event' => $event,
        ];
    }

    private function recordKeyEvent(string $event, string $key): void
    {
        $this->trace[] = [
            'level' => 'debug',
            'event' => $event,
            'detail' => $key,
        ];
    }
}
