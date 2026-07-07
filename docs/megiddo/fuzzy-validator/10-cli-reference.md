# Fuzzy Validator — CLI Reference

**Entry point (repo root):** `bin/fuzzy-validator`  
**Implementation:** `tools/fuzzy-validator/bin/fuzzy-validator` → Python orchestrator + Playwright capture

---

## How running works

```
  bin/fuzzy-validator
        │
        ▼
  tools/fuzzy-validator/bin/fuzzy-validator   (bash dispatcher)
        │
        ├── record  ──► Playwright ×N capture
        │                 ├── Python discover (pixel + DOM fuzz)
        │                 ├── Asset stability check (Phase 2)
        │                 └── Write baselines/ + manifests/
        │
        └── validate ──► Playwright ×1 capture
                          ├── Python gate (assets, DOM, pixels)
                          ├── scoring.py → pass/fail + exit code
                          └── report_html.py → reports/run-{id}/
```

**Requirements on PATH:** `python3`, `node`, `npx` (Playwright). ORK3 app reachable at `ORK3_E2E_BASE_URL` (default `http://localhost:19080/orkui/`).

**Working directory:** Commands assume repo root; paths to baselines and reports are under `tools/fuzzy-validator/`.

---

## Command palette

### `record` — establish baselines + fuzz allowances

Run on a **stable commit** before refactor (or after accepting intentional UI change).

```bash
# Single URL (positional)
bin/fuzzy-validator record 'http://localhost:19080/orkui/index.php?Route=Player/profile'

# Multiple URLs
bin/fuzzy-validator record \
  'http://localhost:19080/orkui/index.php?Route=' \
  'http://localhost:19080/orkui/index.php?Route=Player/profile'

# URL list file (one URL per line; # comments allowed)
bin/fuzzy-validator record --urls tools/fuzzy-validator/manifests/pilot-urls.txt

# Registered page id (resolves url + auth from pages.json5)
bin/fuzzy-validator record --page player-profile
bin/fuzzy-validator record --pages home-anonymous,home-authenticated

# All non-skipped entries in pages.json5
bin/fuzzy-validator record --all
```

**What `record` does:**

1. Stabilize page (fixed clock, disable animations, networkidle, …)
2. Capture **N** renders per target (default 5)
3. Learn fuzz zones (pixel bboxes + DOM tree nodes) from consecutive-run intersection
4. Assert CSS/JS asset manifests identical across N runs (Phase 2)
5. Write committed artifacts: `baselines/`, `manifests/*.fuzz.json`, `manifests/*.dom-fuzz.json`
6. Write debug overlays under `reports/record-{id}/` (review before committing baselines)

**Exit code:** `0` on success, `1` if capture/discovery fails, `2` if URLs unreachable or asset manifests unstable across N runs.

---

### `validate` — compare to baselines (lights-out + report)

Run on a **refactor branch** after baselines exist.

```bash
bin/fuzzy-validator validate --page player-profile

bin/fuzzy-validator validate --urls tools/fuzzy-validator/manifests/pilot-urls.txt

bin/fuzzy-validator validate --all
```

**What `validate` does:**

1. Single stabilized capture per target
2. **Assets:** hard byte compare vs baseline (Phase 2)
3. **DOM:** tree diff vs baseline minus fuzz nodes (Phase 2)
4. **Pixels:** diff vs baseline PNG minus bbox fuzz (Phase 1)
5. Compute stability scores; compare to thresholds
6. **Pass/fail:** exit `0` or `1`; stdout `FUZZ_GATE …` line
7. **Report:** `tools/fuzzy-validator/reports/run-{id}/index.html`

**Exit code:** `0` pass, `1` fail, `2` harness error (missing baseline, bad manifest).

---

## URL inputs

| Form | Example | Notes |
|------|---------|-------|
| Positional URL | `'http://localhost:19080/orkui/index.php?Route='` | Full URL; auth via flags or registry |
| `--urls FILE` | `--urls urls.txt` | One URL per line |
| `--page ID` | `--page player-profile` | Lookup in `manifests/pages.json5` |
| `--pages a,b` | `--pages home-anonymous,player-profile` | Multiple registry ids |
| `--all` | | All pages where `skip != true` |

