# Officer Transition UI Implementation Plan (Plan 2 of 2)

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Put Plan 1's token-gated officer write path behind a UI, and retire the four surfaces it replaces.

**Architecture:** The officer admin's hardcoded kingdom-scope gate becomes scope-aware, which is what unblocks park officers and is the prerequisite for every removal in this plan. A three-step transition wizard replaces the panel inside the existing Manage Officers modal — one overlay, no nested scroll context — and subsumes the assign modal entirely. A Correct the Rolls tab moves history editing off the public profile page. Only after all of that is proven do the two edit pads, the two legacy pages, and the public history writes come out.

**Tech Stack:** PHP 8.2, MariaDB, PHPUnit 11, jQuery + vanilla JS in `.tpl` files (plain PHP templates, **not** Smarty), Flatpickr for dates.

**Spec:** `docs/superpowers/specs/2026-08-30-officer-transition-ui-design.md`
**Predecessor:** `docs/superpowers/specs/2026-08-29-officer-transition-design.md` (Plan 1, shipped)

## Global Constraints

- **`.tpl` files are PLAIN PHP.** `<?php ?>` / `<?= ?>`. `{$var}` and `{if}` render literally as text.
- **Ordering is a safety property.** Task 1's gate lands and its park-officer test passes before anything else; the four surfaces are deleted LAST, in Task 10. Deleting a pad before the gate works locks every Sheriff out of their own park.
- **Domain methods are the authority.** The client mirrors validation for feedback only. Every server rejection is surfaced verbatim — never swallowed, never replaced with a generic string.
- **Response helpers** (`orkservice/Common.definitions.php`): `Success()`→Status **0**, `InvalidParameter($detail,$error)`→4, `NoAuthorization($detail,$error)`→5, `ProcessingError($detail,$error)`→3. Never `Errors::Message`. `emitServiceResult` prefers `Error` over `Detail`, so always use the **two-arg** `(null, 'message')` form — a single-arg call fills `Detail` and the message is replaced by a generic string.
- **Permission keys come from `OfficerPosition::PermissionKeyFor($action, $park_id)`** — never hardcode. Valid actions: `set|vacate|position|history`.
- **`$DB->Clear()` before every raw Execute/DataSet.** `$DB->DataSet()` needs a manual `->Next()` before reading fields.
- **yapo drops a bound PHP null** from INSERT/UPDATE — emit a literal `NULL` in the statement.
- **LAYERING:** no `$DB->`, raw SQL, or `new <DomainClass>(` anywhere under `orkui/`. Controllers reach the domain only through `orkui/model/model.*.php` via `load_model()`.
- **Controller session accessor is `$this->session->token` / `$this->session->user_id`** — never `$this->__session`, which silently yields uid 0.
- **No native `confirm()`/`alert()`/`prompt()`** — they freeze the automation harness. Use the partial's existing `moConfirm` / `moShowNotice`.
- **Tooltips use `data-tip`, never native `title`.**
- **Dark mode is not optional.** Every new rule needs its `html[data-theme="dark"]` counterpart, and must work at ≤768px.
- **Tests:** guard with `ork3_test_db_available()`, MARKER-prefix every seeded row, clean up in `tearDown()` only — inline cleanup does not run on the failure path. Build the PDO directly (`new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8', DB_HOSTNAME, DB_PORT, DB_DATABASE), DB_USERNAME, DB_PASSWORD, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION])`); `ork3_test_pdo()` does NOT exist.
- **`AuthorizedOfficerFixture`** already provides `createAuthorizedOfficer()`, `officerMundaneId()`, `grantParkAuthority(int $parkId)`, `seedRecipient()`. Do not re-add them.
- **COMMIT HYGIENE:** `git add` only your files. NEVER `git add -A`. `system/lib/ork3/class.Authorization.php` carries an uncommitted local debug bypass that MUST NEVER be staged — check `git diff --cached --stat` before every commit.
- **Any task touching controller action routing** must, before finishing, grep the templates for every action name posted and confirm each resolves to both a gate entry and a switch case. Plan 1 shipped a commit that deleted an action the console's only crown Vacate button still called; every test stayed green.
- **Run one file:** `vendor/bin/phpunit --no-coverage tests/Integration/<File>.php` · **Full suite:** `bin/run-unit-tests.sh`

---

## File Structure

| File | Responsibility |
|---|---|
| `orkui/controller/controller.OfficerAdminAjax.php` | Scope-aware gate; new `transition`, `addhistory`, `edithistory`, `deletehistory` actions; `setoccupant` action deleted in Task 10. |
| `orkui/template/revised-frontend/partials/_officer_transition.tpl` | **New.** The wizard: markup, `ot-` CSS, JS module. Rendered into the Manage Officers modal body. |
| `orkui/template/revised-frontend/partials/_manage_officers.tpl` | Hosts the wizard; gains `$mo_park_id`; Correct the Rolls tab; the nudge; `Occupants[]`→`Occupant`; assign modal removed. |
| `orkui/template/revised-frontend/Admin_park.tpl` | **New.** Park admin console. |
| `system/lib/ork3/class.OfficerPosition.php` | Roll-integrity guard on `AddHistoryTerm`/`EditHistoryTerm`. |
| `system/lib/ork3/class.RBACService.php` | `RevokeRole` row-scope check (Task 11, separable). |
| `orkui/template/revised-frontend/Kingdomnew_index.tpl`, `Parknew_index.tpl` | Edit pads and public history writes removed (Task 10). |

---

## Task 1: Scope-aware gate

The one line that blocks every park officer. `controller.OfficerAdminAjax.php:90` gates **every** action on `('kingdom.officer.set', 'kingdom', $kingdom_id)`. Plan 1 built correct park gating in the domain; a park-only officer never reaches it.

**This task must land and pass before any other task in this plan.** It is what makes Task 10's deletions safe.

**Files:**
- Modify: `orkui/controller/controller.OfficerAdminAjax.php:70-95`
- Test: `tests/Integration/OfficerAdminGateTest.php` (create)

**Interfaces:**
- Consumes: `OfficerPosition::PermissionKeyFor($action, $park_id)` (Plan 1).
- Produces: a per-action, per-scope gate. Later tasks add `transition`, `addhistory`, `edithistory`, `deletehistory` to the same map.

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * The officer admin's controller gate.
 *
 * It gated every action on ('kingdom.officer.set','kingdom',$kingdom_id), so a
 * park-only officer was refused before reaching the scope-aware domain gate Plan 1
 * built. This test is the regression guard that makes the edit-pad removals safe:
 * if it fails, deleting the park pad locks every Sheriff out of their own park.
 *
 * `list` MUST stay gated. actionList's personaLabel falls back to
 * GivenName + Surname when a persona is blank, so an ungated list emits real
 * legal names.
 */
final class OfficerAdminGateTest extends TestCase
{
    private const MARKER = 'zzgate';

