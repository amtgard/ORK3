# Officer Transition — Backend API Implementation Plan (Plan 1 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn every officer write into a token-gated, self-authorizing, audited `$request` domain method that any client can call, and give the rolls real data — with no user-visible change and no UI surface removed.

**Architecture:** `OfficerPosition` and `RBACService` currently take the acting user's identity as a *parameter* and rely on a controller for authorization, which is why neither can be exposed on the API. Every public mutator is converted to the shape `Player::AddAward` already uses: a `$request` array carrying a `Token`, `IsAuthorized` resolving the actor, `checkPermissionOrAuthority` gating with a scope-correct permission key, and `dangeraudit` recording before/after. Existing positional methods survive as `private` helpers, or — where another class calls them — under underscore names the dispatcher cannot request. Only then is `OfficerPosition` registered.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 11, yapo ORM, `orkservice/Json` dispatcher.

**Spec:** `docs/superpowers/specs/2026-08-29-officer-transition-design.md`

## Global Constraints

- **Domain code only.** Every file in this plan is under `system/lib/ork3/`, `orkservice/`, `db-migrations/`, or `tests/`. No `orkui/` controller or template is edited in Plan 1. Plan 2 owns the UI.
- **The existing UI must keep working after every task.** The edit pads, Manage Officers, and the legacy admin pages all call these methods today. Signature changes must be additive or fully re-pointed within the same task.
- **Response shape** is `orkservice/Common.definitions.php`: `Success()` → `['Status'=>0,'Error'=>…,'Detail'=>…]`, `InvalidParameter($detail,$error)` → `Status 4`, `NoAuthorization($detail,$error)` → `Status 5`, `ProcessingError()` → `Status 3`. Status **0 is success**. Never `Errors::Message` (that is a different, boolean-Status helper).
- **`$DB->Clear()` before every raw `Execute`/`DataSet`.** Stale PDO bindings cause silent write failures.
- **`$DB->DataSet()` needs a manual `->Next()`** before reading fields.
- **yapo drops `null`** from UPDATE/INSERT. To write a real SQL `NULL`, emit the literal `NULL` in the statement — never bind a PHP null. `InsertOfficerRow` at `class.OfficerPosition.php:1826` is the pattern to copy.
- **`mysql_real_escape_string()` is a no-op shim.** Cast ids with `(int)` or bind them.
- **PSR-12, normalize-first.** Before editing a PHP file run `awk '/^\t/{c++}END{print c+0}' <file>`. Non-zero means it has leading tabs: run `vendor/bin/php-cs-fixer fix <file>` first, commit that separately, then make your change.
- **`IsAuthorized` must run before `dangeraudit->audit`.** `DangerAudit::audit` takes no actor — it reads `$_SESSION['is_authorized_mundane_id']` (`class.DangerAudit.php:65`), populated as a side effect of `IsAuthorized` (`class.Authorization.php:982`).
- **Test DB:** sandbox `ork_test` on `localhost:19307`. Guard every test with `ork3_test_db_available()`. Seed with raw PDO, prefix everything with a MARKER constant, clean up in `tearDown()` — never inline after an assertion, because inline cleanup does not run on the failure path.
- **Run one test:** `vendor/bin/phpunit --no-coverage --filter <testMethod> tests/Integration/<File>.php`
- **Run the suite (sign-off):** `bin/run-unit-tests.sh`
- **Hiding a method from the JSON dispatcher.** Renaming it lowercase-initial does
  **not** work — PHP method names are case-insensitive and the caller picks the casing, so
  a request for `LowerStart` reaches `lowerStart` (verified). Two levers do work: an
  **underscore** in the name (the request string must contain `_` to match, and
  `validate_method` rejects that), and **`private`** visibility (invoking from outside
  throws). Use `private` when nothing outside the class calls it; use underscore naming
  when another class does.
- **New `db-migrations/` file must be classified** in `tools/ork-db/manifests/migration-classification.json5` or `drift-check --strict` blocks the whole suite.

---

## File Structure

| File | Responsibility |
|---|---|
| `system/lib/ork3/class.OfficerPosition.php` | Officer registry + occupancy + history writes. Gains the `$request` API layer; keeps its logic as internal helpers. |
| `system/lib/ork3/class.RBACService.php` | Role and permission writes. Gains token gating on 5 user-facing mutators; 3 `Sync*` methods renamed internal. |
| `system/lib/ork3/common.php` | `Common::set_officer` gains `$skip_history` so history is written exactly once per transition. |
| `orkservice/Json/index.php` | Whitelist. Gains `'OfficerPosition'` only. |
| `db-migrations/2026-08-29-officer-history-backfill.sql` | Opens a term for every seated officer; reopens the one future-dated term. |
| `tools/ork-db/manifests/migration-classification.json5` | Classifies the above so `drift-check --strict` passes. |
| `tests/Integration/OfficerTransitionTest.php` | The transition itself: dates, note, single history write, audit. |
| `tests/Integration/OfficerAuthorizationTest.php` | Token gating, scope-correct permission keys, park actors. |
| `tests/Integration/OfficerOccupancyTest.php` | One-seat, no office limits, retire-clears-all-scopes. |
| `tests/Integration/OfficerHistoryBackfillTest.php` | Migration correctness and idempotency. |
| `tests/Unit/ApiExposureTest.php` | Every public PascalCase method on a registered class is gated or reviewed-public. |

---

## Task 1: Scope-correct permission keys

The spec's permission matrix. `controller.OfficerAdminAjax.php:70-84` maps every action to `kingdom.officer.set`, but `kingdom.officer.vacate`, `kingdom.officer_history.manage` and a full `park.*` mirror already exist in `class.PermissionRegistry.php:56-72,139-157`. `park.officer.position.manage` is defined and referenced by nothing at all.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php` (add a static helper near `DisplayTitleSql`)
- Test: `tests/Unit/OfficerPermissionKeyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `OfficerPosition::PermissionKeyFor(string $action, int $park_id): string` where `$action` is one of `set|vacate|position|history`. Every later task calls this.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerPermissionKeyTest extends TestCase
{
    public function testKingdomScopeUsesKingdomKeys(): void
    {
        self::assertSame('kingdom.officer.set', OfficerPosition::PermissionKeyFor('set', 0));
        self::assertSame('kingdom.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 0));
        self::assertSame('kingdom.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 0));
        self::assertSame('kingdom.officer_history.manage', OfficerPosition::PermissionKeyFor('history', 0));
    }

    public function testParkScopeUsesParkKeys(): void
    {
        self::assertSame('park.officer.set', OfficerPosition::PermissionKeyFor('set', 42));
        self::assertSame('park.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 42));
        self::assertSame('park.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 42));
        self::assertSame('park.officer_history.manage', OfficerPosition::PermissionKeyFor('history', 42));
    }

    public function testEveryKeyExistsInTheRegistry(): void
    {
        foreach (['set', 'vacate', 'position', 'history'] as $action) {
            foreach ([0, 42] as $parkId) {
                $key = OfficerPosition::PermissionKeyFor($action, $parkId);
                self::assertTrue(
                    PermissionRegistry::Exists($key),
                    "Permission key {$key} is not defined in PermissionRegistry"
                );
            }
        }
    }

    public function testUnknownActionThrows(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OfficerPosition::PermissionKeyFor('nonsense', 0);
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/OfficerPermissionKeyTest.php`
Expected: FAIL — `Call to undefined method OfficerPosition::PermissionKeyFor()`.

If `PermissionRegistry::Exists()` does not exist, use `array_key_exists($key, PermissionRegistry::GetAll())` instead — check the class first and adjust the test, not the production code.

- [ ] **Step 3: Implement**

Add to `class.OfficerPosition.php`, immediately after the `DisplayTitleSql` method:

```php
    /**
     * The permission key for an officer action in a given scope.
     *
     * The key is scoped as well as the scope argument. checkPermissionOrAuthority()
     * maps 'park' to AUTH_PARK, so passing scope='park' with a kingdom.* key looks
     * up a kingdom permission against a park id and simply fails. PermissionRegistry
     * has defined a full park mirror since this branch began; nothing used it.
     *
     * @param string $action one of set|vacate|position|history
     * @param int    $park_id 0 for kingdom scope, otherwise park scope
     */
    public static function PermissionKeyFor($action, $park_id)
    {
        $prefix = ((int) $park_id > 0) ? 'park' : 'kingdom';
        $map = [
            'set'      => '.officer.set',
            'vacate'   => '.officer.vacate',
            'position' => '.officer.position.manage',
            'history'  => '.officer_history.manage',
        ];
        if (!isset($map[$action])) {
            throw new InvalidArgumentException('Unknown officer action: ' . $action);
        }
        return $prefix . $map[$action];
    }
```

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/OfficerPermissionKeyTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add tests/Unit/OfficerPermissionKeyTest.php system/lib/ork3/class.OfficerPosition.php
git commit -m "Enhancement: scope-correct permission keys for officer actions

