<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Guard behavior of Report::GaActiveUsers (GA4 human-visitor counts).
 *
 * The happy path needs Google's OAuth + Data API and a real service-account
 * key, so it is exercised by bin/ga-probe.php against production credentials,
 * not here. These tests pin the null-safety contract the weekly recap relies
 * on: any missing or broken configuration must yield null, never a warning,
 * an exception, or a network call.
 */
final class ReportGaActiveUsersTest extends TestCase
{
    /** @var array<string, string|false> */
    private $savedEnv = [];

    protected function setUp(): void
    {
        // The method falls back to env vars when the constants are undefined
        // (they are undefined under ENVIRONMENT=TEST), so pin a clean slate.
        foreach (['GA4_PROPERTY_ID', 'GA4_SA_KEY_PATH'] as $name) {
            $this->savedEnv[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->savedEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
    }

    public function testReturnsNullWithoutAnyConfiguration(): void
    {
        $this->assertNull(Ork3::$Lib->report->GaActiveUsers('2026-08-01', '2026-08-07'));
    }

    public function testReturnsNullWhenKeyPathIsMissing(): void
    {
        putenv('GA4_PROPERTY_ID=495241107');
        $this->assertNull(Ork3::$Lib->report->GaActiveUsers('2026-08-01', '2026-08-07'));
    }

    public function testReturnsNullWhenKeyFileDoesNotExist(): void
    {
        putenv('GA4_PROPERTY_ID=495241107');
        putenv('GA4_SA_KEY_PATH=/nonexistent/ga-key.json');
        $this->assertNull(Ork3::$Lib->report->GaActiveUsers('2026-08-01', '2026-08-07'));
    }

    public function testReturnsNullWhenKeyFileIsNotJson(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ga-key-');
        file_put_contents($tmp, 'this is not json');
        putenv('GA4_PROPERTY_ID=495241107');
        putenv('GA4_SA_KEY_PATH=' . $tmp);
        try {
            $this->assertNull(Ork3::$Lib->report->GaActiveUsers('2026-08-01', '2026-08-07'));
        } finally {
            unlink($tmp);
        }
    }

    public function testReturnsNullWhenKeyFileLacksCredentialFields(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'ga-key-');
        file_put_contents($tmp, json_encode(['type' => 'service_account']));
        putenv('GA4_PROPERTY_ID=495241107');
        putenv('GA4_SA_KEY_PATH=' . $tmp);
        try {
            $this->assertNull(Ork3::$Lib->report->GaActiveUsers('2026-08-01', '2026-08-07'));
        } finally {
            unlink($tmp);
        }
    }
}
