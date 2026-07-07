# Fuzzy Validator — Test Framework

Tests validate the **tool itself** (not ORK3 pages under test). Target: **≥ 90% line coverage** on `tools/fuzzy-validator/python/` at each milestone sign-off from FU-2 onward.

---

## Layout

```
tools/fuzzy-validator/
  python/
    lib/                    # production code — coverage measured here
    tests/
      conftest.py           # shared fixtures (synthetic PNGs, HTML, assets)
      unit/
        test_diff_regions.py
        test_scoring.py
        test_tree_diff.py
        …
      integration/
        test_gate_run_fixtures.py   # end-to-end on checked-in fixture baselines
  playwright/
    tests/                  # optional: unit tests for pure TS helpers
      stabilize.test.ts
```

Playwright **capture** tests against live ORK3 docker are manual / optional integration — do not require docker for CI unit tests.

---

## Python (primary)

### Dependencies

Add to `tools/fuzzy-validator/python/requirements-dev.txt`:

```
pytest>=8.0
pytest-cov>=5.0
```

### Commands

From repo root:

```bash
pip install -r tools/fuzzy-validator/python/requirements.txt \
            -r tools/fuzzy-validator/python/requirements-dev.txt

# Full fuzz-tool unit suite + coverage (sign-off)
pytest tools/fuzzy-validator/python/tests/ \
  --cov=tools/fuzzy-validator/python/lib \
  --cov=tools/fuzzy-validator/python \
  --cov-report=term-missing \
  --cov-fail-under=90

# During development (single module)
pytest tools/fuzzy-validator/python/tests/unit/test_diff_regions.py -v
```

Coverage scope:

| Path | Included |
|------|----------|
| `python/lib/*.py` | Yes — primary target |
| `python/gate*.py`, `discover*.py` | Yes |
| `python/tests/` | No |

FU-0 / FU-1 may be below 90% until FU-2 lands the first Python library; from **FU-2 sign-off onward**, enforce `--cov-fail-under=90`.

### Fixture strategy

| Fixture | Purpose |
|---------|---------|
| `fixtures/png/` | Tiny 32×32 or copied pilot PNGs for pixel diff tests |
| `fixtures/html/` | Minimal HTML snippets for DOM canonicalization |
| `fixtures/assets/` | Pair of CSS/JS files differing by one byte |
| `fixtures/manifests/` | Sample `*.fuzz.json`, `*.dom-fuzz.json`, `defaults.json5` |
| `fixtures/trees/` | Pre-built canonical DOM JSON for tree_diff tests |

No network, no docker in unit tests.

### What to test (by module)

| Module | Minimum tests |
|--------|----------------|
| `diff_regions.py` | Pairwise mask, consecutive intersection, bbox merge |
| `scoring.py` | visual/dom/assets scores; pass at 1.0; fail below threshold |
| `tree_diff.py` | Tag/attr/text failures; fuzz node modes |
| `asset_manifest.py` | sha256 match/mismatch; added/removed asset |
| `gate.py` / `gate_assets.py` / `gate_dom.py` | Pass/fail on fixture baselines |
| `report_html.py` | Generates valid HTML; contains pass/fail and scores |
| `gate_run.py` | Exit code + `summary.json` on fixture run |

---

## TypeScript (secondary)

Pure helpers in `playwright/lib/` (e.g. CSS injection string, registry parsing) may use **Node test runner** or **Vitest** if added — not required for 90% Python coverage target.

Playwright spec smoke test (optional, docker):

```bash
docker compose -f docker-compose.php8.yml up -d
npm run fuzz:capture -- --pages home-anonymous
```

Document as manual / nightly — not milestone sign-off blocker unless milestone is FU-1 and capture is the deliverable.

---

## Milestone sign-off (testing)

Before each FU-* commit (from FU-2+):

1. `pytest … --cov-fail-under=90` — **must pass**
2. Record coverage % in [08-milestone-checklist.md](./08-milestone-checklist.md) sign-off table
3. New modules in the milestone must include unit tests in the same commit

---

## CI (FU-5 / FU-10)

Workflow job (Linux):

```yaml
- run: pip install -r tools/fuzzy-validator/python/requirements-dev.txt
- run: pytest tools/fuzzy-validator/python/tests/ --cov-fail-under=90
```

Separate optional job: docker compose + `npm run fuzz:gate:all -- --pages home-anonymous` on PRs touching `orkui/`.
