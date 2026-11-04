# Monarchy Only Recommendations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let officers mark an award recommendation "Monarchy Only" so it is visible only to officers at the right scope (and its author), never to the recipient or the public.

**Architecture:** One new column (`ork_recommendations.monarchy_only`, 0/1/2) plus one domain class (`RecommendationVisibility`) that is the single owner of the visibility rule. Every recommendation reader in `system/lib/ork3/` routes through it — row filtering after the viewer-agnostic cache for the main reader, a SQL predicate for paged/counted readers. The viewer is resolved from a validated `Token`, never from `RequestedBy`. Controllers pass `$this->session->token`; UI adds a modal checkbox, a badge, and Recs Manager Hide/Unhide actions.

**Tech Stack:** PHP 8 (legacy ORK3, yapo/YapoDb), MariaDB (MyISAM `ork_recommendations`), PHPUnit integration tests against `ork_test`, plain-PHP `.tpl` templates, jQuery/vanilla JS in `revised-frontend`.

**Spec:** `docs/superpowers/specs/2026-10-15-monarchy-only-recommendations-design.md`

## Global Constraints

- Levels: `0` public · `1` park-level · `2` kingdom-level. Effective level = `1` when stored `2` AND `passed_to_local = 1`, else stored.
- "Officer" = `Court::canManage($uid, $kingdom_id, $park_id)` measured against the **recipient's** current kingdom/home park; parent-kingdom officers count for principality recipients.
- The recipient NEVER sees a hidden rec about themselves — this check runs before every other grant, including ORK admin and author.
- The author always sees their own hidden rec (unless they are the recipient).
- Viewer for hidden-rec visibility = `Ork3::$Lib->authorization->IsAuthorized($request['Token'])`. No/invalid token → hidden rows are invisible. `RequestedBy` is NEVER used for this.
- SQL lives only in `system/lib/ork3/`; controllers reach it only through `orkui/model`. Never stage `system/lib/ork3/class.Authorization.php`; never `git add -A`; run `git diff --cached --stat` before every commit.
- `.tpl` files are plain PHP (not Smarty). Tooltips use `data-tip`, never `title`. Dark mode via `html[data-theme="dark"]`. No native `alert/confirm/prompt`.
- Badge text: `Monarchy Only`. Tooltips: park → `Visible to park and kingdom officers`; kingdom → `Visible only to kingdom officers`.
- Create-time rejection message: `Only officers of this player's park or kingdom can mark a recommendation Monarchy Only.`
- Modal checkbox label `Monarchy Only`, helper text `Only officers will see this recommendation; the player will not.`
- yapo drops `null` assignments — assign ints. `$this->db->Clear()` before every raw `query/DataSet/Execute`. `DataSet()` results need `->Next()`.
- Commit messages: `Enhancement: …` style, ending with `Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>`.
- Run PHPUnit with: `vendor/bin/phpunit --testsuite integration --filter <TestName>` (needs the docker sandbox DB on :19307; `docker compose ps` to confirm it is up).

## Review Focus

1. **Forged viewer over the JSON service** — `orkservice/Json` call to `Report/PlayerAwardRecommendations` with `RequestedBy` = a monarch's id and no token must return no hidden rows. (Task 3 test `testForgedRequestedByWithoutTokenSeesNoHiddenRows`.)
2. **Recipient who is also an officer** — a Monarch recommended for an award with a kingdom-level hide must not see it anywhere (list, count, peer check). (Task 2 `testRecipientNeverSeesEvenIfOfficer`, Task 3 `testCountExcludesHiddenForRecipientOfficer`.)
3. **Pass down then un-pass** — kingdom hide → passed (park officers see) → un-passed (park officers lose it again) with no extra write. (Task 2 `testPassDownMakesKingdomHideParkLevelAndUnpassRestores`.)
4. **Recs Manager totals vs delivered rows** — a park officer paging a scope that contains kingdom-hidden clusters gets `Total` equal to the number of groups delivered. (Task 3 `testPageTotalMatchesDeliveredGroupsForParkOfficer`.)
5. **Hidden rec reason leaking to the public award note on court finalize.** (Task 5 `testAddAwardFromHiddenRecMarksCitationCleared`.)

---

### Task 1: Schema migration and test fixture support

**Files:**
- Create: `db-migrations/2026-10-15-recommendation-monarchy-only.sql`
- Modify: `tools/ork-db/manifests/migration-classification.json5` (add one entry before the closing `}` of the map)
- Modify: `tests/Support/CourtFixture.php` (createRecommendation overrides; new `createPlayerWithToken`, `insertScopedAuth`, `insertGlobalAdmin`, `setPassedToLocal`; cleanup of auth + session rows)
- Test: `tests/Integration/RecommendationMonarchyOnlySchemaTest.php`

**Interfaces:**
- Produces: column `ork_recommendations.monarchy_only TINYINT NOT NULL DEFAULT 0`, index `idx_recs_monarchy_only (monarchy_only)`.
- Produces (fixture): `CourtFixture::createRecommendation(int $mundaneId, int $kaId, int $awardId, array $overrides = []): int` now honours overrides `monarchy_only` (int, default 0), `passed_to_local` (int, default 0), `recommended_by_id` (int). `CourtFixture::createPlayerWithToken(string $tag, int $kingdomId, int $parkId): array{mundane_id:int, persona:string, token:string}`. `CourtFixture::insertScopedAuth(int $mundaneId, int $parkId, int $kingdomId, string $role = 'create'): int`. `CourtFixture::insertGlobalAdmin(int $mundaneId): int`. `CourtFixture::setPassedToLocal(int $recId, bool $passed): void`. `CourtFixture::secondParkId(int $kingdomId, int $excludeParkId): int`.

- [ ] **Step 1: Write the migration**

```sql
-- Monarchy Only recommendations: per-recommendation visibility restriction.
-- 0 = public, 1 = park-level (park + kingdom officers), 2 = kingdom-level
-- (kingdom officers only). A kingdom-level rec that is passed_to_local is read
-- as park-level by the app; nothing is rewritten on pass-down.
-- ork_recommendations is MyISAM: one ALTER, one table copy.
ALTER TABLE ork_recommendations
  ADD COLUMN IF NOT EXISTS monarchy_only TINYINT NOT NULL DEFAULT 0,
  ADD INDEX IF NOT EXISTS idx_recs_monarchy_only (monarchy_only);
```

- [ ] **Step 2: Classify it** — add to the map in `tools/ork-db/manifests/migration-classification.json5` (keep the trailing-comma style of neighbours; the last existing entry currently has no trailing comma, so add a comma to it):

```json5
    "2026-10-15-recommendation-monarchy-only.sql": { "class": "S", "render": "full", "notes": "Monarchy Only visibility level on recommendations; ADD COLUMN/INDEX IF NOT EXISTS, idempotent" }
```

- [ ] **Step 3: Apply to both local DBs** (see memory `reference_startup_commands.md` for the exact container/credentials; typical form):

```bash
for db in ork ork_test; do docker compose exec -T db mariadb -uroot -p"$MYSQL_ROOT_PASSWORD" "$db" < db-migrations/2026-10-15-recommendation-monarchy-only.sql; done
```

Verify: `SHOW COLUMNS FROM ork_recommendations LIKE 'monarchy_only'` returns one row in both DBs.

- [ ] **Step 4: Extend CourtFixture.** In `createRecommendation`, add defaults `'monarchy_only' => 0, 'passed_to_local' => 0` to the merged `$row`, add both columns to the INSERT column list and values. Add properties `/** @var list<int> */ private array $authIds = [];` and `private array $tokens = [];`. Add methods:

```php
    /** A player with a live ork_session token, so domain calls can IsAuthorized() them. */
    public function createPlayerWithToken(string $tag, int $kingdomId, int $parkId): array
    {
        $p = $this->createPlayer($tag, $kingdomId, $parkId);
        $token = md5(self::MARKER . $tag . bin2hex(random_bytes(8)));
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'mundane SET token = ?, penalty_box = 0, suspended = 0 WHERE mundane_id = ?')
            ->execute([$token, $p['mundane_id']]);
        $this->pdo->prepare(
            'INSERT IGNORE INTO ' . DB_PREFIX . 'session (mundane_id, token, created, last_seen, expires)
             VALUES (?, ?, NOW(), NOW(), DATE_ADD(NOW(), INTERVAL 72 HOUR))'
        )->execute([$p['mundane_id'], $token]);
        $this->tokens[] = $token;

        return $p + ['token' => $token];
    }

    public function insertScopedAuth(int $mundaneId, int $parkId, int $kingdomId, string $role = 'create'): int
    {
        $this->pdo->prepare(
            'INSERT INTO ' . DB_PREFIX . 'authorization
             (mundane_id, park_id, kingdom_id, event_id, unit_id, role, modified)
             VALUES (?, ?, ?, 0, 0, ?, NOW())'
        )->execute([$mundaneId, $parkId, $kingdomId, $role]);
        $id = (int) $this->pdo->lastInsertId();
        $this->authIds[] = $id;

        return $id;
    }

    public function insertGlobalAdmin(int $mundaneId): int
    {
        return $this->insertScopedAuth($mundaneId, 0, 0, 'admin');
    }

    public function setPassedToLocal(int $recId, bool $passed): void
    {
        $this->pdo->prepare('UPDATE ' . DB_PREFIX . 'recommendations SET passed_to_local = ? WHERE recommendations_id = ?')
            ->execute([$passed ? 1 : 0, $recId]);
    }

    public function secondParkId(int $kingdomId, int $excludeParkId): int
    {
        $st = $this->pdo->prepare(
            'SELECT park_id FROM ' . DB_PREFIX . 'park WHERE kingdom_id = ? AND park_id <> ? ORDER BY park_id ASC LIMIT 1'
        );
        $st->execute([$kingdomId, $excludeParkId]);

        return (int) $st->fetchColumn();
    }
```

