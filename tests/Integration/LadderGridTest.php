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
        $assembly = $this->mirrorLadderGrid('Kingdom', $kid, 0);

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
        $assembly = $this->mirrorLadderGrid('Kingdom', $kid, 0);

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
     * @return array{ScopeName: string, LadderAwards: array<int, array<string, mixed>>, GridRows: list<array<string, mixed>>}
     */
    private function mirrorLadderGrid(string $type, int $kingdomId, int $parkId): array
    {
        global $DB;

        $scopeName = '';
        if ($parkId > 0) {
            $nr = $DB->DataSet(
                'SELECT p.name AS park_name, k.name AS kingdom_name
                 FROM ' . DB_PREFIX . 'park p
                 LEFT JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = p.kingdom_id
                 WHERE p.park_id = ' . (int) $parkId . ' LIMIT 1'
            );
            if ($nr && $nr->Next()) {
                $scopeName = $nr->kingdom_name . ' — ' . $nr->park_name;
            }
        } elseif ($kingdomId > 0) {
            $nr = $DB->DataSet('SELECT name FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . (int) $kingdomId . ' LIMIT 1');
            if ($nr && $nr->Next()) {
                $scopeName = $nr->name;
            }
        }

        if ($kingdomId > 0) {
            $kSql = 'SELECT DISTINCT a.award_id, IFNULL(ka.name, a.name) AS award_name, a.title_class
                     FROM ' . DB_PREFIX . 'kingdomaward ka
                     JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
                     WHERE ka.kingdom_id = ' . (int) $kingdomId . "
                       AND a.is_ladder = 1 AND a.award_id != 31
                     ORDER BY IFNULL(ka.name, a.name)";
        } else {
            $kSql = 'SELECT DISTINCT a.award_id, a.name AS award_name, a.title_class
                     FROM ' . DB_PREFIX . "award a
                     WHERE a.is_ladder = 1 AND a.award_id != 31
                     ORDER BY a.name";
        }

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

        $awardResult = $DB->DataSet($kSql);
        $awardCols = [];
        if ($awardResult && $awardResult->Size() > 0) {
            do {
                if (!$awardResult->award_id) {
                    continue;
                }
                $name = $awardResult->award_name;
                $awardCols[(int) $awardResult->award_id] = [
                    'Name' => $name,
                    'DisplayName' => preg_replace('/^Order of (?:the )?/i', '', $name),
                    'KnightGroup' => $knightGroupMap[$name] ?? '',
                ];
            } while ($awardResult->Next());
        }

        if ($awardCols === []) {
            return ['ScopeName' => $scopeName, 'LadderAwards' => [], 'GridRows' => []];
        }

        $awardIds = implode(',', array_keys($awardCols));
        $locationClause = $parkId > 0
            ? 'AND m.park_id = ' . (int) $parkId
            : ($kingdomId > 0 ? 'AND m.kingdom_id = ' . (int) $kingdomId : '');

        $dataSql = "SELECT m.mundane_id, m.persona, p.park_id, p.name AS park_name, a.award_id,
                           GREATEST(MAX(ma.rank), COUNT(ma.awards_id)) AS award_count
                    FROM " . DB_PREFIX . 'mundane m
                    LEFT JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
                    JOIN ' . DB_PREFIX . 'awards ma ON ma.mundane_id = m.mundane_id
                    JOIN ' . DB_PREFIX . 'kingdomaward ka ON ka.kingdomaward_id = ma.kingdomaward_id
                    JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
                    WHERE m.active = 1 AND a.is_ladder = 1
                      AND a.award_id IN (' . $awardIds . ")
                      AND (ma.revoked = 0 OR ma.revoked IS NULL)
                      {$locationClause}
                    GROUP BY m.mundane_id, a.award_id
                    ORDER BY m.persona";

        $dataResult = $DB->DataSet($dataSql);
        $playerData = [];
        if ($dataResult && $dataResult->Size() > 0) {
            do {
                $mid = (int) $dataResult->mundane_id;
                $aid = (int) $dataResult->award_id;
                if (!$mid || !$aid) {
                    continue;
                }
                if (!isset($playerData[$mid])) {
                    $playerData[$mid] = [
                        'MundaneId' => $mid,
                        'Persona' => $dataResult->persona,
                        'ParkId' => (int) $dataResult->park_id,
                        'ParkName' => $dataResult->park_name ?? '',
                        'Awards' => [],
                    ];
                }
                $val = (int) $dataResult->award_count;
                $playerData[$mid]['Awards'][$aid] = ['Rank' => $val > 0 ? $val : null, 'IsMaster' => false];
            } while ($dataResult->Next());
        }

        return [
            'ScopeName' => $scopeName,
            'LadderAwards' => $awardCols,
            'GridRows' => array_values($playerData),
        ];
    }
}
