# ORK3 gb + host-env-vault

Design for the stacked milestones in [gb-host-env-vault-checklist.md](gb-host-env-vault-checklist.md).

Stack base: `master` at `c552e972` (`origin/master` as of 2026-11-17). Rebases onto current `master`; there is no `main` branch. Do not stack on `stack/warrior-w01-schema`.

Reference apps (read, do not copy secrets):

- Denarius deploy runbook: `ORK4/amtgard-denarius/docs/deploy-gb-host-env-vault.md`
- IDP env load order: `ORK4/amtgard-idp/config/load-env.php`
- Toolkit color-symlink example: `ORK4/generic-green-blue-deployment/examples/.greenblue.prod.nginx-color-symlink.example`
- Vault skill: `ORK4/host-env-vault/.cursor/skills/host-env-vault/SKILL.md`

## What is wrong today

`startup.php` and `index.php` choose `config.test.php`, `config.dev.php`, or gitignored `config.php` from `ENVIRONMENT`. Prod compose files `docker-compose.php8-app.green` (host port 19080) and `docker-compose.php8-app.blue` (host port 29080) set `ENVIRONMENT=DEV` and `env_file: .dev.env`, so a live slot can load the committed dev config. `greenblue.sh` flips `/etc/nginx/sites-enabled/default` and then stops the old container and prunes. `heartbeat.sh` copies a few env vars into the php-fpm pool because `service` scrubs the container environment. `config.dev.php` contains a real `IDP_CLIENT_SECRET`. Never copy that value into git, logs, or `.env.example`. Rotate it after the file is deleted.

## Locked decisions

- One vault profile, `ork-web`. `runtime` empty, `socket` false. No worker or connect profile.
- Keep PHP `define()` constants. Call sites stay. The three config files and the `ENVIRONMENT` file switch go away.
- PHP reads `/run/secrets/secrets.env` itself. Do not add new `heartbeat.sh` pool lines for secrets.
- No `vlucas/phpdotenv`. `startup.php` runs before Composer autoload. `EnvFile` is a Parser in `system/lib/system/class.EnvFile.php`.
- Laptop posture is encrypted host-store, then `sync` / `render`. SSM `store export` is documented later, not implemented here. `plaintext` mode is the staging escape hatch.
- `gb` keeps both slots so `gb revert` works. Container names and ports stay `ork3-php8-app-green:19080` and `ork3-php8-app-blue:29080`. Database containers stay on `ork3-php8-net` and are not slot-swapped.
- `greenblue.sh` stays until an operator has run `gb deploy` on the host. Do not delete it in this stack.
- ORK3 is not a Slim app. Do not add `container.php`, a SQLite log platform, or a thick health test. `class.Log` needs a database and its `Write()` returns immediately. Bootstrap traces are an in-memory list on the new types: level, event name, key name. Never a secret value.

## Env file parser (`EnvFile`)

- Skip blank lines and `#` comments.
- Allow an optional `export ` prefix.
- `KEY=VALUE` with key `[A-Za-z_][A-Za-z0-9_]*`.
- Strip one matching pair of single or double quotes. In double quotes, honor `\\`, `\"`, and `\n` only. Do not expand `$`.
- Missing or unreadable file: no exception. Trace `env_file_missing` or `env_file_unreadable`.
- Fill-unset: if the key is already in the environment, keep it and trace `env_key_kept`. Otherwise `putenv`, `$_ENV`, and `$_SERVER`, and trace `env_key_set`.
- A separate test asserts those trace records. Behavior tests do not replace that test.

## Bootstrap

`config/bootstrap.php` is the only config entry. Order:

1. Load the repo `.env` when the file exists. A missing file is success when the process environment is already set (PHPUnit).
2. Secrets directory: `HOST_ENV_SECRETS_DIR`, otherwise `/run/secrets` when `secrets.env` is readable, otherwise skip.
3. Load `secrets.env` with fill-unset so a dev `.env` key wins over a stale bundle. Production `.env` must not repeat vault keys.
4. Define constants, then `require` mail, Yapo, and Log the way the old config files did.
5. When an inject file `files/ga4-sa.json` exists and `GA4_SA_KEY_PATH` is unset, set that env var to the file path.

`DIR_BASENAME` is the repository root (the directory that contains `config/bootstrap.php`’s parent), not `config/`. `DIR_CACHE` is `{DIR_BASENAME}cache/` once. The second assignment in `config.dist.php` that points `DIR_CACHE` at the repo root is not preserved.

URL rules, matching today:

