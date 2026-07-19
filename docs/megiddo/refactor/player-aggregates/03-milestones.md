# P3-R milestones — Player aggregate APIs

Executable checklist for implementers / orchestrator. IDs reuse **P3-R0…P3-R4** from [11-phase-3-closeout.md](../11-phase-3-closeout.md). Branch one milestone at a time per [05-development-steering.md](../05-development-steering.md) (e.g. `megiddo/p3-r1-class-level-api`).

**Steering:** Full PHPUnit suite before sign-off (DS-4/5). One commit per milestone unless the active branch already holds related docs. Do not push/PR unless asked.

---

## Gates (every implementing milestone)

| Gate | Command / check |
|------|-----------------|
| PHPUnit | Full suite per [06-test-framework.md](../06-test-framework.md) |
| Static `orkui/` | Zero `$DB` access and zero `Ork3::$Lib` (existing Phase 3 greps) |
| Idiom | Controllers use `Model_*` only for new hops; no `(new Player())` / Lib in `orkui/controller` |
| Fuzzy (P3-R4) | `player-profile` (+ sandbox if used) **test + mirror** validate; re-record only with intentional drift approval |

---

## P3-R0 — Inventory + contract

**Status:** Complete when this `player-aggregates/` package is committed.

- [x] Inventory file:line sites ([01-inventory.md](./01-inventory.md))
- [x] API contract ([02-api-contract.md](./02-api-contract.md))
- [x] Cross-links from Phase 3 close-out / remaining checklist / refactor README
- [x] Orchestrator / agent prompts for follow-on implementation

**Acceptance:** Docs only; no production PHP changes required.

---

## P3-R1 — Class level / progress API

**Goal:** Remove threshold block from `Controller_Player::profile`; use `ClassLevel` / `Player` (mirror SignIn).

**Choice (credit semantics):** **Option A** — `Player::GetHighestClassLevel` via `ComputeClassProgress`. Evidence: `GetPlayerProfileDetails` already loads `Classes` from `GetPlayerClasses`, the same source `ComputeClassProgress` uses (Credits + Reconciled). `HighestClassLevelFromClasses` remains as a pure characterization helper (Option B path) and matches Option A on those rows.

### Work

- [x] Choose Option A or B from [02-api-contract.md](./02-api-contract.md) §B1 after comparing `Details['Classes']` credits vs `ComputeClassProgress`
- [x] Implement domain + `Model_Player` wrapper
- [x] Wire controller: assign `Stats['HighestClassLevel']` from model; delete inline `>= 53`… chain
- [x] PHPUnit characterization (extend `ClassLevelTest` and/or Player/integration)

### Acceptance

- [x] No class-level threshold literals in `orkui/controller/controller.Player.php`
- [x] Full PHPUnit green
- [x] Static `orkui/` Lib/$DB clean
- [x] Behavior: HighestClassLevel matches pre-change for fixture players (test assertion)

**Fuzzy:** Not required if chrome number is unchanged; optional spot-check.

---

## P3-R2 — Milestones + award maps + ladder progress

**Goal:** AwardId catalogues and timeline/ladder algorithms leave controller/templates.

**Open-question decisions (this milestone):**
- **Belt assets:** Domain returns AwardIds + short names (`GetKnightAwardMap`); belt **IMAGE URLs** remain in the template (host/path presentation).
- **Milestone icons:** Font Awesome class strings stay in the domain DTO (`icon` field) — template renders as-is (matches pre-R2 controller behavior).
- **Master catalogue:** `GetMasterAwardIds` flattens `GetLadderMasterMap` and **includes Warlord (12)** — former controller list omitted 12; unified on the ladder map as sole source.

### Work

