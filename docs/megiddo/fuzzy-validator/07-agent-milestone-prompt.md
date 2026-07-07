# Fuzzy Validator — Agent Milestone Prompt

Copy the prompt below into a new agent session. Replace `{{MILESTONE}}` with a specific milestone (e.g. `FU-0`, `FU-2`, `FU-10`) or leave the default to pick the next unchecked item in [08-milestone-checklist.md](./08-milestone-checklist.md).

---

## Prompt (copy from here)

```
You are implementing the ORK3 **Fuzzy Validator** — an automated refactor-stability harness under `tools/fuzzy-validator/`. It captures stabilized Playwright renders, learns fuzz allowances (pixels + DOM), hard-gates CSS/JS bytes, and produces pass/fail + a JaCoCo-style HTML report for Megiddo R-* sign-off.

This tool is separate from Megiddo DS/T/R milestones but consumes the same docker e2e stack when capturing live pages.

## Documentation (read before coding)

| Doc | Path | Purpose |
|-----|------|---------|
| Plan index | `docs/megiddo/fuzzy-validator/README.md` | Overview, two phases |
| Architecture | `docs/megiddo/fuzzy-validator/01-architecture.md` | Capture, calibration, gates |
| Implementation plan | `docs/megiddo/fuzzy-validator/02-implementation-plan.md` | FU-* scope and exit criteria |
| Manifest schema | `docs/megiddo/fuzzy-validator/03-manifest-schema.md` | pages.json5, fuzz JSON, thresholds |
| Operating guide | `docs/megiddo/fuzzy-validator/04-operating-guide.md` | calibrate / gate commands |
| Phase 2 | `docs/megiddo/fuzzy-validator/05-phase2-asset-dom-gate.md` | CSS/JS hard + DOM tree fuzz |
| Gate output | `docs/megiddo/fuzzy-validator/06-gate-output-and-report.md` | Pass/fail scores + HTML report |
| Milestone checklist | `docs/megiddo/fuzzy-validator/08-milestone-checklist.md` | Done state — update when finished |
| Test framework | `docs/megiddo/fuzzy-validator/09-test-framework.md` | pytest, 90% coverage |
| CLI reference | `docs/megiddo/fuzzy-validator/10-cli-reference.md` | **`bin/fuzzy-validator record|validate`** |

## Steering (non-negotiable)

- **FU-1:** One branch per milestone: `megiddo/fu-{n}-{slug}` (see implementation plan).
- **FU-2:** Exactly **one squashed commit** per milestone branch.
- **FU-3:** Implement **only** the active FU milestone — no drive-by refactors elsewhere in ORK3.
- **FU-4:** OSS only — Playwright + Python (Pillow, NumPy, OpenCV, Jinja2). No commercial visual services.
- **FU-5:** **Test the tool:** from FU-2 onward, `pytest` with **≥ 90% line coverage** on `tools/fuzzy-validator/python/` must pass before commit (`09-test-framework.md`).
- **FU-6:** Two gate outputs every run (when implemented): exit code pass/fail + `reports/run-{id}/index.html`.
- **FU-7:** Doc updates for the milestone belong in the same commit (checklist + plan notes if needed).

## Milestone to execute

{{MILESTONE}}

Default if not specified: **Execute the next unchecked FU milestone** in `docs/megiddo/fuzzy-validator/08-milestone-checklist.md` in order: FU-0 → FU-1 → … → FU-10. Skip completed milestones unless asked to revisit.

State the milestone ID, branch name, and exit criteria (from `02-implementation-plan.md`) before coding.

---

## Process (follow in order)

### 1. Read the plan for this milestone

- Open `02-implementation-plan.md` § for your FU-* id.
- Open `08-milestone-checklist.md` and confirm prior milestone is checked and committed.
- Read any linked detail doc (Phase 2 → `05-…`; report → `06-…`).

### 2. Branch hygiene

- Verify previous FU branch has **one commit** with all deliverables.
- Create `megiddo/fu-{n}-{slug}` from the last FU branch (or `main` for FU-0).
- All work on this branch only.

### 3. Implement

- Follow repo layout in `02-implementation-plan.md`.
- Reuse existing Playwright patterns from `tests/e2e/` (auth, docker reachability).
- Pilot pages until FU-4: `home-anonymous`, `home-authenticated`, `player-profile`.

### 4. Test the tool (required from FU-2+)

- Add unit tests under `tools/fuzzy-validator/python/tests/`.
- Use synthetic fixtures — no docker required for unit tests (`09-test-framework.md`).
- Run sign-off command:

  pytest tools/fuzzy-validator/python/tests/ \
    --cov=tools/fuzzy-validator/python/lib \
    --cov=tools/fuzzy-validator/python \
    --cov-report=term-missing \
    --cov-fail-under=90

- FU-0 / FU-1: add tests where code exists; full 90% enforcement starts at **FU-2 sign-off**.

### 5. Verify exit criteria

- Run milestone-specific manual checks from `02-implementation-plan.md` (e.g. capture 5 PNGs, gate pass/fail scenarios).
- For gate milestones: confirm exit code **and** report artifacts when in scope.

### 6. Update checklist

- Check off items in `docs/megiddo/fuzzy-validator/08-milestone-checklist.md`.
- Fill sign-off table row: date, commit hash, coverage %.

### 7. Commit

1. pytest coverage ≥ 90% (when applicable)
2. Stage all deliverables: `tools/fuzzy-validator/**`, doc checklist updates, root `package.json` if changed
3. Squash to **one commit**
4. Title format: `FU-2: Pixel fuzz discovery with pytest coverage`

Do not merge or push unless explicitly asked.

Report: milestone ID, branch, commit hash, coverage %, exit criteria met (yes/no), commands run, blockers.

---

Begin with step 1 now.
```

---

## Placeholder reference

| Placeholder | Replace with |
|-------------|----------------|
| `{{MILESTONE}}` | e.g. `FU-0: Scaffold` or `FU-3: Pixel gate` or `FU-10: HTML report + scoring` |

**Next unworked milestone:** Open [08-milestone-checklist.md](./08-milestone-checklist.md) → first unchecked FU-* in order.

## Milestone quick map

| ID | Branch | Primary deliverable | Tests |
|----|--------|---------------------|-------|
| FU-0 | `megiddo/fu-0-scaffold` | Layout, CLI stubs, npm scripts | Stubs only |
| FU-1 | `megiddo/fu-1-capture` | Playwright capture + stabilize | Optional TS smoke |
| FU-2 | `megiddo/fu-2-discover` | `discover_fuzz.py`, pixel overlay | pytest ≥ 90% |
| FU-3 | `megiddo/fu-3-gate` | `gate.py`, visual pass/fail | pytest ≥ 90% |
| FU-4 | `megiddo/fu-4-page-registry` | Expand `pages.json5` | Registry tests |
| FU-5 | `megiddo/fu-5-ci` | Optional GitHub Actions | CI runs pytest |
| FU-6 | `megiddo/fu-6-asset-capture` | CSS/JS byte capture | Asset manifest tests |
| FU-7 | `megiddo/fu-7-asset-gate` | `gate_assets.py` hard gate | pytest ≥ 90% |
| FU-8 | `megiddo/fu-8-dom-fuzz` | DOM tree calibration | pytest ≥ 90% |
| FU-9 | `megiddo/fu-9-unified-gate` | `--phase all`, `gate_run.py` v1 | E2E fixture test |
| FU-10 | `megiddo/fu-10-report` | HTML dashboard + `summary.json` | pytest ≥ 90% whole python/ |

## Per-milestone agent focus (one line each)

| Milestone | You are building… |
|-----------|-------------------|
| **FU-0** | Empty tree, shell scripts, `--help`, `.gitignore`, npm scripts — no algorithms yet. |
| **FU-1** | Stabilized Playwright capture loop → 5 PNGs/page; `pages.json5` pilot entries. |
| **FU-2** | Python pixel diff → bbox fuzz JSON + red calibration overlay; **start pytest fixtures**. |
| **FU-3** | Visual gate + exit code; fail on layout change outside fuzz; green/red overlay data. |
| **FU-4** | Register all e2e routes; validate registry schema. |
| **FU-5** | CI workflow: pytest 90% + optional docker gate artifact upload. |
| **FU-6** | Record every CSS/JS byte loaded; 5-run sha256 stability check. |
| **FU-7** | Zero-tolerance asset compare + diff files for report. |
| **FU-8** | Canonical DOM tree + consecutive-intersection fuzz nodes. |
| **FU-9** | Wire assets + DOM + visual; `gate_run.py` v1; unified exit code. |
| **FU-10** | Normalized scores (`visualMinScore`), full HTML report, `summary.json`, `FUZZ_GATE` stdout. |

## Related documents

- [02-implementation-plan.md](./02-implementation-plan.md)
- [08-milestone-checklist.md](./08-milestone-checklist.md)
- [09-test-framework.md](./09-test-framework.md)