    public function testEveryActionHasAScopeAwarePermissionKey(): void
    {
        // The map must cover every dispatchable action, and every key must exist.
        $actions = [
            'list' => 'set', 'transition' => 'set',
            'vacate' => 'vacate', 'vacateholder' => 'vacate', 'vacateall' => 'vacate',
            'createposition' => 'position', 'editposition' => 'position',
            'reorderpositions' => 'position', 'reclassify' => 'position',
            'retire' => 'position', 'reinstate' => 'position',
            'roles' => 'position', 'permissions' => 'position',
            'addhistory' => 'history', 'edithistory' => 'history', 'deletehistory' => 'history',
        ];
        foreach ($actions as $action => $kind) {
            foreach ([0, 42] as $parkId) {
                $key = OfficerPosition::PermissionKeyFor($kind, $parkId);
                self::assertTrue(
                    PermissionRegistry::Exists($key),
                    "action {$action} (scope park={$parkId}) maps to undefined permission {$key}"
                );
                $expectedPrefix = $parkId > 0 ? 'park.' : 'kingdom.';
                self::assertStringStartsWith($expectedPrefix, $key,
                    "action {$action} must use a {$expectedPrefix}* key at that scope");
            }
        }
    }

    public function testListIsNotInAnUngatedAllowlist(): void
    {
        // Guards against a future "reads don't need gating" simplification.
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertMatchesRegularExpression(
            "/'list'\s*=>\s*'set'/",
            $src,
            'list must remain gated -- personaLabel falls back to GivenName + Surname'
        );
    }

    public function testUnknownActionStillFailsClosed(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringContainsString('Unknown action', $src,
            'an action absent from the map must be refused, not defaulted');
    }
}
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminGateTest.php`
Expected: FAIL — the map has no `transition`/`addhistory`/`edithistory`/`deletehistory` entries yet, and every existing entry is a hardcoded `kingdom.*` string rather than a `PermissionKeyFor` lookup.

- [ ] **Step 3: Rewrite the gate**

Replace the `$gate` array and the `checkPermissionOrAuthority` call. `$park_id` is already read just above it.

```php
        // Which permission FAMILY each action belongs to. The concrete key is resolved
        // per-scope by PermissionKeyFor, so a park-scoped request is checked against
        // park.officer.* rather than kingdom.officer.* -- checking a kingdom key against
        // a park id simply fails, which is what refused every park officer before.
        $action_kind = [
            'list'             => 'set',
            'transition'       => 'set',
            'vacate'           => 'vacate',
            'vacateholder'     => 'vacate',
            'vacateall'        => 'vacate',
            'createposition'   => 'position',
            'editposition'     => 'position',
            'reorderpositions' => 'position',
            'reclassify'       => 'position',
            'retire'           => 'position',
            'reinstate'        => 'position',
            'roles'            => 'position',
            'permissions'      => 'position',
            'addhistory'       => 'history',
            'edithistory'      => 'history',
            'deletehistory'    => 'history',
        ];

        if (!isset($action_kind[$action])) {
            echo json_encode(['status' => 1, 'error' => 'Unknown action']);
            exit;
        }

        $scope    = ($park_id > 0) ? 'park' : 'kingdom';
        $scope_id = ($park_id > 0) ? $park_id : $kingdom_id;

        // edithistory/deletehistory are deliberately looser HERE and stricter in the
        // domain: their methods authorize against the ROW's own kingdom and park, which
        // this controller does not know. This gate is a cheap pre-filter, not the
        // authority. Do not "fix" the apparent inconsistency by trusting the payload.
        if (!$this->Authorization->has_permission_or_authority(
            $uid,
            OfficerPosition::PermissionKeyFor($action_kind[$action], $park_id),
            $scope,
            $scope_id,
            AUTH_EDIT
        )) {
            echo json_encode(['status' => 5, 'error' => 'Unauthorized']);
            exit;
        }
```

Delete the old `$gate` array and its `isset($gate[$action])` check. Keep the existing `valid_id($kingdom_id)` guard above.

- [ ] **Step 4: Run it and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminGateTest.php`
Expected: PASS, 3 tests.

Then the mandatory routing grep:
```bash
grep -rnE "moPost\('|\\\$\.getJSON\(|\\\$\.post\(" orkui/template/revised-frontend/partials/_manage_officers.tpl
```
Every action name it posts must appear in `$action_kind` AND in the dispatch switch. Report the full result.

Then verify in the browser as a KINGDOM admin that Manage Officers still lists, assigns, and vacates. The park half cannot be verified until Task 8 gives the partial a `ParkId`.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.OfficerAdminAjax.php tests/Integration/OfficerAdminGateTest.php
git commit -m "Enhancement: the officer admin gate becomes scope-aware

Every action was gated on ('kingdom.officer.set','kingdom',\$kingdom_id), so a
park-only officer was refused at the controller before reaching the scope-aware
domain gate. That single hardcoded line is why park officers cannot use this
console, and it is the prerequisite for retiring the park edit pad.

list stays gated deliberately: personaLabel falls back to GivenName + Surname
when a persona is blank, so an ungated read emits real legal names."
```

---

## Task 2: Collapse `Occupants[]` to `Occupant`

One office holds one person as of Plan 1's Task 8, but `actionList` still emits a plural array for supporting positions and a singular object for crown. The JS normalizes the mismatch. The plural shape has no referent.

**Files:**
- Modify: `orkui/controller/controller.OfficerAdminAjax.php:300-312`
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl` — `:594-596`, `:714`, `:1011-1012`
- Test: `tests/Integration/OfficerAdminGateTest.php` (extend)

**Interfaces:**
- Produces: `actionList` emits `Occupant` (object or null) for every classification. No consumer reads `Occupants`.

- [ ] **Step 1: Write the failing test**

```php
    public function testActionListEmitsOnlyTheSingularOccupantKey(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringNotContainsString("'Occupants'", $src,
            'one office holds one person; the plural array has no referent');
        self::assertStringContainsString("'Occupant'", $src);
    }

    public function testNoTemplateConsumerReadsOccupants(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2)
            . '/orkui/template/revised-frontend/partials/_manage_officers.tpl');
        self::assertStringNotContainsString('Occupants', $tpl,
            'the normalizer, the vacancy count and the crown/supporting split all collapse together');
    }
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter Occupant tests/Integration/OfficerAdminGateTest.php`
Expected: FAIL — four consumers still exist.

- [ ] **Step 3: Collapse it**

In `actionList`, replace the supporting branch so both classifications assign a single occupant:

```php
            if ($pos['Classification'] === 'supporting') {
                // One office holds one person (Plan 1, Task 8). Take the first
                // occupied row; insertOfficerRow now refuses a second holder, so
                // there can only be one.
                $row = null;
                foreach (($supportingOcc[$pid] ?? []) as $candidate) {
                    if ((int)$candidate['MundaneId'] > 0) {
                        $row = $candidate;
                        break;
                    }
                }
                $base['Occupant'] = $row ? $this->occupant($row, $terms) : null;
                $supporting[] = $base;
            } else {
```

In `_manage_officers.tpl`, replace the normalizer at `:594-596`:

```javascript
	function occupantsOf(pos) {
		// One office, one person. Kept as a list-returning helper so the callers
		// that iterate do not all have to change shape.
		return (pos.Occupant && pos.Occupant.MundaneId) ? [pos.Occupant] : [];
	}
```

At `:1011-1012` the crown/supporting split becomes one line:

```javascript
			if (pos.Occupant && pos.Occupant.MundaneId) who = pos.Occupant.Persona;
```

