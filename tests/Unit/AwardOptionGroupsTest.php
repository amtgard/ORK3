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

    /**
     * Structure, NOT contents.
     *
     * This used to assert the list equalled a copy of itself pasted into the test,
     * plus an exact count. That can only fail when someone legitimately edits the
     * list -- which is a documented, expected thing to do, since the list is
     * hand-maintained and goes stale every time a kingdom invents a ladder. It
     * duly went red on 2026-10-04 when 7513 was added, and stayed red for nine
     * days, which is the proof that a change-detector nobody can act on is noise
     * rather than a guard.
     *
     * What IS worth catching is an editing mistake: a duplicate, or a non-id.
     * Contents are covered behaviourally by the effectiveIsLadder tests below.
     */
    public function testPseudoLadderIdsAreStructurallyValid(): void
    {
        $ids = Award::pseudoLadderKingdomAwardIds();

        $this->assertNotEmpty($ids, 'The curated kingdom-original ladder list must not be empty.');
        foreach ($ids as $id) {
            $this->assertIsInt($id);
            $this->assertGreaterThan(0, $id, 'kingdomaward ids are positive.');
        }
        $this->assertSame(
            array_values(array_unique($ids)),
            array_values($ids),
            'Duplicate id in pseudoLadderKingdomAwardIds() -- harmless at runtime, but always an editing slip.'
        );
    }

    /**
     * The actual contract: three independent ways to be a ladder, any one enough.
     * Case 3 is the one with no other source of truth -- a kingdom-original ladder
     * has no ork_award row, so neither is_ladder column can ever be 1 for it, and
     * dropping the curated list from this test would let that regress silently.
     */
    public function testEffectiveIsLadderCoversAllThreeCases(): void
    {
        $curated = Award::pseudoLadderKingdomAwardIds();
        $curatedId = (int) reset($curated);
        $notCurated = 0;
        for ($candidate = 999000; $candidate < 999100; $candidate++) {
            if (!in_array($candidate, $curated, true)) {
                $notCurated = $candidate;
                break;
            }
        }
        $this->assertGreaterThan(0, $notCurated, 'needed an id outside the curated list');

        // 1. the kingdom's own row says so
        $this->assertTrue(Award::effectiveIsLadder($notCurated, 1, 0));
        // 2. the base catalog row says so
        $this->assertTrue(Award::effectiveIsLadder($notCurated, 0, 1));
        // 3. kingdom-original: neither column can say so, the list is all there is
        $this->assertTrue(Award::effectiveIsLadder($curatedId, 0, 0));
        // none of the above
        $this->assertFalse(Award::effectiveIsLadder($notCurated, 0, 0));
    }

    /**
     * The SQL fragment must carry case 3 too. Court and Report splice this into
     * queries, so if the CASE arm were dropped the PHP and SQL answers would
     * diverge -- and the SQL side is what drives the Recommendations Manager's
     * filters, where a disagreement shows up as pages coming back short.
     */
    public function testSqlEffectiveIsLadderCarriesTheCuratedIds(): void
    {
        $sql = Award::sqlEffectiveIsLadder('ka', 'a');

        $this->assertStringContainsString('ka.is_ladder', $sql);
        $this->assertStringContainsString('a.is_ladder', $sql);
        foreach (Award::pseudoLadderKingdomAwardIds() as $id) {
            $this->assertStringContainsString((string) (int) $id, $sql);
        }
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

        $ladderPos = strpos($html, "optgroup label='Ladder Awards'");
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
     * @return list<int>
     */
    private function mirrorPseudoLadderIds(): array
    {
        // Delegate, never copy. This held its own stale duplicate of the list --
        // 24 ids against the production 25 -- so the categorisation below was
        // quietly bucketing a kingdom-original ladder as an ordinary award.
        return Award::pseudoLadderKingdomAwardIds();
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
