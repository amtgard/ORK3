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

    public function testPseudoLadderIds(): void
    {
        $expected = [
            7067, 7249, 6628, 5813, 6045, 6050, 6430, 6283, 7055,
            6403, 6297, 7273, 7070, 6311, 6310, 7277, 6411, 6771,
            6577, 94, 7084, 6171, 6574, 7254,
        ];
        $actual = Award::pseudoLadderKingdomAwardIds();

        $this->assertSame($expected, $actual);
        $this->assertCount(24, $actual);
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

    /**
     * @return list<int>
     */
    private function mirrorPseudoLadderIds(): array
    {
        return [
            7067, 7249, 6628, 5813, 6045, 6050, 6430, 6283, 7055,
            6403, 6297, 7273, 7070, 6311, 6310, 7277, 6411, 6771,
            6577, 94, 7084, 6171, 6574, 7254,
        ];
    }

    /**
     * @return array<string, list<array<string, mixed>>>
     */
    private function mirrorCategorizeSampleAwards(): array
    {
        $award = new Award();
        $response = $award->GetAwardList(['IsLadder' => null, 'IsTitle' => null]);
        $this->assertSame(0, $response['Status']['Status']);

        $pseudoLadderIds = $this->mirrorPseudoLadderIds();
        $knighthoods = $masterhoods = $paragons = $associates = [];

        foreach ($response['Awards'] as $row) {
            $sysName = $row['AwardName'] ?? $row['KingdomAwardName'] ?? '';
            if (in_array((int) ($row['KingdomAwardId'] ?? 0), $pseudoLadderIds, true)) {
                continue;
            }
            if (($row['Peerage'] ?? '') === 'Knight') {
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
            'Knighthoods' => $knighthoods,
            'Masterhoods' => $masterhoods,
            'Paragons' => $paragons,
            'Associate Titles' => $associates,
        ];
    }
}
