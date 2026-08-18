# FV21-0 — Network + base URL spike

**Date:** 2026-07-20  
**Host:** macOS arm64 (Docker Desktop)  
**App container:** `ork3-php8-app` (php8 stack up)

## Findings

| Check | Result |
|-------|--------|
| Network attached to `ork3-php8-app` | `ork3_ork3-php8-net` |
| `curl -sI http://ork3-php8-app/orkui/` from `ubuntu:26.04` on that network | **HTTP/1.1 200 OK** |
| `curl -sI http://127.0.0.1:19080/orkui/` from same container | **Fails** (curl exit 7 — connection refused) |

## Decisions for FV21-1+

- **Default in-container base URL:** `http://ork3-php8-app/orkui/`
- **External compose network:** auto-detect via `docker inspect ork3-php8-app → Networks`; override with `FUZZY_VALIDATOR_DOCKER_NETWORK`
- **Observed default** when compose project name is `ork3`: `ork3_ork3-php8-net`
- **Base image pin:** `ubuntu:resolute-20260707` (aliases `ubuntu:26.04`; digest `sha256:3131b4cc82a783df6c9df078f86e01819a13594b865c2cad47bd1bca2b7063bb` at spike time)

Spike command (repro):

```bash
NET=$(docker inspect ork3-php8-app -f '{{range $k,$v := .NetworkSettings.Networks}}{{$k}}{{end}}')
docker run --rm --network "$NET" ubuntu:26.04 \
  bash -c 'apt-get update -qq && apt-get install -y -qq curl >/dev/null && curl -sI http://ork3-php8-app/orkui/ | head -5'
```
