# Version 2.1 — Milestones

**Status:** Implemented  
**Parent:** [README.md](./README.md)  
**Design:** [02-design.md](./02-design.md)

---

## Milestone map

| ID | Title | Outcome |
|----|-------|---------|
| **FV21-0** | Spike: network + base URL | ✅ `tools/fuzzy-validator/evidence/fv21-0-network-spike.md` |
| **FV21-1** | Dockerfile + compose fragment | ✅ `docker/` + `docker-compose.runner.yml` |
| **FV21-2** | CLI wrapper default path | ✅ `bin/fuzzy-validator` ensure + exec; `--host` / `NATIVE` |
| **FV21-3** | Dual-profile / ork-db in runner | ✅ socat DB forwards + `ork-db use` via sock |
| **FV21-4** | Docs + operator migration | ✅ README / USER-GUIDE / version-2.1 status |
| **FV21-5** | Parity proof | ✅ `evidence/fv21-5-runner-parity.md` |

---

## FV21-0 — Spike: network + base URL

- [x] Start php8 stack; note network name via `docker inspect ork3-php8-app`
- [x] Run minimal `ubuntu:26.04` attached to that network
- [x] `curl -sI http://ork3-php8-app/orkui/` succeeds
- [x] Confirm `http://127.0.0.1:19080` fails inside
- [x] Written note: `tools/fuzzy-validator/evidence/fv21-0-network-spike.md`

---

## FV21-1 — Dockerfile + compose fragment

- [x] `tools/fuzzy-validator/docker/Dockerfile` from `ubuntu:resolute-20260707`
- [x] Playwright OS deps, Node 22, Python venv, PHP CLI, Docker CLI, socat
- [x] Playwright/Chromium pinned; `BROWSER_PIN.txt`
- [x] `docker-compose.runner.yml` with `restart: "no"`, repo mount, sock, external network
- [x] `FUZZY_VALIDATOR_DOCKER_NETWORK` + auto-detect
- [x] `node_modules` via named volume `ork3-fv-node-modules`

---

## FV21-2 — CLI wrapper default path

- [x] `fuzzy-validator-native` always runs local Python
- [x] Default wrapper: ensure + `docker exec`; `--host` / `FUZZY_VALIDATOR_NATIVE=1`
- [x] Default in-container `ORK3_E2E_BASE_URL=http://ork3-php8-app/orkui/`
- [x] Leave container running; `restart: "no"`

---

## FV21-3 — Dual-profile / ork-db in runner

- [x] `ork-db use dev|prod` from runner (socat + docker.sock)
- [x] Capture via default path against php8 app
- [x] Dual-profile dry-run lists both profiles
- [x] No change to `docker-compose.php8.yml`

**Note:** Visual gate against pre-2.1 macOS baselines fails until setpoint re-record (see FV21-4).

---

## FV21-4 — Docs + operator migration

- [x] tools README quick start (runner default)
- [x] USER-GUIDE prerequisites
- [x] version-2.1 status Implemented + as-built deltas
- [x] Setpoint re-record guidance
- [x] Stop/rebuild / network override / Google Drive caveat

---

## FV21-5 — Parity proof

- [x] Evidence: runner capture works; host Chromium baselines diverge visually (problem statement confirmed)
- [x] Written conclusion in `tools/fuzzy-validator/evidence/fv21-5-runner-parity.md`
