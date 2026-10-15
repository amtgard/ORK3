<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * Monarchy Only recommendations — create, hide/unhide, second and peer-check
 * write paths in Player.
 */
final class RecommendationMonarchyOnlyWritesTest extends TestCase
{
    private CourtFixture $fx;
    private int $k = 0;
    private int $p = 0;
    private int $customKa = 0;
    /** @var list<int> */
    private array $recipients = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $st = $this->fx->pdo()->prepare(
            'SELECT ka.kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward ka JOIN ' . DB_PREFIX . 'award a ON a.award_id = ka.award_id
              WHERE ka.kingdom_id = ? AND a.is_ladder = 0 AND a.is_title = 0 LIMIT 1'
        );
        $st->execute([$this->k]);
        $this->customKa = (int)$st->fetchColumn();
        if (!$this->customKa) {
            $this->markTestSkipped('Sandbox kingdom has no custom (non-ladder, non-title) award.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            foreach ($this->recipients as $rid) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendations WHERE mundane_id = ' . (int)$rid);
            }
            $this->recipients = [];
            $this->fx->cleanup();
        }
        unset($_SESSION['is_authorized_mundane_id']);
    }

    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('recip', $this->k, $this->p);
        $this->recipients[] = $recipient['mundane_id'];
        $author    = $this->fx->createPlayerWithToken('author', $this->k, $this->p);
        $parkOff   = $this->fx->createPlayerWithToken('parkoff', $this->k, $this->p);
        $this->fx->insertScopedAuth($parkOff['mundane_id'], $this->p, 0, 'edit');
        $kOff      = $this->fx->createPlayerWithToken('koff', $this->k, $this->p);
        $this->fx->insertScopedAuth($kOff['mundane_id'], 0, $this->k, 'edit');
        $plain     = $this->fx->createPlayerWithToken('plain', $this->k, $this->p);

        return ['recipient' => $recipient, 'author' => $author, 'parkOfficer' => $parkOff,
            'kingdomOfficer' => $kOff, 'plain' => $plain];
    }

    private function req(string $token, int $recipient, array $extra = []): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return ['Token' => $token, 'MundaneId' => $recipient, 'KingdomAwardId' => $this->customKa, 'Rank' => '', 'Reason' => 'mo test'] + $extra;
    }

    private function latestLevel(int $recipient): int
    {
        $v = $this->fx->pdo()->query(
            'SELECT monarchy_only FROM ' . DB_PREFIX . 'recommendations WHERE mundane_id = ' . $recipient . ' ORDER BY recommendations_id DESC LIMIT 1'
        )->fetchColumn();

        return $v === false ? -1 : (int)$v;
    }

    private function level(int $id): int
    {
        return (int)$this->fx->pdo()->query('SELECT monarchy_only FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $id)->fetchColumn();
    }

    private function publicRec(array $c): int
    {
        $ka = $this->fx->firstKingdomAwardId($this->k);

        return $this->fx->createRecommendation(
            $c['recipient']['mundane_id'],
            (int)$ka['kingdomaward_id'],
            (int)$ka['award_id'],
            ['monarchy_only' => 0, 'recommended_by_id' => $c['author']['mundane_id'], 'rank' => 1]
        );
    }

    public function testKingdomOfficerCreatesKingdomLevel(): void
    {
        $c = $this->cast();
        $r = \Ork3::$Lib->player->AddAwardRecommendation($this->req($c['kingdomOfficer']['token'], $c['recipient']['mundane_id'], ['MonarchyOnly' => 1]));
        $this->assertSame(0, $r['Status']);
        $this->assertSame(2, $this->latestLevel($c['recipient']['mundane_id']));
    }

    public function testParkOfficerCreatesParkLevel(): void
    {
        $c = $this->cast();
        $r = \Ork3::$Lib->player->AddAwardRecommendation($this->req($c['parkOfficer']['token'], $c['recipient']['mundane_id'], ['MonarchyOnly' => 1]));
        $this->assertSame(0, $r['Status']);
        $this->assertSame(1, $this->latestLevel($c['recipient']['mundane_id']));
    }

    public function testNonOfficerIsRejectedAndNothingSaved(): void
    {
        $c = $this->cast();
        $r = \Ork3::$Lib->player->AddAwardRecommendation($this->req($c['plain']['token'], $c['recipient']['mundane_id'], ['MonarchyOnly' => 1]));
        $this->assertNotSame(0, $r['Status']);
        $this->assertStringContainsString("Only officers of this player's park or kingdom", (string)($r['Detail'] ?? '') . (string)($r['Error'] ?? ''));
        $this->assertSame(-1, $this->latestLevel($c['recipient']['mundane_id']));
    }

    public function testUncheckedStaysPublic(): void
    {
        $c = $this->cast();
        \Ork3::$Lib->player->AddAwardRecommendation($this->req($c['kingdomOfficer']['token'], $c['recipient']['mundane_id']));
        $this->assertSame(0, $this->latestLevel($c['recipient']['mundane_id']));
    }

    public function testHideUnhideAuthority(): void
    {
        $c  = $this->cast();
        $id = $this->publicRec($c);
        $set = function ($tok, $hidden) use ($id) {
            unset($_SESSION['is_authorized_mundane_id']);

            return \Ork3::$Lib->player->SetRecommendationMonarchyOnly(['Token' => $tok, 'RecommendationsId' => $id, 'Hidden' => $hidden]);
        };

        $this->assertNotSame(0, $set($c['plain']['token'], 1)['Status']);
        $this->assertSame(0, $set($c['parkOfficer']['token'], 1)['Status']);
        $this->assertSame(1, $this->level($id));
        $this->assertSame(0, $set($c['kingdomOfficer']['token'], 1)['Status']);
        $this->assertSame(2, $this->level($id));
        // A park officer re-hiding must never DOWNGRADE a kingdom hide.
        $this->fx->setPassedToLocal($id, true);
        $this->assertSame(0, $set($c['parkOfficer']['token'], 1)['Status']);
        $this->assertSame(2, $this->level($id));
        // Still passed down: the park officer can SEE it (effective park-level) but
        // the kingdom's hide is not theirs to lift.
        $this->assertNotSame(0, $set($c['parkOfficer']['token'], 0)['Status']);
        $this->assertSame(2, $this->level($id));
        $this->fx->setPassedToLocal($id, false);
        // Park officer cannot unhide a kingdom-level hide; kingdom officer can.
        $this->assertNotSame(0, $set($c['parkOfficer']['token'], 0)['Status']);
        $this->assertSame(0, $set($c['kingdomOfficer']['token'], 0)['Status']);
        $this->assertSame(0, $this->level($id));
    }

    /** Unhiding a PUBLIC rec must not answer differently from a hidden one for a non-officer. */
    public function testUnhideIsNotAHiddennessOracle(): void
    {
        $c   = $this->cast();
        $pub = $this->publicRec($c);
        $hid = $this->publicRec($c);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET monarchy_only = 2 WHERE recommendations_id = $hid");
        $unhide = function ($tok, $id) {
            unset($_SESSION['is_authorized_mundane_id']);

            return \Ork3::$Lib->player->SetRecommendationMonarchyOnly(['Token' => $tok, 'RecommendationsId' => $id, 'Hidden' => 0]);
        };
        foreach (['plain', 'author'] as $who) {
            $a = $unhide($c[$who]['token'], $pub);
            $b = $unhide($c[$who]['token'], $hid);
            $this->assertNotSame(0, $a['Status'], "$who unhide of a public rec must be refused");
            $this->assertSame($b, $a, "$who sees different answers for public vs hidden");
        }
        $this->assertSame(0, $unhide($c['parkOfficer']['token'], $pub)['Status']);
        $this->assertSame(0, $this->level($pub));
        $this->assertSame(2, $this->level($hid));
    }

    public function testCannotSecondAnOutOfRangeHiddenRec(): void
    {
        $c  = $this->cast();
        $id = $this->publicRec($c);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET monarchy_only = -1 WHERE recommendations_id = $id");
        unset($_SESSION['is_authorized_mundane_id']);
        $r = \Ork3::$Lib->player->AddSecondToRecommendation(['Token' => $c['plain']['token'], 'RecommendationsId' => $id, 'Notes' => 'x']);
        $this->assertNotSame(0, $r['Status']);
    }

    public function testCannotSecondAHiddenRecYouCannotSee(): void
    {
        $c  = $this->cast();
        $id = $this->publicRec($c);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET monarchy_only = 2 WHERE recommendations_id = $id");
        unset($_SESSION['is_authorized_mundane_id']);
        $r = \Ork3::$Lib->player->AddSecondToRecommendation(['Token' => $c['plain']['token'], 'RecommendationsId' => $id, 'Notes' => 'x']);
        $this->assertNotSame(0, $r['Status']);
    }

    public function testPeerCheckHidesHiddenRecsFromNonOfficers(): void
    {
        $c  = $this->cast();
        $ka = $this->fx->firstKingdomAwardId($this->k);
        $this->fx->createRecommendation(
            $c['recipient']['mundane_id'],
            (int)$ka['kingdomaward_id'],
            (int)$ka['award_id'],
            ['monarchy_only' => 2, 'recommended_by_id' => $c['author']['mundane_id'], 'rank' => 1]
        );
        $p = \Ork3::$Lib->player;
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertCount(0, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['plain']['mundane_id'], $c['plain']['token']));
        $this->assertCount(0, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['plain']['mundane_id']));
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertCount(1, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['kingdomOfficer']['mundane_id'], $c['kingdomOfficer']['token']));
    }
}