PermissionRegistry has defined park.officer.set, park.officer.vacate and
park.officer_history.manage since this branch began, and nothing has ever
used them. park.officer.position.manage is referenced by no code at all.
PermissionKeyFor() picks the right key for the scope so a park actor is
checked against a park permission."
```

---

## Task 2: Remove the crown-uniqueness check and re-key the advisory lock

`SetOfficerByPosition:1420-1447` refuses any person already holding a crown office anywhere. All five system positions are seeded `classification='crown'` and 705 parks reuse those shared rows, so against production data this refuses **242 people who currently hold office**. The ORK imposes no limit on holding multiple offices. Delete the check; write no replacement.

The `GET_LOCK('crown_assign_' . $mundane_id)` around it is keyed on the *person* solely to serialise that query. With the query gone it guards nothing that matters — two admins assigning *different* people to the *same* office are not serialised, which is the race a transition actually has.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php:1409-1467`
- Test: `tests/Integration/OfficerOccupancyTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `SetOfficerByPosition` no longer returns a crown-conflict rejection. The lock name becomes `officer_assign_{kingdom_id}_{park_id}_{position_id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerOccupancyTest extends TestCase
{
    private const MARKER = 'zzoccupancy';
    private const KINGDOM_ID = 100011;
    private const PARK_A = 100012;
    private const PARK_B = 100013;

    private PDO $pdo;
    private OfficerPosition $positions;
    /** @var array<string,int> */
    private array $seededPositions = [];
    /** @var list<int> */
    private array $seededMundanes = [];

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $this->pdo = ork3_test_pdo();
        $this->positions = new OfficerPosition();
        $this->seededPositions['crown_a'] = $this->seedPosition('crown_a', 'crown');
        $this->seededPositions['crown_b'] = $this->seedPosition('crown_b', 'crown');
        $this->seededMundanes[] = $this->seedMundane('holder');
    }

    protected function tearDown(): void
    {
        if (!isset($this->pdo)) {
            return;
        }
        $this->pdo->exec("DELETE FROM ork_officer_history WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer WHERE role LIKE '" . self::MARKER . "%'");
        $this->pdo->exec("DELETE FROM ork_officer_position WHERE canonical_key LIKE '" . self::MARKER . "%'");
        if ($this->seededMundanes) {
            $this->pdo->exec('DELETE FROM ork_mundane WHERE mundane_id IN (' . implode(',', $this->seededMundanes) . ')');
        }
    }

    /**
     * The rule that was removed. A person holding a crown office in one park must be
     * appointable to a crown office elsewhere -- 242 people in production do exactly
     * this, 176 of them twice in the same park.
     */
    public function testAPersonMayHoldTwoCrownOfficesInDifferentScopes(): void
    {
        $mundaneId = $this->seededMundanes[0];

        $first = $this->positions->SetOfficerByPosition(
            self::KINGDOM_ID, self::PARK_A, $this->seededPositions['crown_a'],
            $mundaneId, '', '', '', 0
        );
        self::assertSame(0, (int) $first['Status'], 'first appointment should succeed');

        $second = $this->positions->SetOfficerByPosition(
            self::KINGDOM_ID, self::PARK_B, $this->seededPositions['crown_a'],
            $mundaneId, '', '', '', 0
        );
        self::assertSame(0, (int) $second['Status'],
            'a second crown office in another park must be allowed: ' . ($second['Error'] ?? ''));
    }

    public function testAPersonMayHoldTwoCrownOfficesInTheSameScope(): void
    {
        $mundaneId = $this->seededMundanes[0];

        $this->positions->SetOfficerByPosition(
            self::KINGDOM_ID, self::PARK_A, $this->seededPositions['crown_a'],
            $mundaneId, '', '', '', 0
        );
        $second = $this->positions->SetOfficerByPosition(
            self::KINGDOM_ID, self::PARK_A, $this->seededPositions['crown_b'],
            $mundaneId, '', '', '', 0
        );
        self::assertSame(0, (int) $second['Status'],
            'two offices in one park must be allowed: ' . ($second['Error'] ?? ''));
    }

    private function seedPosition(string $suffix, string $classification): int
    {
        $key = self::MARKER . '_' . $suffix;
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_officer_position
                (kingdom_id, canonical_key, title, title_alias, classification,
                 is_pinned, is_system, rbac_role_id, has_auth_role, sort_order,
                 parent_position_id, hide_when_vacant, retired_at, created_by, created_at)
             VALUES (:kid, :key, :title, "", :cls, 0, 0, 0, 0, 100, NULL, 0, NULL, 0, NOW())'
        );
        $stmt->execute([
            ':kid' => self::KINGDOM_ID, ':key' => $key,
            ':title' => 'Test ' . $suffix, ':cls' => $classification,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seedMundane(string $suffix): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        );
        $stmt->execute([
            ':g' => self::MARKER, ':u' => $username,
            ':p' => self::MARKER . ' ' . $suffix, ':e' => $username . '@example.test',
            ':kid' => self::KINGDOM_ID,
        ]);
        return (int) $this->pdo->lastInsertId();
    }
}
```

If `ork3_test_pdo()` does not exist in `tests/bootstrap.php`, construct the PDO the way `OfficerPositionReinstateTest::setUp` does and use that instead.

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerOccupancyTest.php`
Expected: both tests FAIL with `Status 4` and the message *"This person already holds a Crown office…"*. That failure message is the bug being fixed — confirm you see it before proceeding.

- [ ] **Step 3: Delete the check and re-key the lock**

In `class.OfficerPosition.php`, inside `SetOfficerByPosition`:

Replace the lock name:

```php
        // Serialize on the OFFICE being written, not the person. The old key
        // ('crown_assign_' . $mundane_id) existed only to serialize a cross-scope
        // uniqueness query that no longer exists; it never guarded the race a
        // transition actually has, which is two admins writing the same seat.
        $lock_name = 'officer_assign_' . $kingdom_id . '_' . $park_id . '_' . $position_id;
```

Then delete the entire conflict block — from the comment `// Crown-per-person global check across kingdom + park scopes.` through the closing brace of the `if ($conflict !== false …)` statement, including the `$DB->Clear(); $DB->cp_mid = …` setup and the `$DB->DataSet("SELECT o.kingdom_id, …")` query. Leave `EnsureCrownSlot`, the `set_officer` call, the `finally` block, and the `RELEASE_LOCK` exactly as they are.

Update the method's docblock to say occupancy is per-seat and the ORK imposes no limit on how many offices a person holds.

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerOccupancyTest.php`
Expected: PASS, 2 tests.

Then confirm nothing else depended on the refusal:
Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerPositionReorderTest.php tests/Integration/OfficerPositionReinstateTest.php tests/Integration/OfficerRowShapeTest.php`
Expected: PASS. If one asserts the crown-conflict message, delete that assertion — it encodes the removed rule.

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/OfficerOccupancyTest.php system/lib/ork3/class.OfficerPosition.php
git commit -m "Bugfix: the one-Crown-office rule refused 242 sitting officers

All five system positions are seeded classification='crown' and 705 parks
reuse those shared rows, so the check treated a park Champion exactly like a
kingdom Monarch. Against production data it refuses 242 people who hold
office right now, 176 of them holding two offices in one park. The ORK
imposes no limit on holding multiple offices, so the check is deleted rather
than narrowed.

Its advisory lock was keyed on the person purely to serialize that query, and
never covered the race a transition actually has. Re-keyed to the office."
```

---

## Task 3: `Common::set_officer` gains `$skip_history`

`Kingdom::SetOfficer` → `Common::set_officer` → `record_officer_history` writes a history term on its own (`common.php:946`). The transition method in Task 5 writes its own term with caller-supplied dates and a note, which `record_officer_history` cannot express. Without a suppression flag every transition would produce two rows and close the outgoing term twice.

**Files:**
- Modify: `system/lib/ork3/common.php:842` (signature) and `:922` (the history call site)
- Test: `tests/Integration/OfficerTransitionTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `Common::set_officer($kingdom_id, $park_id, $new_officer_id, $role, $system = 0, $changed_by = 0, $position_id = 0, $display_label = '', $skip_history = false)`. The flag defaults false, so all four existing callers are unchanged.

- [ ] **Step 1: Write the failing test**

Create `tests/Integration/OfficerTransitionTest.php` with the same MARKER/seed/tearDown scaffolding as Task 1's test (copy `seedPosition`, `seedMundane`, `setUp`, `tearDown` verbatim; MARKER `zztransition`, kingdom `100021`), plus:

```php
    public function testSkipHistorySuppressesTheLegacyHistoryWrite(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID, 0, $mundaneId, self::MARKER . '_crown_a',
            0, 0, $positionId, 'Test crown_a', true   // $skip_history
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'skip_history must suppress the term write entirely');
    }

    public function testDefaultStillWritesHistorySoExistingCallersAreUnchanged(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $mundaneId  = $this->seededMundanes[0];

        // Seed the vacant slot set_officer's find() requires.
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, 0, :role, 0, 0, :pid, NOW())'
        )->execute([':kid' => self::KINGDOM_ID, ':role' => self::MARKER . '_crown_b', ':pid' => $positionId]);

        $common = new Common();
        $common->set_officer(
            self::KINGDOM_ID, 0, $mundaneId, self::MARKER . '_crown_b',
            0, 0, $positionId, 'Test crown_b'        // flag omitted
        );

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(),
            'omitting the flag must behave exactly as before');
    }
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter SkipHistory tests/Integration/OfficerTransitionTest.php`
Expected: FAIL — the ninth argument is ignored, so a history row is written and the count is 1, not 0.

- [ ] **Step 3: Implement**

`common.php:842`, change the signature:

```php
    public function set_officer($kingdom_id, $park_id, $new_officer_id, $role, $system = 0, $changed_by = 0, $position_id = 0, $display_label = '', $skip_history = false)
```

`common.php:922`, guard the history call. It currently reads:

```php
            if ($officer_changed && (int)$old_mundane_id !== (int)$new_officer_id) {
                $this->record_officer_history($kingdom_id, $park_id, $old_mundane_id, $new_officer_id, $role, $changed_by, $position_id, $display_label);
```

becomes:

```php
            if ($officer_changed && (int)$old_mundane_id !== (int)$new_officer_id) {
                // OfficerPosition::TransitionOfficer writes its own term, with the
                // caller's dates and note -- things record_officer_history cannot
                // express. Exactly one function writes ork_officer_history per
                // transition; the flag is how the newer path claims that job.
                if (!$skip_history) {
                    $this->record_officer_history($kingdom_id, $park_id, $old_mundane_id, $new_officer_id, $role, $changed_by, $position_id, $display_label);
                }
```

Leave the RBAC sync block that follows inside the same `if` — role sync must still happen when history is skipped.

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerTransitionTest.php`
Expected: PASS, 2 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/common.php tests/Integration/OfficerTransitionTest.php
git commit -m "Enhancement: set_officer can yield the history write to its caller

record_officer_history stamps today and cannot carry a note, so the transition
path has to write its own term. Without a suppression flag both would write
and every transition would produce two rows. Defaults false; all four existing
callers are unchanged."
```

---

## Task 4: `TransitionOfficer` — the token-gated write

