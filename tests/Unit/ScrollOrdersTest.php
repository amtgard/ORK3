<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollOrdersTest extends TestCase
{
    public function testEveryOrderHasArtDirectoryAndAwardId(): void
    {
        $orders = Scroll::Orders();
        $this->assertCount(16, $orders);

        foreach ($orders as $id => $order) {
            $this->assertSame($id, $order['id']);
            $this->assertGreaterThan(0, $order['award_id'], "{$id} has no award_id");
            $this->assertDirectoryExists(DIR_ASSETS . 'scrolls/' . $id, "{$id} art missing");
        }
    }

    public function testRanksDefaultToZeroThroughTenExceptWarrior(): void
    {
        $orders = Scroll::Orders();
        $this->assertSame(['from' => 0, 'to' => 10], $orders['griffon']['ranks']);
        $this->assertSame(['from' => 1, 'to' => 10], $orders['warrior']['ranks']);
        $this->assertSame(['from' => 0, 'to' => 10], $orders['zodiac']['ranks']);
    }

    public function testResourcesDefaultToFlourishesAndOverridesMergeOverThem(): void
    {
        $orders = Scroll::Orders();

        $this->assertSame(
            'scrolls/common/text/squiggly-top.svg',
            $orders['griffon']['resources']['flourish_top']
        );
        $this->assertSame(
            'scrolls/common/text/writing-line.svg',
            $orders['griffon']['resources']['writing_line']
        );

        // jovious and rose override the flourishes only — writing_line is inherited.
        $this->assertSame(
            'scrolls/jovious/ribbon-top.png',
            $orders['jovious']['resources']['flourish_top']
        );
        $this->assertSame(
            'scrolls/common/text/writing-line.svg',
            $orders['jovious']['resources']['writing_line']
        );
    }

    public function testVariantOrdersDeclareTheirVariants(): void
    {
        $orders = Scroll::Orders();
        $this->assertSame(['arcane', 'martial'], $orders['battle']['variants']);
        // parchment leads deliberately: BuildScroll falls back to variants[0], and
        // Arcanyst's own ordering put cyanotype — a dark blue plate with white text
        // and inverted heraldry — first. See the note in scroll-orders.php.
        $this->assertSame(['parchment', 'cyanotype', 'transparency'], $orders['owl']['variants']);
        $this->assertSame([], $orders['griffon']['variants']);
    }

    public function testOrderLookupByAwardIdCoversTheLaddersAndReturnsNullOtherwise(): void
    {
        $this->assertSame('rose', Scroll::OrderForAwardId(21)['id']);
        $this->assertSame('griffon', Scroll::OrderForAwardId(33)['id']);
        $this->assertSame('crown', Scroll::OrderForAwardId(239)['id']);
        $this->assertSame('battle', Scroll::OrderForAwardId(243)['id']);
        $this->assertSame('flame', Scroll::OrderForAwardId(34)['id']);

        // Master Rose has no certificate art.
        $this->assertNull(Scroll::OrderForAwardId(1));
        $this->assertNull(Scroll::OrderForAwardId(999999));
    }

    public function testEveryLadderInTheAwardCatalogueHasArt(): void
    {
        foreach (array_keys(Award::GetLadderMasterMap()) as $awardId) {
            $this->assertNotNull(
                Scroll::OrderForAwardId((int) $awardId),
                "ladder award {$awardId} has no scroll order"
            );
        }
    }

    public function testFlavourTextCoversEveryRankInTheOrderRange(): void
    {
        foreach (Scroll::Orders() as $id => $order) {
            for ($r = $order['ranks']['from']; $r <= $order['ranks']['to']; $r++) {
                $this->assertArrayHasKey((string) $r, $order['flavour'], "{$id} has no rank-{$r} flavour");
            }
        }

        // Rank 0 is the generic, unranked certificate. Every order but warrior has one.
        $this->assertArrayHasKey('0', Scroll::Orders()['griffon']['flavour']);
        $this->assertArrayNotHasKey('0', Scroll::Orders()['warrior']['flavour']);
    }
}
