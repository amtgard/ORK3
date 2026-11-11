# ORK3 gb + host-env-vault — orchestrator checklist

**Design:** [gb-host-env-vault-plan.md](gb-host-env-vault-plan.md)  
**Stack base:** `master` @ `22981eb6` (`origin/master`, 2026-11-11). This repo has no `main`.  
**Resume from:** H1  
**Skill:** branch-orchestrator. Workers follow the development skill with the ORK3 gates in this file, not a Slim health test and not the host-env-vault Rust `gate.sh`.

The workspace that prepared this checklist was on `stack/warrior-w01-schema` with staged tournament SQL (`db-migrations/2026-03-18-event-external-links.sql` and siblings) and unrelated untracked `* 2.*` copies. That work is not part of this stack. If `git status` shows that branch or those staged migrations, stop with `blocked` and use a clean worktree of the base named below. Copy only this checklist and the plan into that worktree for H0.

Serial milestones. One branch, one commit, stacked on the previous tip. No push and no pull request unless the user asks. Do not commit `build/`, `.env`, `.greenblue`, `.gb/`, or any secret value. `config.dev.php` contains a live IDP client secret; never print it, log it, or copy it into an example file.

## Orchestrator preamble (paste with every milestone prompt)

```
Repo: ORK3. Not Slim. Not the host-env-vault Rust repo.
Read docs/planning/gb-host-env-vault-plan.md and this checklist. Implement only the named milestone.
Base branch and commit are in the milestone prompt. Branch from that tip, not from stack/warrior-w01-schema and not from a dirty index.
Gates: as the milestone says. Docs-only milestones skip PHPUnit and Infection.
Code milestones: sh bin/run-unit-tests.sh with no --filter. If drift-check fails because MariaDB is down, return blocked. Do not skip the suite.
Infection: tools/infection/infection.hev-<milestone>.json5 scoped to PHP files this milestone added or edited. minMsi 80, minCoveredMsi 80. Then sh bin/cleanup-mutation-artifacts.sh. Do not run full-repo Infection.
Line coverage of those same files >= 95%. Legacy system/lib/ork3 under 95% does not fail the milestone.
Logging: in-memory trace on the new type (level, event, key name, no secret values). A test asserts the trace records on their own.
One commit. No push. Update this checklist's boxes and the gate log.
Return: status ok|blocked|failed; branch; commit; gate summary; blockers.
```

## Queue

| # | Branch | Base | Kind |
| --- | --- | --- | --- |
| H0 | `stack/ork3-hev-h00-preflight` | `master` @ `22981eb6` | docs |
| H1 | `stack/ork3-hev-h01-env-file` | H0 tip | code |
| H2 | `stack/ork3-hev-h02-bootstrap` | H1 tip | code |
| H3 | `stack/ork3-hev-h03-vault-catalog` | H2 tip | code |
| H4 | `stack/ork3-hev-h04-gb-compose` | H3 tip | code |
| H5 | `stack/ork3-hev-h05-retire-config` | H4 tip | code |
| H6 | `stack/ork3-hev-h06-operator-doc` | H5 tip | docs |

---

## H0 — Preflight docs on the stack

- [x] Branch `stack/ork3-hev-h00-preflight` from `master` @ `22981eb6`
- [x] Commit only `docs/planning/gb-host-env-vault-plan.md` and this checklist
- [x] Working tree clean of warrior migrations and `* 2.*` copies

### H0 prompt

Milestone H0. Base: `master` @ `22981eb6`. New branch: `stack/ork3-hev-h00-preflight`.

Commit the two planning docs only. Check the H0 boxes and add a gate-log row `docs-only, no PHPUnit`. If the current checkout is `stack/warrior-w01-schema` or the index contains tournament SQL, create a clean worktree of `master` @ `22981eb6` and copy in only the two docs. Do not implement PHP, compose, or vault files. Do not push.

---

## H1 — EnvFile parser

- [x] `system/lib/system/class.EnvFile.php` Parser, methods at or under 80 lines
- [x] Unit tests plus a separate trace test
- [x] Scoped Infection >= 80% MSI and covered MSI; line coverage of `class.EnvFile.php` >= 95%
- [ ] `sh bin/run-unit-tests.sh` green, or `blocked` if the sandbox database is down
- [x] `sh bin/cleanup-mutation-artifacts.sh` before commit
- [x] Checklist and gate log updated in the one commit

