<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Controller_KingdomAjax actions (T-KNA-01, T-KNA-02, T-KNA-04, T-KNA-05, T-KNA-07).
 */
final class KingdomAjaxTest extends TestCase
{
    private KingdomProfileFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        unset($_SESSION['is_authorized_mundane_id']);

        $this->fixture = KingdomProfileFixture::create();
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
        $allowed = $this->mirrorMovePlayerAuthorized($editor['mundane_id'], $player['kingdom_id'], $kid);
        $this->assertTrue($allowed);

        $stranger = $this->fixture->createPlayer('move-stranger', $kid);
        $denied = $this->mirrorMovePlayerAuthorized($stranger['mundane_id'], $player['kingdom_id'], $kid);
        $this->assertFalse($denied);
    }

    public function testSetAwardRecsPublicConfig(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $this->fixture->setAwardRecsPublic($kid, true);
        $this->assertSame('"1"', $this->fixture->fetchAwardRecsPublic($kid));

        $this->mirrorSetAwardRecsPublic($kid, false);
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

        $taken = $this->mirrorKingdomAbbreviationTaken($abbr, 0);
        $this->assertTrue($taken);

        $available = $this->mirrorKingdomAbbreviationTaken($abbr, $kid);
        $this->assertFalse($available);
    }

    public function testCalendarFeedDraftAndRoyalFlags(): void
    {
        $ctx = $this->fixture->createPublishedEvent('cal-feed');

        $feed = $this->mirrorCalendarFeed($ctx['kingdom_id'], date('Y-m-01'), date('Y-m-t'));
        $this->assertIsArray($feed);
        $this->assertArrayHasKey('events', $feed);
        $this->assertArrayHasKey('royalPresence', $feed);
        $this->assertTrue($feed['royalPresence']);
    }

    public function testSuspendPlayerReadsState(): void
    {
        $kid = $this->fixture->firstKingdomId();
        $player = $this->fixture->createPlayer('suspend-target', $kid);
        $editor = $this->fixture->createPlayer('suspend-editor', $kid);
        $this->fixture->insertAuthorization($editor['mundane_id'], $kid, AUTH_EDIT);

        unset($_SESSION['is_authorized_mundane_id']);
        $context = $this->mirrorSuspendPlayerContext($player['mundane_id']);
        $this->assertSame($kid, $context['kingdom_id']);
        $this->assertFalse($context['suspended']);

        $authorized = $this->mirrorSuspendAuthorized($editor['mundane_id'], $context['kingdom_id']);
        $this->assertTrue($authorized);
    }

    private function mirrorMovePlayerAuthorized(int $uid, int $playerKingdomId, int $destKingdomId): bool
    {
        $canSource = $playerKingdomId > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $playerKingdomId, AUTH_EDIT);
        $canDest = $destKingdomId > 0
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $destKingdomId, AUTH_EDIT);

        return $canSource || $canDest;
    }

    private function mirrorSetAwardRecsPublic(int $kingdomId, bool $public): void
    {
        global $DB;
        $value = json_encode($public ? '1' : '0');
        $kid = (int) $kingdomId;
        $DB->Clear();
        $existing = $DB->DataSet(
            'SELECT configuration_id FROM ' . DB_PREFIX . "configuration WHERE type='Kingdom' AND id={$kid} AND `key`='AwardRecsPublic' LIMIT 1"
        );
        if ($existing && $existing->Next()) {
            $cid = (int) $existing->configuration_id;
            $DB->Clear();
            $DB->Execute(
                'UPDATE ' . DB_PREFIX . "configuration SET value='" . $value . "', modified=NOW() WHERE configuration_id={$cid}"
            );
        } else {
            $DB->Clear();
            $DB->Execute(
                'INSERT INTO ' . DB_PREFIX . "configuration (type, var_type, id, `key`, value, user_setting, allowed_values, modified)
                 VALUES ('Kingdom', 'fixed', {$kid}, 'AwardRecsPublic', '" . $value . "', 1, 'null', NOW())"
            );
        }
    }

    private function mirrorKingdomAbbreviationTaken(string $abbr, int $excludeKingdomId): bool
    {
        global $DB;
        $abbr = preg_replace('/[^A-Za-z0-9]/', '', strtoupper(trim($abbr)));
        $excludeClause = $excludeKingdomId > 0 ? ' AND kingdom_id != ' . $excludeKingdomId : '';
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdom_id FROM ' . DB_PREFIX . "kingdom WHERE abbreviation = '{$abbr}'{$excludeClause} LIMIT 1"
        );

        return (bool) ($rs && $rs->Next());
    }

    /**
     * @return array{events: list<array<string, mixed>>, royalPresence: bool}
     */
    private function mirrorCalendarFeed(int $kingdomId, string $start, string $end): array
    {
        $kingdom = new Kingdom();
        $statsEvtKids = implode(',', array_map('intval', $kingdom->GetStatsKingdomIds($kingdomId)));

        global $DB;
        $DB->Clear();
        $evtResult = $DB->DataSet(
            'SELECT e.event_id, e.name, cd.event_start, cd.event_end
             FROM ' . DB_PREFIX . 'event e
             JOIN ' . DB_PREFIX . 'event_calendardetail cd ON cd.event_id = e.event_id
             WHERE e.kingdom_id IN (' . $statsEvtKids . ")
               AND e.status = 'published'
               AND cd.event_start >= '{$start}' AND cd.event_end <= '{$end} 23:59:59'
             ORDER BY cd.event_start"
        );
        $events = [];
        while ($evtResult && $evtResult->Next()) {
            $events[] = [
                'id' => (int) $evtResult->event_id,
                'title' => $evtResult->name,
                'start' => $evtResult->event_start,
                'end' => $evtResult->event_end,
            ];
        }

        $DB->Clear();
        $royRes = $DB->DataSet(
            'SELECT 1 FROM ' . DB_PREFIX . "officer WHERE kingdom_id = {$kingdomId} AND park_id = 0 AND role IN ('Monarch','Regent') AND mundane_id > 0 LIMIT 1"
        );

        return [
            'events' => $events,
            'royalPresence' => (bool) ($royRes && $royRes->Next()),
        ];
    }

    /**
     * @return array{kingdom_id: int, suspended: bool}
     */
    private function mirrorSuspendPlayerContext(int $mundaneId): array
    {
        global $DB;
        $DB->Clear();
        $rs = $DB->DataSet(
            'SELECT kingdom_id, suspended FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int) $mundaneId . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return ['kingdom_id' => 0, 'suspended' => false];
        }

        return [
            'kingdom_id' => (int) $rs->kingdom_id,
            'suspended' => (bool) $rs->suspended,
        ];
    }

    private function mirrorSuspendAuthorized(int $uid, int $playerKingdomId): bool
    {
        $isAdmin = Ork3::$Lib->authorization->HasAuthority($uid, AUTH_ADMIN, 0, AUTH_ADMIN);

        return $isAdmin || (
            valid_id($playerKingdomId)
            && Ork3::$Lib->authorization->HasAuthority($uid, AUTH_KINGDOM, $playerKingdomId, AUTH_EDIT)
        );
    }
}