The core of the feature. Closes the outgoing term at a caller-supplied date, opens the incoming one, persists the note, and audits — all behind a token.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php`
- Test: `tests/Integration/OfficerTransitionTest.php` (extend Task 3's file)

**Interfaces:**
- Consumes: `OfficerPosition::PermissionKeyFor()` (Task 1); `Common::set_officer(..., $skip_history)` (Task 3).
- Produces: `OfficerPosition::TransitionOfficer($request): array`. `$request` keys: `Token` (string, required), `KingdomId` (int, required), `ParkId` (int, 0 for kingdom scope), `PositionId` (int, required), `MundaneId` (int, required — the incoming officer), `OutgoingEndDate` (`Y-m-d`, optional — defaults to today), `OutgoingStartDate` (`Y-m-d`, optional — fills a NULL start on the outgoing term, never overwrites a set one), `TermStart` (`Y-m-d`, optional — defaults to `OutgoingEndDate`), `Note` (string, optional, ≤500 chars). Returns the standard response array. Rejects an incoming officer who is not a member of the kingdom, matching `Kingdom::SetOfficer:1348`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Integration/OfficerTransitionTest.php`. Add `use` of the fixture and a `private AuthorizedOfficerFixture $fixture;` created in `setUp` with marker `self::MARKER` and `self::KINGDOM_ID`.

```php
    public function testRejectsAnAbsentToken(): void
    {
        $r = $this->positions->TransitionOfficer([
            'Token' => '', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status'], 'an absent token must be NoAuthorization');
    }

    public function testBackdatedEndDateIsHonouredForACrownOffice(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $outgoing   = $this->seededMundanes[0];
        $incoming   = $this->seedMundane('incoming');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();

        // Seat the outgoing officer with an open term starting well in the past.
        $this->seatWithOpenTerm($positionId, $outgoing, '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
            'OutgoingEndDate' => '2026-08-15',
            'TermStart' => '2026-08-15',
            'Note' => 'Reign 42',
        ]);
        self::assertSame(0, (int) $r['Status'], $r['Error'] ?? '');

        $stmt = $this->pdo->prepare(
            'SELECT mundane_id, start_date, end_date, notes FROM ork_officer_history
             WHERE position_id = :pid ORDER BY officer_history_id'
        );
        $stmt->execute([':pid' => $positionId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        self::assertCount(2, $rows, 'exactly one term closed and one opened');
        self::assertSame($outgoing, (int) $rows[0]['mundane_id']);
        self::assertSame('2026-08-15', $rows[0]['end_date'], 'the backdated end date must be stored, not today');
        self::assertSame($incoming, (int) $rows[1]['mundane_id']);
        self::assertSame('2026-08-15', $rows[1]['start_date']);
        self::assertNull($rows[1]['end_date'], 'the incoming term must be open');
        self::assertSame('Reign 42', $rows[1]['notes'], 'the note must persist');
    }

    public function testRejectsAFutureEndDate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => date('Y-m-d', strtotime('+30 days')),
        ]);
        self::assertSame(4, (int) $r['Status'],
            'a future end date is what made a sitting officer read as departed');
    }

    public function testRejectsAnEndDateBeforeTheTermStart(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $this->seededMundanes[0],
            'OutgoingEndDate' => '2026-01-01',
        ]);
        self::assertSame(4, (int) $r['Status']);
    }

    public function testAuditRowIsAttributedToTheTokenOwnerNotZero(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $incoming   = $this->seedMundane('audited');
        $this->seededMundanes[] = $incoming;
        $token      = $this->fixture->createAuthorizedOfficer();
        $actorId    = $this->fixture->officerMundaneId();
        $this->seatWithOpenTerm($positionId, $this->seededMundanes[0], '2026-03-02');

        $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $incoming,
        ]);

        $stmt = $this->pdo->prepare(
            "SELECT by_whom_id FROM ork_dangeraudit
             WHERE method_call = 'OfficerPosition::TransitionOfficer'
             ORDER BY dangeraudit_id DESC LIMIT 1"
        );
        $stmt->execute();
        self::assertSame($actorId, (int) $stmt->fetchColumn(),
            'DangerAudit reads $_SESSION[is_authorized_mundane_id]; IsAuthorized must run first');
    }

    public function testTheIncomingOfficerMustBelongToTheKingdom(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $token      = $this->fixture->createAuthorizedOfficer();
        $outsider   = $this->seedMundaneInKingdom('outsider', 999999);
        $this->seededMundanes[] = $outsider;

        $r = $this->positions->TransitionOfficer([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $outsider,
        ]);
        self::assertSame(4, (int) $r['Status'],
            'matches the rule the legacy path has always applied (Kingdom::SetOfficer:1348)');
    }

    /** seedMundane(), but in an arbitrary kingdom. */
    private function seedMundaneInKingdom(string $suffix, int $kingdomId): int
    {
        $username = self::MARKER . '_' . $suffix . '_' . bin2hex(random_bytes(4));
        $stmt = $this->pdo->prepare(
            'INSERT INTO ork_mundane
                (given_name, surname, other_name, username, persona, email, park_id, kingdom_id,
                 token, waiver_ext, password_expires, password_salt, xtoken, reeve_qualified_until)
             VALUES (:g, "Test", "", :u, :p, :e, 0, :kid, "", "", "0000-00-00", "", "", "0000-00-00")'
        );
        $stmt->execute([
            ':g' => self::MARKER, ':u' => $username, ':p' => self::MARKER . ' ' . $suffix,
            ':e' => $username . '@example.test', ':kid' => $kingdomId,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    private function seatWithOpenTerm(int $positionId, int $mundaneId, string $start): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pid, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
        ]);
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                                              position_id, display_label, start_date,
                                              end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, :label, :start, NULL, NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId,
            ':label' => 'Test crown_a', ':start' => $start,
        ]);
    }
```

Confirm `AuthorizedOfficerFixture` exposes the actor's mundane id. If it does not, add:

```php
    public function officerMundaneId(): int
    {
        $this->createAuthorizedOfficer();
        return (int) $this->officerMundaneId;
    }
```

Also confirm the audit table name with `SHOW TABLES LIKE 'ork_%audit%'` and adjust the column/table names in the audit test to match `class.DangerAudit.php`'s yapo table.

