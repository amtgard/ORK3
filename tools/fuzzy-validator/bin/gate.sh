#!/usr/bin/env bash
# Fuzzy Validator — unified gate (capture + layer compare)
set -euo pipefail

TOOL_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REPO_ROOT="$(cd "$TOOL_ROOT/../.." && pwd)"
PYTHON_DIR="$TOOL_ROOT/python"

usage() {
  cat <<'EOF'
Usage:
  gate.sh --page PAGE_ID [--phase visual|assets|dom|all]
  gate.sh --pages id1,id2 [--phase visual|assets|dom|all]

Captures one stabilized render per page and runs gate layer(s).
Default phase: all (assets → dom → pixels).
EOF
}

PAGE=""
PAGES=""
PHASE="all"

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

if [[ "$PHASE" != "visual" && "$PHASE" != "assets" && "$PHASE" != "dom" && "$PHASE" != "all" ]]; then
  echo "gate.sh: unsupported phase '$PHASE'" >&2
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

RUN_ID="$(date -u +%Y%m%dT%H%M%SZ)"
RUN_DIR="$TOOL_ROOT/reports/run-$RUN_ID"
exit_code=0

for target in "${TARGETS[@]}"; do
  trimmed="${target// /}"
  [[ -z "$trimmed" ]] && continue

  echo "gate.sh: capturing candidate for $trimmed"
  FUZZ_MODE=candidate FUZZ_PAGES="$trimmed" \
    npx playwright test --project=fuzzy-capture

  if [[ "$PHASE" == "all" ]]; then
    if ! python3 "$PYTHON_DIR/gate_run.py" \
      --page-id "$trimmed" \
      --phase all \
      --run-dir "$RUN_DIR" \
      --visual-diff-out "$RUN_DIR/data/${trimmed}-annotated.png"; then
      exit_code=1
    fi
    continue
  fi

  if [[ "$PHASE" == "assets" ]]; then
    baseline="$TOOL_ROOT/baselines/${trimmed}.assets.json"
    candidate="$TOOL_ROOT/calibrations/${trimmed}/candidate.assets.json"
    diff_dir="$TOOL_ROOT/reports/${trimmed}-asset-diffs"

    if [[ ! -f "$baseline" ]]; then
      echo "gate.sh: missing asset baseline $baseline" >&2
      exit 2
    fi
    if [[ ! -f "$candidate" ]]; then
      echo "gate.sh: missing candidate asset manifest $candidate" >&2
      exit 2
    fi

    if ! python3 "$PYTHON_DIR/gate_assets.py" \
      --page-id "$trimmed" \
      --baseline "$baseline" \
      --candidate "$candidate" \
      --calibration-dir "$TOOL_ROOT/calibrations/${trimmed}" \
      --diff-dir "$diff_dir"; then
      exit_code=1
    fi
    continue
  fi

  if [[ "$PHASE" == "dom" ]]; then
    baseline="$TOOL_ROOT/baselines/${trimmed}.dom.json"
    candidate="$TOOL_ROOT/calibrations/${trimmed}/candidate.dom.html"
    manifest="$TOOL_ROOT/manifests/${trimmed}.dom-fuzz.json"
    diff_out="$TOOL_ROOT/reports/${trimmed}-dom-diff.json"

    if [[ ! -f "$baseline" ]]; then
      echo "gate.sh: missing DOM baseline $baseline" >&2
      exit 2
    fi
    if [[ ! -f "$manifest" ]]; then
      echo "gate.sh: missing DOM fuzz manifest $manifest" >&2
      exit 2
    fi

    if ! python3 "$PYTHON_DIR/gate_dom.py" \
      --page-id "$trimmed" \
      --baseline "$baseline" \
      --candidate "$candidate" \
      --manifest "$manifest" \
      --diff-out "$diff_out"; then
      exit_code=1
    fi
    continue
  fi

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
