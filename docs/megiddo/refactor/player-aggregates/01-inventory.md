# P3-R inventory — Player aggregate rule sites

Line numbers are approximate as of the Megiddo tip that includes the bootstrap model hop (`Controller_Player` / revised Player templates). Re-verify if upstream drifts.

**Product rule under scrutiny:** domain knowledge encoded in UI must become callable domain/model APIs.

---

## 1. Class-level thresholds — `Controller_Player`

**File:** `orkui/controller/controller.Player.php`  
**Method:** `profile`  
**Lines:** ~449–468

| What | Rule encoded |
|------|----------------|
| Inline `if ($credits >= 53) … elseif (>= 34) … (>= 21) … (>= 12) … (>= 5)` | Class level from credits+reconciled; levels 1–6 |
| Max over classes → `$this->data['Stats']['HighestClassLevel']` | Profile chrome “highest class level” |

**Canonical home already exists:** `system/lib/ork3/class.ClassLevel.php` — `THRESHOLDS = [5, 12, 21, 34, 53]` and `ClassLevel::computeClassLevel(float $credits): array{Level, ToNext}`.

**Related existing API:** `Player::ComputeClassProgress(['MundaneId' => …])` returns per-class `{ClassId, ClassName, Credits, Level, ToNext}` using `GetPlayerClasses` credit semantics (same helper SignIn uses via `Model_Attendance::enrich_classes_with_progress`).

**Risk:** Profile currently levels `Details['Classes']` from `fetch_player_details`. Confirm credits(+Reconciled) match `GetPlayerClasses` / `ComputeClassProgress` before switching HighestClassLevel to the progress API alone. Prefer `ClassLevel::computeClassLevel` over the same credit inputs the UI already has if semantics must stay byte-identical for fuzzy.

**Target:** Domain helper (or thin `Player` wrapper) → model → controller assigns `Stats['HighestClassLevel']` only. No thresholds in controller.

---

## 2. Milestone timeline — `Controller_Player`

**File:** `orkui/controller/controller.Player.php`  
**Method:** `profile`  
**Lines:** ~515–669 (plus beltline peer display dedupe ~671–691 that must run *after* milestones)

| Block | Lines (approx) | Rule encoded |
|-------|----------------|--------------|
| First sign-in | 520–525 | `PlayerSinceDate` → `type: first_signin` |
| Knight awards | 533–555 | AwardIds `[17,18,19,20,245]` + display names Flame/Crown/Serpent/Sword/Battle |
| Master awards | 535, 557–561 | Master AwardIds `[1–11,240,244]` (mirrors template order→master values) |
| Paragon awards | 563–567 | Paragon AwardIds `[37–47,49–51,241,242]` |
| Title / officer | 569–580 | IsTitle + OfficerRole filters; suppress beltline-aliased custom titles |
| Became / took associate | 583–606 | Beltline peers/associates + peerage label map |
| Custom milestones | 608–620 | Already domain: `Model_Player::get_custom_milestones` → `Player::GetCustomMilestones` |
| Cross-type dedup | 622–649 | Drop title milestones that duplicate peerage / Master X |
| Exact dedup + sort | 651–665 | Same description+date; chronological ascending |

**Inputs already available to domain:** awards list, classes (unused for level-6 milestones — those are AJAX/client), `PlayerSinceDate`, beltline peers/associates, custom milestones.

**Target:** `Player::GetPlayerMilestones(…)` (name per [02-api-contract.md](./02-api-contract.md)) returning ready timeline rows. Controller: `$this->data['Milestones'] = …`. Knight/master/paragon ID catalogues become shared constants or `Award` static maps (single source with template belt icons / ladder maps).

---

## 3. Award maps & ladder progress — `Playernew_index.tpl`

**File:** `orkui/template/revised-frontend/Playernew_index.tpl`

### 3a. Class → Paragon map

**Lines:** ~132–136 (preamble); consumed ~2571–2599 (Class Levels tab)

```text
$pnClassToParagon = [
  1=>37, 2=>38, 3=>39, 4=>40, 5=>41, 6=>241, 7=>42, 8=>43,
  9=>44, 10=>45, 11=>46, 12=>47, 14=>242, 15=>49, 16=>50, 17=>51,
];
```

**Also:** JS payload ~3979 `classToParagon: <?= json_encode($pnClassToParagon) ?>`.

**Rule:** Which Paragon award_id corresponds to each class_id for badge display.

**No domain API today.** Net-new `Award::GetClassParagonMap()` (or equivalent on `Player`).

### 3b. Order → Master map (duplicate of domain)

**Lines:** ~2134–2165 (`$pnOrderToMaster`, `$pnOrderNames`); ladder tile build ~2166–2232

**Canonical home already exists:** `Award::GetLadderMasterMap()` in `system/lib/ork3/class.Award.php` — same order/master pairs, names, and `MaxRank` (Zodiac 12). Comment in Award.php already says keep in sync with this template.

**Irony:** Template **already** calls `Award::GetLadderMasterMap()` at ~3915 for JS recommendation logic while the Awards tab still hardcodes `$pnOrderToMaster`.

