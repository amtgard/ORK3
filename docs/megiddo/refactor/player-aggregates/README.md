# Player first-class aggregate APIs (play-aggregates)

**Nickname:** play-aggregates  
**Parent residual:** [P3-R\*](../11-phase-3-closeout.md) from Phase 3 close-out  
**Status:** Planning complete (this package). Implementation starts at **P3-R1**.  
**Branch context:** Stack on the current Megiddo tip (`megiddo/p3-bootstrap-model-hop` or successor).

---

## Why

Phase 3 eliminated `$DB` / `Ork3::$Lib` from `orkui/`, but **award ladders, class-level thresholds, milestone timelines, and reconcile “smart rank” rules** still live in `Controller_Player` and revised Player templates. That violates the product goal:

> Anything the frontend needs must be accessible via API as a first-class citizen. Controllers/templates only orchestrate and render.

Those rules are real domain knowledge. Future orkservice / mobile / embed clients cannot reuse them while they remain PHP in templates.

Sign-in already consumes class progress through the Attendance model (`enrich_classes_with_progress` → `Player::ComputeClassProgress` → `ClassLevel::computeClassLevel`). Player profile must match that pattern for the remaining aggregates.

---

## Scope

| In scope | Out of scope |
|----------|--------------|
| Class-level / highest-level stats on profile | Reopening unrelated R-* controllers |
| Milestone timeline construction + award ID catalogues | Bootstrap Lib / Dangeraudit hop (done) |
| Order→Master and Class→Paragon maps; ladder progress tiles | Human P3-4 / P3-5 / P3-6 close-out |
| Historical award partition + smart-rank suggestions | Product changes to ladder/paragon rules |
| Thin `Controller_Player` + revised templates | Optional orkservice JSON exposure (later gate only) |

Prefer **extending** `Player`, `ClassLevel`, and `Award` over inventing parallel services.

---

## Package map

| Doc | Purpose |
|-----|---------|
| [01-inventory.md](./01-inventory.md) | File:line rule sites and what each encodes |
| [02-api-contract.md](./02-api-contract.md) | Domain / model method shapes and DTOs |
| [03-milestones.md](./03-milestones.md) | Executable P3-R0…R4 checklist + acceptance gates |
| [agent-prompt.md](./agent-prompt.md) | Worker prompts for implementer agents |
| [orchestrator.prompt](./orchestrator.prompt) | Serialized orchestrator driver |

---

## Success criteria

1. **No ladder rules in `orkui/`.** Thresholds, AwardId catalogues, order/class→award maps, peerage/milestone dedup, and smart-rank suggestion logic live in `system/lib/ork3/` (and thin `Model_Player` wrappers). Templates iterate DTOs only.
2. **Controller is thin.** `Controller_Player::profile` / `reconcile` assign domain results into `$this->data`; they do not encode thresholds or AwardId lists.
3. **Reuse first.** `ClassLevel::computeClassLevel`, `Player::ComputeClassProgress`, `Award::GetLadderMasterMap`, `Player::GetReconcileAwardMap`, and custom-milestone APIs are the base — extend or wrap, do not fork.
4. **Characterization then wire.** PHPUnit covers new/changed domain methods before template/controller deletion of duplicated logic.
5. **Gates.** Full PHPUnit suite green; static `orkui/` still zero `$DB` / `Ork3::$Lib`; fuzzy `player-profile` (test + mirror) pass after P3-R4 wire (re-record only if intentional DOM drift is approved).
6. **orkservice optional.** External JSON exposure is a P3-R4 stretch, not a blocker for UI thinness.

---

## Milestone order (summary)

| ID | Deliverable |
|----|-------------|
| **P3-R0** | This inventory + contract package (done when committed) |
| **P3-R1** | Class level / progress via `ClassLevel` / `Player` |
| **P3-R2** | Milestones + award maps + ladder progress API |
| **P3-R3** | Reconcile suggestions API |
| **P3-R4** | Wire controller/templates; fuzzy canaries; optional orkservice |

Details and acceptance gates: [03-milestones.md](./03-milestones.md).

---

## Related

- [11-phase-3-closeout.md](../11-phase-3-closeout.md) — Phase 3 human gates + P3-R residual pointer  
- [04-milestone-checklist.md](../04-milestone-checklist.md) — remaining-work checkboxes  
- [idioms-00-charter.md](../idioms-00-charter.md) — frontend must not reintroduce Lib/domain call-site coupling  
- [05-development-steering.md](../05-development-steering.md) — DS-* commit / branch / test rules  
