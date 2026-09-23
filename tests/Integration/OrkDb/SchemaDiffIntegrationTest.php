<?php

declare(strict_types=1);

namespace OrkDb\Tests;

use OrkDb\Bootstrap;
use OrkDb\SchemaDiff;
use OrkDb\Wiring;
use PHPUnit\Framework\TestCase;

final class SchemaDiffIntegrationTest extends TestCase
{
    /** @var list<string> */
    private const CRITICAL_TABLES = [
        'ork_kingdom',
        'ork_park',
        'ork_mundane',
        'ork_award',
        'ork_kingdomaward',
        'ork_configuration',
        'ork_class',
        'ork_pronoun',
        'ork_officer',
        'ork_event',
    ];

    public function testSchemaDiffCriticalTablesMatchAfterApply(): void
    {
        if (!ork3_sandbox_db_available() || !ork3_mirror_db_available()) {
            $this->markTestSkipped('Mirror and sandbox databases are required.');
        }

        $toolRoot = ORK3_ROOT . '/tools/ork-db';
        $wiring = new Wiring($toolRoot);
        $validate = new \OrkDb\Validate($wiring, $toolRoot);
        $bootstrap = new Bootstrap(
            $validate,
            new \OrkDb\Init($wiring, $validate, ORK3_ROOT),
            new \OrkDb\Extract($wiring, $toolRoot),
            new \OrkDb\Apply(
                $wiring,
                $validate,
                new \OrkDb\Render($toolRoot, ORK3_ROOT),
                ORK3_ROOT
            ),
            ORK3_ROOT
        );

        $bootstrapResult = $bootstrap->run(['yes' => true, 'skip_extract' => true]);
        $this->assertSame(0, $bootstrapResult['exit_code'], implode("\n", $bootstrapResult['lines']));

        $schemaDiff = new SchemaDiff($wiring, ORK3_ROOT);
        $result = $schemaDiff->run();
        $output = implode("\n", $result['lines']);

        $this->assertStringContainsString('SCHEMA DIFF', $output);
        foreach (self::CRITICAL_TABLES as $table) {
            $this->assertDoesNotMatchRegularExpression(
                '/^DIFF  ' . preg_quote($table, '/') . '$/m',
                $output,
                $output
            );
        }
    }
}