- [ ] **Step 2: Run and confirm they fail**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerTransitionTest.php`
Expected: FAIL — `Call to undefined method OfficerPosition::TransitionOfficer()`.

- [ ] **Step 3: Implement**

Add to `class.OfficerPosition.php`, immediately before `SetOfficerByPosition`:

```php
    /**
     * Move an office from one holder to another as a single recorded transition.
     *
     * This is the API shape every other write in the application uses
     * (compare Player::AddAward): a $request array carrying a Token, the actor
     * resolved from that token rather than asserted by the caller, the permission
     * gate inside the domain, and a dangeraudit row. It is the only officer write
     * that can express a backdated end date or a note, because Common::set_officer's
     * record_officer_history stamps today and has nowhere to put one.
     *
     * ork_officer is MyISAM -- no transactions -- so the order is fixed:
     * close the outgoing term, then move the seat, then open the incoming term.
     * A failure part-way leaves a closed term and a visibly vacant office, never a
     * seated officer with no term.
     */
    public function TransitionOfficer($request)
    {
        global $DB;

        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }

        $kingdom_id  = (int) ($request['KingdomId'] ?? 0);
        $park_id     = (int) ($request['ParkId'] ?? 0);
        $position_id = (int) ($request['PositionId'] ?? 0);
        $incoming_id = (int) ($request['MundaneId'] ?? 0);

        $scope     = ($park_id > 0) ? 'park' : 'kingdom';
        $scope_id  = ($park_id > 0) ? $park_id : $kingdom_id;
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id,
            self::PermissionKeyFor('set', $park_id),
            $scope,
            $scope_id,
            AUTH_EDIT
        )) {
            return NoAuthorization();
        }

        if (!valid_id($position_id)) {
            return InvalidParameter(null, 'A valid position is required.');
        }
        if (!valid_id($incoming_id)) {
            return InvalidParameter(null, 'A valid member is required.');
        }

        $position = $this->GetPosition($position_id, $kingdom_id);
        if ($position === false) {
            return InvalidParameter(null, 'Position not found.');
        }
        if ((int) $position['KingdomId'] !== 0 && $kingdom_id > 0
            && (int) $position['KingdomId'] !== $kingdom_id) {
            return NoAuthorization(null, 'Position does not belong to this kingdom.');
        }
        if ($position['RetiredAt'] !== null) {
            return InvalidParameter(null, 'Cannot assign an occupant to a retired position.');
        }

        $today   = date('Y-m-d');
        $end     = $this->normalizeDate($request['OutgoingEndDate'] ?? '', $today);
        $start   = $this->normalizeDate($request['TermStart'] ?? '', $end);
        $backfill_start = $this->normalizeDate($request['OutgoingStartDate'] ?? '', '');
        $note    = substr(trim((string) ($request['Note'] ?? '')), 0, 500);

        if ($end === false || $start === false || $backfill_start === false) {
            return InvalidParameter(null, 'Dates must be in YYYY-MM-DD form.');
        }
        if ($end > $today) {
            return InvalidParameter(null, 'A term cannot end in the future.');
        }
        if ($start < $end) {
            return InvalidParameter(null, 'The incoming term cannot start before the outgoing term ends.');
        }

        // The incoming officer must belong to the org, matching the rule the legacy
        // path has always applied (Kingdom::SetOfficer, class.Kingdom.php:1348).
        $incoming = Ork3::$Lib->player->player_info($incoming_id);
        if (!is_array($incoming) || (int) ($incoming['KingdomId'] ?? 0) !== $kingdom_id) {
            return InvalidParameter(null, 'The new officer must be a member of this kingdom.');
        }

        $outgoing = $this->currentHolder($kingdom_id, $park_id, $position_id);
        if ($outgoing > 0) {
            $open_start = $this->openTermStart($kingdom_id, $park_id, $position_id, $outgoing);
            if ($open_start !== null && $open_start !== '' && $end < $open_start) {
                return InvalidParameter(null, 'A term cannot end before it began.');
            }
            $this->closeTermAt($kingdom_id, $park_id, $position_id, $outgoing, $end, $backfill_start);
        }

        // Move the seat, suppressing set_officer's own history write -- this method
        // owns the term, with dates and a note that record_officer_history cannot carry.
        $c = new Common();
        $this->EnsureCrownSlot($kingdom_id, $park_id, $position_id, $position['CanonicalKey']);
        $c->set_officer(
            $kingdom_id, $park_id, $incoming_id, $position['CanonicalKey'],
            0, $actor_id, $position_id, $position['DisplayTitle'], true
        );

        $this->openTerm(
            $kingdom_id, $park_id, $position_id, $position['CanonicalKey'],
            $incoming_id, $actor_id, $start, $note, $position['DisplayTitle']
        );

        $safe = $request;
        unset($safe['Token']);
        Ork3::$Lib->dangeraudit->audit(
            __CLASS__ . '::' . __FUNCTION__,
            $safe,
            $scope,
            $scope_id,
            ['MundaneId' => $outgoing, 'PositionId' => $position_id, 'EndDate' => $end],
            ['MundaneId' => $incoming_id, 'PositionId' => $position_id, 'StartDate' => $start]
        );

        return Success();
    }

    /** '' -> $default; a valid Y-m-d -> itself; anything else -> false. */
    private function normalizeDate($value, $default)
    {
        $value = trim((string) $value);
        if ($value === '') {
            return $default;
        }
        $d = DateTime::createFromFormat('Y-m-d', $value);
        if ($d === false || $d->format('Y-m-d') !== $value) {
            return false;
        }
        return $value;
    }

    private function currentHolder($kingdom_id, $park_id, $position_id)
    {
        global $DB;
        $DB->Clear();
        $DB->ch_kid = (int) $kingdom_id;
        $DB->ch_pid = (int) $park_id;
        $DB->ch_pos = (int) $position_id;
        $r = $DB->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'officer
             WHERE kingdom_id = :ch_kid AND park_id = :ch_pid
               AND position_id = :ch_pos AND mundane_id > 0 LIMIT 1'
        );
        return ($r !== false && $r->size() > 0 && $r->Next()) ? (int) $r->mundane_id : 0;
    }

    private function openTermStart($kingdom_id, $park_id, $position_id, $mundane_id)
    {
        global $DB;
        $DB->Clear();
        $DB->os_kid = (int) $kingdom_id;
        $DB->os_pid = (int) $park_id;
        $DB->os_pos = (int) $position_id;
        $DB->os_mid = (int) $mundane_id;
        $r = $DB->DataSet(
            'SELECT start_date FROM ' . DB_PREFIX . 'officer_history
             WHERE kingdom_id = :os_kid AND park_id = :os_pid AND position_id = :os_pos
               AND mundane_id = :os_mid AND end_date IS NULL
             ORDER BY officer_history_id DESC LIMIT 1'
        );
        return ($r !== false && $r->size() > 0 && $r->Next()) ? $r->start_date : null;
    }

    /**
     * Close the open term at $end. $backfill_start fills a NULL start_date only --
     * a start date already on the row is never overwritten by a transition.
     */
    private function closeTermAt($kingdom_id, $park_id, $position_id, $mundane_id, $end, $backfill_start)
    {
        global $DB;
        $DB->Clear();
        $DB->ct_end = $end;
        $DB->ct_kid = (int) $kingdom_id;
        $DB->ct_pid = (int) $park_id;
        $DB->ct_pos = (int) $position_id;
        $DB->ct_mid = (int) $mundane_id;
        $start_sql = '';
        if ($backfill_start !== '') {
            $DB->ct_start = $backfill_start;
            $start_sql = ', start_date = IF(start_date IS NULL, :ct_start, start_date)';
        }
        $DB->Execute(
            'UPDATE ' . DB_PREFIX . 'officer_history
             SET end_date = :ct_end' . $start_sql . '
             WHERE kingdom_id = :ct_kid AND park_id = :ct_pid
               AND position_id = :ct_pos AND mundane_id = :ct_mid
               AND end_date IS NULL'
        );
    }

    private function openTerm($kingdom_id, $park_id, $position_id, $canonical_key, $mundane_id, $actor_id, $start, $note, $display_label)
    {
        global $DB;
        $DB->Clear();
        $DB->ot_kid = (int) $kingdom_id;
        $DB->ot_pid = (int) $park_id;
        $DB->ot_mid = (int) $mundane_id;
        $DB->ot_role = $canonical_key;
        $DB->ot_pos = (int) $position_id;
        $DB->ot_label = ($display_label !== '') ? $display_label : $canonical_key;
        $DB->ot_start = $start;
        $DB->ot_cb = ($actor_id > 0 ? $actor_id : null);
        $DB->ot_notes = $note;
        // end_date is a SQL literal NULL: yapo drops a bound PHP null, which would
        // leave the column at its default and make the new officer read as departed.
        $DB->Execute(
            'INSERT INTO ' . DB_PREFIX . 'officer_history
             (kingdom_id, park_id, mundane_id, role, position_id, display_label,
              start_date, end_date, changed_by, notes, created_at)
             VALUES (:ot_kid, :ot_pid, :ot_mid, :ot_role, :ot_pos, :ot_label,
                     :ot_start, NULL, :ot_cb, :ot_notes, NOW())'
        );
    }
```

- [ ] **Step 4: Run and confirm they pass**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerTransitionTest.php`
Expected: PASS, 8 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php tests/Integration/OfficerTransitionTest.php tests/Support/AuthorizedOfficerFixture.php
git commit -m "Enhancement: TransitionOfficer, the first token-gated officer write

Closes the outgoing term at a caller-supplied date, opens the incoming one,
and finally persists the note the admin console has been collecting and
discarding. The actor comes from the token rather than from a \$changed_by
argument, the permission key is scoped with the scope, and the change is
audited -- coverage the newer officer path had lost relative to
Kingdom::SetOfficer."
```

---

## Task 5: Convert `SetOfficerByPosition` and `VacateOfficerByPosition`

Both still take positional arguments and trust a caller-supplied `$changed_by`. Convert to `$request`, keep the old signatures as `private` helpers so Task 10 can register the class safely.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php`
- Modify: `orkui/controller/controller.OfficerAdminAjax.php` — the two call sites only, so the console keeps working. **This is the one `orkui/` exception in Plan 1**; it is a call-site update, not a UI change.
- Test: `tests/Integration/OfficerAuthorizationTest.php`