At `:714`, the vacancy count reads `occupantsOf(pos).length` rather than `pos.Occupants.length`. **Re-grep before editing** — Plan 1 had four separate enumerations come up short:

```bash
grep -n "Occupants" orkui/template/revised-frontend/partials/_manage_officers.tpl orkui/controller/controller.OfficerAdminAjax.php
```

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminGateTest.php tests/Integration/OfficerRowShapeTest.php`
Expected: PASS. Then load Manage Officers in the browser and confirm crown and supporting cards both still show their holder and the vacancy count is right.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.OfficerAdminAjax.php orkui/template/revised-frontend/partials/_manage_officers.tpl tests/Integration/OfficerAdminGateTest.php
git commit -m "Enhancement: one office, one occupant, one shape

actionList emitted Occupants[] for supporting positions and Occupant for crown,
and the client normalized the difference. An office has held one person since
insertOfficerRow started refusing a second, so the plural array described
nothing. Four consumers collapse together."
```

---

## Task 3: Controller actions for transition and history

`TransitionOfficer` shipped with no controller action at all — it is reachable only over the JSON API. The wizard needs a route, and Correct the Rolls needs three.

**Files:**
- Modify: `orkui/controller/controller.OfficerAdminAjax.php` — dispatch switch + four action methods
- Test: `tests/Integration/OfficerAdminActionsTest.php` (create)

**Interfaces:**
- Consumes: `OfficerPosition::TransitionOfficer/AddHistoryTerm/EditHistoryTerm/DeleteHistoryTerm($request)`.
- Produces: actions `transition`, `addhistory`, `edithistory`, `deletehistory`.
  - `transition` POST: `ParkId, PositionId, MundaneId, OutgoingEndDate, OutgoingStartDate, TermStart, Note`
  - `addhistory` POST: `ParkId, PositionId, MundaneId, StartDate, EndDate, Note`
  - `edithistory` POST: `OfficerHistoryId, StartDate, EndDate, Note`
  - `deletehistory` POST: `OfficerHistoryId`

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

