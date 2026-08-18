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
        $assembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));

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

    public function testParkOnlyGridUsesGlobalLadderColumns(): void
    {
        $kid = $this->fixture->kingdomWithLadderAwards();
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $ladder = $this->fixture->firstLadderAward($kid);
        $globalName = $this->fixture->awardGlobalName($ladder['award_id']);
        $alias = 'Park-only ladder alias ' . substr(md5((string) microtime(true)), 0, 8);
        $this->fixture->renameKingdomAward($ladder['kingdomaward_id'], $alias);

        $editor = $this->fixture->createPlayer($parkId, 'ladder-park-only');
        $this->fixture->insertScopedAuth($editor['mundane_id'], $parkId, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        $report = new Report();
        $assembly = $report->GetLadderAwardGrid([
            'KingdomId' => 0,
            'ParkId' => $parkId,
            'Token' => $editor['token'],
        ]);

        $this->assertArrayHasKey($ladder['award_id'], $assembly['LadderAwards']);
        $this->assertSame($globalName, $assembly['LadderAwards'][$ladder['award_id']]['Name']);
        $this->assertNotSame($alias, $assembly['LadderAwards'][$ladder['award_id']]['Name']);
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
        $assembly = $report->GetLadderAwardGrid($this->kingdomGridRequest($kid));

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

    /**
     * @return array{KingdomId: int, ParkId: int, Token: string}
     */
    private function kingdomGridRequest(int $kid): array
    {
        $parkId = $this->fixture->parkIdInKingdom($kid);
        $editor = $this->fixture->createPlayer($parkId, 'ladder-grid');
        $this->fixture->insertScopedAuth($editor['mundane_id'], 0, $kid, AUTH_CREATE);
        unset($_SESSION['is_authorized_mundane_id']);

        return [
            'KingdomId' => $kid,
            'ParkId' => 0,
            'Token' => $editor['token'],
        ];
    }
}