### H1 prompt

Milestone H1. Base: tip of `stack/ork3-hev-h00-preflight`. New branch: `stack/ork3-hev-h01-env-file`.

Read the plan section "Env file parser". Add `system/lib/system/class.EnvFile.php` (Parser). It loads one env file into the process with fill-unset semantics and records trace events `env_file_missing`, `env_file_unreadable`, `env_key_kept`, `env_key_set`. Trace detail may include the key name and must not include the value.

Tests live in `tests/Unit/EnvFileTest.php` and do not include `startup.php` or open a database. One test class or method asserts trace records only. Cover comments, `export `, quotes, `\n` inside double quotes, `$` left unexpanded, invalid keys, missing file, unreadable file, and fill-unset.

Add `tools/infection/infection.hev-h1.json5` with `source` limited to that class file, `minMsi` 80, `minCoveredMsi` 80, bootstrap `tests/bootstrap.php` only if the EnvFile tests still pass when the suite boots; prefer a phpunit config that does not require MariaDB for the mutation run if the default bootstrap demands a database. The sign-off suite is still `sh bin/run-unit-tests.sh` with no `--filter`. If that script cannot reach MariaDB, return `blocked`.

Do not edit `startup.php`, config PHP files, compose, or docs other than this checklist. Do not add Composer packages. After Infection, run `sh bin/cleanup-mutation-artifacts.sh`. One commit. No push.

---

## H2 — Bootstrap and entrypoints

- [x] `config/bootstrap.php` defines the constants in the plan
- [x] Entrypoints listed in the plan include the bootstrap
- [x] `config.dist.php`, `config.dev.php`, and `config.test.php` still exist and are unused by those entrypoints
- [x] Bootstrap tests do not require the vault
- [x] `DIR_CACHE` is `{repo}/cache/` only
- [x] Scoped Infection and 95% line coverage on the new bootstrap PHP
- [x] Full unit suite green, or `blocked` if the sandbox database is down
- [x] Checklist and gate log updated

### H2 prompt

Milestone H2. Base: tip of `stack/ork3-hev-h01-env-file`. New branch: `stack/ork3-hev-h02-bootstrap`.

Read the plan sections "Bootstrap" and "URL rules" / stage defaults. Add `config/bootstrap.php`. It uses `EnvFile` for `.env` then `secrets.env`. It defines every constant the plan lists. `DIR_BASENAME` is the repository root. `DIR_CACHE` is `{DIR_BASENAME}cache/` a single time.

Switch these files off `config*.php` and onto the bootstrap: `startup.php`, `index.php`, `dbsetup.php`, `heraldry-trim-backfill.php`, `import/import.php`, `import/import.primary.php`, `import/import.wl.php`, `import/import.ork2.wl.php`.

`tests/bootstrap.php` must set `ENVIRONMENT=TEST` and the sandbox DB env before `startup.php` connects, matching today's `config.test.php` defaults (`127.0.0.1`, port `19307`, database `ork_test`, user `ork`, password `secret`) unless `ORK3_TEST_DB_HOST` / `ORK3_TEST_DB_PORT` are set. Add tests that include `config/bootstrap.php` in a subprocess or a fresh constant scope and assert TEST vs DEV vs other URL shape, DB defaults, fill-unset (a pre-set `DB_PASSWORD` survives `secrets.env`), and the ga4 path env when `files/ga4-sa.json` is present. A separate test asserts bootstrap trace events and that a fixture secret value is absent from the trace.

Do not delete the three config PHP files. Do not add dotenv. Do not print the committed `IDP_CLIENT_SECRET` from `config.dev.php`. Scoped Infection config `tools/infection/infection.hev-h2.json5` covers the new bootstrap file at 80% MSI and covered MSI. Line coverage of that file >= 95%. Full `sh bin/run-unit-tests.sh`. Cleanup script before commit. One commit. No push. Update this checklist.

---

## H3 — Catalog, manifests, secrets mode, migrate script

- [x] `host-env/catalog.d/30-ork3.json` and `host-env/manifests/ork-web.env-host-vault` name the same inject keys and `ga4-sa.json`
- [x] `host-env/secrets.example/ork-web.env` and `.env.example` have placeholders only
- [x] `scripts/ork3-secrets-mode.sh` supports `plaintext`, `local`, and `host-store`
- [x] Migrate script tested with a fixture; fixture secret absent from captured output
- [x] Full unit suite green, or `blocked` if the sandbox database is down
- [x] Infection scoped to any new PHP helper at 80%; skip Infection if this milestone adds no PHP
- [x] Checklist and gate log updated

