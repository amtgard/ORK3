# Fuzzy Validator — Plan Index

Automated, non-commercial regression harness for the Megiddo **refactor**. Validates that frontend output stays stable across R-* sprints: **hard CSS/JS byte checks**, **fuzzy DOM tree comparison**, and **fuzzy pixel screenshots** — each with calibration-learned allowlists where intrinsic volatility exists.

**Run:** `bin/fuzzy-validator record|validate …` — see [10-cli-reference.md](./10-cli-reference.md)

**Code:** `tools/fuzzy-validator/` · **Docs:** `docs/megiddo/fuzzy-validator/`

---

## Documents

| Doc | Purpose |
|-----|---------|
| [01-architecture.md](./01-architecture.md) | Pipeline, stabilization, Python diff algorithm, data flow |
| [02-implementation-plan.md](./02-implementation-plan.md) | Phases, repo layout, commands, milestones, acceptance criteria |
| [03-manifest-schema.md](./03-manifest-schema.md) | Page registry, fuzz-zone JSON format, baseline metadata |
| [04-operating-guide.md](./04-operating-guide.md) | Day-to-day: record, validate, review baselines |
| [10-cli-reference.md](./10-cli-reference.md) | **`bin/fuzzy-validator` command palette** |
| [11-dual-database-profiles.md](./11-dual-database-profiles.md) | **Test sandbox + mirror profiles, tiered thresholds** |
| [05-phase2-asset-dom-gate.md](./05-phase2-asset-dom-gate.md) | Phase 2: hard CSS/JS + fuzzy DOM tree gate |
| [06-gate-output-and-report.md](./06-gate-output-and-report.md) | Pass/fail scoring + JaCoCo-style HTML report |
| [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) | Copy-paste agent prompt per FU milestone |
| [08-milestone-checklist.md](./08-milestone-checklist.md) | FU-* completion checkboxes |
| [09-test-framework.md](./09-test-framework.md) | pytest + 90% coverage sign-off |
| [11-dual-database-profiles.md](./11-dual-database-profiles.md) | Test sandbox + mirror profiles, tiered thresholds |

---

## Quick start

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox    # stable test dataset (ork_test @ 19307)

# Record baselines on BOTH profiles (default): strict test + lenient mirror
bin/fuzzy-validator record --page player-profile

# Refactor sign-off — must pass test (1.0) and mirror (≥0.98 visual)
bin/fuzzy-validator validate --page player-profile
```

See [11-dual-database-profiles.md](./11-dual-database-profiles.md) for profile switching via `bin/ork-db use dev|prod`.

---

## Two phases

| Phase | Scope | Strictness |
|-------|-------|------------|
| **Phase 1** (FU-0 … FU-5) | Screenshot pixel gate | Fuzzy bbox allowlist + small outside-diff budget |
| **Phase 2** (FU-6 … FU-9) | CSS, JavaScript, DOM tree | CSS/JS **byte-for-byte**; DOM **tree compare** with calibration-learned fuzz nodes |

Refactor assumption: CSS and JS should not change at all; DOM changes are minimal and non-pervasive; rendered pixels match outside learned fuzz zones.

Out of scope: commercial visual platforms, manual dashboard ignore regions.

---

## Quick pipeline (Phase 1 — pixels)

```
pages.json5  →  Playwright capture (×N, stabilized)  →  calibration PNGs
                                                              ↓
                                                    Python discover_fuzz.py
                                                              ↓
                                              fuzz manifest (bounding boxes)
                                                              ↓
         baseline PNG (run once on stable branch)  +  manifest
                                                              ↓
              R-* branch: capture  →  Python gate.py  →  pass / fail + diff report
```

## Quick pipeline (Phase 2 — assets + DOM)

```
pages.json5  →  Playwright capture (×N)  →  PNG + dom.html + asset bytes
                        ↓                              ↓                ↓
              discover_fuzz.py              discover_dom_fuzz.py   assert N identical
              (pixel boxes)                 (tree node paths)      asset sha256 sets
                        ↓                              ↓                ↓
              baselines/*.png              baselines/*.dom.json   baselines/*.assets.json
                        └──────────────────────────┬─────────────────────────┘
                                                   ↓
                         R-* gate --phase all  →  gate_run.py
                                                   ├─ exit 0/1 (lights-out)
                                                   └─ reports/run-{id}/index.html
```

## Two outputs (every gate run)

| Output | Purpose |
|--------|---------|
| **Pass / Fail** | Exit code + stdout scores; normalized stability in `[0,1]` vs configurable thresholds (default **1.0**; pixel layer tunable e.g. **0.98**) |
| **HTML report** | JaCoCo-style static site: summary dashboard, per-page drill-down, annotated screenshots (**green** = fuzz, **red** = regression), optional long-form CSS/JS/DOM diffs |

See [06-gate-output-and-report.md](./06-gate-output-and-report.md).

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| Docker php8 stack | `docker compose -f docker-compose.php8.yml up -d` |
| **Test sandbox** | `bin/ork-db deploy-sandbox` — app on **`test`** profile uses `ork_test` @ `19307` |
| **Mirror DB** | Local `ork` @ `19306` — app on **`mirror`** profile via `bin/ork-db use prod` |
| Node + Playwright | Root `package.json` |
| Python 3.11+ | `pip install -r tools/fuzzy-validator/python/requirements.txt` |
| Auth | Profile-specific — see `manifests/profiles.json5` (`test`: sandbox user; `mirror`: `ORK3_E2E_*`) |

---

## Relationship to Megiddo refactor

| Megiddo artifact | Fuzzy Validator |
|------------------|-------------------|
| T-* / R-* Playwright e2e | Behavior tests; same docker app, may use either DB profile |
| R-* optional gate | `bin/fuzzy-validator validate` — **both** profiles, tiered scores |
| [ork-db](../test-database-tool/README.md) | Switches app DB; sandbox supplies stable **`test`** profile data |
| FR-3 no regression | Complements PHPUnit + e2e; does not replace them |

Recommended usage:

1. **Phase 1** — `record` pixel fuzz once per page group; `validate` during early R-* pilots.
2. **Phase 2** — `record --phase all` before first R-* on a page; `validate --phase all` at each R-* sign-off.