**Interfaces:**
- Consumes: `PermissionKeyFor()` (Task 1).
- Produces:
  - `OfficerPosition::SetOccupant($request): array` — keys `Token, KingdomId, ParkId, PositionId, MundaneId, TermStart, TermEnd, Note`.
  - `OfficerPosition::VacateOfficer($request): array` — keys `Token, KingdomId, ParkId, PositionId, MundaneId`. Gated on `PermissionKeyFor('vacate', …)`.
  - `OfficerPosition::setOfficerByPosition(...)` and `vacateOfficerByPosition(...)` — the former public methods, now **`private`**, unchanged behaviour. Nothing outside the class calls them, so `private` is the right lever; renaming them lowercase-initial would NOT hide them from the dispatcher.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerAuthorizationTest extends TestCase
{
    private const MARKER = 'zzofficerauth';
    private const KINGDOM_ID = 100031;

    // setUp / tearDown / seedPosition / seedMundane: copy verbatim from
    // OfficerOccupancyTest (Task 2), changing only MARKER and KINGDOM_ID.

    public function testSetOccupantRejectsAnInvalidToken(): void
    {
        $r = $this->positions->SetOccupant([
            'Token' => 'not-a-real-token', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testVacateRejectsAnInvalidToken(): void
    {
        $r = $this->positions->VacateOfficer([
            'Token' => '', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $this->seededPositions['crown_a'],
            'MundaneId' => $this->seededMundanes[0],
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testVacateChecksTheVacatePermissionNotTheSetPermission(): void
    {
        // Documents the gate the console never applied: OfficerAdminAjax mapped
        // every vacate action to kingdom.officer.set, so kingdom.officer.vacate
        // existed and was checked by nothing.
        self::assertSame('kingdom.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 0));
        self::assertSame('park.officer.vacate', OfficerPosition::PermissionKeyFor('vacate', 7));
    }

    public function testTheOldEntryPointsAreNoLongerPublic(): void
    {
        // Visibility is the lever, NOT casing. method_exists() is case-insensitive and
        // returns true for private methods, so reflect on visibility explicitly.
        $r = new ReflectionClass('OfficerPosition');
        self::assertTrue($r->getMethod('setOfficerByPosition')->isPrivate(),
            'a public lowercase-initial method is still reachable as ?call=.../SetOfficerByPosition');
        self::assertTrue($r->getMethod('vacateOfficerByPosition')->isPrivate());
        self::assertTrue($r->getMethod('SetOccupant')->isPublic());
        self::assertTrue($r->getMethod('VacateOfficer')->isPublic());
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAuthorizationTest.php`
Expected: FAIL — `SetOccupant()` undefined.

- [ ] **Step 3: Implement**

1. Change `public function SetOfficerByPosition` → `private function setOfficerByPosition` and `public function VacateOfficerByPosition` → `private function vacateOfficerByPosition`. Bodies unchanged. **`private` is doing the work here, not the lowercase letter** — a public `setOfficerByPosition` would still answer `?call=OfficerPosition/SetOfficerByPosition`.
2. Update the internal callers: `RetirePosition` (`:1180`) now calls `$this->vacateOfficerByPosition(...)`; `TransitionOfficer` is unaffected.
3. Add the two `$request` wrappers, each following `TransitionOfficer`'s gate-then-delegate shape:

```php
    public function SetOccupant($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }
        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        $park_id    = (int) ($request['ParkId'] ?? 0);
        $scope      = ($park_id > 0) ? 'park' : 'kingdom';
        $scope_id   = ($park_id > 0) ? $park_id : $kingdom_id;
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id, self::PermissionKeyFor('set', $park_id), $scope, $scope_id, AUTH_EDIT)) {
            return NoAuthorization();
        }
        $r = $this->setOfficerByPosition(
            $kingdom_id, $park_id, (int) ($request['PositionId'] ?? 0),
            (int) ($request['MundaneId'] ?? 0), (string) ($request['TermStart'] ?? ''),
            (string) ($request['TermEnd'] ?? ''), (string) ($request['Note'] ?? ''), $actor_id
        );
        if (is_array($r) && (int) ($r['Status'] ?? 1) === 0) {
            $safe = $request;
            unset($safe['Token']);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $safe,
                $scope, $scope_id, null, ['MundaneId' => (int) ($request['MundaneId'] ?? 0)]);
        }
        return $r;
    }

    public function VacateOfficer($request)
    {
        if (($actor_id = Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) == 0) {
            return NoAuthorization();
        }
        $kingdom_id = (int) ($request['KingdomId'] ?? 0);
        $park_id    = (int) ($request['ParkId'] ?? 0);
        $scope      = ($park_id > 0) ? 'park' : 'kingdom';
        $scope_id   = ($park_id > 0) ? $park_id : $kingdom_id;
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id, self::PermissionKeyFor('vacate', $park_id), $scope, $scope_id, AUTH_EDIT)) {
            return NoAuthorization();
        }
        $mundane_id = (int) ($request['MundaneId'] ?? 0);
        if (!valid_id($mundane_id)) {
            return InvalidParameter(null, 'A valid member is required to remove an officer.');
        }
        $prior = $mundane_id;
        $r = $this->vacateOfficerByPosition(
            $kingdom_id, $park_id, (int) ($request['PositionId'] ?? 0), $actor_id, $mundane_id
        );
        if (is_array($r) && (int) ($r['Status'] ?? 1) === 0) {
            $safe = $request;
            unset($safe['Token']);
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $safe,
                $scope, $scope_id, ['MundaneId' => $prior], ['MundaneId' => 0]);
        }
        return $r;
    }
```

Note `VacateOfficer` **requires** a MundaneId. The all-holders path stays reachable only through `vacateOfficerByPosition`, which `RetirePosition` uses — retiring a position must clear it in every park and the kingdom at once, so that branch is not dead code.

4. In `controller.OfficerAdminAjax.php`, change `actionSetOccupant` and `actionVacate` to call the new methods, passing `'Token' => $this->session->token` and dropping the `$uid` argument. Delete the `vacateall` case from the switch and its `$gate` entry; leave `vacate` and `vacateholder` both routed to the single-holder path.

- [ ] **Step 4: Run and confirm it passes**

**First update the tests this task breaks.** `OfficerOccupancyTest` (Task 2) calls
`$this->positions->SetOfficerByPosition(...)`, which is now private. Rewrite both of its
tests to go through `SetOccupant` with a fixture token, adding
`private AuthorizedOfficerFixture $fixture;` to its `setUp`:

```php
        $token = $this->fixture->createAuthorizedOfficer();
        $base  = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'PositionId' => $positionId];
        $first  = $this->positions->SetOccupant($base + ['ParkId' => self::PARK_A, 'MundaneId' => $mundaneId]);
        $second = $this->positions->SetOccupant($base + ['ParkId' => self::PARK_B, 'MundaneId' => $mundaneId]);
```

The assertions are unchanged — a person may still hold two offices; only the entry point moved.

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAuthorizationTest.php tests/Integration/OfficerOccupancyTest.php tests/Integration/OfficerTransitionTest.php`
Expected: PASS.

Then verify the console still works in the browser: open Kingdom Admin → Manage Officers, assign an occupant, and vacate one. Both must succeed.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php orkui/controller/controller.OfficerAdminAjax.php tests/Integration/OfficerAuthorizationTest.php
git commit -m "Enhancement: SetOccupant and VacateOfficer are token-gated \$request methods

Both took the actor as a \$changed_by argument and relied on a controller for
authorization, which is why neither could be exposed. The old positional
methods survive as private helpers -- visibility, not casing, is what hides
them, because PHP method names are case-insensitive and the caller picks the
casing. RetirePosition keeps the all-holders vacate it needs to clear a
position across every park at once.

Vacate now checks kingdom.officer.vacate / park.officer.vacate. Those existed
and were checked by nothing; the console mapped every vacate to officer.set."
```

---

## Task 6: Convert the five position-management methods

`CreatePosition`, `EditPosition`, `ReorderSiblings`, `RetirePosition`, `ReinstatePosition` all take a caller-supplied actor and none authorize.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php`
- Modify: `orkui/controller/controller.OfficerAdminAjax.php` — call sites only
- Test: `tests/Integration/OfficerAuthorizationTest.php` (extend)

**Interfaces:**
- Consumes: `PermissionKeyFor()` (Task 1).
- Produces: `CreatePosition($request)`, `EditPosition($request)`, `ReorderPositions($request)`, `RetirePosition($request)`, `ReinstatePosition($request)` — all gated on `PermissionKeyFor('position', $park_id)`. Old bodies become `createPositionInternal()`, `editPositionInternal()`, `reorderSiblings()`, `retirePositionInternal()`, `reinstatePositionInternal()`.

- [ ] **Step 1: Write the failing test**

Append to `OfficerAuthorizationTest`:

```php
    /** @return list<array{0:string,1:array<string,mixed>}> */
    public static function positionMethodProvider(): array
    {
        return [
            ['CreatePosition',    ['Title' => 'Test Office', 'Classification' => 'supporting']],
            ['EditPosition',      ['PositionId' => 1, 'Title' => 'Renamed']],
            ['ReorderPositions',  ['ParentPositionId' => 0, 'OrderedPositionIds' => [1, 2]]],
            ['RetirePosition',    ['PositionId' => 1]],
            ['ReinstatePosition', ['PositionId' => 1]],
        ];
    }

    /** @dataProvider positionMethodProvider */
    public function testEveryPositionMethodRejectsAnInvalidToken(string $method, array $payload): void
    {
        $payload['Token'] = 'not-a-real-token';
        $payload['KingdomId'] = self::KINGDOM_ID;
        $payload['ParkId'] = 0;
        $r = $this->positions->{$method}($payload);
        self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
    }

    public function testPositionManagementUsesThePositionPermission(): void
    {
        self::assertSame('kingdom.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 0));
        // Defined in PermissionRegistry since this branch began, referenced nowhere.
        self::assertSame('park.officer.position.manage', OfficerPosition::PermissionKeyFor('position', 9));
    }
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter PositionMethod tests/Integration/OfficerAuthorizationTest.php`
Expected: FAIL — the methods still take positional arguments, so PHP raises an `ArgumentCountError` or the array is silently coerced.

- [ ] **Step 3: Implement**

For each of the five: rename the existing method to its `…Internal` name and mark it `private`, body untouched, then add a `$request` wrapper following exactly the gate-then-delegate-then-audit shape from Task 5's `SetOccupant`, using `self::PermissionKeyFor('position', $park_id)`. `AUTH_CREATE` is the legacy role for `CreatePosition`; the other four use `AUTH_EDIT`.

`ReorderSiblings` becomes `private function reorderSiblingsInternal`, and its wrapper is named `ReorderPositions` to match the controller's existing action name.

**The `…Internal` suffix is not decoration.** PHP method names are case-insensitive, so a
class cannot declare both `CreatePosition($request)` and `createPosition($kingdom_id, …)`
— they are the same method and PHP raises a fatal redeclaration error. Each internal needs
a genuinely different name.

Update all five call sites in `controller.OfficerAdminAjax.php` to pass `'Token' => $this->session->token` and drop the `$uid` argument. The per-action `$gate` array in the controller becomes redundant for these actions — leave it in place for now as defence in depth; Plan 2 removes it.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAuthorizationTest.php tests/Integration/OfficerPositionReorderTest.php tests/Integration/OfficerPositionReinstateTest.php`
Expected: PASS. The two pre-existing suites call the old names — update them to the `…Internal` names, since they test the internals, not the API.

Then verify in the browser: create a position, rename it, drag to reorder, retire it, reinstate it.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php orkui/controller/controller.OfficerAdminAjax.php tests/
git commit -m "Enhancement: position management is token-gated and scope-aware

Create, edit, reorder, retire and reinstate all took a caller-supplied actor
id and none authorized. They now gate on kingdom.officer.position.manage or
park.officer.position.manage -- the latter defined since this branch began
and, until now, checked by nothing anywhere."
```

---

## Task 7: History term methods

The public profile modal currently writes history through `KingdomAjax/{add,edit,delete}officerhistory`. Plan 2 removes those; this task gives them a gated domain home first.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php`
- Test: `tests/Integration/OfficerAuthorizationTest.php` (extend)

**Interfaces:**
- Consumes: `PermissionKeyFor()` (Task 1); `normalizeDate()` (Task 4).
- Produces: `AddHistoryTerm($request)`, `EditHistoryTerm($request)`, `DeleteHistoryTerm($request)`, all gated on `PermissionKeyFor('history', $park_id)`. Keys: `Token, KingdomId, ParkId, PositionId, MundaneId, StartDate, EndDate, Note` (`AddHistoryTerm`); `Token, KingdomId, ParkId, OfficerHistoryId, StartDate, EndDate, Note` (`EditHistoryTerm`); `Token, KingdomId, ParkId, OfficerHistoryId` (`DeleteHistoryTerm`).

- [ ] **Step 1: Write the failing test**

```php
    public function testHistoryMethodsRejectAnInvalidToken(): void
    {
        foreach (['AddHistoryTerm', 'EditHistoryTerm', 'DeleteHistoryTerm'] as $method) {
            $r = $this->positions->{$method}([
                'Token' => 'not-a-real-token', 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                'PositionId' => $this->seededPositions['crown_a'],
                'OfficerHistoryId' => 1, 'MundaneId' => $this->seededMundanes[0],
            ]);
            self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
        }
    }

    public function testEditHistoryTermRejectsAFutureEndDate(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => 1,
            'EndDate' => date('Y-m-d', strtotime('+30 days')),
        ]);
        self::assertSame(4, (int) $r['Status'],
            'the rolls must not be able to record a term ending in the future');
    }

    public function testEditHistoryTermRefusesARowOutsideTheCallersScope(): void
    {
        $token = $this->fixture->createAuthorizedOfficer();
        // officer_history_id 0 never exists; a row from another kingdom must be
        // refused the same way -- the gate is on the row's scope, not the caller's claim.
        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'OfficerHistoryId' => 0, 'EndDate' => '2026-01-01',
        ]);
        self::assertSame(4, (int) $r['Status']);
    }
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter History tests/Integration/OfficerAuthorizationTest.php`
Expected: FAIL — methods undefined.

- [ ] **Step 3: Implement**

Add all three to `class.OfficerPosition.php` following the Task 4 gate shape. Two rules specific to history editing:

- **Authorize against the row's own scope, not the request's.** `EditHistoryTerm` and `DeleteHistoryTerm` must `SELECT kingdom_id, park_id FROM ork_officer_history WHERE officer_history_id = :id` first, then gate on *those* values. Gating on caller-supplied `KingdomId` would let anyone edit any row by naming a kingdom they administer.
- **Same date rules as a transition:** no future end date, end not before start, start may be NULL.

Each writes a `dangeraudit` row. `DeleteHistoryTerm` records the full deleted row as `$prior_state` — it is the only way to recover it.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAuthorizationTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php tests/Integration/OfficerAuthorizationTest.php
git commit -m "Enhancement: officer history terms get a gated domain home

Add, edit and delete are gated on kingdom.officer_history.manage /
park.officer_history.manage, which the registry defined and only the legacy
path used. Edit and delete authorize against the ROW's kingdom and park, not
the caller's claim, so naming a kingdom you administer does not reach another
kingdom's rolls. Delete audits the whole row as prior state."
```

