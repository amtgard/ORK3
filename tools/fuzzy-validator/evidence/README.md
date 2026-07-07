# Fuzzy Validator — Evidence Suite

Committed integration proof for Phase 3 (FU-12…FU-15). Reviewers can open HTML reports here without running docker.

## Layout

| Path | Purpose |
|------|---------|
| `pages.json5` | Evidence page subset (`player-profile`, `home-authenticated`) |
| `baselines/test/` | Virgin `record` output (PNG, DOM, assets) |
| `manifests/test/` | Learned `*.fuzz.json` and `*.dom-fuzz.json` |
| `reports/pixel-proof/` | Pixel discover overlay + in-zone pass + out-of-zone fail |
| `reports/dom-proof/` | DOM fuzz debug + in-zone pass + out-of-zone fail |
| `reports/assets-proof/` | Hard gate pass + CSS/JS 1-byte fail with diffs |
| `reports/unified-proof/` | `--phase all` composite pass/fail |
| **`reports/index.html`** | **Top-level hub — open this first** |
| `scripts/run-evidence-suite.sh` | Orchestrates discover → validate; asserts exit codes |

## Re-run (maintainer)

Prerequisites: docker compose php8 up, `bin/ork-db deploy-sandbox`, `ORK3_E2E_BASE_URL` set.

```bash
# Virgin capture (FU-12)
bin/fuzzy-validator record \
  --tool-root tools/fuzzy-validator/evidence \
  --profile test \
  --pages player-profile,home-authenticated \
  --phase all

# Full evidence suite (FU-13…FU-15)
tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh
```

Use `--tool-root tools/fuzzy-validator/evidence` on all `record` / `validate` commands so baselines and manifests stay under `evidence/`.

Optional CI: `.github/workflows/fuzzy-validator-evidence.yml` (`workflow_dispatch` or weekly cron). Does **not** block pytest CI.

## Reviewer checklist (FU-15 sign-off)

Open **`reports/index.html`** (single entry point), then drill into each layer:

1. **`reports/index.html`** — hub linking all layers and pass/fail scenarios
2. **Pixel** — green heraldry fuzz bbox on calibration overlay; in-zone PASS; out-of-zone FAIL
3. **DOM** — session token attr fuzzed; in-zone PASS; structural heading change FAIL
4. **Assets** — same-commit PASS; 1-byte CSS and JS mutations FAIL with unified diffs
5. **Unified** — all layers on `home-authenticated`: in-zone PASS; out-of-zone DOM FAIL

Also confirm:

5. `manifests/test/player-profile.fuzz.json` has non-empty `fuzzZones`.
6. `manifests/test/home-authenticated.dom-fuzz.json` has non-empty `fuzzNodes`.
7. `scripts/run-evidence-suite.sh` exits 0 locally (or via optional evidence workflow).

See [mutations.md](./mutations.md) for in-zone / out-of-zone recipes.

Cross-ref: [09-test-framework.md](../../docs/megiddo/fuzzy-validator/09-test-framework.md) § Evidence suite.
