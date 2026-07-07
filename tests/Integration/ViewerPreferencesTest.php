<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller font prefs and home kingdom reads (T-INF-04, T-INF-05).
 */
final class ViewerPreferencesTest extends TestCase
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

    public function testGetViewerPreferences(): void
    {
        $parkId = $this->fixture->firstParkId();
        $player = $this->fixture->createPlayer($parkId, 'fonts');
        $this->fixture->setFontPreferences($player['mundane_id'], 1, 1);

        $prefs = $this->mirrorViewerFontPreferences($player['mundane_id']);
        $this->assertSame(1, $prefs['ViewerBasicFonts']);
        $this->assertSame(1, $prefs['ViewerDyslexiaFonts']);

        $playerDomain = new Player();
        $profile = $playerDomain->GetPlayer(['MundaneId' => $player['mundane_id']]);
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

        $home = $this->mirrorHomeKingdom($player['mundane_id']);
        $this->assertSame($kingdomId, $home['KingdomId']);
        $this->assertSame($parentKingdomId, $home['ParentKingdomId']);
    }

    /**
     * @return array{ViewerBasicFonts: int, ViewerDyslexiaFonts: int}
     */
    private function mirrorViewerFontPreferences(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT basic_fonts, dyslexia_fonts FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = '
            . $mundaneId . ' LIMIT 1'
        );
        if ($rs && $rs->Next()) {
            return [
                'ViewerBasicFonts' => (int) $rs->basic_fonts,
                'ViewerDyslexiaFonts' => (int) $rs->dyslexia_fonts,
            ];
        }

        return ['ViewerBasicFonts' => 0, 'ViewerDyslexiaFonts' => 0];
    }

    /**
     * @return array{KingdomId: int, ParentKingdomId: int}
     */
    private function mirrorHomeKingdom(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT p.kingdom_id, k.parent_kingdom_id FROM ' . DB_PREFIX . 'mundane m
             INNER JOIN ' . DB_PREFIX . 'park p ON p.park_id = m.park_id
             INNER JOIN ' . DB_PREFIX . 'kingdom k ON k.kingdom_id = p.kingdom_id
             WHERE m.mundane_id = ' . $mundaneId . ' LIMIT 1'
        );
        if ($rs && $rs->Size() > 0 && $rs->Next()) {
            return [
                'KingdomId' => (int) $rs->kingdom_id,
                'ParentKingdomId' => (int) $rs->parent_kingdom_id,
            ];
        }

        return ['KingdomId' => 0, 'ParentKingdomId' => 0];
    }
}
