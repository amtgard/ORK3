<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for What's New dismiss/read (T-WN-01).
 */
final class WhatsNewTest extends TestCase
{
    private InfrastructureFixture $fixture;

    private Player $playerDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->fixture = InfrastructureFixture::create();
        $this->playerDomain = new Player();
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

        $this->assertSame(0, $this->playerDomain->DismissWhatsNew($player['mundane_id'], $version)['Status']);
        $this->assertSame(0, $this->playerDomain->DismissWhatsNew($player['mundane_id'], $version)['Status']);

        $this->assertTrue($this->fixture->hasSeenWhatsNew($player['mundane_id'], $version));
    }

    public function testHasSeenWhatsNew(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'wn-seen');
        $version = 'T13_seen_version';

        $this->assertFalse($this->playerDomain->GetWhatsNewSeen($player['mundane_id'], $version));
        $this->playerDomain->DismissWhatsNew($player['mundane_id'], $version);
        $this->assertTrue($this->playerDomain->GetWhatsNewSeen($player['mundane_id'], $version));
    }
}
