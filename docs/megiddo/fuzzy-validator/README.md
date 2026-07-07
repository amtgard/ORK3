# Fuzzy Validator — Documentation

Automated refactor-stability harness for Megiddo R-*: **hard CSS/JS byte checks**, **fuzzy DOM tree comparison**, and **fuzzy pixel screenshots** — with calibration-learned allowlists, dual DB profiles, setpoint bundles, and JaCoCo-style HTML reports.

**Run:** `bin/fuzzy-validator record|validate|setpoint …`  
**Code:** `tools/fuzzy-validator/`

---

## Start here

| I want to… | Read |
|------------|------|
| **Use** the tool (validate, record, setpoints, read reports) | **[USER-GUIDE.md](./USER-GUIDE.md)** |
| **Develop** the tool (tests, extend gates, debug) | **[DEVELOPER-GUIDE.md](./DEVELOPER-GUIDE.md)** |
| Understand **design decisions and implementation** | **[12-design-and-implementation.md](./12-design-and-implementation.md)** |

---

## Quick start

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox
bin/fuzzy-validator setpoint restore

bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
open tools/fuzzy-validator/reports/run-*/index.html
```

---

## Documentation map

### Primary (maintained for operators and developers)

| Doc | Audience | Purpose |
|-----|----------|---------|
| [USER-GUIDE.md](./USER-GUIDE.md) | R-* devs, maintainers | Workflows, profiles, setpoints, reading reports |
| [DEVELOPER-GUIDE.md](./DEVELOPER-GUIDE.md) | Tool contributors | Unit/integration tests, extending code, CI |
| [12-design-and-implementation.md](./12-design-and-implementation.md) | Architects, agents | Design decisions, module map, data flow |
| [10-cli-reference.md](./10-cli-reference.md) | All | Complete CLI flag reference |
| [04-operating-guide.md](./04-operating-guide.md) | Maintainers | Detailed operating procedures ( overlaps USER-GUIDE ) |

### Reference

| Doc | Purpose |
|-----|---------|
| [01-architecture.md](./01-architecture.md) | Original architecture spec (algorithms, stabilization) |
| [03-manifest-schema.md](./03-manifest-schema.md) | Page registry, fuzz JSON schemas |
| [05-phase2-asset-dom-gate.md](./05-phase2-asset-dom-gate.md) | Asset + DOM gate detail |
| [06-gate-output-and-report.md](./06-gate-output-and-report.md) | Pass/fail scoring + HTML report |
| [11-dual-database-profiles.md](./11-dual-database-profiles.md) | Test vs mirror profiles |
| [09-test-framework.md](./09-test-framework.md) | Test plan index → see DEVELOPER-GUIDE |

### Project history (agents / milestones)

| Doc | Purpose |
|-----|---------|
| [02-implementation-plan.md](./02-implementation-plan.md) | FU-* milestone plan |
| [07-agent-milestone-prompt.md](./07-agent-milestone-prompt.md) | Agent prompt template |
| [08-milestone-checklist.md](./08-milestone-checklist.md) | FU-0 … FU-16 completion |

### Committed integration proof

| Location | Purpose |
|----------|---------|
| [tools/fuzzy-validator/evidence/README.md](../../../tools/fuzzy-validator/evidence/README.md) | Evidence suite reviewer checklist |
| `evidence/reports/*-proof/index.html` | Pixel, DOM, asset, unified pass/fail HTML |

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

See [06-gate-output-and-report.md](./06-gate-output-and-report.md).
