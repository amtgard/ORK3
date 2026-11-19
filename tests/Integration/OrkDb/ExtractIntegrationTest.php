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
        // The four `fixed_extract` catalogs are committed, so extract refreshes them in
        // templates/catalogs/ rather than in the .gitignored extracted/ directory. When the
        // mirror agrees with the committed copies (which drift-check enforces) this rewrite
        // is a no-op; when it does not, it shows up as a reviewable working-tree diff.
        $catalogDir = ORK3_ROOT . '/tools/ork-db/templates/catalogs';
        $this->assertFileExists($catalogDir . '/award.sql');
        $this->assertFileExists($catalogDir . '/class.sql');
        $this->assertFileExists($catalogDir . '/parktitle.sql');
        $this->assertFileExists($catalogDir . '/pronoun.sql');
        $this->assertFileDoesNotExist($outputDir . '/award.sql');
        $this->assertFileExists($outputDir . '/configuration.sql');
        $this->assertFileExists($outputDir . '/mundane_real.json');
        $this->assertFileExists($outputDir . '/events.json');
        $this->assertFileExists($outputDir . '/manifest.json');
    }
}
