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
| `reports/assets-proof/` | *(FU-14)* hard gate pass/fail |
| `reports/unified-proof/` | *(FU-15)* `--phase all` composite |
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

# Full evidence suite (FU-13+)
tools/fuzzy-validator/evidence/scripts/run-evidence-suite.sh
```

Use `--tool-root tools/fuzzy-validator/evidence` on all `record` / `validate` commands so baselines and manifests stay under `evidence/`.

## Reviewer checklist (FU-13)

1. Open `reports/pixel-proof/index.html` — green heraldry bbox; in-zone PASS; out-of-zone FAIL with red boxes.
2. Open `reports/dom-proof/index.html` — volatile session node fuzzed; in-zone PASS; structural change FAIL.
3. Confirm `manifests/test/player-profile.fuzz.json` has non-empty `fuzzZones`.
4. Confirm `manifests/test/home-authenticated.dom-fuzz.json` has non-empty `fuzzNodes`.
5. Run `scripts/run-evidence-suite.sh` — exit 0.

See [mutations.md](./mutations.md) for in-zone / out-of-zone recipes.

Cross-ref: [09-test-framework.md](../../docs/megiddo/fuzzy-validator/09-test-framework.md) § Evidence suite.
