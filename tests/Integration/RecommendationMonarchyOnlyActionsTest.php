<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

/**
 * Review 2, item 2: every Player write that acts on a recommendation (or one of its
 * seconds) BY ID must treat a Monarchy Only rec the token's viewer cannot see exactly
 * like a rec that does not exist — before any other check — so the recipient cannot
 * dismiss (or probe for) hidden recs about themselves, and a park officer cannot act
 * on a kingdom-level hide in their park.
 */
final class RecommendationMonarchyOnlyActionsTest extends TestCase
{
    private CourtFixture $fx;
    private int $k = 0;
    private int $p = 0;
    /** @var array{kingdomaward_id:int, award_id:int}|array{} */
    private array $ka = [];
    private int $missing = 0;
    private int $missingSecond = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $this->k  = $this->fx->firstKingdomId();
        $this->p  = $this->fx->firstParkId($this->k);
        $ka       = $this->fx->firstKingdomAwardId($this->k);
        if (!$ka || $this->p <= 0) {
            $this->markTestSkipped('No park / kingdomaward in the sandbox kingdom.');
        }
        $this->ka = ['kingdomaward_id' => (int) $ka['kingdomaward_id'], 'award_id' => (int) $ka['award_id']];
        $pdo = $this->fx->pdo();
        $this->missing       = (int) $pdo->query('SELECT MAX(recommendations_id) FROM ' . DB_PREFIX . 'recommendations')->fetchColumn() + 100000;
        $this->missingSecond = (int) $pdo->query('SELECT IFNULL(MAX(recommendation_seconds_id),0) FROM ' . DB_PREFIX . 'recommendation_seconds')->fetchColumn() + 100000;
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (isset($this->fx)) {
            if ($ids = $this->recIds()) {
                $this->fx->pdo()->exec('DELETE FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendations_id IN (' . implode(',', $ids) . ')');
            }
            $this->fx->cleanup();
        }
    }

    /** @var list<int> */
    private array $made = [];

    /** @return list<int> */
    private function recIds(): array
    {
        return array_map('intval', $this->made);
    }

    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('act-recip', $this->k, $this->p);
        $author    = $this->fx->createPlayerWithToken('act-author', $this->k, $this->p);
        $parkOff   = $this->fx->createPlayerWithToken('act-parkoff', $this->k, $this->p);
        $this->fx->insertScopedAuth($parkOff['mundane_id'], $this->p, 0, 'create');
        $kOff      = $this->fx->createPlayerWithToken('act-koff', $this->k, $this->p);
        $this->fx->insertScopedAuth($kOff['mundane_id'], 0, $this->k, 'create');
        $plain     = $this->fx->createPlayerWithToken('act-plain', $this->k, $this->p);

        return ['recipient' => $recipient, 'author' => $author, 'parkOfficer' => $parkOff,
            'kingdomOfficer' => $kOff, 'plain' => $plain];
    }

    private function rec(array $c, int $level, array $extra = []): int
    {
        $id = $this->fx->createRecommendation(
            $c['recipient']['mundane_id'],
            $this->ka['kingdomaward_id'],
            $this->ka['award_id'],
            $extra + ['monarchy_only' => $level, 'recommended_by_id' => $c['author']['mundane_id'], 'reason' => 'act secret']
        );
        $this->made[] = $id;

        return $id;
    }

    private function call(string $method, string $token, array $req): array
    {
        unset($_SESSION['is_authorized_mundane_id']);

        return \Ork3::$Lib->player->$method(['Token' => $token] + $req);
    }

    private function row(int $id): array
    {
        return $this->fx->fetchRecommendation($id);
    }

    public function testRecipientCannotDismissAHiddenRecAboutThemselves(): void
    {
        $c   = $this->cast();
        $tok = $c['recipient']['token'];
        foreach ([1, 2] as $level) {
            $hid = $this->rec($c, $level, ['rank' => $level]);
            $this->assertSame(
                $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $this->missing]),
                $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $hid]),
                "recipient dismiss of a level-$level hide must read as a missing rec"
            );
            $this->assertNull($this->row($hid)['deleted_at'], 'the hidden rec is still live');
        }
        // Control: the recipient may still dismiss a public rec about themselves.
        $pub = $this->rec($c, 0, ['rank' => 3]);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $pub])['Status']);
    }

    public function testParkOfficerCannotActOnAKingdomLevelHide(): void
    {
        $c   = $this->cast();
        $tok = $c['parkOfficer']['token'];
        $hid = $this->rec($c, 2, ['rank' => 1]);
        $gone = $this->rec($c, 2, ['rank' => 2, 'dismissed_by' => $c['author']['mundane_id']]);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . 'recommendations SET snoozed_by_id = ' . (int) $c['kingdomOfficer']['mundane_id'] . ' WHERE recommendations_id = ' . $hid);

        $cases = [
            ['DeleteAwardRecommendation', $hid, []],
            ['SnoozeAwardRecommendation', $hid, []],
            ['UnsnoozeAwardRecommendation', $hid, []],
            ['RestoreAwardRecommendation', $gone, []],
            ['SetRecommendationPassedToLocal', $hid, ['Passed' => 1]],
            ['EditAwardRecommendationReason', $hid, ['Reason' => 'x']],
        ];
        foreach ($cases as [$method, $id, $extra]) {
            $this->assertSame(
                $this->call($method, $tok, ['RecommendationsId' => $this->missing] + $extra),
                $this->call($method, $tok, ['RecommendationsId' => $id] + $extra),
                "park officer $method on a kingdom-level hide must read as a missing rec"
            );
        }
        $live = $this->row($hid);
        $this->assertNull($live['deleted_at']);
        $this->assertSame((int) $c['kingdomOfficer']['mundane_id'], (int) $live['snoozed_by_id']);
        $this->assertSame(0, (int) $live['passed_to_local']);
        $this->assertSame('act secret', $live['reason']);
        $this->assertNotNull($this->row($gone)['deleted_at'], 'the dismissed hide stays dismissed');
    }

    public function testKingdomOfficerStillActsOnAKingdomLevelHide(): void
    {
        $c   = $this->cast();
        $tok = $c['kingdomOfficer']['token'];
        $hid = $this->rec($c, 2, ['rank' => 1]);
        // A snooze needs a recorded Crown for its scope (the recipient's park).
        $crown = $this->fx->createPlayer('act-crown', $this->k, $this->p)['mundane_id'];
        $this->fx->insertOfficer($crown, $this->k, $this->p, 'Monarch');

        $this->assertSame(0, $this->call('SnoozeAwardRecommendation', $tok, ['RecommendationsId' => $hid])['Status']);
        $this->assertSame(0, $this->call('UnsnoozeAwardRecommendation', $tok, ['RecommendationsId' => $hid])['Status']);
        $this->assertSame(0, $this->call('DeleteAwardRecommendation', $tok, ['RecommendationsId' => $hid])['Status']);
        $this->assertNotNull($this->row($hid)['deleted_at']);
        $this->assertSame(0, $this->call('RestoreAwardRecommendation', $tok, ['RecommendationsId' => $hid])['Status']);
        $this->assertNull($this->row($hid)['deleted_at']);
        // The author can still edit the reason of their own hidden rec.
        $this->assertSame(0, $this->call('EditAwardRecommendationReason', $c['author']['token'], ['RecommendationsId' => $hid, 'Reason' => 'edited'])['Status']);
    }

    public function testClusterResolveSkipsHiddenMembersTheCallerCannotSee(): void
    {
        $c   = $this->cast();
        $pub = $this->rec($c, 0, ['rank' => 1]);
        $hid = $this->rec($c, 2, ['rank' => 1]);
        $resolve = function (string $tok) use ($c) {
            unset($_SESSION['is_authorized_mundane_id']);

            return \Ork3::$Lib->player->ResolveRecommendationCluster([
                'Token' => $tok, 'MundaneId' => $c['recipient']['mundane_id'],
                'KingdomAwardId' => $this->ka['kingdomaward_id'], 'Rank' => 1,
            ]);
        };
        $this->assertSame(1, $resolve($c['parkOfficer']['token'])['Resolved']);
        $this->assertNotNull($this->row($pub)['deleted_at']);
        $this->assertNull($this->row($hid)['deleted_at'], 'a park officer cannot resolve a kingdom-level hide');
        $this->assertSame(1, $resolve($c['kingdomOfficer']['token'])['Resolved']);
        $this->assertNotNull($this->row($hid)['deleted_at']);
    }

    public function testSecondsOnAHiddenRecReadAsMissingForThoseWhoCannotSeeIt(): void
    {
        $c  = $this->cast();
        $id = $this->rec($c, 0, ['rank' => 1]);
        $r  = $this->call('AddSecondToRecommendation', $c['plain']['token'], ['RecommendationsId' => $id, 'Notes' => 'me too']);
        $this->assertSame(0, $r['Status']);
        $secondId = (int) $r['Detail'];
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET monarchy_only = 2 WHERE recommendations_id = $id");

        foreach ([['EditSecondNotes', 'plain', ['Notes' => 'changed']], ['WithdrawSecond', 'plain', []], ['WithdrawSecond', 'parkOfficer', []]] as [$method, $who, $extra]) {
            $this->assertSame(
                $this->call($method, $c[$who]['token'], ['RecommendationSecondsId' => $this->missingSecond] + $extra),
                $this->call($method, $c[$who]['token'], ['RecommendationSecondsId' => $secondId] + $extra),
                "$who $method on a hidden rec's second must read as a missing second"
            );
        }
        $second = $this->fx->pdo()->query('SELECT notes, deleted_at FROM ' . DB_PREFIX . 'recommendation_seconds WHERE recommendation_seconds_id = ' . $secondId)->fetch(\PDO::FETCH_ASSOC);
        $this->assertSame('me too', $second['notes']);
        $this->assertNull($second['deleted_at']);
        // A kingdom officer can still withdraw it.
        $this->assertSame(0, $this->call('WithdrawSecond', $c['kingdomOfficer']['token'], ['RecommendationSecondsId' => $secondId])['Status']);
    }
}