final class OfficerAdminActionsTest extends TestCase
{
    public function testTheFourNewActionsAreDispatchable(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['transition', 'addhistory', 'edithistory', 'deletehistory'] as $action) {
            self::assertMatchesRegularExpression("/case '{$action}':/", $src,
                "{$action} must have a dispatch case");
            self::assertMatchesRegularExpression("/'{$action}'\s*=>/", $src,
                "{$action} must have a gate entry, or it fails closed as Unknown action");
        }
    }

    public function testEveryActionForwardsTheSessionToken(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['actionTransition', 'actionAddHistory', 'actionEditHistory', 'actionDeleteHistory'] as $m) {
            $start = strpos($src, "function {$m}(");
            self::assertNotFalse($start, "{$m} must exist");
            $body = substr($src, $start, 1200);
            self::assertStringContainsString('$this->session->token', $body,
                "{$m} must forward the session token -- \$this->__session yields uid 0");
        }
    }

    public function testEditAndDeleteHistoryDoNotSendScope(): void
    {
        // Their domain methods authorize against the ROW's kingdom/park. Sending a
        // caller-supplied scope would invite someone to trust it later.
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        foreach (['actionEditHistory', 'actionDeleteHistory'] as $m) {
            $start = strpos($src, "function {$m}(");
            $body  = substr($src, $start, 1200);
            self::assertStringNotContainsString("'KingdomId'", $body,
                "{$m} must not pass a caller-supplied KingdomId");
            self::assertStringNotContainsString("'ParkId'", $body,
                "{$m} must not pass a caller-supplied ParkId");
        }
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminActionsTest.php`
Expected: FAIL — none of the four actions exist.

- [ ] **Step 3: Add the dispatch cases and methods**

Add to the switch:

```php
            case 'transition':    $this->actionTransition($kingdom_id, $park_id);
                break;
            case 'addhistory':    $this->actionAddHistory($kingdom_id, $park_id);
                break;
            case 'edithistory':   $this->actionEditHistory();
                break;
            case 'deletehistory': $this->actionDeleteHistory();
                break;
```

Add the four methods, following `actionSetOccupant`'s existing shape:

```php
    /**
     * Move an office from one holder to another, or fill a vacant one.
     *
     * The wizard posts here once, from its final step. Every date is optional:
     * the domain defaults OutgoingEndDate to today and TermStart to that end date,
     * and skips the ordering check entirely when the office is vacant.
     */
    private function actionTransition($kingdom_id, $park_id)
    {
        $r = $this->OfficerPosition->TransitionOfficer([
            'Token'             => $this->session->token,
            'KingdomId'         => $kingdom_id,
            'ParkId'            => $park_id,
            'PositionId'        => (int)($_POST['PositionId'] ?? 0),
            'MundaneId'         => (int)($_POST['MundaneId'] ?? 0),
            'OutgoingEndDate'   => trim((string)($_POST['OutgoingEndDate'] ?? '')),
            'OutgoingStartDate' => trim((string)($_POST['OutgoingStartDate'] ?? '')),
            'TermStart'         => trim((string)($_POST['TermStart'] ?? '')),
            'Note'              => trim((string)($_POST['Note'] ?? '')),
        ]);
        $this->emitServiceResult($r);
    }

    private function actionAddHistory($kingdom_id, $park_id)
    {
        $r = $this->OfficerPosition->AddHistoryTerm([
            'Token'      => $this->session->token,
            'KingdomId'  => $kingdom_id,
            'ParkId'     => $park_id,
            'PositionId' => (int)($_POST['PositionId'] ?? 0),
            'MundaneId'  => (int)($_POST['MundaneId'] ?? 0),
            'StartDate'  => trim((string)($_POST['StartDate'] ?? '')),
            'EndDate'    => trim((string)($_POST['EndDate'] ?? '')),
            'Note'       => trim((string)($_POST['Note'] ?? '')),
        ]);
        $this->emitServiceResult($r);
    }

    /**
     * Scope is deliberately absent. EditHistoryTerm reads the row's own kingdom and
     * park and gates on those; passing a caller-supplied scope here would be an
     * invitation to trust it.
     *
     * StartDate/EndDate use array_key_exists semantics in the domain: key present
     * (even empty) clears the date, key absent leaves it alone. Only forward keys
     * the client actually sent.
     */
    private function actionEditHistory()
    {
        $request = [
            'Token'            => $this->session->token,
            'OfficerHistoryId' => (int)($_POST['OfficerHistoryId'] ?? 0),
        ];
        foreach (['StartDate', 'EndDate', 'Note'] as $field) {
            if (array_key_exists($field, $_POST)) {
                $request[$field] = trim((string)$_POST[$field]);
            }
        }
        $this->emitServiceResult($this->OfficerPosition->EditHistoryTerm($request));
    }

    private function actionDeleteHistory()
    {
        $r = $this->OfficerPosition->DeleteHistoryTerm([
            'Token'            => $this->session->token,
            'OfficerHistoryId' => (int)($_POST['OfficerHistoryId'] ?? 0),
        ]);
        $this->emitServiceResult($r);
    }
```

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminActionsTest.php tests/Integration/OfficerAdminGateTest.php`
Expected: PASS.

Verify the route answers end to end with the dev stack up — an unauthenticated POST must be refused:
```bash
curl -s -X POST 'http://localhost:19080/index.php?Route=OfficerAdminAjax/officer/1/transition' -d 'PositionId=1&MundaneId=1'
```
Expected: a JSON refusal (`status` 5 for not-logged-in), not an HTML page or a 500.

- [ ] **Step 5: Commit**

```bash
git add orkui/controller/controller.OfficerAdminAjax.php tests/Integration/OfficerAdminActionsTest.php
git commit -m "Enhancement: routes for transition and history editing

TransitionOfficer shipped in Plan 1 with no controller action -- the console
could not call the feature it was built for. Adds it, plus the three history
write routes Correct the Rolls needs.

edithistory and deletehistory deliberately forward no scope: their domain
methods authorize against the row's own kingdom and park."
```

---

## Task 4: Roll-integrity guard

`AddHistoryTerm` can open a second term on an office that already has one, and `EditHistoryTerm` can reopen a closed one by clearing `EndDate`. Either produces two rows with `end_date IS NULL`, which reads as an office held by two people — the state Plan 1's backfill just repaired. Correct the Rolls is the surface where a person can trip it, so the guard lands before that UI does.

**Files:**
- Modify: `system/lib/ork3/class.OfficerPosition.php` — `AddHistoryTerm`, `EditHistoryTerm`
- Test: `tests/Integration/OfficerAuthorizationTest.php` (extend)

**Interfaces:**
- Produces: both methods return `InvalidParameter(null, 'That office already has an open term. Close it first.')` when the write would create a second open term for the same office+scope.

- [ ] **Step 1: Write the failing test**

Append to `OfficerAuthorizationTest` (it already has the fixture, MARKER, and seed helpers):

```php
    public function testAddHistoryTermRefusesASecondOpenTerm(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_a'];
        $first      = $this->seededMundanes[0];
        $second     = $this->seedMundane('second_open');
        $this->seededMundanes[] = $second;

        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];

        $a = $this->positions->AddHistoryTerm($base + ['MundaneId' => $first, 'StartDate' => '2026-01-01']);
        self::assertSame(0, (int)$a['Status'], $a['Error'] ?? '');

        $b = $this->positions->AddHistoryTerm($base + ['MundaneId' => $second, 'StartDate' => '2026-02-01']);
        self::assertSame(4, (int)$b['Status'],
            'two open terms on one office means it reads as held by two people');
    }

    public function testEditHistoryTermRefusesReopeningIntoASecondOpenTerm(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_b'];
        $former     = $this->seedMundane('former_reopen');
        $current    = $this->seedMundane('current_reopen');
        $this->seededMundanes[] = $former;
        $this->seededMundanes[] = $current;
        $base = ['Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
                 'PositionId' => $positionId];

        // A closed term, then an open one on the same office.
        $closed = $this->positions->AddHistoryTerm(
            $base + ['MundaneId' => $former, 'StartDate' => '2025-01-01', 'EndDate' => '2025-12-31']
        );
        self::assertSame(0, (int)$closed['Status'], $closed['Error'] ?? '');
        $this->positions->AddHistoryTerm($base + ['MundaneId' => $current, 'StartDate' => '2026-01-01']);

        $closedId = (int)$this->pdo->query(
            "SELECT officer_history_id FROM ork_officer_history
              WHERE position_id = {$positionId} AND end_date IS NOT NULL
              ORDER BY officer_history_id DESC LIMIT 1"
        )->fetchColumn();

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $closedId, 'EndDate' => '',
        ]);
        self::assertSame(4, (int)$r['Status'],
            'clearing EndDate must not create a second open term');
    }

    public function testEditHistoryTermStillAllowsClearingWhenNoOtherOpenTermExists(): void
    {
        $token      = $this->fixture->createAuthorizedOfficer();
        $positionId = $this->seededPositions['crown_a'];
        $who        = $this->seedMundane('lone_reopen');
        $this->seededMundanes[] = $who;

        $this->positions->AddHistoryTerm([
            'Token' => $token, 'KingdomId' => self::KINGDOM_ID, 'ParkId' => 0,
            'PositionId' => $positionId, 'MundaneId' => $who,
            'StartDate' => '2025-01-01', 'EndDate' => '2025-12-31',
        ]);
        $id = (int)$this->pdo->query(
            "SELECT officer_history_id FROM ork_officer_history
              WHERE position_id = {$positionId} AND mundane_id = {$who} LIMIT 1"
        )->fetchColumn();

        $r = $this->positions->EditHistoryTerm([
            'Token' => $token, 'OfficerHistoryId' => $id, 'EndDate' => '',
        ]);
        self::assertSame(0, (int)$r['Status'],
            'reopening the ONLY term must still work -- the guard is about a SECOND one');
    }
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter OpenTerm tests/Integration/OfficerAuthorizationTest.php`
Expected: the first two FAIL with Status 0 where 4 was expected. The third should already pass.

- [ ] **Step 3: Add the guard**

Add a private helper and call it from both methods, after their existing validation and before the write:

```php
    /**
     * True when this office+scope already has an open term other than $except_id.
     *
     * `end_date IS NULL` is what defines "current officer" everywhere in this
     * codebase, so a second open row makes one office read as held by two people --
     * the state the 2026-08-29 backfill was written to repair. Matches the legacy
     * position_id = 0 fallback the rest of the class uses.
     */
    private function hasOtherOpenTerm($kingdom_id, $park_id, $position_id, $canonical_key, $except_id = 0)
    {
        global $DB;
        $DB->Clear();
        $DB->ho_kid = (int) $kingdom_id;
        $DB->ho_pid = (int) $park_id;
        $DB->ho_pos = (int) $position_id;
        $DB->ho_role = (string) $canonical_key;
        $DB->ho_except = (int) $except_id;
        $r = $DB->DataSet(
            'SELECT officer_history_id FROM ' . DB_PREFIX . 'officer_history
              WHERE kingdom_id = :ho_kid AND park_id = :ho_pid
                AND ( position_id = :ho_pos OR ( position_id = 0 AND role = :ho_role ) )
                AND end_date IS NULL
                AND officer_history_id <> :ho_except
              LIMIT 1'
        );
        return ($r !== false && $r->size() > 0);
    }
```

In `AddHistoryTerm`, after the date validation and before the INSERT — only when the new term is open (no `EndDate`):

```php
        if ($end === '' && $this->hasOtherOpenTerm($kingdom_id, $park_id, $position_id, $position['CanonicalKey'])) {
            return InvalidParameter(null, 'That office already has an open term. Close it first.');
        }
```

In `EditHistoryTerm`, after the row is loaded and the resulting `end_date` is known — only when the edit results in an open term:

```php
        if ($resulting_end === '' && $this->hasOtherOpenTerm(
            (int)$row['kingdom_id'], (int)$row['park_id'], (int)$row['position_id'],
            (string)$row['role'], $history_id
        )) {
            return InvalidParameter(null, 'That office already has an open term. Close it first.');
        }
```

Note the `$except_id` on the edit path: a row must never block itself.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAuthorizationTest.php tests/Integration/OfficerHistoryBackfillTest.php tests/Integration/OfficerTransitionTest.php`
Expected: PASS. `TransitionOfficer` writes through its own path and must be unaffected — if it regressed, the guard is firing where it should not.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.OfficerPosition.php tests/Integration/OfficerAuthorizationTest.php
git commit -m "Bugfix: the rolls could record one office held by two people

AddHistoryTerm could open a second term on an office that already had one, and
EditHistoryTerm could reopen a closed term into the same state by clearing
EndDate. Since end_date IS NULL defines 'current officer' everywhere, either
produced exactly the condition the backfill was written to repair.

Reopening the ONLY term still works -- the guard is about a second one."
```

---

## Task 5: The transition wizard

**Files:**
- Create: `orkui/template/revised-frontend/partials/_officer_transition.tpl`
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl` — include it, add Transition/Appoint buttons, hide the officer list while the wizard is open
- Test: manual browser verification (this is markup and client JS; the server contract is covered by Tasks 3 and 4)

**Interfaces:**
- Consumes: the `transition` action (Task 3); `moPost`, `base()`, `searchUrl()`, `moConfirm`, `moShowNotice`, `esc`, `occupantsOf` from the host partial.
- Produces: `window.otOpen(positionId, mode)` where `mode` is `'transition'` (occupied) or `'appoint'` (vacant); `window.otClose()`.

- [ ] **Step 1: Create the partial**

`_officer_transition.tpl` — plain PHP, no include contract of its own (it renders inside `_manage_officers.tpl` and uses `MoConfig`). Structure:

```php
<?php
/* =====================================================================
   Officer Transition wizard — rendered INTO the Manage Officers modal body.
   ---------------------------------------------------------------------
   Not a second overlay. otOpen() hides #mo-cards and shows #ot-root inside
   the SAME modal, so there is one overlay and one scroll context, and the
   autocomplete positioner the host partial defines at :521 still applies.

   Steps: 1 close the outgoing term · 2 the incoming officer · 3 review.
   'appoint' mode skips step 1 entirely — the office is vacant, so there is
   no outgoing term, and TransitionOfficer's ordering check is already
   conditioned on there being an outgoing holder.
   ===================================================================== */
?>
<div id="ot-root" style="display:none">
  <button type="button" class="ot-back" id="ot-back">&lsaquo; Back to officers</button>
  <h3 class="ot-title" id="ot-title"></h3>
  <ol class="ot-steps" id="ot-steps" aria-label="Progress"></ol>
  <div class="ka-feedback ka-feedback-err" id="ot-error" style="display:none"></div>

  <section class="ot-step" id="ot-step-1"><!-- body given below --></section>
  <section class="ot-step" id="ot-step-2"><!-- body given below --></section>
  <section class="ot-step" id="ot-step-3"><!-- body given below --></section>

  <div class="ot-actions">
    <button type="button" class="kn-btn" id="ot-cancel">Cancel</button>
    <button type="button" class="kn-btn" id="ot-prev">&larr; Back</button>
    <button type="button" class="kn-btn kn-btn-primary" id="ot-next">Next &rarr;</button>
    <button type="button" class="kn-btn kn-btn-primary" id="ot-commit">Confirm Transition</button>
  </div>
</div>
```

**Step 1 body** shows the outgoing officer and, when their start date is unknown, says so and offers an optional field — never inventing one:

```html
<p class="ot-lede">
  <strong id="ot-outgoing-name"></strong> has served as
  <span id="ot-outgoing-office"></span> <span id="ot-outgoing-since"></span>.
</p>
<div class="ot-hint" id="ot-unknown-start" style="display:none">
  <i class="fas fa-info-circle" aria-hidden="true"></i>
  The ORK has no start date on file for this term. You can supply one now, or leave it blank.
</div>
<label for="ot-out-start">Took office</label>
<input type="text" id="ot-out-start" placeholder="unknown" autocomplete="off" />
<label for="ot-out-end">Term ended</label>
<input type="text" id="ot-out-end" autocomplete="off" />
```

**Step 2** is the incoming officer, reusing the host's search:

```html
<label for="ot-in-player">Incoming officer</label>
<input type="text" id="ot-in-player" placeholder="Search by persona..." autocomplete="off"
       role="combobox" aria-autocomplete="list" aria-expanded="false" aria-controls="ot-in-results" />
<input type="hidden" id="ot-in-id" value="" />
<div class="kn-ac-results" id="ot-in-results" role="listbox"></div>
<label for="ot-in-start">Takes office</label>
<input type="text" id="ot-in-start" autocomplete="off" />
<label for="ot-in-note">Note <span class="ka-hint">(optional)</span></label>
<textarea id="ot-in-note" rows="2" maxlength="500" placeholder="e.g. Reign 42, appointed mid-term..."></textarea>
```

**Step 3** renders a plain-language list of what will change, built from the collected values.

Dates use Flatpickr with `altInput: true` and `altFormat: 'F j, Y'` per house rule, storing `Y-m-d` in the underlying input. Because Flatpickr's `altInput` replaces the visible field, read the stored value from the original element — define this once and use it in both this task and Task 6:

```javascript
	// Flatpickr's altInput swaps in a display field; the real Y-m-d lives on the
	// original input. Returns '' when the field is empty, which every date on this
	// form is allowed to be.
	function otRaw(id) {
		var el = document.getElementById(id);
		return el && el.value ? el.value : '';
	}
```

Task 6 uses the identical helper named `crRaw`.

Copy the player-search wiring from the host partial's existing occupant search (`:1700-1760`) verbatim in shape — same `searchUrl()`, same `kn-ac-results` dropdown, same `tnFixedAcPosition(input, results)` call before opening.

- [ ] **Step 2: Wire `otOpen` / `otClose` and the commit**

```javascript
	window.otOpen = function (positionId, mode) {
		var pos = otFindPosition(positionId);
		if (!pos) { moShowNotice('Not Found', 'That office is no longer listed. Refresh and try again.'); return; }
		otState = { pos: pos, mode: mode, step: (mode === 'appoint') ? 2 : 1 };
		document.getElementById('mo-cards').style.display = 'none';
		document.getElementById('ot-root').style.display  = '';
		otRender();
	};

	window.otClose = function () {
		document.getElementById('ot-root').style.display  = 'none';
		document.getElementById('mo-cards').style.display = '';
		moRefresh();
	};

	function otCommit() {
		var s = otState, d = {
			PositionId: s.pos.PositionId,
			MundaneId:  document.getElementById('ot-in-id').value,
			TermStart:  otRaw('ot-in-start'),
			Note:       document.getElementById('ot-in-note').value
		};
		if (s.mode === 'transition') {
			d.OutgoingEndDate   = otRaw('ot-out-end');
			d.OutgoingStartDate = otRaw('ot-out-start');
		}
		// MoConfig.parkId does not exist until Task 8 adds it. The guard is
		// falsy-safe against undefined, so this is correct both before and after
		// that task -- the kingdom console simply never sets ParkId.
		if (MoConfig.parkId) { d.ParkId = MoConfig.parkId; }
		moPost('transition', d, function () { otClose(); });
	}
```

`moPost` already surfaces the server's verdict verbatim through `moShowNotice` and logs it — do not add a second error path.

- [ ] **Step 3: Replace the card buttons**

In the officer card renderer, the occupant controls become one button whose label depends on occupancy:

```javascript
		if (occ.length) {
			html += '<button type="button" class="mo-btn" onclick="otOpen(' + pos.PositionId + ',\'transition\')">Transition &rarr;</button>';
			html += '<button type="button" class="mo-btn" onclick="moVacate(' + pos.PositionId + ')">Vacate</button>';
		} else {
			html += '<button type="button" class="mo-btn" onclick="otOpen(' + pos.PositionId + ',\'appoint\')">Appoint &rarr;</button>';
		}
```

Include the partial from `_manage_officers.tpl`, immediately after `#mo-cards`:

```php
<?php include __DIR__ . '/_officer_transition.tpl'; ?>
```

- [ ] **Step 4: Verify in the browser**

With the dev stack up, as a kingdom admin: Kingdom Admin → Manage Officers.
1. An occupied office shows **Transition →**; clicking it swaps the panel in place with a back arrow, and step 1 names the sitting officer.
2. An officer with no recorded start shows the "no start date on file" hint rather than a guessed date.
3. Step 2's player search opens its dropdown correctly positioned (this is the `tnFixedAcPosition` path).
4. Step 3 lists the changes; Confirm commits and returns to the officer list with the new holder shown.
5. A vacant office shows **Appoint →** and opens at step 2 with no empty step 1.
6. A backdated Takes-office date on a vacant office is accepted (Plan 1 fixed this specifically).
7. Check dark mode and ≤768px width.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/partials/_officer_transition.tpl orkui/template/revised-frontend/partials/_manage_officers.tpl
git commit -m "Enhancement: the officer transition wizard

Three steps when the office is occupied, two when it is vacant. Renders into
the Manage Officers modal body rather than stacking a second overlay, so there
is one scroll context and the existing autocomplete positioner still applies.

Finally makes the backdated end date and the note reachable -- the console has
been collecting a note and discarding it since it shipped."
```

---

## Task 6: Correct the Rolls

**Files:**
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl` — a second tab, its list, and add/edit/delete controls

**Interfaces:**
- Consumes: `addhistory`, `edithistory`, `deletehistory` (Task 3); the existing `KingdomAjax/officerhistory` and `ParkAjax/officerhistory` reads.
- Produces: `window.crOpen()`, `window.crRefresh()`.

- [ ] **Step 1: Add the tab and list**

Two tabs above `#mo-cards-list`: **Positions** (the existing list) and **Correct the Rolls**. Name it that consistently — "History" is the read-only public tab and must not be confused with it.

The roll list groups terms by office, newest first, each row showing holder, start, end, note, and Edit / Delete. Reads come from the existing endpoint:

```javascript
	function crUrl() {
		return MoConfig.parkId
			? (UIR + 'ParkAjax/park/' + MoConfig.parkId + '/officerhistory')
			: (UIR + 'KingdomAjax/kingdom/' + MoConfig.kingdomId + '/officerhistory');
	}
```

- [ ] **Step 2: Wire the writes**

Edit and delete send only the row id — the domain resolves scope from the row:

```javascript
	function crSaveEdit(historyId) {
		moPost('edithistory', {
			OfficerHistoryId: historyId,
			StartDate: crRaw('cr-edit-start'),
			EndDate:   crRaw('cr-edit-end'),
			Note:      document.getElementById('cr-edit-note').value
		}, function () { crRefresh(); });
	}

	function crDelete(historyId) {
		moConfirm('Delete this term?',
			'This removes the record permanently. The audit log keeps a copy.',
			function () { moPost('deletehistory', { OfficerHistoryId: historyId }, function () { crRefresh(); }); });
	}
```

Add sends the full scope, because it creates a row that does not exist yet:

```javascript
	function crAdd() {
		var d = {
			PositionId: document.getElementById('cr-add-pos').value,
			MundaneId:  document.getElementById('cr-add-id').value,
			StartDate:  crRaw('cr-add-start'),
			EndDate:    crRaw('cr-add-end'),
			Note:       document.getElementById('cr-add-note').value
		};
		if (MoConfig.parkId) { d.ParkId = MoConfig.parkId; }
		moPost('addhistory', d, function () { crRefresh(); });
	}
```

- [ ] **Step 3: Verify in the browser**

1. The tab lists existing terms grouped by office.
2. Editing a term's dates saves and re-renders.
3. Deleting asks first (via `moConfirm`, not native `confirm`) and removes the row.
4. Adding a past term works; adding a **second open term** is refused with the server's message from Task 4 — this is the visible proof that guard is wired.
5. Dark mode and ≤768px.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/partials/_manage_officers.tpl
git commit -m "Enhancement: Correct the Rolls moves history editing into the admin

Add, edit and delete for officer history terms, inside the admin modal rather
than on the public profile page. Edit and delete send only the row id -- the
domain authorizes against the row's own kingdom and park."
```

---

## Task 7: The unknown-start-date nudge

The only consumer of the 2,506 NULL start dates Plan 1's backfill wrote, and the mechanism that turns the migration's honesty into data.

**Files:**
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl`

- [ ] **Step 1: Render the prompt**

In the officer card, when the occupant has a term whose start is unknown:

```javascript
		if (occ.length && !occ[0].TermStartRaw) {
			html += '<div class="mo-nudge">'
			     +  '<i class="fas fa-circle-question" aria-hidden="true"></i> Start date unknown '
			     +  '<button type="button" class="mo-linkbtn" onclick="moSetStart(' + pos.PositionId + ')">Set it</button>'
			     +  '</div>';
		}
```

`actionList`'s occupant DTO already carries `TermStartRaw` (ISO or empty), so no server change is needed.

- [ ] **Step 2: Wire the one-field editor**

It needs the history row id. Resolve it from the same `officerhistory` read Correct the Rolls uses — find the open term for that office and occupant — then post `edithistory` with only `StartDate`. Do not add a new endpoint for this.

- [ ] **Step 3: Verify in the browser**

Against a database with the backfill applied, an office whose holder has no recorded start shows the prompt; setting a date makes the prompt disappear and the date appear. An office with a known start shows no prompt.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/partials/_manage_officers.tpl
git commit -m "Enhancement: nudge for the start dates the backfill left unknown

The 2026-08-29 backfill wrote NULL for every start date rather than inferring
one from a bulk row-creation timestamp. This is what converts that honesty into
real data: the office card asks, once, per officer."
```

---

## Task 8: `$mo_park_id` scoping

**Files:**
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl` — include contract, `MoConfig`, every POST
- Test: `tests/Integration/OfficerAdminGateTest.php` (extend)

**Interfaces:**
- Produces: include contract `$mo_kingdom_id`, `$mo_park_id` (default 0), `$mo_can_manage`. `MoConfig.parkId` is emitted and every `moPost` carries `ParkId` when it is non-zero.

- [ ] **Step 1: Write the failing test**

```php
    public function testThePartialDeclaresAParkIdInItsIncludeContract(): void
    {
        $tpl = file_get_contents(dirname(__DIR__, 2)
            . '/orkui/template/revised-frontend/partials/_manage_officers.tpl');
        self::assertStringContainsString('$mo_park_id', $tpl,
            'one partial must serve both the kingdom and park consoles');
        self::assertMatchesRegularExpression('/parkId\s*:/', $tpl,
            'MoConfig must carry parkId so every POST can scope itself');
    }
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter ParkId tests/Integration/OfficerAdminGateTest.php`
Expected: FAIL — the contract is kingdom-only.

- [ ] **Step 3: Add the parameter**

```php
   INCLUDE CONTRACT (set these PHP locals before including):
     $mo_kingdom_id (int)  — the kingdom whose officer positions to manage
     $mo_park_id    (int)  — 0 for the kingdom console, or the park's id
     $mo_can_manage (bool) — must be truthy or this partial renders nothing
```

```php
$mo_park_id = (int)($mo_park_id ?? 0);
```

Emit it in `MoConfig` and add it to every `moPost` payload. The cleanest single point is inside `moPost` itself:

```javascript
	function moPost(action, data, onOk, onErr) {
		if (MoConfig.parkId) { data = $.extend({}, data, { ParkId: MoConfig.parkId }); }
		...
```

Leave `base()` and `searchUrl()` on `kingdomId` — the route is kingdom-keyed and scope travels in the body, which is what the controller reads. Player search stays kingdom-scoped because officers must be kingdom members regardless of park.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerAdminGateTest.php`
Expected: PASS. The kingdom console must be unchanged — `$mo_park_id` defaults to 0 and `MoConfig.parkId` is falsy, so no POST gains a `ParkId`.

- [ ] **Step 5: Commit**

```bash
git add orkui/template/revised-frontend/partials/_manage_officers.tpl tests/Integration/OfficerAdminGateTest.php
git commit -m "Enhancement: one Manage Officers partial serves both scopes

Adds \$mo_park_id to the include contract and threads ParkId through every POST,
so the same partial drives the kingdom console and the park one. The kingdom
path is unchanged: the parameter defaults to 0 and no request gains a ParkId."
```

---

## Task 9: Park admin console

**Not a port of the kingdom console.** `Admin_kingdom.tpl` is 446 lines and consumes `$AdminDashboard`, `$AdminInfo`, `$KingdomInfo` and `$kingdom_info` — a data contract with no park equivalent, including a dashboard built for kingdoms only. This is the legacy page's link set, restyled, plus an officers card.

**Files:**
- Create: `orkui/template/revised-frontend/Admin_park.tpl`
- Modify: `orkui/controller/controller.Admin.php:2535`
- Reference: `orkui/template/default/Admin_park.tpl` (the 55-line original — read it for the exact link set)

- [ ] **Step 1: Build the console**

Same links as the legacy page: Configure Park, Download Park Dataset, Create Player, Move Player, Merge Players, Reset Waivers (conditional on `$CanResetWaivers`), Schedule an Event, Create Tournament. **Drop "Set Park Officers"** — the officers card replaces it, and Task 10 retires that page.

Use the `.ka-*` classes `Admin_kingdom.tpl` already defines. Its only data dependency is `$ParkInfo`, which `Admin::admin('park')` already supplies.

The officers card:

```php
<?php
$mo_kingdom_id = (int)($ParkInfo['KingdomId'] ?? 0);
$mo_park_id    = (int)($ParkInfo['ParkId'] ?? 0);
$mo_can_manage = true; // the route's front-door check already gated this page
include __DIR__ . '/partials/_manage_officers.tpl';
?>
```

Reset Waivers keeps a confirmation, but via the revised-frontend helper — **not** jQuery UI `dialog()` and not native `confirm()`.

- [ ] **Step 2: Re-point the route**

`controller.Admin.php:2535`: `$this->template = 'Admin_park.tpl';` becomes `$this->template = '../revised-frontend/Admin_park.tpl';`

Leave the front-door authorization check at `:2283` exactly as it is — it accepts park standing alongside kingdom standing, which is what lets a Sheriff reach their own console.

- [ ] **Step 3: Verify in the browser**

**This is the verification Task 10 depends on.** As a **park-only** officer (not a kingdom admin):
1. `Admin/park/<id>` renders the new console.
2. The officers card lists that park's officers.
3. Transition and Vacate both succeed.
4. The same actions against a *different* park are refused.
5. Dark mode and ≤768px.

If any of 1–4 fails, **stop** — Task 10 must not proceed.

- [ ] **Step 4: Commit**

```bash
git add orkui/template/revised-frontend/Admin_park.tpl orkui/controller/controller.Admin.php
git commit -m "Enhancement: a revised-frontend Park admin console

Replaces the 55-line legacy link list. Not a port of the kingdom console --
that one needs a dashboard no park has. This is the same link set restyled,
plus an officers card hosting the shared Manage Officers partial scoped to the
park, which is what gives park officers a console at all."
```

---

## Task 10: Retire the four surfaces

**Do not start this task until Task 9's park-officer verification has passed.** This is the commit that makes a mistake unrecoverable without a revert.

**Files:**
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` — pad markup `:1709`, button `:326`, history write JS `:3513`/`:3565`/`:3626`
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` — pad markup `:2691`, button `:553`, history write JS mirror
- Modify: `orkui/template/revised-frontend/script/revised.js` — the `kn-editoff` IIFE at `:4966`, the `pk-editoff` IIFE at `:13223`
- Modify: `orkui/template/revised-frontend/style/revised.css` — `kn-editoff-*`, `pk-editoff-*` rules
- Modify: `orkui/controller/controller.KingdomAjax.php`, `controller.ParkAjax.php` — retire `setofficers`, `vacateofficer`, `{add,edit,delete}officerhistory`
- Modify: `orkui/controller/controller.Admin.php` — remove `setkingdomofficers`, `setparkofficers`
- Delete: `orkui/template/default/Admin_setofficers.tpl`
- Modify: `orkui/model/model.Principality.php` — remove dead `set_officers`
- Modify: `orkui/controller/controller.OfficerAdminAjax.php` — remove the `setoccupant` action
- Modify: `orkui/template/revised-frontend/partials/_manage_officers.tpl` — remove the `mo-occ-*` assign modal
- Test: `tests/Integration/OfficerSurfaceRemovalTest.php` (create)

- [ ] **Step 1: Write the failing test**

```php
<?php
declare(strict_types=1);
use PHPUnit\Framework\TestCase;

/**
 * Nothing may reference a retired officer write surface.
 *
 * Plan 1 shipped a commit deleting an action the console's only crown Vacate
 * button still called; every test stayed green. This is the check that would
 * have caught it.
 */
final class OfficerSurfaceRemovalTest extends TestCase
{
    /** @return list<array{0:string}> */
    public static function retiredTokenProvider(): array
    {
        return array_map(static fn ($t) => [$t], [
            'kn-editoff', 'pk-editoff',
            'setkingdomofficers', 'setparkofficers',
            'addofficerhistory', 'editofficerhistory', 'deleteofficerhistory',
        ]);
    }

    /** @dataProvider retiredTokenProvider */
    public function testNoLiveReferenceSurvives(string $token): void
    {
        $root = dirname(__DIR__, 2);
        $hits = [];
        $rii  = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/orkui'));
        foreach ($rii as $file) {
            if (!$file->isFile()) {
                continue;
            }
            if (!preg_match('/\.(php|tpl|js|css)$/', $file->getFilename())) {
                continue;
            }
            foreach (file($file->getPathname()) as $n => $line) {
                // Comments may still explain the removal; executable references may not.
                $trimmed = ltrim($line);
                if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')
                    || str_starts_with($trimmed, '/*') || str_starts_with($trimmed, '#')) {
                    continue;
                }
                if (str_contains($line, $token)) {
                    $hits[] = str_replace($root . '/', '', $file->getPathname()) . ':' . ($n + 1);
                }
            }
        }
        self::assertSame([], $hits, "retired surface '{$token}' still referenced in executable code");
    }

    public function testSetOccupantActionIsGone(): void
    {
        $src = file_get_contents(dirname(__DIR__, 2) . '/orkui/controller/controller.OfficerAdminAjax.php');
        self::assertStringNotContainsString("case 'setoccupant':", $src,
            'the wizard subsumes it; the domain method SetOccupant stays as an API verb');
    }
}
```

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerSurfaceRemovalTest.php`
Expected: FAIL, listing every live reference. That list is your work queue.

