# fuzzy-validator

Refactor-stability harness for ORK3 front-end pages: stabilized Playwright capture, fuzz calibration, hard CSS/JS checks, fuzzy DOM tree compare, pixel gate, HTML report, setpoint bundles.

## Run from repo root

```bash
bin/fuzzy-validator setpoint restore    # once after clone
bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
bin/fuzzy-validator record --page player-profile --phase all

# npm aliases
npm run fuzz:validate -- --page home-anonymous
npm run fuzz:record -- --page home-anonymous
```

## Documentation

| Guide | Path |
|-------|------|
| **User guide** (workflows, reports, setpoints) | [docs/megiddo/fuzzy-validator/USER-GUIDE.md](../../docs/megiddo/fuzzy-validator/USER-GUIDE.md) |
| **Developer guide** (tests, extending the tool) | [docs/megiddo/fuzzy-validator/DEVELOPER-GUIDE.md](../../docs/megiddo/fuzzy-validator/DEVELOPER-GUIDE.md) |
| **Design & implementation** | [docs/megiddo/fuzzy-validator/12-design-and-implementation.md](../../docs/megiddo/fuzzy-validator/12-design-and-implementation.md) |
| Doc index | [docs/megiddo/fuzzy-validator/README.md](../../docs/megiddo/fuzzy-validator/README.md) |

## Layout

```
tools/fuzzy-validator/
  bin/fuzzy-validator          # CLI dispatcher
  python/                      # diff, gate, report, discover, tests/
  playwright/                  # capture + stabilization
  manifests/                   # pages.json5, profiles, fuzz JSON (committed)
  setpoint.json                # latest baseline bundle pointer (committed)
  setpoints/bootstrap/         # committed pilot zip for restore/CI
  baselines/                   # gitignored — restore via setpoint
  calibrations/                # ephemeral captures (gitignored)
  reports/                     # HTML report bundles (gitignored)
  evidence/                    # committed integration proof (FU-12+)
```

## Setup

```bash
pip install -r tools/fuzzy-validator/python/requirements.txt
pip install -r tools/fuzzy-validator/python/requirements-dev.txt   # for development
npx playwright install chromium
docker compose -f docker-compose.php8.yml up -d
```

## Tests

```bash
# Required before PR (≥ 90% coverage)
pytest tools/fuzzy-validator/python/tests/ \
  --cov=tools/fuzzy-validator/python/lib \
  --cov=tools/fuzzy-validator/python \
  --cov-fail-under=90

# Evidence integration suite (docker)
tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh
```

See [DEVELOPER-GUIDE.md](../../docs/megiddo/fuzzy-validator/DEVELOPER-GUIDE.md).

## CI

[`.github/workflows/fuzzy-validator.yml`](../../.github/workflows/fuzzy-validator.yml):

- **Required:** Python unit tests with ≥ 90% coverage
- **Optional:** Linux pixel gate after setpoint restore; uploads `reports/` artifacts

Set `ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD` for authenticated pilot pages.
