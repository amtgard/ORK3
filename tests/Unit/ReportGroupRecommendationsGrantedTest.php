<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Report::groupRecommendations (pure transform) with "Show granted & dismissed" on:
 * a closed cluster is Granted when any member was granted, and its provenance
 * (DismissedAt / DismissedByPersona) names the grant, never a later dismissal.
 */
final class ReportGroupRecommendationsGrantedTest extends TestCase
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
        ];
    }

    public function testDismissedOnlyClusterIsNotGranted(): void
    {
        $g = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'DismissedAt' => '2026-02-01 10:00:00', 'DismissedByPersona' => 'Ana']),
        ]);
        $this->assertCount(1, $g);
        $this->assertTrue($g[0]['IsDismissed']);
        $this->assertFalse($g[0]['IsGranted']);
        $this->assertSame('Ana', $g[0]['DismissedByPersona']);
    }

    public function testGrantedMemberMakesTheClusterGrantedAndNamesTheGrant(): void
    {
        $g = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'IsGranted' => true, 'DismissedAt' => '2026-02-01 10:00:00', 'DismissedByPersona' => 'Granter']),
            // A later plain dismissal of a sibling filing must not take over the label.
            $this->rec(2, ['IsDismissed' => true, 'DismissedAt' => '2026-03-01 10:00:00', 'DismissedByPersona' => 'Dismisser']),
        ]);
        $this->assertCount(1, $g);
        $this->assertTrue($g[0]['IsDismissed']);
        $this->assertTrue($g[0]['IsGranted']);
        $this->assertSame('Granter', $g[0]['DismissedByPersona']);
        $this->assertSame('2026-02-01 10:00:00', $g[0]['DismissedAt']);
    }

    public function testLiveClusterIsNeverGranted(): void
    {
        $g = $this->report()->groupRecommendations([
            $this->rec(1, ['IsDismissed' => true, 'IsGranted' => true, 'DismissedAt' => '2026-02-01 10:00:00']),
            $this->rec(2),
        ]);
        $this->assertCount(1, $g);
        $this->assertFalse($g[0]['IsDismissed']);
        $this->assertFalse($g[0]['IsGranted']);
    }
}
