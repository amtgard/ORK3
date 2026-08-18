# FV21-5 — Runner parity evidence

**Date:** 2026-07-20  
**Host:** macOS arm64 (Docker Desktop)  
**Runner:** `ork3-fuzzy-validator-runner:local` (`ubuntu:resolute-20260707`, Playwright **1.61.1**, Chromium headless shell **149.0.7827.55**)

## What we proved

| Check | Result |
|-------|--------|
| Default CLI ensure + `docker exec` | `bin/fuzzy-validator validate --help` auto-starts stopped runner; second call reuses it |
| `restart` policy | `"no"` (inspect) |
| App reachability | Capture hits `http://ork3-php8-app/orkui/` (not host localhost Chrome) |
| Dual-profile ork-db | `bin/ork-db use dev\|prod` inside runner rewrites `.ork3-db.local` and restarts `ork3app` via docker.sock |
| DB tier classification | socat forwards `127.0.0.1:19306/19307` → php8 DB containers |
| Playwright capture | `home-authenticated` fuzzy-capture **passed** inside runner (~9s) |

## Host Chromium vs runner (problem statement)

Against the current gold baselines (recorded on host / pre-2.1 Chromium), runner validate reported:

```text
bin/fuzzy-validator validate --profile test --page home-authenticated --phase visual
→ capture OK
→ Fuzzy UI Gate — FAIL  visual=0.957 (threshold 1.00)
→ 39 unexpected visual drifts
Report: tools/fuzzy-validator/reports/run-20260720T143502Z/index.html
```

**Conclusion:** Cross-host visual disagreement driven by **host Chrome/OS** is exactly what the runner removes for the **default path**. Residual FAIL here is expected until maintainers **re-record and publish the setpoint from the runner** (see version-2.1 README migration note). After that re-record, two hosts using the same runner image+tag should agree within existing fuzz thresholds (residual = allowed page volatility / data only).

## Demo

```bash
docker compose -f docker-compose.php8.yml up -d
bin/fuzzy-validator validate --profile test --page home-authenticated --phase visual
# Stop runner (reboot stand-in); next command starts it again:
docker stop ork3-fuzzy-validator-runner
bin/fuzzy-validator validate --help
```
