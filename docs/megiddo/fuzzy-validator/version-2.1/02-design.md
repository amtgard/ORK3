# Version 2.1 — Design

**Status:** Plan  
**Parent:** [README.md](./README.md)  
**Requirements:** [01-requirements.md](./01-requirements.md)

---

## 1. Current as-built (what we wrap)

| Piece | Today |
|-------|--------|
| Host entry | `bin/fuzzy-validator` → `tools/fuzzy-validator/bin/fuzzy-validator` |
| Dispatcher | Bash case → `python3 -m fuzzy_validator.cli <cmd>` |
| Capture | `npx playwright test --project=fuzzy-capture` (repo-root `playwright.config.ts`) |
| Base URL | `ORK3_E2E_BASE_URL` or default `http://127.0.0.1:19080/orkui/` |
| Profiles | `runtime.activate_profile` → `bin/ork-db use <dev\|prod>` (+ optional `deploy-sandbox`) |
| App stack | `docker-compose.php8.yml`: `ork3app` (:19080), `ork3db` (:19306), `ork3testdb` (:19307) on network `ork3-php8-net` |
| ork-db side effects | Writes `.ork3-db.local`; **`docker compose … restart ork3app`**; many data paths use **`docker exec`** into DB containers |

Cross-host pixel noise is already acknowledged in architecture / USER-GUIDE (“prefer Linux for sign-off”). 2.1 makes that Linux Chromium path the default CLI surface.

---

## 2. Architecture overview

```text
┌──────────────────────────── Host ─────────────────────────────┐
│  bin/fuzzy-validator  (thin ensure + docker exec | native)    │
│       │                                                       │
│       │  bind-mount repo                                      │
│       ▼                                                       │
│  ┌──────────── fuzzy-validator-runner (Ubuntu 26.04) ───────┐ │
│  │  Python CLI + Playwright fuzzy-capture                   │ │
│  │  pinned Chromium (Playwright)                            │ │
│  │  docker CLI → sock (ork-db use / deploy-sandbox)         │ │
│  └────────────┬───────────────────────────────┬─────────────┘ │
│               │ HTTP (compose DNS)            │ docker API    │
│               ▼                               ▼               │
│         ork3-php8-app ◄── restart ── ork-db                   │
│               │                                               │
│         ork3-php8-db / ork3-php8-test-db                      │
└───────────────────────────────────────────────────────────────┘
```

**Invariant:** One system under test (existing php8 stack). The runner is a **browser + tool runtime**, not a second app.

---

## 3. Compose / Dockerfile layout (intended paths)

Plan only — implementers create these files later:

```text
tools/fuzzy-validator/
  docker/
    Dockerfile                 # FROM ubuntu:26.04 (pin dated tag/digest)
    BROWSER_PIN.txt            # playwright + chromium versions recorded at build
    entrypoint.sh              # optional: idle wait / health
  docker-compose.runner.yml    # service fuzzy-validator-runner
  bin/
    fuzzy-validator            # wrapper: ensure runner → docker exec (default)
    fuzzy-validator-native     # optional: today’s bare path extracted for clarity
```

**Do not** fold the runner into `docker-compose.php8.yml` as a required service. Keep a **fragment** compose file that:

- Declares only the runner service
- Attaches to the **external** network created by the php8 stack (`ork3_ork3-php8-net` when project name is `ork3` — see §5)
- Uses `restart: "no"`

Example shape (illustrative):

```yaml
# tools/fuzzy-validator/docker-compose.runner.yml
services:
  fuzzy-validator-runner:
    build:
      context: ../..          # or repo root — finalize in FV21-1
      dockerfile: tools/fuzzy-validator/docker/Dockerfile
    image: ork3-fuzzy-validator-runner:local
    container_name: ork3-fuzzy-validator-runner
    restart: "no"
    working_dir: /ork3
    volumes:
      - ../..:/ork3            # repo root (path relative finalize in impl)
      - /var/run/docker.sock:/var/run/docker.sock
    environment:
      ORK3_E2E_BASE_URL: http://ork3-php8-app/orkui/
    networks:
      - ork3-php8-net
    # no ports published — no inbound need
    stdin_open: true
    tty: true
    command: ["sleep", "infinity"]   # or entrypoint that idles

networks:
  ork3-php8-net:
    external: true
    name: ork3_ork3-php8-net   # detect/override — see §5
```

---

## 4. Image contents

### Base

| Item | Choice |
|------|--------|
| OS | `ubuntu:26.04` (**resolute**). Prefer pin `resolute-20260707` (or newer dated tag) / digest at implementation time |
| Arch | `linux/amd64` primary; document arm64 (Apple Silicon) build as required for local Mac — same Dockerfile, multi-arch or native build |