- [x] Add `Award::GetClassParagonMap` (+ knight/master/paragon helpers as needed); ensure `GetLadderMasterMap` is the only order→master source
- [x] Implement `Player::GetPlayerMilestones` + model wrapper; port controller block ~515–669 including dedup/sort
- [x] Implement `Player::GetLadderProgress` + model wrapper; port template ladder tile algorithm
- [x] Thin `Controller_Player::profile`: assign `Milestones`, `LadderProgress`, maps
- [x] Thin `Playernew_index.tpl`: remove `$pnOrderToMaster`, `$pnOrderNames`, `$pnClassToParagon`; iterate DTOs; knight belt detection may consume shared knight map
- [x] PHPUnit: map equality vs former literals; milestone fixture cases; ladder Approx/Master edge cases

### Acceptance

- [x] `rg 'pnOrderToMaster|pnClassToParagon|__knightIds|__paragonIds'` clean under `orkui/` (except comments pointing to domain if any)
- [x] Template does not define order→master or class→paragon maps
- [x] Full PHPUnit green; static gates clean
- [ ] Visual/DOM behavior preserved for Awards / Class Levels / Milestones tabs (manual or fuzzy later)

**Note:** Belt image URL assembly may remain in template if AwardIds come from domain.

---

## P3-R3 — Reconcile suggestions API

**Goal:** Smart-rank and historical partition leave `Playernew_reconcile.tpl`.

### Work

- [ ] Implement `Player::GetReconcilePageData` (or split pure suggestion helper) + model wrapper
- [ ] Characterization tests locking smart-rank outcomes for mixed historical/real rank sets
- [ ] Wire `Controller_Player::reconcile`; template display-only
- [ ] Optional: profile `HasHistorical` / `HasHistoricalTip` from same helper (remove duplicate filter in index tpl)

### Acceptance

- [ ] No smart-rank / historical partition algorithm in `Playernew_reconcile.tpl`
- [ ] `GetReconcileAwardMap` still used (via page DTO or existing assign)
- [ ] Full PHPUnit green; static gates clean

---

## P3-R4 — Wire + gate (+ optional orkservice)

**Goal:** End-to-end thin UI; prove with fuzzy; optionally expose JSON.

### Work

- [ ] Confirm all P3-R1–R3 call sites wired; grep inventory §7 clean
- [ ] Fuzzy validate `player-profile` (and `player-profile-sandbox` if in active setpoint) on **test** and **mirror**
- [ ] Re-record baselines only if intentional drift approved; otherwise fix regressions
- [ ] Update [04-milestone-checklist.md](../04-milestone-checklist.md) / close-out notes: check off P3-R*
- [ ] **Optional stretch:** register thin `PlayerService` methods for milestones / ladder / reconcile DTOs ([02-api-contract.md](./02-api-contract.md) §D) — does not block UI sign-off

### Acceptance

- [ ] Controllers/templates contain no ladder rule literals (thresholds, AwardId catalogues, smart-rank)
- [ ] Full PHPUnit green
- [ ] Static `orkui/` Lib/$DB clean
- [ ] Fuzzy player-profile test+mirror pass
- [ ] Checklist boxes for P3-R0…R4 marked done; human P3-4/5/6 untouched

---

## Suggested agent order

```text
P3-R0 (docs) → P3-R1 → P3-R2 → P3-R3 → P3-R4
```

Do not parallelize R1–R4 on overlapping `Controller_Player` / template files. Use [orchestrator.prompt](./orchestrator.prompt) to serialize workers from [agent-prompt.md](./agent-prompt.md).

---

## Open questions (resolve during R1–R3, document in commit)

1. **Credit semantics:** **Resolved (P3-R1):** `fetch_player_details` / `GetPlayerProfileDetails` Classes come from `GetPlayerClasses` — same as `ComputeClassProgress`. Chose **Option A** (`GetHighestClassLevel`). Option B helper `HighestClassLevelFromClasses` kept for characterization.
2. **Belt assets:** **Resolved (P3-R2):** Domain returns AwardIds + short names only; belt image URLs remain in the template.
3. **Milestone icons:** **Resolved (P3-R2):** Keep Font Awesome class strings in the domain DTO (`icon`); template does not remap type→icon.
4. **orkservice:** Defer forever vs register in R4 for one client — product call, not a gate.
