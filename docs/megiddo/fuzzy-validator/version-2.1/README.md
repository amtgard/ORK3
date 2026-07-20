# Fuzzy Validator — Version 2.1 (Containerized Runner)

**Status:** Plan (not implemented)  
**Audience:** Humans operating the tool; agents implementing FV21-*  
**Depends on:** v1 shipped; v2 overlays shipped — see [../version-2/](../version-2/)  
**Code landing zone (when built):** `tools/fuzzy-validator/docker/` + thin wrapper changes in `tools/fuzzy-validator/bin/fuzzy-validator`

---

## Problem

Setpoints recorded on one machine often disagree when validated on another. The dominant unnecessary cause is **host OS / Chromium / font / GPU stack drift** (macOS vs Linux, different Chrome builds), not product change. Operators already know this — the tool README and architecture docs say “prefer Linux for sign-off” — but day-to-day CLI still runs Playwright on the host browser by default.

## Goal

Make **stabilized Linux Chromium** the **default** capture and validate surface, without changing how operators invoke the tool:

```bash
bin/fuzzy-validator record|validate|refuzz|setpoint …
```

Containerization is transparent: same flags, same manifests/baselines/reports paths on disk, same dual-profile `ork-db` behavior against the existing php8 app stack.

## User-facing behavior (target)

| Behavior | Detail |
|----------|--------|
| **Default path** | Host CLI ensures a long-lived **fuzzy-validator runner** container is up, then runs the command inside it |
| **I/O** | Tool root artifacts stay on the host via bind mounts (`manifests/`, `baselines/`, `reports/`, `overlays/`, `setpoints/`, calibrations) |
| **App under test** | Still the local ORK3 docker app (`ork3-php8-app` on `ork3-php8-net`, published as `localhost:19080` for browsers on the host) |
| **Lifecycle** | Auto-start if stopped/absent; **leave running** after each command (dev latency). No restart policy that survives host reboot (`restart: "no"`) |
| **Escape hatch** | `FUZZY_VALIDATOR_NATIVE=1` (or `--host`) runs on bare metal for debugging only — not the sign-off path |

From the operator’s perspective, prerequisites shrink toward: php8 stack up + runner image built once. Host Node/Python/Playwright install for fuzzy-validator become optional when using the default path.

## Documents in this folder

| Doc | Purpose |
|-----|---------|
| **[README.md](./README.md)** (this file) | Overview + user-facing behavior |
| **[01-requirements.md](./01-requirements.md)** | Product requirements and non-goals |
| **[02-design.md](./02-design.md)** | Architecture, image, mounts, networking, lifecycle, CLI |
| **[03-milestones.md](./03-milestones.md)** | Executable checklist FV21-0… |

## Relationship to v1 / v2

| Layer | Unchanged | 2.1 change |
|-------|-----------|------------|
| Gate math, overlays, reports | Yes | — |
| Dual profiles test + mirror | Yes | Runner must still call `bin/ork-db use` / optional deploy-sandbox |
| CLI command names / flags | Yes | Wrapper adds container ensure + `docker exec`; optional native escape |
| Capture browser | Host Chromium | Pinned Chromium **inside** Ubuntu 26.04 runner |
| Setpoint contract | Same files | Prefer re-record / publish once from the runner so gold master matches default path |

## Non-goals (summary)

- Replacing or merging the php8 app/db compose stack into the runner image
- Auto-restarting the runner across host reboots
- Guaranteeing pixel identity across GPU vendors *inside* the container beyond Playwright’s headless Chromium (still one OS + one browser pin)
- Containerizing PHPUnit or general e2e (`tests/e2e`) in this milestone

See [01-requirements.md](./01-requirements.md) for the full list.
