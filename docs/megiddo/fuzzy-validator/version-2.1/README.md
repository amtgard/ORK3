# Version 2.1 — As-built status

**Status:** Implemented (FV21-0 … FV21-5 on branch `megiddo/fuzzy-validator-2.1-runner`)  
**Audience:** Humans operating the tool; agents maintaining FV21-*  
**Depends on:** v1 shipped; v2 overlays shipped — see [../version-2/](../version-2/)  
**Code:** `tools/fuzzy-validator/docker/` · `tools/fuzzy-validator/docker-compose.runner.yml` · `tools/fuzzy-validator/bin/fuzzy-validator`

---

## Problem

Setpoints recorded on one machine often disagree when validated on another. The dominant unnecessary cause is **host OS / Chromium / font / GPU stack drift** (macOS vs Linux, different Chrome builds), not product change.

## Goal (shipped)

**Stabilized Linux Chromium** is the **default** capture and validate surface:

```bash
bin/fuzzy-validator record|validate|refuzz|setpoint …
```

Containerization is transparent: same flags, same manifests/baselines/reports paths on disk, same dual-profile `ork-db` behavior against the existing php8 app stack.

## User-facing behavior

| Behavior | Detail |
|----------|--------|
| **Default path** | Host CLI ensures long-lived **ork3-fuzzy-validator-runner** is up, then `docker exec` |
| **I/O** | Repo bind-mounted at `/ork3`; artifacts appear on the host at the same paths |
| **App under test** | `http://ork3-php8-app/orkui/` on `ork3-php8-net` (host browsers still use `localhost:19080`) |
| **Lifecycle** | Auto-start if stopped/absent; **leave running**; `restart: "no"` |
| **Escape hatch** | `FUZZY_VALIDATOR_NATIVE=1` or `--host` |

## Documents in this folder

| Doc | Purpose |
|-----|---------|
| **[README.md](./README.md)** (this file) | Overview + as-built status |
| **[01-requirements.md](./01-requirements.md)** | Product requirements and non-goals |
| **[02-design.md](./02-design.md)** | Architecture (plan + as-built deltas) |
| **[03-milestones.md](./03-milestones.md)** | FV21-0… checklist |

## As-built deltas vs plan

| Topic | Plan | Shipped |
|-------|------|---------|
| Base image | `ubuntu:26.04` / dated resolute | `ubuntu:resolute-20260707` |
| Playwright | lockfile 1.61.1 | Same; see `docker/BROWSER_PIN.txt` |
| `node_modules` | named volume recommended | Volume `ork3-fv-node-modules` + entrypoint `npm ci` |
| ork-db localhost DB ports | assumed sock enough | **socat** forwards `127.0.0.1:19306/19307` → php8 DB containers (wiring.json5 unchanged) |
| Network name | auto-detect | Wrapper inspects `ork3-php8-app`; override `FUZZY_VALIDATOR_DOCKER_NETWORK` |

## Operator migration (setpoints)

Existing baselines recorded on **macOS host Chromium** will often **fail visual** under the runner with no product change. One-time maintainer action:

1. `bin/fuzzy-validator setpoint restore`
2. `bin/fuzzy-validator record … --phase all` (container default) on a known-good SHA
3. `setpoint capture` / `publish` as usual

Do **not** use `--host` / native mode for gold-master sign-off.
