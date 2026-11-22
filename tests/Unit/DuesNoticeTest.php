<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * DuesNotice::classify / message / dismissKey: the pure window logic behind the
 * dues expiry banner and profile alert. No database.
 */
final class DuesNoticeTest extends TestCase
{
    private const TODAY = '2026-11-19';

    public function testNothingToSayForLifetimeNoDuesOrGarbage(): void
    {
        $this->assertNull(DuesNotice::classify('2026-11-21', true, self::TODAY));
        $this->assertNull(DuesNotice::classify('', false, self::TODAY));
        $this->assertNull(DuesNotice::classify(null, false, self::TODAY));
        $this->assertNull(DuesNotice::classify('0000-00-00', false, self::TODAY));
        $this->assertNull(DuesNotice::classify('next week', false, self::TODAY));
    }

    public function testExpiringWindowIsTodayThroughWarnDays(): void
    {
        $this->assertSame(['State' => 'expiring', 'Days' => 0, 'Until' => '2026-11-19'], DuesNotice::classify('2026-11-19', false, self::TODAY));
        $this->assertSame(['State' => 'expiring', 'Days' => 2, 'Until' => '2026-11-21'], DuesNotice::classify('2026-11-21', false, self::TODAY));
        $this->assertSame(['State' => 'expiring', 'Days' => 30, 'Until' => '2026-12-19'], DuesNotice::classify('2026-12-19', false, self::TODAY));
        $this->assertNull(DuesNotice::classify('2026-12-20', false, self::TODAY), 'day 31 is outside the window');
        $this->assertNull(DuesNotice::classify('2027-05-19', false, self::TODAY));
    }

    public function testLapsedWindowIsYesterdayThroughLapsedDays(): void
    {
        $this->assertSame(['State' => 'lapsed', 'Days' => 1, 'Until' => '2026-11-18'], DuesNotice::classify('2026-11-18', false, self::TODAY));
        $this->assertSame(['State' => 'lapsed', 'Days' => 30, 'Until' => '2026-10-20'], DuesNotice::classify('2026-10-20', false, self::TODAY));
        $this->assertNull(DuesNotice::classify('2026-10-19', false, self::TODAY), 'day 31 after is outside the window');
        $this->assertNull(DuesNotice::classify('2024-01-01', false, self::TODAY));
    }

    public function testDayCountsAreCalendarDaysAcrossDstAndMonthEnds(): void
    {
        // US DST ended 2026-11-01; a seconds-based diff would be off by an hour here.
        $this->assertSame(5, DuesNotice::classify('2026-11-03', false, '2026-10-29')['Days']);
        $this->assertSame(1, DuesNotice::classify('2026-12-01', false, '2026-11-30')['Days']);
        $this->assertSame(29, DuesNotice::classify('2027-03-01', false, '2027-01-31')['Days']);
    }

    public function testMessagesReadNaturally(): void
    {
        $this->assertSame('Your dues expire today.', DuesNotice::message(['State' => 'expiring', 'Days' => 0, 'Until' => '2026-11-19']));
        $this->assertSame('Your dues expire tomorrow (Nov 20).', DuesNotice::message(['State' => 'expiring', 'Days' => 1, 'Until' => '2026-11-20']));
        $this->assertSame('Your dues expire in 2 days (Nov 21).', DuesNotice::message(['State' => 'expiring', 'Days' => 2, 'Until' => '2026-11-21']));
        $this->assertSame('Your dues lapsed yesterday (Nov 18).', DuesNotice::message(['State' => 'lapsed', 'Days' => 1, 'Until' => '2026-11-18']));
        $this->assertSame('Your dues lapsed 12 days ago (Nov 7).', DuesNotice::message(['State' => 'lapsed', 'Days' => 12, 'Until' => '2026-11-07']));
    }

    public function testDismissKeyChangesOnRenewalAndOnLapse(): void
    {
        $expiring = DuesNotice::classify('2026-11-21', false, self::TODAY);
        $lapsed   = DuesNotice::classify('2026-11-21', false, '2026-11-25');
        $renewed  = DuesNotice::classify('2027-05-21', false, '2027-05-01');
        $this->assertSame('expiring:2026-11-21', DuesNotice::dismissKey($expiring));
        $this->assertNotSame(DuesNotice::dismissKey($expiring), DuesNotice::dismissKey($lapsed));
        $this->assertNotSame(DuesNotice::dismissKey($expiring), DuesNotice::dismissKey($renewed));
        $this->assertMatchesRegularExpression('/^[a-z]+:\d{4}-\d{2}-\d{2}$/', DuesNotice::dismissKey($lapsed), 'key survives the DuesAjax sanitiser');
    }
}
