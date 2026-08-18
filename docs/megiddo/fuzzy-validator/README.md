# Fuzzy Validator — Documentation

Automated refactor-stability harness for Megiddo R-*: **hard CSS/JS byte checks**, **fuzzy DOM tree comparison**, and **fuzzy pixel screenshots** — with calibration-learned allowlists, dual DB profiles, setpoint bundles, and JaCoCo-style HTML reports.

**Run:** `bin/fuzzy-validator record|validate|refuzz|setpoint …`  
**Code:** `tools/fuzzy-validator/`  
**Status:** v1 shipped (FU-0 … FU-16). v2 overlays shipped: [version-2/](./version-2/). **v2.1 runner shipped:** [version-2.1/](./version-2.1/) — default containerized Ubuntu 26.04 + pinned Chromium.

---

## Start here

| I want to… | Read |
|------------|------|
| **Use** the tool (validate, record, setpoints, read reports) | **[USER-GUIDE.md](./USER-GUIDE.md)** |
| **Develop** the tool (tests, extend gates, debug) | **[DEVELOPER-GUIDE.md](./DEVELOPER-GUIDE.md)** |
| Understand **design decisions and implementation** | **[12-design-and-implementation.md](./12-design-and-implementation.md)** |
| Look up CLI flags, schemas, or gate/report contracts | **[reference/](./reference/)** |

---

## Quick start

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore

# Default path auto-starts the Ubuntu 26.04 runner (pinned Chromium).
bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
open tools/fuzzy-validator/reports/run-*/index.html
```

---

## Folder layout

| Path | Audience | Contents |
|------|----------|----------|
| *(this folder)* | Humans | README, USER-GUIDE, DEVELOPER-GUIDE, design overview |
| [reference/](./reference/) | Humans + agents | Live as-built specs (CLI, schemas, ops, reports, profiles) |
| [skills/](./skills/) | Agents | Orchestration skills (run-setpoint-drift, putative-drift-overlay) |
| [version-2/](./version-2/) | Planners / agents | Classified drift overlays (implemented) |
| [version-2.1/](./version-2.1/) | Planners / agents | Containerized runner plan (Ubuntu 26.04 + pinned Chromium) — **status: plan** |
| [archive/](./archive/) | Historical | Completed FU-* plans, prompts, checklists |

### Reorganization note (2026-07-19)

| Action | Items | Why |
|--------|-------|-----|
| **Kept at top level** | README, USER-GUIDE, DEVELOPER-GUIDE, 12-design | Human-readable entry points |
| **Moved to `reference/`** | 01, 03, 04, 06, 10, 11 + profiles example | Still needed for day-to-day ops and agent work |
| **Moved to `archive/`** | 02, 05, 07, 08, 09 | FU-* complete or superseded (09 → DEVELOPER-GUIDE) |
| **Added** | `skills/`, `version-2/` | Clean home for upcoming orchestration skills and the v2 plan |

---

## Two validation layers

| Layer | CSS/JS | DOM | Pixels |
|-------|--------|-----|--------|
| **Strictness** | Hard (0 bytes) | Fuzzy (learned nodes) | Fuzzy (learned bboxes) |
| **Record** | Assert stable across N runs | Discover fuzz | Discover fuzz |
| **Validate** | Byte diff | Tree diff − fuzz | Pixel diff − fuzz |

---

## Prerequisites

| Requirement | Notes |
|-------------|-------|
| Docker php8 stack | `docker compose -f docker-compose.php8.yml up -d` |
| Test sandbox | `bin/ork-db deploy-sandbox` for **test** profile |
| Mirror DB | Local `ork` for **mirror** profile |
| Node + Playwright | Root `package.json` |
| Python 3.11+ | `pip install -r tools/fuzzy-validator/python/requirements.txt` |
| Baselines | `bin/fuzzy-validator setpoint restore` after clone |

---

## Relationship to Megiddo refactor

| Artifact | Fuzzy Validator |
|----------|-----------------|
| T-* / R-* Playwright e2e | Behavior tests; same docker app |
| R-* optional gate | `bin/fuzzy-validator validate --phase all` |
| [ork-db](../test-database-tool/README.md) | DB profile switching |
| PHPUnit | Complements; does not replace |

---

## Gate outputs (every validate run)

1. **Pass / fail** — exit code + stdout `FUZZ_GATE …` line  
2. **HTML report** — `tools/fuzzy-validator/reports/run-{id}/index.html`

See [reference/06-gate-output-and-report.md](./reference/06-gate-output-and-report.md).

---

## Committed integration proof

| Location | Purpose |
|----------|---------|
| [tools/fuzzy-validator/evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md) | Evidence suite reviewer checklist |
| `evidence/reports/*-proof/index.html` | Pixel, DOM, asset, unified pass/fail HTML |
