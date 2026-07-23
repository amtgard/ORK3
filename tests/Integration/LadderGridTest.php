<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for ladder grid report assembly (T-RPT-01).
 */
final class LadderGridTest extends TestCase
{
    private ReportsFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = ReportsFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testLadderGridAssembly(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $report = new Report();
        $assembly = $report->GetLadderAwardGrid(['KingdomId' => $kid, 'ParkId' => 0]);

        $this->assertArrayHasKey('ScopeName', $assembly);
        $this->assertArrayHasKey('LadderAwards', $assembly);
        $this->assertArrayHasKey('GridRows', $assembly);
        $this->assertNotEmpty($assembly['LadderAwards']);

        foreach ($assembly['GridRows'] as $row) {
            $this->assertArrayHasKey('MundaneId', $row);
            $this->assertArrayHasKey('Persona', $row);
            $this->assertArrayHasKey('Awards', $row);
            foreach ($row['Awards'] as $awardId => $cell) {
                $this->assertArrayHasKey('Rank', $cell);
                $this->assertArrayHasKey('IsMaster', $cell);
                $this->assertContains($awardId, array_keys($assembly['LadderAwards']));
            }
        }
    }

    public function testKnightGroupAliasing(): void
    {
        $knightGroupMap = [
            'Order of Battle' => 'Battle',
            'Order of the Warrior' => 'Sword',
            'Order of the Crown' => 'Crown',
            'Order of the Lion' => 'Flame',
            'Order of the Rose' => 'Flame',
            'Order of the Smith' => 'Flame',
            'Order of the Dragon' => 'Serpent',
            'Order of the Garber' => 'Serpent',
            'Order of the Owl' => 'Serpent',
        ];

        $kid = $this->fixture->kingdomWithLadderAwards();
        $report = new Report();
        $assembly = $report->GetLadderAwardGrid(['KingdomId' => $kid, 'ParkId' => 0]);

        foreach ($assembly['LadderAwards'] as $col) {
            if (isset($knightGroupMap[$col['Name']])) {
                $this->assertSame($knightGroupMap[$col['Name']], $col['KnightGroup']);
            }
        }
    }

    public function testMasterMapUnification(): void
    {
        $controllerMap = [
            21 => [1], 22 => [2], 23 => [3], 24 => [4], 25 => [5],
            26 => [6], 27 => [12], 239 => [240], 243 => [244],
        ];
        $domainMap = Award::GetLadderMasterMap();

        foreach ($controllerMap as $ladderId => $masterIds) {
            $this->assertArrayHasKey($ladderId, $domainMap);
            $this->assertSame($masterIds, $domainMap[$ladderId]['MasterAwardIds']);
        }

        $this->assertGreaterThan(count($controllerMap), count($domainMap));
    }
}