In `cleanup()`, before deleting `mundane`: `$this->deleteIn('authorization', 'authorization_id', $this->authIds);` and, if `$this->tokens`, `DELETE FROM ork_session WHERE token IN (...)` via a prepared statement with placeholders; then reset `$this->authIds = $this->tokens = [];`. (Check `ork_authorization`'s PK column name with `SHOW COLUMNS` first — it is `authorization_id` in ork.sql.)

- [ ] **Step 5: Write the schema test**

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;

final class RecommendationMonarchyOnlySchemaTest extends TestCase
{
    private CourtFixture $fixture;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fixture = CourtFixture::create();
    }

    protected function tearDown(): void
    {
        if (isset($this->fixture)) {
            $this->fixture->cleanup();
        }
    }

    public function testColumnDefaultsToPublicAndFixtureCanSetIt(): void
    {
        $k  = $this->fixture->firstKingdomId();
        $p  = $this->fixture->firstParkId($k);
        $ka = $this->fixture->firstKingdomAwardId($k);
        $r  = $this->fixture->createPlayer('mo-recip', $k, $p)['mundane_id'];

        $plain  = $this->fixture->createRecommendation($r, (int) $ka['kingdomaward_id'], (int) $ka['award_id']);
        $hidden = $this->fixture->createRecommendation($r, (int) $ka['kingdomaward_id'], (int) $ka['award_id'], ['monarchy_only' => 2, 'rank' => 2]);

        $this->assertSame(0, (int) $this->fixture->fetchRecommendation($plain)['monarchy_only']);
        $this->assertSame(2, (int) $this->fixture->fetchRecommendation($hidden)['monarchy_only']);
    }
}
```

- [ ] **Step 6: Run** `vendor/bin/phpunit --testsuite integration --filter RecommendationMonarchyOnlySchemaTest` — Expected: PASS (run it once BEFORE Step 3 if you want to see it fail with "Unknown column").

- [ ] **Step 7: Commit**

```bash
git add db-migrations/2026-10-15-recommendation-monarchy-only.sql tools/ork-db/manifests/migration-classification.json5 tests/Support/CourtFixture.php tests/Integration/RecommendationMonarchyOnlySchemaTest.php
git diff --cached --stat
git commit -m "Enhancement: Add Monarchy Only visibility level to recommendations

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>"
```

---

### Task 2: RecommendationVisibility domain class

**Files:**
- Create: `system/lib/ork3/class.RecommendationVisibility.php` (auto-loaded by `startup.php` as `Ork3::$Lib->recommendationvisibility`; NOT added to `orkservice/Json/index.php`)
- Test: `tests/Integration/RecommendationVisibilityTest.php`

**Interfaces:**
- Consumes: column from Task 1; `Ork3::$Lib->court->canManage($uid, $kingdom_id, $park_id = 0): bool`; `Ork3::$Lib->authorization->IsAuthorized($token): int`; `HasAuthority($uid, AUTH_ADMIN, 0, AUTH_EDIT)`.
- Produces (all used by Tasks 3–6, exact names):
  - `const LEVEL_PUBLIC = 0; const LEVEL_PARK = 1; const LEVEL_KINGDOM = 2;`
  - `public static function effectiveLevel(int $stored, bool $passedToLocal): int`
  - `public static function effectiveLevelSql(string $recAlias): string`
  - `public function viewerFromToken($token): int`
  - `public function officerLevel(int $viewer, int $kingdomId, int $parkId): int` — 2 kingdom officer (or parent-kingdom officer), 1 park officer, 0 none
  - `public function canSee(int $viewer, array $rec): bool` — `$rec` keys: `MundaneId`, `RecommendedById`, `KingdomId`, `ParkId`, `MonarchyOnly`, `PassedToLocal`
  - `public function canManageHidden(int $viewer, array $rec): bool` — may Unhide (officer at the effective level, not merely author)
  - `public function filterRecommendations(array $response, int $viewer): array` — filters `$response['AwardRecommendations']`
  - `public function sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string`
  - `public function creatorLevel(int $creator, int $recipientId): int`
  - `public function kingdomOfPark(int $parkId): int`

- [ ] **Step 1: Write the failing test** (`tests/Integration/RecommendationVisibilityTest.php`)

```php
<?php

declare(strict_types=1);

namespace Tests\Integration;

use CourtFixture;
use PHPUnit\Framework\TestCase;
use RecommendationVisibility;

final class RecommendationVisibilityTest extends TestCase
{
    private CourtFixture $fx;
    private RecommendationVisibility $vis;
    private int $k = 0;
    private int $p = 0;
    private int $otherPark = 0;

