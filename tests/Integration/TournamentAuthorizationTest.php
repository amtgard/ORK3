<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * Tournament creation, which until now authorized nothing.
 *
 * Tournament::CreateTournament() resolved the token and checked valid_id($mundane_id) --
 * that was the whole gate. KingdomId, ParkId and EventCalendarDetailId came straight from
 * the request and were written unverified, so any logged-in player could stamp a
 * tournament onto any kingdom, park, or event in the game. tournament.bracket.manage
 * could not cover it: that key authorizes against a tournament's OWN recorded scope,
 * which does not exist until the row is written. Hence tournament.create.
 *
 * The second defect is in check_auth(), the helper that does consult
 * tournament.bracket.manage. It reassigned $Token to the token string and only then read
 * $Token['TournamentId'] from it -- indexing a string with a non-numeric offset, which in
 * PHP 8 yields the token's first character. All four callers pass an array, so the
 * lookup never found a tournament and every bracket endpoint refused everyone.
 */
final class TournamentAuthorizationTest extends TestCase
{
    private const MARKER = 'zztourneyauth';
    private const KINGDOM_ID = 100049;
    private const OTHER_KINGDOM_ID = 100050;
    private const PARK_ID = 100060;
    private const EVENT_ID = 100061;
    private const ECD_ID = 100062;

    private ?PDO $pdo = null;
    private ?AuthorizedOfficerFixture $fixture = null;
    private ?RbacRoleFixture $rbac = null;
    private ?int $strangerId = null;
    private ?string $strangerToken = null;

    /** @var list<int> */
    private array $tournamentIds = [];
    /** @var list<string> "table:column:id" rows seeded by seedOrgUnits() */
    private array $orgRows = [];

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            foreach ($this->tournamentIds as $id) {
                // ork_bracket / ork_participant carry no marker column and no foreign key,
                // so a bracket written by AddBracket outlives its tournament unless it is
                // deleted here first -- an orphan row in a shared test database pointing at
                // a tournament id that will be reused.
                $this->pdo->exec('DELETE FROM ork_participant WHERE tournament_id = ' . $id);
                $this->pdo->exec('DELETE FROM ork_bracket WHERE tournament_id = ' . $id);
                $this->pdo->exec('DELETE FROM ork_tournament WHERE tournament_id = ' . $id);
            }
            $this->tournamentIds = [];

            $this->pdo->exec(
                'DELETE b FROM ork_bracket b JOIN ork_tournament t ON t.tournament_id = b.tournament_id'
                . " WHERE t.name LIKE '" . self::MARKER . "%'"
            );
            $this->pdo->exec("DELETE FROM ork_tournament WHERE name LIKE '" . self::MARKER . "%'");

