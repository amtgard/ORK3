<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller font prefs and home kingdom reads (T-INF-04, T-INF-05).
 */
final class ViewerPreferencesTest extends TestCase
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

    public function testGetViewerPreferences(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'fonts');
        $this->fixture->setFontPreferences($player['mundane_id'], 1, 1);

        $prefs = $this->playerDomain->GetViewerPreferences($player['mundane_id']);
        $this->assertSame(1, $prefs['BasicFonts']);
        $this->assertSame(1, $prefs['DyslexiaFonts']);

        $profile = $this->playerDomain->GetPlayer(['MundaneId' => $player['mundane_id']]);
        $this->assertSame(0, $profile['Status']['Status']);
        $this->assertSame(1, (int) ($profile['Player']['BasicFonts'] ?? 0));
        $this->assertSame(1, (int) ($profile['Player']['DyslexiaFonts'] ?? 0));
    }

    public function testGetHomeKingdom(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'home-k');
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $parentKingdomId = $this->fixture->parentKingdomId($kingdomId);

        $home = $this->playerDomain->GetHomeKingdom($player['mundane_id']);
        $this->assertSame($kingdomId, $home['KingdomId']);
        $this->assertSame($parentKingdomId, $home['ParentKingdomId']);
    }
}
