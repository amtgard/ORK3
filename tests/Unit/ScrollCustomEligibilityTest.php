<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ScrollCustomEligibilityTest extends TestCase
{
    private function row(array $o = []): array
    {
        return array_merge([
            'AwardId'          => 0,
            'KingdomAwardName' => '',
            'Name'             => '',
            'OfficerRole'      => 'none',
        ], $o);
    }

    /** The 16 specific orders keep their own artwork. */
    public function testASpecificOrderStillWins(): void
    {
        $this->assertSame('griffon', Scroll::OrderForAwardRow($this->row(['AwardId' => 33]))['id']);
        $this->assertSame('battle', Scroll::OrderForAwardRow($this->row(['AwardId' => 243]))['id']);
    }

    /**
     * Archery has no base award id to key on — real grants hang off award_id 0, 94
     * and 131 — so the kingdomaward NAME is the key.
     */
    public function testArcheryResolvesByName(): void
    {
        foreach ([
            'Order of the Hunter',
            'Order of the Archer',
            'Order of the Marksman',
            'Order of the Sharpshooter',
            'Hunter',
        ] as $name) {
            $order = Scroll::OrderForAwardRow($this->row(['AwardId' => 94, 'KingdomAwardName' => $name]));
            $this->assertSame('hunter', $order['id'], "{$name} should print the archery certificate");
        }
    }

    public function testArcheryMatchingIgnoresCaseAndSurroundingSpace(): void
    {
        $order = Scroll::OrderForAwardRow($this->row([
            'AwardId' => 0, 'KingdomAwardName' => '  order of the MARKSMAN  ',
        ]));
        $this->assertSame('hunter', $order['id']);
    }

    /**
     * Master Hunter and Master Archer deliberately fall through to the custom scroll:
     * no other ladder gives its Master tier the ladder's own artwork.
     */
    public function testArcheryMastersFallThroughToTheCustomScroll(): void
    {
        foreach (['Master Hunter', 'Master Archer'] as $name) {
            $order = Scroll::OrderForAwardRow($this->row(['AwardId' => 131, 'KingdomAwardName' => $name]));
            $this->assertSame('custom', $order['id'], "{$name} should get the custom scroll, not hunter art");
        }
    }

    /** An officer position is a job held, not an honour given. */
    public function testOfficerPositionsAreNotPrintable(): void
    {
        $row = $this->row(['AwardId' => 189, 'KingdomAwardName' => 'Guildmaster of Archers', 'OfficerRole' => 'other-officer']);

        $this->assertFalse(Scroll::IsCustomEligible($row));
        $this->assertNull(Scroll::OrderForAwardRow($row));
        $this->assertFalse(Scroll::HasArt($row));
    }

    /** Everything else with no specific order gets the custom scroll. */
    public function testTitlesPeeragesAndPlainAwardsGetTheCustomScroll(): void
    {
        foreach ([
            ['AwardId' => 17, 'KingdomAwardName' => 'Knight of the Flame'],
            ['AwardId' => 1,  'KingdomAwardName' => 'Master Rose'],
            ['AwardId' => 38, 'KingdomAwardName' => 'Paragon Archer'],
            ['AwardId' => 31, 'KingdomAwardName' => 'Order of the Walker in the Middle'],
        ] as $o) {
            $row = $this->row($o);
            $this->assertTrue(Scroll::IsCustomEligible($row), "{$o['KingdomAwardName']} should be eligible");
            $this->assertSame('custom', Scroll::OrderForAwardRow($row)['id']);
            $this->assertTrue(Scroll::HasArt($row));
        }
    }

    /**
     * Award 31 is a genuine RANKED ladder Arcanyst never drew, so its custom scroll
     * must still be able to carry a numeral.
     */
    public function testTheUncoveredLadderIsRankedNotFlat(): void
    {
        $custom = Scroll::Orders()['custom'];
        $this->assertSame(0, $custom['ranks']['from']);
        $this->assertSame(10, $custom['ranks']['to']);
    }
}