            foreach ($this->orgRows as $row) {
                [$table, $column, $id] = explode(':', $row);
                $this->pdo->exec('DELETE FROM ' . $table . ' WHERE ' . $column . ' = ' . (int) $id);
            }
            $this->orgRows = [];
        }

        $this->rbac?->cleanup();
        $this->rbac = null;
        $this->fixture?->cleanup();
        $this->fixture = null;
    }

    private function pdoConnection(): PDO
    {
        if ($this->pdo === null) {
            $this->pdo = new PDO(
                sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE),
                DB_USERNAME,
                DB_PASSWORD,
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
        }

        return $this->pdo;
    }

    private function rbac(): RbacRoleFixture
    {
        if ($this->rbac === null) {
            $this->rbac = new RbacRoleFixture($this->pdoConnection(), self::MARKER, self::KINGDOM_ID);
        }

        return $this->rbac;
    }

    /**
     * A real park, event and calendar detail inside KINGDOM_ID.
     *
     * CreateTournament resolves the owning event from the calendar detail before
     * authorizing, and the RBAC cascade resolves park -> kingdom and event -> kingdom
     * out of these tables, so the park/event scopes need rows behind them or the
     * cascade silently has nothing to walk.
     */
    private function seedOrgUnits(): void
    {
        if ($this->orgRows !== []) {
            return;
        }
        $pdo = $this->pdoConnection();

        $pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZT", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        )->execute([
            ':pid' => self::PARK_ID,
            ':kid' => self::KINGDOM_ID,
            ':name' => self::MARKER . '_park',
        ]);
        $this->orgRows[] = 'ork_park:park_id:' . self::PARK_ID;

        $pdo->prepare(
            'INSERT INTO ork_event (event_id, kingdom_id, park_id, mundane_id, unit_id, name)
             VALUES (:id, :kid, :pid, 0, 0, :name)'
        )->execute([
            ':id' => self::EVENT_ID,
            ':kid' => self::KINGDOM_ID,
            ':pid' => self::PARK_ID,
            ':name' => self::MARKER . ' Event',
        ]);
        $this->orgRows[] = 'ork_event:event_id:' . self::EVENT_ID;

        $pdo->prepare(
            'INSERT INTO ork_event_calendardetail
                (event_calendardetail_id, event_id, price, event_start, event_end, description,
                 url, url_name, address, province, postal_code, city, country, map_url,
                 map_url_name, google_geocode, location, latitude, longitude)
             VALUES (:id, :eid, 0, "2026-09-01 09:00:00", "2026-09-01 18:00:00", "",
                 "", "", "", "", "", "", "", "", "", "", "", 0, 0)'
        )->execute([':id' => self::ECD_ID, ':eid' => self::EVENT_ID]);
        $this->orgRows[] = 'ork_event_calendardetail:event_calendardetail_id:' . self::ECD_ID;
    }

    /** An authenticated player holding no authority anywhere. */
    private function strangerToken(): string
    {
        if ($this->strangerToken === null) {
            [$this->strangerId, $this->strangerToken] = $this->rbac()
                ->seedPlayer('stranger', true, 0, self::OTHER_KINGDOM_ID);
        }

        return $this->strangerToken;
    }

    /** An officer with a kingdom-scoped AUTH_CREATE row, which the bridge accepts. */
    private function officerToken(): string
    {
        if ($this->fixture === null) {
            $this->fixture = new AuthorizedOfficerFixture($this->pdoConnection(), self::MARKER, self::KINGDOM_ID);
        }

        return $this->fixture->createAuthorizedOfficer();
    }

    /**
     * An officer whose ONLY authority is one legacy ork_authorization row at $scope.
     * Used to prove check_auth()'s narrowest-first order still admits a park-only or
     * event-only organizer -- neither of whom holds anything at kingdom scope.
     *
     * @param array{kingdom_id?: int, park_id?: int, event_id?: int} $scope
     */
    private function scopedOfficerToken(string $suffix, array $scope): string
    {
        [$id, $token] = $this->rbac()->seedPlayer($suffix, true, 0, self::KINGDOM_ID);
        $this->rbac()->grantLegacyAuthorization($id, $scope);

        return $token;
    }

    /** An actor holding tournament.create through a role, and NO legacy authorization row. */
    private function permissionOnlyToken(string $key, array $scope): string
    {
        $rbac = $this->rbac();
        [$id, $token] = $rbac->seedPlayer('permonly_' . bin2hex(random_bytes(3)), true, 0, self::KINGDOM_ID);
        $roleId = $rbac->seedRoleWith($key, 'kingdom', self::KINGDOM_ID);
        $rbac->assignRole($id, $roleId, $scope);

        self::assertSame(
            0,
            (int) $this->pdoConnection()
                ->query('SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $id)
                ->fetchColumn(),
            'Fixture check: the actor must hold NO legacy row, or the bridge covers for RBAC.'
        );

        return $token;
    }

    /** Creates a tournament as the kingdom officer and returns its id. */
    private function createTournament(string $name, array $scope = []): int
    {
        $tournament = new Tournament();
        $created = $tournament->CreateTournament([
            'Token' => $this->officerToken(),
            'Name' => self::MARKER . ' ' . $name,
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => $scope['KingdomId'] ?? self::KINGDOM_ID,
            'ParkId' => $scope['ParkId'] ?? 0,
            'EventCalendarDetailId' => $scope['EventCalendarDetailId'] ?? 0,
        ]);
        self::assertSame(0, (int) $created['Status'], 'Fixture check: the tournament must be created.');
        $id = (int) $created['Detail'];
        $this->tournamentIds[] = $id;

        return $id;
    }

    /** A valid AddBracket request -- the enum values ork_bracket actually accepts. */
    private function bracketRequest(string $token, int $tournamentId): array
    {
        return [
            'Token' => $token,
            'TournamentId' => $tournamentId,
            'CopyOfId' => 0,
            'Style' => 'Single Sword',
            'StyleNote' => '',
            'Method' => 'single',
            'Rings' => 1,
            'Participants' => 'individual',
            'Seeding' => 'random',
        ];
    }

    public function testAnAuthenticatedStrangerCannotCreateATournamentInAnotherKingdom(): void
    {
        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $this->strangerToken(),
            'Name' => self::MARKER . ' Intruder',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => self::KINGDOM_ID,
            'ParkId' => 0,
            'EventCalendarDetailId' => 0,
        ]);

        self::assertSame(
            5,
            (int) $r['Status'],
            'A logged-in player with no authority over this kingdom must not be able to create a '
            . 'tournament in it. This was the entire authorization check before tournament.create: '
            . 'valid_id($mundane_id).'
        );

        self::assertSame(
            0,
            (int) $this->pdoConnection()
                ->query("SELECT COUNT(*) FROM ork_tournament WHERE name = '" . self::MARKER . " Intruder'")
                ->fetchColumn(),
            'A refused create must not have written a row.'
        );
    }

    public function testAnAuthorizedOfficerCanCreateATournamentInTheirKingdom(): void
    {
        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $this->officerToken(),
            'Name' => self::MARKER . ' Sanctioned',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => self::KINGDOM_ID,
            'ParkId' => 0,
            'EventCalendarDetailId' => 0,
        ]);

        self::assertSame(0, (int) $r['Status'], 'The legacy kingdom AUTH_CREATE row must still work.');
        self::assertGreaterThan(0, (int) $r['Detail']);
        $this->tournamentIds[] = (int) $r['Detail'];
    }

    public function testCreateRefusesARequestNamingNoOrgUnit(): void
    {
        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $this->officerToken(),
            'Name' => self::MARKER . ' Orphan',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => 0,
            'ParkId' => 0,
            'EventCalendarDetailId' => 0,
        ]);

        self::assertSame(
            5,
            (int) $r['Status'],
            'A tournament attached to no kingdom, park, or event has nothing to authorize against '
            . 'now or later -- check_auth() would find no scope on it either.'
        );
    }

    public function testBracketEndpointsResolveTheTournamentFromAnArrayRequest(): void
    {
        // Regression guard for the $Token shadowing in check_auth(). The observable
        // symptom was that AddBracket and its three siblings refused EVERY caller,
        // including a fully-authorized one, because the tournament lookup was handed a
        // single character of the token instead of the id.
        //
        // "Not refused" is not the invariant: a validation error, a null return or a
        // future ProcessingError would all satisfy it while the endpoint still did
        // nothing. The bracket has to actually exist.
        $token = $this->officerToken();
        $tournament = new Tournament();
        $tournamentId = $this->createTournament('Bracketed');

        $r = $tournament->AddBracket($this->bracketRequest($token, $tournamentId));

        self::assertSame(
            0,
            (int) ($r['Status'] ?? 5),
            'check_auth() must resolve TournamentId out of the request array. While it read the '
            . 'field off the token string instead, this authorized officer was refused.'
        );
        $bracketId = (int) ($r['Detail'] ?? 0);
        self::assertGreaterThan(0, $bracketId, 'AddBracket must return the new bracket id.');
        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_bracket WHERE bracket_id = ' . $bracketId
                . ' AND tournament_id = ' . $tournamentId
            )->fetchColumn(),
            'And the id it returned must be a real bracket row on that tournament.'
        );
    }

    public function testTheStrangerIsStillRefusedByTheBracketGate(): void
    {
        $tournament = new Tournament();
        $tournamentId = $this->createTournament('Guarded');

        // Fixing the shadowing must not turn a broken-closed gate into an open one.
        $r = $tournament->AddBracket($this->bracketRequest($this->strangerToken(), $tournamentId));

        self::assertSame(5, (int) ($r['Status'] ?? 0), 'A stranger must still be refused.');
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_bracket WHERE tournament_id = ' . $tournamentId
            )->fetchColumn(),
            'A refused AddBracket must not have written a bracket.'
        );
    }

    // ------------------------------------------------------------------
    // The RBAC branch of may_create_tournament().
    //
    // Every case above authorizes through a legacy kingdom-scoped AUTH_CREATE row, so
    // the bridge covers for the permission branch entirely: tournament.create could be
    // registered at a scope no call site ever checks and nothing here would notice.
    // These three seed the permission through a role and NO legacy row at all.
    // ------------------------------------------------------------------

    public function testTournamentCreatePermissionAloneAuthorizesAKingdomTournament(): void
    {
        $token = $this->permissionOnlyToken('tournament.create', ['kingdom_id' => self::KINGDOM_ID]);

        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $token,
            'Name' => self::MARKER . ' Perm Kingdom',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => self::KINGDOM_ID,
            'ParkId' => 0,
            'EventCalendarDetailId' => 0,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'tournament.create held through a role, with no ork_authorization row anywhere, must '
            . 'be sufficient -- otherwise the key is decorative and the legacy bridge is the gate.'
        );
        $this->tournamentIds[] = (int) $r['Detail'];
    }

    public function testTournamentCreatePermissionAuthorizesAParkTournament(): void
    {
        $this->seedOrgUnits();
        $token = $this->permissionOnlyToken('tournament.create', ['kingdom_id' => self::KINGDOM_ID]);

        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $token,
            'Name' => self::MARKER . ' Perm Park',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => self::KINGDOM_ID,
            'ParkId' => self::PARK_ID,
            'EventCalendarDetailId' => 0,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'A park request is authorized at PARK scope, which a kingdom-scoped grant reaches '
            . 'through the cascade. If it does not, every kingdom officer loses park tournaments.'
        );
        $this->tournamentIds[] = (int) $r['Detail'];
    }

    public function testTournamentCreatePermissionAuthorizesAnEventTournament(): void
    {
        $this->seedOrgUnits();
        $token = $this->permissionOnlyToken('tournament.create', ['kingdom_id' => self::KINGDOM_ID]);

        $tournament = new Tournament();
        $r = $tournament->CreateTournament([
            'Token' => $token,
            'Name' => self::MARKER . ' Perm Event',
            'Description' => '',
            'When' => '2026-09-01 10:00:00',
            'KingdomId' => self::KINGDOM_ID,
            'ParkId' => 0,
            'EventCalendarDetailId' => self::ECD_ID,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'An EventCalendarDetailId resolves to an event and is authorized at EVENT scope. '
            . 'tournament.create is declared scope_type=event but exercised at three scopes; '
            . 'this is the one that proves the declaration and the call site agree.'
        );
        $this->tournamentIds[] = (int) $r['Detail'];
    }

    // ------------------------------------------------------------------
    // check_auth()'s narrowest-first order, at the scopes only the reorder reaches.
    // Both officers below hold NOTHING at kingdom scope, so under the old widest-first
    // order they were locked out of their own park's / event's brackets.
    // ------------------------------------------------------------------

    public function testAParkOnlyOfficerMayManageTheirParksBrackets(): void
    {
        $this->seedOrgUnits();
        $token = $this->scopedOfficerToken('parkofficer', ['park_id' => self::PARK_ID]);
        $tournamentId = $this->createTournament('Park Bracketed', ['ParkId' => self::PARK_ID]);

        $tournament = new Tournament();
        $r = $tournament->AddBracket($this->bracketRequest($token, $tournamentId));

        self::assertSame(
            0,
            (int) ($r['Status'] ?? 5),
            'A park tournament is saved with BOTH kingdom_id and park_id. Checking kingdom first '
            . 'demanded kingdom authority from the park officer who ran the tournament.'
        );
    }

    public function testAnEventOnlyOfficerMayManageThatEventsBrackets(): void
    {
        $this->seedOrgUnits();
        $token = $this->scopedOfficerToken('eventofficer', ['event_id' => self::EVENT_ID]);
        $tournamentId = $this->createTournament('Event Bracketed', [
            'ParkId' => self::PARK_ID,
            'EventCalendarDetailId' => self::ECD_ID,
        ]);

        $tournament = new Tournament();
        $r = $tournament->AddBracket($this->bracketRequest($token, $tournamentId));

        self::assertSame(
            0,
            (int) ($r['Status'] ?? 5),
            'An event tournament records kingdom, park AND event. The event grant is the '
            . 'narrowest and must be honoured first.'
        );
    }

    // ------------------------------------------------------------------
    // DeleteTournament: reordered to match check_auth() and, until now, untested.
    // An off-by-scope error here locks a park-only organizer out of deleting the
    // tournament they were allowed to create and run.
    // ------------------------------------------------------------------

    public function testDeleteRefusesAStranger(): void
    {
        $tournamentId = $this->createTournament('Delete Guarded');

        $tournament = new Tournament();
        $r = $tournament->DeleteTournament([
            'Token' => $this->strangerToken(),
            'TournamentId' => $tournamentId,
        ]);

        self::assertSame(5, (int) $r['Status'], 'A stranger must not delete a kingdom tournament.');
        self::assertSame(
            1,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_tournament WHERE tournament_id = ' . $tournamentId
            )->fetchColumn(),
            'A refused delete must leave the row in place.'
        );
    }

    public function testAKingdomOfficerMayDeleteAKingdomTournament(): void
    {
        $tournamentId = $this->createTournament('Delete Kingdom');

        $tournament = new Tournament();
        $r = $tournament->DeleteTournament([
            'Token' => $this->officerToken(),
            'TournamentId' => $tournamentId,
        ]);

        self::assertSame(0, (int) $r['Status'], 'Error: ' . (string) ($r['Error'] ?? ''));
        self::assertSame(
            0,
            (int) $this->pdoConnection()->query(
                'SELECT COUNT(*) FROM ork_tournament WHERE tournament_id = ' . $tournamentId
            )->fetchColumn(),
            'A successful delete must remove the row.'
        );
    }

    public function testAParkOnlyOfficerMayDeleteTheirParksTournament(): void
    {
        $this->seedOrgUnits();
        $token = $this->scopedOfficerToken('parkdeleter', ['park_id' => self::PARK_ID]);
        $tournamentId = $this->createTournament('Delete Park', ['ParkId' => self::PARK_ID]);

        $tournament = new Tournament();
        $r = $tournament->DeleteTournament([
            'Token' => $token,
            'TournamentId' => $tournamentId,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'The delete gate must use the same narrowest-first order as check_auth(); checking '
            . 'kingdom first locks the park officer out of their own tournament. Error: '
            . (string) ($r['Error'] ?? '')
        );
    }

    public function testAnEventOnlyOfficerMayDeleteThatEventsTournament(): void
    {
        $this->seedOrgUnits();
        $token = $this->scopedOfficerToken('eventdeleter', ['event_id' => self::EVENT_ID]);
        $tournamentId = $this->createTournament('Delete Event', [
            'ParkId' => self::PARK_ID,
            'EventCalendarDetailId' => self::ECD_ID,
        ]);

        $tournament = new Tournament();
        $r = $tournament->DeleteTournament([
            'Token' => $token,
            'TournamentId' => $tournamentId,
        ]);

        self::assertSame(
            0,
            (int) $r['Status'],
            'Error: ' . (string) ($r['Error'] ?? '')
        );
    }
}
