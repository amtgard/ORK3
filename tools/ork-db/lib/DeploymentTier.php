<?php

declare(strict_types=1);

namespace OrkDb;

final class DeploymentTier
{
    public const LOCAL = 'local';
    public const PRODUCTION = 'production';

    private const LOCAL_HOSTNAMES = [
        '127.0.0.1',
        'localhost',
        'ork3-php8-db',
        'ork3-php8-test-db',
        'host.docker.internal',
    ];

    private const PRODUCTION_HOSTNAMES = [
        'mysql.amtgard.com',
        'ork.amtgard.com',
    ];

    /** @var (callable(string, int): bool)|null */
    private $portReachable;

    public function __construct(
        private readonly Wiring $wiring,
        private readonly string $repoRoot,
        $portReachable = null,
    ) {
        $this->portReachable = $portReachable;
    }

    /** @return array{tier: string, reasons: list<string>, mirror_reachable: bool, sandbox_reachable: bool} */
    public function classify(): array
    {
        $mirror = $this->wiring->mirror();
        $sandbox = $this->wiring->sandbox();

        $mirrorReachable = $this->isPortReachable((string) $mirror['host'], (int) $mirror['port']);
        $sandboxReachable = $this->isPortReachable((string) $sandbox['host'], (int) $sandbox['port']);

        $reasons = [];
        $configHostname = $this->resolveConfigHostname();

        if (!$sandboxReachable) {
            $reasons[] = 'sandbox port unreachable';

            return $this->result(self::PRODUCTION, $reasons, $mirrorReachable, $sandboxReachable);
        }

        if ($configHostname !== null && $this->isProductionHostname($configHostname)) {
            $reasons[] = "config hostname is production ({$configHostname})";

            return $this->result(self::PRODUCTION, $reasons, $mirrorReachable, $sandboxReachable);
        }

        if (!$mirrorReachable) {
            $reasons[] = 'mirror port unreachable';

            return $this->result(self::PRODUCTION, $reasons, $mirrorReachable, $sandboxReachable);
        }

        if (!$this->isLocalEnvironmentSignal()) {
            $reasons[] = 'local environment signal not present (ENVIRONMENT=DEV or local/docker DB_HOSTNAME)';

            return $this->result(self::PRODUCTION, $reasons, $mirrorReachable, $sandboxReachable);
        }

        $reasons[] = 'sandbox and mirror reachable';
        $reasons[] = 'local/docker config or ENVIRONMENT=DEV';

        return $this->result(self::LOCAL, $reasons, $mirrorReachable, $sandboxReachable);
    }

    /** @param list<string> $reasons */
    private function result(
        string $tier,
        array $reasons,
        bool $mirrorReachable,
        bool $sandboxReachable,
    ): array {
        return [
            'tier' => $tier,
            'reasons' => $reasons,
            'mirror_reachable' => $mirrorReachable,
            'sandbox_reachable' => $sandboxReachable,
        ];
    }

    public function isLocal(): bool
    {
        return $this->classify()['tier'] === self::LOCAL;
    }

    public function refuseDataCommands(string $command): void
    {
        if ($this->isLocal()) {
            return;
        }

        throw new TierRefusalException(
            "ork-db: REFUSED — this host is classified as production.\n"
            . "Data commands (extract, render, apply, init, validate) only run on local workstations.\n"
            . "Attempted command: {$command}"
        );
    }

    private function isPortReachable(string $host, int $port): bool
    {
        if ($this->portReachable !== null) {
            return ($this->portReachable)($host, $port);
        }

        return self::probePort($host, $port);
    }

    public static function probePort(string $host, int $port, float $timeoutSeconds = 1.0): bool
    {
        $errno = 0;
        $errstr = '';
        $socket = @fsockopen($host, $port, $errno, $errstr, $timeoutSeconds);
        if ($socket === false) {
            return false;
        }

        fclose($socket);

        return true;
    }

    private function isLocalEnvironmentSignal(): bool
    {
        $environment = getenv('ENVIRONMENT');
        if (is_string($environment) && strtoupper($environment) === 'DEV') {
            return true;
        }

        $appStage = $this->resolveConfigConstant('APP_STAGE');
        if (is_string($appStage) && strtoupper($appStage) === 'DEV') {
            return true;
        }

        $configHostname = $this->resolveConfigHostname();
        if ($configHostname !== null && $this->isLocalHostname($configHostname)) {
            return true;
        }

        return false;
    }

    private function resolveConfigHostname(): ?string
    {
        return $this->resolveConfigConstant('DB_HOSTNAME');
    }

    private function resolveConfigConstant(string $name): ?string
    {
        foreach ($this->configCandidates() as $path) {
            if (!is_readable($path)) {
                continue;
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                continue;
            }

            if (preg_match(
                "/define\\('{$name}',\\s*'([^']*)'\\)/",
                $contents,
                $matches
            ) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /** @return list<string> */
    private function configCandidates(): array
    {
        return [
            $this->repoRoot . '/config.php',
            $this->repoRoot . '/config.dev.php',
            $this->repoRoot . '/config.test.php',
        ];
    }

    private function isLocalHostname(string $hostname): bool
    {
        return in_array(strtolower($hostname), self::LOCAL_HOSTNAMES, true);
    }

    private function isProductionHostname(string $hostname): bool
    {
        $lower = strtolower($hostname);

        if (in_array($lower, self::PRODUCTION_HOSTNAMES, true)) {
            return true;
        }

        return str_contains($lower, 'amtgard.com') && !$this->isLocalHostname($lower);
    }
}