- [ ] **Step 3: Remove them, in this order**

Work back-to-front so nothing is left calling something already gone: **callers first, then endpoints, then markup, then CSS.**

1. Public history write JS in both page templates, and the controls that call it.
2. Both edit-pad buttons, then their markup, then their `revised.js` IIFEs, then their CSS.
3. The `mo-occ-*` assign modal and the `setoccupant` action.
4. `Admin/setkingdomofficers`, `Admin/setparkofficers`, and `default/Admin_setofficers.tpl`.
5. The Ajax endpoints: `setofficers`, `vacateofficer`, and the six `{add,edit,delete}officerhistory` actions.
6. `model.Principality::set_officers` (dead; no callers).

Keep `KingdomAjax/officerhistory` and `ParkAjax/officerhistory` — the **reads** — they still serve the public modal and Correct the Rolls.

Keep `Kingdom::SetOfficer`, `Kingdom::VacateOfficer`, `Park::SetOfficer`, `Park::VacateOfficer` — registered API verbs external clients may call.

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/OfficerSurfaceRemovalTest.php`
then `bin/run-unit-tests.sh`
Expected: PASS.

Then the routing grep for both remaining consoles:
```bash
grep -rnE "moPost\('|\\\$\.post\(|\\\$\.getJSON\(" orkui/template/revised-frontend/partials/_manage_officers.tpl orkui/template/revised-frontend/Kingdomnew_index.tpl orkui/template/revised-frontend/Parknew_index.tpl
```
Every action must still resolve. Report the full result.

Browser: the Kingdom profile and Park profile pages load with no console errors and no Edit Officers button; the officer history tab renders read-only; Manage Officers still works in both consoles.

- [ ] **Step 5: Commit**

```bash
git add -u orkui tests/Integration/OfficerSurfaceRemovalTest.php
git rm orkui/template/default/Admin_setofficers.tpl
git commit -m "Enhancement: officer writes have one home