    protected function setUp(): void
    {
        if (!ork3_test_db_available()) {
            $this->markTestSkipped('Sandbox database not reachable.');
        }
        $this->fx  = CourtFixture::create();
        $this->vis = new RecommendationVisibility();
        $this->k   = $this->fx->firstKingdomId();
        $this->p   = $this->fx->firstParkId($this->k);
        $this->otherPark = $this->fx->secondParkId($this->k, $this->p);
        if (!$this->otherPark) {
            $this->markTestSkipped('Sandbox kingdom needs two parks.');
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->fx)) {
            $this->fx->cleanup();
        }
    }

    private function rec(int $recipient, int $author, int $level, bool $passed = false): array
    {
        return [
            'MundaneId' => $recipient, 'RecommendedById' => $author,
            'KingdomId' => $this->k, 'ParkId' => $this->p,
            'MonarchyOnly' => $level, 'PassedToLocal' => $passed ? 1 : 0,
        ];
    }

    /** @return array{recipient:int, author:int, parkOfficer:int, otherParkOfficer:int, kingdomOfficer:int, seatMonarch:int, plain:int, admin:int} */
    private function cast(): array
    {
        $recipient = $this->fx->createPlayerWithToken('recip', $this->k, $this->p)['mundane_id'];
        $author    = $this->fx->createPlayerWithToken('author', $this->k, $this->p)['mundane_id'];
        $parkOff   = $this->fx->createPlayerWithToken('parkoff', $this->k, $this->p)['mundane_id'];
        $this->fx->insertScopedAuth($parkOff, $this->p, 0, 'edit');
        $otherOff  = $this->fx->createPlayerWithToken('otheroff', $this->k, $this->otherPark)['mundane_id'];
        $this->fx->insertScopedAuth($otherOff, $this->otherPark, 0, 'edit');
        $kOff      = $this->fx->createPlayerWithToken('koff', $this->k, $this->p)['mundane_id'];
        $this->fx->insertScopedAuth($kOff, 0, $this->k, 'edit');
        $seat      = $this->fx->createPlayerWithToken('seat', $this->k, $this->p)['mundane_id'];
        $this->fx->insertOfficer($seat, $this->k, 0, 'Monarch');
        $plain     = $this->fx->createPlayerWithToken('plain', $this->k, $this->p)['mundane_id'];
        $admin     = $this->fx->createPlayerWithToken('admin', $this->k, $this->p)['mundane_id'];
        $this->fx->insertGlobalAdmin($admin);

        return ['recipient' => $recipient, 'author' => $author, 'parkOfficer' => $parkOff,
            'otherParkOfficer' => $otherOff, 'kingdomOfficer' => $kOff, 'seatMonarch' => $seat,
            'plain' => $plain, 'admin' => $admin];
    }

    public function testEffectiveLevel(): void
    {
        $this->assertSame(0, RecommendationVisibility::effectiveLevel(0, true));
        $this->assertSame(1, RecommendationVisibility::effectiveLevel(1, false));
        $this->assertSame(2, RecommendationVisibility::effectiveLevel(2, false));
        $this->assertSame(1, RecommendationVisibility::effectiveLevel(2, true));
    }

    public function testPublicRecVisibleToEveryoneIncludingNoViewer(): void
    {
        $c = $this->cast();
        $this->assertTrue($this->vis->canSee(0, $this->rec($c['recipient'], $c['author'], 0)));
        $this->assertTrue($this->vis->canSee($c['recipient'], $this->rec($c['recipient'], $c['author'], 0)));
    }

    public function testParkLevelMatrix(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 1);
        $this->assertFalse($this->vis->canSee(0, $r));
        $this->assertFalse($this->vis->canSee($c['recipient'], $r));
        $this->assertTrue($this->vis->canSee($c['author'], $r));
        $this->assertTrue($this->vis->canSee($c['parkOfficer'], $r));
        $this->assertFalse($this->vis->canSee($c['otherParkOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['seatMonarch'], $r));
        $this->assertFalse($this->vis->canSee($c['plain'], $r));
        $this->assertTrue($this->vis->canSee($c['admin'], $r));
    }

    public function testKingdomLevelMatrix(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 2);
        $this->assertFalse($this->vis->canSee($c['recipient'], $r));
        $this->assertTrue($this->vis->canSee($c['author'], $r));
        $this->assertFalse($this->vis->canSee($c['parkOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canSee($c['seatMonarch'], $r));
        $this->assertFalse($this->vis->canSee($c['plain'], $r));
        $this->assertTrue($this->vis->canSee($c['admin'], $r));
    }

    public function testPassDownMakesKingdomHideParkLevelAndUnpassRestores(): void
    {
        $c = $this->cast();
        $this->assertTrue($this->vis->canSee($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 2, true)));
        $this->assertFalse($this->vis->canSee($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 2, false)));
    }

    public function testRecipientNeverSeesEvenIfOfficer(): void
    {
        $c = $this->cast();
        // The kingdom officer is the recipient; an author is someone else.
        $r = $this->rec($c['kingdomOfficer'], $c['author'], 2);
        $this->assertFalse($this->vis->canSee($c['kingdomOfficer'], $r));
        $r = $this->rec($c['admin'], $c['author'], 1);
        $this->assertFalse($this->vis->canSee($c['admin'], $r));
    }

    public function testCreatorLevel(): void
    {
        $c = $this->cast();
        $this->assertSame(2, $this->vis->creatorLevel($c['kingdomOfficer'], $c['recipient']));
        $this->assertSame(2, $this->vis->creatorLevel($c['seatMonarch'], $c['recipient']));
        $this->assertSame(1, $this->vis->creatorLevel($c['parkOfficer'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['otherParkOfficer'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['plain'], $c['recipient']));
        $this->assertSame(0, $this->vis->creatorLevel($c['recipient'], $c['recipient']));
    }

    public function testCanManageHiddenNeedsOfficeNotAuthorship(): void
    {
        $c = $this->cast();
        $r = $this->rec($c['recipient'], $c['author'], 2);
        $this->assertFalse($this->vis->canManageHidden($c['author'], $r));
        $this->assertFalse($this->vis->canManageHidden($c['parkOfficer'], $r));
        $this->assertTrue($this->vis->canManageHidden($c['kingdomOfficer'], $r));
        $this->assertTrue($this->vis->canManageHidden($c['parkOfficer'], $this->rec($c['recipient'], $c['author'], 1)));
    }

    public function testSqlPredicateAgreesWithCanSee(): void
    {
        $c  = $this->cast();
        $ka = $this->fx->firstKingdomAwardId($this->k);
        $ids = [];
        foreach ([0, 1, 2] as $lvl) {
            $ids[$lvl] = $this->fx->createRecommendation($c['recipient'], (int) $ka['kingdomaward_id'], (int) $ka['award_id'],
                ['monarchy_only' => $lvl, 'recommended_by_id' => $c['author'], 'rank' => 1 + $lvl]);
        }
        $passed = $this->fx->createRecommendation($c['recipient'], (int) $ka['kingdomaward_id'], (int) $ka['award_id'],
            ['monarchy_only' => 2, 'passed_to_local' => 1, 'recommended_by_id' => $c['author'], 'rank' => 4]);
        $all = array_merge(array_values($ids), [$passed]);

        foreach ($c as $role => $viewer) {
            $pred = $this->vis->sqlPredicate($viewer, 'r', 'm', [$this->k]);
            $st = $this->fx->pdo()->query(
                'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r
                 JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
                 WHERE r.recommendations_id IN (' . implode(',', $all) . ') AND ' . $pred
            );
            $sqlSees = array_map('intval', $st->fetchAll(\PDO::FETCH_COLUMN));
            sort($sqlSees);
            $phpSees = [];
            foreach ($all as $id) {
                $row = $this->fx->fetchRecommendation($id);
                if ($this->vis->canSee($viewer, [
                    'MundaneId' => (int) $row['mundane_id'], 'RecommendedById' => (int) $row['recommended_by_id'],
                    'KingdomId' => $this->k, 'ParkId' => $this->p,
                    'MonarchyOnly' => (int) $row['monarchy_only'], 'PassedToLocal' => (int) $row['passed_to_local'],
                ])) {
                    $phpSees[] = $id;
                }
            }
            sort($phpSees);
            $this->assertSame($phpSees, $sqlSees, "SQL and PHP disagree for $role");
        }
        $this->assertSame([$ids[0]], array_map('intval', $this->fx->pdo()->query(
            'SELECT r.recommendations_id FROM ' . DB_PREFIX . 'recommendations r JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
             WHERE r.recommendations_id IN (' . implode(',', $all) . ') AND ' . $this->vis->sqlPredicate(0, 'r', 'm', [$this->k])
        )->fetchAll(\PDO::FETCH_COLUMN)));
    }

    public function testViewerFromToken(): void
    {
        $p = $this->fx->createPlayerWithToken('tok', $this->k, $this->p);
        unset($_SESSION['is_authorized_mundane_id']);
        $this->assertSame($p['mundane_id'], $this->vis->viewerFromToken($p['token']));
        $this->assertSame(0, $this->vis->viewerFromToken(''));
        $this->assertSame(0, $this->vis->viewerFromToken(null));
        $this->assertSame(0, $this->vis->viewerFromToken(str_repeat('x', 32)));
    }
}
```

(Note: `fetchRecommendation` must return `recommended_by_id`, `monarchy_only`, `passed_to_local` — it does `SELECT *`; confirm, and widen it if not.)

- [ ] **Step 2: Run** `vendor/bin/phpunit --testsuite integration --filter RecommendationVisibilityTest` — Expected: FAIL, `Class "RecommendationVisibility" not found`.

- [ ] **Step 3: Implement** `system/lib/ork3/class.RecommendationVisibility.php`

```php
<?php

/**
 * Monarchy Only recommendations — the single owner of "who may see a hidden
 * recommendation". Every reader in system/lib/ork3 routes through here; nothing
 * else may restate the rule.
 *
 * Levels (ork_recommendations.monarchy_only): 0 public, 1 park-level (park and
 * kingdom officers), 2 kingdom-level (kingdom officers only). A kingdom-level rec
 * that has been passed to local is READ as park-level — the stored value is left
 * alone so un-passing restores it.
 *
 * "Officer" is Court::canManage measured against the RECIPIENT's kingdom and home
 * park; a principality's parent-kingdom officers count as kingdom officers.
 *
 * The viewer must come from a validated Token (viewerFromToken). This class is not
 * whitelisted in orkservice/Json, and callers must never feed it RequestedBy —
 * the JSON service lets anyone choose that value.
 */
class RecommendationVisibility extends Ork3
{
    public const LEVEL_PUBLIC  = 0;
    public const LEVEL_PARK    = 1;
    public const LEVEL_KINGDOM = 2;

    /** @var array<string,int> "viewer:kingdom:park" => officer level, per request */
    private $officerMemo = [];
    /** @var array<int,bool> */
    private $adminMemo = [];
    /** @var array<int,int> */
    private $parentMemo = [];

    public static function effectiveLevel(int $stored, bool $passedToLocal): int
    {
        if ($stored === self::LEVEL_KINGDOM && $passedToLocal) {
            return self::LEVEL_PARK;
        }

        return max(0, min(2, $stored));
    }

    public static function effectiveLevelSql(string $recAlias): string
    {
        return "(CASE WHEN $recAlias.monarchy_only = 2 AND $recAlias.passed_to_local = 1 THEN 1 ELSE $recAlias.monarchy_only END)";
    }

    public function viewerFromToken($token): int
    {
        if (!is_string($token) || $token === '') {
            return 0;
        }

        return (int) Ork3::$Lib->authorization->IsAuthorized($token);
    }

    private function isAdmin(int $viewer): bool
    {
        if ($viewer <= 0) {
            return false;
        }
        if (!isset($this->adminMemo[$viewer])) {
            $this->adminMemo[$viewer] = (bool) Ork3::$Lib->authorization->HasAuthority($viewer, AUTH_ADMIN, 0, AUTH_EDIT);
        }

        return $this->adminMemo[$viewer];
    }

    private function parentKingdom(int $kingdomId): int
    {
        if (!isset($this->parentMemo[$kingdomId])) {
            $this->db->Clear();
            $rs = $this->db->DataSet('SELECT parent_kingdom_id FROM ' . DB_PREFIX . 'kingdom WHERE kingdom_id = ' . $kingdomId . ' LIMIT 1');
            $this->parentMemo[$kingdomId] = ($rs && $rs->Next()) ? (int) $rs->parent_kingdom_id : 0;
        }

        return $this->parentMemo[$kingdomId];
    }

    public function kingdomOfPark(int $parkId): int
    {
        if ($parkId <= 0) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'park WHERE park_id = ' . $parkId . ' LIMIT 1');

        return ($rs && $rs->Next()) ? (int) $rs->kingdom_id : 0;
    }

    public function officerLevel(int $viewer, int $kingdomId, int $parkId): int
    {
        if ($viewer <= 0 || $kingdomId <= 0) {
            return 0;
        }
        $key = $viewer . ':' . $kingdomId . ':' . $parkId;
        if (isset($this->officerMemo[$key])) {
            return $this->officerMemo[$key];
        }
        $court = Ork3::$Lib->court;
        $parent = $this->parentKingdom($kingdomId);
        if ($court->canManage($viewer, $kingdomId, 0) || ($parent > 0 && $court->canManage($viewer, $parent, 0))) {
            $level = self::LEVEL_KINGDOM;
        } elseif ($parkId > 0 && $court->canManage($viewer, $kingdomId, $parkId)) {
            $level = self::LEVEL_PARK;
        } else {
            $level = 0;
        }

        return $this->officerMemo[$key] = $level;
    }

    public function canSee(int $viewer, array $rec): bool
    {
        $eff = self::effectiveLevel((int) ($rec['MonarchyOnly'] ?? 0), !empty($rec['PassedToLocal']));
        if ($eff === self::LEVEL_PUBLIC) {
            return true;
        }
        if ($viewer <= 0 || $viewer === (int) ($rec['MundaneId'] ?? 0)) {
            return false;
        }
        if ($this->isAdmin($viewer) || $viewer === (int) ($rec['RecommendedById'] ?? 0)) {
            return true;
        }

        return $this->officerLevel($viewer, (int) ($rec['KingdomId'] ?? 0), (int) ($rec['ParkId'] ?? 0)) >= $eff;
    }

    public function canManageHidden(int $viewer, array $rec): bool
    {
        $eff = self::effectiveLevel((int) ($rec['MonarchyOnly'] ?? 0), !empty($rec['PassedToLocal']));
        if ($viewer <= 0 || $viewer === (int) ($rec['MundaneId'] ?? 0)) {
            return false;
        }
        if ($this->isAdmin($viewer)) {
            return true;
        }

        return $this->officerLevel($viewer, (int) ($rec['KingdomId'] ?? 0), (int) ($rec['ParkId'] ?? 0)) >= max(1, $eff);
    }

    public function filterRecommendations(array $response, int $viewer): array
    {
        if (empty($response['AwardRecommendations'])) {
            return $response;
        }
        $response['AwardRecommendations'] = array_values(array_filter(
            $response['AwardRecommendations'],
            function ($rec) use ($viewer) {
                return $this->canSee($viewer, $rec);
            }
        ));

        return $response;
    }

    /**
     * SQL twin of canSee() for paged / counted readers. $kingdomIds is the set of
     * recipient kingdoms the query can return (its scope); kingdom-officer status is
     * resolved for exactly those. Park-officer status is resolved from the viewer's
     * own grants and seats, which is a handful of rows.
     */
    public function sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds): string
    {
        $eff = self::effectiveLevelSql($recAlias);
        if ($viewer <= 0) {
            return "($eff = 0)";
        }
        $notRecipient = "$recAlias.mundane_id <> $viewer";
        $parts = ["$eff = 0"];
        if ($this->isAdmin($viewer)) {
            $parts[] = $notRecipient;

            return '(' . implode(' OR ', $parts) . ')';
        }
        $parts[] = "($recAlias.recommended_by_id = $viewer AND $notRecipient)";

        $officerKingdoms = [];
        foreach (array_unique(array_map('intval', $kingdomIds)) as $kid) {
            if ($kid > 0 && $this->officerLevel($viewer, $kid, 0) === self::LEVEL_KINGDOM) {
                $officerKingdoms[] = $kid;
            }
        }
        if ($officerKingdoms) {
            $parts[] = "($notRecipient AND $mundaneAlias.kingdom_id IN (" . implode(',', $officerKingdoms) . '))';
        }

        $officerParks = [];
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT a.park_id, p.kingdom_id FROM ' . DB_PREFIX . 'authorization a
               JOIN ' . DB_PREFIX . 'park p ON p.park_id = a.park_id
              WHERE a.mundane_id = ' . $viewer . ' AND a.park_id > 0
             UNION
             SELECT o.park_id, o.kingdom_id FROM ' . DB_PREFIX . 'officer o
              WHERE o.mundane_id = ' . $viewer . ' AND o.park_id > 0
                AND o.role IN (\'Monarch\',\'Regent\',\'Prime Minister\')'
        );
        if ($rs) {
            while ($rs->Next()) {
                $pid = (int) $rs->park_id;
                if ($this->officerLevel($viewer, (int) $rs->kingdom_id, $pid) >= self::LEVEL_PARK) {
                    $officerParks[$pid] = $pid;
                }
            }
        }
        if ($officerParks) {
            $parts[] = "($notRecipient AND $eff = 1 AND $mundaneAlias.park_id IN (" . implode(',', $officerParks) . '))';
        }

        return '(' . implode(' OR ', $parts) . ')';
    }

    public function creatorLevel(int $creator, int $recipientId): int
    {
        if ($creator <= 0 || $recipientId <= 0 || $creator === $recipientId) {
            return 0;
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT kingdom_id, park_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $recipientId . ' LIMIT 1');
        if (!$rs || !$rs->Next()) {
            return 0;
        }

        return $this->officerLevel($creator, (int) $rs->kingdom_id, (int) $rs->park_id);
    }
}
```

Note on the kingdom-officer SQL clause: a kingdom officer sees eff 1 AND eff 2 in their kingdoms, so the clause does not test `$eff` (eff 0 is already covered).

- [ ] **Step 4: Run** the test — Expected: PASS (all 9 tests). If `testSqlPredicateAgreesWithCanSee` fails for `seatMonarch`, check that `insertOfficer` seated them at `park_id = 0`.

- [ ] **Step 5: Commit** (`git add system/lib/ork3/class.RecommendationVisibility.php tests/Integration/RecommendationVisibilityTest.php`; message `Enhancement: Decide who can see a Monarchy Only recommendation`).

---

### Task 3: Filter every Report reader

**Files:**
- Modify: `system/lib/ork3/class.Report.php` — `REC_CACHE_VERSION` (line ~21), `groupRecommendations` (~573), `PlayerAwardRecommendations` (~684–1019), `PlayerAwardRecommendationsPage` (~1033–1273), `PlayerAwardRecommendationsCount` (~1381), `DeletedAwardRecommendations` (~1432)
- Modify: `system/lib/ork3/class.Player.php` — `bust_player_award_recs_cache` (~2294; drop the Deleted bust), `GetSecondsForRecommendations` (~4965) split
- Test: `tests/Integration/RecommendationMonarchyOnlyReadersTest.php`

**Interfaces:**
- Consumes: Task 2 API.
- Produces: each row of `PlayerAwardRecommendations` carries `MonarchyOnly` (int, stored) and `MonarchyOnlyLevel` (int, effective). Groups from `groupRecommendations` carry `MonarchyOnlyLevel` (max effective level of members, int) and `MonarchyOnlyAll` (bool: every member hidden). All four readers accept `Token` in `$request` (and `PlayerAwardRecommendationsPage` forwards it to hydration). `Player::secondsForRecommendations(array $ids, int $viewer): array` (lowercase → not JSON-callable) holds the old body; `GetSecondsForRecommendations` becomes a wrapper that drops ids of hidden recs.

- [ ] **Step 1: Write the failing test** `tests/Integration/RecommendationMonarchyOnlyReadersTest.php`. Setup like Task 2 (`cast()` with recipient/author/parkOfficer/kingdomOfficer/plain — copy that helper verbatim, returning tokens too: have it store `createPlayerWithToken` results so tests can read `['token']`). Create in the sandbox kingdom: one public rec (rank 1), one park-level (rank 2), one kingdom-level (rank 3), all for the same recipient by `author`. Before each domain call `unset($_SESSION['is_authorized_mundane_id'])`. Tests:

```php
    private function idsFrom(array $resp): array
    {
        $ids = array_map(fn ($r) => (int) $r['RecommendationsId'], $resp['AwardRecommendations'] ?? []);
        sort($ids);
        return $ids;
    }

    public function testMainReaderFiltersPerTokenViewer(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $report = new \Report();
        $q = ['KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id']];

        $this->assertSame([$pub], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['plain']['token']])));
        $this->assertSame([$pub], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['recipient']['token']])));
        $this->assertSame([$pub, $park], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['parkOfficer']['token']])));
        $this->assertSame([$pub, $park, $king], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['kingdomOfficer']['token']])));
        $this->assertSame([$pub, $park, $king], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['author']['token']])));
        // Cached response must not leak between viewers: ask the plain player again after the officer warmed it.
        $this->assertSame([$pub], $this->idsFrom($report->PlayerAwardRecommendations($q + ['Token' => $c['plain']['token']])));
    }

    public function testForgedRequestedByWithoutTokenSeesNoHiddenRows(): void
    {
        [$c, $pub] = $this->seed();
        $resp = (new \Report())->PlayerAwardRecommendations([
            'KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id'],
            'RequestedBy' => $c['kingdomOfficer']['mundane_id'],
        ]);
        $this->assertSame([$pub], $this->idsFrom($resp));
    }

    public function testRowsCarryLevels(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $this->fx->setPassedToLocal($king, true);
        $resp = (new \Report())->PlayerAwardRecommendations([
            'KingdomId' => 0, 'ParkId' => 0, 'PlayerId' => $c['recipient']['mundane_id'], 'SkipCache' => 1,
            'Token' => $c['kingdomOfficer']['token'],
        ]);
        $byId = [];
        foreach ($resp['AwardRecommendations'] as $r) { $byId[(int) $r['RecommendationsId']] = $r; }
        $this->assertSame(0, $byId[$pub]['MonarchyOnlyLevel']);
        $this->assertSame(1, $byId[$park]['MonarchyOnlyLevel']);
        $this->assertSame(2, $byId[$king]['MonarchyOnly']);
        $this->assertSame(1, $byId[$king]['MonarchyOnlyLevel']);
    }

    public function testPageTotalMatchesDeliveredGroupsForParkOfficer(): void
    {
        [$c] = $this->seed();
        $resp = (new \Report())->PlayerAwardRecommendationsPage([
            'ParkId' => $this->p, 'KingdomId' => 0, 'Eligibility' => 'all',
            'Search' => $c['recipient']['persona'], 'Token' => $c['parkOfficer']['token'],
        ]);
        $this->assertSame(2, $resp['Total']);
        $this->assertCount(2, $resp['Groups']);
    }

    public function testCountExcludesHiddenForRecipientOfficer(): void
    {
        [$c] = $this->seed();
        $report = new \Report();
        $base = (int) $report->PlayerAwardRecommendationsCount(['KingdomId' => $this->k, 'Token' => $c['plain']['token']]);
        $asKing = (int) $report->PlayerAwardRecommendationsCount(['KingdomId' => $this->k, 'Token' => $c['kingdomOfficer']['token']]);
        $this->assertSame($base + 2, $asKing);
    }

    public function testDismissedPanelFilters(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET deleted_at = NOW(), deleted_by = {$c['author']['mundane_id']} WHERE recommendations_id IN ($pub,$park,$king)");
        $resp = (new \Report())->DeletedAwardRecommendations(['PlayerId' => $c['recipient']['mundane_id'], 'KingdomId' => 0, 'ParkId' => 0, 'Token' => $c['parkOfficer']['token']]);
        $this->assertSame([$pub, $park], $this->idsFrom($resp));
    }

    public function testPublicSecondsWrapperDropsHiddenRecs(): void
    {
        [$c, $pub, $park, $king] = $this->seed();
        $out = \Ork3::$Lib->player->GetSecondsForRecommendations([$pub, $park, $king], 0);
        $this->assertArrayNotHasKey($park, $out);
        $this->assertArrayNotHasKey($king, $out);
    }
