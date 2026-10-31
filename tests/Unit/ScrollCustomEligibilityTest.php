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
     * Master Hunter and Master Archer are deliberately absent from ARCHERY_NAMES: no
     * ladder gives its Master tier the ladder's own artwork.
     *
     * They used to fall through to the custom scroll. Under the allowlist that
     * depends on what they are hung from: base 131 is not an admitted id and no
     * ork_award row carries it, so it prints nothing at all; a kingdom that built one
     * on Custom Award still gets the CUSTOM scroll — never the hunter art, which is
     * the assertion that actually matters here.
     */
    public function testArcheryMastersNeverGetTheHunterArtwork(): void
    {
        foreach (['Master Hunter', 'Master Archer'] as $name) {
            $this->assertNull(
                Scroll::OrderForAwardRow($this->row(['AwardId' => 131, 'KingdomAwardName' => $name])),
                "{$name} on an unadmitted base id should not print"
            );

            $order = Scroll::OrderForAwardRow($this->row(['AwardId' => 94, 'KingdomAwardName' => $name]));
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

    /**
     * The archery-name match must not outrank the officer-position exclusion: a
     * kingdom naming an officer role "Hunter" (the bare word is in ARCHERY_NAMES)
     * must not get the hunter certificate for a job someone held.
     */
    public function testAnOfficerRoleNamedHunterIsStillNotPrintable(): void
    {
        $row = $this->row(['OfficerRole' => 'other-officer', 'KingdomAwardName' => 'Hunter']);

        $this->assertNull(Scroll::OrderForAwardRow($row));
        $this->assertFalse(Scroll::HasArt($row));
    }

    /**
     * ONLY the two custom catalogue entries take the custom scroll.
     *
     * This test used to say the opposite — that everything with no specific order got
     * the custom scroll, and it listed Lord, Master, Esquire and Walker in the Middle
     * as proof. The allowlist reverses that: the custom scroll is not a fallback, it
     * is the artwork for Custom Award (94) and Custom Title (246), and nothing else
     * reaches it.
     *
     * IsCustomEligible() still says TRUE for all four of the refused rows — that
     * function answers only "is this an honour rather than a job", and deliberately
     * does not carry the policy — but OrderForAwardRow() refuses them, which is the
     * gate every surface actually goes through. See ScrollExcludedAwardsTest.
     */
    public function testOnlyTheTwoCustomEntriesTakeTheCustomScroll(): void
    {
        foreach ([94 => 'Custom Award', 246 => 'Custom Title'] as $id => $name) {
            $row = $this->row(['AwardId' => $id, 'KingdomAwardName' => $name]);
            $this->assertSame('custom', Scroll::OrderForAwardRow($row)['id'], "{$name} should print");
            $this->assertTrue(Scroll::HasArt($row));
        }

        foreach ([
            ['AwardId' => 52,  'KingdomAwardName' => 'Lord'],
            ['AwardId' => 226, 'KingdomAwardName' => 'Master'],
            ['AwardId' => 209, 'KingdomAwardName' => 'Esquire'],
            ['AwardId' => 31,  'KingdomAwardName' => 'Order of the Walker in the Middle'],
        ] as $o) {
            $row = $this->row($o);
            $this->assertTrue(Scroll::IsCustomEligible($row), "{$o['KingdomAwardName']} is still an honour, not a job");
            $this->assertNull(Scroll::OrderForAwardRow($row), "{$o['KingdomAwardName']} must not print");
            $this->assertFalse(Scroll::HasArt($row));
        }
    }

    /**
     * The custom scroll must still be able to carry a numeral: a kingdom's own ladder
     * built on Custom Award (Award::pseudoLadderKingdomAwardIds() is exactly that
     * shape) is ranked, and prints on this scroll.
     */
    public function testTheUncoveredLadderIsRankedNotFlat(): void
    {
        $custom = Scroll::Orders()['custom'];
        $this->assertSame(0, $custom['ranks']['from']);
        $this->assertSame(10, $custom['ranks']['to']);
    }

    /**
     * A single `?? 'none'` used to serve both an ABSENT OfficerRole key and a
     * PRESENT-but-null one identically. They need opposite treatment: an absent key
     * means whatever built this row forgot to say what it is (fail closed — treat
     * as an officer position, print nothing); a present null is the real archery
     * shape (the LEFT JOIN to officer_role misses for its dangling award_id) and
     * must stay open, or archery stops printing.
     */
    public function testAnAbsentOfficerRoleKeyFailsClosedButAPresentNullStaysOpen(): void
    {
        $noKeyAtAll = ['AwardId' => 33, 'KingdomAwardName' => 'Order of the Griffon'];
        $this->assertFalse(Scroll::IsCustomEligible($noKeyAtAll));
        $this->assertNull(Scroll::OrderForAwardRow($noKeyAtAll));
        $this->assertFalse(Scroll::HasArt($noKeyAtAll));

        $presentButNull = $this->row(['OfficerRole' => null, 'KingdomAwardName' => 'Order of the Hunter']);
        $this->assertTrue(Scroll::IsCustomEligible($presentButNull));
        $this->assertSame('hunter', Scroll::OrderForAwardRow($presentButNull)['id']);
        $this->assertTrue(Scroll::HasArt($presentButNull));

        // Award 94 (Custom Award), because this case is about the OfficerRole key and
        // not the policy: it needs a row the allowlist admits, and 52/1 are no longer
        // among them.
        $presentButEmptyString = $this->row(['OfficerRole' => '', 'AwardId' => 94, 'KingdomAwardName' => 'Custom Award']);
        $this->assertTrue(Scroll::IsCustomEligible($presentButEmptyString));
        $this->assertSame('custom', Scroll::OrderForAwardRow($presentButEmptyString)['id']);
    }
}
