<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The star pill: recognition past the top of a ladder. Once a player is at or
 * above an award's max rank, the star offers an unranked (rank = 0) grant
 * instead of extending the ladder past its real max (kingdom-ladder-awards
 * spec, Task 7B).
 */
final class StarPillTest extends TestCase
{
    public function testStarIsOfferedAtMaxRank(): void
    {
        $this->assertTrue(Award::OffersStar(21, 0, 10));
    }

    public function testStarIsNotOfferedBelowMaxRank(): void
    {
        $this->assertFalse(Award::OffersStar(21, 0, 9));
        $this->assertFalse(Award::OffersStar(21, 0, 0));
    }

    public function testStarIsOfferedAboveMaxRank(): void
    {
        // Imported records can already exceed max; they still get the star.
        $this->assertTrue(Award::OffersStar(21, 0, 14));
    }

    public function testStarIsAvailableOnOfficialLaddersDespiteTheirLockedMax(): void
    {
        $this->assertTrue(Award::OffersStar(21, 0, 10));
        $this->assertTrue(Award::OffersStar(30, 0, 12));
        $this->assertFalse(Award::OffersStar(30, 0, 10), 'Zodiac max is 12, not 10');
    }

    public function testStarOnAKingdomLadderUsesTheKingdomMax(): void
    {
        $this->assertTrue(Award::OffersStar(0, 5, 5));
        $this->assertFalse(Award::OffersStar(0, 5, 4));
    }
}