```

`seed()` returns `[$cast, $pubId, $parkId, $kingId]`; the cast also needs the recipient's persona (from `createPlayerWithToken`). For the seconds test, insert one `ork_recommendation_seconds` row for each of the three recs (supporter = plain player) via PDO so the wrapper has something to drop; track and delete them in tearDown.

- [ ] **Step 2: Run** — Expected: FAIL (hidden rows returned; `MonarchyOnlyLevel` undefined).

- [ ] **Step 3: Bump the cache version.** `public const REC_CACHE_VERSION = 4;` with the existing comment updated: row shape gained `monarchy_only`.

- [ ] **Step 4: `PlayerAwardRecommendations`.**
  - At the top, after `$viewer_id`: `$hideViewer = Ork3::$Lib->recommendationvisibility->viewerFromToken($request['Token'] ?? '');` with a comment: hidden-rec visibility keys on the TOKEN, never RequestedBy (JSON callers choose RequestedBy).
  - Both `return $this->applyViewerFlags(...)` calls become `return $this->applyViewerFlags(Ork3::$Lib->recommendationvisibility->filterRecommendations($X, $hideViewer), $viewer_id);` (X = `$cache` / `$cached`). Filtering must come BEFORE `applyViewerFlags` because masking nulls `RecommendedById`, which the author rule reads.
  - SELECT: add `recs.monarchy_only,` after `recs.passed_to_local_at,`.
  - Raw-row whitelist: add `'monarchy_only' => (int)$r->monarchy_only,` after `passed_to_local_at`.
  - Output array: after `'PassedToLocalAt' => ...` add:
    ```php
                    'MonarchyOnly'      => (int)$row->monarchy_only,
                    'MonarchyOnlyLevel' => RecommendationVisibility::effectiveLevel((int)$row->monarchy_only, (int)$row->passed_to_local === 1),
    ```
  - Seconds fetch (line ~999): `Ork3::$Lib->player->secondsForRecommendations($rec_ids, 0)`.

- [ ] **Step 5: `groupRecommendations`.** In the new-group initializer add `'_moMax' => 0, '_moAll' => true,`. In the per-member loop add:
  ```php
            $moLevel = (int)($rec['MonarchyOnlyLevel'] ?? 0);
            if ($moLevel > $g['_moMax']) {
                $g['_moMax'] = $moLevel;
            }
            if ($moLevel === 0) {
                $g['_moAll'] = false;
            }
  ```
  In the finalize loop: `$groups[$k]['MonarchyOnlyLevel'] = $g['_moMax']; $groups[$k]['MonarchyOnlyAll'] = $g['_moMax'] > 0 && $g['_moAll'];` and add `$groups[$k]['_moMax'], $groups[$k]['_moAll']` to the `unset(...)`.

- [ ] **Step 6: `PlayerAwardRecommendationsPage`.** After `$scope` is built:
  ```php
        // Monarchy Only: the same rule PlayerAwardRecommendations applies per row, as SQL,
        // so the paging/total and the hydrated rows agree (else batches come back short).
        $vis        = Ork3::$Lib->recommendationvisibility;
        $hideViewer = $vis->viewerFromToken($request['Token'] ?? '');
        if (valid_id($request['KingdomId'] ?? 0)) {
            $scopeKids = Ork3::$Lib->kingdom->GetStatsKingdomIds($request['KingdomId']);
        } else {
            $scopeKids = [$vis->kingdomOfPark((int)($request['ParkId'] ?? 0))];
        }
        $visClause = ' AND ' . $vis->sqlPredicate($hideViewer, 'recs', 'm', $scopeKids);
  ```
  - Append `$visClause` to `$base` (after `$scope . $whereSql`).
  - In `$supportSub`, both correlated subqueries: add `AND ' . $vis->sqlPredicate($hideViewer, 'r2', 'm', $scopeKids) . '` to the r2 one and the same with `'r3'` to the r3 one. (Their recipient is `recs.mundane_id`, which is `m`, so `m` is the right mundane alias.) Build these strings before `$supportSub` as `$visR2`/`$visR3`.
  - Hydration id lookup `$ir`: append `$visClause` inside the WHERE (it already joins `m`).
  - Hydration call: add `'Token' => $request['Token'] ?? '',`.

- [ ] **Step 7: `PlayerAwardRecommendationsCount`.** The cached count becomes PUBLIC-only: add `AND recs.monarchy_only = 0` to `$where` (comment: hidden recs are counted per viewer below, uncached, so the shared cache entry never depends on who warmed it). Replace the final `return` with:
  ```php
        $n = (int) Ork3::$Lib->ghettocache->cache(__CLASS__ . '.' . __FUNCTION__, $key, $n);
        return $n + $this->hiddenRecommendationsCount($kidList, $request);
  ```
  and the cache-hit branch with `return (int)$cache + $this->hiddenRecommendationsCount($kidList, $request);`. Add:
  ```php
    // The viewer-dependent half of the tab badge: Monarchy Only recs this TOKEN's
    // viewer may see. Never cached (the predicate is per viewer); cheap because
    // idx_recs_monarchy_only narrows to the few hidden rows.
    private function hiddenRecommendationsCount(string $kidList, array $request): int
    {
        $vis    = Ork3::$Lib->recommendationvisibility;
        $viewer = $vis->viewerFromToken($request['Token'] ?? '');
        if ($viewer <= 0) {
            return 0;
        }
        $where = "recs.monarchy_only > 0 AND m.kingdom_id IN ($kidList) AND (m.suspended IS NULL OR m.suspended = 0)"
            . " AND (recs.deleted_by IS NULL OR recs.deleted_by = 0)"
            . ' AND ' . $vis->sqlPredicate($viewer, 'recs', 'm', array_map('intval', explode(',', $kidList)));
        if (!empty($request['RecommendedBy'])) {
            $where .= ' AND recs.recommended_by_id = ' . (int)$request['RecommendedBy'];
        }
        $this->db->Clear();
        $r = $this->db->query('SELECT COUNT(*) AS n FROM ' . DB_PREFIX . 'recommendations recs JOIN ' . DB_PREFIX . "mundane m ON m.mundane_id = recs.mundane_id WHERE $where");

        return ($r !== false && $r->size() > 0 && $r->next()) ? (int)$r->n : 0;
    }
  ```

- [ ] **Step 8: `DeletedAwardRecommendations`.** It becomes uncached (the panel is officer-only and click-to-open; a per-viewer predicate cannot share a cache entry and could not be busted). Remove `$cacheable`, `$key`, the cache-get block and the final cache write (just `return $response;`); update the docblock sentence about caching. Compute `$scopeKids` alongside `$location_clause`, last match wins exactly as that clause does: KingdomId → `GetStatsKingdomIds(KingdomId)`; ParkId → `[$vis->kingdomOfPark(ParkId)]`; PlayerId → the recipient's kingdom (`$this->db->Clear(); DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . (int)PlayerId)` → `[(int)kingdom_id]`). Then add `' AND ' . $vis->sqlPredicate($vis->viewerFromToken($request['Token'] ?? ''), 'recs', 'm', $scopeKids)` to the WHERE after `$location_clause`. In `Player::bust_player_award_recs_cache`, delete the `Report.DeletedAwardRecommendations` bust block and its comment.

- [ ] **Step 9: Seconds split** in `class.Player.php`: rename the existing body to `public function secondsForRecommendations($recommendation_ids, $viewer_id)` (lowercase first letter — JsonServer refuses it). Add:
  ```php
    /**
     * JSON-exposed. Drops the seconds of Monarchy Only recommendations: the caller
     * here is unauthenticated and chooses the ids. In-process readers that have
     * already applied RecommendationVisibility call secondsForRecommendations().
     */
    public function GetSecondsForRecommendations($recommendation_ids, $viewer_id)
    {
        $ids = array_filter(array_map('intval', (array)$recommendation_ids));
        if (!$ids) {
            return [];
        }
        $this->db->Clear();
        $rs = $this->db->DataSet('SELECT recommendations_id FROM ' . DB_PREFIX . 'recommendations WHERE monarchy_only = 0 AND recommendations_id IN (' . implode(',', $ids) . ')');
        $public = [];
        if ($rs) {
            while ($rs->Next()) {
                $public[] = (int)$rs->recommendations_id;
            }
        }
        return $public ? $this->secondsForRecommendations($public, $viewer_id) : [];
    }
  ```

- [ ] **Step 10: Run** the readers test plus the existing suites that touch these readers: `vendor/bin/phpunit --testsuite integration --filter 'RecommendationMonarchyOnlyReadersTest|RecommendationsDismissedTest|PassDownNotificationTest|ReportDomainAuthTest|CourtThread0Test'` — Expected: all PASS. If `RecommendationsDismissedTest` asserted Deleted caching/busting, update that assertion to the uncached behaviour and say so in the commit body.

- [ ] **Step 11: Commit** — `Enhancement: Hide Monarchy Only recommendations from readers who may not see them`.

---

### Task 4: Player write paths (create, hide/unhide, second, peer check)

**Files:**
- Modify: `system/lib/ork3/class.Player.php` — `AddAwardRecommendation` (~4131), new `SetRecommendationMonarchyOnly` (place after `SetRecommendationPassedToLocal`, ~4482), `AddSecondToRecommendation` (~4682), `GetPeerAwardRecommendations` (~4922)
- Modify: `orkui/model/model.Player.php` — new `set_recommendation_monarchy_only`, new `monarchy_only_level`; `get_peer_award_recommendations` gains `$token`
- Test: `tests/Integration/RecommendationMonarchyOnlyWritesTest.php`

**Interfaces:**
- Consumes: Task 2 (`creatorLevel`, `canSee`, `canManageHidden`, `sqlPredicate`, `viewerFromToken`).
- Produces:
  - `AddAwardRecommendation($request)` honours `$request['MonarchyOnly']` (truthy).
  - `Player::SetRecommendationMonarchyOnly($request)` — `Token`, `RecommendationsId`, `Hidden` (0|1). Returns `Success(...)` / `NoAuthorization()` / `InvalidParameter(...)`.
  - `Player::GetPeerAwardRecommendations($mundane_id, $kingdomaward_id, $rank, $exclude_mundane_id, $token = '')`.
  - `Model_Player::set_recommendation_monarchy_only(array $request): array`
  - `Model_Player::monarchy_only_level(int $uid, int $kingdom_id, int $park_id): int` → `Ork3::$Lib->recommendationvisibility->officerLevel(...)` (for template gating in Task 6)
  - `Model_Player::get_peer_award_recommendations($mundane_id, $kingdomaward_id, $rank, $exclude_mundane_id, $token = '')`

- [ ] **Step 1: Write the failing tests** (`RecommendationMonarchyOnlyWritesTest`, same setup/cast as Task 3; `unset($_SESSION['is_authorized_mundane_id'])` before each domain call; read back rows with `$this->fx->pdo()` and track created rec ids for cleanup by deleting `WHERE mundane_id = recipient` in tearDown):

```php
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
        $set = fn ($tok, $hidden) => \Ork3::$Lib->player->SetRecommendationMonarchyOnly(['Token' => $tok, 'RecommendationsId' => $id, 'Hidden' => $hidden]);

        $this->assertNotSame(0, $set($c['plain']['token'], 1)['Status']);
        $this->assertSame(0, $set($c['parkOfficer']['token'], 1)['Status']);
        $this->assertSame(1, $this->level($id));
        $this->assertSame(0, $set($c['kingdomOfficer']['token'], 1)['Status']);
        $this->assertSame(2, $this->level($id));
        // A park officer re-hiding must never DOWNGRADE a kingdom hide.
        $this->fx->setPassedToLocal($id, true);
        $this->assertSame(0, $set($c['parkOfficer']['token'], 1)['Status']);
        $this->assertSame(2, $this->level($id));
        $this->fx->setPassedToLocal($id, false);
        // Park officer cannot unhide a kingdom-level hide; kingdom officer can.
        $this->assertNotSame(0, $set($c['parkOfficer']['token'], 0)['Status']);
        $this->assertSame(0, $set($c['kingdomOfficer']['token'], 0)['Status']);
        $this->assertSame(0, $this->level($id));
    }

    public function testCannotSecondAHiddenRecYouCannotSee(): void
    {
        $c  = $this->cast();
        $id = $this->publicRec($c);
        $this->fx->pdo()->exec('UPDATE ' . DB_PREFIX . "recommendations SET monarchy_only = 2 WHERE recommendations_id = $id");
        $r = \Ork3::$Lib->player->AddSecondToRecommendation(['Token' => $c['plain']['token'], 'RecommendationsId' => $id, 'Notes' => 'x']);
        $this->assertNotSame(0, $r['Status']);
    }

    public function testPeerCheckHidesHiddenRecsFromNonOfficers(): void
    {
        $c  = $this->cast();
        $ka = $this->fx->firstKingdomAwardId($this->k);
        $this->fx->createRecommendation($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], (int)$ka['award_id'],
            ['monarchy_only' => 2, 'recommended_by_id' => $c['author']['mundane_id'], 'rank' => 1]);
        $p = \Ork3::$Lib->player;
        $this->assertCount(0, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['plain']['mundane_id'], $c['plain']['token']));
        $this->assertCount(0, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['plain']['mundane_id']));
        $this->assertCount(1, $p->GetPeerAwardRecommendations($c['recipient']['mundane_id'], (int)$ka['kingdomaward_id'], 1, $c['kingdomOfficer']['mundane_id'], $c['kingdomOfficer']['token']));
    }
