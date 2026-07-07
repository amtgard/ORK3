# fuzzy-validator

Refactor-stability harness for ORK3 front-end pages: stabilized Playwright capture, fuzz calibration, hard CSS/JS checks, fuzzy DOM tree compare, pixel gate, HTML report.

## Run from repo root

```bash
bin/fuzzy-validator record --pages home-anonymous
bin/fuzzy-validator validate --urls path/to/pages.txt

# npm aliases
npm run fuzz:capture -- --pages home-anonymous
```

## Documentation

All planning and CLI reference live under **`docs/megiddo/fuzzy-validator/`** (start at [README](../../docs/megiddo/fuzzy-validator/README.md)).

## Layout (target)

```
tools/fuzzy-validator/
  bin/fuzzy-validator          # CLI dispatcher (this directory)
  python/                      # diff, gate, report, discover
  playwright/                  # capture + stabilization
  manifests/                   # pages.json5, defaults, fuzz JSON
  baselines/                   # golden artifacts (committed)
  calibrations/                # ephemeral captures (gitignored)
  reports/                     # HTML report bundles (gitignored)
```

## Setup (when implemented)

```bash
pip install -r tools/fuzzy-validator/python/requirements.txt
pip install -r tools/fuzzy-validator/python/requirements-dev.txt
npx playwright install chromium
docker compose -f docker-compose.php8.yml up -d
```
