<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_KingdomAjax actions (T-KNA-01, T-KNA-02, T-KNA-04, T-KNA-05, T-KNA-07; T-LIB-08 kingdom lib reads).
 */
final class KingdomAjaxTest extends TestCase
{
    private KingdomProfileFixture $fixture;

    private KingdomProfile $profileDomain;

    private Kingdom $kingdomDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = KingdomProfileFixture::create();
        $this->profileDomain = new KingdomProfile();
        $this->kingdomDomain = new Kingdom();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    /**
     * Moving a player needs AUTH_CREATE, not AUTH_EDIT.
     *
     * This asserted AUTH_EDIT was sufficient, which is what the code did for years
     * while the permission help said otherwise. 135bc7e1 (2026-10-04) corrected the
     * code to match the published contract -- Player::MovePlayer is the real gate
     * and checks AUTH_CREATE, and KingdomProfile::AuthorizeMovePlayer is only a
     * friendlier pre-check, so it must never be looser. The test was left behind
     * and has failed ever since.
     *
     * The EDIT case below is the point of the change, so it is asserted rather than
     * merely no longer assumed: an editor must NOT be able to move a player.
     */
    public function testMovePlayerRequiresCreateNotEdit(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('move-src', $kid);

        unset($_SESSION['is_authorized_mundane_id']);

        $creator = $this->fixture->createPlayer('move-creator', $kid);
        $this->fixture->insertAuthorization($creator['mundane_id'], $kid, AUTH_CREATE);
        $this->assertTrue(
            $this->profileDomain->AuthorizeMovePlayer($creator['mundane_id'], $player['kingdom_id'], $kid),
            'kingdom CREATE should be allowed to move a player'
        );

        $editor = $this->fixture->createPlayer('move-editor', $kid);
        $this->fixture->insertAuthorization($editor['mundane_id'], $kid, AUTH_EDIT);
        $this->assertFalse(
            $this->profileDomain->AuthorizeMovePlayer($editor['mundane_id'], $player['kingdom_id'], $kid),
            'kingdom EDIT alone must NOT be allowed to move a player (135bc7e1)'
        );

        $stranger = $this->fixture->createPlayer('move-stranger', $kid);
        $this->assertFalse(
            $this->profileDomain->AuthorizeMovePlayer($stranger['mundane_id'], $player['kingdom_id'], $kid)
        );
    }

    public function testSetAwardRecsPublicConfig(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->setAwardRecsPublic($kid, true);
        $this->assertSame('"1"', $this->fixture->fetchAwardRecsPublic($kid));

        $this->profileDomain->SetAwardRecsPublic($kid, false);
        $this->assertSame('"0"', $this->fixture->fetchAwardRecsPublic($kid));
    }

    public function testCheckKingdomAbbreviationUnique(): void
    {
        global $DB;
        $DB->Clear();
        $row = $DB->DataSet(
            'SELECT kingdom_id, abbreviation, name FROM ' . DB_PREFIX . 'kingdom ORDER BY kingdom_id ASC LIMIT 1'
        );
        $this->assertTrue($row && $row->Next());
        $kid = (int) $row->kingdom_id;
        $abbr = (string) $row->abbreviation;

        $taken = $this->profileDomain->CheckKingdomAbbreviationTaken($abbr, 0);
        $this->assertTrue($taken);

        $available = $this->profileDomain->CheckKingdomAbbreviationTaken($abbr, $kid);
        $this->assertFalse($available);
    }

    public function testCalendarFeedDraftAndRoyalFlags(): void
    {
        $ctx = $this->fixture->createPublishedEvent('cal-feed');
        $events = $this->profileDomain->GetKingdomCalendarFeed(
            $ctx['kingdom_id'],
            date('Y-m-01'),
            date('Y-m-t'),
            0,
            false
        );
        $this->assertIsArray($events);
        $royalPresence = false;
        foreach ($events as $event) {
            if (!empty($event['extendedProps']['royalPresence'])) {
                $royalPresence = true;
                break;
            }
        }
        $this->assertTrue($royalPresence || $this->profileDomain->HasRoyalOfficers($ctx['kingdom_id']));
    }

    public function testSuspendPlayerReadsState(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('suspend-target', $kid);
        $editor = $this->fixture->createPlayer('suspend-editor', $kid);
        $this->fixture->insertAuthorization($editor['mundane_id'], $kid, AUTH_EDIT);

        unset($_SESSION['is_authorized_mundane_id']);
        $context = $this->profileDomain->GetPlayerSuspensionContext($player['mundane_id']);
        $this->assertSame($kid, $context['kingdom_id']);
        $this->assertFalse($context['suspended']);

        $authorized = $editor['mundane_id'] > 0
            && (Ork3::$Lib->authorization->HasAuthority($editor['mundane_id'], AUTH_ADMIN, 0, AUTH_ADMIN)
                || Ork3::$Lib->authorization->HasAuthority($editor['mundane_id'], AUTH_KINGDOM, $context['kingdom_id'], AUTH_EDIT));
        $this->assertTrue($authorized);
    }

    public function testKingdomLibReadsForAjaxPaths(): void
    {
        $kid = $this->fixture->firstKingdomId();

        $parks = $this->kingdomDomain->GetParks(['KingdomId' => $kid]);
        $this->assertSame(0, $parks['Status']['Status']);
        $this->assertNotEmpty($parks['Parks']);

        $familyIds = $this->kingdomDomain->GetFamilyKingdomIds($kid);
        $this->assertContains($kid, $familyIds);

        $titles = $this->kingdomDomain->GetKingdomParkTitles(['KingdomId' => $kid]);
        $this->assertSame(0, $titles['Status']['Status']);
        $this->assertArrayHasKey('ParkTitles', $titles);

        $kingdoms = $this->kingdomDomain->GetKingdoms([]);
        $this->assertSame(0, $kingdoms['Status']['Status']);
        $this->assertArrayHasKey($kid, $kingdoms['Kingdoms']);
    }
}
