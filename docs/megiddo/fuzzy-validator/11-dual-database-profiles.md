# Fuzzy Validator — Dual Database Profiles

**Status:** Plan (not implemented)  
**Depends on:** [ork-db test database tool](../test-database-tool/README.md) (TD-6+), FU-1+ capture harness

Every `record` and `validate` run targets one or both **database profiles**. The ORK3 app reads data through a single MariaDB connection; the profile selects which database the **local docker app** uses via `bin/ork-db use dev|prod`.

---

## 1. Why two profiles

| Profile | DB | Data | Fuzz role |
|---------|-----|------|-----------|
| **`test`** | `ork_test` @ `19307` (sandbox) | Synthetic stable dataset from `bin/ork-db apply` | **Primary gate** — strict thresholds, reproducible baselines |
| **`mirror`** | `ork` @ `19306` (local mirror) | Real-shaped local copy of prod/dev dump | **Secondary gate** — looser thresholds, catches issues visible only on live-shaped data |

Refactor regressions must not change rendered output on **either** profile. The sandbox is the authoritative stability surface; the mirror catches drift that only appears with real catalog volume, real operators, or mirror-specific volatility.

**Do not** run fuzzy-validator against remote production hosts. Both profiles are **local docker only** (`localhost:19080`).

---

## 2. Mapping to `bin/ork-db`

| Fuzzy profile | `ork-db` command | App DB | Container |
|---------------|------------------|--------|-----------|
| `test` | `bin/ork-db use dev` | `ork_test` @ `19307` | `ork3-php8-test-db` |
| `mirror` | `bin/ork-db use prod` | `ork` @ `19306` | `ork3-php8-db` |

Before each profile pass, the validator:

1. Runs `bin/ork-db use <dev|prod>` (writes `.ork3-db.local`)
2. Restarts or waits for `ork3-php8-app` to pick up the profile (same as manual dev workflow)
3. Optionally runs `bin/ork-db validate --mode post-apply` on **test** profile only when `--ensure-sandbox` is set

See [test-database-tool 10-cli-reference.md](../test-database-tool/10-cli-reference.md).

---

## 3. CLI — profile flags

| Flag | Commands | Default | Description |
|------|----------|---------|-------------|
| `--profile NAME` | record, validate | — | Single profile: `test` or `mirror` |
| `--profiles LIST` | record, validate | `test,mirror` | Comma-separated; runs sequentially |
| `--ensure-sandbox` | record, validate | off | Before `test` pass: `bin/ork-db deploy-sandbox` or `bootstrap` if stale |

**Default behaviour:** both profiles on every `record` and `validate` unless `--profile test` (or `mirror`) alone is specified.

```bash
# Record baselines for both profiles (stable branch)
bin/fuzzy-validator record --pages player-profile --phase all

# Validate both at R-* sign-off (strict test + lenient mirror)
bin/fuzzy-validator validate --pages player-profile --phase all

# Sandbox-only quick check
bin/fuzzy-validator validate --profile test --page home-authenticated

# Re-record mirror baselines after mirror DB refresh
bin/ork-db extract && bin/ork-db apply --yes   # refresh sandbox only
bin/fuzzy-validator record --profile mirror --all
```

---

## 4. Threshold tiers (strict test, lenient mirror)

Stored in `tools/fuzzy-validator/manifests/profiles.json5`. **Test is always stricter** (higher minimum scores).

```json5
{
  "profiles": {
    "test": {
      "orkDbUse": "dev",
      "label": "Sandbox (ork_test)",
      "thresholds": {
        "assetsMinScore": 1.0,
        "domMinScore": 1.0,
        "visualMinScore": 1.0
      },
      "auth": {
        "username": "megiddo",
        "passwordEnv": "ORK3_E2E_TEST_PASSWORD",
        "passwordDefault": "test-db-player"
      }
    },
    "mirror": {
      "orkDbUse": "prod",
      "label": "Mirror (ork)",
      "thresholds": {
        "assetsMinScore": 1.0,
        "domMinScore": 0.99,
        "visualMinScore": 0.98
      },
      "auth": {
        "username": "admin",
        "passwordDefault": "password",
        "usernameEnv": "ORK3_E2E_USERNAME",
        "passwordEnv": "ORK3_E2E_PASSWORD"
      }
    }
  },
  "defaultProfiles": ["test", "mirror"]
}
```

