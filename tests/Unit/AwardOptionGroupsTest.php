<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for award dropdown categorization (T-AWD-01).
 */
final class AwardOptionGroupsTest extends TestCase
{
    private const SAMPLE_OFFICIAL_KAID = 21;
    private const SAMPLE_KINGDOM_KAID = 9001;
    private const SAMPLE_BOTH_FLAGGED_KAID = 9002;

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
        // Assert MEMBERSHIP, not just that the two groups differ. A test that only
        // checks the keys exist and the arrays are not identical still passes when
        // the classification is swapped -- which is the exact rule this is guarding.
        $groups = $this->mirrorCategorizeSampleAwards();

        $officialIds = array_column($groups['Official Ladder Awards'] ?? [], 'KingdomAwardId');
        $kingdomIds  = array_column($groups['Kingdom Ladder Awards'] ?? [], 'KingdomAwardId');

        $this->assertContains(
            self::SAMPLE_OFFICIAL_KAID,
            $officialIds,
            'an award backed by a.is_ladder=1 belongs in the official group'
        );
        $this->assertNotContains(
            self::SAMPLE_OFFICIAL_KAID,
            $kingdomIds,
            'requirement 1: an official ladder must never fall into the kingdom bucket'
        );

        $this->assertContains(
            self::SAMPLE_KINGDOM_KAID,
            $kingdomIds,
            'an award raised only by ka.is_ladder=1 belongs in the kingdom group'
        );
        $this->assertNotContains(
            self::SAMPLE_KINGDOM_KAID,
            $officialIds,
            'a kingdom ladder must not be presented as an Amtgard order'
        );
    }

    public function testAnAwardThatIsBothOfficialAndKingdomFlaggedGroupsAsOfficial(): void
    {
        // The tie-break. GREATEST() makes both flags 1 for an official award, so the
        // classifier must resolve the overlap in official's favour (requirement 1).
        $groups = $this->mirrorCategorizeSampleAwards();

        $this->assertContains(
            self::SAMPLE_BOTH_FLAGGED_KAID,
            array_column($groups['Official Ladder Awards'] ?? [], 'KingdomAwardId')
        );
        $this->assertNotContains(
            self::SAMPLE_BOTH_FLAGGED_KAID,
            array_column($groups['Kingdom Ladder Awards'] ?? [], 'KingdomAwardId')
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
     * This REIMPLEMENTS the classification loop rather than calling the production
     * method -- it never runs Award::GetAwardOptionGroups() itself, so do not read
     * these tests as end-to-end coverage; LadderPredicateSqlTest's
     * testProductionGroupingPutsAnOfficialLadderInTheOfficialGroup() carries the
     * test against the real method.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    private function mirrorCategorizeSampleAwards(): array
    {
        // Both SAMPLE_KINGDOM_KAID and SAMPLE_BOTH_FLAGGED_KAID carry the kingdom flag
        // (id membership); only SAMPLE_BOTH_FLAGGED_KAID also carries IsLadder => 1,
        // which is what exercises the `!$isOfficialLadder &&` tie-break guard.
        $kingdomLadderIds = [self::SAMPLE_KINGDOM_KAID, self::SAMPLE_BOTH_FLAGGED_KAID];
        $rows = [
            // Official ladder: is_ladder => 1 (a.is_ladder), no kingdom flag needed.
            ['KingdomAwardId' => self::SAMPLE_OFFICIAL_KAID, 'AwardName' => 'Order of the Rose', 'IsLadder' => 1, 'Peerage' => '', 'IsTitle' => 0, 'TitleClass' => 0],
            // Kingdom ladder: is_ladder => 0, but ka_is_ladder => 1 (flagged via id membership).
            ['KingdomAwardId' => self::SAMPLE_KINGDOM_KAID, 'AwardName' => 'Order of the Comet', 'KingdomAwardName' => 'Order of the Comet', 'IsLadder' => 0, 'Peerage' => '', 'IsTitle' => 0, 'TitleClass' => 0],
            // Both flagged: is_ladder => 1 (official) AND id is in the kingdom-ladder
            // set. The classifier must still bucket this as official (requirement 1).
            ['KingdomAwardId' => self::SAMPLE_BOTH_FLAGGED_KAID, 'AwardName' => 'Order of the Basilisk', 'KingdomAwardName' => 'Order of the Basilisk', 'IsLadder' => 1, 'Peerage' => '', 'IsTitle' => 0, 'TitleClass' => 0],
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