---

## Task 8: One seat per office

An office holds one person; a person may hold any number of offices. Production has zero positions with more than one occupant, so this is additive.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php` — `InsertOfficerRow`
- Test: `tests/Integration/OfficerOccupancyTest.php` (extend)

**Interfaces:**
- Consumes: nothing new.
- Produces: `insertOfficerRow()` returns `InvalidParameter` when the seat is already held by someone else.

- [ ] **Step 1: Write the failing test**

```php
    public function testASecondPersonCannotTakeAnOccupiedSeat(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $first  = $this->seededMundanes[0];
        $second = $this->seedMundane('second');
        $this->seededMundanes[] = $second;

        $token = $this->fixture->createAuthorizedOfficer();
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];
        $this->positions->SetOccupant($base + ['MundaneId' => $first]);
        $this->positions->SetOccupant($base + ['MundaneId' => $second]);

        // Not a refusal in the crown path -- crown replaces in place. This asserts the
        // SUPPORTING path, which used to append a second row.
        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'an office holds exactly one person');
    }

    public function testRetireStillClearsEveryScope(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $token = $this->fixture->createAuthorizedOfficer();
        foreach ([self::PARK_A, self::PARK_B, 0] as $parkId) {
            $this->positions->SetOccupant([
                'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => $parkId,
                'PositionId' => $positionId, 'MundaneId' => $mundaneId,
            ]);
        }
        $this->positions->RetirePosition([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId,
        ]);

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer WHERE position_id = :pid AND mundane_id > 0'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(0, (int) $stmt->fetchColumn(),
            'retire is cross-scope; one-seat is per-scope. Collapsing them breaks retirement.');
    }
```

These go through the public gated API, not the internals — after Tasks 5 and 6 the
positional methods are `private` and a test cannot call them. Add
`private AuthorizedOfficerFixture $fixture;` to this test's `setUp` if it is not already
there (Task 2 created the file without one).

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter Seat tests/Integration/OfficerOccupancyTest.php`
Expected: FAIL — the supporting path appends, so the count is 2.

- [ ] **Step 3: Implement**

In `insertOfficerRow`, replace the "skip a duplicate active occupant" check with a seat check. It currently looks for the same `mundane_id`; it must instead look for *any* holder:

```php
        // One seat per office. A kingdom that wants two deputies creates two
        // offices. This is the mirror of the removed crown rule, not a survivor of
        // it: the constraint is on the seat, never on the person -- a person may
        // hold any number of offices, at any number of levels.
        $DB->Clear();
        $DB->io_kid = $kingdom_id;
        $DB->io_pid = $park_id;
        $DB->io_pos = $position_id;
        $held = $DB->DataSet(
            'SELECT mundane_id FROM ' . DB_PREFIX . 'officer
             WHERE kingdom_id = :io_kid AND park_id = :io_pid
               AND position_id = :io_pos AND mundane_id > 0 LIMIT 1'
        );
        if ($held !== false && $held->size() > 0 && $held->Next()) {
            if ((int) $held->mundane_id === $mundane_id) {
                return Success();   // idempotent: already seated
            }
            return InvalidParameter(null, 'This office already has a holder. Transition it instead.');
        }
```

Leave `vacateOfficerByPosition`'s all-holders branch alone — `retirePositionInternal` depends on it.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerOccupancyTest.php`
Expected: PASS, 4 tests.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php tests/Integration/OfficerOccupancyTest.php
git commit -m "Enhancement: an office holds one person

Supporting offices appended a second ork_officer row per person; crown
replaced in place. They now behave identically. Production has zero positions
with more than one occupant, so the constraint is additive.

