# P3-R API contract — Player aggregates

Naming: **PascalCase** on domain (`system/lib/ork3/`), **snake_case** on `Model_Player` wrappers. Prefer extending `Player`, `ClassLevel`, and `Award`. Controllers call models only (idiom charter §1.3 / bootstrap hop).

Status codes follow existing Ork3 `Success` / `InvalidParameter` envelopes where methods take `$request` arrays.

---

## A. Already shipped (consume, do not fork)

### `ClassLevel` (`system/lib/ork3/class.ClassLevel.php`)

```php
ClassLevel::THRESHOLDS; // [5, 12, 21, 34, 53]
ClassLevel::computeClassLevel(float $credits): array{Level: int, ToNext: ?float}
```

### `Player::ComputeClassProgress`

```php
Player::ComputeClassProgress(['MundaneId' => int]): Status|Success(list<{
  ClassId, ClassName, Credits, Level, ToNext
}>)
```

Model pattern (SignIn): `Model_Attendance::enrich_classes_with_progress`.

### `Award::GetLadderMasterMap`

```php
Award::GetLadderMasterMap(): array<int, array{
  MasterAwardIds: list<int>,
  LadderName: string,
  MasterName: string,
  MaxRank: int
}>
// Keys = order/ladder AwardId (21, 22, … 243)
```

### `Player::GetReconcileAwardMap`

```php
Player::GetReconcileAwardMap(int $kingdomId): array<int, int> // award_id => kingdomaward_id
```

Model: `get_reconcile_award_map($kingdom_id)`.

### Custom milestones

`Player::GetCustomMilestones($mundane_id)` (+ existing add/update/delete). Model: `get_custom_milestones`.

---

## B. Proposed net-new / extended APIs

Names are proposals; implementers may rename for consistency with neighboring `Player` methods but must keep one clear domain entry point per concern.

### B1. Class stats for profile chrome — **P3-R1**

**Problem:** Controller duplicates thresholds to compute `HighestClassLevel`.

**Option A (preferred if credit semantics match):**

```php
// Domain
Player::GetHighestClassLevel(int $mundaneId): int
// Implementation: max Level from ComputeClassProgress Detail; 0 if none

// Model
Model_Player::get_highest_class_level(int $mundane_id): int
```

**Option B (byte-identical to today’s profile loop):**

```php
// Domain — pure function over already-fetched class rows
Player::HighestClassLevelFromClasses(array $classes): int
// Each row: Credits + Reconciled (or Credits already combined); uses ClassLevel::computeClassLevel

// Model
Model_Player::highest_class_level_from_classes(array $classes): int
```

Controller assigns `$this->data['Stats']['HighestClassLevel']` from the model. No threshold literals in `orkui/`.

**Characterization:** Extend `ClassLevelTest` and/or add a small `Player` unit/integration case for HighestClassLevel vs known credit fixtures. Assert controller no longer contains `>= 53` / `>= 34` / etc.

---

### B2. Shared award catalogues — **P3-R2**

Centralize ID lists currently triple-encoded (controller milestones, template belts, template paragon).

```php
// Domain (Award static helpers — keep next to GetLadderMasterMap)

Award::GetClassParagonMap(): array<int, int>
// class_id => paragon award_id
// Source of truth for $pnClassToParagon

Award::GetKnightAwardMap(): array<int, string>
// award_id => short name (Flame, Crown, …)
// IDs: 17,18,19,20,245 — shared by milestones + belt detection

Award::GetMasterAwardIds(): list<int>
// Flatten MasterAwardIds from GetLadderMasterMap() — replaces $__masterIds

Award::GetParagonAwardIds(): list<int>
// Values of GetClassParagonMap() (or explicit list matching controller today)
```

Model wrappers only if templates/controllers need them without importing `Award` (prefer controller passes maps into `$this->data`):

```php
Model_Player::get_class_paragon_map(): array
Model_Player::get_ladder_master_map(): array  // thin → Award::GetLadderMasterMap()
```

**Note:** Templates must not call `new Award()` / static domain if idiom prefers model-only. Assign from controller: `$this->data['ClassParagonMap']`, `$this->data['LadderMasterMap']`.

---

### B3. Milestone timeline — **P3-R2**

```php
// Domain
Player::GetPlayerMilestones(array $request): Status|Success(list<MilestoneRow>)

// Request (flexible — prefer passing already-loaded aggregates to avoid N+1):
[
  'MundaneId' => int,                 // required if loading internally
  'PlayerSinceDate' => ?string,       // optional override
  'Awards' => ?list,                  // optional; else load via existing detail APIs
  'BeltlinePeers' => ?list,
  'BeltlineAssociates' => ?list,
  'IncludeCustom' => bool,            // default true
]

// MilestoneRow
[
  'type' => string,           // first_signin|knight|master|paragon|title|officer|
                              // became_associate|took_associate|custom
  'date' => string,           // Y-m-d
  'icon' => string,           // fa-* class suffix currently used by UI
  'description' => string,
  'milestoneId' => ?int,      // custom only
]
```

