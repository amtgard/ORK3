<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Controller_PlayerAjax::player()'s 'addaward' action powers the Add Award
 * modal on the CRM player profile (Playernew_index.tpl / pnOpenAwardModal).
 * It exists so the modal — unlike the legacy Admin/player 'addaward' case,
 * which re-renders the whole admin page and discards the result — can read
 * back the freshly-written row's AwardsId and offer a "Print certificate"
 * link (Model_Scrollery::award_is_printable()) without a dead link when the
 * award has no certificate art.
 *
 * The controller itself needs a live session/routing context this suite
 * doesn't stand up, so these tests exercise the exact two-call contract the
 * controller relies on: Model_Player::add_player_award() must return the
 * FLAT shape (['Status' => int, 'AwardsId' => int, ...] — not the nested
 * Errors::Message shape, see reference_add_player_award_flat_status.md), and
 * the AwardsId it hands back must be the same id Model_Scrollery::
 * award_is_printable() resolves against.
 */
final class PlayerAjaxAddAwardTest extends TestCase
{
    private ?PDO $pdo = null;

    private AuthorizationAddFixture $authFixture;

    private PlayerProfileFixture $playerFixture;

    /** @var list<int> awards_id rows this test wrote, deleted in tearDown */
    private array $createdAwardIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }

        $this->authFixture = AuthorizationAddFixture::create();
        $this->playerFixture = PlayerProfileFixture::create();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->createdAwardIds as $awardsId) {
            $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'awards WHERE awards_id = ' . (int) $awardsId);
        }
        $this->authFixture->cleanup();
        $this->playerFixture->cleanup();
    }

    /**
     * award_id 245 (a knighthood-ladder award) has a kingdomaward row in
     * every seeded kingdom (see kingdomaward WHERE award_id = 245) and
     * always resolves a scroll order via Scroll::OrderForAwardId(), so it is
     * always printable — the same fact ScrolleryAwardIsPrintableTest relies
     * on for its "real ladder grant" case.
     */
    private function ladderKingdomAwardId(int $kingdomId): int
    {
        $stmt = $this->pdo->prepare(
            'SELECT kingdomaward_id FROM ' . DB_PREFIX . 'kingdomaward WHERE kingdom_id = ? AND award_id = 245 LIMIT 1'
        );
        $stmt->execute([$kingdomId]);

        return (int) $stmt->fetchColumn();
    }

    public function testAddPlayerAwardReturnsFlatStatusAndAwardsIdOnSuccess(): void
    {
        $parkId = $this->playerFixture->firstParkId();
        $kingdomId = $this->playerFixture->kingdomIdForPark($parkId);
        $kingdomAwardId = $this->ladderKingdomAwardId($kingdomId);
        if ($kingdomAwardId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 245 in this kingdom.');
        }

        $recipient = $this->playerFixture->createPlayer($parkId, 'addaward-recipient');
        $grantor = $this->authFixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'addaward-grantor');

        $model = new Model_Player();
        $r = $model->add_player_award([
            'Token'          => $grantor['token'],
            'RecipientId'    => $recipient['mundane_id'],
            'KingdomAwardId' => $kingdomAwardId,
            'CustomName'     => '',
            'AliasAwardId'   => 0,
            'Rank'           => '',
            'Date'           => date('Y-m-d'),
            'GivenById'      => $grantor['mundane_id'],
            'Note'           => 'PlayerAjaxAddAwardTest fixture grant',
            'ParkId'         => $parkId,
            'KingdomId'      => $kingdomId,
            'EventId'        => 0,
        ]);

        // The controller checks $r['Status'] directly (flat int, 0 = success) —
        // never $r['Status']['Status'] (the Errors::Message shape). Assert the
        // flat contract explicitly so a future change to AddAward() that
        // reintroduces the nested shape fails here instead of silently
        // breaking the modal's success detection.
        $this->assertIsInt($r['Status'], 'add_player_award() must return a flat int Status, not a nested shape.');
        $this->assertSame(0, $r['Status']);
        $this->assertArrayHasKey('AwardsId', $r);
        $this->assertGreaterThan(0, (int) $r['AwardsId']);

        $this->createdAwardIds[] = (int) $r['AwardsId'];
    }

    /**
     * The controller's printable flag is only meaningful if it is computed
     * against the SAME id the grant just wrote — this pins that end-to-end,
     * rather than trusting award_is_printable() in isolation (already covered
     * by ScrolleryAwardIsPrintableTest against pre-existing seed rows).
     */
    public function testFreshlyGrantedLadderAwardIsPrintable(): void
    {
        $parkId = $this->playerFixture->firstParkId();
        $kingdomId = $this->playerFixture->kingdomIdForPark($parkId);
        $kingdomAwardId = $this->ladderKingdomAwardId($kingdomId);
        if ($kingdomAwardId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 245 in this kingdom.');
        }

        $recipient = $this->playerFixture->createPlayer($parkId, 'printable-recipient');
        $grantor = $this->authFixture->createGrantorWithAuth(AUTH_PARK, $parkId, AUTH_CREATE, 'printable-grantor');

        $model = new Model_Player();
        $r = $model->add_player_award([
            'Token'          => $grantor['token'],
            'RecipientId'    => $recipient['mundane_id'],
            'KingdomAwardId' => $kingdomAwardId,
            'CustomName'     => '',
            'AliasAwardId'   => 0,
            'Rank'           => '',
            'Date'           => date('Y-m-d'),
            'GivenById'      => $grantor['mundane_id'],
            'Note'           => 'PlayerAjaxAddAwardTest printable fixture grant',
            'ParkId'         => $parkId,
            'KingdomId'      => $kingdomId,
            'EventId'        => 0,
        ]);
        $this->assertSame(0, $r['Status']);
        $awardsId = (int) $r['AwardsId'];
        $this->createdAwardIds[] = $awardsId;

        $scrollery = new Model_Scrollery();
        $this->assertTrue(
            $scrollery->award_is_printable($awardsId),
            'A fresh grant of a knighthood-ladder award (245) must be printable.'
        );
    }

    /**
     * A grantor with no authorization over the recipient's park must fail
     * closed — mirroring the exact guard the controller uses
     * (($r['Status'] ?? 1) == 0) — and must never leak an AwardsId a caller
     * could mistake for a real grant (the double-grant trap this contract
     * exists to prevent, per reference_add_player_award_flat_status.md).
     */
    public function testAddPlayerAwardFailsClosedWithoutAuthority(): void
    {
        $parkId = $this->playerFixture->firstParkId();
        $kingdomId = $this->playerFixture->kingdomIdForPark($parkId);
        $kingdomAwardId = $this->ladderKingdomAwardId($kingdomId);
        if ($kingdomAwardId <= 0) {
            $this->markTestSkipped('No kingdomaward row for award_id 245 in this kingdom.');
        }

        $recipient = $this->playerFixture->createPlayer($parkId, 'noauth-recipient');
        $noAuthGrantor = $this->authFixture->createGrantorWithoutAuth('addaward-noauth');

        $model = new Model_Player();
        $r = $model->add_player_award([
            'Token'          => $noAuthGrantor['token'],
            'RecipientId'    => $recipient['mundane_id'],
            'KingdomAwardId' => $kingdomAwardId,
            'CustomName'     => '',
            'AliasAwardId'   => 0,
            'Rank'           => '',
            'Date'           => date('Y-m-d'),
            'GivenById'      => $noAuthGrantor['mundane_id'],
            'Note'           => 'PlayerAjaxAddAwardTest unauthorized attempt',
            'ParkId'         => $parkId,
            'KingdomId'      => $kingdomId,
            'EventId'        => 0,
        ]);

        $this->assertNotSame(0, $r['Status'] ?? 1);
        $this->assertSame(0, (int) ($r['AwardsId'] ?? 0), 'A failed grant must not report an AwardsId.');
    }
}