```

Helpers: `req($token, $recipient, $extra = [])` returns `['Token'=>$token,'MundaneId'=>$recipient,'KingdomAwardId'=>$kaid,'Rank'=>'', 'Reason'=>'mo test'] + $extra` using a **non-ladder custom award** kingdomaward so repeated recs never trip the duplicate check — find one with `SELECT ka.kingdomaward_id FROM ork_kingdomaward ka JOIN ork_award a ON a.award_id = ka.award_id WHERE ka.kingdom_id = ? AND a.is_ladder = 0 AND a.is_title = 0 LIMIT 1` (skip the test if none). `latestLevel($recipient)` returns `monarchy_only` of the newest rec for that recipient or `-1` if none. `level($id)` reads one row. `publicRec($c)` creates a public rec authored by `author` via the fixture.

- [ ] **Step 2: Run** — Expected: FAIL (`SetRecommendationMonarchyOnly` undefined; level stays 0; non-officer accepted).

- [ ] **Step 3: `AddAwardRecommendation`.** Immediately after the `$recipient = array(...)` line add:
  ```php
        // Monarchy Only: the level comes from the creator's HIGHEST authority over the
        // recipient (kingdom officer → 2, park officer → 1). A request for it from
        // anyone else is refused outright rather than silently saved as public.
        $monarchyOnly = 0;
        if (!empty($request['MonarchyOnly'])) {
            $monarchyOnly = Ork3::$Lib->recommendationvisibility->creatorLevel((int)$mundane_id, (int)$request['MundaneId']);
            if ($monarchyOnly === 0) {
                return InvalidParameter("Only officers of this player's park or kingdom can mark a recommendation Monarchy Only.");
            }
        }
  ```
  In the yapo save block add `$awardRec->monarchy_only = $monarchyOnly;` after `mask_giver`.

- [ ] **Step 4: `SetRecommendationMonarchyOnly`.**
  ```php
    // Monarchy Only toggle from the Recommendations Manager. Hide: the level is the
    // actor's highest authority over the recipient, and never lowers an existing
    // hide (GREATEST) — a park officer re-hiding a passed-down kingdom rec must not
    // turn it into a park-level one that un-passing could no longer restore.
    // Unhide: only an officer at the rec's effective level (authorship is not
    // enough to lift an officer's hide).
    public function SetRecommendationMonarchyOnly($request)
    {
        if (($mundane_id = (int)Ork3::$Lib->authorization->IsAuthorized($request['Token'] ?? '')) === 0) {
            return NoAuthorization();
        }
        $rec_id = (int)($request['RecommendationsId'] ?? 0);
        if (!$rec_id) {
            return InvalidParameter();
        }
        $this->db->Clear();
        $rs = $this->db->DataSet(
            'SELECT r.mundane_id, r.recommended_by_id, r.monarchy_only, r.passed_to_local, m.kingdom_id, m.park_id
               FROM ' . DB_PREFIX . 'recommendations r
               JOIN ' . DB_PREFIX . 'mundane m ON m.mundane_id = r.mundane_id
              WHERE r.recommendations_id = ' . $rec_id . ' LIMIT 1'
        );
        if (!$rs || !$rs->Next()) {
            return InvalidParameter('Recommendation not found.');
        }
        $rec = [
            'MundaneId'       => (int)$rs->mundane_id,
            'RecommendedById' => (int)$rs->recommended_by_id,
            'KingdomId'       => (int)$rs->kingdom_id,
            'ParkId'          => (int)$rs->park_id,
            'MonarchyOnly'    => (int)$rs->monarchy_only,
            'PassedToLocal'   => (int)$rs->passed_to_local,
        ];
        $vis = Ork3::$Lib->recommendationvisibility;
        if (!empty($request['Hidden'])) {
            $level = $vis->creatorLevel($mundane_id, $rec['MundaneId']);
            if ($level === 0 || ($rec['MonarchyOnly'] > 0 && !$vis->canSee($mundane_id, $rec))) {
                return NoAuthorization("Only officers of this player's park or kingdom can mark a recommendation Monarchy Only.");
            }
            $set = 'monarchy_only = GREATEST(monarchy_only, ' . $level . ')';
        } else {
            if ($rec['MonarchyOnly'] === 0) {
                return Success('Recommendation is already visible.');
            }
            if (!$vis->canManageHidden($mundane_id, $rec)) {
                return NoAuthorization('Only an officer who can see this recommendation at its level can make it visible.');
            }
            $set = 'monarchy_only = 0';
        }
        $this->db->Clear();
        $this->db->Execute('UPDATE ' . DB_PREFIX . 'recommendations SET ' . $set . ' WHERE recommendations_id = ' . $rec_id);

        $this->bust_player_award_recs_cache($rec['MundaneId']);
        if (isset(Ork3::$Lib->dangeraudit)) {
            Ork3::$Lib->dangeraudit->audit(__CLASS__ . '::' . __FUNCTION__, $request, 'Player', $rec['MundaneId'], ['monarchy_only' => !empty($request['Hidden']) ? 1 : 0]);
        }
        return Success('Recommendation visibility updated.');
    }
  ```
  (Check `NoAuthorization()` accepts a detail string — grep its definition in `system/lib/`; if it takes none, use `InvalidParameter($msg)` for the two refusal messages and adjust the test's `assertNotSame(0, …)` — it already tolerates either.)

- [ ] **Step 5: `AddSecondToRecommendation`.** After the `find()`/`deleted_at` check add:
  ```php
        if ((int)$awardRec->monarchy_only > 0) {
            $recip = $this->player_info((int)$awardRec->mundane_id);
            if (!Ork3::$Lib->recommendationvisibility->canSee((int)$mundane_id, [
                'MundaneId' => (int)$awardRec->mundane_id, 'RecommendedById' => (int)$awardRec->recommended_by_id,
                'KingdomId' => (int)($recip['KingdomId'] ?? 0), 'ParkId' => (int)($recip['ParkId'] ?? 0),
                'MonarchyOnly' => (int)$awardRec->monarchy_only, 'PassedToLocal' => (int)$awardRec->passed_to_local,
            ])) {
                return InvalidParameter('Recommendation not found.');
            }
        }
  ```

- [ ] **Step 6: `GetPeerAwardRecommendations`.** Add a 5th param `$token = ''`. Before the query:
  ```php
        $vis    = Ork3::$Lib->recommendationvisibility;
        $viewer = $vis->viewerFromToken($token);
        $this->db->Clear();
        $kr = $this->db->DataSet('SELECT kingdom_id FROM ' . DB_PREFIX . 'mundane WHERE mundane_id = ' . $mundane_id . ' LIMIT 1');
        $recipKingdom = ($kr && $kr->Next()) ? (int)$kr->kingdom_id : 0;
  ```
  Add `LEFT JOIN ork_mundane m ON m.mundane_id = r.mundane_id` to the query and `' AND ' . $vis->sqlPredicate($viewer, 'r', 'm', [$recipKingdom]) .` to the WHERE (before `LIMIT`). Keep the existing `$this->db->Clear()` immediately before the main `DataSet`.

- [ ] **Step 7: Models.** In `orkui/model/model.Player.php` next to `set_recommendation_passed_to_local`:
  ```php
    public function set_recommendation_monarchy_only($request)
    {
        return $this->Player->SetRecommendationMonarchyOnly($request);
    }

    // Officer level for the Add Rec modal's Monarchy Only checkbox: 2 kingdom, 1 park, 0 none.
    public function monarchy_only_level($uid, $kingdom_id, $park_id)
    {
        return (int) Ork3::$Lib->recommendationvisibility->officerLevel((int)$uid, (int)$kingdom_id, (int)$park_id);
    }
  ```
  and change `get_peer_award_recommendations` (line ~255) to take and forward `$token = ''`.

- [ ] **Step 8: Run** `--filter 'RecommendationMonarchyOnlyWritesTest|RecommendationVisibilityTest'` — Expected: PASS.

- [ ] **Step 9: Commit** — `Enhancement: Create, hide and unhide Monarchy Only recommendations`.

---

### Task 5: Court planner — token pass-through and withheld citation

**Files:**
- Modify: `system/lib/ork3/class.Court.php` — `getPendingRecommendations` (~2481), `addAward` (~462, INSERT at ~556)
- Modify: `orkui/model/model.Court.php:271` (`get_pending_recommendations` gains `$caller_token = ''`)
- Modify: `orkui/controller/controller.Court.php:124` (pass `$this->session->token`)
- Test: `tests/Integration/CourtMonarchyOnlyTest.php`

**Interfaces:**
- Consumes: Task 3 (`PlayerAwardRecommendations` honours `Token`).
- Produces: `Court::getPendingRecommendations($kingdom_id, $park_id = 0, $caller_uid = 0, $court_id = 0, $caller_token = '')`; `Model_Court::get_pending_recommendations($kingdom_id, $park_id = 0, $caller_uid = 0, $court_id = 0, $caller_token = '')`.

- [ ] **Step 1: Failing tests.**

```php
    public function testAddAwardFromHiddenRecMarksCitationCleared(): void
    {
        $k = $this->fx->firstKingdomId();
        $p = $this->fx->firstParkId($k);
        $ka = $this->fx->firstKingdomAwardId($k);
        $recip = $this->fx->createPlayer('mo-court', $k, $p)['mundane_id'];
        $rec = $this->fx->createRecommendation($recip, (int)$ka['kingdomaward_id'], (int)$ka['award_id'], ['monarchy_only' => 2, 'reason' => 'secret reason']);
        $court = $this->fx->createCourt(['kingdom_id' => $k]);

        $res = (new \Court())->addAward($court, $k, $recip, (int)$ka['kingdomaward_id'], 1, $rec, 0, '', '');
        $line = $this->fx->fetchAward((int)$res['CourtAwardId']);
        $this->assertSame(1, (int)$line['public_comment_cleared']);
    }

    public function testAddAwardFromPublicRecInheritsAsBefore(): void
    {
        // same as above with monarchy_only 0 → public_comment_cleared 0
    }

    public function testPendingRecsHonourToken(): void
    {
        // cast: parkOfficer + kingdom-level rec in park P → getPendingRecommendations($k, $p, $uid, 0, $token) excludes it;
        // kingdomOfficer → includes it.
    }