- `ENVIRONMENT=TEST`: plain `http://{HTTP_HOST}/...`, empty `ORK_DIST_NAME`, same path suffixes as `config.test.php`.
- `ENVIRONMENT=DEV`: scheme from `X-Forwarded-Proto` when it is `https`, otherwise `http`. Empty `ORK_DIST_NAME`.
- Otherwise: `http://` `HTTP_*` and `https://` `HTTPS_*` with `ORK_DIST_NAME` default `ork`, matching `config.dist.php` path prefixes.

Stage defaults when the env var is unset:

| Concern | TEST | DEV | otherwise |
| --- | --- | --- | --- |
| DB host | `ORK3_TEST_DB_HOST` or `127.0.0.1` | `ORK3_DB_HOST` or `ork3-php8-db` | `DB_HOSTNAME` or `mysql.amtgard.com` |
| DB port | `ORK3_TEST_DB_PORT` or `19307` | `DB_PORT` or `3306` | `3306` |
| DB name | `ork_test` | `ORK3_DB_DATABASE` or `ork` | `DB_DATABASE` required from env in real prod; placeholder `DB_DATABASE` only when unset so define still happens |
| DB user / password | `ork` / `secret` | `ORK3_DB_USER` or `ork` / `ORK3_DB_PASSWORD` or `secret` | `DB_USERNAME` / `DB_PASSWORD` from env, empty string if unset |
| Login timeout | 72 hours | 30 days | 30 days |
| Cache enabled | false | true | false |
| Cache host | `ork-dev` | `ork-dev` | `ork.amtgard.com` |
| IDP base and API | `http://localhost:37080` and `http://host.docker.internal:37080` | same | `https://idp.amtgard.com` for both |
| IDP client id | `ork3_dev` | `ork3_dev` | `IDP_CLIENT_ID` or empty |
| IDP client secret | `test-secret` | empty unless env or secrets file set it | empty unless secrets file set it |
| Setup admin email | `test@example.com` | `youremail@example.com` | `amtgardinc@gmail.com` |
| App stage | `DEV` | `DEV` | `APP_STAGE` or `PROD` |

Also define, from env when set and otherwise empty or the historical constant: `DB_DRIVER=mysql`, `DB_PREFIX=ork_`, `DO_SETUP=false`, `TRACE=false`, `DUMPTRACE=false`, `UI_LOCALITY=LOCAL`, `ORK3_SERVICE_URL=HTTP_SERVICE`, `UIR` only when `ENVIRONMENT=TEST`, `GOOGLE_MAPS_API_KEY`, `GOOGLE_MAPS_ACCESS_API_KEY`, `SENDGRID_API_KEY`, `BEHOLD_KEY`, `CF_API_TOKEN`, `CF_ZONE_ID`, `CARTO_API_KEY`, `AMAZON_SES_HOST`, `AMAZON_SES_USERNAME`, `AMAZON_SES_PASSWORD`, `AMAZON_SES_FROM_EMAIL`, `AMAZON_SES_PORT` (587 when unset), `ORK_CLIENT_NAME=AmtgardORK`, `IDP_API_KEY`, `CACHE_HOST`, `CUSTOM_CSS`, `CONFIG=true`, timezone `America/Chicago`. `display_errors` is `0` when `ENVIRONMENT=TEST`, otherwise `1`.

Entrypoints that must include the bootstrap instead of a `config*.php` file: `startup.php`, `index.php`, `dbsetup.php`, `heraldry-trim-backfill.php`, `import/import.php`, `import/import.primary.php`, `import/import.wl.php`, `import/import.ork2.wl.php`.

Leave `config.dist.php`, `config.dev.php`, and `config.test.php` on disk until the retire milestone. After the switch, nothing in the request path includes them.

## Vault inject vs `.env`

Inject names only (catalog and manifest must match exactly): `DB_PASSWORD`, `IDP_CLIENT_SECRET`, `IDP_API_KEY`, `SENDGRID_API_KEY`, `GOOGLE_MAPS_API_KEY`, `GOOGLE_MAPS_ACCESS_API_KEY`, `BEHOLD_KEY`, `CF_API_TOKEN`, `CARTO_API_KEY`, `AMAZON_SES_USERNAME`, `AMAZON_SES_PASSWORD`. Inject file: `ga4-sa.json`.

`.env.example` (non-secrets): `ENVIRONMENT`, `APP_STAGE`, `DB_HOSTNAME`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `ORK3_DB_HOST`, `ORK3_DB_DATABASE`, `ORK3_DB_USER`, `ORK3_TEST_DB_HOST`, `ORK3_TEST_DB_PORT`, `IDP_BASE_URL`, `IDP_API_URL`, `IDP_CLIENT_ID`, `CACHE_HOST`, `CACHE_ENABLED`, `CF_ZONE_ID`, `AMAZON_SES_HOST`, `AMAZON_SES_FROM_EMAIL`, `AMAZON_SES_PORT`, `ORK_DIST_NAME`, `SETUP_ADMIN_EMAIL`, `HOST_ENV_SECRETS_DIR`. Placeholder values only.

