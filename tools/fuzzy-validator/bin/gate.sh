#!/usr/bin/env bash
# Fuzzy Validator — pixel gate (single capture + compare)
set -euo pipefail

TOOL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$TOOL_ROOT/../.." && pwd)"
PYTHON_DIR="$TOOL_ROOT/python"

usage() {
  cat <<'EOF'
Usage:
  gate.sh --page PAGE_ID
  gate.sh --pages id1,id2

Captures one stabilized render per page and runs pixel gate.
EOF
}

PAGE=""
PAGES=""
PHASE="visual"

while [[ $# -gt 0 ]]; do
  case "$1" in
    --page)
      PAGE="$2"
      shift 2
      ;;
    --pages)
      PAGES="$2"
      shift 2
      ;;
    --phase)
      PHASE="$2"
      shift 2
      ;;
    -h|--help)
      usage
      exit 0
      ;;
    *)
      echo "gate.sh: unknown argument '$1'" >&2
      usage
      exit 2
      ;;
  esac
done

if [[ "$PHASE" != "visual" ]]; then
  echo "gate.sh: phase '$PHASE' not implemented until FU-9" >&2
  exit 2
fi

if [[ -n "$PAGE" ]]; then
  TARGETS=("$PAGE")
elif [[ -n "$PAGES" ]]; then
  IFS=',' read -r -a TARGETS <<< "$PAGES"
else
  echo "gate.sh: specify --page or --pages" >&2
  exit 2
fi

cd "$REPO_ROOT"
export PYTHONPATH="${PYTHONPATH:-}:$PYTHON_DIR"

exit_code=0
for target in "${TARGETS[@]}"; do
  trimmed="${target// /}"
  [[ -z "$trimmed" ]] && continue

  baseline="$TOOL_ROOT/baselines/${trimmed}.png"
  manifest="$TOOL_ROOT/manifests/${trimmed}.fuzz.json"
  candidate="$TOOL_ROOT/calibrations/${trimmed}/candidate.png"
  diff_out="$TOOL_ROOT/reports/${trimmed}-gate-diff.png"

  if [[ ! -f "$baseline" ]]; then
    echo "gate.sh: missing baseline $baseline" >&2
    exit 2
  fi
  if [[ ! -f "$manifest" ]]; then
    echo "gate.sh: missing manifest $manifest" >&2
    exit 2
  fi

  echo "gate.sh: capturing candidate for $trimmed"
  FUZZ_MODE=candidate FUZZ_PAGES="$trimmed" \
    npx playwright test --project=fuzzy-capture

  if ! python3 "$PYTHON_DIR/gate.py" \
    --page-id "$trimmed" \
    --baseline "$baseline" \
    --candidate "$candidate" \
    --manifest "$manifest" \
    --diff-out "$diff_out"; then
    exit_code=1
  fi
done

exit "$exit_code"