Retires the two Edit Officers pads, the two legacy set-officers pages, the
public history write controls, and the assign modal the wizard replaced.
Officer writes had six homes; they now have one.

Safe because the gate became scope-aware first and a park-only officer was
verified able to manage their park through the console before anything was
deleted. The officerhistory READS stay -- the public modal still needs them."
```

---

## Task 11: `RevokeRole` row-scope check

Carried here only because it is a one-method domain fix that should not wait on unrelated UI work. It has no UI component and travels independently if this plan is cut short.

**Files:**
- Modify: `system/lib/ork3/class.RBACService.php` — `RevokeRole`
- Test: `tests/Integration/RbacAuthorizationTest.php` (extend)

- [ ] **Step 1: Write the failing test**

```php
    public function testRevokeRoleRefusesAUserRoleRowFromAnotherKingdom(): void
    {
        // Same defect class the history methods fixed: authorize against the ROW's
        // scope, not the caller's claim. A caller holding kingdom.auth.manage for
        // their own kingdom must not be able to revoke another kingdom's grant by id.
        $rbac  = new RBACService();
        $token = $this->fixture->createAuthorizedOfficer();

        $foreignUserRoleId = $this->seedUserRoleInKingdom(self::KINGDOM_ID + 1);

        $r = $rbac->RevokeRole(['Token' => $token, 'UserRoleId' => $foreignUserRoleId]);
        self::assertSame(5, (int)$r['Status'],
            'the row belongs to another kingdom; the caller administers only their own');
    }
