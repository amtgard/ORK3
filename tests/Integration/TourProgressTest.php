<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * OrkTour progress (ork_tour_progress): one row per person per tour, written by
 * Player::save_tour_progress() and read back by Player::get_tour_progress() in the
 * map shape default.theme hands to ork-tour.js; MergePlayer() carries rows over.
 */
final class TourProgressTest extends TestCase
{
    private PlayerProfileFixture $fixture;
    private PDO $pdo;
    private Player $player;
    /** @var list<int> */
    private array $mundaneIds = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        unset($_SESSION['is_authorized_mundane_id']);
        $this->fixture = PlayerProfileFixture::create();
        $this->pdo = new PDO(
            sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
            DB_USERNAME,
            DB_PASSWORD,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $this->player = new Player();
    }

    protected function tearDown(): void
    {
        unset($_SESSION['is_authorized_mundane_id']);
        if (isset($this->pdo)) {
            foreach ($this->mundaneIds as $id) {
                $this->pdo->exec('DELETE FROM ' . DB_PREFIX . 'tour_progress WHERE mundane_id = ' . (int) $id);
            }
        }
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    private function newPlayerId(string $suffix): int
    {
        $id = (int) $this->fixture->createPlayer($this->fixture->firstParkId(), $suffix)['mundane_id'];
        $this->mundaneIds[] = $id;

        return $id;
    }

    /** @return list<array<string,mixed>> */
    private function rows(int $mundaneId): array
    {
        return $this->pdo->query(
            'SELECT tour_id, version, status, last_step FROM ' . DB_PREFIX . 'tour_progress WHERE mundane_id = '
            . $mundaneId . ' ORDER BY tour_id'
        )->fetchAll(PDO::FETCH_ASSOC);
    }

    public function testSaveThenReadReturnsTheMapShape(): void
    {
        $id = $this->newPlayerId('tour-read');
        $this->assertSame([], $this->player->get_tour_progress($id));

        $res = $this->player->save_tour_progress($id, 'new-player', 1, 'completed', 9);
        $this->assertSame(0, $res['Status'], json_encode($res));

        $progress = $this->player->get_tour_progress($id);
        $this->assertSame(['new-player'], array_keys($progress));
        $this->assertSame(1, $progress['new-player']['v']);
        $this->assertSame('completed', $progress['new-player']['status']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $progress['new-player']['at']);
        $this->assertSame(['v', 'status', 'at'], array_keys($progress['new-player']));
        $this->assertSame(
            [['tour_id' => 'new-player', 'version' => 1, 'status' => 'completed', 'last_step' => 9]],
            array_map(static fn ($r) => [
                'tour_id' => $r['tour_id'], 'version' => (int) $r['version'],
                'status' => $r['status'], 'last_step' => (int) $r['last_step'],
            ], $this->rows($id)),
        );
    }

    public function testDigitStringsFromAFormPostAreAccepted(): void
    {
        $id = $this->newPlayerId('tour-str');
        $res = $this->player->save_tour_progress($id, 'kingdom_page-2', '3', 'dismissed', '0');
        $this->assertSame(0, $res['Status'], json_encode($res));
        $this->assertSame(['v' => 3, 'status' => 'dismissed'], array_intersect_key(
            $this->player->get_tour_progress($id)['kingdom_page-2'],
            ['v' => 1, 'status' => 1],
        ));
    }

    public function testResaveWithAHigherVersionUpdatesTheSingleRow(): void
    {
        $id = $this->newPlayerId('tour-bump');
        $this->assertSame(0, $this->player->save_tour_progress($id, 'new-player', 1, 'dismissed', 2)['Status']);
        $this->assertSame(0, $this->player->save_tour_progress($id, 'new-player', 2, 'completed', 8)['Status']);

        $rows = $this->rows($id);
        $this->assertCount(1, $rows);
        $this->assertSame(2, (int) $rows[0]['version']);
        $this->assertSame('completed', $rows[0]['status']);
        $this->assertSame(8, (int) $rows[0]['last_step']);
        $this->assertSame(2, $this->player->get_tour_progress($id)['new-player']['v']);
    }

    public function testProgressIsPerPerson(): void
    {
        $a = $this->newPlayerId('tour-a');
        $b = $this->newPlayerId('tour-b');
        $this->player->save_tour_progress($a, 'new-player', 1, 'completed', 5);
        $this->assertSame([], $this->player->get_tour_progress($b));
    }

    /** @return array<string, array{0: string, 1: mixed, 2: string, 3: mixed}> */
    public static function invalidInputs(): array
    {
        return [
            'tour id upper case'      => ['New-Player', 1, 'completed', 0],
            'tour id leading dash'    => ['-new', 1, 'completed', 0],
            'tour id quote'           => ["new'player", 1, 'completed', 0],
            'tour id empty'           => ['', 1, 'completed', 0],
            'tour id 65 chars'        => [str_repeat('a', 65), 1, 'completed', 0],
            'status not in enum'      => ['new-player', 1, 'started', 0],
            'status empty'            => ['new-player', 1, '', 0],
            'version zero'            => ['new-player', 0, 'completed', 0],
            'version negative'        => ['new-player', -1, 'completed', 0],
            'version not a number'    => ['new-player', 'abc', 'completed', 0],
            'version with trailing'   => ['new-player', '1abc', 'completed', 0],
            'version empty string'    => ['new-player', '', 'completed', 0],
            'last step negative'      => ['new-player', 1, 'completed', -1],
            'last step not a number'  => ['new-player', 1, 'completed', 'x'],
            'last step float string'  => ['new-player', 1, 'completed', '1.5'],
        ];
    }

    /** @dataProvider invalidInputs */
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidInputs')]
    public function testInvalidInputIsRejectedAndWritesNothing(string $tourId, $version, string $status, $lastStep): void
    {
        $id = $this->newPlayerId('tour-bad');
        $res = $this->player->save_tour_progress($id, $tourId, $version, $status, $lastStep);
        $this->assertNotSame(0, $res['Status'], json_encode($res));
        $this->assertSame([], $this->rows($id));
    }

    public function testInvalidMundaneIdIsRejected(): void
    {
        $this->assertNotSame(0, $this->player->save_tour_progress(0, 'new-player', 1, 'completed', 0)['Status']);
        $this->assertSame([], $this->player->get_tour_progress(0));
    }

    /** Token-free, so neither method may be reachable through orkservice/Json in any spelling. */
    public function testMethodsAreNotJsonCallable(): void
    {
        $server   = new \JsonServer(['Player']);
        $validate = new \ReflectionMethod(\JsonServer::class, 'validate_method');
        $validate->setAccessible(true);
        foreach (['save_tour_progress', 'Save_Tour_Progress', 'SAVE_TOUR_PROGRESS',
                  'get_tour_progress', 'Get_Tour_Progress', 'GET_TOUR_PROGRESS'] as $spelling) {
            $this->assertTrue(method_exists(Player::class, $spelling));
            $this->assertFalse((bool) $validate->invoke($server, 'Player', $spelling), "Player/$spelling is JSON-callable");
        }
    }

    public function testMergeKeepsTheHigherVersionAndMovesTheRest(): void
    {
        $parkId = $this->fixture->firstParkId();
        $kingdomId = $this->fixture->kingdomIdForPark($parkId);
        $admin = $this->fixture->createPlayer($parkId, 'tour-mrg-admin');
        $this->fixture->insertScopedAuth($admin['mundane_id'], $parkId, $kingdomId, 'admin');
        $from = $this->newPlayerId('tour-mrg-from');
        $to = $this->newPlayerId('tour-mrg-to');

        // shared-a: source has the higher version, so the source row wins.
        $this->player->save_tour_progress($from, 'shared-a', 2, 'completed', 7);
        $this->player->save_tour_progress($to, 'shared-a', 1, 'dismissed', 1);
        // shared-b: destination has the higher version, so it is kept.
        $this->player->save_tour_progress($from, 'shared-b', 1, 'dismissed', 0);
        $this->player->save_tour_progress($to, 'shared-b', 3, 'completed', 4);
        // only-from moves; only-to stays.
        $this->player->save_tour_progress($from, 'only-from', 1, 'completed', 2);
        $this->player->save_tour_progress($to, 'only-to', 1, 'dismissed', 0);

        $res = $this->player->MergePlayer([
            'Token' => $admin['token'],
            'FromMundaneId' => $from,
            'ToMundaneId' => $to,
        ]);
        $this->assertSame(0, $res['Status'] ?? -1, json_encode($res));

        $this->assertSame([], $this->rows($from));
        $got = [];
        foreach ($this->rows($to) as $r) {
            $got[$r['tour_id']] = [(int) $r['version'], $r['status'], (int) $r['last_step']];
        }
        $this->assertSame([
            'only-from' => [1, 'completed', 2],
            'only-to'   => [1, 'dismissed', 0],
            'shared-a'  => [2, 'completed', 7],
            'shared-b'  => [3, 'completed', 4],
        ], $got);
    }
}