This is the mirror of the crown rule removed earlier, not a survivor of it:
the limit is on the seat, never on the person."
```

---

## Task 9: Gate `RBACService`'s five user-facing mutators

`GrantRole`, `RevokeRole`, `CreateRole`, `EditRole`, `DeleteRole` each take the actor as a parameter (`$granter_id`, `$revoker_id`, `$creator_id`, `$editor_id`, `$deleter_id`). `GrantRole` prevents privilege escalation by comparing the granter's permissions — but for whoever the caller *claimed* to be. Reachable from two controllers: `OfficerAdminAjax` and the role-management block in `KingdomAjax`.

`RBACService` is deliberately **not** registered on the API in this plan — its 21-method read surface needs its own authorization pass first.

**Files:**
- Modify: `system/lib/ork3/class.RBACService.php`
- Modify: `orkui/controller/controller.OfficerAdminAjax.php`, `orkui/controller/controller.KingdomAjax.php` — call sites only
- Test: `tests/Integration/RbacAuthorizationTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `GrantRole($request)`, `RevokeRole($request)`, `CreateRole($request)`, `EditRole($request)`, `DeleteRole($request)` — token-gated on `kingdom.auth.manage`. Old bodies become `grantRole()`, `revokeRole()`, `createRole()`, `editRole()`, `deleteRole()`. `SyncOfficerRole`, `SyncOfficerRoleByPositionId`, `SyncNewOfficerSlot` are renamed `sync_officer_role()`, `sync_officer_role_by_position_id()`, `sync_new_officer_slot()` — underscores, so the dispatcher cannot request them. They stay **public**, because `Common::set_officer` and `OfficerPosition` call them from other classes and `private` is not available across that boundary.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class RbacAuthorizationTest extends TestCase
{
    public function testEveryUserFacingMutatorRejectsAnInvalidToken(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Test database is not available.');
        }
        $rbac = new RBACService();
        foreach (['GrantRole', 'RevokeRole', 'CreateRole', 'EditRole', 'DeleteRole'] as $method) {
            $r = $rbac->{$method}(['Token' => 'not-a-real-token', 'KingdomId' => 100041]);
            self::assertSame(5, (int) $r['Status'], $method . ' must reject an invalid token');
        }
    }

    public function testTheActorCannotBeNamedByTheCaller(): void
    {
        $rbac = new RBACService();
        // Every one of these took the actor as a parameter. Passing one must not
        // grant standing -- the token is the only thing that establishes identity.
        $r = $rbac->GrantRole([
            'Token' => '', 'GranterId' => 1, 'KingdomId' => 100041,
            'MundaneId' => 2, 'RoleId' => 3, 'ScopeType' => 'kingdom', 'ScopeId' => 100041,
        ]);
        self::assertSame(5, (int) $r['Status']);
    }

    public function testInternalSyncMethodsAreUnreachableByTheDispatcher(): void
    {
        foreach (['SyncOfficerRole', 'SyncOfficerRoleByPositionId', 'SyncNewOfficerSlot'] as $old) {
            self::assertFalse(method_exists('RBACService', $old), $old . ' must be renamed with underscores');
        }
        foreach (['sync_officer_role', 'sync_officer_role_by_position_id', 'sync_new_officer_slot'] as $new) {
            self::assertTrue(method_exists('RBACService', $new));
        }
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/RbacAuthorizationTest.php`
Expected: FAIL — the methods take positional arguments.

- [ ] **Step 3: Implement**

Rename all eight as described, then add five `$request` wrappers gating on `kingdom.auth.manage` (the key both controllers already use) with `AUTH_CREATE`, resolving the actor from the token and passing it into the internal method in place of the old actor parameter.

Update every call site:
- `SyncOfficerRoleByPositionId` is called from `common.php:924` and `class.OfficerPosition.php:1847` → rename to `sync_officer_role_by_position_id`.
- `SyncOfficerRole` from `common.php:926` → `sync_officer_role`.
- `SyncNewOfficerSlot` — find callers with `grep -rn "SyncNewOfficerSlot" --include="*.php" .` and rename each.
- The five mutators in `controller.OfficerAdminAjax.php` and `controller.KingdomAjax.php` → pass `'Token' => $this->session->token`.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/RbacAuthorizationTest.php`
Expected: PASS, 3 tests.

Run the whole suite — this task touches the officer write path's RBAC sync:
Run: `bin/run-unit-tests.sh`
Expected: PASS.

Then verify in the browser: Kingdom Admin → Roles — create a role, edit its permissions, grant it, revoke it, delete it.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.RBACService.php orkui/controller/ tests/Integration/RbacAuthorizationTest.php
git commit -m "Security: RBAC role management proves the actor instead of trusting it

GrantRole, RevokeRole, CreateRole, EditRole and DeleteRole each took the
acting user as a parameter. GrantRole's escalation guard compared the
granter's own permissions -- for whoever the caller said they were. Identity
now comes from the token.

The three Sync* methods are internal, called from set_officer and the officer
insert path, so they are renamed with underscores rather than token-gated:
validate_method rejects any requested name containing an underscore. They stay
public because private is not available across a class boundary."
```

---

## Task 10: Register `OfficerPosition`, and prove the exposure surface

`JsonServer::validate_method` (`class.JsonServer.php:398`) applies no method whitelist — it rejects only lowercase-initial names, names containing `_`, and `__construct`. Whitelisting a class publishes **every public PascalCase method on it**, including ones added later.

**Files:**
- Modify: `orkservice/Json/index.php:19`
- Modify: `system/lib/ork3/class.OfficerPosition.php` — rename `DisplayTitleSql`/`SortOrderSql`
- Test: `tests/Unit/ApiExposureTest.php`

**Interfaces:**
- Consumes: everything from Tasks 1–9.
- Produces: `OfficerPosition` reachable as `?call=OfficerPosition/TransitionOfficer` etc. `DisplayTitleSql` → `displayTitleSql`, `SortOrderSql` → `sortOrderSql`.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * JsonServer publishes every public PascalCase method on a whitelisted class.
 * This test is the regression guard: without it, the next PascalCase method
 * added to a registered class is silently published, ungated.
 */
final class ApiExposureTest extends TestCase
{
    /** Methods deliberately public and safe to call without a token. */
    private const REVIEWED_PUBLIC = [
        'OfficerPosition' => [
            'GetPositions', 'GetPosition', 'GetOfficersForDisplay',
            'ResolvePositionId', 'ResolveCanonicalKey', 'PermissionKeyFor',
        ],
    ];

    /** @return list<array{0:string}> */
    public static function registeredClassProvider(): array
    {
        $src = file_get_contents(__DIR__ . '/../../orkservice/Json/index.php');
        preg_match_all("/'([A-Za-z]+)'/", $src, $m);
        return array_map(static fn ($c) => [$c], array_values(array_intersect($m[1], ['OfficerPosition'])));
    }

    /** @dataProvider registeredClassProvider */
    public function testEveryPublishedMethodIsGatedOrReviewed(string $class): void
    {
        $source = file_get_contents(dirname(__DIR__, 2) . '/system/lib/ork3/class.' . $class . '.php');
        $reflection = new ReflectionClass($class);

        foreach ($reflection->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if ($method->getDeclaringClass()->getName() !== $class) {
                continue;
            }
            // ONLY these two are genuinely unreachable. Casing is NOT a filter:
            // method_exists() is case-insensitive and the caller picks the casing,
            // so a public lowerStart() answers ?call=Class/LowerStart.
            if ($name === '__construct' || str_contains($name, '_')) {
                continue;
            }
            if (in_array($name, self::REVIEWED_PUBLIC[$class] ?? [], true)) {
                continue;
            }
            $body = $this->methodBody($source, $name);
            self::assertStringContainsString(
                'IsAuthorized',
                $body,
                "{$class}::{$name} is published by orkservice/Json but never checks a token. "
                . 'Either gate it, rename it with an underscore, make it private, or '
                . 'add it to REVIEWED_PUBLIC with a reason. Renaming it lowercase-initial '
                . 'does NOT hide it -- PHP method names are case-insensitive.'
            );
        }
    }

    public function testSqlFragmentBuildersAreNotPublishable(): void
    {
        // method_exists is CASE-INSENSITIVE, so a lowercase rename would still pass a
        // naive check here and still be dispatchable. Underscores are the real lever.
        self::assertFalse(method_exists('OfficerPosition', 'DisplayTitleSql'));
        self::assertFalse(method_exists('OfficerPosition', 'SortOrderSql'));
        self::assertTrue(method_exists('OfficerPosition', 'display_title_sql'));
        self::assertTrue(method_exists('OfficerPosition', 'sort_order_sql'));
    }

    public function testRbacServiceIsNotRegistered(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkservice/Json/index.php');
        self::assertStringNotContainsString(
            "'RBACService'",
            $src,
            'RBACService has a 21-method read surface (GetEffectivePermissions, '
            . 'GetUserRoles, GetKingdomRoleAssignments) that has had no authorization '
            . 'pass. Registering it publishes all of them.'
        );
    }

    private function methodBody(string $source, string $name): string
    {
        $start = strpos($source, 'function ' . $name . '(');
        if ($start === false) {
            return '';
        }
        $next = strpos($source, "\n    public function ", $start + 1);
        $next = $next === false ? strlen($source) : $next;
        return substr($source, $start, $next - $start);
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/ApiExposureTest.php`
Expected: FAIL — `DisplayTitleSql` still exists, and `OfficerPosition` is not yet in the whitelist so the provider is empty.

- [ ] **Step 3: Implement**

1. Rename `DisplayTitleSql` → `display_title_sql` and `SortOrderSql` → `sort_order_sql`. Underscores, not merely a lowercase initial — these are called from `class.Kingdom.php`, `class.Park.php` and `common.php`, so `private` is unavailable, and a public `displayTitleSql` would still answer `?call=OfficerPosition/DisplayTitleSql`. Update every caller:
   `grep -rn "DisplayTitleSql\|SortOrderSql" --include="*.php" system/ orkui/` — expect hits in `class.OfficerPosition.php`, `class.Kingdom.php`, `class.Park.php`, `common.php`.
2. Add `'OfficerPosition',` to the array in `orkservice/Json/index.php:19`, alphabetically between `'Map'` and `'Park'`.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Unit/ApiExposureTest.php`
Expected: PASS.

Run: `bin/run-unit-tests.sh`
Expected: PASS.

Verify the endpoint answers. With the dev stack up:

```bash
curl -s 'http://localhost:19080/orkservice/Json/index.php?describe=OfficerPosition/TransitionOfficer'
```
Expected: a JSON call definition, not an error. Then confirm it refuses an unauthenticated write:

```bash
curl -s -X POST 'http://localhost:19080/orkservice/Json/index.php?call=OfficerPosition/TransitionOfficer' \
     -d 'KingdomId=1&ParkId=0&PositionId=1&MundaneId=1'
```
Expected: `"Status":5` (NoAuthorization) — no token was supplied.

- [ ] **Step 5: Commit**

```bash
git add orkservice/Json/index.php system/lib/ork3/ tests/Unit/ApiExposureTest.php
git commit -m "Enhancement: OfficerPosition becomes a real API surface

JsonServer::validate_method has no method whitelist -- it refuses only
lowercase-initial names, names containing an underscore, and __construct. So
registering a class publishes every public PascalCase method on it, forever.

Every mutator is gated as of the preceding tasks. The two SQL-fragment
builders are renamed with underscores, which puts them permanently out of the
dispatcher's reach, and ApiExposureTest fails the build if a future public
method arrives ungated.

Renaming lowercase-initial does NOT hide a method: PHP method names are
case-insensitive and the caller picks the casing, so ?call=.../DisplayTitleSql
reaches displayTitleSql. Verified before relying on it.

RBACService stays unregistered: its 21 read methods have had no
authorization pass."
```

---

## Task 11: Backfill the rolls

2,507 seated officers have no open history term, so the rolls are empty and every transition would hit `CloseOfficerHistoryTerm`'s backfill branch. Only 66 have a usable `ork_officer.modified`. One row (`officer_history_id 1`, `royal_scribe`) is closed with a **future** `end_date`, so a sitting officer reads as departed.

`start_date` and `end_date` are already `DEFAULT NULL` — **no DDL**.

**Files:**
- Create: `db-migrations/2026-08-29-officer-history-backfill.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5`
- Test: `tests/Integration/OfficerHistoryBackfillTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: every seated officer has exactly one open term.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerHistoryBackfillTest extends TestCase
{
    private const MARKER = 'zzbackfill';
    private const KINGDOM_ID = 100051;
    // setUp / tearDown / seedPosition / seedMundane: copy from OfficerOccupancyTest.

    public function testSeatedOfficerWithUsableModifiedGetsThatStartDate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2024-05-01 12:00:00');

        $this->runBackfill();

        $row = $this->openTerm($positionId, $mundaneId);
        self::assertNotNull($row, 'a seated officer must end up with an open term');
        self::assertSame('2024-05-01', $row['start_date']);
        self::assertNull($row['end_date']);
    }

    public function testSeatedOfficerWithZeroModifiedGetsANullStartDate(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '0000-00-00 00:00:00');

        $this->runBackfill();

        $row = $this->openTerm($positionId, $mundaneId);
        self::assertNotNull($row);
        self::assertNull($row['start_date'],
            'an unknown start must stay NULL -- never a derived date presented as recorded fact');
    }

    public function testRunningTwiceDoesNotDuplicate(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2024-05-01 12:00:00');

        $this->runBackfill();
        $this->runBackfill();

        $stmt = $this->pdo->prepare(
            'SELECT COUNT(*) FROM ork_officer_history WHERE position_id = :pid AND end_date IS NULL'
        );
        $stmt->execute([':pid' => $positionId]);
        self::assertSame(1, (int) $stmt->fetchColumn(), 'the migration must be idempotent');
    }

    public function testAFutureEndDateOnASeatedOfficerIsReopened(): void
    {
        $positionId = $this->seededPositions['crown_a'];
        $mundaneId  = $this->seededMundanes[0];
        $this->seatOfficer($positionId, $mundaneId, '2026-08-29 16:06:01');
        $future = date('Y-m-d', strtotime('+90 days'));
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                 position_id, display_label, start_date, end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, "Test", "2026-08-29", :end, NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId, ':end' => $future,
        ]);

        $this->runBackfill();

        self::assertNotNull($this->openTerm($positionId, $mundaneId),
            'a future end date on a still-seated officer made them read as departed');
    }

    public function testALegitimatelyClosedTermIsNotReopened(): void
    {
        $positionId = $this->seededPositions['crown_b'];
        $formerId   = $this->seedMundane('former');
        $this->seededMundanes[] = $formerId;
        $this->pdo->prepare(
            'INSERT INTO ork_officer_history (kingdom_id, park_id, mundane_id, role,
                 position_id, display_label, start_date, end_date, changed_by, created_at)
             VALUES (:kid, 0, :mid, :role, :pid, "Test", "2025-01-01", "2025-12-31", NULL, NOW())'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $formerId,
            ':role' => self::MARKER . '_crown_b', ':pid' => $positionId,
        ]);

        $this->runBackfill();

        self::assertNull($this->openTerm($positionId, $formerId),
            'a person who is no longer seated must stay closed');
    }

    private function runBackfill(): void
    {
        $sql = file_get_contents(dirname(__DIR__, 2) . '/db-migrations/2026-08-29-officer-history-backfill.sql');
        foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
            if (str_starts_with($statement, '--') || $statement === '') {
                continue;
            }
            $this->pdo->exec($statement);
        }
    }

    /** @return array<string,mixed>|null */
    private function openTerm(int $positionId, int $mundaneId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT start_date, end_date FROM ork_officer_history
             WHERE position_id = :pid AND mundane_id = :mid AND end_date IS NULL LIMIT 1'
        );
        $stmt->execute([':pid' => $positionId, ':mid' => $mundaneId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }

    private function seatOfficer(int $positionId, int $mundaneId, string $modified): void
    {
        $this->pdo->prepare(
            'INSERT INTO ork_officer (kingdom_id, park_id, mundane_id, role, system,
                                      authorization_id, position_id, modified)
             VALUES (:kid, 0, :mid, :role, 0, 0, :pid, :mod)'
        )->execute([
            ':kid' => self::KINGDOM_ID, ':mid' => $mundaneId,
            ':role' => self::MARKER . '_crown_a', ':pid' => $positionId, ':mod' => $modified,
        ]);
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerHistoryBackfillTest.php`
Expected: FAIL — the migration file does not exist, so `file_get_contents` warns and returns false.

- [ ] **Step 3: Write the migration**

Create `db-migrations/2026-08-29-officer-history-backfill.sql`:

```sql
-- Migration: open an ork_officer_history term for every seated officer.
-- Run via: docker exec -i ork3-php8-db mariadb -u root -proot ork < db-migrations/2026-08-29-officer-history-backfill.sql
--
-- ork_officer_history held ONE row against 2,507 seated officers, so the rolls
-- were empty and no sitting officer had an open term. Both date columns are
-- already DEFAULT NULL, so this migration alters nothing -- it is pure data.
--
-- Idempotent: the INSERT is guarded by a NOT EXISTS on the open term, and the
-- reopen is naturally idempotent (a second run matches nothing).

-- 1. Open a term for every seated officer that has none.
--    start_date takes ork_officer.modified where it is usable, and NULL otherwise.
--    NULL, never a derived date: 97% of rows have a zero modified, and presenting an
--    inferred date as a recorded fact is its own bug.
INSERT INTO `ork_officer_history`
    (kingdom_id, park_id, mundane_id, role, position_id, display_label,
     start_date, end_date, changed_by, notes, created_at)
SELECT
    o.kingdom_id,
    o.park_id,
    o.mundane_id,
    o.role,
    o.position_id,
    IF(a.title_alias IS NOT NULL AND a.title_alias <> '', a.title_alias, COALESCE(p.title, o.role)),
    CASE
        WHEN o.modified IS NULL THEN NULL
        WHEN DATE(o.modified) <= '1970-01-01' THEN NULL
        WHEN DATE(o.modified) > CURDATE() THEN NULL
        ELSE DATE(o.modified)
    END,
    NULL,
    NULL,
    NULL,
    NOW()
FROM `ork_officer` o
LEFT JOIN `ork_officer_position` p
       ON p.position_id = o.position_id
LEFT JOIN `ork_officer_position_alias` a
       ON a.kingdom_id = o.kingdom_id AND a.canonical_key = p.canonical_key
WHERE o.mundane_id > 0
  AND NOT EXISTS (
      SELECT 1 FROM `ork_officer_history` h
       WHERE h.kingdom_id  = o.kingdom_id
         AND h.park_id     = o.park_id
         AND h.position_id = o.position_id
         AND h.mundane_id  = o.mundane_id
         AND h.end_date IS NULL
  );

-- 2. Reopen any term closed with a FUTURE end date whose holder is still seated.
--    Someone used Term End as a projected end date, which made a sitting officer
--    read as departed -- end_date IS NULL is what defines "current" everywhere.
--    Written generally rather than against the one known row.
UPDATE `ork_officer_history` h
  JOIN `ork_officer` o
    ON o.kingdom_id  = h.kingdom_id
   AND o.park_id     = h.park_id
   AND o.position_id = h.position_id
   AND o.mundane_id  = h.mundane_id
   SET h.end_date = NULL
 WHERE h.end_date > CURDATE();
```

Note step 1 runs before step 2 deliberately: an officer whose only term has a future end date has no *open* term, so step 1 would otherwise insert a duplicate. Reversing the order changes the result — do not reorder.

Wait: that is backwards. Step 2 must run **first**, so the future-dated term becomes the open term and step 1's `NOT EXISTS` then sees it. Put the `UPDATE` block above the `INSERT` block in the file, keeping the numbering comments consistent.

- [ ] **Step 4: Classify the migration and run the tests**

Add to `tools/ork-db/manifests/migration-classification.json5`, matching the shape of the neighbouring entries:

```json5
    // Pure data backfill: opens a history term for every seated officer and
    // reopens future-dated terms. No DDL -- both date columns were already nullable.
    "2026-08-29-officer-history-backfill.sql": "data",
```

Check an existing entry first for the exact key format and allowed values; copy that, do not invent one.

Run: `php tools/ork-db/cli.php drift-check --strict`
Expected: PASS (an unclassified migration blocks the whole suite).

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerHistoryBackfillTest.php`
Expected: PASS, 5 tests.

- [ ] **Step 5: Commit**

```bash
git add db-migrations/2026-08-29-officer-history-backfill.sql tools/ork-db/manifests/migration-classification.json5 tests/Integration/OfficerHistoryBackfillTest.php
git commit -m "Enhancement: give the officer rolls their data

ork_officer_history held one row against 2,507 seated officers, so no sitting
officer had an open term and the rolls rendered empty everywhere. Opens one
per seated officer. The 66 rows with a usable modified date get it; the 2,441
without get NULL rather than an inferred date presented as recorded fact.

Also reopens terms closed with a future end date whose holder is still
seated -- someone had used Term End as a projected end, which made a sitting
officer read as departed. No DDL: both columns were already nullable."
```

---

## Task 12: Full-suite sign-off and a real-data rehearsal

**Files:**
- Test: no new files.

**Interfaces:**
- Consumes: everything.
- Produces: a verified backend.

- [ ] **Step 1: Run the whole suite**

Run: `bin/run-unit-tests.sh`
Expected: PASS, zero failures. This includes `drift-check --strict`. A `class` catalog-hash drift failure is a known local-only issue — if that is the only failure, note it and continue.

- [ ] **Step 2: Rehearse the migration against a copy of real data**

```bash
docker exec ork3-php8-db mariadb -u root -proot -e "CREATE DATABASE IF NOT EXISTS ork_rehearse"
docker exec ork3-php8-db sh -c "mariadb-dump -u root -proot ork | mariadb -u root -proot ork_rehearse"
docker exec -i ork3-php8-db mariadb -u root -proot ork_rehearse < db-migrations/2026-08-29-officer-history-backfill.sql
docker exec -i ork3-php8-db mariadb -u root -proot ork_rehearse -e "
SELECT COUNT(*) AS seated_without_open_term FROM ork_officer o
 LEFT JOIN ork_officer_history h
   ON h.kingdom_id=o.kingdom_id AND h.park_id=o.park_id
      AND h.position_id=o.position_id AND h.mundane_id=o.mundane_id AND h.end_date IS NULL
 WHERE o.mundane_id>0 AND h.officer_history_id IS NULL;
SELECT COUNT(*) AS future_end_rows FROM ork_officer_history WHERE end_date > CURDATE();
SELECT SUM(start_date IS NULL) AS null_starts, COUNT(*) AS total FROM ork_officer_history WHERE end_date IS NULL;"
```

Expected: `seated_without_open_term` = **0**; `future_end_rows` = **0**; `null_starts` ≈ **2,441** of ≈ **2,507**.

- [ ] **Step 3: Run it twice to prove idempotency**

```bash
docker exec -i ork3-php8-db mariadb -u root -proot ork_rehearse < db-migrations/2026-08-29-officer-history-backfill.sql
docker exec -i ork3-php8-db mariadb -u root -proot ork_rehearse -e "
SELECT COUNT(*) AS open_terms FROM ork_officer_history WHERE end_date IS NULL;"
```

Expected: identical to the previous count. Then drop it: `docker exec ork3-php8-db mariadb -u root -proot -e "DROP DATABASE ork_rehearse"`.

- [ ] **Step 4: Verify the existing UI still works**

Nothing user-visible changed, so every current surface must behave exactly as before. In the browser, as an admin:
1. Kingdom profile → Edit Officers pad → assign, then vacate. Both succeed.
2. Park profile → Edit Officers pad → assign, then vacate. Both succeed.
3. Kingdom Admin → Manage Officers → create, edit, reorder, retire, reinstate a position; assign and vacate an occupant.
4. Kingdom Admin → Roles → create, edit, grant, revoke, delete a role.
5. Officer details modal → History tab renders the newly backfilled terms without error.

Item 5 is the one most likely to surface a problem: that panel has only ever rendered an empty list and now receives thousands of rows.

- [ ] **Step 5: Commit any fixes**

```bash
git add -u
git commit -m "Bugfix: <what the rehearsal or UI check turned up>"
```

If nothing needed fixing, skip this step.

---

## Plan 2 preview (not part of this plan)

With the backend proven, Plan 2 covers: the `_officer_transition.tpl` wizard; the
"Correct the Rolls" admin tab; the unknown-start-date nudge on an office card (the only
consumer of the NULL starts Task 11 writes, and what converts the backfill's honesty into
data over time); making the public history panel read-only; the revised-frontend Park
admin console; `$mo_park_id` scoping for `_manage_officers.tpl`; retiring the now-redundant
per-action `$gate` array in `controller.OfficerAdminAjax.php`; and the removal of the two
edit pads, the two legacy set-officers pages, and the six `officerhistory` Ajax actions. That removal is safe only because Task 5 and Task 6 put a scope-aware gate in the domain — verify a park-only officer can still manage their park before deleting the park pad.
