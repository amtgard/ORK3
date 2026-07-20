# Version 2.1 — Milestones

**Status:** Plan (checklist for implementer)  
**Parent:** [README.md](./README.md)  
**Design:** [02-design.md](./02-design.md)

Executable checklist. Do not mark complete in this plan commit — implementation is a follow-on branch.

---

## Milestone map

| ID | Title | Outcome |
|----|-------|---------|
| **FV21-0** | Spike: network + base URL | Proof that Playwright in a throwaway Ubuntu 26.04 container can load `http://ork3-php8-app/orkui/` on the external php8 network |
| **FV21-1** | Dockerfile + compose fragment | Pinned image build; `restart: "no"`; mounts; sock; documented network name/override |
| **FV21-2** | CLI wrapper default path | `bin/fuzzy-validator` ensures runner + `docker exec`; `--host` / `FUZZY_VALIDATOR_NATIVE=1` escape |
| **FV21-3** | Dual-profile / ork-db in runner | `validate --profiles test,mirror` switches DB and captures both from inside the runner |
| **FV21-4** | Docs + operator migration | README / USER-GUIDE / version-2.1 as-built notes; setpoint re-record guidance |
| **FV21-5** | Parity proof | Cross-host (or host vs runner) evidence; optional CI image alignment |

---

## FV21-0 — Spike: network + base URL

**Goal:** De-risk DNS and app reachability before investing in the full image.

### Tasks

- [ ] Start php8 stack; note network name via `docker inspect ork3-php8-app`
- [ ] Run a minimal `ubuntu:26.04` (or `resolute-*`) container attached to that network
- [ ] `curl -sI http://ork3-php8-app/orkui/` succeeds from inside
- [ ] Confirm `http://127.0.0.1:19080` fails or is wrong from inside (documents why default URL must change)
- [ ] Optional: one-shot Playwright install + navigate to a static path

### Acceptance

- [ ] Written note in PR / milestone comment: exact network name pattern + chosen default base URL

---

## FV21-1 — Dockerfile + compose fragment

**Goal:** Reproducible runner image and compose file under `tools/fuzzy-validator/`.

### Tasks

- [ ] Add `tools/fuzzy-validator/docker/Dockerfile` from `ubuntu:26.04` (pin dated tag or digest)
- [ ] Install minimal packages: Playwright OS deps, Node (pinned), Python 3 + pip deps, PHP CLI, Docker CLI
- [ ] Pin Playwright/Chromium to lockfile version; write `BROWSER_PIN.txt` (or equivalent) at build
- [ ] Add `tools/fuzzy-validator/docker-compose.runner.yml` with `restart: "no"`, repo mount, docker.sock, external network, idle command
- [ ] Document `FUZZY_VALIDATOR_DOCKER_NETWORK` (or auto-detect) for non-default project names
- [ ] Decide `node_modules` strategy (named volume vs `npm ci` in image vs mount) and document it in the Dockerfile comment / 02-design update

### Acceptance

- [ ] `docker compose -f tools/fuzzy-validator/docker-compose.runner.yml build` succeeds on amd64 and arm64 (or documented limitation)
- [ ] `restart` policy is `"no"` (inspect confirms)
- [ ] Container can `docker compose … restart ork3app` via mounted sock (smoke)

---

## FV21-2 — CLI wrapper default path

**Goal:** Transparent default containerization; native escape hatch.

### Tasks

- [ ] Split or alias today’s dispatcher to a **native** entry (e.g. `fuzzy-validator-native`) that always runs host/container-local Python without ensure logic
- [ ] Update `tools/fuzzy-validator/bin/fuzzy-validator` to:
  - [ ] Honor `FUZZY_VALIDATOR_NATIVE=1` and `--host`
  - [ ] Otherwise `ensure_runner_running` + `docker exec` with env pass-through
  - [ ] Default in-container `ORK3_E2E_BASE_URL` when unset
- [ ] Keep `bin/fuzzy-validator` at repo root as a pass-through (no behavior fork)
- [ ] Print brief stderr when starting the runner the first time in a session; print native-mode warning when applicable
- [ ] Leave container running after commands (no stop/down)

### Acceptance

- [ ] `bin/fuzzy-validator validate --help` works via default path
- [ ] With runner stopped, first validate auto-starts it; second validate does not rebuild/restart unnecessarily
- [ ] `FUZZY_VALIDATOR_NATIVE=1 bin/fuzzy-validator …` skips docker ensure
- [ ] Host reboot simulation: after `docker stop` of runner (stand-in for reboot dormancy), next CLI starts it again; compose does not use `unless-stopped`/`always`

---

## FV21-3 — Dual-profile / ork-db in runner

**Goal:** Do not break test + mirror assumptions.

### Tasks

- [ ] From default path, run `validate --profile test --page <stable>` with capture succeeding
- [ ] Run `validate --profiles test,mirror --page <stable>` and confirm `.ork3-db.local` flips and app restart occurs via in-container ork-db
- [ ] Confirm `--ensure-sandbox` still works (or document host-only limitation if sock permissions block — prefer fix)
- [ ] Mirror freshness flag still reads mounted `tools/ork-db/extracted/manifest.json`
- [ ] Auth env pass-through verified for both profiles

### Acceptance

- [ ] Dual-profile gate exit codes match expectations on a known-good SHA with restored setpoint **after** runner-based baselines exist (may require FV21-4 re-record first)
- [ ] No change required to `docker-compose.php8.yml` for happy path

---

## FV21-4 — Docs + operator migration

**Goal:** Operators know the default is containerized and how to migrate setpoints.

### Tasks

- [ ] Update `tools/fuzzy-validator/README.md` ORK3 quick start (runner default; native optional)
- [ ] Update `docs/megiddo/fuzzy-validator/USER-GUIDE.md` / prerequisites as needed
- [ ] Mark `version-2.1/` status Implemented (or Partially) with as-built deltas vs this plan
- [ ] Document one-time **re-record / setpoint publish** from the runner for gold master
- [ ] Document stop/rebuild commands and network override
- [ ] Note Google Drive / cloud-sync mount caveats if still relevant

### Acceptance

- [ ] New contributor can follow README-only steps without installing host Playwright for fuzzy-validator default path (php8 stack + Docker still required)

---

## FV21-5 — Parity proof

**Goal:** Evidence that the problem statement is addressed.

### Tasks

- [ ] Capture a small page set on runner; validate on a second OS host using the same runner image/tag (or same machine native-vs-runner contrast for visual layer)
- [ ] Store a short evidence note under `tools/fuzzy-validator/evidence/` or link a report path (optional committed HTML not required)
- [ ] Optional: CI job builds runner image and runs a smoke validate

### Acceptance

- [ ] Written conclusion: cross-host visual disagreement from host Chrome is eliminated for the default path (residual fuzz only from allowed page volatility / data)

---

## Suggested implementation order

```text
FV21-0  →  FV21-1  →  FV21-2  →  FV21-3  →  FV21-4  →  FV21-5
              │
              └─ re-record setpoint once dual-profile works (before declaring visual parity)
```

## Out of scope for these milestones

- Changing v2 overlay schemas or skills beyond noting “run via containerized CLI”
- `restart: always` or systemd user units
- Merging runner into the php8 compose file as a hard dependency at `docker compose up`