```php
// Model
Model_Player::get_player_milestones(array $request): array  // Success envelope or list — match sibling style
```

**Controller after wire:** build/fetch beltline as today → call model → `$this->data['Milestones']`; keep peer display dedupe after milestones (or move both into domain with a documented order).

**Characterization:** Fixture player with known awards/peers; assert types, dedup of Master title vs master award, chronological order. Freeze knight/paragon ID sets in unit tests against `Award::*` maps.

---

### B4. Ladder progress tiles — **P3-R2**

```php
// Domain
Player::GetLadderProgress(array $request): Status|Success(list<LadderTile>)

// Request
[
  'MundaneId' => int,
  'Awards' => ?list,   // optional preloaded Details['Awards']
]

// LadderTile (sorted by Name for stable UI)
[
  'AwardId' => int,       // order/ladder award_id
  'Name' => string,
  'Short' => string,
  'Rank' => int,          // capped display rank
  'MaxRank' => int,       // from GetLadderMasterMap
  'HasMaster' => bool,
  'Approx' => bool,
]
```

Uses `Award::GetLadderMasterMap()` only — delete `$pnOrderToMaster` / `$pnOrderNames`.

```php
// Model
Model_Player::get_ladder_progress(array $request): array
```

---

### B5. Reconcile suggestions — **P3-R3**

```php
// Domain
Player::GetReconcilePageData(array $request): Status|Success(ReconcilePageDto)

// Request
[
  'MundaneId' => int,
  'KingdomId' => int,     // for AwardId→KingdomAwardId map
  'Awards' => ?list,      // optional Details['Awards']
]

// ReconcilePageDto
[
  'HistoricalAwards' => list<array>,  // ladder historical rows, sorted
  'RankSuggestions' => array<int, int>, // AwardsId => suggested Rank
  'RealRanksByAwardId' => array<int, list<int>>,
  'AwardIdToKingdomAwardId' => array<int, int>,
  'Summary' => [
    'AwardTypeCount' => int,
    'TotalCount' => int,
  ],
  'HasHistoricalLadder' => bool,      // for profile CTA / tip
]
```

Smart-rank algorithm must match current template behavior (see [01-inventory.md](./01-inventory.md) §4) — characterization tests before deleting template PHP.

```php
// Model
Model_Player::get_reconcile_page_data(array $request): array
```

**Optional split:** `GetReconcileSuggestions($awards)` pure function + map fetch, if that eases unit testing without DB.

---

## C. Controller / template data contracts after wire (P3-R4)

### `Controller_Player::profile`

Assign (names illustrative):

| `$this->data[…]` | Source |
|------------------|--------|
| `Stats['HighestClassLevel']` | B1 |
| `Milestones` / `CustomMilestones` | B3 + existing custom fetch |
| `LadderProgress` | B4 |
| `ClassParagonMap` | B2 |
| `LadderMasterMap` | B2 / existing Award map |
| `HasHistorical` / `HasHistoricalTip` | B5 flags or light helper |
| `AwardIdToKingdomAwardId` | existing (reconcile only) |

Templates: remove `$pnOrderToMaster`, `$pnClassToParagon`, inline smart-rank, inline class thresholds. Iterate DTOs / maps from `$this->data`.

### `Controller_Player::reconcile`

Assign full `ReconcilePageDto` fields; template drops partition/suggestion PHP.

---

## D. Optional orkservice exposure — **P3-R4 stretch**

Not required for UI thinness. If external clients need the same DTOs:

| Domain | Suggested JSON registration |
|--------|------------------------------|
| `GetPlayerMilestones` | `PlayerService.GetPlayerMilestones` |
| `GetLadderProgress` | `PlayerService.GetLadderProgress` |
| `GetReconcilePageData` | `PlayerService.GetReconcilePageData` |
| `GetHighestClassLevel` / progress | may already be reachable via class APIs — register only if gap |

Follow existing `PlayerService` registration patterns (`*.registration.php`, `*.function.php`, definitions). SOAP only if the service still maintains dual surface for that method family.

---

## E. Anti-patterns

- Do not add a second `THRESHOLDS` array anywhere.
- Do not leave `$pnOrderToMaster` “for convenience” once `GetLadderMasterMap` is wired.
- Do not call `Ork3::$Lib` or `$DB` from `orkui/` while implementing these APIs.
- Do not put smart-rank or AwardId lists into JavaScript as the source of truth; JS may receive JSON copies of domain maps for client UX only.
