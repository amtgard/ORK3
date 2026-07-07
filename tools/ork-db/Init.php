<?php

declare(strict_types=1);

namespace OrkDb;

final class Init
{
    private const TEST_CANARY_MARKER = 'ORK3_TEST_CANARY_v1';

    public function __construct(
        private readonly Wiring $wiring,
        private readonly Validate $validate,
        private readonly string $repoRoot,
    ) {
    }

    public function run(): void
    {
        $pre = $this->validate->run(Validate::MODE_INIT);
        if (!$pre['passed']) {
            throw new ValidationException(
                'Init pre-validation failed: ' . implode('; ', $pre['lines'])
            );
        }

        $sandbox = $this->wiring->sandbox();
        $container = (string) $sandbox['container'];
        $database = (string) $sandbox['database'];
        $schemaPath = $this->repoRoot . '/ork.sql';

        if (!is_readable($schemaPath)) {
            throw new \RuntimeException("Schema file not readable: {$schemaPath}");
        }

        $this->runProcess(
            ['docker', 'exec', '-i', $container, 'mariadb', '-u', 'root', '-proot', $database],
            $schemaPath
        );

        $canarySql = <<<SQL
CREATE TABLE IF NOT EXISTS _ork_canary_test (
  id INT PRIMARY KEY,
  marker VARCHAR(64) NOT NULL,
  created_at DATETIME NOT NULL
);
INSERT INTO _ork_canary_test (id, marker, created_at)
VALUES (1, 'ORK3_TEST_CANARY_v1', NOW())
ON DUPLICATE KEY UPDATE marker = VALUES(marker);
SQL;

        $this->runProcess(
            ['docker', 'exec', '-i', $container, 'mariadb', '-u', 'root', '-proot', $database],
            null,
            $canarySql
        );

        $post = $this->validate->run(Validate::MODE_PRE_APPLY);
        foreach ($post['lines'] as $line) {
            fwrite(STDOUT, $line . PHP_EOL);
        }

        if (!$post['passed']) {
            throw new ValidationException(
                'Init post-validation failed: ' . implode('; ', $post['lines'])
            );
        }
    }

    private function runProcess(array $command, ?string $inputFile = null, ?string $inputString = null): void
    {
        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start process: ' . implode(' ', $command));
        }

        if ($inputFile !== null) {
            $input = fopen($inputFile, 'r');
            if ($input === false) {
                throw new \RuntimeException("Failed to open input file: {$inputFile}");
            }
            stream_copy_to_stream($input, $pipes[0]);
            fclose($input);
        } elseif ($inputString !== null) {
            fwrite($pipes[0], $inputString);
        }

        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Command failed (' . implode(' ', $command) . "): "
                . trim((string) $stderr . ' ' . (string) $stdout)
            );
        }
    }
}
