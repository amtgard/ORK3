<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\MigrationClassifier;
use PHPUnit\Framework\TestCase;

final class MigrationClassifierTest extends TestCase
{
    public function testEveryRepoMigrationIsClassified(): void
    {
        $classifier = new MigrationClassifier(ORK3_ROOT, ORK3_ROOT . '/tools/ork-db');
        $repoFiles = $classifier->repoMigrationFiles();
        $unclassified = $classifier->unclassifiedFiles();

        $this->assertNotEmpty($repoFiles);
        $this->assertSame([], $unclassified, 'Unclassified: ' . implode(', ', $unclassified));
    }

    public function testRenderSourcesIncludeSchemaMigrationsOnly(): void
    {
        $classifier = new MigrationClassifier(ORK3_ROOT, ORK3_ROOT . '/tools/ork-db');
        $sources = $classifier->renderSources();

        $this->assertNotEmpty($sources);
        $this->assertContains('2026-05-17-add-entity-banners.sql', array_column($sources, 'name'));
        $this->assertNotContains('2026-07-07-add-prod-canary.sql', array_column($sources, 'name'));
        $this->assertNotContains('2026-05-11-scrub-viridian-probe-accounts.sql', array_column($sources, 'name'));
    }

    public function testSanitizeMigrationSqlStripsUseOrk(): void
    {
        $classifier = new MigrationClassifier(ORK3_ROOT, ORK3_ROOT . '/tools/ork-db');
        $sql = "USE ork;\nALTER TABLE ork_park ADD COLUMN demo INT;\n";

        $this->assertSame('ALTER TABLE ork_park ADD COLUMN demo INT;', $classifier->sanitizeMigrationSql($sql));
    }

    public function testSanitizeMigrationSqlStripsDatabaseQualifiers(): void
    {
        $classifier = new MigrationClassifier(ORK3_ROOT, ORK3_ROOT . '/tools/ork-db');
        $sql = "ALTER TABLE `ork`.`ork_attendance` DROP INDEX `mundane_id`;\n";

        $this->assertSame(
            'ALTER TABLE `ork_attendance` DROP INDEX `mundane_id`;',
            $classifier->sanitizeMigrationSql($sql)
        );
    }
}
