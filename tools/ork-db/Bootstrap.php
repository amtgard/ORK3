<?php

declare(strict_types=1);

namespace OrkDb;

final class Bootstrap
{
    public function __construct(
        private readonly Validate $validate,
        private readonly Init $init,
        private readonly Extract $extract,
        private readonly Apply $apply,
        private readonly string $toolRoot,
    ) {
    }

    /**
     * @param array{yes?: bool, skip_extract?: bool, force_extract?: bool} $options
     * @return array{lines: list<string>, exit_code: int}
     */
    public function run(array $options = []): array
    {
        $lines = [];
        $yes = (bool) ($options['yes'] ?? false);
        $skipExtract = (bool) ($options['skip_extract'] ?? false);
        $forceExtract = (bool) ($options['force_extract'] ?? false);

        if (!$this->validate->testCanaryPresent()) {
            $lines[] = 'Bootstrap:    running init (test canary missing)';
            $this->init->run();
            $lines[] = 'Bootstrap:    init complete';
        } else {
            $lines[] = 'Bootstrap:    init skipped (test canary present)';
        }

        if ($skipExtract) {
            $lines[] = 'Bootstrap:    extract skipped (--skip-extract)';
        } elseif (!$forceExtract && $this->extractArtifactsFresh()) {
            $lines[] = 'Bootstrap:    extract skipped (artifacts present)';
        } else {
            $extractResult = $this->extract->run();
            $lines[] = 'Bootstrap:    extract complete from ' . $extractResult['source'];
            foreach ($extractResult['files'] as $file) {
                $lines[] = 'Extracted:    ' . $file;
            }
        }

        $applyResult = $this->apply->run(['yes' => $yes]);
        foreach ($applyResult['lines'] as $line) {
            $lines[] = $line;
        }

        if ($applyResult['exit_code'] !== 0) {
            $lines[] = 'Bootstrap:    ABORT — apply failed';

            return ['lines' => $lines, 'exit_code' => $applyResult['exit_code']];
        }

        $lines[] = 'Bootstrap:    complete';

        return ['lines' => $lines, 'exit_code' => 0];
    }

    public function extractArtifactsFresh(): bool
    {
        $manifestPath = $this->toolRoot . '/extracted/manifest.json';
        if (!is_readable($manifestPath)) {
            return false;
        }

        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest) || empty($manifest['files']) || !is_array($manifest['files'])) {
            return false;
        }

        $dir = $this->toolRoot . '/extracted';
        foreach ($manifest['files'] as $file) {
            if (!is_string($file) || !is_readable($dir . '/' . $file)) {
                return false;
            }
        }

        return true;
    }
}