**Target:** Delete template maps; consume `GetLadderMasterMap()` via controller/model-assigned DTO. Prefer building **ladder progress tiles** in domain (`Player::GetLadderProgress`) so Approx/max-rank/master-fill logic is not template-owned.

### 3c. Ladder progress algorithm (template-owned)

**Lines:** ~2132–2232

| Step | Rule |
|------|------|
| Filter ladder awards; skip Walker (31) | Which awards count toward tiles |
| Aggregate Rank / RankSet / UnrankedCount | Dedup by rank; count unranked historical |
| HasMaster via order→master map | Master badge |
| Approx when effective count > Rank and no Master | Historical approximation `~` |
| Cap Rank at MaxRank (10 / Zodiac 12) | Max rank per order |
| Synthetic tile when Master held but no ladder rows | Complete masterhood display |

**Target:** Domain DTO list of tiles `{AwardId, Name, Short, Rank, MaxRank, HasMaster, Approx}`; template only renders.

### 3d. Knight belt catalogue (related, same AwardId set)

**Lines:** ~31–65

| What | Rule |
|------|------|
| `$knightAwardIds` | Same IDs as controller milestone knights |
| `$beltImageMap` | Asset paths per knighthood |
| AliasAwardId promotion | Custom Title aliased to Knight-of-X counts as that belt |

**Recommendation:** Include knight AwardId/name catalogue in the shared Award/Player maps under P3-R2 so controller milestones and template belts cannot drift. Belt **image URLs** may remain presentation (host/path) if the API returns AwardIds + labels only — document choice in the R2 commit.

### 3e. Historical presence flags

**Lines:** ~90–114 (`$hasHistorical`, `$hasHistoricalTip`)

**Rule:** Ladder award with `GivenById === 0 && EnteredById === 0` (and OfficerRole/IsTitle filters). Admin CTA vs public tip.

**Target:** Prefer deriving from reconcile page DTO / `Player::HasHistoricalLadderAwards` (or flags on reconcile/ladder APIs) so the filter is not copied a third time in the template. Can land with P3-R3 if R2 stays map-focused.

---

## 4. Reconcile smart rank — `Playernew_reconcile.tpl`

**File:** `orkui/template/revised-frontend/Playernew_reconcile.tpl`  
**Lines:** ~13–73 (logic); remainder is display/JS POST to `PlayerAjax/.../reconcileaward`

| Block | Rule encoded |
|-------|----------------|
| Partition awards | Historical = GivenById 0 AND EnteredById 0; else collect real ranks by AwardId |
| Ladder-only filter | `IsLadder === 1` for reconcilable rows |
| Sort | AwardId ASC, date ASC (missing dates last) |
| Smart rank | Prefer existing Rank if unused by real+suggested; else smallest free positive integer per AwardId group |

**Already domain:** `Player::GetReconcileAwardMap($kingdomId)` / `Model_Player::get_reconcile_award_map` — award_id → kingdomaward_id for dropdowns (controller ~780). Keep; extend with suggestions aggregate.

**Target:** `Player::GetReconcileSuggestions` (or `GetReconcilePageData`) returning historical rows + `SuggestedRank` + summary counts. Template display-only; auth gate can stay controller or thin template check using controller-provided flags.

---

## 5. Existing domain / model inventory (do not reinvent)

| API | Layer | Role |
|-----|-------|------|
| `ClassLevel::computeClassLevel` / `THRESHOLDS` | Domain static | Level + ToNext |
| `Player::ComputeClassProgress` | Domain | Per-class progress via GetPlayerClasses |
| `Model_Attendance::enrich_classes_with_progress` | Model | SignIn enrichment pattern to mirror |
| `Award::GetLadderMasterMap` | Domain static | Order → MasterAwardIds, names, MaxRank |
| `Player::GetReconcileAwardMap` | Domain | Kingdom award map for reconcile UI |
| `Player::GetCustomMilestones` (+ CRUD siblings) | Domain | Custom timeline rows |
| `Model_Player::get_custom_milestones` / `get_beltline_for_player` / `get_reconcile_award_map` | Model | Existing snake_case wrappers |
| `tests/Unit/ClassLevelTest.php` | Test | Threshold characterization |
| `tests/Integration/AttendanceSignInTest.php` | Test | ComputeClassProgress integration |
| `tests/Integration/LadderGridTest.php` | Test | GetLadderMasterMap consumers |

---

## 6. Explicit non-goals (this residual)

- Moving feast prefs, dues, voting eligibility, or qual-test chrome (already mostly model-backed).
- Changing Amtgard ladder / paragon / knight product rules.
- Re-recording fuzzy baselines without intentional UI change (P3-R4 should be behavior-preserving).
- Mandatory orkservice SOAP/JSON registration (optional stretch on P3-R4).

---

## 7. Drift checklist for implementers

Before deleting a template/controller block, grep for the same AwardId list elsewhere:

```bash
rg -n 'pnOrderToMaster|pnClassToParagon|GetLadderMasterMap|__knightIds|__paragonIds|>= 53' \
  orkui/ system/lib/ork3/ docs/megiddo/refactor/player-aggregates/
```

Single source of truth after P3-R2/R3: domain `Award` / `Player` / `ClassLevel` only.
