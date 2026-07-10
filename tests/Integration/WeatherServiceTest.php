<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_Weather and attendance weather paths (T-LIB-02, DS-12 overlap; T-LIB-11 admin refresh).
 */
final class WeatherServiceTest extends TestCase
{
    private Weather $weather;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->weather = new Weather();
    }

    public function testDailySummaryForToday(): void
    {
        $summary = $this->weather->daily_summary(date('Y-m-d'));
        $this->assertIsArray($summary);
    }

    public function testPlayForDateInvalid(): void
    {
        $normalized = $this->mirrorWeatherPlayDate('not-a-date');
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $normalized);
        $play = $this->weather->play_for_date($normalized);
        $this->assertIsArray($play);
    }

    public function testArchiveForPark(): void
    {
        $parkId = (int) (new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
        ))->query('SELECT park_id FROM ' . DB_PREFIX . 'park ORDER BY park_id ASC LIMIT 1')->fetchColumn();

        $archive = $this->weather->archive_for_date($parkId, '2020-01-15');
        $this->assertIsArray($archive);
    }

    public function testApiStatsShape(): void
    {
        $stats = $this->weather->api_stats(3);

        $this->assertArrayHasKey('days', $stats);
        $this->assertCount(3, $stats['days']);
        $this->assertArrayHasKey('cooldown_present', $stats);
        $this->assertArrayHasKey('error_streak', $stats);
        foreach ($stats['days'] as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('attempt', $day);
            $this->assertArrayHasKey('success', $day);
        }
    }

    public function testAdminRefreshWithPriorShape(): void
    {
        $refresh = $this->weather->AdminRefreshWithPrior();

        $this->assertArrayHasKey('count', $refresh);
        $this->assertArrayHasKey('elapsed_ms', $refresh);
        $this->assertArrayHasKey('previous_fetched_at', $refresh);
        $this->assertArrayHasKey('previous_age_min', $refresh);
        $this->assertGreaterThanOrEqual(0, $refresh['count']);
        $this->assertGreaterThanOrEqual(0, $refresh['elapsed_ms']);
    }

    private function mirrorWeatherPlayDate(string $date): string
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return date('Y-m-d');
        }

        return $date;
    }
}
