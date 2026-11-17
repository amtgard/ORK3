<?php

declare(strict_types=1);

namespace OrkDb;

final class UseProfile
{
    public const PROFILE_PROD = 'prod';
    public const PROFILE_DEV = 'dev';

    /** @var (callable(array<int, string>): int)|null */
    private $processRunner;

    /**
     * @param (callable(array<int, string>): int)|null $processRunner
     */
    public function __construct(
        private readonly DeploymentTier $tier,
        private readonly string $repoRoot,
        $processRunner = null,
    ) {
        $this->processRunner = $processRunner;
    }

    /**
     * @return array{profile: string, changed: bool, lines: list<string>}
     */
    public function run(string $profile): array
    {
        if (!in_array($profile, [self::PROFILE_PROD, self::PROFILE_DEV], true)) {
            throw new ValidationException("Unknown profile '{$profile}' — use prod or dev");
        }

        if ($profile === self::PROFILE_DEV) {
            $this->tier->refuseDataCommands('use dev');
        }

        if (!$this->tier->isLocal()) {
            return [
                'profile' => $profile,
                'changed' => false,
                'lines' => [
                    'Profile:      ' . $profile . ' (production host — already on production DB)',
                ],
            ];
        }

        $profileFile = $this->profileFilePath();
        $content = 'ORK3_DB_PROFILE=' . $profile . PHP_EOL;
        $previous = is_readable($profileFile) ? (string) file_get_contents($profileFile) : null;
        $changed = $previous !== $content;

        if ($changed) {
            if (file_put_contents($profileFile, $content) === false) {
                throw new \RuntimeException("Failed to write profile file: {$profileFile}");
            }
        }

        $this->restartAppContainer();

        $target = $profile === self::PROFILE_DEV
            ? 'sandbox (ork3-php8-test-db / ork_test)'
            : 'mirror (ork3-php8-db / ork)';

        return [
            'profile' => $profile,
            'changed' => $changed,
            'lines' => [
                'Profile:      ' . $profile,
                'App target:   ' . $target,
                'Profile file: ' . $profileFile . ($changed ? ' (updated)' : ' (unchanged)'),
                'App:          restarted ' . $this->runningAppContainerName(),
            ],
        ];
    }

    public function profileFilePath(): string
    {
        return $this->repoRoot . '/.ork3-db.local';
    }

    public function readCurrentProfile(): string
    {
        $profileFile = $this->profileFilePath();
        if (!is_readable($profileFile)) {
            return self::PROFILE_PROD;
        }

        foreach (file($profileFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (str_starts_with($line, 'ORK3_DB_PROFILE=')) {
                $value = trim(substr($line, strlen('ORK3_DB_PROFILE=')));

                return in_array($value, [self::PROFILE_PROD, self::PROFILE_DEV], true)
                    ? $value
                    : self::PROFILE_PROD;
            }
        }

        return self::PROFILE_PROD;
    }

    private function runningAppContainerName(): string
    {
        foreach (['ork3-php8-app', 'ork3-php8-app-green', 'ork3-php8-app-blue'] as $name) {
            if ($this->isDockerContainerRunning($name)) {
                return $name;
            }
        }

        return 'ork3app';
    }

    private function isDockerContainerRunning(string $name): bool
    {
        $command = ['docker', 'inspect', '-f', '{{.State.Running}}', $name];
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];
        $process = proc_open($command, $descriptorSpec, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            return false;
        }
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($process);

        return trim((string) $stdout) === 'true';
    }

    private function restartAppContainer(): void
    {
        $container = $this->runningAppContainerName();
        if ($container !== 'ork3app') {
            $command = ['docker', 'restart', $container];
            $this->runHostCommand($command, 'Failed to restart ' . $container);

            return;
        }

        $command = [
            'docker',
            'compose',
            '-f',
            $this->repoRoot . '/docker-compose.php8.yml',
            'restart',
            'ork3app',
        ];
        $this->runHostCommand($command, 'Failed to restart ork3app');
    }

    /**
     * @param list<string> $command
     */
    private function runHostCommand(array $command, string $errorPrefix): void
    {
        if ($this->processRunner !== null) {
            $exitCode = ($this->processRunner)($command);
            if ($exitCode !== 0) {
                throw new \RuntimeException($errorPrefix . ' (exit ' . $exitCode . ')');
            }

            return;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes, $this->repoRoot);
        if (!is_resource($process)) {
            throw new \RuntimeException($errorPrefix . ': failed to start process');
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException($errorPrefix . ': ' . trim((string) $stderr . ' ' . (string) $stdout));
        }
    }
}
