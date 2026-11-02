<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * The service surfaces apply the gates the site's pages apply. Every test asserts
 * for the anonymous caller, the subject, an officer and an unrelated logged-in
 * player, because a gate that only denies is as wrong as one that only allows.
 */
final class ApiGuardTest extends TestCase
{
    private CourtFixture $fx;
    private \ApiGuard $guard;
    private int $k = 0;
    private int $p = 0;
    private int $otherPark = 0;
    /** @var array<string, array{mundane_id:int, persona:string, token:string}> */
    private array $cast = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx = CourtFixture::create();
        $this->guard = new \ApiGuard();
        $this->k = $this->fx->firstKingdomId();
        $this->p = $this->fx->firstParkId($this->k);
        $this->otherPark = $this->fx->secondParkId($this->k, $this->p);
        if (!$this->otherPark) {
            $this->markTestSkipped('Sandbox kingdom needs two parks.');
        }
        $this->cast = [
            'subject'   => $this->fx->createPlayerWithToken('subject', $this->k, $this->p),
            'officer'   => $this->fx->createPlayerWithToken('officer', $this->k, $this->p),
            'unrelated' => $this->fx->createPlayerWithToken('unrelated', $this->k, $this->otherPark),
            'admin'     => $this->fx->createPlayerWithToken('admin', $this->k, $this->p),
        ];
        $this->fx->insertScopedAuth($this->cast['officer']['mundane_id'], $this->p, 0, 'edit');
        $this->fx->insertGlobalAdmin($this->cast['admin']['mundane_id']);
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
        unset($_REQUEST['Token'], $_SESSION['is_authorized_mundane_id']);
    }

    private function allowed(string $class, string $method, array $args): bool
    {
        return $this->guard->prepare_call_h($class, $method, $args) === null;
    }

    private function asViewer(?string $who, array $request = []): array
    {
        return $who === null ? $request : $request + ['Token' => $this->cast[$who]['token']];
    }

    public function testEveryRuleNamesARealMethodAndARealHandler(): void
    {
        $reflection = new ReflectionClass(\ApiGuard::class);
        foreach (['GATES', 'FILTERS'] as $table) {
            foreach ($reflection->getConstant($table) as $call => $handler) {
                [$class, $method] = explode('/', $call);
                $this->assertTrue(method_exists($class, $method), "$call is not a method");
                $this->assertTrue($reflection->hasMethod($handler), "$handler is not a handler");
            }
        }
    }

    public function testApiGuardIsNotWhitelisted(): void
    {
        $this->assertStringNotContainsString("'ApiGuard'", file_get_contents(DIR_SERVICE . 'Json/index.php'));
    }

    public function testPlayerPrivateReads(): void
    {
        foreach (['GetNotes', 'GetNotesCount'] as $method) {
            $target = ['MundaneId' => $this->cast['subject']['mundane_id']];
            $this->assertFalse($this->allowed('Player', $method, [$target]), 'anonymous');
            $this->assertFalse($this->allowed('Player', $method, [$this->asViewer('unrelated', $target)]), 'unrelated');
            $this->assertTrue($this->allowed('Player', $method, [$this->asViewer('subject', $target)]), 'self');
            $this->assertTrue($this->allowed('Player', $method, [$this->asViewer('officer', $target)]), 'officer');
            $this->assertTrue($this->allowed('Player', $method, [$this->asViewer('admin', $target)]), 'admin');
        }
    }

    public function testRulesApplyInAnyCapitalization(): void
    {
        $target = ['MundaneId' => $this->cast['subject']['mundane_id']];
        $this->assertFalse($this->allowed('Player', 'GETNOTES', [$target]));
    }

    public function testRequestIdentityFieldsAreOverwritten(): void
    {
        $args = [['KingdomId' => $this->k, 'RequestedBy' => $this->cast['admin']['mundane_id'], 'IncludeDismissed' => 1]];
        $this->assertNull($this->guard->prepare_call_h('Report', 'PlayerAwardRecommendations', $args));
        $this->assertSame(0, $args[0]['RequestedBy']);
        $this->assertArrayNotHasKey('IncludeDismissed', $args[0]);

        $args = [['Query' => 'x', 'CallerUserId' => $this->cast['admin']['mundane_id']]];
        $this->assertNull($this->guard->prepare_call_h('SearchService', 'UniversalSearch', $args));
        $this->assertSame(0, $args[0]['CallerUserId']);
        $this->assertTrue($args[0]['PersonaOnly']);
    }

    public function testDuesHistoryNarrowsToTheProfilesPublicViewForOthers(): void
    {
        $target = ['MundaneId' => $this->cast['subject']['mundane_id']];

        $args = [$target];
        $this->assertNull($this->guard->prepare_call_h('Player', 'GetDues', $args));
        $this->assertSame(1, $args[0]['Active']);
        $this->assertSame(1, $args[0]['ExcludeRevoked']);

        $args = [$this->asViewer('officer', $target)];
        $this->assertNull($this->guard->prepare_call_h('Player', 'GetDues', $args));
        $this->assertArrayNotHasKey('Active', $args[0]);
    }

    public function testAccountFieldsReachOnlyTheSubjectAndTheirOfficers(): void
    {
        $id = $this->cast['subject']['mundane_id'];
        $player = fn (?string $who) => $this->guard->filter_result_h(
            'Player',
            'GetPlayer',
            [$this->asViewer($who, ['MundaneId' => $id])],
            ['Player' => ['MundaneId' => $id, 'ParkId' => $this->p, 'UserName' => 'name', 'PasswordExpires' => 'date']]
        )['Player'];

        $this->assertSame('', $player(null)['UserName']);
        $this->assertSame('', $player('unrelated')['PasswordExpires']);
        $this->assertSame('name', $player('subject')['UserName']);
        $this->assertSame('date', $player('officer')['PasswordExpires']);
        $this->assertSame('name', $player('admin')['UserName']);
    }

    public function testAnonymousSearchIsLimitedToPersona(): void
    {
        $args = ['USER', 'term', 15, null, null, null, null];
        $this->assertNull($this->guard->prepare_call_h('SearchService', 'Player', $args));
        $this->assertSame('PERSONA', $args[0]);

        $args = ['USER', 'term', 15, null, null, null, $this->cast['unrelated']['token']];
        $this->assertNull($this->guard->prepare_call_h('SearchService', 'Player', $args));
        $this->assertSame('USER', $args[0]);
    }

    public function testExpensiveUnscopedReportsNeedAScopeOrACaller(): void
    {
        $this->assertFalse($this->allowed('Report', 'AttendanceSummary', [['PerWeeks' => 1]]));
        $this->assertTrue($this->allowed('Report', 'AttendanceSummary', [['KingdomId' => $this->k]]));
        $this->assertTrue($this->allowed('Report', 'AttendanceSummary', [$this->asViewer('unrelated')]));

        $this->assertFalse($this->allowed('Report', 'GetActivePlayers', [['KingdomId' => $this->k]]));
        $this->assertFalse($this->allowed('Report', 'GetActivePlayers', [$this->asViewer('unrelated')]));
        $this->assertTrue($this->allowed('Report', 'GetActivePlayers', [$this->asViewer('unrelated', ['KingdomId' => $this->k])]));
    }

    public function testUngatedCallsPassUntouched(): void
    {
        $args = [['KingdomId' => $this->k]];
        $this->assertNull($this->guard->prepare_call_h('Kingdom', 'GetKingdomDetails', $args));
        $this->assertSame([['KingdomId' => $this->k]], $args);
    }

    public function testOfficerDirectoryContactsAreForAdmins(): void
    {
        $rows = ['Rows' => [['MonarchPersona' => 'p', 'MonarchGiven' => 'g', 'MonarchSurname' => 's', 'MonarchEmail' => 'e']]];
        $filtered = fn (?string $who) => $this->guard->filter_result_h(
            'Report',
            'GetKingdomOfficerDirectoryMerged',
            [$this->asViewer($who)],
            $rows
        )['Rows'][0];

        $this->assertSame(['MonarchPersona' => 'p', 'MonarchGiven' => '', 'MonarchSurname' => '', 'MonarchEmail' => ''], $filtered(null));
        $this->assertSame('', $filtered('officer')['MonarchEmail']);
        $this->assertSame($rows['Rows'][0], $filtered('admin'));
    }
}
