<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Gate-vs-surface contracts for the pages opened to the public on 2026-08-23
 * (Live dashboard, Weather) — the C-22 lesson pinned as tests: a public page's
 * backing getters must serve tokenless callers, while the fetch-capable
 * weather endpoints (which can reach Open-Meteo) stay token-gated.
 */
final class PublicServiceAuthTest extends TestCase
{
    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
    }

    public function testLiveFeedsArePublic(): void
    {
        $svc = new LiveService();

        $stats = $svc->GetStats(null);
        $this->assertArrayHasKey('parks', $stats);
        $this->assertArrayNotHasKey('Error', $stats);

        $recent = $svc->GetRecent(null);
        $this->assertArrayHasKey('signins', $recent);
        $this->assertArrayNotHasKey('Error', $recent);
    }

    public function testWeatherPageReadsArePublic(): void
    {
        $svc = new WeatherService();
        $today = date('Y-m-d');

        $rundown = $svc->GetDailySummary(null, $today);
        $this->assertArrayHasKey('date', $rundown);

        $play = $svc->GetPlayForDate(null, $today);
        $this->assertIsArray($play);
        $this->assertArrayNotHasKey('Error', $play);

        $this->assertIsArray($svc->GetUpcomingEventsWithForecast(null, 7));
        $this->assertIsString($svc->GetFreshnessPhrase(null));
        $this->assertIsArray($svc->GetStripSeverities(null, array($today)));
    }

    public function testFetchCapableWeatherEndpointsStayGated(): void
    {
        $svc = new WeatherService();
        $today = date('Y-m-d');

        // Tokenless callers must be refused — these two can trigger upstream
        // Open-Meteo fetches (forecast via cache-refresh path, archive on
        // cache miss), so anonymous access would let crawlers spend API quota.
        $forecast = $svc->GetForecastForPark(null, 1, $today);
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $forecast['Status'] ?? null);

        $archive = $svc->GetArchiveForPark(null, 1, '2020-01-01');
        $this->assertSame(ServiceErrorIds::SecureTokenFailure, $archive['Status'] ?? null);
    }
}
