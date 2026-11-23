<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Scrollery's one issuing rule, applied to courts and principalities.
 *
 * A court issues from its kingdom when it is a Kingdom Court (even one held at a
 * park), else from its park — so a host-park officer may run a Kingdom Court's
 * planner but not grant, finalize or print in the kingdom's name. And a
 * parent-kingdom officer, whose authority HasAuthority() lets flow down to a
 * principality, gets that principality as a Scrollery door.
 */
final class ScrolleryIssuingScopeTest extends TestCase
{
    private CourtFixture $fx;
    private Court $court;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->fx    = CourtFixture::create();
        $this->court = new Court();
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    public function test_the_issuing_scope_is_the_kingdom_for_a_kingdom_court(): void
    {
        $this->assertSame(['park', 7], Court::issuingScope(['KingdomId' => 3, 'ParkId' => 7, 'IssuedBy' => 'local']));
        $this->assertSame(['kingdom', 3], Court::issuingScope(['KingdomId' => 3, 'ParkId' => 7, 'IssuedBy' => 'kingdom']));
        $this->assertSame(['kingdom', 3], Court::issuingScope(['KingdomId' => 3, 'ParkId' => 0, 'IssuedBy' => 'local']));
        // A precomputed flag wins over the raw columns.
        $this->assertSame(['kingdom', 3], Court::issuingScope(['KingdomId' => 3, 'ParkId' => 7, 'IsKingdomCourt' => true]));
    }

    public function test_a_host_park_officer_cannot_issue_for_a_kingdom_court(): void
    {
        $k = $this->fx->firstKingdomId();
        $p = $this->fx->firstParkId($k);
        $kingdomMonarch = $this->fx->createPlayer('iss-kingdom', $k);
        $parkMonarch    = $this->fx->createPlayer('iss-park', $k, $p);
        $this->fx->insertOfficer($kingdomMonarch['mundane_id'], $k, 0, 'Monarch');
        $this->fx->insertOfficer($parkMonarch['mundane_id'], $k, $p, 'Monarch');

        $id = $this->fx->createCourt(['kingdom_id' => $k, 'park_id' => $p, 'status' => 'published']);
        $line = $this->fx->createAward($id, $kingdomMonarch['mundane_id']);

        $local = $this->court->getCourtDetail($id);
        $this->assertTrue($this->court->canIssueForCourt($parkMonarch['mundane_id'], $local));

        $this->assertTrue($this->court->updateCourt($id, ['IssuedBy' => 'kingdom']));
        $kingdomCourt = $this->court->getCourtDetail($id);

        // Still manages the planner, no longer issues from it.
        $this->assertTrue((bool)$this->court->canManage($parkMonarch['mundane_id'], $k, $p));
        $this->assertFalse($this->court->canIssueForCourt($parkMonarch['mundane_id'], $kingdomCourt));
        $this->assertTrue($this->court->canIssueForCourt($kingdomMonarch['mundane_id'], $kingdomCourt));

        // grant_award reads the line row, which must carry the court's issuer.
        $grantRow = $this->court->getCourtAwardForGrant($line);
        $this->assertTrue($grantRow['IsKingdomCourt']);
        $this->assertFalse($this->court->canIssueForCourt($parkMonarch['mundane_id'], $grantRow));
    }

    public function test_a_parent_kingdom_officer_gets_the_principality_door(): void
    {
        $st = $this->fx->pdo()->query(
            'SELECT kingdom_id, parent_kingdom_id FROM ' . DB_PREFIX . "kingdom
              WHERE parent_kingdom_id > 0 AND active = 'Active' LIMIT 1"
        );
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : false;
        if (!$row) {
            $this->markTestSkipped('No active principality in the test database.');
        }
        $child  = (int)$row['kingdom_id'];
        $parent = (int)$row['parent_kingdom_id'];

        $this->assertContains($child, array_column((new ScrollQuery())->ChildKingdomScopes([$parent]), 'Id'));

        $officer = $this->fx->createPlayer('iss-parent', $parent);
        $this->fx->insertScopedAuth($officer['mundane_id'], 0, $parent, 'edit');

        $doors = array_map(
            static fn (array $s): string => $s['Type'] . ':' . $s['Id'],
            (new Model_Scrollery())->issuable_scopes_for($officer['mundane_id'])
        );
        $this->assertContains('kingdom:' . $parent, $doors);
        $this->assertContains('kingdom:' . $child, $doors);
        // The parent's own door comes first.
        $this->assertLessThan(array_search('kingdom:' . $child, $doors, true), array_search('kingdom:' . $parent, $doors, true));
    }
}
