# Player aggregates — agent prompts (P3-R*)

Worker bodies for Task/sub-agents. Paste the full section for the active milestone. Sub-agents have no parent chat history.

Shared preamble for every worker:

```text
You are implementing Megiddo P3-R Player first-class aggregate APIs.
Repo root: ORK3 working tree on the active megiddo/* branch.
Plan package: docs/megiddo/refactor/player-aggregates/ (README, 01-inventory, 02-api-contract, 03-milestones).
Rules: Prefer extending Player / ClassLevel / Award. Controllers use Model_* only. No $DB / Ork3::$Lib in orkui/. Plan-only docs are done (P3-R0); do not expand scope to P3-4/5/6 human close-out. Do not push/PR unless asked. One commit for this milestone when complete. Return: status (ok|blocked|failed), files touched, commit SHA, PHPUnit result, open questions.
```

---

## P3-R1 worker

```text
Milestone: P3-R1 — Class level / progress API.

Read:
- docs/megiddo/refactor/player-aggregates/01-inventory.md §1
- docs/megiddo/refactor/player-aggregates/02-api-contract.md §B1
- docs/megiddo/refactor/player-aggregates/03-milestones.md §P3-R1
- system/lib/ork3/class.ClassLevel.php
- Player::ComputeClassProgress and Model_Attendance::enrich_classes_with_progress
- orkui/controller/controller.Player.php HighestClassLevel block (~449–468)

Tasks:
1. Compare Details['Classes'] credit semantics vs ComputeClassProgress; pick Option A or B; note choice in commit message.
2. Implement domain + Model_Player wrapper; wire Controller_Player::profile; delete threshold chain from controller.
3. Add/extend PHPUnit characterization.
4. Run full PHPUnit suite; confirm orkui/ has no new Lib/$DB and no threshold literals in controller.
5. Commit on branch megiddo/p3-r1-class-level-api (or stacked name from parent). Check off P3-R1 in docs/megiddo/refactor/04-milestone-checklist.md and 03-milestones.md.

Do not implement milestones, award maps, or reconcile in this hop.
```

---

## P3-R2 worker

```text
Milestone: P3-R2 — Milestones + award maps + ladder progress.

Read:
- docs/megiddo/refactor/player-aggregates/01-inventory.md §2–3
- docs/megiddo/refactor/player-aggregates/02-api-contract.md §B2–B4
- docs/megiddo/refactor/player-aggregates/03-milestones.md §P3-R2
- Award::GetLadderMasterMap
- Controller_Player profile milestones block (~515–669)
- Playernew_index.tpl $pnClassToParagon, $pnOrderToMaster, ladder tile build

Tasks:
1. Add Award catalogue helpers (ClassParagon, Knight, etc.); single-source ladder via GetLadderMasterMap.
2. Implement Player::GetPlayerMilestones + GetLadderProgress + Model_Player wrappers.
3. Thin controller assigns DTOs/maps; thin template — remove hardcoded maps and ladder algorithm.
4. PHPUnit for maps, milestone dedup, ladder Approx/Master cases.
5. Full suite + static gates; commit; check off P3-R2 in checklists.

Do not implement reconcile smart-rank (P3-R3) or fuzzy re-record (P3-R4) unless parent explicitly expands scope.
```

---

## P3-R3 worker

```text
Milestone: P3-R3 — Reconcile suggestions API.

Read:
- docs/megiddo/refactor/player-aggregates/01-inventory.md §4
- docs/megiddo/refactor/player-aggregates/02-api-contract.md §B5
- docs/megiddo/refactor/player-aggregates/03-milestones.md §P3-R3
- Playernew_reconcile.tpl lines ~13–73
- Player::GetReconcileAwardMap / Model_Player::get_reconcile_award_map

Tasks:
1. Port historical partition + smart-rank into Player::GetReconcilePageData (or pure helper + page DTO).
2. Characterization tests locking suggestion outcomes.
3. Wire Controller_Player::reconcile; template display-only. Optionally drive HasHistorical flags from same API.
4. Full suite + static gates; commit; check off P3-R3.

Do not register orkservice or run fuzzy capture unless asked.
```

---

## P3-R4 worker

```text
Milestone: P3-R4 — Wire + gate (+ optional orkservice).

Read:
- docs/megiddo/refactor/player-aggregates/03-milestones.md §P3-R4
- docs/megiddo/refactor/06-test-framework.md (fuzzy / e2e preflight)
- Confirm R1–R3 landed; grep inventory §7

Tasks:
1. Grep orkui/ for residual ladder rule literals; fix any leftovers.
2. Full PHPUnit; static Lib/$DB greps.
3. Fuzzy validate player-profile (test + mirror). Re-record only with intentional drift approval from human/parent.
4. Optional stretch: PlayerService registration for new DTOs — only if parent requests; not a UI blocker.
5. Check off P3-R4 in 04-milestone-checklist.md and 03-milestones.md; note remaining human P3-4/5/6 unchanged. Commit.

Return fuzzy scores and whether orkservice was touched.
```