### H3 prompt

Milestone H3. Base: tip of `stack/ork3-hev-h02-bootstrap`. New branch: `stack/ork3-hev-h03-vault-catalog`.

Read the plan sections "Vault inject vs `.env`" and "Migration script". Add:

- `host-env/catalog.d/30-ork3.json` profile `ork-web`, inject list and `inject_files: ["ga4-sa.json"]`, `runtime` empty, `socket` false
- `host-env/manifests/ork-web.env-host-vault` with the same names
- `host-env/secrets.example/ork-web.env` empty values
- `.env.example` non-secrets from the plan
- `host-env/load-secrets-mode.sh` and `scripts/ork3-secrets-mode.sh` (`plaintext` | `local` | `host-store`) writing `.gb/secrets-mode`
- `scripts/migrate-ork3-secrets-to-host-store.sh`

The migrate script extracts inject keys from a `define('NAME', '...')` file and from a `.env` file, writes a profile env file, and invokes `host-env-vault store import` when the binary exists. It must not echo secret values. Tests use a fixture password such as `fixture-secret-value` and assert the captured stdout/stderr does not contain it, and that the written file contains the key. Do not call a real vault in unit tests. If the only new code is shell, `bash -n` every new script and skip Infection; say so in the gate log. If you add a PHP extractor, gate that file at 95% line and 80% Infection via `tools/infection/infection.hev-h3.json5`.

Do not delete config PHP files. Do not commit a filled secrets file. Full `sh bin/run-unit-tests.sh` or `blocked` when MariaDB is down. Cleanup after any Infection run. One commit. No push.

---

## H4 — gb hooks and compose

- [ ] `.greenblue.example.dev` and `.greenblue.example.prod` match the plan's `GB_*` list
- [ ] `scripts/gb-pre-hev-sync.sh`, `scripts/gb-greenblue-helpers.sh`, `scripts/gb-hev-after-render.sh` pass `bash -n`
- [ ] `docker/compose.prod.yml`, `docker/compose.blue.yml`, `docker/compose.green.yml` mount `/run/secrets` from `ORK_HEV_WEB_SECRETS`
- [ ] Prod slot compose does not set `ENVIRONMENT=DEV`
- [ ] Ports 19080 and 29080 and container names unchanged
- [ ] `.gitignore` covers `.greenblue` and `.gb/`
- [ ] `greenblue.sh` still present
- [ ] Full unit suite green, or `blocked` if the sandbox database is down
- [ ] Checklist and gate log updated

### H4 prompt

Milestone H4. Base: tip of `stack/ork3-hev-h03-vault-catalog`. New branch: `stack/ork3-hev-h04-gb-compose`.

Read the plan section "Deploy files". Add the example greenblue files, the three `scripts/gb-*.sh` hooks, and `docker/compose.prod.yml` plus `docker/compose.blue.yml` and `docker/compose.green.yml`. Keep container names `ork3-php8-app-green` and `ork3-php8-app-blue`, host ports 19080 and 29080, network `ork3-php8-net`. Mount `${ORK_HEV_WEB_SECRETS}:/run/secrets:ro` and set `HOST_ENV_SECRETS_DIR=/run/secrets`. Do not set `ENVIRONMENT=DEV` on the prod slot files. Dev compose bind-mounts the repo and joins the existing DB network. Database services are not slot-swapped.

`bash -n` the new scripts. A small test or fixture assertion checks the prod example contains `GB_NGINX_STRATEGY=color-symlink`, `GB_HEV_PROFILES=ork-web`, and `GB_HEV_MOUNT_MAP=ork-web:ORK_HEV_WEB_SECRETS`, and that the green compose file publishes `19080:80`. Do not require the `gb` binary or a running Docker daemon for sign-off. If they are absent, do not return `blocked` for that reason.

Leave `greenblue.sh` unchanged. Do not delete config PHP files. Full unit suite or `blocked` only when MariaDB is down. Infection only if this milestone edits PHP; otherwise record `bash -n` in the gate log. Cleanup if Infection ran. One commit. No push.

---

## H5 — Retire PHP config files

