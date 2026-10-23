<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Pins the arithmetic behind the weekly recap's "ORK Data" digest
 * (Report::RecapPlatformBreakdown) and the worldwide sentence's host pick
 * (Report::RecapTopOtherHost). Both are pure: no DB, no Cloudflare.
 *
 * The contract the template relies on:
 *   - servers + cache + stopped == total, always (stopped is a remainder);
 *   - malicious + cfPages + other == stopped, always (other is a remainder);
 *   - a v1 block (no OriginRequests) yields null so the old sentence renders.
 */
final class ReportRecapPlatformBreakdownTest extends TestCase
{
    /** The week of 2026-10-12, ORK scope, as Cloudflare reported it. */
    private function orkWeek(): array
    {
        return [
            'Requests'            => 1486332,
            'CacheHits'           => 387691,
            'OriginRequests'      => 649227,
            'BlockedOrChallenged' => 110771,
            'CfPageRequests'      => 256049,
            'StatsVersion'        => 2,
        ];
    }

    public function testBucketsSumToTotalForTheOrkScope(): void
    {
        $b = Report::RecapPlatformBreakdown($this->orkWeek(), 'ork');
        $this->assertNotNull($b);
        $this->assertSame(1486332, $b['total']);
        $this->assertSame(649227, $b['servers']);
        $this->assertSame(387691, $b['cache']);
        $this->assertSame(449414, $b['stopped']);
        $this->assertSame($b['total'], $b['servers'] + $b['cache'] + $b['stopped']);
        $this->assertSame(110771, $b['malicious']);
        $this->assertSame(256049, $b['cfPages']);
        $this->assertSame(82594, $b['other']);
        $this->assertSame($b['stopped'], $b['malicious'] + $b['cfPages'] + $b['other']);
        $this->assertSame([44, 26, 30], [$b['serversPct'], $b['cachePct'], $b['stoppedPct']]);
    }

    public function testGlobalScopeReadsTheGlobalKeys(): void
    {
        $ps = [
            'Requests'             => 1,          // ORK keys must be ignored here
            'OriginRequests'       => 1,
            'RequestsGlobal'       => 7762330,
            'CacheHitsGlobal'      => 848591,
            'OriginRequestsGlobal' => 1469141,
            'BlockedGlobal'        => 2059285,
            'CfPageRequestsGlobal' => 3068676,
        ];
        $b = Report::RecapPlatformBreakdown($ps, 'global');
        $this->assertSame(7762330, $b['total']);
        $this->assertSame(1469141, $b['servers']);
        $this->assertSame(848591, $b['cache']);
        $this->assertSame(5444598, $b['stopped']);
        $this->assertSame(2059285, $b['malicious']);
        $this->assertSame(3068676, $b['cfPages']);
        $this->assertSame(316637, $b['other']);
        $this->assertSame([19, 11, 70], [$b['serversPct'], $b['cachePct'], $b['stoppedPct']]);
    }

    public function testV1BlockWithoutOriginRequestsYieldsNull(): void
    {
        $v1 = ['Requests' => 1352024, 'CacheHits' => 385300, 'BlockedOrChallenged' => 113242];
        $this->assertNull(Report::RecapPlatformBreakdown($v1, 'ork'));
        $this->assertNull(Report::RecapPlatformBreakdown($v1 + ['RequestsGlobal' => 7974761], 'global'));
    }

    public function testFirewallCountLargerThanStoppedIsClampedSoNothingGoesNegative(): void
    {
        // The firewall log and the request log do not reconcile perfectly; the
        // ORK's US+CA malicious count can exceed what the request totals leave
        // as "stopped". The digest must still add up.
        $ps = $this->orkWeek();
        $ps['BlockedOrChallenged'] = 600000; // > stopped (449,414)
        $ps['CfPageRequests']      = 300000;
        $b = Report::RecapPlatformBreakdown($ps, 'ork');
        $this->assertSame(449414, $b['malicious']);
        $this->assertSame(0, $b['cfPages']);
        $this->assertSame(0, $b['other']);
        $this->assertSame($b['stopped'], $b['malicious'] + $b['cfPages'] + $b['other']);
    }

    public function testMissingOptionalKeysFoldIntoOther(): void
    {
        $ps = ['Requests' => 1000, 'CacheHits' => 200, 'OriginRequests' => 500];
        $b = Report::RecapPlatformBreakdown($ps, 'ork');
        $this->assertSame(300, $b['stopped']);
        $this->assertSame(0, $b['malicious']);
        $this->assertSame(0, $b['cfPages']);
        $this->assertSame(300, $b['other']);
    }

    public function testTopOtherHostPicksTheLargestStoppedShareAndSkipsTheOrk(): void
    {
        $totals  = ['wiki.amtgard.com' => 6178161, 'ork.amtgard.com' => 1486332, 'staging.amtgard.com' => 39669];
        $hits    = ['wiki.amtgard.com' => 460331,  'ork.amtgard.com' => 387691,  'staging.amtgard.com' => 272];
        $origins = ['wiki.amtgard.com' => 815152,  'ork.amtgard.com' => 649227,  'staging.amtgard.com' => 196];
        $top = Report::RecapTopOtherHost($totals, $hits, $origins);
        $this->assertSame('wiki.amtgard.com', $top['Host']);
        $this->assertSame(6178161, $top['Requests']);
        $this->assertSame(4902678, $top['Stopped']);
    }

    public function testTopOtherHostIsNullWhenOnlyTheOrkAppears(): void
    {
        $this->assertNull(Report::RecapTopOtherHost(['ork.amtgard.com' => 10], [], []));
        $this->assertNull(Report::RecapTopOtherHost([], [], []));
    }

    public function testTopOtherHostRanksByStoppedNotByTotal(): void
    {
        // play has more total requests but nearly all served; go is mostly stopped.
        $totals  = ['play.amtgard.com' => 1000, 'go.amtgard.com' => 600];
        $hits    = ['play.amtgard.com' => 500];
        $origins = ['play.amtgard.com' => 450, 'go.amtgard.com' => 50];
        $top = Report::RecapTopOtherHost($totals, $hits, $origins);
        $this->assertSame('go.amtgard.com', $top['Host']);
        $this->assertSame(550, $top['Stopped']);
    }
}