| Layer | `test` | `mirror` | Notes |
|-------|--------|----------|-------|
| Assets (CSS/JS) | **1.0** | **1.0** | Byte-identical on both — refactor must not touch static assets |
| DOM tree | **1.0** | **0.99** | Mirror may have minor dynamic markup outside learned fuzz |
| Pixels | **1.0** | **0.98** | Mirror: font/subpixel/live-widget slack |

CLI overrides apply **within** a profile pass: `--visual-min-score 0.99` on `validate --profile mirror` replaces mirror’s default `0.98` for that run only.

---

## 5. Baseline and manifest layout

Artifacts are **per profile** — never share baselines between `test` and `mirror`.

```
tools/fuzzy-validator/
  baselines/
    test/
      {pageId}.png
      {pageId}.dom.json
      {pageId}.assets.json
      assets/{pageId}/…
    mirror/
      {pageId}.png
      …
  manifests/
    test/
      {pageId}.fuzz.json
      {pageId}.dom-fuzz.json
    mirror/
      …
```

`record --profiles test,mirror` runs capture + fuzz discovery twice (switching DB between passes).

---

## 6. Report and pass/fail

Dual-profile `validate` produces one HTML report with **sections per profile**:

```
index.html
  ├── Summary: PASS only if test AND mirror pass
  ├── Profile: test  (strict thresholds)
  │     └── pages/{pageId}.html
  └── Profile: mirror (lenient thresholds)
        └── pages/{pageId}.html
```

Stdout:

```
FUZZ_GATE run=20260707T190000Z profiles=test,mirror pass=2 fail=0 exit=0
  [test]   player-profile  PASS  assets=1.00 dom=1.00 visual=1.00
  [mirror] player-profile  PASS  assets=1.00 dom=0.995 visual=0.983
```

**Exit 1** if **any** profile × page fails its tier thresholds.

---

## 7. Recommended workflow

### First-time setup

```bash
docker compose -f docker-compose.php8.yml up -d
bin/ork-db deploy-sandbox          # test DB ready
# mirror: import local dump into ork @ 19306 if not already present

bin/fuzzy-validator record --phase all --pages home-anonymous,player-profile
# Commits baselines/test/* and baselines/mirror/*
```

### R-* sign-off (optional gate alongside PHPUnit)

```bash
bin/ork-db deploy-sandbox          # fresh sandbox
bin/fuzzy-validator validate --phase all --pages <milestone-pages>
```

### When mirror baselines drift

Mirror data changes when the local dump is refreshed — not on every refactor. Re-`record --profile mirror` only after intentional mirror refresh, not after every R-*.

Sandbox baselines should remain stable across refactors; re-`record --profile test` only when fuzz manifests need recalibration.

---

## 8. PHPUnit / integration tests

PHPUnit uses `ENVIRONMENT=TEST` → `config.test.php` → **`ork_test` @ `19307`** directly (host), independent of `bin/ork-db use`. Fuzzy-validator **`test`** profile aligns with the same sandbox data the integration suite expects after `deploy-sandbox`.

See [refactor 06-test-framework.md](../refactor/06-test-framework.md).

---

## 9. Implementation milestone

**FU-11** (after FU-10): profile switching, split baselines, tiered thresholds, dual-profile report sections.

---

## Related docs

- [10-cli-reference.md](./10-cli-reference.md) — full CLI flags
- [03-manifest-schema.md](./03-manifest-schema.md) — `profiles.json5` schema
- [../test-database-tool/README.md](../test-database-tool/README.md) — sandbox tool