`host-env/secrets.example/ork-web.env` lists every inject key with an empty value. No real secrets.

## Deploy files

- `.greenblue.example.dev` and `.greenblue.example.prod`. Live `.greenblue` is gitignored.
- Prod: `GB_MODE=prod`, `GB_NGINX_STRATEGY=color-symlink`, `GB_NGINX_SITES_AVAILABLE_DIR=/etc/nginx/sites-available`, `GB_NGINX_SITES_ENABLED_DEFAULT=/etc/nginx/sites-enabled/default`, `GB_BLUE_PORT=29080`, `GB_GREEN_PORT=19080`, `GB_BLUE_CONTAINER=ork3-php8-app-blue`, `GB_GREEN_CONTAINER=ork3-php8-app-green`, `GB_HEV_PROFILES=ork-web`, `GB_HEV_MOUNT_MAP=ork-web:ORK_HEV_WEB_SECRETS`, `GB_DEV_STACKS=web`, `GB_DEPLOY_STACKS=web`, `GB_HEV_SYNC_ON_DEPLOY=1`, `GB_HEV_RENDER_ON_DEPLOY=1`. Dev sync source comes from the secrets-mode script (`local` or `host-store`).
- `scripts/gb-pre-hev-sync.sh` copies `host-env/catalog.d/30-ork3.json` into the vault `dev-shared/catalog.d/`.
- `scripts/gb-greenblue-helpers.sh` resolves `GB_HEV_VAULT_ROOT`, `GB_HEV_CONFIG`, `GB_HEV_REPO`.
- `scripts/gb-hev-after-render.sh` may append `HOST_ENV_VAULT_RUNTIME_DIR` to `.gb/runtime.env`.
- Compose: `docker/compose.prod.yml`, `docker/compose.blue.yml`, `docker/compose.green.yml`. Web service mounts `${ORK_HEV_WEB_SECRETS}:/run/secrets:ro` and sets `HOST_ENV_SECRETS_DIR=/run/secrets`. Prod slot files do not set `ENVIRONMENT=DEV`. Dev compose joins `ork3-php8-net` and bind-mounts the repo.
- `.gitignore` includes `.greenblue` and `.gb/`. It does not ignore `.env.example` or `host-env/secrets.example/`.

## Migration script

`scripts/migrate-ork3-secrets-to-host-store.sh` reads an on-server `config.php` and an optional `.dev.env`, writes a profile env file of inject keys only, and calls `host-env-vault store import`. Stdout and stderr must not contain secret values. If the `host-env-vault` binary is absent, the script exits non-zero with a path message and does not print extracted values. Unit tests use a fixture `config.php` with fake values and assert the output file’s keys and that the test log does not contain the fixture secret.

## Retire

- Delete `config.dist.php`, `config.dev.php`, `config.test.php`.
- `tools/ork-db/lib/DeploymentTier.php` stops regex-parsing `define('DB_HOSTNAME'` and `define('APP_STAGE'`. It reads the same environment the bootstrap uses. Update `tests/Unit/OrkDb/DeploymentTierTest.php` and `TierRefusalCliTest.php`.
- `heartbeat.sh` no longer writes `ORK3_DB_*`, `CF_*`, or `AMAZON_SES_*` into the fpm pool. It still starts nginx, php-fpm, and memcached.
- README quick start: copy `.env.example` to `.env`, use `plaintext` or a vault seed, then `gb`. Remove the instruction to edit tracked `config.dev.php`.
- Staging keeps `docker-compose.staging.yml` and `.stage.env` in plaintext mode. No third config file.
- `docs/deploy-gb-host-env-vault.md` covers laptop host-store, `gb <repo> deploy`, `test -f /run/secrets/secrets.env`, health curl on the slot port, and `gb revert`. State that the previous color stays up. SSM export is a later operator step, not a code change.

## Gates that apply to every code milestone

ORK3’s repo-wide Infection floor in `tools/infection/infection.json5` is 15%. That floor is not the bar for this stack. New and edited PHP in the milestone is gated at 95% line coverage and 80% MSI and covered MSI, scoped to those files. Legacy `system/lib/ork3` staying under 95% does not fail the milestone.

`sh bin/run-unit-tests.sh` is the full suite (no `--filter`). It runs `ork-db drift-check --strict` and needs the sandbox database. If MariaDB is down, the worker returns `blocked` and does not skip the suite.

`class.Report.php` getenv fallbacks for `CF_*` and `GA4_*` may stay. Removing them is in scope only for the retire milestone, and only with a test that still covers the empty-constant path.
