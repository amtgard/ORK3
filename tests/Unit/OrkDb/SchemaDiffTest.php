<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\SchemaIntrospection;
use PHPUnit\Framework\TestCase;

final class SchemaDiffTest extends TestCase
{
    public function testNormalizeCreateTableStripsAutoIncrement(): void
    {
        $normalized = SchemaIntrospection::normalizeCreateTable(
            "CREATE TABLE `ork_park` (`park_id` int NOT NULL AUTO_INCREMENT=42) ENGINE=InnoDB AUTO_INCREMENT=100"
        );

        $this->assertStringNotContainsString('AUTO_INCREMENT=100', $normalized);
        $this->assertStringNotContainsString('AUTO_INCREMENT=42', $normalized);
    }

    public function testHashFileContentsMatchesSha256Prefix(): void
    {
        $path = sys_get_temp_dir() . '/ork-db-hash-' . uniqid('', true) . '.sql';
        file_put_contents($path, "SELECT 1;\n");
        $hash = SchemaIntrospection::hashFileContents($path);
        unlink($path);

        $this->assertMatchesRegularExpression('/^sha256:[a-f0-9]{64}$/', $hash);
    }
}
