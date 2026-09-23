# Version 2.1 — Requirements

**Status:** Implemented (see [README.md](./README.md) as-built)  
**Parent:** [README.md](./README.md)

---

## R1 — Default containerized capture / validate

1. The **default** execution path for `bin/fuzzy-validator` (and therefore `tools/fuzzy-validator/bin/fuzzy-validator`, `npm run fuzz:*`) runs capture and gate work inside a **fuzzy-validator runner** container.
2. Operators must **not** need a different command vocabulary. Existing workflows (`record`, `validate`, `refuzz`, `setpoint`, `overlay`) keep the same subcommands and flags.
3. Results written to the tool root (`baselines/`, `manifests/`, `reports/`, `overlays/`, `calibrations/`, `setpoints/`) must appear on the host filesystem at the same paths as today.
4. After implementation, **gold-master setpoints** used for sign-off should be produced (or re-produced) on the runner so cross-host validate does not reintroduce host Chromium drift.

## R2 — Minimum Ubuntu 26 + stabilized Chrome

1. Base image: **Ubuntu 26.04** (`ubuntu:26.04`, codename **resolute**). Pin to a dated digest or `resolute-YYYYMMDD` tag in the Dockerfile; do not float on `ubuntu:latest` in CI/docs examples without noting it aliases LTS.
2. Image contents stay **minimal** for fuzzy-validator work:
   - OS packages required by Playwright Chromium (install via Playwright’s documented dependency path)
   - Python 3.x sufficient for `tools/fuzzy-validator/python`
   - Node.js sufficient to run root `npx playwright test --project=fuzzy-capture` against the mounted repo
   - PHP CLI only as needed for in-container `bin/ork-db` (see R4)
   - Docker CLI client only as needed for `ork-db`’s `docker compose restart` / `docker exec` (see design)
3. **Chrome / Chromium** must be **pinned** for reproducibility:
   - Prefer Playwright-managed Chromium matching the repo’s locked `@playwright/test` / `playwright` version (today: lockfile resolves **1.61.1**)
   - Do **not** rely on distro `chromium` packages or the host’s Google Chrome
   - Record the installed browser version in runner image metadata or a small `tools/fuzzy-validator/docker/BROWSER_PIN.txt` (or equivalent) for operators
4. Capture continues to use the existing **fuzzy-capture** Playwright project (fixed 1280×720 viewport, stabilize helpers).

## R3 — Volume-shared configuration and I/O

1. The runner must bind-mount (at minimum) everything required to share config and artifacts with the host:
   - Repo root (so `playwright.config.ts`, `package.json` / `node_modules` or in-image install strategy, `bin/`, `tools/fuzzy-validator/**`, `.ork3-db.local`)
   - Especially tool-root trees: `manifests/`, `baselines/`, `reports/`, `overlays/`, `setpoints/`, `calibrations/`, `setpoint.json`
2. Auth and base-URL env vars (`ORK3_E2E_*`, optional `--base-url`) must pass through from host → container.
3. No requirement to copy baselines into the image; the image is a **runtime**, not a setpoint store.

## R4 — Reach the existing ORK3 app / dual-profile stack

1. Capture must hit the **same** local ORK3 app operators use today (compose service `ork3app` / container `ork3-php8-app`, published host port **19080**).
2. Dual-profile behavior must remain intact:
   - `bin/ork-db use dev|prod` before each profile pass (writes `.ork3-db.local`, restarts `ork3app`)
   - Optional `--ensure-sandbox` → `bin/ork-db deploy-sandbox`
   - Mirror freshness checks continue to read host-mounted `tools/ork-db/extracted/manifest.json`
3. Do **not** invent a second app container for fuzzy-validator. Do **not** break the assumption that php8 app + `ork3db` / `ork3testdb` remain the system under test.
4. Default in-container base URL must resolve the app on the **Docker network** (not `localhost:19080` inside the runner, which would refer to the runner itself). Design specifies the exact hostname.

## R5 — Lifecycle: warm session, no reboot persistence

1. If the runner container is missing or stopped, the CLI **auto-starts** it before running the command.
2. After a successful or failed fuzzy-validator command, the runner **stays up** (no automatic `compose down` / `stop`).
3. Compose/`docker run` configuration must set **`restart: "no"`** (or omit any restart policy that survives reboot). Intent: session convenience only; host reboot → runner stays down until next CLI ensure.
4. Document how to stop/rebuild (`docker compose … stop`, image rebuild after pin bumps).

## R6 — Escape hatch (justified)

1. Provide **`FUZZY_VALIDATOR_NATIVE=1`** and/or **`--host`** to run the current bare-metal Python/Playwright path.
2. Escape hatch is for tool debugging, unit-test-adjacent experiments, or environments without Docker — **not** the documented sign-off path.
3. When native mode is active, print a one-line stderr notice that results may differ from the containerized default.

## R7 — Documentation

1. Plan and (later) as-built notes live under `docs/megiddo/fuzzy-validator/version-2.1/`.
2. Brief cross-links from `docs/megiddo/fuzzy-validator/README.md` and `tools/fuzzy-validator/README.md`.
3. Operator docs must state: **sign-off validates and setpoint captures use the runner**; macOS native capture is non-canonical.

---

## Non-goals

| Non-goal | Why |
|----------|-----|
| Merging runner into `docker-compose.php8.yml` as a required core service | Keeps app stack independent; runner is optional until first fuzzy command |
| Surviving host reboot via `restart: always` / `unless-stopped` | Explicit product requirement against reboot persistence |
| Pixel-perfect match across hosts *without* the runner | Out of scope; runner *is* the mitigation |
| Shipping a full desktop GUI Chrome | Headless Playwright Chromium only |
| Containerizing general `tests/e2e` Playwright suite | Separate concern; fuzzy-capture project only |
| Changing overlay / gate / report schemas | v2 contracts stay |
| Auto-promoting setpoints after switching to the runner | Maintainer explicitly re-captures / publishes once |

---

## Acceptance criteria (product)

1. On macOS and Linux hosts with the php8 stack up, `bin/fuzzy-validator validate --page <known> --profile test` (default path) produces the same pass/fail for visual/DOM/assets as a second host using the same repo state and DBs, within existing thresholds — without requiring matching host Chrome versions.
2. CLI UX unchanged aside from optional first-run image pull/build time and the documented escape hatch.
3. Stopping the host and rebooting does **not** auto-restart the runner; the next CLI invocation starts it again.
4. Dual-profile `validate` still switches DB via `ork-db` and captures against the restarted app.
