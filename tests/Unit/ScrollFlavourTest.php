<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Arcanyst's flavour text carries two placeholder forms, which its viewmodel
 * expanded before display. Transliterating the data without them prints the raw
 * markup on the certificate.
 *
 *   <<ISSUER>>                    the issuing park or kingdom's name
 *   <<prefix|MONTH|suffix|else>>  prefix + month + suffix when a month is known,
 *                                 otherwise the else branch verbatim
 *
 * Month handling proper is a separate branch. Phase 1 always takes the else
 * branch, which is what those strings were written for.
 */
final class ScrollFlavourTest extends TestCase
{
    public function testIssuerPlaceholderIsReplacedWithTheIssuingBody(): void
    {
        $this->assertSame(
            'a shining star of Crimson Moon, truly',
            Scroll::ExpandFlavour('a shining star of <<ISSUER>>, truly', 'Crimson Moon')
        );
    }

    public function testEveryIssuerOccurrenceIsReplacedNotJustTheFirst(): void
    {
        $this->assertSame(
            'Xyz and Xyz',
            Scroll::ExpandFlavour('<<ISSUER>> and <<ISSUER>>', 'Xyz')
        );
    }

    public function testMonthBlockCollapsesToItsElseBranch(): void
    {
        $this->assertSame(
            'The spirit you bring to the game for whole months to remember.',
            Scroll::ExpandFlavour(
                'The spirit you bring to the game << made |MONTH| a month|for whole months>> to remember.',
                'Anywhere'
            )
        );
    }

    public function testAnEmptyElseBranchCollapsesToNothing(): void
    {
        $this->assertSame(
            'To reflect your exemplary contributions.',
            Scroll::ExpandFlavour(
                'To reflect your exemplary contributions<< during the month of |MONTH||>>.',
                'Anywhere'
            )
        );
    }

    public function testAKnownMonthUsesThePrefixAndSuffixInstead(): void
    {
        $this->assertSame(
            "You were March's shining star",
            Scroll::ExpandFlavour("<<You were |MONTH|'s|You're a>> shining star", 'Anywhere', 'March')
        );
    }

    /**
     * The guard that actually matters: no order, at any rank, may print a raw
     * placeholder. This fails the moment a new flavour string uses a form the
     * expander does not know.
     */
    public function testNoOrderPrintsARawPlaceholderAtAnyRank(): void
    {
        foreach (Scroll::Orders() as $id => $order) {
            for ($rank = $order['ranks']['from']; $rank <= $order['ranks']['to']; $rank++) {
                $raw = (string) ($order['flavour'][(string) $rank] ?? '');
                $out = Scroll::ExpandFlavour($raw, 'Crimson Moon');

                $this->assertStringNotContainsString('<<', $out, "{$id} rank {$rank} leaks a placeholder");
                $this->assertStringNotContainsString('>>', $out, "{$id} rank {$rank} leaks a placeholder");
                $this->assertStringNotContainsString('|MONTH|', $out, "{$id} rank {$rank} leaks a month marker");
            }
        }
    }

    public function testBuildScrollExpandsTheFlavourItHandsToThePartial(): void
    {
        $scroll = Scroll::BuildScroll(
            ['AwardId' => 30, 'Rank' => 10, 'KingdomAwardName' => 'Order of the Zodiac', 'ParkName' => 'Crimson Moon'],
            ['player' => 'Testerly', 'issuerTitle' => 'Outpost']
        );

        $this->assertStringNotContainsString('<<', $scroll['flavour']);
        $this->assertStringContainsString('Crimson Moon', $scroll['flavour']);
    }

    /** Text with no placeholders must come through completely untouched. */
    public function testOrdinaryFlavourIsUnchanged(): void
    {
        $plain = "In victory or defeat, your honour is second to none.";
        $this->assertSame($plain, Scroll::ExpandFlavour($plain, 'Anywhere'));
    }
}
