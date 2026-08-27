<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ZodiacMonthTest extends TestCase
{
    public function testMonthInitialsSpellJFMAMJJASOND(): void
    {
        $initials = '';
        for ($month = 1; $month <= 12; $month++) {
            $initials .= Award::MonthInitial($month);
        }
        $this->assertSame('JFMAMJJASOND', $initials);
    }

    public function testMonthInitialIsEmptyOutsideTheYear(): void
    {
        $this->assertSame('', Award::MonthInitial(0));
        $this->assertSame('', Award::MonthInitial(13));
        $this->assertSame('', Award::MonthInitial(-1));
    }

    public function testMonthNameGivesTheFullName(): void
    {
        $this->assertSame('January', Award::MonthName(1));
        $this->assertSame('July', Award::MonthName(7));
        $this->assertSame('December', Award::MonthName(12));
        $this->assertSame('', Award::MonthName(0));
        $this->assertSame('', Award::MonthName(13));
    }

    public function testOnlyOneThroughTwelveAreValid(): void
    {
        for ($month = 1; $month <= 12; $month++) {
            $this->assertTrue(Award::IsValidZodiacMonth($month));
        }
        $this->assertFalse(Award::IsValidZodiacMonth(0));
        $this->assertFalse(Award::IsValidZodiacMonth(13));
        $this->assertFalse(Award::IsValidZodiacMonth(-1));
    }

    public function testMonthFromGrantDate(): void
    {
        // The grant date is a strong month signal: Zodiac grant dates are near-uniform
        // at 254-364 per month, the fingerprint of a genuinely monthly award.
        $this->assertSame(3, Award::ZodiacMonthFromDate('2024-03-15'));
        $this->assertSame(12, Award::ZodiacMonthFromDate('2024-12-01 09:30:00'));
    }

    public function testMonthFromUnusableDateIsZero(): void
    {
        // '0000-00-00' is endemic in this corpus.
        $this->assertSame(0, Award::ZodiacMonthFromDate('0000-00-00'));
        $this->assertSame(0, Award::ZodiacMonthFromDate(''));
        $this->assertSame(0, Award::ZodiacMonthFromDate('not a date'));
    }
}