```

Write the second and third bodies out fully following the first (the third uses `createPlayerWithToken` + `insertScopedAuth` exactly as in Task 2's `cast()`; assert on the returned list's rec ids — inspect `getPendingRecommendations`' return shape at ~2510 for the id key name). Check `createCourt` override key names and `addAward`'s return array key for the new line id by reading those methods first.

- [ ] **Step 2: Run** — Expected: FAIL.

- [ ] **Step 3: Implement.**
  - `getPendingRecommendations`: add `$caller_token = ''` param; `$req = ['RequestedBy' => (int)$caller_uid, 'Token' => (string)$caller_token];`.
  - `addAward`: before the INSERT (inside the `else` branch, after `$notes_val`):
    ```php
            // A Monarchy Only recommendation's reason is officer-only. commitStagedAward
            // publishes `public_comment ?: rec.reason` to the permanent, public award
            // note unless the citation was explicitly cleared — so a line drawn from a
            // hidden rec starts cleared, and only what an officer types is published.
            $cleared = 0;
            if ($rec_id > 0) {
                $this->db->Clear();
                $mo = $this->db->DataSet('SELECT monarchy_only FROM ' . DB_PREFIX . 'recommendations WHERE recommendations_id = ' . $rec_id . ' LIMIT 1');
                $cleared = ($mo && $mo->Next() && (int)$mo->monarchy_only > 0 && trim((string)$public_comment) === '') ? 1 : 0;
            }
    ```
    Add `public_comment_cleared` to the INSERT column list and `, ' . $cleared . '` to VALUES.
  - Model + controller: forward `$this->session->token`.

- [ ] **Step 4: Run** `--filter 'CourtMonarchyOnlyTest|CourtThread0Test|CourtLedgerGuardTest|CourtNoteTest'` — Expected: PASS.

- [ ] **Step 5: Commit** — `Enhancement: Keep Monarchy Only reasons off the public court record`.

---

### Task 6: Controllers — tokens everywhere, create field, Hide/Unhide bulk, officer flags

**Files (modify; line numbers from the current branch):**
- `orkui/controller/controller.Kingdom.php:135,138` (`recommended_awards` calls) and `:443,:449` (`recommended_awards_count`) → add `'Token' => $this->session->token`
- `orkui/controller/controller.Park.php:310,313` → add Token
- `orkui/controller/controller.PlayerAjax.php:750` → add Token
- `orkui/controller/controller.Player.php:248` (legacy index) → add Token; `profile()` add-rec handler (`:373-398`) and legacy `index()` handler (`:171`) → pass `'MonarchyOnly' => !empty($this->request->...['MonarchyOnly'])` (mirror exactly how `Anonymous` is read there)
- `orkui/controller/controller.Reports.php:224` → add Token
- `orkui/controller/controller.Recommendations.php` — `manage` (`:71`), `rows` (`$req` at ~:161), `export` (its `$req`, ~:400–423): add `'Token' => $this->session->token`; `bulk`: add actions `monarchyonly` and `unmonarchyonly`
- `orkui/controller/controller.KingdomAjax.php:504` and `orkui/controller/controller.ParkAjax.php:372` (`deleted_recommended_awards`) → add Token; both `addrecommendation` handlers (`KingdomAjax:438-472`, `ParkAjax:271-305`) → pass `'MonarchyOnly' => !empty($_POST['MonarchyOnly']) ? 1 : 0`; `ParkAjax checkrecommendation` (`:321`) → pass `$this->session->token` as 5th arg
- Template flags: `Controller_Kingdom::profile` → `$this->data['CanMonarchyOnlyRec'] = $uid > 0 && $this->Player->monarchy_only_level($uid, $kingdom_id, 0) > 0;` (load the Player model if absent); `Controller_Park::profile` → same with `($kingdom_id_of_park, $park_id)`; `Controller_Player::profile` → same with the viewed player's `KingdomId`/`ParkId` (the controller already has `$playerParkId`; get kingdom from the same player info)
- Verification: curl (Step 3) plus the full integration suite (Step 4); no new PHPUnit file.

**Interfaces:**
- Consumes: Task 3 (`Token` in reader requests), Task 4 (`set_recommendation_monarchy_only`, `monarchy_only_level`, peer `$token`), Task 5.
- Produces: template var `$CanMonarchyOnlyRec` (bool) on Kingdom, Park and Player profile pages — the modals read it directly in PHP; no JS config flag is needed. Bulk endpoint contract: `Action=monarchyonly|unmonarchyonly`, `Ids=…` → same per-id `results` shape as the other actions.

- [ ] **Step 1: Make the edits.** Bulk: extend the allow-list to `['dismiss', 'snooze', 'unsnooze', 'passlocal', 'undelete', 'monarchyonly', 'unmonarchyonly']` and add before the final `else`:
  ```php
            } elseif ($action === 'monarchyonly' || $action === 'unmonarchyonly') {
                $r = $this->Player->set_recommendation_monarchy_only([
                    'Token'             => $token,
                    'RecommendationsId' => $rec_id,
                    'Hidden'            => $action === 'monarchyonly' ? 1 : 0,
                ]);
  ```
  Update the bulk contract comment in `Recommendations_manage.tpl` (~line 880) in Task 7.

- [ ] **Step 2: Lint** every touched PHP file: `for f in <files>; do php -l "$f"; done` — Expected: `No syntax errors detected` for each.

- [ ] **Step 3: curl verification** (log in per memory `reference_local_curl_auth_session.md`, one cookie jar; the app is on `http://localhost:19080`). Seed in the `ork` DB: pick a kingdom K, park P, a recipient R in P; set one of R's recs to `monarchy_only = 2`. Then:
  1. As a plain player: `GET index.php?Route=PlayerAjax/recommendations/R` → the hidden rec id is absent.
  2. As a kingdom officer (e.g. the bypass admin account): same URL → present, with `MonarchyOnlyLevel: 2`.
  3. JSON service with forged viewer, no token: `curl -s 'http://localhost:19080/orkservice/Json/index.php?call=Report/PlayerAwardRecommendations&request[PlayerId]=R&request[KingdomId]=0&request[ParkId]=0&request[RequestedBy]=<kingdom officer id>'` (confirm the exact JSON-service URL/argument format by reading `orkservice/Json/index.php` + `JsonServer::RunServer`) → hidden rec absent.
  4. `POST Recommendations/bulk/kingdom/K` with `Action=unmonarchyonly&Ids=<id>` as the kingdom officer → `ok: 1`; row now `monarchy_only = 0`. Restore it to 2 afterwards with `Action=monarchyonly`.
  Record the commands and outputs in the task report.

- [ ] **Step 4: Run the whole integration suite** `vendor/bin/phpunit --testsuite integration` — Expected: no new failures vs. a baseline run on `master`'s merge-base (run the baseline first and save the failure list; report both lists).

- [ ] **Step 5: Commit** — `Enhancement: Pass the viewer's token to every recommendation reader`.

---

### Task 7: UI — checkbox, badge, Recs Manager Hide/Unhide, What's New

**Files:**
- Create: `orkui/template/revised-frontend/_monarchy_only_badge.tpl` (PHP helper only, no output)
- Create: `orkui/template/revised-frontend/_monarchy_only_assets.tpl` (CSS + JS twin; includes the helper)
- Modify: `orkui/template/revised-frontend/Playernew_index.tpl` (modal ~3846; recs table JS ~7852)
- Modify: `orkui/template/revised-frontend/Kingdomnew_index.tpl` (modal ~1565)
- Modify: `orkui/template/revised-frontend/Parknew_index.tpl` (modal ~2324; recs rows ~1784 and delegated list ~1677–1707)
- Modify: `orkui/template/revised-frontend/Kingdomnew_recommendations_panel.tpl` (row award cell ~93)
- Modify: `orkui/template/revised-frontend/_rm_row.tpl` (badges ~107; actions ~140; `<tr>` data attrs)
- Modify: `orkui/template/revised-frontend/Recommendations_manage.tpl` (bulk bar ~113–120; bulk contract comment ~880; new row-action + bulk handlers near the passlocal ones ~844 and ~999)
- Modify: `orkui/template/revised-frontend/script/revised.js` (Kingdom submit ~4285–4329 and reset ~4269; Park submit ~8092–8180 and reset ~8072)
- Modify: `orkui/whats_new_content.php`

**Interfaces:**
- Consumes: Task 3 row fields `MonarchyOnlyLevel` / group fields `MonarchyOnlyLevel`, `MonarchyOnlyAll`; Task 6 `$CanMonarchyOnlyRec`, bulk actions.
- Produces: `ork_monarchy_only_badge(int $level, string $extraClass = ''): string` (PHP, returns escaped HTML or `''` for level 0); JS `window.orkMonarchyOnlyBadge(level)` (returns HTML string or `''`); CSS class `.mo-badge`.

- [ ] **Step 1: Shared partials.** `_monarchy_only_badge.tpl` (output-free; safe in per-row and JSON-rendered templates):

```php
<?php
// Monarchy Only recommendation badge — one definition for every recs surface.
// Included by: Playernew_index.tpl, Parknew_index.tpl, Kingdomnew_recommendations_panel.tpl,
// Recommendations_manage.tpl. Safe to include more than once per request.
if (!function_exists('ork_monarchy_only_badge')) {
    function ork_monarchy_only_badge(int $level, string $extraClass = ''): string
    {
        if ($level <= 0) {
            return '';
        }
        $tip = $level >= 2 ? 'Visible only to kingdom officers' : 'Visible to park and kingdom officers';
        return '<span class="mo-badge' . ($extraClass !== '' ? ' ' . htmlspecialchars($extraClass, ENT_QUOTES) : '')
            . '" data-tip="' . $tip . '"><i class="fas fa-eye-slash" aria-hidden="true"></i> Monarchy Only</span>';
    }
}
```

and `_monarchy_only_assets.tpl` (CSS + the JS twin; include once per page — on Player, Park and Kingdom profile pages next to the `_recommendation_seconds_assets.tpl` include, and on `Recommendations_manage.tpl`; it pulls in the helper too):

```php
<?php include_once __DIR__ . '/_monarchy_only_badge.tpl'; ?>
<style>
.mo-badge { display:inline-flex; align-items:center; gap:4px; margin-left:6px; font-size:11px; font-weight:600; color:#6b46c1; background:rgba(107,70,193,.08); border:1px solid rgba(107,70,193,.45); border-radius:3px; padding:0 5px; white-space:nowrap; position:relative; vertical-align:1px; }
html[data-theme="dark"] .mo-badge { color:#c4b5fd; background:rgba(196,181,253,.10); border-color:rgba(196,181,253,.45); }
.mo-badge[data-tip]:hover::after { content:attr(data-tip); position:absolute; right:0; top:calc(100% + 4px); white-space:normal; width:200px; background:#1a202c; color:#fff; font-size:11px; font-weight:400; padding:6px 8px; border-radius:4px; z-index:50; }
.mo-check-hint { font-size:11px; color:#718096; margin-top:3px; padding-left:24px; }
html[data-theme="dark"] .mo-check-hint { color:#a0aec0; }
</style>
<script>
window.orkMonarchyOnlyBadge = function (level) {
    level = parseInt(level, 10) || 0;
    if (level <= 0) return '';
    var tip = level >= 2 ? 'Visible only to kingdom officers' : 'Visible to park and kingdom officers';
    return '<span class="mo-badge" data-tip="' + tip + '"><i class="fas fa-eye-slash" aria-hidden="true"></i> Monarchy Only</span>';
};
</script>
```

Before finalising colours, check the page's existing tooltip system: if `data-tip` is already globally styled in `revised-frontend` (grep `\[data-tip\]` in `revised.css`/`orkui.css`), DROP the `.mo-badge[data-tip]:hover::after` rule and rely on the global one. Right-anchored is the memory convention for tips near the right edge.

- [ ] **Step 2: Modal checkbox (×3).** Directly after each Anonymous block, rendered only when `!empty($CanMonarchyOnlyRec)`:

Player (`Playernew_index.tpl`, after the `pn-rec-anon` block's closing `</div>`, inside the `<form>` so it posts natively):
```php
				<?php if (!empty($CanMonarchyOnlyRec)): ?>
				<div style="margin-top:12px">
					<label style="display:flex;align-items:center;gap:8px;cursor:pointer;font-weight:normal">
						<input type="checkbox" name="MonarchyOnly" id="pn-rec-monarchy" value="1" style="width:16px;height:16px;cursor:pointer">
						<span>Monarchy Only</span>
					</label>
					<div class="mo-check-hint">Only officers will see this recommendation; the player will not.</div>
				</div>
				<?php endif; ?>
```
Kingdom (`kn-rec-monarchy`) and Park (`pk-rec-monarchy`): same markup with those ids, matching each modal's existing Anonymous wrapper markup exactly (read it first). In `revised.js`: in each reset block add `var xMo = gid('kn-rec-monarchy'); if (xMo) xMo.checked = false;` (and `pk-`), and in each submit add `var moEl = gid('kn-rec-monarchy'); if (moEl && moEl.checked) fd.append('MonarchyOnly', '1');` next to where `Anonymous` is appended (and the same for `pk-`; also clear it in the success-reset at ~4312 / the Park equivalent). Include `_monarchy_only_assets.tpl` once on each profile page (near the `_recommendation_seconds_assets.tpl` include) so `.mo-check-hint` and `.mo-badge` are styled and `orkMonarchyOnlyBadge` exists.

- [ ] **Step 3: Badges.**
  - `Recommendations_manage.tpl`: include `_monarchy_only_assets.tpl` once near the top of the page body. `_rm_row.tpl` (rendered per row, including by the JSON `rows` endpoint) includes only the output-free `_monarchy_only_badge.tpl` at its top: `<?php include_once __DIR__ . '/_monarchy_only_badge.tpl'; ?>`.
  - `_rm_row.tpl`: after the passlocal badge line, `<?= ork_monarchy_only_badge((int)($group['MonarchyOnlyLevel'] ?? 0)) ?>` — one badge per cluster when any member is hidden. In `$membersFull` add `'MonarchyOnlyLevel' => (int)($m['MonarchyOnlyLevel'] ?? 0)` per member, and where the expand panel in `Recommendations_manage.tpl` renders each member's "By", append `orkMonarchyOnlyBadge(m.MonarchyOnlyLevel)` so a partly hidden cluster shows which filings are hidden. Add `data-monarchy="<?= (int)($group['MonarchyOnlyLevel'] ?? 0) ?>" data-monarchyall="<?= !empty($group['MonarchyOnlyAll']) ? 1 : 0 ?>"` to the `<tr>`.
  - `Parknew_index.tpl` recs rows (~1784) and the delegated list rows: after the passlocal badge `<?= ork_monarchy_only_badge((int)($rec['MonarchyOnlyLevel'] ?? 0)) ?>`.
  - `Kingdomnew_recommendations_panel.tpl` (lazy-loaded into the Kingdom profile, which already includes the assets): `include_once __DIR__ . '/_monarchy_only_badge.tpl';` next to the `_recs_hidden_notice.tpl` include; badge after the passlocal span.
  - Player JS (~7853): `'<td>' + esc(rec.AwardName) + (window.orkMonarchyOnlyBadge ? orkMonarchyOnlyBadge(rec.MonarchyOnlyLevel) : '') + '</td>'`.

- [ ] **Step 4: Recs Manager actions.**
  - Row action (in `_rm_row.tpl`, live branch, after the passlocal button): only when the viewer is an officer — the Manager itself is officer-gated, so always render:
    ```php
          <?php $moAll = !empty($group['MonarchyOnlyAll']); ?>
          <button type="button" class="rm-act rm-act-monarchy<?= $moAll ? ' rm-act-active' : '' ?>" data-tip="<?= $moAll ? 'Make visible &mdash; remove Monarchy Only' : 'Monarchy Only &mdash; hide from everyone but officers' ?>" aria-label="<?= $moAll ? 'Remove Monarchy Only' : 'Mark Monarchy Only' ?>"><i class="fas <?= $moAll ? 'fa-eye' : 'fa-eye-slash' ?>" aria-hidden="true"></i></button>
    ```
    Add `<strong>Monarchy Only</strong> hides it from everyone but officers. ` to `.rm-act-help`.
  - Bulk bar: after the Pass down button:
    ```php
    <button type="button" class="rm-bulk rm-bulk-monarchy" data-tip="Hide the selected recommendations from everyone but officers.">Monarchy Only</button>
    <button type="button" class="rm-bulk rm-bulk-unmonarchy" data-tip="Make the selected recommendations visible again.">Unhide</button>
    ```
  - JS in `Recommendations_manage.tpl`: a `rmSetMonarchyRow(tr, hidden)` helper that sets `data-monarchyall`, toggles the row button's class/icon/aria/`data-tip`, and adds/removes the `.mo-badge` in `.rm-col-award` (insert after `.rm-badge-passlocal` if present, else append — mirror `rmSetPasslocalBadge` at ~676). Row click handler modelled on the passlocal one at ~844: `rmBulkRequest(hidden ? 'unmonarchyonly' : 'monarchyonly', rmMemberIds(tr))` then apply or toast the first error. Note a newly-hidden badge level is unknown client-side; render it with level 2 if `RmConfig.context === 'kingdom'` else 1 — and say so in a comment (the server's GREATEST rule may keep a higher level; the next fetch corrects the tip). Bulk buttons call `rmBulkRun('monarchyonly'|'unmonarchyonly', rmSelectedLive(), {apply: …, msg: …})`. Update the contract comment to list the two new actions.

- [ ] **Step 5: What's New.** Add to the top release's `items` in `orkui/whats_new_content.php`, as a `notes_only` item (do NOT bump `WHATS_NEW_VERSION` — that is a release decision for the user):
  ```php
        ['icon' => 'fas fa-eye-slash', 'title' => 'Monarchy Only Recommendations', 'notes_only' => true, 'body' => 'Officers can tick Monarchy Only when recommending an award. The recommendation is hidden from the player and from everyone else; kingdom officers\' hidden recommendations are seen only by kingdom officers, and a park officer\'s by park and kingdom officers. Passing one down to the park lets the park\'s officers see it. Officers can hide or unhide any recommendation from the Recommendations Manager.'],
  ```
  Place it after the Crown modal items and before the first existing `notes_only` item.

- [ ] **Step 6: Lint** touched PHP/tpl files with `php -l`; `node --check orkui/template/revised-frontend/script/revised.js`.

- [ ] **Step 7: Browser verification** (Chrome automation per memory: create a new tab, log in under the bypass, keep resize/login steps serial; check BOTH themes via `html[data-theme]`):
  1. Kingdom officer: Kingdom profile → Recommend → checkbox visible → submit with it ticked → row shows `Monarchy Only` badge with the kingdom tip.
  2. Plain player (different account): same Kingdom tab → row absent; badge count one lower.
  3. Recs Manager (kingdom) → row action Unhide → badge disappears; Hide → returns; bulk both ways.
  4. Pass down → log in as the park officer → Park tab shows it in Delegated with the badge.
  5. Player profile of the recipient (logged in as the recipient) → absent.
  6. Dark mode: badge and hint legible (computed colour check).
  Report screenshots/observations per step.

- [ ] **Step 8: Commit** — `Enhancement: Monarchy Only checkbox, badge and Recommendations Manager actions`.

---

### Task 8: Spec touch-up

**Files:** Modify `docs/superpowers/specs/2026-10-15-monarchy-only-recommendations-design.md`.

- [ ] **Step 1:** Align the spec with decisions made while planning: (a) recipient exclusion precedes the admin grant; (b) `sqlPredicate(int $viewer, string $recAlias, string $mundaneAlias, array $kingdomIds)` — no `$parkIds` param; (c) `DeletedAwardRecommendations` is no longer cached; (d) the Count badge is cached-public + uncached-hidden; (e) `GetSecondsForRecommendations` drops hidden recs and the in-process reader uses `secondsForRecommendations`; (f) re-hiding uses `GREATEST` so it never downgrades. Commit with message `Spec: Record Monarchy Only planning decisions`.