Ubuntu **26** as requested maps to the **26.04** LTS image tag (there is no separate `ubuntu:26` convenience tag in the official library; use `26.04` / `resolute`).

### Runtime pins

| Component | Strategy |
|-----------|----------|
| Playwright / Chromium | Install from npm using the **same version as repo `package-lock.json`** (currently Playwright **1.61.1**). `npx playwright install --with-deps chromium` inside the image build (or on first start into a named volume). Record versions in `BROWSER_PIN.txt` |
| Node | LTS sufficient to run Playwright 1.61 (e.g. Node 22.x from NodeSource or Ubuntu packages — pick one in FV21-1 and pin) |
| Python | 3.11+ with `pip install -r tools/fuzzy-validator/python/requirements.txt` baked or installed at ensure-time against the mount |
| PHP CLI | Needed for `bin/ork-db` → `tools/ork-db/cli.php` |
| Docker CLI | Needed for `ork-db use` (`docker compose restart ork3app`) and `docker exec` DB paths |
| Fonts | Playwright deps + optional `fonts-liberation` / DejaVu for stable text metrics; avoid pulling a full desktop font zoo |

### Minimalism

Omit: full X11 desktop, Google Chrome `.deb` from Google, MariaDB server, nginx, the ORK3 PHP app itself.

### `node_modules` strategy (open but recommended)

**Recommended:** mount repo; if `node_modules` missing or wrong platform, runner `npm ci` once into the mount **or** use an anonymous/named volume for `node_modules` to avoid macOS↔Linux binary clashes on native addons. Playwright browser binaries should live **in the image or a dedicated volume**, not depend on the host’s `~/Library/Caches/ms-playwright`.

---

## 5. Networking — how capture reaches the app

### Problem with today’s default URL

Inside the runner, `http://localhost:19080` / `http://127.0.0.1:19080` points at the **runner**, not the host-published app port.

### Decision: same Docker network + container DNS

| Setting | Value |
|---------|--------|
| Network | Join the php8 user network as **external** |
| Default `ORK3_E2E_BASE_URL` (in-container) | `http://ork3-php8-app/orkui/` |
| Alternate DNS | Compose service name `ork3app` also resolves on that network; prefer **container_name** `ork3-php8-app` for stability across compose project renames when documented |

Host browsers and humans still use `http://localhost:19080/orkui/`. Only the **runner’s** default base URL changes.

### Network name detection

Compose prefixes the network with the project directory name. Observed locally: `ork3_ork3-php8-net`. Other checkouts may differ (`ork3-tobias_ork3-php8-net`, etc.).

**Wrapper requirement:** resolve the network that `ork3-php8-app` is attached to (e.g. `docker inspect ork3-php8-app → Networks`) and pass that name into compose `external.name`, **or** document `FUZZY_VALIDATOR_DOCKER_NETWORK=…` override.

### Fallback (not default)

`extra_hosts: host.docker.internal:host-gateway` + `http://host.docker.internal:19080/orkui/` works on Docker Desktop and recent Linux, but couples to published ports and hairpins through the host. Prefer direct bridge DNS to `ork3-php8-app`.

### Dual-profile / ork-db

Because `Use.php` shells out to `docker compose -f <repo>/docker-compose.php8.yml restart ork3app`, the runner must:

1. Mount the **repo root** (compose file path valid inside container)
2. Mount **`/var/run/docker.sock`**
3. Ship a **Docker CLI** compatible with the host engine

Then existing `runtime.activate_profile` keeps working unchanged inside `docker exec`.

**Security note:** docker.sock grants control of the host engine. Acceptable for a local-dev tool mirroring what operators already do on the host; do not expose this image as a remote multi-tenant service.

**Alternative considered (rejected as default):** host wrapper runs `ork-db` and only puts Playwright inside the container. That forces a profile loop in the wrapper or API changes to `activate_profile`. Keep ork-db in-container via sock for fewer CLI semantics changes.

---

## 6. Volume mounts

| Mount | Purpose |
|-------|---------|
| Repo root → `/ork3` | Code, manifests, baselines, reports, overlays, setpoints, `.ork3-db.local`, compose files, `bin/ork-db` |
| `/var/run/docker.sock` | ork-db docker operations |
| Optional: `fv-playwright-browsers` named volume | Persist Playwright browser downloads across image rebuilds of the OS layer |
| Optional: `fv-node-modules` | Linux `node_modules` isolated from host macOS tree |

Working directory: `/ork3` so `npx playwright` and relative tool paths match today’s “run from repo root” assumption. `REPO_ROOT` in Python already derives from install path under `tools/fuzzy-validator`; with the whole repo mounted at `/ork3` and the same relative layout, path math stays valid **if** the tool is invoked from `/ork3` with the mounted tree (not a copy).

