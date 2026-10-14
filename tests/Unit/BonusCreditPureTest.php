<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The pure parts of BonusCredit: scope coverage and the two input normalisers.
 * No database.
 */
final class BonusCreditPureTest extends TestCase
{
    public function testAKingdomCoversItsOwnAndItsPrincipalitiesPlayers(): void
    {
        $home   = ['park_id' => 10, 'kingdom_id' => 3];
        $prince = ['park_id' => 20, 'kingdom_id' => 7];   // 7 is a principality of 3
        $other  = ['park_id' => 30, 'kingdom_id' => 4];
        $family = [3, 7];
        $this->assertTrue(BonusCredit::covers('kingdom', 3, $home, $family));
        $this->assertTrue(BonusCredit::covers('kingdom', 3, $prince, $family));
        $this->assertFalse(BonusCredit::covers('kingdom', 3, $other, $family));
        // Without the family list a principality's player is not covered: the caller must supply it.
        $this->assertFalse(BonusCredit::covers('kingdom', 3, $prince));
        $this->assertFalse(BonusCredit::covers('kingdom', 3, ['park_id' => 0, 'kingdom_id' => 0], $family));
        $this->assertFalse(BonusCredit::covers('kingdom', 0, $home, $family));
    }

    public function testTheReservedAmtgardScopeCoversAnyoneWithAHome(): void
    {
        $this->assertTrue(BonusCredit::covers('ork', 0, ['park_id' => 10, 'kingdom_id' => 3]));
        $this->assertFalse(BonusCredit::covers('ork', 0, ['park_id' => 0, 'kingdom_id' => 0]));
    }

    public function testParksAndUnknownScopesDefineNothingSoCoverNobody(): void
    {
        $player = ['park_id' => 10, 'kingdom_id' => 3];
        $this->assertFalse(BonusCredit::covers('park', 10, $player, [3]));
        $this->assertFalse(BonusCredit::covers('unit', 3, $player, [3]));
    }

    public function testCreditsAreQuartersBetweenAQuarterAndTheCap(): void
    {
        $this->assertSame(1.0, BonusCredit::normalizeCredits(1));
        $this->assertSame(1.0, BonusCredit::normalizeCredits('1'));
        $this->assertSame(0.25, BonusCredit::normalizeCredits('0.25'));
        $this->assertSame(2.5, BonusCredit::normalizeCredits(2.5));
        $this->assertSame(BonusCredit::MAX_CREDITS, BonusCredit::normalizeCredits(BonusCredit::MAX_CREDITS));
        $this->assertNull(BonusCredit::normalizeCredits(0));
        $this->assertNull(BonusCredit::normalizeCredits(-1));
        $this->assertNull(BonusCredit::normalizeCredits(0.3));
        $this->assertNull(BonusCredit::normalizeCredits(BonusCredit::MAX_CREDITS + 0.25));
        $this->assertNull(BonusCredit::normalizeCredits('lots'));
        $this->assertNull(BonusCredit::normalizeCredits(''));
    }

    public function testDatesAreRealNotFutureAndNotPrehistoric(): void
    {
        $today     = date('Y-m-d');
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $tomorrow  = date('Y-m-d', strtotime('+1 day'));
        $this->assertSame($today, BonusCredit::normalizeDate($today));
        $this->assertSame($yesterday, BonusCredit::normalizeDate($yesterday));
        $this->assertNull(BonusCredit::normalizeDate($tomorrow));
        $this->assertNull(BonusCredit::normalizeDate('2026-02-30'));
        $this->assertNull(BonusCredit::normalizeDate('1982-12-31'));
        $this->assertNull(BonusCredit::normalizeDate('10/14/2026'));
        $this->assertNull(BonusCredit::normalizeDate(''));
    }
}
