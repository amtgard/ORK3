<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for What's New dismiss/read (T-WN-01).
 */
final class WhatsNewTest extends TestCase
{
    private InfrastructureFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = InfrastructureFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testDismissWhatsNewIdempotent(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'wn');
        $version = 'T13_test_version';

        $this->mirrorDismissWhatsNew($player['mundane_id'], $version);
        $this->mirrorDismissWhatsNew($player['mundane_id'], $version);

        $this->assertTrue($this->fixture->hasSeenWhatsNew($player['mundane_id'], $version));
    }

    public function testHasSeenWhatsNew(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'wn-seen');
        $version = 'T13_seen_version';

        $this->assertFalse($this->mirrorHasSeenWhatsNew($player['mundane_id'], $version));
        $this->mirrorDismissWhatsNew($player['mundane_id'], $version);
        $this->assertTrue($this->mirrorHasSeenWhatsNew($player['mundane_id'], $version));
    }

    private function mirrorDismissWhatsNew(int $mundaneId, string $version): void
    {
        global $DB;
        $version = preg_replace('/[^a-zA-Z0-9_\-]/', '', $version);
        $DB->Clear();
        $DB->Execute(
            'INSERT IGNORE INTO ' . DB_PREFIX . "whats_new_seen (mundane_id, version) VALUES ({$mundaneId}, '{$version}')"
        );
    }

    private function mirrorHasSeenWhatsNew(int $mundaneId, string $version): bool
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . "whats_new_seen WHERE mundane_id = {$mundaneId} AND version = '"
            . addslashes($version) . "' LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }
}