```

Add a `seedUserRoleInKingdom(int $kingdomId): int` helper that inserts a MARKER-scoped `ork_user_role` row and records its id for `tearDown`.

- [ ] **Step 2: Run and confirm it fails**

Run: `vendor/bin/phpunit --no-coverage --filter RevokeRole tests/Integration/RbacAuthorizationTest.php`
Expected: FAIL with Status 0 — the revoke succeeds today.

- [ ] **Step 3: Add the check**

In `RevokeRole`, after resolving the actor and before revoking, read the target row's own scope and gate on that — mirroring `EditHistoryTerm`'s shape:

```php
        // Authorize against the ROW's scope, not the caller's claim. Without this a
        // caller holding kingdom.auth.manage for their own kingdom can revoke any
        // kingdom's grant by id.
        $DB->Clear();
        $DB->rr_id = $user_role_id;
        $row = $DB->DataSet(
            'SELECT kingdom_id, park_id FROM ' . DB_PREFIX . 'user_role
              WHERE user_role_id = :rr_id LIMIT 1'
        );
        if ($row === false || $row->size() === 0 || !$row->Next()) {
            return InvalidParameter(null, 'That role assignment does not exist.');
        }
        $row_kingdom = (int) $row->kingdom_id;
        if (!Ork3::$Lib->authorizationgate->checkPermissionOrAuthority(
            $actor_id, 'kingdom.auth.manage', 'kingdom', $row_kingdom, AUTH_CREATE)) {
            return NoAuthorization();
        }
