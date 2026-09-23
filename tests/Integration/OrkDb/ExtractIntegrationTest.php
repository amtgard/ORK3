<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use PHPUnit\Framework\TestCase;

final class ExtractIntegrationTest extends TestCase
{
    public function testExtractCommandWritesCatalogFilesFromMirror(): void
    {
        if (!ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror database is not available.');
        }

        ork3_ensure_mirror_prod_canary();

        $outputDir = ORK3_ROOT . '/tools/ork-db/extracted';
        foreach (glob($outputDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $command = 'cd ' . escapeshellarg(ORK3_ROOT)
            . ' && ENVIRONMENT=DEV php tools/ork-db/cli.php extract 2>&1';
        exec($command, $lines, $exitCode);

        $this->assertSame(0, $exitCode, implode("\n", $lines));
        $this->assertFileExists($outputDir . '/award.sql');
        $this->assertFileExists($outputDir . '/class.sql');
        $this->assertFileExists($outputDir . '/configuration.sql');
        $this->assertFileExists($outputDir . '/mundane_real.json');
        $this->assertFileExists($outputDir . '/events.json');
        $this->assertFileExists($outputDir . '/manifest.json');
    }
}