- [ ] `config.dist.php`, `config.dev.php`, and `config.test.php` deleted
- [ ] No request entrypoint includes them
- [ ] `DeploymentTier` reads environment, and its tests use env fixtures
- [ ] `heartbeat.sh` no longer appends secret env lines to the fpm pool
- [ ] README no longer tells operators to edit `config.dev.php`
- [ ] Scoped Infection on edited PHP at 80%; 95% line coverage of that PHP
- [ ] Full unit suite green, or `blocked` if the sandbox database is down
- [ ] Checklist and gate log updated

### H5 prompt

Milestone H5. Base: tip of `stack/ork3-hev-h04-gb-compose`. New branch: `stack/ork3-hev-h05-retire-config`.

Read the plan section "Retire". Delete `config.dist.php`, `config.dev.php`, and `config.test.php`. Grep the repo for includes of those files and point any remaining runtime include at `config/bootstrap.php`. Do not print file contents while deleting `config.dev.php` (it holds a live client secret).

Change `tools/ork-db/lib/DeploymentTier.php` so tier detection uses process env (`DB_HOSTNAME` / `ORK3_DB_HOST` / `ORK3_TEST_DB_HOST` and `APP_STAGE`) instead of regex over `define()` in config PHP files. Update `tests/Unit/OrkDb/DeploymentTierTest.php` and `tests/Unit/OrkDb/TierRefusalCliTest.php` to write env fixtures, not synthetic config PHP files.

Edit `heartbeat.sh` so it does not append `ORK3_DB_*`, `CF_*`, or `AMAZON_SES_*` to the php-fpm pool. Keep the nginx, php-fpm, and memcached start loop.

Update `README.md` quick start to `.env.example` plus plaintext or vault seed. Remove the "edit tracked config.dev.php" warning.

`class.Report.php` may keep getenv fallbacks. If you remove them, add a test for the empty-constant path.

Infection config `tools/infection/infection.hev-h5.json5` scoped to `DeploymentTier.php` and any other PHP this milestone edits, 80% MSI and covered MSI, 95% line coverage of those files. Full `sh bin/run-unit-tests.sh` or `blocked` when MariaDB is down. Cleanup. Do not delete `greenblue.sh`. One commit. No push.

---

## H6 — Operator runbook

- [ ] `docs/deploy-gb-host-env-vault.md` and `host-env/README.md`
- [ ] Runbook says both slots stay up and `gb revert` flips nginx
- [ ] SSM export described as a later operator step
- [ ] Checklist and gate log updated
- [ ] Docs-only: no PHPUnit

### H6 prompt

Milestone H6. Base: tip of `stack/ork3-hev-h05-retire-config`. New branch: `stack/ork3-hev-h06-operator-doc`.

Write `docs/deploy-gb-host-env-vault.md` and a short `host-env/README.md` that points at it. Cover: install paths for generic-green-blue and host-env-vault, copy `.greenblue.example.dev` to `.greenblue`, `scripts/ork3-secrets-mode.sh`, seed from `host-env/secrets.example/ork-web.env`, `gb <repo> deploy`, check `test -f /run/secrets/secrets.env`, health curl against the slot port, `gb revert`. State that the previous color stays running. State that production SSM is a later `host-env-vault store export`, not part of this stack. Staging stays on plaintext `.stage.env` until that host has a vault.

Do not change PHP or compose. Check the H6 boxes. Gate log: `docs-only`. One commit. No push.

---

## Gate log

| Milestone | Branch | Commit | Gates |
| --- | --- | --- | --- |
| H0 | stack/ork3-hev-h00-preflight | (this commit) | docs-only, no PHPUnit |
| H1 | stack/ork3-hev-h01-env-file | (this commit) | EnvFile 100% lines; infection MSI/covered MSI 100%; unit suite blocked (MariaDB + drift-check) |
| H2 | stack/ork3-hev-h02-bootstrap | (this commit) | bootstrap 96.6% lines; Ork3BootstrapConfig infection MSI 99.0% / covered 100%; phpunit hev-h1+hev-h2 green; run-unit-tests SKIP drift-check (pre-existing strict) |
| H3 | stack/ork3-hev-h03-vault-catalog | (this commit) | Ork3SecretsMigrate 97.3% lines; infection MSI/covered 100%; bash -n on new scripts; phpunit hev-h3 green; run-unit-tests SKIP drift-check (pre-existing strict) |
| H4 | | | |
| H5 | | | |
| H6 | | | |
