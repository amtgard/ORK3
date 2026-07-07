<?php

declare(strict_types=1);

namespace OrkDb;

final class Apply
{
    /** @var (callable(array<int, string>, ?string, ?string): void)|null */
    private $processRunner;

    /**
     * @param (callable(array<int, string>, ?string, ?string): void)|null $processRunner
     */
    public function __construct(
        private readonly Wiring $wiring,
        private readonly Validate $validate,
        private readonly Render $render,
        private readonly string $repoRoot,
        $processRunner = null,
    ) {
        $this->processRunner = $processRunner;
    }

    /**
     * @param array{yes?: bool, sql?: string|null} $options
     * @return array{lines: list<string>, exit_code: int, sql_path: string}
     */
    public function run(array $options = []): array
    {
        $lines = [];
        $pre = $this->validate->run(Validate::MODE_PRE_APPLY);
        foreach ($pre['lines'] as $line) {
            $lines[] = $line;
        }
        if (!$pre['passed']) {
            $lines[] = 'Apply:        ABORT — pre-apply validation failed';

            return ['lines' => $lines, 'exit_code' => $pre['exit_code'], 'sql_path' => ''];
        }

        $sqlPath = isset($options['sql']) && $options['sql'] !== null && $options['sql'] !== ''
            ? (string) $options['sql']
            : $this->render->run()['output'];

        if (!is_readable($sqlPath)) {
            throw new ValidationException("Rendered SQL not readable: {$sqlPath}");
        }

        $lines[] = 'Render:       ' . $sqlPath;

        if (empty($options['yes']) && !$this->confirmApply($sqlPath)) {
            $lines[] = 'Apply:        CANCELLED by operator';

            return ['lines' => $lines, 'exit_code' => 3, 'sql_path' => $sqlPath];
        }

        $this->loadSqlIntoSandbox($sqlPath);
        $lines[] = 'Apply:        loaded into ' . $this->wiring->sandboxTargetLabel();

        $post = $this->validate->run(Validate::MODE_POST_APPLY);
        foreach ($post['lines'] as $line) {
            $lines[] = $line;
        }

        return [
            'lines' => $lines,
            'exit_code' => $post['passed'] ? 0 : $post['exit_code'],
            'sql_path' => $sqlPath,
        ];
    }

    private function confirmApply(string $sqlPath): bool
    {
        $target = $this->wiring->sandboxTargetLabel();
        fwrite(STDOUT, "About to wipe and reload sandbox {$target} using:\n  {$sqlPath}\nType 'yes' to continue: ");
        $answer = trim((string) fgets(STDIN));

        return strtolower($answer) === 'yes';
    }

    private function loadSqlIntoSandbox(string $sqlPath): void
    {
        $sandbox = $this->wiring->sandbox();
        $this->wiring->assertSandboxEndpoint(
            (string) $sandbox['host'],
            (int) $sandbox['port'],
            (string) $sandbox['database']
        );

        $container = (string) $sandbox['container'];
        $command = ['docker', 'exec', '-i', $container, 'mariadb', '-u', 'root', '-proot'];

        if ($this->processRunner !== null) {
            ($this->processRunner)($command, $sqlPath, null);

            return;
        }

        $descriptorSpec = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($command, $descriptorSpec, $pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('Failed to start sandbox load process');
        }

        $input = fopen($sqlPath, 'r');
        if ($input === false) {
            throw new \RuntimeException("Failed to open SQL file: {$sqlPath}");
        }

        stream_copy_to_stream($input, $pipes[0]);
        fclose($input);
        fclose($pipes[0]);

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        $exitCode = proc_close($process);
        if ($exitCode !== 0) {
            throw new \RuntimeException(
                'Sandbox load failed: ' . trim((string) $stderr . ' ' . (string) $stdout)
            );
        }
    }
}
