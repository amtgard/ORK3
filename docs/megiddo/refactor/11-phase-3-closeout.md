# Phase 3 — Audit and Close-out

**Status:** Automated work complete; human close-out remains open. A post-gate residual (Player first-class APIs) is planned under [player-aggregates/](./player-aggregates/) and summarized below.
**Completed:** P3-2/P3-3 automated audit and gate fixes, I-0 … I-VALIDATE idiom enforcement, and RB-0 … RB-Z are complete, with `$DB` and `Ork3::$Lib` at zero in `orkui/`.
**Current tip:** `megiddo/fuzzy-validator-v2` (stacked on post-rebase Megiddo tip). See [PR #492](https://github.com/amtgard/ORK3/pull/492).

Historical audit reports, execution skills, and remediation prompts are in [archive/README.md](./archive/README.md).

---

## Remaining close-out work

| ID | Owner | Work | Status |
|----|-------|------|--------|
| **P3-4** | Human | Walk [the manual smoke matrix](./validations/r-milestone-smoke-matrix.html) and record pass/fail results. | [ ] |
| **P3-5** | Human | Record the retrospective. | [ ] |
| **P3-6** | Human (optional) | Merge after the rebase and human close-out are accepted. | [ ] |

These are the original Phase 3 human gates — not dangling chores. Automated migration and audit already passed; eyes-on smoke and retrospective still close the phase.

---

## Frontend residual hunt (2026-07-19)

Static re-hunt of `orkui/` after the stacked tip: **zero** `$DB->`, **zero** `Ork3::$Lib`, **zero** raw DML in `orkui/`. Auth grants remain on `Authorization->add_auth` / `del_auth`.

Findings fall into three classes against the product goal: *anything the frontend needs should be reachable as a first-class API (domain / orkservice), not encoded in controllers or templates.*

### Non-blocking residual — resolved (bootstrap / idiom API hop)

**2026-07-19:** Decoupled so frontend code does not bypass models:

| Before | After |
|--------|-------|
| `class.Controller` → `Ork3::$Lib->sessiontoken/player` | `Model_SessionToken` / `Model_Player` snake_case wrappers |
| `global $DB; $DB->Clear()` after nav auth | `Model_Authorization::clear_db_after_auth_checks()` |
| Controllers → `(new Dangeraudit())->audit` | `$this->Authorization->audit(…)` |
| `index.php` → `(new Health())` / `(new Event())` | `Model_Health::ping_db` / `Model_Event::get_event_summary_for_redirect` |

Domain methods were already first-class; this hop removes Lib/direct-domain **call-site** coupling so future work cannot casually reintroduce frontend Lib habits. Static gates now also cover `class.Controller.php` and ban `(new Dangeraudit())` in `orkui/controller/`. See [idioms-00-charter.md](./idioms-00-charter.md) §1.3 / §1.6 / §2.

### Blocking for the API goal — Player aggregates still in the UI layer

These rules still live in `Controller_Player` and revised Player templates. They are **real domain knowledge** and affect any future first-class player API / mobile / embed clients. Track as **P3-R\*** below (residual after R-19d gates; not a reopen of `$DB` migration).

| Surface today | Problem | First-class target |
|---------------|---------|-------------------|
| `controller.Player.php` class-level thresholds (5 / 12 / 21 / 34 / 53) | Duplicates `ClassLevel` rules in the controller | Domain (or existing `ClassLevel::computeClassLevel`) → model DTO; controller only assigns |
| `controller.Player.php` milestone timeline (knight / master / paragon AwardId lists, peerage dedup) | Award ladder encoded in UI | `Player` (or Award) API: e.g. `GetPlayerMilestones(mundaneId)` → ready timeline DTO |
| `Playernew_index.tpl` `$pnOrderToMaster` / `$pnClassToParagon` | Templates own award maps | Same APIs; template only iterates |
| `Playernew_reconcile.tpl` historical vs real ranks + “smart rank” suggestion | Ranking algorithm in a template | Player reconcile service / model method; template renders suggestions |

Sign-in already uses migrated class-progress enrichment via the Attendance model — Player profile should match that pattern.

---

## P3-R — Player first-class API residual

**Goal:** Player profile chrome consumes domain/model DTOs only; award ladders, class levels, milestones, and reconcile suggestions are callable without opening `orkui/`.

**Canonical plan package:** [player-aggregates/](./player-aggregates/) (nickname: play-aggregates) — inventory, API contract, executable milestones, orchestrator prompts. Implementation starts at **P3-R1**.

| ID | Deliverable | Notes |
|----|-------------|-------|
| **P3-R0** | Inventory + contract | **Done** in [player-aggregates/](./player-aggregates/). |
| **P3-R1** | Class level / progress API | Replace controller threshold block with domain/`ClassLevel` (mirror SignIn enrichment). PHPUnit characterization. |
| **P3-R2** | Milestones + award maps API | Move AwardId lists and peerage dedup into domain; thin controller; remove maps from `Playernew_index.tpl`. |
| **P3-R3** | Reconcile suggestions API | Move smart-rank logic out of `Playernew_reconcile.tpl` into model/domain; template display-only. |
| **P3-R4** | Wire + gate | Point `Controller_Player` + templates at the new APIs; fuzzy canaries for player-profile (test + mirror); optional thin orkservice exposure if external clients need the same DTOs. |

**Out of scope for P3-R:** reopening R-* for unrelated controllers. Bootstrap Lib / Dangeraudit / index Health·Event hop is **done** (see above). Human P3-4 / P3-5 / P3-6 remain separate.

---

## P3-4 manual smoke matrix

The [HTML smoke matrix](./validations/r-milestone-smoke-matrix.html) provides one manual smoke for each R-* milestone. Open it in a browser, walk the listed flows, and mark the results. This eyes-on check remains necessary for UI behavior that automated gates cannot judge.

Use [06-test-framework.md](./06-test-framework.md) for local test and login prerequisites when preparing the environment.

---

## Completion record

- R-01 … R-19d completed the logic migration and eliminated direct `$DB` / `Ork3::$Lib` access from `orkui/`.
- Phase 3 automated audit VALIDATE-20 passed after FIX-06 … FIX-10.
- Idiom enforcement I-0 … I-VALIDATE completed with `status: ok`.
- Post-refactor rebase RB-0 … RB-Z (and later stacked tip including fuzzy-validator v2) validated on the Megiddo line.
- 2026-07-19 frontend residual hunt: `orkui/` gates clean; Player aggregate APIs tracked as **P3-R\***.
- 2026-07-19 bootstrap/API-coupling hop: `class.Controller`, auth audit call sites, and `index.php` Health/Event now go through models (no `Ork3::$Lib` / no controller `new Dangeraudit()`).
- 2026-07-19 P3-R0 plan package: [player-aggregates/](./player-aggregates/) (inventory, contract, milestones, orchestrator).

Track human close-out checkboxes in [04-milestone-checklist.md](./04-milestone-checklist.md).
