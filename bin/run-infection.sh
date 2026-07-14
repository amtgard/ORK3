#!/usr/bin/env bash
#
# Run Infection mutation tests. Pass extra args to scope a milestone, e.g.:
#   bin/run-infection.sh --filter=CalendarServiceTest
#   bin/run-infection.sh --configuration=infection.t01-rsvp.json5
#   bin/run-infection.sh --configuration=tools/infection/infection.t01-rsvp.json5
#
# Configs live under tools/infection/. Full-repo thresholds are in
# tools/infection/infection.json5. See docs/megiddo/refactor/06-test-framework.md.
#
set -euo pipefail
cd "$(dirname "$0")/.."

if [[ ! -f vendor/bin/infection ]]; then
    echo "Missing vendor/bin/infection — run: composer install" >&2
    exit 1
fi

export ENVIRONMENT=TEST

# Prefer pcov for coverage collection when available.
if php -m 2>/dev/null | grep -q '^pcov$'; then
    export XDEBUG_MODE=off
fi

CONFIG_DIR="tools/infection"
DEFAULT_CONFIG="${CONFIG_DIR}/infection.json5"

args=()
has_config=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --configuration=*)
            has_config=1
            cfg="${1#--configuration=}"
            if [[ ! -f "$cfg" && -f "${CONFIG_DIR}/${cfg}" ]]; then
                cfg="${CONFIG_DIR}/${cfg}"
            elif [[ ! -f "$cfg" && -f "${CONFIG_DIR}/$(basename "$cfg")" ]]; then
                cfg="${CONFIG_DIR}/$(basename "$cfg")"
            fi
            args+=("--configuration=${cfg}")
            shift
            ;;
        --configuration)
            has_config=1
            cfg="${2:?--configuration requires a path}"
            shift 2
            if [[ ! -f "$cfg" && -f "${CONFIG_DIR}/${cfg}" ]]; then
                cfg="${CONFIG_DIR}/${cfg}"
            elif [[ ! -f "$cfg" && -f "${CONFIG_DIR}/$(basename "$cfg")" ]]; then
                cfg="${CONFIG_DIR}/$(basename "$cfg")"
            fi
            args+=(--configuration "$cfg")
            ;;
        *)
            args+=("$1")
            shift
            ;;
    esac
done

if [[ "$has_config" -eq 0 ]]; then
    args=(--configuration="$DEFAULT_CONFIG" "${args[@]}")
fi

exec php -d memory_limit=512M vendor/bin/infection --show-mutations "${args[@]}"
