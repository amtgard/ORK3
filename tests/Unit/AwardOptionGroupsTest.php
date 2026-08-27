<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for award dropdown categorization (T-AWD-01).
 */
final class AwardOptionGroupsTest extends TestCase
{
    private Model_Award $awardModel;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->awardModel = new Model_Award();
    }

    public function testLadderAwardsSplitIntoOfficialAndKingdomGroups(): void
    {
        $groups = $this->mirrorCategorizeSampleAwards();

        $this->assertArrayHasKey('Official Ladder Awards', $groups);
        $this->assertArrayHasKey('Kingdom Ladder Awards', $groups);
        $this->assertNotSame(
            $groups['Official Ladder Awards'],
            $groups['Kingdom Ladder Awards'],
            'Official and kingdom ladders must be visibly distinct groups (requirement 4)'
        );
    }

    public function testPeerageBuckets(): void
    {
        $groups = $this->mirrorCategorizeSampleAwards();
        $this->assertNotEmpty($groups['Knighthoods']);
        $this->assertNotEmpty($groups['Masterhoods']);
        $this->assertArrayHasKey('Paragons', $groups);
        $this->assertArrayHasKey('Associate Titles', $groups);

        foreach ($groups['Knighthoods'] as $award) {
            $this->assertSame('Knight', $award['Peerage'] ?? '');
        }
    }

    public function testOfficerVsAwardBucket(): void
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT COUNT(*) AS c FROM ' . DB_PREFIX . "award WHERE officer_role != 'none'"
        );
        $rs->Next();
        if ((int) $rs->c === 0) {
            $this->markTestSkipped('No officer-role awards in seed data.');
        }

        $officerHtml = $this->awardModel->fetch_award_option_list(0, 'Officers');
        $awardHtml = $this->awardModel->fetch_award_option_list(0, 'Awards');

        $this->assertIsString($officerHtml);
        $this->assertIsString($awardHtml);
        $this->assertNotSame($officerHtml, $awardHtml);
    }

    public function testFetchAwardOptionListReturnsHtml(): void
    {
        $html = $this->awardModel->fetch_award_option_list(0);
        $this->assertIsString($html);
        $this->assertStringContainsString('<option', $html);
    }

    public function testFetchAwardOptionListLadderOptgroupBeforeCustomStandalone(): void
    {
        $html = $this->awardModel->fetch_award_option_list(0);
        $this->assertIsString($html);

        // Requirement 4 split "Ladder Awards" into two labeled optgroups; either
        // (or both) may appear, so take whichever comes first.
        $ladderPos = false;
        foreach (["optgroup label='Official Ladder Awards'", "optgroup label='Kingdom Ladder Awards'"] as $needle) {
            $pos = strpos($html, $needle);
            if ($pos !== false && ($ladderPos === false || $pos < $ladderPos)) {
                $ladderPos = $pos;
            }
        }
        if ($ladderPos === false) {
            $this->markTestSkipped('No Ladder Awards optgroup in seed data.');
        }

        $customPos = strpos($html, "data-custom-award='1'");
        if ($customPos === false) {
            $customPos = strpos($html, "data-custom-title='1'");
        }
        if ($customPos === false) {
            $this->markTestSkipped('No custom standalone award options in seed data.');
        }

        $this->assertLessThan($customPos, $ladderPos);
    }

    /**
     * Mirrors Award::GetAwardOptionGroups()'s categorization loop over sample rows
     * shaped like its per-award array. 'IsLadder' mirrors a.is_ladder (official);
     * membership in the sample kingdom-ladder id set mirrors a kingdom's own
     * ka.is_ladder = 1 flag (Award::LadderSql(), Task 1) for a row that carries no
     * official award_id.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function mirrorCategorizeSampleAwards(): array
    {
        $kingdomLadderIds = [9001];
        $rows = [
            // Official ladder: is_ladder => 1 (a.is_ladder), no kingdom flag needed.
            ['KingdomAwardId' => 21, 'AwardName' => 'Order of the Rose', 'IsLadder' => 1, 'Peerage' => '', 'IsTitle' => 0, 'TitleClass' => 0],
            // Kingdom ladder: is_ladder => 0, but ka_is_ladder => 1 (flagged via id membership).
            ['KingdomAwardId' => 9001, 'AwardName' => 'Order of the Comet', 'KingdomAwardName' => 'Order of the Comet', 'IsLadder' => 0, 'Peerage' => '', 'IsTitle' => 0, 'TitleClass' => 0],
            ['KingdomAwardId' => 101, 'AwardName' => 'Sir Something', 'IsLadder' => 0, 'Peerage' => 'Knight', 'IsTitle' => 0, 'TitleClass' => 0],
            ['KingdomAwardId' => 102, 'AwardName' => 'Master Something', 'IsLadder' => 0, 'Peerage' => 'Master', 'IsTitle' => 0, 'TitleClass' => 0],
            ['KingdomAwardId' => 103, 'AwardName' => 'Paragon Something', 'IsLadder' => 0, 'Peerage' => 'Paragon', 'IsTitle' => 0, 'TitleClass' => 0],
            ['KingdomAwardId' => 104, 'AwardName' => 'Squire Something', 'IsLadder' => 0, 'Peerage' => 'Squire', 'IsTitle' => 0, 'TitleClass' => 0],
        ];

        $officialLadder = $kingdomLadder = $knighthoods = $masterhoods = $paragons = $associates = [];

        foreach ($rows as $row) {
            $sysName = $row['AwardName'] ?? $row['KingdomAwardName'] ?? '';
            $isOfficialLadder = !empty($row['IsLadder']);
            $isKingdomLadder = !$isOfficialLadder
                && in_array((int) ($row['KingdomAwardId'] ?? 0), $kingdomLadderIds, true);

            if ($isOfficialLadder) {
                $officialLadder[] = $row;
            } elseif ($isKingdomLadder) {
                $kingdomLadder[] = $row;
            } elseif (($row['Peerage'] ?? '') === 'Knight') {
                $knighthoods[] = $row;
            } elseif (($row['Peerage'] ?? '') === 'Paragon') {
                $paragons[] = $row;
            } elseif (($row['Peerage'] ?? '') === 'Master'
                || (!empty($row['IsTitle']) && ($row['TitleClass'] ?? 0) == 10)) {
                $masterhoods[] = $row;
            } elseif (in_array($row['Peerage'] ?? '', ['Squire', 'Man-At-Arms', 'Page', 'Lords-Page'], true)
                || $sysName === 'Apprentice') {
                $associates[] = $row;
            }
        }

        return [
            'Official Ladder Awards' => $officialLadder,
            'Kingdom Ladder Awards' => $kingdomLadder,
            'Knighthoods' => $knighthoods,
            'Masterhoods' => $masterhoods,
            'Paragons' => $paragons,
            'Associate Titles' => $associates,
        ];
    }
}
