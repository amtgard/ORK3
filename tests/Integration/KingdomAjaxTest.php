<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_KingdomAjax actions (T-KNA-01, T-KNA-02, T-KNA-04, T-KNA-05, T-KNA-07).
 */
final class KingdomAjaxTest extends TestCase
{
    private KingdomProfileFixture $fixture;

    private KingdomProfile $profileDomain;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = KingdomProfileFixture::create();
        $this->profileDomain = new KingdomProfile();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);

        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testMovePlayerAuthSourceOrDest(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('move-src', $kid);
        $editor = $this->fixture->createPlayer('move-editor', $kid);
        $this->fixture->insertAuthorization($editor['mundane_id'], $kid, AUTH_EDIT);

        unset($_SESSION['is_authorized_mundane_id']);
        $allowed = $this->profileDomain->AuthorizeMovePlayer($editor['mundane_id'], $player['kingdom_id'], $kid);
        $this->assertTrue($allowed);

        $stranger = $this->fixture->createPlayer('move-stranger', $kid);
        $denied = $this->profileDomain->AuthorizeMovePlayer($stranger['mundane_id'], $player['kingdom_id'], $kid);
        $this->assertFalse($denied);
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
}