**URL list file format:**

```text
# pilot-urls.txt
http://localhost:19080/orkui/index.php?Route=
http://localhost:19080/orkui/index.php?Route=Player/profile
# page id also allowed when prefixed:
page:player-profile
```

When a line starts with `page:`, resolve via registry (auth, viewport, waits).

---

## Shared options

| Option | Commands | Default | Description |
|--------|----------|---------|-------------|
| `--phase` | both | `all` | `visual` \| `assets` \| `dom` \| `all` |
| `--repeat N` | record | `5` | Calibration capture count |
| `--base-url URL` | both | `$ORK3_E2E_BASE_URL` | Override app base |
| `--run-id ID` | both | UTC timestamp | Report directory name |
| `--report-dir PATH` | validate | `reports/run-{id}` | HTML bundle output |
| `--visual-min-score` | validate | `1.0` | Pixel pass threshold (e.g. `0.98`) |
| `--dom-min-score` | validate | `1.0` | DOM pass threshold |
| `--assets-min-score` | validate | `1.0` | Asset pass threshold (keep at 1.0 for refactor) |
| `--defaults FILE` | both | `manifests/defaults.json5` | Threshold overrides |
| `--dry-run` | both | off | Print planned targets, no capture |
| `-h, --help` | both | | Subcommand help |

### Auth (ORK3)

| Option | Description |
|--------|-------------|
| `--auth login` | Run login flow before each URL |
| `--username` / `--password` | Override `ORK3_E2E_USERNAME` / `ORK3_E2E_PASSWORD` |
| *(registry)* | Pages with `"auth": "login"` in `pages.json5` login automatically |

---

## Examples (Megiddo R-*)

**Before first R-* on player profile routes:**

```bash
docker compose -f docker-compose.php8.yml up -d
export ORK3_E2E_USERNAME=… ORK3_E2E_PASSWORD=…

bin/fuzzy-validator record --pages home-anonymous,player-profile --phase all
# Review reports/record-*/ then commit baselines/ + manifests/
```

**At R-* sign-off:**

```bash
bin/fuzzy-validator validate --pages home-anonymous,player-profile --phase all
echo $?   # 0 = pass
open tools/fuzzy-validator/reports/run-*/index.html
```

**CI (lights-out):**

```bash
bin/fuzzy-validator validate --urls "$URLS_FILE" --phase all --visual-min-score 0.98
# Upload tools/fuzzy-validator/reports/run-*/ as artifact
```

---

## Mapping from internal names

| User command | Internal modules (implementation) |
|--------------|-----------------------------------|
| `record` | `capture.spec.ts` + `discover_fuzz.py` + `discover_dom_fuzz.py` |
| `validate` | `capture.spec.ts` + `gate_assets.py` + `gate_dom.py` + `gate.py` + `gate_run.py` |

Legacy npm aliases (optional, FU-0+):

```json
"fuzz:record": "bin/fuzzy-validator record",
"fuzz:validate": "bin/fuzzy-validator validate"
```

---

## Implementation notes (FU-0+)

1. **`bin/fuzzy-validator`** — repo-root wrapper; always use this in docs and CI.
2. **`tools/fuzzy-validator/bin/fuzzy-validator`** — bash dispatcher → `python3 -m fuzzy_validator.cli` (planned FU-0).
3. Python package `tools/fuzzy-validator/python/fuzzy_validator/cli.py` with `record` / `validate` subcommands using `argparse`.
4. Playwright invoked via `npx playwright test` with env `FUZZ_PAGES=…` or programmatic API from Python.

---

## Related docs

- [04-operating-guide.md](./04-operating-guide.md) — review workflow
- [06-gate-output-and-report.md](./06-gate-output-and-report.md) — pass/fail + HTML report
- [03-manifest-schema.md](./03-manifest-schema.md) — `pages.json5`, thresholds
