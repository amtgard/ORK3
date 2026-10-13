<?php

declare(strict_types=1);
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/../Support/RbacRoleFixture.php';

/**
 * The ~30 domain gates the branch swapped from HasAuthority() onto a permission, and
 * the pairing rule that makes them usable.
 *
 * Every one of these methods comes in a set: something that writes a record and
 * something that undoes it. When only one half was converted, the officer holding the
 * new permission could revoke an award and not reinstate it, upload an event's logo and
 * not remove it, enter attendance and not open the sign-in link. That split-brain state
 * passes every existing test in the suite, because no test calls either half -- the
 * audit that found these had to read the source.
 *
 * So: for each pair, one actor holding ONLY the permission (through a role, with no
 * legacy ork_authorization row anywhere) must be able to run BOTH halves, and a
 * stranger must be refused by BOTH. A regression in either half, or a future edit that
 * re-desyncs one, fails here rather than shipping green.
 */
final class DomainGateParityTest extends TestCase
{
    private const MARKER = 'zzgateparity';
    private const KINGDOM_ID = 100070;
    private const PARK_ID = 100071;
    private const EVENT_ID = 100072;

    private ?PDO $pdo = null;
    private ?RbacRoleFixture $rbac = null;
    private ?int $subjectId = null;
    private ?int $awardId = null;
    private ?int $noteId = null;
    private ?int $linkId = null;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo !== null) {
            if ($this->subjectId !== null) {
                $this->pdo->exec('DELETE FROM ork_mundane_note WHERE mundane_id = ' . $this->subjectId);
                $this->pdo->exec('DELETE FROM ork_awards WHERE mundane_id = ' . $this->subjectId);
                if ($this->awardPairIsExercisable()) {
                    $this->pdo->exec('DELETE FROM ork_awards WHERE stripped_from = ' . $this->subjectId);
                }
            }
            $this->pdo->exec("DELETE FROM ork_attendance_link WHERE token LIKE '" . self::MARKER . "%'");
            $this->pdo->exec('DELETE FROM ork_attendance_link WHERE park_id = ' . self::PARK_ID);
            $this->pdo->exec('DELETE FROM ork_event WHERE event_id = ' . self::EVENT_ID);
            $this->pdo->exec('DELETE FROM ork_park WHERE park_id = ' . self::PARK_ID);
            // The note/award/waiver audits are written as ('Player', $request['MundaneId']),
            // so their entity_id is the SUBJECT PLAYER; only ResetWaivers records the park.
            // Deleting by PARK_ID alone matched one of the four and leaked the rest into the
            // shared test database on every run.
            $entityIds = [self::PARK_ID];
            if ($this->subjectId !== null) {
                $entityIds[] = $this->subjectId;
            }
            $this->pdo->exec(
                'DELETE FROM ork_danger_audit WHERE entity_id IN (' . implode(', ', $entityIds) . ')'
                . " AND method_call LIKE 'Player::%'"
            );
        }
        $this->subjectId = null;
        $this->awardId = null;
        $this->noteId = null;
        $this->linkId = null;

        $this->rbac?->cleanup();
        $this->rbac = null;
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
     * The park, event and subject player every pair below acts on, plus one award and
     * one note to undo. Recreated per test so each pair starts from the same state.
     */
    private function seedWorld(): void
    {
        $pdo = $this->pdoConnection();

        $pdo->prepare(
            'INSERT INTO ork_park
                (park_id, kingdom_id, name, abbreviation, url, address, city, province,
                 postal_code, google_geocode, latitude, longitude, location,
                 map_url, description, directions)
             VALUES (:pid, :kid, :name, "ZZD", "", "", "", "", "", "", 0, 0, "", "", "", "")'
        )->execute([
            ':pid' => self::PARK_ID,
            ':kid' => self::KINGDOM_ID,
            ':name' => self::MARKER . '_park',
        ]);

        $pdo->prepare(
            'INSERT INTO ork_event (event_id, kingdom_id, park_id, mundane_id, unit_id, name, has_heraldry)
             VALUES (:id, :kid, :pid, 0, 0, :name, 1)'
        )->execute([
            ':id' => self::EVENT_ID,
            ':kid' => self::KINGDOM_ID,
            ':pid' => self::PARK_ID,
            ':name' => self::MARKER . ' Event',
        ]);

        // The player these records hang off. player_info()['ParkId'] is what every
        // player.* gate below is scoped to, so the park has to be this one.
        $this->subjectId = $this->rbac()->seedUnprivilegedPlayer('subject', self::PARK_ID);

        if ($this->awardPairIsExercisable()) {
            $pdo->prepare(
                'INSERT INTO ork_awards
                    (kingdomaward_id, mundane_id, unit_id, park_id, kingdom_id, team_id, `rank`,
                     date, given_by_id, note, at_park_id, at_kingdom_id, at_event_id,
                     custom_name, award_id, revoked, stripped_from)
                 VALUES (0, :mid, 0, :pid, :kid, 0, 1, "2026-01-01", 0, "", :pid2, :kid2, 0,
                     :name, 0, 0, 0)'
            )->execute([
                ':mid' => $this->subjectId,
                ':pid' => self::PARK_ID,
                ':kid' => self::KINGDOM_ID,
                ':pid2' => self::PARK_ID,
                ':kid2' => self::KINGDOM_ID,
                ':name' => self::MARKER . ' Award',
            ]);
            $this->awardId = (int) $pdo->lastInsertId();
        }

        $pdo->prepare(
            'INSERT INTO ork_mundane_note (mundane_id, note, description, given_by, date, date_complete)
             VALUES (:mid, :note, "", "", "2026-01-01", "0000-00-00")'
        )->execute([':mid' => $this->subjectId, ':note' => self::MARKER . ' original note']);
        $this->noteId = (int) $pdo->lastInsertId();

        $pdo->prepare(
            'INSERT INTO ork_attendance_link
                (token, by_whom_id, park_id, kingdom_id, credits, expires_at, created_at)
             VALUES (:t, 0, :pid, 0, 1, DATE_ADD(NOW(), INTERVAL 3 HOUR), NOW())'
        )->execute([':t' => self::MARKER . bin2hex(random_bytes(6)), ':pid' => self::PARK_ID]);
        $this->linkId = (int) $pdo->lastInsertId();
    }

    /**
     * LOUD, not a bare continue. The award pair is the only coverage of the
     * ReactivateAward authorize-before-validate ordering fix, and ork_awards.stripped_from
     * is in the committed schema (tools/ork-db/templates/schema/supplements.sql), so on a
     * correctly built sandbox this guard is dead defensiveness. Skipping silently would let
     * the pair evaporate behind a fully green run -- the same failure mode as the
     * --allow-catalog-drift opt-out that bin/run-unit-tests.sh deleted.
     *
     * Shared by both test methods: two copies is one copy that gets left behind when the
     * condition changes, which reintroduces exactly that silent evaporation.
     *
     * @param array<string, mixed> $spec
     */
    private function skipIfAwardPairNotExercisable(array $spec): void
    {
        if (($spec['needs_award_columns'] ?? false) && !$this->awardPairIsExercisable()) {
            self::markTestSkipped(
                'ork_awards is missing revoked/stripped_from, so the award pair -- the only '
                . 'coverage of the ReactivateAward ordering fix -- cannot run. Rebuild the '
                . 'sandbox with bin/ork-db deploy-sandbox.'
            );
        }
    }

    /**
     * Whether ork_awards carries the revocation columns RevokeAward writes.
     *
     * ork_test's ork_awards has no `revoked` / `stripped_from` on some snapshots, and
     * yapo drops unknown fields silently -- so running the pair there would assert a
     * write that could never happen and prove nothing. The pair is skipped rather than
     * weakened, and starts running by itself once the schema catches up.
     */
    private function awardPairIsExercisable(): bool
    {
        $pdo = $this->pdoConnection();
        foreach (['revoked', 'stripped_from'] as $column) {
            if ($pdo->query("SHOW COLUMNS FROM ork_awards LIKE '" . $column . "'")->fetch() === false) {
                return false;
            }
        }

        return true;
    }

    /** An actor holding exactly $key through a role at $scope, and no legacy row. */
    private function permissionOnlyToken(string $key, array $scope): string
    {
        $rbac = $this->rbac();
        [$id, $token] = $rbac->seedPlayer('holder_' . bin2hex(random_bytes(3)), true, self::PARK_ID, self::KINGDOM_ID);
        $roleId = $rbac->seedRoleWith($key, 'park', self::KINGDOM_ID);
        $rbac->assignRole($id, $roleId, $scope);

        self::assertSame(
            0,
            (int) $this->pdoConnection()
                ->query('SELECT COUNT(*) FROM ork_authorization WHERE mundane_id = ' . $id)
                ->fetchColumn(),
            'Fixture check: the actor must hold NO legacy authorization row, or the bridge covers '
            . 'for the permission and the gate swap is untested.'
        );

        return $token;
    }

    /** An authenticated player holding nothing at all. */
    private function strangerToken(): string
    {
        [, $token] = $this->rbac()->seedPlayer('stranger', true, 0, self::KINGDOM_ID);

        return $token;
    }

    /**
     * The pairs, as a table.
     *
     * Each half is a closure taking a token and returning the domain response. 'verify'
     * runs only on the authorized pass and proves the WRITE happened -- a status of 0
     * from a method that did nothing would otherwise satisfy the whole test.
     *
     * @return array<string, array{key: string, scope: array<string, int>, halves: array<string, array{call: Closure, expect?: int, verify?: Closure}>}>
     */
    private function pairSpecs(): array
    {
        $pdo = $this->pdoConnection();

        return [
            'player notes (AddNote / EditNote / ClearNotes)' => [
                'key' => 'player.note.manage',
                'scope' => ['park_id' => self::PARK_ID],
                'halves' => [
                    'AddNote' => [
                        'call' => fn (string $t): array => (new Player())->AddNote([
                            'Token' => $t,
                            'MundaneId' => $this->subjectId,
                            'Note' => self::MARKER . ' added note',
                            'Description' => '',
                            'GivenBy' => '',
                            'Date' => '2026-01-01',
                            'DateComplete' => '',
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT COUNT(*) FROM ork_mundane_note WHERE mundane_id = ' . $this->subjectId
                            . " AND note = '" . self::MARKER . " added note'"
                        )->fetchColumn() === 1,
                    ],
                    'EditNote' => [
                        'call' => fn (string $t): array => (new Player())->EditNote([
                            'Token' => $t,
                            'MundaneId' => $this->subjectId,
                            'NotesId' => $this->noteId,
                            'Note' => self::MARKER . ' edited note',
                            'Description' => '',
                            'Date' => '2026-01-02',
                            'DateComplete' => '',
                        ]),
                        'verify' => fn (): bool => (string) $pdo->query(
                            'SELECT note FROM ork_mundane_note WHERE mundane_note_id = ' . $this->noteId
                        )->fetchColumn() === self::MARKER . ' edited note',
                    ],
                    'ClearNotes' => [
                        'call' => fn (string $t): array => (new Player())->ClearNotes([
                            'Token' => $t,
                            'MundaneId' => $this->subjectId,
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT COUNT(*) FROM ork_mundane_note WHERE mundane_id = ' . $this->subjectId
                        )->fetchColumn() === 0,
                    ],
                ],
            ],

            'awards (RevokeAward / ReactivateAward)' => [
                'key' => 'player.award.manage',
                'scope' => ['park_id' => self::PARK_ID],
                'needs_award_columns' => true,
                'halves' => [
                    'RevokeAward' => [
                        'call' => fn (string $t): array => (new Player())->RevokeAward([
                            'Token' => $t,
                            'AwardsId' => $this->awardId,
                            'Revocation' => self::MARKER . ' revoked',
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT revoked FROM ork_awards WHERE awards_id = ' . $this->awardId
                        )->fetchColumn() === 1,
                    ],
                    'ReactivateAward' => [
                        'call' => fn (string $t): array => (new Player())->ReactivateAward([
                            'Token' => $t,
                            'AwardsId' => $this->awardId,
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT revoked FROM ork_awards WHERE awards_id = ' . $this->awardId
                        )->fetchColumn() === 0,
                    ],
                ],
            ],

            'waivers (SetWaiver / ResetWaivers)' => [
                'key' => 'player.waiver.manage',
                'scope' => ['park_id' => self::PARK_ID],
                'halves' => [
                    'SetWaiver' => [
                        'call' => fn (string $t): array => (new Player())->SetWaiver([
                            'Token' => $t,
                            'MundaneId' => $this->subjectId,
                            'Waivered' => 1,
                            'Waiver' => '',
                            'WaiverMimeType' => '',
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT waivered FROM ork_mundane WHERE mundane_id = ' . $this->subjectId
                        )->fetchColumn() === 1,
                    ],
                    'ResetWaivers' => [
                        'call' => fn (string $t): array => (new Player())->ResetWaivers([
                            'Token' => $t,
                            'ParkId' => self::PARK_ID,
                            'KingdomId' => 0,
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT waivered FROM ork_mundane WHERE mundane_id = ' . $this->subjectId
                        )->fetchColumn() === 0,
                    ],
                ],
            ],

            'attendance links (Create / Get / Delete)' => [
                'key' => 'park.attendance.manage',
                'scope' => ['park_id' => self::PARK_ID],
                'halves' => [
                    'CreateAttendanceLink' => [
                        'call' => fn (string $t): array => (new Attendance())->CreateAttendanceLink([
                            'Token' => $t,
                            'ParkId' => self::PARK_ID,
                            'Credits' => 1,
                            'Hours' => 3,
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT COUNT(*) FROM ork_attendance_link WHERE park_id = ' . self::PARK_ID
                        )->fetchColumn() >= 2,
                    ],
                    'GetAttendanceLinks' => [
                        'call' => fn (string $t): array => (new Attendance())->GetAttendanceLinks([
                            'Token' => $t,
                            'ParkId' => self::PARK_ID,
                        ]),
                    ],
                    'DeleteAttendanceLink' => [
                        'call' => fn (string $t): array => (new Attendance())->DeleteAttendanceLink([
                            'Token' => $t,
                            'LinkId' => $this->linkId,
                        ]),
                        'verify' => fn (): bool => $pdo->query(
                            'SELECT revoked_at FROM ork_attendance_link WHERE link_id = ' . $this->linkId
                        )->fetchColumn() !== null,
                    ],
                ],
            ],

            'event heraldry (SetEventHeraldry / RemoveEventHeraldry)' => [
                'key' => 'event.heraldry.manage',
                'scope' => ['event_id' => self::EVENT_ID],
                'halves' => [
                    'RemoveEventHeraldry' => [
                        'call' => fn (string $t): array => (new Heraldry())->RemoveEventHeraldry([
                            'Token' => $t,
                            'EventId' => self::EVENT_ID,
                        ]),
                        'verify' => fn (): bool => (int) $pdo->query(
                            'SELECT has_heraldry FROM ork_event WHERE event_id = ' . self::EVENT_ID
                        )->fetchColumn() === 0,
                    ],
                    // SetEventHeraldry needs a real uploaded image to write anything, which
                    // is not what this test is about: the invariant here is that it does not
                    // refuse the same officer its counterpart accepts. Any status but
                    // NoAuthorization means the gate let them through.
                    'SetEventHeraldry' => [
                        'call' => fn (string $t): array => (new Heraldry())->SetEventHeraldry([
                            'Token' => $t,
                            'EventId' => self::EVENT_ID,
                            'Heraldry' => '',
                            'HeraldryMimeType' => '',
                        ]),
                        'expect' => -1,
                    ],
                ],
            ],
        ];
    }

    public function testAPermissionOnlyOfficerCanRunBothHalvesOfEveryConvertedPair(): void
    {
        foreach ($this->pairSpecs() as $pairName => $spec) {
            $this->skipIfAwardPairNotExercisable($spec);
            $this->tearDown();
            $this->seedWorld();
            $token = $this->permissionOnlyToken($spec['key'], $spec['scope']);

            // pairSpecs() closes over the ids seeded above, so rebuild it per pair.
            $halves = $this->pairSpecs()[$pairName]['halves'];
            foreach ($halves as $label => $half) {
                $r = ($half['call'])($token);
                $status = (int) ($r['Status'] ?? 5);

                if (($half['expect'] ?? 0) === -1) {
                    self::assertNotSame(
                        5,
                        $status,
                        "{$pairName}: {$label} refused an officer holding {$spec['key']}, while its "
                        . 'counterpart accepted them. That asymmetry is the whole defect class.'
                    );
                } else {
                    self::assertSame(
                        0,
                        $status,
                        "{$pairName}: {$label} must accept an officer holding {$spec['key']} through "
                        . 'a role and nothing else. Error: ' . (string) ($r['Error'] ?? '')
                    );
                }

                if (isset($half['verify'])) {
                    self::assertTrue(
                        ($half['verify'])(),
                        "{$pairName}: {$label} returned success but wrote nothing."
                    );
                }
            }
        }
    }

    public function testAStrangerIsRefusedByBothHalvesOfEveryConvertedPair(): void
    {
        foreach ($this->pairSpecs() as $pairName => $spec) {
            $this->skipIfAwardPairNotExercisable($spec);
            $this->tearDown();
            $this->seedWorld();
            $token = $this->strangerToken();

            $halves = $this->pairSpecs()[$pairName]['halves'];
            foreach ($halves as $label => $half) {
                $r = ($half['call'])($token);

                self::assertSame(
                    5,
                    (int) ($r['Status'] ?? 0),
                    "{$pairName}: {$label} must refuse a logged-in player holding no authority "
                    . 'anywhere. Converting a gate must not open it.'
                );
            }
        }
    }
}
