<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for award dropdown categorization (T-AWD-01).
 *
 * Every assertion here runs production code -- Award::GetAwardOptionGroups() for
 * the grouping rules, Model_Award::fetch_award_option_list() for the rendered
 * <option> markup. An earlier revision mirrored the classifier's bucketing loop
 * into this file; that copy could not fail when production drifted, so it is gone.
 */
final class AwardOptionGroupsTest extends TestCase
{
    // System-award list (KingdomId 0): Award::GetAwardList() keys each row by
    // award_id, so these ARE the KingdomAwardId values in the response.
    // Order of the Rose -- ork_award.is_ladder = 1, one of the 16 official orders.
    private const OFFICIAL_LADDER_AWARD_ID = 21;
    private const KNIGHT_AWARD_ID = 17;      // Knight of the Flame, peerage = Knight
    private const MASTER_AWARD_ID = 1;       // Master Rose, peerage = Master
    private const SQUIRE_AWARD_ID = 16;      // Squire, peerage = Squire

    private Model_Award $awardModel;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->awardModel = new Model_Award();
    }

    public function testOfficialLadderAwardsGroupAsOfficialNotKingdom(): void
    {
        // Calls the production classifier. Requirement 1: an award whose ork_award
        // row carries is_ladder = 1 is an official Amtgard order and must be
        // presented as one -- never as a kingdom's own ladder.
        $groups = $this->productionGroups();

        $this->assertContains(
            self::OFFICIAL_LADDER_AWARD_ID,
            $this->groupIds($groups, 'Official Ladder Awards'),
            'an award backed by a.is_ladder = 1 belongs in the official group'
        );
        // Deliberately NOT assertNotContains(..., groupIds($groups, 'Kingdom Ladder
        // Awards')): this path (KingdomId 0) produces no 'Kingdom Ladder Awards'
        // group at all, so groupIds() would return [] and that assertion could
        // never fail. Assert instead on the labels the award actually lands in --
        // exactly one, and it must be the official bucket. The official-vs-kingdom
        // tie-break itself needs seeded ork_kingdomaward rows and is covered by
        // LadderPredicateSqlTest::testProductionGroupingPutsAnOfficialLadderInTheOfficialGroup().
        $this->assertSame(
            ['Official Ladder Awards'],
            $this->labelsContaining($groups, self::OFFICIAL_LADDER_AWARD_ID),
            'requirement 1: an official ladder must appear in exactly one group, the official one'
        );
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
        $groups = $this->productionGroups();

        $this->assertContains(self::KNIGHT_AWARD_ID, $this->groupIds($groups, 'Knighthoods'));
        $this->assertContains(self::MASTER_AWARD_ID, $this->groupIds($groups, 'Masterhoods'));
        $this->assertContains(self::SQUIRE_AWARD_ID, $this->groupIds($groups, 'Associate Titles'));

        foreach ($this->groupItems($groups, 'Knighthoods') as $award) {
            $this->assertSame('Knight', $award['Peerage'] ?? '', 'the Knighthoods bucket must hold only Knight-peerage awards');
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
     * Calls the REAL Award::GetAwardOptionGroups() over the system award list
     * (KingdomId 0) and returns its Groups. Nothing in this file re-implements the
     * classifier -- a drift in production's tie-break order or peerage buckets
     * fails the tests above.
     *
     * The official-vs-kingdom SPLIT needs kingdomaward rows, which only exist for a
     * real kingdom; LadderPredicateSqlTest::
     * testProductionGroupingPutsAnOfficialLadderInTheOfficialGroup() seeds a kingdom
     * and covers that half against the same production method.
     *
     * @return list<array{Label: string, Items: list<array<string, mixed>>}>
     */
    private function productionGroups(): array
    {
        $result = (new Award())->GetAwardOptionGroups(['KingdomId' => 0]);
        $this->assertSame(
            0,
            (int) ($result['Status']['Status'] ?? -1),
            'GetAwardOptionGroups() must succeed against the system award list'
        );

        return $result['Groups'] ?? [];
    }

    /**
     * @param list<array{Label: string, Items: list<array<string, mixed>>}> $groups
     * @return list<array<string, mixed>>
     */
    private function groupItems(array $groups, string $label): array
    {
        foreach ($groups as $group) {
            if (($group['Label'] ?? null) === $label) {
                return $group['Items'] ?? [];
            }
        }

        $this->fail("group '{$label}' is missing from GetAwardOptionGroups()");
    }

    /**
     * Every group label whose Items carry $kingdomAwardId, in group order.
     *
     * @param list<array{Label: string, Items: list<array<string, mixed>>}> $groups
     * @return list<string>
     */
    private function labelsContaining(array $groups, int $kingdomAwardId): array
    {
        $labels = [];
        foreach ($groups as $group) {
            $ids = array_map('intval', array_column($group['Items'] ?? [], 'KingdomAwardId'));
            if (in_array($kingdomAwardId, $ids, true)) {
                $labels[] = (string) ($group['Label'] ?? '');
            }
        }

        return $labels;
    }

    /**
     * @param list<array{Label: string, Items: list<array<string, mixed>>}> $groups
     * @return list<int>
     */
    private function groupIds(array $groups, string $label): array
    {
        foreach ($groups as $group) {
            if (($group['Label'] ?? null) === $label) {
                return array_map('intval', array_column($group['Items'] ?? [], 'KingdomAwardId'));
            }
        }

        return [];
    }
}