**Google Drive / cloud-synced worktrees:** bind mounts from cloud-synced paths can be slow or flaky on macOS. Document as an operational risk; prefer a non-synced clone for heavy validate loops if needed.

---

## 7. CLI wrapper behavior

### Default path

```text
bin/fuzzy-validator <args>
  → tools/fuzzy-validator/bin/fuzzy-validator
       if FUZZY_VALIDATOR_NATIVE=1 or --host ∈ argv:
           exec native python path (today)
       else:
           ensure_runner_running()
           docker exec -e … ork3-fuzzy-validator-runner \
             /ork3/tools/fuzzy-validator/bin/fuzzy-validator-native <args>
```

Strip `--host` before forwarding. Propagate: `ORK3_E2E_*`, `ORK3_CLOCK_DATE`, `CI`, `FUZZY_VALIDATOR_*`, and user env allowlist as needed.

### `ensure_runner_running`

1. If container `ork3-fuzzy-validator-runner` is running → return
2. Else `docker compose -f tools/fuzzy-validator/docker-compose.runner.yml up -d --build` (build only when image missing or `FUZZY_VALIDATOR_REBUILD=1`)
3. Wait until `docker exec … true` succeeds (short retry)
4. Fail with a clear message if php8 app is not running / network missing

### Lifecycle

| Event | Action |
|-------|--------|
| First command | Build (if needed) + start runner |
| Subsequent commands | Reuse running container (`docker exec`) |
| After command | Leave running |
| Host reboot | Runner does not auto-start (`restart: "no"`) |
| Explicit stop | `docker compose -f … stop` (document in README) |

### Escape hatch

| Mechanism | Effect |
|-----------|--------|
| `FUZZY_VALIDATOR_NATIVE=1` | Skip container; host Python + host Playwright |
| `--host` | Same; global flag parsed by wrapper before dispatch |
| Stderr notice | “fuzzy-validator: native mode — captures may differ from containerized default” |

Justify escape hatch: Docker-less debugging, iterating on Python unit tests adjacent to CLI, and recovery if the runner image is broken.

### Overlay-only commands

`overlay validate|summarize` needs no browser; still fine to run in-container (same Python) for consistency, or short-circuit native — implementer’s choice; default **same container path** for one code path.

---

## 8. Env and ports

| Env | In-container default |
|-----|----------------------|
| `ORK3_E2E_BASE_URL` | `http://ork3-php8-app/orkui/` unless host explicitly set |
| `ORK3_E2E_USERNAME` / `PASSWORD` / test auth | Pass-through from host |
| `FUZZY_VALIDATOR_IN_CONTAINER=1` | Set by wrapper for diagnostics / reproduce.md honesty |
| `PLAYWRIGHT_BROWSERS_PATH` | Optional fixed path inside image/volume |

**Ports:** runner publishes **none**. App remains on host **19080**.

Reproduce packs (`lib/reproduce.py`) today default to `http://localhost:19080/orkui/` for humans — keep **human-facing** URLs as localhost; only the capture process uses container DNS. If reproduce.md records the capture URL, prefer noting both or rewriting to localhost for operator copy-paste (FV21-4 polish).

---

## 9. CI implications (non-blocking for plan)

CI Linux runners that already match Ubuntu+Playwright may keep native mode **or** use the same image for parity. Prefer one pin: either CI uses the runner image, or CI documents identical Playwright/Chromium versions. Milestone FV21-5 can wire this; not required to land the local default path.

---

## 10. Setpoint migration note

Existing baselines recorded on macOS host Chromium may **fail** visual compare under the runner even with no product change. Expected one-time maintainer action after 2.1 ships:

1. Restore current setpoint
2. `bin/fuzzy-validator record … --phase all` (container default) on a known-good SHA
3. `setpoint capture` / `publish` as usual

Document clearly in tools README when implementing — do not silently soft-fail.

---

## 11. Design decisions summary

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Network | External join to php8 net; URL `http://ork3-php8-app/orkui/` | Same app as operators; no localhost hairpin |
| ork-db | In-container via docker.sock + Docker CLI | Preserves `activate_profile` dual-profile without rewriting ork-db |
| Lifecycle | Auto-up; leave up; `restart: "no"` | Dev latency + no reboot persistence |
| Chrome | Playwright-pinned Chromium, not distro Chrome | Reproducible with lockfile |
| OS | Ubuntu 26.04 (resolute) | User requirement; official tag exists |
| Default vs native | Container default; `NATIVE` / `--host` escape | Transparent UX + debug hatch |
| Compose home | `tools/fuzzy-validator/docker-compose.runner.yml` | Isolated from app stack |
