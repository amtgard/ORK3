<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\SchemaIntrospection;
use PHPUnit\Framework\TestCase;

final class SchemaIntrospectionTest extends TestCase
{
    public function testNormalizeCreateTableCollapsesEngineAndDefaultVariants(): void
    {
        $mirror = "CREATE TABLE `ork_award` (`name` varchar(100) NOT NULL) ENGINE=MyISAM";
        $sandbox = "CREATE TABLE `ork_award` (`name` varchar(100) NOT NULL DEFAULT '') ENGINE=InnoDB";

        $this->assertSame(
            SchemaIntrospection::normalizeCreateTable($mirror),
            SchemaIntrospection::normalizeCreateTable($sandbox)
        );
    }

    public function testHashFileContentsIsDeterministic(): void
    {
        $path = sys_get_temp_dir() . '/ork-db-introspection-' . uniqid('', true) . '.txt';
        file_put_contents($path, 'catalog');
        $first = SchemaIntrospection::hashFileContents($path);
        $second = SchemaIntrospection::hashFileContents($path);
        unlink($path);

        $this->assertSame($first, $second);
    }
}
