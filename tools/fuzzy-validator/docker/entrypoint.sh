#!/usr/bin/env bash
# Fuzzy-validator runner entrypoint: ensure Linux node_modules, forward
# host-published DB ports to php8 containers, then idle or exec.
set -euo pipefail

REPO_ROOT="${FUZZY_VALIDATOR_REPO_ROOT:-/ork3}"
cd "$REPO_ROOT"

ensure_node_modules() {
  if [[ "${FUZZY_VALIDATOR_SKIP_NPM_CI:-}" == "1" ]]; then
    return 0
  fi
  if [[ -d "$REPO_ROOT/node_modules/@playwright/test" ]]; then
    return 0
  fi
  if [[ ! -f "$REPO_ROOT/package-lock.json" ]]; then
    echo "fuzzy-validator-runner: missing package-lock.json at $REPO_ROOT" >&2
    return 1
  fi
  echo "fuzzy-validator-runner: installing node_modules (npm ci) into runner volume…" >&2
  # --ignore-scripts: avoid postinstall rewriting bind-mounted orkui assets on the host.
  npm ci --ignore-scripts
}

# ork-db wiring uses 127.0.0.1:19306/19307 (host-published ports). Inside the
# runner those ports are on the php8 DB containers — forward them so dual-profile
# tier checks and PDO DSNs keep working without changing wiring.json5.
#
# docker stop/start re-runs this entrypoint but /tmp may persist; always ensure
# listeners exist (pgrep) rather than relying on a ready file.
start_db_port_forwards() {
  if [[ "${FUZZY_VALIDATOR_SKIP_DB_FORWARD:-}" == "1" ]]; then
    return 0
  fi
  if ! command -v socat >/dev/null 2>&1; then
    echo "fuzzy-validator-runner: socat missing; ork-db localhost DB ports will fail" >&2
    return 0
  fi
  if ! pgrep -f 'TCP-LISTEN:19306' >/dev/null 2>&1; then
    socat TCP-LISTEN:19306,bind=127.0.0.1,fork,reuseaddr TCP:ork3-php8-db:3306 &
  fi
  if ! pgrep -f 'TCP-LISTEN:19307' >/dev/null 2>&1; then
    socat TCP-LISTEN:19307,bind=127.0.0.1,fork,reuseaddr TCP:ork3-php8-test-db:3306 &
  fi
}

ensure_node_modules
start_db_port_forwards

# Prefer image-pinned Chromium over any host cache path.
export PLAYWRIGHT_BROWSERS_PATH="${PLAYWRIGHT_BROWSERS_PATH:-/ms-playwright}"
export FUZZY_VALIDATOR_IN_CONTAINER=1
export ORK3_E2E_BASE_URL="${ORK3_E2E_BASE_URL:-http://ork3-php8-app/orkui/}"

# Do not `exec` — keep socat children alive under this PID 1 shell.
"$@" &
child=$!
wait "$child"
exit $?