```

- [ ] **Step 4: Run and confirm it passes**

Run: `vendor/bin/phpunit --no-coverage tests/Integration/RbacAuthorizationTest.php`
then `bin/run-unit-tests.sh`
Expected: PASS. Then verify in the browser that Kingdom Admin → Roles can still revoke a grant in its **own** kingdom.

- [ ] **Step 5: Commit**

```bash
git add system/lib/ork3/class.RBACService.php tests/Integration/RbacAuthorizationTest.php
git commit -m "Security: RevokeRole authorizes against the row, not the claim

A caller holding kingdom.auth.manage for their own kingdom could revoke a
user_role row belonging to any other kingdom by passing its id. Same defect
class EditHistoryTerm and DeleteHistoryTerm fixed, same fix."
```

---

## Task 12: Sign-off

**Files:** none. This task proves the plan and finds what it broke.

- [ ] **Step 1: Full suite**

Run: `bin/run-unit-tests.sh`. Report absolute totals and any failure.

- [ ] **Step 2: Every officer flow, both consoles, in a browser**

As a **kingdom** admin: list, transition an occupied office, appoint a vacant one, vacate, create/edit/reorder/retire/reinstate a position, add/edit/delete a history term, and set an unknown start date via the nudge.

As a **park-only** officer: the same officer flows in their own park, and refused in another park.

- [ ] **Step 3: Confirm nothing that was removed is reachable**

The Kingdom and Park profile pages load with no console errors, show no Edit Officers button, and their officer history tab is read-only. `Admin/setkingdomofficers` and `Admin/setparkofficers` no longer route.

- [ ] **Step 4: Report, do not fix**

Report every failure with evidence. Do not fix anything — the controller decides.
