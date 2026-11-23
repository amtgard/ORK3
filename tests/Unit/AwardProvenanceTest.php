<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pure halves of the profile's (i) award-info modal: which recs count as "for
 * this award", the masked-giver shape, and the tracking labels.
 */
final class AwardProvenanceTest extends TestCase
{
    public function testLadderMatchesSameRankOnly(): void
    {
        $sql = AwardProvenance::recMatchSql(['KingdomAwardId' => 6199, 'AwardId' => 30, 'Rank' => 2, 'IsLadder' => true]);
        $this->assertSame('(r.kingdomaward_id = 6199 OR r.award_id = 30) AND COALESCE(r.rank, 0) = 2', $sql);
    }

    public function testNonLadderMatchesAnyRank(): void
    {
        $sql = AwardProvenance::recMatchSql(['KingdomAwardId' => 12, 'AwardId' => 7, 'Rank' => 0, 'IsLadder' => false]);
        $this->assertSame('(r.kingdomaward_id = 12 OR r.award_id = 7)', $sql);
    }

    public function testGenericAwardIdsNeverMatchByAwardId(): void
    {
        foreach ([94, 246] as $generic) {
            $sql = AwardProvenance::recMatchSql(['KingdomAwardId' => 55, 'AwardId' => $generic, 'IsLadder' => false]);
            $this->assertSame('(r.kingdomaward_id = 55)', $sql);
        }
    }

    public function testNoIdsMatchesNothing(): void
    {
        $this->assertSame('0 = 1', AwardProvenance::recMatchSql(['KingdomAwardId' => 0, 'AwardId' => 94]));
    }

    public function testACourtLineRecIsTheOnlyRecBehindTheAward(): void
    {
        $sql = AwardProvenance::recMatchSql([
            'KingdomAwardId' => 12, 'AwardId' => 7, 'CourtRecId' => 4411,
            'WindowStart' => '2020-01-01', 'WindowEnd' => '2024-05-01',
        ]);
        $this->assertSame('r.recommendations_id = 4411', $sql);
    }

    public function testRecsAreWindowedBetweenThePreviousInstanceAndThisOne(): void
    {
        $sql = AwardProvenance::recMatchSql([
            'KingdomAwardId' => 12, 'AwardId' => 7, 'Rank' => 0, 'IsLadder' => false,
            'WindowStart' => '2020-01-01', 'WindowEnd' => '2024-05-01',
        ]);
        $this->assertSame(
            "(r.kingdomaward_id = 12 OR r.award_id = 7) AND r.date_recommended <= '2024-05-01' AND r.date_recommended > '2020-01-01'",
            $sql
        );
        $first = AwardProvenance::recMatchSql([
            'KingdomAwardId' => 12, 'AwardId' => 7, 'IsLadder' => false,
            'WindowStart' => '', 'WindowEnd' => '2024-05-01',
        ]);
        $this->assertSame("(r.kingdomaward_id = 12 OR r.award_id = 7) AND r.date_recommended <= '2024-05-01'", $first);
        // Malformed dates never reach the SQL.
        $bad = AwardProvenance::recMatchSql(['KingdomAwardId' => 12, 'WindowEnd' => "x' OR 1=1"]);
        $this->assertSame('(r.kingdomaward_id = 12)', $bad);
    }

    public function testMaskedGiverLosesNameAndId(): void
    {
        $rec = AwardProvenance::shapeRec([
            'recommendations_id' => 9, 'recommended_by_id' => 42, 'recommender_persona' => 'Secret Person',
            'mask_giver' => 1, 'reason' => 'Because', 'date_recommended' => '2026-01-02', 'seconds' => 3,
        ]);
        $this->assertTrue($rec['IsAnonymous']);
        $this->assertSame(0, $rec['RecommendedById']);
        $this->assertSame('', $rec['RecommendedBy']);
        $this->assertStringNotContainsString('Secret', json_encode($rec));
        $this->assertSame(3, $rec['Seconds']);
    }

    public function testUnmaskedGiverKeepsNameAndId(): void
    {
        $rec = AwardProvenance::shapeRec(['recommended_by_id' => 42, 'recommender_persona' => 'Known', 'mask_giver' => 0]);
        $this->assertFalse($rec['IsAnonymous']);
        $this->assertSame(42, $rec['RecommendedById']);
        $this->assertSame('Known', $rec['RecommendedBy']);
    }

    /** Not reachable from the public JSON service: not whitelisted, and underscored. */
    public function testReaderIsNotJsonCallable(): void
    {
        $index = file_get_contents(ORK3_ROOT . '/orkservice/Json/index.php');
        $this->assertStringNotContainsString("'AwardProvenance'", $index);
        $this->assertTrue(method_exists('AwardProvenance', 'award_provenance'));
        $this->assertFalse(method_exists('AwardProvenance', 'AwardProvenance'));
    }
}
