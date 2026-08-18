<?php

declare(strict_types=1);

namespace OrkDb;

final class Wiring
{
    private const FORBIDDEN_PORTS = [3306, 19306];
    private const ALLOWED_TEST_HOSTS = ['127.0.0.1', 'localhost', 'ork3-php8-test-db'];
    private const ALLOWED_MIRROR_HOSTS = ['127.0.0.1', 'localhost', 'ork3-php8-db'];
    private const FORBIDDEN_MIRROR_PORTS = [3306, 19307];

    /** @var array<string, mixed> */
    private array $manifest;

    public function __construct(string $toolRoot)
    {
        $this->manifest = Json5::decodeFile($toolRoot . '/manifests/wiring.json5');
    }

    /** @return array<string, mixed> */
    public function mirror(): array
    {
        return $this->manifest['mirror'];
    }

    /** @return array<string, mixed> */
    public function sandbox(): array
    {
        return $this->manifest['sandbox'];
    }

    public function sandboxDsn(): string
    {
        $sandbox = $this->sandbox();

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $sandbox['host'],
            (int) $sandbox['port'],
            $sandbox['database']
        );
    }

    public function mirrorDsn(): string
    {
        $mirror = $this->mirror();

        return sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $mirror['host'],
            (int) $mirror['port'],
            $mirror['database']
        );
    }

    /** @return array{user: string, password: string} */
    public function credentials(): array
    {
        $credentials = $this->manifest['credentials'] ?? ['user' => 'root', 'password' => 'root'];

        return [
            'user' => (string) $credentials['user'],
            'password' => (string) $credentials['password'],
        ];
    }

    public function assertMirrorEndpoint(string $host, int $port, string $database): void
    {
        $mirror = $this->mirror();

        if ($port !== (int) $mirror['port']) {
            throw new ValidationException("Mirror port lock failed: expected {$mirror['port']}, got {$port}");
        }

        if (in_array($port, self::FORBIDDEN_MIRROR_PORTS, true)) {
            throw new ValidationException("Forbidden port {$port} for mirror extract");
        }

        if ($database !== (string) $mirror['database']) {
            throw new ValidationException(
                "Mirror database name lock failed: expected {$mirror['database']}, got {$database}"
            );
        }

        if (!in_array($host, self::ALLOWED_MIRROR_HOSTS, true)) {
            throw new ValidationException("Mirror host not allowed: {$host}");
        }
    }

    public function assertSandboxEndpoint(string $host, int $port, string $database): void
    {
        $sandbox = $this->sandbox();

        if ($port !== (int) $sandbox['port']) {
            throw new ValidationException("Port lock failed: expected {$sandbox['port']}, got {$port}");
        }

        if (in_array($port, self::FORBIDDEN_PORTS, true)) {
            throw new ValidationException("Forbidden port {$port} for sandbox operations");
        }

        if ($database !== (string) $sandbox['database']) {
            throw new ValidationException("Database name lock failed: expected {$sandbox['database']}, got {$database}");
        }

        if (!in_array($host, self::ALLOWED_TEST_HOSTS, true)) {
            throw new ValidationException("Sandbox host not allowed: {$host}");
        }
    }

    public function sandboxTargetLabel(): string
    {
        $sandbox = $this->sandbox();

        return sprintf(
            '%s:%d/%s',
            $sandbox['host'],
            (int) $sandbox['port'],
            $sandbox['database']
        );
    }

    public function mirrorTargetLabel(): string
    {
        $mirror = $this->mirror();

        return sprintf(
            '%s:%d/%s',
            $mirror['host'],
            (int) $mirror['port'],
            $mirror['database']
        );
    }
}
