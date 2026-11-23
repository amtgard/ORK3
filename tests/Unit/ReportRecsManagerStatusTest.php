<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Recs Manager lifecycle: the server-side status view (Open / Granted / Dismissed /
 * All closed) and the granted award a closed cluster links to.
 */
final class ReportRecsManagerStatusTest extends TestCase
{
    private function report(): Report
    {
        return (new ReflectionClass(Report::class))->newInstanceWithoutConstructor();
    }

    private function rec(int $id, array $over = []): array
    {
        return $over + [
            'RecommendationsId' => $id,
            'MundaneId'         => 10,
            'KingdomAwardId'    => 20,
            'Rank'              => 3,
            'DateRecommended'   => '2026-01-01',
            'AgeDays'           => 30,
            'IsDismissed'       => false,
            'IsGranted'         => false,
            'GrantedAwardsId'   => 0,
        ];
    }

    public function testStatusDefaultsToOpenAndValidates(): void
    {
        $this->assertSame('open', Report::recsManagerStatus([]));
        $this->assertSame('granted', Report::recsManagerStatus(['Status' => 'Granted']));
        $this->assertSame('dismissed', Report::recsManagerStatus(['Status' => 'dismissed']));
        $this->assertSame('closed', Report::recsManagerStatus(['Status' => 'closed']));
        $this->assertSame('open', Report::recsManagerStatus(['Status' => "x' OR 1=1"]));
    }

    public function testLegacyDismissedFlagKeepsLivePlusClosed(): void
    {
        $this->assertSame('all', Report::recsManagerStatus(['IncludeDismissed' => true]));
        // An explicit status wins over the legacy flag.
        $this->assertSame('open', Report::recsManagerStatus(['Status' => 'open', 'IncludeDismissed' => true]));
    }

    public function testGrantedClusterCarriesTheGrantsAward(): void
    {
        $g = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'IsGranted' => true, 'GrantedAwardsId' => 501, 'DismissedAt' => '2026-02-01 10:00:00']),
            $this->rec(2, ['IsDismissed' => true, 'DismissedAt' => '2026-03-01 10:00:00']),
        ]);
        $this->assertCount(1, $g);
        $this->assertTrue($g[0]['IsGranted']);
        $this->assertSame(501, $g[0]['GrantedAwardsId']);
    }

    public function testNewestGrantWithoutAnAwardFallsBackToALinkedOne(): void
    {
        $g = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'IsGranted' => true, 'GrantedAwardsId' => 0, 'DismissedAt' => '2026-03-01 10:00:00']),
            $this->rec(2, ['IsDismissed' => true, 'IsGranted' => true, 'GrantedAwardsId' => 777, 'DismissedAt' => '2026-02-01 10:00:00']),
        ]);
        $this->assertSame(777, $g[0]['GrantedAwardsId']);
    }

    public function testDismissedAndLiveClustersLinkNoAward(): void
    {
        $dismissed = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'DismissedAt' => '2026-02-01 10:00:00']),
        ]);
        $this->assertSame(0, $dismissed[0]['GrantedAwardsId']);

        $live = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'IsGranted' => true, 'GrantedAwardsId' => 501, 'DismissedAt' => '2026-02-01 10:00:00']),
            $this->rec(2),
        ]);
        $this->assertFalse($live[0]['IsGranted']);
        $this->assertSame(0, $live[0]['GrantedAwardsId']);
    }
}
